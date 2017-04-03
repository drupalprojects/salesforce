<?php

namespace Drupal\salesforce_pull;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\salesforce\Event\SalesforceEvents;
use Drupal\salesforce\Event\SalesforceWarningEvent;
use Drupal\salesforce\Event\SalesforceErrorEvent;
use Drupal\salesforce\Event\SalesforceNoticeEvent;
use Drupal\salesforce\Rest\RestClientInterface;
use Drupal\salesforce\SFID;
use Drupal\salesforce_mapping\MappedObjectStorage;
use Drupal\salesforce_mapping\MappingConstants;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Handles pull cron deletion of Drupal entities based onSF mapping settings.
 *
 * @see \Drupal\salesforce_pull\DeleteHandler
 */
class DeleteHandler {

  /**
   * Rest client service.
   *
   * @var \Drupal\salesforce\Rest\RestClientInterface
   */
  protected $sfapi;

  /**
   * Salesforce mapping storage service.
   *
   * @var \Drupal\salesforce_mapping\SalesforceMappingStorage
   */
  protected $mappingStorage;

  /**
   * Mapped Object storage service.
   *
   * @var \Drupal\salesforce_mapping\MappedObjectStorage
   */
  protected $mappedObjectStorage;

  /**
   * Entity tpye manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $etm;

  /**
   * State service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * Request service.
   *
   * @var \Symfony\Component\HttpFoundation\Request
   */
  protected $request;

  protected $eventDispatcher;

  /**
   * Constructor.
   *
   * @param \Drupal\salesforce\Rest\RestClientInterface $sfapi
   *   RestClient object.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   Entity Manager service.
   * @param \Drupal\Core\State\StateInterface $state
   *   State service.
   */
    public function __construct(RestClientInterface $sfapi, EntityTypeManagerInterface $entity_type_manager, StateInterface $state, EventDispatcherInterface $event_dispatcher, RequestStack $request_stack) {
    $this->sfapi = $sfapi;
    $this->etm = $entity_type_manager;
    $this->mappingStorage = $this->etm->getStorage('salesforce_mapping');
    $this->mappedObjectStorage = $this->etm->getStorage('salesforce_mapped_object');
    $this->state = $state;
    $this->eventDispatcher = $event_dispatcher;
    $this->request = $request_stack->getCurrentRequest();
  }

  /**
   * Process deleted records from salesforce.
   *
   * @return bool
   *   TRUE.
   */
  public function processDeletedRecords() {
    // @TODO Add back in SOAP, and use autoloading techniques
    $pull_info = $this->state->get('salesforce.sobject_pull_info', []);
    foreach (array_reverse($this->mappingStorage->getMappedSobjectTypes()) as $type) {
      $last_delete_sync = !empty($pull_info[$type]['last_delete_timestamp'])
        ? $pull_info[$type]['last_delete_timestamp']
        : strtotime('-29 days');

      $now = time();
      // getDeleted() restraint: startDate must be at least one minute
      // greater than endDate.
      $now = $now > $last_delete_sync + 60 ? $now : $now + 60;
      $last_delete_sync_sf = gmdate('Y-m-d\TH:i:s\Z', $last_delete_sync);
      $now_sf = gmdate('Y-m-d\TH:i:s\Z', $now);
      $deleted = $this->sfapi->getDeleted($type, $last_delete_sync_sf, $now_sf);
      $this->handleDeletedRecords($deleted, $type);
      $pull_info[$type]['last_delete_timestamp'] = $now;
      $this->state->set('salesforce.sobject_pull_info', $pull_info);
    }
    return TRUE;
  }

  /**
   * Delete records.
   *
   * @param array $deleted
   *   Array of deleted records.
   * @param string $type
   *   Salesforce object type.
   */
  protected function handleDeletedRecords(array $deleted, $type) {
    if (empty($deleted['deletedRecords'])) {
      return;
    }

    $sf_mappings = $this->mappingStorage->loadByProperties(
      ['salesforce_object_type' => $type]
    );
    if (empty($sf_mappings)) {
      return;
    }

    foreach ($deleted['deletedRecords'] as $record) {
      $this->handleDeletedRecord($record, $type);
    }
  }

  /**
   * Delete single mapped object.
   *
   * @param array $record
   *   Record array.
   * @param string $type
   *   Salesforce object type.
   */
  protected function handleDeletedRecord(array $record, $type) {
    $mapped_objects = $this->mappedObjectStorage->loadBySfid(new SFID($record['id']));
    if (empty($mapped_objects)) {
      return;
    }

    foreach ($mapped_objects as $mapped_object) {
      $entity = $mapped_object->getMappedEntity();
      if (!$entity) {
        $message = 'No entity found for ID %id associated with Salesforce Object ID: %sfid ';
        $args = [
          '%id' => $mapped_object->entity_id->value,
          '%sfid' => $record['id'],
        ];
        $this->eventDispatcher->dispatch(SalesforceEvents::NOTICE, new SalesforceNoticeEvent(NULL, $message, $args));
        $mapped_object->delete();
        return;
      }
    }

    // The mapping entity is an Entity reference field on mapped object, so we
    // need to get the id value this way.
    $sf_mapping = $mapped_object->getMapping();
    if (!$sf_mapping) {
      $message = 'No mapping exists for mapped object %id with Salesforce Object ID: %sfid';
      $args = [
        '%id' => $mapped_object->id(),
        '%sfid' => $record['id'],
      ];
      $this->eventDispatcher->dispatch(SalesforceEvents::WARNING, new SalesforceWarningEvent(NULL, $message, $args));
      // @TODO should we delete a mapped object whose parent mapping no longer exists? Feels like someone else's job.
      // $mapped_object->delete();
      return;
    }

    if (!$sf_mapping->checkTriggers([MappingConstants::SALESFORCE_MAPPING_SYNC_SF_DELETE])) {
      return;
    }

    try {
      // Flag this entity to avoid duplicate processing.
      $entity->salesforce_pull = TRUE;

      $entity->delete();
      $message = 'Deleted entity %label with ID: %id associated with Salesforce Object ID: %sfid';
      $args = [
        '%label' => $entity->label(),
        '%id' => $mapped_object->entity_id,
        '%sfid' => $record['id'],
      ];
      $this->eventDispatcher->dispatch(SalesforceEvents::NOTICE, new SalesforceNoticeEvent(NULL, $message, $args));
    }
    catch (\Exception $e) {
      $this->eventDispatcher->dispatch(SalesforceEvents::ERROR, new SalesforceErrorEvent($e));
      // If mapped entity couldn't be deleted, do not delete the mapped object.
      return;
    }

    $mapped_object->delete();
  }

}
