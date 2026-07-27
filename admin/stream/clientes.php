<?php
/**
 * Clientes (estilo PAC) ADAPTADO al sistema real: lista sobre streaming_clientes (los 514 de PAC),
 * enlazada a usuarios (cuenta web + billetera ConecPoints) por usuario_id. Tipo Cliente/Distribuidor
 * = es_revendedor. Acciones: Editar, Ver accesos, Excluir recordatorios, Activar Usuario Web,
 * Reset contraseña, Agregar/Disminuir saldo, Eliminar. Carga masiva. Reusa wallet + streaming helpers.
 */
define('CONEC_ADMIN', true);
require __DIR__ . '/../_auth.php';
require __DIR__ . '/_layout.php';
require __DIR__ . '/../../api/wa/_wa.php';
require __DIR__ . '/../../api/wallet/_helpers.php';
require_once __DIR__ . '/../../api/_audit.php';
admin_require_login();
if (!admin_es_admin() && !in_array(admin_wa_area(), ['streaming', 'ambas'], true)) {
  http_response_code(403);
  exit('Esta sección es del área de Streaming.');
}
$verSaldo = admin_es_admin();   // saldo/ajustes: solo dueño
$pdo = db();
$OWNER = (int) stream_owner_id();

// ── Export CSV / Plantilla ──
if (($_GET['export'] ?? '') === 'plantilla') {
  header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="plantilla-clientes.csv"');
  echo "\xEF\xBB\xBF"; $o = fopen('php://output', 'w'); fputcsv($o, ['Nombre', 'WhatsApp', 'Correo', 'Comentarios']); fputcsv($o, ['Juan Perez', '+584120000000', 'juan@mail.com', 'nota opcional']); fclose($o); exit;
}
if (($_GET['export'] ?? '') === 'csv') {
  header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="clientes.csv"');
  echo "\xEF\xBB\xBF"; $o = fopen('php://output', 'w'); fputcsv($o, ['Nombre', 'WhatsApp', 'Correo', 'Comentarios', 'Tipo', 'Fecha']);
  try { foreach ($pdo->query("SELECT nombre,wa,email,notas,tipo,creado_en FROM streaming_clientes WHERE owner_id=$OWNER ORDER BY nombre") as $r) fputcsv($o, [$r['nombre'], "\t" . $r['wa'], $r['email'], $r['notas'], $r['tipo'], $r['creado_en']]); } catch (Throwable $e) {}
  fclose($o); exit;
}

// ── AJAX: accesos (perfiles/ventas del cliente) ──
if (($_GET['ajax'] ?? '') === 'accesos') {
  header('Content-Type: application/json; charset=utf-8');
  $id = (int) ($_GET['id'] ?? 0);
  $v = $pdo->prepare("SELECT id, plataforma, perfil, fecha_vencimiento, precio, estado FROM streaming_ventas WHERE cliente_id=? AND owner_id=? ORDER BY fecha_vencimiento DESC");
  $v->execute([$id, $OWNER]);
  echo json_encode(['ok' => true, 'ventas' => $v->fetchAll(PDO::FETCH_ASSOC)]);
  exit;
}

