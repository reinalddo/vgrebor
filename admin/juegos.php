<?php
// admin/juegos.php - Gestión de juegos y características
require_once '../includes/db_connect.php';
require_once '../includes/tenant.php';
require_once '../includes/recargas_api.php';
require_once '../includes/store_config.php';
require_once '../includes/api_discord.php';
require_once '../includes/game_entry_window_per_game.php';
require_once '../includes/slugify.php';

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

function admin_game_normalize_api_selection(array $payload, string $giftVenKey, string $discordKey, bool $allowCombined = false): array {
    $giftVenCategory = trim((string) ($payload[$giftVenKey] ?? ''));
    $discordCommand = trim((string) ($payload[$discordKey] ?? ''));

    if (!$allowCombined && $giftVenCategory !== '' && $discordCommand !== '') {
        return [
            'ok' => false,
            'message' => 'Solo puedes seleccionar una API por juego: TiendaGiftVen o Discord.',
            'giftven' => '',
            'discord' => '',
            'api_free_fire' => 0,
        ];
    }

    if ($discordCommand !== '') {
        $discordDefinition = api_discord_find_command($discordCommand);
        if (!$discordDefinition || ($discordDefinition['kind'] ?? '') !== 'topup') {
            return [
                'ok' => false,
                'message' => 'El comando seleccionado en Juegos API Discord no es válido.',
                'giftven' => '',
                'discord' => '',
                'api_free_fire' => 0,
            ];
        }
    }

    return [
        'ok' => true,
        'message' => '',
        'giftven' => $giftVenCategory,
        'discord' => $discordCommand,
        'api_free_fire' => $giftVenCategory !== '' ? 1 : 0,
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
ensure_juegos_orden_column($mysqli);
ensure_juegos_slug_column($mysqli);
ensure_juegos_imagen_hero_column($mysqli);

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
    $stmt_img = $mysqli->prepare("SELECT imagen, imagen_hero, imagen_paquete FROM juegos WHERE id=?");
    $stmt_img->bind_param('i', $del_id);
    $stmt_img->execute();
    $stmt_img->bind_result($img_juego, $img_juego_hero, $img_juego_paquete);
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
        $currentGameStmt = $mysqli->prepare("SELECT categoria_api_discord, imagen, imagen_paquete, imagen_hero FROM juegos WHERE id = ? LIMIT 1");
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
    $edit_categoria_api = $apiSelection['giftven'];
    $edit_categoria_api_discord = $discordApiEnabled
        ? $apiSelection['discord']
        : trim((string) ($currentGame['categoria_api_discord'] ?? ''));
    $edit_api_free_fire = $apiSelection['api_free_fire'];
    $edit_activo = isset($_POST['edit_activo']) ? 1 : 0;
    $edit_moneda_fija_id = isset($_POST['edit_moneda_fija_id']) && $_POST['edit_moneda_fija_id'] !== '' ? intval($_POST['edit_moneda_fija_id']) : null;
    $edit_imagen = admin_game_store_upload($_FILES['edit_imagen'] ?? [], 'juego_');
    $edit_imagen_hero = admin_game_store_upload($_FILES['edit_imagen_hero'] ?? [], 'juegohero_');
    $edit_imagen_paquete = admin_game_store_upload($_FILES['edit_imagen_paquete'] ?? [], 'juegopaq_');
    $remove_edit_imagen_hero = isset($_POST['remove_edit_imagen_hero']);
    $currentImage = (string) ($currentGame['imagen'] ?? '');
    $currentHeroImage = (string) ($currentGame['imagen_hero'] ?? '');
    $currentPackageImage = (string) ($currentGame['imagen_paquete'] ?? '');

    if ($edit_imagen !== null && $currentImage !== '') {
        admin_game_delete_upload($currentImage);
    }
    if ($edit_imagen_hero !== null && $currentHeroImage !== '') {
        admin_game_delete_upload($currentHeroImage);
    }
    if ($edit_imagen_paquete !== null && $currentPackageImage !== '') {
        admin_game_delete_upload($currentPackageImage);
    }
    if ($remove_edit_imagen_hero && $currentHeroImage !== '' && $edit_imagen_hero === null) {
        admin_game_delete_upload($currentHeroImage);
    }

    $nextImage = $edit_imagen ?? $currentImage;
    $nextHeroImage = $remove_edit_imagen_hero ? '' : ($edit_imagen_hero ?? $currentHeroImage);
    $nextPackageImage = $edit_imagen_paquete ?? $currentPackageImage;

    $stmt = $mysqli->prepare("UPDATE juegos SET nombre=?, descripcion=?, slug=?, imagen=?, imagen_hero=?, imagen_paquete=?, popular=?, api_free_fire=?, categoria_api=?, categoria_api_discord=?, activo=?, moneda_fija_id=? WHERE id=?");
    $stmt->bind_param('ssssssiissiii', $edit_nombre, $edit_descripcion, $edit_slug, $nextImage, $nextHeroImage, $nextPackageImage, $edit_popular, $edit_api_free_fire, $edit_categoria_api, $edit_categoria_api_discord, $edit_activo, $edit_moneda_fija_id, $edit_id);
    $stmt->execute();
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
    $categoria_api = $apiSelection['giftven'];
    $categoria_api_discord = $discordApiEnabled ? $apiSelection['discord'] : '';
    $api_free_fire = $apiSelection['api_free_fire'];
    $activo = isset($_POST['activo']) ? 1 : 0;
    $orden = admin_game_next_order($mysqli);
    $imagen = admin_game_store_upload($_FILES['imagen'] ?? [], 'juego_');
    $imagen_hero = admin_game_store_upload($_FILES['imagen_hero'] ?? [], 'juegohero_');
    $imagen_paquete = admin_game_store_upload($_FILES['imagen_paquete'] ?? [], 'juegopaq_');
    $stmt = $mysqli->prepare("INSERT INTO juegos (nombre, imagen, imagen_hero, imagen_paquete, descripcion, slug, moneda_fija_id, popular, api_free_fire, categoria_api, categoria_api_discord, activo, orden) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param('ssssssiiissii', $nombre, $imagen, $imagen_hero, $imagen_paquete, $descripcion, $slug, $moneda_fija_id, $popular, $api_free_fire, $categoria_api, $categoria_api_discord, $activo, $orden);
    $stmt->execute();
    $juego_id = $mysqli->insert_id;
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
                <label class="form-label text-neon" for="editCategoriaApiInput">Juegos API TiendaGiftVen</label>
                <select name="edit_categoria_api_tiendagiftven" id="editCategoriaApiInput" class="form-select<?= $gameApiExclusiveClass ?>" data-exclusive-group="edit-game-api" data-exclusive-target="editDiscordApiInput" data-exclusive-enabled="<?= $mixedApiUnionEnabled ? '0' : '1' ?>" style="background:#222c3a;color:#00fff7;border:1px solid #00fff7;">
                    <option value="">Proceso manual / sin API</option>
                    <?php foreach ($apiCategories as $apiCategory): ?>
                    <option value="<?= htmlspecialchars($apiCategory, ENT_QUOTES, 'UTF-8') ?>" <?= (string) ($juego_edit['categoria_api'] ?? '') === (string) $apiCategory ? 'selected' : '' ?>><?= htmlspecialchars($apiCategory, ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text mt-2" style="color:#8be9fd;"><?= $mixedApiUnionEnabled ? 'Selecciona la categoría exacta de TiendaGiftVen. Con la unión activa puedes guardar también un comando de Discord en este mismo juego.' : 'Selecciona la categoría exacta de TiendaGiftVen. Si eliges una aquí, el select de Discord debe quedar vacío.' ?></div>
            </div>
            <?php if ($discordApiEnabled): ?>
            <div class="form-check mb-3">
                <label class="form-label text-neon" for="editDiscordApiInput">Juegos API Discord</label>
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
                <div class="form-text mt-2" style="color:#8be9fd;"><?= $mixedApiUnionEnabled ? 'Selecciona el comando base de Discord. Con la unión activa este juego puede conservar también su categoría de TiendaGiftVen.' : 'Selecciona el comando base de Discord que usará este juego más adelante. Si eliges uno aquí, TiendaGiftVen debe quedar vacío.' ?></div>
            </div>
            <?php endif; ?>
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
            <button type="submit" name="edit_juego_submit" class="btn neon-btn-info w-100 mt-3">Guardar cambios</button>
            <a href="<?= htmlspecialchars($adminGamesUrl, ENT_QUOTES, 'UTF-8') ?>" class="position-absolute top-0 end-0 m-3 text-neon fs-3" style="text-decoration:none;">&times;</a>
        </form>
    </div>
    <?php endif; }
    ?>
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
                <label class="form-label" for="categoriaApiInput" style="color:#00fff7;">Juegos API TiendaGiftVen</label>
                <select name="categoria_api_tiendagiftven" id="categoriaApiInput" class="form-select<?= $gameApiExclusiveClass ?>" data-exclusive-group="create-game-api" data-exclusive-target="categoriaDiscordApiInput" data-exclusive-enabled="<?= $mixedApiUnionEnabled ? '0' : '1' ?>" style="background:#222c3a; color:#00fff7; border:1px solid #00fff7;">
                    <option value="">Proceso manual / sin API</option>
                    <?php foreach ($apiCategories as $apiCategory): ?>
                    <option value="<?= htmlspecialchars($apiCategory, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($apiCategory, ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text mt-2" style="color:#8be9fd;"><?= $mixedApiUnionEnabled ? 'Selecciona la categoría exacta del catálogo de TiendaGiftVen. Con la unión activa también puedes guardar un comando de Discord en este mismo juego.' : 'Selecciona la categoría exacta del catálogo de TiendaGiftVen. Si la eliges, el select de Discord debe quedar vacío.' ?></div>
            </div>
            <?php if ($discordApiEnabled): ?>
            <div class="form-check mt-3">
                <label class="form-label" for="categoriaDiscordApiInput" style="color:#00fff7;">Juegos API Discord</label>
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
                <div class="form-text mt-2" style="color:#8be9fd;"><?= $mixedApiUnionEnabled ? 'Selecciona el comando base de Discord. Con la unión activa este juego puede conservar también su categoría de TiendaGiftVen.' : 'Selecciona el comando base de Discord que representa este juego. Si eliges este valor, TiendaGiftVen debe quedar vacío.' ?></div>
            </div>
            <?php endif; ?>
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
                    <th style="color:#00fff7; background:#181f2a;">Popular</th>
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
                        <?php if (!empty($j['popular'])): ?>
                                <span title="Popular" style="color:#00fff7; font-size:1.2em;">★</span>
                            <?php else: ?>
                                <span style="color:#444;">—</span>
                            <?php endif; ?>
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
            <label class="block text-slate-300 font-medium mb-1">Juegos API TiendaGiftVen:</label>
            <select name="edit_categoria_api_tiendagiftven" id="editCategoriaApiInputLegacy" class="w-full rounded-lg px-3 py-2 bg-slate-800 text-white mb-2<?= $gameApiExclusiveClass ?>" data-exclusive-group="edit-game-api-legacy" data-exclusive-target="editDiscordApiInputLegacy" data-exclusive-enabled="<?= $mixedApiUnionEnabled ? '0' : '1' ?>">
                <option value="">Proceso manual / sin API</option>
                <?php foreach ($apiCategories as $apiCategory): ?>
                <option value="<?= htmlspecialchars($apiCategory, ENT_QUOTES, 'UTF-8') ?>" <?= (string) ($juego_edit['categoria_api'] ?? '') === (string) $apiCategory ? 'selected' : '' ?>><?= htmlspecialchars($apiCategory, ENT_QUOTES, 'UTF-8') ?></option>
                <?php endforeach; ?>
            </select>
            <div class="text-xs text-slate-400 mb-2"><?= $mixedApiUnionEnabled ? 'Con la unión activa puedes guardar también el comando de Discord en este mismo juego.' : 'Si eliges TiendaGiftVen, deja vacío el select de Discord.' ?></div>
            <?php if ($discordApiEnabled): ?>
            <label class="block text-slate-300 font-medium mb-1">Juegos API Discord:</label>
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
            <div class="text-xs text-slate-400 mb-2"><?= $mixedApiUnionEnabled ? 'Con la unión activa puedes conservar también la categoría de TiendaGiftVen en este juego.' : 'Si eliges Discord, deja vacío el select de TiendaGiftVen.' ?></div>
            <?php endif; ?>
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
</script>
<?php include '../includes/footer.php'; ?>