<?php
require_once __DIR__ . '/includes/tenant.php';
if (session_status() !== PHP_SESSION_ACTIVE) {
    tenant_start_session();
}
require_once __DIR__ . '/includes/store_config.php';

header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: public, max-age=3600');

$name  = (string) store_config_get('nombre_tienda', 'VirtualGaming');
$short = mb_strlen($name, 'UTF-8') > 12 ? mb_substr($name, 0, 12, 'UTF-8') : $name;
$logo  = (string) store_config_get('logo_tienda', '');

$scheme  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host    = $_SERVER['HTTP_HOST'] ?? 'localhost';
$baseUrl = $scheme . '://' . $host;

$startPath = function_exists('app_path') ? app_path('/') : '/';
$scopePath = $startPath;

$icons = [];
if ($logo !== '') {
    $logoUrl  = preg_match('#^https?://#', $logo)
        ? $logo
        : $baseUrl . '/' . ltrim($logo, '/');
    $icons[] = ['src' => $logoUrl, 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any maskable'];
    $icons[] = ['src' => $logoUrl, 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any maskable'];
}

echo json_encode([
    'name'             => $name,
    'short_name'       => $short,
    'start_url'        => $startPath,
    'scope'            => $scopePath,
    'display'          => 'standalone',
    'background_color' => '#0c1220',
    'theme_color'      => '#0c1220',
    'lang'             => 'es',
    'dir'              => 'ltr',
    'icons'            => $icons,
    'description'      => (string) store_config_get('meta_descripcion', 'Tienda de recargas digitales'),
], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
