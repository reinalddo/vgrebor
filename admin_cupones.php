<?php
// admin_cupones.php
require_once 'includes/auth.php';
require_once 'includes/db_connect.php';
require_once 'includes/slugify.php';

// Helper para URLs amigables
define('ADMIN_CUPONES_BASE', '/admin/cupones');

function url_cupon($action, $id = null) {
    $base = ADMIN_CUPONES_BASE;
    switch ($action) {
        case 'nuevo': return "$base/nuevo";
        case 'editar': return "$base/editar/" . intval($id);
        case 'eliminar': return "$base/eliminar/" . intval($id);
        case 'activar': return "$base/activar/" . intval($id);
        case 'desactivar': return "$base/desactivar/" . intval($id);
        default: return $base;
    }
}

// Routing básico para CRUD amigable
$uri = $_SERVER['REQUEST_URI'];
$path = parse_url($uri, PHP_URL_PATH);
$action = null;
$id = null;

if (preg_match('#^' . ADMIN_CUPONES_BASE . '/(nuevo)$#', $path, $m)) {
    $action = 'nuevo';
} elseif (preg_match('#^' . ADMIN_CUPONES_BASE . '/(editar|eliminar|activar|desactivar)/(\d+)$#', $path, $m)) {
    $action = $m[1];
    $id = (int)$m[2];
} else {
    $action = 'listar';
}

// CRUD
// Salida dinámica para el contenido principal
$contenido = '';

