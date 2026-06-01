<?php

require_once __DIR__ . '/store_config.php';

function binance_pay_base_url(): string {
    return 'https://pay.coinpal.io';
}

function binance_pay_checkout_path(): string {
    return '/gateway/pay/checkout';
}

function binance_pay_query_path(): string {
    return '/gateway/pay/query';
}

function binance_pay_checkout_url(): string {
    return binance_pay_base_url() . binance_pay_checkout_path();
}

function binance_pay_query_url(): string {
    return binance_pay_base_url() . binance_pay_query_path();
}

function binance_pay_version(): string {
    return '2';
}

function binance_pay_config(): array {
    $storeName = trim((string) store_config_get('nombre_tienda', ''));
    if ($storeName === '') {
        $storeName = trim((string) store_config_get('nombre_prefijo', 'CoinPal'));
    }
    if ($storeName === '') {
        $storeName = 'CoinPal';
    }

    return [
        'feature_enabled' => trim((string) store_config_get('api_binance', '0')) === '1',
        'user_enabled' => trim((string) store_config_get('api_binance_usuario', '1')) === '1',
        'version' => binance_pay_version(),
        'base_url' => binance_pay_base_url(),
        'merchant_name' => $storeName,
        'merchant_no' => trim((string) store_config_get('binance_pay_merchant_no', '')),
        'api_key' => trim((string) store_config_get('binance_pay_secret_key', '')),
        'store_id' => trim((string) store_config_get('binance_pay_store_id', '')),
        'access_token' => trim((string) store_config_get('binance_pay_access_token', '')),
        'store_url' => rtrim(trim((string) store_config_get('binance_pay_store_url', '')), '/'),
    ];
}

function binance_pay_is_enabled(): bool {
    $config = binance_pay_config();
    return ($config['feature_enabled'] ?? false) === true
        && ($config['user_enabled'] ?? false) === true;
}

function binance_pay_missing_configuration_fields(bool $includePortalFields = false): array {
    $config = binance_pay_config();
    $missing = [];

    if ($config['merchant_no'] === '') {
        $missing[] = 'merchant_no';
    }
    if ($config['api_key'] === '') {
        $missing[] = 'api_key';
    }

    if ($includePortalFields) {
        if ($config['store_id'] === '') {
            $missing[] = 'store_id';
        }
        if ($config['access_token'] === '') {
            $missing[] = 'access_token';
        }
        if ($config['store_url'] === '') {
            $missing[] = 'store_url';
        }
    }

    return $missing;
}

function binance_pay_is_configured(bool $includePortalFields = false): bool {
    return binance_pay_missing_configuration_fields($includePortalFields) === [];
}

function binance_pay_decode_response_body(?string $body): ?array {
    $body = trim((string) $body);
    if ($body === '') {
        return null;
    }

    $data = json_decode($body, true);
    return is_array($data) ? $data : null;
}

