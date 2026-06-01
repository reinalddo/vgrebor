<?php
require_once __DIR__ . '/../includes/tenant.php';
tenant_start_session();
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/player_verification.php';

function verify_player_json(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function verify_player_normalize_package_provider(array $game, array $package): string {
    $provider = strtolower(trim((string) ($package['api_provider'] ?? '')));
    if ($provider !== '') {
        return $provider;
    }

    if ((int) ($package['paquete_api'] ?? 0) > 0) {
        return 'giftven';
    }

    if (trim((string) ($package['monto_ff'] ?? '')) !== '') {
        return 'free_fire';
    }

    if (trim((string) ($game['categoria_api_discord'] ?? '')) !== '') {
        return 'discord';
    }

    return '';
}

$gameId = (int) ($_POST['game_id'] ?? $_GET['game_id'] ?? 0);
if ($gameId <= 0) {
    verify_player_json(['ok' => false, 'message' => 'Juego inválido.'], 422);
}

$stmt = $mysqli->prepare('SELECT * FROM juegos WHERE id = ? LIMIT 1');
if (!$stmt) {
    verify_player_json(['ok' => false, 'message' => 'No se pudo preparar la verificación.'], 500);
}

$stmt->bind_param('i', $gameId);
$stmt->execute();
$result = $stmt->get_result();
$game = $result ? $result->fetch_assoc() : null;
$stmt->close();

if (!$game) {
    verify_player_json(['ok' => false, 'message' => 'Juego no encontrado.'], 404);
}

$packageId = (int) ($_POST['package_id'] ?? $_GET['package_id'] ?? 0);
if ($packageId > 0) {
    $packageStmt = $mysqli->prepare('SELECT id, paquete_api, monto_ff, api_provider FROM juego_paquetes WHERE id = ? AND juego_id = ? LIMIT 1');
    if (!$packageStmt) {
        verify_player_json(['ok' => false, 'message' => 'No se pudo preparar la verificación del paquete.'], 500);
    }

    $packageStmt->bind_param('ii', $packageId, $gameId);
    $packageStmt->execute();
    $packageResult = $packageStmt->get_result();
    $package = $packageResult ? $packageResult->fetch_assoc() : null;
    $packageStmt->close();

    if ($package) {
        $packageProvider = verify_player_normalize_package_provider($game, $package);
        if ($packageProvider === 'discord') {
            verify_player_json([
                'ok' => false,
                'status' => 'unsupported',
                'message' => 'La verificación automática no aplica para paquetes configurados con api_discord.',
            ], 422);
        }
    }
}

$userIdentifier = trim((string) ($_POST['user_identifier'] ?? $_GET['user_identifier'] ?? ''));
$playerFieldsRaw = (string) ($_POST['player_fields_json'] ?? $_GET['player_fields_json'] ?? '');
$playerFields = [];
if ($playerFieldsRaw !== '') {
    $decoded = json_decode($playerFieldsRaw, true);
    if (is_array($decoded)) {
        $playerFields = $decoded;
    }
}

$verification = player_verification_verify($game, $userIdentifier, $playerFields);
$httpStatus = (int) ($verification['http_status'] ?? 200);
unset($verification['http_status']);

verify_player_json($verification, $httpStatus);