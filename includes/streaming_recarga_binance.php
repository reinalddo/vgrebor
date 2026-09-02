<?php
/**
 * includes/streaming_recarga_binance.php
 * Verificación AUTOMÁTICA de una recarga de SALDO del revendedor pagada por Binance (verificador
 * "apicentral.pro" · el mismo que usa la tienda en los juegos). Replica EXACTO el motor de la tienda:
 *   - referencia = últimos N dígitos (config binance_pagonorte_referencia_digitos), con ceros a la izq.
 *   - monto = igualdad a 2 decimales.
 *   - solo movimientos recientes (defensa contra reusar uno viejo).
 *   - GUARDA ANTI-DOBLE-COBRO atómica: cada movimiento se "reclama" una sola vez (columna checked +
 *     wallet_recarga_id) con un UPDATE condicional; si dos personas envían a la vez, solo una acredita.
 *
 * SEGURIDAD: el fetch a la API es best-effort con INSERT IGNORE → NUNCA sobrescribe los montos que la
 * tienda ya sincronizó (no corrompe su verificación de pedidos). Si algo falla o no casa, NO acredita
 * (queda 'pendiente' para aprobación manual). Nunca acredita de más.
 *
 * Uso: sbr_binance_verify_and_credit($pdo, $uid, $recId, $reportedRef, $amount): array
 *   → ['credited'=>bool, 'message'=>string]
 */

require_once __DIR__ . '/store_config.php';
require_once __DIR__ . '/../api/wallet/_helpers.php';

