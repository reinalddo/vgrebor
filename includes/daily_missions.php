<?php

require_once __DIR__ . '/tenant.php';
require_once __DIR__ . '/store_config.php';

if (!function_exists('daily_missions_db')) {
    function daily_missions_db(): mysqli {
        global $mysqli;

        if (!isset($mysqli) || !($mysqli instanceof mysqli)) {
            require_once __DIR__ . '/db_connect.php';
        }

        return $mysqli;
    }
}

if (!function_exists('daily_missions_now')) {
    function daily_missions_now(): string {
        return date('Y-m-d H:i:s');
    }
}

if (!function_exists('daily_missions_today')) {
    function daily_missions_today(): string {
        return date('Y-m-d');
    }
}

if (!function_exists('daily_missions_default_settings')) {
    function daily_missions_default_settings(): array {
        return [
            'id' => 1,
            'enabled' => 1,
            'title' => 'Mision diaria',
            'subtitle' => 'Completa las tareas, abre el cofre y gana Win Points.',
            'active_task_goal' => 4,
            'day20_multiplier' => 4.0,
            'month_end_multiplier' => 10.0,
            'explore_timer_seconds' => 7,
            'share_timer_seconds' => 15,
            'immunity_days' => 1,
            'coupon_discount_percent' => 10,
            'coupon_expiration_days' => 30,
            'streaming_user_id' => null,
            'accordion_base_color1' => '',
            'accordion_base_color2' => '',
            'accordion_bg_color1' => '',
            'accordion_bg_color2' => '',
            'accordion_base_border_enabled' => 0,
            'accordion_base_border_color' => '',
            'accordion_base_border_width' => 2,
            'accordion_bg_border_enabled' => 0,
            'accordion_bg_border_color' => '',
            'accordion_bg_border_width' => 2,
        ];
    }
}

if (!function_exists('daily_missions_default_tasks')) {
    function daily_missions_default_tasks(): array {
        return [
            [
                'mission_key' => 'login_daily',
                'title' => 'Iniciar Sesión',
                'description' => 'Entra a la tienda y reclama tu bono de conexion diaria.',
                'task_type' => 'login',
                'action_label' => 'Entrar',
                'action_url' => '/',
                'base_points' => 2,
                'timer_seconds' => 0,
                'active' => 1,
                'sort_order' => 1,
                'accent_color' => '#22d3ee',
                'icon_label' => '01',
                'reward_reason' => 'Bono de inicio de sesion diario',
            ],
            [
                'mission_key' => 'explore_random',
                'title' => 'Explora un juego',
                'description' => 'Abre un juego al azar y permanece 7 segundos en la experiencia.',
                'task_type' => 'explore',
                'action_label' => 'Explorar ahora',
                'action_url' => '/juegos',
                'base_points' => 3,
                'timer_seconds' => 7,
                'active' => 1,
                'sort_order' => 2,
                'accent_color' => '#34d399',
                'icon_label' => '02',
                'reward_reason' => 'Exploracion diaria de juegos',
            ],
            [
                'mission_key' => 'share_social',
                'title' => 'Comparte en redes',
                'description' => 'Abre Instagram, TikTok o Facebook y mantente 15 segundos.',
                'task_type' => 'share',
                'action_label' => 'Compartir',
                'action_url' => '/',
                'base_points' => 3,
                'timer_seconds' => 15,
                'active' => 1,
                'sort_order' => 3,
                'accent_color' => '#f97316',
                'icon_label' => '03',
                'reward_reason' => 'Difusion social diaria',
            ],
            [
                'mission_key' => 'purchase_any',
                'title' => 'Realiza una compra',
                'description' => 'Completa una compra para sumar premio por actividad.',
                'task_type' => 'purchase',
                'action_label' => 'Ir a comprar',
                'action_url' => '/juegos',
                'base_points' => 5,
                'timer_seconds' => 0,
                'active' => 1,
                'sort_order' => 4,
                'accent_color' => '#fbbf24',
                'icon_label' => '04',
                'reward_reason' => 'Compra completada',
            ],
            [
                'mission_key' => 'refer_friend',
                'title' => 'Invita a un amigo',
                'description' => 'Mision temporalmente desactivada.',
                'task_type' => 'friend',
                'action_label' => 'Invitar',
                'action_url' => '/quiero-unirme',
                'base_points' => 0,
                'timer_seconds' => 0,
                'active' => 0,
                'sort_order' => 5,
                'accent_color' => '#94a3b8',
                'icon_label' => '05',
                'reward_reason' => 'Invitacion de amigo',
            ],
        ];
    }
}

if (!function_exists('daily_missions_default_prizes')) {
    function daily_missions_default_prizes(): array {
        return [
            ['level_key' => 'basic', 'prize_type' => 'winpoints', 'prize_label' => 'Win Points', 'chance_percent' => 65, 'points_amount' => 5, 'coupon_discount_percent' => 0, 'immunity_days' => 0, 'streaming_user_id' => null, 'active' => 1, 'sort_order' => 1, 'metadata_json' => null],
            ['level_key' => 'basic', 'prize_type' => 'coupon', 'prize_label' => 'Cupón de descuento', 'chance_percent' => 15, 'points_amount' => 0, 'coupon_discount_percent' => 10, 'immunity_days' => 0, 'streaming_user_id' => null, 'active' => 1, 'sort_order' => 2, 'metadata_json' => null],
            ['level_key' => 'basic', 'prize_type' => 'immunity', 'prize_label' => 'Escudo de continuidad', 'chance_percent' => 10, 'points_amount' => 0, 'coupon_discount_percent' => 0, 'immunity_days' => 1, 'streaming_user_id' => null, 'active' => 1, 'sort_order' => 3, 'metadata_json' => null],
            ['level_key' => 'basic', 'prize_type' => 'streaming_ticket', 'prize_label' => 'Ticket de streaming', 'chance_percent' => 10, 'points_amount' => 0, 'coupon_discount_percent' => 0, 'immunity_days' => 0, 'streaming_user_id' => null, 'active' => 1, 'sort_order' => 4, 'metadata_json' => null],
            ['level_key' => 'intermediate', 'prize_type' => 'winpoints', 'prize_label' => 'Win Points', 'chance_percent' => 55, 'points_amount' => 15, 'coupon_discount_percent' => 0, 'immunity_days' => 0, 'streaming_user_id' => null, 'active' => 1, 'sort_order' => 1, 'metadata_json' => null],
            ['level_key' => 'intermediate', 'prize_type' => 'coupon', 'prize_label' => 'Cupón de descuento', 'chance_percent' => 20, 'points_amount' => 0, 'coupon_discount_percent' => 15, 'immunity_days' => 0, 'streaming_user_id' => null, 'active' => 1, 'sort_order' => 2, 'metadata_json' => null],
            ['level_key' => 'intermediate', 'prize_type' => 'immunity', 'prize_label' => 'Escudo de continuidad', 'chance_percent' => 15, 'points_amount' => 0, 'coupon_discount_percent' => 0, 'immunity_days' => 1, 'streaming_user_id' => null, 'active' => 1, 'sort_order' => 3, 'metadata_json' => null],
            ['level_key' => 'intermediate', 'prize_type' => 'streaming_ticket', 'prize_label' => 'Ticket de streaming', 'chance_percent' => 10, 'points_amount' => 0, 'coupon_discount_percent' => 0, 'immunity_days' => 0, 'streaming_user_id' => null, 'active' => 1, 'sort_order' => 4, 'metadata_json' => null],
            ['level_key' => 'legendary', 'prize_type' => 'winpoints', 'prize_label' => 'Win Points legendarios', 'chance_percent' => 40, 'points_amount' => 30, 'coupon_discount_percent' => 0, 'immunity_days' => 0, 'streaming_user_id' => null, 'active' => 1, 'sort_order' => 1, 'metadata_json' => null],
            ['level_key' => 'legendary', 'prize_type' => 'coupon', 'prize_label' => 'Cupón legendario', 'chance_percent' => 20, 'points_amount' => 0, 'coupon_discount_percent' => 20, 'immunity_days' => 0, 'streaming_user_id' => null, 'active' => 1, 'sort_order' => 2, 'metadata_json' => null],
            ['level_key' => 'legendary', 'prize_type' => 'immunity', 'prize_label' => 'Escudo legendario', 'chance_percent' => 20, 'points_amount' => 0, 'coupon_discount_percent' => 0, 'immunity_days' => 2, 'streaming_user_id' => null, 'active' => 1, 'sort_order' => 3, 'metadata_json' => null],
            ['level_key' => 'legendary', 'prize_type' => 'streaming_ticket', 'prize_label' => 'Ticket de streaming', 'chance_percent' => 20, 'points_amount' => 0, 'coupon_discount_percent' => 0, 'immunity_days' => 0, 'streaming_user_id' => null, 'active' => 1, 'sort_order' => 4, 'metadata_json' => null],
        ];
    }
}

if (!function_exists('daily_missions_level_palette')) {
    function daily_missions_level_palette(string $levelKey): array {
        $palette = [
            'basic' => [
                'label' => 'Basico',
                'accent' => '#22d3ee',
                'glow' => 'rgba(34,211,238,0.32)',
                'border' => 'rgba(34,211,238,0.42)',
                'surface' => 'rgba(7,15,25,0.94)',
            ],
            'intermediate' => [
                'label' => 'Intermedio',
                'accent' => '#fb923c',
                'glow' => 'rgba(251,146,60,0.30)',
                'border' => 'rgba(251,146,60,0.42)',
                'surface' => 'rgba(15,12,8,0.94)',
            ],
            'legendary' => [
                'label' => 'Legendario',
                'accent' => '#fcd34d',
                'glow' => 'rgba(252,211,77,0.34)',
                'border' => 'rgba(252,211,77,0.48)',
                'surface' => 'rgba(20,14,3,0.96)',
            ],
        ];

        return $palette[$levelKey] ?? $palette['basic'];
    }
}

