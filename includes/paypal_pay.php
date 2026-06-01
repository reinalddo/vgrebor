<?php

require_once __DIR__ . '/store_config.php';

function paypal_pay_environment(): string {
    $environment = strtolower(trim((string) store_config_get('paypal_environment', 'sandbox')));
    return $environment === 'live' ? 'live' : 'sandbox';
}

function paypal_pay_base_url(?string $environment = null): string {
    return ($environment ?? paypal_pay_environment()) === 'live'
        ? 'https://api-m.paypal.com'
        : 'https://api-m.sandbox.paypal.com';
}

function paypal_pay_oauth_url(?string $environment = null): string {
    return paypal_pay_base_url($environment) . '/v1/oauth2/token';
}

function paypal_pay_orders_url(?string $orderId = null, ?string $environment = null): string {
    $base = paypal_pay_base_url($environment) . '/v2/checkout/orders';
    $orderId = trim((string) $orderId);
    return $orderId !== '' ? ($base . '/' . rawurlencode($orderId)) : $base;
}

function paypal_pay_webhook_verify_url(?string $environment = null): string {
    return paypal_pay_base_url($environment) . '/v1/notifications/verify-webhook-signature';
}

function paypal_pay_config(): array {
    $brandName = trim((string) store_config_get('paypal_brand_name', ''));
    if ($brandName === '') {
        $brandName = trim((string) store_config_get('nombre_tienda', ''));
    }
    if ($brandName === '') {
        $brandName = 'VirtualGaming';
    }

    return [
        'feature_enabled' => trim((string) store_config_get('pago_paypal', '0')) === '1',
        'environment' => paypal_pay_environment(),
        'base_url' => paypal_pay_base_url(),
        'client_id' => trim((string) store_config_get('paypal_client_id', '')),
        'client_secret' => trim((string) store_config_get('paypal_client_secret', '')),
        'webhook_id' => trim((string) store_config_get('paypal_webhook_id', '')),
        'brand_name' => $brandName,
    ];
}

function paypal_pay_is_enabled(): bool {
    return (paypal_pay_config()['feature_enabled'] ?? false) === true;
}

function paypal_pay_checkout_is_enabled(): bool {
    return paypal_pay_is_enabled()
        && trim((string) store_config_get('paypal_activo', '1')) === '1';
}

function paypal_pay_missing_configuration_fields(bool $includeWebhook = false): array {
    $config = paypal_pay_config();
    $missing = [];

    if (($config['client_id'] ?? '') === '') {
        $missing[] = 'client_id';
    }
    if (($config['client_secret'] ?? '') === '') {
        $missing[] = 'client_secret';
    }
    if ($includeWebhook && ($config['webhook_id'] ?? '') === '') {
        $missing[] = 'webhook_id';
    }

    return $missing;
}

function paypal_pay_is_configured(bool $includeWebhook = false): bool {
    return paypal_pay_missing_configuration_fields($includeWebhook) === [];
}

function paypal_pay_supported_currencies(): array {
    return [
        'AUD', 'BRL', 'CAD', 'CZK', 'DKK', 'EUR', 'HKD', 'HUF', 'ILS', 'JPY', 'MXN',
        'MYR', 'NOK', 'NZD', 'PHP', 'PLN', 'GBP', 'SGD', 'SEK', 'CHF', 'TWD', 'THB', 'USD'
    ];
}

function paypal_pay_supports_currency(?string $currencyCode): bool {
    $currencyCode = strtoupper(trim((string) $currencyCode));
    if ($currencyCode === '') {
        return false;
    }

    return in_array($currencyCode, paypal_pay_supported_currencies(), true);
}

function paypal_pay_connect_timeout_seconds(): int {
    return 10;
}

function paypal_pay_request_timeout_seconds(): int {
    return 60;
}

function paypal_pay_encode_json($payload): string {
    $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return is_string($encoded) ? $encoded : '{}';
}

function paypal_pay_response_snippet(?string $body, int $limit = 240): string {
    $body = trim((string) $body);
    if ($body === '') {
        return '[empty body]';
    }

    $body = preg_replace('/\s+/u', ' ', $body) ?? $body;
    if (function_exists('mb_substr')) {
        return mb_substr($body, 0, $limit, 'UTF-8');
    }

    return substr($body, 0, $limit);
}

function paypal_pay_decode_json_body(?string $body): ?array {
    $body = trim((string) $body);
    if ($body === '') {
        return null;
    }

    $decoded = json_decode($body, true);
    return is_array($decoded) ? $decoded : null;
}

