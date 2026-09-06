<?php
/**
 * admin/stream/saldo.php — Billetera del REVENDEDOR: saldo actual, movimientos y solicitud de
 * recarga de saldo. La recarga queda 'pendiente'; el admin la aprueba (o la aprueba la pasarela
 * de pago automáticamente cuando esté conectada). Solo tiene sentido en contexto revendedor.
 */
define('CONEC_ADMIN', true);
require __DIR__ . '/../_auth.php';
require __DIR__ . '/_layout.php';
require __DIR__ . '/../_streaming.php';
require __DIR__ . '/../../api/wallet/_helpers.php';
admin_require_login();

if (stream_ctx() !== 'revendedor') {
    header('Location: dashboard.php');
    exit;
}
$pdo = db();
$uid = (int) current_user_id();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $msg = '';
    if (($_POST['accion'] ?? '') === 'solicitar_recarga') {
        if (function_exists('st_rev_pausado') && st_rev_pausado($pdo)) {
            header('Location: saldo.php?msg=' . urlencode('⚠ Tu cuenta está pausada por el administrador. No puedes recargar por ahora.'));
            exit;
        }
        $monto = round(abs((float) ($_POST['monto'] ?? 0)), 2);
        $metodo = trim((string) ($_POST['metodo'] ?? '')) ?: null;
        $ref = trim((string) ($_POST['referencia'] ?? '')) ?: null;
        if ($monto <= 0) {
            $msg = '⚠ Indica un monto válido.';
        } elseif ($ref === null) {
            // La referencia NO es opcional: es lo que permite verificar el pago (BNC/Binance) automáticamente.
            $msg = '⚠ Escribe la referencia del pago (Nº de referencia / ID de transacción). Es obligatoria para verificar tu recarga.';
        } else {
            $pdo->prepare("INSERT INTO wallet_recargas (usuario_id, monto, metodo, referencia, estado) VALUES (?,?,?,?, 'pendiente')")
                ->execute([$uid, $monto, $metodo, $ref]);
            $recId = (int) $pdo->lastInsertId();
            // AUTOMÁTICO (Binance / BNC / Pago Móvil): se verifica con la API que corresponda y se
            // acredita al instante. Si no casa, queda pendiente (aprobación manual). El monto en $ es lo
            // que se acredita; si el método se paga en Bs (VES), el pago real (y el movimiento) van en Bs,
            // así que se pasa el monto EN BS para casarlo (monto en $ × tasa).
            //
            // BUG CORREGIDO (2026-08-30): antes esto buscaba una fila de payment_methods cuyo NOMBRE
            // coincidiera EXACTO con $metodo. Nunca coincidía: el <select> de más abajo tiene una lista
            // de RESPALDO con el valor fijo "Pago Móvil" (sin banco) para cuando no hay métodos activos
            // configurados, pero la fila real en payment_methods se llama "Pago Móvil Mercantil" (con
            // el banco) — nunca son el mismo string. Sin coincidencia, $montoMatch se quedaba en null,
            // y sbr_bank_verify_and_credit() terminaba comparando el monto EN DÓLARES (ej. $20) contra
            // movimientos que están en MILES DE BOLÍVARES → nunca encontraba nada, SIEMPRE quedaba
            // pendiente de aprobación manual aunque el pago fuera perfecto. Ahora se usa el MISMO
            // clasificador por contenido (sbr_metodo_slug) que ya usa el resto del motor más abajo —
            // reconoce "Pago Móvil"/"BNC" venga como venga escrito, sin depender de una fila exacta.
            //
            // AJUSTE (2026-08-31): la tasa ya NO se busca con una fila fija UPPER(clave)='BS'. Se toma
            // del MÉTODO elegido (payment_methods.moneda_id → monedas.tasa), que es la misma fila con
            // la que se le cobra al cliente en la tienda y la que se le muestra acá en pantalla
            // ("A pagar: X Bs"). Si esa moneda se renombra (VES/BSD…) o se desactiva, la consulta vieja
            // devolvía vacío, $montoMatch quedaba NULL y se terminaba buscando el movimiento por el
            // monto EN DÓLARES — que no existe en el banco → "pendiente" para siempre. Ver
            // sbr_monto_a_casar(), que hace la cascada método → mismo tipo → moneda Bs de respaldo.
            $montoMatch = null;
            try {
                require_once __DIR__ . '/../../includes/streaming_recarga_binance.php';
                $montoMatch = sbr_monto_a_casar($pdo, (string) $metodo, $monto);
            } catch (Throwable $e) {}
            if ($ref !== null && $metodo !== null) {
                require_once __DIR__ . '/../../includes/streaming_recarga_binance.php';
                $vr = sbr_verify_recarga($pdo, $uid, $recId, $ref, $monto, $metodo, $montoMatch);
                if (!empty($vr['reused']) || !empty($vr['rejected'])) {
                    // Referencia REPETIDA o que NO coincide con ningún pago recibido por ese monto → se
                    // RECHAZA (no queda "pendiente" dando a entender que ya va a llegar sola).
                    try { $pdo->prepare("UPDATE wallet_recargas SET estado='rechazada' WHERE id=? AND usuario_id=?")->execute([$recId, $uid]); } catch (Throwable $e) {}
                    $msg = $vr['message'];
                } else {
                    $msg = !empty($vr['credited'])
                        ? $vr['message']
                        : '✓ Recarga por $' . number_format($monto, 2) . ' registrada. ' . ($vr['message'] ?? 'Se acreditará al confirmarse el pago.');
                }
                // Correo de saldo acreditado (agregado): SOLO si sbr_verify_recarga() ya acreditó (verificación
                // automática al instante). Si quedó pendiente de aprobación manual, el correo sale más abajo,
                // en revendedores.php, cuando el admin la apruebe — nunca antes de que el crédito sea real.
                if (!empty($vr['credited'])) {
                    require_once __DIR__ . '/../../includes/stream_mailer.php';
                    try { stream_email_saldo_acreditado($pdo, $uid, ['monto' => $monto, 'metodo' => $metodo, 'referencia' => $ref, 'motivo' => 'Recarga automática']); } catch (Throwable $e) {}
                }
            } else {
                $msg = '✓ Solicitud de recarga por $' . number_format($monto, 2) . ' registrada. Se acreditará al confirmarse el pago.';
            }
            // FASE 3: avisar al admin de la recarga (acreditada, pendiente o rechazada por referencia repetida).
            try {
                require_once __DIR__ . '/../../api/_rev_avisos.php';
                $acreditada = isset($vr) && !empty($vr['credited']);
                $repetida   = isset($vr) && (!empty($vr['reused']) || !empty($vr['rejected']));
                if (!$repetida) {
                    stream_notif_crear($pdo, 0, 'recarga',
                        'Recarga de saldo · $' . number_format($monto, 2) . ($acreditada ? ' (acreditada automáticamente)' : ' (por aprobar)'),
                        'Método: ' . ($metodo ?: '—') . ' · Referencia: ' . ($ref ?: '—') . ($acreditada ? "\nEl pago se verificó solo y ya se acreditó." : "\nRevísala en Revendedores → Recargas pendientes."),
                        'revendedores.php', $uid, $recId);
                }
            } catch (Throwable $e) {}
        }
        header('Location: saldo.php?msg=' . urlencode($msg));
        exit;
    }
}

