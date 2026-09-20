<?php
// Herramienta de SOLO LECTURA para diagnosticar "No se pudo consultar" / "Failed to connect to
// panel.recargasamerica.com port 443" (saldo del dashboard y selector de productos de paquetes).
// No compra nada, no modifica nada y por defecto NO envía NINGUNA solicitud a la API de
// RecargasAmérica: la prueba HTTPS es "solo conectar" (TCP + saludo TLS, sin pedir ninguna URL).
// Esto es a propósito: RecargasAmérica banea la IP del servidor si ve varios intentos seguidos sin
// credenciales o con credenciales incorrectas (lo trata como fuerza bruta), así que abrir esta
// página —aunque sea varias veces— nunca debe generar tráfico que ese sistema pueda contar.
// La prueba con la API KEY guardada solo corre si se pide con ?probar_clave=1 (una única solicitud).
//
// Separa las causas posibles: DNS, conexión TCP al puerto 443, HTTPS con cURL, y un sitio de control
// (para distinguir "el servidor no tiene salida a internet" de "bloqueo solo hacia RecargasAmérica").
// De paso muestra la IP pública de SALIDA del servidor (la que RecargasAmérica ve y podría bloquear
// o pedir en su lista de IPs permitidas).
require_once __DIR__ . '/../includes/tenant.php';
tenant_start_session();
$adminRole = trim((string) ($_SESSION['auth_user']['rol'] ?? ''));
if (!isset($_SESSION['auth_user']) || !in_array($adminRole, ['admin', 'root'], true)) {
    header('Location: ' . app_path('/login.php'));
    exit();
}
require_once __DIR__ . '/../includes/auth.php';
csrf_verify_soft();
require_once __DIR__ . '/../includes/store_config.php';
require_once __DIR__ . '/../includes/recargasamerica_api.php';

// Reiniciar el corte automático (includes/recargasamerica_api.php): útil cuando RecargasAmérica
// avisa que ya desbloqueó la IP y no se quiere esperar a que venza el tiempo de espera.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['reset_breaker'])) {
    recargasamerica_breaker_reset();
    header('Location: ' . strtok((string) ($_SERVER['REQUEST_URI'] ?? ''), '?'));
    exit;
}

@set_time_limit(120);

const RA_DIAG_HOST = 'panel.recargasamerica.com';

function ra_diag_e(string $text): string {
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

// GET por cURL sin clave. Devuelve el detalle completo del intento.
function ra_diag_curl(string $url, bool $forceIpv4 = false, bool $connectOnly = false): array {
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'error' => 'La extensión cURL de PHP no está disponible.', 'errno' => -1];
    }
    $ch = curl_init($url);
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ];
    if ($forceIpv4 && defined('CURL_IPRESOLVE_V4')) {
        $options[CURLOPT_IPRESOLVE] = CURL_IPRESOLVE_V4;
    }
    if ($connectOnly) {
        // Solo TCP + saludo TLS: no se envía ninguna petición HTTP.
        $options[CURLOPT_CONNECT_ONLY] = true;
    }
    curl_setopt_array($ch, $options);
    $body = curl_exec($ch);
    $info = [
        'ok' => $body !== false,
        'http' => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE),
        'errno' => curl_errno($ch),
        'error' => curl_error($ch),
        'ip' => (string) curl_getinfo($ch, CURLINFO_PRIMARY_IP),
        't_dns' => round((float) curl_getinfo($ch, CURLINFO_NAMELOOKUP_TIME) * 1000),
        't_connect' => round((float) curl_getinfo($ch, CURLINFO_CONNECT_TIME) * 1000),
        't_total' => round((float) curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000),
        'body' => is_string($body) ? $body : '',
    ];
    curl_close($ch);
    return $info;
}

// 1) DNS
$dnsIps = @gethostbynamel(RA_DIAG_HOST) ?: [];

// 2) TCP directo al 443 de cada IP resuelta
$tcpResults = [];
foreach ($dnsIps as $ip) {
    $start = microtime(true);
    $errno = 0;
    $errstr = '';
    $socket = @fsockopen($ip, 443, $errno, $errstr, 8);
    $tcpResults[] = [
        'ip' => $ip,
        'ok' => is_resource($socket),
        'ms' => round((microtime(true) - $start) * 1000),
        'errno' => $errno,
        'error' => $errstr,
    ];
    if (is_resource($socket)) {
        fclose($socket);
    }
}

