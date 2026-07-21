<?php
require_once __DIR__ . '/includes/tenant.php';
tenant_start_session();
$adminRole = trim((string) ($_SESSION['auth_user']['rol'] ?? ''));
if (!isset($_SESSION['auth_user']) || !in_array($adminRole, ['admin', 'root'], true)) {
    http_response_code(403);
    die('Acceso denegado');
}
// Generar hash correcto para admin123
echo password_hash('admin123', PASSWORD_DEFAULT);