function paypal_pay_error_message_from_response(?array $data, int $status): string {
    if (is_array($data)) {
        $details = $data['details'] ?? null;
        if (is_array($details)) {
            foreach ($details as $detail) {
                if (!is_array($detail)) {
                    continue;
                }
                foreach (['description', 'issue', 'field'] as $key) {
                    $text = trim((string) ($detail[$key] ?? ''));
                    if ($text !== '') {
                        return $text;
                    }
                }
            }
        }

        foreach (['message', 'error_description', 'error', 'name'] as $key) {
            $text = trim((string) ($data[$key] ?? ''));
            if ($text !== '') {
                return $text;
            }
        }
    }

    return 'PayPal respondió con código HTTP ' . $status . '.';
}

function paypal_pay_http_request(
    string $method,
    string $url,
    ?string $body = null,
    array $headers = [],
    int $timeout = 30,
    bool $verifySsl = true
): array {
    $method = strtoupper(trim($method));
    $timeout = max(1, $timeout);
    $connectTimeout = min(paypal_pay_connect_timeout_seconds(), $timeout);
    $status = null;
    $responseHeaders = [];

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => $connectTimeout,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => $verifySsl,
            CURLOPT_SSL_VERIFYHOST => $verifySsl ? 2 : 0,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $headerLine) use (&$responseHeaders): int {
                $length = strlen($headerLine);
                $parts = explode(':', $headerLine, 2);
                if (count($parts) === 2) {
                    $name = strtolower(trim($parts[0]));
                    $value = trim($parts[1]);
                    if ($name !== '') {
                        $responseHeaders[$name] = $value;
                    }
                }

                return $length;
            },
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $responseBody = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($responseBody === false) {
            throw new RuntimeException('No se pudo consultar PayPal: ' . $error);
        }
    } else {
        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'timeout' => $timeout,
                'ignore_errors' => true,
                'header' => implode("\r\n", $headers),
                'content' => $body ?? '',
            ],
            'ssl' => [
                'verify_peer' => $verifySsl,
                'verify_peer_name' => $verifySsl,
            ],
        ]);
        $responseBody = @file_get_contents($url, false, $context);
        if ($responseBody === false) {
            throw new RuntimeException('No se pudo consultar PayPal.');
        }

        if (isset($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $headerLine) {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $headerLine, $matches) === 1) {
                    $status = (int) $matches[1];
                    continue;
                }
                $parts = explode(':', $headerLine, 2);
                if (count($parts) === 2) {
                    $name = strtolower(trim($parts[0]));
                    $value = trim($parts[1]);
                    if ($name !== '') {
                        $responseHeaders[$name] = $value;
                    }
                }
            }
        }
    }

    return [
        'status' => $status ?? 0,
        'headers' => $responseHeaders,
        'body' => (string) $responseBody,
        'json' => paypal_pay_decode_json_body((string) $responseBody),
    ];
}

function paypal_pay_access_token(?array $config = null): string {
    static $cachedTokens = [];

    $config = $config ?? paypal_pay_config();
    $cacheKey = implode('|', [
        (string) ($config['environment'] ?? paypal_pay_environment()),
        (string) ($config['client_id'] ?? ''),
    ]);

    if (isset($cachedTokens[$cacheKey])) {
        $entry = $cachedTokens[$cacheKey];
        if (is_array($entry) && (int) ($entry['expires_at'] ?? 0) > (time() + 30)) {
            return (string) ($entry['token'] ?? '');
        }
    }

    $clientId = trim((string) ($config['client_id'] ?? ''));
    $clientSecret = trim((string) ($config['client_secret'] ?? ''));
    if ($clientId === '' || $clientSecret === '') {
        throw new RuntimeException('Faltan credenciales de PayPal.');
    }

    $response = paypal_pay_http_request(
        'POST',
        paypal_pay_oauth_url((string) ($config['environment'] ?? paypal_pay_environment())),
        'grant_type=client_credentials',
        [
            'Accept: application/json',
            'Accept-Language: es_ES',
            'Content-Type: application/x-www-form-urlencoded',
            'Authorization: Basic ' . base64_encode($clientId . ':' . $clientSecret),
        ],
        paypal_pay_request_timeout_seconds(),
        true
    );

    $data = is_array($response['json'] ?? null) ? $response['json'] : null;
    $status = (int) ($response['status'] ?? 0);
    if ($status >= 400 || !is_array($data)) {
        throw new RuntimeException(paypal_pay_error_message_from_response($data, $status > 0 ? $status : 502));
    }

    $token = trim((string) ($data['access_token'] ?? ''));
    if ($token === '') {
        throw new RuntimeException('PayPal no devolvió un access token válido.');
    }

    $expiresIn = max(60, (int) ($data['expires_in'] ?? 300));
    $cachedTokens[$cacheKey] = [
        'token' => $token,
        'expires_at' => time() + $expiresIn,
    ];

    return $token;
}

