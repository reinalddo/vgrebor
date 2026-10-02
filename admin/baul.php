<?php
/**
 * Baúl de Giftcards — inventario propio de códigos (ver includes/baul_api.php y CLAUDE.md).
 * Crear/activar productos, cargar códigos por lote (con costo por lote), ver stock/historial,
 * anular códigos sin vender, y completar/cancelar a mano las entregas parciales pendientes.
 */
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
require_once __DIR__ . '/../includes/baul_api.php';
require_once __DIR__ . '/../includes/header.php';

bau_ensure_schema($mysqli);

$ordersApiUrl = app_path('/api/pedidos.php');
$flashMessage = '';
$flashType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'crear_producto') {
            $nombre = trim((string) ($_POST['nombre'] ?? ''));
            if ($nombre === '') {
                throw new RuntimeException('Escribe un nombre para el producto.');
            }
            bau_create_product($mysqli, $nombre);
            $flashMessage = 'Producto creado: ' . htmlspecialchars($nombre, ENT_QUOTES, 'UTF-8') . '. Ahora cárgale códigos.';
        } elseif ($action === 'activar_producto' || $action === 'desactivar_producto') {
            $productId = (int) ($_POST['producto_id'] ?? 0);
            if ($productId <= 0) {
                throw new RuntimeException('Producto inválido.');
            }
            bau_set_product_active($mysqli, $productId, $action === 'activar_producto');
            $flashMessage = $action === 'activar_producto' ? 'Producto activado.' : 'Producto desactivado (deja de poder elegirse en Paquetes; lo ya vinculado no se ve afectado).';
        } elseif ($action === 'renombrar_producto') {
            $productId = (int) ($_POST['producto_id'] ?? 0);
            $nuevoNombre = trim((string) ($_POST['nombre'] ?? ''));
            if ($productId <= 0) {
                throw new RuntimeException('Producto inválido.');
            }
            bau_rename_product($mysqli, $productId, $nuevoNombre);
            $flashMessage = 'Producto renombrado.';
        } elseif ($action === 'eliminar_producto') {
            $productId = (int) ($_POST['producto_id'] ?? 0);
            bau_delete_product($mysqli, $productId);
            $flashMessage = 'Producto eliminado.';
        } elseif ($action === 'cargar_codigos') {
            $productId = (int) ($_POST['producto_id'] ?? 0);
            $raw = (string) ($_POST['codigos'] ?? '');
            $costoRaw = trim((string) ($_POST['costo'] ?? ''));
            $costo = ($costoRaw !== '' && is_numeric(str_replace(',', '.', $costoRaw))) ? (float) str_replace(',', '.', $costoRaw) : null;
            $product = bau_fetch_product_by_id($mysqli, $productId);
            if (!$product) {
                throw new RuntimeException('Producto inválido.');
            }
            $parsed = bau_parse_bulk_codes($raw);
            if (empty($parsed['items'])) {
                throw new RuntimeException('Pega al menos un código (uno por línea).');
            }
            $result = bau_load_codes($mysqli, $productId, $parsed['items'], $costo);
            $flashMessage = '✓ ' . $result['insertados'] . ' código(s) cargado(s) a "' . htmlspecialchars($product['nombre'], ENT_QUOTES, 'UTF-8') . '".';
            if ($result['omitidos_duplicados'] > 0) {
                $flashMessage .= ' ' . $result['omitidos_duplicados'] . ' se omitieron por estar repetidos (ya cargados antes o repetidos en el pegado).';
            }
            if ($costo === null) {
                $flashMessage .= ' ⚠ No indicaste costo: quedó en blanco, revisa Estadísticas.';
            }
        } elseif ($action === 'editar_codigo') {
            $codeId = (int) ($_POST['codigo_id'] ?? 0);
            $codigoVal = (string) ($_POST['codigo'] ?? '');
            $serialVal = trim((string) ($_POST['serial'] ?? ''));
            $costoRaw = trim((string) ($_POST['costo'] ?? ''));
            $costoVal = ($costoRaw !== '' && is_numeric(str_replace(',', '.', $costoRaw))) ? (float) str_replace(',', '.', $costoRaw) : null;
            bau_update_code($mysqli, $codeId, $codigoVal, $serialVal !== '' ? $serialVal : null, $costoVal);
            $flashMessage = 'Código actualizado.';
        } elseif ($action === 'anular_codigo') {
            $codeId = (int) ($_POST['codigo_id'] ?? 0);
            if (!bau_void_code($mysqli, $codeId)) {
                throw new RuntimeException('Ese código ya no está disponible (puede que ya se haya vendido).');
            }
            $flashMessage = 'Código anulado.';
        } elseif ($action === 'eliminar_codigo') {
            $codeId = (int) ($_POST['codigo_id'] ?? 0);
            if (!bau_delete_code($mysqli, $codeId)) {
                throw new RuntimeException('Ese código no se puede eliminar (ya fue vendido: es historial de un pedido).');
            }
            $flashMessage = 'Código eliminado.';
        } elseif ($action === 'eliminar_codigos_masivo') {
            $codeIds = $_POST['codigo_ids'] ?? [];
            if (!is_array($codeIds) || empty($codeIds)) {
                throw new RuntimeException('Selecciona al menos un código.');
            }
            $result = bau_delete_codes_bulk($mysqli, $codeIds);
            $flashMessage = $result['eliminados'] . ' código(s) eliminado(s).';
            if ($result['omitidos_vendidos'] > 0) {
                $flashMessage .= ' ' . $result['omitidos_vendidos'] . ' se omitieron por estar vendidos (son historial de un pedido).';
            }
        } elseif ($action === 'editar_costo_codigos_masivo') {
            $codeIds = $_POST['codigo_ids'] ?? [];
            if (!is_array($codeIds) || empty($codeIds)) {
                throw new RuntimeException('Selecciona al menos un código.');
            }
            $costoRaw = trim((string) ($_POST['costo'] ?? ''));
            $costoVal = ($costoRaw !== '' && is_numeric(str_replace(',', '.', $costoRaw))) ? (float) str_replace(',', '.', $costoRaw) : null;
            $result = bau_update_codes_cost_bulk($mysqli, $codeIds, $costoVal);
            $flashMessage = 'Costo actualizado en ' . $result['actualizados'] . ' código(s).';
            if ($result['omitidos_no_disponibles'] > 0) {
                $flashMessage .= ' ' . $result['omitidos_no_disponibles'] . ' se omitieron por no estar disponibles (vendidos o anulados).';
            }
        }
    } catch (Throwable $e) {
        $flashMessage = $e->getMessage();
        $flashType = 'danger';
    }
}

