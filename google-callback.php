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

    $stmt = $pdo->prepare('SELECT id, username, nombre, email, telefono, foto_perfil, rol, COALESCE(bloqueado, 0) AS bloqueado FROM usuarios WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user && (int) ($user['bloqueado'] ?? 0) === 1) {
        $_SESSION['auth_modal_state'] = [
            'mode' => 'login',
            'message' => 'Usuario Bloqueado, Contacte al administrador para más información',
            'email' => $email,
            'blocked' => true,
        ];
        header('Location: ' . google_oauth_home_url());
        exit;
    }

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

        // Sistema de Referidos: mismo mecanismo que register_user.php, pero leyendo
        // el código desde $_SESSION en vez del body JSON (ver includes/header.php,
        // que lo guarda ahí porque este flujo de Google no tiene JS/localStorage
        // disponible en el momento del callback). Solo se atribuye en la creación
        // de una cuenta NUEVA — nunca a un login posterior de una cuenta existente.
        require_once __DIR__ . '/includes/referidos.php';
        require_once __DIR__ . '/includes/db_connect.php';
        referidos_ensure_schema();

        $refCodigoInput = strtoupper(trim((string) ($_SESSION['tvg_referido_codigo'] ?? '')));
        $referidorUserId = null;
        if ($refCodigoInput !== '') {
            $refStmt = $mysqli->prepare('SELECT id FROM usuarios WHERE referido_codigo = ? LIMIT 1');
            if ($refStmt) {
                $refStmt->bind_param('s', $refCodigoInput);
                $refStmt->execute();
                $refRow = $refStmt->get_result()->fetch_assoc();
                $refStmt->close();
                if ($refRow) {
                    $referidorUserId = (int) $refRow['id'];
                }
            }
        }
        $referidoEn = $referidorUserId !== null ? date('Y-m-d H:i:s') : null;

        $insertStmt = $pdo->prepare('INSERT INTO usuarios (username, password, nombre, email, telefono, rol, referido_por_id, referido_en, creado_en) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())');
        $insertStmt->execute([$username, $passwordHash, $fullName, $email, null, $role, $referidorUserId, $referidoEn]);
        $userId = (int) $pdo->lastInsertId();

        if ($userId > 0) {
            referidos_asegurar_codigo_usuario($mysqli, $userId);
            if ($referidorUserId !== null) {
                referidos_generar_cupon_bienvenida($mysqli, $userId);
            }
        }
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

    // Sistema de Comentarios: vincular a esta cuenta las compras que el
    // navegador hizo como invitado antes de entrar con Google, igual que en
    // login.php, para que pueda comentarlas.
    //
    // ⚠️ El require de db_connect.php va acá adentro a propósito: más arriba
    // solo se carga dentro de la rama de usuario NUEVO (línea ~109), así que
    // en el login de una cuenta que YA existía $mysqli no estaría definido y
    // esto reventaría el login con Google. require_once es idempotente, si
    // ya se cargó no vuelve a ejecutarse.
    require_once __DIR__ . '/includes/db_connect.php';
    require_once __DIR__ . '/includes/comentarios.php';
    comentarios_vincular_pedidos_de_sesion($mysqli, (int) $userId);

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
