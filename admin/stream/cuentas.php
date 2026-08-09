<?php
/**
 * Plataformas → Cuentas (estilo PAC). Inventario de cuentas/perfiles de streaming.
 * 4 KPIs + Inventario Detallado colapsable + tabla + modales: Nueva, Editar (gestión de
 * perfiles), Renovar, Detalles (+ Ventas Asociadas) y Eliminar. Reusa la lógica de admin/streaming.php.
 */
define('CONEC_ADMIN', true);
require __DIR__ . '/../_auth.php';
require __DIR__ . '/_layout.php';
require __DIR__ . '/../_streaming.php';   // st_propagar_credenciales() — ver editar_cuenta
admin_require_login();
if (!admin_es_admin() && !in_array(admin_wa_area(), ['streaming', 'ambas'], true)) {
  http_response_code(403);
  exit('Esta sección es del área de Streaming.');
}
$verCostos = admin_es_admin();   // costos/proveedor: solo dueño
$pdo = db();
$OWNER = (int) stream_owner_id();
$esRev = $OWNER > 0;             // C1: el revendedor NO edita la fecha del STOCK (se fija al comprar);
                                 // solo cambia la fecha del CLIENTE en «Ventas».
$hoy = new DateTimeImmutable('today');
// #D: precio de venta y reventa POR CUENTA (override del precio de la plataforma). Sirve cuando el
// mismo servicio, comprado a proveedores distintos, se vende a precios distintos. Se crean solas.
try { $pdo->exec("ALTER TABLE streaming_cuentas ADD COLUMN precio_venta DECIMAL(12,2) NULL"); } catch (Throwable $e) {}
try { $pdo->exec("ALTER TABLE streaming_cuentas ADD COLUMN precio_reventa DECIMAL(12,2) NULL"); } catch (Throwable $e) {}
// Asegura el esquema espejo (origen_cuenta_id, rev_editable, etc.) AQUÍ, fuera de toda transacción,
// para que al ASIGNAR/vender a un revendedor (st_rev_entregar) las columnas ya existan y NO se dispare
// un ALTER dentro de la transacción (causaba "There is no active transaction"). Ver _streaming.php.
if (function_exists('st_rev_stock_schema')) { try { st_rev_stock_schema($pdo); } catch (Throwable $e) {} }

function dias_de(?string $venc, DateTimeImmutable $hoy): ?int {
  if (!$venc) return null;
  $v = DateTimeImmutable::createFromFormat('Y-m-d', substr($venc, 0, 10));
  return $v ? (int) $hoy->diff($v->setTime(0, 0))->format('%r%a') : null;
}

// ── AJAX: detalle de una cuenta (para Editar y Detalles) ──
if (($_GET['ajax'] ?? '') === 'cuenta') {
  header('Content-Type: application/json; charset=utf-8');
  $id = (int) ($_GET['id'] ?? 0);
  $cu = $pdo->prepare("SELECT * FROM streaming_cuentas WHERE id=? AND owner_id=?");
  $cu->execute([$id, $OWNER]);
  $cu = $cu->fetch(PDO::FETCH_ASSOC);
  if (!$cu) { echo json_encode(['ok' => false]); exit; }
  if (!$verCostos) { unset($cu['costo'], $cu['costo_pagado'], $cu['proveedor_id']); }
  $pf = $pdo->prepare("SELECT p.id,p.etiqueta,p.pin,p.estado,p.venta_id, v.cliente_nombre, v.fecha_vencimiento
                       FROM streaming_perfiles p LEFT JOIN streaming_ventas v ON v.id=p.venta_id
                       WHERE p.cuenta_id=? ORDER BY p.id");
  $pf->execute([$id]);
  $perfiles = $pf->fetchAll(PDO::FETCH_ASSOC);
  $prov = null;
  if ($verCostos && !empty($cu['proveedor_id'])) {
    $prov = $pdo->query("SELECT nombre,contacto FROM streaming_proveedores WHERE id=" . (int) $cu['proveedor_id'] . " AND owner_id=$OWNER")->fetch(PDO::FETCH_ASSOC) ?: null;
  }
  // C2: para el revendedor, el stock comprado/asignado viene de la tienda → proveedor "REBOR".
  if ($esRev && !empty($cu['origen_cuenta_id'])) { $prov = ['nombre' => 'REBOR', 'contacto' => '']; }
  echo json_encode(['ok' => true, 'cuenta' => $cu, 'perfiles' => $perfiles, 'prov' => $prov]);
  exit;
}
if (($_GET['ajax'] ?? '') === 'calendario') {
  header('Content-Type: application/json; charset=utf-8');
  $ym = preg_match('/^\d{4}-\d{2}$/', (string) ($_GET['ym'] ?? '')) ? $_GET['ym'] : date('Y-m');
  $out = [];
  try {
    $q = $pdo->prepare("SELECT plataforma, correo, vencimiento, costo FROM streaming_cuentas WHERE owner_id=? AND vencimiento LIKE ? ORDER BY vencimiento");
    $q->execute([$OWNER, $ym . '%']);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
      $d = substr((string) $r['vencimiento'], 0, 10);
      $out[$d][] = ['plataforma' => $r['plataforma'], 'correo' => $r['correo'], 'costo' => $verCostos ? $r['costo'] : null];
    }
  } catch (Throwable $e) {}
  echo json_encode(['ok' => true, 'ym' => $ym, 'dias' => $out]);
  exit;
}

// ── Export CSV / Plantilla ──
if (($_GET['export'] ?? '') === 'plantilla') {
  if ($esRev && function_exists('st_rev_puede_exportar') && !st_rev_puede_exportar($pdo)) {
    http_response_code(403); exit('El administrador deshabilitó la descarga de tu inventario.');
  }
  header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="plantilla-cuentas.csv"');
  echo "\xEF\xBB\xBF"; $o = fopen('php://output', 'w');
  fputcsv($o, ['Plataforma', 'Correo', 'Clave', 'Nombre del perfil', 'PIN (opcional)', 'Vence (AAAA-MM-DD o DD/MM/AAAA)', 'Costo (opcional)', 'Vendedor (opcional)']);
  fputcsv($o, ['Netflix', 'correo@x.com', 'clave123', 'Rebeca JR', '9669', '01/08/2026', '3.50']);
  fputcsv($o, ['Netflix', 'correo@x.com', 'clave123', 'Pedro', '1234', '01/08/2026', '']);
  fputcsv($o, ['Disney+', 'otro@x.com', 'clave99', '', '', '2026-09-15', '']);
  fclose($o); exit;
}
if (($_GET['export'] ?? '') === 'csv') {
  // D5: el admin puede quitarle al revendedor el permiso de exportar su inventario (anti-secuestro).
  if ($esRev && function_exists('st_rev_puede_exportar') && !st_rev_puede_exportar($pdo)) {
    http_response_code(403);
    exit('El administrador deshabilitó la descarga de tu inventario.');
  }
  header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="cuentas.csv"');
  echo "\xEF\xBB\xBF"; $o = fopen('php://output', 'w');
  // El proveedor SOLO va en el CSV si es el dueño ($verCostos). Antes se exportaba a cualquier
  // empleado del área, saltándose el flag que oculta proveedor/costo en el resto de la página.
  $csvCols = ['Plataforma', 'Correo', 'Perfiles libres', 'Perfiles total', 'Vence'];
  if ($verCostos) $csvCols[] = 'Proveedor';
  fputcsv($o, $csvCols);
  $provSel  = $verCostos ? ", pr.nombre prov" : "";
  $provJoin = $verCostos ? " LEFT JOIN streaming_proveedores pr ON pr.id=c.proveedor_id" : "";
  try { foreach ($pdo->query("SELECT c.plataforma, c.correo, c.vencimiento{$provSel}, (SELECT COUNT(*) FROM streaming_perfiles p WHERE p.cuenta_id=c.id AND p.estado='libre') libres, (SELECT COUNT(*) FROM streaming_perfiles p WHERE p.cuenta_id=c.id) total FROM streaming_cuentas c{$provJoin} WHERE c.owner_id=$OWNER ORDER BY c.plataforma") as $r) {
    $row = [$r['plataforma'], $r['correo'], $r['libres'], $r['total'], $r['vencimiento']];
    if ($verCostos) $row[] = $r['prov'] ?? '';
    fputcsv($o, $row);
  } } catch (Throwable $e) {}
  fclose($o); exit;
}

