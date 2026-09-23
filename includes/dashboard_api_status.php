<?php
// Tarjetas de estado de las APIs para el dashboard del admin (BNC, Binance, CONEC,
// TiendaGiftVen, FullImpulso). RecargasAmérica tiene su propia tarjeta en admin.php.
//
// Reglas:
//  · APIs con DÍAS de suscripción (BNC y Binance): verde si quedan MÁS de 2 días, rojo si
//    quedan 2 o menos (incluido 0).
//  · APIs con SALDO (CONEC, TiendaGiftVen, FullImpulso): rojo si el saldo es menor a
//    DASH_GADGET_MIN_BALANCE_USD (mismo criterio que la tarjeta de RecargasAmérica).
//  · Si no se puede consultar: rojo, con el motivo y el último dato conocido.
//
// Diseño para no dañar nada (lección del bloqueo de RecargasAmérica: repetir consultas fallidas
// puede hacer que un proveedor bloquee la IP del servidor):
//  · Cada consulta se guarda en un archivo temporal (10 min si salió bien, 5 min si falló), así
//    abrir el dashboard muchas veces NO repite las llamadas a los proveedores.
//  · El botón "Actualizar" fuerza una consulta nueva, pero no más de una cada 30 s por tarjeta.
//  · Todo es de SOLO LECTURA. Los días de BNC/Binance se guardan también en la configuración
//    (mismas claves que ya usa el resto del sistema).
//  · La caché va separada por tenant y por credenciales: al cambiar una API KEY/token se
//    consulta de nuevo sola.

require_once __DIR__ . '/store_config.php';

if (!defined('DASH_GADGET_MIN_DAYS')) {
    define('DASH_GADGET_MIN_DAYS', 2);            // rojo si quedan 2 días o menos
}
if (!defined('DASH_GADGET_MIN_BALANCE_USD')) {
    define('DASH_GADGET_MIN_BALANCE_USD', 10.0);  // rojo si el saldo es menor a esto
}
if (!defined('DASH_GADGET_TTL_OK')) {
    define('DASH_GADGET_TTL_OK', 600);
}
if (!defined('DASH_GADGET_TTL_FAIL')) {
    define('DASH_GADGET_TTL_FAIL', 300);
}
if (!defined('DASH_GADGET_FORCE_MIN_AGE')) {
    define('DASH_GADGET_FORCE_MIN_AGE', 30);
}

function dash_gadget_titles(): array {
    return [
        'bnc' => 'Días BNC (verificación)',
        'binance' => 'Días Binance (verificación)',
        'conec' => 'Saldo CONEC',
        'giftven' => 'Saldo TiendaGiftVen',
        'fullimpulso' => 'Saldo FullImpulso',
    ];
}

// ── Caché en archivo ────────────────────────────────────────────────────────

function dash_gadget_cache_path(string $key, string $fingerprint): string {
    $tenant = '';
    if (function_exists('tenant_database_config')) {
        $tenant = (string) ((tenant_database_config())['name'] ?? '');
    }

    return rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'tvg_dash_' . preg_replace('/[^a-z0-9_]/i', '', $key)
        . '_' . substr(sha1($tenant . '|' . $key . '|' . $fingerprint), 0, 16) . '.json';
}

function dash_gadget_cache_read(string $path): ?array {
    if (!is_file($path)) {
        return null;
    }
    $decoded = json_decode((string) @file_get_contents($path), true);
    return is_array($decoded) && isset($decoded['fetched_at']) ? $decoded : null;
}

/**
 * Devuelve el resultado (de la caché o de una consulta nueva) para una tarjeta.
 * $fetch debe devolver ['kind' => 'days'|'balance', 'value' => número, ...extras] o lanzar excepción.
 */
