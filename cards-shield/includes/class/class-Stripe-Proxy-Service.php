<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once CARDSSHIELD_PLUGIN_DIR . '/includes/stripe-php/init.php';
require_once CARDSSHIELD_PLUGIN_DIR . '/includes/helpers/helpers.php';
require_once CARDSSHIELD_PLUGIN_DIR . '/includes/config/config-Stripe.php';

class Shield_Stripe_Proxy_Service {
    private $stripe;
    private $traceId;
    private $managerId;
    private $source = 'cards-shield-stripe-proxy';

    public function __construct() {
        $this->traceId = $this->readHeader('X-Shield-Trace-Id');
        if ($this->traceId === '') {
            $this->traceId = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : uniqid('stripe-trace-', true);
        }
        $this->managerId = Helpers::currentManagerId();

        if (!defined('STRIPE_SECRET_KEY') || !STRIPE_SECRET_KEY) {
            throw new RuntimeException('Stripe secret key is not configured.');
        }

        $this->stripe = new \Stripe\StripeClient(STRIPE_SECRET_KEY);
    }

    public function makePayment(array $payload) {
        $validationError = $this->validateMakePaymentPayload($payload);
        if ($validationError) {
            return $validationError;
        }

        $amountDecimal = (float) $payload['amount'];
        $currency = strtolower((string) $payload['currency']);
        $amountMinor = $this->normalizeAmountToMinor($amountDecimal, $currency);
        $shipping = (isset($payload['shipping']) && is_array($payload['shipping'])) ? $payload['shipping'] : [];
        $idempotencyKey = $this->buildIdempotencyKey([
            (string) ($payload['order_id'] ?? ''),
            (string) ($payload['payment_intent'] ?? ''),
            (string) ($payload['payment_method_id'] ?? ''),
            (string) ($payload['amount'] ?? ''),
            $currency,
        ]);

        if (!Helpers::acquireIdempotencyLock('stripe_make_payment', $idempotencyKey, 900)) {
            return $this->errorResponse(409, 'duplicate_request', 'Duplicate make-payment request', 'duplicate_request');
        }

        try {
            $paymentIntent = null;
            $paymentIntentId = isset($payload['payment_intent']) ? sanitize_text_field((string) $payload['payment_intent']) : '';

            if ($paymentIntentId !== '') {
                try {
                    $paymentIntent = $this->stripe->paymentIntents->retrieve($paymentIntentId);
                    if (in_array($paymentIntent->status, ['requires_payment_method', 'requires_confirmation', 'requires_action'], true)) {
                        $paymentIntent = $this->stripe->paymentIntents->update($paymentIntentId, [
                            'payment_method' => $payload['payment_method_id'],
                            'amount' => $amountMinor,
                            'currency' => $currency,
                            'payment_method_options' => [
                                'card' => [
                                    'request_three_d_secure' => 'automatic',
                                ],
                            ],
                        ]);
                    }
                } catch (\Throwable $e) {
                    $this->log('warning', 'Existing PaymentIntent could not be reused', [
                        'payment_intent_id' => $paymentIntentId,
                        'exception' => $e->getMessage(),
                    ]);
                    $paymentIntent = null;
                }
            }

            if (empty($paymentIntent)) {
                $paymentIntent = $this->stripe->paymentIntents->create([
                    'amount' => $amountMinor,
                    'currency' => $currency,
                    'payment_method' => $payload['payment_method_id'],
                    'description' => (string) ($payload['statement_descriptor'] ?? ''),
                    'shipping' => $shipping,
                    'payment_method_options' => [
                        'card' => [
                            'request_three_d_secure' => 'automatic',
                        ],
                    ],
                    'metadata' => [
                        'customer_email' => (string) ($payload['customer_email'] ?? ''),
                        'customer_name' => (string) ($payload['name'] ?? ''),
                        'order_id' => (string) ($payload['order_invoice'] ?? ''),
                        'woo_order_id' => (string) ($payload['order_id'] ?? ''),
                        'shield_id' => (string) ($payload['shield_id'] ?? ''),
                        'manager_id' => $this->managerId,
                        'manager_callback_url' => esc_url_raw((string) ($payload['manager_callback_url'] ?? '')),
                        'merchant_site' => home_url(),
                    ],
                ], [
                    'idempotency_key' => substr(hash('sha256', $idempotencyKey), 0, 255),
                ]);
            }

            if ($paymentIntent->status === 'succeeded') {
                $total = $payload['amount'] ?? 0.0;
                Helpers::sendTransactionToShield($total, 'Stripe');
            }

            $chargeIntent = $this->stripe->paymentIntents->retrieve(
                $paymentIntent->id,
                ['expand' => ['latest_charge.refunds', 'latest_charge.balance_transaction']]
            );

            $this->log('info', 'Stripe make-payment completed', [
                'payment_intent_id' => $paymentIntent->id,
                'status' => $paymentIntent->status,
                'amount_minor' => $amountMinor,
                'currency' => $currency,
            ]);

            if ($paymentIntent->status === 'requires_action') {
                Helpers::trackStripeWebhookPayment($paymentIntent->id, [
                    'mode' => $this->detectMode(),
                    'state' => 'requires_action',
                    'order_id' => (string) ($payload['order_id'] ?? ''),
                    'order_invoice' => (string) ($payload['order_invoice'] ?? ''),
                    'shield_id' => (string) ($payload['shield_id'] ?? ''),
                    'manager_callback_url' => esc_url_raw((string) ($payload['manager_callback_url'] ?? '')),
                    'manager_id' => $this->managerId,
                    'proxy_id' => home_url(),
                    'trace_id' => $this->traceId,
                    'is_3ds_candidate' => true,
                    'last_source' => 'make_payment',
                ]);
            }

            return $this->successResponse([
                'code' => 'ok',
                'message' => 'Payment intent processed',
                'status' => 'success',
                'payment_intent' => $paymentIntent,
                'charge' => $chargeIntent->latest_charge,
                'payment_intent_id' => $paymentIntent->id,
            ]);
        } catch (\Stripe\Exception\ApiErrorException $e) {
            return $this->handleStripeException($e, 'Stripe make-payment failed');
        } catch (\Throwable $e) {
            return $this->handleThrowable($e, 'Stripe make-payment failed unexpectedly');
        }
    }