if (!function_exists('sbr_cfg')) {
    /** Lee una config de la tienda con FALLBACK: primero configuracion_general (store_config_get) y,
     *  si viene vacía, la tabla `configuracion`. Muchos tenants guardan binance_pagonorte_* / ff_bank_*
     *  en `configuracion` (no en `configuracion_general`) → sin este fallback la verificación NUNCA
     *  encontraba el token y la recarga quedaba pendiente ("por binance/bnc siguen pendiente"). */
    function sbr_cfg(string $key, string $default = ''): string {
        $v = trim((string) store_config_get($key, ''));
        if ($v !== '') return $v;
        try {
            $pdo = function_exists('db') ? db() : null;
            if ($pdo instanceof PDO) {
                foreach (['configuracion', 'configuracion_general'] as $t) {
                    try {
                        $st = $pdo->prepare("SELECT valor FROM $t WHERE clave=? LIMIT 1");
                        $st->execute([$key]);
                        $val = trim((string) ($st->fetchColumn() ?: ''));
                        if ($val !== '') return $val;
                    } catch (Throwable $e) {}
                }
            }
        } catch (Throwable $e) {}
        return $default;
    }
}
if (!function_exists('sbr_binance_enabled')) {
    function sbr_binance_enabled(): bool {
        return sbr_cfg('binance_pagonorte_token') !== '';
    }
}
if (!function_exists('sbr_binance_digits')) {
    // SEGURIDAD (endurecido 2026-08-30, mismo incidente que bnc_referencia_digitos más abajo): antes
    // esto reusaba binance_pagonorte_referencia_digitos — la config del STORE PRINCIPAL para verificar
    // pagos de clientes normales, pensada para SU flujo, no para la billetera del revendedor. Si el
    // admin la bajó a pocos dígitos por comodidad de sus clientes (ej. 6, lo normal para Binance), la
    // billetera de streaming heredaba el mismo riesgo (adivinar/reusar el final de la referencia de un
    // pago ajeno) sin que nadie lo decidiera para streaming. Se usa una clave PROPIA
    // (binance_streaming_referencia_digitos) que NO toca ni depende de la del store.
    //
    // CORREGIDO (2026-08-30, reporte real "binance ahora dice pendiente"): el default era 12, elegido
    // para que el corte por la derecha no mordiera el prefijo "BINANCE:". Era un PARCHE con un número
    // mágico, y rompía en silencio TODO pago de Binance cuya referencia tuviera menos de 12 dígitos
    // (comprobado: 8, 10 y 11 dígitos quedaban rechazados aun siendo legítimos). La causa real estaba
    // en sbr_reference_matches(), que no quitaba el prefijo antes de comparar; ya está arreglada allí.
    //
    // Con el prefijo bien tratado, el default correcto es 0 = exigir la referencia COMPLETA: es lo MÁS
    // seguro (no hay sufijo corto que adivinar, que fue justo el hueco del incidente) y ahora sí
    // funciona con referencias de cualquier largo. Si el admin ya configuró un número de dígitos para
    // su método Binance en payment_methods, se respeta ESE (mismo criterio que la tienda y que BNC),
    // porque es una decisión suya explícita para ese método — nunca se hereda una config ajena.
    function sbr_binance_digits(?PDO $pdo = null, string $metodoNombre = ''): int {
        if ($pdo instanceof PDO) {
            $pm = sbr_payment_method_digits($pdo, 'binance', $metodoNombre);
            if ($pm !== null) return $pm;
        }
        return max(0, min(120, (int) sbr_cfg('binance_streaming_referencia_digitos', '0')));
    }
}
if (!function_exists('sbr_norm_digits')) {
    // Igual que normalize_reference_digits() de la tienda.
    function sbr_norm_digits(string $ref): string { $s = ltrim($ref, '0'); return $s !== '' ? $s : '0'; }
}
if (!function_exists('sbr_reference_matches')) {
    // Igual que movement_reference_matches() de la tienda. $fullRef es la referencia GUARDADA
    // ("BINANCE:xxxxx"); $reported es lo que escribió el revendedor.
    //
    // BUG CORREGIDO (2026-08-30): la referencia guardada puede traer un PREFIJO de método
    // ("BINANCE:1234…", ver sbr_fetch_sync) que el revendedor NUNCA escribe, y aquí no se quitaba
    // nunca. Eso rompía las dos formas de comparar:
    //   · con $digits = 0 (referencia completa) "BINANCE:123" !== "123" → NO casaba jamás, ni el
    //     pago más legítimo;
    //   · con $digits > 0, el corte por la derecha MUERDE el prefijo en cuanto la referencia real
    //     es más corta que $digits (substr('BINANCE:12345678', -12) = 'NCE:12345678') → tampoco
    //     casaba. Por eso un default alto "seguro" (12) rechazaba en silencio todo pago de Binance
    //     con referencia de menos de 12 dígitos.
    // La solución correcta NO es elegir un número de dígitos que esquive el prefijo (eso solo mueve
    // el problema de sitio): es QUITAR el prefijo antes de comparar. Así funciona con referencias de
    // cualquier largo y, además, permite exigir la referencia COMPLETA ($digits=0), que es lo más
    // seguro posible porque no hay nada que adivinar.
    function sbr_reference_matches(string $fullRef, string $reported, int $digits): bool {
        $reported = trim($reported);
        if ($reported === '') return false;
        // Candidatos: la referencia tal cual está guardada y, si trae prefijo, sin él.
        // (Las de banco/BNC se guardan sin prefijo → no hay segundo candidato, nada cambia para ellas.)
        $candidatos = [$fullRef];
        $sep = strpos($fullRef, ':');
        if ($sep !== false && $sep < strlen($fullRef) - 1) {
            $candidatos[] = substr($fullRef, $sep + 1);
        }
        foreach ($candidatos as $ref) {
            if ($digits > 0) {
                $nr = strlen($reported) > $digits ? substr($reported, -$digits) : $reported;
                $bs = strlen($ref) > $digits ? substr($ref, -$digits) : $ref;
                if ($ref === $reported || $bs === $nr || sbr_norm_digits($bs) === sbr_norm_digits($nr)) {
                    return true;
                }
            } elseif ($ref === $reported || sbr_norm_digits($ref) === sbr_norm_digits($reported)) {
                return true;
            }
        }
        return false;
    }
}
if (!function_exists('sbr_norm_amount')) {
    // Igual criterio que normalize_bank_amount() de la tienda (coma/punto → float).
    function sbr_norm_amount($value): float {
        if (is_numeric($value)) return round((float) $value, 2);
        $raw = trim((string) $value);
        if ($raw === '') return 0.0;
        $clean = preg_replace('/[^0-9,.-]/', '', str_replace(' ', '', $raw));
        if ($clean === null || $clean === '') return 0.0;
        $lc = strrpos($clean, ','); $ld = strrpos($clean, '.');
        if ($lc !== false && $ld !== false) {
            if ($lc > $ld) { $clean = str_replace('.', '', $clean); $clean = str_replace(',', '.', $clean); }
            else { $clean = str_replace(',', '', $clean); }
        } elseif ($lc !== false) { $clean = str_replace('.', '', $clean); $clean = str_replace(',', '.', $clean); }
        else { $clean = str_replace(',', '', $clean); }
        return is_numeric($clean) ? round((float) $clean, 2) : 0.0;
    }
}
if (!function_exists('sbr_ensure_columns')) {
    function sbr_ensure_columns(PDO $pdo): void {
        try { $pdo->exec("ALTER TABLE movimientos ADD COLUMN checked TINYINT(1) NOT NULL DEFAULT 0"); } catch (Throwable $e) {}
        try { $pdo->exec("ALTER TABLE movimientos ADD COLUMN wallet_recarga_id INT NULL"); } catch (Throwable $e) {}
    }
}
if (!function_exists('sbr_fetch_sync')) {
    // Best-effort: trae los movimientos de la API y agrega SOLO los NUEVOS (INSERT IGNORE) para no
    // pisar montos que la tienda ya guardó. Si falla, seguimos con lo que ya haya sincronizado.
    // Devuelve cuántos movimientos DEVOLVIÓ la API en esta corrida (0 = no respondió / vacío). Sirve como
    // señal "apiRespondio" para rechazar referencias inventadas solo cuando la pasarela está VIVA.
    function sbr_fetch_sync(PDO $pdo): int {
        $token = sbr_cfg('binance_pagonorte_token', '');
        if ($token === '') return 0;
        $url = function_exists('store_config_build_binance_pagonorte_movements_url')
            ? store_config_build_binance_pagonorte_movements_url($token)
            : ('https://apicentral.pro/apis/movimientos_binance.jsp?token=' . rawurlencode($token));
        $body = null;
        try {
            if (function_exists('curl_init')) {
                $ch = curl_init($url);
                curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_TIMEOUT => 15, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0]);
                $body = curl_exec($ch); curl_close($ch);
            } else {
                $body = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 15, 'ignore_errors' => true], 'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]));
            }
        } catch (Throwable $e) { $body = null; }
        if (!is_string($body) || $body === '') return 0;
        $data = json_decode($body, true);
        if (!is_array($data) || !isset($data['movimientos']) || !is_array($data['movimientos'])) return 0;
        // INSERT IGNORE → nunca sobrescribe un movimiento existente (no corrompe la data de la tienda).
        $ins = $pdo->prepare("INSERT IGNORE INTO movimientos (referencia, descripcion, fecha_raw, fecha_movimiento, tipo, monto, moneda, payload_json) VALUES (?,?,?,?,?,?, 'USDT', ?)");
        foreach ($data['movimientos'] as $m) {
            if (!is_array($m)) continue;
            $ref = trim((string) ($m['referencia'] ?? '')); if ($ref === '') continue;
            $fecha = (string) ($m['fecha'] ?? '');
            $fm = strtotime($fecha) ? date('Y-m-d H:i:s', strtotime($fecha)) : null;
            try {
                $ins->execute([
                    substr('BINANCE:' . $ref, 0, 120),
                    substr((string) ($m['descripcion'] ?? ''), 0, 255),
                    substr($fecha, 0, 120),
                    $fm,
                    substr((string) ($m['tipo'] ?? ''), 0, 80),
                    sbr_norm_amount($m['monto'] ?? 0),
                    json_encode($m, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]);
            } catch (Throwable $e) {}
        }
        return count($data['movimientos']);
    }
}

