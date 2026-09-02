<?php
/**
 * admin/stream/comprar.php — El REVENDEDOR compra un perfil del STOCK DEL ADMIN (owner 0)
 * a precio de revendedor (precio_distribuidor), pagando con su SALDO. Portado de CONEC
 * (api/revendedor/comprar-streaming.php): claim atómico del perfil + débito condicionado del saldo.
 * Solo tiene sentido en el panel del revendedor; en el del admin redirige (el admin dueño no se
 * compra a sí mismo).
 */
define('CONEC_ADMIN', true);
require __DIR__ . '/../_auth.php';
require __DIR__ . '/_layout.php';
require __DIR__ . '/../_streaming.php';
require __DIR__ . '/../../api/wa/_wa.php';
require __DIR__ . '/../../api/wallet/_helpers.php';
admin_require_login();

if (stream_ctx() !== 'revendedor') {
    header('Location: dashboard.php');
    exit;
}

$pdo = db();
$uid = (int) current_user_id();
const STREAM_ADMIN_OWNER = 0; // el stock se compra al dueño de la tienda

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $msg = '';
    if (($_POST['accion'] ?? '') === 'comprar') {
        if (function_exists('st_rev_pausado') && st_rev_pausado($pdo)) {
            header('Location: comprar.php?msg=' . urlencode('⚠ Tu cuenta está pausada por el administrador. No puedes comprar por ahora.'));
            exit;
        }
        $platId = (int) ($_POST['plataforma_id'] ?? 0);
        $cliId = ((int) ($_POST['cliente_id'] ?? 0)) ?: null;
        $precioVenta = ($_POST['precio_venta_cliente'] ?? '') !== '' ? round((float) $_POST['precio_venta_cliente'], 2) : null;
        // FASE 2: cuántas unidades y de qué tipo (perfil suelto o cuenta completa).
        $cantidad = max(1, min(100, (int) ($_POST['cantidad'] ?? 1)));
        $unidad   = (($_POST['unidad'] ?? '') === 'cuenta') ? 'cuenta' : 'perfil';

        // Plataforma del ADMIN (owner 0), activa y publicada; el precio lo manda el servidor.
        $pl = $pdo->prepare("SELECT id, nombre, precio_distribuidor, dias_default, COALESCE(modo_entrega,'perfil') AS modo, COALESCE(unidad_venta,'perfil') AS unidad_venta, COALESCE(disponible,1) AS disponible
                             FROM streaming_plataformas WHERE id=? AND owner_id=" . STREAM_ADMIN_OWNER . " AND activo=1 AND en_tienda=1");
        $pl->execute([$platId]);
        $plat = $pl->fetch(PDO::FETCH_ASSOC);

        if (!$plat) {
            $msg = '⚠ Esa plataforma no está disponible.';
        } elseif ((float) ($plat['precio_distribuidor'] ?? 0) <= 0) {
            $msg = '⚠ Esa plataforma no tiene precio de revendedor configurado. Pídeselo al administrador.';
        } else {
            $precio = (float) $plat['precio_distribuidor'];
            $dias = (int) ($plat['dias_default'] ?: 30);
            // Nombre de la plataforma: se usa ADEMÁS del id para localizar las cuentas, porque en el server
            // real hay cuentas cuyo plataforma_id NO quedó vinculado al tipo (importadas de PAC, etc.) — el
            // nombre sí coincide. Sin esto, esas cuentas son INVISIBLES para comprar (ni perfil ni completa).
            $platNombre = (string) $plat['nombre'];

            // Cliente del revendedor (opcional) — debe pertenecerle (owner = uid).
            $cliNombre = null; $cliWa = null;
            if ($cliId) {
                $c = $pdo->prepare("SELECT nombre, wa FROM streaming_clientes WHERE id=? AND owner_id=?");
                $c->execute([$cliId, $uid]);
                $cc = $c->fetch(PDO::FETCH_ASSOC);
                if (!$cc) { $cliId = null; } else { $cliNombre = $cc['nombre']; $cliWa = $cc['wa']; }
            }
            // #9: si no eligió un cliente ya guardado pero escribió nombre → buscarlo (por WhatsApp o nombre)
            // y, si no existe, crearlo al vuelo. Así no hace dos procesos (guardar cliente + comprar).
            if (!$cliId) {
                $nom = trim((string) ($_POST['cliente_nombre'] ?? ''));
                $wa  = function_exists('wa_norm') ? wa_norm((string) ($_POST['cliente_wa'] ?? '')) : preg_replace('/\D+/', '', (string) ($_POST['cliente_wa'] ?? ''));
                $em  = trim((string) ($_POST['cliente_email'] ?? '')) ?: null;
                if ($nom !== '') {
                    $found = null;
                    if ($wa !== '') { $q = $pdo->prepare("SELECT id, nombre, wa FROM streaming_clientes WHERE owner_id=? AND wa=? LIMIT 1"); $q->execute([$uid, $wa]); $found = $q->fetch(PDO::FETCH_ASSOC) ?: null; }
                    if (!$found) { $q = $pdo->prepare("SELECT id, nombre, wa FROM streaming_clientes WHERE owner_id=? AND nombre=? LIMIT 1"); $q->execute([$uid, $nom]); $found = $q->fetch(PDO::FETCH_ASSOC) ?: null; }
                    if ($found) { $cliId = (int) $found['id']; $cliNombre = $found['nombre']; $cliWa = $found['wa'] ?: ($wa ?: null); }
                    else { $pdo->prepare("INSERT INTO streaming_clientes (owner_id,nombre,wa,email) VALUES (?,?,?,?)")->execute([$uid, $nom, $wa ?: null, $em]); $cliId = (int) $pdo->lastInsertId(); $cliNombre = $nom; $cliWa = $wa ?: null; }
                }
            }

            st_rev_stock_schema($pdo);
            // Asegura el esquema del SALDO aquí, FUERA de la transacción: si se ejecutara dentro (dentro
            // de wallet_debitar) su ALTER/CREATE haría commit implícito → "There is no active transaction".
            if (function_exists('wallet_ensure_schema')) { try { wallet_ensure_schema($pdo); } catch (Throwable $e) {} }
            $venc = date('Y-m-d', strtotime("+$dias days"));
            // Nombre con el que el admin verá la venta. nombre_tienda es una columna nueva: puede no
            // existir aún en la BD del cliente → probamos y caemos a nombre/username sin romper.
            $miNombre = '';
            try { $miNombre = (string) ($pdo->query("SELECT COALESCE(NULLIF(nombre_tienda,''), NULLIF(nombre,''), username) FROM usuarios WHERE id=" . (int) $uid)->fetchColumn() ?: ''); } catch (Throwable $e) { $miNombre = ''; }
            if ($miNombre === '') {
                try { $miNombre = (string) ($pdo->query("SELECT COALESCE(NULLIF(nombre,''), username) FROM usuarios WHERE id=" . (int) $uid)->fetchColumn() ?: ''); } catch (Throwable $e) { $miNombre = ''; }
            }
            if ($miNombre === '') $miNombre = 'Revendedor #' . $uid;

            // ── E) MODOS POR INVITACIÓN / ACTIVACIÓN ─────────────────────────────────────
            // No hay stock: el revendedor da el correo (invitación) o correo+clave (activación) donde
            // el ADMIN activará a mano. Se descuenta el saldo y ambas ventas quedan PENDIENTES hasta que
            // el admin las marque entregadas. Nunca cobra de más: si el saldo no alcanza, no pasa nada.
            $modo = (string) ($plat['modo'] ?? 'perfil');
            if ($modo === 'email_manual') $modo = 'invitacion';
            if ($modo === 'invitacion' || $modo === 'activacion') {
                if ((int) ($plat['disponible'] ?? 1) === 0) {
                    header('Location: comprar.php?msg=' . urlencode('⚠ Esta plataforma está AGOTADA por ahora. Intenta más tarde.'));
                    exit;
                }
                // ¿Es una COMPLETA por activación (Spotify Familiar / YouTube Premium)? Se distingue por la
                // unidad_venta='cuenta' de la plataforma. La completa pasa correo+clave (como activación) PERO
                // al aprobar el admin le cae al revendedor en su STOCK (con N cupos).
                $esCompletaAct = (($plat['unidad_venta'] ?? 'perfil') === 'cuenta');
                $cupos = $esCompletaAct ? max(1, min(50, (int) ($_POST['svc_cupos'] ?? 1))) : null;
                $tipoV = $esCompletaAct ? 'cuenta' : $modo;
                $svcEmail = trim((string) ($_POST['svc_email'] ?? ''));
                $svcClave = trim((string) ($_POST['svc_clave'] ?? ''));
                if ($svcEmail === '' || !filter_var($svcEmail, FILTER_VALIDATE_EMAIL)) {
                    header('Location: comprar.php?msg=' . urlencode('⚠ Escribe un correo válido donde se activará el servicio.'));
                    exit;
                }
                // La activación y la COMPLETA necesitan la clave (se activa a un correo con su contraseña).
                $requiereClave = ($modo === 'activacion' || $esCompletaAct);
                if ($requiereClave && $svcClave === '') {
                    header('Location: comprar.php?msg=' . urlencode('⚠ Debes escribir también la contraseña de la cuenta a activar.'));
                    exit;
                }
                $claveGuardar = $requiereClave ? $svcClave : '';
                $total = round($precio, 2); // 1 servicio por compra manual
                $pdo->beginTransaction();
                try {
                    if (!wallet_debitar($pdo, $uid, $total, 'compra_streaming', 'Streaming ' . $tipoV . ' · ' . $plat['nombre'])) {
                        throw new RuntimeException('saldo_insuficiente');
                    }
                    // Venta del ADMIN (owner 0): activa pero SIN entregar (entregada=0) → pendiente de activar.
                    // email_activar guarda el correo que el admin debe invitar/activar. cupos>0 = completa (→ stock al aprobar).
                    $insA = $pdo->prepare("INSERT INTO streaming_ventas
                        (owner_id, plataforma, tipo, revendedor_id, cliente_nombre, cliente_wa, correo, clave, email_activar, precio, cupos, fecha_inicio, fecha_vencimiento, estado, entregada, creado_por)
                        VALUES (0,?,?,?,?,?,?,?,?,?,?,?,?, 'activa', 0, ?)");
                    $insA->execute([$plat['nombre'], $tipoV, $uid, $miNombre, $cliWa, $svcEmail, $claveGuardar, $svcEmail, $total, $cupos, date('Y-m-d'), $venc, $uid]);
                    $vidAdmin = (int) $pdo->lastInsertId();
                    try { $pdo->prepare("INSERT INTO streaming_venta_registro (venta_id, evento, descripcion, usuario_id) VALUES (?,?,?,?)")
                          ->execute([$vidAdmin, 'creada', 'Compra ' . ($esCompletaAct ? 'COMPLETA por activación' : $modo) . ' de ' . $miNombre . ' · correo ' . $svcEmail, $uid]); } catch (Throwable $e) {}
                    // Venta del REVENDEDOR (owner=uid): pendiente y ENLAZADA a la del admin (origen_venta_id).
                    // BUG PRE-EXISTENTE corregido (2026-08-30, desde el 09/08 original): faltaba un
                    // placeholder para fecha_vencimiento — la lista tiene 19 columnas y el VALUES traía
                    // solo 18, error real de MySQL "1136 Column count doesn't match value count". El
                    // array de abajo YA traía $venc en su lugar correcto; solo faltaba el '?' del SQL.
                    $insR = $pdo->prepare("INSERT INTO streaming_ventas
                        (owner_id, plataforma, tipo, revendedor_id, cliente_id, cliente_nombre, cliente_wa, correo, clave, email_activar, precio, precio_venta_cliente, cupos, fecha_inicio, fecha_vencimiento, estado, entregada, origen_venta_id, creado_por)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?, 'activa', 0, ?, ?)");
                    $insR->execute([$uid, $plat['nombre'], $tipoV, $uid, $cliId, $cliNombre, $cliWa, $svcEmail, $claveGuardar, $svcEmail, ($precioVenta !== null ? $precioVenta : $precio), $precioVenta, $cupos, date('Y-m-d'), $venc, $vidAdmin, $uid]);
                    $pdo->commit();
                    try {
                        require_once __DIR__ . '/../../api/_rev_avisos.php';
                        $etqModo = $esCompletaAct ? 'completa por activación' : ($modo === 'activacion' ? 'activación' : 'invitación');
                        stream_notif_crear($pdo, STREAM_ADMIN_OWNER, 'compra',
                            'Activar · ' . $plat['nombre'] . ' (' . $etqModo . ')',
                            $miNombre . ' compró ' . $plat['nombre'] . '. Correo: ' . $svcEmail . ($requiereClave ? ' · Clave: ' . $svcClave : '') . ($esCompletaAct ? ' · Cupos: ' . $cupos . ' → al aprobar cae en su stock.' : '') . ' Actívalo y márcalo entregado en Ventas.',
                            'ventas.php', $uid);
                        stream_notif_crear($pdo, $uid, 'compra',
                            'Compra en proceso · ' . $plat['nombre'],
                            'Se descontaron $' . number_format($total, 2) . '. El administrador activará ' . $svcEmail . ($esCompletaAct ? ' y la cuenta caerá en tu stock.' : ' y te avisaremos.'),
                            'ventas.php', $uid);
                    } catch (Throwable $e) {}
                    header('Location: comprar.php?msg=' . urlencode('✓ Compra enviada. El administrador activará ' . $svcEmail . ' pronto. Se descontaron $' . number_format($total, 2) . ' de tu saldo.' . ($esCompletaAct ? ' Al aprobarla, la cuenta caerá en tu stock.' : '')));
                    exit;
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) { $pdo->rollBack(); }
                    $m = $e->getMessage();
                    header('Location: comprar.php?msg=' . urlencode($m === 'saldo_insuficiente' ? '⚠ Saldo insuficiente. Recarga tu saldo para comprar.' : '⚠ No se pudo completar la compra: ' . mb_substr((string) $m, 0, 180)));
                    exit;
                }
            }

            $pdo->beginTransaction();
            try {
                // ---- 1) Reservar del stock del ADMIN lo que se compró -------------------------
                // 'perfil' → N perfiles libres (los que haya, de las cuentas que venzan primero).
                // 'cuenta' → N cuentas del admin que tengan TODOS sus perfiles libres (cuenta entera).
                $lotes = [];   // cada lote = ['cuenta'=>row, 'perfiles'=>[rows]]
                if ($unidad === 'cuenta') {
                    $selC = $pdo->prepare("SELECT c.id, c.plataforma, c.plataforma_id, c.correo, c.clave, c.usa_pin, c.vencimiento
                                            FROM streaming_cuentas c
                                            WHERE c.owner_id=" . STREAM_ADMIN_OWNER . " AND (c.plataforma_id=? OR c.plataforma=?) AND c.estado='activa'
                                              AND (SELECT COUNT(*) FROM streaming_perfiles sp WHERE sp.cuenta_id=c.id AND sp.estado<>'inactivo') > 0
                                              AND (SELECT COUNT(*) FROM streaming_perfiles sp WHERE sp.cuenta_id=c.id AND sp.estado<>'libre' AND sp.estado<>'inactivo') = 0
                                            ORDER BY (c.vencimiento IS NULL), c.vencimiento ASC
                                            LIMIT $cantidad FOR UPDATE");
                    $selC->execute([$platId, $platNombre]);
                    foreach ($selC->fetchAll(PDO::FETCH_ASSOC) as $cta) {
                        $sp = $pdo->prepare("SELECT id, etiqueta, pin FROM streaming_perfiles WHERE cuenta_id=? AND estado='libre' ORDER BY id");
                        $sp->execute([(int) $cta['id']]);
                        $perfs = $sp->fetchAll(PDO::FETCH_ASSOC);
                        if ($perfs) $lotes[] = ['cuenta' => $cta, 'perfiles' => $perfs];
                    }
                } else {
                    $selP = $pdo->prepare("SELECT p.id AS pid, p.etiqueta, p.pin AS ppin, c.id AS cuenta_id, c.plataforma, c.plataforma_id, c.correo, c.clave, c.usa_pin, c.vencimiento
                                            FROM streaming_perfiles p
                                            JOIN streaming_cuentas c ON c.id = p.cuenta_id
                                            WHERE c.owner_id=" . STREAM_ADMIN_OWNER . " AND (c.plataforma_id=? OR c.plataforma=?) AND p.estado='libre' AND c.estado='activa'
                                            ORDER BY (c.vencimiento IS NULL), c.vencimiento ASC, p.id
                                            LIMIT $cantidad FOR UPDATE");
                    $selP->execute([$platId, $platNombre]);
                    foreach ($selP->fetchAll(PDO::FETCH_ASSOC) as $r) {
                        $lotes[] = [
                            'cuenta'   => ['id' => (int) $r['cuenta_id'], 'plataforma' => $r['plataforma'], 'plataforma_id' => $r['plataforma_id'], 'correo' => $r['correo'], 'clave' => $r['clave'], 'usa_pin' => $r['usa_pin'], 'vencimiento' => $r['vencimiento']],
                            'perfiles' => [['id' => (int) $r['pid'], 'etiqueta' => $r['etiqueta'], 'pin' => $r['ppin']]],
                        ];
                    }
                }
                if (!$lotes) { throw new RuntimeException('sin_stock'); }

                // ---- 2) Precio real según lo que de verdad se pudo reservar --------------------
                $nPerfilesTotal = 0;
                foreach ($lotes as $l) { $nPerfilesTotal += count($l['perfiles']); }
                $nCuentas = count($lotes);
                // Si la PLATAFORMA se vende como CUENTA COMPLETA, el precio (precio_distribuidor) es POR
                // CUENTA, NO por perfil. Antes multiplicaba por los perfiles → una cuenta de 5 a $10 cobraba
                // $50. Ahora: cuenta completa → precio × nº de cuentas; perfil → precio × nº de perfiles.
                $esPlatCuenta = (($plat['unidad_venta'] ?? 'perfil') === 'cuenta');
                $total = $esPlatCuenta ? round($precio * $nCuentas, 2) : round($precio * $nPerfilesTotal, 2);

                // ---- 3) Débito ATÓMICO del saldo del revendedor (solo si alcanza) --------------
                if (!wallet_debitar($pdo, $uid, $total, 'compra_streaming', 'Streaming mayorista · ' . $plat['nombre'] . ' · ' . $nPerfilesTotal . ' perfil(es)')) {
                    throw new RuntimeException('saldo_insuficiente');
                }

                $resumen = []; $misVentas = 0; $aStock = 0;
                // Ids para el aviso por correo, que se manda DESPUÉS del commit (nunca SMTP dentro de
                // la transacción). $idsAClientes = ventas a su cliente · $idsAStock = lo que entró a su stock.
                $idsAClientes = []; $idsAStock = [];
                foreach ($lotes as $lote) {
                    $cta = $lote['cuenta'];
                    // 3.1) Marcar VENDIDOS los perfiles del admin (claim atómico por perfil).
                    $idsOk = [];
                    foreach ($lote['perfiles'] as $pf) {
                        $up = $pdo->prepare("UPDATE streaming_perfiles SET estado='vendido' WHERE id=? AND estado='libre'");
                        $up->execute([(int) $pf['id']]);
                        if ($up->rowCount() === 1) $idsOk[] = $pf;
                    }
                    if (!$idsOk) continue;

                    // 3.2) VENTA DEL ADMIN (owner 0): al admin le queda registrada la venta al revendedor
                    //      DESDE YA, pase lo que pase después del lado del revendedor.
                    $tipoVenta = $unidad === 'cuenta' ? 'cuenta' : 'perfil';
                    $etqs = implode(', ', array_map(static fn($x) => (string) ($x['etiqueta'] ?? ''), $idsOk));
                    $insA = $pdo->prepare("INSERT INTO streaming_ventas
                        (owner_id, plataforma, tipo, cuenta_id, revendedor_id, cliente_nombre, correo, clave, perfil, pin, precio, fecha_inicio, fecha_vencimiento, estado, entregada, creado_por)
                        VALUES (0,?,?,?,?,?,?,?,?,?,?,?,?, 'activa', 1, ?)");
                    // Precio de ESTA línea: si es plataforma de cuenta completa → precio por cuenta (1×);
                    // si es por perfil → precio × perfiles de esta cuenta.
                    $lineaPrecio = $esPlatCuenta ? round($precio, 2) : round($precio * count($idsOk), 2);
                    $insA->execute([
                        $plat['nombre'], $tipoVenta, (int) $cta['id'], $uid, $miNombre,
                        $cta['correo'], $cta['clave'], $etqs, (string) ($idsOk[0]['pin'] ?? ''),
                        $lineaPrecio, date('Y-m-d'), $venc, $uid,
                    ]);
                    $vidAdmin = (int) $pdo->lastInsertId();
                    foreach ($idsOk as $pf) {
                        try { $pdo->prepare("UPDATE streaming_perfiles SET venta_id=? WHERE id=?")->execute([$vidAdmin, (int) $pf['id']]); } catch (Throwable $e) {}
                    }
                    try {
                        $pdo->prepare("INSERT INTO streaming_venta_registro (venta_id, evento, descripcion, usuario_id) VALUES (?,?,?,?)")
                            ->execute([$vidAdmin, 'creada', 'Comprado del stock por el revendedor ' . $miNombre, $uid]);
                    } catch (Throwable $e) {}

                    // 3.3) STOCK PROPIO DEL REVENDEDOR: cuenta espejo + perfiles espejo (libres).
                    // Su fecha de vencimiento = la de ESTA compra ($venc), no la del admin. El costo por
                    // perfil = $precio (para que el dashboard cuente bien su ganancia).
                    // Si compró la CUENTA COMPLETA, en su panel podrá editar clave/perfiles/PINes.
                    // Costo por perfil del espejo: en plataforma de cuenta completa, el costo de la CUENTA es
                    // $precio → costo por perfil = precio/nºperfiles (así el total del espejo = $precio, no ×perfiles).
                    $costoUnit = $esPlatCuenta ? ($precio / max(1, count($idsOk))) : $precio;
                    $mirrorCta = st_rev_mirror_cuenta($pdo, $uid, $cta, $costoUnit, $unidad === 'cuenta', $venc);
                    // BOT de códigos: la cuenta espejo nace con bot_asignado=0; se asigna UNA sola vez con
                    // bot_codigos_flush() DESPUÉS del commit (no por perfil → sin conteo doble en prycorreos).
                    $mirrorPerfs = [];
                    foreach ($idsOk as $pf) {
                        $mp = st_rev_mirror_perfil($pdo, $mirrorCta, $pf, $costoUnit);
                        if ($mp > 0) $mirrorPerfs[] = $mp;
                    }

                    // 3.4) Si eligió cliente, se lo vendemos YA (una venta suya por perfil).
                    if ($cliId && $mirrorPerfs) {
                        foreach ($mirrorPerfs as $i => $mpid) {
                            $pf = $idsOk[$i] ?? $idsOk[0];
                            $insR = $pdo->prepare("INSERT INTO streaming_ventas
                                (owner_id, plataforma, tipo, cuenta_id, revendedor_id, cliente_id, cliente_nombre, cliente_wa, correo, clave, perfil, pin, precio, precio_venta_cliente, fecha_inicio, fecha_vencimiento, estado, entregada, creado_por)
                                VALUES (?,?, 'perfil', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'activa', 1, ?)");
                            $insR->execute([$uid, $plat['nombre'], $mirrorCta, $uid, $cliId, $cliNombre, $cliWa,
                                $cta['correo'], $cta['clave'], (string) ($pf['etiqueta'] ?? ''), (string) ($pf['pin'] ?? ''),
                                $precioVenta !== null ? $precioVenta : $costoUnit, $precioVenta, date('Y-m-d'), $venc, $uid]);
                            $vidRev = (int) $pdo->lastInsertId();
                            $pdo->prepare("UPDATE streaming_perfiles SET estado='vendido', venta_id=? WHERE id=? AND estado='libre'")->execute([$vidRev, $mpid]);
                            $misVentas++;
                            $idsAClientes[] = $vidRev;
                        }
                    } else {
                        $aStock += count($mirrorPerfs);   // queda en SU stock, para venderlo cuando quiera
                        // Sin cliente asignado: el aviso es "entró esto a tu stock", sobre la venta del
                        // admin (owner 0 + revendedor_id = él) → el correo le llega a él.
                        $idsAStock[] = $vidAdmin;
                    }
                    $resumen[] = ($cta['correo'] ?? '') . ' (' . count($idsOk) . ')';
                }

                $pdo->commit();

                // BOT de códigos (FUERA de la transacción): asigna UNA vez por correo nuevo del revendedor.
                if (function_exists('bot_codigos_flush')) { try { bot_codigos_flush($pdo, $uid); } catch (Throwable $e) {} }

                // Aviso por CORREO (agregado), también fuera de la transacción y en try/catch: la compra
                // ya está commiteada, un fallo de correo no puede tumbarla. Las ventas a su cliente van
                // como 'compra' (le llegan a su cliente y a él); lo que quedó en stock va como 'entrega'.
                foreach ($idsAClientes as $vidC) { try { stream_email_notificar_venta($pdo, (int) $vidC, 'compra'); } catch (Throwable $e) {} }
                foreach ($idsAStock as $vidS)    { try { stream_email_notificar_venta($pdo, (int) $vidS, 'entrega'); } catch (Throwable $e) {} }

                // Notificaciones (fuera de la transacción: nunca deben tumbar la compra).
                try {
                    require_once __DIR__ . '/../../api/_rev_avisos.php';
                    stream_notif_crear($pdo, STREAM_ADMIN_OWNER, 'compra',
                        'Compra del stock · ' . $miNombre,
                        $miNombre . ' compró ' . $nPerfilesTotal . ' ' . ($unidad === 'cuenta' ? 'perfil(es) en cuenta(s) completa(s)' : 'perfil(es)') . ' de ' . $plat['nombre'] . ' por $' . number_format($total, 2) . '.',
                        'ventas.php', $uid);
                    stream_notif_crear($pdo, $uid, 'compra',
                        'Compraste ' . $nPerfilesTotal . ' × ' . $plat['nombre'],
                        'Se descontaron $' . number_format($total, 2) . ' de tu saldo. ' . ($misVentas > 0 ? $misVentas . ' venta(s) a tu cliente.' : 'Está en tu stock, listo para vender.'),
                        $aStock > 0 ? 'perfiles.php' : 'ventas.php', $uid);
                } catch (Throwable $e) {}

                $msg = '✓ ¡Compra realizada! ' . $nPerfilesTotal . ' perfil(es) de ' . $plat['nombre'] . ' · $' . number_format($total, 2)
                     . ' · Vence: ' . $venc . '. '
                     . ($aStock > 0 ? 'Está en TU STOCK (pestaña «Perfiles»), listo para que lo vendas cuando quieras.' : 'Ya quedó vendido a tu cliente (pestaña «Ventas»).');
                if ($nPerfilesTotal < $cantidad && $unidad === 'perfil') {
                    $msg .= ' Nota: solo había ' . $nPerfilesTotal . ' de los ' . $cantidad . ' que pediste (se cobró solo lo entregado).';
                }
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                $m = $e->getMessage();
                $msg = $m === 'sin_stock' ? '⚠ No hay stock disponible de esa plataforma ahora mismo. Intenta más tarde.'
                     : ($m === 'saldo_insuficiente' ? '⚠ Saldo insuficiente. Recarga tu saldo para comprar.'
                     : '⚠ No se pudo completar la compra: ' . mb_substr((string) $m, 0, 180));  // detalle real para diagnosticar
            }
        }
        header('Location: comprar.php?msg=' . urlencode($msg));
        exit;
    }
}

$flash = (string) ($_GET['msg'] ?? '');
$saldo = wallet_saldo($pdo, $uid);

// Descripción/garantía por plataforma (se crean solas si no existen) para mostrarlas en la compra.
try { $pdo->exec("ALTER TABLE streaming_plataformas ADD COLUMN descripcion_venta TEXT NULL"); } catch (Throwable $e) {}
try { $pdo->exec("ALTER TABLE streaming_plataformas ADD COLUMN garantia TEXT NULL"); } catch (Throwable $e) {}
// Catálogo del STOCK DEL ADMIN con precio de revendedor y stock libre en vivo.
try { $pdo->exec("ALTER TABLE streaming_plataformas ADD COLUMN unidad_venta VARCHAR(10) NOT NULL DEFAULT 'perfil'"); } catch (Throwable $e) {}
try { $pdo->exec("ALTER TABLE streaming_plataformas ADD COLUMN modo_entrega VARCHAR(20) NOT NULL DEFAULT 'perfil'"); } catch (Throwable $e) {}
try { $pdo->exec("ALTER TABLE streaming_plataformas ADD COLUMN disponible TINYINT(1) NOT NULL DEFAULT 1"); } catch (Throwable $e) {}
$stock = $pdo->query("SELECT pl.id, pl.nombre, pl.emoji, pl.color, pl.logo_url, pl.precio_distribuidor, pl.dias_default, pl.descripcion_venta, pl.garantia, COALESCE(pl.unidad_venta,'perfil') AS unidad_venta, COALESCE(pl.modo_entrega,'perfil') AS modo, COALESCE(pl.disponible,1) AS disponible,
        (SELECT COUNT(*) FROM streaming_ventas sv WHERE sv.plataforma=pl.nombre AND sv.estado<>'cancelada') AS vendidos_n,
        (SELECT COUNT(*) FROM streaming_perfiles p JOIN streaming_cuentas c ON c.id=p.cuenta_id
          WHERE c.owner_id=" . STREAM_ADMIN_OWNER . " AND (c.plataforma_id=pl.id OR c.plataforma=pl.nombre) AND p.estado='libre' AND c.estado='activa') AS libres,
        (SELECT COUNT(DISTINCT c.id) FROM streaming_cuentas c
          WHERE c.owner_id=" . STREAM_ADMIN_OWNER . " AND (c.plataforma_id=pl.id OR c.plataforma=pl.nombre) AND c.estado='activa'
            AND (SELECT COUNT(*) FROM streaming_perfiles sp WHERE sp.cuenta_id=c.id AND sp.estado<>'inactivo')>0
            AND (SELECT COUNT(*) FROM streaming_perfiles sp WHERE sp.cuenta_id=c.id AND sp.estado<>'libre' AND sp.estado<>'inactivo')=0) AS cuentas_completas
    FROM streaming_plataformas pl
    WHERE pl.owner_id=" . STREAM_ADMIN_OWNER . " AND pl.activo=1 AND pl.en_tienda=1 AND pl.precio_distribuidor>0
    ORDER BY pl.orden, pl.nombre")->fetchAll(PDO::FETCH_ASSOC);

$misClientes = st_clientes($pdo); // clientes del revendedor (owner = uid)

// Prueba social: iniciales de quienes REALMENTE compraron cada plataforma (no círculos genéricos).
$buyersByPlat = [];
try {
    $bq = $pdo->query("SELECT plataforma, COALESCE(NULLIF(TRIM(cliente_nombre),''),'?') AS nom, MAX(id) AS mx
                       FROM streaming_ventas WHERE estado<>'cancelada' AND plataforma IS NOT NULL AND plataforma<>''
                       GROUP BY plataforma, nom ORDER BY mx DESC");
    foreach ($bq->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $p = (string) $r['plataforma'];
        if (!isset($buyersByPlat[$p])) $buyersByPlat[$p] = [];
        if (count($buyersByPlat[$p]) < 4) $buyersByPlat[$p][] = mb_strtoupper(mb_substr(trim((string) $r['nom']), 0, 1) ?: '?');
    }
} catch (Throwable $e) {}

// Tasa en Bs: LA MISMA que usa la página de recargas (tabla `monedas`, moneda NO base = Bolívar).
// NO usar configuracion.tasa_bcv (esa es la tasa BCV oficial ~35-40); el cliente cobra a su tasa (p.ej. 900).
$tasa = 0.0; $bsDec = 2;
try {
    $monBs = $pdo->query("SELECT tasa, mostrar_decimales FROM monedas WHERE es_base=0 AND activo=1 ORDER BY id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$monBs) { $monBs = $pdo->query("SELECT tasa, mostrar_decimales FROM monedas WHERE es_base=0 ORDER BY id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC); }
    if ($monBs) { $tasa = (float) $monBs['tasa']; $bsDec = ((int) ($monBs['mostrar_decimales'] ?? 1) === 1) ? 2 : 0; }
} catch (Throwable $e) { $tasa = 0.0; }
$bsTxt = static function (float $usd) use ($tasa, $bsDec): string {
    if ($tasa <= 0 || $usd <= 0) return '';
    $v = $usd * $tasa;
    $v = $bsDec > 0 ? round($v, 2) : floor($v);
    return '≈ Bs ' . number_format($v, $bsDec, ',', '.');
};

stream_head('Comprar del stock', 'comprar');
?>
<?php if ($flash): ?><div class="banner" style="margin-bottom:16px;white-space:normal"><i data-lucide="info"></i><?= h($flash) ?></div><?php endif; ?>

<div class="pagehd">
  <div>
    <h1>Comprar del <span class="nm">stock de la tienda</span></h1>
    <p>Compra un perfil disponible del inventario de la tienda a tu precio de revendedor. Se descuenta de tu saldo.</p>
  </div>
  <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
    <div class="card" style="padding:10px 16px"><div style="font-size:11px;color:var(--faint);font-weight:700;text-transform:uppercase">Tu saldo</div>
      <div class="tnum" style="font-size:20px;font-weight:800;color:var(--accent)">$<?= number_format($saldo, 2) ?></div></div>
    <a href="saldo.php" class="btn primary"><i data-lucide="wallet"></i> Recargar saldo</a>
  </div>
</div>

<?php if (!$stock): ?>
  <div class="card" style="padding:40px;text-align:center;color:var(--muted)">
    <div style="font-size:38px">🛒</div>
    La tienda aún no tiene plataformas disponibles para revendedores (con precio de revendedor y stock).
  </div>
<?php else: ?>
  <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-4 gap-3">
    <?php foreach ($stock as $s):
      $esManual = in_array($s['modo'], ['invitacion', 'activacion', 'email_manual'], true);
      $agotadoManual = $esManual && (int) ($s['disponible'] ?? 1) === 0;   // agotado marcado a mano
      $sinStock = $agotadoManual || (!$esManual && (int) $s['libres'] <= 0); ?>
      <div class="card" style="padding:16px;display:flex;flex-direction:column;gap:10px">
        <div style="display:flex;align-items:center;gap:10px">
          <?php if (!empty($s['logo_url'])): ?>
            <img src="<?= h($s['logo_url']) ?>" alt="" style="width:34px;height:34px;border-radius:9px;object-fit:cover;background:var(--surface-2)">
          <?php else: ?>
            <span style="width:34px;height:34px;border-radius:9px;display:grid;place-items:center;font-weight:800;color:#fff;background:<?= h($s['color'] ?: '#3f4fb5') ?>"><?= h($s['emoji'] ?: mb_substr($s['nombre'], 0, 1)) ?></span>
          <?php endif; ?>
          <div><div style="font-weight:800"><?= h($s['nombre']) ?></div>
            <?php if ($esManual): ?>
            <div style="font-size:12px;color:var(--muted)"><?= (int) $s['dias_default'] ?> días · <span style="color:var(--accent);font-weight:700"><?= $s['modo'] === 'activacion' ? 'Por activación' : 'Por invitación' ?></span> · <span style="color:<?= $agotadoManual ? 'var(--bad)' : 'var(--good)' ?>;font-weight:700"><?= $agotadoManual ? 'Agotado' : 'Disponible' ?></span></div>
            <?php else: ?>
            <div style="font-size:12px;color:var(--muted)"><?= (int) $s['dias_default'] ?> días · <span style="color:<?= $sinStock ? 'var(--bad)' : 'var(--good)' ?>;font-weight:700"><?= (int) $s['libres'] ?> perfil(es)</span><?php if ((int) ($s['cuentas_completas'] ?? 0) > 0): ?> · <span style="color:var(--accent);font-weight:700"><?= (int) $s['cuentas_completas'] ?> completa(s)</span><?php endif; ?></div>
            <?php endif; ?></div>
        </div>
        <div>
          <div style="display:flex;align-items:baseline;gap:6px">
            <span class="tnum" style="font-size:22px;font-weight:800">$<?= number_format((float) $s['precio_distribuidor'], 2) ?></span>
            <span style="font-size:12px;color:var(--faint)">/ <?= ($s['unidad_venta'] ?? 'perfil') === 'cuenta' ? 'cuenta completa' : 'perfil' ?></span>
          </div>
          <?php $bs = $bsTxt((float) $s['precio_distribuidor']); if ($bs !== ''): ?><div style="font-size:11px;color:var(--faint)"><?= h($bs) ?></div><?php endif; ?>
        </div>
        <?php $vn = (int) ($s['vendidos_n'] ?? 0); $inis = $buyersByPlat[$s['nombre']] ?? []; if ($vn > 0): ?>
        <div style="display:flex;align-items:center;gap:6px;font-size:11.5px;color:var(--muted)">
          <span style="display:inline-flex"><?php $cols = ['#ef4444', '#f59e0b', '#22c55e', '#3b82f6']; $shown = $inis ?: array_fill(0, min(3, $vn), '?'); foreach (array_slice($shown, 0, 3) as $i => $ini): $cc = $cols[$i % 4]; ?><span title="Compró: <?= h($ini) ?>" style="width:18px;height:18px;border-radius:50%;background:<?= $cc ?>;border:2px solid var(--surface);margin-left:<?= $i ? '-7px' : '0' ?>;display:inline-flex;align-items:center;justify-content:center;color:#fff;font-size:9px;font-weight:800"><?= h($ini) ?></span><?php endforeach; ?></span>
          <span>🔥 <b style="color:var(--text)"><?= $vn ?></b> ya lo compraron</span>
        </div>
        <?php endif; ?>
        <?php if ($sinStock): ?>
          <button class="btn ghost" disabled style="opacity:.6;cursor:not-allowed"><?= $agotadoManual ? 'Agotado' : 'Sin stock' ?></button>
        <?php else: ?>
          <button class="btn primary" onclick='abrirComprar(<?= json_encode(["id"=>(int)$s["id"],"nombre"=>$s["nombre"],"precio"=>(float)$s["precio_distribuidor"],"desc"=>(string)($s["descripcion_venta"] ?? ""),"garantia"=>(string)($s["garantia"] ?? ""),"unidad"=>(string)($s["unidad_venta"] ?? "perfil"),"modo"=>(string)($s["modo"] ?? "perfil")], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'><i data-lucide="shopping-cart"></i> Comprar</button>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<!-- Modal comprar -->
<div id="mComprar" class="modal-bg" style="display:none;position:fixed;inset:0;background:#0008;align-items:flex-start;justify-content:center;padding:28px 14px;z-index:60;overflow:auto">
  <div class="card" style="max-width:440px;width:100%;padding:22px">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px">
      <h3 style="margin:0" id="mcTitle">Comprar</h3>
      <button onclick="cerrarComprar()" class="iconbtn"><i data-lucide="x"></i></button>
    </div>
    <form method="post">
      <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="accion" value="comprar">
      <input type="hidden" name="plataforma_id" id="mc-plat">
      <p style="color:var(--muted);font-size:13px;margin:0 0 12px" id="mcInfo"></p>
      <label style="display:block;font-size:12px;color:var(--muted);font-weight:600;margin-bottom:5px">Cliente (opcional · escribe y elige, o crea uno nuevo)</label>
      <input list="mcClientesList" name="cliente_nombre" id="mc-clinombre" oninput="mcCliSel(this.value)" placeholder="Nombre del cliente…" autocomplete="off" style="width:100%;margin-bottom:8px;padding:9px;border:1px solid var(--border);border-radius:9px;background:var(--surface);color:var(--text)">
      <datalist id="mcClientesList"><?php foreach ($misClientes as $c): ?><option value="<?= h($c['nombre']) ?>"><?php endforeach; ?></datalist>
      <div style="display:flex;gap:8px;margin-bottom:12px">
        <input name="cliente_wa" id="mc-cliwa" placeholder="WhatsApp (opcional)" style="flex:1;min-width:0;padding:9px;border:1px solid var(--border);border-radius:9px;background:var(--surface);color:var(--text)">
        <input name="cliente_email" id="mc-cliemail" placeholder="Correo (opcional)" style="flex:1;min-width:0;padding:9px;border:1px solid var(--border);border-radius:9px;background:var(--surface);color:var(--text)">
      </div>
      <script>
        var MC_CLIENTES = <?= json_encode(array_map(static fn($c) => ['n' => (string) ($c['nombre'] ?? ''), 'wa' => (string) ($c['wa'] ?? ''), 'em' => (string) ($c['email'] ?? '')], $misClientes), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
        function mcCliSel(v){ v=(v||'').trim().toLowerCase(); var m=MC_CLIENTES.find(function(c){return (c.n||'').toLowerCase()===v;}); var wa=document.getElementById('mc-cliwa'), em=document.getElementById('mc-cliemail'); if(m){ if(wa)wa.value=m.wa||''; if(em)em.value=m.em||''; } }
      </script>
      <!-- Modos por invitación / activación: el revendedor da el correo (y clave en activación) donde el admin activará. -->
      <div id="mc-manual" style="display:none;background:var(--surface-2);border:1px solid var(--border);border-radius:10px;padding:12px;margin-bottom:12px">
        <div id="mc-manual-hint" style="font-size:12px;color:var(--muted);margin-bottom:8px"></div>
        <label style="display:block;font-size:12px;color:var(--muted);font-weight:600;margin-bottom:5px">Correo donde se activará</label>
        <input type="email" name="svc_email" id="mc-svcemail" placeholder="correo@ejemplo.com" style="width:100%;margin-bottom:8px;padding:9px;border:1px solid var(--border);border-radius:9px;background:var(--surface);color:var(--text)">
        <div id="mc-svcclave-wrap" style="display:none">
          <label style="display:block;font-size:12px;color:var(--muted);font-weight:600;margin-bottom:5px">Contraseña de ese correo/cuenta</label>
          <input type="text" name="svc_clave" id="mc-svcclave" placeholder="Contraseña" style="width:100%;padding:9px;border:1px solid var(--border);border-radius:9px;background:var(--surface);color:var(--text)">
        </div>
        <div id="mc-cupos-wrap" style="display:none;margin-top:8px">
          <label style="display:block;font-size:12px;color:var(--muted);font-weight:600;margin-bottom:5px">¿Cuántos cupos/perfiles tiene la cuenta?</label>
          <input type="number" name="svc_cupos" id="mc-svccupos" min="1" max="50" value="1" style="width:100%;padding:9px;border:1px solid var(--border);border-radius:9px;background:var(--surface);color:var(--text)">
          <div style="font-size:11px;color:var(--faint);margin-top:4px">Al aprobar la tienda, esta cuenta <b>completa</b> caerá en tu stock con estos cupos.</div>
        </div>
      </div>
      <div id="mc-stockrow" style="display:flex;gap:8px;margin-bottom:10px;flex-wrap:wrap">
        <div style="flex:1;min-width:0">
          <label style="display:block;font-size:12px;color:var(--muted);font-weight:600;margin-bottom:5px">Cantidad</label>
          <input type="number" min="1" max="100" step="1" name="cantidad" id="mc-cant" value="1" oninput="mcCalcGan(document.getElementById('mc-pvc').value)" style="width:100%;padding:9px;border:1px solid var(--border);border-radius:9px;background:var(--surface);color:var(--text)">
        </div>
        <div style="flex:1.4;min-width:0">
          <label style="display:block;font-size:12px;color:var(--muted);font-weight:600;margin-bottom:5px">¿Qué compras?</label>
          <select name="unidad" id="mc-unidad" onchange="mcCalcGan(document.getElementById('mc-pvc').value)" style="width:100%;padding:9px;border:1px solid var(--border);border-radius:9px;background:var(--surface);color:var(--text)">
            <option value="perfil">Perfil(es) sueltos</option>
            <option value="cuenta">Cuenta(s) completa(s)</option>
          </select>
        </div>
      </div>
      <div id="mc-cuentahint" style="font-size:11px;color:var(--muted);margin:-4px 0 12px">Cuenta completa = te llevas la cuenta con todos sus perfiles (puedes editar su clave, perfiles y PINes desde «Cuentas»).</div>
      <label style="display:block;font-size:12px;color:var(--muted);font-weight:600;margin-bottom:5px">¿A cuánto se lo vendes a tu cliente? (opcional · por perfil)</label>
      <input type="number" step="0.01" min="0" name="precio_venta_cliente" id="mc-pvc" oninput="mcCalcGan(this.value)" placeholder="Ej: 3.50" style="width:100%;margin-bottom:8px;padding:9px;border:1px solid var(--border);border-radius:9px;background:var(--surface);color:var(--text)">
      <div id="mcGanancia" style="display:none;font-size:12.5px;font-weight:650;margin:0 0 14px"></div>
      <div style="font-size:11.5px;color:var(--muted);margin:0 0 12px">Si <b>no</b> pones cliente, lo comprado queda en <b>tu stock</b> (pestaña «Perfiles») para que lo vendas cuando quieras.</div>
      <button class="btn primary" type="submit" style="width:100%"><i data-lucide="check"></i> Confirmar compra</button>
    </form>
    <!-- Descripción / Garantía en pestañas DEBAJO de Confirmar compra (no empujan el botón). -->
    <div id="mcTabs" style="display:none;margin-top:16px">
      <div style="display:flex;gap:8px;margin-bottom:8px">
        <button type="button" id="mcTabDesc" onclick="mcTab('desc')" class="btn ghost" style="flex:1;font-size:12.5px;padding:7px 10px">Descripción</button>
        <button type="button" id="mcTabGar" onclick="mcTab('gar')" class="btn ghost" style="flex:1;font-size:12.5px;padding:7px 10px">Garantía</button>
      </div>
      <div id="mcTabBody" style="white-space:pre-line;font-size:12.5px;color:var(--text);background:var(--surface-2);border:1px solid var(--border);border-radius:10px;padding:10px 12px"></div>
    </div>
  </div>
</div>

<script>
function abrirComprar(p){
  document.getElementById('mc-plat').value = p.id;
  document.getElementById('mcTitle').textContent = 'Comprar ' + p.nombre;
  document.getElementById('mcInfo').textContent = 'Se descontarán $' + p.precio.toFixed(2) + ' de tu saldo y recibirás los datos de acceso al instante.';
  window._mcCosto = Number(p.precio) || 0;
  // Pestañas Descripción / Garantía (debajo de Confirmar compra). Por defecto: Descripción.
  window._mcDesc = (p.desc || '').trim();
  window._mcGar  = (p.garantia || '').trim();
  var tabs = document.getElementById('mcTabs');
  var btnD = document.getElementById('mcTabDesc'), btnG = document.getElementById('mcTabGar');
  if (btnD) btnD.style.display = window._mcDesc !== '' ? '' : 'none';
  if (btnG) btnG.style.display = window._mcGar !== '' ? '' : 'none';
  if (tabs) {
    if (window._mcDesc === '' && window._mcGar === '') { tabs.style.display = 'none'; }
    else { tabs.style.display = 'block'; mcTab(window._mcDesc !== '' ? 'desc' : 'gar'); }
  }
  var pvc = document.getElementById('mc-pvc'); if (pvc) pvc.value = '';
  var cant = document.getElementById('mc-cant'); if (cant) cant.value = '1';
  var uni = document.getElementById('mc-unidad'); if (uni) uni.value = (p.unidad === 'cuenta' ? 'cuenta' : 'perfil');
  // Modo de entrega: invitacion (correo) / activacion (correo+clave) / perfil (stock).
  var modo = p.modo || 'perfil'; if (modo === 'email_manual') modo = 'invitacion';
  window._mcModo = modo;
  var esManual = (modo === 'invitacion' || modo === 'activacion');
  // COMPLETA por activación: plataforma manual + unidad 'cuenta' → correo+clave+cupos; al aprobar cae en stock.
  var esCompletaAct = esManual && (p.unidad === 'cuenta');
  var man = document.getElementById('mc-manual');
  var stockRow = document.getElementById('mc-stockrow');
  var cuentaHint = document.getElementById('mc-cuentahint');
  var claveWrap = document.getElementById('mc-svcclave-wrap');
  var cuposWrap = document.getElementById('mc-cupos-wrap');
  var hint = document.getElementById('mc-manual-hint');
  var svcEmail = document.getElementById('mc-svcemail'); if (svcEmail) svcEmail.value = '';
  var svcClave = document.getElementById('mc-svcclave'); if (svcClave) svcClave.value = '';
  var svcCupos = document.getElementById('mc-svccupos'); if (svcCupos) svcCupos.value = '1';
  if (man) man.style.display = esManual ? 'block' : 'none';
  if (stockRow) stockRow.style.display = esManual ? 'none' : 'flex';
  if (cuentaHint) cuentaHint.style.display = esManual ? 'none' : 'block';
  // La clave se pide en activación Y en completa (se activa a un correo con su contraseña).
  if (claveWrap) claveWrap.style.display = (modo === 'activacion' || esCompletaAct) ? 'block' : 'none';
  if (cuposWrap) cuposWrap.style.display = esCompletaAct ? 'block' : 'none';
  if (hint) hint.textContent = esCompletaAct
    ? 'Cuenta COMPLETA por activación: escribe el correo y la contraseña de la cuenta. La tienda la activa y luego cae en TU stock.'
    : ((modo === 'activacion')
      ? 'Escribe el correo y la contraseña de la cuenta donde el administrador hará la activación. Recibirás un aviso cuando esté lista.'
      : 'Escribe el correo donde el administrador enviará la invitación. Recibirás un aviso cuando esté lista.');
  mcCalcGan('');
  document.getElementById('mComprar').style.display = 'flex';
  if (window.lucide) lucide.createIcons();
}
function mcCalcGan(v){
  var g = document.getElementById('mcGanancia'); if (!g) return;
  var costo = window._mcCosto || 0;
  var cantEl = document.getElementById('mc-cant');
  var cant = Math.max(1, parseInt((cantEl && cantEl.value) || '1', 10) || 1);
  var venta = parseFloat(v);
  if (isNaN(venta) || v === '') {
    // Sin precio de venta: al menos mostramos cuánto se le descuenta en total.
    g.style.display = 'block'; g.style.color = 'var(--muted)';
    g.textContent = 'Se descontarán $' + (costo * cant).toFixed(2) + ' de tu saldo (' + cant + ' × $' + costo.toFixed(2) + ').';
    return;
  }
  var gan = (venta - costo) * cant;
  g.style.display = 'block';
  if (gan >= 0) { g.textContent = '💰 Ganancia: $' + gan.toFixed(2) + ' (por ' + cant + ')'; g.style.color = 'var(--good)'; }
  else { g.textContent = '⚠ Pierdes $' + Math.abs(gan).toFixed(2) + ' (le cobras menos de lo que te cuesta)'; g.style.color = 'var(--bad)'; }
}
function mcTab(which){
  var body = document.getElementById('mcTabBody');
  var btnD = document.getElementById('mcTabDesc'), btnG = document.getElementById('mcTabGar');
  if (!body) return;
  var active = which === 'gar' ? 'gar' : 'desc';
  if (active === 'gar') { body.textContent = window._mcGar || ''; }
  else { body.textContent = window._mcDesc || ''; }
  // Resalta la pestaña activa (usa la clase primary del tema).
  if (btnD) { btnD.classList.toggle('primary', active === 'desc'); btnD.classList.toggle('ghost', active !== 'desc'); }
  if (btnG) { btnG.classList.toggle('primary', active === 'gar'); btnG.classList.toggle('ghost', active !== 'gar'); }
}
function cerrarComprar(){ document.getElementById('mComprar').style.display = 'none'; }
document.getElementById('mComprar').addEventListener('click', e => { if (e.target.id === 'mComprar') cerrarComprar(); });
</script>
<?php stream_foot();
