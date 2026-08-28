<?php
// API del sistema de Comentarios/Reseñas.
// Acciones públicas (sin sesión): listar, resumen.
// Acciones con sesión: mis_pedidos, publicar, editar, like, mis_comentarios.
//
// Toda la lógica de negocio vive en includes/comentarios.php — este archivo
// solo valida entrada, resuelve la sesión y serializa la respuesta (mismo
// criterio que api/account.php).
require_once __DIR__ . '/../includes/tenant.php';
tenant_start_session();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/store_config.php';
require_once __DIR__ . '/../includes/win_points.php';
require_once __DIR__ . '/../includes/comentarios.php';
require_once __DIR__ . '/../includes/notificaciones.php';

function comentarios_api_error(string $message, int $status = 400, array $extra = []): void {
    http_response_code($status);
    echo json_encode(array_merge(['ok' => false, 'message' => $message], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

function comentarios_api_ok(array $payload = []): void {
    echo json_encode(array_merge(['ok' => true], $payload), JSON_UNESCAPED_UNICODE);
    exit;
}

function comentarios_api_usuario_id(): int {
    $user = $_SESSION['auth_user'] ?? null;
    if (!is_array($user) || empty($user['id'])) {
        return 0;
    }
    return (int) $user['id'];
}

function comentarios_api_requiere_sesion(): int {
    $usuarioId = comentarios_api_usuario_id();
    if ($usuarioId <= 0) {
        comentarios_api_error('Debes iniciar sesión para continuar.', 401);
    }
    return $usuarioId;
}

// Acepta tanto JSON como form-urlencoded (el formulario del modal manda
// FormData, y el registro integrado manda JSON).
function comentarios_api_input(): array {
    $raw = file_get_contents('php://input');
    if (is_string($raw) && trim($raw) !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return array_merge($_POST, $decoded);
        }
    }
    return $_POST;
}

$accion = trim((string) ($_GET['action'] ?? $_POST['action'] ?? ''));
$usuarioId = comentarios_api_usuario_id();

switch ($accion) {
    // ── Público: listado paginado + resumen para la sección del home ──
    case 'listar': {
        $filtro = (int) ($_GET['estrellas'] ?? 0);
        $pagina = (int) ($_GET['pagina'] ?? 1);
        $listado = comentarios_listar_publicos($mysqli, $filtro, $pagina, $usuarioId);
        comentarios_api_ok([
            'resumen' => comentarios_resumen_calificaciones($mysqli),
            'listado' => $listado,
            'sesion' => [
                'logueado' => $usuarioId > 0,
                'puede_comentar' => $usuarioId > 0 && comentarios_usuario_puede_comentar($mysqli, $usuarioId),
            ],
        ]);
        break;
    }

    // ── Pedidos del usuario disponibles para comentar (1 por pedido) ──
    case 'mis_pedidos': {
        $uid = comentarios_api_requiere_sesion();
        comentarios_api_ok([
            'pedidos' => comentarios_pedidos_disponibles($mysqli, $uid),
            'mis_comentarios' => comentarios_mis_comentarios($mysqli, $uid),
            'config' => [
                'min_caracteres' => comentarios_min_caracteres(),
                'max_caracteres' => comentarios_max_caracteres(),
                'recompensa' => comentarios_recompensa_publicar(),
                'penalizacion_edicion' => comentarios_penalizacion_edicion(),
                'saldo_recoins' => function_exists('win_points_wallet_balance') ? win_points_wallet_balance($mysqli, $uid) : 0,
            ],
        ]);
        break;
    }

    case 'publicar': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            comentarios_api_error('Método no permitido.', 405);
        }
        $uid = comentarios_api_requiere_sesion();
        $input = comentarios_api_input();
        $resultado = comentarios_publicar(
            $mysqli,
            $uid,
            (int) ($input['pedido_id'] ?? 0),
            $input['estrellas'] ?? 0,
            (string) ($input['texto'] ?? '')
        );
        if (!$resultado['ok']) {
            comentarios_api_error($resultado['message'], 422);
        }
        comentarios_api_ok($resultado);
        break;
    }

    case 'editar': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            comentarios_api_error('Método no permitido.', 405);
        }
        $uid = comentarios_api_requiere_sesion();
        $input = comentarios_api_input();
        $resultado = comentarios_editar(
            $mysqli,
            $uid,
            (int) ($input['comentario_id'] ?? 0),
            $input['estrellas'] ?? 0,
            (string) ($input['texto'] ?? '')
        );
        if (!$resultado['ok']) {
            // saldo_insuficiente se propaga para que el frontend pueda
            // mostrar el aviso de "recarga para poder editar".
            comentarios_api_error($resultado['message'], 422, [
                'saldo_insuficiente' => !empty($resultado['saldo_insuficiente']),
            ]);
        }
        comentarios_api_ok($resultado);
        break;
    }

    case 'like': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            comentarios_api_error('Método no permitido.', 405);
        }
        $uid = comentarios_api_requiere_sesion();
        $input = comentarios_api_input();
        $resultado = comentarios_alternar_like($mysqli, $uid, (int) ($input['comentario_id'] ?? 0));
        if (!$resultado['ok']) {
            comentarios_api_error($resultado['message'], 422);
        }
        comentarios_api_ok($resultado);
        break;
    }

    // ── Notificaciones del usuario ──────────────────────────────────────
    case 'notificaciones': {
        $uid = comentarios_api_requiere_sesion();
        comentarios_api_ok([
            'items' => notificaciones_listar($mysqli, $uid),
            'no_leidas' => notificaciones_no_leidas($mysqli, $uid),
        ]);
        break;
    }

    case 'notificaciones_leidas': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            comentarios_api_error('Método no permitido.', 405);
        }
        $uid = comentarios_api_requiere_sesion();
        comentarios_api_ok(['marcadas' => notificaciones_marcar_leidas($mysqli, $uid)]);
        break;
    }

    default:
        comentarios_api_error('Acción no reconocida.', 404);
}
