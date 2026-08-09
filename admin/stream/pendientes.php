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
// Solo la TIENDA (admin) APRUEBA/activa. El REVENDEDOR ve esta página en modo SOLO LECTURA: para saber
// si sus compras por invitación/activación ya se activaron o siguen pendientes (no puede aprobarlas él;
// se le activan solas cuando el admin las aprueba). $soloLectura controla el botón y el POST.
$soloLectura = function_exists('stream_ctx') && stream_ctx() === 'revendedor';
$pdo = db();
$OWNER = (int) stream_owner_id();

$hasEA = false;
try { $hasEA = (bool) $pdo->query("SHOW COLUMNS FROM streaming_ventas LIKE 'email_activar'")->fetch(); } catch (Throwable $e) {}

// ── POST: aprobar (marcar activada) — SOLO el admin; el revendedor no aprueba ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  if ($soloLectura) { header('Location: pendientes.php'); exit; }
  $vid = (int) ($_POST['id'] ?? 0);
  $msg = '';
  if (($_POST['accion'] ?? '') === 'activar' && $vid && $hasEA) {
    $upd = $pdo->prepare("UPDATE streaming_ventas SET entregada=1 WHERE id=? AND owner_id=? AND entregada=0 AND email_activar IS NOT NULL AND email_activar<>''");
    $upd->execute([$vid, $OWNER]);
    if ($upd->rowCount() > 0) {
      try { $pdo->prepare("INSERT INTO streaming_venta_registro (venta_id,evento,descripcion,usuario_id) VALUES (?,?,?,?)")
                ->execute([$vid, 'activada', 'Aprobada: invitación enviada al correo del cliente', current_user_id()]); } catch (Throwable $e) {}
      // COMPLETA POR ACTIVACIÓN (Spotify Familiar / YouTube Premium): si la venta tiene cupos, al aprobarla
      // se le crea al REVENDEDOR una cuenta COMPLETA en SU stock (credenciales que pasó + N perfiles),
      // editable, lista para revender. "Solo la completa va a stock" (el perfil se queda solo en Ventas).
      try {
        $hasCupos = (bool) $pdo->query("SHOW COLUMNS FROM streaming_ventas LIKE 'cupos'")->fetch();
        if ($hasCupos) {
          $av = $pdo->query("SELECT revendedor_id, plataforma, email_activar, clave, fecha_vencimiento, precio, COALESCE(cupos,0) AS cupos FROM streaming_ventas WHERE id=$vid")->fetch(PDO::FETCH_ASSOC);
          if ($av && (int) $av['cupos'] > 0 && (int) $av['revendedor_id'] > 0) {
            require_once __DIR__ . '/../_streaming.php';
            if (function_exists('st_rev_stock_schema')) st_rev_stock_schema($pdo);
            $rev = (int) $av['revendedor_id']; $cupos = (int) $av['cupos'];
            // Anti-duplicado (por si se aprueba 2 veces): no crear si ya existe esa cuenta del rev.
            $ya = $pdo->prepare("SELECT id FROM streaming_cuentas WHERE owner_id=? AND correo=? AND plataforma=? LIMIT 1");
            $ya->execute([$rev, (string) $av['email_activar'], (string) $av['plataforma']]);
            if (!$ya->fetchColumn()) {
              $insC = $pdo->prepare("INSERT INTO streaming_cuentas (owner_id, plataforma, correo, clave, perfiles_total, vencimiento, costo, rev_editable) VALUES (?,?,?,?,?,?,?,1)");
              $insC->execute([$rev, (string) $av['plataforma'], (string) $av['email_activar'], (string) $av['clave'], $cupos, $av['fecha_vencimiento'], $av['precio']]);
              $ncid = (int) $pdo->lastInsertId();
              $insP = $pdo->prepare("INSERT INTO streaming_perfiles (cuenta_id, etiqueta, estado) VALUES (?,?, 'libre')");
              for ($i = 1; $i <= $cupos; $i++) $insP->execute([$ncid, 'P' . $i]);
              // BOT de códigos: la cuenta nace con bot_asignado=0; se asigna UNA sola vez con flush (así no
              // se descuadra el conteo de prycorreos aunque se apruebe/reintente). La cuenta ya está guardada.
              if (function_exists('bot_codigos_flush')) { try { bot_codigos_flush($pdo, $rev); } catch (Throwable $e) {} }
              try {
                require_once __DIR__ . '/../../api/_rev_avisos.php';
                if (function_exists('stream_notif_crear')) stream_notif_crear($pdo, $rev, 'compra', 'Cuenta completa activada · ' . $av['plataforma'],
                  'Tu ' . $av['plataforma'] . ' completa ya está activada y en tu STOCK (' . $cupos . ' cupos). Ya puedes venderla.', 'cuentas.php');
              } catch (Throwable $e) {}
            }
          }
        }
      } catch (Throwable $e) {}
      // AUTO-APROBAR la venta del REVENDEDOR enlazada (él compró a la tienda) + avisarle. Así no le queda
      // "pendiente fantasma" ni tiene que aprobarla él: al aprobar la tienda, la suya queda activada.
      try {
        $hasOrigen = (bool) $pdo->query("SHOW COLUMNS FROM streaming_ventas LIKE 'origen_venta_id'")->fetch();
        if ($hasOrigen) {
          $lk = $pdo->prepare("SELECT id, owner_id, plataforma, email_activar FROM streaming_ventas WHERE origen_venta_id=? AND entregada=0");
          $lk->execute([$vid]);
          foreach ($lk->fetchAll(PDO::FETCH_ASSOC) as $lv) {
            $pdo->prepare("UPDATE streaming_ventas SET entregada=1 WHERE id=?")->execute([(int) $lv['id']]);
            try {
              require_once __DIR__ . '/../../api/_rev_avisos.php';
              if (function_exists('stream_notif_crear')) {
                stream_notif_crear($pdo, (int) $lv['owner_id'], 'compra', 'Activado · ' . $lv['plataforma'],
                  'Tu compra de ' . $lv['plataforma'] . ' (' . (string) $lv['email_activar'] . ') ya fue activada por la tienda.', 'ventas.php');
              }
            } catch (Throwable $e) {}
          }
        }
      } catch (Throwable $e) {}
      $msg = '✓ Venta aprobada y marcada como activada.';
    } else { $msg = 'No se pudo aprobar (¿ya estaba activada?).'; }
  }
  header('Location: pendientes.php?msg=' . urlencode($msg));
  exit;
}
$flash = (string) ($_GET['msg'] ?? '');