function dash_gadget_resolve(string $key, string $fingerprint, callable $fetch, bool $force = false, ?int $now = null): array {
    $now = $now ?? time();
    $path = dash_gadget_cache_path($key, $fingerprint);
    $cached = dash_gadget_cache_read($path);

    if ($cached !== null) {
        $age = $now - (int) $cached['fetched_at'];
        $ttl = !empty($cached['ok']) ? DASH_GADGET_TTL_OK : DASH_GADGET_TTL_FAIL;
        $mayRefresh = $force ? $age >= DASH_GADGET_FORCE_MIN_AGE : $age >= $ttl;
        if (!$mayRefresh) {
            $cached['cached'] = true;
            return $cached;
        }
    }

    try {
        $entry = array_merge($fetch(), ['ok' => true, 'fetched_at' => $now]);
    } catch (Throwable $e) {
        // Se conserva el último dato bueno (solo lo mínimo) para poder mostrarlo junto al error.
        $lastOk = null;
        if (is_array($cached)) {
            $source = !empty($cached['ok']) ? $cached : (is_array($cached['last_ok'] ?? null) ? $cached['last_ok'] : null);
            if (is_array($source) && isset($source['kind'], $source['value'])) {
                $lastOk = [
                    'kind' => $source['kind'],
                    'value' => $source['value'],
                    'currency' => $source['currency'] ?? null,
                    'fetched_at' => (int) ($source['fetched_at'] ?? 0),
                ];
            }
        }
        $message = trim(preg_replace('/\s+/', ' ', $e->getMessage()) ?? '');
        $entry = [
            'ok' => false,
            'error' => $message !== '' ? mb_substr($message, 0, 160, 'UTF-8') : 'Error desconocido.',
            'fetched_at' => $now,
            'last_ok' => $lastOk,
        ];
    }

    @file_put_contents($path, json_encode($entry), LOCK_EX);
    $entry['cached'] = false;
    return $entry;
}

// ── HTTP de solo lectura ────────────────────────────────────────────────────

/** GET que devuelve JSON. Reintenta sin validar SSL solo si el fallo es del certificado. */
function dash_http_get_json(string $url, int $timeout = 12): array {
    if (!function_exists('curl_init')) {
        throw new RuntimeException('La extensión cURL de PHP no está disponible.');
    }

    $verify = true;
    for ($attempt = 0; $attempt < 2; $attempt++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => min(8, $timeout),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_USERAGENT => 'Reborxstore/1.0 (+https://reborxstore.com)',
            CURLOPT_SSL_VERIFYPEER => $verify,
            CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            if ($attempt === 0 && (stripos($error, 'SSL certificate problem') !== false || stripos($error, 'unable to get local issuer certificate') !== false)) {
                $verify = false;
                continue;
            }
            throw new RuntimeException('No se pudo conectar: ' . $error);
        }

        $data = json_decode((string) $body, true);
        if (!is_array($data)) {
            throw new RuntimeException('La API no devolvió un JSON válido (HTTP ' . $status . ').');
        }

        return ['status' => $status, 'data' => $data];
    }

    throw new RuntimeException('No se pudo conectar.');
}

// ── Consultas por API (todas de solo lectura) ───────────────────────────────

function dash_bank_credentials(): array {
    return [
        'base' => (string) store_config_get('ff_bank_api_base_url', 'https://pagonorte.net'),
        'posicion' => trim((string) store_config_get('ff_bank_posicion', '')),
        'token' => trim((string) store_config_get('ff_bank_token', '')),
        'clave' => trim((string) store_config_get('ff_bank_clave', '')),
    ];
}

function dash_bank_is_configured(): bool {
    $c = dash_bank_credentials();
    return $c['posicion'] !== '' && $c['token'] !== '' && $c['clave'] !== '';
}

// La API informa `dias_disponibles` en la misma respuesta de movimientos, incluso cuando ya
// están en 0 (por eso se lee ese campo aunque no vengan movimientos). No se sincronizan
// movimientos desde aquí.
function dash_fetch_bank_days(): array {
    $c = dash_bank_credentials();
    if (!dash_bank_is_configured()) {
        throw new RuntimeException('La conexión bancaria no está configurada completamente.');
    }

    $url = store_config_build_bank_movements_url($c['base'], [
        'posicion' => $c['posicion'],
        'token' => $c['token'],
        'password' => $c['clave'],
    ]);
    $result = dash_http_get_json($url);
    $data = $result['data'];

    if (!isset($data['dias_disponibles']) || !is_numeric($data['dias_disponibles'])) {
        $detail = trim((string) ($data['error'] ?? $data['mensaje'] ?? $data['message'] ?? ''));
        throw new RuntimeException($detail !== '' ? $detail : 'La API bancaria no informó los días disponibles (HTTP ' . (int) $result['status'] . ').');
    }

    $days = max(0, (int) $data['dias_disponibles']);
    store_config_upsert('ff_bank_dias_disponibles', (string) $days);

    return ['kind' => 'days', 'value' => $days];
}

function dash_binance_token(): string {
    return trim((string) store_config_get('binance_pagonorte_token', ''));
}

function dash_binance_is_configured(): bool {
    return trim((string) store_config_get('api_binance_pagonorte', '0')) === '1' && dash_binance_token() !== '';
}

