<?php
// Estado de las APIs (BNC, Binance, CONEC, TiendaGiftVen, FullImpulso) para las tarjetas del
// dashboard. Devuelve JSON. SOLO LECTURA y solo admin/root (son datos de cuentas de proveedores).
//
//   ?g=lista                       → tarjetas que aplican a esta tienda: [{key, title}]
//   ?g=<bnc|binance|conec|giftven|fullimpulso>[&forzar=1]
//                                  → estado de esa tarjeta (usa la caché salvo que se fuerce; ver
//                                    includes/dashboard_api_status.php)
//
// Cada tarjeta se pide por separado desde el navegador, así una API lenta o caída nunca retrasa
// el dashboard ni a las demás tarjetas.
require_once __DIR__ . '/../includes/tenant.php';
tenant_start_session();
$adminRole = trim((string) ($_SESSION['auth_user']['rol'] ?? ''));
$isAllowed = isset($_SESSION['auth_user']) && in_array($adminRole, ['admin', 'root'], true);

// Se libera el bloqueo de sesión enseguida: si no, las tarjetas se consultarían una tras otra
// (PHP serializa las solicitudes de una misma sesión) en vez de en paralelo.
session_write_close();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!$isAllowed) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'No autorizado.']);
    exit;
}

require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/store_config.php';
require_once __DIR__ . '/../includes/dashboard_api_status.php';

$gadget = trim((string) ($_GET['g'] ?? ''));

try {
    if ($gadget === 'lista') {
        echo json_encode(['ok' => true, 'gadgets' => dash_api_gadgets_list()], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Solo se consultan tarjetas que aplican (APIs configuradas): evita llamadas sin credenciales.
    $applicable = array_column(dash_api_gadgets_list(), 'key');
    if (!in_array($gadget, $applicable, true)) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Tarjeta no disponible en esta tienda.']);
        exit;
    }

    $status = dash_api_gadget_status($gadget, isset($_GET['forzar']) && (string) $_GET['forzar'] === '1');
    echo json_encode(['ok' => true] + $status, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('TVG api_gadgets ' . $gadget . ': ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'No se pudo consultar el estado.']);
}