    public function confirmPayment(array $payload) {
        $paymentIntentId = sanitize_text_field((string) ($payload['payment_intent_id'] ?? ''));
        if ($paymentIntentId === '') {
            return $this->errorResponse(400, 'invalid_payload', 'payment_intent_id is required', 'error');
        }

        try {
            $paymentIntent = $this->stripe->paymentIntents->retrieve(
                $paymentIntentId,
                ['expand' => ['latest_charge.refunds', 'latest_charge.balance_transaction']]
            );

            if ($paymentIntent->status === 'succeeded') {
                $total = $payload['amount'] ?? 0.0;
                Helpers::sendTransactionToShield($total, 'Stripe');
            }

            $status = $paymentIntent->status;
            $normalizedStatus = in_array($status, ['succeeded', 'requires_capture'], true) ? 'success' : $status;

            $this->log('info', 'Stripe confirm-payment completed', [
                'payment_intent_id' => $paymentIntentId,
                'status' => $status,
            ]);

            if (in_array($status, ['requires_action', 'processing'], true)) {
                Helpers::trackStripeWebhookPayment($paymentIntentId, [
                    'mode' => $this->detectMode(),
                    'state' => $status,
                    'order_id' => (string) ($payload['order_id'] ?? ''),
                    'shield_id' => (string) ($payload['shield_id'] ?? ''),
                    'manager_callback_url' => esc_url_raw((string) ($payload['manager_callback_url'] ?? '')),
                    'manager_id' => $this->managerId,
                    'proxy_id' => home_url(),
                    'trace_id' => $this->traceId,
                    'is_3ds_candidate' => true,
                    'last_source' => 'confirm_payment',
                ]);
            }

            return $this->successResponse([
                'code' => 'ok',
                'message' => 'Payment intent retrieved',
                'status' => $normalizedStatus,
                'payment_intent' => $paymentIntent,
                'charge' => $paymentIntent->latest_charge,
                'payment_intent_id' => $paymentIntentId,
            ]);
        } catch (\Stripe\Exception\ApiErrorException $e) {
            return $this->handleStripeException($e, 'Stripe confirm-payment failed');
        } catch (\Throwable $e) {
            return $this->handleThrowable($e, 'Stripe confirm-payment failed unexpectedly');
        }
    }

