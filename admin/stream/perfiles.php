<?php
/**
 * PERFILES — vista tipo "plantilla" por PERFIL (no por cuenta). Cada perfil es una fila:
 * ves cuáles están libres o vendidos, a quién, y puedes VENDER (asignar) 1 o varios perfiles a
 * un cliente o revendedor SIN que sea al azar. Las cuentas con el mismo correo cuentan como una
 * sola por dentro, pero aquí cada perfil se ve por separado. Aislado por owner_id (dueño 0 / rev).
 */
define('CONEC_ADMIN', true);
require __DIR__ . '/../_auth.php';
require __DIR__ . '/_layout.php';
require_once __DIR__ . '/../_streaming.php';
admin_require_login();
if (!admin_es_admin() && !in_array(admin_wa_area(), ['streaming', 'ambas'], true)) {
  http_response_code(403);
  exit('Esta sección es del área de Streaming.');
}
$pdo = db();
$OWNER = (int) stream_owner_id();
$verCostos = function_exists('admin_es_admin') && admin_es_admin();
$esRevCtx = function_exists('stream_ctx') && stream_ctx() === 'revendedor';
$csrf = csrf_token();
// Columnas del stock del revendedor (origen_*, rev_editable): se crean solas si no existen.
if (function_exists('st_rev_stock_schema')) { st_rev_stock_schema($pdo); }

