<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/public_catalog_search.php';

$query = trim((string) ($_GET['q'] ?? ''));
$limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 8;

if ($query === '' || (function_exists('mb_strlen') ? mb_strlen($query, 'UTF-8') : strlen($query)) < 2) {
    echo json_encode([
        'ok' => true,
        'query' => $query,
        'items' => [],
        'games' => [],
        'packages' => [],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$results = public_catalog_search_results($mysqli, $query, max(1, min(20, $limit)));
echo json_encode([
    'ok' => true,
    'query' => $results['query'],
    'items' => array_slice($results['items'], 0, max(1, min(20, $limit))),
    'games' => $results['games'],
    'packages' => $results['packages'],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);