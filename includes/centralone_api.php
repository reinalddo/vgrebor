<?php
// Integración con la API REST de Central One (portal.centraloneglobal.com) —
// tercer proveedor de recargas/gift cards/PINes, en paralelo a GiftVen
// (includes/recargas_api.php) y RecargasAmérica (includes/recargasamerica_api.php).
//
// Fase 1 (2026-10-09): solo el cliente de la API (catálogo, saldo, crear
// pedido, consultar estado, pedir códigos, verificar firma de webhook) +
// la API KEY configurable desde el panel. Todavía NO está enganchado al
// flujo de compra de clientes (api/pedidos.php) ni al selector de paquetes
// (admin/paquetes.php) — eso es la Fase 2, pendiente de aprobación aparte
// porque toca el motor de pedidos real.
//
// Autenticación: Bearer token (Authorization: Bearer co_live_... / co_test_...).
// Formato de errores confirmado en vivo: {"error":{"code","message","request_id"}}
// con el HTTP status real (400/401/403/404/409/422/429/5xx).
// Todos los importes son strings con 4 decimales (confirmado contra la API
// real: el saldo y los precios del catálogo, pese a que la documentación
// trae ejemplos inconsistentes con 2 decimales — se confía en el
// comportamiento real, no en el ejemplo de la doc).

require_once __DIR__ . '/store_config.php';

function centralone_api_base_url(): string {
    return 'https://portal.centraloneglobal.com/api/v1';
}

function centralone_api_key(): string {
    return trim(store_config_get('centralone_api_key', ''));
}

function centralone_webhook_secret(): string {
    return trim(store_config_get('centralone_webhook_secret', ''));
}

function centralone_api_is_configured(): bool {
    return centralone_api_key() !== '';
}

function centralone_api_connect_timeout_seconds(): int {
    return 10;
}

// El catálogo completo pesa varios MB (no pagina) — más margen que una
// consulta normal.
function centralone_api_catalog_timeout_seconds(): int {
    return 30;
}

function centralone_api_order_timeout_seconds(): int {
    return 30;
}

function centralone_api_lookup_timeout_seconds(): int {
    return 20;
}

function centralone_api_decode_response_body(?string $body): ?array {
    $body = trim((string) $body);
    if ($body === '') {
        return null;
    }

    $data = json_decode($body, true);
    return is_array($data) ? $data : null;
}

function centralone_api_response_snippet(?string $body, int $limit = 240): string {
    $body = trim((string) $body);
    if ($body === '') {
        return '[empty body]';
    }

    $body = preg_replace('/\s+/u', ' ', $body) ?? $body;
    return function_exists('mb_substr') ? mb_substr($body, 0, $limit, 'UTF-8') : substr($body, 0, $limit);
}

// Se lanza solo cuando Central One respondió de verdad con un error
// estructurado ({"error":{"code","message","request_id"}}) — nunca para
// timeouts o fallos de conexión, donde no hubo respuesta real que mostrar.
class CentralOneProviderException extends RuntimeException {
    public int $httpStatus;
    public string $errorCode;
    public string $requestId;
    public array $responseData;

    public function __construct(string $message, int $httpStatus, string $errorCode = '', string $requestId = '', array $responseData = []) {
        parent::__construct($message);
        $this->httpStatus = $httpStatus;
        $this->errorCode = $errorCode;
        $this->requestId = $requestId;
        $this->responseData = $responseData;
    }
}

// ── Estado en disco (caché del catálogo) ────────────────────────────────────
// Mismo patrón que recargasamerica_state_*(): archivos temporales separados
// por API KEY, así cambiar la llave en Configuración invalida la caché sola.

function centralone_state_dir(): string {
    return sys_get_temp_dir();
}

function centralone_state_key(): string {
    return substr(sha1('tvg-co|' . centralone_api_key()), 0, 16);
}

function centralone_state_path(string $kind): string {
    return rtrim(centralone_state_dir(), '/\\') . DIRECTORY_SEPARATOR . 'tvg_co_' . preg_replace('/[^a-z0-9_]+/i', '', $kind) . '_' . centralone_state_key() . '.json';
}

function centralone_cache_get(string $name, int $maxAgeSeconds): ?array {
    $path = centralone_state_path('cache_' . $name);
    if (!is_file($path)) {
        return null;
    }
    $decoded = json_decode((string) @file_get_contents($path), true);
    if (!is_array($decoded) || !isset($decoded['t'], $decoded['data']) || !is_array($decoded['data'])) {
        return null;
    }
    return (time() - (int) $decoded['t']) <= $maxAgeSeconds ? $decoded['data'] : null;
}

