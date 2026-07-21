<?php
require_once __DIR__ . '/includes/tenant.php';
tenant_start_session();
$adminRole = trim((string) ($_SESSION['auth_user']['rol'] ?? ''));
if (!isset($_SESSION['auth_user']) || !in_array($adminRole, ['admin', 'root'], true)) {
    http_response_code(403);
    die('Acceso denegado');
}
require_once __DIR__ . '/includes/db_connect.php';
require_once __DIR__ . '/admin_misiones_premios.php'; // Include the file with the function

// Simulate awarding a prize
$userId = 1; // Example user ID
$prizeType = 'winpoints';
$reason = 'Completar tarea diaria';
$status = 'granted';

// Log the prize distribution
log_prize_distribution($mysqli, $userId, $prizeType, $reason, $status);

// Fetch and display the prize history for verification
$result = $mysqli->query('SELECT * FROM prize_history WHERE user_id = ' . $userId);
while ($row = $result->fetch_assoc()) {
    echo 'User ID: ' . $row['user_id'] . ', Prize Type: ' . $row['prize_type'] . ', Reason: ' . $row['reason'] . ', Status: ' . $row['reward_status'] . ', Date: ' . $row['created_at'] . '<br>';
}
?>