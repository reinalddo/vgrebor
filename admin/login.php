<?php
// Redirige a la ubicación correcta del login
require_once __DIR__ . '/../includes/tenant.php';
header('Location: ' . app_path('/login.php'));
exit;