function binance_pay_response_snippet(?string $body, int $limit = 240): string {
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

function binance_pay_invalid_json_exception(string $url, ?int $status, ?string $body): RuntimeException {
    $statusLabel = $status !== null && $status > 0 ? (string) $status : 'n/a';
    $snippet = binance_pay_response_snippet($body);
    error_log('TVG Binance Pay invalid JSON response [' . $statusLabel . '] ' . $url . ' :: ' . $snippet);

    if (trim((string) $body) === '') {
        return new RuntimeException('CoinPal devolvió una respuesta vacía o incompleta.');
    }

    return new RuntimeException('CoinPal no devolvió un JSON válido.');
}

function binance_pay_error_message_from_response(?array $data, int $status): string {
    if (is_array($data)) {
        $candidates = [
            $data['respMessage'] ?? null,
            $data['message'] ?? null,
            $data['error'] ?? null,
            $data['detail'] ?? null,
            $data['remark'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            $text = trim((string) $candidate);
            if ($text !== '') {
                return $text;
            }
        }
    }

    return 'CoinPal respondió con código HTTP ' . $status . '.';
}

function binance_pay_customer_message(string $message, ?string $currencyCode = null): string {
    $rawMessage = trim($message);
    if ($rawMessage === '') {
        return 'No se pudo completar la validación con Binance Pay.';
    }

    $loweredMessage = strtolower($rawMessage);
    $normalizedCurrency = strtoupper(trim((string) $currencyCode));

    if (str_contains($loweredMessage, 'signature verification failed')) {
        return 'No se pudo validar Binance Pay con la configuración actual de la tienda. Intenta de nuevo o contacta al administrador.';
    }

    if (str_contains($loweredMessage, 'payment in this currency is not supported')) {
        if ($normalizedCurrency !== '') {
            return 'Binance Pay no admite pagos en ' . $normalizedCurrency . ' para esta tienda. Cambia la moneda del pedido o habilita una moneda compatible en CoinPal.';
        }

        return 'Binance Pay no admite la moneda actual de este pedido para esta tienda. Cambia la moneda del pedido o habilita una moneda compatible en CoinPal.';
    }

    return $rawMessage;
}

function binance_pay_connect_timeout_seconds(): int {
    return 10;
}

function binance_pay_checkout_timeout_seconds(): int {
    return 60;
}

function binance_pay_query_timeout_seconds(): int {
    return 35;
}

function binance_pay_http_post_form(string $url, array $payload, array $headers = [], int $timeout = 30, bool $verifySsl = true): array {
    $body = null;
    $status = null;
    $connectTimeout = min(binance_pay_connect_timeout_seconds(), max(1, $timeout));
    $requestBody = http_build_query($payload);
    $httpHeaders = array_merge(['Content-Type: application/x-www-form-urlencoded'], $headers);

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => $connectTimeout,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $requestBody,
            CURLOPT_HTTPHEADER => $httpHeaders,
            CURLOPT_SSL_VERIFYPEER => $verifySsl,
            CURLOPT_SSL_VERIFYHOST => $verifySsl ? 2 : 0,
        ]);
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new RuntimeException('No se pudo consultar CoinPal: ' . $error);
        }

        $body = $response;
    } else {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'timeout' => $timeout,
                'ignore_errors' => true,
                'header' => implode("\r\n", $httpHeaders),
                'content' => $requestBody,
            ],
            'ssl' => [
                'verify_peer' => $verifySsl,
                'verify_peer_name' => $verifySsl,
            ],
        ]);
        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            throw new RuntimeException('No se pudo consultar CoinPal.');
        }

        $body = $response;
    }

    $data = binance_pay_decode_response_body((string) $body);
    if (!is_array($data)) {
        throw binance_pay_invalid_json_exception($url, $status, (string) $body);
    }

    if (isset($status) && $status >= 400) {
        throw new RuntimeException(binance_pay_error_message_from_response($data, $status));
    }

    return $data;
}

function binance_pay_http_head(string $url, int $timeout = 15, bool $verifySsl = true): array {
    $status = null;
    $responseHeaders = [];
    $connectTimeout = min(binance_pay_connect_timeout_seconds(), max(1, $timeout));

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => $connectTimeout,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_NOBODY => true,
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

        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new RuntimeException('No se pudo validar el checkout de CoinPal: ' . $error);
        }
    } else {
        $context = stream_context_create([
            'http' => [
                'method' => 'HEAD',
                'timeout' => $timeout,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => $verifySsl,
                'verify_peer_name' => $verifySsl,
            ],
        ]);
        $response = @file_get_contents($url, false, $context);
        if ($response === false && empty($http_response_header)) {
            throw new RuntimeException('No se pudo validar el checkout de CoinPal.');
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
    ];
}

function binance_pay_generate_request_id(): string {
    return 'Q' . date('YmdHis') . uniqid();
}

function binance_pay_make_order_no(string $prefix = 'D'): string {
    return strtoupper($prefix) . sprintf(
        '%04x%04x%04x%04x',
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000
    );
}