// ── POST: acciones de cuenta ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $a = $_POST['accion'] ?? '';
  $msg = '';
  try {
    if ($a === 'nueva_cuenta') {
      $platId = ((int) ($_POST['plataforma_id'] ?? 0)) ?: null;
      $platNombre = $platId ? (string) $pdo->query("SELECT nombre FROM streaming_plataformas WHERE id=" . (int) $platId . " AND owner_id=$OWNER")->fetchColumn() : '';
      if ($platNombre === '') { $platNombre = 'Streaming'; $platId = null; }
      $perf = max(1, min(500, (int) ($_POST['perfiles_total'] ?? 1)));
      $usaPin = !empty($_POST['usa_pin']) ? 1 : 0;
      $st = $pdo->prepare("INSERT INTO streaming_cuentas (owner_id,plataforma,plataforma_id,proveedor_id,correo,clave,perfiles_total,costo,vencimiento,notas)
                           VALUES (?,?,?,?,?,?,?,?,?,?)");
      $st->execute([
        $OWNER, $platNombre, $platId,
        $verCostos ? (((int) ($_POST['proveedor_id'] ?? 0)) ?: null) : null,
        trim((string) ($_POST['correo'] ?? '')), trim((string) ($_POST['clave'] ?? '')), $perf,
        ($verCostos && ($_POST['costo'] ?? '') !== '') ? (float) $_POST['costo'] : null,
        ($_POST['vencimiento'] ?? '') ?: null, trim((string) ($_POST['notas'] ?? '')),
      ]);
      $cid = (int) $pdo->lastInsertId();
      try { $pdo->prepare("UPDATE streaming_cuentas SET usa_pin=? WHERE id=?")->execute([$usaPin, $cid]); } catch (Throwable $e) {}
      $pet = (array) ($_POST['pet'] ?? []); $ppin = (array) ($_POST['ppin'] ?? []);
      $insP = $pdo->prepare("INSERT INTO streaming_perfiles (cuenta_id,etiqueta,pin) VALUES (?,?,?)");
      for ($i = 1; $i <= $perf; $i++) {
        $et = trim((string) ($pet[$i - 1] ?? '')) ?: ('P' . $i);
        $pn = $usaPin ? (trim((string) ($ppin[$i - 1] ?? '')) ?: null) : null;
        $insP->execute([$cid, $et, $pn]);
      }
      $msg = "✓ Cuenta agregada con $perf perfil(es).";
    }
    elseif ($a === 'editar_cuenta') {
      $cid = (int) $_POST['id'];
      // Guarda de aislamiento (anti-IDOR): la cuenta DEBE ser del dueño del contexto.
      // Protege de una vez todos los accesos por id=$cid que siguen (incl. credenciales).
      $ownCu = $pdo->query("SELECT owner_id FROM streaming_cuentas WHERE id=$cid")->fetchColumn();
      if ($ownCu === false || (int) $ownCu !== $OWNER) throw new Exception('Cuenta no encontrada.');
      $nCorreo = trim((string) ($_POST['correo'] ?? ''));
      $nClave  = trim((string) ($_POST['clave'] ?? ''));
      if ($esRev) {
        // ¿Es stock COMPRADO/ASIGNADO a la tienda (espejo)? Entonces correo/fecha/costo los fija la TIENDA:
        // el revendedor solo cambia clave y notas. Si es una cuenta PROPIA (que subió él), edita todo.
        $esMirror = false;
        try { $esMirror = ((int) ($pdo->query("SELECT COALESCE(origen_cuenta_id,0) FROM streaming_cuentas WHERE id=$cid")->fetchColumn() ?: 0)) > 0; } catch (Throwable $e) {}
        if ($esMirror) {
          $nCorreo = (string) ($pdo->query("SELECT correo FROM streaming_cuentas WHERE id=$cid")->fetchColumn() ?: ''); // correo NO editable
          $pdo->prepare("UPDATE streaming_cuentas SET clave=?, notas=? WHERE id=? AND owner_id=?")
              ->execute([$nClave, trim((string) ($_POST['notas'] ?? '')) ?: null, $cid, $OWNER]);
        } else {
          // Cuenta propia del revendedor → puede editar todo (correo, clave, fecha, costo, notas).
          $pdo->prepare("UPDATE streaming_cuentas SET correo=?, clave=?, vencimiento=?, notas=? WHERE id=? AND owner_id=?")
              ->execute([$nCorreo, $nClave, ($_POST['vencimiento'] ?? '') ?: null, trim((string) ($_POST['notas'] ?? '')) ?: null, $cid, $OWNER]);
          if (($_POST['costo'] ?? '') !== '') { try { $pdo->prepare("UPDATE streaming_cuentas SET costo=? WHERE id=? AND owner_id=?")->execute([(float) str_replace(',', '.', (string) $_POST['costo']), $cid, $OWNER]); } catch (Throwable $e) {} }
        }
      } else {
        $pdo->prepare("UPDATE streaming_cuentas SET correo=?, clave=?, vencimiento=?, notas=? WHERE id=? AND owner_id=?")
            ->execute([$nCorreo, $nClave, ($_POST['vencimiento'] ?? '') ?: null, trim((string) ($_POST['notas'] ?? '')) ?: null, $cid, $OWNER]);
      }
      // Precio de VENTA del revendedor (a su cliente): esto SÍ lo cambia él, sea mirror o propia.
      if ($esRev && ($_POST['precio_venta_rev'] ?? '') !== '') {
        try { $pdo->prepare("UPDATE streaming_cuentas SET precio_venta=? WHERE id=? AND owner_id=?")->execute([(float) str_replace(',', '.', (string) $_POST['precio_venta_rev']), $cid, $OWNER]); } catch (Throwable $e) {}
      }
      // La VENTA guarda una COPIA de las credenciales: sin propagar, el cliente sigue viendo la
      // clave VIEJA en cuenta/acceso.php (el link de WhatsApp) y no puede entrar. Ver st_propagar_credenciales().
      $nSync = st_propagar_credenciales($pdo, $cid, $nCorreo, $nClave);

      // TODO lo que cambia el ADMIN se le ACTUALIZA al revendedor y le LLEGA notificación: propaga las
      // credenciales a sus cuentas ESPEJO (Fase 2) y les avisa en su panel. (Solo cuando edita el ADMIN;
      // si edita el revendedor su propia cuenta no debe generar avisos hacia nadie.)
      $nRev = 0;
      if (!$esRev && ($nCorreo !== '' || $nClave !== '')) {
        try {
          require_once __DIR__ . '/../../api/_rev_avisos.php';
          if (function_exists('st_rev_propagar_a_espejos')) { st_rev_propagar_a_espejos($pdo, $cid, $nCorreo !== '' ? $nCorreo : null, $nClave !== '' ? $nClave : null); }
          $plat = (string) ($pdo->query("SELECT plataforma FROM streaming_cuentas WHERE id=$cid")->fetchColumn() ?: '');
          $nRev = rev_avisar_credenciales($pdo, $cid, $plat);
        } catch (Throwable $e) {}
      }
      if ($verCostos) {
        $pdo->prepare("UPDATE streaming_cuentas SET costo=?, proveedor_id=? WHERE id=?")
            ->execute([($_POST['costo'] ?? '') !== '' ? (float) $_POST['costo'] : null, ((int) ($_POST['proveedor_id'] ?? 0)) ?: null, $cid]);
      }
      // Editar perfiles existentes (etiqueta/pin) por id; sincroniza con la venta si está vendido.
      $pet = (array) ($_POST['pet'] ?? []); $ppin = (array) ($_POST['ppin'] ?? []);
      $upP = $pdo->prepare("UPDATE streaming_perfiles SET etiqueta=?, pin=? WHERE id=? AND cuenta_id=?");
      // La venta debe CONSERVAR su perfil/pin si el campo viene vacío (no borrarlos: el cliente
      // perdería el acceso). Se actualiza solo lo que trae valor, en PHP, para no mezclar collations
      // (COALESCE(NULLIF(?,''),col) revienta con conexión general_ci + columnas unicode_ci → error 1267).
      $q = $pdo->prepare("SELECT id, venta_id, origen_perfil_id, etiqueta, pin, COALESCE(cambios_np,0) AS cn FROM streaming_perfiles WHERE cuenta_id=?"); $q->execute([$cid]);
      // ¿La cuenta es CUENTA COMPLETA del revendedor (rev_editable)? Si es completa, no hay límite; si es
      // un PERFIL suelto comprado a la tienda, el revendedor solo puede cambiar nombre/PIN 2 veces/periodo.
      $esCCcta = false;
      if ($esRev) { try { $esCCcta = ((int) ($pdo->query("SELECT COALESCE(rev_editable,0) FROM streaming_cuentas WHERE id=$cid")->fetchColumn() ?: 0)) === 1; } catch (Throwable $e) {} }
      $revSyncNP = 0; $bloqueados = 0;   // sincronizados a la tienda / bloqueados por el límite
      foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $pid = (int) $row['id'];
        if (!array_key_exists($pid, $pet)) continue;
        $et = trim((string) ($pet[$pid] ?? '')) ?: null;
        $pn = trim((string) ($ppin[$pid] ?? '')) ?: null;
        // ¿realmente cambió respecto a lo actual? (para no gastar el cupo al guardar sin tocar nada)
        $cambio = (($et !== null && $et !== (string) $row['etiqueta']) || ($pn !== null && $pn !== (string) $row['pin']));
        $esMirrorPerfil = $esRev && !$esCCcta && (int) ($row['origen_perfil_id'] ?? 0) > 0;
        if ($esMirrorPerfil && $cambio && (int) $row['cn'] >= 2) { $bloqueados++; continue; }   // límite alcanzado → no aplica
        $upP->execute([$et, $pn, $pid, $cid]);
        if (!empty($row['venta_id'])) {
          $setsV = []; $argsV = [];
          if ($et !== null) { $setsV[] = 'perfil=?'; $argsV[] = $et; }
          if ($pn !== null) { $setsV[] = 'pin=?';    $argsV[] = $pn; }
          if ($setsV) { $argsV[] = (int) $row['venta_id']; $pdo->prepare("UPDATE streaming_ventas SET " . implode(',', $setsV) . " WHERE id=?")->execute($argsV); }
        }
        // Revendedor → si el perfil vino de la tienda (origen_perfil_id), se ACTUALIZA el original del
        // admin también, para que "al ella cambiar, me cambie a mí" y me llegue notificación.
        if ($esRev && ($et !== null || $pn !== null) && (int) ($row['origen_perfil_id'] ?? 0) > 0) {
          $og = (int) $row['origen_perfil_id']; $s2 = []; $a2 = [];
          if ($et !== null) { $s2[] = 'etiqueta=?'; $a2[] = mb_substr($et, 0, 60); }
          if ($pn !== null) { $s2[] = 'pin=?'; $a2[] = $pn; }
          if ($s2) { $a2[] = $og; try { $pdo->prepare("UPDATE streaming_perfiles SET " . implode(',', $s2) . " WHERE id=?")->execute($a2); $revSyncNP++; } catch (Throwable $e) {} }
        }
        // Gasta 1 cambio del cupo (perfil suelto de mirror) solo si de verdad cambió.
        if ($esMirrorPerfil && $cambio) { try { $pdo->prepare("UPDATE streaming_perfiles SET cambios_np=COALESCE(cambios_np,0)+1 WHERE id=?")->execute([$pid]); } catch (Throwable $e) {} }
      }
      // Un solo aviso al admin si el revendedor sincronizó nombre/PIN de perfiles comprados a la tienda.
      if ($esRev && $revSyncNP > 0) {
        try {
          require_once __DIR__ . '/../../api/_rev_avisos.php';
          if (function_exists('stream_notif_crear')) {
            $rn = (string) ($pdo->query("SELECT COALESCE(NULLIF(nombre,''),username) FROM usuarios WHERE id=$OWNER")->fetchColumn() ?: '');
            $plt = (string) ($pdo->query("SELECT plataforma FROM streaming_cuentas WHERE id=$cid")->fetchColumn() ?: '');
            stream_notif_crear($pdo, 0, 'cuenta', 'Cambio de nombre/PIN · ' . ($plt ?: 'perfil'),
              'El revendedor ' . ($rn ?: '#' . $OWNER) . ' cambió el nombre/PIN de ' . $revSyncNP . ' perfil(es) de ' . ($plt ?: 'streaming') . '.', 'cuentas.php', (int) current_user_id());
          }
        } catch (Throwable $e) {}
      }
      // Aumentar stock si la cantidad subió (no se borran perfiles para no tumbar ventas).
      // El revendedor NO puede aumentar perfiles del stock que compró a la tienda (mirror): lo fija la tienda.
      $esMirrorCta = false;
      if ($esRev) { try { $esMirrorCta = ((int) ($pdo->query("SELECT COALESCE(origen_cuenta_id,0) FROM streaming_cuentas WHERE id=$cid")->fetchColumn() ?: 0)) > 0; } catch (Throwable $e) {} }
      $nuevaCant = ($esRev && $esMirrorCta) ? 0 : (int) ($_POST['perfiles_total'] ?? 0);
      if ($nuevaCant > 0) {
        $actual = (int) $pdo->query("SELECT perfiles_total FROM streaming_cuentas WHERE id=$cid")->fetchColumn();
        if ($nuevaCant > $actual) {
          $ins = $pdo->prepare("INSERT INTO streaming_perfiles (cuenta_id,etiqueta) VALUES (?,?)");
          for ($i = $actual + 1; $i <= $nuevaCant; $i++) $ins->execute([$cid, 'P' . $i]);
          $pdo->prepare("UPDATE streaming_cuentas SET perfiles_total=? WHERE id=?")->execute([$nuevaCant, $cid]);
        }
      }
      $msg = '✓ Cuenta actualizada.' . ($nSync > 0 ? " Credenciales sincronizadas en $nSync venta(s) activa(s)." : '')
           . ($nRev > 0 ? " Avisé a $nRev revendedor(es)." : '')
           . (!empty($bloqueados) ? " ⚠ $bloqueados perfil(es) NO se cambiaron: ya llegaron al límite de 2 cambios de nombre/PIN (se reinicia al renovar)." : '');
    }
    elseif ($a === 'renovar_cuenta') {
      $cid = (int) $_POST['id'];
      $ownCu = $pdo->query("SELECT owner_id FROM streaming_cuentas WHERE id=$cid")->fetchColumn();
      if ($ownCu === false || (int) $ownCu !== $OWNER) throw new Exception('Cuenta no encontrada.');
      $hasta = trim((string) ($_POST['hasta'] ?? ''));
      if ($hasta !== '' && strtotime($hasta)) { $nueva = date('Y-m-d', strtotime($hasta)); }
      else {
        $meses = max(1, min(24, (int) ($_POST['meses'] ?? 1)));
        $f = $pdo->query("SELECT vencimiento FROM streaming_cuentas WHERE id=$cid")->fetchColumn();
        $base = ($f && strtotime($f) > time()) ? $f : date('Y-m-d');
        $nueva = date('Y-m-d', strtotime($base . " +$meses months"));
      }
      $pdo->prepare("UPDATE streaming_cuentas SET vencimiento=? WHERE id=? AND owner_id=?")->execute([$nueva, $cid, $OWNER]);
      $msg = '✓ Cuenta renovada hasta ' . date('d/m/Y', strtotime($nueva)) . '.';
    }
    elseif ($a === 'eliminar_cuenta') {
      // GATE: eliminar es SOLO del dueño (antes cualquier empleado del área podía vaciar el
      // inventario, incluidas cuentas vendidas a revendedores).
      if (!$verCostos) throw new Exception('Solo el dueño puede eliminar cuentas.');
      $r = st_eliminar_cuenta($pdo, (int) $_POST['id'], !empty($_POST['forzar']));
      $msg = $r['msg'];
    }
    // ── LOTE: renovar varias cuentas ──────────────────────────────────────────────────────────
    // Los ids viajan en UN solo campo 'ids' ("12,45,78"), nunca ids[]: con ids[] el límite
    // max_input_vars (1000) los TRUNCA EN SILENCIO y creerías que renovaste todo.
    elseif ($a === 'renovar_cuentas_masivo') {
      $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string) ($_POST['ids'] ?? ''))))));
      if (!$ids) throw new Exception('No seleccionaste ninguna cuenta.');
      if (count($ids) > 200) throw new Exception('Máximo 200 cuentas por lote (seleccionaste ' . count($ids) . ').');
      $in = implode(',', $ids);
      $hasta = trim((string) ($_POST['hasta'] ?? ''));
      $pdo->beginTransaction();
      try {
        if ($hasta !== '' && strtotime($hasta)) {
          $st = $pdo->prepare("UPDATE streaming_cuentas SET vencimiento=? WHERE id IN ($in) AND owner_id=$OWNER");
          $st->execute([date('Y-m-d', strtotime($hasta))]);
          $n = $st->rowCount();
        } else {
          // Con "meses", cada cuenta parte de SU PROPIO vencimiento (vigente → desde su fecha;
          // vencida → desde hoy). Por eso es un loop y no un UPDATE masivo.
          $meses = max(1, min(24, (int) ($_POST['meses'] ?? 1)));
          $rows = $pdo->query("SELECT id, vencimiento FROM streaming_cuentas WHERE id IN ($in) AND owner_id=$OWNER FOR UPDATE")->fetchAll(PDO::FETCH_ASSOC);
          $up = $pdo->prepare("UPDATE streaming_cuentas SET vencimiento=? WHERE id=?");
          $n = 0;
          foreach ($rows as $r) {
            $base = ($r['vencimiento'] && strtotime($r['vencimiento']) > time()) ? $r['vencimiento'] : date('Y-m-d');
            $up->execute([date('Y-m-d', strtotime($base . " +$meses months")), (int) $r['id']]);
            $n += $up->rowCount();
          }
        }
        $pdo->commit();
      } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
      $msg = "✓ $n cuenta(s) renovadas. Ojo: esto renueva TU pago al PROVEEDOR, no el vencimiento de tus clientes.";
    }
    // ── LOTE: eliminar varias cuentas ─────────────────────────────────────────────────────────
    elseif ($a === 'eliminar_cuentas_masivo') {
      if (!$verCostos) throw new Exception('Solo el dueño puede eliminar cuentas.');
      if ((string) ($_POST['confirmar'] ?? '') !== 'ELIMINAR') throw new Exception('Escribe ELIMINAR para confirmar.');
      $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string) ($_POST['ids'] ?? ''))))));
      if (!$ids) throw new Exception('No seleccionaste ninguna cuenta.');
      if (count($ids) > 5000) throw new Exception('Máximo 5000 cuentas por lote.');
      @set_time_limit(180); // borrar muchas cuentas puede tardar; evita cortes por timeout
      $forzar = !empty($_POST['forzar']);
      $ok = 0; $abort = 0; $ventas = 0;
      foreach ($ids as $cid) {
        $r = st_eliminar_cuenta($pdo, $cid, $forzar);
        if ($r['ok']) { $ok++; $ventas += (int) $r['ventas']; } else $abort++;
      }
      $msg = ($ok > 0 ? "✓ $ok cuenta(s) eliminadas" . ($ventas > 0 ? " ($ventas venta(s) canceladas)" : '') . '.' : '⚠ No se eliminó ninguna.')
           . ($abort > 0 ? " $abort NO se tocaron por tener ventas activas (marca «Forzar» si aun así quieres)." : '');
    }
    // ── LOTE: asignar PROVEEDOR a varias cuentas ──
    elseif ($a === 'asignar_proveedor_masivo') {
      if (!$verCostos) throw new Exception('Solo el dueño puede cambiar el proveedor.');
      $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string) ($_POST['ids'] ?? ''))))));
      if (!$ids) throw new Exception('No seleccionaste ninguna cuenta.');
      if (count($ids) > 500) throw new Exception('Máximo 500 cuentas por lote.');
      $provId = ((int) ($_POST['proveedor_id'] ?? 0)) ?: null;
      $in = implode(',', $ids);
      $st = $pdo->prepare("UPDATE streaming_cuentas SET proveedor_id=? WHERE id IN ($in) AND owner_id=$OWNER");
      $st->execute([$provId]);
      $msg = '✓ Proveedor asignado a ' . $st->rowCount() . ' cuenta(s).';
    }
    // ── LOTE: cambiar COSTO (lo que pagas al proveedor) de varias cuentas ──
    elseif ($a === 'cambiar_costo_masivo') {
      // #D: costo + precio de VENTA + precio de REVENTA POR CUENTA (los precios varían por proveedor
      // aunque sea la misma plataforma). Se guarda solo lo que escribiste; lo vacío queda igual.
      // El ADMIN cambia todo. El REVENDEDOR puede cambiar VENTA y REVENTA de cualquiera; el COSTO solo
      // en sus cuentas PROPIAS (en el stock comprado a la tienda el costo es automático y no se toca).
      if (!$verCostos && !$esRev) throw new Exception('Sin permiso para cambiar precios.');
      $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string) ($_POST['ids'] ?? ''))))));
      if (!$ids) throw new Exception('No seleccionaste ninguna cuenta.');
      if (count($ids) > 500) throw new Exception('Máximo 500 cuentas por lote.');
      $num = static function ($k) { $r = str_replace(',', '.', trim((string) ($_POST[$k] ?? ''))); return ($r !== '' && is_numeric($r)) ? (float) $r : null; };
      $costo = $num('costo'); $venta = $num('precio_venta'); $reventa = $num('precio_reventa');
      if ($costo === null && $venta === null && $reventa === null) throw new Exception('Escribe al menos un precio (costo, venta o reventa).');
      $in = implode(',', $ids); $partes = []; $tot = 0;
      // venta/reventa → a TODAS las seleccionadas (del dueño del contexto).
      if ($venta !== null || $reventa !== null) {
        $sets = []; $args = [];
        if ($venta   !== null) { $sets[] = 'precio_venta=?';   $args[] = $venta; }
        if ($reventa !== null) { $sets[] = 'precio_reventa=?'; $args[] = $reventa; }
        $st = $pdo->prepare("UPDATE streaming_cuentas SET " . implode(',', $sets) . " WHERE id IN ($in) AND owner_id=$OWNER");
        $st->execute($args); $tot = max($tot, $st->rowCount());
        if ($venta   !== null) $partes[] = 'venta $' . number_format($venta, 2);
        if ($reventa !== null) $partes[] = 'reventa $' . number_format($reventa, 2);
      }
      // costo → admin a todas; revendedor SOLO a las propias (origen_cuenta_id vacío).
      if ($costo !== null) {
        $wc = "id IN ($in) AND owner_id=$OWNER" . ($esRev ? " AND COALESCE(origen_cuenta_id,0)=0" : "");
        $stC = $pdo->prepare("UPDATE streaming_cuentas SET costo=? WHERE $wc");
        $stC->execute([$costo]); $tot = max($tot, $stC->rowCount());
        $partes[] = 'costo $' . number_format($costo, 2) . ($esRev ? ' (solo cuentas propias)' : '');
      }
      $msg = '✓ ' . implode(' · ', $partes) . ' aplicado.';
    }
    // ── LOTE: VENDER varias (marca vendido: toma 1 perfil libre por cuenta y crea la venta) ──
    elseif ($a === 'vender_cuentas_masivo') {
      $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string) ($_POST['ids'] ?? ''))))));
      if (!$ids) throw new Exception('No seleccionaste ninguna cuenta.');
      if (count($ids) > 200) throw new Exception('Máximo 200 cuentas por lote.');
      $destino = (($_POST['destino'] ?? 'cliente') === 'revendedor') ? 'revendedor' : 'cliente';
      $cliNombre = trim((string) ($_POST['cliente_nombre'] ?? ''));
      $cliWa = preg_replace('/\D/', '', (string) ($_POST['cliente_wa'] ?? ''));
      $revId = ((int) ($_POST['revendedor_id'] ?? 0)) ?: null;
      $precio = ($_POST['precio'] ?? '') !== '' ? (float) str_replace(',', '.', (string) $_POST['precio']) : null;   // precio para TODAS
      $pPrecio = is_array($_POST['p_precio'] ?? null) ? $_POST['p_precio'] : [];   // precio por CADA perfil
      $pNombre = is_array($_POST['p_nombre'] ?? null) ? $_POST['p_nombre'] : [];
      $pPin    = is_array($_POST['p_pin'] ?? null) ? $_POST['p_pin'] : [];
      $vencIn = trim((string) ($_POST['vencimiento'] ?? ''));
      if ($destino === 'revendedor') { if (!$revId) throw new Exception('Elige a qué revendedor.'); $cliNombre = ''; $cliWa = ''; }
      else { if ($cliNombre === '') throw new Exception('Escribe el nombre del cliente.'); $revId = null; }
      // SELECCIÓN POR PERFIL: solo se venden los perfiles marcados (p_sel). Regla: si de una cuenta se
      // marcan TODOS sus perfiles libres = 1 línea (cuenta completa, tipo='cuenta'); si ALGUNOS = 1 línea
      // por perfil. Así "todos = una cuenta / menos = por perfil", con nombre/PIN por cada uno.
      $pSel = is_array($_POST['p_sel'] ?? null) ? array_map('intval', array_keys($_POST['p_sel'])) : [];
      if (!$pSel) throw new Exception('No marcaste ningún perfil para vender.');
      $in = implode(',', $pSel);
      $rows = $pdo->query("SELECT p.id, p.etiqueta, p.pin, c.id AS cuenta_id, c.plataforma, c.correo, c.clave, c.vencimiento, c.plataforma_id, c.precio_venta, c.precio_reventa
                           FROM streaming_perfiles p JOIN streaming_cuentas c ON c.id=p.cuenta_id
                           WHERE p.id IN ($in) AND c.owner_id=$OWNER AND p.estado='libre' FOR UPDATE")->fetchAll(PDO::FETCH_ASSOC);
      if (!$rows) throw new Exception('Los perfiles marcados ya no están libres.');
      $vencDe = function ($cuentaVenc, $platId) use ($pdo, $vencIn) {
        if ($vencIn !== '' && strtotime($vencIn)) return date('Y-m-d', strtotime($vencIn));
        if ($cuentaVenc && strtotime((string) $cuentaVenc)) return (string) $cuentaVenc;
        $dd = (int) ($pdo->query("SELECT COALESCE(dias_default,30) FROM streaming_plataformas WHERE id=" . (int) $platId)->fetchColumn() ?: 30);
        return date('Y-m-d', strtotime("+$dd days"));
      };
      $insCC = $pdo->prepare("INSERT INTO streaming_ventas (owner_id,plataforma,tipo,cuenta_id,cliente_nombre,cliente_wa,correo,clave,perfil,pin,precio,precio_renovacion,fecha_inicio,fecha_vencimiento,creado_por,revendedor_id,entregada) VALUES (?,?,'cuenta',?,?,?,?,?,?,?,?,?,CURDATE(),?,?,?,1)");
      $insP  = $pdo->prepare("INSERT INTO streaming_ventas (owner_id,plataforma,tipo,cuenta_id,cliente_nombre,cliente_wa,correo,clave,perfil,pin,precio,precio_renovacion,fecha_inicio,fecha_vencimiento,creado_por,revendedor_id,entregada) VALUES (?,?,'perfil',?,?,?,?,?,?,?,?,?,CURDATE(),?,?,?,1)");
      $upP = $pdo->prepare("UPDATE streaming_perfiles SET estado='vendido', venta_id=? WHERE id=? AND estado='libre'");
      $vendidas = 0; $vend = 0;
      $pdo->beginTransaction();
      try {
        $byCuenta = [];
        foreach ($rows as $r) { $byCuenta[(int) $r['cuenta_id']][] = $r; }
        foreach ($byCuenta as $cidG => $grp) {
          $totalLibres = (int) ($pdo->query("SELECT COUNT(*) FROM streaming_perfiles WHERE cuenta_id=$cidG AND estado='libre'")->fetchColumn() ?: 0);
          $p0 = $grp[0];
          $venc = $vencDe($p0['vencimiento'], $p0['plataforma_id']);
          $precioBase = ($destino === 'revendedor') ? $p0['precio_reventa'] : $p0['precio_venta'];
          $precioBase = ($precioBase !== null && $precioBase !== '') ? (float) $precioBase : null;
          if ($totalLibres > 1 && count($grp) >= $totalLibres) {
            // TODOS → CUENTA COMPLETA (1 línea)
            $precioG = $precio !== null ? $precio : $precioBase;
            $nombres = implode(', ', array_map(static function ($x) use ($pNombre) { $pid = (int) $x['id']; return trim((string) ($pNombre[$pid] ?? '')) ?: (string) ($x['etiqueta'] ?? ''); }, $grp));
            $insCC->execute([$OWNER, $p0['plataforma'], $cidG, $cliNombre ?: null, $cliWa ?: null, $p0['correo'], $p0['clave'], $nombres, (string) ($p0['pin'] ?? ''), $precioG, $precioG, $venc, current_user_id(), $revId]);
            $vid = (int) $pdo->lastInsertId();
            foreach ($grp as $p) {
              $pid = (int) $p['id'];
              $etq = trim((string) ($pNombre[$pid] ?? '')); $pin = trim((string) ($pPin[$pid] ?? ''));
              if ($etq !== '' || $pin !== '') { try { $pdo->prepare("UPDATE streaming_perfiles SET etiqueta=COALESCE(NULLIF(?,''),etiqueta), pin=COALESCE(NULLIF(?,''),pin) WHERE id=?")->execute([$etq, $pin, $pid]); } catch (Throwable $e) {} }
              $upP->execute([$vid, $pid]);
            }
            if ($revId && function_exists('st_rev_entregar')) { try { st_rev_entregar($pdo, (int) $revId, $cidG, $grp, (float) ($precioG ?? 0), true, $venc); } catch (Throwable $e) {} }
            $vendidas++;
          } else {
            // ALGUNOS → una línea POR PERFIL
            foreach ($grp as $p) {
              $pid = (int) $p['id'];
              $etq = trim((string) ($pNombre[$pid] ?? '')) ?: (string) ($p['etiqueta'] ?? '');
              $pin = trim((string) ($pPin[$pid] ?? '')) ?: (string) ($p['pin'] ?? '');
              $rowPrecio = (array_key_exists($pid, $pPrecio) && $pPrecio[$pid] !== '' && $pPrecio[$pid] !== null) ? (float) str_replace(',', '.', (string) $pPrecio[$pid]) : ($precio !== null ? $precio : $precioBase);
              $insP->execute([$OWNER, $p['plataforma'], $cidG, $cliNombre ?: null, $cliWa ?: null, $p['correo'], $p['clave'], $etq, $pin, $rowPrecio, $rowPrecio, $venc, current_user_id(), $revId]);
              $vid = (int) $pdo->lastInsertId();
              try { $pdo->prepare("UPDATE streaming_perfiles SET etiqueta=?, pin=? WHERE id=?")->execute([$etq, $pin ?: null, $pid]); } catch (Throwable $e) {}
              $upP->execute([$vid, $pid]);
              if ($revId && function_exists('st_rev_entregar')) { try { st_rev_entregar($pdo, (int) $revId, $cidG, [['id' => $pid, 'etiqueta' => $etq, 'pin' => $pin]], (float) ($rowPrecio ?? 0), false, $venc); } catch (Throwable $e) {} }
              $vend++;
            }
          }
        }
        $pdo->commit();
      } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
      // BOT de códigos (tras el commit): asigna UNA vez cada correo nuevo que le quedó al revendedor.
      if ($revId && function_exists('bot_codigos_flush')) { try { bot_codigos_flush($pdo, (int) $revId); } catch (Throwable $e) {} }
      $msg = "✓ Vendido: $vendidas cuenta(s) completa(s)" . ($vend > 0 ? " + $vend perfil(es)" : '') . '. Míralas en Ventas.';
    }
    elseif ($a === 'cuentas_masivo') {
      // Carga masiva CENTRADA EN PERFILES: cada línea es UN perfil (con su nombre y PIN).
      // Las líneas con el MISMO correo (y plataforma) se agrupan en UNA sola cuenta (no una por línea).
      // Formato: Plataforma, Correo, Clave, NombrePerfil, PIN, Vence(AAAA-MM-DD o DD/MM/AAAA), Costo(opcional)
      $pmap = [];
      try { foreach ($pdo->query("SELECT id, nombre FROM streaming_plataformas WHERE owner_id=$OWNER") as $r) $pmap[mb_strtolower(trim($r['nombre']))] = (int) $r['id']; } catch (Throwable $e) {}
      // Vendedor opcional (8ª columna): mapea nombre / usuario / email → id de revendedor. Solo el dueño (owner 0) asigna.
      $revMap = [];
      if ((int) $OWNER === 0) { try { foreach ($pdo->query("SELECT id, nombre, username, email FROM usuarios WHERE rol='revendedor'") as $r) { foreach ([$r['nombre'], $r['username'], $r['email']] as $kk) { $kk = mb_strtolower(trim((string) $kk)); if ($kk !== '') $revMap[$kk] = (int) $r['id']; } } } catch (Throwable $e) {} }
      $insPlat   = $pdo->prepare("INSERT INTO streaming_plataformas (owner_id, nombre, dias_default, activo) VALUES ($OWNER,?,30,1)");
      $insCuenta = $pdo->prepare("INSERT INTO streaming_cuentas (owner_id,plataforma,plataforma_id,correo,clave,perfiles_total,vencimiento,costo) VALUES ($OWNER,?,?,?,?,0,?,?)");
      $insPerf   = $pdo->prepare("INSERT INTO streaming_perfiles (cuenta_id,etiqueta,pin) VALUES (?,?,?)");
      // Si la línea trae vendedor → se crea la venta a ese revendedor y el perfil queda VENDIDO (no stock).
      $insVentaMasiva = $pdo->prepare("INSERT INTO streaming_ventas (owner_id,plataforma,tipo,cuenta_id,correo,clave,perfil,pin,fecha_inicio,fecha_vencimiento,creado_por,revendedor_id,entregada) VALUES ($OWNER,?,'perfil',?,?,?,?,?,CURDATE(),?,?,?,1)");
      $upVendMasivo   = $pdo->prepare("UPDATE streaming_perfiles SET estado='vendido', venta_id=? WHERE id=?");
      $vendMasivo = 0;
      // Nota: los valores finales se calculan en PHP (abajo), NO con COALESCE(NULLIF(?,''),col):
      // ese patrón mezcla la collation del parámetro con la de la columna y revienta en servidores
      // cuya conexión es utf8mb4_general_ci mientras las tablas son utf8mb4_unicode_ci (error 1267).
      $updCuenta = $pdo->prepare("UPDATE streaming_cuentas SET perfiles_total=?, clave=?, vencimiento=?, costo=? WHERE id=?");
      // Fecha flexible: acepta AAAA-MM-DD y DD/MM/AAAA (evita que 1/8/2026 se lea como mes/día al estilo EEUU).
      $parseFecha = static function ($s) {
        $s = trim((string) $s); if ($s === '') return null;
        if (preg_match('#^(\d{4})-(\d{1,2})-(\d{1,2})$#', $s, $m)) return sprintf('%04d-%02d-%02d', (int) $m[1], (int) $m[2], (int) $m[3]);
        if (preg_match('#^(\d{1,2})[/\-.](\d{1,2})[/\-.](\d{4})$#', $s, $m)) return sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);
        $t = strtotime($s); return $t ? date('Y-m-d', $t) : null;
      };
      $cuentas = []; // gkey => ['cid'=>int,'nperf'=>int,'clave'=>string,'venc'=>?string]
      $nPerf = 0;
      foreach (preg_split('/\r?\n/', (string) ($_POST['datos'] ?? '')) as $ln) {
        $ln = trim($ln); if ($ln === '') continue;
        $p = preg_split('/\s*[,;\t|]\s*/', $ln);
        $platNom = trim($p[0] ?? ''); if ($platNom === '') continue;
        $correo  = trim($p[1] ?? '');
        $clave   = trim($p[2] ?? '');
        $nombreP = ltrim(trim($p[3] ?? ''), '#');   // por si escriben "#nombre" (herencia del antiguo "#Perfiles")
        $pin     = trim($p[4] ?? '');
        $venc    = $parseFecha($p[5] ?? '');
        $costoRaw = str_replace(',', '.', trim((string) ($p[6] ?? '')));   // costo opcional (7ª columna): acepta coma o punto
        $costo    = ($costoRaw !== '' && is_numeric($costoRaw)) ? (float) $costoRaw : null;
        $vendedor = mb_strtolower(trim((string) ($p[7] ?? '')));   // vendedor opcional (8ª columna)

        $k = mb_strtolower($platNom); $pid = $pmap[$k] ?? null;
        if ($pid === null) { $insPlat->execute([$platNom]); $pid = (int) $pdo->lastInsertId(); $pmap[$k] = $pid; }

        // Agrupar por plataforma + correo → misma cuenta. Sin correo, cada línea es su propia cuenta.
        $gkey = $k . '|' . mb_strtolower($correo);
        if ($correo === '') $gkey .= '|solo' . $nPerf;
        if (!isset($cuentas[$gkey])) {
          $insCuenta->execute([$platNom, $pid, $correo ?: null, $clave ?: null, $venc, $costo]);
          $cuentas[$gkey] = ['cid' => (int) $pdo->lastInsertId(), 'nperf' => 0, 'clave' => $clave, 'venc' => $venc, 'costo' => $costo];
        }
        $cuentas[$gkey]['nperf']++;
        if ($cuentas[$gkey]['clave'] === '' && $clave !== '') $cuentas[$gkey]['clave'] = $clave;
        if ($cuentas[$gkey]['venc'] === null && $venc !== null) $cuentas[$gkey]['venc'] = $venc;
        if ($cuentas[$gkey]['costo'] === null && $costo !== null) $cuentas[$gkey]['costo'] = $costo;
        // El NOMBRE del perfil (nombre del cliente) se guarda como etiqueta; si viene vacío, P1..Pn.
        $etiqueta = $nombreP !== '' ? mb_substr($nombreP, 0, 60) : ('P' . $cuentas[$gkey]['nperf']);
        $insPerf->execute([$cuentas[$gkey]['cid'], $etiqueta, $pin !== '' ? $pin : null]);
        $newPid = (int) $pdo->lastInsertId();
        // Si la línea trae un vendedor válido → se sube ya VENDIDA a ese revendedor; si no, queda stock.
        if ($vendedor !== '' && isset($revMap[$vendedor])) {
          $sVenc = $venc ?: ($cuentas[$gkey]['venc'] ?: date('Y-m-d', strtotime('+30 days')));
          $insVentaMasiva->execute([$platNom, $cuentas[$gkey]['cid'], $correo ?: null, $clave ?: null, $etiqueta, $pin !== '' ? $pin : null, $sVenc, current_user_id(), $revMap[$vendedor]]);
          $upVendMasivo->execute([(int) $pdo->lastInsertId(), $newPid]);
          $vendMasivo++;
        }
        if (++$nPerf >= 3000) break;
      }
      // Ajusta el total de perfiles + clave/vencimiento/costo de cada cuenta según lo importado.
      foreach ($cuentas as $c) $updCuenta->execute([$c['nperf'], ($c['clave'] !== '' ? $c['clave'] : null), $c['venc'], $c['costo'], $c['cid']]);
      $nCta = count($cuentas);
      $msg = "✓ $nPerf perfil(es) importados en $nCta cuenta(s)" . ($vendMasivo > 0 ? " · $vendMasivo ya vendido(s) a su revendedor" : '') . '.';
    }
  } catch (Throwable $e) { $msg = '⚠ ' . $e->getMessage(); }
  header('Location: cuentas.php?msg=' . urlencode($msg));
  exit;
}
$flash = (string) ($_GET['msg'] ?? '');

