<?php

if (session_status() !== PHP_SESSION_ACTIVE) {
    require_once __DIR__ . '/includes/tenant.php';
    tenant_start_session();
}

require_once __DIR__ . '/includes/store_config.php';
require_once __DIR__ . '/includes/google_oauth.php';

if (!google_oauth_is_configured()) {
    $_SESSION['auth_flash'] = ['type' => 'error', 'message' => 'El acceso con Google no está configurado todavía.'];
    header('Location: ' . google_oauth_home_url());
    exit;
}

$state = google_oauth_generate_state();
$params = [
    'client_id' => trim(store_config_get('google_client_id', '')),
    'redirect_uri' => google_oauth_callback_url(),
    'response_type' => 'code',
    'scope' => 'openid email profile',
    'state' => $state,
    'include_granted_scopes' => 'true',
    'prompt' => 'select_account',
];

$authUrl = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
header('Location: ' . $authUrl);
exit;
