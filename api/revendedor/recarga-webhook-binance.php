<?php
/**
 * api/revendedor/recarga-webhook-binance.php — Webhook (notifyURL) de CoinPal/Binance para las
 * recargas de saldo del revendedor. CoinPal llama aquí server-to-server al confirmarse el pago.
 * Seguridad = FIRMA (binance_pay_verify_signature), no sesión. Acredita el saldo AUTOMÁTICAMENTE.
 * Idempotente: solo acredita si la recarga sigue 'pendiente'. Responde 'success' (ACK que espera CoinPal).
 */
require_once __DIR__ . '/../../includes/tenant.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/store_config.php';
require_once __DIR__ . '/../../includes/binance_pay.php';
require_once __DIR__ . '/../../admin/_streaming_schema.php';
require_once __DIR__ . '/../wallet/_helpers.php';

header('Content-Type: text/plain; charset=UTF-8');

if (!function_exists('binance_pay_is_enabled') || !binance_pay_is_enabled()) {
    http_response_code(503);
    echo 'disabled';
    exit;
}

// 1) Verificar la firma de CoinPal (rechaza cualquier payload no firmado con la Secret Key).
try {
    $payload = binance_pay_notify_payload($_POST);
} catch (Throwable $e) {
    http_response_code(422);
    echo 'invalid';
    exit;
}

stream_ensure_schema($pdo);

$orderNo = binance_pay_extract_order_no($payload);
$status = (string) ($payload['status'] ?? '');

if ($orderNo === '') {
    http_response_code(404);
    echo 'missing';
    exit;
}

// 2) Buscar la recarga por el orderNo con el que creamos el checkout.
$rec = $pdo->prepare("SELECT * FROM wallet_recargas WHERE pasarela_ref = ? AND metodo = 'Binance' LIMIT 1");
$rec->execute([$orderNo]);
$r = $rec->fetch(PDO::FETCH_ASSOC);
if (!$r) {
    http_response_code(404);
    echo 'missing';
    exit;
}

// 3) Solo acreditar cuando el pago quedó PAGADO. Otros estados (pending/created) → ACK sin acreditar.
if (!binance_pay_is_paid_status($status)) {
    http_response_code(200);
    echo 'success';
    exit;
}

try {
    // Crédito idempotente: solo si la recarga sigue pendiente (evita doble crédito por reintentos del webhook).
    $pdo->beginTransaction();
    try {
        $claim = $pdo->prepare("UPDATE wallet_recargas SET estado='aprobada', resuelto_en=NOW() WHERE id=? AND estado='pendiente'");
        $claim->execute([(int) $r['id']]);
        if ($claim->rowCount() === 1) {
            wallet_acreditar($pdo, (int) $r['usuario_id'], (float) $r['monto'], 'recarga', 'Recarga Binance (automática)', $orderNo);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
    http_response_code(200);
    echo 'success';
} catch (Throwable $e) {
    error_log('recarga-webhook-binance: ' . $e->getMessage());
    http_response_code(500);
    echo 'error';
}
