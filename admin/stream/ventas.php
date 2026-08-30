<?php
/**
 * Ventas (estilo PAC). Tabla de suscripciones + menú de 3 puntos con 6 modales:
 * Notificar (WhatsApp), Renovar (con método/ref/comprobante), Registro (bitácora),
 * Nota (interna), Editar y Eliminar. Reusa helpers de admin/_streaming.php.
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
$hoy = new DateTimeImmutable('today');
// AISLAMIENTO POR DUEÑO (owner_id) SIEMPRE: cada panel (admin=0 / revendedor=su id) solo ve lo suyo.
// Además, un empleado (no dueño) NO ve ni toca ventas del inventario de REVENDEDORES.
// En el panel del REVENDEDOR: ve SUS ventas (owner=su id) + las que el admin le ASIGNÓ o le vendió del
// stock (revendedor_id = su id). Antes las asignadas y sus compras del stock no le salían. #11
$esRevCtx = function_exists('stream_ctx') && stream_ctx() === 'revendedor';
if ($esRevCtx) {
  // El revendedor ve SUS ventas (owner = él). Lo que el admin le asigna llega como venta SUYA
  // (creada por st_rev_entregar con owner=él), así que con owner alcanza — sin duplicar la del admin.
  $scopeRev = 'v.owner_id=' . $OWNER;
} else {
  $scopeRev = 'v.owner_id=' . $OWNER . ($verCostos ? '' : ' AND (v.revendedor_id IS NULL OR v.revendedor_id = 0)');
}

function v_dias(?string $venc, DateTimeImmutable $hoy): ?int {
  if (!$venc) return null;
  $v = DateTimeImmutable::createFromFormat('Y-m-d', substr($venc, 0, 10));
  return $v ? (int) $hoy->diff($v->setTime(0, 0))->format('%r%a') : null;
}
function st_log(PDO $pdo, int $vid, string $evento, string $desc = ''): void {
  try { $pdo->prepare("INSERT INTO streaming_venta_registro (venta_id,evento,descripcion,usuario_id) VALUES (?,?,?,?)")->execute([$vid, $evento, $desc ?: null, current_user_id()]); }
  catch (Throwable $e) {}
}
/** ¿Este usuario puede ver/tocar esta venta? El dueño sí siempre; un empleado NO si la venta es de un revendedor. */
function st_puede_ver(PDO $pdo, int $vid, bool $esDueno): bool {
  if ($esDueno) return true;
  // PANEL DEL REVENDEDOR: manda lo SUYO (owner_id = su id) — puede eliminarlo y, al hacerlo, el
  // perfil vuelve a SU stock. Las ventas que son del ADMIN (owner_id=0) y solo van etiquetadas con
  // su revendedor_id NO las toca: ésas son el registro del admin, ella solo las ve.
  if (function_exists('stream_ctx') && stream_ctx() === 'revendedor') {
    $mio = (int) stream_owner_id();
    try { $o = $pdo->query("SELECT owner_id FROM streaming_ventas WHERE id=" . (int) $vid)->fetchColumn(); }
    catch (Throwable $e) { return false; }
    return ((int) $o === $mio && $mio > 0);
  }
  try { $r = $pdo->query("SELECT revendedor_id FROM streaming_ventas WHERE id=" . (int) $vid)->fetchColumn(); }
  catch (Throwable $e) { return true; }   // si la columna no existe en este entorno, no restringimos
  return ($r === null || (int) $r === 0);
}

