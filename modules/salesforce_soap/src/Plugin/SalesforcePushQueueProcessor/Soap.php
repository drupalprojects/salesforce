<?php

namespace Drupal\salesforce_soap\Plugin\SalesforcePushQueueProcessor;

use Drupal\Core\Plugin\PluginBase;
use Drupal\Core\Queue\SuspendQueueException;
use Drupal\salesforce\EntityNotFoundException;
use Drupal\salesforce_soap\SoapClient;
use Drupal\salesforce_push\PushQueue;
use Drupal\salesforce_push\PushQueueProcessorInterface;
use Drupal\salesforce_mapping\Entity\MappedObject;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Soap queue processor plugin.
 *
 * @Plugin(
 *   id = "soap",
 *   label = @Translation("SOAP Push Queue Processor")
 * )
 */
class Soap extends PluginBase implements PushQueueProcessorInterface {

  protected $queue;
  protected $client;

  public function __construct(array $configuration, $plugin_id, array $plugin_definition, PushQueue $queue, SoapClient $client) {
    $this->queue = $queue;
    $this->client = $client;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static($configuration, $plugin_id, $plugin_definition,
      $container->get('queue.salesforce_push'),
      $container->get('salesforce_soap.client')
    );
  }

  public function process(array $items) {
    if (!$this->client->isAuthorized()) {
      throw new SuspendQueueException('Salesforce client not authorized.');
    }
    foreach ($items as $item) {
      try {
        $this->processItem($item);
        $this->queue->deleteItem($item);
      }
      catch (\Exception $e) {
        $this->queue->failItem($e, $item);
      }
    }
  }

  protected function processItem(\stdClass $item) {
    $mapped_object = \Drupal::entityTypeManager()
      ->getStorage('salesforce_mapped_object')
      ->load($item->mapped_object_id);

    // Allow exceptions to bubble up for PushQueue to sort things out.
    $mapping = salesforce_mapping_load($item->name);

    if (!$mapped_object) {
      if ($item->op == SALESFORCE_MAPPING_SYNC_DRUPAL_DELETE) {
        // If mapped object doesn't exist or fails to load for this delete, this item can be considered successfully processed.
        return;
      }
      $mapped_object = new MappedObject([
        'entity_id' => $item->entity_id,
        'entity_type_id' => $mapping->drupal_entity_type,
        'salesforce_mapping' => $mapping->id(),
      ]);
    } 
   
    // @TODO: the following is nearly identical to the end of salesforce_push_entity_crud(). Can we DRY it? Do we care?
    try {
      // If this is a delete, destroy the SF object and we're done.
      if ($item->op == SALESFORCE_MAPPING_SYNC_DRUPAL_DELETE) {
        $mapped_object->pushDelete();
      }
      else {
        $entity = \Drupal::entityTypeManager()
          ->getStorage($mapping->drupal_entity_type)
          ->load($item->entity_id);
        if (!$entity) {
          // Bubble this up also
          throw new EntityNotFoundException($item->entity_id, $mapping->drupal_entity_type);
        }

        // Push to SF. This also saves the mapped object.
        $mapped_object
          ->setDrupalEntity($entity)
          ->push();
      }
    }
    catch (\Exception $e) {
      if (!$mapped_object->isNew()) {
        // Only update existing mapped objects.
        $mapped_object
          ->set('last_sync_action', $item->op)
          ->set('last_sync_status', FALSE)
          ->set('revision_log_message', $e->getMessage())
          ->save();
      }
      throw $e;
    }
  }

}
