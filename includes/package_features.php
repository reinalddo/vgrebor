<?php

if (!function_exists('package_features_ensure_schema')) {
    function package_features_ensure_schema(mysqli $mysqli): void {
        $catalogSql = <<<'SQL'
CREATE TABLE IF NOT EXISTS paquete_caracteristicas_catalogo (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(150) NOT NULL,
    icono VARCHAR(80) NOT NULL DEFAULT 'sparkles',
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    actualizado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_paquete_caracteristicas_catalogo_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;
        $mysqli->query($catalogSql);

        $assignSql = <<<'SQL'
CREATE TABLE IF NOT EXISTS paquete_caracteristicas_asignadas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    paquete_id INT NOT NULL,
    caracteristica_id INT NOT NULL,
    orden INT NOT NULL DEFAULT 0,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_paquete_caracteristica (paquete_id, caracteristica_id),
    KEY idx_paquete_caracteristicas_asignadas_paquete (paquete_id),
    KEY idx_paquete_caracteristicas_asignadas_caracteristica (caracteristica_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;
        $mysqli->query($assignSql);
    }
}

if (!function_exists('package_feature_icon_options')) {
    function package_feature_icon_options(): array {
        return [
            'sparkles' => 'Brillo',
            'diamond' => 'Diamante',
            'lightning' => 'Entrega instantanea',
            'shield' => 'Proteccion',
            'gift' => 'Bonus',
            'controller' => 'Gaming',
            'trophy' => 'Premio',
            'rocket' => 'Impulso',
            'star' => 'Destacado',
            'layers' => 'Bundle',
            'best_seller' => 'MAS VENDIDO',
            'limited' => 'LIMITADO',
            'recommended' => 'RECOMENDADO',
        ];
    }
}

if (!function_exists('package_feature_icon_svg_map')) {
    function package_feature_icon_svg_map(): array {
        return [
            'sparkles' => '<svg viewBox="0 0 16 16" aria-hidden="true"><path fill="currentColor" d="M8 0l1.1 3.3L12.4 4.4 9.7 6.3 10.7 9.6 8 7.7 5.3 9.6 6.3 6.3 3.6 4.4 6.9 3.3 8 0Zm-5.2 9.2.6 1.8 1.8.6-1.8.6-.6 1.8-.6-1.8-1.8-.6 1.8-.6.6-1.8Zm10.4 0 .6 1.8 1.8.6-1.8.6-.6 1.8-.6-1.8-1.8-.6 1.8-.6.6-1.8Z"/></svg>',
            'diamond' => '<svg viewBox="0 0 16 16" aria-hidden="true"><path fill="currentColor" d="M4.1 1h7.8l3 3.6L8 15 1.1 4.6 4.1 1Zm1 1.5L3.1 5h2.7l1.1-2.5H5.1Zm5.8 0H9.1L10.2 5h2.7l-2-2.5ZM8 3.2 7.2 5h1.6L8 3.2ZM6.4 6.2 8 11.6l1.6-5.4H6.4Z"/></svg>',
            'lightning' => '<svg viewBox="0 0 16 16" aria-hidden="true"><path fill="currentColor" d="M9.5.5 3 8h3.6L5.5 15.5 13 7.9H9.4L9.5.5Z"/></svg>',
            'shield' => '<svg viewBox="0 0 16 16" aria-hidden="true"><path fill="currentColor" d="M8 0 2.5 2v4.8c0 3.6 2.2 6.8 5.5 8.2 3.3-1.4 5.5-4.6 5.5-8.2V2L8 0Zm0 1.6 4 1.4v3.8c0 2.7-1.5 5.2-4 6.4-2.5-1.2-4-3.7-4-6.4V3l4-1.4Z"/></svg>',
            'gift' => '<svg viewBox="0 0 16 16" aria-hidden="true"><path fill="currentColor" d="M15 4h-2.1c.4-.4.6-.9.6-1.5C13.5 1.1 12.4 0 11 0 9.8 0 8.8.7 8 1.8 7.2.7 6.2 0 5 0 3.6 0 2.5 1.1 2.5 2.5c0 .6.2 1.1.6 1.5H1v3h1v8h12V7h1V4ZM9 2.5c.5-.8 1.2-1.2 2-1.2.6 0 1.1.5 1.1 1.2S11.6 3.7 11 3.7H9V2.5Zm-4 0c.8 0 1.5.4 2 1.2v1.2H5c-.6 0-1.1-.5-1.1-1.2S4.4 2.5 5 2.5ZM2.3 5.3h5v1.4h-5V5.3Zm6.4 0h5v1.4h-5V5.3ZM7.3 8v5.7H3.4V8h3.9Zm1.4 0h3.9v5.7H8.7V8Z"/></svg>',
            'controller' => '<svg viewBox="0 0 16 16" aria-hidden="true"><path fill="currentColor" d="M4 4.5C2.1 4.5.5 6 .5 7.9c0 1 .4 1.9 1.1 2.6l1.8 1.8c.6.6 1.4.9 2.2.9.9 0 1.7-.4 2.3-1.1l.1-.2.1.2c.6.7 1.4 1.1 2.3 1.1.8 0 1.6-.3 2.2-.9l1.8-1.8c.7-.7 1.1-1.6 1.1-2.6C15.5 6 13.9 4.5 12 4.5c-1.1 0-2.2.5-2.9 1.3L8 7 6.9 5.8C6.2 5 5.1 4.5 4 4.5ZM4.5 7h1v1h1v1h-1v1h-1V9h-1V8h1V7Zm6.8.7a.8.8 0 1 1 0 1.6.8.8 0 0 1 0-1.6Zm1.9-1.7a.8.8 0 1 1 0 1.6.8.8 0 0 1 0-1.6Z"/></svg>',
            'trophy' => '<svg viewBox="0 0 16 16" aria-hidden="true"><path fill="currentColor" d="M4 1h8v2h2v1a4 4 0 0 1-3.5 4A4.5 4.5 0 0 1 8.8 10v1.5h2V13H5.2v-1.5h2V10A4.5 4.5 0 0 1 5.5 8 4 4 0 0 1 2 4V3h2V1Zm1.5 1.5v3A3 3 0 0 0 8 8.5a3 3 0 0 0 2.5-3v-3h-5ZM3.5 4.5c0 1 .7 1.9 1.6 2.2-.1-.4-.1-.8-.1-1.2v-.9H3.5v-.1Zm9.5 0v.9c0 .4 0 .8-.1 1.2.9-.3 1.6-1.2 1.6-2.2v.1H13Z"/></svg>',
            'rocket' => '<svg viewBox="0 0 16 16" aria-hidden="true"><path fill="currentColor" d="M9.7.5c1.8.1 3.4.8 4.8 2.1 1.3 1.4 2 3 2.1 4.8l-3.4 1.7-1.5-1.5-2.3 2.3.6 2.7-2.1 2.1-.9-2.4-2.4-.9 2.1-2.1 2.7.6 2.3-2.3-1.5-1.5L9.7.5Zm2.6 3.1a1.2 1.2 0 1 0 0 2.4 1.2 1.2 0 0 0 0-2.4ZM2.2 11.3c.8 0 1.5.7 1.5 1.5 0 1.2-1.9 2.7-3.2 3.2.5-1.3 2-3.2 3.2-3.2Z"/></svg>',
            'star' => '<svg viewBox="0 0 16 16" aria-hidden="true"><path fill="currentColor" d="m8 0 2.2 4.6 5 .7-3.6 3.6.9 5.1L8 11.6 3.5 14l.9-5.1L.8 5.3l5-.7L8 0Z"/></svg>',
            'layers' => '<svg viewBox="0 0 16 16" aria-hidden="true"><path fill="currentColor" d="M8 1 1 4.5 8 8l7-3.5L8 1Zm-7 6 7 3.5L15 7v2L8 12.5 1 9V7Zm0 4 7 3.5 7-3.5v2L8 16 1 13v-2Z"/></svg>',
            'best_seller' => '<span aria-hidden="true" style="display:inline-block;font-size:1.05rem;line-height:1;">🔥</span>',
            'limited' => '<span aria-hidden="true" style="display:inline-block;font-size:1.05rem;line-height:1;">⏳</span>',
            'recommended' => '<span aria-hidden="true" style="display:inline-block;font-size:1.05rem;line-height:1;">👑</span>',
        ];
    }
}

if (!function_exists('package_feature_icon_aliases')) {
    function package_feature_icon_aliases(): array {
        return [
            'brillo' => 'sparkles',
            'sparkles' => 'sparkles',
            'diamante' => 'diamond',
            'diamond' => 'diamond',
            'entrega inmediata' => 'lightning',
            'entrega instantanea' => 'lightning',
            'entrega instantánea' => 'lightning',
            'lightning' => 'lightning',
            'proteccion' => 'shield',
            'protección' => 'shield',
            'shield' => 'shield',
            'bonus' => 'gift',
            'gift' => 'gift',
            'gaming' => 'controller',
            'controller' => 'controller',
            'premio' => 'trophy',
            'trophy' => 'trophy',
            'impulso' => 'rocket',
            'rocket' => 'rocket',
            'destacado' => 'star',
            'star' => 'star',
            'bundle' => 'layers',
            'layers' => 'layers',
            'mas vendido' => 'best_seller',
            'más vendido' => 'best_seller',
            'best seller' => 'best_seller',
            'best_seller' => 'best_seller',
            'limitado' => 'limited',
            'limited' => 'limited',
            'recomendado' => 'recommended',
            'recommended' => 'recommended',
        ];
    }
}

if (!function_exists('package_feature_icon_visual_styles')) {
    function package_feature_icon_visual_styles(string $icon): array {
        $normalizedIcon = package_feature_icon_aliases()[strtolower(trim($icon))] ?? package_feature_normalize_icon($icon);
        $styles = [
            'sparkles' => ['color' => '#67e8f9', 'shadow' => 'rgba(103, 232, 249, 0.45)'],
            'diamond' => ['color' => '#7dd3fc', 'shadow' => 'rgba(125, 211, 252, 0.5)'],
            'lightning' => ['color' => '#fbbf24', 'shadow' => 'rgba(251, 191, 36, 0.58)'],
            'shield' => ['color' => '#93c5fd', 'shadow' => 'rgba(147, 197, 253, 0.48)'],
            'gift' => ['color' => '#f9a8d4', 'shadow' => 'rgba(249, 168, 212, 0.45)'],
            'controller' => ['color' => '#c4b5fd', 'shadow' => 'rgba(196, 181, 253, 0.48)'],
            'trophy' => ['color' => '#fcd34d', 'shadow' => 'rgba(252, 211, 77, 0.52)'],
            'rocket' => ['color' => '#fb7185', 'shadow' => 'rgba(251, 113, 133, 0.48)'],
            'star' => ['color' => '#fde68a', 'shadow' => 'rgba(253, 230, 138, 0.48)'],
            'layers' => ['color' => '#86efac', 'shadow' => 'rgba(134, 239, 172, 0.45)'],
            'best_seller' => ['color' => '#fb923c', 'shadow' => 'rgba(251, 146, 60, 0.5)'],
            'limited' => ['color' => '#fca5a5', 'shadow' => 'rgba(252, 165, 165, 0.45)'],
            'recommended' => ['color' => '#facc15', 'shadow' => 'rgba(250, 204, 21, 0.5)'],
        ];

        return $styles[$normalizedIcon] ?? $styles['sparkles'];
    }
}

if (!function_exists('package_feature_normalize_icon')) {
    function package_feature_normalize_icon(?string $value): string {
        $normalized = strtolower(trim((string) $value));
        $aliases = package_feature_icon_aliases();
        if (isset($aliases[$normalized])) {
            $normalized = $aliases[$normalized];
        }
        $options = package_feature_icon_options();
        return array_key_exists($normalized, $options) ? $normalized : 'sparkles';
    }
}

if (!function_exists('package_feature_normalize_name')) {
    function package_feature_normalize_name(?string $value): string {
        $normalized = trim((string) $value);
        $normalized = preg_replace('/\s+/', ' ', $normalized) ?? $normalized;
        return mb_substr($normalized, 0, 150, 'UTF-8');
    }
}

if (!function_exists('package_feature_public_asset_url')) {
    function package_feature_public_asset_url(?string $path): string {
        $value = trim((string) $path);
        if ($value === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $value) === 1) {
            return $value;
        }
        if (function_exists('app_asset_path')) {
            return app_asset_path($value);
        }
        return '/' . ltrim($value, '/');
    }
}

if (!function_exists('package_feature_render_icon')) {
    function package_feature_render_icon(string $icon, string $className = ''): string {
        $svgMap = package_feature_icon_svg_map();
        $normalizedIcon = package_feature_normalize_icon($icon);
        $visualStyles = package_feature_icon_visual_styles($normalizedIcon);
        $svg = $svgMap[$normalizedIcon] ?? $svgMap['sparkles'];
        $isSvg = stripos($svg, '<svg') !== false;
        if ($isSvg) {
            $svg = preg_replace('/<svg\s+/i', '<svg style="width:100%;height:100%;display:block;" ', $svg, 1) ?? $svg;
        }
        $classAttr = trim($className) !== '' ? ' class="' . htmlspecialchars(trim($className), ENT_QUOTES, 'UTF-8') . '"' : '';
        $style = $isSvg
            ? 'display:inline-flex;width:1rem;height:1rem;line-height:0;flex:0 0 auto;align-items:center;justify-content:center;'
            : 'display:inline-flex;min-width:1rem;line-height:1;flex:0 0 auto;align-items:center;justify-content:center;';
        $style .= 'color:' . htmlspecialchars((string) ($visualStyles['color'] ?? '#67e8f9'), ENT_QUOTES, 'UTF-8') . ';';
        $style .= 'filter:drop-shadow(0 0 0.42rem ' . htmlspecialchars((string) ($visualStyles['shadow'] ?? 'rgba(103, 232, 249, 0.45)'), ENT_QUOTES, 'UTF-8') . ');';
        return '<span' . $classAttr . ' style="' . $style . '">' . $svg . '</span>';
    }
}

if (!function_exists('package_feature_catalog_all')) {
    function package_feature_catalog_all(mysqli $mysqli): array {
        package_features_ensure_schema($mysqli);
        $result = $mysqli->query('SELECT id, nombre, icono FROM paquete_caracteristicas_catalogo ORDER BY nombre ASC, id ASC');
        if (!($result instanceof mysqli_result)) {
            return [];
        }

        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = [
                'id' => (int) ($row['id'] ?? 0),
                'name' => trim((string) ($row['nombre'] ?? '')),
                'icon' => package_feature_normalize_icon((string) ($row['icono'] ?? 'sparkles')),
            ];
        }

        return $rows;
    }
}

