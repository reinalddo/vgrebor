<?php
// Herramienta de SOLO LECTURA — no cambia ningún paquete ni pedido.
//
// Motivo: se reportó un paquete de "recarga" (RecargasAmérica) que entregó un PIN.
// Causa raíz encontrada: el asistente de migración (admin/paquetes.php) determinaba
// si un paquete era PIN o Recarga consultando el catálogo VIEJO (dado de baja el
// 20-sep) — si esa consulta no reconocía el ID guardado (nuestra copia de respaldo
// no tiene por qué coincidir con los IDs reales de cada cuenta), el desplegable
// dejaba de filtrar por tipo y mezclaba pines y recargas, con nombres y precios
// casi idénticos entre sí ("Pin Free Fire 100 Diamantes" vs "Recarga Free Fire -
// 100 Diamantes", mismo precio). Ya se corrigió: ahora se usa la marca guardada en
// el propio paquete como fuente de verdad, no el catálogo viejo. Esta página busca
// si quedó algún paquete con esa confusión, migrado o sin migrar, para revisarlo.
require_once __DIR__ . '/../includes/tenant.php';
tenant_start_session();
$adminRole = trim((string) ($_SESSION['auth_user']['rol'] ?? ''));
if (!isset($_SESSION['auth_user']) || !in_array($adminRole, ['admin', 'root'], true)) {
    header('Location: ' . app_path('/login.php'));
    exit();
}
require_once __DIR__ . '/../includes/auth.php';
csrf_verify_soft();
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/store_config.php';
require_once __DIR__ . '/../includes/recargasamerica_api.php';

@set_time_limit(60);

