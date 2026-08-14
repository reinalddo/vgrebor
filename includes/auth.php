<?php
require_once __DIR__ . '/tenant.php';

// ── CSRF (panel admin "clásico": admin.php y admin/*.php) ──────────────────
// Auditoría 2026-08-12: estos formularios no verificaban ningún token,
// dependían solo de la sesión — vulnerables a que un admin logueado visite
// una página ajena que dispare una solicitud falsificada a su nombre.
//
// FASE DE OBSERVACIÓN (no bloquea todavía): csrf_verify_soft() solo
// registra en error_log si un POST llega sin token o con uno inválido, para
// detectar cualquier formulario/AJAX al que se le haya olvidado el token
// ANTES de activar el bloqueo real con csrf_verify_enforce(). Una vez
// confirmado unos días sin entradas sospechosas en el log, se cambia cada
// llamada de csrf_verify_soft() a csrf_verify_enforce() (mismo chequeo,
// pero corta la solicitud en vez de solo avisar).
if (!function_exists('csrf_token')) {
    function csrf_token(): string {
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(16));
        }
        return (string) $_SESSION['csrf'];
    }
}

if (!function_exists('csrf_submitted_token')) {
    function csrf_submitted_token(): string {
        $fromPost = (string) ($_POST['_csrf'] ?? '');
        if ($fromPost !== '') {
            return $fromPost;
        }
        return (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    }
}

if (!function_exists('csrf_is_valid')) {
    function csrf_is_valid(): bool {
        $expected = (string) ($_SESSION['csrf'] ?? '');
        $submitted = csrf_submitted_token();
        return $expected !== '' && $submitted !== '' && hash_equals($expected, $submitted);
    }
}

if (!function_exists('csrf_verify_soft')) {
    function csrf_verify_soft(): void {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            return;
        }
        if (csrf_is_valid()) {
            return;
        }
        $postKeys = implode(',', array_keys($_POST));
        error_log(
            'TVG CSRF [modo observación, NO bloqueado] uri=' . (string) ($_SERVER['REQUEST_URI'] ?? '?')
            . ' post_keys=' . $postKeys
            . ' ip=' . (string) ($_SERVER['REMOTE_ADDR'] ?? '?')
        );
    }
}

if (!function_exists('csrf_verify_enforce')) {
    function csrf_verify_enforce(): void {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            return;
        }
        if (csrf_is_valid()) {
            return;
        }
        http_response_code(403);
        if (stripos((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false || !empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'message' => 'Token de seguridad inválido. Recarga la página e intenta de nuevo.'], JSON_UNESCAPED_UNICODE);
        } else {
            echo 'Token de seguridad inválido. Recarga la página e intenta de nuevo.';
        }
        exit;
    }
}

function auth_normalize_email($email) {
  return strtolower(trim((string) $email));
}

function auth_ensure_profile_columns(mysqli $connection): void {
  try {
    $res = $connection->query("SHOW COLUMNS FROM usuarios LIKE 'foto_perfil'");
    $has = $res instanceof mysqli_result && $res->num_rows > 0;
    if ($res instanceof mysqli_result) {
      $res->free();
    }
    if (!$has) {
      $connection->query("ALTER TABLE usuarios ADD COLUMN foto_perfil VARCHAR(255) NULL AFTER email");
    }

    $res = $connection->query("SHOW COLUMNS FROM usuarios LIKE 'bloqueado'");
    $hasBlocked = $res instanceof mysqli_result && $res->num_rows > 0;
    if ($res instanceof mysqli_result) {
      $res->free();
    }
    if (!$hasBlocked) {
      $connection->query("ALTER TABLE usuarios ADD COLUMN bloqueado TINYINT(1) NOT NULL DEFAULT 0 AFTER rol");
    }
  } catch (Throwable $ex) {
    // ignore failures - best effort
  }
}

function auth_user_is_blocked(mysqli $connection, int $userId): bool {
  if ($userId <= 0) {
    return false;
  }

  try {
    auth_ensure_profile_columns($connection);
    $stmt = $connection->prepare('SELECT COALESCE(bloqueado, 0) AS bloqueado FROM usuarios WHERE id = ? LIMIT 1');
    if (!$stmt) {
      return false;
    }
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();
    return (int) ($row['bloqueado'] ?? 0) === 1;
  } catch (Throwable $ex) {
    return false;
  }
}

function auth_sync_session_user(): ?array {
  tenant_start_session();
  $sessionUser = $_SESSION['auth_user'] ?? null;
  if (!is_array($sessionUser) || empty($sessionUser['id'])) {
    return null;
  }

  global $mysqli;
  require_once __DIR__ . '/db_connect.php';
  if (!isset($mysqli) || !($mysqli instanceof mysqli)) {
    return $sessionUser;
  }

  // Ensure profile columns exist (best-effort migration)
  auth_ensure_profile_columns($mysqli);

  $userId = (int) $sessionUser['id'];
  $stmt = $mysqli->prepare('SELECT id, username, nombre, email, telefono, foto_perfil, rol FROM usuarios WHERE id = ? LIMIT 1');
  if (!$stmt) {
    return $sessionUser;
  }

  $stmt->bind_param('i', $userId);
  if (!$stmt->execute()) {
    $stmt->close();
    return $sessionUser;
  }

  $result = $stmt->get_result();
  $freshUser = $result ? $result->fetch_assoc() : null;
  $stmt->close();

  if (!is_array($freshUser)) {
    unset($_SESSION['auth_user']);
    return null;
  }

  $_SESSION['auth_user'] = [
    'id' => (int) ($freshUser['id'] ?? $userId),
    'email' => (string) ($freshUser['email'] ?? ''),
    'telefono' => (string) ($freshUser['telefono'] ?? ''),
    'foto_perfil' => (string) ($freshUser['foto_perfil'] ?? ''),
    'full_name' => (string) ($freshUser['nombre'] ?? ''),
    'username' => (string) ($freshUser['username'] ?? ''),
    'rol' => strtolower(trim((string) ($freshUser['rol'] ?? 'usuario'))),
  ];

  return $_SESSION['auth_user'];
}

function auth_set_flash($type, $message) {
  tenant_start_session();
  $_SESSION["auth_flash"] = ["type" => $type, "message" => $message];
}

function auth_redirect_back($fallback = "/") {
  $target = $_SERVER["HTTP_REFERER"] ?? $fallback;
  if ($target === $fallback) {
    $target = app_path($fallback);
  }
  header("Location: " . $target);
  exit;
}
