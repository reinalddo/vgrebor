<?php
/**
 * includes/stream_mailer.php — CORREOS del módulo de STREAMING / REVENDEDORES.
 *
 * POR QUÉ EXISTE ESTE ARCHIVO (y no se reusa el de la tienda):
 * La tienda ya sabe mandar correos, pero esa maquinaria vive DENTRO de api/pedidos.php, que es un
 * ENDPOINT (rutea por $_GET['action'], toca pagos y usa mysqli), no una librería: incluirlo desde el
 * módulo de streaming ejecutaría ese ruteo, y extraerle las funciones obligaría a editar 13k líneas de
 * código de cobros que hoy funciona. Este archivo es 100% ADITIVO y autocontenido: usa PDO (como todo
 * el módulo de streaming), lee la MISMA configuración SMTP de la tienda y NO modifica nada existente.
 *
 * Todo aquí es "best-effort": ninguna función lanza excepciones ni corta el flujo del que la llama.
 * Si el correo falla, la venta/renovación/entrega sigue su curso igual (solo queda en el error_log).
 *
 * Prefijo stream_mail_* a propósito: evita colisionar con las funciones homónimas de api/pedidos.php
 * (send_app_mail, email_branding, resolve_admin_email…) si ambos se cargan en el mismo request.
 */

if (!function_exists('stream_mail_cfg')) {
    /** Lee una clave de config de la tienda: primero `configuracion_general`, luego `configuracion`.
     *  Mismo orden de prioridad que usa la tienda. Cacheado por request. Nunca lanza. */
    function stream_mail_cfg(PDO $pdo, string $clave, string $default = ''): string {
        static $cache = [];
        if (array_key_exists($clave, $cache)) return $cache[$clave];
        $val = '';
        foreach (['configuracion_general', 'configuracion'] as $tabla) {
            try {
                $st = $pdo->prepare("SELECT valor FROM $tabla WHERE clave=? LIMIT 1");
                $st->execute([$clave]);
                $v = $st->fetchColumn();
                if ($v !== false && $v !== null && trim((string) $v) !== '') { $val = (string) $v; break; }
            } catch (Throwable $e) {}
        }
        return $cache[$clave] = ($val !== '' ? $val : $default);
    }
}

if (!function_exists('stream_mail_settings')) {
    /** Credenciales SMTP de la tienda, normalizadas igual que load_mail_settings() de api/pedidos.php. */
    function stream_mail_settings(PDO $pdo): array {
        $s = [
            'correo_corporativo' => stream_mail_cfg($pdo, 'correo_corporativo'),
            'smtp_host'          => stream_mail_cfg($pdo, 'smtp_host'),
            'smtp_user'          => stream_mail_cfg($pdo, 'smtp_user'),
            'smtp_pass'          => stream_mail_cfg($pdo, 'smtp_pass'),
            'smtp_port'          => stream_mail_cfg($pdo, 'smtp_port', '587'),
            'smtp_secure'        => stream_mail_cfg($pdo, 'smtp_secure', 'tls'),
        ];
        $s['smtp_port'] = (int) ($s['smtp_port'] ?: 587);
        $s['smtp_secure'] = strtolower(trim((string) $s['smtp_secure']));
        if (!in_array($s['smtp_secure'], ['ssl', 'tls'], true)) { $s['smtp_secure'] = 'tls'; }
        return $s;
    }
}

if (!function_exists('stream_mail_admin_email')) {
    /** Correo del ADMIN de la tienda (para la copia de cada operación). Misma prioridad que la tienda:
     *  correo corporativo → usuario SMTP → primer usuario con rol admin. null si no hay ninguno válido. */
    function stream_mail_admin_email(PDO $pdo): ?string {
        $s = stream_mail_settings($pdo);
        foreach (['correo_corporativo', 'smtp_user'] as $k) {
            $c = trim((string) ($s[$k] ?? ''));
            if ($c !== '' && filter_var($c, FILTER_VALIDATE_EMAIL)) return $c;
        }
        try {
            $e = (string) ($pdo->query("SELECT email FROM usuarios WHERE rol='admin' AND email IS NOT NULL AND email<>'' ORDER BY id ASC LIMIT 1")->fetchColumn() ?: '');
            if ($e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL)) return $e;
        } catch (Throwable $e) {}
        return null;
    }
}

