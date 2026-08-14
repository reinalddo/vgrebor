<?php
// admin/paquete_categorias.php — AJAX endpoint para CRUD de categorías de paquetes y asignación a paquetes
require_once '../includes/db_connect.php';
require_once '../includes/tenant.php';
require_once '../includes/auth.php';
require_once '../includes/package_categories.php';

header('Content-Type: application/json; charset=utf-8');

function pc_ok(array $extra = []): void {
    echo json_encode(array_merge(['ok' => true], $extra), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function pc_err(string $message, int $status = 422): void {
    http_response_code($status);
    echo json_encode(['ok' => false, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

function pc_require_admin(): void {
    tenant_start_session();
    $user = $_SESSION['auth_user'] ?? null;
    if (!is_array($user) || !in_array($user['rol'] ?? '', ['admin', 'root'], true)) {
        pc_err('No autorizado.', 403);
    }
}

pc_require_admin();
csrf_verify_soft();

function pc_store_image(array $file): ?string {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return null;
    }
    $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
        return null;
    }
    $dir = tenant_upload_absolute_dir('categorias_paquetes');
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        return null;
    }
    $fileName    = uniqid('pcat_', true) . '.' . $ext;
    $destination = $dir . DIRECTORY_SEPARATOR . $fileName;
    if (!move_uploaded_file((string) ($file['tmp_name'] ?? ''), $destination)) {
        return null;
    }
    return tenant_upload_public_path('categorias_paquetes', $fileName, false);
}

$action = trim((string) ($_REQUEST['action'] ?? ''));

// ── Categorías de paquetes (scoped por juego) ──────────────────────────────

if ($action === 'list') {
    $juegoId = (int) ($_GET['juego_id'] ?? $_POST['juego_id'] ?? 0);
    if ($juegoId <= 0) pc_err('juego_id requerido.');
    pc_ok(['categories' => package_category_list($mysqli, $juegoId)]);
}

if ($action === 'create') {
    $juegoId = (int) ($_POST['juego_id'] ?? 0);
    if ($juegoId <= 0) pc_err('juego_id requerido.');
    $stored = pc_store_image($_FILES['imagen'] ?? []);
    if ($stored !== null) {
        $_POST['imagen'] = $stored;
    }
    $result = package_category_create($mysqli, $juegoId, $_POST);
    if (!$result['ok']) pc_err($result['message']);
    pc_ok(['id' => $result['id'], 'slug' => $result['slug']]);
}

if ($action === 'update') {
    $id = (int) ($_POST['id'] ?? $_GET['id'] ?? 0);
    if ($id <= 0) pc_err('ID de categoría requerido.');
    $existing = package_category_get($mysqli, $id);
    if (!$existing) pc_err('Categoría no encontrada.', 404);
    $stored = pc_store_image($_FILES['imagen'] ?? []);
    if ($stored !== null) {
        if ($existing['imagen'] !== '') {
            $abs = tenant_resolve_public_path($existing['imagen']);
            if ($abs !== null && is_file($abs)) {
                @unlink($abs);
            }
        }
        $_POST['imagen'] = $stored;
    } elseif (!empty($_POST['remove_imagen']) && (int) $_POST['remove_imagen'] === 1) {
        if ($existing['imagen'] !== '') {
            $abs = tenant_resolve_public_path($existing['imagen']);
            if ($abs !== null && is_file($abs)) {
                @unlink($abs);
            }
        }
        $_POST['imagen'] = '';
    } else {
        unset($_POST['imagen']);
    }
    $result = package_category_update($mysqli, $id, $_POST);
    if (!$result['ok']) pc_err($result['message']);
    pc_ok(['slug' => $result['slug']]);
}

if ($action === 'delete') {
    $id = (int) ($_POST['id'] ?? $_GET['id'] ?? 0);
    if ($id <= 0) pc_err('ID de categoría requerido.');
    $result = package_category_delete($mysqli, $id);
    if (!$result['ok']) pc_err($result['message'], 404);
    pc_ok();
}

// ── Asignación de categoría a un paquete ───────────────────────────────────

if ($action === 'set_package_category') {
    $paqueteId = (int) ($_POST['paquete_id'] ?? 0);
    $categoryId = (int) ($_POST['category_id'] ?? 0);
    if ($paqueteId <= 0) pc_err('paquete_id requerido.');
    if (!package_set_category($mysqli, $paqueteId, $categoryId)) {
        pc_err('No se pudo asignar la categoría al paquete.');
    }
    pc_ok();
}

pc_err('Acción no reconocida.', 400);
