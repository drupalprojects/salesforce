<?php

namespace Drupal\salesforce;

/**
 * Class SObject.
 *
 * @package Drupal\salesforce
 */
class SObject {
  protected $type;
  protected $fields;
  protected $id;

  /**
   * SObject constructor.
   *
   * @param array $data
   *
   * @throws \Exception
   */
  public function __construct(array $data = []) {
    if (!isset($data['id']) && !isset($data['Id'])) {
      throw new \Exception('Refused to instantiate SObject without ID');
    }

    if (isset($data['id'])) {
      $data['Id'] = $data['id'];
    }
    $this->id = new SFID($data['Id']);
    unset($data['id'], $data['Id']);

    if (empty($data['attributes']) || !isset($data['attributes']['type'])) {
      throw new \Exception('Refused to instantiate SObject without Type');
    }
    $this->type = $data['attributes']['type'];

    // Attributes array also contains "url" index, which we don't need.
    unset($data['attributes']);
    $this->fields = [];
    foreach ($data as $key => $value) {
      $this->fields[$key] = $value;
    }
    $this->fields['Id'] = (string) $this->id;
  }

  /**
   * @return \Drupal\salesforce\SFID
   */
  public function id() {
    return $this->id;
  }

  /**
   * @return mixed
   */
  public function type() {
    return $this->type;
  }

  /**
   *
   */
  public function fields() {
    return $this->fields;
  }

  /**
   * Given $key, return corresponding field value.
   *
   * @return mixed
   * @throws \Exception if $key is not found
   */
  public function field($key) {
    if (!array_key_exists($key, $this->fields)) {
      throw new \Exception('Index not found');
    }
    return $this->fields[$key];
  }

}
