<?php
/**
 * api/revendedor/api.php — API para que el REVENDEDOR venda desde su PROPIA página.
 * Autenticación por API KEY (no sesión). El revendedor obtiene su key en su panel («Mi API»).
 *
 * Uso:  POST/GET  api/revendedor/api.php   con  api_key=...  &  action=...
 *   action=saldo               → { ok, saldo }
 *   action=catalogo            → { ok, streaming:[...], recargas:[...] }  (sus precios de revendedor)
 *   action=comprar_streaming   → { plataforma_id }  compra un perfil del stock; devuelve credenciales
 *   action=comprar_recarga     → { game_id, package_id, user_identifier, quantity? }  recarga de juego
 * Todo se paga con el SALDO del revendedor (atómico). Respuestas JSON.
 */
require_once __DIR__ . '/../../includes/tenant.php';
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: X-Api-Key, Content-Type');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/../../includes/db.php'; // $pdo
require_once __DIR__ . '/../../admin/_streaming_schema.php';
require_once __DIR__ . '/../wallet/_helpers.php';

function api_out($data, int $code = 200): void { http_response_code($code); echo json_encode($data, JSON_UNESCAPED_UNICODE); exit; }
function api_err(string $msg, int $code = 400): void { api_out(['ok' => false, 'error' => $msg], $code); }

stream_ensure_schema($pdo);

// ── Autenticación por API key ──────────────────────────────────────────────
$key = trim((string) ($_POST['api_key'] ?? $_GET['api_key'] ?? $_SERVER['HTTP_X_API_KEY'] ?? ''));
if ($key === '' || strlen($key) < 20) { api_err('API key faltante o inválida.', 401); }
$st = $pdo->prepare("SELECT id, nombre, email, saldo, rol FROM usuarios WHERE api_key = ? AND rol = 'revendedor' LIMIT 1");
$st->execute([$key]);
$rev = $st->fetch(PDO::FETCH_ASSOC);
if (!$rev) { api_err('API key no autorizada.', 401); }
$uid = (int) $rev['id'];

$action = (string) ($_POST['action'] ?? $_GET['action'] ?? '');

// ── SALDO ──────────────────────────────────────────────────────────────────
if ($action === 'saldo') {
    api_out(['ok' => true, 'saldo' => round(wallet_saldo($pdo, $uid), 2)]);
}

// ── CATÁLOGO (precios de revendedor) ────────────────────────────────────────
if ($action === 'catalogo') {
    // Streaming del stock de la tienda (owner 0), en tienda, con precio de revendedor y stock libre.
    $streaming = [];
    try {
        $rows = $pdo->query("SELECT pl.id, pl.nombre, pl.precio_distribuidor,
                (SELECT COUNT(*) FROM streaming_perfiles p JOIN streaming_cuentas c ON c.id=p.cuenta_id
                  WHERE c.owner_id=0 AND c.plataforma_id=pl.id AND p.estado='libre' AND c.estado='activa') AS stock
            FROM streaming_plataformas pl
            WHERE pl.owner_id=0 AND pl.activo=1 AND pl.en_tienda=1 AND pl.precio_distribuidor>0
            ORDER BY pl.nombre")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $streaming[] = ['plataforma_id' => (int) $r['id'], 'nombre' => $r['nombre'],
                            'precio' => round((float) $r['precio_distribuidor'], 2), 'stock' => (int) $r['stock']];
        }
    } catch (Throwable $e) {}
    // Recargas de juegos con precio de revendedor.
    $recargas = [];
    try {
        $rows = $pdo->query("SELECT p.id AS package_id, p.juego_id AS game_id, p.nombre, p.cantidad, p.precio_revendedor,
                j.nombre AS juego
            FROM juego_paquetes p JOIN juegos j ON j.id=p.juego_id
            WHERE p.activo=1 AND j.activo=1 AND p.precio_revendedor IS NOT NULL AND p.precio_revendedor>0
            ORDER BY j.nombre, p.id")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $recargas[] = ['game_id' => (int) $r['game_id'], 'package_id' => (int) $r['package_id'],
                           'juego' => $r['juego'], 'paquete' => $r['nombre'], 'cantidad' => $r['cantidad'],
                           'precio' => round((float) $r['precio_revendedor'], 2)];
        }
    } catch (Throwable $e) {}
    api_out(['ok' => true, 'saldo' => round(wallet_saldo($pdo, $uid), 2), 'streaming' => $streaming, 'recargas' => $recargas]);
}

