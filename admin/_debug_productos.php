<?php
require_once __DIR__ . '/../includes/tenant.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/store_config.php';
require_once __DIR__ . '/../includes/recargas_api.php';

if (!isset($_SESSION) || session_status() === PHP_SESSION_NONE) {
    tenant_start_session();
}
if (empty($_SESSION['admin_logged_in'])) {
    http_response_code(403);
    die('Acceso denegado');
}

header('Content-Type: text/plain; charset=utf-8');

try {
    $products = recargas_api_fetch_products();
    $found = 0;
    foreach ($products as $p) {
        $name = (string)($p['nombre'] ?? '');
        $cat  = strtolower((string)($p['categoria'] ?? ''));
        if (
            stripos($name, 'blood') !== false || stripos($name, 'strike') !== false
            || stripos($cat, 'blood') !== false || stripos($cat, 'strike') !== false
        ) {
            $found++;
            echo "=== PRODUCTO: $name ===\n";
            echo "ID: " . ($p['id'] ?? '?') . "\n";
            echo "Categoria: " . ($p['categoria'] ?? '?') . "\n";
            echo "procesamiento_manual: " . json_encode($p['procesamiento_manual'] ?? null) . "\n";
            $campos = $p['campos_requeridos'] ?? null;
            echo "campos_requeridos: " . json_encode($campos, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n\n";
        }
    }
    if ($found === 0) {
        echo "No se encontraron productos con 'blood' o 'strike' en nombre o categoria.\n\n";
        echo "Total productos: " . count($products) . "\n";
        echo "Categorias disponibles:\n";
        $cats = [];
        foreach ($products as $p) {
            $cats[(string)($p['categoria'] ?? '?')] = true;
        }
        foreach (array_keys($cats) as $c) {
            echo "  - $c\n";
        }
    }
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