// ── POST: vender perfiles seleccionados (a un cliente o a un revendedor) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $msg = '';
  try {
    if (($_POST['accion'] ?? '') === 'vender_perfiles') {
      $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string) ($_POST['ids'] ?? ''))))));
      if (!$ids) throw new Exception('No seleccionaste ningún perfil.');
      if (count($ids) > 500) throw new Exception('Máximo 500 perfiles por lote.');
      $destino = (($_POST['destino'] ?? 'cliente') === 'revendedor') ? 'revendedor' : 'cliente';
      $cliNombre = trim((string) ($_POST['cliente_nombre'] ?? ''));
      $cliWa = preg_replace('/\D/', '', (string) ($_POST['cliente_wa'] ?? ''));
      $revId = ((int) ($_POST['revendedor_id'] ?? 0)) ?: null;
      $precio = ($_POST['precio'] ?? '') !== '' ? (float) str_replace(',', '.', (string) $_POST['precio']) : null;
      // Datos POR PERFIL (nombre / PIN / precio editados en el modal), indexados por id de perfil.
      $pNombre = is_array($_POST['p_nombre'] ?? null) ? $_POST['p_nombre'] : [];
      $pPin    = is_array($_POST['p_pin'] ?? null) ? $_POST['p_pin'] : [];
      $pPrecio = is_array($_POST['p_precio'] ?? null) ? $_POST['p_precio'] : [];
      $vencIn = trim((string) ($_POST['vencimiento'] ?? ''));
      if ($destino === 'revendedor') { if (!$revId) throw new Exception('Elige a qué revendedor.'); $cliNombre = ''; $cliWa = ''; }
      else { if ($cliNombre === '') throw new Exception('Escribe el nombre del cliente.'); $revId = null; }
      $in = implode(',', $ids);
      $ins = $pdo->prepare("INSERT INTO streaming_ventas (owner_id,plataforma,tipo,cuenta_id,cliente_nombre,cliente_wa,correo,clave,perfil,pin,precio,precio_renovacion,fecha_inicio,fecha_vencimiento,creado_por,revendedor_id,entregada) VALUES (?,?,'perfil',?,?,?,?,?,?,?,?,?,CURDATE(),?,?,?,1)");
      $insC = $pdo->prepare("INSERT INTO streaming_ventas (owner_id,plataforma,tipo,cuenta_id,cliente_nombre,cliente_wa,correo,clave,perfil,pin,precio,precio_renovacion,fecha_inicio,fecha_vencimiento,creado_por,revendedor_id,entregada) VALUES (?,?,'cuenta',?,?,?,?,?,?,?,?,?,CURDATE(),?,?,?,1)");
      $upP = $pdo->prepare("UPDATE streaming_perfiles SET estado='vendido', venta_id=? WHERE id=? AND estado='libre'");
      $vend = 0; $entregados = 0;
      $vencDe = static function ($rowVenc, $platId) use ($vencIn, $pdo) {
        if ($vencIn !== '' && strtotime($vencIn)) return date('Y-m-d', strtotime($vencIn));
        if ($rowVenc && strtotime((string) $rowVenc)) return (string) $rowVenc;
        $dd = (int) ($pdo->query("SELECT COALESCE(dias_default,30) FROM streaming_plataformas WHERE id=" . (int) $platId)->fetchColumn() ?: 30);
        return date('Y-m-d', strtotime("+$dd days"));
      };
      $pdo->beginTransaction();
      try {
        // Trae la unidad de venta de la plataforma: 'cuenta' (completa) se agrupa en UNA venta por cuenta.
        $rows = $pdo->query("SELECT p.id, p.etiqueta, p.pin, c.id AS cuenta_id, c.plataforma, c.correo, c.clave, c.vencimiento, c.plataforma_id, c.precio_venta, c.precio_reventa, COALESCE(pl.unidad_venta,'perfil') AS unidad_venta
                             FROM streaming_perfiles p JOIN streaming_cuentas c ON c.id = p.cuenta_id
                             LEFT JOIN streaming_plataformas pl ON pl.id=c.plataforma_id AND pl.owner_id=c.owner_id
                             WHERE p.id IN ($in) AND c.owner_id=$OWNER AND p.estado='libre' FOR UPDATE")->fetchAll(PDO::FETCH_ASSOC);
        // Si el modal marcó CUÁLES perfiles vender (p_sel[]), se venden solo esos (los no marcados se quedan).
        $incluir = is_array($_POST['p_sel'] ?? null) ? array_map('intval', array_keys($_POST['p_sel'])) : [];
        if ($incluir) { $rows = array_values(array_filter($rows, static fn($p) => in_array((int) $p['id'], $incluir, true))); }
        if (!$rows) throw new Exception('No marcaste ningún perfil para vender.');
        // REGLA (cliente): "cuenta completa" = se venden TODOS los perfiles de una misma cuenta (mismo
        // correo) de una sola vez → 1 línea (tipo='cuenta'). Si se venden ALGUNOS (menos del total) →
        // 1 línea por perfil. NO depende de unidad_venta de la plataforma: manda cuántos perfiles marcaste.
        $byCuenta = [];
        foreach ($rows as $p) { $byCuenta[(int) $p['cuenta_id']][] = $p; }
        $grupos = []; $sueltos = [];
        foreach ($byCuenta as $cidG => $grp) {
          $totalCuenta = (int) ($pdo->query("SELECT COUNT(*) FROM streaming_perfiles WHERE cuenta_id=$cidG AND estado<>'inactivo'")->fetchColumn() ?: 0);
          if ($totalCuenta > 1 && count($grp) >= $totalCuenta) { $grupos[$cidG] = $grp; }   // TODOS → cuenta completa
          else { foreach ($grp as $p) { $sueltos[] = $p; } }                                   // subconjunto → por perfil
        }

        // (A) CUENTA COMPLETA → 1 sola línea (tipo='cuenta').
        foreach ($grupos as $cidG => $grp) {
          $p0 = $grp[0];
          $venc = $vencDe($p0['vencimiento'], $p0['plataforma_id']);
          $precioG = $precio; if ($precioG === null) { $ov = ($destino === 'revendedor') ? $p0['precio_reventa'] : $p0['precio_venta']; $precioG = ($ov !== null && $ov !== '') ? (float) $ov : null; }
          $nombres = implode(', ', array_map(static fn($x) => (string) ($x['etiqueta'] ?? ''), $grp));
          $insC->execute([$OWNER, $p0['plataforma'], $cidG, $cliNombre ?: null, $cliWa ?: null, $p0['correo'], $p0['clave'], $nombres, (string) ($p0['pin'] ?? ''), $precioG, $precioG, $venc, current_user_id(), $revId]);
          $vidG = (int) $pdo->lastInsertId();
          foreach ($grp as $p) { $upP->execute([$vidG, (int) $p['id']]); $vend++; }
          if ($revId) { $entregados += st_rev_entregar($pdo, (int) $revId, $cidG, $grp, (float) ($precioG ?? 0), true, $venc); }
        }

        // (B) PERFILES sueltos → una venta por perfil.
        foreach ($sueltos as $p) {
          $pid = (int) $p['id'];
          $venc = $vencDe($p['vencimiento'], $p['plataforma_id']);
          $etq = array_key_exists($pid, $pNombre) && trim((string) $pNombre[$pid]) !== '' ? trim((string) $pNombre[$pid]) : (string) $p['etiqueta'];
          $pin = array_key_exists($pid, $pPin) ? trim((string) $pPin[$pid]) : (string) $p['pin'];
          $rowPrecio = (array_key_exists($pid, $pPrecio) && $pPrecio[$pid] !== '' && $pPrecio[$pid] !== null) ? (float) str_replace(',', '.', (string) $pPrecio[$pid]) : $precio;
          if ($rowPrecio === null) { $ov = ($destino === 'revendedor') ? $p['precio_reventa'] : $p['precio_venta']; $rowPrecio = ($ov !== null && $ov !== '') ? (float) $ov : null; }
          $ins->execute([$OWNER, $p['plataforma'], (int) $p['cuenta_id'], $cliNombre ?: null, $cliWa ?: null, $p['correo'], $p['clave'], $etq, $pin, $rowPrecio, $rowPrecio, $venc, current_user_id(), $revId]);
          $upP->execute([(int) $pdo->lastInsertId(), $pid]);
          try { $pdo->prepare("UPDATE streaming_perfiles SET etiqueta=?, pin=? WHERE id=?")->execute([$etq, $pin, $pid]); } catch (Throwable $e) {}
          $vend++;
          if ($revId) { $entregados += st_rev_entregar($pdo, (int) $revId, (int) $p['cuenta_id'], [['id' => $pid, 'etiqueta' => $etq, 'pin' => $pin]], (float) ($rowPrecio ?? 0), false, $venc); }
        }
        $pdo->commit();
      } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
      // BOT de códigos (tras el commit): asigna UNA vez cada correo nuevo que le quedó al revendedor.
      if ($revId && function_exists('bot_codigos_flush')) { try { bot_codigos_flush($pdo, (int) $revId); } catch (Throwable $e) {} }
      if ($revId && $vend > 0) {
        try {
          require_once __DIR__ . '/../../api/_rev_avisos.php';
          stream_notif_crear($pdo, (int) $revId, 'asignacion', $vend . ' perfil(es) nuevos en tu stock',
            'El administrador te asignó ' . $vend . ' perfil(es). Ya están en tu inventario para que los vendas.',
            'perfiles.php', (int) current_user_id());
        } catch (Throwable $e) {}
      }
      $saltados = count($ids) - $vend;
      $msg = "✓ $vend perfil(es) vendido(s)" . ($saltados > 0 ? " · $saltados ya no estaban libres" : '')
           . ($revId ? " · $entregados quedaron en el stock del revendedor" : '') . '. Míralos en Ventas.';
    }
    // #12: editar un perfil — nombre y PIN; y (para el dueño) costo/venta/reventa de SU cuenta.
    elseif (($_POST['accion'] ?? '') === 'editar_perfil') {
      $pid = (int) ($_POST['pid'] ?? 0);
      if ($pid <= 0) throw new Exception('Perfil inválido.');
      $cid = (int) ($pdo->query("SELECT c.id FROM streaming_perfiles p JOIN streaming_cuentas c ON c.id=p.cuenta_id WHERE p.id=$pid AND c.owner_id=$OWNER")->fetchColumn() ?: 0);
      if ($cid <= 0) throw new Exception('Perfil no encontrado.');
      // ¿Cuenta COMPLETA? (rev_editable=1). Solo entonces el revendedor edita nombre/PIN/CLAVE; el admin siempre.
      $esCompleta = false;
      try { $esCompleta = ((int) ($pdo->query("SELECT COALESCE(rev_editable,0) FROM streaming_cuentas WHERE id=$cid")->fetchColumn() ?: 0)) === 1; } catch (Throwable $e) {}
      $puedeCuenta = !$esRevCtx || $esCompleta;
      $vid = (int) ($pdo->query("SELECT venta_id FROM streaming_perfiles WHERE id=$pid")->fetchColumn() ?: 0);

      // (1) ASIGNAR CLIENTE + FECHA del cliente — SIEMPRE (aunque sea perfil suelto), para poder venderlo/darle
      //     fecha a SU cliente. Solo toca los datos del cliente y su vencimiento (no la cuenta).
      $cliN = trim((string) ($_POST['cliente_nombre'] ?? ''));
      $cliW = preg_replace('/\D+/', '', (string) ($_POST['cliente_wa'] ?? ''));
      $vencCli = trim((string) ($_POST['fecha_venc'] ?? ''));
      if ($vid > 0 && ($cliN !== '' || $cliW !== '' || $vencCli !== '')) {
        $s2 = []; $a2 = [];
        if ($cliN !== '') { $s2[] = 'cliente_nombre=?'; $a2[] = mb_substr($cliN, 0, 120); }
        if ($cliW !== '') { $s2[] = 'cliente_wa=?'; $a2[] = $cliW; }
        if ($vencCli !== '' && strtotime($vencCli)) { $s2[] = 'fecha_vencimiento=?'; $a2[] = date('Y-m-d', strtotime($vencCli)); }
        if ($s2) { $a2[] = $vid; $pdo->prepare("UPDATE streaming_ventas SET " . implode(',', $s2) . " WHERE id=?")->execute($a2); }
      }

      // (2) NOMBRE / PIN. Revendedor: cuenta COMPLETA sin límite; perfil suelto MÁX 2 veces por periodo
      //     (se reinicia al renovar). Admin siempre. Se SINCRONIZA al otro lado (por origen_perfil_id) + notif.
      $etq = trim((string) ($_POST['etiqueta'] ?? ''));
      $pin = trim((string) ($_POST['pin'] ?? ''));
      if ($etq !== '' || $pin !== '') {
        if ($esRevCtx && !$esCompleta) {
          $usados = (int) ($pdo->query("SELECT COALESCE(cambios_np,0) FROM streaming_perfiles WHERE id=$pid")->fetchColumn() ?: 0);
          if ($usados >= 2) throw new Exception('⚠ Ya cambiaste el nombre/PIN 2 veces este periodo. Se reinicia al renovar.');
        }
        // Valores VIEJOS (para el aviso "antes → después").
        $oldNP = ['etiqueta' => '', 'pin' => ''];
        try { $oldNP = $pdo->query("SELECT COALESCE(etiqueta,'') etiqueta, COALESCE(pin,'') pin FROM streaming_perfiles WHERE id=$pid")->fetch(PDO::FETCH_ASSOC) ?: $oldNP; } catch (Throwable $e) {}
        $pdo->prepare("UPDATE streaming_perfiles SET etiqueta=?, pin=? WHERE id=?")->execute([$etq !== '' ? mb_substr($etq, 0, 60) : null, $pin !== '' ? $pin : null, $pid]);
        if ($esRevCtx && !$esCompleta) { try { $pdo->prepare("UPDATE streaming_perfiles SET cambios_np=COALESCE(cambios_np,0)+1 WHERE id=?")->execute([$pid]); } catch (Throwable $e) {} }
        // Detalle "Antes → Ahora" reutilizable en el aviso.
        $npCamb = [];
        if ($etq !== '') $npCamb[] = 'Nombre: "' . ($oldNP['etiqueta'] ?: '—') . '" → "' . $etq . '"';
        if ($pin !== '') $npCamb[] = 'PIN: "' . ($oldNP['pin'] ?: '—') . '" → "' . $pin . '"';
        $npDetalle = $npCamb ? ' ' . implode(' · ', $npCamb) . '.' : '';
        if ($vid > 0) { $sv = []; $av = []; if ($etq !== '') { $sv[] = 'perfil=?'; $av[] = $etq; } if ($pin !== '') { $sv[] = 'pin=?'; $av[] = $pin; } if ($sv) { $av[] = $vid; $pdo->prepare("UPDATE streaming_ventas SET " . implode(',', $sv) . " WHERE id=?")->execute($av); } }
        require_once __DIR__ . '/../../api/_rev_avisos.php';
        $plt = (string) ($pdo->query("SELECT plataforma FROM streaming_cuentas WHERE id=$cid")->fetchColumn() ?: '');
        $tipoTxt = $esCompleta ? 'cuenta completa' : 'perfil';
        $svn = []; $avn = [];
        if ($etq !== '') { $svn[] = 'etiqueta=?'; $avn[] = mb_substr($etq, 0, 60); }
        if ($pin !== '') { $svn[] = 'pin=?'; $avn[] = $pin; }
        if ($esRevCtx) {
          // Revendedor → actualiza al ADMIN el perfil ORIGEN (origen_perfil_id) + le notifica.
          try {
            $og = (int) ($pdo->query("SELECT origen_perfil_id FROM streaming_perfiles WHERE id=$pid")->fetchColumn() ?: 0);
            // SOLO si es stock comprado a la tienda (origen). En cuentas PROPIAS del revendedor NO se
            // actualiza ni se le notifica al admin (el admin solo recibe avisos de lo que le compró a él).
            if ($og > 0) {
              if ($svn) { $a = $avn; $a[] = $og; $pdo->prepare("UPDATE streaming_perfiles SET " . implode(',', $svn) . " WHERE id=?")->execute($a); }
              $rn = (string) ($pdo->query("SELECT COALESCE(NULLIF(nombre,''),username) FROM usuarios WHERE id=$OWNER")->fetchColumn() ?: '');
              $ogCid = (int) ($pdo->query("SELECT cuenta_id FROM streaming_perfiles WHERE id=$og")->fetchColumn() ?: 0);   // cuenta origen (para resaltar)
              stream_notif_crear($pdo, 0, 'cuenta', 'Cambio de nombre/PIN · ' . ($plt ?: $tipoTxt),
                'El revendedor ' . ($rn ?: '#' . $OWNER) . ' cambió el nombre/PIN de un ' . $tipoTxt . ' de ' . ($plt ?: 'streaming') . '.' . $npDetalle,
                'cuentas.php' . ($ogCid > 0 ? '?hl=' . $ogCid : ''), (int) current_user_id(), $ogCid ?: null);
            }
          } catch (Throwable $e) {}
        } else {
          // Admin → actualiza los ESPEJOS (origen_perfil_id=$pid) + notifica a esos revendedores.
          try {
            if ($svn) { $a = $avn; $a[] = $pid; $pdo->prepare("UPDATE streaming_perfiles SET " . implode(',', $svn) . " WHERE origen_perfil_id=?")->execute($a); }
            foreach ($pdo->query("SELECT DISTINCT c.owner_id FROM streaming_perfiles sp JOIN streaming_cuentas c ON c.id=sp.cuenta_id WHERE sp.origen_perfil_id=$pid AND COALESCE(c.owner_id,0)>0")->fetchAll(PDO::FETCH_COLUMN) as $rev) {
              stream_notif_crear($pdo, (int) $rev, 'cuenta', 'La tienda actualizó un perfil de ' . ($plt ?: 'streaming'),
                'El administrador cambió el nombre/PIN de un perfil. Ya está actualizado en tu stock.', 'perfiles.php');
            }
          } catch (Throwable $e) {}
        }
      }

      // (3) CLAVE de la cuenta — solo cuenta completa (o admin) + SINCRONIZACIÓN bidireccional + aviso.
      $clave = trim((string) ($_POST['clave'] ?? ''));
      if ($puedeCuenta && $clave !== '') {
        $pdo->prepare("UPDATE streaming_cuentas SET clave=? WHERE id=? AND owner_id=$OWNER")->execute([$clave, $cid]);
        try { $pdo->prepare("UPDATE streaming_ventas SET clave=? WHERE cuenta_id=? AND estado='activa'")->execute([$clave, $cid]); } catch (Throwable $e) {}
        require_once __DIR__ . '/../../api/_rev_avisos.php';
        if ($esRevCtx && $esCompleta) {
          // El REVENDEDOR cambió la clave de SU cuenta completa → se actualiza en TU inventario (origen)
          // + otros espejos, y TE llega notificación. (Solo la CLAVE, no perfiles/PIN.)
          $origen = (int) ($pdo->query("SELECT origen_cuenta_id FROM streaming_cuentas WHERE id=$cid")->fetchColumn() ?: 0);
          $plt = (string) ($pdo->query("SELECT plataforma FROM streaming_cuentas WHERE id=$cid")->fetchColumn() ?: '');
          // SOLO si la cuenta completa es del stock comprado a la tienda (origen). Si es PROPIA del
          // revendedor, no se toca el inventario del admin ni se le notifica.
          if ($origen > 0) {
            $oldClave = (string) ($pdo->query("SELECT COALESCE(clave,'') FROM streaming_cuentas WHERE id=$origen")->fetchColumn() ?: '');   // clave vieja (antes→después)
            try { $pdo->prepare("UPDATE streaming_cuentas SET clave=? WHERE id=? AND owner_id=0")->execute([$clave, $origen]); } catch (Throwable $e) {}
            try { $pdo->prepare("UPDATE streaming_ventas SET clave=? WHERE cuenta_id=? AND estado='activa'")->execute([$clave, $origen]); } catch (Throwable $e) {}
            if (function_exists('st_rev_propagar_a_espejos')) { try { st_rev_propagar_a_espejos($pdo, $origen, null, $clave); } catch (Throwable $e) {} }
            try {
              $rn = (string) ($pdo->query("SELECT COALESCE(NULLIF(nombre,''),username) FROM usuarios WHERE id=$OWNER")->fetchColumn() ?: '');
              stream_notif_crear($pdo, 0, 'cuenta', 'Cambio de CLAVE · ' . ($plt ?: 'cuenta completa'),
                'El revendedor ' . ($rn ?: '#' . $OWNER) . ' cambió la clave de su cuenta completa (' . $plt . '). Clave: "' . ($oldClave ?: '—') . '" → "' . $clave . '". Se actualizó en tu inventario.',
                'cuentas.php?hl=' . $origen, (int) current_user_id(), $origen);
            } catch (Throwable $e) {}
          }
        } elseif (!$esRevCtx) {
          // El ADMIN cambió la clave → se propaga a los espejos de los revendedores y les AVISA.
          $plt = (string) ($pdo->query("SELECT plataforma FROM streaming_cuentas WHERE id=$cid")->fetchColumn() ?: '');
          if (function_exists('st_rev_propagar_a_espejos')) { try { st_rev_propagar_a_espejos($pdo, $cid, null, $clave); } catch (Throwable $e) {} }
          if (function_exists('rev_avisar_credenciales')) { try { rev_avisar_credenciales($pdo, $cid, $plt); } catch (Throwable $e) {} }
        }
      }

      // (4) Precios de la CUENTA (su pricing). Solo el dueño edita costo/reventa; venta la ajusta cualquiera.
      $num = static function ($k) { $r = str_replace(',', '.', trim((string) ($_POST[$k] ?? ''))); return ($r !== '' && is_numeric($r)) ? (float) $r : null; };
      $sc = []; $ac = [];
      $pv = $num('precio_venta'); if ($pv !== null) { $sc[] = 'precio_venta=?'; $ac[] = $pv; }
      if ($verCostos) { $co = $num('costo'); if ($co !== null) { $sc[] = 'costo=?'; $ac[] = $co; } $pr = $num('precio_reventa'); if ($pr !== null) { $sc[] = 'precio_reventa=?'; $ac[] = $pr; } }
      elseif ($esRevCtx) {
        // El revendedor edita costo SOLO en cuentas PROPIAS (no en el stock comprado a la tienda, que es
        // automático). Se detecta por origen_cuenta_id: 0/NULL = propia.
        $esMirrorPf = ((int) ($pdo->query("SELECT COALESCE(origen_cuenta_id,0) FROM streaming_cuentas WHERE id=$cid")->fetchColumn() ?: 0)) > 0;
        if (!$esMirrorPf) { $co = $num('costo'); if ($co !== null) { $sc[] = 'costo=?'; $ac[] = $co; } }
      }
      if ($sc) { $ac[] = $cid; $pdo->prepare("UPDATE streaming_cuentas SET " . implode(',', $sc) . " WHERE id=? AND owner_id=$OWNER")->execute($ac); }
      $msg = '✓ Actualizado.';
    }
    // FASE 3: el revendedor SOLICITA a la tienda el cambio de perfil/PIN (no lo edita él).
    // Deja notificación al admin (y le devolvemos un link de WhatsApp para avisarle al instante).
    elseif (($_POST['accion'] ?? '') === 'solicitar_cambio') {
      $pid = (int) ($_POST['pid'] ?? 0);
      $que = (($_POST['que'] ?? '') === 'pin') ? 'PIN' : (($_POST['que'] ?? '') === 'ambos' ? 'perfil y PIN' : 'perfil');
      $nota = trim((string) ($_POST['nota'] ?? ''));
      if ($pid <= 0) throw new Exception('Perfil inválido.');
      $row = $pdo->query("SELECT p.etiqueta, p.pin, c.plataforma, c.correo, c.origen_cuenta_id
                          FROM streaming_perfiles p JOIN streaming_cuentas c ON c.id=p.cuenta_id
                          WHERE p.id=$pid AND c.owner_id=$OWNER")->fetch(PDO::FETCH_ASSOC);
      if (!$row) throw new Exception('Perfil no encontrado.');
      $quien = '';
      try { $quien = (string) ($pdo->query("SELECT COALESCE(NULLIF(nombre,''), username) FROM usuarios WHERE id=" . (int) current_user_id())->fetchColumn() ?: ''); } catch (Throwable $e) {}
      $detalle = 'Solicita cambio de ' . $que . ' · ' . (string) $row['plataforma'] . ' · cuenta ' . (string) $row['correo']
               . ' · perfil actual: ' . ((string) ($row['etiqueta'] ?? '') ?: '—')
               . (((string) ($row['pin'] ?? '')) !== '' ? ' · PIN: ' . (string) $row['pin'] : '')
               . ($nota !== '' ? "\nNota: " . $nota : '');
      require_once __DIR__ . '/../../api/_rev_avisos.php';
      stream_notif_crear($pdo, 0, 'solicitud', 'Solicitud de cambio de ' . $que . ' · ' . ($quien ?: 'Revendedor'),
        $detalle, 'perfiles.php', (int) current_user_id(), $pid);
      stream_notif_crear($pdo, $OWNER, 'solicitud', 'Enviaste una solicitud de cambio de ' . $que,
        'La tienda ya recibió tu solicitud sobre ' . (string) $row['plataforma'] . ' (' . (string) $row['correo'] . ').', 'perfiles.php', (int) current_user_id(), $pid);
      // WhatsApp del admin para avisarle al toque (si está configurado en la tienda).
      $waAdmin = '';
      foreach (['configuracion_general', 'configuracion'] as $ct) {
        try { $w = (string) ($pdo->query("SELECT valor FROM $ct WHERE clave='whatsapp' LIMIT 1")->fetchColumn() ?: ''); if ($w !== '') { $waAdmin = preg_replace('/\D+/', '', $w); break; } } catch (Throwable $e) {}
      }
      $msg = '✓ Solicitud enviada a la tienda.';
      if ($waAdmin !== '') { $msg .= ' |WA|' . $waAdmin . '|' . rawurlencode('Hola, soy ' . ($quien ?: 'tu revendedor') . '. ' . $detalle); }
    }
    // #: renovar un perfil por N meses. Si está VENDIDO extiende el vencimiento de la VENTA (el cliente);
    // si está libre, extiende el vencimiento de la CUENTA (tu stock). Cada uno parte de SU propia fecha.
    elseif (($_POST['accion'] ?? '') === 'renovar_perfil') {
      $pid = (int) ($_POST['pid'] ?? 0);
      $meses = max(1, min(24, (int) ($_POST['meses'] ?? 1)));
      if ($pid <= 0) throw new Exception('Perfil inválido.');
      $row = $pdo->query("SELECT p.venta_id, p.cuenta_id, c.vencimiento FROM streaming_perfiles p JOIN streaming_cuentas c ON c.id=p.cuenta_id WHERE p.id=$pid AND c.owner_id=$OWNER")->fetch(PDO::FETCH_ASSOC);
      if (!$row) throw new Exception('Perfil no encontrado.');
      // Al RENOVAR se reinician los cambios de nombre/PIN permitidos (vuelve a 0/2).
      try { $pdo->prepare("UPDATE streaming_perfiles SET cambios_np=0 WHERE id=?")->execute([$pid]); } catch (Throwable $e) {}
      $vid = (int) ($row['venta_id'] ?? 0);
      if ($vid > 0) {
        $base = (string) ($pdo->query("SELECT fecha_vencimiento FROM streaming_ventas WHERE id=$vid")->fetchColumn() ?: '');
        $from = ($base && strtotime($base) > time()) ? $base : date('Y-m-d');
        $nueva = date('Y-m-d', strtotime("$from +$meses months"));
        $pdo->prepare("UPDATE streaming_ventas SET fecha_vencimiento=?, estado='activa' WHERE id=?")->execute([$nueva, $vid]);
        $msg = "✓ Renovado (cliente) hasta " . date('d/m/Y', strtotime($nueva)) . " · +$meses mes(es).";
      } else {
        $base = (string) ($row['vencimiento'] ?? '');
        $from = ($base && strtotime($base) > time()) ? $base : date('Y-m-d');
        $nueva = date('Y-m-d', strtotime("$from +$meses months"));
        $pdo->prepare("UPDATE streaming_cuentas SET vencimiento=? WHERE id=? AND owner_id=$OWNER")->execute([$nueva, (int) $row['cuenta_id']]);
        $msg = "✓ Cuenta renovada hasta " . date('d/m/Y', strtotime($nueva)) . " · +$meses mes(es).";
      }
    }
    // ── LOTE desde Perfiles: cambiar precios de las CUENTAS de los perfiles marcados ──
    elseif (($_POST['accion'] ?? '') === 'bulk_precios_p') {
      $pids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string) ($_POST['ids'] ?? ''))))));
      if (!$pids) throw new Exception('No seleccionaste perfiles.');
      $in = implode(',', $pids);
      $num = static function ($k) { $r = str_replace(',', '.', trim((string) ($_POST[$k] ?? ''))); return ($r !== '' && is_numeric($r)) ? (float) $r : null; };
      $venta = $num('precio_venta'); $reventa = $num('precio_reventa'); $costo = $num('costo');
      if ($venta === null && $reventa === null && $costo === null) throw new Exception('Escribe al menos un precio.');
      $ctas = $pdo->query("SELECT DISTINCT c.id FROM streaming_perfiles p JOIN streaming_cuentas c ON c.id=p.cuenta_id WHERE p.id IN ($in) AND c.owner_id=$OWNER")->fetchAll(PDO::FETCH_COLUMN);
      if (!$ctas) throw new Exception('No hay cuentas para esos perfiles.');
      $cin = implode(',', array_map('intval', $ctas)); $partes = [];
      if ($venta !== null || $reventa !== null) {
        $sets = []; $args = [];
        if ($venta   !== null) { $sets[] = 'precio_venta=?';   $args[] = $venta;   $partes[] = 'venta $' . number_format($venta, 2); }
        if ($reventa !== null) { $sets[] = 'precio_reventa=?'; $args[] = $reventa; $partes[] = 'reventa $' . number_format($reventa, 2); }
        $pdo->prepare("UPDATE streaming_cuentas SET " . implode(',', $sets) . " WHERE id IN ($cin) AND owner_id=$OWNER")->execute($args);
        // El precio de REVENTA = costo del revendedor → propágalo al costo de su cuenta espejo (total = reventa × perfiles).
        if ($reventa !== null && (int) $OWNER === 0) {
          try { foreach ($ctas as $cid3) { foreach ($pdo->query("SELECT id FROM streaming_cuentas WHERE origen_cuenta_id=" . (int) $cid3 . " AND COALESCE(owner_id,0)>0")->fetchAll(PDO::FETCH_COLUMN) as $mid3) { $np = (int) $pdo->query("SELECT COUNT(*) FROM streaming_perfiles WHERE cuenta_id=" . (int) $mid3)->fetchColumn(); try { $pdo->prepare("UPDATE streaming_cuentas SET costo=? WHERE id=?")->execute([round($reventa * max(1, $np), 2), (int) $mid3]); } catch (Throwable $e) {} } } } catch (Throwable $e) {}
        }
      }
      if ($costo !== null) {
        $wc = "id IN ($cin) AND owner_id=$OWNER" . ($esRevCtx ? " AND COALESCE(origen_cuenta_id,0)=0" : "");
        $pdo->prepare("UPDATE streaming_cuentas SET costo=? WHERE $wc")->execute([$costo]);
        $partes[] = 'costo $' . number_format($costo, 2) . ($esRevCtx ? ' (solo propias)' : '');
      }
      $msg = '✓ ' . implode(' · ', $partes) . ' en las cuentas de ' . count($pids) . ' perfil(es).';
    }
    // ── LOTE desde Perfiles: CAMBIAR / REASIGNAR VENDEDOR (sin borrar) ──
    // Mueve los perfiles seleccionados (su venta) a otro revendedor: quita del viejo, entrega al nuevo y
    // actualiza la venta. Sirve para corregir una asignación equivocada sin tener que eliminar y revender.
    elseif (($_POST['accion'] ?? '') === 'reasignar_vendedor_p') {
      if ($esRevCtx) throw new Exception('Solo el dueño puede cambiar el cliente/vendedor.');
      $pids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string) ($_POST['ids'] ?? ''))))));
      if (!$pids) throw new Exception('No seleccionaste perfiles.');
      $revRaw = (string) ($_POST['revendedor_id'] ?? '');       // '' = no cambiar vendedor · '0' = quitar · >0 = reasignar
      $cambiaRev = ($revRaw !== '');
      $nuevoRev  = $cambiaRev ? (int) $revRaw : -1;
      // Cliente (opcional): id de un cliente PROPIO existente, o un nombre nuevo + WhatsApp.
      $nuevoCliId = (int) ($_POST['cliente_id'] ?? 0);
      $nuevoCli   = trim((string) ($_POST['cliente_nombre'] ?? ''));
      $nuevoWa    = preg_replace('/\D+/', '', (string) ($_POST['cliente_wa'] ?? ''));
      $cliRow = null;
      if ($nuevoCliId > 0) {
        try { $qc = $pdo->prepare("SELECT id, nombre, wa FROM streaming_clientes WHERE id=? AND owner_id=?"); $qc->execute([$nuevoCliId, $OWNER]); $cliRow = $qc->fetch(PDO::FETCH_ASSOC) ?: null; } catch (Throwable $e) {}
        if ($cliRow) { $nuevoCli = (string) $cliRow['nombre']; if ($nuevoWa === '') $nuevoWa = preg_replace('/\D+/', '', (string) ($cliRow['wa'] ?? '')); }
      }
      $cambiaCli = ($cliRow || $nuevoCli !== '' || $nuevoWa !== '');
      if (!$cambiaRev && !$cambiaCli) throw new Exception('No indicaste ningún cambio (cliente o vendedor).');
      $in = implode(',', $pids);
      $vids = array_map('intval', $pdo->query("SELECT DISTINCT venta_id FROM streaming_perfiles WHERE id IN ($in) AND venta_id IS NOT NULL AND venta_id>0")->fetchAll(PDO::FETCH_COLUMN));
      if (!$vids) throw new Exception('Esos perfiles no están vendidos/asignados; no hay venta que cambiar.');
      $flush = []; $nOk = 0; $nCli = 0;
      $pdo->beginTransaction();
      try {
        foreach ($vids as $vid) {
          $vid = (int) $vid;
          if ($cambiaRev && function_exists('st_rev_reasignar_venta')) { $f = st_rev_reasignar_venta($pdo, $vid, $nuevoRev); if ($f > 0) $flush[$f] = true; $nOk++; }
          if ($cambiaCli) {
            if ($cliRow) { $pdo->prepare("UPDATE streaming_ventas SET cliente_id=?, cliente_nombre=?, cliente_wa=? WHERE id=? AND owner_id=$OWNER")->execute([(int) $cliRow['id'], $nuevoCli, ($nuevoWa !== '' ? $nuevoWa : null), $vid]); $nCli++; }
            elseif ($nuevoCli !== '') { $pdo->prepare("UPDATE streaming_ventas SET cliente_id=NULL, cliente_nombre=?, cliente_wa=? WHERE id=? AND owner_id=$OWNER")->execute([$nuevoCli, ($nuevoWa !== '' ? $nuevoWa : null), $vid]); $nCli++; }
            elseif ($nuevoWa !== '') { $pdo->prepare("UPDATE streaming_ventas SET cliente_wa=? WHERE id=? AND owner_id=$OWNER")->execute([$nuevoWa, $vid]); }
          }
        }
        $pdo->commit();
      } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
      if (!empty($flush) && function_exists('bot_codigos_flush')) { foreach (array_keys($flush) as $rf) { try { bot_codigos_flush($pdo, (int) $rf); } catch (Throwable $e) {} } }
      $msg = '✓ Cambios aplicados' . ($cambiaRev ? ' · ' . $nOk . ' venta(s) ' . ($nuevoRev > 0 ? 'al nuevo vendedor' : 'sin vendedor') : '') . ($nCli > 0 ? " · $nCli con nuevo cliente" : '') . '.';
    }
    // ── LOTE desde Perfiles: asignar proveedor (solo dueño) ──
    elseif (($_POST['accion'] ?? '') === 'bulk_proveedor_p') {
      if (!$verCostos) throw new Exception('Solo el dueño asigna proveedor.');
      $pids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string) ($_POST['ids'] ?? ''))))));
      if (!$pids) throw new Exception('No seleccionaste perfiles.');
      $in = implode(',', $pids);
      $provId = ((int) ($_POST['proveedor_id'] ?? 0)) ?: null;
      $ctas = $pdo->query("SELECT DISTINCT c.id FROM streaming_perfiles p JOIN streaming_cuentas c ON c.id=p.cuenta_id WHERE p.id IN ($in) AND c.owner_id=$OWNER")->fetchAll(PDO::FETCH_COLUMN);
      if (!$ctas) throw new Exception('No hay cuentas para esos perfiles.');
      $cin = implode(',', array_map('intval', $ctas));
      $st = $pdo->prepare("UPDATE streaming_cuentas SET proveedor_id=? WHERE id IN ($cin) AND owner_id=$OWNER");
      $st->execute([$provId]);
      $msg = '✓ Proveedor asignado a ' . $st->rowCount() . ' cuenta(s).';
    }
    // ── LOTE desde Perfiles: eliminar (vendidos → se liberan a stock; libres → se borran) ──
    elseif (($_POST['accion'] ?? '') === 'bulk_eliminar_p') {
      $pids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string) ($_POST['ids'] ?? ''))))));
      if (!$pids) throw new Exception('No seleccionaste perfiles.');
      if ((string) ($_POST['confirmar'] ?? '') !== 'ELIMINAR') throw new Exception('Escribe ELIMINAR para confirmar.');
      $in = implode(',', $pids);
      $pdo->beginTransaction();
      try {
        $rows = $pdo->query("SELECT p.id, p.estado, p.venta_id FROM streaming_perfiles p JOIN streaming_cuentas c ON c.id=p.cuenta_id WHERE p.id IN ($in) AND c.owner_id=$OWNER FOR UPDATE")->fetchAll(PDO::FETCH_ASSOC);
        $freed = 0; $del = 0;
        foreach ($rows as $r) {
          if ($r['estado'] === 'vendido') {
            if (!empty($r['venta_id'])) { try { $pdo->prepare("UPDATE streaming_ventas SET estado='cancelada' WHERE id=?")->execute([(int) $r['venta_id']]); } catch (Throwable $e) {} }
            $pdo->prepare("UPDATE streaming_perfiles SET estado='libre', venta_id=NULL WHERE id=?")->execute([(int) $r['id']]); $freed++;
          } else {
            $pdo->prepare("DELETE FROM streaming_perfiles WHERE id=?")->execute([(int) $r['id']]); $del++;
          }
        }
        $pdo->commit();
        $msg = "✓ $del perfil(es) eliminados" . ($freed > 0 ? ", $freed liberado(s) a stock" : '') . '.';
      } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    }
  } catch (Throwable $e) { $msg = '⚠ ' . $e->getMessage(); }
  header('Location: perfiles.php?msg=' . urlencode($msg));
  exit;
}
$flash = (string) ($_GET['msg'] ?? '');

