<?php
// includes/barra_promo_juego.php
// Barra promocional por juego (ej. "Canjea tus Roblox giftcards acá").
// Cada juego la tiene apagada por defecto. Si está activa, sale en la página del
// juego cada vez que se carga: abajo, centrada, sin ocupar todo el ancho, con la X en cian.

function barra_promo_juego_ensure_schema(mysqli $mysqli): void {
    static $initialized = false;
    if ($initialized) {
        return;
    }

    $columns = [
        'barra_promo_activa' => "TINYINT(1) NOT NULL DEFAULT 0",
        'barra_promo_titulo' => "VARCHAR(160) NULL DEFAULT NULL",
        'barra_promo_enlace' => "VARCHAR(500) NULL DEFAULT NULL",
        'barra_promo_imagen' => "VARCHAR(255) NULL DEFAULT NULL",
    ];

    foreach ($columns as $col => $def) {
        $res = $mysqli->query("SHOW COLUMNS FROM juegos LIKE '$col'");
        if ($res instanceof mysqli_result && $res->num_rows === 0) {
            $mysqli->query("ALTER TABLE juegos ADD COLUMN `$col` $def");
        }
    }

    $initialized = true;
}

// Solo http(s). Devuelve la URL limpia o '' si no sirve.
function barra_promo_juego_enlace_valido(string $enlace): string {
    $enlace = trim($enlace);
    if ($enlace === '' || mb_strlen($enlace, 'UTF-8') > 500) {
        return '';
    }
    if (!preg_match('#^https?://#i', $enlace)) {
        return '';
    }
    return filter_var($enlace, FILTER_VALIDATE_URL) ? $enlace : '';
}

// Datos de la barra si debe mostrarse (activa, con título y enlace válido); si no, null.
function barra_promo_juego_from_row(array $row): ?array {
    if ((int) ($row['barra_promo_activa'] ?? 0) !== 1) {
        return null;
    }

    $titulo = trim((string) ($row['barra_promo_titulo'] ?? ''));
    $enlace = barra_promo_juego_enlace_valido((string) ($row['barra_promo_enlace'] ?? ''));
    if ($titulo === '' || $enlace === '') {
        return null;
    }

    return [
        'titulo'  => $titulo,
        'enlace'  => $enlace,
        'imagen'  => trim((string) ($row['barra_promo_imagen'] ?? '')),
    ];
}

function barra_promo_juego_imagen_actual(mysqli $mysqli, int $juego_id): string {
    $stmt = $mysqli->prepare('SELECT barra_promo_imagen FROM juegos WHERE id = ? LIMIT 1');
    if (!$stmt) {
        return '';
    }
    $stmt->bind_param('i', $juego_id);
    $stmt->execute();
    $fila = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (string) ($fila['barra_promo_imagen'] ?? '');
}

// Lee los campos del formulario (con prefijo '' al crear o 'edit_' al editar).
// Devuelve ok=false con un mensaje para el admin si la barra activa está incompleta.
// No borra nada: el caller borra la imagen anterior solo después de guardar bien.
function barra_promo_juego_datos_desde_post(array $post, array $file, string $prefijo, string $imagenActual, bool $quitarImagen): array {
    $activa = isset($post[$prefijo . 'barra_promo_activa']) ? 1 : 0;
    $titulo = mb_substr(trim((string) ($post[$prefijo . 'barra_promo_titulo'] ?? '')), 0, 160, 'UTF-8');
    $enlaceRaw = trim((string) ($post[$prefijo . 'barra_promo_enlace'] ?? ''));
    $enlace = barra_promo_juego_enlace_valido($enlaceRaw);

    if ($activa === 1 && $titulo === '') {
        return ['ok' => false, 'error' => 'La barra promocional necesita un título para mostrarse.'];
    }
    if ($activa === 1 && $enlace === '') {
        return ['ok' => false, 'error' => 'El enlace de la barra promocional debe empezar con http:// o https://.'];
    }

    $imagen = $imagenActual;
    $subida = barra_promo_juego_store_upload($file);
    if (!$subida['ok']) {
        return ['ok' => false, 'error' => $subida['message']];
    }
    if ($subida['path'] !== '') {
        $imagen = $subida['path'];
    } elseif ($quitarImagen) {
        $imagen = '';
    }

    return [
        'ok'              => true,
        'activa'          => $activa,
        'titulo'          => $titulo,
        'enlace'          => $enlace !== '' ? $enlace : mb_substr($enlaceRaw, 0, 500, 'UTF-8'),
        'imagen'          => $imagen,
        'imagen_anterior' => $imagenActual,
    ];
}

