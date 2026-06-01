<?php

function payment_methods_db(): mysqli {
    global $mysqli;

    if (!isset($mysqli) || !($mysqli instanceof mysqli)) {
        require_once __DIR__ . '/db_connect.php';
    }

    return $mysqli;
}

function payment_methods_ensure_table(): void {
    static $initialized = false;

    if ($initialized) {
        return;
    }

    $mysqli = payment_methods_db();
    $sql = <<<'SQL'
CREATE TABLE IF NOT EXISTS payment_methods (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(160) NOT NULL,
    datos TEXT NOT NULL,
    image_path VARCHAR(255) NOT NULL DEFAULT '',
    qr_image_path VARCHAR(255) NOT NULL DEFAULT '',
    corner_image_path VARCHAR(255) NOT NULL DEFAULT '',
    moneda_id INT NULL,
    referencia_digitos INT NOT NULL DEFAULT 0,
    descuento_porcentaje DECIMAL(5,2) NOT NULL DEFAULT 0,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    actualizado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_payment_methods_activo (activo),
    INDEX idx_payment_methods_nombre (nombre),
    INDEX idx_payment_methods_moneda_id (moneda_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;
    $mysqli->query($sql);

    $columns = [];
    $columnResult = $mysqli->query('SHOW COLUMNS FROM payment_methods');
    if ($columnResult instanceof mysqli_result) {
        while ($column = $columnResult->fetch_assoc()) {
            $columns[$column['Field']] = true;
        }
    }

    if (!isset($columns['moneda_id'])) {
        $mysqli->query('ALTER TABLE payment_methods ADD COLUMN moneda_id INT NULL AFTER datos');
    }
    if (!isset($columns['image_path'])) {
        $mysqli->query("ALTER TABLE payment_methods ADD COLUMN image_path VARCHAR(255) NOT NULL DEFAULT '' AFTER datos");
    }
    if (!isset($columns['qr_image_path'])) {
        $mysqli->query("ALTER TABLE payment_methods ADD COLUMN qr_image_path VARCHAR(255) NOT NULL DEFAULT '' AFTER image_path");
    }
    if (!isset($columns['corner_image_path'])) {
        $mysqli->query("ALTER TABLE payment_methods ADD COLUMN corner_image_path VARCHAR(255) NOT NULL DEFAULT '' AFTER qr_image_path");
    }
    if (!isset($columns['referencia_digitos'])) {
        $mysqli->query('ALTER TABLE payment_methods ADD COLUMN referencia_digitos INT NOT NULL DEFAULT 0 AFTER moneda_id');
    }
    if (!isset($columns['descuento_porcentaje'])) {
        $mysqli->query('ALTER TABLE payment_methods ADD COLUMN descuento_porcentaje DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER referencia_digitos');
    }

    $hasCurrencyIndex = false;
    $indexResult = $mysqli->query("SHOW INDEX FROM payment_methods WHERE Key_name = 'idx_payment_methods_moneda_id'");
    if ($indexResult instanceof mysqli_result) {
        $hasCurrencyIndex = $indexResult->num_rows > 0;
    }
    if (!$hasCurrencyIndex) {
        $mysqli->query('ALTER TABLE payment_methods ADD INDEX idx_payment_methods_moneda_id (moneda_id)');
    }

    $initialized = true;
}

function payment_methods_storage_relative_dir(): string {
    return 'assets/img/payment-methods';
}

function payment_methods_storage_absolute_dir(): string {
    return dirname(__DIR__) . '/' . str_replace('\\', '/', payment_methods_storage_relative_dir());
}

function payment_methods_ensure_storage_dir(): string {
    $dir = payment_methods_storage_absolute_dir();
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    return $dir;
}

function payment_methods_is_valid_image_upload(array $file): bool {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return false;
    }

    $tmpName = (string) ($file['tmp_name'] ?? '');
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        return false;
    }

    $imageInfo = @getimagesize($tmpName);
    if (!is_array($imageInfo) || empty($imageInfo['mime'])) {
        return false;
    }

    return in_array(strtolower((string) $imageInfo['mime']), ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true);
}

