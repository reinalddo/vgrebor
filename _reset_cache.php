<?php
// Súbelo a la carpeta public_html, ábrelo UNA vez en el navegador
// (https://reborxstore.com/_reset_cache.php) y luego BÓRRALO del servidor.
header('Content-Type: text/plain; charset=utf-8');
$hecho = [];
if (function_exists('opcache_reset')) { opcache_reset(); $hecho[] = 'OPcache reiniciado ✔'; }
else { $hecho[] = 'OPcache no está activo (nada que reiniciar).'; }
if (function_exists('apcu_clear_cache')) { apcu_clear_cache(); $hecho[] = 'APCu limpiado ✔'; }
clearstatcache(true);
$hecho[] = 'clearstatcache ✔';
echo implode("\n", $hecho) . "\n\nLISTO. Ahora BORRA este archivo del servidor y vuelve a probar.\n";
