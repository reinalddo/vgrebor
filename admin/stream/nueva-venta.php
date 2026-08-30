<?php
/**
 * Nueva Venta — wizard 4 pasos CALCADO de PAC: Cliente → Plataforma (multi + logos) →
 * Cuenta (tabla rankeada Riesgo/Margen/Perfiles + Recomendada) → Detalles (tarjeta por cuenta,
 * multi-perfil, Historial del cliente, comprobante, toggle WhatsApp). Crea N ventas (una por perfil).
 */
define('CONEC_ADMIN', true);
require __DIR__ . '/../_auth.php';
require __DIR__ . '/_layout.php';
require __DIR__ . '/../../api/wa/_wa.php';
require __DIR__ . '/../_streaming.php';
admin_require_login();
if (!admin_es_admin() && !in_array(admin_wa_area(), ['streaming', 'ambas'], true)) {
  http_response_code(403);
  exit('Esta sección es del área de Streaming.');
}
$verCostos = admin_es_admin();
$pdo = db();
$OWNER = (int) stream_owner_id();

// ── AJAX: historial del cliente ──
if (($_GET['ajax'] ?? '') === 'historial') {
  header('Content-Type: application/json; charset=utf-8');
  $id = (int) ($_GET['cliente_id'] ?? 0);
  $r = ['ultima' => 'Sin historial', 'fecha' => 'Sin historial', 'ticket' => 0.0, 'n' => 0];
  try {
    $row = $pdo->prepare("SELECT COUNT(*) n, COALESCE(AVG(precio),0) tk, MAX(creado_en) f FROM streaming_ventas WHERE cliente_id=? AND owner_id=?");
    $row->execute([$id, $OWNER]); $row = $row->fetch(PDO::FETCH_ASSOC);
    if ($row && $row['n'] > 0) {
      $r['n'] = (int) $row['n']; $r['ticket'] = (float) $row['tk']; $r['fecha'] = date('d/m/Y', strtotime($row['f']));
      $up = $pdo->prepare("SELECT plataforma FROM streaming_ventas WHERE cliente_id=? AND owner_id=? ORDER BY id DESC LIMIT 1"); $up->execute([$id, $OWNER]);
      $r['ultima'] = (string) ($up->fetchColumn() ?: 'Sin historial');
    }
  } catch (Throwable $e) {}
  echo json_encode(['ok' => true, 'h' => $r]);
  exit;
}

