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

// Se lanza SOLO cuando RecargasAmérica respondió de verdad (con un cuerpo
// JSON válido y un error estructurado: saldo insuficiente, producto
// inválido, proveedor externo rechazó la orden, etc. — ver la colección de
// Postman oficial: código HTTP 4xx/5xx + {"success":false,"error":"...",
// "code":"..."}). A diferencia de un RuntimeException genérico (que
// también cubre timeouts y fallos de conexión donde NUNCA hubo respuesta),
// esta excepción significa que el proveedor sí procesó la solicitud y dio
// una razón real — no debe tratarse como "reintentar limpio en silencio",
// sino dejar rastro visible para el admin.
class RecargasAmericaProviderException extends RuntimeException {
    public string $providerCode;
    public array $responseData;

    public function __construct(string $message, string $providerCode = '', array $responseData = []) {
        parent::__construct($message);
        $this->providerCode = $providerCode;
        $this->responseData = $responseData;
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// Corte automático ("circuit breaker") y caché del catálogo.
//
// POR QUÉ: RecargasAmérica banea (24 h) la IP del servidor si ve varios
// intentos seguidos sin credenciales o con credenciales incorrectas — los
// trata como fuerza bruta. Sin memoria entre solicitudes, cada visita pública a
// un juego, cada verificación de ID y cada carga del dashboard repetía la
// llamada fallida (clave borrada, IP bloqueada, endpoint dado de baja…) y
// podía disparar Y MANTENER el bloqueo.
//
// CÓMO: tras un fallo "definitivo" (no se puede conectar, 401, 403, 429,
// ENDPOINT_DISABLED) se deja de llamar a RecargasAmérica durante un tiempo
// (con espera creciente hasta 60 min mientras siga fallando); pasado ese
// tiempo entra UNA sola solicitud de prueba a la vez, y si funciona se cierra
// el corte. El estado vive en archivos temporales, separado por API KEY: al
// cambiar la clave en Configuración el corte se reinicia solo. Si el directorio
// temporal no se puede escribir, todo esto se desactiva (comportamiento anterior).
// NO se corta por timeouts de lectura ni por errores 4xx/5xx de una compra
// (422, 409, 502…): son respuestas reales de una orden concreta.
// ═══════════════════════════════════════════════════════════════════════════

function recargasamerica_state_dir(): string {
    return sys_get_temp_dir();
}

function recargasamerica_state_key(): string {
    return substr(sha1('tvg-ra|' . recargasamerica_api_key()), 0, 16);
}

function recargasamerica_state_path(string $kind): string {
    return rtrim(recargasamerica_state_dir(), '/\\') . DIRECTORY_SEPARATOR . 'tvg_ra_' . preg_replace('/[^a-z0-9_]+/i', '', $kind) . '_' . recargasamerica_state_key() . '.json';
}

function recargasamerica_state_read(string $kind): array {
    $path = recargasamerica_state_path($kind);
    if (!is_file($path)) {
        return [];
    }
    $decoded = json_decode((string) @file_get_contents($path), true);
    return is_array($decoded) ? $decoded : [];
}

function recargasamerica_state_write(string $kind, array $data): void {
    @file_put_contents(recargasamerica_state_path($kind), json_encode($data), LOCK_EX);
}

function recargasamerica_state_delete(string $kind): void {
    $path = recargasamerica_state_path($kind);
    if (is_file($path)) {
        @unlink($path);
    }
}

// Ámbito de un endpoint para los cortes por "endpoint dado de baja": los dos
// primeros tramos del path (products/pins, buy/catalog…); orders/{ref} es uno solo.
function recargasamerica_breaker_scope(string $path): string {
    $segments = array_values(array_filter(explode('/', trim($path, '/')), static fn ($p) => $p !== ''));
    if (($segments[0] ?? '') === 'orders') {
        return 'orders';
    }
    return implode('/', array_slice($segments, 0, 2));
}

// Lanza RuntimeException (fallo "limpio": no se envió nada) si el corte está
// abierto. Si el tiempo de espera ya pasó, deja pasar UNA solicitud de prueba.
function recargasamerica_breaker_check(string $path): void {
    $state = recargasamerica_state_read('breaker');
    if (empty($state)) {
        return;
    }

    $now = time();
    $keys = ['global', 'scope:' . recargasamerica_breaker_scope($path)];
    $changed = false;
    foreach ($keys as $key) {
        $entry = $state[$key] ?? null;
        if (!is_array($entry)) {
            continue;
        }

        $until = (int) ($entry['until'] ?? 0);
        $reason = trim((string) ($entry['reason'] ?? 'fallo de conexión'));
        if ($now < $until) {
            throw new RuntimeException('RecargasAmérica no está disponible temporalmente (' . $reason . '). Se volverá a intentar automáticamente a las ' . date('H:i', $until) . '.');
        }
        if ((int) ($entry['probe_until'] ?? 0) > $now) {
            throw new RuntimeException('RecargasAmérica no está disponible temporalmente (' . $reason . '): se está comprobando si ya responde.');
        }

        // Esta solicitud hace de prueba; las demás esperan su resultado.
        $state[$key]['probe_until'] = $now + 20;
        $changed = true;
    }

    if ($changed) {
        recargasamerica_state_write('breaker', $state);
    }
}

// Abre el corte (global si $scope es null). La espera se duplica con cada
// fallo consecutivo (base, 2×, 4×… hasta 60 min) y se reinicia con un éxito.
function recargasamerica_breaker_trip(?string $scope, int $baseSeconds, string $reason): void {
    $state = recargasamerica_state_read('breaker');
    $key = $scope === null ? 'global' : 'scope:' . $scope;
    $now = time();
    $entry = is_array($state[$key] ?? null) ? $state[$key] : [];

    // Solicitudes que ya iban en camino cuando se abrió: no agravan la espera.
    if ($now < (int) ($entry['until'] ?? 0)) {
        return;
    }

    $strikes = (int) ($entry['strikes'] ?? 0) + 1;
    $duration = min(3600, $baseSeconds * (2 ** min(6, $strikes - 1)));
    $state[$key] = [
        'until' => $now + $duration,
        'strikes' => $strikes,
        'reason' => $reason,
        'opened' => $now,
        'probe_until' => 0,
    ];
    recargasamerica_state_write('breaker', $state);
    error_log('TVG recargasamerica corte automático abierto [' . $key . '] ' . $duration . 's: ' . $reason);
}

function recargasamerica_breaker_success(string $path): void {
    $state = recargasamerica_state_read('breaker');
    if (empty($state)) {
        return;
    }

    $scopeKey = 'scope:' . recargasamerica_breaker_scope($path);
    $changed = false;
    foreach (['global', $scopeKey] as $key) {
        if (isset($state[$key])) {
            unset($state[$key]);
            $changed = true;
        }
    }

    if ($changed) {
        if (empty($state)) {
            recargasamerica_state_delete('breaker');
        } else {
            recargasamerica_state_write('breaker', $state);
        }
    }
}

// Qué error HTTP de la API justifica dejar de llamar (los demás 4xx/5xx son
// respuestas normales de una compra/consulta concreta).
function recargasamerica_breaker_note_http_failure(string $path, int $status, string $code): void {
    if ($status === 401) {
        recargasamerica_breaker_trip(null, 1800, 'API KEY inválida, borrada o desactivada (' . ($code !== '' ? $code : '401') . ')');
    } elseif ($status === 403) {
        recargasamerica_breaker_trip(null, 900, 'acceso denegado por RecargasAmérica (' . ($code !== '' ? $code : '403') . ')');
    } elseif ($status === 429) {
        recargasamerica_breaker_trip(null, 300, 'demasiadas solicitudes (429)');
    } elseif ($status === 404 && $code === 'ENDPOINT_DISABLED') {
        recargasamerica_breaker_trip(recargasamerica_breaker_scope($path), 3600, 'este endpoint fue dado de baja por RecargasAmérica');
    } else {
        // Un error "normal" de una orden concreta (422, 409, 502…) también prueba
        // que la conexión y la clave funcionan: cierra un corte previo.
        recargasamerica_breaker_success($path);
    }
}

// Entradas activas del corte, para mostrarlas en el diagnóstico.
function recargasamerica_breaker_status(): array {
    $rows = [];
    $now = time();
    foreach (recargasamerica_state_read('breaker') as $key => $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $rows[] = [
            'key' => (string) $key,
            'reason' => (string) ($entry['reason'] ?? ''),
            'until' => (int) ($entry['until'] ?? 0),
            'open' => $now < (int) ($entry['until'] ?? 0),
            'strikes' => (int) ($entry['strikes'] ?? 0),
        ];
    }
    return $rows;
}

function recargasamerica_breaker_reset(): void {
    recargasamerica_state_delete('breaker');
}

// Caché en archivo (5 min frescos; hasta 24 h como "último catálogo bueno"
// cuando RecargasAmérica no responde).
function recargasamerica_cache_get(string $name, int $maxAgeSeconds): ?array {
    $cached = recargasamerica_state_read('cache_' . $name);
    if (!isset($cached['t'], $cached['data']) || !is_array($cached['data'])) {
        return null;
    }
    return (time() - (int) $cached['t']) <= $maxAgeSeconds ? $cached['data'] : null;
}

function recargasamerica_cache_put(string $name, array $data): void {
    recargasamerica_state_write('cache_' . $name, ['t' => time(), 'data' => $data]);
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

function recargasamerica_api_request(string $method, string $path, ?array $payload, int $timeout, bool $verifySsl = true, array $extraHeaders = []): array {
    $apiKey = recargasamerica_api_key();
    if ($apiKey === '') {
        throw new RuntimeException('Configura primero la API KEY de RecargasAmérica.');
    }

    // Corte automático: si RecargasAmérica está fallando de forma definitiva
    // (clave inválida, IP bloqueada…), no se insiste — ver arriba.
    recargasamerica_breaker_check($path);

    $url = recargasamerica_api_base_url() . '/' . ltrim($path, '/');
    $connectTimeout = min(recargasamerica_api_connect_timeout_seconds(), max(1, $timeout));
    $headers = array_merge([
        'Authorization: Bearer ' . $apiKey,
        'Accept: application/json',
        'Content-Type: application/json',
    ], $extraHeaders);

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
        $curlErrno = curl_errno($ch);
        curl_close($ch);

        if ($response === false) {
            // 6 = no resuelve el nombre, 7 = conexión rechazada/inalcanzable: la
            // solicitud nunca llegó, y seguir insistiendo solo alarga un bloqueo.
            if (in_array($curlErrno, [6, 7], true)) {
                recargasamerica_breaker_trip(null, 300, 'no se puede conectar con RecargasAmérica');
            }
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
        recargasamerica_breaker_note_http_failure($path, (int) $status, (string) ($data['code'] ?? ''));
        throw new RecargasAmericaProviderException(
            recargasamerica_api_error_message_from_response($data, $status),
            (string) ($data['code'] ?? ''),
            $data
        );
    }

    if (array_key_exists('success', $data) && !$data['success']) {
        throw new RecargasAmericaProviderException(
            recargasamerica_api_error_message_from_response($data, $status ?? 0),
            (string) ($data['code'] ?? ''),
            $data
        );
    }

    // Llegó una respuesta buena: se cierra cualquier corte que hubiera.
    recargasamerica_breaker_success($path);

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

function recargasamerica_api_post(string $path, array $payload, int $timeout, array $extraHeaders = []): array {
    try {
        return recargasamerica_api_request('POST', $path, $payload, $timeout, true, $extraHeaders);
    } catch (Throwable $e) {
        $message = (string) $e->getMessage();
        $sslIssue = stripos($message, 'SSL certificate problem') !== false
            || stripos($message, 'unable to get local issuer certificate') !== false;
        if (!$sslIssue) {
            throw $e;
        }
        return recargasamerica_api_request('POST', $path, $payload, $timeout, false, $extraHeaders);
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

    $fresh = recargasamerica_cache_get('legacy_pins', 300);
    if ($fresh !== null) {
        return $cachedProducts = $fresh;
    }

    try {
        $response = recargasamerica_api_get('products/pins', recargasamerica_api_catalog_timeout_seconds());
        $products = $response['data'] ?? null;
        if (!is_array($products)) {
            throw new RuntimeException('RecargasAmérica no devolvió una lista válida de productos.');
        }
    } catch (Throwable $e) {
        // Sin respuesta: se usa el último catálogo bueno (hasta 24 h) en vez de fallar.
        $stale = recargasamerica_cache_get('legacy_pins', 86400);
        if ($stale !== null) {
            return $cachedProducts = $stale;
        }
        throw $e;
    }

    $cachedProducts = array_values(array_filter($products, 'is_array'));
    recargasamerica_cache_put('legacy_pins', $cachedProducts);
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
    } catch (RecargasAmericaProviderException $e) {
        // El proveedor SÍ respondió, con un error real y estructurado (saldo
        // insuficiente, producto inválido, rechazo del proveedor externo,
        // etc.) — no es un fallo de red. Debe dejar rastro visible (a
        // diferencia del catch de abajo) y marcarse para revisión manual en
        // vez de reintentarse en silencio sin que nadie se entere de la
        // razón real. NUNCA se creó una transacción del lado de
        // RecargasAmérica en este caso, así que reintentar más tarde sigue
        // siendo seguro (no hay riesgo de compra duplicada).
        return [
            'success' => false,
            'accepted' => false,
            'needs_manual_review' => true,
            'message' => $e->getMessage(),
            'reference' => '',
            'payload' => array_merge($e->responseData, ['provider_code' => $e->providerCode, 'request_payload' => $requestPayload]),
        ];
    } catch (Throwable $e) {
        // Fallo de transporte puro (timeout, DNS, conexión rechazada, JSON
        // inválido): el proveedor nunca llegó a responder, así que no hay
        // nada que verificar — se deja "limpio" para permitir un reintento
        // normal sin demora (ver recargasamerica_dispatch_or_recover).
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

// ═══════════════════════════════════════════════════════════════════════════
// Catálogo Unificado (/products/catalog, /buy/catalog, /catalog/validate,
// /catalog/orders/{order_id}) — reemplaza al módulo "PINes & Recargas"
// (/products/pins, /buy/pins, /pins/validate), que RecargasAmérica da de baja
// el 20-sep-2026 (después responde 404 ENDPOINT_DISABLED).
//
// OJO — los IDs del catálogo viejo y del nuevo son espacios DISTINTOS (ej. el
// id 4 es "Pin 5.600" en el viejo y "Pase Booyah" en el nuevo). Por eso cada
// paquete/pedido lleva una marca en `recargasamerica_tipo`:
//   'pin' | 'recharge'                  → catálogo VIEJO (legado)
//   'catalog_pin' | 'catalog_recharge'
//   | 'catalog_game' | 'catalog_streaming' → catálogo NUEVO
// Nunca se debe enviar un ID de un espacio a los endpoints del otro.
// ═══════════════════════════════════════════════════════════════════════════

function recargasamerica_tipo_normalize(?string $tipo): string {
    $value = strtolower(trim((string) $tipo));
    if (strpos($value, 'catalog_') === 0) {
        return in_array(substr($value, 8), ['pin', 'recharge', 'game', 'streaming'], true) ? $value : 'catalog_recharge';
    }

    return $value === 'pin' ? 'pin' : 'recharge';
}

function recargasamerica_tipo_is_catalog(?string $tipo): bool {
    return strpos(recargasamerica_tipo_normalize($tipo), 'catalog_') === 0;
}

// 'pin' | 'recharge' | 'game' | 'streaming' (sin importar en qué catálogo esté).
function recargasamerica_tipo_base(?string $tipo): string {
    $normalized = recargasamerica_tipo_normalize($tipo);
    return strpos($normalized, 'catalog_') === 0 ? substr($normalized, 8) : $normalized;
}

function recargasamerica_catalog_product_type(array $product): string {
    $type = strtolower(trim((string) ($product['type'] ?? '')));
    return in_array($type, ['pin', 'recharge', 'game', 'streaming'], true) ? $type : '';
}

function recargasamerica_catalog_tipo_for_product(array $product): string {
    $type = recargasamerica_catalog_product_type($product);
    return $type !== '' ? 'catalog_' . $type : '';
}

function recargasamerica_catalog_product_label(array $product): string {
    $name = trim((string) ($product['name'] ?? 'Producto'));
    $id = (int) ($product['id'] ?? 0);
    $price = isset($product['price']) ? number_format((float) $product['price'], 4, '.', '') : '0.0000';
    $labels = ['pin' => 'PIN', 'recharge' => 'Recarga', 'game' => 'Juego', 'streaming' => 'Streaming'];
    $type = $labels[recargasamerica_catalog_product_type($product)] ?? 'Producto';

    return $name . ' [ID ' . $id . '] - $' . $price . ' - ' . $type;
}

// Igual que recargasamerica_api_fetch_products_pins(): cacheado por request.
function recargasamerica_api_fetch_catalog(): array {
    static $cachedCatalog = null;

    if ($cachedCatalog !== null) {
        return $cachedCatalog;
    }

    // Catálogo fresco de hasta 5 min: evita una llamada a RecargasAmérica por
    // cada visita pública a un juego (los precios se sincronizan desde aquí).
    $fresh = recargasamerica_cache_get('catalog', 300);
    if ($fresh !== null) {
        return $cachedCatalog = $fresh;
    }

    try {
        $response = recargasamerica_api_get('products/catalog', recargasamerica_api_catalog_timeout_seconds());
        $products = $response['data'] ?? null;
        if (!is_array($products)) {
            throw new RuntimeException('RecargasAmérica no devolvió una lista válida del Catálogo Unificado.');
        }
    } catch (Throwable $e) {
        // Sin respuesta: se usa el último catálogo bueno (hasta 24 h) en vez de fallar.
        $stale = recargasamerica_cache_get('catalog', 86400);
        if ($stale !== null) {
            return $cachedCatalog = $stale;
        }
        throw $e;
    }

    $cachedCatalog = array_values(array_filter($products, 'is_array'));
    recargasamerica_cache_put('catalog', $cachedCatalog);
    return $cachedCatalog;
}

function recargasamerica_api_fetch_catalog_product_by_id(int $productId): ?array {
    foreach (recargasamerica_api_fetch_catalog() as $product) {
        if ((int) ($product['id'] ?? 0) === $productId) {
            return $product;
        }
    }

    return null;
}

// Busca el producto en el catálogo que le corresponde a la marca del
// paquete/pedido (viejo o nuevo) — nunca cruza espacios de IDs.
function recargasamerica_api_fetch_product_for_tipo(int $productId, ?string $tipo): ?array {
    return recargasamerica_tipo_is_catalog($tipo)
        ? recargasamerica_api_fetch_catalog_product_by_id($productId)
        : recargasamerica_api_fetch_product_by_id($productId);
}

// Campos que exige el producto (player_id, zone_id, manual_id, server_id,
// username), normalizados a minúsculas sin símbolos extraños.
function recargasamerica_catalog_required_fields(array $product): array {
    $fields = [];
    foreach ((array) ($product['required_fields'] ?? []) as $field) {
        $name = is_array($field) ? (string) ($field['name'] ?? '') : (string) $field;
        $name = preg_replace('/[^a-z0-9_]+/', '', strtolower(trim($name))) ?? '';
        if ($name !== '' && !in_array($name, $fields, true)) {
            $fields[] = $name;
        }
    }

    return $fields;
}

// Arma el cuerpo de campos del jugador para /buy/catalog. $submittedFields
// son los player_fields del pedido (claves ya normalizadas: minúsculas,
// solo a-z0-9_). El primer campo requerido toma el ID de jugador del pedido
// como valor de respaldo. Devuelve ['fields' => [...], 'missing' => [...]].
function recargasamerica_catalog_build_fields(array $requiredFields, string $userIdentifier, array $submittedFields): array {
    $aliases = [
        'player_id' => ['player_id', 'playerid', 'id_juego', 'user_id', 'userid', 'uid', 'input1'],
        'manual_id' => ['manual_id', 'manualid', 'player_id', 'playerid', 'id_juego', 'user_id', 'userid', 'uid', 'input1'],
        'zone_id' => ['zone_id', 'zoneid', 'zona', 'zone', 'input2'],
        'server_id' => ['server_id', 'serverid', 'server', 'input2'],
        'username' => ['username', 'usuario', 'user', 'correo', 'email'],
    ];

    $fields = [];
    $missing = [];
    foreach (array_values($requiredFields) as $index => $name) {
        $value = '';
        foreach ($aliases[$name] ?? [$name] as $alias) {
            $candidate = trim((string) ($submittedFields[$alias] ?? ''));
            if ($candidate !== '') {
                $value = $candidate;
                break;
            }
        }
        if ($value === '' && $index === 0) {
            $value = trim($userIdentifier);
        }

        if ($value === '') {
            $missing[] = $name;
            continue;
        }
        $fields[$name] = $value;
    }

    return ['fields' => $fields, 'missing' => $missing];
}

// Precheck de cuenta — puramente informativo: supported=false significa
// "este proveedor no sabe validar", NO un error ni un ID inválido.
function recargasamerica_api_validate_catalog_account(int $productId, string $serviceUserId): array {
    $response = recargasamerica_api_post('catalog/validate', [
        'product_id' => $productId,
        'service_user_id' => $serviceUserId,
    ], recargasamerica_api_lookup_timeout_seconds());

    $data = is_array($response['data'] ?? null) ? $response['data'] : [];

    return [
        'supported' => !array_key_exists('supported', $data) || !empty($data['supported']),
        'found' => !empty($data['status']),
        'account_name' => trim((string) ($data['account_name'] ?? '')),
    ];
}

// Idempotency-Key = una clave, una compra: mismo valor en un reintento
// (RecargasAmérica responde 409 DUPLICATE_REQUEST si la primera ya entró, en
// vez de cobrar dos veces); valor distinto para una compra distinta.
function recargasamerica_api_buy_catalog(int $productId, array $fields, int $quantity, string $idempotencyKey): array {
    $payload = array_merge($fields, [
        'product_id' => $productId,
        'quantity' => max(1, min(10, $quantity)),
    ]);

    return recargasamerica_api_post('buy/catalog', $payload, recargasamerica_api_purchase_timeout_seconds(), [
        'Idempotency-Key: ' . $idempotencyKey,
    ]);
}

function recargasamerica_api_fetch_catalog_order(string $orderId): array {
    return recargasamerica_api_get('catalog/orders/' . rawurlencode($orderId), recargasamerica_api_lookup_timeout_seconds());
}

// 'completed' | 'pending' | 'other' (cualquier otra cosa NUNCA cuenta como
// entregado — a diferencia del código legado, que trataba todo lo que no
// fuera "pendiente" como éxito).
function recargasamerica_catalog_classify_status(string $status): string {
    $normalized = strtolower(trim($status));
    if ($normalized === 'completed') {
        return 'completed';
    }
    if (in_array($normalized, ['processing_provider', 'pending', 'processing'], true)) {
        return 'pending';
    }

    return 'other';
}

// Aplana el `delivery` de una respuesta a una lista de ítems: acepta tanto
// una lista [{key, serial}, ...] como un único objeto {username, password}.
function recargasamerica_catalog_delivery_items($delivery): array {
    if (!is_array($delivery) || empty($delivery)) {
        return [];
    }

    $isSingleObject = isset($delivery['username']) || isset($delivery['password']) || isset($delivery['key']) || isset($delivery['serial']);
    $items = $isSingleObject ? [$delivery] : $delivery;

    return array_values(array_filter($items, static fn ($item) => is_array($item) || trim((string) $item) !== ''));
}

// `delivery` es siempre un array: [{key, serial}, ...] para PINs, o un único
// {username, password} para streaming (vacío si el producto no entrega nada,
// ej. recargas). Devuelve texto listo para guardar/mostrar al cliente.
function recargasamerica_catalog_format_delivery($delivery): string {
    $lines = [];
    foreach (recargasamerica_catalog_delivery_items($delivery) as $item) {
        if (!is_array($item)) {
            $lines[] = trim((string) $item);
            continue;
        }

        $username = trim((string) ($item['username'] ?? ''));
        $password = trim((string) ($item['password'] ?? ''));
        if ($username !== '' || $password !== '') {
            if ($username !== '') {
                $lines[] = 'Usuario: ' . $username;
            }
            if ($password !== '') {
                $lines[] = 'Contraseña: ' . $password;
            }
            continue;
        }

        $key = trim((string) ($item['key'] ?? ''));
        $serial = trim((string) ($item['serial'] ?? ''));
        if ($key !== '' && $serial !== '') {
            $lines[] = 'PIN: ' . $key;
            $lines[] = 'Serial: ' . $serial;
        } elseif ($key !== '') {
            $lines[] = $key;
        } elseif ($serial !== '') {
            $lines[] = 'Serial: ' . $serial;
        }
    }

    return implode("\n", $lines);
}

// recargas_api_pedido_id es VARCHAR(120): si la lista completa de order_id
// no cabe, se guarda solo el primero (la lista completa viaja siempre en
// ff_api_payload → catalog_order_ids, y de ahí la lee la recuperación).
function recargasamerica_catalog_reference_string(array $orderIds): string {
    $orderIds = array_values(array_filter(array_map('strval', $orderIds), static fn ($id) => $id !== ''));
    $joined = implode(',', $orderIds);

    return strlen($joined) <= 120 ? $joined : ($orderIds[0] ?? '');
}

function recargasamerica_catalog_reference_list(string $storedReference, ?string $payloadJson = null): array {
    $payload = is_string($payloadJson) && trim($payloadJson) !== '' ? json_decode($payloadJson, true) : null;
    if (is_array($payload) && !empty($payload['catalog_order_ids']) && is_array($payload['catalog_order_ids'])) {
        $ids = array_values(array_filter(array_map(static fn ($id) => trim((string) $id), $payload['catalog_order_ids']), static fn ($id) => $id !== ''));
        if (!empty($ids)) {
            return $ids;
        }
    }

    return array_values(array_filter(array_map('trim', explode(',', $storedReference)), static fn ($id) => $id !== ''));
}

// Cuántas llamadas a /buy/catalog corresponden a la cantidad del pedido:
// PIN admite hasta 10 por llamada; el resto siempre es 1 unidad por llamada.
function recargasamerica_catalog_expected_units(string $tipo, int $quantity): int {
    $quantity = max(1, $quantity);
    return recargasamerica_tipo_base($tipo) === 'pin' ? (int) ceil($quantity / 10) : $quantity;
}

function recargasamerica_catalog_result(bool $success, bool $accepted, bool $needsReview, string $message, string $reference, array $payload): array {
    // pedidos.ff_api_mensaje es VARCHAR(255): un mensaje más largo haría
    // fallar el UPDATE en modo estricto y el pedido perdería su rastro.
    $message = function_exists('mb_substr') ? mb_substr($message, 0, 240, 'UTF-8') : substr($message, 0, 240);

    return [
        'success' => $success,
        'accepted' => $accepted,
        'needs_manual_review' => $needsReview,
        'message' => $message,
        'reference' => $reference,
        'payload' => $payload,
    ];
}

// Compra en el Catálogo Unificado, mismo contrato de retorno que
// execute_recargasamerica_purchase(): success/accepted/needs_manual_review/
// message/reference/payload. Reglas de seguridad:
//  · Nunca se compra si el producto no existe en el catálogo NUEVO o si su
//    tipo no coincide con la marca del paquete (defensa contra mezclar IDs
//    del catálogo viejo con el nuevo).
//  · Cada llamada lleva su propia Idempotency-Key (estable por pedido) →
//    un reintento tras timeout nunca cobra dos veces.
//  · Si una unidad falla después de que otras ya se compraron, NO se
//    reintenta a ciegas: queda en revisión manual con las referencias de las
//    unidades que sí entraron.
function recargasamerica_catalog_purchase(int $productId, string $tipo, string $userIdentifier, array $submittedFields, int $quantity, string $idempotencyBase): array {
    $tipo = recargasamerica_tipo_normalize($tipo);
    $baseType = recargasamerica_tipo_base($tipo);
    $quantity = max(1, $quantity);
    $requestPayload = [
        'producto_id' => $productId,
        'tipo' => $tipo,
        'quantity' => $quantity,
        'catalogo' => 'unificado',
    ];

    try {
        $product = recargasamerica_api_fetch_catalog_product_by_id($productId);
    } catch (RecargasAmericaProviderException $e) {
        return recargasamerica_catalog_result(false, false, true, $e->getMessage(), '', array_merge($e->responseData, ['provider_code' => $e->providerCode, 'request_payload' => $requestPayload]));
    } catch (Throwable $e) {
        // Aún no se compró nada: fallo de transporte limpio, reintento seguro.
        return recargasamerica_catalog_result(false, false, false, trim($e->getMessage()) !== '' ? trim($e->getMessage()) : 'No se pudo consultar el Catálogo Unificado de RecargasAmérica.', '', ['exception' => $e->getMessage(), 'request_payload' => $requestPayload]);
    }

    if ($product === null) {
        return recargasamerica_catalog_result(false, false, true, 'El producto ' . $productId . ' ya no existe en el Catálogo Unificado de RecargasAmérica.', '', ['request_payload' => $requestPayload]);
    }
    if (recargasamerica_catalog_product_type($product) !== $baseType) {
        return recargasamerica_catalog_result(false, false, true, 'El paquete apunta a un producto distinto del esperado en el Catálogo Unificado (ID ' . $productId . ' es "' . trim((string) ($product['name'] ?? '')) . '"). Reasigna el paquete a su producto correcto.', '', ['request_payload' => $requestPayload]);
    }

    $built = recargasamerica_catalog_build_fields(recargasamerica_catalog_required_fields($product), $userIdentifier, $submittedFields);
    if (!empty($built['missing'])) {
        return recargasamerica_catalog_result(false, false, true, 'Falta el campo requerido por RecargasAmérica: ' . implode(', ', $built['missing']) . '.', '', ['request_payload' => $requestPayload]);
    }

    $calls = [];
    if ($baseType === 'pin') {
        $remaining = $quantity;
        while ($remaining > 0) {
            $chunk = min(10, $remaining);
            $calls[] = $chunk;
            $remaining -= $chunk;
        }
    } else {
        $calls = array_fill(0, $quantity, 1);
    }

    $orderIds = [];
    $deliveryItems = [];
    $anyPending = false;
    $amountCharged = 0.0;
    $total = count($calls);

    foreach ($calls as $index => $callQuantity) {
        $callNumber = $index + 1;
        $failure = null;
        $failureIsProvider = false;

        try {
            $response = recargasamerica_api_buy_catalog($productId, $built['fields'], $callQuantity, $idempotencyBase . '-' . $callNumber);
        } catch (RecargasAmericaProviderException $e) {
            $failure = $e;
            $failureIsProvider = true;
        } catch (Throwable $e) {
            $failure = $e;
        }

        if ($failure !== null) {
            $partialPayload = [
                'request_payload' => $requestPayload,
                'catalog_order_ids' => $orderIds,
                'units_purchased' => count($orderIds),
                'units_expected' => $total,
            ];
            if ($failureIsProvider) {
                $partialPayload = array_merge($failure->responseData, ['provider_code' => $failure->providerCode], $partialPayload);
            }

            if (empty($orderIds)) {
                if ($failureIsProvider) {
                    $duplicate = $failure->providerCode === 'DUPLICATE_REQUEST';
                    return recargasamerica_catalog_result(false, false, true, $duplicate
                        ? 'RecargasAmérica ya había aceptado esta compra (DUPLICATE_REQUEST). No se reintenta: revisa el historial de RecargasAmérica.'
                        : $failure->getMessage(), '', $partialPayload);
                }
                // Transporte puro sin ninguna unidad comprada: reintento limpio
                // (misma Idempotency-Key → si la primera sí entró, 409).
                return recargasamerica_catalog_result(false, false, false, trim($failure->getMessage()) !== '' ? trim($failure->getMessage()) : 'La API de RecargasAmérica rechazó la compra.', '', array_merge($partialPayload, ['exception' => $failure->getMessage()]));
            }

            return recargasamerica_catalog_result(false, false, true, 'Solo ' . count($orderIds) . ' de ' . $total . ' compras se completaron en RecargasAmérica; la siguiente falló: ' . $failure->getMessage() . ' Revisa el pedido manualmente antes de reintentar.', recargasamerica_catalog_reference_string($orderIds), $partialPayload);
        }

        $data = is_array($response['data'] ?? null) ? $response['data'] : [];
        $orderId = trim((string) ($data['order_id'] ?? ''));
        if ($orderId === '') {
            $orderId = trim((string) ($data['transaction_id'] ?? ''));
        }
        if ($orderId !== '') {
            $orderIds[] = $orderId;
        }
        $amountCharged += (float) ($data['amount_charged'] ?? 0);

        $statusClass = recargasamerica_catalog_classify_status((string) ($data['status'] ?? ''));
        if ($statusClass === 'other') {
            return recargasamerica_catalog_result(false, false, true, 'RecargasAmérica devolvió un estado no reconocido ("' . trim((string) ($data['status'] ?? '')) . '") para esta compra. Revísala manualmente.', recargasamerica_catalog_reference_string($orderIds), array_merge($data, [
                'request_payload' => $requestPayload,
                'catalog_order_ids' => $orderIds,
                'units_purchased' => count($orderIds),
                'units_expected' => $total,
            ]));
        }
        if ($statusClass === 'pending') {
            $anyPending = true;
        }
        foreach (recargasamerica_catalog_delivery_items($data['delivery'] ?? []) as $deliveryItem) {
            $deliveryItems[] = $deliveryItem;
        }
    }

    $payload = [
        'status' => $anyPending ? 'PROCESSING_PROVIDER' : 'COMPLETED',
        'catalog_order_ids' => $orderIds,
        'units_purchased' => count($orderIds),
        'units_expected' => $total,
        'amount_charged' => round($amountCharged, 4),
        'item' => trim((string) ($product['name'] ?? '')),
        'delivery' => $deliveryItems,
        'delivery_text' => $anyPending ? '' : recargasamerica_catalog_format_delivery($deliveryItems),
        'request_payload' => $requestPayload,
    ];
    $reference = recargasamerica_catalog_reference_string($orderIds);

    if ($anyPending) {
        return recargasamerica_catalog_result(false, true, false, 'Recarga en proceso.', $reference, $payload);
    }

    return recargasamerica_catalog_result(true, false, false, 'Compra completada por RecargasAmérica.', $reference, $payload);
}

// Reconsulta el estado real de compras ya hechas (nunca compra de nuevo).
function recargasamerica_catalog_recover(array $orderIds, int $expectedUnits): array {
    $orderIds = array_values(array_unique(array_filter(array_map('strval', $orderIds), static fn ($id) => $id !== '')));
    $reference = recargasamerica_catalog_reference_string($orderIds);
    $basePayload = ['recovery_check' => true, 'catalog_order_ids' => $orderIds, 'units_expected' => $expectedUnits];

    if (empty($orderIds)) {
        return recargasamerica_catalog_result(false, false, true, 'No hay referencias guardadas de RecargasAmérica para verificar este pedido.', '', $basePayload);
    }

    $anyPending = false;
    $needsReview = false;
    $deliveryItems = [];
    $statuses = [];
    $reviewMessage = '';

    foreach ($orderIds as $orderId) {
        try {
            $response = recargasamerica_api_fetch_catalog_order($orderId);
        } catch (RecargasAmericaProviderException $e) {
            return recargasamerica_catalog_result(false, false, true, $e->getMessage(), $reference, array_merge($basePayload, $e->responseData, ['provider_code' => $e->providerCode]));
        } catch (Throwable $e) {
            return recargasamerica_catalog_result(false, false, true, 'No se pudo verificar el estado del intento anterior con RecargasAmérica: ' . $e->getMessage(), $reference, array_merge($basePayload, ['exception_recovery' => $e->getMessage()]));
        }

        $data = is_array($response['data'] ?? null) ? $response['data'] : [];
        $status = trim((string) ($data['status'] ?? ''));
        $statuses[$orderId] = $status;
        $statusClass = recargasamerica_catalog_classify_status($status);

        if (!empty($data['needs_review']) || $statusClass === 'other') {
            $needsReview = true;
            if ($reviewMessage === '') {
                $reviewMessage = 'La orden ' . $orderId . ' está en estado "' . ($status !== '' ? $status : 'desconocido') . '"' . (!empty($data['needs_review']) ? ' y marcada para revisión' : '') . ' en RecargasAmérica.';
            }
        } elseif ($statusClass === 'pending') {
            $anyPending = true;
        }

        foreach (recargasamerica_catalog_delivery_items($data['delivery'] ?? []) as $deliveryItem) {
            $deliveryItems[] = $deliveryItem;
        }
    }

    $payload = array_merge($basePayload, ['statuses' => $statuses, 'units_purchased' => count($orderIds)]);

    if ($needsReview) {
        return recargasamerica_catalog_result(false, false, true, $reviewMessage, $reference, $payload);
    }
    if (count($orderIds) < $expectedUnits) {
        return recargasamerica_catalog_result(false, false, true, 'Solo ' . count($orderIds) . ' de ' . $expectedUnits . ' compras quedaron registradas en RecargasAmérica. Revisa el pedido manualmente.', $reference, $payload);
    }
    if ($anyPending) {
        return recargasamerica_catalog_result(false, true, false, 'Recarga en proceso.', $reference, array_merge($payload, ['status' => 'PROCESSING_PROVIDER']));
    }

    return recargasamerica_catalog_result(true, false, false, 'Compra confirmada por RecargasAmérica.', $reference, array_merge($payload, [
        'status' => 'COMPLETED',
        'delivery' => $deliveryItems,
        'delivery_text' => recargasamerica_catalog_format_delivery($deliveryItems),
    ]));
}