if (!function_exists('daily_missions_level_label')) {
    function daily_missions_level_label(string $levelKey): string {
        return daily_missions_level_palette($levelKey)['label'] ?? 'Basico';
    }
}

if (!function_exists('daily_missions_reward_status_label')) {
    function daily_missions_reward_status_label(string $status): string {
        $labels = [
            'pending' => 'Pendiente',
            'granted' => 'Entregado',
            'issued' => 'Emitido',
            'assigned' => 'Asignado',
            'claimed' => 'Reclamado',
            'used' => 'Usado',
            'expired' => 'Vencido',
            'cancelled' => 'Cancelado',
        ];

        return $labels[$status] ?? ($status !== '' ? $status : 'Pendiente');
    }
}

if (!function_exists('daily_missions_task_type_label')) {
    function daily_missions_task_type_label(string $taskType): string {
        $labels = [
            'login' => 'Ingreso diario',
            'explore' => 'Explorar',
            'share' => 'Compartir',
            'purchase' => 'Compra',
            'friend' => 'Invitar amigo',
        ];

        return $labels[$taskType] ?? ($taskType !== '' ? $taskType : 'Tarea');
    }
}

if (!function_exists('daily_missions_task_flag_column')) {
    function daily_missions_task_flag_column(string $missionKey): string {
        $mapping = [
            'login_daily' => 'login_done',
            'explore_random' => 'explore_done',
            'share_social' => 'share_done',
            'purchase_any' => 'purchase_done',
            'refer_friend' => 'friend_done',
        ];

        return $mapping[$missionKey] ?? 'login_done';
    }
}

if (!function_exists('daily_missions_date_diff_days')) {
    function daily_missions_date_diff_days(string $fromDate, string $toDate): int {
        try {
            $from = new DateTimeImmutable($fromDate);
            $to = new DateTimeImmutable($toDate);
            if ($to < $from) {
                return 0;
            }

            return (int) $from->diff($to)->days;
        } catch (Throwable $exception) {
            return 0;
        }
    }
}

if (!function_exists('daily_missions_is_month_end')) {
    function daily_missions_is_month_end(?string $date = null): bool {
        $candidate = trim((string) ($date ?? ''));
        if ($candidate === '') {
            $candidate = daily_missions_today();
        }

        return $candidate === date('Y-m-t', strtotime($candidate));
    }
}

if (!function_exists('daily_missions_db_query_assoc')) {
    function daily_missions_db_query_assoc(mysqli $mysqli, string $sql): array {
        $result = $mysqli->query($sql);
        if (!($result instanceof mysqli_result)) {
            return [];
        }

        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }

        return $rows;
    }
}

if (!function_exists('daily_missions_fetch_settings')) {
    function daily_missions_fetch_settings(mysqli $mysqli): array {
        daily_missions_ensure_schema();
        $defaults = daily_missions_default_settings();
        $settings = $defaults;

        $result = $mysqli->query('SELECT * FROM win_points_daily_mission_settings WHERE id = 1 LIMIT 1');
        $row = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
        if (is_array($row)) {
            $settings['enabled'] = (int) ($row['enabled'] ?? $settings['enabled']);
            $settings['title'] = trim((string) ($row['title'] ?? $settings['title']));
            $settings['subtitle'] = trim((string) ($row['subtitle'] ?? $settings['subtitle']));
            $settings['active_task_goal'] = max(1, (int) ($row['active_task_goal'] ?? $settings['active_task_goal']));
            $settings['day20_multiplier'] = max(0, (float) ($row['day20_multiplier'] ?? $settings['day20_multiplier']));
            $settings['month_end_multiplier'] = max(0, (float) ($row['month_end_multiplier'] ?? $settings['month_end_multiplier']));
            $settings['explore_timer_seconds'] = max(0, (int) ($row['explore_timer_seconds'] ?? $settings['explore_timer_seconds']));
            $settings['share_timer_seconds'] = max(0, (int) ($row['share_timer_seconds'] ?? $settings['share_timer_seconds']));
            $settings['immunity_days'] = max(0, (int) ($row['immunity_days'] ?? $settings['immunity_days']));
            $settings['coupon_discount_percent'] = max(0, min(100, (int) ($row['coupon_discount_percent'] ?? $settings['coupon_discount_percent'])));
            $settings['coupon_expiration_days'] = max(1, (int) ($row['coupon_expiration_days'] ?? $settings['coupon_expiration_days']));
            $settings['streaming_user_id'] = (int) ($row['streaming_user_id'] ?? 0) > 0 ? (int) $row['streaming_user_id'] : null;
            $settings['accordion_base_color1'] = (string) ($row['accordion_base_color1'] ?? '');
            $settings['accordion_base_color2'] = (string) ($row['accordion_base_color2'] ?? '');
            $settings['accordion_bg_color1'] = (string) ($row['accordion_bg_color1'] ?? '');
            $settings['accordion_bg_color2'] = (string) ($row['accordion_bg_color2'] ?? '');
            $settings['accordion_base_border_enabled'] = (int) ($row['accordion_base_border_enabled'] ?? 0);
            $settings['accordion_base_border_color'] = (string) ($row['accordion_base_border_color'] ?? '');
            $settings['accordion_base_border_width'] = max(1, min(12, (int) ($row['accordion_base_border_width'] ?? 2)));
            $settings['accordion_bg_border_enabled'] = (int) ($row['accordion_bg_border_enabled'] ?? 0);
            $settings['accordion_bg_border_color'] = (string) ($row['accordion_bg_border_color'] ?? '');
            $settings['accordion_bg_border_width'] = max(1, min(12, (int) ($row['accordion_bg_border_width'] ?? 2)));
            $settings['created_at'] = (string) ($row['created_at'] ?? '');
            $settings['updated_at'] = (string) ($row['updated_at'] ?? '');
        }

        return $settings;
    }
}

if (!function_exists('daily_missions_fetch_tasks')) {
    function daily_missions_fetch_tasks(mysqli $mysqli, bool $onlyActive = true): array {
        daily_missions_ensure_schema();
        $sql = 'SELECT * FROM win_points_daily_mission_tasks';
        if ($onlyActive) {
            $sql .= ' WHERE active = 1';
        }
        $sql .= ' ORDER BY sort_order ASC, id ASC';

        $rows = daily_missions_db_query_assoc($mysqli, $sql);
        $tasks = [];
        foreach ($rows as $row) {
            $task = daily_missions_normalize_task_row($row);
            $tasks[$task['mission_key']] = $task;
        }

        return $tasks;
    }
}

if (!function_exists('daily_missions_fetch_task_by_key')) {
    function daily_missions_fetch_task_by_key(mysqli $mysqli, string $missionKey): ?array {
        $missionKey = trim($missionKey);
        if ($missionKey === '') {
            return null;
        }

        daily_missions_ensure_schema();
        $stmt = $mysqli->prepare('SELECT * FROM win_points_daily_mission_tasks WHERE mission_key = ? LIMIT 1');
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('s', $missionKey);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
        $stmt->close();

        return is_array($row) ? daily_missions_normalize_task_row($row) : null;
    }
}

if (!function_exists('daily_missions_fetch_prizes')) {
    function daily_missions_fetch_prizes(mysqli $mysqli, string $levelKey, bool $onlyActive = true): array {
        daily_missions_ensure_schema();
        $levelKey = trim($levelKey);
        if ($levelKey === '') {
            return [];
        }

        $sql = 'SELECT * FROM win_points_daily_mission_prizes WHERE level_key = ?';
        if ($onlyActive) {
            $sql .= ' AND active = 1';
        }
        $sql .= ' ORDER BY sort_order ASC, id ASC';

        $stmt = $mysqli->prepare($sql);
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('s', $levelKey);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        if ($result instanceof mysqli_result) {
            while ($row = $result->fetch_assoc()) {
                $rows[] = daily_missions_normalize_prize_row($row);
            }
        }
        $stmt->close();

        return $rows;
    }
}

if (!function_exists('daily_missions_ensure_user_state')) {
    function daily_missions_ensure_user_state(mysqli $mysqli, int $userId): void {
        if ($userId <= 0) {
            return;
        }

        daily_missions_ensure_schema();
        $stmt = $mysqli->prepare('INSERT IGNORE INTO win_points_daily_mission_state (user_id, current_streak_days, immunity_balance) VALUES (?, 0, 0)');
        if (!$stmt) {
            return;
        }
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $stmt->close();
    }
}

if (!function_exists('daily_missions_ensure_user_day')) {
    function daily_missions_ensure_user_day(mysqli $mysqli, int $userId, string $missionDate, int $requiredTasks): void {
        if ($userId <= 0 || $missionDate === '') {
            return;
        }

        daily_missions_ensure_schema();
        $stmt = $mysqli->prepare(
            'INSERT IGNORE INTO win_points_daily_mission_days (user_id, mission_date, required_tasks_count, last_event_at) VALUES (?, ?, ?, ?)'
        );
        if (!$stmt) {
            return;
        }
        $now = daily_missions_now();
        $stmt->bind_param('isis', $userId, $missionDate, $requiredTasks, $now);
        $stmt->execute();
        $stmt->close();
    }
}

if (!function_exists('daily_missions_fetch_user_state')) {
    function daily_missions_fetch_user_state(mysqli $mysqli, int $userId): array {
        daily_missions_ensure_schema();
        if ($userId <= 0) {
            return [
                'user_id' => 0,
                'current_streak_days' => 0,
                'last_completed_date' => null,
                'immunity_balance' => 0,
                'last_chest_claim_date' => null,
                'chest_rewards_total' => 0,
                'created_at' => null,
                'updated_at' => null,
            ];
        }

        daily_missions_ensure_user_state($mysqli, $userId);
        $stmt = $mysqli->prepare('SELECT * FROM win_points_daily_mission_state WHERE user_id = ? LIMIT 1');
        if (!$stmt) {
            return [
                'user_id' => $userId,
                'current_streak_days' => 0,
                'last_completed_date' => null,
                'immunity_balance' => 0,
                'last_chest_claim_date' => null,
                'chest_rewards_total' => 0,
                'created_at' => null,
                'updated_at' => null,
            ];
        }
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
        $stmt->close();

        return daily_missions_normalize_state_row(is_array($row) ? $row : []);
    }
}

