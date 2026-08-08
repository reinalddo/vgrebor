<?php
// Integración con la API REST de RecargasAmérica (panel.recargasamerica.com) —
// segundo proveedor de recargas, en paralelo a TiendaGiftVen
// (includes/recargas_api.php). Solo cubre lo que el cliente confirmó que
// existe hoy en esa API: catálogo de PINs/recargas de juegos
// (/products/pins), validación de cuenta antes de comprar (/pins/validate),
// compra (/buy/pins) y consulta de estado de orden (/orders/{reference}).
// No cubre /products/streaming ni /products/games (el cliente confirmó que
// no los usa).
//
// Autenticación: Bearer token (Authorization: Bearer ra_...), a diferencia
// de GiftVen que usa el header X-API-Key.

require_once __DIR__ . '/store_config.php';

function recargasamerica_api_base_url(): string {
    return 'https://panel.recargasamerica.com/api/v1';
}

function recargasamerica_api_key(): string {
    return trim(store_config_get('recargasamerica_api_key', ''));
}

function recargasamerica_api_is_configured(): bool {
    return recargasamerica_api_key() !== '';
}

function recargasamerica_api_connect_timeout_seconds(): int {
    return 10;
}

function recargasamerica_api_catalog_timeout_seconds(): int {
    return 30;
}

// Mismo razonamiento que recargas_api_purchase_timeout_seconds() (GiftVen):
// suficientemente alto para dar margen al proveedor, sin acercarse tanto al
// límite del proxy/servidor que corte con un 504 en HTML antes de que
// nuestro propio código responda con un JSON manejable.
function recargasamerica_api_purchase_timeout_seconds(): int {
    return 35;
}

function recargasamerica_api_lookup_timeout_seconds(): int {
    return 25;
}

function recargasamerica_api_decode_response_body(?string $body): ?array {
    $body = trim((string) $body);
    if ($body === '') {
        return null;
    }

    $data = json_decode($body, true);
    return is_array($data) ? $data : null;
}

function recargasamerica_api_response_snippet(?string $body, int $limit = 240): string {
    $body = trim((string) $body);
    if ($body === '') {
        return '[empty body]';
    }

    $body = preg_replace('/\s+/u', ' ', $body) ?? $body;
    return function_exists('mb_substr') ? mb_substr($body, 0, $limit, 'UTF-8') : substr($body, 0, $limit);
}

function recargasamerica_api_error_message_from_response(?array $data, int $status): string {
    if (is_array($data)) {
        foreach ([$data['error'] ?? null, $data['message'] ?? null] as $candidate) {
            $text = trim((string) $candidate);
            if ($text !== '') {
                return $text;
            }
        }
    }

    return 'RecargasAmérica respondió con código HTTP ' . $status . '.';
}

function recargasamerica_api_request(string $method, string $path, ?array $payload, int $timeout, bool $verifySsl = true): array {
    $apiKey = recargasamerica_api_key();
    if ($apiKey === '') {
        throw new RuntimeException('Configura primero la API KEY de RecargasAmérica.');
    }

    $url = recargasamerica_api_base_url() . '/' . ltrim($path, '/');
    $connectTimeout = min(recargasamerica_api_connect_timeout_seconds(), max(1, $timeout));
    $headers = [
        'Authorization: Bearer ' . $apiKey,
        'Accept: application/json',
        'Content-Type: application/json',
    ];

    $body = null;
    $status = null;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => $connectTimeout,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => $verifySsl,
            CURLOPT_SSL_VERIFYHOST => $verifySsl ? 2 : 0,
        ];
        if ($method === 'POST') {
            $requestBody = json_encode($payload ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!is_string($requestBody)) {
                throw new RuntimeException('No se pudo serializar la solicitud JSON para RecargasAmérica.');
            }
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = $requestBody;
        }
        curl_setopt_array($ch, $options);
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new RuntimeException('No se pudo consultar la API de RecargasAmérica: ' . $error);
        }

        $body = $response;
    } else {
        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'timeout' => $timeout,
                'ignore_errors' => true,
                'header' => implode("\r\n", $headers),
                'content' => $method === 'POST' ? (json_encode($payload ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}') : null,
            ],
            'ssl' => [
                'verify_peer' => $verifySsl,
                'verify_peer_name' => $verifySsl,
            ],
        ]);
        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            throw new RuntimeException('No se pudo consultar la API de RecargasAmérica.');
        }
        $body = $response;
    }

    $data = recargasamerica_api_decode_response_body((string) $body);
    if (!is_array($data)) {
        error_log('TVG recargasamerica invalid JSON response [' . ($status ?? 'n/a') . '] ' . $url . ' :: ' . recargasamerica_api_response_snippet((string) $body));
        throw new RuntimeException(trim((string) $body) === ''
            ? 'RecargasAmérica devolvió una respuesta vacía.'
            : 'RecargasAmérica no devolvió un JSON válido.');
    }

    if (isset($status) && $status >= 400) {
        throw new RuntimeException(recargasamerica_api_error_message_from_response($data, $status));
    }

    if (array_key_exists('success', $data) && !$data['success']) {
        throw new RuntimeException(recargasamerica_api_error_message_from_response($data, $status ?? 0));
    }

    return $data;
}

