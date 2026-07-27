<?php
/**
 * PENDIENTES DE APROBAR — activaciones MANUALES (Canva / entrega por correo).
 * El empleado ve la venta, invita el correo del cliente desde la cuenta maestra, y la APRUEBA
 * (marca entregada=1). Incluye ventas de revendedores (solo CONEC puede activar Canva), pero
 * mostrando SOLO campos seguros para la activación (nunca precios del revendedor).
 */
define('CONEC_ADMIN', true);
require __DIR__ . '/../_auth.php';
require __DIR__ . '/_layout.php';
admin_require_login();
if (!admin_es_admin() && !in_array(admin_wa_area(), ['streaming', 'ambas'], true)) {
  http_response_code(403);
  exit('Esta sección es del área de Streaming.');
}
$pdo = db();
$OWNER = (int) stream_owner_id();

$hasEA = false;
try { $hasEA = (bool) $pdo->query("SHOW COLUMNS FROM streaming_ventas LIKE 'email_activar'")->fetch(); } catch (Throwable $e) {}

// ── POST: aprobar (marcar activada) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $vid = (int) ($_POST['id'] ?? 0);
  $msg = '';
  if (($_POST['accion'] ?? '') === 'activar' && $vid && $hasEA) {
    $upd = $pdo->prepare("UPDATE streaming_ventas SET entregada=1 WHERE id=? AND owner_id=? AND entregada=0 AND email_activar IS NOT NULL AND email_activar<>''");
    $upd->execute([$vid, $OWNER]);
    if ($upd->rowCount() > 0) {
      try { $pdo->prepare("INSERT INTO streaming_venta_registro (venta_id,evento,descripcion,usuario_id) VALUES (?,?,?,?)")
                ->execute([$vid, 'activada', 'Aprobada: invitación enviada al correo del cliente', current_user_id()]); } catch (Throwable $e) {}
      $msg = '✓ Venta aprobada y marcada como activada.';
    } else { $msg = 'No se pudo aprobar (¿ya estaba activada?).'; }
  }
  header('Location: pendientes.php?msg=' . urlencode($msg));
  exit;
}
$flash = (string) ($_GET['msg'] ?? '');