if (!function_exists('daily_missions_fetch_user_day')) {
    function daily_missions_fetch_user_day(mysqli $mysqli, int $userId, ?string $missionDate = null): array {
        daily_missions_ensure_schema();
        if ($userId <= 0) {
            return daily_missions_normalize_day_row([]);
        }

        $resolvedDate = trim((string) ($missionDate ?? ''));
        if ($resolvedDate === '') {
            $resolvedDate = daily_missions_today();
        }
        $activeTasks = count(daily_missions_fetch_tasks($mysqli, true));
        daily_missions_ensure_user_day($mysqli, $userId, $resolvedDate, $activeTasks);

        $stmt = $mysqli->prepare('SELECT * FROM win_points_daily_mission_days WHERE user_id = ? AND mission_date = ? LIMIT 1');
        if (!$stmt) {
            return daily_missions_normalize_day_row([]);
        }
        $stmt->bind_param('is', $userId, $resolvedDate);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
        $stmt->close();

        return daily_missions_normalize_day_row(is_array($row) ? $row : []);
    }
}

if (!function_exists('daily_missions_fetch_user_history')) {
    function daily_missions_fetch_user_history(mysqli $mysqli, int $userId, int $limit = 60): array {
        daily_missions_ensure_schema();
        if ($userId <= 0) {
            return [];
        }

        $limit = max(1, min(200, $limit));
        $stmt = $mysqli->prepare(
            'SELECT * FROM win_points_daily_mission_rewards WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT ?'
        );
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('ii', $userId, $limit);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        if ($result instanceof mysqli_result) {
            while ($row = $result->fetch_assoc()) {
                $rows[] = daily_missions_normalize_reward_row($row);
            }
        }
        $stmt->close();

        return $rows;
    }
}

if (!function_exists('daily_missions_fetch_admin_history')) {
    function daily_missions_fetch_admin_history(mysqli $mysqli, int $userId = 0, int $limit = 100): array {
        daily_missions_ensure_schema();
        if ($userId <= 0) {
            return [];
        }

        $limit = max(1, min(300, $limit));
        $stmt = $mysqli->prepare(
            'SELECT r.*, u.nombre AS user_name, u.email AS user_email
             FROM win_points_daily_mission_rewards r
             LEFT JOIN usuarios u ON u.id = r.user_id
             WHERE r.user_id = ?
             ORDER BY r.created_at DESC, r.id DESC
             LIMIT ?'
        );
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('ii', $userId, $limit);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        if ($result instanceof mysqli_result) {
            while ($row = $result->fetch_assoc()) {
                $rows[] = daily_missions_normalize_reward_row($row);
            }
        }
        $stmt->close();

        return $rows;
    }
}

if (!function_exists('daily_missions_normalize_settings_row')) {
    function daily_missions_normalize_settings_row(array $row): array {
        $defaults = daily_missions_default_settings();

        return [
            'id' => (int) ($row['id'] ?? $defaults['id']),
            'enabled' => (int) ($row['enabled'] ?? $defaults['enabled']),
            'title' => trim((string) ($row['title'] ?? $defaults['title'])),
            'subtitle' => trim((string) ($row['subtitle'] ?? $defaults['subtitle'])),
            'active_task_goal' => max(1, (int) ($row['active_task_goal'] ?? $defaults['active_task_goal'])),
            'day20_multiplier' => max(0, (float) ($row['day20_multiplier'] ?? $defaults['day20_multiplier'])),
            'month_end_multiplier' => max(0, (float) ($row['month_end_multiplier'] ?? $defaults['month_end_multiplier'])),
            'explore_timer_seconds' => max(0, (int) ($row['explore_timer_seconds'] ?? $defaults['explore_timer_seconds'])),
            'share_timer_seconds' => max(0, (int) ($row['share_timer_seconds'] ?? $defaults['share_timer_seconds'])),
            'immunity_days' => max(0, (int) ($row['immunity_days'] ?? $defaults['immunity_days'])),
            'coupon_discount_percent' => max(0, min(100, (int) ($row['coupon_discount_percent'] ?? $defaults['coupon_discount_percent']))),
            'coupon_expiration_days' => max(1, (int) ($row['coupon_expiration_days'] ?? $defaults['coupon_expiration_days'])),
            'streaming_user_id' => (int) ($row['streaming_user_id'] ?? 0) > 0 ? (int) $row['streaming_user_id'] : null,
            'created_at' => trim((string) ($row['created_at'] ?? '')),
            'updated_at' => trim((string) ($row['updated_at'] ?? '')),
        ];
    }
}

if (!function_exists('daily_missions_normalize_task_row')) {
    function daily_missions_normalize_task_row(array $row): array {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'mission_key' => trim((string) ($row['mission_key'] ?? '')),
            'title' => trim((string) ($row['title'] ?? '')),
            'description' => trim((string) ($row['description'] ?? '')),
            'task_type' => trim((string) ($row['task_type'] ?? '')),
            'action_label' => trim((string) ($row['action_label'] ?? '')),
            'action_url' => trim((string) ($row['action_url'] ?? '')),
            'base_points' => max(0, (int) ($row['base_points'] ?? 0)),
            'timer_seconds' => max(0, (int) ($row['timer_seconds'] ?? 0)),
            'active' => (int) ($row['active'] ?? 0) === 1,
            'sort_order' => (int) ($row['sort_order'] ?? 0),
            'accent_color' => trim((string) ($row['accent_color'] ?? '')),
            'icon_label' => trim((string) ($row['icon_label'] ?? '')),
            'reward_reason' => trim((string) ($row['reward_reason'] ?? '')),
            'day20_multiplier' => isset($row['day20_multiplier']) && $row['day20_multiplier'] !== null ? (float) $row['day20_multiplier'] : null,
            'month_end_multiplier' => isset($row['month_end_multiplier']) && $row['month_end_multiplier'] !== null ? (float) $row['month_end_multiplier'] : null,
            'created_at' => trim((string) ($row['created_at'] ?? '')),
            'updated_at' => trim((string) ($row['updated_at'] ?? '')),
        ];
    }
}

if (!function_exists('daily_missions_normalize_prize_row')) {
    function daily_missions_normalize_prize_row(array $row): array {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'level_key' => trim((string) ($row['level_key'] ?? 'basic')),
            'prize_type' => trim((string) ($row['prize_type'] ?? 'winpoints')),
            'prize_label' => trim((string) ($row['prize_label'] ?? '')),
            'chance_percent' => max(0, (float) ($row['chance_percent'] ?? 0)),
            'points_amount' => max(0, (int) ($row['points_amount'] ?? 0)),
            'coupon_discount_percent' => max(0, min(100, (int) ($row['coupon_discount_percent'] ?? 0))),
            'immunity_days' => max(0, (int) ($row['immunity_days'] ?? 0)),
            'streaming_user_id' => (int) ($row['streaming_user_id'] ?? 0),
            'active' => (int) ($row['active'] ?? 0) === 1,
            'sort_order' => (int) ($row['sort_order'] ?? 0),
            'metadata_json' => trim((string) ($row['metadata_json'] ?? '')),
            'created_at' => trim((string) ($row['created_at'] ?? '')),
            'updated_at' => trim((string) ($row['updated_at'] ?? '')),
        ];
    }
}

if (!function_exists('daily_missions_normalize_state_row')) {
    function daily_missions_normalize_state_row(array $row): array {
        return [
            'user_id' => (int) ($row['user_id'] ?? 0),
            'current_streak_days' => max(0, (int) ($row['current_streak_days'] ?? 0)),
            'last_completed_date' => trim((string) ($row['last_completed_date'] ?? '')) ?: null,
            'immunity_balance' => max(0, (int) ($row['immunity_balance'] ?? 0)),
            'last_chest_claim_date' => trim((string) ($row['last_chest_claim_date'] ?? '')) ?: null,
            'chest_rewards_total' => max(0, (int) ($row['chest_rewards_total'] ?? 0)),
            'created_at' => trim((string) ($row['created_at'] ?? '')),
            'updated_at' => trim((string) ($row['updated_at'] ?? '')),
        ];
    }
}

if (!function_exists('daily_missions_normalize_day_row')) {
    function daily_missions_normalize_day_row(array $row): array {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'user_id' => (int) ($row['user_id'] ?? 0),
            'mission_date' => trim((string) ($row['mission_date'] ?? '')),
            'login_done' => (int) ($row['login_done'] ?? 0) === 1,
            'explore_done' => (int) ($row['explore_done'] ?? 0) === 1,
            'share_done' => (int) ($row['share_done'] ?? 0) === 1,
            'purchase_done' => (int) ($row['purchase_done'] ?? 0) === 1,
            'friend_done' => (int) ($row['friend_done'] ?? 0) === 1,
            'completed_tasks_count' => max(0, (int) ($row['completed_tasks_count'] ?? 0)),
            'required_tasks_count' => max(0, (int) ($row['required_tasks_count'] ?? 0)),
            'is_completed' => (int) ($row['is_completed'] ?? 0) === 1,
            'current_streak_days' => max(0, (int) ($row['current_streak_days'] ?? 0)),
            'chest_level' => trim((string) ($row['chest_level'] ?? 'basic')),
            'chest_claimed_at' => trim((string) ($row['chest_claimed_at'] ?? '')) ?: null,
            'chest_reward_id' => (int) ($row['chest_reward_id'] ?? 0) > 0 ? (int) $row['chest_reward_id'] : null,
            'immunity_consumed' => (int) ($row['immunity_consumed'] ?? 0) === 1,
            'last_event_at' => trim((string) ($row['last_event_at'] ?? '')) ?: null,
            'created_at' => trim((string) ($row['created_at'] ?? '')),
            'updated_at' => trim((string) ($row['updated_at'] ?? '')),
        ];
    }
}

