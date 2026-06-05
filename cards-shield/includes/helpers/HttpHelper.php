<?php

class HttpHelper {

	public $_curl = null;
	public $_headers = array();
	private $_lastStatusCode = 0;
	private $_lastResponseHeaders = '';
	private $_lastRequestError = '';

	public function __construct() {
		$this->_initCurl();
	}

	public function __destruct() {
		if (is_resource($this->_curl) || $this->_curl instanceof CurlHandle) {
			curl_close($this->_curl);
		}
	}

	private function _initCurl() {
		if (!function_exists('curl_version')) {
			trigger_error("Curl not available", E_USER_ERROR);
		} else {
			$this->_curl = curl_init();
			$this->_setDefaults();
		}
	}

	private function _setDefaults() {
                curl_setopt($this->_curl, CURLOPT_VERBOSE, 0);
                curl_setopt($this->_curl, CURLOPT_SSL_VERIFYPEER, TRUE);
                curl_setopt($this->_curl, CURLOPT_SSL_VERIFYHOST, 2);
                curl_setopt($this->_curl, CURLOPT_SSLVERSION, CURL_SSLVERSION_TLSv1_2);
		curl_setopt($this->_curl, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($this->_curl, CURLOPT_MAXREDIRS, 10);
		curl_setopt($this->_curl, CURLOPT_TIMEOUT, 30);
		curl_setopt($this->_curl, CURLOPT_HEADER, 1);
		curl_setopt($this->_curl, CURLINFO_HEADER_OUT, 1);
	}

	private function _setHeaders() {
		curl_setopt($this->_curl, CURLOPT_HTTPHEADER, $this->_headers);
	}

	private function _sendRequest() {
		$this->_setHeaders();
		$result = curl_exec($this->_curl);
		$this->_lastStatusCode = (int) curl_getinfo($this->_curl, CURLINFO_HTTP_CODE);
		$this->_lastRequestError = '';

		if (curl_errno($this->_curl)) {
			$this->_lastRequestError = curl_error($this->_curl);
			return array(
				'name' => 'CURL_ERROR',
				'message' => $this->_lastRequestError,
				'http_status' => 0,
				'success' => false,
			);
		}

		$headerSize = curl_getinfo($this->_curl, CURLINFO_HEADER_SIZE);
		$this->_lastResponseHeaders = substr($result, 0, $headerSize);
		$body = substr($result, $headerSize);
		$decoded = json_decode($body, true);

		if ($body !== '' && json_last_error() !== JSON_ERROR_NONE) {
			return array(
				'name' => 'INVALID_JSON',
				'message' => json_last_error_msg(),
				'raw_body' => $body,
				'http_status' => $this->_lastStatusCode,
				'success' => false,
			);
		}

		if (!is_array($decoded)) {
			$decoded = array();
		}

		$decoded['http_status'] = $this->_lastStatusCode;
		$decoded['success'] = $this->_lastStatusCode >= 200 && $this->_lastStatusCode < 300;
		return $decoded;
	}

	public function resetHelper() {
		if (is_resource($this->_curl) || $this->_curl instanceof CurlHandle) {
			curl_close($this->_curl);
		}
		$this->_curl = null;
		$this->_initCurl();
		$this->_headers = array();
		$this->_lastStatusCode = 0;
		$this->_lastResponseHeaders = '';
		$this->_lastRequestError = '';
	}

	public function setUrl($url) {
		curl_setopt($this->_curl, CURLOPT_URL, $url);
	}

	public function setBody($postData) {
		if (is_array($postData)) {
			$postData = json_encode($postData);
		}
		curl_setopt($this->_curl, CURLOPT_POSTFIELDS, $postData);
		curl_setopt($this->_curl, CURLOPT_POST, true);
		$this->setRequestType('POST');
	}

	public function setRequestType($type) {
		curl_setopt($this->_curl, CURLOPT_CUSTOMREQUEST, $type);
	}

	public function setAuthentication($authData) {
		curl_setopt($this->_curl, CURLOPT_USERPWD, $authData);
	}

	public function addHeader($header) {
		$this->_headers[] = $header;
	}

	public function sendRequest() {
		return $this->_sendRequest();
	}

	public function getLastStatusCode() {
		return $this->_lastStatusCode;
	}

	public function getLastRequestError() {
		return $this->_lastRequestError;
	}
}
