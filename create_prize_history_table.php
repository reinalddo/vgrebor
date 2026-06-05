<?php
require_once __DIR__ . '/includes/db_connect.php';

$sql = "CREATE TABLE IF NOT EXISTS prize_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    prize_type VARCHAR(50) NOT NULL,
    reason VARCHAR(255) NOT NULL,
    reward_status VARCHAR(50) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES usuarios(id) ON DELETE CASCADE
)";

if ($mysqli->query($sql) === TRUE) {
    echo "Table 'prize_history' created successfully.";
} else {
    echo "Error creating table: " . $mysqli->error;
}

$mysqli->close();
?>