// ── POST: crear N ventas (una por fila de perfil) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $msg = '';
  try {
    $cliId = ((int) ($_POST['cliente_id'] ?? 0)) ?: null;
    $cliNombre = trim((string) ($_POST['cliente_nombre'] ?? ''));
    $cliWa = wa_norm((string) ($_POST['cliente_wa'] ?? ''));
    // CORREO del cliente (agregado): sirve para enviarle sus datos de acceso y queda guardado en su
    // ficha para las próximas ventas. Si no es un correo válido se ignora en silencio — nunca debe
    // impedir registrar la venta, que es lo importante.
    $cliEmail = trim((string) ($_POST['cliente_email'] ?? ''));
    if ($cliEmail !== '' && !filter_var($cliEmail, FILTER_VALIDATE_EMAIL)) { $cliEmail = ''; }
    if ($cliId) { $c = $pdo->query("SELECT nombre, wa FROM streaming_clientes WHERE id=" . (int) $cliId . " AND owner_id=$OWNER")->fetch(PDO::FETCH_ASSOC); if ($c) { $cliNombre = $c['nombre']; if (!$cliWa) $cliWa = wa_norm((string) ($c['wa'] ?? '')); } else { $cliId = null; } }
    if ($cliNombre === '') throw new Exception('Elige un cliente.');
    if (!$cliId && $cliNombre !== '') { $pdo->prepare("INSERT INTO streaming_clientes (owner_id,nombre,wa,email) VALUES (?,?,?,?)")->execute([$OWNER, $cliNombre, $cliWa ?: null, $cliEmail ?: null]); $cliId = (int) $pdo->lastInsertId(); }
    // Cliente ya existente: si escribieron un correo en el formulario, se guarda en su ficha.
    elseif ($cliId && $cliEmail !== '') { try { $pdo->prepare("UPDATE streaming_clientes SET email=? WHERE id=? AND owner_id=?")->execute([$cliEmail, $cliId, $OWNER]); } catch (Throwable $e) {} }
    // Si el cliente/WhatsApp pertenece a un revendedor, la venta nace ya con su revendedor_id (auto-atribución).
    $revId = st_revendedor_de_cliente($pdo, $cliId, $cliWa);

    $metodo = trim((string) ($_POST['metodo'] ?? '')) ?: null;
    $ref = trim((string) ($_POST['referencia'] ?? '')) ?: null;
    $comp = null;
    if (!empty($_FILES['comprobante']['tmp_name']) && is_uploaded_file($_FILES['comprobante']['tmp_name']) && (int) $_FILES['comprobante']['error'] === 0 && (int) $_FILES['comprobante']['size'] <= 4 * 1024 * 1024) {
      $ext = strtolower(pathinfo($_FILES['comprobante']['name'], PATHINFO_EXTENSION));
      if (in_array($ext, ['png', 'jpg', 'jpeg', 'webp', 'pdf'], true)) { $dir = __DIR__ . '/../../uploads/streaming-comprobantes'; @mkdir($dir, 0755, true); $fn = 'venta-' . time() . '-' . random_int(100, 999) . '.' . $ext; if (@move_uploaded_file($_FILES['comprobante']['tmp_name'], $dir . '/' . $fn)) $comp = '/uploads/streaming-comprobantes/' . $fn; }
    }
    $enviarWa = !empty($_POST['enviar_wa']);

    $rc = (array) ($_POST['r_cuenta'] ?? []); $rpin = (array) ($_POST['r_pin'] ?? []); $rf = (array) ($_POST['r_fecha'] ?? []); $rpr = (array) ($_POST['r_precio'] ?? []); $rpid = (array) ($_POST['r_perfilid'] ?? []); $rperfil = (array) ($_POST['r_perfil'] ?? []); $rrenov = (array) ($_POST['r_renovacion'] ?? []);
    if (!$rc) throw new Exception('No agregaste ningún perfil. Vuelve al paso 4.');
    $creadas = 0; $enviadas = 0; $noVentana = 0; $errEnvio = 0; $sinStock = 0;
    $idsCreadas = [];   // ventas con perfil realmente asignado → son las que llevan aviso por correo
    foreach ($rc as $i => $cuRaw) {
      $cuentaId = (int) $cuRaw; if ($cuentaId <= 0) continue;
      $cu = $pdo->query("SELECT plataforma, correo, clave FROM streaming_cuentas WHERE id=$cuentaId AND owner_id=$OWNER")->fetch(PDO::FETCH_ASSOC);
      if (!$cu) continue;
      $venc = ($rf[$i] ?? '') ?: date('Y-m-d', strtotime('+30 days'));
      $precio = ($rpr[$i] ?? '') !== '' ? (float) $rpr[$i] : null;
      // Precio de renovación (opcional): si no lo escriben, se usa el mismo precio de venta.
      $precioRenov = ($rrenov[$i] ?? '') !== '' ? (float) $rrenov[$i] : $precio;
      // Nombre del perfil escrito por el usuario (opcional) + PIN. Truncados al tamaño de la columna
      // (perfil/etiqueta ≤60, pin ≤20) para no reventar con "Data too long".
      $perfil = mb_substr(trim((string) ($rperfil[$i] ?? '')), 0, 60);
      $pin    = mb_substr(trim((string) ($rpin[$i] ?? '')), 0, 20);
      $st = $pdo->prepare("INSERT INTO streaming_ventas (owner_id,plataforma,tipo,cuenta_id,cliente_id,cliente_nombre,cliente_wa,correo,clave,perfil,pin,precio,precio_renovacion,fecha_inicio,fecha_vencimiento,metodo_pago,referencia,comprobante_url,creado_por,revendedor_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
      $st->execute([$OWNER, $cu['plataforma'], 'perfil', $cuentaId, $cliId, $cliNombre ?: null, $cliWa ?: null, $cu['correo'], $cu['clave'], $perfil, $pin, $precio, $precioRenov, date('Y-m-d'), $venc, $metodo, $ref, $comp, current_user_id(), $revId]);
      $vid = (int) $pdo->lastInsertId();
      // Asignar el perfil ELEGIDO (específico) si vino; si no, cualquiera libre. Claim atómico.
      $perfilId = (int) ($rpid[$i] ?? 0);
      $claimed = false; $claimedProfileId = 0;
      if ($perfilId > 0) {
        $up = $pdo->prepare("UPDATE streaming_perfiles SET estado='vendido', venta_id=? WHERE id=? AND cuenta_id=? AND estado='libre'");
        $up->execute([$vid, $perfilId, $cuentaId]);
        if ($up->rowCount() === 1) { $claimed = true; $claimedProfileId = $perfilId; $sp = $pdo->query("SELECT etiqueta, pin FROM streaming_perfiles WHERE id=$perfilId")->fetch(PDO::FETCH_ASSOC); if ($sp) { if ($perfil === '' && $sp['etiqueta']) $perfil = $sp['etiqueta']; if ($pin === '' && $sp['pin']) $pin = $sp['pin']; } }
      }
      $asignado = $claimed;
      if (!$claimed && st_asignar_perfiles($pdo, $cuentaId, $vid, 1)) { $asignado = true; $sp = $pdo->query("SELECT id, etiqueta, pin FROM streaming_perfiles WHERE venta_id=$vid LIMIT 1")->fetch(PDO::FETCH_ASSOC); if ($sp) { $claimedProfileId = (int) $sp['id']; if ($perfil === '' && $sp['etiqueta']) $perfil = $sp['etiqueta']; if ($pin === '' && $sp['pin']) $pin = $sp['pin']; } }
      // Si el usuario escribió un NOMBRE (o PIN) para el perfil, guardárselo también al perfil reclamado.
      // Solo se actualiza lo que trae valor (en PHP), para conservar el resto sin mezclar collations
      // (COALESCE(NULLIF(?,''),col) revienta con conexión general_ci + columnas unicode_ci → error 1267).
      if ($claimedProfileId > 0 && ($perfil !== '' || $pin !== '')) {
        $setsP = []; $argsP = [];
        if ($perfil !== '') { $setsP[] = 'etiqueta=?'; $argsP[] = $perfil; }
        if ($pin !== '')    { $setsP[] = 'pin=?';      $argsP[] = $pin; }
        if ($setsP) { $argsP[] = $claimedProfileId; try { $pdo->prepare("UPDATE streaming_perfiles SET " . implode(',', $setsP) . " WHERE id=?")->execute($argsP); } catch (Throwable $e) {} }
      }
      $pdo->prepare("UPDATE streaming_ventas SET perfil=?, pin=? WHERE id=?")->execute([$perfil, $pin, $vid]);
      try { $pdo->prepare("INSERT INTO streaming_venta_registro (venta_id,evento,descripcion,usuario_id) VALUES (?,?,?,?)")->execute([$vid, 'creada', 'Venta creada', current_user_id()]); } catch (Throwable $e) {}
      if ($metodo || $ref || $comp) { try { $pdo->prepare("INSERT INTO streaming_venta_pagos (venta_id,tipo,metodo,referencia,monto,comprobante_url,creado_por) VALUES (?,?,?,?,?,?,?)")->execute([$vid, 'venta', $metodo, $ref, $precio, $comp, current_user_id()]); } catch (Throwable $e) {} }
      // Si NO se pudo reservar ningún perfil (stock agotado / carrera): marcar entregada=0 y NO enviar.
      // Antes se enviaban correo+clave MAESTRAS de la cuenta a un cliente sin slot asignado (fuga real).
      if (!$asignado) {
        $pdo->prepare("UPDATE streaming_ventas SET entregada=0 WHERE id=?")->execute([$vid]);
        try { $pdo->prepare("INSERT INTO streaming_venta_registro (venta_id,evento,descripcion,usuario_id) VALUES (?,?,?,?)")->execute([$vid, 'nota', 'SIN STOCK al confirmar: no había perfil libre, NO se enviaron credenciales. Revisa inventario.', current_user_id()]); } catch (Throwable $e) {}
        $sinStock++;
      }
      // Auto-envío de credenciales: SOLO si se reservó un perfil, y si el cliente escribió en las últimas 24h.
      if ($asignado && $enviarWa && $cliWa) {
        $v = $pdo->query("SELECT * FROM streaming_ventas WHERE id=$vid")->fetch(PDO::FETCH_ASSOC);
        $rr = stream_wa_enviar($pdo, $cliWa, $cliNombre, stream_msg_credenciales($v));
        if (!empty($rr['ok'])) { $pdo->prepare("UPDATE streaming_ventas SET entregada=1 WHERE id=?")->execute([$vid]); $enviadas++; }
        elseif (($rr['error'] ?? '') === 'ventana_cerrada') { $noVentana++; }
        else { $errEnvio++; }
      }
      if ($asignado) { $idsCreadas[] = $vid; }
      $creadas++;
    }

    // ── AVISO POR CORREO (agregado) ────────────────────────────────────────────────────────────
    // A cada quien el suyo: al CLIENTE sus datos de acceso y al DUEÑO de la operación su copia
    // (lo decide stream_email_notificar_venta). Va DESPUÉS del bucle a propósito: no se hace una
    // conexión SMTP a mitad de las inserciones. Solo para ventas con perfil realmente asignado —
    // si no hubo stock no hay credenciales que mandar. En try/catch: un fallo de correo NUNCA
    // invalida las ventas, que ya quedaron guardadas.
    $mails = 0;
    foreach ($idsCreadas as $vidOk) {
      try { $mails += stream_email_notificar_venta($pdo, (int) $vidOk, 'compra'); } catch (Throwable $e) {}
    }

    $resumen = "✓ $creadas venta(s) creada(s).";
    if ($mails) $resumen .= " ✉ $mails correo(s) enviado(s).";
    if ($enviadas)  $resumen .= " 📤 $enviadas enviada(s) al cliente por WhatsApp.";
    if ($noVentana) $resumen .= " ⏰ $noVentana SIN enviar: el cliente no escribió en las últimas 24h. Pídele que te mande un mensaje y reenvía desde Ventas › ⋮ › Notificar (o pásale los datos a mano).";
    if ($errEnvio)  $resumen .= " ⚠ $errEnvio con error de envío (revisa Ventas › Notificar).";
    if ($sinStock)  $resumen .= " ⛔ $sinStock SIN STOCK: no se pudo reservar perfil y NO se enviaron credenciales. Agrega inventario y entrega a mano.";
    header('Location: ventas.php?msg=' . urlencode($resumen));
    exit;
  } catch (Throwable $e) { $msg = '⚠ ' . $e->getMessage(); }
  header('Location: nueva-venta.php?msg=' . urlencode($msg));
  exit;
}
$flash = (string) ($_GET['msg'] ?? '');

