<?php
/**
 * api/revendedor/recargar.php — El REVENDEDOR compra una recarga de juego pagando con su SALDO,
 * a precio de revendedor (juego_paquetes.precio_revendedor). Descuenta el saldo (atómico) y crea el
 * pedido en estado 'pagado' atribuido al revendedor, con un cart_batch_id secreto. Devuelve
 * { order_id, batch_id } para que el frontend dispare el fulfillment con el MOTOR de la tienda
 * (api/pedidos.php?action=batch_fulfill_item), reusando el despacho al proveedor sin tocar pedidos.php.
 *
 * POST { game_id, package_id, user_identifier, player_fields_json?, quantity? } → JSON
 */
require_once __DIR__ . '/../../includes/tenant.php';
tenant_start_session();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../includes/db.php'; // $pdo
require_once __DIR__ . '/../../admin/_streaming_schema.php';
require_once __DIR__ . '/../wallet/_helpers.php';

function rr_err(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'message' => $msg]);
    exit;
}

$user = $_SESSION['auth_user'] ?? null;
$rol = is_array($user) ? strtolower(trim((string) ($user['rol'] ?? ''))) : '';
if (!is_array($user) || $rol !== 'revendedor') {
    rr_err('Solo revendedores.', 403);
}
$uid = (int) $user['id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    rr_err('Método no permitido.', 405);
}

stream_ensure_schema($pdo);
try { $pdo->exec("ALTER TABLE juego_paquetes ADD COLUMN precio_revendedor DECIMAL(12,2) NULL"); } catch (Throwable $e) {}

// ── REVERSO: si el despacho falló, devolver el saldo de un pedido PROPIO no entregado. Idempotente. ──
if (($_POST['action'] ?? '') === 'reverso') {
    $oid = (int) ($_POST['order_id'] ?? 0);
    if ($oid <= 0) { rr_err('Pedido inválido.'); }
    $o = $pdo->prepare("SELECT precio, cliente_usuario_id FROM pedidos WHERE id=? LIMIT 1");
    $o->execute([$oid]);
    $ord = $o->fetch(PDO::FETCH_ASSOC);
    if (!$ord || (int) $ord['cliente_usuario_id'] !== $uid) { rr_err('No autorizado.', 403); }
    // Solo si sigue 'pagado' (no entregado) — el claim atómico evita doble reverso.
    $claim = $pdo->prepare("UPDATE pedidos SET estado='cancelado' WHERE id=? AND estado='pagado' AND cliente_usuario_id=?");
    $claim->execute([$oid, $uid]);
    if ($claim->rowCount() === 1) {
        wallet_acreditar($pdo, $uid, (float) $ord['precio'], 'reverso_recarga', 'Reverso: recarga no entregada (pedido #' . $oid . ')');
    }
    echo json_encode(['ok' => true, 'saldo' => wallet_saldo($pdo, $uid)]);
    exit;
}

$gameId = (int) ($_POST['game_id'] ?? 0);
$packageId = (int) ($_POST['package_id'] ?? 0);
$userIdentifier = substr(trim((string) ($_POST['user_identifier'] ?? '')), 0, 150);
$playerFieldsJson = trim((string) ($_POST['player_fields_json'] ?? ''));
$qty = max(1, min(50, (int) ($_POST['quantity'] ?? 1)));

if ($gameId <= 0 || $packageId <= 0) {
    rr_err('Juego o paquete inválido.');
}
// El requisito del ID del jugador se valida MÁS ABAJO, cuando ya sabemos el proveedor/producto:
// las GIFT CARDS no llevan ID (igual que en la tienda). Ver el bloque tras cargar el paquete.