function dash_fetch_binance_days(): array {
    $token = dash_binance_token();
    if ($token === '') {
        throw new RuntimeException('La conexión automática para Binance no está configurada.');
    }

    $result = dash_http_get_json(store_config_build_binance_pagonorte_movements_url($token));
    $data = $result['data'];

    if (!isset($data['dias_disponibles']) || !is_numeric($data['dias_disponibles'])) {
        $detail = trim((string) ($data['error'] ?? $data['mensaje'] ?? $data['message'] ?? ''));
        throw new RuntimeException($detail !== '' ? $detail : 'La API de Binance no informó los días disponibles (HTTP ' . (int) $result['status'] . ').');
    }

    $days = max(0, (int) $data['dias_disponibles']);
    $cutoff = trim((string) ($data['fecha_corte'] ?? ''));
    store_config_upsert('binance_pagonorte_dias_disponibles', (string) $days);
    store_config_upsert('binance_pagonorte_fecha_corte', $cutoff);

    return ['kind' => 'days', 'value' => $days, 'cutoff' => $cutoff];
}

/** PDO propio al mismo tenant (CONEC usa PDO; la tienda usa mysqli). null si no se pudo crear. */
function dash_conec_pdo(): ?PDO {
    static $pdo = false;
    if ($pdo !== false) {
        return $pdo;
    }

    $pdo = null;
    try {
        $tdb = function_exists('tenant_database_config') ? tenant_database_config() : [];
        $pdo = new PDO(
            'mysql:host=' . ($tdb['host'] ?? 'localhost') . ';dbname=' . ($tdb['name'] ?? '') . ';charset=' . ($tdb['charset'] ?? 'utf8mb4'),
            (string) ($tdb['user'] ?? 'root'),
            (string) ($tdb['password'] ?? ''),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT]
        );
    } catch (Throwable $e) {
        $pdo = null;
    }

    return $pdo;
}

function dash_conec_is_configured(): bool {
    if (!function_exists('conec_enabled')) {
        require_once __DIR__ . '/conec_recargas.php';
    }
    $pdo = dash_conec_pdo();
    return $pdo !== null && conec_enabled($pdo);
}

function dash_fetch_conec_balance(): array {
    if (!function_exists('conec_balance')) {
        require_once __DIR__ . '/conec_recargas.php';
    }
    $pdo = dash_conec_pdo();
    if ($pdo === null) {
        throw new RuntimeException('No se pudo abrir la base de datos para consultar CONEC.');
    }
    if (!conec_enabled($pdo)) {
        throw new RuntimeException('CONEC no está activo en esta tienda.');
    }

    $r = conec_balance($pdo);
    if (empty($r['ok'])) {
        throw new RuntimeException(trim((string) ($r['error'] ?? '')) ?: 'No se pudo consultar el saldo de CONEC.');
    }
    if (!isset($r['balance']) || $r['balance'] === null) {
        throw new RuntimeException('CONEC no devolvió el saldo.');
    }

    return ['kind' => 'balance', 'value' => (float) $r['balance'], 'currency' => (string) (($r['currency'] ?? '') ?: 'USD')];
}

function dash_fetch_giftven_balance(): array {
    if (!function_exists('recargas_api_fetch_balance')) {
        require_once __DIR__ . '/recargas_api.php';
    }
    $r = recargas_api_fetch_balance(12);
    return ['kind' => 'balance', 'value' => (float) $r['saldo'], 'currency' => 'USD'];
}

function dash_fetch_fullimpulso_balance(): array {
    if (!function_exists('fullimpulso_api_fetch_balance')) {
        require_once __DIR__ . '/fullimpulso_api.php';
    }
    $r = fullimpulso_api_fetch_balance(12);
    return ['kind' => 'balance', 'value' => (float) $r['balance'], 'currency' => (string) $r['currency']];
}

// ── Qué tarjetas mostrar y cómo se ven ──────────────────────────────────────

/** Tarjetas que aplican a esta tienda (solo las APIs que están configuradas), en orden. */
function dash_api_gadgets_list(): array {
    if (!function_exists('recargas_api_is_configured')) {
        require_once __DIR__ . '/recargas_api.php';
    }
    if (!function_exists('fullimpulso_is_configured')) {
        require_once __DIR__ . '/fullimpulso_api.php';
    }

    $titles = dash_gadget_titles();
    $available = [
        'bnc' => dash_bank_is_configured(),
        'binance' => dash_binance_is_configured(),
        'conec' => dash_conec_is_configured(),
        'giftven' => recargas_api_is_configured(),
        'fullimpulso' => fullimpulso_is_configured(),
    ];

    $list = [];
    foreach ($titles as $key => $title) {
        if (!empty($available[$key])) {
            $list[] = ['key' => $key, 'title' => $title];
        }
    }

    return $list;
}

