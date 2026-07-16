<?php
// Backend: categorías de paquetes (por juego, un paquete pertenece a lo sumo a una categoría)

if (!function_exists('package_categories_ensure_schema')) {
    function package_categories_ensure_schema(mysqli $mysqli): void {
        static $done = false;
        if ($done) return;
        $done = true;

        $mysqli->query("CREATE TABLE IF NOT EXISTS paquete_categorias (
            id           INT AUTO_INCREMENT PRIMARY KEY,
            juego_id     INT NOT NULL,
            nombre       VARCHAR(100) NOT NULL,
            slug         VARCHAR(120) NOT NULL,
            icono        VARCHAR(100) NOT NULL DEFAULT '',
            color        VARCHAR(20)  NOT NULL DEFAULT '',
            imagen       VARCHAR(500) NOT NULL DEFAULT '',
            mostrar_menu VARCHAR(20)  NOT NULL DEFAULT 'icono_texto',
            orden        INT          NOT NULL DEFAULT 0,
            activa       TINYINT(1)   NOT NULL DEFAULT 1,
            creado_en    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_juego_slug (juego_id, slug),
            KEY idx_juego (juego_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $cols = $mysqli->query("SHOW COLUMNS FROM juego_paquetes LIKE 'categoria_paquete_id'");
        if ($cols instanceof mysqli_result && $cols->num_rows === 0) {
            $mysqli->query("ALTER TABLE juego_paquetes ADD COLUMN categoria_paquete_id INT NULL AFTER orden");
        }

        $colsTexto = $mysqli->query("SHOW COLUMNS FROM paquete_categorias LIKE 'color_texto'");
        if ($colsTexto instanceof mysqli_result && $colsTexto->num_rows === 0) {
            $mysqli->query("ALTER TABLE paquete_categorias ADD COLUMN color_texto VARCHAR(20) NOT NULL DEFAULT '' AFTER color");
        }
    }
}

// ─── Helpers ───────────────────────────────────────────────────────────────

if (!function_exists('package_category_slugify')) {
    function package_category_slugify(string $text): string {
        $text = mb_strtolower(trim($text), 'UTF-8');
        $map  = ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n',
                 'à'=>'a','è'=>'e','ì'=>'i','ò'=>'o','ù'=>'u','â'=>'a','ê'=>'e',
                 'î'=>'i','ô'=>'o','û'=>'u','ä'=>'a','ë'=>'e','ï'=>'i','ö'=>'o'];
        $text = strtr($text, $map);
        $text = preg_replace('/[^a-z0-9]+/', '-', $text);
        return trim((string) $text, '-');
    }
}

if (!function_exists('package_category_slug_exists')) {
    function package_category_slug_exists(mysqli $mysqli, int $juegoId, string $slug, int $excludeId = 0): bool {
        $stmt = $mysqli->prepare('SELECT id FROM paquete_categorias WHERE juego_id = ? AND slug = ? AND id != ?');
        if (!$stmt) return false;
        $stmt->bind_param('isi', $juegoId, $slug, $excludeId);
        $stmt->execute();
        $exists = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        return $exists;
    }
}

if (!function_exists('package_category_unique_slug')) {
    function package_category_unique_slug(mysqli $mysqli, int $juegoId, string $nombre, int $excludeId = 0): string {
        $base = package_category_slugify($nombre);
        $slug = $base;
        $n    = 2;
        while (package_category_slug_exists($mysqli, $juegoId, $slug, $excludeId)) {
            $slug = $base . '-' . $n++;
        }
        return $slug;
    }
}

if (!function_exists('package_category_normalize')) {
    function package_category_normalize(array $row): array {
        return [
            'id'           => (int) $row['id'],
            'juego_id'     => (int) $row['juego_id'],
            'nombre'       => trim((string) $row['nombre']),
            'slug'         => trim((string) $row['slug']),
            'icono'        => trim((string) ($row['icono'] ?? '')),
            'color'        => trim((string) ($row['color'] ?? '')),
            'color_texto'  => trim((string) ($row['color_texto'] ?? '')),
            'imagen'       => trim((string) ($row['imagen'] ?? '')),
            'mostrar_menu' => trim((string) ($row['mostrar_menu'] ?? 'icono_texto')),
            'orden'        => (int) ($row['orden'] ?? 0),
            'activa'       => (int) ($row['activa'] ?? 1) === 1,
        ];
    }
}

// ─── CRUD de categorías ────────────────────────────────────────────────────

if (!function_exists('package_category_list')) {
    function package_category_list(mysqli $mysqli, int $juegoId, bool $onlyActive = false): array {
        package_categories_ensure_schema($mysqli);
        $where = $onlyActive ? 'AND activa = 1 ' : '';
        $stmt  = $mysqli->prepare("SELECT * FROM paquete_categorias WHERE juego_id = ? {$where}ORDER BY orden ASC, nombre ASC");
        if (!$stmt) return [];
        $stmt->bind_param('i', $juegoId);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows   = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = package_category_normalize($row);
        }
        $stmt->close();
        return $rows;
    }
}

