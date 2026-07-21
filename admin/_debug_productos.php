<?php
require_once __DIR__ . '/../includes/tenant.php';
tenant_start_session();
$adminRole = trim((string) ($_SESSION['auth_user']['rol'] ?? ''));
if (!isset($_SESSION['auth_user']) || !in_array($adminRole, ['admin', 'root'], true)) {
    http_response_code(403);
    die('Acceso denegado');
}

require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/store_config.php';
require_once __DIR__ . '/../includes/recargas_api.php';

header('Content-Type: text/plain; charset=utf-8');

echo "Host: " . ($_SERVER['HTTP_HOST'] ?? 'desconocido') . "\n\n";

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
