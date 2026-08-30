<?php
/**
 * admin/stream/revendedores.php — Gestión de revendedores (solo admin/root):
 * ver saldo de cada revendedor, acreditar/ajustar saldo a mano, y aprobar recargas de saldo
 * pendientes (fallback de la pasarela). El listado de compras del revendedor sale de streaming_ventas.
 */
define('CONEC_ADMIN', true);
require __DIR__ . '/../_auth.php';
require __DIR__ . '/_layout.php';
require __DIR__ . '/../../api/wallet/_helpers.php';
admin_require_login();

// Solo el dueño de la TIENDA (contexto admin + admin/root). Un revendedor no gestiona a otros.
if (stream_ctx() !== 'admin' || !admin_es_admin()) {
    http_response_code(403);
    exit('Solo el administrador de la tienda puede gestionar revendedores.');
}
$pdo = db();

// ── AJAX: estadísticas de un revendedor (para el modal "Estadísticas") ──
if (($_GET['ajax'] ?? '') === 'stats') {
    header('Content-Type: application/json; charset=utf-8');
    $rid = (int) ($_GET['id'] ?? 0);
    $ok = false;
    try { $ok = (bool) $pdo->query("SELECT 1 FROM usuarios WHERE id=$rid AND rol='revendedor'")->fetchColumn(); } catch (Throwable $e) {}
    if (!$ok) { echo json_encode(['ok' => false]); exit; }
    $g = static function (string $sql) use ($pdo): float { try { return (float) ($pdo->query($sql)->fetchColumn() ?: 0); } catch (Throwable $e) { return 0.0; } };
    $ingresos  = $g("SELECT COALESCE(SUM(precio),0) FROM streaming_ventas WHERE owner_id=$rid AND estado<>'cancelada' AND ((cliente_nombre IS NOT NULL AND cliente_nombre<>'') OR COALESCE(cliente_id,0)>0)");
    $invertido = $g("SELECT COALESCE(SUM(costo),0) FROM streaming_cuentas WHERE owner_id=$rid AND costo>0");
    $st = [
        'ventas'    => (int) $g("SELECT COUNT(*) FROM streaming_ventas WHERE owner_id=$rid AND estado<>'cancelada'"),
        'activas'   => (int) $g("SELECT COUNT(*) FROM streaming_ventas WHERE owner_id=$rid AND estado='activa' AND fecha_vencimiento>=CURDATE()"),
        'vencidas'  => (int) $g("SELECT COUNT(*) FROM streaming_ventas WHERE owner_id=$rid AND (estado='vencida' OR (estado='activa' AND fecha_vencimiento<CURDATE()))"),
        'cuentas'   => (int) $g("SELECT COUNT(*) FROM streaming_cuentas WHERE owner_id=$rid"),
        'libres'    => (int) $g("SELECT COUNT(*) FROM streaming_perfiles p JOIN streaming_cuentas c ON c.id=p.cuenta_id WHERE c.owner_id=$rid AND p.estado='libre'"),
        'vendidos'  => (int) $g("SELECT COUNT(*) FROM streaming_perfiles p JOIN streaming_cuentas c ON c.id=p.cuenta_id WHERE c.owner_id=$rid AND p.estado='vendido'"),
        'saldo'     => $g("SELECT COALESCE(saldo,0) FROM usuarios WHERE id=$rid"),
        'ingresos'  => $ingresos,
        'invertido' => $invertido,
        'ganancia'  => $ingresos - $invertido,
    ];
    // Top de servicios que MÁS mueve (lo que más compra/vende) con su cantidad.
    $top = [];
    try {
        $tq = $pdo->query("SELECT plataforma, COUNT(*) AS n FROM streaming_ventas
                            WHERE owner_id=$rid AND estado<>'cancelada' AND plataforma IS NOT NULL AND plataforma<>''
                            GROUP BY plataforma ORDER BY n DESC, plataforma LIMIT 10");
        foreach ($tq->fetchAll(PDO::FETCH_ASSOC) as $t) { $top[] = ['plataforma' => (string) $t['plataforma'], 'n' => (int) $t['n']]; }
    } catch (Throwable $e) {}
    echo json_encode(['ok' => true, 'stats' => $st, 'top' => $top]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $a = trim((string) ($_POST['accion'] ?? ''));   // trim: evita que un espacio accidental deje 'acreditar ' sin match
    $msg = '';
    try {
        // ── Crear / aprobar revendedor (no requiere un revendedor previo) ──
        if ($a === 'crear_revendedor') {
            // Columna para el nombre de tienda del revendedor (se crea sola la 1ª vez).
            try { $pdo->exec("ALTER TABLE usuarios ADD COLUMN nombre_tienda VARCHAR(120) NULL"); } catch (Throwable $e) {}
            $email = strtolower(trim((string) ($_POST['email'] ?? '')));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('Correo inválido.');
            }
            // WhatsApp del revendedor: SOLO dígitos. Necesario para poder enviarle avisos.
            $telefono = preg_replace('/\D+/', '', (string) ($_POST['telefono'] ?? ''));
            $nombreTienda = trim((string) ($_POST['nombre_tienda'] ?? '')) ?: null;
            $ex = $pdo->prepare("SELECT id, rol FROM usuarios WHERE email=? LIMIT 1");
            $ex->execute([$email]);
            $exu = $ex->fetch(PDO::FETCH_ASSOC);
            if ($exu) {
                if (($exu['rol'] ?? '') === 'revendedor') {
                    throw new Exception('Ese usuario ya es revendedor.');
                }
                if (in_array($exu['rol'] ?? '', ['admin', 'root'], true)) {
                    throw new Exception('No puedes convertir a un administrador en revendedor.');
                }
                $pdo->prepare("UPDATE usuarios SET rol='revendedor' WHERE id=?")->execute([(int) $exu['id']]);
                // Si escribiste WhatsApp/tienda, se guardan (sin pisar lo que ya tenga si lo dejaste vacío).
                if ($telefono !== '') { try { $pdo->prepare("UPDATE usuarios SET telefono=? WHERE id=?")->execute([$telefono, (int) $exu['id']]); } catch (Throwable $e) {} }
                if ($nombreTienda !== null) { try { $pdo->prepare("UPDATE usuarios SET nombre_tienda=? WHERE id=?")->execute([$nombreTienda, (int) $exu['id']]); } catch (Throwable $e) {} }
                $msg = '✓ Usuario existente aprobado como revendedor.' . ($telefono === '' ? ' ⚠ No le pusiste WhatsApp: sin él no podrás enviarle avisos.' : '');
            } else {
                if ($telefono === '') throw new Exception('Ponle el WhatsApp del revendedor (es necesario para enviarle los avisos).');
                $nombre = trim((string) ($_POST['nombre'] ?? '')) ?: $email;
                $pass = trim((string) ($_POST['password'] ?? '')) ?: substr(bin2hex(random_bytes(5)), 0, 8);
                // username único a partir del correo.
                $base = preg_replace('/[^a-z0-9_]+/', '', strtolower(explode('@', $email)[0])) ?: 'rev';
                $username = $base; $i = 0;
                $uq = $pdo->prepare("SELECT 1 FROM usuarios WHERE username=? LIMIT 1");
                while (true) { $uq->execute([$username]); if (!$uq->fetch()) break; $username = $base . (++$i); }
                $pdo->prepare("INSERT INTO usuarios (username, password, nombre, email, telefono, rol) VALUES (?,?,?,?,?, 'revendedor')")
                    ->execute([$username, password_hash($pass, PASSWORD_DEFAULT), $nombre, $email, $telefono]);
                if ($nombreTienda !== null) { try { $pdo->prepare("UPDATE usuarios SET nombre_tienda=? WHERE email=?")->execute([$nombreTienda, $email]); } catch (Throwable $e) {} }
                $msg = '✓ Revendedor creado. Entra en la tienda → Iniciar sesión con el CORREO (no el usuario): ' . $email . ' · Clave: ' . $pass . ' · WhatsApp: ' . $telefono . ' — anótalos y pásaselos.';
            }
            header('Location: revendedores.php?msg=' . urlencode($msg));
            exit;
        }
        // Guardar config del BOT de códigos (URL + token). No requiere revendedor_id.
        if ($a === 'guardar_bot_codigos') {
            $url = trim((string) ($_POST['bot_url'] ?? ''));
            $tok = trim((string) ($_POST['bot_token'] ?? ''));
            try {
                // La tabla `configuracion` puede NO existir (o tener `clave` sin índice único) en el
                // server del cliente → el "ON DUPLICATE KEY" fallaba en silencio y el token salía
                // "sin configurar". La aseguramos y guardamos con SELECT→UPDATE/INSERT (no depende de
                // ningún índice único), y luego VERIFICAMOS releyendo para confirmar que quedó guardado.
                try { $pdo->exec("CREATE TABLE IF NOT EXISTS configuracion (clave VARCHAR(64) NOT NULL PRIMARY KEY, valor TEXT NULL)"); } catch (Throwable $e) {}
                foreach (['bot_codigos_url' => $url, 'bot_codigos_token' => $tok] as $k => $v) {
                    $ex = $pdo->prepare("SELECT COUNT(*) FROM configuracion WHERE clave=?");
                    $ex->execute([$k]);
                    if ((int) $ex->fetchColumn() > 0) {
                        $pdo->prepare("UPDATE configuracion SET valor=? WHERE clave=?")->execute([$v, $k]);
                    } else {
                        $pdo->prepare("INSERT INTO configuracion (clave, valor) VALUES (?, ?)")->execute([$k, $v]);
                    }
                }
                // Relee la URL directo de la BD (sin caché) para confirmar que de verdad persistió.
                $chk = $pdo->prepare("SELECT valor FROM configuracion WHERE clave='bot_codigos_url'");
                $chk->execute();
                $guardado = trim((string) ($chk->fetchColumn() ?: ''));
                if ($url === '') {
                    $msg = '✓ Bot de códigos desactivado (URL vacía).';
                } elseif ($guardado === $url) {
                    $msg = '✓ Bot de códigos configurado y VERIFICADO. Ya asigna/desasigna correos al comprar/vender/eliminar.';
                } else {
                    $msg = '⚠ Se guardó pero al releer la URL no coincide (en BD quedó: "' . mb_substr($guardado, 0, 60) . '"). Muéstrale esto al programador: revisar la tabla `configuracion`.';
                }
            } catch (Throwable $e) { $msg = '⚠ No se pudo guardar: ' . $e->getMessage(); }
            header('Location: revendedores.php?msg=' . urlencode($msg));
            exit;
        }
        // Probar la conexión al bot (POST de prueba) y mostrar la respuesta.
        if ($a === 'probar_bot') {
            if (function_exists('bot_codigos_probar')) {
                $r = bot_codigos_probar();
                if (!empty($r['ok'])) {
                    $msg = '✓ El bot respondió OK (HTTP ' . (int) $r['code'] . '). Respuesta: ' . mb_substr(trim((string) $r['body']), 0, 200);
                } else {
                    $det = $r['error'] ? (' · error: ' . $r['error']) : '';
                    $msg = '⚠ El bot NO respondió bien (HTTP ' . (int) $r['code'] . ')' . $det . '. Respuesta: ' . mb_substr(trim((string) $r['body']), 0, 200);
                }
            } else { $msg = '⚠ Falta el helper del bot.'; }
            header('Location: revendedores.php?msg=' . urlencode($msg));
            exit;
        }

        $rid = (int) ($_POST['revendedor_id'] ?? 0);
        // El objetivo DEBE ser un revendedor.
        $chk = $pdo->prepare("SELECT id FROM usuarios WHERE id=? AND rol='revendedor'");
        $chk->execute([$rid]);
        if (!$chk->fetch()) {
            throw new Exception('Ese usuario no es un revendedor.');
        }

        if ($a === 'quitar_revendedor') {
            // Revoca el acceso de revendedor (vuelve a 'usuario'). Su inventario/saldo se conserva.
            $pdo->prepare("UPDATE usuarios SET rol='usuario' WHERE id=? AND rol='revendedor'")->execute([$rid]);
            $msg = '✓ Acceso de revendedor removido (sus datos se conservan).';
        } elseif ($a === 'toggle_activo') {
            // PAUSAR sin borrar: rev_activo=0 → no puede operar; sus cuentas/ventas/saldo se conservan.
            try { st_rev_stock_schema($pdo); } catch (Throwable $e) {}
            $pdo->prepare("UPDATE usuarios SET rev_activo = CASE WHEN COALESCE(rev_activo,1)=1 THEN 0 ELSE 1 END WHERE id=? AND rol='revendedor'")->execute([$rid]);
            $na = (int) ($pdo->query("SELECT COALESCE(rev_activo,1) FROM usuarios WHERE id=$rid")->fetchColumn() ?: 0);
            $msg = $na ? '✓ Revendedor ACTIVADO (ya puede operar de nuevo).' : '⏸ Revendedor DESACTIVADO (en pausa; conserva todo, no puede operar).';
        } elseif ($a === 'toggle_export') {
            // Anti-secuestro: activar/desactivar que el revendedor pueda EXPORTAR/descargar su plantilla.
            try { st_rev_stock_schema($pdo); } catch (Throwable $e) {}
            $pdo->prepare("UPDATE usuarios SET rev_export = CASE WHEN COALESCE(rev_export,1)=1 THEN 0 ELSE 1 END WHERE id=? AND rol='revendedor'")->execute([$rid]);
            $ne = (int) ($pdo->query("SELECT COALESCE(rev_export,1) FROM usuarios WHERE id=$rid")->fetchColumn() ?: 0);
            $msg = $ne ? '✓ Exportar/descargar HABILITADO para el revendedor.' : '🔒 Exportar/descargar BLOQUEADO para el revendedor (no puede llevarse sus datos).';
        } elseif ($a === 'editar_wa_revendedor') {
            // Setear/actualizar el WhatsApp de un revendedor ya creado (para poder enviarle avisos).
            $tel = preg_replace('/\D+/', '', (string) ($_POST['telefono'] ?? ''));
            $pdo->prepare("UPDATE usuarios SET telefono=? WHERE id=? AND rol='revendedor'")->execute([$tel ?: null, $rid]);
            $msg = $tel !== '' ? '✓ WhatsApp del revendedor guardado.' : '✓ WhatsApp borrado.';
        } elseif ($a === 'acreditar' || $a === 'debitar') {
            $monto = round(abs((float) ($_POST['monto'] ?? 0)), 2);
            if ($monto <= 0) {
                throw new Exception('Monto inválido.');
            }
            $motivo = trim((string) ($_POST['motivo'] ?? '')) ?: 'Ajuste manual (admin)';
            if ($a === 'acreditar') {
                wallet_acreditar($pdo, $rid, $monto, 'ajuste_admin', $motivo);
                $nuevo = wallet_saldo($pdo, $rid);
                $msg = '✓ Saldo acreditado: $' . number_format($monto, 2) . '. Nuevo saldo del revendedor: $' . number_format($nuevo, 2) . '.';
                // Correo de saldo acreditado (agregado): fuera de cualquier transacción, el crédito ya
                // es real.
                // BUG CORREGIDO (2026-08-30): el require_once estaba DENTRO del function_exists(), así
                // que la condición siempre daba falso (la función nunca llegaba a existir para probarse)
                // y el correo no se mandaba NUNCA. El require va primero, sin condición.
                require_once __DIR__ . '/../../includes/stream_mailer.php';
                try { stream_email_saldo_acreditado($pdo, $rid, ['monto' => $monto, 'motivo' => $motivo, 'metodo' => 'Ajuste manual']); } catch (Throwable $e) {}
            } else {
                if (!wallet_debitar($pdo, $rid, $monto, 'ajuste_admin', $motivo)) {
                    throw new Exception('El revendedor no tiene saldo suficiente para descontar.');
                }
                $msg = '✓ Saldo descontado: $' . number_format($monto, 2) . '.';
            }
        } elseif ($a === 'aprobar_recarga') {
            $recId = (int) ($_POST['recarga_id'] ?? 0);
            $rec = $pdo->prepare("SELECT * FROM wallet_recargas WHERE id=? AND usuario_id=? AND estado='pendiente'");
            $rec->execute([$recId, $rid]);
            $r = $rec->fetch(PDO::FETCH_ASSOC);
            if (!$r) {
                throw new Exception('Recarga no encontrada o ya resuelta.');
            }
            $pdo->beginTransaction();
            try {
                wallet_acreditar($pdo, $rid, (float) $r['monto'], 'recarga', 'Recarga de saldo aprobada' . ($r['referencia'] ? ' · ref ' . $r['referencia'] : ''));
                $pdo->prepare("UPDATE wallet_recargas SET estado='aprobada', resuelto_en=NOW() WHERE id=?")->execute([$recId]);
                $pdo->commit();
                $msg = '✓ Recarga aprobada y saldo acreditado.';
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                throw $e;
            }
            // Correo de saldo acreditado (agregado): SIEMPRE después del commit.
            // BUG CORREGIDO (2026-08-30): mismo error que en 'acreditar' — el require_once estaba
            // atrapado dentro de un function_exists() que nunca podía dar verdadero.
            require_once __DIR__ . '/../../includes/stream_mailer.php';
            try { stream_email_saldo_acreditado($pdo, $rid, ['monto' => (float) $r['monto'], 'metodo' => (string) ($r['metodo'] ?? ''), 'referencia' => (string) ($r['referencia'] ?? ''), 'motivo' => 'Recarga aprobada por el administrador']); } catch (Throwable $e) {}
        } elseif ($a === 'rechazar_recarga') {
            $recId = (int) ($_POST['recarga_id'] ?? 0);
            $pdo->prepare("UPDATE wallet_recargas SET estado='rechazada', resuelto_en=NOW() WHERE id=? AND usuario_id=? AND estado='pendiente'")->execute([$recId, $rid]);
            $msg = '✓ Recarga rechazada.';
        }
    } catch (Throwable $e) {
        $msg = '⚠ ' . $e->getMessage();
    }
    header('Location: revendedores.php?msg=' . urlencode($msg));
    exit;
}

$flash = (string) ($_GET['msg'] ?? '');

try { st_rev_stock_schema($pdo); } catch (Throwable $e) {}   // asegura rev_activo / rev_export

// Revendedores + saldo + estado (activo/export) + mini-estadística (cuentas, ventas, ingresos, invertido).
$revs = $pdo->query("SELECT u.id, u.nombre, u.email, u.telefono, COALESCE(u.saldo,0) AS saldo,
        COALESCE(u.rev_activo,1) AS rev_activo, COALESCE(u.rev_export,1) AS rev_export,
        (SELECT COUNT(*) FROM streaming_ventas v WHERE v.owner_id=u.id) AS ventas_n,
        (SELECT COUNT(*) FROM streaming_cuentas c WHERE c.owner_id=u.id) AS cuentas_n,
        (SELECT COALESCE(SUM(precio),0) FROM streaming_ventas v WHERE v.owner_id=u.id AND ((v.cliente_nombre IS NOT NULL AND v.cliente_nombre<>'') OR COALESCE(v.cliente_id,0)>0)) AS ingresos,
        (SELECT COALESCE(SUM(costo),0) FROM streaming_cuentas c WHERE c.owner_id=u.id AND costo>0) AS invertido
    FROM usuarios u WHERE u.rol='revendedor' ORDER BY u.nombre")->fetchAll(PDO::FETCH_ASSOC);

// Recargas de saldo pendientes (fallback manual de la pasarela).
$pend = $pdo->query("SELECT r.*, u.nombre FROM wallet_recargas r JOIN usuarios u ON u.id=r.usuario_id
    WHERE r.estado='pendiente' ORDER BY r.creado_en ASC")->fetchAll(PDO::FETCH_ASSOC);

// Config actual del BOT de códigos (para prellenar el formulario).
$botUrl = function_exists('bot_codigos_cfg') ? bot_codigos_cfg('bot_codigos_url') : '';
$botTok = function_exists('bot_codigos_cfg') ? bot_codigos_cfg('bot_codigos_token') : '';
stream_head('Revendedores', 'revendedores');
?>
<?php if ($flash): ?><div class="banner" style="margin-bottom:16px;white-space:normal"><i data-lucide="info"></i><?= h($flash) ?></div><?php endif; ?>

<div class="pagehd">
  <div><h1>Gestión de <span class="nm">Revendedores</span></h1>
  <p>Crea, aprueba y gestiona revendedores; su saldo y recargas.</p></div>
  <button class="btn primary" onclick="document.getElementById('mNuevo').style.display='flex'"><i data-lucide="user-plus"></i> Nuevo revendedor</button>
</div>

<!-- Integración BOT de códigos: asigna/desasigna el correo de la cuenta al correo del revendedor. -->
<details class="card" style="margin-bottom:16px;padding:0" <?= $botUrl === '' ? '' : '' ?>>
  <summary style="cursor:pointer;padding:12px 16px;font-weight:700;font-size:13.5px;display:flex;align-items:center;gap:8px">
    <i data-lucide="bot" style="width:16px;height:16px;vertical-align:-2px"></i> Bot de códigos (asignar correos al revendedor)
    <span class="pill <?= $botUrl !== '' ? 'ok' : 'wait' ?>" style="font-size:10px;margin-left:auto"><?= $botUrl !== '' ? 'Activo' : 'Sin configurar' ?></span>
  </summary>
  <form method="post" style="padding:0 16px 16px">
    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="accion" value="guardar_bot_codigos">
    <p style="font-size:11.5px;color:var(--faint);margin:0 0 10px">Cuando le entregas/vendes una cuenta a un revendedor, se <b>asigna</b> el correo de esa cuenta al correo del revendedor en la página de códigos; al eliminársela, se <b>desasigna</b>. Deja la URL vacía para desactivar.<br>
    URL (según la propuesta de prycorreos): <code>https://streaming.reborxstore.com/api/external/asignaciones.php</code> · POST JSON <code>{action, account_email, reseller_email}</code> · el <b>token va en el header</b> <code>Authorization: Bearer</code>. <b>Ojo:</b> ese endpoint lo debe crear primero el programador de la página de códigos.</p>
    <div class="grid md:grid-cols-2 gap-3">
      <div class="field" style="margin:0"><label class="flbl">URL del bot (endpoint)</label><input name="bot_url" class="input" value="<?= h($botUrl) ?>" placeholder="https://tu-bot.com/api/asignar"></div>
      <div class="field" style="margin:0"><label class="flbl">Token / clave (opcional)</label><input name="bot_token" class="input" value="<?= h($botTok) ?>" placeholder="Token de seguridad"></div>
    </div>
    <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:12px"><button class="btn primary"><i data-lucide="save"></i> Guardar bot</button></div>
  </form>
  <form method="post" style="padding:0 16px 16px;margin-top:-8px">
    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="accion" value="probar_bot">
    <button class="btn ghost" style="font-size:12.5px"><i data-lucide="plug-zap" style="width:14px;height:14px"></i> Probar bot (envía una prueba y muestra la respuesta)</button>
    <span style="font-size:11px;color:var(--faint);margin-left:8px">Úsalo para ver si tu bot recibe bien y qué formato espera.</span>
  </form>
</details>

<div id="mNuevo" class="modal-bg" style="display:none;position:fixed;inset:0;background:#0008;align-items:flex-start;justify-content:center;padding:28px 14px;z-index:60;overflow:auto">
  <div class="card" style="max-width:440px;width:100%;padding:22px">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px">
      <h3 style="margin:0">Nuevo revendedor</h3>
      <button onclick="document.getElementById('mNuevo').style.display='none'" class="iconbtn"><i data-lucide="x"></i></button>
    </div>
    <p class="muted" style="font-size:12.5px;margin:0 0 14px">Si el correo ya tiene cuenta, se aprueba como revendedor. Si no, se crea una cuenta nueva.</p>
    <form method="post">
      <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="accion" value="crear_revendedor">
      <label style="display:block;font-size:12px;color:var(--muted);font-weight:600;margin-bottom:5px">Correo *</label>
      <input type="email" name="email" required style="width:100%;margin-bottom:12px;padding:9px;border:1px solid var(--border);border-radius:9px;background:var(--surface);color:var(--text)">
      <label style="display:block;font-size:12px;color:var(--muted);font-weight:600;margin-bottom:5px">Nombre (si es cuenta nueva)</label>
      <input name="nombre" style="width:100%;margin-bottom:12px;padding:9px;border:1px solid var(--border);border-radius:9px;background:var(--surface);color:var(--text)">
      <label style="display:block;font-size:12px;color:var(--muted);font-weight:600;margin-bottom:5px">WhatsApp del revendedor <span style="color:var(--faint);font-weight:400">(para poder enviarle los avisos)</span></label>
      <input name="telefono" placeholder="Ej: 584121234567" style="width:100%;margin-bottom:12px;padding:9px;border:1px solid var(--border);border-radius:9px;background:var(--surface);color:var(--text)">
      <label style="display:block;font-size:12px;color:var(--muted);font-weight:600;margin-bottom:5px">Nombre de su tienda <span style="color:var(--faint);font-weight:400">(opcional)</span></label>
      <input name="nombre_tienda" style="width:100%;margin-bottom:12px;padding:9px;border:1px solid var(--border);border-radius:9px;background:var(--surface);color:var(--text)">
      <label style="display:block;font-size:12px;color:var(--muted);font-weight:600;margin-bottom:5px">Contraseña (si es nueva; vacío = se genera)</label>
      <input name="password" style="width:100%;margin-bottom:16px;padding:9px;border:1px solid var(--border);border-radius:9px;background:var(--surface);color:var(--text)">
      <button class="btn primary" type="submit" style="width:100%"><i data-lucide="check"></i> Crear / aprobar revendedor</button>
    </form>
  </div>
</div>

<?php if ($pend): ?>
<div class="card" style="margin-bottom:16px;padding:16px">
  <h2 style="margin:0 0 10px;font-size:15px">Recargas de saldo pendientes</h2>
  <div class="overflow-x-auto thin"><table class="dtable">
    <thead><tr><th>Revendedor</th><th>Monto</th><th>Método</th><th>Referencia</th><th>Fecha</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($pend as $r): ?>
      <tr>
        <td style="font-weight:700"><?= h($r['nombre']) ?></td>
        <td class="tnum">$<?= number_format((float) $r['monto'], 2) ?></td>
        <td><?= h($r['metodo'] ?: '—') ?></td>
        <td><?= h($r['referencia'] ?: '—') ?></td>
        <td class="muted" style="font-size:12px"><?= h($r['creado_en']) ?></td>
        <td style="text-align:right;white-space:nowrap">
          <form method="post" style="display:inline"><input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="accion" value="aprobar_recarga"><input type="hidden" name="revendedor_id" value="<?= (int) $r['usuario_id'] ?>"><input type="hidden" name="recarga_id" value="<?= (int) $r['id'] ?>"><button class="btn primary" style="padding:6px 10px">Aprobar</button></form>
          <form method="post" style="display:inline" onsubmit="return confirm('¿Rechazar esta recarga?')"><input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="accion" value="rechazar_recarga"><input type="hidden" name="revendedor_id" value="<?= (int) $r['usuario_id'] ?>"><input type="hidden" name="recarga_id" value="<?= (int) $r['id'] ?>"><button class="btn ghost" style="padding:6px 10px">Rechazar</button></form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php endif; ?>

<div class="card">
  <div class="card-hd"><i data-lucide="users"></i><h2>Revendedores</h2><span class="pill-count"><?= count($revs) ?></span>
    <div style="margin-left:auto;position:relative">
      <i data-lucide="search" style="position:absolute;left:9px;top:50%;transform:translateY(-50%);width:15px;height:15px;color:var(--faint)"></i>
      <input id="revBuscar" type="search" placeholder="Buscar por nombre o correo…" oninput="filtrarRevs(this.value)" autocomplete="off"
             style="padding:7px 10px 7px 30px;border:1px solid var(--border);border-radius:9px;background:var(--surface);color:var(--text);font-size:13px;width:240px;max-width:52vw">
    </div>
  </div>
  <div class="overflow-x-auto thin"><table class="dtable">
    <thead><tr><th>Revendedor</th><th>Correo</th><th>WhatsApp</th><th>Estado</th><th>Cuentas / Ventas</th><th>Ingresos / Invertido</th><th>Saldo</th><th style="text-align:center">Acciones</th></tr></thead>
    <tbody>
    <?php if (!$revs): ?>
      <tr><td colspan="8" class="muted" style="text-align:center;padding:26px">Aún no hay usuarios con rol «Revendedor». Asigna el rol desde Usuarios en el admin.</td></tr>
    <?php else: foreach ($revs as $r): $act = (int) ($r['rev_activo'] ?? 1) === 1; $exp = (int) ($r['rev_export'] ?? 1) === 1; ?>
      <tr class="rev-row" data-search="<?= h(mb_strtolower(trim(($r['nombre'] ?? '') . ' ' . ($r['email'] ?? '') . ' ' . ($r['telefono'] ?? '')))) ?>" style="<?= $act ? '' : 'opacity:.6' ?>">
        <td style="font-weight:700;color:var(--accent)"><?= h($r['nombre']) ?></td>
        <td class="muted"><?= h($r['email']) ?></td>
        <td class="muted" style="white-space:nowrap"><?= !empty($r['telefono']) ? h($r['telefono']) : '<span style="color:var(--bad);font-size:12px">falta</span>' ?>
          <button class="iconbtn" style="width:26px;height:26px" title="Editar WhatsApp" onclick="editarWa(<?= (int) $r['id'] ?>, <?= h(json_encode((string) ($r['telefono'] ?? ''))) ?>)"><i data-lucide="pencil" style="width:13px;height:13px"></i></button></td>
        <td><?php if ($act): ?><span class="pill ok" style="font-size:11px">Activo</span><?php else: ?><span class="pill wait" style="font-size:11px">⏸ Pausado</span><?php endif; ?><?php if (!$exp): ?> <span class="tag" style="font-size:10px;color:var(--warn)" title="No puede exportar">🔒 export</span><?php endif; ?></td>
        <td style="font-size:12.5px"><?= (int) ($r['cuentas_n'] ?? 0) ?> cta · <?= (int) $r['ventas_n'] ?> ventas</td>
        <td style="font-size:12.5px"><span style="color:var(--good)">$<?= number_format((float) ($r['ingresos'] ?? 0), 2) ?></span> · <span style="color:var(--muted)">$<?= number_format((float) ($r['invertido'] ?? 0), 2) ?></span></td>
        <td class="tnum" style="font-weight:800">$<?= number_format((float) $r['saldo'], 2) ?></td>
        <td style="text-align:center;white-space:nowrap">
          <button class="btn ghost" style="padding:6px 10px" onclick="verStats(<?= (int) $r['id'] ?>,<?= h(json_encode($r['nombre'])) ?>)" title="Ver estadísticas de este revendedor"><i data-lucide="bar-chart-3"></i> Estadísticas</button>
          <a class="btn ghost" style="padding:6px 10px" href="ventas.php?f=todas&rev=<?= (int) $r['id'] ?>" title="Ver las ventas de este revendedor"><i data-lucide="list"></i> Ventas</a>
          <button class="btn ghost" style="padding:6px 10px" onclick='saldoMod(<?= json_encode(["id"=>(int)$r["id"],"nombre"=>$r["nombre"],"saldo"=>(float)$r["saldo"]], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'><i data-lucide="wallet"></i> Ajustar</button>
          <form method="post" style="display:inline"><input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="accion" value="toggle_activo"><input type="hidden" name="revendedor_id" value="<?= (int) $r['id'] ?>">
            <button class="btn <?= $act ? 'ghost' : 'primary' ?>" style="padding:6px 10px;<?= $act ? 'color:var(--bad)' : '' ?>" type="submit" title="<?= $act ? 'Pausar (sin borrar)' : 'Reactivar' ?>"><i data-lucide="<?= $act ? 'pause' : 'play' ?>"></i> <?= $act ? 'Desactivar' : 'Activar' ?></button></form>
          <form method="post" style="display:inline"><input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="accion" value="toggle_export"><input type="hidden" name="revendedor_id" value="<?= (int) $r['id'] ?>">
            <button class="btn ghost" style="padding:6px 8px" type="submit" title="<?= $exp ? 'Bloquear que exporte sus datos' : 'Permitir que exporte' ?>"><i data-lucide="<?= $exp ? 'download' : 'lock' ?>"></i></button></form>
          <form method="post" style="display:inline" onsubmit="return confirm('¿Quitar el acceso de revendedor a <?= h(addslashes($r['nombre'])) ?>? Sus datos (inventario, saldo) se conservan.')">
            <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="accion" value="quitar_revendedor">
            <input type="hidden" name="revendedor_id" value="<?= (int) $r['id'] ?>">
            <button class="btn ghost" style="padding:6px 10px;color:var(--bad)" type="submit"><i data-lucide="user-x"></i> Quitar</button>
          </form>
        </td>
      </tr>
    <?php endforeach; endif; ?>
      <tr id="revSinRes" style="display:none"><td colspan="8" class="muted" style="text-align:center;padding:22px">Ningún revendedor coincide con la búsqueda.</td></tr>
    </tbody>
  </table></div>
</div>
<script>
  function filtrarRevs(q){
    q = (q || '').trim().toLowerCase();
    var rows = document.querySelectorAll('tr.rev-row'), vis = 0;
    rows.forEach(function(tr){
      var ok = q === '' || (tr.getAttribute('data-search') || '').indexOf(q) !== -1;
      tr.style.display = ok ? '' : 'none';
      if (ok) vis++;
    });
    var sr = document.getElementById('revSinRes');
    if (sr) sr.style.display = (rows.length && vis === 0) ? '' : 'none';
  }
</script>

<div id="mSaldo" class="modal-bg" style="display:none;position:fixed;inset:0;background:#0008;align-items:flex-start;justify-content:center;padding:28px 14px;z-index:60;overflow:auto">
  <div class="card" style="max-width:420px;width:100%;padding:22px">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px">
      <h3 style="margin:0" id="msTitle">Ajustar saldo</h3>
      <button onclick="document.getElementById('mSaldo').style.display='none'" class="iconbtn"><i data-lucide="x"></i></button>
    </div>
    <p class="muted" style="font-size:13px;margin:0 0 14px" id="msSaldo"></p>
    <form method="post">
      <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="revendedor_id" id="ms-id">
      <!-- accion va en un campo OCULTO que fija el onclick del botón. Antes iba en value="" del <button>,
           pero al hacer clic sobre el ícono SVG (Lucide) algunos navegadores NO enviaban el value del
           botón → accion llegaba vacía y "Acreditar" no entraba a su bloque (bug real capturado en Network). -->
      <input type="hidden" name="accion" id="ms-accion" value="acreditar">
      <label style="display:block;font-size:12px;color:var(--muted);font-weight:600;margin-bottom:5px">Monto ($)</label>
      <input type="number" step="0.01" min="0" name="monto" required style="width:100%;margin-bottom:12px;padding:9px;border:1px solid var(--border);border-radius:9px;background:var(--surface);color:var(--text)">
      <label style="display:block;font-size:12px;color:var(--muted);font-weight:600;margin-bottom:5px">Motivo</label>
      <input name="motivo" placeholder="Pago recibido, ajuste…" style="width:100%;margin-bottom:16px;padding:9px;border:1px solid var(--border);border-radius:9px;background:var(--surface);color:var(--text)">
      <div style="display:flex;gap:8px">
        <button class="btn primary" type="submit" onclick="document.getElementById('ms-accion').value='acreditar'" style="flex:1"><i data-lucide="plus"></i> Acreditar</button>
        <button class="btn ghost" type="submit" onclick="document.getElementById('ms-accion').value='debitar'" style="flex:1"><i data-lucide="minus"></i> Descontar</button>
      </div>
    </form>
  </div>
</div>
<script>
function saldoMod(r){
  document.getElementById('ms-id').value = r.id;
  document.getElementById('msTitle').textContent = 'Saldo de ' + r.nombre;
  document.getElementById('msSaldo').textContent = 'Saldo actual: $' + r.saldo.toFixed(2);
  document.getElementById('mSaldo').style.display = 'flex';
  if (window.lucide) lucide.createIcons();
}
document.getElementById('mSaldo').addEventListener('click', e => { if (e.target.id === 'mSaldo') document.getElementById('mSaldo').style.display='none'; });
</script>
<!-- Modal Estadísticas del revendedor -->
<div id="mStats" class="modal-bg" style="display:none;position:fixed;inset:0;background:#0008;align-items:flex-start;justify-content:center;padding:28px 14px;z-index:60;overflow:auto">
  <div class="card" style="max-width:520px;width:100%;padding:22px">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px">
      <h3 style="margin:0" id="stTitle">Estadísticas</h3>
      <button onclick="document.getElementById('mStats').style.display='none'" class="iconbtn"><i data-lucide="x"></i></button>
    </div>
    <div id="stBody" style="color:var(--muted)">Cargando…</div>
    <div style="margin-top:14px;text-align:right"><a id="stVentas" class="btn primary" href="#"><i data-lucide="list"></i> Ver sus ventas</a></div>
  </div>
</div>
<script>
  const esc = s => String(s==null?'':s).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  async function verStats(id, nombre){
    document.getElementById('stTitle').textContent = 'Estadísticas · ' + nombre;
    document.getElementById('stVentas').href = 'ventas.php?f=todas&rev=' + id;
    document.getElementById('stBody').innerHTML = 'Cargando…';
    document.getElementById('mStats').style.display = 'flex';
    if (window.lucide) lucide.createIcons();
    try {
      const r = await fetch('?ajax=stats&id=' + id).then(x => x.json());
      if (!r.ok) { document.getElementById('stBody').innerHTML = 'No se pudo cargar.'; return; }
      const s = r.stats;
      const money = v => '$' + Number(v||0).toLocaleString('es-VE',{minimumFractionDigits:2,maximumFractionDigits:2});
      const tile = (lbl,val,col) => '<div style="background:var(--surface-2);border:1px solid var(--border);border-radius:11px;padding:12px 14px"><div style="font-size:10.5px;font-weight:700;text-transform:uppercase;color:var(--faint);letter-spacing:.03em">'+lbl+'</div><div style="font-size:19px;font-weight:800;margin-top:3px;color:'+(col||'var(--text)')+'">'+val+'</div></div>';
      document.getElementById('stBody').innerHTML =
        '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">'
        + tile('Saldo', money(s.saldo), 'var(--accent)')
        + tile('Ganancia', money(s.ganancia), s.ganancia>=0?'var(--good)':'var(--bad)')
        + tile('Ingresos (ventas a cliente)', money(s.ingresos), 'var(--good)')
        + tile('Invertido (costo stock)', money(s.invertido))
        + tile('Ventas totales', s.ventas)
        + tile('Activas / Vencidas', s.activas + ' / ' + s.vencidas)
        + tile('Cuentas', s.cuentas)
        + tile('Perfiles libres / vendidos', s.libres + ' / ' + s.vendidos)
        + '</div>';
      // Top de servicios que más mueve (con cantidad).
      var top = r.top || [];
      if (top.length) {
        var max = Math.max.apply(null, top.map(function(t){return t.n;})) || 1;
        var rows = top.map(function(t){
          var pct = Math.round((t.n/max)*100);
          return '<div style="display:flex;align-items:center;gap:8px;margin-bottom:6px">'
            + '<div style="flex:0 0 120px;font-size:12.5px;font-weight:600;color:var(--text);overflow:hidden;text-overflow:ellipsis;white-space:nowrap">'+esc(t.plataforma)+'</div>'
            + '<div style="flex:1;height:9px;background:var(--surface-2);border-radius:6px;overflow:hidden"><div style="width:'+pct+'%;height:100%;background:var(--accent)"></div></div>'
            + '<div style="flex:0 0 34px;text-align:right;font-weight:800;color:var(--accent)">'+t.n+'</div></div>';
        }).join('');
        document.getElementById('stBody').innerHTML += '<div style="margin-top:16px"><div style="font-size:11px;font-weight:700;text-transform:uppercase;color:var(--faint);letter-spacing:.03em;margin-bottom:8px">Top servicios (cantidad)</div>'+rows+'</div>';
      }
    } catch(e) { document.getElementById('stBody').innerHTML = 'No se pudo cargar.'; }
  }
  document.getElementById('mStats').addEventListener('click', e => { if (e.target.id === 'mStats') document.getElementById('mStats').style.display='none'; });
</script>
<form method="post" id="waForm" style="display:none"><input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="accion" value="editar_wa_revendedor"><input type="hidden" name="revendedor_id" id="wa-rid"><input type="hidden" name="telefono" id="wa-tel"></form>
<script>
  function editarWa(id, tel){ const v = prompt('WhatsApp del revendedor (solo números, con código de país. Ej: 584121234567):', tel || ''); if (v === null) return; document.getElementById('wa-rid').value = id; document.getElementById('wa-tel').value = v; document.getElementById('waForm').submit(); }
</script>
<?php stream_foot();
