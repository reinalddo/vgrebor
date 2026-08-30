<?php
/**
 * SOPORTE / TICKETS (nuevo). El REVENDEDOR abre un ticket (asunto, n° de pedido, descripción, datos/
 * credenciales y una IMAGEN del inconveniente); le llega al ADMIN a su panel como «pendiente». El admin
 * responde y lo marca «resuelto», y al revendedor le aparece resuelto. Cada ticket lleva un HISTORIAL
 * (apertura → mensajes → resuelto) que es un log liviano por ticket (no ralentiza la página).
 *
 *   - Panel del REVENDEDOR (stream_ctx()==='revendedor'): abre tickets y ve SOLO los suyos.
 *   - Panel del ADMIN (owner 0): ve TODOS, responde y resuelve.
 * Aislado por owner_id: un revendedor jamás ve el ticket de otro.
 *
 * Aviso: al abrir/responder/resolver se crea una NOTIFICACIÓN (stream_notif_crear) → el otro lado la ve
 * en su campana y con el TONO en tiempo real del layout.
 */
define('CONEC_ADMIN', true);
require __DIR__ . '/../_auth.php';
require __DIR__ . '/_layout.php';
require __DIR__ . '/../_streaming.php';
require_once __DIR__ . '/../../api/_rev_avisos.php';
admin_require_login();

$esRev = function_exists('stream_ctx') && stream_ctx() === 'revendedor';
// Admin/staff de streaming ven todos; el revendedor ve/abre los suyos. Nadie más entra.
if (!$esRev && !admin_es_admin() && !in_array(admin_wa_area(), ['streaming', 'ambas'], true)) {
  http_response_code(403);
  exit('Esta sección es del área de Streaming.');
}
$pdo   = db();
$OWNER = (int) stream_owner_id();     // 0 admin · >0 revendedor
$csrf  = csrf_token();
st_tickets_schema($pdo);

/** Sube una imagen del ticket (mismo patrón validado que los comprobantes). Devuelve URL o null. */
$subirImg = static function (string $campo): ?string {
  if (empty($_FILES[$campo]['tmp_name']) || !is_uploaded_file($_FILES[$campo]['tmp_name'])
      || (int) $_FILES[$campo]['error'] !== 0 || (int) $_FILES[$campo]['size'] > 5 * 1024 * 1024) return null;
  $ext = strtolower(pathinfo((string) $_FILES[$campo]['name'], PATHINFO_EXTENSION));
  if (!in_array($ext, ['png', 'jpg', 'jpeg', 'webp', 'gif', 'pdf'], true)) return null;
  $dir = __DIR__ . '/../../uploads/streaming-tickets';
  @mkdir($dir, 0755, true);
  $fn = 'tk-' . time() . '-' . random_int(1000, 9999) . '.' . $ext;
  return @move_uploaded_file($_FILES[$campo]['tmp_name'], $dir . '/' . $fn) ? '/uploads/streaming-tickets/' . $fn : null;
};

/** Agrega un evento al historial del ticket. */
$evento = static function (PDO $pdo, int $ticketId, string $tipo, string $mensaje = '', ?string $img = null) use ($esRev): void {
  try {
    $st = $pdo->prepare("INSERT INTO streaming_ticket_eventos (ticket_id, autor_id, autor_nombre, es_admin, tipo, mensaje, imagen_url) VALUES (?,?,?,?,?,?,?)");
    $nombre = null;
    try { $u = function_exists('get_current_user_data') ? (get_current_user_data() ?: []) : []; $nombre = trim((string) ($u['nombre'] ?? '')) ?: null; } catch (Throwable $e) {}
    $st->execute([$ticketId, (int) current_user_id(), $nombre !== null ? mb_substr($nombre, 0, 120) : null, $esRev ? 0 : 1, $tipo, $mensaje !== '' ? $mensaje : null, $img]);
  } catch (Throwable $e) {}
};

