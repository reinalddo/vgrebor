<?php
require_once __DIR__ . "/includes/auth.php";
require_once __DIR__ . "/includes/store_config.php";
require_once __DIR__ . "/includes/db_connect.php";
require_once __DIR__ . "/includes/tenant.php";

$pageTitle = store_config_get('nombre_tienda', 'TVirtualGaming') . " | Restablecer contraseña";
$resetToken = trim((string) ($_GET['token'] ?? $_POST['token'] ?? ''));
$resetPasswordError = '';
$requestEmailValue = auth_normalize_email($_POST['email'] ?? '');
$neonButtonStyle = 'display:block;width:100%;border:1px solid rgba(103,232,249,.68);background:linear-gradient(135deg,#22d3ee 0%,#0ea5e9 46%,#8b5cf6 100%);box-shadow:0 0 0 1px rgba(255,255,255,.08) inset,0 0 20px rgba(34,211,238,.32),0 14px 30px rgba(14,165,233,.24);';
$neonInlineButtonStyle = 'display:inline-flex;align-items:center;justify-content:center;white-space:nowrap;border:1px solid rgba(103,232,249,.68);background:linear-gradient(135deg,#22d3ee 0%,#0ea5e9 46%,#8b5cf6 100%);box-shadow:0 0 0 1px rgba(255,255,255,.08) inset,0 0 20px rgba(34,211,238,.32),0 14px 30px rgba(14,165,233,.24);min-height:42px;padding:0 18px;';

function reset_table_exists(mysqli $mysqli, string $table): bool {
  $escapedTable = $mysqli->real_escape_string($table);
  $result = $mysqli->query("SHOW TABLES LIKE '{$escapedTable}'");
  $exists = $result instanceof mysqli_result && $result->num_rows > 0;
  if ($result instanceof mysqli_result) {
    $result->free();
  }
  return $exists;
}

function reset_column_exists(mysqli $mysqli, string $table, string $column): bool {
  $escapedTable = $mysqli->real_escape_string($table);
  $escapedColumn = $mysqli->real_escape_string($column);
  $result = $mysqli->query("SHOW COLUMNS FROM `{$escapedTable}` LIKE '{$escapedColumn}'");
  $exists = $result instanceof mysqli_result && $result->num_rows > 0;
  if ($result instanceof mysqli_result) {
    $result->free();
  }
  return $exists;
}

function ensure_password_reset_columns(mysqli $mysqli): void {
  if (!reset_column_exists($mysqli, 'usuarios', 'reset_requested_at')) {
    $mysqli->query("ALTER TABLE usuarios ADD COLUMN reset_requested_at DATETIME NULL AFTER rol");
  }
  if (!reset_column_exists($mysqli, 'usuarios', 'reset_token_hash')) {
    $mysqli->query("ALTER TABLE usuarios ADD COLUMN reset_token_hash CHAR(64) NULL AFTER reset_requested_at");
  }
  if (!reset_column_exists($mysqli, 'usuarios', 'reset_token_expires_at')) {
    $mysqli->query("ALTER TABLE usuarios ADD COLUMN reset_token_expires_at DATETIME NULL AFTER reset_token_hash");
  }
}

function reset_mail_settings(mysqli $mysqli): array {
  $settings = [
    'correo_corporativo' => '',
    'smtp_host' => '',
    'smtp_user' => '',
    'smtp_pass' => '',
    'smtp_port' => 587,
    'smtp_secure' => 'tls',
  ];

  if (reset_table_exists($mysqli, 'configuracion_general')) {
    $result = $mysqli->query("SELECT clave, valor FROM configuracion_general");
    if ($result instanceof mysqli_result) {
      while ($row = $result->fetch_assoc()) {
        $key = (string) ($row['clave'] ?? '');
        if ($key !== '' && array_key_exists($key, $settings)) {
          $settings[$key] = $row['valor'];
        }
      }
      $result->free();
    }
  } elseif (reset_table_exists($mysqli, 'configuracion')) {
    $result = $mysqli->query("SELECT * FROM configuracion ORDER BY id DESC LIMIT 1");
    if ($result instanceof mysqli_result && ($row = $result->fetch_assoc())) {
      foreach ($settings as $key => $defaultValue) {
        if (isset($row[$key]) && $row[$key] !== '') {
          $settings[$key] = $row[$key];
        }
      }
      $result->free();
    }
  }

  $settings['smtp_port'] = (int) ($settings['smtp_port'] ?: 587);
  $settings['smtp_secure'] = strtolower(trim((string) $settings['smtp_secure']));
  if (!in_array($settings['smtp_secure'], ['ssl', 'tls'], true)) {
    $settings['smtp_secure'] = 'tls';
  }

  return $settings;
}