if (!function_exists('package_feature_catalog_find_or_create')) {
    function package_feature_catalog_find_or_create(mysqli $mysqli, string $name, string $icon): int {
        package_features_ensure_schema($mysqli);
        $normalizedName = package_feature_normalize_name($name);
        if ($normalizedName === '') {
            return 0;
        }

        $normalizedIcon = package_feature_normalize_icon($icon);
        $stmt = $mysqli->prepare(
            'INSERT INTO paquete_caracteristicas_catalogo (nombre, icono) VALUES (?, ?) '
            . 'ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), nombre = VALUES(nombre), icono = VALUES(icono), actualizado_en = CURRENT_TIMESTAMP'
        );
        if (!$stmt) {
            return 0;
        }

        $stmt->bind_param('ss', $normalizedName, $normalizedIcon);
        $stmt->execute();
        $id = (int) $mysqli->insert_id;
        $stmt->close();

        return $id;
    }
}

if (!function_exists('package_feature_catalog_update')) {
    function package_feature_catalog_update(mysqli $mysqli, int $featureId, string $name, string $icon): bool {
        package_features_ensure_schema($mysqli);
        if ($featureId <= 0) {
            return false;
        }

        $normalizedName = package_feature_normalize_name($name);
        if ($normalizedName === '') {
            return false;
        }

        $normalizedIcon = package_feature_normalize_icon($icon);
        $stmt = $mysqli->prepare('UPDATE paquete_caracteristicas_catalogo SET nombre = ?, icono = ? WHERE id = ? LIMIT 1');
        if (!$stmt) {
            return false;
        }

        $stmt->bind_param('ssi', $normalizedName, $normalizedIcon, $featureId);
        $ok = $stmt->execute();
        $stmt->close();

        return $ok;
    }
}

