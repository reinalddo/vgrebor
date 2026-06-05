<?php
require_once __DIR__ . '/includes/tenant.php';
tenant_start_session();

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db_connect.php';
require_once __DIR__ . '/includes/daily_missions.php';
require_once __DIR__ . '/includes/win_points.php';

$adminUser = auth_sync_session_user();
$adminRole = trim((string) ($adminUser['rol'] ?? ''));
if (!$adminUser || !in_array($adminRole, ['admin', 'root'], true)) {
    header('Location: ' . app_path('/login.php'));
    exit;
}

daily_missions_ensure_schema();

function admin_missions_fetch_users(mysqli $mysqli, string $searchTerm, int $limit = 12): array {
    $limit = max(1, min(50, $limit));
    $rows = [];

    if ($searchTerm !== '') {
        $like = '%' . $searchTerm . '%';
        $stmt = $mysqli->prepare(
            'SELECT id, nombre, email, rol, creado_en
             FROM usuarios
             WHERE nombre LIKE ? OR email LIKE ?
             ORDER BY creado_en DESC, id DESC
             LIMIT ' . $limit
        );
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('ss', $like, $like);
    } else {
        $stmt = $mysqli->prepare(
            'SELECT id, nombre, email, rol, creado_en
             FROM usuarios
             ORDER BY creado_en DESC, id DESC
             LIMIT ' . $limit
        );
        if (!$stmt) {
            return [];
        }
    }

    $stmt->execute();
    $result = $stmt->get_result();
    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            $rows[] = [
                'id' => (int) ($row['id'] ?? 0),
                'nombre' => trim((string) ($row['nombre'] ?? '')),
                'email' => trim((string) ($row['email'] ?? '')),
                'rol' => trim((string) ($row['rol'] ?? 'usuario')),
                'creado_en' => trim((string) ($row['creado_en'] ?? '')),
            ];
        }
    }
    $stmt->close();

    return $rows;
}

function admin_missions_fetch_user_by_id(mysqli $mysqli, int $userId): ?array {
    if ($userId <= 0) {
        return null;
    }

    $stmt = $mysqli->prepare('SELECT id, nombre, email, rol, creado_en FROM usuarios WHERE id = ? LIMIT 1');
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
    $stmt->close();

    if (!is_array($row)) {
        return null;
    }

    return [
        'id' => (int) ($row['id'] ?? 0),
        'nombre' => trim((string) ($row['nombre'] ?? '')),
        'email' => trim((string) ($row['email'] ?? '')),
        'rol' => trim((string) ($row['rol'] ?? 'usuario')),
        'creado_en' => trim((string) ($row['creado_en'] ?? '')),
    ];
}

function admin_missions_date_parts(string $value): array {
    $rawValue = trim($value);
    if ($rawValue === '') {
        return ['date' => '', 'time' => ''];
    }

    $parts = explode(' ', $rawValue, 2);
    $datePart = $parts[0] ?? '';
    $timePart = $parts[1] ?? '';
    $dateBits = explode('-', $datePart);
    $date = count($dateBits) === 3 ? $dateBits[2] . '/' . $dateBits[1] . '/' . $dateBits[0] : $datePart;

    return [
        'date' => $date,
        'time' => $timePart !== '' ? substr($timePart, 0, 5) : '',
    ];
}

function admin_missions_status_badge(string $status): array {
    $labels = [
        'pending' => ['label' => 'Pendiente', 'class' => 'text-warning border-warning-subtle'],
        'granted' => ['label' => 'Otorgado', 'class' => 'text-success border-success-subtle'],
        'issued' => ['label' => 'Emitido', 'class' => 'text-info border-info-subtle'],
        'assigned' => ['label' => 'Asignado', 'class' => 'text-info border-info-subtle'],
        'claimed' => ['label' => 'Reclamado', 'class' => 'text-success border-success-subtle'],
        'resolved' => ['label' => 'Resuelto', 'class' => 'text-success border-success-subtle'],
        'failed' => ['label' => 'Fallido', 'class' => 'text-danger border-danger-subtle'],
        'expired' => ['label' => 'Vencido', 'class' => 'text-secondary border-secondary-subtle'],
        'used' => ['label' => 'Usado', 'class' => 'text-secondary border-secondary-subtle'],
        'cancelled' => ['label' => 'Cancelado', 'class' => 'text-danger border-danger-subtle'],
    ];

    return $labels[$status] ?? ['label' => ucfirst($status !== '' ? $status : 'Pendiente'), 'class' => 'text-info border-info-subtle'];
}

