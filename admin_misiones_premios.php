<?php
require_once __DIR__ . '/includes/tenant.php';
tenant_start_session();

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db_connect.php';
require_once __DIR__ . '/includes/daily_missions.php';
require_once __DIR__ . '/includes/win_points.php';
require_once __DIR__ . '/includes/roulette.php';

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
            $prizeId           = (int) ($_POST['prize_id'] ?? 0);
            $chancePercent     = max(0.0, min(100.0, (float) ($_POST['chance_percent'] ?? 0)));
            $pointsAmount      = max(0, (int) ($_POST['points_amount'] ?? 0));
            $couponDiscountPct = max(0, min(100, (int) ($_POST['coupon_discount_percent'] ?? 0)));
            $immunityDays      = max(0, (int) ($_POST['immunity_days'] ?? 0));

            if ($prizeId <= 0) { echo json_encode(['ok' => false, 'error' => 'ID de premio inválido.']); exit; }

            $stmt = $mysqli->prepare(
                'UPDATE win_points_daily_mission_prizes
                 SET chance_percent=?, points_amount=?, coupon_discount_percent=?, immunity_days=?
                 WHERE id=?'
            );
            if (!$stmt) { echo json_encode(['ok' => false, 'error' => $mysqli->error]); exit; }
            $stmt->bind_param('diiii', $chancePercent, $pointsAmount, $couponDiscountPct,
                $immunityDays, $prizeId);
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

        // ── Ruleta ─────────────────────────────────────────────────────
        case 'rlt_get_data': {
            roulette_ensure_schema();
            echo json_encode([
                'ok'       => true,
                'config'   => roulette_fetch_config($mysqli),
                'sections' => roulette_fetch_sections($mysqli),
                'history'  => roulette_fetch_all_history($mysqli, 50),
            ]);
            exit;
        }

        case 'rlt_save_config': {
            roulette_admin_save_config($mysqli, $_POST);
            echo json_encode(['ok' => true]);
            exit;
        }

        case 'rlt_save_section': {
            $sectionOrder = max(1, min(8, (int) ($_POST['section_order'] ?? 1)));
            roulette_admin_save_section($mysqli, $sectionOrder, $_POST);
            echo json_encode(['ok' => true]);
            exit;
        }

        case 'rlt_upload_center_image': {
            if (empty($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
                $errCode = (int) ($_FILES['image']['error'] ?? -1);
                $errMsg  = in_array($errCode, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                    ? 'La imagen es demasiado grande.'
                    : ($errCode === UPLOAD_ERR_NO_FILE ? 'No se seleccionó ningún archivo.' : 'Error al recibir el archivo (código ' . $errCode . ').');
                echo json_encode(['ok' => false, 'error' => $errMsg]);
                exit;
            }
            $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            $imgInfo = @getimagesize($_FILES['image']['tmp_name']);
            $mime    = $imgInfo ? ($imgInfo['mime'] ?? '') : '';
            if (!in_array($mime, $allowedMimes, true)) {
                echo json_encode(['ok' => false, 'error' => 'Formato no permitido. Usa JPG, PNG, GIF o WebP.']);
                exit;
            }
            if ($_FILES['image']['size'] > 2 * 1024 * 1024) {
                echo json_encode(['ok' => false, 'error' => 'La imagen supera el límite de 2 MB.']);
                exit;
            }
            $extMap    = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
            $ext       = $extMap[$mime] ?? 'jpg';
            $uploadDir = __DIR__ . '/uploads/roulette/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            foreach (glob($uploadDir . 'center_*') ?: [] as $old) {
                @unlink($old);
            }
            $filename = 'center_' . bin2hex(random_bytes(6)) . '.' . $ext;
            $dest     = $uploadDir . $filename;
            if (!move_uploaded_file($_FILES['image']['tmp_name'], $dest)) {
                echo json_encode(['ok' => false, 'error' => 'No se pudo guardar la imagen.']);
                exit;
            }
            echo json_encode(['ok' => true, 'url' => app_path('/uploads/roulette/' . $filename)]);
            exit;
        }

        case 'rlt_upload_btn_image': {
            if (empty($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
                $errCode = (int) ($_FILES['image']['error'] ?? -1);
                $errMsg  = in_array($errCode, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                    ? 'La imagen es demasiado grande.'
                    : ($errCode === UPLOAD_ERR_NO_FILE ? 'No se seleccionó ningún archivo.' : 'Error al recibir el archivo (código ' . $errCode . ').');
                echo json_encode(['ok' => false, 'error' => $errMsg]);
                exit;
            }
            $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            $imgInfo = @getimagesize($_FILES['image']['tmp_name']);
            $mime    = $imgInfo ? ($imgInfo['mime'] ?? '') : '';
            if (!in_array($mime, $allowedMimes, true)) {
                echo json_encode(['ok' => false, 'error' => 'Formato no permitido. Usa JPG, PNG, GIF o WebP.']);
                exit;
            }
            if ($_FILES['image']['size'] > 2 * 1024 * 1024) {
                echo json_encode(['ok' => false, 'error' => 'La imagen supera el límite de 2 MB.']);
                exit;
            }
            $extMap    = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
            $ext       = $extMap[$mime] ?? 'jpg';
            $uploadDir = __DIR__ . '/uploads/roulette/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            foreach (glob($uploadDir . 'btn_*') ?: [] as $old) {
                @unlink($old);
            }
            $filename = 'btn_' . bin2hex(random_bytes(6)) . '.' . $ext;
            $dest     = $uploadDir . $filename;
            if (!move_uploaded_file($_FILES['image']['tmp_name'], $dest)) {
                echo json_encode(['ok' => false, 'error' => 'No se pudo guardar la imagen.']);
                exit;
            }
            echo json_encode(['ok' => true, 'url' => app_path('/uploads/roulette/' . $filename)]);
            exit;
        }

        case 'rlt_upload_image': {
            $sectionOrder = max(1, min(8, (int) ($_POST['section_order'] ?? 1)));
            if (empty($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
                $errCode = (int) ($_FILES['image']['error'] ?? -1);
                $errMsg  = in_array($errCode, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                    ? 'La imagen es demasiado grande.'
                    : ($errCode === UPLOAD_ERR_NO_FILE ? 'No se seleccionó ningún archivo.' : 'Error al recibir el archivo.');
                echo json_encode(['ok' => false, 'error' => $errMsg]);
                exit;
            }
            $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            $imgInfo = @getimagesize($_FILES['image']['tmp_name']);
            $mime    = $imgInfo ? ($imgInfo['mime'] ?? '') : '';
            if (!in_array($mime, $allowedMimes, true)) {
                echo json_encode(['ok' => false, 'error' => 'Formato no permitido. Usa JPG, PNG, GIF o WebP.']);
                exit;
            }
            if ($_FILES['image']['size'] > 2 * 1024 * 1024) {
                echo json_encode(['ok' => false, 'error' => 'La imagen supera el límite de 2 MB.']);
                exit;
            }
            $extMap    = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
            $ext       = $extMap[$mime] ?? 'jpg';
            $uploadDir = __DIR__ . '/uploads/roulette/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            foreach (glob($uploadDir . 'slot_' . $sectionOrder . '_*') ?: [] as $old) {
                @unlink($old);
            }
            $filename = 'slot_' . $sectionOrder . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
            $dest     = $uploadDir . $filename;
            if (!move_uploaded_file($_FILES['image']['tmp_name'], $dest)) {
                echo json_encode(['ok' => false, 'error' => 'No se pudo guardar la imagen en el servidor.']);
                exit;
            }
            echo json_encode(['ok' => true, 'url' => app_path('/uploads/roulette/' . $filename)]);
            exit;
        }

        case 'rlt_get_history': {
            roulette_ensure_schema();
            echo json_encode(['ok' => true, 'history' => roulette_fetch_all_history($mysqli, 100)]);
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
        'winpoints'        => win_points_program_name(),
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
    // Nunca mostrar usuarios root en el historial
    $conditions[] = "rol != 'root'";

    if ($roleFilter !== '' && $roleFilter !== 'root') {
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

function admin_missions_fetch_pending_streaming_user_ids(mysqli $mysqli): array {
    $result = $mysqli->query(
        "SELECT DISTINCT user_id FROM win_points_daily_mission_rewards
         WHERE prize_type='streaming_ticket' AND reward_status='pending'"
    );
    $ids = [];
    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_row()) { $ids[(int) $row[0]] = true; }
    }
    return $ids;
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
        $dp         = admin_missions_date_parts((string) ($entry['created_at'] ?? $entry['claimed_at'] ?? $entry['resolved_at'] ?? ''));
        $sb         = admin_missions_status_badge((string) ($entry['reward_status'] ?? 'pending'));
        $ePrizeType = (string) ($entry['prize_type'] ?? '');

        // Usar mission_date como fecha de display (refleja el día simulado o real)
        $missionDate = trim((string) ($entry['mission_date'] ?? ''));
        if ($missionDate !== '') {
            $bits        = explode('-', $missionDate);
            $displayDate = count($bits) === 3 ? $bits[2] . '/' . $bits[1] . '/' . $bits[0] : $missionDate;
        } else {
            $displayDate = $dp['date'];
        }

        // Detectar si es un registro simulado
        $metaRaw  = (string) ($entry['metadata_json'] ?? '');
        $isSim    = false;
        if ($metaRaw !== '') {
            $decoded = json_decode($metaRaw, true);
            $isSim   = is_array($decoded) && !empty($decoded['simulated']);
        }

        $historyOut[] = [
            'id'               => (int) ($entry['id'] ?? 0),
            'date'             => $displayDate,
            'time'             => $dp['time'],
            'is_simulated'     => $isSim,
            'prize_type'       => $ePrizeType,
            'prize_type_label' => admin_missions_prize_label($ePrizeType),
            'points_amount'    => (int) ($entry['points_amount'] ?? 0),
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
$allUsersList            = admin_missions_fetch_all_users_list($mysqli);
$pendingStreamingUserIds = admin_missions_fetch_pending_streaming_user_ids($mysqli);

$ajaxUrl  = app_path('/admin/misiones-premios');
$allRoles = ['admin', 'empleado', 'influencer', 'usuario'];
$pageBase = '/admin/misiones-premios';
$activeTab = trim((string) ($_GET['tab'] ?? 'historial'));
if (!in_array($activeTab, ['historial', 'config', 'tareas', 'cofre', 'ruleta'], true)) {
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

  /* ── Historial: tarjeta por usuario en móvil ── */
  @media (max-width:575.98px) {
    /* Filtros: stack completo */
    #tab-historial form .col-auto { width: 100%; }
    #tab-historial form .col-auto .btn { flex: 1; }

    /* Tabla de usuarios: layout de tarjeta */
    #tab-historial .missions-admin-table-wrap thead { display: none; }

    #tab-historial .missions-admin-table-wrap .table tbody tr {
      display: block;
      padding: .9rem;
      border-bottom: 1px solid rgba(255,255,255,.07);
    }
    #tab-historial .missions-admin-table-wrap .table tbody tr:last-child { border-bottom: none; }

    #tab-historial .missions-admin-table-wrap .table tbody td {
      display: block;
      border: none;
      padding: .15rem 0;
    }
    /* Ocultar #  y Registrado */
    #tab-historial .missions-admin-table-wrap .table tbody td:nth-child(1),
    #tab-historial .missions-admin-table-wrap .table tbody td:nth-child(5) { display: none; }

    /* Email: gris y pequeño */
    #tab-historial .missions-admin-table-wrap .table tbody td:nth-child(3) { font-size: .78rem; }

    /* Acciones: padding superior y botón ancho completo */
    #tab-historial .missions-admin-table-wrap .table tbody td:nth-child(6) { padding-top: .65rem; }
    #tab-historial .missions-admin-table-wrap .btn-ver-historial { width: 100%; justify-content: center; }
  }

  /* ── Ruleta: grid de 8 tarjetas de sección ── */
  .rlt2-sect-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: .85rem;
    margin-top: .75rem;
  }
  @media (max-width:991.98px) { .rlt2-sect-grid { grid-template-columns: repeat(2, 1fr); } }
  @media (max-width:575.98px) { .rlt2-sect-grid { grid-template-columns: 1fr; } }

  .rlt2-sect-card {
    background: rgba(8,15,28,.9);
    border: 1px solid rgba(99,102,241,.25);
    border-radius: 1rem;
    padding: .85rem;
    display: flex;
    flex-direction: column;
    gap: .5rem;
  }
  .rlt2-sect-card-head {
    display: flex; align-items: center; justify-content: space-between;
    padding-bottom: .5rem;
    border-bottom: 1px solid rgba(99,102,241,.15);
  }
  .rlt2-sect-card-num {
    font-size: .92rem; font-weight: 700; color: #818cf8;
    display: flex; align-items: center; gap: .38rem;
  }
  .rlt2-sect-card-swatch {
    width: 14px; height: 14px; border-radius: 3px;
    border: 1px solid rgba(255,255,255,.2); flex-shrink: 0;
  }
  .rlt2-sect-card-field { display: flex; flex-direction: column; gap: .16rem; }
  .rlt2-sect-card-lbl {
    font-size: .58rem; font-weight: 700;
    text-transform: uppercase; letter-spacing: .1em; color: #6366f1;
  }
  .rlt2-sect-card-row { display: grid; grid-template-columns: 1fr 1fr; gap: .42rem; }
  .rlt2-sect-card-foot {
    display: flex; align-items: center; gap: .5rem;
    padding-top: .42rem; border-top: 1px solid rgba(99,102,241,.12);
    margin-top: auto;
  }
  .rlt2-img-area {
    background: rgba(99,102,241,.06);
    border: 1px dashed rgba(99,102,241,.3);
    border-radius: .55rem; padding: .42rem;
    display: flex; align-items: center; gap: .4rem; flex-wrap: wrap;
    min-height: 50px;
  }
  .rlt2-img-preview {
    width: 38px; height: 38px; object-fit: cover;
    border-radius: .35rem; border: 1px solid rgba(255,255,255,.15);
    display: none;
  }
  .rlt2-img-preview.visible { display: block; }
  .rlt2-img-upload-btn {
    background: rgba(99,102,241,.15); border: 1px solid rgba(99,102,241,.4);
    color: #a5b4fc; border-radius: .45rem; padding: .26rem .58rem;
    font-size: .68rem; font-weight: 700; cursor: pointer; transition: background .16s;
  }
  .rlt2-img-upload-btn:hover { background: rgba(99,102,241,.28); }
  .rlt2-img-remove-btn {
    background: rgba(248,113,113,.1); border: 1px solid rgba(248,113,113,.35);
    color: #f87171; border-radius: .45rem; padding: .26rem .5rem;
    font-size: .68rem; font-weight: 700; cursor: pointer; display: none; transition: background .16s;
  }
  .rlt2-img-remove-btn.visible { display: inline-block; }
  .rlt2-img-remove-btn:hover { background: rgba(248,113,113,.2); }

  /* ── Tareas y multiplicadores: tarjeta por tarea en móvil ── */
  @media (max-width:575.98px) {
    #tab-tareas .missions-admin-table-wrap thead { display: none; }

    #tab-tareas .missions-admin-table-wrap .table tbody tr {
      display: block;
      padding: .85rem;
      border-bottom: 1px solid rgba(255,255,255,.07);
    }
    #tab-tareas .missions-admin-table-wrap .table tbody tr:last-child { border-bottom: none; }

    #tab-tareas .missions-admin-table-wrap .table tbody td {
      display: flex;
      align-items: center;
      justify-content: space-between;
      border: none;
      padding: .32rem 0;
    }
    /* Nombre de la tarea: ocupa toda la fila */
    #tab-tareas .missions-admin-table-wrap .table tbody td:first-child {
      display: block;
      padding-bottom: .65rem;
      margin-bottom: .4rem;
      border-bottom: 1px solid rgba(255,255,255,.06);
    }
    /* Etiqueta generada por data-label */
    #tab-tareas .missions-admin-table-wrap .table tbody td[data-label]::before {
      content: attr(data-label);
      color: #22d3ee;
      font-size: .69rem;
      font-weight: 700;
      letter-spacing: .1em;
      text-transform: uppercase;
      flex-shrink: 0;
      margin-right: .75rem;
    }
    #tab-tareas .missions-admin-table-wrap .table tbody td[data-label=""]::before { display: none; }
    /* Fila del botón: alineada a la derecha */
    #tab-tareas .missions-admin-table-wrap .table tbody td[data-label=""] {
      justify-content: flex-end;
      padding-top: .55rem;
    }
    #tab-tareas .missions-admin-table-wrap .missions-form-input { width: 90px; }
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
        <a href="<?php echo htmlspecialchars(app_path('/admin/win-points'), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-outline-info rounded-pill fw-bold px-4"><?php echo htmlspecialchars(win_points_program_name(), ENT_QUOTES, 'UTF-8'); ?></a>
        <a href="<?php echo htmlspecialchars(app_path('/admin/cupones'), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-outline-info rounded-pill fw-bold px-4">Cupones</a>
        <a href="<?php echo htmlspecialchars(app_path('/admin/sim-misiones'), ENT_QUOTES, 'UTF-8'); ?>" class="btn rounded-pill fw-bold px-4" style="background:rgba(245,158,11,.12);border:1px solid rgba(245,158,11,.35);color:#fbbf24;" title="Simulador de misiones para testing"><i class="fa-solid fa-flask-vial me-1"></i>Simulador</a>
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
        <li role="presentation">
          <button class="nav-link <?php echo $activeTab === 'ruleta' ? 'active' : ''; ?>"
                  data-bs-toggle="tab" data-bs-target="#tab-ruleta" type="button">
            🎰 Ruleta
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
                        <?php $hasPending = isset($pendingStreamingUserIds[(int) ($uRow['id'] ?? 0)]); ?>
                        <button type="button"
                                class="btn btn-outline-info btn-sm rounded-pill fw-bold btn-ver-historial"
                                data-user-id="<?php echo (int) ($uRow['id'] ?? 0); ?>"
                                data-user-name="<?php echo htmlspecialchars((string) ($uRow['nombre'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                data-user-rol="<?php echo htmlspecialchars(strtoupper((string) ($uRow['rol'] ?? '')), ENT_QUOTES, 'UTF-8'); ?>"
                                data-user-email="<?php echo htmlspecialchars((string) ($uRow['email'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                          Ver historial<?php if ($hasPending): ?> <span class="badge bg-warning text-dark ms-1" title="Tiene un ticket streaming pendiente">▶ Pendiente</span><?php endif; ?>
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
                    <td data-label="WP diario">
                      <input type="number" class="missions-form-input task-base-points" min="0" max="9999"
                             value="<?php echo (int) ($task['base_points'] ?? 0); ?>"
                             placeholder="WP">
                    </td>
                    <td data-label="x Día 20">
                      <input type="number" class="missions-form-input task-day20-mult" min="0" step="0.1"
                             value="<?php echo isset($task['day20_multiplier']) && $task['day20_multiplier'] !== null ? number_format((float) $task['day20_multiplier'], 2, '.', '') : ''; ?>"
                             placeholder="global">
                    </td>
                    <td data-label="x Final de mes">
                      <input type="number" class="missions-form-input task-monthend-mult" min="0" step="0.1"
                             value="<?php echo isset($task['month_end_multiplier']) && $task['month_end_multiplier'] !== null ? number_format((float) $task['month_end_multiplier'], 2, '.', '') : ''; ?>"
                             placeholder="global">
                    </td>
                    <td data-label="">
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
                          <label class="missions-admin-label mb-1">Cantidad <?php echo htmlspecialchars(win_points_program_name(), ENT_QUOTES, 'UTF-8'); ?></label>
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
                          <p class="text-secondary small mb-0">Al salir este premio, se registra como <strong class="text-warning">Pendiente</strong> en el historial del usuario. El usuario podrá reclamar vía WhatsApp y el administrador lo marcará como entregado desde el historial.</p>
                        </div>
                      <?php endif; ?>

                      <div class="col-12 d-flex align-items-center justify-content-end gap-2 mt-2">
                        <button class="save-btn btn-save-prize" type="button">Guardar</button>
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

    <!-- ══════════════════ TAB: RULETA ══════════════════ -->
    <div class="tab-pane fade <?php echo $activeTab === 'ruleta' ? 'show active' : ''; ?>" id="tab-ruleta">
      <section class="missions-admin-panel">

