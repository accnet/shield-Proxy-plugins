<?php
require_once CARDSSHIELD_PLUGIN_DIR . '/includes/helpers/helpers.php';
require_once CARDSSHIELD_PLUGIN_DIR . '/includes/class/class-PayPal.php';

$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'POST') {
  Helpers::checkRequest('POST');
}
else {
  Helpers::checkRequest('GET');
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

$paypal = new PayPal();
$response = [
  'success' => false,
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
    echo json_encode($response);
    return;
  }

  $batches = array_chunk($shippingData, $batchSize);
  foreach ($batches as $index => $batch) {
    $trackersPayload = ['trackers' => $batch];
    $batchResult = $paypal->shipping($trackersPayload);

    $processableCount = count($batch);
    $errorMessage = null;
    $batchSuccess = true;

    $duplicateIndexes = [];
    $errorIndexes = [];
    $trackerResults = [];

    $createdMap = [];
    if (isset($batchResult['order']['tracker_identifiers']) && is_array($batchResult['order']['tracker_identifiers'])) {
      foreach ($batchResult['order']['tracker_identifiers'] as $ti) {
        if (isset($ti['transaction_id']) && isset($ti['tracking_number'])) {
          $createdMap[$ti['transaction_id'] . '|' . $ti['tracking_number']] = true;
        }
      }
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
      if (isset($duplicateIndexes[$i])) {
        $trackerResults[] = [
          'index' => $i,
          'tracker' => $tracker,
          'status' => 'duplicate',
          'message' => 'Tracking number already exists on PayPal'
        ];
      }
      elseif (isset($errorIndexes[$i])) {
        $trackerResults[] = [
          'index' => $i,
          'tracker' => $tracker,
          'status' => 'error',
          'message' => $errorIndexes[$i]
        ];
      }
      elseif (isset($createdMap[$key])) {
        $trackerResults[] = [
          'index' => $i,
          'tracker' => $tracker,
          'status' => 'created',
          'message' => 'Created'
        ];
      }
      else {
        $trackerResults[] = [
          'index' => $i,
          'tracker' => $tracker,
          'status' => 'unknown',
          'message' => 'Not returned by API and no explicit error'
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
  }

  $response['success'] = true;
  foreach ($response['batches'] as $b) {
    if ($b['processable_count'] === 0) {
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

  if (function_exists('error_log')) {
    error_log('[cardsshield-sync-tracking] batches=' . count($batches) . ' total_sent=' . $response['total_sent'] . ' total_processable=' . $response['total_reported_processable']);
  }
}
catch (Exception $e) {
  $response['success'] = false;
  $response['exception'] = $e->getMessage();
}

echo json_encode($response);