    public function refundPayment(array $payload) {
        $transactionId = sanitize_text_field((string) ($payload['transaction_id'] ?? ''));
        $amount = isset($payload['amount']) ? (int) $payload['amount'] : 0;
        $reason = sanitize_text_field((string) ($payload['reason'] ?? 'requested_by_customer'));

        if ($transactionId === '' || $amount <= 0) {
            return $this->errorResponse(400, 'invalid_payload', 'transaction_id and positive amount are required', 'error');
        }

        try {
            $charge = $this->stripe->paymentIntents->retrieve($transactionId);
            $refund = $this->stripe->refunds->create([
                'charge' => $charge->latest_charge,
                'reason' => $reason ?: 'requested_by_customer',
                'amount' => $amount,
            ]);
            $expandedCharge = $this->stripe->paymentIntents->retrieve(
                $transactionId,
                ['expand' => ['latest_charge.refunds', 'latest_charge.balance_transaction']]
            );

            $this->log('info', 'Stripe refund completed', [
                'payment_intent_id' => $transactionId,
                'refund_id' => isset($refund->id) ? $refund->id : null,
                'amount_minor' => $amount,
            ]);

            return $this->successResponse([
                'code' => 'ok',
                'message' => 'Refund created',
                'status' => 'success',
                'refund_obj' => $refund,
                'charge_obj' => $expandedCharge,
                'payment_intent_id' => $transactionId,
            ]);
        } catch (\Stripe\Exception\ApiErrorException $e) {
            return $this->handleStripeException($e, 'Stripe refund failed');
        } catch (\Throwable $e) {
            return $this->handleThrowable($e, 'Stripe refund failed unexpectedly');
        }
    }

    public function health() {
        $force = isset($_GET['force']) && sanitize_text_field((string) $_GET['force']) === 'true';
        $cacheKey = 'shield_stripe_health_' . md5((string) (defined('STRIPE_SECRET_KEY') ? STRIPE_SECRET_KEY : 'missing'));

        if (!$force) {
            $cached = get_transient($cacheKey);
            if (is_array($cached)) {
                $cached['correlation_id'] = $this->traceId;
                return $cached;
            }
        }

        $mode = $this->detectMode();
        $response = $this->successResponse([
            'code' => 'ok',
            'message' => 'Stripe health check completed',
            'status' => 'active',
            'health_status' => 'healthy',
            'mode' => $mode,
            'checks' => [
                'config' => 'ok',
                'auth' => 'ok',
                'remote' => 'ok',
            ],
        ]);

        try {
            $account = $this->stripe->accounts->retrieve();
            $response['stripe_account_id'] = isset($account->id) ? $account->id : null;
            $this->log('info', 'Stripe health check passed', [
                'mode' => $mode,
                'health_status' => 'healthy',
                'stripe_account_id' => $response['stripe_account_id'],
            ]);
        } catch (\Stripe\Exception\AuthenticationException $e) {
            $response = $this->errorResponse(200, 'stripe_auth_failed', $e->getMessage(), 'deactive', $e);
            $response['health_status'] = 'auth_failed';
            $response['mode'] = $mode;
            $response['checks'] = [
                'config' => 'ok',
                'auth' => 'failed',
                'remote' => 'failed',
            ];
            $response['retryable'] = false;
            $this->log('error', 'Stripe health auth failed', [
                'mode' => $mode,
                'health_status' => 'auth_failed',
                'exception' => $e->getMessage(),
            ]);
        } catch (\Stripe\Exception\ApiConnectionException $e) {
            $response['status'] = 'active';
            $response['health_status'] = 'degraded';
            $response['checks']['remote'] = 'timeout';
            $response['retryable'] = true;
            $response['message'] = $e->getMessage();
            $this->log('warning', 'Stripe health degraded due to connection error', [
                'mode' => $mode,
                'health_status' => 'degraded',
                'exception' => $e->getMessage(),
            ]);
        } catch (\Stripe\Exception\ApiErrorException $e) {
            $response['status'] = 'active';
            $response['health_status'] = 'degraded';
            $response['checks']['remote'] = 'failed';
            $response['retryable'] = true;
            $response['message'] = $e->getMessage();
            $this->log('warning', 'Stripe health degraded due to API error', [
                'mode' => $mode,
                'health_status' => 'degraded',
                'exception' => $e->getMessage(),
                'stripe_code' => method_exists($e, 'getStripeCode') ? $e->getStripeCode() : '',
            ]);
        } catch (\Throwable $e) {
            $response['status'] = 'active';
            $response['health_status'] = 'degraded';
            $response['checks']['remote'] = 'failed';
            $response['retryable'] = true;
            $response['message'] = $e->getMessage();
            $this->log('warning', 'Stripe health degraded unexpectedly', [
                'mode' => $mode,
                'health_status' => 'degraded',
                'exception' => $e->getMessage(),
            ]);
        }

        $cachedResponse = $response;
        $cachedResponse['correlation_id'] = '';
        set_transient($cacheKey, $cachedResponse, 60);

        return $response;
    }

