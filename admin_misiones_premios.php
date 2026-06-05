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

// ── AJAX POST handlers ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $action = trim((string) ($_POST['action'] ?? ''));

    switch ($action) {
        case 'save_settings': {
            $enabled          = (int) ($_POST['enabled'] ?? 1) > 0 ? 1 : 0;
            $title            = trim((string) ($_POST['title'] ?? 'Mision diaria'));
            $subtitle         = trim((string) ($_POST['subtitle'] ?? ''));
            $day20Mult        = max(0.0, (float) ($_POST['day20_multiplier'] ?? 4.0));
            $monthEndMult     = max(0.0, (float) ($_POST['month_end_multiplier'] ?? 10.0));
            $exploreTimer     = max(0, (int) ($_POST['explore_timer_seconds'] ?? 7));
            $shareTimer       = max(0, (int) ($_POST['share_timer_seconds'] ?? 15));
            $immunityDays     = max(0, (int) ($_POST['immunity_days'] ?? 1));
            $couponDiscount   = max(0, min(100, (int) ($_POST['coupon_discount_percent'] ?? 10)));
            $couponExpiration = max(1, (int) ($_POST['coupon_expiration_days'] ?? 30));

            $stmt = $mysqli->prepare(
                'UPDATE win_points_daily_mission_settings SET
                    enabled=?, title=?, subtitle=?,
                    day20_multiplier=?, month_end_multiplier=?,
                    explore_timer_seconds=?, share_timer_seconds=?,
                    immunity_days=?, coupon_discount_percent=?,
                    coupon_expiration_days=?
                 WHERE id=1'
            );
            if (!$stmt) { echo json_encode(['ok' => false, 'error' => $mysqli->error]); exit; }
            $stmt->bind_param('issddiiiii', $enabled, $title, $subtitle,
                $day20Mult, $monthEndMult, $exploreTimer, $shareTimer,
                $immunityDays, $couponDiscount, $couponExpiration
            );
            $stmt->execute();
            $stmt->close();
            echo json_encode(['ok' => true]);
            exit;
        }

        case 'save_task': {
            $taskId       = (int) ($_POST['task_id'] ?? 0);
            $basePoints   = max(0, (int) ($_POST['base_points'] ?? 0));
            $day20Raw     = trim((string) ($_POST['day20_multiplier'] ?? ''));
            $monthEndRaw  = trim((string) ($_POST['month_end_multiplier'] ?? ''));
            $day20        = $day20Raw    !== '' ? max(0.0, (float) $day20Raw)   : null;
            $monthEnd     = $monthEndRaw !== '' ? max(0.0, (float) $monthEndRaw) : null;

            if ($taskId <= 0) { echo json_encode(['ok' => false, 'error' => 'ID de tarea inválido.']); exit; }

            $stmt = $mysqli->prepare(
                'UPDATE win_points_daily_mission_tasks SET base_points=?, day20_multiplier=?, month_end_multiplier=? WHERE id=?'
            );
            if (!$stmt) { echo json_encode(['ok' => false, 'error' => $mysqli->error]); exit; }
            $stmt->bind_param('iddi', $basePoints, $day20, $monthEnd, $taskId);
            $stmt->execute();
            $stmt->close();
            echo json_encode(['ok' => true]);
            exit;
        }

        case 'save_prize': {
            $prizeId              = (int) ($_POST['prize_id'] ?? 0);
            $chancePercent        = max(0.0, min(100.0, (float) ($_POST['chance_percent'] ?? 0)));
            $pointsAmount         = max(0, (int) ($_POST['points_amount'] ?? 0));
            $couponDiscountPct    = max(0, min(100, (int) ($_POST['coupon_discount_percent'] ?? 0)));
            $immunityDays         = max(0, (int) ($_POST['immunity_days'] ?? 0));
            $streamingUserId      = (int) ($_POST['streaming_user_id'] ?? 0) > 0 ? (int) $_POST['streaming_user_id'] : null;
            $active               = (int) ($_POST['active'] ?? 1) > 0 ? 1 : 0;

            if ($prizeId <= 0) { echo json_encode(['ok' => false, 'error' => 'ID de premio inválido.']); exit; }

            $stmt = $mysqli->prepare(
                'UPDATE win_points_daily_mission_prizes
                 SET chance_percent=?, points_amount=?, coupon_discount_percent=?,
                     immunity_days=?, streaming_user_id=?, active=?
                 WHERE id=?'
            );
            if (!$stmt) { echo json_encode(['ok' => false, 'error' => $mysqli->error]); exit; }
            $stmt->bind_param('diiiiii', $chancePercent, $pointsAmount, $couponDiscountPct,
                $immunityDays, $streamingUserId, $active, $prizeId);
            $stmt->execute();
            $stmt->close();
            echo json_encode(['ok' => true]);
            exit;
        }

        case 'mark_reward_resolved': {
            $rewardId = (int) ($_POST['reward_id'] ?? 0);
            if ($rewardId <= 0) { echo json_encode(['ok' => false, 'error' => 'ID inválido.']); exit; }

            $now = date('Y-m-d H:i:s');
            $stmt = $mysqli->prepare(
                "UPDATE win_points_daily_mission_rewards SET reward_status='resolved', resolved_at=? WHERE id=?"
            );
            if (!$stmt) { echo json_encode(['ok' => false, 'error' => $mysqli->error]); exit; }
            $stmt->bind_param('si', $now, $rewardId);
            $stmt->execute();
            $stmt->close();
            echo json_encode(['ok' => true]);
            exit;
        }
    }

    echo json_encode(['ok' => false, 'error' => 'Acción desconocida.']);
    exit;
}

// ── Helper functions ─────────────────────────────────────────────────────
function admin_missions_date_parts(string $value): array {
    $rawValue = trim($value);
    if ($rawValue === '') return ['date' => '', 'time' => ''];
    $parts    = explode(' ', $rawValue, 2);
    $datePart = $parts[0] ?? '';
    $timePart = $parts[1] ?? '';
    $dateBits = explode('-', $datePart);
    $date     = count($dateBits) === 3 ? $dateBits[2] . '/' . $dateBits[1] . '/' . $dateBits[0] : $datePart;
    return ['date' => $date, 'time' => $timePart !== '' ? substr($timePart, 0, 5) : ''];
}

function admin_missions_status_badge(string $status): array {
    $labels = [
        'pending'   => ['label' => 'Pendiente', 'class' => 'text-warning border-warning-subtle'],
        'granted'   => ['label' => 'Otorgado',  'class' => 'text-success border-success-subtle'],
        'issued'    => ['label' => 'Emitido',   'class' => 'text-info border-info-subtle'],
        'assigned'  => ['label' => 'Asignado',  'class' => 'text-info border-info-subtle'],
        'claimed'   => ['label' => 'Reclamado', 'class' => 'text-success border-success-subtle'],
        'resolved'  => ['label' => 'Resuelto',  'class' => 'text-success border-success-subtle'],
        'failed'    => ['label' => 'Fallido',   'class' => 'text-danger border-danger-subtle'],
        'expired'   => ['label' => 'Vencido',   'class' => 'text-secondary border-secondary-subtle'],
        'used'      => ['label' => 'Usado',     'class' => 'text-secondary border-secondary-subtle'],
        'cancelled' => ['label' => 'Cancelado', 'class' => 'text-danger border-danger-subtle'],
    ];
    return $labels[$status] ?? ['label' => ucfirst($status !== '' ? $status : 'Pendiente'), 'class' => 'text-info border-info-subtle'];
}

