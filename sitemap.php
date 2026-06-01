<?php

require_once __DIR__ . '/includes/db_connect.php';
require_once __DIR__ . '/includes/store_config.php';
require_once __DIR__ . '/includes/slugify.php';
require_once __DIR__ . '/includes/tenant.php';

header('Content-Type: application/xml; charset=UTF-8');

$urls = [];
$seen = [];

$addUrl = static function (string $absoluteUrl) use (&$urls, &$seen): void {
    $normalized = trim($absoluteUrl);
    if ($normalized === '' || isset($seen[$normalized])) {
        return;
    }

    $seen[$normalized] = true;
    $urls[] = $normalized;
};

$addUrl(app_url('/'));
$addUrl(app_url('/juegos'));
$addUrl(app_url('/populares'));

if (store_config_get('instrucciones_influencer', '0') === '1') {
    $addUrl(app_url('/quiero-unirme'));
}

$sql = "SELECT j.id, j.nombre, j.slug\n"
    . "FROM juegos j\n"
    . "WHERE COALESCE(j.activo, 1) = 1\n"
    . "AND EXISTS (\n"
    . "    SELECT 1\n"
    . "    FROM juego_paquetes jp\n"
    . "    WHERE jp.juego_id = j.id AND COALESCE(jp.activo, 1) = 1\n"
    . ")\n"
    . "ORDER BY CASE WHEN j.orden IS NULL THEN 1 ELSE 0 END, j.orden ASC, j.id ASC";

$result = $mysqli->query($sql);
if ($result instanceof mysqli_result) {
    while ($game = $result->fetch_assoc()) {
        $addUrl(app_url(game_route_path($game)));
    }
    $result->free();
}

echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
<?php foreach ($urls as $url): ?>
  <url>
    <loc><?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?></loc>
  </url>
<?php endforeach; ?>
</urlset>