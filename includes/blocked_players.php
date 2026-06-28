<?php

function blocked_players_db(): mysqli {
    global $mysqli;

    if (!isset($mysqli) || !($mysqli instanceof mysqli)) {
        require_once __DIR__ . '/db_connect.php';
    }

    return $mysqli;
}

function blocked_players_ensure_table(): void {
    static $initialized = false;

    if ($initialized) {
        return;
    }

    $mysqli = blocked_players_db();
    $mysqli->query("
        CREATE TABLE IF NOT EXISTS jugadores_bloqueados (
            id INT AUTO_INCREMENT PRIMARY KEY,
            player_id VARCHAR(100) NOT NULL,
            razon VARCHAR(255) NOT NULL DEFAULT '',
            creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_player_id (player_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $initialized = true;
}

function blocked_players_all(): array {
    blocked_players_ensure_table();
    $mysqli = blocked_players_db();
    $result = $mysqli->query('SELECT id, player_id, razon, creado_en FROM jugadores_bloqueados ORDER BY creado_en DESC');
    if (!($result instanceof mysqli_result)) {
        return [];
    }
    return $result->fetch_all(MYSQLI_ASSOC) ?: [];
}

function blocked_players_get_all_ids(): array {
    blocked_players_ensure_table();
    $mysqli = blocked_players_db();
    $result = $mysqli->query('SELECT player_id FROM jugadores_bloqueados');
    if (!($result instanceof mysqli_result)) {
        return [];
    }
    return array_column($result->fetch_all(MYSQLI_ASSOC) ?: [], 'player_id');
}

function blocked_players_is_blocked(string $playerId): bool {
    $playerId = trim($playerId);
    if ($playerId === '') {
        return false;
    }
    blocked_players_ensure_table();
    $mysqli = blocked_players_db();
    $stmt = $mysqli->prepare('SELECT id FROM jugadores_bloqueados WHERE player_id = ? LIMIT 1');
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('s', $playerId);
    $stmt->execute();
    $res = $stmt->get_result();
    $found = ($res instanceof mysqli_result) && $res->num_rows > 0;
    $stmt->close();
    return $found;
}

function blocked_players_add(string $playerId, string $reason = ''): bool {
    $playerId = trim($playerId);
    if ($playerId === '') {
        return false;
    }
    blocked_players_ensure_table();
    $mysqli = blocked_players_db();
    $stmt = $mysqli->prepare('INSERT IGNORE INTO jugadores_bloqueados (player_id, razon) VALUES (?, ?)');
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('ss', $playerId, $reason);
    $ok = $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    return $ok && $affected > 0;
}

function blocked_players_delete(int $id): bool {
    if ($id <= 0) {
        return false;
    }
    blocked_players_ensure_table();
    $mysqli = blocked_players_db();
    $stmt = $mysqli->prepare('DELETE FROM jugadores_bloqueados WHERE id = ? LIMIT 1');
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('i', $id);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}
