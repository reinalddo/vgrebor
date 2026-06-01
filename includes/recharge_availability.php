<?php

function recharge_availability_ensure_columns(mysqli $mysqli): void {
    $gameResult = $mysqli->query("SHOW COLUMNS FROM juegos LIKE 'activo'");
    if (!($gameResult instanceof mysqli_result) || $gameResult->num_rows === 0) {
        $mysqli->query("ALTER TABLE juegos ADD COLUMN activo TINYINT(1) DEFAULT 1 NULL AFTER api_free_fire");
    }

    $packageResult = $mysqli->query("SHOW COLUMNS FROM juego_paquetes LIKE 'activo'");
    if (!($packageResult instanceof mysqli_result) || $packageResult->num_rows === 0) {
        $mysqli->query("ALTER TABLE juego_paquetes ADD COLUMN activo TINYINT(1) DEFAULT 1 NULL AFTER imagen_icono");
    }
}

function recharge_availability_normalized_message(string $message): string {
    $normalized = trim(strip_tags($message));
    $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;
    return function_exists('mb_strtolower')
        ? mb_strtolower($normalized, 'UTF-8')
        : strtolower($normalized);
}

function recharge_availability_message_indicates_inventory_shortage(string $message): bool {
    $normalized = recharge_availability_normalized_message($message);
    if ($normalized === '') {
        return false;
    }

    $patterns = [
        '/\bsin\s+(saldo|stock|inventario|cupos?|recargas?)\b/u',
        '/\b(no\s+hay|sin)\s+suficientes?\s+(puntos?|saldo|stock|recargas?|cupos?)\b/u',
        '/\b(saldo|stock|inventario|cupos?|puntos?)\s+insuficientes?\b/u',
        '/\brecargas?\s+no\s+disponibles?\b/u',
        '/\bagotad[oa]s?\b/u',
        '/\bout\s+of\s+stock\b/u',
        '/\binsufficient\s+(balance|stock|inventory|points)\b/u',
        '/\bnot\s+enough\s+(balance|stock|inventory|points)\b/u',
        '/\btemporarily\s+unavailable\b/u',
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $normalized) === 1) {
            return true;
        }
    }

    return false;
}

function recharge_availability_is_game_active(mysqli $mysqli, int $gameId): bool {
    if ($gameId <= 0) {
        return false;
    }

    recharge_availability_ensure_columns($mysqli);
    $stmt = $mysqli->prepare('SELECT COALESCE(activo, 1) AS activo FROM juegos WHERE id = ? LIMIT 1');
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('i', $gameId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    return $row ? (int) ($row['activo'] ?? 1) === 1 : false;
}

function recharge_availability_is_package_active(mysqli $mysqli, int $packageId, int $gameId): bool {
    if ($packageId <= 0 || $gameId <= 0) {
        return false;
    }

    recharge_availability_ensure_columns($mysqli);
    $stmt = $mysqli->prepare('SELECT COALESCE(activo, 1) AS activo FROM juego_paquetes WHERE id = ? AND juego_id = ? LIMIT 1');
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('ii', $packageId, $gameId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    return $row ? (int) ($row['activo'] ?? 1) === 1 : false;
}

function recharge_availability_set_game_active(mysqli $mysqli, int $gameId, bool $active): bool {
    if ($gameId <= 0) {
        return false;
    }

    recharge_availability_ensure_columns($mysqli);
    $value = $active ? 1 : 0;
    $stmt = $mysqli->prepare('UPDATE juegos SET activo = ? WHERE id = ?');
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('ii', $value, $gameId);
    $ok = $stmt->execute();
    $stmt->close();

    return $ok;
}

function recharge_availability_set_package_active(mysqli $mysqli, int $packageId, int $gameId, bool $active, bool $activateGameOnEnable = true): bool {
    if ($packageId <= 0 || $gameId <= 0) {
        return false;
    }

    recharge_availability_ensure_columns($mysqli);
    $value = $active ? 1 : 0;
    $stmt = $mysqli->prepare('UPDATE juego_paquetes SET activo = ? WHERE id = ? AND juego_id = ?');
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('iii', $value, $packageId, $gameId);
    $ok = $stmt->execute();
    $stmt->close();

    if ($ok && $active && $activateGameOnEnable) {
        recharge_availability_set_game_active($mysqli, $gameId, true);
    }

    return $ok;
}

function recharge_availability_count_active_packages(mysqli $mysqli, int $gameId): int {
    if ($gameId <= 0) {
        return 0;
    }

    recharge_availability_ensure_columns($mysqli);
    $stmt = $mysqli->prepare('SELECT COUNT(*) AS total FROM juego_paquetes WHERE juego_id = ? AND COALESCE(activo, 1) = 1');
    if (!$stmt) {
        return 0;
    }

    $stmt->bind_param('i', $gameId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    return max(0, (int) ($row['total'] ?? 0));
}

function recharge_availability_lock_package_for_inventory_shortage(mysqli $mysqli, int $gameId, int $packageId): array {
    $result = [
        'game_updated' => false,
        'game_deactivated' => false,
        'game_active' => false,
        'packages_updated' => 0,
        'package_updated' => false,
        'active_packages_remaining' => 0,
    ];

    if ($gameId <= 0 || $packageId <= 0) {
        return $result;
    }

    recharge_availability_ensure_columns($mysqli);

    $packageStmt = $mysqli->prepare('UPDATE juego_paquetes SET activo = 0 WHERE id = ? AND juego_id = ? AND COALESCE(activo, 1) = 1');
    if ($packageStmt) {
        $packageStmt->bind_param('ii', $packageId, $gameId);
        if ($packageStmt->execute()) {
            $result['packages_updated'] = max(0, (int) $packageStmt->affected_rows);
            $result['package_updated'] = $result['packages_updated'] > 0;
        }
        $packageStmt->close();
    }

    $result['active_packages_remaining'] = recharge_availability_count_active_packages($mysqli, $gameId);
    $result['game_active'] = recharge_availability_is_game_active($mysqli, $gameId);

    // NOTE: Do not deactivate the whole game automatically when packages
    // are locked due to inventory shortage. Only packages are marked inactive.
    // This prevents games (p.ej. Free Fire) from being hidden/disabled
    // automatically while staff reviews or replenishes stock.

    return $result;
}

function recharge_availability_lock_game_and_packages(mysqli $mysqli, int $gameId): array {
    if ($gameId <= 0) {
        return [
            'game_updated' => false,
            'packages_updated' => 0,
        ];
    }

    recharge_availability_ensure_columns($mysqli);

    $gameStmt = $mysqli->prepare('UPDATE juegos SET activo = 0 WHERE id = ?');
    $gameUpdated = false;
    if ($gameStmt) {
        $gameStmt->bind_param('i', $gameId);
        $gameUpdated = $gameStmt->execute();
        $gameStmt->close();
    }

    $packageStmt = $mysqli->prepare('UPDATE juego_paquetes SET activo = 0 WHERE juego_id = ?');
    $packagesUpdated = 0;
    if ($packageStmt) {
        $packageStmt->bind_param('i', $gameId);
        if ($packageStmt->execute()) {
            $packagesUpdated = max(0, (int) $packageStmt->affected_rows);
        }
        $packageStmt->close();
    }

    return [
        'game_updated' => $gameUpdated,
        'packages_updated' => $packagesUpdated,
    ];
}