if (!function_exists('package_feature_catalog_delete')) {
    function package_feature_catalog_delete(mysqli $mysqli, int $featureId): bool {
        package_features_ensure_schema($mysqli);
        if ($featureId <= 0) {
            return false;
        }

        $deleteAssignments = $mysqli->prepare('DELETE FROM paquete_caracteristicas_asignadas WHERE caracteristica_id = ?');
        if ($deleteAssignments) {
            $deleteAssignments->bind_param('i', $featureId);
            $deleteAssignments->execute();
            $deleteAssignments->close();
        }

        $stmt = $mysqli->prepare('DELETE FROM paquete_caracteristicas_catalogo WHERE id = ? LIMIT 1');
        if (!$stmt) {
            return false;
        }

        $stmt->bind_param('i', $featureId);
        $ok = $stmt->execute();
        $stmt->close();

        return $ok;
    }
}

if (!function_exists('package_feature_catalog_ids_for_package')) {
    function package_feature_catalog_ids_for_package(mysqli $mysqli, int $packageId): array {
        package_features_ensure_schema($mysqli);
        if ($packageId <= 0) {
            return [];
        }

        $stmt = $mysqli->prepare('SELECT caracteristica_id FROM paquete_caracteristicas_asignadas WHERE paquete_id = ? ORDER BY orden ASC, id ASC');
        if (!$stmt) {
            return [];
        }

        $stmt->bind_param('i', $packageId);
        $stmt->execute();
        $result = $stmt->get_result();
        $ids = [];
        if ($result instanceof mysqli_result) {
            while ($row = $result->fetch_assoc()) {
                $ids[] = (int) ($row['caracteristica_id'] ?? 0);
            }
        }
        $stmt->close();

        return array_values(array_filter(array_unique($ids), static fn ($id) => $id > 0));
    }
}