function paypal_pay_api_request(
    string $method,
    string $path,
    $payload = null,
    array $extraHeaders = [],
    ?array $config = null
): array {
    $config = $config ?? paypal_pay_config();
    $token = paypal_pay_access_token($config);
    $url = paypal_pay_base_url((string) ($config['environment'] ?? paypal_pay_environment())) . '/' . ltrim($path, '/');
    $headers = array_merge([
        'Accept: application/json',
        'Accept-Language: es_ES',
        'Content-Type: application/json',
        'Authorization: Bearer ' . $token,
    ], $extraHeaders);

    $response = paypal_pay_http_request(
        $method,
        $url,
        $payload !== null ? paypal_pay_encode_json($payload) : null,
        $headers,
        paypal_pay_request_timeout_seconds(),
        true
    );

    $data = is_array($response['json'] ?? null) ? $response['json'] : null;
    $status = (int) ($response['status'] ?? 0);
    if ($status >= 400) {
        throw new RuntimeException(paypal_pay_error_message_from_response($data, $status));
    }

    if (!is_array($data)) {
        error_log('TVG PayPal invalid JSON response [' . $status . '] ' . $url . ' :: ' . paypal_pay_response_snippet((string) ($response['body'] ?? '')));
        throw new RuntimeException('PayPal no devolvió un JSON válido.');
    }

    return $data;
}

function paypal_pay_make_invoice_id(int $localOrderId): string {
    return 'VG-' . max(1, $localOrderId) . '-' . date('YmdHis');
}

function paypal_pay_build_create_order_payload(array $input, ?array $config = null): array {
    $config = $config ?? paypal_pay_config();
    $currencyCode = strtoupper(trim((string) ($input['currency'] ?? '')));
    $amount = round((float) ($input['amount'] ?? 0), 2);
    $localOrderId = max(0, (int) ($input['local_order_id'] ?? 0));
    $description = trim((string) ($input['description'] ?? ('Pedido #' . $localOrderId)));
    $returnUrl = trim((string) ($input['return_url'] ?? ''));
    $cancelUrl = trim((string) ($input['cancel_url'] ?? ''));

    if (!paypal_pay_supports_currency($currencyCode)) {
        throw new InvalidArgumentException('PayPal no admite pagos en ' . $currencyCode . ' para esta tienda.');
    }
    if ($amount <= 0) {
        throw new InvalidArgumentException('El monto enviado a PayPal debe ser mayor a cero.');
    }
    if ($returnUrl === '' || $cancelUrl === '') {
        throw new InvalidArgumentException('Faltan las URLs de retorno o cancelación de PayPal.');
    }

    $purchaseUnit = [
        'reference_id' => (string) max(1, $localOrderId),
        'custom_id' => (string) max(1, $localOrderId),
        'invoice_id' => paypal_pay_make_invoice_id($localOrderId),
        'description' => $description,
        'amount' => [
            'currency_code' => $currencyCode,
            'value' => number_format($amount, 2, '.', ''),
        ],
    ];

    return [
        'intent' => 'CAPTURE',
        'purchase_units' => [$purchaseUnit],
        'payment_source' => [
            'paypal' => [
                'experience_context' => [
                    'payment_method_preference' => 'IMMEDIATE_PAYMENT_REQUIRED',
                    'brand_name' => trim((string) ($input['brand_name'] ?? ($config['brand_name'] ?? 'VirtualGaming'))),
                    'landing_page' => 'LOGIN',
                    'shipping_preference' => 'NO_SHIPPING',
                    'user_action' => 'PAY_NOW',
                    'return_url' => $returnUrl,
                    'cancel_url' => $cancelUrl,
                ],
            ],
        ],
    ];
}

function paypal_pay_create_order(array $input, ?array $config = null): array {
    $config = $config ?? paypal_pay_config();
    $payload = paypal_pay_build_create_order_payload($input, $config);
    return paypal_pay_api_request('POST', '/v2/checkout/orders', $payload, [], $config);
}

