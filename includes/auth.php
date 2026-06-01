<?php
require_once __DIR__ . '/tenant.php';

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
  } catch (Throwable $ex) {
    // ignore failures - best effort
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
