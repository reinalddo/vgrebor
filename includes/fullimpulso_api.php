<?php
// Integración con la API SMM de FullImpulso (fullimpulso.com/api/v2) — venta
// de seguidores/likes/comentarios de redes sociales. Reutiliza el sistema de
// "juegos -> paquetes" existente: cada paquete queda ligado a un servicio
// fijo de FullImpulso (service_id) y a una cantidad fija (cantidad), igual
// que Free Fire usa monto_ff. El cliente final solo pega su enlace.

require_once __DIR__ . '/store_config.php';

function fullimpulso_ensure_schema(mysqli $mysqli): void {
    $result = $mysqli->query("SHOW COLUMNS FROM juego_paquetes LIKE 'fullimpulso_service_id'");
    if (!($result instanceof mysqli_result) || $result->num_rows === 0) {
        $mysqli->query("ALTER TABLE juego_paquetes ADD COLUMN fullimpulso_service_id INT NULL AFTER api_source_key");
    }
    $result = $mysqli->query("SHOW COLUMNS FROM juego_paquetes LIKE 'fullimpulso_cantidad'");
    if (!($result instanceof mysqli_result) || $result->num_rows === 0) {
        $mysqli->query("ALTER TABLE juego_paquetes ADD COLUMN fullimpulso_cantidad INT NULL AFTER fullimpulso_service_id");
    }
}

/**
 * Asigna (o quita, con serviceId/cantidad <= 0) el servicio y la cantidad
 * fija de FullImpulso de un paquete. Se guarda con una UPDATE aparte (igual
 * que package_set_category()/levelpass_set_key()) para no tocar el INSERT
 * principal de admin/paquetes.php.
 */
function fullimpulso_set_package(mysqli $mysqli, int $packageId, int $serviceId, int $cantidad): bool {
    if ($packageId <= 0) {
        return false;
    }
    $serviceValue = $serviceId > 0 ? $serviceId : null;
    $cantidadValue = $cantidad > 0 ? $cantidad : null;
    $stmt = $mysqli->prepare('UPDATE juego_paquetes SET fullimpulso_service_id = ?, fullimpulso_cantidad = ? WHERE id = ?');
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('iii', $serviceValue, $cantidadValue, $packageId);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function fullimpulso_api_base_url(): string {
    return 'https://fullimpulso.com/api/v2';
}

function fullimpulso_api_key(): string {
    return trim((string) store_config_get('fullimpulso_api_key', ''));
}

function fullimpulso_is_configured(): bool {
    return fullimpulso_api_key() !== '';
}

function fullimpulso_api_http_post(array $payload, int $timeout = 30): array {
    $url = fullimpulso_api_base_url();
    $body = http_build_query($payload);
    $status = 0;
    $response = '';

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'User-Agent: TVirtualGaming/1.0'],
        ]);
        $result = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($result === false) {
            throw new RuntimeException('No se pudo conectar con la API de FullImpulso: ' . $error);
        }
        $response = (string) $result;
    } else {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/x-www-form-urlencoded\r\nAccept: application/json\r\nUser-Agent: TVirtualGaming/1.0",
                'content' => $body,
                'timeout' => $timeout,
                'ignore_errors' => true,
            ],
        ]);
        $result = @file_get_contents($url, false, $context);
        if ($result === false) {
            throw new RuntimeException('No se pudo conectar con la API de FullImpulso.');
        }
        $response = (string) $result;
        if (isset($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $headerLine) {
                if (preg_match('/^HTTP\/\S+\s+(\d+)/i', (string) $headerLine, $matches)) {
                    $status = (int) ($matches[1] ?? 0);
                }
            }
        }
    }

    $data = json_decode($response, true);
    if (!is_array($data)) {
        throw new RuntimeException('La API de FullImpulso no devolvió un JSON válido.');
    }

    return ['status' => $status, 'data' => $data];
}

/** Lista de servicios disponibles (id, name, category, rate por 1000, min, max). */
function fullimpulso_api_fetch_services(): array {
    if (!fullimpulso_is_configured()) {
        throw new RuntimeException('Falta configurar la API key de FullImpulso.');
    }
    $result = fullimpulso_api_http_post([
        'key' => fullimpulso_api_key(),
        'action' => 'services',
    ]);
    $services = $result['data'];
    if (!is_array($services) || (isset($services['error']))) {
        $message = trim((string) ($services['error'] ?? 'Respuesta inválida al listar servicios.'));
        throw new RuntimeException($message);
    }
    return array_values(array_filter($services, 'is_array'));
}

