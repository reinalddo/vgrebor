<?php
/**
 * includes/bot_codigos.php — Integración con el BOT / página de CÓDIGOS.
 *
 * IDEA (pedida por el cliente): la tienda tiene stock de cuentas (cada una es un "correo"). Cuando a un
 * REVENDEDOR (identificado por SU correo, ej. v1) se le ENTREGA una cuenta (correo b) — al comprarla, al
 * asignársela el admin, o al aprobar una completa por activación — el bot debe ASIGNAR automáticamente el
 * correo b al correo v1, para que el revendedor lo consulte en la página de códigos. Cuando el admin le
 * ELIMINA esa cuenta (cambio de cuenta / no renovó / se borra), el bot debe DESASIGNARla.
 *
 * Los correos de revendedor = los mismos que registran en la página de códigos (usuarios.email).
 *
 * IMPORTANTE:
 *  - Best-effort: NUNCA debe romper la venta ni el borrado. Todo va en try/catch y devuelve bool.
 *  - Configurable: si NO está la URL del bot, no hace nada (queda listo para cuando la pongas).
 *  - Config (tabla `configuracion` o `configuracion_general`):
 *      bot_codigos_url    = URL del endpoint del bot (POST JSON).   ← sin esto, desactivado
 *      bot_codigos_token  = token/clave (opcional; va en el body y en Authorization: Bearer).
 *
 *  CONTRATO (según la propuesta de prycorreos api/external/asignaciones.php):
 *      POST application/json  ·  header: Authorization: Bearer <token>
 *      body: { "action": "asignar"|"desasignar", "account_email": "correo_b", "reseller_email": "v1" }
 *      (el TOKEN va SOLO en el header, NO en el body). Respuesta: { "success": true|false, "message": "..." }.
 *      OK = HTTP 200 con success=true; 409 (ya estaba asignada/desasignada) también se toma como OK (idempotente).
 */

if (!function_exists('bot_codigos_cfg')) {
    /** Lee una clave de config desde `configuracion` o `configuracion_general` (cacheado por request). */
    function bot_codigos_cfg(string $key, string $default = ''): string {
        static $cache = [];
        if (array_key_exists($key, $cache)) return $cache[$key];
        $val = '';
        try {
            $pdo = db();
            foreach (['configuracion', 'configuracion_general'] as $t) {
                try {
                    $st = $pdo->prepare("SELECT valor FROM $t WHERE clave=? LIMIT 1");
                    $st->execute([$key]);
                    $v = $st->fetchColumn();
                    if ($v !== false && $v !== null && (string) $v !== '') { $val = (string) $v; break; }
                } catch (Throwable $e) {}
            }
        } catch (Throwable $e) {}
        return $cache[$key] = ($val !== '' ? $val : $default);
    }
}

if (!function_exists('bot_codigos_enabled')) {
    /** ¿Está configurada la integración? (hay URL del bot). */
    function bot_codigos_enabled(): bool { return trim(bot_codigos_cfg('bot_codigos_url')) !== ''; }
}

if (!function_exists('bot_codigos_call')) {
    /** Llama al bot. $accion = 'asignar' | 'desasignar'. Devuelve true si respondió 2xx. Nunca lanza. */
    function bot_codigos_call(string $accion, string $correoCuenta, string $correoRevendedor): bool {
        $url = trim(bot_codigos_cfg('bot_codigos_url'));
        if ($url === '' || $correoCuenta === '' || $correoRevendedor === '') return false;
        $token = trim(bot_codigos_cfg('bot_codigos_token'));
        // El token va SOLO en el header (Authorization: Bearer), NO en el body — así lo pide la propuesta.
        $payload = json_encode([
            'action'         => $accion,
            'account_email'  => $correoCuenta,
            'reseller_email' => $correoRevendedor,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        try {
            if (!function_exists('curl_init')) return false;
            $headers = ['Content-Type: application/json', 'Accept: application/json'];
            if ($token !== '') $headers[] = 'Authorization: Bearer ' . $token;
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $payload,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 4,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_SSL_VERIFYPEER => false,
            ]);
            curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            // 2xx = OK. 409 = ya estaba en ese estado (idempotente) → también OK para nosotros.
            return ($code >= 200 && $code < 300) || $code === 409;
        } catch (Throwable $e) { return false; }
    }
}