if (!function_exists('stream_mail_user_email')) {
    /** Correo de un usuario de la web (revendedor/admin) por id. '' si no tiene o no es válido. */
    function stream_mail_user_email(PDO $pdo, int $userId): string {
        if ($userId <= 0) return '';
        try {
            $e = trim((string) ($pdo->query("SELECT email FROM usuarios WHERE id=" . (int) $userId)->fetchColumn() ?: ''));
            return filter_var($e, FILTER_VALIDATE_EMAIL) ? $e : '';
        } catch (Throwable $e) { return ''; }
    }
}

if (!function_exists('stream_mail_e')) {
    /** Escape para HTML de correo. */
    function stream_mail_e(?string $v): string {
        return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('stream_mail_host_es_publico')) {
    /** ¿El dominio actual es público? En local (WAMP/localhost/IP privada) NO se manda correo: se
     *  registra en el log y se sigue. Evita que el SMTP cuelgue el panel en desarrollo. */
    function stream_mail_host_es_publico(string $host): bool {
        $host = strtolower(trim($host, '[] '));
        if ($host === '' || $host === 'localhost' || $host === '::1') return false;
        if (!str_contains($host, '.')) return false;
        if (str_contains($host, '.local') || str_contains($host, '.test')) return false;
        if (preg_match('/^127\./', $host) === 1 || preg_match('/^10\./', $host) === 1 || preg_match('/^192\.168\./', $host) === 1) return false;
        if (preg_match('/^172\.(1[6-9]|2[0-9]|3[0-1])\./', $host) === 1) return false;
        return true;
    }
}

if (!function_exists('stream_mail_branding')) {
    /** Marca de la TIENDA para el encabezado del correo (nombre, prefijo y logo).
     *  El logo se INCRUSTA (cid) si es un archivo local: así se ve aunque el cliente de correo
     *  bloquee imágenes remotas. Los .webp se convierten a .png porque casi ningún cliente de
     *  correo (Gmail, Outlook) renderiza webp; si no se puede convertir, se omite el logo. */
    function stream_mail_branding(PDO $pdo): array {
        static $cache = null;
        if ($cache !== null) return $cache;
        $nombre  = trim(stream_mail_cfg($pdo, 'nombre_tienda', 'Streaming')) ?: 'Streaming';
        $prefijo = trim(stream_mail_cfg($pdo, 'nombre_prefijo', 'TIENDA')) ?: 'TIENDA';
        $logoCfg = trim(stream_mail_cfg($pdo, 'logo_tienda', ''));
        $logoPath = '';
        if ($logoCfg !== '' && !preg_match('#^https?://#i', $logoCfg)) {
            $rel = (string) (parse_url($logoCfg, PHP_URL_PATH) ?: $logoCfg);
            $abs = dirname(__DIR__) . DIRECTORY_SEPARATOR . ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $rel), DIRECTORY_SEPARATOR);
            if (is_file($abs)) { $logoPath = $abs; }
        }
        if ($logoPath !== '' && strtolower(pathinfo($logoPath, PATHINFO_EXTENSION)) === 'webp') {
            $png = '';
            if (function_exists('imagecreatefromwebp') && function_exists('imagepng')) {
                $tmp = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
                     . 'stream-mail-logo-' . sha1($logoPath . '|' . (string) @filemtime($logoPath)) . '.png';
                if (is_file($tmp)) { $png = $tmp; }
                else {
                    $img = @imagecreatefromwebp($logoPath);
                    if ($img !== false) {
                        if (function_exists('imagepalettetotruecolor')) { @imagepalettetotruecolor($img); }
                        @imagesavealpha($img, true);
                        if (@imagepng($img, $tmp, 6)) { $png = $tmp; }
                        @imagedestroy($img);
                    }
                }
            }
            $logoPath = $png;   // '' si no se pudo convertir → se muestra solo el nombre
        }
        return $cache = ['nombre' => $nombre, 'prefijo' => $prefijo, 'logo_path' => $logoPath];
    }
}

