<?php
// admin/paquetes.php - Gestión de paquetes de un juego

require_once '../includes/db_connect.php';
require_once '../includes/tenant.php';
require_once '../includes/recargas_api.php';
require_once '../includes/api_discord.php';
require_once '../includes/store_config.php';
require_once '../includes/package_features.php';
require_once '../includes/package_account_sales.php';
require_once '../includes/recharge_availability.php';
require_once '../includes/win_points.php';

function admin_packages_is_ajax_request(): bool {
    if (isset($_REQUEST['ajax']) && (string) $_REQUEST['ajax'] === '1') {
        return true;
    }

    $requestedWith = strtolower(trim((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')));
    $accept = strtolower(trim((string) ($_SERVER['HTTP_ACCEPT'] ?? '')));

    return $requestedWith === 'xmlhttprequest' || str_contains($accept, 'application/json');
}

function admin_packages_json_response(array $payload, int $statusCode = 200): void {
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function admin_packages_redirect(string $baseUrl, array $params = []): void {
    $target = $baseUrl;
    if ($params !== []) {
        $target .= '?' . http_build_query($params);
    }

    header('Location: ' . $target);
    exit;
}

function admin_package_store_upload_to_dir(array $file, string $subdirectory): ?string {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return null;
    }

    $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
    $permitidas = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    if (!in_array($ext, $permitidas, true)) {
        return null;
    }

    $dir = tenant_upload_absolute_dir($subdirectory);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        return null;
    }

    $fileName = uniqid('paquete_', true) . '.' . $ext;
    $destination = $dir . DIRECTORY_SEPARATOR . $fileName;
    if (!move_uploaded_file((string) ($file['tmp_name'] ?? ''), $destination)) {
        return null;
    }

    return tenant_upload_public_path($subdirectory, $fileName, false);
}

function admin_package_store_upload(array $file): ?string {
    return admin_package_store_upload_to_dir($file, 'paquetes');
}

function admin_package_store_account_gallery_upload(array $file): ?string {
    return admin_package_store_upload_to_dir($file, 'paquetes/cuentas');
}

function admin_package_delete_upload(?string $path): void {
    $absolutePath = tenant_resolve_public_path((string) $path);
    if ($absolutePath !== null && is_file($absolutePath)) {
        @unlink($absolutePath);
    }
}

function admin_package_delete_gallery_uploads(array $galleryItems): void {
    foreach ($galleryItems as $galleryItem) {
        $imagePath = trim((string) ($galleryItem['image_path'] ?? ''));
        if ($imagePath !== '') {
            admin_package_delete_upload($imagePath);
        }
    }
}

function admin_package_normalize_uploaded_file_list(array $files): array {
    $names = $files['name'] ?? null;
    if (!is_array($names)) {
        return $names !== null ? [$files] : [];
    }

    $normalized = [];
    foreach (array_keys($names) as $index) {
        $normalized[] = [
            'name' => $files['name'][$index] ?? '',
            'type' => $files['type'][$index] ?? '',
            'tmp_name' => $files['tmp_name'][$index] ?? '',
            'error' => $files['error'][$index] ?? UPLOAD_ERR_NO_FILE,
            'size' => $files['size'][$index] ?? 0,
        ];
    }

    return $normalized;
}

function admin_package_build_account_gallery_payload(array $existingPaths, array $existingDescriptions, array $existingRemovals, array $existingReplacementFiles, array $newDescriptions, array $newFiles): array {
    $items = [];
    $deletePaths = [];
    $order = 1;
    $removeLookup = [];

    foreach ($existingRemovals as $index => $value) {
        if ((string) $value === '1') {
            $removeLookup[(int) $index] = true;
        }
    }

    foreach ($existingPaths as $index => $path) {
        $currentPath = trim((string) $path);
        if ($currentPath === '') {
            continue;
        }

        $description = package_account_sales_normalize_caption((string) ($existingDescriptions[$index] ?? ''));
        $replacementPath = admin_package_store_account_gallery_upload($existingReplacementFiles[$index] ?? []);
        if ($replacementPath !== null) {
            $items[] = [
                'image_path' => $replacementPath,
                'description' => $description,
                'order' => $order++,
            ];
            $deletePaths[] = $currentPath;
            continue;
        }

        if (isset($removeLookup[(int) $index])) {
            $deletePaths[] = $currentPath;
            continue;
        }

        $items[] = [
            'image_path' => $currentPath,
            'description' => $description,
            'order' => $order++,
        ];
    }

    foreach ($newFiles as $index => $file) {
        $newPath = admin_package_store_account_gallery_upload($file);
        if ($newPath === null) {
            continue;
        }

        $items[] = [
            'image_path' => $newPath,
            'description' => package_account_sales_normalize_caption((string) ($newDescriptions[$index] ?? '')),
            'order' => $order++,
        ];
    }

    return [
        'items' => $items,
        'delete_paths' => array_values(array_unique(array_filter($deletePaths, static fn (string $path): bool => trim($path) !== ''))),
    ];
}

function ensure_juego_paquetes_monto_ff_column(mysqli $mysqli): void {
    $result = $mysqli->query("SHOW COLUMNS FROM juego_paquetes LIKE 'monto_ff'");
    if (!($result instanceof mysqli_result) || $result->num_rows === 0) {
        $mysqli->query("ALTER TABLE juego_paquetes ADD COLUMN monto_ff VARCHAR(20) NULL AFTER clave");
    }
}

function ensure_juego_paquetes_activo_column(mysqli $mysqli): void {
    $result = $mysqli->query("SHOW COLUMNS FROM juego_paquetes LIKE 'activo'");
    if (!($result instanceof mysqli_result) || $result->num_rows === 0) {
        $mysqli->query("ALTER TABLE juego_paquetes ADD COLUMN activo TINYINT(1) DEFAULT 1 NULL AFTER imagen_icono");
    }
}

function ensure_juego_paquetes_paquete_api_column(mysqli $mysqli): void {
    $result = $mysqli->query("SHOW COLUMNS FROM juego_paquetes LIKE 'paquete_api'");
    if (!($result instanceof mysqli_result) || $result->num_rows === 0) {
        $mysqli->query("ALTER TABLE juego_paquetes ADD COLUMN paquete_api INT NULL AFTER monto_ff");
    }
}

function ensure_juego_paquetes_api_provider_column(mysqli $mysqli): void {
    $result = $mysqli->query("SHOW COLUMNS FROM juego_paquetes LIKE 'api_provider'");
    if (!($result instanceof mysqli_result) || $result->num_rows === 0) {
        $mysqli->query("ALTER TABLE juego_paquetes ADD COLUMN api_provider VARCHAR(30) NULL AFTER paquete_api");
    }
}

function ensure_juego_paquetes_cantidad_text_column(mysqli $mysqli): void {
    $result = $mysqli->query("SHOW COLUMNS FROM juego_paquetes LIKE 'cantidad'");
    if (!($result instanceof mysqli_result) || $result->num_rows === 0) {
        return;
    }

    $column = $result->fetch_assoc();
    $result->free();
    $columnType = strtolower(trim((string) ($column['Type'] ?? '')));
    if ($columnType !== '' && !str_contains($columnType, 'char') && !str_contains($columnType, 'text')) {
        $mysqli->query("ALTER TABLE juego_paquetes MODIFY cantidad VARCHAR(80) NOT NULL DEFAULT '1'");
    }
}

function ensure_juego_paquetes_orden_column(mysqli $mysqli): void {
    $result = $mysqli->query("SHOW COLUMNS FROM juego_paquetes LIKE 'orden'");
    if (!($result instanceof mysqli_result) || $result->num_rows === 0) {
        $mysqli->query("ALTER TABLE juego_paquetes ADD COLUMN orden INT NULL AFTER activo");
    }
}

function ensure_juegos_api_discord_catalog_columns(mysqli $mysqli): void {
    $columns = [
        'api_discord_catalog_json' => "ALTER TABLE juegos ADD COLUMN api_discord_catalog_json LONGTEXT NULL AFTER categoria_api_discord",
        'api_discord_catalog_raw' => "ALTER TABLE juegos ADD COLUMN api_discord_catalog_raw LONGTEXT NULL AFTER api_discord_catalog_json",
        'api_discord_catalog_status' => "ALTER TABLE juegos ADD COLUMN api_discord_catalog_status VARCHAR(40) NULL AFTER api_discord_catalog_raw",
        'api_discord_catalog_message_id' => "ALTER TABLE juegos ADD COLUMN api_discord_catalog_message_id VARCHAR(120) NULL AFTER api_discord_catalog_status",
        'api_discord_catalog_updated_at' => "ALTER TABLE juegos ADD COLUMN api_discord_catalog_updated_at DATETIME NULL AFTER api_discord_catalog_message_id",
    ];

    foreach ($columns as $columnName => $statement) {
        $result = $mysqli->query("SHOW COLUMNS FROM juegos LIKE '" . $mysqli->real_escape_string($columnName) . "'");
        if (!($result instanceof mysqli_result) || $result->num_rows === 0) {
            $mysqli->query($statement);
        }
    }
}

function admin_package_save_discord_catalog(mysqli $mysqli, int $gameId, array $items, string $rawText, string $status = 'ready'): bool {
    if ($gameId <= 0 || $items === []) {
        return false;
    }

    $catalogJson = json_encode(api_discord_normalize_catalog_items($items), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($catalogJson) || $catalogJson === '') {
        return false;
    }

    $normalizedRawText = trim($rawText);
    $normalizedStatus = trim($status) !== '' ? trim($status) : 'ready';
    $stmt = $mysqli->prepare("UPDATE juegos SET api_discord_catalog_json = ?, api_discord_catalog_raw = ?, api_discord_catalog_status = ?, api_discord_catalog_updated_at = NOW() WHERE id = ? LIMIT 1");
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('sssi', $catalogJson, $normalizedRawText, $normalizedStatus, $gameId);
    $ok = $stmt->execute();
    $stmt->close();

    return $ok;
}

function admin_package_normalize_provider_value($value): string {
    $normalized = strtolower(trim((string) $value));
    return in_array($normalized, ['giftven', 'discord', 'free_fire'], true) ? $normalized : '';
}

function admin_package_resolve_provider(array $package, array $game, bool $discordFeatureEnabled): string {
    $storedProvider = admin_package_normalize_provider_value($package['api_provider'] ?? '');
    if ($storedProvider !== '') {
        return $storedProvider;
    }

    if ((int) ($package['paquete_api'] ?? 0) > 0) {
        return 'giftven';
    }

    if (trim((string) ($package['monto_ff'] ?? '')) !== '') {
        return 'free_fire';
    }

    if ($discordFeatureEnabled && trim((string) ($game['categoria_api_discord'] ?? '')) !== '') {
        return 'discord';
    }

    return '';
}

function admin_package_provider_label(string $provider): string {
    return match ($provider) {
        'giftven' => 'TiendaGiftVen',
        'discord' => 'Discord',
        'free_fire' => 'Free Fire API',
        default => 'Manual',
    };
}

function admin_package_provider_reference_text(string $provider, array $package, array $apiProductsById): string {
    if ($provider === 'giftven') {
        $apiProductId = (int) ($package['paquete_api'] ?? 0);
        if ($apiProductId > 0 && isset($apiProductsById[$apiProductId])) {
            return recargas_api_product_label($apiProductsById[$apiProductId]);
        }

        return $apiProductId > 0 ? 'ID ' . $apiProductId : '—';
    }

    if ($provider === 'discord') {
        $quantity = trim((string) ($package['cantidad'] ?? ''));
        return $quantity !== '' ? $quantity : '—';
    }

    if ($provider === 'free_fire') {
        $amount = trim((string) ($package['monto_ff'] ?? ''));
        return $amount !== '' ? free_fire_api_amount_label($amount) : '—';
    }

    return '—';
}

function admin_package_format_catalog_quantity(array $item): string {
    $quantity = trim((string) ($item['quantity'] ?? ''));
    if ($quantity !== '') {
        return $quantity;
    }

    $name = trim((string) ($item['name'] ?? ''));
    if (preg_match('/^([0-9]+(?:\s*\+\s*[0-9]+)?)/u', $name, $matches) === 1) {
        return trim((string) ($matches[1] ?? ''));
    }

    return '-';
}

function admin_package_next_order(mysqli $mysqli, int $juegoId): int {
    $stmt = $mysqli->prepare("SELECT COALESCE(MAX(orden), 0) + 1 AS next_order FROM juego_paquetes WHERE juego_id = ?");
    $stmt->bind_param('i', $juegoId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();
    return max(1, (int) ($row['next_order'] ?? 1));
}

function free_fire_api_amount_options(): array {
    return [
        '1' => ['suggested_name' => 'FF_110', 'diamonds' => '110 diamantes'],
        '2' => ['suggested_name' => 'FF_341', 'diamonds' => '341 diamantes'],
        '3' => ['suggested_name' => 'FF_572', 'diamonds' => '572 diamantes'],
        '4' => ['suggested_name' => 'FF_1166', 'diamonds' => '1166 diamantes'],
        '5' => ['suggested_name' => 'FF_2376', 'diamonds' => '2376 diamantes'],
        '6' => ['suggested_name' => 'FF_6138', 'diamonds' => '6138 diamantes'],
    ];
}

function free_fire_api_amount_label(string $amount): string {
    $options = free_fire_api_amount_options();
    if (!isset($options[$amount])) {
        return $amount;
    }

    $option = $options[$amount];
    return $amount . ' - ' . $option['suggested_name'] . ' - ' . $option['diamonds'];
}

function admin_package_find_api_discord_price_command(string $topupCommandKey): ?array {
    $topupCommand = api_discord_find_command($topupCommandKey);
    if (!$topupCommand || trim((string) ($topupCommand['kind'] ?? '')) !== 'topup') {
        return null;
    }

    $gameKey = trim((string) ($topupCommand['game'] ?? ''));
    if ($gameKey === '') {
        return null;
    }

    foreach (api_discord_price_commands() as $priceCommand) {
        if (trim((string) ($priceCommand['game'] ?? '')) === $gameKey) {
            return $priceCommand;
        }
    }

    return null;
}

function admin_package_feature_icon_options_html(array $iconOptions, string $selected = 'sparkles'): string {
    $selectedIcon = package_feature_normalize_icon($selected);
    $iconSymbols = [
        'sparkles' => '✨',
        'diamond' => '💎',
        'lightning' => '⚡',
        'shield' => '🛡',
        'gift' => '🎁',
        'controller' => '🎮',
        'trophy' => '🏆',
        'rocket' => '🚀',
        'star' => '⭐',
        'layers' => '🧩',
        'best_seller' => '🔥',
        'limited' => '⏳',
        'recommended' => '👑',
    ];
    $html = '';
    foreach ($iconOptions as $iconKey => $label) {
        $optionLabel = trim((string) (($iconSymbols[$iconKey] ?? '•') . ' ' . $label));
        $html .= '<option value="' . htmlspecialchars($iconKey, ENT_QUOTES, 'UTF-8') . '"'
            . ($selectedIcon === $iconKey ? ' selected' : '')
            . '>' . htmlspecialchars($optionLabel, ENT_QUOTES, 'UTF-8') . '</option>';
    }
    return $html;
}

function admin_package_feature_badges_html(array $features): string {
    if (empty($features)) {
        return '';
    }

    ob_start();
    ?>
    <div class="d-flex flex-wrap gap-2 mt-2">
        <?php foreach ($features as $feature): ?>
            <span class="badge rounded-pill d-inline-flex align-items-center gap-2 px-3 py-2" style="background:rgba(15,23,42,0.9);border:1px solid rgba(34,211,238,0.28);color:#d8fbff;">
                <?= package_feature_render_icon((string) ($feature['icon'] ?? 'sparkles'), 'package-feature-badge-icon') ?>
                <span><?= htmlspecialchars((string) ($feature['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
            </span>
        <?php endforeach; ?>
    </div>
    <?php
    return trim((string) ob_get_clean());
}

function admin_package_normalize_apply_mode(?string $value): string {
    $normalized = strtolower(trim((string) $value));
    return in_array($normalized, ['replace', 'add'], true) ? $normalized : '';
}

function admin_package_collect_bulk_feature_ids_from_existing(array $featureIds, array $modes): array {
    $actions = ['replace' => [], 'add' => []];
    foreach ($modes as $index => $mode) {
        $normalizedMode = admin_package_normalize_apply_mode((string) $mode);
        $featureId = (int) ($featureIds[$index] ?? 0);
        if ($normalizedMode === '' || $featureId <= 0) {
            continue;
        }
        if (!in_array($featureId, $actions[$normalizedMode], true)) {
            $actions[$normalizedMode][] = $featureId;
        }
    }
    return $actions;
}

function admin_package_collect_bulk_feature_ids_from_catalog(array $catalogFeatureIds, array $selectedFeatureIds, array $modes): array {
    $actions = ['replace' => [], 'add' => []];
    $selectedLookup = [];
    foreach ($selectedFeatureIds as $featureId) {
        $normalizedId = (int) $featureId;
        if ($normalizedId > 0) {
            $selectedLookup[$normalizedId] = true;
        }
    }

    foreach ($catalogFeatureIds as $index => $featureId) {
        $normalizedFeatureId = (int) $featureId;
        $normalizedMode = admin_package_normalize_apply_mode((string) ($modes[$index] ?? ''));
        if ($normalizedFeatureId <= 0 || $normalizedMode === '' || !isset($selectedLookup[$normalizedFeatureId])) {
            continue;
        }
        if (!in_array($normalizedFeatureId, $actions[$normalizedMode], true)) {
            $actions[$normalizedMode][] = $normalizedFeatureId;
        }
    }

    return $actions;
}

function admin_package_collect_bulk_feature_ids_from_new(mysqli $mysqli, array $names, array $icons, array $modes): array {
    $actions = ['replace' => [], 'add' => []];
    foreach ($modes as $index => $mode) {
        $normalizedMode = admin_package_normalize_apply_mode((string) $mode);
        if ($normalizedMode === '') {
            continue;
        }
        $featureName = package_feature_normalize_name((string) ($names[$index] ?? ''));
        if ($featureName === '') {
            continue;
        }
        $featureId = package_feature_catalog_find_or_create($mysqli, $featureName, (string) ($icons[$index] ?? 'sparkles'));
        if ($featureId > 0 && !in_array($featureId, $actions[$normalizedMode], true)) {
            $actions[$normalizedMode][] = $featureId;
        }
    }
    return $actions;
}

function admin_package_merge_bulk_actions(array ...$actionGroups): array {
    $merged = ['replace' => [], 'add' => []];
    foreach ($actionGroups as $group) {
        foreach (['replace', 'add'] as $mode) {
            foreach ((array) ($group[$mode] ?? []) as $featureId) {
                $normalizedId = (int) $featureId;
                if ($normalizedId > 0 && !in_array($normalizedId, $merged[$mode], true)) {
                    $merged[$mode][] = $normalizedId;
                }
            }
        }
    }
    return $merged;
}

function admin_package_apply_bulk_feature_actions(mysqli $mysqli, int $gameId, int $currentPackageId, array $actions): void {
    $replaceIds = array_values(array_filter(array_map('intval', (array) ($actions['replace'] ?? [])), static fn ($id) => $id > 0));
    $addIds = array_values(array_filter(array_map('intval', (array) ($actions['add'] ?? [])), static fn ($id) => $id > 0));
    if (empty($replaceIds) && empty($addIds)) {
        return;
    }
    if (!empty($replaceIds) && $currentPackageId > 0) {
        $currentFeatureIds = $replaceIds;
        foreach ($addIds as $featureId) {
            if (!in_array($featureId, $currentFeatureIds, true)) {
                $currentFeatureIds[] = $featureId;
            }
        }
        package_assign_feature_ids_to_package($mysqli, $currentPackageId, $currentFeatureIds);
    }
    if (!empty($replaceIds)) {
        package_apply_feature_ids_to_game_packages($mysqli, $gameId, $replaceIds, true, $currentPackageId);
    }
    if (!empty($addIds)) {
        package_apply_feature_ids_to_game_packages($mysqli, $gameId, $addIds, false, $currentPackageId);
    }
}

ensure_juego_paquetes_monto_ff_column($mysqli);
ensure_juego_paquetes_activo_column($mysqli);
ensure_juego_paquetes_paquete_api_column($mysqli);
ensure_juego_paquetes_api_provider_column($mysqli);
ensure_juego_paquetes_cantidad_text_column($mysqli);
ensure_juego_paquetes_orden_column($mysqli);
ensure_juegos_api_discord_catalog_columns($mysqli);
package_account_sales_ensure_schema($mysqli);
package_features_ensure_schema($mysqli);
win_points_ensure_schema();

$accountSaleFeatureEnabled = trim((string) store_config_get('vender_cuentas', '0')) === '1';

$adminGamesUrl = app_path('/admin/juegos');
$adminPackageBaseUrl = app_path('/admin/paquetes');

$juego_id = 0;
if (isset($_GET['juego'])) {
    $juego_id = intval($_GET['juego']);
} elseif (isset($_SERVER['REQUEST_URI'])) {
    // Soporta /admin/paquetes/2
    if (preg_match('#/admin/paquetes/(\\d+)#', $_SERVER['REQUEST_URI'], $m)) {
        $juego_id = intval($m[1]);
    }
}
if ($juego_id <= 0) { die('Juego no especificado.'); }

$juego = [];
$res_juego = $mysqli->prepare("SELECT * FROM juegos WHERE id=?");
$res_juego->bind_param('i', $juego_id);
$res_juego->execute();
$juego = $res_juego->get_result()->fetch_assoc();
$freeFireApiOptions = free_fire_api_amount_options();
$discordApiEnabled = trim((string) store_config_get('api_discord', '0')) === '1';
$unionApisEnabled = $discordApiEnabled && trim((string) store_config_get('union_apis_discord_giftven', '0')) === '1';
$juegoCategoriaApi = trim((string) ($juego['categoria_api'] ?? ''));
$juegoCategoriaApiDiscord = trim((string) ($juego['categoria_api_discord'] ?? ''));
$hasGiftVenCatalog = $juegoCategoriaApi !== '';
$hasDiscordCatalog = $juegoCategoriaApiDiscord !== '' && $discordApiEnabled;
$usesApiCatalog = $hasGiftVenCatalog;
$usesDiscordCatalog = !$hasGiftVenCatalog && $hasDiscordCatalog;
$usesLegacyFreeFire = !$hasGiftVenCatalog && !$hasDiscordCatalog && !empty($juego['api_free_fire']);
$packageSourceOptions = [];
if ($hasGiftVenCatalog) {
    $packageSourceOptions['giftven'] = admin_package_provider_label('giftven');
}
if ($hasDiscordCatalog) {
    $packageSourceOptions['discord'] = admin_package_provider_label('discord');
}
if ($usesLegacyFreeFire) {
    $packageSourceOptions['free_fire'] = admin_package_provider_label('free_fire');
}
$packageSourceSelectionEnabled = $unionApisEnabled && count($packageSourceOptions) > 1;
$packageDefaultProvider = $packageSourceSelectionEnabled ? '' : (array_key_first($packageSourceOptions) ?: '');
$winPointsName = win_points_program_name();
$defaultWinPointsReward = 0;
$apiProducts = [];
$apiProductsById = [];
$apiProductsError = null;
$discordTopupCommand = $hasDiscordCatalog ? api_discord_find_command($juegoCategoriaApiDiscord) : null;
$discordPriceCommand = $hasDiscordCatalog ? admin_package_find_api_discord_price_command($juegoCategoriaApiDiscord) : null;
$discordTopupCommandText = $discordTopupCommand ? api_discord_sample_command_text($discordTopupCommand) : '';
$discordPriceCommandText = $discordPriceCommand ? api_discord_sample_command_text($discordPriceCommand) : '';
$discordCatalogStatus = strtolower(trim((string) ($juego['api_discord_catalog_status'] ?? '')));
$discordCatalogRaw = trim((string) ($juego['api_discord_catalog_raw'] ?? ''));
$discordCatalogMessageId = trim((string) ($juego['api_discord_catalog_message_id'] ?? ''));
$discordCatalogUpdatedAt = trim((string) ($juego['api_discord_catalog_updated_at'] ?? ''));
$discordCatalogJson = trim((string) ($juego['api_discord_catalog_json'] ?? ''));
$discordCatalogItems = [];
if ($discordCatalogJson !== '') {
    $decodedDiscordCatalog = json_decode($discordCatalogJson, true);
    if (is_array($decodedDiscordCatalog)) {
        $discordCatalogItems = api_discord_normalize_catalog_items($decodedDiscordCatalog);
    }
}
$discordCatalogNotice = trim((string) ($_GET['discord_catalog_notice'] ?? ''));
$discordCatalogError = trim((string) ($_GET['discord_catalog_error'] ?? ''));
$packageError = trim((string) ($_GET['package_error'] ?? ''));

if ($hasGiftVenCatalog) {
    try {
        $apiProducts = recargas_api_fetch_products_by_category($juegoCategoriaApi);
        foreach ($apiProducts as $apiProduct) {
            $apiProductsById[(int) ($apiProduct['id'] ?? 0)] = $apiProduct;
        }
    } catch (Throwable $e) {
        $apiProductsError = $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sync_discord_catalog'])) {
    if (!$hasDiscordCatalog) {
        admin_packages_redirect($adminPackageBaseUrl . '/' . $juego_id, ['discord_catalog_error' => 'Este juego no usa el flujo de precios por API Discord.']);
    }

    if (!$discordPriceCommand || $discordPriceCommandText === '') {
        admin_packages_redirect($adminPackageBaseUrl . '/' . $juego_id, ['discord_catalog_error' => 'No existe un comando de precios vinculado para este juego Discord.']);
    }

    $discordConfig = api_discord_config();
    if (!$discordConfig['enabled']) {
        admin_packages_redirect($adminPackageBaseUrl . '/' . $juego_id, ['discord_catalog_error' => 'API Discord está desactivada en la configuración general.']);
    }

    if ($discordConfig['webhook_url'] === '' || !api_discord_validate_webhook_url($discordConfig['webhook_url'])) {
        admin_packages_redirect($adminPackageBaseUrl . '/' . $juego_id, ['discord_catalog_error' => 'El webhook de API Discord no está configurado correctamente.']);
    }

    $response = api_discord_send_webhook_message($discordConfig['webhook_url'], $discordPriceCommandText, [
        'timeout' => $discordConfig['timeout'],
        'username' => $discordConfig['username'],
        'avatar_url' => $discordConfig['avatar_url'],
        'wait' => true,
    ]);

    if (empty($response['ok'])) {
        $detail = trim((string) ($response['error'] ?? $response['body'] ?? ''));
        if ($detail === '') {
            $detail = 'Sin detalle adicional.';
        }
        admin_packages_redirect($adminPackageBaseUrl . '/' . $juego_id, ['discord_catalog_error' => 'No se pudo consultar el comando de precios. ' . $detail]);
    }

    $messageId = api_discord_extract_message_id($response);
    if ($messageId === '') {
        admin_packages_redirect($adminPackageBaseUrl . '/' . $juego_id, ['discord_catalog_error' => 'Discord aceptó el comando, pero no devolvió un message_id para correlacionar la respuesta.']);
    }

    $stmtCatalog = $mysqli->prepare("UPDATE juegos SET api_discord_catalog_status = ?, api_discord_catalog_message_id = ?, api_discord_catalog_updated_at = NOW() WHERE id = ? LIMIT 1");
    if ($stmtCatalog) {
        $pendingStatus = 'pending';
        $stmtCatalog->bind_param('ssi', $pendingStatus, $messageId, $juego_id);
        $stmtCatalog->execute();
        $stmtCatalog->close();
    }

    admin_packages_redirect($adminPackageBaseUrl . '/' . $juego_id, ['discord_catalog_notice' => 'Se envió ' . $discordPriceCommandText . '. Cuando el relay devuelva la respuesta al listener de catálogo, los precios quedarán disponibles aquí.']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import_discord_catalog_text'])) {
    if (!$hasDiscordCatalog) {
        admin_packages_redirect($adminPackageBaseUrl . '/' . $juego_id, ['discord_catalog_error' => 'Este juego no usa catálogo Discord.']);
    }

    $catalogText = trim((string) ($_POST['discord_catalog_text'] ?? ''));
    if ($catalogText === '') {
        admin_packages_redirect($adminPackageBaseUrl . '/' . $juego_id, ['discord_catalog_error' => 'Pega primero el texto de respuesta de Discord para importar el catálogo.']);
    }

    $parsedCatalogItems = api_discord_parse_catalog_text($catalogText);
    if ($parsedCatalogItems === []) {
        admin_packages_redirect($adminPackageBaseUrl . '/' . $juego_id, ['discord_catalog_error' => 'No se encontraron paquetes ni precios válidos en el texto pegado.']);
    }

    if (!admin_package_save_discord_catalog($mysqli, $juego_id, $parsedCatalogItems, $catalogText, 'ready')) {
        admin_packages_redirect($adminPackageBaseUrl . '/' . $juego_id, ['discord_catalog_error' => 'No se pudo guardar el catálogo Discord para este juego.']);
    }

    admin_packages_redirect($adminPackageBaseUrl . '/' . $juego_id, ['discord_catalog_notice' => 'Se importaron ' . count($parsedCatalogItems) . ' paquetes desde el texto de Discord.']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['package_feature_catalog_action'])) {
    $catalogAction = trim((string) ($_POST['package_feature_catalog_action'] ?? ''));
    $featureId = (int) ($_POST['package_feature_id'] ?? 0);
    $featureName = (string) ($_POST['package_feature_name'] ?? '');
    $featureIcon = (string) ($_POST['package_feature_icon'] ?? 'sparkles');
    $catalogApplyMode = admin_package_normalize_apply_mode((string) ($_POST['package_feature_apply_mode'] ?? ''));
    $catalogAppliedFeatureId = 0;

    if ($catalogAction === 'create') {
        $catalogAppliedFeatureId = package_feature_catalog_find_or_create($mysqli, $featureName, $featureIcon);
    } elseif ($catalogAction === 'update' && $featureId > 0) {
        package_feature_catalog_update($mysqli, $featureId, $featureName, $featureIcon);
        $catalogAppliedFeatureId = $featureId;
    } elseif ($catalogAction === 'delete' && $featureId > 0) {
        package_feature_catalog_delete($mysqli, $featureId);
    }

    if ($catalogAppliedFeatureId > 0 && $catalogApplyMode !== '') {
        package_apply_feature_ids_to_game_packages($mysqli, $juego_id, [$catalogAppliedFeatureId], $catalogApplyMode === 'replace');
    }

    header('Location: ' . $adminPackageBaseUrl . '/' . $juego_id);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_paquete_activo'], $_POST['paquete_id'], $_POST['activo'])) {
    $packageId = intval($_POST['paquete_id']);
    $activeValue = intval($_POST['activo']) === 1 ? 1 : 0;
    if ($packageId > 0) {
        recharge_availability_set_package_active($mysqli, $packageId, $juego_id, $activeValue === 1);
        if (admin_packages_is_ajax_request()) {
            admin_packages_json_response(['ok' => true, 'id' => $packageId, 'activo' => $activeValue]);
        }
    }
    header('Location: ' . $adminPackageBaseUrl . '/' . $juego_id);
    exit;
}

if (isset($_GET['toggle_activo'])) {
    $toggleId = intval($_GET['toggle_activo']);
    if ($toggleId > 0) {
        $nextActive = !recharge_availability_is_package_active($mysqli, $toggleId, $juego_id);
        recharge_availability_set_package_active($mysqli, $toggleId, $juego_id, $nextActive);
        if (admin_packages_is_ajax_request()) {
            admin_packages_json_response(['ok' => true, 'id' => $toggleId, 'activo' => $nextActive ? 1 : 0]);
        }
    }
    header('Location: ' . $adminPackageBaseUrl . '/' . $juego_id);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_orden_paquete'], $_POST['paquete_id'], $_POST['orden'])) {
    $packageId = intval($_POST['paquete_id']);
    $order = max(1, intval($_POST['orden']));
    if ($packageId > 0) {
        $stmtOrder = $mysqli->prepare("UPDATE juego_paquetes SET orden = ? WHERE id = ? AND juego_id = ?");
        $stmtOrder->bind_param('iii', $order, $packageId, $juego_id);
        $stmtOrder->execute();
        $stmtOrder->close();
        if (admin_packages_is_ajax_request()) {
            admin_packages_json_response(['ok' => true, 'id' => $packageId, 'orden' => $order]);
        }
    }
    header('Location: ' . $adminPackageBaseUrl . '/' . $juego_id);
    exit;
}

// Procesar eliminación de paquete (antes de cualquier salida)
if (isset($_GET['eliminar'])) {
    $del_id = intval($_GET['eliminar']);
    // Obtener la ruta de la imagen antes de borrar
    $stmt_img = $mysqli->prepare("SELECT imagen_icono FROM juego_paquetes WHERE id=? AND juego_id=?");
    $stmt_img->bind_param('ii', $del_id, $juego_id);
    $stmt_img->execute();
    $stmt_img->bind_result($img_path);
    $stmt_img->fetch();
    $stmt_img->close();
    $deletedGalleryItems = package_account_sales_delete_gallery($mysqli, $del_id);
    // Borrar el registro
    $stmt = $mysqli->prepare("DELETE FROM juego_paquetes WHERE id=? AND juego_id=?");
    $stmt->bind_param('ii', $del_id, $juego_id);
    $stmt->execute();
    package_delete_feature_assignments($mysqli, $del_id);
    // Borrar la imagen física si existe y no está vacía
    if ($img_path) {
        admin_package_delete_upload((string) $img_path);
    }
    admin_package_delete_gallery_uploads($deletedGalleryItems);
    header('Location: ' . $adminPackageBaseUrl . '/' . $juego_id);
    exit;
}

// Procesar edición de paquete (antes de cualquier salida)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_paquete_id'])) {
    $edit_id = intval($_POST['edit_paquete_id']);
    $edit_nombre = trim($_POST['edit_nombre'] ?? '');
    $edit_clave = trim($_POST['edit_clave'] ?? '');
    $edit_provider = admin_package_normalize_provider_value($_POST['edit_api_provider'] ?? $packageDefaultProvider);
    if (($edit_provider === '' && !empty($packageSourceOptions)) || ($edit_provider !== '' && !isset($packageSourceOptions[$edit_provider]))) {
        admin_packages_redirect($adminPackageBaseUrl . '/' . $juego_id, ['package_error' => 'Selecciona un origen válido para este paquete antes de guardarlo.']);
    }
    $edit_monto_ff = $edit_provider === 'free_fire' ? trim((string) ($_POST['edit_monto_ff'] ?? '')) : '';
    $edit_paquete_api = $edit_provider === 'giftven' ? trim((string) ($_POST['edit_paquete_api'] ?? '')) : '';
    $edit_vender_cuenta = $accountSaleFeatureEnabled && isset($_POST['edit_vender_cuenta']) ? 1 : 0;
    $edit_cuenta_texto = $accountSaleFeatureEnabled
        ? package_account_sales_normalize_text((string) ($_POST['edit_cuenta_texto'] ?? ''))
        : '';
    $edit_cantidad = $edit_provider === 'discord'
        ? api_discord_normalize_catalog_quantity($_POST['edit_cantidad'] ?? '')
        : '1';
    if ($edit_cantidad === '') {
        $edit_cantidad = '1';
    }
    $edit_precio = floatval($_POST['edit_precio'] ?? 0);
    $edit_win_points_reward = max(0, (int) ($_POST['edit_win_points_reward'] ?? 0));
    $edit_activo = isset($_POST['edit_activo']) ? 1 : 0;
    $edit_imagen_icono = admin_package_store_upload($_FILES['edit_imagen_icono'] ?? []);
    $editExistingGalleryFiles = admin_package_normalize_uploaded_file_list($_FILES['edit_existing_account_gallery_replace'] ?? []);
    $editNewGalleryFiles = admin_package_normalize_uploaded_file_list($_FILES['edit_new_account_gallery_image'] ?? []);
    $editGalleryPayload = $accountSaleFeatureEnabled
        ? admin_package_build_account_gallery_payload(
            $_POST['edit_existing_account_gallery_path'] ?? [],
            $_POST['edit_existing_account_gallery_description'] ?? [],
            $_POST['edit_existing_account_gallery_remove'] ?? [],
            $editExistingGalleryFiles,
            $_POST['edit_new_account_gallery_description'] ?? [],
            $editNewGalleryFiles
        )
        : ['items' => [], 'delete_paths' => []];
    if ($edit_provider === 'giftven' && $edit_paquete_api === '') {
        admin_packages_redirect($adminPackageBaseUrl . '/' . $juego_id, ['package_error' => 'Selecciona el producto de TiendaGiftVen para este paquete.']);
    }
    if ($edit_provider === 'free_fire' && $edit_monto_ff === '') {
        admin_packages_redirect($adminPackageBaseUrl . '/' . $juego_id, ['package_error' => 'Selecciona el monto de Free Fire para este paquete.']);
    }
    if ($edit_imagen_icono) {
        $stmt = $mysqli->prepare("UPDATE juego_paquetes SET nombre=?, clave=?, monto_ff=NULLIF(?, ''), paquete_api=NULLIF(?, ''), api_provider=?, vender_cuenta=?, cuenta_texto=NULLIF(?, ''), cantidad=?, precio=?, win_points_reward=?, imagen_icono=?, activo=? WHERE id=?");
        $stmt->bind_param('sssssissdisii', $edit_nombre, $edit_clave, $edit_monto_ff, $edit_paquete_api, $edit_provider, $edit_vender_cuenta, $edit_cuenta_texto, $edit_cantidad, $edit_precio, $edit_win_points_reward, $edit_imagen_icono, $edit_activo, $edit_id);
    } else {
        $stmt = $mysqli->prepare("UPDATE juego_paquetes SET nombre=?, clave=?, monto_ff=NULLIF(?, ''), paquete_api=NULLIF(?, ''), api_provider=?, vender_cuenta=?, cuenta_texto=NULLIF(?, ''), cantidad=?, precio=?, win_points_reward=?, activo=? WHERE id=?");
        $stmt->bind_param('sssssissdiii', $edit_nombre, $edit_clave, $edit_monto_ff, $edit_paquete_api, $edit_provider, $edit_vender_cuenta, $edit_cuenta_texto, $edit_cantidad, $edit_precio, $edit_win_points_reward, $edit_activo, $edit_id);
    }
    $stmt->execute();
    $stmt->close();
    if ($accountSaleFeatureEnabled) {
        package_account_sales_replace_gallery($mysqli, $edit_id, $editGalleryPayload['items']);
        foreach ($editGalleryPayload['delete_paths'] as $deletePath) {
            admin_package_delete_upload($deletePath);
        }
    }
    if ($edit_activo === 1) {
        recharge_availability_set_game_active($mysqli, $juego_id, true);
    }
    $editAssignedFeatureIds = $_POST['edit_assigned_feature_id'] ?? [];
    $editAssignedFeatureNames = $_POST['edit_assigned_feature_name'] ?? [];
    $editAssignedFeatureIcons = $_POST['edit_assigned_feature_icon'] ?? [];
    foreach ($editAssignedFeatureIds as $index => $featureId) {
        $normalizedFeatureId = (int) $featureId;
        if ($normalizedFeatureId <= 0) {
            continue;
        }
        package_feature_catalog_update(
            $mysqli,
            $normalizedFeatureId,
            (string) ($editAssignedFeatureNames[$index] ?? ''),
            (string) ($editAssignedFeatureIcons[$index] ?? 'sparkles')
        );
    }
    $editNewFeatureNames = $_POST['edit_new_feature_name'] ?? [];
    $editNewFeatureIcons = $_POST['edit_new_feature_icon'] ?? [];
    $editNewFeatures = package_feature_pairs_from_request($editNewFeatureNames, $editNewFeatureIcons);
    package_assign_features_to_package(
        $mysqli,
        $edit_id,
        $_POST['edit_package_feature_ids'] ?? [],
        $editNewFeatures
    );
    $editBulkActions = admin_package_merge_bulk_actions(
        admin_package_collect_bulk_feature_ids_from_catalog(
            $_POST['edit_catalog_feature_id'] ?? [],
            $_POST['edit_package_feature_ids'] ?? [],
            $_POST['edit_catalog_feature_apply_mode'] ?? []
        ),
        admin_package_collect_bulk_feature_ids_from_existing($editAssignedFeatureIds, $_POST['edit_assigned_feature_apply_mode'] ?? []),
        admin_package_collect_bulk_feature_ids_from_new($mysqli, $editNewFeatureNames, $editNewFeatureIcons, $_POST['edit_new_feature_apply_mode'] ?? [])
    );
    admin_package_apply_bulk_feature_actions($mysqli, $juego_id, $edit_id, $editBulkActions);
    header('Location: ' . $adminPackageBaseUrl . '/' . $juego_id);
    exit;
}

// Procesar creación de paquete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['nombre'], $_POST['clave'], $_POST['precio'])) {
    $nombre = trim($_POST['nombre']);
    $clave = trim($_POST['clave']);
    $provider = admin_package_normalize_provider_value($_POST['api_provider'] ?? $packageDefaultProvider);
    if (($provider === '' && !empty($packageSourceOptions)) || ($provider !== '' && !isset($packageSourceOptions[$provider]))) {
        admin_packages_redirect($adminPackageBaseUrl . '/' . $juego_id, ['package_error' => 'Selecciona el origen del paquete antes de guardarlo.']);
    }
    $monto_ff = $provider === 'free_fire' ? trim((string) ($_POST['monto_ff'] ?? '')) : '';
    $paquete_api = $provider === 'giftven' ? trim((string) ($_POST['paquete_api'] ?? '')) : '';
    $vender_cuenta = $accountSaleFeatureEnabled && isset($_POST['vender_cuenta']) ? 1 : 0;
    $cuenta_texto = $accountSaleFeatureEnabled
        ? package_account_sales_normalize_text((string) ($_POST['cuenta_texto'] ?? ''))
        : '';
    $cantidad = $provider === 'discord'
        ? api_discord_normalize_catalog_quantity($_POST['cantidad'] ?? '')
        : '1';
    if ($cantidad === '') {
        $cantidad = '1';
    }
    $precio = floatval($_POST['precio']);
    $win_points_reward = max(0, (int) ($_POST['win_points_reward'] ?? $defaultWinPointsReward));
    $activo = isset($_POST['activo']) ? 1 : 0;
    $orden = admin_package_next_order($mysqli, $juego_id);
    $imagen_icono = admin_package_store_upload($_FILES['imagen_icono'] ?? []);
    $newGalleryFiles = admin_package_normalize_uploaded_file_list($_FILES['new_account_gallery_image'] ?? []);
    $newGalleryPayload = $accountSaleFeatureEnabled
        ? admin_package_build_account_gallery_payload([], [], [], [], $_POST['new_account_gallery_description'] ?? [], $newGalleryFiles)
        : ['items' => [], 'delete_paths' => []];
    if ($provider === 'giftven' && $paquete_api === '') {
        admin_packages_redirect($adminPackageBaseUrl . '/' . $juego_id, ['package_error' => 'Selecciona el producto de TiendaGiftVen para este paquete.']);
    }
    if ($provider === 'free_fire' && $monto_ff === '') {
        admin_packages_redirect($adminPackageBaseUrl . '/' . $juego_id, ['package_error' => 'Selecciona el monto de Free Fire para este paquete.']);
    }
    $stmt = $mysqli->prepare("INSERT INTO juego_paquetes (juego_id, nombre, clave, monto_ff, paquete_api, api_provider, vender_cuenta, cuenta_texto, cantidad, precio, win_points_reward, imagen_icono, activo, orden) VALUES (?, ?, ?, NULLIF(?, ''), NULLIF(?, ''), ?, ?, NULLIF(?, ''), ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param('isssssissdisii', $juego_id, $nombre, $clave, $monto_ff, $paquete_api, $provider, $vender_cuenta, $cuenta_texto, $cantidad, $precio, $win_points_reward, $imagen_icono, $activo, $orden);
    $stmt->execute();
    $newPackageId = (int) $mysqli->insert_id;
    $stmt->close();
    if ($accountSaleFeatureEnabled) {
        package_account_sales_replace_gallery($mysqli, $newPackageId, $newGalleryPayload['items']);
    }
    if ($activo === 1) {
        recharge_availability_set_game_active($mysqli, $juego_id, true);
    }
    $newFeatureNames = $_POST['new_feature_name'] ?? [];
    $newFeatureIcons = $_POST['new_feature_icon'] ?? [];
    $newFeatures = package_feature_pairs_from_request($newFeatureNames, $newFeatureIcons);
    package_assign_features_to_package(
        $mysqli,
        $newPackageId,
        $_POST['package_feature_ids'] ?? [],
        $newFeatures
    );
    $createBulkActions = admin_package_merge_bulk_actions(
        admin_package_collect_bulk_feature_ids_from_catalog(
            $_POST['package_catalog_feature_id'] ?? [],
            $_POST['package_feature_ids'] ?? [],
            $_POST['package_feature_apply_mode'] ?? []
        ),
        admin_package_collect_bulk_feature_ids_from_new($mysqli, $newFeatureNames, $newFeatureIcons, $_POST['new_feature_apply_mode'] ?? [])
    );
    admin_package_apply_bulk_feature_actions($mysqli, $juego_id, $newPackageId, $createBulkActions);
    header('Location: ' . $adminPackageBaseUrl . '/' . $juego_id);
    exit;
}

// Listar paquetes
$res = $mysqli->prepare("SELECT * FROM juego_paquetes WHERE juego_id=? ORDER BY CASE WHEN orden IS NULL THEN 1 ELSE 0 END, orden ASC, id ASC");
$res->bind_param('i', $juego_id);
$res->execute();
$result = $res->get_result();
$paquetes = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
$packageFeatureCatalog = package_feature_catalog_all($mysqli);
$packageFeatureIconOptions = package_feature_icon_options();
$packageFeaturesByPackage = package_features_for_packages($mysqli, array_map(static fn (array $package): int => (int) ($package['id'] ?? 0), $paquetes));
$packageAccountGalleryByPackage = package_account_sales_fetch_gallery_map($mysqli, array_map(static fn (array $package): int => (int) ($package['id'] ?? 0), $paquetes));
$packageFeatureIconOptionsHtml = admin_package_feature_icon_options_html($packageFeatureIconOptions);
$activePackageCount = count(array_filter($paquetes, static fn (array $package): bool => !isset($package['activo']) || !empty($package['activo'])));
$inactivePackageCount = count($paquetes) - $activePackageCount;
$currentPackageTab = 'active';

// Incluir header
include '../includes/header.php';
?>
<main class="container py-4">
    <h2 class="mb-4 text-neon">Paquetes de <?= htmlspecialchars($juego['nombre'] ?? 'Juego') ?></h2>
    <?php if ($hasDiscordCatalog): ?>
        <div class="rounded-4 p-3 mb-4" style="background:#101826;border:1px solid rgba(34,211,238,0.18);">
            <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3">
                <div>
                    <div class="text-neon fw-semibold">Sincronización de catálogo Discord</div>
                    <div class="small" style="color:#8be9fd;">
                        <?php if ($discordPriceCommandText !== ''): ?>
                            Comando de precios: <?= htmlspecialchars($discordPriceCommandText, ENT_QUOTES, 'UTF-8') ?>.
                        <?php else: ?>
                            Este juego no tiene un comando de precios vinculado en el catálogo Discord.
                        <?php endif; ?>
                    </div>
                    <?php if ($discordCatalogUpdatedAt !== ''): ?>
                        <div class="small mt-1" style="color:#8be9fd;">Última sincronización: <?= htmlspecialchars($discordCatalogUpdatedAt, ENT_QUOTES, 'UTF-8') ?></div>
                    <?php endif; ?>
                    <?php if ($discordCatalogMessageId !== ''): ?>
                        <div class="small mt-1" style="color:#8be9fd;">Message ID correlacionado: <?= htmlspecialchars($discordCatalogMessageId, ENT_QUOTES, 'UTF-8') ?></div>
                    <?php endif; ?>
                </div>
                <form method="post" class="d-flex flex-column flex-sm-row gap-2 align-items-stretch align-items-sm-center mb-0">
                    <input type="hidden" name="sync_discord_catalog" value="1">
                    <button type="submit" class="btn btn-info fw-bold" <?= $discordPriceCommandText === '' ? 'disabled' : '' ?>>Consultar precios en Discord</button>
                </form>
            </div>
            <?php if (!empty($discordCatalogItems)): ?>
                <div class="small mt-2" style="color:#8be9fd;">Hay <?= count($discordCatalogItems) ?> paquetes disponibles para reutilizar en el formulario.</div>
            <?php elseif ($discordCatalogStatus === 'pending'): ?>
                <div class="small mt-2" style="color:#fbbf24;">La consulta ya fue enviada. Falta que el relay publique la respuesta en el listener de catálogo.</div>
            <?php endif; ?>

            <div class="row g-3 mt-1">
                <div class="col-lg-7">
                    <div class="rounded-4 p-3 h-100" style="background:#0f172a;border:1px solid rgba(34,211,238,0.16);">
                        <div class="text-neon fw-semibold mb-2">Listado de paquetes y precios del juego</div>
                        <?php if (!empty($discordCatalogItems)): ?>
                            <div class="table-responsive">
                                <table class="table table-dark table-sm align-middle mb-0" style="--bs-table-bg:transparent;">
                                    <thead>
                                        <tr>
                                            <th style="color:#67e8f9;">Paquete</th>
                                            <th style="color:#67e8f9;">Cantidad</th>
                                            <th style="color:#67e8f9;">Precio USD</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($discordCatalogItems as $catalogItem): ?>
                                            <tr>
                                                <td style="color:#d8fbff;"><?= htmlspecialchars((string) ($catalogItem['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                                <td style="color:#d8fbff;"><?= htmlspecialchars(admin_package_format_catalog_quantity($catalogItem), ENT_QUOTES, 'UTF-8') ?></td>
                                                <td style="color:#d8fbff;">$<?= htmlspecialchars((string) ($catalogItem['price_usd'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="small" style="color:#8be9fd;">Aún no hay paquetes guardados para este juego. Puedes importarlos pegando el texto de respuesta de Discord en el bloque de la derecha.</div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="col-lg-5">
                    <form method="post" class="rounded-4 p-3 h-100" style="background:#0f172a;border:1px solid rgba(34,211,238,0.16);">
                        <input type="hidden" name="import_discord_catalog_text" value="1">
                        <div class="text-neon fw-semibold mb-2">Importar catálogo desde texto</div>
                        <div class="small mb-2" style="color:#8be9fd;">Pega aquí la respuesta completa del bot en Discord para este juego. Se guardará como catálogo del juego seleccionado.</div>
                        <textarea name="discord_catalog_text" rows="10" class="form-control mb-3" style="background:#222c3a;color:#22d3ee;border:1px solid #22d3ee;" placeholder="Ejemplo:
110 💎
$0.92
341 💎
$2.90
Semanal Básica 💎
$0.41"><?= htmlspecialchars($discordCatalogRaw, ENT_QUOTES, 'UTF-8') ?></textarea>
                        <button type="submit" class="btn btn-outline-info w-100 fw-bold">Guardar listado para este juego</button>
                    </form>
                </div>
            </div>
        </div>
    <?php endif; ?>
    <form method="post" enctype="multipart/form-data" class="row g-3 mb-4" data-package-source-form="1" style="background:#181f2a; border-radius:16px; border:2px solid #22d3ee; box-shadow:0 0 24px #22d3ee33; padding:2rem;">
        <?php if (!$packageSourceSelectionEnabled && $packageDefaultProvider !== ''): ?>
            <input type="hidden" name="api_provider" value="<?= htmlspecialchars($packageDefaultProvider, ENT_QUOTES, 'UTF-8') ?>">
        <?php endif; ?>
        <div class="col-md-6">
            <label class="form-label text-neon">Nombre del paquete</label>
            <input type="text" name="nombre" placeholder="Nombre del paquete" required class="form-control" style="background:#222c3a; color:#22d3ee; border:1px solid #22d3ee;" data-discord-catalog-field="name">
        </div>
        <div class="col-md-6">
            <label class="form-label text-neon">Clave interna</label>
            <input type="text" name="clave" placeholder="Clave" required class="form-control" style="background:#222c3a; color:#22d3ee; border:1px solid #22d3ee;" data-discord-catalog-field="key">
        </div>
        <?php if ($packageSourceSelectionEnabled): ?>
            <div class="col-12">
                <div class="rounded-4 p-3" style="background:#101826;border:1px solid rgba(34,211,238,0.18);">
                    <div class="text-neon fw-semibold">Origen del paquete</div>
                    <div class="small mt-2" style="color:#8be9fd;">Selecciona una sola API por paquete. Cuando elijas una, los demás paneles quedarán bloqueados hasta limpiar la selección.</div>
                    <div class="d-flex flex-wrap gap-3 mt-3">
                        <?php foreach ($packageSourceOptions as $sourceKey => $sourceLabel): ?>
                            <label class="d-inline-flex align-items-center gap-2 rounded-pill px-3 py-2" style="background:rgba(15,23,42,0.92);border:1px solid rgba(34,211,238,0.28);color:#d8fbff;cursor:pointer;">
                                <input type="radio" name="api_provider" value="<?= htmlspecialchars($sourceKey, ENT_QUOTES, 'UTF-8') ?>" class="form-check-input mt-0" data-package-source-radio>
                                <span><?= htmlspecialchars($sourceLabel, ENT_QUOTES, 'UTF-8') ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="btn btn-outline-info btn-sm mt-3 d-none" data-package-source-clear>Limpiar selección</button>
                </div>
            </div>
        <?php endif; ?>
        <?php if ($hasGiftVenCatalog): ?>
            <div class="col-md-6" data-package-source-panel="giftven">
                <label class="form-label text-neon">Producto API</label>
                <select name="paquete_api" <?= $packageSourceSelectionEnabled ? 'data-package-source-required="1"' : 'required' ?> class="form-select" style="background:#222c3a; color:#22d3ee; border:1px solid #22d3ee;">
                    <option value="">Selecciona un producto API</option>
                    <?php foreach ($apiProducts as $apiProduct): ?>
                        <option value="<?= (int) ($apiProduct['id'] ?? 0) ?>"><?= htmlspecialchars(recargas_api_product_label($apiProduct), ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text mt-2" style="color:#8be9fd;">Categoría API vinculada: <?= htmlspecialchars($juegoCategoriaApi, ENT_QUOTES, 'UTF-8') ?></div>
            </div>
        <?php endif; ?>
        <?php if ($hasDiscordCatalog): ?>
            <div class="col-md-6" data-package-source-panel="discord">
                <label class="form-label text-neon">Cantidad / paquete Discord</label>
                <input type="text" name="cantidad" placeholder="Ej: 86, 257+40 o Pase semanal" <?= $packageSourceSelectionEnabled ? 'data-package-source-required="1"' : 'required' ?> class="form-control" style="background:#222c3a; color:#22d3ee; border:1px solid #22d3ee;" value="1" maxlength="80" data-discord-catalog-field="quantity">
                <div class="form-text mt-2" style="color:#8be9fd;">Registra aquí el valor exacto que debe reemplazar <code>{cantidad}</code> en el comando de recarga de Discord.</div>
            </div>
            <div class="col-md-6" data-package-source-panel="discord">
                <?php if (!empty($discordCatalogItems)): ?>
                    <label class="form-label text-neon">Paquete a recargar</label>
                    <select class="form-select mb-3" data-discord-catalog-select style="background:#222c3a; color:#22d3ee; border:1px solid #22d3ee;">
                        <option value="">Selecciona un paquete del juego</option>
                        <?php foreach ($discordCatalogItems as $catalogItem): ?>
                            <option
                                value="<?= htmlspecialchars((string) ($catalogItem['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                data-name="<?= htmlspecialchars((string) ($catalogItem['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                data-key="<?= htmlspecialchars((string) ($catalogItem['quantity'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                data-quantity="<?= htmlspecialchars((string) ($catalogItem['quantity'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                data-price="<?= htmlspecialchars((string) ($catalogItem['price_usd'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                            ><?= htmlspecialchars(api_discord_catalog_item_label($catalogItem), ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php else: ?>
                    <label class="form-label text-neon">Comando de precio de referencia</label>
                    <input type="text" class="form-control" value="<?= htmlspecialchars($discordPriceCommandText !== '' ? $discordPriceCommandText : 'Sin comando de precio vinculado', ENT_QUOTES, 'UTF-8') ?>" readonly style="background:#222c3a; color:#22d3ee; border:1px solid #22d3ee;">
                <?php endif; ?>
                <div class="form-text mt-2" style="color:#8be9fd;">
                    <?php if (!empty($discordCatalogItems)): ?>
                        Selecciona aquí el paquete del juego que quieres registrar. Esa selección llenará nombre, cantidad y precio para que la recarga use exactamente ese paquete.
                    <?php elseif ($discordPriceCommandText !== ''): ?>
                        Puedes disparar la consulta desde el bloque superior y luego cargar aquí el listado del juego para seleccionar un paquete concreto.
                    <?php else: ?>
                        Este juego usa Discord, pero no tiene un comando de precio vinculado en el catálogo actual.
                    <?php endif; ?>
                </div>
                <?php if ($discordPriceCommandText !== ''): ?>
                    <div class="form-text mt-2" style="color:#8be9fd;">Comando usado para consultar precios: <?= htmlspecialchars($discordPriceCommandText, ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>
                <?php if ($discordTopupCommandText !== ''): ?>
                    <div class="form-text mt-2" style="color:#8be9fd;">Comando de recarga configurado: <?= htmlspecialchars($discordTopupCommandText, ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>
                <?php if ($discordCatalogRaw !== ''): ?>
                    <div class="form-text mt-2" style="color:#8be9fd;">Último texto crudo recibido: <?= htmlspecialchars(mb_strimwidth($discordCatalogRaw, 0, 220, '...'), ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <?php if ($usesLegacyFreeFire): ?>
            <div class="col-md-6" data-package-source-panel="free_fire">
                <label class="form-label text-neon">Montos (API)</label>
                <select name="monto_ff" <?= $packageSourceSelectionEnabled ? 'data-package-source-required="1"' : 'required' ?> class="form-select" style="background:#222c3a; color:#22d3ee; border:1px solid #22d3ee;">
                    <option value="">Selecciona un monto API</option>
                    <?php foreach ($freeFireApiOptions as $amount => $option): ?>
                        <option value="<?= htmlspecialchars($amount, ENT_QUOTES, 'UTF-8') ?>">&#128142; <?= htmlspecialchars($option['suggested_name'], ENT_QUOTES, 'UTF-8') ?> - <?= htmlspecialchars($option['diamonds'], ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>
        <div class="col-md-4">
            <label class="form-label text-neon">Precio USD</label>
            <input type="number" step="0.01" min="0" name="precio" placeholder="Precio" required class="form-control" style="background:#222c3a; color:#22d3ee; border:1px solid #22d3ee;" data-discord-catalog-field="price">
        </div>
        <div class="col-md-4">
            <label class="form-label text-neon"><?= htmlspecialchars($winPointsName, ENT_QUOTES, 'UTF-8') ?> a ganar</label>
            <input type="number" min="0" name="win_points_reward" value="<?= $defaultWinPointsReward ?>" class="form-control" style="background:#222c3a; color:#22d3ee; border:1px solid #22d3ee;">
        </div>
        <div class="col-md-4">
            <label class="form-label text-neon">Icono del paquete</label>
            <input type="file" name="imagen_icono" accept="image/*" class="form-control" style="background:#222c3a; color:#22d3ee; border:1px solid #22d3ee;" onchange="previewNuevoPaqueteImg(event)">
        </div>
        <?php if ($accountSaleFeatureEnabled): ?>
        <div class="col-12">
            <div class="rounded-4 p-3" data-account-sale-scope style="background:#101826;border:1px solid rgba(34,211,238,0.18);">
                <div class="form-check form-switch mb-3">
                    <input type="checkbox" name="vender_cuenta" class="form-check-input" id="packageSellAccountCheck" data-account-sale-toggle>
                    <label class="form-check-label text-neon" for="packageSellAccountCheck">Vender Cuenta</label>
                </div>
                <div class="small mb-3" style="color:#8be9fd;">Si activas este paquete como venta de cuenta, el checkout entregará los datos guardados aquí y no ejecutará la recarga automática del juego aunque el paquete tenga API configurada.</div>
                <div data-account-sale-config class="d-none">
                    <div class="mb-3">
                        <label class="form-label text-neon">Datos de la cuenta</label>
                        <textarea name="cuenta_texto" rows="6" class="form-control" data-account-sale-textarea style="background:#222c3a;color:#22d3ee;border:1px solid #22d3ee;" placeholder="Ej: correo, contraseña, región, detalles de acceso, advertencias y pasos para el cliente."></textarea>
                    </div>
                    <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-2 mb-3">
                        <div>
                            <div class="text-neon fw-semibold">Galería de la cuenta</div>
                            <div class="small" style="color:#8be9fd;">Agrega imágenes opcionales con una descripción corta para el botón Ver Más.</div>
                        </div>
                        <button type="button" class="btn btn-outline-info btn-sm" onclick="window.addPackageAccountGalleryRow('new-account-gallery-rows', 'new_account_gallery_image[]', 'new_account_gallery_description[]')">Agregar imagen</button>
                    </div>
                    <div id="new-account-gallery-rows" class="d-grid gap-2"></div>
                </div>
            </div>
        </div>
        <?php endif; ?>
        <div class="col-12">
            <div class="rounded-4 p-3" style="background:#101826;border:1px solid rgba(34,211,238,0.18);">
                <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-2 mb-3">
                    <div>
                        <div class="text-neon fw-semibold">Caracteristicas reutilizables</div>
                        <div class="small" style="color:#8be9fd;">Selecciona caracteristicas ya creadas o agrega nuevas sin salir del formulario.</div>
                    </div>
                    <button type="button" class="btn btn-outline-info btn-sm" onclick="window.addPackageFeatureRow('package-new-features', 'new_feature_name[]', 'new_feature_icon[]', <?= htmlspecialchars(json_encode($packageFeatureIconOptionsHtml, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8') ?>, 'new_feature_apply_mode[]')">Nueva caracteristica</button>
                </div>
                <div class="d-flex flex-wrap gap-2 mb-3">
                    <?php if (!empty($packageFeatureCatalog)): ?>
                        <?php foreach ($packageFeatureCatalog as $feature): ?>
                            <div class="d-inline-flex flex-wrap align-items-center gap-2" data-package-feature-apply-scope>
                                <input type="hidden" name="package_catalog_feature_id[]" value="<?= (int) ($feature['id'] ?? 0) ?>">
                                <input type="hidden" name="package_feature_apply_mode[]" value="" data-package-feature-apply-input>
                                <label class="badge rounded-pill d-inline-flex align-items-center gap-2 px-3 py-2" style="cursor:pointer;background:rgba(15,23,42,0.92);border:1px solid rgba(34,211,238,0.28);color:#d8fbff;">
                                    <input type="checkbox" name="package_feature_ids[]" value="<?= (int) ($feature['id'] ?? 0) ?>" class="form-check-input mt-0 me-1" style="float:none;" data-package-feature-select-checkbox>
                                    <?= package_feature_render_icon((string) ($feature['icon'] ?? 'sparkles'), 'package-feature-badge-icon') ?>
                                    <span><?= htmlspecialchars((string) ($feature['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
                                </label>
                                <button type="button" class="btn btn-outline-warning btn-sm" data-package-feature-apply-button onclick="window.openPackageFeatureApplyModal(this)">Aplicar a todos</button>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <span class="small" style="color:#8be9fd;">Aun no hay caracteristicas guardadas en el catalogo.</span>
                    <?php endif; ?>
                </div>
                <div class="small mb-3" style="color:#8be9fd;">Cada caracteristica seleccionada o nueva puede marcarse con Aplicar a todos para copiarla al resto de paquetes de este juego al guardar.</div>
                <div id="package-new-features" class="d-grid gap-2"></div>
            </div>
        </div>
        <div class="col-12">
            <div class="form-check mt-2">
                <input type="checkbox" name="activo" class="form-check-input" id="paqueteActivoCheck" checked>
                <label class="form-check-label text-neon" for="paqueteActivoCheck">Paquete activo / publicado</label>
            </div>
        </div>
        <div class="col-12 text-center">
            <img id="preview-nuevo-paquete-img" src="#" alt="Previsualización" style="display:none;max-width:120px;max-height:120px;border-radius:0.75rem;box-shadow:0 0 0.5rem #22d3ee55;border:2px solid #22d3ee;background:#222c3a;" />
        </div>
        <div class="col-12">
            <button type="submit" class="btn neon-btn-info w-100">Agregar paquete</button>
        </div>
    </form>
    <div class="mb-4 rounded-4 p-4" style="background:#181f2a;border:1px solid rgba(34,211,238,0.18);box-shadow:0 0 20px rgba(34,211,238,0.08);">
        <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-2 mb-3">
            <div>
                <h3 class="h5 text-neon mb-1">Catalogo de caracteristicas</h3>
                <p class="mb-0 small" style="color:#8be9fd;">Edita el nombre e icono de cada caracteristica para reutilizarla en varios paquetes.</p>
            </div>
        </div>
        <form method="post" class="row g-3 align-items-end mb-4">
            <input type="hidden" name="package_feature_catalog_action" value="create">
            <div class="col-md-4">
                <label class="form-label text-neon">Icono</label>
                <div class="d-flex align-items-center gap-2" data-package-feature-editor>
                    <select name="package_feature_icon" data-package-feature-icon-select class="form-select" style="background:#222c3a;color:#22d3ee;border:1px solid #22d3ee;"><?= $packageFeatureIconOptionsHtml ?></select>
                    <span data-package-feature-icon-preview class="d-inline-flex align-items-center justify-content-center rounded-3 flex-shrink-0" style="width:44px;height:44px;background:#0f172a;border:1px solid rgba(34,211,238,0.22);color:#67e8f9;"><?= package_feature_render_icon('sparkles') ?></span>
                </div>
            </div>
            <div class="col-md-5">
                <label class="form-label text-neon">Nombre</label>
                <input type="text" name="package_feature_name" class="form-control" required placeholder="Ej: Entrega inmediata" style="background:#222c3a;color:#22d3ee;border:1px solid #22d3ee;">
            </div>
            <div class="col-md-2" data-package-feature-apply-scope>
                <input type="hidden" name="package_feature_apply_mode" value="" data-package-feature-apply-input>
                <label class="form-label text-neon">Aplicacion</label>
                <button type="button" class="btn btn-outline-warning w-100" data-package-feature-apply-button onclick="window.openPackageFeatureApplyModal(this)">Aplicar a todos</button>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn neon-btn-info w-100">Guardar en catalogo</button>
            </div>
        </form>
        <div class="d-grid gap-3">
            <?php foreach ($packageFeatureCatalog as $feature): ?>
                <form method="post" class="row g-2 align-items-center rounded-4 p-3" style="background:#101826;border:1px solid rgba(34,211,238,0.14);">
                    <input type="hidden" name="package_feature_id" value="<?= (int) ($feature['id'] ?? 0) ?>">
                    <div class="col-md-3">
                        <label class="form-label text-neon small mb-1">Icono</label>
                        <div class="d-flex align-items-center gap-2" data-package-feature-editor>
                            <select name="package_feature_icon" data-package-feature-icon-select class="form-select" style="background:#222c3a;color:#22d3ee;border:1px solid #22d3ee;"><?= admin_package_feature_icon_options_html($packageFeatureIconOptions, (string) ($feature['icon'] ?? 'sparkles')) ?></select>
                            <span data-package-feature-icon-preview class="d-inline-flex align-items-center justify-content-center rounded-3 flex-shrink-0" style="width:44px;height:44px;background:#0f172a;border:1px solid rgba(34,211,238,0.22);color:#67e8f9;"><?= package_feature_render_icon((string) ($feature['icon'] ?? 'sparkles')) ?></span>
                        </div>
                    </div>
                    <div class="col-md-5">
                        <label class="form-label text-neon small mb-1">Nombre</label>
                        <input type="text" name="package_feature_name" value="<?= htmlspecialchars((string) ($feature['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" class="form-control" required style="background:#222c3a;color:#22d3ee;border:1px solid #22d3ee;">
                    </div>
                    <div class="col-md-2 text-center">
                        <span class="badge rounded-pill d-inline-flex align-items-center gap-2 px-3 py-2" style="background:rgba(15,23,42,0.92);border:1px solid rgba(34,211,238,0.28);color:#d8fbff;">
                            <?= package_feature_render_icon((string) ($feature['icon'] ?? 'sparkles'), 'package-feature-badge-icon') ?>
                            <span>ID <?= (int) ($feature['id'] ?? 0) ?></span>
                        </span>
                    </div>
                    <div class="col-md-2 d-flex gap-2">
                        <button type="submit" name="package_feature_catalog_action" value="update" class="btn neon-btn-info btn-sm flex-fill">Actualizar</button>
                        <button type="submit" name="package_feature_catalog_action" value="delete" class="btn btn-danger btn-sm flex-fill" onclick="return confirm('¿Eliminar esta caracteristica del catalogo?')">Borrar</button>
                    </div>
                </form>
            <?php endforeach; ?>
        </div>
    </div>
    <?php if ($packageError !== ''): ?>
        <div class="alert alert-danger mb-4"><?= htmlspecialchars($packageError, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <?php if ($hasGiftVenCatalog && $apiProductsError !== null): ?>
        <div class="alert alert-warning mb-4">No se pudieron cargar los productos de la categoría API: <?= htmlspecialchars($apiProductsError, ENT_QUOTES, 'UTF-8') ?></div>
    <?php elseif ($hasGiftVenCatalog && empty($apiProducts)): ?>
        <div class="alert alert-warning mb-4">No hay productos disponibles en la API para la categoría <?= htmlspecialchars($juegoCategoriaApi, ENT_QUOTES, 'UTF-8') ?>.</div>
    <?php endif; ?>
    <?php if ($discordCatalogError !== ''): ?>
        <div class="alert alert-danger mb-4"><?= htmlspecialchars($discordCatalogError, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <?php if ($discordCatalogNotice !== ''): ?>
        <div class="alert alert-info mb-4"><?= htmlspecialchars($discordCatalogNotice, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <?php if ($hasDiscordCatalog && $discordCatalogStatus === 'pending' && $discordCatalogNotice === ''): ?>
        <div class="alert alert-warning mb-4">La consulta de precios sigue pendiente. Cuando el relay llame a <code>action=discord_catalog_listener</code>, el catálogo se actualizará aquí.</div>
    <?php endif; ?>
    <div class="d-flex flex-wrap gap-2 mb-3">
        <button type="button" class="btn <?= $currentPackageTab === 'active' ? 'btn-info' : 'btn-outline-info' ?> fw-bold js-package-tab-btn" data-package-tab="active" onclick="window.filterAdminPackagesByClass('activo'); return false;">Activos <span data-package-tab-count="active"><?= $activePackageCount ?></span></button>
        <button type="button" class="btn <?= $currentPackageTab === 'inactive' ? 'btn-info' : 'btn-outline-info' ?> fw-bold js-package-tab-btn" data-package-tab="inactive" onclick="window.filterAdminPackagesByClass('inactivo'); return false;">Inactivos <span data-package-tab-count="inactive"><?= $inactivePackageCount ?></span></button>
    </div>
    <div class="table-responsive d-none d-md-block">
        <table class="table table-dark table-bordered align-middle" style="border:2px solid #22d3ee;">
            <thead>
                <tr>
                    <th style="color:#22d3ee; background:#181f2a;">Icono</th>
                    <th style="color:#22d3ee; background:#181f2a;">Nombre</th>
                    <th style="color:#22d3ee; background:#181f2a;">Clave</th>
                    <th style="color:#22d3ee; background:#181f2a;">Orden</th>
                    <?php if ($packageSourceSelectionEnabled): ?>
                        <th style="color:#22d3ee; background:#181f2a;">Origen</th>
                        <th style="color:#22d3ee; background:#181f2a;">Referencia API</th>
                    <?php elseif ($usesApiCatalog): ?>
                        <th style="color:#22d3ee; background:#181f2a;">Producto API</th>
                    <?php elseif ($usesLegacyFreeFire): ?>
                        <th style="color:#22d3ee; background:#181f2a;">Monto FF</th>
                    <?php endif; ?>
                    <th style="color:#22d3ee; background:#181f2a;">Activo</th>
                    <th style="color:#22d3ee; background:#181f2a;">Precio</th>
                    <th style="color:#22d3ee; background:#181f2a;"><?= htmlspecialchars($winPointsName, ENT_QUOTES, 'UTF-8') ?></th>
                    <th style="color:#22d3ee; background:#181f2a;">Acciones</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($paquetes as $p): ?>
                <?php $packageFeatures = $packageFeaturesByPackage[(int) ($p['id'] ?? 0)] ?? []; ?>
                <?php $packageGalleryItems = $packageAccountGalleryByPackage[(int) ($p['id'] ?? 0)] ?? []; ?>
                <?php $packageSellsAccount = (int) ($p['vender_cuenta'] ?? 0) === 1; ?>
                <?php $packageIsActive = !isset($p['activo']) || !empty($p['activo']); ?>
                <?php $packageProvider = admin_package_resolve_provider($p, $juego, $discordApiEnabled); ?>
                <?php $packageProviderReference = admin_package_provider_reference_text($packageProvider, $p, $apiProductsById); ?>
                <tr class="js-package-record js-package-filterable <?= $packageIsActive ? 'activo' : 'inactivo' ?>" data-package-context="desktop" data-package-id="<?= (int) ($p['id'] ?? 0) ?>" style="background:#181f2a; color:#fff;<?= (($currentPackageTab === 'active' && !$packageIsActive) || ($currentPackageTab === 'inactive' && $packageIsActive)) ? 'display:none;' : '' ?>">
                    <td style="background:#181f2a;">
                        <?php if (!empty($p['imagen_icono'])): ?>
                            <img src="/<?= htmlspecialchars($p['imagen_icono']) ?>" alt="icono" class="rounded img-thumbnail" style="max-height:48px;max-width:48px;box-shadow:0 0 8px #22d3ee; border:2px solid #22d3ee; background:#222c3a;">
                        <?php elseif (!empty($juego['imagen_paquete'])): ?>
                            <img src="/<?= htmlspecialchars($juego['imagen_paquete']) ?>" alt="icono" class="rounded img-thumbnail" style="max-height:48px;max-width:48px;box-shadow:0 0 8px #22d3ee; border:2px solid #22d3ee; background:#222c3a;">
                        <?php else: ?>
                            <span class="fst-italic text-secondary">Sin imagen</span>
                        <?php endif; ?>
                    </td>
                    <td class="fw-semibold text-neon" style="background:#181f2a; color:#22d3ee;">
                        <div><?= htmlspecialchars($p['nombre']) ?></div>
                        <?php if ($packageSourceSelectionEnabled): ?>
                            <div class="small mt-2"><span style="display:inline-flex;align-items:center;gap:0.35rem;padding:0.2rem 0.55rem;border-radius:999px;border:1px solid rgba(34,211,238,0.35);background:rgba(15,23,42,0.92);color:#d8fbff;font-weight:700;letter-spacing:0.03em;"><?= htmlspecialchars(admin_package_provider_label($packageProvider), ENT_QUOTES, 'UTF-8') ?></span></div>
                        <?php endif; ?>
                        <?php if ($packageSellsAccount): ?>
                            <div class="d-flex flex-wrap align-items-center gap-2 mt-2">
                                <span class="badge rounded-pill px-3 py-2" style="background:rgba(34,211,238,0.14);border:1px solid rgba(34,211,238,0.28);color:#d8fbff;">Vender Cuenta</span>
                                <span class="small" style="color:#8be9fd;">Galería: <?= count($packageGalleryItems) ?> imágenes</span>
                            </div>
                        <?php endif; ?>
                        <?= admin_package_feature_badges_html($packageFeatures) ?>
                    </td>
                    <td style="background:#181f2a; color:#fff;"><?= htmlspecialchars($p['clave']) ?></td>
                    <td class="text-center" style="background:#181f2a;">
                        <form method="post" action="<?= htmlspecialchars($adminPackageBaseUrl, ENT_QUOTES, 'UTF-8') ?>/<?= $juego_id ?>" class="d-inline-flex align-items-center gap-2 m-0 js-ajax-order-form">
                            <input type="hidden" name="ajax" value="1">
                            <input type="hidden" name="update_orden_paquete" value="1">
                            <input type="hidden" name="paquete_id" value="<?= (int) $p['id'] ?>">
                            <input type="number" name="orden" min="1" value="<?= max(1, (int) ($p['orden'] ?? 0)) ?>" class="form-control form-control-sm text-center js-ajax-order-input" style="width:84px;background:#222c3a;color:#22d3ee;border:1px solid #22d3ee;" data-last-value="<?= max(1, (int) ($p['orden'] ?? 0)) ?>" onchange="window.adminPackageOrderChange(this)">
                        </form>
                    </td>
                    <?php if ($packageSourceSelectionEnabled): ?>
                        <td style="background:#181f2a; color:#fff;"><?= htmlspecialchars(admin_package_provider_label($packageProvider), ENT_QUOTES, 'UTF-8') ?></td>
                        <td style="background:#181f2a; color:#fff;"><?= htmlspecialchars($packageProviderReference, ENT_QUOTES, 'UTF-8') ?></td>
                    <?php elseif ($usesApiCatalog): ?>
                        <td style="background:#181f2a; color:#fff;"><?= htmlspecialchars($packageProviderReference, ENT_QUOTES, 'UTF-8') ?></td>
                    <?php elseif ($usesLegacyFreeFire): ?>
                        <td style="background:#181f2a; color:#fff;"><?= htmlspecialchars($packageProviderReference, ENT_QUOTES, 'UTF-8') ?></td>
                    <?php endif; ?>
                    <td class="text-center" style="background:#181f2a;">
                        <form method="post" action="<?= htmlspecialchars($adminPackageBaseUrl, ENT_QUOTES, 'UTF-8') ?>/<?= $juego_id ?>" class="m-0 d-inline-block js-ajax-toggle-form">
                            <input type="hidden" name="ajax" value="1">
                            <input type="hidden" name="toggle_paquete_activo" value="1">
                            <input type="hidden" name="paquete_id" value="<?= (int) $p['id'] ?>">
                            <input type="hidden" name="activo" value="<?= !isset($p['activo']) || !empty($p['activo']) ? '1' : '0' ?>" class="js-ajax-toggle-value">
                            <div class="form-check form-switch d-inline-flex justify-content-center mb-0">
                                <input class="form-check-input js-ajax-toggle-input" type="checkbox" <?= !isset($p['activo']) || !empty($p['activo']) ? 'checked' : '' ?> aria-label="Activar o desactivar paquete <?= htmlspecialchars($p['nombre'], ENT_QUOTES, 'UTF-8') ?>" onchange="window.adminPackageToggle(this)">
                            </div>
                        </form>
                    </td>
                    <td class="text-neon" style="background:#181f2a; color:#22d3ee;">$<?= number_format($p['precio'], 2) ?></td>
                    <td style="background:#181f2a; color:#fff;"><?= (int) ($p['win_points_reward'] ?? 0) ?></td>
                    <td style="background:#181f2a;" class="text-nowrap">
                        <a href="<?= htmlspecialchars($adminPackageBaseUrl, ENT_QUOTES, 'UTF-8') ?>/<?= $juego_id ?>?editar=<?= $p['id'] ?>" class="btn neon-btn-info btn-sm me-2">Editar</a>
                        <a href="<?= htmlspecialchars($adminPackageBaseUrl, ENT_QUOTES, 'UTF-8') ?>/<?= $juego_id ?>?eliminar=<?= $p['id'] ?>" class="btn btn-danger btn-sm" onclick="return confirm('¿Eliminar este paquete?')">Eliminar</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <!-- Cards móvil -->
    <div class="d-md-none">
        <div class="row gy-4">
            <?php foreach ($paquetes as $p): ?>
            <?php $packageFeatures = $packageFeaturesByPackage[(int) ($p['id'] ?? 0)] ?? []; ?>
            <?php $packageGalleryItems = $packageAccountGalleryByPackage[(int) ($p['id'] ?? 0)] ?? []; ?>
            <?php $packageSellsAccount = (int) ($p['vender_cuenta'] ?? 0) === 1; ?>
            <?php $packageIsActive = !isset($p['activo']) || !empty($p['activo']); ?>
            <?php $packageProvider = admin_package_resolve_provider($p, $juego, $discordApiEnabled); ?>
            <?php $packageProviderReference = admin_package_provider_reference_text($packageProvider, $p, $apiProductsById); ?>
            <div class="col-12 js-package-record js-package-filterable <?= $packageIsActive ? 'activo' : 'inactivo' ?>" data-package-context="mobile" data-package-id="<?= (int) ($p['id'] ?? 0) ?>" style="<?= (($currentPackageTab === 'active' && !$packageIsActive) || ($currentPackageTab === 'inactive' && $packageIsActive)) ? 'display:none;' : '' ?>">
                <div class="card neon-card p-3" style="background:#181f2a; border:2px solid #22d3ee; box-shadow:0 0 16px #22d3ee,0 0 4px #2dd4bf; color:#22d3ee;">
                    <div class="d-flex align-items-center mb-2">
                        <?php if (!empty($p['imagen_icono'])): ?>
                            <img src="/<?= htmlspecialchars($p['imagen_icono']) ?>" alt="icono" class="rounded img-thumbnail me-3" style="max-height:56px;max-width:56px;box-shadow:0 0 8px #22d3ee; border:2px solid #22d3ee; background:#222c3a;">
                        <?php elseif (!empty($juego['imagen_paquete'])): ?>
                            <img src="/<?= htmlspecialchars($juego['imagen_paquete']) ?>" alt="icono" class="rounded img-thumbnail me-3" style="max-height:56px;max-width:56px;box-shadow:0 0 8px #22d3ee; border:2px solid #22d3ee; background:#222c3a;">
                        <?php else: ?>
                            <span class="fst-italic text-secondary">Sin imagen</span>
                        <?php endif; ?>
                        <div>
                            <div class="fw-bold text-neon" style="font-size:1.1rem; color:#22d3ee;"><?= htmlspecialchars($p['nombre']) ?></div>
                            <?php if ($packageSourceSelectionEnabled): ?>
                                <div class="small mt-1" style="color:#b2f6ff;">Origen: <?= htmlspecialchars(admin_package_provider_label($packageProvider), ENT_QUOTES, 'UTF-8') ?></div>
                            <?php endif; ?>
                            <div class="small" style="font-size:0.85rem; color:#b2f6ff;">Orden: <?= max(1, (int) ($p['orden'] ?? 0)) ?></div>
                            <div class="text-muted" style="font-size:0.85rem; color:#b2f6ff;">ID: <?= $p['id'] ?></div>
                            <div class="mt-2">
                                <form method="post" action="<?= htmlspecialchars($adminPackageBaseUrl, ENT_QUOTES, 'UTF-8') ?>/<?= $juego_id ?>" class="m-0 d-inline-flex align-items-center gap-2 js-ajax-toggle-form">
                                    <input type="hidden" name="ajax" value="1">
                                    <input type="hidden" name="toggle_paquete_activo" value="1">
                                    <input type="hidden" name="paquete_id" value="<?= (int) $p['id'] ?>">
                                    <input type="hidden" name="activo" value="<?= !isset($p['activo']) || !empty($p['activo']) ? '1' : '0' ?>" class="js-ajax-toggle-value">
                                    <div class="form-check form-switch mb-0">
                                        <input class="form-check-input js-ajax-toggle-input" type="checkbox" <?= !isset($p['activo']) || !empty($p['activo']) ? 'checked' : '' ?> aria-label="Activar o desactivar paquete <?= htmlspecialchars($p['nombre'], ENT_QUOTES, 'UTF-8') ?>" onchange="window.adminPackageToggle(this)">
                                    </div>
                                    <span style="color:#b2f6ff;font-size:0.85rem;" class="js-ajax-toggle-label"><?= !isset($p['activo']) || !empty($p['activo']) ? 'Activo' : 'Inactivo' ?></span>
                                </form>
                            </div>
                        </div>
                    </div>
                    <div style="color:#fff;"><span class="fw-semibold">Clave:</span> <?= htmlspecialchars($p['clave']) ?></div>
                    <?php if ($packageSellsAccount): ?>
                        <div style="color:#8be9fd;"><span class="fw-semibold">Modo:</span> Venta de cuenta</div>
                        <div style="color:#8be9fd;"><span class="fw-semibold">Galería:</span> <?= count($packageGalleryItems) ?> imágenes</div>
                    <?php endif; ?>
                    <?= admin_package_feature_badges_html($packageFeatures) ?>
                    <?php if ($packageSourceSelectionEnabled): ?>
                        <div style="color:#fff;"><span class="fw-semibold">Referencia API:</span> <?= htmlspecialchars($packageProviderReference, ENT_QUOTES, 'UTF-8') ?></div>
                    <?php elseif ($usesApiCatalog): ?>
                        <div style="color:#fff;"><span class="fw-semibold">Producto API:</span> <?= htmlspecialchars($packageProviderReference, ENT_QUOTES, 'UTF-8') ?></div>
                    <?php elseif ($usesLegacyFreeFire): ?>
                        <div style="color:#fff;"><span class="fw-semibold">Monto FF:</span> <?= htmlspecialchars($packageProviderReference, ENT_QUOTES, 'UTF-8') ?></div>
                    <?php endif; ?>
                    <div class="text-neon" style="color:#22d3ee;"><span class="fw-semibold">Precio:</span> $<?= number_format($p['precio'], 2) ?></div>
                    <div style="color:#fff;"><span class="fw-semibold"><?= htmlspecialchars($winPointsName, ENT_QUOTES, 'UTF-8') ?>:</span> <?= (int) ($p['win_points_reward'] ?? 0) ?></div>
                    <form method="post" action="<?= htmlspecialchars($adminPackageBaseUrl, ENT_QUOTES, 'UTF-8') ?>/<?= $juego_id ?>" class="mt-3 d-flex align-items-center gap-2 flex-wrap js-ajax-order-form">
                        <input type="hidden" name="ajax" value="1">
                        <input type="hidden" name="update_orden_paquete" value="1">
                        <input type="hidden" name="paquete_id" value="<?= (int) $p['id'] ?>">
                        <label class="small" style="color:#b2f6ff;">Orden</label>
                        <input type="number" name="orden" min="1" value="<?= max(1, (int) ($p['orden'] ?? 0)) ?>" class="form-control form-control-sm js-ajax-order-input" style="width:96px;background:#222c3a;color:#22d3ee;border:1px solid #22d3ee;" data-last-value="<?= max(1, (int) ($p['orden'] ?? 0)) ?>" onchange="window.adminPackageOrderChange(this)">
                    </form>
                    <div class="mt-3 d-flex gap-2">
                        <a href="<?= htmlspecialchars($adminPackageBaseUrl, ENT_QUOTES, 'UTF-8') ?>/<?= $juego_id ?>?editar=<?= $p['id'] ?>" class="btn neon-btn-info btn-sm flex-fill">Editar</a>
                        <a href="<?= htmlspecialchars($adminPackageBaseUrl, ENT_QUOTES, 'UTF-8') ?>/<?= $juego_id ?>?eliminar=<?= $p['id'] ?>" class="btn btn-danger btn-sm flex-fill" onclick="return confirm('¿Eliminar este paquete?')">Eliminar</a>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <a href="<?= htmlspecialchars($adminGamesUrl, ENT_QUOTES, 'UTF-8') ?>" class="inline-block mt-4 text-neon">&larr; Volver a juegos</a>
</main>

<div id="package-feature-apply-modal" class="position-fixed top-0 start-0 w-100 h-100 d-none align-items-center justify-content-center" style="background:rgba(2,6,23,0.82);z-index:1080;padding:1rem;">
    <div class="bg-dark rounded-4 p-4 w-100" data-package-feature-apply-dialog style="max-width:520px;border:1px solid rgba(34,211,238,0.22);box-shadow:0 0 24px rgba(34,211,238,0.18);">
        <h3 class="h5 text-neon mb-2">Aplicar a todos</h3>
        <p class="small mb-4" style="color:#8be9fd;">Elige cómo deseas propagar esta caracteristica al resto de paquetes del juego actual.</p>
        <div class="d-grid gap-2">
            <button type="button" id="package-feature-apply-replace" class="btn btn-outline-danger text-start" style="touch-action:manipulation;">Eliminar caracteristicas de los demas paquetes del juego</button>
            <button type="button" id="package-feature-apply-add" class="btn btn-outline-info text-start" style="touch-action:manipulation;">Agregar esta caracteristica a los demas paquetes</button>
            <button type="button" id="package-feature-apply-cancel" class="btn btn-secondary text-start" style="touch-action:manipulation;">Cancelar</button>
        </div>
    </div>
</div>


<?php
// Modal edición de paquete
if (isset($_GET['editar'])) {
    $edit_id = intval($_GET['editar']);
    $res_edit = $mysqli->prepare("SELECT * FROM juego_paquetes WHERE id=? AND juego_id=?");
    $res_edit->bind_param('ii', $edit_id, $juego_id);
    $res_edit->execute();
    $paq_edit = $res_edit->get_result()->fetch_assoc();
    $paqEditFeatureIds = package_feature_catalog_ids_for_package($mysqli, $edit_id);
    $paqEditFeatures = $packageFeaturesByPackage[$edit_id] ?? [];
    $paqEditGallery = package_account_sales_fetch_gallery($mysqli, $edit_id);
    $paqEditProvider = $paq_edit ? admin_package_resolve_provider($paq_edit, $juego, $discordApiEnabled) : '';
    if ($paq_edit):
?>
<div class="fixed-top w-100 h-100 d-flex align-items-start justify-content-center" style="background:rgba(0,0,0,0.7);z-index:1050;overflow-y:auto;padding:1rem;">
    <form method="post" enctype="multipart/form-data" class="bg-dark neon-card p-4 rounded-4 position-relative" data-package-source-form="1" style="max-width:560px;width:100%;max-height:calc(100vh - 2rem);overflow-y:auto;box-shadow:0 0 2rem #22d3ee33;">
        <div class="d-flex align-items-start justify-content-between gap-3 mb-3">
            <h3 class="text-neon mb-0">Editar paquete</h3>
            <a href="<?= htmlspecialchars($adminPackageBaseUrl, ENT_QUOTES, 'UTF-8') ?>/<?= $juego_id ?>" class="btn btn-outline-info btn-sm flex-shrink-0">Cerrar</a>
        </div>
        <input type="hidden" name="edit_paquete_id" value="<?= $paq_edit['id'] ?>">
        <?php if (!$packageSourceSelectionEnabled && $packageDefaultProvider !== ''): ?>
            <input type="hidden" name="edit_api_provider" value="<?= htmlspecialchars($packageDefaultProvider, ENT_QUOTES, 'UTF-8') ?>">
        <?php endif; ?>
        <div class="mb-3">
            <label class="form-label text-neon">Nombre</label>
            <input type="text" name="edit_nombre" value="<?= htmlspecialchars($paq_edit['nombre']) ?>" required class="form-control" style="background:#222c3a;color:#22d3ee;border:1px solid #22d3ee;" data-discord-catalog-field="name">
        </div>
        <div class="mb-3">
            <label class="form-label text-neon">Clave interna</label>
            <input type="text" name="edit_clave" value="<?= htmlspecialchars($paq_edit['clave']) ?>" required class="form-control" style="background:#222c3a;color:#22d3ee;border:1px solid #22d3ee;" data-discord-catalog-field="key">
        </div>
        <?php if ($packageSourceSelectionEnabled): ?>
            <div class="mb-3 rounded-4 p-3" style="background:#101826;border:1px solid rgba(34,211,238,0.18);">
                <div class="text-neon fw-semibold">Origen del paquete</div>
                <div class="small mt-2" style="color:#8be9fd;">Este juego tiene varias APIs. Elige cuál debe usar este paquete.</div>
                <div class="d-flex flex-wrap gap-3 mt-3">
                    <?php foreach ($packageSourceOptions as $sourceKey => $sourceLabel): ?>
                        <label class="d-inline-flex align-items-center gap-2 rounded-pill px-3 py-2" style="background:rgba(15,23,42,0.92);border:1px solid rgba(34,211,238,0.28);color:#d8fbff;cursor:pointer;">
                            <input type="radio" name="edit_api_provider" value="<?= htmlspecialchars($sourceKey, ENT_QUOTES, 'UTF-8') ?>" class="form-check-input mt-0" data-package-source-radio <?= $paqEditProvider === $sourceKey ? 'checked' : '' ?>>
                            <span><?= htmlspecialchars($sourceLabel, ENT_QUOTES, 'UTF-8') ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <button type="button" class="btn btn-outline-info btn-sm mt-3" data-package-source-clear>Limpiar selección</button>
            </div>
        <?php endif; ?>
        <?php if ($hasGiftVenCatalog): ?>
            <div class="mb-3" data-package-source-panel="giftven">
                <label class="form-label text-neon">Producto API</label>
                <select name="edit_paquete_api" <?= $packageSourceSelectionEnabled ? 'data-package-source-required="1"' : 'required' ?> class="form-select" style="background:#222c3a;color:#22d3ee;border:1px solid #22d3ee;">
                    <option value="">Selecciona un producto API</option>
                    <?php foreach ($apiProducts as $apiProduct): ?>
                        <option value="<?= (int) ($apiProduct['id'] ?? 0) ?>" <?= (int) ($paq_edit['paquete_api'] ?? 0) === (int) ($apiProduct['id'] ?? 0) ? 'selected' : '' ?>><?= htmlspecialchars(recargas_api_product_label($apiProduct), ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>
        <?php if ($hasDiscordCatalog): ?>
            <div class="mb-3" data-package-source-panel="discord">
                <?php if (!empty($discordCatalogItems)): ?>
                    <label class="form-label text-neon">Paquete a recargar</label>
                    <select class="form-select mb-3" data-discord-catalog-select style="background:#222c3a;color:#22d3ee;border:1px solid #22d3ee;">
                        <option value="">Selecciona un paquete del juego</option>
                        <?php foreach ($discordCatalogItems as $catalogItem): ?>
                            <option
                                value="<?= htmlspecialchars((string) ($catalogItem['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                data-name="<?= htmlspecialchars((string) ($catalogItem['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                data-key="<?= htmlspecialchars((string) ($catalogItem['quantity'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                data-quantity="<?= htmlspecialchars((string) ($catalogItem['quantity'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                data-price="<?= htmlspecialchars((string) ($catalogItem['price_usd'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                            ><?= htmlspecialchars(api_discord_catalog_item_label($catalogItem), ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php else: ?>
                    <label class="form-label text-neon">Comando de precio de referencia</label>
                    <input type="text" class="form-control" value="<?= htmlspecialchars($discordPriceCommandText !== '' ? $discordPriceCommandText : 'Sin comando de precio vinculado', ENT_QUOTES, 'UTF-8') ?>" readonly style="background:#222c3a;color:#22d3ee;border:1px solid #22d3ee;">
                <?php endif; ?>
                <div class="form-text mt-2" style="color:#8be9fd;">
                    <?php if (!empty($discordCatalogItems)): ?>
                        Selecciona el paquete del juego que corresponde a esta recarga para actualizar abajo la cantidad y el precio USD.
                    <?php elseif ($discordPriceCommandText !== ''): ?>
                        Usa la sincronización superior y luego carga aquí el listado del juego para seleccionar un paquete detectado.
                    <?php else: ?>
                        Este juego usa Discord, pero no tiene un comando de precio vinculado en el catálogo actual.
                    <?php endif; ?>
                </div>
                <?php if ($discordPriceCommandText !== ''): ?>
                    <div class="form-text mt-2" style="color:#8be9fd;">Comando usado para consultar precios: <?= htmlspecialchars($discordPriceCommandText, ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>
                <?php if ($discordTopupCommandText !== ''): ?>
                    <div class="form-text mt-2" style="color:#8be9fd;">Comando de recarga configurado: <?= htmlspecialchars($discordTopupCommandText, ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>
            </div>
            <div class="mb-3" data-package-source-panel="discord">
                <label class="form-label text-neon">Cantidad / paquete Discord</label>
                <input type="text" name="edit_cantidad" value="<?= htmlspecialchars($paq_edit['cantidad']) ?>" <?= $packageSourceSelectionEnabled ? 'data-package-source-required="1"' : 'required' ?> maxlength="80" class="form-control" style="background:#222c3a;color:#22d3ee;border:1px solid #22d3ee;" data-discord-catalog-field="quantity">
                <div class="form-text mt-2" style="color:#8be9fd;">Este valor es el que se insertará exactamente en el parámetro <code>{cantidad}</code> del comando Discord del juego.</div>
            </div>
        <?php endif; ?>
        <?php if ($usesLegacyFreeFire): ?>
            <div class="mb-3" data-package-source-panel="free_fire">
                <label class="form-label text-neon">Montos (API)</label>
                <select name="edit_monto_ff" <?= $packageSourceSelectionEnabled ? 'data-package-source-required="1"' : 'required' ?> class="form-select" style="background:#222c3a;color:#22d3ee;border:1px solid #22d3ee;">
                    <option value="">Selecciona un monto API</option>
                    <?php foreach ($freeFireApiOptions as $amount => $option): ?>
                        <option value="<?= htmlspecialchars($amount, ENT_QUOTES, 'UTF-8') ?>" <?= (string) ($paq_edit['monto_ff'] ?? '') === (string) $amount ? 'selected' : '' ?>>&#128142; <?= htmlspecialchars($option['suggested_name'], ENT_QUOTES, 'UTF-8') ?> - <?= htmlspecialchars($option['diamonds'], ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>
        <div class="mb-3">
            <label class="form-label text-neon">Precio USD</label>
            <input type="number" step="0.01" name="edit_precio" value="<?= htmlspecialchars($paq_edit['precio']) ?>" required class="form-control" style="background:#222c3a;color:#22d3ee;border:1px solid #22d3ee;" data-discord-catalog-field="price">
        </div>
        <div class="mb-3">
            <label class="form-label text-neon"><?= htmlspecialchars($winPointsName, ENT_QUOTES, 'UTF-8') ?> a ganar</label>
            <input type="number" min="0" name="edit_win_points_reward" value="<?= (int) ($paq_edit['win_points_reward'] ?? 0) ?>" class="form-control" style="background:#222c3a;color:#22d3ee;border:1px solid #22d3ee;">
        </div>
        <div class="form-check mb-3">
            <input type="checkbox" name="edit_activo" class="form-check-input" id="editPaqueteActivoCheck" <?= !isset($paq_edit['activo']) || !empty($paq_edit['activo']) ? 'checked' : '' ?>>
            <label class="form-check-label text-neon" for="editPaqueteActivoCheck">Paquete activo / publicado</label>
        </div>
        <div class="mb-3">
            <label class="form-label text-neon">Icono actual:</label><br>
            <?php if ($paq_edit['imagen_icono']): ?>
                <img src="/<?= htmlspecialchars($paq_edit['imagen_icono']) ?>" alt="Icono actual" class="mb-2 rounded" style="max-width:80px;max-height:80px;border:2px solid #22d3ee;background:#222c3a;box-shadow:0 0 8px #22d3ee;">
            <?php endif; ?>
            <input type="file" name="edit_imagen_icono" accept="image/*" class="form-control mt-2" style="background:#222c3a;color:#22d3ee;border:1px solid #22d3ee;" onchange="previewEditPaqueteImg(event)">
            <div class="text-center my-2">
                <img id="preview-edit-paquete-img" src="#" alt="Previsualización" style="display:none;max-width:120px;max-height:120px;border-radius:0.75rem;box-shadow:0 0 0.5rem #22d3ee55;" />
            </div>
        </div>
        <?php if ($accountSaleFeatureEnabled): ?>
        <div class="mb-3 rounded-4 p-3" data-account-sale-scope style="background:#101826;border:1px solid rgba(34,211,238,0.18);">
            <div class="form-check form-switch mb-3">
                <input type="checkbox" name="edit_vender_cuenta" class="form-check-input" id="editPackageSellAccountCheck" data-account-sale-toggle <?= !empty($paq_edit['vender_cuenta']) ? 'checked' : '' ?>>
                <label class="form-check-label text-neon" for="editPackageSellAccountCheck">Vender Cuenta</label>
            </div>
            <div class="small mb-3" style="color:#8be9fd;">Este bloque define la entrega manual/automática de una cuenta. Si está activo, el pago se resolverá entregando estos datos en vez de disparar la recarga del juego.</div>
            <div data-account-sale-config class="<?= !empty($paq_edit['vender_cuenta']) ? '' : 'd-none' ?>">
                <div class="mb-3">
                    <label class="form-label text-neon">Datos de la cuenta</label>
                    <textarea name="edit_cuenta_texto" rows="6" class="form-control" data-account-sale-textarea style="background:#222c3a;color:#22d3ee;border:1px solid #22d3ee;" placeholder="Correo, contraseña, instrucciones y observaciones para el cliente."><?= htmlspecialchars((string) ($paq_edit['cuenta_texto'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
                </div>
                <div class="mb-3">
                    <div class="text-neon fw-semibold mb-2">Galería actual</div>
                    <?php if (!empty($paqEditGallery)): ?>
                        <div class="d-grid gap-3">
                            <?php foreach ($paqEditGallery as $galleryIndex => $galleryItem): ?>
                                <div class="rounded-4 p-3" style="background:#0f172a;border:1px solid rgba(34,211,238,0.12);">
                                    <input type="hidden" name="edit_existing_account_gallery_path[]" value="<?= htmlspecialchars((string) ($galleryItem['image_path'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                    <div class="d-flex flex-column flex-md-row gap-3">
                                        <div class="flex-shrink-0">
                                            <img src="/<?= htmlspecialchars((string) ($galleryItem['image_path'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" alt="Imagen actual" class="rounded" style="width:120px;height:120px;object-fit:cover;border:1px solid rgba(34,211,238,0.24);background:#081018;">
                                        </div>
                                        <div class="flex-grow-1">
                                            <div class="mb-2">
                                                <label class="form-label text-neon small">Descripción</label>
                                                <input type="text" name="edit_existing_account_gallery_description[]" value="<?= htmlspecialchars((string) ($galleryItem['description'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" class="form-control" style="background:#222c3a;color:#22d3ee;border:1px solid #22d3ee;" maxlength="255">
                                            </div>
                                            <div class="mb-2">
                                                <label class="form-label text-neon small">Reemplazar imagen</label>
                                                <input type="file" name="edit_existing_account_gallery_replace[]" accept="image/*" class="form-control" style="background:#222c3a;color:#22d3ee;border:1px solid #22d3ee;">
                                            </div>
                                            <div class="form-check">
                                                <input type="checkbox" name="edit_existing_account_gallery_remove[<?= (int) $galleryIndex ?>]" value="1" class="form-check-input" id="removeAccountGallery<?= (int) $galleryIndex ?>">
                                                <label class="form-check-label text-neon small" for="removeAccountGallery<?= (int) $galleryIndex ?>">Eliminar esta imagen al guardar</label>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="small" style="color:#8be9fd;">Este paquete todavía no tiene imágenes asociadas.</div>
                    <?php endif; ?>
                </div>
                <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-2 mb-3">
                    <div>
                        <div class="text-neon fw-semibold">Agregar nuevas imágenes</div>
                        <div class="small" style="color:#8be9fd;">Puedes sumar más capturas para la galería pública del botón Ver Más.</div>
                    </div>
                    <button type="button" class="btn btn-outline-info btn-sm" onclick="window.addPackageAccountGalleryRow('edit-account-gallery-rows', 'edit_new_account_gallery_image[]', 'edit_new_account_gallery_description[]')">Agregar imagen</button>
                </div>
                <div id="edit-account-gallery-rows" class="d-grid gap-2"></div>
            </div>
        </div>
        <?php endif; ?>
        <div class="mb-3 rounded-4 p-3" style="background:#101826;border:1px solid rgba(34,211,238,0.18);">
            <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-2 mb-3">
                <div>
                    <div class="text-neon fw-semibold">Caracteristicas del paquete</div>
                    <div class="small" style="color:#8be9fd;">Puedes reutilizar caracteristicas existentes o crear nuevas para este paquete.</div>
                </div>
                <button type="button" class="btn btn-outline-info btn-sm" onclick="window.addPackageFeatureRow('edit-package-new-features', 'edit_new_feature_name[]', 'edit_new_feature_icon[]', <?= htmlspecialchars(json_encode($packageFeatureIconOptionsHtml, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8') ?>, 'edit_new_feature_apply_mode[]')">Nueva caracteristica</button>
            </div>
            <div class="d-flex flex-wrap gap-2 mb-3">
                <?php if (!empty($packageFeatureCatalog)): ?>
                    <?php foreach ($packageFeatureCatalog as $feature): ?>
                        <div class="d-inline-flex flex-wrap align-items-center gap-2" data-package-feature-apply-scope>
                            <input type="hidden" name="edit_catalog_feature_id[]" value="<?= (int) ($feature['id'] ?? 0) ?>">
                            <input type="hidden" name="edit_catalog_feature_apply_mode[]" value="" data-package-feature-apply-input>
                            <label class="badge rounded-pill d-inline-flex align-items-center gap-2 px-3 py-2" style="cursor:pointer;background:rgba(15,23,42,0.92);border:1px solid rgba(34,211,238,0.28);color:#d8fbff;">
                                <input type="checkbox" name="edit_package_feature_ids[]" value="<?= (int) ($feature['id'] ?? 0) ?>" class="form-check-input mt-0 me-1" style="float:none;" <?= in_array((int) ($feature['id'] ?? 0), $paqEditFeatureIds, true) ? 'checked' : '' ?> data-package-feature-select-checkbox>
                                <?= package_feature_render_icon((string) ($feature['icon'] ?? 'sparkles'), 'package-feature-badge-icon') ?>
                                <span><?= htmlspecialchars((string) ($feature['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
                            </label>
                            <button type="button" class="btn btn-outline-warning btn-sm" data-package-feature-apply-button onclick="window.openPackageFeatureApplyModal(this)">Aplicar a todos</button>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <span class="small" style="color:#8be9fd;">Aun no hay caracteristicas guardadas en el catalogo.</span>
                <?php endif; ?>
            </div>
            <div class="small mb-3" style="color:#8be9fd;">Usa Aplicar a todos en una caracteristica seleccionada, editada o nueva para copiarla al resto de paquetes de este juego cuando guardes.</div>
            <?php if (!empty($paqEditFeatures)): ?>
                <div class="d-grid gap-2 mb-3">
                    <div class="small fw-semibold" style="color:#8be9fd;">Editar caracteristicas asignadas</div>
                    <?php foreach ($paqEditFeatures as $feature): ?>
                        <div class="row g-2 align-items-center rounded-4 p-2" data-package-feature-apply-scope style="background:#0f172a;border:1px solid rgba(34,211,238,0.12);">
                            <input type="hidden" name="edit_assigned_feature_id[]" value="<?= (int) ($feature['id'] ?? 0) ?>">
                            <input type="hidden" name="edit_assigned_feature_apply_mode[]" value="" data-package-feature-apply-input>
                            <div class="col-md-4">
                                <label class="form-label text-neon small mb-1">Icono</label>
                                <div class="d-flex align-items-center gap-2" data-package-feature-editor>
                                    <select name="edit_assigned_feature_icon[]" data-package-feature-icon-select class="form-select" style="background:#222c3a;color:#22d3ee;border:1px solid #22d3ee;"><?= admin_package_feature_icon_options_html($packageFeatureIconOptions, (string) ($feature['icon'] ?? 'sparkles')) ?></select>
                                    <span data-package-feature-icon-preview class="d-inline-flex align-items-center justify-content-center rounded-3 flex-shrink-0" style="width:44px;height:44px;background:#0f172a;border:1px solid rgba(34,211,238,0.22);color:#67e8f9;"><?= package_feature_render_icon((string) ($feature['icon'] ?? 'sparkles')) ?></span>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label text-neon small mb-1">Nombre</label>
                                <input type="text" name="edit_assigned_feature_name[]" value="<?= htmlspecialchars((string) ($feature['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" class="form-control" style="background:#222c3a;color:#22d3ee;border:1px solid #22d3ee;" required>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label text-neon small mb-1 d-block">Aplicacion</label>
                                <button type="button" class="btn btn-outline-warning btn-sm w-100" data-package-feature-apply-button onclick="window.openPackageFeatureApplyModal(this)">Aplicar a todos</button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <div id="edit-package-new-features" class="d-grid gap-2"></div>
        </div>
        <button type="submit" name="edit_paquete_submit" class="btn neon-btn-info w-100 mt-3">Guardar cambios</button>
    </form>
</div>
<script>
const adminPackageFeatureIconMap = <?= json_encode(package_feature_icon_svg_map(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

function updatePackageFeatureIconPreview(select) {
    if (!select) {
        return;
    }

    const editor = select.closest('[data-package-feature-editor]');
    const preview = editor ? editor.querySelector('[data-package-feature-icon-preview]') : null;
    if (!preview) {
        return;
    }

    const iconKey = String(select.value || 'sparkles').trim();
    preview.innerHTML = adminPackageFeatureIconMap[iconKey] || adminPackageFeatureIconMap.sparkles || '';
}

function bindPackageFeatureIconPreview(root = document) {
    root.querySelectorAll('[data-package-feature-icon-select]').forEach((select) => {
        if (select.dataset.iconPreviewBound === '1') {
            updatePackageFeatureIconPreview(select);
            return;
        }

        select.dataset.iconPreviewBound = '1';
        select.addEventListener('change', function() {
            updatePackageFeatureIconPreview(this);
        });
        updatePackageFeatureIconPreview(select);
    });
}

function previewNuevoPaqueteImg(event) {
    const input = event.target;
    const img = document.getElementById('preview-nuevo-paquete-img');
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

function previewEditPaqueteImg(event) {
        const input = event.target;
        const img = document.getElementById('preview-edit-paquete-img');
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

window.adminPackageActiveTab = '<?= $currentPackageTab ?>';

window.adminPackageRefreshTabCounts = function() {
    const counts = { active: 0, inactive: 0 };
    document.querySelectorAll('.js-package-record[data-package-context="desktop"]').forEach((record) => {
        const status = record.classList.contains('inactivo') ? 'inactive' : 'active';
        counts[status] += 1;
    });
    document.querySelectorAll('[data-package-tab-count]').forEach((node) => {
        const status = node.getAttribute('data-package-tab-count') === 'inactive' ? 'inactive' : 'active';
        node.textContent = String(counts[status] || 0);
    });
};

window.filterAdminPackagesByClass = function(filterClass) {
    const activeTab = filterClass === 'inactivo' ? 'inactive' : 'active';
    window.adminPackageActiveTab = activeTab;

    document.querySelectorAll('.js-package-tab-btn').forEach((button) => {
        const isCurrent = button.getAttribute('data-package-tab') === activeTab;
        button.classList.toggle('btn-info', isCurrent);
        button.classList.toggle('btn-outline-info', !isCurrent);
    });

    document.querySelectorAll('.js-package-filterable').forEach((record) => {
        const matches = record.classList.contains(filterClass);
        record.style.display = matches ? '' : 'none';
    });

};

window.initAdminPackageTabs = function() {
    window.adminPackageRefreshTabCounts();
    window.filterAdminPackagesByClass(window.adminPackageActiveTab === 'inactive' ? 'inactivo' : 'activo');
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', window.initAdminPackageTabs, { once: true });
} else {
    window.initAdminPackageTabs();
}

window.adminPackageToggle = async function(input) {
    if (!input || input.dataset.busy === '1' || !input.form) {
        return;
    }

    const form = input.form;
    const valueInput = form.querySelector('.js-ajax-toggle-value');
    const label = form.querySelector('.js-ajax-toggle-label');

    if (valueInput) {
        valueInput.value = input.checked ? '1' : '0';
    }

    const requestData = new FormData(form);
    input.dataset.busy = '1';
    input.disabled = true;

    try {
        const payload = await submitAjaxAdminForm(form, requestData);
        input.checked = String(payload.activo || 0) === '1';
        if (valueInput) {
            valueInput.value = input.checked ? '1' : '0';
        }
        if (label) {
            label.textContent = input.checked ? 'Activo' : 'Inactivo';
        }
        const packageIdInput = form.querySelector('input[name="paquete_id"]');
        const packageId = packageIdInput ? String(packageIdInput.value || '') : '';
        document.querySelectorAll('.js-package-record').forEach((record) => {
            if (String(record.getAttribute('data-package-id') || '') === packageId) {
                record.classList.remove('activo', 'inactivo');
                record.classList.add(input.checked ? 'activo' : 'inactivo');
            }
        });
        window.adminPackageRefreshTabCounts();
        window.filterAdminPackagesByClass(window.adminPackageActiveTab === 'inactive' ? 'inactivo' : 'activo');
    } catch (error) {
        input.checked = !input.checked;
        if (valueInput) {
            valueInput.value = input.checked ? '1' : '0';
        }
        window.alert(error.message);
    } finally {
        input.disabled = false;
        input.dataset.busy = '0';
    }
};

window.adminPackageOrderChange = async function(input) {
    if (!input || !input.form) {
        return;
    }

    const form = input.form;
    const normalized = String(Math.max(1, parseInt(input.value || '1', 10) || 1));
    const lastValue = String(input.dataset.lastValue || input.defaultValue || '1');
    if (normalized === lastValue) {
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
        input.value = lastValue;
        window.alert(error.message);
    } finally {
        input.readOnly = false;
    }
};
</script>
<?php endif; }
?>

<script>
if (typeof window.submitAjaxAdminForm !== 'function') {
    window.submitAjaxAdminForm = async function(form, requestData = null) {
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
    };
}

if (typeof window.adminPackageRefreshTabCounts !== 'function') {
    window.adminPackageActiveTab = '<?= $currentPackageTab ?>';
    window.adminPackageRefreshTabCounts = function() {
        const counts = { active: 0, inactive: 0 };
        document.querySelectorAll('.js-package-record[data-package-context="desktop"]').forEach((record) => {
            const status = record.classList.contains('inactivo') ? 'inactive' : 'active';
            counts[status] += 1;
        });
        document.querySelectorAll('[data-package-tab-count]').forEach((node) => {
            const status = node.getAttribute('data-package-tab-count') === 'inactive' ? 'inactive' : 'active';
            node.textContent = String(counts[status] || 0);
        });
    };

    window.filterAdminPackagesByClass = function(filterClass) {
        const activeTab = filterClass === 'inactivo' ? 'inactive' : 'active';
        window.adminPackageActiveTab = activeTab;

        document.querySelectorAll('.js-package-tab-btn').forEach((button) => {
            const isCurrent = button.getAttribute('data-package-tab') === activeTab;
            button.classList.toggle('btn-info', isCurrent);
            button.classList.toggle('btn-outline-info', !isCurrent);
        });

        document.querySelectorAll('.js-package-filterable').forEach((record) => {
            const matches = record.classList.contains(filterClass);
            record.style.display = matches ? '' : 'none';
        });

    };

    window.initAdminPackageTabs = function() {
        window.adminPackageRefreshTabCounts();
        window.filterAdminPackagesByClass(window.adminPackageActiveTab === 'inactive' ? 'inactivo' : 'activo');
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', window.initAdminPackageTabs, { once: true });
    } else {
        window.initAdminPackageTabs();
    }
}

if (typeof window.adminPackageToggle !== 'function') {
    window.adminPackageToggle = async function(input) {
        if (!input || input.dataset.busy === '1' || !input.form) {
            return;
        }

        const form = input.form;
        const valueInput = form.querySelector('.js-ajax-toggle-value');
        const label = form.querySelector('.js-ajax-toggle-label');

        if (valueInput) {
            valueInput.value = input.checked ? '1' : '0';
        }

        const requestData = new FormData(form);
        input.dataset.busy = '1';
        input.disabled = true;

        try {
            const payload = await window.submitAjaxAdminForm(form, requestData);
            input.checked = String(payload.activo || 0) === '1';
            if (valueInput) {
                valueInput.value = input.checked ? '1' : '0';
            }
            if (label) {
                label.textContent = input.checked ? 'Activo' : 'Inactivo';
            }
            const packageIdInput = form.querySelector('input[name="paquete_id"]');
            const packageId = packageIdInput ? String(packageIdInput.value || '') : '';
            document.querySelectorAll('.js-package-record').forEach((record) => {
                if (String(record.getAttribute('data-package-id') || '') === packageId) {
                    record.classList.remove('activo', 'inactivo');
                    record.classList.add(input.checked ? 'activo' : 'inactivo');
                }
            });
            window.adminPackageRefreshTabCounts();
            window.filterAdminPackagesByClass(window.adminPackageActiveTab === 'inactive' ? 'inactivo' : 'activo');
        } catch (error) {
            input.checked = !input.checked;
            if (valueInput) {
                valueInput.value = input.checked ? '1' : '0';
            }
            window.alert(error.message);
        } finally {
            input.disabled = false;
            input.dataset.busy = '0';
        }
    };
}

if (typeof window.adminPackageOrderChange !== 'function') {
    window.adminPackageOrderChange = async function(input) {
        if (!input || !input.form) {
            return;
        }

        const form = input.form;
        const normalized = String(Math.max(1, parseInt(input.value || '1', 10) || 1));
        const lastValue = String(input.dataset.lastValue || input.defaultValue || '1');
        if (normalized === lastValue) {
            input.value = normalized;
            return;
        }

        input.value = normalized;
        const requestData = new FormData(form);
        input.readOnly = true;

        try {
            const payload = await window.submitAjaxAdminForm(form, requestData);
            input.dataset.lastValue = String(payload.orden || normalized);
            input.value = input.dataset.lastValue;
        } catch (error) {
            input.value = lastValue;
            window.alert(error.message);
        } finally {
            input.readOnly = false;
        }
    };
}

if (typeof window.previewNuevoPaqueteImg !== 'function') {
    window.previewNuevoPaqueteImg = function(event) {
        const input = event.target;
        const img = document.getElementById('preview-nuevo-paquete-img');
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
    };
}

if (typeof window.previewEditPaqueteImg !== 'function') {
    window.previewEditPaqueteImg = function(event) {
        const input = event.target;
        const img = document.getElementById('preview-edit-paquete-img');
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
    };
}

if (!window.adminPackageFeatureIconMap) {
    window.adminPackageFeatureIconMap = <?= json_encode(package_feature_icon_svg_map(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
}

if (typeof window.updatePackageFeatureIconPreview !== 'function') {
    window.updatePackageFeatureIconPreview = function(select) {
        if (!select) {
            return;
        }

        const editor = select.closest('[data-package-feature-editor]');
        const preview = editor ? editor.querySelector('[data-package-feature-icon-preview]') : null;
        if (!preview) {
            return;
        }

        const iconKey = String(select.value || 'sparkles').trim();
        preview.innerHTML = window.adminPackageFeatureIconMap[iconKey] || window.adminPackageFeatureIconMap.sparkles || '';
    };
}

if (typeof window.bindPackageFeatureIconPreview !== 'function') {
    window.bindPackageFeatureIconPreview = function(root = document) {
        root.querySelectorAll('[data-package-feature-icon-select]').forEach((select) => {
            if (select.dataset.iconPreviewBound === '1') {
                window.updatePackageFeatureIconPreview(select);
                return;
            }

            select.dataset.iconPreviewBound = '1';
            select.addEventListener('change', function() {
                window.updatePackageFeatureIconPreview(this);
            });
            window.updatePackageFeatureIconPreview(select);
        });
    };
}

if (typeof window.removePackageFeatureRow !== 'function') {
    window.removePackageFeatureRow = function(button) {
        const row = button ? button.closest('.package-feature-inline-row') : null;
        if (row) {
            row.remove();
        }
    };
}

if (typeof window.removePackageAccountGalleryRow !== 'function') {
    window.removePackageAccountGalleryRow = function(button) {
        const row = button ? button.closest('.package-account-gallery-row') : null;
        if (row) {
            row.remove();
        }
    };
}

if (typeof window.addPackageAccountGalleryRow !== 'function') {
    window.addPackageAccountGalleryRow = function(containerId, imageField, descriptionField) {
        const container = document.getElementById(containerId);
        if (!container) {
            return;
        }

        const row = document.createElement('div');
        row.className = 'package-account-gallery-row rounded-4 p-3';
        row.style.background = '#0f172a';
        row.style.border = '1px solid rgba(34,211,238,0.12)';
        row.innerHTML = `
            <div class="row g-2 align-items-end">
                <div class="col-md-5">
                    <label class="form-label text-neon small mb-1">Imagen</label>
                    <input type="file" name="${imageField}" accept="image/*" class="form-control" style="background:#222c3a;color:#22d3ee;border:1px solid #22d3ee;">
                </div>
                <div class="col-md-5">
                    <label class="form-label text-neon small mb-1">Descripción</label>
                    <input type="text" name="${descriptionField}" class="form-control" maxlength="255" placeholder="Ej: Inventario principal" style="background:#222c3a;color:#22d3ee;border:1px solid #22d3ee;">
                </div>
                <div class="col-md-2">
                    <button type="button" class="btn btn-outline-danger btn-sm w-100" onclick="window.removePackageAccountGalleryRow(this)">Quitar</button>
                </div>
            </div>`;
        container.appendChild(row);
    };
}

if (typeof window.bindPackageAccountSaleScopes !== 'function') {
    window.bindPackageAccountSaleScopes = function(root = document) {
        root.querySelectorAll('[data-account-sale-scope]').forEach((scope) => {
            if (scope.dataset.accountSaleBound === '1') {
                return;
            }

            const toggle = scope.querySelector('[data-account-sale-toggle]');
            const config = scope.querySelector('[data-account-sale-config]');
            const textarea = scope.querySelector('[data-account-sale-textarea]');
            if (!toggle || !config) {
                return;
            }

            const sync = function() {
                const enabled = toggle.checked;
                config.classList.toggle('d-none', !enabled);
                if (textarea) {
                    textarea.required = enabled;
                }
            };

            scope.dataset.accountSaleBound = '1';
            toggle.addEventListener('change', sync);
            sync();
        });
    };
}

if (typeof window.addPackageFeatureRow !== 'function') {
    window.addPackageFeatureRow = function(containerId, nameField, iconField, iconOptionsHtml, applyModeField = '') {
        const container = document.getElementById(containerId);
        if (!container) {
            return;
        }

        const row = document.createElement('div');
        row.className = 'package-feature-inline-row d-grid gap-2';
        row.innerHTML = `
            <div class="row g-2 align-items-center rounded-4 p-2" data-package-feature-apply-scope style="background:#0f172a;border:1px solid rgba(34,211,238,0.12);">
                <input type="hidden" name="${applyModeField}" value="" data-package-feature-apply-input>
                <div class="col-md-4">
                    <label class="form-label text-neon small mb-1">Icono</label>
                    <div class="d-flex align-items-center gap-2" data-package-feature-editor>
                        <select name="${iconField}" data-package-feature-icon-select class="form-select" style="background:#222c3a;color:#22d3ee;border:1px solid #22d3ee;">${iconOptionsHtml}</select>
                        <span data-package-feature-icon-preview class="d-inline-flex align-items-center justify-content-center rounded-3 flex-shrink-0" style="width:44px;height:44px;background:#0f172a;border:1px solid rgba(34,211,238,0.22);color:#67e8f9;"></span>
                    </div>
                </div>
                <div class="col-md-4">
                    <label class="form-label text-neon small mb-1">Nombre</label>
                    <input type="text" name="${nameField}" class="form-control" placeholder="Nombre de la caracteristica" style="background:#222c3a;color:#22d3ee;border:1px solid #22d3ee;">
                </div>
                <div class="col-md-2">
                    <label class="form-label text-neon small mb-1 d-block">Aplicacion</label>
                    <button type="button" class="btn btn-outline-warning btn-sm w-100" data-package-feature-apply-button onclick="window.openPackageFeatureApplyModal(this)">Aplicar a todos</button>
                </div>
                <div class="col-md-2">
                    <label class="form-label text-neon small mb-1 d-block">Accion</label>
                    <button type="button" class="btn btn-outline-danger btn-sm w-100" onclick="window.removePackageFeatureRow(this)">Quitar</button>
                </div>
            </div>`;
        container.appendChild(row);
        window.bindPackageFeatureIconPreview(row);
        if (typeof window.bindPackageFeatureApplyButtons === 'function') {
            window.bindPackageFeatureApplyButtons(row);
        }
    };
}

if (typeof window.getPackageFeatureApplyScope !== 'function') {
    window.getPackageFeatureApplyScope = function(element) {
        return element ? element.closest('[data-package-feature-apply-scope]') : null;
    };
}

if (typeof window.openPackageFeatureApplyModal !== 'function') {
    window.openPackageFeatureApplyModal = function(button) {
        const modal = document.getElementById('package-feature-apply-modal');
        const scope = window.getPackageFeatureApplyScope(button);
        const input = scope ? scope.querySelector('[data-package-feature-apply-input]') : null;
        if (!modal || !scope || !input) {
            return;
        }
        window.__packageFeatureApplyTarget = input;
        window.__packageFeatureApplyScope = scope;
        modal.classList.remove('d-none');
        modal.classList.add('d-flex');
    };
}

if (typeof window.closePackageFeatureApplyModal !== 'function') {
    window.closePackageFeatureApplyModal = function() {
        const modal = document.getElementById('package-feature-apply-modal');
        if (!modal) {
            return;
        }
        modal.classList.add('d-none');
        modal.classList.remove('d-flex');
        window.__packageFeatureApplyTarget = null;
        window.__packageFeatureApplyScope = null;
    };
}

if (typeof window.selectPackageFeatureApplyMode !== 'function') {
    window.selectPackageFeatureApplyMode = function(mode) {
        const normalizedMode = mode === 'replace' ? 'replace' : (mode === 'add' ? 'add' : '');
        if (normalizedMode !== '' && window.__packageFeatureApplyTarget) {
            window.__packageFeatureApplyTarget.value = normalizedMode;
            const scope = window.__packageFeatureApplyScope || window.getPackageFeatureApplyScope(window.__packageFeatureApplyTarget);
            const checkbox = scope ? scope.querySelector('[data-package-feature-select-checkbox]') : null;
            if (checkbox && !checkbox.checked) {
                checkbox.checked = true;
            }
            const button = scope ? scope.querySelector('[data-package-feature-apply-button]') : null;
            window.syncPackageFeatureApplyButton(button);
        }
        window.closePackageFeatureApplyModal();
    };
}

if (typeof window.syncPackageFeatureApplyButton !== 'function') {
    window.syncPackageFeatureApplyButton = function(button) {
        if (!button) {
            return;
        }
        const scope = window.getPackageFeatureApplyScope(button);
        const input = scope ? scope.querySelector('[data-package-feature-apply-input]') : null;
        const mode = input ? String(input.value || '').trim() : '';
        button.classList.remove('btn-outline-warning', 'btn-outline-info', 'btn-outline-danger');
        if (mode === 'replace') {
            button.classList.add('btn-outline-danger');
            button.textContent = 'Reemplazar en los demas';
            return;
        }
        if (mode === 'add') {
            button.classList.add('btn-outline-info');
            button.textContent = 'Agregar en los demas';
            return;
        }
        button.classList.add('btn-outline-warning');
        button.textContent = 'Aplicar a todos';
    };
}

if (typeof window.bindPackageFeatureActionButton !== 'function') {
    window.bindPackageFeatureActionButton = function(button, handler) {
        if (!button || button.dataset.boundAction === '1') {
            return;
        }

        let lastTouchTime = 0;
        const trigger = function(event) {
            if (event.type === 'click' && Date.now() - lastTouchTime < 500) {
                event.preventDefault();
                return;
            }
            if (event.type === 'touchend') {
                lastTouchTime = Date.now();
            }
            event.preventDefault();
            event.stopPropagation();
            handler();
        };

        button.dataset.boundAction = '1';
        button.addEventListener('click', trigger);
        button.addEventListener('touchend', trigger, { passive: false });
    };
}

if (typeof window.bindPackageFeatureApplyButtons !== 'function') {
    window.bindPackageFeatureApplyButtons = function(root = document) {
        root.querySelectorAll('[data-package-feature-apply-button]').forEach((button) => {
            window.syncPackageFeatureApplyButton(button);
        });
    };
}

if (typeof window.slugifyDiscordCatalogKey !== 'function') {
    window.slugifyDiscordCatalogKey = function(value) {
        return String(value || '')
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .toLowerCase()
            .replace(/[^a-z0-9]+/g, '_')
            .replace(/^_+|_+$/g, '')
            .slice(0, 80);
    };
}

if (typeof window.applyDiscordCatalogSelection !== 'function') {
    window.applyDiscordCatalogSelection = function(select) {
        if (!select) {
            return;
        }

        const option = select.options[select.selectedIndex];
        const form = select.closest('form');
        if (!option || !form) {
            return;
        }

        const name = String(option.dataset.name || '').trim();
        const quantity = String(option.dataset.quantity || '').trim();
        const price = String(option.dataset.price || '').trim();
        const key = window.slugifyDiscordCatalogKey(name || option.dataset.key || quantity || 'discord_paquete');

        const nameInput = form.querySelector('[data-discord-catalog-field="name"]');
        const keyInput = form.querySelector('[data-discord-catalog-field="key"]');
        const quantityInput = form.querySelector('[data-discord-catalog-field="quantity"]');
        const priceInput = form.querySelector('[data-discord-catalog-field="price"]');

        if (nameInput && name !== '') {
            nameInput.value = name;
        }
        if (keyInput && key !== '') {
            keyInput.value = key;
        }
        if (quantityInput && quantity !== '') {
            quantityInput.value = quantity;
        }
        if (priceInput && price !== '') {
            priceInput.value = price;
        }
    };
}

if (typeof window.bindDiscordCatalogSelects !== 'function') {
    window.bindDiscordCatalogSelects = function(root = document) {
        root.querySelectorAll('[data-discord-catalog-select]').forEach((select) => {
            if (select.dataset.boundDiscordCatalog === '1') {
                return;
            }
            select.dataset.boundDiscordCatalog = '1';
            select.addEventListener('change', function() {
                window.applyDiscordCatalogSelection(select);
            });
        });
    };
}

if (typeof window.applyPackageSourceSelection !== 'function') {
    window.applyPackageSourceSelection = function(form) {
        if (!(form instanceof HTMLElement)) {
            return;
        }

        const radios = Array.from(form.querySelectorAll('[data-package-source-radio]'));
        if (!radios.length) {
            return;
        }

        const selectedRadio = radios.find((radio) => radio.checked) || null;
        const selectedSource = selectedRadio ? String(selectedRadio.value || '').trim() : '';

        form.querySelectorAll('[data-package-source-panel]').forEach((panel) => {
            const panelSource = String(panel.dataset.packageSourcePanel || '').trim();
            const isActive = selectedSource === '' || panelSource === selectedSource;
            panel.classList.toggle('opacity-50', selectedSource !== '' && !isActive);

            panel.querySelectorAll('input, select, textarea, button').forEach((control) => {
                if (control.hasAttribute('data-package-source-radio') || control.hasAttribute('data-package-source-clear')) {
                    return;
                }

                if (String(control.type || '').toLowerCase() === 'hidden') {
                    return;
                }

                const sourceRequired = control.dataset.packageSourceRequired === '1';

                if (selectedSource !== '' && !isActive) {
                    control.disabled = true;
                    control.required = false;
                    return;
                }

                control.disabled = false;
                if (sourceRequired) {
                    control.required = selectedSource !== '' && isActive;
                }
            });
        });

        form.querySelectorAll('[data-package-source-clear]').forEach((button) => {
            button.classList.toggle('d-none', selectedSource === '');
        });
    };
}

if (typeof window.bindPackageSourceForms !== 'function') {
    window.bindPackageSourceForms = function(root = document) {
        root.querySelectorAll('[data-package-source-form]').forEach((form) => {
            if (form.dataset.boundPackageSource === '1') {
                window.applyPackageSourceSelection(form);
                return;
            }

            form.dataset.boundPackageSource = '1';
            form.querySelectorAll('[data-package-source-radio]').forEach((radio) => {
                radio.addEventListener('change', function() {
                    window.applyPackageSourceSelection(form);
                });
            });
            form.querySelectorAll('[data-package-source-clear]').forEach((button) => {
                button.addEventListener('click', function() {
                    form.querySelectorAll('[data-package-source-radio]').forEach((radio) => {
                        radio.checked = false;
                    });
                    window.applyPackageSourceSelection(form);
                });
            });

            window.applyPackageSourceSelection(form);
        });
    };
}

window.bindPackageFeatureIconPreview();
window.bindPackageFeatureApplyButtons();
window.bindPackageAccountSaleScopes();
window.bindDiscordCatalogSelects();
window.bindPackageSourceForms();

const packageFeatureApplyReplaceButton = document.getElementById('package-feature-apply-replace');
const packageFeatureApplyAddButton = document.getElementById('package-feature-apply-add');
const packageFeatureApplyCancelButton = document.getElementById('package-feature-apply-cancel');
const packageFeatureApplyModal = document.getElementById('package-feature-apply-modal');
const packageFeatureApplyDialog = document.querySelector('[data-package-feature-apply-dialog]');

window.bindPackageFeatureActionButton(packageFeatureApplyReplaceButton, function() {
    window.selectPackageFeatureApplyMode('replace');
});

window.bindPackageFeatureActionButton(packageFeatureApplyAddButton, function() {
    window.selectPackageFeatureApplyMode('add');
});

window.bindPackageFeatureActionButton(packageFeatureApplyCancelButton, function() {
    window.closePackageFeatureApplyModal();
});

if (packageFeatureApplyDialog && !packageFeatureApplyDialog.dataset.boundDismiss) {
    packageFeatureApplyDialog.dataset.boundDismiss = '1';
    packageFeatureApplyDialog.addEventListener('click', function(event) {
        event.stopPropagation();
    });
    packageFeatureApplyDialog.addEventListener('touchend', function(event) {
        event.preventDefault();
        event.stopPropagation();
    }, { passive: false });
}

if (packageFeatureApplyModal && !packageFeatureApplyModal.dataset.boundDismiss) {
    packageFeatureApplyModal.dataset.boundDismiss = '1';
    packageFeatureApplyModal.addEventListener('click', function(event) {
        if (event.target === packageFeatureApplyModal) {
            window.closePackageFeatureApplyModal();
        }
    });
}

if (typeof window.submitAjaxAdminForm !== 'function') {
    window.submitAjaxAdminForm = async function(form, requestData = null) {
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
    };
}

window.adminPackageToggle = async function(input) {
    if (!input || input.dataset.busy === '1' || !input.form) {
        return;
    }

    const form = input.form;
    const valueInput = form.querySelector('.js-ajax-toggle-value');
    const label = form.querySelector('.js-ajax-toggle-label');

    if (valueInput) {
        valueInput.value = input.checked ? '1' : '0';
    }

    const requestData = new FormData(form);
    input.dataset.busy = '1';
    input.disabled = true;

    try {
        const payload = await window.submitAjaxAdminForm(form, requestData);
        input.checked = String(payload.activo || 0) === '1';
        if (valueInput) {
            valueInput.value = input.checked ? '1' : '0';
        }
        if (label) {
            label.textContent = input.checked ? 'Activo' : 'Inactivo';
        }
    } catch (error) {
        input.checked = !input.checked;
        if (valueInput) {
            valueInput.value = input.checked ? '1' : '0';
        }
        window.alert(error.message);
    } finally {
        input.disabled = false;
        input.dataset.busy = '0';
    }
};

window.adminPackageOrderChange = async function(input) {
    if (!input || !input.form) {
        return;
    }

    const form = input.form;
    const normalized = String(Math.max(1, parseInt(input.value || '1', 10) || 1));
    const lastValue = String(input.dataset.lastValue || input.defaultValue || '1');
    if (normalized === lastValue) {
        input.value = normalized;
        return;
    }

    input.value = normalized;
    const requestData = new FormData(form);
    input.readOnly = true;

    try {
        const payload = await window.submitAjaxAdminForm(form, requestData);
        input.dataset.lastValue = String(payload.orden || normalized);
        input.value = input.dataset.lastValue;
    } catch (error) {
        input.value = lastValue;
        window.alert(error.message);
    } finally {
        input.readOnly = false;
    }
};
</script>

<?php include '../includes/footer.php'; ?>