function centralone_cache_put(string $name, array $data): void {
    @file_put_contents(centralone_state_path('cache_' . $name), json_encode(['t' => time(), 'data' => $data]), LOCK_EX);
}

// ── Petición HTTP ────────────────────────────────────────────────────────────

function centralone_api_error_message_from_response(?array $data, int $status): string {
    $error = is_array($data['error'] ?? null) ? $data['error'] : [];
    $text = trim((string) ($error['message'] ?? ''));
    return $text !== '' ? $text : ('Central One respondió con código HTTP ' . $status . '.');
}

function centralone_api_request(string $method, string $path, ?array $payload, int $timeout, array $extraHeaders = []): array {
    $apiKey = centralone_api_key();
    if ($apiKey === '') {
        throw new RuntimeException('Configura primero la API KEY de Central One en Configuración > Datos API.');
    }

    $url = centralone_api_base_url() . '/' . ltrim($path, '/');
    $connectTimeout = min(centralone_api_connect_timeout_seconds(), max(1, $timeout));
    $headers = array_merge([
        'Authorization: Bearer ' . $apiKey,
        'Accept: application/json',
        'Content-Type: application/json',
    ], $extraHeaders);

    if (!function_exists('curl_init')) {
        throw new RuntimeException('La extensión cURL de PHP no está disponible: no se puede llamar a Central One.');
    }

    $ch = curl_init($url);
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => $connectTimeout,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ];
    if ($method === 'POST') {
        $requestBody = json_encode($payload ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($requestBody)) {
            throw new RuntimeException('No se pudo serializar la solicitud JSON para Central One.');
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
        throw new RuntimeException('No se pudo consultar la API de Central One: ' . $error);
    }

    $data = centralone_api_decode_response_body((string) $response);
    if (!is_array($data)) {
        error_log('TVG centralone invalid JSON response [' . $status . '] ' . $url . ' :: ' . centralone_api_response_snippet((string) $response));
        throw new RuntimeException(trim((string) $response) === ''
            ? 'Central One devolvió una respuesta vacía.'
            : 'Central One no devolvió un JSON válido.');
    }

    if ($status >= 400) {
        $errorBlock = is_array($data['error'] ?? null) ? $data['error'] : [];
        throw new CentralOneProviderException(
            centralone_api_error_message_from_response($data, $status),
            $status,
            (string) ($errorBlock['code'] ?? ''),
            (string) ($errorBlock['request_id'] ?? ''),
            $data
        );
    }

    return $data;
}

function centralone_api_get(string $path, int $timeout): array {
    return centralone_api_request('GET', $path, null, $timeout);
}

function centralone_api_post(string $path, array $payload, int $timeout, array $extraHeaders = []): array {
    return centralone_api_request('POST', $path, $payload, $timeout, $extraHeaders);
}

// ── Endpoints de solo lectura ────────────────────────────────────────────────

function centralone_api_health(): array {
    return centralone_api_request('GET', 'health', null, centralone_api_lookup_timeout_seconds());
}

function centralone_api_fetch_balance(): array {
    $data = centralone_api_get('balance', centralone_api_lookup_timeout_seconds());
    return [
        'currency' => (string) ($data['currency'] ?? 'USD'),
        'available_balance' => (string) ($data['available_balance'] ?? '0.0000'),
        'held_balance' => (string) ($data['held_balance'] ?? '0.0000'),
        'total_balance' => (string) ($data['total_balance'] ?? '0.0000'),
    ];
}

// Catálogo completo (no pagina — hoy ronda ~8000 productos en una sola
// respuesta). Se cachea 10 min frescos / 24 h como respaldo si la API falla,
// mismo criterio que recargasamerica_api_fetch_catalog().
function centralone_api_fetch_catalog(): array {
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    $fresh = centralone_cache_get('catalog', 600);
    if ($fresh !== null) {
        return $cached = $fresh;
    }

    try {
        $data = centralone_api_get('catalog', centralone_api_catalog_timeout_seconds());
        $items = $data['items'] ?? null;
        if (!is_array($items)) {
            throw new RuntimeException('Central One no devolvió una lista válida de catálogo.');
        }
    } catch (Throwable $e) {
        $stale = centralone_cache_get('catalog', 86400);
        if ($stale !== null) {
            return $cached = $stale;
        }
        throw $e;
    }

    $cached = array_values(array_filter($items, 'is_array'));
    centralone_cache_put('catalog', $cached);
    return $cached;
}