function payment_methods_store_uploaded_image(array $file, string $kind = 'method'): array {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['ok' => false, 'error' => 'No se recibió la imagen.'];
    }

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'No se pudo subir la imagen del método de pago.'];
    }

    if (!payment_methods_is_valid_image_upload($file)) {
        return ['ok' => false, 'error' => 'La imagen debe ser JPG, PNG, WEBP o GIF válida.'];
    }

    $tmpName = (string) ($file['tmp_name'] ?? '');
    $extension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
    if (!in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
        $extension = 'png';
    }

    $safeKind = $kind === 'qr'
        ? 'qr'
        : ($kind === 'corner' ? 'corner' : 'method');
    $storageDir = payment_methods_ensure_storage_dir();
    $fileName = sprintf('%s_%s.%s', $safeKind, bin2hex(random_bytes(8)), $extension);
    $absolutePath = $storageDir . '/' . $fileName;
    $relativePath = payment_methods_storage_relative_dir() . '/' . $fileName;

    if (!@move_uploaded_file($tmpName, $absolutePath)) {
        return ['ok' => false, 'error' => 'No se pudo guardar la imagen del método de pago.'];
    }

    return ['ok' => true, 'path' => str_replace('\\', '/', $relativePath)];
}

function payment_methods_is_managed_asset_path(string $path): bool {
    $normalized = trim(str_replace('\\', '/', $path));
    if ($normalized === '') {
        return false;
    }

    return strpos($normalized, payment_methods_storage_relative_dir() . '/') === 0;
}

function payment_methods_delete_asset_file(string $path): void {
    if (!payment_methods_is_managed_asset_path($path)) {
        return;
    }

    $absolutePath = dirname(__DIR__) . '/' . ltrim(str_replace('\\', '/', $path), '/');
    if (is_file($absolutePath)) {
        @unlink($absolutePath);
    }
}

function payment_methods_normalize_discount_percentage($value): float {
    if ($value === null || $value === '') {
        return 0.0;
    }

    if (is_string($value)) {
        $value = str_replace(',', '.', trim($value));
    }

    if (!is_numeric($value)) {
        return 0.0;
    }

    $normalized = round((float) $value, 2);
    if ($normalized < 0) {
        return 0.0;
    }
    if ($normalized > 100) {
        return 100.0;
    }

    return $normalized;
}

function payment_methods_discount_feature_enabled(): bool {
    require_once __DIR__ . '/store_config.php';

    return trim((string) store_config_get('descuento_metodo_pago', '0')) === '1';
}

function payment_methods_currency_options(): array {
    $mysqli = payment_methods_db();
    $currencies = [];
    $res = $mysqli->query('SELECT id, nombre, clave FROM monedas ORDER BY es_base DESC, nombre ASC, id ASC');
    if ($res instanceof mysqli_result) {
        while ($row = $res->fetch_assoc()) {
            $currencies[] = [
                'id' => (int) ($row['id'] ?? 0),
                'nombre' => (string) ($row['nombre'] ?? ''),
                'clave' => (string) ($row['clave'] ?? ''),
            ];
        }
    }

    return $currencies;
}

function payment_methods_currency_exists(int $currencyId): bool {
    if ($currencyId <= 0) {
        return false;
    }

    $mysqli = payment_methods_db();
    $stmt = $mysqli->prepare('SELECT id FROM monedas WHERE id = ? LIMIT 1');
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('i', $currencyId);
    $stmt->execute();
    $res = $stmt->get_result();
    $exists = $res instanceof mysqli_result && (bool) $res->fetch_assoc();
    $stmt->close();

    return $exists;
}

