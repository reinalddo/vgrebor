<?php

function currency_db(): mysqli {
    global $mysqli;

    if (!isset($mysqli) || !($mysqli instanceof mysqli)) {
        require_once __DIR__ . '/db_connect.php';
    }

    return $mysqli;
}

function currency_ensure_schema(): void {
    static $initialized = false;

    if ($initialized) {
        return;
    }

    $mysqli = currency_db();
    $tableResult = $mysqli->query("SHOW TABLES LIKE 'monedas'");
    if (!($tableResult instanceof mysqli_result) || $tableResult->num_rows === 0) {
        $initialized = true;
        return;
    }

    $columnResult = $mysqli->query("SHOW COLUMNS FROM monedas LIKE 'mostrar_decimales'");
    if (!($columnResult instanceof mysqli_result) || $columnResult->num_rows === 0) {
        $mysqli->query("ALTER TABLE monedas ADD COLUMN mostrar_decimales TINYINT(1) NOT NULL DEFAULT 1 AFTER activo");
    }

    $initialized = true;
}

function currency_normalize_code(string $code): string {
    return strtoupper(trim($code));
}

function currency_find_by_id(int $currencyId): ?array {
    currency_ensure_schema();

    if ($currencyId <= 0) {
        return null;
    }

    $mysqli = currency_db();
    $stmt = $mysqli->prepare('SELECT * FROM monedas WHERE id = ? LIMIT 1');
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('i', $currencyId);
    $stmt->execute();
    $res = $stmt->get_result();
    $currency = $res instanceof mysqli_result ? $res->fetch_assoc() : null;
    $stmt->close();

    return $currency ?: null;
}

function currency_find_by_code(string $code): ?array {
    currency_ensure_schema();

    $normalizedCode = currency_normalize_code($code);
    if ($normalizedCode === '') {
        return null;
    }

    $mysqli = currency_db();
    $stmt = $mysqli->prepare('SELECT * FROM monedas WHERE UPPER(clave) = ? LIMIT 1');
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('s', $normalizedCode);
    $stmt->execute();
    $res = $stmt->get_result();
    $currency = $res instanceof mysqli_result ? $res->fetch_assoc() : null;
    $stmt->close();

    return $currency ?: null;
}

function currency_ensure_code(string $code, string $name = '', float $rate = 1.0, bool $showDecimals = true, bool $active = true): ?array {
    currency_ensure_schema();

    $normalizedCode = currency_normalize_code($code);
    if ($normalizedCode === '') {
        return null;
    }

    $existing = currency_find_by_code($normalizedCode);
    $mysqli = currency_db();
    $activeFlag = $active ? 1 : 0;
    $showDecimalsFlag = $showDecimals ? 1 : 0;

    if ($existing === null) {
        $resolvedName = trim($name) !== '' ? trim($name) : $normalizedCode;
        $stmt = $mysqli->prepare('INSERT INTO monedas (nombre, clave, tasa, es_base, activo, mostrar_decimales) VALUES (?, ?, ?, 0, ?, ?)');
        if ($stmt) {
            $stmt->bind_param('ssdii', $resolvedName, $normalizedCode, $rate, $activeFlag, $showDecimalsFlag);
            $stmt->execute();
            $stmt->close();
        }

        return currency_find_by_code($normalizedCode);
    }

    $currencyId = isset($existing['id']) ? (int) $existing['id'] : 0;
    if ($currencyId > 0 && ((int) ($existing['activo'] ?? 1) !== $activeFlag || (int) ($existing['mostrar_decimales'] ?? 1) !== $showDecimalsFlag)) {
        $stmt = $mysqli->prepare('UPDATE monedas SET activo = ?, mostrar_decimales = ? WHERE id = ? LIMIT 1');
        if ($stmt) {
            $stmt->bind_param('iii', $activeFlag, $showDecimalsFlag, $currencyId);
            $stmt->execute();
            $stmt->close();
        }
    }

    return currency_find_by_code($normalizedCode);
}

function currency_should_show_decimals(?array $currency): bool {
    if (!is_array($currency)) {
        return true;
    }

    return (int) ($currency['mostrar_decimales'] ?? 1) === 1;
}

function currency_fraction_digits(?array $currency): int {
    return currency_should_show_decimals($currency) ? 2 : 0;
}

function currency_apply_amount_rule(float $amount, ?array $currency): float {
    if (currency_should_show_decimals($currency)) {
        return round($amount, 2);
    }

    return $amount >= 0 ? floor($amount) : ceil($amount);
}

function currency_convert_from_base(float $baseAmount, ?array $currency): float {
    $rate = is_array($currency) ? (float) ($currency['tasa'] ?? 1) : 1.0;
    return currency_apply_amount_rule($baseAmount * $rate, $currency);
}

function currency_format_amount(float $amount, ?array $currency): string {
    $normalizedAmount = currency_apply_amount_rule($amount, $currency);
    $fractionDigits = currency_fraction_digits($currency);
    return number_format($normalizedAmount, $fractionDigits, '.', ',');
}

function currency_format_amount_by_code(float $amount, string $currencyCode): string {
    return currency_format_amount($amount, currency_find_by_code($currencyCode));
}
