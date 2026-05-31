<?php
header('Content-Type: application/json');
http_response_code(410);
echo wp_json_encode([
    'success' => false,
    'code' => 'endpoint_deprecated',
    'message' => 'This Stripe payment form endpoint is deprecated. Use the stripe-pe payment form endpoints instead.',
    'status' => 'gone',
]);