function paypal_pay_get_order(string $orderId, ?array $config = null): array {
    $orderId = trim($orderId);
    if ($orderId === '') {
        throw new InvalidArgumentException('El ID de la orden PayPal es obligatorio.');
    }

    return paypal_pay_api_request('GET', '/v2/checkout/orders/' . rawurlencode($orderId), null, [], $config);
}

function paypal_pay_capture_order(string $orderId, ?array $config = null): array {
    $orderId = trim($orderId);
    if ($orderId === '') {
        throw new InvalidArgumentException('El ID de la orden PayPal es obligatorio para capturar.');
    }

    return paypal_pay_api_request('POST', '/v2/checkout/orders/' . rawurlencode($orderId) . '/capture', (object) [], [], $config);
}

function paypal_pay_extract_link(array $payload, string $rel): string {
    $links = $payload['links'] ?? null;
    if (!is_array($links)) {
        return '';
    }

    foreach ($links as $link) {
        if (!is_array($link)) {
            continue;
        }
        if (strcasecmp(trim((string) ($link['rel'] ?? '')), $rel) !== 0) {
            continue;
        }
        $href = trim((string) ($link['href'] ?? ''));
        if ($href !== '') {
            return $href;
        }
    }

    return '';
}

function paypal_pay_extract_order_id(array $payload): string {
    return trim((string) ($payload['id'] ?? ''));
}

function paypal_pay_extract_status(array $payload): string {
    return strtolower(trim((string) ($payload['status'] ?? '')));
}

function paypal_pay_extract_approval_url(array $payload): string {
    $approvalUrl = paypal_pay_extract_link($payload, 'approve');
    if ($approvalUrl !== '') {
        return $approvalUrl;
    }

    return paypal_pay_extract_link($payload, 'payer-action');
}

function paypal_pay_extract_capture_id(array $payload): string {
    $purchaseUnits = $payload['purchase_units'] ?? null;
    if (!is_array($purchaseUnits)) {
        return '';
    }

    foreach ($purchaseUnits as $unit) {
        if (!is_array($unit)) {
            continue;
        }
        $captures = $unit['payments']['captures'] ?? null;
        if (!is_array($captures)) {
            continue;
        }
        foreach ($captures as $capture) {
            if (!is_array($capture)) {
                continue;
            }
            $captureId = trim((string) ($capture['id'] ?? ''));
            if ($captureId !== '') {
                return $captureId;
            }
        }
    }

    return '';
}

function paypal_pay_extract_payer_id(array $payload): string {
    return trim((string) ($payload['payer']['payer_id'] ?? ''));
}

function paypal_pay_extract_paid_amount(array $payload): ?float {
    $purchaseUnits = $payload['purchase_units'] ?? null;
    if (!is_array($purchaseUnits)) {
        return null;
    }

    foreach ($purchaseUnits as $unit) {
        if (!is_array($unit)) {
            continue;
        }
        $captures = $unit['payments']['captures'] ?? null;
        if (!is_array($captures)) {
            continue;
        }
        foreach ($captures as $capture) {
            if (!is_array($capture)) {
                continue;
            }
            $amount = $capture['amount']['value'] ?? null;
            if ($amount !== null && is_numeric($amount)) {
                return round((float) $amount, 2);
            }
        }
    }

    return null;
}

function paypal_pay_extract_paid_currency(array $payload): string {
    $purchaseUnits = $payload['purchase_units'] ?? null;
    if (!is_array($purchaseUnits)) {
        return '';
    }

    foreach ($purchaseUnits as $unit) {
        if (!is_array($unit)) {
            continue;
        }
        $captures = $unit['payments']['captures'] ?? null;
        if (!is_array($captures)) {
            continue;
        }
        foreach ($captures as $capture) {
            if (!is_array($capture)) {
                continue;
            }
            $currency = strtoupper(trim((string) ($capture['amount']['currency_code'] ?? '')));
            if ($currency !== '') {
                return $currency;
            }
        }
    }

    return '';
}