if (!function_exists('daily_missions_normalize_reward_row')) {
    function daily_missions_normalize_reward_row(array $row): array {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'user_id' => (int) ($row['user_id'] ?? 0),
            'user_name' => trim((string) ($row['user_name'] ?? '')),
            'user_email' => trim((string) ($row['user_email'] ?? '')),
            'mission_date' => trim((string) ($row['mission_date'] ?? '')),
            'mission_key' => trim((string) ($row['mission_key'] ?? '')),
            'origin_type' => trim((string) ($row['origin_type'] ?? 'task')),
            'prize_type' => trim((string) ($row['prize_type'] ?? 'winpoints')),
            'prize_label' => trim((string) ($row['prize_label'] ?? '')),
            'reason' => trim((string) ($row['reason'] ?? '')),
            'reward_status' => trim((string) ($row['reward_status'] ?? 'pending')),
            'level_key' => trim((string) ($row['level_key'] ?? '')),
            'points_amount' => max(0, (int) ($row['points_amount'] ?? 0)),
            'coupon_code' => trim((string) ($row['coupon_code'] ?? '')),
            'coupon_discount_percent' => max(0, min(100, (int) ($row['coupon_discount_percent'] ?? 0))),
            'immunity_days' => max(0, (int) ($row['immunity_days'] ?? 0)),
            'streaming_user_id' => max(0, (int) ($row['streaming_user_id'] ?? 0)),
            'order_id' => (int) ($row['order_id'] ?? 0) > 0 ? (int) $row['order_id'] : null,
            'metadata_json' => trim((string) ($row['metadata_json'] ?? '')),
            'created_at' => trim((string) ($row['created_at'] ?? '')),
            'updated_at' => trim((string) ($row['updated_at'] ?? '')),
            'claimed_at' => trim((string) ($row['claimed_at'] ?? '')) ?: null,
            'resolved_at' => trim((string) ($row['resolved_at'] ?? '')) ?: null,
        ];
    }
}