// ── POST ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $a = $_POST['accion'] ?? '';
  $msg = '';
  try {
    $cid = (int) ($_POST['id'] ?? 0);
    // Guarda de aislamiento (anti-IDOR): un $cid existente DEBE pertenecer al dueño de este panel.
    // Protege de una vez TODOS los accesos por id que siguen (WHERE id=$cid).
    if ($cid > 0) {
      $ownCli = $pdo->query("SELECT owner_id FROM streaming_clientes WHERE id=$cid")->fetchColumn();
      if ($ownCli === false || (int) $ownCli !== $OWNER) throw new Exception('Cliente no encontrado.');
    }
    if ($a === 'cliente_save') {
      $vals = [trim((string) ($_POST['nombre'] ?? '')), wa_norm((string) ($_POST['wa'] ?? '')) ?: null, trim((string) ($_POST['email'] ?? '')) ?: null, trim((string) ($_POST['notas'] ?? '')) ?: null];
      if ($vals[0] === '') throw new Exception('El nombre es obligatorio.');
      if ($cid) $pdo->prepare("UPDATE streaming_clientes SET nombre=?,wa=?,email=?,notas=? WHERE id=? AND owner_id=?")->execute([...$vals, $cid, $OWNER]);
      else { $pdo->prepare("INSERT INTO streaming_clientes (owner_id,nombre,wa,email,notas) VALUES (?,?,?,?,?)")->execute([$OWNER, ...$vals]); $cid = (int) $pdo->lastInsertId(); }
      // ── SEGURIDAD: solo el DUEÑO marca/desmarca a alguien como distribuidor (= revendedor) ──
      // Ese campo otorga es_revendedor en la cuenta web (precios mayoristas, panel y billetera). Sin
      // este candado, un empleado del área se lo daba a SÍ MISMO, o se lo quitaba a un revendedor real.
      if ($verSaldo) {
        $prevTipo = (string) ($pdo->query("SELECT tipo FROM streaming_clientes WHERE id=$cid")->fetchColumn() ?: 'cliente');
        $tipo = ($_POST['tipo'] ?? 'cliente') === 'distribuidor' ? 'distribuidor' : 'cliente';
        try { $pdo->prepare("UPDATE streaming_clientes SET tipo=? WHERE id=?")->execute([$tipo, $cid]); } catch (Throwable $e) {}
        // Sincroniza es_revendedor en la cuenta web enlazada (si tiene)
        $uid = (int) ($pdo->query("SELECT usuario_id FROM streaming_clientes WHERE id=$cid")->fetchColumn() ?: 0);
        if ($uid) { try { $pdo->prepare("UPDATE usuarios SET es_revendedor=? WHERE id=?")->execute([$tipo === 'distribuidor' ? 1 : 0, $uid]); } catch (Throwable $e) {} }
        if ($tipo !== $prevTipo) { try { audit_log($pdo, 'stream_tipo_cliente', 'streaming_cliente', $cid, ($tipo === 'distribuidor' ? 'Marcado DISTRIBUIDOR (otorga es_revendedor)' : 'Quitado distribuidor (revoca es_revendedor)') . ($uid ? ' · usuario #' . $uid : ' · sin cuenta web'), true); } catch (Throwable $e) {} }
      }
      $msg = '✓ Cliente guardado.';
    }
    elseif ($a === 'cliente_del') { $pdo->prepare("DELETE FROM streaming_clientes WHERE id=? AND owner_id=?")->execute([$cid, $OWNER]); $msg = '✓ Cliente eliminado.'; }
    elseif ($a === 'recordatorios') { $pdo->prepare("UPDATE streaming_clientes SET recordatorios_auto = 1 - COALESCE(recordatorios_auto,1) WHERE id=? AND owner_id=?")->execute([$cid, $OWNER]); $msg = '✓ Recordatorios actualizados.'; }
    elseif ($a === 'clientes_masivo') {
      $exist = [];
      try { foreach ($pdo->query("SELECT wa FROM streaming_clientes WHERE owner_id=$OWNER AND wa IS NOT NULL AND wa<>''") as $r) $exist[$r['wa']] = true; } catch (Throwable $e) {}
      $ins = $pdo->prepare("INSERT INTO streaming_clientes (owner_id,nombre,wa,email,notas) VALUES ($OWNER,?,?,?,?)");
      $n = 0; $skip = 0;
      foreach (preg_split('/\r?\n/', (string) ($_POST['datos'] ?? '')) as $ln) {
        $ln = trim($ln); if ($ln === '') continue;
        $p = preg_split('/\s*[,;\t|]\s*/', $ln);
        $nom = trim($p[0] ?? ''); if ($nom === '') continue;
        $wa = isset($p[1]) ? (wa_norm($p[1]) ?: null) : null;
        if ($wa && isset($exist[$wa])) { $skip++; continue; }
        $ins->execute([$nom, $wa, isset($p[2]) ? (trim($p[2]) ?: null) : null, isset($p[3]) ? (trim($p[3]) ?: null) : null]);
        if ($wa) $exist[$wa] = true;
        if (++$n >= 3000) break;
      }
      $msg = "✓ $n cliente(s) importados" . ($skip ? " · $skip ya existían" : "") . ".";
    }
    elseif ($a === 'activar_web') {
      $cl = $pdo->query("SELECT * FROM streaming_clientes WHERE id=$cid")->fetch(PDO::FETCH_ASSOC);
      if (!$cl) throw new Exception('Cliente no encontrado.');
      if (!empty($cl['usuario_id'])) throw new Exception('Ya tiene cuenta web.');
      $email = trim((string) ($_POST['email'] ?? '')) ?: (string) $cl['email'];
      if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new Exception('Necesita un correo válido para la cuenta web.');
      $ex = $pdo->prepare("SELECT id, es_admin, rol_admin, es_revendedor, vcoins_saldo FROM usuarios WHERE email=?"); $ex->execute([$email]); $exr = $ex->fetch(PDO::FETCH_ASSOC) ?: null; $exu = (int) ($exr['id'] ?? 0);
      if ($exu) {
        // ── SEGURIDAD: un empleado NO puede secuestrar una cuenta ajena vía enlazar + reset_pass ──
        // Nunca enlazar a una cuenta de personal/administración (sería toma de cuenta del dueño), ni a
        // una cuenta con saldo/revendedor salvo que sea el dueño quien lo hace (evita robo de billetera).
        $esStaff   = ((int) ($exr['es_admin'] ?? 0) === 1) || !empty($exr['rol_admin']);
        $tieneValor = ((float) ($exr['vcoins_saldo'] ?? 0) > 0) || ((int) ($exr['es_revendedor'] ?? 0) === 1);
        if ($esStaff) throw new Exception('Ese correo pertenece a una cuenta de personal/administración; no se puede enlazar.');
        if ($tieneValor && !admin_es_admin()) throw new Exception('Esa cuenta ya tiene saldo o privilegios — solo el dueño puede enlazarla.');
        $uid = $exu; $msg = '✓ Cliente enlazado a la cuenta web existente. ';
      }
      else {
        $pass = trim((string) ($_POST['password'] ?? '')) ?: substr(bin2hex(random_bytes(4)), 0, 8);
        $pdo->prepare("INSERT INTO usuarios (email,password_hash,nombre,telefono) VALUES (?,?,?,?)")
            ->execute([$email, password_hash($pass, PASSWORD_DEFAULT), $cl['nombre'], $cl['wa'] ?: null]);
        $uid = (int) $pdo->lastInsertId();
        $msg = '✓ Cuenta web creada · Clave: ' . $pass . ' (anótala). ';
      }
      $pdo->prepare("UPDATE streaming_clientes SET usuario_id=?, email=COALESCE(email,?) WHERE id=?")->execute([$uid, $email, $cid]);
    }
    elseif ($a === 'reset_pass') {
      $uid = (int) ($pdo->query("SELECT usuario_id FROM streaming_clientes WHERE id=$cid")->fetchColumn() ?: 0);
      if (!$uid) throw new Exception('Este cliente no tiene cuenta web (actívala primero).');
      // ── SEGURIDAD: nunca resetear la clave de una cuenta de personal/administración desde aquí ──
      // (cerraría el vector de toma de cuenta del dueño). El staff se gestiona en usuarios/empleados.
      $tg = $pdo->prepare("SELECT es_admin, rol_admin, es_revendedor, vcoins_saldo FROM usuarios WHERE id=?"); $tg->execute([$uid]); $tgr = $tg->fetch(PDO::FETCH_ASSOC) ?: [];
      if (((int) ($tgr['es_admin'] ?? 0) === 1) || !empty($tgr['rol_admin'])) throw new Exception('Esa cuenta es de personal/administración; cámbiale la clave desde Usuarios/Empleados.');
      // ── SEGURIDAD: tampoco la de un REVENDEDOR ni una cuenta con saldo ──
      // Cambiarle la clave = entrar a su panel y gastarle sus vcoins reales. Mismo criterio que ya
      // aplica 'activar_web' (:92-95); aquí faltaba y era el hueco.
      $tieneValor = ((float) ($tgr['vcoins_saldo'] ?? 0) > 0) || ((int) ($tgr['es_revendedor'] ?? 0) === 1);
      if ($tieneValor && !admin_es_admin()) throw new Exception('Esa cuenta es de un revendedor o tiene saldo — solo el dueño puede cambiarle la clave.');
      $p1 = (string) ($_POST['password'] ?? '');
      if (strlen($p1) < 6) throw new Exception('La clave debe tener al menos 6 caracteres.');
      $pdo->prepare("UPDATE usuarios SET password_hash=? WHERE id=?")->execute([password_hash($p1, PASSWORD_DEFAULT), $uid]);
      try { $pdo->prepare("DELETE FROM user_sessions WHERE data LIKE ?")->execute(['%user_id|i:' . $uid . ';%']); } catch (Throwable $e) {}
      try { audit_log($pdo, 'stream_reset_pass', 'usuario', $uid, 'Reset de clave de cuenta web del cliente #' . $cid, true); } catch (Throwable $e) {}
      $msg = '✓ Contraseña actualizada.';
    }
    elseif ($a === 'ajuste_saldo' && $verSaldo) {
      $uid = (int) ($pdo->query("SELECT usuario_id FROM streaming_clientes WHERE id=$cid")->fetchColumn() ?: 0);
      if (!$uid) throw new Exception('Este cliente no tiene cuenta web (actívala primero).');
      $monto = abs((float) ($_POST['monto'] ?? 0));
      if ($monto <= 0) throw new Exception('Monto inválido.');
      $motivo = trim((string) ($_POST['motivo'] ?? '')) ?: 'Ajuste manual (admin)';
      $esDism = ($_POST['tipo_ajuste'] ?? 'aumentar') === 'disminuir';
      if ($esDism) {
        if (!wallet_debitar($pdo, $uid, $monto, 'ajuste', $motivo)) throw new Exception('Saldo insuficiente para descontar.');
        $msg = '✓ Saldo disminuido $' . number_format($monto, 2) . '.';
      } else { wallet_acreditar($pdo, $uid, $monto, 'ajuste', $motivo); $msg = '✓ Saldo aumentado $' . number_format($monto, 2) . '.'; }
      try { audit_log($pdo, 'stream_ajuste_saldo', 'usuario', $uid, ($esDism ? '-' : '+') . '$' . number_format($monto, 2) . ' vcoins · ' . $motivo . ' (cliente #' . $cid . ')', true); } catch (Throwable $e) {}
    }
  } catch (Throwable $e) { $msg = '⚠ ' . $e->getMessage(); }
  header('Location: clientes.php?msg=' . urlencode($msg));
  exit;
}
$flash = (string) ($_GET['msg'] ?? '');

