<?php
require_once CARDSSHIELD_PLUGIN_DIR . '/includes/helpers/PayPalHelper.php';
require_once CARDSSHIELD_PLUGIN_DIR . '/includes/config/config-PayPal.php';
class PayPal extends PayPalHelper {

  private function _createOrder($postData) {
    $this->_checkToken();
    $this->_http->resetHelper();
    $this->_setDefaultHeaders();
    $this->_setAPIVersion('2');
    $this->_http->setUrl($this->_createApiUrl("checkout/orders"));
    $this->_http->setBody($postData);
    return $this->_respond($this->_http->sendRequest());
  }

  private function _getOrder($orderID) {
    $this->_checkToken();
    $this->_http->resetHelper();
    $this->_setDefaultHeaders();
    $this->_setAPIVersion('2');
    $this->_http->setUrl($this->_createApiUrl("checkout/orders/" . $orderID));
    return $this->_respond($this->_http->sendRequest());
  }

  private function _patchOrder($orderID, $patchData) {
    $this->_checkToken();
    $this->_http->resetHelper();
    $this->_setDefaultHeaders();
    $this->_setAPIVersion('2');
    $this->_http->setUrl($this->_createApiUrl("checkout/orders/" . $orderID));
    $this->_http->setBody($patchData);
    $this->_http->setRequestType('PATCH');
    return $this->_respond($this->_http->sendRequest());
  }

  private function _captureOrder($orderID) {
    $this->_checkToken();
    $this->_http->resetHelper();
    $this->_setDefaultHeaders();
    $this->_setAPIVersion('2');
    $this->_http->setUrl($this->_createApiUrl("checkout/orders/" . $orderID . "/capture"));
    $this->_http->setBody('');
    return $this->_respond($this->_http->sendRequest());
  }

  private function _disburseCapture($postData) {
    $this->_checkToken();
    $this->_http->resetHelper();
    $this->_setDefaultHeaders();
    $this->_setAPIVersion('1');
    $this->_http->setUrl($this->_createApiUrl("payments/referenced-payouts-items"));
    $this->_http->setBody($postData);
    return $this->_respond($this->_http->sendRequest());
  }

  private function _refundCapture($transactionID, $postData) {
    $this->_checkToken();
    $this->_http->resetHelper();
    $this->_setDefaultHeaders();
    $this->_http->addHeader("Prefer: return=representation");
    $this->_setAPIVersion('2');
    $this->_http->setUrl($this->_createApiUrl("payments/captures/" . $transactionID . "/refund"));
    $this->_http->setBody($postData);
    return $this->_respond($this->_http->sendRequest());
  }
  private function _shippingOrder($postData) {
    $this->_checkToken();
    $this->_http->resetHelper();
    $this->_setDefaultHeaders();
    $this->_setAPIVersion('1');
    $this->_http->setUrl($this->_createApiUrl("shipping/trackers"));
    $this->_http->setBody($postData);
    return $this->_respond($this->_http->sendRequest());
  }

  public function create($postData) {
  }

  public function get($orderID) {
    return $this->_getOrder($orderID);
  }

  public function patch($orderID, $patchData) {

    return $this->_patchOrder($orderID, $patchData);
  }

  public function capture($orderID) {
    return $this->_captureOrder($orderID);
  }

  public function disburse($transactionID) {
    $postData = array(
      "reference_type" => "TRANSACTION_ID",
      "reference_id" => $transactionID
    );
    return $this->_disburseCapture($postData);
  }

  public function refund($transactionID, $postData) {
    return $this->_refundCapture($transactionID, $postData);
  }
  public function shipping($postData) {
    $postData = json_encode($postData);
    return $this->_shippingOrder($postData);
  }
}