if (!function_exists('package_feature_pairs_from_request')) {
    function package_feature_pairs_from_request(array $names, array $icons): array {
        $pairs = [];
        foreach ($names as $index => $name) {
            $normalizedName = package_feature_normalize_name((string) $name);
            if ($normalizedName === '') {
                continue;
            }
            $pairs[] = [
                'name' => $normalizedName,
                'icon' => package_feature_normalize_icon((string) ($icons[$index] ?? 'sparkles')),
            ];
        }
        return $pairs;
    }
}

if (!function_exists('package_feature_resolve_ids')) {
    function package_feature_resolve_ids(mysqli $mysqli, array $existingIds, array $newFeatures = []): array {
        package_features_ensure_schema($mysqli);

        $orderedIds = [];
        foreach ($existingIds as $featureId) {
            $normalizedId = (int) $featureId;
            if ($normalizedId > 0 && !in_array($normalizedId, $orderedIds, true)) {
                $orderedIds[] = $normalizedId;
            }
        }

        foreach ($newFeatures as $feature) {
            $featureId = package_feature_catalog_find_or_create(
                $mysqli,
                (string) ($feature['name'] ?? ''),
                (string) ($feature['icon'] ?? 'sparkles')
            );
            if ($featureId > 0 && !in_array($featureId, $orderedIds, true)) {
                $orderedIds[] = $featureId;
            }
        }

        return $orderedIds;
    }
}

