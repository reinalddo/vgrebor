<?php
// admin/verificar_catalogo_proveedores.php
// Herramienta de diagnóstico: compara los product ID guardados en
// juego_paquetes (GiftVen y RecargasAmérica) contra el catálogo EN VIVO de
// cada proveedor, para detectar de una sola vez todos los paquetes cuyo ID
// remoto ya no existe (la causa de "El producto API configurado ya no está
// disponible en el catálogo remoto." reportada en varios pedidos: #6814,
// #6834...). Antes había que esperar a que un cliente reportara la falla
// pedido por pedido.
require_once '../includes/db_connect.php';
require_once '../includes/tenant.php';
tenant_start_session();
$adminRole = trim((string) ($_SESSION['auth_user']['rol'] ?? ''));
if (!isset($_SESSION['auth_user']) || !in_array($adminRole, ['admin', 'root'], true)) {
    header('Location: ' . app_path('/login.php'));
    exit();
}
require_once '../includes/recargas_api.php';
require_once '../includes/recargasamerica_api.php';

function verificar_catalogo_giftven(mysqli $mysqli): array {
    if (!recargas_api_is_configured()) {
        return ['error' => 'La API KEY de GiftVen no está configurada.', 'rows' => []];
    }

    try {
        $liveProducts = recargas_api_fetch_products();
    } catch (Throwable $e) {
        return ['error' => 'No se pudo consultar el catálogo en vivo de GiftVen: ' . $e->getMessage(), 'rows' => []];
    }

    $liveById = [];
    foreach ($liveProducts as $product) {
        if (is_array($product)) {
            $liveById[(int) ($product['id'] ?? 0)] = $product;
        }
    }

    // Solo paquetes con api_provider EXPLÍCITAMENTE 'giftven' — los que
    // tienen la columna vacía se revisan aparte en
    // verificar_paquetes_ambiguos(), porque asumir "vacío = GiftVen" es
    // precisamente el bug que causaba que paquetes de RecargasAmérica mal
    // etiquetados perdieran su margen de ganancia (ver #6823).
    $rows = [];
    $res = $mysqli->query(
        "SELECT jp.id, jp.nombre AS paquete_nombre, jp.paquete_api, jp.precio, j.id AS juego_id, j.nombre AS juego_nombre
         FROM juego_paquetes jp
         JOIN juegos j ON j.id = jp.juego_id
         WHERE jp.api_provider = 'giftven' AND jp.paquete_api > 0
         ORDER BY j.nombre, jp.nombre"
    );
    while ($row = $res->fetch_assoc()) {
        $productId = (int) $row['paquete_api'];
        $liveProduct = $liveById[$productId] ?? null;
        $rows[] = [
            'paquete_id' => (int) $row['id'],
            'paquete_nombre' => (string) $row['paquete_nombre'],
            'juego_id' => (int) $row['juego_id'],
            'juego_nombre' => (string) $row['juego_nombre'],
            'product_id' => $productId,
            'precio_local' => (float) $row['precio'],
            'encontrado' => $liveProduct !== null,
            'live_nombre' => $liveProduct['nombre'] ?? null,
            'live_precio' => isset($liveProduct['precio']) ? (float) $liveProduct['precio'] : null,
        ];
    }

    return ['error' => null, 'rows' => $rows, 'live_by_id' => $liveById];
}

function verificar_catalogo_recargasamerica(mysqli $mysqli): array {
    if (!recargasamerica_api_is_configured()) {
        return ['error' => 'La API KEY de RecargasAmérica no está configurada.', 'rows' => []];
    }

    try {
        $liveProducts = recargasamerica_api_fetch_products_pins();
    } catch (Throwable $e) {
        return ['error' => 'No se pudo consultar el catálogo en vivo de RecargasAmérica: ' . $e->getMessage(), 'rows' => []];
    }

    $liveById = [];
    foreach ($liveProducts as $product) {
        if (is_array($product)) {
            $liveById[(int) ($product['id'] ?? 0)] = $product;
        }
    }

    $rows = [];
    $res = $mysqli->query(
        "SELECT jp.id, jp.nombre AS paquete_nombre, jp.paquete_api, jp.precio, j.id AS juego_id, j.nombre AS juego_nombre
         FROM juego_paquetes jp
         JOIN juegos j ON j.id = jp.juego_id
         WHERE jp.api_provider = 'recargasamerica' AND jp.paquete_api > 0
         ORDER BY j.nombre, jp.nombre"
    );
    while ($row = $res->fetch_assoc()) {
        $productId = (int) $row['paquete_api'];
        $liveProduct = $liveById[$productId] ?? null;
        $rows[] = [
            'paquete_id' => (int) $row['id'],
            'paquete_nombre' => (string) $row['paquete_nombre'],
            'juego_id' => (int) $row['juego_id'],
            'juego_nombre' => (string) $row['juego_nombre'],
            'product_id' => $productId,
            'precio_local' => (float) $row['precio'],
            'encontrado' => $liveProduct !== null,
            'live_nombre' => $liveProduct['name'] ?? null,
            'live_precio' => isset($liveProduct['price']) ? (float) $liveProduct['price'] : null,
        ];
    }

    return ['error' => null, 'rows' => $rows, 'live_by_id' => $liveById];
}

