<?php
// Herramienta de SOLO LECTURA para diagnosticar el reporte "las referencias que empiezan en 0 no se
// reconocen" (api/pedidos.php, verificación de pagos Bs/VES y Binance). No modifica nada — ni la
// tabla movimientos, ni pedidos, ni el criterio de coincidencia. Muestra exactamente cómo quedó
// GUARDADA una referencia (incluye el largo en bytes y su HEX, para descartar caracteres invisibles
// que un simple vistazo no revela) y si, con los dígitos que se le indiquen, movement_reference_matches()
// la consideraría una coincidencia — la MISMA función que usa la verificación real, copiada aquí
// tal cual (ver includes/../api/pedidos.php) solo para poder probarla sin ejecutar todo ese archivo
// (que despacha acciones de compra reales al incluirse).
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

// Copia exacta de api/pedidos.php (normalize_reference_digits / movement_reference_matches) —
// solo para reproducir el mismo veredicto sin incluir ese archivo completo.
if (!function_exists('normalize_reference_digits')) {
    function normalize_reference_digits(string $ref): string {
        $stripped = ltrim($ref, '0');
        return $stripped !== '' ? $stripped : '0';
    }
}
if (!function_exists('movement_reference_matches')) {
    function movement_reference_matches(string $fullReference, string $reportedReference, int $requiredDigits): bool {
        if ($reportedReference === '') {
            return false;
        }
        if ($requiredDigits > 0) {
            $normalizedReportedReference = strlen($reportedReference) > $requiredDigits
                ? substr($reportedReference, -$requiredDigits)
                : $reportedReference;
            $bankSuffix = substr($fullReference, -$requiredDigits);
            return $fullReference === $reportedReference
                || $bankSuffix === $normalizedReportedReference
                || normalize_reference_digits($bankSuffix) === normalize_reference_digits($normalizedReportedReference);
        }
        return $fullReference === $reportedReference
            || normalize_reference_digits($fullReference) === normalize_reference_digits($reportedReference);
    }
}

$buscado = trim((string) ($_GET['ref'] ?? ''));
$digitos = max(0, (int) ($_GET['digitos'] ?? 7));
$monto = trim((string) ($_GET['monto'] ?? ''));
$filas = [];
$filasMonto = [];
$error = '';