function binance_pay_client_ip(array $server): string {
    $candidates = [
        $server['HTTP_CF_CONNECTING_IP'] ?? null,
        $server['HTTP_X_FORWARDED_FOR'] ?? null,
        $server['HTTP_X_REAL_IP'] ?? null,
        $server['REMOTE_ADDR'] ?? null,
    ];

    foreach ($candidates as $candidate) {
        $value = trim((string) $candidate);
        if ($value === '') {
            continue;
        }

        if (str_contains($value, ',')) {
            $value = trim((string) explode(',', $value)[0]);
        }

        if (filter_var($value, FILTER_VALIDATE_IP)) {
            return $value;
        }
    }

    return '127.0.0.1';
}

function binance_pay_sign(array $data, ?string $apiKey = null): string {
    $config = binance_pay_config();
    $apiKey = trim((string) ($apiKey ?? $config['api_key'] ?? ''));
    if ($apiKey === '') {
        throw new RuntimeException('Falta la Secret Key de CoinPal para firmar la solicitud.');
    }

    $signString = $apiKey
        . trim((string) ($data['requestId'] ?? ''))
        . trim((string) ($data['merchantNo'] ?? ''))
        . trim((string) ($data['orderNo'] ?? ''))
        . trim((string) ($data['orderAmount'] ?? ''))
        . trim((string) ($data['orderCurrency'] ?? ''));

    return hash('sha256', $signString);
}

function binance_pay_verify_signature(array $payload, ?string $apiKey = null): bool {
    $receivedSign = strtolower(trim((string) ($payload['sign'] ?? '')));
    if ($receivedSign === '') {
        return false;
    }

    $expectedSign = strtolower(binance_pay_sign($payload, $apiKey));
    return hash_equals($expectedSign, $receivedSign);
}

function binance_pay_assert_checkout_payload(array $payload): void {
    $required = [
        'requestId' => 'requestId',
        'orderNo' => 'orderNo',
        'orderCurrencyType' => 'orderCurrencyType',
        'orderCurrency' => 'orderCurrency',
        'orderAmount' => 'orderAmount',
        'notifyURL' => 'notifyURL',
        'payerIP' => 'payerIP',
    ];

    foreach ($required as $field => $label) {
        if (trim((string) ($payload[$field] ?? '')) === '') {
            throw new InvalidArgumentException('El campo ' . $label . ' es obligatorio para crear el checkout en CoinPal.');
        }
    }
}

function binance_pay_build_checkout_payload(array $input, ?array $config = null): array {
    $config = $config ?? binance_pay_config();

    $payload = [
        'version' => trim((string) ($config['version'] ?? binance_pay_version())),
        'merchantNo' => trim((string) ($config['merchant_no'] ?? '')),
        'merchantName' => trim((string) ($input['merchant_name'] ?? ($config['merchant_name'] ?? ''))),
        'requestId' => trim((string) ($input['request_id'] ?? '')),
        'orderNo' => trim((string) ($input['order_no'] ?? '')),
        'orderCurrencyType' => strtolower(trim((string) ($input['order_currency_type'] ?? 'fiat'))),
        'orderCurrency' => strtoupper(trim((string) ($input['order_currency'] ?? ''))),
        'orderAmount' => trim((string) ($input['order_amount'] ?? '')),
        'notifyURL' => trim((string) ($input['notify_url'] ?? '')),
        'redirectURL' => trim((string) ($input['redirect_url'] ?? '')),
        'payerIP' => trim((string) ($input['payer_ip'] ?? '')),
        'orderDescription' => trim((string) ($input['order_description'] ?? '')),
        'remark' => trim((string) ($input['remark'] ?? '')),
    ];

    if ($payload['requestId'] === '') {
        $payload['requestId'] = binance_pay_generate_request_id();
    }

    binance_pay_assert_checkout_payload($payload);
    if ($payload['merchantNo'] === '') {
        throw new RuntimeException('Falta el Merchant No de CoinPal.');
    }

    if (!is_numeric($payload['orderAmount']) || (float) $payload['orderAmount'] <= 0) {
        throw new InvalidArgumentException('El monto enviado a CoinPal debe ser numérico y mayor que cero.');
    }

    $payload['sign'] = binance_pay_sign($payload, (string) ($config['api_key'] ?? ''));

    if ($payload['redirectURL'] === '') {
        unset($payload['redirectURL']);
    }
    if ($payload['orderDescription'] === '') {
        unset($payload['orderDescription']);
    }
    if ($payload['remark'] === '') {
        unset($payload['remark']);
    }
    if ($payload['merchantName'] === '') {
        unset($payload['merchantName']);
    }

    if (isset($input['extra']) && is_array($input['extra'])) {
        foreach ($input['extra'] as $key => $value) {
            if (!is_string($key) || $key === '' || isset($payload[$key])) {
                continue;
            }

            $text = trim((string) $value);
            if ($text !== '') {
                $payload[$key] = $text;
            }
        }
    }

    return $payload;
}

