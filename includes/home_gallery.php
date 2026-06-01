<?php

require_once __DIR__ . '/tenant.php';

function home_gallery_db(): mysqli {
    global $mysqli;

    if (!isset($mysqli) || !($mysqli instanceof mysqli)) {
        require_once __DIR__ . '/db_connect.php';
    }

    return $mysqli;
}

function home_gallery_ensure_table(): void {
    static $initialized = false;

    if ($initialized) {
        return;
    }

    $mysqli = home_gallery_db();
    $sql = <<<'SQL'
CREATE TABLE IF NOT EXISTS home_gallery (
    id INT AUTO_INCREMENT PRIMARY KEY,
    titulo VARCHAR(160) NOT NULL,
    descripcion1 VARCHAR(255) NOT NULL,
    descripcion2 VARCHAR(255) NOT NULL,
    imagen VARCHAR(255) NOT NULL,
    url VARCHAR(500) DEFAULT NULL,
    abrir_nueva_pestana TINYINT(1) NOT NULL DEFAULT 0,
    orden INT NULL,
    destacado TINYINT(1) NOT NULL DEFAULT 0,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    actualizado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_home_gallery_destacado (destacado),
    INDEX idx_home_gallery_creado (creado_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;
    $mysqli->query($sql);
    $columnResult = $mysqli->query("SHOW COLUMNS FROM home_gallery LIKE 'orden'");
    if (!($columnResult instanceof mysqli_result) || $columnResult->num_rows === 0) {
        $mysqli->query("ALTER TABLE home_gallery ADD COLUMN orden INT NULL AFTER abrir_nueva_pestana");
    }
    $initialized = true;
}

function home_gallery_next_order(): int {
    home_gallery_ensure_table();

    $mysqli = home_gallery_db();
    $res = $mysqli->query('SELECT COALESCE(MAX(orden), 0) + 1 AS next_order FROM home_gallery');
    $row = $res instanceof mysqli_result ? $res->fetch_assoc() : null;
    return max(1, (int) ($row['next_order'] ?? 1));
}

function home_gallery_all(): array {
    home_gallery_ensure_table();

    $mysqli = home_gallery_db();
    $items = [];
    $res = $mysqli->query('SELECT * FROM home_gallery ORDER BY CASE WHEN orden IS NULL THEN 1 ELSE 0 END, orden ASC, destacado DESC, id ASC');
    if ($res instanceof mysqli_result) {
        while ($row = $res->fetch_assoc()) {
            $row['id'] = (int) $row['id'];
            $row['abrir_nueva_pestana'] = (int) $row['abrir_nueva_pestana'];
            $row['orden'] = isset($row['orden']) ? (int) $row['orden'] : null;
            $row['destacado'] = (int) $row['destacado'];
            $items[] = $row;
        }
    }

    return $items;
}

function home_gallery_find(int $id): ?array {
    home_gallery_ensure_table();

    $mysqli = home_gallery_db();
    $stmt = $mysqli->prepare('SELECT * FROM home_gallery WHERE id = ? LIMIT 1');
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('i', $id);
    $stmt->execute();
    $res = $stmt->get_result();
    $item = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    if (!$item) {
        return null;
    }

    $item['id'] = (int) $item['id'];
    $item['abrir_nueva_pestana'] = (int) $item['abrir_nueva_pestana'];
    $item['orden'] = isset($item['orden']) ? (int) $item['orden'] : null;
    $item['destacado'] = (int) $item['destacado'];

    return $item;
}

function home_gallery_featured(): ?array {
    home_gallery_ensure_table();

    $mysqli = home_gallery_db();
    $res = $mysqli->query('SELECT * FROM home_gallery WHERE destacado = 1 ORDER BY CASE WHEN orden IS NULL THEN 1 ELSE 0 END, orden ASC, id ASC LIMIT 1');
    $item = $res instanceof mysqli_result ? $res->fetch_assoc() : null;

    if (!$item) {
        return null;
    }

    $item['id'] = (int) $item['id'];
    $item['abrir_nueva_pestana'] = (int) $item['abrir_nueva_pestana'];
    $item['orden'] = isset($item['orden']) ? (int) $item['orden'] : null;
    $item['destacado'] = (int) $item['destacado'];

    return $item;
}

function home_gallery_is_managed_image_path(string $relativePath): bool {
    return tenant_is_managed_path($relativePath, 'gallery');
}

function home_gallery_delete_image_file(string $relativePath): void {
    if ($relativePath === '' || !home_gallery_is_managed_image_path($relativePath)) {
        return;
    }

    $absolutePath = tenant_resolve_public_path($relativePath);
    if ($absolutePath !== null && is_file($absolutePath)) {
        @unlink($absolutePath);
    }
}

function home_gallery_store_image_upload(array $file): array {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['success' => true, 'path' => ''];
    }

    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        return ['success' => false, 'message' => 'No se pudo cargar la imagen de galería.'];
    }

    $tmpName = $file['tmp_name'] ?? '';
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        return ['success' => false, 'message' => 'El archivo de galería no es válido.'];
    }

    if (($file['size'] ?? 0) > 4 * 1024 * 1024) {
        return ['success' => false, 'message' => 'La imagen de galería no puede superar 4 MB.'];
    }

    $imageInfo = @getimagesize($tmpName);
    if ($imageInfo === false) {
        return ['success' => false, 'message' => 'La imagen de galería debe ser una imagen válida.'];
    }

    $mime = $imageInfo['mime'] ?? '';
    $extensions = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];

    if (!isset($extensions[$mime])) {
        return ['success' => false, 'message' => 'Formato de imagen no permitido. Usa JPG, PNG, WEBP o GIF.'];
    }

    $targetDir = tenant_upload_absolute_dir('gallery');
    if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
        return ['success' => false, 'message' => 'No se pudo crear la carpeta de galería.'];
    }

    $fileName = 'gallery-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $extensions[$mime];
    $targetPath = $targetDir . DIRECTORY_SEPARATOR . $fileName;

    if (!move_uploaded_file($tmpName, $targetPath)) {
        return ['success' => false, 'message' => 'No se pudo guardar la imagen de galería en el servidor.'];
    }

    return ['success' => true, 'path' => tenant_upload_public_path('gallery', $fileName, true)];
}

