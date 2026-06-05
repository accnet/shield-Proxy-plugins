<?php

require_once CARDSSHIELD_PLUGIN_DIR . '/includes/helpers/HttpHelper.php';

/**
 *	PayPal helper class for REST API requests.
 *
 */

class PayPalHelper {

	protected $_http = null;
	protected $_apiUrl = null;
	protected $_token = null;
	protected $_apiVersion = null;

	public function __construct() {
		$this->_http = new HttpHelper;
		$this->_apiUrl = PAYPAL_ENDPOINTS;
		$this->_setAPIVersion('1');
	}

	protected function _setDefaultHeaders() {
		$this->_http->addHeader("PayPal-Partner-Attribution-Id: " . SBN_CODE);
		$this->_http->addHeader("Content-Type: application/json");
		if ($this->_token !== null) {
			$this->_http->addHeader("Authorization: Bearer " . $this->_token);
		}
	}

	protected function _setAPIVersion($version) {
		if ((int)$version === 2) {
			$this->_apiVersion = 'v2';
		} else {
			$this->_apiVersion = 'v1';
		}
	}

	protected function _createApiUrl($resource) {
		return $this->_apiUrl . "/" . $this->_apiVersion . "/" . $resource;
	}

	protected function _getToken() {
		if (empty(PAYPAL_ENDPOINTS) || empty(PAYPAL_CLIENT_ID) || empty(PAYPAL_CLIENT_SECRET)) {
			throw new RuntimeException('PayPal credentials are not configured.');
		}

		$this->_http->resetHelper();
		$this->_setDefaultHeaders();
		$this->_setAPIVersion('1');
		$this->_http->setUrl($this->_createApiUrl("oauth2/token"));
		$this->_http->setAuthentication(PAYPAL_CLIENT_ID . ":" . PAYPAL_CLIENT_SECRET);
		$this->_http->setBody("grant_type=client_credentials");
		$returnData = $this->_http->sendRequest();
		if (empty($returnData['access_token'])) {
			$message = isset($returnData['message']) ? $returnData['message'] : 'PayPal access token was not returned.';
			$name = isset($returnData['name']) ? $returnData['name'] : 'PAYPAL_AUTH_FAILED';
			throw new RuntimeException($name . ': ' . $message);
		}
		$this->_token = $returnData['access_token'];
	}

	protected function _checkToken() {
		if ($this->_token === null) {
			$this->_getToken();
		}
	}

	protected function _respond($data) {
		$success = isset($data['success']) ? (bool) $data['success'] : true;
		$httpStatus = isset($data['http_status']) ? (int) $data['http_status'] : $this->_http->getLastStatusCode();

		return array(
			"status" => $success ? "success" : "error",
			"success" => $success,
			"http_status" => $httpStatus,
			"error" => $success ? null : array(
				"name" => isset($data['name']) ? $data['name'] : 'PAYPAL_API_ERROR',
				"message" => isset($data['message']) ? $data['message'] : 'PayPal API request failed.',
			),
			"order" => $data
		);
	}
}