$clientes = $pdo->query("SELECT cl.id, cl.nombre, cl.wa, cl.email, cl.notas, cl.tipo, cl.recordatorios_auto, cl.usuario_id, cl.creado_en,
    COALESCE(u.saldo, 0) AS saldo,
    (SELECT COUNT(*) FROM streaming_ventas v WHERE v.cliente_id=cl.id) AS ventas_n
  FROM streaming_clientes cl LEFT JOIN usuarios u ON u.id=cl.usuario_id
  WHERE cl.owner_id=$OWNER
  ORDER BY cl.creado_en DESC, cl.id DESC LIMIT 3000")->fetchAll(PDO::FETCH_ASSOC);

stream_head('Clientes', 'clientes');
?>
<style>
  /* Ajustes de presentación propios de esta página (menú de acciones + modales) */
  .search-wrap{position:relative;margin-left:auto;max-width:300px;width:100%}
  .search-wrap [data-lucide]{position:absolute;left:11px;top:50%;transform:translateY(-50%);width:15px;height:15px;color:var(--faint)}
  .search-wrap input{padding-left:32px}
  .actbtn{width:32px;height:32px;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--muted);display:inline-grid;place-items:center;cursor:pointer;transition:.12s}
  .actbtn:hover{border-color:var(--border-strong);color:var(--accent)}.actbtn [data-lucide]{width:16px;height:16px}
  .walink{display:inline-flex;align-items:center;gap:6px;color:#16a34a;font-weight:600}
  .walink [data-lucide]{width:14px;height:14px}
  /* Menú contextual de 3 puntos */
  .menu3{background:var(--surface);border:1px solid var(--border);border-radius:12px;box-shadow:var(--shadow);padding:6px;width:224px;font-size:13px}
  .menu3 button{width:100%;text-align:left;padding:8px 10px;border-radius:8px;display:flex;align-items:center;gap:10px;color:var(--text);background:none;border:0;cursor:pointer;font-size:12.8px;font-family:inherit;font-weight:550}
  .menu3 button:hover{background:var(--surface-2)}
  .menu3 button [data-lucide]{width:16px;height:16px;flex:0 0 auto}
  .menu3 .sep{height:1px;background:var(--border);margin:5px 4px}
  /* Modales */
  #overlay{background:rgba(6,9,16,.55)}
  .mcard{background:var(--surface);border:1px solid var(--border);border-radius:15px;box-shadow:0 24px 60px -24px rgba(0,0,0,.5);width:100%;overflow:hidden}
  .mhd{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:15px 18px;border-bottom:1px solid var(--border)}
  .mhd h3{margin:0;font-size:15px;font-weight:730;display:flex;align-items:center;gap:9px}
  .mhd h3 [data-lucide]{width:18px;height:18px;color:var(--accent)}
  .mx{width:32px;height:32px;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--muted);display:grid;place-items:center;cursor:pointer;transition:.12s}
  .mx:hover{border-color:var(--border-strong);color:var(--text)}.mx [data-lucide]{width:17px;height:17px}
  .mbody{padding:18px}
  .mfoot{display:flex;justify-content:flex-end;gap:8px;margin-top:6px}
  .msub{color:var(--muted);font-size:12.5px;margin:0 0 4px}
  .amt-in{display:flex}
  .amt-in span{display:grid;place-items:center;padding:0 12px;background:var(--surface-2);border:1px solid var(--border);border-right:0;border-radius:9px 0 0 9px;color:var(--muted);font-weight:700}
  .amt-in input{border-radius:0 9px 9px 0}
  .warn-circle{width:56px;height:56px;border-radius:50%;background:var(--bad-soft);color:var(--bad);display:grid;place-items:center;margin:0 auto 12px}
  .warn-circle [data-lucide]{width:26px;height:26px}