if ($action === 'nuevo') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $codigo = trim($_POST['codigo'] ?? '');
        $tipo_descuento = $_POST['tipo_descuento'] ?? 'porcentaje';
        $valor_descuento = floatval($_POST['valor_descuento'] ?? 0);
        $fecha_expiracion = $_POST['fecha_expiracion'] ?? null;
        $limite_usos = $_POST['limite_usos'] !== '' ? intval($_POST['limite_usos']) : null;
        $activo = isset($_POST['activo']) ? 1 : 0;

        $errores = [];
        if ($codigo === '') $errores[] = 'El código es obligatorio.';
        if ($valor_descuento < 0) $errores[] = 'El valor de descuento no puede ser menor a 0.';
        if (!in_array($tipo_descuento, ['porcentaje', 'fijo'])) $errores[] = 'Tipo de descuento inválido.';

        if (empty($errores)) {
            $stmt = $db->prepare("INSERT INTO cupones (codigo, tipo_descuento, valor_descuento, fecha_expiracion, limite_usos, activo) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $codigo,
                $tipo_descuento,
                $valor_descuento,
                $fecha_expiracion !== '' ? $fecha_expiracion : null,
                $limite_usos,
                $activo
            ]);
            header('Location: ' . ADMIN_CUPONES_BASE);
            exit;
        }
    }
    $contenido .= '<h2>Nuevo Cupón</h2>';
    if (!empty($errores)) {
        $contenido .= '<div class="errores"><ul><li>' . implode('</li><li>', $errores) . '</li></ul></div>';
    }
    $contenido .= '<form method="post" action="'.url_cupon('nuevo').'" class="neon-form">';
    $contenido .= '<label>Código: <input type="text" name="codigo" required></label>';
    $contenido .= '<label>Tipo de descuento: <select name="tipo_descuento"><option value="porcentaje">Porcentaje (%)</option><option value="fijo">Monto fijo</option></select></label>';
    $contenido .= '<label>Valor descuento: <input type="number" name="valor_descuento" step="0.01" min="0" required></label>';
    $contenido .= '<label>Fecha expiración: <input type="datetime-local" name="fecha_expiracion"></label>';
    $contenido .= '<label>Límite de usos: <input type="number" name="limite_usos" min="0" placeholder="0 = ilimitado"></label>';
    $contenido .= '<div class="checkbox"><input type="checkbox" name="activo" checked> <span>Cupón activo</span></div>';
    $contenido .= '<button type="submit">Crear cupón</button>';
    $contenido .= '<a href="'.ADMIN_CUPONES_BASE.'" class="btn">Cancelar</a>';
    $contenido .= '</form>';
} elseif ($action === 'editar' && $id) {
    $stmt = $db->prepare("SELECT * FROM cupones WHERE id = ?");
    $stmt->execute([$id]);
    $cupon = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$cupon) {
        $contenido .= '<p>Cupón no encontrado.</p>';
    } else {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $codigo = trim($_POST['codigo'] ?? '');
            $tipo_descuento = $_POST['tipo_descuento'] ?? 'porcentaje';
            $valor_descuento = floatval($_POST['valor_descuento'] ?? 0);
            $fecha_expiracion = $_POST['fecha_expiracion'] ?? null;
            $limite_usos = $_POST['limite_usos'] !== '' ? intval($_POST['limite_usos']) : null;
            $activo = isset($_POST['activo']) ? 1 : 0;
            $errores = [];
            if ($codigo === '') $errores[] = 'El código es obligatorio.';
            if ($valor_descuento < 0) $errores[] = 'El valor de descuento no puede ser menor a 0.';
            if (!in_array($tipo_descuento, ['porcentaje', 'fijo'])) $errores[] = 'Tipo de descuento inválido.';
            if (empty($errores)) {
                $stmt2 = $db->prepare("UPDATE cupones SET codigo=?, tipo_descuento=?, valor_descuento=?, fecha_expiracion=?, limite_usos=?, activo=? WHERE id=?");
                $stmt2->execute([
                    $codigo,
                    $tipo_descuento,
                    $valor_descuento,
                    $fecha_expiracion !== '' ? $fecha_expiracion : null,
                    $limite_usos,
                    $activo,
                    $id
                ]);
                header('Location: ' . ADMIN_CUPONES_BASE);
                exit;
            }
        } else {
            $codigo = $cupon['codigo'];
            $tipo_descuento = $cupon['tipo_descuento'];
            $valor_descuento = $cupon['valor_descuento'];
            $fecha_expiracion = $cupon['fecha_expiracion'];
            $limite_usos = $cupon['limite_usos'];
            $activo = $cupon['activo'];
        }
        $contenido .= '<h2>Editar Cupón</h2>';
        if (!empty($errores)) {
            $contenido .= '<div class="errores"><ul><li>' . implode('</li><li>', $errores) . '</li></ul></div>';
        }
        $contenido .= '<form method="post" action="'.url_cupon('editar', $id).'" class="neon-form">';
        $contenido .= '<label>Código: <input type="text" name="codigo" value="'.htmlspecialchars($codigo).'" required></label>';
        $contenido .= '<label>Tipo de descuento: <select name="tipo_descuento">';
        $contenido .= '<option value="porcentaje"'.($tipo_descuento=='porcentaje'?' selected':'').'>Porcentaje (%)</option>';
        $contenido .= '<option value="fijo"'.($tipo_descuento=='fijo'?' selected':'').'>Monto fijo</option>';
        $contenido .= '</select></label>';
        $contenido .= '<label>Valor descuento: <input type="number" name="valor_descuento" step="0.01" min="0" value="'.htmlspecialchars($valor_descuento).'" required></label>';
        $contenido .= '<label>Fecha expiración: <input type="datetime-local" name="fecha_expiracion" value="'.($fecha_expiracion ? date('Y-m-d\TH:i', strtotime($fecha_expiracion)) : '').'"></label>';
        $contenido .= '<label>Límite de usos: <input type="number" name="limite_usos" min="0" value="'.htmlspecialchars($limite_usos).'" placeholder="0 = ilimitado"></label>';
        $contenido .= '<div class="checkbox"><input type="checkbox" name="activo"'.($activo?' checked':'').'> <span>Cupón activo</span></div>';
        $contenido .= '<button type="submit">Guardar cambios</button>';
        $contenido .= '<a href="'.ADMIN_CUPONES_BASE.'" class="btn">Cancelar</a>';
        $contenido .= '</form>';
    }
} elseif ($action === 'eliminar' && $id) {
    $stmt = $db->prepare("DELETE FROM cupones WHERE id = ?");
    $stmt->execute([$id]);
    header('Location: ' . ADMIN_CUPONES_BASE);
    exit;
} elseif ($action === 'activar' && $id) {
    $stmt = $db->prepare("UPDATE cupones SET activo = 1 WHERE id = ?");
    $stmt->execute([$id]);
    header('Location: ' . ADMIN_CUPONES_BASE);
    exit;
} elseif ($action === 'desactivar' && $id) {
    $stmt = $db->prepare("UPDATE cupones SET activo = 0 WHERE id = ?");
    $stmt->execute([$id]);
    header('Location: ' . ADMIN_CUPONES_BASE);
    exit;
} else {
    // Listar cupones
    $stmt = $db->query("SELECT * FROM cupones ORDER BY id DESC");
    $cupones = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $contenido .= '<a href="'.url_cupon('nuevo').'" class="btn">Nuevo cupón</a>';
    $contenido .= '<h2>Listado de Cupones</h2>';
    if (empty($cupones)) {
        $contenido .= '<p>No hay cupones registrados.</p>';
    } else {
        $contenido .= '<table class="neon-table"><tr><th>ID</th><th>Código</th><th>Tipo</th><th>Valor</th><th>Expira</th><th>Límite usos</th><th>Usos actuales</th><th>Activo</th><th>Acciones</th></tr>';
        foreach ($cupones as $c) {
            $contenido .= '<tr>';
            $contenido .= '<td style="background:#181f2a; color:#00fff7;">'.$c['id'].'</td>';
            $contenido .= '<td style="background:#181f2a; color:#00fff7;">'.$c['codigo'].'</td>';
            $contenido .= '<td style="background:#181f2a; color:#00fff7;">'.$c['tipo_descuento'].'</td>';
            $contenido .= '<td style="background:#181f2a; color:#00fff7;">'.$c['valor_descuento'].'</td>';
            $contenido .= '<td style="background:#181f2a; color:#00fff7;">'.($c['fecha_expiracion'] ? $c['fecha_expiracion'] : '-').'</td>';
            $contenido .= '<td style="background:#181f2a; color:#00fff7;">'.($c['limite_usos'] ?? '-').'</td>';
            $contenido .= '<td style="background:#181f2a; color:#00fff7;">'.$c['usos_actuales'].'</td>';
            $contenido .= '<td style="background:#181f2a; color:#00fff7;">'.($c['activo'] ? 'Sí' : 'No').'</td>';
            $contenido .= '<td style="background:#181f2a; color:#00fff7;">';
            $contenido .= '<a href="'.url_cupon('editar', $c['id']).'" style="color:#00fff7; text-decoration:underline; margin-right:1em;">Editar</a>';
            if ($c['activo']) {
                $contenido .= '<a href="'.url_cupon('desactivar', $c['id']).'" style="color:#00fff7; text-decoration:underline; margin-right:1em;">Desactivar</a> | ';
            } else {
                $contenido .= '<a href="'.url_cupon('activar', $c['id']).'" style="color:#00fff7; text-decoration:underline; margin-right:1em;">Activar</a> | ';
            }
            $contenido .= '<a href="'.url_cupon('eliminar', $c['id']).'" style="color:#ff0059; text-decoration:underline;" onclick="return confirm(\'¿Eliminar este cupón?\')">Eliminar</a>';
            $contenido .= '</td>';
            $contenido .= '</tr>';
        }
        $contenido .= '</table>';
    }
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
        <meta charset="UTF-8">
        <title>Administrar Cupones</title>
        <style>
            body { background: #101622; color: #00fff7; font-family: 'Space Grotesk', sans-serif; }
            h1, h2 { color: #00fff7; text-shadow: 0 0 8px #00fff7; }
            .neon-box { background: #181f2a; border-radius: 16px; border: 2px solid #00fff7; box-shadow: 0 0 24px #00fff733; padding: 1.5rem; margin-bottom: 2rem; }
            .neon-table { width: 100%; background: #181f2a; color: #00fff7; border-radius: 12px; border: 2px solid #00fff7; box-shadow: 0 0 24px #00fff733; margin-bottom: 2rem; }
            .neon-table th, .neon-table td { background: #181f2a; color: #00fff7; border-bottom: 1px solid #00fff7; padding: 0.5em 0.7em; }
            .neon-table th { color: #00fff7; font-weight: bold; border-bottom: 2px solid #00fff7; }
            .neon-table tr:last-child td { border-bottom: none; }
            .neon-form label { color: #00fff7; font-weight: bold; margin-top: 0.5em; display: block; }
            .neon-form input, .neon-form select { background: #222c3a; color: #00fff7; border: 1px solid #00fff7; border-radius: 8px; padding: 0.5em; margin-bottom: 0.5em; width: 100%; }
            .neon-form button, .neon-form a.btn { background: #00fff7; color: #181f2a; border: none; border-radius: 8px; box-shadow: 0 0 8px #00fff7; font-weight: bold; padding: 0.5em 1.2em; margin-right: 0.5em; text-decoration: none; display: inline-block; }
            .neon-form button:hover, .neon-form a.btn:hover { background: #00b3ff; color: #fff; }
            .neon-form .checkbox { display: flex; align-items: center; gap: 0.5em; margin-bottom: 0.5em; }
            .errores { background: #ff0059; color: #fff; border-radius: 8px; padding: 0.5em 1em; margin-bottom: 1em; }
            @media (max-width: 768px) {
                .neon-table { display: none; }
                .cupon-card { background: #181f2a; border-radius: 16px; border: 2px solid #00fff7; box-shadow: 0 0 24px #00fff733; color: #00fff7; margin-bottom: 1.2rem; padding: 1rem; }
                .cupon-card .acciones a { color: #00fff7; text-decoration: underline; margin-right: 1em; }
                .cupon-card .acciones a:last-child { color: #ff0059; }
            }
            @media (min-width: 769px) {
                .cupones-cards { display: none; }
            }
        </style>
</head>
<body>
    <main class="container-lg mt-5 bg-dark bg-opacity-75 rounded-4 p-4 shadow">
        <h2 class="text-center mb-4" style="color:#00fff7;">Gestión de Cupones</h2>
        <form method="post" action="<?= url_cupon('nuevo') ?>" class="row g-3 mb-4" style="background:#181f2a; border-radius:16px; border:2px solid #00fff7; box-shadow:0 0 24px #00fff733; padding:2rem;">
            <div class="col-md-4">
                <label class="form-label" style="color:#00fff7;">Código del cupón</label>
                <input type="text" name="codigo" required class="form-control" style="background:#222c3a; color:#00fff7; border:1px solid #00fff7;">
            </div>
            <div class="col-md-4">
                <label class="form-label" style="color:#00fff7;">Tipo de descuento</label>
                <select name="tipo_descuento" class="form-select" style="background:#222c3a; color:#00fff7; border:1px solid #00fff7;">
                    <option value="porcentaje">Porcentaje (%)</option>
                    <option value="fijo">Monto fijo</option>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label" style="color:#00fff7;">Valor del descuento</label>
                <input type="number" name="valor_descuento" step="0.01" min="0" required class="form-control" style="background:#222c3a; color:#00fff7; border:1px solid #00fff7;">
            </div>
            <div class="col-md-4">
                <label class="form-label" style="color:#00fff7;">Fecha expiración</label>
                <input type="datetime-local" name="fecha_expiracion" class="form-control" style="background:#222c3a; color:#00fff7; border:1px solid #00fff7;">
            </div>
            <div class="col-md-4">
                <label class="form-label" style="color:#00fff7;">Límite de usos</label>
                <input type="number" name="limite_usos" min="0" placeholder="0 = ilimitado" class="form-control" style="background:#222c3a; color:#00fff7; border:1px solid #00fff7;">
            </div>
            <div class="col-md-4 d-flex align-items-end">
                <div class="form-check">
                    <input type="checkbox" name="activo" class="form-check-input" id="activoCheck" checked>
                    <label class="form-check-label" for="activoCheck" style="color:#00fff7;">Cupón activo</label>
                </div>
            </div>
            <div class="col-12">
                <button type="submit" class="btn btn-info w-100" style="background:#00fff7; color:#222; border:none; box-shadow:0 0 8px #00fff7;">Crear cupón</button>
            </div>
        </form>
        <h3 class="text-info mt-5 mb-3">Cupones existentes</h3>
        <div class="table-responsive d-none d-md-block">
            <table class="table align-middle" style="background:#181f2a; color:#00fff7; border-radius:12px;">
                <thead style="background:#181f2a; color:#00fff7; border-bottom:2px solid #00fff7;">
                    <tr>
                        <th style="color:#00fff7; background:#181f2a;">ID</th>
                        <th style="color:#00fff7; background:#181f2a;">Código</th>
                        <th style="color:#00fff7; background:#181f2a;">Tipo</th>
                        <th style="color:#00fff7; background:#181f2a;">Valor</th>
                        <th style="color:#00fff7; background:#181f2a;">Expira</th>
                        <th style="color:#00fff7; background:#181f2a;">Límite usos</th>
                        <th style="color:#00fff7; background:#181f2a;">Usos actuales</th>
                        <th style="color:#00fff7; background:#181f2a;">Activo</th>
                        <th style="color:#00fff7; background:#181f2a;">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($cupones as $c): ?>
                    <tr style="background:#181f2a; color:#fff;">
                        <td style="background:#181f2a; color:#00fff7;"><?= $c['id'] ?></td>
                        <td style="background:#181f2a; color:#00fff7;"><?= htmlspecialchars($c['codigo']) ?></td>
                        <td style="background:#181f2a; color:#00fff7;"><?= htmlspecialchars($c['tipo_descuento']) ?></td>
                        <td style="background:#181f2a; color:#00fff7;"><?= htmlspecialchars($c['valor_descuento']) ?></td>
                        <td style="background:#181f2a; color:#00fff7;"><?= $c['fecha_expiracion'] ? htmlspecialchars($c['fecha_expiracion']) : '-' ?></td>
                        <td style="background:#181f2a; color:#00fff7;"><?= $c['limite_usos'] ?? '-' ?></td>
                        <td style="background:#181f2a; color:#00fff7;"><?= $c['usos_actuales'] ?></td>
                        <td style="background:#181f2a; color:#00fff7;"><?= $c['activo'] ? 'Sí' : 'No' ?></td>
                        <td style="background:#181f2a;">
                            <a href="<?= url_cupon('editar', $c['id']) ?>" style="color:#00fff7; text-decoration:underline; margin-right:1em;">Editar</a>
                            <?php if ($c['activo']): ?>
                                <a href="<?= url_cupon('desactivar', $c['id']) ?>" style="color:#00fff7; text-decoration:underline; margin-right:1em;">Desactivar</a>
                            <?php else: ?>
                                <a href="<?= url_cupon('activar', $c['id']) ?>" style="color:#00fff7; text-decoration:underline; margin-right:1em;">Activar</a>
                            <?php endif; ?>
                            <a href="<?= url_cupon('eliminar', $c['id']) ?>" style="color:#ff0059; text-decoration:underline;" onclick="return confirm('¿Eliminar este cupón?')">Eliminar</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <!-- Mobile Cards -->
        <div class="d-block d-md-none space-y-4">
            <?php foreach ($cupones as $c): ?>
                <div style="background:#181f2a; border-radius:16px; border:2px solid #00fff7; box-shadow:0 0 24px #00fff733; padding:1rem; color:#00fff7; margin-bottom:1.2rem;">
                    <div style="font-weight:bold; font-size:1.2em; color:#00fff7; display:flex; align-items:center;">
                        <?= htmlspecialchars($c['codigo']) ?>
                        <span style="font-size:0.9em; color:#b2f6ff; margin-left:0.5em;">ID: <?= $c['id'] ?></span>
                    </div>
                    <div style="margin-top:0.5em; color:#fff;"><span style="color:#00fff7; font-weight:bold;">Tipo:</span> <?= htmlspecialchars($c['tipo_descuento']) ?> | <span style="color:#00fff7; font-weight:bold;">Valor:</span> <?= htmlspecialchars($c['valor_descuento']) ?></div>
                    <div style="margin-top:0.5em; color:#fff;"><span style="color:#00fff7; font-weight:bold;">Expira:</span> <?= $c['fecha_expiracion'] ? htmlspecialchars($c['fecha_expiracion']) : '-' ?></div>
                    <div style="margin-top:0.5em; color:#fff;"><span style="color:#00fff7; font-weight:bold;">Límite usos:</span> <?= $c['limite_usos'] ?? '-' ?> | <span style="color:#00fff7; font-weight:bold;">Usos actuales:</span> <?= $c['usos_actuales'] ?></div>
                    <div style="margin-top:0.5em; color:#fff;"><span style="color:#00fff7; font-weight:bold;">Activo:</span> <?= $c['activo'] ? 'Sí' : 'No' ?></div>
                    <div style="display:flex; gap:1rem; margin-top:1rem;">
                        <a href="<?= url_cupon('editar', $c['id']) ?>" style="color:#00fff7; text-decoration:underline; font-weight:bold;">Editar</a>
                        <?php if ($c['activo']): ?>
                            <a href="<?= url_cupon('desactivar', $c['id']) ?>" style="color:#00fff7; text-decoration:underline; font-weight:bold;">Desactivar</a>
                        <?php else: ?>
                            <a href="<?= url_cupon('activar', $c['id']) ?>" style="color:#00fff7; text-decoration:underline; font-weight:bold;">Activar</a>
                        <?php endif; ?>
                        <a href="<?= url_cupon('eliminar', $c['id']) ?>" style="color:#ff0059; text-decoration:underline; font-weight:bold;" onclick="return confirm('¿Eliminar este cupón?')">Eliminar</a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </main>
</body>
</html>
