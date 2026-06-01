<?php
// Gestión de paquetes de juegos
require_once 'includes/db_connect.php';

// Listar paquetes de un juego
function listar_paquetes($mysqli, $juego_id) {
    $stmt = $mysqli->prepare("SELECT * FROM juego_paquetes WHERE juego_id=?");
    $stmt->bind_param('i', $juego_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $paquetes = [];
    while ($row = $res->fetch_assoc()) {
        $paquetes[] = $row;
    }
    return $paquetes;
}

// Crear paquete
function crear_paquete($mysqli, $juego_id, $nombre, $clave, $cantidad, $precio, $imagen_icono) {
    $stmt = $mysqli->prepare("INSERT INTO juego_paquetes (juego_id, nombre, clave, cantidad, precio, imagen_icono) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param('issids', $juego_id, $nombre, $clave, $cantidad, $precio, $imagen_icono);
    return $stmt->execute();
}

// Editar paquete
function editar_paquete($mysqli, $id, $nombre, $clave, $cantidad, $precio, $imagen_icono, $activo) {
    $stmt = $mysqli->prepare("UPDATE juego_paquetes SET nombre=?, clave=?, cantidad=?, precio=?, imagen_icono=?, activo=? WHERE id=?");
    $stmt->bind_param('ssidiii', $nombre, $clave, $cantidad, $precio, $imagen_icono, $activo, $id);
    return $stmt->execute();
}

// Eliminar paquete
function eliminar_paquete($mysqli, $id) {
    $stmt = $mysqli->prepare("DELETE FROM juego_paquetes WHERE id=?");
    $stmt->bind_param('i', $id);
    return $stmt->execute();
}
