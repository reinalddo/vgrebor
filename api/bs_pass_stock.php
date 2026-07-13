<?php
// api/bs_pass_stock.php — Consulta de stock de pases BLOOD STRIKE PASS.
// Proxy hacia el validador externo (HTTP plano, no accesible directo desde
// el navegador en HTTPS). Solo lectura: no escribe pedidos ni toca checkout.
require_once __DIR__ . '/../includes/tenant.php';
tenant_start_session();
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/store_config.php';
require_once __DIR__ . '/../includes/recargas_api.php';
require_once __DIR__ . '/../includes/bs_pass_stock.php';

function bs_pass_stock_json(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$gameId = (int) ($_POST['game_id'] ?? $_GET['game_id'] ?? 0);
$playerId = trim((string) ($_POST['player_id'] ?? $_GET['player_id'] ?? ''));

if ($gameId <= 0) {
    bs_pass_stock_json(['ok' => false, 'message' => 'Juego inválido.'], 422);
}
if (!preg_match('/^[0-9]{4,20}$/', $playerId)) {
    bs_pass_stock_json(['ok' => false, 'message' => 'ID de jugador inválido.'], 422);
}
if (!bs_pass_stock_is_configured()) {
    bs_pass_stock_json(['ok' => true, 'applicable' => false, 'disabled' => true]);
}

// El validador externo tarda 20-35s; ampliar el límite solo para esta petición.
@set_time_limit(120);

$result = bs_pass_stock_check($mysqli, $gameId, $playerId);

bs_pass_stock_json($result);
