<?php

namespace Drupal\salesforce\Rest;

use Drupal\Component\Serialization\Json;
use GuzzleHttp\Psr7\Response;

/**
 *
 */
class RestResponse extends Response {

  /**
   * The original Response used to build this object.
   *
   * @var GuzzleHttp\Psr7\Response
   * @see __get()
   */
  protected $response;

  /**
   * The json-decoded response body.
   *
   * @var mixed
   * @see __get()
   */
  protected $data;

  /**
   * {@inheritdoc}
   *
   * @throws RestException if body cannot be json-decoded
   */
  public function __construct(Response $response) {
    $this->response = $response;
    parent::__construct($response->getStatusCode(), $response->getHeaders(), $response->getBody(), $response->getProtocolVersion(), $response->getReasonPhrase());
    $this->handleJsonResponse();
  }

  /**
   * Magic getter method to return the given property.
   *
   * @param string $key
   *
   * @return mixed
   *
   * @throws Exception if $key is not a property
   */
  public function __get($key) {
    if (!property_exists($this, $key)) {
      throw new \Exception("Undefined property $key");
    }
    return $this->$key;
  }

  /**
   * Helper function to eliminate repetitive json parsing.
   *
   * @return $this
   *
   * @throws Drupal\salesforce\RestException
   */
  private function handleJsonResponse() {
    $this->data = '';
    $response_body = $this->getBody()->getContents();
    if (empty($response_body)) {
      return;
    }

    // Allow any exceptions here to bubble up:
    try {
      $data = Json::decode($response_body);
    }
    catch (UnexpectedValueException $e) {
      throw new RestException($this, $e->getMessage(), $e->getCode(), $e);
    }

    if (empty($data)) {
      throw new RestException($this, t('Invalid response'));
    }

    if (!empty($data['error'])) {
      throw new RestException($this, $data['error']);
    }

    if (!empty($data[0]) && count($data) == 1) {
      $data = $data[0];
    }

    if (!empty($data['error'])) {
      throw new RestException($this, $data['error']);
    }

    if (!empty($data['errorCode'])) {
      throw new RestException($this, $data['errorCode']);
    }
    $this->data = $data;
    return $this;
  }

}
