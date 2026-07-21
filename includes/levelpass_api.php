<?php
// Verificación de disponibilidad de Pase de Nivel (Free Fire).
// Consulta una API externa que, dado un ID de jugador YA VERIFICADO (nombre
// confirmado), devuelve qué niveles de pase están disponibles para comprar.
// A diferencia de BS Pass Stock, aquí el comportamiento es fail-CLOSED: los
// paquetes de pase de nivel nacen ocultos y solo se muestran cuando la API
// confirma explícitamente `available: true` para ese nivel y ese jugador.
// Esto es intencional: evita mostrar/vender un nivel que la API no confirmó,
// y evita disparar la consulta para IDs sin cuenta real (ver player_verification.php).

require_once __DIR__ . '/store_config.php';

function levelpass_ensure_schema(mysqli $mysqli): void {
    $result = $mysqli->query("SHOW COLUMNS FROM juego_paquetes LIKE 'levelpass_key'");
    if (!($result instanceof mysqli_result) || $result->num_rows === 0) {
        $mysqli->query("ALTER TABLE juego_paquetes ADD COLUMN levelpass_key VARCHAR(20) NULL AFTER api_source_key");
    }
}

/** Claves válidas de nivel de pase, en el orden en que se ofrecen. */
function levelpass_key_options(): array {
    return [
        'levelpass6'  => 'Pase de Nivel (Nivel 6)',
        'levelpass10' => 'Pase de Nivel (Nivel 10)',
        'levelpass15' => 'Pase de Nivel (Nivel 15)',
        'levelpass20' => 'Pase de Nivel (Nivel 20)',
        'levelpass25' => 'Pase de Nivel (Nivel 25)',
        'levelpass30' => 'Pase de Nivel (Nivel 30)',
    ];
}

function levelpass_key_label(string $key): string {
    return levelpass_key_options()[$key] ?? $key;
}

function levelpass_normalize_key($value): string {
    $key = trim((string) $value);
    return isset(levelpass_key_options()[$key]) ? $key : '';
}

function levelpass_set_key(mysqli $mysqli, int $packageId, string $key): bool {
    if ($packageId <= 0) {
        return false;
    }
    $normalized = levelpass_normalize_key($key);
    $stmt = $mysqli->prepare("UPDATE juego_paquetes SET levelpass_key = NULLIF(?, '') WHERE id = ?");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('si', $normalized, $packageId);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function levelpass_api_token(): string {
    return trim((string) store_config_get('levelpass_api_token', 'psn-tiendagiftven'));
}

function levelpass_api_url(): string {
    $url = trim((string) store_config_get('levelpass_api_url', ''));
    return $url !== '' ? $url : 'http://2.24.197.52/api/freefire-bo/levelpass-check';
}

function levelpass_is_configured(): bool {
    return levelpass_api_token() !== '';
}

function levelpass_http_get(string $url, int $timeout = 30): array {
    $body = '';
    $status = 0;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => ['Accept: application/json,text/plain,*/*', 'User-Agent: TVirtualGaming/1.0'],
        ]);
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($response === false) {
            throw new RuntimeException('No se pudo consultar el validador de pase de nivel: ' . $error);
        }
        $body = (string) $response;
    } else {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => $timeout,
                'ignore_errors' => true,
                'header' => "Accept: application/json,text/plain,*/*\r\nUser-Agent: TVirtualGaming/1.0",
            ],
        ]);
        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            throw new RuntimeException('No se pudo consultar el validador de pase de nivel.');
        }
        $body = (string) $response;
        if (isset($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $headerLine) {
                if (preg_match('/^HTTP\/\S+\s+(\d+)/i', (string) $headerLine, $matches)) {
                    $status = (int) ($matches[1] ?? 0);
                }
            }
        }
    }

    return ['status' => $status, 'body' => $body];
}

