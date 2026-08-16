<?php
// Gestión de características de juegos
require_once __DIR__ . '/includes/tenant.php';
if (session_status() !== PHP_SESSION_ACTIVE) {
    tenant_start_session();
}
$adminRole = trim((string) ($_SESSION['auth_user']['rol'] ?? ''));
if (!isset($_SESSION['auth_user']) || !in_array($adminRole, ['admin', 'root'], true)) {
    header('Location: ' . app_path('/login.php'));
    exit();
}
require_once 'includes/db_connect.php';

// Listar características de un juego
function listar_caracteristicas($mysqli, $juego_id) {
    $stmt = $mysqli->prepare("SELECT * FROM juego_caracteristicas WHERE juego_id=?");
    $stmt->bind_param('i', $juego_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $caracteristicas = [];
    while ($row = $res->fetch_assoc()) {
        $caracteristicas[] = $row;
    }
    return $caracteristicas;
}

// Agregar característica
function agregar_caracteristica($mysqli, $juego_id, $caracteristica) {
    $stmt = $mysqli->prepare("INSERT INTO juego_caracteristicas (juego_id, caracteristica) VALUES (?, ?)");
    $stmt->bind_param('is', $juego_id, $caracteristica);
    return $stmt->execute();
}

// Eliminar característica
function eliminar_caracteristica($mysqli, $id) {
    $stmt = $mysqli->prepare("DELETE FROM juego_caracteristicas WHERE id=?");
    $stmt->bind_param('i', $id);
    return $stmt->execute();
}
