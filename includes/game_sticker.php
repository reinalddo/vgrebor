<?php

function game_sticker_ensure_schema(mysqli $mysqli): void {
    static $initialized = false;
    if ($initialized) {
        return;
    }

    $columns = [
        'sticker_texto'       => "VARCHAR(80) NULL DEFAULT NULL",
        'sticker_icono'       => "VARCHAR(80) NULL DEFAULT NULL",
        'sticker_color_fondo' => "VARCHAR(20) NULL DEFAULT NULL",
        'sticker_imagen'      => "VARCHAR(255) NULL DEFAULT NULL",
    ];

    foreach ($columns as $col => $def) {
        $res = $mysqli->query("SHOW COLUMNS FROM juegos LIKE '$col'");
        if ($res instanceof mysqli_result && $res->num_rows === 0) {
            $mysqli->query("ALTER TABLE juegos ADD COLUMN `$col` $def");
        }
    }

    $initialized = true;
}

function game_sticker_from_row(array $row): ?array {
    $texto  = trim((string) ($row['sticker_texto'] ?? ''));
    $icono  = trim((string) ($row['sticker_icono'] ?? ''));
    $color  = trim((string) ($row['sticker_color_fondo'] ?? ''));
    $imagen = trim((string) ($row['sticker_imagen'] ?? ''));

    if ($texto === '' && $icono === '' && $imagen === '') {
        return null;
    }

    return [
        'texto'       => $texto,
        'icono'       => $icono,
        'color_fondo' => $color !== '' ? $color : '#0f1a2e',
        'imagen'      => $imagen,
    ];
}

function game_sticker_icon_html(string $icono): string {
    if ($icono === '') {
        return '';
    }

    // Check own emoji symbol map first (e.g. 'recommended' → '👑')
    $symbols = game_sticker_icon_symbols();
    if (isset($symbols[$icono])) {
        return '<span class="game-sticker-icon" aria-hidden="true">' . $symbols[$icono] . '</span>';
    }

    // Then check package feature SVG icons
    if (
        function_exists('package_feature_normalize_icon') &&
        function_exists('package_feature_icon_options') &&
        function_exists('package_feature_render_icon')
    ) {
        $normalized = package_feature_normalize_icon($icono);
        $options    = package_feature_icon_options();
        if (array_key_exists($normalized, $options)) {
            return package_feature_render_icon($icono, 'game-sticker-icon');
        }
    }

    // Fallback: render as-is (custom emoji typed directly)
    return '<span class="game-sticker-icon" aria-hidden="true">' . htmlspecialchars($icono, ENT_QUOTES, 'UTF-8') . '</span>';
}

function game_sticker_render(?array $sticker): string {
    if ($sticker === null) {
        return '';
    }

    $texto = htmlspecialchars($sticker['texto'], ENT_QUOTES, 'UTF-8');
    $color = htmlspecialchars($sticker['color_fondo'], ENT_QUOTES, 'UTF-8');

    $inner = '';
    if ($sticker['imagen'] !== '') {
        $imgUrl = function_exists('app_path')
            ? app_path('/' . ltrim($sticker['imagen'], '/'))
            : '/' . ltrim($sticker['imagen'], '/');
        $inner .= '<img src="' . htmlspecialchars($imgUrl, ENT_QUOTES, 'UTF-8') . '" alt="" class="game-sticker-img" aria-hidden="true" />';
    } elseif ($sticker['icono'] !== '') {
        $inner .= game_sticker_icon_html($sticker['icono']);
    }

    if ($sticker['texto'] !== '') {
        $inner .= '<span class="game-sticker-text">' . $texto . '</span>';
    }

    $label = $sticker['texto'] !== '' ? ' aria-label="' . $texto . '"' : ' aria-hidden="true"';

    return '<span class="game-sticker"' . $label . ' style="--sticker-bg:' . $color . ';">' . $inner . '</span>';
}

