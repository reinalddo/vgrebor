<?php
/**
 * api/revendedor/recargar-saldo.php — Inicia una recarga de SALDO del revendedor con la pasarela
 * que YA tiene Reborxstore (PayPal o Binance/CoinPal), reusando includes/paypal_pay.php /
 * includes/binance_pay.php + las credenciales de store_config. Crea una wallet_recarga 'pendiente',
 * crea la orden en la pasarela y redirige al pago. La confirmación acredita el saldo automáticamente:
 *  - PayPal  → al volver (api/revendedor/recarga-callback.php captura y acredita).
 *  - Binance → por webhook (api/revendedor/recarga-webhook-binance.php verifica firma y acredita).
 *
 * POST { monto, pasarela = 'paypal' | 'binance' }
 */
require_once __DIR__ . '/../../includes/tenant.php';
tenant_start_session();
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/store_config.php';
require_once __DIR__ . '/../../includes/paypal_pay.php';
require_once __DIR__ . '/../../includes/binance_pay.php';
require_once __DIR__ . '/../../admin/_streaming_schema.php';
require_once __DIR__ . '/../wallet/_helpers.php';

stream_ensure_schema($pdo);

function rec_back(string $msg): void {
    $to = function_exists('app_path') ? app_path('/revendedor/stream/saldo.php') : '/revendedor/stream/saldo.php';
    header('Location: ' . $to . '?msg=' . urlencode($msg));
    exit;
}

$user = $_SESSION['auth_user'] ?? null;
$rol = is_array($user) ? strtolower(trim((string) ($user['rol'] ?? ''))) : '';
if (!is_array($user) || $rol !== 'revendedor') {
    header('Location: ' . (function_exists('app_path') ? app_path('/login.php') : '/login.php'));
    exit;
}
$uid = (int) $user['id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    rec_back('Solicitud inválida.');
}

$monto = round((float) ($_POST['monto'] ?? 0), 2);
if ($monto < 1) {
    rec_back('⚠ El monto mínimo de recarga es $1.');
}
$pasarela = strtolower(trim((string) ($_POST['pasarela'] ?? 'paypal')));
if (!in_array($pasarela, ['paypal', 'binance'], true)) {
    $pasarela = 'paypal';
}

// ── Binance / CoinPal (confirmación por webhook) ──────────────────────────────
if ($pasarela === 'binance') {
    if (!function_exists('binance_pay_is_enabled') || !binance_pay_is_enabled() || !binance_pay_is_configured()) {
        rec_back('⚠ El pago automático con Binance no está disponible. Usa otro método (lo aprueba el admin).');
    }
    // El orderNo debe ser único y con él casamos el webhook.
    $orderNo = binance_pay_make_order_no('S'); // p.ej. S...
    $pdo->prepare("INSERT INTO wallet_recargas (usuario_id, monto, metodo, pasarela_ref, estado) VALUES (?,?,?,?, 'pendiente')")
        ->execute([$uid, $monto, 'Binance', $orderNo]);
    try {
        $resp = binance_pay_create_checkout([
            'order_no'          => $orderNo,
            'order_currency'    => 'USD',
            'order_amount'      => number_format($monto, 2, '.', ''),
            'notify_url'        => app_url('/api/revendedor/recarga-webhook-binance.php'),
            'redirect_url'      => app_url('/revendedor/stream/saldo.php?msg=' . urlencode('Verificando tu pago… tu saldo se acreditará al confirmarse.')),
            'payer_ip'          => binance_pay_client_ip($_SERVER),
            'order_description' => 'Recarga de saldo revendedor',
        ]);
        $checkoutUrl = binance_pay_extract_checkout_url(is_array($resp) ? $resp : []);
        if ($checkoutUrl === '') {
            throw new RuntimeException('CoinPal no devolvió URL de pago.');
        }
        header('Location: ' . $checkoutUrl);
        exit;
    } catch (Throwable $e) {
        error_log('recargar-saldo Binance: ' . $e->getMessage());
        rec_back('⚠ No se pudo iniciar el pago con Binance. Intenta de nuevo o usa otro método.');
    }
}

// ── PayPal (confirmación al volver / captura) ─────────────────────────────────
if (!function_exists('paypal_pay_checkout_is_enabled') || !paypal_pay_checkout_is_enabled() || !paypal_pay_is_configured()) {
    rec_back('⚠ El pago automático con PayPal no está disponible ahora. Usa otro método (lo aprueba el admin).');
}

$pdo->prepare("INSERT INTO wallet_recargas (usuario_id, monto, metodo, estado) VALUES (?,?,?, 'pendiente')")
    ->execute([$uid, $monto, 'PayPal']);
$recId = (int) $pdo->lastInsertId();

try {
    $order = paypal_pay_create_order([
        'currency'       => 'USD',
        'amount'         => $monto,
        'local_order_id' => $recId,
        'description'    => 'Recarga de saldo revendedor #' . $recId,
        'return_url'     => app_url('/api/revendedor/recarga-callback.php?rec=' . $recId),
        'cancel_url'     => app_url('/revendedor/stream/saldo.php?msg=' . urlencode('Recarga cancelada.')),
    ]);
    $orderId = paypal_pay_extract_order_id(is_array($order) ? $order : []);
    $approve = paypal_pay_extract_approval_url(is_array($order) ? $order : []);
    if ($orderId === '' || $approve === '') {
        throw new RuntimeException('Respuesta de PayPal incompleta.');
    }
    $pdo->prepare("UPDATE wallet_recargas SET pasarela_ref = ? WHERE id = ?")->execute([$orderId, $recId]);
    header('Location: ' . $approve);
    exit;
} catch (Throwable $e) {
    error_log('recargar-saldo PayPal: ' . $e->getMessage());
    rec_back('⚠ No se pudo iniciar el pago con PayPal. Intenta de nuevo o usa otro método.');
}
