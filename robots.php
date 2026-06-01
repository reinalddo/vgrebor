<?php

require_once __DIR__ . '/includes/tenant.php';

header('Content-Type: text/plain; charset=UTF-8');

$lines = [
    'User-agent: *',
    'Allow: /',
    'Disallow: /admin/',
    'Disallow: /api/',
    'Disallow: /logout',
    'Disallow: /login.php',
    'Disallow: /reset.php',
    'Disallow: /register_user.php',
    'Disallow: /google-login.php',
    'Disallow: /google-callback.php',
    'Disallow: /checkout.php',
    'Disallow: /checkout_quick.php',
    'Disallow: /carrito_acciones.php',
    'Disallow: /aplicar_cupon.php',
    'Disallow: /ajax_',
    '',
    'Sitemap: ' . app_url('/sitemap.xml'),
];

echo implode("\n", $lines) . "\n";