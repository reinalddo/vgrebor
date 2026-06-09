<?php
require_once __DIR__ . '/../includes/tenant.php';
tenant_start_session();
header('Content-Type: application/json');

require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/win_points.php';
require_once __DIR__ . '/../includes/roulette.php';

function roulette_api_error(string $message, int $status = 400): void {
    http_response_code($status);
    echo json_encode(['ok' => false, 'message' => $message]);
    exit;
}

function roulette_api_ok(array $payload = []): void {
    echo json_encode(array_merge(['ok' => true], $payload));
    exit;
}

function roulette_api_auth_user(): array {
    $user = $_SESSION['auth_user'] ?? null;
    if (!is_array($user) || empty($user['id'])) {
        roulette_api_error('Debes iniciar sesión para continuar.', 401);
    }
    return $user;
}

$action = trim((string) ($_POST['action'] ?? $_GET['action'] ?? 'status'));

if ($action === 'status') {
    $user   = $_SESSION['auth_user'] ?? null;
    $userId = is_array($user) && !empty($user['id']) ? (int) $user['id'] : 0;
    roulette_api_ok([
        'payload' => roulette_public_payload($mysqli, $userId),
    ]);
}

$authUser   = roulette_api_auth_user();
$authUserId = (int) ($authUser['id'] ?? 0);

if ($action === 'spin') {
    $result = roulette_execute_spin($mysqli, $authUserId);
    if (empty($result['ok'])) {
        roulette_api_error((string) ($result['message'] ?? 'No se pudo girar la ruleta.'), 400);
    }
    roulette_api_ok([
        'result'  => $result,
        'payload' => roulette_public_payload($mysqli, $authUserId),
    ]);
}

roulette_api_error('Acción no soportada.', 422);
?>