function binance_pay_response_is_success(array $response): bool {
    $respCode = trim((string) ($response['respCode'] ?? ''));
    if ($respCode !== '' && $respCode !== '200') {
        return false;
    }

    $status = strtolower(trim((string) ($response['status'] ?? '')));
    return $status === '' || $status === 'created' || $status === 'paid' || $status === 'pending' || $status === 'paid_confirming';
}

function binance_pay_extract_reference(array $payload): string {
    return trim((string) ($payload['reference'] ?? $payload['gcid'] ?? ''));
}

function binance_pay_extract_request_id(array $payload): string {
    return trim((string) ($payload['requestId'] ?? ''));
}

function binance_pay_extract_order_no(array $payload): string {
    return trim((string) ($payload['orderNo'] ?? ''));
}

function binance_pay_canonical_checkout_host(): string {
    return 'pay.coinpal.io';
}

function binance_pay_normalize_checkout_url(?string $url): string {
    $url = trim((string) $url);
    if ($url === '') {
        return '';
    }

    $parts = parse_url($url);
    if (!is_array($parts)) {
        return $url;
    }

    $host = strtolower(trim((string) ($parts['host'] ?? '')));
    $path = trim((string) ($parts['path'] ?? ''));
    if ($host === '' || $path === '') {
        return $url;
    }

    $isCoinpalHost = $host === binance_pay_canonical_checkout_host() || str_ends_with($host, '.coinpal.io');
    if (!$isCoinpalHost || !str_contains(strtolower($path), '/cashier/')) {
        return $url;
    }

    $normalized = 'https://' . binance_pay_canonical_checkout_host() . $path;
    if (isset($parts['query']) && trim((string) $parts['query']) !== '') {
        $normalized .= '?' . (string) $parts['query'];
    }
    if (isset($parts['fragment']) && trim((string) $parts['fragment']) !== '') {
        $normalized .= '#' . (string) $parts['fragment'];
    }

    return $normalized;
}

function binance_pay_extract_checkout_url(array $payload): string {
    return binance_pay_normalize_checkout_url((string) ($payload['nextStepContent'] ?? ''));
}

function binance_pay_is_coinpal_checkout_url(?string $url): bool {
    $url = binance_pay_normalize_checkout_url($url);
    if ($url === '') {
        return false;
    }

    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    $path = strtolower((string) parse_url($url, PHP_URL_PATH));

    if ($host === '' || $path === '') {
        return false;
    }

    $isCoinpalHost = $host === binance_pay_canonical_checkout_host();
    if (!$isCoinpalHost) {
        return false;
    }

    return str_contains($path, '/cashier/');
}

function binance_pay_checkout_issue_message(?string $url): string {
    $url = binance_pay_normalize_checkout_url($url);
    if ($url === '' || !binance_pay_is_coinpal_checkout_url($url)) {
        return 'CoinPal no devolvió una URL válida del checkout para Binance Pay.';
    }

    try {
        $head = binance_pay_http_head($url, 12, true);
    } catch (Throwable $e) {
        try {
            $head = binance_pay_http_head($url, 12, false);
        } catch (Throwable $fallbackError) {
            return '';
        }
    }

    $status = (int) ($head['status'] ?? 0);
    $location = trim((string) (($head['headers']['location'] ?? '')));
    $locationHost = strtolower(trim((string) parse_url($location, PHP_URL_HOST)));

    if ($locationHost === 'cashier-pro.coinpal.io') {
        return 'Binance Pay no está disponible temporalmente porque CoinPal está redirigiendo el checkout a cashier-pro.coinpal.io, un host caído con Error 1016. Usa otro método de pago o contacta al administrador.';
    }

    if ($status >= 500) {
        return 'Binance Pay no está disponible temporalmente porque CoinPal devolvió HTTP ' . $status . ' al abrir el checkout. Usa otro método de pago o contacta al administrador.';
    }

    return '';
}

