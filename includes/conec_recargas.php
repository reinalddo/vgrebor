<?php
/**
 * includes/conec_recargas.php — Cliente PHP del proveedor MAYORISTA CONEC (coneclatam.com/api/reseller/v1).
 *
 * Espejo funcional de services/conec_service.py de "Recargas Escanor". Autocontenido: NO toca api/pedidos.php.
 * Se usa para: ver saldo, ver catálogo (con TU precio), y despachar recargas por la API (idempotente por
 * merchant_ref). Lo consume admin/stream/conec.php (UI) y, en el futuro, el despacho de recargas.php.
 *
 * CONFIG (nunca se hardcodea la llave aquí): tabla configuracion_general (fallback configuracion), claves:
 *   - conec_api_key   → rk_test_... (sandbox, no mueve dinero) | rk_... (real). El prefijo test = modo pruebas.
 *   - conec_base_url  → por defecto https://coneclatam.com/api/reseller/v1
 *   - conec_enabled   → '1' para habilitar
 * En pruebas se puede pasar por variable de entorno: CONEC_API_KEY / CONEC_BASE_URL.
 *
 * ⚠️ coneclatam está detrás de Cloudflare: SIN un User-Agent propio devuelve 403/1010. Por eso se manda uno.
 * ⚠️ El sandbox NO responde igual que la documentación → conec_normalize() acepta las DOS formas.
 */

if (!defined('CONEC_BASE_DEFAULT')) {
    define('CONEC_BASE_DEFAULT', 'https://coneclatam.com/api/reseller/v1');
}
if (!defined('CONEC_TIMEOUT')) {
    define('CONEC_TIMEOUT', 45);
}

// Estados que puede devolver, en las dos convenciones (doc y real).
if (!function_exists('conec_estados')) {
    function conec_estados(): array {
        return [
            'entregado' => ['delivered', 'completed', 'complete', 'exitosa', 'exitoso', 'success', 'entregado'],
            'en_curso'  => ['processing', 'pending', 'en_proceso', 'procesando', 'en_curso'],
            'fallido'   => ['failed', 'error', 'fallida', 'cancelled', 'canceled', 'rechazada'],
        ];
    }
}

if (!function_exists('conec_cfg')) {
    function conec_cfg(PDO $pdo, string $key, string $default = ''): string {
        // 1) override por entorno (solo para pruebas locales)
        $env = getenv(strtoupper($key));
        if ($env !== false && trim((string) $env) !== '') {
            return trim((string) $env);
        }
        // 2) configuracion_general, luego configuracion (ambas clave/valor)
        foreach (['configuracion_general', 'configuracion'] as $tabla) {
            try {
                $q = $pdo->prepare("SELECT valor FROM $tabla WHERE clave = ? LIMIT 1");
                $q->execute([$key]);
                $v = $q->fetchColumn();
                if ($v !== false && $v !== null && trim((string) $v) !== '') {
                    return trim((string) $v);
                }
            } catch (Throwable $e) { /* tabla puede no existir */ }
        }
        return $default;
    }
}

if (!function_exists('conec_config')) {
    function conec_config(PDO $pdo): array {
        $key  = conec_cfg($pdo, 'conec_api_key', '');
        $base = conec_cfg($pdo, 'conec_base_url', CONEC_BASE_DEFAULT);
        $base = rtrim(trim($base !== '' ? $base : CONEC_BASE_DEFAULT), '/');
        return [
            'api_key' => $key,
            'base'    => $base,
            'enabled' => conec_cfg($pdo, 'conec_enabled', '0') === '1',
            'sandbox' => stripos($key, 'rk_test_') === 0,   // la llave de pruebas empieza por rk_test_
        ];
    }
}

if (!function_exists('conec_enabled')) {
    function conec_enabled(PDO $pdo): bool {
        $c = conec_config($pdo);
        return $c['enabled'] && $c['api_key'] !== '';
    }
}

