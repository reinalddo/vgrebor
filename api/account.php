<?php
require_once __DIR__ . '/../includes/tenant.php';
tenant_start_session();
header('Content-Type: application/json');

require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/currency.php';
require_once __DIR__ . '/../includes/win_points.php';

currency_ensure_schema();

function account_json_error(string $message, int $status = 400): void {
    http_response_code($status);
    echo json_encode(['ok' => false, 'message' => $message]);
    exit;
}

function account_json_ok(array $payload = []): void {
    echo json_encode(array_merge(['ok' => true], $payload));
    exit;
}

function account_ensure_user_session(): array {
    $user = $_SESSION['auth_user'] ?? null;
    if (!is_array($user) || empty($user['id'])) {
        account_json_error('Debes iniciar sesión para continuar.', 401);
    }
    return $user;
}

function account_pedidos_has_owner_column(mysqli $mysqli): bool {
    $result = $mysqli->query("SHOW COLUMNS FROM pedidos LIKE 'cliente_usuario_id'");
    return $result && $result->num_rows > 0;
}

function account_pedidos_table_exists(mysqli $mysqli): bool {
    $result = $mysqli->query("SHOW TABLES LIKE 'pedidos'");
    return $result && $result->num_rows > 0;
}

function account_pedidos_has_purchase_quantity_column(mysqli $mysqli): bool {
    $result = $mysqli->query("SHOW COLUMNS FROM pedidos LIKE 'cantidad_compra'");
    return $result && $result->num_rows > 0;
}

function account_profile_image_public_url(string $path): string {
    $relativePath = trim($path);
    if ($relativePath === '') {
        return '';
    }

    if (preg_match('#^(?:https?:)?//#i', $relativePath) === 1 || str_starts_with($relativePath, 'data:')) {
        return $relativePath;
    }

    $url = app_path($relativePath);
    $absolutePath = tenant_resolve_public_path($relativePath);
    if ($absolutePath !== null && is_file($absolutePath)) {
        $url .= '?v=' . rawurlencode((string) filemtime($absolutePath));
    }

    return $url;
}

function account_delete_profile_image_file(string $relativePath): void {
    $path = trim($relativePath);
    if ($path === '' || !tenant_is_managed_path($path, 'users')) {
        return;
    }

    $absolutePath = tenant_resolve_public_path($path);
    if ($absolutePath !== null && is_file($absolutePath)) {
        @unlink($absolutePath);
    }
}

function account_store_profile_image_upload(array $file): array {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['success' => true, 'path' => ''];
    }

    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        return ['success' => false, 'message' => 'No se pudo cargar la imagen de perfil.'];
    }

    $tmpName = (string) ($file['tmp_name'] ?? '');
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        return ['success' => false, 'message' => 'El archivo de perfil no es válido.'];
    }

    if (($file['size'] ?? 0) > 2 * 1024 * 1024) {
        return ['success' => false, 'message' => 'La imagen de perfil no puede superar 2 MB.'];
    }

    $imageInfo = @getimagesize($tmpName);
    if ($imageInfo === false) {
        return ['success' => false, 'message' => 'Debes subir una imagen válida para el perfil.'];
    }

    $mime = (string) ($imageInfo['mime'] ?? '');
    $extensions = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];
    if (!isset($extensions[$mime])) {
        return ['success' => false, 'message' => 'Formato no permitido. Usa JPG, PNG, WEBP o GIF.'];
    }

    $targetDir = tenant_upload_absolute_dir('users');
    if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
        return ['success' => false, 'message' => 'No se pudo crear la carpeta de la imagen de perfil.'];
    }

    $fileName = 'profile-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $extensions[$mime];
    $targetPath = $targetDir . DIRECTORY_SEPARATOR . $fileName;
    if (!move_uploaded_file($tmpName, $targetPath)) {
        return ['success' => false, 'message' => 'No se pudo guardar la imagen de perfil en el servidor.'];
    }

    $publicPath = tenant_upload_public_path('users', $fileName, true);
    return [
        'success' => true,
        'path' => $publicPath,
        'url' => account_profile_image_public_url($publicPath),
    ];
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';
if ($action === '') {
    account_json_error('Acción no especificada.', 422);
}