if (!function_exists('package_assign_feature_ids_to_package')) {
    function package_assign_feature_ids_to_package(mysqli $mysqli, int $packageId, array $featureIds): void {
        package_features_ensure_schema($mysqli);
        if ($packageId <= 0) {
            return;
        }

        $normalizedIds = [];
        foreach ($featureIds as $featureId) {
            $normalizedId = (int) $featureId;
            if ($normalizedId > 0 && !in_array($normalizedId, $normalizedIds, true)) {
                $normalizedIds[] = $normalizedId;
            }
        }

        $deleteStmt = $mysqli->prepare('DELETE FROM paquete_caracteristicas_asignadas WHERE paquete_id = ?');
        if ($deleteStmt) {
            $deleteStmt->bind_param('i', $packageId);
            $deleteStmt->execute();
            $deleteStmt->close();
        }

        if (empty($normalizedIds)) {
            return;
        }

        $insertStmt = $mysqli->prepare('INSERT INTO paquete_caracteristicas_asignadas (paquete_id, caracteristica_id, orden) VALUES (?, ?, ?)');
        if (!$insertStmt) {
            return;
        }

        foreach ($normalizedIds as $index => $featureId) {
            $order = $index + 1;
            $insertStmt->bind_param('iii', $packageId, $featureId, $order);
            $insertStmt->execute();
        }
        $insertStmt->close();
    }
}