if (!function_exists('package_category_get')) {
    function package_category_get(mysqli $mysqli, int $id): ?array {
        package_categories_ensure_schema($mysqli);
        $stmt = $mysqli->prepare('SELECT * FROM paquete_categorias WHERE id = ?');
        if (!$stmt) return null;
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ? package_category_normalize($row) : null;
    }
}

if (!function_exists('package_category_create')) {
    function package_category_create(mysqli $mysqli, int $juegoId, array $data): array {
        package_categories_ensure_schema($mysqli);
        if ($juegoId <= 0) {
            return ['ok' => false, 'message' => 'Juego inválido.'];
        }
        $nombre = trim((string) ($data['nombre'] ?? ''));
        if ($nombre === '') {
            return ['ok' => false, 'message' => 'El nombre de la categoría es obligatorio.'];
        }
        $slug = trim((string) ($data['slug'] ?? ''));
        if ($slug === '') {
            $slug = package_category_unique_slug($mysqli, $juegoId, $nombre);
        } else {
            $slug = package_category_slugify($slug);
            if ($slug === '') {
                return ['ok' => false, 'message' => 'El slug generado está vacío.'];
            }
            if (package_category_slug_exists($mysqli, $juegoId, $slug)) {
                return ['ok' => false, 'message' => "El slug '{$slug}' ya está en uso en este juego."];
            }
        }
        $icono       = trim((string) ($data['icono'] ?? ''));
        $color       = trim((string) ($data['color'] ?? ''));
        $colorTexto  = trim((string) ($data['color_texto'] ?? ''));
        $imagen      = trim((string) ($data['imagen'] ?? ''));
        $mostrarMenu = in_array($data['mostrar_menu'] ?? '', ['icono', 'texto', 'icono_texto'], true)
                       ? $data['mostrar_menu'] : 'icono_texto';
        $orden       = max(0, (int) ($data['orden'] ?? 0));
        $activa      = isset($data['activa']) && (int) $data['activa'] === 0 ? 0 : 1;

        $stmt = $mysqli->prepare(
            'INSERT INTO paquete_categorias (juego_id, nombre, slug, icono, color, color_texto, imagen, mostrar_menu, orden, activa)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        if (!$stmt) return ['ok' => false, 'message' => 'Error interno de base de datos.'];
        $stmt->bind_param('isssssssii', $juegoId, $nombre, $slug, $icono, $color, $colorTexto, $imagen, $mostrarMenu, $orden, $activa);
        $ok         = $stmt->execute();
        $insertedId = (int) $mysqli->insert_id;
        $stmt->close();

        if (!$ok || $insertedId === 0) {
            return ['ok' => false, 'message' => 'No se pudo crear la categoría.'];
        }
        return ['ok' => true, 'id' => $insertedId, 'slug' => $slug];
    }
}

if (!function_exists('package_category_update')) {
    function package_category_update(mysqli $mysqli, int $id, array $data): array {
        package_categories_ensure_schema($mysqli);
        if ($id <= 0) return ['ok' => false, 'message' => 'ID inválido.'];
        $existing = package_category_get($mysqli, $id);
        if (!$existing) return ['ok' => false, 'message' => 'Categoría no encontrada.'];
        $juegoId = $existing['juego_id'];

        $nombre = trim((string) ($data['nombre'] ?? $existing['nombre']));
        if ($nombre === '') return ['ok' => false, 'message' => 'El nombre es obligatorio.'];

        $slug = trim((string) ($data['slug'] ?? ''));
        if ($slug === '') {
            $slug = package_category_unique_slug($mysqli, $juegoId, $nombre, $id);
        } else {
            $slug = package_category_slugify($slug);
            if ($slug === '') return ['ok' => false, 'message' => 'El slug generado está vacío.'];
            if (package_category_slug_exists($mysqli, $juegoId, $slug, $id)) {
                return ['ok' => false, 'message' => "El slug '{$slug}' ya está en uso en este juego."];
            }
        }
        $icono       = trim((string) ($data['icono'] ?? $existing['icono']));
        $color       = trim((string) ($data['color'] ?? $existing['color']));
        $colorTexto  = trim((string) ($data['color_texto'] ?? $existing['color_texto']));
        $imagen      = array_key_exists('imagen', $data) ? trim((string) $data['imagen']) : $existing['imagen'];
        $mostrarMenu = isset($data['mostrar_menu']) && in_array($data['mostrar_menu'], ['icono', 'texto', 'icono_texto'], true)
                       ? $data['mostrar_menu'] : (isset($data['mostrar_menu']) ? 'icono_texto' : $existing['mostrar_menu']);
        $orden       = isset($data['orden']) ? max(0, (int) $data['orden']) : $existing['orden'];
        $activa      = isset($data['activa']) ? ((int) $data['activa'] === 0 ? 0 : 1) : (int) $existing['activa'];

        $stmt = $mysqli->prepare(
            'UPDATE paquete_categorias
             SET nombre=?, slug=?, icono=?, color=?, color_texto=?, imagen=?, mostrar_menu=?, orden=?, activa=?
             WHERE id=?'
        );
        if (!$stmt) return ['ok' => false, 'message' => 'Error interno de base de datos.'];
        $stmt->bind_param('sssssssiii', $nombre, $slug, $icono, $color, $colorTexto, $imagen, $mostrarMenu, $orden, $activa, $id);
        $ok = $stmt->execute();
        $stmt->close();

        return $ok
            ? ['ok' => true, 'slug' => $slug]
            : ['ok' => false, 'message' => 'No se pudo actualizar la categoría.'];
    }
}

if (!function_exists('package_category_packages_count')) {
    function package_category_packages_count(mysqli $mysqli, int $id): int {
        $stmt = $mysqli->prepare('SELECT COUNT(*) FROM juego_paquetes WHERE categoria_paquete_id = ?');
        if (!$stmt) return 0;
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->bind_result($count);
        $stmt->fetch();
        $stmt->close();
        return (int) $count;
    }
}

if (!function_exists('package_category_delete')) {
    function package_category_delete(mysqli $mysqli, int $id): array {
        package_categories_ensure_schema($mysqli);
        if ($id <= 0) return ['ok' => false, 'message' => 'ID inválido.'];

        $toDelete = package_category_get($mysqli, $id);
        if (!$toDelete) return ['ok' => false, 'message' => 'Categoría no encontrada.'];

        $assignedCount = package_category_packages_count($mysqli, $id);
        if ($assignedCount > 0) {
            return ['ok' => false, 'message' => "No se puede eliminar: la categoría tiene {$assignedCount} paquete(s) asignado(s). Reasigna esos paquetes primero."];
        }

        $stmt = $mysqli->prepare('DELETE FROM paquete_categorias WHERE id = ?');
        if (!$stmt) return ['ok' => false, 'message' => 'Error interno de base de datos.'];
        $stmt->bind_param('i', $id);
        $ok       = $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();

        if ($ok && $affected > 0) {
            if ($toDelete['imagen'] !== '' && function_exists('tenant_resolve_public_path')) {
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

// ─── Asignación de categoría a un paquete (1:1) ────────────────────────────

if (!function_exists('package_set_category')) {
    /** Asigna (o quita, con categoryId <= 0) la categoría de un paquete. */
    function package_set_category(mysqli $mysqli, int $paqueteId, int $categoryId): bool {
        package_categories_ensure_schema($mysqli);
        $value = $categoryId > 0 ? $categoryId : null;
        $stmt  = $mysqli->prepare('UPDATE juego_paquetes SET categoria_paquete_id = ? WHERE id = ?');
        if (!$stmt) return false;
        $stmt->bind_param('ii', $value, $paqueteId);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }
}

if (!function_exists('package_categories_for_game')) {
    /** Alias explícito de package_category_list para uso en el storefront. */
    function package_categories_for_game(mysqli $mysqli, int $juegoId, bool $onlyActive = true): array {
        return package_category_list($mysqli, $juegoId, $onlyActive);
    }
}
