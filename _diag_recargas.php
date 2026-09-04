<?php
/**
 * _diag_recargas.php — DIAGNÓSTICO de un solo uso: por qué las recargas de saldo quedan "pendientes".
 * Sube a la raíz, ábrelo UNA vez (https://TU-DOMINIO/_diag_recargas.php), copia lo que sale y mándamelo.
 * NO muestra tokens/claves. Se AUTOBORRA al terminar.
 */
require __DIR__ . '/includes/db.php'; // $pdo
header('Content-Type: text/plain; charset=utf-8');
function row($q, $pdo) { try { return $pdo->query($q)->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) { return ['ERROR' => $e->getMessage()]; } }

echo "==== DIAGNÓSTICO RECARGAS (" . date('Y-m-d H:i') . ") ====\n\n";

echo "-- MÉTODOS DE PAGO (nombre → moneda → TASA que usa el sistema para casar) --\n";
foreach (row("SELECT pm.id, pm.nombre, pm.activo, mo.clave AS moneda, mo.tasa FROM payment_methods pm LEFT JOIN monedas mo ON mo.id=pm.moneda_id ORDER BY pm.activo DESC, pm.id", $pdo) as $r) {
    echo sprintf("  [%s] %-28s activo=%s  moneda=%-6s tasa=%s\n", $r['id'] ?? '?', $r['nombre'] ?? '?', $r['activo'] ?? '?', $r['moneda'] ?? '—', $r['tasa'] ?? '—');
}

echo "\n-- MONEDAS (todas las filas de tasa) --\n";
foreach (row("SELECT id, clave, nombre, tasa, activo, es_base FROM monedas ORDER BY es_base DESC, id", $pdo) as $r) {
    echo sprintf("  [%s] clave=%-6s %-16s tasa=%s activo=%s base=%s\n", $r['id'] ?? '?', $r['clave'] ?? '?', $r['nombre'] ?? '', $r['tasa'] ?? '—', $r['activo'] ?? '?', $r['es_base'] ?? '?');
}

echo "\n-- ÚLTIMOS 12 MOVIMIENTOS sincronizados (ref enmascarada) --\n";
$movs = row("SELECT referencia, monto, moneda, COALESCE(checked,0) checked, COALESCE(wallet_recarga_id,0) wr, fecha_movimiento FROM movimientos ORDER BY id DESC LIMIT 12", $pdo);
if (!$movs) { echo "  (NINGUNO — la sincronización del banco/binance NO está trayendo movimientos)\n"; }
foreach ($movs as $r) {
    $ref = (string) ($r['referencia'] ?? ''); $refm = strlen($ref) > 10 ? substr($ref, 0, 8) . '…' . substr($ref, -4) : $ref;
    echo sprintf("  %-16s monto=%-10s %-5s usado=%s  %s\n", $refm, $r['monto'] ?? '?', $r['moneda'] ?? '?', (($r['checked'] ?? 0) || ($r['wr'] ?? 0)) ? 'sí' : 'no', $r['fecha_movimiento'] ?? '');
}

echo "\n-- ÚLTIMAS 12 RECARGAS de saldo (estado) --\n";
foreach (row("SELECT monto, metodo, referencia, estado, creado_en FROM wallet_recargas ORDER BY id DESC LIMIT 12", $pdo) as $r) {
    $ref = (string) ($r['referencia'] ?? ''); $refm = strlen($ref) > 10 ? substr($ref, 0, 6) . '…' . substr($ref, -4) : $ref;
    echo sprintf("  \$%-8s %-16s ref=%-16s estado=%-10s %s\n", $r['monto'] ?? '?', $r['metodo'] ?? '?', $refm, $r['estado'] ?? '?', $r['creado_en'] ?? '');
}

echo "\n-- CONFIG de pagos (solo SI están puestas, sin mostrar el valor) --\n";
foreach (['binance_pagonorte_token', 'binance_streaming_referencia_digitos', 'ff_bank_api_base_url', 'ff_bank_token', 'binance_pagonorte_referencia_digitos'] as $k) {
    $v = '';
    foreach (['configuracion_general', 'configuracion'] as $t) {
        try { $s = $pdo->prepare("SELECT valor FROM $t WHERE clave=? LIMIT 1"); $s->execute([$k]); $vv = $s->fetchColumn(); if ($vv !== false && $vv !== null && $vv !== '') { $v = $vv; break; } } catch (Throwable $e) {}
    }
    echo sprintf("  %-42s %s\n", $k, $v === '' ? 'VACÍO' : (is_numeric($v) ? $v : 'puesta (' . strlen((string) $v) . ' chars)'));
}

@unlink(__FILE__);
echo "\n==== fin. Este archivo se borró solo. Copia TODO esto y mándamelo. ====\n";