// Paquetes con paquete_api guardado pero SIN proveedor explícito: el resto
// del código (game.php, admin_package_resolve_provider, order_api_provider)
// los trata como "posible GiftVen" por defecto — si en realidad son de
// RecargasAmérica, quedan mal etiquetados en todos lados: no se les aplica
// el margen de ganancia (game.php busca su ID en el catálogo de GiftVen, no
// lo encuentra, y usa el precio crudo guardado sin margen) y el admin los
// muestra como "TiendaGiftVen" aunque no lo sean. Se revisan contra AMBOS
// catálogos en vivo para saber a cuál pertenecen de verdad.
function verificar_paquetes_ambiguos(mysqli $mysqli, array $giftvenById, array $recargasamericaById): array {
    $rows = [];
    $res = $mysqli->query(
        "SELECT jp.id, jp.nombre AS paquete_nombre, jp.paquete_api, jp.precio, j.id AS juego_id, j.nombre AS juego_nombre
         FROM juego_paquetes jp
         JOIN juegos j ON j.id = jp.juego_id
         WHERE (jp.api_provider = '' OR jp.api_provider IS NULL) AND jp.paquete_api > 0
         ORDER BY j.nombre, jp.nombre"
    );
    while ($row = $res->fetch_assoc()) {
        $productId = (int) $row['paquete_api'];
        $inGiftven = isset($giftvenById[$productId]);
        $inRecargasamerica = isset($recargasamericaById[$productId]);

        if ($inGiftven && !$inRecargasamerica) {
            $veredicto = 'giftven';
        } elseif ($inRecargasamerica && !$inGiftven) {
            $veredicto = 'recargasamerica';
        } elseif ($inGiftven && $inRecargasamerica) {
            $veredicto = 'ambiguo';
        } else {
            $veredicto = 'ninguno';
        }

        $rows[] = [
            'paquete_id' => (int) $row['id'],
            'paquete_nombre' => (string) $row['paquete_nombre'],
            'juego_id' => (int) $row['juego_id'],
            'juego_nombre' => (string) $row['juego_nombre'],
            'product_id' => $productId,
            'precio_local' => (float) $row['precio'],
            'veredicto' => $veredicto,
            'nombre_giftven' => $giftvenById[$productId]['nombre'] ?? null,
            'nombre_recargasamerica' => $recargasamericaById[$productId]['name'] ?? null,
        ];
    }

    return $rows;
}

function render_ambiguous_table(array $rows): void {
    echo '<h2 style="color:#facc15; font-family:\'Oxanium\',sans-serif; margin-top:2rem;">Paquetes sin proveedor explícito (' . count($rows) . ')</h2>';
    echo '<p style="color:#94a3b8;">Tienen un product ID guardado pero la columna api_provider está vacía. El sistema los trata como GiftVen por defecto — si en realidad son de RecargasAmérica, pierden el margen de ganancia y quedan mal identificados en todos lados.</p>';

    if ($rows === []) {
        echo '<p style="color:#4ade80;">Ninguno — todos los paquetes tienen su proveedor explícito. ✅</p>';
        return;
    }

    echo '<div style="overflow-x:auto;"><table style="width:100%; border-collapse:collapse; font-size:0.9rem;">';
    echo '<thead><tr style="text-align:left; border-bottom:1px solid rgba(250,204,21,0.3);">
            <th style="padding:0.5rem;">Pertenece a</th>
            <th style="padding:0.5rem;">Juego</th>
            <th style="padding:0.5rem;">Paquete</th>
            <th style="padding:0.5rem;">Product ID</th>
            <th style="padding:0.5rem;">Precio local</th>
            <th style="padding:0.5rem;"></th>
          </tr></thead><tbody>';

    $veredictoLabels = [
        'giftven' => ['label' => '➡️ Es de GiftVen', 'color' => '#22d3ee'],
        'recargasamerica' => ['label' => '⚠️ Es de RecargasAmérica', 'color' => '#facc15'],
        'ambiguo' => ['label' => '❓ ID existe en AMBOS catálogos', 'color' => '#f87171'],
        'ninguno' => ['label' => '❌ No existe en ningún catálogo', 'color' => '#f87171'],
    ];

    foreach ($rows as $row) {
        $veredicto = $veredictoLabels[$row['veredicto']];
        $nombreRemoto = $row['nombre_giftven'] ?? $row['nombre_recargasamerica'];
        echo '<tr style="border-bottom:1px solid rgba(148,163,184,0.15);">';
        echo '<td style="padding:0.5rem; color:' . $veredicto['color'] . '; font-weight:700;">' . $veredicto['label'] . '</td>';
        echo '<td style="padding:0.5rem; color:#e2e8f0;">' . htmlspecialchars($row['juego_nombre']) . '</td>';
        echo '<td style="padding:0.5rem; color:#e2e8f0;">' . htmlspecialchars($row['paquete_nombre']) . '</td>';
        echo '<td style="padding:0.5rem; color:#94a3b8;">' . (int) $row['product_id'] . '</td>';
        echo '<td style="padding:0.5rem; color:#94a3b8;">' . number_format($row['precio_local'], 2) . '</td>';
        echo '<td style="padding:0.5rem; color:#94a3b8;">' . ($nombreRemoto !== null ? htmlspecialchars((string) $nombreRemoto) : '—') . '</td>';
        echo '</tr>';
    }

    echo '</tbody></table></div>';
}