/** Carga un ticket respetando el aislamiento por owner (el revendedor solo el suyo). */
$cargarTicket = static function (PDO $pdo, int $id, bool $esRev, int $OWNER): ?array {
  if ($id <= 0) return null;
  try {
    $sql = "SELECT * FROM streaming_tickets WHERE id=?" . ($esRev ? " AND owner_id=" . (int) $OWNER : "");
    $st = $pdo->prepare($sql); $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
  } catch (Throwable $e) { return null; }
};

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $a = (string) ($_POST['accion'] ?? '');
  try {
    if ($a === 'abrir_ticket') {
      if (!$esRev) throw new Exception('Solo un revendedor abre tickets de soporte.');
      $asunto = trim((string) ($_POST['asunto'] ?? ''));
      $desc   = trim((string) ($_POST['descripcion'] ?? ''));
      if ($asunto === '' || $desc === '') throw new Exception('Pon al menos el asunto y la descripción del inconveniente.');
      $ped  = trim((string) ($_POST['pedido_ref'] ?? '')) ?: null;
      $datos = trim((string) ($_POST['datos'] ?? '')) ?: null;
      $img  = $subirImg('imagen');
      $ins = $pdo->prepare("INSERT INTO streaming_tickets (owner_id, asunto, pedido_ref, datos, descripcion, imagen_url, estado, creado_por) VALUES (?,?,?,?,?,?, 'pendiente', ?)");
      $ins->execute([$OWNER, mb_substr($asunto, 0, 180), $ped ? mb_substr($ped, 0, 80) : null, $datos, $desc, $img, (int) current_user_id()]);
      $tid = (int) $pdo->lastInsertId();
      $evento($pdo, $tid, 'creado', $desc, $img);
      // Avisar al ADMIN (owner 0) → le suena el tono en tiempo real.
      stream_notif_crear($pdo, 0, 'ticket', 'Nuevo ticket #' . $tid . ' — ' . mb_substr($asunto, 0, 120),
        $desc, 'tickets.php?id=' . $tid, (int) current_user_id(), $tid);
      $msg = '✓ Ticket enviado. El administrador ya recibió tu solicitud.';
      header('Location: tickets.php?id=' . $tid . '&msg=' . urlencode($msg)); exit;
    }
    elseif ($a === 'responder') {
      $tid = (int) ($_POST['id'] ?? 0);
      $t = $cargarTicket($pdo, $tid, $esRev, $OWNER);
      if (!$t) throw new Exception('Ticket no encontrado.');
      $txt = trim((string) ($_POST['mensaje'] ?? ''));
      $img = $subirImg('imagen');
      if ($txt === '' && $img === null) throw new Exception('Escribe un mensaje o adjunta una imagen.');
      $evento($pdo, $tid, 'mensaje', $txt, $img);
      // Notificar al OTRO lado: si responde el revendedor → al admin; si responde el admin → al revendedor.
      if ($esRev) {
        stream_notif_crear($pdo, 0, 'ticket', 'Respuesta en ticket #' . $tid, $txt, 'tickets.php?id=' . $tid, (int) current_user_id(), $tid);
      } else {
        stream_notif_crear($pdo, (int) $t['owner_id'], 'ticket', 'El soporte respondió tu ticket #' . $tid, $txt, 'tickets.php?id=' . $tid, (int) current_user_id(), $tid);
      }
      $msg = '✓ Mensaje enviado.';
      header('Location: tickets.php?id=' . $tid . '&msg=' . urlencode($msg)); exit;
    }
    elseif ($a === 'garantia') {
      // GARANTÍA / REEMPLAZO (agregado): el admin le manda por correo al revendedor los datos de la
      // cuenta que le entrega en reemplazo, y queda el respaldo por escrito en el historial del ticket.
      // Solo el ADMIN: es él quien entrega el reemplazo.
      if ($esRev) throw new Exception('Solo el administrador envía datos de garantía.');
      $tid = (int) ($_POST['id'] ?? 0);
      $t = $cargarTicket($pdo, $tid, false, 0);
      if (!$t) throw new Exception('Ticket no encontrado.');
      $revId = (int) $t['owner_id'];

      // Los datos salen de una VENTA existente del revendedor (lo normal, así no se teclean mal) o,
      // si la cuenta todavía no está cargada en el sistema, de los campos escritos a mano.
      $ventaId = (int) ($_POST['venta_id'] ?? 0);
      $datos = [];
      if ($ventaId > 0) {
        // Aislamiento: la venta DEBE ser del revendedor de ESTE ticket (suya, o del admin etiquetada a él).
        $q = $pdo->prepare("SELECT plataforma, correo, clave, perfil, pin, fecha_vencimiento
                              FROM streaming_ventas WHERE id=? AND (owner_id=? OR revendedor_id=?) LIMIT 1");
        $q->execute([$ventaId, $revId, $revId]);
        $v = $q->fetch(PDO::FETCH_ASSOC);
        if (!$v) throw new Exception('Esa cuenta no pertenece al revendedor de este ticket.');
        $datos = ['plataforma' => $v['plataforma'], 'correo' => $v['correo'], 'clave' => $v['clave'],
                  'perfil' => $v['perfil'], 'pin' => $v['pin'], 'vencimiento' => $v['fecha_vencimiento']];
      } else {
        foreach (['plataforma', 'correo', 'clave', 'perfil', 'pin', 'vencimiento'] as $k) {
          $datos[$k] = trim((string) ($_POST['g_' . $k] ?? ''));
        }
      }
      if (trim((string) $datos['plataforma']) === '' || trim((string) $datos['correo']) === '') {
        throw new Exception('Elige una cuenta o escribe al menos la plataforma y el correo.');
      }

      $nota = trim((string) ($_POST['g_nota'] ?? ''));
      $mailRev = stream_mail_user_email($pdo, $revId);
      if ($mailRev === '') throw new Exception('El revendedor no tiene un correo válido registrado; no se le puede enviar la garantía.');

      $asunto = 'Garantía · ' . $datos['plataforma'] . ' · Ticket #' . $tid;
      $logo = stream_mail_branding($pdo)['logo_path'];
      $enviado = stream_mail_send($pdo, $mailRev, $asunto, stream_email_html_garantia($pdo, $tid, $datos, $nota, 'revendedor'), $logo);
      // Copia al admin, para que le quede el respaldo del reemplazo entregado.
      $adminMail = (string) (stream_mail_admin_email($pdo) ?? '');
      if ($adminMail !== '') { stream_mail_send($pdo, $adminMail, $asunto, stream_email_html_garantia($pdo, $tid, $datos, $nota, 'dueno'), $logo); }

      // Queda en el historial del ticket SIEMPRE, haya salido el correo o no: es el registro de que el
      // admin entregó el reemplazo. La clave NO se escribe aquí (el historial lo ve el revendedor, pero
      // también queda guardado en claro en la BD; los datos completos ya van en el correo).
      $resumen = 'Datos de garantía enviados a ' . $mailRev . ' · ' . $datos['plataforma'] . ' · ' . $datos['correo']
               . ($nota !== '' ? "\n" . $nota : '')
               . ($enviado ? '' : "\n(⚠ el correo no pudo enviarse; revisa la configuración SMTP)");
      $evento($pdo, $tid, 'garantia', $resumen);
      stream_notif_crear($pdo, $revId, 'ticket', 'Garantía enviada · ticket #' . $tid,
        'Te enviamos por correo los datos de la cuenta de reemplazo (' . $datos['plataforma'] . ').', 'tickets.php?id=' . $tid, (int) current_user_id(), $tid);

      $msg = $enviado
        ? '✓ Datos de garantía enviados a ' . $mailRev . '.'
        : '⚠ Quedó registrado en el ticket, pero el correo NO pudo enviarse (revisa la configuración SMTP).';
      header('Location: tickets.php?id=' . $tid . '&msg=' . urlencode($msg)); exit;
    }
    elseif ($a === 'resolver' || $a === 'reabrir') {
      if ($esRev) throw new Exception('Solo el administrador resuelve tickets.');
      $tid = (int) ($_POST['id'] ?? 0);
      $t = $cargarTicket($pdo, $tid, false, 0);
      if (!$t) throw new Exception('Ticket no encontrado.');
      if ($a === 'resolver') {
        $pdo->prepare("UPDATE streaming_tickets SET estado='resuelto', resuelto_en=NOW(), resuelto_por=? WHERE id=?")->execute([(int) current_user_id(), $tid]);
        $nota = trim((string) ($_POST['nota'] ?? ''));
        $evento($pdo, $tid, 'resuelto', $nota);
        stream_notif_crear($pdo, (int) $t['owner_id'], 'ticket', 'Tu ticket #' . $tid . ' fue RESUELTO ✓',
          ($nota !== '' ? $nota : 'El administrador marcó tu ticket como resuelto.'), 'tickets.php?id=' . $tid, (int) current_user_id(), $tid);
        $msg = '✓ Ticket marcado como resuelto. Se le avisó al revendedor.';
      } else {
        $pdo->prepare("UPDATE streaming_tickets SET estado='pendiente', resuelto_en=NULL, resuelto_por=NULL WHERE id=?")->execute([$tid]);
        $evento($pdo, $tid, 'reabierto', '');
        stream_notif_crear($pdo, (int) $t['owner_id'], 'ticket', 'Tu ticket #' . $tid . ' fue reabierto', '', 'tickets.php?id=' . $tid, (int) current_user_id(), $tid);
        $msg = '✓ Ticket reabierto.';
      }
      header('Location: tickets.php?id=' . $tid . '&msg=' . urlencode($msg)); exit;
    }
  } catch (Throwable $e) {
    $msg = '⚠ ' . $e->getMessage();
    header('Location: tickets.php' . (isset($_POST['id']) ? '?id=' . (int) $_POST['id'] : '') . (isset($_POST['id']) ? '&' : '?') . 'msg=' . urlencode($msg));
    exit;
  }
}