// ANTES de listar: limpia cuentas FANTASMA (espejo cuyo origen del admin ya no existe). Va aquí (no
// después) para que la tabla de ESTA carga ya salga limpia — antes se barría después de la query y el
// fantasma se veía una vez más. Aislada, corre siempre.
try { if (function_exists('st_rev_limpiar_fantasmas')) { $nf = st_rev_limpiar_fantasmas($pdo); if ($nf > 0 && $flash === '') $flash = "✓ Se quitaron $nf cuenta(s) que la tienda eliminó."; } } catch (Throwable $e) {}

// ── Datos para la tabla + modales ──
$err = '';
$cuentas = [];
try {
  $cuentas = $pdo->query(
    "SELECT c.id, c.plataforma, c.correo, c.clave, c.costo, c.precio_venta, c.precio_reventa, c.vencimiento, c.notas, COALESCE(c.origen_cuenta_id,0) AS origen_cuenta_id,
            pr.nombre AS prov_nombre, pl.logo_url AS logo, pl.color AS color,
            (SELECT COUNT(*) FROM streaming_perfiles p WHERE p.cuenta_id=c.id AND p.estado='libre') AS libres,
            (SELECT COUNT(*) FROM streaming_perfiles p WHERE p.cuenta_id=c.id) AS perf
     FROM streaming_cuentas c
     LEFT JOIN streaming_proveedores pr ON pr.id = c.proveedor_id AND pr.owner_id = c.owner_id
     LEFT JOIN streaming_plataformas pl ON pl.id = c.plataforma_id AND pl.owner_id = c.owner_id
     WHERE c.owner_id = $OWNER
     ORDER BY (c.vencimiento IS NULL), c.vencimiento ASC, c.id DESC"
  )->fetchAll(PDO::FETCH_ASSOC);
  // Un empleado (no dueño) NO debe ver el proveedor: lo vaciamos aquí para que no se filtre por
  // los atributos data-b / data-prov del HTML (el <td> visible ya está gateado, pero los data- no).
  if (!$verCostos) { foreach ($cuentas as &$c) { $c['prov_nombre'] = ''; } unset($c); }
} catch (Throwable $e) { $err = $e->getMessage(); }

