<?php

require_once __DIR__ . '/tenant.php';
require_once __DIR__ . '/store_config.php';

if (!function_exists('roulette_db')) {
    function roulette_db(): mysqli {
        global $mysqli;
        if (!isset($mysqli) || !($mysqli instanceof mysqli)) {
            require_once __DIR__ . '/db_connect.php';
        }
        return $mysqli;
    }
}

if (!function_exists('roulette_enabled')) {
    function roulette_enabled(): bool {
        $mysqli = roulette_db();
        roulette_ensure_schema();
        $row = $mysqli->query('SELECT enabled FROM win_points_roulette_config WHERE id = 1 LIMIT 1');
        if (!($row instanceof mysqli_result)) {
            return false;
        }
        $r = $row->fetch_assoc();
        return isset($r['enabled']) && (int) $r['enabled'] === 1;
    }
}

if (!function_exists('roulette_default_sections')) {
    function roulette_default_sections(): array {
        return [
            ['section_order' => 1, 'prize_type' => 'winpoints',       'prize_label' => 'Win Points',       'chance_percent' => 25.0, 'points_amount' => 5,  'coupon_discount_percent' => 0,  'immunity_days' => 0, 'streaming_user_id' => 0, 'color' => '#22d3ee', 'icon_emoji' => '🪙', 'active' => 1],
            ['section_order' => 2, 'prize_type' => 'coupon',          'prize_label' => 'Cupón 10%',        'chance_percent' => 15.0, 'points_amount' => 0,  'coupon_discount_percent' => 10, 'immunity_days' => 0, 'streaming_user_id' => 0, 'color' => '#fb923c', 'icon_emoji' => '🎟️', 'active' => 1],
            ['section_order' => 3, 'prize_type' => 'winpoints',       'prize_label' => 'Win Points ×2',   'chance_percent' => 20.0, 'points_amount' => 10, 'coupon_discount_percent' => 0,  'immunity_days' => 0, 'streaming_user_id' => 0, 'color' => '#a78bfa', 'icon_emoji' => '💰', 'active' => 1],
            ['section_order' => 4, 'prize_type' => 'immunity',        'prize_label' => 'Escudo',           'chance_percent' => 10.0, 'points_amount' => 0,  'coupon_discount_percent' => 0,  'immunity_days' => 1, 'streaming_user_id' => 0, 'color' => '#4ade80', 'icon_emoji' => '🛡️', 'active' => 1],
            ['section_order' => 5, 'prize_type' => 'winpoints',       'prize_label' => 'Win Points',       'chance_percent' => 15.0, 'points_amount' => 5,  'coupon_discount_percent' => 0,  'immunity_days' => 0, 'streaming_user_id' => 0, 'color' => '#22d3ee', 'icon_emoji' => '🪙', 'active' => 1],
            ['section_order' => 6, 'prize_type' => 'coupon',          'prize_label' => 'Cupón 15%',        'chance_percent' => 8.0,  'points_amount' => 0,  'coupon_discount_percent' => 15, 'immunity_days' => 0, 'streaming_user_id' => 0, 'color' => '#fb923c', 'icon_emoji' => '🎟️', 'active' => 1],
            ['section_order' => 7, 'prize_type' => 'winpoints',       'prize_label' => 'Win Points ×4',   'chance_percent' => 5.0,  'points_amount' => 20, 'coupon_discount_percent' => 0,  'immunity_days' => 0, 'streaming_user_id' => 0, 'color' => '#fcd34d', 'icon_emoji' => '🌟', 'active' => 1],
            ['section_order' => 8, 'prize_type' => 'streaming_ticket','prize_label' => 'Ticket Streaming', 'chance_percent' => 2.0,  'points_amount' => 0,  'coupon_discount_percent' => 0,  'immunity_days' => 0, 'streaming_user_id' => 0, 'color' => '#f472b6', 'icon_emoji' => '🎬', 'active' => 1],
        ];
    }
}