function admin_missions_prize_label(string $prizeType): string {
    $labels = [
        'winpoints'        => 'Win Points',
        'coupon'           => 'Cupón',
        'immunity'         => 'Escudo',
        'streaming_ticket' => 'Ticket streaming',
    ];
    return $labels[$prizeType] ?? ($prizeType !== '' ? $prizeType : 'Premio');
}

function admin_missions_fetch_users_paginated(mysqli $mysqli, string $searchTerm, string $roleFilter, int $page, int $perPage): array {
    $conditions = [];
    $params     = [];
    $types      = '';

    if ($searchTerm !== '') {
        $like          = '%' . $searchTerm . '%';
        $conditions[]  = '(nombre LIKE ? OR email LIKE ?)';
        $params[]      = $like;
        $params[]      = $like;
        $types        .= 'ss';
    }
    if ($roleFilter !== '') {
        $conditions[] = 'rol = ?';
        $params[]     = $roleFilter;
        $types       .= 's';
    }

    $where  = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';
    $offset = max(0, ($page - 1) * $perPage);
    $total  = 0;

    $stmtCount = $mysqli->prepare("SELECT COUNT(*) FROM usuarios $where");
    if ($stmtCount) {
        if ($types !== '') $stmtCount->bind_param($types, ...$params);
        $stmtCount->execute();
        $stmtCount->bind_result($total);
        $stmtCount->fetch();
        $stmtCount->close();
    }

    $stmt = $mysqli->prepare("SELECT id, nombre, email, rol, creado_en FROM usuarios $where ORDER BY creado_en DESC, id DESC LIMIT $perPage OFFSET $offset");
    if (!$stmt) return ['rows' => [], 'total' => (int) $total];
    if ($types !== '') $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows   = [];
    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            $rows[] = [
                'id'        => (int)    ($row['id']        ?? 0),
                'nombre'    => trim((string) ($row['nombre']    ?? '')),
                'email'     => trim((string) ($row['email']     ?? '')),
                'rol'       => trim((string) ($row['rol']       ?? 'usuario')),
                'creado_en' => trim((string) ($row['creado_en'] ?? '')),
            ];
        }
    }
    $stmt->close();
    return ['rows' => $rows, 'total' => (int) $total];
}

function admin_missions_pagination_url(string $base, array $params, int $page): string {
    if ($page > 1) {
        $params['page'] = $page;
    } else {
        unset($params['page']);
    }
    $params = array_filter($params, static fn ($v) => $v !== '' && $v !== null);
    return $base . (empty($params) ? '' : '?' . http_build_query($params));
}

function admin_missions_fetch_all_users_list(mysqli $mysqli): array {
    $result = $mysqli->query("SELECT id, nombre, email FROM usuarios ORDER BY nombre ASC LIMIT 200");
    $rows = [];
    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            $rows[] = [
                'id'     => (int) ($row['id'] ?? 0),
                'nombre' => trim((string) ($row['nombre'] ?? '')),
                'email'  => trim((string) ($row['email']  ?? '')),
            ];
        }
    }
    return $rows;
}

// ── AJAX GET: historial modal ────────────────────────────────────────────
if (isset($_GET['modal']) && $_GET['modal'] === '1') {
    header('Content-Type: application/json; charset=utf-8');
    $uid = max(0, (int) ($_GET['user_id'] ?? 0));
    if ($uid <= 0) { echo json_encode(['ok' => false]); exit; }

    $stmt = $mysqli->prepare('SELECT id, nombre, email, rol, creado_en FROM usuarios WHERE id = ? LIMIT 1');
    if (!$stmt) { echo json_encode(['ok' => false]); exit; }
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $res  = $stmt->get_result();
    $uRow = $res instanceof mysqli_result ? $res->fetch_assoc() : null;
    $stmt->close();
    if (!is_array($uRow)) { echo json_encode(['ok' => false, 'error' => 'not found']); exit; }

    $state    = daily_missions_fetch_user_state($mysqli, $uid);
    $day      = daily_missions_fetch_user_day($mysqli, $uid);
    $history  = daily_missions_fetch_admin_history($mysqli, $uid, 200);
    $progress = daily_missions_progress_percent($day);
    $levelKey = daily_missions_resolve_level_key((int) ($day['current_streak_days'] ?? 0), daily_missions_today());

    $historyOut = [];
    foreach ($history as $entry) {
        $dp           = admin_missions_date_parts((string) ($entry['created_at'] ?? $entry['claimed_at'] ?? $entry['resolved_at'] ?? ''));
        $sb           = admin_missions_status_badge((string) ($entry['reward_status'] ?? 'pending'));
        $historyOut[] = [
            'id'               => (int) ($entry['id'] ?? 0),
            'date'             => $dp['date'],
            'time'             => $dp['time'],
            'prize_label'      => $entry['prize_label'] ?? admin_missions_prize_label((string) ($entry['prize_type'] ?? '')),
            'prize_type'       => (string) ($entry['prize_type'] ?? ''),
            'prize_type_label' => admin_missions_prize_label((string) ($entry['prize_type'] ?? '')),
            'reason'           => (string) ($entry['reason'] ?? ''),
            'reward_status'    => (string) ($entry['reward_status'] ?? 'pending'),
            'status_label'     => $sb['label'],
            'status_class'     => $sb['class'],
            'level_label'      => daily_missions_level_label((string) ($entry['level_key'] ?? $levelKey)),
        ];
    }

    echo json_encode([
        'ok'              => true,
        'user'            => [
            'id'        => (int)    $uRow['id'],
            'nombre'    => trim((string) $uRow['nombre']),
            'email'     => trim((string) $uRow['email']),
            'rol'       => trim((string) $uRow['rol']),
            'creado_en' => trim((string) $uRow['creado_en']),
        ],
        'streak'          => (int) ($state['current_streak_days'] ?? 0),
        'immunity'        => (int) ($state['immunity_balance']     ?? 0),
        'progress'        => (int) $progress,
        'completed_tasks' => (int) ($day['completed_tasks_count']  ?? 0),
        'required_tasks'  => (int) ($day['required_tasks_count']   ?? 0),
        'level_label'     => daily_missions_level_label($levelKey),
        'history'         => $historyOut,
    ]);
    exit;
}

// ── Page data ───────────────────────────────────────────────────────────
$searchTerm  = trim((string) ($_GET['q']   ?? ''));
$roleFilter  = trim((string) ($_GET['rol'] ?? ''));
$currentPage = max(1, (int) ($_GET['page'] ?? 1));
$perPage     = 15;