function admin_missions_prize_label(string $prizeType): string {
    $labels = [
        'winpoints' => 'Win Points',
        'coupon' => 'Cupón',
        'immunity' => 'Escudo',
        'streaming_ticket' => 'Ticket de streaming',
    ];

    return $labels[$prizeType] ?? ($prizeType !== '' ? $prizeType : 'Premio');
}

function admin_missions_user_label(array $user): string {
    $name = trim((string) ($user['nombre'] ?? ''));
    $email = trim((string) ($user['email'] ?? ''));

    if ($name !== '' && $email !== '') {
        return $name . ' · ' . $email;
    }

    return $name !== '' ? $name : ($email !== '' ? $email : 'Usuario');
}

function admin_missions_money_label($value): string {
    return number_format((float) $value, 0, '.', ',');
}

$searchTerm = trim((string) ($_GET['q'] ?? ''));
$selectedUserId = max(0, (int) ($_GET['user_id'] ?? 0));
$matchingUsers = admin_missions_fetch_users($mysqli, $searchTerm, $searchTerm !== '' ? 20 : 12);

if ($selectedUserId <= 0 && count($matchingUsers) === 1) {
    $selectedUserId = (int) ($matchingUsers[0]['id'] ?? 0);
}

$selectedUser = $selectedUserId > 0 ? admin_missions_fetch_user_by_id($mysqli, $selectedUserId) : null;
$selectedState = $selectedUserId > 0 ? daily_missions_fetch_user_state($mysqli, $selectedUserId) : daily_missions_normalize_state_row([]);
$selectedDay = $selectedUserId > 0 ? daily_missions_fetch_user_day($mysqli, $selectedUserId) : daily_missions_normalize_day_row([]);
$selectedHistory = $selectedUserId > 0 ? daily_missions_fetch_admin_history($mysqli, $selectedUserId, 200) : [];
$selectedProgress = daily_missions_progress_percent($selectedDay);
$selectedLevelKey = $selectedUserId > 0 ? daily_missions_resolve_level_key((int) ($selectedDay['current_streak_days'] ?? 0), daily_missions_today()) : 'basic';
$selectedLevelLabel = daily_missions_level_label($selectedLevelKey);

$settings = daily_missions_fetch_settings($mysqli);
$tasks = daily_missions_fetch_tasks($mysqli, false);
$tasksActiveCount = count(array_filter($tasks, static fn (array $task): bool => !empty($task['active'])));
$prizeLevels = ['basic', 'intermediate', 'legendary'];
$prizesByLevel = [];
foreach ($prizeLevels as $levelKey) {
    $prizesByLevel[$levelKey] = daily_missions_fetch_prizes($mysqli, $levelKey, true);
}