$tipos = $pdo->query("SELECT id, nombre FROM streaming_plataformas WHERE owner_id=$OWNER AND activo=1 ORDER BY orden, nombre")->fetchAll(PDO::FETCH_ASSOC);
$proveedores = $verCostos ? $pdo->query("SELECT id, nombre FROM streaming_proveedores WHERE owner_id=$OWNER AND activo=1 ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC) : [];
// Limpia cuentas espejo FANTASMA (su cuenta origen ya no existe) y stock vencido sin renovar.
try { if (function_exists('st_rev_sweep_vencidos')) st_rev_sweep_vencidos($pdo); } catch (Throwable $e) {}

// Revendedores (para "Vender varias" → asignar la venta a un revendedor). Solo tiene sentido para el dueño.
$revList = [];
if ((int) $OWNER === 0) { try { $revList = $pdo->query("SELECT id, nombre FROM usuarios WHERE rol='revendedor' ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) { $revList = []; } }

// Perfiles LIBRES por cuenta → "Vender varias" con SELECCIÓN por perfil (marcar cuáles, nombre/PIN).
// Regla: si marcas TODOS los perfiles libres de una cuenta = 1 línea (cuenta completa); si marcas
// ALGUNOS = una línea por perfil. (Igual que en la pestaña Perfiles.)
$perfMapCta = [];
try {
  foreach ($pdo->query("SELECT p.cuenta_id, p.id, p.etiqueta, p.pin FROM streaming_perfiles p JOIN streaming_cuentas c ON c.id=p.cuenta_id WHERE c.owner_id=$OWNER AND p.estado='libre' ORDER BY p.cuenta_id, p.id") as $r) {
    $perfMapCta[(int) $r['cuenta_id']][] = ['id' => (int) $r['id'], 'etq' => (string) ($r['etiqueta'] ?? ''), 'pin' => (string) ($r['pin'] ?? '')];
  }
} catch (Throwable $e) {}

// Logos por nombre normalizado: arregla cuentas cuyo plataforma_id no quedó vinculado al tipo
// (p.ej. PrimeVideo/Paramount importados de PAC: el nombre coincide aunque el id no). Así el
// inventario y la tabla muestran el logo del catálogo aunque el join directo venga vacío.
$logoMap = [];
try {
  foreach ($pdo->query("SELECT nombre, logo_url, color FROM streaming_plataformas WHERE owner_id=$OWNER AND logo_url IS NOT NULL AND logo_url <> ''") as $t) {
    $k = preg_replace('/[^a-z0-9]/', '', mb_strtolower((string) $t['nombre']));
    if ($k !== '' && !isset($logoMap[$k])) $logoMap[$k] = ['logo' => $t['logo_url'], 'color' => $t['color']];
  }
} catch (Throwable $e) {}
foreach ($cuentas as &$c) {
  if (empty($c['logo'])) {
    $k = preg_replace('/[^a-z0-9]/', '', mb_strtolower((string) ($c['plataforma'] ?? '')));
    if ($k !== '' && isset($logoMap[$k])) { $c['logo'] = $logoMap[$k]['logo']; if (empty($c['color'])) $c['color'] = $logoMap[$k]['color']; }
  }
}
unset($c);

$kTotal = count($cuentas); $kVencen = 0; $kVencidas = 0; $perfLibres = 0; $perfTotal = 0;
$inv = [];
foreach ($cuentas as &$c) {
  $d = dias_de($c['vencimiento'] ?? null, $hoy);
  $c['_dias'] = $d;
  $perfLibres += (int) $c['libres']; $perfTotal += (int) $c['perf'];
  if ($d !== null && $d < 0) $kVencidas++;
  elseif ($d !== null && $d <= 5) $kVencen++;
  $pn = trim((string) ($c['plataforma'] ?? '')) ?: 'Otros';
  if (!isset($inv[$pn])) $inv[$pn] = ['cuentas' => 0, 'disp' => 0, 'exp' => 0, 'logo' => $c['logo'], 'color' => $c['color'] ?: '#2DE2D5'];
  $inv[$pn]['cuentas']++; $inv[$pn]['disp'] += (int) $c['libres'];
  if ($d !== null && $d <= 5) $inv[$pn]['exp']++;
}
unset($c);
uasort($inv, fn($a, $b) => $b['cuentas'] <=> $a['cuentas']);
$provNames = [];
foreach ($cuentas as $cc) { $pn = trim((string) ($cc['prov_nombre'] ?? '')); if ($pn !== '' && !in_array($pn, $provNames, true)) $provNames[] = $pn; }
sort($provNames);

$kpis = [
  ['Total Cuentas', $kTotal, 'Activas', 'monitor', 'acc'],
  ['Próximas a Vencer', $kVencen, 'En los próximos 5 días', 'clock', ''],
  ['Perfiles Disponibles', $perfLibres, "De $perfTotal totales", 'users', ''],
  ['Plataformas Vencidas', $kVencidas, 'Requieren renovación', 'alert-triangle', 'flag'],
];
$csrf = csrf_token();

stream_head('Cuentas', 'cuentas');
?>

<style>
  .sflash{display:flex;align-items:center;gap:8px;border-radius:11px;padding:11px 15px;font-size:13px;margin-bottom:16px}
  .sflash [data-lucide]{width:17px;height:17px;flex:0 0 auto}
  .sflash.ok{background:var(--good-soft);border:1px solid color-mix(in srgb,var(--good) 30%,transparent);color:var(--good)}
  .sflash.bad{background:var(--bad-soft);border:1px solid color-mix(in srgb,var(--bad) 30%,transparent);color:var(--bad)}
  .flbl{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:var(--muted);display:block;margin-bottom:5px}
  .invcard{display:flex;align-items:center;gap:12px;border:1px solid var(--border);background:var(--surface-2);border-radius:12px;padding:12px}
  .invcard.hot{border-color:color-mix(in srgb,var(--bad) 45%,var(--border))}
  .invlogo{width:40px;height:40px;border-radius:9px;object-fit:contain;background:var(--surface);border:1px solid var(--border)}
  .invmono{width:40px;height:40px;border-radius:9px;display:grid;place-items:center;color:#fff;font-weight:800;font-size:14px}
  .cellmono{width:32px;height:32px;border-radius:8px;display:grid;place-items:center;color:#fff;font-weight:700;font-size:12px}
  .celllogo{width:32px;height:32px;border-radius:8px;object-fit:contain;background:var(--surface-2);border:1px solid var(--border)}
  .iconact{width:32px;height:32px;display:grid;place-items:center;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--muted);cursor:pointer;transition:.12s}
  .iconact [data-lucide]{width:16px;height:16px}
  .iconact:hover{border-color:var(--border-strong)}
  .iconact.v{color:var(--accent)}.iconact.r{color:var(--good)}.iconact.e{color:var(--warn)}.iconact.d{color:var(--bad)}
  .modal{background:var(--surface);border:1px solid var(--border);border-radius:14px;box-shadow:var(--shadow);overflow:hidden}
  .modal-hd{display:flex;align-items:center;gap:10px;padding:14px 18px;border-bottom:1px solid var(--border)}
  .modal-hd h3{margin:0;flex:1;display:flex;align-items:center;gap:8px;font-size:15px;font-weight:720;color:var(--text)}
  .modal-hd h3 [data-lucide]{width:18px;height:18px;color:var(--accent)}
  .modal-x{width:32px;height:32px;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--muted);display:grid;place-items:center;cursor:pointer}
  .modal-x:hover{border-color:var(--border-strong);color:var(--text)}
  .softbox{background:var(--surface-2);border:1px solid var(--border);border-radius:12px}
  .caret{display:inline-flex;align-items:center;justify-content:center;padding:6px 11px;border-radius:8px;background:var(--surface-2);border:1px solid var(--border);color:var(--muted);font-weight:700;cursor:pointer}
</style>

<div class="pagehd">
  <div>
    <h1>Cuentas de <span class="nm">streaming</span></h1>
    <p>Inventario de plataformas, perfiles disponibles y renovaciones.</p>
  </div>
  <div style="display:flex;gap:10px;align-items:center">
    <button onclick="abrirNueva()" class="btn primary"><i data-lucide="plus"></i> Nueva Plataforma</button>
  </div>
</div>

<?php if ($flash): ?>
  <?php // El flash NO siempre es un éxito: los handlers devuelven "⚠ ..." cuando NO hicieron nada
        // (ej. "No borré nada: hay 12 ventas activas"). Pintarlo verde con ✓ hacía que se leyera
        // como éxito y repitieras la operación. Ahora se detecta y se pinta como advertencia. ?>
  <?php $flashMal = (mb_substr(trim((string) $flash), 0, 1) === '⚠'); ?>
  <div class="sflash <?= $flashMal ? 'bad' : 'ok' ?>"><i data-lucide="<?= $flashMal ? 'alert-triangle' : 'check-circle' ?>"></i><?= h($flash) ?></div>
<?php endif; ?>
<?php if ($err): ?>
  <div class="sflash bad"><i data-lucide="alert-triangle"></i>No pude cargar el inventario: <?= h($err) ?>.</div>
<?php endif; ?>

<!-- KPIs -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-3 md:gap-4" style="margin-bottom:16px">
  <?php foreach ($kpis as [$lbl, $val, $sub, $icon, $cls]): ?>
    <div class="kpi <?= $cls ?>">
      <div class="k-top"><div class="k-ic"><i data-lucide="<?= h($icon) ?>"></i></div> <?= h($lbl) ?></div>
      <h3 class="tnum"><?= (int) $val ?></h3>
      <div class="k-sub"><?= h($sub) ?></div>
    </div>
  <?php endforeach; ?>
</div>

<!-- Inventario detallado -->
<div class="card" style="margin-bottom:16px">
  <button type="button" onclick="document.getElementById('inv-body').classList.toggle('hidden');this.querySelector('i.chev').classList.toggle('rotate-180')" class="card-hd" style="width:100%;text-align:left;background:none;cursor:pointer;font:inherit;color:inherit">
    <i data-lucide="boxes"></i>
    <h2>Inventario detallado</h2>
    <span class="pill-count"><?= count($inv) ?> servicios</span>
    <i data-lucide="chevron-up" class="chev" style="margin-left:auto;width:16px;height:16px;color:var(--faint);transition:.15s"></i>
  </button>
  <div id="inv-body" class="card-bd">
    <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-4 gap-3">
      <?php foreach ($inv as $nombre => $d): $alert = $d['exp'] > 0; ?>
        <div class="invcard <?= $alert ? 'hot' : '' ?>">
          <?php if (!empty($d['logo'])): ?><img src="<?= h($d['logo']) ?>" class="invlogo" alt="">
          <?php else: ?><div class="invmono" style="background:<?= h($d['color']) ?>"><?= h(mb_strtoupper(mb_substr($nombre, 0, 1))) ?></div><?php endif; ?>
          <div class="min-w-0 flex-1"><div class="font-bold text-sm truncate" style="color:var(--text)"><?= h($nombre) ?></div>
            <div style="font-size:11px;color:var(--faint)"><?= (int) $d['cuentas'] ?> cuenta(s)<?= $alert ? ' · por vencer' : '' ?></div></div>
          <div class="text-right"><div style="font-size:18px;font-weight:800;color:<?= $d['disp'] > 0 ? 'var(--good)' : 'var(--faint)' ?>"><?= (int) $d['disp'] ?></div><div style="font-size:10px;color:var(--faint);margin-top:-2px">Disp.</div></div>
        </div>
      <?php endforeach; ?>
      <?php if (!$inv): ?><div class="empty">Sin cuentas en inventario todavía.</div><?php endif; ?>
    </div>
  </div>
</div>

<!-- Barra de acciones -->
<div class="flex flex-wrap items-center gap-2" style="margin-bottom:16px">
  <button onclick="abrirNueva()" class="btn primary"><i data-lucide="monitor"></i> Nueva Plataforma</button>
  <button onclick="abrir('m-masivo')" class="btn ghost"><i data-lucide="upload-cloud"></i> Carga Masiva</button>
  <button onclick="abrirCalendario()" class="btn ghost"><i data-lucide="calendar"></i> Ver calendario</button>
  <span class="ml-auto"></span>
  <?php if (!$esRev || !function_exists('st_rev_puede_exportar') || st_rev_puede_exportar($pdo)): ?>
  <a href="?export=csv" class="btn ghost"><i data-lucide="file-spreadsheet"></i> Excel</a>
  <a href="?export=plantilla" class="btn ghost"><i data-lucide="file-down"></i> Descargar Plantilla</a>
  <?php endif; ?>
</div>

<!-- Filtros Avanzados -->
<div class="card" style="margin-bottom:16px">
  <div class="card-hd">
    <i data-lucide="sliders-horizontal"></i><h2>Filtros avanzados</h2>
    <button onclick="resetFiltros()" class="btn ghost" style="margin-left:auto;padding:6px 11px;font-size:11.5px"><i data-lucide="rotate-ccw"></i> Resetear</button>
  </div>
  <div class="card-bd">
    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-3">
      <div><label class="flbl">Búsqueda</label><input id="f-busqueda" oninput="filtrar()" placeholder="Nombre, correo…" class="input"></div>
      <div><label class="flbl">Plataforma</label><select id="f-plat" onchange="filtrar()" class="input"><option value="">Todas</option><?php foreach (array_keys($inv) as $pn): ?><option value="<?= h(mb_strtolower($pn)) ?>"><?= h($pn) ?></option><?php endforeach; ?></select></div>
      <div><label class="flbl">Estado</label><select id="f-estado" onchange="filtrar()" class="input"><option value="">Todos</option><option value="vencida">Vencidas</option><option value="porvencer">Por vencer (≤5d)</option><option value="vigente">Vigentes</option></select></div>
      <div><label class="flbl">Stock</label><select id="f-stock" onchange="filtrar()" class="input"><option value="">Todo</option><option value="con">Con stock</option><option value="sin">Sin stock</option></select></div>
      <?php if ($verCostos): ?><div><label class="flbl">Proveedor</label><select id="f-prov" onchange="filtrar()" class="input"><option value="">Todos</option><?php foreach ($provNames as $pn): ?><option value="<?= h(mb_strtolower($pn)) ?>"><?= h($pn) ?></option><?php endforeach; ?></select></div><?php endif; ?>
      <div><label class="flbl">Ordenar por</label><select id="f-orden" onchange="ordenar()" class="input"><option value="">— Por defecto —</option><option value="dias-asc">Vence primero</option><option value="dias-desc">Vence último</option><option value="plat-asc">Plataforma A→Z</option><option value="plat-desc">Plataforma Z→A</option></select></div>
    </div>
  </div>
</div>

<!-- Tabla -->
<div class="card">
  <div class="card-hd">
    <i data-lucide="list"></i><h2>Cuentas</h2><span class="pill-count"><?= $kTotal ?></span>
    <button type="button" onclick="selTodos()" class="btn ghost" style="margin-left:auto;padding:6px 11px;font-size:12px"><i data-lucide="check-square" style="width:14px;height:14px"></i> Seleccionar todos</button>
    <input id="buscar" type="text" placeholder="Buscar rápido…" class="input" style="max-width:240px">
  </div>
  <div class="overflow-x-auto thin">
    <!-- Barra de acciones masivas: aparece al marcar. Solo opera sobre filas VISIBLES. -->
    <div id="bulkbar" style="display:none;position:sticky;top:0;z-index:5;margin-bottom:10px;padding:10px 13px;border-radius:11px;background:rgba(45,226,213,.09);border:1px solid rgba(45,226,213,.35);display:none;align-items:center;gap:10px;flex-wrap:wrap">
      <b style="color:var(--acc)"><span id="bulk-n">0</span> seleccionada(s)</b>
      <button type="button" onclick="bulkVender()" class="btn ghost" style="padding:5px 12px;font-size:12.5px;color:var(--good);border-color:rgba(34,197,94,.4)"><i data-lucide="shopping-cart" style="width:14px;height:14px"></i> Vender varias</button>
      <button type="button" onclick="bulkRenovar()" class="btn ghost" style="padding:5px 12px;font-size:12.5px"><i data-lucide="calendar-check" style="width:14px;height:14px"></i> Renovar varias</button>
      <?php if ($verCostos): ?>
      <button type="button" onclick="bulkProveedor()" class="btn ghost" style="padding:5px 12px;font-size:12.5px"><i data-lucide="truck" style="width:14px;height:14px"></i> Asignar proveedor</button>
      <?php endif; ?>
      <button type="button" onclick="bulkCosto()" class="btn ghost" style="padding:5px 12px;font-size:12.5px"><i data-lucide="dollar-sign" style="width:14px;height:14px"></i> Cambiar precios</button>
      <button type="button" onclick="bulkEliminar()" class="btn ghost" style="padding:5px 12px;font-size:12.5px;color:var(--err);border-color:rgba(239,68,68,.4)"><i data-lucide="trash-2" style="width:14px;height:14px"></i> Eliminar varias</button>
      <button type="button" onclick="document.querySelectorAll('.ck-row').forEach(c=>c.checked=false);ckSync()" class="btn ghost" style="padding:5px 12px;font-size:12.5px;margin-left:auto">Quitar selección</button>
    </div>
    <table class="dtable">
      <thead><tr>
        <th style="width:34px;position:sticky;left:0;background:var(--surface);z-index:2"><input type="checkbox" id="ck-all" onclick="ckTodo(this)" title="Seleccionar los visibles" style="width:15px;height:15px;accent-color:var(--acc)"></th>
        <th>Plataforma</th><th>Credenciales</th>
        <th>Inventario</th><?php if ($verCostos): ?><th>Proveedor</th><?php endif; ?>
        <th>Observaciones</th><th>Estado / Días faltantes</th><th style="text-align:center">Acciones</th>
      </tr></thead>
      <tbody id="tbody">
      <?php foreach ($cuentas as $c):
        $d = $c['_dias'];
        if ($d === null) { $pill = 'tag'; $txt = 'Sin fecha'; }
        elseif ($d < 0)  { $pill = 'pill err'; $txt = 'Vencido (' . abs($d) . 'd)'; }
        elseif ($d <= 5) { $pill = 'pill wait'; $txt = 'En ' . $d . 'd'; }
        else             { $pill = 'pill ok'; $txt = $d . ' días'; }
        // Estado de VENTA de la cuenta según sus perfiles: Libre (todos libres) / Venta parcial (algunos
        // vendidos) / Vendida (todos vendidos). Es distinto del vencimiento (los días).
        $totalP = (int) $c['perf']; $libresP = (int) $c['libres'];
        if ($totalP <= 0)            { $vEstado = 'Sin perfiles'; $vColor = 'var(--faint)'; $vBg = 'var(--surface-2)'; }
        elseif ($libresP >= $totalP) { $vEstado = 'Libre';        $vColor = 'var(--good)';  $vBg = 'var(--good-soft)'; }
        elseif ($libresP <= 0)       { $vEstado = 'Vendida';      $vColor = 'var(--bad)';   $vBg = 'var(--bad-soft)'; }
        else                         { $vEstado = 'Venta parcial'; $vColor = 'var(--warn)'; $vBg = 'rgba(245,166,35,.12)'; }
        $busca = mb_strtolower(($c['plataforma'] ?? '') . ' ' . ($c['correo'] ?? '') . ' ' . ($c['prov_nombre'] ?? ''));
        $fEstado = ($d === null) ? 'sinfecha' : ($d < 0 ? 'vencida' : ($d <= 5 ? 'porvencer' : 'vigente'));
        $fStock = (int) $c['libres'] > 0 ? 'con' : 'sin';
      ?>
        <tr data-b="<?= h($busca) ?>" data-plat="<?= h(mb_strtolower($c['plataforma'] ?? '')) ?>" data-estado="<?= $fEstado ?>" data-stock="<?= $fStock ?>" data-prov="<?= h(mb_strtolower($c['prov_nombre'] ?? '')) ?>" data-dias="<?= $d === null ? 999999 : (int) $d ?>">
          <td style="position:sticky;left:0;background:var(--surface);z-index:1"><input type="checkbox" class="ck-row" value="<?= (int) $c['id'] ?>" data-lbl="<?= h(trim(($c['plataforma'] ?? '') . ' · ' . ($c['correo'] ?? ''), ' ·')) ?>" onclick="event.stopPropagation();ckSync()" style="width:15px;height:15px;accent-color:var(--acc)"></td>
          <td><div class="flex items-center gap-2.5">
            <?php if (!empty($c['logo'])): ?><img src="<?= h($c['logo']) ?>" class="celllogo" alt="">
            <?php else: ?><div class="cellmono" style="background:<?= h($c['color'] ?: '#3f4fb5') ?>"><?= h(mb_strtoupper(mb_substr($c['plataforma'] ?? '?', 0, 1))) ?></div><?php endif; ?>
            <div class="font-bold" style="color:var(--text)"><?= h($c['plataforma']) ?></div></div></td>
          <td><div class="flex items-center gap-1.5" style="color:var(--muted)"><i data-lucide="mail" style="width:14px;height:14px;color:var(--faint)"></i><?= h($c['correo'] ?: '—') ?></div>
            <?php if (!empty($c['clave'])): ?><div class="flex items-center gap-1.5" style="color:var(--faint);font-size:11px;margin-top:2px"><i data-lucide="key" style="width:12px;height:12px"></i><?= h($c['clave']) ?></div><?php endif; ?></td>
          <td><div class="font-semibold" style="color:<?= (int) $c['libres'] > 0 ? 'var(--good)' : 'var(--bad)' ?>"><?= (int) $c['libres'] ?> / <?= (int) $c['perf'] ?></div>
            <?php if ($verCostos && $c['costo'] !== null): ?><div style="font-size:11px;color:var(--faint)">costo $<?= number_format((float) $c['costo'], 2) ?></div><?php endif; ?>
            <?php if ($c['precio_venta'] !== null && (float) $c['precio_venta'] > 0): ?><div style="font-size:11px;color:var(--good);font-weight:600">venta $<?= number_format((float) $c['precio_venta'], 2) ?></div><?php endif; ?></td>
          <?php if ($verCostos): ?><td><?= $c['prov_nombre'] ? h($c['prov_nombre']) : '<span style="color:var(--faint)">—</span>' ?></td><?php endif; ?>
          <td style="color:var(--faint);font-size:11.5px;max-width:180px" class="truncate"><?= h($c['notas'] ?: 'Sin notas') ?></td>
          <td>
            <span style="display:inline-block;font-size:10px;font-weight:700;padding:2px 8px;border-radius:20px;color:<?= $vColor ?>;background:<?= $vBg ?>"><?= $vEstado ?></span>
            <div style="margin-top:4px"><span class="<?= $pill ?>" style="font-size:10px" title="Días faltantes para el vencimiento"><?= h($txt) ?></span></div>
            <?php if (!empty($c['vencimiento'])): ?><div style="font-size:10px;color:var(--faint);margin-top:2px"><?= $esRev && (int) ($c['origen_cuenta_id'] ?? 0) > 0 ? '🛒 tu corte: ' : 'vence: ' ?><?= h(date('d/m/Y', strtotime((string) $c['vencimiento']))) ?></div><?php endif; ?>
          </td>
          <td><div class="flex items-center justify-center gap-1">
            <button onclick="abrirDetalle(<?= (int) $c['id'] ?>)" class="iconact v" title="Detalles"><i data-lucide="eye"></i></button>
            <button onclick="abrirRenovar(<?= (int) $c['id'] ?>,'<?= h($c['plataforma']) ?>')" class="iconact r" title="Renovar"><i data-lucide="calendar-check"></i></button>
            <button onclick="abrirEditar(<?= (int) $c['id'] ?>)" class="iconact e" title="Editar"><i data-lucide="pencil"></i></button>
            <button onclick="abrirEliminar(<?= (int) $c['id'] ?>,'<?= h($c['plataforma']) ?>')" class="iconact d" title="Eliminar"><i data-lucide="trash-2"></i></button>
          </div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php if (!$cuentas && !$err): ?><div class="empty">Sin cuentas todavía. Crea la primera con "Nueva Plataforma".</div><?php endif; ?>
  </div>
</div>

<!-- ════════ MODALES ════════ -->
<div id="overlay" class="fixed inset-0 bg-black/50 z-40 hidden items-start justify-center overflow-y-auto p-4" onclick="if(event.target===this)cerrarModales()">

  <!-- Nueva / Editar Plataforma -->
  <div id="m-cuenta" class="modal hidden w-full max-w-2xl my-8">
    <div class="modal-hd">
      <h3><i data-lucide="monitor"></i> <span id="m-cuenta-title">Agregar Nueva Plataforma</span></h3>
      <button onclick="cerrarModales()" class="modal-x"><i data-lucide="x"></i></button>
    </div>
    <form method="post" class="p-5 space-y-4">
      <input type="hidden" name="_csrf" value="<?= h($csrf) ?>"><input type="hidden" name="accion" id="f-accion" value="nueva_cuenta"><input type="hidden" name="id" id="f-id" value="">
      <div class="grid md:grid-cols-2 gap-4">
        <div><label class="flbl">Tipo de Plataforma</label>
          <select name="plataforma_id" id="ff-plat" class="input">
            <option value="">— Selecciona —</option>
            <?php foreach ($tipos as $t): ?><option value="<?= (int) $t['id'] ?>"><?= h($t['nombre']) ?></option><?php endforeach; ?>
          </select></div>
        <div><label class="flbl">Correo / Usuario</label><input name="correo" id="f-correo" class="input" placeholder="correo@ejemplo.com"></div>
        <div><label class="flbl">Contraseña</label><input name="clave" id="f-clave" class="input" placeholder="Contraseña de la cuenta"></div>
        <div id="f-avisar-wrap" style="grid-column:1/-1;padding:9px 11px;border-radius:9px;background:rgba(45,226,213,.07);border:1px solid rgba(45,226,213,.28)">
          <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:12.5px;font-weight:600">
            <input type="checkbox" name="avisar_rev" value="1" checked style="width:16px;height:16px;accent-color:var(--acc)">
            Avisar a los revendedores que venden esta cuenta
          </label>
          <div style="font-size:11px;color:var(--faint);margin-top:4px;padding-left:24px">
            Les llega a su panel (y push si lo tienen) para que avisen a sus clientes. Solo si cambias correo o clave. No manda WhatsApp.
          </div>
        </div>
        <div><label class="flbl">Cantidad de Perfiles</label><input name="perfiles_total" id="f-perf" type="number" min="1" max="500" value="1" class="input" oninput="syncPerfiles()"></div>
        <?php if ($verCostos || $esRev): ?>
        <div><label class="flbl">Precio (Costo)</label><div class="flex" style="margin-top:0"><span style="padding:0 12px;display:grid;place-items:center;background:var(--surface-2);border:1px solid var(--border);border-right:0;border-radius:9px 0 0 9px;color:var(--muted)">$</span><input name="costo" id="f-costo" type="number" step="0.01" class="input" style="border-radius:0 9px 9px 0"></div><p id="f-costo-nota" style="font-size:10.5px;color:var(--faint);margin-top:3px"><?= $esRev ? 'Tu costo. En las cuentas que compras a la tienda es <b>automático</b> y no se edita; en las tuyas puedes ponerlo.' : 'Lo que <b>tú pagas</b> por esta cuenta (tu costo). El precio de venta al revendedor y a la tienda se fija en <b>Plataformas → Tipos</b>.' ?></p></div>
        <?php endif; ?>
        <?php if ($esRev): ?>
        <div><label class="flbl">Precio de venta <span style="color:var(--faint);font-weight:400">(a tu cliente)</span></label><div class="flex" style="margin-top:0"><span style="padding:0 12px;display:grid;place-items:center;background:var(--good);color:#fff;border-right:0;border-radius:9px 0 0 9px;font-weight:700">$</span><input name="precio_venta_rev" id="f-venta-rev" type="number" step="0.01" class="input" style="border-radius:0 9px 9px 0" placeholder="$"></div><p style="font-size:10.5px;color:var(--faint);margin-top:3px">Lo que le cobras a tu cliente. Esto <b>sí</b> lo cambias tú.</p></div>
        <?php endif; ?>
        <div><label class="flbl">Fecha de Renovación</label><input name="vencimiento" id="f-venc" type="date" class="input"><p id="f-venc-nota" style="font-size:10.5px;color:var(--faint);margin-top:3px"><?= $esRev ? 'En tus cuentas PROPIAS la fijas tú; en el stock comprado a la tienda se fija al comprar (no editable).' : 'Fecha en que vence. No lleva precio aparte: la renovación se cobra al mismo precio por perfil de <b>Plataformas</b>.' ?></p></div>
        <?php if ($verCostos): ?>
        <div><label class="flbl">Proveedor</label>
          <select name="proveedor_id" id="ff-prov" class="input"><option value="">— Selecciona —</option>
            <?php foreach ($proveedores as $p): ?><option value="<?= (int) $p['id'] ?>"><?= h($p['nombre']) ?></option><?php endforeach; ?></select>
          <a href="proveedores.php" target="_blank" style="font-size:11px;color:var(--accent);font-weight:650">¿No aparece? Crear nuevo</a></div>
        <?php elseif ($esRev): ?>
        <div><label class="flbl">Proveedor</label><input id="ff-prov-rev" class="input" readonly value="" style="opacity:.75"><p style="font-size:10.5px;color:var(--faint);margin-top:3px">Automático: lo comprado a la tienda es <b>REBOR</b>.</p></div>
        <?php endif; ?>
        <div class="md:col-span-2"><label class="flbl">Observaciones</label><input name="notas" id="f-notas" class="input" placeholder="Notas opcionales…"></div>
      </div>
      <div id="f-toggles" class="softbox flex flex-wrap items-center gap-6" style="padding:12px 16px">
        <label class="flex items-center gap-2.5 cursor-pointer" style="font-size:13px;font-weight:600;color:var(--muted)"><span class="relative inline-block w-11 h-6"><input type="checkbox" id="f-prefill" checked onchange="syncPerfiles()" class="peer sr-only"><span class="absolute inset-0 rounded-full bg-slate-400 peer-checked:bg-indigo-500 transition"></span><span class="absolute left-0.5 top-0.5 w-5 h-5 bg-white rounded-full transition peer-checked:translate-x-5"></span></span> Pre-rellenar Perfiles</label>
        <label class="flex items-center gap-2.5 cursor-pointer" style="font-size:13px;font-weight:600;color:var(--muted)"><span class="relative inline-block w-11 h-6"><input type="checkbox" name="usa_pin" id="f-pin" value="1" checked onchange="syncPerfiles()" class="peer sr-only"><span class="absolute inset-0 rounded-full bg-slate-400 peer-checked:bg-indigo-500 transition"></span><span class="absolute left-0.5 top-0.5 w-5 h-5 bg-white rounded-full transition peer-checked:translate-x-5"></span></span> ¿Requiere PIN?</label>
      </div>
      <div id="perfiles-box" class="softbox p-4 space-y-2"><div class="font-bold text-sm mb-2 flex items-center gap-2" style="color:var(--text)"><i data-lucide="users" style="width:16px;height:16px;color:var(--accent)"></i> Datos de los Perfiles <span id="perfiles-base-lbl" style="font-size:11px;font-weight:700;color:var(--accent);background:var(--accent-soft);padding:2px 9px;border-radius:20px">Perfiles Base (1)</span></div><div id="perfiles-rows" class="space-y-2"></div></div>
      <div class="flex justify-end gap-2 pt-1"><button type="button" onclick="cerrarModales()" class="btn ghost">Cancelar</button>
        <button class="btn primary"><i data-lucide="save"></i> <span id="m-cuenta-btn">Guardar Plataforma</span></button></div>
    </form>
  </div>

  <!-- Renovar -->
  <!-- ── LOTE: Renovar varias ── -->
  <div id="m-bulk-renovar" class="modal hidden w-full max-w-md my-8">
    <div class="modal-hd"><h3><i data-lucide="calendar-check"></i> Renovar varias</h3><button onclick="cerrarModales()" class="modal-x"><i data-lucide="x"></i></button></div>
    <form method="post" class="p-5 space-y-4"><input type="hidden" name="_csrf" value="<?= h($csrf) ?>"><input type="hidden" name="accion" value="renovar_cuentas_masivo"><input type="hidden" name="ids" id="bm-ids">
      <p style="color:var(--muted)">Vas a renovar <strong id="bm-n" style="color:var(--text)">0</strong> cuenta(s).</p>
      <div style="padding:9px 11px;border-radius:9px;background:rgba(245,166,35,.10);border:1px solid rgba(245,166,35,.35);font-size:12px;color:var(--warn)">
        <b>Ojo:</b> esto renueva la fecha de <b>tu pago al PROVEEDOR</b>. <b>No</b> cambia el vencimiento de tus clientes.
      </div>
      <div class="space-y-2">
        <label class="flex items-center gap-2" style="font-weight:600"><input type="radio" name="modo" value="meses" checked onchange="document.getElementById('bm-meses-box').classList.remove('hidden');document.getElementById('bm-fecha-box').classList.add('hidden');document.getElementById('bm-hasta').value=''" class="accent-teal-500"> Por meses <span style="font-weight:400;color:var(--faint);font-size:11.5px">(cada una desde SU propia fecha)</span></label>
        <div id="bm-meses-box"><select name="meses" class="input"><?php for ($i = 1; $i <= 12; $i++): ?><option value="<?= $i ?>"><?= $i ?> mes<?= $i > 1 ? 'es' : '' ?></option><?php endfor; ?></select></div>
        <label class="flex items-center gap-2" style="font-weight:600"><input type="radio" name="modo" value="fecha" onchange="document.getElementById('bm-fecha-box').classList.remove('hidden');document.getElementById('bm-meses-box').classList.add('hidden')" class="accent-teal-500"> Todas a una fecha</label>
        <div id="bm-fecha-box" class="hidden"><input type="date" name="hasta" id="bm-hasta" class="input"></div>
      </div>
      <button class="btn primary w-full">Renovar</button>
    </form>
  </div>

  <!-- ── LOTE: Eliminar varias ── -->
  <div id="m-bulk-eliminar" class="modal hidden w-full max-w-md my-8">
    <div class="modal-hd"><h3 style="color:var(--err)"><i data-lucide="trash-2"></i> Eliminar varias</h3><button onclick="cerrarModales()" class="modal-x"><i data-lucide="x"></i></button></div>
    <form method="post" class="p-5 space-y-4"><input type="hidden" name="_csrf" value="<?= h($csrf) ?>"><input type="hidden" name="accion" value="eliminar_cuentas_masivo"><input type="hidden" name="ids" id="bd-ids">
      <p style="color:var(--muted)">Vas a eliminar <strong id="bd-n" style="color:var(--err)">0</strong> cuenta(s). <b>Esto no se deshace.</b></p>
      <div style="padding:9px 11px;border-radius:9px;background:rgba(239,68,68,.09);border:1px solid rgba(239,68,68,.32);font-size:12px;color:var(--muted)">
        Las cuentas con <b>ventas activas</b> se saltan por seguridad y te aviso cuántas fueron.
        El <b>historial de ventas NUNCA se borra</b>: quedan canceladas con la nota de qué pasó.
      </div>
      <label class="flex items-center gap-2" style="font-size:12.5px;color:var(--warn)"><input type="checkbox" name="forzar" value="1" class="accent-teal-500"> <b>Forzar</b>: borrar aunque tengan ventas activas</label>
      <div class="field"><label class="flbl">Escribe <b>ELIMINAR</b> para confirmar</label><input name="confirmar" id="bd-confirm" class="input" placeholder="ELIMINAR" autocomplete="off"></div>
      <button class="btn w-full" style="background:var(--err);color:#fff">Eliminar definitivamente</button>
    </form>
  </div>

  <!-- ── LOTE: Vender varias (marcar vendido) ── -->
  <div id="m-bulk-vender" class="modal hidden w-full max-w-md my-8">
    <div class="modal-hd"><h3 style="color:var(--good)"><i data-lucide="shopping-cart"></i> Vender varias</h3><button onclick="cerrarModales()" class="modal-x"><i data-lucide="x"></i></button></div>
    <form method="post" class="p-5 space-y-4"><input type="hidden" name="_csrf" value="<?= h($csrf) ?>"><input type="hidden" name="accion" value="vender_cuentas_masivo"><input type="hidden" name="ids" id="bv-ids">
      <p style="color:var(--muted)">Vas a vender <strong id="bv-n" style="color:var(--text)">0</strong> perfil(es). Marca ✓ cuáles vender. Si marcas <b>TODOS</b> los de una cuenta → se vende como <b>cuenta completa</b> (1 línea); si marcas <b>algunos</b> → 1 línea por perfil.</p>
      <div class="space-y-2">
        <label class="flex items-center gap-2" style="font-weight:600"><input type="radio" name="destino" value="cliente" checked onchange="bvDestino()" class="accent-teal-500"> A un cliente</label>
        <label class="flex items-center gap-2" style="font-weight:600"><input type="radio" name="destino" value="revendedor" onchange="bvDestino()" class="accent-teal-500"> A un revendedor</label>
      </div>
      <div id="bv-cliente" class="space-y-2">
        <div class="field"><label class="flbl">Nombre del cliente</label><input name="cliente_nombre" id="bv-cli-nombre" class="input" placeholder="Ej: María"></div>
        <div class="field"><label class="flbl">WhatsApp (opcional)</label><input name="cliente_wa" class="input" placeholder="Ej: 584121234567"></div>
      </div>
      <div id="bv-rev" class="hidden">
        <div class="field"><label class="flbl">Revendedor</label>
          <select name="revendedor_id" class="input"><option value="">— Elige —</option><?php foreach ($revList as $rv): ?><option value="<?= (int) $rv['id'] ?>"><?= h($rv['nombre']) ?></option><?php endforeach; ?></select>
        </div>
      </div>
      <div class="grid grid-cols-2 gap-3">
        <div class="field"><label class="flbl">Precio para TODAS (opcional)</label><input id="bv-precio-all" type="number" step="0.01" min="0" class="input" placeholder="$" oninput="bvPrecioAll(this.value)"></div>
        <div class="field"><label class="flbl">Vence (opcional)</label><input name="vencimiento" type="date" class="input"></div>
      </div>
      <div class="field" style="margin-bottom:0">
        <label class="flbl">Marca ✓ los perfiles a vender · nombre / PIN / precio por cada uno</label>
        <div id="bv-rows" style="max-height:240px;overflow:auto;border:1px solid var(--border);border-radius:10px;padding:8px;background:var(--surface-2)"></div>
      </div>
      <p style="font-size:11px;color:var(--faint)">Si dejas un precio vacío, usa el de cada cuenta. Vencimiento vacío = el de cada cuenta / días de la plataforma. Luego verás los botones de WhatsApp en <b>Ventas</b>.</p>
      <button class="btn primary w-full">Marcar como vendidas</button>
    </form>
  </div>
<?php if ($verCostos): ?>
  <!-- ── LOTE: Asignar proveedor ── -->
  <div id="m-bulk-proveedor" class="modal hidden w-full max-w-md my-8">
    <div class="modal-hd"><h3><i data-lucide="truck"></i> Asignar proveedor</h3><button onclick="cerrarModales()" class="modal-x"><i data-lucide="x"></i></button></div>
    <form method="post" class="p-5 space-y-4"><input type="hidden" name="_csrf" value="<?= h($csrf) ?>"><input type="hidden" name="accion" value="asignar_proveedor_masivo"><input type="hidden" name="ids" id="bp-ids">
      <p style="color:var(--muted)">Le pones el proveedor a <strong id="bp-n" style="color:var(--text)">0</strong> cuenta(s).</p>
      <div class="field"><label class="flbl">Proveedor</label>
        <select name="proveedor_id" class="input"><option value="">— Sin proveedor —</option><?php foreach ($proveedores as $pv): ?><option value="<?= (int) $pv['id'] ?>"><?= h($pv['nombre']) ?></option><?php endforeach; ?></select>
      </div>
      <button class="btn primary w-full">Asignar a todas</button>
    </form>
  </div>
<?php endif; ?>

  <!-- ── LOTE: Cambiar precios (costo / venta / reventa) — disponible para admin Y revendedor ── -->
  <div id="m-bulk-costo" class="modal hidden w-full max-w-md my-8">
    <div class="modal-hd"><h3><i data-lucide="dollar-sign"></i> Cambiar precios</h3><button onclick="cerrarModales()" class="modal-x"><i data-lucide="x"></i></button></div>
    <form method="post" class="p-5 space-y-4"><input type="hidden" name="_csrf" value="<?= h($csrf) ?>"><input type="hidden" name="accion" value="cambiar_costo_masivo"><input type="hidden" name="ids" id="bc-ids">
      <p style="color:var(--muted)">A <strong id="bc-n" style="color:var(--text)">0</strong> cuenta(s). Deja vacío lo que no quieras cambiar.</p>
      <div class="field"><label class="flbl">Costo por cuenta <span style="color:var(--faint);font-weight:400"><?= $esRev ? '(solo en tus cuentas propias; el stock comprado a la tienda es automático)' : '(lo que pagas al proveedor)' ?></span></label><input name="costo" type="number" step="0.01" min="0" class="input" placeholder="$"></div>
      <div class="grid grid-cols-2 gap-3">
        <div class="field"><label class="flbl">Precio venta <span style="color:var(--faint);font-weight:400">(al cliente)</span></label><input name="precio_venta" type="number" step="0.01" min="0" class="input" placeholder="$"></div>
        <div class="field"><label class="flbl">Precio reventa <span style="color:var(--faint);font-weight:400">(al revendedor)</span></label><input name="precio_reventa" type="number" step="0.01" min="0" class="input" placeholder="$"></div>
      </div>
      <p style="font-size:11px;color:var(--faint)">Estos precios son <b>de estas cuentas</b> (no de toda la plataforma). Al vender, se usan estos si están puestos; si no, el precio general de la plataforma.</p>
      <button class="btn primary w-full">Aplicar a todas</button>
    </form>
  </div>

  <div id="m-renovar" class="modal hidden w-full max-w-md my-8">
    <div class="modal-hd"><h3><i data-lucide="calendar-check"></i> Renovar Plataforma</h3><button onclick="cerrarModales()" class="modal-x"><i data-lucide="x"></i></button></div>
    <form method="post" class="p-5 space-y-4"><input type="hidden" name="_csrf" value="<?= h($csrf) ?>"><input type="hidden" name="accion" value="renovar_cuenta"><input type="hidden" name="id" id="r-id">
      <p style="color:var(--muted)">Cuenta: <strong id="r-nombre" style="color:var(--text)"></strong></p>
      <div class="space-y-2">
        <label class="flex items-center gap-2" style="font-weight:600"><input type="radio" name="modo" value="meses" checked onchange="document.getElementById('r-meses-box').classList.remove('hidden');document.getElementById('r-fecha-box').classList.add('hidden')" class="accent-teal-500"> Por meses</label>
        <div id="r-meses-box"><select name="meses" class="input"><?php for ($i = 1; $i <= 12; $i++): ?><option value="<?= $i ?>"><?= $i ?> mes<?= $i > 1 ? 'es' : '' ?></option><?php endfor; ?></select></div>
        <label class="flex items-center gap-2" style="font-weight:600"><input type="radio" name="modo" value="fecha" onchange="document.getElementById('r-fecha-box').classList.remove('hidden');document.getElementById('r-meses-box').classList.add('hidden')" class="accent-teal-500"> Asignar fecha específica</label>
        <div id="r-fecha-box" class="hidden"><input type="date" name="hasta" class="input"></div>
      </div>
      <div class="flex justify-end gap-2"><button type="button" onclick="cerrarModales()" class="btn ghost">Cancelar</button><button class="btn primary">Renovar</button></div>
    </form>
  </div>

  <!-- Detalles -->
  <div id="m-detalle" class="modal hidden w-full max-w-2xl my-8">
    <div class="modal-hd"><h3><i data-lucide="eye"></i> Detalles de la Plataforma</h3><button onclick="cerrarModales()" class="modal-x"><i data-lucide="x"></i></button></div>
    <div id="detalle-body" class="p-5" style="font-size:13px;color:var(--muted)">Cargando…</div>
    <div class="px-5 pb-5 flex justify-end"><button onclick="cerrarModales()" class="btn primary">Cerrar</button></div>
  </div>

  <!-- Carga Masiva -->
  <div id="m-masivo" class="modal hidden w-full max-w-lg my-8">
    <div class="modal-hd"><h3><i data-lucide="upload-cloud"></i> Carga Masiva de Perfiles</h3><button onclick="cerrarModales()" class="modal-x"><i data-lucide="x"></i></button></div>
    <form method="post" class="p-5 space-y-3"><input type="hidden" name="_csrf" value="<?= h($csrf) ?>"><input type="hidden" name="accion" value="cuentas_masivo">
      <p style="font-size:11.5px;color:var(--faint)"><b>Una línea por PERFIL:</b> <code class="softbox" style="padding:1px 6px;border-radius:6px">Plataforma, Correo, Clave, Nombre del perfil, PIN, Vence, Costo, Vendedor</code><br>
        · Los perfiles con el <b>mismo correo</b> se agrupan en una <b>sola cuenta</b> (no una por línea).<br>
        · <b>Nombre del perfil</b> = el que quieras (ej. el nombre del cliente); si lo dejas vacío se numera P1, P2…<br>
        · <b>PIN</b>, <b>Vence</b> y <b>Costo</b> son opcionales. Fecha: <b>AAAA-MM-DD</b> o <b>DD/MM/AAAA</b>. El costo es lo que tú pagas por la cuenta.<br>
        · <b>Vendedor</b> (opcional, última columna): nombre/usuario/correo de un revendedor → el perfil se sube ya <b>vendido</b> a ese revendedor. Si lo dejas vacío, queda como <b>stock</b>.</p>
      <textarea name="datos" rows="8" class="input" style="font-family:ui-monospace,SFMono-Regular,Menlo,monospace" placeholder="Netflix, correo@x.com, clave123, Rebeca JR, 9669, 01/08/2026, 3.50, rev1&#10;Netflix, correo@x.com, clave123, Pedro, 1234, 01/08/2026, ,&#10;Disney+, otro@x.com, clave99, , , 2026-09-15"></textarea>
      <div class="flex justify-end gap-2"><button type="button" onclick="cerrarModales()" class="btn ghost">Cancelar</button><button class="btn primary">Importar</button></div>
    </form>
  </div>
  <!-- Calendario -->
  <div id="m-cal" class="modal hidden w-full max-w-2xl my-8">
    <div class="modal-hd"><h3><i data-lucide="calendar"></i> Calendario de Renovaciones</h3><button onclick="cerrarModales()" class="modal-x"><i data-lucide="x"></i></button></div>
    <div class="p-5">
      <div class="flex items-center justify-between mb-3"><button onclick="calMes(-1)" class="caret">←</button><span id="cal-titulo" class="font-bold capitalize" style="color:var(--text)"></span><button onclick="calMes(1)" class="caret">→</button></div>
      <div id="cal-grid" class="grid grid-cols-7 gap-1 text-center text-xs"></div>
    </div>
    <div class="px-5 pb-5 flex justify-end"><button onclick="cerrarModales()" class="btn primary">Cerrar</button></div>
  </div>
  <!-- Eliminar -->
  <div id="m-eliminar" class="modal hidden w-full max-w-sm my-8 p-6 text-center">
    <div style="width:56px;height:56px;border-radius:50%;border:1px solid var(--border);display:grid;place-items:center;margin:0 auto 12px;color:var(--bad)"><i data-lucide="help-circle" style="width:30px;height:30px"></i></div>
    <h3 class="font-black text-lg" style="color:var(--text)">¿Deseas Eliminar esta cuenta?</h3>
    <p style="color:var(--muted);margin-bottom:2px" id="e-nombre"></p><p style="font-size:11.5px;color:var(--faint);margin-bottom:14px">Esta acción no se puede deshacer</p>
    <form method="post"><input type="hidden" name="_csrf" value="<?= h($csrf) ?>"><input type="hidden" name="accion" value="eliminar_cuenta"><input type="hidden" name="id" id="e-id">
      <label style="display:flex;align-items:center;justify-content:center;gap:8px;font-size:12.5px;color:var(--warn);margin-bottom:16px"><input type="checkbox" name="forzar" value="1" class="accent-teal-500"> <b>Forzar</b>: bórrala aunque tenga ventas activas (cambio de proveedor)</label>
      <div class="flex justify-center gap-2">
        <button class="btn danger">Sí, eliminar</button>
        <button type="button" onclick="cerrarModales()" class="btn ghost">Cancelar</button>
      </div>
    </form>
  </div>
</div>

<script>
  const ES_REV = <?= $esRev ? 'true' : 'false' ?>;   // contexto revendedor (bloqueos de edición sobre stock comprado)
  function fval(id){ const e=document.getElementById(id); return e?e.value:''; }
  const esc=s=>String(s==null?'':s).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  function filtrar(){
    const q=(fval('f-busqueda')+' '+fval('buscar')).toLowerCase().trim().split(/\s+/).filter(Boolean);
    const plat=fval('f-plat'), estado=fval('f-estado'), stock=fval('f-stock'), prov=fval('f-prov');
    document.querySelectorAll('#tbody tr').forEach(tr=>{ const b=tr.dataset.b||'';
      const ok = q.every(t=>b.includes(t)) && (!plat||tr.dataset.plat===plat) && (!estado||tr.dataset.estado===estado) && (!stock||tr.dataset.stock===stock) && (!prov||tr.dataset.prov===prov);
      tr.style.display=ok?'':'none';
      // REGLA DE ORO: fila oculta = DESMARCADA. Si no, marcas → filtras → "Eliminar varias"
      // borraría lo que ya no ves. Es el escenario más fácil de provocar sin querer.
      if(!ok){ const c=tr.querySelector('.ck-row'); if(c) c.checked=false; }
    });
    ckSync();
  }

  /* ── Selección múltiple ───────────────────────────────────────────────────── */
  function filasVisibles(){ return Array.from(document.querySelectorAll('#tbody tr')).filter(tr=>tr.style.display!=='none'); }
  function ckTodo(master){ filasVisibles().forEach(tr=>{ const c=tr.querySelector('.ck-row'); if(c) c.checked=master.checked; }); ckSync(); }
  function ckIds(){ return Array.from(document.querySelectorAll('#tbody tr')).filter(tr=>tr.style.display!=='none').map(tr=>tr.querySelector('.ck-row')).filter(c=>c&&c.checked).map(c=>c.value); }
  function ckSync(){
    const ids=ckIds(), n=ids.length, bar=document.getElementById('bulkbar');
    if(bar){ bar.style.display=n>0?'':'none'; const t=document.getElementById('bulk-n'); if(t) t.textContent=n; }
    const vis=filasVisibles().length, master=document.getElementById('ck-all');
    if(master){ master.checked = n>0 && n===vis; master.indeterminate = n>0 && n<vis; }
  }
  function bulkRenovar(){ const ids=ckIds(); if(!ids.length) return; document.getElementById('bm-ids').value=ids.join(','); document.getElementById('bm-n').textContent=ids.length; abrir('m-bulk-renovar'); }
  function bulkEliminar(){ const ids=ckIds(); if(!ids.length) return; document.getElementById('bd-ids').value=ids.join(','); document.getElementById('bd-n').textContent=ids.length; document.getElementById('bd-confirm').value=''; abrir('m-bulk-eliminar'); }
  function selTodos(){ ckTodo({checked:true}); }
  const PERF_CTA = <?= json_encode($perfMapCta, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  function bulkVender(){
    const checks=Array.from(document.querySelectorAll('#tbody tr')).filter(tr=>tr.style.display!=='none').map(tr=>tr.querySelector('.ck-row')).filter(c=>c&&c.checked);
    if(!checks.length){ alert('Marca al menos una cuenta.'); return; }
    document.getElementById('bv-ids').value=checks.map(c=>c.value).join(',');
    // Filas POR PERFIL (agrupadas por cuenta): checkbox incluir + nombre + PIN + precio.
    const box=document.getElementById('bv-rows'); box.innerHTML=''; let tienePerf=false;
    checks.forEach(c=>{
      const cid=c.value; const perfs=PERF_CTA[cid]||[];
      const head=document.createElement('div');
      head.style.cssText='font-weight:700;font-size:12px;color:var(--accent);margin:6px 0 4px;border-top:1px solid var(--border);padding-top:6px';
      head.textContent=esc(c.getAttribute('data-lbl')||('Cuenta #'+cid))+' — '+perfs.length+' perfil(es) libre(s)';
      box.appendChild(head);
      if(!perfs.length){ const w=document.createElement('div'); w.style.cssText='font-size:11px;color:var(--bad)'; w.textContent='Sin perfiles libres'; box.appendChild(w); return; }
      perfs.forEach(p=>{ tienePerf=true;
        const row=document.createElement('div');
        row.style.cssText='display:grid;grid-template-columns:22px 1fr 60px 78px;gap:6px;margin-bottom:5px;align-items:center';
        row.innerHTML='<input type="checkbox" name="p_sel['+p.id+']" value="1" checked class="bv-sel accent-teal-500" onchange="bvCount()" title="Incluir este perfil">'
          +'<input name="p_nombre['+p.id+']" class="input" style="padding:6px 8px;font-size:12px" value="'+esc(p.etq)+'" placeholder="Nombre">'
          +'<input name="p_pin['+p.id+']" class="input" style="padding:6px 8px;font-size:12px" value="'+esc(p.pin)+'" placeholder="PIN">'
          +'<input name="p_precio['+p.id+']" type="number" step="0.01" min="0" class="input bv-prow" style="padding:6px 8px;font-size:12px" placeholder="$">';
        box.appendChild(row);
      });
    });
    if(!tienePerf){ alert('Las cuentas marcadas no tienen perfiles libres.'); return; }
    const pa=document.getElementById('bv-precio-all'); if(pa) pa.value='';
    bvCount(); bvDestino(); abrir('m-bulk-vender');
  }
  function bvCount(){ const n=document.querySelectorAll('#bv-rows .bv-sel:checked').length; const t=document.getElementById('bv-n'); if(t) t.textContent=n; }
  function bvPrecioAll(v){ document.querySelectorAll('#bv-rows .bv-prow').forEach(i=>{ i.value=v; }); }
  function bvDestino(){ const r=document.querySelector('input[name="destino"]:checked'); const esRev=!!r&&r.value==='revendedor'; const c=document.getElementById('bv-cliente'), v=document.getElementById('bv-rev'); if(c)c.classList.toggle('hidden',esRev); if(v)v.classList.toggle('hidden',!esRev); }
  function bulkProveedor(){ const el=document.getElementById('bp-ids'); if(!el) return; const ids=ckIds(); if(!ids.length){ alert('Marca al menos una cuenta.'); return; } el.value=ids.join(','); document.getElementById('bp-n').textContent=ids.length; abrir('m-bulk-proveedor'); }
  function bulkCosto(){ const el=document.getElementById('bc-ids'); if(!el) return; const ids=ckIds(); if(!ids.length){ alert('Marca al menos una cuenta.'); return; } el.value=ids.join(','); document.getElementById('bc-n').textContent=ids.length; abrir('m-bulk-costo'); }
  function ordenar(){ const v=document.getElementById('f-orden').value; if(!v) return; const tb=document.getElementById('tbody'); if(!tb) return; const rows=Array.from(tb.querySelectorAll('tr')); const p=v.split('-'), key=p[0], mul=p[1]==='desc'?-1:1; rows.sort((a,b)=>{ if(key==='dias'){ return ((parseInt(a.dataset.dias||'0',10))-(parseInt(b.dataset.dias||'0',10)))*mul; } return String(a.dataset.plat||'').localeCompare(String(b.dataset.plat||''))*mul; }); rows.forEach(r=>tb.appendChild(r)); }
  function resetFiltros(){ ['f-busqueda','f-plat','f-estado','f-stock','f-prov','f-orden','buscar'].forEach(id=>{const e=document.getElementById(id); if(e) e.value='';}); filtrar(); }
  document.getElementById('buscar')?.addEventListener('input', filtrar);
  const ov = document.getElementById('overlay');
  function cerrarModales(){ ov.classList.add('hidden'); ov.classList.remove('flex'); document.querySelectorAll('#overlay > div').forEach(m=>m.classList.add('hidden')); }
  function abrir(id){ ov.classList.remove('hidden'); ov.classList.add('flex'); document.querySelectorAll('#overlay > div').forEach(m=>m.classList.add('hidden')); document.getElementById(id).classList.remove('hidden'); lucide.createIcons(); }

  // Perfiles base (Nueva): genera filas según cantidad
  function syncPerfiles(){
    const n=Math.max(1,Math.min(500,parseInt(document.getElementById('f-perf').value)||1));
    const pre=document.getElementById('f-prefill').checked, pin=document.getElementById('f-pin').checked;
    const lbl=document.getElementById('perfiles-base-lbl'); if(lbl) lbl.textContent='Perfiles Base ('+n+')';
    const box=document.getElementById('perfiles-box'); box.style.display=pre?'':'none';
    const rows=document.getElementById('perfiles-rows'); rows.innerHTML='';
    if(!pre) return;
    for(let i=0;i<n;i++){
      rows.insertAdjacentHTML('beforeend',
        `<div class="flex gap-2 items-center"><span class="tag" style="color:var(--good);background:var(--good-soft);border-color:transparent">PRE</span>
         <input name="pet[]" placeholder="Nombre Perfil ${i+1}" class="input" style="flex:1">
         ${pin?`<input name="ppin[]" placeholder="PIN" class="input" style="width:96px">`:''}</div>`);
    }
  }
  function abrirNueva(){
    document.getElementById('m-cuenta-title').textContent='Agregar Nueva Plataforma';
    document.getElementById('m-cuenta-btn').textContent='Guardar Plataforma';
    document.getElementById('f-accion').value='nueva_cuenta'; document.getElementById('f-id').value='';
    ['f-correo','f-clave','f-venc','f-notas','f-costo','ff-prov','ff-plat'].forEach(id=>{const e=document.getElementById(id); if(e) e.value='';});
    document.getElementById('f-perf').value='1';
    document.getElementById('f-prefill').checked=true; document.getElementById('f-pin').checked=true;
    document.getElementById('ff-plat').disabled=false;
    document.getElementById('f-toggles').style.display='';        // Nueva SÍ muestra los toggles (bug: Editar los ocultaba y no se restauraban)
    const av=document.getElementById('f-avisar-wrap'); if(av) av.style.display='none';  // Avisar revendedores: solo al Editar (en Nueva no hay a quién avisar)
    syncPerfiles(); abrir('m-cuenta');
  }
  async function abrirEditar(id){
    abrir('m-cuenta');
    document.getElementById('m-cuenta-title').textContent='Editar Plataforma';
    document.getElementById('m-cuenta-btn').textContent='Guardar Cambios';
    document.getElementById('f-accion').value='editar_cuenta'; document.getElementById('f-id').value=id;
    document.getElementById('ff-plat').disabled=true;
    const r=await fetch('?ajax=cuenta&id='+id).then(x=>x.json()); if(!r.ok) return;
    const c=r.cuenta;
    document.getElementById('f-correo').value=c.correo||''; document.getElementById('f-clave').value=c.clave||'';
    document.getElementById('f-venc').value=(c.vencimiento||'').slice(0,10); document.getElementById('f-notas').value=c.notas||'';
    document.getElementById('f-perf').value=c.perfiles_total||1;
    if(document.getElementById('f-costo')) document.getElementById('f-costo').value=c.costo||'';
    if(document.getElementById('f-venta-rev')) document.getElementById('f-venta-rev').value=c.precio_venta||'';
    if(document.getElementById('ff-plat')) document.getElementById('ff-plat').value=c.plataforma_id||'';
    if(document.getElementById('ff-prov')) document.getElementById('ff-prov').value=c.proveedor_id||'';
    // Revendedor: si la cuenta la compró/le asignaron la tienda (espejo), correo + costo son AUTOMÁTICOS
    // (solo los cambia la tienda). Si es una cuenta PROPIA suya, los edita. El tipo de plataforma nunca
    // se cambia al editar (ff-plat ya va deshabilitado).
    if (ES_REV) {
      var esMirror = !!(c.origen_cuenta_id && Number(c.origen_cuenta_id) > 0);
      var esCC = Number(c.rev_editable||0) === 1;   // compró la CUENTA COMPLETA → sí edita clave
      // Correo y costo: bloqueados en mirror. La FECHA: bloqueada en mirror, EDITABLE en cuentas propias.
      ['f-correo','f-costo'].forEach(function(id){ var el=document.getElementById(id); if(el){ el.readOnly=esMirror; el.style.opacity=esMirror?'.6':'1'; el.title=esMirror?'Automático: lo fija la tienda (comprada a REBOR)':''; } });
      var fv=document.getElementById('f-venc'); if(fv){ fv.readOnly=esMirror; fv.disabled=esMirror; fv.style.opacity=esMirror?'.6':'1'; fv.title=esMirror?'Se fija al comprar; no editable':''; }
      var fvn=document.getElementById('f-venc-nota'); if(fvn) fvn.innerHTML = esMirror ? 'Se fija al comprar tu stock; no se edita aquí. La fecha de tu <b>cliente</b> la cambias en <b>Ventas</b>.' : 'Cuenta propia: la fecha la fijas tú.';
      var cn=document.getElementById('f-costo-nota'); if(cn) cn.innerHTML = esMirror ? 'Comprada a <b>REBOR</b>: tu costo es automático y no se edita.' : 'Tu costo (cuenta propia): puedes ponerlo.';
      // Clave: solo se edita si compró la CUENTA COMPLETA; en un perfil suelto (mirror) la fija la tienda.
      var kc=document.getElementById('f-clave'); if(kc){ var lockK = esMirror && !esCC; kc.readOnly=lockK; kc.style.opacity=lockK?'.6':'1'; kc.title=lockK?'Solo la tienda cambia la clave (o cómprala como cuenta completa)':''; }
      // Cantidad de perfiles: no editable en stock comprado a la tienda.
      var pf=document.getElementById('f-perf'); if(pf){ pf.readOnly=esMirror; pf.style.opacity=esMirror?'.6':'1'; pf.title=esMirror?'Automático: lo fija la tienda':''; }
      // Proveedor visible (solo lectura) para el revendedor: REBOR si es comprada a la tienda.
      var pv=document.getElementById('ff-prov-rev'); if(pv) pv.value = esMirror ? 'REBOR' : '';
    }
    // Gestión de perfiles existentes (por id) con badge Vendido/Libre
    const box=document.getElementById('perfiles-box'); box.style.display='';
    const rows=document.getElementById('perfiles-rows'); rows.innerHTML='';
    document.getElementById('f-toggles').style.display='none';   // Editar no usa los toggles de "Nueva"
    const av=document.getElementById('f-avisar-wrap'); if(av) av.style.display='';   // Avisar revendedores: visible al editar (aquí sí cambian credenciales)
    const lblE=document.getElementById('perfiles-base-lbl'); if(lblE) lblE.textContent='Gestión de Perfiles ('+r.perfiles.length+')';
    r.perfiles.forEach(p=>{
      const badge=p.estado==='vendido'?'<span class="pill ok" style="font-size:10px">Vendido</span>':p.estado==='inactivo'?'<span class="pill wait" style="font-size:10px">Ocupado (PAC)</span>':'<span class="tag">Libre</span>';
      rows.insertAdjacentHTML('beforeend',
        `<div class="flex gap-2 items-center">${badge}
         <input name="pet[${p.id}]" value="${esc(p.etiqueta||'')}" class="input" style="flex:1">
         <input name="ppin[${p.id}]" value="${esc(p.pin||'')}" placeholder="PIN" class="input" style="width:96px"></div>`);
    });
  }
  function abrirRenovar(id,nombre){
    document.querySelector('#m-renovar input[name=modo][value=meses]').checked=true;
    document.getElementById('r-meses-box').classList.remove('hidden');
    document.getElementById('r-fecha-box').classList.add('hidden');
    document.querySelector('#m-renovar input[name=hasta]').value='';
    document.getElementById('r-id').value=id; document.getElementById('r-nombre').textContent=nombre; abrir('m-renovar'); }
  function abrirEliminar(id,nombre){ document.getElementById('e-id').value=id; document.getElementById('e-nombre').textContent=nombre; abrir('m-eliminar'); }
  async function abrirDetalle(id){
    abrir('m-detalle'); const body=document.getElementById('detalle-body'); body.innerHTML='Cargando…';
    const r=await fetch('?ajax=cuenta&id='+id).then(x=>x.json()); if(!r.ok){body.innerHTML='No se pudo cargar.';return;}
    const c=r.cuenta;
    let info=`<div class="softbox p-4 mb-4 space-y-1">
      <div class="font-bold" style="color:var(--text)">Plataforma: ${esc(c.plataforma||'')}</div>
      <div><b>Correo:</b> ${esc(c.correo||'—')}</div>
      <div><b>Fecha de Renovación:</b> ${(c.vencimiento||'—').slice(0,10).split('-').reverse().join('/')}</div>
      ${r.prov?`<div><b>Proveedor:</b> ${esc(r.prov.nombre)} ${r.prov.contacto?'· '+esc(r.prov.contacto):''}</div>`:''}
      <div><b>Observaciones:</b> ${esc(c.notas||'Sin observaciones.')}</div></div>`;
    let ventas='<div class="font-bold mb-2" style="color:var(--text)">Ventas Asociadas</div><div class="grid sm:grid-cols-2 gap-2">';
    r.perfiles.forEach((p,i)=>{
      if(p.estado==='vendido'){
        let dias='';
        if(p.fecha_vencimiento){const d=Math.ceil((new Date(p.fecha_vencimiento)-new Date())/86400000); dias=`<span class="pill ${d<0?'err':d<=5?'wait':'ok'}" style="font-size:10px">${d<0?'Vencido':d+' días'}</span>`;}
        ventas+=`<div class="softbox p-3"><div class="flex justify-between items-start"><div class="font-bold" style="color:var(--text)">${esc(p.cliente_nombre||'Cliente')}</div>${dias}</div><div style="font-size:11px;color:var(--faint);margin-top:4px">${esc(p.etiqueta||'')}</div></div>`;
      } else if(p.estado==='inactivo'){
        ventas+=`<div class="p-3" style="border:1px dashed var(--border-strong);border-radius:12px;opacity:.7"><div class="flex justify-between items-center"><div class="font-bold" style="color:var(--muted)">Ocupado en PAC</div><span class="pill wait" style="font-size:10px">No disp.</span></div><div style="font-size:11px;color:var(--faint);margin-top:4px">Sin cliente en CONEC · no vendible</div></div>`;
      } else {
        ventas+=`<div class="p-3" style="border:1px dashed var(--border-strong);border-radius:12px"><div class="flex justify-between items-center"><div class="font-bold" style="color:var(--muted)">Disponible #${i+1}</div><span class="tag">Libre</span></div><div style="font-size:11px;color:var(--faint);margin-top:4px">Slot abierto</div></div>`;
      }
    });
    ventas+='</div>'; body.innerHTML=info+ventas; lucide.createIcons();
  }
  // Calendario de renovaciones
  let calYM=null;
  function abrirCalendario(){ const d=new Date(); calYM=d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0'); abrir('m-cal'); renderCal(); }
  function calMes(delta){ let [y,m]=calYM.split('-').map(Number); m+=delta; if(m<1){m=12;y--;} if(m>12){m=1;y++;} calYM=y+'-'+String(m).padStart(2,'0'); renderCal(); }
  async function renderCal(){
    const r=await fetch('?ajax=calendario&ym='+calYM).then(x=>x.json());
    const [y,m]=calYM.split('-').map(Number);
    document.getElementById('cal-titulo').textContent=new Date(y,m-1,1).toLocaleDateString('es-VE',{month:'long',year:'numeric'});
    const first=new Date(y,m-1,1).getDay(), days=new Date(y,m,0).getDate(), grid=document.getElementById('cal-grid');
    grid.innerHTML=['D','L','M','M','J','V','S'].map(d=>`<div class="font-bold py-1" style="color:var(--faint)">${d}</div>`).join('');
    for(let i=0;i<first;i++) grid.innerHTML+='<div></div>';
    for(let dd=1;dd<=days;dd++){ const key=`${calYM}-${String(dd).padStart(2,'0')}`; const it=(r.dias&&r.dias[key])||null;
      grid.innerHTML+=`<div class="rounded-lg p-1 min-h-[44px] text-left" style="border:1px solid ${it?'color-mix(in srgb,var(--bad) 45%,var(--border))':'var(--border)'};background:${it?'var(--bad-soft)':'transparent'}"><div style="color:var(--muted)">${dd}</div>${it?`<div style="font-size:10px;font-weight:700;color:var(--bad)">${it.length} renov.</div>`:''}</div>`; }
  }
  syncPerfiles();
</script>

<?php stream_foot(); ?>
