<?php
header('Content-Type: application/json');

require_once __DIR__ . '/includes/tenant.php';

$tenant = resolve_tenant_slug();

// Recibe los datos del POST
$data = json_decode(file_get_contents('php://input'), true);

// Validación básica
defaultResponse();
if (!isset($data['nombre']) || !isset($data['correo']) || !isset($data['telefono']) || !isset($data['contrasena'])) {
    response(false, 'Faltan datos requeridos.');
}

$nombre = trim($data['nombre']);
$correo = strtolower(trim($data['correo']));
$telefono = substr(trim($data['telefono']), 0, 50);
$contrasena = $data['contrasena'];

if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
    response(false, 'Correo electrónico inválido.');
}
if (strlen($contrasena) < 6) {
    response(false, 'La contraseña debe tener al menos 6 caracteres.');
}


// Guardar usuario en la base de datos MySQL
require_once __DIR__ . '/includes/db.php';

// Verificar si el correo ya existe en la BD
$stmt = $pdo->prepare('SELECT id FROM usuarios WHERE email = ? LIMIT 1');
$stmt->execute([$correo]);
if ($stmt->fetch()) {
    response(false, 'El correo ya está registrado.');
}

$hash = password_hash($contrasena, PASSWORD_DEFAULT);
$rol = 'usuario';

// Sistema de Referidos: si llegó un código de invitación (?ref=CODIGO capturado
// en includes/footer.php y guardado en localStorage hasta este registro), se
// busca al referidor por su código. Este archivo usa PDO ($pdo, includes/db.php)
// para todo lo demás, pero referidos.php trabaja con mysqli (mismo patrón que
// win_points/influencer_coupons) — se abre una conexión mysqli aparte solo para
// esta parte, apuntando a la misma base de datos.
require_once __DIR__ . '/includes/referidos.php';
require_once __DIR__ . '/includes/db_connect.php';
referidos_ensure_schema();

$refCodigoInput = isset($data['ref']) ? strtoupper(trim((string) $data['ref'])) : '';
$referidorUserId = null;
if ($refCodigoInput !== '') {
    $refStmt = $mysqli->prepare('SELECT id FROM usuarios WHERE referido_codigo = ? LIMIT 1');
    if ($refStmt) {
        $refStmt->bind_param('s', $refCodigoInput);
        $refStmt->execute();
        $refRow = $refStmt->get_result()->fetch_assoc();
        $refStmt->close();
        if ($refRow) {
            $referidorUserId = (int) $refRow['id'];
        }
    }
}

$sql = 'INSERT INTO usuarios (username, password, nombre, email, telefono, rol, referido_por_id, referido_en, creado_en) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())';
$username = $correo;
$referidoEn = $referidorUserId !== null ? date('Y-m-d H:i:s') : null;
$stmt = $pdo->prepare($sql);
$ok = $stmt->execute([$username, $hash, $nombre, $correo, $telefono, $rol, $referidorUserId, $referidoEn]);

if ($ok) {
    $nuevoUsuarioId = (int) $pdo->lastInsertId();

    // Cada usuario (haya sido invitado o no) tiene su propio código para poder
    // invitar a otros. El cupón de bienvenida solo se genera si SÍ fue invitado.
    if ($nuevoUsuarioId > 0) {
        referidos_asegurar_codigo_usuario($mysqli, $nuevoUsuarioId);
        if ($referidorUserId !== null) {
            referidos_generar_cupon_bienvenida($mysqli, $nuevoUsuarioId);
        }
    }

    // ── Sistema de Comentarios: registro + comentario en un solo paso ──
    // Caso de uso: alguien recargó como invitado, vio el bloque "Gracias por
    // su compra" y eligió registrarse; en ese caso el formulario de registro
    // trae además un comentario. Se vinculan a la cuenta nueva los pedidos
    // que este navegador hizo como invitado (probados por la sesión del
    // servidor, no por lo que mande el navegador) y recién ahí se publica el
    // comentario sobre ese pedido.
    $comentarioResultado = null;
    if ($nuevoUsuarioId > 0) {
        require_once __DIR__ . '/includes/comentarios.php';
        require_once __DIR__ . '/includes/win_points.php';
        tenant_start_session();

        comentarios_vincular_pedidos_de_sesion($mysqli, $nuevoUsuarioId);

        $comentarioTexto = trim((string) ($data['comentario_texto'] ?? ''));
        $comentarioPedidoId = (int) ($data['comentario_pedido_id'] ?? 0);
        if ($comentarioTexto !== '' && $comentarioPedidoId > 0) {
            // Doble validación: el pedido tiene que estar entre los que ESTE
            // navegador hizo. Aunque comentarios_publicar() ya revalida el
            // dueño contra la BD, sin este chequeo alguien podría mandar un
            // pedido_id ajeno que justo quedó sin dueño.
            if (in_array($comentarioPedidoId, comentarios_pedidos_de_sesion(), true)) {
                $comentarioResultado = comentarios_publicar(
                    $mysqli,
                    $nuevoUsuarioId,
                    $data['comentario_estrellas'] ?? 5,
                    $comentarioTexto,
                    $comentarioPedidoId
                );
            } else {
                $comentarioResultado = ['ok' => false, 'message' => 'No pudimos asociar tu comentario a una compra de este navegador.'];
            }
        }
    }

    $mensaje = 'Usuario registrado correctamente.';
    if (is_array($comentarioResultado)) {
        $mensaje .= $comentarioResultado['ok']
            ? ' ' . $comentarioResultado['message']
            : ' Tu cuenta quedó creada, pero no se pudo publicar el comentario: ' . $comentarioResultado['message'];
    }

    response(true, $mensaje, [
        'comentario_publicado' => is_array($comentarioResultado) ? (bool) $comentarioResultado['ok'] : false,
    ]);
} else {
    response(false, 'Error al guardar el usuario en la base de datos.');
}

function response($success, $message, array $extra = []) {
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra));
    exit;
}
function defaultResponse() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        response(false, 'Método no permitido.');
    }
}
