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

// ── Helpers ──────────────────────────────────────────────────────────────

function sim_get_state(int $uid): array {
    global $mysqli;
    $st = $mysqli->prepare('SELECT * FROM win_points_daily_mission_state WHERE user_id = ? LIMIT 1');
    $st->bind_param('i', $uid);
    $st->execute();
    return $st->get_result()->fetch_assoc() ?? [];
}

function sim_get_day(int $uid, string $date): array {
    global $mysqli;
    $st = $mysqli->prepare('SELECT * FROM win_points_daily_mission_days WHERE user_id = ? AND mission_date = ? LIMIT 1');
    $st->bind_param('is', $uid, $date);
    $st->execute();
    return $st->get_result()->fetch_assoc() ?? [];
}

function sim_complete_day(int $uid, string $date, int $streak, string $level): void {
    global $mysqli;
    $settings  = daily_missions_fetch_settings($mysqli);
    $tasks     = daily_missions_fetch_tasks($mysqli, true);
    $taskCount = max(1, count($tasks));

    $st = $mysqli->prepare(
        'INSERT INTO win_points_daily_mission_days
           (user_id, mission_date, login_done, explore_done, share_done, purchase_done, friend_done,
            completed_tasks_count, required_tasks_count, is_completed, current_streak_days, chest_level)
         VALUES (?, ?, 1, 1, 1, 1, 0, ?, ?, 1, ?, ?)
         ON DUPLICATE KEY UPDATE
           login_done=1, explore_done=1, share_done=1, purchase_done=1,
           completed_tasks_count=VALUES(completed_tasks_count),
           required_tasks_count=VALUES(required_tasks_count),
           is_completed=1, current_streak_days=VALUES(current_streak_days),
           chest_level=VALUES(chest_level)'
    );
    $st->bind_param('isiisi', $uid, $date, $taskCount, $taskCount, $streak, $level);
    $st->execute();
    $st->close();

    $st2 = $mysqli->prepare(
        'INSERT INTO win_points_daily_mission_state (user_id, current_streak_days, last_completed_date)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE
           current_streak_days=VALUES(current_streak_days),
           last_completed_date=VALUES(last_completed_date)'
    );
    $st2->bind_param('iis', $uid, $streak, $date);
    $st2->execute();
    $st2->close();

    // Crear registros de premio por cada tarea activa (si aún no existen para ese día)
    $wpName = win_points_program_name();
    $meta   = json_encode(['simulated' => true]);
    $origin = 'task';
    $ptype  = 'winpoints';
    $pstatus = 'granted';
    $checkSt = $mysqli->prepare(
        'SELECT COUNT(*) FROM win_points_daily_mission_rewards WHERE user_id=? AND mission_date=? AND mission_key=? AND origin_type="task"'
    );
    $insertSt = $mysqli->prepare(
        'INSERT INTO win_points_daily_mission_rewards
           (user_id, mission_date, mission_key, origin_type, prize_type, prize_label, reason, reward_status, level_key, points_amount, metadata_json)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    foreach ($tasks as $task) {
        $mKey   = (string) ($task['mission_key'] ?? '');
        $reason = (string) ($task['reward_reason'] ?? $task['title'] ?? 'Tarea');
        $pts    = daily_missions_calculate_reward_points($task, $settings, $streak, $date);
        if ($mKey === '' || $pts <= 0) continue;

        // Saltar si ya existe un reward de tarea para ese día/misión
        $checkSt->bind_param('iss', $uid, $date, $mKey);
        $checkSt->execute();
        if ((int) $checkSt->get_result()->fetch_row()[0] > 0) continue;

        $insertSt->bind_param('issssssssis',
            $uid, $date, $mKey, $origin, $ptype, $wpName, $reason, $pstatus, $level, $pts, $meta
        );
        $insertSt->execute();

        try {
            win_points_record_transaction($mysqli, $uid, $pts, 'mission_daily', '[SIM] ' . $reason);
        } catch (Throwable $e) { /* win_points opcional */ }
    }
    $checkSt->close();
    $insertSt->close();
}

function sim_claim_chest(int $uid, string $date, string $level): array {
    global $mysqli;
    $day = sim_get_day($uid, $date);
    if (empty($day['is_completed'])) {
        return ['ok' => false, 'msg' => 'Día no completado.'];
    }
    if (!empty($day['chest_claimed_at'])) {
        return ['ok' => false, 'msg' => 'Cofre ya reclamado.'];
    }

    $prizes = daily_missions_fetch_prizes($mysqli, $level, true);
    $prize  = daily_missions_pick_prize($prizes);
    if (!$prize) {
        return ['ok' => false, 'msg' => "Sin premios configurados para nivel $level."];
    }

    $pts       = max(0, (int) ($prize['points_amount'] ?? 0));
    $immDays   = max(0, (int) ($prize['immunity_days'] ?? 0));
    $prizeType = $prize['prize_type'] ?? 'winpoints';
    $prizeLabel = $prize['prize_label'] ?? 'Premio';
    $reason    = '[SIM] Cofre ' . $level;
    $status    = 'granted';
    $origin    = 'chest';
    $adminId   = (int) ($_SESSION['auth_user']['id'] ?? 0);
    $meta      = json_encode(['simulated' => true, 'admin_id' => $adminId]);

    $st = $mysqli->prepare(
        'INSERT INTO win_points_daily_mission_rewards
           (user_id, mission_date, origin_type, prize_type, prize_label, reason,
            reward_status, level_key, points_amount, immunity_days, metadata_json)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $st->bind_param('isssssssiis',
        $uid, $date, $origin, $prizeType, $prizeLabel, $reason,
        $status, $level, $pts, $immDays, $meta
    );
    $st->execute();
    $rewardId = (int) $mysqli->insert_id;
    $st->close();

    $now = date('Y-m-d H:i:s');
    $st2 = $mysqli->prepare(
        'UPDATE win_points_daily_mission_days SET chest_claimed_at=?, chest_reward_id=? WHERE user_id=? AND mission_date=?'
    );
    $st2->bind_param('siis', $now, $rewardId, $uid, $date);
    $st2->execute();
    $st2->close();

    $st3 = $mysqli->prepare(
        'INSERT INTO win_points_daily_mission_state (user_id, last_chest_claim_date, immunity_balance, chest_rewards_total)
         VALUES (?, ?, ?, 1)
         ON DUPLICATE KEY UPDATE
           last_chest_claim_date=VALUES(last_chest_claim_date),
           immunity_balance = immunity_balance + VALUES(immunity_balance),
           chest_rewards_total = chest_rewards_total + 1'
    );
    $st3->bind_param('isi', $uid, $date, $immDays);
    $st3->execute();
    $st3->close();

    if ($pts > 0 && $prizeType === 'winpoints') {
        try {
            win_points_record_transaction($mysqli, $uid, $pts, 'mission_daily', $reason);
        } catch (Throwable $e) { /* win_points opcional */ }
    }

    return ['ok' => true, 'prize' => $prize, 'reward_id' => $rewardId, 'points' => $pts, 'immunity' => $immDays];
}

// ── AJAX ─────────────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['action'])) {
    ob_start(); // captura cualquier warning/notice de PHP para que no corrompa el JSON
    header('Content-Type: application/json; charset=utf-8');
    $action = trim((string) ($_POST['action'] ?? ''));
    $uid    = (int) ($_POST['user_id'] ?? 0);
    $out    = ['ok' => false, 'msg' => 'Acción desconocida.'];

    if ($action !== 'search_users' && $uid <= 0) {
        $out = ['ok' => false, 'msg' => 'Usuario inválido.'];
    } else {
        switch ($action) {

            case 'search_users': {
                $q  = '%' . trim((string) ($_POST['q'] ?? '')) . '%';
                $st = $mysqli->prepare(
                    'SELECT id, nombre, email, rol FROM usuarios WHERE nombre LIKE ? OR email LIKE ? ORDER BY nombre ASC LIMIT 20'
                );
                $st->bind_param('ss', $q, $q);
                $st->execute();
                $out = ['ok' => true, 'users' => $st->get_result()->fetch_all(MYSQLI_ASSOC)];
                break;
            }

            case 'get_state': {
                $state    = sim_get_state($uid);
                $today    = date('Y-m-d');
                $todayDay = sim_get_day($uid, $today);

                $stD = $mysqli->prepare(
                    'SELECT * FROM win_points_daily_mission_days WHERE user_id = ? ORDER BY mission_date DESC LIMIT 30'
                );
                $stD->bind_param('i', $uid);
                $stD->execute();
                $days = $stD->get_result()->fetch_all(MYSQLI_ASSOC);

                $stR = $mysqli->prepare(
                    'SELECT * FROM win_points_daily_mission_rewards WHERE user_id = ? ORDER BY created_at DESC LIMIT 15'
                );
                $stR->bind_param('i', $uid);
                $stR->execute();
                $rewards = $stR->get_result()->fetch_all(MYSQLI_ASSOC);

                $stU = $mysqli->prepare('SELECT id, nombre, email, rol FROM usuarios WHERE id = ? LIMIT 1');
                $stU->bind_param('i', $uid);
                $stU->execute();
                $user = $stU->get_result()->fetch_assoc() ?? [];

                $out = [
                    'ok'          => true,
                    'state'       => $state,
                    'today'       => $todayDay,
                    'days'        => $days,
                    'rewards'     => $rewards,
                    'user'        => $user,
                    'server_date' => $today,
                ];
                break;
            }

            case 'complete_today': {
                $today  = date('Y-m-d');
                $state  = sim_get_state($uid);
                $streak = (int) ($state['current_streak_days'] ?? 0);
                $last   = $state['last_completed_date'] ?? null;

                if ($last) {
                    $gap = (int) round((strtotime($today) - strtotime($last)) / 86400);
                    if ($gap === 1)     $streak++;
                    elseif ($gap === 2 && ($state['immunity_balance'] ?? 0) > 0) $streak++;
                    elseif ($gap === 0) { /* mismo día, sin cambio */ }
                    else                $streak = 1;
                } else {
                    $streak = 1;
                }

                $level = daily_missions_resolve_level_key($streak, $today);
                sim_complete_day($uid, $today, $streak, $level);
                $out = [
                    'ok'     => true,
                    'msg'    => "Día $today marcado completado. Racha: $streak. Cofre: $level.",
                    'streak' => $streak,
                    'level'  => $level,
                ];
                break;
            }

            case 'claim_chest_today': {
                $today = date('Y-m-d');
                $day   = sim_get_day($uid, $today);
                $level = $day['chest_level'] ?? 'basic';
                $res   = sim_claim_chest($uid, $today, $level);
                $res['msg'] = $res['ok']
                    ? 'Cofre ' . $level . ' reclamado: ' . ($res['prize']['prize_label'] ?? '?')
                      . ($res['points'] > 0 ? ' (+' . $res['points'] . ' WP)' : '')
                      . ($res['immunity'] > 0 ? ' +' . $res['immunity'] . ' día(s) inmunidad' : '')
                    : ($res['msg'] ?? 'Error al reclamar.');
                $out = $res;
                break;
            }

            case 'advance_day': {
                $state    = sim_get_state($uid);
                $lastDate = $state['last_completed_date'] ?? date('Y-m-d', strtotime('-1 day'));
                $newDate  = date('Y-m-d', strtotime($lastDate . ' +1 day'));
                $streak   = (int) ($state['current_streak_days'] ?? 0) + 1;
                $level    = daily_missions_resolve_level_key($streak, $newDate);
                sim_complete_day($uid, $newDate, $streak, $level);
                $res = sim_claim_chest($uid, $newDate, $level);
                $out = [
                    'ok'     => true,
                    'msg'    => "Día $newDate completado y cofre reclamado. Racha: $streak. Premio: "
                                . ($res['prize']['prize_label'] ?? '—')
                                . ($res['points'] > 0 ? ' (+' . $res['points'] . ' WP)' : ''),
                    'date'   => $newDate,
                    'streak' => $streak,
                    'level'  => $level,
                ];
                break;
            }

            case 'jump_day20': {
                $state   = sim_get_state($uid);
                $current = (int) ($state['current_streak_days'] ?? 0);
                if ($current >= 20) {
                    $out = ['ok' => false, 'msg' => "La racha ya es $current (≥ 20)."];
                    break;
                }
                $lastDate = $state['last_completed_date'] ?? date('Y-m-d', strtotime('-1 day'));
                $needed   = 20 - $current;
                $log      = [];
                for ($i = 1; $i <= $needed; $i++) {
                    $newDate   = date('Y-m-d', strtotime($lastDate . " +$i day"));
                    $newStreak = $current + $i;
                    $level     = daily_missions_resolve_level_key($newStreak, $newDate);
                    sim_complete_day($uid, $newDate, $newStreak, $level);
                    sim_claim_chest($uid, $newDate, $level);
                    $log[] = "$newDate · racha $newStreak · $level";
                }
                $out = [
                    'ok'  => true,
                    'msg' => "Racha llevada a 20. Días generados: $needed.",
                    'log' => $log,
                ];
                break;
            }

            case 'jump_month_end': {
                $state    = sim_get_state($uid);
                $current  = (int) ($state['current_streak_days'] ?? 0);
                $lastDate = $state['last_completed_date'] ?? date('Y-m-d', strtotime('-1 day'));
                $monthEnd = date('Y-m-t', strtotime($lastDate));
                if ($lastDate >= $monthEnd) {
                    $out = ['ok' => false, 'msg' => "Ya estás en el último día del mes ($monthEnd)."];
                    break;
                }
                $needed = (int) round((strtotime($monthEnd) - strtotime($lastDate)) / 86400);
                $log    = [];
                for ($i = 1; $i <= $needed; $i++) {
                    $newDate   = date('Y-m-d', strtotime($lastDate . " +$i day"));
                    $newStreak = $current + $i;
                    $level     = daily_missions_resolve_level_key($newStreak, $newDate);
                    sim_complete_day($uid, $newDate, $newStreak, $level);
                    sim_claim_chest($uid, $newDate, $level);
                    $log[] = "$newDate · racha $newStreak · $level";
                }
                $out = [
                    'ok'  => true,
                    'msg' => "Saltado a fin de mes ($monthEnd). Días generados: $needed.",
                    'log' => $log,
                ];
                break;
            }

            case 'break_streak': {
                $state    = sim_get_state($uid);
                $lastDate = $state['last_completed_date'] ?? date('Y-m-d', strtotime('-3 day'));
                $skipDate = date('Y-m-d', strtotime($lastDate . ' +3 day'));
                $st = $mysqli->prepare(
                    'INSERT IGNORE INTO win_points_daily_mission_days
                       (user_id, mission_date, completed_tasks_count, required_tasks_count, is_completed, current_streak_days)
                     VALUES (?, ?, 0, 4, 0, 0)'
                );
                $st->bind_param('is', $uid, $skipDate);
                $st->execute();
                $st->close();

                $st2 = $mysqli->prepare(
                    'UPDATE win_points_daily_mission_state SET current_streak_days=0 WHERE user_id=?'
                );
                $st2->bind_param('i', $uid);
                $st2->execute();
                $st2->close();

                $out = ['ok' => true, 'msg' => "Racha rota. Saltados 3 días (al $skipDate). Racha reiniciada a 0."];
                break;
            }

            case 'reset_user': {
                foreach (['win_points_daily_mission_rewards', 'win_points_daily_mission_days', 'win_points_daily_mission_state'] as $t) {
                    $st = $mysqli->prepare("DELETE FROM `$t` WHERE user_id = ?");
                    $st->bind_param('i', $uid);
                    $st->execute();
                    $st->close();
                }
                $stWP = $mysqli->prepare("DELETE FROM win_points_transactions WHERE user_id = ? AND description LIKE '[SIM]%'");
                if ($stWP) {
                    $stWP->bind_param('i', $uid);
                    $stWP->execute();
                    $stWP->close();
                }
                $out = ['ok' => true, 'msg' => 'Estado de misiones del usuario reseteado completamente.'];
                break;
            }
        }
    }

    ob_clean(); // descarta cualquier output espurio (warnings, notices) antes de responder
    echo json_encode($out);
    exit;
}

// ── Page setup ───────────────────────────────────────────────────────────
$pageTitle = 'Simulador · Misiones Diarias';
include __DIR__ . '/includes/header.php';
?>
<style>
  .sim-shell { display: grid; gap: 1.4rem; max-width: 1200px; margin: 0 auto; padding: 1.25rem; }

  .sim-card {
    border-radius: 1.4rem;
    border: 1px solid rgba(34,211,238,.18);
    background:
      radial-gradient(circle at top, rgba(34,211,238,.10), transparent 30%),
      linear-gradient(180deg, rgba(8,15,28,.96), rgba(13,22,39,.94));
    box-shadow: 0 14px 36px rgba(0,0,0,.22), 0 0 20px rgba(34,211,238,.06);
    padding: 1.25rem 1.4rem;
  }

  .sim-hero {
    display: flex; align-items: center; flex-wrap: wrap; gap: .75rem;
  }
  .sim-hero-icon {
    width: 46px; height: 46px; border-radius: 50%;
    background: rgba(34,211,238,.12); border: 1px solid rgba(34,211,238,.28);
    display: flex; align-items: center; justify-content: center;
    color: #22d3ee; font-size: 1.25rem; flex-shrink: 0;
  }
  .sim-hero-title { font-size: 1.2rem; font-weight: 700; color: #e2f8ff; }
  .sim-hero-sub   { font-size: .8rem; color: #8cf6ff; letter-spacing: .08em; text-transform: uppercase; }
  .sim-badge-admin {
    margin-left: auto;
    display: inline-flex; align-items: center; gap: .4rem;
    padding: .32rem .75rem; border-radius: 999px;
    background: rgba(239,68,68,.10); border: 1px solid rgba(239,68,68,.28);
    color: #fca5a5; font-size: .72rem; font-weight: 700; letter-spacing: .1em; text-transform: uppercase;
  }

  /* User search */
  .sim-user-search { position: relative; }
  .sim-user-search input {
    width: 100%; padding: .55rem 1rem; border-radius: .7rem;
    background: rgba(255,255,255,.05); border: 1px solid rgba(34,211,238,.2);
    color: #e2f8ff; font-size: .92rem; outline: none;
    transition: border-color .2s;
  }
  .sim-user-search input:focus { border-color: rgba(34,211,238,.5); }
  .sim-user-dropdown {
    position: absolute; top: calc(100% + 4px); left: 0; right: 0; z-index: 50;
    background: #0c1624; border: 1px solid rgba(34,211,238,.22);
    border-radius: .7rem; overflow: hidden; box-shadow: 0 8px 28px rgba(0,0,0,.4);
    display: none;
  }
  .sim-user-dropdown.open { display: block; }
  .sim-user-item {
    padding: .55rem 1rem; cursor: pointer; border-bottom: 1px solid rgba(255,255,255,.05);
    transition: background .15s;
  }
  .sim-user-item:last-child { border-bottom: none; }
  .sim-user-item:hover { background: rgba(34,211,238,.08); }
  .sim-user-item .u-name { font-size: .88rem; font-weight: 600; color: #e2f8ff; }
  .sim-user-item .u-email { font-size: .75rem; color: #8cf6ff; }
  .sim-user-item .u-rol   { font-size: .68rem; color: #64748b; text-transform: uppercase; letter-spacing: .08em; }
  .sim-selected-user {
    margin-top: .7rem; padding: .6rem 1rem; border-radius: .65rem;
    background: rgba(34,211,238,.07); border: 1px solid rgba(34,211,238,.2);
    display: flex; align-items: center; gap: .75rem;
    display: none;
  }
  .sim-selected-user.visible { display: flex; }
  .sim-selected-user .u-avatar {
    width: 34px; height: 34px; border-radius: 50%;
    background: rgba(34,211,238,.15); display: flex; align-items: center;
    justify-content: center; font-size: .85rem; color: #22d3ee; flex-shrink: 0;
  }
  .sim-selected-user .u-info .u-name  { font-size: .88rem; font-weight: 600; color: #e2f8ff; }
  .sim-selected-user .u-info .u-email { font-size: .76rem; color: #8cf6ff; }

  /* State panel */
  .sim-state-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(120px,1fr)); gap: .75rem; margin-top: .85rem; }
  .sim-stat {
    padding: .8rem 1rem; border-radius: .85rem;
    background: rgba(255,255,255,.04); border: 1px solid rgba(255,255,255,.07);
    text-align: center;
  }
  .sim-stat .s-val  { font-size: 1.5rem; font-weight: 800; color: #22d3ee; line-height: 1; }
  .sim-stat .s-lbl  { font-size: .7rem; color: #64748b; text-transform: uppercase; letter-spacing: .09em; margin-top: .3rem; }
  .sim-stat.s-legend .s-val { color: #f59e0b; }
  .sim-stat.s-inter  .s-val { color: #a78bfa; }
  .sim-today-tasks { margin-top: .75rem; display: flex; gap: .5rem; flex-wrap: wrap; }
  .sim-task-dot {
    display: inline-flex; align-items: center; gap: .35rem;
    font-size: .75rem; padding: .28rem .65rem; border-radius: 999px;
    border: 1px solid rgba(255,255,255,.09); background: rgba(255,255,255,.04); color: #64748b;
  }
  .sim-task-dot.done { background: rgba(34,211,238,.1); border-color: rgba(34,211,238,.28); color: #22d3ee; }
  .sim-chest-status {
    margin-top: .65rem; font-size: .82rem; padding: .45rem .85rem;
    border-radius: .55rem; display: inline-flex; align-items: center; gap: .45rem;
  }
  .sim-chest-status.claimed  { background: rgba(34,211,238,.09); border: 1px solid rgba(34,211,238,.22); color: #22d3ee; }
  .sim-chest-status.ready    { background: rgba(245,158,11,.09); border: 1px solid rgba(245,158,11,.28); color: #fbbf24; }
  .sim-chest-status.locked   { background: rgba(100,116,139,.07); border: 1px solid rgba(100,116,139,.18); color: #64748b; }
  .sim-chest-status.no-day   { background: rgba(100,116,139,.07); border: 1px solid rgba(100,116,139,.18); color: #64748b; }

  /* Action buttons */
  .sim-actions { display: grid; grid-template-columns: 1fr 1fr; gap: .6rem; }
  @media (max-width: 480px) { .sim-actions { grid-template-columns: 1fr; } }
  .sim-btn {
    display: flex; align-items: center; gap: .55rem;
    padding: .65rem 1rem; border-radius: .7rem; border: 1px solid transparent;
    font-size: .84rem; font-weight: 600; cursor: pointer;
    transition: background .18s, border-color .18s, opacity .15s;
    text-align: left; width: 100%;
    background: rgba(255,255,255,.05); color: #cbd5e1;
    border-color: rgba(255,255,255,.09);
  }
  .sim-btn:hover:not(:disabled) { background: rgba(255,255,255,.09); color: #e2f8ff; }
  .sim-btn:disabled  { opacity: .4; cursor: not-allowed; }
  .sim-btn i         { font-size: .95rem; flex-shrink: 0; }

  .sim-btn.btn-complete  { background: rgba(34,211,238,.08); border-color: rgba(34,211,238,.25); color: #22d3ee; }
  .sim-btn.btn-complete:hover:not(:disabled) { background: rgba(34,211,238,.16); }
  .sim-btn.btn-claim     { background: rgba(245,158,11,.08); border-color: rgba(245,158,11,.28); color: #fbbf24; }
  .sim-btn.btn-claim:hover:not(:disabled)    { background: rgba(245,158,11,.16); }
  .sim-btn.btn-advance   { background: rgba(167,139,250,.08); border-color: rgba(167,139,250,.25); color: #c4b5fd; }
  .sim-btn.btn-advance:hover:not(:disabled)  { background: rgba(167,139,250,.16); }
  .sim-btn.btn-day20     { background: rgba(167,139,250,.12); border-color: rgba(167,139,250,.35); color: #a78bfa; }
  .sim-btn.btn-day20:hover:not(:disabled)    { background: rgba(167,139,250,.22); }
  .sim-btn.btn-monthend  { background: rgba(251,191,36,.1);  border-color: rgba(251,191,36,.3);  color: #f59e0b; }
  .sim-btn.btn-monthend:hover:not(:disabled) { background: rgba(251,191,36,.18); }
  .sim-btn.btn-break     { background: rgba(239,68,68,.07); border-color: rgba(239,68,68,.22); color: #f87171; }
  .sim-btn.btn-break:hover:not(:disabled)    { background: rgba(239,68,68,.14); }
  .sim-btn.btn-reset     { background: rgba(239,68,68,.06); border-color: rgba(239,68,68,.2); color: #ef4444; grid-column: 1/-1; }
  .sim-btn.btn-reset:hover:not(:disabled)    { background: rgba(239,68,68,.14); }

  /* Log */
  .sim-log {
    background: rgba(0,0,0,.3); border-radius: .7rem; border: 1px solid rgba(255,255,255,.06);
    padding: .7rem 1rem; font-size: .78rem; color: #94a3b8;
    max-height: 180px; overflow-y: auto; font-family: monospace;
  }
  .sim-log .log-ok   { color: #34d399; }
  .sim-log .log-err  { color: #f87171; }
  .sim-log .log-info { color: #8cf6ff; }
  .sim-log .log-sub  { color: #64748b; padding-left: 1rem; }

  /* Timeline */
  .sim-days-table { width: 100%; border-collapse: collapse; font-size: .8rem; }
  .sim-days-table th { color: #22d3ee; font-size: .68rem; letter-spacing: .1em; text-transform: uppercase; padding: .45rem .7rem; border-bottom: 1px solid rgba(34,211,238,.15); text-align: left; }
  .sim-days-table td { padding: .45rem .7rem; border-bottom: 1px solid rgba(255,255,255,.04); color: #cbd5e1; vertical-align: middle; }
  .sim-days-table tr:hover td { background: rgba(34,211,238,.04); }
  .level-basic       { color: #94a3b8; }
  .level-intermediate{ color: #a78bfa; }
  .level-legendary   { color: #f59e0b; }

  /* Rewards */
  .sim-reward-item {
    padding: .55rem .85rem; border-radius: .6rem;
    background: rgba(255,255,255,.03); border: 1px solid rgba(255,255,255,.06);
    display: flex; align-items: center; gap: .75rem; margin-bottom: .4rem;
  }
  .sim-reward-icon { font-size: 1rem; flex-shrink: 0; }
  .sim-reward-label { font-size: .82rem; font-weight: 600; color: #e2f8ff; }
  .sim-reward-meta  { font-size: .72rem; color: #64748b; }
  .sim-reward-sim   { font-size: .65rem; background: rgba(245,158,11,.1); border: 1px solid rgba(245,158,11,.2); color: #fbbf24; padding: .1rem .4rem; border-radius: .3rem; margin-left: auto; flex-shrink: 0; }

  /* Section labels */
  .sim-section-label {
    font-size: .72rem; font-weight: 700; letter-spacing: .12em; text-transform: uppercase;
    color: #22d3ee; margin-bottom: .65rem;
    display: flex; align-items: center; gap: .5rem;
  }
  .sim-section-label::after { content: ''; flex: 1; height: 1px; background: rgba(34,211,238,.15); }

  /* Empty / loading states */
  .sim-empty { color: #64748b; font-size: .82rem; text-align: center; padding: 1.5rem; }
  .sim-spinner { display: inline-block; width: 14px; height: 14px; border: 2px solid rgba(34,211,238,.3); border-top-color: #22d3ee; border-radius: 50%; animation: spin .6s linear infinite; }
  @keyframes spin { to { transform: rotate(360deg); } }

  /* No user selected */
  .sim-no-user { color: #475569; text-align: center; padding: 2.5rem 1rem; font-size: .88rem; }
</style>

<?php
// (Inline admin layout wrapper — avoids needing a separate admin-only layout)
$isAdminPage = true;
?>

<div class="sim-shell" id="sim-shell">

  <!-- Hero -->
  <div class="sim-card">
    <div class="sim-hero">
      <div class="sim-hero-icon"><i class="fa-solid fa-flask-vial"></i></div>
      <div>
        <div class="sim-hero-title">Simulador de Misiones Diarias</div>
        <div class="sim-hero-sub">Herramienta de pruebas — Solo admin</div>
      </div>
      <div class="sim-badge-admin"><i class="fa-solid fa-lock-open"></i> Admin Only</div>
    </div>
  </div>

  <!-- User selector -->
  <div class="sim-card">
    <div class="sim-section-label"><i class="fa-solid fa-user"></i> Seleccionar usuario</div>
    <div class="sim-user-search" id="userSearchWrap">
      <input type="text" id="userSearchInput" placeholder="Buscar por nombre o email…" autocomplete="off">
      <div class="sim-user-dropdown" id="userDropdown"></div>
    </div>
    <div class="sim-selected-user" id="selectedUserBadge">
      <div class="u-avatar"><i class="fa-solid fa-user"></i></div>
      <div class="u-info">
        <div class="u-name" id="selUserName">—</div>
        <div class="u-email" id="selUserEmail">—</div>
      </div>
      <button type="button" class="btn btn-sm ms-auto" style="background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.25);color:#f87171;border-radius:.45rem;font-size:.72rem;" onclick="clearSelectedUser()">
        <i class="fa-solid fa-xmark"></i> Cambiar
      </button>
    </div>
  </div>

  <!-- Main grid: state + actions -->
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.4rem;" id="mainGrid">

    <!-- State panel -->
    <div class="sim-card" id="stateCard">
      <div class="sim-section-label"><i class="fa-solid fa-gauge-high"></i> Estado actual</div>
      <div id="stateContent">
        <div class="sim-no-user"><i class="fa-solid fa-user-slash" style="font-size:1.5rem;display:block;margin-bottom:.5rem;opacity:.4;"></i>Selecciona un usuario primero</div>
      </div>
    </div>

    <!-- Actions panel -->
    <div class="sim-card" id="actionsCard">
      <div class="sim-section-label"><i class="fa-solid fa-bolt"></i> Acciones de simulación</div>
      <div class="sim-actions" id="actionButtons">
        <button class="sim-btn btn-complete" disabled data-action="complete_today">
          <i class="fa-solid fa-check-circle"></i><span>Completar hoy</span>
        </button>
        <button class="sim-btn btn-claim" disabled data-action="claim_chest_today">
          <i class="fa-solid fa-treasure-chest"></i><span>Reclamar cofre hoy</span>
        </button>
        <button class="sim-btn btn-advance" disabled data-action="advance_day">
          <i class="fa-solid fa-arrow-right-to-bracket"></i><span>Avanzar 1 día</span>
        </button>
        <button class="sim-btn btn-day20" disabled data-action="jump_day20">
          <i class="fa-solid fa-forward-step"></i><span>Saltar a racha 20</span>
        </button>
        <button class="sim-btn btn-monthend" disabled data-action="jump_month_end">
          <i class="fa-solid fa-calendar-day"></i><span>Saltar a fin de mes</span>
        </button>
        <button class="sim-btn btn-break" disabled data-action="break_streak">
          <i class="fa-solid fa-heart-crack"></i><span>Romper racha</span>
        </button>
        <button class="sim-btn btn-reset" disabled data-action="reset_user">
          <i class="fa-solid fa-trash-can"></i><span>Reset completo del usuario</span>
        </button>
      </div>

      <div style="margin-top:1rem;">
        <div class="sim-section-label"><i class="fa-solid fa-terminal"></i> Log de acciones</div>
        <div class="sim-log" id="simLog"><span class="log-info">— Esperando acciones…</span></div>
      </div>
    </div>
  </div>

  <!-- Timeline -->
  <div class="sim-card" id="timelineCard" style="display:none;">
    <div class="sim-section-label"><i class="fa-solid fa-calendar-check"></i> Días simulados</div>
    <div style="overflow-x:auto;">
      <table class="sim-days-table" id="daysTable">
        <thead>
          <tr>
            <th>Fecha</th>
            <th>Racha</th>
            <th>Nivel cofre</th>
            <th>Tareas</th>
            <th>Cofre</th>
          </tr>
        </thead>
        <tbody id="daysBody"></tbody>
      </table>
    </div>
  </div>

  <!-- Rewards -->
  <div class="sim-card" id="rewardsCard" style="display:none;">
    <div class="sim-section-label"><i class="fa-solid fa-gift"></i> Recompensas generadas</div>
    <div id="rewardsList"></div>
  </div>

</div>

<!-- Responsive fix for main grid -->
<style>
  @media (max-width: 700px) {
    #mainGrid { grid-template-columns: 1fr !important; }
  }
</style>

<script>
(function () {
  const SIM_URL = '<?php echo htmlspecialchars(app_path('/admin/sim-misiones'), ENT_QUOTES); ?>';
  let selectedUserId = null;
  let searchTimer = null;

  // ── User search ──────────────────────────────────────────────────────
  const searchInput  = document.getElementById('userSearchInput');
  const dropdown     = document.getElementById('userDropdown');
  const selectedBadge = document.getElementById('selectedUserBadge');

  searchInput.addEventListener('input', () => {
    clearTimeout(searchTimer);
    const q = searchInput.value.trim();
    if (q.length < 1) { dropdown.classList.remove('open'); return; }
    searchTimer = setTimeout(() => searchUsers(q), 250);
  });

  document.addEventListener('click', e => {
    if (!document.getElementById('userSearchWrap').contains(e.target)) {
      dropdown.classList.remove('open');
    }
  });

  function searchUsers(q) {
    dropdown.innerHTML = '<div class="sim-user-item"><span class="log-info"><span class="sim-spinner"></span> Buscando…</span></div>';
    dropdown.classList.add('open');
    post({ action: 'search_users', q }).then(data => {
      if (!data.ok || !data.users.length) {
        dropdown.innerHTML = '<div class="sim-user-item"><span style="color:#64748b">Sin resultados.</span></div>';
        return;
      }
      dropdown.innerHTML = data.users.map(u =>
        `<div class="sim-user-item" data-id="${u.id}" data-name="${esc(u.nombre)}" data-email="${esc(u.email)}">
           <div class="u-name">${esc(u.nombre)}</div>
           <div class="u-email">${esc(u.email)} <span class="u-rol">${esc(u.rol)}</span></div>
         </div>`
      ).join('');
      dropdown.querySelectorAll('.sim-user-item').forEach(el => {
        el.addEventListener('click', () => selectUser(el.dataset.id, el.dataset.name, el.dataset.email));
      });
    });
  }

  function selectUser(id, name, email) {
    selectedUserId = id;
    document.getElementById('selUserName').textContent  = name;
    document.getElementById('selUserEmail').textContent = email;
    selectedBadge.classList.add('visible');
    searchInput.value = '';
    dropdown.classList.remove('open');
    enableButtons(true);
    loadState();
  }

  window.clearSelectedUser = function () {
    selectedUserId = null;
    selectedBadge.classList.remove('visible');
    enableButtons(false);
    document.getElementById('stateContent').innerHTML = '<div class="sim-no-user"><i class="fa-solid fa-user-slash" style="font-size:1.5rem;display:block;margin-bottom:.5rem;opacity:.4;"></i>Selecciona un usuario primero</div>';
    document.getElementById('timelineCard').style.display = 'none';
    document.getElementById('rewardsCard').style.display = 'none';
  };

  function enableButtons(on) {
    document.querySelectorAll('#actionButtons .sim-btn').forEach(btn => {
      btn.disabled = !on;
    });
  }

  // ── State loading ────────────────────────────────────────────────────
  function loadState() {
    if (!selectedUserId) return;
    document.getElementById('stateContent').innerHTML = '<div class="sim-empty"><span class="sim-spinner"></span> Cargando…</div>';
    post({ action: 'get_state', user_id: selectedUserId }).then(data => {
      if (!data.ok) { logMsg('Error: ' + data.msg, 'err'); return; }
      renderState(data);
      renderDays(data.days);
      renderRewards(data.rewards);
    });
  }

  function fmtDate(iso) {
    if (!iso || iso === '—') return '—';
    const p = iso.split('-');
    return p.length === 3 ? p[2] + '/' + p[1] + '/' + p[0] : iso;
  }

  function nextIsoDate(iso) {
    if (!iso) return null;
    const d = new Date(iso + 'T00:00:00');
    d.setDate(d.getDate() + 1);
    return d.toISOString().slice(0, 10);
  }

  function renderState(data) {
    const s     = data.state || {};
    const today = data.today || {};
    const streak = parseInt(s.current_streak_days || 0);
    const immunity = parseInt(s.immunity_balance || 0);
    const lastDate = s.last_completed_date || null;
    const chestLevel = today.chest_level || '';
    const chestClaimed = !!today.chest_claimed_at;
    const dayCompleted = !!today.is_completed;
    const serverDate = data.server_date;

    let levelClass = '';
    let levelLabel = 'Básico';
    if (chestLevel === 'legendary')    { levelClass = 'level-legendary'; levelLabel = 'Legendario'; }
    else if (chestLevel === 'intermediate') { levelClass = 'level-intermediate'; levelLabel = 'Intermedio'; }

    let statClass = '';
    if (chestLevel === 'legendary')    statClass = 's-legend';
    else if (chestLevel === 'intermediate') statClass = 's-inter';

    let chestHtml = '';
    if (!today.mission_date) {
      chestHtml = '<div class="sim-chest-status no-day"><i class="fa-solid fa-calendar-xmark"></i> Sin registro hoy (' + serverDate + ')</div>';
    } else if (chestClaimed) {
      chestHtml = '<div class="sim-chest-status claimed"><i class="fa-solid fa-check-circle"></i> Cofre reclamado hoy ✓</div>';
    } else if (dayCompleted) {
      chestHtml = '<div class="sim-chest-status ready"><i class="fa-solid fa-treasure-chest"></i> Cofre listo para reclamar</div>';
    } else {
      chestHtml = '<div class="sim-chest-status locked"><i class="fa-solid fa-lock"></i> Cofre bloqueado (tareas pendientes)</div>';
    }

    const taskKeys = ['login','explore','share','purchase','friend'];
    const taskMap  = { login:'Login', explore:'Explorar', share:'Compartir', purchase:'Compra', friend:'Referido' };
    const taskCols = { login: today.login_done, explore: today.explore_done, share: today.share_done, purchase: today.purchase_done, friend: today.friend_done };
    const taskDots = taskKeys.map(k =>
      `<span class="sim-task-dot ${taskCols[k] ? 'done' : ''}">
         ${taskCols[k] ? '<i class="fa-solid fa-check" style="font-size:.7rem;"></i>' : '<i class="fa-solid fa-minus" style="font-size:.7rem;"></i>'}
         ${taskMap[k]}
       </span>`
    ).join('');

    // Banner de fecha simulada
    const simDate   = lastDate || serverDate;
    const nextDate  = nextIsoDate(simDate);
    const isAhead   = lastDate && lastDate > serverDate;
    const bannerBg  = isAhead
      ? 'rgba(245,158,11,.09)' : 'rgba(34,211,238,.07)';
    const bannerBdr = isAhead
      ? 'rgba(245,158,11,.3)'  : 'rgba(34,211,238,.2)';
    const bannerClr = isAhead ? '#f59e0b' : '#22d3ee';
    const simLabel  = isAhead ? 'SIMULANDO' : 'DÍA ACTUAL';
    const simDateBanner = `
      <div style="display:flex;align-items:center;flex-wrap:wrap;gap:.6rem .9rem;
                  padding:.7rem 1rem;margin-bottom:.85rem;border-radius:.7rem;
                  background:${bannerBg};border:1px solid ${bannerBdr};">
        <i class="fa-solid fa-clock-rotate-left" style="color:${bannerClr};font-size:.95rem;flex-shrink:0;"></i>
        <div style="flex:1;min-width:0;">
          <div style="font-size:.65rem;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:${bannerClr};margin-bottom:.15rem;">${simLabel}</div>
          <div style="font-size:1.05rem;font-weight:800;color:${isAhead ? '#fbbf24' : '#e2f8ff'};">${fmtDate(simDate)}</div>
        </div>
        <div style="text-align:right;flex-shrink:0;">
          <div style="font-size:.63rem;color:#64748b;text-transform:uppercase;letter-spacing:.09em;margin-bottom:.1rem;">Próximo día</div>
          <div style="font-size:.88rem;font-weight:600;color:#94a3b8;">${fmtDate(nextDate)}</div>
        </div>
      </div>`;

    document.getElementById('stateContent').innerHTML = simDateBanner + `
      <div class="sim-state-grid">
        <div class="sim-stat"><div class="s-val">${streak}</div><div class="s-lbl">Racha (días)</div></div>
        <div class="sim-stat ${statClass}"><div class="s-val ${levelClass}" style="font-size:1rem;padding-top:.15rem;">${chestLevel ? levelLabel : '—'}</div><div class="s-lbl">Nivel cofre</div></div>
        <div class="sim-stat"><div class="s-val" style="font-size:1rem;">${immunity}</div><div class="s-lbl">Inmunidad</div></div>
      </div>
      <div style="margin-top:.75rem;font-size:.76rem;color:#64748b;margin-bottom:.3rem;">Tareas del día real (${serverDate}):</div>
      <div class="sim-today-tasks">${taskDots}</div>
      ${chestHtml}
    `;
  }

  function renderDays(days) {
    const card = document.getElementById('timelineCard');
    const tbody = document.getElementById('daysBody');
    if (!days || !days.length) { card.style.display = 'none'; return; }
    card.style.display = '';

    const levelLabel = { basic:'Básico', intermediate:'Intermedio', legendary:'Legendario' };
    const levelCls   = { basic:'level-basic', intermediate:'level-intermediate', legendary:'level-legendary' };

    tbody.innerHTML = days.map(d => {
      const lvl = d.chest_level || 'basic';
      const claimed = d.chest_claimed_at ? '<i class="fa-solid fa-check" style="color:#22d3ee;"></i>' : '<i class="fa-solid fa-xmark" style="color:#475569;"></i>';
      return `<tr>
        <td>${d.mission_date}</td>
        <td>${d.current_streak_days ?? '—'}</td>
        <td class="${levelCls[lvl] || ''}">${levelLabel[lvl] || lvl}</td>
        <td>${d.completed_tasks_count ?? 0}/${d.required_tasks_count ?? 4}</td>
        <td>${claimed}</td>
      </tr>`;
    }).join('');
  }

  function renderRewards(rewards) {
    const card = document.getElementById('rewardsCard');
    const list = document.getElementById('rewardsList');
    if (!rewards || !rewards.length) { card.style.display = 'none'; return; }
    card.style.display = '';

    const icons = { winpoints:'fa-coins', coupon:'fa-tag', immunity:'fa-shield-halved', streaming_ticket:'fa-play-circle' };
    const isSim = r => { try { return JSON.parse(r.metadata_json || '{}').simulated; } catch(e){ return false; } };

    list.innerHTML = rewards.map(r => {
      const icon = icons[r.prize_type] || 'fa-gift';
      const sim  = isSim(r) ? '<span class="sim-reward-sim">SIM</span>' : '';
      const pts  = r.points_amount > 0 ? ` <span style="color:#22d3ee;font-size:.75rem;">+${r.points_amount} WP</span>` : '';
      const imm  = r.immunity_days > 0 ? ` <span style="color:#a78bfa;font-size:.75rem;">+${r.immunity_days}d inmunidad</span>` : '';
      return `<div class="sim-reward-item">
        <i class="fa-solid ${icon} sim-reward-icon" style="color:#22d3ee;"></i>
        <div>
          <div class="sim-reward-label">${esc(r.prize_label || '?')}${pts}${imm}</div>
          <div class="sim-reward-meta">${esc(r.mission_date || '')} · ${esc(r.level_key || '')} · ${esc(r.reward_status || '')}</div>
        </div>
        ${sim}
      </div>`;
    }).join('');
  }

  // ── Action buttons ───────────────────────────────────────────────────
  document.querySelectorAll('#actionButtons .sim-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      const action = btn.dataset.action;
      if (!selectedUserId) return;

      if (action === 'reset_user') {
        if (!confirm('¿Borrar todos los datos de misiones de este usuario? Esta acción es irreversible.')) return;
      }
      if (action === 'break_streak') {
        if (!confirm('¿Romper la racha del usuario (simular 3 días sin actividad)?')) return;
      }

      const btnLabel = btn.querySelector('span').textContent;
      logMsg('→ ' + btnLabel + '…', 'info');
      setLoading(btn, true);

      post({ action, user_id: selectedUserId }).then(data => {
        setLoading(btn, false);
        if (data.ok) {
          logMsg('✓ ' + data.msg, 'ok');
          if (data.log && data.log.length) {
            data.log.forEach(l => logMsg('  ' + l, 'sub'));
          }
        } else {
          logMsg('✗ ' + (data.msg || 'Error desconocido.'), 'err');
        }
        loadState();
      }).catch(e => {
        setLoading(btn, false);
        logMsg('✗ Error de red: ' + e.message, 'err');
      });
    });
  });

  // ── Utilities ────────────────────────────────────────────────────────
  function post(data) {
    const fd = new FormData();
    Object.entries(data).forEach(([k,v]) => fd.append(k, v));
    return fetch(SIM_URL, { method: 'POST', body: fd }).then(r => r.json());
  }

  function logMsg(msg, type = 'info') {
    const log = document.getElementById('simLog');
    const now = new Date().toLocaleTimeString('es-VE', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
    const cls = { ok: 'log-ok', err: 'log-err', info: 'log-info', sub: 'log-sub' }[type] || 'log-info';
    if (log.innerHTML.includes('Esperando acciones')) log.innerHTML = '';
    log.innerHTML += `<div class="${cls}">[${now}] ${esc(msg)}</div>`;
    log.scrollTop = log.scrollHeight;
  }

  function setLoading(btn, on) {
    if (on) {
      btn.dataset.origHtml = btn.innerHTML;
      btn.innerHTML = '<span class="sim-spinner"></span> Procesando…';
      btn.disabled = true;
    } else {
      btn.innerHTML = btn.dataset.origHtml || btn.innerHTML;
      btn.disabled = false;
    }
  }

  function esc(s) {
    return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