if (!function_exists('stream_mail_send')) {
    /** Envía un correo HTML. Devuelve true si salió. NUNCA lanza: si falla, solo deja rastro en el log.
     *  $logoPath (opcional) se incrusta como cid:stream-logo. */
    function stream_mail_send(PDO $pdo, string $para, string $asunto, string $html, string $logoPath = ''): bool {
        $para = trim($para);
        if ($para === '' || !filter_var($para, FILTER_VALIDATE_EMAIL)) return false;

        // En local no se manda nada (el SMTP colgaría el panel de desarrollo).
        $host = '';
        if (function_exists('app_url')) { $host = (string) parse_url(app_url('/'), PHP_URL_HOST); }
        elseif (isset($_SERVER['HTTP_HOST'])) { $host = (string) $_SERVER['HTTP_HOST']; }
        if ($host !== '' && !stream_mail_host_es_publico($host)) {
            error_log('stream_mail omitido en host local (' . $host . '): ' . $asunto . ' → ' . $para);
            return false;
        }

        $s = stream_mail_settings($pdo);
        $desde = trim((string) ($s['correo_corporativo'] ?: $s['smtp_user']));
        if (trim((string) $s['smtp_host']) === '' || $desde === '') {
            error_log('stream_mail sin SMTP configurado: ' . $asunto . ' → ' . $para);
            return false;
        }
        $marca = stream_mail_branding($pdo);

        try {
            require_once __DIR__ . '/PHPMailerAutoload.php';
            if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
                throw new RuntimeException('PHPMailer no disponible');
            }
            $mail = new PHPMailer\PHPMailer\PHPMailer(true);
            $mail->CharSet = 'UTF-8';
            $mail->isSMTP();
            $mail->Host       = $s['smtp_host'];
            $mail->SMTPAuth   = true;
            $mail->Username   = $s['smtp_user'];
            $mail->Password   = $s['smtp_pass'];
            $mail->SMTPSecure = $s['smtp_secure'];
            $mail->Port       = $s['smtp_port'];
            $mail->Timeout    = 12;
            $mail->Timelimit  = 12;
            $mail->SMTPKeepAlive = false;
            $mail->setFrom($desde, $marca['nombre']);
            $mail->addAddress($para);
            if ($logoPath !== '' && is_file($logoPath)) {
                $mail->addEmbeddedImage($logoPath, 'stream-logo', basename($logoPath), 'base64', 'image/png');
            }
            $mail->isHTML(true);
            $mail->Subject = $asunto;
            $mail->Body    = $html;
            $mail->send();
            return true;
        } catch (Throwable $e) {
            error_log('stream_mail error (' . $asunto . ' → ' . $para . '): ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('stream_mail_layout')) {
    /** Plantilla HTML del correo, en el mismo estilo visual que los correos de pedidos de la tienda.
     *
     *  $opts:
     *    titulo    (string)  Título grande del correo.
     *    eyebrow   (string)  Etiqueta pequeña arriba ("CLIENTE", "REVENDEDOR", "ADMINISTRADOR"…).
     *    intro     (string)  Párrafo de entrada — HTML ya escapado por quien llama.
     *    encabezado(string)  Título de la tabla de datos (ej. "Netflix · RBX-000123").
     *    filas     (array)   [ ['Etiqueta', 'Valor'], ... ]  Se omiten las de valor vacío.
     *    destacado (string)  Fila resaltada con el acento (ej. el total o la fecha). Formato ['Etiqueta','Valor'].
     *    nota      (string)  Aviso al pie (opcional) — HTML ya escapado por quien llama.
     *    acento    (string)  Color de acento. Por defecto el turquesa de la tienda.
     */
    function stream_mail_layout(PDO $pdo, array $opts): string {
        $marca   = stream_mail_branding($pdo);
        $acento  = (string) ($opts['acento'] ?? '#22d3ee');
        $titulo  = stream_mail_e((string) ($opts['titulo'] ?? ''));
        $eyebrow = stream_mail_e((string) ($opts['eyebrow'] ?? ''));
        $intro   = (string) ($opts['intro'] ?? '');
        $encab   = stream_mail_e((string) ($opts['encabezado'] ?? ''));
        $nota    = (string) ($opts['nota'] ?? '');

        $bordeFila = 'border-bottom:1px solid #1e293b;';
        $tdL = 'padding:10px 0 10px 20px;color:#94a3b8;font-size:14px;' . $bordeFila;
        $tdR = 'padding:10px 20px 10px 0;color:#e2e8f0;font-size:14px;text-align:right;' . $bordeFila;

        $filasHtml = '';
        foreach ((array) ($opts['filas'] ?? []) as $fila) {
            $et = trim((string) ($fila[0] ?? ''));
            $va = trim((string) ($fila[1] ?? ''));
            if ($et === '' || $va === '') continue;   // no se pintan datos vacíos
            $filasHtml .= '<tr><td style="' . $tdL . '">' . stream_mail_e($et) . '</td>'
                        . '<td style="' . $tdR . 'font-family:Consolas,Menlo,monospace;">' . stream_mail_e($va) . '</td></tr>';
        }
        $dest = (array) ($opts['destacado'] ?? []);
        if (trim((string) ($dest[1] ?? '')) !== '') {
            $filasHtml .= '<tr><td style="' . $tdL . '">' . stream_mail_e((string) $dest[0]) . '</td>'
                        . '<td style="padding:10px 20px 10px 0;color:' . $acento . ';font-size:18px;font-weight:700;text-align:right;' . $bordeFila . '">'
                        . stream_mail_e((string) $dest[1]) . '</td></tr>';
        }

        $logoHtml = $marca['logo_path'] !== ''
            ? '<div style="margin:0 auto 16px;width:72px;height:72px;border-radius:18px;overflow:hidden;border:1px solid rgba(103,232,249,0.65);background:rgba(8,15,24,0.65);">'
              . '<img src="cid:stream-logo" alt="' . stream_mail_e($marca['nombre']) . '" style="display:block;width:100%;height:100%;object-fit:cover;">'
              . '</div>'
            : '';

        $tablaHtml = $filasHtml !== ''
            ? '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;background:#0f172a;border:1px solid #1e293b;border-radius:16px;overflow:hidden;">'
              . ($encab !== '' ? '<tr><td colspan="2" style="padding:16px 20px;background:#0b1220;color:#67e8f9;font-size:16px;font-weight:700;">' . $encab . '</td></tr>' : '')
              . $filasHtml
              . '</table>'
            : '';

        $notaHtml = $nota !== ''
            ? '<div style="margin-top:20px;padding:14px 16px;background:rgba(245,158,11,0.08);border:1px solid rgba(245,158,11,0.35);border-radius:12px;color:#fcd34d;font-size:13px;line-height:1.6;">' . $nota . '</div>'
            : '';

        return '<!doctype html>'
            . '<html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>' . $titulo . '</title></head>'
            . '<body style="margin:0;padding:0;background:#0a0f14;font-family:Arial,Helvetica,sans-serif;color:#e2e8f0;">'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#0a0f14;padding:24px 12px;">'
            . '<tr><td align="center">'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:640px;background:#111827;border:1px solid #164e63;border-radius:20px;overflow:hidden;">'
            . '<tr><td style="padding:28px 32px;background:linear-gradient(135deg,#0b1220 0%,#102133 55%,#0f3b46 100%);text-align:center;">'
            . $logoHtml
            . '<div style="color:#67e8f9;font-size:12px;letter-spacing:4px;text-transform:uppercase;margin-bottom:10px;">' . stream_mail_e($marca['prefijo']) . '</div>'
            . '<div style="color:#ffffff;font-size:30px;line-height:1.2;font-weight:700;margin-bottom:8px;">' . stream_mail_e($marca['nombre']) . '</div>'
            . ($eyebrow !== '' ? '<div style="display:inline-block;padding:6px 14px;border:1px solid ' . $acento . ';border-radius:999px;color:' . $acento . ';font-size:12px;font-weight:700;letter-spacing:1px;text-transform:uppercase;">' . $eyebrow . '</div>' : '')
            . '</td></tr>'
            . '<tr><td style="padding:32px;">'
            . '<h1 style="margin:0 0 14px;color:#f8fafc;font-size:26px;line-height:1.25;">' . $titulo . '</h1>'
            . ($intro !== '' ? '<div style="color:#cbd5e1;font-size:15px;line-height:1.7;margin-bottom:24px;">' . $intro . '</div>' : '')
            . $tablaHtml
            . $notaHtml
            . '<div style="margin-top:24px;padding:16px 18px;background:#0b1220;border:1px solid #1e293b;border-radius:14px;color:#94a3b8;font-size:13px;line-height:1.6;">'
            . 'Este correo fue generado automáticamente por ' . stream_mail_e($marca['nombre']) . '. Si necesitas ayuda, responde por los canales de soporte configurados.'
            . '</div>'
            . '</td></tr>'
            . '</table>'
            . '</td></tr>'
            . '</table>'
            . '</body></html>';
    }
}
