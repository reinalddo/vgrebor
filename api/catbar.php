<?php
// api/catbar.php — Datos para la barra de navegación de categorías del frontend
require_once '../includes/db_connect.php';
require_once '../includes/tenant.php';
require_once '../includes/slugify.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function cb_ok(array $data): void {
    echo json_encode(array_merge(['ok' => true], $data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function cb_err(string $msg, int $status = 400): void {
    http_response_code($status);
    echo json_encode(['ok' => false, 'message' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

$action = trim((string) ($_GET['action'] ?? ''));

// ── Juegos por categoría ──────────────────────────────────────────────────
if ($action === 'games') {
    $catId = (int) ($_GET['cat_id'] ?? 0);
    if ($catId <= 0) cb_err('cat_id requerido.');

    $stmt = $mysqli->prepare(
        'SELECT j.id, j.nombre, j.slug, j.imagen
         FROM juegos j
         INNER JOIN juego_categoria_asignada a ON a.juego_id = j.id
         WHERE a.categoria_id = ? AND COALESCE(j.activo, 1) = 1
         ORDER BY j.orden ASC, j.id ASC'
    );
    if (!$stmt) cb_err('Error de base de datos.', 500);
    $stmt->bind_param('i', $catId);
    $stmt->execute();
    $result = $stmt->get_result();

    $games = [];
    while ($row = $result->fetch_assoc()) {
        $games[] = [
            'id'     => (int) $row['id'],
            'nombre' => (string) $row['nombre'],
            'imagen' => (string) ($row['imagen'] ?? ''),
            'url'    => app_path(game_route_path($row)),
        ];
    }
    $stmt->close();
    cb_ok(['games' => $games]);
}

// ── Paquetes por juego ────────────────────────────────────────────────────
if ($action === 'packages') {
    $gameId = (int) ($_GET['game_id'] ?? 0);
    if ($gameId <= 0) cb_err('game_id requerido.');

    $stmtG = $mysqli->prepare('SELECT id, nombre, slug FROM juegos WHERE id = ? LIMIT 1');
    if (!$stmtG) cb_err('Error de base de datos.', 500);
    $stmtG->bind_param('i', $gameId);
    $stmtG->execute();
    $game = $stmtG->get_result()->fetch_assoc();
    $stmtG->close();
    if (!$game) cb_err('Juego no encontrado.', 404);

    $gameUrl = app_path(game_route_path($game));

    $stmtP = $mysqli->prepare(
        'SELECT id, nombre, imagen_icono, precio
         FROM juego_paquetes
         WHERE juego_id = ? AND activo = 1
         ORDER BY orden ASC, id ASC'
    );
    if (!$stmtP) cb_err('Error de base de datos.', 500);
    $stmtP->bind_param('i', $gameId);
    $stmtP->execute();
    $result = $stmtP->get_result();

    $packages = [];
    while ($row = $result->fetch_assoc()) {
        $packages[] = [
            'id'          => (int) $row['id'],
            'nombre'      => (string) $row['nombre'],
            'imagen_icono'=> (string) ($row['imagen_icono'] ?? ''),
            'precio'      => (string) ($row['precio'] ?? ''),
            'url'         => $gameUrl . '?package_id=' . (int) $row['id'],
        ];
    }
    $stmtP->close();
    cb_ok(['packages' => $packages, 'game_url' => $gameUrl]);
}

cb_err('Acción no reconocida.');
