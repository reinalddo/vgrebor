<?php
// api/centralone_webhook.php — Fase 1 (2026-10-09).
//
// Receptor del webhook de Central One. Por ahora es un stub a propósito:
// verifica la firma, responde 2xx rápido (como exige la doc: <10s, sin
// redirecciones) y deja el evento guardado para poder revisarlo — pero
// todavía NO actualiza ningún pedido ni dispara ninguna entrega. Eso es
// la Fase 2 (pendiente de aprobación), porque implica tocar el motor real
// de pedidos (api/pedidos.php).
//
// Registrar en el portal: https://TU-DOMINIO/api/centralone_webhook.php
// (debe ser HTTPS público; en reborxstore.com sería
// https://reborxstore.com/api/centralone_webhook.php). El secreto que
// muestra el portal al registrar la URL se pega en Configuración > Datos
// API > "Secreto del webhook Central One".

require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/centralone_api.php';

header('Content-Type: application/json; charset=utf-8');

$rawBody = (string) file_get_contents('php://input');
$timestampHeader = (string) ($_SERVER['HTTP_X_CENTRALONE_TIMESTAMP'] ?? '');
$signatureHeader = (string) ($_SERVER['HTTP_X_CENTRALONE_SIGNATURE'] ?? '');

$valido = centralone_webhook_verify($rawBody, $timestampHeader, $signatureHeader);

if (!$valido) {
    // 401 y no 200: si devolviéramos 200 a una firma inválida, Central One
    // dejaría de reintentar un aviso real que falló por otra razón confusa
    // con esto. No se revela el motivo exacto (firma vs timestamp vs
    // secreto vacío) para no ayudar a adivinar el secreto.
    error_log('TVG centralone webhook: firma invalida o faltante');
    http_response_code(401);
    echo json_encode(['status' => 'invalid_signature']);
    exit;
}

$evento = json_decode($rawBody, true);
if (!is_array($evento)) {
    http_response_code(400);
    echo json_encode(['status' => 'invalid_json']);
    exit;
}

$tipo = (string) ($evento['type'] ?? $evento['event'] ?? '');

// Se guardan los últimos eventos recibidos (hasta 50) para poder revisarlos
// desde el panel más adelante — no se procesa nada todavía.
$historial = centralone_cache_get('webhook_historial', 30 * 24 * 3600) ?? [];
array_unshift($historial, [
    'recibido_en' => date('c'),
    'tipo' => $tipo,
    'evento' => $evento,
]);
$historial = array_slice($historial, 0, 50);
centralone_cache_put('webhook_historial', $historial);

http_response_code(200);
echo json_encode(['status' => 'received']);