// 3) HTTPS con cURL en modo "solo conectar": no se envía ninguna solicitud a la API
$curlRa = ra_diag_curl('https://' . RA_DIAG_HOST . '/', false, true);
$curlRaV4 = ra_diag_curl('https://' . RA_DIAG_HOST . '/', true, true);

// 4) Sitio de control + IP pública de salida del servidor
// RecargasAmérica solo tiene IPv4: la IP que ella ve es la de salida por IPv4
// (puede ser distinta de la IPv6 que el servidor use por defecto hacia otros sitios).
$control = ra_diag_curl('https://www.cloudflare.com/cdn-cgi/trace');
$controlV4 = ra_diag_curl('https://www.cloudflare.com/cdn-cgi/trace', true);
$outboundIpDefault = '';
if ($control['ok'] && preg_match('/^ip=(.+)$/m', $control['body'], $m)) {
    $outboundIpDefault = trim($m[1]);
}
$outboundIp = '';
if ($controlV4['ok'] && preg_match('/^ip=(.+)$/m', $controlV4['body'], $m)) {
    $outboundIp = trim($m[1]);
}
$outboundIpIsV6 = $outboundIpDefault !== '' && strpos($outboundIpDefault, ':') !== false;
// Milisegundos de la conexión rechazada: ~0-5 ms = lo rechaza el propio servidor/hosting;
// decenas de ms = el rechazo viene desde el otro extremo (RecargasAmérica o su red).
$refusedMs = null;
foreach ($tcpResults as $tcp) {
    if (!$tcp['ok']) {
        $refusedMs = (int) $tcp['ms'];
        break;
    }
}

// 5) Con la clave guardada (solo responde / no responde)
$keyConfigured = recargasamerica_api_is_configured();
$keyResult = ['ran' => false, 'ok' => false, 'message' => ''];
$keyRequested = (string) ($_GET['probar_clave'] ?? '') === '1';
// Solo si se pide expresamente Y la conexión ya llega: una sola solicitud autenticada.
if ($keyConfigured && $keyRequested && ($curlRa['ok'] || $curlRaV4['ok'])) {
    $keyResult['ran'] = true;
    try {
        $wallet = recargasamerica_api_fetch_wallet();
        $keyResult['ok'] = true;
        $keyResult['message'] = 'Respondió correctamente (saldo: ' . number_format((float) $wallet['balance'], 2, '.', '') . ' ' . $wallet['currency'] . ').';
    } catch (Throwable $e) {
        $keyResult['message'] = $e->getMessage();
    }
}