<style>
  .rlt-admin-card2 {
    border-radius: 1.2rem; border: 1px solid rgba(99,102,241,.22);
    background: radial-gradient(circle at top,rgba(99,102,241,.08),transparent 30%),
      linear-gradient(180deg,rgba(8,15,28,.96),rgba(13,22,39,.94));
    box-shadow: 0 12px 32px rgba(0,0,0,.2); padding: 1.2rem 1.4rem; margin-bottom: 1.2rem;
  }
  .rlt2-label {
    font-size:.72rem; font-weight:700; letter-spacing:.12em; text-transform:uppercase;
    color:#818cf8; margin-bottom:.8rem; display:flex; align-items:center; gap:.5rem;
  }
  .rlt2-label::after { content:''; flex:1; height:1px; background:rgba(99,102,241,.18); }
  .rlt2-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(190px,1fr)); gap:.75rem; margin-bottom:.75rem; }
  .rlt2-field label { display:block; font-size:.72rem; color:#818cf8; font-weight:700; text-transform:uppercase; letter-spacing:.1em; margin-bottom:.25rem; }
  .rlt2-field input, .rlt2-field select {
    width:100%; padding:.42rem .75rem; border-radius:.55rem;
    background:rgba(255,255,255,.05); border:1px solid rgba(99,102,241,.25);
    color:#e2e8f0; font-size:.87rem; outline:none; transition:border-color .2s;
  }
  .rlt2-field input:focus,.rlt2-field select:focus { border-color:rgba(99,102,241,.55); }
  .rlt2-field input[type="color"] { padding:.2rem .35rem; height:36px; cursor:pointer; }
  .rlt2-field input[type="number"] { width:80px; }
  .rlt2-toggle { display:flex; align-items:center; gap:.5rem; }
  .rlt2-toggle input[type="checkbox"] { width:16px; height:16px; accent-color:#6366f1; cursor:pointer; }
  .rlt2-save-btn {
    padding:.45rem 1.3rem; border-radius:.55rem;
    background:rgba(99,102,241,.2); border:1px solid rgba(99,102,241,.4);
    color:#c7d2fe; font-size:.84rem; font-weight:700; cursor:pointer; transition:background .15s;
  }
  .rlt2-save-btn:hover { background:rgba(99,102,241,.35); color:#fff; }
  .rlt2-size-btn {
    padding:.28rem .62rem; border-radius:.45rem; font-size:.72rem; font-weight:700; cursor:pointer;
    background:rgba(99,102,241,.1); border:1px solid rgba(99,102,241,.3); color:#94a3b8; transition:all .15s;
  }
  .rlt2-size-btn.active {
    background:rgba(99,102,241,.35); border-color:rgba(99,102,241,.7); color:#c7d2fe;
  }
  .rlt2-size-btn:hover { background:rgba(99,102,241,.22); color:#c7d2fe; }
  .rlt2-msg { font-size:.78rem; margin-left:.75rem; min-height:1.1em; }
  .rlt2-msg.ok  { color:#34d399; }
  .rlt2-msg.err { color:#f87171; }
  .rlt2-tbl { width:100%; border-collapse:collapse; font-size:.8rem; }
  .rlt2-tbl th { color:#818cf8; font-size:.67rem; letter-spacing:.1em; text-transform:uppercase; padding:.4rem .5rem; border-bottom:1px solid rgba(99,102,241,.18); text-align:left; }
  .rlt2-tbl td { padding:.38rem .45rem; border-bottom:1px solid rgba(255,255,255,.04); vertical-align:middle; }
  .rlt2-tbl tr:hover td { background:rgba(99,102,241,.04); }
  .rlt2-inp {
    background:rgba(255,255,255,.04); border:1px solid rgba(99,102,241,.2);
    border-radius:.4rem; padding:.22rem .5rem; color:#e2e8f0; font-size:.8rem; outline:none;
  }
  select.rlt2-inp { background:#0b1422; }
  .rlt2-inp option { background:#0b1422; color:#e2e8f0; }
  .rlt2-inp:focus { border-color:rgba(99,102,241,.5); }
  .rlt2-inp[type="number"] { width:70px; }
  .rlt2-inp[type="text"]   { width:110px; }
  .rlt2-inp[type="color"]  { width:42px; height:28px; padding:.1rem .25rem; cursor:pointer; }
  .rlt2-sect-btn {
    padding:.2rem .6rem; border-radius:.4rem; white-space:nowrap;
    background:rgba(99,102,241,.15); border:1px solid rgba(99,102,241,.3);
    color:#a5b4fc; font-size:.72rem; font-weight:700; cursor:pointer;
  }
  .rlt2-sect-btn:hover { background:rgba(99,102,241,.3); }
  .rlt2-sect-msg { font-size:.67rem; color:#34d399; min-width:50px; }
  .rlt2-hist-tbl { width:100%; border-collapse:collapse; font-size:.8rem; }
  .rlt2-hist-tbl th { color:#818cf8; font-size:.67rem; letter-spacing:.1em; text-transform:uppercase; padding:.4rem .6rem; border-bottom:1px solid rgba(99,102,241,.18); text-align:left; }
  .rlt2-hist-tbl td { padding:.42rem .6rem; border-bottom:1px solid rgba(255,255,255,.04); color:#cbd5e1; vertical-align:middle; }
  .rlt2-hist-tbl tr:hover td { background:rgba(99,102,241,.04); }
  .rlt2-tag { display:inline-block; padding:.08rem .4rem; border-radius:.3rem; font-size:.67rem; font-weight:700; text-transform:uppercase; letter-spacing:.06em; }
  .rlt2-tag-wp   { background:rgba(34,211,238,.12); color:#22d3ee; }
  .rlt2-tag-cup  { background:rgba(251,146,60,.12);  color:#fb923c; }
  .rlt2-tag-imm  { background:rgba(74,222,128,.12);  color:#4ade80; }
  .rlt2-tag-str  { background:rgba(244,114,182,.12); color:#f472b6; }
</style>

        <!-- Config Card -->
        <div class="rlt-admin-card2">
          <div class="rlt2-label"><i class="fa-solid fa-dice"></i> Ruleta — Configuración general</div>
          <div class="rlt2-grid" id="rlt2ConfigGrid">
            <div class="rlt2-field">
              <label>Estado</label>
              <div class="rlt2-toggle">
                <input type="checkbox" id="rlt2CfgEnabled"> <span style="font-size:.85rem;color:#e2e8f0;">Ruleta activa</span>
              </div>
            </div>
            <div class="rlt2-field">
              <label>Costo por giro</label>
              <input type="number" id="rlt2CfgCost" value="10" min="0" max="9999">
            </div>
            <div class="rlt2-field">
              <label>Título</label>
              <input type="text" id="rlt2CfgTitle" value="Ruleta de Premios" maxlength="120" style="width:100%">
            </div>
            <div class="rlt2-field">
              <label>Subtítulo</label>
              <input type="text" id="rlt2CfgSubtitle" value="Gira y gana premios increíbles." maxlength="255" style="width:100%">
            </div>
            <div class="rlt2-field">
              <label>Exp. cupones (días)</label>
              <input type="number" id="rlt2CfgExpDays" value="30" min="1" max="365">
            </div>
            <div class="rlt2-field">
              <label>Color borde / divisores</label>
              <div style="display:flex;align-items:center;gap:.5rem;">
                <input type="color" id="rlt2CfgRingColor" value="#6366f1">
                <input type="text" id="rlt2CfgRingColorHex" value="#6366f1" maxlength="7" style="width:80px">
              </div>
            </div>
            <div class="rlt2-field">
              <label>Grosor borde (px)</label>
              <input type="number" id="rlt2CfgRingWidth" value="2" min="1" max="10">
            </div>
            <div class="rlt2-field">
              <label>Efecto neon gaming</label>
              <div class="rlt2-toggle">
                <input type="checkbox" id="rlt2CfgNeon"> <span style="font-size:.85rem;color:#e2e8f0;">Activar neon en bordes</span>
              </div>
            </div>
            <div class="rlt2-field" style="grid-column:1/-1">
              <label>Centro de la ruleta</label>
              <div style="display:flex;align-items:flex-start;gap:.85rem;flex-wrap:wrap;">
                <div>
                  <div style="font-size:.64rem;color:#94a3b8;margin-bottom:.22rem;text-transform:uppercase;letter-spacing:.08em;">Emoji</div>
                  <input type="text" id="rlt2CfgCenterEmoji" value="⭐" maxlength="4" style="width:58px;text-align:center;">
                </div>
                <div style="flex:1;min-width:200px;">
                  <div style="font-size:.64rem;color:#94a3b8;margin-bottom:.22rem;text-transform:uppercase;letter-spacing:.08em;">Imagen personalizada (reemplaza al emoji)</div>
                  <div class="rlt2-img-area">
                    <img class="rlt2-img-preview" id="rlt2CenterImgPreview" src="" alt="">
                    <input type="file" id="rlt2CenterImgFileInput" accept="image/jpeg,image/png,image/gif,image/webp" style="display:none">
                    <button type="button" class="rlt2-img-upload-btn" id="rlt2CenterUploadBtn">Subir imagen</button>
                    <button type="button" class="rlt2-img-remove-btn" id="rlt2CenterRemoveBtn">Quitar</button>
                    <span id="rlt2CenterImgStatus" style="font-size:.66rem;color:#94a3b8;"></span>
                  </div>
                </div>
                <div>
                  <div style="font-size:.64rem;color:#94a3b8;margin-bottom:.42rem;text-transform:uppercase;letter-spacing:.08em;">Tamaño</div>
                  <div style="display:flex;gap:.3rem;">
                    <button type="button" class="rlt2-size-btn" data-size="small" id="rlt2SizeSmall">Pequeño</button>
                    <button type="button" class="rlt2-size-btn" data-size="medium" id="rlt2SizeMedium">Mediano</button>
                    <button type="button" class="rlt2-size-btn" data-size="large" id="rlt2SizeLarge">Grande</button>
                  </div>
                  <input type="hidden" id="rlt2CfgCenterSize" value="medium">
                </div>
              </div>
            </div>
            <div class="rlt2-field" style="grid-column:1/-1">
              <label>Icono del botón «Girar Ahora»</label>
              <div style="display:flex;align-items:flex-start;gap:.85rem;flex-wrap:wrap;">
                <div>
                  <div style="font-size:.64rem;color:#94a3b8;margin-bottom:.22rem;text-transform:uppercase;letter-spacing:.08em;">Emoji</div>
                  <input type="text" id="rlt2CfgBtnEmoji" value="🎰" maxlength="4" style="width:58px;text-align:center;">
                </div>
                <div style="flex:1;min-width:200px;">
                  <div style="font-size:.64rem;color:#94a3b8;margin-bottom:.22rem;text-transform:uppercase;letter-spacing:.08em;">Imagen personalizada (reemplaza al emoji, gira igual)</div>
                  <div class="rlt2-img-area">
                    <img class="rlt2-img-preview" id="rlt2BtnImgPreview" src="" alt="">
                    <input type="file" id="rlt2BtnImgFileInput" accept="image/jpeg,image/png,image/gif,image/webp" style="display:none">
                    <button type="button" class="rlt2-img-upload-btn" id="rlt2BtnUploadBtn">Subir imagen</button>
                    <button type="button" class="rlt2-img-remove-btn" id="rlt2BtnRemoveBtn">Quitar</button>
                    <span id="rlt2BtnImgStatus" style="font-size:.66rem;color:#94a3b8;"></span>
                  </div>
                </div>
                <div>
                  <div style="font-size:.64rem;color:#94a3b8;margin-bottom:.42rem;text-transform:uppercase;letter-spacing:.08em;">Tamaño</div>
                  <div style="display:flex;gap:.3rem;">
                    <button type="button" class="rlt2-size-btn" data-size="small" id="rlt2BtnSizeSmall">Pequeño</button>
                    <button type="button" class="rlt2-size-btn active" data-size="medium" id="rlt2BtnSizeMedium">Mediano</button>
                    <button type="button" class="rlt2-size-btn" data-size="large" id="rlt2BtnSizeLarge">Grande</button>
                  </div>
                  <input type="hidden" id="rlt2CfgBtnImgSize" value="medium">
                </div>
              </div>
            </div>
          </div>
          <div style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;">
            <button type="button" class="rlt2-save-btn" id="rlt2SaveConfigBtn">Guardar configuración</button>
            <span class="rlt2-msg" id="rlt2CfgMsg"></span>
          </div>
        </div>

        <!-- Sections Grid -->
        <div class="rlt-admin-card2">
          <div class="rlt2-label"><i class="fa-solid fa-table-cells"></i> Ruleta — 8 Secciones</div>
          <div class="rlt2-sect-grid" id="rlt2SectionsGrid">
            <div style="grid-column:1/-1;text-align:center;color:#64748b;padding:1.2rem;">Cargando…</div>
          </div>
          <p style="margin-top:.6rem;font-size:.72rem;color:#475569;">
            Las probabilidades no necesitan sumar exactamente 100% — el sistema normaliza internamente.
          </p>
        </div>

        <!-- History Card -->
        <div class="rlt-admin-card2">
          <div class="rlt2-label">
            <i class="fa-solid fa-history"></i> Historial de giros
            <button type="button" class="rlt2-sect-btn" id="rlt2HistLoadBtn" style="margin-left:auto;">Cargar historial</button>
          </div>
          <div id="rlt2HistContainer" style="font-size:.82rem;color:#64748b;text-align:center;padding:1rem;">
            Haz clic en «Cargar historial» para ver los giros recientes.
          </div>
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
    const pendingTickets = (data.history || []).filter(e => e.prize_type === 'streaming_ticket' && e.reward_status === 'pending');
    let html = '';
    if (pendingTickets.length > 0) {
      html += '<div class="alert d-flex align-items-center gap-2 mb-3" style="background:rgba(251,191,36,.12);border:1px solid rgba(251,191,36,.4);color:#fbbf24;border-radius:.75rem;">'
        + '<span style="font-size:1.2rem">▶</span>'
        + '<span><strong>Ticket streaming pendiente:</strong> Este usuario tiene ' + pendingTickets.length + ' ticket(s) de streaming sin entregar. Revisa el historial y marca como entregado.</span>'
        + '</div>';
    }
    html += '<div class="row g-3 mb-4">'
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
        ? '<button class="save-btn btn-resolve-ticket" data-reward-id="' + esc(e.id) + '" type="button" style="background:rgba(74,222,128,.12);border-color:rgba(74,222,128,.4);color:#4ade80">✓ Marcar entregado</button>'
        : '';
      const dateCellHtml = e.is_simulated
        ? '<span style="color:#f59e0b;font-weight:600">' + esc(e.date) + '</span>'
          + '<div style="margin-top:.2rem"><span style="font-size:.63rem;background:rgba(245,158,11,.12);border:1px solid rgba(245,158,11,.28);color:#fbbf24;padding:.08rem .38rem;border-radius:.3rem;letter-spacing:.06em;font-weight:700">SIMULACIÓN</span></div>'
        : '<span class="text-secondary">' + esc(e.date) + '</span>';
      html += '<tr>'
        + td(dateCellHtml)
        + td('<span class="text-secondary">' + esc(e.time) + '</span>')
        + td((function(e) {
            const name = esc(e.prize_type_label);
            const sub  = (e.prize_type === 'winpoints' && e.points_amount > 0)
              ? '<div class="small" style="color:#22d3ee">+' + e.points_amount + ' ' + name + '</div>'
              : '';
            return '<span class="fw-semibold text-light">' + name + '</span>' + sub;
          })(e))
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

<script>
// ── Ruleta Admin ─────────────────────────────────────────────────────────────
(function () {
  const base = <?php echo json_encode($ajaxUrl); ?>;
  const PRIZE_TYPES = ['winpoints','coupon','immunity','streaming_ticket'];
  const PRIZE_LABELS = {winpoints:'<?= htmlspecialchars(win_points_program_name(), ENT_QUOTES, 'UTF-8') ?>',coupon:'Cupón',immunity:'Inmunidad',streaming_ticket:'Ticket Streaming'};

  function rltPost(action, extraData, cb) {
    const fd = new FormData();
    fd.append('action', action);
    if (extraData) Object.entries(extraData).forEach(([k,v]) => fd.append(k, v));
    fetch(base, {method:'POST', body:fd})
      .then(r => r.json())
      .then(cb)
      .catch(() => cb({ok:false,error:'Error de conexión'}));
  }

  function esc(s) {
    return String(s??'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

  function showMsg(el, text, isOk) {
    if (!el) return;
    el.textContent = text;
    el.className = 'rlt2-msg' + (isOk ? ' ok' : ' err');
    if (isOk) setTimeout(() => { el.textContent = ''; el.className = 'rlt2-msg'; }, 3000);
  }

  // ── Load data on tab activation ──
  let rltLoaded = false;
  const rltTab = document.querySelector('[data-bs-target="#tab-ruleta"]');
  if (rltTab) {
    rltTab.addEventListener('shown.bs.tab', function () {
      if (!rltLoaded) { loadRltData(); rltLoaded = true; }
    });
    // If already active on load
    if (rltTab.classList.contains('active')) { loadRltData(); rltLoaded = true; }
  }

  function loadRltData() {
    rltPost('rlt_get_data', null, function (data) {
      if (!data.ok) return;
      applyConfig(data.config || {});
      renderSections(data.sections || []);
    });
  }

  // Mutable image URLs shared across applyConfig and save
  let centerImgUrl = '';
  let btnImgUrl    = '';

  function applyConfig(cfg) {
    const set = (id, v) => { const el = document.getElementById(id); if (el) el.value = v; };
    const setChk = (id, v) => { const el = document.getElementById(id); if (el) el.checked = !!v; };
    setChk('rlt2CfgEnabled', cfg.enabled);
    set('rlt2CfgCost', cfg.spin_cost ?? 10);
    set('rlt2CfgTitle', cfg.title ?? 'Ruleta de Premios');
    set('rlt2CfgSubtitle', cfg.subtitle ?? '');
    set('rlt2CfgExpDays', cfg.coupon_expiration_days ?? 30);
    const rc = cfg.ring_color ?? '#6366f1';
    set('rlt2CfgRingColor', rc);
    set('rlt2CfgRingColorHex', rc);
    set('rlt2CfgRingWidth', cfg.ring_width ?? 2);
    setChk('rlt2CfgNeon', cfg.ring_neon ?? false);
    // Center
    set('rlt2CfgCenterEmoji', cfg.center_emoji ?? '⭐');
    centerImgUrl = cfg.center_image_url ?? '';
    const prev = document.getElementById('rlt2CenterImgPreview');
    const rem  = document.getElementById('rlt2CenterRemoveBtn');
    if (prev) { prev.src = centerImgUrl; prev.classList.toggle('visible', !!centerImgUrl); }
    if (rem)  { rem.classList.toggle('visible', !!centerImgUrl); }
    // Center size
    const size = cfg.center_size ?? 'medium';
    set('rlt2CfgCenterSize', size);
    document.querySelectorAll('.rlt2-size-btn').forEach(b => b.classList.toggle('active', b.dataset.size === size));
    // Button icon
    set('rlt2CfgBtnEmoji', cfg.btn_emoji ?? '🎰');
    btnImgUrl = cfg.btn_image_url ?? '';
    const btnPrev = document.getElementById('rlt2BtnImgPreview');
    const btnRem  = document.getElementById('rlt2BtnRemoveBtn');
    if (btnPrev) { btnPrev.src = btnImgUrl; btnPrev.classList.toggle('visible', !!btnImgUrl); }
    if (btnRem)  { btnRem.classList.toggle('visible', !!btnImgUrl); }
    // Button image size
    const btnSize = cfg.btn_image_size ?? 'medium';
    set('rlt2CfgBtnImgSize', btnSize);
    ['rlt2BtnSizeSmall','rlt2BtnSizeMedium','rlt2BtnSizeLarge'].forEach(id => {
      const el = document.getElementById(id);
      if (el) el.classList.toggle('active', el.dataset.size === btnSize);
    });
  }

  // Sync color input ↔ hex text
  const ringColorEl = document.getElementById('rlt2CfgRingColor');
  const ringHexEl   = document.getElementById('rlt2CfgRingColorHex');
  if (ringColorEl) ringColorEl.addEventListener('input', () => { if (ringHexEl) ringHexEl.value = ringColorEl.value; });
  if (ringHexEl)   ringHexEl.addEventListener('input',  () => { if (/^#[0-9a-fA-F]{6}$/.test(ringHexEl.value)) { if (ringColorEl) ringColorEl.value = ringHexEl.value; } });

  // Center image upload wiring
  const centerFileInput  = document.getElementById('rlt2CenterImgFileInput');
  const centerUploadBtn  = document.getElementById('rlt2CenterUploadBtn');
  const centerRemoveBtn  = document.getElementById('rlt2CenterRemoveBtn');
  const centerImgPreview = document.getElementById('rlt2CenterImgPreview');
  const centerImgStatus  = document.getElementById('rlt2CenterImgStatus');

  if (centerUploadBtn) centerUploadBtn.addEventListener('click', () => centerFileInput?.click());

  if (centerFileInput) {
    centerFileInput.addEventListener('change', async () => {
      if (!centerFileInput.files || !centerFileInput.files[0]) return;
      if (centerImgStatus) centerImgStatus.textContent = 'Subiendo…';
      if (centerUploadBtn) centerUploadBtn.disabled = true;
      const fd = new FormData();
      fd.append('action', 'rlt_upload_center_image');
      fd.append('image', centerFileInput.files[0]);
      try {
        const resp = await fetch(base, { method: 'POST', body: fd });
        const txt  = await resp.text();
        let data;
        try { data = JSON.parse(txt); } catch (e) {
          if (centerImgStatus) centerImgStatus.textContent = 'Error servidor: ' + txt.replace(/<[^>]*>/g,'').trim().slice(0, 120);
          if (centerUploadBtn) centerUploadBtn.disabled = false;
          centerFileInput.value = '';
          return;
        }
        if (data.ok) {
          centerImgUrl = data.url;
          if (centerImgPreview) { centerImgPreview.src = data.url; centerImgPreview.classList.add('visible'); }
          if (centerRemoveBtn) centerRemoveBtn.classList.add('visible');
          if (centerImgStatus) centerImgStatus.textContent = '';
        } else {
          if (centerImgStatus) centerImgStatus.textContent = data.error || 'Error al subir';
        }
      } catch (e) {
        if (centerImgStatus) centerImgStatus.textContent = 'Error de red: ' + e.message;
      }
      if (centerUploadBtn) centerUploadBtn.disabled = false;
      centerFileInput.value = '';
    });
  }

  if (centerRemoveBtn) {
    centerRemoveBtn.addEventListener('click', () => {
      centerImgUrl = '';
      if (centerImgPreview) { centerImgPreview.src = ''; centerImgPreview.classList.remove('visible'); }
      centerRemoveBtn.classList.remove('visible');
    });
  }

  // Center size selector buttons
  const centerSizeBtns = [document.getElementById('rlt2SizeSmall'), document.getElementById('rlt2SizeMedium'), document.getElementById('rlt2SizeLarge')].filter(Boolean);
  centerSizeBtns.forEach(btn => {
    btn.addEventListener('click', () => {
      centerSizeBtns.forEach(b => b.classList.remove('active'));
      btn.classList.add('active');
      const hidden = document.getElementById('rlt2CfgCenterSize');
      if (hidden) hidden.value = btn.dataset.size;
    });
  });

  // Button icon size selector
  const btnSizeBtns = [document.getElementById('rlt2BtnSizeSmall'), document.getElementById('rlt2BtnSizeMedium'), document.getElementById('rlt2BtnSizeLarge')].filter(Boolean);
  btnSizeBtns.forEach(btn => {
    btn.addEventListener('click', () => {
      btnSizeBtns.forEach(b => b.classList.remove('active'));
      btn.classList.add('active');
      const hidden = document.getElementById('rlt2CfgBtnImgSize');
      if (hidden) hidden.value = btn.dataset.size;
    });
  });

  // Button icon upload wiring
  const btnFileInput  = document.getElementById('rlt2BtnImgFileInput');
  const btnUploadBtn  = document.getElementById('rlt2BtnUploadBtn');
  const btnRemoveBtn  = document.getElementById('rlt2BtnRemoveBtn');
  const btnImgPreview = document.getElementById('rlt2BtnImgPreview');
  const btnImgStatus  = document.getElementById('rlt2BtnImgStatus');

  if (btnUploadBtn) btnUploadBtn.addEventListener('click', () => btnFileInput?.click());

  if (btnFileInput) {
    btnFileInput.addEventListener('change', async () => {
      if (!btnFileInput.files || !btnFileInput.files[0]) return;
      if (btnImgStatus) btnImgStatus.textContent = 'Subiendo…';
      if (btnUploadBtn) btnUploadBtn.disabled = true;
      const fd = new FormData();
      fd.append('action', 'rlt_upload_btn_image');
      fd.append('image', btnFileInput.files[0]);
      try {
        const resp = await fetch(base, { method: 'POST', body: fd });
        const txt  = await resp.text();
        let data;
        try { data = JSON.parse(txt); } catch (e) {
          if (btnImgStatus) btnImgStatus.textContent = 'Error servidor: ' + txt.replace(/<[^>]*>/g,'').trim().slice(0, 120);
          if (btnUploadBtn) btnUploadBtn.disabled = false;
          btnFileInput.value = '';
          return;
        }
        if (data.ok) {
          btnImgUrl = data.url;
          if (btnImgPreview) { btnImgPreview.src = data.url; btnImgPreview.classList.add('visible'); }
          if (btnRemoveBtn) btnRemoveBtn.classList.add('visible');
          if (btnImgStatus) btnImgStatus.textContent = '';
        } else {
          if (btnImgStatus) btnImgStatus.textContent = data.error || 'Error al subir';
        }
      } catch (e) {
        if (btnImgStatus) btnImgStatus.textContent = 'Error de red: ' + e.message;
      }
      if (btnUploadBtn) btnUploadBtn.disabled = false;
      btnFileInput.value = '';
    });
  }

  if (btnRemoveBtn) {
    btnRemoveBtn.addEventListener('click', () => {
      btnImgUrl = '';
      if (btnImgPreview) { btnImgPreview.src = ''; btnImgPreview.classList.remove('visible'); }
      btnRemoveBtn.classList.remove('visible');
    });
  }

  // Save config
  const saveConfigBtn = document.getElementById('rlt2SaveConfigBtn');
  const cfgMsg        = document.getElementById('rlt2CfgMsg');
  if (saveConfigBtn) {
    saveConfigBtn.addEventListener('click', function () {
      saveConfigBtn.disabled = true;
      const ringColor = (ringHexEl?.value || ringColorEl?.value || '#6366f1').trim();
      rltPost('rlt_save_config', {
        enabled:                document.getElementById('rlt2CfgEnabled')?.checked ? 1 : 0,
        spin_cost:              document.getElementById('rlt2CfgCost')?.value ?? 10,
        title:                  document.getElementById('rlt2CfgTitle')?.value ?? '',
        subtitle:               document.getElementById('rlt2CfgSubtitle')?.value ?? '',
        coupon_expiration_days: document.getElementById('rlt2CfgExpDays')?.value ?? 30,
        ring_color:             ringColor,
        ring_width:             document.getElementById('rlt2CfgRingWidth')?.value ?? 2,
        ring_neon:              document.getElementById('rlt2CfgNeon')?.checked ? 1 : 0,
        center_emoji:           document.getElementById('rlt2CfgCenterEmoji')?.value ?? '⭐',
        center_image_url:       centerImgUrl,
        center_size:            document.getElementById('rlt2CfgCenterSize')?.value ?? 'medium',
        btn_emoji:              document.getElementById('rlt2CfgBtnEmoji')?.value ?? '🎰',
        btn_image_url:          btnImgUrl,
        btn_image_size:         document.getElementById('rlt2CfgBtnImgSize')?.value ?? 'medium',
      }, function (data) {
        saveConfigBtn.disabled = false;
        showMsg(cfgMsg, data.ok ? '✓ Guardado' : ('✗ ' + (data.error||'Error')), data.ok);
      });
    });
  }

  // ── Section cards ──
  function renderSections(sections) {
    const grid = document.getElementById('rlt2SectionsGrid');
    if (!grid) return;
    grid.innerHTML = '';
    for (let i = 0; i < 8; i++) {
      const s = sections[i] || { section_order: i+1, prize_type:'winpoints', prize_label:'', chance_percent:12.5, points_amount:0, coupon_discount_percent:0, immunity_days:0, color:'#22d3ee', icon_emoji:'🎁', active:true, font_size:7, font_color:'#ffffff', icon_image_url:'', icon_size:'medium' };
      const ord = s.section_order ?? (i+1);
      const isTransparent = (s.color||'') === 'transparent';
      const colorVal = isTransparent ? '#22d3ee' : (s.color||'#22d3ee');
      const hasImg = !!(s.icon_image_url);
      const typeOpts = PRIZE_TYPES.map(t =>
        `<option value="${t}"${s.prize_type===t?' selected':''}>${PRIZE_LABELS[t]||t}</option>`
      ).join('');

      const card = document.createElement('div');
      card.className = 'rlt2-sect-card';
      card.dataset.order = ord;
      card.innerHTML = `
        <div class="rlt2-sect-card-head">
          <div class="rlt2-sect-card-num">
            <span class="rlt2-sect-card-swatch" style="background:${isTransparent?'rgba(255,255,255,.1)':esc(colorVal)};"></span>
            Sección ${esc(ord)}
          </div>
          <label style="display:flex;align-items:center;gap:.35rem;cursor:pointer;font-size:.74rem;color:#c7d2fe;">
            <input type="checkbox" class="rlt2-sect-active" style="accent-color:#6366f1;"${s.active?' checked':''}> Activa
          </label>
        </div>

        <div class="rlt2-sect-card-row">
          <div class="rlt2-sect-card-field">
            <span class="rlt2-sect-card-lbl">Emoji</span>
            <input type="text" class="rlt2-inp rlt2-sect-emoji" value="${esc(s.icon_emoji||'🎁')}" maxlength="4" style="width:100%;text-align:center;">
          </div>
          <div class="rlt2-sect-card-field">
            <span class="rlt2-sect-card-lbl">Color slot</span>
            <div style="display:flex;gap:3px;align-items:center;">
              <input type="color" class="rlt2-inp rlt2-sect-color" value="${esc(colorVal)}"${isTransparent?' disabled':''} style="width:32px;height:29px;padding:1px;flex-shrink:0;">
              <input type="text" class="rlt2-inp rlt2-sect-color-hex" value="${esc(colorVal)}" maxlength="7" style="width:58px;min-width:0;"${isTransparent?' disabled':''}>
            </div>
            <label style="display:flex;align-items:center;gap:.28rem;margin-top:3px;font-size:.67rem;color:#94a3b8;cursor:pointer;">
              <input type="checkbox" class="rlt2-sect-transparent" style="accent-color:#6366f1;"${isTransparent?' checked':''}> Transparente
            </label>
          </div>
        </div>

        <div class="rlt2-sect-card-field">
          <span class="rlt2-sect-card-lbl">Imagen del slot</span>
          <div class="rlt2-img-area">
            <img class="rlt2-img-preview${hasImg?' visible':''}" src="${hasImg?esc(s.icon_image_url):''}" alt="">
            <input type="file" class="rlt2-img-file-input" accept="image/jpeg,image/png,image/gif,image/webp" style="display:none">
            <button type="button" class="rlt2-img-upload-btn">Subir imagen</button>
            <button type="button" class="rlt2-img-remove-btn${hasImg?' visible':''}">Quitar</button>
            <span class="rlt2-img-status" style="font-size:.66rem;color:#94a3b8;"></span>
          </div>
          <div style="display:flex;gap:.3rem;margin-top:.38rem;">
            <button type="button" class="rlt2-size-btn rlt2-icon-size-btn${(s.icon_size||'medium')==='small'?' active':''}" data-size="small">Pequeño</button>
            <button type="button" class="rlt2-size-btn rlt2-icon-size-btn${(s.icon_size||'medium')==='medium'?' active':''}" data-size="medium">Mediano</button>
            <button type="button" class="rlt2-size-btn rlt2-icon-size-btn${(s.icon_size||'medium')==='large'?' active':''}" data-size="large">Grande</button>
            <input type="hidden" class="rlt2-icon-size-val" value="${esc(s.icon_size||'medium')}">
          </div>
        </div>

        <div class="rlt2-sect-card-field">
          <span class="rlt2-sect-card-lbl">Tipo de premio</span>
          <select class="rlt2-inp rlt2-sect-type" style="width:100%">${typeOpts}</select>
        </div>

        <div class="rlt2-sect-card-field">
          <span class="rlt2-sect-card-lbl">Etiqueta</span>
          <input type="text" class="rlt2-inp rlt2-sect-label" value="${esc(s.prize_label||'')}" maxlength="60" style="width:100%">
        </div>

        <div class="rlt2-sect-card-row">
          <div class="rlt2-sect-card-field">
            <span class="rlt2-sect-card-lbl">% Probabilidad</span>
            <input type="number" class="rlt2-inp rlt2-sect-chance" value="${parseFloat(s.chance_percent||0).toFixed(1)}" min="0" max="100" step="0.5" style="width:100%">
          </div>
          <div class="rlt2-sect-card-field">
            <span class="rlt2-sect-card-lbl">Win Points</span>
            <input type="number" class="rlt2-inp rlt2-sect-wp" value="${esc(s.points_amount||0)}" min="0" style="width:100%">
          </div>
          <div class="rlt2-sect-card-field">
            <span class="rlt2-sect-card-lbl">Cupón %</span>
            <input type="number" class="rlt2-inp rlt2-sect-coupon" value="${esc(s.coupon_discount_percent||0)}" min="0" max="100" style="width:100%">
          </div>
          <div class="rlt2-sect-card-field">
            <span class="rlt2-sect-card-lbl">Inmunidad días</span>
            <input type="number" class="rlt2-inp rlt2-sect-imm" value="${esc(s.immunity_days||0)}" min="0" style="width:100%">
          </div>
        </div>

        <div class="rlt2-sect-card-row">
          <div class="rlt2-sect-card-field">
            <span class="rlt2-sect-card-lbl">Texto tamaño (px)</span>
            <input type="number" class="rlt2-inp rlt2-sect-font-size" value="${esc(s.font_size||7)}" min="5" max="20" style="width:100%">
          </div>
          <div class="rlt2-sect-card-field">
            <span class="rlt2-sect-card-lbl">Color texto</span>
            <input type="color" class="rlt2-inp rlt2-sect-font-color" value="${esc(s.font_color||'#ffffff')}" style="width:100%;height:30px;">
          </div>
        </div>

        <div class="rlt2-sect-card-foot">
          <button type="button" class="rlt2-sect-btn rlt2-sect-save" style="flex:1;">Guardar</button>
          <span class="rlt2-sect-msg" style="font-size:.76rem;"></span>
        </div>
      `;

      // State: current image URL (mutable per card)
      let currentImgUrl = s.icon_image_url || '';

      // Color sync ↔ swatch
      const colorPicker    = card.querySelector('.rlt2-sect-color');
      const colorHex       = card.querySelector('.rlt2-sect-color-hex');
      const transparentChk = card.querySelector('.rlt2-sect-transparent');
      const swatch         = card.querySelector('.rlt2-sect-card-swatch');

      const updateSwatch = () => {
        swatch.style.background = transparentChk.checked ? 'rgba(255,255,255,.1)' : colorPicker.value;
      };
      colorPicker.addEventListener('input', () => { colorHex.value = colorPicker.value; updateSwatch(); });
      colorHex.addEventListener('input', () => { if (/^#[0-9a-fA-F]{6}$/.test(colorHex.value)) { colorPicker.value = colorHex.value; updateSwatch(); } });
      transparentChk.addEventListener('change', () => {
        colorPicker.disabled = transparentChk.checked;
        colorHex.disabled    = transparentChk.checked;
        updateSwatch();
      });

      // Image upload
      const imgPreview = card.querySelector('.rlt2-img-preview');
      const fileInput  = card.querySelector('.rlt2-img-file-input');
      const uploadBtn  = card.querySelector('.rlt2-img-upload-btn');
      const removeBtn  = card.querySelector('.rlt2-img-remove-btn');
      const imgStatus  = card.querySelector('.rlt2-img-status');

      uploadBtn.addEventListener('click', () => fileInput.click());

      fileInput.addEventListener('change', async () => {
        if (!fileInput.files || !fileInput.files[0]) return;
        imgStatus.textContent = 'Subiendo…';
        uploadBtn.disabled = true;
        const fd = new FormData();
        fd.append('action', 'rlt_upload_image');
        fd.append('section_order', ord);
        fd.append('image', fileInput.files[0]);
        try {
          const resp = await fetch(base, { method: 'POST', body: fd });
          const txt  = await resp.text();
          let data;
          try { data = JSON.parse(txt); } catch (e) {
            imgStatus.textContent = 'Error servidor: ' + txt.replace(/<[^>]*>/g,'').trim().slice(0, 120);
            uploadBtn.disabled = false;
            fileInput.value = '';
            return;
          }
          if (data.ok) {
            currentImgUrl = data.url;
            imgPreview.src = data.url;
            imgPreview.classList.add('visible');
            removeBtn.classList.add('visible');
            imgStatus.textContent = '';
          } else {
            imgStatus.textContent = data.error || 'Error al subir';
          }
        } catch (e) {
          imgStatus.textContent = 'Error de red: ' + e.message;
        }
        uploadBtn.disabled = false;
        fileInput.value = '';
      });

      removeBtn.addEventListener('click', () => {
        currentImgUrl = '';
        imgPreview.src = '';
        imgPreview.classList.remove('visible');
        removeBtn.classList.remove('visible');
      });

      // Icon size buttons
      card.querySelectorAll('.rlt2-icon-size-btn').forEach(sBtn => {
        sBtn.addEventListener('click', () => {
          card.querySelectorAll('.rlt2-icon-size-btn').forEach(b => b.classList.remove('active'));
          sBtn.classList.add('active');
          const sizeInput = card.querySelector('.rlt2-icon-size-val');
          if (sizeInput) sizeInput.value = sBtn.dataset.size;
        });
      });

      // Save
      card.querySelector('.rlt2-sect-save').addEventListener('click', function () {
        const btn = this;
        btn.disabled = true;
        const finalColor = transparentChk.checked ? 'transparent' : (colorHex.value.trim() || colorPicker.value);
        rltPost('rlt_save_section', {
          section_order:           ord,
          active:                  card.querySelector('.rlt2-sect-active').checked ? 1 : 0,
          icon_emoji:              card.querySelector('.rlt2-sect-emoji').value,
          icon_image_url:          currentImgUrl,
          icon_size:               card.querySelector('.rlt2-icon-size-val')?.value || 'medium',
          color:                   finalColor,
          prize_type:              card.querySelector('.rlt2-sect-type').value,
          prize_label:             card.querySelector('.rlt2-sect-label').value,
          chance_percent:          card.querySelector('.rlt2-sect-chance').value,
          points_amount:           card.querySelector('.rlt2-sect-wp').value,
          coupon_discount_percent: card.querySelector('.rlt2-sect-coupon').value,
          immunity_days:           card.querySelector('.rlt2-sect-imm').value,
          font_size:               card.querySelector('.rlt2-sect-font-size').value,
          font_color:              card.querySelector('.rlt2-sect-font-color').value,
        }, function (data) {
          btn.disabled = false;
          const msgEl = card.querySelector('.rlt2-sect-msg');
          msgEl.textContent = data.ok ? '✓ Guardado' : '✗ Error';
          msgEl.style.color = data.ok ? '#34d399' : '#f87171';
          setTimeout(() => { msgEl.textContent = ''; }, 2500);
        });
      });

      grid.appendChild(card);
    }
  }

  // ── History ──
  const histLoadBtn = document.getElementById('rlt2HistLoadBtn');
  if (histLoadBtn) {
    histLoadBtn.addEventListener('click', function () {
      this.disabled = true;
      rltPost('rlt_get_history', null, function (data) {
        histLoadBtn.disabled = false;
        const container = document.getElementById('rlt2HistContainer');
        if (!data.ok || !data.history?.length) {
          container.innerHTML = '<div style="color:#64748b;text-align:center;padding:1rem;">Sin giros registrados.</div>';
          return;
        }
        const tagClass = {winpoints:'rlt2-tag-wp',coupon:'rlt2-tag-cup',immunity:'rlt2-tag-imm',streaming_ticket:'rlt2-tag-str'};
        let html = '<div style="overflow-x:auto"><table class="rlt2-hist-tbl"><thead><tr>'
          + '<th>Fecha</th><th>Usuario</th><th>Sección</th><th>Premio</th><th>Costo WP</th><th>Saldo antes</th><th>Saldo después</th>'
          + '</tr></thead><tbody>';
        for (const r of data.history) {
          const tCls = tagClass[r.prize_type] || 'rlt2-tag-wp';
          html += `<tr>
            <td>${esc(r.created_at||'')}</td>
            <td><span style="font-weight:600;color:#e2f8ff">${esc(r.nombre||'—')}</span><br><span style="font-size:.7rem;color:#64748b">${esc(r.email||'')}</span></td>
            <td style="text-align:center">${esc(r.section_order||'')}</td>
            <td>
              <span class="rlt2-tag ${tCls}">${esc(r.prize_type||'')}</span><br>
              <span style="font-size:.75rem;color:#e2e8f0">${esc(r.prize_label||'')}</span>
              ${r.prize_type==='winpoints'&&r.points_amount>0?`<span style="color:#22d3ee;font-size:.75rem"> +${esc(r.points_amount)}</span>`:''}
              ${r.prize_type==='coupon'&&r.coupon_code?`<span style="color:#fb923c;font-size:.7rem"> ${esc(r.coupon_code)}</span>`:''}
            </td>
            <td style="color:#f87171">-${esc(r.wp_cost||0)}</td>
            <td style="color:#94a3b8">${esc(r.balance_before||0)}</td>
            <td style="color:#22d3ee">${esc(r.balance_after||0)}</td>
          </tr>`;
        }
        html += '</tbody></table></div>';
        container.innerHTML = html;
      });
    });
  }
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