function centralone_api_fetch_catalog_product(string $productId): ?array {
    $productId = trim($productId);
    if ($productId === '') {
        return null;
    }
    foreach (centralone_api_fetch_catalog() as $product) {
        if ((string) ($product['product_id'] ?? '') === $productId) {
            return $product;
        }
    }
    return null;
}

// Búsqueda para el selector "agregar producto" (uno a la vez, nunca por
// lote): coincidencia por nombre, SKU o familia de producto. Resultados
// activos y en stock primero, ordenados por precio ascendente.
function centralone_catalog_buscar(string $query, int $limit = 25): array {
    $query = mb_strtolower(trim($query), 'UTF-8');
    if ($query === '' || mb_strlen($query, 'UTF-8') < 2) {
        return [];
    }

    $coincidencias = [];
    foreach (centralone_api_fetch_catalog() as $producto) {
        if ((string) ($producto['status'] ?? '') !== 'active') {
            continue;
        }
        $haystack = mb_strtolower(
            ($producto['name'] ?? '') . ' ' . ($producto['sku'] ?? '') . ' ' . ($producto['product_family_name'] ?? ''),
            'UTF-8'
        );
        if (mb_strpos($haystack, $query, 0, 'UTF-8') !== false) {
            $coincidencias[] = $producto;
        }
        if (count($coincidencias) >= $limit * 6) {
            // Suficientes candidatos para ordenar y recortar sin recorrer
            // los ~8000 productos completos en cada búsqueda.
            break;
        }
    }

    usort($coincidencias, static function (array $a, array $b): int {
        $enStockA = !empty($a['in_stock']) ? 0 : 1;
        $enStockB = !empty($b['in_stock']) ? 0 : 1;
        if ($enStockA !== $enStockB) {
            return $enStockA <=> $enStockB;
        }
        return (float) ($a['reseller_price'] ?? 0) <=> (float) ($b['reseller_price'] ?? 0);
    });

    return array_slice($coincidencias, 0, $limit);
}

// ── Pedidos ──────────────────────────────────────────────────────────────────

// 8-255 caracteres, solo A-Za-z0-9_- (regla exacta de Central One). Estable
// por los mismos insumos: un reintento con los mismos datos reutiliza la
// misma llave y Central One devuelve el mismo pedido sin cobrar de nuevo.
function centralone_idempotency_key(string $scope, string ...$partes): string {
    $base = 'vgr-' . preg_replace('/[^a-z0-9]+/i', '', $scope) . '-' . substr(sha1(implode('|', $partes)), 0, 32);
    return substr($base, 0, 255);
}

// $items: lista de ['catalog_item_id' => string, 'quantity' => int, 'target_payload' => array opcional].
function centralone_api_create_order(array $items, string $idempotencyKey, string $note = ''): array {
    if (strlen($idempotencyKey) < 8 || strlen($idempotencyKey) > 255 || !preg_match('/^[A-Za-z0-9_-]+$/', $idempotencyKey)) {
        throw new RuntimeException('Idempotency-Key inválida (debe tener de 8 a 255 caracteres A-Za-z0-9_-).');
    }
    if (empty($items) || count($items) > 20) {
        throw new RuntimeException('Un pedido a Central One admite de 1 a 20 líneas.');
    }

    $payloadItems = [];
    foreach ($items as $item) {
        $linea = [
            'catalog_item_id' => (string) ($item['catalog_item_id'] ?? ''),
            'quantity' => max(1, min(1000, (int) ($item['quantity'] ?? 1))),
        ];
        if (!empty($item['target_payload']) && is_array($item['target_payload'])) {
            $linea['target_payload'] = $item['target_payload'];
        }
        $payloadItems[] = $linea;
    }

    $body = ['items' => $payloadItems];
    if (trim($note) !== '') {
        $body['note'] = mb_substr(trim($note), 0, 500, 'UTF-8');
    }

    $data = centralone_api_post('orders', $body, centralone_api_order_timeout_seconds(), [
        'Idempotency-Key: ' . $idempotencyKey,
    ]);

    return is_array($data['order'] ?? null) ? $data['order'] : [];
}