$usersResult   = admin_missions_fetch_users_paginated($mysqli, $searchTerm, $roleFilter, $currentPage, $perPage);
$matchingUsers = $usersResult['rows'];
$totalUsers    = $usersResult['total'];
$totalPages    = max(1, (int) ceil($totalUsers / $perPage));

$settings         = daily_missions_fetch_settings($mysqli);
$tasks            = daily_missions_fetch_tasks($mysqli, true);
$tasksActiveCount = count($tasks);
$prizeLevels      = ['basic', 'intermediate', 'legendary'];
$prizesByLevel    = [];
foreach ($prizeLevels as $lk) {
    $prizesByLevel[$lk] = daily_missions_fetch_prizes($mysqli, $lk, false);
}
$allUsersList     = admin_missions_fetch_all_users_list($mysqli);

$ajaxUrl  = app_path('/admin/misiones-premios');
$allRoles = ['admin', 'root', 'empleado', 'influencer', 'usuario'];
$pageBase = '/admin/misiones-premios';
$activeTab = trim((string) ($_GET['tab'] ?? 'historial'));
if (!in_array($activeTab, ['historial', 'config', 'tareas', 'cofre'], true)) {
    $activeTab = 'historial';
}

include __DIR__ . '/includes/header.php';
?>
<style>
  .missions-admin-shell { display: grid; gap: 1.5rem; }
  .missions-admin-hero,
  .missions-admin-panel {
    border-radius: 1.6rem;
    border: 1px solid rgba(34,211,238,.18);
    background:
      radial-gradient(circle at top, rgba(34,211,238,.12), transparent 32%),
      linear-gradient(180deg, rgba(8,15,28,.96), rgba(13,22,39,.94));
    box-shadow: 0 18px 42px rgba(0,0,0,.24), 0 0 24px rgba(34,211,238,.08);
  }
  .missions-admin-hero  { padding: 1.35rem; }
  .missions-admin-panel { padding: 1.25rem; }
  .missions-admin-chip {
    display: inline-flex; align-items: center; gap: .45rem;
    padding: .42rem .8rem; border-radius: 999px;
    border: 1px solid rgba(34,211,238,.28); background: rgba(34,211,238,.08);
    color: #8cf6ff; font-size: .78rem; font-weight: 700;
    letter-spacing: .14em; text-transform: uppercase;
  }
  .missions-admin-title { font-family: 'Oxanium','Space Grotesk',sans-serif; color: #fff; letter-spacing: -.02em; }
  .missions-admin-label { color: rgba(226,232,240,.68); font-size: .72rem; letter-spacing: .18em; text-transform: uppercase; }
  .missions-admin-value { color: #fff; font-family: 'Oxanium','Space Grotesk',sans-serif; font-size: 1.35rem; font-weight: 700; }
  .missions-admin-pill {
    display: inline-flex; align-items: center; gap: .35rem;
    padding: .38rem .68rem; border-radius: 999px;
    border: 1px solid rgba(255,255,255,.1); background: rgba(255,255,255,.04);
    color: #e5f6ff; font-size: .76rem; font-weight: 700;
  }
  .missions-admin-table-wrap {
    border-radius: 1.35rem; overflow: hidden;
    border: 1px solid rgba(255,255,255,.08); background: rgba(8,15,28,.86);
  }
  .missions-admin-table-wrap .table { --bs-table-bg: transparent; --bs-table-color: #e5f6ff; }
  .missions-admin-table-wrap .table thead th {
    color: #22d3ee; font-size: .72rem; font-weight: 700; letter-spacing: .12em;
    text-transform: uppercase; background: transparent;
    border-bottom: 1px solid rgba(34,211,238,.22); padding: .85rem 1rem;
  }
  .missions-admin-table-wrap .table tbody td {
    background: transparent; border-bottom: 1px solid rgba(255,255,255,.05);
    padding: .75rem 1rem; vertical-align: middle;
  }
  .missions-admin-table-wrap .table tbody tr:last-child td { border-bottom: none; }
  .missions-admin-table-wrap .table tbody tr:hover td { background: rgba(34,211,238,.04); }
  .missions-admin-prize-card {
    border-radius: 1.25rem; border: 1px solid rgba(34,211,238,.18); background: rgba(8,15,28,.86);
  }
  /* Tabs */
  .missions-tabs .nav-link {
    color: rgba(226,232,240,.55); border: 1px solid transparent;
    border-radius: .75rem; padding: .52rem 1.15rem; font-weight: 700; font-size: .84rem;
    transition: all .18s;
  }
  .missions-tabs .nav-link:hover { color: #8cf6ff; background: rgba(34,211,238,.07); }
  .missions-tabs .nav-link.active { color: #22d3ee; background: rgba(34,211,238,.14); border-color: rgba(34,211,238,.3); }
  /* Pagination */
  .missions-page-link {
    background: rgba(8,15,28,.9); border: 1px solid rgba(34,211,238,.2);
    color: #8cf6ff; border-radius: .6rem !important;
    font-size: .82rem; font-weight: 600; padding: .45rem .75rem;
    transition: all .18s;
  }
  .missions-page-link:hover { background: rgba(34,211,238,.12); color: #22d3ee; border-color: rgba(34,211,238,.5); }
  .page-item.active .missions-page-link { background: rgba(34,211,238,.22); color: #22d3ee; border-color: rgba(34,211,238,.5); }
  .page-item.disabled .missions-page-link { color: rgba(255,255,255,.25); cursor: default; }
  /* Form inputs inside admin panels */
  .missions-form-input {
    background: rgba(8,15,28,.86); border: 1px solid rgba(34,211,238,.22); color: #e5f6ff;
    border-radius: .65rem; padding: .45rem .75rem; font-size: .86rem; width: 100%;
    transition: border-color .18s;
  }
  .missions-form-input:focus { outline: none; border-color: rgba(34,211,238,.6); box-shadow: 0 0 0 3px rgba(34,211,238,.1); }
  .missions-form-input::placeholder { color: rgba(226,232,240,.3); }
  select.missions-form-input option { background: #0a0f1e; color: #e5f6ff; }
  /* Prize level cards */
  .prize-level-card {
    border-radius: 1.25rem; border: 1px solid rgba(255,255,255,.1);
    background: rgba(8,15,28,.86); padding: 1.25rem;
  }
  .prize-level-card.level-basic   { border-color: rgba(34,211,238,.28); }
  .prize-level-card.level-intermediate { border-color: rgba(251,146,60,.28); }
  .prize-level-card.level-legendary   { border-color: rgba(252,211,77,.38); }
  .prize-row {
    border-radius: .9rem; padding: .9rem 1rem;
    border: 1px solid rgba(255,255,255,.07); background: rgba(255,255,255,.03);
    margin-bottom: .6rem;
  }
  .save-btn {
    background: rgba(34,211,238,.12); border: 1px solid rgba(34,211,238,.35); color: #22d3ee;
    border-radius: .65rem; padding: .38rem .9rem; font-size: .8rem; font-weight: 700;
    cursor: pointer; transition: all .18s; white-space: nowrap;
  }
  .save-btn:hover { background: rgba(34,211,238,.25); }
  .save-btn:disabled { opacity: .5; cursor: default; }
  .save-feedback { font-size: .75rem; margin-left: .5rem; display: none; }
  .save-feedback.ok  { color: #4ade80; }
  .save-feedback.err { color: #f87171; }
  /* Modal */
  #modalHistorial { z-index: 14000 !important; }
  .modal-backdrop { z-index: 13900 !important; }
  #modalHistorial .modal-content {
    background: linear-gradient(180deg,#080f1c 0%,#0d1627 100%);
    border: 1px solid rgba(34,211,238,.25); border-radius: 1.5rem;
  }
  #modalHistorial .modal-header { border-bottom: 1px solid rgba(34,211,238,.18); }
  #modalHistorial .modal-footer { border-top: 1px solid rgba(34,211,238,.18); }
  .missions-modal-stat {
    border-radius: 1rem; border: 1px solid rgba(34,211,238,.16);
    background: rgba(8,15,28,.86); padding: .9rem 1rem;
  }
  @media (max-width:767.98px) {
    .missions-admin-hero, .missions-admin-panel { padding: 1rem; }
  }
</style>

<div class="container py-4 py-lg-5 missions-admin-shell">

  <!-- Hero -->
  <section class="missions-admin-hero">
    <div class="d-flex flex-column flex-lg-row align-items-lg-end justify-content-between gap-3">
      <div>
        <span class="missions-admin-chip mb-3">Misiones y premios</span>
        <h1 class="display-6 fw-bold missions-admin-title mb-2">Gestión de Misiones y Premios</h1>
        <p class="text-secondary mb-0">Configura tareas diarias, multiplicadores y premios del cofre por nivel.</p>
      </div>
      <div class="d-flex flex-wrap gap-2">
        <a href="<?php echo htmlspecialchars(app_path('/admin/dashboard'), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-outline-info rounded-pill fw-bold px-4">Dashboard</a>
        <a href="<?php echo htmlspecialchars(app_path('/admin/win-points'), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-outline-info rounded-pill fw-bold px-4">Win Points</a>
        <a href="<?php echo htmlspecialchars(app_path('/admin/cupones'), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-outline-info rounded-pill fw-bold px-4">Cupones</a>
      </div>
    </div>

    <!-- Tabs -->
    <nav class="mt-4">
      <ul class="nav missions-tabs gap-1 flex-wrap" id="missionsTabs" role="tablist">
        <li role="presentation">
          <button class="nav-link <?php echo $activeTab === 'historial' ? 'active' : ''; ?>"
                  data-bs-toggle="tab" data-bs-target="#tab-historial" type="button">
            Historial
          </button>
        </li>
        <li role="presentation">
          <button class="nav-link <?php echo $activeTab === 'config' ? 'active' : ''; ?>"
                  data-bs-toggle="tab" data-bs-target="#tab-config" type="button">
            Configuración
          </button>
        </li>
        <li role="presentation">
          <button class="nav-link <?php echo $activeTab === 'tareas' ? 'active' : ''; ?>"
                  data-bs-toggle="tab" data-bs-target="#tab-tareas" type="button">
            Tareas y multiplicadores
          </button>
        </li>
        <li role="presentation">
          <button class="nav-link <?php echo $activeTab === 'cofre' ? 'active' : ''; ?>"
                  data-bs-toggle="tab" data-bs-target="#tab-cofre" type="button">
            Cofre por nivel
          </button>
        </li>
      </ul>
    </nav>
  </section>

  <!-- Tab content -->
  <div class="tab-content">

    <!-- ══════════════════ TAB: HISTORIAL ══════════════════ -->
    <div class="tab-pane fade <?php echo $activeTab === 'historial' ? 'show active' : ''; ?>" id="tab-historial">
      <section class="missions-admin-panel">

        <div class="d-flex align-items-center justify-content-between gap-2 mb-3">
          <div>
            <h2 class="h5 mb-1 missions-admin-title">Historial por usuario</h2>
            <p class="text-secondary small mb-0">Busca un usuario y revisa su historial completo de premios.</p>
          </div>
          <div class="d-flex flex-wrap gap-2">
            <span class="missions-admin-pill">Sistema: <?php echo daily_missions_enabled() ? 'Activo' : 'Inactivo'; ?></span>
            <span class="missions-admin-pill">Tareas activas: <?php echo number_format($tasksActiveCount); ?></span>
          </div>
        </div>

        <!-- Filters -->
        <form method="get" action="<?php echo htmlspecialchars($pageBase, ENT_QUOTES, 'UTF-8'); ?>" class="row g-3 mb-4 align-items-end">
          <input type="hidden" name="tab" value="historial">
          <div class="col-12 col-md-5 col-xl-4">
            <label for="q-input" class="missions-admin-label mb-1">Buscar usuario</label>
            <input id="q-input" type="text" name="q" value="<?php echo htmlspecialchars($searchTerm, ENT_QUOTES, 'UTF-8'); ?>"
                   class="form-control bg-dark text-info border-info" placeholder="Nombre o correo" autocomplete="off">
          </div>
          <div class="col-12 col-md-3 col-xl-2">
            <label for="rol-select" class="missions-admin-label mb-1">Rol</label>
            <select id="rol-select" name="rol" class="form-select bg-dark text-info border-info">
              <option value="">Todos los roles</option>
              <?php foreach ($allRoles as $r): ?>
                <option value="<?php echo htmlspecialchars($r, ENT_QUOTES, 'UTF-8'); ?>"
                        <?php echo $roleFilter === $r ? 'selected' : ''; ?>>
                  <?php echo ucfirst($r); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-auto d-flex gap-2">
            <button type="submit" class="btn btn-info fw-bold rounded-pill px-4">Buscar</button>
            <a href="<?php echo htmlspecialchars($pageBase, ENT_QUOTES, 'UTF-8'); ?>?tab=historial" class="btn btn-outline-info fw-bold rounded-pill px-4">Limpiar</a>
          </div>
        </form>

        <p class="text-secondary small mb-3">
          Mostrando <strong class="text-info"><?php echo count($matchingUsers); ?></strong>
          de <strong class="text-info"><?php echo number_format($totalUsers); ?></strong> usuarios.
        </p>

        <!-- Table -->
        <div class="missions-admin-table-wrap">
          <div class="table-responsive">
            <table class="table align-middle mb-0">
              <thead>
                <tr>
                  <th style="width:50px">#</th>
                  <th>Usuario</th>
                  <th>Email</th>
                  <th>Rol</th>
                  <th>Registrado</th>
                  <th class="text-end">Acciones</th>
                </tr>
              </thead>
              <tbody>
                <?php if (!empty($matchingUsers)): ?>
                  <?php foreach ($matchingUsers as $i => $uRow): ?>
                    <tr>
                      <td class="text-secondary small"><?php echo ($currentPage - 1) * $perPage + $i + 1; ?></td>
                      <td><div class="fw-semibold text-light"><?php echo htmlspecialchars((string) ($uRow['nombre'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div></td>
                      <td class="text-secondary small"><?php echo htmlspecialchars((string) ($uRow['email'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                      <td>
                        <span class="badge rounded-pill text-bg-dark border border-info-subtle text-info">
                          <?php echo htmlspecialchars(strtoupper((string) ($uRow['rol'] ?? 'usuario')), ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                      </td>
                      <td class="text-secondary small"><?php echo htmlspecialchars((string) ($uRow['creado_en'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                      <td class="text-end">
                        <button type="button"
                                class="btn btn-outline-info btn-sm rounded-pill fw-bold btn-ver-historial"
                                data-user-id="<?php echo (int) ($uRow['id'] ?? 0); ?>"
                                data-user-name="<?php echo htmlspecialchars((string) ($uRow['nombre'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                data-user-rol="<?php echo htmlspecialchars(strtoupper((string) ($uRow['rol'] ?? '')), ENT_QUOTES, 'UTF-8'); ?>"
                                data-user-email="<?php echo htmlspecialchars((string) ($uRow['email'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                          Ver historial
                        </button>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php else: ?>
                  <tr>
                    <td colspan="6" class="text-center text-secondary py-5">
                      No se encontraron usuarios<?php echo $searchTerm !== '' ? ' con "' . htmlspecialchars($searchTerm, ENT_QUOTES, 'UTF-8') . '"' : ''; ?>.
                    </td>
                  </tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
          <?php
            $pParams = ['tab' => 'historial'];
            if ($searchTerm !== '') $pParams['q']   = $searchTerm;
            if ($roleFilter  !== '') $pParams['rol'] = $roleFilter;
            $pageStart = max(1, $currentPage - 2);
            $pageEnd   = min($totalPages, $currentPage + 2);
          ?>
          <nav class="mt-4" aria-label="Paginación">
            <ul class="pagination justify-content-center gap-1 flex-wrap mb-0">
              <li class="page-item <?php echo $currentPage <= 1 ? 'disabled' : ''; ?>">
                <a class="page-link missions-page-link"
                   href="<?php echo $currentPage > 1 ? htmlspecialchars(admin_missions_pagination_url($pageBase, $pParams, $currentPage - 1), ENT_QUOTES, 'UTF-8') : '#'; ?>">← Anterior</a>
              </li>
              <?php if ($pageStart > 1): ?>
                <li class="page-item"><a class="page-link missions-page-link" href="<?php echo htmlspecialchars(admin_missions_pagination_url($pageBase, $pParams, 1), ENT_QUOTES, 'UTF-8'); ?>">1</a></li>
                <?php if ($pageStart > 2): ?><li class="page-item disabled"><span class="page-link missions-page-link">…</span></li><?php endif; ?>
              <?php endif; ?>
              <?php for ($p = $pageStart; $p <= $pageEnd; $p++): ?>
                <li class="page-item <?php echo $p === $currentPage ? 'active' : ''; ?>">
                  <a class="page-link missions-page-link" href="<?php echo htmlspecialchars(admin_missions_pagination_url($pageBase, $pParams, $p), ENT_QUOTES, 'UTF-8'); ?>"><?php echo $p; ?></a>
                </li>
              <?php endfor; ?>
              <?php if ($pageEnd < $totalPages): ?>
                <?php if ($pageEnd < $totalPages - 1): ?><li class="page-item disabled"><span class="page-link missions-page-link">…</span></li><?php endif; ?>
                <li class="page-item"><a class="page-link missions-page-link" href="<?php echo htmlspecialchars(admin_missions_pagination_url($pageBase, $pParams, $totalPages), ENT_QUOTES, 'UTF-8'); ?>"><?php echo $totalPages; ?></a></li>
              <?php endif; ?>
              <li class="page-item <?php echo $currentPage >= $totalPages ? 'disabled' : ''; ?>">
                <a class="page-link missions-page-link"
                   href="<?php echo $currentPage < $totalPages ? htmlspecialchars(admin_missions_pagination_url($pageBase, $pParams, $currentPage + 1), ENT_QUOTES, 'UTF-8') : '#'; ?>">Siguiente →</a>
              </li>
            </ul>
            <p class="text-center text-secondary small mt-2 mb-0">Página <?php echo $currentPage; ?> de <?php echo $totalPages; ?></p>
          </nav>
        <?php endif; ?>

      </section>
    </div>

    <!-- ══════════════════ TAB: CONFIGURACIÓN ══════════════════ -->
    <div class="tab-pane fade <?php echo $activeTab === 'config' ? 'show active' : ''; ?>" id="tab-config">
      <section class="missions-admin-panel">
        <div class="mb-4">
          <h2 class="h5 mb-1 missions-admin-title">Configuración general</h2>
          <p class="text-secondary small mb-0">Parámetros base del sistema de misiones diarias.</p>
        </div>

        <form id="formSettings" class="row g-4">
          <div class="col-12">
            <label class="missions-admin-label mb-1">Sistema</label>
            <div class="d-flex gap-3 mt-1">
              <div class="form-check">
                <input class="form-check-input" type="radio" name="enabled" value="1" id="sysOn"
                       <?php echo (int) ($settings['enabled'] ?? 1) === 1 ? 'checked' : ''; ?>>
                <label class="form-check-label text-success" for="sysOn">Activo</label>
              </div>
              <div class="form-check">
                <input class="form-check-input" type="radio" name="enabled" value="0" id="sysOff"
                       <?php echo (int) ($settings['enabled'] ?? 1) === 0 ? 'checked' : ''; ?>>
                <label class="form-check-label text-danger" for="sysOff">Inactivo</label>
              </div>
            </div>
          </div>
          <div class="col-12 col-md-6">
            <label class="missions-admin-label mb-1">Título</label>
            <input type="text" name="title" value="<?php echo htmlspecialchars((string) ($settings['title'] ?? 'Mision diaria'), ENT_QUOTES, 'UTF-8'); ?>" class="missions-form-input" required>
          </div>
          <div class="col-12 col-md-6">
            <label class="missions-admin-label mb-1">Subtítulo</label>
            <input type="text" name="subtitle" value="<?php echo htmlspecialchars((string) ($settings['subtitle'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" class="missions-form-input">
          </div>
          <div class="col-6 col-md-3">
            <label class="missions-admin-label mb-1">Timer explorar (seg)</label>
            <input type="number" name="explore_timer_seconds" min="0" max="300" value="<?php echo (int) ($settings['explore_timer_seconds'] ?? 7); ?>" class="missions-form-input">
          </div>
          <div class="col-6 col-md-3">
            <label class="missions-admin-label mb-1">Timer compartir (seg)</label>
            <input type="number" name="share_timer_seconds" min="0" max="300" value="<?php echo (int) ($settings['share_timer_seconds'] ?? 15); ?>" class="missions-form-input">
          </div>
          <div class="col-6 col-md-3">
            <label class="missions-admin-label mb-1">Días inmunidad base</label>
            <input type="number" name="immunity_days" min="0" max="30" value="<?php echo (int) ($settings['immunity_days'] ?? 1); ?>" class="missions-form-input">
          </div>
          <div class="col-12">
            <div class="missions-admin-prize-card p-3">
              <p class="text-secondary small mb-3">Multiplicadores globales (aplican cuando la tarea no tiene multiplicador individual definido).</p>
              <div class="row g-3">
                <div class="col-6 col-md-3">
                  <label class="missions-admin-label mb-1">x Día 20 global</label>
                  <input type="number" name="day20_multiplier" min="0" step="0.5" value="<?php echo number_format((float) ($settings['day20_multiplier'] ?? 4), 2, '.', ''); ?>" class="missions-form-input">
                </div>
                <div class="col-6 col-md-3">
                  <label class="missions-admin-label mb-1">x Final de mes global</label>
                  <input type="number" name="month_end_multiplier" min="0" step="0.5" value="<?php echo number_format((float) ($settings['month_end_multiplier'] ?? 10), 2, '.', ''); ?>" class="missions-form-input">
                </div>
              </div>
            </div>
          </div>
          <div class="col-12">
            <div class="missions-admin-prize-card p-3">
              <p class="text-secondary small mb-3">Configuración de cupones generados por el cofre.</p>
              <div class="row g-3">
                <div class="col-6 col-md-3">
                  <label class="missions-admin-label mb-1">% descuento base</label>
                  <input type="number" name="coupon_discount_percent" min="1" max="100" value="<?php echo (int) ($settings['coupon_discount_percent'] ?? 10); ?>" class="missions-form-input">
                </div>
                <div class="col-6 col-md-3">
                  <label class="missions-admin-label mb-1">Expiración (días)</label>
                  <input type="number" name="coupon_expiration_days" min="1" max="365" value="<?php echo (int) ($settings['coupon_expiration_days'] ?? 30); ?>" class="missions-form-input">
                </div>
              </div>
            </div>
          </div>
          <div class="col-12">
            <button type="submit" class="btn btn-info rounded-pill fw-bold px-5">Guardar configuración</button>
            <span class="save-feedback" id="settingsFeedback"></span>
          </div>
        </form>

      </section>
    </div>

    <!-- ══════════════════ TAB: TAREAS ══════════════════ -->
    <div class="tab-pane fade <?php echo $activeTab === 'tareas' ? 'show active' : ''; ?>" id="tab-tareas">
      <section class="missions-admin-panel">
        <div class="mb-4">
          <h2 class="h5 mb-1 missions-admin-title">Tareas y multiplicadores</h2>
          <p class="text-secondary small mb-0">
            Define los WP base por tarea y el multiplicador aplicado en el Día 20 y en el Último día del mes.
            El multiplicador actúa solo si el usuario completó las tareas <strong>sin interrupciones</strong> hasta ese día.
            Deja vacío para usar el multiplicador global de Configuración.
          </p>
        </div>

        <div class="missions-admin-table-wrap">
          <div class="table-responsive">
            <table class="table align-middle mb-0">
              <thead>
                <tr>
                  <th>Tarea</th>
                  <th style="width:130px">WP diario</th>
                  <th style="width:150px">x Día 20</th>
                  <th style="width:150px">x Final de mes</th>
                  <th style="width:100px"></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($tasks as $task): ?>
                  <tr data-task-id="<?php echo (int) ($task['id'] ?? 0); ?>">
                    <td>
                      <div class="fw-semibold text-light"><?php echo htmlspecialchars((string) ($task['title'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                      <div class="text-secondary small"><?php echo htmlspecialchars(daily_missions_task_type_label((string) ($task['task_type'] ?? '')), ENT_QUOTES, 'UTF-8'); ?>
                        <?php if (!($task['active'] ?? false)): ?><span class="badge bg-secondary ms-1">inactiva</span><?php endif; ?>
                      </div>
                    </td>
                    <td>
                      <input type="number" class="missions-form-input task-base-points" min="0" max="9999"
                             value="<?php echo (int) ($task['base_points'] ?? 0); ?>"
                             placeholder="WP">
                    </td>
                    <td>
                      <input type="number" class="missions-form-input task-day20-mult" min="0" step="0.1"
                             value="<?php echo isset($task['day20_multiplier']) && $task['day20_multiplier'] !== null ? number_format((float) $task['day20_multiplier'], 2, '.', '') : ''; ?>"
                             placeholder="global">
                    </td>
                    <td>
                      <input type="number" class="missions-form-input task-monthend-mult" min="0" step="0.1"
                             value="<?php echo isset($task['month_end_multiplier']) && $task['month_end_multiplier'] !== null ? number_format((float) $task['month_end_multiplier'], 2, '.', '') : ''; ?>"
                             placeholder="global">
                    </td>
                    <td>
                      <button class="save-btn btn-save-task" type="button">Guardar</button>
                      <span class="save-feedback task-feedback"></span>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
        <p class="text-secondary small mt-3 mb-0">
          Ejemplo: tarea con 2 WP diario, x4 en día 20 → el usuario recibe 8 WP ese día si no falló ninguno.
          "global" usa el multiplicador de Configuración.
        </p>

      </section>
    </div>

    <!-- ══════════════════ TAB: COFRE ══════════════════ -->
    <div class="tab-pane fade <?php echo $activeTab === 'cofre' ? 'show active' : ''; ?>" id="tab-cofre">
      <section class="missions-admin-panel">
        <div class="mb-4">
          <h2 class="h5 mb-1 missions-admin-title">Cofre por nivel</h2>
          <p class="text-secondary small mb-0">
            Configura los premios de cada nivel del cofre. Probabilidad = 0 deshabilita ese premio.
            Las probabilidades no necesitan sumar 100: el sistema pondera proporcionalmente.
          </p>
        </div>

        <div class="row g-4">
          <?php foreach ($prizesByLevel as $levelKey => $levelPrizes): ?>
            <?php $palette = daily_missions_level_palette($levelKey); ?>
            <div class="col-12 col-xl-4">
              <div class="prize-level-card level-<?php echo htmlspecialchars($levelKey, ENT_QUOTES, 'UTF-8'); ?>">

                <div class="d-flex align-items-center justify-content-between mb-3">
                  <div class="missions-admin-title h6 mb-0" style="color:<?php echo htmlspecialchars($palette['accent'], ENT_QUOTES, 'UTF-8'); ?>">
                    Cofre <?php echo htmlspecialchars(daily_missions_level_label($levelKey), ENT_QUOTES, 'UTF-8'); ?>
                  </div>
                  <span class="missions-admin-pill" style="border-color:<?php echo htmlspecialchars($palette['border'], ENT_QUOTES, 'UTF-8'); ?>">
                    <?php echo $levelKey === 'basic' ? 'Racha 1-19' : ($levelKey === 'intermediate' ? 'Racha 20+' : 'Último día del mes'); ?>
                  </span>
                </div>

                <?php foreach ($levelPrizes as $prize): ?>
                  <?php
                    $pt = (string) ($prize['prize_type'] ?? 'winpoints');
                    $prizeIcons = ['winpoints' => '✦', 'coupon' => '%', 'immunity' => '🛡', 'streaming_ticket' => '▶'];
                    $prizeColors = ['winpoints' => '#22d3ee', 'coupon' => '#fbbf24', 'immunity' => '#a78bfa', 'streaming_ticket' => '#f472b6'];
                  ?>
                  <div class="prize-row" data-prize-id="<?php echo (int) ($prize['id'] ?? 0); ?>">
                    <div class="d-flex align-items-center gap-2 mb-2">
                      <span style="color:<?php echo $prizeColors[$pt] ?? '#8cf6ff'; ?>;font-size:1.1rem"><?php echo $prizeIcons[$pt] ?? '?'; ?></span>
                      <strong class="text-light" style="font-size:.88rem"><?php echo htmlspecialchars(admin_missions_prize_label($pt), ENT_QUOTES, 'UTF-8'); ?></strong>
                    </div>

                    <div class="row g-2 align-items-end">
                      <div class="col-6">
                        <label class="missions-admin-label mb-1">Probabilidad %</label>
                        <input type="number" class="missions-form-input prize-chance" min="0" max="100" step="0.01"
                               value="<?php echo number_format((float) ($prize['chance_percent'] ?? 0), 2, '.', ''); ?>"
                               placeholder="0 = desactivado">
                      </div>

                      <?php if ($pt === 'winpoints'): ?>
                        <div class="col-6">
                          <label class="missions-admin-label mb-1">Cantidad WP</label>
                          <input type="number" class="missions-form-input prize-points" min="0" max="99999"
                                 value="<?php echo (int) ($prize['points_amount'] ?? 0); ?>">
                        </div>
                      <?php elseif ($pt === 'coupon'): ?>
                        <div class="col-6">
                          <label class="missions-admin-label mb-1">% descuento</label>
                          <input type="number" class="missions-form-input prize-coupon-pct" min="1" max="100"
                                 value="<?php echo (int) ($prize['coupon_discount_percent'] ?? 10); ?>">
                        </div>
                      <?php elseif ($pt === 'immunity'): ?>
                        <div class="col-6">
                          <label class="missions-admin-label mb-1">Días inmunidad</label>
                          <input type="number" class="missions-form-input prize-immunity" min="1" max="30"
                                 value="<?php echo (int) ($prize['immunity_days'] ?? 1); ?>">
                        </div>
                      <?php elseif ($pt === 'streaming_ticket'): ?>
                        <div class="col-12 mt-1">
                          <label class="missions-admin-label mb-1">Usuario de streaming a regalar</label>
                          <select class="missions-form-input prize-streaming-user">
                            <option value="0">— Usar el de Configuración general —</option>
                            <?php foreach ($allUsersList as $u): ?>
                              <option value="<?php echo (int) $u['id']; ?>"
                                      <?php echo (int) ($prize['streaming_user_id'] ?? 0) === (int) $u['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars(($u['nombre'] !== '' ? $u['nombre'] : $u['email']), ENT_QUOTES, 'UTF-8'); ?>
                                (<?php echo htmlspecialchars($u['email'], ENT_QUOTES, 'UTF-8'); ?>)
                              </option>
                            <?php endforeach; ?>
                          </select>
                        </div>
                      <?php endif; ?>

                      <div class="col-12 d-flex align-items-center gap-2 mt-1">
                        <div class="form-check mb-0">
                          <input class="form-check-input prize-active" type="checkbox" id="active-<?php echo (int) ($prize['id'] ?? 0); ?>"
                                 <?php echo !empty($prize['active']) ? 'checked' : ''; ?>>
                          <label class="form-check-label text-secondary small" for="active-<?php echo (int) ($prize['id'] ?? 0); ?>">Activo</label>
                        </div>
                        <button class="save-btn btn-save-prize ms-auto" type="button">Guardar</button>
                        <span class="save-feedback prize-feedback"></span>
                      </div>
                    </div>
                  </div>
                <?php endforeach; ?>

              </div>
            </div>
          <?php endforeach; ?>
        </div>

      </section>
    </div>

  </div><!-- /tab-content -->

</div><!-- /container -->

<!-- Modal Historial -->
<div class="modal fade" id="modalHistorial" tabindex="-1" aria-labelledby="modalHistorialLabel" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header px-4 pt-4 pb-3">
        <div>
          <h5 class="modal-title missions-admin-title mb-0" id="modalHistorialLabel">Historial</h5>
          <div class="d-flex align-items-center gap-2 mt-1" id="modalHistorialMeta"></div>
        </div>
        <button type="button" class="btn-close btn-close-white ms-3" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body p-4" id="modalHistorialBody">
        <div class="text-center py-5">
          <div class="spinner-border text-info" role="status"></div>
          <p class="text-secondary mt-3 mb-0">Cargando historial…</p>
        </div>
      </div>
      <div class="modal-footer px-4 pb-4 pt-3 justify-content-end">
        <button type="button" class="btn btn-outline-info rounded-pill px-4 fw-bold" data-bs-dismiss="modal">Cerrar</button>
      </div>
    </div>
  </div>
</div>

<script>
(function () {
  const ajaxBase = <?php echo json_encode($ajaxUrl); ?>;

  function esc(str) {
    return String(str ?? '')
      .replace(/&/g, '&amp;').replace(/</g, '&lt;')
      .replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
  }

  // ─── Modal historial ──────────────────────────────────────────────
  function buildModalHtml(data) {
    let html = '<div class="row g-3 mb-4">'
      + stat('Racha actual', data.streak)
      + stat('Inmunidad', data.immunity)
      + stat('Progreso hoy', data.progress + '%', data.completed_tasks + '/' + data.required_tasks + ' tareas')
      + stat('Nivel del cofre', esc(data.level_label))
      + '</div>';

    if (!data.history || data.history.length === 0) {
      html += '<div class="text-center text-secondary py-5">Este usuario no tiene premios registrados aún.</div>';
      return html;
    }

    html += '<div class="missions-admin-table-wrap"><div class="table-responsive"><table class="table align-middle mb-0">'
      + '<thead><tr>'
      + th('Fecha') + th('Hora') + th('Premio') + th('Motivo') + th('Estado') + th('Nivel') + th('')
      + '</tr></thead><tbody>';

    for (const e of data.history) {
      const isTicket   = e.prize_type === 'streaming_ticket';
      const canResolve = isTicket && (e.reward_status === 'assigned' || e.reward_status === 'pending');
      const resolveBtn = canResolve
        ? '<button class="save-btn btn-resolve-ticket" data-reward-id="' + esc(e.id) + '" type="button">Marcar resuelto</button>'
        : '';
      html += '<tr>'
        + td('<span class="text-secondary">' + esc(e.date) + '</span>')
        + td('<span class="text-secondary">' + esc(e.time) + '</span>')
        + td('<span class="fw-semibold text-light">' + esc(e.prize_label) + '</span><div class="small text-info">' + esc(e.prize_type_label) + '</div>')
        + td('<span class="text-light">' + esc(e.reason) + '</span>')
        + td('<span class="badge rounded-pill text-bg-dark border ' + esc(e.status_class) + '" id="rsts-' + esc(e.id) + '">' + esc(e.status_label) + '</span>')
        + td('<span class="text-info fw-semibold">' + esc(e.level_label) + '</span>')
        + td(resolveBtn)
        + '</tr>';
    }

    html += '</tbody></table></div></div>';
    return html;
  }

  function stat(label, value, sub) {
    return '<div class="col-6 col-md-3"><div class="missions-modal-stat">'
      + '<div class="missions-admin-label mb-1">' + esc(label) + '</div>'
      + '<div class="missions-admin-value">' + esc(value) + '</div>'
      + (sub ? '<div class="small text-secondary mt-1">' + esc(sub) + '</div>' : '')
      + '</div></div>';
  }

  function th(text) {
    return '<th class="text-info text-uppercase small fw-bold bg-transparent border-bottom border-info-subtle">' + esc(text) + '</th>';
  }

  function td(inner) {
    return '<td class="bg-transparent border-bottom border-info-subtle">' + inner + '</td>';
  }

  // Move modal to body to escape stacking context issues
  const _modalEl = document.getElementById('modalHistorial');
  if (_modalEl && _modalEl.parentElement !== document.body) {
    document.body.appendChild(_modalEl);
  }

  document.addEventListener('click', function (e) {
    const histBtn = e.target.closest('.btn-ver-historial');
    if (histBtn) {
      const userId    = histBtn.dataset.userId;
      const userName  = histBtn.dataset.userName;
      const userRol   = histBtn.dataset.userRol;
      const userEmail = histBtn.dataset.userEmail;

      document.getElementById('modalHistorialLabel').textContent = userName;
      document.getElementById('modalHistorialMeta').innerHTML =
        '<span class="badge rounded-pill text-bg-dark border border-info-subtle text-info">' + esc(userRol) + '</span>'
        + '<span class="text-secondary small">' + esc(userEmail) + '</span>';
      document.getElementById('modalHistorialBody').innerHTML =
        '<div class="text-center py-5"><div class="spinner-border text-info" role="status"></div>'
        + '<p class="text-secondary mt-3 mb-0">Cargando historial…</p></div>';

      bootstrap.Modal.getOrCreateInstance(document.getElementById('modalHistorial')).show();

      fetch(ajaxBase + '?modal=1&user_id=' + encodeURIComponent(userId))
        .then(r => r.json())
        .then(data => {
          if (!data.ok) {
            document.getElementById('modalHistorialBody').innerHTML =
              '<div class="text-center text-danger py-5">Error al cargar el historial.</div>';
            return;
          }
          document.getElementById('modalHistorialBody').innerHTML = buildModalHtml(data);
        })
        .catch(() => {
          document.getElementById('modalHistorialBody').innerHTML =
            '<div class="text-center text-danger py-5">Error de conexión. Intenta de nuevo.</div>';
        });
      return;
    }

    // Marcar ticket como resuelto
    const resolveBtn = e.target.closest('.btn-resolve-ticket');
    if (resolveBtn) {
      const rewardId = resolveBtn.dataset.rewardId;
      resolveBtn.disabled = true;
      const fd = new FormData();
      fd.append('action', 'mark_reward_resolved');
      fd.append('reward_id', rewardId);
      fetch(ajaxBase, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
          if (data.ok) {
            resolveBtn.textContent = 'Resuelto ✓';
            resolveBtn.style.color = '#4ade80';
            const badge = document.getElementById('rsts-' + rewardId);
            if (badge) { badge.textContent = 'Resuelto'; badge.className = 'badge rounded-pill text-bg-dark border text-success border-success-subtle'; }
          } else {
            resolveBtn.disabled = false;
            resolveBtn.textContent = 'Error';
          }
        })
        .catch(() => { resolveBtn.disabled = false; });
      return;
    }
  });

  // ─── Guardar Configuración ────────────────────────────────────────
  const formSettings = document.getElementById('formSettings');
  if (formSettings) {
    formSettings.addEventListener('submit', function (e) {
      e.preventDefault();
      const fb  = document.getElementById('settingsFeedback');
      const btn = formSettings.querySelector('[type=submit]');
      btn.disabled = true;
      fb.style.display = 'none';

      const fd = new FormData(formSettings);
      fd.append('action', 'save_settings');

      fetch(ajaxBase, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
          btn.disabled = false;
          fb.style.display = 'inline';
          if (data.ok) {
            fb.textContent = '✓ Guardado';
            fb.className = 'save-feedback ok';
          } else {
            fb.textContent = '✗ Error: ' + (data.error || 'desconocido');
            fb.className = 'save-feedback err';
          }
          setTimeout(() => { fb.style.display = 'none'; }, 3000);
        })
        .catch(() => { btn.disabled = false; });
    });
  }

  // ─── Guardar tarea ────────────────────────────────────────────────
  document.querySelectorAll('.btn-save-task').forEach(function (btn) {
    btn.addEventListener('click', function () {
      const row    = btn.closest('tr');
      const taskId = row.dataset.taskId;
      const fb     = row.querySelector('.task-feedback');
      btn.disabled = true;
      fb.style.display = 'none';

      const fd = new FormData();
      fd.append('action', 'save_task');
      fd.append('task_id', taskId);
      fd.append('base_points', row.querySelector('.task-base-points').value);
      fd.append('day20_multiplier', row.querySelector('.task-day20-mult').value);
      fd.append('month_end_multiplier', row.querySelector('.task-monthend-mult').value);

      fetch(ajaxBase, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
          btn.disabled = false;
          fb.style.display = 'inline';
          if (data.ok) { fb.textContent = '✓'; fb.className = 'save-feedback ok'; }
          else          { fb.textContent = '✗'; fb.className = 'save-feedback err'; }
          setTimeout(() => { fb.style.display = 'none'; }, 2500);
        })
        .catch(() => { btn.disabled = false; });
    });
  });

  // ─── Guardar premio ───────────────────────────────────────────────
  document.querySelectorAll('.btn-save-prize').forEach(function (btn) {
    btn.addEventListener('click', function () {
      const row     = btn.closest('.prize-row');
      const prizeId = row.dataset.prizeId;
      const fb      = row.querySelector('.prize-feedback');
      btn.disabled  = true;
      fb.style.display = 'none';

      const fd = new FormData();
      fd.append('action', 'save_prize');
      fd.append('prize_id', prizeId);
      fd.append('chance_percent',  row.querySelector('.prize-chance')?.value ?? '0');
      fd.append('points_amount',   row.querySelector('.prize-points')?.value ?? '0');
      fd.append('coupon_discount_percent', row.querySelector('.prize-coupon-pct')?.value ?? '0');
      fd.append('immunity_days',   row.querySelector('.prize-immunity')?.value ?? '0');
      fd.append('streaming_user_id', row.querySelector('.prize-streaming-user')?.value ?? '0');
      fd.append('active', row.querySelector('.prize-active')?.checked ? '1' : '0');

      fetch(ajaxBase, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
          btn.disabled = false;
          fb.style.display = 'inline';
          if (data.ok) { fb.textContent = '✓'; fb.className = 'save-feedback ok'; }
          else          { fb.textContent = '✗'; fb.className = 'save-feedback err'; }
          setTimeout(() => { fb.style.display = 'none'; }, 2500);
        })
        .catch(() => { btn.disabled = false; });
    });
  });

})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
