<?php
// Mostrar errores PHP para debug
//ini_set('display_errors', 1);
//ini_set('display_startup_errors', 1);
//error_reporting(E_ALL);
// admin/monedas.php - Gestión de monedas (CRUD)
require_once '../includes/db_connect.php';
require_once '../includes/currency.php';

currency_ensure_schema();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_decimals_id'])) {
    $id = intval($_POST['toggle_decimals_id']);
    $mostrarDecimales = isset($_POST['mostrar_decimales']) ? 1 : 0;

    if ($id > 0) {
        $stmt = $mysqli->prepare('UPDATE monedas SET mostrar_decimales = ? WHERE id = ? LIMIT 1');
        if ($stmt) {
            $stmt->bind_param('ii', $mostrarDecimales, $id);
            $stmt->execute();
            $stmt->close();
        }
    }

    header('Location: monedas.php');
    exit;
}

// Procesar formulario de creación
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['nombre'], $_POST['clave'], $_POST['tasa'])) {
    $nombre = trim($_POST['nombre']);
    $clave = strtoupper(trim($_POST['clave']));
    $tasa = floatval($_POST['tasa']);
    if ($clave !== 'USD') {
        $stmt = $mysqli->prepare("INSERT INTO monedas (nombre, clave, tasa) VALUES (?, ?, ?)");
        $stmt->bind_param('ssd', $nombre, $clave, $tasa);
        $stmt->execute();
    }
    header('Location: monedas.php');
    exit;
}

// Editar moneda
if (isset($_POST['editar_id'], $_POST['editar_nombre'], $_POST['editar_clave'], $_POST['editar_tasa'])) {
    $id = intval($_POST['editar_id']);
    $nombre = trim($_POST['editar_nombre']);
    $clave = strtoupper(trim($_POST['editar_clave']));
    $tasa = floatval($_POST['editar_tasa']);
    $stmt = $mysqli->prepare("UPDATE monedas SET nombre=?, clave=?, tasa=? WHERE id=? AND es_base=0");
    $stmt->bind_param('ssdi', $nombre, $clave, $tasa, $id);
    $stmt->execute();
    header('Location: monedas.php');
    exit;
}
// Eliminar moneda
if (isset($_GET['eliminar'])) {
    $id = intval($_GET['eliminar']);
    $stmt = $mysqli->prepare("DELETE FROM monedas WHERE id=? AND es_base=0");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    header('Location: monedas.php');
    exit;
}