// ── RECONCILIACIÓN (auto, al cargar): corrige FANTASMAS ──────────────────────────────────────────
// Compras por invitación/activación del REVENDEDOR que quedaron pendientes (entregada=0) pero cuya venta
// del ADMIN ya fue APROBADA (entregada=1) — pasa con compras VIEJAS (antes de origen_venta_id) que no se
// auto-aprobaron. Se marcan entregadas para que desaparezcan de "sus pendientes" y no queden fantasma.
if ($hasEA && $OWNER > 0) {
  try {
    $pend = $pdo->query("SELECT id, plataforma, email_activar, revendedor_id, origen_venta_id
                           FROM streaming_ventas
                          WHERE owner_id=$OWNER AND entregada=0 AND email_activar IS NOT NULL AND email_activar<>''")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($pend as $pv) {
      $ok = false;
      if ((int) ($pv['origen_venta_id'] ?? 0) > 0) {
        $ok = (bool) $pdo->query("SELECT 1 FROM streaming_ventas WHERE id=" . (int) $pv['origen_venta_id'] . " AND entregada=1")->fetchColumn();
      }
      if (!$ok) {
        $q = $pdo->prepare("SELECT 1 FROM streaming_ventas WHERE owner_id=0 AND revendedor_id=? AND email_activar=? AND plataforma=? AND entregada=1 LIMIT 1");
        $q->execute([(int) $pv['revendedor_id'], (string) $pv['email_activar'], (string) $pv['plataforma']]);
        $ok = (bool) $q->fetchColumn();
      }
      if ($ok) { try { $pdo->prepare("UPDATE streaming_ventas SET entregada=1 WHERE id=?")->execute([(int) $pv['id']]); } catch (Throwable $e) {} }
    }
  } catch (Throwable $e) {}
}

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
    <h1>Pendientes <?= $soloLectura ? '<span class="nm">por activar</span>' : 'de <span class="nm">aprobar</span>' ?></h1>
    <p><?= $soloLectura
        ? 'Tus compras por invitación/activación que la tienda aún no ha activado. Cuando las active, desaparecen de aquí y te llega aviso.'
        : 'Activaciones manuales (Canva y similares). Invita el correo del cliente desde la cuenta maestra y aprueba la venta.' ?></p>
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
          <?php if (!$soloLectura): // la cuenta MAESTRA solo la ve la tienda (no el revendedor) ?>
          <div class="pa-box">
            <div class="lb">🔑 Cuenta para activar</div>
            <div class="pa-acc-line"><span style="color:var(--faint)">Correo:</span> <b style="word-break:break-all"><?= h($r['acc_correo'] ?: '—') ?></b><?php if ($r['acc_correo']): ?><button class="pa-cp" type="button" onclick="paCopy(<?= h(json_encode($r['acc_correo'])) ?>)">copiar</button><?php endif; ?></div>
            <div class="pa-acc-line"><span style="color:var(--faint)">Clave:</span> <b style="word-break:break-all"><?= h($r['acc_clave'] ?: '—') ?></b><?php if ($r['acc_clave']): ?><button class="pa-cp" type="button" onclick="paCopy(<?= h(json_encode($r['acc_clave'])) ?>)">copiar</button><?php endif; ?></div>
          </div>
          <?php else: ?>
          <div class="pa-box" style="display:flex;align-items:center;gap:8px;color:var(--warn)"><i data-lucide="clock" style="width:16px;height:16px"></i> Esperando que la tienda lo active. Te avisaremos cuando esté listo.</div>
          <?php endif; ?>
        </div>

        <?php if (!$soloLectura): ?>
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
        <?php endif; // !$soloLectura ?>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<script>
  function paCopy(t){ try{ navigator.clipboard.writeText(String(t)); }catch(e){} }
</script>
<?php stream_foot();