if (!function_exists('sbr_ref_ya_usada')) {
    /** ¿La referencia YA fue usada? Busca un movimiento que case esa referencia pero que ya esté
     *  RECLAMADO (por un pedido de la tienda, por otra recarga, o marcado checked). Sirve para AVISAR
     *  claro "ya usada" y RECHAZAR la recarga (no dejarla pendiente, que el admin podría aprobar por
     *  error → doble cobro). $filtro es el WHERE de moneda/prefijo del método (Binance/VES/genérico). */
    function sbr_ref_ya_usada(PDO $pdo, string $reportedRef, int $digits, string $filtro): bool {
        $reportedRef = trim($reportedRef);
        if ($reportedRef === '') return false;
        try {
            $q = $pdo->query("SELECT referencia FROM movimientos
                  WHERE ($filtro)
                    AND (COALESCE(checked,0)=1 OR COALESCE(pedido_id,0)>0 OR COALESCE(wallet_recarga_id,0)>0)
                    AND (fecha_movimiento IS NULL OR fecha_movimiento >= (NOW() - INTERVAL 15 DAY))
                  ORDER BY id DESC LIMIT 400");
            foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $ref) {
                if (sbr_reference_matches((string) $ref, $reportedRef, $digits)) return true;
            }
        } catch (Throwable $e) {}
        return false;
    }
}

if (!function_exists('sbr_metodo_slug')) {
    /** Slug de config a partir del nombre del método de pago ("Pago Móvil BNC" → "bnc"). */
    function sbr_metodo_slug(string $metodo): string {
        $m = mb_strtolower(trim($metodo));
        if ($m === '') return '';
        if (mb_strpos($m, 'binance') !== false) return 'binance';
        if (mb_strpos($m, 'bnc') !== false) return 'bnc';
        if (mb_strpos($m, 'zinli') !== false) return 'zinli';
        if (mb_strpos($m, 'zelle') !== false) return 'zelle';
        // "Pago Móvil" / "Pago Movil" / "Pago Mobil" (mal escrito con B) → todos a 'pagomovil' (usa el banco).
        if (mb_strpos($m, 'pago') !== false && (mb_strpos($m, 'vil') !== false || mb_strpos($m, 'bil') !== false)) return 'pagomovil';
        if (mb_strpos($m, 'movil') !== false || mb_strpos($m, 'mobil') !== false) return 'pagomovil';
        return preg_replace('/[^a-z0-9]+/', '', $m) ?: '';
    }
}