if (!function_exists('daily_missions_ensure_schema')) {
    function daily_missions_ensure_schema(): void {
        static $initialized = false;

        if ($initialized) {
            return;
        }

        $mysqli = daily_missions_db();

        $mysqli->query(
            "CREATE TABLE IF NOT EXISTS win_points_daily_mission_settings (
                id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
                enabled TINYINT(1) NOT NULL DEFAULT 1,
                title VARCHAR(120) NOT NULL DEFAULT 'Mision diaria',
                subtitle VARCHAR(255) NOT NULL DEFAULT 'Completa las tareas, abre el cofre y gana Win Points.',
                active_task_goal INT NOT NULL DEFAULT 4,
                day20_multiplier DECIMAL(8,2) NOT NULL DEFAULT 4.00,
                month_end_multiplier DECIMAL(8,2) NOT NULL DEFAULT 10.00,
                explore_timer_seconds INT NOT NULL DEFAULT 7,
                share_timer_seconds INT NOT NULL DEFAULT 15,
                immunity_days INT NOT NULL DEFAULT 1,
                coupon_discount_percent INT NOT NULL DEFAULT 10,
                coupon_expiration_days INT NOT NULL DEFAULT 30,
                streaming_user_id INT NULL DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $mysqli->query(
            "CREATE TABLE IF NOT EXISTS win_points_daily_mission_tasks (
                id INT AUTO_INCREMENT PRIMARY KEY,
                mission_key VARCHAR(60) NOT NULL,
                title VARCHAR(120) NOT NULL,
                description VARCHAR(255) NOT NULL,
                task_type VARCHAR(30) NOT NULL,
                action_label VARCHAR(120) NOT NULL,
                action_url VARCHAR(255) DEFAULT NULL,
                base_points INT NOT NULL DEFAULT 0,
                timer_seconds INT NOT NULL DEFAULT 0,
                active TINYINT(1) NOT NULL DEFAULT 1,
                sort_order INT NOT NULL DEFAULT 0,
                accent_color VARCHAR(20) DEFAULT NULL,
                icon_label VARCHAR(20) DEFAULT NULL,
                reward_reason VARCHAR(160) DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_win_points_daily_mission_task_key (mission_key),
                INDEX idx_win_points_daily_mission_tasks_active (active, sort_order)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        // ADD COLUMN IF NOT EXISTS no está disponible en MySQL 5.x — verificar via INFORMATION_SCHEMA
        $dbName = $mysqli->query("SELECT DATABASE()")->fetch_row()[0];

        // Accordion color columns for win_points_daily_mission_settings
        $settingsColCheck = $mysqli->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA='$dbName' AND TABLE_NAME='win_points_daily_mission_settings' AND COLUMN_NAME IN ('accordion_base_color1','accordion_base_color2','accordion_bg_color1','accordion_bg_color2')");
        $existingSettingsCols = [];
        while ($sColRow = $settingsColCheck->fetch_row()) { $existingSettingsCols[] = $sColRow[0]; }
        if (!in_array('accordion_base_color1', $existingSettingsCols)) {
            $mysqli->query("ALTER TABLE win_points_daily_mission_settings ADD COLUMN accordion_base_color1 VARCHAR(30) NULL DEFAULT NULL");
        }
        if (!in_array('accordion_base_color2', $existingSettingsCols)) {
            $mysqli->query("ALTER TABLE win_points_daily_mission_settings ADD COLUMN accordion_base_color2 VARCHAR(30) NULL DEFAULT NULL");
        }
        if (!in_array('accordion_bg_color1', $existingSettingsCols)) {
            $mysqli->query("ALTER TABLE win_points_daily_mission_settings ADD COLUMN accordion_bg_color1 VARCHAR(30) NULL DEFAULT NULL");
        }
        if (!in_array('accordion_bg_color2', $existingSettingsCols)) {
            $mysqli->query("ALTER TABLE win_points_daily_mission_settings ADD COLUMN accordion_bg_color2 VARCHAR(30) NULL DEFAULT NULL");
        }

        // Neon border columns for win_points_daily_mission_settings
        $borderColCheck = $mysqli->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA='$dbName' AND TABLE_NAME='win_points_daily_mission_settings' AND COLUMN_NAME IN ('accordion_base_border_enabled','accordion_base_border_color','accordion_base_border_width','accordion_bg_border_enabled','accordion_bg_border_color','accordion_bg_border_width')");
        $existingBorderCols = [];
        while ($bColRow = $borderColCheck->fetch_row()) { $existingBorderCols[] = $bColRow[0]; }
        if (!in_array('accordion_base_border_enabled', $existingBorderCols)) {
            $mysqli->query("ALTER TABLE win_points_daily_mission_settings ADD COLUMN accordion_base_border_enabled TINYINT(1) NOT NULL DEFAULT 0");
        }
        if (!in_array('accordion_base_border_color', $existingBorderCols)) {
            $mysqli->query("ALTER TABLE win_points_daily_mission_settings ADD COLUMN accordion_base_border_color VARCHAR(30) NULL DEFAULT NULL");
        }
        if (!in_array('accordion_base_border_width', $existingBorderCols)) {
            $mysqli->query("ALTER TABLE win_points_daily_mission_settings ADD COLUMN accordion_base_border_width TINYINT NOT NULL DEFAULT 2");
        }
        if (!in_array('accordion_bg_border_enabled', $existingBorderCols)) {
            $mysqli->query("ALTER TABLE win_points_daily_mission_settings ADD COLUMN accordion_bg_border_enabled TINYINT(1) NOT NULL DEFAULT 0");
        }
        if (!in_array('accordion_bg_border_color', $existingBorderCols)) {
            $mysqli->query("ALTER TABLE win_points_daily_mission_settings ADD COLUMN accordion_bg_border_color VARCHAR(30) NULL DEFAULT NULL");
        }
        if (!in_array('accordion_bg_border_width', $existingBorderCols)) {
            $mysqli->query("ALTER TABLE win_points_daily_mission_settings ADD COLUMN accordion_bg_border_width TINYINT NOT NULL DEFAULT 2");
        }

        $colCheck = $mysqli->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA='$dbName' AND TABLE_NAME='win_points_daily_mission_tasks' AND COLUMN_NAME IN ('day20_multiplier','month_end_multiplier')");
        $existingCols = [];
        while ($colRow = $colCheck->fetch_row()) { $existingCols[] = $colRow[0]; }
        if (!in_array('day20_multiplier', $existingCols)) {
            $mysqli->query("ALTER TABLE win_points_daily_mission_tasks ADD COLUMN day20_multiplier DECIMAL(8,2) NULL DEFAULT NULL");
        }
        if (!in_array('month_end_multiplier', $existingCols)) {
            $mysqli->query("ALTER TABLE win_points_daily_mission_tasks ADD COLUMN month_end_multiplier DECIMAL(8,2) NULL DEFAULT NULL");
        }

        $mysqli->query(
            "CREATE TABLE IF NOT EXISTS win_points_daily_mission_prizes (
                id INT AUTO_INCREMENT PRIMARY KEY,
                level_key VARCHAR(20) NOT NULL,
                prize_type VARCHAR(30) NOT NULL,
                prize_label VARCHAR(120) NOT NULL,
                chance_percent DECIMAL(8,2) NOT NULL DEFAULT 0,
                points_amount INT NOT NULL DEFAULT 0,
                coupon_discount_percent INT NOT NULL DEFAULT 0,
                immunity_days INT NOT NULL DEFAULT 0,
                streaming_user_id INT NULL DEFAULT NULL,
                active TINYINT(1) NOT NULL DEFAULT 1,
                sort_order INT NOT NULL DEFAULT 0,
                metadata_json LONGTEXT DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_win_points_daily_mission_prizes_level (level_key, active, sort_order)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $mysqli->query(
            "CREATE TABLE IF NOT EXISTS win_points_daily_mission_state (
                user_id INT NOT NULL PRIMARY KEY,
                current_streak_days INT NOT NULL DEFAULT 0,
                last_completed_date DATE DEFAULT NULL,
                immunity_balance INT NOT NULL DEFAULT 0,
                last_chest_claim_date DATE DEFAULT NULL,
                chest_rewards_total INT NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_win_points_daily_mission_state_last_completed (last_completed_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $mysqli->query(
            "CREATE TABLE IF NOT EXISTS win_points_daily_mission_days (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                mission_date DATE NOT NULL,
                login_done TINYINT(1) NOT NULL DEFAULT 0,
                explore_done TINYINT(1) NOT NULL DEFAULT 0,
                share_done TINYINT(1) NOT NULL DEFAULT 0,
                purchase_done TINYINT(1) NOT NULL DEFAULT 0,
                friend_done TINYINT(1) NOT NULL DEFAULT 0,
                completed_tasks_count INT NOT NULL DEFAULT 0,
                required_tasks_count INT NOT NULL DEFAULT 4,
                is_completed TINYINT(1) NOT NULL DEFAULT 0,
                current_streak_days INT NOT NULL DEFAULT 0,
                chest_level VARCHAR(20) NOT NULL DEFAULT 'basic',
                chest_claimed_at DATETIME DEFAULT NULL,
                chest_reward_id INT DEFAULT NULL,
                immunity_consumed TINYINT(1) NOT NULL DEFAULT 0,
                last_event_at DATETIME DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_win_points_daily_mission_day (user_id, mission_date),
                INDEX idx_win_points_daily_mission_day_user (user_id, mission_date),
                INDEX idx_win_points_daily_mission_day_completed (mission_date, is_completed)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $mysqli->query(
            "CREATE TABLE IF NOT EXISTS win_points_daily_mission_rewards (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                mission_date DATE DEFAULT NULL,
                mission_key VARCHAR(60) DEFAULT NULL,
                origin_type VARCHAR(30) NOT NULL,
                prize_type VARCHAR(30) NOT NULL,
                prize_label VARCHAR(120) NOT NULL,
                reason VARCHAR(255) NOT NULL,
                reward_status VARCHAR(20) NOT NULL DEFAULT 'pending',
                level_key VARCHAR(20) DEFAULT NULL,
                points_amount INT NOT NULL DEFAULT 0,
                coupon_code VARCHAR(60) DEFAULT NULL,
                coupon_discount_percent INT NOT NULL DEFAULT 0,
                immunity_days INT NOT NULL DEFAULT 0,
                streaming_user_id INT NOT NULL DEFAULT 0,
                order_id INT DEFAULT NULL,
                metadata_json LONGTEXT DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                claimed_at DATETIME DEFAULT NULL,
                resolved_at DATETIME DEFAULT NULL,
                INDEX idx_win_points_daily_mission_rewards_user (user_id, created_at),
                INDEX idx_win_points_daily_mission_rewards_status (reward_status, created_at),
                INDEX idx_win_points_daily_mission_rewards_origin (origin_type, prize_type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $mysqli->query(
            "INSERT IGNORE INTO win_points_daily_mission_settings (
                id,
                enabled,
                title,
                subtitle,
                active_task_goal,
                day20_multiplier,
                month_end_multiplier,
                explore_timer_seconds,
                share_timer_seconds,
                immunity_days,
                coupon_discount_percent,
                coupon_expiration_days,
                streaming_user_id
            ) VALUES (
                1,
                1,
                'Mision diaria',
                'Completa las tareas, abre el cofre y gana Win Points.',
                4,
                4.00,
                10.00,
                7,
                15,
                1,
                10,
                30,
                NULL
            )"
        );

        foreach (daily_missions_default_tasks() as $task) {
            $missionKey = addslashes((string) $task['mission_key']);
            $title = addslashes((string) $task['title']);
            $description = addslashes((string) $task['description']);
            $taskType = addslashes((string) $task['task_type']);
            $actionLabel = addslashes((string) $task['action_label']);
            $actionUrl = addslashes((string) $task['action_url']);
            $basePoints = (int) $task['base_points'];
            $timerSeconds = (int) $task['timer_seconds'];
            $active = (int) $task['active'];
            $sortOrder = (int) $task['sort_order'];
            $accentColor = addslashes((string) $task['accent_color']);
            $iconLabel = addslashes((string) $task['icon_label']);
            $rewardReason = addslashes((string) $task['reward_reason']);

            $mysqli->query(
                "INSERT IGNORE INTO win_points_daily_mission_tasks (
                    mission_key,
                    title,
                    description,
                    task_type,
                    action_label,
                    action_url,
                    base_points,
                    timer_seconds,
                    active,
                    sort_order,
                    accent_color,
                    icon_label,
                    reward_reason
                ) VALUES (
                    '{$missionKey}',
                    '{$title}',
                    '{$description}',
                    '{$taskType}',
                    '{$actionLabel}',
                    '{$actionUrl}',
                    {$basePoints},
                    {$timerSeconds},
                    {$active},
                    {$sortOrder},
                    '{$accentColor}',
                    '{$iconLabel}',
                    '{$rewardReason}'
                )"
            );
        }

        // Limpiar filas duplicadas de premios si existen (bug de versiones anteriores sin UNIQUE constraint)
        $prizeCount = (int) ($mysqli->query("SELECT COUNT(*) FROM win_points_daily_mission_prizes")->fetch_row()[0] ?? 0);
        if ($prizeCount > 12) {
            $mysqli->query(
                "DELETE p1 FROM win_points_daily_mission_prizes p1
                 INNER JOIN win_points_daily_mission_prizes p2
                 ON p1.level_key = p2.level_key AND p1.prize_type = p2.prize_type AND p1.id < p2.id"
            );
            $prizeCount = (int) ($mysqli->query("SELECT COUNT(*) FROM win_points_daily_mission_prizes")->fetch_row()[0] ?? 0);
        }
        // Sembrar premios por defecto solo si la tabla está vacía
        if ($prizeCount === 0) {
            foreach (daily_missions_default_prizes() as $prize) {
                $levelKey = addslashes((string) $prize['level_key']);
                $prizeType = addslashes((string) $prize['prize_type']);
                $prizeLabel = addslashes((string) $prize['prize_label']);
                $chancePercent = (float) $prize['chance_percent'];
                $pointsAmount = (int) $prize['points_amount'];
                $couponDiscountPercent = (int) $prize['coupon_discount_percent'];
                $immunityDays = (int) $prize['immunity_days'];
                $sortOrder = (int) $prize['sort_order'];

                $mysqli->query(
                    "INSERT INTO win_points_daily_mission_prizes (
                        level_key, prize_type, prize_label,
                        chance_percent, points_amount, coupon_discount_percent,
                        immunity_days, streaming_user_id, active, sort_order
                    ) VALUES (
                        '{$levelKey}', '{$prizeType}', '{$prizeLabel}',
                        {$chancePercent}, {$pointsAmount}, {$couponDiscountPercent},
                        {$immunityDays}, NULL, 1, {$sortOrder}
                    )"
                );
            }
        }

        $initialized = true;
    }
}

if (!function_exists('daily_missions_enabled')) {
    function daily_missions_enabled(): bool {
        $settings = daily_missions_fetch_settings(daily_missions_db());

        return (int) ($settings['enabled'] ?? 0) === 1;
    }
}

if (!function_exists('daily_missions_resolve_next_streak_context')) {
    function daily_missions_resolve_next_streak_context(array $state, string $missionDate): array {
        $currentStreak = max(0, (int) ($state['current_streak_days'] ?? 0));
        $lastCompletedDate = trim((string) ($state['last_completed_date'] ?? ''));
        $immunityBalance = max(0, (int) ($state['immunity_balance'] ?? 0));

        if ($lastCompletedDate === '') {
            return [
                'streak' => 1,
                'gap_days' => null,
                'consume_immunity' => false,
                'reset' => true,
            ];
        }

        $gapDays = daily_missions_date_diff_days($lastCompletedDate, $missionDate);
        if ($gapDays === 0) {
            return [
                'streak' => $currentStreak,
                'gap_days' => 0,
                'consume_immunity' => false,
                'reset' => false,
            ];
        }

        if ($gapDays === 1) {
            return [
                'streak' => max(1, $currentStreak + 1),
                'gap_days' => 1,
                'consume_immunity' => false,
                'reset' => false,
            ];
        }

        if ($gapDays === 2 && $immunityBalance > 0) {
            return [
                'streak' => max(1, $currentStreak + 1),
                'gap_days' => 2,
                'consume_immunity' => true,
                'reset' => false,
            ];
        }

        return [
            'streak' => 1,
            'gap_days' => $gapDays,
            'consume_immunity' => false,
            'reset' => true,
        ];
    }
}

if (!function_exists('daily_missions_resolve_level_key')) {
    function daily_missions_resolve_level_key(int $streakDays, string $missionDate): string {
        if (daily_missions_is_month_end($missionDate)) {
            return 'legendary';
        }

        if ($streakDays >= 20) {
            return 'intermediate';
        }

        return 'basic';
    }
}

if (!function_exists('daily_missions_resolve_task_multiplier')) {
    function daily_missions_resolve_task_multiplier(array $settings, int $streakDays, string $missionDate, array $task = []): float {
        if (daily_missions_is_month_end($missionDate)) {
            $taskMult = isset($task['month_end_multiplier']) && $task['month_end_multiplier'] !== null ? (float) $task['month_end_multiplier'] : null;
            return $taskMult !== null ? max(0.0, $taskMult) : max(0.0, (float) ($settings['month_end_multiplier'] ?? 1));
        }

        if ($streakDays === 20) {
            $taskMult = isset($task['day20_multiplier']) && $task['day20_multiplier'] !== null ? (float) $task['day20_multiplier'] : null;
            return $taskMult !== null ? max(0.0, $taskMult) : max(0.0, (float) ($settings['day20_multiplier'] ?? 1));
        }

        return 1.0;
    }
}

if (!function_exists('daily_missions_calculate_reward_points')) {
    function daily_missions_calculate_reward_points(array $task, array $settings, int $streakDays, string $missionDate): int {
        $basePoints = max(0, (int) ($task['base_points'] ?? 0));
        $multiplier = daily_missions_resolve_task_multiplier($settings, $streakDays, $missionDate, $task);

        return (int) round($basePoints * $multiplier);
    }
}

if (!function_exists('daily_missions_random_code')) {
    function daily_missions_random_code(string $prefix = 'DM'): string {
        try {
            $token = strtoupper(bin2hex(random_bytes(4)));
        } catch (Throwable $exception) {
            $token = strtoupper(substr(sha1((string) microtime(true) . mt_rand()), 0, 8));
        }

        return $prefix . '-' . date('ymd') . '-' . $token;
    }
}

if (!function_exists('daily_missions_register_reward_row')) {
    function daily_missions_register_reward_row(mysqli $mysqli, array $payload): array {
        $metadata = $payload['metadata_json'] ?? [];
        if (!is_string($metadata)) {
            $metadataJson = json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $metadata = is_string($metadataJson) ? $metadataJson : '{}';
        }

        $stmt = $mysqli->prepare(
            'INSERT INTO win_points_daily_mission_rewards (
                user_id,
                mission_date,
                mission_key,
                origin_type,
                prize_type,
                prize_label,
                reason,
                reward_status,
                level_key,
                points_amount,
                coupon_code,
                coupon_discount_percent,
                immunity_days,
                streaming_user_id,
                order_id,
                metadata_json,
                claimed_at,
                resolved_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        if (!$stmt) {
            throw new RuntimeException('No se pudo registrar el historial de misiones.');
        }

        $userId = (int) ($payload['user_id'] ?? 0);
        $missionDate = trim((string) ($payload['mission_date'] ?? ''));
        $missionKey = trim((string) ($payload['mission_key'] ?? ''));
        $originType = trim((string) ($payload['origin_type'] ?? 'task'));
        $prizeType = trim((string) ($payload['prize_type'] ?? 'winpoints'));
        $prizeLabel = trim((string) ($payload['prize_label'] ?? ''));
        $reason = trim((string) ($payload['reason'] ?? ''));
        $rewardStatus = trim((string) ($payload['reward_status'] ?? 'pending'));
        $levelKey = trim((string) ($payload['level_key'] ?? ''));
        $pointsAmount = (int) ($payload['points_amount'] ?? 0);
        $couponCode = trim((string) ($payload['coupon_code'] ?? ''));
        $couponDiscountPercent = (int) ($payload['coupon_discount_percent'] ?? 0);
        $immunityDays = (int) ($payload['immunity_days'] ?? 0);
        $streamingUserId = (int) ($payload['streaming_user_id'] ?? 0);
        $orderId = isset($payload['order_id']) && (int) $payload['order_id'] > 0 ? (int) $payload['order_id'] : null;
        $claimedAt = trim((string) ($payload['claimed_at'] ?? ''));
        $resolvedAt = trim((string) ($payload['resolved_at'] ?? daily_missions_now()));

        $orderIdValue = $orderId ?? 0;
        $claimedAtValue = $claimedAt !== '' ? $claimedAt : null;

        $stmt->bind_param(
            'isssssssissiiissss',
            $userId,
            $missionDate,
            $missionKey,
            $originType,
            $prizeType,
            $prizeLabel,
            $reason,
            $rewardStatus,
            $levelKey,
            $pointsAmount,
            $couponCode,
            $couponDiscountPercent,
            $immunityDays,
            $streamingUserId,
            $orderIdValue,
            $metadata,
            $claimedAtValue,
            $resolvedAt
        );
        $stmt->execute();
        $rewardId = (int) $stmt->insert_id;
        $stmt->close();

        return [
            'id' => $rewardId,
            'user_id' => $userId,
            'mission_date' => $missionDate,
            'mission_key' => $missionKey,
            'origin_type' => $originType,
            'prize_type' => $prizeType,
            'prize_label' => $prizeLabel,
            'reason' => $reason,
            'reward_status' => $rewardStatus,
            'level_key' => $levelKey,
            'points_amount' => $pointsAmount,
            'coupon_code' => $couponCode,
            'coupon_discount_percent' => $couponDiscountPercent,
            'immunity_days' => $immunityDays,
            'streaming_user_id' => $streamingUserId,
            'order_id' => $orderId,
            'metadata_json' => $metadata,
            'claimed_at' => $claimedAtValue,
            'resolved_at' => $resolvedAt,
        ];
    }
}

if (!function_exists('daily_missions_issue_coupon')) {
    function daily_missions_issue_coupon(mysqli $mysqli, int $discountPercent, int $expirationDays): array {
        $discountPercent = max(1, min(100, $discountPercent));
        $expirationDays = max(1, $expirationDays);

        $tableResult = $mysqli->query("SHOW TABLES LIKE 'cupones'");
        if (!($tableResult instanceof mysqli_result) || $tableResult->num_rows === 0) {
            return [
                'coupon_code' => daily_missions_random_code('CPN'),
                'inserted' => false,
            ];
        }

        $expirationAt = date('Y-m-d H:i:s', strtotime('+' . $expirationDays . ' days'));
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $code = daily_missions_random_code('CPN');
            $stmt = $mysqli->prepare('SELECT id FROM cupones WHERE codigo = ? LIMIT 1');
            if (!$stmt) {
                break;
            }
            $stmt->bind_param('s', $code);
            $stmt->execute();
            $result = $stmt->get_result();
            $existing = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
            $stmt->close();
            if ($existing) {
                continue;
            }

            $insertStmt = $mysqli->prepare(
                'INSERT INTO cupones (codigo, tipo_descuento, valor_descuento, fecha_expiracion, limite_usos, activo) VALUES (?, ?, ?, ?, ?, 1)'
            );
            if (!$insertStmt) {
                break;
            }
            $discountType = 'porcentaje';
            $limitUses = 1;
            $insertStmt->bind_param('ssdsi', $code, $discountType, $discountPercent, $expirationAt, $limitUses);
            $insertStmt->execute();
            $insertId = (int) $insertStmt->insert_id;
            $insertStmt->close();

            return [
                'coupon_code' => $code,
                'coupon_id' => $insertId,
                'inserted' => true,
                'expiration_at' => $expirationAt,
            ];
        }

        return [
            'coupon_code' => daily_missions_random_code('CPN'),
            'inserted' => false,
        ];
    }
}

if (!function_exists('daily_missions_apply_task_reward')) {
    function daily_missions_apply_task_reward(mysqli $mysqli, int $userId, array $task, string $missionDate, int $streakDays, array $settings): array {
        $pointsAmount = daily_missions_calculate_reward_points($task, $settings, $streakDays, $missionDate);
        $reason = trim((string) ($task['reward_reason'] ?? ''));
        if ($reason === '') {
            $reason = 'Recompensa por ' . daily_missions_task_type_label((string) ($task['task_type'] ?? 'task'));
        }

        $metadata = [
            'origin' => 'daily_mission',
            'mission_key' => $task['mission_key'] ?? '',
            'mission_date' => $missionDate,
            'streak_days' => $streakDays,
            'multiplier' => daily_missions_resolve_task_multiplier($settings, $streakDays, $missionDate),
            'task_type' => $task['task_type'] ?? '',
            'base_points' => (int) ($task['base_points'] ?? 0),
        ];

        if ($pointsAmount > 0 && function_exists('win_points_record_transaction')) {
            win_points_record_transaction(
                $mysqli,
                $userId,
                $pointsAmount,
                'daily_mission_task',
                $reason,
                null,
                null,
                null,
                null,
                $metadata
            );
        }

        return daily_missions_register_reward_row($mysqli, [
            'user_id' => $userId,
            'mission_date' => $missionDate,
            'mission_key' => (string) ($task['mission_key'] ?? ''),
            'origin_type' => 'task',
            'prize_type' => 'winpoints',
            'prize_label' => 'Win Points',
            'reason' => $reason,
            'reward_status' => 'granted',
            'level_key' => daily_missions_resolve_level_key($streakDays, $missionDate),
            'points_amount' => $pointsAmount,
            'coupon_code' => '',
            'coupon_discount_percent' => 0,
            'immunity_days' => 0,
            'streaming_user_id' => 0,
            'order_id' => null,
            'metadata_json' => $metadata,
            'claimed_at' => daily_missions_now(),
            'resolved_at' => daily_missions_now(),
        ]);
    }
}

if (!function_exists('daily_missions_consume_immunity_if_needed')) {
    function daily_missions_consume_immunity_if_needed(mysqli $mysqli, int $userId): void {
        if ($userId <= 0) {
            return;
        }

        $stmt = $mysqli->prepare('UPDATE win_points_daily_mission_state SET immunity_balance = GREATEST(immunity_balance - 1, 0) WHERE user_id = ? AND immunity_balance > 0');
        if (!$stmt) {
            return;
        }
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $stmt->close();
    }
}

if (!function_exists('daily_missions_touch_day_row')) {
    function daily_missions_touch_day_row(mysqli $mysqli, int $userId, string $missionDate, array $updates): void {
        if ($userId <= 0 || $missionDate === '' || empty($updates)) {
            return;
        }

        $assignments = [];
        $types = '';
        $values = [];
        foreach ($updates as $column => $value) {
            $assignments[] = $column . ' = ?';
            if (is_int($value)) {
                $types .= 'i';
                $values[] = $value;
            } elseif (is_float($value)) {
                $types .= 'd';
                $values[] = $value;
            } else {
                $types .= 's';
                $values[] = (string) $value;
            }
        }

        $types .= 'is';
        $values[] = $userId;
        $values[] = $missionDate;
        $sql = 'UPDATE win_points_daily_mission_days SET ' . implode(', ', $assignments) . ' WHERE user_id = ? AND mission_date = ?';
        $stmt = $mysqli->prepare($sql);
        if (!$stmt) {
            return;
        }

        daily_missions_bind_param_array($stmt, $types, $values);
        $stmt->execute();
        $stmt->close();
    }
}

if (!function_exists('daily_missions_record_task_completion')) {
    function daily_missions_record_task_completion(mysqli $mysqli, int $userId, string $missionKey, array $context = []): array {
        daily_missions_ensure_schema();
        $missionKey = trim($missionKey);
        if ($userId <= 0 || $missionKey === '') {
            return [
                'ok' => false,
                'message' => 'Usuario o mision invalida.',
            ];
        }

        $task = daily_missions_fetch_task_by_key($mysqli, $missionKey);
        if (!$task || empty($task['active'])) {
            return [
                'ok' => false,
                'message' => 'La tarea no esta activa.',
            ];
        }

        $settings = daily_missions_fetch_settings($mysqli);
        $missionDate = trim((string) ($context['mission_date'] ?? ''));
        if ($missionDate === '') {
            $missionDate = daily_missions_today();
        }

        $activeTasks = count(daily_missions_fetch_tasks($mysqli, true));
        if ($activeTasks <= 0) {
            $activeTasks = max(1, (int) ($settings['active_task_goal'] ?? 4));
        }

        $flagColumn = daily_missions_task_flag_column($missionKey);
        $notes = $context['notes'] ?? [];
        if (!is_array($notes)) {
            $notes = ['value' => (string) $notes];
        }

        $mysqli->begin_transaction();
        try {
            daily_missions_ensure_user_state($mysqli, $userId);
            daily_missions_ensure_user_day($mysqli, $userId, $missionDate, $activeTasks);

            $stateStmt = $mysqli->prepare('SELECT * FROM win_points_daily_mission_state WHERE user_id = ? LIMIT 1 FOR UPDATE');
            if (!$stateStmt) {
                throw new RuntimeException('No se pudo bloquear el estado de misiones.');
            }
            $stateStmt->bind_param('i', $userId);
            $stateStmt->execute();
            $stateResult = $stateStmt->get_result();
            $stateRow = $stateResult instanceof mysqli_result ? $stateResult->fetch_assoc() : null;
            $stateStmt->close();
            $state = daily_missions_normalize_state_row(is_array($stateRow) ? $stateRow : []);

            $dayStmt = $mysqli->prepare('SELECT * FROM win_points_daily_mission_days WHERE user_id = ? AND mission_date = ? LIMIT 1 FOR UPDATE');
            if (!$dayStmt) {
                throw new RuntimeException('No se pudo bloquear el progreso diario.');
            }
            $dayStmt->bind_param('is', $userId, $missionDate);
            $dayStmt->execute();
            $dayResult = $dayStmt->get_result();
            $dayRow = $dayResult instanceof mysqli_result ? $dayResult->fetch_assoc() : null;
            $dayStmt->close();

            $day = daily_missions_normalize_day_row(is_array($dayRow) ? $dayRow : []);
            if (($day[$flagColumn] ?? false) === true) {
                $mysqli->commit();
                return [
                    'ok' => true,
                    'already_completed' => true,
                    'mission_key' => $missionKey,
                    'day' => $day,
                    'state' => $state,
                    'message' => 'La tarea ya estaba completada.',
                ];
            }

            $streakContext = daily_missions_resolve_next_streak_context($state, $missionDate);
            $provisionalStreak = max(1, (int) ($streakContext['streak'] ?? 1));
            $reward = daily_missions_apply_task_reward($mysqli, $userId, $task, $missionDate, $provisionalStreak, $settings);

            $completedTasksCount = max(0, (int) ($day['completed_tasks_count'] ?? 0)) + 1;
            $isCompleted = $completedTasksCount >= $activeTasks;
            $currentLevel = daily_missions_resolve_level_key($provisionalStreak, $missionDate);

            $updates = [
                $flagColumn => 1,
                'completed_tasks_count' => $completedTasksCount,
                'required_tasks_count' => $activeTasks,
                'current_streak_days' => $provisionalStreak,
                'chest_level' => $currentLevel,
                'last_event_at' => daily_missions_now(),
            ];
            if ($isCompleted) {
                $updates['is_completed'] = 1;
            }
            daily_missions_touch_day_row($mysqli, $userId, $missionDate, $updates);

            if ($isCompleted) {
                $stateUpdates = [
                    'current_streak_days' => $provisionalStreak,
                    'last_completed_date' => $missionDate,
                ];
                if (!empty($streakContext['consume_immunity'])) {
                    daily_missions_consume_immunity_if_needed($mysqli, $userId);
                    $stateUpdates['updated_at'] = daily_missions_now();
                    daily_missions_touch_state_row($mysqli, $userId, $stateUpdates);
                    daily_missions_touch_day_row($mysqli, $userId, $missionDate, [
                        'immunity_consumed' => 1,
                    ]);
                } else {
                    daily_missions_touch_state_row($mysqli, $userId, $stateUpdates);
                }
            }

            $day = daily_missions_fetch_user_day($mysqli, $userId, $missionDate);
            $state = daily_missions_fetch_user_state($mysqli, $userId);

            $mysqli->commit();

            return [
                'ok' => true,
                'mission_key' => $missionKey,
                'task' => $task,
                'day' => $day,
                'state' => $state,
                'reward' => $reward,
                'reward_points' => (int) ($reward['points_amount'] ?? 0),
                'streak_days' => $provisionalStreak,
                'level_key' => $currentLevel,
                'day_completed' => $isCompleted,
                'chest_unlocked' => $isCompleted,
                'message' => $isCompleted ? 'Tarea completada y cofre desbloqueado.' : 'Tarea completada.',
                'notes' => $notes,
            ];
        } catch (Throwable $exception) {
            $mysqli->rollback();

            return [
                'ok' => false,
                'message' => $exception->getMessage() !== '' ? $exception->getMessage() : 'No se pudo registrar la tarea.',
            ];
        }
    }
}

if (!function_exists('daily_missions_mark_purchase_for_order')) {
    /* Marca la tarea diaria "Realiza una compra" (purchase_any) para el usuario
       dueño de un pedido ya pagado/verificado. Es idempotente (si ya estaba
       completada no hace nada) y nunca interrumpe el flujo de compra. Debe
       llamarse fuera de una transacción abierta, porque abre la suya propia. */
    function daily_missions_mark_purchase_for_order(mysqli $mysqli, int $orderId, ?int $userId = null): void {
        if ($orderId <= 0 || !daily_missions_enabled()) {
            return;
        }

        try {
            if ($userId === null) {
                $stmt = $mysqli->prepare('SELECT cliente_usuario_id FROM pedidos WHERE id = ? LIMIT 1');
                if (!$stmt) {
                    return;
                }
                $stmt->bind_param('i', $orderId);
                $stmt->execute();
                $result = $stmt->get_result();
                $row = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
                $stmt->close();
                $userId = (int) ($row['cliente_usuario_id'] ?? 0);
            }

            if ($userId <= 0) {
                return;
            }

            daily_missions_record_task_completion($mysqli, $userId, 'purchase_any', [
                'order_id' => $orderId,
                'source'   => 'purchase_verified',
            ]);
        } catch (Throwable $exception) {
            error_log('daily_missions_mark_purchase_for_order error: ' . $exception->getMessage());
        }
    }
}

if (!function_exists('daily_missions_touch_state_row')) {
    function daily_missions_touch_state_row(mysqli $mysqli, int $userId, array $updates): void {
        if ($userId <= 0 || empty($updates)) {
            return;
        }

        $assignments = [];
        $types = '';
        $values = [];
        foreach ($updates as $column => $value) {
            $assignments[] = $column . ' = ?';
            if (is_int($value)) {
                $types .= 'i';
                $values[] = $value;
            } elseif (is_float($value)) {
                $types .= 'd';
                $values[] = $value;
            } else {
                $types .= 's';
                $values[] = (string) $value;
            }
        }

        $types .= 'i';
        $values[] = $userId;
        $sql = 'UPDATE win_points_daily_mission_state SET ' . implode(', ', $assignments) . ' WHERE user_id = ?';
        $stmt = $mysqli->prepare($sql);
        if (!$stmt) {
            return;
        }

        daily_missions_bind_param_array($stmt, $types, $values);
        $stmt->execute();
        $stmt->close();
    }
}

if (!function_exists('daily_missions_bind_param_array')) {
    function daily_missions_bind_param_array(mysqli_stmt $stmt, string $types, array $values): void {
        $references = [$types];
        foreach ($values as $index => $value) {
            $references[$index + 1] = &$values[$index];
        }

        call_user_func_array([$stmt, 'bind_param'], $references);
    }
}

if (!function_exists('daily_missions_pick_prize')) {
    function daily_missions_pick_prize(array $prizes): ?array {
        $filtered = array_values(array_filter($prizes, static fn (array $prize): bool => !empty($prize['active'])));
        if (empty($filtered)) {
            return null;
        }

        $totalWeight = 0.0;
        foreach ($filtered as $prize) {
            $totalWeight += max(0.0, (float) ($prize['chance_percent'] ?? 0));
        }

        if ($totalWeight <= 0) {
            return $filtered[0];
        }

        $roll = function_exists('random_int') ? random_int(1, 10000) : mt_rand(1, 10000);
        $cursor = 0.0;
        foreach ($filtered as $prize) {
            $cursor += max(0.0, (float) ($prize['chance_percent'] ?? 0)) * 100.0;
            if ($roll <= (int) round($cursor)) {
                return $prize;
            }
        }

        return $filtered[array_key_last($filtered)];
    }
}

if (!function_exists('daily_missions_get_streaming_account_label')) {
    function daily_missions_get_streaming_account_label(mysqli $mysqli, int $streamingUserId): string {
        if ($streamingUserId <= 0) {
            return '';
        }

        $stmt = $mysqli->prepare('SELECT nombre, email FROM usuarios WHERE id = ? LIMIT 1');
        if (!$stmt) {
            return '';
        }
        $stmt->bind_param('i', $streamingUserId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
        $stmt->close();

        if (!is_array($row)) {
            return '';
        }

        $name = trim((string) ($row['nombre'] ?? ''));
        $email = trim((string) ($row['email'] ?? ''));

        return $name !== '' ? $name : $email;
    }
}

if (!function_exists('daily_missions_open_chest')) {
    function daily_missions_open_chest(mysqli $mysqli, int $userId, array $context = []): array {
        daily_missions_ensure_schema();
        if ($userId <= 0) {
            return [
                'ok' => false,
                'message' => 'Debes iniciar sesion para abrir el cofre.',
            ];
        }

        $missionDate = trim((string) ($context['mission_date'] ?? ''));
        if ($missionDate === '') {
            $missionDate = daily_missions_today();
        }

        $mysqli->begin_transaction();
        try {
            daily_missions_ensure_user_state($mysqli, $userId);
            daily_missions_ensure_user_day($mysqli, $userId, $missionDate, count(daily_missions_fetch_tasks($mysqli, true)));

            $dayStmt = $mysqli->prepare('SELECT * FROM win_points_daily_mission_days WHERE user_id = ? AND mission_date = ? LIMIT 1 FOR UPDATE');
            if (!$dayStmt) {
                throw new RuntimeException('No se pudo bloquear el progreso del cofre.');
            }
            $dayStmt->bind_param('is', $userId, $missionDate);
            $dayStmt->execute();
            $dayResult = $dayStmt->get_result();
            $dayRow = $dayResult instanceof mysqli_result ? $dayResult->fetch_assoc() : null;
            $dayStmt->close();

            $stateStmt = $mysqli->prepare('SELECT * FROM win_points_daily_mission_state WHERE user_id = ? LIMIT 1 FOR UPDATE');
            if (!$stateStmt) {
                throw new RuntimeException('No se pudo bloquear el estado del cofre.');
            }
            $stateStmt->bind_param('i', $userId);
            $stateStmt->execute();
            $stateResult = $stateStmt->get_result();
            $stateRow = $stateResult instanceof mysqli_result ? $stateResult->fetch_assoc() : null;
            $stateStmt->close();

            $day = daily_missions_normalize_day_row(is_array($dayRow) ? $dayRow : []);
            $state = daily_missions_normalize_state_row(is_array($stateRow) ? $stateRow : []);
            $settings = daily_missions_fetch_settings($mysqli);

            if (empty($day['is_completed'])) {
                throw new RuntimeException('Primero completa todas las tareas del dia.');
            }
            if (!empty($day['chest_claimed_at'])) {
                throw new RuntimeException('El cofre de hoy ya fue reclamado.');
            }

            $levelKey = daily_missions_resolve_level_key((int) ($day['current_streak_days'] ?? 0), $missionDate);
            $prize = daily_missions_pick_prize(daily_missions_fetch_prizes($mysqli, $levelKey, true));
            if (!$prize) {
                throw new RuntimeException('No hay premios configurados para este cofre.');
            }

            $rewardStatus = 'granted';
            $pointsAmount = 0;
            $couponCode = '';
            $couponDiscountPercent = 0;
            $immunityDays = 0;
            $streamingUserId = 0;
            $prizeLabel = (string) ($prize['prize_label'] ?? 'Premio');
            $reason = 'Premio del cofre ' . daily_missions_level_label($levelKey);
            $metadata = [
                'origin' => 'daily_mission_chest',
                'mission_date' => $missionDate,
                'level_key' => $levelKey,
                'selected_prize' => $prize,
            ];

            switch ((string) ($prize['prize_type'] ?? 'winpoints')) {
                case 'coupon':
                    $couponDiscountPercent = max(1, (int) ($prize['coupon_discount_percent'] ?? $settings['coupon_discount_percent'] ?? 10));
                    $couponData = daily_missions_issue_coupon($mysqli, $couponDiscountPercent, (int) ($settings['coupon_expiration_days'] ?? 30));
                    $couponCode = (string) ($couponData['coupon_code'] ?? '');
                    $rewardStatus = !empty($couponData['inserted']) ? 'issued' : 'pending';
                    break;
                case 'immunity':
                    $immunityDays = max(1, (int) ($prize['immunity_days'] ?? $settings['immunity_days'] ?? 1));
                    $rewardStatus = 'granted';
                    break;
                case 'streaming_ticket':
                    $streamingUserId = (int) ($prize['streaming_user_id'] ?? 0);
                    if ($streamingUserId <= 0) {
                        $streamingUserId = (int) ($settings['streaming_user_id'] ?? 0);
                    }
                    $rewardStatus = 'assigned';
                    break;
                default:
                    $pointsAmount = max(0, (int) ($prize['points_amount'] ?? 0));
                    if ($pointsAmount > 0 && function_exists('win_points_record_transaction')) {
                        win_points_record_transaction(
                            $mysqli,
                            $userId,
                            $pointsAmount,
                            'daily_mission_chest',
                            $reason,
                            null,
                            null,
                            null,
                            null,
                            [
                                'origin' => 'daily_mission_chest',
                                'mission_date' => $missionDate,
                                'level_key' => $levelKey,
                                'prize' => $prize,
                            ]
                        );
                    }
                    break;
            }

            $reward = daily_missions_register_reward_row($mysqli, [
                'user_id' => $userId,
                'mission_date' => $missionDate,
                'mission_key' => 'daily_chest',
                'origin_type' => 'chest',
                'prize_type' => (string) ($prize['prize_type'] ?? 'winpoints'),
                'prize_label' => $prizeLabel,
                'reason' => $reason,
                'reward_status' => $rewardStatus,
                'level_key' => $levelKey,
                'points_amount' => $pointsAmount,
                'coupon_code' => $couponCode,
                'coupon_discount_percent' => $couponDiscountPercent,
                'immunity_days' => $immunityDays,
                'streaming_user_id' => $streamingUserId,
                'order_id' => null,
                'metadata_json' => $metadata,
                'claimed_at' => daily_missions_now(),
                'resolved_at' => daily_missions_now(),
            ]);

            $dayUpdate = [
                'chest_claimed_at' => daily_missions_now(),
                'chest_reward_id' => (int) ($reward['id'] ?? 0),
                'chest_level' => $levelKey,
                'last_event_at' => daily_missions_now(),
            ];
            daily_missions_touch_day_row($mysqli, $userId, $missionDate, $dayUpdate);

            $stateTouch = [
                'chest_rewards_total' => max(0, (int) ($state['chest_rewards_total'] ?? 0)) + 1,
                'last_chest_claim_date' => $missionDate,
            ];
            if ($immunityDays > 0) {
                $stateTouch['immunity_balance'] = max(0, (int) ($state['immunity_balance'] ?? 0)) + $immunityDays;
            }
            daily_missions_touch_state_row($mysqli, $userId, $stateTouch);

            $mysqli->commit();

            return [
                'ok' => true,
                'message' => 'Cofre abierto correctamente.',
                'day' => daily_missions_fetch_user_day($mysqli, $userId, $missionDate),
                'state' => daily_missions_fetch_user_state($mysqli, $userId),
                'reward' => $reward,
                'prize' => $prize,
                'level_key' => $levelKey,
            ];
        } catch (Throwable $exception) {
            $mysqli->rollback();

            return [
                'ok' => false,
                'message' => $exception->getMessage() !== '' ? $exception->getMessage() : 'No se pudo abrir el cofre.',
            ];
        }
    }
}

if (!function_exists('daily_missions_task_status_map')) {
    function daily_missions_task_status_map(array $day): array {
        return [
            'login_daily' => !empty($day['login_done']),
            'explore_random' => !empty($day['explore_done']),
            'share_social' => !empty($day['share_done']),
            'purchase_any' => !empty($day['purchase_done']),
            'refer_friend' => !empty($day['friend_done']),
        ];
    }
}

if (!function_exists('daily_missions_active_task_count')) {
    function daily_missions_active_task_count(mysqli $mysqli): int {
        $tasks = daily_missions_fetch_tasks($mysqli, true);

        return count($tasks);
    }
}

if (!function_exists('daily_missions_progress_percent')) {
    function daily_missions_progress_percent(array $day): int {
        $required = max(1, (int) ($day['required_tasks_count'] ?? 4));
        $completed = max(0, (int) ($day['completed_tasks_count'] ?? 0));

        return (int) min(100, round(($completed / $required) * 100));
    }
}

if (!function_exists('daily_missions_public_payload')) {
    function daily_missions_public_payload(mysqli $mysqli, int $userId, int $historyLimit = 60): array {
        daily_missions_ensure_schema();
        $settings = daily_missions_fetch_settings($mysqli);
        $tasks = daily_missions_fetch_tasks($mysqli, false);
        $today = daily_missions_today();
        $day = $userId > 0 ? daily_missions_fetch_user_day($mysqli, $userId, $today) : daily_missions_normalize_day_row([]);
        $state = $userId > 0 ? daily_missions_fetch_user_state($mysqli, $userId) : daily_missions_normalize_state_row([]);
        $history = $userId > 0 ? daily_missions_fetch_user_history($mysqli, $userId, $historyLimit) : [];

        $taskStatusMap = daily_missions_task_status_map($day);
        $taskList = [];
        foreach ($tasks as $task) {
            $task['completed_today'] = !empty($taskStatusMap[$task['mission_key']] ?? false);
            $task['action_url'] = $task['action_url'] !== '' ? $task['action_url'] : '/';
            $taskList[] = $task;
        }

        return [
            'enabled' => (int) ($settings['enabled'] ?? 0) === 1,
            'settings' => $settings,
            'tasks' => $taskList,
            'day' => $day,
            'state' => $state,
            'history' => $history,
            'progress_percent' => daily_missions_progress_percent($day),
            'active_task_count' => count(array_filter($tasks, static fn (array $task): bool => !empty($task['active']))),
            'today' => $today,
            'can_open_chest' => !empty($day['is_completed']) && empty($day['chest_claimed_at']),
            'chest_level' => daily_missions_resolve_level_key((int) ($day['current_streak_days'] ?? 0), $today),
        ];
    }
}

if (function_exists('win_points_db')) {
    daily_missions_ensure_schema();
}

?>