$giftven = verificar_catalogo_giftven($mysqli);
$recargasamerica = verificar_catalogo_recargasamerica($mysqli);
$ambiguos = verificar_paquetes_ambiguos(
    $mysqli,
    $giftven['live_by_id'] ?? [],
    $recargasamerica['live_by_id'] ?? []
);

function render_provider_table(string $title, array $result): void {
    echo '<h2 style="color:#22d3ee; font-family:\'Oxanium\',sans-serif; margin-top:2rem;">' . htmlspecialchars($title) . '</h2>';

    if ($result['error'] !== null) {
        echo '<p style="color:#f87171;">' . htmlspecialchars($result['error']) . '</p>';
        return;
    }

    if ($result['rows'] === []) {
        echo '<p style="color:#94a3b8;">No hay paquetes configurados con este proveedor.</p>';
        return;
    }

    $broken = array_filter($result['rows'], static fn (array $r): bool => !$r['encontrado']);
    $okCount = count($result['rows']) - count($broken);
    echo '<p style="color:#94a3b8;">' . count($result['rows']) . ' paquete(s) revisado(s) — <span style="color:#4ade80;">' . $okCount . ' OK</span>, <span style="color:#f87171;">' . count($broken) . ' rotos</span>.</p>';

    echo '<div style="overflow-x:auto;"><table style="width:100%; border-collapse:collapse; font-size:0.9rem;">';
    echo '<thead><tr style="text-align:left; border-bottom:1px solid rgba(34,211,238,0.3);">
            <th style="padding:0.5rem;">Estado</th>
            <th style="padding:0.5rem;">Juego</th>
            <th style="padding:0.5rem;">Paquete</th>
            <th style="padding:0.5rem;">Product ID</th>
            <th style="padding:0.5rem;">Precio local</th>
            <th style="padding:0.5rem;">Nombre en catálogo remoto</th>
            <th style="padding:0.5rem;"></th>
          </tr></thead><tbody>';

    // Rotos primero — son los que requieren acción.
    usort($result['rows'], static function (array $a, array $b): int {
        return ($a['encontrado'] <=> $b['encontrado']);
    });

    foreach ($result['rows'] as $row) {
        $rowColor = $row['encontrado'] ? '#4ade80' : '#f87171';
        $statusLabel = $row['encontrado'] ? '✅ OK' : '❌ NO ENCONTRADO';
        echo '<tr style="border-bottom:1px solid rgba(148,163,184,0.15);">';
        echo '<td style="padding:0.5rem; color:' . $rowColor . '; font-weight:700;">' . $statusLabel . '</td>';
        echo '<td style="padding:0.5rem; color:#e2e8f0;">' . htmlspecialchars($row['juego_nombre']) . '</td>';
        echo '<td style="padding:0.5rem; color:#e2e8f0;">' . htmlspecialchars($row['paquete_nombre']) . '</td>';
        echo '<td style="padding:0.5rem; color:#94a3b8;">' . (int) $row['product_id'] . '</td>';
        echo '<td style="padding:0.5rem; color:#94a3b8;">' . number_format($row['precio_local'], 2) . '</td>';
        echo '<td style="padding:0.5rem; color:#94a3b8;">' . ($row['live_nombre'] !== null ? htmlspecialchars((string) $row['live_nombre']) . ' ($' . number_format((float) $row['live_precio'], 2) . ')' : '—') . '</td>';
        echo '<td style="padding:0.5rem;"><a href="' . htmlspecialchars(app_path('/admin/paquetes') . '?juego=' . (int) $row['juego_id']) . '" style="color:#22d3ee;" target="_blank">Editar →</a></td>';
        echo '</tr>';
    }

    echo '</tbody></table></div>';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Verificación de catálogo de proveedores</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
</head>
<body style="background:#0b1420; color:#e2e8f0; font-family:sans-serif; padding:2rem;">
  <h1 style="color:#22d3ee; font-family:'Oxanium',sans-serif;">Verificación de catálogo de proveedores</h1>
  <p style="color:#94a3b8;">Compara los product ID guardados en cada paquete contra el catálogo EN VIVO de GiftVen y RecargasAmérica. Un paquete "NO ENCONTRADO" es la causa de "El producto API configurado ya no está disponible en el catálogo remoto." al procesar sus pedidos — hay que editarlo en Paquetes y volver a seleccionar el producto correcto.</p>
  <?php
  render_ambiguous_table($ambiguos);
  render_provider_table('GiftVen', $giftven);
  render_provider_table('RecargasAmérica', $recargasamerica);
  ?>
</body>
</html>