$authUser = account_ensure_user_session();
$authUserId = (int) ($authUser['id'] ?? 0);
$authUserEmail = trim((string) ($authUser['email'] ?? ''));

if ($action === 'orders') {
    $orders = [];
    if (!account_pedidos_table_exists($mysqli)) {
        account_json_ok(['orders' => []]);
    }
    $hasOwnerColumn = account_pedidos_has_owner_column($mysqli);
    $purchaseQuantitySelect = account_pedidos_has_purchase_quantity_column($mysqli) ? 'cantidad_compra' : '1 AS cantidad_compra';

    if ($hasOwnerColumn) {
        $stmt = $mysqli->prepare(
            "SELECT id, juego_nombre, paquete_nombre, paquete_cantidad, {$purchaseQuantitySelect}, moneda, precio, email, estado, creado_en
             FROM pedidos
             WHERE cliente_usuario_id = ?
                OR (cliente_usuario_id IS NULL AND email = ?)
             ORDER BY creado_en DESC, id DESC"
        );
        if (!$stmt) {
            account_json_error('No se pudo consultar el historial de pedidos.', 500);
        }
        $stmt->bind_param('is', $authUserId, $authUserEmail);
    } else {
        $stmt = $mysqli->prepare(
            "SELECT id, juego_nombre, paquete_nombre, paquete_cantidad, {$purchaseQuantitySelect}, moneda, precio, email, estado, creado_en
             FROM pedidos
             WHERE email = ?
             ORDER BY creado_en DESC, id DESC"
        );
        if (!$stmt) {
            account_json_error('No se pudo consultar el historial de pedidos.', 500);
        }
        $stmt->bind_param('s', $authUserEmail);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $orders[] = [
                'id' => (int) ($row['id'] ?? 0),
                'juego_nombre' => (string) ($row['juego_nombre'] ?? ''),
                'paquete_nombre' => (string) ($row['paquete_nombre'] ?? ''),
                'paquete_cantidad' => (string) ($row['paquete_cantidad'] ?? ''),
                'cantidad_compra' => max(1, (int) ($row['cantidad_compra'] ?? 1)),
                'moneda' => (string) ($row['moneda'] ?? ''),
                'precio' => currency_format_amount_by_code((float) ($row['precio'] ?? 0), (string) ($row['moneda'] ?? '')),
                'email' => (string) ($row['email'] ?? ''),
                'estado' => (string) ($row['estado'] ?? 'pendiente'),
                'creado_en' => (string) ($row['creado_en'] ?? ''),
            ];
        }
    }
    $stmt->close();

    account_json_ok(['orders' => $orders]);
}

if ($action === 'rewards') {
    $config = win_points_config();
    if (!$config['enabled']) {
        account_json_ok([
            'enabled' => false,
            'config' => $config,
            'summary' => win_points_empty_user_summary(),
            'transactions' => [],
        ]);
    }

    account_json_ok([
        'enabled' => true,
        'config' => $config,
        'summary' => win_points_fetch_user_summary($mysqli, $authUserId),
        'transactions' => win_points_fetch_user_transactions($mysqli, $authUserId, 50),
    ]);
}

