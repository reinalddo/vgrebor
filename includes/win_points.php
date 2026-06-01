<?php

require_once __DIR__ . '/tenant.php';
require_once __DIR__ . '/store_config.php';

if (!function_exists('win_points_db')) {
    function win_points_db(): mysqli {
        global $mysqli;

        if (!isset($mysqli) || !($mysqli instanceof mysqli)) {
            require_once __DIR__ . '/db_connect.php';
        }

        return $mysqli;
    }
}

if (!function_exists('win_points_enabled')) {
    function win_points_enabled(): bool {
        return trim((string) store_config_get('win_points', '0')) === '1';
    }
}

if (!function_exists('win_points_program_name')) {
    function win_points_program_name(): string {
        $name = trim(store_config_get('win_points_name', 'Win Points'));
        return $name !== '' ? $name : 'Win Points';
    }
}

if (!function_exists('win_points_default_award')) {
    function win_points_default_award(): int {
        return 0;
    }
}

if (!function_exists('win_points_guest_message')) {
    function win_points_guest_message(): string {
        return 'Registrate o Inicia Sesion para acceder al sistema de Premios por recarga';
    }
}

if (!function_exists('win_points_normalize_expiration_days')) {
    function win_points_normalize_expiration_days($value): int {
        $days = (int) $value;
        if ($days <= 0) {
            $days = 180;
        }

        return max(1, min(3650, $days));
    }
}

if (!function_exists('win_points_expiration_days')) {
    function win_points_expiration_days(): int {
        return win_points_normalize_expiration_days(store_config_get('win_points_expiration_days', '180'));
    }
}

if (!function_exists('win_points_icon_path')) {
    function win_points_icon_path(): string {
        return trim(store_config_get('win_points_icon', ''));
    }
}

if (!function_exists('win_points_icon_url')) {
    function win_points_icon_url(): string {
        $path = win_points_icon_path();
        if ($path === '') {
            return '';
        }

        if (preg_match('#^https?://#i', $path) === 1) {
            return $path;
        }

        return function_exists('app_path') ? app_path('/' . ltrim($path, '/')) : '/' . ltrim($path, '/');
    }
}

if (!function_exists('win_points_payment_image_path')) {
    function win_points_payment_image_path(): string {
        return trim(store_config_get('win_points_payment_image', ''));
    }
}

if (!function_exists('win_points_payment_image_url')) {
    function win_points_payment_image_url(): string {
        $path = win_points_payment_image_path();
        if ($path === '') {
            return '';
        }

        if (preg_match('#^https?://#i', $path) === 1) {
            return $path;
        }

        return function_exists('app_path') ? app_path('/' . ltrim($path, '/')) : '/' . ltrim($path, '/');
    }
}

if (!function_exists('win_points_payment_corner_image_path')) {
    function win_points_payment_corner_image_path(): string {
        return trim(store_config_get('win_points_payment_corner_image', ''));
    }
}

if (!function_exists('win_points_payment_corner_image_url')) {
    function win_points_payment_corner_image_url(): string {
        $path = win_points_payment_corner_image_path();
        if ($path === '') {
            return '';
        }

        if (preg_match('#^https?://#i', $path) === 1) {
            return $path;
        }

        return function_exists('app_path') ? app_path('/' . ltrim($path, '/')) : '/' . ltrim($path, '/');
    }
}

if (!function_exists('win_points_normalize_hex_color')) {
    function win_points_normalize_hex_color($value, string $default): string {
        $fallback = strtoupper(trim($default));
        $candidate = strtoupper(trim((string) $value));

        if (preg_match('/^#([0-9A-F]{3}|[0-9A-F]{6})$/', $candidate) !== 1) {
            return $fallback;
        }

        if (strlen($candidate) === 4) {
            return sprintf(
                '#%1$s%1$s%2$s%2$s%3$s%3$s',
                $candidate[1],
                $candidate[2],
                $candidate[3]
            );
        }

        return $candidate;
    }
}

if (!function_exists('win_points_badge_background_color')) {
    function win_points_badge_background_color(): string {
        return win_points_normalize_hex_color(store_config_get('win_points_badge_background_color', '#3E2D07'), '#3E2D07');
    }
}

if (!function_exists('win_points_badge_text_color')) {
    function win_points_badge_text_color(): string {
        return win_points_normalize_hex_color(store_config_get('win_points_badge_text_color', '#FCD34D'), '#FCD34D');
    }
}

if (!function_exists('win_points_notification_position_options')) {
    function win_points_notification_position_options(): array {
        return [
            'bottom-left' => 'Abajo a la izquierda',
            'bottom-center' => 'Abajo al centro',
            'bottom-right' => 'Abajo a la derecha',
            'top-left' => 'Arriba a la izquierda',
            'top-center' => 'Arriba al centro',
            'top-right' => 'Arriba a la derecha',
            'middle-right' => 'Centro derecha',
            'middle-left' => 'Centro izquierda',
        ];
    }
}

if (!function_exists('win_points_normalize_notification_position')) {
    function win_points_normalize_notification_position($value): string {
        $position = trim((string) $value);
        $options = win_points_notification_position_options();

        return array_key_exists($position, $options) ? $position : 'bottom-left';
    }
}

if (!function_exists('win_points_notification_position')) {
    function win_points_notification_position(): string {
        return win_points_normalize_notification_position(store_config_get('win_points_notification_position', 'bottom-left'));
    }
}

if (!function_exists('win_points_hex_to_rgba')) {
    function win_points_hex_to_rgba(string $hexColor, float $alpha): string {
        $normalized = win_points_normalize_hex_color($hexColor, '#000000');
        $alpha = max(0, min(1, $alpha));
        $red = hexdec(substr($normalized, 1, 2));
        $green = hexdec(substr($normalized, 3, 2));
        $blue = hexdec(substr($normalized, 5, 2));

        return sprintf('rgba(%d, %d, %d, %.3F)', $red, $green, $blue, $alpha);
    }
}

if (!function_exists('win_points_config')) {
    function win_points_config(): array {
        return [
            'enabled' => win_points_enabled(),
            'name' => win_points_program_name(),
            'icon_path' => win_points_icon_path(),
            'icon_url' => win_points_icon_url(),
            'payment_image_path' => win_points_payment_image_path(),
            'payment_image_url' => win_points_payment_image_url(),
            'payment_corner_image_path' => win_points_payment_corner_image_path(),
            'payment_corner_image_url' => win_points_payment_corner_image_url(),
            'badge_background_color' => win_points_badge_background_color(),
            'badge_text_color' => win_points_badge_text_color(),
            'notification_position' => win_points_notification_position(),
            'expiration_days' => win_points_expiration_days(),
            'default_award' => win_points_default_award(),
            'guest_message' => win_points_guest_message(),
        ];
    }
}

if (!function_exists('win_points_store_icon_upload')) {
    function win_points_store_icon_upload(array $file): array {
        return store_config_store_named_logo_upload($file, 'win-points-icon');
    }
}

if (!function_exists('win_points_delete_icon_file')) {
    function win_points_delete_icon_file(string $path): void {
        store_config_delete_logo_file($path);
    }
}

if (!function_exists('win_points_store_payment_image_upload')) {
    function win_points_store_payment_image_upload(array $file): array {
        return store_config_store_named_logo_upload($file, 'win-points-payment-method');
    }
}

if (!function_exists('win_points_delete_payment_image_file')) {
    function win_points_delete_payment_image_file(string $path): void {
        store_config_delete_logo_file($path);
    }
}

if (!function_exists('win_points_store_payment_corner_image_upload')) {
    function win_points_store_payment_corner_image_upload(array $file): array {
        return store_config_store_named_logo_upload($file, 'win-points-payment-corner');
    }
}

if (!function_exists('win_points_delete_payment_corner_image_file')) {
    function win_points_delete_payment_corner_image_file(string $path): void {
        store_config_delete_logo_file($path);
    }
}

if (!function_exists('win_points_normalize_delta')) {
    function win_points_normalize_delta($value): int {
        return (int) $value;
    }
}

