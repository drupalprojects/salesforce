<?php

namespace Drupal\Tests\salesforce_mapping\Unit;

use Drupal\Core\Config\Entity\ConfigEntityTypeInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\salesforce\Rest\RestClientInterface;
use Drupal\salesforce\SFID;
use Drupal\salesforce\SObject;
use Drupal\salesforce_mapping\Entity\MappedObject;
use Drupal\salesforce_mapping\Entity\MappedObjectInterface;
use Drupal\salesforce_mapping\Entity\SalesforceMapping;
use Drupal\salesforce_mapping\Entity\SalesforceMappingInterface;
use Drupal\salesforce_mapping\MappingConstants;
use Drupal\salesforce_mapping\Plugin\SalesforceMappingField\Properties;
use Drupal\salesforce_mapping\SalesforceMappingFieldPluginInterface;
use Drupal\salesforce_mapping\SalesforceMappingFieldPluginManager;
use Prophecy\Argument;

/**
 * Test Mapped Object instantitation
 * @coversDefaultClass \Drupal\salesforce_mapping\Entity\MappedObject
 * @group salesforce_mapping
 */

class MappedObjectTest extends UnitTestCase {
  static $modules = ['salesforce_mapping'];

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    parent::setUp();

    $this->entityTypeId = $this->randomMachineName();
    $this->bundle = $this->randomMachineName();
    $this->mapped_object_id = 1;
    $this->salesforce_id = '1234567890abcdeAAA';
    $this->mapping_id = 1;
    $this->entity_id = 1;
    $this->sf_object = new SObject([
      'id' => $this->salesforce_id,
      'attributes' => ['type' => $this->randomMachineName()],
      'foo' => 'bar',
    ]);

    $values = array(
      'id' => $this->mapped_object_id,
      // 'defaultLangcode' => array(LanguageInterface::LANGCODE_DEFAULT => 'en'),
      'entity_id' => $this->entity_id,
      'entity_type_id' => $this->entityTypeId,
      'salesforce_id' => $this->salesforce_id,
      'salesforce_mapping' => $this->mapping_id,
    );

    $this->sfid = $this->getMockBuilder(SFID::CLASS)
      ->setConstructorArgs([$this->salesforce_id])
      ->getMock();
    $this->sfid->expects($this->any())
      ->method('__toString')
      ->willReturn($this->salesforce_id);

    $this->entityType = $this->getMock('\Drupal\Core\Entity\EntityTypeInterface');
    $this->entityType->expects($this->any())
      ->method('getKeys')
      ->will($this->returnValue([
        'id' => 'id',
        'uuid' => 'uuid',
    ]));

    $this->entityManager = $this->getMock('\Drupal\Core\Entity\EntityManagerInterface');
    $this->entityManager->expects($this->any())
      ->method('getDefinition')
      ->with($this->entityTypeId)
      ->will($this->returnValue($this->entityType));

    $this->mappedObjectEntityType = $this->getMock('\Drupal\Core\Entity\EntityTypeInterface');
    $this->mappedObjectEntityType->expects($this->any())
      ->method('getKeys')
      ->will($this->returnValue([
        'id' => 'id',
        'entity_id' => 'entity_id',
        'salesforce_id' => 'salesforce_id'
    ]));

    $this->entityManager = $this->getMock('\Drupal\Core\Entity\EntityManagerInterface');
    $this->entityManager->expects($this->any())
      ->method('getDefinition')
      ->with('salesforce_mapped_object')
      ->will($this->returnValue($this->mappedObjectEntityType));

    $this->event_dispatcher = $this->getMock('\Symfony\Component\EventDispatcher\EventDispatcherInterface');

    $this->client = $this->getMock(RestClientInterface::CLASS);

    $this->fieldTypePluginManager = $this->getMockBuilder('\Drupal\Core\Field\FieldTypePluginManager')
      ->disableOriginalConstructor()
      ->getMock();
    $this->fieldTypePluginManager->expects($this->any())
      ->method('getDefaultStorageSettings')
      ->will($this->returnValue(array()));
    $this->fieldTypePluginManager->expects($this->any())
      ->method('getDefaultFieldSettings')
      ->will($this->returnValue(array()));
    $this->fieldTypePluginManager->expects($this->any())
      ->method('createFieldItemList')
      ->will($this->returnValue(
        $this->getMock('Drupal\Core\Field\FieldItemListInterface')));