/** Crea la orden en FullImpulso: servicio + enlace + cantidad fija del paquete. */
function fullimpulso_api_create_order(int $serviceId, string $link, int $quantity): array {
    if (!fullimpulso_is_configured()) {
        throw new RuntimeException('Falta configurar la API key de FullImpulso.');
    }
    if ($serviceId <= 0) {
        throw new RuntimeException('El paquete seleccionado no tiene un servicio de FullImpulso configurado.');
    }
    $link = trim($link);
    if ($link === '') {
        throw new RuntimeException('El enlace es obligatorio para procesar este pedido.');
    }
    if ($quantity <= 0) {
        throw new RuntimeException('El paquete seleccionado no tiene una cantidad configurada.');
    }

    $result = fullimpulso_api_http_post([
        'key' => fullimpulso_api_key(),
        'action' => 'add',
        'service' => $serviceId,
        'link' => $link,
        'quantity' => $quantity,
    ]);
    $data = $result['data'];

    $orderId = trim((string) ($data['order'] ?? ''));
    if ($orderId === '') {
        $message = trim((string) ($data['error'] ?? 'FullImpulso no devolvió un ID de orden.'));
        return ['success' => false, 'message' => $message, 'order_id' => '', 'payload' => $data];
    }

    return ['success' => true, 'message' => 'Orden creada en FullImpulso.', 'order_id' => $orderId, 'payload' => $data];
}

/** Consulta el estado de una orden ya creada (action=status). */
function fullimpulso_api_fetch_order_status(string $orderId): array {
    if (!fullimpulso_is_configured()) {
        throw new RuntimeException('Falta configurar la API key de FullImpulso.');
    }
    $orderId = trim($orderId);
    if ($orderId === '') {
        throw new RuntimeException('ID de orden de FullImpulso inválido.');
    }

    $result = fullimpulso_api_http_post([
        'key' => fullimpulso_api_key(),
        'action' => 'status',
        'order' => $orderId,
    ]);
    $data = $result['data'];
    if (isset($data['error'])) {
        throw new RuntimeException(trim((string) $data['error']));
    }

    return [
        'status' => trim((string) ($data['status'] ?? '')),
        'charge' => trim((string) ($data['charge'] ?? '')),
        'start_count' => trim((string) ($data['start_count'] ?? '')),
        'remains' => trim((string) ($data['remains'] ?? '')),
        'currency' => trim((string) ($data['currency'] ?? '')),
        'payload' => $data,
    ];
}

/** Solicita la reposición (refill) de una orden ya entregada, si el servicio la incluye. */
function fullimpulso_api_request_refill(string $orderId): array {
    if (!fullimpulso_is_configured()) {
        throw new RuntimeException('Falta configurar la API key de FullImpulso.');
    }
    $orderId = trim($orderId);
    if ($orderId === '') {
        throw new RuntimeException('ID de orden de FullImpulso inválido.');
    }

    $result = fullimpulso_api_http_post([
        'key' => fullimpulso_api_key(),
        'action' => 'refill',
        'order' => $orderId,
    ]);
    $data = $result['data'];
    if (isset($data['error'])) {
        throw new RuntimeException(trim((string) $data['error']));
    }

    return [
        'refill_id' => trim((string) ($data['refill'] ?? '')),
        'payload' => $data,
    ];
}

/** Consulta el estado de una reposición ya solicitada (action=refill_status). */
function fullimpulso_api_fetch_refill_status(string $refillId): array {
    if (!fullimpulso_is_configured()) {
        throw new RuntimeException('Falta configurar la API key de FullImpulso.');
    }
    $refillId = trim($refillId);
    if ($refillId === '') {
        throw new RuntimeException('ID de reposición de FullImpulso inválido.');
    }

    $result = fullimpulso_api_http_post([
        'key' => fullimpulso_api_key(),
        'action' => 'refill_status',
        'refill' => $refillId,
    ]);
    $data = $result['data'];
    if (isset($data['error'])) {
        throw new RuntimeException(trim((string) $data['error']));
    }

    return [
        'status' => trim((string) ($data['status'] ?? '')),
        'payload' => $data,
    ];
}

/** Cancela una orden (action=cancel, siempre vía el endpoint por lote de la API, con un solo ID). */
function fullimpulso_api_request_cancel(string $orderId): array {
    if (!fullimpulso_is_configured()) {
        throw new RuntimeException('Falta configurar la API key de FullImpulso.');
    }
    $orderId = trim($orderId);
    if ($orderId === '') {
        throw new RuntimeException('ID de orden de FullImpulso inválido.');
    }

    $result = fullimpulso_api_http_post([
        'key' => fullimpulso_api_key(),
        'action' => 'cancel',
        'orders' => $orderId,
    ]);
    $data = $result['data'];

    // La API devuelve una lista (incluso para un solo id): [{"order":X,"cancel":{...}}]
    $entry = is_array($data) && isset($data[0]) && is_array($data[0]) ? $data[0] : null;
    $cancelResult = is_array($entry) ? ($entry['cancel'] ?? null) : null;

    if (is_array($cancelResult) && isset($cancelResult['error'])) {
        throw new RuntimeException(trim((string) $cancelResult['error']));
    }

    $cancelled = is_scalar($cancelResult) ? (string) $cancelResult : '';

    return [
        'success' => $cancelled !== '' || (is_array($cancelResult) && empty($cancelResult['error'])),
        'payload' => $data,
    ];
}