// Conclusión
$tcpAnyOk = false;
foreach ($tcpResults as $tcp) {
    if ($tcp['ok']) {
        $tcpAnyOk = true;
    }
}
$raReachable = $curlRa['ok'] || $curlRaV4['ok'];
if (empty($dnsIps)) {
    $verdict = ['bad', 'El servidor no logra resolver el nombre panel.recargasamerica.com (DNS).', 'Pídele a tu hosting que revise la resolución de nombres (DNS) del servidor.'];
} elseif ($raReachable && $keyResult['ok']) {
    $verdict = ['good', 'La conexión con RecargasAmérica funciona en este momento.', 'Si el aviso rojo del saldo sigue apareciendo, recarga el dashboard; ya debería mostrar el saldo.'];
} elseif ($raReachable && $keyConfigured && !$keyResult['ran']) {
    $verdict = ['good', 'La conexión con RecargasAmérica llega: el servidor ya puede comunicarse con ellos.', 'Para confirmar que también acepta tu clave, pulsa "Probar la clave guardada" más abajo (envía UNA sola solicitud). Luego revisa el saldo en el dashboard.'];
} elseif ($raReachable && $keyConfigured && !$keyResult['ok']) {
    $verdict = ['warn', 'La conexión llega, pero RecargasAmérica rechazó la clave guardada.', 'Revisa que la API KEY de la configuración sea una clave ACTIVA en el panel de RecargasAmérica (sección "Mis claves API"), o que la IP de este servidor esté permitida allí.'];
} elseif ($raReachable) {
    $verdict = ['warn', 'La conexión con RecargasAmérica llega, pero no hay API KEY guardada en la configuración.', 'Pega la API KEY (ra_...) en Configuración.'];
} elseif (!$control['ok']) {
    $verdict = ['bad', 'El servidor no puede salir a internet por HTTPS (ni siquiera a un sitio de control).', 'Es un bloqueo del hosting: pídele a Hostinger que permita las conexiones salientes por el puerto 443.'];
} elseif (!$tcpAnyOk) {
    $originHint = ($refusedMs !== null && $refusedMs >= 20)
        ? 'El rechazo tardó ' . $refusedMs . ' ms (ida y vuelta hasta el otro extremo): lo más probable es que RecargasAmérica (o su red) esté rechazando la IP de este servidor.'
        : 'El rechazo fue casi inmediato: lo más probable es un firewall del propio hosting.';
    $verdict = ['bad', 'El servidor sí sale a internet, pero la conexión al puerto 443 de RecargasAmérica es rechazada al instante.', $originHint . ' La IP (IPv4) con la que este servidor sale hacia RecargasAmérica es ' . ($outboundIp !== '' ? $outboundIp : '(no se pudo obtener: pídesela al hosting)') . '. Escribe a ambos con los datos de esta página.'];
} else {
    $verdict = ['warn', 'La conexión TCP funciona pero falla el HTTPS.', 'Puede ser un problema de certificados (SSL) del servidor. Copia los datos de esta página y envíalos al hosting.'];
}
$verdictColors = ['good' => ['#052e1a', '#22c55e', '#bbf7d0'], 'warn' => ['#3b2a05', '#f59e0b', '#fde68a'], 'bad' => ['#3b1010', '#ef4444', '#fecaca']];
[$vBg, $vBorder, $vText] = $verdictColors[$verdict[0]];
$curlVersion = function_exists('curl_version') ? (curl_version()['version'] ?? '') . ' / ' . (curl_version()['ssl_version'] ?? '') : 'sin cURL';
?><!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Diagnóstico de conexión con RecargasAmérica</title>
<style>
  body { background:#0b1220; color:#e2e8f0; font-family:system-ui,Segoe UI,Arial,sans-serif; margin:0; padding:24px 16px; line-height:1.5; }
  main { max-width:860px; margin:0 auto; }
  h1 { font-size:1.4rem; margin:0 0 4px; color:#22d3ee; }
  h2 { font-size:1.02rem; margin:26px 0 8px; color:#8be9fd; }
  p.note { color:#94a3b8; font-size:.9rem; margin:0 0 16px; }
  table { width:100%; border-collapse:collapse; font-size:.9rem; }
  th, td { text-align:left; padding:7px 10px; border-bottom:1px solid #1e293b; vertical-align:top; word-break:break-word; }
  th { color:#8be9fd; font-weight:600; white-space:nowrap; }
  .ok { color:#4ade80; font-weight:700; } .fail { color:#f87171; font-weight:700; }
  .verdict { border-radius:12px; padding:14px 16px; margin:16px 0; }
  code { background:#111c30; padding:1px 6px; border-radius:6px; }
  pre { background:#111c30; padding:12px; border-radius:10px; overflow:auto; font-size:.85rem; white-space:pre-wrap; }
  a { color:#22d3ee; }
</style>
</head>
<body>
<main>
  <h1>Diagnóstico de conexión con RecargasAmérica</h1>
  <p class="note">Solo lectura: no compra ni modifica nada. Las pruebas de red no envían tu API KEY. Recarga la página para repetir las pruebas. <a href="<?= ra_diag_e(app_path('/admin/dashboard')) ?>">← Volver al panel</a></p>

  <div class="verdict" style="background:<?= $vBg ?>;border:1px solid <?= $vBorder ?>;color:<?= $vText ?>;">
    <strong><?= ra_diag_e($verdict[1]) ?></strong><br>
    <?= ra_diag_e($verdict[2]) ?>
  </div>

  <h2>Corte automático del sitio hacia RecargasAmérica</h2>
  <?php $breakerRows = recargasamerica_breaker_status(); ?>
  <table>
    <?php if (empty($breakerRows)): ?>
      <tr><td><span class="ok">Sin cortes activos</span> — el sitio está intentando conectar con normalidad.</td></tr>
    <?php endif; ?>
    <?php foreach ($breakerRows as $bRow): ?>
      <tr>
        <th><?= ra_diag_e($bRow['key'] === 'global' ? 'Toda la conexión' : 'Solo ' . preg_replace('/^scope:/', '', $bRow['key'])) ?></th>
        <td>
          <?php if ($bRow['open']): ?>
            <span class="fail">en pausa hasta las <?= ra_diag_e(date('H:i', $bRow['until'])) ?></span>
          <?php else: ?>
            <span style="color:#fbbf24;">listo para reintentar (la próxima solicitud hace de prueba)</span>
          <?php endif; ?>
          — <?= ra_diag_e($bRow['reason']) ?> · fallos seguidos: <?= (int) $bRow['strikes'] ?>
        </td>
      </tr>
    <?php endforeach; ?>
  </table>
  <p class="note">Cuando RecargasAmérica falla de forma definitiva (no conecta, clave inválida, IP bloqueada), el sitio deja de llamarlos un rato para no alargar el bloqueo. Al cambiar la API KEY en Configuración, la pausa se reinicia sola.</p>
  <?php if (!empty($breakerRows)): ?>
    <form method="post" style="margin:0 0 8px;">
      <input type="hidden" name="reset_breaker" value="1">
      <button type="submit" style="background:#111c30;color:#22d3ee;border:1px solid #22d3ee;border-radius:8px;padding:6px 12px;cursor:pointer;" onclick="return confirm('¿Quitar la pausa y volver a intentar conectar ahora? Hazlo solo cuando RecargasAmérica confirme que ya desbloqueó la IP o cuando hayas corregido la API KEY.')">Quitar la pausa ahora</button>
    </form>
  <?php endif; ?>

  <h2>IP pública de salida de este servidor</h2>
  <table>
    <tr><th>IPv4 (la que ve RecargasAmérica)</th><td><?= $outboundIp !== '' ? '<strong>' . ra_diag_e($outboundIp) . '</strong>' : '<span class="fail">no se pudo obtener</span>' ?></td></tr>
    <tr><th>IP por defecto del servidor</th><td><?= $outboundIpDefault !== '' ? ra_diag_e($outboundIpDefault) . ($outboundIpIsV6 ? ' <span style="color:#94a3b8;">(IPv6: RecargasAmérica solo usa IPv4, esta no sirve para pedirles un desbloqueo)</span>' : '') : '<span class="fail">no se pudo obtener</span>' ?></td></tr>
  </table>
  <p class="note">Si RecargasAmérica bloqueó una IP o tiene una lista de IPs permitidas, es la IPv4 de arriba.</p>

  <h2>1. Nombre (DNS)</h2>
  <table>
    <tr><th><?= ra_diag_e(RA_DIAG_HOST) ?></th><td><?= !empty($dnsIps) ? '<span class="ok">resuelve</span> → ' . ra_diag_e(implode(', ', $dnsIps)) : '<span class="fail">no resuelve</span>' ?></td></tr>
  </table>

  <h2>2. Conexión directa al puerto 443</h2>
  <table>
    <?php if (empty($tcpResults)): ?>
      <tr><td>Sin IPs que probar.</td></tr>
    <?php endif; ?>
    <?php foreach ($tcpResults as $tcp): ?>
      <tr>
        <th><?= ra_diag_e($tcp['ip']) ?>:443</th>
        <td><?= $tcp['ok'] ? '<span class="ok">conecta</span>' : '<span class="fail">NO conecta</span> — ' . ra_diag_e($tcp['error'] !== '' ? $tcp['error'] : 'sin detalle') . ' (código ' . (int) $tcp['errno'] . ')' ?> · <?= (int) $tcp['ms'] ?> ms</td>
      </tr>
    <?php endforeach; ?>
  </table>

  <h2>3. Conexión segura (HTTPS) — solo se conecta, no se envía ninguna solicitud</h2>
  <table>
    <?php foreach ([['Normal', $curlRa], ['Forzando IPv4', $curlRaV4]] as [$label, $r]): ?>
      <tr>
        <th><?= ra_diag_e($label) ?></th>
        <td>
          <?php if ($r['ok']): ?>
            <span class="ok">conexión segura establecida</span> · IP <?= ra_diag_e($r['ip']) ?> · conexión <?= (int) $r['t_connect'] ?> ms · total <?= (int) $r['t_total'] ?> ms
          <?php else: ?>
            <span class="fail">falló</span> (código cURL <?= (int) $r['errno'] ?>): <?= ra_diag_e($r['error']) ?>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
  </table>

  <h2>4. Sitio de control (¿el servidor sale a internet?)</h2>
  <table>
    <tr><th>www.cloudflare.com</th><td><?= $control['ok'] ? '<span class="ok">responde</span> (HTTP ' . (int) $control['http'] . ')' : '<span class="fail">falló</span>: ' . ra_diag_e($control['error']) ?></td></tr>
    <tr><th>www.cloudflare.com (solo IPv4)</th><td><?= $controlV4['ok'] ? '<span class="ok">responde</span> (HTTP ' . (int) $controlV4['http'] . ')' : '<span class="fail">falló</span>: ' . ra_diag_e($controlV4['error']) ?></td></tr>
  </table>

  <h2>5. Con la API KEY guardada</h2>
  <table>
    <tr><th>Saldo (/wallet)</th><td>
      <?php if (!$keyConfigured): ?>
        <span class="fail">no hay API KEY guardada</span>
      <?php elseif (!$keyResult['ran']): ?>
        <?php if (!$raReachable): ?>
          <span style="color:#94a3b8;">No se probó: primero tiene que llegar la conexión (paso 3).</span>
        <?php else: ?>
          <span style="color:#94a3b8;">No se probó todavía (para no generar solicitudes de más).</span><br>
          <a href="?probar_clave=1" style="display:inline-block;margin-top:6px;padding:6px 12px;border:1px solid #22d3ee;border-radius:8px;text-decoration:none;">Probar la clave guardada (envía 1 sola solicitud)</a>
        <?php endif; ?>
      <?php elseif ($keyResult['ok']): ?>
        <span class="ok"><?= ra_diag_e($keyResult['message']) ?></span>
      <?php else: ?>
        <span class="fail"><?= ra_diag_e($keyResult['message']) ?></span>
      <?php endif; ?>
    </td></tr>
  </table>

  <h2>Resumen para copiar y enviar al hosting / a RecargasAmérica</h2>
<pre>Fecha: <?= ra_diag_e(date('Y-m-d H:i:s')) ?> (<?= ra_diag_e(date_default_timezone_get()) ?>)
IP de salida IPv4 (la que ve RecargasAmerica): <?= ra_diag_e($outboundIp !== '' ? $outboundIp : 'no disponible') ?>

IP de salida por defecto: <?= ra_diag_e($outboundIpDefault !== '' ? $outboundIpDefault : 'no disponible') ?>

DNS <?= ra_diag_e(RA_DIAG_HOST) ?>: <?= ra_diag_e(!empty($dnsIps) ? implode(', ', $dnsIps) : 'no resuelve') ?>

TCP 443: <?php foreach ($tcpResults as $tcp): ?><?= ra_diag_e($tcp['ip']) ?> => <?= $tcp['ok'] ? 'conecta' : 'FALLA (' . ($tcp['error'] !== '' ? $tcp['error'] : 'sin detalle') . ', codigo ' . (int) $tcp['errno'] . ')' ?> en <?= (int) $tcp['ms'] ?> ms; <?php endforeach; ?>

HTTPS (solo conexion): <?= $curlRa['ok'] ? 'OK' : 'FALLA (' . (int) $curlRa['errno'] . ': ' . $curlRa['error'] . ')' ?>

HTTPS IPv4 (solo conexion): <?= $curlRaV4['ok'] ? 'OK' : 'FALLA (' . (int) $curlRaV4['errno'] . ': ' . $curlRaV4['error'] . ')' ?>

Sitio de control (cloudflare): <?= $control['ok'] ? 'responde' : 'FALLA (' . $control['error'] . ')' ?> · solo IPv4: <?= $controlV4['ok'] ? 'responde' : 'FALLA (' . $controlV4['error'] . ')' ?>

Software: PHP <?= ra_diag_e(PHP_VERSION) ?> · cURL <?= ra_diag_e($curlVersion) ?>
</pre>
</main>
</body>
</html>