</style>

<?php if ($flash): $flashMal = (mb_substr(trim((string) $flash), 0, 1) === '⚠'); ?><div class="banner" style="margin-bottom:16px<?= $flashMal ? ';background:var(--bad-soft);color:var(--bad);border-color:var(--bad)' : '' ?>"><i data-lucide="<?= $flashMal ? 'alert-triangle' : 'check-circle' ?>"></i><span><?= h($flash) ?></span></div><?php endif; ?>

<div class="pagehd">
  <div>
    <h1>Gestión de <span class="nm">Clientes</span></h1>
    <p>Administra tu base de clientes, contactos y tipo de usuario.</p>
  </div>
  <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
    <button onclick="abrirNuevo()" class="btn primary"><i data-lucide="user-plus"></i> Nuevo Cliente</button>
    <a href="?export=plantilla" class="btn ghost"><i data-lucide="file-down"></i> Plantilla</a>
    <button onclick="abrir('m-masivo')" class="btn ghost"><i data-lucide="upload-cloud"></i> Carga Masiva</button>
    <a href="?export=csv" class="btn ghost"><i data-lucide="file-spreadsheet"></i> Exportar</a>
  </div>
</div>

<div class="card">
  <div class="card-hd">
    <i data-lucide="users"></i>
    <h2><?= count($clientes) ?> clientes</h2>
    <div class="search-wrap">
      <i data-lucide="search"></i>
      <input id="buscar" class="input" placeholder="Buscar nombre, WhatsApp, correo…">
    </div>
  </div>
  <div class="thin" style="overflow-x:auto">
    <table class="dtable">
      <thead><tr>
        <th>Nombre</th><th>WhatsApp</th><th>Correo</th>
        <th>Comentarios</th><th>Fecha</th><th>Tipo</th><th>Recordatorios</th>
        <?php if ($verSaldo): ?><th>Saldo</th><?php endif; ?><th style="text-align:center">Acciones</th>
      </tr></thead>
      <tbody id="tbody">
      <?php foreach ($clientes as $c):
        $tipo = ($c['tipo'] ?? 'cliente') === 'distribuidor';
        $rec = (int) ($c['recordatorios_auto'] ?? 1);
        $busca = mb_strtolower(($c['nombre'] ?? '') . ' ' . ($c['wa'] ?? '') . ' ' . ($c['email'] ?? ''));
        $json = h(json_encode(['id' => $c['id'], 'nombre' => $c['nombre'], 'wa' => $c['wa'], 'email' => $c['email'], 'notas' => $c['notas'], 'tipo' => $c['tipo'], 'web' => (int) (bool) $c['usuario_id']], JSON_UNESCAPED_UNICODE));
      ?>
        <tr data-b="<?= h($busca) ?>">
          <td style="font-weight:600"><?= h($c['nombre']) ?><?php if ($c['usuario_id']): ?> <i data-lucide="globe" style="width:13px;height:13px;display:inline;vertical-align:-1px;color:var(--accent)" title="Tiene cuenta web"></i><?php endif; ?></td>
          <td><?php if ($c['wa']): ?><a href="https://wa.me/<?= h($c['wa']) ?>" target="_blank" class="walink"><i data-lucide="message-circle"></i><?= h($c['wa']) ?></a><?php else: ?><span style="color:var(--faint)">—</span><?php endif; ?></td>
          <td style="color:var(--muted)"><?= h($c['email'] ?: '—') ?></td>
          <td style="color:var(--faint);max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= h($c['notas'] ?: '') ?></td>
          <td style="color:var(--muted);white-space:nowrap"><?= !empty($c['creado_en']) ? date('d/m/Y', strtotime($c['creado_en'])) : '—' ?></td>
          <td><?php if ($tipo): ?><span class="pill acc">Distribuidor</span><?php else: ?><span class="tag">Cliente</span><?php endif; ?></td>
          <td><?php if ($rec): ?><span class="pill ok">Activo</span><?php else: ?><span class="tag">Excluido</span><?php endif; ?></td>
          <?php if ($verSaldo): ?><td class="tnum"><?php if ($c['usuario_id']): ?><?php if (($c['saldo'] ?? 0) > 0): ?><span class="amt">$<?= number_format((float) ($c['saldo'] ?? 0), 2) ?></span><?php else: ?><span style="color:var(--faint)">$<?= number_format((float) ($c['saldo'] ?? 0), 2) ?></span><?php endif; ?><?php else: ?><span style="color:var(--faint)">—</span><?php endif; ?></td><?php endif; ?>
          <td style="text-align:center"><button onclick='menu(event,<?= $json ?>)' class="actbtn"><i data-lucide="more-vertical"></i></button></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php if (!$clientes): ?><div class="empty">Sin clientes todavía.</div><?php endif; ?>
  </div>
