<?php
header('Content-Type: application/json');
// Manejo global de errores de conexión
try {
    require_once __DIR__ . '/../includes/db_connect.php';
    require_once __DIR__ . '/../includes/currency.php';
    currency_ensure_schema();
    if (!isset($mysqli) || $mysqli->connect_errno) {
        throw new Exception('Error de conexión a la base de datos: ' . ($mysqli->connect_error ?? 'Desconocido'));
    }
} catch (Exception $e) {
    $errorMsg = date('Y-m-d H:i:s') . " | ERROR: " . $e->getMessage() . "\n";
    file_put_contents(__DIR__ . '/log_cupon.txt', $errorMsg, FILE_APPEND);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
    exit;
}

function normalize_coupon_code(string $value): string {
    return strtoupper(trim($value));
}

function is_valid_coupon_code(string $value): bool {
    return $value !== '' && preg_match('/^[A-Za-z0-9]+$/', $value) === 1;
}

function coupon_game_scope_enabled(mysqli $mysqli): bool {
    $result = $mysqli->query("SHOW TABLES LIKE 'configuracion_general'");
    if (!($result instanceof mysqli_result) || $result->num_rows === 0) {
        return false;
    }

    $stmt = $mysqli->prepare('SELECT valor FROM configuracion_general WHERE clave = ? LIMIT 1');
    if (!$stmt) {
        return false;
    }

    $key = 'cupon_x_juegos';
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    return trim((string) ($row['valor'] ?? '0')) === '1';
}

function coupon_ensure_game_scope_column(mysqli $mysqli): void {
    $result = $mysqli->query("SHOW COLUMNS FROM cupones LIKE 'juegos_restringidos_json'");
    if (!($result instanceof mysqli_result) || $result->num_rows === 0) {
        $mysqli->query("ALTER TABLE cupones ADD COLUMN juegos_restringidos_json LONGTEXT NULL AFTER permitir_acumular_puntos");
    }
}

function coupon_selected_game_ids(?string $json): array {
    if (!is_string($json) || trim($json) === '') {
        return [];
    }

    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        return [];
    }

    $gameIds = [];
    foreach ($decoded as $gameId) {
        $normalizedId = (int) $gameId;
        if ($normalizedId > 0) {
            $gameIds[$normalizedId] = true;
        }
    }

    return array_map('intval', array_keys($gameIds));
}

function coupon_applies_to_game(mysqli $mysqli, array $coupon, int $gameId): bool {
    if (!coupon_game_scope_enabled($mysqli)) {
        return true;
    }

    coupon_ensure_game_scope_column($mysqli);
    $selectedGameIds = coupon_selected_game_ids($coupon['juegos_restringidos_json'] ?? null);
    if (empty($selectedGameIds)) {
        return true;
    }

    return $gameId > 0 && in_array($gameId, $selectedGameIds, true);
}

$codeInput = isset($_POST['code']) ? trim($_POST['code']) : '';
$code = normalize_coupon_code($codeInput);
$gameId = isset($_POST['game_id']) ? intval($_POST['game_id']) : 0;
$pack_price = isset($_POST['pack_price']) ? floatval($_POST['pack_price']) : 0;
$currencyCode = isset($_POST['currency']) ? trim((string) $_POST['currency']) : '';
$currency = currency_find_by_code($currencyCode);
$pack_price = currency_apply_amount_rule($pack_price, $currency);

if ($code === '') {
    $errorMsg = date('Y-m-d H:i:s') . " | ERROR: Cupón vacío.\n";
    file_put_contents(__DIR__ . '/log_cupon.txt', $errorMsg, FILE_APPEND);
    echo json_encode(['success' => false, 'message' => 'Cupón vacío.']);
    exit;
}

if (!is_valid_coupon_code($codeInput)) {
    $errorMsg = date('Y-m-d H:i:s') . " | ERROR: Cupón con formato inválido.\n";
    file_put_contents(__DIR__ . '/log_cupon.txt', $errorMsg, FILE_APPEND);
    echo json_encode(['success' => false, 'message' => 'El cupón solo puede contener letras y números, sin espacios ni caracteres especiales.']);
    exit;
}

// LOG TEMPORAL PARA DEPURAR
file_put_contents(__DIR__ . '/log_cupon.txt', date('Y-m-d H:i:s') . " | code: $code | pack_price: $pack_price\n", FILE_APPEND);

$stmt = $mysqli->prepare('SELECT * FROM cupones WHERE codigo = ? LIMIT 1');
$stmt->bind_param('s', $code);
$stmt->execute();
$res = $stmt->get_result();
$cupon = $res->fetch_assoc();
$stmt->close();

if (!$cupon) {
    $errorMsg = date('Y-m-d H:i:s') . " | ERROR: Cupón inexistente.\n";
    file_put_contents(__DIR__ . '/log_cupon.txt', $errorMsg, FILE_APPEND);
    echo json_encode(['success' => false, 'message' => 'Cupón inexistente.']);
    exit;
}
if ($cupon['activo'] != 1) {
    $errorMsg = date('Y-m-d H:i:s') . " | ERROR: Cupón inactivo.\n";
    file_put_contents(__DIR__ . '/log_cupon.txt', $errorMsg, FILE_APPEND);
    echo json_encode(['success' => false, 'message' => 'Cupón inactivo.']);
    exit;
}
if (!is_null($cupon['fecha_expiracion']) && strtotime($cupon['fecha_expiracion']) < time()) {
    $errorMsg = date('Y-m-d H:i:s') . " | ERROR: Cupón expirado.\n";
    file_put_contents(__DIR__ . '/log_cupon.txt', $errorMsg, FILE_APPEND);
    echo json_encode(['success' => false, 'message' => 'Cupón expirado.']);
    exit;
}
if (!is_null($cupon['limite_usos']) && $cupon['limite_usos'] > 0 && $cupon['usos_actuales'] >= $cupon['limite_usos']) {
    $errorMsg = date('Y-m-d H:i:s') . " | ERROR: Cupón agotado.\n";
    file_put_contents(__DIR__ . '/log_cupon.txt', $errorMsg, FILE_APPEND);
    echo json_encode(['success' => false, 'message' => 'Cupón agotado.']);
    exit;
}
if (!coupon_applies_to_game($mysqli, $cupon, $gameId)) {
    $errorMsg = date('Y-m-d H:i:s') . " | ERROR: Cupón no disponible para el juego {$gameId}.\n";
    file_put_contents(__DIR__ . '/log_cupon.txt', $errorMsg, FILE_APPEND);
    echo json_encode(['success' => false, 'message' => 'Este cupón no está activo para este juego.']);
    exit;
}

$descuento = 0;
if ($cupon['tipo_descuento'] === 'porcentaje') {
    $descuento = $pack_price * ($cupon['valor_descuento'] / 100);
} else {
    $descuento = floatval($cupon['valor_descuento']);
}

$nuevo_total = currency_apply_amount_rule(max(0, $pack_price - $descuento), $currency);
$descuento = currency_apply_amount_rule(max(0, $pack_price - $nuevo_total), $currency);

echo json_encode([
    'success' => true,
    'message' => 'Cupón aplicado correctamente.',
    'descuento' => $descuento,
    'nuevo_total' => $nuevo_total,
    'tipo_descuento' => $cupon['tipo_descuento'],
    'valor_descuento' => $cupon['valor_descuento']
]);
exit;