function binance_pay_extract_message(array $payload): string {
    $candidates = [
        $payload['respMessage'] ?? null,
        $payload['message'] ?? null,
        $payload['remark'] ?? null,
        $payload['detail'] ?? null,
        $payload['error'] ?? null,
    ];

    foreach ($candidates as $candidate) {
        $text = trim((string) $candidate);
        if ($text !== '') {
            return $text;
        }
    }

    return '';
}

function binance_pay_extract_paid_amount(array $payload): ?float {
    $candidates = [
        $payload['paidOrderAmount'] ?? null,
        $payload['orderAmount'] ?? null,
    ];

    foreach ($candidates as $candidate) {
        if ($candidate !== null && is_numeric($candidate)) {
            return round((float) $candidate, 2);
        }
    }

    return null;
}

function binance_pay_extract_order_currency(array $payload): string {
    return strtoupper(trim((string) ($payload['orderCurrency'] ?? '')));
}

function binance_pay_payload_json(array $payload): string {
    $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return is_string($encoded) ? $encoded : '{}';
}

function binance_pay_create_checkout(array $input, ?array $config = null): array {
    $config = $config ?? binance_pay_config();
    $payload = binance_pay_build_checkout_payload($input, $config);
    $response = binance_pay_http_post_form(binance_pay_checkout_url(), $payload, [], binance_pay_checkout_timeout_seconds(), true);

    if (!binance_pay_response_is_success($response)) {
        $message = trim((string) ($response['respMessage'] ?? $response['remark'] ?? ''));
        throw new RuntimeException($message !== '' ? $message : 'CoinPal rechazó la creación del checkout.');
    }

    return $response;
}

function binance_pay_query_payment(string $reference, ?array $config = null): array {
    $reference = trim($reference);
    if ($reference === '') {
        throw new InvalidArgumentException('La referencia de CoinPal es obligatoria para consultar el pago.');
    }

    $config = $config ?? binance_pay_config();
    if (trim((string) ($config['merchant_no'] ?? '')) === '') {
        throw new RuntimeException('Falta el Merchant No de CoinPal para consultar pagos.');
    }

    return binance_pay_http_post_form(
        binance_pay_query_url(),
        [
            'merchantNo' => trim((string) ($config['merchant_no'] ?? '')),
            'gcid' => $reference,
        ],
        [],
        binance_pay_query_timeout_seconds(),
        true
    );
}

function binance_pay_notify_payload(?array $payload = null, ?string $apiKey = null): array {
    $payload = $payload ?? $_POST;
    if (!is_array($payload) || $payload === []) {
        throw new RuntimeException('CoinPal no envió datos en la notificación.');
    }

    if (!binance_pay_verify_signature($payload, $apiKey)) {
        throw new RuntimeException('La firma de CoinPal no es válida.');
    }

    return $payload;
}

function binance_pay_normalize_status(?string $status): string {
    return strtolower(trim((string) $status));
}

function binance_pay_is_paid_status(?string $status): bool {
    return binance_pay_normalize_status($status) === 'paid';
}

function binance_pay_is_pending_status(?string $status): bool {
    return in_array(binance_pay_normalize_status($status), ['created', 'unpaid', 'pending', 'paid_confirming', 'partial_paid_confirming', 'partial_paid'], true);
}

function binance_pay_is_failed_status(?string $status): bool {
    return in_array(binance_pay_normalize_status($status), ['failed', 'expired', 'cancelled', 'canceled'], true);
}