function reset_store_name(): string {
  $name = trim((string) store_config_get('nombre_tienda', 'TVirtualGaming'));
  return $name !== '' ? $name : 'TVirtualGaming';
}

function reset_find_user_by_email(mysqli $mysqli, string $email): ?array {
  $stmt = $mysqli->prepare("SELECT id, username, nombre, email FROM usuarios WHERE LOWER(email) = ? LIMIT 1");
  if (!$stmt) {
    return null;
  }

  $stmt->bind_param('s', $email);
  $stmt->execute();
  $result = $stmt->get_result();
  $user = $result ? $result->fetch_assoc() : null;
  $stmt->close();

  return is_array($user) ? $user : null;
}

function reset_issue_token(mysqli $mysqli, int $userId): string {
  ensure_password_reset_columns($mysqli);
  $token = bin2hex(random_bytes(32));
  $tokenHash = hash('sha256', $token);
  $requestedAt = date('Y-m-d H:i:s');
  $expiresAt = date('Y-m-d H:i:s', time() + 3600);
  $stmt = $mysqli->prepare("UPDATE usuarios SET reset_requested_at = ?, reset_token_hash = ?, reset_token_expires_at = ? WHERE id = ? LIMIT 1");
  if (!$stmt) {
    throw new RuntimeException('No fue posible preparar el token de recuperación.');
  }

  $stmt->bind_param('sssi', $requestedAt, $tokenHash, $expiresAt, $userId);
  $stmt->execute();
  $stmt->close();

  return $token;
}

function reset_find_user_by_token(mysqli $mysqli, string $token): ?array {
  if ($token === '' || preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
    return null;
  }

  ensure_password_reset_columns($mysqli);
  $tokenHash = hash('sha256', $token);
  $stmt = $mysqli->prepare("SELECT id, username, nombre, email, reset_token_expires_at FROM usuarios WHERE reset_token_hash = ? AND reset_token_expires_at IS NOT NULL AND reset_token_expires_at >= NOW() LIMIT 1");
  if (!$stmt) {
    return null;
  }

  $stmt->bind_param('s', $tokenHash);
  $stmt->execute();
  $result = $stmt->get_result();
  $user = $result ? $result->fetch_assoc() : null;
  $stmt->close();

  return is_array($user) ? $user : null;
}

function reset_clear_token(mysqli $mysqli, int $userId): void {
  $stmt = $mysqli->prepare("UPDATE usuarios SET reset_requested_at = NULL, reset_token_hash = NULL, reset_token_expires_at = NULL WHERE id = ? LIMIT 1");
  if (!$stmt) {
    return;
  }
  $stmt->bind_param('i', $userId);
  $stmt->execute();
  $stmt->close();
}

function reset_update_password(mysqli $mysqli, int $userId, string $password): bool {
  $passwordHash = password_hash($password, PASSWORD_DEFAULT);
  $stmt = $mysqli->prepare("UPDATE usuarios SET password = ? WHERE id = ? LIMIT 1");
  if (!$stmt) {
    return false;
  }
  $stmt->bind_param('si', $passwordHash, $userId);
  $ok = $stmt->execute();
  $stmt->close();
  return $ok;
}