if (!function_exists('bot_codigos_probar')) {
    /** PRUEBA la conexión al bot: hace un POST real y DEVUELVE el detalle (código HTTP + respuesta) para
     *  diagnosticar (si el bot responde, si el formato es el correcto, etc.). No lanza. */
    function bot_codigos_probar(string $correoCuenta = 'prueba@ejemplo.com', string $correoRev = 'revendedor@ejemplo.com'): array {
        $url = trim(bot_codigos_cfg('bot_codigos_url'));
        if ($url === '') return ['ok' => false, 'code' => 0, 'body' => '', 'error' => 'No hay URL del bot configurada.'];
        $token = trim(bot_codigos_cfg('bot_codigos_token'));
        // Token SOLO en el header; body solo action/account_email/reseller_email (como pide la propuesta).
        $payload = json_encode(['action' => 'asignar', 'account_email' => $correoCuenta, 'reseller_email' => $correoRev], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!function_exists('curl_init')) return ['ok' => false, 'code' => 0, 'body' => '', 'error' => 'cURL no está disponible en el servidor.'];
        try {
            $headers = ['Content-Type: application/json', 'Accept: application/json'];
            if ($token !== '') $headers[] = 'Authorization: Bearer ' . $token;
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload, CURLOPT_HTTPHEADER => $headers, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 6, CURLOPT_SSL_VERIFYPEER => false]);
            $body = curl_exec($ch);
            $err  = curl_error($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            return ['ok' => (($code >= 200 && $code < 300) || $code === 409), 'code' => $code, 'body' => (string) $body, 'error' => $err];
        } catch (Throwable $e) { return ['ok' => false, 'code' => 0, 'body' => '', 'error' => $e->getMessage()]; }
    }
}

if (!function_exists('bot_codigos_email_rev')) {
    /** Correo del revendedor (el mismo que usa en la página de códigos) por su id. */
    function bot_codigos_email_rev(PDO $pdo, int $revId): string {
        if ($revId <= 0) return '';
        try { return (string) ($pdo->query("SELECT email FROM usuarios WHERE id=" . (int) $revId)->fetchColumn() ?: ''); }
        catch (Throwable $e) { return ''; }
    }
}

if (!function_exists('bot_codigos_asignar')) {
    /** ASIGNA el correo de la cuenta al correo del revendedor en el bot. Best-effort. */
    function bot_codigos_asignar(PDO $pdo, string $correoCuenta, int $revId): bool {
        if (!bot_codigos_enabled() || trim($correoCuenta) === '' || $revId <= 0) return false;
        $email = bot_codigos_email_rev($pdo, $revId);
        if ($email === '') return false;
        return bot_codigos_call('asignar', trim($correoCuenta), $email);
    }
}

if (!function_exists('bot_codigos_desasignar')) {
    /** DESASIGNA el correo de la cuenta del revendedor en el bot. Best-effort. */
    function bot_codigos_desasignar(PDO $pdo, string $correoCuenta, int $revId): bool {
        if (!bot_codigos_enabled() || trim($correoCuenta) === '' || $revId <= 0) return false;
        $email = bot_codigos_email_rev($pdo, $revId);
        if ($email === '') return false;
        return bot_codigos_call('desasignar', trim($correoCuenta), $email);
    }
}

if (!function_exists('bot_codigos_flush')) {
    /** Envía las asignaciones PENDIENTES de un revendedor: una sola vez por cada cuenta espejo (correo)
     *  que aún NO se ha asignado (streaming_cuentas.bot_asignado = 0). Marca bot_asignado=1 al lograrlo.
     *
     *  POR QUÉ ASÍ: prycorreos CUENTA perfiles (+1 por asignar, -1 por desasignar, quita el acceso en 0).
     *  Si mandáramos "asignar" por CADA perfil (como antes), 2 perfiles del mismo correo dejaban el conteo
     *  en 2, pero al eliminar solo mandamos 1 "desasignar" (cuando llega a 0) → quedaba en 1 = perfil
     *  fantasma. Con la bandera, "asignar" se manda EXACTAMENTE UNA VEZ por (revendedor, correo) —cuando
     *  aparece su cuenta espejo por primera vez— y así cuadra 1↔1 con el "desasignar" del final.
     *
     *  Se llama DESPUÉS del commit (nunca dentro de una transacción): consulta solo cuentas ya guardadas,
     *  así una compra que se revirtió no manda nada. Idempotente y a prueba de reintentos. Nunca lanza. */
    function bot_codigos_flush(PDO $pdo, int $revId): int {
        if ($revId <= 0 || !bot_codigos_enabled()) return 0;
        $n = 0;
        try {
            $st = $pdo->prepare("SELECT id, correo FROM streaming_cuentas
                                  WHERE owner_id=? AND COALESCE(bot_asignado,0)=0
                                    AND correo IS NOT NULL AND correo<>''");
            $st->execute([$revId]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $correo = trim((string) ($r['correo'] ?? ''));
                if ($correo === '') continue;
                if (bot_codigos_asignar($pdo, $correo, $revId)) {
                    try { $pdo->prepare("UPDATE streaming_cuentas SET bot_asignado=1 WHERE id=?")->execute([(int) $r['id']]); } catch (Throwable $e) {}
                    $n++;
                }
            }
        } catch (Throwable $e) {}
        return $n;
    }
}