function centralone_api_get_order(string $orderId): array {
    $data = centralone_api_get('orders/' . rawurlencode($orderId), centralone_api_lookup_timeout_seconds());
    return is_array($data['order'] ?? null) ? $data['order'] : [];
}

// Requiere el permiso codes:read en la llave — si no lo tiene, Central One
// responde 403 insufficient_scope (CentralOneProviderException).
function centralone_api_get_order_codes(string $orderId): array {
    $data = centralone_api_get('orders/' . rawurlencode($orderId) . '/codes', centralone_api_lookup_timeout_seconds());
    return is_array($data['order'] ?? null) ? $data['order'] : [];
}

// ── Webhooks ─────────────────────────────────────────────────────────────────
// Firma: header X-CentralOne-Signature = "sha256=" + HMAC-SHA256(secreto,
// timestamp + "." + cuerpo crudo), con X-CentralOne-Timestamp en segundos
// Unix. Se rechazan timestamps de más de 5 minutos (replay).
// ── Fase 2: despacho real de un pedido de la tienda ─────────────────────────
// Mismo contrato de retorno que recargasamerica_dispatch_or_recover() /
// recargasamerica_catalog_purchase() (api/pedidos.php): success/accepted/
// needs_manual_review/message/reference/payload. $order es la fila de
// `pedidos` (o una copia en memoria) con, al menos: id, centralone_product_id
// (snapshot del producto elegido), player_fields_json, user_identifier,
// cantidad_compra, creado_en, y — en una recuperación — recargas_api_pedido_id
// (reutilizada como columna genérica de "id de pedido del proveedor", igual
// que ya hacen GiftVen y el respaldo del Baúl, no solo RecargasAmérica).

