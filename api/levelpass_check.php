<?php
// api/levelpass_check.php — Consulta de disponibilidad de Pase de Nivel.
// Proxy hacia el validador externo (HTTP plano). Solo lectura: no escribe
// pedidos ni toca checkout. El frontend SOLO debe llamar este endpoint
// después de verificar exitosamente el nombre del jugador (game.php).
require_once __DIR__ . '/../includes/tenant.php';
tenant_start_session();
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/store_config.php';
require_once __DIR__ . '/../includes/levelpass_api.php';

function levelpass_check_json(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$gameId = (int) ($_POST['game_id'] ?? $_GET['game_id'] ?? 0);
$playerId = trim((string) ($_POST['player_id'] ?? $_GET['player_id'] ?? ''));

if ($gameId <= 0) {
    levelpass_check_json(['ok' => false, 'message' => 'Juego inválido.'], 422);
}
if (!preg_match('/^[0-9]{4,20}$/', $playerId)) {
    levelpass_check_json(['ok' => false, 'message' => 'ID de jugador inválido.'], 422);
}
if (!levelpass_is_configured()) {
    levelpass_check_json(['ok' => true, 'applicable' => false, 'disabled' => true]);
}

@set_time_limit(60);

$debugKey = trim((string) ($_POST['debug_key'] ?? $_GET['debug_key'] ?? ''));
$debug = $debugKey !== '' && hash_equals(levelpass_api_token(), $debugKey);

$result = levelpass_check($mysqli, $gameId, $playerId, $debug);

levelpass_check_json($result);
