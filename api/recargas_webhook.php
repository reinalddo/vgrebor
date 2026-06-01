<?php
$rawBody = file_get_contents('php://input');
$payload = json_decode((string) $rawBody, true);
if (!is_array($payload)) {
    $payload = [];
}

$_POST = array_merge($_POST, $payload, ['action' => 'provider_webhook']);
require __DIR__ . '/pedidos.php';
