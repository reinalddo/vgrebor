<?php
// Herramienta de SOLO LECTURA para diagnosticar "No se pudo consultar" / "Failed to connect to
// panel.recargasamerica.com port 443" (saldo del dashboard y selector de productos de paquetes).
// No compra nada, no modifica nada y NO envía la API KEY en las pruebas de red (la prueba de
// conectividad usa /api/v1/wallet sin clave: una respuesta 401 significa que la conexión SÍ llega).
// Solo la última verificación usa la clave guardada, y únicamente para saber si responde o no.
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

@set_time_limit(120);

const RA_DIAG_HOST = 'panel.recargasamerica.com';

function ra_diag_e(string $text): string {
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

// GET por cURL sin clave. Devuelve el detalle completo del intento.
function ra_diag_curl(string $url, bool $forceIpv4 = false): array {
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
        'body' => $body === false ? '' : (string) $body,
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

// 3) HTTPS con cURL (sin clave): 401 = la conexión llega
$curlRa = ra_diag_curl('https://' . RA_DIAG_HOST . '/api/v1/wallet');
$curlRaV4 = ra_diag_curl('https://' . RA_DIAG_HOST . '/api/v1/wallet', true);

// 4) Sitio de control + IP pública de salida del servidor
$control = ra_diag_curl('https://www.cloudflare.com/cdn-cgi/trace');
$outboundIp = '';
if ($control['ok'] && preg_match('/^ip=(.+)$/m', $control['body'], $m)) {
    $outboundIp = trim($m[1]);
}

// 5) Con la clave guardada (solo responde / no responde)
$keyConfigured = recargasamerica_api_is_configured();
$keyResult = ['ran' => false, 'ok' => false, 'message' => ''];
if ($keyConfigured) {
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
} elseif ($raReachable && $keyConfigured && !$keyResult['ok']) {
    $verdict = ['warn', 'La conexión llega, pero RecargasAmérica rechazó la clave guardada.', 'Revisa que la API KEY de la configuración sea una clave ACTIVA en el panel de RecargasAmérica (sección "Mis claves API"), o que la IP de este servidor esté permitida allí.'];
} elseif ($raReachable) {
    $verdict = ['warn', 'La conexión con RecargasAmérica llega, pero no hay API KEY guardada en la configuración.', 'Pega la API KEY (ra_...) en Configuración.'];
} elseif (!$control['ok']) {
    $verdict = ['bad', 'El servidor no puede salir a internet por HTTPS (ni siquiera a un sitio de control).', 'Es un bloqueo del hosting: pídele a Hostinger que permita las conexiones salientes por el puerto 443.'];
} elseif (!$tcpAnyOk) {
    $verdict = ['bad', 'El servidor sí sale a internet, pero la conexión al puerto 443 de RecargasAmérica es rechazada al instante.', 'Es un bloqueo específico hacia RecargasAmérica: o el firewall del hosting, o RecargasAmérica bloqueó la IP de salida de este servidor (' . ($outboundIp !== '' ? $outboundIp : 'ver abajo') . '). Escribe a ambos con los datos de esta página.'];
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

  <h2>IP pública de salida de este servidor</h2>
  <table>
    <tr><th>IP que ve RecargasAmérica</th><td><?= $outboundIp !== '' ? '<strong>' . ra_diag_e($outboundIp) . '</strong>' : '<span class="fail">no se pudo obtener</span>' ?></td></tr>
  </table>
  <p class="note">Si RecargasAmérica tiene una lista de IPs permitidas o bloqueó una IP, es esta.</p>

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

  <h2>3. HTTPS con cURL (sin clave; "401" significa que SÍ llega)</h2>
  <table>
    <?php foreach ([['Normal', $curlRa], ['Forzando IPv4', $curlRaV4]] as [$label, $r]): ?>
      <tr>
        <th><?= ra_diag_e($label) ?></th>
        <td>
          <?php if ($r['ok']): ?>
            <span class="ok">respondió HTTP <?= (int) $r['http'] ?></span> · IP <?= ra_diag_e($r['ip']) ?> · conexión <?= (int) $r['t_connect'] ?> ms · total <?= (int) $r['t_total'] ?> ms
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
  </table>

  <h2>5. Con la API KEY guardada</h2>
  <table>
    <tr><th>Saldo (/wallet)</th><td>
      <?php if (!$keyResult['ran']): ?>
        <span class="fail">no hay API KEY guardada</span>
      <?php elseif ($keyResult['ok']): ?>
        <span class="ok"><?= ra_diag_e($keyResult['message']) ?></span>
      <?php else: ?>
        <span class="fail"><?= ra_diag_e($keyResult['message']) ?></span>
      <?php endif; ?>
    </td></tr>
  </table>

  <h2>Resumen para copiar y enviar al hosting / a RecargasAmérica</h2>
<pre>Fecha: <?= ra_diag_e(date('Y-m-d H:i:s')) ?> (<?= ra_diag_e(date_default_timezone_get()) ?>)
IP de salida del servidor: <?= ra_diag_e($outboundIp !== '' ? $outboundIp : 'no disponible') ?>

DNS <?= ra_diag_e(RA_DIAG_HOST) ?>: <?= ra_diag_e(!empty($dnsIps) ? implode(', ', $dnsIps) : 'no resuelve') ?>

TCP 443: <?php foreach ($tcpResults as $tcp): ?><?= ra_diag_e($tcp['ip']) ?> => <?= $tcp['ok'] ? 'conecta' : 'FALLA (' . ($tcp['error'] !== '' ? $tcp['error'] : 'sin detalle') . ', codigo ' . (int) $tcp['errno'] . ')' ?> en <?= (int) $tcp['ms'] ?> ms; <?php endforeach; ?>

cURL normal: <?= $curlRa['ok'] ? 'HTTP ' . (int) $curlRa['http'] : 'FALLA (' . (int) $curlRa['errno'] . ': ' . $curlRa['error'] . ')' ?>

cURL IPv4: <?= $curlRaV4['ok'] ? 'HTTP ' . (int) $curlRaV4['http'] : 'FALLA (' . (int) $curlRaV4['errno'] . ': ' . $curlRaV4['error'] . ')' ?>

Sitio de control (cloudflare): <?= $control['ok'] ? 'responde' : 'FALLA (' . $control['error'] . ')' ?>

Software: PHP <?= ra_diag_e(PHP_VERSION) ?> · cURL <?= ra_diag_e($curlVersion) ?>
</pre>
</main>
</body>
</html>