// ── COMPRAR STREAMING (perfil del stock de la tienda, pago con saldo) ────────
if ($action === 'comprar_streaming') {
    $platId = (int) ($_POST['plataforma_id'] ?? $_GET['plataforma_id'] ?? 0);
    if ($platId <= 0) { api_err('Falta plataforma_id.'); }
    $pl = $pdo->prepare("SELECT nombre, precio_distribuidor, COALESCE(dias_default,30) AS dias FROM streaming_plataformas WHERE id=? AND owner_id=0 AND activo=1 AND en_tienda=1");
    $pl->execute([$platId]);
    $plat = $pl->fetch(PDO::FETCH_ASSOC);
    if (!$plat) { api_err('Plataforma no disponible.'); }
    $precio = round((float) ($plat['precio_distribuidor'] ?? 0), 2);
    if ($precio <= 0) { api_err('Esa plataforma no tiene precio de revendedor.'); }

    $pdo->beginTransaction();
    try {
        $sel = $pdo->prepare("SELECT p.id AS pid, p.etiqueta, p.pin AS ppin, c.id AS cuenta_id, c.correo, c.clave
                              FROM streaming_perfiles p JOIN streaming_cuentas c ON c.id=p.cuenta_id
                              WHERE c.owner_id=0 AND c.plataforma_id=? AND p.estado='libre' AND c.estado='activa'
                              ORDER BY (c.vencimiento IS NULL), c.vencimiento ASC LIMIT 1 FOR UPDATE");
        $sel->execute([$platId]);
        $p = $sel->fetch(PDO::FETCH_ASSOC);
        if (!$p) { $pdo->rollBack(); api_err('Sin stock disponible.', 409); }
        if (!wallet_debitar($pdo, $uid, $precio, 'compra_streaming_api', 'Streaming API · ' . $plat['nombre'])) {
            $pdo->rollBack(); api_err('Saldo insuficiente.', 402);
        }
        $dias = (int) ($plat['dias'] ?: 30);
        $venc = date('Y-m-d', strtotime("+$dias days"));
        $ins = $pdo->prepare("INSERT INTO streaming_ventas (owner_id,plataforma,tipo,cuenta_id,revendedor_id,correo,clave,perfil,pin,precio,fecha_inicio,fecha_vencimiento,estado,entregada)
                              VALUES (?,?, 'perfil', ?, ?, ?, ?, ?, ?, ?, CURDATE(), ?, 'activa', 1)");
        $ins->execute([$uid, $plat['nombre'], $p['cuenta_id'], $uid, $p['correo'], $p['clave'], $p['etiqueta'], $p['ppin'], $precio, $venc]);
        $vid = (int) $pdo->lastInsertId();
        $up = $pdo->prepare("UPDATE streaming_perfiles SET estado='vendido', venta_id=? WHERE id=? AND estado='libre'");
        $up->execute([$vid, $p['pid']]);
        if ($up->rowCount() < 1) { $pdo->rollBack(); api_err('Sin stock disponible.', 409); }
        $pdo->commit();
        // Aviso por correo al revendedor con los datos de la cuenta que acaba de comprar. Va DESPUÉS
        // del commit (nunca SMTP con una transacción abierta) y en try/catch: si el correo falla, la
        // compra ya está hecha y las credenciales igual van en esta misma respuesta JSON.
        try {
            require_once __DIR__ . '/../../includes/stream_mailer.php';
            stream_email_notificar_venta($pdo, $vid, 'entrega');
        } catch (Throwable $eMail) { error_log('api revendedor correo compra: ' . $eMail->getMessage()); }
        api_out(['ok' => true, 'venta_id' => $vid, 'plataforma' => $plat['nombre'],
                 'correo' => $p['correo'], 'clave' => $p['clave'], 'perfil' => $p['etiqueta'], 'pin' => $p['ppin'],
                 'vencimiento' => $venc, 'total' => $precio, 'saldo' => round(wallet_saldo($pdo, $uid), 2)]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        error_log('api revendedor comprar_streaming: ' . $e->getMessage());
        api_err('No se pudo completar la compra.', 500);
    }
}

// ── COMPRAR RECARGA (juego, pago con saldo; despacho por el motor de la tienda) ──
if ($action === 'comprar_recarga') {
    $gameId = (int) ($_POST['game_id'] ?? $_GET['game_id'] ?? 0);
    $packageId = (int) ($_POST['package_id'] ?? $_GET['package_id'] ?? 0);
    $userIdentifier = substr(trim((string) ($_POST['user_identifier'] ?? $_GET['user_identifier'] ?? '')), 0, 150);
    $qty = max(1, min(50, (int) ($_POST['quantity'] ?? $_GET['quantity'] ?? 1)));
    if ($gameId <= 0 || $packageId <= 0 || $userIdentifier === '') { api_err('Faltan datos (game_id, package_id, user_identifier).'); }

    $pk = $pdo->prepare("SELECT p.nombre, p.precio_revendedor, p.paquete_api, p.api_provider, p.monto_ff, j.nombre AS juego
        FROM juego_paquetes p JOIN juegos j ON j.id=p.juego_id
        WHERE p.id=? AND p.juego_id=? AND p.activo=1 AND j.activo=1");
    $pk->execute([$packageId, $gameId]);
    $pkg = $pk->fetch(PDO::FETCH_ASSOC);
    if (!$pkg) { api_err('Paquete no disponible.'); }
    if ($pkg['precio_revendedor'] === null || (float) $pkg['precio_revendedor'] <= 0) { api_err('Paquete sin precio de revendedor.'); }
    $precioUnit = round((float) $pkg['precio_revendedor'], 2);
    $total = round($precioUnit * $qty, 2);

    $provider = strtolower(trim((string) ($pkg['api_provider'] ?? '')));
    if ($provider === '') {
        if ((int) ($pkg['paquete_api'] ?? 0) > 0) { $provider = 'giftven'; }
        elseif (trim((string) ($pkg['monto_ff'] ?? '')) !== '') { $provider = 'free_fire'; }
    }
    $batchId = bin2hex(random_bytes(16));
    $existing = [];
    try { foreach ($pdo->query("SHOW COLUMNS FROM pedidos") as $c) { $existing[$c['Field']] = true; } } catch (Throwable $e) {}
    $data = [
        'tenant_slug' => function_exists('resolve_tenant_slug') ? resolve_tenant_slug() : '',
        'juego_id' => $gameId, 'paquete_id' => $packageId, 'juego_nombre' => $pkg['juego'], 'paquete_nombre' => $pkg['nombre'],
        'monto_ff' => ($pkg['monto_ff'] !== null && $pkg['monto_ff'] !== '') ? $pkg['monto_ff'] : null,
        'paquete_api' => ((int) ($pkg['paquete_api'] ?? 0)) ?: null, 'api_provider' => $provider !== '' ? $provider : null,
        'moneda' => 'USD', 'precio' => $total, 'user_identifier' => $userIdentifier, 'email' => (string) ($rev['email'] ?? ''),
        'cliente_usuario_id' => $uid, 'cantidad_compra' => $qty, 'metodo_pago' => 'Saldo revendedor (API)', 'estado' => 'pagado',
        'cart_batch_id' => $batchId, 'cart_batch_total' => 1, 'cart_batch_index' => 0, 'cart_batch_size' => 1,
    ];
    $pdo->beginTransaction();
    try {
        if (!wallet_debitar($pdo, $uid, $total, 'recarga_juego_api', 'Recarga API ' . $pkg['juego'] . ' · ' . $pkg['nombre'])) {
            $pdo->rollBack(); api_err('Saldo insuficiente.', 402);
        }
        $cols = []; $ph = []; $vals = [];
        foreach ($data as $col => $val) { if (isset($existing[$col])) { $cols[] = "`$col`"; $ph[] = '?'; $vals[] = $val; } }
        $pdo->prepare("INSERT INTO pedidos (" . implode(',', $cols) . ") VALUES (" . implode(',', $ph) . ")")->execute($vals);
        $orderId = (int) $pdo->lastInsertId();
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        error_log('api revendedor comprar_recarga: ' . $e->getMessage());
        api_err('No se pudo crear la recarga.', 500);
    }
    // Disparar el despacho con el motor de la tienda (mismo mecanismo que el panel).
    $estado = 'pagado'; $codigo = ''; $mensaje = 'Pedido creado.'; $fulfillOk = null;
    try {
        $fulfillUrl = (function_exists('app_url') ? app_url('/api/pedidos.php') : '/api/pedidos.php') . '?action=batch_fulfill_item';
        $ch = curl_init($fulfillUrl);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 40,
            CURLOPT_POSTFIELDS => http_build_query(['action' => 'batch_fulfill_item', 'order_id' => $orderId, 'batch_id' => $batchId])]);
        $resp = curl_exec($ch); curl_close($ch);
        $j = json_decode((string) $resp, true);
        if (is_array($j)) { $estado = (string) ($j['estado'] ?? $estado); $codigo = (string) ($j['provider_code'] ?? ''); $mensaje = (string) ($j['message'] ?? $mensaje); $fulfillOk = !empty($j['ok']); }
    } catch (Throwable $e) {}

    // REVERSO DE SALDO: si el proveedor RECHAZÓ (ok=false y no quedó enviado/procesándose), devolver el
    // saldo y cancelar el pedido, para que el revendedor nunca pague sin recibir. (Los estados
    // 'accepted'/'processing'/'enviado' NO se revierten — son entregas válidas o en curso.)
    if ($fulfillOk === false && $estado !== 'enviado') {
        try { $pdo->prepare("UPDATE pedidos SET estado='cancelado' WHERE id=? AND estado='pagado'")->execute([$orderId]); } catch (Throwable $e) {}
        wallet_acreditar($pdo, $uid, $total, 'reverso_recarga_api', 'Reverso: recarga no entregada (pedido #' . $orderId . ')');
        api_out(['ok' => false, 'order_id' => $orderId, 'error' => 'La recarga no se pudo entregar; se te devolvió el saldo.',
                 'mensaje' => $mensaje, 'saldo' => round(wallet_saldo($pdo, $uid), 2)], 409);
    }
    api_out(['ok' => true, 'order_id' => $orderId, 'estado' => $estado, 'codigo' => $codigo, 'mensaje' => $mensaje,
             'total' => $total, 'saldo' => round(wallet_saldo($pdo, $uid), 2)]);
}

api_err('Acción no reconocida. Usa: saldo, catalogo, comprar_streaming, comprar_recarga.', 404);