function centralone_result(bool $success, bool $accepted, bool $needsReview, string $message, string $reference, array $payload): array {
    // recargas_api_mensaje es VARCHAR(255) en varias UPDATE: un mensaje más
    // largo haría fallar el guardado en modo estricto.
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

// Junta los códigos de todas las líneas de la respuesta de /codes en un solo
// texto (uno por línea) — hoy cada pedido de la tienda es una sola línea de
// Central One, pero esto no asume esa cantidad.
function centralone_format_delivery_text(array $codesOrder): string {
    $lineas = [];
    foreach ((array) ($codesOrder['items'] ?? []) as $item) {
        foreach ((array) ($item['codes'] ?? []) as $codigo) {
            $codigo = trim((string) $codigo);
            if ($codigo !== '') {
                $lineas[] = $codigo;
            }
        }
    }
    return implode("\n", $lineas);
}

// 'completed' | 'partial' | 'failed' | 'pending'. 'partial' es su propio
// estado (no se mezcla con 'completed' ni se auto-resuelve): la regla del
// cliente es que un pedido parcial SIEMPRE pasa a revisión manual, igual que
// uno fallido — nunca hay reembolso automático.
function centralone_order_classify_status(string $status): string {
    $normalizado = strtolower(trim($status));
    if ($normalizado === 'completed') {
        return 'completed';
    }
    if ($normalizado === 'partially_completed') {
        return 'partial';
    }
    if (in_array($normalizado, ['failed', 'cancelled'], true)) {
        return 'failed';
    }
    // created, confirmed, processing: todavía en curso.
    return 'pending';
}

// Arma el target_payload a partir de los campos ya guardados del pedido
// (player_fields_json/user_identifier), validando contra el target_fields
// REAL del producto en el catálogo vigente — nunca se confía en lo que haya
// quedado guardado en el pedido si el catálogo cambió. Devuelve
// ['ok'=>bool, 'payload'=>array, 'falta'=>string] ('falta' solo si ok=false).
function centralone_build_target_payload(array $producto, string $userIdentifier, array $submittedFields): array {
    if (empty($producto['requires_target'])) {
        return ['ok' => true, 'payload' => [], 'falta' => ''];
    }

    $camposRequeridos = array_values(array_filter(array_map('strval', (array) ($producto['target_fields'] ?? []))));
    if (empty($camposRequeridos)) {
        return ['ok' => true, 'payload' => [], 'falta' => ''];
    }

    $payload = [];
    foreach ($camposRequeridos as $indice => $campo) {
        $valor = trim((string) ($submittedFields[$campo] ?? ''));
        // El primer campo requerido admite el identificador principal del
        // pedido como respaldo (mismo criterio que
        // recargasamerica_catalog_build_fields()).
        if ($valor === '' && $indice === 0) {
            $valor = trim($userIdentifier);
        }
        if ($valor === '') {
            return ['ok' => false, 'payload' => [], 'falta' => $campo];
        }
        $payload[$campo] = $valor;
    }

    return ['ok' => true, 'payload' => $payload, 'falta' => ''];
}

// Compra nueva: el pedido todavía no tiene order_id de Central One guardado.
function centralone_purchase(array $order): array {
    $productId = trim((string) ($order['centralone_product_id'] ?? ''));
    if ($productId === '') {
        return centralone_result(false, false, true, 'Este pedido no tiene un producto de Central One configurado.', '', []);
    }

    try {
        $producto = centralone_api_fetch_catalog_product($productId);
    } catch (Throwable $e) {
        // Fallo de red al consultar el catálogo: no es un rechazo real, se
        // puede reintentar (el pedido se queda "pagado" sin marcar revisión).
        return centralone_result(false, false, false, 'No se pudo consultar el catálogo de Central One: ' . $e->getMessage(), '', ['exception' => $e->getMessage()]);
    }
    if ($producto === null || (string) ($producto['status'] ?? '') !== 'active') {
        return centralone_result(false, false, true, 'Este producto ya no está disponible en Central One.', '', []);
    }

    $playerFields = function_exists('order_player_fields_from_json')
        ? order_player_fields_from_json($order['player_fields_json'] ?? null)
        : [];
    $targetBuild = centralone_build_target_payload($producto, (string) ($order['user_identifier'] ?? ''), $playerFields);
    if (!$targetBuild['ok']) {
        return centralone_result(false, false, true, 'Falta el dato "' . $targetBuild['falta'] . '" que exige este producto.', '', []);
    }

    $cantidad = max(1, (int) ($order['cantidad_compra'] ?? 1));
    // Clave estable por pedido: un reintento del MISMO pedido (timeout,
    // recarga de página) reutiliza la misma llave y Central One devuelve el
    // mismo pedido sin cobrar dos veces.
    $idempotencyKey = centralone_idempotency_key(
        'order',
        (string) ($order['id'] ?? 0),
        $productId,
        (string) ($order['creado_en'] ?? '')
    );

    try {
        $creado = centralone_api_create_order(
            [['catalog_item_id' => $productId, 'quantity' => $cantidad, 'target_payload' => $targetBuild['payload']]],
            $idempotencyKey,
            'Pedido tienda #' . (int) ($order['id'] ?? 0)
        );
    } catch (CentralOneProviderException $e) {
        return centralone_result(false, false, true, $e->getMessage(), '', ['error_code' => $e->errorCode, 'request_id' => $e->requestId]);
    } catch (Throwable $e) {
        return centralone_result(false, false, false, $e->getMessage(), '', ['exception' => $e->getMessage()]);
    }

    $orderId = trim((string) ($creado['id'] ?? ''));
    if ($orderId === '') {
        return centralone_result(false, false, true, 'Central One no devolvió un id de pedido.', '', $creado);
    }

    return centralone_recover($orderId, $creado);
}

// Recuperación/sondeo: el pedido ya tiene (o se acaba de crear) un order_id
// de Central One — se consulta su estado real, nunca se vuelve a comprar.
function centralone_recover(string $orderId, ?array $detalleConocido = null): array {
    try {
        $detalle = $detalleConocido ?? centralone_api_get_order($orderId);
    } catch (CentralOneProviderException $e) {
        // 404/403 reales del proveedor sobre ESTE pedido: hay que revisar a mano.
        return centralone_result(false, false, true, $e->getMessage(), $orderId, ['error_code' => $e->errorCode]);
    } catch (Throwable $e) {
        // Fallo de transporte puro: se reintenta, no es un rechazo.
        return centralone_result(false, true, false, 'No se pudo consultar el pedido en Central One: ' . $e->getMessage(), $orderId, ['exception' => $e->getMessage()]);
    }

    $status = (string) ($detalle['status'] ?? '');
    $clase = centralone_order_classify_status($status);
    $mensaje = $status !== '' ? ('Central One: ' . $status) : 'Central One no devolvió un estado.';
    // El webhook identifica el pedido por reference_code, no por id — se
    // guarda como "reference" (columna genérica ff_api_referencia) para que
    // api/centralone_webhook.php pueda encontrar la fila; recargas_api_pedido_id
    // sigue guardando el id real, que es lo que piden GET /orders/{id} y
    // GET /orders/{id}/codes.
    $referenceCode = trim((string) ($detalle['reference_code'] ?? '')) !== '' ? (string) $detalle['reference_code'] : $orderId;

    $tieneEntrega = false;
    foreach ((array) ($detalle['items'] ?? []) as $linea) {
        if ((int) ($linea['delivered_count'] ?? 0) > 0) {
            $tieneEntrega = true;
            break;
        }
    }

    $deliveryText = '';
    if ($tieneEntrega) {
        try {
            $deliveryText = centralone_format_delivery_text(centralone_api_get_order_codes($orderId));
        } catch (Throwable $e) {
            // Se completó pero los códigos no se pudieron leer todavía (ej.
            // codes:read sin permiso, fallo transitorio) — se reintenta
            // después; nunca se pierde el order_id ya guardado.
            return centralone_result(false, true, true, 'El pedido se completó pero no se pudieron leer los códigos: ' . $e->getMessage(), $referenceCode, $detalle);
        }
    }

    $payload = array_merge($detalle, ['delivery_text' => $deliveryText]);

    if ($clase === 'completed') {
        return centralone_result(true, true, false, $mensaje, $referenceCode, $payload);
    }
    if ($clase === 'partial' || $clase === 'failed') {
        // Nunca reembolso automático: parcial y fallido van IGUAL a revisión
        // manual (regla confirmada por el cliente). Si algo sí se entregó
        // ($deliveryText), queda guardado para que el admin lo vea.
        return centralone_result(false, false, true, $mensaje, $referenceCode, $payload);
    }
    // pending: created/confirmed/processing — sigue en curso.
    return centralone_result(false, true, false, $mensaje, $referenceCode, $payload);
}

// Punto de entrada único: compra si el pedido no tiene order_id todavía,
// reconsulta si ya lo tiene. Nunca compra dos veces.
function centralone_dispatch_or_recover(array $order): array {
    $existingOrderId = trim((string) ($order['recargas_api_pedido_id'] ?? ''));
    if ($existingOrderId === '') {
        return centralone_purchase($order);
    }

    return centralone_recover($existingOrderId);
}

function centralone_webhook_verify(string $rawBody, string $timestampHeader, string $signatureHeader, int $toleranciaSegundos = 300): bool {
    $secret = centralone_webhook_secret();
    if ($secret === '') {
        return false;
    }

    $timestamp = trim($timestampHeader);
    if ($timestamp === '' || !ctype_digit($timestamp)) {
        return false;
    }
    if (abs(time() - (int) $timestamp) > $toleranciaSegundos) {
        return false;
    }

    $signatureHeader = trim($signatureHeader);
    if (!str_starts_with($signatureHeader, 'sha256=')) {
        return false;
    }
    $received = substr($signatureHeader, 7);

    $expected = hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);

    return hash_equals($expected, $received);
}