if (!function_exists('package_assign_features_to_package')) {
    function package_assign_features_to_package(mysqli $mysqli, int $packageId, array $existingIds, array $newFeatures = []): void {
        package_features_ensure_schema($mysqli);
        if ($packageId <= 0) {
            return;
        }

        package_assign_feature_ids_to_package($mysqli, $packageId, package_feature_resolve_ids($mysqli, $existingIds, $newFeatures));
    }
}

if (!function_exists('package_delete_feature_assignments')) {
    function package_delete_feature_assignments(mysqli $mysqli, int $packageId): void {
        package_features_ensure_schema($mysqli);
        if ($packageId <= 0) {
            return;
        }

        $stmt = $mysqli->prepare('DELETE FROM paquete_caracteristicas_asignadas WHERE paquete_id = ?');
        if (!$stmt) {
            return;
        }

        $stmt->bind_param('i', $packageId);
        $stmt->execute();
        $stmt->close();
    }
}

if (!function_exists('package_features_for_packages')) {
    function package_features_for_packages(mysqli $mysqli, array $packageIds): array {
        package_features_ensure_schema($mysqli);
        $normalizedIds = array_values(array_filter(array_map('intval', $packageIds), static fn ($id) => $id > 0));
        if (empty($normalizedIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($normalizedIds), '?'));
        $types = str_repeat('i', count($normalizedIds));
        $sql = 'SELECT a.paquete_id, c.id AS caracteristica_id, c.nombre, c.icono '
            . 'FROM paquete_caracteristicas_asignadas a '
            . 'INNER JOIN paquete_caracteristicas_catalogo c ON c.id = a.caracteristica_id '
            . 'WHERE a.paquete_id IN (' . $placeholders . ') '
            . 'ORDER BY a.paquete_id ASC, a.orden ASC, a.id ASC';
        $stmt = $mysqli->prepare($sql);
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
                if (!isset($map[$packageId])) {
                    $map[$packageId] = [];
                }
                $map[$packageId][] = [
                    'id' => (int) ($row['caracteristica_id'] ?? 0),
                    'name' => trim((string) ($row['nombre'] ?? '')),
                    'icon' => package_feature_normalize_icon((string) ($row['icono'] ?? 'sparkles')),
                ];
            }
        }
        $stmt->close();

        return $map;
    }
}

if (!function_exists('package_apply_feature_ids_to_game_packages')) {
    function package_apply_feature_ids_to_game_packages(mysqli $mysqli, int $gameId, array $featureIds, bool $replaceExisting = false, ?int $excludePackageId = null): void {
        package_features_ensure_schema($mysqli);
        $normalizedGameId = (int) $gameId;
        if ($normalizedGameId <= 0) {
            return;
        }

        $resolvedFeatureIds = [];
        foreach ($featureIds as $featureId) {
            $normalizedId = (int) $featureId;
            if ($normalizedId > 0 && !in_array($normalizedId, $resolvedFeatureIds, true)) {
                $resolvedFeatureIds[] = $normalizedId;
            }
        }

        if (empty($resolvedFeatureIds)) {
            return;
        }

        $stmt = $mysqli->prepare('SELECT id FROM juego_paquetes WHERE juego_id = ? ORDER BY CASE WHEN orden IS NULL THEN 1 ELSE 0 END, orden ASC, id ASC');
        if (!$stmt) {
            return;
        }

        $stmt->bind_param('i', $normalizedGameId);
        $stmt->execute();
        $result = $stmt->get_result();
        $packageIds = [];
        if ($result instanceof mysqli_result) {
            while ($row = $result->fetch_assoc()) {
                $packageId = (int) ($row['id'] ?? 0);
                if ($packageId <= 0) {
                    continue;
                }
                if ($excludePackageId !== null && $packageId === (int) $excludePackageId) {
                    continue;
                }
                $packageIds[] = $packageId;
            }
        }
        $stmt->close();

        foreach ($packageIds as $packageId) {
            $packageFeatureIds = $replaceExisting ? [] : package_feature_catalog_ids_for_package($mysqli, $packageId);
            foreach ($resolvedFeatureIds as $featureId) {
                if (!in_array($featureId, $packageFeatureIds, true)) {
                    $packageFeatureIds[] = $featureId;
                }
            }
            package_assign_feature_ids_to_package($mysqli, $packageId, $packageFeatureIds);
        }
    }
}
