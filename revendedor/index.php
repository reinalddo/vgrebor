<?php
/**
 * revendedor/index.php — Entrada del panel de REVENDEDOR.
 * Verifica que el usuario sea revendedor y lo lleva a su gestor de streaming.
 */
require_once __DIR__ . '/../includes/tenant.php';
tenant_start_session();

$rol = strtolower(trim((string) ($_SESSION['auth_user']['rol'] ?? '')));
if ($rol !== 'revendedor') {
    // No es revendedor (o no está logueado) → al login/inicio.
    header('Location: ' . app_path('/login.php'));
    exit;
}

header('Location: ' . app_path('/revendedor/stream/dashboard.php'));
exit;