    $container = new ContainerBuilder();
    $container->set('entity.manager', $this->entityManager);
    $container->set('salesforce.client', $this->client);
    $container->set('event_dispatcher', $this->event_dispatcher);
    $container->set('plugin.manager.field.field_type', $this->fieldTypePluginManager);
    \Drupal::setContainer($container);

    $this->entity = $this->getMock('\Drupal\Core\Entity\ContentEntityInterface');
    $this->entity
      ->expects($this->any())
      ->method('id')
      ->willReturn($this->entity_id);

    $this->entity
      ->expects($this->any())
      ->method('isTranslatable')
      ->willReturn(FALSE);

    $this->fieldDefinitions = array(
      'id' => BaseFieldDefinition::create('integer'),
      'entity_id' => BaseFieldDefinition::create('integer'),
      'entity_type_id' => BaseFieldDefinition::create('string'),
      'salesforce_id' => BaseFieldDefinition::create('string'),
      'salesforce_mapping' => BaseFieldDefinition::create('entity_reference'),
    );

    $this->entityManager->expects($this->any())
      ->method('getFieldDefinitions')
      ->with('salesforce_mapped_object', 'salesforce_mapped_object')
      ->will($this->returnValue($this->fieldDefinitions));

    // mock salesforce mapping
    $this->mapping = $this->getMock(SalesforceMappingInterface::CLASS);
    $this->mapping
      ->expects($this->any())
      ->method('getFieldMappings')
      ->willReturn([]);
    $this->mapping
      ->expects($this->any())
      ->method('getPullFields')
      ->willReturn([]);
    $this->mapping
      ->expects($this->any())
      ->method('getSalesforceObjectType')
      ->willReturn('dummy_sf_object_type');

