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
        'streaming_ticket' => 'Ticket de streaming',
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

// ── AJAX: historial modal ────────────────────────────────────────────────
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
            'date'             => $dp['date'],
            'time'             => $dp['time'],
            'prize_label'      => $entry['prize_label'] ?? admin_missions_prize_label((string) ($entry['prize_type'] ?? '')),
            'prize_type_label' => admin_missions_prize_label((string) ($entry['prize_type'] ?? '')),
            'reason'           => (string) ($entry['reason'] ?? ''),
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
$tasks            = daily_missions_fetch_tasks($mysqli, false);
$tasksActiveCount = count(array_filter($tasks, static fn (array $t): bool => !empty($t['active'])));
$prizeLevels      = ['basic', 'intermediate', 'legendary'];
$prizesByLevel    = [];
foreach ($prizeLevels as $lk) {
    $prizesByLevel[$lk] = daily_missions_fetch_prizes($mysqli, $lk, true);
}

$ajaxUrl  = app_path('/admin/misiones-premios');
$allRoles = ['admin', 'root', 'empleado', 'influencer', 'usuario'];
$pageBase = '/admin/misiones-premios';

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
  /* Modal — z-index por encima del header (máximo: 13100) */
  #modalHistorial { z-index: 14000 !important; }
  /* Backdrop override: CSS es más fiable que timing vía rAF */
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
        <h1 class="display-6 fw-bold missions-admin-title mb-2">Gestión de misiones diarias</h1>
        <p class="text-secondary mb-0">Filtra por usuario y revisa el historial completo de premios, razones, estado, fecha y hora.</p>
      </div>
      <div class="d-flex flex-wrap gap-2">
        <a href="<?php echo htmlspecialchars(app_path('/admin/dashboard'), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-outline-info rounded-pill fw-bold px-4">Dashboard</a>
        <a href="<?php echo htmlspecialchars(app_path('/admin/win-points'), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-outline-info rounded-pill fw-bold px-4">Win Points</a>
        <a href="<?php echo htmlspecialchars(app_path('/admin/cupones'), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-outline-info rounded-pill fw-bold px-4">Cupones</a>
      </div>
    </div>
  </section>

  <!-- Users panel -->
  <section class="missions-admin-panel">

    <!-- Filters -->
    <form method="get" action="<?php echo htmlspecialchars($pageBase, ENT_QUOTES, 'UTF-8'); ?>" class="row g-3 mb-4 align-items-end">
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
        <a href="<?php echo htmlspecialchars($pageBase, ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-outline-info fw-bold rounded-pill px-4">Limpiar</a>
      </div>
    </form>

    <!-- Info row -->
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
      <p class="text-secondary small mb-0">
        Mostrando <strong class="text-info"><?php echo count($matchingUsers); ?></strong>
        de <strong class="text-info"><?php echo number_format($totalUsers); ?></strong> usuarios
        <?php if ($searchTerm !== ''): ?> — Filtro: <em><?php echo htmlspecialchars($searchTerm, ENT_QUOTES, 'UTF-8'); ?></em><?php endif; ?>
        <?php if ($roleFilter !== ''): ?> — Rol: <em><?php echo htmlspecialchars(ucfirst($roleFilter), ENT_QUOTES, 'UTF-8'); ?></em><?php endif; ?>
      </p>
      <div class="d-flex flex-wrap gap-2">
        <span class="missions-admin-pill">Sistema: <?php echo daily_missions_enabled() ? 'Activo' : 'Inactivo'; ?></span>
        <span class="missions-admin-pill">Tareas activas: <?php echo number_format($tasksActiveCount); ?></span>
        <span class="missions-admin-pill">Niveles: <?php echo number_format(count($prizeLevels)); ?></span>
      </div>
    </div>

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
                  <td>
                    <div class="fw-semibold text-light"><?php echo htmlspecialchars((string) ($uRow['nombre'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                  </td>
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
                  No se encontraron usuarios<?php echo $searchTerm !== '' ? ' con el filtro "' . htmlspecialchars($searchTerm, ENT_QUOTES, 'UTF-8') . '"' : ''; ?>.
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
        $pParams = [];
        if ($searchTerm !== '') $pParams['q']   = $searchTerm;
        if ($roleFilter  !== '') $pParams['rol'] = $roleFilter;
        $pageStart = max(1, $currentPage - 2);
        $pageEnd   = min($totalPages, $currentPage + 2);
      ?>
      <nav class="mt-4" aria-label="Paginación de usuarios">
        <ul class="pagination justify-content-center gap-1 flex-wrap mb-0">
          <!-- Prev -->
          <li class="page-item <?php echo $currentPage <= 1 ? 'disabled' : ''; ?>">
            <a class="page-link missions-page-link"
               href="<?php echo $currentPage > 1 ? htmlspecialchars(admin_missions_pagination_url($pageBase, $pParams, $currentPage - 1), ENT_QUOTES, 'UTF-8') : '#'; ?>">
              ← Anterior
            </a>
          </li>

          <?php if ($pageStart > 1): ?>
            <li class="page-item">
              <a class="page-link missions-page-link" href="<?php echo htmlspecialchars(admin_missions_pagination_url($pageBase, $pParams, 1), ENT_QUOTES, 'UTF-8'); ?>">1</a>
            </li>
            <?php if ($pageStart > 2): ?>
              <li class="page-item disabled"><span class="page-link missions-page-link">…</span></li>
            <?php endif; ?>
          <?php endif; ?>

          <?php for ($p = $pageStart; $p <= $pageEnd; $p++): ?>
            <li class="page-item <?php echo $p === $currentPage ? 'active' : ''; ?>">
              <a class="page-link missions-page-link" href="<?php echo htmlspecialchars(admin_missions_pagination_url($pageBase, $pParams, $p), ENT_QUOTES, 'UTF-8'); ?>"><?php echo $p; ?></a>
            </li>
          <?php endfor; ?>

          <?php if ($pageEnd < $totalPages): ?>
            <?php if ($pageEnd < $totalPages - 1): ?>
              <li class="page-item disabled"><span class="page-link missions-page-link">…</span></li>
            <?php endif; ?>
            <li class="page-item">
              <a class="page-link missions-page-link" href="<?php echo htmlspecialchars(admin_missions_pagination_url($pageBase, $pParams, $totalPages), ENT_QUOTES, 'UTF-8'); ?>"><?php echo $totalPages; ?></a>
            </li>
          <?php endif; ?>

          <!-- Next -->
          <li class="page-item <?php echo $currentPage >= $totalPages ? 'disabled' : ''; ?>">
            <a class="page-link missions-page-link"
               href="<?php echo $currentPage < $totalPages ? htmlspecialchars(admin_missions_pagination_url($pageBase, $pParams, $currentPage + 1), ENT_QUOTES, 'UTF-8') : '#'; ?>">
              Siguiente →
            </a>
          </li>
        </ul>
        <p class="text-center text-secondary small mt-2 mb-0">
          Página <?php echo $currentPage; ?> de <?php echo $totalPages; ?>
        </p>
      </nav>
    <?php endif; ?>

  </section>

  <!-- Configuración + Tareas -->
  <div class="row g-3">
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

  <!-- Premios por nivel -->
  <section class="missions-admin-panel">
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
                <div class="rounded-4 border border-info-subtle p-3" style="background:rgba(5,10,18,.72)">
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
  </section>

</div>

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
      + th('Fecha') + th('Hora') + th('Premio') + th('Motivo') + th('Estado') + th('Nivel')
      + '</tr></thead><tbody>';

    for (const e of data.history) {
      html += '<tr>'
        + td('<span class="text-secondary">' + esc(e.date) + '</span>')
        + td('<span class="text-secondary">' + esc(e.time) + '</span>')
        + td('<span class="fw-semibold text-light">' + esc(e.prize_label) + '</span><div class="small text-info">' + esc(e.prize_type_label) + '</div>')
        + td('<span class="text-light">' + esc(e.reason) + '</span>')
        + td('<span class="badge rounded-pill text-bg-dark border ' + esc(e.status_class) + '">' + esc(e.status_label) + '</span>')
        + td('<span class="text-info fw-semibold">' + esc(e.level_label) + '</span>')
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

  // Mover el modal a document.body para sacarlo del stacking context del store-shell/topbar
  const _modalEl = document.getElementById('modalHistorial');
  if (_modalEl && _modalEl.parentElement !== document.body) {
    document.body.appendChild(_modalEl);
  }

  document.addEventListener('click', function (e) {
    const btn = e.target.closest('.btn-ver-historial');
    if (!btn) return;

    const userId    = btn.dataset.userId;
    const userName  = btn.dataset.userName;
    const userRol   = btn.dataset.userRol;
    const userEmail = btn.dataset.userEmail;

    document.getElementById('modalHistorialLabel').textContent = userName;
    document.getElementById('modalHistorialMeta').innerHTML =
      '<span class="badge rounded-pill text-bg-dark border border-info-subtle text-info">' + esc(userRol) + '</span>'
      + '<span class="text-secondary small">' + esc(userEmail) + '</span>';
    document.getElementById('modalHistorialBody').innerHTML =
      '<div class="text-center py-5"><div class="spinner-border text-info" role="status"></div>'
      + '<p class="text-secondary mt-3 mb-0">Cargando historial…</p></div>';

    bootstrap.Modal.getOrCreateInstance(document.getElementById('modalHistorial')).show();

    fetch(ajaxBase + '?modal=1&user_id=' + encodeURIComponent(userId))
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data.ok) {
          document.getElementById('modalHistorialBody').innerHTML =
            '<div class="text-center text-danger py-5">Error al cargar el historial.</div>';
          return;
        }
        document.getElementById('modalHistorialBody').innerHTML = buildModalHtml(data);
      })
      .catch(function () {
        document.getElementById('modalHistorialBody').innerHTML =
          '<div class="text-center text-danger py-5">Error de conexión. Intenta de nuevo.</div>';
      });
  });
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
