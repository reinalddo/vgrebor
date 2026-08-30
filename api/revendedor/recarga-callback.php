<?php
/**
 * api/revendedor/recarga-callback.php — Retorno de PayPal tras aprobar la recarga de saldo.
 * Captura la orden y, si el pago quedó COMPLETADO, acredita el saldo del revendedor AUTOMÁTICAMENTE.
 * Idempotente: solo acredita si la recarga sigue 'pendiente' (evita doble crédito por recarga/refresh).
 *
 * GET ?rec={walletRecargaId}  (PayPal añade &token={orderId})
 */
require_once __DIR__ . '/../../includes/tenant.php';
tenant_start_session();
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/store_config.php';
require_once __DIR__ . '/../../includes/paypal_pay.php';
require_once __DIR__ . '/../../admin/_streaming_schema.php';
require_once __DIR__ . '/../wallet/_helpers.php';
require_once __DIR__ . '/../../includes/stream_mailer.php';

stream_ensure_schema($pdo);

function cb_back(string $msg): void {
    $to = function_exists('app_path') ? app_path('/revendedor/stream/saldo.php') : '/revendedor/stream/saldo.php';
    header('Location: ' . $to . '?msg=' . urlencode($msg));
    exit;
}

$recId = (int) ($_GET['rec'] ?? 0);
if ($recId <= 0) {
    cb_back('Recarga inválida.');
}

$rec = $pdo->prepare("SELECT * FROM wallet_recargas WHERE id = ?");
$rec->execute([$recId]);
$r = $rec->fetch(PDO::FETCH_ASSOC);
if (!$r) {
    cb_back('Recarga no encontrada.');
}
if ($r['estado'] !== 'pendiente') {
    // Ya resuelta (crédito idempotente): no acreditar de nuevo.
    cb_back($r['estado'] === 'aprobada' ? '✓ Tu recarga ya fue acreditada.' : 'Esta recarga ya fue procesada.');
}

$orderId = trim((string) ($r['pasarela_ref'] ?? ''));
$token = trim((string) ($_GET['token'] ?? ''));
if ($orderId === '' && $token !== '') {
    $orderId = $token; // respaldo: PayPal devuelve el id de la orden en 'token'
}
if ($orderId === '') {
    cb_back('No se pudo verificar el pago (falta la referencia de PayPal).');
}
// Coherencia: si vino token, debe coincidir con la orden que creamos.
if ($token !== '' && $orderId !== $token) {
    cb_back('La referencia de pago no coincide.');
}

try {
    // 1) Capturar el pago en PayPal.
    $capture = paypal_pay_capture_order($orderId);
    $status = paypal_pay_extract_status(is_array($capture) ? $capture : []);
    if ($status !== 'completed') {
        cb_back('⚠ El pago no se completó (estado: ' . ($status ?: 'desconocido') . '). Si ya pagaste, avísale al admin.');
    }

    // 2) Acreditar el saldo — SOLO si ganamos la carrera (recarga aún pendiente). Idempotente.
    $pdo->beginTransaction();
    try {
        $claim = $pdo->prepare("UPDATE wallet_recargas SET estado='aprobada', resuelto_en=NOW(), pasarela_ref=? WHERE id=? AND estado='pendiente'");
        $claim->execute([$orderId, $recId]);
        $acreditado = false;
        if ($claim->rowCount() === 1) {
            wallet_acreditar($pdo, (int) $r['usuario_id'], (float) $r['monto'], 'recarga', 'Recarga PayPal (automática)', $orderId);
            $acreditado = true;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }

    // Correo de saldo acreditado (agregado): DESPUÉS del commit, nunca antes — si algo revierte la
    // transacción no debe salir un correo avisando de un crédito que no ocurrió. Best-effort.
    if ($acreditado) {
        try { stream_email_saldo_acreditado($pdo, (int) $r['usuario_id'], ['monto' => (float) $r['monto'], 'metodo' => 'PayPal', 'referencia' => $orderId, 'motivo' => 'Recarga automática']); } catch (Throwable $e) {}
    }

    cb_back('✓ ¡Pago confirmado! Se acreditaron $' . number_format((float) $r['monto'], 2) . ' a tu saldo.');
} catch (Throwable $e) {
    error_log('recarga-callback PayPal: ' . $e->getMessage());
    cb_back('⚠ No pudimos confirmar el pago con PayPal. Si el cobro se hizo, el admin lo acreditará.');
}
