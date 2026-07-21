<?php
// admin/juegos.php - Gestión de juegos y características
require_once '../includes/db_connect.php';
require_once '../includes/tenant.php';
tenant_start_session();
$adminRole = trim((string) ($_SESSION['auth_user']['rol'] ?? ''));
if (!isset($_SESSION['auth_user']) || !in_array($adminRole, ['admin', 'root'], true)) {
    header('Location: ' . app_path('/login.php'));
    exit();
}
require_once '../includes/recargas_api.php';
require_once '../includes/store_config.php';
require_once '../includes/api_discord.php';
require_once '../includes/game_entry_window_per_game.php';
require_once '../includes/slugify.php';
require_once '../includes/game_categories.php';
require_once '../includes/package_features.php';
require_once '../includes/game_sticker.php';

function admin_games_is_ajax_request(): bool {
    if (isset($_REQUEST['ajax']) && (string) $_REQUEST['ajax'] === '1') {
        return true;
    }

    $requestedWith = strtolower(trim((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')));
    $accept = strtolower(trim((string) ($_SERVER['HTTP_ACCEPT'] ?? '')));

    return $requestedWith === 'xmlhttprequest' || str_contains($accept, 'application/json');
}

function admin_games_json_response(array $payload, int $statusCode = 200): void {
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function admin_games_redirect(string $baseUrl, array $params = []): void {
    $target = $baseUrl;
    if ($params !== []) {
        $target .= '?' . http_build_query($params);
    }
    header('Location: ' . $target);
    exit;
}

function admin_game_store_upload(array $file, string $prefix): ?string {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return null;
    }

    $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
    $permitidas = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    if (!in_array($ext, $permitidas, true)) {
        return null;
    }

    $dir = tenant_upload_absolute_dir('juegos');
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        return null;
    }

    $fileName = uniqid($prefix, true) . '.' . $ext;
    $destination = $dir . DIRECTORY_SEPARATOR . $fileName;
    if (!move_uploaded_file((string) ($file['tmp_name'] ?? ''), $destination)) {
        return null;
    }

    return tenant_upload_public_path('juegos', $fileName, false);
}

function admin_game_delete_upload(?string $path): void {
    $absolutePath = tenant_resolve_public_path((string) $path);
    if ($absolutePath !== null && is_file($absolutePath)) {
        @unlink($absolutePath);
    }
}

function ensure_juegos_api_free_fire_column(mysqli $mysqli): void {
    $result = $mysqli->query("SHOW COLUMNS FROM juegos LIKE 'api_free_fire'");
    if (!($result instanceof mysqli_result) || $result->num_rows === 0) {
        $mysqli->query("ALTER TABLE juegos ADD COLUMN api_free_fire TINYINT(1) NOT NULL DEFAULT 0 AFTER popular");
    }
}

function ensure_juegos_activo_column(mysqli $mysqli): void {
    $result = $mysqli->query("SHOW COLUMNS FROM juegos LIKE 'activo'");
    if (!($result instanceof mysqli_result) || $result->num_rows === 0) {
        $mysqli->query("ALTER TABLE juegos ADD COLUMN activo TINYINT(1) DEFAULT 1 NULL AFTER api_free_fire");
    }
}

function ensure_juegos_categoria_api_column(mysqli $mysqli): void {
    $result = $mysqli->query("SHOW COLUMNS FROM juegos LIKE 'categoria_api'");
    if (!($result instanceof mysqli_result) || $result->num_rows === 0) {
        $mysqli->query("ALTER TABLE juegos ADD COLUMN categoria_api VARCHAR(100) NULL AFTER api_free_fire");
    }
}

function ensure_juegos_categoria_api_discord_column(mysqli $mysqli): void {
    $result = $mysqli->query("SHOW COLUMNS FROM juegos LIKE 'categoria_api_discord'");
    if (!($result instanceof mysqli_result) || $result->num_rows === 0) {
        $mysqli->query("ALTER TABLE juegos ADD COLUMN categoria_api_discord VARCHAR(120) NULL AFTER categoria_api");
    }
}

function ensure_juegos_orden_column(mysqli $mysqli): void {
    $result = $mysqli->query("SHOW COLUMNS FROM juegos LIKE 'orden'");
    if (!($result instanceof mysqli_result) || $result->num_rows === 0) {
        $mysqli->query("ALTER TABLE juegos ADD COLUMN orden INT NULL AFTER categoria_api");
    }
}

function ensure_juegos_orden_catbar_column(mysqli $mysqli): void {
    $result = $mysqli->query("SHOW COLUMNS FROM juegos LIKE 'orden_catbar'");
    if (!($result instanceof mysqli_result) || $result->num_rows === 0) {
        $mysqli->query("ALTER TABLE juegos ADD COLUMN orden_catbar INT NULL AFTER orden");
    }
}

function ensure_juegos_imagen_catbar_column(mysqli $mysqli): void {
    $result = $mysqli->query("SHOW COLUMNS FROM juegos LIKE 'imagen_catbar'");
    if (!($result instanceof mysqli_result) || $result->num_rows === 0) {
        $mysqli->query("ALTER TABLE juegos ADD COLUMN imagen_catbar VARCHAR(255) NULL AFTER imagen_hero");
    }
}

function ensure_juegos_categoria_api_2_column(mysqli $mysqli): void {
    $result = $mysqli->query("SHOW COLUMNS FROM juegos LIKE 'categoria_api_2'");
    if (!($result instanceof mysqli_result) || $result->num_rows === 0) {
        $mysqli->query("ALTER TABLE juegos ADD COLUMN categoria_api_2 VARCHAR(100) NULL AFTER categoria_api");
    }
}

function ensure_juegos_categoria_api_discord_2_column(mysqli $mysqli): void {
    $result = $mysqli->query("SHOW COLUMNS FROM juegos LIKE 'categoria_api_discord_2'");
    if (!($result instanceof mysqli_result) || $result->num_rows === 0) {
        $mysqli->query("ALTER TABLE juegos ADD COLUMN categoria_api_discord_2 VARCHAR(120) NULL AFTER categoria_api_discord");
    }
}

function ensure_juegos_categoria_api_3_column(mysqli $mysqli): void {
    $result = $mysqli->query("SHOW COLUMNS FROM juegos LIKE 'categoria_api_3'");
    if (!($result instanceof mysqli_result) || $result->num_rows === 0) {
        $mysqli->query("ALTER TABLE juegos ADD COLUMN categoria_api_3 VARCHAR(100) NULL AFTER categoria_api_2");
    }
}

function ensure_juegos_categoria_api_discord_3_column(mysqli $mysqli): void {
    $result = $mysqli->query("SHOW COLUMNS FROM juegos LIKE 'categoria_api_discord_3'");
    if (!($result instanceof mysqli_result) || $result->num_rows === 0) {
        $mysqli->query("ALTER TABLE juegos ADD COLUMN categoria_api_discord_3 VARCHAR(120) NULL AFTER categoria_api_discord_2");
    }
}

function ensure_juegos_precio_markup_pct_column(mysqli $mysqli): void {
    $result = $mysqli->query("SHOW COLUMNS FROM juegos LIKE 'precio_markup_pct'");
    if (!($result instanceof mysqli_result) || $result->num_rows === 0) {
        $mysqli->query("ALTER TABLE juegos ADD COLUMN precio_markup_pct DECIMAL(8,4) NOT NULL DEFAULT 0 AFTER categoria_api_discord_3");
    }
}

function admin_game_normalize_api_selection(array $payload, string $giftVenKey, string $discordKey, bool $allowCombined = false): array {
    $giftVenCategory  = trim((string) ($payload[$giftVenKey] ?? ''));
    $giftVenCategory2 = trim((string) ($payload[$giftVenKey . '_2'] ?? ''));
    $giftVenCategory3 = trim((string) ($payload[$giftVenKey . '_3'] ?? ''));
    $discordCommand   = trim((string) ($payload[$discordKey] ?? ''));
    $discordCommand2  = trim((string) ($payload[$discordKey . '_2'] ?? ''));
    $discordCommand3  = trim((string) ($payload[$discordKey . '_3'] ?? ''));

    $hasAnyGiftVen = $giftVenCategory !== '' || $giftVenCategory2 !== '' || $giftVenCategory3 !== '';
    $hasAnyDiscord = $discordCommand !== '' || $discordCommand2 !== '' || $discordCommand3 !== '';

    if (!$allowCombined && $hasAnyGiftVen && $hasAnyDiscord) {
        return [
            'ok' => false,
            'message' => 'Solo puedes seleccionar una API por juego: TiendaGiftVen o Discord.',
            'giftven' => '', 'giftven2' => '', 'giftven3' => '', 'discord' => '', 'discord2' => '', 'discord3' => '', 'api_free_fire' => 0,
        ];
    }

    foreach ([$discordCommand, $discordCommand2, $discordCommand3] as $cmd) {
        if ($cmd !== '') {
            $discordDefinition = api_discord_find_command($cmd);
            if (!$discordDefinition || ($discordDefinition['kind'] ?? '') !== 'topup') {
                return [
                    'ok' => false,
                    'message' => 'El comando seleccionado en Juegos API Discord no es válido.',
                    'giftven' => '', 'giftven2' => '', 'giftven3' => '', 'discord' => '', 'discord2' => '', 'discord3' => '', 'api_free_fire' => 0,
                ];
            }
        }
    }

    return [
        'ok' => true,
        'message' => '',
        'giftven'  => $giftVenCategory,
        'giftven2' => $giftVenCategory2,
        'giftven3' => $giftVenCategory3,
        'discord'  => $discordCommand,
        'discord2' => $discordCommand2,
        'discord3' => $discordCommand3,
        'api_free_fire' => $hasAnyGiftVen ? 1 : 0,
    ];
}

function ensure_juegos_slug_column(mysqli $mysqli): void {
    $result = $mysqli->query("SHOW COLUMNS FROM juegos LIKE 'slug'");
    if (!($result instanceof mysqli_result) || $result->num_rows === 0) {
        $mysqli->query("ALTER TABLE juegos ADD COLUMN slug VARCHAR(200) NULL AFTER descripcion");
    }
}

function ensure_juegos_imagen_hero_column(mysqli $mysqli): void {
    $result = $mysqli->query("SHOW COLUMNS FROM juegos LIKE 'imagen_hero'");
    if (!($result instanceof mysqli_result) || $result->num_rows === 0) {
        $mysqli->query("ALTER TABLE juegos ADD COLUMN imagen_hero VARCHAR(255) NULL AFTER imagen");
    }
}

function admin_game_next_order(mysqli $mysqli): int {
    $result = $mysqli->query("SELECT COALESCE(MAX(orden), 0) + 1 AS next_order FROM juegos");
    $row = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
    return max(1, (int) ($row['next_order'] ?? 1));
}

function admin_game_discord_command_options(array $commands): array {
    $options = [];
    foreach ($commands as $command) {
        if (!is_array($command)) {
            continue;
        }

        $key = trim((string) ($command['key'] ?? ''));
        if ($key === '') {
            continue;
        }

        $options[] = [
            'key' => $key,
            'label' => trim((string) ($command['label'] ?? $key)),
        ];
    }

    return $options;
}

ensure_juegos_api_free_fire_column($mysqli);
ensure_juegos_activo_column($mysqli);
ensure_juegos_categoria_api_column($mysqli);
ensure_juegos_categoria_api_discord_column($mysqli);
ensure_juegos_categoria_api_2_column($mysqli);
ensure_juegos_categoria_api_discord_2_column($mysqli);
ensure_juegos_categoria_api_3_column($mysqli);
ensure_juegos_categoria_api_discord_3_column($mysqli);
ensure_juegos_precio_markup_pct_column($mysqli);
ensure_juegos_orden_column($mysqli);
ensure_juegos_orden_catbar_column($mysqli);
ensure_juegos_slug_column($mysqli);
ensure_juegos_imagen_hero_column($mysqli);
ensure_juegos_imagen_catbar_column($mysqli);
game_sticker_ensure_schema($mysqli);
game_badge2_ensure_schema($mysqli);

$adminGamesUrl = app_path('/admin/juegos');
$adminPackagesBaseUrl = app_path('/admin/paquetes');
$adminGameEntryWindowBaseUrl = app_path('/admin/ventana-inicial-juegos');
$gameEntryWindowEnabled = game_entry_window_feature_available();
$discordApiEnabled = trim((string) store_config_get('api_discord', '0')) === '1';
$mixedApiUnionEnabled = $discordApiEnabled && trim((string) store_config_get('union_apis_discord_giftven', '0')) === '1';
$gameApiExclusiveClass = $mixedApiUnionEnabled ? '' : ' js-exclusive-api-select';
$apiCategories = [];
$apiCategoriesError = null;
if (recargas_api_is_configured()) {
    try {
        $apiCategories = recargas_api_fetch_categories();
    } catch (Throwable $e) {
        $apiCategoriesError = $e->getMessage();
    }
}
$discordApiCommands = $discordApiEnabled ? api_discord_topup_commands() : [];
$discordApiCommandOptions = admin_game_discord_command_options($discordApiCommands);
$adminGamesError = trim((string) ($_GET['error'] ?? ''));

if (admin_games_is_ajax_request() && isset($_GET['load_discord_games'])) {
    if (!$discordApiEnabled) {
        admin_games_json_response([
            'ok' => false,
            'message' => 'API Discord está desactivada en la configuración general.',
        ], 422);
    }

    admin_games_json_response([
        'ok' => true,
        'commands' => $discordApiCommandOptions,
    ]);
}

if (isset($_GET['toggle_activo'])) {
    $toggleId = intval($_GET['toggle_activo']);
    if ($toggleId > 0) {
        $stmtToggle = $mysqli->prepare("UPDATE juegos SET activo = IF(COALESCE(activo, 1) = 1, 0, 1) WHERE id = ?");
        $stmtToggle->bind_param('i', $toggleId);
        $stmtToggle->execute();
        $stmtToggle->close();
        if (admin_games_is_ajax_request()) {
            admin_games_json_response(['ok' => true, 'id' => $toggleId]);
        }
    }
    header('Location: ' . $adminGamesUrl);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_orden_juego'], $_POST['juego_id'], $_POST['orden'])) {
    $gameId = intval($_POST['juego_id']);
    $order = max(1, intval($_POST['orden']));
    if ($gameId > 0) {
        $stmtOrder = $mysqli->prepare("UPDATE juegos SET orden = ? WHERE id = ?");
        $stmtOrder->bind_param('ii', $order, $gameId);
        $stmtOrder->execute();
        $stmtOrder->close();
        if (admin_games_is_ajax_request()) {
            admin_games_json_response(['ok' => true, 'id' => $gameId, 'orden' => $order]);
        }
    }
    header('Location: ' . $adminGamesUrl);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_orden_catbar_juego'], $_POST['juego_id'], $_POST['orden'])) {
    $gameId = intval($_POST['juego_id']);
    $order = max(1, intval($_POST['orden']));
    if ($gameId > 0) {
        $stmtOrder = $mysqli->prepare("UPDATE juegos SET orden_catbar = ? WHERE id = ?");
        $stmtOrder->bind_param('ii', $order, $gameId);
        $stmtOrder->execute();
        $stmtOrder->close();
        if (admin_games_is_ajax_request()) {
            admin_games_json_response(['ok' => true, 'id' => $gameId, 'orden' => $order]);
        }
    }
    header('Location: ' . $adminGamesUrl);
    exit;
}

// Procesar eliminación de juego (antes de cualquier salida)
if (isset($_GET['eliminar'])) {
    $del_id = intval($_GET['eliminar']);
    // Eliminar imágenes de paquetes asociados
    $stmt_paq = $mysqli->prepare("SELECT imagen_icono FROM juego_paquetes WHERE juego_id=?");
    $stmt_paq->bind_param('i', $del_id);
    $stmt_paq->execute();
    $res_paq = $stmt_paq->get_result();
    while ($row = $res_paq->fetch_assoc()) {
        if (!empty($row['imagen_icono'])) {
            admin_game_delete_upload((string) $row['imagen_icono']);
        }
    }
    $stmt_paq->close();
    // Eliminar paquetes
    $stmt = $mysqli->prepare("DELETE FROM juego_paquetes WHERE juego_id=?");
    $stmt->bind_param('i', $del_id);
    $stmt->execute();
    // Eliminar características
    $stmt = $mysqli->prepare("DELETE FROM juego_caracteristicas WHERE juego_id=?");
    $stmt->bind_param('i', $del_id);
    $stmt->execute();
    // Eliminar imagen del juego
    $stmt_img = $mysqli->prepare("SELECT imagen, imagen_hero, imagen_paquete, imagen_catbar, sticker_imagen, badge2_imagen FROM juegos WHERE id=?");
    $stmt_img->bind_param('i', $del_id);
    $stmt_img->execute();
    $stmt_img->bind_result($img_juego, $img_juego_hero, $img_juego_paquete, $img_juego_catbar, $img_sticker, $img_badge2);
    $stmt_img->fetch();
    $stmt_img->close();
    if ($img_juego) {
        admin_game_delete_upload((string) $img_juego);
    }
    if ($img_juego_hero) {
        admin_game_delete_upload((string) $img_juego_hero);
    }
    if ($img_juego_paquete) {
        admin_game_delete_upload((string) $img_juego_paquete);
    }
    if ($img_juego_catbar) {
        admin_game_delete_upload((string) $img_juego_catbar);
    }
    if ($img_sticker) {
        game_sticker_delete_image((string) $img_sticker);
    }
    if ($img_badge2) {
        game_sticker_delete_image((string) $img_badge2);
    }
    // Eliminar el juego
    $stmt = $mysqli->prepare("DELETE FROM juegos WHERE id=?");
    $stmt->bind_param('i', $del_id);
    $stmt->execute();
    header('Location: ' . $adminGamesUrl);
    exit;
}
// Procesar edición de cabecera de juego (antes de cualquier salida)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_juego_submit'], $_POST['edit_juego_id'], $_POST['edit_nombre'], $_POST['edit_descripcion'])) {
    $edit_id = intval($_POST['edit_juego_id']);
    $currentGame = null;
    if ($edit_id > 0) {
        $currentGameStmt = $mysqli->prepare("SELECT categoria_api_discord, imagen, imagen_paquete, imagen_hero, imagen_catbar, sticker_imagen, badge2_imagen FROM juegos WHERE id = ? LIMIT 1");
        if ($currentGameStmt) {
            $currentGameStmt->bind_param('i', $edit_id);
            $currentGameStmt->execute();
            $currentGame = $currentGameStmt->get_result()->fetch_assoc() ?: null;
            $currentGameStmt->close();
        }
    }
    $edit_nombre = trim($_POST['edit_nombre']);
    $edit_descripcion = trim($_POST['edit_descripcion']);
    $edit_slug = slugify($edit_nombre);
    $edit_popular = isset($_POST['edit_popular']) ? 1 : 0;
    $apiSelection = admin_game_normalize_api_selection($_POST, 'edit_categoria_api_tiendagiftven', 'edit_categoria_api_discord', $mixedApiUnionEnabled);
    if (!$apiSelection['ok']) {
        admin_games_redirect($adminGamesUrl, ['editar' => $edit_id, 'error' => $apiSelection['message']]);
    }
    $edit_categoria_api   = $apiSelection['giftven'];
    $edit_categoria_api_2 = $apiSelection['giftven2'];
    $edit_categoria_api_3 = $apiSelection['giftven3'];
    $edit_categoria_api_discord = $discordApiEnabled
        ? $apiSelection['discord']
        : trim((string) ($currentGame['categoria_api_discord'] ?? ''));
    $edit_categoria_api_discord_2 = $discordApiEnabled ? $apiSelection['discord2'] : '';
    $edit_categoria_api_discord_3 = $discordApiEnabled ? $apiSelection['discord3'] : '';
    $edit_api_free_fire = $apiSelection['api_free_fire'];
    $edit_activo = isset($_POST['edit_activo']) ? 1 : 0;
    $edit_moneda_fija_id = isset($_POST['edit_moneda_fija_id']) && $_POST['edit_moneda_fija_id'] !== '' ? intval($_POST['edit_moneda_fija_id']) : null;
    $edit_precio_markup_pct = max(0.0, min(10000.0, floatval(str_replace(',', '.', trim((string) ($_POST['edit_precio_markup_pct'] ?? '0'))))));
    $edit_imagen = admin_game_store_upload($_FILES['edit_imagen'] ?? [], 'juego_');
    $edit_imagen_hero = admin_game_store_upload($_FILES['edit_imagen_hero'] ?? [], 'juegohero_');
    $edit_imagen_paquete = admin_game_store_upload($_FILES['edit_imagen_paquete'] ?? [], 'juegopaq_');
    $edit_imagen_catbar = admin_game_store_upload($_FILES['edit_imagen_catbar'] ?? [], 'juegocatbar_');
    $remove_edit_imagen_hero = isset($_POST['remove_edit_imagen_hero']);
    $remove_edit_imagen_catbar = isset($_POST['remove_edit_imagen_catbar']);
    $currentImage = (string) ($currentGame['imagen'] ?? '');
    $currentHeroImage = (string) ($currentGame['imagen_hero'] ?? '');
    $currentPackageImage = (string) ($currentGame['imagen_paquete'] ?? '');
    $currentCatbarImage = (string) ($currentGame['imagen_catbar'] ?? '');

    if ($edit_imagen !== null && $currentImage !== '') {
        admin_game_delete_upload($currentImage);
    }
    if ($edit_imagen_hero !== null && $currentHeroImage !== '') {
        admin_game_delete_upload($currentHeroImage);
    }
    if ($edit_imagen_paquete !== null && $currentPackageImage !== '') {
        admin_game_delete_upload($currentPackageImage);
    }
    if ($edit_imagen_catbar !== null && $currentCatbarImage !== '') {
        admin_game_delete_upload($currentCatbarImage);
    }
    if ($remove_edit_imagen_hero && $currentHeroImage !== '' && $edit_imagen_hero === null) {
        admin_game_delete_upload($currentHeroImage);
    }
    if ($remove_edit_imagen_catbar && $currentCatbarImage !== '' && $edit_imagen_catbar === null) {
        admin_game_delete_upload($currentCatbarImage);
    }

    $nextImage = $edit_imagen ?? $currentImage;
    $nextHeroImage = $remove_edit_imagen_hero ? '' : ($edit_imagen_hero ?? $currentHeroImage);
    $nextPackageImage = $edit_imagen_paquete ?? $currentPackageImage;
    $nextCatbarImage = $remove_edit_imagen_catbar ? '' : ($edit_imagen_catbar ?? $currentCatbarImage);

    $edit_sticker_ico_custom = trim((string) ($_POST['edit_sticker_icono_custom'] ?? ''));
    $edit_sticker_ico_preset = trim((string) ($_POST['edit_sticker_icono_select'] ?? ''));
    $edit_sticker_icono       = $edit_sticker_ico_custom !== '' ? $edit_sticker_ico_custom : $edit_sticker_ico_preset;
    $edit_sticker_texto       = mb_substr(trim((string) ($_POST['edit_sticker_texto'] ?? '')), 0, 80, 'UTF-8');
    $rawStickerColor          = trim((string) ($_POST['edit_sticker_color_fondo'] ?? ''));
    $edit_sticker_color_fondo = preg_match('/^#[0-9a-fA-F]{3,6}$/', $rawStickerColor) ? $rawStickerColor : '#0f1a2e';
    $currentStickerImage      = (string) ($currentGame['sticker_imagen'] ?? '');
    $stickerUpload            = game_sticker_store_upload($_FILES['edit_sticker_imagen'] ?? []);
    $removeStickerImg         = isset($_POST['remove_edit_sticker_imagen']);
    if ($stickerUpload['ok'] && $stickerUpload['path'] !== '') {
        if ($currentStickerImage !== '') {
            game_sticker_delete_image($currentStickerImage);
        }
        $edit_sticker_imagen = $stickerUpload['path'];
    } elseif ($removeStickerImg) {
        if ($currentStickerImage !== '') {
            game_sticker_delete_image($currentStickerImage);
        }
        $edit_sticker_imagen = '';
    } else {
        $edit_sticker_imagen = $currentStickerImage;
    }

    $edit_badge2_ico_custom = trim((string) ($_POST['edit_badge2_icono_custom'] ?? ''));
    $edit_badge2_ico_preset = trim((string) ($_POST['edit_badge2_icono_select'] ?? ''));
    $edit_badge2_icono       = $edit_badge2_ico_custom !== '' ? $edit_badge2_ico_custom : $edit_badge2_ico_preset;
    $edit_badge2_texto       = mb_substr(trim((string) ($_POST['edit_badge2_texto'] ?? '')), 0, 80, 'UTF-8');
    $rawBadge2Color          = trim((string) ($_POST['edit_badge2_color_fondo'] ?? ''));
    $edit_badge2_color_fondo = preg_match('/^#[0-9a-fA-F]{3,6}$/', $rawBadge2Color) ? $rawBadge2Color : '#0f1a2e';
    $currentBadge2Image      = (string) ($currentGame['badge2_imagen'] ?? '');
    $badge2Upload            = game_badge2_store_upload($_FILES['edit_badge2_imagen'] ?? []);
    $removeBadge2Img         = isset($_POST['remove_edit_badge2_imagen']);
    if ($badge2Upload['ok'] && $badge2Upload['path'] !== '') {
        if ($currentBadge2Image !== '') {
            game_sticker_delete_image($currentBadge2Image);
        }
        $edit_badge2_imagen = $badge2Upload['path'];
    } elseif ($removeBadge2Img) {
        if ($currentBadge2Image !== '') {
            game_sticker_delete_image($currentBadge2Image);
        }
        $edit_badge2_imagen = '';
    } else {
        $edit_badge2_imagen = $currentBadge2Image;
    }

    $stmt = $mysqli->prepare("UPDATE juegos SET nombre=?, descripcion=?, slug=?, imagen=?, imagen_hero=?, imagen_paquete=?, imagen_catbar=?, popular=?, api_free_fire=?, categoria_api=?, categoria_api_2=?, categoria_api_3=?, categoria_api_discord=?, categoria_api_discord_2=?, categoria_api_discord_3=?, activo=?, moneda_fija_id=?, sticker_texto=?, sticker_icono=?, sticker_color_fondo=?, sticker_imagen=?, badge2_texto=?, badge2_icono=?, badge2_color_fondo=?, badge2_imagen=?, precio_markup_pct=? WHERE id=?");
    // Types: 7s + 2i + 6s(cat_api..cat_discord3) + 2i(activo,moneda) + 4s(stickers) + 4s(badge2) + 1d(markup) + 1i(WHERE id) = 27
    $stmt->bind_param('sssssss'.'ii'.'ssssss'.'ii'.'ssss'.'ssss'.'di', $edit_nombre, $edit_descripcion, $edit_slug, $nextImage, $nextHeroImage, $nextPackageImage, $nextCatbarImage, $edit_popular, $edit_api_free_fire, $edit_categoria_api, $edit_categoria_api_2, $edit_categoria_api_3, $edit_categoria_api_discord, $edit_categoria_api_discord_2, $edit_categoria_api_discord_3, $edit_activo, $edit_moneda_fija_id, $edit_sticker_texto, $edit_sticker_icono, $edit_sticker_color_fondo, $edit_sticker_imagen, $edit_badge2_texto, $edit_badge2_icono, $edit_badge2_color_fondo, $edit_badge2_imagen, $edit_precio_markup_pct, $edit_id);
    $stmt->execute();
    $catIds = isset($_POST['cat_ids']) && is_array($_POST['cat_ids']) ? $_POST['cat_ids'] : [];
    game_set_categories($mysqli, $edit_id, $catIds);
    admin_games_redirect($adminGamesUrl);
}

// Procesar creación de juego y características
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['nombre'], $_POST['descripcion'])) {
    $nombre = trim($_POST['nombre']);
    $descripcion = trim($_POST['descripcion']);
    $slug = slugify($nombre);
    $moneda_fija_id = !empty($_POST['moneda_fija_id']) ? intval($_POST['moneda_fija_id']) : null;
    $popular = isset($_POST['popular']) ? 1 : 0;
    $apiSelection = admin_game_normalize_api_selection($_POST, 'categoria_api_tiendagiftven', 'categoria_api_discord', $mixedApiUnionEnabled);
    if (!$apiSelection['ok']) {
        admin_games_redirect($adminGamesUrl, ['error' => $apiSelection['message']]);
    }
    $categoria_api          = $apiSelection['giftven'];
    $categoria_api_2        = $apiSelection['giftven2'];
    $categoria_api_3        = $apiSelection['giftven3'];
    $categoria_api_discord  = $discordApiEnabled ? $apiSelection['discord'] : '';
    $categoria_api_discord_2 = $discordApiEnabled ? $apiSelection['discord2'] : '';
    $categoria_api_discord_3 = $discordApiEnabled ? $apiSelection['discord3'] : '';
    $api_free_fire = $apiSelection['api_free_fire'];
    $precio_markup_pct = max(0.0, min(10000.0, floatval(str_replace(',', '.', trim((string) ($_POST['precio_markup_pct'] ?? '0'))))));
    $activo = isset($_POST['activo']) ? 1 : 0;
    $orden = admin_game_next_order($mysqli);
    $imagen = admin_game_store_upload($_FILES['imagen'] ?? [], 'juego_');
    $imagen_hero = admin_game_store_upload($_FILES['imagen_hero'] ?? [], 'juegohero_');
    $imagen_paquete = admin_game_store_upload($_FILES['imagen_paquete'] ?? [], 'juegopaq_');
    $imagen_catbar = admin_game_store_upload($_FILES['imagen_catbar'] ?? [], 'juegocatbar_');
    $sticker_ico_custom   = trim((string) ($_POST['sticker_icono_custom'] ?? ''));
    $sticker_ico_preset   = trim((string) ($_POST['sticker_icono_select'] ?? ''));
    $sticker_icono        = $sticker_ico_custom !== '' ? $sticker_ico_custom : $sticker_ico_preset;
    $sticker_texto        = mb_substr(trim((string) ($_POST['sticker_texto'] ?? '')), 0, 80, 'UTF-8');
    $rawStickerColorC     = trim((string) ($_POST['sticker_color_fondo'] ?? ''));
    $sticker_color_fondo  = preg_match('/^#[0-9a-fA-F]{3,6}$/', $rawStickerColorC) ? $rawStickerColorC : '#0f1a2e';
    $stickerUploadC       = game_sticker_store_upload($_FILES['sticker_imagen'] ?? []);
    $sticker_imagen       = ($stickerUploadC['ok'] && $stickerUploadC['path'] !== '') ? $stickerUploadC['path'] : '';
    $badge2_ico_custom   = trim((string) ($_POST['badge2_icono_custom'] ?? ''));
    $badge2_ico_preset   = trim((string) ($_POST['badge2_icono_select'] ?? ''));
    $badge2_icono        = $badge2_ico_custom !== '' ? $badge2_ico_custom : $badge2_ico_preset;
    $badge2_texto        = mb_substr(trim((string) ($_POST['badge2_texto'] ?? '')), 0, 80, 'UTF-8');
    $rawBadge2ColorC     = trim((string) ($_POST['badge2_color_fondo'] ?? ''));
    $badge2_color_fondo  = preg_match('/^#[0-9a-fA-F]{3,6}$/', $rawBadge2ColorC) ? $rawBadge2ColorC : '#0f1a2e';
    $badge2UploadC       = game_badge2_store_upload($_FILES['badge2_imagen'] ?? []);
    $badge2_imagen       = ($badge2UploadC['ok'] && $badge2UploadC['path'] !== '') ? $badge2UploadC['path'] : '';
    $stmt = $mysqli->prepare("INSERT INTO juegos (nombre, imagen, imagen_hero, imagen_paquete, imagen_catbar, descripcion, slug, moneda_fija_id, popular, api_free_fire, categoria_api, categoria_api_2, categoria_api_3, categoria_api_discord, categoria_api_discord_2, categoria_api_discord_3, activo, orden, sticker_texto, sticker_icono, sticker_color_fondo, sticker_imagen, badge2_texto, badge2_icono, badge2_color_fondo, badge2_imagen, precio_markup_pct) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    // Types: 7s + 3i(moneda,popular,api_ff) + 6s(cat_api..cat_discord3) + 2i(activo,orden) + 4s(stickers) + 4s(badge2) + 1d(markup) = 27
    $stmt->bind_param('sssssss'.'iii'.'ssssss'.'ii'.'ssss'.'ssss'.'d', $nombre, $imagen, $imagen_hero, $imagen_paquete, $imagen_catbar, $descripcion, $slug, $moneda_fija_id, $popular, $api_free_fire, $categoria_api, $categoria_api_2, $categoria_api_3, $categoria_api_discord, $categoria_api_discord_2, $categoria_api_discord_3, $activo, $orden, $sticker_texto, $sticker_icono, $sticker_color_fondo, $sticker_imagen, $badge2_texto, $badge2_icono, $badge2_color_fondo, $badge2_imagen, $precio_markup_pct);
    $stmt->execute();
    $juego_id = $mysqli->insert_id;
    $catIds = isset($_POST['cat_ids']) && is_array($_POST['cat_ids']) ? $_POST['cat_ids'] : [];
    game_set_categories($mysqli, (int) $juego_id, $catIds);
    // Características seleccionadas del select múltiple
    if (!empty($_POST['caracteristicas_select'])) {
        foreach ($_POST['caracteristicas_select'] as $car) {
            $car = trim($car);
            if ($car !== '') {
                $stmt2 = $mysqli->prepare("INSERT INTO juego_caracteristicas (juego_id, caracteristica) VALUES (?, ?)");
                $stmt2->bind_param('is', $juego_id, $car);
                $stmt2->execute();
            }
        }
    }
    // Características nuevas escritas
    if (!empty($_POST['caracteristicas'])) {
        foreach ($_POST['caracteristicas'] as $car) {
            $car = trim($car);
            if ($car !== '') {
                $stmt2 = $mysqli->prepare("INSERT INTO juego_caracteristicas (juego_id, caracteristica) VALUES (?, ?)");
                $stmt2->bind_param('is', $juego_id, $car);
                $stmt2->execute();
            }
        }
    }
    admin_games_redirect($adminGamesUrl);
}

// Listar monedas para el select
$resm = $mysqli->query("SELECT * FROM monedas ORDER BY nombre ASC");
$monedas = $resm->fetch_all(MYSQLI_ASSOC);
// Listar características únicas
$rescar = $mysqli->query("SELECT DISTINCT caracteristica FROM juego_caracteristicas ORDER BY caracteristica ASC");
$caracteristicas_unicas = [];
while ($row = $rescar->fetch_assoc()) {
    $caracteristicas_unicas[] = $row['caracteristica'];
}
// Listar juegos existentes
$resj = $mysqli->query("SELECT * FROM juegos ORDER BY CASE WHEN orden IS NULL THEN 1 ELSE 0 END, orden ASC, id ASC");
$juegos = $resj->fetch_all(MYSQLI_ASSOC);
$paquetesPorJuego = [];
$resPaquetes = $mysqli->query("SELECT juego_id, COUNT(*) AS total FROM juego_paquetes GROUP BY juego_id");
if ($resPaquetes instanceof mysqli_result) {
    while ($row = $resPaquetes->fetch_assoc()) {
        $paquetesPorJuego[(int) $row['juego_id']] = (int) $row['total'];
    }
}
// Categorías de juegos
game_categories_ensure_schema($mysqli);
if (function_exists('game_category_get_or_create_todos')) {
    game_category_get_or_create_todos($mysqli);
}
$allCategories = game_category_list($mysqli);
// Precargar categorías asignadas por juego
$gameCategories = [];
$gcatAssignResult = $mysqli->query(
    "SELECT a.juego_id, c.id AS cat_id, c.nombre, c.icono, c.color
     FROM juego_categoria_asignada a
     INNER JOIN juego_categorias c ON c.id = a.categoria_id
     ORDER BY c.orden ASC, c.nombre ASC"
);
if ($gcatAssignResult instanceof mysqli_result) {
    while ($gcRow = $gcatAssignResult->fetch_assoc()) {
        $gameCategories[(int) $gcRow['juego_id']][] = $gcRow;
    }
}
?>
<?php include '../includes/header.php'; ?>
<main class="container-lg mt-5 bg-dark bg-opacity-75 rounded-4 p-4 shadow">
    <?php if ($adminGamesError !== ''): ?>
        <div class="alert alert-danger mb-4" style="background:#3a1520;color:#ffd6de;border:1px solid #ff5e8a;">
            <?= htmlspecialchars($adminGamesError, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>
    <?php
    // Modal edición de juego
    if (isset($_GET['editar'])) {
            $edit_id = intval($_GET['editar']);
            $res_edit = $mysqli->prepare("SELECT * FROM juegos WHERE id=?");
            $res_edit->bind_param('i', $edit_id);
            $res_edit->execute();
            $juego_edit = $res_edit->get_result()->fetch_assoc();
            $editGameCatIds = $juego_edit ? game_get_category_ids($mysqli, $edit_id) : [];
            if ($juego_edit):
    ?>
    <div class="fixed-top w-100 h-100 d-flex align-items-start justify-content-center" style="background:rgba(0,0,0,0.7);z-index:1050;overflow-y:auto;-webkit-overflow-scrolling:touch;padding:1rem;">
        <form method="post" enctype="multipart/form-data" class="bg-dark neon-card p-4 rounded-4 position-relative" style="max-width:600px;width:100%;max-height:calc(100dvh - 2rem);overflow-y:auto;-webkit-overflow-scrolling:touch;box-shadow:0 0 2rem #00fff733;margin:auto;">
            <h3 class="text-neon mb-3">Editar juego</h3>
            <input type="hidden" name="edit_juego_id" value="<?= $juego_edit['id'] ?>">
            <div class="mb-3">
                <label class="form-label text-neon">Nombre</label>
                <input type="text" name="edit_nombre" value="<?= htmlspecialchars($juego_edit['nombre']) ?>" required class="form-control" style="background:#222c3a;color:#00fff7;border:1px solid #00fff7;">
            </div>
            <div class="mb-3">
                <label class="form-label text-neon">Descripción</label>
                <textarea name="edit_descripcion" required class="form-control" style="background:#222c3a;color:#00fff7;border:1px solid #00fff7;"><?= htmlspecialchars($juego_edit['descripcion']) ?></textarea>
            </div>
            <div class="mb-3">
                <label class="form-label text-neon">Moneda fija o variable</label>
                <select name="edit_moneda_fija_id" class="form-select" style="background:#222c3a;color:#00fff7;border:1px solid #00fff7;">
                    <option value="" <?= ($juego_edit['moneda_fija_id'] === null || $juego_edit['moneda_fija_id'] === '' || $juego_edit['moneda_fija_id'] == 0) ? 'selected' : '' ?>>Moneda variable (usuario elige)</option>
                    <?php foreach ($monedas as $m): ?>
                    <option value="<?= $m['id'] ?>" <?= ($juego_edit['moneda_fija_id'] == $m['id']) ? 'selected' : '' ?>><?= htmlspecialchars($m['nombre']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-check mb-3">
                <input type="checkbox" name="edit_popular" class="form-check-input" id="editPopularCheck" <?= !empty($juego_edit['popular']) ? 'checked' : '' ?>>
                <label class="form-check-label text-neon" for="editPopularCheck">Marcar como popular</label>
            </div>
            <div class="form-check mb-3">
                <label class="form-label text-neon" for="editCategoriaApiInput">Juegos API TiendaGiftVen (Slot 1)</label>
                <select name="edit_categoria_api_tiendagiftven" id="editCategoriaApiInput" class="form-select<?= $gameApiExclusiveClass ?>" data-exclusive-group="edit-game-api" data-exclusive-target="editDiscordApiInput" data-exclusive-enabled="<?= $mixedApiUnionEnabled ? '0' : '1' ?>" style="background:#222c3a;color:#00fff7;border:1px solid #00fff7;">
                    <option value="">Proceso manual / sin API</option>
                    <?php foreach ($apiCategories as $apiCategory): ?>
                    <option value="<?= htmlspecialchars($apiCategory, ENT_QUOTES, 'UTF-8') ?>" <?= (string) ($juego_edit['categoria_api'] ?? '') === (string) $apiCategory ? 'selected' : '' ?>><?= htmlspecialchars($apiCategory, ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text mt-2" style="color:#8be9fd;">Categoría principal de TiendaGiftVen para este juego.</div>
            </div>
            <div class="form-check mb-3">
                <label class="form-label text-neon" for="editCategoriaApiInput2">Juegos API TiendaGiftVen (Slot 2 — opcional)</label>
                <select name="edit_categoria_api_tiendagiftven_2" id="editCategoriaApiInput2" class="form-select<?= $gameApiExclusiveClass ?>" data-exclusive-group="edit-game-api" data-exclusive-target="editDiscordApiInput" data-exclusive-enabled="<?= $mixedApiUnionEnabled ? '0' : '1' ?>" style="background:#222c3a;color:#00fff7;border:1px solid #00fff7;">
                    <option value="">— Sin segundo slot —</option>
                    <?php foreach ($apiCategories as $apiCategory): ?>
                    <option value="<?= htmlspecialchars($apiCategory, ENT_QUOTES, 'UTF-8') ?>" <?= (string) ($juego_edit['categoria_api_2'] ?? '') === (string) $apiCategory ? 'selected' : '' ?>><?= htmlspecialchars($apiCategory, ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text mt-2" style="color:#8be9fd;">Segunda categoría de TiendaGiftVen (ej: Blood Strike 2.0).</div>
            </div>
            <div class="form-check mb-3">
                <label class="form-label text-neon" for="editCategoriaApiInput3">Juegos API TiendaGiftVen (Slot 3 — opcional)</label>
                <select name="edit_categoria_api_tiendagiftven_3" id="editCategoriaApiInput3" class="form-select<?= $gameApiExclusiveClass ?>" data-exclusive-group="edit-game-api" data-exclusive-target="editDiscordApiInput" data-exclusive-enabled="<?= $mixedApiUnionEnabled ? '0' : '1' ?>" style="background:#222c3a;color:#00fff7;border:1px solid #00fff7;">
                    <option value="">— Sin tercer slot —</option>
                    <?php foreach ($apiCategories as $apiCategory): ?>
                    <option value="<?= htmlspecialchars($apiCategory, ENT_QUOTES, 'UTF-8') ?>" <?= (string) ($juego_edit['categoria_api_3'] ?? '') === (string) $apiCategory ? 'selected' : '' ?>><?= htmlspecialchars($apiCategory, ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text mt-2" style="color:#8be9fd;">Tercera categoría de TiendaGiftVen opcional.</div>
            </div>
            <?php if ($discordApiEnabled): ?>
            <div class="form-check mb-3">
                <label class="form-label text-neon" for="editDiscordApiInput">Juegos API Discord (Slot 1)</label>
                <div class="d-flex gap-2 align-items-start flex-wrap">
                    <select name="edit_categoria_api_discord" id="editDiscordApiInput" class="form-select<?= $gameApiExclusiveClass ?> flex-grow-1" data-discord-games-select="1" data-exclusive-group="edit-game-api" data-exclusive-target="editCategoriaApiInput" data-exclusive-enabled="<?= $mixedApiUnionEnabled ? '0' : '1' ?>" style="background:#222c3a;color:#00fff7;border:1px solid #00fff7;min-width:260px;">
                        <option value="">Proceso manual / sin API</option>
                        <?php foreach ($discordApiCommandOptions as $discordCommand): ?>
                            <?php $discordKey = (string) ($discordCommand['key'] ?? ''); ?>
                            <?php $discordLabel = trim((string) ($discordCommand['label'] ?? $discordKey)); ?>
                            <option value="<?= htmlspecialchars($discordKey, ENT_QUOTES, 'UTF-8') ?>" <?= (string) ($juego_edit['categoria_api_discord'] ?? '') === $discordKey ? 'selected' : '' ?>><?= htmlspecialchars($discordLabel, ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="button" class="btn btn-outline-info js-refresh-discord-games" style="border-color:#00fff7;color:#00fff7;white-space:nowrap;">Traer juegos</button>
                </div>
                <div class="form-text mt-2" style="color:#8be9fd;">Comando principal de Discord para este juego.</div>
            </div>
            <div class="form-check mb-3">
                <label class="form-label text-neon" for="editDiscordApiInput2">Juegos API Discord (Slot 2 — opcional)</label>
                <select name="edit_categoria_api_discord_2" id="editDiscordApiInput2" class="form-select<?= $gameApiExclusiveClass ?> flex-grow-1" data-exclusive-group="edit-game-api" data-exclusive-target="editCategoriaApiInput" data-exclusive-enabled="<?= $mixedApiUnionEnabled ? '0' : '1' ?>" style="background:#222c3a;color:#00fff7;border:1px solid #00fff7;">
                    <option value="">— Sin segundo slot —</option>
                    <?php foreach ($discordApiCommandOptions as $discordCommand): ?>
                        <?php $discordKey = (string) ($discordCommand['key'] ?? ''); ?>
                        <?php $discordLabel = trim((string) ($discordCommand['label'] ?? $discordKey)); ?>
                        <option value="<?= htmlspecialchars($discordKey, ENT_QUOTES, 'UTF-8') ?>" <?= (string) ($juego_edit['categoria_api_discord_2'] ?? '') === $discordKey ? 'selected' : '' ?>><?= htmlspecialchars($discordLabel, ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text mt-2" style="color:#8be9fd;">Segundo comando de Discord (ej: Roblox recarga).</div>
            </div>
            <div class="form-check mb-3">
                <label class="form-label text-neon" for="editDiscordApiInput3">Juegos API Discord (Slot 3 — opcional)</label>
                <select name="edit_categoria_api_discord_3" id="editDiscordApiInput3" class="form-select<?= $gameApiExclusiveClass ?> flex-grow-1" data-exclusive-group="edit-game-api" data-exclusive-target="editCategoriaApiInput" data-exclusive-enabled="<?= $mixedApiUnionEnabled ? '0' : '1' ?>" style="background:#222c3a;color:#00fff7;border:1px solid #00fff7;">
                    <option value="">— Sin tercer slot —</option>
                    <?php foreach ($discordApiCommandOptions as $discordCommand): ?>
                        <?php $discordKey = (string) ($discordCommand['key'] ?? ''); ?>
                        <?php $discordLabel = trim((string) ($discordCommand['label'] ?? $discordKey)); ?>
                        <option value="<?= htmlspecialchars($discordKey, ENT_QUOTES, 'UTF-8') ?>" <?= (string) ($juego_edit['categoria_api_discord_3'] ?? '') === $discordKey ? 'selected' : '' ?>><?= htmlspecialchars($discordLabel, ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text mt-2" style="color:#8be9fd;">Tercer comando de Discord opcional.</div>
            </div>
            <?php endif; ?>
            <div class="mb-3">
                <label class="form-label text-neon">Margen de ganancia API (%)</label>
                <div class="input-group">
                    <input type="number" name="edit_precio_markup_pct" step="0.01" min="0" max="10000" value="<?= htmlspecialchars(number_format((float) ($juego_edit['precio_markup_pct'] ?? 0), 2, '.', ''), ENT_QUOTES, 'UTF-8') ?>" class="form-control" style="background:#222c3a;color:#00fff7;border:1px solid #00fff7;">
                    <span class="input-group-text" style="background:#222c3a;color:#00fff7;border:1px solid #00fff7;">%</span>
                </div>
                <div class="form-text mt-2" style="color:#8be9fd;">Porcentaje de ganancia sobre el precio de la API. Ej: 50 → precio API x1.5. Se aplica automáticamente en tiempo real cuando el dueño de la API modifica sus precios.</div>
            </div>
            <div class="form-check mb-3">
                <input type="checkbox" name="edit_activo" class="form-check-input" id="editActivoCheck" <?= !isset($juego_edit['activo']) || !empty($juego_edit['activo']) ? 'checked' : '' ?>>
                <label class="form-check-label text-neon" for="editActivoCheck">Juego activo / publicado</label>
            </div>
            <div class="mb-3">
                <label class="form-label text-neon">Imagen actual:</label><br>
                <?php if ($juego_edit['imagen']): ?>
                    <img src="/<?= htmlspecialchars($juego_edit['imagen']) ?>" alt="Imagen actual" class="mb-2 rounded" style="max-width:120px;max-height:120px;border:2px solid #00fff7;background:#222c3a;box-shadow:0 0 8px #00fff7;">
                <?php endif; ?>
                <input type="file" name="edit_imagen" accept="image/*" class="form-control mt-2" style="background:#222c3a;color:#00fff7;border:1px solid #00fff7;">
            </div>
            <div class="mb-3">
                <label class="form-label text-neon">Imagen hero del juego:</label><br>
                <?php if (!empty($juego_edit['imagen_hero'])): ?>
                    <img src="/<?= htmlspecialchars($juego_edit['imagen_hero']) ?>" alt="Imagen hero actual" class="mb-2 rounded" style="max-width:180px;max-height:120px;border:2px solid #00fff7;background:#222c3a;box-shadow:0 0 8px #00fff7;object-fit:cover;">
                <?php else: ?>
                    <div class="small mb-2" style="color:#8be9fd;">Si no cargas una imagen hero, se usará la imagen principal del juego.</div>
                <?php endif; ?>
                <input type="file" name="edit_imagen_hero" accept="image/*" class="form-control mt-2" style="background:#222c3a;color:#00fff7;border:1px solid #00fff7;">
                <div class="form-check mt-2">
                    <input type="checkbox" name="remove_edit_imagen_hero" class="form-check-input" id="removeEditHeroImage">
                    <label class="form-check-label text-neon" for="removeEditHeroImage">Usar la imagen principal como hero</label>
                </div>
            </div>
            <div class="mb-3">
                <label class="form-label text-neon">Imagen común para paquetes:</label><br>
                <?php if ($juego_edit['imagen_paquete']): ?>
                    <img src="/<?= htmlspecialchars($juego_edit['imagen_paquete']) ?>" alt="Imagen paquete actual" class="mb-2 rounded" style="max-width:80px;max-height:80px;border:2px solid #00fff7;background:#222c3a;box-shadow:0 0 8px #00fff7;">
                <?php endif; ?>
                <input type="file" name="edit_imagen_paquete" accept="image/*" class="form-control mt-2" style="background:#222c3a;color:#00fff7;border:1px solid #00fff7;">
            </div>
            <div class="mb-3">
                <label class="form-label text-neon">Imagen para barra de categorías (header):</label><br>
                <?php if (!empty($juego_edit['imagen_catbar'])): ?>
                    <img src="/<?= htmlspecialchars($juego_edit['imagen_catbar']) ?>" alt="Imagen barra actual" class="mb-2 rounded-circle" style="width:64px;height:64px;object-fit:cover;border:2px solid #00fff7;background:#222c3a;box-shadow:0 0 8px #00fff7;">
                <?php else: ?>
                    <div class="small mb-2" style="color:#8be9fd;">Si no cargas una imagen, en la barra del header se usará la imagen principal del juego.</div>
                <?php endif; ?>
                <input type="file" name="edit_imagen_catbar" accept="image/*" class="form-control mt-2" style="background:#222c3a;color:#00fff7;border:1px solid #00fff7;">
                <?php if (!empty($juego_edit['imagen_catbar'])): ?>
                <div class="form-check mt-2">
                    <input type="checkbox" name="remove_edit_imagen_catbar" class="form-check-input" id="removeEditCatbarImage">
                    <label class="form-check-label text-neon" for="removeEditCatbarImage">Usar la imagen principal en la barra</label>
                </div>
                <?php endif; ?>
            </div>
            <!-- STICKER / BADGE -->
            <?php
            $editStickerSymbols     = game_sticker_icon_symbols();
            $editStickerCurrentIcon = (string) ($juego_edit['sticker_icono'] ?? '');
            $editStickerIsPreset    = isset($editStickerSymbols[$editStickerCurrentIcon]);
            $editStickerPresetVal   = $editStickerIsPreset ? $editStickerCurrentIcon : '';
            $editStickerCustomVal   = $editStickerIsPreset ? '' : $editStickerCurrentIcon;
            ?>
            <div class="mb-3 p-3" style="border:1px solid #7b2fff;border-radius:8px;background:#130828;">
                <div class="mb-2 fw-bold" style="color:#c77dff;font-size:0.9rem;letter-spacing:0.04em;">STICKER / BADGE</div>
                <div class="row g-2 mb-2">
                    <div class="col-sm-6">
                        <label class="form-label" style="color:#c77dff;font-size:0.85rem;">Icono predefinido</label>
                        <select name="edit_sticker_icono_select" class="form-select" style="background:#1a0a30;color:#c77dff;border:1px solid #7b2fff;font-size:0.92rem;">
                            <option value="">— Sin icono —</option>
                            <?php foreach ($editStickerSymbols as $symKey => $symEmoji): ?>
                                <option value="<?= htmlspecialchars($symKey, ENT_QUOTES, 'UTF-8') ?>" <?= $editStickerPresetVal === $symKey ? 'selected' : '' ?>>
                                    <?= $symEmoji ?> <?= htmlspecialchars(ucwords(str_replace('_', ' ', $symKey)), ENT_QUOTES, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label" style="color:#c77dff;font-size:0.85rem;">Emoji propio <span style="color:#8be9fd;font-weight:400;">(sobreescribe el predefinido)</span></label>
                        <input type="text" name="edit_sticker_icono_custom" value="<?= htmlspecialchars($editStickerCustomVal, ENT_QUOTES, 'UTF-8') ?>" class="form-control" style="background:#1a0a30;color:#c77dff;border:1px solid #7b2fff;" placeholder="ej: 🔥 💎 ⚡">
                    </div>
                </div>
                <div class="row g-2 mb-2">
                    <div class="col-sm-8">
                        <label class="form-label" style="color:#c77dff;font-size:0.85rem;">Texto del sticker</label>
                        <input type="text" name="edit_sticker_texto" value="<?= htmlspecialchars((string) ($juego_edit['sticker_texto'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" class="form-control" style="background:#1a0a30;color:#c77dff;border:1px solid #7b2fff;" placeholder="Más vendido, Oferta, Nuevo…" maxlength="80">
                    </div>
                    <div class="col-sm-4">
                        <label class="form-label" style="color:#c77dff;font-size:0.85rem;">Color de fondo</label>
                        <input type="color" name="edit_sticker_color_fondo" value="<?= htmlspecialchars(!empty($juego_edit['sticker_color_fondo']) ? $juego_edit['sticker_color_fondo'] : '#7b2fff', ENT_QUOTES, 'UTF-8') ?>" class="form-control form-control-color w-100" style="background:#1a0a30;border:1px solid #7b2fff;height:38px;padding:2px 4px;">
                    </div>
                </div>
                <div>
                    <label class="form-label" style="color:#c77dff;font-size:0.85rem;">Imagen del sticker <span style="color:#8be9fd;font-weight:400;">PNG/WebP con transparencia · max 2 MB</span></label>
                    <?php if (!empty($juego_edit['sticker_imagen'])): ?>
                    <div class="mb-2 d-flex align-items-center gap-3">
                        <img src="/<?= htmlspecialchars(ltrim((string) $juego_edit['sticker_imagen'], '/'), ENT_QUOTES, 'UTF-8') ?>" alt="Sticker actual" style="max-width:60px;max-height:60px;border:1px solid #7b2fff;border-radius:6px;background:#1a0a30;object-fit:contain;">
                        <label class="d-flex align-items-center gap-2 mb-0" style="cursor:pointer;color:#ff6b6b;font-size:0.85rem;">
                            <input type="checkbox" name="remove_edit_sticker_imagen" style="accent-color:#ff6b6b;width:1rem;height:1rem;">
                            Eliminar imagen del sticker
                        </label>
                    </div>
                    <?php endif; ?>
                    <input type="file" name="edit_sticker_imagen" accept="image/*" class="form-control" style="background:#1a0a30;color:#c77dff;border:1px solid #7b2fff;">
                </div>
            </div>
            <!-- BADGE INFERIOR -->
            <?php
            $editBadge2Symbols     = game_sticker_icon_symbols();
            $editBadge2CurrentIcon = (string) ($juego_edit['badge2_icono'] ?? '');
            $editBadge2IsPreset    = isset($editBadge2Symbols[$editBadge2CurrentIcon]);
            $editBadge2PresetVal   = $editBadge2IsPreset ? $editBadge2CurrentIcon : '';
            $editBadge2CustomVal   = $editBadge2IsPreset ? '' : $editBadge2CurrentIcon;
            ?>
            <div class="mb-3 p-3" style="border:1px solid #2f8fff;border-radius:8px;background:#081b30;">
                <div class="mb-2 fw-bold" style="color:#7dc2ff;font-size:0.9rem;letter-spacing:0.04em;">BADGE INFERIOR</div>
                <div class="row g-2 mb-2">
                    <div class="col-sm-6">
                        <label class="form-label" style="color:#7dc2ff;font-size:0.85rem;">Icono predefinido</label>
                        <select name="edit_badge2_icono_select" class="form-select" style="background:#0a2140;color:#7dc2ff;border:1px solid #2f8fff;font-size:0.92rem;">
                            <option value="">— Sin icono —</option>
                            <?php foreach ($editBadge2Symbols as $symKey => $symEmoji): ?>
                                <option value="<?= htmlspecialchars($symKey, ENT_QUOTES, 'UTF-8') ?>" <?= $editBadge2PresetVal === $symKey ? 'selected' : '' ?>>
                                    <?= $symEmoji ?> <?= htmlspecialchars(ucwords(str_replace('_', ' ', $symKey)), ENT_QUOTES, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label" style="color:#7dc2ff;font-size:0.85rem;">Emoji propio <span style="color:#8be9fd;font-weight:400;">(sobreescribe el predefinido, deja vacío para no usar emoji)</span></label>
                        <input type="text" name="edit_badge2_icono_custom" value="<?= htmlspecialchars($editBadge2CustomVal, ENT_QUOTES, 'UTF-8') ?>" class="form-control" style="background:#0a2140;color:#7dc2ff;border:1px solid #2f8fff;" placeholder="ej: 🔥 💎 ⚡">
                    </div>
                </div>
                <div class="row g-2 mb-2">
                    <div class="col-sm-8">
                        <label class="form-label" style="color:#7dc2ff;font-size:0.85rem;">Texto del badge</label>
                        <input type="text" name="edit_badge2_texto" value="<?= htmlspecialchars((string) ($juego_edit['badge2_texto'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" class="form-control" style="background:#0a2140;color:#7dc2ff;border:1px solid #2f8fff;" placeholder="Envío rápido, Exclusivo…" maxlength="80">
                    </div>
                    <div class="col-sm-4">
                        <label class="form-label" style="color:#7dc2ff;font-size:0.85rem;">Color de fondo</label>
                        <input type="color" name="edit_badge2_color_fondo" value="<?= htmlspecialchars(!empty($juego_edit['badge2_color_fondo']) ? $juego_edit['badge2_color_fondo'] : '#2f8fff', ENT_QUOTES, 'UTF-8') ?>" class="form-control form-control-color w-100" style="background:#0a2140;border:1px solid #2f8fff;height:38px;padding:2px 4px;">
                    </div>
                </div>
                <div>
                    <label class="form-label" style="color:#7dc2ff;font-size:0.85rem;">Imagen del badge <span style="color:#8be9fd;font-weight:400;">PNG/WebP con transparencia · max 2 MB</span></label>
                    <?php if (!empty($juego_edit['badge2_imagen'])): ?>
                    <div class="mb-2 d-flex align-items-center gap-3">
                        <img src="/<?= htmlspecialchars(ltrim((string) $juego_edit['badge2_imagen'], '/'), ENT_QUOTES, 'UTF-8') ?>" alt="Badge actual" style="max-width:60px;max-height:60px;border:1px solid #2f8fff;border-radius:6px;background:#0a2140;object-fit:contain;">
                        <label class="d-flex align-items-center gap-2 mb-0" style="cursor:pointer;color:#ff6b6b;font-size:0.85rem;">
                            <input type="checkbox" name="remove_edit_badge2_imagen" style="accent-color:#ff6b6b;width:1rem;height:1rem;">
                            Eliminar imagen del badge
                        </label>
                    </div>
                    <?php endif; ?>
                    <input type="file" name="edit_badge2_imagen" accept="image/*" class="form-control" style="background:#0a2140;color:#7dc2ff;border:1px solid #2f8fff;">
                </div>
            </div>
            <?php if ($allCategories !== []): ?>
            <div class="mb-3">
                <label class="form-label text-neon">Categorías</label>
                <div class="d-flex flex-wrap gap-2" style="max-height:160px;overflow-y:auto;background:#0f1a28;border:1px solid #1e3a5f;border-radius:8px;padding:0.6rem;">
                    <?php foreach ($allCategories as $gcat): ?>
                    <label class="d-flex align-items-center gap-2 px-2 py-1 rounded-2" style="cursor:pointer;background:#182030;border:1px solid #1e3a5f;user-select:none;">
                        <input type="checkbox" name="cat_ids[]" value="<?= (int) $gcat['id'] ?>"
                               <?= in_array((int) $gcat['id'], $editGameCatIds, true) ? 'checked' : '' ?>
                               style="accent-color:#00fff7;width:1rem;height:1rem;">
                        <?php if ($gcat['icono'] !== ''): ?><span><?= htmlspecialchars($gcat['icono'], ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
                        <span style="color:#00fff7;font-size:0.9rem;"><?= htmlspecialchars($gcat['nombre'], ENT_QUOTES, 'UTF-8') ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php else: ?>
            <div class="mb-3">
                <label class="form-label text-neon">Categorías</label>
                <div class="small" style="color:#8be9fd;">Crea categorías en la sección «Categorías» para poder asignarlas.</div>
            </div>
            <?php endif; ?>
            <button type="submit" name="edit_juego_submit" class="btn neon-btn-info w-100 mt-3">Guardar cambios</button>
            <a href="<?= htmlspecialchars($adminGamesUrl, ENT_QUOTES, 'UTF-8') ?>" class="position-absolute top-0 end-0 m-3 text-neon fs-3" style="text-decoration:none;">&times;</a>
        </form>
    </div>
    <?php endif; }
    ?>
    <!-- ═══ GESTIÓN DE CATEGORÍAS ════════════════════════════════════════ -->
    <section class="mb-5" id="gcatSection">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
            <h3 style="color:#00fff7;margin:0;">Categorías de juegos</h3>
            <button type="button" id="gcatToggleCreate" class="btn btn-sm" style="background:transparent;border:1px solid #00fff7;color:#00fff7;padding:0.3rem 0.9rem;">+ Nueva categoría</button>
        </div>

        <!-- Formulario crear categoría -->
        <div id="gcatCreateForm" style="display:none;background:#182030;border:1px solid #00fff7;border-radius:10px;padding:1rem;margin-bottom:1rem;">
            <div class="row g-2 align-items-end">
                <div class="col-md-4 col-sm-6">
                    <label class="form-label" style="color:#00fff7;font-size:0.85rem;margin-bottom:0.25rem;">Nombre *</label>
                    <input type="text" id="gcatNombre" class="form-control form-control-sm" placeholder="ej. Battle Royale" style="background:#222c3a;color:#00fff7;border:1px solid #00fff7;">
                </div>
                <div class="col-md-3 col-sm-6">
                    <label class="form-label" style="color:#8be9fd;font-size:0.85rem;margin-bottom:0.25rem;">Slug <span style="opacity:.6">(auto)</span></label>
                    <input type="text" id="gcatSlug" class="form-control form-control-sm" placeholder="battle-royale" style="background:#222c3a;color:#8be9fd;border:1px solid #1e3a5f;">
                </div>
                <div class="col-md-2 col-6">
                    <label class="form-label" style="color:#00fff7;font-size:0.85rem;margin-bottom:0.25rem;">Icono / Emoji</label>
                    <input type="text" id="gcatIcono" class="form-control form-control-sm text-center" placeholder="🎮" style="background:#222c3a;color:#00fff7;border:1px solid #00fff7;font-size:1.2rem;">
                </div>
                <div class="col-md-2 col-6">
                    <label class="form-label" style="color:#00fff7;font-size:0.85rem;margin-bottom:0.25rem;">Color</label>
                    <input type="color" id="gcatColor" value="#00fff7" class="form-control form-control-color form-control-sm" style="background:#222c3a;border:1px solid #00fff7;width:100%;height:34px;">
                </div>
                <div class="col-md-1 col-6">
                    <label class="form-label" style="color:#8be9fd;font-size:0.85rem;margin-bottom:0.25rem;">Orden</label>
                    <input type="number" id="gcatOrden" value="0" min="0" class="form-control form-control-sm" style="background:#222c3a;color:#00fff7;border:1px solid #1e3a5f;">
                </div>
            </div>
            <div class="mt-2">
                <input type="text" id="gcatDescripcion" class="form-control form-control-sm" placeholder="Descripción opcional" style="background:#222c3a;color:#8be9fd;border:1px solid #1e3a5f;">
            </div>
            <div class="mt-2">
                <label class="form-label" style="color:#00fff7;font-size:0.82rem;margin-bottom:0.3rem;">Mostrar en barra de menú del frontend</label>
                <div class="d-flex flex-wrap gap-3">
                    <?php foreach (['no' => 'No mostrar', 'imagen' => 'Solo imagen', 'texto' => 'Solo texto', 'imagen_texto' => 'Imagen + texto'] as $val => $lbl): ?>
                    <label class="d-flex align-items-center gap-1" style="cursor:pointer;color:#8be9fd;font-size:0.82rem;">
                        <input type="radio" name="gcatMostrarMenu" value="<?= $val ?>" <?= $val === 'no' ? 'checked' : '' ?> style="accent-color:#00fff7;">
                        <?= $lbl ?>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="mt-2">
                <label class="d-flex align-items-center gap-2" style="cursor:pointer;color:#00fff7;font-size:0.82rem;">
                    <input type="checkbox" id="gcatDestacada" style="accent-color:#00fff7;">
                    <span>Categoría Destacada <small style="color:#8be9fd;font-size:0.78rem;">(aparece en la sección inferior de la tienda, no en la barra de menú)</small></span>
                </label>
            </div>
            <div class="mt-2 d-flex align-items-center gap-3 flex-wrap">
                <div style="flex:1;min-width:200px;">
                    <label class="form-label" style="color:#8be9fd;font-size:0.82rem;margin-bottom:0.2rem;">Imagen de categoría <span style="opacity:.6">(opcional)</span></label>
                    <input type="file" id="gcatImagen" accept="image/*" class="form-control form-control-sm" style="background:#222c3a;color:#8be9fd;border:1px solid #1e3a5f;">
                </div>
                <div id="gcatImagenPreview" style="display:none;">
                    <img id="gcatImagenPreviewImg" style="max-height:54px;border-radius:6px;border:1px solid #1e3a5f;" alt="preview">
                </div>
            </div>
            <div class="d-flex align-items-center gap-2 mt-3 flex-wrap">
                <button type="button" id="gcatCreateBtn" class="btn btn-sm" style="background:#00fff7;color:#111;border:none;font-weight:600;">Crear categoría</button>
                <button type="button" id="gcatCancelCreate" class="btn btn-sm" style="background:transparent;border:1px solid #555;color:#aaa;">Cancelar</button>
                <span id="gcatCreateStatus" class="small" style="color:#ff5e8a;"></span>
            </div>
        </div>

        <!-- Lista de categorías -->
        <div id="gcatList">
            <?php if ($allCategories === []): ?>
            <p class="small" style="color:#8be9fd;">Aún no hay categorías. Crea la primera con el botón de arriba.</p>
            <?php else: ?>
            <?php foreach ($allCategories as $gcat): ?>
            <div class="gcatRow d-flex align-items-center gap-3 p-2 rounded-3 mb-2 flex-wrap" data-id="<?= (int) $gcat['id'] ?>" style="background:#182030;border:1px solid #1e3a5f;">
                <?php if ($gcat['imagen'] !== ''): ?>
                <img src="/<?= htmlspecialchars($gcat['imagen'], ENT_QUOTES, 'UTF-8') ?>" style="width:36px;height:36px;object-fit:cover;border-radius:5px;border:1px solid #1e3a5f;flex-shrink:0;" alt="">
                <?php endif; ?>
                <span style="font-size:1.3em;min-width:1.5rem;text-align:center;"><?= $gcat['icono'] !== '' ? htmlspecialchars($gcat['icono'], ENT_QUOTES, 'UTF-8') : '📁' ?></span>
                <span style="width:12px;height:12px;border-radius:50%;background:<?= htmlspecialchars($gcat['color'] ?: '#00fff7', ENT_QUOTES, 'UTF-8') ?>;display:inline-block;flex-shrink:0;"></span>
                <strong style="color:#00fff7;flex:1;min-width:100px;"><?= htmlspecialchars($gcat['nombre'], ENT_QUOTES, 'UTF-8') ?></strong>
                <code style="color:#8be9fd;font-size:0.8rem;opacity:.8;"><?= htmlspecialchars($gcat['slug'], ENT_QUOTES, 'UTF-8') ?></code>
                <?php if ($gcat['descripcion'] !== ''): ?>
                <span style="color:#b2f6ff;font-size:0.82rem;opacity:.8;"><?= htmlspecialchars($gcat['descripcion'], ENT_QUOTES, 'UTF-8') ?></span>
                <?php endif; ?>
                <?php if (!empty($gcat['es_todos'])): ?>
                <span style="font-size:0.72rem;background:#1a1a3b;border:1px solid #8be9fd;color:#8be9fd;border-radius:12px;padding:0.1rem 0.55rem;flex-shrink:0;">Sistema</span>
                <?php elseif (!empty($gcat['destacada'])): ?>
                <span style="font-size:0.72rem;background:#152b1a;border:1px solid #00ff88;color:#00ff88;border-radius:12px;padding:0.1rem 0.55rem;flex-shrink:0;">Destacada</span>
                <?php endif; ?>
                <div class="d-flex gap-2 ms-auto flex-shrink-0">
                    <button type="button" class="btn btn-sm gcatEditBtn" data-id="<?= (int) $gcat['id'] ?>" style="border:1px solid #00fff7;color:#00fff7;background:transparent;padding:0.1rem 0.55rem;font-size:0.82rem;">Editar</button>
                    <?php if (empty($gcat['es_todos'])): ?>
                    <button type="button" class="btn btn-sm gcatDeleteBtn" data-id="<?= (int) $gcat['id'] ?>" data-nombre="<?= htmlspecialchars($gcat['nombre'], ENT_QUOTES, 'UTF-8') ?>" style="border:1px solid #ff5e8a;color:#ff5e8a;background:transparent;padding:0.1rem 0.55rem;font-size:0.82rem;">Eliminar</button>
                    <?php endif; ?>
                </div>
            </div>
            <div class="gcatEditRow" id="gcatEdit_<?= (int) $gcat['id'] ?>" style="display:none;background:#182030;border:1px solid #00fff7;border-radius:10px;padding:0.75rem;margin-bottom:0.5rem;">
                <div class="row g-2 align-items-end">
                    <div class="col-md-4 col-sm-6">
                        <label class="form-label" style="color:#00fff7;font-size:0.8rem;margin-bottom:0.2rem;">Nombre *</label>
                        <input type="text" class="form-control form-control-sm gcatEditNombre" value="<?= htmlspecialchars($gcat['nombre'], ENT_QUOTES, 'UTF-8') ?>" style="background:#222c3a;color:#00fff7;border:1px solid #00fff7;">
                    </div>
                    <div class="col-md-3 col-sm-6">
                        <label class="form-label" style="color:#8be9fd;font-size:0.8rem;margin-bottom:0.2rem;">Slug</label>
                        <input type="text" class="form-control form-control-sm gcatEditSlug" value="<?= htmlspecialchars($gcat['slug'], ENT_QUOTES, 'UTF-8') ?>" style="background:#222c3a;color:#8be9fd;border:1px solid #1e3a5f;">
                    </div>
                    <div class="col-md-2 col-6">
                        <label class="form-label" style="color:#00fff7;font-size:0.8rem;margin-bottom:0.2rem;">Icono</label>
                        <input type="text" class="form-control form-control-sm gcatEditIcono text-center" value="<?= htmlspecialchars($gcat['icono'], ENT_QUOTES, 'UTF-8') ?>" style="background:#222c3a;color:#00fff7;border:1px solid #00fff7;font-size:1.1rem;">
                    </div>
                    <div class="col-md-2 col-6">
                        <label class="form-label" style="color:#00fff7;font-size:0.8rem;margin-bottom:0.2rem;">Color</label>
                        <input type="color" class="form-control form-control-color form-control-sm gcatEditColor" value="<?= htmlspecialchars($gcat['color'] ?: '#00fff7', ENT_QUOTES, 'UTF-8') ?>" style="background:#222c3a;border:1px solid #00fff7;width:100%;height:32px;">
                    </div>
                    <div class="col-md-1 col-6">
                        <label class="form-label" style="color:#8be9fd;font-size:0.8rem;margin-bottom:0.2rem;">Orden</label>
                        <input type="number" class="form-control form-control-sm gcatEditOrden" value="<?= (int) $gcat['orden'] ?>" min="0" style="background:#222c3a;color:#00fff7;border:1px solid #1e3a5f;">
                    </div>
                </div>
                <div class="mt-2">
                    <input type="text" class="form-control form-control-sm gcatEditDescripcion" value="<?= htmlspecialchars($gcat['descripcion'], ENT_QUOTES, 'UTF-8') ?>" placeholder="Descripción" style="background:#222c3a;color:#8be9fd;border:1px solid #1e3a5f;">
                </div>
                <div class="mt-2">
                    <?php if (!empty($gcat['es_todos'])): ?>
                    <label class="form-label" style="color:#00fff7;font-size:0.78rem;margin-bottom:0.25rem;">Apariencia del tab en el inicio <small style="color:#8be9fd;">(esta categoría nunca va al menú superior)</small></label>
                    <?php else: ?>
                    <label class="form-label" style="color:#00fff7;font-size:0.78rem;margin-bottom:0.25rem;">Mostrar en barra de menú</label>
                    <?php endif; ?>
                    <div class="d-flex flex-wrap gap-3">
                        <?php foreach (['no' => (!empty($gcat['es_todos']) ? 'Solo icono / texto' : 'No'), 'imagen' => 'Solo imagen', 'texto' => 'Solo texto', 'imagen_texto' => 'Imagen + texto'] as $mval => $mlbl): ?>
                        <label class="d-flex align-items-center gap-1" style="cursor:pointer;color:#8be9fd;font-size:0.78rem;">
                            <input type="radio" class="gcatEditMostrarMenu" name="gcatEditMostrarMenu_<?= (int) $gcat['id'] ?>" value="<?= $mval ?>" <?= ($gcat['mostrar_menu'] ?? 'no') === $mval ? 'checked' : '' ?> style="accent-color:#00fff7;">
                            <?= $mlbl ?>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="mt-2">
                    <?php if (!empty($gcat['es_todos'])): ?>
                    <label class="d-flex align-items-center gap-2" style="color:#8be9fd;font-size:0.78rem;opacity:.7;cursor:default;">
                        <input type="checkbox" class="gcatEditDestacada" value="1" checked disabled style="accent-color:#00fff7;">
                        <span>Categoría Destacada <small style="color:#8be9fd;font-size:0.75rem;">(siempre activa para la categoría "Todos")</small></span>
                    </label>
                    <?php else: ?>
                    <label class="d-flex align-items-center gap-2" style="cursor:pointer;color:#00fff7;font-size:0.78rem;">
                        <input type="checkbox" class="gcatEditDestacada" value="1" <?= !empty($gcat['destacada']) ? 'checked' : '' ?> style="accent-color:#00fff7;">
                        <span>Categoría Destacada <small style="color:#8be9fd;font-size:0.75rem;">(en sección inferior, no en barra de menú)</small></span>
                    </label>
                    <?php endif; ?>
                </div>
                <?php if (!empty($gcat['es_todos'])): ?>
                <div class="mt-2">
                    <label class="d-flex align-items-center gap-2" style="cursor:pointer;color:#00fff7;font-size:0.78rem;">
                        <input type="checkbox" class="gcatEditActiva" value="1" <?= !empty($gcat['activa']) ? 'checked' : '' ?> style="accent-color:#00fff7;">
                        <span>Visible en el inicio <small style="color:#8be9fd;font-size:0.75rem;">(si se desactiva, la primera categoría destacada toma el relevo)</small></span>
                    </label>
                </div>
                <?php endif; ?>
                <div class="mt-2 d-flex align-items-center gap-2 flex-wrap">
                    <?php if ($gcat['imagen'] !== ''): ?>
                    <img src="/<?= htmlspecialchars($gcat['imagen'], ENT_QUOTES, 'UTF-8') ?>" class="gcatCurrentImgThumb" style="max-height:40px;border-radius:5px;border:1px solid #1e3a5f;" alt="">
                    <button type="button" class="btn btn-sm gcatRemoveImgBtn" style="border:1px solid #ff5e8a;color:#ff5e8a;background:transparent;font-size:0.78rem;padding:0.1rem 0.5rem;">✕ Quitar imagen</button>
                    <?php endif; ?>
                    <input type="hidden" class="gcatEditRemoveImagen" value="0">
                    <input type="file" class="gcatEditImagen form-control form-control-sm" accept="image/*" style="background:#222c3a;color:#8be9fd;border:1px solid #1e3a5f;max-width:260px;">
                </div>
                <div class="d-flex align-items-center gap-2 mt-2 flex-wrap">
                    <button type="button" class="btn btn-sm gcatSaveEditBtn" data-id="<?= (int) $gcat['id'] ?>" style="background:#00fff7;color:#111;border:none;font-weight:600;">Guardar</button>
                    <button type="button" class="btn btn-sm gcatCancelEditBtn" data-id="<?= (int) $gcat['id'] ?>" style="border:1px solid #555;color:#aaa;background:transparent;">Cancelar</button>
                    <span class="gcatEditStatus small" style="color:#ff5e8a;"></span>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </section>
    <!-- ═══════════════════════════════════════════════════════════════════ -->

    <h2 class="text-center mb-4" style="color:#00fff7;">Gestión de Juegos</h2>
    <form method="post" enctype="multipart/form-data" class="row g-3 mb-4" style="background:#181f2a; border-radius:16px; border:2px solid #00fff7; box-shadow:0 0 24px #00fff733; padding:2rem;">
        <div class="col-md-6">
            <label class="form-label" style="color:#00fff7;">Nombre del juego</label>
            <input type="text" name="nombre" placeholder="Nombre del juego" required class="form-control" style="background:#222c3a; color:#00fff7; border:1px solid #00fff7;">
        </div>
        <div class="col-md-6">
            <label class="form-label" style="color:#00fff7;">Marcar como popular</label>
            <div class="form-check">
                <input type="checkbox" name="popular" class="form-check-input" id="popularCheck">
                <label class="form-check-label" for="popularCheck" style="color:#00fff7;">Popular</label>
            </div>
            <div class="form-check mt-3">
                <label class="form-label" for="categoriaApiInput" style="color:#00fff7;">Juegos API TiendaGiftVen (Slot 1)</label>
                <select name="categoria_api_tiendagiftven" id="categoriaApiInput" class="form-select<?= $gameApiExclusiveClass ?>" data-exclusive-group="create-game-api" data-exclusive-target="categoriaDiscordApiInput" data-exclusive-enabled="<?= $mixedApiUnionEnabled ? '0' : '1' ?>" style="background:#222c3a; color:#00fff7; border:1px solid #00fff7;">
                    <option value="">Proceso manual / sin API</option>
                    <?php foreach ($apiCategories as $apiCategory): ?>
                    <option value="<?= htmlspecialchars($apiCategory, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($apiCategory, ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text mt-2" style="color:#8be9fd;">Categoría principal de TiendaGiftVen.</div>
            </div>
            <div class="form-check mt-3">
                <label class="form-label" for="categoriaApiInput2" style="color:#00fff7;">Juegos API TiendaGiftVen (Slot 2 — opcional)</label>
                <select name="categoria_api_tiendagiftven_2" id="categoriaApiInput2" class="form-select<?= $gameApiExclusiveClass ?>" data-exclusive-group="create-game-api" data-exclusive-target="categoriaDiscordApiInput" data-exclusive-enabled="<?= $mixedApiUnionEnabled ? '0' : '1' ?>" style="background:#222c3a; color:#00fff7; border:1px solid #00fff7;">
                    <option value="">— Sin segundo slot —</option>
                    <?php foreach ($apiCategories as $apiCategory): ?>
                    <option value="<?= htmlspecialchars($apiCategory, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($apiCategory, ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text mt-2" style="color:#8be9fd;">Segunda categoría TiendaGiftVen (ej: Blood Strike 2.0).</div>
            </div>
            <div class="form-check mt-3">
                <label class="form-label" for="categoriaApiInput3" style="color:#00fff7;">Juegos API TiendaGiftVen (Slot 3 — opcional)</label>
                <select name="categoria_api_tiendagiftven_3" id="categoriaApiInput3" class="form-select<?= $gameApiExclusiveClass ?>" data-exclusive-group="create-game-api" data-exclusive-target="categoriaDiscordApiInput" data-exclusive-enabled="<?= $mixedApiUnionEnabled ? '0' : '1' ?>" style="background:#222c3a; color:#00fff7; border:1px solid #00fff7;">
                    <option value="">— Sin tercer slot —</option>
                    <?php foreach ($apiCategories as $apiCategory): ?>
                    <option value="<?= htmlspecialchars($apiCategory, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($apiCategory, ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text mt-2" style="color:#8be9fd;">Tercera categoría TiendaGiftVen opcional.</div>
            </div>
            <?php if ($discordApiEnabled): ?>
            <div class="form-check mt-3">
                <label class="form-label" for="categoriaDiscordApiInput" style="color:#00fff7;">Juegos API Discord (Slot 1)</label>
                <div class="d-flex gap-2 align-items-start flex-wrap">
                    <select name="categoria_api_discord" id="categoriaDiscordApiInput" class="form-select<?= $gameApiExclusiveClass ?> flex-grow-1" data-discord-games-select="1" data-exclusive-group="create-game-api" data-exclusive-target="categoriaApiInput" data-exclusive-enabled="<?= $mixedApiUnionEnabled ? '0' : '1' ?>" style="background:#222c3a; color:#00fff7; border:1px solid #00fff7; min-width:260px;">
                        <option value="">Proceso manual / sin API</option>
                        <?php foreach ($discordApiCommandOptions as $discordCommand): ?>
                            <?php $discordKey = (string) ($discordCommand['key'] ?? ''); ?>
                            <?php $discordLabel = trim((string) ($discordCommand['label'] ?? $discordKey)); ?>
                            <option value="<?= htmlspecialchars($discordKey, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($discordLabel, ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="button" class="btn btn-outline-info js-refresh-discord-games" style="border-color:#00fff7;color:#00fff7;white-space:nowrap;">Traer juegos</button>
                </div>
                <div class="form-text mt-2" style="color:#8be9fd;">Comando principal de Discord.</div>
            </div>
            <div class="form-check mt-3">
                <label class="form-label" for="categoriaDiscordApiInput2" style="color:#00fff7;">Juegos API Discord (Slot 2 — opcional)</label>
                <select name="categoria_api_discord_2" id="categoriaDiscordApiInput2" class="form-select<?= $gameApiExclusiveClass ?> flex-grow-1" data-exclusive-group="create-game-api" data-exclusive-target="categoriaApiInput" data-exclusive-enabled="<?= $mixedApiUnionEnabled ? '0' : '1' ?>" style="background:#222c3a; color:#00fff7; border:1px solid #00fff7;">
                    <option value="">— Sin segundo slot —</option>
                    <?php foreach ($discordApiCommandOptions as $discordCommand): ?>
                        <?php $discordKey = (string) ($discordCommand['key'] ?? ''); ?>
                        <?php $discordLabel = trim((string) ($discordCommand['label'] ?? $discordKey)); ?>
                        <option value="<?= htmlspecialchars($discordKey, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($discordLabel, ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text mt-2" style="color:#8be9fd;">Segundo comando Discord (ej: Roblox recarga).</div>
            </div>
            <div class="form-check mt-3">
                <label class="form-label" for="categoriaDiscordApiInput3" style="color:#00fff7;">Juegos API Discord (Slot 3 — opcional)</label>
                <select name="categoria_api_discord_3" id="categoriaDiscordApiInput3" class="form-select<?= $gameApiExclusiveClass ?> flex-grow-1" data-exclusive-group="create-game-api" data-exclusive-target="categoriaApiInput" data-exclusive-enabled="<?= $mixedApiUnionEnabled ? '0' : '1' ?>" style="background:#222c3a; color:#00fff7; border:1px solid #00fff7;">
                    <option value="">— Sin tercer slot —</option>
                    <?php foreach ($discordApiCommandOptions as $discordCommand): ?>
                        <?php $discordKey = (string) ($discordCommand['key'] ?? ''); ?>
                        <?php $discordLabel = trim((string) ($discordCommand['label'] ?? $discordKey)); ?>
                        <option value="<?= htmlspecialchars($discordKey, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($discordLabel, ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text mt-2" style="color:#8be9fd;">Tercer comando Discord opcional.</div>
            </div>
            <?php endif; ?>
            <div class="mt-3">
                <label class="form-label" style="color:#00fff7;">Margen de ganancia API (%)</label>
                <div class="input-group">
                    <input type="number" name="precio_markup_pct" step="0.01" min="0" max="10000" value="0" class="form-control" style="background:#222c3a;color:#00fff7;border:1px solid #00fff7;">
                    <span class="input-group-text" style="background:#222c3a;color:#00fff7;border:1px solid #00fff7;">%</span>
                </div>
                <div class="form-text mt-2" style="color:#8be9fd;">Porcentaje de ganancia sobre el precio de la API. Ej: 50 → precio API x1.5. 0 = precio directo de la API.</div>
            </div>
            <div class="form-check mt-3">
                <input type="checkbox" name="activo" class="form-check-input" id="activoCheck" checked>
                <label class="form-check-label" for="activoCheck" style="color:#00fff7;">Publicar este juego ahora</label>
            </div>
        </div>
        <div class="col-12">
            <label class="form-label" style="color:#00fff7;">Descripción</label>
            <textarea name="descripcion" placeholder="Descripción" required class="form-control" style="background:#222c3a; color:#00fff7; border:1px solid #00fff7;"></textarea>
        </div>
        <div class="col-md-6">
            <label class="form-label" style="color:#00fff7;">Imagen del juego</label>
            <input type="file" name="imagen" accept="image/*" class="form-control" style="background:#222c3a; color:#00fff7; border:1px solid #00fff7;" onchange="previewImagenJuego(event)">
            <div class="text-center mt-2">
                <img id="preview-juego-img" src="#" alt="Previsualización" style="display:none;max-width:180px;max-height:180px;border-radius:0.75rem;box-shadow:0 0 0.5rem #00fff7; border:2px solid #00fff7;" />
            </div>
        </div>
        <div class="col-md-6">
            <label class="form-label" style="color:#00fff7;">Imagen hero del juego</label>
            <input type="file" name="imagen_hero" accept="image/*" class="form-control" style="background:#222c3a; color:#00fff7; border:1px solid #00fff7;" onchange="previewImagenHeroJuego(event)">
            <div class="form-text mt-2" style="color:#8be9fd;">Si queda vacía, al entrar al juego se mostrará la imagen principal como hero.</div>
            <div class="text-center mt-2">
                <img id="preview-juego-hero-img" src="#" alt="Previsualización Hero" style="display:none;max-width:220px;max-height:140px;border-radius:0.75rem;box-shadow:0 0 0.5rem #00fff7; border:2px solid #00fff7;object-fit:cover;" />
            </div>
        </div>
        <div class="col-md-6">
            <label class="form-label" style="color:#00fff7;">Imagen común para paquetes</label>
            <input type="file" name="imagen_paquete" accept="image/*" class="form-control" style="background:#222c3a; color:#00fff7; border:1px solid #00fff7;" onchange="previewImagenPaqueteJuego(event)">
            <div class="text-center mt-2">
                <img id="preview-juego-img-paquete" src="#" alt="Previsualización Paquete" style="display:none;max-width:120px;max-height:120px;border-radius:0.75rem;box-shadow:0 0 0.5rem #00fff7; border:2px solid #00fff7;" />
            </div>
        </div>
        <div class="col-md-6">
            <label class="form-label" style="color:#00fff7;">Imagen para barra de categorías (header)</label>
            <input type="file" name="imagen_catbar" accept="image/*" class="form-control" style="background:#222c3a; color:#00fff7; border:1px solid #00fff7;" onchange="previewImagenCatbarJuego(event)">
            <div class="form-text mt-2" style="color:#8be9fd;">Si queda vacía, en la barra del header se mostrará la imagen principal del juego.</div>
            <div class="text-center mt-2">
                <img id="preview-juego-img-catbar" src="#" alt="Previsualización Barra" style="display:none;width:72px;height:72px;object-fit:cover;border-radius:50%;box-shadow:0 0 0.5rem #00fff7; border:2px solid #00fff7;" />
            </div>
        </div>
        <div class="col-md-6">
            <label class="form-label" style="color:#00fff7;">Moneda fija</label>
            <select name="moneda_fija_id" class="form-select" style="background:#222c3a; color:#00fff7; border:1px solid #00fff7;">
                <option value="">Moneda variable (usuario elige)</option>
                <?php foreach ($monedas as $m): ?>
                <option value="<?= $m['id'] ?>">Solo <?= htmlspecialchars($m['nombre']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-6">
            <label class="form-label" style="color:#00fff7;">Seleccionar características existentes</label>
            <select name="caracteristicas_select[]" multiple class="form-select" size="3" style="background:#222c3a; color:#00fff7; border:1px solid #00fff7;">
                <?php foreach ($caracteristicas_unicas as $car): ?>
                    <option value="<?= htmlspecialchars($car) ?>" style="background:#222c3a; color:#00fff7;"><?= htmlspecialchars($car) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-12">
            <label class="form-label" style="color:#00fff7;">Nuevas características</label>
            <div id="caracteristicas" class="mb-2">
                <input type="text" name="caracteristicas[]" placeholder="Nueva característica" class="form-control mb-2" style="background:#222c3a; color:#00fff7; border:1px solid #00fff7;">
            </div>
            <button type="button" onclick="addCarField()" class="btn btn-outline-info btn-sm" style="border-color:#00fff7; color:#00fff7;">Agregar nueva característica</button>
        </div>
        <!-- STICKER / BADGE -->
        <div class="col-12">
            <div class="p-3" style="border:1px solid #7b2fff;border-radius:8px;background:#130828;">
                <div class="mb-2 fw-bold" style="color:#c77dff;font-size:0.9rem;letter-spacing:0.04em;">STICKER / BADGE</div>
                <div class="row g-2 mb-2">
                    <div class="col-sm-6">
                        <label class="form-label" style="color:#c77dff;font-size:0.85rem;">Icono predefinido</label>
                        <select name="sticker_icono_select" class="form-select" style="background:#1a0a30;color:#c77dff;border:1px solid #7b2fff;font-size:0.92rem;">
                            <option value="">— Sin icono —</option>
                            <?php foreach (game_sticker_icon_symbols() as $symKey => $symEmoji): ?>
                                <option value="<?= htmlspecialchars($symKey, ENT_QUOTES, 'UTF-8') ?>">
                                    <?= $symEmoji ?> <?= htmlspecialchars(ucwords(str_replace('_', ' ', $symKey)), ENT_QUOTES, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label" style="color:#c77dff;font-size:0.85rem;">Emoji propio <span style="color:#8be9fd;font-weight:400;">(sobreescribe el predefinido)</span></label>
                        <input type="text" name="sticker_icono_custom" class="form-control" style="background:#1a0a30;color:#c77dff;border:1px solid #7b2fff;" placeholder="ej: 🔥 💎 ⚡">
                    </div>
                </div>
                <div class="row g-2 mb-2">
                    <div class="col-sm-8">
                        <label class="form-label" style="color:#c77dff;font-size:0.85rem;">Texto del sticker</label>
                        <input type="text" name="sticker_texto" class="form-control" style="background:#1a0a30;color:#c77dff;border:1px solid #7b2fff;" placeholder="Más vendido, Oferta, Nuevo…" maxlength="80">
                    </div>
                    <div class="col-sm-4">
                        <label class="form-label" style="color:#c77dff;font-size:0.85rem;">Color de fondo</label>
                        <input type="color" name="sticker_color_fondo" value="#7b2fff" class="form-control form-control-color w-100" style="background:#1a0a30;border:1px solid #7b2fff;height:38px;padding:2px 4px;">
                    </div>
                </div>
                <div>
                    <label class="form-label" style="color:#c77dff;font-size:0.85rem;">Imagen del sticker <span style="color:#8be9fd;font-weight:400;">PNG/WebP con transparencia · max 2 MB</span></label>
                    <input type="file" name="sticker_imagen" accept="image/*" class="form-control" style="background:#1a0a30;color:#c77dff;border:1px solid #7b2fff;">
                </div>
            </div>
        </div>
        <!-- BADGE INFERIOR -->
        <div class="col-12">
            <div class="p-3" style="border:1px solid #2f8fff;border-radius:8px;background:#081b30;">
                <div class="mb-2 fw-bold" style="color:#7dc2ff;font-size:0.9rem;letter-spacing:0.04em;">BADGE INFERIOR</div>
                <div class="row g-2 mb-2">
                    <div class="col-sm-6">
                        <label class="form-label" style="color:#7dc2ff;font-size:0.85rem;">Icono predefinido</label>
                        <select name="badge2_icono_select" class="form-select" style="background:#0a2140;color:#7dc2ff;border:1px solid #2f8fff;font-size:0.92rem;">
                            <option value="">— Sin icono —</option>
                            <?php foreach (game_sticker_icon_symbols() as $symKey => $symEmoji): ?>
                                <option value="<?= htmlspecialchars($symKey, ENT_QUOTES, 'UTF-8') ?>">
                                    <?= $symEmoji ?> <?= htmlspecialchars(ucwords(str_replace('_', ' ', $symKey)), ENT_QUOTES, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label" style="color:#7dc2ff;font-size:0.85rem;">Emoji propio <span style="color:#8be9fd;font-weight:400;">(sobreescribe el predefinido, deja vacío para no usar emoji)</span></label>
                        <input type="text" name="badge2_icono_custom" class="form-control" style="background:#0a2140;color:#7dc2ff;border:1px solid #2f8fff;" placeholder="ej: 🔥 💎 ⚡">
                    </div>
                </div>
                <div class="row g-2 mb-2">
                    <div class="col-sm-8">
                        <label class="form-label" style="color:#7dc2ff;font-size:0.85rem;">Texto del badge</label>
                        <input type="text" name="badge2_texto" class="form-control" style="background:#0a2140;color:#7dc2ff;border:1px solid #2f8fff;" placeholder="Envío rápido, Exclusivo…" maxlength="80">
                    </div>
                    <div class="col-sm-4">
                        <label class="form-label" style="color:#7dc2ff;font-size:0.85rem;">Color de fondo</label>
                        <input type="color" name="badge2_color_fondo" value="#2f8fff" class="form-control form-control-color w-100" style="background:#0a2140;border:1px solid #2f8fff;height:38px;padding:2px 4px;">
                    </div>
                </div>
                <div>
                    <label class="form-label" style="color:#7dc2ff;font-size:0.85rem;">Imagen del badge <span style="color:#8be9fd;font-weight:400;">PNG/WebP con transparencia · max 2 MB</span></label>
                    <input type="file" name="badge2_imagen" accept="image/*" class="form-control" style="background:#0a2140;color:#7dc2ff;border:1px solid #2f8fff;">
                </div>
            </div>
        </div>
        <?php if ($allCategories !== []): ?>
        <div class="col-12">
            <label class="form-label" style="color:#00fff7;">Categorías del juego</label>
            <div class="d-flex flex-wrap gap-2 p-2 rounded-3" style="background:#0f1a28;border:1px solid #1e3a5f;min-height:2.5rem;">
                <?php foreach ($allCategories as $gcat): ?>
                <label class="d-flex align-items-center gap-2 px-2 py-1 rounded-2" style="cursor:pointer;background:#182030;border:1px solid #1e3a5f;user-select:none;">
                    <input type="checkbox" name="cat_ids[]" value="<?= (int) $gcat['id'] ?>" style="accent-color:#00fff7;width:1rem;height:1rem;">
                    <?php if ($gcat['icono'] !== ''): ?><span><?= htmlspecialchars($gcat['icono'], ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
                    <span style="color:#00fff7;font-size:0.9rem;"><?= htmlspecialchars($gcat['nombre'], ENT_QUOTES, 'UTF-8') ?></span>
                </label>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
        <div class="col-12">
            <button type="submit" class="btn btn-info w-100" style="background:#00fff7; color:#222; border:none; box-shadow:0 0 8px #00fff7;">Agregar juego</button>
        </div>
    </form>
    <h3 class="text-info mt-5 mb-3">Juegos existentes</h3>
    <div class="table-responsive d-none d-md-block">
        <table class="table align-middle" style="background:#181f2a; color:#00fff7; border-radius:12px;">
            <thead style="background:#181f2a; color:#00fff7; border-bottom:2px solid #00fff7;">
                <tr>
                    <th style="color:#00fff7; background:#181f2a;">Imagen</th>
                    <th style="color:#00fff7; background:#181f2a;">Nombre</th>
                    <th style="color:#00fff7; background:#181f2a;">Orden</th>
                    <th style="color:#00fff7; background:#181f2a;" title="Orden en la barra de categorías del header">Orden categoría</th>
                    <th style="color:#00fff7; background:#181f2a;">Activo</th>
                    <th style="color:#00fff7; background:#181f2a;">Imagen Paquete</th>
                    <th style="color:#00fff7; background:#181f2a;">Descripción</th>
                    <th style="color:#00fff7; background:#181f2a;">Moneda</th>
                    <th style="color:#00fff7; background:#181f2a;">Características</th>
                    <th style="color:#00fff7; background:#181f2a;">Acciones</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($juegos as $j): ?>
                <?php $totalPaquetes = $paquetesPorJuego[(int) $j['id']] ?? 0; ?>
                <tr style="background:#181f2a; color:#fff;">
                    <td style="background:#181f2a;">
                        <?php if (!empty($j['imagen'])): ?>
                            <img src="/<?= htmlspecialchars($j['imagen']) ?>" alt="img" class="rounded img-thumbnail" style="max-height:64px;max-width:64px; border:2px solid #00fff7; background:#222c3a;">
                        <?php else: ?>
                            <span class="fst-italic text-secondary">Sin imagen</span>
                        <?php endif; ?>
                    </td>
                    <td style="background:#181f2a; color:#00fff7;">
                        <div class="fw-semibold"><?= htmlspecialchars($j['nombre']) ?></div>
                        <div class="small" style="color:#b2f6ff;"><?= $totalPaquetes ?> paquete<?= $totalPaquetes === 1 ? '' : 's' ?> registrado<?= $totalPaquetes === 1 ? '' : 's' ?></div>
                        <?php if (!empty($j['categoria_api'])): ?>
                            <div class="small mt-1"><span style="display:inline-flex;align-items:center;gap:0.35rem;padding:0.2rem 0.55rem;border-radius:999px;border:1px solid rgba(52,211,153,0.7);background:rgba(16,185,129,0.12);color:#6ee7b7;font-weight:700;letter-spacing:0.04em;">API TiendaGiftVen: <?= htmlspecialchars((string) $j['categoria_api'], ENT_QUOTES, 'UTF-8') ?></span></div>
                        <?php endif; ?>
                        <?php if ($discordApiEnabled && !empty($j['categoria_api_discord'])): ?>
                            <div class="small mt-1"><span style="display:inline-flex;align-items:center;gap:0.35rem;padding:0.2rem 0.55rem;border-radius:999px;border:1px solid rgba(192,132,252,0.7);background:rgba(168,85,247,0.12);color:#e9d5ff;font-weight:700;letter-spacing:0.04em;">API Discord: <?= htmlspecialchars((string) $j['categoria_api_discord'], ENT_QUOTES, 'UTF-8') ?></span></div>
                        <?php endif; ?>
                        <?php foreach ($gameCategories[(int) $j['id']] ?? [] as $gc): ?>
                            <div class="small mt-1"><span style="display:inline-flex;align-items:center;gap:0.3rem;padding:0.15rem 0.5rem;border-radius:999px;border:1px solid <?= htmlspecialchars($gc['color'] ?: '#00fff7', ENT_QUOTES, 'UTF-8') ?>55;background:<?= htmlspecialchars($gc['color'] ?: '#00fff7', ENT_QUOTES, 'UTF-8') ?>18;color:<?= htmlspecialchars($gc['color'] ?: '#00fff7', ENT_QUOTES, 'UTF-8') ?>;font-weight:600;font-size:0.78rem;"><?= $gc['icono'] !== '' ? htmlspecialchars($gc['icono'], ENT_QUOTES, 'UTF-8') . ' ' : '' ?><?= htmlspecialchars($gc['nombre'], ENT_QUOTES, 'UTF-8') ?></span></div>
                        <?php endforeach; ?>
                    </td>
                    <td class="text-center" style="background:#181f2a;">
                        <form method="post" action="<?= htmlspecialchars($adminGamesUrl, ENT_QUOTES, 'UTF-8') ?>" class="d-inline-flex align-items-center gap-2 m-0 js-ajax-order-form">
                            <input type="hidden" name="ajax" value="1">
                            <input type="hidden" name="update_orden_juego" value="1">
                            <input type="hidden" name="juego_id" value="<?= (int) $j['id'] ?>">
                            <input type="number" name="orden" min="1" value="<?= max(1, (int) ($j['orden'] ?? 0)) ?>" class="form-control form-control-sm text-center js-ajax-order-input" style="width:84px;background:#222c3a;color:#00fff7;border:1px solid #00fff7;">
                        </form>
                    </td>
                    <td class="text-center" style="background:#181f2a;">
                        <form method="post" action="<?= htmlspecialchars($adminGamesUrl, ENT_QUOTES, 'UTF-8') ?>" class="d-inline-flex align-items-center gap-2 m-0 js-ajax-order-form">
                            <input type="hidden" name="ajax" value="1">
                            <input type="hidden" name="update_orden_catbar_juego" value="1">
                            <input type="hidden" name="juego_id" value="<?= (int) $j['id'] ?>">
                            <input type="number" name="orden" min="1" value="<?= (int) ($j['orden_catbar'] ?? 0) > 0 ? (int) $j['orden_catbar'] : '' ?>" placeholder="—" title="Orden en la barra de categorías del header" class="form-control form-control-sm text-center js-ajax-order-input" style="width:84px;background:#222c3a;color:#00fff7;border:1px solid #00fff7;">
                        </form>
                        </td>
                        <td class="text-center" style="background:#181f2a;">
                            <form method="get" action="<?= htmlspecialchars($adminGamesUrl, ENT_QUOTES, 'UTF-8') ?>" class="m-0 d-inline-block js-ajax-toggle-form">
                                <input type="hidden" name="ajax" value="1">
                                <input type="hidden" name="toggle_activo" value="<?= (int) $j['id'] ?>">
                                <div class="form-check form-switch d-inline-flex justify-content-center mb-0">
                                    <input class="form-check-input js-ajax-toggle-input" type="checkbox" <?= !isset($j['activo']) || !empty($j['activo']) ? 'checked' : '' ?> aria-label="Activar o desactivar juego <?= htmlspecialchars($j['nombre'], ENT_QUOTES, 'UTF-8') ?>">
                                </div>
                            </form>
                        </td>
                        <td style="background:#181f2a;">
                            <?php if (!empty($j['imagen_paquete'])): ?>
                                <img src="/<?= htmlspecialchars($j['imagen_paquete']) ?>" alt="imgpaq" class="rounded-lg" style="max-height:48px;max-width:48px; border:2px solid #00fff7; background:#222c3a;">
                            <?php else: ?>
                                <span class="italic text-slate-400">Sin imagen</span>
                            <?php endif; ?>
                        </td>
                        <td style="background:#181f2a; color:#fff; max-width:220px;overflow-x:auto;white-space:pre-line;"><?= nl2br(htmlspecialchars($j['descripcion'])) ?></td>
                        <td style="background:#181f2a; color:#00fff7;">
                            <?php 
                                if (!empty($j['moneda_fija_id'])) {
                                    $mon = $mysqli->query("SELECT nombre FROM monedas WHERE id=" . intval($j['moneda_fija_id']));
                                    $moneda = $mon && $mon->num_rows ? $mon->fetch_assoc()['nombre'] : 'Desconocida';
                                    echo htmlspecialchars($moneda);
                                } else {
                                    echo '<span class="italic text-slate-400">Variable</span>';
                                }
                            ?>
                        </td>
                        <td style="background:#181f2a; color:#00fff7;">
                            <?php 
                                $carRes = $mysqli->query("SELECT caracteristica FROM juego_caracteristicas WHERE juego_id=" . intval($j['id']));
                                $cars = [];
                                while ($row = $carRes->fetch_assoc()) $cars[] = $row['caracteristica'];
                                echo $cars ? htmlspecialchars(implode(', ', $cars)) : '<span class="italic text-slate-400">Ninguna</span>';
                            ?>
                        </td>
                        <td style="background:#181f2a;">
                            <a href="<?= htmlspecialchars($adminGamesUrl, ENT_QUOTES, 'UTF-8') ?>?editar=<?= $j['id'] ?>" style="color:#00fff7; text-decoration:underline; margin-right:1em;">Editar</a>
                            <a href="<?= htmlspecialchars($adminPackagesBaseUrl, ENT_QUOTES, 'UTF-8') ?>/<?= $j['id'] ?>" style="color:#00fff7; text-decoration:underline; margin-right:1em;">Paquetes</a>
                                                        <?php if ($gameEntryWindowEnabled): ?>
                                                            <a href="<?= htmlspecialchars($adminGameEntryWindowBaseUrl . '?game_id=' . (int) $j['id'], ENT_QUOTES, 'UTF-8') ?>" style="color:#facc15; text-decoration:underline; margin-right:1em;">Configurar Ventana Inicial</a>
                                                        <?php endif; ?>
                            <a href="<?= htmlspecialchars($adminGamesUrl, ENT_QUOTES, 'UTF-8') ?>?eliminar=<?= $j['id'] ?>" style="color:#ff0059; text-decoration:underline;" onclick="return confirm('¿Eliminar este juego y todos sus paquetes/características?')">Eliminar</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <!-- Mobile Cards -->
    <div class="d-md-none">
        <div class="row gy-4 mt-1 mb-2">
        <?php foreach ($juegos as $j): ?>
            <?php $totalPaquetes = $paquetesPorJuego[(int) $j['id']] ?? 0; ?>
            <div class="col-12">
                <div class="card neon-card p-3" style="background:#181f2a; border:2px solid #22d3ee; box-shadow:0 0 16px #22d3ee,0 0 4px #2dd4bf; color:#22d3ee; border-radius:16px;">
                    <div class="d-flex align-items-center mb-3">
                        <?php if (!empty($j['imagen'])): ?>
                            <img src="/<?= htmlspecialchars($j['imagen']) ?>" alt="img" class="rounded img-thumbnail me-3" style="max-height:120px;max-width:120px;box-shadow:0 0 8px #22d3ee; border:2px solid #22d3ee; background:#222c3a; object-fit:cover;">
                        <?php else: ?>
                            <span class="fst-italic text-secondary">Sin imagen</span>
                        <?php endif; ?>
                        <div>
                            <div class="fw-bold text-neon" style="font-size:1.1rem; color:#22d3ee;">
                                <?= htmlspecialchars($j['nombre']) ?>
                                <?php if (!empty($j['popular'])): ?>
                                    <span title="Popular" style="margin-left:0.35rem; color:#22d3ee; font-size:1.1rem;">★</span>
                                <?php endif; ?>
                            </div>
                            <div style="font-size:0.9rem; color:#b2f6ff;"><?= $totalPaquetes ?> paquete<?= $totalPaquetes === 1 ? '' : 's' ?> registrado<?= $totalPaquetes === 1 ? '' : 's' ?></div>
                            <?php if (!empty($j['categoria_api'])): ?>
                                <div class="mt-1"><span style="display:inline-flex;align-items:center;gap:0.35rem;padding:0.2rem 0.55rem;border-radius:999px;border:1px solid rgba(52,211,153,0.7);background:rgba(16,185,129,0.12);color:#6ee7b7;font-weight:700;font-size:0.78rem;letter-spacing:0.04em;">API TiendaGiftVen: <?= htmlspecialchars((string) $j['categoria_api'], ENT_QUOTES, 'UTF-8') ?></span></div>
                            <?php endif; ?>
                            <?php if ($discordApiEnabled && !empty($j['categoria_api_discord'])): ?>
                                <div class="mt-1"><span style="display:inline-flex;align-items:center;gap:0.35rem;padding:0.2rem 0.55rem;border-radius:999px;border:1px solid rgba(192,132,252,0.7);background:rgba(168,85,247,0.12);color:#e9d5ff;font-weight:700;font-size:0.78rem;letter-spacing:0.04em;">API Discord: <?= htmlspecialchars((string) $j['categoria_api_discord'], ENT_QUOTES, 'UTF-8') ?></span></div>
                            <?php endif; ?>
                            <?php foreach ($gameCategories[(int) $j['id']] ?? [] as $gc): ?>
                                <div class="mt-1"><span style="display:inline-flex;align-items:center;gap:0.3rem;padding:0.15rem 0.5rem;border-radius:999px;border:1px solid <?= htmlspecialchars($gc['color'] ?: '#00fff7', ENT_QUOTES, 'UTF-8') ?>55;background:<?= htmlspecialchars($gc['color'] ?: '#00fff7', ENT_QUOTES, 'UTF-8') ?>18;color:<?= htmlspecialchars($gc['color'] ?: '#00fff7', ENT_QUOTES, 'UTF-8') ?>;font-weight:600;font-size:0.78rem;"><?= $gc['icono'] !== '' ? htmlspecialchars($gc['icono'], ENT_QUOTES, 'UTF-8') . ' ' : '' ?><?= htmlspecialchars($gc['nombre'], ENT_QUOTES, 'UTF-8') ?></span></div>
                            <?php endforeach; ?>
                            <div style="font-size:0.85rem; color:#b2f6ff;">Orden: <?= max(1, (int) ($j['orden'] ?? 0)) ?></div>
                            <div class="text-muted" style="font-size:0.85rem; color:#b2f6ff;">ID: <?= $j['id'] ?></div>
                            <div class="mt-2">
                                <form method="get" action="<?= htmlspecialchars($adminGamesUrl, ENT_QUOTES, 'UTF-8') ?>" class="m-0 d-inline-flex align-items-center gap-2 js-ajax-toggle-form">
                                    <input type="hidden" name="ajax" value="1">
                                    <input type="hidden" name="toggle_activo" value="<?= (int) $j['id'] ?>">
                                    <div class="form-check form-switch mb-0">
                                        <input class="form-check-input js-ajax-toggle-input" type="checkbox" <?= !isset($j['activo']) || !empty($j['activo']) ? 'checked' : '' ?> aria-label="Activar o desactivar juego <?= htmlspecialchars($j['nombre'], ENT_QUOTES, 'UTF-8') ?>">
                                    </div>
                                    <span style="color:#b2f6ff;font-size:0.85rem;"><?= !isset($j['activo']) || !empty($j['activo']) ? 'Activo' : 'Inactivo' ?></span>
                                </form>
                            </div>
                        </div>
                    </div>
                    <div style="color:#fff;"><span class="fw-semibold">Descripción:</span> <?= nl2br(htmlspecialchars($j['descripcion'])) ?></div>
                    <div class="mt-2" style="color:#fff;"><span class="fw-semibold">Moneda:</span> <?php 
                        if (!empty($j['moneda_fija_id'])) {
                            $mon = $mysqli->query("SELECT nombre FROM monedas WHERE id=" . intval($j['moneda_fija_id']));
                            $moneda = $mon && $mon->num_rows ? $mon->fetch_assoc()['nombre'] : 'Desconocida';
                            echo '<span style="color:#b2f6ff;">' . htmlspecialchars($moneda) . '</span>';
                        } else {
                            echo '<span class="fst-italic" style="color:#b2f6ff;">Variable</span>';
                        }
                    ?></div>
                    <div class="mt-2" style="color:#fff;"><span class="fw-semibold">Características:</span> <?php 
                        $carRes = $mysqli->query("SELECT caracteristica FROM juego_caracteristicas WHERE juego_id=" . intval($j['id']));
                        $cars = [];
                        while ($row = $carRes->fetch_assoc()) $cars[] = $row['caracteristica'];
                        echo $cars ? '<span style="color:#b2f6ff;">' . htmlspecialchars(implode(', ', $cars)) . '</span>' : '<span class="fst-italic" style="color:#b2f6ff;">Ninguna</span>';
                    ?></div>
                    <form method="post" action="<?= htmlspecialchars($adminGamesUrl, ENT_QUOTES, 'UTF-8') ?>" class="mt-3 d-flex align-items-center gap-2 flex-wrap js-ajax-order-form">
                        <input type="hidden" name="ajax" value="1">
                        <input type="hidden" name="update_orden_juego" value="1">
                        <input type="hidden" name="juego_id" value="<?= (int) $j['id'] ?>">
                        <label class="small" style="color:#b2f6ff;">Orden</label>
                        <input type="number" name="orden" min="1" value="<?= max(1, (int) ($j['orden'] ?? 0)) ?>" class="form-control form-control-sm js-ajax-order-input" style="width:96px;background:#222c3a;color:#22d3ee;border:1px solid #22d3ee;">
                    </form>
                    <form method="post" action="<?= htmlspecialchars($adminGamesUrl, ENT_QUOTES, 'UTF-8') ?>" class="mt-2 d-flex align-items-center gap-2 flex-wrap js-ajax-order-form">
                        <input type="hidden" name="ajax" value="1">
                        <input type="hidden" name="update_orden_catbar_juego" value="1">
                        <input type="hidden" name="juego_id" value="<?= (int) $j['id'] ?>">
                        <label class="small" style="color:#b2f6ff;">Orden categoría</label>
                        <input type="number" name="orden" min="1" value="<?= (int) ($j['orden_catbar'] ?? 0) > 0 ? (int) $j['orden_catbar'] : '' ?>" placeholder="—" title="Orden en la barra de categorías del header" class="form-control form-control-sm js-ajax-order-input" style="width:96px;background:#222c3a;color:#22d3ee;border:1px solid #22d3ee;">
                    </form>
                    <div class="mt-3 d-flex gap-3 flex-wrap">
                        <a href="/admin/juegos?editar=<?= $j['id'] ?>" style="color:#22d3ee; text-decoration:underline; font-weight:bold;">Editar</a>
                        <a href="/admin/paquetes/<?= $j['id'] ?>" style="color:#22d3ee; text-decoration:underline; font-weight:bold;">Paquetes</a>
                                                <?php if ($gameEntryWindowEnabled): ?>
                                                    <a href="<?= htmlspecialchars($adminGameEntryWindowBaseUrl . '?game_id=' . (int) $j['id'], ENT_QUOTES, 'UTF-8') ?>" style="color:#facc15; text-decoration:underline; font-weight:bold;">Configurar Ventana Inicial</a>
                                                <?php endif; ?>
                        <a href="/admin/juegos?eliminar=<?= $j['id'] ?>" style="color:#ff0059; text-decoration:underline; font-weight:bold;" onclick="return confirm('¿Eliminar este juego y todos sus paquetes/características?')">Eliminar</a>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
        </div>
    </div>
    <?php
    // Procesar eliminación de juego
    if (isset($_GET['eliminar'])) {
        $del_id = intval($_GET['eliminar']);
        $stmt = $mysqli->prepare("DELETE FROM juegos WHERE id=?");
        $stmt->bind_param('i', $del_id);
        $stmt->execute();
        header('Location: /admin/juegos');
        exit;
    }
    ?>
    <?php
    // Formulario de edición de cabecera de juego
    if (isset($_GET['editar'])) {
            $edit_id = intval($_GET['editar']);
            $res_edit = $mysqli->prepare("SELECT * FROM juegos WHERE id=?");
            $res_edit->bind_param('i', $edit_id);
            $res_edit->execute();
            $juego_edit = $res_edit->get_result()->fetch_assoc();
            if ($juego_edit):
    ?>
    <div class="fixed inset-0 bg-black/60 flex items-center justify-center z-50">
        <form method="post" action="/admin/juegos" enctype="multipart/form-data" class="bg-slate-900 rounded-xl p-8 max-w-lg w-full relative" style="box-shadow:0 0 2rem #22d3ee33;">
            <h3 class="text-xl font-bold mb-4 text-cyan-300">Editar juego</h3>
            <input type="hidden" name="edit_juego_id" value="<?= $juego_edit['id'] ?>">
            <input type="text" name="edit_nombre" value="<?= htmlspecialchars($juego_edit['nombre']) ?>" required class="w-full rounded-lg px-3 py-2 bg-slate-800 text-white mb-2">
            <textarea name="edit_descripcion" required class="w-full rounded-lg px-3 py-2 bg-slate-800 text-white mb-2"><?= htmlspecialchars($juego_edit['descripcion']) ?></textarea>
            <label class="block text-slate-300 font-medium mb-1">Moneda fija o variable:</label>
            <select name="edit_moneda_fija_id" class="w-full rounded-lg px-3 py-2 bg-slate-800 text-white mb-2">
                <option value="" <?= empty($juego_edit['moneda_fija_id']) ? 'selected' : '' ?>>Moneda variable (usuario elige)</option>
                <?php foreach ($monedas as $m): ?>
                <option value="<?= $m['id'] ?>" <?= (!empty($juego_edit['moneda_fija_id']) && $juego_edit['moneda_fija_id'] == $m['id']) ? 'selected' : '' ?>>Solo <?= htmlspecialchars($m['nombre']) ?></option>
                <?php endforeach; ?>
            </select>
            <label class="inline-flex items-center mb-2">
                <input type="checkbox" name="edit_popular" class="form-checkbox h-5 w-5 text-emerald-500" <?= !empty($juego_edit['popular']) ? 'checked' : '' ?>>
                <span class="ml-2 text-slate-300">Marcar como popular</span>
            </label>
            <label class="block text-slate-300 font-medium mb-1">Juegos API TiendaGiftVen (Slot 1):</label>
            <select name="edit_categoria_api_tiendagiftven" id="editCategoriaApiInputLegacy" class="w-full rounded-lg px-3 py-2 bg-slate-800 text-white mb-2<?= $gameApiExclusiveClass ?>" data-exclusive-group="edit-game-api-legacy" data-exclusive-target="editDiscordApiInputLegacy" data-exclusive-enabled="<?= $mixedApiUnionEnabled ? '0' : '1' ?>">
                <option value="">Proceso manual / sin API</option>
                <?php foreach ($apiCategories as $apiCategory): ?>
                <option value="<?= htmlspecialchars($apiCategory, ENT_QUOTES, 'UTF-8') ?>" <?= (string) ($juego_edit['categoria_api'] ?? '') === (string) $apiCategory ? 'selected' : '' ?>><?= htmlspecialchars($apiCategory, ENT_QUOTES, 'UTF-8') ?></option>
                <?php endforeach; ?>
            </select>
            <label class="block text-slate-300 font-medium mb-1">Juegos API TiendaGiftVen (Slot 2 — opcional):</label>
            <select name="edit_categoria_api_tiendagiftven_2" class="w-full rounded-lg px-3 py-2 bg-slate-800 text-white mb-2<?= $gameApiExclusiveClass ?>" data-exclusive-group="edit-game-api-legacy" data-exclusive-target="editDiscordApiInputLegacy" data-exclusive-enabled="<?= $mixedApiUnionEnabled ? '0' : '1' ?>">
                <option value="">— Sin segundo slot —</option>
                <?php foreach ($apiCategories as $apiCategory): ?>
                <option value="<?= htmlspecialchars($apiCategory, ENT_QUOTES, 'UTF-8') ?>" <?= (string) ($juego_edit['categoria_api_2'] ?? '') === (string) $apiCategory ? 'selected' : '' ?>><?= htmlspecialchars($apiCategory, ENT_QUOTES, 'UTF-8') ?></option>
                <?php endforeach; ?>
            </select>
            <div class="text-xs text-slate-400 mb-2">Slot 2: segunda categoría TiendaGiftVen opcional.</div>
            <label class="block text-slate-300 font-medium mb-1">Juegos API TiendaGiftVen (Slot 3 — opcional):</label>
            <select name="edit_categoria_api_tiendagiftven_3" class="w-full rounded-lg px-3 py-2 bg-slate-800 text-white mb-2<?= $gameApiExclusiveClass ?>" data-exclusive-group="edit-game-api-legacy" data-exclusive-target="editDiscordApiInputLegacy" data-exclusive-enabled="<?= $mixedApiUnionEnabled ? '0' : '1' ?>">
                <option value="">— Sin tercer slot —</option>
                <?php foreach ($apiCategories as $apiCategory): ?>
                <option value="<?= htmlspecialchars($apiCategory, ENT_QUOTES, 'UTF-8') ?>" <?= (string) ($juego_edit['categoria_api_3'] ?? '') === (string) $apiCategory ? 'selected' : '' ?>><?= htmlspecialchars($apiCategory, ENT_QUOTES, 'UTF-8') ?></option>
                <?php endforeach; ?>
            </select>
            <div class="text-xs text-slate-400 mb-2">Slot 3: tercera categoría TiendaGiftVen opcional.</div>
            <?php if ($discordApiEnabled): ?>
            <label class="block text-slate-300 font-medium mb-1">Juegos API Discord (Slot 1):</label>
            <div class="flex gap-2 items-start flex-wrap mb-2">
                <select name="edit_categoria_api_discord" id="editDiscordApiInputLegacy" class="w-full rounded-lg px-3 py-2 bg-slate-800 text-white<?= $gameApiExclusiveClass ?>" data-discord-games-select="1" data-exclusive-group="edit-game-api-legacy" data-exclusive-target="editCategoriaApiInputLegacy" data-exclusive-enabled="<?= $mixedApiUnionEnabled ? '0' : '1' ?>" style="flex:1 1 260px;">
                    <option value="">Proceso manual / sin API</option>
                    <?php foreach ($discordApiCommandOptions as $discordCommand): ?>
                    <?php $discordKey = (string) ($discordCommand['key'] ?? ''); ?>
                    <?php $discordLabel = trim((string) ($discordCommand['label'] ?? $discordKey)); ?>
                    <option value="<?= htmlspecialchars($discordKey, ENT_QUOTES, 'UTF-8') ?>" <?= (string) ($juego_edit['categoria_api_discord'] ?? '') === $discordKey ? 'selected' : '' ?>><?= htmlspecialchars($discordLabel, ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="button" class="bg-slate-800 hover:bg-slate-700 text-cyan-300 border border-cyan-400 px-3 py-2 rounded-lg js-refresh-discord-games" style="white-space:nowrap;">Traer juegos</button>
            </div>
            <label class="block text-slate-300 font-medium mb-1">Juegos API Discord (Slot 2 — opcional):</label>
            <select name="edit_categoria_api_discord_2" class="w-full rounded-lg px-3 py-2 bg-slate-800 text-white mb-2<?= $gameApiExclusiveClass ?>" data-exclusive-group="edit-game-api-legacy" data-exclusive-target="editCategoriaApiInputLegacy" data-exclusive-enabled="<?= $mixedApiUnionEnabled ? '0' : '1' ?>">
                <option value="">— Sin segundo slot —</option>
                <?php foreach ($discordApiCommandOptions as $discordCommand): ?>
                <?php $discordKey = (string) ($discordCommand['key'] ?? ''); ?>
                <?php $discordLabel = trim((string) ($discordCommand['label'] ?? $discordKey)); ?>
                <option value="<?= htmlspecialchars($discordKey, ENT_QUOTES, 'UTF-8') ?>" <?= (string) ($juego_edit['categoria_api_discord_2'] ?? '') === $discordKey ? 'selected' : '' ?>><?= htmlspecialchars($discordLabel, ENT_QUOTES, 'UTF-8') ?></option>
                <?php endforeach; ?>
            </select>
            <div class="text-xs text-slate-400 mb-2">Slot 2: segundo comando Discord opcional.</div>
            <label class="block text-slate-300 font-medium mb-1">Juegos API Discord (Slot 3 — opcional):</label>
            <select name="edit_categoria_api_discord_3" class="w-full rounded-lg px-3 py-2 bg-slate-800 text-white mb-2<?= $gameApiExclusiveClass ?>" data-exclusive-group="edit-game-api-legacy" data-exclusive-target="editCategoriaApiInputLegacy" data-exclusive-enabled="<?= $mixedApiUnionEnabled ? '0' : '1' ?>">
                <option value="">— Sin tercer slot —</option>
                <?php foreach ($discordApiCommandOptions as $discordCommand): ?>
                <?php $discordKey = (string) ($discordCommand['key'] ?? ''); ?>
                <?php $discordLabel = trim((string) ($discordCommand['label'] ?? $discordKey)); ?>
                <option value="<?= htmlspecialchars($discordKey, ENT_QUOTES, 'UTF-8') ?>" <?= (string) ($juego_edit['categoria_api_discord_3'] ?? '') === $discordKey ? 'selected' : '' ?>><?= htmlspecialchars($discordLabel, ENT_QUOTES, 'UTF-8') ?></option>
                <?php endforeach; ?>
            </select>
            <div class="text-xs text-slate-400 mb-2">Slot 3: tercer comando Discord opcional.</div>
            <?php endif; ?>
            <label class="block text-slate-300 font-medium mb-1">Margen de ganancia API (%):</label>
            <div class="flex items-center mb-2">
                <input type="number" name="edit_precio_markup_pct" step="0.01" min="0" max="10000" value="<?= htmlspecialchars(number_format((float) ($juego_edit['precio_markup_pct'] ?? 0), 2, '.', ''), ENT_QUOTES, 'UTF-8') ?>" class="w-full rounded-lg px-3 py-2 bg-slate-800 text-white" style="border:1px solid #22d3ee;">
                <span class="ml-2 text-slate-300 whitespace-nowrap">%</span>
            </div>
            <div class="text-xs text-slate-400 mb-2">Ej: 50 → precio API x1.5. Se aplica en tiempo real al cambiar el proveedor sus precios.</div>
            <label class="block text-slate-300 mb-1">Imagen actual:</label>
            <?php if ($juego_edit['imagen']): ?>
                <img src="/<?= htmlspecialchars($juego_edit['imagen']) ?>" alt="Imagen actual" class="mb-2 rounded-lg max-h-32">
            <?php endif; ?>
            <input type="file" name="edit_imagen" accept="image/*" class="w-full rounded-lg px-3 py-2 bg-slate-800 text-white mb-2" onchange="previewEditJuegoImg(event)">
            <div class="flex justify-center my-2">
                <img id="preview-edit-juego-img" src="#" alt="Previsualización" style="display:none;max-width:180px;max-height:180px;border-radius:0.75rem;box-shadow:0 0 0.5rem #22d3ee55;" />
            </div>
            <label class="block text-slate-300 mb-1">Imagen hero del juego:</label>
            <?php if (!empty($juego_edit['imagen_hero'])): ?>
                <img src="/<?= htmlspecialchars($juego_edit['imagen_hero']) ?>" alt="Imagen Hero" class="mb-2 rounded-lg" style="max-height:120px;max-width:220px;object-fit:cover;">
            <?php else: ?>
                <div class="text-xs text-slate-400 mb-2">Si no tiene hero, se usará la imagen principal del juego.</div>
            <?php endif; ?>
            <input type="file" name="edit_imagen_hero" accept="image/*" class="w-full rounded-lg px-3 py-2 bg-slate-800 text-white mb-2" onchange="previewEditHeroJuegoImg(event)">
            <label class="inline-flex items-center mb-2">
                <input type="checkbox" name="remove_edit_imagen_hero" class="form-checkbox h-5 w-5 text-emerald-500">
                <span class="ml-2 text-slate-300">Usar la imagen principal como hero</span>
            </label>
            <div class="flex justify-center my-2">
                <img id="preview-edit-juego-hero-img" src="#" alt="Previsualización Hero" style="display:none;max-width:220px;max-height:140px;border-radius:0.75rem;box-shadow:0 0 0.5rem #22d3ee55;object-fit:cover;" />
            </div>
            <label class="block text-slate-300 mb-1">Imagen común para paquetes:</label>
            <input type="file" name="edit_imagen_paquete" accept="image/*" class="w-full rounded-lg px-3 py-2 bg-slate-800 text-white mb-2" onchange="previewEditImagenPaqueteJuego(event)">
            <?php if ($juego_edit['imagen_paquete']): ?>
                <img src="/<?= htmlspecialchars($juego_edit['imagen_paquete']) ?>" alt="Imagen Paquete" class="mb-2 rounded-lg max-h-24">
            <?php endif; ?>
            <div class="flex justify-center my-2">
                <img id="preview-edit-juego-img-paquete" src="#" alt="Previsualización Paquete" style="display:none;max-width:120px;max-height:120px;border-radius:0.75rem;box-shadow:0 0 0.5rem #22d3ee55;" />
            </div>
            <button type="submit" name="edit_juego_submit" class="bg-emerald-600 hover:bg-emerald-500 text-white px-4 py-2 rounded-lg w-full">Guardar cambios</button>
            <script>
            function previewImagenPaqueteJuego(event) {
                const input = event.target;
                const img = document.getElementById('preview-juego-img-paquete');
                if (input.files && input.files[0]) {
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        img.src = e.target.result;
                        img.style.display = 'block';
                    };
                    reader.readAsDataURL(input.files[0]);
                } else {
                    img.src = '#';
                    img.style.display = 'none';
                }
            }
            function previewEditImagenPaqueteJuego(event) {
                const input = event.target;
                const img = document.getElementById('preview-edit-juego-img-paquete');
                if (input.files && input.files[0]) {
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        img.src = e.target.result;
                        img.style.display = 'block';
                    };
                    reader.readAsDataURL(input.files[0]);
                } else {
                    img.src = '#';
                    img.style.display = 'none';
                }
            }
            </script>
            <a href="/admin/juegos" class="absolute top-2 right-4 text-cyan-300 hover:underline text-lg">&times;</a>
        </form>
    </div>
    <script>
    function previewEditJuegoImg(event) {
            const input = event.target;
            const img = document.getElementById('preview-edit-juego-img');
            if (input.files && input.files[0]) {
                    const reader = new FileReader();
                    reader.onload = function(e) {
                            img.src = e.target.result;
                            img.style.display = 'block';
                    };
                    reader.readAsDataURL(input.files[0]);
            } else {
                    img.src = '#';
                    img.style.display = 'none';
            }
    }
    </script>
    <?php endif; } 
    // Procesar edición de cabecera de juego
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_juego_submit'], $_POST['edit_juego_id'], $_POST['edit_nombre'], $_POST['edit_descripcion'])) {
        $edit_id = intval($_POST['edit_juego_id']);
        $edit_nombre = trim($_POST['edit_nombre']);
        $edit_descripcion = trim($_POST['edit_descripcion']);
        $edit_imagen = admin_game_store_upload($_FILES['edit_imagen'] ?? [], 'juego_');
        if ($edit_imagen) {
            $stmt = $mysqli->prepare("UPDATE juegos SET nombre=?, descripcion=?, imagen=? WHERE id=?");
            $stmt->bind_param('sssi', $edit_nombre, $edit_descripcion, $edit_imagen, $edit_id);
        } else {
            $stmt = $mysqli->prepare("UPDATE juegos SET nombre=?, descripcion=? WHERE id=?");
            $stmt->bind_param('ssi', $edit_nombre, $edit_descripcion, $edit_id);
        }
        $stmt->execute();
        header('Location: /admin/juegos');
        exit;
    }
    ?>
</main>
<script>
function addCarField() {
    var cont = document.getElementById('caracteristicas');
    var input = document.createElement('input');
    input.type = 'text';
    input.name = 'caracteristicas[]';
    input.placeholder = 'Característica';
    input.className = 'w-full rounded-lg px-3 py-2 bg-slate-800 text-white placeholder-slate-400 mt-2';
    cont.appendChild(input);
}
function previewImagenJuego(event) {
    const input = event.target;
    const img = document.getElementById('preview-juego-img');
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
            img.src = e.target.result;
            img.style.display = 'block';
        };
        reader.readAsDataURL(input.files[0]);
    } else {
        img.src = '#';
        img.style.display = 'none';
    }
}

function previewImagenHeroJuego(event) {
    const input = event.target;
    const img = document.getElementById('preview-juego-hero-img');
    if (!img) {
        return;
    }
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
            img.src = e.target.result;
            img.style.display = 'block';
        };
        reader.readAsDataURL(input.files[0]);
    } else {
        img.src = '#';
        img.style.display = 'none';
    }
}

function previewImagenCatbarJuego(event) {
    const input = event.target;
    const img = document.getElementById('preview-juego-img-catbar');
    if (!img) {
        return;
    }
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
            img.src = e.target.result;
            img.style.display = 'inline-block';
        };
        reader.readAsDataURL(input.files[0]);
    } else {
        img.src = '#';
        img.style.display = 'none';
    }
}

function previewEditHeroJuegoImg(event) {
    const input = event.target;
    const img = document.getElementById('preview-edit-juego-hero-img');
    if (!img) {
        return;
    }
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
            img.src = e.target.result;
            img.style.display = 'block';
        };
        reader.readAsDataURL(input.files[0]);
    } else {
        img.src = '#';
        img.style.display = 'none';
    }
}

async function submitAjaxAdminForm(form, requestData = null) {
    const method = (form.method || 'POST').toUpperCase();
    const formData = requestData instanceof FormData ? requestData : new FormData(form);
    const headers = {
        'X-Requested-With': 'XMLHttpRequest',
        'Accept': 'application/json, text/plain, */*'
    };
    let response;
    if (method === 'GET') {
        const params = new URLSearchParams(formData);
        const separator = (form.action || window.location.href).includes('?') ? '&' : '?';
        response = await fetch((form.action || window.location.href) + separator + params.toString(), {
            method,
            headers,
            cache: 'no-store'
        });
    } else {
        response = await fetch(form.action || window.location.href, {
            method,
            headers,
            body: formData
        });
    }
    const payload = await response.json().catch(() => null);
    if (!response.ok || !payload || payload.ok !== true) {
        throw new Error(payload && payload.message ? payload.message : 'No se pudo guardar el cambio.');
    }
    return payload;
}

document.querySelectorAll('.js-ajax-toggle-form').forEach((form) => {
    const input = form.querySelector('.js-ajax-toggle-input');
    if (!input) {
        return;
    }
    input.addEventListener('change', async () => {
        if (input.dataset.busy === '1') {
            return;
        }

        const requestData = new FormData(form);
        input.dataset.busy = '1';
        input.disabled = true;
        try {
            await submitAjaxAdminForm(form, requestData);
        } catch (error) {
            input.checked = !input.checked;
            window.alert(error.message);
        } finally {
            input.disabled = false;
            input.dataset.busy = '0';
        }
    });
});

document.querySelectorAll('.js-ajax-order-form').forEach((form) => {
    const input = form.querySelector('.js-ajax-order-input');
    if (!input) {
        return;
    }
    input.dataset.lastValue = input.value;
    input.addEventListener('change', async () => {
        const normalized = String(Math.max(1, parseInt(input.value || '1', 10) || 1));
        if (normalized === input.dataset.lastValue) {
            input.value = normalized;
            return;
        }
        input.value = normalized;
        const requestData = new FormData(form);
        input.readOnly = true;
        try {
            const payload = await submitAjaxAdminForm(form, requestData);
            input.dataset.lastValue = String(payload.orden || normalized);
            input.value = input.dataset.lastValue;
        } catch (error) {
            input.value = input.dataset.lastValue || '1';
            window.alert(error.message);
        } finally {
            input.readOnly = false;
        }
    });
});

document.querySelectorAll('.js-exclusive-api-select').forEach((select) => {
    select.addEventListener('change', () => {
        if (String(select.dataset.exclusiveEnabled || '1') !== '1') {
            return;
        }

        const selectedValue = String(select.value || '').trim();
        if (selectedValue === '') {
            return;
        }

        const targetId = String(select.dataset.exclusiveTarget || '').trim();
        if (!targetId) {
            return;
        }

        const other = document.getElementById(targetId);
        if (other) {
            other.value = '';
        }
    });
});

async function fetchDiscordGameOptions() {
    const response = await fetch('<?= htmlspecialchars($adminGamesUrl, ENT_QUOTES, 'UTF-8') ?>?ajax=1&load_discord_games=1', {
        headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'application/json, text/plain, */*'
        },
        cache: 'no-store'
    });

    const payload = await response.json().catch(() => null);
    if (!response.ok || !payload || payload.ok !== true || !Array.isArray(payload.commands)) {
        throw new Error(payload && payload.message ? payload.message : 'No se pudieron traer los juegos de API Discord.');
    }

    return payload.commands;
}

function populateDiscordGameSelect(select, commands) {
    if (!select) {
        return;
    }

    const currentValue = String(select.value || '');
    const fragment = document.createDocumentFragment();
    const manualOption = document.createElement('option');
    manualOption.value = '';
    manualOption.textContent = 'Proceso manual / sin API';
    fragment.appendChild(manualOption);

    let hasCurrentValue = currentValue === '';
    commands.forEach((command) => {
        const key = String((command && command.key) || '').trim();
        if (key === '') {
            return;
        }

        const option = document.createElement('option');
        option.value = key;
        option.textContent = String((command && command.label) || key).trim() || key;
        if (key === currentValue) {
            hasCurrentValue = true;
        }
        fragment.appendChild(option);
    });

    select.innerHTML = '';
    select.appendChild(fragment);
    select.value = hasCurrentValue ? currentValue : '';
}

document.querySelectorAll('.js-refresh-discord-games').forEach((button) => {
    button.addEventListener('click', async () => {
        if (button.dataset.loading === '1') {
            return;
        }

        const originalText = button.textContent;
        button.dataset.loading = '1';
        button.disabled = true;
        button.textContent = 'Trayendo...';

        try {
            const commands = await fetchDiscordGameOptions();
            document.querySelectorAll('[data-discord-games-select="1"]').forEach((select) => {
                populateDiscordGameSelect(select, commands);
            });
            button.textContent = 'Actualizado';
            window.setTimeout(() => {
                button.textContent = originalText;
            }, 1200);
        } catch (error) {
            button.textContent = originalText;
            window.alert(error.message || 'No se pudieron traer los juegos de API Discord.');
        } finally {
            button.disabled = false;
            button.dataset.loading = '0';
        }
    });
});

// ═══ CATEGORÍAS DE JUEGOS ════════════════════════════════════════════════
(function () {
    const CATS_URL = '<?= htmlspecialchars(app_path('/admin/juego-categorias'), ENT_QUOTES, 'UTF-8') ?>';

    async function catsFetch(action, data) {
        const fd = new FormData();
        fd.append('action', action);
        if (data) {
            for (const [k, v] of Object.entries(data)) {
                if (v == null) continue;
                if (Array.isArray(v)) {
                    v.forEach(item => fd.append(k + '[]', item instanceof File ? item : String(item)));
                } else if (v instanceof File) {
                    fd.append(k, v);
                } else {
                    fd.append(k, String(v));
                }
            }
        }
        const resp = await fetch(CATS_URL, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: fd
        });
        const json = await resp.json().catch(() => null);
        if (!resp.ok || !json || !json.ok) {
            throw new Error((json && json.message) ? json.message : 'Error de red');
        }
        return json;
    }

    // Crear categoría
    document.getElementById('gcatToggleCreate')?.addEventListener('click', () => {
        const f = document.getElementById('gcatCreateForm');
        if (f) f.style.display = f.style.display === 'none' ? '' : 'none';
    });
    document.getElementById('gcatCancelCreate')?.addEventListener('click', () => {
        const f = document.getElementById('gcatCreateForm');
        if (f) f.style.display = 'none';
    });
    document.getElementById('gcatCreateBtn')?.addEventListener('click', async () => {
        const status = document.getElementById('gcatCreateStatus');
        const btn = document.getElementById('gcatCreateBtn');
        if (status) status.textContent = '';
        btn.disabled = true;
        try {
            await catsFetch('create', {
                nombre:        document.getElementById('gcatNombre')?.value ?? '',
                slug:          document.getElementById('gcatSlug')?.value ?? '',
                descripcion:   document.getElementById('gcatDescripcion')?.value ?? '',
                icono:         document.getElementById('gcatIcono')?.value ?? '',
                color:         document.getElementById('gcatColor')?.value ?? '#00fff7',
                orden:         document.getElementById('gcatOrden')?.value ?? '0',
                activa:        '1',
                imagen:        document.getElementById('gcatImagen')?.files?.[0] ?? null,
                mostrar_menu:  document.querySelector('input[name="gcatMostrarMenu"]:checked')?.value ?? 'no',
                destacada:     document.getElementById('gcatDestacada')?.checked ? '1' : '0',
            });
            // Reload page so new category appears in PHP-rendered lists
            window.location.reload();
        } catch (e) {
            if (status) { status.textContent = e.message; }
        } finally {
            btn.disabled = false;
        }
    });

    // Editar categoría (botones renderizados por PHP)
    document.querySelectorAll('.gcatEditBtn').forEach(btn => {
        btn.addEventListener('click', () => {
            const id = btn.dataset.id;
            const row = btn.closest('.gcatRow');
            const editRow = document.getElementById('gcatEdit_' + id);
            if (row) row.style.display = 'none';
            if (editRow) editRow.style.display = '';
        });
    });

    document.querySelectorAll('.gcatCancelEditBtn').forEach(btn => {
        btn.addEventListener('click', () => {
            const id = btn.dataset.id;
            const row = document.querySelector('.gcatRow[data-id="' + id + '"]');
            const editRow = document.getElementById('gcatEdit_' + id);
            if (editRow) editRow.style.display = 'none';
            if (row) row.style.display = '';
        });
    });

    document.querySelectorAll('.gcatSaveEditBtn').forEach(btn => {
        btn.addEventListener('click', async () => {
            const id = btn.dataset.id;
            const editRow = document.getElementById('gcatEdit_' + id);
            const status = editRow?.querySelector('.gcatEditStatus');
            if (status) status.textContent = '';
            btn.disabled = true;
            try {
                const gcatUpdatePayload = {
                    id,
                    nombre:        editRow?.querySelector('.gcatEditNombre')?.value ?? '',
                    slug:          editRow?.querySelector('.gcatEditSlug')?.value ?? '',
                    descripcion:   editRow?.querySelector('.gcatEditDescripcion')?.value ?? '',
                    icono:         editRow?.querySelector('.gcatEditIcono')?.value ?? '',
                    color:         editRow?.querySelector('.gcatEditColor')?.value ?? '#00fff7',
                    orden:         editRow?.querySelector('.gcatEditOrden')?.value ?? '0',
                    imagen:        editRow?.querySelector('.gcatEditImagen')?.files?.[0] ?? null,
                    remove_imagen: editRow?.querySelector('.gcatEditRemoveImagen')?.value ?? '0',
                    mostrar_menu:  editRow?.querySelector('.gcatEditMostrarMenu:checked')?.value ?? 'no',
                    destacada:     editRow?.querySelector('.gcatEditDestacada')?.checked ? '1' : '0',
                };
                const gcatActivaEl = editRow?.querySelector('.gcatEditActiva');
                if (gcatActivaEl) gcatUpdatePayload.activa = gcatActivaEl.checked ? '1' : '0';
                await catsFetch('update', gcatUpdatePayload);
                window.location.reload();
            } catch (e) {
                if (status) status.textContent = e.message;
                btn.disabled = false;
            }
        });
    });

    document.querySelectorAll('.gcatDeleteBtn').forEach(btn => {
        btn.addEventListener('click', async () => {
            if (!window.confirm('¿Eliminar la categoría "' + (btn.dataset.nombre || '') + '"?\nSe desvinculará de todos los juegos.')) return;
            btn.disabled = true;
            try {
                await catsFetch('delete', { id: btn.dataset.id });
                window.location.reload();
            } catch (e) {
                window.alert(e.message);
                btn.disabled = false;
            }
        });
    });

    // Preview imagen en formulario de crear
    document.getElementById('gcatImagen')?.addEventListener('change', function () {
        const preview    = document.getElementById('gcatImagenPreview');
        const previewImg = document.getElementById('gcatImagenPreviewImg');
        if (this.files?.[0] && preview && previewImg) {
            previewImg.src = URL.createObjectURL(this.files[0]);
            preview.style.display = '';
        } else if (preview) {
            preview.style.display = 'none';
        }
    });

    // Preview imagen nueva en filas de edición
    document.querySelectorAll('.gcatEditImagen').forEach(input => {
        input.addEventListener('change', function () {
            if (!this.files?.[0]) return;
            const editRow = this.closest('.gcatEditRow');
            if (!editRow) return;
            let thumb = editRow.querySelector('.gcatCurrentImgThumb');
            if (!thumb) {
                thumb = document.createElement('img');
                thumb.className = 'gcatCurrentImgThumb';
                thumb.style.cssText = 'max-height:40px;border-radius:5px;border:1px solid #1e3a5f;';
                this.closest('.mt-2.d-flex').prepend(thumb);
            }
            thumb.src = URL.createObjectURL(this.files[0]);
            thumb.style.display = '';
        });
    });

    // Botón "Quitar imagen" en edición
    document.querySelectorAll('.gcatRemoveImgBtn').forEach(btn => {
        btn.addEventListener('click', () => {
            const editRow = btn.closest('.gcatEditRow');
            if (!editRow) return;
            const thumb = editRow.querySelector('.gcatCurrentImgThumb');
            if (thumb) thumb.style.display = 'none';
            const removeInput = editRow.querySelector('.gcatEditRemoveImagen');
            if (removeInput) removeInput.value = '1';
            btn.style.display = 'none';
        });
    });
})();
</script>
<?php include '../includes/footer.php'; ?>