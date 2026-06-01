<?php
require_once __DIR__ . '/includes/tenant.php';
tenant_start_session();

$_SESSION = [];
tenant_forget_session_cookie();
session_destroy();
header('Location: ' . app_path('/'));
exit();