if (!function_exists('conec_merchant_ref')) {
    /** Ref propia y estable por pedido (la API rechaza <6 chars: invalid_merchant_ref). */
    function conec_merchant_ref($orderId): string {
        return sprintf('RBX_%06d', (int) $orderId);
    }
}

if (!function_exists('conec_request')) {
    /**
     * Llama a la API. Devuelve ['ok'=>bool, 'data'=>array|null, 'error'=>string|null, 'code'=>int]. Nunca lanza.
     */
    function conec_request(PDO $pdo, string $method, string $path, ?array $payload = null, ?array $params = null): array {
        $cfg = conec_config($pdo);
        if ($cfg['api_key'] === '') {
            return ['ok' => false, 'data' => null, 'error' => 'Falta la API key de CONEC en la configuración.', 'code' => 0];
        }
        $url = $cfg['base'] . $path;
        if ($params) { $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($params); }

        $ch = curl_init($url);
        $headers = [
            'X-Api-Key: ' . $cfg['api_key'],
            'Content-Type: application/json',
            'Accept: application/json',
        ];
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            // Cloudflare bloquea (403/1010) a los agentes que no reconoce → mandar uno propio e identificable.
            CURLOPT_USERAGENT      => 'Reborxstore/1.0 (+https://reborxstore.com)',
            CURLOPT_TIMEOUT        => CONEC_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload ?: [], JSON_UNESCAPED_UNICODE));
        }
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            return ['ok' => false, 'data' => null, 'error' => 'Error de conexión con CONEC: ' . $cerr, 'code' => $code];
        }
        $d = json_decode((string) $body, true);
        if (!is_array($d)) {
            return ['ok' => false, 'data' => null, 'error' => 'CONEC respondió algo que no es JSON (HTTP ' . $code . ').', 'code' => $code];
        }
        if (!empty($d['ok'])) {
            return ['ok' => true, 'data' => $d, 'error' => null, 'code' => $code];
        }
        $errCode = (string) ($d['error'] ?? 'error');
        $errMsg  = trim($errCode . (isset($d['message']) ? ': ' . $d['message'] : ''));
        return ['ok' => false, 'data' => $d, 'error' => $errMsg, 'code' => $code];
    }
}

if (!function_exists('conec_balance')) {
    /** ['balance'=>float, 'currency'=>str, 'reseller'=>str, 'mode'=>str] o null + error. */
    function conec_balance(PDO $pdo): array {
        $r = conec_request($pdo, 'GET', '/balance.php');
        if (!$r['ok']) { return ['ok' => false, 'error' => $r['error'], 'code' => $r['code']]; }
        $d = $r['data'];
        return [
            'ok'       => true,
            'balance'  => isset($d['balance']) ? (float) $d['balance'] : null,
            'currency' => (string) ($d['currency'] ?? 'USD'),
            'reseller' => (string) ($d['reseller'] ?? ''),
            'mode'     => (string) ($d['mode'] ?? ''),
        ];
    }
}

if (!function_exists('conec_catalog')) {
    /** Devuelve ['ok'=>bool, 'games'=>array] (cada juego: product_id, name, category, requires_game_id, variants[]). */
    function conec_catalog(PDO $pdo): array {
        $r = conec_request($pdo, 'GET', '/catalog.php');
        if (!$r['ok']) { return ['ok' => false, 'error' => $r['error'], 'games' => []]; }
        $d = $r['data'];
        $games = $d['games'] ?? ($d['catalog'] ?? []);
        return ['ok' => true, 'games' => is_array($games) ? $games : [], 'mode' => (string) ($d['mode'] ?? '')];
    }
}

if (!function_exists('conec_classify')) {
    function conec_classify(?string $estado): string {
        $e = strtolower(trim((string) $estado));
        foreach (conec_estados() as $clase => $lista) {
            if (in_array($e, $lista, true)) { return $clase; }
        }
        return 'desconocido';
    }
}

