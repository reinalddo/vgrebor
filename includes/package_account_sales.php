<?php

function package_account_sales_parent_table_exists(mysqli $mysqli): bool {
    $result = $mysqli->query("SHOW TABLES LIKE 'juego_paquetes'");
    return $result instanceof mysqli_result && $result->num_rows > 0;
}

function package_account_sales_fk_exists(mysqli $mysqli): bool {
    $sql = "SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'juego_paquetes_cuenta_galeria' AND CONSTRAINT_NAME = 'fk_juego_paquetes_cuenta_galeria_paquete' AND REFERENCED_TABLE_NAME = 'juego_paquetes' LIMIT 1";
    $result = $mysqli->query($sql);
    return $result instanceof mysqli_result && $result->num_rows > 0;
}

function package_account_sales_ensure_schema(mysqli $mysqli): void {
    static $ensured = false;

    if ($ensured) {
        return;
    }

    if (!package_account_sales_parent_table_exists($mysqli)) {
        return;
    }

    $columns = [
        'vender_cuenta' => "ALTER TABLE juego_paquetes ADD COLUMN vender_cuenta TINYINT(1) NOT NULL DEFAULT 0 AFTER paquete_api",
        'cuenta_texto' => "ALTER TABLE juego_paquetes ADD COLUMN cuenta_texto LONGTEXT NULL AFTER vender_cuenta",
    ];

    foreach ($columns as $column => $alterSql) {
        $result = $mysqli->query("SHOW COLUMNS FROM juego_paquetes LIKE '" . $mysqli->real_escape_string($column) . "'");
        if (!($result instanceof mysqli_result) || $result->num_rows === 0) {
            $mysqli->query($alterSql);
        }
    }

    $mysqli->query(
        "CREATE TABLE IF NOT EXISTS juego_paquetes_cuenta_galeria (
            id INT AUTO_INCREMENT PRIMARY KEY,
            paquete_id INT NOT NULL,
            imagen_ruta VARCHAR(255) NOT NULL,
            descripcion VARCHAR(255) DEFAULT NULL,
            orden INT NOT NULL DEFAULT 1,
            creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_paquete_id (paquete_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    if (!package_account_sales_fk_exists($mysqli)) {
        try {
            $mysqli->query(
                "ALTER TABLE juego_paquetes_cuenta_galeria
                ADD CONSTRAINT fk_juego_paquetes_cuenta_galeria_paquete
                FOREIGN KEY (paquete_id) REFERENCES juego_paquetes(id)
                ON DELETE CASCADE"
            );
        } catch (Throwable $e) {
        }
    }

    $ensured = true;
}

function package_account_sales_normalize_text(?string $value): string {
    $text = trim((string) $value);
    if ($text === '') {
        return '';
    }

    return mb_substr($text, 0, 12000);
}

function package_account_sales_normalize_caption(?string $value): string {
    $caption = trim((string) $value);
    if ($caption === '') {
        return '';
    }

    return mb_substr($caption, 0, 255);
}

function package_account_sales_is_enabled_for_package(array $package, bool $featureEnabled = true): bool {
    if (!$featureEnabled) {
        return false;
    }

    return (int) ($package['vender_cuenta'] ?? 0) === 1;
}

function package_account_sales_fetch_gallery(mysqli $mysqli, int $packageId): array {
    if ($packageId <= 0) {
        return [];
    }

    $stmt = $mysqli->prepare('SELECT id, paquete_id, imagen_ruta, descripcion, orden FROM juego_paquetes_cuenta_galeria WHERE paquete_id = ? ORDER BY orden ASC, id ASC');
    if (!$stmt) {
        return [];
    }

    $stmt->bind_param('i', $packageId);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();

    return array_map(static function (array $row): array {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'package_id' => (int) ($row['paquete_id'] ?? 0),
            'image_path' => trim((string) ($row['imagen_ruta'] ?? '')),
            'description' => package_account_sales_normalize_caption((string) ($row['descripcion'] ?? '')),
            'order' => max(1, (int) ($row['orden'] ?? 1)),
        ];
    }, $rows);
}

function package_account_sales_fetch_gallery_map(mysqli $mysqli, array $packageIds): array {
    $normalizedIds = array_values(array_filter(array_map('intval', $packageIds), static fn (int $id): bool => $id > 0));
    if (empty($normalizedIds)) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($normalizedIds), '?'));
    $types = str_repeat('i', count($normalizedIds));
    $stmt = $mysqli->prepare(
        'SELECT id, paquete_id, imagen_ruta, descripcion, orden FROM juego_paquetes_cuenta_galeria WHERE paquete_id IN (' . $placeholders . ') ORDER BY paquete_id ASC, orden ASC, id ASC'
    );
    if (!$stmt) {
        return [];
    }

    $stmt->bind_param($types, ...$normalizedIds);
    $stmt->execute();
    $result = $stmt->get_result();
    $map = [];
    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            $packageId = (int) ($row['paquete_id'] ?? 0);
            if ($packageId <= 0) {
                continue;
            }

            $map[$packageId][] = [
                'id' => (int) ($row['id'] ?? 0),
                'package_id' => $packageId,
                'image_path' => trim((string) ($row['imagen_ruta'] ?? '')),
                'description' => package_account_sales_normalize_caption((string) ($row['descripcion'] ?? '')),
                'order' => max(1, (int) ($row['orden'] ?? 1)),
            ];
        }
    }
    $stmt->close();

    return $map;
}

function package_account_sales_delete_gallery(mysqli $mysqli, int $packageId): array {
    $existingItems = package_account_sales_fetch_gallery($mysqli, $packageId);
    if ($packageId <= 0) {
        return $existingItems;
    }

    $stmt = $mysqli->prepare('DELETE FROM juego_paquetes_cuenta_galeria WHERE paquete_id = ?');
    if ($stmt) {
        $stmt->bind_param('i', $packageId);
        $stmt->execute();
        $stmt->close();
    }

    return $existingItems;
}

function package_account_sales_replace_gallery(mysqli $mysqli, int $packageId, array $items): void {
    if ($packageId <= 0) {
        return;
    }

    package_account_sales_delete_gallery($mysqli, $packageId);
    if (empty($items)) {
        return;
    }

    $stmt = $mysqli->prepare('INSERT INTO juego_paquetes_cuenta_galeria (paquete_id, imagen_ruta, descripcion, orden) VALUES (?, ?, NULLIF(?, \'\'), ?)');
    if (!$stmt) {
        return;
    }

    foreach ($items as $index => $item) {
        $imagePath = trim((string) ($item['image_path'] ?? ''));
        if ($imagePath === '') {
            continue;
        }

        $description = package_account_sales_normalize_caption((string) ($item['description'] ?? ''));
        $order = max(1, (int) ($item['order'] ?? ($index + 1)));
        $stmt->bind_param('issi', $packageId, $imagePath, $description, $order);
        $stmt->execute();
    }

    $stmt->close();
}

function package_account_sales_build_snapshot(array $package, array $gallery): array {
    return [
        'enabled' => (int) ($package['vender_cuenta'] ?? 0) === 1,
        'account_text' => package_account_sales_normalize_text((string) ($package['cuenta_texto'] ?? '')),
        'gallery' => array_values(array_map(static function (array $item): array {
            return [
                'image_path' => trim((string) ($item['image_path'] ?? '')),
                'description' => package_account_sales_normalize_caption((string) ($item['description'] ?? '')),
                'order' => max(1, (int) ($item['order'] ?? 1)),
            ];
        }, array_filter($gallery, static function (array $item): bool {
            return trim((string) ($item['image_path'] ?? '')) !== '';
        }))),
    ];
}