</div>

<!-- Menú 3 puntos -->
<div id="menu3" class="hidden fixed z-50 menu3">
  <button onclick="mEditar()"><i data-lucide="pencil" style="color:var(--muted)"></i> Editar</button>
  <button onclick="mAccesos()"><i data-lucide="eye" style="color:var(--accent)"></i> Ver accesos</button>
  <button onclick="mRecordatorios()"><i data-lucide="bell-off" style="color:var(--good)"></i> Excluir/incluir recordatorios</button>
  <button onclick="mWeb()"><i data-lucide="user-check" style="color:var(--accent)"></i> Activar Usuario Web</button>
  <button onclick="mPass()"><i data-lucide="key" style="color:var(--warn)"></i> Reset contraseña</button>
  <?php if ($verSaldo): ?><button onclick="mSaldo()"><i data-lucide="wallet" style="color:var(--accent)"></i> Agregar/Disminuir saldo</button><?php endif; ?>
  <div class="sep"></div>
  <button onclick="mDel()" style="color:var(--bad)"><i data-lucide="trash-2" style="color:var(--bad)"></i> Eliminar</button>
</div>

<div id="overlay" class="fixed inset-0 z-40 hidden items-start justify-center overflow-y-auto p-4" onclick="if(event.target===this)cerrar()">
  <!-- Nuevo/Editar -->
  <div id="m-cli" class="hidden mcard my-8" style="max-width:28rem">
    <div class="mhd"><h3><i data-lucide="user-plus"></i> <span id="c-title">Agregar Nuevo Cliente</span></h3><button onclick="cerrar()" class="mx"><i data-lucide="x"></i></button></div>
    <form method="post" class="mbody"><input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="accion" value="cliente_save"><input type="hidden" name="id" id="c-id">
      <div class="field"><label>Nombre</label><input name="nombre" id="c-nombre" required class="input"></div>
      <div class="field"><label>WhatsApp</label><input name="wa" id="c-wa" class="input" placeholder="+58 412…"></div>
      <div class="field"><label>Correo</label><input name="email" id="c-email" class="input"></div>
      <div class="field"><label>Comentarios</label><textarea name="notas" id="c-notas" rows="2" class="input"></textarea></div>
      <div class="field"><label>Tipo de usuario<?= $verSaldo ? '' : ' <span style="color:var(--faint);font-size:11px">· solo el dueño</span>' ?></label><select name="tipo" id="c-tipo" class="input"<?= $verSaldo ? '' : ' disabled' ?>><option value="cliente">Cliente</option><option value="distribuidor">Distribuidor</option></select></div>
      <div class="mfoot"><button type="button" onclick="cerrar()" class="btn ghost">Cancelar</button><button class="btn primary">Guardar Cliente</button></div>
    </form>
  </div>
  <!-- Ver accesos -->
  <div id="m-accesos" class="hidden mcard my-8" style="max-width:32rem">
    <div class="mhd"><h3><i data-lucide="eye"></i> Perfiles asignados · <span id="ac-nombre"></span></h3><button onclick="cerrar()" class="mx"><i data-lucide="x"></i></button></div>
    <div id="ac-body" class="mbody thin" style="max-height:24rem;overflow-y:auto;font-size:13px">Cargando…</div>
    <div class="mbody" style="padding-top:0"><div class="mfoot" style="margin-top:0"><button onclick="cerrar()" class="btn primary">Cerrar</button></div></div>
  </div>
  <!-- Activar web -->
  <div id="m-web" class="hidden mcard my-8" style="max-width:28rem">
    <div class="mhd"><h3><i data-lucide="user-check"></i> Activar Usuario Web</h3><button onclick="cerrar()" class="mx"><i data-lucide="x"></i></button></div>
    <form method="post" class="mbody"><input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="accion" value="activar_web"><input type="hidden" name="id" id="w-id">
      <p class="msub" style="margin-bottom:12px">Le crea una cuenta web (login + billetera) al cliente.</p>
      <div class="field"><label>Correo (login)</label><input name="email" id="w-email" type="email" required class="input"></div>
      <div class="field"><label>Clave (vacío = generar)</label><input id="w-pass" name="password" class="input" placeholder="Se genera una si lo dejas vacío"></div>
      <div class="mfoot"><button type="button" onclick="cerrar()" class="btn ghost">Cancelar</button><button class="btn primary">Activar</button></div>
    </form>
  </div>
  <!-- Reset pass -->
  <div id="m-pass" class="hidden mcard my-8" style="max-width:28rem">
    <div class="mhd"><h3><i data-lucide="key"></i> Cambiar Contraseña</h3><button onclick="cerrar()" class="mx"><i data-lucide="x"></i></button></div>
    <form method="post" class="mbody"><input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="accion" value="reset_pass"><input type="hidden" name="id" id="pw-id">
      <div class="field"><label>Nueva contraseña</label><input id="pw-pass" name="password" type="text" minlength="6" required class="input"><span style="font-size:11px;color:var(--faint)">Mínimo 6 caracteres.</span></div>
      <div class="mfoot"><button type="button" onclick="cerrar()" class="btn ghost">Cancelar</button><button class="btn primary">Guardar contraseña</button></div>
    </form>
  </div>
  <!-- Saldo -->
  <?php if ($verSaldo): ?>
  <div id="m-saldo" class="hidden mcard my-8" style="max-width:28rem">
    <div class="mhd"><h3><i data-lucide="wallet"></i> Gestionar Saldo</h3><button onclick="cerrar()" class="mx"><i data-lucide="x"></i></button></div>
    <form method="post" class="mbody"><input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="accion" value="ajuste_saldo"><input type="hidden" name="id" id="s-id">
      <p class="msub" style="text-align:center;margin-bottom:12px">Cliente <strong id="s-nombre" style="color:var(--text);display:block;font-size:15px"></strong></p>
      <div class="field"><label>Tipo de ajuste</label><select name="tipo_ajuste" class="input"><option value="aumentar">Aumentar saldo</option><option value="disminuir">Disminuir saldo</option></select></div>
      <div class="field"><label>Monto del ajuste</label><div class="amt-in"><span>$</span><input id="s-monto" name="monto" type="number" step="0.01" min="0" required class="input"></div></div>
      <div class="field"><label>Motivo</label><input id="s-motivo" name="motivo" class="input" placeholder="Opcional"></div>
      <div class="mfoot"><button type="button" onclick="cerrar()" class="btn ghost">Cancelar</button><button class="btn primary">Guardar Ajuste</button></div>
    </form>
  </div>
  <?php endif; ?>
  <!-- Eliminar -->
  <div id="m-del" class="hidden mcard my-8" style="max-width:24rem">
    <div class="mbody" style="text-align:center">
      <div class="warn-circle"><i data-lucide="trash-2"></i></div>
      <h3 style="margin:0;font-size:17px;font-weight:730">¿Eliminar este cliente?</h3>
      <p style="color:var(--faint);font-size:12px;margin:6px 0 18px">No se puede deshacer (no borra sus ventas).</p>
      <form method="post" style="display:flex;justify-content:center;gap:8px"><input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="accion" value="cliente_del"><input type="hidden" name="id" id="d-id">
        <button class="btn danger">Sí, eliminar</button><button type="button" onclick="cerrar()" class="btn ghost">Cancelar</button></form>
    </div>
  </div>
  <!-- Carga masiva -->
  <div id="m-masivo" class="hidden mcard my-8" style="max-width:28rem">
    <div class="mhd"><h3><i data-lucide="upload-cloud"></i> Carga Masiva</h3><button onclick="cerrar()" class="mx"><i data-lucide="x"></i></button></div>
    <form method="post" class="mbody"><input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="accion" value="clientes_masivo">
      <p class="msub" style="margin-bottom:10px">Una línea por cliente: <code style="background:var(--surface-2);border:1px solid var(--border);border-radius:5px;padding:1px 5px;font-size:11.5px">Nombre, WhatsApp, Correo, Comentarios</code></p>
      <textarea name="datos" rows="8" class="input" style="font-family:ui-monospace,SFMono-Regular,Menlo,monospace"></textarea>
      <div class="mfoot"><button type="button" onclick="cerrar()" class="btn ghost">Cancelar</button><button class="btn primary">Importar</button></div>
    </form>
  </div>
