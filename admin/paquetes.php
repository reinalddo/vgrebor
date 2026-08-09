<?php
// admin/paquetes.php - Gestión de paquetes de un juego

require_once '../includes/db_connect.php';
require_once '../includes/tenant.php';
tenant_start_session();
$adminRole = trim((string) ($_SESSION['auth_user']['rol'] ?? ''));
if (!isset($_SESSION['auth_user']) || !in_array($adminRole, ['admin', 'root'], true)) {
    header('Location: ' . app_path('/login.php'));
    exit();
}
require_once '../includes/recargas_api.php';
require_once '../includes/api_discord.php';
require_once '../includes/store_config.php';
require_once '../includes/package_features.php';
require_once '../includes/package_account_sales.php';
require_once '../includes/recharge_availability.php';
require_once '../includes/win_points.php';
require_once '../includes/package_categories.php';
require_once '../includes/levelpass_api.php';
require_once '../includes/fullimpulso_api.php';
require_once '../includes/recargasamerica_api.php';

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

function ensure_juego_paquetes_api_source_key_column(mysqli $mysqli): void {
    $result = $mysqli->query("SHOW COLUMNS FROM juego_paquetes LIKE 'api_source_key'");
    if (!($result instanceof mysqli_result) || $result->num_rows === 0) {
        $mysqli->query("ALTER TABLE juego_paquetes ADD COLUMN api_source_key VARCHAR(120) NULL AFTER api_provider");
    }
}

// Distingue si un paquete de RecargasAmérica es type=pin (entrega códigos)
// o type=recharge (recarga directa a un ID de cuenta) — reutiliza
// paquete_api como ID externo (ya no es ambiguo con GiftVen porque
// api_provider siempre se guarda explícito para paquetes nuevos).
function ensure_juego_paquetes_recargasamerica_tipo_column(mysqli $mysqli): void {
    $result = $mysqli->query("SHOW COLUMNS FROM juego_paquetes LIKE 'recargasamerica_tipo'");
    if (!($result instanceof mysqli_result) || $result->num_rows === 0) {
        $mysqli->query("ALTER TABLE juego_paquetes ADD COLUMN recargasamerica_tipo VARCHAR(20) NULL AFTER api_source_key");
    }
}