// Listar monedas
$res = $mysqli->query("SELECT * FROM monedas ORDER BY es_base DESC, nombre ASC");
$monedas = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
?>
<?php include '../includes/header.php'; ?>
<main class="container-sm mt-5 bg-dark bg-opacity-75 rounded-4 p-4 shadow">
    <h2 class="text-center mb-4" style="color:#00fff7;">Gestión de Monedas</h2>
    <form method="post" class="row g-3 mb-4" style="background:#181f2a; border-radius:16px; border:2px solid #00fff7; box-shadow:0 0 24px #00fff733; padding:2rem;">
        <div class="col-md-4">
            <label class="form-label" style="color:#00fff7;">Nombre de la moneda</label>
            <input type="text" name="nombre" placeholder="Nombre de la moneda" required class="form-control" style="background:#222c3a; color:#00fff7; border:1px solid #00fff7;">
        </div>
        <div class="col-md-4">
            <label class="form-label" style="color:#00fff7;">Clave (ej: USD, BS)</label>
            <input type="text" name="clave" placeholder="Clave" maxlength="10" required class="form-control" style="background:#222c3a; color:#00fff7; border:1px solid #00fff7;">
        </div>
        <div class="col-md-4">
            <label class="form-label" style="color:#00fff7;">Tasa respecto al USD</label>
            <input type="number" step="0.000001" name="tasa" placeholder="Tasa" required class="form-control" style="background:#222c3a; color:#00fff7; border:1px solid #00fff7;">
        </div>
        <div class="col-12">
            <button type="submit" class="btn btn-info w-100" style="background:#00fff7; color:#222; border:none; box-shadow:0 0 8px #00fff7;">Agregar moneda</button>
        </div>
    </form>
    <h3 class="text-info mt-5 mb-3">Monedas existentes</h3>
    <div class="table-responsive d-none d-md-block">
        <table class="table align-middle" style="background:#181f2a; color:#00fff7; border-radius:12px;">
            <thead style="background:#181f2a; color:#00fff7; border-bottom:2px solid #00fff7;">
                <tr>
                    <th style="color:#00fff7; background:#181f2a;">Nombre</th>
                    <th style="color:#00fff7; background:#181f2a;">Clave</th>
                    <th style="color:#00fff7; background:#181f2a;">Tasa</th>
                    <th style="color:#00fff7; background:#181f2a;">Decimales</th>
                    <th style="color:#00fff7; background:#181f2a;">Base</th>
                    <th style="color:#00fff7; background:#181f2a;">Acciones</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($monedas as $m): ?>
            <tr style="background:#181f2a; color:#fff;">
                <?php if (!$m['es_base'] && isset($_GET['editar']) && $_GET['editar'] == $m['id']): ?>
                <form method="post" class="contents">
                    <td><input type="text" name="editar_nombre" value="<?= htmlspecialchars($m['nombre']) ?>" class="form-control" style="background:#222c3a; color:#00fff7; border:1px solid #00fff7;"></td>
                    <td><input type="text" name="editar_clave" value="<?= htmlspecialchars($m['clave']) ?>" class="form-control" style="background:#222c3a; color:#00fff7; border:1px solid #00fff7;"></td>
                    <td><input type="number" step="0.000001" name="editar_tasa" value="<?= htmlspecialchars($m['tasa']) ?>" class="form-control" style="background:#222c3a; color:#00fff7; border:1px solid #00fff7;"></td>
                    <td>
                        <div class="form-check d-flex justify-content-center align-items-center" style="min-height:38px;">
                            <input class="form-check-input" type="checkbox" disabled <?= !empty($m['mostrar_decimales']) ? 'checked' : '' ?>>
                        </div>
                    </td>
                    <td>No</td>
                    <td>
                        <input type="hidden" name="editar_id" value="<?= $m['id'] ?>">
                        <button type="submit" class="btn btn-info btn-sm" style="background:#00fff7; color:#222; border:none; box-shadow:0 0 8px #00fff7;">Guardar</button>
                        <a href="monedas.php" class="btn btn-secondary btn-sm ms-2">Cancelar</a>
                    </td>
                </form>
                <?php else: ?>
                <td style="background:#181f2a; color:#00fff7;"><?= htmlspecialchars($m['nombre']) ?></td>
                <td style="background:#181f2a; color:#00fff7;"><?= htmlspecialchars($m['clave']) ?></td>
                <td style="background:#181f2a; color:#00fff7;"><?= htmlspecialchars($m['tasa']) ?></td>
                <td style="background:#181f2a; color:#00fff7;">
                    <form method="post" class="d-flex justify-content-center align-items-center m-0">
                        <input type="hidden" name="toggle_decimals_id" value="<?= (int) $m['id'] ?>">
                        <div class="form-check form-switch m-0">
                            <input class="form-check-input" type="checkbox" name="mostrar_decimales" value="1" <?= !empty($m['mostrar_decimales']) ? 'checked' : '' ?> onchange="this.form.submit()" aria-label="Mostrar decimales en <?= htmlspecialchars($m['clave'], ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                    </form>
                </td>
                <td style="background:#181f2a; color:#00fff7;"><?= $m['es_base'] ? '<span style=\'color:#00fff7;\'>Sí</span>' : 'No' ?></td>
                <td style="background:#181f2a;"><?php if (!$m['es_base']): ?><a href="?editar=<?= $m['id'] ?>" style="color:#00fff7; text-decoration:underline; margin-right:1em; display:inline-block;">Editar</a><a href="?eliminar=<?= $m['id'] ?>" onclick="return confirm('¿Eliminar moneda?')" style="color:#ff0059; text-decoration:underline; display:inline-block; margin-left:0.25rem;">Eliminar</a><?php else: ?>-<?php endif; ?></td>
                <?php endif; ?>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <!-- Mobile Cards -->
    <div class="d-block d-md-none mt-4">
        <?php foreach ($monedas as $m): ?>
        <div style="background:#181f2a; border-radius:16px; border:2px solid #00fff7; box-shadow:0 0 24px #00fff733; padding:1rem; color:#00fff7; margin-bottom:1.2rem;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:0.5em;">
                <span style="font-size:1em; color:#00fff7; font-weight:bold;"><?= htmlspecialchars($m['nombre']) ?></span>
                <span style="font-size:1em; color:#b2f6ff;"><?= htmlspecialchars($m['clave']) ?></span>
            </div>
            <div style="color:#fff; margin-bottom:0.3em;"><span style="color:#00fff7; font-weight:bold;">Tasa:</span> <?= htmlspecialchars($m['tasa']) ?></div>
            <div style="color:#fff; margin-bottom:0.3em;">
                <span style="color:#00fff7; font-weight:bold;">Mostrar decimales:</span>
                <form method="post" class="d-inline-flex align-items-center ms-2 m-0">
                    <input type="hidden" name="toggle_decimals_id" value="<?= (int) $m['id'] ?>">
                    <div class="form-check form-switch m-0">
                        <input class="form-check-input" type="checkbox" name="mostrar_decimales" value="1" <?= !empty($m['mostrar_decimales']) ? 'checked' : '' ?> onchange="this.form.submit()" aria-label="Mostrar decimales en <?= htmlspecialchars($m['clave'], ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                </form>
            </div>
            <div style="color:#fff; margin-bottom:0.3em;"><span style="color:#00fff7; font-weight:bold;">Base:</span> <?= $m['es_base'] ? '<span style=\'color:#00fff7;\'>Sí</span>' : 'No' ?></div>
            <div style="display:flex; margin-top:1rem; gap:1rem; flex-wrap:wrap;">
                <?php if (!$m['es_base']): ?>
                    <a href="?editar=<?= $m['id'] ?>" style="color:#00fff7; text-decoration:underline; font-weight:bold;">Editar</a>
                    <a href="?eliminar=<?= $m['id'] ?>" onclick="return confirm('¿Eliminar moneda?')" style="color:#ff0059; text-decoration:underline; font-weight:bold;">Eliminar</a>
                <?php else: ?>
                    <span style="color:#888;">-</span>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <a href="/admin/dashboard" class="btn btn-link text-info">Regresar</a>
</main>
<?php include '../includes/footer.php'; ?>