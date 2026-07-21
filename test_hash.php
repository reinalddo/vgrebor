<?php
require_once __DIR__ . '/includes/tenant.php';
tenant_start_session();
$adminRole = trim((string) ($_SESSION['auth_user']['rol'] ?? ''));
if (!isset($_SESSION['auth_user']) || !in_array($adminRole, ['admin', 'root'], true)) {
    http_response_code(403);
    die('Acceso denegado');
}
$hash = '$2y$10$wH8QwQwQwQwQwQwQwQwOQwQwQwQwQwQwQwQwQwQwQwQwQwQwQw';
var_dump(password_verify('admin123', $hash));