// 'tipo' es una columna PEREZOSA (admin/stream/clientes.php la escribe en try/catch): la pedimos
// guardada para saber si el cliente es DISTRIBUIDOR y así autocompletar el precio de revendedor.
$hasTipoCli = false;
try { $hasTipoCli = (bool) $pdo->query("SHOW COLUMNS FROM streaming_clientes LIKE 'tipo'")->fetch(); } catch (Throwable $e) {}
$selTipo = $hasTipoCli ? ", tipo" : ", 'cliente' AS tipo";
$clientes = $pdo->query("SELECT id, nombre, wa, email$selTipo FROM streaming_clientes WHERE owner_id=$OWNER ORDER BY nombre LIMIT 3000")->fetchAll(PDO::FETCH_ASSOC);
$plataformas = $pdo->query("SELECT id, nombre, logo_url, color, precio_publico, precio_sugerido, dias_default FROM streaming_plataformas WHERE owner_id=$OWNER AND activo=1 ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);
$hoy = date('Y-m-d');
$cuentasRaw = $pdo->query("SELECT c.id, c.plataforma_id, COALESCE(pl.nombre,c.plataforma) plataforma, c.correo, c.vencimiento, c.costo, pl.logo_url, pl.color, pl.precio_publico, pl.precio_sugerido, pl.precio_distribuidor, pl.dias_default,
    (SELECT COUNT(*) FROM streaming_perfiles p WHERE p.cuenta_id=c.id AND p.estado='libre') libres
  FROM streaming_cuentas c LEFT JOIN streaming_plataformas pl ON pl.id=c.plataforma_id AND pl.owner_id=c.owner_id WHERE c.owner_id=$OWNER HAVING libres > 0 ORDER BY (c.vencimiento IS NULL), c.vencimiento ASC")->fetchAll(PDO::FETCH_ASSOC);
// Enriquecer: riesgo + margen (solo dueño ve costo/margen)
$cuentas = [];
foreach ($cuentasRaw as $c) {
  $d = $c['vencimiento'] ? (int) ((strtotime(substr($c['vencimiento'], 0, 10)) - strtotime($hoy)) / 86400) : null;
  $riesgo = ($d === null) ? 'bajo' : ($d < 0 ? 'alto' : ($d <= 7 ? 'medio' : 'bajo'));
  $precioPub = (float) ($c['precio_publico'] ?: $c['precio_sugerido'] ?: 0);
  // PRECIO REVENDEDOR (15-jul): es el que TÚ tecleas en Plataformas → "Precio Revendedor / perfil"
  // (streaming_plataformas.precio_distribuidor). NO se calcula con ninguna fórmula: en streaming el
  // mayorista es manual (el "−10% con piso costo+5%" es solo de RECARGAS, tabla variantes).
  // Si la plataforma no tiene precio de revendedor cargado, cae al público (nunca queda en 0).
  $precioRev = (float) ($c['precio_distribuidor'] ?? 0);
  $margen = $verCostos ? round($precioPub - (float) ($c['costo'] ?? 0), 2) : null;
  $cuentas[] = ['id' => (int) $c['id'], 'plataforma_id' => (int) ($c['plataforma_id'] ?? 0), 'plataforma' => $c['plataforma'], 'correo' => $c['correo'],
    'vencimiento' => $c['vencimiento'], 'dias' => $d, 'libres' => (int) $c['libres'], 'logo' => $c['logo_url'], 'color' => $c['color'] ?: '#2DE2D5',
    'riesgo' => $riesgo, 'margen' => $margen, 'precio' => $precioPub,
    // GATE $verCostos: precio_distribuidor es SOLO del dueño (así está decidido en tipos.php:5 y :85).
    // Sin este gate, el array se serializa entero al navegador (const CUENTAS = json_encode) y el
    // empleado veía tu lista de precios MAYORISTAS completa con solo abrir el inspector.
    // Al empleado se le autocompleta el precio PÚBLICO aunque el cliente sea distribuidor.
    'precio_rev' => $verCostos ? ($precioRev > 0 ? $precioRev : $precioPub) : $precioPub,
    'dias_default' => (int) ($c['dias_default'] ?: 30)];
}
// Perfiles LIBRES por cuenta (para el selector del paso 4 + autorelleno de PIN)
$ctaIds = array_map(fn($x) => $x['id'], $cuentas);
$perfByCta = [];
if ($ctaIds) {
  $phIn = implode(',', array_map('intval', $ctaIds));
  try { foreach ($pdo->query("SELECT id, cuenta_id, etiqueta, pin FROM streaming_perfiles WHERE estado='libre' AND cuenta_id IN ($phIn) ORDER BY id") as $r)
    $perfByCta[(int) $r['cuenta_id']][] = ['id' => (int) $r['id'], 'etiqueta' => $r['etiqueta'], 'pin' => $r['pin']]; } catch (Throwable $e) {}
}
foreach ($cuentas as &$cc) { $cc['perfiles'] = $perfByCta[$cc['id']] ?? []; }
unset($cc);

stream_head('Nueva Venta', 'ventas');
?>
<style>
  .wz-card{max-width:900px;margin:0 auto}
  .wz-hd{display:flex;align-items:center;gap:10px;padding:14px 18px;border-bottom:1px solid var(--border)}
  .wz-hd h2{margin:0;font-size:15px;font-weight:750}
  .wz-hd > [data-lucide]{width:17px;height:17px;color:var(--accent)}
  .wz-bar{height:3px;background:var(--surface-2)}
  .wz-bar > i{display:block;height:3px;background:var(--accent);transition:width .25s ease}
  .wz-steps{display:flex;border-bottom:1px solid var(--border)}
  .step-tab{flex:1;text-align:center;padding:11px 6px;font-size:12.5px;font-weight:600;color:var(--faint);transition:.12s}
  .step-tab.on{color:var(--accent);box-shadow:inset 0 -2px 0 var(--accent)}
  .step-tab.done{color:var(--good)}
  .step-tab .chk{color:var(--good)}
  .wz-body{padding:20px 22px}
  .wz-lbl{display:block;font-size:12.5px;font-weight:700;color:var(--text);margin-bottom:8px}
  .wz-lbl small{font-weight:500;color:var(--faint)}
  .mini-lbl{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.03em;color:var(--faint)}
  .plat-card{border:1.5px solid var(--border);border-radius:12px;padding:12px 8px;text-align:center;cursor:pointer;background:var(--surface);transition:.12s}
  .plat-card:hover{border-color:var(--border-strong)}
  .plat-card.sel{border-color:var(--accent);background:var(--accent-soft)}
  .plat-logo{width:46px;height:46px;margin:0 auto 6px;border-radius:10px;object-fit:contain;display:grid;place-items:center;color:#fff;font-weight:800}
  .plat-card .nm{font-size:12.5px;font-weight:600;color:var(--text)}
  .det-card{border:1px solid var(--border);border-radius:12px;padding:14px;background:var(--surface-2)}
  .rm-btn{width:28px;height:28px;display:grid;place-items:center;border-radius:8px;background:var(--bad-soft);color:var(--bad);border:0;cursor:pointer;margin-bottom:2px}
  .hist{background:var(--surface-2);border:1px solid var(--border);border-radius:12px;padding:13px}
  .hist-tile{background:var(--surface);border:1px solid var(--border);border-radius:9px;padding:9px;text-align:center}
  .hist-tile .t-lbl{font-size:10px;text-transform:uppercase;color:var(--faint);letter-spacing:.03em}
  .hist-tile .t-val{font-weight:700;color:var(--text);font-size:13px;margin-top:2px}
  .wa-toggle{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-top:16px;background:var(--good-soft);border:1px solid color-mix(in srgb,var(--good) 30%,transparent);border-radius:12px;padding:12px 16px;font-size:13px;font-weight:600;color:var(--good);cursor:pointer}
  .wa-toggle small{color:var(--good);font-weight:500}
</style>

<?php if ($flash): ?><div style="background:var(--bad-soft);border:1px solid color-mix(in srgb,var(--bad) 30%,transparent);color:var(--bad);border-radius:12px;padding:12px 16px;margin-bottom:16px;font-size:13px"><?= h($flash) ?></div><?php endif; ?>

<div class="card wz-card">
  <div class="wz-hd">
    <i data-lucide="shopping-cart"></i>
    <h2>Agregar Nueva Venta</h2>
    <div style="margin-left:auto;display:flex;align-items:center;gap:8px">
      <button type="button" onclick="wizardTour()" class="btn ghost" style="padding:6px 12px"><i data-lucide="sparkles"></i> Tour</button>
      <a href="ventas.php" class="iconbtn" style="width:32px;height:32px"><i data-lucide="x"></i></a>
    </div>
  </div>
  <!-- Stepper -->
  <div class="wz-bar"><i id="bar" style="width:25%"></i></div>
  <div class="wz-steps">
    <?php foreach (['Cliente', 'Plataforma', 'Cuenta', 'Detalles'] as $i => $s): ?>
      <div class="step-tab" data-i="<?= $i ?>"><span class="chk hidden">✓ </span><?= $i + 1 ?>. <?= $s ?></div>
    <?php endforeach; ?>
  </div>

  <form method="post" id="wz" enctype="multipart/form-data" class="wz-body">
    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="cliente_id" id="cliente_id">

    <!-- Paso 1 -->
    <div class="step" data-step="0">
      <label class="wz-lbl">Seleccione el Cliente</label>
      <div class="flex gap-2">
        <select id="sel-cliente" onchange="if(window.repreciarFilas)repreciarFilas();autoEmail()" class="input" style="flex:1"><option value="">Seleccione un cliente</option>
          <?php foreach ($clientes as $c): $esDist = (($c['tipo'] ?? 'cliente') === 'distribuidor'); ?><option value="<?= (int) $c['id'] ?>" data-wa="<?= h($c['wa']) ?>" data-nom="<?= h($c['nombre']) ?>" data-email="<?= h((string) ($c['email'] ?? '')) ?>" data-rev="<?= $esDist ? '1' : '0' ?>"><?= h($c['nombre']) ?><?= $esDist ? ' · REVENDEDOR' : '' ?><?= $c['wa'] ? ' · ' . h($c['wa']) : '' ?></option><?php endforeach; ?></select>
        <button type="button" onclick="location.reload()" class="btn ghost" style="padding:0 12px"><i data-lucide="rotate-cw"></i></button>
      </div>
      <div id="aviso-rev" style="display:none;margin-top:8px;padding:8px 11px;border-radius:9px;background:rgba(245,166,35,.10);border:1px solid rgba(245,166,35,.35);font-size:12px;color:var(--warn)">
        <b>Es REVENDEDOR</b> — los precios se llenan con tu <b>Precio Revendedor</b> (el de Plataformas). Puedes editarlos igual.
      </div>
      <!-- CORREO del cliente: con él se le envían sus datos de acceso al confirmar la venta.
           Se autocompleta con el que ya tenga guardado y se guarda en su ficha si lo cambias. -->
      <div style="margin-top:12px">
        <label class="wz-lbl" style="margin-bottom:6px">Correo del cliente <small>(para enviarle sus datos de acceso)</small></label>
        <input name="cliente_email" id="cliente_email" type="email" oninput="this.dataset.tocado='1'" placeholder="cliente@correo.com" class="input">
        <div style="font-size:11.5px;color:var(--faint);margin-top:6px">Si lo dejas vacío no se le envía correo. Queda guardado en su ficha para las próximas ventas.</div>
      </div>
      <details style="margin-top:12px"><summary style="font-size:12px;color:var(--faint);cursor:pointer">¿Cliente nuevo? créalo aquí</summary>
        <div class="grid grid-cols-2 gap-2" style="margin-top:8px"><input name="cliente_nombre" id="cliente_nombre" placeholder="Nombre" class="input"><input name="cliente_wa" id="cliente_wa" placeholder="WhatsApp" class="input"></div></details>
    </div>

    <!-- Paso 2 -->
    <div class="step hidden" data-step="1">
      <label class="wz-lbl">Seleccione la Plataforma <small>(puedes elegir más de una)</small></label>
      <input id="buscar-plat" oninput="filtraPlat()" placeholder="Buscar plataforma…" class="input">
      <div style="font-size:11.5px;color:var(--faint);margin:12px 0 6px">O seleccione plataformas individuales:</div>
      <div id="plat-grid" class="grid grid-cols-2 md:grid-cols-4 gap-2">
        <?php foreach ($plataformas as $p): ?>
          <button type="button" class="plat-card" data-id="<?= (int) $p['id'] ?>" data-nom="<?= h(mb_strtolower($p['nombre'])) ?>" onclick="togglePlat(this)">
            <?php if (!empty($p['logo_url'])): ?><img src="<?= h($p['logo_url']) ?>" class="plat-logo" alt="">
            <?php else: ?><div class="plat-logo" style="background:<?= h($p['color'] ?: '#2DE2D5') ?>"><?= h(mb_strtoupper(mb_substr($p['nombre'], 0, 1))) ?></div><?php endif; ?>
            <div class="nm"><?= h($p['nombre']) ?></div>
          </button>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Paso 3 -->
    <div class="step hidden" data-step="2">
      <label class="wz-lbl">Seleccione la Cuenta</label>
      <div class="flex items-center gap-3" style="margin-bottom:8px">
        <input id="buscar-cta" oninput="renderCuentas()" placeholder="Buscar cuenta o correo…" class="input" style="flex:1">
        <label style="display:flex;align-items:center;gap:6px;font-size:11.5px;font-weight:600;color:var(--muted);white-space:nowrap"><input type="checkbox" id="auto-best" onchange="renderCuentas()" class="accent-teal-500"> Mejor cuenta automática</label>
      </div>
      <div id="reco" class="banner hidden" style="margin-bottom:8px"></div>
      <div class="overflow-x-auto thin" style="border:1px solid var(--border);border-radius:12px">
        <table class="dtable"><thead><tr><th></th><th>Plataforma</th><th>Correo</th><th>Renovación</th><th>Riesgo</th><?php if ($verCostos): ?><th>Margen</th><?php endif; ?><th>Perfiles</th></tr></thead><tbody id="cta-body"></tbody></table>
      </div>
    </div>

    <!-- Paso 4 -->
    <div class="step hidden" data-step="3">
      <label class="wz-lbl">Detalles de la Venta</label>
      <div id="detalle-cards" class="space-y-3"></div>
      <!-- Historial -->
      <div class="hist" style="margin-top:14px"><div style="display:flex;justify-content:space-between;font-size:11.5px;font-weight:700;color:var(--accent);margin-bottom:8px">Historial del cliente <span id="hist-n" style="color:var(--faint);font-weight:500"></span></div>
        <div class="grid grid-cols-3 gap-2">
          <div class="hist-tile"><div class="t-lbl">Última plataforma</div><div id="h-ultima" class="t-val">—</div></div>
          <div class="hist-tile"><div class="t-lbl">Última venta</div><div id="h-fecha" class="t-val">—</div></div>
          <div class="hist-tile"><div class="t-lbl">Ticket promedio</div><div id="h-ticket" class="t-val">$0.00</div></div>
        </div></div>
      <div class="grid md:grid-cols-2 gap-3" style="margin-top:14px">
        <div class="field" style="margin-bottom:0"><label>Método de Pago (opcional)</label><input name="metodo" class="input" placeholder="Sin especificar"></div>
        <div class="field" style="margin-bottom:0"><label>N° Transacción / Boleta (opcional)</label><input name="referencia" class="input" placeholder="Ej: 482910, BOLETA-1458"></div>
      </div>
      <div class="field" style="margin-top:12px;margin-bottom:0"><label>Comprobante de Pago (opcional)</label><input type="file" name="comprobante" accept="image/*,application/pdf" style="font-size:12.5px;color:var(--muted)"></div>
      <label class="wa-toggle">
        <span style="display:flex;align-items:center;gap:8px"><i data-lucide="message-circle" style="width:16px;height:16px"></i> ¿Enviar notificación vía WhatsApp?<br><small>Si está activado, el cliente recibirá sus accesos al confirmar.</small></span>
        <span class="relative inline-block w-11 h-6"><input type="checkbox" name="enviar_wa" value="1" id="tg-wa" checked class="peer sr-only"><span class="absolute inset-0 rounded-full bg-slate-300 peer-checked:bg-emerald-500 transition"></span><span class="absolute left-0.5 top-0.5 w-5 h-5 bg-white rounded-full transition peer-checked:translate-x-5"></span></span>
      </label>
    </div>

    <!-- Nav -->
    <div class="flex justify-between" style="margin-top:22px">
      <button type="button" id="btn-prev" onclick="step(-1)" class="btn ghost hidden">← Anterior</button>
      <a href="ventas.php" class="btn ghost" id="btn-cancel">Cancelar</a>
      <button type="button" id="btn-next" onclick="step(1)" class="btn primary ml-auto">Siguiente →</button>
      <button type="submit" id="btn-confirm" class="btn primary ml-auto hidden">✓ Confirmar Venta</button>
    </div>
  </form>
</div>

<script>
  const PLATS=<?= json_encode($plataformas, JSON_UNESCAPED_UNICODE) ?>, CUENTAS=<?= json_encode($cuentas, JSON_UNESCAPED_UNICODE) ?>, VERCOSTOS=<?= $verCostos ? 'true' : 'false' ?>;

  /* PRECIO REVENDEDOR (15-jul): si el cliente elegido está marcado como DISTRIBUIDOR, el precio se
     autocompleta con el mayorista que tecleaste en Plataformas ("Precio Revendedor / perfil").
     El campo queda editable: esto solo rellena, nunca obliga. */
  function cliEsRev(){
    const s=document.getElementById('sel-cliente'); const o=s&&s.selectedOptions[0];
    return !!(o && o.dataset.rev==='1');
  }
  function precioDe(c){ return (cliEsRev() ? (c.precio_rev||c.precio) : c.precio) || ''; }
  /* Trae el correo guardado del cliente elegido. Si ya escribiste uno a mano (dataset.tocado), no
     te lo pisa: lo que teclea el usuario siempre manda sobre el autocompletado. */
  function autoEmail(){
    const s=document.getElementById('sel-cliente'), inp=document.getElementById('cliente_email');
    if(!s||!inp||inp.dataset.tocado==='1') return;
    const o=s.selectedOptions[0];
    inp.value=(o&&o.dataset.email)||'';
  }
  /* Si cambias el cliente DESPUÉS de armar las filas, re-precia las que no tocaste a mano. */
  function repreciarFilas(){
    const rev=cliEsRev();
    document.querySelectorAll('#detalle-cards [data-cta]').forEach(card=>{
      const c=CUENTAS.find(x=>x.id==card.dataset.cta); if(!c) return;
      card.querySelectorAll('input[name="r_precio[]"]').forEach(inp=>{
        if(inp.dataset.tocado==='1') return;           // respeta lo que el usuario escribió
        inp.value=(rev ? (c.precio_rev||c.precio) : c.precio)||'';
      });
    });
    const av=document.getElementById('aviso-rev'); if(av) av.style.display=rev?'':'none';
  }
  let cur=0, selPlats=new Set(), selCuentas=[];
  function show(){
    document.querySelectorAll('.step').forEach(s=>s.classList.toggle('hidden',+s.dataset.step!==cur));
    document.querySelectorAll('.step-tab').forEach((t,i)=>{ t.className='step-tab '+(i===cur?'on':(i<cur?'done':'')); t.querySelector('.chk').classList.toggle('hidden',i>=cur); });
    document.getElementById('bar').style.width=((cur+1)*25)+'%';
    document.getElementById('btn-prev').classList.toggle('hidden',cur===0);
    document.getElementById('btn-cancel').classList.toggle('hidden',cur!==0);
    document.getElementById('btn-next').classList.toggle('hidden',cur===3);
    document.getElementById('btn-confirm').classList.toggle('hidden',cur!==3);
    if(cur===2) renderCuentas();
    if(cur===3){ renderDetalle(); cargarHistorial(); }
    lucide.createIcons();
  }
  function step(d){ if(d>0 && !validate()) return; cur=Math.max(0,Math.min(3,cur+d)); show(); }
  function validate(){
    if(cur===0){ const id=document.getElementById('sel-cliente').value, nom=document.getElementById('cliente_nombre').value.trim(); if(!id && !nom){ alert('Elige o crea un cliente.'); return false; } document.getElementById('cliente_id').value=id; if(id){ const o=document.getElementById('sel-cliente').selectedOptions[0]; if(!document.getElementById('cliente_nombre').value) document.getElementById('cliente_nombre').value=o.dataset.nom||''; if(!document.getElementById('cliente_wa').value) document.getElementById('cliente_wa').value=o.dataset.wa||''; } }
    if(cur===1){ if(selPlats.size===0){ alert('Elige al menos una plataforma.'); return false; } }
    if(cur===2){ if(selCuentas.length===0){ alert('Elige al menos una cuenta.'); return false; } }
    return true;
  }
  function togglePlat(btn){ const id=+btn.dataset.id; if(selPlats.has(id)){ selPlats.delete(id); btn.classList.remove('sel'); } else { selPlats.add(id); btn.classList.add('sel'); } }
  function filtraPlat(){ const q=document.getElementById('buscar-plat').value.toLowerCase(); document.querySelectorAll('.plat-card').forEach(c=>c.style.display=(!q||c.dataset.nom.includes(q))?'':'none'); }
  function riesgoBadge(r){ const m={alto:'err',medio:'wait',bajo:'ok'}; return `<span class="pill ${m[r]}">${r.charAt(0).toUpperCase()+r.slice(1)}</span>`; }
  function renovBadge(d){ if(d===null) return '<span style="color:var(--faint)">—</span>'; if(d<0) return `<span class="pill err">Vencida (${-d}d)</span>`; if(d<=7) return `<span class="pill wait">En ${d}d</span>`; return `<span class="pill ok">${d}d</span>`; }
  function renderCuentas(){
    const q=(document.getElementById('buscar-cta').value||'').toLowerCase();
    let list=CUENTAS.filter(c=>selPlats.has(c.plataforma_id) && (!q || (c.plataforma+' '+(c.correo||'')).toLowerCase().includes(q)));
    list.sort((a,b)=>(b.libres-a.libres)||((a.dias??9999)-(b.dias??9999)));
    const body=document.getElementById('cta-body'); body.innerHTML = list.length? '' : `<tr><td colspan="${VERCOSTOS?7:6}" class="empty">No hay cuentas con stock para las plataformas elegidas.</td></tr>`;
    // Recomendada = primera (más stock + más urgente)
    const reco=document.getElementById('reco');
    if(list.length){ const r=list[0]; reco.classList.remove('hidden'); reco.innerHTML=`<div><b>Recomendada:</b> ${r.plataforma} · ${r.correo||''} <span style="color:var(--faint)">· ${r.libres} libre(s)${r.dias!=null?' · vence en '+r.dias+'d':''}</span></div><button type="button" onclick="pickCuenta(${r.id},true)" class="btn primary" style="margin-left:auto;padding:6px 12px;font-size:11px">Usar recomendada</button>`; } else reco.classList.add('hidden');
    list.forEach(c=>{
      const sel=selCuentas.includes(c.id);
      const tr=document.createElement('tr');
      tr.innerHTML=`<td><input type="checkbox" ${sel?'checked':''} onchange="pickCuenta(${c.id})" class="accent-teal-500"></td>
        <td style="font-weight:600">${c.plataforma}</td><td style="color:var(--muted);font-size:11.5px">${c.correo||''}</td>
        <td>${renovBadge(c.dias)}</td><td>${riesgoBadge(c.riesgo)}</td>
        ${VERCOSTOS?`<td class="tnum" style="font-weight:700;color:${c.margen>=0?'var(--good)':'var(--bad)'}">${c.margen!=null?(c.margen>=0?'+':'')+c.margen.toFixed(2):'—'}</td>`:''}
        <td><span class="pill acc">${c.libres} disp.</span></td>`;
      body.appendChild(tr);
    });
  }
  function pickCuenta(id,only){ if(only){ selCuentas=[id]; } else { const i=selCuentas.indexOf(id); if(i>=0) selCuentas.splice(i,1); else selCuentas.push(id); } renderCuentas(); }
  function renderDetalle(){
    const box=document.getElementById('detalle-cards');
    const actuales=Array.from(box.querySelectorAll('[data-cta]')).map(el=>+el.dataset.cta);
    if(actuales.length===selCuentas.length && actuales.every(id=>selCuentas.includes(id))) return;
    box.innerHTML='';
    selCuentas.forEach(id=>{
      const c=CUENTAS.find(x=>x.id===id); if(!c) return;
      const card=document.createElement('div'); card.className='det-card'; card.dataset.cta=id;
      card.innerHTML=`<div class="flex items-center justify-between" style="margin-bottom:10px"><div style="font-weight:700">${c.plataforma} <span style="font-size:11.5px;color:var(--faint);font-weight:500">(${c.correo||''})</span></div><span class="pill ok">Disponibles: ${c.libres}</span></div><div class="rows space-y-2"></div><button type="button" onclick="addRow(${id})" class="text-brand-teal" style="margin-top:8px;font-size:12px;font-weight:700;background:none;border:0;cursor:pointer;display:inline-flex;align-items:center;gap:4px"><span>＋ Agregar otro perfil</span></button>`;
      box.appendChild(card); addRow(id);
    });
  }
  function addRow(ctaId){
    const c=CUENTAS.find(x=>x.id===ctaId); const card=document.querySelector(`#detalle-cards [data-cta="${ctaId}"]`); const rows=card.querySelector('.rows');
    if(rows.children.length>=c.libres){ return; }
    const d=new Date(); d.setDate(d.getDate()+(c.dias_default||30)); const fecha=d.toISOString().slice(0,10);
    const opts=(c.perfiles||[]).map(p=>`<option value="${p.id}" data-pin="${(p.pin||'').replace(/"/g,'&quot;')}" data-etiqueta="${(p.etiqueta||('Perfil '+p.id)).replace(/"/g,'&quot;')}">${(p.etiqueta||('Perfil '+p.id)).replace(/[<>]/g,'')}${p.pin?' 🔒':''}</option>`).join('');
    const div=document.createElement('div'); div.className='grid grid-cols-2 md:grid-cols-6 gap-2 items-end';
    div.innerHTML=`<input type="hidden" name="r_cuenta[]" value="${ctaId}">
      <div><label class="mini-lbl">Perfil disponible</label><select name="r_perfilid[]" onchange="onPerfil(this)" class="input"><option value="">Cualquiera libre…</option>${opts}</select></div>
      <div><label class="mini-lbl">Nombre del perfil</label><input name="r_perfil[]" placeholder="Ej: Perfil 1, Juan…" maxlength="60" class="input"></div>
      <div><label class="mini-lbl">PIN</label><input name="r_pin[]" placeholder="(auto si tiene)" maxlength="20" class="input"></div>
      <div><label class="mini-lbl">Fecha</label><input type="date" name="r_fecha[]" value="${fecha}" class="input"></div>
      <div><label class="mini-lbl">Precio venta</label><input name="r_precio[]" type="number" step="0.01" value="${precioDe(c)}" oninput="this.dataset.tocado='1'" class="input"></div>
      <div class="flex gap-1 items-end"><div class="flex-1"><label class="mini-lbl">Precio renov.</label><input name="r_renovacion[]" type="number" step="0.01" value="${precioDe(c)}" class="input"></div><button type="button" onclick="const cd=this.closest('[data-cta]'); this.closest('.grid').remove(); refreshPerfilOpts(cd); const cc=CUENTAS.find(x=>x.id==cd.dataset.cta); const ab=cd.querySelector('button[onclick^=&quot;addRow&quot;]'); if(cc && ab && cd.querySelectorAll('.rows > div').length < cc.libres){ ab.disabled=false; ab.innerHTML='<span>＋ Agregar otro perfil</span>'; }" class="rm-btn"><i data-lucide="x" class="w-3.5 h-3.5"></i></button></div>`;
    rows.appendChild(div); lucide.createIcons(); refreshPerfilOpts(card);
    if(rows.children.length>=c.libres){ const btn=card.querySelector('button[onclick^="addRow"]'); btn.innerHTML='<span style="color:var(--faint)">Máximo alcanzado</span>'; btn.disabled=true; }
  }
  function onPerfil(sel){ const opt=sel.selectedOptions[0]; const row=sel.closest('.grid'); const pinInput=row.querySelector('input[name="r_pin[]"]'); const nameInput=row.querySelector('input[name="r_perfil[]"]'); if(opt && opt.value){ if(pinInput && opt.dataset.pin) pinInput.value=opt.dataset.pin; if(nameInput && !nameInput.value && opt.dataset.etiqueta) nameInput.value=opt.dataset.etiqueta; } refreshPerfilOpts(sel.closest('[data-cta]')); }
  function refreshPerfilOpts(card){ if(!card) return; const used=new Set(); card.querySelectorAll('select[name="r_perfilid[]"]').forEach(s=>{ if(s.value) used.add(s.value); }); card.querySelectorAll('select[name="r_perfilid[]"]').forEach(s=>{ Array.from(s.options).forEach(o=>{ if(o.value) o.disabled = used.has(o.value) && s.value!==o.value; }); }); }
  async function cargarHistorial(){
    const id=document.getElementById('cliente_id').value; if(!id){ return; }
    const r=await fetch('?ajax=historial&cliente_id='+id).then(x=>x.json()); if(!r.ok) return;
    document.getElementById('h-ultima').textContent=r.h.ultima; document.getElementById('h-fecha').textContent=r.h.fecha;
    document.getElementById('h-ticket').textContent='$'+(r.h.ticket||0).toFixed(2); document.getElementById('hist-n').textContent=r.h.n+' venta(s)';
  }
  show();

  // Tour del wizard (botón Tour). conecTour() vive en el shell (stream_foot).
  function wizardTour(){
    if (typeof window.conecTour !== 'function') { alert('Recarga la página para usar el tour.'); return; }
    window.conecTour([
      { title:'Nueva venta en 4 pasos', text:'Cliente → Plataforma → Cuenta → Detalles. Te muestro lo importante en 20 segundos.' },
      { el:'#sel-cliente', title:'1) El cliente', text:'Elige el cliente (o créalo en "¿Cliente nuevo?"). Si tiene WhatsApp guardado, se usa para enviarle sus datos al confirmar.' },
      { el:'.step-tab', title:'Los pasos', text:'En el paso 3 verás las cuentas ordenadas por stock y, si eres el dueño, por margen. La primera es la recomendada.' },
      { el:'#btn-next', title:'Avanzar', text:'Pulsa "Siguiente" para ir paso a paso. Al final pulsas "✓ Confirmar Venta".' },
      { title:'Envío automático 📤', text:'En el último paso, si dejas activado "Enviar por WhatsApp" y el cliente escribió en las últimas 24h, recibe sus accesos SOLO al confirmar. Si no escribió, te avisamos para que se los pases tú.' },
    ], 'conec_tour_wizard_v1', true);
  }
</script>
<?php stream_foot(); ?>