$flash = (string) ($_GET['msg'] ?? '');

// RE-VERIFICACIÓN al cargar la página: si una recarga quedó PENDIENTE porque el movimiento del banco/Binance
// aún no había sincronizado cuando se solicitó (demora típica: el revendedor solicita justo después de pagar),
// aquí se re-verifica contra los movimientos MÁS RECIENTES y se ACREDITA sola — sin esperar al admin. El fetch
// del banco corre UNA sola vez por carga (guard estático en el verificador). Solo ACREDITA: nunca rechaza en el
// re-chequeo (un pago real que todavía no sincroniza no debe rechazarse por recargar la página).
try {
    require_once __DIR__ . '/../../includes/streaming_recarga_binance.php';
    if (function_exists('sbr_verify_recarga')) {
        $pendRe = $pdo->prepare("SELECT id, monto, metodo, referencia FROM wallet_recargas
            WHERE usuario_id = ? AND estado IN ('pendiente','pending') AND metodo IS NOT NULL AND referencia IS NOT NULL
              AND creado_en >= (NOW() - INTERVAL 2 DAY) ORDER BY id DESC LIMIT 8");
        $pendRe->execute([$uid]);
        foreach ($pendRe->fetchAll(PDO::FETCH_ASSOC) as $prRe) {
            $mmRe = function_exists('sbr_monto_a_casar') ? sbr_monto_a_casar($pdo, (string) $prRe['metodo'], (float) $prRe['monto']) : null;
            // sbr_verify_recarga acredita + marca 'aprobado' si casa. Si NO casa (o "rechazada"), se deja
            // pendiente a propósito (no se toca la BD aquí) para no tumbar un pago que aún puede sincronizar.
            try { sbr_verify_recarga($pdo, $uid, (int) $prRe['id'], (string) $prRe['referencia'], (float) $prRe['monto'], (string) $prRe['metodo'], $mmRe); } catch (Throwable $e) {}
        }
    }
} catch (Throwable $e) {}

$saldo = wallet_saldo($pdo, $uid);

// Tasa Bs de la tienda (moneda con clave 'BS'): para mostrar el monto a pagar en bolívares.
$bsRate = 0.0; $bsDec = 2;
try {
    $m = $pdo->query("SELECT tasa, mostrar_decimales FROM monedas WHERE UPPER(clave)='BS' AND activo=1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($m) { $bsRate = (float) $m['tasa']; $bsDec = (int) $m['mostrar_decimales']; }
} catch (Throwable $e) {}

// ¿Pasarelas automáticas habilitadas? (al pagar se acredita el saldo solo). Config de la tienda.
$paypalOn = false; $binanceOn = false;
try {
    $cfg = $pdo->query("SELECT clave, valor FROM configuracion_general WHERE clave IN
        ('pago_paypal','paypal_activo','paypal_client_id','paypal_client_secret',
         'api_binance','api_binance_usuario','binance_pay_merchant_no','binance_pay_secret_key')")->fetchAll(PDO::FETCH_KEY_PAIR);
    $paypalOn = (($cfg['pago_paypal'] ?? '0') === '1')
        && (($cfg['paypal_activo'] ?? '1') === '1')
        && trim((string) ($cfg['paypal_client_id'] ?? '')) !== ''
        && trim((string) ($cfg['paypal_client_secret'] ?? '')) !== '';
    $binanceOn = (($cfg['api_binance'] ?? '0') === '1')
        && (($cfg['api_binance_usuario'] ?? '1') === '1')
        && trim((string) ($cfg['binance_pay_merchant_no'] ?? '')) !== ''
        && trim((string) ($cfg['binance_pay_secret_key'] ?? '')) !== '';
} catch (Throwable $e) {}
$autoOn = $paypalOn || $binanceOn;
$recargarUrl = function_exists('app_path') ? app_path('/api/revendedor/recargar-saldo.php') : '/api/revendedor/recargar-saldo.php';

// Métodos de pago ACTIVOS de la tienda (los mismos del checkout): el revendedor paga con cualquiera.
$metodosPago = [];
try {
    $metodosPago = $pdo->query("SELECT pm.id, pm.nombre, pm.datos, pm.qr_image_path, pm.referencia_digitos,
            mo.clave AS moneda, mo.tasa AS moneda_tasa
        FROM payment_methods pm LEFT JOIN monedas mo ON mo.id = pm.moneda_id
        WHERE pm.activo = 1 ORDER BY pm.nombre")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $metodosPago = []; }

// #6: Binance del cliente (verificador «pagonorte»). Si está configurado, se ofrece como método
// MANUAL en la recarga del revendedor: paga a ese Binance y el admin aprueba (no usa la pasarela
// automática, así no hay riesgo con la verificación). Los datos salen de la config de la tienda.
try {
    $binDatos = ''; $binActivo = false;
    foreach (['configuracion_general', 'configuracion'] as $ct) {
        try {
            $bcfg = $pdo->query("SELECT clave, valor FROM $ct WHERE clave IN ('binance_pagonorte_activo','binance_pagonorte_datos','binance_pagonorte_usuario')")->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch (Throwable $e) { $bcfg = []; }
        if ($bcfg) {
            $d = trim((string) ($bcfg['binance_pagonorte_datos'] ?? '')) ?: trim((string) ($bcfg['binance_pagonorte_usuario'] ?? ''));
            if (($bcfg['binance_pagonorte_activo'] ?? '0') === '1' || $d !== '') { $binDatos = $d; $binActivo = true; break; }
        }
    }
    if ($binActivo) {
        $yaHay = false;
        foreach ($metodosPago as $mp) { if (mb_stripos((string) $mp['nombre'], 'binance') !== false) { $yaHay = true; break; } }
        if (!$yaHay) {
            array_unshift($metodosPago, ['id' => 0, 'nombre' => 'Binance', 'datos' => ($binDatos !== '' ? $binDatos : 'Paga por Binance y sube la referencia.'), 'qr_image_path' => null, 'referencia_digitos' => 0, 'moneda' => null, 'moneda_tasa' => null]);
        }
    }
} catch (Throwable $e) {}

$movs = $pdo->prepare("SELECT tipo, monto, descripcion, referencia, creado_en FROM wallet_movimientos WHERE usuario_id=? ORDER BY id DESC LIMIT 200");
$movs->execute([$uid]);
$movimientos = $movs->fetchAll(PDO::FETCH_ASSOC);

$recs = $pdo->prepare("SELECT monto, metodo, estado, creado_en FROM wallet_recargas WHERE usuario_id=? ORDER BY id DESC LIMIT 20");
$recs->execute([$uid]);
$recargas = $recs->fetchAll(PDO::FETCH_ASSOC);

stream_head('Mi saldo', 'saldo');
?>
<?php if ($flash): ?><div class="banner" style="margin-bottom:16px;white-space:normal"><i data-lucide="info"></i><?= h($flash) ?></div><?php endif; ?>

<div class="pagehd">
  <div><h1>Mi <span class="nm">saldo</span></h1><p>Recarga tu saldo para comprar del stock de la tienda.</p></div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px" class="saldo-grid">
  <div class="card" style="padding:20px;display:flex;flex-direction:column;justify-content:center">
    <div style="font-size:12px;color:var(--faint);font-weight:700;text-transform:uppercase;letter-spacing:.05em">Saldo disponible</div>
    <div class="tnum" style="font-size:38px;font-weight:800;color:var(--accent);line-height:1.1">$<?= number_format($saldo, 2) ?></div>
  </div>
  <div class="card" style="padding:18px">
    <h2 style="margin:0 0 12px;font-size:15px"><i data-lucide="plus-circle" style="width:16px;height:16px;vertical-align:-2px"></i> Recargar saldo</h2>
    <form method="post">
      <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="accion" value="solicitar_recarga">
      <div class="row2" style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
        <div><label style="display:block;font-size:12px;color:var(--muted);font-weight:600;margin-bottom:5px">Monto ($)</label>
          <input type="number" step="0.01" min="1" name="monto" id="rcMonto" required oninput="rcCalcBs()" style="width:100%;padding:9px;border:1px solid var(--border);border-radius:9px;background:var(--surface);color:var(--text)"></div>
        <div><label style="display:block;font-size:12px;color:var(--muted);font-weight:600;margin-bottom:5px">Método de pago</label>
          <?php if ($metodosPago): ?>
          <select name="metodo" id="rcMetodo" onchange="rcMetodoInfo()" style="width:100%;padding:9px;border:1px solid var(--border);border-radius:9px;background:var(--surface);color:var(--text)">
            <?php foreach ($metodosPago as $mp): ?><option value="<?= h($mp['nombre']) ?>"><?= h($mp['nombre']) ?></option><?php endforeach; ?>
          </select>
          <?php else: ?>
          <select name="metodo" style="width:100%;padding:9px;border:1px solid var(--border);border-radius:9px;background:var(--surface);color:var(--text)">
            <option value="Binance">Binance</option><option value="PayPal">PayPal</option>
            <option value="Pago Móvil">Pago Móvil</option><option value="Transferencia">Transferencia</option>
          </select>
          <?php endif; ?>
        </div>
      </div>
      <?php if ($metodosPago): ?>
      <div id="rcMetodoBox" style="margin-top:10px;padding:12px;border-radius:10px;border:1px solid var(--border);background:var(--surface-2);font-size:13px;display:none">
        <div id="rcMetodoDatos" style="white-space:pre-line;overflow-wrap:anywhere;color:var(--text)"></div>
        <img id="rcMetodoQr" src="" alt="QR" style="display:none;max-width:150px;margin-top:8px;border-radius:8px">
      </div>
      <?php endif; ?>
      <div id="rcBsBox" style="margin-top:10px;padding:10px 12px;border-radius:10px;background:var(--accent-soft);color:var(--accent);font-weight:700;font-size:13.5px;display:none">
        A pagar: <span id="rcBs">0,00</span> Bs
        <span style="font-weight:500;color:var(--muted);font-size:12px">· tasa <span id="rcBsTasa"><?= number_format($bsRate, 2, ',', '.') ?></span> Bs/$</span>
      </div>
      <div style="margin-top:10px"><label style="display:block;font-size:12px;color:var(--muted);font-weight:600;margin-bottom:5px">Referencia del pago <span style="color:var(--bad)">*</span> (obligatoria)</label>
        <input name="referencia" required placeholder="Nº de referencia / ID de transacción" style="width:100%;padding:9px;border:1px solid var(--border);border-radius:9px;background:var(--surface);color:var(--text)">
        <div style="font-size:11px;color:var(--muted);margin-top:4px">La necesitamos para verificar tu pago automáticamente (BNC/Binance).</div></div>
      <?php if ($paypalOn): ?>
        <button class="btn primary" type="submit" name="pasarela" value="paypal" formaction="<?= h($recargarUrl) ?>" formmethod="post" style="width:100%;margin-top:14px;background:#0070ba"><i data-lucide="zap"></i> Pagar con PayPal (automático)</button>
      <?php endif; ?>
      <?php if ($binanceOn): ?>
        <button class="btn primary" type="submit" name="pasarela" value="binance" formaction="<?= h($recargarUrl) ?>" formmethod="post" style="width:100%;margin-top:<?= $paypalOn ? '8' : '14' ?>px;background:#f0b90b;color:#111"><i data-lucide="zap"></i> Pagar con Binance (automático)</button>
      <?php endif; ?>
      <?php if ($autoOn): ?>
        <button class="btn ghost" type="submit" style="width:100%;margin-top:8px"><i data-lucide="clock"></i> Otro método (lo aprueba el admin)</button>
      <?php else: ?>
        <button class="btn primary" type="submit" style="width:100%;margin-top:14px"><i data-lucide="wallet"></i> Solicitar recarga</button>
      <?php endif; ?>
    </form>
    <p class="muted" style="font-size:11.5px;margin:10px 0 0"><?= $autoOn ? 'Con PayPal/Binance el saldo se acredita al instante. Con otro método, el admin confirma tu pago.' : 'Al confirmarse el pago se acredita tu saldo.' ?></p>
  </div>
</div>

<?php if ($recargas): ?>
<div class="card" style="margin-bottom:16px;padding:16px">
  <h2 style="margin:0 0 10px;font-size:14px">Recargas recientes</h2>
  <div class="overflow-x-auto thin"><table class="dtable">
    <thead><tr><th>Monto</th><th>Método</th><th>Estado</th><th>Fecha</th></tr></thead>
    <tbody>
    <?php foreach ($recargas as $r): $col = $r['estado']==='aprobada'?'var(--good)':($r['estado']==='rechazada'?'var(--bad)':'var(--warn)'); ?>
      <tr><td class="tnum">$<?= number_format((float) $r['monto'], 2) ?></td><td><?= h($r['metodo'] ?: '—') ?></td>
      <td><span style="color:<?= $col ?>;font-weight:700"><?= h($r['estado']) ?></span></td>
      <td class="muted" style="font-size:12px"><?= h($r['creado_en']) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php endif; ?>

<div class="card">
  <div class="card-hd"><i data-lucide="receipt"></i><h2>Movimientos</h2></div>
  <div class="overflow-x-auto thin"><table class="dtable">
    <thead><tr><th>Fecha</th><th>Concepto</th><th style="text-align:right">Monto</th></tr></thead>
    <tbody>
    <?php if (!$movimientos): ?>
      <tr><td colspan="3" class="muted" style="text-align:center;padding:24px">Aún no hay movimientos.</td></tr>
    <?php else: foreach ($movimientos as $m): $neg = (float) $m['monto'] < 0; ?>
      <tr>
        <td class="muted" style="font-size:12px"><?= h($m['creado_en']) ?></td>
        <td><?= h($m['descripcion'] ?: $m['tipo']) ?></td>
        <td class="tnum" style="text-align:right;font-weight:700;color:<?= $neg ? 'var(--bad)' : 'var(--good)' ?>"><?= $neg ? '-' : '+' ?>$<?= number_format(abs((float) $m['monto']), 2) ?></td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table></div>
</div>
<style>@media(max-width:640px){.saldo-grid{grid-template-columns:1fr!important}}@media(max-width:400px){.row2{grid-template-columns:1fr!important}}</style>
<script>
const RC_RATE = <?= json_encode($bsRate) ?>;
const RC_DEC = <?= json_encode($bsDec) ?>;
// Cada método lleva SU tasa (payment_methods.moneda_id → monedas.tasa) = la MISMA que usa el sistema para
// CASAR el pago (sbr_monto_a_casar). Mostrar el Bs con esta tasa (no con una fila 'BS' aparte) evita que el
// revendedor pague un monto y el sistema espere otro → esa divergencia dejaba las recargas "pendientes".
const RC_METODOS = <?= json_encode(array_map(fn($m) => ['nombre'=>$m['nombre'],'datos'=>$m['datos'],'qr'=>$m['qr_image_path'],'tasa'=>(float)($m['moneda_tasa'] ?? 0)], $metodosPago), JSON_UNESCAPED_UNICODE) ?>;
function rcTasaActual(){
  const sel = document.getElementById('rcMetodo');
  if (sel) { const m = RC_METODOS.find(x => x.nombre === sel.value); if (m && m.tasa > 0) return m.tasa; }
  return RC_RATE; // respaldo: la tasa Bs de la tienda
}
function rcCalcBs(){
  const box = document.getElementById('rcBsBox'); if (!box) return;
  const inp = document.getElementById('rcMonto');
  const u = parseFloat((inp && inp.value) || '0');
  const tasa = rcTasaActual();
  if (!u || u <= 0 || tasa <= 0) { box.style.display = 'none'; return; }
  document.getElementById('rcBs').textContent = (u * tasa).toLocaleString('es-VE', { minimumFractionDigits: RC_DEC, maximumFractionDigits: RC_DEC });
  const tl = document.getElementById('rcBsTasa'); if (tl) tl.textContent = tasa.toLocaleString('es-VE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  box.style.display = 'block';
}
function rcMetodoInfo(){
  const box = document.getElementById('rcMetodoBox');
  const sel = document.getElementById('rcMetodo');
  if (box && sel) {
    const m = RC_METODOS.find(x => x.nombre === sel.value);
    if (!m) { box.style.display = 'none'; }
    else {
      document.getElementById('rcMetodoDatos').textContent = m.datos || '';
      const qr = document.getElementById('rcMetodoQr');
      if (m.qr) { qr.src = m.qr; qr.style.display = 'block'; } else { qr.style.display = 'none'; }
      box.style.display = 'block';
    }
  }
  rcCalcBs(); // recalcular el Bs con la tasa del método elegido (display = lo que casa el sistema)
}
document.addEventListener('DOMContentLoaded', rcMetodoInfo);
</script>
<?php stream_foot();