    private function validateMakePaymentPayload(array $payload) {
        if (empty($payload['amount']) || empty($payload['currency']) || empty($payload['payment_method_id'])) {
            return $this->errorResponse(400, 'invalid_payload', 'amount, currency, and payment_method_id are required', 'ERROR');
        }
        if ((float) $payload['amount'] <= 0) {
            return $this->errorResponse(400, 'invalid_amount', 'amount must be greater than zero', 'ERROR');
        }
        return null;
    }

    private function normalizeAmountToMinor($amount, $currency) {
        $zeroDecimalCurrencies = ['bif','clp','djf','gnf','jpy','kmf','krw','mga','pyg','rwf','ugx','vnd','vuv','xaf','xof','xpf'];
        if (in_array(strtolower((string) $currency), $zeroDecimalCurrencies, true)) {
            return (int) round((float) $amount);
        }
        return (int) round(((float) $amount) * 100);
    }

    private function detectMode() {
        $shieldStripe = get_option('shield_stripe') ?? [];
        $testMode = is_array($shieldStripe) ? !empty($shieldStripe['test_mode']) : false;
        return $testMode ? 'test' : 'live';
    }

    private function handleStripeException($e, $message) {
        $code = method_exists($e, 'getStripeCode') ? $e->getStripeCode() : '';
        $type = method_exists($e, 'getError') && $e->getError() ? ($e->getError()->type ?? '') : '';
        $normalizedCode = $code ?: ($type ?: 'stripe_api_error');

        $this->log('error', $message, [
            'stripe_code' => $code,
            'stripe_type' => $type,
            'exception' => $e->getMessage(),
        ]);

        return $this->errorResponse(400, $normalizedCode, $e->getMessage(), 'error', $e);
    }

    private function handleThrowable($e, $message) {
        $this->log('error', $message, [
            'exception' => $e->getMessage(),
        ]);

        return $this->errorResponse(500, 'internal_error', $e->getMessage(), 'error', $e);
    }

    private function successResponse(array $data) {
        return array_merge([
            'success' => true,
            'correlation_id' => $this->traceId,
            'retryable' => false,
            'http_status' => 200,
        ], $data);
    }

    private function errorResponse($httpStatus, $code, $message, $status = 'error', $exception = null) {
        $payload = [
            'success' => false,
            'correlation_id' => $this->traceId,
            'retryable' => $httpStatus >= 500,
            'http_status' => (int) $httpStatus,
            'code' => (string) $code,
            'message' => (string) $message,
            'status' => (string) $status,
            'payment_intent' => [],
            'charge' => [],
            'err' => [
                'message' => (string) $message,
                'type' => is_object($exception) ? get_class($exception) : null,
            ],
        ];

        return $payload;
    }

    private function buildIdempotencyKey(array $parts) {
        return Helpers::getIdempotencyKey(implode('|', $parts));
    }

    private function log($level, $message, array $context = []) {
        $payload = array_merge([
            'source' => $this->source,
            'correlation_id' => $this->traceId,
            'manager_id' => $this->managerId,
        ], $context);

        if (function_exists('wc_get_logger')) {
            $logger = wc_get_logger();
            if ($logger && method_exists($logger, $level)) {
                $logger->{$level}($message, $payload);
                return;
            }
        }

        if (function_exists('error_log')) {
            error_log('[' . $this->source . '] ' . $message . ' ' . wp_json_encode($payload));
        }
    }

    private function readHeader($name) {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return isset($_SERVER[$key]) ? sanitize_text_field((string) $_SERVER[$key]) : '';
    }
}