function barra_promo_juego_store_upload(array $file): array {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['ok' => true, 'path' => ''];
    }

    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'message' => 'Error al cargar la imagen de la barra promocional.'];
    }

    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return ['ok' => false, 'message' => 'Archivo de la barra promocional inválido.'];
    }

    if (($file['size'] ?? 0) > 2 * 1024 * 1024) {
        return ['ok' => false, 'message' => 'La imagen de la barra no puede superar 2 MB.'];
    }

    $info = @getimagesize($tmp);
    if ($info === false) {
        return ['ok' => false, 'message' => 'La imagen de la barra debe ser una imagen válida.'];
    }

    $exts = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    $mime = $info['mime'] ?? '';
    if (!isset($exts[$mime])) {
        return ['ok' => false, 'message' => 'Formato no permitido para la barra. Usa JPG, PNG, WEBP o GIF.'];
    }

    $dir = function_exists('tenant_upload_absolute_dir')
        ? tenant_upload_absolute_dir('juegos')
        : __DIR__ . '/../uploads/juegos';

    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        return ['ok' => false, 'message' => 'No se pudo crear la carpeta de la barra promocional.'];
    }

    $fileName = 'juegobarra_' . uniqid('', true) . '.' . $exts[$mime];
    $dest = $dir . DIRECTORY_SEPARATOR . $fileName;

    if (!move_uploaded_file($tmp, $dest)) {
        return ['ok' => false, 'message' => 'No se pudo guardar la imagen de la barra promocional.'];
    }

    $path = function_exists('tenant_upload_public_path')
        ? tenant_upload_public_path('juegos', $fileName, false)
        : 'uploads/juegos/' . $fileName;

    return ['ok' => true, 'path' => $path];
}

function barra_promo_juego_delete_image(?string $path): void {
    if ($path === null || $path === '') {
        return;
    }

    if (function_exists('tenant_resolve_public_path')) {
        $abs = tenant_resolve_public_path($path);
        if ($abs !== null && is_file($abs)) {
            @unlink($abs);
        }
        return;
    }

    if (is_file($path)) {
        @unlink($path);
    }
}

// HTML de la barra para la página del juego ('' si no corresponde mostrarla).
function barra_promo_juego_render(?array $barra): string {
    if ($barra === null) {
        return '';
    }

    $e = static fn (string $texto): string => htmlspecialchars($texto, ENT_QUOTES, 'UTF-8');

    $imagenHtml = '';
    if ($barra['imagen'] !== '') {
        $imgUrl = function_exists('app_path')
            ? app_path('/' . ltrim($barra['imagen'], '/'))
            : '/' . ltrim($barra['imagen'], '/');
        $imagenHtml = '<img class="barra-promo-juego-img" src="' . $e($imgUrl) . '" alt="" loading="lazy">';
    }

    // El título es solo texto en negrita; lo único clicable es la URL que se muestra debajo.
    return '<div class="barra-promo-juego" id="barraPromoJuego" role="region" aria-label="Promoción">'
        . $imagenHtml
        . '<div class="barra-promo-juego-cuerpo">'
        . '<span class="barra-promo-juego-titulo">' . $e($barra['titulo']) . '</span>'
        . '<a class="barra-promo-juego-link" href="' . $e($barra['enlace']) . '" target="_blank" rel="noopener noreferrer">' . $e($barra['enlace']) . '</a>'
        . '</div>'
        . '<button type="button" class="barra-promo-juego-cerrar" aria-label="Cerrar promoción"><span aria-hidden="true">&times;</span></button>'
        . '</div>'
        . '<script>(function(){var b=document.getElementById("barraPromoJuego");if(!b)return;'
        . 'var c=b.querySelector(".barra-promo-juego-cerrar");if(c){c.addEventListener("click",function(){b.remove();});}})();</script>';
}
