<?php
require_once CARDSSHIELD_PLUGIN_DIR . '/includes/helpers/helpers.php';
require_once CARDSSHIELD_PLUGIN_DIR . '/includes/class/class-PayPal.php';

function shield_sync_tracking_log($level, $message, $context = []) {
  $source = 'cards-shield-paypal-sync-tracking';
  $payload = array_merge([
    'source' => $source,
  ], is_array($context) ? $context : []);

  if (function_exists('wc_get_logger')) {
    $logger = wc_get_logger();
    if ($logger && method_exists($logger, $level)) {
      $logger->{$level}($message, $payload);
      return;
    }
  }

  if (function_exists('error_log')) {
    error_log('[' . $source . '] ' . $message . ' ' . wp_json_encode($payload));
  }
}

function shield_sync_tracking_trace_id() {
  return sanitize_text_field(isset($_SERVER['HTTP_X_SHIELD_TRACE_ID']) ? (string) $_SERVER['HTTP_X_SHIELD_TRACE_ID'] : '');
}

$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'POST') {
  Helpers::checkRequest('POST');
}
else {
  Helpers::checkRequest('GET');
}
if (!Helpers::verifyProxyHmacV2Request()) {
  shield_sync_tracking_log('warning', 'Rejected unauthorized sync tracking request', [
    'correlation_id' => shield_sync_tracking_trace_id(),
    'method' => $method,
    'request_uri' => isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '',
    'remote_addr' => isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '',
  ]);
  status_header(401);
  echo json_encode(['success' => false, 'error' => 'unauthorized']);
  exit;
}

$rawBody = file_get_contents('php://input');
$decodedJson = null;
if (!empty($rawBody)) {
  $tmp = json_decode($rawBody, true);
  if (json_last_error() === JSON_ERROR_NONE) {
    $decodedJson = $tmp;
  }
}

if (isset($decodedJson['trackers']) && is_array($decodedJson['trackers'])) {
  $shippingData = $decodedJson['trackers'];
}
elseif (isset($_POST['data-track'])) {
  $shippingData = $_POST['data-track'];
}
else {
  $shippingData = isset($_GET['data-track']) ? $_GET['data-track'] : [];
}

if (!is_array($shippingData)) {
  $shippingData = [];
}

$submittedCount = count($shippingData);
$managerId = Helpers::currentManagerId();
$traceId = shield_sync_tracking_trace_id();

$paypal = new PayPal();
$response = [
  'success' => false,
  'correlation_id' => $traceId,
  'submitted_count' => $submittedCount,
  'batches' => [],
  'total_sent' => 0,
  'total_reported_processable' => 0,
];

$batchSize = 3;
$includeFullPayload = false;

if (isset($_GET['debug']) && $_GET['debug'] === 'full') {
  $includeFullPayload = true;
}
elseif (isset($_POST['debug']) && $_POST['debug'] === 'full') {
  $includeFullPayload = true;
}
elseif (isset($decodedJson['debug']) && $decodedJson['debug'] === 'full') {
  $includeFullPayload = true;
}