if (!function_exists('conec_normalize')) {
    /** Normaliza la respuesta de /recharge o /order (forma PLANA del sandbox y ANIDADA bajo "order" de la doc). */
    function conec_normalize(?array $d): array {
        if (!is_array($d)) {
            return ['estado' => 'desconocido', 'clase' => 'desconocido', 'codigos' => [], 'confirmacion' => '',
                    'order_id' => null, 'merchant_ref' => null, 'duplicado' => false, 'modo' => '', 'error' => null];
        }
        $o = (isset($d['order']) && is_array($d['order'])) ? $d['order'] : $d;

        $estado = strtolower(trim((string) ($o['status'] ?? $d['status'] ?? '')));

        $codigos = [];
        foreach (($o['codes'] ?? $d['codes'] ?? []) as $c) { if ($c) { $codigos[] = (string) $c; } }
        $confirmacion = '';
        foreach (($o['items'] ?? $d['items'] ?? []) as $it) {
            if (!is_array($it)) { continue; }
            if (!empty($it['code'])) { $codigos[] = (string) $it['code']; }
            if ($confirmacion === '' && !empty($it['confirmation'])) { $confirmacion = (string) $it['confirmation']; }
            if ($estado === '' && !empty($it['status'])) { $estado = strtolower(trim((string) $it['status'])); }
        }
        // confirmación (nick del jugador) también puede venir plana
        if ($confirmacion === '' && !empty($d['confirmation'])) { $confirmacion = (string) $d['confirmation']; }

        $error = null;
        foreach (($o['items'] ?? $d['items'] ?? []) as $it) {
            if (is_array($it) && !empty($it['error'])) { $error = (string) $it['error']; break; }
        }
        if ($error === null && !empty($d['error'])) { $error = (string) $d['error']; }

        return [
            'estado'       => $estado,
            'clase'        => conec_classify($estado),
            'codigos'      => array_values(array_unique($codigos)),
            'confirmacion' => $confirmacion,
            'order_id'     => $o['order_id'] ?? $o['ref'] ?? $d['order_id'] ?? null,
            'merchant_ref' => $o['merchant_ref'] ?? $d['merchant_ref'] ?? null,
            'duplicado'    => (bool) ($d['duplicate'] ?? $d['idempotent'] ?? false),
            'modo'         => (string) ($d['mode'] ?? ''),
            'monto'        => $d['amount_usd'] ?? $o['total'] ?? null,
            'error'        => $error,
        ];
    }
}

if (!function_exists('conec_recharge')) {
    /**
     * Compra una recarga por CONEC. IDEMPOTENTE por $merchantRef (repetirlo NO cobra dos veces).
     * @param string       $variantId  código del catálogo (variant_id, p.ej. "226")
     * @param string       $gameId     ID del jugador (si el producto lo pide)
     * @param string       $merchantRef tu nº de pedido (6-80 chars). Usa conec_merchant_ref($orderId).
     * @param int          $quantity   1-10
     * @param array        $extraFields campos extra que pida el producto (variants[].fields), key=>valor
     * @return array ['ok'=>bool, 'clase'=>'entregado|en_curso|fallido|desconocido', 'norm'=>..., 'error'=>?, 'error_code'=>?, 'duro'=>bool]
     */
    function conec_recharge(PDO $pdo, string $variantId, string $gameId, string $merchantRef, int $quantity = 1, array $extraFields = []): array {
        $payload = ['variant_id' => $variantId, 'merchant_ref' => $merchantRef];
        if ($gameId !== '') { $payload['game_id'] = $gameId; }
        if ($quantity > 1) { $payload['quantity'] = max(1, min(10, $quantity)); }
        foreach ($extraFields as $k => $v) {
            if ($k !== '' && $v !== '' && !isset($payload[$k])) { $payload[$k] = $v; }
        }

        $r = conec_request($pdo, 'POST', '/recharge.php', $payload);
        if (!$r['ok']) {
            $code = (string) (($r['data']['error'] ?? '') ?: '');
            // Errores DUROS: no tiene sentido reintentar solos (mismo criterio que Escanor).
            $duros = ['insufficient_balance', 'out_of_stock', 'variant_unavailable', 'invalid_variant',
                      'game_id_required', 'invalid_merchant_ref', 'invalid_quantity', 'not_reseller',
                      'invalid_api_key', 'missing_api_key', 'missing_fields', 'invalid_field_value'];
            return ['ok' => false, 'clase' => 'fallido', 'norm' => conec_normalize($r['data']),
                    'error' => $r['error'], 'error_code' => $code, 'duro' => in_array($code, $duros, true), 'http' => $r['code']];
        }
        $norm = conec_normalize($r['data']);
        return ['ok' => $norm['clase'] === 'entregado', 'clase' => $norm['clase'], 'norm' => $norm,
                'error' => $norm['error'], 'error_code' => '', 'duro' => false, 'http' => $r['code']];
    }
}

