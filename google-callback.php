<?php

if (session_status() !== PHP_SESSION_ACTIVE) {
    require_once __DIR__ . '/includes/tenant.php';
    tenant_start_session();
}

require_once __DIR__ . '/includes/store_config.php';
require_once __DIR__ . '/includes/google_oauth.php';
require_once __DIR__ . '/includes/db.php';

if (!google_oauth_is_configured()) {
    $_SESSION['auth_flash'] = ['type' => 'error', 'message' => 'El acceso con Google no está configurado todavía.'];
    header('Location: ' . google_oauth_home_url());
    exit;
}

if (!google_oauth_validate_state($_GET['state'] ?? null)) {
    $_SESSION['auth_flash'] = ['type' => 'error', 'message' => 'No se pudo validar la sesión de Google. Intenta nuevamente.'];
    header('Location: ' . google_oauth_home_url());
    exit;
}

if (!empty($_GET['error'])) {
    $_SESSION['auth_flash'] = ['type' => 'error', 'message' => 'Google devolvió un error al iniciar sesión.'];
    header('Location: ' . google_oauth_home_url());
    exit;
}

$authCode = trim((string) ($_GET['code'] ?? ''));
if ($authCode === '') {
    $_SESSION['auth_flash'] = ['type' => 'error', 'message' => 'Google no devolvió un código de autorización válido.'];
    header('Location: ' . google_oauth_home_url());
    exit;
}

try {
    $tokenResponse = google_oauth_http_post('https://oauth2.googleapis.com/token', [
        'code' => $authCode,
        'client_id' => trim(store_config_get('google_client_id', '')),
        'client_secret' => trim(store_config_get('google_client_secret', '')),
        'redirect_uri' => google_oauth_callback_url(),
        'grant_type' => 'authorization_code',
    ]);

    $tokenData = json_decode((string) $tokenResponse['body'], true);
    if (($tokenResponse['status'] ?? 0) < 200 || ($tokenResponse['status'] ?? 0) >= 300 || !is_array($tokenData) || empty($tokenData['access_token'])) {
        throw new RuntimeException('No se pudo obtener el token de acceso desde Google.');
    }

    $userResponse = google_oauth_http_get('https://openidconnect.googleapis.com/v1/userinfo', [
        'Accept: application/json',
        'Authorization: Bearer ' . $tokenData['access_token'],
    ]);

    $googleUser = json_decode((string) $userResponse['body'], true);
    if (($userResponse['status'] ?? 0) < 200 || ($userResponse['status'] ?? 0) >= 300 || !is_array($googleUser)) {
        throw new RuntimeException('No se pudo obtener el perfil de Google.');
    }

    $email = strtolower(trim((string) ($googleUser['email'] ?? '')));
    $fullName = trim((string) ($googleUser['name'] ?? $googleUser['given_name'] ?? 'Usuario Google'));
    $emailVerified = !empty($googleUser['email_verified']);

    if ($email === '' || !$emailVerified) {
        throw new RuntimeException('Google no devolvió un correo verificado para esta cuenta.');
    }

    $stmt = $pdo->prepare('SELECT id, username, nombre, email, telefono, foto_perfil, rol FROM usuarios WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user) {
        $userId = (int) $user['id'];
        $username = trim((string) ($user['username'] ?? ''));
        $userPhone = (string) ($user['telefono'] ?? '');
        $userProfileImage = (string) ($user['foto_perfil'] ?? '');
        if ($username === '') {
            $username = $email;
        }

        $updateStmt = $pdo->prepare('UPDATE usuarios SET username = ?, nombre = ?, email = ? WHERE id = ?');
        $updateStmt->execute([$username, $fullName, $email, $userId]);
        $role = (string) ($user['rol'] ?? 'usuario');
    } else {
        $username = $email;
        $passwordHash = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
        $role = 'usuario';
        $userPhone = '';
        $userProfileImage = '';

        $insertStmt = $pdo->prepare('INSERT INTO usuarios (username, password, nombre, email, telefono, rol, creado_en) VALUES (?, ?, ?, ?, ?, ?, NOW())');
        $insertStmt->execute([$username, $passwordHash, $fullName, $email, null, $role]);
        $userId = (int) $pdo->lastInsertId();
    }

    session_regenerate_id(true);
    unset($_SESSION['auth_modal_state']);
    $_SESSION['auth_user'] = [
        'id' => $userId,
        'email' => $email,
        'telefono' => $userPhone,
        'foto_perfil' => $userProfileImage,
        'full_name' => $fullName,
        'username' => $username,
        'rol' => $role,
    ];
    $_SESSION['auth_flash'] = ['type' => 'success', 'message' => 'Sesión iniciada con Google.'];

    if ($role === 'admin') {
        header('Location: ' . google_oauth_admin_dashboard_url());
        exit;
    }

    if ($role === 'empleado') {
        header('Location: ' . app_path('/admin/dashboard'));
        exit;
    }

    if ($role === 'influencer') {
        header('Location: ' . app_path('/admin/cupones') . '?tab=influencers');
        exit;
    }

    header('Location: ' . google_oauth_home_url());
    exit;
} catch (Throwable $exception) {
    $_SESSION['auth_flash'] = ['type' => 'error', 'message' => 'No se pudo iniciar sesión con Google.'];
    header('Location: ' . google_oauth_home_url());
    exit;
}