$flash = (string) ($_GET['msg'] ?? '');
$verId = (int) ($_GET['id'] ?? 0);

/** Pill de estado. */
function tk_pill(string $estado): string {
  return $estado === 'resuelto'
    ? '<span class="pill ok">Resuelto</span>'
    : '<span class="pill wait">Pendiente</span>';
}

// ─────────────────────────────── DETALLE DE UN TICKET ───────────────────────────────
if ($verId > 0) {
  $t = $cargarTicket($pdo, $verId, $esRev, $OWNER);
  if (!$t) { stream_head('Soporte', 'tickets'); echo '<div class="card" style="padding:26px;text-align:center;color:var(--muted)">Ticket no encontrado.</div>'; stream_foot(); exit; }
  $eventos = [];
  try { $st = $pdo->prepare("SELECT * FROM streaming_ticket_eventos WHERE ticket_id=? ORDER BY id ASC"); $st->execute([$verId]); $eventos = $st->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) {}
  $revNombre = '';
  if (!$esRev) { try { $revNombre = (string) ($pdo->query("SELECT COALESCE(NULLIF(nombre,''),username,email) FROM usuarios WHERE id=" . (int) $t['owner_id'])->fetchColumn() ?: ('#' . (int) $t['owner_id'])); } catch (Throwable $e) {} }
  // Cuentas del revendedor de este ticket, para el selector de GARANTÍA (solo las ve el admin).
  // Incluye las suyas (owner_id) y las del admin etiquetadas a él (revendedor_id) — las dos formas en
  // que una cuenta puede estar en sus manos. Las más recientes primero: el reemplazo suele ser reciente.
  $ctasRev = [];
  $revMail = '';
  if (!$esRev) {
    try {
      $q = $pdo->prepare("SELECT id, plataforma, correo, perfil, fecha_vencimiento
                            FROM streaming_ventas
                           WHERE (owner_id=? OR revendedor_id=?) AND estado<>'cancelada'
                           ORDER BY id DESC LIMIT 200");
      $q->execute([(int) $t['owner_id'], (int) $t['owner_id']]);
      $ctasRev = $q->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { $ctasRev = []; }
    $revMail = stream_mail_user_email($pdo, (int) $t['owner_id']);
  }
  stream_head('Soporte · Ticket #' . $verId, 'tickets');
  ?>
  <?php if ($flash !== ''): ?><div class="card" style="padding:10px 14px;margin-bottom:12px;border-left:3px solid <?= str_starts_with($flash, '⚠') ? 'var(--bad)' : 'var(--good)' ?>"><?= h($flash) ?></div><?php endif; ?>
  <div style="display:flex;align-items:center;gap:10px;margin-bottom:14px">
    <a href="tickets.php" class="btn ghost" style="padding:7px 11px"><i data-lucide="arrow-left" style="width:15px;height:15px"></i> Volver</a>
    <h1 style="margin:0;font-size:19px">Ticket #<?= (int) $verId ?></h1>
    <?= tk_pill((string) $t['estado']) ?>
  </div>

  <div class="cols" style="align-items:start">
    <div class="card" style="padding:0">
      <div style="padding:16px;border-bottom:1px solid var(--border)">
        <div style="font-size:15px;font-weight:700;color:var(--text)"><?= h((string) $t['asunto']) ?></div>
        <div style="font-size:11.5px;color:var(--faint);margin-top:5px">
          <?= h(date('d/m/Y H:i', strtotime((string) $t['creado_en']))) ?>
          <?php if (!$esRev && $revNombre !== ''): ?> · Revendedor: <b style="color:var(--muted)"><?= h($revNombre) ?></b><?php endif; ?>
          <?php if (!empty($t['pedido_ref'])): ?> · N° pedido: <b style="color:var(--muted)"><?= h((string) $t['pedido_ref']) ?></b><?php endif; ?>
        </div>
      </div>
      <div style="padding:16px;border-bottom:1px solid var(--border)">
        <div style="font-size:11px;font-weight:700;text-transform:uppercase;color:var(--faint);letter-spacing:.04em;margin-bottom:5px">Descripción del inconveniente</div>
        <div style="font-size:13px;color:var(--text);white-space:pre-line"><?= h((string) $t['descripcion']) ?></div>
        <?php if (!empty($t['datos'])): ?>
          <div style="font-size:11px;font-weight:700;text-transform:uppercase;color:var(--faint);letter-spacing:.04em;margin:12px 0 5px">Datos / credenciales</div>
          <div style="font-size:12.5px;color:var(--muted);white-space:pre-line;background:var(--surface-2);border:1px solid var(--border);border-radius:9px;padding:9px 11px"><?= h((string) $t['datos']) ?></div>
        <?php endif; ?>
        <?php if (!empty($t['imagen_url'])): ?>
          <div style="margin-top:12px"><a href="<?= h((string) $t['imagen_url']) ?>" target="_blank" rel="noopener"><img src="<?= h((string) $t['imagen_url']) ?>" alt="Imagen del inconveniente" style="max-width:100%;max-height:260px;border-radius:10px;border:1px solid var(--border)"></a></div>
        <?php endif; ?>
      </div>

      <!-- HISTORIAL / conversación -->
      <div style="padding:16px">
        <div style="font-size:11px;font-weight:700;text-transform:uppercase;color:var(--faint);letter-spacing:.04em;margin-bottom:10px">Historial</div>
        <?php foreach ($eventos as $ev):
          $lado = ((int) $ev['es_admin'] === 1);
          $icon = ['creado' => '📩', 'mensaje' => '💬', 'resuelto' => '✅', 'reabierto' => '🔁', 'garantia' => '🛡️'][(string) $ev['tipo']] ?? '•';
        ?>
          <div style="display:flex;gap:10px;margin-bottom:12px;<?= $lado ? 'flex-direction:row-reverse;text-align:right' : '' ?>">
            <div style="flex:0 0 30px;height:30px;border-radius:8px;display:grid;place-items:center;font-size:15px;background:var(--surface-2)"><?= $icon ?></div>
            <div style="flex:1;min-width:0">
              <div style="font-size:11px;color:var(--faint)"><b style="color:<?= $lado ? 'var(--accent)' : 'var(--muted)' ?>"><?= h((string) ($ev['autor_nombre'] ?: ($lado ? 'Soporte' : 'Revendedor'))) ?></b> · <?= h(date('d/m/Y H:i', strtotime((string) $ev['creado_en']))) ?><?php if ((string) $ev['tipo'] === 'resuelto'): ?> · <span style="color:var(--good);font-weight:700">marcó resuelto</span><?php elseif ((string) $ev['tipo'] === 'reabierto'): ?> · <span style="color:var(--warn);font-weight:700">reabrió</span><?php endif; ?></div>
              <?php if (!empty($ev['mensaje'])): ?><div style="font-size:13px;color:var(--text);margin-top:3px;white-space:pre-line;display:inline-block;background:<?= $lado ? 'var(--accent-soft)' : 'var(--surface-2)' ?>;border-radius:10px;padding:8px 11px"><?= h((string) $ev['mensaje']) ?></div><?php endif; ?>
              <?php if (!empty($ev['imagen_url'])): ?><div style="margin-top:6px"><a href="<?= h((string) $ev['imagen_url']) ?>" target="_blank" rel="noopener"><img src="<?= h((string) $ev['imagen_url']) ?>" style="max-width:180px;max-height:150px;border-radius:9px;border:1px solid var(--border)"></a></div><?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>

        <?php if ((string) $t['estado'] !== 'resuelto' || !$esRev): ?>
        <!-- Responder -->
        <form method="post" enctype="multipart/form-data" style="margin-top:14px;border-top:1px solid var(--border);padding-top:14px">
          <input type="hidden" name="_csrf" value="<?= h($csrf) ?>"><input type="hidden" name="accion" value="responder"><input type="hidden" name="id" value="<?= (int) $verId ?>">
          <textarea name="mensaje" rows="2" class="input" placeholder="Escribe una respuesta…"></textarea>
          <div style="display:flex;align-items:center;gap:10px;margin-top:8px;flex-wrap:wrap">
            <input type="file" name="imagen" accept="image/*,application/pdf" style="font-size:12px;color:var(--muted)">
            <button class="btn primary" style="margin-left:auto"><i data-lucide="send" style="width:14px;height:14px"></i> Enviar</button>
          </div>
        </form>
        <?php endif; ?>
      </div>
    </div>

    <!-- Columna de acciones (admin resuelve) -->
    <div class="card" style="padding:16px">
      <div style="font-size:12px;font-weight:700;color:var(--text);margin-bottom:10px">Estado</div>
      <div style="margin-bottom:12px"><?= tk_pill((string) $t['estado']) ?>
        <?php if ((string) $t['estado'] === 'resuelto' && !empty($t['resuelto_en'])): ?>
          <div style="font-size:11px;color:var(--faint);margin-top:6px">Resuelto el <?= h(date('d/m/Y H:i', strtotime((string) $t['resuelto_en']))) ?></div>
        <?php endif; ?>
      </div>
      <?php if (!$esRev): ?>
        <?php if ((string) $t['estado'] !== 'resuelto'): ?>
          <form method="post">
            <input type="hidden" name="_csrf" value="<?= h($csrf) ?>"><input type="hidden" name="accion" value="resolver"><input type="hidden" name="id" value="<?= (int) $verId ?>">
            <textarea name="nota" rows="2" class="input" placeholder="Nota para el revendedor (opcional): cómo se resolvió…" style="margin-bottom:8px"></textarea>
            <button class="btn primary" style="width:100%"><i data-lucide="check-check" style="width:15px;height:15px"></i> Marcar resuelto</button>
          </form>
        <?php else: ?>
          <form method="post">
            <input type="hidden" name="_csrf" value="<?= h($csrf) ?>"><input type="hidden" name="accion" value="reabrir"><input type="hidden" name="id" value="<?= (int) $verId ?>">
            <button class="btn ghost" style="width:100%"><i data-lucide="rotate-ccw" style="width:15px;height:15px"></i> Reabrir ticket</button>
          </form>
        <?php endif; ?>
      <?php else: ?>
        <p style="font-size:12px;color:var(--muted);margin:0">Cuando el administrador lo resuelva, verás aquí el estado <b>Resuelto</b> y te llegará una notificación.</p>
      <?php endif; ?>
    </div>

    <?php if (!$esRev): ?>
    <!-- GARANTÍA / REEMPLAZO: le manda al revendedor por correo los datos de la cuenta de reemplazo. -->
    <div class="card" style="padding:16px;margin-top:12px">
      <div style="font-size:12px;font-weight:700;color:var(--text);margin-bottom:4px"><i data-lucide="shield-check" style="width:14px;height:14px;vertical-align:-2px"></i> Enviar garantía</div>
      <p style="font-size:11.5px;color:var(--faint);margin:0 0 10px">Le llegan por correo los datos de la cuenta de reemplazo y queda registrado en este ticket.</p>
      <?php if ($revMail === ''): ?>
        <p style="font-size:12px;color:var(--bad);margin:0"><b><?= h($revNombre) ?></b> no tiene un correo válido registrado, así que no se le puede enviar la garantía.</p>
      <?php else: ?>
        <form method="post">
          <input type="hidden" name="_csrf" value="<?= h($csrf) ?>"><input type="hidden" name="accion" value="garantia"><input type="hidden" name="id" value="<?= (int) $verId ?>">
          <div style="font-size:11px;color:var(--faint);margin-bottom:8px">Para: <b style="color:var(--muted)"><?= h($revMail) ?></b></div>
          <select name="venta_id" id="g-venta" onchange="gToggle()" class="input" style="margin-bottom:8px">
            <option value="0">— Escribir los datos a mano —</option>
            <?php foreach ($ctasRev as $c):
              $et = trim((string) $c['plataforma']) . ' · ' . trim((string) $c['correo'])
                  . (trim((string) $c['perfil']) !== '' ? ' · ' . trim((string) $c['perfil']) : '')
                  . (!empty($c['fecha_vencimiento']) ? ' · vence ' . date('d/m/Y', strtotime((string) $c['fecha_vencimiento'])) : '');
            ?>
              <option value="<?= (int) $c['id'] ?>"><?= h($et) ?></option>
            <?php endforeach; ?>
          </select>
          <?php if (!$ctasRev): ?>
            <div style="font-size:11px;color:var(--faint);margin:-4px 0 8px">Este revendedor no tiene cuentas registradas todavía: escribe los datos a mano.</div>
          <?php endif; ?>
          <div id="g-manual" class="grid grid-cols-2 gap-2" style="margin-bottom:8px">
            <input name="g_plataforma" class="input" placeholder="Plataforma">
            <input name="g_correo" class="input" placeholder="Correo de la cuenta">
            <input name="g_clave" class="input" placeholder="Clave">
            <input name="g_perfil" class="input" placeholder="Perfil (opcional)">
            <input name="g_pin" class="input" placeholder="PIN (opcional)">
            <input name="g_vencimiento" type="date" class="input" title="Vencimiento (opcional)">
          </div>
          <textarea name="g_nota" rows="2" class="input" placeholder="Nota para el revendedor (opcional)" style="margin-bottom:8px"></textarea>
          <button class="btn primary" style="width:100%"><i data-lucide="mail" style="width:15px;height:15px"></i> Enviar datos de garantía</button>
        </form>
        <script>
          /* Si eligió una cuenta guardada, se ocultan los campos manuales (sus datos salen de la venta). */
          function gToggle(){
            var s=document.getElementById('g-venta'), m=document.getElementById('g-manual');
            if(s&&m) m.style.display = (s.value!=='0') ? 'none' : '';
          }
          gToggle();
        </script>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
  <?php
  stream_foot();
  exit;
}

// ─────────────────────────────── LISTA DE TICKETS ───────────────────────────────
$fEstado = (string) ($_GET['e'] ?? '');
$where = $esRev ? "owner_id=" . (int) $OWNER : "1";
if ($fEstado === 'pendiente' || $fEstado === 'resuelto') $where .= " AND estado='" . $fEstado . "'";
$tickets = [];
try {
  $sql = "SELECT t.*, COALESCE(NULLIF(u.nombre,''), u.username, u.email) AS rev_nombre,
                 (SELECT COUNT(*) FROM streaming_ticket_eventos e WHERE e.ticket_id=t.id) AS n_eventos
          FROM streaming_tickets t LEFT JOIN usuarios u ON u.id=t.owner_id
          WHERE $where ORDER BY (t.estado='pendiente') DESC, t.id DESC LIMIT 500";
  $tickets = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $tickets = []; }
$nPend = 0; foreach ($tickets as $t) { if ((string) $t['estado'] === 'pendiente') $nPend++; }

stream_head('Soporte', 'tickets');
?>
<div style="display:flex;justify-content:space-between;align-items:flex-end;gap:12px;flex-wrap:wrap;margin-bottom:6px">
  <div>
    <h1 style="margin:0;font-size:20px">Soporte<?= $esRev ? '' : ' · Tickets' ?></h1>
    <p style="margin:4px 0 0;color:var(--muted);font-size:13px"><?= $esRev
      ? 'Abre un ticket cuando tengas un inconveniente. El administrador lo recibe al instante y te avisa cuando lo resuelva.'
      : 'Tickets de soporte de tus revendedores. Respóndelos y márcalos como resueltos.' ?></p>
  </div>
  <?php if ($esRev): ?>
    <button class="btn primary" onclick="document.getElementById('mNuevoTk').style.display='flex'"><i data-lucide="plus"></i> Nuevo ticket</button>
  <?php endif; ?>
</div>

<?php if ($flash !== ''): ?><div class="card" style="padding:10px 14px;margin:12px 0;border-left:3px solid <?= str_starts_with($flash, '⚠') ? 'var(--bad)' : 'var(--good)' ?>"><?= h($flash) ?></div><?php endif; ?>

<div style="display:flex;gap:6px;flex-wrap:wrap;margin:14px 0">
  <a href="tickets.php" class="btn <?= $fEstado === '' ? 'primary' : 'ghost' ?>" style="padding:6px 12px;font-size:12.5px">Todos</a>
  <a href="tickets.php?e=pendiente" class="btn <?= $fEstado === 'pendiente' ? 'primary' : 'ghost' ?>" style="padding:6px 12px;font-size:12.5px">Pendientes<?= $nPend ? ' (' . (int) $nPend . ')' : '' ?></a>
  <a href="tickets.php?e=resuelto" class="btn <?= $fEstado === 'resuelto' ? 'primary' : 'ghost' ?>" style="padding:6px 12px;font-size:12.5px">Resueltos</a>
</div>

<?php if (!$tickets): ?>
  <div class="card" style="padding:34px;text-align:center;color:var(--muted)">
    <div style="font-size:34px;margin-bottom:8px">🎫</div>
    <b style="color:var(--text)"><?= $esRev ? 'No tienes tickets todavía' : 'No hay tickets' ?></b>
    <p style="margin:6px 0 0;font-size:13px"><?= $esRev ? 'Cuando tengas un inconveniente, abre un ticket con «Nuevo ticket».' : 'Cuando un revendedor abra un ticket, aparecerá aquí y te avisará con un tono.' ?></p>
  </div>
<?php else: ?>
  <div class="card" style="padding:0;overflow:hidden">
    <?php foreach ($tickets as $t): ?>
      <a href="tickets.php?id=<?= (int) $t['id'] ?>" style="display:flex;gap:12px;padding:13px 16px;border-bottom:1px solid var(--border);<?= (string) $t['estado'] === 'pendiente' ? 'background:var(--surface-2)' : '' ?>">
        <div style="flex:0 0 34px;height:34px;border-radius:9px;display:grid;place-items:center;font-size:16px;background:var(--accent-soft)">🎫</div>
        <div style="flex:1;min-width:0">
          <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
            <b style="font-size:13.5px;color:var(--text)">#<?= (int) $t['id'] ?> · <?= h((string) $t['asunto']) ?></b>
            <?= tk_pill((string) $t['estado']) ?>
          </div>
          <div style="font-size:12px;color:var(--muted);margin-top:3px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:640px"><?= h(mb_substr((string) ($t['descripcion'] ?? ''), 0, 120)) ?></div>
          <div style="font-size:11px;color:var(--faint);margin-top:5px">
            <?= h(date('d/m/Y H:i', strtotime((string) $t['creado_en']))) ?>
            <?php if (!$esRev && !empty($t['rev_nombre'])): ?> · <?= h((string) $t['rev_nombre']) ?><?php endif; ?>
            <?php if (!empty($t['pedido_ref'])): ?> · pedido <?= h((string) $t['pedido_ref']) ?><?php endif; ?>
            <?php if (!empty($t['imagen_url'])): ?> · 📎 imagen<?php endif; ?>
          </div>
        </div>
        <div style="flex:0 0 auto;color:var(--faint);align-self:center"><i data-lucide="chevron-right" style="width:18px;height:18px"></i></div>
      </a>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php if ($esRev): ?>
<!-- Modal: nuevo ticket (revendedor) -->
<div id="mNuevoTk" style="display:none;position:fixed;inset:0;background:#0008;align-items:flex-start;justify-content:center;padding:28px 14px;z-index:60;overflow:auto">
  <div class="card" style="max-width:520px;width:100%;padding:22px">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px">
      <h3 style="margin:0">Nuevo ticket de soporte</h3>
      <button onclick="document.getElementById('mNuevoTk').style.display='none'" class="iconbtn"><i data-lucide="x"></i></button>
    </div>
    <p class="muted" style="font-size:12.5px;margin:0 0 14px;color:var(--muted)">Cuéntanos el inconveniente. El administrador lo recibe al instante.</p>
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="_csrf" value="<?= h($csrf) ?>"><input type="hidden" name="accion" value="abrir_ticket">
      <div class="field"><label class="flbl" style="font-size:12px;font-weight:600;color:var(--muted)">Asunto *</label><input name="asunto" required class="input" placeholder="Ej: Netflix no carga el perfil"></div>
      <div class="field"><label class="flbl" style="font-size:12px;font-weight:600;color:var(--muted)">N° de pedido / compra <span style="color:var(--faint);font-weight:400">(si aplica)</span></label><input name="pedido_ref" class="input" placeholder="Ej: RBX-000123"></div>
      <div class="field"><label class="flbl" style="font-size:12px;font-weight:600;color:var(--muted)">Descripción del inconveniente *</label><textarea name="descripcion" rows="3" required class="input" placeholder="Explica qué está pasando…"></textarea></div>
      <div class="field"><label class="flbl" style="font-size:12px;font-weight:600;color:var(--muted)">Datos / credenciales solicitados <span style="color:var(--faint);font-weight:400">(opcional)</span></label><textarea name="datos" rows="2" class="input" placeholder="Correo, clave, perfil… lo que el soporte necesite"></textarea></div>
      <div class="field"><label class="flbl" style="font-size:12px;font-weight:600;color:var(--muted)">Imagen del inconveniente <span style="color:var(--faint);font-weight:400">(captura, opcional)</span></label><input type="file" name="imagen" accept="image/*,application/pdf" class="input" style="padding:7px"></div>
      <button class="btn primary" type="submit" style="width:100%"><i data-lucide="send"></i> Enviar ticket</button>
    </form>
  </div>
</div>
<?php endif; ?>
<?php stream_foot();
