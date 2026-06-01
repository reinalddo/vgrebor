<?php
// Gestión de monedas: listar, crear, editar, eliminar, conversión
require_once 'includes/db_connect.php';
require_once 'includes/currency.php';

currency_ensure_schema();

// Listar monedas
function listar_monedas($mysqli) {
    $res = $mysqli->query("SELECT * FROM monedas ORDER BY es_base DESC, nombre ASC");
    $monedas = [];
    while ($row = $res->fetch_assoc()) {
        $monedas[] = $row;
    }
    return $monedas;
}

// Crear moneda
function crear_moneda($mysqli, $nombre, $clave, $tasa) {
    $stmt = $mysqli->prepare("INSERT INTO monedas (nombre, clave, tasa) VALUES (?, ?, ?)");
    $stmt->bind_param('ssd', $nombre, $clave, $tasa);
    return $stmt->execute();
}

// Editar moneda
function editar_moneda($mysqli, $id, $nombre, $clave, $tasa, $activo) {
    $stmt = $mysqli->prepare("UPDATE monedas SET nombre=?, clave=?, tasa=?, activo=? WHERE id=? AND es_base=0");
    $stmt->bind_param('ssdii', $nombre, $clave, $tasa, $activo, $id);
    return $stmt->execute();
}

// Eliminar moneda (no base)
function eliminar_moneda($mysqli, $id) {
    $stmt = $mysqli->prepare("DELETE FROM monedas WHERE id=? AND es_base=0");
    $stmt->bind_param('i', $id);
    return $stmt->execute();
}

// Conversión de moneda
function convertir_precio($precio_usd, $tasa) {
    return currency_apply_amount_rule($precio_usd * $tasa, ['tasa' => $tasa, 'mostrar_decimales' => 1]);
}

// Ejemplo de uso (puedes eliminar esto en producción)
/*
$monedas = listar_monedas($mysqli);
foreach ($monedas as $m) {
    echo $m['nombre'] . ' (' . $m['clave'] . ') - Tasa: ' . $m['tasa'] . '<br>';
}
*/