// Revendedores para el dropdown (solo el dueño asigna a revendedores).
$revList = [];
if ((int) $OWNER === 0) { try { $revList = $pdo->query("SELECT id, nombre FROM usuarios WHERE rol='revendedor' ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) { $revList = []; } }
// Clientes propios (para el desplegable «Cambiar cliente/vendedor»).
$clientesList = [];
if ((int) $OWNER === 0) { try { $clientesList = $pdo->query("SELECT id, nombre, wa FROM streaming_clientes WHERE owner_id=$OWNER ORDER BY nombre LIMIT 3000")->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) { $clientesList = []; } }
// Proveedores para el lote "Asignar proveedor" (solo el dueño).
$proveedores = $verCostos ? (function () use ($pdo, $OWNER) { try { return $pdo->query("SELECT id, nombre FROM streaming_proveedores WHERE owner_id=$OWNER AND activo=1 ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) { return []; } })() : [];

// Limpieza de FANTASMAS (espejo cuyo origen ya no existe) — aislada, corre siempre, antes de listar.
try { if (function_exists('st_rev_limpiar_fantasmas')) st_rev_limpiar_fantasmas($pdo); } catch (Throwable $e) {}
// Barrido: quita del stock del revendedor lo que compró y venció sin renovar (avisa al admin).
try { if (function_exists('st_rev_sweep_vencidos')) st_rev_sweep_vencidos($pdo); } catch (Throwable $e) {}

// Cada PERFIL como una fila, con su cuenta y (si vendido) a quién.
$perfiles = [];
try {
  $perfiles = $pdo->query("SELECT p.id, p.etiqueta, p.pin, p.estado, COALESCE(p.cambios_np,0) AS cambios_np,
        c.id AS cuenta_id, c.plataforma, c.correo, c.clave, c.vencimiento, c.costo, c.precio_venta, c.precio_reventa, COALESCE(c.rev_editable,0) AS rev_editable, COALESCE(c.origen_cuenta_id,0) AS origen_cuenta_id,
        COALESCE(pl.unidad_venta,'perfil') AS unidad_venta,
        v.cliente_nombre, v.cliente_wa, v.revendedor_id, ru.nombre AS rev_nombre, v.fecha_vencimiento AS venta_venc
      FROM streaming_perfiles p
      JOIN streaming_cuentas c ON c.id = p.cuenta_id
      LEFT JOIN streaming_plataformas pl ON pl.id = c.plataforma_id AND pl.owner_id = c.owner_id
      LEFT JOIN streaming_ventas v ON v.id = p.venta_id
      LEFT JOIN usuarios ru ON ru.id = v.revendedor_id
      WHERE c.owner_id=$OWNER
      -- Por defecto: EL QUE VENCE PRIMERO arriba. Usa la fecha efectiva de cada fila (la del cliente si
      -- está vendido, si no la de la cuenta); las SIN fecha van al final. Luego agrupa por cuenta.
      ORDER BY (COALESCE(v.fecha_vencimiento, c.vencimiento) IS NULL),
               COALESCE(v.fecha_vencimiento, c.vencimiento) ASC,
               c.plataforma, c.correo, p.id
      LIMIT 5000")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $perfiles = []; }

$nLibres = 0; $nVend = 0;
foreach ($perfiles as $p) { if ($p['estado'] === 'libre') $nLibres++; elseif ($p['estado'] === 'vendido') $nVend++; }
$platNames = array_values(array_unique(array_map(fn($p) => $p['plataforma'], $perfiles)));

// AGRUPACIÓN: si la plataforma se vende "por cuenta completa" (unidad_venta='cuenta'), TODOS sus
// perfiles se muestran en UNA sola fila (la cuenta) — se vende completa. El resto: 1 fila por perfil.
$renderItems = [];
$ccIdx = [];
foreach ($perfiles as $p) {
  $esCuenta = ($p['unidad_venta'] ?? 'perfil') === 'cuenta';
  if ($esCuenta) {
    $cid = (int) $p['cuenta_id'];
    if (!isset($ccIdx[$cid])) {
      $ccIdx[$cid] = count($renderItems);
      $renderItems[] = ['cc' => true, 'cuenta_id' => $cid, 'plataforma' => $p['plataforma'], 'correo' => $p['correo'], 'clave' => $p['clave'],
        'vencimiento' => $p['vencimiento'], 'rev_editable' => (int) $p['rev_editable'], 'costo' => $p['costo'], 'origen_cuenta_id' => (int) ($p['origen_cuenta_id'] ?? 0),
        'precio_venta' => $p['precio_venta'], 'precio_reventa' => $p['precio_reventa'],
        'libres_ids' => [], 'vendidos_ids' => [], 'total' => 0, 'libres' => 0, 'vendidos' => 0,
        'venta_venc' => null, 'cliente_nombre' => null, 'cliente_wa' => null, 'rev_nombre' => null, 'first_id' => (int) $p['id']];
    }
    $i = $ccIdx[$cid];
    $renderItems[$i]['total']++;
    if ($p['estado'] === 'libre') { $renderItems[$i]['libres']++; $renderItems[$i]['libres_ids'][] = (int) $p['id']; }
    elseif ($p['estado'] === 'vendido') {
      $renderItems[$i]['vendidos']++; $renderItems[$i]['vendidos_ids'][] = (int) $p['id'];
      if ($renderItems[$i]['venta_venc'] === null) { $renderItems[$i]['venta_venc'] = $p['venta_venc']; $renderItems[$i]['cliente_nombre'] = $p['cliente_nombre']; $renderItems[$i]['cliente_wa'] = $p['cliente_wa']; $renderItems[$i]['rev_nombre'] = $p['rev_nombre']; }
    }
  } else {
    $renderItems[] = ['cc' => false, 'p' => $p];
  }
}
// Mapa JS con los datos de cada perfil LIBRE (para editar nombre/PIN/precio por perfil al vender).
$perfMap = [];
foreach ($perfiles as $p) {
  if ($p['estado'] !== 'libre') continue;
  $perfMap[(int) $p['id']] = ['etq' => (string) ($p['etiqueta'] ?? ''), 'pin' => (string) ($p['pin'] ?? ''),
    'plat' => (string) ($p['plataforma'] ?? ''), 'correo' => (string) ($p['correo'] ?? ''),
    'venta' => $p['precio_venta'], 'reventa' => $p['precio_reventa']];
}

// Celda de precios (costo/venta/reventa) para la tabla. El cliente pidió ver estos precios en Perfiles.
// costo = solo el dueño (verCostos); venta = precio al cliente; reventa = precio al revendedor (se muestra
// también al revendedor para que vea el suyo).
$precioCell = function ($costo, $venta, $reventa) use ($verCostos) {
  $out = '';
  if ($verCostos && $costo !== null && $costo !== '' && (float) $costo > 0) $out .= '<div style="font-size:11px;color:var(--faint)">costo $' . number_format((float) $costo, 2) . '</div>';
  if ($venta !== null && $venta !== '' && (float) $venta > 0) $out .= '<div style="font-size:11px;color:var(--good);font-weight:600">venta $' . number_format((float) $venta, 2) . '</div>';
  if ($reventa !== null && $reventa !== '' && (float) $reventa > 0) $out .= '<div style="font-size:11px;color:var(--accent);font-weight:600" title="Precio para el revendedor">reventa $' . number_format((float) $reventa, 2) . '</div>';
  return $out !== '' ? $out : '<span style="color:var(--faint)">—</span>';
};

stream_head('Perfiles', 'perfiles');
?>
<div class="pagehd">
  <div>
    <h1>Gestión de <span class="nm">Perfiles</span></h1>
    <p>Cada perfil por separado (como una plantilla). Marca 1 o varios y asígnalos a un cliente o revendedor.</p>
  </div>
  <div style="display:flex;gap:8px">
    <span class="pill ok" style="font-size:12.5px"><?= $nLibres ?> libres</span>
    <span class="pill wait" style="font-size:12.5px"><?= $nVend ?> vendidos</span>
  </div>
</div>

<?php if ($flash):
  $flashMal = (mb_substr(trim((string) $flash), 0, 1) === '⚠');
  // Si la acción dejó un aviso de WhatsApp (formato: texto |WA|numero|mensaje), lo mostramos como botón.
  $waLink = ''; $flashTxt = (string) $flash;
  if (str_contains($flashTxt, '|WA|')) {
    [$flashTxt, $resto] = explode('|WA|', $flashTxt, 2);
    $pz = explode('|', $resto, 2);
    if (count($pz) === 2 && $pz[0] !== '') { $waLink = 'https://wa.me/' . preg_replace('/\D+/', '', $pz[0]) . '?text=' . $pz[1]; }
    $flashTxt = trim($flashTxt);
  }
?><div class="banner" style="margin-bottom:16px<?= $flashMal ? ';background:var(--bad-soft);color:var(--bad);border-color:var(--bad)' : '' ?>"><i data-lucide="<?= $flashMal ? 'alert-triangle' : 'check-circle' ?>"></i><span><?= h($flashTxt) ?></span>
  <?php if ($waLink !== ''): ?><a href="<?= h($waLink) ?>" target="_blank" rel="noopener" class="btn ghost" style="margin-left:10px;padding:5px 11px;font-size:12.5px"><i data-lucide="message-circle"></i> Avisar por WhatsApp</a><?php endif; ?>
</div><?php endif; ?>

<!-- Filtros -->
<div class="card" style="margin-bottom:14px">
  <div class="card-bd">
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
      <div><label class="flbl">Búsqueda (correo, perfil, cliente…)</label><input id="f-busqueda" oninput="filtrar()" placeholder="Buscar…" class="input"></div>
      <div><label class="flbl">Plataforma</label><select id="f-plat" onchange="filtrar()" class="input"><option value="">Todas</option><?php foreach ($platNames as $pn): ?><option value="<?= h(mb_strtolower($pn)) ?>"><?= h($pn) ?></option><?php endforeach; ?></select></div>
      <div><label class="flbl">Estado</label><select id="f-estado" onchange="filtrar()" class="input"><option value="">Todos</option><option value="libre">Libres</option><option value="vendido">Vendidos</option></select></div>
      <?php if ((int) $OWNER === 0 && !empty($revList)): ?>
      <div><label class="flbl">Revendedor</label><select id="f-rev" onchange="filtrar()" class="input"><option value="">Todos</option><?php foreach ($revList as $rv): ?><option value="<?= h(mb_strtolower((string) $rv['nombre'])) ?>"><?= h($rv['nombre']) ?></option><?php endforeach; ?></select></div>
      <?php endif; ?>
      <div><label class="flbl">Ordenar por</label><select id="f-orden" onchange="ordenar()" class="input"><option value="">— Por defecto —</option><option value="plat-asc">Plataforma (A→Z)</option><option value="correo-asc">Correo (A→Z)</option><option value="rev-asc">Vendedor (A→Z)</option><option value="venc-asc">Vence primero</option></select></div>
    </div>
  </div>
</div>

<!-- Tabla de perfiles -->
<div class="card">
  <div class="card-hd">
    <i data-lucide="list"></i><h2>Perfiles</h2><span class="pill-count"><?= count($perfiles) ?></span>
    <button type="button" onclick="selTodos()" class="btn ghost" style="margin-left:auto;padding:6px 11px;font-size:12px"><i data-lucide="check-square" style="width:14px;height:14px"></i> Seleccionar todos</button>
  </div>
  <div class="overflow-x-auto thin">
    <div id="bulkbar" style="display:none;margin-bottom:10px;padding:10px 13px;border-radius:11px;background:rgba(45,226,213,.09);border:1px solid rgba(45,226,213,.35);align-items:center;gap:10px;flex-wrap:wrap">
      <b style="color:var(--acc)"><span id="bulk-n">0</span> seleccionado(s)</b>
      <button type="button" onclick="bulkVender()" class="btn ghost" style="padding:5px 12px;font-size:12.5px;color:var(--good);border-color:rgba(34,197,94,.4)"><i data-lucide="shopping-cart" style="width:14px;height:14px"></i> Vender seleccionados</button>
      <button type="button" onclick="bulkPreciosP()" class="btn ghost" style="padding:5px 12px;font-size:12.5px"><i data-lucide="dollar-sign" style="width:14px;height:14px"></i> Cambiar precios</button>
      <?php if ((int) $OWNER === 0): ?><button type="button" onclick="bulkReasignarP()" class="btn ghost" style="padding:5px 12px;font-size:12.5px"><i data-lucide="user-cog" style="width:14px;height:14px"></i> Cambiar cliente/vendedor</button><?php endif; ?>
      <?php if ($verCostos): ?>
      <button type="button" onclick="bulkProveedorP()" class="btn ghost" style="padding:5px 12px;font-size:12.5px"><i data-lucide="truck" style="width:14px;height:14px"></i> Asignar proveedor</button>
      <?php endif; ?>
      <button type="button" onclick="bulkEliminarP()" class="btn ghost" style="padding:5px 12px;font-size:12.5px;color:var(--err);border-color:rgba(239,68,68,.4)"><i data-lucide="trash-2" style="width:14px;height:14px"></i> Eliminar varias</button>
      <button type="button" onclick="document.querySelectorAll('.ck-row').forEach(c=>c.checked=false);ckSync()" class="btn ghost" style="padding:5px 12px;font-size:12.5px;margin-left:auto">Quitar selección</button>
    </div>
    <table class="dtable">
      <thead><tr>
        <th style="width:34px"><input type="checkbox" id="ck-all" onclick="ckTodo(this)" title="Seleccionar los visibles (libres)" style="width:15px;height:15px;accent-color:var(--acc)"></th>
        <th>Plataforma</th><th>Correo</th><th>Perfil</th><th>PIN</th><th>Estado</th><th>Precios</th><th>Asignado a</th><th>Vence</th><th style="text-align:center">Acciones</th>
      </tr></thead>
      <tbody id="tbody">
      <?php foreach ($renderItems as $it): ?>
      <?php if (!empty($it['cc'])):
        // ===== FILA DE CUENTA COMPLETA (1 sola línea por cuenta) =====
        $venc = $it['venta_venc'] ?: $it['vencimiento'];
        $d = $venc ? (int) floor((strtotime($venc) - strtotime('today')) / 86400) : null;
        if ($d === null) { $pill = 'tag'; $txt = '—'; }
        elseif ($d < 0)  { $pill = 'pill err'; $txt = 'Vencido'; }
        elseif ($d <= 5) { $pill = 'pill wait'; $txt = 'En ' . $d . 'd'; }
        else             { $pill = 'pill ok'; $txt = $d . 'd'; }
        $asignado = $it['vendidos'] > 0 ? (($it['rev_nombre'] ? 'Rev: ' . $it['rev_nombre'] : ($it['cliente_nombre'] ?: 'Cliente')) . ($it['cliente_wa'] ? ' · ' . $it['cliente_wa'] : '')) : '';
        $estadoData = $it['libres'] > 0 ? 'libre' : 'vendido';
        $busca = mb_strtolower(($it['plataforma'] ?? '') . ' ' . ($it['correo'] ?? '') . ' cuenta completa ' . $asignado);
      ?>
        <tr data-b="<?= h($busca) ?>" data-plat="<?= h(mb_strtolower($it['plataforma'] ?? '')) ?>" data-estado="<?= h($estadoData) ?>" data-correo="<?= h(mb_strtolower($it['correo'] ?? '')) ?>" data-rev="<?= h(mb_strtolower((string) ($it['rev_nombre'] ?? ''))) ?>" data-venc="<?= $d === null ? 999999 : (int) $d ?>">
          <td><?php $ccVal = $it['libres'] > 0 ? $it['libres_ids'] : $it['vendidos_ids']; if ($ccVal): ?><input type="checkbox" class="ck-row" value="<?= h(implode(',', $ccVal)) ?>" onclick="ckSync()" style="width:15px;height:15px;accent-color:var(--acc)"><?php endif; ?></td>
          <td><b style="color:var(--text)"><?= h($it['plataforma']) ?></b> <span class="tag" style="font-size:10px;color:var(--accent)">Cuenta completa</span></td>
          <td style="color:var(--muted)"><?= h($it['correo'] ?: '—') ?><?php if (!empty($it['clave'])): ?><br><span style="font-size:10px;color:var(--faint)">🔑 <?= h($it['clave']) ?></span><?php endif; ?></td>
          <td style="font-weight:650" colspan="2">Cuenta completa · <?= (int) $it['total'] ?> perfil(es)<?php if ($it['libres'] > 0 && $it['vendidos'] > 0): ?> · <?= (int) $it['libres'] ?> libre(s)<?php endif; ?></td>
          <td><?php if ($it['libres'] > 0): ?><span class="tag" style="color:var(--good)">Libre</span><?php else: ?><span class="pill wait">Vendida</span><?php endif; ?></td>
          <td><?= $precioCell($it['costo'], $it['precio_venta'], $it['precio_reventa']) ?></td>
          <td style="color:var(--muted);font-size:12.5px"><?= $asignado ? h($asignado) : '<span style="color:var(--faint)">—</span>' ?></td>
          <td><span class="<?= $pill ?>"><?= h($txt) ?></span></td>
          <td style="text-align:center;white-space:nowrap">
            <?php if (!($esRevCtx && $it['rev_editable'] !== 1)): ?>
              <button type="button" class="iconbtn" style="width:30px;height:30px" title="Editar cuenta (clave, cliente, fecha, precios)" onclick='editarPerfil(<?= json_encode(["id"=>(int)$it["first_id"],"etiqueta"=>"","pin"=>"","costo"=>$it["costo"],"venta"=>$it["precio_venta"],"reventa"=>$it["precio_reventa"],"cliente"=>(string)($it["cliente_nombre"]??""),"wa"=>(string)($it["cliente_wa"]??""),"vencCli"=>substr((string)($it["venta_venc"]??""),0,10),"puedeNombre"=>(!$esRevCtx || (int)$it["rev_editable"]===1),"puedeClave"=>(!$esRevCtx || (int)$it["rev_editable"]===1),"clave"=>(string)($it["clave"]??""),"vendido"=>($it["vendidos"]>0),"origenCuenta"=>(int)($it["origen_cuenta_id"]??0)], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE) ?: 'null' ?>)'><i data-lucide="pencil" style="width:14px;height:14px"></i></button>
            <?php endif; ?>
            <button type="button" class="iconbtn" style="width:30px;height:30px" title="Renovar (elegir meses)" onclick="renovarPerfil(<?= (int) $it['first_id'] ?>)"><i data-lucide="calendar-check" style="width:14px;height:14px"></i></button>
          </td>
        </tr>
      <?php else:
        $p = $it['p'];
        $vend = $p['estado'] === 'vendido';
        $venc = $p['venta_venc'] ?: $p['vencimiento'];
        $d = $venc ? (int) floor((strtotime($venc) - strtotime('today')) / 86400) : null;
        if ($d === null) { $pill = 'tag'; $txt = '—'; }
        elseif ($d < 0)  { $pill = 'pill err'; $txt = 'Vencido'; }
        elseif ($d <= 5) { $pill = 'pill wait'; $txt = 'En ' . $d . 'd'; }
        else             { $pill = 'pill ok'; $txt = $d . 'd'; }
        $asignado = $vend ? (($p['rev_nombre'] ? 'Rev: ' . $p['rev_nombre'] : ($p['cliente_nombre'] ?: 'Cliente')) . ($p['cliente_wa'] ? ' · ' . $p['cliente_wa'] : '')) : '';
        $busca = mb_strtolower(($p['plataforma'] ?? '') . ' ' . ($p['correo'] ?? '') . ' ' . ($p['etiqueta'] ?? '') . ' ' . $asignado);
      ?>
        <tr data-b="<?= h($busca) ?>" data-plat="<?= h(mb_strtolower($p['plataforma'] ?? '')) ?>" data-estado="<?= h($p['estado']) ?>" data-correo="<?= h(mb_strtolower($p['correo'] ?? '')) ?>" data-rev="<?= h(mb_strtolower((string) ($p['rev_nombre'] ?? ''))) ?>" data-venc="<?= $d === null ? 999999 : (int) $d ?>">
          <td><input type="checkbox" class="ck-row" value="<?= (int) $p['id'] ?>" onclick="ckSync()" style="width:15px;height:15px;accent-color:var(--acc)"></td>
          <td><b style="color:var(--text)"><?= h($p['plataforma']) ?></b></td>
          <td style="color:var(--muted)"><?= h($p['correo'] ?: '—') ?><?php if (!empty($p['clave'])): ?><br><span style="font-size:10px;color:var(--faint)">🔑 <?= h($p['clave']) ?></span><?php endif; ?></td>
          <td style="font-weight:650"><?= h($p['etiqueta'] ?: '—') ?></td>
          <td style="color:var(--faint);font-family:ui-monospace,monospace"><?= h($p['pin'] ?: '—') ?></td>
          <td><?php if ($vend): ?><span class="pill wait">Vendido</span><?php else: ?><span class="tag" style="color:var(--good)">Libre</span><?php endif; ?></td>
          <td><?= $precioCell($p['costo'] ?? null, $p['precio_venta'] ?? null, $p['precio_reventa'] ?? null) ?></td>
          <td style="color:var(--muted);font-size:12.5px"><?= $asignado ? h($asignado) : '<span style="color:var(--faint)">—</span>' ?></td>
          <td><span class="<?= $pill ?>" title="Vence para tu cliente"><?= h($txt) ?></span>
            <?php if ($vend && $esRevCtx && !empty($p['vencimiento']) && !empty($p['venta_venc']) && substr((string) $p['vencimiento'], 0, 10) !== substr((string) $p['venta_venc'], 0, 10)): ?>
              <div style="font-size:10px;color:var(--faint);margin-top:2px" title="Tu fecha de corte, desde que la compraste (no editable)">🛒 tu corte: <?= h(date('d/m/Y', strtotime((string) $p['vencimiento']))) ?></div>
            <?php endif; ?>
          </td>
          <td style="text-align:center;white-space:nowrap">
            <?php $esCompl = (int) ($p['rev_editable'] ?? 0) === 1; $limitado = $esRevCtx && !$esCompl; ?>
            <button type="button" class="iconbtn" style="width:30px;height:30px" title="Editar (nombre, PIN, cliente, fecha, precios)" onclick='editarPerfil(<?= json_encode(["id"=>(int)$p["id"],"etiqueta"=>(string)($p["etiqueta"]??""),"pin"=>(string)($p["pin"]??""),"costo"=>$p["costo"],"venta"=>$p["precio_venta"],"reventa"=>$p["precio_reventa"],"cliente"=>(string)($p["cliente_nombre"]??""),"wa"=>(string)($p["cliente_wa"]??""),"vencCli"=>substr((string)($p["venta_venc"]??""),0,10),"puedeNombre"=>true,"puedeClave"=>(!$esRevCtx || $esCompl),"clave"=>(!$esRevCtx || $esCompl)?(string)($p["clave"]??""):"","vendido"=>($p["estado"]==="vendido"),"cambiosNp"=>(int)($p["cambios_np"]??0),"limitado"=>$limitado,"origenCuenta"=>(int)($p["origen_cuenta_id"]??0)], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE) ?: 'null' ?>)'><i data-lucide="pencil" style="width:14px;height:14px"></i></button>
            <?php if ($esRevCtx && (int) ($p['rev_editable'] ?? 0) !== 1): ?>
              <button type="button" class="iconbtn" style="width:30px;height:30px" title="Solicitar cambio de perfil/PIN a la tienda" onclick="solicitarCambio(<?= (int) $p['id'] ?>)"><i data-lucide="message-square-warning" style="width:14px;height:14px"></i></button>
            <?php endif; ?>
            <button type="button" class="iconbtn" style="width:30px;height:30px" title="Renovar (elegir meses)" onclick="renovarPerfil(<?= (int) $p['id'] ?>)"><i data-lucide="calendar-check" style="width:14px;height:14px"></i></button>
          </td>
        </tr>
      <?php endif; ?>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php if (!$perfiles): ?><div class="empty">No hay perfiles todavía. Créalos en <b>Cuentas</b> (o carga masiva).</div><?php endif; ?>
  </div>
</div>

<!-- Modal Vender seleccionados -->
<div id="overlay" class="fixed inset-0 bg-black/50 z-40 hidden items-start justify-center overflow-y-auto p-4" onclick="if(event.target===this)cerrarModales()">
  <div id="m-vender" class="modal hidden w-full max-w-md my-8">
    <div class="modal-hd"><h3 style="color:var(--good)"><i data-lucide="shopping-cart"></i> Vender perfiles</h3><button onclick="cerrarModales()" class="modal-x"><i data-lucide="x"></i></button></div>
    <form method="post" class="p-5 space-y-4"><input type="hidden" name="_csrf" value="<?= h($csrf) ?>"><input type="hidden" name="accion" value="vender_perfiles"><input type="hidden" name="ids" id="v-ids">
      <p style="color:var(--muted)">Vas a vender <strong id="v-n" style="color:var(--text)">0</strong> perfil(es).</p>
      <div class="space-y-2">
        <label class="flex items-center gap-2" style="font-weight:600"><input type="radio" name="destino" value="cliente" checked onchange="vDestino()" class="accent-teal-500"> A un cliente</label>
        <label class="flex items-center gap-2" style="font-weight:600"><input type="radio" name="destino" value="revendedor" onchange="vDestino()" class="accent-teal-500"> A un revendedor</label>
      </div>
      <div id="v-cliente" class="space-y-2">
        <div class="field"><label class="flbl">Nombre del cliente</label><input name="cliente_nombre" class="input" placeholder="Ej: María"></div>
        <div class="field"><label class="flbl">WhatsApp (opcional)</label><input name="cliente_wa" class="input" placeholder="Ej: 584121234567"></div>
      </div>
      <div id="v-rev" class="hidden">
        <div class="field"><label class="flbl">Revendedor</label>
          <select name="revendedor_id" class="input"><option value="">— Elige —</option><?php foreach ($revList as $rv): ?><option value="<?= (int) $rv['id'] ?>"><?= h($rv['nombre']) ?></option><?php endforeach; ?></select>
        </div>
      </div>
      <div class="grid grid-cols-2 gap-3">
        <div class="field"><label class="flbl">Precio para TODOS (opcional)</label><input id="v-precio-all" type="number" step="0.01" min="0" class="input" placeholder="$" oninput="vPrecioAll(this.value)"></div>
        <div class="field"><label class="flbl">Vence (opcional)</label><input name="vencimiento" type="date" class="input"></div>
      </div>
      <div class="field" style="margin-bottom:0">
        <label class="flbl">Marca ✓ los que vas a vender (desmárcalos para dejarlos en stock) · edita Nombre / PIN / precio</label>
        <div id="v-rows" style="max-height:230px;overflow:auto;border:1px solid var(--border);border-radius:10px;padding:8px;background:var(--surface-2)"></div>
      </div>
      <p style="font-size:11px;color:var(--faint)">Si dejas un precio vacío, usa el de la cuenta. Vencimiento vacío = el de la cuenta / días de la plataforma. Luego verás los botones de WhatsApp en <b>Ventas</b>.</p>
      <button class="btn primary w-full">Marcar como vendidos</button>
    </form>
  </div>

  <!-- #12: Editar perfil (nombre, PIN y precios de su cuenta) -->
  <div id="m-editar" class="modal hidden w-full max-w-md my-8">
    <div class="modal-hd"><h3><i data-lucide="pencil"></i> Editar perfil</h3><button onclick="cerrarModales()" class="modal-x"><i data-lucide="x"></i></button></div>
    <form method="post" class="p-5 space-y-4"><input type="hidden" name="_csrf" value="<?= h($csrf) ?>"><input type="hidden" name="accion" value="editar_perfil"><input type="hidden" name="pid" id="e-pid">
      <!-- Asignar cliente + fecha de vencimiento de SU cliente (para poder venderle / darle garantía). -->
      <div id="e-cliente-box" style="display:none">
        <div class="grid grid-cols-2 gap-3">
          <div class="field"><label class="flbl">Cliente (a quién se lo vendes)</label><input name="cliente_nombre" id="e-cliente" class="input" placeholder="Ej: María"></div>
          <div class="field"><label class="flbl">WhatsApp del cliente</label><input name="cliente_wa" id="e-cliwa" class="input" placeholder="Ej: 584121234567"></div>
        </div>
        <div class="field"><label class="flbl">Fecha de vencimiento (de tu cliente)</label><input type="date" name="fecha_venc" id="e-venccli" class="input"></div>
      </div>
      <!-- Nombre / PIN. -->
      <div id="e-nombre-box" class="grid grid-cols-2 gap-3">
        <div class="field"><label class="flbl">Nombre del perfil</label><input name="etiqueta" id="e-etq" class="input" placeholder="Ej: María / P1"></div>
        <div class="field"><label class="flbl">PIN</label><input name="pin" id="e-pin" class="input" placeholder="Ej: 1234"></div>
      </div>
      <div id="e-limit-note" style="display:none;font-size:11px;color:var(--warn);margin:-6px 0 0"></div>
      <!-- Clave de la cuenta — solo cuenta completa (se sincroniza y notifica). -->
      <div id="e-clave-box" class="field" style="display:none;margin-bottom:0"><label class="flbl">Clave de la cuenta completa</label><input name="clave" id="e-clave" class="input" placeholder="Nueva clave"><p style="font-size:11px;color:var(--faint);margin:3px 0 0">Al cambiarla se actualiza en la tienda y les llega aviso.</p></div>
      <p style="font-size:11px;color:var(--faint);margin:0">Precios de <b>la cuenta</b> (aplican a todos sus perfiles). Útil si lo vendes más caro/económico. Deja vacío lo que no quieras cambiar.</p>
      <div class="grid grid-cols-<?= $verCostos ? '3' : ($esRevCtx ? '2' : '1') ?> gap-3">
        <?php if ($verCostos || $esRevCtx): ?><div class="field"><label class="flbl">Costo</label><input name="costo" id="e-costo" type="number" step="0.01" min="0" class="input" placeholder="$"><?php if ($esRevCtx): ?><p id="e-costo-nota" style="font-size:10px;color:var(--faint);margin-top:3px"></p><?php endif; ?></div><?php endif; ?>
        <div class="field"><label class="flbl">Venta</label><input name="precio_venta" id="e-venta" type="number" step="0.01" min="0" class="input" placeholder="$"></div>
        <?php if ($verCostos): ?><div class="field"><label class="flbl">Reventa</label><input name="precio_reventa" id="e-reventa" type="number" step="0.01" min="0" class="input" placeholder="$"></div><?php endif; ?>
      </div>
      <button class="btn primary w-full">Guardar</button>
    </form>
  </div>

  <!-- Solicitar cambio de perfil/PIN a la tienda (revendedor) -->
  <div id="m-solicitar" class="modal hidden w-full max-w-sm my-8">
    <div class="modal-hd"><h3><i data-lucide="message-square-warning"></i> Solicitar cambio</h3><button onclick="cerrarModales()" class="modal-x"><i data-lucide="x"></i></button></div>
    <form method="post" class="p-5 space-y-4"><input type="hidden" name="_csrf" value="<?= h($csrf) ?>"><input type="hidden" name="accion" value="solicitar_cambio"><input type="hidden" name="pid" id="s-pid">
      <p style="color:var(--muted);font-size:13px;margin:0">Este perfil es de una cuenta compartida, así que lo cambia la tienda. Manda tu solicitud y te avisan cuando esté listo.</p>
      <div class="field"><label class="flbl">¿Qué necesitas cambiar?</label>
        <select name="que" class="input"><option value="perfil">El nombre del perfil</option><option value="pin">El PIN</option><option value="ambos">Perfil y PIN</option></select>
      </div>
      <div class="field"><label class="flbl">Nota para la tienda (opcional)</label>
        <textarea name="nota" rows="3" class="input" placeholder="Ej: el cliente pide que se llame Ana y PIN 1234"></textarea>
      </div>
      <button class="btn primary w-full">Enviar solicitud</button>
    </form>
  </div>

  <!-- Renovar perfil (elegir meses) -->
  <div id="m-renovar" class="modal hidden w-full max-w-sm my-8">
    <div class="modal-hd"><h3><i data-lucide="calendar-check"></i> Renovar</h3><button onclick="cerrarModales()" class="modal-x"><i data-lucide="x"></i></button></div>
    <form method="post" class="p-5 space-y-4"><input type="hidden" name="_csrf" value="<?= h($csrf) ?>"><input type="hidden" name="accion" value="renovar_perfil"><input type="hidden" name="pid" id="r-pid">
      <p style="color:var(--muted);font-size:13px;margin:0">¿Por cuántos meses? Si el perfil está <b>vendido</b>, se le extiende el vencimiento al <b>cliente</b>; si está libre, a la <b>cuenta</b>.</p>
      <div class="field"><label class="flbl">Meses</label>
        <select name="meses" class="input"><?php for ($i = 1; $i <= 12; $i++): ?><option value="<?= $i ?>"><?= $i ?> mes<?= $i > 1 ? 'es' : '' ?></option><?php endfor; ?></select>
      </div>
      <button class="btn primary w-full">Renovar</button>
    </form>
  </div>

  <!-- LOTE desde Perfiles: cambiar precios -->
  <div id="m-perf-precios" class="modal hidden w-full max-w-md my-8">
    <div class="modal-hd"><h3><i data-lucide="dollar-sign"></i> Cambiar precios</h3><button onclick="cerrarModales()" class="modal-x"><i data-lucide="x"></i></button></div>
    <form method="post" class="p-5 space-y-4"><input type="hidden" name="_csrf" value="<?= h($csrf) ?>"><input type="hidden" name="accion" value="bulk_precios_p"><input type="hidden" name="ids" id="pp-ids">
      <p style="color:var(--muted)">Precios de las cuentas de <strong id="pp-n" style="color:var(--text)">0</strong> perfil(es). Deja vacío lo que no cambies.</p>
      <?php if ($verCostos || $esRevCtx): ?><div class="field"><label class="flbl">Costo <span style="color:var(--faint);font-weight:400"><?= $esRevCtx ? '(solo tus cuentas propias)' : '(lo que pagas)' ?></span></label><input name="costo" type="number" step="0.01" min="0" class="input" placeholder="$"></div><?php endif; ?>
      <div class="grid grid-cols-2 gap-3">
        <div class="field"><label class="flbl">Precio venta</label><input name="precio_venta" type="number" step="0.01" min="0" class="input" placeholder="$"></div>
        <?php if ($verCostos): ?><div class="field"><label class="flbl">Precio reventa</label><input name="precio_reventa" type="number" step="0.01" min="0" class="input" placeholder="$"></div><?php endif; ?>
      </div>
      <button class="btn primary w-full">Aplicar</button>
    </form>
  </div>

  <?php if ((int) $OWNER === 0): ?>
  <!-- LOTE desde Perfiles: cambiar cliente y/o vendedor de la venta del perfil -->
  <div id="m-perf-reasig" class="modal hidden w-full max-w-md my-8">
    <div class="modal-hd"><h3><i data-lucide="user-cog"></i> Cambiar cliente/vendedor</h3><button onclick="cerrarModales()" class="modal-x"><i data-lucide="x"></i></button></div>
    <form method="post" class="p-5 space-y-4"><input type="hidden" name="_csrf" value="<?= h($csrf) ?>"><input type="hidden" name="accion" value="reasignar_vendedor_p"><input type="hidden" name="ids" id="prz-ids">
      <p style="color:var(--muted)">Cambiar <strong id="prz-n" style="color:var(--text)">0</strong> perfil(es) (su venta). <b>Deja en «No cambiar» lo que no quieras tocar.</b></p>
      <div class="field"><label class="flbl">Cliente existente</label>
        <select name="cliente_id" id="prz-cli" class="input" onchange="przCliSel(this)">
          <option value="">— No cambiar —</option>
          <?php foreach ($clientesList as $cl): ?><option value="<?= (int) $cl['id'] ?>" data-wa="<?= h((string) ($cl['wa'] ?? '')) ?>"><?= h($cl['nombre']) ?><?= !empty($cl['wa']) ? ' · ' . h($cl['wa']) : '' ?></option><?php endforeach; ?>
        </select>
        <div style="font-size:11px;color:var(--faint);margin-top:5px">Pasa la venta a uno de tus clientes propios. Para uno nuevo, deja «No cambiar» y escribe el nombre abajo.</div>
      </div>
      <div class="field"><label class="flbl">…o cliente nuevo (nombre)</label><input name="cliente_nombre" id="prz-clinom" class="input" placeholder="Dejar vacío = no cambiar"></div>
      <div class="field"><label class="flbl">WhatsApp del cliente</label><input name="cliente_wa" id="prz-cliwa" class="input" placeholder="Ej: 584121234567 (opcional)"></div>
      <div class="field"><label class="flbl">Nuevo vendedor (revendedor)</label>
        <select name="revendedor_id" class="input">
          <option value="">— No cambiar —</option>
          <option value="0">Quitar vendedor (dejar directa)</option>
          <?php foreach ($revList as $rv): ?><option value="<?= (int) $rv['id'] ?>"><?= h($rv['nombre']) ?></option><?php endforeach; ?>
        </select>
        <div style="font-size:11px;color:var(--faint);margin-top:5px">Al cambiar de vendedor, la cuenta se mueve del inventario del vendedor viejo al nuevo y el bot se actualiza.</div>
      </div>
      <button class="btn primary w-full">Aplicar</button>
    </form>
  </div>
  <?php endif; ?>
  <?php if ($verCostos): ?>
  <!-- LOTE desde Perfiles: asignar proveedor (solo dueño) -->
  <div id="m-perf-prov" class="modal hidden w-full max-w-md my-8">
    <div class="modal-hd"><h3><i data-lucide="truck"></i> Asignar proveedor</h3><button onclick="cerrarModales()" class="modal-x"><i data-lucide="x"></i></button></div>
    <form method="post" class="p-5 space-y-4"><input type="hidden" name="_csrf" value="<?= h($csrf) ?>"><input type="hidden" name="accion" value="bulk_proveedor_p"><input type="hidden" name="ids" id="ppv-ids">
      <p style="color:var(--muted)">Proveedor a las cuentas de <strong id="ppv-n" style="color:var(--text)">0</strong> perfil(es).</p>
      <div class="field"><label class="flbl">Proveedor</label><select name="proveedor_id" class="input"><option value="">— Sin proveedor —</option><?php foreach ($proveedores as $pv): ?><option value="<?= (int) $pv['id'] ?>"><?= h($pv['nombre']) ?></option><?php endforeach; ?></select></div>
      <button class="btn primary w-full">Asignar</button>
    </form>
  </div>
  <?php endif; ?>
  <!-- LOTE desde Perfiles: eliminar varias -->
  <div id="m-perf-elim" class="modal hidden w-full max-w-md my-8">
    <div class="modal-hd"><h3 style="color:var(--err)"><i data-lucide="trash-2"></i> Eliminar varias</h3><button onclick="cerrarModales()" class="modal-x"><i data-lucide="x"></i></button></div>
    <form method="post" class="p-5 space-y-4"><input type="hidden" name="_csrf" value="<?= h($csrf) ?>"><input type="hidden" name="accion" value="bulk_eliminar_p"><input type="hidden" name="ids" id="pe-ids">
      <p style="color:var(--muted);font-size:13px">Vas a eliminar <b id="pe-n" style="color:var(--err)">0</b> perfil(es). Los <b>vendidos</b> se liberan a stock; los <b>libres</b> se borran. No se deshace.</p>
      <div class="field"><label class="flbl">Escribe <b>ELIMINAR</b> para confirmar</label><input name="confirmar" id="pe-conf" class="input" placeholder="ELIMINAR" autocomplete="off"></div>
      <button class="btn" style="width:100%;background:var(--err);color:#fff">Eliminar</button>
    </form>
  </div>
</div>

<script>
  const ES_REV_CTX = <?= $esRevCtx ? 'true' : 'false' ?>;   // contexto revendedor (bloqueo de costo en stock comprado)
  const ov = document.getElementById('overlay');
  function renovarPerfil(id){ document.getElementById('r-pid').value = id; abrir('m-renovar'); }
  function solicitarCambio(id){ document.getElementById('s-pid').value = id; abrir('m-solicitar'); }
  function editarPerfil(p){
    var set=function(id,v){ var el=document.getElementById(id); if(el) el.value = (v===null||v===undefined)?'':v; };
    set('e-pid', p.id); set('e-etq', p.etiqueta); set('e-pin', p.pin);
    set('e-costo', p.costo); set('e-venta', p.venta); set('e-reventa', p.reventa);
    set('e-cliente', p.cliente); set('e-cliwa', p.wa); set('e-venccli', p.vencCli); set('e-clave', '');
    // Cliente + fecha: solo si está VENDIDO (asignar cliente / darle fecha a su cliente).
    var cb=document.getElementById('e-cliente-box'); if(cb) cb.style.display = p.vendido ? 'block' : 'none';
    // Nombre/PIN: siempre visible; si es perfil de cuenta compartida del revendedor, límite 2 cambios.
    var nb=document.getElementById('e-nombre-box'); if(nb) nb.style.display = (p.puedeNombre!==false) ? 'grid' : 'none';
    var ln=document.getElementById('e-limit-note');
    if(ln){
      if(p.limitado){
        var quedan=Math.max(0,2-(p.cambiosNp||0));
        ln.style.display='block';
        ln.textContent='Cambios de nombre/PIN: '+(p.cambiosNp||0)+'/2 usados'+(quedan===0?' — sin cambios disponibles (se reinicia al renovar).':' ('+quedan+' disponible(s)).');
        var eq=document.getElementById('e-etq'), ep=document.getElementById('e-pin');
        var block=quedan===0; if(eq){eq.readOnly=block;eq.style.opacity=block?'.5':'1';} if(ep){ep.readOnly=block;ep.style.opacity=block?'.5':'1';}
      } else { ln.style.display='none'; var eq2=document.getElementById('e-etq'),ep2=document.getElementById('e-pin'); if(eq2){eq2.readOnly=false;eq2.style.opacity='1';} if(ep2){ep2.readOnly=false;ep2.style.opacity='1';} }
    }
    // Clave de la cuenta: solo cuenta completa (o admin).
    var kb=document.getElementById('e-clave-box'); if(kb) kb.style.display = p.puedeClave ? 'block' : 'none';
    // Costo (revendedor): si la cuenta la compró/le asignaron la tienda (espejo), es AUTOMÁTICO y no se
    // edita (solo la tienda). Si es una cuenta propia, sí lo puede poner.
    var ec=document.getElementById('e-costo'); var ecn=document.getElementById('e-costo-nota');
    if(ec && ES_REV_CTX){ var mir=(p.origenCuenta && Number(p.origenCuenta)>0); ec.readOnly=mir; ec.style.opacity=mir?'.6':'1'; ec.title=mir?'Automático: lo fija la tienda (comprada a REBOR)':''; if(ecn) ecn.textContent = mir ? 'Comprada a REBOR: costo automático (no editable).' : 'Cuenta propia: puedes poner tu costo.'; }
    abrir('m-editar');
  }
  function abrir(id){ ov.classList.remove('hidden'); ov.classList.add('flex'); document.querySelectorAll('#overlay > div').forEach(m=>m.classList.add('hidden')); document.getElementById(id).classList.remove('hidden'); if(window.lucide) lucide.createIcons(); }
  function cerrarModales(){ ov.classList.add('hidden'); ov.classList.remove('flex'); }
  function filasVisibles(){ return Array.from(document.querySelectorAll('#tbody tr')).filter(tr=>tr.style.display!=='none'); }
  function filtrar(){
    const q=(document.getElementById('f-busqueda').value||'').toLowerCase().trim();
    const plat=document.getElementById('f-plat').value, est=document.getElementById('f-estado').value;
    const revEl=document.getElementById('f-rev'); const rev=revEl?revEl.value:'';
    document.querySelectorAll('#tbody tr').forEach(tr=>{
      let ok=true;
      if(q && !(tr.dataset.b||'').includes(q)) ok=false;
      if(plat && tr.dataset.plat!==plat) ok=false;
      if(est && tr.dataset.estado!==est) ok=false;
      if(rev && (tr.dataset.rev||'')!==rev) ok=false;
      tr.style.display=ok?'':'none';
      if(!ok){ const c=tr.querySelector('.ck-row'); if(c) c.checked=false; }
    });
    ckSync();
  }
  function ordenar(){ const v=document.getElementById('f-orden').value; if(!v) return; const tb=document.getElementById('tbody'); const rows=Array.from(tb.querySelectorAll('tr')); const p=v.split('-'), key=p[0], mul=p[1]==='desc'?-1:1;
    rows.sort((a,b)=>{ if(key==='venc'){ return ((parseInt(a.dataset.venc||'0',10))-(parseInt(b.dataset.venc||'0',10)))*mul; } const ka=(key==='correo')?'correo':((key==='rev')?'rev':'plat'); return String(a.dataset[ka]||'').localeCompare(String(b.dataset[ka]||''))*mul; });
    rows.forEach(r=>tb.appendChild(r)); }
  function ckTodo(m){ filasVisibles().forEach(tr=>{ const c=tr.querySelector('.ck-row'); if(c) c.checked=m.checked; }); ckSync(); }
  function selTodos(){ ckTodo({checked:true}); }
  function ckIds(){ return Array.from(document.querySelectorAll('#tbody tr')).filter(tr=>tr.style.display!=='none').map(tr=>tr.querySelector('.ck-row')).filter(c=>c&&c.checked).map(c=>c.value); }
  // Ids de los seleccionados SEGÚN estado de su fila ('libre' | 'vendido'). Vender usa solo 'libre';
  // Cambiar cliente/vendedor opera sobre los 'vendido' (el handler ya ignora los que no tienen venta).
  function ckIdsEstado(est){ return Array.from(document.querySelectorAll('#tbody tr')).filter(tr=>tr.style.display!=='none' && tr.dataset.estado===est).map(tr=>tr.querySelector('.ck-row')).filter(c=>c&&c.checked).map(c=>c.value); }
  function ckSync(){ const ids=ckIds(), n=ids.length, bar=document.getElementById('bulkbar'); if(bar){ bar.style.display=n>0?'flex':'none'; const t=document.getElementById('bulk-n'); if(t) t.textContent=n; } const master=document.getElementById('ck-all'); const vis=filasVisibles().filter(tr=>tr.querySelector('.ck-row')).length; if(master){ master.checked=n>0&&n===vis; master.indeterminate=n>0&&n<vis; } }
  <?php /* JSON_INVALID_UTF8_SUBSTITUTE + fallback: si UN perfil tiene bytes no-UTF8 (pegado de Excel/latin1),
           json_encode devolvía false → "const PERF_MAP = ;" → SyntaxError → se caía TODO el JS y no se podía
           seleccionar ni nada. Ahora nunca falla: sustituye el byte malo y, ante cualquier duda, emite {}. */ ?>
  const PERF_MAP = <?= json_encode($perfMap, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}' ?>;
  function esc(s){ return String(s==null?'':s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
  function bulkVender(){
    // Expande la selección: los checkbox de "cuenta completa" traen varios ids ("12,13,14").
    // Solo LIBRES: si marcó vendidos (para «Cambiar cliente/vendedor»), aquí se ignoran — no se re-venden.
    let ids=[]; ckIdsEstado('libre').forEach(v=>String(v).split(',').forEach(x=>{ x=x.trim(); if(x) ids.push(x); }));
    ids=Array.from(new Set(ids));
    if(!ids.length){ alert('Marca al menos un perfil/cuenta LIBRE (los vendidos no se pueden vender de nuevo).'); return; }
    document.getElementById('v-ids').value=ids.join(',');
    document.getElementById('v-n').textContent=ids.length;
    // Construye una fila editable (nombre / PIN / precio) por cada perfil.
    const box=document.getElementById('v-rows'); box.innerHTML='';
    ids.forEach(id=>{
      const m=PERF_MAP[id]||{}; const row=document.createElement('div');
      row.style.cssText='display:grid;grid-template-columns:22px 1fr 62px 78px;gap:6px;margin-bottom:6px;align-items:center';
      row.innerHTML='<input type="checkbox" name="p_sel['+id+']" value="1" checked class="v-sel accent-teal-500" onchange="vCount()" title="Incluir este perfil en la venta">'
        +'<input name="p_nombre['+id+']" class="input" style="padding:6px 8px;font-size:12px" value="'+esc(m.etq)+'" placeholder="Nombre">'
        +'<input name="p_pin['+id+']" class="input" style="padding:6px 8px;font-size:12px" value="'+esc(m.pin)+'" placeholder="PIN">'
        +'<input name="p_precio['+id+']" type="number" step="0.01" min="0" class="input v-prow" style="padding:6px 8px;font-size:12px" placeholder="$">';
      box.appendChild(row);
    });
    const pa=document.getElementById('v-precio-all'); if(pa) pa.value='';
    vCount(); vDestino(); abrir('m-vender');
  }
  // Recuenta cuántos perfiles quedan marcados (los desmarcados NO se venden, se quedan en stock).
  function vCount(){ const n=document.querySelectorAll('#v-rows .v-sel:checked').length; const t=document.getElementById('v-n'); if(t) t.textContent=n; }
  function vPrecioAll(v){ document.querySelectorAll('#v-rows .v-prow').forEach(i=>{ i.value=v; }); }
  function vDestino(){ const r=document.querySelector('input[name="destino"]:checked'); const esRev=!!r&&r.value==='revendedor'; const c=document.getElementById('v-cliente'), v=document.getElementById('v-rev'); if(c)c.classList.toggle('hidden',esRev); if(v)v.classList.toggle('hidden',!esRev); }
  // Lote desde Perfiles: cambiar precios / asignar proveedor / eliminar varias.
  function bulkPreciosP(){ const i=ckIds(); if(!i.length){ alert('Marca al menos un perfil.'); return; } document.getElementById('pp-ids').value=i.join(','); document.getElementById('pp-n').textContent=i.length; abrir('m-perf-precios'); }
  function bulkReasignarP(){ const el=document.getElementById('prz-ids'); if(!el) return; const i=ckIdsEstado('vendido'); if(!i.length){ alert('Marca al menos un perfil VENDIDO (esto cambia el cliente/vendedor de una venta; los libres no tienen venta).'); return; } el.value=i.join(','); document.getElementById('prz-n').textContent=i.length; const s=document.getElementById('prz-cli'); if(s){ s.value=''; przCliSel(s); } const nn=document.getElementById('prz-clinom'), ww=document.getElementById('prz-cliwa'); if(nn) nn.value=''; if(ww) ww.value=''; abrir('m-perf-reasig'); }
  function przCliSel(sel){
    const nom=document.getElementById('prz-clinom'), wa=document.getElementById('prz-cliwa');
    const op=sel.options[sel.selectedIndex];
    if(sel.value){ if(nom){ nom.value=''; nom.disabled=true; nom.placeholder='(usando el cliente elegido arriba)'; } if(wa && !wa.value){ wa.value=(op&&op.dataset.wa)||''; } }
    else { if(nom){ nom.disabled=false; nom.placeholder='Dejar vacío = no cambiar'; } }
  }
  function bulkProveedorP(){ const el=document.getElementById('ppv-ids'); if(!el) return; const i=ckIds(); if(!i.length){ alert('Marca al menos un perfil.'); return; } el.value=i.join(','); document.getElementById('ppv-n').textContent=i.length; abrir('m-perf-prov'); }
  function bulkEliminarP(){ const i=ckIds(); if(!i.length){ alert('Marca al menos un perfil.'); return; } document.getElementById('pe-ids').value=i.join(','); document.getElementById('pe-n').textContent=i.length; document.getElementById('pe-conf').value=''; abrir('m-perf-elim'); }
</script>
<?php stream_foot();