/**
 * Paquetes locales activos del juego que tienen una clave de pase de nivel
 * asignada (configurada manualmente en el admin, ver levelpass_set_key()).
 * Devuelve id local => clave (levelpassN).
 */
function levelpass_game_packages(mysqli $mysqli, int $gameId): array {
    if ($gameId <= 0) {
        return [];
    }

    $stmt = $mysqli->prepare("SELECT id, levelpass_key FROM juego_paquetes WHERE juego_id = ? AND COALESCE(activo, 1) = 1 AND levelpass_key IS NOT NULL AND levelpass_key <> ''");
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param('i', $gameId);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();

    $map = [];
    foreach ($rows as $row) {
        $key = levelpass_normalize_key($row['levelpass_key'] ?? '');
        if ($key !== '') {
            $map[(int) $row['id']] = $key;
        }
    }
    return $map;
}

/**
 * Consulta principal. SOLO debe llamarse después de que el nombre del
 * jugador ya fue verificado con éxito (ver game.php: se dispara dentro del
 * bloque de éxito de verifyCurrentPlayer, nunca en paralelo, para no saturar
 * la IP del proveedor con IDs falsos/sin cuenta).
 *
 * Devuelve:
 *  - ['ok'=>true,'applicable'=>false] si el juego no tiene paquetes de pase de nivel.
 *  - ['ok'=>false,'reason'=>...] si el validador falló (el frontend NO revela nada: fail-closed).
 *  - ['ok'=>true,'applicable'=>true,'available'=>[ids locales disponibles]].
 */
function levelpass_check(mysqli $mysqli, int $gameId, string $playerId, bool $debug = false): array {
    $passPackages = levelpass_game_packages($mysqli, $gameId);
    if ($passPackages === []) {
        return ['ok' => true, 'applicable' => false];
    }

    // Caché corto por jugador (60s) para no repetir la consulta si el mismo
    // ID se re-verifica enseguida.
    $cacheKey = $gameId . '_' . $playerId;
    if (!$debug && isset($_SESSION) && is_array($_SESSION['levelpass_check_cache'][$cacheKey] ?? null)) {
        $cached = $_SESSION['levelpass_check_cache'][$cacheKey];
        if ((time() - (int) ($cached['t'] ?? 0)) < 60 && is_array($cached['result'] ?? null)) {
            return $cached['result'];
        }
    }

    $url = levelpass_api_url()
        . '?playerId=' . rawurlencode($playerId)
        . '&token=' . rawurlencode(levelpass_api_token());

    $sessionWasActive = session_status() === PHP_SESSION_ACTIVE;
    if ($sessionWasActive) {
        session_write_close();
    }

    try {
        $response = levelpass_http_get($url, 30);
    } catch (Throwable $e) {
        return ['ok' => false, 'reason' => 'unavailable'];
    } finally {
        if ($sessionWasActive && session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
    }

    $data = json_decode((string) $response['body'], true);
    if (!is_array($data) || !is_array($data['levelPasses'] ?? null)) {
        return ['ok' => false, 'reason' => 'invalid_response'];
    }

    $levelPasses = $data['levelPasses'];
    $available = [];
    foreach ($passPackages as $localId => $key) {
        $entry = $levelPasses[$key] ?? null;
        if (is_array($entry) && !empty($entry['available'])) {
            $available[] = $localId;
        }
    }

    $result = [
        'ok' => true,
        'applicable' => true,
        'available' => $available,
    ];

    if ($debug) {
        $result['raw'] = $data;
        return $result;
    }

    if (isset($_SESSION)) {
        if (!is_array($_SESSION['levelpass_check_cache'] ?? null)) {
            $_SESSION['levelpass_check_cache'] = [];
        }
        $_SESSION['levelpass_check_cache'][$cacheKey] = ['t' => time(), 'result' => $result];
        while (count($_SESSION['levelpass_check_cache']) > 8) {
            array_shift($_SESSION['levelpass_check_cache']);
        }
    }

    return $result;
}