</div>

<script>
  let C={};
  // Escapar HTML antes de meterlo en innerHTML (los campos plataforma/perfil son texto libre del
  // admin; sin esto, una etiqueta de perfil con <img onerror=...> ejecutaba JS en la sesión del dueño).
  const esc=s=>String(s==null?'':s).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const ov=document.getElementById('overlay'), m3=document.getElementById('menu3');
  function cerrar(){ ov.classList.add('hidden'); ov.classList.remove('flex'); document.querySelectorAll('#overlay > div').forEach(m=>m.classList.add('hidden')); }
  function abrir(id){ ov.classList.remove('hidden'); ov.classList.add('flex'); document.querySelectorAll('#overlay > div').forEach(m=>m.classList.add('hidden')); document.getElementById(id).classList.remove('hidden'); lucide.createIcons(); }
  // Mismo arreglo que en ventas.php: si no cabe abajo (últimas filas), el menú se abre hacia ARRIBA
  // en vez de quedar fuera de la pantalla.
  function menu(e,c){
    e.stopPropagation(); C=c;
    const r=e.currentTarget.getBoundingClientRect();
    m3.classList.remove('hidden'); lucide.createIcons();   // medir con el menú visible y con iconos
    const h=m3.offsetHeight, w=m3.offsetWidth||224;
    let top=(window.innerHeight-r.bottom >= h+8) ? r.bottom+4 : r.top-h-4;
    m3.style.top =Math.max(8, Math.min(top, window.innerHeight-h-8))+'px';
    m3.style.left=Math.max(8, Math.min(r.right-w, window.innerWidth-w-8))+'px';
  }
  document.addEventListener('click',e=>{ if(!m3.contains(e.target)) m3.classList.add('hidden'); });
  function abrirNuevo(){ C={}; document.getElementById('c-title').textContent='Agregar Nuevo Cliente'; ['c-id','c-nombre','c-wa','c-email','c-notas'].forEach(i=>document.getElementById(i).value=''); document.getElementById('c-tipo').value='cliente'; abrir('m-cli'); }
  function mEditar(){ m3.classList.add('hidden'); document.getElementById('c-title').textContent='Editar Cliente'; document.getElementById('c-id').value=C.id; document.getElementById('c-nombre').value=C.nombre||''; document.getElementById('c-wa').value=C.wa||''; document.getElementById('c-email').value=C.email||''; document.getElementById('c-notas').value=C.notas||''; document.getElementById('c-tipo').value=C.tipo||'cliente'; abrir('m-cli'); }
  async function mAccesos(){ m3.classList.add('hidden'); document.getElementById('ac-nombre').textContent=C.nombre||''; abrir('m-accesos'); const b=document.getElementById('ac-body'); b.innerHTML='Cargando…'; const r=await fetch('?ajax=accesos&id='+C.id).then(x=>x.json()); b.innerHTML=r.ventas&&r.ventas.length? r.ventas.map(v=>{ const d=v.fecha_vencimiento?Math.ceil((new Date(v.fecha_vencimiento)-new Date())/86400000):null; const badge=d===null?'':`<span class="pill ${d<0?'err':d<=5?'wait':'ok'}">${d<0?'Vencido':d+'d'}</span>`; return `<div class="lrow" style="border:1px solid var(--border);border-radius:10px;margin-bottom:6px"><div class="l-main"><b>${esc(v.plataforma||'')}</b><span>${esc(v.perfil||'')}${v.fecha_vencimiento?' · '+(''+v.fecha_vencimiento).slice(0,10).split('-').reverse().join('/'):' · —'}</span></div><div style="text-align:right;display:flex;flex-direction:column;align-items:flex-end;gap:4px">${badge}<span class="amt">$${(parseFloat(v.precio)||0).toFixed(2)}</span></div></div>`; }).join('') : '<div class="empty">Este cliente no tiene perfiles asignados.</div>'; lucide.createIcons(); }
  function mRecordatorios(){ m3.classList.add('hidden'); post('recordatorios',{id:C.id}); }
  function mWeb(){ m3.classList.add('hidden'); if(C.web){ alert('Este cliente ya tiene cuenta web.'); return; } document.getElementById('w-id').value=C.id; document.getElementById('w-email').value=C.email||''; document.getElementById('w-pass').value=''; abrir('m-web'); }
  function mPass(){ m3.classList.add('hidden'); if(!C.web){ alert('Primero activa la cuenta web de este cliente.'); return; } document.getElementById('pw-id').value=C.id; document.getElementById('pw-pass').value=''; abrir('m-pass'); }
  function mSaldo(){ m3.classList.add('hidden'); if(!C.web){ alert('Primero activa la cuenta web (la billetera vive en la cuenta).'); return; } const s=document.getElementById('s-id'); if(s){ s.value=C.id; document.getElementById('s-nombre').textContent=C.nombre||''; document.getElementById('s-monto').value=''; document.getElementById('s-motivo').value=''; document.querySelector('#m-saldo [name=tipo_ajuste]').value='aumentar'; abrir('m-saldo'); } }
  function mDel(){ m3.classList.add('hidden'); document.getElementById('d-id').value=C.id; abrir('m-del'); }
  function post(accion,fields){ const f=document.createElement('form'); f.method='post'; f.innerHTML='<input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="accion" value="'+accion+'">'; for(const k in fields){ const i=document.createElement('input'); i.type='hidden'; i.name=k; i.value=fields[k]; f.appendChild(i);} document.body.appendChild(f); f.submit(); }
  document.getElementById('buscar')?.addEventListener('input',function(){ const q=this.value.toLowerCase().trim(); document.querySelectorAll('#tbody tr').forEach(tr=>tr.style.display=(!q||(tr.dataset.b||'').includes(q))?'':'none'); });
</script>
<?php stream_foot(); ?>