    $this->mapped_object = $this->getMockBuilder(MappedObject::CLASS)
      ->disableOriginalConstructor()
      ->setMethods(['getMappedEntity', 'getMapping', 'getEntityType', 'sfid', 'set', 'save', 'setNewRevision', 'client'])
      ->getMock();
    $this->mapped_object->expects($this->any())
      ->method('getMappedEntity')
      ->willReturn($this->entity);
    $this->mapped_object->expects($this->any())
      ->method('getMapping')
      ->willReturn($this->mapping);
    $this->mapped_object->expects($this->any())
      ->method('getEntityType')
      ->willReturn($this->mappedObjectEntityType);
    $this->mapped_object->expects($this->any())
      ->method('set')
      ->willReturn($this->mapped_object);
    $this->mapped_object->expects($this->any())
      ->method('client')
      ->willReturn($this->client);
  }

  /**
   * @covers ::push
   */
  public function testPushUpsert() {
    // First pass: test upsert
    $this->mapping->expects($this->any())
      ->method('hasKey')
      ->will($this->returnValue(TRUE));
    $this->client->expects($this->once())
      ->method('objectUpsert')
      ->willReturn(NULL);
    $this->assertNull($this->mapped_object->push());
  }

  /**
   * @covers ::push
   */
  public function testPushUpdate() {
    // Second pass: test update
    $this->mapping->expects($this->once())
      ->method('hasKey')
      ->willReturn(FALSE);
    $this->mapped_object->expects($this->any())
      ->method('sfid')
      ->willReturn($this->sfid);
    $this->client->expects($this->once())
      ->method('objectUpdate')
      ->willReturn(NULL);
    $this->assertNull($this->mapped_object->push());
  }

  /**
   * @covers ::push
   */
  public function testPushCreate() {
    // Third pass: test create
    $this->mapping->expects($this->once())
      ->method('hasKey')
      ->will($this->returnValue(FALSE));
    $this->mapped_object->expects($this->any())
      ->method('sfid')
      ->willReturn(FALSE);
    $this->client->expects($this->once())
      ->method('objectCreate')
      ->willReturn($this->sfid);

    $result = $this->mapped_object->push();
    $this->assertTrue($result instanceof SFID);
    $this->assertEquals($this->salesforce_id, (string)$result);
  }

  /**
   * @covers ::pushDelete
   */
  public function testPushDelete() {
    $this->client->expects($this->once())
      ->method('objectDelete')
      ->willReturn(NULL);
    $this->assertEquals($this->mapped_object, $this->mapped_object->pushDelete());
  }

  /**
   * @covers ::pull
   * @expectedException \Drupal\salesforce\Exception
   */
  public function testPullException() {
    $this->mapped_object->expects($this->any())
      ->method('sfid')
      ->willReturn(FALSE);
    $this->mapping->expects($this->any())
      ->method('hasKey')
      ->willReturn(FALSE);

    $this->mapped_object->pull();
  }

  /**
   * @covers ::pull
   */
  public function testPullExisting() {
    $this->mapped_object->expects($this->any())
      ->method('sfid')
      ->willReturn($this->sfid);

    $this->client->expects($this->once())
      ->method('objectRead')
      ->willReturn($this->sf_object);

    $this->assertNull($this->mapped_object->getSalesforceRecord());
    $this->mapped_object->pull();
    $this->assertEquals($this->sf_object, $this->mapped_object->getSalesforceRecord());
  }

  /**
   * @covers ::pull
   */
  public function testPull() {
    // Set sf_object to mock coming from cron pull.
    $this->mapped_object->setSalesforceRecord($this->sf_object);
    // $this->event_dispatcher->expects($this->once())
    //   ->method('dispatch');

    $this->assertEquals($this->mapped_object, $this->mapped_object->pull());
    return;
      // @TODO better way to handle push/pull:
      $fields = $mapping->getPullFields();

      foreach ($fields as $field) {
        // @TODO: The field plugin should be in charge of setting its value on an entity, we should not assume the field plugin's logic as we're doing here.
        try {
          $value = $this->sf_object->field($field->get('salesforce_field'));
        }
        catch (\Exception $e) {
          // Field missing from SObject? Skip it.
          continue;
        }

        $this->eventDispatcher()->dispatch(
          SalesforceEvents::PULL_ENTITY_VALUE,
          new SalesforcePullEntityValueEvent($value, $field, $this)
        );

        $drupal_field = $field->get('drupal_field_value');

        try {
          $this->drupal_entity->set($drupal_field, $value);
        }
        catch (\Exception $e) {
          $message = t();
          $this->logger('Salesforce Pull')->notice('Exception during pull for @sfobj.@sffield @sfid to @dobj.@dprop @did with value @v: @e', [
            '@sfobj' => $mapping->getSalesforceObjectType(),
            '@sffield' => $sf_field,
            '@sfid' => $this->sfid(),
            '@dobj' => $this->entity_type_id->value,
            '@dprop' => $drupal_field,
            '@did' => $this->entity_id->value,
            '@v' => $value,
            '@e' => $e->getMessage(),
          ]);
          \Drupal::logger(__CLASS__)->log(
            LogLevel::ERROR,
            '%type: @message in %function (line %line of %file).',
            Error::decodeException($e)
          );
          continue;
        }
      }

      // @TODO: Event dispatching and entity saving should not be happening in this context, but inside a controller. This class needs to be more model-like.
      $this->eventDispatcher()->dispatch(
        SalesforceEvents::PULL_PRESAVE,
        new SalesforcePullEvent($this, $this->drupal_entity->isNew()
          ? MappingConstants::SALESFORCE_MAPPING_SYNC_SF_CREATE
          : MappingConstants::SALESFORCE_MAPPING_SYNC_SF_UPDATE)
      );

      $this->drupal_entity->save();

      // Update mapping object.
      $this
        ->set('entity_id', $this->drupal_entity->id())
        ->set('entity_updated', $this->getRequestTime())
        ->set('last_sync_action', 'pull')
        ->set('last_sync_status', TRUE)
        // ->set('last_sync_message', '')
        ->save();

      return $this;

  }

}
