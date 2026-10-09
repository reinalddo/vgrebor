<?php
// api/centralone_webhook.php — Fase 2 (2026-10-09).
//
// Receptor del webhook de Central One. Verifica la firma con el cuerpo
// CRUDO (tiene que ser antes de json_decode, la firma es sobre el texto
// exacto) y, si es válida, delega en la acción 'centralone_webhook' de
// api/pedidos.php — el mismo despachador que usan "Sincronizar" y
// "Reintentar" del admin (centralone_sync_and_persist()), así que un pedido
// se resuelve solo apenas Central One avisa, sin esperar a que alguien lo
// revise a mano.
//
// Registrar en el portal: https://TU-DOMINIO/api/centralone_webhook.php
// (debe ser HTTPS público; en reborxstore.com sería
// https://reborxstore.com/api/centralone_webhook.php). El secreto que
// muestra el portal al registrar la URL se pega en Configuración > Datos
// API > "Secreto del webhook Central One".

require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/centralone_api.php';

$rawBody = (string) file_get_contents('php://input');
$timestampHeader = (string) ($_SERVER['HTTP_X_CENTRALONE_TIMESTAMP'] ?? '');
$signatureHeader = (string) ($_SERVER['HTTP_X_CENTRALONE_SIGNATURE'] ?? '');

if (!centralone_webhook_verify($rawBody, $timestampHeader, $signatureHeader)) {
    // 401 y no 200: si devolviéramos 200 a una firma inválida, Central One
    // dejaría de reintentar un aviso real que falló por otra razón, confuso
    // con esto. No se revela el motivo exacto (firma vs timestamp vs
    // secreto vacío) para no ayudar a adivinar el secreto.
    error_log('TVG centralone webhook: firma invalida o faltante');
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'invalid_signature']);
    exit;
}

$evento = json_decode($rawBody, true);
if (!is_array($evento)) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'invalid_json']);
    exit;
}

$tipoEvento = (string) ($evento['event'] ?? '');
$eventId = trim((string) ($evento['event_id'] ?? ($_SERVER['HTTP_X_CENTRALONE_EVENT_ID'] ?? '')));

// Historial de los últimos 50 eventos recibidos, para poder revisarlos
// aparte del efecto real que tuvieron sobre el pedido.
$historial = centralone_cache_get('webhook_historial', 30 * 24 * 3600) ?? [];
array_unshift($historial, [
    'recibido_en' => date('c'),
    'tipo' => $tipoEvento,
    'evento' => $evento,
]);
centralone_cache_put('webhook_historial', array_slice($historial, 0, 50));

// webhook.test: lo manda el portal para probar el endpoint. "No pertenece a
// ninguna orden" (sin order.reference_code) — si se dejara seguir, la acción
// centralone_webhook de pedidos.php respondería 422 por "sin reference_code",
// y el botón de prueba del portal vería un fallo con todo bien configurado.
if ($tipoEvento === 'webhook.test') {
    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'test_ok']);
    exit;
}

// Deduplicar por event_id: "la entrega es al menos una vez... el mismo
// event_id puede llegar dos veces: procésalo una sola" (doc de Central One).
if (centralone_webhook_event_already_processed($eventId)) {
    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'duplicate_ignored']);
    exit;
}
if ($eventId !== '') {
    centralone_webhook_mark_event_processed($eventId);
}

$_GET['action'] = 'centralone_webhook';
$GLOBALS['centralone_webhook_event'] = $evento;
require __DIR__ . '/pedidos.php';