function recargasamerica_api_get(string $path, int $timeout): array {
    try {
        return recargasamerica_api_request('GET', $path, null, $timeout, true);
    } catch (Throwable $e) {
        $message = (string) $e->getMessage();
        $sslIssue = stripos($message, 'SSL certificate problem') !== false
            || stripos($message, 'unable to get local issuer certificate') !== false;
        if (!$sslIssue) {
            throw $e;
        }
        return recargasamerica_api_request('GET', $path, null, $timeout, false);
    }
}

function recargasamerica_api_post(string $path, array $payload, int $timeout): array {
    try {
        return recargasamerica_api_request('POST', $path, $payload, $timeout, true);
    } catch (Throwable $e) {
        $message = (string) $e->getMessage();
        $sslIssue = stripos($message, 'SSL certificate problem') !== false
            || stripos($message, 'unable to get local issuer certificate') !== false;
        if (!$sslIssue) {
            throw $e;
        }
        return recargasamerica_api_request('POST', $path, $payload, $timeout, false);
    }
}

function recargasamerica_api_fetch_wallet(): array {
    $response = recargasamerica_api_get('wallet', recargasamerica_api_lookup_timeout_seconds());
    $data = is_array($response['data'] ?? null) ? $response['data'] : [];

    return [
        'balance' => isset($data['balance']) ? (float) $data['balance'] : 0.0,
        'currency' => (string) ($data['currency'] ?? 'USD'),
    ];
}

// Catálogo de PINs/recargas — cacheado por request (misma vida útil que
// recargas_api_fetch_products() de GiftVen) para no repetir la llamada si
// se consulta varias veces en la misma carga de página del admin.
function recargasamerica_api_fetch_products_pins(): array {
    static $cachedProducts = null;

    if ($cachedProducts !== null) {
        return $cachedProducts;
    }

    $response = recargasamerica_api_get('products/pins', recargasamerica_api_catalog_timeout_seconds());
    $products = $response['data'] ?? null;
    if (!is_array($products)) {
        throw new RuntimeException('RecargasAmérica no devolvió una lista válida de productos.');
    }

    $cachedProducts = array_values(array_filter($products, 'is_array'));
    return $cachedProducts;
}

function recargasamerica_api_fetch_product_by_id(int $productId): ?array {
    foreach (recargasamerica_api_fetch_products_pins() as $product) {
        if ((int) ($product['id'] ?? 0) === $productId) {
            return $product;
        }
    }

    return null;
}

function recargasamerica_api_product_type(array $product): string {
    $type = strtolower(trim((string) ($product['type'] ?? '')));
    return $type === 'pin' ? 'pin' : 'recharge';
}

function recargasamerica_api_product_label(array $product): string {
    $name = trim((string) ($product['name'] ?? 'Producto'));
    $id = (int) ($product['id'] ?? 0);
    $price = isset($product['price']) ? number_format((float) $product['price'], 4, '.', '') : '0.0000';
    $type = recargasamerica_api_product_type($product) === 'pin' ? 'PIN' : 'Recarga';

    return $name . ' [ID ' . $id . '] - $' . $price . ' - ' . $type;
}