if (!function_exists('conec_order_status')) {
    /** Consulta una orden por merchant_ref. Devuelve la normalización (para pollear las 'en_curso'). */
    function conec_order_status(PDO $pdo, string $merchantRef): array {
        $r = conec_request($pdo, 'GET', '/order.php', null, ['merchant_ref' => $merchantRef]);
        if (!$r['ok']) { return ['ok' => false, 'error' => $r['error'], 'norm' => conec_normalize($r['data'])]; }
        return ['ok' => true, 'error' => null, 'norm' => conec_normalize($r['data'])];
    }
}

/* ─────────────────────────────────────────────────────────────────────────────
 * ADAPTADORES para el motor de la tienda (admin/paquetes.php + api/pedidos.php).
 * El UI de paquetes espera productos PLANOS [{id,name,price,...}] (como RecargasAmérica);
 * CONEC los da anidados (games→variants) → los aplanamos aquí. El motor solo debe llamar
 * a conec_dispatch_or_recover() y guardar el resultado en las columnas recargas_api_*.
 * ──────────────────────────────────────────────────────────────────────────── */

if (!function_exists('conec_api_fetch_products')) {
    /** Catálogo APLANADO a [{id(variant_id int), name, price, product_id, requires_game_id, fields[]}]. Cacheado por request. */
    function conec_api_fetch_products(PDO $pdo, bool $refresh = false): array {
        static $cache = null;
        if ($cache !== null && !$refresh) { return $cache; }
        $cat = conec_catalog($pdo);
        $out = [];
        if (!empty($cat['ok'])) {
            foreach ($cat['games'] as $g) {
                $gid   = (string) ($g['product_id'] ?? '');
                $gname = (string) ($g['name'] ?? $gid);
                $reqId = !empty($g['requires_game_id']);
                foreach (($g['variants'] ?? []) as $v) {
                    $vid = (int) ($v['variant_id'] ?? 0);
                    if ($vid <= 0) { continue; }
                    $out[] = [
                        'id'               => $vid,
                        'name'             => trim($gname . ' — ' . (string) ($v['name'] ?? '')),
                        'price'            => (float) ($v['your_price'] ?? $v['price'] ?? 0),
                        'product_id'       => $gid,
                        'requires_game_id' => $reqId,
                        'fields'           => is_array($v['fields'] ?? null) ? $v['fields'] : [],
                    ];
                }
            }
        }
        $cache = $out;
        return $out;
    }
}

if (!function_exists('conec_api_product_by_id')) {
    function conec_api_product_by_id(PDO $pdo, int $variantId): ?array {
        foreach (conec_api_fetch_products($pdo) as $p) {
            if ((int) $p['id'] === $variantId) { return $p; }
        }
        return null;
    }
}

