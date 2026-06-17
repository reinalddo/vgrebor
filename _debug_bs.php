<?php
$_SERVER['HTTP_HOST'] = 'localhost';
require_once __DIR__ . '/includes/db_connect.php';
require_once __DIR__ . '/includes/tenant.php';
require_once __DIR__ . '/includes/store_config.php';
require_once __DIR__ . '/includes/recargas_api.php';

try {
    $products = recargas_api_fetch_products();
    foreach ($products as $p) {
        $name = (string)($p['nombre'] ?? '');
        if (stripos($name, 'blood') !== false || stripos($name, 'strike') !== false) {
            echo "=== PRODUCTO: $name ===\n";
            echo "ID: " . ($p['id'] ?? '?') . "\n";
            echo "Categoria: " . ($p['categoria'] ?? '?') . "\n";
            echo "procesamiento_manual: " . json_encode($p['procesamiento_manual'] ?? null) . "\n";
            echo "campos_requeridos: " . json_encode($p['campos_requeridos'] ?? 'NO_DEFINIDO', JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n\n";
        }
    }
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