function reset_absolute_url(string $path): string {
  $resolvedPath = function_exists('app_path') ? app_path($path) : $path;
  if (preg_match('#^https?://#i', $resolvedPath) === 1) {
    return $resolvedPath;
  }

  $scheme = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') ? 'https' : 'http';
  $host = (string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost');
  $normalizedPath = '/' . ltrim($resolvedPath, '/');

  return $scheme . '://' . $host . $normalizedPath;
}

function reset_mail_escape(string $value): string {
  return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function reset_build_message_id_host(string $fromAddr): string {
  $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? ''));
  $host = preg_replace('/:\d+$/', '', $host) ?? $host;
  if ($host !== '') {
    return $host;
  }

  $emailHost = strtolower((string) substr(strrchr($fromAddr, '@') ?: '', 1));
  return $emailHost !== '' ? $emailHost : 'localhost';
}

function reset_sender_name(): string {
  return trim((string) reset_store_name()) ?: 'TVirtualGaming';
}

function reset_email_html(array $user, string $resetUrl): string {
  $storeName = reset_store_name();
  $recipientName = trim((string) ($user['nombre'] ?? '')) !== ''
    ? trim((string) $user['nombre'])
    : (trim((string) ($user['username'] ?? '')) !== '' ? trim((string) $user['username']) : 'jugador');

  return '
    <html lang="es">
      <body style="margin:0;padding:24px;background:#020617;color:#e2e8f0;font-family:Arial,Helvetica,sans-serif;">
        <div style="max-width:640px;margin:0 auto;border:1px solid rgba(34,211,238,0.26);border-radius:24px;background:linear-gradient(180deg,#0f172a 0%,#020617 100%);overflow:hidden;box-shadow:0 0 32px rgba(34,211,238,0.12);">
          <div style="padding:20px 24px;border-bottom:1px solid rgba(34,211,238,0.18);background:radial-gradient(circle at top right, rgba(34,211,238,0.18), transparent 36%),#07111f;">
            <div style="font-size:11px;letter-spacing:0.36em;text-transform:uppercase;color:#67e8f9;">Recuperación</div>
            <h1 style="margin:10px 0 0;font-size:28px;line-height:1.15;color:#f8fafc;">Restablecer contraseña</h1>
          </div>
          <div style="padding:24px;">
            <p style="margin-top:0;">Hola ' . reset_mail_escape($recipientName) . ',</p>
            <p>Recibimos una solicitud para cambiar la contraseña de tu cuenta en <strong>' . reset_mail_escape($storeName) . '</strong>.</p>
            <p>Usa el siguiente botón para crear una nueva contraseña. Este enlace estará disponible por 60 minutos.</p>
            <p style="margin:28px 0;">
              <a href="' . reset_mail_escape($resetUrl) . '" style="display:inline-block;padding:14px 22px;border-radius:14px;border:1px solid rgba(103,232,249,0.72);background:linear-gradient(135deg,#22d3ee 0%,#0ea5e9 46%,#8b5cf6 100%);color:#ffffff;text-decoration:none;font-weight:700;box-shadow:0 0 18px rgba(34,211,238,0.24);">Crear nueva contraseña</a>
            </p>
            <p style="margin-bottom:8px;">Si el botón no abre, copia y pega este enlace en tu navegador:</p>
            <p style="word-break:break-all;margin-top:0;"><a href="' . reset_mail_escape($resetUrl) . '" style="color:#67e8f9;">' . reset_mail_escape($resetUrl) . '</a></p>
            <p style="margin-bottom:0;color:#94a3b8;">Si no solicitaste este cambio, puedes ignorar este correo.</p>
          </div>
        </div>
      </body>
    </html>';
}

function reset_encode_subject(string $subject): string {
  return '=?UTF-8?B?' . base64_encode($subject) . '?=';
}

function reset_smtp_read_response($socket): string {
  $response = '';
  while (!feof($socket)) {
    $line = fgets($socket, 515);
    if ($line === false) {
      break;
    }
    $response .= $line;
    if (preg_match('/^\d{3}\s/', $line) === 1) {
      break;
    }
  }
  return $response;
}

function reset_smtp_expect($socket, array $allowedCodes, string $context): string {
  $response = reset_smtp_read_response($socket);
  $code = (int) substr(trim($response), 0, 3);
  if (!in_array($code, $allowedCodes, true)) {
    throw new RuntimeException($context . ': ' . trim($response));
  }
  return $response;
}