include __DIR__ . '/includes/header.php';
?>
<style>
  .missions-admin-shell {
    display: grid;
    gap: 1.5rem;
  }
  .missions-admin-hero,
  .missions-admin-panel {
    border-radius: 1.6rem;
    border: 1px solid rgba(34, 211, 238, 0.18);
    background:
      radial-gradient(circle at top, rgba(34, 211, 238, 0.12), transparent 32%),
      linear-gradient(180deg, rgba(8, 15, 28, 0.96), rgba(13, 22, 39, 0.94));
    box-shadow: 0 18px 42px rgba(0, 0, 0, 0.24), 0 0 24px rgba(34, 211, 238, 0.08);
  }
  .missions-admin-hero {
    padding: 1.35rem;
  }
  .missions-admin-chip {
    display: inline-flex;
    align-items: center;
    gap: 0.45rem;
    padding: 0.42rem 0.8rem;
    border-radius: 999px;
    border: 1px solid rgba(34, 211, 238, 0.28);
    background: rgba(34, 211, 238, 0.08);
    color: #8cf6ff;
    font-size: 0.78rem;
    font-weight: 700;
    letter-spacing: 0.14em;
    text-transform: uppercase;
  }
  .missions-admin-title {
    font-family: 'Oxanium', 'Space Grotesk', sans-serif;
    color: #ffffff;
    letter-spacing: -0.02em;
  }
  .missions-admin-panel {
    padding: 1.25rem;
  }
  .missions-admin-label {
    color: rgba(226, 232, 240, 0.68);
    font-size: 0.72rem;
    letter-spacing: 0.18em;
    text-transform: uppercase;
  }
  .missions-admin-value {
    color: #ffffff;
    font-family: 'Oxanium', 'Space Grotesk', sans-serif;
    font-size: 1.35rem;
    font-weight: 700;
  }
  .missions-admin-table-wrap {
    border-radius: 1.35rem;
    overflow: hidden;
    border: 1px solid rgba(255, 255, 255, 0.08);
    background: rgba(8, 15, 28, 0.86);
  }
  .missions-admin-table-wrap .table {
    --bs-table-bg: transparent;
    --bs-table-color: #e5f6ff;
  }
  .missions-admin-pill {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    padding: 0.38rem 0.68rem;
    border-radius: 999px;
    border: 1px solid rgba(255, 255, 255, 0.1);
    background: rgba(255, 255, 255, 0.04);
    color: #e5f6ff;
    font-size: 0.76rem;
    font-weight: 700;
  }
  .missions-admin-user-card {
    border-radius: 1.2rem;
    border: 1px solid rgba(34, 211, 238, 0.16);
    background: rgba(8, 15, 28, 0.86);
    box-shadow: 0 12px 30px rgba(0, 0, 0, 0.18);
  }
  .missions-admin-user-card.is-active {
    border-color: rgba(34, 211, 238, 0.34);
    box-shadow: 0 0 0 1px rgba(34, 211, 238, 0.08), 0 0 18px rgba(34, 211, 238, 0.1);
  }
  .missions-admin-prize-card {
    border-radius: 1.25rem;
    border: 1px solid rgba(34, 211, 238, 0.18);
    background: rgba(8, 15, 28, 0.86);
  }
  @media (max-width: 767.98px) {
    .missions-admin-hero,
    .missions-admin-panel {
      padding: 1rem;
    }
  }
</style>

