<?php

namespace Drupal\Tests\salesforce_push\Unit;

use Drupal\Core\Database\Connection;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Sql\SqlEntityStorageInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\salesforce_mapping\Entity\SalesforceMappingInterface;
use Drupal\salesforce_push\PushQueue;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Drupal\salesforce_push\PushQueueProcessorPluginManager;
use Drupal\Core\Database\StatementInterface;
use Drupal\Core\Database\Query\Update;
use Drupal\salesforce_mapping\SalesforceMappingStorage;
use Drupal\salesforce_push\PushQueueProcessorInterface;
use Drupal\Component\Datetime\TimeInterface;

/**
 * Test Object instantitation.
 *
 * @coversDefaultClass \Drupal\salesforce_push\PushQueue
 *
 * @group salesforce_push
 */
class PushQueueTest extends UnitTestCase {
  static public $modules = ['salesforce_push'];

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    $this->database = $this->getMockBuilder(Connection::class)
      ->disableOriginalConstructor()
      ->getMock();
    $this->state = $this->getMock(StateInterface::class);
    $this->push_queue_processor_plugin_manager =
      $this->getMockBuilder(PushQueueProcessorPluginManager::class)
        ->disableOriginalConstructor()
        ->getMock();
    $this->entityTypeManager =
      $this->getMock(EntityTypeManagerInterface::class);
    $this->entity_manager = $this->getMock(EntityManagerInterface::class);
    $this->eventDispatcher = $this->getMock(EventDispatcherInterface::CLASS);
    $this->eventDispatcher->expects($this->any())
      ->method('dispatch')
      ->willReturn(NULL);
    $this->string_translation = $this->getMock(TranslationInterface::class);
    $this->time = $this->getMock(TimeInterface::class);

    $this->mapping_storage = $this->getMockBuilder(SalesforceMappingStorage::CLASS)
      ->disableOriginalConstructor()
      ->getMock();

    $this->mapped_object_storage = $this->getMock(SqlEntityStorageInterface::CLASS);

    $this->entityStorage = $this->getMock(SqlEntityStorageInterface::CLASS);

    $this->entityTypeManager->expects($this->at(0))
      ->method('getStorage')
      ->with($this->equalTo('salesforce_mapping'))
      ->willReturn($this->mapping_storage);

    $this->entityTypeManager->expects($this->at(1))
      ->method('getStorage')
      ->with($this->equalTo('salesforce_mapped_object'))
      ->willReturn($this->mapped_object_storage);

    $container = new ContainerBuilder();
    $container->set('database', $this->database);
    $container->set('state', $this->state);
    $container->set('entity_type.manager', $this->entityTypeManager);
    $container->set('event_dispatcher', $this->eventDispatcher);
    $container->set('string_translation', $this->string_translation);
    $container->set('entity.manager', $this->entity_manager);
    $container->set('plugin.manager.salesforce_push_queue_processor', $this->push_queue_processor_plugin_manager);
    $container->set('datetime.time', $this->time);
    \Drupal::setContainer($container);
  }

  /**
   * @covers ::claimItem
   *
   * @expectedException \Exception
   */
  public function testClaimItem() {
    $this->queue = PushQueue::create(\Drupal::getContainer());
    $this->queue->claimItem();
  }

  /**
   * @covers ::claimItems
   */
  public function testClaimItems() {
    $this->queue = PushQueue::create(\Drupal::getContainer());

    // Test claiming items.
    $items = [1, 2, 3];
    $this->queryRange = $this->getMock(StatementInterface::class);
    $this->queryRange->expects($this->once())
      ->method('fetchAllAssoc')
      ->willReturn($items);
    $this->database->expects($this->once())
      ->method('queryRange')
      ->willReturn($this->queryRange);

    $this->updateQuery = $this->getMockBuilder(Update::class)
      ->disableOriginalConstructor()
      ->getMock();
    $this->updateQuery->expects($this->once())
      ->method('fields')
      ->willReturn($this->updateQuery);
    $this->updateQuery->expects($this->any())
      ->method('condition')
      ->willReturn($this->updateQuery);
    $this->updateQuery->expects($this->once())
      ->method('execute')
      ->willReturn(TRUE);
    $this->database->expects($this->once())
      ->method('update')
      ->willReturn($this->updateQuery);

    $this->assertEquals($items, $this->queue->claimItems(0));
  }

  /**
   * @covers ::processQueues
   */
  public function testProcessQueues() {
    $items = [1, 2, 3];
    $mapping1 = $this->getMock(SalesforceMappingInterface::CLASS);
    $mapping1->expects($this->any())
      ->method('getNextPushTime')
      ->willReturn(0);
    $mapping1->expects($this->any())
      ->method('id')
      ->willReturn(1);
    $mapping1->push_limit = 1;
    $mapping1->push_retries = 1;

    $mappings =
    $this->mapping_storage->expects($this->once())
      ->method('loadPushMappings')
      ->willReturn([$mapping1]);

    $this->worker = $this->getMock(PushQueueProcessorInterface::class);
    $this->worker->expects($this->once())
      ->method('process')
      ->willReturn(NULL);
    $this->push_queue_processor_plugin_manager->expects($this->once())
      ->method('createInstance')
      ->willReturn($this->worker);

    $this->queue = $this->getMock(PushQueue::class, ['claimItems', 'setName'], [$this->database, $this->state, $this->push_queue_processor_plugin_manager, $this->entityTypeManager, $this->eventDispatcher, $this->time]);
    $this->queue->expects($this->once())
      ->method('claimItems')
      ->willReturn($items);
    $this->queue->expects($this->once())
      ->method('setName')
      ->willReturn(NULL);

    $this->queue->processQueues();

  }

  /**
   * @covers ::failItem
   */
  // Not sure best way to test this yet.
  // public function testFailItem() {
  //   // Test failed item gets its "fail" property incremented by 1.
  // }

}