// ── Lista de activaciones manuales pendientes ──
$rows = [];
if ($hasEA) {
  try {
    $rows = $pdo->query("SELECT v.id, v.plataforma, v.cliente_nombre, v.cliente_wa, v.email_activar,
            v.fecha_inicio, v.fecha_vencimiento, v.revendedor_id,
            ru.nombre AS rev_nombre,
            COALESCE(c.correo, v.correo) AS acc_correo, COALESCE(c.clave, v.clave) AS acc_clave,
            pl.logo_url, pl.color
          FROM streaming_ventas v
          LEFT JOIN usuarios ru ON ru.id = v.revendedor_id
          LEFT JOIN streaming_cuentas c ON c.id = v.cuenta_id
          LEFT JOIN streaming_plataformas pl ON pl.nombre = v.plataforma AND pl.owner_id = v.owner_id
          WHERE v.owner_id=$OWNER AND v.entregada=0 AND v.estado<>'cancelada' AND v.email_activar IS NOT NULL AND v.email_activar<>''
          ORDER BY v.fecha_inicio DESC, v.id DESC LIMIT 500")->fetchAll(PDO::FETCH_ASSOC);
  } catch (Throwable $e) { $rows = []; }
}
$n = count($rows);

stream_head('Pendientes de aprobar', 'ventas-pendientes');
?>
<style>
  .pa-card{padding:15px 16px;border-bottom:1px solid var(--border)}
  .pa-card:last-child{border-bottom:0}
  .pa-top{display:flex;align-items:center;gap:12px;margin-bottom:12px}
  .pa-av{width:44px;height:44px;border-radius:12px;display:grid;place-items:center;font-weight:800;font-size:18px;flex:0 0 auto;overflow:hidden}
  .pa-av img{width:100%;height:100%;object-fit:cover}
  .pa-mn{flex:1;min-width:0}
  .pa-mn b{display:block;font-size:15px;font-weight:750}
  .pa-mn span{font-size:12px;color:var(--muted)}
  .pa-grid{display:grid;grid-template-columns:1fr;gap:10px;margin-bottom:13px}
  @media(min-width:640px){.pa-grid{grid-template-columns:1fr 1fr}}
  .pa-box{background:var(--surface-2);border:1px solid var(--border);border-radius:11px;padding:10px 12px}
  .pa-box .lb{font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:var(--faint);margin-bottom:3px}
  .pa-box .vl{font-size:14px;font-weight:650;color:var(--text);word-break:break-all;font-family:ui-monospace,SFMono-Regular,Menlo,monospace}
  .pa-cp{margin-left:6px;font-size:11px;font-weight:700;color:var(--accent);background:none;border:0;cursor:pointer}
  .pa-actions{display:flex;gap:8px;flex-wrap:wrap}
  .pa-acc-line{display:flex;align-items:center;gap:6px;font-size:13px}
  .pa-acc-line + .pa-acc-line{margin-top:5px}
</style>

<div class="pagehd">
  <div>
    <h1>Pendientes de <span class="nm">aprobar</span></h1>
    <p>Activaciones manuales (Canva y similares). Invita el correo del cliente desde la cuenta maestra y aprueba la venta.</p>
  </div>
  <?php if ($n): ?><span class="pill wait" style="font-size:13px;padding:6px 13px"><?= $n ?> por activar</span><?php endif; ?>
</div>

<?php if ($flash): $flashMal = (mb_substr(trim((string) $flash), 0, 1) === '⚠'); ?><div class="banner" style="margin-bottom:16px<?= $flashMal ? ';background:var(--bad-soft);color:var(--bad);border-color:var(--bad)' : '' ?>"><i data-lucide="<?= $flashMal ? 'alert-triangle' : 'check-circle' ?>"></i><span><?= h($flash) ?></span></div><?php endif; ?>

<?php if (!$n): ?>
  <div class="card" style="padding:44px 20px;text-align:center;color:var(--muted)">
    <i data-lucide="check-circle-2" style="width:40px;height:40px;color:var(--good);margin:0 auto 10px"></i>
    <div style="font-weight:700;color:var(--text);font-size:15px">Todo al día</div>
    <div style="font-size:13px;margin-top:3px">No hay activaciones manuales pendientes.</div>
  </div>
<?php else: ?>
  <div class="card">
    <?php foreach ($rows as $r):
      $col = $r['color'] ?: '';
      $ini = mb_strtoupper(mb_substr((string) ($r['plataforma'] ?? '?'), 0, 1));
      $src = $r['revendedor_id'] ? ('Revendedor: ' . ($r['rev_nombre'] ?: '#' . $r['revendedor_id'])) : 'Tienda / directo';
      $fecha = $r['fecha_inicio'] ? date('d/m/Y', strtotime($r['fecha_inicio'])) : '';
      $wa = preg_replace('/\D/', '', (string) ($r['cliente_wa'] ?? '')); ?>
      <div class="pa-card">
        <div class="pa-top">
          <div class="pa-av" style="background:<?= $col ? h($col) . '22' : 'var(--accent-soft)' ?>;color:<?= $col ? h($col) : 'var(--accent)' ?>">
            <?php if ($r['logo_url']): ?><img src="<?= h($r['logo_url']) ?>" alt=""><?php else: ?><?= h($ini) ?><?php endif; ?>
          </div>
          <div class="pa-mn">
            <b><?= h($r['plataforma']) ?> · <?= h($r['cliente_nombre'] ?: 'Cliente') ?></b>
            <span><?= h($src) ?><?= $fecha ? ' · ' . $fecha : '' ?><?= $wa ? ' · ' . h($r['cliente_wa']) : '' ?></span>
          </div>
          <span class="pill wait" style="flex:0 0 auto">Por activar</span>
        </div>

        <div class="pa-grid">
          <div class="pa-box">
            <div class="lb">📧 Correo del cliente a invitar</div>
            <div class="vl"><?= h($r['email_activar']) ?><button class="pa-cp" type="button" onclick="paCopy(<?= h(json_encode($r['email_activar'])) ?>)">copiar</button></div>
          </div>
          <div class="pa-box">
            <div class="lb">🔑 Cuenta para activar</div>
            <div class="pa-acc-line"><span style="color:var(--faint)">Correo:</span> <b style="word-break:break-all"><?= h($r['acc_correo'] ?: '—') ?></b><?php if ($r['acc_correo']): ?><button class="pa-cp" type="button" onclick="paCopy(<?= h(json_encode($r['acc_correo'])) ?>)">copiar</button><?php endif; ?></div>
            <div class="pa-acc-line"><span style="color:var(--faint)">Clave:</span> <b style="word-break:break-all"><?= h($r['acc_clave'] ?: '—') ?></b><?php if ($r['acc_clave']): ?><button class="pa-cp" type="button" onclick="paCopy(<?= h(json_encode($r['acc_clave'])) ?>)">copiar</button><?php endif; ?></div>
          </div>
        </div>

        <div class="pa-actions">
          <a class="btn ghost" href="https://www.canva.com/" target="_blank" rel="noopener"><i data-lucide="external-link"></i> Abrir Canva</a>
          <?php if ($wa): ?><a class="btn ghost" href="https://wa.me/<?= h($wa) ?>?text=<?= rawurlencode('¡Hola' . ($r['cliente_nombre'] ? ' ' . $r['cliente_nombre'] : '') . '! 🎨 Ya te enviamos la invitación de ' . $r['plataforma'] . ' a tu correo (' . $r['email_activar'] . '). Revisa tu bandeja y acepta la invitación. ¡Gracias!') ?>" target="_blank" rel="noopener" style="color:#16a34a"><i data-lucide="message-circle"></i> Avisar al cliente</a><?php endif; ?>
          <form method="post" style="margin-left:auto">
            <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="accion" value="activar">
            <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
            <button class="btn primary" onclick="return confirm('¿Ya enviaste la invitación a '+<?= h(json_encode($r['email_activar'])) ?>+'? Se marcará como activada.')"><i data-lucide="check"></i> Aprobar (activada)</button>
          </form>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<script>
  function paCopy(t){ try{ navigator.clipboard.writeText(String(t)); }catch(e){} }
</script>
<?php stream_foot();