if (!function_exists('win_points_users_has_phone_column')) {
    function win_points_users_has_phone_column(mysqli $mysqli): bool {
        $result = $mysqli->query("SHOW COLUMNS FROM usuarios LIKE 'telefono'");
        return $result instanceof mysqli_result && $result->num_rows > 0;
    }
}

if (!function_exists('win_points_ensure_schema')) {
    function win_points_ensure_schema(): void {
        static $initialized = false;

        if ($initialized) {
            return;
        }

        store_config_ensure_defaults();
        $mysqli = win_points_db();

        $mysqli->query(
            "CREATE TABLE IF NOT EXISTS win_points_wallets (
                user_id INT NOT NULL PRIMARY KEY,
                balance INT NOT NULL DEFAULT 0,
                expiration_reference_at DATETIME NULL DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_win_points_wallets_balance (balance),
                INDEX idx_win_points_wallets_expiration_reference (expiration_reference_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $walletColumns = [
            'user_id' => "ALTER TABLE win_points_wallets ADD COLUMN user_id INT NOT NULL PRIMARY KEY",
            'balance' => "ALTER TABLE win_points_wallets ADD COLUMN balance INT NOT NULL DEFAULT 0 AFTER user_id",
            'expiration_reference_at' => "ALTER TABLE win_points_wallets ADD COLUMN expiration_reference_at DATETIME NULL DEFAULT NULL AFTER balance",
            'created_at' => "ALTER TABLE win_points_wallets ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER expiration_reference_at",
            'updated_at' => "ALTER TABLE win_points_wallets ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at",
        ];
        $walletColumnResult = $mysqli->query('SHOW COLUMNS FROM win_points_wallets');
        $walletExisting = [];
        if ($walletColumnResult instanceof mysqli_result) {
            while ($row = $walletColumnResult->fetch_assoc()) {
                $walletExisting[$row['Field']] = true;
            }
        }
        foreach ($walletColumns as $column => $sql) {
            if (!isset($walletExisting[$column])) {
                $mysqli->query($sql);
            }
        }
        $walletIndexResult = $mysqli->query("SHOW INDEX FROM win_points_wallets WHERE Key_name = 'idx_win_points_wallets_balance'");
        if (!($walletIndexResult instanceof mysqli_result) || $walletIndexResult->num_rows === 0) {
            $mysqli->query('ALTER TABLE win_points_wallets ADD INDEX idx_win_points_wallets_balance (balance)');
        }
        $walletExpirationIndexResult = $mysqli->query("SHOW INDEX FROM win_points_wallets WHERE Key_name = 'idx_win_points_wallets_expiration_reference'");
        if (!($walletExpirationIndexResult instanceof mysqli_result) || $walletExpirationIndexResult->num_rows === 0) {
            $mysqli->query('ALTER TABLE win_points_wallets ADD INDEX idx_win_points_wallets_expiration_reference (expiration_reference_at)');
        }

        $mysqli->query(
            "CREATE TABLE IF NOT EXISTS win_points_transactions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                order_id INT DEFAULT NULL,
                juego_id INT DEFAULT NULL,
                paquete_id INT DEFAULT NULL,
                actor_user_id INT DEFAULT NULL,
                transaction_type VARCHAR(50) NOT NULL,
                points_delta INT NOT NULL,
                balance_after INT NOT NULL,
                description VARCHAR(255) DEFAULT NULL,
                metadata_json LONGTEXT DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_win_points_transactions_user_id (user_id),
                INDEX idx_win_points_transactions_order_id (order_id),
                INDEX idx_win_points_transactions_created_at (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $transactionColumns = [
            'user_id' => "ALTER TABLE win_points_transactions ADD COLUMN user_id INT NOT NULL AFTER id",
            'order_id' => "ALTER TABLE win_points_transactions ADD COLUMN order_id INT NULL AFTER user_id",
            'juego_id' => "ALTER TABLE win_points_transactions ADD COLUMN juego_id INT NULL AFTER order_id",
            'paquete_id' => "ALTER TABLE win_points_transactions ADD COLUMN paquete_id INT NULL AFTER juego_id",
            'actor_user_id' => "ALTER TABLE win_points_transactions ADD COLUMN actor_user_id INT NULL AFTER paquete_id",
            'transaction_type' => "ALTER TABLE win_points_transactions ADD COLUMN transaction_type VARCHAR(50) NOT NULL AFTER actor_user_id",
            'points_delta' => "ALTER TABLE win_points_transactions ADD COLUMN points_delta INT NOT NULL AFTER transaction_type",
            'balance_after' => "ALTER TABLE win_points_transactions ADD COLUMN balance_after INT NOT NULL AFTER points_delta",
            'description' => "ALTER TABLE win_points_transactions ADD COLUMN description VARCHAR(255) NULL AFTER balance_after",
            'metadata_json' => "ALTER TABLE win_points_transactions ADD COLUMN metadata_json LONGTEXT NULL AFTER description",
            'created_at' => "ALTER TABLE win_points_transactions ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER metadata_json",
        ];
        $transactionColumnResult = $mysqli->query('SHOW COLUMNS FROM win_points_transactions');
        $transactionExisting = [];
        if ($transactionColumnResult instanceof mysqli_result) {
            while ($row = $transactionColumnResult->fetch_assoc()) {
                $transactionExisting[$row['Field']] = true;
            }
        }
        foreach ($transactionColumns as $column => $sql) {
            if (!isset($transactionExisting[$column])) {
                $mysqli->query($sql);
            }
        }
        foreach ([
            'idx_win_points_transactions_user_id' => 'ALTER TABLE win_points_transactions ADD INDEX idx_win_points_transactions_user_id (user_id)',
            'idx_win_points_transactions_order_id' => 'ALTER TABLE win_points_transactions ADD INDEX idx_win_points_transactions_order_id (order_id)',
            'idx_win_points_transactions_created_at' => 'ALTER TABLE win_points_transactions ADD INDEX idx_win_points_transactions_created_at (created_at)',
        ] as $indexName => $sql) {
            $indexResult = $mysqli->query("SHOW INDEX FROM win_points_transactions WHERE Key_name = '" . $mysqli->real_escape_string($indexName) . "'");
            if (!($indexResult instanceof mysqli_result) || $indexResult->num_rows === 0) {
                $mysqli->query($sql);
            }
        }

        $mysqli->query(
            "CREATE TABLE IF NOT EXISTS win_points_redemption_rules (
                id INT AUTO_INCREMENT PRIMARY KEY,
                juego_id INT NOT NULL,
                paquete_id INT NOT NULL,
                required_points INT NOT NULL DEFAULT 0,
                activo TINYINT(1) NOT NULL DEFAULT 1,
                orden INT DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_win_points_redemption_package (paquete_id),
                INDEX idx_win_points_redemption_game (juego_id),
                INDEX idx_win_points_redemption_active (activo, juego_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $ruleColumns = [
            'juego_id' => "ALTER TABLE win_points_redemption_rules ADD COLUMN juego_id INT NOT NULL AFTER id",
            'paquete_id' => "ALTER TABLE win_points_redemption_rules ADD COLUMN paquete_id INT NOT NULL AFTER juego_id",
            'required_points' => "ALTER TABLE win_points_redemption_rules ADD COLUMN required_points INT NOT NULL DEFAULT 0 AFTER paquete_id",
            'activo' => "ALTER TABLE win_points_redemption_rules ADD COLUMN activo TINYINT(1) NOT NULL DEFAULT 1 AFTER required_points",
            'orden' => "ALTER TABLE win_points_redemption_rules ADD COLUMN orden INT NULL AFTER activo",
            'created_at' => "ALTER TABLE win_points_redemption_rules ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER orden",
            'updated_at' => "ALTER TABLE win_points_redemption_rules ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at",
        ];
        $ruleColumnResult = $mysqli->query('SHOW COLUMNS FROM win_points_redemption_rules');
        $ruleExisting = [];
        if ($ruleColumnResult instanceof mysqli_result) {
            while ($row = $ruleColumnResult->fetch_assoc()) {
                $ruleExisting[$row['Field']] = true;
            }
        }
        foreach ($ruleColumns as $column => $sql) {
            if (!isset($ruleExisting[$column])) {
                $mysqli->query($sql);
            }
        }
        foreach ([
            'uniq_win_points_redemption_package' => 'ALTER TABLE win_points_redemption_rules ADD UNIQUE KEY uniq_win_points_redemption_package (paquete_id)',
            'idx_win_points_redemption_game' => 'ALTER TABLE win_points_redemption_rules ADD INDEX idx_win_points_redemption_game (juego_id)',
            'idx_win_points_redemption_active' => 'ALTER TABLE win_points_redemption_rules ADD INDEX idx_win_points_redemption_active (activo, juego_id)',
        ] as $indexName => $sql) {
            $indexResult = $mysqli->query("SHOW INDEX FROM win_points_redemption_rules WHERE Key_name = '" . $mysqli->real_escape_string($indexName) . "'");
            if (!($indexResult instanceof mysqli_result) || $indexResult->num_rows === 0) {
                $mysqli->query($sql);
            }
        }

        $packageColumnResult = $mysqli->query("SHOW COLUMNS FROM juego_paquetes LIKE 'win_points_reward'");
        if (!($packageColumnResult instanceof mysqli_result) || $packageColumnResult->num_rows === 0) {
            $mysqli->query("ALTER TABLE juego_paquetes ADD COLUMN win_points_reward INT NULL AFTER precio");
        }

        $orderColumns = [
            'win_points_awarded' => "ALTER TABLE pedidos ADD COLUMN win_points_awarded INT NOT NULL DEFAULT 0 AFTER precio",
            'win_points_awarded_granted' => "ALTER TABLE pedidos ADD COLUMN win_points_awarded_granted TINYINT(1) NOT NULL DEFAULT 0 AFTER win_points_awarded",
            'win_points_award_reversed' => "ALTER TABLE pedidos ADD COLUMN win_points_award_reversed TINYINT(1) NOT NULL DEFAULT 0 AFTER win_points_awarded_granted",
            'win_points_spent' => "ALTER TABLE pedidos ADD COLUMN win_points_spent INT NOT NULL DEFAULT 0 AFTER win_points_award_reversed",
            'win_points_spent_refunded' => "ALTER TABLE pedidos ADD COLUMN win_points_spent_refunded TINYINT(1) NOT NULL DEFAULT 0 AFTER win_points_spent",
            'win_points_redemption_rule_id' => "ALTER TABLE pedidos ADD COLUMN win_points_redemption_rule_id INT NULL AFTER win_points_spent_refunded",
            'win_points_payment_mode' => "ALTER TABLE pedidos ADD COLUMN win_points_payment_mode VARCHAR(20) NOT NULL DEFAULT 'money' AFTER win_points_redemption_rule_id",
        ];
        $orderColumnResult = $mysqli->query('SHOW COLUMNS FROM pedidos');
        $orderExisting = [];
        if ($orderColumnResult instanceof mysqli_result) {
            while ($row = $orderColumnResult->fetch_assoc()) {
                $orderExisting[$row['Field']] = true;
            }
        }
        foreach ($orderColumns as $column => $sql) {
            if (!isset($orderExisting[$column])) {
                $mysqli->query($sql);
            }
        }

        $initialized = true;
    }
}

if (!function_exists('win_points_ensure_wallet')) {
    function win_points_ensure_wallet(mysqli $mysqli, int $userId): void {
        if ($userId <= 0) {
            return;
        }

        $stmt = $mysqli->prepare('INSERT IGNORE INTO win_points_wallets (user_id, balance) VALUES (?, 0)');
        if (!$stmt) {
            return;
        }
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $stmt->close();
    }
}

if (!function_exists('win_points_wallet_balance')) {
    function win_points_wallet_balance(mysqli $mysqli, int $userId): int {
        if ($userId <= 0) {
            return 0;
        }

        win_points_ensure_schema();
        win_points_ensure_wallet($mysqli, $userId);
        $walletState = win_points_fetch_wallet_state($mysqli, $userId);
        return (int) ($walletState['effective_balance'] ?? 0);
    }
}

if (!function_exists('win_points_wallet_expiration_empty')) {
    function win_points_wallet_expiration_empty(?int $expirationDays = null): array {
        return [
            'expiration_days' => $expirationDays ?? win_points_expiration_days(),
            'expiration_reference_at' => null,
            'expires_at' => null,
            'expires_at_iso' => null,
            'expires_at_label' => 'Sin saldo',
            'days_remaining' => null,
            'days_remaining_label' => 'Sin saldo',
            'is_expired' => false,
            'status' => 'no_balance',
        ];
    }
}

if (!function_exists('win_points_compute_expiration_data')) {
    function win_points_compute_expiration_data(?string $referenceAt, int $balance, ?int $expirationDays = null): array {
        $resolvedDays = $expirationDays ?? win_points_expiration_days();
        if ($balance <= 0) {
            return win_points_wallet_expiration_empty($resolvedDays);
        }

        $referenceAt = trim((string) $referenceAt);
        if ($referenceAt === '') {
            return [
                'expiration_days' => $resolvedDays,
                'expiration_reference_at' => null,
                'expires_at' => null,
                'expires_at_iso' => null,
                'expires_at_label' => 'Por definir',
                'days_remaining' => null,
                'days_remaining_label' => 'Por definir',
                'is_expired' => false,
                'status' => 'pending_reference',
            ];
        }

        try {
            $referenceDate = new DateTimeImmutable($referenceAt);
            $expiresDate = $referenceDate->modify('+' . $resolvedDays . ' days');
        } catch (Throwable $exception) {
            return [
                'expiration_days' => $resolvedDays,
                'expiration_reference_at' => null,
                'expires_at' => null,
                'expires_at_iso' => null,
                'expires_at_label' => 'Por definir',
                'days_remaining' => null,
                'days_remaining_label' => 'Por definir',
                'is_expired' => false,
                'status' => 'pending_reference',
            ];
        }

        $nowTimestamp = time();
        $expiresTimestamp = $expiresDate->getTimestamp();
        $remainingSeconds = $expiresTimestamp - $nowTimestamp;

        if ($remainingSeconds <= 0) {
            return [
                'expiration_days' => $resolvedDays,
                'expiration_reference_at' => $referenceDate->format('Y-m-d H:i:s'),
                'expires_at' => $expiresDate->format('Y-m-d H:i:s'),
                'expires_at_iso' => $expiresDate->format(DATE_ATOM),
                'expires_at_label' => $expiresDate->format('d/m/Y H:i'),
                'days_remaining' => 0,
                'days_remaining_label' => 'Vencidos',
                'is_expired' => true,
                'status' => 'expired',
            ];
        }

        $daysRemaining = (int) ceil($remainingSeconds / 86400);

        return [
            'expiration_days' => $resolvedDays,
            'expiration_reference_at' => $referenceDate->format('Y-m-d H:i:s'),
            'expires_at' => $expiresDate->format('Y-m-d H:i:s'),
            'expires_at_iso' => $expiresDate->format(DATE_ATOM),
            'expires_at_label' => $expiresDate->format('d/m/Y H:i'),
            'days_remaining' => $daysRemaining,
            'days_remaining_label' => $daysRemaining === 1 ? '1 dia' : ($daysRemaining . ' dias'),
            'is_expired' => false,
            'status' => $daysRemaining <= 7 ? 'warning' : 'active',
        ];
    }
}

if (!function_exists('win_points_find_wallet_reference_at')) {
    function win_points_find_wallet_reference_at(mysqli $mysqli, int $userId, array $walletRow = []): ?string {
        if ($userId <= 0) {
            return null;
        }

        $stmt = $mysqli->prepare(
            "SELECT MAX(created_at) AS last_reference_at
             FROM win_points_transactions
             WHERE user_id = ?
               AND points_delta > 0
               AND transaction_type IN ('earn', 'admin_adjustment')"
        );
        if ($stmt) {
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
            $stmt->close();
            $referenceAt = trim((string) ($row['last_reference_at'] ?? ''));
            if ($referenceAt !== '') {
                return $referenceAt;
            }
        }

        foreach (['updated_at', 'created_at'] as $fallbackKey) {
            $fallbackValue = trim((string) ($walletRow[$fallbackKey] ?? ''));
            if ($fallbackValue !== '') {
                return $fallbackValue;
            }
        }

        return null;
    }
}

if (!function_exists('win_points_resolve_wallet_reference_at')) {
    function win_points_resolve_wallet_reference_at(mysqli $mysqli, int $userId, array $walletRow, bool $persist = false): ?string {
        $referenceAt = trim((string) ($walletRow['expiration_reference_at'] ?? ''));
        if ($referenceAt !== '') {
            return $referenceAt;
        }

        if ((int) ($walletRow['balance'] ?? 0) <= 0) {
            return null;
        }

        $referenceAt = win_points_find_wallet_reference_at($mysqli, $userId, $walletRow);
        if ($persist && $referenceAt !== null && trim($referenceAt) !== '') {
            $stmt = $mysqli->prepare('UPDATE win_points_wallets SET expiration_reference_at = ? WHERE user_id = ?');
            if ($stmt) {
                $stmt->bind_param('si', $referenceAt, $userId);
                $stmt->execute();
                $stmt->close();
            }
        }

        return $referenceAt !== null && trim($referenceAt) !== '' ? $referenceAt : null;
    }
}

if (!function_exists('win_points_fetch_wallet_state')) {
    function win_points_fetch_wallet_state(mysqli $mysqli, int $userId): array {
        if ($userId <= 0) {
            return [
                'user_id' => 0,
                'balance' => 0,
                'effective_balance' => 0,
            ] + win_points_wallet_expiration_empty();
        }

        win_points_ensure_schema();
        win_points_ensure_wallet($mysqli, $userId);
        $stmt = $mysqli->prepare('SELECT user_id, balance, expiration_reference_at, created_at, updated_at FROM win_points_wallets WHERE user_id = ? LIMIT 1');
        if (!$stmt) {
            return [
                'user_id' => $userId,
                'balance' => 0,
                'effective_balance' => 0,
            ] + win_points_wallet_expiration_empty();
        }
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
        $stmt->close();

        $walletRow = is_array($row) ? $row : [
            'user_id' => $userId,
            'balance' => 0,
            'expiration_reference_at' => null,
            'created_at' => null,
            'updated_at' => null,
        ];
        $referenceAt = win_points_resolve_wallet_reference_at($mysqli, $userId, $walletRow, true);
        $expiration = win_points_compute_expiration_data($referenceAt, (int) ($walletRow['balance'] ?? 0));

        return array_merge($walletRow, $expiration, [
            'effective_balance' => !empty($expiration['is_expired']) ? 0 : (int) ($walletRow['balance'] ?? 0),
        ]);
    }
}

if (!function_exists('win_points_insert_transaction_row')) {
    function win_points_insert_transaction_row(mysqli $mysqli, int $userId, int $pointsDelta, string $type, int $balanceAfter, string $description = '', ?int $orderId = null, ?int $gameId = null, ?int $packageId = null, ?int $actorUserId = null, array $metadata = []): int {
        $metadataJson = json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($metadataJson)) {
            $metadataJson = '{}';
        }

        $insertStmt = $mysqli->prepare('INSERT INTO win_points_transactions (user_id, order_id, juego_id, paquete_id, actor_user_id, transaction_type, points_delta, balance_after, description, metadata_json) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        if (!$insertStmt) {
            throw new RuntimeException('No se pudo registrar el movimiento de premios.');
        }
        $resolvedOrderId = $orderId ?? 0;
        $resolvedGameId = $gameId ?? 0;
        $resolvedPackageId = $packageId ?? 0;
        $resolvedActorUserId = $actorUserId ?? 0;
        $insertStmt->bind_param('iiiiisiiss', $userId, $resolvedOrderId, $resolvedGameId, $resolvedPackageId, $resolvedActorUserId, $type, $pointsDelta, $balanceAfter, $description, $metadataJson);
        $insertStmt->execute();
        $transactionId = (int) $insertStmt->insert_id;
        $insertStmt->close();

        return $transactionId;
    }
}

if (!function_exists('win_points_transaction_refreshes_expiration')) {
    function win_points_transaction_refreshes_expiration(string $type, int $pointsDelta, array $metadata = []): bool {
        if (array_key_exists('refresh_expiration', $metadata)) {
            return (bool) $metadata['refresh_expiration'];
        }

        if ($pointsDelta <= 0) {
            return false;
        }

        return in_array($type, ['earn', 'admin_adjustment'], true);
    }
}

if (!function_exists('win_points_record_transaction')) {
    function win_points_record_transaction(mysqli $mysqli, int $userId, int $pointsDelta, string $type, string $description = '', ?int $orderId = null, ?int $gameId = null, ?int $packageId = null, ?int $actorUserId = null, array $metadata = []): array {
        win_points_ensure_schema();
        win_points_ensure_wallet($mysqli, $userId);

        $walletStmt = $mysqli->prepare('SELECT balance, expiration_reference_at, created_at, updated_at FROM win_points_wallets WHERE user_id = ? LIMIT 1 FOR UPDATE');
        if (!$walletStmt) {
            throw new RuntimeException('No se pudo bloquear la wallet de premios.');
        }
        $walletStmt->bind_param('i', $userId);
        $walletStmt->execute();
        $walletResult = $walletStmt->get_result();
        $walletRow = $walletResult instanceof mysqli_result ? $walletResult->fetch_assoc() : null;
        $walletStmt->close();

        $currentBalance = (int) ($walletRow['balance'] ?? 0);
        $referenceAt = win_points_resolve_wallet_reference_at($mysqli, $userId, $walletRow ?? [], false);
        $expiration = win_points_compute_expiration_data($referenceAt, $currentBalance);

        if (!empty($expiration['is_expired']) && $currentBalance > 0) {
            $expiredBalance = $currentBalance;
            $expireUpdateStmt = $mysqli->prepare('UPDATE win_points_wallets SET balance = 0, expiration_reference_at = ? WHERE user_id = ?');
            if (!$expireUpdateStmt) {
                throw new RuntimeException('No se pudo actualizar la wallet vencida de premios.');
            }
            $resolvedReferenceAt = $expiration['expiration_reference_at'] ?? $referenceAt;
            $expireUpdateStmt->bind_param('si', $resolvedReferenceAt, $userId);
            $expireUpdateStmt->execute();
            $expireUpdateStmt->close();

            win_points_insert_transaction_row(
                $mysqli,
                $userId,
                -$expiredBalance,
                'expiration',
                0,
                'Vencimiento automatico de ' . win_points_program_name() . ' por inactividad de recarga.',
                null,
                null,
                null,
                null,
                [
                    'expired_balance' => $expiredBalance,
                    'expiration_days' => (int) ($expiration['expiration_days'] ?? win_points_expiration_days()),
                    'expiration_reference_at' => $resolvedReferenceAt,
                ]
            );

            $currentBalance = 0;
        }

        $nextBalance = $currentBalance + $pointsDelta;
        $nextReferenceAt = $referenceAt;
        if (win_points_transaction_refreshes_expiration($type, $pointsDelta, $metadata)) {
            $nextReferenceAt = date('Y-m-d H:i:s');
        } elseif ($currentBalance <= 0 && $nextBalance <= 0) {
            $nextReferenceAt = null;
        }

        $updateStmt = $mysqli->prepare('UPDATE win_points_wallets SET balance = ?, expiration_reference_at = ? WHERE user_id = ?');
        if (!$updateStmt) {
            throw new RuntimeException('No se pudo actualizar el saldo de premios.');
        }
        $updateStmt->bind_param('isi', $nextBalance, $nextReferenceAt, $userId);
        $updateStmt->execute();
        $updateStmt->close();

        $transactionId = win_points_insert_transaction_row($mysqli, $userId, $pointsDelta, $type, $nextBalance, $description, $orderId, $gameId, $packageId, $actorUserId, $metadata);

        return [
            'transaction_id' => $transactionId,
            'balance_before' => $currentBalance,
            'balance_after' => $nextBalance,
        ];
    }
}

if (!function_exists('win_points_package_reward')) {
    function win_points_package_reward(array $package): int {
        $packageReward = isset($package['win_points_reward']) && $package['win_points_reward'] !== null
            ? (int) $package['win_points_reward']
            : 0;
        return max(0, $packageReward);
    }
}

if (!function_exists('win_points_order_purchase_quantity')) {
    function win_points_order_purchase_quantity(array $order): int {
        $quantity = (int) ($order['cantidad_compra'] ?? 1);
        return $quantity > 0 ? $quantity : 1;
    }
}

if (!function_exists('win_points_fetch_package_reward')) {
    function win_points_fetch_package_reward(mysqli $mysqli, int $packageId): int {
        win_points_ensure_schema();
        if ($packageId <= 0) {
            return 0;
        }
        $stmt = $mysqli->prepare('SELECT win_points_reward FROM juego_paquetes WHERE id = ? LIMIT 1');
        if (!$stmt) {
            return 0;
        }
        $stmt->bind_param('i', $packageId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
        $stmt->close();
        return win_points_package_reward($row ?? []);
    }
}

if (!function_exists('win_points_fetch_game_package_rewards')) {
    function win_points_fetch_game_package_rewards(mysqli $mysqli, int $gameId): array {
        win_points_ensure_schema();
        $rewards = [];
        if ($gameId <= 0) {
            return $rewards;
        }
        $stmt = $mysqli->prepare('SELECT id, win_points_reward FROM juego_paquetes WHERE juego_id = ?');
        if (!$stmt) {
            return $rewards;
        }
        $stmt->bind_param('i', $gameId);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result instanceof mysqli_result) {
            while ($row = $result->fetch_assoc()) {
                $rewards[(int) ($row['id'] ?? 0)] = win_points_package_reward($row);
            }
        }
        $stmt->close();
        return $rewards;
    }
}

if (!function_exists('win_points_fetch_game_redemption_rules')) {
    function win_points_fetch_game_redemption_rules(mysqli $mysqli, int $gameId): array {
        win_points_ensure_schema();
        $rules = [];
        if ($gameId <= 0) {
            return $rules;
        }
        $stmt = $mysqli->prepare('SELECT id, juego_id, paquete_id, required_points, activo, orden FROM win_points_redemption_rules WHERE juego_id = ? ORDER BY activo DESC, CASE WHEN orden IS NULL THEN 1 ELSE 0 END, orden ASC, id ASC');
        if (!$stmt) {
            return $rules;
        }
        $stmt->bind_param('i', $gameId);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result instanceof mysqli_result) {
            while ($row = $result->fetch_assoc()) {
                $rules[(int) ($row['paquete_id'] ?? 0)] = [
                    'id' => (int) ($row['id'] ?? 0),
                    'juego_id' => (int) ($row['juego_id'] ?? 0),
                    'paquete_id' => (int) ($row['paquete_id'] ?? 0),
                    'required_points' => max(0, (int) ($row['required_points'] ?? 0)),
                    'activo' => (int) ($row['activo'] ?? 0) === 1,
                    'orden' => isset($row['orden']) ? (int) $row['orden'] : null,
                ];
            }
        }
        $stmt->close();
        return $rules;
    }
}

if (!function_exists('win_points_fetch_rule_by_package')) {
    function win_points_fetch_rule_by_package(mysqli $mysqli, int $packageId, bool $onlyActive = true): ?array {
        win_points_ensure_schema();
        if ($packageId <= 0) {
            return null;
        }
        $sql = 'SELECT id, juego_id, paquete_id, required_points, activo, orden FROM win_points_redemption_rules WHERE paquete_id = ?';
        if ($onlyActive) {
            $sql .= ' AND activo = 1';
        }
        $sql .= ' LIMIT 1';
        $stmt = $mysqli->prepare($sql);
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('i', $packageId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
        $stmt->close();
        if (!$row) {
            return null;
        }
        return [
            'id' => (int) ($row['id'] ?? 0),
            'juego_id' => (int) ($row['juego_id'] ?? 0),
            'paquete_id' => (int) ($row['paquete_id'] ?? 0),
            'required_points' => max(0, (int) ($row['required_points'] ?? 0)),
            'activo' => (int) ($row['activo'] ?? 0) === 1,
            'orden' => isset($row['orden']) ? (int) $row['orden'] : null,
        ];
    }
}

if (!function_exists('win_points_fetch_user_summary')) {
    function win_points_fetch_user_summary(mysqli $mysqli, int $userId): array {
        win_points_ensure_schema();

        if ($userId <= 0) {
            return [
                'balance' => 0,
                'earned' => 0,
                'spent' => 0,
                'transactions' => 0,
            ] + win_points_wallet_expiration_empty();
        }

        $walletState = win_points_fetch_wallet_state($mysqli, $userId);
        $balance = (int) ($walletState['effective_balance'] ?? 0);
        $stmt = $mysqli->prepare(
            "SELECT
                COALESCE(SUM(CASE WHEN transaction_type = 'earn' THEN points_delta ELSE 0 END), 0) AS earned_points,
                COALESCE(SUM(CASE WHEN transaction_type = 'redeem' THEN ABS(points_delta) ELSE 0 END), 0) AS spent_points,
                COUNT(*) AS total_transactions
             FROM win_points_transactions
             WHERE user_id = ?"
        );
        if (!$stmt) {
            return [
                'balance' => $balance,
                'earned' => 0,
                'spent' => 0,
                'transactions' => 0,
            ] + [
                'expiration_days' => (int) ($walletState['expiration_days'] ?? win_points_expiration_days()),
                'expiration_reference_at' => $walletState['expiration_reference_at'] ?? null,
                'expires_at' => $walletState['expires_at'] ?? null,
                'expires_at_iso' => $walletState['expires_at_iso'] ?? null,
                'expires_at_label' => $walletState['expires_at_label'] ?? 'Sin saldo',
                'days_remaining' => $walletState['days_remaining'] ?? null,
                'days_remaining_label' => $walletState['days_remaining_label'] ?? 'Sin saldo',
                'is_expired' => !empty($walletState['is_expired']),
                'expiration_status' => (string) ($walletState['status'] ?? 'no_balance'),
            ];
        }
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
        $stmt->close();

        return [
            'balance' => $balance,
            'earned' => (int) ($row['earned_points'] ?? 0),
            'spent' => (int) ($row['spent_points'] ?? 0),
            'transactions' => (int) ($row['total_transactions'] ?? 0),
            'expiration_days' => (int) ($walletState['expiration_days'] ?? win_points_expiration_days()),
            'expiration_reference_at' => $walletState['expiration_reference_at'] ?? null,
            'expires_at' => $walletState['expires_at'] ?? null,
            'expires_at_iso' => $walletState['expires_at_iso'] ?? null,
            'expires_at_label' => $walletState['expires_at_label'] ?? 'Sin saldo',
            'days_remaining' => $walletState['days_remaining'] ?? null,
            'days_remaining_label' => $walletState['days_remaining_label'] ?? 'Sin saldo',
            'is_expired' => !empty($walletState['is_expired']),
            'expiration_status' => (string) ($walletState['status'] ?? 'no_balance'),
        ];
    }
}

if (!function_exists('win_points_empty_user_summary')) {
    function win_points_empty_user_summary(): array {
        return [
            'balance' => 0,
            'earned' => 0,
            'spent' => 0,
            'transactions' => 0,
        ] + win_points_wallet_expiration_empty();
    }
}

if (!function_exists('win_points_response_payload')) {
    function win_points_response_payload(mysqli $mysqli, ?int $userId = null, array $extra = []): array {
        $summary = $userId !== null && $userId > 0
            ? win_points_fetch_user_summary($mysqli, $userId)
            : win_points_empty_user_summary();

        return array_merge([
            'name' => win_points_program_name(),
            'balance' => (int) ($summary['balance'] ?? 0),
            'days_remaining' => $summary['days_remaining'] ?? null,
            'days_remaining_label' => (string) ($summary['days_remaining_label'] ?? 'Sin saldo'),
            'expires_at' => $summary['expires_at'] ?? null,
            'expires_at_label' => (string) ($summary['expires_at_label'] ?? 'Sin saldo'),
            'is_expired' => !empty($summary['is_expired']),
            'expiration_status' => (string) ($summary['expiration_status'] ?? 'no_balance'),
        ], $extra);
    }
}

if (!function_exists('win_points_fetch_user_transactions')) {
    function win_points_fetch_user_transactions(mysqli $mysqli, int $userId, int $limit = 20): array {
        win_points_ensure_schema();
        if ($userId <= 0) {
            return [];
        }

        $resolvedLimit = max(1, min(100, $limit));
        $sql = "SELECT t.id, t.order_id, t.juego_id, t.paquete_id, t.transaction_type, t.points_delta, t.balance_after, t.description, t.created_at,
                       p.juego_nombre, p.paquete_nombre
                FROM win_points_transactions t
                LEFT JOIN pedidos p ON p.id = t.order_id
                WHERE t.user_id = ?
                ORDER BY t.created_at DESC, t.id DESC
                LIMIT {$resolvedLimit}";
        $stmt = $mysqli->prepare($sql);
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $transactions = [];
        if ($result instanceof mysqli_result) {
            while ($row = $result->fetch_assoc()) {
                $transactions[] = [
                    'id' => (int) ($row['id'] ?? 0),
                    'order_id' => isset($row['order_id']) ? (int) $row['order_id'] : null,
                    'transaction_type' => (string) ($row['transaction_type'] ?? ''),
                    'points_delta' => (int) ($row['points_delta'] ?? 0),
                    'balance_after' => (int) ($row['balance_after'] ?? 0),
                    'description' => (string) ($row['description'] ?? ''),
                    'created_at' => (string) ($row['created_at'] ?? ''),
                    'juego_nombre' => (string) ($row['juego_nombre'] ?? ''),
                    'paquete_nombre' => (string) ($row['paquete_nombre'] ?? ''),
                ];
            }
        }
        $stmt->close();

        return $transactions;
    }
}

if (!function_exists('win_points_adjust_user_balance')) {
    function win_points_adjust_user_balance(mysqli $mysqli, int $userId, int $delta, string $reason, int $actorUserId = 0): array {
        win_points_ensure_schema();
        if ($userId <= 0) {
            throw new RuntimeException('Usuario invalido para ajustar premios.');
        }
        if ($delta === 0) {
            throw new RuntimeException('El ajuste de premios no puede ser cero.');
        }

        $mysqli->begin_transaction();
        try {
            $transaction = win_points_record_transaction(
                $mysqli,
                $userId,
                $delta,
                'admin_adjustment',
                trim($reason) !== '' ? $reason : 'Ajuste manual desde panel',
                null,
                null,
                null,
                $actorUserId > 0 ? $actorUserId : null,
                ['source' => 'admin_panel']
            );
            $mysqli->commit();
            return $transaction;
        } catch (Throwable $e) {
            $mysqli->rollback();
            throw $e;
        }
    }
}

if (!function_exists('win_points_assign_pending_order_redemption')) {
    function win_points_assign_pending_order_redemption(mysqli $mysqli, int $orderId, int $userId): array {
        win_points_ensure_schema();
        if (!win_points_enabled()) {
            throw new RuntimeException('El sistema de premios no está activo.');
        }
        if ($orderId <= 0 || $userId <= 0) {
            throw new RuntimeException('No se pudo preparar el canje de premios.');
        }

        $mysqli->begin_transaction();
        try {
            $orderStmt = $mysqli->prepare('SELECT * FROM pedidos WHERE id = ? LIMIT 1 FOR UPDATE');
            if (!$orderStmt) {
                throw new RuntimeException('No se pudo bloquear el pedido para el canje.');
            }
            $orderStmt->bind_param('i', $orderId);
            $orderStmt->execute();
            $orderResult = $orderStmt->get_result();
            $order = $orderResult instanceof mysqli_result ? $orderResult->fetch_assoc() : null;
            $orderStmt->close();

            if (!$order) {
                throw new RuntimeException('Pedido no encontrado para el canje.');
            }
            if ((string) ($order['estado'] ?? '') !== 'pendiente') {
                throw new RuntimeException('El pedido ya no admite canje por premios.');
            }
            if ((int) ($order['cliente_usuario_id'] ?? 0) !== $userId) {
                throw new RuntimeException('Debes iniciar sesión con el usuario dueño del pedido para usar tus premios.');
            }
            if ((string) ($order['win_points_payment_mode'] ?? 'money') === 'points' && (int) ($order['win_points_spent'] ?? 0) > 0) {
                $mysqli->commit();
                return [
                    'order' => $order,
                    'required_points' => (int) ($order['win_points_spent'] ?? 0),
                ];
            }

            $packageId = (int) ($order['paquete_id'] ?? 0);
            $rule = win_points_fetch_rule_by_package($mysqli, $packageId, true);
            if (!$rule) {
                throw new RuntimeException('Este paquete no tiene una regla activa de canje por premios.');
            }

            $requiredPoints = max(0, (int) ($rule['required_points'] ?? 0));
            if ($requiredPoints <= 0) {
                throw new RuntimeException('La regla de canje no tiene un costo válido en premios.');
            }

            $requiredPoints *= win_points_order_purchase_quantity($order);

            $walletBalance = win_points_wallet_balance($mysqli, $userId);
            if ($walletBalance < $requiredPoints) {
                throw new RuntimeException('No tienes saldo suficiente para canjear este paquete.');
            }

            win_points_record_transaction(
                $mysqli,
                $userId,
                -$requiredPoints,
                'redeem',
                'Canje de ' . win_points_program_name() . ' en pedido #' . $orderId,
                $orderId,
                (int) ($order['juego_id'] ?? 0),
                $packageId,
                $userId,
                ['rule_id' => (int) ($rule['id'] ?? 0)]
            );

            $updateStmt = $mysqli->prepare("UPDATE pedidos SET win_points_spent = ?, win_points_spent_refunded = 0, win_points_redemption_rule_id = ?, win_points_payment_mode = 'points' WHERE id = ?");
            if (!$updateStmt) {
                throw new RuntimeException('No se pudo marcar el pedido como canjeado por premios.');
            }
            $ruleId = (int) ($rule['id'] ?? 0);
            $updateStmt->bind_param('iii', $requiredPoints, $ruleId, $orderId);
            $updateStmt->execute();
            $updateStmt->close();

            $mysqli->commit();

            $order['win_points_spent'] = $requiredPoints;
            $order['win_points_redemption_rule_id'] = $ruleId;
            $order['win_points_payment_mode'] = 'points';

            return [
                'order' => $order,
                'rule' => $rule,
                'required_points' => $requiredPoints,
            ];
        } catch (Throwable $e) {
            $mysqli->rollback();
            throw $e;
        }
    }
}

if (!function_exists('win_points_apply_order_sent')) {
    function win_points_apply_order_sent(mysqli $mysqli, int $orderId): void {
        win_points_ensure_schema();
        if (!win_points_enabled() || $orderId <= 0) {
            return;
        }

        $mysqli->begin_transaction();
        try {
            $stmt = $mysqli->prepare('SELECT * FROM pedidos WHERE id = ? LIMIT 1 FOR UPDATE');
            if (!$stmt) {
                throw new RuntimeException('No se pudo bloquear el pedido para premios.');
            }
            $stmt->bind_param('i', $orderId);
            $stmt->execute();
            $result = $stmt->get_result();
            $order = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
            $stmt->close();

            if (!$order || (string) ($order['estado'] ?? '') !== 'enviado') {
                $mysqli->commit();
                return;
            }

            $userId = (int) ($order['cliente_usuario_id'] ?? 0);
            $paymentMode = (string) ($order['win_points_payment_mode'] ?? 'money');
            $awardPoints = max(0, (int) ($order['win_points_awarded'] ?? 0));
            $awardGranted = (int) ($order['win_points_awarded_granted'] ?? 0) === 1;
            $awardReversed = (int) ($order['win_points_award_reversed'] ?? 0) === 1;

            if ($userId <= 0 || $paymentMode === 'points' || $awardPoints <= 0 || ($awardGranted && !$awardReversed)) {
                $mysqli->commit();
                return;
            }

            win_points_record_transaction(
                $mysqli,
                $userId,
                $awardPoints,
                'earn',
                'Premio por recarga enviada #' . $orderId,
                $orderId,
                (int) ($order['juego_id'] ?? 0),
                (int) ($order['paquete_id'] ?? 0),
                null,
                ['payment_mode' => $paymentMode]
            );

            $updateStmt = $mysqli->prepare('UPDATE pedidos SET win_points_awarded_granted = 1, win_points_award_reversed = 0 WHERE id = ?');
            if (!$updateStmt) {
                throw new RuntimeException('No se pudo actualizar la entrega de premios del pedido.');
            }
            $updateStmt->bind_param('i', $orderId);
            $updateStmt->execute();
            $updateStmt->close();

            $mysqli->commit();
        } catch (Throwable $e) {
            $mysqli->rollback();
            error_log('win_points_apply_order_sent error: ' . $e->getMessage());
        }
    }
}

if (!function_exists('win_points_apply_order_cancelled')) {
    function win_points_apply_order_cancelled(mysqli $mysqli, int $orderId): void {
        win_points_ensure_schema();
        if (!win_points_enabled() || $orderId <= 0) {
            return;
        }

        $mysqli->begin_transaction();
        try {
            $stmt = $mysqli->prepare('SELECT * FROM pedidos WHERE id = ? LIMIT 1 FOR UPDATE');
            if (!$stmt) {
                throw new RuntimeException('No se pudo bloquear el pedido para revertir premios.');
            }
            $stmt->bind_param('i', $orderId);
            $stmt->execute();
            $result = $stmt->get_result();
            $order = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
            $stmt->close();

            if (!$order || (string) ($order['estado'] ?? '') !== 'cancelado') {
                $mysqli->commit();
                return;
            }

            $userId = (int) ($order['cliente_usuario_id'] ?? 0);
            if ($userId <= 0) {
                $mysqli->commit();
                return;
            }

            $orderAward = max(0, (int) ($order['win_points_awarded'] ?? 0));
            $awardGranted = (int) ($order['win_points_awarded_granted'] ?? 0) === 1;
            $awardReversed = (int) ($order['win_points_award_reversed'] ?? 0) === 1;
            $spentPoints = max(0, (int) ($order['win_points_spent'] ?? 0));
            $spentRefunded = (int) ($order['win_points_spent_refunded'] ?? 0) === 1;

            if ($awardGranted && !$awardReversed && $orderAward > 0) {
                win_points_record_transaction(
                    $mysqli,
                    $userId,
                    -$orderAward,
                    'award_reversal',
                    'Reverso de premios por pedido cancelado #' . $orderId,
                    $orderId,
                    (int) ($order['juego_id'] ?? 0),
                    (int) ($order['paquete_id'] ?? 0)
                );
                $reverseStmt = $mysqli->prepare('UPDATE pedidos SET win_points_award_reversed = 1 WHERE id = ?');
                if (!$reverseStmt) {
                    throw new RuntimeException('No se pudo marcar el reverso de premios.');
                }
                $reverseStmt->bind_param('i', $orderId);
                $reverseStmt->execute();
                $reverseStmt->close();
            }

            if ($spentPoints > 0 && !$spentRefunded) {
                win_points_record_transaction(
                    $mysqli,
                    $userId,
                    $spentPoints,
                    'redeem_refund',
                    'Reembolso de premios por pedido cancelado #' . $orderId,
                    $orderId,
                    (int) ($order['juego_id'] ?? 0),
                    (int) ($order['paquete_id'] ?? 0)
                );
                $refundStmt = $mysqli->prepare('UPDATE pedidos SET win_points_spent_refunded = 1 WHERE id = ?');
                if (!$refundStmt) {
                    throw new RuntimeException('No se pudo marcar el reembolso de premios.');
                }
                $refundStmt->bind_param('i', $orderId);
                $refundStmt->execute();
                $refundStmt->close();
            }

            $mysqli->commit();
        } catch (Throwable $e) {
            $mysqli->rollback();
            error_log('win_points_apply_order_cancelled error: ' . $e->getMessage());
        }
    }
}

if (!function_exists('win_points_handle_order_status_change')) {
    function win_points_handle_order_status_change(mysqli $mysqli, int $orderId, ?string $newStatus = null): void {
        if ($orderId <= 0 || !win_points_enabled()) {
            return;
        }

        $status = $newStatus !== null ? trim($newStatus) : '';
        if ($status === '') {
            $stmt = $mysqli->prepare('SELECT estado FROM pedidos WHERE id = ? LIMIT 1');
            if (!$stmt) {
                return;
            }
            $stmt->bind_param('i', $orderId);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
            $stmt->close();
            $status = (string) ($row['estado'] ?? '');
        }

        if ($status === 'enviado') {
            win_points_apply_order_sent($mysqli, $orderId);
        } elseif ($status === 'cancelado') {
            win_points_apply_order_cancelled($mysqli, $orderId);
        }
    }
}

if (!function_exists('win_points_fetch_admin_rules')) {
    function win_points_fetch_admin_rules(mysqli $mysqli): array {
        win_points_ensure_schema();
        $rules = [];
        $sql = "SELECT r.*, j.nombre AS juego_nombre, p.nombre AS paquete_nombre, p.win_points_reward
                FROM win_points_redemption_rules r
                INNER JOIN juegos j ON j.id = r.juego_id
                INNER JOIN juego_paquetes p ON p.id = r.paquete_id
                ORDER BY j.nombre ASC, CASE WHEN r.orden IS NULL THEN 1 ELSE 0 END, r.orden ASC, r.id ASC";
        $result = $mysqli->query($sql);
        if ($result instanceof mysqli_result) {
            while ($row = $result->fetch_assoc()) {
                $row['win_points_reward'] = win_points_package_reward($row);
                $rules[] = $row;
            }
        }
        return $rules;
    }
}

if (!function_exists('win_points_fetch_admin_users')) {
    function win_points_fetch_admin_users(mysqli $mysqli): array {
        win_points_ensure_schema();
        $rows = [];
        $phoneSelect = win_points_users_has_phone_column($mysqli) ? 'u.telefono' : "'' AS telefono";
        $sql = "SELECT u.id, u.nombre, u.email, {$phoneSelect}, u.rol
                FROM usuarios u
                WHERE COALESCE(u.rol, 'usuario') <> 'root'
                ORDER BY u.nombre ASC, u.email ASC, u.id ASC";
        $result = $mysqli->query($sql);
        if ($result instanceof mysqli_result) {
            while ($row = $result->fetch_assoc()) {
                $walletState = win_points_fetch_wallet_state($mysqli, (int) ($row['id'] ?? 0));
                $row['balance'] = (int) ($walletState['effective_balance'] ?? 0);
                $row['expiration_days'] = (int) ($walletState['expiration_days'] ?? win_points_expiration_days());
                $row['expiration_reference_at'] = $walletState['expiration_reference_at'] ?? null;
                $row['expires_at'] = $walletState['expires_at'] ?? null;
                $row['expires_at_iso'] = $walletState['expires_at_iso'] ?? null;
                $row['expires_at_label'] = $walletState['expires_at_label'] ?? 'Sin saldo';
                $row['days_remaining'] = $walletState['days_remaining'] ?? null;
                $row['days_remaining_label'] = $walletState['days_remaining_label'] ?? 'Sin saldo';
                $row['is_expired'] = !empty($walletState['is_expired']);
                $row['expiration_status'] = (string) ($walletState['status'] ?? 'no_balance');
                $rows[] = $row;
            }
        }
        usort($rows, static function (array $left, array $right): int {
            $balanceComparison = (int) ($right['balance'] ?? 0) <=> (int) ($left['balance'] ?? 0);
            if ($balanceComparison !== 0) {
                return $balanceComparison;
            }

            $nameComparison = strcasecmp((string) ($left['nombre'] ?? ''), (string) ($right['nombre'] ?? ''));
            if ($nameComparison !== 0) {
                return $nameComparison;
            }

            return strcasecmp((string) ($left['email'] ?? ''), (string) ($right['email'] ?? ''));
        });
        return $rows;
    }
}

if (!function_exists('win_points_fetch_admin_package_options')) {
    function win_points_fetch_admin_package_options(mysqli $mysqli): array {
        win_points_ensure_schema();
        $rows = [];
        $sql = "SELECT p.id, p.juego_id, p.nombre AS paquete_nombre, p.win_points_reward, COALESCE(p.activo, 1) AS paquete_activo,
                       j.nombre AS juego_nombre
                FROM juego_paquetes p
                INNER JOIN juegos j ON j.id = p.juego_id
                ORDER BY j.nombre ASC, CASE WHEN p.orden IS NULL THEN 1 ELSE 0 END, p.orden ASC, p.id ASC";
        $result = $mysqli->query($sql);
        if ($result instanceof mysqli_result) {
            while ($row = $result->fetch_assoc()) {
                $row['win_points_reward'] = win_points_package_reward($row);
                $rows[] = $row;
            }
        }
        return $rows;
    }
}

if (!function_exists('win_points_upsert_redemption_rule')) {
    function win_points_upsert_redemption_rule(mysqli $mysqli, int $packageId, int $rewardPoints, int $requiredPoints, bool $active = true, ?int $order = null): array {
        win_points_ensure_schema();
        if ($packageId <= 0) {
            throw new RuntimeException('Selecciona un paquete valido para la regla de canje.');
        }
        if ($rewardPoints < 0) {
            throw new RuntimeException('El premio por compra no puede ser negativo.');
        }
        if ($requiredPoints <= 0) {
            throw new RuntimeException('Los puntos requeridos deben ser mayores a cero.');
        }

        $packageStmt = $mysqli->prepare('SELECT id, juego_id FROM juego_paquetes WHERE id = ? LIMIT 1');
        if (!$packageStmt) {
            throw new RuntimeException('No se pudo consultar el paquete de canje.');
        }
        $packageStmt->bind_param('i', $packageId);
        $packageStmt->execute();
        $packageResult = $packageStmt->get_result();
        $package = $packageResult instanceof mysqli_result ? $packageResult->fetch_assoc() : null;
        $packageStmt->close();

        if (!$package) {
            throw new RuntimeException('El paquete seleccionado no existe.');
        }

        $gameId = (int) ($package['juego_id'] ?? 0);
        $activeValue = $active ? 1 : 0;

        $packageUpdateStmt = $mysqli->prepare('UPDATE juego_paquetes SET win_points_reward = ? WHERE id = ?');
        if (!$packageUpdateStmt) {
            throw new RuntimeException('No se pudo guardar el premio por compra del paquete.');
        }
        $packageUpdateStmt->bind_param('ii', $rewardPoints, $packageId);
        $packageUpdateStmt->execute();
        $packageUpdateStmt->close();

        $stmt = $mysqli->prepare(
            'INSERT INTO win_points_redemption_rules (juego_id, paquete_id, required_points, activo, orden) VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE juego_id = VALUES(juego_id), required_points = VALUES(required_points), activo = VALUES(activo), orden = VALUES(orden)'
        );
        if (!$stmt) {
            throw new RuntimeException('No se pudo guardar la regla de canje.');
        }
        $stmt->bind_param('iiiii', $gameId, $packageId, $requiredPoints, $activeValue, $order);
        $stmt->execute();
        $stmt->close();

        $savedRule = win_points_fetch_rule_by_package($mysqli, $packageId, false);
        if (!$savedRule) {
            throw new RuntimeException('La regla de canje no pudo recuperarse despues de guardarse.');
        }

        return $savedRule;
    }
}

if (!function_exists('win_points_delete_redemption_rule')) {
    function win_points_delete_redemption_rule(mysqli $mysqli, int $ruleId): bool {
        win_points_ensure_schema();
        if ($ruleId <= 0) {
            return false;
        }
        $stmt = $mysqli->prepare('DELETE FROM win_points_redemption_rules WHERE id = ? LIMIT 1');
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('i', $ruleId);
        $stmt->execute();
        $deleted = $stmt->affected_rows > 0;
        $stmt->close();
        return $deleted;
    }
}

if (!function_exists('win_points_fetch_admin_wallets')) {
    function win_points_fetch_admin_wallets(mysqli $mysqli): array {
        win_points_ensure_schema();
        $rows = [];
        $hasPhoneColumn = win_points_users_has_phone_column($mysqli);
        $phoneSelect = $hasPhoneColumn ? 'u.telefono' : "'' AS telefono";
        $groupBy = $hasPhoneColumn
            ? 'u.id, u.nombre, u.email, u.telefono, w.balance'
            : 'u.id, u.nombre, u.email, w.balance';
        $sql = "SELECT u.id, u.nombre, u.email, {$phoneSelect}, COALESCE(w.balance, 0) AS balance,
                       COALESCE(SUM(CASE WHEN t.transaction_type = 'earn' THEN t.points_delta ELSE 0 END), 0) AS earned_points,
                       COALESCE(SUM(CASE WHEN t.transaction_type = 'redeem' THEN ABS(t.points_delta) ELSE 0 END), 0) AS spent_points,
                       COUNT(t.id) AS total_transactions,
                       MAX(t.created_at) AS last_transaction_at
                FROM usuarios u
                LEFT JOIN win_points_wallets w ON w.user_id = u.id
                LEFT JOIN win_points_transactions t ON t.user_id = u.id
            WHERE COALESCE(u.rol, 'usuario') <> 'root'
                GROUP BY {$groupBy}
                ORDER BY COALESCE(w.balance, 0) DESC, u.nombre ASC, u.email ASC";
        $result = $mysqli->query($sql);
        if ($result instanceof mysqli_result) {
            while ($row = $result->fetch_assoc()) {
                $walletState = win_points_fetch_wallet_state($mysqli, (int) ($row['id'] ?? 0));
                $row['balance'] = (int) ($walletState['effective_balance'] ?? 0);
                $row['expiration_days'] = (int) ($walletState['expiration_days'] ?? win_points_expiration_days());
                $row['expiration_reference_at'] = $walletState['expiration_reference_at'] ?? null;
                $row['expires_at'] = $walletState['expires_at'] ?? null;
                $row['expires_at_iso'] = $walletState['expires_at_iso'] ?? null;
                $row['expires_at_label'] = $walletState['expires_at_label'] ?? 'Sin saldo';
                $row['days_remaining'] = $walletState['days_remaining'] ?? null;
                $row['days_remaining_label'] = $walletState['days_remaining_label'] ?? 'Sin saldo';
                $row['is_expired'] = !empty($walletState['is_expired']);
                $row['expiration_status'] = (string) ($walletState['status'] ?? 'no_balance');
                $rows[] = $row;
            }
        }
        usort($rows, static function (array $left, array $right): int {
            $balanceComparison = (int) ($right['balance'] ?? 0) <=> (int) ($left['balance'] ?? 0);
            if ($balanceComparison !== 0) {
                return $balanceComparison;
            }

            $nameComparison = strcasecmp((string) ($left['nombre'] ?? ''), (string) ($right['nombre'] ?? ''));
            if ($nameComparison !== 0) {
                return $nameComparison;
            }

            return strcasecmp((string) ($left['email'] ?? ''), (string) ($right['email'] ?? ''));
        });
        return $rows;
    }
}

if (!function_exists('win_points_fetch_admin_transactions')) {
    function win_points_fetch_admin_transactions(mysqli $mysqli, int $limit = 100): array {
        win_points_ensure_schema();
        $rows = [];
        $resolvedLimit = max(1, min(300, $limit));
        $sql = "SELECT t.*, u.nombre AS usuario_nombre, u.email AS usuario_email, p.juego_nombre, p.paquete_nombre
                FROM win_points_transactions t
                LEFT JOIN usuarios u ON u.id = t.user_id AND COALESCE(u.rol, 'usuario') <> 'root'
                LEFT JOIN pedidos p ON p.id = t.order_id
                ORDER BY t.created_at DESC, t.id DESC
                LIMIT {$resolvedLimit}";
        $result = $mysqli->query($sql);
        if ($result instanceof mysqli_result) {
            while ($row = $result->fetch_assoc()) {
                $rows[] = $row;
            }
        }
        return $rows;
    }
}