function home_gallery_validate_form(array $input, array $files, ?array $existing = null): array {
    $titulo = trim((string) ($input['titulo'] ?? ''));
    $descripcion1 = trim((string) ($input['descripcion1'] ?? ''));
    $descripcion2 = trim((string) ($input['descripcion2'] ?? ''));
    $url = trim((string) ($input['url'] ?? ''));
    $abrirNuevaPestana = isset($input['abrir_nueva_pestana']) && (string) $input['abrir_nueva_pestana'] === '1' ? 1 : 0;
    $ordenInput = trim((string) ($input['orden'] ?? ''));
    $destacado = isset($input['destacado']) ? 1 : 0;
    $errores = [];
    $orden = $ordenInput !== '' ? max(1, (int) $ordenInput) : (isset($existing['orden']) && (int) $existing['orden'] > 0 ? (int) $existing['orden'] : home_gallery_next_order());

    if ($url !== '' && filter_var($url, FILTER_VALIDATE_URL) === false) {
        $errores[] = 'La URL debe ser válida.';
    }

    $imagenActual = $existing['imagen'] ?? '';
    $nuevaImagen = $imagenActual;
    $hasUpload = isset($files['imagen']) && (($files['imagen']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE);

    if ($hasUpload) {
        $upload = home_gallery_store_image_upload($files['imagen']);
        if (!$upload['success']) {
            $errores[] = $upload['message'];
        } elseif (!empty($upload['path'])) {
            $nuevaImagen = $upload['path'];
        }
    }

    if ($nuevaImagen === '') {
        $errores[] = 'La imagen es obligatoria.';
    }

    return [
        'is_valid' => empty($errores),
        'errors' => $errores,
        'data' => [
            'titulo' => $titulo,
            'descripcion1' => $descripcion1,
            'descripcion2' => $descripcion2,
            'imagen' => $nuevaImagen,
            'url' => $url,
            'abrir_nueva_pestana' => $abrirNuevaPestana,
            'orden' => $orden,
            'destacado' => $destacado,
        ],
        'replaced_image' => $hasUpload ? $imagenActual : '',
    ];
}

function home_gallery_update_order(int $id, int $orden): bool {
    home_gallery_ensure_table();

    $mysqli = home_gallery_db();
    $stmt = $mysqli->prepare('UPDATE home_gallery SET orden = ? WHERE id = ?');
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('ii', $orden, $id);
    $ok = $stmt->execute();
    $stmt->close();

    return $ok;
}

function home_gallery_save(array $data, ?int $id = null): bool {
    home_gallery_ensure_table();

    $mysqli = home_gallery_db();
    $orden = isset($data['orden']) ? max(1, (int) $data['orden']) : home_gallery_next_order();
    if (!empty($data['destacado'])) {
        $mysqli->query('UPDATE home_gallery SET destacado = 0 WHERE destacado = 1');
    }

    if ($id === null) {
        $stmt = $mysqli->prepare('INSERT INTO home_gallery (titulo, descripcion1, descripcion2, imagen, url, abrir_nueva_pestana, orden, destacado) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param(
            'sssssiii',
            $data['titulo'],
            $data['descripcion1'],
            $data['descripcion2'],
            $data['imagen'],
            $data['url'],
            $data['abrir_nueva_pestana'],
            $orden,
            $data['destacado']
        );
    } else {
        $stmt = $mysqli->prepare('UPDATE home_gallery SET titulo = ?, descripcion1 = ?, descripcion2 = ?, imagen = ?, url = ?, abrir_nueva_pestana = ?, orden = ?, destacado = ? WHERE id = ?');
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param(
            'sssssiiii',
            $data['titulo'],
            $data['descripcion1'],
            $data['descripcion2'],
            $data['imagen'],
            $data['url'],
            $data['abrir_nueva_pestana'],
            $orden,
            $data['destacado'],
            $id
        );
    }

    $ok = $stmt->execute();
    $stmt->close();

    return $ok;
}

function home_gallery_delete(int $id): bool {
    home_gallery_ensure_table();
    $existing = home_gallery_find($id);
    if ($existing === null) {
        return false;
    }

    $mysqli = home_gallery_db();
    $stmt = $mysqli->prepare('DELETE FROM home_gallery WHERE id = ?');
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('i', $id);
    $ok = $stmt->execute();
    $stmt->close();

    if ($ok) {
        home_gallery_delete_image_file((string) $existing['imagen']);
    }

    return $ok;
}
