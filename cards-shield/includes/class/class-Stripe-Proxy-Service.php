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

        $orderId = $this->normalizeReference($payload['order_id'] ?? null);
        $orderInvoice = $this->normalizeReference($payload['order_invoice'] ?? null) ?: $orderId;
        $shieldId = $this->normalizeReference($payload['shield_id'] ?? null);
        $managerCallbackUrl = esc_url_raw((string) ($payload['manager_callback_url'] ?? ''));
        $amountDecimal = (float) $payload['amount'];
        $currency = strtolower((string) $payload['currency']);
        $amountMinor = $this->normalizeAmountToMinor($amountDecimal, $currency);
        $shipping = (isset($payload['shipping']) && is_array($payload['shipping'])) ? $payload['shipping'] : [];
        $idempotencyKey = $this->buildIdempotencyKey([
            (string) $orderId,
            (string) ($payload['payment_intent'] ?? ''),
            (string) ($payload['payment_method_id'] ?? ''),
            (string) ($payload['amount'] ?? ''),
            $currency,
        ]);

        if (!Helpers::acquireIdempotencyLock('stripe_make_payment', $idempotencyKey, 900)) {
            // Log duplicate event locally for admin visibility — not sent to SaaS, not counted in payment stats.
            // Only log a partial hash of the idempotency key to avoid exposing order_id+PI combinations in logs.
            $this->log('warning', 'Stripe duplicate make-payment request blocked by idempotency lock', [
                'idempotency_hash' => substr(md5($idempotencyKey), 0, 8),
                'order_id'         => $orderId,
                'manager_id'       => $this->managerId,
                'trace_id'         => $this->traceId,
            ]);
            return $this->errorResponse(409, 'duplicate_request', 'Duplicate make-payment request', 'duplicate_request');
        }

        $routeId = $this->resolveOrCreateCallbackRoute($payload);

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

            if (!empty($paymentIntent)) {
                $this->linkPaymentIntentToRoute($routeId, $paymentIntent->id);
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
                        'order_id' => (string) ($orderInvoice ?? ''),
                        'woo_order_id' => (string) ($orderId ?? ''),
                        'processor_id' => (string) ($shieldId ?? ''),
                        'manager_id' => $this->managerId,
                        'route_id' => $routeId,
                        'trace_id' => $this->traceId,
                        'merchant_site' => home_url(),
                    ],
                ], [
                    'idempotency_key' => substr(hash('sha256', $idempotencyKey), 0, 255),
                ]);
                $this->linkPaymentIntentToRoute($routeId, $paymentIntent->id);
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

            $this->queuePaymentTransitionLog([
                'source' => 'make_payment',
                'transactionId' => $paymentIntent->id,
                'nextState' => (string) $paymentIntent->status,
                'amount' => $amountDecimal,
                'currency' => strtoupper($currency),
                'orderId' => $orderId,
                'orderNumber' => $orderInvoice,
                'site2Url' => $managerCallbackUrl,
                'metadata' => [
                    'amount' => $amountDecimal,
                    'currency' => strtoupper($currency),
                    'is_3ds' => $paymentIntent->status === 'requires_action',
                    'capture_method' => isset($paymentIntent->capture_method) ? (string) $paymentIntent->capture_method : '',
                ],
            ]);

            if ($paymentIntent->status === 'requires_action') {
                Helpers::trackStripeWebhookPayment($paymentIntent->id, [
                    'mode' => $this->detectMode(),
                    'state' => 'requires_action',
                    'order_id' => $orderId,
                    'order_invoice' => $orderInvoice,
                    'shield_id' => $shieldId,
                    'manager_callback_url' => $managerCallbackUrl,
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

    public function createLinkExpressIntent(array $payload) {
        $validationError = $this->validateLinkExpressPayload($payload);
        if ($validationError) {
            return $validationError;
        }

        $orderId = $this->normalizeReference($payload['order_id'] ?? null);
        $orderInvoice = $this->normalizeReference($payload['order_invoice'] ?? null) ?: $orderId;
        $shieldId = $this->normalizeReference($payload['shield_id'] ?? null);
        $managerCallbackUrl = esc_url_raw((string) ($payload['manager_callback_url'] ?? ''));
        $amountDecimal = (float) $payload['amount'];
        $currency = strtolower((string) $payload['currency']);
        $amountMinor = $this->normalizeAmountToMinor($amountDecimal, $currency);
        $shipping = (isset($payload['shipping']) && is_array($payload['shipping'])) ? $payload['shipping'] : [];
        $idempotencyKey = $this->buildIdempotencyKey([
            'link',
            (string) $orderId,
            (string) ($payload['confirmation_token'] ?? ''),
            (string) ($payload['amount'] ?? ''),
            $currency,
        ]);

        if (!Helpers::acquireIdempotencyLock('stripe_link_express_intent', $idempotencyKey, 900)) {
            return $this->errorResponse(409, 'duplicate_request', 'Duplicate Link express request', 'duplicate_request');
        }

        $routeId = $this->resolveOrCreateCallbackRoute($payload);

        try {
            $createParams = [
                'amount' => $amountMinor,
                'currency' => $currency,
                'capture_method' => (string) ($payload['capture_method'] ?? 'automatic') === 'manual' ? 'manual' : 'automatic',
                'description' => (string) ($payload['statement_descriptor'] ?? ''),
                'shipping' => $shipping,
                'payment_method_types' => ['link', 'card'],
                'metadata' => [
                    'customer_email' => (string) ($payload['customer_email'] ?? ''),
                    'customer_name' => (string) ($payload['name'] ?? ''),
                    'order_id' => (string) ($orderInvoice ?? ''),
                    'woo_order_id' => (string) ($orderId ?? ''),
                    'processor_id' => (string) ($shieldId ?? ''),
                    'manager_id' => $this->managerId,
                    'route_id' => $routeId,
                    'trace_id' => $this->traceId,
                    'merchant_site' => home_url(),
                    'funding_source' => 'link_express',
                ],
            ];

            $paymentIntent = $this->stripe->paymentIntents->create($createParams, [
                'idempotency_key' => substr(hash('sha256', $idempotencyKey), 0, 255),
            ]);
            $this->linkPaymentIntentToRoute($routeId, $paymentIntent->id);

            Helpers::trackStripeWebhookPayment($paymentIntent->id, [
                'mode' => $this->detectMode(),
                'state' => isset($paymentIntent->status) ? (string) $paymentIntent->status : 'requires_payment_method',
                'order_id' => $orderId,
                'order_invoice' => $orderInvoice,
                'shield_id' => $shieldId,
                'manager_callback_url' => $managerCallbackUrl,
                'manager_id' => $this->managerId,
                'proxy_id' => home_url(),
                'trace_id' => $this->traceId,
                'is_3ds_candidate' => true,
                'last_source' => 'link_express_create_intent',
            ]);

            $this->queuePaymentTransitionLog([
                'source' => 'link_express_create_intent',
                'transactionId' => $paymentIntent->id,
                'nextState' => (string) $paymentIntent->status,
                'amount' => $amountDecimal,
                'currency' => strtoupper($currency),
                'orderId' => $orderId,
                'orderNumber' => $orderInvoice,
                'site2Url' => $managerCallbackUrl,
                'metadata' => [
                    'amount' => $amountDecimal,
                    'currency' => strtoupper($currency),
                    'capture_method' => isset($paymentIntent->capture_method) ? (string) $paymentIntent->capture_method : '',
                    'funding_source' => 'link_express',
                ],
            ]);

            $this->log('info', 'Stripe Link express intent created', [
                'payment_intent_id' => $paymentIntent->id,
                'status' => $paymentIntent->status,
                'amount_minor' => $amountMinor,
                'currency' => $currency,
            ]);

            return $this->successResponse([
                'code' => 'ok',
                'message' => 'Link express payment intent created',
                'status' => 'success',
                'payment_intent' => $paymentIntent,
                'payment_intent_id' => $paymentIntent->id,
                'client_secret' => $paymentIntent->client_secret,
                'charge' => [],
            ]);
        } catch (\Stripe\Exception\ApiErrorException $e) {
            return $this->handleStripeException($e, 'Stripe Link express intent failed');
        } catch (\Throwable $e) {
            return $this->handleThrowable($e, 'Stripe Link express intent failed unexpectedly');
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
            $metadata = isset($paymentIntent->metadata) && is_object($paymentIntent->metadata) ? $paymentIntent->metadata : null;
            $orderId = $this->normalizeReference($payload['order_id'] ?? null);
            if ($orderId === null && is_object($metadata) && isset($metadata->woo_order_id)) {
                $orderId = $this->normalizeReference((string) $metadata->woo_order_id);
            }
            $orderInvoice = $this->normalizeReference($payload['order_invoice'] ?? null);
            if ($orderInvoice === null && is_object($metadata) && isset($metadata->order_id)) {
                $orderInvoice = $this->normalizeReference((string) $metadata->order_id);
            }
            if ($orderInvoice === null) {
                $orderInvoice = $orderId;
            }
            $shieldId = $this->normalizeReference($payload['shield_id'] ?? null);
            if ($shieldId === null && is_object($metadata) && isset($metadata->processor_id)) {
                $shieldId = $this->normalizeReference((string) $metadata->processor_id);
            }
            $managerCallbackUrl = esc_url_raw((string) ($payload['manager_callback_url'] ?? ''));
            // manager_callback_url không còn được lưu trong Stripe metadata.
            // URL site2 được giải quyết qua route_id transient (Tier 1) hoặc local tracking (Tier 2).

            $this->log('info', 'Stripe confirm-payment completed', [
                'payment_intent_id' => $paymentIntentId,
                'status' => $status,
            ]);

            $this->queuePaymentTransitionLog([
                'source' => 'confirm_payment',
                'transactionId' => $paymentIntentId,
                'nextState' => (string) $status,
                'amount' => isset($payload['amount']) ? (float) $payload['amount'] : null,
                'currency' => isset($payload['currency']) ? strtoupper((string) $payload['currency']) : null,
                'orderId' => $orderId,
                'orderNumber' => $orderInvoice,
                'site2Url' => $managerCallbackUrl,
                'metadata' => [
                    'is_3ds' => in_array($status, ['requires_action', 'processing'], true),
                ],
            ]);

            if (in_array($status, ['requires_action', 'processing'], true)) {
                Helpers::trackStripeWebhookPayment($paymentIntentId, [
                    'mode' => $this->detectMode(),
                    'state' => $status,
                    'order_id' => $orderId,
                    'order_invoice' => $orderInvoice,
                    'shield_id' => $shieldId,
                    'manager_callback_url' => $managerCallbackUrl,
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

            $this->queuePaymentTransitionLog([
                'source' => 'refund',
                'transactionId' => $transactionId,
                'eventId' => isset($refund->id) ? (string) $refund->id : $transactionId . ':refund',
                'nextState' => 'refunded',
                'amount' => $amount / 100,
                'currency' => isset($expandedCharge->currency) ? strtoupper((string) $expandedCharge->currency) : null,
                'metadata' => [
                    'amount' => $amount / 100,
                    'currency' => isset($expandedCharge->currency) ? strtoupper((string) $expandedCharge->currency) : null,
                    'gateway_response_code' => isset($refund->status) ? (string) $refund->status : 'succeeded',
                ],
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
            $this->queuePaymentTransitionLog([
                'source' => 'refund',
                'transactionId' => $transactionId ?: 'unknown',
                'eventId' => ($transactionId ?: 'unknown') . ':refund:stripe_error',
                'nextState' => 'payment_failed',
                'transitionApplied' => true,
                'amount' => $amount > 0 ? $amount / 100 : null,
                'errorCode' => method_exists($e, 'getStripeCode') ? $e->getStripeCode() : 'stripe_api_error',
                'errorMessage' => $e->getMessage(),
                'metadata' => [
                    'amount' => $amount > 0 ? $amount / 100 : null,
                    'gateway_response_code' => method_exists($e, 'getStripeCode') ? $e->getStripeCode() : 'stripe_api_error',
                ],
            ]);
            return $this->handleStripeException($e, 'Stripe refund failed');
        } catch (\Throwable $e) {
            $this->queuePaymentTransitionLog([
                'source' => 'refund',
                'transactionId' => $transactionId ?: 'unknown',
                'eventId' => ($transactionId ?: 'unknown') . ':refund:exception',
                'nextState' => 'payment_failed',
                'transitionApplied' => true,
                'amount' => $amount > 0 ? $amount / 100 : null,
                'errorCode' => 'internal_error',
                'errorMessage' => $e->getMessage(),
                'metadata' => [
                    'amount' => $amount > 0 ? $amount / 100 : null,
                    'gateway_response_code' => 'internal_error',
                ],
            ]);
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
        if (empty($payload['amount']) || empty($payload['currency']) || empty($payload['payment_method_id']) || empty($payload['order_id']) || empty($payload['shield_id'])) {
            return $this->errorResponse(400, 'invalid_payload', 'amount, currency, payment_method_id, order_id, and shield_id are required', 'ERROR');
        }
        if ((float) $payload['amount'] <= 0) {
            return $this->errorResponse(400, 'invalid_amount', 'amount must be greater than zero', 'ERROR');
        }
        return null;
    }

    private function validateLinkExpressPayload(array $payload) {
        if (empty($payload['amount']) || empty($payload['currency']) || empty($payload['confirmation_token']) || empty($payload['order_id']) || empty($payload['shield_id'])) {
            return $this->errorResponse(400, 'invalid_payload', 'amount, currency, confirmation_token, order_id, and shield_id are required', 'ERROR');
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

    private function queuePaymentTransitionLog(array $data) {
        if (!class_exists('Helpers') || !method_exists('Helpers', 'queuePaymentTransitionLog')) {
            return;
        }

        Helpers::queuePaymentTransitionLog(array_merge([
            'gateway'           => 'stripe',
            'mode'              => $this->detectMode(),
            'managerId'         => $this->managerId,
            'traceId'           => $this->traceId,
            'transitionApplied' => true,
        ], $data));
    }

    private function normalizeReference($value): ?string {
        if ($value === null) {
            return null;
        }

        $normalized = sanitize_text_field((string) $value);
        if ($normalized === '' || $normalized === '0') {
            return null;
        }

        return $normalized;
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

    /**
     * Tao hoac reuse route_id on dinh cho mot order.
     * Key stable: sha256(order_id|shield_id|manager_id) dam bao retry cung order dung lai route cu.
     */
    private function resolveOrCreateCallbackRoute(array $payload): string {
        $stableKey = hash('sha256', implode('|', [
            (string) ($payload['order_id'] ?? ''),
            (string) ($payload['shield_id'] ?? ''),
            (string) ($this->managerId ?? ''),
        ]));
        $lookupKey = 'shield_route_key_' . $stableKey;

        // Neu route da ton tai cho order nay, reuse
        $existingRouteId = get_transient($lookupKey);
        if ($existingRouteId && is_string($existingRouteId)) {
            $routeData = get_transient('shield_route_' . $existingRouteId);
            if (is_array($routeData)) {
                // Refresh TTL
                set_transient('shield_route_' . $existingRouteId, $routeData, 30 * DAY_IN_SECONDS);
                set_transient($lookupKey, $existingRouteId, 30 * DAY_IN_SECONDS);
                return $existingRouteId;
            }
        }

        // Tao moi
        $routeId = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : bin2hex(random_bytes(16));
        $routeData = [
            'route_id'             => $routeId,
            'manager_callback_url' => esc_url_raw((string) ($payload['manager_callback_url'] ?? '')),
            'manager_id'           => $this->managerId,
            'shield_id'            => $this->normalizeReference($payload['shield_id'] ?? null),
            'woo_order_id'         => $this->normalizeReference($payload['order_id'] ?? null),
            'order_invoice'        => $this->normalizeReference($payload['order_invoice'] ?? null) ?: $this->normalizeReference($payload['order_id'] ?? null),
            'trace_id'             => $this->traceId,
            'payment_intent_id'    => '',
            'created_at'           => current_time('mysql'),
        ];

        set_transient('shield_route_' . $routeId, $routeData, 30 * DAY_IN_SECONDS);
        set_transient($lookupKey, $routeId, 30 * DAY_IN_SECONDS);
        return $routeId;
    }

    /**
     * Ghi payment_intent_id vao route record sau khi PI duoc tao thanh cong.
     */
    private function linkPaymentIntentToRoute(string $routeId, string $paymentIntentId): void {
        if ($routeId === '' || $paymentIntentId === '') {
            return;
        }
        $key = 'shield_route_' . $routeId;
        $data = get_transient($key);
        if (is_array($data)) {
            $data['payment_intent_id'] = $paymentIntentId;
            set_transient($key, $data, 30 * DAY_IN_SECONDS);
        }
    }
}