/** Identificador de las credenciales de cada API: si cambian, la caché deja de servir. */
function dash_gadget_fingerprint(string $key): string {
    switch ($key) {
        case 'bnc':
            $c = dash_bank_credentials();
            return implode('|', [$c['base'], $c['posicion'], $c['token'], $c['clave']]);
        case 'binance':
            return dash_binance_token();
        case 'giftven':
            return (string) store_config_get('recargas_api_key', '');
        case 'fullimpulso':
            return (string) store_config_get('fullimpulso_api_key', '');
        case 'conec':
            return (string) store_config_get('conec_api_key', '') . '|' . (string) store_config_get('conec_base_url', '');
    }

    return '';
}

function dash_gadget_fetcher(string $key): ?callable {
    $map = [
        'bnc' => 'dash_fetch_bank_days',
        'binance' => 'dash_fetch_binance_days',
        'conec' => 'dash_fetch_conec_balance',
        'giftven' => 'dash_fetch_giftven_balance',
        'fullimpulso' => 'dash_fetch_fullimpulso_balance',
    ];

    return isset($map[$key]) ? $map[$key] : null;
}

function dash_format_days(int $days): string {
    return $days . ($days === 1 ? ' día' : ' días');
}

function dash_format_money(string $currency, float $amount): string {
    return trim($currency) . ' ' . number_format($amount, 2, '.', ',');
}

/**
 * Convierte el resultado de una consulta en lo que se pinta:
 * ['status' => 'ok'|'low'|'error', 'main', 'sub', 'age' => segundos].
 * $now se puede fijar para pruebas.
 */
function dash_gadget_present(string $key, array $entry, ?int $now = null): array {
    $now = $now ?? time();
    $titles = dash_gadget_titles();
    $out = [
        'key' => $key,
        'title' => $titles[$key] ?? $key,
        'age' => max(0, $now - (int) ($entry['fetched_at'] ?? $now)),
        'cached' => !empty($entry['cached']),
    ];

    if (empty($entry['ok'])) {
        $sub = (string) ($entry['error'] ?? '');
        $last = $entry['last_ok'] ?? null;
        if (is_array($last) && isset($last['kind'], $last['value'])) {
            $lastText = $last['kind'] === 'days'
                ? dash_format_days((int) $last['value'])
                : dash_format_money((string) ($last['currency'] ?? 'USD'), (float) $last['value']);
            $lastAge = max(0, $now - (int) ($last['fetched_at'] ?? $now));
            $sub .= ($sub !== '' ? ' · ' : '') . 'Último dato conocido: ' . $lastText . ' (hace ' . dash_format_age($lastAge) . ')';
        }

        return $out + ['status' => 'error', 'main' => '⚠ No se pudo consultar', 'sub' => $sub];
    }

    if (($entry['kind'] ?? '') === 'days') {
        $days = (int) $entry['value'];
        $low = $days <= DASH_GADGET_MIN_DAYS;
        $sub = '';
        if ($low) {
            $sub = $days <= 0 ? '⚠ Suscripción vencida' : '⚠ Se está acabando';
        }
        $cutoff = trim((string) ($entry['cutoff'] ?? ''));
        if ($cutoff !== '') {
            $sub .= ($sub !== '' ? ' · ' : '') . 'Corte: ' . $cutoff;
        }

        return $out + ['status' => $low ? 'low' : 'ok', 'main' => dash_format_days($days), 'sub' => $sub];
    }

    $amount = (float) $entry['value'];
    $low = $amount < DASH_GADGET_MIN_BALANCE_USD;

    return $out + [
        'status' => $low ? 'low' : 'ok',
        'main' => dash_format_money((string) ($entry['currency'] ?? 'USD'), $amount),
        'sub' => $low ? '⚠ Saldo bajo' : '',
    ];
}

function dash_format_age(int $seconds): string {
    if ($seconds < 60) {
        return $seconds . ' s';
    }
    if ($seconds < 3600) {
        return (int) floor($seconds / 60) . ' min';
    }
    if ($seconds < 86400) {
        return (int) floor($seconds / 3600) . ' h';
    }

    return (int) floor($seconds / 86400) . ' d';
}

/** Punto de entrada: consulta (o toma de la caché) una tarjeta y devuelve lo que se pinta. */
function dash_api_gadget_status(string $key, bool $force = false): array {
    $fetcher = dash_gadget_fetcher($key);
    if ($fetcher === null) {
        throw new InvalidArgumentException('Tarjeta desconocida.');
    }

    $entry = dash_gadget_resolve($key, dash_gadget_fingerprint($key), $fetcher, $force);
    return dash_gadget_present($key, $entry);
}