if (!function_exists('conec_api_product_label')) {
    function conec_api_product_label(array $p): string {
        return (string) ($p['name'] ?? ('#' . ($p['id'] ?? ''))) . ' ($' . number_format((float) ($p['price'] ?? 0), 4) . ')';
    }
}

if (!function_exists('conec_api_filter_products')) {
    /** Pre-filtra por palabra clave (para los slots de catálogo por juego). Vacío = todo. */
    function conec_api_filter_products(array $products, string $keyword): array {
        $kw = trim(mb_strtolower($keyword));
        if ($kw === '') { return $products; }
        return array_values(array_filter($products, static function ($p) use ($kw) {
            return mb_strpos(mb_strtolower((string) ($p['name'] ?? '')), $kw) !== false;
        }));
    }
}

if (!function_exists('conec_dispatch_or_recover')) {
    /**
     * DESPACHO para el motor (api/pedidos.php). Espejo de recargasamerica_dispatch_or_recover().
     * Si $savedReference viene (ya se despachó antes) → reconsulta estado (polling). Si no → compra (idempotente).
     * Devuelve la MISMA forma que espera el motor de RecargasAmérica:
     *   ['success'=>bool, 'accepted'=>bool, 'needs_manual_review'=>bool, 'message'=>str,
     *    'reference'=>str, 'codigos'=>array, 'confirmacion'=>str, 'payload'=>array, 'error_code'=>str]
     *  - success=true            → entregado (guarda estado 'enviado', codigos en recargas_api_codigo_entregado)
     *  - accepted=true           → en proceso (deja 'pagado', reconsultar luego con la misma referencia)
     *  - needs_manual_review     → falló/dudoso → revisión manual (NO cobra de más: idempotente por merchant_ref)
     */
    function conec_dispatch_or_recover(PDO $pdo, int $orderId, string $variantId, string $gameId = '', int $quantity = 1, array $extraFields = [], string $savedReference = ''): array {
        $ref = conec_merchant_ref($orderId);
        if (trim($savedReference) !== '') {
            $st   = conec_order_status($pdo, $ref);
            $norm = $st['norm'];
        } else {
            $res  = conec_recharge($pdo, $variantId, $gameId, $ref, $quantity, $extraFields);
            $norm = $res['norm'];
            if (!$res['ok'] && !empty($res['duro'])) {
                return ['success' => false, 'accepted' => false, 'needs_manual_review' => true,
                        'message' => 'CONEC: ' . ($res['error'] ?: 'error'), 'reference' => $ref,
                        'codigos' => [], 'confirmacion' => '', 'payload' => $norm, 'error_code' => (string) ($res['error_code'] ?? '')];
            }
        }
        $reference = (string) ($norm['order_id'] ?: $ref);
        switch ($norm['clase']) {
            case 'entregado':
                return ['success' => true, 'accepted' => false, 'needs_manual_review' => false,
                        'message' => 'CONEC: recarga entregada' . (!empty($norm['duplicado']) ? ' (idempotente, no se cobró dos veces)' : ''),
                        'reference' => $reference, 'codigos' => $norm['codigos'], 'confirmacion' => (string) $norm['confirmacion'],
                        'payload' => $norm, 'error_code' => ''];
            case 'en_curso':
                return ['success' => false, 'accepted' => true, 'needs_manual_review' => false,
                        'message' => 'CONEC: en proceso con el proveedor', 'reference' => $reference,
                        'codigos' => [], 'confirmacion' => '', 'payload' => $norm, 'error_code' => ''];
            default: // fallido / desconocido
                return ['success' => false, 'accepted' => false, 'needs_manual_review' => true,
                        'message' => 'CONEC: ' . ($norm['error'] ?: ($norm['estado'] ?: 'sin confirmar')),
                        'reference' => $reference, 'codigos' => [], 'confirmacion' => '', 'payload' => $norm, 'error_code' => ''];
        }
    }
}