// Precheck de cuenta/jugador antes de comprar una recarga (type=recharge).
// No descuenta saldo. Mismo contrato que player_verification_verify()
// espera para poder integrarse como un verificador más.
function recargasamerica_api_validate_recharge_account(int $productId, string $serviceUserId): array {
    $response = recargasamerica_api_post('pins/validate', [
        'product_id' => $productId,
        'service_user_id' => $serviceUserId,
    ], recargasamerica_api_lookup_timeout_seconds());

    $data = is_array($response['data'] ?? null) ? $response['data'] : [];

    return [
        'found' => !empty($data['status']),
        'account_name' => trim((string) ($data['account_name'] ?? '')),
    ];
}

// Compra type=pin: entrega N códigos de una vez, sin concepto de "pendiente"
// documentado — se trata siempre como una respuesta final (no como GiftVen,
// que sí puede quedar "procesando").
function recargasamerica_api_buy_pin(int $productId, int $quantity, string $clientName = ''): array {
    $payload = ['product_id' => $productId, 'quantity' => max(1, min(10, $quantity))];
    if ($clientName !== '') {
        $payload['client_name'] = $clientName;
    }

    return recargasamerica_api_post('buy/pins', $payload, recargasamerica_api_purchase_timeout_seconds());
}

// Compra type=recharge: recarga directa a una cuenta (redemption_id = el
// mismo ID que ya se validó en /pins/validate como service_user_id).
function recargasamerica_api_buy_recharge(int $productId, string $redemptionId, string $clientName = ''): array {
    $payload = ['product_id' => $productId, 'redemption_id' => $redemptionId];
    if ($clientName !== '') {
        $payload['client_name'] = $clientName;
    }

    return recargasamerica_api_post('buy/pins', $payload, recargasamerica_api_purchase_timeout_seconds());
}

function recargasamerica_api_fetch_order_status(string $reference): array {
    return recargasamerica_api_get('orders/' . rawurlencode($reference), recargasamerica_api_lookup_timeout_seconds());
}

// Punto único de despacho de compra — mismo contrato de retorno que
// execute_catalog_api_purchase() (GiftVen, api/pedidos.php) para poder
// conectarse al mismo código de manejo de resultado en batch_fulfill_item:
// ['success', 'accepted', 'message', 'reference', 'payload'].
//
// A diferencia de GiftVen, /buy/pins no documenta un estado "pendiente" en
// sus respuestas de ejemplo — la capa de request (recargasamerica_api_request)
// ya valida success=true antes de devolver, así que si no hubo excepción se
// asume entrega inmediata salvo que la respuesta indique explícitamente que
// sigue en curso.
function execute_recargasamerica_purchase(int $productId, string $tipo, string $userIdentifier, int $quantity = 1, string $clientName = ''): array {
    $requestPayload = [
        'producto_id' => $productId,
        'tipo' => $tipo,
        'user_identifier' => $userIdentifier,
        'quantity' => $quantity,
    ];

    try {
        $response = $tipo === 'pin'
            ? recargasamerica_api_buy_pin($productId, $quantity, $clientName)
            : recargasamerica_api_buy_recharge($productId, $userIdentifier, $clientName);
    } catch (Throwable $e) {
        return [
            'success' => false,
            'accepted' => false,
            'message' => trim((string) $e->getMessage()) !== '' ? trim((string) $e->getMessage()) : 'La API de RecargasAmérica rechazó la compra.',
            'reference' => '',
            'payload' => ['exception' => $e->getMessage(), 'request_payload' => $requestPayload],
        ];
    }

    $data = is_array($response['data'] ?? null) ? $response['data'] : [];
    $status = strtolower(trim((string) ($data['status'] ?? '')));
    $isPending = in_array($status, ['pending', 'procesando', 'processing'], true);
    $message = trim((string) ($data['message'] ?? $response['message'] ?? ''));

    return [
        'success' => !$isPending,
        'accepted' => $isPending,
        'message' => $message !== '' ? $message : ($isPending ? 'Recarga en proceso.' : 'Compra completada por RecargasAmérica.'),
        'reference' => trim((string) ($data['transaction_id'] ?? $data['reference'] ?? '')),
        'payload' => array_merge($data, ['request_payload' => $requestPayload]),
    ];
}