function reset_smtp_command($socket, string $command, array $allowedCodes, string $context): string {
  fwrite($socket, $command . "\r\n");
  return reset_smtp_expect($socket, $allowedCodes, $context);
}

function reset_send_mail_via_socket(string $to, string $subject, string $html, string $fromAddr, array $settings, string $senderName): void {
  $host = (string) ($settings['smtp_host'] ?? '');
  $port = (int) ($settings['smtp_port'] ?? 587);
  $secure = strtolower((string) ($settings['smtp_secure'] ?? 'tls'));
  $username = (string) ($settings['smtp_user'] ?? '');
  $password = (string) ($settings['smtp_pass'] ?? '');

  if ($host === '' || $username === '' || $password === '' || !filter_var($fromAddr, FILTER_VALIDATE_EMAIL)) {
    throw new RuntimeException('La configuración SMTP está incompleta.');
  }

  $transport = $secure === 'ssl' ? 'ssl://' : 'tcp://';
  $socket = @stream_socket_client($transport . $host . ':' . $port, $errno, $errstr, 20, STREAM_CLIENT_CONNECT);
  if (!$socket) {
    throw new RuntimeException('No se pudo conectar al servidor SMTP: ' . $errstr . ' (' . $errno . ')');
  }

  stream_set_timeout($socket, 20);

  try {
    reset_smtp_expect($socket, [220], 'Conexión SMTP');
    reset_smtp_command($socket, 'EHLO localhost', [250], 'EHLO inicial');

    if ($secure === 'tls') {
      reset_smtp_command($socket, 'STARTTLS', [220], 'STARTTLS');
      $cryptoEnabled = stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
      if ($cryptoEnabled !== true) {
        throw new RuntimeException('No se pudo habilitar TLS.');
      }
      reset_smtp_command($socket, 'EHLO localhost', [250], 'EHLO tras TLS');
    }

    reset_smtp_command($socket, 'AUTH LOGIN', [334], 'AUTH LOGIN');
    reset_smtp_command($socket, base64_encode($username), [334], 'SMTP usuario');
    reset_smtp_command($socket, base64_encode($password), [235], 'SMTP contraseña');
    reset_smtp_command($socket, 'MAIL FROM:<' . $fromAddr . '>', [250], 'MAIL FROM');
    reset_smtp_command($socket, 'RCPT TO:<' . $to . '>', [250, 251], 'RCPT TO');
    reset_smtp_command($socket, 'DATA', [354], 'DATA');

    $headers = [
      'Date: ' . date(DATE_RFC2822),
      'From: ' . str_replace(["\r", "\n"], '', $senderName) . ' <' . $fromAddr . '>',
      'To: <' . $to . '>',
      'Subject: ' . reset_encode_subject($subject),
      'MIME-Version: 1.0',
      'Content-Type: text/html; charset=UTF-8',
      'Content-Transfer-Encoding: 8bit',
      'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . reset_build_message_id_host($fromAddr) . '>',
    ];

    $payload = implode("\r\n", $headers) . "\r\n\r\n" . str_replace(["\r\n.", "\n.", "\r."], ["\r\n..", "\n..", "\r.."], $html);
    fwrite($socket, $payload . "\r\n.\r\n");
    reset_smtp_expect($socket, [250], 'Envío de mensaje');
    reset_smtp_command($socket, 'QUIT', [221], 'QUIT');
  } finally {
    fclose($socket);
  }
}

function reset_send_mail(mysqli $mysqli, string $to, string $subject, string $html): void {
  $settings = reset_mail_settings($mysqli);
  $fromAddr = trim((string) ($settings['correo_corporativo'] ?? ''));
  if (!filter_var($fromAddr, FILTER_VALIDATE_EMAIL)) {
    $fromAddr = trim((string) ($settings['smtp_user'] ?? ''));
  }

  $senderName = reset_sender_name();

  try {
    require_once __DIR__ . '/includes/PHPMailerAutoload.php';
    if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
      throw new RuntimeException('PHPMailer no disponible.');
    }

    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    $mail->CharSet = 'UTF-8';
    $mail->isSMTP();
    $mail->Host = (string) ($settings['smtp_host'] ?? '');
    $mail->SMTPAuth = true;
    $mail->Username = (string) ($settings['smtp_user'] ?? '');
    $mail->Password = (string) ($settings['smtp_pass'] ?? '');
    $mail->SMTPSecure = (string) ($settings['smtp_secure'] ?? 'tls');
    $mail->Port = (int) ($settings['smtp_port'] ?? 587);
    $mail->setFrom($fromAddr, $senderName);
    $mail->addAddress($to);
    $mail->isHTML(true);
    $mail->Subject = $subject;
    $mail->Body = $html;
    $mail->send();
    return;
  } catch (Throwable $mailerError) {
    error_log('TVG password reset mailer error: ' . $mailerError->getMessage());
  }

  reset_send_mail_via_socket($to, $subject, $html, $fromAddr, $settings, $senderName);
}