if (!function_exists('sbr_payment_method_digits')) {
    /** Dígitos de referencia YA configurados por el admin para el método de pago real elegido (tabla
     *  payment_methods, columna referencia_digitos) — el MISMO valor que usa el checkout de la tienda
     *  (ver $method['referencia_digitos'] en api/pedidos.php) para verificar pagos de clientes con ESE
     *  banco. No es un número inventado aparte: si el admin la dejó en 0 (default), exige referencia
     *  completa igual que la tienda; si la puso en 6 (típico, porque su banco solo le muestra al pagador
     *  los últimos dígitos), la billetera de streaming ahora respeta EXACTAMENTE ese mismo criterio en
     *  vez de uno propio desalineado. Prioriza el método con el nombre EXACTO elegido; si no hay
     *  coincidencia exacta, cualquier método activo del mismo slug (bnc/pagomovil); si ninguno, null
     *  (el llamador usa su propio fallback, nunca el de otro método). */
    function sbr_payment_method_digits(PDO $pdo, string $slug, string $metodoNombre = ''): ?int {
        try {
            $rows = $pdo->query("SELECT nombre, referencia_digitos FROM payment_methods WHERE activo=1")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { return null; }
        $nombreNorm = mb_strtolower(trim($metodoNombre));
        $porSlug = null;
        foreach ($rows as $r) {
            if (sbr_metodo_slug((string) $r['nombre']) !== $slug) continue;
            $d = $r['referencia_digitos'];
            if ($d === null || $d === '') continue;
            $d = max(0, min(120, (int) $d));
            if ($nombreNorm !== '' && mb_strtolower(trim((string) $r['nombre'])) === $nombreNorm) return $d;
            if ($porSlug === null) $porSlug = $d;
        }
        return $porSlug;
    }
}

if (!function_exists('sbr_monto_a_casar')) {
    /** Monto contra el que hay que casar el movimiento, en la MONEDA REAL del método elegido.
     *
     *  El saldo se acredita en $, pero el pago (y por tanto el movimiento del banco) va en la moneda
     *  del método: Bs para Pago Móvil/BNC, USD/USDT para Binance. Si se busca el movimiento por el
     *  monto en DÓLARES cuando el pago fue en BOLÍVARES, no casa nunca y la recarga queda "pendiente"
     *  eternamente aunque el pago sea perfecto (ese fue un bug real ya visto acá).
     *
     *  La tasa se toma del MÉTODO DE PAGO elegido (payment_methods.moneda_id → monedas.tasa), que es
     *  la fuente correcta: es la misma fila que la tienda usa para cobrarle al cliente y la que se le
     *  muestra al revendedor en pantalla ("A pagar: X Bs"). Antes se leía una fila fija buscando
     *  UPPER(clave)='BS', que se rompe sola si mañana esa moneda se llama distinto (VES, BSD…) o se
     *  desactiva: sin fila, no había tasa, y se terminaba buscando el movimiento por el monto en $.
     *  Se mantiene esa búsqueda como último respaldo.
     *
     *  Devuelve null si no hay conversión que aplicar (el monto en $ ya sirve, p. ej. Binance/USDT).
     */
    function sbr_monto_a_casar(PDO $pdo, string $metodo, float $montoUsd): ?float {
        if ($montoUsd <= 0) return null;
        $slug = sbr_metodo_slug($metodo);
        if ($slug !== 'bnc' && $slug !== 'pagomovil') return null;   // Binance/USDT: 1:1, sin conversión
        $tasa = 0.0;
        try {
            // 1) La tasa del método exacto que eligió el revendedor.
            $st = $pdo->prepare("SELECT mo.tasa FROM payment_methods pm
                                   LEFT JOIN monedas mo ON mo.id = pm.moneda_id
                                  WHERE pm.activo=1 AND LOWER(TRIM(pm.nombre)) = LOWER(TRIM(?)) LIMIT 1");
            $st->execute([$metodo]);
            $tasa = (float) ($st->fetchColumn() ?: 0);
            // 2) Cualquier método activo del mismo tipo (el <select> tiene una lista de respaldo cuyos
            //    nombres no siempre coinciden con la fila real: "Pago Móvil" vs "Pago Móvil Mercantil").
            if ($tasa <= 0) {
                $rows = $pdo->query("SELECT pm.nombre, mo.tasa FROM payment_methods pm
                                       LEFT JOIN monedas mo ON mo.id = pm.moneda_id
                                      WHERE pm.activo=1")->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as $r) {
                    if (sbr_metodo_slug((string) $r['nombre']) !== $slug) continue;
                    $t = (float) ($r['tasa'] ?? 0);
                    if ($t > 0) { $tasa = $t; break; }
                }
            }
            // 3) Último respaldo: la moneda Bs de la tienda, como se hacía antes.
            if ($tasa <= 0) {
                $tasa = (float) ($pdo->query("SELECT tasa FROM monedas WHERE UPPER(clave) IN ('BS','VES','BSD','VED') AND activo=1 ORDER BY (UPPER(clave)='BS') DESC LIMIT 1")->fetchColumn() ?: 0);
            }
        } catch (Throwable $e) { return null; }
        return $tasa > 0 ? round($montoUsd * $tasa, 2) : null;
    }
}

if (!function_exists('sbr_log_sin_coincidencia')) {
    /** Deja en el error_log POR QUÉ no casó una recarga. Sin esto, "queda pendiente" es indistinguible
     *  entre: monto convertido mal, el movimiento aún no llegó del banco, o la referencia no coincide
     *  — y desde fuera de producción no hay forma de saber cuál de las tres es. Nunca lanza. */
    function sbr_log_sin_coincidencia(PDO $pdo, string $contexto, array $datos, string $filtroSql): void {
        try {
            $partes = [];
            foreach ($datos as $k => $v) $partes[] = $k . '=' . (is_float($v) ? number_format($v, 2, '.', '') : (string) $v);
            // Montos realmente disponibles ahora mismo, para ver de un vistazo si el problema es el monto.
            $disponibles = [];
            try {
                $q = $pdo->query("SELECT ROUND(monto,2) m FROM movimientos
                                   WHERE ($filtroSql)
                                     AND COALESCE(pedido_id,0)=0 AND COALESCE(checked,0)=0 AND COALESCE(wallet_recarga_id,0)=0
                                     AND (fecha_movimiento IS NULL OR fecha_movimiento >= (NOW() - INTERVAL 3 DAY))
                                   ORDER BY id DESC LIMIT 12");
                $disponibles = $q->fetchAll(PDO::FETCH_COLUMN) ?: [];
            } catch (Throwable $e) {}
            error_log('TVG streaming recarga SIN COINCIDENCIA [' . $contexto . '] ' . implode(' ', $partes)
                . ' | montos disponibles sin reclamar (ult. 3 dias): '
                . ($disponibles ? implode(', ', $disponibles) : 'NINGUNO (el movimiento no ha llegado del proveedor)'));
        } catch (Throwable $e) {}
    }
}

if (!function_exists('sbr_verify_recarga')) {
    /**
     * Punto de entrada ÚNICO de la verificación automática de una recarga.
     *
     *  - Binance  → motor probado (verificador apicentral «pagonorte»).
     *  - Otro método (BNC, Pago Móvil…) → SOLO si el admin configuró su API de movimientos:
     *      config `<slug>_movimientos_url` (+ opcional `<slug>_movimientos_token`,
     *      `<slug>_referencia_digitos`, `<slug>_referencia_prefijo`).
     *    Sin esa config NO se acredita nada: la recarga queda pendiente de aprobación manual.
     *    Es a propósito: no se puede acreditar dinero real contra un API que no existe/no probamos.
     *
     * $amount       = monto a ACREDITAR en la billetera (en $, la moneda del saldo).
     * $amountMatch  = monto REALMENTE PAGADO en la moneda del método (Bs para BNC/Pago Móvil, USDT
     *                 ≈ $ para Binance). Es contra ESTE que se casa el movimiento del banco/Binance.
     *                 Si es null se usa $amount (caso Binance/USDT donde $ y USDT son 1:1).
     *
     * Devuelve ['credited'=>bool, 'message'=>string].
     */
    function sbr_verify_recarga(PDO $pdo, int $uid, int $recId, string $reportedRef, float $amount, string $metodo, ?float $amountMatch = null): array {
        $slug = sbr_metodo_slug($metodo);
        $mm = ($amountMatch !== null && $amountMatch > 0) ? $amountMatch : $amount;
        if ($slug === 'binance') {
            return sbr_binance_verify_and_credit($pdo, $uid, $recId, $reportedRef, $amount, $mm, $metodo);
        }
        // BNC / Pago Móvil (Bs/VES): reusa la MISMA conexión bancaria (ff_bank_*) que la tienda YA
        // usa para verificar los pedidos ("el BNC que ya está en la página"). Si no está configurada,
        // cae al genérico (por si el admin puso <slug>_movimientos_url a mano).
        if (($slug === 'bnc' || $slug === 'pagomovil') && sbr_bank_enabled()) {
            return sbr_bank_verify_and_credit($pdo, $uid, $recId, $reportedRef, $amount, $slug, $mm, $metodo);
        }
        if ($slug === '') return ['credited' => false, 'message' => 'Tu recarga quedó registrada; el admin la aprueba enseguida.'];
        $url = sbr_cfg($slug . '_movimientos_url', '');
        if ($url === '') {
            return ['credited' => false, 'message' => 'Tu recarga quedó registrada; el admin la aprueba enseguida.'];
        }
        return sbr_generic_verify_and_credit($pdo, $uid, $recId, $reportedRef, $amount, $slug, $url, $mm);
    }
}

if (!function_exists('sbr_generic_verify_and_credit')) {
    /** Mismo motor que Binance (match por últimos N dígitos + monto exacto + claim atómico
     *  anti-doble-cobro), pero contra el API de movimientos que se configure para ese método. */
    function sbr_generic_verify_and_credit(PDO $pdo, int $uid, int $recId, string $reportedRef, float $amount, string $slug, string $url, ?float $amountMatch = null): array {
        $reportedRef = trim($reportedRef);
        if ($reportedRef === '' || $amount <= 0) return ['credited' => false, 'message' => 'Falta la referencia o el monto.'];
        sbr_ensure_columns($pdo);
        $credit = round($amount, 2);   // a acreditar en $
        $matchAmt = ($amountMatch !== null && $amountMatch > 0) ? round($amountMatch, 2) : $credit;  // pagado (moneda del método)
        $prefijo = sbr_cfg($slug . '_referencia_prefijo', '') ?: (mb_strtoupper($slug) . ':');
        $digits  = max(0, min(120, (int) sbr_cfg($slug . '_referencia_digitos', '0')));

        // Traer movimientos (best-effort, INSERT IGNORE: nunca pisa lo que ya haya).
        $apiRespondio = false;   // ¿la API devolvió movimientos EN ESTA corrida? (para rechazar seguro)
        try {
            $token = sbr_cfg($slug . '_movimientos_token', '');
            $full = $url . ((mb_strpos($url, '?') !== false) ? '&' : '?') . ($token !== '' ? 'token=' . rawurlencode($token) : '');
            $body = null;
            if (function_exists('curl_init')) {
                $ch = curl_init($full);
                curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_TIMEOUT => 15, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0]);
                $body = curl_exec($ch); curl_close($ch);
            } else {
                $body = @file_get_contents($full, false, stream_context_create(['http' => ['timeout' => 15, 'ignore_errors' => true], 'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]));
            }
            if (is_string($body) && $body !== '') {
                $data = json_decode($body, true);
                $lista = is_array($data) ? ($data['movimientos'] ?? $data['data'] ?? (array_is_list($data) ? $data : [])) : [];
                if (is_array($lista) && count($lista) > 0) $apiRespondio = true;
                if (is_array($lista)) {
                    $ins = $pdo->prepare("INSERT IGNORE INTO movimientos (referencia, descripcion, fecha_raw, fecha_movimiento, tipo, monto, moneda) VALUES (?,?,?,?,?,?,?)");
                    foreach ($lista as $m) {
                        if (!is_array($m)) continue;
                        $ref = trim((string) ($m['referencia'] ?? $m['reference'] ?? '')); if ($ref === '') continue;
                        $fecha = (string) ($m['fecha'] ?? $m['date'] ?? '');
                        $fm = strtotime($fecha) ? date('Y-m-d H:i:s', strtotime($fecha)) : null;
                        try {
                            $ins->execute([
                                mb_substr($prefijo . $ref, 0, 120),
                                mb_substr((string) ($m['descripcion'] ?? $m['description'] ?? ''), 0, 255),
                                mb_substr($fecha, 0, 120), $fm,
                                mb_substr((string) ($m['tipo'] ?? ''), 0, 80),
                                sbr_norm_amount($m['monto'] ?? $m['amount'] ?? 0),
                                mb_substr((string) ($m['moneda'] ?? 'VES'), 0, 10),
                            ]);
                        } catch (Throwable $e) {}
                    }
                }
            }
        } catch (Throwable $e) {}

        // Match (contra el monto pagado) + claim atómico + acreditar (en $).
        $q = $pdo->prepare("SELECT id, referencia FROM movimientos
              WHERE referencia LIKE ? AND ROUND(monto,2) = ?
                AND COALESCE(pedido_id,0)=0 AND COALESCE(checked,0)=0 AND COALESCE(wallet_recarga_id,0)=0
                AND (fecha_movimiento IS NULL OR fecha_movimiento >= (NOW() - INTERVAL 3 DAY))
              ORDER BY id DESC LIMIT 100");
        $q->execute([$prefijo . '%', $matchAmt]);
        $hayCandidatos = false;
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $m) {
            $hayCandidatos = true;
            if (!sbr_reference_matches((string) $m['referencia'], $reportedRef, $digits)) continue;
            $claim = $pdo->prepare("UPDATE movimientos SET checked=1, wallet_recarga_id=? WHERE id=? AND COALESCE(pedido_id,0)=0 AND COALESCE(checked,0)=0 AND COALESCE(wallet_recarga_id,0)=0");
            $claim->execute([$recId, (int) $m['id']]);
            if ($claim->rowCount() !== 1) continue;
            try {
                wallet_acreditar($pdo, $uid, $credit, 'recarga_' . $slug, 'Recarga ' . mb_strtoupper($slug) . ' (auto) · ref ' . $reportedRef);
            } catch (Throwable $e) {
                try { $pdo->prepare("UPDATE movimientos SET checked=0, wallet_recarga_id=NULL WHERE id=? AND wallet_recarga_id=?")->execute([(int) $m['id'], $recId]); } catch (Throwable $e2) {}
                return ['credited' => false, 'message' => 'No se pudo acreditar el saldo. Intenta de nuevo.'];
            }
            try { $pdo->prepare("UPDATE wallet_recargas SET estado='aprobado' WHERE id=? AND usuario_id=?")->execute([$recId, $uid]); } catch (Throwable $e) {}
            return ['credited' => true, 'message' => '✓ Pago verificado. Saldo acreditado al instante.'];
        }
        if (sbr_ref_ya_usada($pdo, $reportedRef, $digits, "referencia LIKE '" . str_replace("'", "''", $prefijo) . "%'")) {
            return ['credited' => false, 'reused' => true, 'message' => '⚠ Esta referencia YA fue usada en un pago anterior (una compra en la tienda o otra recarga). No se puede usar dos veces.'];
        }
        // Hay movimientos recientes CON ESE MONTO exacto (no es problema de sincronización/demora), pero
        // NINGUNO casa con la referencia escrita → es un dato erróneo, no algo por llegar. Rechazar claro
        // en vez de dejarlo "pendiente" (que sonaba a "espera, ya llega" cuando en realidad no va a llegar).
        if ($hayCandidatos) {
            return ['credited' => false, 'rejected' => true, 'message' => '✗ La referencia no coincide con ningún pago recibido por ese monto. Verifica el número de referencia e intenta de nuevo.'];
        }
        // AMPLIACIÓN (pedido del cliente 30-ago): referencia INVENTADA que ni siquiera casa por monto.
        // Si la API está VIVA (trajo movimientos recientes de ESTE método) pero la referencia NO aparece
        // en NINGUNO de ellos → no es un pago que "va a llegar", es un dato falso → RECHAZAR. Si la API no
        // trajo nada reciente (caída/retraso), NO se rechaza: queda pendiente para no tumbar por error un
        // pago válido que aún no sincronizó. (Balance seguro: solo rechaza con evidencia de que la API sí
        // está devolviendo movimientos y la referencia simplemente no existe.)
        if ($apiRespondio) {
            try {
                $rr = $pdo->prepare("SELECT referencia FROM movimientos
                      WHERE referencia LIKE ? AND (fecha_movimiento IS NULL OR fecha_movimiento >= (NOW() - INTERVAL 3 DAY))
                      ORDER BY id DESC LIMIT 300");
                $rr->execute([$prefijo . '%']);
                $refAparece = false;
                foreach ($rr->fetchAll(PDO::FETCH_COLUMN) as $fullRef) { if (sbr_reference_matches((string) $fullRef, $reportedRef, $digits)) { $refAparece = true; break; } }
                if (!$refAparece) {
                    return ['credited' => false, 'rejected' => true, 'message' => '✗ Esa referencia no aparece en los pagos recibidos. Verifícala; si es correcta, espera 1-2 min y vuelve a intentar.'];
                }
            } catch (Throwable $e) {}
        }
        return ['credited' => false, 'message' => 'No encontramos tu pago todavía. Puede tardar 1-2 min; el admin también puede aprobarlo.'];
    }
}

/* ============================================================================
 * BNC / PAGO MÓVIL (Bs/VES) — reusa la conexión bancaria ff_bank_* de la tienda.
 * ========================================================================== */
if (!function_exists('sbr_bank_config')) {
    /** Config del verificador de banco VES que la tienda YA usa para los pedidos (pagonorte). */
    function sbr_bank_config(): array {
        return [
            'base'     => (sbr_cfg('ff_bank_api_base_url', 'https://pagonorte.net')) ?: 'https://pagonorte.net',
            'posicion' => sbr_cfg('ff_bank_posicion', ''),
            'token'    => sbr_cfg('ff_bank_token', ''),
            'clave'    => sbr_cfg('ff_bank_clave', ''),
        ];
    }
}
if (!function_exists('sbr_bank_enabled')) {
    function sbr_bank_enabled(): bool {
        $c = sbr_bank_config();
        return $c['posicion'] !== '' && $c['token'] !== '' && $c['clave'] !== '';
    }
}
if (!function_exists('sbr_bank_verify_and_credit')) {
    /** Verifica una recarga por BNC / Pago Móvil (VES) contra la MISMA API bancaria de la tienda.
     *  Match por referencia (exacta por defecto: bnc_referencia_digitos=0) + monto exacto, solo
     *  movimientos VES recientes y DISPONIBLES (no usados por un pedido ni otra recarga), con claim
     *  atómico anti-doble-cobro. El fetch es best-effort con INSERT IGNORE (no corrompe la data de la
     *  tienda). Si nada casa, NO acredita (queda pendiente de aprobación manual). Nunca acredita de más. */
    function sbr_bank_verify_and_credit(PDO $pdo, int $uid, int $recId, string $reportedRef, float $amount, string $slug = 'bnc', ?float $amountMatch = null, string $metodoNombre = ''): array {
        $reportedRef = trim($reportedRef);
        if ($reportedRef === '' || $amount <= 0) return ['credited' => false, 'message' => 'Falta la referencia o el monto.'];
        if (!sbr_bank_enabled())               return ['credited' => false, 'message' => 'Tu recarga quedó registrada; el admin la aprueba enseguida. (La verificación automática por banco no está configurada.)'];
        sbr_ensure_columns($pdo);
        // $amount = lo que se acredita en $ (billetera). $match = lo que se pagó en Bs (contra el movimiento).
        $credit = round($amount, 2);
        $match  = ($amountMatch !== null && $amountMatch > 0) ? round($amountMatch, 2) : $credit;
        // SEGURIDAD (endurecido 2026-08-30, reportado como incidente real: saldo acreditado sin pago
        // verdadero): ya NO se hereda el ajuste de Binance (binance_pagonorte_referencia_digitos) como
        // respaldo. Si un admin bajó ESE número para comodidad de Binance (ej. a 6 dígitos), BNC lo
        // heredaba automáticamente SIN que nadie lo decidiera para BNC — cuantos menos dígitos se
        // exigen, más fácil es que una referencia "inventada" case por pura coincidencia contra un
        // movimiento real de otra persona que ya estaba en la tabla sin reclamar.
        //
        // AJUSTE (2026-08-30, reporte "la de Pago Móvil sigue quedando pendiente"): el default seguro
        // NO puede ser un número inventado aparte (bnc_referencia_digitos=0 fijo) — la TIENDA misma
        // (api/pedidos.php, $method['referencia_digitos']) ya verifica sus propios pagos BNC/Pago Móvil
        // usando el dígito configurado POR CADA MÉTODO en payment_methods (0 = completa por defecto,
        // pero muchos admins lo bajan a 6 porque su banco solo le muestra al pagador los últimos
        // dígitos). Si streaming exige SIEMPRE la referencia completa mientras la tienda acepta 6
        // dígitos para ese mismo banco, una referencia perfectamente legítima (y ya aceptada por la
        // tienda para pagos normales) nunca casa aquí → queda "pendiente" para siempre, no por demora,
        // sino porque el criterio es más estricto que el de la propia tienda para el MISMO método.
        // Se usa el valor YA configurado por el admin para ese método exacto (sbr_payment_method_digits);
        // solo si no hay ninguno configurado se cae al valor propio bnc_referencia_digitos (default 0,
        // igual de estricto que el default de la tienda) — nunca al de Binance.
        $pmDigits = sbr_payment_method_digits($pdo, $slug, $metodoNombre);
        $digits = $pmDigits !== null ? $pmDigits : max(0, min(120, (int) sbr_cfg('bnc_referencia_digitos', '0')));

        // Fetch best-effort (INSERT IGNORE por referencia UNIQUE → nunca pisa lo que la tienda sincronizó).
        $apiRespondio = false;   // ¿el banco devolvió movimientos EN ESTA corrida? (para rechazar seguro)
        try {
            $c = sbr_bank_config();
            if (function_exists('store_config_build_bank_movements_url')) {
                $url = store_config_build_bank_movements_url($c['base'] !== '' ? $c['base'] : 'https://pagonorte.net',
                    ['posicion' => $c['posicion'], 'token' => $c['token'], 'password' => $c['clave']]);
                $body = null;
                if (function_exists('curl_init')) {
                    $ch = curl_init($url);
                    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_CONNECTTIMEOUT => 20, CURLOPT_TIMEOUT => 20, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0]);
                    $body = curl_exec($ch); curl_close($ch);
                } else {
                    $body = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 20, 'ignore_errors' => true], 'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]));
                }
                if (is_string($body) && $body !== '') {
                    $data = json_decode($body, true);
                    $lista = (is_array($data) && isset($data['movimientos']) && is_array($data['movimientos'])) ? $data['movimientos'] : [];
                    if (count($lista) > 0) $apiRespondio = true;
                    // Los movimientos del banco se guardan SIN prefijo y moneda VES (igual que la tienda).
                    $ins = $pdo->prepare("INSERT IGNORE INTO movimientos (referencia, descripcion, fecha_raw, fecha_movimiento, tipo, monto, moneda) VALUES (?,?,?,?,?,?, 'VES')");
                    foreach ($lista as $m) {
                        if (!is_array($m)) continue;
                        $ref = trim((string) ($m['referencia'] ?? '')); if ($ref === '') continue;
                        $fecha = (string) ($m['fecha'] ?? '');
                        $fm = strtotime($fecha) ? date('Y-m-d H:i:s', strtotime($fecha)) : null;
                        try {
                            $ins->execute([
                                mb_substr($ref, 0, 120),
                                mb_substr((string) ($m['descripcion'] ?? ''), 0, 255),
                                mb_substr($fecha, 0, 120), $fm,
                                mb_substr((string) ($m['tipo'] ?? ''), 0, 80),
                                sbr_norm_amount($m['monto'] ?? 0),
                            ]);
                        } catch (Throwable $e) {}
                    }
                }
            }
        } catch (Throwable $e) {}

        // Match (moneda VES, sin prefijo, contra el monto en Bs) + claim atómico + acreditar (en $).
        $q = $pdo->prepare(
            "SELECT id, referencia FROM movimientos
              WHERE moneda='VES' AND ROUND(monto,2) = ?
                AND COALESCE(pedido_id,0)=0 AND COALESCE(checked,0)=0 AND COALESCE(wallet_recarga_id,0)=0
                AND (fecha_movimiento IS NULL OR fecha_movimiento >= (NOW() - INTERVAL 3 DAY))
              ORDER BY id DESC LIMIT 200");
        $q->execute([$match]);
        $hayCandidatos = false;
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $m) {
            $hayCandidatos = true;
            if (!sbr_reference_matches((string) $m['referencia'], $reportedRef, $digits)) continue;
            $claim = $pdo->prepare("UPDATE movimientos SET checked=1, wallet_recarga_id=? WHERE id=? AND COALESCE(pedido_id,0)=0 AND COALESCE(checked,0)=0 AND COALESCE(wallet_recarga_id,0)=0");
            $claim->execute([$recId, (int) $m['id']]);
            if ($claim->rowCount() !== 1) continue;   // otro se lo llevó → seguir
            try {
                wallet_acreditar($pdo, $uid, $credit, 'recarga_' . $slug, 'Recarga ' . mb_strtoupper($slug) . ' (auto) · ref ' . $reportedRef);
            } catch (Throwable $e) {
                try { $pdo->prepare("UPDATE movimientos SET checked=0, wallet_recarga_id=NULL WHERE id=? AND wallet_recarga_id=?")->execute([(int) $m['id'], $recId]); } catch (Throwable $e2) {}
                return ['credited' => false, 'message' => 'No se pudo acreditar el saldo. Intenta de nuevo.'];
            }
            try { $pdo->prepare("UPDATE wallet_recargas SET estado='aprobado' WHERE id=? AND usuario_id=?")->execute([$recId, $uid]); } catch (Throwable $e) {}
            return ['credited' => true, 'message' => '✓ Pago verificado (BNC / Pago Móvil). Saldo acreditado al instante.'];
        }
        if (sbr_ref_ya_usada($pdo, $reportedRef, $digits, "moneda='VES'")) {
            return ['credited' => false, 'reused' => true, 'message' => '⚠ Esta referencia YA fue usada en un pago anterior (una compra en la tienda o otra recarga). No se puede usar dos veces.'];
        }
        sbr_log_sin_coincidencia($pdo, 'BNC/PagoMovil', [
            'recarga' => $recId, 'metodo' => $metodoNombre !== '' ? $metodoNombre : $slug, 'slug' => $slug,
            'digitos' => $digits, 'ref_escrita' => $reportedRef,
            'monto_usd' => $credit, 'monto_buscado_bs' => $match,
            'candidatos_con_ese_monto' => $hayCandidatos ? 'SI' : 'NO',
        ], "moneda='VES'");
        // Hay movimientos VES recientes con ESE MONTO exacto disponibles (no es demora de sincronización),
        // pero ninguno casa con la referencia escrita → dato erróneo, rechazar claro (no dejar "pendiente").
        if ($hayCandidatos) {
            return ['credited' => false, 'rejected' => true, 'message' => '✗ La referencia no coincide con ningún pago recibido por ese monto. Verifica el número de referencia e intenta de nuevo.'];
        }
        // AMPLIACIÓN (pedido del cliente 30-ago): referencia INVENTADA que no casa ni por monto. Si el
        // banco RESPONDIÓ con movimientos en esta corrida (API viva) pero la referencia NO aparece en
        // ninguno reciente → dato falso → RECHAZAR. Si el banco no respondió (caída/retraso), queda
        // pendiente para no tumbar un pago válido que aún no sincroniza.
        if ($apiRespondio) {
            try {
                $rr = $pdo->prepare("SELECT referencia FROM movimientos
                      WHERE moneda='VES' AND (fecha_movimiento IS NULL OR fecha_movimiento >= (NOW() - INTERVAL 3 DAY))
                      ORDER BY id DESC LIMIT 300");
                $rr->execute();
                $refAparece = false;
                foreach ($rr->fetchAll(PDO::FETCH_COLUMN) as $fullRef) { if (sbr_reference_matches((string) $fullRef, $reportedRef, $digits)) { $refAparece = true; break; } }
                if (!$refAparece) {
                    return ['credited' => false, 'rejected' => true, 'message' => '✗ Esa referencia no aparece en los pagos recibidos. Verifícala; si es correcta, espera 1-2 min y vuelve a intentar.'];
                }
            } catch (Throwable $e) {}
        }
        return ['credited' => false, 'message' => 'No encontramos tu pago todavía. Puede tardar 1-2 min; el admin también puede aprobarlo.'];
    }
}

if (!function_exists('sbr_binance_verify_and_credit')) {
    function sbr_binance_verify_and_credit(PDO $pdo, int $uid, int $recId, string $reportedRef, float $amount, ?float $amountMatch = null, string $metodoNombre = ''): array {
        $reportedRef = trim($reportedRef);
        if (!sbr_binance_enabled()) return ['credited' => false, 'message' => 'Binance no está configurado.'];
        if ($reportedRef === '' || $amount <= 0) return ['credited' => false, 'message' => 'Falta la referencia o el monto.'];

        sbr_ensure_columns($pdo);
        $apiRespondio = false;   // ¿la API de Binance devolvió movimientos en esta corrida? (rechazo seguro)
        try { $apiRespondio = (sbr_fetch_sync($pdo) > 0); } catch (Throwable $e) {}   // best-effort

        $digits = sbr_binance_digits($pdo, $metodoNombre);
        // $amount = a acreditar en $. Binance cobra en USDT ≈ $ (1:1) → el match usa el mismo monto,
        // salvo que se pase $amountMatch (moneda distinta).
        $credit = round($amount, 2);
        $amount = ($amountMatch !== null && $amountMatch > 0) ? round($amountMatch, 2) : $credit;
        // Candidatos: movimientos de Binance, DISPONIBLES (no usados por un pedido ni otra recarga),
        // recientes (últimos 3 días) y con monto exacto. La referencia se compara en PHP (últimos N díg).
        $q = $pdo->prepare(
            "SELECT id, referencia, monto FROM movimientos
              WHERE referencia LIKE 'BINANCE:%'
                AND ROUND(monto,2) = ?
                AND COALESCE(pedido_id,0) = 0
                AND COALESCE(checked,0) = 0
                AND COALESCE(wallet_recarga_id,0) = 0
                AND (fecha_movimiento IS NULL OR fecha_movimiento >= (NOW() - INTERVAL 3 DAY))
              ORDER BY id DESC LIMIT 100"
        );
        $q->execute([$amount]);
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        $hayCandidatos = false;

        foreach ($rows as $m) {
            $hayCandidatos = true;
            if (!sbr_reference_matches((string) $m['referencia'], $reportedRef, $digits)) continue;
            // Claim ATÓMICO: solo si sigue disponible. Si dos envían a la vez, solo uno hace rowCount=1.
            $claim = $pdo->prepare("UPDATE movimientos SET checked=1, wallet_recarga_id=? WHERE id=? AND COALESCE(pedido_id,0)=0 AND COALESCE(checked,0)=0 AND COALESCE(wallet_recarga_id,0)=0");
            $claim->execute([$recId, (int) $m['id']]);
            if ($claim->rowCount() !== 1) continue;   // otro se lo llevó → seguir buscando
            // Acreditar el saldo y aprobar la recarga.
            try {
                wallet_acreditar($pdo, $uid, $credit, 'recarga_binance', 'Recarga Binance (auto) · ref ' . $reportedRef);
            } catch (Throwable $e) {
                // Si falla el crédito, liberar el movimiento para no dejarlo "usado" sin acreditar.
                try { $pdo->prepare("UPDATE movimientos SET checked=0, wallet_recarga_id=NULL WHERE id=? AND wallet_recarga_id=?")->execute([(int) $m['id'], $recId]); } catch (Throwable $e2) {}
                return ['credited' => false, 'message' => 'No se pudo acreditar el saldo. Intenta de nuevo.'];
            }
            try { $pdo->prepare("UPDATE wallet_recargas SET estado='aprobado' WHERE id=? AND usuario_id=?")->execute([$recId, $uid]); } catch (Throwable $e) {}
            return ['credited' => true, 'message' => '✓ Pago de Binance verificado. Saldo acreditado al instante.'];
        }
        if (sbr_ref_ya_usada($pdo, $reportedRef, $digits, "referencia LIKE 'BINANCE:%'")) {
            return ['credited' => false, 'reused' => true, 'message' => '⚠ Esta referencia YA fue usada en un pago anterior (una compra en la tienda o otra recarga). No se puede usar dos veces.'];
        }
        sbr_log_sin_coincidencia($pdo, 'Binance', [
            'recarga' => $recId, 'metodo' => $metodoNombre !== '' ? $metodoNombre : 'Binance',
            'digitos' => $digits, 'ref_escrita' => $reportedRef,
            'monto_buscado' => $amount,
            'candidatos_con_ese_monto' => $hayCandidatos ? 'SI' : 'NO',
        ], "referencia LIKE 'BINANCE:%'");
        // Hay movimientos de Binance recientes con ESE MONTO exacto disponibles (no es demora de
        // sincronización: sbr_fetch_sync() ya corrió arriba), pero ninguno casa con la referencia
        // escrita → dato erróneo, rechazar claro (no dejar "pendiente" sonando a "ya va a llegar").
        if ($hayCandidatos) {
            return ['credited' => false, 'rejected' => true, 'message' => '✗ La referencia no coincide con ningún pago recibido por ese monto. Verifica el ID de transacción de Binance e intenta de nuevo.'];
        }
        // AMPLIACIÓN (pedido del cliente 30-ago): referencia INVENTADA que no casa ni por monto. Si la API
        // de Binance RESPONDIÓ con movimientos en esta corrida (viva) pero la referencia NO aparece en
        // ninguno reciente → dato falso → RECHAZAR. Si la API no respondió (caída/retraso), queda pendiente
        // (no rechaza un pago válido que aún no sincroniza).
        if ($apiRespondio) {
            try {
                $rr = $pdo->prepare("SELECT referencia FROM movimientos
                      WHERE referencia LIKE 'BINANCE:%' AND (fecha_movimiento IS NULL OR fecha_movimiento >= (NOW() - INTERVAL 3 DAY))
                      ORDER BY id DESC LIMIT 300");
                $rr->execute();
                $refAparece = false;
                foreach ($rr->fetchAll(PDO::FETCH_COLUMN) as $fullRef) { if (sbr_reference_matches((string) $fullRef, $reportedRef, $digits)) { $refAparece = true; break; } }
                if (!$refAparece) {
                    return ['credited' => false, 'rejected' => true, 'message' => '✗ Ese ID de transacción no aparece en los pagos de Binance recibidos. Verifícalo; si es correcto, espera 1-2 min y vuelve a intentar.'];
                }
            } catch (Throwable $e) {}
        }
        return ['credited' => false, 'message' => 'No encontramos tu pago todavía. Puede tardar 1-2 min; vuelve a intentar o el admin lo aprueba manual.'];
    }
}
