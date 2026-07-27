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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $a = $_POST['accion'] ?? '';
    $msg = '';
    try {
        // ── Crear / aprobar revendedor (no requiere un revendedor previo) ──
        if ($a === 'crear_revendedor') {
            $email = strtolower(trim((string) ($_POST['email'] ?? '')));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('Correo inválido.');
            }
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
                $msg = '✓ Usuario existente aprobado como revendedor.';
            } else {
                $nombre = trim((string) ($_POST['nombre'] ?? '')) ?: $email;
                $pass = trim((string) ($_POST['password'] ?? '')) ?: substr(bin2hex(random_bytes(5)), 0, 8);
                // username único a partir del correo.
                $base = preg_replace('/[^a-z0-9_]+/', '', strtolower(explode('@', $email)[0])) ?: 'rev';
                $username = $base; $i = 0;
                $uq = $pdo->prepare("SELECT 1 FROM usuarios WHERE username=? LIMIT 1");
                while (true) { $uq->execute([$username]); if (!$uq->fetch()) break; $username = $base . (++$i); }
                $pdo->prepare("INSERT INTO usuarios (username, password, nombre, email, rol) VALUES (?,?,?,?, 'revendedor')")
                    ->execute([$username, password_hash($pass, PASSWORD_DEFAULT), $nombre, $email]);
                $msg = '✓ Revendedor creado. Correo: ' . $email . ' · Clave: ' . $pass . ' (anótala y pásasela).';
            }
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
        } elseif ($a === 'acreditar' || $a === 'debitar') {
            $monto = round(abs((float) ($_POST['monto'] ?? 0)), 2);
            if ($monto <= 0) {
                throw new Exception('Monto inválido.');
            }
            $motivo = trim((string) ($_POST['motivo'] ?? '')) ?: 'Ajuste manual (admin)';
            if ($a === 'acreditar') {
                wallet_acreditar($pdo, $rid, $monto, 'ajuste_admin', $motivo);
                $msg = '✓ Saldo acreditado: $' . number_format($monto, 2) . '.';
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

// Revendedores + su saldo + nº de ventas (su inventario propio + lo comprado del stock).
$revs = $pdo->query("SELECT u.id, u.nombre, u.email, COALESCE(u.saldo,0) AS saldo,
        (SELECT COUNT(*) FROM streaming_ventas v WHERE v.owner_id=u.id) AS ventas_n
    FROM usuarios u WHERE u.rol='revendedor' ORDER BY u.nombre")->fetchAll(PDO::FETCH_ASSOC);

// Recargas de saldo pendientes (fallback manual de la pasarela).
$pend = $pdo->query("SELECT r.*, u.nombre FROM wallet_recargas r JOIN usuarios u ON u.id=r.usuario_id
    WHERE r.estado='pendiente' ORDER BY r.creado_en ASC")->fetchAll(PDO::FETCH_ASSOC);

stream_head('Revendedores', 'revendedores');
?>
<?php if ($flash): ?><div class="banner" style="margin-bottom:16px;white-space:normal"><i data-lucide="info"></i><?= h($flash) ?></div><?php endif; ?>

<div class="pagehd">
  <div><h1>Gestión de <span class="nm">Revendedores</span></h1>
  <p>Crea, aprueba y gestiona revendedores; su saldo y recargas.</p></div>
  <button class="btn primary" onclick="document.getElementById('mNuevo').style.display='flex'"><i data-lucide="user-plus"></i> Nuevo revendedor</button>
</div>

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
  <div class="card-hd"><i data-lucide="users"></i><h2>Revendedores</h2><span class="pill-count"><?= count($revs) ?></span></div>
  <div class="overflow-x-auto thin"><table class="dtable">
    <thead><tr><th>Revendedor</th><th>Correo</th><th>Ventas</th><th>Saldo</th><th style="text-align:center">Acciones</th></tr></thead>
    <tbody>
    <?php if (!$revs): ?>
      <tr><td colspan="5" class="muted" style="text-align:center;padding:26px">Aún no hay usuarios con rol «Revendedor». Asigna el rol desde Usuarios en el admin.</td></tr>
    <?php else: foreach ($revs as $r): ?>
      <tr>
        <td style="font-weight:700;color:var(--accent)"><?= h($r['nombre']) ?></td>
        <td class="muted"><?= h($r['email']) ?></td>
        <td><?= (int) $r['ventas_n'] ?></td>
        <td class="tnum" style="font-weight:800">$<?= number_format((float) $r['saldo'], 2) ?></td>
        <td style="text-align:center;white-space:nowrap">
          <button class="btn ghost" style="padding:6px 10px" onclick='saldoMod(<?= json_encode(["id"=>(int)$r["id"],"nombre"=>$r["nombre"],"saldo"=>(float)$r["saldo"]], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'><i data-lucide="wallet"></i> Ajustar</button>
          <form method="post" style="display:inline" onsubmit="return confirm('¿Quitar el acceso de revendedor a <?= h(addslashes($r['nombre'])) ?>? Sus datos (inventario, saldo) se conservan.')">
            <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="accion" value="quitar_revendedor">
            <input type="hidden" name="revendedor_id" value="<?= (int) $r['id'] ?>">
            <button class="btn ghost" style="padding:6px 10px;color:var(--bad)" type="submit"><i data-lucide="user-x"></i> Quitar</button>
          </form>
        </td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table></div>
</div>

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
      <label style="display:block;font-size:12px;color:var(--muted);font-weight:600;margin-bottom:5px">Monto ($)</label>
      <input type="number" step="0.01" min="0" name="monto" required style="width:100%;margin-bottom:12px;padding:9px;border:1px solid var(--border);border-radius:9px;background:var(--surface);color:var(--text)">
      <label style="display:block;font-size:12px;color:var(--muted);font-weight:600;margin-bottom:5px">Motivo</label>
      <input name="motivo" placeholder="Pago recibido, ajuste…" style="width:100%;margin-bottom:16px;padding:9px;border:1px solid var(--border);border-radius:9px;background:var(--surface);color:var(--text)">
      <div style="display:flex;gap:8px">
        <button class="btn primary" type="submit" name="accion" value="acreditar" style="flex:1"><i data-lucide="plus"></i> Acreditar</button>
        <button class="btn ghost" type="submit" name="accion" value="debitar" style="flex:1"><i data-lucide="minus"></i> Descontar</button>
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
<?php stream_foot();