ensure_password_reset_columns($mysqli);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = (string) ($_POST['action'] ?? 'request');

  if ($action === 'update_password') {
    $resetToken = trim((string) ($_POST['token'] ?? ''));
    $resetUser = reset_find_user_by_token($mysqli, $resetToken);
    $password = (string) ($_POST['password'] ?? '');
    $passwordConfirmation = (string) ($_POST['password_confirmation'] ?? '');

    if (!$resetUser) {
      auth_set_flash('error', 'El enlace de recuperación no es válido o ya expiró. Solicita uno nuevo.');
      header('Location: ' . app_path('/reset.php'));
      exit;
    }

    if ($password === '' || $passwordConfirmation === '') {
      $resetPasswordError = 'Completa y confirma la nueva contraseña.';
    } elseif (strlen($password) < 6) {
      $resetPasswordError = 'La nueva contraseña debe tener al menos 6 caracteres.';
    } elseif ($password !== $passwordConfirmation) {
      $resetPasswordError = 'La confirmación no coincide con la nueva contraseña.';
    } else {
      if (!reset_update_password($mysqli, (int) $resetUser['id'], $password)) {
        $resetPasswordError = 'No pudimos actualizar tu contraseña en este momento.';
      } else {
        reset_clear_token($mysqli, (int) $resetUser['id']);
        auth_set_flash('success', 'Tu contraseña fue actualizada. Ya puedes iniciar sesión con la nueva clave.');
        header('Location: ' . app_path('/reset.php'));
        exit;
      }
    }
  } else {
    $email = auth_normalize_email($_POST['email'] ?? '');

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
      auth_set_flash('error', 'Ingresa un correo electrónico válido.');
      header('Location: ' . app_path('/reset.php'));
      exit;
    }

    $user = reset_find_user_by_email($mysqli, $email);
    if ($user) {
      try {
        $token = reset_issue_token($mysqli, (int) $user['id']);
        $resetUrl = reset_absolute_url('/reset.php?token=' . urlencode($token));
        $subject = reset_store_name() . ' | Restablecer contraseña';
        $html = reset_email_html($user, $resetUrl);
        reset_send_mail($mysqli, (string) $user['email'], $subject, $html);
      } catch (Throwable $resetError) {
        error_log('TVG password reset error: ' . $resetError->getMessage());
        auth_set_flash('error', 'No pudimos enviar las instrucciones en este momento. Verifica la configuración SMTP y vuelve a intentarlo.');
        header('Location: ' . app_path('/reset.php'));
        exit;
      }
    }

    auth_set_flash('success', 'Si el correo existe, enviamos instrucciones para restablecer la contraseña.');
    header('Location: ' . app_path('/reset.php'));
    exit;
  }
}

$resetUser = $resetToken !== '' ? reset_find_user_by_token($mysqli, $resetToken) : null;
$invalidResetToken = $resetToken !== '' && !$resetUser;