// ── Deduplicación de eventos de webhook ─────────────────────────────────────
// La doc de Central One es explícita: "la entrega es AL MENOS UNA vez... el
// mismo event_id puede llegar dos veces: procésalo una sola" (y lo repite
// reintentando hasta 6 veces con esperas crecientes si el endpoint falla).
// Como un código entregado es dinero, no basta con la protección natural de
// los UPDATE ... WHERE estado='pagado' — se guarda explícitamente qué
// event_id ya se procesó. Vive en la misma caché de disco que el catálogo
// (sys_get_temp_dir(), por hash de la API KEY); se guardan hasta 500 ids con
// menos de 48 h (más margen que el último reintento, ~22 h).
function centralone_webhook_event_already_processed(string $eventId): bool {
    $eventId = trim($eventId);
    if ($eventId === '') {
        return false;
    }
    $vistos = centralone_cache_get('webhook_event_ids', 48 * 3600) ?? [];
    return isset($vistos[$eventId]);
}

function centralone_webhook_mark_event_processed(string $eventId): void {
    $eventId = trim($eventId);
    if ($eventId === '') {
        return;
    }
    $vistos = centralone_cache_get('webhook_event_ids', 48 * 3600) ?? [];
    $vistos[$eventId] = time();
    // Más recientes primero, recorta a 500 para que el archivo no crezca sin límite.
    arsort($vistos);
    $vistos = array_slice($vistos, 0, 500, true);
    centralone_cache_put('webhook_event_ids', $vistos);
}