try {
  if ($submittedCount === 0) {
    $response['error'] = 'No trackers received';
    shield_sync_tracking_log('warning', 'Sync tracking request had no trackers', [
      'correlation_id' => $traceId,
      'manager_id' => $managerId,
      'submitted_count' => 0,
    ]);
    echo json_encode($response);
    return;
  }

  $batches = array_chunk($shippingData, $batchSize);
  foreach ($batches as $index => $batch) {
    // Strip order_id before sending to PayPal API (internal field only)
    $cleanBatch = array_map(function ($tracker) {
      $clean = $tracker;
      unset($clean['order_id']);
      return $clean;
    }, $batch);
    $trackersPayload = ['trackers' => $cleanBatch];
    $batchResult = $paypal->shipping($trackersPayload);

    shield_sync_tracking_log('info', 'PayPal tracking batch API response', [
      'correlation_id' => $traceId,
      'manager_id' => $managerId,
      'batch_index' => $index,
      'http_status' => isset($batchResult['http_status']) ? $batchResult['http_status'] : null,
      'api_success' => isset($batchResult['success']) ? $batchResult['success'] : null,
      'has_tracker_identifiers' => isset($batchResult['order']['tracker_identifiers']),
      'tracker_identifier_count' => isset($batchResult['order']['tracker_identifiers']) && is_array($batchResult['order']['tracker_identifiers'])
        ? count($batchResult['order']['tracker_identifiers'])
        : 0,
      'has_errors' => isset($batchResult['order']['errors']),
      'error_count' => isset($batchResult['order']['errors']) && is_array($batchResult['order']['errors'])
        ? count($batchResult['order']['errors'])
        : 0,
      'raw' => $batchResult,
    ]);

    $processableCount = count($batch);
    $errorMessage = null;
    $batchSuccess = true;

    $duplicateIndexes = [];
    $errorIndexes = [];
    $trackerResults = [];

    $createdMap = [];
    if (
      (isset($batchResult['success']) && !$batchResult['success']) ||
      (isset($batchResult['http_status']) && ((int) $batchResult['http_status'] < 200 || (int) $batchResult['http_status'] >= 300))
    ) {
      $processableCount = 0;
      $batchSuccess = false;
      if (isset($batchResult['order']['errors'][0]['name']) || isset($batchResult['order']['errors'][0]['message'])) {
        $errorName = isset($batchResult['order']['errors'][0]['name']) ? $batchResult['order']['errors'][0]['name'] : 'PAYPAL_API_ERROR';
        $errorText = isset($batchResult['order']['errors'][0]['message']) ? $batchResult['order']['errors'][0]['message'] : 'PayPal API request failed.';
        $errorMessage = $errorName . ': ' . $errorText;
      } elseif (isset($batchResult['error']['name']) || isset($batchResult['error']['message'])) {
        $errorName = isset($batchResult['error']['name']) ? $batchResult['error']['name'] : 'PAYPAL_API_ERROR';
        $errorText = isset($batchResult['error']['message']) ? $batchResult['error']['message'] : 'PayPal API request failed.';
        $errorMessage = $errorName . ': ' . $errorText;
      } else {
        $errorMessage = 'PayPal API request failed';
      }
    }

    if (isset($batchResult['order']['tracker_identifiers']) && is_array($batchResult['order']['tracker_identifiers'])) {
      foreach ($batchResult['order']['tracker_identifiers'] as $ti) {
        if (isset($ti['transaction_id']) && isset($ti['tracking_number'])) {
          $createdMap[$ti['transaction_id'] . '|' . $ti['tracking_number']] = true;
        }
      }
    }

    if (
      isset($batchResult['order']['name']) &&
      isset($batchResult['order']['message']) &&
      empty($createdMap)
    ) {
      $processableCount = 0;
      $batchSuccess = false;
      $errorMessage = $batchResult['order']['name'] . ': ' . $batchResult['order']['message'];
    }

    if (isset($batchResult['order']['errors'][0]['details'])) {
      foreach ($batchResult['order']['errors'][0]['details'] as $detail) {
        if (isset($detail['issue']) && stripos($detail['issue'], 'Processable Tracker Collection Size') !== false) {
          if (preg_match('/(\d+)/', $detail['issue'], $m)) {
            $processableCount = (int)$m[1];
          }
          $errorMessage = $detail['issue'];
          $batchSuccess = false;
        }
      }
    }

    if (isset($batchResult['order']['errors']) && is_array($batchResult['order']['errors'])) {
      foreach ($batchResult['order']['errors'] as $err) {
        if (!isset($err['details']) || !is_array($err['details']))
          continue;
        foreach ($err['details'] as $detail) {
          if (!isset($detail['field']))
            continue;
          if (preg_match('##/trackers/(\d+)/tracking_number#', $detail['field'], $mm)) {
            $i = (int)$mm[1];
            $issue = isset($detail['issue']) ? $detail['issue'] : '';
            if (stripos($issue, 'TRACKING_NUMBER_ALREADY_EXIST') !== false) {
              $duplicateIndexes[$i] = true;
            }
            else {
              if (!isset($duplicateIndexes[$i])) {
                $errorIndexes[$i] = $issue ?: 'UNKNOWN_ERROR';
              }
            }
          }
        }
      }
    }

    foreach ($batch as $i => $tracker) {
      $key = (isset($tracker['transaction_id']) ? $tracker['transaction_id'] : '') . '|' . (isset($tracker['tracking_number']) ? $tracker['tracking_number'] : '');
      $orderId = isset($tracker['order_id']) ? $tracker['order_id'] : null;
      if (isset($duplicateIndexes[$i])) {
        $trackerResults[] = [
          'index'    => $i,
          'order_id' => $orderId,
          'tracker'  => $tracker,
          'status'   => 'duplicate',
          'message'  => 'Tracking number already exists on PayPal'
        ];
      }
      elseif (isset($errorIndexes[$i])) {
        $trackerResults[] = [
          'index'    => $i,
          'order_id' => $orderId,
          'tracker'  => $tracker,
          'status'   => 'error',
          'message'  => $errorIndexes[$i]
        ];
      }
      elseif (isset($createdMap[$key])) {
        $trackerResults[] = [
          'index'    => $i,
          'order_id' => $orderId,
          'tracker'  => $tracker,
          'status'   => 'created',
          'message'  => 'Created'
        ];
      }
      else {
        $trackerResults[] = [
          'index'    => $i,
          'order_id' => $orderId,
          'tracker'  => $tracker,
          'status'   => 'unknown',
          'message'  => 'Not returned by API and no explicit error'
        ];
      }
    }

    $createdCount = 0;
    $duplicateCount = 0;
    $errorCount = 0;
    $unknownCount = 0;
    foreach ($trackerResults as $trr) {
      switch ($trr['status']) {
        case 'created':
          $createdCount++;
          break;
        case 'duplicate':
          $duplicateCount++;
          break;
        case 'error':
          $errorCount++;
          break;
        case 'unknown':
          $unknownCount++;
          break;
      }
    }

    $response['batches'][] = [
      'batch_index' => $index,
      'sent_count' => count($batch),
      'processable_count' => $processableCount,
      'missing_count' => max(0, count($batch) - $processableCount),
      'success' => $batchSuccess,
      'error_message' => $errorMessage,
      'payload_sample' => $includeFullPayload ? $batch : array_slice($batch, 0, 2),
      'payload_truncated' => !$includeFullPayload && count($batch) > 2,
      'created_count' => $createdCount,
      'duplicate_count' => $duplicateCount,
      'error_count' => $errorCount,
      'unknown_count' => $unknownCount,
      'tracker_results' => $trackerResults,
      'raw' => $batchResult,
    ];
    $response['total_sent'] += count($batch);
    $response['total_reported_processable'] += $processableCount;

    if (!$batchSuccess) {
      shield_sync_tracking_log('error', 'PayPal tracking batch failed', [
        'correlation_id' => $traceId,
        'manager_id' => $managerId,
        'batch_index' => $index,
        'sent_count' => count($batch),
        'processable_count' => $processableCount,
        'created_count' => $createdCount,
        'duplicate_count' => $duplicateCount,
        'error_count' => $errorCount,
        'unknown_count' => $unknownCount,
        'error_message' => $errorMessage,
      ]);
    }
  }

  $response['success'] = true;
  foreach ($response['batches'] as $b) {
    if (!$b['success'] || $b['processable_count'] === 0) {
      $response['success'] = false;
      break;
    }
  }

  $response['diagnostic'] = [
    'batch_size' => $batchSize,
    'batch_total' => count($batches),
    'overall_missing' => max(0, $response['total_sent'] - $response['total_reported_processable'])
  ];

  $global = ['created' => 0, 'duplicate' => 0, 'error' => 0, 'unknown' => 0];
  foreach ($response['batches'] as $binfo) {
    $global['created'] += $binfo['created_count'];
    $global['duplicate'] += $binfo['duplicate_count'];
    $global['error'] += $binfo['error_count'];
    $global['unknown'] += $binfo['unknown_count'];
  }
  $response['totals'] = $global;

  shield_sync_tracking_log($response['success'] ? 'info' : 'warning', 'Completed PayPal tracking sync request', [
    'correlation_id' => $traceId,
    'manager_id' => $managerId,
    'batch_total' => count($batches),
    'submitted_count' => $submittedCount,
    'total_sent' => $response['total_sent'],
    'total_reported_processable' => $response['total_reported_processable'],
    'created_count' => $global['created'],
    'duplicate_count' => $global['duplicate'],
    'error_count' => $global['error'],
    'unknown_count' => $global['unknown'],
    'success' => $response['success'],
  ]);
}
catch (Exception $e) {
  $response['success'] = false;
  $response['exception'] = $e->getMessage();
  shield_sync_tracking_log('error', 'Unhandled exception during PayPal tracking sync', [
    'correlation_id' => $traceId,
    'manager_id' => $managerId,
    'submitted_count' => $submittedCount,
    'exception' => $e->getMessage(),
  ]);
}

echo json_encode($response);
