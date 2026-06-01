<?php
// Gestión de juegos: listar, crear, editar, eliminar
require_once 'includes/db_connect.php';
require_once 'includes/slugify.php';

// Listar juegos
function listar_juegos($mysqli) {
    $res = $mysqli->query("SELECT * FROM juegos ORDER BY nombre ASC");
    $juegos = [];
    while ($row = $res->fetch_assoc()) {
        $juegos[] = $row;
    }
    return $juegos;
}

// Crear juego
function crear_juego($mysqli, $nombre, $imagen, $descripcion, $moneda_fija_id = null) {
    $slug = slugify($nombre);
    $stmt = $mysqli->prepare("INSERT INTO juegos (nombre, imagen, descripcion, slug, moneda_fija_id) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param('ssssi', $nombre, $imagen, $descripcion, $slug, $moneda_fija_id);
    return $stmt->execute();
}

// Editar juego
function editar_juego($mysqli, $id, $nombre, $imagen, $descripcion, $moneda_fija_id = null) {
    $slug = slugify($nombre);
    $stmt = $mysqli->prepare("UPDATE juegos SET nombre=?, imagen=?, descripcion=?, slug=?, moneda_fija_id=? WHERE id=?");
    $stmt->bind_param('ssssii', $nombre, $imagen, $descripcion, $slug, $moneda_fija_id, $id);
    return $stmt->execute();
}

// Eliminar juego
function eliminar_juego($mysqli, $id) {
    $stmt = $mysqli->prepare("DELETE FROM juegos WHERE id=?");
    $stmt->bind_param('i', $id);
    return $stmt->execute();
}
