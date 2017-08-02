<?php

namespace Drupal\salesforce_pull\Plugin\QueueWorker;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\salesforce\Event\SalesforceErrorEvent;
use Drupal\salesforce\Event\SalesforceEvents;
use Drupal\salesforce\Event\SalesforceNoticeEvent;
use Drupal\salesforce\Rest\RestClientInterface;
use Drupal\salesforce\Rest\RestException;
use Drupal\salesforce\SObject;
use Drupal\salesforce_mapping\Entity\MappedObjectInterface;
use Drupal\salesforce_mapping\Entity\SalesforceMappingInterface;
use Drupal\salesforce_mapping\Event\SalesforcePullEvent;
use Drupal\salesforce_mapping\Event\SalesforcePushParamsEvent;
use Drupal\salesforce_mapping\MappingConstants;
use Drupal\salesforce_mapping\PushParams;
use Drupal\salesforce_pull\PullException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Provides base functionality for the Salesforce Pull Queue Workers.
 */
abstract class PullBase extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $etm;

  /**
   * The SF REST client.
   *
   * @var Drupal\salesforce\Rest\RestClientInterface
   */
  protected $client;

  /**
   * Storage handler for SF mappings.
   *
   * @var \Drupal\salesforce_mapping\SalesforceMappingStorage
   */
  protected $mappingStorage;

  /**
   * Storage handler for Mapped Objects.
   *
   * @var \Drupal\salesforce_mapping\Entity\MappedObjectStorage
   */
  protected $mappedObjectStorage;

  /**
   * Event dispatcher service.
   *
   * @var \Symfony\Component\EventDispatcher\EventDispatcherInterface
   */
  protected $eventDispatcher;

  /**
   * Creates a new PullBase object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\salesforce\Rest\RestClientInterface $client
   *   Salesforce REST client.
   * @param \Symfony\Component\EventDispatcher\EventDispatcherInterface $eventDispatcher
   *   Event dispatcher service.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, RestClientInterface $client, EventDispatcherInterface $event_dispatcher) {
    $this->etm = $entity_type_manager;
    $this->client = $client;
    $this->eventDispatcher = $event_dispatcher;
    $this->mappingStorage = $this->etm->getStorage('salesforce_mapping');
    $this->mappedObjectStorage = $this->etm->getStorage('salesforce_mapped_object');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('salesforce.client'),
      $container->get('event_dispatcher')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($item) {
    $sf_object = $item->sobject;
    $mapping = $this->mappingStorage->load($item->mapping_id);
    if (!$mapping) {
      return;
    }

    // loadMappedObjects returns an array, but providing salesforce id and
    // mapping guarantees at most one result.
    $mapped_object = $this->mappedObjectStorage->loadByProperties([
      'salesforce_id' => (string) $sf_object->id(),
      'salesforce_mapping' => $mapping->id,
    ]);
    // @TODO one-to-many: this is a blocker for OTM support:
    $mapped_object = current($mapped_object);
    if (!empty($mapped_object)) {
      return $this->updateEntity($mapping, $mapped_object, $sf_object);
    }
    else {
      return $this->createEntity($mapping, $sf_object);
    }

  }

  /**
   * Update an existing Drupal entity.
   *
   * @param SalesforceMappingInterface $mapping
   *   Object of field maps.
   * @param MappedObjectInterface $mapped_object
   *   SF Mmapped object.
   * @param SObject $sf_object
   *   Current Salesforce record array.
   */
  protected function updateEntity(SalesforceMappingInterface $mapping, MappedObjectInterface $mapped_object, SObject $sf_object) {
    if (!$mapping->checkTriggers([MappingConstants::SALESFORCE_MAPPING_SYNC_SF_UPDATE])) {
      return;
    }

    try {
      $entity = $this->getMappedEntity($mapped_object);
      if (!$entity) {
        $this->eventDispatcher->dispatch(SalesforceEvents::ERROR, new SalesforceErrorEvent(NULL, 'Drupal entity existed at one time for Salesforce object %sfobjectid, but does not currently exist.', ['%sfobjectid' => (string) $sf_object->id()]));
        return;
      }

      // Flag this entity as having been processed. This does not persist,
      // but is used by salesforce_push to avoid duplicate processing.
      $entity->salesforce_pull = TRUE;

      $entity_updated = !empty($entity->changed->value)
        ? $entity->changed->value
        : $mapped_object->getChanged();

      $pull_trigger_date =
        $sf_object->field($mapping->getPullTriggerDate());
      $sf_record_updated = strtotime($pull_trigger_date);

      $mapped_object
        ->setDrupalEntity($entity)
        ->setSalesforceRecord($sf_object);

      // Push upsert ID to SF object, if allowed and not set.
      if (
        $mapping->hasKey()
        && $mapping->checkTriggers([
          MappingConstants::SALESFORCE_MAPPING_SYNC_DRUPAL_CREATE,
          MappingConstants::SALESFORCE_MAPPING_SYNC_DRUPAL_UPDATE,
        ])
        && $sf_object->field($mapping->getKeyField()) === NULL
      ) {
        $params = new PushParams($mapping, $entity);
        $this->eventDispatcher->dispatch(
          SalesforceEvents::PUSH_PARAMS,
          new SalesforcePushParamsEvent($mapped_object, $params)
        );
        // Get just the key param and send that.
        $key_field = $mapping->getKeyField();
        $key_param = [$key_field => $params->getParam($key_field)];
        $sent_id = $this->sendEntityId(
          $mapping->getSalesforceObjectType(),
          $mapped_object->sfid(),
          $key_param
        );
        if (!$sent_id) {
          throw new PullException();
        }
      }

      $this->eventDispatcher->dispatch(
        SalesforceEvents::PULL_PREPULL,
        new SalesforcePullEvent($mapped_object, MappingConstants::SALESFORCE_MAPPING_SYNC_SF_UPDATE)
      );

      if ($sf_record_updated > $entity_updated || $mapped_object->force_pull) {
        // Set fields values on the Drupal entity.
        $mapped_object->pull();
        $this->eventDispatcher->dispatch(SalesforceEvents::NOTICE, new SalesforceNoticeEvent(NULL, 'Updated entity %label associated with Salesforce Object ID: %sfid', ['%label' => $entity->label(), '%sfid' => (string) $sf_object->id()]));
        return MappingConstants::SALESFORCE_MAPPING_SYNC_SF_UPDATE;
      }
    }
    catch (\Exception $e) {
      $this->eventDispatcher->dispatch(SalesforceEvents::ERROR, new SalesforceErrorEvent($e, 'Failed to update entity %label from Salesforce object %sfobjectid.', ['%label' => (isset($entity)) ? $entity->label() : "Unknown", '%sfobjectid' => (string) $sf_object->id()]));

      if ($e instanceof PullException) {
        // Throwing a new exception to keep current item in queue in Cron.
        throw $e;
      }
    }
  }

  protected function getMappedEntity($mapped_object) {
    return $this->etm
      ->getStorage($mapped_object->entity_type_id->value)
      ->load($mapped_object->entity_id->value);
  }

  /**
   * Create a Drupal entity and mapped object.
   *
   * @param SalesforceMappingInterface $mapping
   *   Object of field maps.
   * @param SObject $sf_object
   *   Current Salesforce record array.
   */
  protected function createEntity(SalesforceMappingInterface $mapping, SObject $sf_object) {
    if (!$mapping->checkTriggers([MappingConstants::SALESFORCE_MAPPING_SYNC_SF_CREATE])) {
      return;
    }

    try {
      // Define values to pass to entity_create().
      $entity_type = $mapping->getDrupalEntityType();
      $entity_keys = $this->etm->getDefinition($entity_type)->getKeys();
      $values = [];
      if (isset($entity_keys['bundle'])
          && !empty($entity_keys['bundle'])) {
        $values[$entity_keys['bundle']] = $mapping->getDrupalBundle();
      }

      // See note above about flag.
      $values['salesforce_pull'] = TRUE;

      // Create entity.
      $entity = $this->etm
        ->getStorage($entity_type)
        ->create($values);

      // Create mapping object.
      $mapped_object = $this->mappedObjectStorage->create([
        'entity_type_id' => $entity_type,
        'salesforce_mapping' => $mapping->id,
        'salesforce_id' => (string) $sf_object->id(),
      ]);
      $mapped_object
        ->setDrupalEntity($entity)
        ->setSalesforceRecord($sf_object);

      $this->eventDispatcher->dispatch(
        SalesforceEvents::PULL_PREPULL,
        new SalesforcePullEvent($mapped_object, MappingConstants::SALESFORCE_MAPPING_SYNC_SF_CREATE)
      );

      $mapped_object->pull();

      // Push upsert ID to SF object, if allowed and not already set.
      if ($mapping->hasKey() && $mapping->checkTriggers([
          MappingConstants::SALESFORCE_MAPPING_SYNC_DRUPAL_CREATE,
          MappingConstants::SALESFORCE_MAPPING_SYNC_DRUPAL_UPDATE,
        ]) && $sf_object->field($mapping->getKeyField()) === NULL) {
        $params = new PushParams($mapping, $entity);
        $this->eventDispatcher->dispatch(
          SalesforceEvents::PUSH_PARAMS,
          new SalesforcePushParamsEvent($mapped_object, $params)
        );
        // Get just the key param and send that.
        $key_field = $mapping->getKeyField();
        $key_param = [$key_field => $params->getParam($key_field)];
        $sent_id = $this->sendEntityId(
          $mapping->getSalesforceObjectType(),
          $mapped_object->sfid(),
          $key_param
        );
        if (!$sent_id) {
          throw new PullException();
        }
      }

      $this->eventDispatcher->dispatch(SalesforceEvents::NOTICE, new SalesforceNoticeEvent(NULL, 'Created entity %id %label associated with Salesforce Object ID: %sfid', ['%id' => $entity->id(), '%label' => $entity->label(), '%sfid' => (string) $sf_object->id()]));

      return MappingConstants::SALESFORCE_MAPPING_SYNC_SF_CREATE;
    }
    catch (\Exception $e) {
      $this->eventDispatcher->dispatch(SalesforceEvents::NOTICE, new SalesforceNoticeEvent($e, 'Pull-create failed for Salesforce Object ID: %sfobjectid', ['%sfobjectid' => (string) $sf_object->id()]));
      if ($e instanceof PullException) {
        // Throwing a new exception to keep current item in queue in Cron.
        throw $e;
      }
    }
  }

  /**
   * Push the Entity ID up to Salesforce.
   *
   * @param string $object_type
   *   Salesforce object type.
   * @param string $sfid
   *   Salesforce ID.
   * @param array $key_param
   *   Key parameter to be pushed.
   *
   * @return bool
   *   TRUE/FALSE
   */
  protected function sendEntityId(string $object_type, string $sfid, array $key_param) {
    try {
      $this->client->objectUpdate($object_type, $sfid, $key_param);
      return TRUE;
    }
    catch (RestException $e) {
      $this->eventDispatcher->dispatch(SalesforceEvents::ERROR, new SalesforceErrorEvent($e));
      return FALSE;
    }
  }

}