if ($buscado !== '') {
    try {
        // Trae cualquier movimiento cuya referencia CONTENGA lo buscado en cualquier posición —
        // pero un simple LIKE '%0020020%' NO encuentra una fila guardada como "20020" (sin los
        // ceros a la izquierda): esa es justo la hipótesis que se está investigando, así que
        // buscar SOLO tal cual daría un falso "no existe" incluso si el movimiento sí está y
        // movement_reference_matches() sí lo reconocería. Por eso se busca también la versión SIN
        // ceros a la izquierda (ltrim, mismo criterio que normalize_reference_digits) como patrón
        // adicional — cubre el caso de que la BD lo haya guardado más corto.
        $soloDigitos = preg_replace('/\D+/', '', $buscado) ?: $buscado;
        $sinCerosIzq = ltrim($soloDigitos, '0');
        if ($sinCerosIzq === '') $sinCerosIzq = $soloDigitos;
        $stmt = $mysqli->prepare(
            "SELECT id, referencia, monto, moneda, fecha_movimiento, creado_en, COALESCE(checked,0) AS checked, COALESCE(pedido_id,0) AS pedido_id
               FROM movimientos
              WHERE referencia LIKE CONCAT('%', ?, '%')
                 OR referencia LIKE CONCAT('%', ?, '%')
                 OR referencia LIKE CONCAT('%', ?, '%')
              ORDER BY id DESC LIMIT 50"
        );
        $stmt->bind_param('sss', $buscado, $soloDigitos, $sinCerosIzq);
        $stmt->execute();
        $filas = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

// Respaldo por MONTO: si por referencia no aparece nada, esto es lo que de verdad zanja la duda —
// si hay un movimiento reciente con el monto exacto del pedido, está ahí sin importar cómo haya
// quedado su referencia (se ve tal cual, para comparar a simple vista contra lo que escribió el
// cliente); si NO hay ninguno, es evidencia real de que el banco aún no lo había reportado, y el
// problema no tiene nada que ver con el cero.
if ($monto !== '' && is_numeric(str_replace(',', '.', $monto))) {
    try {
        $montoNum = (float) str_replace(',', '.', $monto);
        $stmt2 = $mysqli->prepare(
            "SELECT id, referencia, monto, moneda, fecha_movimiento, creado_en, COALESCE(checked,0) AS checked, COALESCE(pedido_id,0) AS pedido_id
               FROM movimientos
              WHERE ABS(monto - ?) < 0.01
              ORDER BY id DESC LIMIT 50"
        );
        $stmt2->bind_param('d', $montoNum);
        $stmt2->execute();
        $filasMonto = $stmt2->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt2->close();
    } catch (Throwable $e) {
        $error = $error !== '' ? $error : $e->getMessage();
    }
}

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Diagnóstico de referencia — TVG</title>
<style>
  body { background:#0b0f1a; color:#d7e2f2; font-family:system-ui,sans-serif; padding:1.5rem; }
  h1 { color:#00fff7; font-size:1.3rem; }
  form { margin-bottom:1.5rem; display:flex; gap:.6rem; flex-wrap:wrap; align-items:end; }
  label { display:block; font-size:.75rem; color:#8be9fd; margin-bottom:.25rem; }
  input { background:#141c2e; color:#00fff7; border:1px solid #22d3ee; border-radius:6px; padding:.5rem .7rem; }
  button { background:#00fff7; color:#04121a; border:none; border-radius:6px; padding:.55rem 1.1rem; font-weight:700; cursor:pointer; }
  table { border-collapse:collapse; width:100%; font-size:.82rem; }
  th, td { border:1px solid #22344d; padding:.5rem .6rem; text-align:left; vertical-align:top; }
  th { background:#141c2e; color:#8be9fd; }
  .ok { color:#4ade80; font-weight:700; }
  .no { color:#f87171; }
  .mono { font-family:'Courier New',monospace; }
  .muted { color:#6b7c93; font-size:.78rem; }
  .aviso { background:#241a10; border:1px solid #f59e0b; color:#fbbf24; padding:.8rem 1rem; border-radius:8px; margin-bottom:1rem; font-size:.85rem; }
</style>
</head>
<body>
<h1>🔍 Diagnóstico de referencia (movimientos)</h1>
<div class="aviso">Herramienta de SOLO LECTURA — no cambia nada. Busca por cualquier parte de la referencia y muestra exactamente cómo quedó guardada (largo en bytes + HEX, para detectar caracteres invisibles).</div>

<form method="get">
  <div>
    <label for="ref">Referencia (completa o los últimos dígitos que escribió el cliente)</label>
    <input type="text" id="ref" name="ref" value="<?= h($buscado) ?>" placeholder="Ej: 0020020" required>
  </div>
  <div>
    <label for="digitos">Dígitos configurados para ese método</label>
    <input type="number" id="digitos" name="digitos" min="0" max="120" value="<?= (int) $digitos ?>" style="width:6rem">
  </div>
  <div>
    <label for="monto">Monto del RECIBO/pago (opcional — Bs para Pago Móvil/BNC, USDT para Binance; respaldo si por referencia no sale nada)</label>
    <input type="text" id="monto" name="monto" value="<?= h($monto) ?>" placeholder="Ej: 7519.00" style="width:9rem">
  </div>
  <button type="submit">Buscar</button>
</form>

<?php if ($error !== ''): ?>
  <div class="aviso" style="border-color:#f87171;color:#f87171;">Error: <?= h($error) ?></div>
<?php elseif ($buscado !== ''): ?>
  <p class="muted">
    Buscado tal cual: "<span class="mono"><?= h($buscado) ?></span>" (<?= strlen($buscado) ?> bytes)
    · Solo dígitos: "<span class="mono"><?= h(preg_replace('/\D+/', '', $buscado) ?: '') ?>"</span>
    · <?= count($filas) ?> resultado(s) en movimientos.
  </p>
  <?php if (!$filas): ?>
    <p class="no">No se encontró ningún movimiento cuya referencia contenga esos caracteres (probado tal cual y también sin ceros a la izquierda). Si el pago es reciente, puede que aún no se haya sincronizado desde el banco.</p>
  <?php else: ?>
  <div class="overflow-x-auto">
  <table>
    <thead><tr>
      <th>ID</th><th>Referencia guardada</th><th>Largo (bytes)</th><th>HEX</th><th>Monto</th><th>Moneda</th>
      <th>Fecha mov.</th><th>Creado</th><th>checked</th><th>pedido_id</th>
      <th>¿Coincide con "<?= h($buscado) ?>" a <?= (int) $digitos ?> dígitos?</th>
    </tr></thead>
    <tbody>
    <?php foreach ($filas as $f):
        $ref = (string) $f['referencia'];
        $coincide = movement_reference_matches($ref, $buscado, $digitos);
    ?>
      <tr>
        <td><?= (int) $f['id'] ?></td>
        <td class="mono"><?= h($ref) ?></td>
        <td><?= strlen($ref) ?></td>
        <td class="mono muted"><?= h(strtoupper(bin2hex($ref))) ?></td>
        <td class="mono"><?= h(number_format((float) $f['monto'], 2)) ?></td>
        <td><?= h((string) $f['moneda']) ?></td>
        <td><?= h((string) ($f['fecha_movimiento'] ?? '—')) ?></td>
        <td><?= h((string) $f['creado_en']) ?></td>
        <td><?= (int) $f['checked'] ?></td>
        <td><?= (int) $f['pedido_id'] ?></td>
        <td class="<?= $coincide ? 'ok' : 'no' ?>"><?= $coincide ? '✓ SÍ coincide' : '✗ No coincide' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
<?php endif; ?>

<?php if ($monto !== ''): ?>
  <hr style="border-color:#22344d;margin:1.5rem 0;">
  <h2 style="color:#00fff7;font-size:1.05rem;">Respaldo por monto (<?= h($monto) ?>)</h2>
  <p class="muted">Esto es lo que de verdad zanja la duda: si aquí aparece un movimiento con el monto exacto, EXISTE sin importar cómo haya quedado su referencia — se compara a simple vista contra lo que escribió el cliente. Si no aparece ninguno, es que el banco todavía no lo había reportado (nada que ver con el cero).</p>
  <?php if (!$filasMonto): ?>
    <p class="no">Ningún movimiento en la tabla tiene ese monto exacto. Esto confirma que, al momento de esta búsqueda, el banco aún no había reportado ese pago — el sistema no tenía con qué compararlo, sin importar cómo estuviera escrita la referencia.</p>
  <?php else: ?>
  <div class="overflow-x-auto">
  <table>
    <thead><tr>
      <th>ID</th><th>Referencia guardada</th><th>Largo (bytes)</th><th>Monto</th><th>Moneda</th>
      <th>Fecha mov.</th><th>Creado</th><th>checked</th><th>pedido_id</th>
      <?php if ($buscado !== ''): ?><th>¿Coincide con "<?= h($buscado) ?>" a <?= (int) $digitos ?> dígitos?</th><?php endif; ?>
    </tr></thead>
    <tbody>
    <?php foreach ($filasMonto as $f):
        $ref = (string) $f['referencia'];
    ?>
      <tr>
        <td><?= (int) $f['id'] ?></td>
        <td class="mono"><?= h($ref) ?></td>
        <td><?= strlen($ref) ?></td>
        <td class="mono"><?= h(number_format((float) $f['monto'], 2)) ?></td>
        <td><?= h((string) $f['moneda']) ?></td>
        <td><?= h((string) ($f['fecha_movimiento'] ?? '—')) ?></td>
        <td><?= h((string) $f['creado_en']) ?></td>
        <td><?= (int) $f['checked'] ?></td>
        <td><?= (int) $f['pedido_id'] ?></td>
        <?php if ($buscado !== ''): $coincide = movement_reference_matches($ref, $buscado, $digitos); ?>
        <td class="<?= $coincide ? 'ok' : 'no' ?>"><?= $coincide ? '✓ SÍ coincide' : '✗ No coincide' ?></td>
        <?php endif; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
<?php endif; ?>

</body>
</html>
