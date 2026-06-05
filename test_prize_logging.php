<?php
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