function ra_audit_e(string $text): string {
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

// Palabras que delatan la intención del nombre del paquete, independientes de
// cualquier catálogo — es lo que el propio dueño de la tienda escribió.
function ra_audit_name_hints_pin(string $name): bool {
    return preg_match('/\bpin(es)?\b/iu', $name) === 1;
}
function ra_audit_name_hints_recharge(string $name): bool {
    return preg_match('/\brecarga(s)?\b|\brecharge\b|\btarjeta\b|\bpase\b/iu', $name) === 1;
}

$rows = [];
$catalogError = null;
$legacyError = null;

$res = $mysqli->query(
    "SELECT jp.id, jp.nombre, jp.paquete_api, jp.recargasamerica_tipo, jp.activo, jp.precio,
            j.id AS juego_id, j.nombre AS juego_nombre
     FROM juego_paquetes jp
     JOIN juegos j ON j.id = jp.juego_id
     WHERE jp.api_provider = 'recargasamerica' AND jp.paquete_api > 0
     ORDER BY j.nombre ASC, jp.nombre ASC"
);
$packages = [];
if ($res instanceof mysqli_result) {
    while ($row = $res->fetch_assoc()) {
        $packages[] = $row;
    }
}

$catalogById = [];
$legacyById = [];
$needsCatalog = false;
$needsLegacy = false;
foreach ($packages as $pkg) {
    if (recargasamerica_tipo_is_catalog($pkg['recargasamerica_tipo'] ?? '')) {
        $needsCatalog = true;
    } else {
        $needsLegacy = true;
    }
}
if ($needsCatalog) {
    try {
        foreach (recargasamerica_api_fetch_catalog() as $p) {
            $catalogById[(int) ($p['id'] ?? 0)] = $p;
        }
    } catch (Throwable $e) {
        $catalogError = $e->getMessage();
    }
}
if ($needsLegacy) {
    try {
        foreach (recargasamerica_api_fetch_products_pins() as $p) {
            $legacyById[(int) ($p['id'] ?? 0)] = $p;
        }
    } catch (Throwable $e) {
        $legacyError = $e->getMessage();
    }
}

$typeLabels = ['pin' => 'PIN', 'recharge' => 'Recarga', 'game' => 'Juego', 'streaming' => 'Streaming', '' => '(sin marca)'];
$suspicious = [];
$clean = [];

foreach ($packages as $pkg) {
    $tipoRaw = trim((string) ($pkg['recargasamerica_tipo'] ?? ''));
    $isCatalog = recargasamerica_tipo_is_catalog($tipoRaw);
    $baseType = $tipoRaw !== '' ? recargasamerica_tipo_base($tipoRaw) : '';
    $productId = (int) ($pkg['paquete_api'] ?? 0);
    $product = $isCatalog ? ($catalogById[$productId] ?? null) : ($legacyById[$productId] ?? null);
    $liveType = $product !== null ? recargasamerica_tipo_base((string) ($product['type'] ?? ($isCatalog ? '' : ($legacyById[$productId]['type'] ?? '')))) : '';
    if (!$isCatalog && $product !== null) {
        $liveType = recargasamerica_tipo_base((string) ($product['type'] ?? ''));
    }

    $name = (string) ($pkg['nombre'] ?? '');
    $nameHintsPin = ra_audit_name_hints_pin($name);
    $nameHintsRecharge = ra_audit_name_hints_recharge($name);

    $problems = [];
    if ($tipoRaw === '') {
        $problems[] = 'El paquete no tiene marca guardada (pin/recarga desconocido).';
    }
    if ($product === null) {
        $problems[] = 'El producto ID ' . $productId . ' no se encontró en el catálogo ' . ($isCatalog ? 'nuevo' : 'viejo') . ' — puede haber sido retirado.';
    } elseif ($baseType !== '' && $liveType !== '' && $baseType !== $liveType) {
        $problems[] = 'La marca guardada dice "' . ($typeLabels[$baseType] ?? $baseType) . '" pero el producto real es de tipo "' . ($typeLabels[$liveType] ?? $liveType) . '".';
    }
    if ($nameHintsPin && !$nameHintsRecharge && $baseType === 'recharge') {
        $problems[] = 'El nombre del paquete sugiere PIN, pero está configurado como Recarga.';
    }
    if ($nameHintsRecharge && !$nameHintsPin && $baseType === 'pin') {
        $problems[] = 'El nombre del paquete sugiere Recarga, pero está configurado como PIN.';
    }

    $entry = [
        'pkg' => $pkg,
        'is_catalog' => $isCatalog,
        'tipo_raw' => $tipoRaw,
        'base_type' => $baseType,
        'product' => $product,
        'live_type' => $liveType,
        'problems' => $problems,
    ];
    if (!empty($problems)) {
        $suspicious[] = $entry;
    } else {
        $clean[] = $entry;
    }
}
?><!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Auditoría de paquetes RecargasAmérica</title>
<style>
  body { background:#0b1220; color:#e2e8f0; font-family:system-ui,Segoe UI,Arial,sans-serif; margin:0; padding:24px 16px; line-height:1.5; }
  main { max-width:1000px; margin:0 auto; }
  h1 { font-size:1.4rem; margin:0 0 4px; color:#22d3ee; }
  h2 { font-size:1.02rem; margin:26px 0 8px; color:#8be9fd; }
  p.note { color:#94a3b8; font-size:.9rem; margin:0 0 16px; }
  table { width:100%; border-collapse:collapse; font-size:.88rem; }
  th, td { text-align:left; padding:8px 10px; border-bottom:1px solid #1e293b; vertical-align:top; word-break:break-word; }
  th { color:#8be9fd; font-weight:600; }
  a { color:#22d3ee; }
  .warn-row { background:rgba(239,68,68,0.10); }
  .badge { display:inline-block; padding:2px 8px; border-radius:999px; font-size:.78rem; font-weight:600; }
  .badge-bad { background:#3b1010; color:#fecaca; border:1px solid #ef4444; }
  .badge-ok { background:#052e1a; color:#bbf7d0; border:1px solid #22c55e; }
  ul.problems { margin:4px 0 0; padding-left:18px; color:#fbbf24; }
</style>
</head>
<body>
<main>
  <h1>Auditoría de paquetes de RecargasAmérica</h1>
  <p class="note">Solo lectura: no cambia ningún paquete. Revisa que cada paquete entregue lo que su nombre promete. <a href="<?= ra_audit_e(app_path('/admin/dashboard')) ?>">← Volver al panel</a></p>

  <?php if ($catalogError !== null): ?>
    <div class="badge badge-bad" style="display:block;padding:10px;margin-bottom:12px;">No se pudo consultar el Catálogo Unificado: <?= ra_audit_e($catalogError) ?></div>
  <?php endif; ?>
  <?php if ($legacyError !== null): ?>
    <div class="badge badge-bad" style="display:block;padding:10px;margin-bottom:12px;">No se pudo consultar el catálogo viejo (normal si ya fue dado de baja): <?= ra_audit_e($legacyError) ?></div>
  <?php endif; ?>

  <h2><?= count($suspicious) ?> paquete(s) para revisar</h2>
  <?php if (empty($suspicious)): ?>
    <p><span class="badge badge-ok">Sin problemas detectados</span> en los <?= count($packages) ?> paquete(s) de RecargasAmérica.</p>
  <?php else: ?>
    <table>
      <thead>
        <tr><th>Juego / Paquete</th><th>Marcado como</th><th>Producto guardado (ID <?= '' ?>)</th><th>Qué está mal</th></tr>
      </thead>
      <tbody>
        <?php foreach ($suspicious as $entry): $pkg = $entry['pkg']; ?>
          <tr class="warn-row">
            <td>
              <div style="color:#8be9fd;"><?= ra_audit_e((string) ($pkg['juego_nombre'] ?? '')) ?></div>
              <strong><?= ra_audit_e((string) ($pkg['nombre'] ?? '')) ?></strong>
              <?php if (empty($pkg['activo'])): ?><span class="badge badge-bad">inactivo</span><?php endif; ?>
              <div class="note">Editar: <a href="<?= ra_audit_e(app_path('/admin/paquetes/' . (int) ($pkg['juego_id'] ?? 0))) ?>">ir al juego</a></div>
            </td>
            <td><?= ra_audit_e($typeLabels[$entry['base_type']] ?? $entry['base_type']) ?><?= $entry['is_catalog'] ? ' (Catálogo Unificado)' : ' (catálogo viejo)' ?></td>
            <td>
              <?php if ($entry['product'] !== null): ?>
                ID <?= (int) ($pkg['paquete_api'] ?? 0) ?> — <?= ra_audit_e((string) ($entry['product']['name'] ?? '')) ?>
                (tipo real: <?= ra_audit_e($typeLabels[$entry['live_type']] ?? $entry['live_type']) ?>)
              <?php else: ?>
                ID <?= (int) ($pkg['paquete_api'] ?? 0) ?> — <span style="color:#f87171;">no encontrado</span>
              <?php endif; ?>
            </td>
            <td><ul class="problems"><?php foreach ($entry['problems'] as $p): ?><li><?= ra_audit_e($p) ?></li><?php endforeach; ?></ul></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <h2><?= count($clean) ?> paquete(s) sin problemas detectados</h2>
  <p class="note">El nombre concuerda con el tipo guardado y el producto existe en su catálogo correspondiente.</p>
  <table>
    <thead><tr><th>Juego / Paquete</th><th>Tipo</th><th>Producto</th></tr></thead>
    <tbody>
      <?php foreach ($clean as $entry): $pkg = $entry['pkg']; ?>
        <tr>
          <td><?= ra_audit_e((string) ($pkg['juego_nombre'] ?? '')) ?> — <?= ra_audit_e((string) ($pkg['nombre'] ?? '')) ?></td>
          <td><?= ra_audit_e($typeLabels[$entry['base_type']] ?? $entry['base_type']) ?></td>
          <td><?= $entry['product'] !== null ? ra_audit_e((string) ($entry['product']['name'] ?? '')) : '—' ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</main>
</body>
</html>