function game_sticker_store_upload(array $file): array {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['ok' => true, 'path' => ''];
    }

    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'message' => 'Error al cargar la imagen del sticker.'];
    }

    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return ['ok' => false, 'message' => 'Archivo de sticker inválido.'];
    }

    if (($file['size'] ?? 0) > 2 * 1024 * 1024) {
        return ['ok' => false, 'message' => 'La imagen del sticker no puede superar 2 MB.'];
    }

    $info = @getimagesize($tmp);
    if ($info === false) {
        return ['ok' => false, 'message' => 'El sticker debe ser una imagen válida.'];
    }

    $exts = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    $mime = $info['mime'] ?? '';
    if (!isset($exts[$mime])) {
        return ['ok' => false, 'message' => 'Formato no permitido. Usa JPG, PNG, WEBP o GIF.'];
    }

    $dir = function_exists('tenant_upload_absolute_dir')
        ? tenant_upload_absolute_dir('juegos')
        : __DIR__ . '/../uploads/juegos';

    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        return ['ok' => false, 'message' => 'No se pudo crear la carpeta del sticker.'];
    }

    $fileName = 'juegosticker_' . uniqid('', true) . '.' . $exts[$mime];
    $dest     = $dir . DIRECTORY_SEPARATOR . $fileName;

    if (!move_uploaded_file($tmp, $dest)) {
        return ['ok' => false, 'message' => 'No se pudo guardar la imagen del sticker.'];
    }

    $path = function_exists('tenant_upload_public_path')
        ? tenant_upload_public_path('juegos', $fileName, false)
        : 'uploads/juegos/' . $fileName;

    return ['ok' => true, 'path' => $path];
}