include __DIR__ . "/includes/header.php";
?>

      <section class="mt-10 flex items-center justify-center">
        <div class="w-full max-w-md rounded-2xl border border-slate-800 bg-slate-900/95 p-6 shadow-2xl" style="animation: fadeUp 320ms ease-out both;">
          <div>
            <p class="text-xs uppercase tracking-[0.35em] text-slate-400">Recuperación</p>
            <h2 class="mt-2 font-oxanium text-2xl font-semibold"><?= $resetUser ? 'Crear nueva contraseña' : 'Restablecer contraseña' ?></h2>
            <p class="mt-1 text-xs text-slate-400"><?= $resetUser ? 'Define una nueva clave para volver a entrar a tu cuenta.' : 'Ingresa tu correo para recibir instrucciones.' ?></p>
          </div>

          <?php if ($invalidResetToken): ?>
            <div class="mt-4 rounded-2xl border border-rose-400/30 bg-rose-500/10 px-4 py-3 text-sm text-rose-200">
              Este enlace ya no es válido o expiró. Solicita uno nuevo desde este formulario.
            </div>
          <?php endif; ?>

          <?php if ($resetUser): ?>
            <?php if ($resetPasswordError !== ''): ?>
              <div class="mt-4 rounded-2xl border border-rose-400/30 bg-rose-500/10 px-4 py-3 text-sm text-rose-200">
                <?= htmlspecialchars($resetPasswordError, ENT_QUOTES, 'UTF-8') ?>
              </div>
            <?php endif; ?>
            <form action="<?= htmlspecialchars(app_path('/reset.php'), ENT_QUOTES, 'UTF-8') ?>" method="post" class="mt-4 space-y-4" novalidate>
              <input type="hidden" name="action" value="update_password">
              <input type="hidden" name="token" value="<?= htmlspecialchars($resetToken, ENT_QUOTES, 'UTF-8') ?>">
              <div>
                <label class="block text-xs text-slate-400">Correo electrónico</label>
                <input type="email" value="<?= htmlspecialchars((string) ($resetUser['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" readonly class="mt-1 w-full rounded-xl border border-slate-800 bg-slate-950/60 px-3 py-2 text-sm text-slate-300 outline-none">
              </div>
              <div>
                <label class="block text-xs text-slate-400">Nueva contraseña</label>
                <input type="password" name="password" autocomplete="new-password" class="mt-1 w-full rounded-xl border border-slate-800 bg-slate-950/60 px-3 py-2 text-sm text-slate-100 outline-none transition focus:border-cyan-400/70" placeholder="Mínimo 6 caracteres">
              </div>
              <div>
                <label class="block text-xs text-slate-400">Confirmar contraseña</label>
                <input type="password" name="password_confirmation" autocomplete="new-password" class="mt-1 w-full rounded-xl border border-slate-800 bg-slate-950/60 px-3 py-2 text-sm text-slate-100 outline-none transition focus:border-cyan-400/70" placeholder="Repite la nueva contraseña">
              </div>
              <button type="submit" class="w-full rounded-xl px-4 py-2 text-sm font-semibold uppercase tracking-wide text-white transition hover:brightness-110" style="<?= htmlspecialchars($neonButtonStyle, ENT_QUOTES, 'UTF-8') ?>">Guardar nueva contraseña</button>
            </form>
          <?php else: ?>
            <form action="<?= htmlspecialchars(app_path('/reset.php'), ENT_QUOTES, 'UTF-8') ?>" method="post" class="mt-4 space-y-4" novalidate>
              <input type="hidden" name="action" value="request">
              <div>
                <label class="block text-xs text-slate-400">Correo electrónico</label>
                <div class="mt-1 flex flex-col gap-3 sm:flex-row sm:items-center">
                  <input type="email" name="email" value="<?= htmlspecialchars($requestEmailValue, ENT_QUOTES, 'UTF-8') ?>" autocomplete="email" class="min-w-0 flex-1 rounded-xl border border-slate-800 bg-slate-950/60 px-3 py-2 text-sm text-slate-100 outline-none transition focus:border-cyan-400/70" placeholder="nombre@correo.com" />
                  <button type="submit" class="shrink-0 rounded-xl px-4 py-2 text-sm font-semibold uppercase tracking-wide text-white transition hover:brightness-110" style="<?= htmlspecialchars($neonInlineButtonStyle, ENT_QUOTES, 'UTF-8') ?>">Enviar instrucciones</button>
                </div>
              </div>
            </form>
          <?php endif; ?>
        </div>
      </section>

<?php
include __DIR__ . "/includes/footer.php";
?>
