<?php

namespace Drupal\salesforce_mapping\Plugin\SalesforceMappingField;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\salesforce_mapping\SalesforceMappingFieldPluginBase;
use Drupal\salesforce_mapping\Entity\SalesforceMappingInterface;

/**
 * Adapter for entity Reference and fields.
 *
 * @Plugin(
 *   id = "RelatedIDs",
 *   label = @Translation("Related Entity Ids")
 * )
 */
class RelatedIDs extends SalesforceMappingFieldPluginBase {

  /**
   * Implementation of PluginFormInterface::buildConfigurationForm
   * This is basically the inverse of Properties::buildConfigurationForm()
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $pluginForm = parent::buildConfigurationForm($form, $form_state);

    // @TODO inspecting the form and form_state feels wrong, but haven't found a good way to get the entity from config before the config is saved.
    $options = $this->getConfigurationOptions($form['#entity']);

    if (empty($options)) {
      $pluginForm['drupal_field_value'] += [
        '#markup' => t('No available entity reference fields.'),
      ];
    }
    else {
      $pluginForm['drupal_field_value'] += [
        '#type' => 'select',
        '#options' => $options,
        '#empty_option' => $this->t('- Select -'),
        '#default_value' => $this->config('drupal_field_value'),
        '#description' => $this->t('If an existing connection is found with the selected entity reference, the linked identifier will be used.<br />For example, Salesforce ID for Drupal to SF, or Node ID for SF to Drupal.<br />If more than one entity is referenced, the entity at delta zero will be used.'),
      ];
    }
    return $pluginForm;

  }

  /**
   * @see RelatedProperties::value
   */
  public function value(EntityInterface $entity, SalesforceMappingInterface
$mapping) {
    $field_name = $this->config('drupal_field_value');
    $instances = $this->entityFieldManager->getFieldDefinitions(
      get_class($entity),
      $entity->bundle()
    );

    if (empty($instances[$field_name])) {
      return;
    }

    $field = $entity->get($field_name);
    if (empty($field->value)) {
      // This reference field is blank.
      return;
    }

    // Now we can actually fetch the referenced entity.
    $field_settings = $field->getFieldDefinition()->getFieldSettings();
    // @TODO this procedural call will go away when sf mapping object becomes a service or field
    if ($referenced_mapping = $this
        ->mapped_object_storage
        ->loadByDrupal($field_settings['target_type'], $field->value)) {
      return $referenced_mapping->sfid();
    }
  }

  /**
   *
   */
  private function getConfigurationOptions($mapping) {
    $instances = $this->entityFieldManager->getFieldDefinitions(
      $mapping->get('drupal_entity_type'),
      $mapping->get('drupal_bundle')
    );
    $options = [];
    foreach ($instances as $name => $instance) {
      if ($instance->getType() != 'entity_reference') {
        continue;
      }
      // @TODO exclude config entities?
      $options[$name] = $instance->getLabel();
    }
    asort($options);
    return $options;
  }

  /**
   * {@inheritdoc}
   *
   * @return array
   *   Field config upon which this mapping depends
   */
  public function getDependencies(SalesforceMappingInterface $mapping) {
    $field_config = FieldConfig::loadByName($mapping->get('drupal_entity_type'), $mapping->get('drupal_bundle'), $this->config('drupal_field_value'));
    if (empty($field_config)) {
      return [];
    }
    return [
      'config' => array($field_config->getConfigDependencyName()),
    ];
  }

}
