<?php
require_once __DIR__ . '/../includes/tenant.php';
tenant_start_session();
header('Content-Type: application/json');

require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/win_points.php';

function daily_missions_api_error(string $message, int $status = 400): void {
    http_response_code($status);
    echo json_encode(['ok' => false, 'message' => $message]);
    exit;
}

function daily_missions_api_ok(array $payload = []): void {
    echo json_encode(array_merge(['ok' => true], $payload));
    exit;
}

function daily_missions_api_auth_user(): array {
    $user = $_SESSION['auth_user'] ?? null;
    if (!is_array($user) || empty($user['id'])) {
        daily_missions_api_error('Debes iniciar sesión para continuar.', 401);
    }

    return $user;
}

$action = trim((string) ($_POST['action'] ?? $_GET['action'] ?? 'status'));
$authUser = daily_missions_api_auth_user();
$authUserId = (int) ($authUser['id'] ?? 0);

if ($action === 'status') {
    daily_missions_api_ok([
        'payload' => daily_missions_public_payload($mysqli, $authUserId, 80),
    ]);
}

if (!daily_missions_enabled()) {
    daily_missions_api_error('El sistema de misiones diarias está desactivado.', 409);
}

if ($action === 'complete_task') {
    $missionKey = trim((string) ($_POST['mission_key'] ?? $_GET['mission_key'] ?? ''));
    if ($missionKey === '') {
        daily_missions_api_error('Debes indicar una misión válida.', 422);
    }

    $result = daily_missions_record_task_completion($mysqli, $authUserId, $missionKey, [
        'source' => 'api_daily_missions',
    ]);
    if (empty($result['ok'])) {
        daily_missions_api_error((string) ($result['message'] ?? 'No se pudo completar la misión.'), 400);
    }

    daily_missions_api_ok([
        'result' => $result,
        'payload' => daily_missions_public_payload($mysqli, $authUserId, 80),
    ]);
}

if ($action === 'open_chest') {
    $result = daily_missions_open_chest($mysqli, $authUserId, [
        'source' => 'api_daily_missions',
    ]);
    if (empty($result['ok'])) {
        daily_missions_api_error((string) ($result['message'] ?? 'No se pudo abrir el cofre.'), 400);
    }

    daily_missions_api_ok([
        'result' => $result,
        'payload' => daily_missions_public_payload($mysqli, $authUserId, 80),
    ]);
}

daily_missions_api_error('Acción no soportada.', 422);
?>
