<?php
/**
 * Revendedores (del REVENDEDOR) — lista SIMPLE de sus propios sub-revendedores, al estilo de
 * Proveedores/Clientes: solo para anotar datos (nombre, WhatsApp, precio de reventa, notas). NO es
 * gestión con saldo/login (eso es del admin, en revendedores.php). Cada revendedor ve SOLO los suyos
 * (aislamiento por owner_id = su id, vía stream_owner_id()). Se muestra solo en el panel del REVENDEDOR
 * (la pestaña se agrega dentro de if($esRev) en _layout.php). Mapea a streaming_subrevendedores.
 */
define('CONEC_ADMIN', true);
require __DIR__ . '/../_auth.php';
require __DIR__ . '/_layout.php';
admin_require_login();
if (!admin_es_admin()) { http_response_code(403); exit('Sin acceso.'); }
$pdo = db();
$OWNER = (int) stream_owner_id();

// Tabla (se auto-crea, idempotente). Lista per-owner, mismo estilo que streaming_proveedores.
try {
  $pdo->exec("CREATE TABLE IF NOT EXISTS streaming_subrevendedores (
    id INT AUTO_INCREMENT PRIMARY KEY,
    owner_id INT NOT NULL DEFAULT 0,
    nombre VARCHAR(120) NOT NULL,
    wa VARCHAR(30) NULL,
    precio_reventa DECIMAL(12,2) NULL,
    notas VARCHAR(300) NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_owner (owner_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Throwable $e) {}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $a = $_POST['accion'] ?? '';
  $msg = '';
  try {
    if ($a === 'subrev_save') {
      $id = (int) ($_POST['id'] ?? 0);
      $nombre = trim((string) ($_POST['nombre'] ?? ''));
      if ($nombre === '') throw new Exception('El nombre es obligatorio.');
      $wa = preg_replace('/\D+/', '', (string) ($_POST['wa'] ?? '')) ?: null;
      $notas = trim((string) ($_POST['notas'] ?? '')) ?: null;
      $prRaw = str_replace(',', '.', trim((string) ($_POST['precio_reventa'] ?? '')));
      $precio = ($prRaw !== '' && is_numeric($prRaw)) ? round((float) $prRaw, 2) : null;
      if ($id) {
        $pdo->prepare("UPDATE streaming_subrevendedores SET nombre=?, wa=?, precio_reventa=?, notas=? WHERE id=? AND owner_id=?")->execute([$nombre, $wa, $precio, $notas, $id, $OWNER]);
      } else {
        $pdo->prepare("INSERT INTO streaming_subrevendedores (owner_id, nombre, wa, precio_reventa, notas, activo) VALUES (?,?,?,?,?,1)")->execute([$OWNER, $nombre, $wa, $precio, $notas]);
      }
      $msg = '✓ Revendedor guardado.';
    } elseif ($a === 'subrev_del') {
      $pdo->prepare("DELETE FROM streaming_subrevendedores WHERE id=? AND owner_id=?")->execute([(int) ($_POST['id'] ?? 0), $OWNER]);
      $msg = '✓ Revendedor eliminado.';
    }
  } catch (Throwable $e) { $msg = '⚠ ' . $e->getMessage(); }
  header('Location: misrevendedores.php?msg=' . urlencode($msg));
  exit;
}
$flash = (string) ($_GET['msg'] ?? '');

$rows = [];
try { $rows = $pdo->query("SELECT id, nombre, wa, precio_reventa, notas FROM streaming_subrevendedores WHERE owner_id=$OWNER ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) { $rows = []; }

stream_head('Revendedores', 'mis-revendedores');
?>
<?php if ($flash): $flashMal = (mb_substr(trim((string) $flash), 0, 1) === '⚠'); ?><div class="banner" style="margin-bottom:16px<?= $flashMal ? ';background:var(--bad-soft);color:var(--bad);border-color:var(--bad)' : '' ?>"><i data-lucide="<?= $flashMal ? 'alert-triangle' : 'check-circle' ?>"></i><?= h($flash) ?></div><?php endif; ?>

<div class="pagehd">
  <div>
    <h1>Mis <span class="nm">Revendedores</span></h1>
    <p>Tu lista de revendedores (a quién le revendes). Solo datos y su precio de reventa — no maneja saldo.</p>
  </div>
  <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
    <button onclick="abrirRev()" class="btn primary"><i data-lucide="user-plus"></i> Nuevo Revendedor</button>
  </div>
</div>

<div class="card">
  <div class="card-hd">
    <i data-lucide="store"></i>
    <h2>Revendedores</h2>
    <span class="pill-count"><?= count($rows) ?></span>
    <div style="margin-left:auto">
      <input id="buscar" class="input" style="width:260px;max-width:55vw" placeholder="Buscar…">
    </div>
  </div>
  <div class="overflow-x-auto thin">
    <table class="dtable">
      <thead><tr>
        <th>Nombre</th><th>WhatsApp</th><th style="text-align:right">Precio reventa</th><th>Notas</th><th style="text-align:center">Acciones</th>
      </tr></thead>
      <tbody id="tbody">
      <?php foreach ($rows as $r):
        $json = h(json_encode($r, JSON_UNESCAPED_UNICODE)); ?>
        <tr data-b="<?= h(mb_strtolower($r['nombre'] . ' ' . ($r['wa'] ?? ''))) ?>">
          <td style="font-weight:700;color:var(--accent)"><?= h($r['nombre']) ?></td>
          <td><?php if ($r['wa']): ?><a href="https://wa.me/<?= h($r['wa']) ?>" target="_blank" rel="noopener" style="display:inline-flex;align-items:center;gap:6px;color:var(--good)"><i data-lucide="message-circle" style="width:15px;height:15px"></i><?= h($r['wa']) ?></a><?php else: ?><span style="color:var(--faint)">—</span><?php endif; ?></td>
          <td class="tnum" style="text-align:right"><?= $r['precio_reventa'] !== null ? '$' . number_format((float) $r['precio_reventa'], 2) : '<span style="color:var(--faint)">—</span>' ?></td>
          <td style="color:var(--muted)"><?= h($r['notas'] ?: '—') ?></td>
          <td><div style="display:flex;align-items:center;justify-content:center;gap:6px">
            <button onclick='abrirRev(<?= $json ?>)' class="btn ghost" style="padding:7px" title="Editar"><i data-lucide="pencil"></i></button>
            <button onclick="delRev(<?= (int) $r['id'] ?>, <?= h(json_encode($r['nombre'])) ?>)" class="btn ghost" style="padding:7px;color:var(--bad)" title="Eliminar"><i data-lucide="trash-2"></i></button>
          </div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php if (!$rows): ?><div class="empty">Sin revendedores todavía. Crea el primero con "Nuevo Revendedor".</div><?php endif; ?>
  </div>
</div>

<!-- Modal Nuevo/Editar -->
<div id="overlay" class="fixed inset-0 bg-black/50 z-40 hidden items-start justify-center overflow-y-auto p-4" onclick="if(event.target===this)cerrar()">
  <div id="m-rev" class="hidden" style="background:var(--surface);border:1px solid var(--border);border-radius:14px;width:100%;max-width:440px;margin:32px 0;box-shadow:var(--shadow);overflow:hidden">
    <div class="card-hd" style="justify-content:space-between">
      <div style="display:flex;align-items:center;gap:10px"><i data-lucide="user-plus"></i><h2 id="r-title">Agregar Revendedor</h2></div>
      <button onclick="cerrar()" class="btn ghost" style="padding:7px"><i data-lucide="x"></i></button>
    </div>
    <form method="post" style="padding:16px"><input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="accion" value="subrev_save"><input type="hidden" name="id" id="r-id">
      <div class="field"><label>Nombre <span style="color:var(--bad)">*</span></label><input name="nombre" id="r-nombre" required class="input" placeholder="Ej: Juan Pérez"></div>
      <div class="field"><label>WhatsApp</label><input name="wa" id="r-wa" class="input" placeholder="Ej: 584121234567"><span style="font-size:11px;color:var(--faint)">Solo números (con código de país).</span></div>
      <div class="field"><label>Precio de reventa <span style="color:var(--faint);font-weight:400">(opcional)</span></label><div style="display:flex"><span style="padding:0 10px;display:grid;place-items:center;background:var(--surface-2);border:1px solid var(--border);border-right:0;border-radius:9px 0 0 9px;color:var(--muted)">$</span><input name="precio_reventa" id="r-precio" type="number" step="0.01" min="0" class="input" style="border-radius:0 9px 9px 0" placeholder="Lo que le cobras a este revendedor"></div></div>
      <div class="field"><label>Notas</label><textarea name="notas" id="r-notas" rows="2" class="input"></textarea></div>
      <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:4px"><button type="button" onclick="cerrar()" class="btn ghost">Cancelar</button><button class="btn primary">Guardar</button></div>
    </form>
  </div>
</div>

<form id="del-form" method="post" style="display:none"><input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="accion" value="subrev_del"><input type="hidden" name="id" id="del-id"></form>

<script>
  const ov=document.getElementById('overlay');
  function cerrar(){ ov.classList.add('hidden'); ov.classList.remove('flex'); document.querySelectorAll('#overlay > div').forEach(m=>m.classList.add('hidden')); }
  function abrir(id){ ov.classList.remove('hidden'); ov.classList.add('flex'); document.querySelectorAll('#overlay > div').forEach(m=>m.classList.add('hidden')); document.getElementById(id).classList.remove('hidden'); lucide.createIcons(); }
  function abrirRev(r){
    document.getElementById('r-title').textContent=r?'Editar Revendedor':'Agregar Revendedor';
    document.getElementById('r-id').value=r?r.id:''; document.getElementById('r-nombre').value=r?(r.nombre||''):'';
    document.getElementById('r-wa').value=r?(r.wa||''):''; document.getElementById('r-precio').value=r&&r.precio_reventa!=null?r.precio_reventa:'';
    document.getElementById('r-notas').value=r?(r.notas||''):'';
    abrir('m-rev');
  }
  function delRev(id, nombre){ if(!confirm('¿Eliminar al revendedor "'+nombre+'"? (solo se borra de esta lista)')) return; document.getElementById('del-id').value=id; document.getElementById('del-form').submit(); }
  document.getElementById('buscar')?.addEventListener('input',function(){ const q=this.value.toLowerCase().trim(); document.querySelectorAll('#tbody tr').forEach(tr=>tr.style.display=(!q||(tr.dataset.b||'').includes(q))?'':'none'); });
</script>
<?php stream_foot(); ?>