$searchTerm = trim((string) ($_GET['q'] ?? ''));
$products = bau_fetch_products($mysqli, $searchTerm, false);
$pendingOrders = bau_fetch_pending_orders($mysqli);

function baul_money($amount, string $moneda = 'USD'): string {
    if ($amount === null) {
        return '<span class="text-secondary">—</span>';
    }
    return '$' . number_format((float) $amount, 2, '.', ',') . ' ' . htmlspecialchars($moneda, ENT_QUOTES, 'UTF-8');
}
?>
<style>
  .baul-card { background:#181f2a; border:1px solid #00fff7; border-radius:14px; padding:1.4rem; margin-bottom:1.5rem; }
  .baul-table { background:#181f2a; color:#e2e8f0; }
  .baul-table thead th { color:#00fff7; border-bottom:2px solid #00fff7; background:#181f2a; }
  .baul-table tbody tr { border-bottom:1px solid #222c3a; }
  .baul-input, .baul-textarea, .baul-search { background:#222c3a; color:#e2e8f0; border:1px solid #00fff7; }
  .baul-input::placeholder, .baul-textarea::placeholder { color:#6b7a90; }
  .baul-pill { display:inline-block; padding:.15rem .6rem; border-radius:999px; font-size:.78rem; font-weight:700; }
  .baul-pill-stock { background:rgba(0,255,179,.12); color:#00ffb3; }
  .baul-pill-agotado { background:rgba(255,90,90,.12); color:#ff6b6b; }
  .baul-pill-inactivo { background:rgba(148,163,184,.15); color:#94a3b8; }
  .baul-codes-row { display:none; }
  .baul-codes-row.is-visible { display:table-row; }
  .baul-codes-list { max-height:260px; overflow-y:auto; }
  .baul-codes-list table { width:100%; }
  .baul-pending-row td { vertical-align:middle; }
  .baul-toggle-btn { color:#8be9fd; font-size:.82rem; text-decoration:underline; background:none; border:none; cursor:pointer; padding:0; }
</style>

<div class="row mb-4">
  <div class="col-12 text-center">
    <p class="text-uppercase text-info mb-1">Panel</p>
    <h1 class="display-5 fw-bold text-info mb-2">Baúl de Giftcards</h1>
    <p class="text-secondary">Inventario propio de códigos. Se vinculan a un paquete desde /admin/paquetes eligiendo "Baúl" como fuente.</p>
  </div>
</div>

<?php if ($flashMessage !== ''): ?>
<div class="alert alert-<?= htmlspecialchars($flashType, ENT_QUOTES, 'UTF-8') ?> text-center" style="background:#181f2a;border:1px solid #00fff7;color:#e2e8f0;">
  <?= htmlspecialchars($flashMessage, ENT_QUOTES, 'UTF-8') ?>
</div>
<?php endif; ?>

<div class="baul-card">
  <h2 class="h5 text-info mb-3">Nuevo producto del baúl</h2>
  <form method="post" class="d-flex align-items-end gap-2 flex-wrap">
    <input type="hidden" name="action" value="crear_producto">
    <div>
      <label class="form-label small text-secondary mb-1">Nombre (búsqueda libre, sin categoría)</label>
      <input type="text" name="nombre" class="form-control baul-input" style="min-width:280px;" placeholder="Ej: Robux 800 / PSN $50" required>
    </div>
    <button type="submit" class="btn btn-sm fw-bold" style="background:#00fff7;color:#181f2a;border:none;">Crear producto</button>
  </form>
</div>

<div class="baul-card">
  <h2 class="h5 text-info mb-2">Entregas pendientes (<?= count($pendingOrders) ?>)</h2>
  <p class="text-secondary small mb-3">Pedidos con entrega parcial, sin stock, o en seguimiento con un proveedor de respaldo. Nunca se completan solos: revisa y decide.</p>
  <?php if (empty($pendingOrders)): ?>
    <p class="text-secondary text-center mb-0">No hay entregas pendientes.</p>
  <?php else: ?>
  <div class="table-responsive">
    <table class="table align-middle baul-table">
      <thead>
        <tr>
          <th>Pedido</th>
          <th>Producto</th>
          <th>Cantidad pendiente</th>
          <th>Monto</th>
          <th>Cliente</th>
          <th>Fecha del pedido</th>
          <th>Estado</th>
          <th>Acciones</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($pendingOrders as $po): ?>
        <?php $esIncierto = (string) ($po['bau_estado'] ?? '') === 'respaldo_incierto'; ?>
        <tr class="baul-pending-row" id="baul-pending-<?= (int) $po['id'] ?>">
          <td>
            #<?= (int) $po['id'] ?>
            <?php if (!empty($po['bau_pedido_origen_id'])): ?>
              <br><span class="text-secondary small">(dividido de #<?= (int) $po['bau_pedido_origen_id'] ?>)</span>
            <?php endif; ?>
          </td>
          <td><?= htmlspecialchars((string) ($po['producto_nombre'] ?? $po['paquete_nombre']), ENT_QUOTES, 'UTF-8') ?></td>
          <td><?= (int) $po['cantidad_compra'] ?></td>
          <td><?= baul_money($po['precio'], (string) ($po['moneda'] ?? 'USD')) ?></td>
          <td><?= htmlspecialchars((string) ($po['email'] ?: $po['user_identifier'] ?: '—'), ENT_QUOTES, 'UTF-8') ?></td>
          <td><?= htmlspecialchars((string) $po['creado_en'], ENT_QUOTES, 'UTF-8') ?></td>
          <td>
            <?php if ($esIncierto): ?>
              <span class="baul-pill baul-pill-agotado">⏳ En seguimiento con <?= htmlspecialchars(ucfirst((string) ($po['bau_fallback_provider_used'] ?? '?')), ENT_QUOTES, 'UTF-8') ?></span>
              <?php if (!empty($po['ff_api_mensaje'])): ?>
                <div class="text-secondary small mt-1"><?= htmlspecialchars((string) $po['ff_api_mensaje'], ENT_QUOTES, 'UTF-8') ?></div>
              <?php endif; ?>
            <?php else: ?>
              <?php $stockPend = (int) ($po['stock_actual'] ?? 0); ?>
              <span class="baul-pill <?= $stockPend > 0 ? 'baul-pill-stock' : 'baul-pill-agotado' ?>"><?= $stockPend ?> disponible<?= $stockPend === 1 ? '' : 's' ?> en el Baúl</span>
            <?php endif; ?>
          </td>
          <td class="d-flex gap-2 flex-wrap">
            <?php if ($esIncierto): ?>
              <span class="text-secondary small" style="max-width:220px;">Verifica con el proveedor antes de cerrar — no se puede "Completar" aquí, podría duplicar la compra.</span>
            <?php else: ?>
              <?php $stockPend = (int) ($po['stock_actual'] ?? 0); ?>
              <button type="button" class="btn btn-sm fw-bold js-baul-completar" data-order-id="<?= (int) $po['id'] ?>" style="background:#00fff7;color:#181f2a;border:none;" <?= $stockPend <= 0 ? 'disabled title="No hay stock todavía"' : '' ?>>Completar</button>
            <?php endif; ?>
            <button type="button" class="btn btn-sm btn-outline-danger js-baul-cancelar" data-order-id="<?= (int) $po['id'] ?>">Cancelar pedido</button>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<div class="baul-card">
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h2 class="h5 text-info mb-0">Productos del baúl</h2>
    <form method="get" class="d-flex gap-2">
      <input type="text" name="q" class="form-control form-control-sm baul-search" style="max-width:280px;" placeholder="Buscar producto..." value="<?= htmlspecialchars($searchTerm, ENT_QUOTES, 'UTF-8') ?>">
      <button type="submit" class="btn btn-outline-info btn-sm">Buscar</button>
    </form>
  </div>
  <?php if (empty($products)): ?>
    <p class="text-secondary text-center">No hay productos<?= $searchTerm !== '' ? ' que coincidan con esa búsqueda' : ' todavía' ?>.</p>
  <?php else: ?>
  <div class="table-responsive">
    <table class="table align-middle baul-table">
      <thead>
        <tr>
          <th>Producto</th>
          <th>Stock</th>
          <th>Próximo costo</th>
          <th>Estado</th>
          <th>Acciones</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($products as $p): ?>
        <tr>
          <td>
            <span id="baul-nombre-<?= $p['id'] ?>"><?= htmlspecialchars($p['nombre'], ENT_QUOTES, 'UTF-8') ?></span>
          </td>
          <td>
            <span class="baul-pill <?= $p['stock'] > 0 ? 'baul-pill-stock' : 'baul-pill-agotado' ?>"><?= $p['stock'] ?> disponible<?= $p['stock'] === 1 ? '' : 's' ?></span>
          </td>
          <td><?= $p['proximo_costo'] !== null ? baul_money($p['proximo_costo']) : '<span class="text-secondary">—</span>' ?></td>
          <td>
            <?php if ($p['activo']): ?>
              <span class="baul-pill baul-pill-stock">Activo</span>
            <?php else: ?>
              <span class="baul-pill baul-pill-inactivo">Inactivo</span>
            <?php endif; ?>
          </td>
          <td class="d-flex gap-2 flex-wrap">
            <button type="button" class="baul-toggle-btn" data-toggle-codes="<?= $p['id'] ?>">✎ Editar (nombre, costos y códigos)</button>
            <form method="post" class="m-0">
              <input type="hidden" name="action" value="<?= $p['activo'] ? 'desactivar_producto' : 'activar_producto' ?>">
              <input type="hidden" name="producto_id" value="<?= $p['id'] ?>">
              <button type="submit" class="btn btn-sm btn-outline-secondary"><?= $p['activo'] ? 'Desactivar' : 'Activar' ?></button>
            </form>
            <form method="post" class="m-0" onsubmit="return confirm('¿Eliminar este producto? Solo se puede si nunca se le cargó ningún código y no está vinculado a ningún paquete.');">
              <input type="hidden" name="action" value="eliminar_producto">
              <input type="hidden" name="producto_id" value="<?= $p['id'] ?>">
              <button type="submit" class="btn btn-sm btn-outline-danger">Eliminar</button>
            </form>
          </td>
        </tr>
        <tr class="baul-codes-row" id="baul-codes-<?= $p['id'] ?>">
          <td colspan="5" style="background:#0f1a28;">
            <div class="row g-4">
              <div class="col-12">
                <h3 class="h6 text-info">Nombre del producto</h3>
                <form method="post" class="d-flex gap-2 flex-wrap mb-2" style="max-width:480px;">
                  <input type="hidden" name="action" value="renombrar_producto">
                  <input type="hidden" name="producto_id" value="<?= $p['id'] ?>">
                  <input type="text" name="nombre" class="form-control form-control-sm baul-input" value="<?= htmlspecialchars($p['nombre'], ENT_QUOTES, 'UTF-8') ?>" required>
                  <button type="submit" class="btn btn-sm fw-bold" style="background:#00fff7;color:#181f2a;border:none;">Guardar nombre</button>
                </form>
              </div>
              <div class="col-md-5">
                <h3 class="h6 text-info">Cargar códigos</h3>
                <form method="post">
                  <input type="hidden" name="action" value="cargar_codigos">
                  <input type="hidden" name="producto_id" value="<?= $p['id'] ?>">
                  <textarea name="codigos" class="form-control baul-textarea mb-2" rows="6" placeholder="Un código por línea. Si tiene serial: CODIGO|SERIAL"></textarea>
                  <div class="input-group input-group-sm mb-2" style="max-width:220px;">
                    <span class="input-group-text" style="background:#222c3a;color:#00fff7;border:1px solid #00fff7;">Costo c/u $</span>
                    <input type="text" name="costo" class="form-control baul-input" placeholder="0.00">
                  </div>
                  <button type="submit" class="btn btn-sm fw-bold" style="background:#00fff7;color:#181f2a;border:none;">Cargar lote</button>
                </form>
              </div>
              <div class="col-md-7">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
                  <h3 class="h6 text-info mb-0">Códigos cargados (últimos 300)</h3>
                  <div class="d-flex align-items-center flex-wrap gap-2">
                    <div class="input-group input-group-sm" style="max-width:170px;">
                      <span class="input-group-text" style="background:#222c3a;color:#00fff7;border:1px solid #00fff7;">Costo $</span>
                      <input type="text" class="form-control baul-input js-baul-bulk-costo-input" data-product-id="<?= $p['id'] ?>" placeholder="0.00">
                    </div>
                    <button type="button" class="btn btn-sm btn-outline-info js-baul-apply-cost-selected" data-product-id="<?= $p['id'] ?>" disabled>Aplicar costo a seleccionados (<span class="js-baul-selected-count">0</span>)</button>
                    <button type="button" class="btn btn-sm btn-outline-danger js-baul-delete-selected" data-product-id="<?= $p['id'] ?>" disabled>Eliminar seleccionados (<span class="js-baul-selected-count">0</span>)</button>
                  </div>
                </div>
                <div class="baul-codes-list">
                  <?php $codigosDelProducto = bau_list_codes($mysqli, (int) $p['id']); ?>
                  <table class="table table-sm baul-table mb-0">
                    <thead>
                      <tr>
                        <th><input type="checkbox" class="js-baul-select-all" data-product-id="<?= $p['id'] ?>" title="Seleccionar todos (no incluye vendidos)"></th>
                        <th>Código</th><th>Serial</th><th>Costo</th><th>Estado</th><th></th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($codigosDelProducto as $c): ?>
                      <?php
                        $cid = (int) $c['id'];
                        $esVendido = $c['estado'] === 'vendido';
                        $esEditable = $c['estado'] === 'disponible';
                        $formId = 'baul-edit-form-' . $cid;
                      ?>
                      <tr>
                        <td>
                          <?php if (!$esVendido): ?>
                          <input type="checkbox" class="js-baul-code-check" data-product-id="<?= $p['id'] ?>" value="<?= $cid ?>">
                          <?php endif; ?>
                        </td>
                        <td>
                          <span class="baul-code-view" id="baul-code-view-<?= $cid ?>"><?= htmlspecialchars((string) $c['codigo'], ENT_QUOTES, 'UTF-8') ?></span>
                          <?php if ($esEditable): ?>
                          <input type="text" form="<?= $formId ?>" name="codigo" class="form-control form-control-sm baul-input d-none baul-code-edit-field" id="baul-code-input-<?= $cid ?>" value="<?= htmlspecialchars((string) $c['codigo'], ENT_QUOTES, 'UTF-8') ?>" required>
                          <?php endif; ?>
                        </td>
                        <td>
                          <span class="baul-code-view" id="baul-serial-view-<?= $cid ?>"><?= $c['serial'] !== null ? htmlspecialchars((string) $c['serial'], ENT_QUOTES, 'UTF-8') : '<span class="text-secondary">—</span>' ?></span>
                          <?php if ($esEditable): ?>
                          <input type="text" form="<?= $formId ?>" name="serial" class="form-control form-control-sm baul-input d-none baul-code-edit-field" id="baul-serial-input-<?= $cid ?>" value="<?= htmlspecialchars((string) ($c['serial'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="(sin serial)">
                          <?php endif; ?>
                        </td>
                        <td>
                          <span class="baul-code-view" id="baul-costo-view-<?= $cid ?>"><?= $c['costo'] !== null ? baul_money($c['costo']) : '<span class="text-secondary">—</span>' ?></span>
                          <?php if ($esEditable): ?>
                          <div class="input-group input-group-sm d-none baul-code-edit-field" id="baul-costo-group-<?= $cid ?>" style="max-width:140px;">
                            <span class="input-group-text" style="background:#222c3a;color:#00fff7;border:1px solid #00fff7;">$</span>
                            <input type="text" form="<?= $formId ?>" name="costo" class="form-control baul-input" id="baul-costo-input-<?= $cid ?>" value="<?= $c['costo'] !== null ? htmlspecialchars(number_format((float) $c['costo'], 4, '.', ''), ENT_QUOTES, 'UTF-8') : '' ?>" placeholder="0.00">
                          </div>
                          <?php endif; ?>
                        </td>
                        <td>
                          <?php
                            $estadoLabel = ['disponible' => 'Disponible', 'vendido' => 'Vendido', 'anulado' => 'Anulado'][$c['estado']] ?? $c['estado'];
                            $estadoClass = $c['estado'] === 'disponible' ? 'baul-pill-stock' : ($esVendido ? 'baul-pill-inactivo' : 'baul-pill-agotado');
                          ?>
                          <span class="baul-pill <?= $estadoClass ?>"><?= htmlspecialchars($estadoLabel, ENT_QUOTES, 'UTF-8') ?></span>
                          <?php if ($esVendido && !empty($c['pedido_id'])): ?>
                            <span class="text-secondary small">pedido #<?= (int) $c['pedido_id'] ?></span>
                          <?php endif; ?>
                        </td>
                        <td class="d-flex gap-1 flex-wrap">
                          <?php if ($esEditable): ?>
                          <button type="button" class="baul-toggle-btn js-baul-code-edit-toggle" data-code-id="<?= $cid ?>">✎ Editar</button>
                          <button type="submit" form="<?= $formId ?>" class="btn btn-sm fw-bold d-none baul-code-edit-field" id="baul-code-save-<?= $cid ?>" style="background:#00fff7;color:#181f2a;border:none;">Guardar</button>
                          <button type="button" class="btn btn-sm btn-outline-secondary d-none baul-code-edit-field js-baul-code-edit-cancel" id="baul-code-cancel-<?= $cid ?>" data-code-id="<?= $cid ?>">Cancelar</button>
                          <?php endif; ?>
                          <?php if ($c['estado'] === 'disponible'): ?>
                          <form method="post" class="m-0" onsubmit="return confirm('¿Anular este código? No podrá venderse (pero se conserva en la lista).');">
                            <input type="hidden" name="action" value="anular_codigo">
                            <input type="hidden" name="codigo_id" value="<?= $cid ?>">
                            <button type="submit" class="btn btn-sm btn-outline-secondary">Anular</button>
                          </form>
                          <?php endif; ?>
                          <?php if (!$esVendido): ?>
                          <form method="post" class="m-0" onsubmit="return confirm('¿Eliminar este código para siempre? No se puede deshacer.');">
                            <input type="hidden" name="action" value="eliminar_codigo">
                            <input type="hidden" name="codigo_id" value="<?= $cid ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger">Eliminar</button>
                          </form>
                          <?php endif; ?>
                        </td>
                      </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                  <?php foreach ($codigosDelProducto as $c): if ($c['estado'] !== 'disponible') continue; ?>
                  <form method="post" id="baul-edit-form-<?= (int) $c['id'] ?>" class="d-none">
                    <input type="hidden" name="action" value="editar_codigo">
                    <input type="hidden" name="codigo_id" value="<?= (int) $c['id'] ?>">
                  </form>
                  <?php endforeach; ?>
                </div>
              </div>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<script>
(function () {
  var apiUrl = <?php echo json_encode($ordersApiUrl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

  document.querySelectorAll('[data-toggle-codes]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var target = document.getElementById('baul-codes-' + btn.dataset.toggleCodes);
      if (target) target.classList.toggle('is-visible');
    });
  });

  function setBaulCodeEditMode(codeId, editing) {
    document.querySelectorAll(
      '#baul-code-view-' + codeId + ', #baul-serial-view-' + codeId + ', #baul-costo-view-' + codeId
    ).forEach(function (el) { el.classList.toggle('d-none', editing); });
    document.querySelectorAll(
      '#baul-code-input-' + codeId + ', #baul-serial-input-' + codeId + ', #baul-costo-group-' + codeId
      + ', #baul-code-save-' + codeId + ', #baul-code-cancel-' + codeId
    ).forEach(function (el) { el.classList.toggle('d-none', !editing); });
    var editBtn = document.querySelector('.js-baul-code-edit-toggle[data-code-id="' + codeId + '"]');
    if (editBtn) editBtn.classList.toggle('d-none', editing);
  }
  document.querySelectorAll('.js-baul-code-edit-toggle').forEach(function (btn) {
    btn.addEventListener('click', function () { setBaulCodeEditMode(btn.dataset.codeId, true); });
  });
  document.querySelectorAll('.js-baul-code-edit-cancel').forEach(function (btn) {
    btn.addEventListener('click', function () { setBaulCodeEditMode(btn.dataset.codeId, false); });
  });

  function updateBaulBulkButton(productId) {
    var checked = document.querySelectorAll('.js-baul-code-check[data-product-id="' + productId + '"]:checked');
    ['.js-baul-delete-selected', '.js-baul-apply-cost-selected'].forEach(function (sel) {
      var btn = document.querySelector(sel + '[data-product-id="' + productId + '"]');
      if (!btn) return;
      btn.disabled = checked.length === 0;
      var countEl = btn.querySelector('.js-baul-selected-count');
      if (countEl) countEl.textContent = checked.length;
    });
  }
  document.querySelectorAll('.js-baul-select-all').forEach(function (cb) {
    cb.addEventListener('change', function () {
      var pid = cb.dataset.productId;
      document.querySelectorAll('.js-baul-code-check[data-product-id="' + pid + '"]').forEach(function (c) { c.checked = cb.checked; });
      updateBaulBulkButton(pid);
    });
  });
  document.querySelectorAll('.js-baul-code-check').forEach(function (cb) {
    cb.addEventListener('change', function () { updateBaulBulkButton(cb.dataset.productId); });
  });
  document.querySelectorAll('.js-baul-delete-selected').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var pid = btn.dataset.productId;
      var checked = document.querySelectorAll('.js-baul-code-check[data-product-id="' + pid + '"]:checked');
      if (checked.length === 0) return;
      if (!window.confirm('¿Eliminar ' + checked.length + ' código(s) para siempre? Los vendidos nunca se tocan aunque estén seleccionados. No se puede deshacer.')) return;
      var form = document.createElement('form');
      form.method = 'post';
      form.style.display = 'none';
      var actionInput = document.createElement('input');
      actionInput.type = 'hidden';
      actionInput.name = 'action';
      actionInput.value = 'eliminar_codigos_masivo';
      form.appendChild(actionInput);
      checked.forEach(function (c) {
        var inp = document.createElement('input');
        inp.type = 'hidden';
        inp.name = 'codigo_ids[]';
        inp.value = c.value;
        form.appendChild(inp);
      });
      document.body.appendChild(form);
      form.submit();
    });
  });
  document.querySelectorAll('.js-baul-apply-cost-selected').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var pid = btn.dataset.productId;
      var checked = document.querySelectorAll('.js-baul-code-check[data-product-id="' + pid + '"]:checked');
      if (checked.length === 0) return;
      var costoInput = document.querySelector('.js-baul-bulk-costo-input[data-product-id="' + pid + '"]');
      var costoVal = costoInput ? costoInput.value.trim() : '';
      var msg = costoVal === ''
        ? '¿Dejar en BLANCO el costo de ' + checked.length + ' código(s)? Los vendidos/anulados nunca se tocan aunque estén seleccionados.'
        : '¿Poner el costo en $' + costoVal + ' para ' + checked.length + ' código(s)? Los vendidos/anulados nunca se tocan aunque estén seleccionados.';
      if (!window.confirm(msg)) return;
      var form = document.createElement('form');
      form.method = 'post';
      form.style.display = 'none';
      var actionInput = document.createElement('input');
      actionInput.type = 'hidden';
      actionInput.name = 'action';
      actionInput.value = 'editar_costo_codigos_masivo';
      form.appendChild(actionInput);
      var costoHidden = document.createElement('input');
      costoHidden.type = 'hidden';
      costoHidden.name = 'costo';
      costoHidden.value = costoVal;
      form.appendChild(costoHidden);
      checked.forEach(function (c) {
        var inp = document.createElement('input');
        inp.type = 'hidden';
        inp.name = 'codigo_ids[]';
        inp.value = c.value;
        form.appendChild(inp);
      });
      document.body.appendChild(form);
      form.submit();
    });
  });


  function postPending(action, orderId, confirmMsg) {
    if (confirmMsg && !window.confirm(confirmMsg)) {
      return;
    }
    var fd = new FormData();
    fd.append('action', action);
    fd.append('order_id', orderId);
    fetch(apiUrl, { method: 'POST', body: fd })
      .then(function (res) { return res.json().then(function (data) { return { res: res, data: data }; }); })
      .then(function (r) {
        if (!r.res.ok || !r.data.ok) {
          throw new Error((r.data && r.data.message) ? r.data.message : 'No se pudo completar la acción.');
        }
        alert(r.data.message || 'Listo.');
        window.location.reload();
      })
      .catch(function (err) {
        alert(err.message || 'No se pudo completar la acción.');
      });
  }

  document.querySelectorAll('.js-baul-completar').forEach(function (btn) {
    btn.addEventListener('click', function () {
      postPending('admin_baul_complete_pending', btn.dataset.orderId, null);
    });
  });

  document.querySelectorAll('.js-baul-cancelar').forEach(function (btn) {
    btn.addEventListener('click', function () {
      postPending('admin_baul_cancel_pending', btn.dataset.orderId, '¿Cerrar este pedido sin entregarlo? Úsalo solo si ya devolviste el dinero fuera del sistema.');
    });
  });
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
