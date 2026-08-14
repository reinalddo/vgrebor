<?php
// admin/juego_categorias.php — AJAX endpoint para CRUD de categorías y asignación a juegos
require_once '../includes/db_connect.php';
require_once '../includes/tenant.php';
require_once '../includes/auth.php';
require_once '../includes/game_categories.php';

header('Content-Type: application/json; charset=utf-8');

function gc_ok(array $extra = []): void {
    echo json_encode(array_merge(['ok' => true], $extra), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function gc_err(string $message, int $status = 422): void {
    http_response_code($status);
    echo json_encode(['ok' => false, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

function gc_require_admin(): void {
    tenant_start_session();
    $user = $_SESSION['auth_user'] ?? null;
    if (!is_array($user) || !in_array($user['rol'] ?? '', ['admin', 'root'], true)) {
        gc_err('No autorizado.', 403);
    }
}

gc_require_admin();
csrf_verify_soft();

function gc_store_image(array $file): ?string {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return null;
    }
    $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
        return null;
    }
    $dir = tenant_upload_absolute_dir('categorias');
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        return null;
    }
    $fileName    = uniqid('cat_', true) . '.' . $ext;
    $destination = $dir . DIRECTORY_SEPARATOR . $fileName;
    if (!move_uploaded_file((string) ($file['tmp_name'] ?? ''), $destination)) {
        return null;
    }
    return tenant_upload_public_path('categorias', $fileName, false);
}

$action = trim((string) ($_REQUEST['action'] ?? ''));

// ── Categorías ─────────────────────────────────────────────────────────────

if ($action === 'list') {
    gc_ok(['categories' => game_category_list($mysqli)]);
}

if ($action === 'create') {
    $stored = gc_store_image($_FILES['imagen'] ?? []);
    if ($stored !== null) {
        $_POST['imagen'] = $stored;
    }
    $result = game_category_create($mysqli, $_POST);
    if (!$result['ok']) gc_err($result['message']);
    gc_ok(['id' => $result['id'], 'slug' => $result['slug']]);
}

if ($action === 'update') {
    $id = (int) ($_POST['id'] ?? $_GET['id'] ?? 0);
    if ($id <= 0) gc_err('ID de categoría requerido.');
    $stored = gc_store_image($_FILES['imagen'] ?? []);
    if ($stored !== null) {
        $existing = game_category_get($mysqli, $id);
        if ($existing && $existing['imagen'] !== '') {
            $abs = tenant_resolve_public_path($existing['imagen']);
            if ($abs !== null && is_file($abs)) {
                @unlink($abs);
            }
        }
        $_POST['imagen'] = $stored;
    } elseif (!empty($_POST['remove_imagen']) && (int) $_POST['remove_imagen'] === 1) {
        $existing = $existing ?? game_category_get($mysqli, $id);
        if ($existing && $existing['imagen'] !== '') {
            $abs = tenant_resolve_public_path($existing['imagen']);
            if ($abs !== null && is_file($abs)) {
                @unlink($abs);
            }
        }
        $_POST['imagen'] = '';
    } else {
        unset($_POST['imagen']);
    }
    $result = game_category_update($mysqli, $id, $_POST);
    if (!$result['ok']) gc_err($result['message']);
    gc_ok(['slug' => $result['slug']]);
}

if ($action === 'delete') {
    $id = (int) ($_POST['id'] ?? $_GET['id'] ?? 0);
    if ($id <= 0) gc_err('ID de categoría requerido.');
    $result = game_category_delete($mysqli, $id);
    if (!$result['ok']) gc_err($result['message'], 404);
    gc_ok();
}

// ── Asignación de categorías a juegos ──────────────────────────────────────

if ($action === 'get_game_cats') {
    $juegoId = (int) ($_GET['juego_id'] ?? $_POST['juego_id'] ?? 0);
    if ($juegoId <= 0) gc_err('juego_id requerido.');
    gc_ok([
        'category_ids' => game_get_category_ids($mysqli, $juegoId),
        'categories'   => game_get_categories($mysqli, $juegoId),
    ]);
}

if ($action === 'set_game_cats') {
    $juegoId    = (int) ($_POST['juego_id'] ?? 0);
    $categoryIds = $_POST['cat_ids'] ?? [];
    if ($juegoId <= 0) gc_err('juego_id requerido.');
    if (!is_array($categoryIds)) {
        $categoryIds = $categoryIds !== '' ? [(int) $categoryIds] : [];
    }
    game_set_categories($mysqli, $juegoId, $categoryIds);
    gc_ok(['assigned' => count(array_filter(array_map('intval', $categoryIds), fn($id) => $id > 0))]);
}

gc_err('Acción no reconocida.', 400);