// ── AJAX ──
if (isset($_GET['ajax'])) {
  header('Content-Type: application/json; charset=utf-8');
  $id = (int) ($_GET['id'] ?? 0);
  // ¿Venta ASIGNADA a este revendedor (del admin, con revendedor_id = él)? La puede VER para asignarle
  // un cliente / editar la fecha de SU cliente / notificar — NO borrarla (eso se bloquea en el POST).
  $ajOwn = (int) (function_exists('stream_owner_id') ? stream_owner_id() : 0);
  $asigMia = false;
  if ($id > 0 && $esRevCtx && $ajOwn > 0) {
    try { $ck = $pdo->prepare("SELECT 1 FROM streaming_ventas WHERE id=? AND revendedor_id=? LIMIT 1"); $ck->execute([$id, $ajOwn]); $asigMia = (bool) $ck->fetchColumn(); } catch (Throwable $e) {}
  }
  // Guarda de aislamiento (anti-IDOR): la venta DEBE ser del dueño del contexto (o asignada a este revendedor).
  if ($id > 0 && !$asigMia && !st_venta($pdo, $id)) { echo json_encode(['ok' => false]); exit; }
  if (!$asigMia && !st_puede_ver($pdo, $id, $verCostos)) { echo json_encode(['ok' => false]); exit; }
  if ($_GET['ajax'] === 'venta') {
    $v = st_venta($pdo, $id);
    if (!$v && $asigMia) { try { $q = $pdo->prepare("SELECT * FROM streaming_ventas WHERE id=? LIMIT 1"); $q->execute([$id]); $v = $q->fetch(PDO::FETCH_ASSOC) ?: null; } catch (Throwable $e) {} }
    if (!$v) { echo json_encode(['ok' => false]); exit; }
    // Nombre del revendedor: «Notificar» lo usa para avisar que ese cliente NO es tuyo antes de
    // escribirle (cliente_wa en una venta de revendedor es el cliente DE ÉL, no tuyo).
    if (!empty($v['revendedor_id'])) {
      try {
        $qr = $pdo->prepare("SELECT nombre, telefono FROM usuarios WHERE id=?");
        $qr->execute([(int) $v['revendedor_id']]);
        $ru = $qr->fetch(PDO::FETCH_ASSOC) ?: [];
        $v['rev_nombre'] = $ru['nombre'] ?? null;
        $v['rev_wa'] = preg_replace('/\D/', '', (string) ($ru['telefono'] ?? ''));   // #15: para avisar al revendedor
      } catch (Throwable $e) {}
    }
    echo json_encode(['ok' => true, 'venta' => $v, 'msgs' => ['credenciales' => stream_msg_credenciales($v), 'recordatorio' => stream_msg_recordatorio($v), 'aviso_rev' => stream_msg_aviso_revendedor($v)]]);
    exit;
  }
  // ¿Qué OTRAS ventas viven en la misma cuenta? Si la cuenta murió, hay que mover a TODOS sus
  // clientes, no solo al que estás mirando. $scopeRev va SIEMPRE: un empleado no debe ver
  // (ni arrastrar) ventas del inventario de revendedores.
  if ($_GET['ajax'] === 'hermanas') {
    $v = st_venta($pdo, $id);
    $cta = (int) ($v['cuenta_id'] ?? 0);
    if (!$v || $cta <= 0) { echo json_encode(['ok' => true, 'cuenta_id' => 0, 'items' => []]); exit; }
    $q = $pdo->prepare("SELECT v.id, COALESCE(cl.nombre, v.cliente_nombre) AS cliente, v.perfil,
                               v.fecha_vencimiento, v.cliente_wa, ru.nombre AS rev_nombre
                          FROM streaming_ventas v
                          LEFT JOIN streaming_clientes cl ON cl.id = v.cliente_id
                          LEFT JOIN usuarios ru ON ru.id = v.revendedor_id
                         WHERE v.cuenta_id = ? AND v.estado = 'activa' AND v.id <> ? AND $scopeRev
                         ORDER BY v.id");
    $q->execute([$cta, $id]);
    echo json_encode(['ok' => true, 'cuenta_id' => $cta, 'items' => $q->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
  }
  if ($_GET['ajax'] === 'registro') {
    $rows = $pdo->prepare("SELECT r.evento, r.descripcion, r.creado_en, u.nombre AS quien FROM streaming_venta_registro r LEFT JOIN usuarios u ON u.id=r.usuario_id WHERE r.venta_id=? ORDER BY r.creado_en DESC");
    $rows->execute([$id]);
    echo json_encode(['ok' => true, 'items' => $rows->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
  }
  if ($_GET['ajax'] === 'notas') {
    $rows = $pdo->prepare("SELECT n.nota, n.creado_en, u.nombre AS quien FROM streaming_venta_notas n LEFT JOIN usuarios u ON u.id=n.autor_id WHERE n.venta_id=? ORDER BY n.creado_en DESC");
    $rows->execute([$id]);
    echo json_encode(['ok' => true, 'items' => $rows->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
  }
  echo json_encode(['ok' => false]);
  exit;
}

// ── Export CSV (botón Excel) ──
if (($_GET['export'] ?? '') === 'csv') {
  // D5: el admin puede quitarle al revendedor el permiso de exportar sus ventas (anti-secuestro).
  if ($esRevCtx && function_exists('st_rev_puede_exportar') && !st_rev_puede_exportar($pdo)) {
    http_response_code(403);
    exit('El administrador deshabilitó la descarga de tus ventas.');
  }
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="ventas.csv"');
  echo "\xEF\xBB\xBF";
  $out = fopen('php://output', 'w');
  // 'Revendedor' va AL FINAL a propósito: así no rompe ninguna plantilla tuya que lea por posición.
  fputcsv($out, ['N° Pedido', 'Cliente', 'Producto', 'Correo', 'Perfil', 'PIN', 'Precio', 'Renovación', 'Vence', 'Estado', 'Revendedor']);
  try { foreach ($pdo->query("SELECT v.id, COALESCE(cl.nombre,v.cliente_nombre) cliente, v.plataforma, v.correo, v.perfil, v.pin, v.precio, v.precio_renovacion, v.fecha_vencimiento, v.estado, ru.nombre AS rev_nombre FROM streaming_ventas v LEFT JOIN streaming_clientes cl ON cl.id=v.cliente_id LEFT JOIN usuarios ru ON ru.id=v.revendedor_id WHERE $scopeRev ORDER BY v.id DESC") as $r)
    fputcsv($out, [stream_cod_pedido((int) $r['id']), $r['cliente'], $r['plataforma'], $r['correo'], $r['perfil'], "\t" . $r['pin'], $r['precio'], $r['precio_renovacion'], $r['fecha_vencimiento'], $r['estado'], $r['rev_nombre'] ?: '']); } catch (Throwable $e) {}
  fclose($out); exit;
}

// ── POST ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $a = $_POST['accion'] ?? '';
  $msg = '';
  try {
    $vid = (int) ($_POST['id'] ?? 0);
    // ¿Venta ASIGNADA a este revendedor? Puede EDITARLA (solo cliente + fecha, ver handler) y NOTIFICAR
    // a su cliente — pero NO eliminarla/reemplazarla/etc. (esas siguen bloqueadas por st_puede_ver).
    $pOwn = (int) (function_exists('stream_owner_id') ? stream_owner_id() : 0);
    $asigMia = false;
    if ($esRevCtx && $vid > 0 && $pOwn > 0) {
      try { $ck = $pdo->prepare("SELECT 1 FROM streaming_ventas WHERE id=? AND revendedor_id=? LIMIT 1"); $ck->execute([$vid, $pOwn]); $asigMia = (bool) $ck->fetchColumn(); } catch (Throwable $e) {}
    }
    $permAsignada = $asigMia && ($a === 'editar_venta' || $a === 'notificar');
    // OJO: este guard NO protege al LOTE — con id=0, st_puede_ver hace fetchColumn()→false y
    // (int)false===0 en su return → devuelve TRUE (fail-open). El lote valida el permiso EN SQL
    // sobre todo el conjunto (ver 'lote_ventas'), no fila por fila.
    if ($a !== 'lote_ventas' && !$permAsignada && !st_puede_ver($pdo, $vid, $verCostos)) throw new Exception('No tienes acceso a esa venta (es de un revendedor).');
    // Guarda de aislamiento por dueño (anti-IDOR): la venta DEBE ser del contexto actual (o asignada a él).
    if ($a !== 'lote_ventas' && $vid > 0 && !$permAsignada && !st_venta($pdo, $vid)) throw new Exception('Venta no encontrada.');
    // Precio que la TIENDA le cobra a un REVENDEDOR por renovar (de su saldo): 1º el de renovación de la
    // plataforma (reventa), si no el de compra (precio_distribuidor, lo mismo que pagó al comprar), y como
    // último recurso el precio de la venta (para no renovar NUNCA gratis). Se usa en renovar individual y lote.
    $costoRenovRev = function (string $platName, $ventaPrecioRenov, $ventaPrecio) use ($pdo): float {
      $c = null;
      try {
        $qc = $pdo->prepare("SELECT COALESCE(
            (SELECT precio_renovacion FROM streaming_plataformas WHERE nombre=? AND owner_id=0 AND precio_renovacion IS NOT NULL AND precio_renovacion>0 ORDER BY id LIMIT 1),
            (SELECT precio_distribuidor FROM streaming_plataformas WHERE nombre=? AND owner_id=0 AND precio_distribuidor IS NOT NULL AND precio_distribuidor>0 ORDER BY id LIMIT 1)
          )");
        $qc->execute([$platName, $platName]); $c = $qc->fetchColumn();
      } catch (Throwable $e) {}
      $c = ($c !== null && $c !== false) ? (float) $c : 0.0;
      if ($c <= 0) { $c = ((float) ($ventaPrecioRenov ?? 0)) ?: (float) ($ventaPrecio ?? 0); }
      return round($c, 2);
    };
    if ($a === 'notificar') {
      $v = st_venta($pdo, $vid);
      $texto = trim((string) ($_POST['mensaje'] ?? ''));
      if (!$v || $texto === '') throw new Exception('Faltan datos.');
      if (empty($v['cliente_wa'])) throw new Exception('La venta no tiene número de WhatsApp.');
      $r = stream_wa_enviar($pdo, $v['cliente_wa'], (string) $v['cliente_nombre'], $texto);
      if ($r['ok']) { $pdo->prepare("UPDATE streaming_ventas SET entregada=1 WHERE id=?")->execute([$vid]); st_log($pdo, $vid, 'notificada', 'Mensaje enviado por WhatsApp'); $msg = '✓ Mensaje enviado por WhatsApp.'; }
      else $msg = '⚠ No se pudo enviar: ' . ($r['error'] === 'ventana_cerrada' ? 'ventana de 24h cerrada' : ($r['error'] ?? 'error'));
    }
    elseif ($a === 'activar') {
      // Canva/entrega por correo: el empleado ya envió la invitación → marcar activada.
      $pdo->prepare("UPDATE streaming_ventas SET entregada=1 WHERE id=?")->execute([$vid]);
      st_log($pdo, $vid, 'activada', 'Activada manualmente (invitación enviada al correo del cliente)');
      $msg = '✓ Marcada como activada.';
      // Correo de credenciales (agregado): mismo caso que pendientes.php — aquí es donde una venta
      // "por invitación/activación" pasa a tener datos reales por primera vez.
      try { stream_email_notificar_venta($pdo, $vid, 'compra'); } catch (Throwable $e) {}
    }
    elseif ($a === 'renovar') {
      $hasta = trim((string) ($_POST['hasta'] ?? ''));
      if ($hasta !== '' && strtotime($hasta)) { $nueva = date('Y-m-d', strtotime($hasta)); $meses = null; }
      else {
        $meses = max(1, min(24, (int) ($_POST['meses'] ?? 1)));
        $f = $pdo->query("SELECT fecha_vencimiento FROM streaming_ventas WHERE id=$vid")->fetchColumn();
        $base = ($f && strtotime($f) > time()) ? $f : date('Y-m-d');
        $nueva = date('Y-m-d', strtotime($base . " +$meses months"));
      }
      // COBRO AL REVENDEDOR (bug reportado: "renovaba sin saldo"). Si quien renueva es un REVENDEDOR, la
      // tienda le cobra el precio de renovación de SU saldo — igual que al comprar. Si no le alcanza, NO se
      // renueva (se aborta ANTES de tocar la fecha). El ADMIN sigue renovando sin cobro (es su inventario).
      if ($esRevCtx) {
        $vr = $pdo->query("SELECT plataforma, precio_renovacion, precio FROM streaming_ventas WHERE id=$vid")->fetch(PDO::FETCH_ASSOC) ?: [];
        $platName = (string) ($vr['plataforma'] ?? '');
        $costoRenov = $costoRenovRev($platName, $vr['precio_renovacion'] ?? null, $vr['precio'] ?? null);
        if ($costoRenov > 0) {
          require_once __DIR__ . '/../../api/wallet/_helpers.php';
          $mio = (int) current_user_id();
          if (!function_exists('wallet_debitar') || !wallet_debitar($pdo, $mio, $costoRenov, 'renovacion_streaming', 'Renovación ' . ($platName ?: 'streaming'))) {
            $sal = function_exists('wallet_saldo') ? wallet_saldo($pdo, $mio) : 0.0;
            throw new Exception('⚠ Saldo insuficiente para renovar: cuesta $' . number_format($costoRenov, 2) . ' y tu saldo es $' . number_format($sal, 2) . '. Recarga tu saldo y vuelve a intentar.');
          }
        }
      }
      $pdo->prepare("UPDATE streaming_ventas SET fecha_vencimiento=?, estado='activa', recordado=0, recordado_at=NULL WHERE id=?")->execute([$nueva, $vid]);
      // Al renovar se reinicia el contador de cambios de nombre/PIN del perfil de esta venta (2 nuevos).
      try { $pdo->prepare("UPDATE streaming_perfiles SET cambios_np=0 WHERE venta_id=?")->execute([$vid]); } catch (Throwable $e) {}
      // Si esta venta es de un REVENDEDOR, propaga la nueva fecha a SU cuenta espejo (para que a él también
      // se le renueve y NO se le borre por vencida al pasar el barrido). Antes solo se renovaba la del admin.
      if (!$esRevCtx) { try {
        $vinf = $pdo->query("SELECT cuenta_id, revendedor_id FROM streaming_ventas WHERE id=$vid")->fetch(PDO::FETCH_ASSOC);
        if ($vinf && (int) ($vinf['revendedor_id'] ?? 0) > 0 && (int) ($vinf['cuenta_id'] ?? 0) > 0 && function_exists('st_rev_propagar_vencimiento')) {
          $pids = array_map('intval', $pdo->query("SELECT id FROM streaming_perfiles WHERE venta_id=$vid")->fetchAll(PDO::FETCH_COLUMN));
          st_rev_propagar_vencimiento($pdo, (int) $vinf['cuenta_id'], $nueva, $pids);
        }
      } catch (Throwable $e) {} }
      // Registrar el pago de la renovación (método/ref/comprobante)
      $comp = null;
      if (!empty($_FILES['comprobante']['tmp_name']) && is_uploaded_file($_FILES['comprobante']['tmp_name']) && (int) $_FILES['comprobante']['error'] === 0 && (int) $_FILES['comprobante']['size'] <= 4 * 1024 * 1024) {
        $ext = strtolower(pathinfo($_FILES['comprobante']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['png', 'jpg', 'jpeg', 'webp', 'pdf'], true)) {
          $dir = __DIR__ . '/../../uploads/streaming-comprobantes'; @mkdir($dir, 0755, true);
          $fn = 'venta-' . $vid . '-' . time() . '.' . $ext;
          if (@move_uploaded_file($_FILES['comprobante']['tmp_name'], $dir . '/' . $fn)) $comp = '/uploads/streaming-comprobantes/' . $fn;
        }
      }
      $metodo = trim((string) ($_POST['metodo'] ?? '')) ?: null;
      $ref = trim((string) ($_POST['referencia'] ?? '')) ?: null;
      // Cobrar el PRECIO DE RENOVACIÓN si está definido; si no, cae al precio de venta.
      // Precio de renovación: 1º el de ESTA venta. 2º el de la PLATAFORMA (Tipos de cuenta), pero SOLO si es
      // una venta a un REVENDEDOR (la tienda le renueva al revendedor). En tus ventas DIRECTAS a clientes NO
      // se aplica el de la plataforma: mantienen su precio manual, para no descuadrar tus estadísticas.
      // 3º el precio de venta. (Acotado a petición del cliente 17/08.)
      $ctxAdmin = $esRevCtx ? 0 : 1;
      $precio = (float) ($pdo->query("SELECT COALESCE(v.precio_renovacion, CASE WHEN COALESCE(v.revendedor_id,0)>0 AND $ctxAdmin=1 THEN (SELECT pl.precio_renovacion FROM streaming_plataformas pl WHERE pl.nombre=v.plataforma AND pl.precio_renovacion IS NOT NULL ORDER BY (pl.owner_id=0) DESC LIMIT 1) ELSE NULL END, v.precio) FROM streaming_ventas v WHERE v.id=$vid")->fetchColumn() ?: 0);
      try {
        $pdo->prepare("INSERT INTO streaming_venta_pagos (venta_id,tipo,metodo,referencia,monto,meses,comprobante_url,creado_por) VALUES (?,?,?,?,?,?,?,?)")
            ->execute([$vid, 'renovacion', $metodo, $ref, $precio, $meses, $comp, current_user_id()]);
        $pdo->prepare("UPDATE streaming_ventas SET metodo_pago=COALESCE(?,metodo_pago), referencia=COALESCE(?,referencia), comprobante_url=COALESCE(?,comprobante_url) WHERE id=?")->execute([$metodo, $ref, $comp, $vid]);
      } catch (Throwable $e) {}
      st_log($pdo, $vid, 'renovada', 'Renovada hasta ' . date('d/m/Y', strtotime($nueva)) . ($metodo ? " · $metodo" : ''));
      $msg = '✓ Renovada hasta ' . date('d/m/Y', strtotime($nueva)) . '.';
      // AVISO POR CORREO (agregado): al cliente su renovación y al dueño de la operación su copia.
      // Va al FINAL, con la fecha nueva ya guardada, y en try/catch: si el correo falla la renovación
      // sigue siendo válida. Esta rama NO corre dentro de una transacción, así que es seguro aquí.
      try {
        $nMail = stream_email_notificar_venta($pdo, $vid, 'renovacion');
        if ($nMail) $msg .= " ✉ $nMail correo(s) enviado(s).";
      } catch (Throwable $e) {}
    }
    elseif ($a === 'nota') {
      $nota = trim((string) ($_POST['nota'] ?? ''));
      if ($nota === '') throw new Exception('La nota está vacía.');
      $pdo->prepare("INSERT INTO streaming_venta_notas (venta_id,nota,autor_id) VALUES (?,?,?)")->execute([$vid, $nota, current_user_id()]);
      st_log($pdo, $vid, 'nota', 'Nota interna agregada');
      $msg = '✓ Nota guardada.';
    }
    elseif ($a === 'editar_venta') {
      $venc = ($_POST['fecha_vencimiento'] ?? '') ?: null;
      // El REVENDEDOR solo edita TODO si es cuenta completa; si es perfil, solo puede tocar los datos
      // de SU cliente (nombre, WhatsApp, precio) y la fecha de vencimiento — no el correo/clave/perfil/PIN.
      $vtipo = (string) ($pdo->query("SELECT tipo FROM streaming_ventas WHERE id=$vid")->fetchColumn() ?: 'perfil');
      // El revendedor edita SOLO cliente/WhatsApp/precio/fecha si: es un perfil, o es una venta ASIGNADA
      // por el admin (para poder asignarle un cliente y darle la fecha a SU cliente / garantía). Cuenta
      // completa PROPIA → edita todo. Guarda de aislamiento en el WHERE (no puede tocar ventas ajenas).
      if ($esRevCtx && ($vtipo !== 'cuenta' || !empty($asigMia))) {
        // El revendedor edita: cliente, WhatsApp, PRECIO, MÉTODO DE PAGO, fecha del cliente y NOMBRE/PIN
        // del perfil (máx 2 veces por periodo; se reinicia al renovar; cuenta completa sin límite).
        // NUNCA toca plataforma / correo / clave.
        $nPerfil = trim((string) ($_POST['perfil'] ?? ''));
        $nPin    = trim((string) ($_POST['pin'] ?? ''));
        if ($nPerfil !== '' || $nPin !== '') {
          $pf = $pdo->query("SELECT id, COALESCE(cambios_np,0) AS cn FROM streaming_perfiles WHERE venta_id=$vid LIMIT 1")->fetch(PDO::FETCH_ASSOC);
          $esCompletaV = ($vtipo === 'cuenta');
          if ($pf && !$esCompletaV && (int) $pf['cn'] >= 2) {
            throw new Exception('⚠ Ya cambiaste el nombre/PIN 2 veces este periodo. Se reinicia al renovar.');
          }
          if ($pf) {
            $sp = []; $ap = [];
            if ($nPerfil !== '') { $sp[] = 'etiqueta=?'; $ap[] = mb_substr($nPerfil, 0, 60); }
            if ($nPin !== '')    { $sp[] = 'pin=?'; $ap[] = $nPin; }
            if ($sp) { $ap[] = (int) $pf['id']; $pdo->prepare("UPDATE streaming_perfiles SET " . implode(',', $sp) . " WHERE id=?")->execute($ap); }
            if (!$esCompletaV) { try { $pdo->prepare("UPDATE streaming_perfiles SET cambios_np=COALESCE(cambios_np,0)+1 WHERE id=?")->execute([(int) $pf['id']]); } catch (Throwable $e) {} }
          }
        }
        // El precio que edita el revendedor es SU PRECIO DE VENTA (lo que le cobra a su cliente), NO el
        // costo. Se guarda en precio Y en precio_venta_cliente para que quede claro que es el de venta.
        $pVentaRev = ($_POST['precio'] ?? '') !== '' ? (float) str_replace(',', '.', (string) $_POST['precio']) : null;
        $pdo->prepare("UPDATE streaming_ventas SET cliente_nombre=?, cliente_wa=?, perfil=COALESCE(?,perfil), pin=COALESCE(?,pin), precio=?, precio_venta_cliente=?, metodo_pago=?, fecha_vencimiento=COALESCE(?, fecha_vencimiento) WHERE id=? AND (owner_id=? OR revendedor_id=?)")
            ->execute([
              trim((string) ($_POST['cliente_nombre'] ?? '')) ?: null, wa_norm((string) ($_POST['cliente_wa'] ?? '')) ?: null,
              $nPerfil !== '' ? $nPerfil : null, $nPin !== '' ? $nPin : null,
              $pVentaRev, $pVentaRev,
              trim((string) ($_POST['metodo_pago'] ?? '')) ?: null,
              $venc, $vid, $pOwn, $pOwn,
            ]);
      } else {
        $pdo->prepare("UPDATE streaming_ventas SET cliente_nombre=?, cliente_wa=?, plataforma=?, perfil=?, pin=?, correo=?, clave=?, precio=?, fecha_vencimiento=COALESCE(?, fecha_vencimiento), metodo_pago=?, notas=? WHERE id=?")
            ->execute([
              trim((string) ($_POST['cliente_nombre'] ?? '')) ?: null, wa_norm((string) ($_POST['cliente_wa'] ?? '')) ?: null,
              trim((string) ($_POST['plataforma'] ?? '')) ?: 'Streaming', trim((string) ($_POST['perfil'] ?? '')) ?: null,
              trim((string) ($_POST['pin'] ?? '')) ?: null, trim((string) ($_POST['correo'] ?? '')) ?: null,
              trim((string) ($_POST['clave'] ?? '')) ?: null, ($_POST['precio'] ?? '') !== '' ? (float) $_POST['precio'] : null,
              $venc, trim((string) ($_POST['metodo_pago'] ?? '')) ?: null, trim((string) ($_POST['notas'] ?? '')) ?: null, $vid,
            ]);
      }
      st_log($pdo, $vid, 'editada', 'Venta editada');
      $msg = '✓ Venta actualizada.';
    }
    // ── REEMPLAZAR: mover una venta a OTRA cuenta (misma plataforma u otra) ────────────────────
    // Para cuando una cuenta muere/la cambia el proveedor y hay que reubicar al cliente sin borrar
    // la venta (se conserva vencimiento, precio, cliente, historial y a quién se le cobró).
    // Libera el perfil viejo, reclama uno libre en la cuenta nueva y copia las credenciales nuevas.
    elseif ($a === 'reemplazar_venta') {
      $nuevaCta = (int) ($_POST['cuenta_id'] ?? 0);
      $todas    = !empty($_POST['todas']);    // la cuenta vieja MURIÓ → mover a todos sus clientes
      $avisar   = !empty($_POST['avisar']);
      if ($nuevaCta <= 0) throw new Exception('Elige la cuenta destino.');
      $v = st_venta($pdo, $vid);
      if (!$v) throw new Exception('Esa venta ya no existe.');
      $ctaVieja = (int) ($v['cuenta_id'] ?? 0);
      if ($ctaVieja === $nuevaCta) throw new Exception('Esa venta YA está en esa cuenta.');

      // Qué ventas se mueven. Con "todas", las demás ACTIVAS de la misma cuenta vieja.
      // $scopeRev va SIEMPRE: un empleado no puede arrastrar ventas de revendedores que ni ve.
      $ids = [$vid];
      if ($todas && $ctaVieja > 0) {
        $qh = $pdo->query("SELECT v.id FROM streaming_ventas v
                            WHERE v.cuenta_id = $ctaVieja AND v.estado = 'activa' AND $scopeRev");
        $ids = array_values(array_unique(array_merge([$vid], array_map('intval', $qh->fetchAll(PDO::FETCH_COLUMN)))));
      }

      $movidas = [];
      $pdo->beginTransaction();
      try {
        // FOR UPDATE: sin el candado, dos empleados reemplazando a la vez (o una compra de
        // revendedor en vuelo) pueden reclamar el MISMO perfil libre y entregárselo a dos clientes.
        $q = $pdo->prepare("SELECT c.*, pl.nombre AS plat_nombre, COALESCE(pl.modo_entrega,'perfil') AS modo
                              FROM streaming_cuentas c
                              LEFT JOIN streaming_plataformas pl ON pl.id = c.plataforma_id AND pl.owner_id = c.owner_id
                             WHERE c.id = ? AND c.owner_id = ? FOR UPDATE");
        $q->execute([$nuevaCta, $OWNER]);
        $cta = $q->fetch(PDO::FETCH_ASSOC);
        if (!$cta) throw new Exception('La cuenta destino no existe.');
        $esEmailManual = ((string) $cta['modo'] === 'email_manual');
        $platNue = (string) ($cta['plat_nombre'] ?: $cta['plataforma'] ?: $v['plataforma']);

        // CUÁNTOS perfiles necesita CADA venta, contado ANTES de liberar: no todas son de 1 perfil
        // (hay de 2 y de 3). Reclamar siempre 1 las degradaría en silencio: el cliente pagó por 3 y
        // se quedaría con 1. 'COMPLETA' no es un caso aparte — es una plataforma propia con 1 perfil.
        $qn = $pdo->prepare("SELECT COUNT(*) FROM streaming_perfiles WHERE venta_id=? AND estado='vendido'");
        $need = [];
        foreach ($ids as $id) { $qn->execute([$id]); $need[$id] = max(1, (int) $qn->fetchColumn()); }
        $totalNeed = array_sum($need);

        // Chequeo TOTAL por adelantado: si son 5 ventas y la cuenta destino tiene 3 perfiles, mejor
        // abortar entero que mover 3 y dejar 2 clientes sin nada a mitad de camino.
        if (!$esEmailManual) {
          $qc = $pdo->prepare("SELECT COUNT(*) FROM streaming_perfiles WHERE cuenta_id=? AND estado='libre'");
          $qc->execute([$nuevaCta]);
          $hay = (int) $qc->fetchColumn();
          if ($hay < $totalNeed) {
            throw new Exception('La cuenta destino tiene ' . $hay . ' perfil(es) libre(s) y ' . count($ids)
                              . ' venta(s) necesitan ' . $totalNeed . '. Elige otra cuenta o mueve menos.');
          }
        }

        $upFree  = $pdo->prepare("UPDATE streaming_perfiles SET estado='libre', venta_id=NULL WHERE venta_id=? AND estado='vendido'");
        $upClaim = $pdo->prepare("UPDATE streaming_perfiles SET estado='vendido', venta_id=? WHERE id=? AND estado='libre'");
        $upVenta = $pdo->prepare("UPDATE streaming_ventas SET cuenta_id=?, plataforma=?, perfil=?, pin=?, correo=?, clave=? WHERE id=?");

        foreach ($ids as $id) {
          // Liberar los perfiles viejos. AND estado='vendido' (igual que en eliminar_venta): sin ese
          // filtro también devolvería a 'libre' los que la reconciliación de PAC marcó 'inactivo'
          // → stock inflado y venderías lo que no tienes.
          $upFree->execute([$id]);

          $etiqueta = null; $pin = null;
          if (!$esEmailManual) {
            $n = $need[$id];
            $p = $pdo->prepare("SELECT id, etiqueta, pin FROM streaming_perfiles
                                 WHERE cuenta_id=? AND estado='libre' ORDER BY id LIMIT $n FOR UPDATE");
            $p->execute([$nuevaCta]);
            $libres = $p->fetchAll(PDO::FETCH_ASSOC);
            if (count($libres) < $n) throw new Exception('Se acabaron los perfiles libres a mitad del reemplazo. No se movió nada.');
            $etqs = []; $pins = [];
            foreach ($libres as $lib) {
              $upClaim->execute([$id, (int) $lib['id']]);
              if ($upClaim->rowCount() !== 1) throw new Exception('Otro usuario tomó ese perfil justo ahora. Reintenta.');
              if ($lib['etiqueta']) $etqs[] = $lib['etiqueta'];
              if ($lib['pin']) $pins[] = $lib['pin'];
            }
            $etiqueta = $etqs ? implode(', ', $etqs) : null;
            $pin = $pins ? implode(', ', $pins) : null;
          }
          $upVenta->execute([$nuevaCta, $platNue, $etiqueta, $pin, $cta['correo'] ?: null, $cta['clave'] ?: null, $id]);
          $movidas[] = $id;
        }
        $pdo->commit();
      } catch (Throwable $e) {
        try { if ($pdo->inTransaction()) $pdo->rollBack(); } catch (Throwable $e2) {}
        throw $e;
      }

      foreach ($movidas as $id) st_log($pdo, $id, 'reemplazada', "Reemplazada: cuenta #$ctaVieja → cuenta #$nuevaCta" . (count($movidas) > 1 ? ' (lote de ' . count($movidas) . ')' : ''));

      // ── Avisar ─────────────────────────────────────────────────────────────────────────────────
      // FUERA de la transacción a propósito: son llamadas de red (Evolution/push), y sostener
      // candados de BD mientras se espera a un HTTP ajeno traba a todo el que quiera esos perfiles.
      //
      // A QUIÉN se avisa depende de DE QUIÉN es la venta:
      //  · Venta propia      → WhatsApp al cliente. El mensaje manda un LINK privado
      //    (stream_msg_credenciales), NUNCA la clave en el texto: fue justo mandar credenciales por
      //    WhatsApp lo que provocó el baneo permanente de la WABA. El link lee la BD en vivo → ya
      //    muestra los datos nuevos.
      //  · Venta de REVENDEDOR → aviso al REVENDEDOR en su panel (+ push), NUNCA a su cliente.
      //    En esas ventas cliente_wa es el cliente DEL REVENDEDOR: escribirle desde el número de
      //    CONEC.VE con un link de conecta2ve.com le revela que su proveedor nos compra a nosotros
      //    y le pasa por encima al revendedor (que vende con su propia marca). Él le avisa a su gente.
      $avisados = 0; $fallidos = 0; $sinWa = 0; $revAvisados = 0;
      if ($avisar) {
        $porRev = [];   // revendedor_id => nº de sus clientes movidos
        foreach ($movidas as $id) {
          $vv = st_venta($pdo, $id);
          if (!$vv) continue;
          $rid = (int) ($vv['revendedor_id'] ?? 0);
          if ($rid > 0) { $porRev[$rid] = ($porRev[$rid] ?? 0) + 1; continue; }
          if (empty($vv['cliente_wa'])) { $sinWa++; continue; }
          $r = stream_wa_enviar($pdo, (string) $vv['cliente_wa'], (string) $vv['cliente_nombre'], stream_msg_credenciales($vv));
          if (!empty($r['ok'])) { $avisados++; st_log($pdo, $id, 'notificada', 'Aviso automático de cambio de cuenta'); }
          else { $fallidos++; st_log($pdo, $id, 'aviso_fallido', 'No se pudo avisar: ' . ($r['error'] ?? 'error')); }
        }
        if ($porRev) {
          try {
            require_once __DIR__ . '/../../api/_rev_avisos.php';
            if (function_exists('rev_aviso_crear')) {
              foreach ($porRev as $rid => $cnt) {
                $t = "🔄 Cambiamos de cuenta a " . ($cnt === 1 ? 'un cliente tuyo' : "$cnt clientes tuyos");
                $m = "Movimos " . ($cnt === 1 ? 'a 1 cliente tuyo' : "a $cnt clientes tuyos") . " de "
                   . (trim((string) ($v['plataforma'] ?? '')) ?: 'streaming') . " a otra cuenta porque la anterior "
                   . "dejó de servir. Los datos nuevos ya están en «Mis Streaming»: revísalos y avísales tú.";
                if (rev_aviso_crear($pdo, (int) $rid, 'credenciales', $t, $m, '/revendedor/#streaming')) $revAvisados++;
              }
            }
          } catch (Throwable $e) {}
        }
      }

      $n = count($movidas);
      $msg = '✓ ' . $n . ' venta(s) reemplazada(s) a la cuenta #' . $nuevaCta . '.';
      if ($avisar) {
        if ($avisados)    $msg .= " Clientes avisados: $avisados.";
        if ($revAvisados) $msg .= " Revendedores avisados: $revAvisados (ellos le avisan a sus clientes).";
        // Se dice EXACTO a cuántos no se les avisó: si no, el dueño cree que sus clientes ya saben
        // y se entera por el reclamo.
        if ($fallidos) $msg .= " ⚠ $fallidos no recibieron el aviso (ventana de 24h cerrada o WhatsApp caído) → avísales a mano con «Notificar».";
        if ($sinWa)    $msg .= " ⚠ $sinWa sin número de WhatsApp.";
      } else {
        $msg .= ' Recuerda avisarles con «Notificar».';
      }
      if ($fallidos || $sinWa) $msg = '⚠' . mb_substr($msg, 1);   // que el banner salga en rojo, no en verde
    }
    elseif ($a === 'eliminar_venta') {
      // Info ANTES de borrar: si es una venta del ADMIN a un REVENDEDOR, al eliminarla hay que quitarle
      // su cuenta espejo (que ya no la tenga); el perfil del admin, aparte, vuelve al stock del admin.
      $vinfo = $pdo->query("SELECT revendedor_id, cuenta_id FROM streaming_ventas WHERE id=" . (int) $vid)->fetch(PDO::FETCH_ASSOC) ?: [];
      st_log($pdo, $vid, 'eliminada', 'Venta eliminada desde el panel');   // ANTES del DELETE: el registro no tiene FK y sobrevive
      // AND estado='vendido': sin ese filtro también devolvía a 'libre' los perfiles que la
      // reconciliación de PAC marcó 'inactivo' → stock inflado y vendías lo que no tienes.
      // #16: el perfil vuelve al stock como P{n} (su posición en la cuenta), NO con el nombre del
      // cliente que le quedó de la venta.
      $frows = $pdo->query("SELECT id, cuenta_id FROM streaming_perfiles WHERE venta_id=" . (int) $vid . " AND estado='vendido'")->fetchAll(PDO::FETCH_ASSOC);
      $pdo->prepare("UPDATE streaming_perfiles SET estado='libre', venta_id=NULL WHERE venta_id=? AND estado='vendido'")->execute([$vid]);
      $rnP = $pdo->prepare("UPDATE streaming_perfiles SET etiqueta=? WHERE id=?");
      foreach ($frows as $fr) { $pos = (int) $pdo->query("SELECT COUNT(*) FROM streaming_perfiles WHERE cuenta_id=" . (int) $fr['cuenta_id'] . " AND id<=" . (int) $fr['id'])->fetchColumn(); $rnP->execute(['P' . max(1, $pos), (int) $fr['id']]); }
      $pdo->prepare("DELETE FROM streaming_ventas WHERE id=?")->execute([$vid]);
      // Si la borró el ADMIN y era una venta a un revendedor → quitarle su cuenta espejo de todos lados.
      $qRev = 0;
      if (!$esRevCtx && (int) ($vinfo['revendedor_id'] ?? 0) > 0 && (int) ($vinfo['cuenta_id'] ?? 0) > 0 && function_exists('st_rev_quitar_por_origen')) {
        // Pasa los perfiles del admin de ESTA venta → al revendedor se le quita SOLO ese perfil (no toda
        // la cuenta si tiene otros perfiles del mismo correo); el bot desasigna solo si no le queda ninguno.
        try { $qRev = st_rev_quitar_por_origen($pdo, (int) $vinfo['revendedor_id'], (int) $vinfo['cuenta_id'], array_column($frows, 'id')); } catch (Throwable $e) {}
      }
      $msg = $qRev > 0
        ? '✓ Venta eliminada. El perfil vuelve a TU stock y se le quitó al revendedor.'
        : '✓ Venta eliminada. El perfil vuelve al stock (renombrado P#).';
    }
    // ── LOTE de ventas: renovar / eliminar varias ─────────────────────────────────────────────
    // ids en UN solo campo ("12,45,78"): con ids[] el límite max_input_vars (1000) los trunca EN
    // SILENCIO y creerías que renovaste todas (esta tabla carga hasta 3000 filas).
    elseif ($a === 'lote_ventas') {
      $op  = (string) ($_POST['op'] ?? '');
      $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string) ($_POST['ids'] ?? ''))))));
      if (!$ids) throw new Exception('No seleccionaste ninguna venta.');
      if (count($ids) > 200) throw new Exception('Máximo 200 ventas por lote (seleccionaste ' . count($ids) . ').');
      $in = implode(',', $ids);
      // PERMISO EN SQL (no fila por fila): aislamiento por dueño SIEMPRE (owner_id = contexto). El filtro
      // extra "sin revendedor_id" es SOLO para EMPLEADOS del admin (que no deben tocar ventas atribuidas a
      // revendedores). NO aplica al REVENDEDOR: sus PROPIAS ventas llevan revendedor_id = su id, así que ese
      // filtro la excluía a ella misma → "omitidas: son de revendedores" al intentar borrar SUS ventas. #bugfix
      $gate = 'owner_id=' . $OWNER;
      if (!$verCostos && !$esRevCtx) { $gate .= ' AND (revendedor_id IS NULL OR revendedor_id = 0)'; }

      $pdo->beginTransaction();
      try {
        // FOR UPDATE: evita la carrera contra compras en vivo mientras liberamos perfiles.
        // El filtro "estado <> 'cancelada'" SOLO aplica al RENOVAR (una venta cancelada NO se revive:
        // st_eliminar_cuenta() las deja con cuenta_id=NULL para que no queden zombis). Al ELIMINAR sí se
        // pueden borrar las canceladas (son justamente las FANTASMA que el revendedor quiere limpiar).
        $estadoGate = ($op === 'eliminar') ? '' : " AND estado <> 'cancelada'";
        $rows = $pdo->query("SELECT id, revendedor_id, cuenta_id, fecha_inicio, fecha_vencimiento, plataforma, precio_renovacion, precio
                               FROM streaming_ventas
                              WHERE id IN ($in) AND $gate $estadoGate FOR UPDATE")->fetchAll(PDO::FETCH_ASSOC);
        $saltadas = count($ids) - count($rows);
        $n = 0;
        $revsFlush = [];   // revendedores nuevos a los que hay que asignarles en el bot (tras el commit)
        $idsRenovadas = []; // ventas realmente renovadas → llevan aviso por correo DESPUÉS del commit

        if ($op === 'renovar') {
          $hasta = trim((string) ($_POST['hasta'] ?? ''));
          if ($hasta !== '' && (!strtotime($hasta) || strtotime($hasta) < strtotime('today')))
            throw new Exception('La fecha debe ser de hoy en adelante.');
          $meses = max(1, min(24, (int) ($_POST['meses'] ?? 1)));
          $up = $pdo->prepare("UPDATE streaming_ventas SET fecha_inicio=?, fecha_vencimiento=?, estado='activa', recordado=0, recordado_at=NULL WHERE id=?");
          $sinSaldo = 0;   // renovaciones de revendedor que NO se hicieron por falta de saldo
          if ($esRevCtx) { require_once __DIR__ . '/../../api/wallet/_helpers.php'; }
          foreach ($rows as $r) {
            // COBRO AL REVENDEDOR también en lote (si no, se salta el cobro renovando varias). Si no le
            // alcanza el saldo para ESTA, se salta sin renovarla (las demás sí). El admin no paga.
            if ($esRevCtx) {
              $costoR = $costoRenovRev((string) ($r['plataforma'] ?? ''), $r['precio_renovacion'] ?? null, $r['precio'] ?? null);
              if ($costoR > 0 && (!function_exists('wallet_debitar') || !wallet_debitar($pdo, (int) current_user_id(), $costoR, 'renovacion_streaming', 'Renovación ' . ((string) ($r['plataforma'] ?? '') ?: 'streaming')))) { $sinSaldo++; continue; }
            }
            // fecha_inicio SÍ se avanza: el renovar individual NO lo hace y eso REGALA días. La
            // auto-renovación calcula la duración como (vencimiento − inicio) y cobra el precio
            // completo (api/revendedor/renovar-streaming.php); si el inicio nunca avanza, ese lapso
            // crece 30→60→120… y el revendedor recibe cada vez más días por el mismo precio.
            if ($hasta !== '') {
              // MODO FECHA FIJA: hay que PRESERVAR la duración vendida. Si pusiéramos
              // inicio = vencimiento viejo, el ciclo pasaría a ser solo la extensión (ej. 3 días) y
              // en su próxima renovación el revendedor pagaría un mes entero por 3 días — y el dato
              // corrupto se recongelaría en cada renovación siguiente.
              $nueva = date('Y-m-d', strtotime($hasta));
              $ini0 = $r['fecha_inicio']; $ven0 = $r['fecha_vencimiento'];
              $span = ($ini0 && $ven0) ? (int) round((strtotime($ven0) - strtotime($ini0)) / 86400) : 0;
              $ini  = $span > 0 ? date('Y-m-d', strtotime($nueva . " -$span days")) : ($ini0 ?: date('Y-m-d'));
            } else {
              // MODO MESES: cada una desde SU fecha (vigente → su vencimiento; vencida → hoy).
              $f = $r['fecha_vencimiento'];
              $base = ($f && strtotime($f) > time()) ? $f : date('Y-m-d');
              $nueva = date('Y-m-d', strtotime($base . " +$meses months"));
              $ini   = $base;
            }
            $up->execute([$ini, $nueva, (int) $r['id']]);
            $n += $up->rowCount();
            if ($up->rowCount() > 0) { $idsRenovadas[] = (int) $r['id']; }   // para el aviso por correo
            // Propaga la nueva fecha al ESPEJO del revendedor (que a él también se le renueve y no se borre).
            if (!$esRevCtx && (int) ($r['revendedor_id'] ?? 0) > 0 && (int) ($r['cuenta_id'] ?? 0) > 0 && function_exists('st_rev_propagar_vencimiento')) {
              try { $pids = array_map('intval', $pdo->query("SELECT id FROM streaming_perfiles WHERE venta_id=" . (int) $r['id'])->fetchAll(PDO::FETCH_COLUMN)); st_rev_propagar_vencimiento($pdo, (int) $r['cuenta_id'], $nueva, $pids); } catch (Throwable $e) {}
            }
            st_log($pdo, (int) $r['id'], 'renovada', 'Renovada en lote hasta ' . $nueva);
          }
          // NO se insertan pagos: el individual registra monto = precio de la venta, y copiarlo aquí
          // inventaría N cobros que nadie hizo y descuadraría las métricas.
          $msg = "✓ $n venta(s) renovadas." . ($sinSaldo > 0 ? " ⚠ $sinSaldo NO se renovaron por saldo insuficiente." : '');
        }
        elseif ($op === 'eliminar') {
          if ((string) ($_POST['confirmar'] ?? '') !== 'ELIMINAR') throw new Exception('Escribe ELIMINAR para confirmar.');
          $nq = 0;   // cuántas cuentas espejo se le quitaron a revendedores
          $libera = $pdo->prepare("UPDATE streaming_perfiles SET estado='libre', venta_id=NULL WHERE venta_id=? AND estado='vendido'");
          $del = $pdo->prepare("DELETE FROM streaming_ventas WHERE id=?");
          $rnP = $pdo->prepare("UPDATE streaming_perfiles SET etiqueta=? WHERE id=?");   // #16: renombrar a P{n}
          foreach ($rows as $r) {
            st_log($pdo, (int) $r['id'], 'eliminada', 'Eliminada en lote');   // antes del DELETE
            $frows = $pdo->query("SELECT id, cuenta_id FROM streaming_perfiles WHERE venta_id=" . (int) $r['id'] . " AND estado='vendido'")->fetchAll(PDO::FETCH_ASSOC);
            $libera->execute([(int) $r['id']]);
            foreach ($frows as $fr) { $pos = (int) $pdo->query("SELECT COUNT(*) FROM streaming_perfiles WHERE cuenta_id=" . (int) $fr['cuenta_id'] . " AND id<=" . (int) $fr['id'])->fetchColumn(); $rnP->execute(['P' . max(1, $pos), (int) $fr['id']]); }
            $del->execute([(int) $r['id']]);
            $n += $del->rowCount();
            // Si el ADMIN borra una venta a un revendedor → quitarle SOLO ese perfil (no toda la cuenta si
            // tiene otros del mismo correo); el bot desasigna solo cuando no le quede ninguno de ese correo.
            if (!$esRevCtx && (int) ($r['revendedor_id'] ?? 0) > 0 && (int) ($r['cuenta_id'] ?? 0) > 0 && function_exists('st_rev_quitar_por_origen')) {
              try { $nq += st_rev_quitar_por_origen($pdo, (int) $r['revendedor_id'], (int) $r['cuenta_id'], array_column($frows, 'id')); } catch (Throwable $e) {}
            }
          }
          $msg = "✓ $n venta(s) eliminadas (perfiles liberados)" . ($nq > 0 ? " · se le quitó $nq cuenta(s) a revendedor(es)" : '') . '.';
        }
        elseif ($op === 'precios') {
          // Cambiar el PRECIO DE VENTA de varias ventas a la vez (útil para revendedor: cambia lo que
          // cobra a sus clientes sin tocar el costo). Se guarda en precio y precio_venta_cliente.
          $nuevoPrecio = ($_POST['precio'] ?? '') !== '' ? (float) str_replace(',', '.', (string) $_POST['precio']) : null;
          if ($nuevoPrecio === null || $nuevoPrecio < 0) throw new Exception('Escribe un precio válido.');
          $upPr = $pdo->prepare("UPDATE streaming_ventas SET precio=?, precio_venta_cliente=? WHERE id=?");
          foreach ($rows as $r) { $upPr->execute([$nuevoPrecio, $nuevoPrecio, (int) $r['id']]); $n += $upPr->rowCount(); }
          $msg = "✓ Precio de venta \$" . number_format($nuevoPrecio, 2) . " aplicado a $n venta(s).";
        }
        elseif ($op === 'reasignar') {
          // Cambiar el CLIENTE y/o el VENDEDOR (revendedor) de las ventas seleccionadas. Deja en blanco lo
          // que no quieras cambiar. Cambiar de vendedor MUEVE la cuenta del inventario del revendedor viejo
          // al nuevo (espejo) y actualiza el bot; solo lo hace el dueño (no un revendedor sobre sus ventas).
          $nuevoCliId = (int) ($_POST['cliente_id'] ?? 0);        // >0 = pasar a un cliente PROPIO existente (enlaza cliente_id)
          $nuevoCli = trim((string) ($_POST['cliente_nombre'] ?? ''));
          $nuevoWa  = preg_replace('/\D+/', '', (string) ($_POST['cliente_wa'] ?? ''));
          // Si eligió un cliente del desplegable, tomamos su nombre/WhatsApp REALES (y lo enlazamos por id).
          $cliRow = null;
          if ($nuevoCliId > 0) {
            try { $qc = $pdo->prepare("SELECT id, nombre, wa FROM streaming_clientes WHERE id=? AND owner_id=?"); $qc->execute([$nuevoCliId, $OWNER]); $cliRow = $qc->fetch(PDO::FETCH_ASSOC) ?: null; } catch (Throwable $e) {}
            if ($cliRow) { $nuevoCli = (string) $cliRow['nombre']; if ($nuevoWa === '') $nuevoWa = preg_replace('/\D+/', '', (string) ($cliRow['wa'] ?? '')); }
          }
          $revRaw   = (string) ($_POST['revendedor_id'] ?? '');   // '' = no cambiar · '0' = quitar · >0 = reasignar
          $cambiaRev = ($revRaw !== '' && !$esRevCtx);
          $nuevoRev = $cambiaRev ? (int) $revRaw : -1;
          if ($nuevoCli === '' && $nuevoWa === '' && !$cambiaRev) throw new Exception('No indicaste ningún cambio (cliente o vendedor).');
          $nCli = 0; $nRev = 0;
          foreach ($rows as $r) {
            $vid2 = (int) $r['id'];
            // 1) Cliente (nombre / WhatsApp). Si vino de un cliente EXISTENTE, además se enlaza cliente_id;
            //    si es un nombre libre (cliente nuevo), se DESLIGA el cliente_id anterior.
            if ($cliRow) { try { $pdo->prepare("UPDATE streaming_ventas SET cliente_id=?, cliente_nombre=?, cliente_wa=? WHERE id=?")->execute([(int) $cliRow['id'], $nuevoCli, ($nuevoWa !== '' ? $nuevoWa : null), $vid2]); $nCli++; } catch (Throwable $e) {} }
            elseif ($nuevoCli !== '') { try { $pdo->prepare("UPDATE streaming_ventas SET cliente_id=NULL, cliente_nombre=?, cliente_wa=? WHERE id=?")->execute([$nuevoCli, ($nuevoWa !== '' ? $nuevoWa : null), $vid2]); $nCli++; } catch (Throwable $e) {} }
            elseif ($nuevoWa !== '') { try { $pdo->prepare("UPDATE streaming_ventas SET cliente_wa=? WHERE id=?")->execute([$nuevoWa, $vid2]); } catch (Throwable $e) {} }
            // 2) Vendedor (mover el espejo del revendedor viejo → nuevo)
            if ($cambiaRev) {
              $oldRev = (int) ($r['revendedor_id'] ?? 0);
              $cuentaId = (int) ($r['cuenta_id'] ?? 0);
              if ($nuevoRev !== $oldRev) {
                $perfIds = [];
                try { $perfIds = array_map('intval', $pdo->query("SELECT id FROM streaming_perfiles WHERE venta_id=$vid2")->fetchAll(PDO::FETCH_COLUMN)); } catch (Throwable $e) {}
                // quitar del revendedor viejo (su espejo) + bot desasignar
                if ($oldRev > 0 && $cuentaId > 0 && function_exists('st_rev_quitar_por_origen')) { try { st_rev_quitar_por_origen($pdo, $oldRev, $cuentaId, $perfIds); } catch (Throwable $e) {} }
                // dar al revendedor nuevo (crear su espejo). Solo si hay perfiles del admin que mover.
                if ($nuevoRev > 0 && $cuentaId > 0 && $perfIds && function_exists('st_rev_entregar')) {
                  $pfRows = [];
                  try { $in2 = implode(',', $perfIds); $pfRows = $pdo->query("SELECT id, etiqueta, pin FROM streaming_perfiles WHERE id IN ($in2)")->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) {}
                  if ($pfRows) { try { st_rev_entregar($pdo, $nuevoRev, $cuentaId, $pfRows, 0.0, false, ((string) ($r['fecha_vencimiento'] ?? '') ?: null)); } catch (Throwable $e) {} $revsFlush[$nuevoRev] = true; }
                }
                // actualizar la venta del admin: nuevo revendedor (o directa si es 0)
                try { $pdo->prepare("UPDATE streaming_ventas SET revendedor_id=? WHERE id=?")->execute([($nuevoRev > 0 ? $nuevoRev : null), $vid2]); } catch (Throwable $e) {}
                $nRev++;
              }
            }
          }
          $msg = '✓ Cambios aplicados' . ($nCli > 0 ? " · $nCli cliente(s)" : '') . ($nRev > 0 ? " · $nRev con nuevo vendedor" : '') . '.';
        }
        else throw new Exception('Operación de lote no válida.');

        $pdo->commit();
        // BOT de códigos (tras el commit): asigna a los revendedores nuevos las cuentas que recibieron.
        if (!empty($revsFlush) && function_exists('bot_codigos_flush')) { foreach (array_keys($revsFlush) as $rf) { try { bot_codigos_flush($pdo, (int) $rf); } catch (Throwable $e) {} } }
        // Aviso por correo de las cuentas que cambiaron de vendedor (op 'reasignar' encola en st_rev_entregar).
        if (function_exists('stream_email_flush_entregas')) {
          try { $nEnt = stream_email_flush_entregas($pdo); if ($nEnt) $msg .= " ✉ $nEnt correo(s) de entrega enviado(s)."; } catch (Throwable $e) {}
        }
        // AVISO POR CORREO de las renovadas en lote — SIEMPRE tras el commit (jamás SMTP dentro de una
        // transacción: sostener candados de BD esperando a un servidor de correo traba a los demás).
        // PRESUPUESTO DE TIEMPO: un lote son hasta 200 ventas × 2 correos; aunque la conexión SMTP se
        // reusa, si se pasa de 25s se corta el envío para no tumbar la página por timeout. Lo ya hecho
        // en BD queda intacto (el commit ya pasó) y el mensaje avisa cuántas quedaron sin correo.
        if ($idsRenovadas) {
          $nMail = 0; $sinMail = 0; $t0 = microtime(true);
          foreach ($idsRenovadas as $vidR) {
            if (microtime(true) - $t0 > 25) { $sinMail++; continue; }
            try { $nMail += stream_email_notificar_venta($pdo, (int) $vidR, 'renovacion'); } catch (Throwable $e) {}
          }
          if ($nMail)   $msg .= " ✉ $nMail correo(s) enviado(s).";
          if ($sinMail) $msg .= " ⏱ $sinMail venta(s) quedaron renovadas pero sin aviso por correo (el envío tardaba demasiado).";
        }
        if ($saltadas > 0) $msg .= " $saltadas omitida(s): " . ($esRevCtx ? 'no eran tuyas o ya no existen.' : 'son de revendedores y no tienes acceso.');
      } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    }
  } catch (Throwable $e) { $msg = '⚠ ' . $e->getMessage(); }
  header('Location: ventas.php?f=' . urlencode($_POST['f'] ?? 'todas') . '&msg=' . urlencode($msg));
  exit;
}
$flash = (string) ($_GET['msg'] ?? '');

// ── Lista ──
$fReq = $_GET['f'] ?? 'todas';
$f = in_array($fReq, ['todas', 'vencidas', 'pendientes'], true) ? $fReq : 'todas';
$hoyStr = date('Y-m-d');
if ($f === 'vencidas')        $where = "(v.estado='vencida' OR (v.estado='activa' AND v.fecha_vencimiento < '$hoyStr'))";
elseif ($f === 'pendientes')  $where = "v.entregada=0 AND v.estado<>'cancelada'";
else                          $where = "v.estado <> 'cancelada'";   // 'todas' oculta las CANCELADAS (ej. de cuentas ya eliminadas), que antes seguían saliendo. El historial sigue en la BD.
// D3: filtro por REVENDEDOR (solo el dueño). rev>0 → ese revendedor · rev=-1 → ventas directas (sin revendedor).
$revF = isset($_GET['rev']) ? (int) $_GET['rev'] : 0;
if (!$esRevCtx && $revF > 0)        $where .= " AND v.revendedor_id = " . $revF;
elseif (!$esRevCtx && $revF === -1) $where .= " AND (v.revendedor_id IS NULL OR v.revendedor_id = 0)";
$hasEmailActivar = false;
try { $hasEmailActivar = (bool) $pdo->query("SHOW COLUMNS FROM streaming_ventas LIKE 'email_activar'")->fetch(); } catch (Throwable $e) {}
$emailSel = $hasEmailActivar ? ', v.email_activar, v.entregada' : ", NULL AS email_activar, v.entregada";
$ventas = [];
try {
  // ATRIBUCIÓN (15-jul): traemos el REVENDEDOR para que en el panel del dueño la venta se vea como
  // "del revendedor X" y no como si el cliente final fuera suyo (ese cliente es del revendedor, no
  // tuyo). NO se toca el COALESCE del cliente ni el $scopeRev (ese es el blindaje que le oculta al
  // empleado las ventas de revendedores).
  $ventas = $pdo->query("SELECT v.id, v.plataforma, v.tipo, COALESCE(cl.nombre, v.cliente_nombre) AS cliente, v.cliente_wa,
                          v.correo, v.clave, v.perfil, v.pin, v.precio, v.precio_venta_cliente, v.precio_renovacion, v.fecha_vencimiento, v.estado, COALESCE(pl.modo_entrega,'perfil') AS modo,
                          v.revendedor_id, ru.nombre AS rev_nombre, ru.telefono AS rev_wa, cc.vencimiento AS corte$emailSel
                         FROM streaming_ventas v LEFT JOIN streaming_clientes cl ON cl.id=v.cliente_id
                         LEFT JOIN streaming_plataformas pl ON pl.nombre=v.plataforma AND pl.owner_id=v.owner_id
                         LEFT JOIN streaming_cuentas cc ON cc.id = v.cuenta_id
                         LEFT JOIN usuarios ru ON ru.id = v.revendedor_id
                         WHERE ($where) AND $scopeRev
                         ORDER BY CASE WHEN v.estado='cancelada' THEN 2
                                       WHEN (v.estado='vencida' OR v.fecha_vencimiento < '$hoyStr') THEN 0
                                       ELSE 1 END ASC,
                                  v.fecha_vencimiento ASC, v.id DESC LIMIT 3000")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

// Cuentas destino para "Reemplazar": solo las que PUEDEN recibir al cliente.
// - modo 'perfil'      → necesita al menos 1 perfil libre (si no, no hay dónde meterlo).
// - modo 'email_manual'→ no usa perfiles (Canva invita al correo del cliente) → siempre disponible.
$ctasDestino = [];
try {
  $ctasDestino = $pdo->query("SELECT c.id, c.correo, COALESCE(pl.nombre, c.plataforma) AS plataforma,
                                     COALESCE(pl.modo_entrega,'perfil') AS modo,
                                     (SELECT COUNT(*) FROM streaming_perfiles sp
                                       WHERE sp.cuenta_id = c.id AND sp.estado = 'libre') AS libres
                                FROM streaming_cuentas c
                                LEFT JOIN streaming_plataformas pl ON pl.id = c.plataforma_id AND pl.owner_id = c.owner_id
                               WHERE c.owner_id = $OWNER
                               ORDER BY COALESCE(pl.nombre, c.plataforma), c.id")->fetchAll(PDO::FETCH_ASSOC);
  $ctasDestino = array_values(array_filter($ctasDestino,
    fn($c) => $c['modo'] === 'email_manual' || (int) $c['libres'] > 0));
} catch (Throwable $e) {}

// Plataformas presentes en las ventas cargadas (para el filtro por plataforma).
$platsList = [];
foreach ($ventas as $vv) { $p = trim((string) ($vv['plataforma'] ?? '')); if ($p !== '' && !in_array($p, $platsList, true)) $platsList[] = $p; }
sort($platsList);

// D3: lista de revendedores para el filtro del dueño (solo los que tienen ventas atribuidas).
$revList = [];
if (!$esRevCtx) {
  try {
    $revList = $pdo->query("SELECT DISTINCT u.id, COALESCE(NULLIF(u.nombre,''), u.username) AS nombre
                             FROM streaming_ventas v JOIN usuarios u ON u.id = v.revendedor_id
                            WHERE v.revendedor_id IS NOT NULL AND v.revendedor_id > 0
                            ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);
  } catch (Throwable $e) { $revList = []; }
}

// Lista de CLIENTES propios (para el desplegable "Cambiar cliente/vendedor" → pasar la venta a un cliente propio).
$clientesList = [];
try { $clientesList = $pdo->query("SELECT id, nombre, wa FROM streaming_clientes WHERE owner_id=$OWNER ORDER BY nombre LIMIT 3000")->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) { $clientesList = []; }

stream_head('Ventas', 'ventas');
?>
<style>
  /* Helpers de presentación locales (sistema índigo, theme-aware) */
  .s3menu{position:fixed;z-index:50;background:var(--surface);border:1px solid var(--border);border-radius:11px;box-shadow:var(--shadow);padding:6px;width:176px;font-size:13px}
  .s3menu .s3lbl{padding:4px 10px 5px;font-size:10px;font-weight:700;color:var(--faint);text-transform:uppercase;letter-spacing:.05em}
  .s3menu button{width:100%;text-align:left;padding:8px 10px;border-radius:8px;display:flex;align-items:center;gap:9px;background:none;border:0;cursor:pointer;color:var(--text);font-size:13px;font-weight:500}
  .s3menu button:hover{background:var(--surface-2)}
  .s3menu button [data-lucide]{width:16px;height:16px;color:var(--muted)}
  .s3menu button.danger{color:var(--bad)}.s3menu button.danger [data-lucide]{color:var(--bad)}
  .s3sep{border-top:1px solid var(--border);margin:5px 0}
  .ov{background:rgba(4,7,13,.55)}
  .modal{background:var(--surface);border:1px solid var(--border);border-radius:14px;box-shadow:var(--shadow);width:100%;margin:2rem 0}
  .modal-hd{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:14px 16px;border-bottom:1px solid var(--border)}
  .modal-hd h3{margin:0;font-size:15px;font-weight:700;display:flex;align-items:center;gap:8px;color:var(--text)}
  .modal-hd h3 [data-lucide]{width:18px;height:18px;color:var(--accent)}
  .modal-x{width:32px;height:32px;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--muted);display:grid;place-items:center;cursor:pointer}
  .modal-x:hover{color:var(--text);border-color:var(--border-strong)}
  .modal-x [data-lucide]{width:17px;height:17px}
  .modal-bd{padding:16px}
</style>

<?php if ($flash): $flashMal = (mb_substr(trim((string) $flash), 0, 1) === '⚠'); /* los handlers devuelven "⚠ ..." cuando NO hicieron nada (ej. "Máximo 200 por lote"); pintarlo con ✓ verde hacía leer un fallo como éxito */ ?>
<div class="banner" style="margin-bottom:16px<?= $flashMal ? ';border-color:rgba(239,68,68,.4);color:var(--err)' : '' ?>"><i data-lucide="<?= $flashMal ? 'alert-triangle' : 'check-circle' ?>"></i><span><?= h($flash) ?></span></div><?php endif; ?>

<div class="pagehd">
  <div>
    <h1>Gestión de <span class="nm">Ventas</span></h1>
    <p>Administra tus suscripciones activas y renovaciones.</p>
  </div>
  <div style="display:flex;align-items:center;gap:8px">
    <a href="?f=<?= h($f) ?>" class="btn ghost" title="Actualizar"><i data-lucide="refresh-cw"></i></a>
    <a href="nueva-venta.php" class="btn primary"><i data-lucide="shopping-cart"></i> Nueva Venta</a>
  </div>
</div>

<!-- Toggle Ventas / Recargas -->
<div style="display:flex;gap:8px;margin-bottom:12px">
  <button class="btn primary"><i data-lucide="list"></i> Ventas</button>
  <?php if (function_exists('stream_ctx') && stream_ctx() === 'revendedor'): ?>
    <a href="recargas.php" class="btn ghost" title="Vender recargas de juegos"><i data-lucide="zap"></i> Recargas</a>
  <?php else: ?>
    <a href="<?= h(function_exists('app_path') ? app_path('/admin/dashboard') : '/admin/dashboard') ?>" class="btn ghost" title="Las recargas de la tienda se gestionan en tu panel principal"><i data-lucide="zap"></i> Recargas</a>
  <?php endif; ?>
</div>

<div style="display:flex;flex-wrap:wrap;align-items:center;gap:8px;margin-bottom:16px">
  <a href="?f=vencidas&rev=<?= (int) $revF ?>" class="btn <?= $f === 'vencidas' ? 'primary' : 'ghost' ?>"><i data-lucide="alert-triangle"></i> Vencidas</a>
  <a href="?f=todas&rev=<?= (int) $revF ?>" class="btn <?= $f === 'todas' ? 'primary' : 'ghost' ?>"><i data-lucide="eye"></i> Todas <?= $f === 'todas' ? '✓' : '' ?></a>
  <?php if (!$esRevCtx || !function_exists('st_rev_puede_exportar') || st_rev_puede_exportar($pdo)): ?>
  <a href="?export=csv&f=<?= h($f) ?>&rev=<?= (int) $revF ?>" class="btn ghost"><i data-lucide="file-spreadsheet"></i> Excel</a>
  <?php endif; ?>
  <?php if (!$esRevCtx && $revList): ?>
  <select onchange="location.href='?f=<?= h($f) ?>&rev='+encodeURIComponent(this.value)" class="input" style="width:auto" title="Filtrar por revendedor">
    <option value="0"<?= $revF === 0 ? ' selected' : '' ?>>Todos los revendedores</option>
    <option value="-1"<?= $revF === -1 ? ' selected' : '' ?>>— Ventas directas (sin revendedor)</option>
    <?php foreach ($revList as $rv): ?><option value="<?= (int) $rv['id'] ?>"<?= $revF === (int) $rv['id'] ? ' selected' : '' ?>><?= h($rv['nombre']) ?></option><?php endforeach; ?>
  </select>
  <?php endif; ?>
  <select id="fplat" onchange="aplicarFiltro()" class="input" style="width:auto">
    <option value="">Todas las plataformas</option>
    <?php foreach ($platsList as $p): ?><option value="<?= h(mb_strtolower($p)) ?>"><?= h($p) ?></option><?php endforeach; ?>
  </select>
  <select id="fest" onchange="aplicarFiltro()" class="input" style="width:auto">
    <option value="">Todos los estados</option>
    <option value="activo">Activas</option>
    <option value="pronto">Por vencer (≤5 días)</option>
    <option value="venc">Vencidas</option>
  </select>
  <?php if ($verCostos): /* solo el dueño ve ventas de revendedores ($scopeRev) → solo a él le sirve */ ?>
  <select id="fsrc" onchange="aplicarFiltro()" class="input" style="width:auto">
    <option value="">Míos y de revendedores</option>
    <option value="propia">Solo ventas mías</option>
    <option value="rev">Solo de revendedores</option>
  </select>
  <?php endif; ?>
  <select id="forden" onchange="ordenarVentas()" class="input" style="width:auto">
    <option value="">Ordenar por…</option>
    <option value="venc-asc">Vence primero</option>
    <option value="venc-desc">Vence último</option>
    <option value="cli-asc">Cliente (A→Z)</option>
    <option value="correo-asc">Correo (A→Z)</option>
    <option value="rev-asc">Vendedor (A→Z)</option>
  </select>
  <span style="font-size:12px;color:var(--faint)" class="hidden sm:inline">Mostrar <select id="mostrar" onchange="limitar(this.value)" class="input" style="width:auto;display:inline-block;padding:5px 8px"><option value="0" selected>Todas</option><option>25</option><option>50</option><option>100</option></select> registros</span>
  <span style="margin-left:auto"></span>
  <input id="buscar" class="input" style="width:16rem;max-width:55%" placeholder="Buscar cliente, plataforma, correo, revendedor…">
</div>

<div class="card" style="overflow-x:auto">
  <!-- Barra de acciones masivas: solo opera sobre las filas VISIBLES y marcadas. -->
  <div id="bulkbar" style="display:none;position:sticky;top:0;z-index:5;margin-bottom:10px;padding:10px 13px;border-radius:11px;background:rgba(45,226,213,.09);border:1px solid rgba(45,226,213,.35);align-items:center;gap:10px;flex-wrap:wrap">
    <b style="color:var(--acc)"><span id="bulk-n">0</span> seleccionada(s)</b>
    <span id="bulk-rev" style="display:none;font-size:11.5px;color:var(--warn)">⚠ <b id="bulk-rev-n">0</b> son de revendedores</span>
    <button type="button" onclick="bulkRenovar()" class="btn ghost" style="padding:5px 12px;font-size:12.5px"><i data-lucide="refresh-cw" style="width:14px;height:14px"></i> Renovar varias</button>
    <button type="button" onclick="bulkPrecios()" class="btn ghost" style="padding:5px 12px;font-size:12.5px"><i data-lucide="dollar-sign" style="width:14px;height:14px"></i> Cambiar precios</button>
    <button type="button" onclick="bulkReasignar()" class="btn ghost" style="padding:5px 12px;font-size:12.5px"><i data-lucide="user-cog" style="width:14px;height:14px"></i> Cambiar cliente/vendedor</button>
    <button type="button" onclick="bulkEliminar()" class="btn ghost" style="padding:5px 12px;font-size:12.5px;color:var(--err);border-color:rgba(239,68,68,.4)"><i data-lucide="trash-2" style="width:14px;height:14px"></i> Eliminar varias</button>
    <button type="button" onclick="document.querySelectorAll('.ck-row').forEach(c=>c.checked=false);ckSync()" class="btn ghost" style="padding:5px 12px;font-size:12.5px;margin-left:auto">Quitar selección</button>
  </div>
  <table class="dtable">
    <thead><tr>
      <th style="width:34px;position:sticky;left:0;background:var(--surface);z-index:2"><input type="checkbox" id="ck-all" onclick="ckTodo(this)" title="Seleccionar los visibles" style="width:15px;height:15px;accent-color:var(--acc)"></th>
      <th>N° Pedido</th><th>Cliente</th><th>Producto</th><th>Correo</th><th>Perfil</th><th>PIN</th><th>Precio</th><th>Vence</th><th>Días Restantes</th><th style="text-align:center">Acciones</th>
    </tr></thead>
    <tbody id="tbody">
    <?php foreach ($ventas as $v):
      $codigo = stream_cod_pedido((int) $v['id']);   // N° de pedido legible (derivado del id único)
      $d = v_dias($v['fecha_vencimiento'] ?? null, $hoy);
      if ($d === null) { $badge = 'tag'; $txt = '—'; }
      elseif ($d < 0)  { $badge = 'pill err'; $txt = 'Vencido'; }
      elseif ($d <= 5) { $badge = 'pill wait'; $txt = $d . ' días'; }
      else             { $badge = 'pill ok'; $txt = $d . ' días'; }
      [$pcolor] = streamPlatFallback((string) $v['plataforma']);
      $esRev  = !empty($v['revendedor_id']);
      $revNom = $esRev ? (trim((string) ($v['rev_nombre'] ?? '')) ?: ('#' . (int) $v['revendedor_id'])) : '';
      $busca = mb_strtolower(($v['cliente'] ?? '') . ' ' . ($v['plataforma'] ?? '') . ' ' . ($v['correo'] ?? '') . ' ' . $revNom . ' ' . $codigo);
      $estKey = $d === null ? 'sin' : ($d < 0 ? 'venc' : ($d <= 5 ? 'pronto' : 'activo'));
      $esManualV = in_array($v['modo'] ?? 'perfil', ['email_manual', 'invitacion', 'activacion'], true);
    ?>
      <tr data-b="<?= h($busca) ?>" data-plat="<?= h(mb_strtolower((string) $v['plataforma'])) ?>" data-cli="<?= h(mb_strtolower((string) ($v['cliente'] ?? ''))) ?>" data-correo="<?= h(mb_strtolower((string) ($v['correo'] ?? ''))) ?>" data-est="<?= h($estKey) ?>" data-src="<?= $esRev ? 'rev' : 'propia' ?>" data-venc="<?= $d === null ? 999999 : (int) $d ?>" data-rev="<?= h(mb_strtolower($revNom)) ?>">
        <td style="position:sticky;left:0;background:var(--surface);z-index:1"><input type="checkbox" class="ck-row" value="<?= (int) $v['id'] ?>" data-rev="<?= ($esRev && !$esRevCtx) ? '1' : '0' ?>" onclick="event.stopPropagation();ckSync()" style="width:15px;height:15px;accent-color:var(--acc)"></td>
        <td style="white-space:nowrap"><span onclick="event.stopPropagation();copiarCod('<?= h($codigo) ?>',this)" title="N° de pedido · clic para copiar" style="font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:11.5px;font-weight:600;color:var(--accent);background:var(--accent-soft);border:1px solid var(--border);border-radius:6px;padding:2px 7px;cursor:pointer"><?= h($codigo) ?></span></td>
        <td style="font-weight:600">
          <?php if ($esRev): ?>
            <span class="pill acc" style="font-size:9px">REVENDEDOR</span>
            <span style="margin-left:5px"><?= h($revNom) ?></span>
            <?php if (!empty($v['cliente'])): ?><div style="font-weight:400;font-size:10.5px;color:var(--faint);margin-top:2px">su cliente: <?= h($v['cliente']) ?></div><?php endif; ?>
          <?php else: ?>
            <?= h($v['cliente'] ?: '—') ?>
          <?php endif; ?>
        </td>
        <td><span style="display:inline-flex;align-items:center;gap:6px"><span style="width:9px;height:9px;border-radius:50%;background:<?= h($pcolor) ?>"></span><?= h($v['plataforma']) ?></span></td>
        <td style="color:var(--faint);font-size:11.5px">
        <?php if ($esManualV):
                $cliEmail = $v['email_activar'] ?: ((strpos((string) $v['perfil'], '@') !== false) ? $v['perfil'] : ''); ?>
          <?php if ($cliEmail): ?><span style="color:var(--text)"><?= h($cliEmail) ?></span><?php else: ?>—<?php endif; ?>
          <?php if ((int) ($v['entregada'] ?? 1) === 0): ?> <span class="pill wait" style="font-size:9px">por activar</span><?php endif; ?>
          <?php if (!empty($v['clave'])): ?><br><span style="font-size:10px;color:var(--faint)">🔑 <?= h($v['clave']) ?></span><?php endif; ?>
        <?php else: ?>
          <?= h($v['correo'] ?: '—') ?>
          <?php if (!empty($v['clave'])): ?><br><span style="font-size:10px;color:var(--faint)">🔑 <?= h($v['clave']) ?></span><?php endif; ?>
        <?php endif; ?>
        </td>
        <td style="color:var(--muted)"><?= $esManualV ? '—' : h($v['perfil'] ?: '—') ?></td>
        <td style="color:var(--muted)"><?= $esManualV ? '—' : h($v['pin'] ?: '—') ?></td>
        <td class="amt"><?= $v['precio'] !== null ? '$' . number_format((float) $v['precio'], 2) : '—' ?>
          <?php if ($esRevCtx && $v['precio_venta_cliente'] !== null && (float) $v['precio_venta_cliente'] > 0 && (float) $v['precio_venta_cliente'] !== (float) $v['precio']): ?>
            <div style="font-size:10px;color:var(--good);font-weight:600" title="Precio de venta a tu cliente">venta $<?= number_format((float) $v['precio_venta_cliente'], 2) ?></div>
          <?php endif; ?>
          <?php if ($v['precio_renovacion'] !== null && (float) $v['precio_renovacion'] > 0 && (float) $v['precio_renovacion'] !== (float) $v['precio']): ?>
            <div style="font-size:10px;color:var(--faint);font-weight:400">renov. $<?= number_format((float) $v['precio_renovacion'], 2) ?></div>
          <?php endif; ?>
        </td>
        <td style="color:var(--muted)"><?= $v['fecha_vencimiento'] ? date('d/m/Y', strtotime($v['fecha_vencimiento'])) : '—' ?>
          <?php if ($esRevCtx && !empty($v['corte']) && (empty($v['fecha_vencimiento']) || substr((string) $v['corte'], 0, 10) !== substr((string) $v['fecha_vencimiento'], 0, 10))): ?>
            <div style="font-size:10px;color:var(--faint)" title="Tu fecha de corte, desde que la compraste">🛒 tu corte: <?= h(date('d/m/Y', strtotime((string) $v['corte']))) ?></div>
          <?php endif; ?>
        </td>
        <td><span class="<?= $badge ?>"><?= h($txt) ?></span></td>
        <td style="text-align:center;white-space:nowrap">
          <?php
            $vencTxt = !empty($v['fecha_vencimiento']) ? date('d/m/Y', strtotime((string) $v['fecha_vencimiento'])) : '';
            if (!empty($v['revendedor_id'])):
              // Venta de REVENDEDOR → los avisos van AL REVENDEDOR (icono AZUL), no a su cliente.
              $revWa = preg_replace('/\D+/', '', (string) ($v['rev_wa'] ?? ''));
              if ($revWa !== ''):
                $waRev = function_exists('stream_msg_aviso_revendedor') ? stream_msg_aviso_revendedor(['plataforma' => $v['plataforma'], 'cliente_nombre' => $v['cliente'], 'fecha_vencimiento' => $v['fecha_vencimiento']]) : ('Aviso: el ' . $v['plataforma'] . ' de ' . $v['cliente'] . ($vencTxt ? ' vence el ' . $vencTxt : '') . '. Avísale a tu cliente.');
          ?>
            <a href="https://wa.me/<?= h($revWa) ?>?text=<?= h(rawurlencode($waRev)) ?>" target="_blank" rel="noopener" class="iconbtn" style="width:32px;height:32px;color:#2563eb" title="Avisar vencimiento al revendedor"><i data-lucide="message-circle"></i></a>
          <?php else: ?><span style="font-size:10px;color:var(--faint)" title="El revendedor no tiene WhatsApp cargado">sin WA</span><?php endif; ?>
          <button onclick="return commNotif(<?= (int) $v['id'] ?>)" class="iconbtn" style="width:32px;height:32px;color:#2563eb" title="Mensaje personalizado (datos, etc.)"><i data-lucide="message-square-plus"></i></button>
          <?php else:
              // Venta a CLIENTE → avisos al cliente (icono VERDE). Datos de la compra + recordatorio.
              $waNum = preg_replace('/\D+/', '', (string) ($v['cliente_wa'] ?? ''));
              if ($waNum !== ''):
                $tiendaNom = function_exists('stream_store_cfg') ? stream_store_cfg('nombre_tienda', '') : '';
                $waMsg = 'Hola ' . trim((string) ($v['cliente'] ?? '')) . ' 👋 '
                       . ($tiendaNom !== '' ? 'Te escribimos de ' . $tiendaNom . '. ' : '')
                       . 'Te recordamos que tu servicio de ' . trim((string) ($v['plataforma'] ?? 'streaming'))
                       . ($vencTxt !== '' ? ' vence el ' . $vencTxt : ' está por vencer')
                       . '. Escríbenos para renovarlo y que no se te corte. ¡Gracias!';
                $datosLineas = ['Hola ' . trim((string) ($v['cliente'] ?? '')) . ' 👋 Estos son los datos de tu ' . trim((string) ($v['plataforma'] ?? 'servicio')) . ':'];
                if (trim((string) ($v['correo'] ?? '')) !== '') { $datosLineas[] = '📧 Correo: ' . trim((string) $v['correo']); }
                if (trim((string) ($v['clave'] ?? '')) !== '')  { $datosLineas[] = '🔑 Clave: ' . trim((string) $v['clave']); }
                if (trim((string) ($v['perfil'] ?? '')) !== '') { $datosLineas[] = '👤 Perfil: ' . trim((string) $v['perfil']); }
                if (trim((string) ($v['pin'] ?? '')) !== '')    { $datosLineas[] = '🔒 PIN: ' . trim((string) $v['pin']); }
                if ($vencTxt !== '') { $datosLineas[] = '📅 Vence: ' . $vencTxt; }
                $datosLineas[] = '¡Gracias por tu compra!';
                $waDatos = implode("\n", $datosLineas);
          ?>
            <a href="https://wa.me/<?= h($waNum) ?>?text=<?= h(rawurlencode($waDatos)) ?>" target="_blank" rel="noopener" class="iconbtn" style="width:32px;height:32px;color:#16a34a" title="Enviar datos de la compra al cliente"><i data-lucide="send"></i></a>
            <a href="https://wa.me/<?= h($waNum) ?>?text=<?= h(rawurlencode($waMsg)) ?>" target="_blank" rel="noopener" class="iconbtn" style="width:32px;height:32px;color:#16a34a" title="Avisar vencimiento al cliente"><i data-lucide="message-circle"></i></a>
          <?php endif; ?>
          <button onclick="return commNotif(<?= (int) $v['id'] ?>)" class="iconbtn" style="width:32px;height:32px;color:#16a34a" title="Mensaje personalizado por WhatsApp"><i data-lucide="message-square-plus"></i></button>
          <?php endif; ?>
          <button onclick="menu(event,<?= (int) $v['id'] ?>)" class="iconbtn" style="width:32px;height:32px" title="Más acciones (personalizado, renovar, editar…)"><i data-lucide="more-vertical"></i></button>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php if (!$ventas): ?><div class="empty">No hay ventas en este filtro.</div><?php endif; ?>
</div>

<!-- Menú 3 puntos -->
<div id="menu3" class="hidden s3menu">
  <div class="s3lbl">Gestionar</div>
  <button onclick="mActivar()"><i data-lucide="check-circle"></i> Activar (marcar entregada)</button>
  <button onclick="mNotificar()"><i data-lucide="message-circle"></i> Notificar</button>
  <button onclick="mRenovar()"><i data-lucide="refresh-cw"></i> Renovar</button>
  <button onclick="mRegistro()"><i data-lucide="clock"></i> Registro</button>
  <button onclick="mNota()"><i data-lucide="sticky-note"></i> Nota</button>
  <div class="s3sep"></div>
  <button onclick="mReemplazar()"><i data-lucide="repeat"></i> Reemplazar</button>
  <button onclick="mEditar()"><i data-lucide="pencil"></i> Editar</button>
  <button onclick="mEliminar()" class="danger"><i data-lucide="trash-2"></i> Eliminar</button>
</div>

<form method="post" id="f-activar" style="display:none"><input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="accion" value="activar"><input type="hidden" name="id" id="ac-id"><input type="hidden" name="f" value="<?= h($f) ?>"></form>

<div id="overlay" class="fixed inset-0 z-40 hidden items-start justify-center overflow-y-auto p-4 ov" onclick="if(event.target===this)cerrar()">
  <!-- Notificar -->
  <div id="m-notif" class="hidden modal" style="max-width:32rem">
    <div class="modal-hd"><h3><i data-lucide="bell"></i> Notificar Venta</h3><button onclick="cerrar()" class="modal-x"><i data-lucide="x"></i></button></div>
    <form method="post" class="modal-bd" onsubmit="return nConfirmar()"><input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="accion" value="notificar"><input type="hidden" name="id" id="n-id"><input type="hidden" name="f" value="<?= h($f) ?>">
      <!-- Este cliente es de un REVENDEDOR: escribirle desde el número de CONEC.VE le revela que su
           proveedor nos compra a nosotros. Se avisa ANTES de enviar, no después. -->
      <div id="n-rev" style="display:none;padding:10px 12px;border-radius:9px;background:rgba(245,166,35,.10);border:1px solid rgba(245,166,35,.35);font-size:12px;color:var(--warn);margin-bottom:12px;line-height:1.6">
        ⚠ <b>Este cliente es de <span id="n-rev-nom">un revendedor</span>.</b><br>
        Normalmente es el revendedor quien avisa a su propia gente. Puedes enviarle el aviso al
        <b>revendedor</b> para que él lo reenvíe a su cliente.
      </div>
      <div class="field"><label>Tipo de Mensaje</label>
        <select id="n-tipo" onchange="rellenarMsg()" class="input"><option value="credenciales">Datos de Acceso</option><option value="recordatorio">Recordatorio de renovación</option><option value="custom">Personalizado</option></select></div>
      <div class="field"><label>Mensaje</label><textarea name="mensaje" id="n-msg" rows="7" class="input" style="font-family:ui-monospace,SFMono-Regular,Menlo,monospace"></textarea></div>
      <div style="display:flex;justify-content:flex-end;gap:8px;flex-wrap:wrap"><button type="button" onclick="copiar()" class="btn ghost"><i data-lucide="copy"></i> Copiar Datos</button>
        <a id="n-rev-wa" href="#" target="_blank" rel="noopener" class="btn ghost" style="display:none;color:#2563eb"><i data-lucide="message-circle"></i> Avisar al revendedor</a>
        <button class="btn primary"><i data-lucide="message-circle"></i> Enviar por WhatsApp</button></div>
    </form>
  </div>
  <!-- Renovar -->
  <!-- ── LOTE: renovar varias ventas ── -->
  <div id="m-lote-renovar" class="hidden modal" style="max-width:28rem">
    <div class="modal-hd"><h3><i data-lucide="refresh-cw"></i> Renovar varias</h3><button onclick="cerrar()" class="modal-x"><i data-lucide="x"></i></button></div>
    <form method="post" class="modal-bd"><input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="accion" value="lote_ventas"><input type="hidden" name="op" value="renovar"><input type="hidden" name="ids" id="lr-ids"><input type="hidden" name="f" value="<?= h($f) ?>">
      <p style="color:var(--muted);font-size:13px;margin-bottom:10px">Vas a renovar <b id="lr-n" style="color:var(--text)">0</b> venta(s).</p>
      <div id="lr-rev" style="display:none;padding:9px 11px;border-radius:9px;background:rgba(245,166,35,.10);border:1px solid rgba(245,166,35,.35);font-size:11.5px;color:var(--warn);margin-bottom:10px">
        <b><span id="lr-rev-n">0</span> son de REVENDEDORES.</b> Al renovarlas tú, <b>no se les cobra</b> vcoins (ellos normalmente pagan al renovar).
      </div>
      <div style="display:flex;flex-direction:column;gap:8px;margin-bottom:12px">
        <label style="display:flex;align-items:center;gap:8px;font-size:13px;font-weight:600"><input type="radio" name="modo" value="meses" checked onchange="document.getElementById('lr-meses').classList.remove('hidden');document.getElementById('lr-fecha').classList.add('hidden');document.getElementById('lr-hasta').value=''" class="accent-teal-500"> Por meses <span style="font-weight:400;color:var(--faint);font-size:11px">(cada una desde SU fecha)</span></label>
        <div id="lr-meses"><select name="meses" class="input"><?php for ($i = 1; $i <= 12; $i++): ?><option value="<?= $i ?>"><?= $i ?> mes<?= $i > 1 ? 'es' : '' ?></option><?php endfor; ?></select></div>
        <label style="display:flex;align-items:center;gap:8px;font-size:13px;font-weight:600"><input type="radio" name="modo" value="fecha" onchange="document.getElementById('lr-fecha').classList.remove('hidden');document.getElementById('lr-meses').classList.add('hidden')" class="accent-teal-500"> Todas a una fecha</label>
        <div id="lr-fecha" class="hidden"><input type="date" name="hasta" id="lr-hasta" class="input"></div>
      </div>
      <button class="btn primary" style="width:100%">Renovar</button>
    </form>
  </div>

  <!-- ── LOTE: eliminar varias ventas ── -->
  <div id="m-lote-eliminar" class="hidden modal" style="max-width:28rem">
    <div class="modal-hd"><h3 style="color:var(--err)"><i data-lucide="trash-2"></i> Eliminar varias</h3><button onclick="cerrar()" class="modal-x"><i data-lucide="x"></i></button></div>
    <form method="post" class="modal-bd"><input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="accion" value="lote_ventas"><input type="hidden" name="op" value="eliminar"><input type="hidden" name="ids" id="le-ids"><input type="hidden" name="f" value="<?= h($f) ?>">
      <p style="color:var(--muted);font-size:13px;margin-bottom:10px">Vas a eliminar <b id="le-n" style="color:var(--err)">0</b> venta(s). Los perfiles se liberan. <b>Esto no se deshace.</b></p>
      <div class="field" style="margin-bottom:12px"><label class="flbl">Escribe <b>ELIMINAR</b> para confirmar</label><input name="confirmar" id="le-conf" class="input" placeholder="ELIMINAR" autocomplete="off"></div>
      <button class="btn" style="width:100%;background:var(--err);color:#fff">Eliminar definitivamente</button>
    </form>
  </div>

  <div id="m-lote-precios" class="hidden modal" style="max-width:26rem">
    <div class="modal-hd"><h3><i data-lucide="dollar-sign"></i> Cambiar precio de venta</h3><button onclick="cerrar()" class="modal-x"><i data-lucide="x"></i></button></div>
    <form method="post" class="modal-bd"><input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="accion" value="lote_ventas"><input type="hidden" name="op" value="precios"><input type="hidden" name="ids" id="lp-ids"><input type="hidden" name="f" value="<?= h($f) ?>">
      <p style="color:var(--muted);font-size:13px;margin-bottom:10px">Nuevo <b>precio de venta</b> para <b id="lp-n" style="color:var(--acc)">0</b> venta(s). Es lo que le cobras a tu cliente (no el costo).</p>
      <div class="field" style="margin-bottom:12px"><label class="flbl">Precio de venta</label><div style="display:flex"><span style="padding:0 10px;display:grid;place-items:center;background:var(--surface-2);border:1px solid var(--border);border-right:0;border-radius:9px 0 0 9px;color:var(--muted)">$</span><input name="precio" type="number" step="0.01" min="0" class="input" style="border-radius:0 9px 9px 0" placeholder="Ej: 3.50" required></div></div>
      <button class="btn primary" style="width:100%">Aplicar a todas</button>
    </form>
  </div>

  <div id="m-lote-reasignar" class="hidden modal" style="max-width:27rem">
    <div class="modal-hd"><h3><i data-lucide="user-cog"></i> Cambiar cliente / vendedor</h3><button onclick="cerrar()" class="modal-x"><i data-lucide="x"></i></button></div>
    <form method="post" class="modal-bd"><input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="accion" value="lote_ventas"><input type="hidden" name="op" value="reasignar"><input type="hidden" name="ids" id="lra-ids"><input type="hidden" name="f" value="<?= h($f) ?>">
      <p style="color:var(--muted);font-size:13px;margin-bottom:10px">Cambiar <b id="lra-n" style="color:var(--acc)">0</b> venta(s). <b>Deja en blanco lo que NO quieras cambiar.</b></p>
      <div class="field" style="margin-bottom:10px"><label class="flbl">Cliente existente</label>
        <select name="cliente_id" id="lra-cli" class="input" onchange="lraCliSel(this)">
          <option value="">— No cambiar —</option>
          <?php foreach ($clientesList as $cl): ?><option value="<?= (int) $cl['id'] ?>" data-wa="<?= h((string) ($cl['wa'] ?? '')) ?>"><?= h($cl['nombre']) ?><?= !empty($cl['wa']) ? ' · ' . h($cl['wa']) : '' ?></option><?php endforeach; ?>
        </select>
        <div style="font-size:11px;color:var(--faint);margin-top:5px">Pasa la(s) venta(s) a uno de tus clientes ya guardados. Para uno nuevo, deja esto en «No cambiar» y escribe el nombre abajo.</div>
      </div>
      <div class="field" style="margin-bottom:10px"><label class="flbl">…o cliente nuevo (nombre)</label><input name="cliente_nombre" id="lra-clinom" class="input" placeholder="Dejar vacío = no cambiar"></div>
      <div class="field" style="margin-bottom:10px"><label class="flbl">WhatsApp del cliente</label><input name="cliente_wa" id="lra-cliwa" class="input" placeholder="Ej: 584121234567 (opcional)"></div>
      <?php if (!$esRevCtx && $revList): ?>
      <div class="field" style="margin-bottom:12px"><label class="flbl">Nuevo vendedor (revendedor)</label>
        <select name="revendedor_id" class="input">
          <option value="">— No cambiar —</option>
          <option value="0">Quitar vendedor (venta directa)</option>
          <?php foreach ($revList as $rv): ?><option value="<?= (int) $rv['id'] ?>"><?= h($rv['nombre']) ?></option><?php endforeach; ?>
        </select>
        <div style="font-size:11px;color:var(--faint);margin-top:5px">Al cambiar de vendedor, la cuenta se mueve del inventario del revendedor viejo al nuevo, y el bot se actualiza.</div>
      </div>
      <?php endif; ?>
      <button class="btn primary" style="width:100%">Aplicar</button>
    </form>
  </div>

  <div id="m-renovar" class="hidden modal" style="max-width:28rem">
    <div class="modal-hd"><h3><i data-lucide="refresh-cw"></i> Renovar Venta</h3><button onclick="cerrar()" class="modal-x"><i data-lucide="x"></i></button></div>
    <form method="post" enctype="multipart/form-data" class="modal-bd"><input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="accion" value="renovar"><input type="hidden" name="id" id="rv-id"><input type="hidden" name="f" value="<?= h($f) ?>">
      <div style="display:flex;flex-direction:column;gap:8px;margin-bottom:12px">
        <label style="display:flex;align-items:center;gap:8px;font-size:13px;font-weight:600"><input type="radio" name="modo" value="meses" checked onchange="document.getElementById('rv-meses').classList.remove('hidden');document.getElementById('rv-fecha').classList.add('hidden')" class="accent-teal-500"> Por meses</label>
        <div id="rv-meses"><select name="meses" class="input"><?php for ($i = 1; $i <= 12; $i++): ?><option value="<?= $i ?>"><?= $i ?> mes<?= $i > 1 ? 'es' : '' ?></option><?php endfor; ?></select></div>
        <label style="display:flex;align-items:center;gap:8px;font-size:13px;font-weight:600"><input type="radio" name="modo" value="fecha" onchange="document.getElementById('rv-fecha').classList.remove('hidden');document.getElementById('rv-meses').classList.add('hidden')" class="accent-teal-500"> Asignar fecha manualmente</label>
        <div id="rv-fecha" class="hidden"><input type="date" name="hasta" class="input"></div>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
        <div class="field" style="margin:0"><label>Método de pago</label><input name="metodo" class="input" placeholder="Sin especificar"></div>
        <div class="field" style="margin:0"><label>Ref / Boleta</label><input name="referencia" class="input" placeholder="Ej: 482910"></div>
      </div>
      <div class="field" style="margin:12px 0 0"><label>Comprobante (opcional)</label><input type="file" name="comprobante" accept="image/*,application/pdf" style="font-size:13px;color:var(--muted)"></div>
      <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:14px"><button type="button" onclick="cerrar()" class="btn ghost">Cancelar</button><button class="btn primary">Renovar</button></div>
    </form>
  </div>
  <!-- Registro -->
  <div id="m-registro" class="hidden modal" style="max-width:28rem">
    <div class="modal-hd"><h3><i data-lucide="clock"></i> Registro de la venta</h3><button onclick="cerrar()" class="modal-x"><i data-lucide="x"></i></button></div>
    <div id="reg-body" class="thin" style="padding:16px;max-height:24rem;overflow-y:auto;font-size:13px">Cargando…</div>
    <div style="padding:0 16px 16px;display:flex;justify-content:flex-end"><button onclick="cerrar()" class="btn primary">Cerrar</button></div>
  </div>
  <!-- Nota -->
  <div id="m-nota" class="hidden modal" style="max-width:28rem">
    <div class="modal-hd"><h3><i data-lucide="sticky-note"></i> Nota de la Venta</h3><button onclick="cerrar()" class="modal-x"><i data-lucide="x"></i></button></div>
    <form method="post" class="modal-bd"><input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="accion" value="nota"><input type="hidden" name="id" id="nt-id"><input type="hidden" name="f" value="<?= h($f) ?>">
      <textarea name="nota" rows="3" class="input" placeholder="Ej: Cliente confirmó que no renovará el próximo mes." required></textarea>
      <div id="nt-prev" class="thin" style="max-height:8rem;overflow-y:auto;display:flex;flex-direction:column;gap:4px;font-size:12px;margin-top:10px"></div>
      <p style="font-size:11.5px;color:var(--faint);margin:10px 0 0">Esta nota es interna y solo se usa para seguimiento.</p>
      <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:12px"><button type="button" onclick="cerrar()" class="btn ghost">Cancelar</button><button class="btn primary">Guardar nota</button></div>
    </form>
  </div>
  <!-- Reemplazar: mover la venta a otra cuenta (misma plataforma u otra) -->
  <div id="m-reemplazar" class="hidden modal" style="max-width:34rem">
    <div class="modal-hd"><h3><i data-lucide="repeat"></i> Reemplazar cuenta</h3><button onclick="cerrar()" class="modal-x"><i data-lucide="x"></i></button></div>
    <form method="post" class="modal-bd"><input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="accion" value="reemplazar_venta"><input type="hidden" name="id" id="rp-id"><input type="hidden" name="f" value="<?= h($f) ?>">
      <div style="padding:9px 11px;border-radius:9px;background:var(--surface-2);border:1px solid var(--border);font-size:12.5px;margin-bottom:12px">
        <div style="color:var(--faint);font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;margin-bottom:3px">Ahora está en</div>
        <div id="rp-actual" style="color:var(--text);font-weight:600">—</div>
      </div>
      <!-- Otras ventas en la MISMA cuenta: si la cuenta no sirve, todas están rotas, no solo esta -->
      <div id="rp-herm-box" style="display:none;padding:9px 11px;border-radius:9px;background:rgba(245,166,35,.10);border:1px solid rgba(245,166,35,.35);margin-bottom:12px">
        <div style="font-size:12px;color:var(--warn);font-weight:600;margin-bottom:6px">
          ⚠ Esta cuenta tiene <b id="rp-herm-n">0</b> cliente(s) más. Si la cuenta no sirve, a ellos tampoco les funciona.
        </div>
        <div id="rp-herm-list" style="max-height:130px;overflow:auto;font-size:11.5px;color:var(--muted);line-height:1.7"></div>
        <label style="display:flex;align-items:center;gap:7px;margin-top:8px;font-size:12.5px;color:var(--text);cursor:pointer">
          <input type="checkbox" name="todas" id="rp-todas" value="1" onchange="rpFiltrar()">
          <span>Mover <b>a todos</b> a la cuenta nueva</span>
        </label>
      </div>
      <div class="field"><label>Plataforma destino</label>
        <select id="rp-plat" class="input" onchange="rpFiltrar()"><option value="">Todas</option></select></div>
      <div class="field"><label>Cuenta destino</label>
        <select name="cuenta_id" id="rp-cta" class="input" required></select>
        <div id="rp-vacio" style="display:none;font-size:11.5px;color:var(--warn);margin-top:5px">No hay cuentas con perfiles libres suficientes en esa plataforma.</div>
      </div>
      <label style="display:flex;align-items:center;gap:7px;margin-bottom:10px;font-size:12.5px;color:var(--text);cursor:pointer">
        <input type="checkbox" name="avisar" id="rp-avisar" value="1" checked>
        <span>Avisar del cambio automáticamente</span>
      </label>
      <div style="padding:9px 11px;border-radius:9px;background:var(--surface-2);border:1px solid var(--border);font-size:11.5px;color:var(--muted);margin-bottom:12px;line-height:1.65">
        Se libera el perfil viejo y se toma uno libre de la cuenta nueva. Se conservan cliente, precio,
        vencimiento e historial.<br>
        · <b>Ventas tuyas</b>: al cliente por WhatsApp, con un <b>link privado</b> (nunca la clave en el mensaje).
        Si su ventana de 24h está cerrada te aviso para que lo hagas a mano.<br>
        · <b>Ventas de revendedor</b>: el aviso le llega <b>al revendedor</b> en su panel, no a su cliente
        (ese cliente es suyo y no debe saber que nos compra a nosotros).
      </div>
      <div style="display:flex;justify-content:flex-end;gap:8px">
        <button type="button" onclick="cerrar()" class="btn ghost">Cancelar</button>
        <button class="btn primary"><i data-lucide="repeat"></i> Reemplazar</button>
      </div>
    </form>
  </div>
  <!-- Editar -->
  <div id="m-editar" class="hidden modal" style="max-width:32rem">
    <div class="modal-hd"><h3><i data-lucide="pencil"></i> Editar Venta</h3><button onclick="cerrar()" class="modal-x"><i data-lucide="x"></i></button></div>
    <form method="post" class="modal-bd grid md:grid-cols-2 gap-3"><input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="accion" value="editar_venta"><input type="hidden" name="id" id="e-id"><input type="hidden" name="f" value="<?= h($f) ?>">
      <div class="field" style="margin:0"><label>Cliente</label><input name="cliente_nombre" id="e-cli" class="input"></div>
      <div class="field" style="margin:0"><label>WhatsApp</label><input name="cliente_wa" id="e-wa" class="input"></div>
      <div class="field" style="margin:0"><label>Plataforma</label><input name="plataforma" id="e-plat" class="input"></div>
      <div class="field" style="margin:0"><label>Correo</label><input name="correo" id="e-correo" class="input"></div>
      <div class="field" style="margin:0"><label>Clave</label><input name="clave" id="e-clave" class="input"></div>
      <div class="field" style="margin:0"><label>Perfil</label><input name="perfil" id="e-perfil" class="input"></div>
      <div class="field" style="margin:0"><label>PIN</label><input name="pin" id="e-pin" class="input"></div>
      <div class="field" style="margin:0"><label>Fecha de Renovación</label><input type="date" name="fecha_vencimiento" id="e-venc" class="input"></div>
      <div class="field" style="margin:0"><label><?= $esRevCtx ? 'Precio de venta (a tu cliente)' : 'Precio' ?></label><div style="display:flex"><span style="padding:0 10px;display:grid;place-items:center;background:var(--surface-2);border:1px solid var(--border);border-right:0;border-radius:9px 0 0 9px;color:var(--muted)">$</span><input name="precio" id="e-precio" type="number" step="0.01" class="input" style="border-radius:0 9px 9px 0"></div></div>
      <div class="field" style="margin:0"><label>Método de Pago</label><input name="metodo_pago" id="e-met" class="input" placeholder="Sin especificar"></div>
      <div id="e-limite-nota" class="md:col-span-2" style="display:none;font-size:11px;color:var(--faint);margin:0">Puedes cambiar <b>nombre y PIN</b> del perfil máx. <b>2 veces</b> por periodo (se reinicia al renovar). <b>Plataforma, correo y clave</b> solo los cambia la tienda.</div>
      <div class="md:col-span-2" style="display:flex;justify-content:flex-end;gap:8px;padding-top:2px"><button type="button" onclick="cerrar()" class="btn ghost">Cancelar</button><button class="btn primary">Guardar Cambios</button></div>
    </form>
  </div>
  <!-- Eliminar -->
  <div id="m-eliminar" class="hidden modal" style="max-width:24rem">
    <div class="modal-bd" style="text-align:center;padding:24px">
      <div style="width:56px;height:56px;border-radius:50%;background:var(--bad-soft);color:var(--bad);display:grid;place-items:center;margin:0 auto 12px"><i data-lucide="trash-2" style="width:26px;height:26px"></i></div>
      <h3 style="font-weight:700;font-size:17px;margin:0 0 4px">¿Eliminar esta venta?</h3><p style="font-size:12px;color:var(--faint);margin:0 0 18px">Se libera el perfil. No se puede deshacer.</p>
      <form method="post" style="display:flex;justify-content:center;gap:8px"><input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="accion" value="eliminar_venta"><input type="hidden" name="id" id="el-id"><input type="hidden" name="f" value="<?= h($f) ?>">
        <button class="btn danger">Sí, eliminar</button><button type="button" onclick="cerrar()" class="btn ghost">Cancelar</button></form>
    </div>
  </div>
</div>

<script>
  let VID=0, VMSGS={};
  const esc=s=>String(s==null?'':s).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const ov=document.getElementById('overlay'), m3=document.getElementById('menu3');
  function cerrar(){ ov.classList.add('hidden'); ov.classList.remove('flex'); document.querySelectorAll('#overlay > div').forEach(m=>m.classList.add('hidden')); }
  function abrir(id){ ov.classList.remove('hidden'); ov.classList.add('flex'); document.querySelectorAll('#overlay > div').forEach(m=>m.classList.add('hidden')); document.getElementById(id).classList.remove('hidden'); lucide.createIcons(); }
  // El menú es position:fixed. Antes SIEMPRE se abría hacia abajo: en las últimas filas de la tabla
  // caía fuera de la pantalla y parecía que los 3 puntos no hacían nada. Ahora se voltea hacia
  // arriba cuando no cabe abajo, y se queda pegado al borde si no cabe en ninguna parte.
  function menu(e,id){
    e.stopPropagation(); VID=id;
    const r=e.currentTarget.getBoundingClientRect();
    // Mostrar + pintar iconos ANTES de medir: oculto mide 0, y sin iconos mide menos de lo real.
    // Todo pasa en el mismo frame, así que no se ve ningún salto.
    m3.classList.remove('hidden'); lucide.createIcons();
    const h=m3.offsetHeight, w=m3.offsetWidth||176;
    let top=(window.innerHeight-r.bottom >= h+8) ? r.bottom+4 : r.top-h-4;
    m3.style.top =Math.max(8, Math.min(top, window.innerHeight-h-8))+'px';
    m3.style.left=Math.max(8, Math.min(r.right-w, window.innerWidth-w-8))+'px';
  }
  document.addEventListener('click',e=>{ if(!m3.contains(e.target)) m3.classList.add('hidden'); });
  function setId(id){ document.getElementById(id).value=VID; }
  async function fetchVenta(){ return (await fetch('?ajax=venta&id='+VID).then(x=>x.json())); }

  function mActivar(){ m3.classList.add('hidden'); if(!confirm('¿Marcar esta venta como ACTIVADA? Úsalo cuando ya enviaste la invitación al correo del cliente (ej. Canva).')) return; document.getElementById('ac-id').value=VID; document.getElementById('f-activar').submit(); }

  // ── Reemplazar: mover la venta a otra cuenta ──
  // Solo llegan cuentas que PUEDEN recibir al cliente (con perfil libre, o Canva que no usa perfiles).
  const RP_CTAS = <?= json_encode($ctasDestino, JSON_UNESCAPED_UNICODE) ?>;
  let RP_ACT = 0;    // cuenta_id donde está HOY la venta abierta (para no ofrecerla como destino)
  let RP_HERM = 0;   // cuántas OTRAS ventas activas viven en esa misma cuenta
  async function mReemplazar(){
    m3.classList.add('hidden'); setId('rp-id');
    const r=await fetchVenta(); if(!r.ok) return; const v=r.venta;
    RP_ACT = v.cuenta_id || 0;
    document.getElementById('rp-actual').textContent =
      (v.plataforma||'—') + (v.perfil?' · '+v.perfil:'') + (v.correo?' · '+v.correo:'');

    // ¿Quién más está en esta cuenta? Si no sirve, a ellos tampoco les sirve.
    const h=await fetch('?ajax=hermanas&id='+VID).then(x=>x.json()).catch(()=>({ok:false}));
    const items=(h.ok&&h.items)?h.items:[];
    RP_HERM=items.length;
    document.getElementById('rp-herm-box').style.display = items.length?'block':'none';
    document.getElementById('rp-todas').checked=false;
    if(items.length){
      document.getElementById('rp-herm-n').textContent=items.length;
      document.getElementById('rp-herm-list').innerHTML=items.map(i=>'• '+esc(i.cliente||'(sin nombre)')
        +(i.perfil?' <b style="color:var(--text)">'+esc(i.perfil)+'</b>':'')
        +(i.rev_nombre?' <span style="opacity:.7">— rev: '+esc(i.rev_nombre)+'</span>':'')
        +(i.fecha_vencimiento?' <span style="opacity:.6">vence '+esc(String(i.fecha_vencimiento).slice(0,10))+'</span>':'')
        +(i.cliente_wa?'':' <span style="color:var(--warn)">sin WhatsApp</span>')).join('<br>');
    }

    // La plataforma actual va preseleccionada: reemplazar por la MISMA plataforma es el caso normal
    // (cambio de cuenta); cambiar de plataforma existe pero es la excepción.
    const plats=[...new Set(RP_CTAS.map(c=>c.plataforma).filter(Boolean))].sort();
    const sp=document.getElementById('rp-plat');
    sp.innerHTML='<option value="">Todas</option>'+plats.map(p=>'<option'+(p===v.plataforma?' selected':'')+'>'+esc(p)+'</option>').join('');
    rpFiltrar();
    abrir('m-reemplazar');
  }
  function rpFiltrar(){
    const plat=document.getElementById('rp-plat').value;
    const sel=document.getElementById('rp-cta');
    // Al mover a TODOS hacen falta al menos 1 perfil por venta. Es un mínimo, no la cuenta exacta:
    // una venta puede atar 2 o 3 perfiles, y eso solo lo sabe el servidor (que revalida el total y
    // aborta entero si no alcanza, antes de mover a nadie).
    const nMin=document.getElementById('rp-todas').checked ? RP_HERM+1 : 1;
    // Nunca ofrecer la cuenta en la que YA está: el servidor lo rechaza igual, pero mejor no tentar.
    const lista=RP_CTAS.filter(c=>(!plat||c.plataforma===plat) && String(c.id)!==String(RP_ACT)
                                 && (c.modo==='email_manual' || c.libres>=nMin));
    sel.innerHTML=lista.map(c=>'<option value="'+c.id+'">'+esc(c.plataforma||'?')+' · '+esc(c.correo||('cuenta #'+c.id))
      +' — '+(c.modo==='email_manual'?'por correo':(c.libres+' libre'+(c.libres==1?'':'s')))+'</option>').join('');
    document.getElementById('rp-vacio').style.display = lista.length?'none':'block';
    document.getElementById('rp-vacio').textContent = nMin>1
      ? 'Ninguna cuenta de esa plataforma tiene '+nMin+' perfiles libres para mover a todos.'
      : 'No hay cuentas con perfiles libres en esa plataforma.';
    sel.disabled = !lista.length;
  }
  let N_REV='', N_REV_WA='', N_REV_MSG='';   // datos del revendedor dueño de la venta ('' = venta tuya)
  function commNotif(id){ VID=id; mNotificar(); return false; }   // abrir Notificar (mensaje personalizado) desde la fila
  async function mNotificar(){ m3.classList.add('hidden'); setId('n-id'); const r=await fetchVenta(); if(!r.ok)return; VMSGS=r.msgs;
    const v=r.venta;
    // Ojo: le vas a escribir al cliente DEL REVENDEDOR, no al tuyo. Se avisa antes, no después.
    N_REV = (v.revendedor_id && Number(v.revendedor_id)>0) ? (v.rev_nombre||'un revendedor') : '';
    N_REV_WA = v.rev_wa || ''; N_REV_MSG = (r.msgs && r.msgs.aviso_rev) || '';
    document.getElementById('n-rev').style.display = N_REV?'block':'none';
    if(N_REV) document.getElementById('n-rev-nom').textContent = N_REV;
    // #15: enlace para avisar AL REVENDEDOR (para que él avise a su cliente), si tiene WhatsApp.
    const brw=document.getElementById('n-rev-wa');
    if(brw){ if(N_REV && N_REV_WA){ brw.href='https://wa.me/'+N_REV_WA+'?text='+encodeURIComponent(N_REV_MSG||''); brw.style.display=''; } else { brw.style.display='none'; } }
    document.getElementById('n-tipo').value='credenciales'; rellenarMsg(); abrir('m-notif'); }
  function nConfirmar(){
    if(!N_REV) return true;
    return confirm('Este cliente es de '+N_REV+' (un revendedor).\n\nNormalmente es el revendedor quien avisa a su propia gente.\n\n¿Enviar igual?');
  }
  function rellenarMsg(){ const t=document.getElementById('n-tipo').value; const ta=document.getElementById('n-msg'); if(t==='custom'){ if(!ta.value) ta.value=''; } else ta.value=VMSGS[t]||''; }
  function copiar(){ navigator.clipboard.writeText(document.getElementById('n-msg').value); }
  function mRenovar(){ m3.classList.add('hidden'); setId('rv-id');
    document.querySelector('#m-renovar input[name=modo][value=meses]').checked=true;
    document.getElementById('rv-meses').classList.remove('hidden'); document.getElementById('rv-fecha').classList.add('hidden');
    ['hasta','metodo','referencia'].forEach(n=>{const el=document.querySelector('#m-renovar [name='+n+']'); if(el) el.value='';});
    const fc=document.querySelector('#m-renovar [name=comprobante]'); if(fc) fc.value='';
    abrir('m-renovar'); }
  async function mRegistro(){ m3.classList.add('hidden'); abrir('m-registro'); const b=document.getElementById('reg-body'); b.innerHTML='Cargando…'; const r=await fetch('?ajax=registro&id='+VID).then(x=>x.json()); b.innerHTML = r.items&&r.items.length? r.items.map(i=>`<div style="border-left:2px solid var(--accent);padding:4px 0 4px 12px;margin-bottom:6px"><div style="font-weight:600;text-transform:capitalize">${esc(i.evento)}</div><div style="font-size:12px;color:var(--muted)">${esc(i.descripcion)}</div><div style="font-size:10.5px;color:var(--faint)">${new Date(i.creado_en.replace(' ','T')).toLocaleString('es-VE')} ${i.quien?'· '+esc(i.quien):''}</div></div>`).join('') : '<div class="empty">Sin movimientos registrados.</div>'; }
  async function mNota(){ m3.classList.add('hidden'); setId('nt-id'); abrir('m-nota'); const r=await fetch('?ajax=notas&id='+VID).then(x=>x.json()); const p=document.getElementById('nt-prev'); p.innerHTML=r.items&&r.items.length? r.items.map(i=>`<div style="background:var(--surface-2);border-radius:8px;padding:8px;color:var(--muted)">${esc(i.nota)} <span style="color:var(--faint)">· ${new Date(i.creado_en.replace(' ','T')).toLocaleDateString('es-VE')}</span></div>`).join('') : ''; }
  const ES_REV = <?= $esRevCtx ? 'true' : 'false' ?>;
  async function mEditar(){ m3.classList.add('hidden'); setId('e-id'); const r=await fetchVenta(); if(!r.ok)return; const v=r.venta;
    e_set('e-cli',v.cliente_nombre); e_set('e-wa',v.cliente_wa); e_set('e-plat',v.plataforma); e_set('e-correo',v.correo); e_set('e-clave',v.clave); e_set('e-perfil',v.perfil); e_set('e-pin',v.pin);
    // El revendedor ve/edita su PRECIO DE VENTA (no el costo): usa precio_venta_cliente si existe.
    e_set('e-precio', (ES_REV && v.precio_venta_cliente!=null && v.precio_venta_cliente!=='') ? v.precio_venta_cliente : v.precio);
    e_set('e-met',v.metodo_pago); document.getElementById('e-venc').value=(v.fecha_vencimiento||'').slice(0,10);
    // El revendedor (perfil): SÍ edita cliente/WhatsApp/precio/método/fecha y NOMBRE-PIN del perfil
    // (máx 2 veces, se reinicia al renovar). SOLO bloquea plataforma / correo / clave. Cuenta completa: todo.
    var soloCliente = ES_REV && (v.tipo !== 'cuenta');
    ['e-plat','e-correo','e-clave'].forEach(function(id){ var el=document.getElementById(id); if(el){ el.readOnly=soloCliente; el.style.opacity=soloCliente?'.5':'1'; el.title=soloCliente?'Solo lo cambia la tienda':''; } });
    ['e-perfil','e-pin','e-met'].forEach(function(id){ var el=document.getElementById(id); if(el){ el.readOnly=false; el.style.opacity='1'; el.title=''; } });
    var nota=document.getElementById('e-limite-nota'); if(nota) nota.style.display=soloCliente?'block':'none';
    abrir('m-editar'); }
  function e_set(id,val){ document.getElementById(id).value = val==null?'':val; }
  function mEliminar(){ m3.classList.add('hidden'); setId('el-id'); abrir('m-eliminar'); }
  function ordenarVentas(){ const v=document.getElementById('forden').value; if(!v) return; const tb=document.getElementById('tbody'); if(!tb) return; const rows=Array.from(tb.querySelectorAll('tr')); const p=v.split('-'), key=p[0], mul=p[1]==='desc'?-1:1; rows.sort((a,b)=>{ if(key==='venc'){ return ((parseInt(a.dataset.venc||'0',10))-(parseInt(b.dataset.venc||'0',10)))*mul; } const ka=(key==='cli')?'cli':((key==='correo')?'correo':((key==='rev')?'rev':'venc')); const va=String(a.dataset[ka]||''), vb=String(b.dataset[ka]||''); if(!va&&!vb) return 0; if(!va) return 1; if(!vb) return -1; return va.localeCompare(vb)*mul; }); rows.forEach(r=>tb.appendChild(r)); aplicarFiltro(); }
  let LIM=0;   // 0 = mostrar TODAS por defecto (antes 25: ocultaba las de más abajo y no se veían al renovar/vencer).
  function aplicarFiltro(){
    const q=(document.getElementById('buscar').value||'').toLowerCase().trim();
    const fp=(document.getElementById('fplat')?.value||'');
    const fe=(document.getElementById('fest')?.value||'');
    const fs=(document.getElementById('fsrc')?.value||'');   // origen: mías / de revendedores
    const anyFiltro = q||fp||fe||fs;
    let shown=0;
    document.querySelectorAll('#tbody tr').forEach(tr=>{
      const okQ=!q||(tr.dataset.b||'').includes(q);
      const okP=!fp||(tr.dataset.plat||'')===fp;
      const okE=!fe||(tr.dataset.est||'')===fe;
      const okS=!fs||(tr.dataset.src||'')===fs;
      const match=okQ&&okP&&okE&&okS;
      let vis=match; if(match && !anyFiltro && LIM>0) vis=shown<LIM; if(vis) shown++;
      tr.style.display=vis?'':'none';
      // REGLA DE ORO: fila oculta = DESMARCADA. Esta tabla además oculta todo lo que pase de 25 por
      // defecto, así que sin esto "eliminar varias" borraría ventas que ni estás viendo.
      if(!vis){ const c=tr.querySelector('.ck-row'); if(c) c.checked=false; }
    });
    ckSync();
  }

  /* ── Selección múltiple ───────────────────────────────────────────────────── */
  function filasVisibles(){ return Array.from(document.querySelectorAll('#tbody tr')).filter(tr=>tr.style.display!=='none'); }
  function ckTodo(m){ filasVisibles().forEach(tr=>{ const c=tr.querySelector('.ck-row'); if(c) c.checked=m.checked; }); ckSync(); }
  function ckMarcados(){ return filasVisibles().map(tr=>tr.querySelector('.ck-row')).filter(c=>c&&c.checked); }
  function ckIds(){ return ckMarcados().map(c=>c.value); }
  function ckSync(){
    const m=ckMarcados(), n=m.length, bar=document.getElementById('bulkbar');
    if(bar){ bar.style.display=n>0?'flex':'none'; const t=document.getElementById('bulk-n'); if(t) t.textContent=n; }
    const nrev=m.filter(c=>c.dataset.rev==='1').length, av=document.getElementById('bulk-rev');
    if(av){ av.style.display=nrev>0?'':'none'; const x=document.getElementById('bulk-rev-n'); if(x) x.textContent=nrev; }
    const vis=filasVisibles().length, all=document.getElementById('ck-all');
    if(all){ all.checked=n>0&&n===vis; all.indeterminate=n>0&&n<vis; }
  }
  function bulkRenovar(){ const i=ckIds(); if(!i.length) return; document.getElementById('lr-ids').value=i.join(','); document.getElementById('lr-n').textContent=i.length; const nr=ckMarcados().filter(c=>c.dataset.rev==='1').length; document.getElementById('lr-rev').style.display=nr>0?'':'none'; document.getElementById('lr-rev-n').textContent=nr; abrir('m-lote-renovar'); }
  function bulkEliminar(){ const i=ckIds(); if(!i.length) return; document.getElementById('le-ids').value=i.join(','); document.getElementById('le-n').textContent=i.length; document.getElementById('le-conf').value=''; abrir('m-lote-eliminar'); }
  function bulkPrecios(){ const i=ckIds(); if(!i.length) return; document.getElementById('lp-ids').value=i.join(','); document.getElementById('lp-n').textContent=i.length; abrir('m-lote-precios'); }
  function bulkReasignar(){ const i=ckIds(); if(!i.length) return; document.getElementById('lra-ids').value=i.join(','); document.getElementById('lra-n').textContent=i.length; const s=document.getElementById('lra-cli'); if(s){ s.value=''; lraCliSel(s); } const nn=document.getElementById('lra-clinom'), ww=document.getElementById('lra-cliwa'); if(nn) nn.value=''; if(ww) ww.value=''; abrir('m-lote-reasignar'); }
  // Al elegir un cliente existente del desplegable: se bloquea el nombre libre (se usará el del cliente) y se
  // prellena su WhatsApp. Al volver a «No cambiar», se reactiva el nombre libre.
  function lraCliSel(sel){
    const nom=document.getElementById('lra-clinom'), wa=document.getElementById('lra-cliwa');
    const op=sel.options[sel.selectedIndex];
    if(sel.value){ if(nom){ nom.value=''; nom.disabled=true; nom.placeholder='(usando el cliente elegido arriba)'; } if(wa && !wa.value){ wa.value=(op&&op.dataset.wa)||''; } }
    else { if(nom){ nom.disabled=false; nom.placeholder='Dejar vacío = no cambiar'; } }
  }
  function limitar(n){ LIM=parseInt(n)||0; aplicarFiltro(); }
  // Copia el N° de pedido al portapapeles (para pegarlo, p.ej., en un ticket de soporte).
  function copiarCod(cod, el){
    function done(){ if(el){ var o=el.textContent; el.textContent='¡Copiado!'; setTimeout(function(){ el.textContent=o; }, 1100); } }
    try{ if(navigator.clipboard && navigator.clipboard.writeText){ navigator.clipboard.writeText(cod).then(done, function(){ done(); }); return; } }catch(e){}
    try{ var t=document.createElement('textarea'); t.value=cod; document.body.appendChild(t); t.select(); document.execCommand('copy'); document.body.removeChild(t); }catch(e){}
    done();
  }
  document.getElementById('buscar')?.addEventListener('input',aplicarFiltro);
  aplicarFiltro();
</script>
<?php stream_foot(); ?>
