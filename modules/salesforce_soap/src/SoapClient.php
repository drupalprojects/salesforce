<?php

namespace Drupal\salesforce_soap;

class SoapClient {
  const PARTNER_NAMESPACE = 'urn:partner.soap.sforce.com';

	protected $sforce;
	protected $sessionId;
	protected $location;
	protected $version;

	protected $namespace;

	// Header Options
	protected $sessionHeader;

  public function __construct() {
    $this->namespace = self::PARTNER_NAMESPACE;
  }
	
  protected function getSoapClient($wsdl, $options) {
		return new SoapClient($wsdl, $options);      
  }
	
	public function getNamespace() {
		return $this->namespace;
	}

  public function isAuthorized() {
    throw new \Exception(__CLASS__.__FUNCTION__);
  }

	// clientId specifies which application or toolkit is accessing the
	// salesforce.com API. For applications that are certified salesforce.com
	// solutions, replace this with the value provided by salesforce.com.
	// Otherwise, leave this value as 'phpClient/1.0'.
	protected $client_id;

	/**
	 * Connect method to www.salesforce.com
	 *
	 * @param string $wsdl   Salesforce.com Partner WSDL
	 *
   * @param array $soap_options (optional) Additional options to send to the
   *                       SoapClient constructor. @see
   *                       http://php.net/manual/en/soapclient.soapclient.php
	 */
	public function createConnection($wsdl, $soap_options=array()) {
		$phpversion = substr(phpversion(), 0, strpos(phpversion(), '-'));
		
		$soapClientArray = array_merge(array (
			'user_agent' => 'salesforce-toolkit-php/' . $this->version,
			'encoding' => 'utf-8',
			'trace' => 1,
			'features' => SOAP_SINGLE_ELEMENT_ARRAYS,
			'compression' => SOAP_COMPRESSION_ACCEPT | SOAP_COMPRESSION_GZIP
		), $soap_options);

  	$this->sforce = $this->getSoapClient($wsdl, $soapClientArray);

		return $this->sforce;
	}

  /**
   * Adds one or more new individual objects to your organization's data.
   * @param array $sObjects Array of one or more PushParams (up to 200) to create.
   * @return SaveResult
   */
  public function create(array $sObjects) {
    $arg = new stdClass;
    foreach ($sObjects as $sObject) {
      if (isset ($sObject->fields)) {
        $sObject->any = $this->_convertToAny($sObject->fields);
      }
    }
    $arg->sObjects = $sObjects;
		$this->setHeaders("create");
		return $this->sforce->create($arg)->result;
  }

  /**
   * Updates one or more new individual objects to your organization's data.
   * @param array sObjects    Array of sObjects
   * @return UpdateResult
   */
  public function update($sObjects) {
    $arg = new stdClass;
    foreach ($sObjects as $sObject) {
      if (isset($sObject->fields)) {
        $sObject->any = $this->_convertToAny($sObject->fields);
      }
    }
    $arg->sObjects = $sObjects;
		$this->setHeaders("update");
		return $this->sforce->update($arg)->result;
  }

  /**
   * Creates new objects and updates existing objects; uses a custom field to
   * determine the presence of existing objects. In most cases, we recommend
   * that you use upsert instead of create because upsert is idempotent.
   * Available in the API version 7.0 and later.
   *
   * @param string $ext_Id        External Id
   * @param array  $sObjects  Array of sObjects
   * @return UpsertResult
   */
  public function upsert($ext_Id, $sObjects) {
    //		$this->_setSessionHeader();
    $arg = new stdClass;
    $arg->externalIDFieldName = new SoapVar($ext_Id, XSD_STRING, 'string', 'http://www.w3.org/2001/XMLSchema');
    foreach ($sObjects as $sObject) {
      if (isset ($sObject->fields)) {
        $sObject->any = $this->_convertToAny($sObject->fields);
      }
    }
    $arg->sObjects = $sObjects;
		$this->setHeaders("upsert");
		return $this->sforce->upsert($arg)->result;
  }

	/**
	 * Deletes one or more new individual objects to your organization's data.
	 *
	 * @param array $ids    Array of fields
	 * @return DeleteResult
	 */
	public function delete($ids) {
		$this->setHeaders("delete");
		if(count($ids) > 200) {
			$result = array();
			$chunked_ids = array_chunk($ids, 200);
			foreach($chunked_ids as $cids) {
				$arg = new stdClass;
				$arg->ids = $cids;
				$result = array_merge($result, $this->sforce->delete($arg)->result);
			}
		}
    else {
			$arg = new stdClass;
			$arg->ids = $ids;
			$result = $this->sforce->delete($arg)->result;
		}
		return $result;
	}

	/**
	 * Set the endpoint.
	 *
	 * @param string $location   Location
	 */
	public function setEndpoint($location) {
		$this->location = $location;
		$this->sforce->__setLocation($location);
	}

	private function setHeaders($call=NULL) {
		$this->sforce->__setSoapHeaders(NULL);
		$header_array = array (
			$this->sessionHeader
		);
		$this->sforce->__setSoapHeaders($header_array);
	}

	public function setSessionHeader($id) {
		if ($id != NULL) {
			$this->sessionHeader = new SoapHeader($this->namespace, 'SessionHeader', array (
			 'sessionId' => $id
			));
			$this->sessionId = $id;
		}
    else {
			$this->sessionHeader = NULL;
			$this->sessionId = NULL;
		}
	}

	public function getSessionId() {
		return $this->sessionId;
	}

	public function getLocation() {
		return $this->location;
	}

	public function getConnection() {
		return $this->sforce;
	}

	protected function _convertToAny($fields) {
		$anyString = '';
		foreach ($fields as $key => $value) {
			$anyString = $anyString . '<' . $key . '>' . $value . '</' . $key . '>';
		}
		return $anyString;
	}

}