function game_sticker_delete_image(?string $path): void {
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

function game_sticker_is_known_icon(string $icono): bool {
    if ($icono === '' || !function_exists('package_feature_icon_options')) {
        return false;
    }
    if (array_key_exists($icono, package_feature_icon_options())) {
        return true;
    }
    if (function_exists('package_feature_icon_aliases')) {
        return isset(package_feature_icon_aliases()[strtolower($icono)]);
    }
    return false;
}

function game_sticker_icon_symbols(): array {
    return [
        'sparkles'    => '✨',
        'diamond'     => '💎',
        'lightning'   => '⚡',
        'shield'      => '🛡',
        'gift'        => '🎁',
        'controller'  => '🎮',
        'trophy'      => '🏆',
        'rocket'      => '🚀',
        'star'        => '⭐',
        'layers'      => '🧩',
        'best_seller' => '🔥',
        'limited'     => '⏳',
        'recommended' => '👑',
    ];
}

// ── Badge inferior (segunda etiqueta, esquina inferior derecha, sin animación) ──

function game_badge2_ensure_schema(mysqli $mysqli): void {
    static $initialized = false;
    if ($initialized) {
        return;
    }

    $columns = [
        'badge2_texto'       => "VARCHAR(80) NULL DEFAULT NULL",
        'badge2_icono'       => "VARCHAR(80) NULL DEFAULT NULL",
        'badge2_color_fondo' => "VARCHAR(20) NULL DEFAULT NULL",
        'badge2_imagen'      => "VARCHAR(255) NULL DEFAULT NULL",
    ];

    foreach ($columns as $col => $def) {
        $res = $mysqli->query("SHOW COLUMNS FROM juegos LIKE '$col'");
        if ($res instanceof mysqli_result && $res->num_rows === 0) {
            $mysqli->query("ALTER TABLE juegos ADD COLUMN `$col` $def");
        }
    }

    $initialized = true;
}

function game_badge2_from_row(array $row): ?array {
    $texto  = trim((string) ($row['badge2_texto'] ?? ''));
    $icono  = trim((string) ($row['badge2_icono'] ?? ''));
    $color  = trim((string) ($row['badge2_color_fondo'] ?? ''));
    $imagen = trim((string) ($row['badge2_imagen'] ?? ''));

    if ($texto === '' && $icono === '' && $imagen === '') {
        return null;
    }

    return [
        'texto'       => $texto,
        'icono'       => $icono,
        'color_fondo' => $color !== '' ? $color : '#0f1a2e',
        'imagen'      => $imagen,
    ];
}

function game_badge2_icon_html(string $icono): string {
    if ($icono === '') {
        return '';
    }

    $symbols = game_sticker_icon_symbols();
    if (isset($symbols[$icono])) {
        return '<span class="game-badge2-icon" aria-hidden="true">' . $symbols[$icono] . '</span>';
    }

    if (
        function_exists('package_feature_normalize_icon') &&
        function_exists('package_feature_icon_options') &&
        function_exists('package_feature_render_icon')
    ) {
        $normalized = package_feature_normalize_icon($icono);
        $options    = package_feature_icon_options();
        if (array_key_exists($normalized, $options)) {
            return package_feature_render_icon($icono, 'game-badge2-icon');
        }
    }

    return '<span class="game-badge2-icon" aria-hidden="true">' . htmlspecialchars($icono, ENT_QUOTES, 'UTF-8') . '</span>';
}

function game_badge2_render(?array $badge2): string {
    if ($badge2 === null) {
        return '';
    }

    $texto = htmlspecialchars($badge2['texto'], ENT_QUOTES, 'UTF-8');
    $color = htmlspecialchars($badge2['color_fondo'], ENT_QUOTES, 'UTF-8');

    $inner = '';
    if ($badge2['imagen'] !== '') {
        $imgUrl = function_exists('app_path')
            ? app_path('/' . ltrim($badge2['imagen'], '/'))
            : '/' . ltrim($badge2['imagen'], '/');
        $inner .= '<img src="' . htmlspecialchars($imgUrl, ENT_QUOTES, 'UTF-8') . '" alt="" class="game-badge2-img" aria-hidden="true" />';
    } elseif ($badge2['icono'] !== '') {
        $inner .= game_badge2_icon_html($badge2['icono']);
    }

    if ($badge2['texto'] !== '') {
        $inner .= '<span class="game-badge2-text">' . $texto . '</span>';
    }

    $label = $badge2['texto'] !== '' ? ' aria-label="' . $texto . '"' : ' aria-hidden="true"';

    return '<span class="game-badge2"' . $label . ' style="--badge2-bg:' . $color . ';">' . $inner . '</span>';
}

function game_badge2_store_upload(array $file): array {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['ok' => true, 'path' => ''];
    }

    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'message' => 'Error al cargar la imagen del badge.'];
    }

    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return ['ok' => false, 'message' => 'Archivo de badge inválido.'];
    }

    if (($file['size'] ?? 0) > 2 * 1024 * 1024) {
        return ['ok' => false, 'message' => 'La imagen del badge no puede superar 2 MB.'];
    }

    $info = @getimagesize($tmp);
    if ($info === false) {
        return ['ok' => false, 'message' => 'El badge debe ser una imagen válida.'];
    }

    $exts = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    $mime = $info['mime'] ?? '';
    if (!isset($exts[$mime])) {
        return ['ok' => false, 'message' => 'Formato no permitido. Usa JPG, PNG, WEBP o GIF.'];
    }

    $dir = function_exists('tenant_upload_absolute_dir')
        ? tenant_upload_absolute_dir('juegos')
        : __DIR__ . '/../uploads/juegos';

    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        return ['ok' => false, 'message' => 'No se pudo crear la carpeta del badge.'];
    }

    $fileName = 'juegobadge2_' . uniqid('', true) . '.' . $exts[$mime];
    $dest     = $dir . DIRECTORY_SEPARATOR . $fileName;

    if (!move_uploaded_file($tmp, $dest)) {
        return ['ok' => false, 'message' => 'No se pudo guardar la imagen del badge.'];
    }

    $path = function_exists('tenant_upload_public_path')
        ? tenant_upload_public_path('juegos', $fileName, false)
        : 'uploads/juegos/' . $fileName;

    return ['ok' => true, 'path' => $path];
}