if (!function_exists('roulette_ensure_schema')) {
    function roulette_ensure_schema(): void {
        static $initialized = false;
        if ($initialized) {
            return;
        }
        $mysqli = roulette_db();

        $mysqli->query(
            "CREATE TABLE IF NOT EXISTS win_points_roulette_config (
                id TINYINT UNSIGNED NOT NULL PRIMARY KEY DEFAULT 1,
                enabled TINYINT(1) NOT NULL DEFAULT 1,
                spin_cost INT NOT NULL DEFAULT 10,
                title VARCHAR(120) NOT NULL DEFAULT 'Ruleta de Premios',
                subtitle VARCHAR(255) NOT NULL DEFAULT 'Gira y gana premios increíbles.',
                coupon_expiration_days INT NOT NULL DEFAULT 30,
                streaming_user_id INT NULL DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $mysqli->query(
            "INSERT IGNORE INTO win_points_roulette_config (id, enabled, spin_cost, title, subtitle)
             VALUES (1, 1, 10, 'Ruleta de Premios', 'Gira y gana premios increíbles.')"
        );

        // Migrations: config columns
        $chkRingColor = $mysqli->query("SELECT COUNT(*) AS n FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='win_points_roulette_config' AND COLUMN_NAME='ring_color'");
        if ($chkRingColor instanceof mysqli_result && (int)($chkRingColor->fetch_assoc()['n'] ?? 0) === 0) {
            $mysqli->query("ALTER TABLE win_points_roulette_config ADD COLUMN ring_color VARCHAR(20) NOT NULL DEFAULT '#6366f1' AFTER streaming_user_id");
        }
        $chkRingWidth = $mysqli->query("SELECT COUNT(*) AS n FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='win_points_roulette_config' AND COLUMN_NAME='ring_width'");
        if ($chkRingWidth instanceof mysqli_result && (int)($chkRingWidth->fetch_assoc()['n'] ?? 0) === 0) {
            $mysqli->query("ALTER TABLE win_points_roulette_config ADD COLUMN ring_width TINYINT UNSIGNED NOT NULL DEFAULT 2 AFTER ring_color");
        }
        $chkRingNeon = $mysqli->query("SELECT COUNT(*) AS n FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='win_points_roulette_config' AND COLUMN_NAME='ring_neon'");
        if ($chkRingNeon instanceof mysqli_result && (int)($chkRingNeon->fetch_assoc()['n'] ?? 0) === 0) {
            $mysqli->query("ALTER TABLE win_points_roulette_config ADD COLUMN ring_neon TINYINT(1) NOT NULL DEFAULT 0 AFTER ring_width");
        }

        // Migrations: section columns
        $chkFontSize = $mysqli->query("SELECT COUNT(*) AS n FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='win_points_roulette_sections' AND COLUMN_NAME='font_size'");
        if ($chkFontSize instanceof mysqli_result && (int)($chkFontSize->fetch_assoc()['n'] ?? 0) === 0) {
            $mysqli->query("ALTER TABLE win_points_roulette_sections ADD COLUMN font_size TINYINT UNSIGNED NOT NULL DEFAULT 7 AFTER active");
        }
        $chkFontColor = $mysqli->query("SELECT COUNT(*) AS n FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='win_points_roulette_sections' AND COLUMN_NAME='font_color'");
        if ($chkFontColor instanceof mysqli_result && (int)($chkFontColor->fetch_assoc()['n'] ?? 0) === 0) {
            $mysqli->query("ALTER TABLE win_points_roulette_sections ADD COLUMN font_color VARCHAR(20) NOT NULL DEFAULT '#ffffff' AFTER font_size");
        }
        $chkImgUrl = $mysqli->query("SELECT COUNT(*) AS n FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='win_points_roulette_sections' AND COLUMN_NAME='icon_image_url'");
        if ($chkImgUrl instanceof mysqli_result && (int)($chkImgUrl->fetch_assoc()['n'] ?? 0) === 0) {
            $mysqli->query("ALTER TABLE win_points_roulette_sections ADD COLUMN icon_image_url VARCHAR(500) NOT NULL DEFAULT '' AFTER font_color");
        }

        $mysqli->query(
            "CREATE TABLE IF NOT EXISTS win_points_roulette_sections (
                id INT AUTO_INCREMENT PRIMARY KEY,
                section_order TINYINT UNSIGNED NOT NULL DEFAULT 1,
                prize_type VARCHAR(30) NOT NULL DEFAULT 'winpoints',
                prize_label VARCHAR(120) NOT NULL DEFAULT '',
                chance_percent DECIMAL(8,2) NOT NULL DEFAULT 12.50,
                points_amount INT NOT NULL DEFAULT 0,
                coupon_discount_percent INT NOT NULL DEFAULT 0,
                immunity_days INT NOT NULL DEFAULT 0,
                streaming_user_id INT NOT NULL DEFAULT 0,
                color VARCHAR(20) NOT NULL DEFAULT '#22d3ee',
                icon_emoji VARCHAR(16) NOT NULL DEFAULT '🎁',
                active TINYINT(1) NOT NULL DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_roulette_section_order (section_order)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        // Seed default sections if none exist
        $countResult = $mysqli->query('SELECT COUNT(*) AS n FROM win_points_roulette_sections');
        $countRow = $countResult instanceof mysqli_result ? $countResult->fetch_assoc() : null;
        if ((int) ($countRow['n'] ?? 0) === 0) {
            foreach (roulette_default_sections() as $sec) {
                $o  = (int) $sec['section_order'];
                $pt = addslashes((string) $sec['prize_type']);
                $pl = addslashes((string) $sec['prize_label']);
                $ch = (float) $sec['chance_percent'];
                $pa = (int) $sec['points_amount'];
                $cd = (int) $sec['coupon_discount_percent'];
                $im = (int) $sec['immunity_days'];
                $su = (int) $sec['streaming_user_id'];
                $cl = addslashes((string) $sec['color']);
                $em = addslashes((string) $sec['icon_emoji']);
                $ac = (int) $sec['active'];
                $mysqli->query(
                    "INSERT IGNORE INTO win_points_roulette_sections
                     (section_order,prize_type,prize_label,chance_percent,points_amount,coupon_discount_percent,immunity_days,streaming_user_id,color,icon_emoji,active)
                     VALUES ($o,'$pt','$pl',$ch,$pa,$cd,$im,$su,'$cl','$em',$ac)"
                );
            }
        }

        $mysqli->query(
            "CREATE TABLE IF NOT EXISTS win_points_roulette_spins (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                section_id INT NOT NULL DEFAULT 0,
                section_order TINYINT UNSIGNED NOT NULL DEFAULT 1,
                prize_type VARCHAR(30) NOT NULL DEFAULT 'winpoints',
                prize_label VARCHAR(120) NOT NULL DEFAULT '',
                points_amount INT NOT NULL DEFAULT 0,
                coupon_code VARCHAR(60) NOT NULL DEFAULT '',
                coupon_discount_percent INT NOT NULL DEFAULT 0,
                immunity_days INT NOT NULL DEFAULT 0,
                streaming_user_id INT NOT NULL DEFAULT 0,
                wp_cost INT NOT NULL DEFAULT 0,
                balance_before INT NOT NULL DEFAULT 0,
                balance_after INT NOT NULL DEFAULT 0,
                metadata_json LONGTEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_roulette_spins_user (user_id, created_at),
                INDEX idx_roulette_spins_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $initialized = true;
    }
}

if (!function_exists('roulette_normalize_section')) {
    function roulette_normalize_section(array $row): array {
        $color = trim((string) ($row['color'] ?? '#22d3ee'));
        if ($color !== 'transparent' && !preg_match('/^#[0-9a-fA-F]{3,6}$/', $color)) {
            $color = '#22d3ee';
        }
        $fontColor = trim((string) ($row['font_color'] ?? '#ffffff'));
        if (!preg_match('/^#[0-9a-fA-F]{3,6}$/', $fontColor)) {
            $fontColor = '#ffffff';
        }
        return [
            'id'                     => (int) ($row['id'] ?? 0),
            'section_order'          => (int) ($row['section_order'] ?? 1),
            'prize_type'             => trim((string) ($row['prize_type'] ?? 'winpoints')),
            'prize_label'            => trim((string) ($row['prize_label'] ?? '')),
            'chance_percent'         => max(0.0, (float) ($row['chance_percent'] ?? 0)),
            'points_amount'          => max(0, (int) ($row['points_amount'] ?? 0)),
            'coupon_discount_percent'=> max(0, min(100, (int) ($row['coupon_discount_percent'] ?? 0))),
            'immunity_days'          => max(0, (int) ($row['immunity_days'] ?? 0)),
            'streaming_user_id'      => max(0, (int) ($row['streaming_user_id'] ?? 0)),
            'color'                  => $color,
            'icon_emoji'             => trim((string) ($row['icon_emoji'] ?? '🎁')),
            'active'                 => (int) ($row['active'] ?? 1) === 1,
            'font_size'              => max(5, min(20, (int) ($row['font_size'] ?? 7))),
            'font_color'             => $fontColor,
            'icon_image_url'         => trim((string) ($row['icon_image_url'] ?? '')),
        ];
    }
}

if (!function_exists('roulette_fetch_config')) {
    function roulette_fetch_config(mysqli $mysqli): array {
        roulette_ensure_schema();
        $result = $mysqli->query('SELECT * FROM win_points_roulette_config WHERE id = 1 LIMIT 1');
        $row = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
        $ringColor = trim((string) ($row['ring_color'] ?? '#6366f1'));
        if (!preg_match('/^#[0-9a-fA-F]{3,6}$/', $ringColor)) {
            $ringColor = '#6366f1';
        }
        return [
            'enabled'                => (int) ($row['enabled'] ?? 1) === 1,
            'spin_cost'              => max(0, (int) ($row['spin_cost'] ?? 10)),
            'title'                  => trim((string) ($row['title'] ?? 'Ruleta de Premios')),
            'subtitle'               => trim((string) ($row['subtitle'] ?? 'Gira y gana premios increíbles.')),
            'coupon_expiration_days' => max(1, (int) ($row['coupon_expiration_days'] ?? 30)),
            'streaming_user_id'      => (int) ($row['streaming_user_id'] ?? 0) > 0 ? (int) $row['streaming_user_id'] : null,
            'ring_color'             => $ringColor,
            'ring_width'             => max(1, min(10, (int) ($row['ring_width'] ?? 2))),
            'ring_neon'              => (int) ($row['ring_neon'] ?? 0) === 1,
        ];
    }
}

if (!function_exists('roulette_fetch_sections')) {
    function roulette_fetch_sections(mysqli $mysqli): array {
        roulette_ensure_schema();
        $result = $mysqli->query('SELECT * FROM win_points_roulette_sections ORDER BY section_order ASC LIMIT 8');
        $sections = [];
        if ($result instanceof mysqli_result) {
            while ($row = $result->fetch_assoc()) {
                $sections[] = roulette_normalize_section($row);
            }
        }
        // Fill missing sections up to 8 with defaults
        $defaults = roulette_default_sections();
        for ($i = count($sections); $i < 8; $i++) {
            $sections[] = roulette_normalize_section($defaults[$i] ?? [
                'section_order' => $i + 1,
                'prize_type' => 'winpoints',
                'prize_label' => 'Win Points',
                'chance_percent' => 12.5,
                'points_amount' => 5,
                'color' => '#22d3ee',
                'icon_emoji' => '🪙',
                'active' => 1,
            ]);
        }
        return $sections;
    }
}

if (!function_exists('roulette_pick_winner')) {
    function roulette_pick_winner(array $sections): ?array {
        $eligible = array_values(array_filter($sections, fn($s) => !empty($s['active']) && $s['chance_percent'] > 0));
        if (empty($eligible)) {
            return $sections[0] ?? null;
        }
        $total = 0.0;
        foreach ($eligible as $s) {
            $total += (float) $s['chance_percent'];
        }
        if ($total <= 0) {
            return $eligible[0];
        }
        $roll = function_exists('random_int') ? random_int(1, 10000) : mt_rand(1, 10000);
        $cursor = 0.0;
        foreach ($eligible as $s) {
            $cursor += ((float) $s['chance_percent'] / $total) * 10000.0;
            if ($roll <= (int) round($cursor)) {
                return $s;
            }
        }
        return $eligible[array_key_last($eligible)];
    }
}

if (!function_exists('roulette_execute_spin')) {
    function roulette_execute_spin(mysqli $mysqli, int $userId, array $context = []): array {
        roulette_ensure_schema();
        if ($userId <= 0) {
            return ['ok' => false, 'message' => 'Debes iniciar sesión para girar la ruleta.'];
        }

        $config   = roulette_fetch_config($mysqli);
        if (!$config['enabled']) {
            return ['ok' => false, 'message' => 'La ruleta no está activa en este momento.'];
        }

        $spinCost = $config['spin_cost'];
        $sections = roulette_fetch_sections($mysqli);

        // Check WP balance
        if (!function_exists('win_points_wallet_balance')) {
            require_once __DIR__ . '/win_points.php';
        }
        $balance = win_points_wallet_balance($mysqli, $userId);
        if ($balance < $spinCost) {
            return [
                'ok'      => false,
                'message' => 'No tienes suficientes ' . win_points_program_name() . ' para girar la ruleta. Necesitas ' . $spinCost . ' y tienes ' . $balance . '.',
                'balance' => $balance,
            ];
        }

        $winner = roulette_pick_winner($sections);
        if (!$winner) {
            return ['ok' => false, 'message' => 'La ruleta no tiene secciones configuradas.'];
        }

        $mysqli->begin_transaction();
        try {
            // Deduct spin cost
            $spendResult = win_points_record_transaction(
                $mysqli, $userId, -$spinCost, 'roulette_spend',
                'Giro de ruleta (costo: ' . $spinCost . ' ' . win_points_program_name() . ')',
                null, null, null, null,
                ['origin' => 'roulette', 'spin_cost' => $spinCost, 'section_order' => $winner['section_order']]
            );

            $balanceBefore = (int) ($spendResult['balance_before'] ?? $balance);
            $balanceAfterCost = (int) ($spendResult['balance_after'] ?? ($balance - $spinCost));

            // Apply prize
            $prizeType             = (string) ($winner['prize_type'] ?? 'winpoints');
            $prizeLabel            = (string) ($winner['prize_label'] ?? 'Premio');
            $pointsAmount          = 0;
            $couponCode            = '';
            $couponDiscountPercent = 0;
            $immunityDays          = 0;
            $streamingUserId       = 0;
            $balanceAfterPrize     = $balanceAfterCost;

            switch ($prizeType) {
                case 'winpoints':
                    $pointsAmount = max(0, (int) ($winner['points_amount'] ?? 0));
                    if ($pointsAmount > 0) {
                        $earnResult = win_points_record_transaction(
                            $mysqli, $userId, $pointsAmount, 'roulette_prize',
                            'Premio de ruleta: ' . $prizeLabel,
                            null, null, null, null,
                            ['origin' => 'roulette', 'section_order' => $winner['section_order'], 'prize_label' => $prizeLabel]
                        );
                        $balanceAfterPrize = (int) ($earnResult['balance_after'] ?? $balanceAfterCost);
                    }
                    break;

                case 'coupon':
                    $couponDiscountPercent = max(1, (int) ($winner['coupon_discount_percent'] ?? 10));
                    $expirationDays        = max(1, (int) ($config['coupon_expiration_days'] ?? 30));
                    if (function_exists('daily_missions_issue_coupon')) {
                        $couponData = daily_missions_issue_coupon($mysqli, $couponDiscountPercent, $expirationDays);
                        $couponCode = (string) ($couponData['coupon_code'] ?? '');
                    } else {
                        $couponCode = 'SPIN-' . strtoupper(substr(md5(uniqid('', true)), 0, 8));
                    }
                    break;

                case 'immunity':
                    $immunityDays = max(1, (int) ($winner['immunity_days'] ?? 1));
                    $stmt = $mysqli->prepare(
                        'INSERT INTO win_points_daily_mission_state (user_id, immunity_balance)
                         VALUES (?, ?)
                         ON DUPLICATE KEY UPDATE immunity_balance = immunity_balance + VALUES(immunity_balance)'
                    );
                    if ($stmt) {
                        $stmt->bind_param('ii', $userId, $immunityDays);
                        $stmt->execute();
                        $stmt->close();
                    }
                    break;

                case 'streaming_ticket':
                    $streamingUserId = (int) ($winner['streaming_user_id'] ?? 0);
                    if ($streamingUserId <= 0 && isset($config['streaming_user_id'])) {
                        $streamingUserId = (int) ($config['streaming_user_id'] ?? 0);
                    }
                    break;
            }

            // Record spin in roulette_spins history
            $metaJson = json_encode([
                'origin'         => 'roulette',
                'section'        => $winner,
                'spin_cost'      => $spinCost,
                'balance_before' => $balanceBefore,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $sectionId    = (int) ($winner['id'] ?? 0);
            $sectionOrder = (int) ($winner['section_order'] ?? 1);
            $insertStmt = $mysqli->prepare(
                'INSERT INTO win_points_roulette_spins
                 (user_id, section_id, section_order, prize_type, prize_label, points_amount, coupon_code,
                  coupon_discount_percent, immunity_days, streaming_user_id, wp_cost, balance_before, balance_after, metadata_json)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            if (!$insertStmt) {
                throw new RuntimeException('No se pudo registrar el giro de la ruleta.');
            }
            $insertStmt->bind_param(
                'iiisssissiiiis',
                $userId, $sectionId, $sectionOrder,
                $prizeType, $prizeLabel, $pointsAmount, $couponCode,
                $couponDiscountPercent, $immunityDays, $streamingUserId,
                $spinCost, $balanceBefore, $balanceAfterPrize, $metaJson
            );
            $insertStmt->execute();
            $spinId = (int) $insertStmt->insert_id;
            $insertStmt->close();

            // Also record in daily_missions_rewards for unified user history
            if (function_exists('daily_missions_register_reward_row')) {
                daily_missions_register_reward_row($mysqli, [
                    'user_id'               => $userId,
                    'mission_date'          => date('Y-m-d'),
                    'mission_key'           => 'roulette_spin',
                    'origin_type'           => 'roulette',
                    'prize_type'            => $prizeType,
                    'prize_label'           => $prizeLabel,
                    'reason'                => 'Giro de ruleta — sección ' . $sectionOrder,
                    'reward_status'         => $prizeType === 'coupon' && $couponCode !== '' ? 'issued' : ($prizeType === 'streaming_ticket' ? 'assigned' : 'granted'),
                    'level_key'             => '',
                    'points_amount'         => $pointsAmount,
                    'coupon_code'           => $couponCode,
                    'coupon_discount_percent' => $couponDiscountPercent,
                    'immunity_days'         => $immunityDays,
                    'streaming_user_id'     => $streamingUserId,
                    'order_id'              => null,
                    'metadata_json'         => [
                        'origin'         => 'roulette',
                        'spin_id'        => $spinId,
                        'section_order'  => $sectionOrder,
                        'spin_cost'      => $spinCost,
                    ],
                    'claimed_at'            => date('Y-m-d H:i:s'),
                    'resolved_at'           => date('Y-m-d H:i:s'),
                ]);
            }

            $mysqli->commit();

            return [
                'ok'                     => true,
                'spin_id'                => $spinId,
                'section_index'          => $sectionOrder - 1,
                'section_order'          => $sectionOrder,
                'prize_type'             => $prizeType,
                'prize_label'            => $prizeLabel,
                'points_amount'          => $pointsAmount,
                'coupon_code'            => $couponCode,
                'coupon_discount_percent'=> $couponDiscountPercent,
                'immunity_days'          => $immunityDays,
                'streaming_user_id'      => $streamingUserId,
                'icon_emoji'             => (string) ($winner['icon_emoji'] ?? '🎁'),
                'color'                  => (string) ($winner['color'] ?? '#22d3ee'),
                'wp_cost'                => $spinCost,
                'balance_before'         => $balanceBefore,
                'balance_after'          => $balanceAfterPrize,
            ];

        } catch (Throwable $e) {
            $mysqli->rollback();
            return ['ok' => false, 'message' => 'Error al procesar el giro: ' . $e->getMessage()];
        }
    }
}

if (!function_exists('roulette_fetch_user_history')) {
    function roulette_fetch_user_history(mysqli $mysqli, int $userId, int $limit = 20): array {
        roulette_ensure_schema();
        if ($userId <= 0) {
            return [];
        }
        $limit = max(1, min(100, $limit));
        $stmt = $mysqli->prepare(
            'SELECT s.*, sec.color, sec.icon_emoji
             FROM win_points_roulette_spins s
             LEFT JOIN win_points_roulette_sections sec ON sec.section_order = s.section_order
             WHERE s.user_id = ?
             ORDER BY s.created_at DESC, s.id DESC
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
                $rows[] = $row;
            }
        }
        $stmt->close();
        return $rows;
    }
}

if (!function_exists('roulette_fetch_all_history')) {
    function roulette_fetch_all_history(mysqli $mysqli, int $limit = 100): array {
        roulette_ensure_schema();
        $limit = max(1, min(500, $limit));
        $result = $mysqli->query(
            "SELECT s.*, u.nombre, u.email
             FROM win_points_roulette_spins s
             LEFT JOIN usuarios u ON u.id = s.user_id
             ORDER BY s.created_at DESC, s.id DESC
             LIMIT $limit"
        );
        $rows = [];
        if ($result instanceof mysqli_result) {
            while ($row = $result->fetch_assoc()) {
                $rows[] = $row;
            }
        }
        return $rows;
    }
}

if (!function_exists('roulette_admin_save_config')) {
    function roulette_admin_save_config(mysqli $mysqli, array $data): void {
        roulette_ensure_schema();
        $enabled    = isset($data['enabled']) && (int) $data['enabled'] === 1 ? 1 : 0;
        $spinCost   = max(0, (int) ($data['spin_cost'] ?? 10));
        $title      = trim((string) ($data['title'] ?? 'Ruleta de Premios'));
        $subtitle   = trim((string) ($data['subtitle'] ?? ''));
        $expDays    = max(1, (int) ($data['coupon_expiration_days'] ?? 30));
        $streamId   = (int) ($data['streaming_user_id'] ?? 0) > 0 ? (int) $data['streaming_user_id'] : null;

        $ringColor = trim((string) ($data['ring_color'] ?? '#6366f1'));
        if (!preg_match('/^#[0-9a-fA-F]{3,6}$/', $ringColor)) {
            $ringColor = '#6366f1';
        }
        $ringWidth = max(1, min(10, (int) ($data['ring_width'] ?? 2)));
        $ringNeon  = isset($data['ring_neon']) && (int) $data['ring_neon'] === 1 ? 1 : 0;

        $stmt = $mysqli->prepare(
            'INSERT INTO win_points_roulette_config (id, enabled, spin_cost, title, subtitle, coupon_expiration_days, streaming_user_id, ring_color, ring_width, ring_neon)
             VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE enabled=VALUES(enabled), spin_cost=VALUES(spin_cost),
               title=VALUES(title), subtitle=VALUES(subtitle),
               coupon_expiration_days=VALUES(coupon_expiration_days),
               streaming_user_id=VALUES(streaming_user_id),
               ring_color=VALUES(ring_color), ring_width=VALUES(ring_width), ring_neon=VALUES(ring_neon)'
        );
        if (!$stmt) {
            return;
        }
        $stmt->bind_param('iissiiisi', $enabled, $spinCost, $title, $subtitle, $expDays, $streamId, $ringColor, $ringWidth, $ringNeon);
        $stmt->execute();
        $stmt->close();
    }
}

if (!function_exists('roulette_admin_save_section')) {
    function roulette_admin_save_section(mysqli $mysqli, int $sectionOrder, array $data): void {
        roulette_ensure_schema();
        $sectionOrder = max(1, min(8, $sectionOrder));
        $prizeType    = trim((string) ($data['prize_type'] ?? 'winpoints'));
        if (!in_array($prizeType, ['winpoints', 'coupon', 'immunity', 'streaming_ticket'], true)) {
            $prizeType = 'winpoints';
        }
        $prizeLabel   = trim((string) ($data['prize_label'] ?? ''));
        $chance       = max(0.0, min(100.0, (float) ($data['chance_percent'] ?? 0)));
        $points       = max(0, (int) ($data['points_amount'] ?? 0));
        $couponPct    = max(0, min(100, (int) ($data['coupon_discount_percent'] ?? 0)));
        $immunityDays = max(0, (int) ($data['immunity_days'] ?? 0));
        $streamId     = max(0, (int) ($data['streaming_user_id'] ?? 0));
        $color        = trim((string) ($data['color'] ?? '#22d3ee'));
        if ($color !== 'transparent' && !preg_match('/^#[0-9a-fA-F]{3,6}$/', $color)) {
            $color = '#22d3ee';
        }
        $emoji        = trim((string) ($data['icon_emoji'] ?? '🎁'));
        $active       = isset($data['active']) && (int) $data['active'] === 1 ? 1 : 0;
        $fontSize     = max(5, min(20, (int) ($data['font_size'] ?? 7)));
        $fontColor    = trim((string) ($data['font_color'] ?? '#ffffff'));
        if (!preg_match('/^#[0-9a-fA-F]{3,6}$/', $fontColor)) {
            $fontColor = '#ffffff';
        }
        $iconImageUrl = trim((string) ($data['icon_image_url'] ?? ''));

        $stmt = $mysqli->prepare(
            'INSERT INTO win_points_roulette_sections
             (section_order, prize_type, prize_label, chance_percent, points_amount, coupon_discount_percent,
              immunity_days, streaming_user_id, color, icon_emoji, active, font_size, font_color, icon_image_url)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
               prize_type=VALUES(prize_type), prize_label=VALUES(prize_label),
               chance_percent=VALUES(chance_percent), points_amount=VALUES(points_amount),
               coupon_discount_percent=VALUES(coupon_discount_percent), immunity_days=VALUES(immunity_days),
               streaming_user_id=VALUES(streaming_user_id), color=VALUES(color),
               icon_emoji=VALUES(icon_emoji), active=VALUES(active),
               font_size=VALUES(font_size), font_color=VALUES(font_color), icon_image_url=VALUES(icon_image_url)'
        );
        if (!$stmt) {
            return;
        }
        $stmt->bind_param('issdiiiissiiss',
            $sectionOrder, $prizeType, $prizeLabel, $chance,
            $points, $couponPct, $immunityDays, $streamId,
            $color, $emoji, $active, $fontSize, $fontColor, $iconImageUrl
        );
        $stmt->execute();
        $stmt->close();
    }
}

if (!function_exists('roulette_public_payload')) {
    function roulette_public_payload(mysqli $mysqli, int $userId): array {
        roulette_ensure_schema();
        $config   = roulette_fetch_config($mysqli);
        $sections = roulette_fetch_sections($mysqli);
        $balance  = $userId > 0 && function_exists('win_points_wallet_balance')
            ? win_points_wallet_balance($mysqli, $userId)
            : 0;
        $history  = $userId > 0 ? roulette_fetch_user_history($mysqli, $userId, 10) : [];

        $canSpin = $config['enabled']
            && $userId > 0
            && $balance >= $config['spin_cost'];

        return [
            'config'   => $config,
            'sections' => $sections,
            'balance'  => $balance,
            'can_spin' => $canSpin,
            'history'  => $history,
        ];
    }
}