// Paquete + precio de revendedor (el SERVIDOR manda el precio — anti-tampering).
// categoria_api_discord (juego) y api_source_key (paquete) pueden no existir en instalaciones viejas.
$discordCol = '';
try {
    $hasCat = $pdo->query("SHOW COLUMNS FROM juegos LIKE 'categoria_api_discord'")->fetch();
    if ($hasCat) { $discordCol = ", j.categoria_api_discord"; }
} catch (Throwable $e) {}
$pk = $pdo->prepare("SELECT p.id, p.juego_id, p.nombre, p.cantidad, p.precio, p.precio_revendedor,
        p.monto_ff, p.paquete_api, p.api_provider, j.nombre AS juego_nombre$discordCol
    FROM juego_paquetes p JOIN juegos j ON j.id = p.juego_id
    WHERE p.id = ? AND p.juego_id = ? AND p.activo = 1 AND j.activo = 1");
$pk->execute([$packageId, $gameId]);
$pkg = $pk->fetch(PDO::FETCH_ASSOC);
if (!$pkg) {
    rr_err('Paquete no disponible.');
}

// ¿Este paquete NECESITA el ID del jugador? Las GIFT CARDS no (igual que la tienda: game.php oculta el
// paso del ID para gift cards). Se decide por proveedor/producto para no bloquear una gift card ni dejar
// pasar sin ID un juego que sí lo pide (evita debitar saldo por una recarga que el proveedor rechazará).
$rr_needsId = true;
$rr_prov = trim((string) ($pkg['api_provider'] ?? ''));
if (trim((string) ($pkg['monto_ff'] ?? '')) !== '') {
    $rr_needsId = true;                       // Free Fire por monto: siempre pide ID.
} elseif ($rr_prov === 'conec') {
    // CONEC: el catálogo dice requires_game_id por producto (las gift cards vienen en false).
    $rr_needsId = true;
    try {
        require_once __DIR__ . '/../../includes/conec_recargas.php';
        $rr_cp = function_exists('conec_api_product_by_id') ? conec_api_product_by_id($pdo, (int) ($pkg['paquete_api'] ?? 0)) : null;
        if (is_array($rr_cp)) { $rr_needsId = !empty($rr_cp['requires_game_id']); }
    } catch (Throwable $e) {}
}
if ($rr_needsId && $userIdentifier === '') {
    rr_err('Falta el ID del jugador.');
}
if ($pkg['precio_revendedor'] === null || (float) $pkg['precio_revendedor'] <= 0) {
    rr_err('Este paquete no tiene precio de revendedor. Pídeselo al administrador.');
}
$precioUnit = round((float) $pkg['precio_revendedor'], 2);
$total = round($precioUnit * $qty, 2);

// Proveedor efectivo (misma prioridad que la tienda): api_provider explícito → giftven (paquete_api) → free_fire (monto_ff).
$apiProvider = strtolower(trim((string) ($pkg['api_provider'] ?? '')));
if ($apiProvider === '') {
    if ((int) ($pkg['paquete_api'] ?? 0) > 0) { $apiProvider = 'giftven'; }
    elseif (trim((string) ($pkg['monto_ff'] ?? '')) !== '') { $apiProvider = 'free_fire'; }
}

// Campos Discord: si el juego despacha por Discord, arma el comando igual que la tienda
// (build_api_discord_order_insert_data): command_key = juego.categoria_api_discord, template del comando.
$discordFields = [];
if ($apiProvider === 'discord') {
    $commandKey = trim((string) ($pkg['categoria_api_discord'] ?? ''));
    $enabled = false;
    try {
        $cf = $pdo->prepare("SELECT valor FROM configuracion_general WHERE clave='api_discord' LIMIT 1");
        $cf->execute();
        $enabled = trim((string) ($cf->fetchColumn() ?: '0')) === '1';
    } catch (Throwable $e) {}
    if ($commandKey !== '' && $enabled && is_file(__DIR__ . '/../../includes/api_discord.php')) {
        require_once __DIR__ . '/../../includes/api_discord.php';
        $cmd = function_exists('api_discord_find_command') ? api_discord_find_command($commandKey) : null;
        if (is_array($cmd) && trim((string) ($cmd['kind'] ?? '')) === 'topup') {
            $discordFields = [
                'api_discord_command_key'      => $commandKey,
                'api_discord_command_template' => trim((string) ($cmd['template'] ?? '')),
                'api_discord_status'           => 'ready',
                'api_discord_attempts'         => 0,
                'api_discord_requires_review'  => 0,
            ];
        }
    }
}

$batchId = bin2hex(random_bytes(16));
$tenantSlug = function_exists('resolve_tenant_slug') ? resolve_tenant_slug() : '';

// Columnas existentes de pedidos (inserción dinámica segura, como pedidos_insert_order).
$existing = [];
try { foreach ($pdo->query("SHOW COLUMNS FROM pedidos") as $c) { $existing[$c['Field']] = true; } } catch (Throwable $e) {}

$data = [
    'tenant_slug'      => $tenantSlug,
    'juego_id'         => $gameId,
    'paquete_id'       => $packageId,
    'juego_nombre'     => (string) $pkg['juego_nombre'],
    'paquete_nombre'   => (string) $pkg['nombre'],
    'paquete_cantidad' => (string) ($pkg['cantidad'] ?? ''),
    'monto_ff'         => ($pkg['monto_ff'] !== null && $pkg['monto_ff'] !== '') ? $pkg['monto_ff'] : null,
    'paquete_api'      => ((int) ($pkg['paquete_api'] ?? 0)) ?: null,
    'api_provider'     => $apiProvider !== '' ? $apiProvider : null,
    'moneda'           => 'USD',
    'precio'           => $total,
    'user_identifier'  => $userIdentifier,
    'player_fields_json' => $playerFieldsJson !== '' ? $playerFieldsJson : null,
    'email'            => (string) ($user['email'] ?? ''),
    'cliente_usuario_id' => $uid,
    'cantidad_compra'  => $qty,
    'cantidad'         => is_numeric($pkg['cantidad']) ? (float) $pkg['cantidad'] : null,
    'metodo_pago'      => 'Saldo revendedor',
    'estado'           => 'pagado',
    'cart_batch_id'    => $batchId,
    'cart_batch_total' => 1,
    'cart_batch_index' => 0,
    'cart_batch_size'  => 1,
];
// Los campos Discord solo se insertan si el motor de la tienda tiene esas columnas.
if ($discordFields) { $data = array_merge($data, $discordFields); }

$pdo->beginTransaction();
try {
    // 1) Débito ATÓMICO del saldo (solo si alcanza).
    if (!wallet_debitar($pdo, $uid, $total, 'recarga_juego', 'Recarga ' . $pkg['juego_nombre'] . ' · ' . $pkg['nombre'] . ($qty > 1 ? " x$qty" : ''))) {
        $pdo->rollBack();
        rr_err('Saldo insuficiente. Recarga tu saldo.', 402);
    }

    // 2) Insertar el pedido PAGADO (solo columnas que existen).
    $cols = []; $ph = []; $vals = [];
    foreach ($data as $col => $val) {
        if (!isset($existing[$col])) { continue; }
        $cols[] = "`$col`"; $ph[] = '?'; $vals[] = $val;
    }
    if (!$cols) { throw new RuntimeException('Tabla pedidos incompatible.'); }
    $ins = $pdo->prepare("INSERT INTO pedidos (" . implode(',', $cols) . ") VALUES (" . implode(',', $ph) . ")");
    $ins->execute($vals);
    $orderId = (int) $pdo->lastInsertId();

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    error_log('recargar revendedor: ' . $e->getMessage());
    rr_err('No se pudo crear la recarga. Intenta de nuevo.', 500);
}

// Aviso al ADMIN de la compra de recarga → aparece en Notificaciones y le suena el tono en tiempo real.
// (El cliente notó que las compras de recargas no generaban notificación.) Nunca rompe la respuesta.
try {
    require_once __DIR__ . '/../_rev_avisos.php';
    if (function_exists('stream_notif_crear')) {
        $rn = '';
        try { $rn = (string) ($pdo->query("SELECT COALESCE(NULLIF(nombre,''),username,email) FROM usuarios WHERE id=" . (int) $uid)->fetchColumn() ?: ''); } catch (Throwable $e) {}
        stream_notif_crear($pdo, 0, 'recarga_juego',
            'Recarga comprada · ' . $pkg['juego_nombre'],
            ($rn ?: 'Un revendedor') . ' compró ' . $pkg['nombre'] . ($qty > 1 ? " x$qty" : '') . ' de ' . $pkg['juego_nombre'] . ' por $' . number_format((float) $total, 2) . '.',
            'recargas.php', (int) $uid, $orderId);
    }
} catch (Throwable $e) {}

// El frontend ahora llama a api/pedidos.php?action=batch_fulfill_item con estos datos para
// disparar el despacho al proveedor (reusa el motor de la tienda).
echo json_encode([
    'ok'         => true,
    'order_id'   => $orderId,
    'batch_id'   => $batchId,
    'provider'   => $apiProvider,
    'total'      => $total,
    'saldo'      => wallet_saldo($pdo, $uid),
    'fulfill_url' => function_exists('app_path') ? app_path('/api/pedidos.php') : '/api/pedidos.php',
]);