function ensure_juego_paquetes_info_html_column(mysqli $mysqli): void {
    $result = $mysqli->query("SHOW COLUMNS FROM juego_paquetes LIKE 'info_html'");
    if (!($result instanceof mysqli_result) || $result->num_rows === 0) {
        $mysqli->query("ALTER TABLE juego_paquetes ADD COLUMN info_html TEXT NULL AFTER imagen_icono");
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

function ensure_juego_paquetes_destacado_column(mysqli $mysqli): void {
    $result = $mysqli->query("SHOW COLUMNS FROM juego_paquetes LIKE 'destacado'");
    if (!($result instanceof mysqli_result) || $result->num_rows === 0) {
        $mysqli->query("ALTER TABLE juego_paquetes ADD COLUMN destacado TINYINT(1) DEFAULT 0 NULL AFTER orden");
    }
}

function ensure_juego_paquetes_descuento_destacado_column(mysqli $mysqli): void {
    $result = $mysqli->query("SHOW COLUMNS FROM juego_paquetes LIKE 'descuento_destacado'");
    if (!($result instanceof mysqli_result) || $result->num_rows === 0) {
        $mysqli->query("ALTER TABLE juego_paquetes ADD COLUMN descuento_destacado TINYINT UNSIGNED DEFAULT 0 NULL AFTER destacado");
    }
}

function ensure_juego_paquetes_precio_manual_override_column(mysqli $mysqli): void {
    $result = $mysqli->query("SHOW COLUMNS FROM juego_paquetes LIKE 'precio_manual_override'");
    if (!($result instanceof mysqli_result) || $result->num_rows === 0) {
        $mysqli->query("ALTER TABLE juego_paquetes ADD COLUMN precio_manual_override TINYINT(1) NOT NULL DEFAULT 0 AFTER descuento_destacado");
    }
}

function ensure_juego_paquetes_orden_gg_column(mysqli $mysqli): void {
    $result = $mysqli->query("SHOW COLUMNS FROM juego_paquetes LIKE 'orden_gg'");
    if (!($result instanceof mysqli_result) || $result->num_rows === 0) {
        $mysqli->query("ALTER TABLE juego_paquetes ADD COLUMN orden_gg SMALLINT UNSIGNED NULL AFTER descuento_destacado");
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
    return in_array($normalized, ['giftven', 'discord', 'free_fire', 'fullimpulso', 'recargasamerica'], true) ? $normalized : '';
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

    if ((int) ($package['fullimpulso_service_id'] ?? 0) > 0) {
        return 'fullimpulso';
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
        'fullimpulso' => 'FullImpulso (Seguidores)',
        'recargasamerica' => 'RecargasAmérica',
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

    if ($provider === 'fullimpulso') {
        $serviceId = (int) ($package['fullimpulso_service_id'] ?? 0);
        $quantity = (int) ($package['fullimpulso_cantidad'] ?? 0);
        return $serviceId > 0 ? 'Servicio ' . $serviceId . ' · ' . number_format($quantity) : '—';
    }

    if ($provider === 'recargasamerica') {
        $apiProductId = (int) ($package['paquete_api'] ?? 0);
        if ($apiProductId <= 0) {
            return '—';
        }
        $tipo = trim((string) ($package['recargasamerica_tipo'] ?? '')) === 'pin' ? 'PIN' : 'Recarga';
        return 'ID ' . $apiProductId . ' · ' . $tipo;
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

function admin_package_info_editor_html(string $fieldName, string $currentHtml): string {
    $sanitized = package_info_sanitize_html($currentHtml);
    $editorId = 'pkg-info-editor-' . preg_replace('/[^a-z0-9_-]/i', '', $fieldName);
    ob_start();
    ?>
    <div data-pkg-info-editor style="border:1px solid rgba(34,211,238,0.35);border-radius:0.6rem;overflow:hidden;background:#0d1420;">
        <div class="d-flex flex-wrap align-items-center gap-1 p-2" style="background:#131c2b;border-bottom:1px solid rgba(34,211,238,0.2);">
            <button type="button" data-pkg-cmd="bold" class="btn btn-sm btn-outline-info fw-bold" title="Negrita" style="min-width:36px;">B</button>
            <button type="button" data-pkg-cmd="italic" class="btn btn-sm btn-outline-info fst-italic" title="Cursiva" style="min-width:36px;">I</button>
            <button type="button" data-pkg-cmd="underline" class="btn btn-sm btn-outline-info text-decoration-underline" title="Subrayado" style="min-width:36px;">S</button>
            <span class="mx-1" style="width:1px;height:22px;background:rgba(34,211,238,0.3);"></span>
            <button type="button" data-pkg-cmd="justifyLeft" class="btn btn-sm btn-outline-info" title="Alinear a la izquierda" style="min-width:36px;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="14" y2="12"/><line x1="3" y1="18" x2="18" y2="18"/></svg>
            </button>
            <button type="button" data-pkg-cmd="justifyCenter" class="btn btn-sm btn-outline-info" title="Centrar" style="min-width:36px;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><line x1="3" y1="6" x2="21" y2="6"/><line x1="6" y1="12" x2="18" y2="12"/><line x1="4" y1="18" x2="20" y2="18"/></svg>
            </button>
            <button type="button" data-pkg-cmd="justifyRight" class="btn btn-sm btn-outline-info" title="Alinear a la derecha" style="min-width:36px;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><line x1="3" y1="6" x2="21" y2="6"/><line x1="10" y1="12" x2="21" y2="12"/><line x1="6" y1="18" x2="21" y2="18"/></svg>
            </button>
            <label class="d-inline-flex align-items-center gap-1 ms-2 mb-0 small" style="color:#8be9fd;cursor:pointer;">
                Color
                <input type="color" data-pkg-cmd-color value="#22d3ee" title="Color de texto" style="width:34px;height:28px;border:1px solid rgba(34,211,238,0.4);border-radius:0.35rem;background:#222c3a;padding:2px;cursor:pointer;">
            </label>
            <label class="d-inline-flex align-items-center gap-1 ms-2 mb-0 small" style="color:#8be9fd;">
                Tamaño
                <select data-pkg-cmd-size class="form-select form-select-sm" style="width:auto;background:#222c3a;color:#22d3ee;border:1px solid rgba(34,211,238,0.4);">
                    <option value="">Normal</option>
                    <option value="1">Pequeño</option>
                    <option value="4">Grande</option>
                    <option value="6">Muy grande</option>
                </select>
            </label>
            <button type="button" data-pkg-cmd="removeFormat" class="btn btn-sm btn-outline-secondary ms-2" title="Quitar formato">Limpiar</button>
        </div>
        <div id="<?= htmlspecialchars($editorId, ENT_QUOTES, 'UTF-8') ?>" contenteditable="true" data-pkg-info-area
             style="min-height:110px;max-height:260px;overflow-y:auto;padding:0.75rem 0.9rem;color:#e2e8f0;line-height:1.6;outline:none;"><?= $sanitized ?></div>
        <textarea name="<?= htmlspecialchars($fieldName, ENT_QUOTES, 'UTF-8') ?>" data-pkg-info-field class="d-none"><?= htmlspecialchars($sanitized, ENT_QUOTES, 'UTF-8') ?></textarea>
    </div>
    <?php
    return trim((string) ob_get_clean());
}

function admin_package_feature_badge_inline_style(array $feature): string {
    $style = 'background:rgba(15,23,42,0.9);border:1px solid rgba(34,211,238,0.28);color:#d8fbff;';
    $custom = function_exists('package_feature_badge_style_attr') ? package_feature_badge_style_attr($feature) : '';
    if ($custom !== '') {
        $style .= $custom;
        if (strpos($custom, 'border-radius:') !== false) {
            $style .= 'border-radius:' . (int) ($feature['border_radius'] ?? 0) . 'px !important;';
        }
    }
    return $style;
}

function admin_package_feature_badges_html(array $features): string {
    if (empty($features)) {
        return '';
    }

    ob_start();
    ?>
    <div class="d-flex flex-wrap gap-2 mt-2">
        <?php foreach ($features as $feature): ?>
            <span class="badge rounded-pill d-inline-flex align-items-center gap-2 px-3 py-2" style="<?= htmlspecialchars(admin_package_feature_badge_inline_style($feature), ENT_QUOTES, 'UTF-8') ?>">
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
ensure_juego_paquetes_api_source_key_column($mysqli);
ensure_juego_paquetes_recargasamerica_tipo_column($mysqli);
ensure_juego_paquetes_info_html_column($mysqli);
ensure_juego_paquetes_cantidad_text_column($mysqli);
ensure_juego_paquetes_orden_column($mysqli);
ensure_juego_paquetes_destacado_column($mysqli);
ensure_juego_paquetes_descuento_destacado_column($mysqli);
ensure_juego_paquetes_precio_manual_override_column($mysqli);
ensure_juego_paquetes_orden_gg_column($mysqli);
ensure_juegos_api_discord_catalog_columns($mysqli);
package_account_sales_ensure_schema($mysqli);
package_features_ensure_schema($mysqli);
win_points_ensure_schema();
package_categories_ensure_schema($mysqli);
levelpass_ensure_schema($mysqli);
fullimpulso_ensure_schema($mysqli);

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
$adminPackageMarkupPct = floatval($juego['precio_markup_pct'] ?? 0);
$adminPackageMarkupPctRecargasamerica = floatval($juego['precio_markup_pct_recargasamerica'] ?? 0);

// Precio API + margen correcto para un paquete, sin importar el proveedor —
// mismo criterio que game.php: se distingue por api_provider ANTES de
// mirar los catálogos (IDs de GiftVen y RecargasAmérica son de sistemas
// independientes y pueden coincidir por coincidencia), y cada proveedor usa
// su propio margen de ganancia (no pueden compartir el mismo %).
function admin_package_raw_price_and_markup(array $package, array $apiProductsById, array $recargasAmericaProductsById, float $markupGiftven, float $markupRecargasamerica): array {
    $apiId = (int) ($package['paquete_api'] ?? 0);
    $provider = trim((string) ($package['api_provider'] ?? ''));

    if ($apiId > 0 && $provider === 'recargasamerica' && isset($recargasAmericaProductsById[$apiId])) {
        return [floatval($recargasAmericaProductsById[$apiId]['price'] ?? 0), $markupRecargasamerica];
    }

    if ($apiId > 0 && $provider !== 'recargasamerica' && isset($apiProductsById[$apiId])) {
        return [floatval($apiProductsById[$apiId]['precio']), $markupGiftven];
    }

    return [null, 0.0];
}
$packageCategories = package_category_list($mysqli, $juego_id);
$packageCategoryNotice = trim((string) ($_GET['package_category_notice'] ?? ''));
$packageCategoryError = trim((string) ($_GET['package_category_error'] ?? ''));
$freeFireApiOptions = free_fire_api_amount_options();
$fullimpulsoServices = [];
$fullimpulsoServicesError = '';
if (fullimpulso_is_configured()) {
    try {
        $fullimpulsoServices = fullimpulso_api_fetch_services();
    } catch (Throwable $e) {
        $fullimpulsoServicesError = $e->getMessage();
    }
}
// Auto-corrige paquetes existentes cuyo flag "fullimpulso_custom_comments"
// haya quedado desactualizado (p.ej. guardados antes de que existiera la
// detección, o cuando el proveedor no reportó el "type" correctamente en su
// momento). Solo corrige de 0 -> 1 (nunca apaga un override manual del
// admin), y no cuesta una petición extra porque $fullimpulsoServices ya se
// obtuvo arriba para poblar los selects de este formulario.
if (!empty($fullimpulsoServices)) {
    $fiReconcileResult = $mysqli->query(
        'SELECT id, fullimpulso_service_id FROM juego_paquetes WHERE juego_id = ' . (int) $juego_id
        . ' AND fullimpulso_service_id IS NOT NULL AND fullimpulso_service_id > 0 AND COALESCE(fullimpulso_custom_comments, 0) = 0'
    );
    if ($fiReconcileResult instanceof mysqli_result) {
        while ($fiRow = $fiReconcileResult->fetch_assoc()) {
            $fiDetected = fullimpulso_service_type_is_custom_comments(
                fullimpulso_service_type_for_id($fullimpulsoServices, (int) $fiRow['fullimpulso_service_id'])
            );
            if ($fiDetected) {
                $mysqli->query('UPDATE juego_paquetes SET fullimpulso_custom_comments = 1 WHERE id = ' . (int) $fiRow['id']);
            }
        }
        $fiReconcileResult->free();
    }
}
$discordApiEnabled = trim((string) store_config_get('api_discord', '0')) === '1';
$unionApisEnabled = $discordApiEnabled && trim((string) store_config_get('union_apis_discord_giftven', '0')) === '1';
$juegoCategoriaApi        = trim((string) ($juego['categoria_api'] ?? ''));
$juegoCategoriaApi2       = trim((string) ($juego['categoria_api_2'] ?? ''));
$juegoCategoriaApi3       = trim((string) ($juego['categoria_api_3'] ?? ''));
$juegoCategoriaApiDiscord  = trim((string) ($juego['categoria_api_discord'] ?? ''));
$juegoCategoriaApiDiscord2 = trim((string) ($juego['categoria_api_discord_2'] ?? ''));
$juegoCategoriaApiDiscord3 = trim((string) ($juego['categoria_api_discord_3'] ?? ''));
$juegoCategoriaApiRecargasAmerica  = trim((string) ($juego['categoria_api_recargasamerica'] ?? ''));
$juegoCategoriaApiRecargasAmerica2 = trim((string) ($juego['categoria_api_recargasamerica_2'] ?? ''));
$juegoCategoriaApiRecargasAmerica3 = trim((string) ($juego['categoria_api_recargasamerica_3'] ?? ''));
$hasGiftVenCatalog  = $juegoCategoriaApi !== '';
$hasGiftVenCatalog2 = $juegoCategoriaApi2 !== '';
$hasGiftVenCatalog3 = $juegoCategoriaApi3 !== '';
$hasDiscordCatalog  = $juegoCategoriaApiDiscord !== '' && $discordApiEnabled;
$hasDiscordCatalog2 = $juegoCategoriaApiDiscord2 !== '' && $discordApiEnabled;
$hasDiscordCatalog3 = $juegoCategoriaApiDiscord3 !== '' && $discordApiEnabled;
$recargasAmericaApiAvailableGlobal = function_exists('recargasamerica_api_is_configured') && recargasamerica_api_is_configured();
$hasRecargasAmericaCatalog  = $juegoCategoriaApiRecargasAmerica !== '' && $recargasAmericaApiAvailableGlobal;
$hasRecargasAmericaCatalog2 = $juegoCategoriaApiRecargasAmerica2 !== '' && $recargasAmericaApiAvailableGlobal;
$hasRecargasAmericaCatalog3 = $juegoCategoriaApiRecargasAmerica3 !== '' && $recargasAmericaApiAvailableGlobal;
$usesApiCatalog     = $hasGiftVenCatalog;
$usesLegacyFreeFire = !$hasGiftVenCatalog && !$hasDiscordCatalog && !empty($juego['api_free_fire']);

// Count configured slots per provider
$giftVenActiveSlots = ($hasGiftVenCatalog ? 1 : 0) + ($hasGiftVenCatalog2 ? 1 : 0) + ($hasGiftVenCatalog3 ? 1 : 0);
$discordActiveSlots = ($hasDiscordCatalog ? 1 : 0) + ($hasDiscordCatalog2 ? 1 : 0) + ($hasDiscordCatalog3 ? 1 : 0);

// Build structured source items (each represents one radio option for the package form)
$packageSourceItems = [];
$packageSourceValueMap = [];
if ($giftVenActiveSlots > 1) {
    if ($hasGiftVenCatalog)  $packageSourceItems[] = ['value' => 'giftven_1', 'provider' => 'giftven', 'source_key' => $juegoCategoriaApi,  'label' => 'TiendaGiftVen: ' . $juegoCategoriaApi];
    if ($hasGiftVenCatalog2) $packageSourceItems[] = ['value' => 'giftven_2', 'provider' => 'giftven', 'source_key' => $juegoCategoriaApi2, 'label' => 'TiendaGiftVen: ' . $juegoCategoriaApi2];
    if ($hasGiftVenCatalog3) $packageSourceItems[] = ['value' => 'giftven_3', 'provider' => 'giftven', 'source_key' => $juegoCategoriaApi3, 'label' => 'TiendaGiftVen: ' . $juegoCategoriaApi3];
} elseif ($hasGiftVenCatalog) {
    $packageSourceItems[] = ['value' => 'giftven', 'provider' => 'giftven', 'source_key' => $juegoCategoriaApi, 'label' => admin_package_provider_label('giftven')];
}
if ($discordActiveSlots > 1) {
    if ($hasDiscordCatalog) {
        $dc1Cmd = api_discord_find_command($juegoCategoriaApiDiscord);
        $packageSourceItems[] = ['value' => 'discord_1', 'provider' => 'discord', 'source_key' => $juegoCategoriaApiDiscord,  'label' => 'Discord: ' . trim((string) ($dc1Cmd['label'] ?? $juegoCategoriaApiDiscord))];
    }
    if ($hasDiscordCatalog2) {
        $dc2Cmd = api_discord_find_command($juegoCategoriaApiDiscord2);
        $packageSourceItems[] = ['value' => 'discord_2', 'provider' => 'discord', 'source_key' => $juegoCategoriaApiDiscord2, 'label' => 'Discord: ' . trim((string) ($dc2Cmd['label'] ?? $juegoCategoriaApiDiscord2))];
    }
    if ($hasDiscordCatalog3) {
        $dc3Cmd = api_discord_find_command($juegoCategoriaApiDiscord3);
        $packageSourceItems[] = ['value' => 'discord_3', 'provider' => 'discord', 'source_key' => $juegoCategoriaApiDiscord3, 'label' => 'Discord: ' . trim((string) ($dc3Cmd['label'] ?? $juegoCategoriaApiDiscord3))];
    }
} elseif ($hasDiscordCatalog) {
    $packageSourceItems[] = ['value' => 'discord', 'provider' => 'discord', 'source_key' => $juegoCategoriaApiDiscord, 'label' => admin_package_provider_label('discord')];
}
if ($usesLegacyFreeFire) {
    $packageSourceItems[] = ['value' => 'free_fire', 'provider' => 'free_fire', 'source_key' => '', 'label' => admin_package_provider_label('free_fire')];
}
// RecargasAmérica no tiene "categoría" real en su API (su catálogo de
// /products/pins mezcla productos de todos los juegos) — cada slot guarda
// una PALABRA CLAVE de texto libre que se usa para pre-filtrar el catálogo
// completo por nombre de producto, en vez de una categoría real como
// GiftVen. Mismo patrón de slots 1/2/3 para que el juego pueda tener varios
// filtros distintos (ej. "Free Fire" y "FF").
$recargasAmericaActiveSlots = ($hasRecargasAmericaCatalog ? 1 : 0) + ($hasRecargasAmericaCatalog2 ? 1 : 0) + ($hasRecargasAmericaCatalog3 ? 1 : 0);
if ($recargasAmericaActiveSlots > 1) {
    if ($hasRecargasAmericaCatalog)  $packageSourceItems[] = ['value' => 'recargasamerica_1', 'provider' => 'recargasamerica', 'source_key' => $juegoCategoriaApiRecargasAmerica,  'label' => 'RecargasAmérica: ' . $juegoCategoriaApiRecargasAmerica];
    if ($hasRecargasAmericaCatalog2) $packageSourceItems[] = ['value' => 'recargasamerica_2', 'provider' => 'recargasamerica', 'source_key' => $juegoCategoriaApiRecargasAmerica2, 'label' => 'RecargasAmérica: ' . $juegoCategoriaApiRecargasAmerica2];
    if ($hasRecargasAmericaCatalog3) $packageSourceItems[] = ['value' => 'recargasamerica_3', 'provider' => 'recargasamerica', 'source_key' => $juegoCategoriaApiRecargasAmerica3, 'label' => 'RecargasAmérica: ' . $juegoCategoriaApiRecargasAmerica3];
} elseif ($hasRecargasAmericaCatalog) {
    $packageSourceItems[] = ['value' => 'recargasamerica', 'provider' => 'recargasamerica', 'source_key' => $juegoCategoriaApiRecargasAmerica, 'label' => admin_package_provider_label('recargasamerica')];
}
foreach ($packageSourceItems as $item) {
    $packageSourceValueMap[$item['value']] = ['provider' => $item['provider'], 'source_key' => $item['source_key']];
}
$packageSourceSelectionEnabled = count($packageSourceItems) > 1;
$packageDefaultSourceValue = $packageSourceSelectionEnabled ? '' : ($packageSourceItems[0]['value'] ?? '');
// Keep backward-compat alias used in hidden inputs
$packageDefaultProvider = $packageSourceSelectionEnabled ? '' : ($packageSourceItems[0]['provider'] ?? '');

$winPointsName = win_points_program_name();
$defaultWinPointsReward = 0;
$apiProducts  = [];
$apiProducts2 = [];
$apiProducts3 = [];
$apiProductsById = [];
$apiProductsError = null;
$discordTopupCommand  = $hasDiscordCatalog  ? api_discord_find_command($juegoCategoriaApiDiscord)  : null;
$discordTopupCommand2 = $hasDiscordCatalog2 ? api_discord_find_command($juegoCategoriaApiDiscord2) : null;
$discordTopupCommand3 = $hasDiscordCatalog3 ? api_discord_find_command($juegoCategoriaApiDiscord3) : null;
$discordPriceCommand  = $hasDiscordCatalog  ? admin_package_find_api_discord_price_command($juegoCategoriaApiDiscord)  : null;
$discordTopupCommandText  = $discordTopupCommand  ? api_discord_sample_command_text($discordTopupCommand)  : '';
$discordTopupCommandText2 = $discordTopupCommand2 ? api_discord_sample_command_text($discordTopupCommand2) : '';
$discordTopupCommandText3 = $discordTopupCommand3 ? api_discord_sample_command_text($discordTopupCommand3) : '';
$discordPriceCommandText  = $discordPriceCommand  ? api_discord_sample_command_text($discordPriceCommand)  : '';
$discordCatalogStatus    = strtolower(trim((string) ($juego['api_discord_catalog_status'] ?? '')));
$discordCatalogRaw       = trim((string) ($juego['api_discord_catalog_raw'] ?? ''));
$discordCatalogMessageId = trim((string) ($juego['api_discord_catalog_message_id'] ?? ''));
$discordCatalogUpdatedAt = trim((string) ($juego['api_discord_catalog_updated_at'] ?? ''));
$discordCatalogJson      = trim((string) ($juego['api_discord_catalog_json'] ?? ''));
$discordCatalogItems = [];
if ($discordCatalogJson !== '') {
    $decodedDiscordCatalog = json_decode($discordCatalogJson, true);
    if (is_array($decodedDiscordCatalog)) {
        $discordCatalogItems = api_discord_normalize_catalog_items($decodedDiscordCatalog);
    }
}
$discordCatalogNotice = trim((string) ($_GET['discord_catalog_notice'] ?? ''));
$discordCatalogError  = trim((string) ($_GET['discord_catalog_error'] ?? ''));
$packageError         = trim((string) ($_GET['package_error'] ?? ''));

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
if ($hasGiftVenCatalog2) {
    try {
        $apiProducts2 = recargas_api_fetch_products_by_category($juegoCategoriaApi2);
        foreach ($apiProducts2 as $apiProduct) {
            $apiProductsById[(int) ($apiProduct['id'] ?? 0)] = $apiProduct;
        }
    } catch (Throwable $e) {
        if ($apiProductsError === null) {
            $apiProductsError = $e->getMessage();
        }
    }
}
if ($hasGiftVenCatalog3) {
    try {
        $apiProducts3 = recargas_api_fetch_products_by_category($juegoCategoriaApi3);
        foreach ($apiProducts3 as $apiProduct) {
            $apiProductsById[(int) ($apiProduct['id'] ?? 0)] = $apiProduct;
        }
    } catch (Throwable $e) {
        if ($apiProductsError === null) {
            $apiProductsError = $e->getMessage();
        }
    }
}

$recargasAmericaAvailable = $recargasAmericaApiAvailableGlobal;
$recargasAmericaProducts = [];
$recargasAmericaProductsById = [];
$recargasAmericaProductsError = null;
if ($recargasAmericaAvailable) {
    try {
        $recargasAmericaProducts = recargasamerica_api_fetch_products_pins();
        foreach ($recargasAmericaProducts as $raProduct) {
            $recargasAmericaProductsById[(int) ($raProduct['id'] ?? 0)] = $raProduct;
        }
    } catch (Throwable $e) {
        $recargasAmericaProductsError = $e->getMessage();
    }
}

// Catálogo pre-filtrado por palabra clave, uno por cada slot activo del
// juego (ver categoria_api_recargasamerica en admin/juegos.php) — evita que
// el admin tenga que buscar a mano entre el catálogo completo (mezcla de
// todos los juegos).
function admin_package_filter_recargasamerica_products(array $products, string $keyword): array {
    $keyword = mb_strtolower(trim($keyword), 'UTF-8');
    if ($keyword === '') {
        return $products;
    }
    return array_values(array_filter($products, static function (array $product) use ($keyword): bool {
        return str_contains(mb_strtolower((string) ($product['name'] ?? ''), 'UTF-8'), $keyword);
    }));
}
$recargasAmericaProducts1 = $hasRecargasAmericaCatalog  ? admin_package_filter_recargasamerica_products($recargasAmericaProducts, $juegoCategoriaApiRecargasAmerica)  : [];
$recargasAmericaProducts2 = $hasRecargasAmericaCatalog2 ? admin_package_filter_recargasamerica_products($recargasAmericaProducts, $juegoCategoriaApiRecargasAmerica2) : [];
$recargasAmericaProducts3 = $hasRecargasAmericaCatalog3 ? admin_package_filter_recargasamerica_products($recargasAmericaProducts, $juegoCategoriaApiRecargasAmerica3) : [];

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
    $featureIconCustom = trim((string) ($_POST['package_feature_icon_custom'] ?? ''));
    if ($featureIconCustom !== '') {
        $featureIcon = $featureIconCustom;
    }
    $catalogApplyMode = admin_package_normalize_apply_mode((string) ($_POST['package_feature_apply_mode'] ?? ''));
    $catalogAppliedFeatureId = 0;

    if ($catalogAction === 'create') {
        $catalogAppliedFeatureId = package_feature_catalog_find_or_create($mysqli, $featureName, $featureIcon);
    } elseif ($catalogAction === 'update' && $featureId > 0) {
        $featureUseCustomStyle = trim((string) ($_POST['package_feature_use_style'] ?? '')) === '1';
        $featureBgColor = $featureUseCustomStyle ? (string) ($_POST['package_feature_bg_color'] ?? '') : '';
        $featureTextColor = $featureUseCustomStyle ? (string) ($_POST['package_feature_text_color'] ?? '') : '';
        $featureRadius = $featureUseCustomStyle ? ($_POST['package_feature_border_radius'] ?? '') : '';
        package_feature_catalog_update($mysqli, $featureId, $featureName, $featureIcon, $featureBgColor, $featureTextColor, $featureRadius);
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_descuento_destacado'], $_POST['paquete_id'])) {
    $packageId = intval($_POST['paquete_id']);
    $descuento = max(0, min(99, (int) ($_POST['descuento_destacado'] ?? 0)));
    if ($packageId > 0) {
        $stmtDesc = $mysqli->prepare("UPDATE juego_paquetes SET descuento_destacado = ? WHERE id = ? AND juego_id = ?");
        $stmtDesc->bind_param('iii', $descuento, $packageId, $juego_id);
        $stmtDesc->execute();
        $stmtDesc->close();
        if (admin_packages_is_ajax_request()) {
            admin_packages_json_response(['ok' => true, 'id' => $packageId, 'descuento_destacado' => $descuento]);
        }
    }
    header('Location: ' . $adminPackageBaseUrl . '/' . $juego_id);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_paquete_destacado'], $_POST['paquete_id'], $_POST['destacado'])) {
    $packageId = intval($_POST['paquete_id']);
    $destValue = intval($_POST['destacado']) === 1 ? 1 : 0;
    if ($packageId > 0) {
        $stmtDest = $mysqli->prepare("UPDATE juego_paquetes SET destacado = ? WHERE id = ? AND juego_id = ?");
        $stmtDest->bind_param('iii', $destValue, $packageId, $juego_id);
        $stmtDest->execute();
        $stmtDest->close();
        if (admin_packages_is_ajax_request()) {
            admin_packages_json_response(['ok' => true, 'id' => $packageId, 'destacado' => $destValue]);
        }
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_categoria_paquete'], $_POST['paquete_id'])) {
    $packageId = intval($_POST['paquete_id']);
    $categoryId = (int) ($_POST['categoria_paquete_id'] ?? 0);
    if ($packageId > 0) {
        $stmtOwner = $mysqli->prepare("SELECT id FROM juego_paquetes WHERE id = ? AND juego_id = ?");
        $stmtOwner->bind_param('ii', $packageId, $juego_id);
        $stmtOwner->execute();
        $ownsPackage = $stmtOwner->get_result()->num_rows > 0;
        $stmtOwner->close();
        if ($ownsPackage && ($categoryId <= 0 || in_array($categoryId, array_map(static fn (array $c): int => (int) $c['id'], $packageCategories), true))) {
            package_set_category($mysqli, $packageId, $categoryId);
            if (admin_packages_is_ajax_request()) {
                admin_packages_json_response(['ok' => true, 'id' => $packageId, 'categoria_paquete_id' => $categoryId]);
            }
        } elseif (admin_packages_is_ajax_request()) {
            admin_packages_json_response(['ok' => false, 'message' => 'Categoría inválida para este juego.'], 422);
        }
    }
    header('Location: ' . $adminPackageBaseUrl . '/' . $juego_id);
    exit;
}

// Ajuste masivo de precios
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_price_adjust'])) {
    $rawPct = trim((string) ($_POST['bulk_price_adjust_pct'] ?? ''));
    if (!is_numeric($rawPct)) {
        admin_packages_redirect($adminPackageBaseUrl . '/' . $juego_id, ['package_error' => 'El porcentaje debe ser un número válido.']);
    }
    $pct = floatval($rawPct);
    if ($pct <= -100 || $pct > 1000) {
        admin_packages_redirect($adminPackageBaseUrl . '/' . $juego_id, ['package_error' => 'Porcentaje fuera de rango. Usa entre -99.9 y 1000.']);
    }
    $multiplier = 1 + ($pct / 100);
    $allPackages = $mysqli->query("SELECT id, precio FROM juego_paquetes WHERE juego_id=" . intval($juego_id));
    if ($allPackages) {
        $stmtBulk = $mysqli->prepare("UPDATE juego_paquetes SET precio=? WHERE id=?");
        if ($stmtBulk) {
            while ($pkgRow = $allPackages->fetch_assoc()) {
                $newPrice = round(floatval($pkgRow['precio']) * $multiplier, 2);
                if ($newPrice < 0) {
                    $newPrice = 0.00;
                }
                $stmtBulk->bind_param('di', $newPrice, $pkgRow['id']);
                $stmtBulk->execute();
            }
            $stmtBulk->close();
        }
    }
    $sign = $pct >= 0 ? '+' : '';
    admin_packages_redirect($adminPackageBaseUrl . '/' . $juego_id, ['discord_catalog_notice' => 'Precios actualizados: ' . $sign . number_format($pct, 1, '.', '') . '% aplicado a todos los paquetes.']);
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
    $rawEditSourceValue = trim((string) ($_POST['edit_api_provider'] ?? $packageDefaultSourceValue));
    if (($rawEditSourceValue === '' && !empty($packageSourceItems)) || ($rawEditSourceValue !== '' && !isset($packageSourceValueMap[$rawEditSourceValue]))) {
        admin_packages_redirect($adminPackageBaseUrl . '/' . $juego_id, ['package_error' => 'Selecciona un origen válido para este paquete antes de guardarlo.']);
    }
    $edit_provider      = $packageSourceValueMap[$rawEditSourceValue]['provider'] ?? admin_package_normalize_provider_value($rawEditSourceValue);
    $edit_api_source_key = $packageSourceValueMap[$rawEditSourceValue]['source_key'] ?? '';
    $edit_monto_ff = $edit_provider === 'free_fire' ? trim((string) ($_POST['edit_monto_ff'] ?? '')) : '';
    $edit_paquete_api = in_array($edit_provider, ['giftven', 'recargasamerica'], true) ? trim((string) ($_POST['edit_paquete_api'] ?? '')) : '';
    $edit_recargasamerica_tipo = '';
    if ($edit_provider === 'recargasamerica' && $edit_paquete_api !== '') {
        $raEditSelectedProduct = $recargasAmericaProductsById[(int) $edit_paquete_api] ?? null;
        $edit_recargasamerica_tipo = $raEditSelectedProduct ? recargasamerica_api_product_type($raEditSelectedProduct) : '';
    }
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
    $edit_destacado = isset($_POST['edit_destacado']) ? 1 : 0;
    $edit_descuento_destacado = max(0, min(99, (int) ($_POST['edit_descuento_destacado'] ?? 0)));
    $edit_orden_gg = ($_POST['edit_orden_gg'] ?? '') !== '' ? max(0, (int) $_POST['edit_orden_gg']) : null;
    $edit_precio_manual_override = isset($_POST['edit_precio_manual_override']) ? 1 : 0;
    $edit_categoria_paquete_id = (int) ($_POST['edit_categoria_paquete_id'] ?? 0);
    $edit_levelpass_key = levelpass_normalize_key($_POST['edit_levelpass_key'] ?? '');
    $edit_fullimpulsoEnabled = isset($_POST['edit_fullimpulso_enabled']) && (string) $_POST['edit_fullimpulso_enabled'] === '1';
    $edit_fullimpulso_service_id = $edit_fullimpulsoEnabled ? (int) ($_POST['edit_fullimpulso_service_id'] ?? 0) : 0;
    $edit_fullimpulso_cantidad = $edit_fullimpulsoEnabled ? max(0, (int) ($_POST['edit_fullimpulso_cantidad'] ?? 0)) : 0;
    if ($edit_fullimpulsoEnabled) {
        $edit_provider = 'fullimpulso';
        if ($edit_fullimpulso_service_id <= 0 || $edit_fullimpulso_cantidad <= 0) {
            admin_packages_redirect($adminPackageBaseUrl . '/' . $juego_id, ['package_error' => 'Selecciona el servicio y la cantidad de FullImpulso para este paquete.']);
        }
    }
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
    if ($edit_provider === 'recargasamerica' && ($edit_paquete_api === '' || $edit_recargasamerica_tipo === '')) {
        admin_packages_redirect($adminPackageBaseUrl . '/' . $juego_id, ['package_error' => 'Selecciona el producto de RecargasAmérica para este paquete.']);
    }
    if ($edit_provider === 'free_fire' && $edit_monto_ff === '') {
        admin_packages_redirect($adminPackageBaseUrl . '/' . $juego_id, ['package_error' => 'Selecciona el monto de Free Fire para este paquete.']);
    }
    if ($edit_imagen_icono) {
        $stmt = $mysqli->prepare("UPDATE juego_paquetes SET nombre=?, clave=?, monto_ff=NULLIF(?, ''), paquete_api=NULLIF(?, ''), api_provider=?, api_source_key=NULLIF(?, ''), recargasamerica_tipo=NULLIF(?, ''), vender_cuenta=?, cuenta_texto=NULLIF(?, ''), cantidad=?, precio=?, win_points_reward=?, imagen_icono=?, activo=?, destacado=?, descuento_destacado=?, orden_gg=?, precio_manual_override=? WHERE id=?");
        $stmt->bind_param('sssssssissdisiiiiii', $edit_nombre, $edit_clave, $edit_monto_ff, $edit_paquete_api, $edit_provider, $edit_api_source_key, $edit_recargasamerica_tipo, $edit_vender_cuenta, $edit_cuenta_texto, $edit_cantidad, $edit_precio, $edit_win_points_reward, $edit_imagen_icono, $edit_activo, $edit_destacado, $edit_descuento_destacado, $edit_orden_gg, $edit_precio_manual_override, $edit_id);
    } else {
        $stmt = $mysqli->prepare("UPDATE juego_paquetes SET nombre=?, clave=?, monto_ff=NULLIF(?, ''), paquete_api=NULLIF(?, ''), api_provider=?, api_source_key=NULLIF(?, ''), recargasamerica_tipo=NULLIF(?, ''), vender_cuenta=?, cuenta_texto=NULLIF(?, ''), cantidad=?, precio=?, win_points_reward=?, activo=?, destacado=?, descuento_destacado=?, orden_gg=?, precio_manual_override=? WHERE id=?");
        $stmt->bind_param('sssssssissdiiiiiii', $edit_nombre, $edit_clave, $edit_monto_ff, $edit_paquete_api, $edit_provider, $edit_api_source_key, $edit_recargasamerica_tipo, $edit_vender_cuenta, $edit_cuenta_texto, $edit_cantidad, $edit_precio, $edit_win_points_reward, $edit_activo, $edit_destacado, $edit_descuento_destacado, $edit_orden_gg, $edit_precio_manual_override, $edit_id);
    }
    $stmt->execute();
    $stmt->close();
    package_set_category($mysqli, $edit_id, $edit_categoria_paquete_id);
    levelpass_set_key($mysqli, $edit_id, $edit_levelpass_key);
    $editFullimpulsoCustomComments = $edit_fullimpulso_service_id > 0
        && (
            fullimpulso_service_type_is_custom_comments(fullimpulso_service_type_for_id($fullimpulsoServices, $edit_fullimpulso_service_id))
            || isset($_POST['edit_fullimpulso_custom_comments_manual'])
        );
    fullimpulso_set_package($mysqli, $edit_id, $edit_fullimpulso_service_id, $edit_fullimpulso_cantidad, $editFullimpulsoCustomComments);
    $editInfoHtml = package_info_sanitize_html((string) ($_POST['edit_info_paquete_html'] ?? ''));
    $stmtEditInfo = $mysqli->prepare("UPDATE juego_paquetes SET info_html = NULLIF(?, '') WHERE id = ?");
    if ($stmtEditInfo) {
        $stmtEditInfo->bind_param('si', $editInfoHtml, $edit_id);
        $stmtEditInfo->execute();
        $stmtEditInfo->close();
    }
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
    $rawSourceValue = trim((string) ($_POST['api_provider'] ?? $packageDefaultSourceValue));
    if (($rawSourceValue === '' && !empty($packageSourceItems)) || ($rawSourceValue !== '' && !isset($packageSourceValueMap[$rawSourceValue]))) {
        admin_packages_redirect($adminPackageBaseUrl . '/' . $juego_id, ['package_error' => 'Selecciona el origen del paquete antes de guardarlo.']);
    }
    $provider      = $packageSourceValueMap[$rawSourceValue]['provider'] ?? admin_package_normalize_provider_value($rawSourceValue);
    $api_source_key = $packageSourceValueMap[$rawSourceValue]['source_key'] ?? '';
    $monto_ff = $provider === 'free_fire' ? trim((string) ($_POST['monto_ff'] ?? '')) : '';
    $paquete_api = in_array($provider, ['giftven', 'recargasamerica'], true) ? trim((string) ($_POST['paquete_api'] ?? '')) : '';
    // El "tipo" (pin/recharge) NUNCA se confía del formulario — se resuelve
    // en vivo contra el catálogo de RecargasAmérica por el ID elegido, para
    // que no se pueda desincronizar con lo que la API realmente tiene.
    $recargasamerica_tipo = '';
    if ($provider === 'recargasamerica' && $paquete_api !== '') {
        $raSelectedProduct = $recargasAmericaProductsById[(int) $paquete_api] ?? null;
        $recargasamerica_tipo = $raSelectedProduct ? recargasamerica_api_product_type($raSelectedProduct) : '';
    }
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
    $destacado = isset($_POST['destacado']) ? 1 : 0;
    $descuento_destacado = max(0, min(99, (int) ($_POST['descuento_destacado'] ?? 0)));
    $orden_gg = ($_POST['orden_gg'] ?? '') !== '' ? max(0, (int) $_POST['orden_gg']) : null;
    $precio_manual_override = isset($_POST['precio_manual_override']) ? 1 : 0;
    $categoria_paquete_id = (int) ($_POST['categoria_paquete_id'] ?? 0);
    $levelpass_key = levelpass_normalize_key($_POST['levelpass_key'] ?? '');
    // FullImpulso es un toggle independiente del radio giftven/discord/free_fire
    // (igual que "Nivel de Pase"): si se marca, sobreescribe el proveedor del
    // paquete sin tocar la lógica existente del radio.
    $fullimpulsoEnabled = isset($_POST['fullimpulso_enabled']) && (string) $_POST['fullimpulso_enabled'] === '1';
    $fullimpulso_service_id = $fullimpulsoEnabled ? (int) ($_POST['fullimpulso_service_id'] ?? 0) : 0;
    $fullimpulso_cantidad = $fullimpulsoEnabled ? max(0, (int) ($_POST['fullimpulso_cantidad'] ?? 0)) : 0;
    if ($fullimpulsoEnabled) {
        $provider = 'fullimpulso';
        if ($fullimpulso_service_id <= 0 || $fullimpulso_cantidad <= 0) {
            admin_packages_redirect($adminPackageBaseUrl . '/' . $juego_id, ['package_error' => 'Selecciona el servicio y la cantidad de FullImpulso para este paquete.']);
        }
    }
    $orden = admin_package_next_order($mysqli, $juego_id);
    $imagen_icono = admin_package_store_upload($_FILES['imagen_icono'] ?? []);
    $newGalleryFiles = admin_package_normalize_uploaded_file_list($_FILES['new_account_gallery_image'] ?? []);
    $newGalleryPayload = $accountSaleFeatureEnabled
        ? admin_package_build_account_gallery_payload([], [], [], [], $_POST['new_account_gallery_description'] ?? [], $newGalleryFiles)
        : ['items' => [], 'delete_paths' => []];
    if ($provider === 'giftven' && $paquete_api === '') {
        admin_packages_redirect($adminPackageBaseUrl . '/' . $juego_id, ['package_error' => 'Selecciona el producto de TiendaGiftVen para este paquete.']);
    }
    if ($provider === 'recargasamerica' && ($paquete_api === '' || $recargasamerica_tipo === '')) {
        admin_packages_redirect($adminPackageBaseUrl . '/' . $juego_id, ['package_error' => 'Selecciona el producto de RecargasAmérica para este paquete.']);
    }
    if ($provider === 'free_fire' && $monto_ff === '') {
        admin_packages_redirect($adminPackageBaseUrl . '/' . $juego_id, ['package_error' => 'Selecciona el monto de Free Fire para este paquete.']);
    }
    $stmt = $mysqli->prepare("INSERT INTO juego_paquetes (juego_id, nombre, clave, monto_ff, paquete_api, api_provider, api_source_key, recargasamerica_tipo, vender_cuenta, cuenta_texto, cantidad, precio, win_points_reward, imagen_icono, activo, orden, destacado, descuento_destacado, orden_gg, precio_manual_override) VALUES (?, ?, ?, NULLIF(?, ''), NULLIF(?, ''), ?, NULLIF(?, ''), NULLIF(?, ''), ?, NULLIF(?, ''), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param('isssssssissdisiiiiii', $juego_id, $nombre, $clave, $monto_ff, $paquete_api, $provider, $api_source_key, $recargasamerica_tipo, $vender_cuenta, $cuenta_texto, $cantidad, $precio, $win_points_reward, $imagen_icono, $activo, $orden, $destacado, $descuento_destacado, $orden_gg, $precio_manual_override);
    $stmt->execute();
    $newPackageId = (int) $mysqli->insert_id;
    $stmt->close();
    package_set_category($mysqli, $newPackageId, $categoria_paquete_id);
    levelpass_set_key($mysqli, $newPackageId, $levelpass_key);
    $fullimpulsoCustomComments = $fullimpulso_service_id > 0
        && (
            fullimpulso_service_type_is_custom_comments(fullimpulso_service_type_for_id($fullimpulsoServices, $fullimpulso_service_id))
            || isset($_POST['fullimpulso_custom_comments_manual'])
        );
    fullimpulso_set_package($mysqli, $newPackageId, $fullimpulso_service_id, $fullimpulso_cantidad, $fullimpulsoCustomComments);
    $infoHtml = package_info_sanitize_html((string) ($_POST['info_paquete_html'] ?? ''));
    $stmtInfo = $mysqli->prepare("UPDATE juego_paquetes SET info_html = NULLIF(?, '') WHERE id = ?");
    if ($stmtInfo) {
        $stmtInfo->bind_param('si', $infoHtml, $newPackageId);
        $stmtInfo->execute();
        $stmtInfo->close();
    }
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
$packageCategoriesById = [];
foreach ($packageCategories as $pcatItem) {
    $packageCategoriesById[(int) $pcatItem['id']] = $pcatItem;
}
$packageCategoryTabCounts = ['otros' => 0];
foreach ($packageCategories as $pcatItem) {
    $packageCategoryTabCounts[(int) $pcatItem['id']] = 0;
}
foreach ($paquetes as $pkgRow) {
    $pkgCatId = (int) ($pkgRow['categoria_paquete_id'] ?? 0);
    if ($pkgCatId > 0 && isset($packageCategoryTabCounts[$pkgCatId])) {
        $packageCategoryTabCounts[$pkgCatId]++;
    } else {
        $packageCategoryTabCounts['otros']++;
    }
}

// Incluir header
include '../includes/header.php';
?>
<main class="container py-4">
    <h2 class="mb-4 text-neon">Paquetes de <?= htmlspecialchars($juego['nombre'] ?? 'Juego') ?></h2>
    <?php if ($packageCategoryNotice !== ''): ?>
        <div class="alert alert-info mb-4" style="background:#0f2a1a;color:#c8ffe0;border:1px solid #22d3ee;"><?= htmlspecialchars($packageCategoryNotice, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <?php if ($packageCategoryError !== ''): ?>
        <div class="alert alert-danger mb-4" style="background:#3a1520;color:#ffd6de;border:1px solid #ff5e8a;"><?= htmlspecialchars($packageCategoryError, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <!-- ═══ GESTIÓN DE CATEGORÍAS DE PAQUETES ═══════════════════════════ -->
    <section class="mb-5" id="pcatSection">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
            <h3 style="color:#22d3ee;margin:0;">Categorías de paquetes</h3>
            <button type="button" id="pcatToggleCreate" class="btn btn-sm" style="background:transparent;border:1px solid #22d3ee;color:#22d3ee;padding:0.3rem 0.9rem;">+ Nueva categoría</button>
        </div>
        <div class="small mb-3" style="color:#8be9fd;">Si creas al menos una categoría, en la tienda los paquetes de este juego se mostrarán agrupados en pestañas (una por categoría, más "Otros" para los paquetes sin categoría). Si no creas ninguna, se muestra todo igual que ahora.</div>

        <!-- Formulario crear categoría -->
        <div id="pcatCreateForm" style="display:none;background:#182030;border:1px solid #22d3ee;border-radius:10px;padding:1rem;margin-bottom:1rem;">
            <div class="row g-2 align-items-end">
                <div class="col-md-4 col-sm-6">
                    <label class="form-label" style="color:#22d3ee;font-size:0.85rem;margin-bottom:0.25rem;">Nombre *</label>
                    <input type="text" id="pcatNombre" class="form-control form-control-sm" placeholder="ej. Diamantes" style="background:#222c3a;color:#22d3ee;border:1px solid #22d3ee;">
                </div>
                <div class="col-md-3 col-sm-6">
                    <label class="form-label" style="color:#8be9fd;font-size:0.85rem;margin-bottom:0.25rem;">Slug <span style="opacity:.6">(auto)</span></label>
                    <input type="text" id="pcatSlug" class="form-control form-control-sm" placeholder="diamantes" style="background:#222c3a;color:#8be9fd;border:1px solid #1e3a5f;">
                </div>
                <div class="col-md-2 col-6">
                    <label class="form-label" style="color:#22d3ee;font-size:0.85rem;margin-bottom:0.25rem;">Icono / Emoji</label>
                    <input type="text" id="pcatIcono" class="form-control form-control-sm text-center" placeholder="💎" style="background:#222c3a;color:#22d3ee;border:1px solid #22d3ee;font-size:1.2rem;">
                </div>
                <div class="col-md-2 col-6">
                    <label class="form-label" style="color:#22d3ee;font-size:0.85rem;margin-bottom:0.25rem;">Color</label>
                    <input type="color" id="pcatColor" value="#22d3ee" class="form-control form-control-color form-control-sm" style="background:#222c3a;border:1px solid #22d3ee;width:100%;height:34px;">
                </div>
                <div class="col-md-1 col-6">
                    <label class="form-label" style="color:#8be9fd;font-size:0.85rem;margin-bottom:0.25rem;">Orden</label>
                    <input type="number" id="pcatOrden" value="0" min="0" class="form-control form-control-sm" style="background:#222c3a;color:#22d3ee;border:1px solid #1e3a5f;">
                </div>
                <div class="col-md-2 col-6">
                    <label class="form-label" style="color:#8be9fd;font-size:0.85rem;margin-bottom:0.25rem;">Color de texto <span style="opacity:.6">(tab activo)</span></label>
                    <input type="color" id="pcatColorTexto" value="#ffffff" class="form-control form-control-color form-control-sm" style="background:#222c3a;border:1px solid #1e3a5f;width:100%;height:34px;">
                </div>
            </div>
            <div class="mt-2">
                <label class="form-label" style="color:#22d3ee;font-size:0.82rem;margin-bottom:0.3rem;">Estilo del tab en el juego</label>
                <div class="d-flex flex-wrap gap-3">
                    <?php foreach (['icono' => 'Solo icono', 'texto' => 'Solo texto', 'icono_texto' => 'Icono + texto'] as $val => $lbl): ?>
                    <label class="d-flex align-items-center gap-1" style="cursor:pointer;color:#8be9fd;font-size:0.82rem;">
                        <input type="radio" name="pcatMostrarMenu" value="<?= $val ?>" <?= $val === 'icono_texto' ? 'checked' : '' ?> style="accent-color:#22d3ee;">
                        <?= $lbl ?>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="mt-2 d-flex align-items-center gap-3 flex-wrap">
                <div style="flex:1;min-width:200px;">
                    <label class="form-label" style="color:#8be9fd;font-size:0.82rem;margin-bottom:0.2rem;">Imagen de categoría <span style="opacity:.6">(opcional)</span></label>
                    <input type="file" id="pcatImagen" accept="image/*" class="form-control form-control-sm" style="background:#222c3a;color:#8be9fd;border:1px solid #1e3a5f;">
                </div>
                <div id="pcatImagenPreview" style="display:none;">
                    <img id="pcatImagenPreviewImg" style="max-height:54px;border-radius:6px;border:1px solid #1e3a5f;" alt="preview">
                </div>
            </div>
            <div class="d-flex align-items-center gap-2 mt-3 flex-wrap">
                <button type="button" id="pcatCreateBtn" class="btn btn-sm" style="background:#22d3ee;color:#111;border:none;font-weight:600;">Crear categoría</button>
                <button type="button" id="pcatCancelCreate" class="btn btn-sm" style="background:transparent;border:1px solid #555;color:#aaa;">Cancelar</button>
                <span id="pcatCreateStatus" class="small" style="color:#ff5e8a;"></span>
            </div>
        </div>

        <!-- Lista de categorías -->
        <div id="pcatList">
            <?php if ($packageCategories === []): ?>
            <p class="small" style="color:#8be9fd;">Aún no hay categorías de paquetes para este juego. Crea la primera con el botón de arriba.</p>
            <?php else: ?>
            <?php foreach ($packageCategories as $pcat): ?>
            <div class="pcatRow d-flex align-items-center gap-3 p-2 rounded-3 mb-2 flex-wrap" data-id="<?= (int) $pcat['id'] ?>" style="background:#182030;border:1px solid #1e3a5f;<?= $pcat['activa'] ? '' : 'opacity:.55;' ?>">
                <?php if ($pcat['imagen'] !== ''): ?>
                <img src="/<?= htmlspecialchars($pcat['imagen'], ENT_QUOTES, 'UTF-8') ?>" style="width:36px;height:36px;object-fit:cover;border-radius:5px;border:1px solid #1e3a5f;flex-shrink:0;" alt="">
                <?php endif; ?>
                <span style="font-size:1.3em;min-width:1.5rem;text-align:center;"><?= $pcat['icono'] !== '' ? htmlspecialchars($pcat['icono'], ENT_QUOTES, 'UTF-8') : '📁' ?></span>
                <span style="width:12px;height:12px;border-radius:50%;background:<?= htmlspecialchars($pcat['color'] ?: '#22d3ee', ENT_QUOTES, 'UTF-8') ?>;display:inline-block;flex-shrink:0;"></span>
                <strong style="color:#22d3ee;flex:1;min-width:100px;"><?= htmlspecialchars($pcat['nombre'], ENT_QUOTES, 'UTF-8') ?></strong>
                <?php if (!$pcat['activa']): ?>
                <span class="small fw-bold" style="color:#ff5e8a;border:1px solid #ff5e8a;border-radius:4px;padding:0 0.4rem;">Inactiva</span>
                <?php endif; ?>
                <code style="color:#8be9fd;font-size:0.8rem;opacity:.8;"><?= htmlspecialchars($pcat['slug'], ENT_QUOTES, 'UTF-8') ?></code>
                <span class="small" style="color:#8be9fd;"><?= package_category_packages_count($mysqli, (int) $pcat['id']) ?> paquete(s)</span>
                <div class="d-flex gap-2 ms-auto flex-shrink-0">
                    <button type="button" class="btn btn-sm pcatToggleActiveBtn" data-id="<?= (int) $pcat['id'] ?>" data-active="<?= $pcat['activa'] ? '1' : '0' ?>" style="border:1px solid <?= $pcat['activa'] ? '#8be9fd' : '#22c55e' ?>;color:<?= $pcat['activa'] ? '#8be9fd' : '#22c55e' ?>;background:transparent;padding:0.1rem 0.55rem;font-size:0.82rem;"><?= $pcat['activa'] ? 'Desactivar' : 'Activar' ?></button>
                    <button type="button" class="btn btn-sm pcatEditBtn" data-id="<?= (int) $pcat['id'] ?>" style="border:1px solid #22d3ee;color:#22d3ee;background:transparent;padding:0.1rem 0.55rem;font-size:0.82rem;">Editar</button>
                    <button type="button" class="btn btn-sm pcatDeleteBtn" data-id="<?= (int) $pcat['id'] ?>" data-nombre="<?= htmlspecialchars($pcat['nombre'], ENT_QUOTES, 'UTF-8') ?>" style="border:1px solid #ff5e8a;color:#ff5e8a;background:transparent;padding:0.1rem 0.55rem;font-size:0.82rem;">Eliminar</button>
                </div>
            </div>
            <div class="pcatEditRow" id="pcatEdit_<?= (int) $pcat['id'] ?>" style="display:none;background:#182030;border:1px solid #22d3ee;border-radius:10px;padding:0.75rem;margin-bottom:0.5rem;">
                <div class="row g-2 align-items-end">
                    <div class="col-md-4 col-sm-6">
                        <label class="form-label" style="color:#22d3ee;font-size:0.8rem;margin-bottom:0.2rem;">Nombre *</label>
                        <input type="text" class="form-control form-control-sm pcatEditNombre" value="<?= htmlspecialchars($pcat['nombre'], ENT_QUOTES, 'UTF-8') ?>" style="background:#222c3a;color:#22d3ee;border:1px solid #22d3ee;">
                    </div>
                    <div class="col-md-3 col-sm-6">
                        <label class="form-label" style="color:#8be9fd;font-size:0.8rem;margin-bottom:0.2rem;">Slug</label>
                        <input type="text" class="form-control form-control-sm pcatEditSlug" value="<?= htmlspecialchars($pcat['slug'], ENT_QUOTES, 'UTF-8') ?>" style="background:#222c3a;color:#8be9fd;border:1px solid #1e3a5f;">
                    </div>
                    <div class="col-md-2 col-6">
                        <label class="form-label" style="color:#22d3ee;font-size:0.8rem;margin-bottom:0.2rem;">Icono</label>
                        <input type="text" class="form-control form-control-sm pcatEditIcono text-center" value="<?= htmlspecialchars($pcat['icono'], ENT_QUOTES, 'UTF-8') ?>" style="background:#222c3a;color:#22d3ee;border:1px solid #22d3ee;font-size:1.1rem;">
                    </div>
                    <div class="col-md-2 col-6">
                        <label class="form-label" style="color:#22d3ee;font-size:0.8rem;margin-bottom:0.2rem;">Color</label>
                        <input type="color" class="form-control form-control-color form-control-sm pcatEditColor" value="<?= htmlspecialchars($pcat['color'] ?: '#22d3ee', ENT_QUOTES, 'UTF-8') ?>" style="background:#222c3a;border:1px solid #22d3ee;width:100%;height:32px;">
                    </div>
                    <div class="col-md-1 col-6">
                        <label class="form-label" style="color:#8be9fd;font-size:0.8rem;margin-bottom:0.2rem;">Orden</label>
                        <input type="number" class="form-control form-control-sm pcatEditOrden" value="<?= (int) $pcat['orden'] ?>" min="0" style="background:#222c3a;color:#22d3ee;border:1px solid #1e3a5f;">
                    </div>
                    <div class="col-md-2 col-6">
                        <label class="form-label" style="color:#8be9fd;font-size:0.8rem;margin-bottom:0.2rem;">Color de texto <span style="opacity:.6">(tab activo)</span></label>
                        <input type="color" class="form-control form-control-color form-control-sm pcatEditColorTexto" value="<?= htmlspecialchars($pcat['color_texto'] ?: '#ffffff', ENT_QUOTES, 'UTF-8') ?>" style="background:#222c3a;border:1px solid #1e3a5f;width:100%;height:32px;">
                    </div>
                </div>
                <div class="mt-2">
                    <label class="form-label" style="color:#22d3ee;font-size:0.78rem;margin-bottom:0.25rem;">Estilo del tab en el juego</label>
                    <div class="d-flex flex-wrap gap-3">
                        <?php foreach (['icono' => 'Solo icono', 'texto' => 'Solo texto', 'icono_texto' => 'Icono + texto'] as $mval => $mlbl): ?>
                        <label class="d-flex align-items-center gap-1" style="cursor:pointer;color:#8be9fd;font-size:0.78rem;">
                            <input type="radio" class="pcatEditMostrarMenu" name="pcatEditMostrarMenu_<?= (int) $pcat['id'] ?>" value="<?= $mval ?>" <?= ($pcat['mostrar_menu'] ?? 'icono_texto') === $mval ? 'checked' : '' ?> style="accent-color:#22d3ee;">
                            <?= $mlbl ?>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="mt-2 d-flex align-items-center gap-2 flex-wrap">
                    <?php if ($pcat['imagen'] !== ''): ?>
                    <img src="/<?= htmlspecialchars($pcat['imagen'], ENT_QUOTES, 'UTF-8') ?>" class="pcatCurrentImgThumb" style="max-height:40px;border-radius:5px;border:1px solid #1e3a5f;" alt="">
                    <button type="button" class="btn btn-sm pcatRemoveImgBtn" style="border:1px solid #ff5e8a;color:#ff5e8a;background:transparent;font-size:0.78rem;padding:0.1rem 0.5rem;">✕ Quitar imagen</button>
                    <?php endif; ?>
                    <input type="hidden" class="pcatEditRemoveImagen" value="0">
                    <input type="file" class="pcatEditImagen form-control form-control-sm" accept="image/*" style="background:#222c3a;color:#8be9fd;border:1px solid #1e3a5f;max-width:260px;">
                </div>
                <div class="d-flex align-items-center gap-2 mt-2 flex-wrap">
                    <button type="button" class="btn btn-sm pcatSaveEditBtn" data-id="<?= (int) $pcat['id'] ?>" style="background:#22d3ee;color:#111;border:none;font-weight:600;">Guardar</button>
                    <button type="button" class="btn btn-sm pcatCancelEditBtn" data-id="<?= (int) $pcat['id'] ?>" style="border:1px solid #555;color:#aaa;background:transparent;">Cancelar</button>
                    <span class="pcatEditStatus small" style="color:#ff5e8a;"></span>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </section>
    <!-- ═══════════════════════════════════════════════════════════════════ -->
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
        <?php if (!$packageSourceSelectionEnabled && $packageDefaultSourceValue !== ''): ?>
            <input type="hidden" name="api_provider" value="<?= htmlspecialchars($packageDefaultSourceValue, ENT_QUOTES, 'UTF-8') ?>">
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
                        <?php foreach ($packageSourceItems as $srcItem): ?>
                            <label class="d-inline-flex align-items-center gap-2 rounded-pill px-3 py-2" style="background:rgba(15,23,42,0.92);border:1px solid rgba(34,211,238,0.28);color:#d8fbff;cursor:pointer;">
                                <input type="radio" name="api_provider" value="<?= htmlspecialchars($srcItem['value'], ENT_QUOTES, 'UTF-8') ?>" class="form-check-input mt-0" data-package-source-radio>
                                <span><?= htmlspecialchars($srcItem['label'], ENT_QUOTES, 'UTF-8') ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="btn btn-outline-info btn-sm mt-3 d-none" data-package-source-clear>Limpiar selección</button>
                </div>
            </div>
        <?php endif; ?>
        <?php if ($giftVenActiveSlots > 1): ?>
            <?php if ($hasGiftVenCatalog): ?>
            <div class="col-md-6" data-package-source-panel="giftven_1">
                <label class="form-label text-neon">Producto API — <?= htmlspecialchars($juegoCategoriaApi, ENT_QUOTES, 'UTF-8') ?></label>
                <select name="paquete_api" data-package-source-required="1" class="form-select" style="background:#222c3a; color:#22d3ee; border:1px solid #22d3ee;">
                    <option value="">Selecciona un producto API</option>
                    <?php foreach ($apiProducts as $apiProduct): ?>
                        <option value="<?= (int) ($apiProduct['id'] ?? 0) ?>"><?= htmlspecialchars(recargas_api_product_label($apiProduct), ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <?php if ($hasGiftVenCatalog2): ?>
            <div class="col-md-6" data-package-source-panel="giftven_2">
                <label class="form-label text-neon">Producto API — <?= htmlspecialchars($juegoCategoriaApi2, ENT_QUOTES, 'UTF-8') ?></label>
                <select name="paquete_api" data-package-source-required="1" class="form-select" style="background:#222c3a; color:#22d3ee; border:1px solid #22d3ee;">
                    <option value="">Selecciona un producto API</option>
                    <?php foreach ($apiProducts2 as $apiProduct): ?>
                        <option value="<?= (int) ($apiProduct['id'] ?? 0) ?>"><?= htmlspecialchars(recargas_api_product_label($apiProduct), ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <?php if ($hasGiftVenCatalog3): ?>
            <div class="col-md-6" data-package-source-panel="giftven_3">
                <label class="form-label text-neon">Producto API — <?= htmlspecialchars($juegoCategoriaApi3, ENT_QUOTES, 'UTF-8') ?></label>
                <select name="paquete_api" data-package-source-required="1" class="form-select" style="background:#222c3a; color:#22d3ee; border:1px solid #22d3ee;">
                    <option value="">Selecciona un producto API</option>
                    <?php foreach ($apiProducts3 as $apiProduct): ?>
                        <option value="<?= (int) ($apiProduct['id'] ?? 0) ?>"><?= htmlspecialchars(recargas_api_product_label($apiProduct), ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
        <?php elseif ($hasGiftVenCatalog): ?>
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
        <?php if ($recargasAmericaActiveSlots > 1): ?>
            <?php if ($hasRecargasAmericaCatalog): ?>
            <div class="col-md-6" data-package-source-panel="recargasamerica_1">
                <label class="form-label text-neon">Producto RecargasAmérica — <?= htmlspecialchars($juegoCategoriaApiRecargasAmerica, ENT_QUOTES, 'UTF-8') ?></label>
                <select name="paquete_api" data-package-source-required="1" class="form-select" style="background:#222c3a; color:#22d3ee; border:1px solid #22d3ee;">
                    <option value="">Selecciona un producto</option>
                    <?php foreach ($recargasAmericaProducts1 as $raProduct): ?>
                        <option value="<?= (int) ($raProduct['id'] ?? 0) ?>"><?= htmlspecialchars(recargasamerica_api_product_label($raProduct), ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text mt-2" style="color:#8be9fd;">Filtrado por: "<?= htmlspecialchars($juegoCategoriaApiRecargasAmerica, ENT_QUOTES, 'UTF-8') ?>" (<?= count($recargasAmericaProducts1) ?> productos).</div>
            </div>
            <?php endif; ?>
            <?php if ($hasRecargasAmericaCatalog2): ?>
            <div class="col-md-6" data-package-source-panel="recargasamerica_2">
                <label class="form-label text-neon">Producto RecargasAmérica — <?= htmlspecialchars($juegoCategoriaApiRecargasAmerica2, ENT_QUOTES, 'UTF-8') ?></label>
                <select name="paquete_api" data-package-source-required="1" class="form-select" style="background:#222c3a; color:#22d3ee; border:1px solid #22d3ee;">
                    <option value="">Selecciona un producto</option>
                    <?php foreach ($recargasAmericaProducts2 as $raProduct): ?>
                        <option value="<?= (int) ($raProduct['id'] ?? 0) ?>"><?= htmlspecialchars(recargasamerica_api_product_label($raProduct), ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text mt-2" style="color:#8be9fd;">Filtrado por: "<?= htmlspecialchars($juegoCategoriaApiRecargasAmerica2, ENT_QUOTES, 'UTF-8') ?>" (<?= count($recargasAmericaProducts2) ?> productos).</div>
            </div>
            <?php endif; ?>
            <?php if ($hasRecargasAmericaCatalog3): ?>
            <div class="col-md-6" data-package-source-panel="recargasamerica_3">
                <label class="form-label text-neon">Producto RecargasAmérica — <?= htmlspecialchars($juegoCategoriaApiRecargasAmerica3, ENT_QUOTES, 'UTF-8') ?></label>
                <select name="paquete_api" data-package-source-required="1" class="form-select" style="background:#222c3a; color:#22d3ee; border:1px solid #22d3ee;">
                    <option value="">Selecciona un producto</option>
                    <?php foreach ($recargasAmericaProducts3 as $raProduct): ?>
                        <option value="<?= (int) ($raProduct['id'] ?? 0) ?>"><?= htmlspecialchars(recargasamerica_api_product_label($raProduct), ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text mt-2" style="color:#8be9fd;">Filtrado por: "<?= htmlspecialchars($juegoCategoriaApiRecargasAmerica3, ENT_QUOTES, 'UTF-8') ?>" (<?= count($recargasAmericaProducts3) ?> productos).</div>
            </div>
            <?php endif; ?>
        <?php elseif ($hasRecargasAmericaCatalog): ?>
            <div class="col-md-6" data-package-source-panel="recargasamerica">
                <label class="form-label text-neon">Producto RecargasAmérica</label>
                <select name="paquete_api" <?= $packageSourceSelectionEnabled ? 'data-package-source-required="1"' : 'required' ?> class="form-select" style="background:#222c3a; color:#22d3ee; border:1px solid #22d3ee;">
                    <option value="">Selecciona un producto</option>
                    <?php foreach ($recargasAmericaProducts1 as $raProduct): ?>
                        <option value="<?= (int) ($raProduct['id'] ?? 0) ?>"><?= htmlspecialchars(recargasamerica_api_product_label($raProduct), ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if ($recargasAmericaProductsError !== null): ?>
                    <div class="form-text mt-2 text-danger">No se pudo cargar el catálogo de RecargasAmérica: <?= htmlspecialchars($recargasAmericaProductsError, ENT_QUOTES, 'UTF-8') ?></div>
                <?php else: ?>
                    <div class="form-text mt-2" style="color:#8be9fd;">Filtrado por: "<?= htmlspecialchars($juegoCategoriaApiRecargasAmerica, ENT_QUOTES, 'UTF-8') ?>" (<?= count($recargasAmericaProducts1) ?> productos).</div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <?php if ($discordActiveSlots > 1): ?>
            <?php if ($hasDiscordCatalog): ?>
            <div class="col-md-6" data-package-source-panel="discord_1">
                <label class="form-label text-neon">Cantidad — <?= htmlspecialchars(trim((string) ($discordTopupCommand['label'] ?? $juegoCategoriaApiDiscord)), ENT_QUOTES, 'UTF-8') ?></label>
                <input type="text" name="cantidad" placeholder="Ej: 86, 257+40 o Pase semanal" data-package-source-required="1" class="form-control" style="background:#222c3a; color:#22d3ee; border:1px solid #22d3ee;" value="1" maxlength="80" data-discord-catalog-field="quantity">
                <?php if ($discordTopupCommandText !== ''): ?><div class="form-text mt-1" style="color:#8be9fd;">Comando: <?= htmlspecialchars($discordTopupCommandText, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
            </div>
            <?php endif; ?>
            <?php if ($hasDiscordCatalog2): ?>
            <div class="col-md-6" data-package-source-panel="discord_2">
                <label class="form-label text-neon">Cantidad — <?= htmlspecialchars(trim((string) ($discordTopupCommand2['label'] ?? $juegoCategoriaApiDiscord2)), ENT_QUOTES, 'UTF-8') ?></label>
                <input type="text" name="cantidad" placeholder="Ej: 86, 257+40 o Pase semanal" data-package-source-required="1" class="form-control" style="background:#222c3a; color:#22d3ee; border:1px solid #22d3ee;" value="1" maxlength="80">
                <?php if ($discordTopupCommandText2 !== ''): ?><div class="form-text mt-1" style="color:#8be9fd;">Comando: <?= htmlspecialchars($discordTopupCommandText2, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
            </div>
            <?php endif; ?>
            <?php if ($hasDiscordCatalog3): ?>
            <div class="col-md-6" data-package-source-panel="discord_3">
                <label class="form-label text-neon">Cantidad — <?= htmlspecialchars(trim((string) ($discordTopupCommand3['label'] ?? $juegoCategoriaApiDiscord3)), ENT_QUOTES, 'UTF-8') ?></label>
                <input type="text" name="cantidad" placeholder="Ej: 86, 257+40 o Pase semanal" data-package-source-required="1" class="form-control" style="background:#222c3a; color:#22d3ee; border:1px solid #22d3ee;" value="1" maxlength="80">
                <?php if ($discordTopupCommandText3 !== ''): ?><div class="form-text mt-1" style="color:#8be9fd;">Comando: <?= htmlspecialchars($discordTopupCommandText3, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
            </div>
            <?php endif; ?>
        <?php elseif ($hasDiscordCatalog): ?>
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
            <?php if ($adminPackageMarkupPct > 0): ?>
                <div class="form-text mt-1" style="color:#8be9fd;">Para paquetes TiendaGiftVen el precio se calcula automáticamente: precio API × <?= number_format(1 + $adminPackageMarkupPct / 100, 4) ?> (margen <?= number_format($adminPackageMarkupPct, 2) ?>%). Este campo se usa como respaldo si la API no responde.</div>
            <?php endif; ?>
            <?php if ($adminPackageMarkupPctRecargasamerica > 0): ?>
                <div class="form-text mt-1" style="color:#8be9fd;">Para paquetes RecargasAmérica el precio se calcula automáticamente: precio API × <?= number_format(1 + $adminPackageMarkupPctRecargasamerica / 100, 4) ?> (margen <?= number_format($adminPackageMarkupPctRecargasamerica, 2) ?>%). Este campo se usa como respaldo si la API no responde.</div>
            <?php endif; ?>
            <div class="form-check mt-2">
                <input type="checkbox" name="precio_manual_override" class="form-check-input" id="precioManualCheck">
                <label class="form-check-label" for="precioManualCheck" style="color:#f9a825;font-size:0.875rem;">Usar precio manual (ignorar precio de API)</label>
            </div>
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
                <div class="text-neon fw-semibold mb-1">Información del paquete (ícono "i")</div>
                <div class="small mb-2" style="color:#8be9fd;">Este texto se mostrará en una ventana al tocar el ícono "i" del paquete en la tienda. Si lo dejas vacío, el ícono no aparece.</div>
                <?= admin_package_info_editor_html('info_paquete_html', '') ?>
            </div>
        </div>
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
                                <label class="badge rounded-pill d-inline-flex align-items-center gap-2 px-3 py-2" style="cursor:pointer;<?= htmlspecialchars(admin_package_feature_badge_inline_style($feature), ENT_QUOTES, 'UTF-8') ?>">
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
            <label class="form-label text-neon">Categoría de paquete</label>
            <select name="categoria_paquete_id" class="form-select" style="background:#222c3a;color:#22d3ee;border:1px solid #22d3ee;">
                <option value="0">Sin categoría (irá en "Otros")</option>
                <?php foreach ($packageCategories as $pcat): ?>
                <option value="<?= (int) $pcat['id'] ?>"><?= htmlspecialchars(($pcat['icono'] !== '' ? $pcat['icono'] . ' ' : '') . $pcat['nombre'], ENT_QUOTES, 'UTF-8') ?></option>
                <?php endforeach; ?>
            </select>
            <?php if ($packageCategories === []): ?>
            <div class="form-text mt-2" style="color:#8be9fd;">Crea categorías en la sección «Categorías de paquetes» para poder asignarlas.</div>
            <?php endif; ?>
        </div>
        <div class="col-12">
            <label class="form-label text-neon">Nivel de Pase (Pase de Nivel)</label>
            <select name="levelpass_key" class="form-select" style="background:#222c3a;color:#22d3ee;border:1px solid #22d3ee;">
                <option value="">No aplica</option>
                <?php foreach (levelpass_key_options() as $lpKey => $lpLabel): ?>
                <option value="<?= htmlspecialchars($lpKey, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($lpLabel, ENT_QUOTES, 'UTF-8') ?></option>
                <?php endforeach; ?>
            </select>
            <div class="form-text mt-2" style="color:#8be9fd;">Solo para paquetes de "Pase de Nivel": define qué nivel representa este paquete para consultar disponibilidad por jugador.</div>
        </div>
        <div class="col-12">
            <div class="p-3 rounded-3" style="background:#182030;border:1px solid #1e3a5f;">
                <div class="form-check mb-2">
                    <input type="checkbox" class="form-check-input js-fi-toggle" id="fiEnabledCheck" data-panel="fiPanel" data-hidden-input="fiEnabledInput">
                    <label class="form-check-label text-neon" for="fiEnabledCheck">Usar FullImpulso (Seguidores) para este paquete</label>
                </div>
                <input type="hidden" name="fullimpulso_enabled" id="fiEnabledInput" value="0">
                <div id="fiPanel" style="display:none;">
                    <div class="row g-2">
                        <div class="col-md-7">
                            <label class="form-label text-neon small mb-1">Servicio de FullImpulso</label>
                            <select name="fullimpulso_service_id" class="form-select form-select-sm js-fi-service" style="background:#222c3a;color:#22d3ee;border:1px solid #22d3ee;">
                                <option value="">Selecciona un servicio</option>
                                <?php foreach ($fullimpulsoServices as $fiSvc): ?>
                                <option value="<?= (int) ($fiSvc['service'] ?? 0) ?>" data-rate="<?= htmlspecialchars((string) ($fiSvc['rate'] ?? '0'), ENT_QUOTES, 'UTF-8') ?>" data-type="<?= htmlspecialchars((string) ($fiSvc['type'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(trim((string) ($fiSvc['name'] ?? '')) . ' — $' . trim((string) ($fiSvc['rate'] ?? '0')) . '/1000', ENT_QUOTES, 'UTF-8') ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php if ($fullimpulsoServicesError !== ''): ?>
                            <div class="form-text mt-1" style="color:#ff5e8a;">No se pudo cargar la lista de servicios: <?= htmlspecialchars($fullimpulsoServicesError, ENT_QUOTES, 'UTF-8') ?></div>
                            <?php elseif (!fullimpulso_is_configured()): ?>
                            <div class="form-text mt-1" style="color:#ff5e8a;">Configura la API key de FullImpulso primero.</div>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label text-neon small mb-1">Cantidad fija a enviar</label>
                            <input type="number" min="1" name="fullimpulso_cantidad" class="form-control form-control-sm js-fi-quantity" style="background:#222c3a;color:#22d3ee;border:1px solid #22d3ee;" placeholder="Ej: 1000">
                        </div>
                    </div>
                    <div class="form-text mt-2 js-fi-cost-preview" style="color:#8be9fd;"></div>
                    <div class="form-check mt-2">
                        <input type="checkbox" name="fullimpulso_custom_comments_manual" class="form-check-input js-fi-comments-manual" id="fiCommentsManualCheck">
                        <label class="form-check-label" style="color:#facc15;" for="fiCommentsManualCheck">📝 Requiere que el cliente escriba un comentario por línea (se marca solo si se detecta, pero puedes forzarlo aquí)</label>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-12">
            <div class="form-check mt-2">
                <input type="checkbox" name="activo" class="form-check-input" id="paqueteActivoCheck" checked>
                <label class="form-check-label text-neon" for="paqueteActivoCheck">Paquete activo / publicado</label>
            </div>
            <div class="form-check mt-2">
                <input type="checkbox" name="destacado" class="form-check-input" id="paqueteDestacadoCheck" onchange="toggleDescuentoDestacado('descuentoDestacadoWrap', this.checked)">
                <label class="form-check-label text-neon" for="paqueteDestacadoCheck">&#9889; Paquete destacado (GG Drops)</label>
            </div>
            <div id="descuentoDestacadoWrap" class="mt-2" style="display:none;">
                <label class="form-label text-neon small mb-1" for="descuentoDestacadoInput">Orden en GG Drops</label>
                <input type="number" min="0" max="9999" name="orden_gg" id="ordenGgInput" value="" class="form-control form-control-sm mb-2" style="background:#222c3a;color:#22d3ee;border:1px solid #22d3ee;max-width:120px;" placeholder="Auto">
                <div class="form-text mb-2" style="color:#8be9fd;">Posición en la sección GG Drops (vacío = automático).</div>
                <label class="form-label text-neon small mb-1" for="descuentoDestacadoInput">Descuento GG Drops (%)</label>
                <input type="number" min="0" max="99" name="descuento_destacado" id="descuentoDestacadoInput" value="0" class="form-control form-control-sm" style="background:#222c3a;color:#22d3ee;border:1px solid #22d3ee;max-width:120px;">
                <div class="form-text" style="color:#8be9fd;">Porcentaje mostrado en la tarjeta (0 = sin descuento).</div>
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
                <input type="text" name="package_feature_icon_custom" data-package-feature-icon-custom maxlength="8" placeholder="…o escribe tu propio emoji" class="form-control form-control-sm mt-2" style="background:#222c3a;color:#22d3ee;border:1px dashed rgba(34,211,238,0.5);">
                <div class="form-text" style="color:#64748b;">Si escribes un emoji aquí, se usará en lugar del ícono de la lista.</div>
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
                <?php
                    $featureBg = (string) ($feature['bg_color'] ?? '');
                    $featureText = (string) ($feature['text_color'] ?? '');
                    $featureRadius = $feature['border_radius'] ?? null;
                    $featureHasStyle = $featureBg !== '' || $featureText !== '' || $featureRadius !== null;
                    $previewStyle = 'background:rgba(15,23,42,0.92);border:1px solid rgba(34,211,238,0.28);color:#d8fbff;';
                    if ($featureHasStyle) {
                        $previewStyle = 'border:1px solid rgba(34,211,238,0.28);'
                            . ($featureBg !== '' ? 'background:' . htmlspecialchars($featureBg, ENT_QUOTES, 'UTF-8') . ';' : 'background:rgba(15,23,42,0.92);')
                            . ($featureText !== '' ? 'color:' . htmlspecialchars($featureText, ENT_QUOTES, 'UTF-8') . ';' : 'color:#d8fbff;')
                            . ($featureRadius !== null ? 'border-radius:' . (int) $featureRadius . 'px !important;' : '');
                    }
                ?>
                <?php $featureIconIsCustom = !array_key_exists((string) ($feature['icon'] ?? 'sparkles'), $packageFeatureIconOptions); ?>
                <form method="post" class="row g-2 align-items-center rounded-4 p-3" style="background:#101826;border:1px solid rgba(34,211,238,0.14);">
                    <input type="hidden" name="package_feature_id" value="<?= (int) ($feature['id'] ?? 0) ?>">
                    <div class="col-md-3">
                        <label class="form-label text-neon small mb-1">Icono</label>
                        <div class="d-flex align-items-center gap-2" data-package-feature-editor>
                            <select name="package_feature_icon" data-package-feature-icon-select class="form-select" style="background:#222c3a;color:#22d3ee;border:1px solid #22d3ee;"><?= admin_package_feature_icon_options_html($packageFeatureIconOptions, (string) ($feature['icon'] ?? 'sparkles')) ?></select>
                            <span data-package-feature-icon-preview class="d-inline-flex align-items-center justify-content-center rounded-3 flex-shrink-0" style="width:44px;height:44px;background:#0f172a;border:1px solid rgba(34,211,238,0.22);color:#67e8f9;"><?= package_feature_render_icon((string) ($feature['icon'] ?? 'sparkles')) ?></span>
                        </div>
                        <input type="text" name="package_feature_icon_custom" data-package-feature-icon-custom maxlength="8" placeholder="…o tu propio emoji" value="<?= $featureIconIsCustom ? htmlspecialchars((string) ($feature['icon'] ?? ''), ENT_QUOTES, 'UTF-8') : '' ?>" class="form-control form-control-sm mt-2" style="background:#222c3a;color:#22d3ee;border:1px dashed rgba(34,211,238,0.5);">
                    </div>
                    <div class="col-md-5">
                        <label class="form-label text-neon small mb-1">Nombre</label>
                        <input type="text" name="package_feature_name" value="<?= htmlspecialchars((string) ($feature['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" class="form-control" required style="background:#222c3a;color:#22d3ee;border:1px solid #22d3ee;">
                    </div>
                    <div class="col-md-2 text-center">
                        <span class="badge rounded-pill d-inline-flex align-items-center gap-2 px-3 py-2" style="<?= $previewStyle ?>">
                            <?= package_feature_render_icon((string) ($feature['icon'] ?? 'sparkles'), 'package-feature-badge-icon') ?>
                            <span>ID <?= (int) ($feature['id'] ?? 0) ?></span>
                        </span>
                    </div>
                    <div class="col-md-2 d-flex gap-2">
                        <button type="submit" name="package_feature_catalog_action" value="update" class="btn neon-btn-info btn-sm flex-fill">Actualizar</button>
                        <button type="submit" name="package_feature_catalog_action" value="delete" class="btn btn-danger btn-sm flex-fill" onclick="return confirm('¿Eliminar esta caracteristica del catalogo?')">Borrar</button>
                    </div>
                    <div class="col-12">
                        <div class="d-flex flex-wrap align-items-end gap-3 rounded-3 p-2" style="background:#0d1420;border:1px dashed rgba(34,211,238,0.2);">
                            <div class="form-check mb-1">
                                <input class="form-check-input" type="checkbox" name="package_feature_use_style" value="1" id="feature-style-<?= (int) ($feature['id'] ?? 0) ?>" <?= $featureHasStyle ? 'checked' : '' ?>>
                                <label class="form-check-label small text-neon" for="feature-style-<?= (int) ($feature['id'] ?? 0) ?>">Estilo personalizado</label>
                            </div>
                            <div>
                                <label class="form-label small mb-1" style="color:#8be9fd;">Fondo</label>
                                <input type="color" name="package_feature_bg_color" value="<?= htmlspecialchars($featureBg !== '' ? $featureBg : '#0f172a', ENT_QUOTES, 'UTF-8') ?>" class="form-control form-control-color form-control-sm" style="background:#222c3a;border:1px solid #22d3ee;">
                            </div>
                            <div>
                                <label class="form-label small mb-1" style="color:#8be9fd;">Texto</label>
                                <input type="color" name="package_feature_text_color" value="<?= htmlspecialchars($featureText !== '' ? $featureText : '#f8fbff', ENT_QUOTES, 'UTF-8') ?>" class="form-control form-control-color form-control-sm" style="background:#222c3a;border:1px solid #22d3ee;">
                            </div>
                            <div>
                                <label class="form-label small mb-1" style="color:#8be9fd;">Radio borde (px)</label>
                                <input type="number" name="package_feature_border_radius" min="0" max="999" value="<?= $featureRadius !== null ? (int) $featureRadius : '' ?>" placeholder="999" class="form-control form-control-sm" style="background:#222c3a;color:#22d3ee;border:1px solid #22d3ee;max-width:120px;">
                            </div>
                            <div class="small mb-1" style="color:#64748b;">Desmarca la casilla y actualiza para volver al estilo por defecto.</div>
                        </div>
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
    <form method="post" class="mb-4 rounded-4 p-3 d-flex flex-wrap align-items-center gap-3" style="background:#101826;border:1px solid rgba(34,211,238,0.22);">
        <input type="hidden" name="bulk_price_adjust" value="1">
        <div class="text-neon fw-semibold" style="white-space:nowrap;">Ajuste masivo de precios</div>
        <div class="input-group" style="max-width:200px;">
            <input type="number" name="bulk_price_adjust_pct" step="0.1" min="-99.9" max="1000" placeholder="Ej: 10 o -5" class="form-control" style="background:#222c3a;color:#22d3ee;border:1px solid #22d3ee;" required>
            <span class="input-group-text" style="background:#222c3a;color:#22d3ee;border:1px solid #22d3ee;">%</span>
        </div>
        <button type="submit" class="btn btn-outline-info btn-sm" onclick="return confirm('¿Aplicar este porcentaje al precio de todos los paquetes de este juego?')">Aplicar a todos</button>
        <div class="small" style="color:#8be9fd;">Modifica el precio de todos los paquetes del juego en el porcentaje indicado. Usa valores negativos para reducir.</div>
    </form>
    <?php if ($packageCategories !== []): ?>
    <div class="d-flex flex-wrap gap-2 mb-3">
        <button type="button" class="btn btn-info fw-bold js-package-category-tab-btn active" data-package-category-tab="" onclick="window.filterAdminPackagesByCategory(''); return false;">Todos <span data-package-category-tab-count="">(<?= count($paquetes) ?>)</span></button>
        <?php foreach ($packageCategories as $pcatTab): ?>
        <button type="button" class="btn btn-outline-info fw-bold js-package-category-tab-btn" data-package-category-tab="<?= (int) $pcatTab['id'] ?>" onclick="window.filterAdminPackagesByCategory('<?= (int) $pcatTab['id'] ?>'); return false;"><?= $pcatTab['icono'] !== '' ? htmlspecialchars($pcatTab['icono'], ENT_QUOTES, 'UTF-8') . ' ' : '' ?><?= htmlspecialchars($pcatTab['nombre'], ENT_QUOTES, 'UTF-8') ?> <span data-package-category-tab-count="<?= (int) $pcatTab['id'] ?>">(<?= $packageCategoryTabCounts[(int) $pcatTab['id']] ?? 0 ?>)</span></button>
        <?php endforeach; ?>
        <button type="button" class="btn btn-outline-info fw-bold js-package-category-tab-btn" data-package-category-tab="otros" onclick="window.filterAdminPackagesByCategory('otros'); return false;">Otros <span data-package-category-tab-count="otros">(<?= $packageCategoryTabCounts['otros'] ?>)</span></button>
    </div>
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
                    <th style="color:#22d3ee; background:#181f2a;" title="Destacado en GG Drops">&#9889;</th>
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
                <?php $packageCat = $packageCategoriesById[(int) ($p['categoria_paquete_id'] ?? 0)] ?? null; ?>
                <tr class="js-package-record js-package-filterable <?= $packageIsActive ? 'activo' : 'inactivo' ?>" data-package-context="desktop" data-package-id="<?= (int) ($p['id'] ?? 0) ?>" data-package-category="<?= $packageCat !== null ? (int) $packageCat['id'] : 'otros' ?>" style="background:#181f2a; color:#fff;<?= (($currentPackageTab === 'active' && !$packageIsActive) || ($currentPackageTab === 'inactive' && $packageIsActive)) ? 'display:none;' : '' ?>">
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
                        <?php if ($packageCategories !== []): ?>
                            <form method="post" action="<?= htmlspecialchars($adminPackageBaseUrl, ENT_QUOTES, 'UTF-8') ?>/<?= $juego_id ?>" class="mt-2 m-0 js-ajax-category-form">
                                <input type="hidden" name="ajax" value="1">
                                <input type="hidden" name="update_categoria_paquete" value="1">
                                <input type="hidden" name="paquete_id" value="<?= (int) $p['id'] ?>">
                                <select name="categoria_paquete_id" class="form-select form-select-sm js-ajax-category-select" style="background:#222c3a;color:#22d3ee;border:1px solid #22d3ee;font-size:0.8rem;max-width:200px;" data-last-value="<?= $packageCat !== null ? (int) $packageCat['id'] : 0 ?>" onchange="window.adminPackageCategoryChange(this)">
                                    <option value="0"<?= $packageCat === null ? ' selected' : '' ?>>Sin categoría</option>
                                    <?php foreach ($packageCategories as $pcatOpt): ?>
                                    <option value="<?= (int) $pcatOpt['id'] ?>"<?= ($packageCat !== null && (int) $packageCat['id'] === (int) $pcatOpt['id']) ? ' selected' : '' ?>><?= htmlspecialchars(($pcatOpt['icono'] !== '' ? $pcatOpt['icono'] . ' ' : '') . $pcatOpt['nombre'], ENT_QUOTES, 'UTF-8') ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </form>
                        <?php endif; ?>
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
                    <td class="text-center" style="background:#181f2a;">
                        <div class="d-flex flex-column align-items-center gap-1">
                            <form method="post" action="<?= htmlspecialchars($adminPackageBaseUrl, ENT_QUOTES, 'UTF-8') ?>/<?= $juego_id ?>" class="m-0 d-inline-block js-ajax-toggle-dest-form">
                                <input type="hidden" name="ajax" value="1">
                                <input type="hidden" name="toggle_paquete_destacado" value="1">
                                <input type="hidden" name="paquete_id" value="<?= (int) $p['id'] ?>">
                                <input type="hidden" name="destacado" value="<?= !empty($p['destacado']) ? '1' : '0' ?>" class="js-ajax-toggle-dest-value">
                                <div class="form-check form-switch d-inline-flex justify-content-center mb-0">
                                    <input class="form-check-input js-ajax-toggle-dest-input" type="checkbox" <?= !empty($p['destacado']) ? 'checked' : '' ?> aria-label="Destacar paquete <?= htmlspecialchars($p['nombre'], ENT_QUOTES, 'UTF-8') ?>" onchange="window.adminPackageToggleDestacado(this)" style="<?= !empty($p['destacado']) ? 'background-color:#22d3ee;border-color:#22d3ee;' : '' ?>">
                                </div>
                            </form>
                        </div>
                    </td>
                    <td class="text-neon" style="background:#181f2a; color:#22d3ee;">
                        <?php
                        [$pAdminApiRaw, $pAdminMarkupPct] = admin_package_raw_price_and_markup($p, $apiProductsById, $recargasAmericaProductsById, $adminPackageMarkupPct, $adminPackageMarkupPctRecargasamerica);
                        $pAdminManualOverride = !empty($p['precio_manual_override']);
                        $pAdminDisplayPrice = (!$pAdminManualOverride && $pAdminApiRaw !== null)
                            ? max(0.0, round($pAdminApiRaw * (1 + $pAdminMarkupPct / 100), 2))
                            : floatval($p['precio']);
                        ?>
                        $<?= number_format($pAdminDisplayPrice, 2) ?>
                        <?php if ($pAdminManualOverride): ?>
                            <div class="small" style="color:#f9a825;font-size:0.7rem;">Manual</div>
                        <?php elseif ($pAdminApiRaw !== null): ?>
                            <div class="small" style="color:#8be9fd;font-size:0.7rem;">API: $<?= number_format($pAdminApiRaw, 4) ?></div>
                        <?php endif; ?>
                    </td>
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
            <?php $packageCat = $packageCategoriesById[(int) ($p['categoria_paquete_id'] ?? 0)] ?? null; ?>
            <div class="col-12 js-package-record js-package-filterable <?= $packageIsActive ? 'activo' : 'inactivo' ?>" data-package-context="mobile" data-package-id="<?= (int) ($p['id'] ?? 0) ?>" data-package-category="<?= $packageCat !== null ? (int) $packageCat['id'] : 'otros' ?>" style="<?= (($currentPackageTab === 'active' && !$packageIsActive) || ($currentPackageTab === 'inactive' && $packageIsActive)) ? 'display:none;' : '' ?>">
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
                            <?php if ($packageCategories !== []): ?>
                                <form method="post" action="<?= htmlspecialchars($adminPackageBaseUrl, ENT_QUOTES, 'UTF-8') ?>/<?= $juego_id ?>" class="mt-2 m-0 js-ajax-category-form">
                                    <input type="hidden" name="ajax" value="1">
                                    <input type="hidden" name="update_categoria_paquete" value="1">
                                    <input type="hidden" name="paquete_id" value="<?= (int) $p['id'] ?>">
                                    <select name="categoria_paquete_id" class="form-select form-select-sm js-ajax-category-select" style="background:#222c3a;color:#22d3ee;border:1px solid #22d3ee;font-size:0.8rem;max-width:220px;" data-last-value="<?= $packageCat !== null ? (int) $packageCat['id'] : 0 ?>" onchange="window.adminPackageCategoryChange(this)">
                                        <option value="0"<?= $packageCat === null ? ' selected' : '' ?>>Sin categoría</option>
                                        <?php foreach ($packageCategories as $pcatOpt): ?>
                                        <option value="<?= (int) $pcatOpt['id'] ?>"<?= ($packageCat !== null && (int) $packageCat['id'] === (int) $pcatOpt['id']) ? ' selected' : '' ?>><?= htmlspecialchars(($pcatOpt['icono'] !== '' ? $pcatOpt['icono'] . ' ' : '') . $pcatOpt['nombre'], ENT_QUOTES, 'UTF-8') ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </form>
                            <?php endif; ?>
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
                    <?php
                    [$pCardApiRaw, $pCardMarkupPct] = admin_package_raw_price_and_markup($p, $apiProductsById, $recargasAmericaProductsById, $adminPackageMarkupPct, $adminPackageMarkupPctRecargasamerica);
                    $pCardManualOverride = !empty($p['precio_manual_override']);
                    $pCardDisplayPrice = (!$pCardManualOverride && $pCardApiRaw !== null)
                        ? max(0.0, round($pCardApiRaw * (1 + $pCardMarkupPct / 100), 2))
                        : floatval($p['precio']);
                    ?>
                    <div class="text-neon" style="color:#22d3ee;"><span class="fw-semibold">Precio:</span> $<?= number_format($pCardDisplayPrice, 2) ?><?php if ($pCardManualOverride): ?> <span class="small" style="color:#f9a825;">Manual</span><?php elseif ($pCardApiRaw !== null): ?> <span class="small" style="color:#8be9fd;">(API: $<?= number_format($pCardApiRaw, 4) ?>)</span><?php endif; ?></div>
                    <div style="color:#fff;"><span class="fw-semibold"><?= htmlspecialchars($winPointsName, ENT_QUOTES, 'UTF-8') ?>:</span> <?= (int) ($p['win_points_reward'] ?? 0) ?></div>
                    <form method="post" action="<?= htmlspecialchars($adminPackageBaseUrl, ENT_QUOTES, 'UTF-8') ?>/<?= $juego_id ?>" class="mt-2 d-inline-flex align-items-center gap-2 js-ajax-toggle-dest-form">
                        <input type="hidden" name="ajax" value="1">
                        <input type="hidden" name="toggle_paquete_destacado" value="1">
                        <input type="hidden" name="paquete_id" value="<?= (int) $p['id'] ?>">
                        <input type="hidden" name="destacado" value="<?= !empty($p['destacado']) ? '1' : '0' ?>" class="js-ajax-toggle-dest-value">
                        <div class="form-check form-switch mb-0">
                            <input class="form-check-input js-ajax-toggle-dest-input" type="checkbox" <?= !empty($p['destacado']) ? 'checked' : '' ?> aria-label="Destacar en GG Drops" onchange="window.adminPackageToggleDestacado(this)">
                        </div>
                        <span class="small" style="color:#22d3ee;">&#9889; GG Drops</span>
                    </form>
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
    $paqEditApiSourceKey = trim((string) ($paq_edit['api_source_key'] ?? ''));
    $paqEditSelectedSource = '';
    if ($paqEditProvider === 'giftven') {
        if ($giftVenActiveSlots > 1) {
            if ($hasGiftVenCatalog3 && $paqEditApiSourceKey === $juegoCategoriaApi3) {
                $paqEditSelectedSource = 'giftven_3';
            } elseif ($hasGiftVenCatalog2 && $paqEditApiSourceKey === $juegoCategoriaApi2) {
                $paqEditSelectedSource = 'giftven_2';
            } else {
                $paqEditSelectedSource = 'giftven_1';
            }
        } else {
            $paqEditSelectedSource = 'giftven';
        }
    } elseif ($paqEditProvider === 'discord') {
        if ($discordActiveSlots > 1) {
            if ($hasDiscordCatalog3 && $paqEditApiSourceKey === $juegoCategoriaApiDiscord3) {
                $paqEditSelectedSource = 'discord_3';
            } elseif ($hasDiscordCatalog2 && $paqEditApiSourceKey === $juegoCategoriaApiDiscord2) {
                $paqEditSelectedSource = 'discord_2';
            } else {
                $paqEditSelectedSource = 'discord_1';
            }
        } else {
            $paqEditSelectedSource = 'discord';
        }
    } elseif ($paqEditProvider === 'free_fire') {
        $paqEditSelectedSource = 'free_fire';
    }
    if ($paq_edit):
?>
<div class="fixed-top w-100 h-100 d-flex align-items-start justify-content-center" style="background:rgba(0,0,0,0.7);z-index:1050;overflow-y:auto;padding:1rem;">
    <form method="post" enctype="multipart/form-data" class="bg-dark neon-card p-4 rounded-4 position-relative" data-package-source-form="1" style="max-width:560px;width:100%;max-height:calc(100vh - 2rem);overflow-y:auto;box-shadow:0 0 2rem #22d3ee33;">
        <div class="d-flex align-items-start justify-content-between gap-3 mb-3">
            <h3 class="text-neon mb-0">Editar paquete</h3>
            <a href="<?= htmlspecialchars($adminPackageBaseUrl, ENT_QUOTES, 'UTF-8') ?>/<?= $juego_id ?>" class="btn btn-outline-info btn-sm flex-shrink-0">Cerrar</a>
        </div>
        <input type="hidden" name="edit_paquete_id" value="<?= $paq_edit['id'] ?>">
        <?php if (!$packageSourceSelectionEnabled && $packageDefaultSourceValue !== ''): ?>
            <input type="hidden" name="edit_api_provider" value="<?= htmlspecialchars($packageDefaultSourceValue, ENT_QUOTES, 'UTF-8') ?>">
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
                    <?php foreach ($packageSourceItems as $srcItem): ?>
                        <label class="d-inline-flex align-items-center gap-2 rounded-pill px-3 py-2" style="background:rgba(15,23,42,0.92);border:1px solid rgba(34,211,238,0.28);color:#d8fbff;cursor:pointer;">
                            <input type="radio" name="edit_api_provider" value="<?= htmlspecialchars($srcItem['value'], ENT_QUOTES, 'UTF-8') ?>" class="form-check-input mt-0" data-package-source-radio <?= $paqEditSelectedSource === $srcItem['value'] ? 'checked' : '' ?>>
                            <span><?= htmlspecialchars($srcItem['label'], ENT_QUOTES, 'UTF-8') ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <button type="button" class="btn btn-outline-info btn-sm mt-3" data-package-source-clear>Limpiar selección</button>
            </div>
        <?php endif; ?>
        <?php if ($giftVenActiveSlots > 1): ?>
            <?php if ($hasGiftVenCatalog): ?>
            <div class="mb-3" data-package-source-panel="giftven_1">
                <label class="form-label text-neon">Producto API — <?= htmlspecialchars($juegoCategoriaApi, ENT_QUOTES, 'UTF-8') ?></label>
                <select name="edit_paquete_api" data-package-source-required="1" class="form-select" style="background:#222c3a;color:#22d3ee;border:1px solid #22d3ee;">
                    <option value="">Selecciona un producto API</option>
                    <?php foreach ($apiProducts as $apiProduct): ?>
                        <option value="<?= (int) ($apiProduct['id'] ?? 0) ?>" <?= ($paqEditSelectedSource === 'giftven_1' && (int) ($paq_edit['paquete_api'] ?? 0) === (int) ($apiProduct['id'] ?? 0)) ? 'selected' : '' ?>><?= htmlspecialchars(recargas_api_product_label($apiProduct), ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <?php if ($hasGiftVenCatalog2): ?>
            <div class="mb-3" data-package-source-panel="giftven_2">
                <label class="form-label text-neon">Producto API — <?= htmlspecialchars($juegoCategoriaApi2, ENT_QUOTES, 'UTF-8') ?></label>
                <select name="edit_paquete_api" data-package-source-required="1" class="form-select" style="background:#222c3a;color:#22d3ee;border:1px solid #22d3ee;">
                    <option value="">Selecciona un producto API</option>
                    <?php foreach ($apiProducts2 as $apiProduct): ?>
                        <option value="<?= (int) ($apiProduct['id'] ?? 0) ?>" <?= ($paqEditSelectedSource === 'giftven_2' && (int) ($paq_edit['paquete_api'] ?? 0) === (int) ($apiProduct['id'] ?? 0)) ? 'selected' : '' ?>><?= htmlspecialchars(recargas_api_product_label($apiProduct), ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <?php if ($hasGiftVenCatalog3): ?>
            <div class="mb-3" data-package-source-panel="giftven_3">
                <label class="form-label text-neon">Producto API — <?= htmlspecialchars($juegoCategoriaApi3, ENT_QUOTES, 'UTF-8') ?></label>
                <select name="edit_paquete_api" data-package-source-required="1" class="form-select" style="background:#222c3a;color:#22d3ee;border:1px solid #22d3ee;">
                    <option value="">Selecciona un producto API</option>
                    <?php foreach ($apiProducts3 as $apiProduct): ?>
                        <option value="<?= (int) ($apiProduct['id'] ?? 0) ?>" <?= ($paqEditSelectedSource === 'giftven_3' && (int) ($paq_edit['paquete_api'] ?? 0) === (int) ($apiProduct['id'] ?? 0)) ? 'selected' : '' ?>><?= htmlspecialchars(recargas_api_product_label($apiProduct), ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
        <?php elseif ($hasGiftVenCatalog): ?>
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
        <?php if ($recargasAmericaActiveSlots > 1): ?>
            <?php if ($hasRecargasAmericaCatalog): ?>
            <div class="mb-3" data-package-source-panel="recargasamerica_1">
                <label class="form-label text-neon">Producto RecargasAmérica — <?= htmlspecialchars($juegoCategoriaApiRecargasAmerica, ENT_QUOTES, 'UTF-8') ?></label>
                <select name="edit_paquete_api" data-package-source-required="1" class="form-select" style="background:#222c3a;color:#22d3ee;border:1px solid #22d3ee;">
                    <option value="">Selecciona un producto</option>
                    <?php foreach ($recargasAmericaProducts1 as $raProduct): ?>
                        <option value="<?= (int) ($raProduct['id'] ?? 0) ?>" <?= ($paqEditSelectedSource === 'recargasamerica_1' && (int) ($paq_edit['paquete_api'] ?? 0) === (int) ($raProduct['id'] ?? 0)) ? 'selected' : '' ?>><?= htmlspecialchars(recargasamerica_api_product_label($raProduct), ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <?php if ($hasRecargasAmericaCatalog2): ?>
            <div class="mb-3" data-package-source-panel="recargasamerica_2">
                <label class="form-label text-neon">Producto RecargasAmérica — <?= htmlspecialchars($juegoCategoriaApiRecargasAmerica2, ENT_QUOTES, 'UTF-8') ?></label>
                <select name="edit_paquete_api" data-package-source-required="1" class="form-select" style="background:#222c3a;color:#22d3ee;border:1px solid #22d3ee;">
                    <option value="">Selecciona un producto</option>
                    <?php foreach ($recargasAmericaProducts2 as $raProduct): ?>
                        <option value="<?= (int) ($raProduct['id'] ?? 0) ?>" <?= ($paqEditSelectedSource === 'recargasamerica_2' && (int) ($paq_edit['paquete_api'] ?? 0) === (int) ($raProduct['id'] ?? 0)) ? 'selected' : '' ?>><?= htmlspecialchars(recargasamerica_api_product_label($raProduct), ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <?php if ($hasRecargasAmericaCatalog3): ?>
            <div class="mb-3" data-package-source-panel="recargasamerica_3">
                <label class="form-label text-neon">Producto RecargasAmérica — <?= htmlspecialchars($juegoCategoriaApiRecargasAmerica3, ENT_QUOTES, 'UTF-8') ?></label>
                <select name="edit_paquete_api" data-package-source-required="1" class="form-select" style="background:#222c3a;color:#22d3ee;border:1px solid #22d3ee;">
                    <option value="">Selecciona un producto</option>
                    <?php foreach ($recargasAmericaProducts3 as $raProduct): ?>
                        <option value="<?= (int) ($raProduct['id'] ?? 0) ?>" <?= ($paqEditSelectedSource === 'recargasamerica_3' && (int) ($paq_edit['paquete_api'] ?? 0) === (int) ($raProduct['id'] ?? 0)) ? 'selected' : '' ?>><?= htmlspecialchars(recargasamerica_api_product_label($raProduct), ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
        <?php elseif ($hasRecargasAmericaCatalog): ?>
            <div class="mb-3" data-package-source-panel="recargasamerica">
                <label class="form-label text-neon">Producto RecargasAmérica</label>
                <select name="edit_paquete_api" <?= $packageSourceSelectionEnabled ? 'data-package-source-required="1"' : 'required' ?> class="form-select" style="background:#222c3a;color:#22d3ee;border:1px solid #22d3ee;">
                    <option value="">Selecciona un producto</option>
                    <?php foreach ($recargasAmericaProducts1 as $raProduct): ?>
                        <option value="<?= (int) ($raProduct['id'] ?? 0) ?>" <?= (int) ($paq_edit['paquete_api'] ?? 0) === (int) ($raProduct['id'] ?? 0) ? 'selected' : '' ?>><?= htmlspecialchars(recargasamerica_api_product_label($raProduct), ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if ($recargasAmericaProductsError !== null): ?>
                    <div class="form-text mt-2 text-danger">No se pudo cargar el catálogo de RecargasAmérica: <?= htmlspecialchars($recargasAmericaProductsError, ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <?php if ($discordActiveSlots > 1): ?>
            <?php if ($hasDiscordCatalog): ?>
            <div class="mb-3" data-package-source-panel="discord_1">
                <label class="form-label text-neon">Cantidad — <?= htmlspecialchars(trim((string) ($discordTopupCommand['label'] ?? $juegoCategoriaApiDiscord)), ENT_QUOTES, 'UTF-8') ?></label>
                <input type="text" name="edit_cantidad" value="<?= $paqEditSelectedSource === 'discord_1' ? htmlspecialchars($paq_edit['cantidad'] ?? '') : '1' ?>" data-package-source-required="1" maxlength="80" class="form-control" style="background:#222c3a;color:#22d3ee;border:1px solid #22d3ee;" data-discord-catalog-field="quantity">
                <?php if ($discordTopupCommandText !== ''): ?><div class="form-text mt-1" style="color:#8be9fd;">Comando: <?= htmlspecialchars($discordTopupCommandText, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
            </div>
            <?php endif; ?>
            <?php if ($hasDiscordCatalog2): ?>
            <div class="mb-3" data-package-source-panel="discord_2">
                <label class="form-label text-neon">Cantidad — <?= htmlspecialchars(trim((string) ($discordTopupCommand2['label'] ?? $juegoCategoriaApiDiscord2)), ENT_QUOTES, 'UTF-8') ?></label>
                <input type="text" name="edit_cantidad" value="<?= $paqEditSelectedSource === 'discord_2' ? htmlspecialchars($paq_edit['cantidad'] ?? '') : '1' ?>" data-package-source-required="1" maxlength="80" class="form-control" style="background:#222c3a;color:#22d3ee;border:1px solid #22d3ee;">
                <?php if ($discordTopupCommandText2 !== ''): ?><div class="form-text mt-1" style="color:#8be9fd;">Comando: <?= htmlspecialchars($discordTopupCommandText2, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
            </div>
            <?php endif; ?>
            <?php if ($hasDiscordCatalog3): ?>
            <div class="mb-3" data-package-source-panel="discord_3">
                <label class="form-label text-neon">Cantidad — <?= htmlspecialchars(trim((string) ($discordTopupCommand3['label'] ?? $juegoCategoriaApiDiscord3)), ENT_QUOTES, 'UTF-8') ?></label>
                <input type="text" name="edit_cantidad" value="<?= $paqEditSelectedSource === 'discord_3' ? htmlspecialchars($paq_edit['cantidad'] ?? '') : '1' ?>" data-package-source-required="1" maxlength="80" class="form-control" style="background:#222c3a;color:#22d3ee;border:1px solid #22d3ee;">
                <?php if ($discordTopupCommandText3 !== ''): ?><div class="form-text mt-1" style="color:#8be9fd;">Comando: <?= htmlspecialchars($discordTopupCommandText3, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
            </div>
            <?php endif; ?>
        <?php elseif ($hasDiscordCatalog): ?>
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
            <?php if ($paqEditProvider === 'giftven' || $paqEditProvider === 'recargasamerica'): ?>
                <?php
                [$paqEditApiRaw, $paqEditMarkupPct] = admin_package_raw_price_and_markup($paq_edit, $apiProductsById, $recargasAmericaProductsById, $adminPackageMarkupPct, $adminPackageMarkupPctRecargasamerica);
                $paqEditComputedPrice = $paqEditApiRaw !== null ? max(0.0, round($paqEditApiRaw * (1 + $paqEditMarkupPct / 100), 2)) : null;
                $paqEditManualOverride = !empty($paq_edit['precio_manual_override']);
                $paqEditProviderLabel = admin_package_provider_label($paqEditProvider);
                ?>
                <?php if ($paqEditManualOverride): ?>
                    <div class="form-text mt-1" style="color:#f9a825;">Precio manual activo. El precio de la API no se aplicará a este paquete.</div>
                <?php elseif ($paqEditComputedPrice !== null): ?>
                    <div class="form-text mt-1" style="color:#22d3ee;">Precio vigente (<?= htmlspecialchars($paqEditProviderLabel, ENT_QUOTES, 'UTF-8') ?> + margen): <strong>$<?= number_format($paqEditComputedPrice, 2) ?></strong> — API base: $<?= number_format($paqEditApiRaw, 4) ?> × <?= number_format(1 + $paqEditMarkupPct / 100, 4) ?></div>
                <?php else: ?>
                    <div class="form-text mt-1" style="color:#8be9fd;">Margen configurado (<?= htmlspecialchars($paqEditProviderLabel, ENT_QUOTES, 'UTF-8') ?>): <?= number_format($paqEditMarkupPct, 2) ?>%. El precio real se calculará desde la API cuando el cliente abra el juego.</div>
                <?php endif; ?>
            <?php endif; ?>
            <div class="form-check mt-2">
                <input type="checkbox" name="edit_precio_manual_override" class="form-check-input" id="editPrecioManualCheck" <?= !empty($paq_edit['precio_manual_override']) ? 'checked' : '' ?>>
                <label class="form-check-label" for="editPrecioManualCheck" style="color:#f9a825;font-size:0.875rem;">Usar precio manual (ignorar precio de API)</label>
            </div>
        </div>
        <div class="mb-3">
            <label class="form-label text-neon"><?= htmlspecialchars($winPointsName, ENT_QUOTES, 'UTF-8') ?> a ganar</label>
            <input type="number" min="0" name="edit_win_points_reward" value="<?= (int) ($paq_edit['win_points_reward'] ?? 0) ?>" class="form-control" style="background:#222c3a;color:#22d3ee;border:1px solid #22d3ee;">
        </div>
        <div class="mb-3">
            <label class="form-label text-neon">Categoría de paquete</label>
            <select name="edit_categoria_paquete_id" class="form-select" style="background:#222c3a;color:#22d3ee;border:1px solid #22d3ee;">
                <option value="0">Sin categoría (irá en "Otros")</option>
                <?php foreach ($packageCategories as $pcat): ?>
                <option value="<?= (int) $pcat['id'] ?>" <?= (int) ($paq_edit['categoria_paquete_id'] ?? 0) === (int) $pcat['id'] ? 'selected' : '' ?>><?= htmlspecialchars(($pcat['icono'] !== '' ? $pcat['icono'] . ' ' : '') . $pcat['nombre'], ENT_QUOTES, 'UTF-8') ?></option>
                <?php endforeach; ?>
            </select>
            <?php if ($packageCategories === []): ?>
            <div class="form-text mt-2" style="color:#8be9fd;">Crea categorías en la sección «Categorías de paquetes» para poder asignarlas.</div>
            <?php endif; ?>
        </div>
        <div class="mb-3">
            <label class="form-label text-neon">Nivel de Pase (Pase de Nivel)</label>
            <select name="edit_levelpass_key" class="form-select" style="background:#222c3a;color:#22d3ee;border:1px solid #22d3ee;">
                <option value="">No aplica</option>
                <?php foreach (levelpass_key_options() as $lpKey => $lpLabel): ?>
                <option value="<?= htmlspecialchars($lpKey, ENT_QUOTES, 'UTF-8') ?>" <?= (string) ($paq_edit['levelpass_key'] ?? '') === $lpKey ? 'selected' : '' ?>><?= htmlspecialchars($lpLabel, ENT_QUOTES, 'UTF-8') ?></option>
                <?php endforeach; ?>
            </select>
            <div class="form-text mt-2" style="color:#8be9fd;">Solo para paquetes de "Pase de Nivel": define qué nivel representa este paquete para consultar disponibilidad por jugador.</div>
        </div>
        <?php $editFiServiceId = (int) ($paq_edit['fullimpulso_service_id'] ?? 0); ?>
        <?php $editFiCantidad = (int) ($paq_edit['fullimpulso_cantidad'] ?? 0); ?>
        <div class="mb-3">
            <div class="p-3 rounded-3" style="background:#182030;border:1px solid #1e3a5f;">
                <div class="form-check mb-2">
                    <input type="checkbox" class="form-check-input js-fi-toggle" id="editFiEnabledCheck" data-panel="editFiPanel" data-hidden-input="editFiEnabledInput" <?= $editFiServiceId > 0 ? 'checked' : '' ?>>
                    <label class="form-check-label text-neon" for="editFiEnabledCheck">Usar FullImpulso (Seguidores) para este paquete</label>
                </div>
                <input type="hidden" name="edit_fullimpulso_enabled" id="editFiEnabledInput" value="<?= $editFiServiceId > 0 ? '1' : '0' ?>">
                <div id="editFiPanel" style="<?= $editFiServiceId > 0 ? '' : 'display:none;' ?>">
                    <div class="row g-2">
                        <div class="col-md-7">
                            <label class="form-label text-neon small mb-1">Servicio de FullImpulso</label>
                            <select name="edit_fullimpulso_service_id" class="form-select form-select-sm js-fi-service" style="background:#222c3a;color:#22d3ee;border:1px solid #22d3ee;">
                                <option value="">Selecciona un servicio</option>
                                <?php foreach ($fullimpulsoServices as $fiSvc): ?>
                                <option value="<?= (int) ($fiSvc['service'] ?? 0) ?>" data-rate="<?= htmlspecialchars((string) ($fiSvc['rate'] ?? '0'), ENT_QUOTES, 'UTF-8') ?>" data-type="<?= htmlspecialchars((string) ($fiSvc['type'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" <?= (int) ($fiSvc['service'] ?? 0) === $editFiServiceId ? 'selected' : '' ?>><?= htmlspecialchars(trim((string) ($fiSvc['name'] ?? '')) . ' — $' . trim((string) ($fiSvc['rate'] ?? '0')) . '/1000', ENT_QUOTES, 'UTF-8') ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php if ($fullimpulsoServicesError !== ''): ?>
                            <div class="form-text mt-1" style="color:#ff5e8a;">No se pudo cargar la lista de servicios: <?= htmlspecialchars($fullimpulsoServicesError, ENT_QUOTES, 'UTF-8') ?></div>
                            <?php elseif (!fullimpulso_is_configured()): ?>
                            <div class="form-text mt-1" style="color:#ff5e8a;">Configura la API key de FullImpulso primero.</div>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label text-neon small mb-1">Cantidad fija a enviar</label>
                            <input type="number" min="1" name="edit_fullimpulso_cantidad" value="<?= $editFiCantidad > 0 ? $editFiCantidad : '' ?>" class="form-control form-control-sm js-fi-quantity" style="background:#222c3a;color:#22d3ee;border:1px solid #22d3ee;" placeholder="Ej: 1000">
                        </div>
                    </div>
                    <div class="form-text mt-2 js-fi-cost-preview" style="color:#8be9fd;"></div>
                    <div class="form-check mt-2">
                        <input type="checkbox" name="edit_fullimpulso_custom_comments_manual" class="form-check-input js-fi-comments-manual" id="editFiCommentsManualCheck" <?= !empty($paq_edit['fullimpulso_custom_comments']) ? 'checked' : '' ?>>
                        <label class="form-check-label" style="color:#facc15;" for="editFiCommentsManualCheck">Requiere que el cliente escriba un comentario por linea (se marca solo si se detecta, pero puedes forzarlo aqui)</label>
                    </div>
                </div>
            </div>
        </div>
        <div class="form-check mb-2">
            <input type="checkbox" name="edit_activo" class="form-check-input" id="editPaqueteActivoCheck" <?= !isset($paq_edit['activo']) || !empty($paq_edit['activo']) ? 'checked' : '' ?>>
            <label class="form-check-label text-neon" for="editPaqueteActivoCheck">Paquete activo / publicado</label>
        </div>
        <div class="form-check mb-2">
            <input type="checkbox" name="edit_destacado" class="form-check-input" id="editPaqueteDestacadoCheck" <?= !empty($paq_edit['destacado']) ? 'checked' : '' ?> onchange="toggleDescuentoDestacado('editDescuentoDestacadoWrap', this.checked)">
            <label class="form-check-label text-neon" for="editPaqueteDestacadoCheck">&#9889; Paquete destacado (GG Drops)</label>
        </div>
        <div id="editDescuentoDestacadoWrap" class="mb-3" style="<?= !empty($paq_edit['destacado']) ? '' : 'display:none;' ?>">
            <label class="form-label text-neon small mb-1" for="editOrdenGgInput">Orden en GG Drops</label>
            <input type="number" min="0" max="9999" name="edit_orden_gg" id="editOrdenGgInput" value="<?= $paq_edit['orden_gg'] !== null ? (int) $paq_edit['orden_gg'] : '' ?>" class="form-control form-control-sm mb-2" style="background:#222c3a;color:#22d3ee;border:1px solid #22d3ee;max-width:120px;" placeholder="Auto">
            <div class="form-text mb-2" style="color:#8be9fd;">Posición en la sección GG Drops (vacío = automático).</div>
            <label class="form-label text-neon small mb-1" for="editDescuentoDestacadoInput">Descuento GG Drops (%)</label>
            <input type="number" min="0" max="99" name="edit_descuento_destacado" id="editDescuentoDestacadoInput" value="<?= (int) ($paq_edit['descuento_destacado'] ?? 0) ?>" class="form-control form-control-sm" style="background:#222c3a;color:#22d3ee;border:1px solid #22d3ee;max-width:120px;">
            <div class="form-text" style="color:#8be9fd;">Porcentaje mostrado en la tarjeta (0 = sin descuento).</div>
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
            <div class="text-neon fw-semibold mb-1">Información del paquete (ícono "i")</div>
            <div class="small mb-2" style="color:#8be9fd;">Este texto se mostrará en una ventana al tocar el ícono "i" del paquete en la tienda. Si lo dejas vacío, el ícono no aparece.</div>
            <?= admin_package_info_editor_html('edit_info_paquete_html', (string) ($paq_edit['info_html'] ?? '')) ?>
        </div>
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
                            <label class="badge rounded-pill d-inline-flex align-items-center gap-2 px-3 py-2" style="cursor:pointer;<?= htmlspecialchars(admin_package_feature_badge_inline_style($feature), ENT_QUOTES, 'UTF-8') ?>">
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

function packageFeatureCustomIconInputFor(select) {
    const editor = select ? select.closest('[data-package-feature-editor]') : null;
    const scope = editor ? editor.parentElement : null;
    return scope ? scope.querySelector('[data-package-feature-icon-custom]') : null;
}

function updatePackageFeatureIconPreview(select) {
    if (!select) {
        return;
    }

    const editor = select.closest('[data-package-feature-editor]');
    const preview = editor ? editor.querySelector('[data-package-feature-icon-preview]') : null;
    if (!preview) {
        return;
    }

    const customInput = packageFeatureCustomIconInputFor(select);
    const customValue = customInput ? customInput.value.trim() : '';
    if (customValue !== '') {
        preview.innerHTML = '';
        const span = document.createElement('span');
        span.setAttribute('aria-hidden', 'true');
        span.style.cssText = 'display:inline-block;font-size:1.05rem;line-height:1;';
        span.textContent = customValue;
        preview.appendChild(span);
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
        const customInput = packageFeatureCustomIconInputFor(select);
        if (customInput) {
            customInput.addEventListener('input', function() {
                updatePackageFeatureIconPreview(select);
            });
        }
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
window.adminPackageCategoryTab = '';

window.adminPackageRefreshTabCounts = function() {
    const counts = { active: 0, inactive: 0 };
    const categoryCounts = {};
    document.querySelectorAll('.js-package-record[data-package-context="desktop"]').forEach((record) => {
        const status = record.classList.contains('inactivo') ? 'inactive' : 'active';
        counts[status] += 1;
        const cat = record.getAttribute('data-package-category') || 'otros';
        categoryCounts[cat] = (categoryCounts[cat] || 0) + 1;
        categoryCounts.__todos__ = (categoryCounts.__todos__ || 0) + 1;
    });
    document.querySelectorAll('[data-package-tab-count]').forEach((node) => {
        const status = node.getAttribute('data-package-tab-count') === 'inactive' ? 'inactive' : 'active';
        node.textContent = String(counts[status] || 0);
    });
    document.querySelectorAll('[data-package-category-tab-count]').forEach((node) => {
        const cat = node.getAttribute('data-package-category-tab-count') || '';
        const key = cat === '' ? '__todos__' : cat;
        node.textContent = '(' + String(categoryCounts[key] || 0) + ')';
    });
};

window.adminPackageApplyFilters = function() {
    const filterClass = window.adminPackageActiveTab === 'inactive' ? 'inactivo' : 'activo';
    const categoryTab = window.adminPackageCategoryTab || '';

    document.querySelectorAll('.js-package-tab-btn').forEach((button) => {
        const isCurrent = button.getAttribute('data-package-tab') === window.adminPackageActiveTab;
        button.classList.toggle('btn-info', isCurrent);
        button.classList.toggle('btn-outline-info', !isCurrent);
    });

    document.querySelectorAll('.js-package-category-tab-btn').forEach((button) => {
        const isCurrent = (button.getAttribute('data-package-category-tab') || '') === categoryTab;
        button.classList.toggle('btn-info', isCurrent);
        button.classList.toggle('active', isCurrent);
        button.classList.toggle('btn-outline-info', !isCurrent);
    });

    document.querySelectorAll('.js-package-filterable').forEach((record) => {
        const matchesActive = record.classList.contains(filterClass);
        const matchesCategory = categoryTab === '' || (record.getAttribute('data-package-category') || 'otros') === categoryTab;
        record.style.display = (matchesActive && matchesCategory) ? '' : 'none';
    });
};

window.filterAdminPackagesByClass = function(filterClass) {
    window.adminPackageActiveTab = filterClass === 'inactivo' ? 'inactive' : 'active';
    window.adminPackageApplyFilters();
};

window.filterAdminPackagesByCategory = function(categoryTab) {
    window.adminPackageCategoryTab = categoryTab || '';
    window.adminPackageApplyFilters();
};

window.initAdminPackageTabs = function() {
    window.adminPackageRefreshTabCounts();
    window.adminPackageApplyFilters();
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
    window.adminPackageCategoryTab = '';

    window.adminPackageRefreshTabCounts = function() {
        const counts = { active: 0, inactive: 0 };
        const categoryCounts = {};
        document.querySelectorAll('.js-package-record[data-package-context="desktop"]').forEach((record) => {
            const status = record.classList.contains('inactivo') ? 'inactive' : 'active';
            counts[status] += 1;
            const cat = record.getAttribute('data-package-category') || 'otros';
            categoryCounts[cat] = (categoryCounts[cat] || 0) + 1;
            categoryCounts.__todos__ = (categoryCounts.__todos__ || 0) + 1;
        });
        document.querySelectorAll('[data-package-tab-count]').forEach((node) => {
            const status = node.getAttribute('data-package-tab-count') === 'inactive' ? 'inactive' : 'active';
            node.textContent = String(counts[status] || 0);
        });
        document.querySelectorAll('[data-package-category-tab-count]').forEach((node) => {
            const cat = node.getAttribute('data-package-category-tab-count') || '';
            const key = cat === '' ? '__todos__' : cat;
            node.textContent = '(' + String(categoryCounts[key] || 0) + ')';
        });
    };

    window.adminPackageApplyFilters = function() {
        const filterClass = window.adminPackageActiveTab === 'inactive' ? 'inactivo' : 'activo';
        const categoryTab = window.adminPackageCategoryTab || '';

        document.querySelectorAll('.js-package-tab-btn').forEach((button) => {
            const isCurrent = button.getAttribute('data-package-tab') === window.adminPackageActiveTab;
            button.classList.toggle('btn-info', isCurrent);
            button.classList.toggle('btn-outline-info', !isCurrent);
        });

        document.querySelectorAll('.js-package-category-tab-btn').forEach((button) => {
            const isCurrent = (button.getAttribute('data-package-category-tab') || '') === categoryTab;
            button.classList.toggle('btn-info', isCurrent);
            button.classList.toggle('active', isCurrent);
            button.classList.toggle('btn-outline-info', !isCurrent);
        });

        document.querySelectorAll('.js-package-filterable').forEach((record) => {
            const matchesActive = record.classList.contains(filterClass);
            const matchesCategory = categoryTab === '' || (record.getAttribute('data-package-category') || 'otros') === categoryTab;
            record.style.display = (matchesActive && matchesCategory) ? '' : 'none';
        });
    };

    window.filterAdminPackagesByClass = function(filterClass) {
        window.adminPackageActiveTab = filterClass === 'inactivo' ? 'inactive' : 'active';
        window.adminPackageApplyFilters();
    };

    window.filterAdminPackagesByCategory = function(categoryTab) {
        window.adminPackageCategoryTab = categoryTab || '';
        window.adminPackageApplyFilters();
    };

    window.initAdminPackageTabs = function() {
        window.adminPackageRefreshTabCounts();
        window.adminPackageApplyFilters();
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

window.adminPackageSaveDestDiscount = async function(input) {
    if (!input || input.dataset.busy === '1' || !input.form) {
        return;
    }
    const form = input.form;
    const val = Math.max(0, Math.min(99, parseInt(input.value || '0', 10) || 0));
    input.value = val;
    const requestData = new FormData(form);
    input.dataset.busy = '1';
    input.readOnly = true;
    try {
        await window.submitAjaxAdminForm(form, requestData);
    } catch (error) {
        window.alert(error.message);
    } finally {
        input.readOnly = false;
        input.dataset.busy = '0';
    }
};

window.toggleDescuentoDestacado = function(wrapId, show) {
    const wrap = document.getElementById(wrapId);
    if (wrap) {
        wrap.style.display = show ? '' : 'none';
        if (!show) {
            const input = wrap.querySelector('input[type="number"]');
            if (input) input.value = '0';
        }
    }
};

window.adminPackageToggleDestacado = async function(input) {
    if (!input || input.dataset.busy === '1' || !input.form) {
        return;
    }

    const form = input.form;
    const valueInput = form.querySelector('.js-ajax-toggle-dest-value');

    if (valueInput) {
        valueInput.value = input.checked ? '1' : '0';
    }

    const requestData = new FormData(form);
    input.dataset.busy = '1';
    input.disabled = true;

    try {
        const payload = await window.submitAjaxAdminForm(form, requestData);
        const isChecked = String(payload.destacado || 0) === '1';
        input.checked = isChecked;
        input.style.backgroundColor = isChecked ? '#22d3ee' : '';
        input.style.borderColor = isChecked ? '#22d3ee' : '';
        if (valueInput) {
            valueInput.value = isChecked ? '1' : '0';
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

window.adminPackageCategoryChange = async function(select) {
    if (!select || !select.form) {
        return;
    }

    const form = select.form;
    const lastValue = String(select.dataset.lastValue || '0');
    const newValue = String(select.value || '0');
    if (newValue === lastValue) {
        return;
    }

    const requestData = new FormData(form);
    select.disabled = true;

    try {
        const payload = await window.submitAjaxAdminForm(form, requestData);
        const savedCategoryId = String(payload.categoria_paquete_id ?? newValue);
        select.dataset.lastValue = savedCategoryId;
        select.value = savedCategoryId;

        const packageIdInput = form.querySelector('input[name="paquete_id"]');
        const packageId = packageIdInput ? String(packageIdInput.value || '') : '';
        const categoryTab = savedCategoryId === '0' ? 'otros' : savedCategoryId;

        document.querySelectorAll('.js-package-record').forEach((record) => {
            if (String(record.getAttribute('data-package-id') || '') === packageId) {
                record.setAttribute('data-package-category', categoryTab);
            }
        });

        document.querySelectorAll('.js-ajax-category-select').forEach((otherSelect) => {
            if (otherSelect === select) {
                return;
            }
            const otherForm = otherSelect.form;
            const otherPkgInput = otherForm ? otherForm.querySelector('input[name="paquete_id"]') : null;
            const otherPkgId = otherPkgInput ? String(otherPkgInput.value || '') : '';
            if (otherPkgId === packageId) {
                otherSelect.dataset.lastValue = savedCategoryId;
                otherSelect.value = savedCategoryId;
            }
        });

        if (typeof window.filterAdminPackagesByCategory === 'function') {
            window.filterAdminPackagesByCategory(categoryTab);
        }
        if (typeof window.adminPackageRefreshTabCounts === 'function') {
            window.adminPackageRefreshTabCounts();
        }
    } catch (error) {
        select.value = lastValue;
        window.alert(error.message);
    } finally {
        select.disabled = false;
    }
};

/* ── Editor visual de "Información del paquete" (ícono i) ─────────────── */
(function () {
    document.querySelectorAll('[data-pkg-info-editor]').forEach((editor) => {
        const area = editor.querySelector('[data-pkg-info-area]');
        const field = editor.querySelector('[data-pkg-info-field]');
        if (!area || !field) return;

        const sync = () => { field.value = area.innerHTML; };

        editor.querySelectorAll('[data-pkg-cmd]').forEach((btn) => {
            btn.addEventListener('click', () => {
                area.focus();
                document.execCommand(btn.dataset.pkgCmd, false, null);
                sync();
            });
        });

        const colorInput = editor.querySelector('[data-pkg-cmd-color]');
        if (colorInput) {
            colorInput.addEventListener('input', () => {
                area.focus();
                document.execCommand('foreColor', false, colorInput.value);
                sync();
            });
        }

        const sizeSelect = editor.querySelector('[data-pkg-cmd-size]');
        if (sizeSelect) {
            sizeSelect.addEventListener('change', () => {
                area.focus();
                document.execCommand('fontSize', false, sizeSelect.value !== '' ? sizeSelect.value : '3');
                sync();
            });
        }

        area.addEventListener('input', sync);
        area.addEventListener('blur', sync);

        const form = editor.closest('form');
        if (form) {
            form.addEventListener('submit', sync);
        }
    });
}());

// ═══ CATEGORÍAS DE PAQUETES ══════════════════════════════════════════════
(function () {
    const PCATS_URL = '<?= htmlspecialchars(app_path('/admin/paquete-categorias'), ENT_QUOTES, 'UTF-8') ?>';
    const CURRENT_JUEGO_ID = <?= (int) $juego_id ?>;

    async function pcatsFetch(action, data) {
        const fd = new FormData();
        fd.append('action', action);
        if (data) {
            for (const [k, v] of Object.entries(data)) {
                if (v == null) continue;
                if (v instanceof File) {
                    fd.append(k, v);
                } else {
                    fd.append(k, String(v));
                }
            }
        }
        const resp = await fetch(PCATS_URL, {
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

    document.getElementById('pcatToggleCreate')?.addEventListener('click', () => {
        const f = document.getElementById('pcatCreateForm');
        if (f) f.style.display = f.style.display === 'none' ? '' : 'none';
    });
    document.getElementById('pcatCancelCreate')?.addEventListener('click', () => {
        const f = document.getElementById('pcatCreateForm');
        if (f) f.style.display = 'none';
    });
    document.getElementById('pcatCreateBtn')?.addEventListener('click', async () => {
        const status = document.getElementById('pcatCreateStatus');
        const btn = document.getElementById('pcatCreateBtn');
        if (status) status.textContent = '';
        btn.disabled = true;
        try {
            await pcatsFetch('create', {
                juego_id:     CURRENT_JUEGO_ID,
                nombre:       document.getElementById('pcatNombre')?.value ?? '',
                slug:         document.getElementById('pcatSlug')?.value ?? '',
                icono:        document.getElementById('pcatIcono')?.value ?? '',
                color:        document.getElementById('pcatColor')?.value ?? '#22d3ee',
                color_texto:  document.getElementById('pcatColorTexto')?.value ?? '#ffffff',
                orden:        document.getElementById('pcatOrden')?.value ?? '0',
                activa:       '1',
                imagen:       document.getElementById('pcatImagen')?.files?.[0] ?? null,
                mostrar_menu: document.querySelector('input[name="pcatMostrarMenu"]:checked')?.value ?? 'icono_texto',
            });
            window.location.reload();
        } catch (e) {
            if (status) { status.textContent = e.message; }
        } finally {
            btn.disabled = false;
        }
    });

    document.querySelectorAll('.pcatEditBtn').forEach(btn => {
        btn.addEventListener('click', () => {
            const id = btn.dataset.id;
            const row = btn.closest('.pcatRow');
            const editRow = document.getElementById('pcatEdit_' + id);
            if (row) row.style.display = 'none';
            if (editRow) editRow.style.display = '';
        });
    });

    document.querySelectorAll('.pcatCancelEditBtn').forEach(btn => {
        btn.addEventListener('click', () => {
            const id = btn.dataset.id;
            const row = document.querySelector('.pcatRow[data-id="' + id + '"]');
            const editRow = document.getElementById('pcatEdit_' + id);
            if (editRow) editRow.style.display = 'none';
            if (row) row.style.display = '';
        });
    });

    document.querySelectorAll('.pcatSaveEditBtn').forEach(btn => {
        btn.addEventListener('click', async () => {
            const id = btn.dataset.id;
            const editRow = document.getElementById('pcatEdit_' + id);
            const status = editRow?.querySelector('.pcatEditStatus');
            if (status) status.textContent = '';
            btn.disabled = true;
            try {
                await pcatsFetch('update', {
                    id,
                    nombre:        editRow?.querySelector('.pcatEditNombre')?.value ?? '',
                    slug:          editRow?.querySelector('.pcatEditSlug')?.value ?? '',
                    icono:         editRow?.querySelector('.pcatEditIcono')?.value ?? '',
                    color:         editRow?.querySelector('.pcatEditColor')?.value ?? '#22d3ee',
                    color_texto:   editRow?.querySelector('.pcatEditColorTexto')?.value ?? '#ffffff',
                    orden:         editRow?.querySelector('.pcatEditOrden')?.value ?? '0',
                    imagen:        editRow?.querySelector('.pcatEditImagen')?.files?.[0] ?? null,
                    remove_imagen: editRow?.querySelector('.pcatEditRemoveImagen')?.value ?? '0',
                    mostrar_menu:  editRow?.querySelector('.pcatEditMostrarMenu:checked')?.value ?? 'icono_texto',
                });
                window.location.reload();
            } catch (e) {
                if (status) status.textContent = e.message;
                btn.disabled = false;
            }
        });
    });

    document.querySelectorAll('.pcatToggleActiveBtn').forEach(btn => {
        btn.addEventListener('click', async () => {
            const id = btn.dataset.id;
            const editRow = document.getElementById('pcatEdit_' + id);
            const nextActive = btn.dataset.active === '1' ? '0' : '1';
            btn.disabled = true;
            try {
                await pcatsFetch('update', {
                    id,
                    nombre:        editRow?.querySelector('.pcatEditNombre')?.value ?? '',
                    slug:          editRow?.querySelector('.pcatEditSlug')?.value ?? '',
                    icono:         editRow?.querySelector('.pcatEditIcono')?.value ?? '',
                    color:         editRow?.querySelector('.pcatEditColor')?.value ?? '#22d3ee',
                    color_texto:   editRow?.querySelector('.pcatEditColorTexto')?.value ?? '#ffffff',
                    orden:         editRow?.querySelector('.pcatEditOrden')?.value ?? '0',
                    mostrar_menu:  editRow?.querySelector('.pcatEditMostrarMenu:checked')?.value ?? 'icono_texto',
                    activa:        nextActive,
                });
                window.location.reload();
            } catch (e) {
                window.alert(e.message);
                btn.disabled = false;
            }
        });
    });

    document.querySelectorAll('.pcatDeleteBtn').forEach(btn => {
        btn.addEventListener('click', async () => {
            if (!window.confirm('¿Eliminar la categoría "' + (btn.dataset.nombre || '') + '"?')) return;
            btn.disabled = true;
            try {
                await pcatsFetch('delete', { id: btn.dataset.id });
                window.location.reload();
            } catch (e) {
                window.alert(e.message);
                btn.disabled = false;
            }
        });
    });

    document.getElementById('pcatImagen')?.addEventListener('change', function () {
        const preview    = document.getElementById('pcatImagenPreview');
        const previewImg = document.getElementById('pcatImagenPreviewImg');
        if (this.files?.[0] && preview && previewImg) {
            previewImg.src = URL.createObjectURL(this.files[0]);
            preview.style.display = '';
        } else if (preview) {
            preview.style.display = 'none';
        }
    });

    document.querySelectorAll('.pcatEditImagen').forEach(input => {
        input.addEventListener('change', function () {
            if (!this.files?.[0]) return;
            const editRow = this.closest('.pcatEditRow');
            if (!editRow) return;
            let thumb = editRow.querySelector('.pcatCurrentImgThumb');
            if (!thumb) {
                thumb = document.createElement('img');
                thumb.className = 'pcatCurrentImgThumb';
                thumb.style.cssText = 'max-height:40px;border-radius:5px;border:1px solid #1e3a5f;';
                this.parentElement.insertBefore(thumb, this);
            }
            thumb.src = URL.createObjectURL(this.files[0]);
        });
    });

    document.querySelectorAll('.pcatRemoveImgBtn').forEach(btn => {
        btn.addEventListener('click', function () {
            const editRow = this.closest('.pcatEditRow');
            if (!editRow) return;
            editRow.querySelector('.pcatEditRemoveImagen').value = '1';
            const thumb = editRow.querySelector('.pcatCurrentImgThumb');
            if (thumb) thumb.remove();
            this.remove();
        });
    });
}());

// ═══ FULLIMPULSO (SEGUIDORES) ════════════════════════════════════════════
(function () {
    function updateFiCostPreview(panel) {
        if (!panel) return;
        const select = panel.querySelector('.js-fi-service');
        const quantityInput = panel.querySelector('.js-fi-quantity');
        const preview = panel.querySelector('.js-fi-cost-preview');
        if (!select || !quantityInput || !preview) return;

        const option = select.selectedOptions && select.selectedOptions[0];
        const rate = option ? parseFloat(option.dataset.rate || '0') : 0;
        const quantity = parseInt(quantityInput.value || '0', 10);

        if (!option || !option.value || !rate || !quantity || quantity <= 0) {
            preview.textContent = '';
            return;
        }
        const cost = (rate * quantity) / 1000;
        preview.textContent = 'Costo estimado en FullImpulso: $' + cost.toFixed(4) + ' (referencia para fijar tu precio de venta).';
    }

    function updateFiCommentsHint(panel) {
        if (!panel) return;
        const select = panel.querySelector('.js-fi-service');
        const manualCheck = panel.querySelector('.js-fi-comments-manual');
        if (!select || !manualCheck) return;
        // Solo auto-marca cuando cambia el servicio seleccionado; si el admin
        // ya la marco/desmarco a mano, no la pisamos en cada input/change.
        if (select.dataset.lastAutoDetectedFor === select.value) return;
        select.dataset.lastAutoDetectedFor = select.value;
        const option = select.selectedOptions && select.selectedOptions[0];
        const type = option ? String(option.dataset.type || '') : '';
        manualCheck.checked = /custom comment/i.test(type);
    }

    document.querySelectorAll('.js-fi-toggle').forEach((checkbox) => {
        const panel = document.getElementById(checkbox.dataset.panel || '');
        const hiddenInput = document.getElementById(checkbox.dataset.hiddenInput || '');
        checkbox.addEventListener('change', () => {
            if (panel) panel.style.display = checkbox.checked ? '' : 'none';
            if (hiddenInput) hiddenInput.value = checkbox.checked ? '1' : '0';
            if (checkbox.checked && panel) {
                updateFiCostPreview(panel);
                updateFiCommentsHint(panel);
            }
        });
    });

    document.querySelectorAll('.js-fi-service, .js-fi-quantity').forEach((el) => {
        el.addEventListener('input', () => {
            const panel = el.closest('#fiPanel, #editFiPanel');
            updateFiCostPreview(panel);
            updateFiCommentsHint(panel);
        });
        el.addEventListener('change', () => {
            const panel = el.closest('#fiPanel, #editFiPanel');
            updateFiCostPreview(panel);
            updateFiCommentsHint(panel);
        });
    });

    document.querySelectorAll('#fiPanel, #editFiPanel').forEach((panel) => {
        // No se llama a updateFiCommentsHint aqui: el checkbox ya refleja el
        // valor guardado en BD (o queda sin marcar en un paquete nuevo), y
        // solo debe recalcularse cuando el admin cambia el servicio elegido.
        const select = panel.querySelector('.js-fi-service');
        if (select) select.dataset.lastAutoDetectedFor = select.value;
        if (panel.style.display !== 'none') {
            updateFiCostPreview(panel);
        }
    });
}());
</script>

<?php include '../includes/footer.php'; ?>