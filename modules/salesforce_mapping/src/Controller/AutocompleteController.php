<?php

namespace Drupal\salesforce_mapping\Controller;

use Drupal\Component\Utility\Unicode;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\typed_data\DataFetcherTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;


/**
 * Class AutocompleteController.
 */
class AutocompleteController extends ControllerBase {

  use DataFetcherTrait;

  /**
   * Entity Field manager service.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $fieldManager;

  /**
   * Constructs a new AutocompleteController object.
   *
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $field_manager
   *   Entity field manager.
   */
  public function __construct( EntityFieldManagerInterface $field_manager) {
    $this->fieldManager = $field_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_field.manager')
    );
  }


  /**
   * Autocomplete.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object providing the autocomplete query parameter.
   *
   * @param string $entity_type_id
   *   The entity type filter options by.
   * @param string $bundle
   *   The bundle of the entity to filter options by.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The JSON results.
   */
  public function autocomplete(Request $request, $entity_type_id, $bundle) {
    $string = Unicode::strtolower($request->query->get('q'));
    $field_definitions = $this->fieldManager->getFieldDefinitions($entity_type_id, $bundle);
    $results = $this
        ->getDataFetcher()
        ->autocompletePropertyPath($field_definitions, $string);

    return new JsonResponse($results);
  }

}