if ($action === 'update_profile') {
    $name = trim((string) ($_POST['name'] ?? ''));
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $phone = substr(trim((string) ($_POST['phone'] ?? '')), 0, 50);
    $password = (string) ($_POST['password'] ?? '');
    $passwordConfirm = (string) ($_POST['password_confirm'] ?? '');

    if ($name === '' || $email === '') {
        account_json_error('Nombre y correo son obligatorios.');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        account_json_error('El correo electrónico no es válido.');
    }
    if ($password !== '' && strlen($password) < 6) {
        account_json_error('La nueva contraseña debe tener al menos 6 caracteres.');
    }
    if ($password !== $passwordConfirm) {
        account_json_error('La confirmación de contraseña no coincide.');
    }

    $currentProfileImage = '';
    $currentStmt = $mysqli->prepare('SELECT foto_perfil FROM usuarios WHERE id = ? LIMIT 1');
    if ($currentStmt) {
        $currentStmt->bind_param('i', $authUserId);
        if ($currentStmt->execute()) {
            $currentResult = $currentStmt->get_result();
            $currentRow = $currentResult ? $currentResult->fetch_assoc() : null;
            $currentProfileImage = trim((string) ($currentRow['foto_perfil'] ?? ''));
        }
        $currentStmt->close();
    }

    $nextProfileImage = $currentProfileImage;
    $uploadedProfileImage = isset($_FILES['profile_image']) ? account_store_profile_image_upload($_FILES['profile_image']) : ['success' => true, 'path' => ''];
    if (empty($uploadedProfileImage['success'])) {
        account_json_error((string) ($uploadedProfileImage['message'] ?? 'No se pudo cargar la imagen de perfil.'));
    }
    if (!empty($uploadedProfileImage['path'])) {
        $nextProfileImage = (string) $uploadedProfileImage['path'];
    }

    $dupStmt = $mysqli->prepare('SELECT id FROM usuarios WHERE email = ? AND id <> ? LIMIT 1');
    if (!$dupStmt) {
        account_json_error('No se pudo validar el correo.', 500);
    }
    $dupStmt->bind_param('si', $email, $authUserId);
    $dupStmt->execute();
    $duplicate = $dupStmt->get_result();
    if ($duplicate && $duplicate->fetch_assoc()) {
        $dupStmt->close();
        account_json_error('Ese correo ya está registrado por otro usuario.');
    }
    $dupStmt->close();

    if ($password !== '') {
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $mysqli->prepare('UPDATE usuarios SET nombre = ?, email = ?, telefono = ?, foto_perfil = ?, username = ?, password = ? WHERE id = ? LIMIT 1');
        if (!$stmt) {
            account_json_error('No se pudo actualizar el usuario.', 500);
        }
        $stmt->bind_param('ssssssi', $name, $email, $phone, $nextProfileImage, $email, $passwordHash, $authUserId);
    } else {
        $stmt = $mysqli->prepare('UPDATE usuarios SET nombre = ?, email = ?, telefono = ?, foto_perfil = ?, username = ? WHERE id = ? LIMIT 1');
        if (!$stmt) {
            account_json_error('No se pudo actualizar el usuario.', 500);
        }
        $stmt->bind_param('sssssi', $name, $email, $phone, $nextProfileImage, $email, $authUserId);
    }

    if (!$stmt->execute()) {
        $stmt->close();
        if (!empty($uploadedProfileImage['path']) && $nextProfileImage !== $currentProfileImage) {
            account_delete_profile_image_file($nextProfileImage);
        }
        account_json_error('No se pudieron guardar los datos del usuario.', 500);
    }
    $stmt->close();

    if (!empty($uploadedProfileImage['path']) && $currentProfileImage !== '' && $currentProfileImage !== $nextProfileImage) {
        account_delete_profile_image_file($currentProfileImage);
    }

    $_SESSION['auth_user']['email'] = $email;
    $_SESSION['auth_user']['telefono'] = $phone;
    $_SESSION['auth_user']['full_name'] = $name;
    $_SESSION['auth_user']['username'] = $email;
    $_SESSION['auth_user']['foto_perfil'] = $nextProfileImage;

    account_json_ok([
        'message' => 'Datos de usuario actualizados correctamente.',
        'user' => [
            'id' => $authUserId,
            'email' => $email,
            'phone' => $phone,
            'full_name' => $name,
            'profile_image' => $nextProfileImage,
            'profile_image_url' => account_profile_image_public_url($nextProfileImage),
            'rol' => (string) ($_SESSION['auth_user']['rol'] ?? 'usuario'),
        ],
    ]);
}

account_json_error('Acción no soportada.', 422);
?>