function paypal_pay_extract_message(array $payload): string {
    $details = $payload['details'] ?? null;
    if (is_array($details)) {
        foreach ($details as $detail) {
            if (!is_array($detail)) {
                continue;
            }
            $description = trim((string) ($detail['description'] ?? ''));
            if ($description !== '') {
                return $description;
            }
        }
    }

    $status = paypal_pay_extract_status($payload);
    if ($status === 'completed') {
        return 'Pago aprobado correctamente por PayPal.';
    }
    if ($status === 'approved') {
        return 'El cliente aprobó la orden en PayPal. Falta la captura final.';
    }
    if (paypal_pay_is_failed_status($status)) {
        return 'El pago fue rechazado o cancelado en PayPal.';
    }
    if ($status !== '') {
        return 'La orden de PayPal quedó en estado ' . strtoupper($status) . '.';
    }

    return '';
}

function paypal_pay_is_completed_status(?string $status): bool {
    return paypal_pay_extract_status(['status' => $status]) === 'completed';
}

function paypal_pay_is_pending_status(?string $status): bool {
    return in_array(paypal_pay_extract_status(['status' => $status]), ['created', 'saved', 'payer_action_required', 'approved'], true);
}

function paypal_pay_is_failed_status(?string $status): bool {
    return in_array(paypal_pay_extract_status(['status' => $status]), ['voided', 'failed', 'denied', 'cancelled', 'canceled', 'expired'], true);
}

function paypal_pay_payload_json(array $payload): string {
    return paypal_pay_encode_json($payload);
}

function paypal_pay_webhook_headers_from_server(array $server): array {
    $headers = [];
    $map = [
        'PAYPAL-TRANSMISSION-ID' => 'HTTP_PAYPAL_TRANSMISSION_ID',
        'PAYPAL-TRANSMISSION-TIME' => 'HTTP_PAYPAL_TRANSMISSION_TIME',
        'PAYPAL-TRANSMISSION-SIG' => 'HTTP_PAYPAL_TRANSMISSION_SIG',
        'PAYPAL-CERT-URL' => 'HTTP_PAYPAL_CERT_URL',
        'PAYPAL-AUTH-ALGO' => 'HTTP_PAYPAL_AUTH_ALGO',
    ];

    foreach ($map as $headerName => $serverKey) {
        $value = trim((string) ($server[$serverKey] ?? ''));
        if ($value !== '') {
            $headers[$headerName] = $value;
        }
    }

    return $headers;
}

function paypal_pay_verify_webhook_signature(array $headers, array $event, ?array $config = null): bool {
    $config = $config ?? paypal_pay_config();
    $webhookId = trim((string) ($config['webhook_id'] ?? ''));
    if ($webhookId === '') {
        throw new RuntimeException('Falta el Webhook ID de PayPal.');
    }

    $requiredHeaders = [
        'PAYPAL-TRANSMISSION-ID',
        'PAYPAL-TRANSMISSION-TIME',
        'PAYPAL-TRANSMISSION-SIG',
        'PAYPAL-CERT-URL',
        'PAYPAL-AUTH-ALGO',
    ];
    foreach ($requiredHeaders as $headerName) {
        if (trim((string) ($headers[$headerName] ?? '')) === '') {
            return false;
        }
    }

    $payload = [
        'auth_algo' => trim((string) ($headers['PAYPAL-AUTH-ALGO'] ?? '')),
        'cert_url' => trim((string) ($headers['PAYPAL-CERT-URL'] ?? '')),
        'transmission_id' => trim((string) ($headers['PAYPAL-TRANSMISSION-ID'] ?? '')),
        'transmission_sig' => trim((string) ($headers['PAYPAL-TRANSMISSION-SIG'] ?? '')),
        'transmission_time' => trim((string) ($headers['PAYPAL-TRANSMISSION-TIME'] ?? '')),
        'webhook_id' => $webhookId,
        'webhook_event' => $event,
    ];

    $response = paypal_pay_api_request('POST', '/v1/notifications/verify-webhook-signature', $payload, [], $config);
    return strtolower(trim((string) ($response['verification_status'] ?? ''))) === 'success';
}

function paypal_pay_customer_message(string $message, ?string $currencyCode = null): string {
    $rawMessage = trim($message);
    $currencyCode = strtoupper(trim((string) $currencyCode));

    if ($currencyCode !== '' && str_contains(strtolower($rawMessage), 'currency')) {
        return 'PayPal no admite pagos en ' . $currencyCode . ' para esta tienda. Cambia la moneda del pedido o usa otro método.';
    }
    if (str_contains(strtolower($rawMessage), 'client')) {
        return 'La configuración actual de PayPal no es válida para esta tienda. Contacta al administrador.';
    }
    if ($rawMessage !== '') {
        return $rawMessage;
    }

    return 'No se pudo completar la validación con PayPal.';
}