<div class="container py-4 py-lg-5 missions-admin-shell">
  <section class="missions-admin-hero">
    <div class="d-flex flex-column flex-lg-row align-items-lg-end justify-content-between gap-3">
      <div>
        <span class="missions-admin-chip mb-3">Misiones y premios</span>
        <h1 class="display-6 fw-bold missions-admin-title mb-2">Gestión de misiones diarias</h1>
        <p class="text-secondary mb-0">Filtra por usuario y revisa el historial completo de premios, razones, estado, fecha y hora. Las recompensas diarias se entregan siempre en Win Points u otro premio configurado por nivel.</p>
      </div>
      <div class="d-flex flex-wrap gap-2">
        <a href="<?php echo htmlspecialchars(app_path('/admin/dashboard'), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-outline-info rounded-pill fw-bold px-4">Dashboard</a>
        <a href="<?php echo htmlspecialchars(app_path('/admin/win-points'), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-outline-info rounded-pill fw-bold px-4">Win Points</a>
        <a href="<?php echo htmlspecialchars(app_path('/admin/cupones'), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-outline-info rounded-pill fw-bold px-4">Cupones</a>
      </div>
    </div>
  </section>

  <section class="missions-admin-panel">
    <div class="row g-3 align-items-stretch">
      <div class="col-12 col-lg-8">
        <form method="get" action="/admin/misiones-premios" class="row g-3 align-items-end">
          <div class="col-md-8">
            <label for="mission-user-query" class="form-label missions-admin-label mb-2">Buscar usuario</label>
            <input id="mission-user-query" type="text" name="q" value="<?php echo htmlspecialchars($searchTerm, ENT_QUOTES, 'UTF-8'); ?>" class="form-control form-control-lg bg-dark text-info border-info" placeholder="Nombre o correo del usuario" autocomplete="off">
          </div>
          <div class="col-md-4 d-flex gap-2">
            <button type="submit" class="btn btn-info fw-bold rounded-pill px-4 flex-grow-1">Buscar</button>
            <a href="/admin/misiones-premios" class="btn btn-outline-info fw-bold rounded-pill px-4">Limpiar</a>
          </div>
        </form>
      </div>
      <div class="col-12 col-lg-4">
        <div class="h-100 missions-admin-panel d-grid gap-2">
          <div>
            <div class="missions-admin-label mb-1">Estado del sistema</div>
            <div class="missions-admin-value"><?php echo daily_missions_enabled() ? 'Activo' : 'Desactivado'; ?></div>
          </div>
          <div class="d-flex flex-wrap gap-2">
            <span class="missions-admin-pill">Tareas activas: <?php echo number_format($tasksActiveCount); ?></span>
            <span class="missions-admin-pill">Niveles: <?php echo number_format(count($prizeLevels)); ?></span>
            <span class="missions-admin-pill">Historial: <?php echo number_format(count($selectedHistory)); ?></span>
          </div>
        </div>
      </div>
    </div>

    <?php if ($searchTerm !== '' || !empty($matchingUsers)): ?>
      <div class="mt-4">
        <div class="d-flex align-items-center justify-content-between gap-2 mb-3">
          <div>
            <h2 class="h5 mb-1 missions-admin-title">Usuarios encontrados</h2>
            <p class="text-secondary small mb-0">Haz clic en un usuario para abrir su historial diario.</p>
          </div>
          <?php if ($searchTerm !== ''): ?>
            <span class="missions-admin-pill">Filtro: <?php echo htmlspecialchars($searchTerm, ENT_QUOTES, 'UTF-8'); ?></span>
          <?php endif; ?>
        </div>
        <?php if (!empty($matchingUsers)): ?>
          <div class="row g-3">
            <?php foreach ($matchingUsers as $userRow): ?>
              <?php $isActiveUser = $selectedUserId > 0 && (int) ($userRow['id'] ?? 0) === $selectedUserId; ?>
              <div class="col-12 col-md-6 col-xl-4">
                <div class="missions-admin-user-card <?php echo $isActiveUser ? 'is-active' : ''; ?> p-3 h-100">
                  <div class="d-flex justify-content-between align-items-start gap-3 mb-2">
                    <div>
                      <div class="missions-admin-title h6 mb-1"><?php echo htmlspecialchars((string) ($userRow['nombre'] ?? 'Usuario'), ENT_QUOTES, 'UTF-8'); ?></div>
                      <div class="text-secondary small"><?php echo htmlspecialchars((string) ($userRow['email'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                    </div>
                    <span class="badge rounded-pill text-bg-dark border border-info-subtle text-info"><?php echo htmlspecialchars(strtoupper((string) ($userRow['rol'] ?? 'usuario')), ENT_QUOTES, 'UTF-8'); ?></span>
                  </div>
                  <div class="small text-secondary mb-3">Registrado: <?php echo htmlspecialchars((string) ($userRow['creado_en'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                  <a href="/admin/misiones-premios?user_id=<?php echo (int) ($userRow['id'] ?? 0); ?>" class="btn btn-outline-info w-100 rounded-pill fw-bold">Ver historial</a>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <div class="missions-admin-user-card p-4 text-center text-secondary">No se encontraron usuarios con ese filtro.</div>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <div class="mt-4">
      <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-2 mb-3">
        <div>
          <h2 class="h5 mb-1 missions-admin-title">Usuario seleccionado</h2>
          <p class="text-secondary small mb-0">Historial completo de premios con fecha, hora, motivo y estado.</p>
        </div>
        <?php if ($selectedUser): ?>
          <span class="missions-admin-pill"><?php echo htmlspecialchars(admin_missions_user_label($selectedUser), ENT_QUOTES, 'UTF-8'); ?></span>
        <?php else: ?>
          <span class="missions-admin-pill">Selecciona un usuario para ver su historial</span>
        <?php endif; ?>
      </div>

      <?php if ($selectedUser): ?>
        <div class="row g-3 mb-4">
          <div class="col-6 col-xl-3">
            <div class="missions-admin-prize-card p-3 h-100">
              <div class="missions-admin-label mb-1">Racha actual</div>
              <div class="missions-admin-value"><?php echo number_format((int) ($selectedState['current_streak_days'] ?? 0)); ?></div>
            </div>
          </div>
          <div class="col-6 col-xl-3">
            <div class="missions-admin-prize-card p-3 h-100">
              <div class="missions-admin-label mb-1">Inmunidad</div>
              <div class="missions-admin-value"><?php echo number_format((int) ($selectedState['immunity_balance'] ?? 0)); ?></div>
            </div>
          </div>
          <div class="col-6 col-xl-3">
            <div class="missions-admin-prize-card p-3 h-100">
              <div class="missions-admin-label mb-1">Progreso de hoy</div>
              <div class="missions-admin-value"><?php echo number_format((int) $selectedProgress); ?>%</div>
              <div class="small text-secondary mt-1"><?php echo number_format((int) ($selectedDay['completed_tasks_count'] ?? 0)); ?>/<?php echo number_format((int) ($selectedDay['required_tasks_count'] ?? 0)); ?> tareas</div>
            </div>
          </div>
          <div class="col-6 col-xl-3">
            <div class="missions-admin-prize-card p-3 h-100">
              <div class="missions-admin-label mb-1">Nivel del cofre</div>
              <div class="missions-admin-value"><?php echo htmlspecialchars($selectedLevelLabel, ENT_QUOTES, 'UTF-8'); ?></div>
            </div>
          </div>
        </div>

        <div class="missions-admin-table-wrap mb-4">
          <div class="table-responsive d-none d-md-block">
            <table class="table align-middle mb-0">
              <thead>
                <tr>
                  <th class="text-info text-uppercase small fw-bold bg-transparent border-bottom border-info-subtle">Fecha</th>
                  <th class="text-info text-uppercase small fw-bold bg-transparent border-bottom border-info-subtle">Hora</th>
                  <th class="text-info text-uppercase small fw-bold bg-transparent border-bottom border-info-subtle">Premio</th>
                  <th class="text-info text-uppercase small fw-bold bg-transparent border-bottom border-info-subtle">Motivo</th>
                  <th class="text-info text-uppercase small fw-bold bg-transparent border-bottom border-info-subtle">Estado</th>
                  <th class="text-info text-uppercase small fw-bold bg-transparent border-bottom border-info-subtle">Nivel</th>
                </tr>
              </thead>
              <tbody>
                <?php if (!empty($selectedHistory)): ?>
                  <?php foreach ($selectedHistory as $entry): ?>
                    <?php $dateParts = admin_missions_date_parts((string) ($entry['created_at'] ?? $entry['claimed_at'] ?? $entry['resolved_at'] ?? '')); ?>
                    <?php $statusBadge = admin_missions_status_badge((string) ($entry['reward_status'] ?? 'pending')); ?>
                    <tr>
                      <td class="bg-transparent border-bottom border-info-subtle text-secondary"><?php echo htmlspecialchars($dateParts['date'], ENT_QUOTES, 'UTF-8'); ?></td>
                      <td class="bg-transparent border-bottom border-info-subtle text-secondary"><?php echo htmlspecialchars($dateParts['time'], ENT_QUOTES, 'UTF-8'); ?></td>
                      <td class="bg-transparent border-bottom border-info-subtle text-light fw-semibold"><?php echo htmlspecialchars((string) ($entry['prize_label'] ?? admin_missions_prize_label((string) ($entry['prize_type'] ?? ''))), ENT_QUOTES, 'UTF-8'); ?><div class="small text-info"><?php echo htmlspecialchars(admin_missions_prize_label((string) ($entry['prize_type'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></div></td>
                      <td class="bg-transparent border-bottom border-info-subtle text-light"><?php echo htmlspecialchars((string) ($entry['reason'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                      <td class="bg-transparent border-bottom border-info-subtle"><span class="badge rounded-pill text-bg-dark border <?php echo htmlspecialchars($statusBadge['class'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($statusBadge['label'], ENT_QUOTES, 'UTF-8'); ?></span></td>
                      <td class="bg-transparent border-bottom border-info-subtle text-info fw-semibold"><?php echo htmlspecialchars(daily_missions_level_label((string) ($entry['level_key'] ?? $selectedLevelKey)), ENT_QUOTES, 'UTF-8'); ?></td>
                    </tr>
                  <?php endforeach; ?>
                <?php else: ?>
                  <tr>
                    <td colspan="6" class="bg-transparent border-bottom border-info-subtle text-center text-secondary py-4">Este usuario todavía no tiene premios registrados.</td>
                  </tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
          <div class="d-grid d-md-none gap-3 p-3">
            <?php if (!empty($selectedHistory)): ?>
              <?php foreach ($selectedHistory as $entry): ?>
                <?php $dateParts = admin_missions_date_parts((string) ($entry['created_at'] ?? $entry['claimed_at'] ?? $entry['resolved_at'] ?? '')); ?>
                <?php $statusBadge = admin_missions_status_badge((string) ($entry['reward_status'] ?? 'pending')); ?>
                <article class="missions-admin-prize-card p-3">
                  <div class="d-flex justify-content-between align-items-start gap-3 mb-2">
                    <div>
                      <div class="missions-admin-label mb-1"><?php echo htmlspecialchars(admin_missions_prize_label((string) ($entry['prize_type'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></div>
                      <div class="missions-admin-title h6 mb-0"><?php echo htmlspecialchars((string) ($entry['prize_label'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                    </div>
                    <span class="badge rounded-pill text-bg-dark border <?php echo htmlspecialchars($statusBadge['class'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($statusBadge['label'], ENT_QUOTES, 'UTF-8'); ?></span>
                  </div>
                  <div class="small text-secondary mb-1"><?php echo htmlspecialchars($dateParts['date'], ENT_QUOTES, 'UTF-8'); ?> <?php echo htmlspecialchars($dateParts['time'], ENT_QUOTES, 'UTF-8'); ?></div>
                  <div class="small text-light mb-1"><strong class="text-info">Motivo:</strong> <?php echo htmlspecialchars((string) ($entry['reason'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                  <div class="small text-info">Nivel: <?php echo htmlspecialchars(daily_missions_level_label((string) ($entry['level_key'] ?? $selectedLevelKey)), ENT_QUOTES, 'UTF-8'); ?></div>
                </article>
              <?php endforeach; ?>
            <?php else: ?>
              <div class="text-center text-secondary py-4">Este usuario todavía no tiene premios registrados.</div>
            <?php endif; ?>
          </div>
        </div>
      <?php else: ?>
        <div class="missions-admin-user-card p-4 text-center text-secondary mb-4">Busca un usuario y pulsa "Ver historial" para abrir su línea completa de premios de misiones diarias.</div>
      <?php endif; ?>
    </div>

    <div class="row g-3 mb-4">
      <div class="col-12 col-xl-6">
        <div class="missions-admin-panel h-100">
          <div class="d-flex align-items-center justify-content-between gap-2 mb-3">
            <div>
              <h2 class="h5 mb-1 missions-admin-title">Configuración actual</h2>
              <p class="text-secondary small mb-0">Parámetros base que controlan la experiencia diaria.</p>
            </div>
            <span class="missions-admin-pill"><?php echo htmlspecialchars((string) ($settings['title'] ?? 'Mision diaria'), ENT_QUOTES, 'UTF-8'); ?></span>
          </div>
          <div class="row g-3">
            <div class="col-sm-6">
              <div class="missions-admin-prize-card p-3 h-100">
                <div class="missions-admin-label mb-1">Objetivo diario</div>
                <div class="missions-admin-value"><?php echo number_format((int) ($settings['active_task_goal'] ?? 4)); ?> tareas</div>
              </div>
            </div>
            <div class="col-sm-6">
              <div class="missions-admin-prize-card p-3 h-100">
                <div class="missions-admin-label mb-1">Temporizador explorar</div>
                <div class="missions-admin-value"><?php echo number_format((int) ($settings['explore_timer_seconds'] ?? 7)); ?>s</div>
              </div>
            </div>
            <div class="col-sm-6">
              <div class="missions-admin-prize-card p-3 h-100">
                <div class="missions-admin-label mb-1">Temporizador compartir</div>
                <div class="missions-admin-value"><?php echo number_format((int) ($settings['share_timer_seconds'] ?? 15)); ?>s</div>
              </div>
            </div>
            <div class="col-sm-6">
              <div class="missions-admin-prize-card p-3 h-100">
                <div class="missions-admin-label mb-1">Inmunidad base</div>
                <div class="missions-admin-value"><?php echo number_format((int) ($settings['immunity_days'] ?? 1)); ?> día(s)</div>
              </div>
            </div>
          </div>
          <div class="mt-3 small text-secondary"><?php echo htmlspecialchars((string) ($settings['subtitle'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
        </div>
      </div>
      <div class="col-12 col-xl-6">
        <div class="missions-admin-panel h-100">
          <div class="d-flex align-items-center justify-content-between gap-2 mb-3">
            <div>
              <h2 class="h5 mb-1 missions-admin-title">Tareas activas</h2>
              <p class="text-secondary small mb-0">Los premios de las misiones se convierten siempre en Win Points.</p>
            </div>
            <span class="missions-admin-pill"><?php echo number_format(count($tasks)); ?> definidas</span>
          </div>
          <div class="d-grid gap-2">
            <?php foreach ($tasks as $task): ?>
              <div class="missions-admin-prize-card p-3 d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-2">
                <div>
                  <div class="missions-admin-title h6 mb-1"><?php echo htmlspecialchars((string) ($task['title'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                  <div class="text-secondary small"><?php echo htmlspecialchars((string) ($task['description'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                </div>
                <div class="text-md-end">
                  <div class="missions-admin-pill mb-1"><?php echo htmlspecialchars(daily_missions_task_type_label((string) ($task['task_type'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></div>
                  <div class="small text-info">+<?php echo number_format((int) ($task['base_points'] ?? 0)); ?> WP</div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    </div>

    <div class="missions-admin-panel">
      <div class="d-flex align-items-center justify-content-between gap-2 mb-3">
        <div>
          <h2 class="h5 mb-1 missions-admin-title">Premios por nivel</h2>
          <p class="text-secondary small mb-0">Distribución actual del cofre diario para cada nivel de racha.</p>
        </div>
        <span class="missions-admin-pill">Historial + premios</span>
      </div>
      <div class="row g-3">
        <?php foreach ($prizesByLevel as $levelKey => $levelPrizes): ?>
          <div class="col-12 col-lg-4">
            <div class="missions-admin-prize-card p-3 h-100">
              <div class="d-flex align-items-center justify-content-between gap-2 mb-3">
                <div>
                  <div class="missions-admin-label mb-1">Nivel</div>
                  <div class="missions-admin-title h6 mb-0"><?php echo htmlspecialchars(daily_missions_level_label($levelKey), ENT_QUOTES, 'UTF-8'); ?></div>
                </div>
                <span class="missions-admin-pill"><?php echo number_format(count($levelPrizes)); ?> premios</span>
              </div>
              <div class="d-grid gap-2">
                <?php foreach ($levelPrizes as $prize): ?>
                  <div class="rounded-4 border border-info-subtle p-3" style="background:rgba(5,10,18,0.72);">
                    <div class="d-flex justify-content-between align-items-start gap-2 mb-1">
                      <div class="text-light fw-semibold"><?php echo htmlspecialchars((string) ($prize['prize_label'] ?? admin_missions_prize_label((string) ($prize['prize_type'] ?? ''))), ENT_QUOTES, 'UTF-8'); ?></div>
                      <span class="badge rounded-pill text-bg-dark border border-info-subtle text-info"><?php echo htmlspecialchars(admin_missions_prize_label((string) ($prize['prize_type'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></span>
                    </div>
                    <div class="small text-secondary">Probabilidad: <?php echo number_format((float) ($prize['chance_percent'] ?? 0), 2); ?>%</div>
                    <?php if (($prize['prize_type'] ?? '') === 'winpoints'): ?>
                      <div class="small text-success">Monto: +<?php echo number_format((int) ($prize['points_amount'] ?? 0)); ?> WP</div>
                    <?php elseif (($prize['prize_type'] ?? '') === 'coupon'): ?>
                      <div class="small text-warning">Descuento: <?php echo number_format((int) ($prize['coupon_discount_percent'] ?? 0)); ?>%</div>
                    <?php elseif (($prize['prize_type'] ?? '') === 'immunity'): ?>
                      <div class="small text-info">Inmunidad: <?php echo number_format((int) ($prize['immunity_days'] ?? 0)); ?> día(s)</div>
                    <?php else: ?>
                      <div class="small text-info">Ticket asignado al usuario de streaming configurado.</div>
                    <?php endif; ?>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </section>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
