<?php
// Backend: categorías de juegos (many-to-many)

if (!function_exists('game_categories_ensure_schema')) {
    function game_categories_ensure_schema(mysqli $mysqli): void {
        static $done = false;
        if ($done) return;
        $done = true;

        $mysqli->query("CREATE TABLE IF NOT EXISTS juego_categorias (
            id          INT AUTO_INCREMENT PRIMARY KEY,
            nombre      VARCHAR(100) NOT NULL,
            slug        VARCHAR(120) NOT NULL,
            descripcion VARCHAR(500) NOT NULL DEFAULT '',
            icono       VARCHAR(100) NOT NULL DEFAULT '',
            color       VARCHAR(20)  NOT NULL DEFAULT '',
            orden       INT          NOT NULL DEFAULT 0,
            activa      TINYINT(1)   NOT NULL DEFAULT 1,
            creado_en   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_slug (slug)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $cols = $mysqli->query("SHOW COLUMNS FROM juego_categorias LIKE 'imagen'");
        if ($cols instanceof mysqli_result && $cols->num_rows === 0) {
            $mysqli->query("ALTER TABLE juego_categorias ADD COLUMN imagen VARCHAR(500) NOT NULL DEFAULT '' AFTER color");
        }

        $mysqli->query("CREATE TABLE IF NOT EXISTS juego_categoria_asignada (
            juego_id     INT NOT NULL,
            categoria_id INT NOT NULL,
            PRIMARY KEY (juego_id, categoria_id),
            KEY idx_categoria (categoria_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
}

// ─── Helpers ───────────────────────────────────────────────────────────────

if (!function_exists('game_category_slugify')) {
    function game_category_slugify(string $text): string {
        $text = mb_strtolower(trim($text), 'UTF-8');
        $map  = ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n',
                 'à'=>'a','è'=>'e','ì'=>'i','ò'=>'o','ù'=>'u','â'=>'a','ê'=>'e',
                 'î'=>'i','ô'=>'o','û'=>'u','ä'=>'a','ë'=>'e','ï'=>'i','ö'=>'o'];
        $text = strtr($text, $map);
        $text = preg_replace('/[^a-z0-9]+/', '-', $text);
        return trim((string) $text, '-');
    }
}

if (!function_exists('game_category_slug_exists')) {
    function game_category_slug_exists(mysqli $mysqli, string $slug, int $excludeId = 0): bool {
        $stmt = $mysqli->prepare('SELECT id FROM juego_categorias WHERE slug = ? AND id != ?');
        if (!$stmt) return false;
        $stmt->bind_param('si', $slug, $excludeId);
        $stmt->execute();
        $exists = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        return $exists;
    }
}

if (!function_exists('game_category_unique_slug')) {
    function game_category_unique_slug(mysqli $mysqli, string $nombre, int $excludeId = 0): string {
        $base = game_category_slugify($nombre);
        $slug = $base;
        $n    = 2;
        while (game_category_slug_exists($mysqli, $slug, $excludeId)) {
            $slug = $base . '-' . $n++;
        }
        return $slug;
    }
}

if (!function_exists('game_category_normalize')) {
    function game_category_normalize(array $row): array {
        return [
            'id'          => (int)    $row['id'],
            'nombre'      => trim((string) $row['nombre']),
            'slug'        => trim((string) $row['slug']),
            'descripcion' => trim((string) ($row['descripcion'] ?? '')),
            'icono'       => trim((string) ($row['icono'] ?? '')),
            'color'       => trim((string) ($row['color'] ?? '')),
            'imagen'      => trim((string) ($row['imagen'] ?? '')),
            'orden'       => (int) ($row['orden'] ?? 0),
            'activa'      => (int) ($row['activa'] ?? 1) === 1,
        ];
    }
}

// ─── CRUD de categorías ────────────────────────────────────────────────────

if (!function_exists('game_category_list')) {
    function game_category_list(mysqli $mysqli, bool $onlyActive = false): array {
        game_categories_ensure_schema($mysqli);
        $where  = $onlyActive ? 'WHERE activa = 1 ' : '';
        $result = $mysqli->query("SELECT * FROM juego_categorias {$where}ORDER BY orden ASC, nombre ASC");
        $rows   = [];
        if ($result instanceof mysqli_result) {
            while ($row = $result->fetch_assoc()) {
                $rows[] = game_category_normalize($row);
            }
        }
        return $rows;
    }
}

if (!function_exists('game_category_get')) {
    function game_category_get(mysqli $mysqli, int $id): ?array {
        game_categories_ensure_schema($mysqli);
        $stmt = $mysqli->prepare('SELECT * FROM juego_categorias WHERE id = ?');
        if (!$stmt) return null;
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ? game_category_normalize($row) : null;
    }
}

if (!function_exists('game_category_create')) {
    function game_category_create(mysqli $mysqli, array $data): array {
        game_categories_ensure_schema($mysqli);
        $nombre = trim((string) ($data['nombre'] ?? ''));
        if ($nombre === '') {
            return ['ok' => false, 'message' => 'El nombre de la categoría es obligatorio.'];
        }
        $slug = trim((string) ($data['slug'] ?? ''));
        if ($slug === '') {
            $slug = game_category_unique_slug($mysqli, $nombre);
        } else {
            $slug = game_category_slugify($slug);
            if ($slug === '') {
                return ['ok' => false, 'message' => 'El slug generado está vacío.'];
            }
            if (game_category_slug_exists($mysqli, $slug)) {
                return ['ok' => false, 'message' => "El slug '{$slug}' ya está en uso."];
            }
        }
        $descripcion = trim((string) ($data['descripcion'] ?? ''));
        $icono       = trim((string) ($data['icono'] ?? ''));
        $color       = trim((string) ($data['color'] ?? ''));
        $imagen      = trim((string) ($data['imagen'] ?? ''));
        $orden       = max(0, (int) ($data['orden'] ?? 0));
        $activa      = isset($data['activa']) && (int) $data['activa'] === 0 ? 0 : 1;

        // ssssssii: nombre, slug, descripcion, icono, color, imagen (strings) + orden, activa (ints)
        $stmt = $mysqli->prepare(
            'INSERT INTO juego_categorias (nombre, slug, descripcion, icono, color, imagen, orden, activa)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        if (!$stmt) return ['ok' => false, 'message' => 'Error interno de base de datos.'];
        $stmt->bind_param('ssssssii', $nombre, $slug, $descripcion, $icono, $color, $imagen, $orden, $activa);
        $ok         = $stmt->execute();
        $insertedId = (int) $mysqli->insert_id;
        $stmt->close();

        if (!$ok || $insertedId === 0) {
            return ['ok' => false, 'message' => 'No se pudo crear la categoría.'];
        }
        return ['ok' => true, 'id' => $insertedId, 'slug' => $slug];
    }
}

if (!function_exists('game_category_update')) {
    function game_category_update(mysqli $mysqli, int $id, array $data): array {
        game_categories_ensure_schema($mysqli);
        if ($id <= 0) return ['ok' => false, 'message' => 'ID inválido.'];
        $existing = game_category_get($mysqli, $id);
        if (!$existing) return ['ok' => false, 'message' => 'Categoría no encontrada.'];

        $nombre = trim((string) ($data['nombre'] ?? $existing['nombre']));
        if ($nombre === '') return ['ok' => false, 'message' => 'El nombre es obligatorio.'];

        $slug = trim((string) ($data['slug'] ?? ''));
        if ($slug === '') {
            $slug = game_category_unique_slug($mysqli, $nombre, $id);
        } else {
            $slug = game_category_slugify($slug);
            if ($slug === '') return ['ok' => false, 'message' => 'El slug generado está vacío.'];
            if (game_category_slug_exists($mysqli, $slug, $id)) {
                return ['ok' => false, 'message' => "El slug '{$slug}' ya está en uso."];
            }
        }
        $descripcion = trim((string) ($data['descripcion'] ?? $existing['descripcion']));
        $icono       = trim((string) ($data['icono'] ?? $existing['icono']));
        $color       = trim((string) ($data['color'] ?? $existing['color']));
        $imagen      = array_key_exists('imagen', $data) ? trim((string) $data['imagen']) : $existing['imagen'];
        $orden       = isset($data['orden']) ? max(0, (int) $data['orden']) : $existing['orden'];
        $activa      = isset($data['activa']) ? ((int) $data['activa'] === 0 ? 0 : 1) : (int) $existing['activa'];

        // ssssssiii: nombre, slug, descripcion, icono, color, imagen (strings) + orden, activa, id (ints)
        $stmt = $mysqli->prepare(
            'UPDATE juego_categorias
             SET nombre=?, slug=?, descripcion=?, icono=?, color=?, imagen=?, orden=?, activa=?
             WHERE id=?'
        );
        if (!$stmt) return ['ok' => false, 'message' => 'Error interno de base de datos.'];
        $stmt->bind_param('ssssssiii', $nombre, $slug, $descripcion, $icono, $color, $imagen, $orden, $activa, $id);
        $ok = $stmt->execute();
        $stmt->close();

        return $ok
            ? ['ok' => true, 'slug' => $slug]
            : ['ok' => false, 'message' => 'No se pudo actualizar la categoría.'];
    }
}

if (!function_exists('game_category_delete')) {
    function game_category_delete(mysqli $mysqli, int $id): array {
        game_categories_ensure_schema($mysqli);
        if ($id <= 0) return ['ok' => false, 'message' => 'ID inválido.'];

        $toDelete = game_category_get($mysqli, $id);

        $stmtCheck = $mysqli->prepare('SELECT COUNT(*) FROM juego_categoria_asignada WHERE categoria_id = ?');
        if ($stmtCheck) {
            $stmtCheck->bind_param('i', $id);
            $stmtCheck->execute();
            $stmtCheck->bind_result($assignedCount);
            $stmtCheck->fetch();
            $stmtCheck->close();
            if ((int) $assignedCount > 0) {
                return ['ok' => false, 'message' => "No se puede eliminar: la categoría está asignada a {$assignedCount} juego(s). Desvincula los juegos primero."];
            }
        }

        $stmt = $mysqli->prepare('DELETE FROM juego_categoria_asignada WHERE categoria_id = ?');
        if ($stmt) { $stmt->bind_param('i', $id); $stmt->execute(); $stmt->close(); }

        $stmt = $mysqli->prepare('DELETE FROM juego_categorias WHERE id = ?');
        if (!$stmt) return ['ok' => false, 'message' => 'Error interno de base de datos.'];
        $stmt->bind_param('i', $id);
        $ok       = $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();

        if ($ok && $affected > 0) {
            if ($toDelete && $toDelete['imagen'] !== '' && function_exists('tenant_resolve_public_path')) {
                $abs = tenant_resolve_public_path($toDelete['imagen']);
                if ($abs !== null && is_file($abs)) {
                    @unlink($abs);
                }
            }
            return ['ok' => true];
        }
        return ['ok' => false, 'message' => 'Categoría no encontrada.'];
    }
}

// ─── Asignación de categorías a juegos ────────────────────────────────────

if (!function_exists('game_set_categories')) {
    /**
     * Reemplaza todas las categorías de un juego con el array dado de IDs.
     * Pasar array vacío desvincula todas las categorías.
     */
    function game_set_categories(mysqli $mysqli, int $juegoId, array $categoryIds): void {
        game_categories_ensure_schema($mysqli);
        $stmt = $mysqli->prepare('DELETE FROM juego_categoria_asignada WHERE juego_id = ?');
        if ($stmt) { $stmt->bind_param('i', $juegoId); $stmt->execute(); $stmt->close(); }

        foreach ($categoryIds as $catId) {
            $catId = (int) $catId;
            if ($catId <= 0) continue;
            $stmt = $mysqli->prepare(
                'INSERT IGNORE INTO juego_categoria_asignada (juego_id, categoria_id) VALUES (?, ?)'
            );
            if (!$stmt) continue;
            $stmt->bind_param('ii', $juegoId, $catId);
            $stmt->execute();
            $stmt->close();
        }
    }
}

if (!function_exists('game_get_categories')) {
    /** Retorna las categorías completas asignadas a un juego. */
    function game_get_categories(mysqli $mysqli, int $juegoId): array {
        game_categories_ensure_schema($mysqli);
        $stmt = $mysqli->prepare(
            'SELECT c.* FROM juego_categorias c
             INNER JOIN juego_categoria_asignada a ON a.categoria_id = c.id
             WHERE a.juego_id = ?
             ORDER BY c.orden ASC, c.nombre ASC'
        );
        if (!$stmt) return [];
        $stmt->bind_param('i', $juegoId);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows   = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = game_category_normalize($row);
        }
        $stmt->close();
        return $rows;
    }
}

if (!function_exists('game_get_category_ids')) {
    /** Retorna solo los IDs de categorías asignadas a un juego. */
    function game_get_category_ids(mysqli $mysqli, int $juegoId): array {
        game_categories_ensure_schema($mysqli);
        $stmt = $mysqli->prepare(
            'SELECT categoria_id FROM juego_categoria_asignada WHERE juego_id = ?'
        );
        if (!$stmt) return [];
        $stmt->bind_param('i', $juegoId);
        $stmt->execute();
        $result = $stmt->get_result();
        $ids    = [];
        while ($row = $result->fetch_assoc()) {
            $ids[] = (int) $row['categoria_id'];
        }
        $stmt->close();
        return $ids;
    }
}

// ─── Consultas combinadas ──────────────────────────────────────────────────

if (!function_exists('games_by_category')) {
    /** Retorna todos los juegos asignados a una categoría. */
    function games_by_category(mysqli $mysqli, int $categoryId, bool $onlyActive = true): array {
        game_categories_ensure_schema($mysqli);
        $activeClause = $onlyActive ? 'AND COALESCE(j.activo, 1) = 1 ' : '';
        $stmt = $mysqli->prepare(
            "SELECT j.* FROM juegos j
             INNER JOIN juego_categoria_asignada a ON a.juego_id = j.id
             WHERE a.categoria_id = ? {$activeClause}
             ORDER BY j.orden ASC, j.id ASC"
        );
        if (!$stmt) return [];
        $stmt->bind_param('i', $categoryId);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows   = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();
        return $rows;
    }
}

if (!function_exists('games_list_with_categories')) {
    /**
     * Retorna todos los juegos con sus categorías asignadas embebidas.
     * Cada elemento incluye los campos de `juegos` + 'categories' => [{id, nombre, slug, icono, color}]
     */
    function games_list_with_categories(mysqli $mysqli, bool $onlyActive = false): array {
        game_categories_ensure_schema($mysqli);
        $where  = $onlyActive ? 'WHERE COALESCE(j.activo, 1) = 1 ' : '';
        $result = $mysqli->query(
            "SELECT j.*,
                    GROUP_CONCAT(c.id     ORDER BY c.orden ASC, c.nombre ASC SEPARATOR ',')  AS _cat_ids,
                    GROUP_CONCAT(c.nombre ORDER BY c.orden ASC, c.nombre ASC SEPARATOR '\t') AS _cat_nombres,
                    GROUP_CONCAT(c.slug   ORDER BY c.orden ASC, c.nombre ASC SEPARATOR '\t') AS _cat_slugs,
                    GROUP_CONCAT(c.icono  ORDER BY c.orden ASC, c.nombre ASC SEPARATOR '\t') AS _cat_iconos,
                    GROUP_CONCAT(c.color  ORDER BY c.orden ASC, c.nombre ASC SEPARATOR '\t') AS _cat_colores
             FROM juegos j
             LEFT JOIN juego_categoria_asignada a ON a.juego_id = j.id
             LEFT JOIN juego_categorias c ON c.id = a.categoria_id
             {$where}
             GROUP BY j.id
             ORDER BY CASE WHEN j.orden IS NULL THEN 1 ELSE 0 END, j.orden ASC, j.id ASC"
        );
        $rows = [];
        if ($result instanceof mysqli_result) {
            while ($row = $result->fetch_assoc()) {
                $catIds     = $row['_cat_ids']     !== null ? array_map('intval', explode(',',  $row['_cat_ids']))     : [];
                $catNombres = $row['_cat_nombres']  !== null ? explode("\t", $row['_cat_nombres'])  : [];
                $catSlugs   = $row['_cat_slugs']   !== null ? explode("\t", $row['_cat_slugs'])   : [];
                $catIconos  = $row['_cat_iconos']  !== null ? explode("\t", $row['_cat_iconos'])  : [];
                $catColores = $row['_cat_colores'] !== null ? explode("\t", $row['_cat_colores']) : [];
                $categories = [];
                foreach ($catIds as $k => $catId) {
                    if ($catId <= 0) continue;
                    $categories[] = [
                        'id'     => $catId,
                        'nombre' => $catNombres[$k] ?? '',
                        'slug'   => $catSlugs[$k]   ?? '',
                        'icono'  => $catIconos[$k]  ?? '',
                        'color'  => $catColores[$k] ?? '',
                    ];
                }
                unset($row['_cat_ids'], $row['_cat_nombres'], $row['_cat_slugs'],
                      $row['_cat_iconos'], $row['_cat_colores']);
                $row['categories'] = $categories;
                $rows[] = $row;
            }
        }
        return $rows;
    }
}