function payment_methods_all(): array {
    payment_methods_ensure_table();

    $mysqli = payment_methods_db();
    $items = [];
    $res = $mysqli->query("SELECT pm.*, m.nombre AS moneda_nombre, m.clave AS moneda_clave
        FROM payment_methods pm
        LEFT JOIN monedas m ON m.id = pm.moneda_id
        ORDER BY pm.activo DESC, pm.nombre ASC, pm.id DESC");
    if ($res instanceof mysqli_result) {
        while ($row = $res->fetch_assoc()) {
            $row['id'] = (int) $row['id'];
            $row['activo'] = (int) $row['activo'];
            $row['moneda_id'] = isset($row['moneda_id']) ? (int) $row['moneda_id'] : 0;
            $row['image_path'] = trim((string) ($row['image_path'] ?? ''));
            $row['qr_image_path'] = trim((string) ($row['qr_image_path'] ?? ''));
            $row['corner_image_path'] = trim((string) ($row['corner_image_path'] ?? ''));
            $row['referencia_digitos'] = isset($row['referencia_digitos']) ? max(0, (int) $row['referencia_digitos']) : 0;
            $row['descuento_porcentaje'] = payment_methods_normalize_discount_percentage($row['descuento_porcentaje'] ?? 0);
            $items[] = $row;
        }
    }

    return $items;
}

function payment_methods_find(int $id): ?array {
    payment_methods_ensure_table();

    $mysqli = payment_methods_db();
    $stmt = $mysqli->prepare("SELECT pm.*, m.nombre AS moneda_nombre, m.clave AS moneda_clave
        FROM payment_methods pm
        LEFT JOIN monedas m ON m.id = pm.moneda_id
        WHERE pm.id = ?
        LIMIT 1");
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
    $item['activo'] = (int) $item['activo'];
    $item['moneda_id'] = isset($item['moneda_id']) ? (int) $item['moneda_id'] : 0;
    $item['image_path'] = trim((string) ($item['image_path'] ?? ''));
    $item['qr_image_path'] = trim((string) ($item['qr_image_path'] ?? ''));
    $item['corner_image_path'] = trim((string) ($item['corner_image_path'] ?? ''));
    $item['referencia_digitos'] = isset($item['referencia_digitos']) ? max(0, (int) $item['referencia_digitos']) : 0;
    $item['descuento_porcentaje'] = payment_methods_normalize_discount_percentage($item['descuento_porcentaje'] ?? 0);
    return $item;
}

function payment_methods_validate_form(array $input): array {
    $nombre = trim((string) ($input['nombre_metodo_pago'] ?? ''));
    $datos = trim((string) ($input['datos_metodo_pago'] ?? ''));
    $monedaId = isset($input['moneda_metodo_pago']) ? (int) $input['moneda_metodo_pago'] : 0;
    $referenciaDigitos = isset($input['referencia_digitos_metodo_pago']) && $input['referencia_digitos_metodo_pago'] !== ''
        ? (int) $input['referencia_digitos_metodo_pago']
        : 0;
    $discountFeatureEnabled = payment_methods_discount_feature_enabled();
    $descuentoPorcentajeRaw = trim((string) ($input['descuento_metodo_pago_porcentaje'] ?? '0'));
    $descuentoPorcentaje = $discountFeatureEnabled
        ? payment_methods_normalize_discount_percentage($descuentoPorcentajeRaw)
        : 0.0;
    $activo = isset($input['activo_metodo_pago']) ? 1 : 0;
    $errors = [];

    if ($nombre === '') {
        $errors[] = 'El nombre del método de pago es obligatorio.';
    }
    if ($datos === '') {
        $errors[] = 'Los datos del método de pago son obligatorios.';
    }
    if ($monedaId <= 0) {
        $errors[] = 'Debes seleccionar la moneda del método de pago.';
    } elseif (!payment_methods_currency_exists($monedaId)) {
        $errors[] = 'La moneda seleccionada para el método de pago no es válida.';
    }
    if ($referenciaDigitos < 0) {
        $errors[] = 'Los dígitos de referencia no pueden ser negativos.';
    }
    if ($discountFeatureEnabled && $descuentoPorcentajeRaw !== '' && !is_numeric(str_replace(',', '.', $descuentoPorcentajeRaw))) {
        $errors[] = 'El descuento del método de pago debe ser numérico.';
    }

    return [
        'is_valid' => empty($errors),
        'errors' => $errors,
        'data' => [
            'nombre' => $nombre,
            'datos' => $datos,
            'image_path' => trim((string) ($input['existing_payment_method_image_path'] ?? '')),
            'qr_image_path' => trim((string) ($input['existing_payment_method_qr_image_path'] ?? '')),
            'corner_image_path' => trim((string) ($input['existing_payment_method_corner_image_path'] ?? '')),
            'moneda_id' => $monedaId,
            'referencia_digitos' => $referenciaDigitos,
            'descuento_porcentaje' => $descuentoPorcentaje,
            'activo' => $activo,
        ],
    ];
}

function payment_methods_save(array $data, ?int $id = null): bool {
    payment_methods_ensure_table();

    $mysqli = payment_methods_db();
    if ($id === null) {
        $stmt = $mysqli->prepare('INSERT INTO payment_methods (nombre, datos, image_path, qr_image_path, corner_image_path, moneda_id, referencia_digitos, descuento_porcentaje, activo) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('sssssiidi', $data['nombre'], $data['datos'], $data['image_path'], $data['qr_image_path'], $data['corner_image_path'], $data['moneda_id'], $data['referencia_digitos'], $data['descuento_porcentaje'], $data['activo']);
    } else {
        $stmt = $mysqli->prepare('UPDATE payment_methods SET nombre = ?, datos = ?, image_path = ?, qr_image_path = ?, corner_image_path = ?, moneda_id = ?, referencia_digitos = ?, descuento_porcentaje = ?, activo = ? WHERE id = ?');
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('sssssiidii', $data['nombre'], $data['datos'], $data['image_path'], $data['qr_image_path'], $data['corner_image_path'], $data['moneda_id'], $data['referencia_digitos'], $data['descuento_porcentaje'], $data['activo'], $id);
    }

    $ok = $stmt->execute();
    $stmt->close();

    return $ok;
}

function payment_methods_delete(int $id): bool {
    payment_methods_ensure_table();

    $existing = payment_methods_find($id);
    $mysqli = payment_methods_db();
    $stmt = $mysqli->prepare('DELETE FROM payment_methods WHERE id = ?');
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('i', $id);
    $ok = $stmt->execute();
    $stmt->close();

    if ($ok && is_array($existing)) {
        payment_methods_delete_asset_file((string) ($existing['image_path'] ?? ''));
        payment_methods_delete_asset_file((string) ($existing['qr_image_path'] ?? ''));
        payment_methods_delete_asset_file((string) ($existing['corner_image_path'] ?? ''));
    }

    return $ok;
}

function payment_methods_toggle(int $id): bool {
    payment_methods_ensure_table();

    $mysqli = payment_methods_db();
    $stmt = $mysqli->prepare('UPDATE payment_methods SET activo = NOT activo WHERE id = ?');
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('i', $id);
    $ok = $stmt->execute();
    $stmt->close();

    return $ok;
}

function payment_methods_active_by_currency(): array {
    payment_methods_ensure_table();

    $mysqli = payment_methods_db();
    $items = [];
    $res = $mysqli->query("SELECT pm.id, pm.nombre, pm.datos, pm.image_path, pm.qr_image_path, pm.corner_image_path, pm.moneda_id, pm.referencia_digitos, pm.descuento_porcentaje,
        m.nombre AS moneda_nombre, m.clave AS moneda_clave
        FROM payment_methods pm
        INNER JOIN monedas m ON m.id = pm.moneda_id
        WHERE pm.activo = 1
        ORDER BY m.nombre ASC, pm.nombre ASC, pm.id ASC");
    if ($res instanceof mysqli_result) {
        while ($row = $res->fetch_assoc()) {
            $currencyKey = strtoupper(trim((string) ($row['moneda_clave'] ?? '')));
            if ($currencyKey === '') {
                continue;
            }
            if (!isset($items[$currencyKey])) {
                $items[$currencyKey] = [];
            }
            $items[$currencyKey][] = [
                'id' => (int) ($row['id'] ?? 0),
                'nombre' => (string) ($row['nombre'] ?? ''),
                'datos' => (string) ($row['datos'] ?? ''),
                'image_path' => trim((string) ($row['image_path'] ?? '')),
                'qr_image_path' => trim((string) ($row['qr_image_path'] ?? '')),
                'corner_image_path' => trim((string) ($row['corner_image_path'] ?? '')),
                'moneda_id' => (int) ($row['moneda_id'] ?? 0),
                'moneda_nombre' => (string) ($row['moneda_nombre'] ?? ''),
                'moneda_clave' => (string) ($row['moneda_clave'] ?? ''),
                'referencia_digitos' => max(0, (int) ($row['referencia_digitos'] ?? 0)),
                'descuento_porcentaje' => payment_methods_normalize_discount_percentage($row['descuento_porcentaje'] ?? 0),
            ];
        }
    }

    return $items;
}