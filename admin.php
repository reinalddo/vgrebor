<?php
require_once __DIR__ . '/includes/tenant.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/slugify.php';
require_once __DIR__ . '/includes/store_config.php';
require_once __DIR__ . '/includes/win_points.php';
tenant_start_session();

function admin_allowed_roles(): array {
    return ['admin', 'empleado', 'influencer', 'root'];
}

function admin_is_root_role(string $role): bool {
    return $role === 'root';
}

function admin_has_full_access(string $role): bool {
    return in_array($role, ['admin', 'root'], true);
}

function admin_manageable_user_roles(): array {
    return [
        'usuario' => 'Usuario',
        'empleado' => 'Empleado',
        'influencer' => 'Influencer',
        'admin' => 'Admin',
    ];
}

function admin_default_section_for_role(string $role): string {
    if ($role === 'influencer') {
        return 'cupones';
    }

    return 'dashboard';
}

function admin_user_can_access_section(string $role, string $section): bool {
    if (admin_has_full_access($role)) {
        return true;
    }

    if ($role === 'empleado') {
        return in_array($section, ['dashboard', 'pedidos', 'movimientos', 'movimientos-binance'], true);
    }

    if ($role === 'influencer') {
        return in_array($section, ['cupones'], true);
    }

    return false;
}

$adminUser = auth_sync_session_user();
$adminUserRole = trim((string) ($adminUser['rol'] ?? ''));
if (!$adminUser || !in_array($adminUserRole, admin_allowed_roles(), true)) {
    header('Location: ' . app_path('/login.php'));
    exit();
}

// Definir la variable $seccion
$seccion = $_GET['seccion'] ?? 'dashboard';
if (isset($_SERVER['REQUEST_URI'])) {
    if (preg_match('#/admin/([a-zA-Z0-9_-]+)#', $_SERVER['REQUEST_URI'], $m)) {
        $seccion = $m[1];
    }
}

if (!admin_user_can_access_section($adminUserRole, $seccion)) {
    admin_set_flash('error', 'No tienes permisos para acceder a esa sección.');
    admin_redirect(admin_default_section_for_role($adminUserRole));
}

function normalize_coupon_code(string $value): string {
    return strtoupper(trim($value));
}

function is_valid_coupon_code(string $value): bool {
    return $value !== '' && preg_match('/^[A-Za-z0-9]+$/', $value) === 1;
}

function admin_coupons_win_points_enabled(): bool {
    return trim((string) store_config_get('win_points', '0')) === '1';
}

function admin_coupons_game_scope_enabled(): bool {
    return trim((string) store_config_get('cupon_x_juegos', '0')) === '1';
}

function admin_coupon_ensure_points_column(PDO $pdo): void {
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM cupones LIKE 'permitir_acumular_puntos'");
        $column = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
        if (!$column) {
            $pdo->exec("ALTER TABLE cupones ADD COLUMN permitir_acumular_puntos TINYINT(1) NOT NULL DEFAULT 1 AFTER activo");
        }
    } catch (Throwable $exception) {
    }
}

function admin_coupon_ensure_game_scope_column(PDO $pdo): void {
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM cupones LIKE 'juegos_restringidos_json'");
        $column = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
        if (!$column) {
            $pdo->exec("ALTER TABLE cupones ADD COLUMN juegos_restringidos_json LONGTEXT NULL AFTER permitir_acumular_puntos");
        }
    } catch (Throwable $exception) {
    }
}

function admin_coupon_game_ids_from_storage(?string $json): array {
    if (!is_string($json) || trim($json) === '') {
        return [];
    }

    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        return [];
    }

    $gameIds = [];
    foreach ($decoded as $gameId) {
        $normalizedId = (int) $gameId;
        if ($normalizedId > 0) {
            $gameIds[$normalizedId] = true;
        }
    }

    return array_map('intval', array_keys($gameIds));
}

function admin_coupon_game_ids_from_input(array $input, bool $featureEnabled): array {
    if (!$featureEnabled) {
        return [];
    }

    $rawValues = $input['juegos_restringidos'] ?? [];
    if (!is_array($rawValues)) {
        $rawValues = [$rawValues];
    }

    $gameIds = [];
    foreach ($rawValues as $rawValue) {
        $gameId = (int) $rawValue;
        if ($gameId > 0) {
            $gameIds[$gameId] = true;
        }
    }

    return array_map('intval', array_keys($gameIds));
}

function admin_coupon_game_ids_json(array $gameIds): ?string {
    if (empty($gameIds)) {
        return null;
    }

    $encoded = json_encode(array_values($gameIds), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return is_string($encoded) ? $encoded : null;
}

function admin_fetch_coupon_game_options(PDO $pdo): array {
    $stmt = $pdo->query('SELECT id, nombre FROM juegos ORDER BY nombre ASC, id ASC');
    $games = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    return is_array($games) ? $games : [];
}

function admin_coupon_game_labels(array $gameIds, array $gamesById): array {
    if (empty($gameIds)) {
        return ['Todos'];
    }

    $labels = [];
    foreach ($gameIds as $gameId) {
        $label = trim((string) ($gamesById[$gameId]['nombre'] ?? ('Juego #' . $gameId)));
        if ($label !== '') {
            $labels[] = $label;
        }
    }

    return empty($labels) ? ['Todos'] : array_values($labels);
}

function admin_coupon_game_ids_label(array $gameIds, array $gamesById): string {
    return implode(', ', admin_coupon_game_labels($gameIds, $gamesById));
}

function admin_coupon_points_allowed_from_input(array $input, bool $featureEnabled, ?array $currentCoupon = null): int {
    if ($featureEnabled) {
        return isset($input['permitir_acumular_puntos']) ? 1 : 0;
    }

    if ($currentCoupon !== null) {
        return !empty($currentCoupon['permitir_acumular_puntos']) ? 1 : 0;
    }

    return 0;
}

function admin_set_flash(string $type, string $message): void {
    $_SESSION['auth_flash'] = [
        'type' => $type,
        'message' => $message,
    ];
}

function admin_redirect(string $section, array $query = []): void {
    $target = app_path('/admin/' . $section);
    if (!empty($query)) {
        $target .= '?' . http_build_query($query);
    }
    header('Location: ' . $target);
    exit();
}

function admin_json_response(array $payload, int $statusCode = 200): void {
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit();
}

function admin_runtime_refresh_targets(): array {
    return [
        __DIR__ . '/game.php',
        __DIR__ . '/api/pedidos.php',
        __DIR__ . '/includes/paypal_pay.php',
        __DIR__ . '/includes/binance_pay.php',
        __DIR__ . '/includes/store_config.php',
        __DIR__ . '/includes/tenant.php',
    ];
}

function admin_refresh_runtime_code(): array {
    $targets = [];
    foreach (admin_runtime_refresh_targets() as $path) {
        $realPath = realpath($path);
        if (is_string($realPath) && $realPath !== '' && is_file($realPath)) {
            $targets[$realPath] = $realPath;
        }
    }

    $invalidatedFiles = [];
    foreach ($targets as $path) {
        clearstatcache(true, $path);
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($path, true);
        }
        $invalidatedFiles[] = basename($path);
    }

    $opcacheResetResult = null;
    if (function_exists('opcache_reset')) {
        $opcacheResetResult = @opcache_reset();
    }

    $message = 'Se solicitó el refresco del código publicado';
    if ($opcacheResetResult === true) {
        $message .= ' y OPcache fue reiniciado.';
    } elseif ($opcacheResetResult === false) {
        $message .= '. OPcache no permitió un reinicio global, pero se invalidaron los archivos clave.';
    } else {
        $message .= '. El servidor no expone opcache_reset(), pero se invalidaron los archivos clave.';
    }

    if (!empty($invalidatedFiles)) {
        $message .= ' Archivos: ' . implode(', ', $invalidatedFiles) . '.';
    }

    return [
        'ok' => !empty($invalidatedFiles),
        'message' => !empty($invalidatedFiles)
            ? $message
            : 'No se encontraron archivos PHP válidos para invalidar en este tenant.',
        'invalidated_files' => $invalidatedFiles,
        'opcache_reset' => $opcacheResetResult,
    ];
}

function admin_startup_popup_gallery_images(): array {
    return store_config_startup_popup_gallery_images((string) store_config_get('inicio_popup_galeria_imagenes', '[]'));
}

function admin_normalize_startup_popup_gallery_url($value): string {
    $url = trim((string) $value);
    if ($url === '') {
        return '';
    }

    if (preg_match('#^https?://#i', $url) === 1) {
        return filter_var($url, FILTER_VALIDATE_URL) ? $url : '';
    }

    if (str_starts_with($url, '/')) {
        return $url;
    }

    return '';
}

function admin_normalize_startup_popup_gallery_target($value): string {
    return trim((string) $value) === '_blank' ? '_blank' : '_self';
}

function admin_startup_popup_gallery_item_path($item): string {
    if (is_array($item)) {
        return trim((string) ($item['path'] ?? ''));
    }

    return trim((string) $item);
}

function admin_startup_popup_gallery_find_index_by_path(array $images, string $path): ?int {
    foreach ($images as $index => $image) {
        if (admin_startup_popup_gallery_item_path($image) === $path) {
            return $index;
        }
    }

    return null;
}

function admin_startup_popup_gallery_save(array $images): bool {
    $normalized = [];
    foreach ($images as $image) {
        $path = admin_startup_popup_gallery_item_path($image);
        if ($path === '') {
            continue;
        }

        if (isset($normalized[$path])) {
            continue;
        }

        $normalized[$path] = [
            'path' => $path,
            'link_url' => is_array($image) ? admin_normalize_startup_popup_gallery_url($image['link_url'] ?? '') : '',
            'link_target' => is_array($image)
                ? admin_normalize_startup_popup_gallery_target($image['link_target'] ?? '_self')
                : '_self',
        ];
    }

    $normalized = array_values($normalized);
    $json = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        return false;
    }

    return store_config_upsert('inicio_popup_galeria_imagenes', $json);
}

function admin_startup_popup_gallery_payload(array $images): array {
    $items = [];
    foreach (array_values($images) as $index => $image) {
        $path = admin_startup_popup_gallery_item_path($image);
        if ($path === '') {
            continue;
        }

        $linkUrl = is_array($image) ? admin_normalize_startup_popup_gallery_url($image['link_url'] ?? '') : '';
        $linkTarget = is_array($image)
            ? admin_normalize_startup_popup_gallery_target($image['link_target'] ?? '_self')
            : '_self';

        $items[] = [
            'path' => $path,
            'url' => $path,
            'name' => basename($path),
            'order' => $index + 1,
            'link_url' => $linkUrl,
            'link_target' => $linkTarget,
        ];
    }

    return [
        'ok' => true,
        'items' => $items,
        'count' => count($items),
    ];
}

function admin_display_value($value, string $fallback = '—'): string {
    $text = trim((string) $value);
    return $text !== '' ? $text : $fallback;
}

function admin_format_money($amount): string {
    return number_format((float) $amount, 2, '.', ',');
}

function admin_normalize_influencer_payment_filter($value): string {
    $filter = trim((string) $value);
    return in_array($filter, ['pendiente', 'pagado', 'todos'], true) ? $filter : 'pendiente';
}

function admin_normalize_date_filter($value): ?string {
    $date = trim((string) $value);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? $date : null;
}

function admin_user_filters_from_input(array $input): array {
    $role = trim((string) ($input['filtro_rol'] ?? ''));
    if ($role !== '' && !array_key_exists($role, admin_manageable_user_roles())) {
        $role = '';
    }

    $dateFrom = admin_normalize_date_filter($input['filtro_fecha_desde'] ?? null) ?? '';
    $dateTo = admin_normalize_date_filter($input['filtro_fecha_hasta'] ?? null) ?? '';

    if ($dateFrom !== '' && $dateTo !== '' && $dateFrom > $dateTo) {
        [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
    }

    return [
        'nombre' => trim((string) ($input['filtro_nombre'] ?? '')),
        'correo' => trim((string) ($input['filtro_correo'] ?? '')),
        'rol' => $role,
        'fecha_desde' => $dateFrom,
        'fecha_hasta' => $dateTo,
    ];
}

function admin_fetch_users(PDO $pdo, array $filters): array {
    $sql = "SELECT * FROM usuarios WHERE 1=1 AND COALESCE(rol, 'usuario') <> 'root'";
    $params = [];

    if ($filters['nombre'] !== '') {
        $sql .= ' AND nombre LIKE ?';
        $params[] = '%' . $filters['nombre'] . '%';
    }

    if ($filters['correo'] !== '') {
        $sql .= ' AND email LIKE ?';
        $params[] = '%' . $filters['correo'] . '%';
    }

    if ($filters['rol'] !== '') {
        $sql .= ' AND rol = ?';
        $params[] = $filters['rol'];
    }

    if ($filters['fecha_desde'] !== '') {
        $sql .= ' AND DATE(creado_en) >= ?';
        $params[] = $filters['fecha_desde'];
    }

    if ($filters['fecha_hasta'] !== '') {
        $sql .= ' AND DATE(creado_en) <= ?';
        $params[] = $filters['fecha_hasta'];
    }

    $sql .= ' ORDER BY creado_en DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function admin_display_phone($value): string {
    $phone = trim((string) $value);
    return $phone !== '' ? $phone : 'No disponible';
}

function admin_fetch_user_role(PDO $pdo, int $userId): string {
    if ($userId <= 0) {
        return '';
    }

    $stmt = $pdo->prepare('SELECT rol FROM usuarios WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return trim((string) ($row['rol'] ?? ''));
}

function admin_is_protected_user(PDO $pdo, int $userId): bool {
    if ($userId === 1) {
        return true;
    }

    return admin_fetch_user_role($pdo, $userId) === 'root';
}

function admin_extra_features_ensure_schema(): void {
    static $ensured = false;

    if ($ensured) {
        return;
    }

    $mysqli = store_config_db();
    $columns = [
        'mostrar_a_cliente' => "ALTER TABLE configuracion_general ADD COLUMN mostrar_a_cliente TINYINT(1) DEFAULT 0 NULL AFTER actualizado_en",
        'funcion_venta' => "ALTER TABLE configuracion_general ADD COLUMN funcion_venta VARCHAR(255) NULL AFTER mostrar_a_cliente",
        'descripcion_venta' => "ALTER TABLE configuracion_general ADD COLUMN descripcion_venta VARCHAR(255) NULL AFTER funcion_venta",
        'precio' => "ALTER TABLE configuracion_general ADD COLUMN precio INT NULL AFTER descripcion_venta",
        'comision_venta' => "ALTER TABLE configuracion_general ADD COLUMN comision_venta INT NULL AFTER precio",
    ];

    foreach ($columns as $columnName => $alterSql) {
        $columnNameEscaped = $mysqli->real_escape_string($columnName);
        $result = $mysqli->query("SHOW COLUMNS FROM configuracion_general LIKE '{$columnNameEscaped}'");
        if ($result instanceof mysqli_result) {
            $column = $result->fetch_assoc();
            $result->free();
            if (!$column) {
                $mysqli->query($alterSql);
            }
        }
    }

    $configDescriptions = store_config_descriptions();
    store_config_upsert('api_binance', store_config_get('api_binance', '0') === '1' ? '1' : '0', $configDescriptions['api_binance'] ?? 'Activa o desactiva la configuracion e integracion de Binance Pay via CoinPal para este tenant.');
    $featureName = 'API Binance Pay';
    $featureDescription = 'Activa pagos automaticos con Binance Pay via CoinPal para este tenant.';
    $stmt = $mysqli->prepare(
        "UPDATE configuracion_general
         SET mostrar_a_cliente = 1,
             funcion_venta = COALESCE(NULLIF(funcion_venta, ''), ?),
             descripcion_venta = COALESCE(NULLIF(descripcion_venta, ''), ?),
             precio = COALESCE(precio, 0),
             comision_venta = COALESCE(comision_venta, 0)
         WHERE clave = 'api_binance'"
    );
    if ($stmt) {
        $stmt->bind_param('ss', $featureName, $featureDescription);
        $stmt->execute();
        $stmt->close();
    }

    store_config_upsert(
        'api_binance_pagonorte',
        store_config_get('api_binance_pagonorte', '0') === '1' ? '1' : '0',
        $configDescriptions['api_binance_pagonorte'] ?? 'Api para verificar movimientos en Binance'
    );
    $binancePagonorteFeatureName = 'Binance';
    $binancePagonorteFeatureDescription = 'Api para verificar movimientos en Binance';
    $binancePagonorteStmt = $mysqli->prepare(
        "UPDATE configuracion_general
         SET mostrar_a_cliente = 1,
             funcion_venta = COALESCE(NULLIF(funcion_venta, ''), ?),
             descripcion_venta = COALESCE(NULLIF(descripcion_venta, ''), ?),
             precio = COALESCE(precio, 70),
             comision_venta = COALESCE(comision_venta, 0)
         WHERE clave = 'api_binance_pagonorte'"
    );
    if ($binancePagonorteStmt) {
        $binancePagonorteStmt->bind_param('ss', $binancePagonorteFeatureName, $binancePagonorteFeatureDescription);
        $binancePagonorteStmt->execute();
        $binancePagonorteStmt->close();
    }

    store_config_upsert('pago_paypal', store_config_get('pago_paypal', '0') === '1' ? '1' : '0', $configDescriptions['pago_paypal'] ?? 'Activa o desactiva la configuración base de PayPal Checkout para este tenant.');
    $paypalFeatureName = 'Pasarela de Pago Paypal';
    $paypalFeatureDescription = 'Activa cobros automáticos con PayPal Checkout, webhook y recarga automática para este tenant.';
    $paypalStmt = $mysqli->prepare(
        "UPDATE configuracion_general
         SET mostrar_a_cliente = 1,
             funcion_venta = COALESCE(NULLIF(funcion_venta, ''), ?),
             descripcion_venta = COALESCE(NULLIF(descripcion_venta, ''), ?),
             precio = COALESCE(precio, 30),
             comision_venta = COALESCE(comision_venta, 0)
         WHERE clave = 'pago_paypal'"
    );
    if ($paypalStmt) {
        $paypalStmt->bind_param('ss', $paypalFeatureName, $paypalFeatureDescription);
        $paypalStmt->execute();
        $paypalStmt->close();
    }

    store_config_upsert('api_discord', store_config_get('api_discord', '0') === '1' ? '1' : '0', $configDescriptions['api_discord'] ?? 'Activa o desactiva la integración base de API Discord para enviar comandos de consulta y pruebas de recarga desde este tenant.');
    $discordFeatureName = 'API Discord';
    $discordFeatureDescription = 'Automatiza pruebas webhook y futuras integraciones de comandos de Discord para consultas de precios y recargas controladas.';
    $discordStmt = $mysqli->prepare(
        "UPDATE configuracion_general
         SET mostrar_a_cliente = 1,
             funcion_venta = COALESCE(NULLIF(funcion_venta, ''), ?),
             descripcion_venta = COALESCE(NULLIF(descripcion_venta, ''), ?),
             precio = COALESCE(precio, 60),
             comision_venta = COALESCE(comision_venta, 0)
         WHERE clave = 'api_discord'"
    );
    if ($discordStmt) {
        $discordStmt->bind_param('ss', $discordFeatureName, $discordFeatureDescription);
        $discordStmt->execute();
        $discordStmt->close();
    }

    store_config_upsert(
        'union_apis_discord_giftven',
        store_config_get('union_apis_discord_giftven', '0') === '1' ? '1' : '0',
        $configDescriptions['union_apis_discord_giftven'] ?? 'Permite combinar paquetes de TiendaGiftVen y Discord dentro del mismo juego, moviendo la elección del proveedor al paquete.'
    );
    $unionFeatureName = 'Union APIs Discord + GiftVen';
    $unionFeatureDescription = 'Permite vender en un mismo juego paquetes de TiendaGiftVen y Discord, eligiendo el proveedor final desde cada paquete.';
    $unionStmt = $mysqli->prepare(
        "UPDATE configuracion_general
         SET mostrar_a_cliente = 1,
             funcion_venta = COALESCE(NULLIF(funcion_venta, ''), ?),
             descripcion_venta = COALESCE(NULLIF(descripcion_venta, ''), ?),
             precio = COALESCE(precio, 30),
             comision_venta = COALESCE(comision_venta, 0)
         WHERE clave = 'union_apis_discord_giftven'"
    );
    if ($unionStmt) {
        $unionStmt->bind_param('ss', $unionFeatureName, $unionFeatureDescription);
        $unionStmt->execute();
        $unionStmt->close();
    }

    $ensured = true;
}

function admin_extra_feature_is_active($value): bool {
    $normalized = strtolower(trim((string) $value));
    return !in_array($normalized, ['', '0', 'false', 'off', 'no', 'null'], true);
}

function admin_humanize_extra_feature_label($value): string {
    $label = trim((string) $value);
    if ($label === '') {
        return 'Funcion extra';
    }

    $normalized = preg_replace('/[_-]+/', ' ', $label) ?? $label;
    $normalized = trim(preg_replace('/\s+/', ' ', $normalized) ?? $normalized);
    if ($normalized === '') {
        return 'Funcion extra';
    }

    return ucwords($normalized);
}

function admin_extra_features_whatsapp_config_key(): string {
    return 'funciones_extra_whatsapp_numero';
}

function admin_normalize_whatsapp_number($value): string {
    $normalized = preg_replace('/\D+/', '', (string) $value);
    return trim((string) $normalized);
}

function admin_extra_features_whatsapp_number(): string {
    $moduleSpecificNumber = admin_normalize_whatsapp_number(store_config_get(admin_extra_features_whatsapp_config_key(), ''));
    if ($moduleSpecificNumber !== '') {
        return $moduleSpecificNumber;
    }

    return admin_normalize_whatsapp_number(store_config_get('whatsapp', ''));
}

function admin_build_extra_feature_whatsapp_link(string $whatsappNumber, string $featureName): string {
    $normalizedNumber = admin_normalize_whatsapp_number($whatsappNumber);
    if ($normalizedNumber === '') {
        return '';
    }

    $message = 'Hola, quisiera la función "' . trim($featureName) . '" en mi página web';
    return 'https://wa.me/' . rawurlencode($normalizedNumber) . '?text=' . rawurlencode($message);
}

function admin_render_extra_features_whatsapp_settings(string $whatsappNumber): string {
    ob_start();
    echo '<div class="mb-4" style="border-radius:20px; border:1px solid rgba(34,211,238,0.24); background:linear-gradient(180deg,rgba(15,23,42,0.94),rgba(24,31,42,0.9)); box-shadow:0 0 24px rgba(34,211,238,0.08); padding:1.1rem;">';
    echo '<div class="d-flex flex-column flex-lg-row align-items-lg-end justify-content-between gap-3">';
    echo '<div style="max-width:720px;">';
    echo '<h3 class="h5 mb-2" style="color:#22d3ee;">Número de WhatsApp para vender funciones</h3>';
    echo '<p class="mb-0" style="color:#c9f9ff;">Este número será usado por el botón <strong>Quiero esta función</strong> que verán los usuarios admin. Si lo dejas vacío, el módulo usará el WhatsApp general configurado en Redes Sociales.</p>';
    echo '</div>';
    echo '<form method="POST" class="d-flex flex-column flex-md-row align-items-stretch gap-2 w-100" style="max-width:520px;">';
    echo '<input type="hidden" name="guardar_funciones_extra_whatsapp" value="1">';
    echo '<input type="text" name="funciones_extra_whatsapp_numero" value="' . htmlspecialchars($whatsappNumber, ENT_QUOTES, 'UTF-8') . '" placeholder="Ej. 584141234567" class="form-control" style="background:#222c3a; color:#00fff7; border:1px solid #00fff7; min-height:46px;">';
    echo '<button type="submit" class="btn btn-info" style="min-width:180px; min-height:46px; background:linear-gradient(90deg,#00fff7 0%,#22d3ee 100%); color:#0f172a; border:none; font-weight:800;">Guardar número</button>';
    echo '</form>';
    echo '</div>';
    echo '</div>';
    return (string) ob_get_clean();
}

function admin_fetch_extra_features(): array {
    admin_extra_features_ensure_schema();

    $mysqli = store_config_db();
    $sql = "SELECT id, clave, valor, descripcion, mostrar_a_cliente, funcion_venta, descripcion_venta, precio, comision_venta
            FROM configuracion_general
            WHERE COALESCE(mostrar_a_cliente, 0) = 1
            ORDER BY COALESCE(NULLIF(TRIM(funcion_venta), ''), clave) ASC, id ASC";
    $result = $mysqli->query($sql);
    $features = [];

    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            $basePrice = max(0, (int) ($row['precio'] ?? 0));
            $commission = max(0, (int) ($row['comision_venta'] ?? 0));
            $row['precio'] = $basePrice;
            $row['comision_venta'] = $commission;
            $row['precio_total'] = $basePrice + $commission;
            $row['activa'] = admin_extra_feature_is_active($row['valor'] ?? '0');
            $features[] = $row;
        }
        $result->free();
    }

    return $features;
}

function admin_update_extra_feature(int $featureId, int $basePrice, int $commission, string $value): bool {
    admin_extra_features_ensure_schema();

    $mysqli = store_config_db();
    $stmt = $mysqli->prepare('UPDATE configuracion_general SET valor = ?, precio = ?, comision_venta = ? WHERE id = ? AND COALESCE(mostrar_a_cliente, 0) = 1');
    if (!$stmt) {
        return false;
    }

    $normalizedValue = admin_extra_feature_is_active($value) ? '1' : '0';
    $normalizedBasePrice = max(0, $basePrice);
    $normalizedCommission = max(0, $commission);
    $stmt->bind_param('siii', $normalizedValue, $normalizedBasePrice, $normalizedCommission, $featureId);
    $executed = $stmt->execute();
    $stmt->close();

    return $executed;
}

function admin_fetch_extra_feature_by_id(int $featureId): ?array {
    if ($featureId <= 0) {
        return null;
    }

    admin_extra_features_ensure_schema();

    $mysqli = store_config_db();
    $stmt = $mysqli->prepare('SELECT id, clave, valor FROM configuracion_general WHERE id = ? LIMIT 1');
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('i', $featureId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
    $stmt->close();

    return is_array($row) ? $row : null;
}

function admin_validate_extra_feature_update(array $feature, string $value): ?string {
    $featureKey = trim((string) ($feature['clave'] ?? ''));
    if ($featureKey === '') {
        return 'La función seleccionada no es válida.';
    }

    $nextIsActive = admin_extra_feature_is_active($value);
    $discordEnabled = admin_extra_feature_is_active(store_config_get('api_discord', '0'));
    $unionEnabled = admin_extra_feature_is_active(store_config_get('union_apis_discord_giftven', '0'));

    if ($featureKey === 'union_apis_discord_giftven' && $nextIsActive && !$discordEnabled) {
        return 'Primero debes activar API Discord antes de habilitar Union APIs Discord + GiftVen.';
    }

    if ($featureKey === 'api_discord' && !$nextIsActive && $unionEnabled) {
        return 'No puedes desactivar API Discord mientras Union APIs Discord + GiftVen siga activa.';
    }

    return null;
}

function admin_render_extra_features_table(array $features, bool $isRootViewer, string $whatsappNumber = ''): string {
    ob_start();

    if (count($features) === 0) {
        echo '<div class="alert alert-info" style="background:#10141a; color:#c9f9ff; border:1px solid #00fff7;">No hay funciones extra disponibles para este tenant en este momento.</div>';
        return (string) ob_get_clean();
    }

    echo '<style>';
    echo '.extra-feature-filters{display:flex;flex-wrap:wrap;gap:0.75rem;margin:0 0 1rem;}';
    echo '.extra-feature-filter-btn{display:inline-flex;align-items:center;justify-content:center;gap:0.55rem;min-height:46px;padding:0.8rem 1rem;border-radius:999px;border:1px solid rgba(34,211,238,0.24);background:rgba(15,23,42,0.72);color:#c9f9ff;font-weight:800;letter-spacing:0.02em;cursor:pointer;transition:transform 0.18s ease, box-shadow 0.18s ease, border-color 0.18s ease, background 0.18s ease;}';
    echo '.extra-feature-filter-btn:hover{transform:translateY(-1px);border-color:rgba(34,211,238,0.52);box-shadow:0 0 18px rgba(34,211,238,0.12);}';
    echo '.extra-feature-filter-btn.is-active{background:linear-gradient(90deg, rgba(34,211,238,0.22), rgba(45,212,191,0.18));border-color:rgba(34,211,238,0.62);color:#ffffff;box-shadow:0 0 18px rgba(34,211,238,0.16);}';
    echo '.extra-feature-filter-btn svg{width:18px;height:18px;fill:currentColor;flex:0 0 18px;}';
    echo '.extra-features-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:1rem;}';
    echo '.extra-feature-card{height:100%;border-radius:24px;border:1px solid rgba(34,211,238,0.22);background:linear-gradient(180deg,rgba(8,15,25,0.98),rgba(17,24,39,0.94));box-shadow:0 0 26px rgba(34,211,238,0.08), inset 0 0 0 1px rgba(34,211,238,0.04);display:flex;flex-direction:column;overflow:hidden;position:relative;}';
    echo '.extra-feature-card::before{content:"";position:absolute;inset:0 auto auto 0;width:100%;height:140px;background:radial-gradient(circle at top left, rgba(34,211,238,0.18), transparent 58%), linear-gradient(135deg, rgba(34,211,238,0.12), rgba(15,23,42,0));pointer-events:none;}';
    echo '.extra-feature-card.is-hidden{display:none;}';
    echo '.extra-feature-header{position:relative;padding:1.15rem 1.2rem 0.85rem;border-bottom:1px solid rgba(34,211,238,0.12);background:linear-gradient(180deg, rgba(15,23,42,0.78), rgba(15,23,42,0.38));}';
    echo '.extra-feature-body{position:relative;padding:1rem 1.2rem 1.1rem;display:flex;flex-direction:column;gap:1rem;flex:1 1 auto;}';
    echo '.extra-feature-footer{position:relative;margin-top:auto;padding:1rem 1.2rem 1.2rem;border-top:1px solid rgba(34,211,238,0.1);background:linear-gradient(180deg, rgba(15,23,42,0.18), rgba(2,6,23,0.42));display:flex;flex-direction:column;gap:0.75rem;}';
    echo '.extra-feature-top{display:flex;align-items:flex-start;justify-content:space-between;gap:0.85rem;flex-wrap:wrap;}';
    echo '.extra-feature-key{display:inline-flex;align-items:center;gap:0.35rem;border-radius:999px;border:1px solid rgba(34,211,238,0.35);background:rgba(34,211,238,0.08);padding:0.3rem 0.7rem;color:#67e8f9;font-size:0.75rem;font-weight:700;letter-spacing:0.04em;word-break:break-word;}';
    echo '.extra-feature-title{margin:0;color:#f8fafc;font-size:1.22rem;font-weight:800;line-height:1.2;}';
    echo '.extra-feature-hook{margin:0.35rem 0 0;color:#7dd3fc;font-size:0.84rem;font-weight:700;letter-spacing:0.03em;text-transform:uppercase;}';
    echo '.extra-feature-description{margin:0;color:#c9f9ff;font-size:0.98rem;line-height:1.58;}';
    echo '.extra-feature-price-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:0.75rem;}';
    echo '.extra-feature-price-box{border-radius:16px;border:1px solid rgba(34,211,238,0.16);background:linear-gradient(180deg, rgba(8,15,25,0.9), rgba(15,23,42,0.7));padding:0.8rem 0.9rem;box-shadow:inset 0 0 0 1px rgba(34,211,238,0.03);}';
    echo '.extra-feature-price-label{display:block;color:#7dd3fc;font-size:0.75rem;font-weight:700;letter-spacing:0.06em;text-transform:uppercase;margin-bottom:0.35rem;}';
    echo '.extra-feature-price-value{display:block;color:#fde68a;font-size:1.08rem;font-weight:800;}';
    echo '.extra-feature-status{display:inline-flex;align-items:center;justify-content:center;border-radius:999px;padding:0.3rem 0.7rem;font-size:0.78rem;font-weight:700;}';
    echo '.extra-feature-status svg{width:17px;height:17px;fill:currentColor;flex:0 0 17px;}';
    echo '.extra-feature-status--owned{gap:0.45rem;padding:0.42rem 0.82rem;background:linear-gradient(90deg, rgba(16,185,129,0.24), rgba(5,150,105,0.24));color:#a7f3d0;border:1px solid rgba(16,185,129,0.48);box-shadow:0 0 18px rgba(16,185,129,0.16);}';
    echo '.extra-feature-status--available{gap:0.45rem;padding:0.42rem 0.82rem;background:linear-gradient(90deg, rgba(251,191,36,0.18), rgba(249,115,22,0.18));color:#fde68a;border:1px solid rgba(251,191,36,0.42);box-shadow:0 0 18px rgba(251,191,36,0.12);}';
    echo '.extra-feature-form-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:0.85rem;}';
    echo '.extra-feature-field{display:flex;flex-direction:column;gap:0.4rem;}';
    echo '.extra-feature-field label{color:#67e8f9;font-size:0.82rem;font-weight:700;letter-spacing:0.04em;}';
    echo '.extra-feature-action{margin-top:auto;display:flex;flex-direction:column;gap:0.7rem;}';
    echo '.extra-feature-submit{min-height:44px;border:none;border-radius:14px;background:linear-gradient(90deg,#00fff7 0%,#22d3ee 100%);color:#0f172a;font-weight:800;box-shadow:0 0 18px rgba(0,255,247,0.28);}';
    echo '.extra-feature-submit:hover{filter:brightness(1.04);}';
    echo '.extra-feature-whatsapp{display:inline-flex;align-items:center;justify-content:center;gap:0.65rem;min-height:46px;padding:0.85rem 1rem;border-radius:14px;background:linear-gradient(90deg,#25d366 0%,#1fb154 100%);color:#ffffff;font-weight:800;text-decoration:none;box-shadow:0 0 18px rgba(37,211,102,0.22);}';
    echo '.extra-feature-whatsapp:hover{filter:brightness(1.04);color:#ffffff;}';
    echo '.extra-feature-whatsapp.is-disabled{background:linear-gradient(90deg,#334155 0%,#475569 100%);color:#e2e8f0;box-shadow:none;pointer-events:none;}';
    echo '.extra-feature-whatsapp svg{width:18px;height:18px;flex:0 0 18px;fill:currentColor;}';
    echo '.extra-feature-note{margin:0;color:#93c5fd;font-size:0.88rem;line-height:1.5;}';
    echo '.extra-feature-footer-copy{margin:0;color:#cbd5e1;font-size:0.88rem;line-height:1.55;}';
    echo '.extra-feature-footer-copy.is-hot{color:#bbf7d0;}';
    echo '@media (max-width: 767px){.extra-feature-card{padding:1rem;}.extra-feature-title{font-size:1.08rem;}}';
    echo '</style>';
    if ($isRootViewer) {
        echo '<div class="extra-feature-filters" data-extra-feature-filters="1">';
        echo '<button type="button" class="extra-feature-filter-btn is-active" data-extra-filter="all">';
        echo '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 5a2 2 0 0 1 2-2h3.5a2 2 0 0 1 1.73 1H19a2 2 0 0 1 2 2v2H3V5zm0 5h18v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-9zm6 3v2h6v-2H9z"/></svg>';
        echo '<span>Todas las funciones</span>';
        echo '</button>';
        echo '<button type="button" class="extra-feature-filter-btn" data-extra-filter="active">';
        echo '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 16.17 4.83 12 3.41 13.41 9 19l12-12-1.41-1.41z"/></svg>';
        echo '<span>Activas</span>';
        echo '</button>';
        echo '<button type="button" class="extra-feature-filter-btn" data-extra-filter="inactive">';
        echo '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2a10 10 0 1 0 10 10A10 10 0 0 0 12 2zm5 11H7v-2h10z"/></svg>';
        echo '<span>Desactivadas</span>';
        echo '</button>';
        echo '</div>';
    } else {
        echo '<div class="extra-feature-filters" data-extra-feature-filters="1">';
        echo '<button type="button" class="extra-feature-filter-btn is-active" data-extra-filter="all">';
        echo '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 5a2 2 0 0 1 2-2h3.5a2 2 0 0 1 1.73 1H19a2 2 0 0 1 2 2v2H3V5zm0 5h18v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-9zm6 3v2h6v-2H9z"/></svg>';
        echo '<span>Todas las funciones</span>';
        echo '</button>';
        echo '<button type="button" class="extra-feature-filter-btn" data-extra-filter="owned">';
        echo '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 16.17 4.83 12 3.41 13.41 9 19l12-12-1.41-1.41z"/></svg>';
        echo '<span>Compradas</span>';
        echo '</button>';
        echo '<button type="button" class="extra-feature-filter-btn" data-extra-filter="available">';
        echo '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 18c-1.1 0-1.99.9-1.99 2S5.9 22 7 22s2-.9 2-2-.9-2-2-2zm10 0c-1.1 0-1.99.9-1.99 2S15.9 22 17 22s2-.9 2-2-.9-2-2-2zM7.17 14h9.95c.75 0 1.41-.41 1.75-1.03l3.58-6.49A1 1 0 0 0 21.58 5H6.21l-.94-2H2v2h2l3.6 7.59-1.35 2.44A2 2 0 0 0 8 18h12v-2H8l1.17-2z"/></svg>';
        echo '<span>Disponibles para la compra</span>';
        echo '</button>';
        echo '</div>';
    }
    echo '<div class="extra-features-grid">';

    foreach ($features as $feature) {
        $featureId = (int) ($feature['id'] ?? 0);
        $formId = 'extra-feature-form-' . $featureId;
        $featureKey = trim((string) ($feature['clave'] ?? ''));
        $configuredFeatureName = trim((string) ($feature['funcion_venta'] ?? ''));
        if ($configuredFeatureName === '' || $configuredFeatureName === $featureKey) {
            $featureName = admin_humanize_extra_feature_label($featureKey);
        } else {
            $featureName = $configuredFeatureName;
        }
        $featureDescription = admin_display_value($feature['descripcion_venta'] ?? '', (string) ($feature['descripcion'] ?? 'Sin descripción disponible'));
        $statusText = !empty($feature['activa']) ? 'Activa' : 'Desactivada';
        $statusStyle = !empty($feature['activa'])
            ? 'background:rgba(52,211,153,0.14); color:#86efac; border:1px solid rgba(52,211,153,0.45);'
            : 'background:rgba(248,113,113,0.14); color:#fca5a5; border:1px solid rgba(248,113,113,0.45);';
        $basePrice = (int) ($feature['precio'] ?? 0);
        $commission = (int) ($feature['comision_venta'] ?? 0);
        $totalPrice = (int) ($feature['precio_total'] ?? 0);
        $whatsappLink = admin_build_extra_feature_whatsapp_link($whatsappNumber, $featureName);
        $featureOwned = !empty($feature['activa']);
        $showCustomerCta = !$isRootViewer && !$featureOwned && $whatsappLink !== '';

        $featureState = $isRootViewer
            ? ($featureOwned ? 'active' : 'inactive')
            : ($featureOwned ? 'owned' : 'available');

        echo '<section class="extra-feature-card' . (!$isRootViewer ? ' extra-feature-card--admin' : '') . '" data-extra-feature-state="' . $featureState . '">';
        echo '<div class="extra-feature-header">';
        echo '<div class="extra-feature-top">';
        echo '<div style="flex:1 1 220px; min-width:0;">';
        if ($isRootViewer) {
            echo '<span class="extra-feature-key">' . htmlspecialchars($featureKey) . '</span>';
        }
        echo '<h3 class="extra-feature-title mt-3">' . htmlspecialchars($featureName) . '</h3>';
        if (!$isRootViewer) {
            echo '<p class="extra-feature-hook">Mejora premium para tu tienda</p>';
        }
        echo '</div>';
        if ($isRootViewer) {
            echo '<span class="extra-feature-status" style="' . $statusStyle . '">' . htmlspecialchars($statusText) . '</span>';
        } elseif ($featureOwned) {
            echo '<span class="extra-feature-status extra-feature-status--owned">';
            echo '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2a10 10 0 1 0 10 10A10 10 0 0 0 12 2zm-1.1 14.3-3.2-3.2 1.4-1.4 1.8 1.8 4.8-4.8 1.4 1.4z"/></svg>';
            echo '<span>Ya activa</span>';
            echo '</span>';
        } else {
            echo '<span class="extra-feature-status extra-feature-status--available">';
            echo '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 18c-1.1 0-1.99.9-1.99 2S5.9 22 7 22s2-.9 2-2-.9-2-2-2zm10 0c-1.1 0-1.99.9-1.99 2S15.9 22 17 22s2-.9 2-2-.9-2-2-2zM7.17 14h9.95c.75 0 1.41-.41 1.75-1.03l3.58-6.49A1 1 0 0 0 21.58 5H6.21l-.94-2H2v2h2l3.6 7.59-1.35 2.44A2 2 0 0 0 8 18h12v-2H8l1.17-2z"/></svg>';
            echo '<span>Disponible para comprar</span>';
            echo '</span>';
        }
        echo '</div>';
        echo '</div>';
        echo '<div class="extra-feature-body">';
        echo '<p class="extra-feature-description">' . htmlspecialchars($featureDescription) . '</p>';
        echo '<div class="extra-feature-price-grid">';
        if ($isRootViewer) {
            echo '<div class="extra-feature-price-box">';
            echo '<span class="extra-feature-price-label">Tu ganancia</span>';
            echo '<span class="extra-feature-price-value" data-extra-price-base-display="1">USD ' . htmlspecialchars(admin_format_money($basePrice)) . '</span>';
            echo '</div>';
            echo '<div class="extra-feature-price-box">';
            echo '<span class="extra-feature-price-label">Comisión vendedor</span>';
            echo '<span class="extra-feature-price-value" style="color:#67e8f9;" data-extra-price-commission-display="1">USD ' . htmlspecialchars(admin_format_money($commission)) . '</span>';
            echo '</div>';
        }
        echo '<div class="extra-feature-price-box">';
        echo '<span class="extra-feature-price-label">' . ($isRootViewer ? 'Precio mostrado' : 'Precio') . '</span>';
        echo '<span class="extra-feature-price-value" data-extra-price-total="1">USD ' . htmlspecialchars(admin_format_money($totalPrice)) . '</span>';
        echo '</div>';
        echo '</div>';
        echo '</div>';
        if ($isRootViewer) {
            echo '<div class="extra-feature-footer">';
            echo '<div class="extra-feature-action">';
            echo '<form id="' . htmlspecialchars($formId) . '" method="POST" class="d-flex flex-column gap-3 mt-1" data-extra-feature-form="1">';
            echo '<input type="hidden" name="guardar_funcion_extra" value="1">';
            echo '<input type="hidden" name="feature_id" value="' . $featureId . '">';
            echo '<div class="extra-feature-form-grid">';
            echo '<div class="extra-feature-field">';
            echo '<label for="extra-precio-' . $featureId . '">Tu ganancia en USD</label>';
            echo '<input id="extra-precio-' . $featureId . '" type="number" min="0" step="1" name="precio" value="' . htmlspecialchars((string) $basePrice) . '" class="form-control" style="background:#222c3a; color:#00fff7; border:1px solid #00fff7;" data-extra-price-base="1">';
            echo '</div>';
            echo '<div class="extra-feature-field">';
            echo '<label for="extra-comision-' . $featureId . '">Comisión vendedor USD</label>';
            echo '<input id="extra-comision-' . $featureId . '" type="number" min="0" step="1" name="comision_venta" value="' . htmlspecialchars((string) $commission) . '" class="form-control" style="background:#222c3a; color:#00fff7; border:1px solid #00fff7;" data-extra-price-commission="1">';
            echo '</div>';
            echo '</div>';
            echo '<label class="form-check d-flex align-items-center gap-2" style="color:#c9f9ff;">';
            echo '<input class="form-check-input" type="checkbox" name="activar_funcion" value="1" ' . (!empty($feature['activa']) ? 'checked' : '') . '>';
            echo '<span>Activar módulo</span>';
            echo '</label>';
            echo '<button type="submit" class="extra-feature-submit">Guardar cambios</button>';
            echo '</form>';
            echo '</div>';
            echo '</div>';
        } else {
            echo '<div class="extra-feature-footer">';
            echo '<div class="extra-feature-action">';
            if ($showCustomerCta) {
                echo '<p class="extra-feature-footer-copy is-hot">Actívala hoy y empieza a aprovechar esta función premium en tu tienda.</p>';
                echo '<a href="' . htmlspecialchars($whatsappLink, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener noreferrer" class="extra-feature-whatsapp">';
                echo '<svg viewBox="0 0 32 32" aria-hidden="true"><path d="M19.11 17.21c-.27-.13-1.58-.78-1.82-.87-.24-.09-.41-.13-.58.14-.17.27-.67.87-.82 1.05-.15.18-.3.2-.56.07-.27-.13-1.12-.41-2.13-1.31-.79-.7-1.32-1.56-1.47-1.83-.15-.27-.02-.41.11-.54.12-.12.27-.3.41-.45.13-.15.18-.26.27-.44.09-.18.05-.34-.02-.47-.07-.13-.58-1.39-.8-1.91-.21-.5-.43-.43-.58-.44h-.5c-.18 0-.47.07-.72.34s-.94.92-.94 2.24.96 2.59 1.09 2.77c.13.18 1.88 2.86 4.55 4.01.64.28 1.14.45 1.53.58.64.2 1.22.17 1.67.1.51-.08 1.58-.65 1.8-1.28.22-.63.22-1.18.15-1.28-.07-.1-.24-.16-.5-.29zm-3.17 10.79h-.01a13.6 13.6 0 0 1-6.92-1.89L4 27.41l1.33-4.85A13.57 13.57 0 0 1 2.4 13.6C2.4 6.56 8.14.83 15.19.83c3.41 0 6.62 1.33 9.03 3.74a12.7 12.7 0 0 1 3.74 9.03c0 7.04-5.73 12.78-12.78 12.78zm10.87-23.65A15.22 15.22 0 0 0 15.19 0C6.81 0 0 6.81 0 15.18c0 2.68.7 5.3 2.02 7.61L0 32l9.47-1.97a15.13 15.13 0 0 0 5.72 1.14h.01C23.57 31.17 32 22.74 32 15.18c0-4.05-1.58-7.85-4.39-10.83z"/></svg>';
                echo '<span>Quiero esta función</span>';
                echo '</a>';
            } elseif ($featureOwned) {
                echo '<p class="extra-feature-footer-copy is-hot">Ya tienes Activa esta función.</p>';
            } elseif ($whatsappLink === '') {
                echo '<p class="extra-feature-footer-copy">Esta función está disponible para la compra. Configura primero el número de atención para habilitar el contacto rápido por WhatsApp.</p>';
            } else {
                echo '<p class="extra-feature-footer-copy">Esta función está disponible para la compra. Escríbenos y la activamos para tu tienda.</p>';
            }
            echo '</div>';
            echo '</div>';
        }
        echo '</section>';
    }

    echo '</div>';
    echo '<script>';
    echo '(function(){';
    echo 'var filterButtons=document.querySelectorAll("[data-extra-filter]");';
    echo 'var cards=document.querySelectorAll("[data-extra-feature-state]");';
    echo 'var matchesFilter=function(filter,state){';
    echo 'if(filter==="all"){return true;}';
    echo 'if(filter==="owned"){return state==="owned";}';
    echo 'if(filter==="available"){return state==="available";}';
    echo 'if(filter==="active"){return state==="active";}';
    echo 'if(filter==="inactive"){return state==="inactive";}';
    echo 'return true;';
    echo '};';
    echo 'if(filterButtons.length&&cards.length){';
    echo 'filterButtons.forEach(function(button){';
    echo 'button.addEventListener("click",function(){';
    echo 'var filter=button.getAttribute("data-extra-filter")||"all";';
    echo 'filterButtons.forEach(function(item){item.classList.toggle("is-active", item===button);});';
    echo 'cards.forEach(function(card){';
    echo 'var state=card.getAttribute("data-extra-feature-state")||"available";';
    echo 'card.classList.toggle("is-hidden", !matchesFilter(filter,state));';
    echo '});';
    echo '});';
    echo '});';
    echo '}';
    if ($isRootViewer) {
        echo 'document.querySelectorAll("[data-extra-feature-form=\"1\"]").forEach(function(form){';
        echo 'var baseInput=form.querySelector("[data-extra-price-base=\"1\"]");';
        echo 'var commissionInput=form.querySelector("[data-extra-price-commission=\"1\"]");';
        echo 'var card=form.closest(".extra-feature-card");';
        echo 'if(!baseInput||!commissionInput||!card){return;}';
        echo 'var baseDisplay=card.querySelector("[data-extra-price-base-display=\"1\"]");';
        echo 'var commissionDisplay=card.querySelector("[data-extra-price-commission-display=\"1\"]");';
        echo 'var totalDisplay=card.querySelector("[data-extra-price-total=\"1\"]");';
        echo 'var render=function(){';
        echo 'var base=Math.max(0,parseFloat(baseInput.value)||0);';
        echo 'var commission=Math.max(0,parseFloat(commissionInput.value)||0);';
        echo 'if(baseDisplay){baseDisplay.textContent="USD "+base.toFixed(2);}';
        echo 'if(commissionDisplay){commissionDisplay.textContent="USD "+commission.toFixed(2);}';
        echo 'if(totalDisplay){totalDisplay.textContent="USD "+(base+commission).toFixed(2);}';
        echo '};';
        echo 'baseInput.addEventListener("input",render);';
        echo 'commissionInput.addEventListener("input",render);';
        echo 'render();';
        echo '});';
    }
    echo '})();';
    echo '</script>';

    return (string) ob_get_clean();
}

function admin_fetch_influencer_users(PDO $pdo): array {
    $stmt = $pdo->prepare("SELECT id, nombre, email, telefono FROM usuarios WHERE rol = 'influencer' ORDER BY nombre ASC, email ASC, id ASC");
    $stmt->execute();

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function admin_fetch_influencer_sales_counts_by_coupon(PDO $pdo): array {
    $stmt = $pdo->query('SELECT cupon_id, COUNT(*) AS total FROM cupones_influencer_ventas GROUP BY cupon_id');
    if (!$stmt instanceof PDOStatement) {
        return [];
    }

    $counts = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $couponId = (int) ($row['cupon_id'] ?? 0);
        if ($couponId <= 0) {
            continue;
        }
        $counts[$couponId] = (int) ($row['total'] ?? 0);
    }

    return $counts;
}

function admin_match_coupon_influencer_user_id(array $users, ?array $coupon): string {
    if (!$coupon) {
        return '';
    }

    $couponEmail = strtolower(trim((string) ($coupon['email_influencer'] ?? '')));
    $couponPhone = trim((string) ($coupon['telefono_influencer'] ?? ''));
    $couponName = trim((string) ($coupon['nombre_influencer'] ?? ''));
    $couponNameNormalized = function_exists('mb_strtolower') ? mb_strtolower($couponName, 'UTF-8') : strtolower($couponName);

    foreach ($users as $user) {
        if ($couponEmail !== '' && strtolower(trim((string) ($user['email'] ?? ''))) === $couponEmail) {
            return (string) ($user['id'] ?? '');
        }
    }

    foreach ($users as $user) {
        if ($couponPhone !== '' && trim((string) ($user['telefono'] ?? '')) === $couponPhone) {
            return (string) ($user['id'] ?? '');
        }
    }

    foreach ($users as $user) {
        $userName = trim((string) ($user['nombre'] ?? ''));
        $userNameNormalized = function_exists('mb_strtolower') ? mb_strtolower($userName, 'UTF-8') : strtolower($userName);
        if ($couponNameNormalized !== '' && $userNameNormalized === $couponNameNormalized) {
            return (string) ($user['id'] ?? '');
        }
    }

    return '';
}

function admin_normalize_influencer_user_filter($value, array $users): string {
    $userId = trim((string) $value);
    if ($userId === '') {
        return '';
    }

    foreach ($users as $user) {
        if ((string) ($user['id'] ?? '') === $userId) {
            return $userId;
        }
    }

    return '';
}

function admin_match_influencer_sale_user_id(array $users, array $sale): string {
    $saleEmail = strtolower(trim((string) ($sale['email_influencer'] ?? '')));
    $salePhone = trim((string) ($sale['telefono_influencer'] ?? ''));
    $saleName = trim((string) ($sale['nombre_influencer'] ?? ''));
    $saleNameNormalized = function_exists('mb_strtolower') ? mb_strtolower($saleName, 'UTF-8') : strtolower($saleName);

    foreach ($users as $user) {
        if ($saleEmail !== '' && strtolower(trim((string) ($user['email'] ?? ''))) === $saleEmail) {
            return (string) ($user['id'] ?? '');
        }
    }

    foreach ($users as $user) {
        if ($salePhone !== '' && trim((string) ($user['telefono'] ?? '')) === $salePhone) {
            return (string) ($user['id'] ?? '');
        }
    }

    foreach ($users as $user) {
        $userName = trim((string) ($user['nombre'] ?? ''));
        $userNameNormalized = function_exists('mb_strtolower') ? mb_strtolower($userName, 'UTF-8') : strtolower($userName);
        if ($saleNameNormalized !== '' && $userNameNormalized === $saleNameNormalized) {
            return (string) ($user['id'] ?? '');
        }
    }

    return '';
}

function admin_filter_influencer_sales_by_user(array $sales, array $users, string $userId): array {
    if ($userId === '') {
        return array_values($sales);
    }

    return array_values(array_filter($sales, static function ($sale) use ($users, $userId) {
        return admin_match_influencer_sale_user_id($users, $sale) === $userId;
    }));
}

function admin_filter_influencer_sales_by_payment_state(array $sales, string $paymentFilter): array {
    if ($paymentFilter === 'todos') {
        return array_values($sales);
    }

    return array_values(array_filter($sales, static function ($sale) use ($paymentFilter) {
        $status = trim((string) ($sale['estado_pago_influencer'] ?? $sale['estado_pago'] ?? 'pendiente'));
        if (!in_array($status, ['pendiente', 'pagado'], true)) {
            $status = 'pendiente';
        }

        return $status === $paymentFilter;
    }));
}

function admin_fetch_influencer_sales_dataset(PDO $pdo, array $influencerUsers, bool $isInfluencerViewer, array $adminUser, ?string $dateFrom, ?string $dateTo, string $selectedInfluencerFilterId, string $paymentFilter): array {
    $influencerSalesSql = "SELECT s.*, p.estado_pago_influencer, p.juego_nombre
        FROM cupones_influencer_ventas s
        INNER JOIN pedidos p ON p.id = s.pedido_id
        WHERE 1=1";
    $influencerSalesParams = [];

    if ($dateFrom !== null) {
        $influencerSalesSql .= ' AND DATE(s.creado_en) >= ?';
        $influencerSalesParams[] = $dateFrom;
    }
    if ($dateTo !== null) {
        $influencerSalesSql .= ' AND DATE(s.creado_en) <= ?';
        $influencerSalesParams[] = $dateTo;
    }
    if ($isInfluencerViewer) {
        $identityFilter = admin_build_influencer_sales_identity_filter($adminUser);
        if (!empty($identityFilter['clauses'])) {
            $influencerSalesSql .= ' AND (' . implode(' OR ', $identityFilter['clauses']) . ')';
            $influencerSalesParams = array_merge($influencerSalesParams, $identityFilter['params']);
        } else {
            $influencerSalesSql .= ' AND 1 = 0';
        }
    }

    $influencerSalesSql .= ' ORDER BY s.creado_en DESC';
    $influencerSalesStmt = $pdo->prepare($influencerSalesSql);
    $influencerSalesStmt->execute($influencerSalesParams);
    $influencerSalesAllStates = $influencerSalesStmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$isInfluencerViewer && $selectedInfluencerFilterId !== '') {
        $influencerSalesAllStates = admin_filter_influencer_sales_by_user($influencerSalesAllStates, $influencerUsers, $selectedInfluencerFilterId);
    }

    return [
        'allStates' => $influencerSalesAllStates,
        'sales' => admin_filter_influencer_sales_by_payment_state($influencerSalesAllStates, $paymentFilter),
        'totals' => admin_build_influencer_sales_totals($influencerSalesAllStates),
    ];
}

function admin_build_influencer_sales_totals(array $sales): array {
    $totals = [
        'pendiente' => ['count' => 0, 'amounts' => []],
        'pagado' => ['count' => 0, 'amounts' => []],
        'todos' => ['count' => 0, 'amounts' => []],
    ];

    foreach ($sales as $sale) {
        $status = trim((string) ($sale['estado_pago_influencer'] ?? $sale['estado_pago'] ?? 'pendiente'));
        if (!in_array($status, ['pendiente', 'pagado'], true)) {
            $status = 'pendiente';
        }

        $currency = trim((string) ($sale['moneda'] ?? ''));
        if ($currency === '') {
            $currency = 'Sin moneda';
        }

        $amount = round((float) ($sale['total_comision'] ?? 0), 2);
        foreach (['todos', $status] as $bucket) {
            $totals[$bucket]['count']++;
            if (!isset($totals[$bucket]['amounts'][$currency])) {
                $totals[$bucket]['amounts'][$currency] = 0.0;
            }
            $totals[$bucket]['amounts'][$currency] += $amount;
        }
    }

    foreach ($totals as $bucket => $data) {
        if (!empty($data['amounts'])) {
            ksort($data['amounts']);
            foreach ($data['amounts'] as $currency => $amount) {
                $totals[$bucket]['amounts'][$currency] = round((float) $amount, 2);
            }
        }
    }

    return $totals;
}

function admin_format_influencer_total_amounts(array $amounts): string {
    if (empty($amounts)) {
        return '0.00';
    }

    $parts = [];
    foreach ($amounts as $currency => $amount) {
        $label = trim((string) $currency);
        $formattedAmount = admin_format_money($amount);
        $parts[] = $label !== '' ? $label . ' ' . $formattedAmount : $formattedAmount;
    }

    return implode(' | ', $parts);
}

function admin_build_influencer_sales_identity_filter(array $user): array {
    $clauses = [];
    $params = [];

    $email = strtolower(trim((string) ($user['email'] ?? '')));
    if ($email !== '') {
        $clauses[] = 'LOWER(TRIM(s.email_influencer)) = ?';
        $params[] = $email;
    }

    $phone = trim((string) ($user['telefono'] ?? ''));
    if ($phone !== '') {
        $clauses[] = 'TRIM(s.telefono_influencer) = ?';
        $params[] = $phone;
    }

    $name = trim((string) ($user['full_name'] ?? $user['nombre'] ?? ''));
    if ($name !== '') {
        $clauses[] = 'LOWER(TRIM(s.nombre_influencer)) = ?';
        $params[] = function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
    }

    return ['clauses' => $clauses, 'params' => $params];
}

function admin_render_users_results(array $usuarios): string {
    ob_start();

    if (count($usuarios) === 0) {
        echo '<div class="text-secondary">No hay usuarios registrados con esos filtros.</div>';
        return (string) ob_get_clean();
    }

    echo '<div class="table-responsive mb-4 d-none d-md-block" style="background:#10141a; border-radius:16px; border:2px solid #00fff7; box-shadow:0 0 24px #00fff733; padding:1rem;">';
    echo '<table class="table align-middle" style="background:#181f2a; color:#00fff7; border-radius:12px;">';
    echo '<thead style="background:#181f2a; color:#00fff7; border-bottom:2px solid #00fff7;">';
    echo '<tr>';
    echo '<th style="color:#00fff7; background:#181f2a;">ID</th>';
    echo '<th style="color:#00fff7; background:#181f2a;">Nombre</th>';
    echo '<th style="color:#00fff7; background:#181f2a;">Email</th>';
    echo '<th style="color:#00fff7; background:#181f2a;">Teléfono</th>';
    echo '<th style="color:#00fff7; background:#181f2a;">Rol</th>';
    echo '<th style="color:#00fff7; background:#181f2a;">Creado</th>';
    echo '<th style="color:#00fff7; background:#181f2a;">Acciones</th>';
    echo '</tr>';
    echo '</thead>';
    echo '<tbody>';
    $rowAlt = false;
    foreach ($usuarios as $usuario) {
        $rowStyle = $rowAlt ? 'background:#151a24;' : 'background:#181f2a;';
        echo '<tr style="' . $rowStyle . ' color:#fff;">';
        echo '<td style="color:#00fff7; background:#181f2a;">' . htmlspecialchars($usuario['id']) . '</td>';
        echo '<td style="background:#181f2a;">';
        echo '<form method="POST" class="d-flex gap-2 align-items-center">';
        echo '<input type="hidden" name="editar_usuario" value="1">';
        echo '<input type="hidden" name="id" value="' . htmlspecialchars($usuario['id']) . '">';
        echo '<input type="text" name="nombre" value="' . htmlspecialchars($usuario['nombre']) . '" class="form-control form-control-sm" style="background:#222c3a; color:#00fff7; border:1px solid #00fff7;">';
        echo '</td>';
        echo '<td style="color:#fff; background:#181f2a;">' . htmlspecialchars($usuario['email']) . '</td>';
        echo '<td style="color:#b2f6ff; background:#181f2a;">' . htmlspecialchars(admin_display_phone($usuario['telefono'] ?? '')) . '</td>';
        echo '<td style="background:#181f2a;">';
        echo '<select name="rol" class="form-select form-select-sm" style="background:#222c3a; color:#00fff7; border:1px solid #00fff7;">';
        foreach (admin_manageable_user_roles() as $rolVal => $rolTxt) {
            $sel = $usuario['rol'] === $rolVal ? 'selected' : '';
            echo "<option value='$rolVal' $sel>$rolTxt</option>";
        }
        echo '</select>';
        echo '<button type="submit" class="btn btn-info btn-sm ms-2" style="background:#00fff7; color:#222; border:none; box-shadow:0 0 8px #00fff7;">Guardar</button>';
        echo '</form>';
        echo '</td>';
        echo '<td style="color:#00fff7; background:#181f2a;">' . htmlspecialchars($usuario['creado_en']) . '</td>';
        echo '<td style="background:#181f2a;">';
        if ($usuario['id'] != 1) {
            echo '<a href="?seccion=usuarios&borrar_usuario=' . urlencode((string) $usuario['id']) . '" class="btn btn-outline-danger btn-sm" style="border-color:#ff0059; color:#ff0059; background:#181f2a;" onmouseover="this.style.background=\'#ff0059\';this.style.color=\'#fff\'" onmouseout="this.style.background=\'#181f2a\';this.style.color=\'#ff0059\'" onclick="return confirm(\'¿Eliminar este usuario?\')">Eliminar</a>';
        } else {
            echo '<span class="text-secondary">Admin</span>';
        }
        echo '</td>';
        echo '</tr>';
        $rowAlt = !$rowAlt;
    }
    echo '</tbody></table>';
    echo '</div>';

    echo '<div class="d-block d-md-none">';
    foreach ($usuarios as $usuario) {
        echo '<div class="card bg-dark text-light mb-3 border-info shadow">';
        echo '<div class="card-header d-flex justify-content-between align-items-center">';
        echo '<span class="small text-info">ID: ' . htmlspecialchars($usuario['id']) . '</span>';
        echo '<span class="small text-secondary">' . htmlspecialchars($usuario['creado_en']) . '</span>';
        echo '</div>';
        echo '<div class="card-body">';
        echo '<form method="POST">';
        echo '<input type="hidden" name="editar_usuario" value="1">';
        echo '<input type="hidden" name="id" value="' . htmlspecialchars($usuario['id']) . '">';
        echo '<div class="mb-2">';
        echo '<label class="form-label text-info">Nombre</label>';
        echo '<input type="text" name="nombre" value="' . htmlspecialchars($usuario['nombre']) . '" class="form-control">';
        echo '</div>';
        echo '<div class="mb-2">';
        echo '<label class="form-label text-info">Email</label>';
        echo '<div class="form-control bg-dark text-light">' . htmlspecialchars($usuario['email']) . '</div>';
        echo '</div>';
        echo '<div class="mb-2">';
        echo '<label class="form-label text-info">Teléfono</label>';
        echo '<div class="form-control bg-dark text-light">' . htmlspecialchars(admin_display_phone($usuario['telefono'] ?? '')) . '</div>';
        echo '</div>';
        echo '<div class="mb-2">';
        echo '<label class="form-label text-info">Rol</label>';
        echo '<select name="rol" class="form-select">';
        foreach (admin_manageable_user_roles() as $rolVal => $rolTxt) {
            $sel = $usuario['rol'] === $rolVal ? 'selected' : '';
            echo "<option value='$rolVal' $sel>$rolTxt</option>";
        }
        echo '</select>';
        echo '</div>';
        echo '<div class="d-flex gap-2 mt-2">';
        echo '<button type="submit" class="btn btn-info flex-fill">Guardar</button>';
        if ($usuario['id'] != 1) {
            echo '<a href="?seccion=usuarios&borrar_usuario=' . urlencode((string) $usuario['id']) . '" class="btn btn-danger flex-fill" onclick="return confirm(\'¿Eliminar este usuario?\')">Eliminar</a>';
        } else {
            echo '<span class="btn btn-secondary flex-fill disabled">Admin</span>';
        }
        echo '</div>';
        echo '</form>';
        echo '</div>';
        echo '</div>';
    }
    echo '</div>';

    return (string) ob_get_clean();
}

function admin_normalize_positive_page($value): int {
    $page = (int) $value;
    return $page > 0 ? $page : 1;
}

function admin_normalize_per_page($value, int $default = 15): int {
    $perPage = (int) $value;
    return in_array($perPage, [15, 30, 50], true) ? $perPage : $default;
}

function admin_normalize_sort_direction($value, string $default = 'desc'): string {
    $direction = strtolower(trim((string) $value));
    return in_array($direction, ['asc', 'desc'], true) ? $direction : $default;
}

function admin_normalize_movement_sort_column($value): string {
    $column = trim((string) $value);
    $allowed = ['referencia', 'descripcion', 'fecha_movimiento', 'monto', 'moneda'];
    return in_array($column, $allowed, true) ? $column : 'fecha_movimiento';
}

function admin_normalize_movement_checked_filter($value): string {
    $filter = trim((string) $value);
    $allowed = ['no_verificados', 'verificados', 'todos'];
    return in_array($filter, $allowed, true) ? $filter : 'no_verificados';
}

function admin_normalize_movement_order_link_filter($value): string {
    $filter = trim((string) $value);
    $allowed = ['todos', 'con_pedido', 'sin_pedido'];
    return in_array($filter, $allowed, true) ? $filter : 'todos';
}

function admin_build_url(string $path, array $query = []): string {
    $query = array_filter($query, static function ($value) {
        return $value !== null && $value !== '';
    });

    if (empty($query)) {
        return $path;
    }

    return $path . '?' . http_build_query($query);
}

function admin_is_ajax_request(): bool {
    if (isset($_REQUEST['ajax']) && (string) $_REQUEST['ajax'] === '1') {
        return true;
    }

    $requestedWith = strtolower(trim((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')));
    $accept = strtolower(trim((string) ($_SERVER['HTTP_ACCEPT'] ?? '')));

    return $requestedWith === 'xmlhttprequest' || str_contains($accept, 'application/json');
}

function admin_movement_query_from_input(array $input): array {
    $query = [];

    $reference = trim((string) ($input['referencia'] ?? ''));
    if ($reference !== '') {
        $query['referencia'] = $reference;
    }

    $dateFrom = admin_normalize_date_filter($input['fecha_desde'] ?? null);
    if ($dateFrom !== null) {
        $query['fecha_desde'] = $dateFrom;
    }

    $dateTo = admin_normalize_date_filter($input['fecha_hasta'] ?? null);
    if ($dateTo !== null) {
        $query['fecha_hasta'] = $dateTo;
    }

    $currency = strtoupper(trim((string) ($input['moneda'] ?? '')));
    if ($currency !== '' && preg_match('/^[A-Z0-9_-]{1,20}$/', $currency) === 1) {
        $query['moneda'] = $currency;
    }

    $query['estado_verificacion'] = admin_normalize_movement_checked_filter($input['estado_verificacion'] ?? 'no_verificados');
    $query['pedido_relacionado'] = admin_normalize_movement_order_link_filter($input['pedido_relacionado'] ?? 'todos');
    $query['orden'] = admin_normalize_movement_sort_column($input['orden'] ?? 'fecha_movimiento');
    $query['direccion'] = admin_normalize_sort_direction($input['direccion'] ?? 'desc');
    $query['por_pagina'] = admin_normalize_per_page($input['por_pagina'] ?? 15);
    $query['pagina'] = admin_normalize_positive_page($input['pagina'] ?? 1);

    return $query;
}

function admin_parse_bank_movement_datetime(?string $value): ?string {
    $raw = trim((string) $value);
    if ($raw === '') {
        return null;
    }

    $normalized = str_ireplace([' a. m.', ' p. m.', ' a.m.', ' p.m.', ' am', ' pm'], [' AM', ' PM', ' AM', ' PM', ' AM', ' PM'], $raw);
    $normalized = preg_replace('/\s+/', ' ', $normalized) ?: $normalized;

    $formats = [
        'd/m/Y h:i:s A',
        'd/m/Y h:i A',
        'd/m/Y g:i:s A',
        'd/m/Y g:i A',
        'd/m/Y H:i:s',
        'd/m/Y H:i',
        'd-m-Y h:i:s A',
        'd-m-Y h:i A',
        'd-m-Y g:i:s A',
        'd-m-Y g:i A',
        'd-m-Y H:i:s',
        'd-m-Y H:i',
        'Y-m-d H:i:s',
        'Y-m-d H:i',
        'Y-m-d',
    ];

    $date = null;
    foreach ($formats as $format) {
        $date = DateTime::createFromFormat($format, $normalized);
        if ($date instanceof DateTime) {
            break;
        }
    }

    return $date ? $date->format('Y-m-d H:i:s') : null;
}

function admin_normalize_bank_amount($value): float {
    if (is_numeric($value)) {
        return round((float) $value, 2);
    }

    $raw = trim((string) $value);
    if ($raw === '') {
        return 0.0;
    }

    $clean = preg_replace('/[^0-9,.-]/', '', str_replace(' ', '', $raw));
    if ($clean === null || $clean === '') {
        return 0.0;
    }

    $lastComma = strrpos($clean, ',');
    $lastDot = strrpos($clean, '.');

    if ($lastComma !== false && $lastDot !== false) {
        if ($lastComma > $lastDot) {
            $clean = str_replace('.', '', $clean);
            $clean = str_replace(',', '.', $clean);
        } else {
            $clean = str_replace(',', '', $clean);
        }
    } elseif ($lastComma !== false) {
        $clean = str_replace('.', '', $clean);
        $clean = str_replace(',', '.', $clean);
    } else {
        $clean = str_replace(',', '', $clean);
    }

    return is_numeric($clean) ? round((float) $clean, 2) : 0.0;
}

function admin_http_get_json(string $url, int $timeout = 20, bool $verifySsl = true): array {
    $body = null;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => $verifySsl,
            CURLOPT_SSL_VERIFYHOST => $verifySsl ? 2 : 0,
        ]);
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new RuntimeException('No se pudo consultar la API bancaria: ' . $error);
        }

        if ($status >= 400) {
            throw new RuntimeException('La API bancaria respondió con código HTTP ' . $status . '.');
        }

        $body = $response;
    } else {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => $timeout,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => $verifySsl,
                'verify_peer_name' => $verifySsl,
            ],
        ]);
        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            throw new RuntimeException('No se pudo consultar la API bancaria.');
        }
        $body = $response;
    }

    $data = json_decode((string) $body, true);
    if (!is_array($data)) {
        throw new RuntimeException('La API bancaria no devolvió un JSON válido.');
    }

    return $data;
}

function admin_fetch_bank_movements_from_api(array $config): array {
    $baseUrl = store_config_normalize_bank_api_base_url((string) ($config['ff_bank_api_base_url'] ?? 'https://pagonorte.net'));
    $position = trim((string) ($config['ff_bank_posicion'] ?? ''));
    $token = trim((string) ($config['ff_bank_token'] ?? ''));
    $password = trim((string) ($config['ff_bank_clave'] ?? ''));

    if ($position === '' || $token === '' || $password === '') {
        throw new RuntimeException('La conexión automática para pagos en Bs/VES no está configurada completamente.');
    }

    if ($baseUrl === '') {
        throw new RuntimeException('El enlace base de la API bancaria no es válido.');
    }

    $url = store_config_build_bank_movements_url($baseUrl, [
        'posicion' => $position,
        'token' => $token,
        'password' => $password,
    ]);

    $data = admin_http_get_json($url, 20, false);
    $availableDays = isset($data['dias_disponibles']) ? max(0, (int) $data['dias_disponibles']) : null;
    store_config_upsert('ff_bank_dias_disponibles', $availableDays !== null ? (string) $availableDays : '');

    $movements = $data['movimientos'] ?? null;
    if (!is_array($movements)) {
        throw new RuntimeException('La API bancaria no devolvió la lista de movimientos esperada.');
    }

    $normalized = [];
    foreach ($movements as $movement) {
        if (!is_array($movement)) {
            continue;
        }

        $reference = trim((string) ($movement['referencia'] ?? ''));
        if ($reference === '') {
            continue;
        }

        $normalized[] = [
            'referencia' => substr($reference, 0, 120),
            'descripcion' => substr(trim((string) ($movement['descripcion'] ?? '')), 0, 255),
            'fecha_raw' => substr(trim((string) ($movement['fecha'] ?? '')), 0, 120),
            'fecha_movimiento' => admin_parse_bank_movement_datetime((string) ($movement['fecha'] ?? '')),
            'tipo' => substr(trim((string) ($movement['tipo'] ?? '')), 0, 80),
            'monto' => admin_normalize_bank_amount($movement['monto'] ?? 0),
            'moneda' => 'VES',
            'payload_json' => json_encode($movement, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
    }

    return $normalized;
}

function admin_binance_pagonorte_reference_prefix(): string {
    return 'BINANCE:';
}

function admin_store_binance_pagonorte_reference(string $reference): string {
    $trimmed = trim($reference);
    if ($trimmed === '') {
        return '';
    }

    return admin_binance_pagonorte_reference_prefix() . $trimmed;
}

function admin_display_movement_reference(string $reference): string {
    $prefix = admin_binance_pagonorte_reference_prefix();
    if (strncmp($reference, $prefix, strlen($prefix)) === 0) {
        return substr($reference, strlen($prefix));
    }

    return $reference;
}

function admin_fetch_binance_pagonorte_movements_from_api(array $config): array {
    $token = trim((string) ($config['binance_pagonorte_token'] ?? ''));
    if ($token === '') {
        throw new RuntimeException('El token de Binance no está configurado.');
    }

    $url = store_config_build_binance_pagonorte_movements_url($token);
    $data = admin_http_get_json($url, 20, false);
    $availableDays = isset($data['dias_disponibles']) ? max(0, (int) $data['dias_disponibles']) : null;
    store_config_upsert('binance_pagonorte_dias_disponibles', $availableDays !== null ? (string) $availableDays : '');

    $movements = $data['movimientos'] ?? null;
    if (!is_array($movements)) {
        throw new RuntimeException('La API de Binance no devolvió la lista de movimientos esperada.');
    }

    $normalized = [];
    foreach ($movements as $movement) {
        if (!is_array($movement)) {
            continue;
        }

        $reference = trim((string) ($movement['referencia'] ?? ''));
        if ($reference === '') {
            continue;
        }

        $normalized[] = [
            'referencia' => substr(admin_store_binance_pagonorte_reference($reference), 0, 120),
            'descripcion' => substr(trim((string) ($movement['descripcion'] ?? '')), 0, 255),
            'fecha_raw' => substr(trim((string) ($movement['fecha'] ?? '')), 0, 120),
            'fecha_movimiento' => admin_parse_bank_movement_datetime((string) ($movement['fecha'] ?? '')),
            'tipo' => substr(trim((string) ($movement['tipo'] ?? '')), 0, 80),
            'monto' => admin_normalize_bank_amount($movement['monto'] ?? 0),
            'moneda' => 'USDT',
            'payload_json' => json_encode($movement, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
    }

    return $normalized;
}

function admin_fetch_binance_pagonorte_balance_from_api(array $config): array {
    $token = trim((string) ($config['binance_pagonorte_token'] ?? ''));
    if ($token === '') {
        throw new RuntimeException('El token de Binance no está configurado.');
    }

    $url = store_config_build_binance_pagonorte_balance_url($token);
    $data = admin_http_get_json($url, 20, false);
    $availableDays = isset($data['dias_disponibles']) ? max(0, (int) $data['dias_disponibles']) : null;
    $cutoffDate = trim((string) ($data['fecha_corte'] ?? ''));

    store_config_upsert('binance_pagonorte_dias_disponibles', $availableDays !== null ? (string) $availableDays : '');
    store_config_upsert('binance_pagonorte_fecha_corte', $cutoffDate);

    return [
        'dias_disponibles' => $availableDays,
        'fecha_corte' => $cutoffDate,
        'payload' => $data,
    ];
}

function admin_sync_bank_movements(PDO $pdo, array $movements): array {
    if (empty($movements)) {
        return [
            'inserted' => 0,
            'updated' => 0,
            'processed' => 0,
        ];
    }

    $references = [];
    foreach ($movements as $movement) {
        $reference = trim((string) ($movement['referencia'] ?? ''));
        if ($reference !== '') {
            $references[$reference] = true;
        }
    }

    $existingReferences = [];
    $referenceList = array_keys($references);
    foreach (array_chunk($referenceList, 200) as $referenceChunk) {
        if (empty($referenceChunk)) {
            continue;
        }

        $placeholders = implode(', ', array_fill(0, count($referenceChunk), '?'));
        $existingStmt = $pdo->prepare('SELECT referencia FROM movimientos WHERE referencia IN (' . $placeholders . ')');
        $existingStmt->execute($referenceChunk);
        foreach ($existingStmt->fetchAll(PDO::FETCH_COLUMN) as $existingReference) {
            $existingReferences[(string) $existingReference] = true;
        }
    }

    $syncStmt = $pdo->prepare(
        'INSERT INTO movimientos (referencia, descripcion, fecha_raw, fecha_movimiento, tipo, monto, moneda, payload_json) '
        . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?) '
        . 'ON DUPLICATE KEY UPDATE descripcion = VALUES(descripcion), fecha_raw = VALUES(fecha_raw), fecha_movimiento = COALESCE(VALUES(fecha_movimiento), fecha_movimiento), tipo = VALUES(tipo), monto = VALUES(monto), moneda = VALUES(moneda), payload_json = VALUES(payload_json)'
    );

    $inserted = 0;
    $updated = 0;
    foreach ($movements as $movement) {
        $reference = (string) ($movement['referencia'] ?? '');
        if ($reference === '') {
            continue;
        }

        $wasExisting = isset($existingReferences[$reference]);
        $syncStmt->execute([
            $reference,
            (string) ($movement['descripcion'] ?? ''),
            (string) ($movement['fecha_raw'] ?? ''),
            $movement['fecha_movimiento'] !== null ? (string) $movement['fecha_movimiento'] : null,
            (string) ($movement['tipo'] ?? ''),
            (float) ($movement['monto'] ?? 0),
            (string) ($movement['moneda'] ?? 'VES'),
            (string) ($movement['payload_json'] ?? ''),
        ]);

        $affectedRows = (int) $syncStmt->rowCount();

        if ($wasExisting) {
            if ($affectedRows > 0) {
                $updated++;
            }
        } else {
            $inserted++;
        }
    }

    return [
        'inserted' => $inserted,
        'updated' => $updated,
        'processed' => $inserted + $updated,
    ];
}

require_once __DIR__ . '/includes/influencer_coupons.php';

switch ($seccion) {
    case 'usuarios':
        require_once __DIR__ . '/includes/db.php';
        if (isset($_GET['borrar_usuario'])) {
            $id = intval($_GET['borrar_usuario']);
            if (admin_is_protected_user($pdo, $id)) {
                admin_set_flash('error', 'No puedes eliminar este usuario protegido.');
            } else {
                $pdo->prepare('DELETE FROM usuarios WHERE id = ?')->execute([$id]);
                admin_set_flash('success', 'Usuario eliminado.');
            }
            admin_redirect('usuarios');
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['editar_usuario'])) {
            $id = intval($_POST['id']);
            $nombre = trim($_POST['nombre'] ?? '');
            $rol = $_POST['rol'] ?? 'usuario';
            if (admin_is_protected_user($pdo, $id)) {
                admin_set_flash('error', 'No puedes modificar este usuario protegido.');
            } elseif ($id && $nombre && array_key_exists($rol, admin_manageable_user_roles())) {
                $pdo->prepare('UPDATE usuarios SET nombre = ?, rol = ? WHERE id = ?')->execute([$nombre, $rol, $id]);
                admin_set_flash('success', 'Usuario actualizado.');
            } else {
                admin_set_flash('error', 'Datos inválidos para actualizar el usuario.');
            }
            admin_redirect('usuarios');
        }
        break;

    case 'comprar-funciones-extra':
        admin_extra_features_ensure_schema();

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_funciones_extra_whatsapp'])) {
            if (!admin_is_root_role($adminUserRole)) {
                admin_set_flash('error', 'Solo el usuario root puede configurar el numero de WhatsApp de venta.');
                admin_redirect('comprar-funciones-extra');
            }

            $whatsappNumber = admin_normalize_whatsapp_number($_POST['funciones_extra_whatsapp_numero'] ?? '');
            if (store_config_upsert(admin_extra_features_whatsapp_config_key(), $whatsappNumber, 'Numero usado para vender funciones extra por WhatsApp')) {
                admin_set_flash('success', 'Numero de WhatsApp guardado correctamente.');
            } else {
                admin_set_flash('error', 'No se pudo guardar el numero de WhatsApp.');
            }

            admin_redirect('comprar-funciones-extra');
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_funcion_extra'])) {
            if (!admin_is_root_role($adminUserRole)) {
                admin_set_flash('error', 'Solo el usuario root puede activar módulos o cambiar la comisión de venta.');
                admin_redirect('comprar-funciones-extra');
            }

            $featureId = intval($_POST['feature_id'] ?? 0);
            $basePrice = max(0, intval($_POST['precio'] ?? 0));
            $commission = max(0, intval($_POST['comision_venta'] ?? 0));
            $value = isset($_POST['activar_funcion']) ? '1' : '0';
            $feature = admin_fetch_extra_feature_by_id($featureId);

            if ($featureId <= 0) {
                admin_set_flash('error', 'La función seleccionada no es válida.');
            } elseif (!$feature) {
                admin_set_flash('error', 'No se encontró la función extra seleccionada.');
            } elseif (($validationError = admin_validate_extra_feature_update($feature, $value)) !== null) {
                admin_set_flash('error', $validationError);
            } elseif (admin_update_extra_feature($featureId, $basePrice, $commission, $value)) {
                admin_set_flash('success', 'Función extra actualizada correctamente.');
            } else {
                admin_set_flash('error', 'No se pudo actualizar la función extra.');
            }

            admin_redirect('comprar-funciones-extra');
        }
        break;

    case 'juegos':
        if (file_exists(__DIR__ . '/includes/db.php')) {
            require_once __DIR__ . '/includes/db.php';
        }
        if (isset($pdo)) {
            if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['nuevo_juego'])) {
                $nombre = $_POST['nombre'] ?? '';
                $descripcion = $_POST['descripcion'] ?? '';
                $precio = $_POST['precio'] ?? 0;
                $imagen = $_POST['imagen'] ?? '';
                $stmt = $pdo->prepare('INSERT INTO juegos (nombre, descripcion, slug, precio, imagen) VALUES (?, ?, ?, ?, ?)');
                $stmt->execute([$nombre, $descripcion, slugify($nombre), $precio, $imagen]);
                admin_set_flash('success', 'Juego agregado correctamente.');
                admin_redirect('juegos');
            }

            if (isset($_GET['borrar_juego'])) {
                $id = intval($_GET['borrar_juego']);
                $pdo->prepare('DELETE FROM juegos WHERE id = ?')->execute([$id]);
                admin_set_flash('success', 'Juego eliminado.');
                admin_redirect('juegos');
            }
        }
        break;

    case 'cupones':
        require_once __DIR__ . '/includes/db.php';
        influencer_coupon_ensure_sales_table_pdo($pdo);
        ensure_influencer_payment_status_column_pdo($pdo);

        $isInfluencerViewer = $adminUserRole === 'influencer';
        $influencerFiltersEnabled = store_config_get('filtros_influencer', '0') === '1';

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['actualizar_estado_pago_influencer'])) {
            if ($isInfluencerViewer) {
                admin_set_flash('error', 'No tienes permisos para modificar el estado de pago de comisiones.');
                admin_redirect('cupones', ['tab' => 'influencers']);
            }
            $pedidoId = intval($_POST['pedido_id'] ?? 0);
            $nuevoEstadoPago = trim((string) ($_POST['estado_pago_influencer'] ?? 'pendiente')) === 'pagado' ? 'pagado' : 'pendiente';
            $redirectFilter = $influencerFiltersEnabled ? admin_normalize_influencer_payment_filter($_POST['filtro_estado_pago'] ?? 'pendiente') : 'todos';
            $redirectDateFrom = $influencerFiltersEnabled ? admin_normalize_date_filter($_POST['fecha_desde'] ?? null) : null;
            $redirectDateTo = $influencerFiltersEnabled ? admin_normalize_date_filter($_POST['fecha_hasta'] ?? null) : null;
            $redirectInfluencerUserId = $influencerFiltersEnabled ? admin_normalize_influencer_user_filter($_POST['filtro_influencer_usuario'] ?? '', admin_fetch_influencer_users($pdo)) : '';
            if ($pedidoId > 0) {
                $stmt = $pdo->prepare('UPDATE pedidos SET estado_pago_influencer = ? WHERE id = ?');
                $stmt->execute([$nuevoEstadoPago, $pedidoId]);
                admin_set_flash('success', 'Estado de comisión actualizado.');
            } else {
                admin_set_flash('error', 'Pedido inválido para actualizar la comisión.');
            }
            $redirectQuery = ['tab' => 'influencers'];
            if ($influencerFiltersEnabled) {
                $redirectQuery['filtro_estado_pago'] = $redirectFilter;
                if ($redirectDateFrom !== null) {
                    $redirectQuery['fecha_desde'] = $redirectDateFrom;
                }
                if ($redirectDateTo !== null) {
                    $redirectQuery['fecha_hasta'] = $redirectDateTo;
                }
                if ($redirectInfluencerUserId !== '') {
                    $redirectQuery['filtro_influencer_usuario'] = $redirectInfluencerUserId;
                }
            }
            admin_redirect('cupones', $redirectQuery);
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['actualizar_pendientes_filtrados_influencer'])) {
            if ($isInfluencerViewer) {
                admin_set_flash('error', 'No tienes permisos para modificar el estado de pago de comisiones.');
                admin_redirect('cupones', ['tab' => 'influencers']);
            }

            $influencerUsers = admin_fetch_influencer_users($pdo);
            $redirectFilter = $influencerFiltersEnabled ? admin_normalize_influencer_payment_filter($_POST['filtro_estado_pago'] ?? 'pendiente') : 'todos';
            $redirectDateFrom = $influencerFiltersEnabled ? admin_normalize_date_filter($_POST['fecha_desde'] ?? null) : null;
            $redirectDateTo = $influencerFiltersEnabled ? admin_normalize_date_filter($_POST['fecha_hasta'] ?? null) : null;
            $redirectInfluencerUserId = $influencerFiltersEnabled ? admin_normalize_influencer_user_filter($_POST['filtro_influencer_usuario'] ?? '', $influencerUsers) : '';

            $redirectQuery = ['tab' => 'influencers'];
            if ($influencerFiltersEnabled) {
                $redirectQuery['filtro_estado_pago'] = $redirectFilter;
                if ($redirectDateFrom !== null) {
                    $redirectQuery['fecha_desde'] = $redirectDateFrom;
                }
                if ($redirectDateTo !== null) {
                    $redirectQuery['fecha_hasta'] = $redirectDateTo;
                }
                if ($redirectInfluencerUserId !== '') {
                    $redirectQuery['filtro_influencer_usuario'] = $redirectInfluencerUserId;
                }
            }

            if (!$influencerFiltersEnabled || $redirectFilter !== 'pendiente') {
                admin_set_flash('error', 'Esta acción solo está disponible al mostrar solo comisiones pendientes.');
                admin_redirect('cupones', $redirectQuery);
            }

            $salesDataset = admin_fetch_influencer_sales_dataset(
                $pdo,
                $influencerUsers,
                $isInfluencerViewer,
                $adminUser,
                $redirectDateFrom,
                $redirectDateTo,
                $redirectInfluencerUserId,
                $redirectFilter
            );
            $pendingOrderIds = array_values(array_unique(array_map(static function ($sale): int {
                return max(0, (int) ($sale['pedido_id'] ?? 0));
            }, $salesDataset['sales'])));
            $pendingOrderIds = array_values(array_filter($pendingOrderIds, static function (int $pedidoId): bool {
                return $pedidoId > 0;
            }));

            if (empty($pendingOrderIds)) {
                admin_set_flash('error', 'No hay comisiones pendientes dentro del filtro actual.');
                admin_redirect('cupones', $redirectQuery);
            }

            $placeholders = implode(', ', array_fill(0, count($pendingOrderIds), '?'));
            $params = array_merge(['pagado'], $pendingOrderIds);
            $stmt = $pdo->prepare("UPDATE pedidos SET estado_pago_influencer = ? WHERE id IN ($placeholders) AND (estado_pago_influencer IS NULL OR estado_pago_influencer = '' OR estado_pago_influencer = 'pendiente')");
            $stmt->execute($params);

            admin_set_flash('success', 'Se marcaron ' . count($pendingOrderIds) . ' comisión(es) pendiente(s) como pagadas.');
            admin_redirect('cupones', $redirectQuery);
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['nuevo_cupon'])) {
            if ($isInfluencerViewer) {
                admin_set_flash('error', 'No tienes permisos para crear cupones.');
                admin_redirect('cupones', ['tab' => 'influencers']);
            }
            $codigoInput = trim($_POST['codigo'] ?? '');
            $codigo = normalize_coupon_code($codigoInput);
            $tipo_descuento = $_POST['tipo_descuento'] ?? 'porcentaje';
            $valor_descuento = floatval($_POST['valor_descuento'] ?? 0);
            $fecha_expiracion = $_POST['fecha_expiracion'] ?? null;
            $limite_usos = ($_POST['limite_usos'] ?? '') !== '' ? intval($_POST['limite_usos']) : null;
            $activo = isset($_POST['activo']) ? 1 : 0;
            $permitirAcumularPuntos = admin_coupon_points_allowed_from_input($_POST, admin_coupons_win_points_enabled());
            $couponGameIds = admin_coupon_game_ids_from_input($_POST, admin_coupons_game_scope_enabled());
            $couponGameIdsJson = admin_coupon_game_ids_json($couponGameIds);
            $influencerPayload = influencer_coupon_payload_from_input($_POST);
            $influencerErrors = influencer_coupon_validate_payload($influencerPayload);

            if (!is_valid_coupon_code($codigoInput)) {
                admin_set_flash('error', 'El código del cupón solo puede contener letras y números, sin espacios, acentos ni caracteres especiales.');
            } elseif (!empty($influencerErrors)) {
                admin_set_flash('error', implode(' ', $influencerErrors));
            } else {
                $stmt_check = $pdo->prepare('SELECT 1 FROM cupones WHERE codigo = ? LIMIT 1');
                $stmt_check->execute([$codigo]);
                if ($stmt_check->fetch()) {
                    admin_set_flash('error', 'Ya existe un cupón con ese código.');
                } elseif ($codigo && $valor_descuento >= 0 && in_array($tipo_descuento, ['porcentaje', 'fijo'], true)) {
                    $stmt = $pdo->prepare('INSERT INTO cupones (codigo, tipo_descuento, valor_descuento, fecha_expiracion, limite_usos, activo, permitir_acumular_puntos, juegos_restringidos_json, nombre_influencer, telefono_influencer, email_influencer, comision_influencer) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                    $stmt->execute([
                        $codigo,
                        $tipo_descuento,
                        $valor_descuento,
                        $fecha_expiracion !== '' ? $fecha_expiracion : null,
                        $limite_usos,
                        $activo,
                        $permitirAcumularPuntos,
                        $couponGameIdsJson,
                        $influencerPayload['nombre_influencer'],
                        $influencerPayload['telefono_influencer'],
                        $influencerPayload['email_influencer'],
                        $influencerPayload['comision_influencer'],
                    ]);
                    admin_set_flash('success', 'Cupón creado correctamente.');
                } else {
                    admin_set_flash('error', 'Datos inválidos para el cupón.');
                }
            }
            admin_redirect('cupones');
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['editar_cupon'])) {
            if ($isInfluencerViewer) {
                admin_set_flash('error', 'No tienes permisos para editar cupones.');
                admin_redirect('cupones', ['tab' => 'influencers']);
            }
            $id = intval($_POST['id'] ?? 0);
            $codigoInput = trim($_POST['codigo'] ?? '');
            $codigo = normalize_coupon_code($codigoInput);
            $tipo_descuento = $_POST['tipo_descuento'] ?? 'porcentaje';
            $valor_descuento = floatval($_POST['valor_descuento'] ?? 0);
            $fecha_expiracion = $_POST['fecha_expiracion'] ?? null;
            $limite_usos = ($_POST['limite_usos'] ?? '') !== '' ? intval($_POST['limite_usos']) : null;
            $activo = isset($_POST['activo']) ? 1 : 0;
            $currentCouponStmt = $pdo->prepare('SELECT * FROM cupones WHERE id = ? LIMIT 1');
            $currentCouponStmt->execute([$id]);
            $currentCoupon = $currentCouponStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            $permitirAcumularPuntos = admin_coupon_points_allowed_from_input($_POST, admin_coupons_win_points_enabled(), $currentCoupon);
            $couponGameIds = admin_coupon_game_ids_from_input($_POST, admin_coupons_game_scope_enabled());
            $couponGameIdsJson = admin_coupon_game_ids_json($couponGameIds);
            $influencerPayload = influencer_coupon_payload_from_input($_POST);
            $influencerErrors = influencer_coupon_validate_payload($influencerPayload);

            if (!is_valid_coupon_code($codigoInput)) {
                admin_set_flash('error', 'El código del cupón solo puede contener letras y números, sin espacios, acentos ni caracteres especiales.');
            } elseif (!empty($influencerErrors)) {
                admin_set_flash('error', implode(' ', $influencerErrors));
            } else {
                $stmt_check = $pdo->prepare('SELECT 1 FROM cupones WHERE codigo = ? AND id <> ? LIMIT 1');
                $stmt_check->execute([$codigo, $id]);
                if ($stmt_check->fetch()) {
                    admin_set_flash('error', 'Ya existe un cupón con ese código.');
                } elseif ($id && $codigo && $valor_descuento >= 0 && in_array($tipo_descuento, ['porcentaje', 'fijo'], true)) {
                    $stmt = $pdo->prepare('UPDATE cupones SET codigo=?, tipo_descuento=?, valor_descuento=?, fecha_expiracion=?, limite_usos=?, activo=?, permitir_acumular_puntos=?, juegos_restringidos_json=?, nombre_influencer=?, telefono_influencer=?, email_influencer=?, comision_influencer=? WHERE id=?');
                    $stmt->execute([
                        $codigo,
                        $tipo_descuento,
                        $valor_descuento,
                        $fecha_expiracion !== '' ? $fecha_expiracion : null,
                        $limite_usos,
                        $activo,
                        $permitirAcumularPuntos,
                        $couponGameIdsJson,
                        $influencerPayload['nombre_influencer'],
                        $influencerPayload['telefono_influencer'],
                        $influencerPayload['email_influencer'],
                        $influencerPayload['comision_influencer'],
                        $id,
                    ]);
                    admin_set_flash('success', 'Cupón actualizado correctamente.');
                } else {
                    admin_set_flash('error', 'Datos inválidos para el cupón.');
                }
            }
            admin_redirect('cupones', ['editar_cupon' => $id]);
        }

        if (isset($_GET['borrar_cupon'])) {
            if ($isInfluencerViewer) {
                admin_set_flash('error', 'No tienes permisos para eliminar cupones.');
                admin_redirect('cupones', ['tab' => 'influencers']);
            }
            $id = intval($_GET['borrar_cupon']);
            $pdo->prepare('DELETE FROM cupones WHERE id = ?')->execute([$id]);
            admin_set_flash('success', 'Cupón eliminado.');
            admin_redirect('cupones');
        }

        if (isset($_GET['toggle_cupon'])) {
            if ($isInfluencerViewer) {
                admin_set_flash('error', 'No tienes permisos para cambiar el estado de cupones.');
                admin_redirect('cupones', ['tab' => 'influencers']);
            }
            $id = intval($_GET['toggle_cupon']);
            $pdo->prepare('UPDATE cupones SET activo = NOT activo WHERE id = ?')->execute([$id]);
            admin_set_flash('success', 'Estado del cupón actualizado.');
            admin_redirect('cupones');
        }
        break;

    case 'instrucciones-influencer':
        require_once __DIR__ . '/includes/influencer_instructions.php';

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['influencer_instructions_save'])) {
            $result = influencer_instructions_save_from_request($_POST, $_FILES);
            admin_set_flash($result['success'] ? 'success' : 'error', (string) ($result['message'] ?? 'No se pudo actualizar el modulo.'));
            admin_redirect('instrucciones-influencer');
        }
        break;

    case 'ventana-inicial-juegos':
        if (trim((string) store_config_get('ventana_inicio_juego', '0')) !== '1') {
            admin_set_flash('error', 'La función de ventanas iniciales por juego no está activa para este tenant.');
            admin_redirect('comprar-funciones-extra');
        }

        require_once __DIR__ . '/includes/game_entry_window_per_game.php';

        $gameEntryWindowGameId = max(0, (int) ($_POST['game_id'] ?? $_GET['game_id'] ?? 0));
        if ($gameEntryWindowGameId <= 0) {
            admin_set_flash('error', 'Selecciona un juego para configurar su ventana inicial.');
            admin_redirect('juegos');
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['game_entry_window_save'])) {
            $result = game_entry_window_save_from_request($_POST, $_FILES);
            admin_set_flash($result['success'] ? 'success' : 'error', (string) ($result['message'] ?? 'No se pudo actualizar la ventana inicial de este juego.'));
            admin_redirect('ventana-inicial-juegos', ['game_id' => $gameEntryWindowGameId]);
        }
        break;

    case 'configuracion':
        require_once __DIR__ . '/includes/store_config.php';
        require_once __DIR__ . '/includes/home_gallery.php';
        require_once __DIR__ . '/includes/payment_methods.php';
        require_once __DIR__ . '/includes/api_discord.php';
        $startupPopupNormalEnabled = store_config_get('inicio_popup_activo', '1') === '1';
        $startupPopupVideoEnabled = store_config_get('inicio_popup_video_activo', '0') === '1';
        $startupPopupGalleryEnabled = store_config_get('inicio_popup_galeria', '0') === '1';
        $startupPopupTabEnabled = store_config_get('inicio_popup_tab_habilitado', '1') === '1'
            || $startupPopupNormalEnabled
            || $startupPopupVideoEnabled
            || $startupPopupGalleryEnabled;
        $binanceApiTabEnabled = store_config_get('api_binance', '0') === '1';
        $binancePagonorteTabEnabled = store_config_get('api_binance_pagonorte', '0') === '1';
        $paypalTabEnabled = store_config_get('pago_paypal', '0') === '1';
        $discordApiTabEnabled = store_config_get('api_discord', '0') === '1';
        $activeTab = $_GET['tab'] ?? 'correo';
        $allowedTabs = ['correo', 'cabecera', 'notificaciones-recargas', 'sociales', 'api-banco', 'api-free-fire', 'personalizar-colores', 'galeria', 'metodos-pago'];
        if ($binanceApiTabEnabled) {
            $allowedTabs[] = 'api-binance';
        }
        if ($binancePagonorteTabEnabled) {
            $allowedTabs[] = 'verificacion-binance';
        }
        if ($paypalTabEnabled) {
            $allowedTabs[] = 'paypal';
        }
        if ($discordApiTabEnabled) {
            $allowedTabs[] = 'api-discord';
        }
        if ($startupPopupTabEnabled) {
            $allowedTabs[] = 'ventana-inicial';
        }
        if (!in_array($activeTab, $allowedTabs, true)) {
            $activeTab = 'correo';
        }

        home_gallery_ensure_table();
        payment_methods_ensure_table();

        if ($activeTab === 'ventana-inicial' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['startup_popup_gallery_action'])) {
            $galleryEnabled = store_config_get('inicio_popup_galeria', '0') === '1';
            if (!$galleryEnabled) {
                admin_json_response(['ok' => false, 'message' => 'La galería de la ventana inicial no está habilitada en esta tienda.'], 422);
            }

            $galleryAction = trim((string) ($_POST['startup_popup_gallery_action'] ?? ''));
            $galleryImages = admin_startup_popup_gallery_images();

            if ($galleryAction === 'upload') {
                if (!isset($_FILES['inicio_popup_galeria_imagenes'])) {
                    admin_json_response(['ok' => false, 'message' => 'Selecciona al menos una imagen para subir.'], 422);
                }

                $upload = store_config_store_startup_popup_gallery_uploads($_FILES['inicio_popup_galeria_imagenes']);
                if (!$upload['success']) {
                    admin_json_response(['ok' => false, 'message' => (string) ($upload['message'] ?? 'No se pudieron subir las imágenes seleccionadas.')], 422);
                }

                $newImages = [];
                foreach (array_values(array_filter(array_map('strval', $upload['paths'] ?? []))) as $newImagePath) {
                    $newImages[] = [
                        'path' => $newImagePath,
                        'link_url' => '',
                        'link_target' => '_self',
                    ];
                }
                $galleryImages = array_values(array_merge($galleryImages, $newImages));
                if (!admin_startup_popup_gallery_save($galleryImages)) {
                    foreach ($newImages as $newImage) {
                        store_config_delete_startup_popup_gallery_file((string) ($newImage['path'] ?? ''));
                    }
                    admin_json_response(['ok' => false, 'message' => 'No se pudo guardar la galería de la ventana inicial.'], 500);
                }

                admin_json_response(admin_startup_popup_gallery_payload($galleryImages));
            }

            $galleryImagePath = trim((string) ($_POST['startup_popup_gallery_image'] ?? ''));
            $galleryIndex = $galleryImagePath !== '' ? admin_startup_popup_gallery_find_index_by_path($galleryImages, $galleryImagePath) : null;
            if ($galleryImagePath === '' || $galleryIndex === null) {
                admin_json_response(['ok' => false, 'message' => 'La imagen seleccionada no existe dentro de la galería actual.'], 404);
            }

            if ($galleryAction === 'update-meta') {
                $rawLinkUrl = trim((string) ($_POST['startup_popup_gallery_link_url'] ?? ''));
                $normalizedLinkUrl = admin_normalize_startup_popup_gallery_url($rawLinkUrl);
                if ($rawLinkUrl !== '' && $normalizedLinkUrl === '') {
                    admin_json_response(['ok' => false, 'message' => 'El enlace debe comenzar con https://, http:// o / para una ruta interna válida.'], 422);
                }

                $galleryImages[$galleryIndex]['path'] = $galleryImagePath;
                $galleryImages[$galleryIndex]['link_url'] = $normalizedLinkUrl;
                $galleryImages[$galleryIndex]['link_target'] = admin_normalize_startup_popup_gallery_target($_POST['startup_popup_gallery_link_target'] ?? '_self');

                if (!admin_startup_popup_gallery_save($galleryImages)) {
                    admin_json_response(['ok' => false, 'message' => 'No se pudo guardar el enlace de esta imagen.'], 500);
                }

                admin_json_response(admin_startup_popup_gallery_payload($galleryImages));
            }

            if ($galleryAction === 'delete') {
                $galleryItem = $galleryImages[$galleryIndex];
                unset($galleryImages[$galleryIndex]);
                $galleryImages = array_values($galleryImages);
                if (!admin_startup_popup_gallery_save($galleryImages)) {
                    admin_json_response(['ok' => false, 'message' => 'No se pudo actualizar la galería de la ventana inicial.'], 500);
                }

                store_config_delete_startup_popup_gallery_file(admin_startup_popup_gallery_item_path($galleryItem));
                admin_json_response(admin_startup_popup_gallery_payload($galleryImages));
            }

            if ($galleryAction === 'move') {
                $direction = trim((string) ($_POST['startup_popup_gallery_direction'] ?? ''));
                $targetIndex = $direction === 'left' ? $galleryIndex - 1 : ($direction === 'right' ? $galleryIndex + 1 : $galleryIndex);
                if ($targetIndex < 0 || $targetIndex >= count($galleryImages) || $targetIndex === $galleryIndex) {
                    admin_json_response(admin_startup_popup_gallery_payload($galleryImages));
                }

                $swapImage = $galleryImages[$targetIndex];
                $galleryImages[$targetIndex] = $galleryImages[$galleryIndex];
                $galleryImages[$galleryIndex] = $swapImage;

                if (!admin_startup_popup_gallery_save($galleryImages)) {
                    admin_json_response(['ok' => false, 'message' => 'No se pudo reordenar la galería de la ventana inicial.'], 500);
                }

                admin_json_response(admin_startup_popup_gallery_payload($galleryImages));
            }

            admin_json_response(['ok' => false, 'message' => 'Acción de galería no soportada.'], 422);
        }

        if ($activeTab === 'galeria' && isset($_GET['eliminar_galeria'])) {
            $galleryId = intval($_GET['eliminar_galeria']);
            if ($galleryId > 0 && home_gallery_delete($galleryId)) {
                admin_set_flash('success', 'Elemento de galería eliminado.');
            } else {
                admin_set_flash('error', 'No se pudo eliminar el elemento de galería.');
            }
            admin_redirect('configuracion', ['tab' => 'galeria']);
        }

        if ($activeTab === 'galeria' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['gallery_order_update'], $_POST['gallery_order_id'], $_POST['gallery_order'])) {
            $galleryId = intval($_POST['gallery_order_id']);
            $galleryOrder = max(1, intval($_POST['gallery_order']));
            if ($galleryId > 0 && home_gallery_update_order($galleryId, $galleryOrder)) {
                if (admin_is_ajax_request()) {
                    admin_json_response(['ok' => true, 'id' => $galleryId, 'orden' => $galleryOrder]);
                }
                admin_set_flash('success', 'Orden de galería actualizado.');
            } else {
                if (admin_is_ajax_request()) {
                    admin_json_response(['ok' => false, 'message' => 'No se pudo actualizar el orden de la galería.'], 422);
                }
                admin_set_flash('error', 'No se pudo actualizar el orden de la galería.');
            }
            admin_redirect('configuracion', ['tab' => 'galeria']);
        }

        if ($activeTab === 'metodos-pago' && isset($_GET['eliminar_metodo_pago'])) {
            $paymentId = intval($_GET['eliminar_metodo_pago']);
            if ($paymentId > 0 && payment_methods_delete($paymentId)) {
                admin_set_flash('success', 'Método de pago eliminado.');
            } else {
                admin_set_flash('error', 'No se pudo eliminar el método de pago.');
            }
            admin_redirect('configuracion', ['tab' => 'metodos-pago']);
        }

        if ($activeTab === 'metodos-pago' && isset($_GET['toggle_metodo_pago'])) {
            $paymentId = intval($_GET['toggle_metodo_pago']);
            if ($paymentId > 0 && payment_methods_toggle($paymentId)) {
                admin_set_flash('success', 'Estado del método de pago actualizado.');
            } else {
                admin_set_flash('error', 'No se pudo actualizar el método de pago.');
            }
            admin_redirect('configuracion', ['tab' => 'metodos-pago']);
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $activeTab = $_POST['config_section'] ?? $activeTab;
            if (!in_array($activeTab, $allowedTabs, true)) {
                $activeTab = 'correo';
            }

            if (isset($_POST['refresh_runtime_code'])) {
                if (!admin_has_full_access($adminUserRole)) {
                    admin_set_flash('error', 'No tienes permisos para refrescar el código publicado.');
                } else {
                    $refreshResult = admin_refresh_runtime_code();
                    admin_set_flash($refreshResult['ok'] ? 'success' : 'error', (string) ($refreshResult['message'] ?? 'No se pudo refrescar el código publicado.'));
                }
                define('ADMIN_CONFIG_POST_HANDLED', true);
                admin_redirect('configuracion', ['tab' => $activeTab]);
            }

            if ($activeTab === 'correo') {
                $campos = [
                    'correo_corporativo', 'smtp_host', 'smtp_user', 'smtp_pass', 'smtp_port', 'smtp_secure'
                ];
                foreach ($campos as $clave) {
                    store_config_upsert($clave, trim((string) ($_POST[$clave] ?? '')));
                }
                admin_set_flash('success', 'Configuración de correo actualizada.');
            }

            if ($activeTab === 'cabecera') {
                $nombrePrefijo = trim((string) ($_POST['nombre_prefijo'] ?? ''));
                $nombreTienda = trim((string) ($_POST['nombre_tienda'] ?? ''));
                $nombreTiendaSubtitulo = trim((string) ($_POST['nombre_tienda_subtitulo'] ?? ''));
                $metaTitulo = trim((string) ($_POST['meta_titulo'] ?? ''));
                $metaDescripcion = trim((string) ($_POST['meta_descripcion'] ?? ''));
                $fondoAnimadoEnabled = store_config_public_background_enabled();
                $fondoPublicoModo = store_config_get('fondo_publico_modo', 'normal');
                $fondoPublicoOverlayColor = store_config_get('fondo_publico_overlay_color', '#081018');
                $fondoPublicoOverlayOpacity = store_config_get('fondo_publico_overlay_opacity', '52');
                $fondoPublicoAudioActivo = store_config_get('fondo_publico_audio_activo', '0');
                $fondoPublicoVolumen = store_config_get('fondo_publico_volumen', '35');
                $instruccionesInfluencer = isset($_POST['instrucciones_influencer']) ? '1' : '0';
                $googleAnalyticsActivo = isset($_POST['google_analytics_activo']) ? '1' : '0';
                $googleAnalyticsScript = trim((string) ($_POST['google_analytics_script'] ?? ''));
                $currentLogo = store_config_get('logo_tienda', '');
                $nextLogo = $currentLogo;
                $currentBackgroundMedia = store_config_get('fondo_publico_media', '');
                $nextBackgroundMedia = $currentBackgroundMedia;
                $hasUpload = isset($_FILES['logo_tienda']) && (($_FILES['logo_tienda']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE);
                $hasBackgroundUpload = $fondoAnimadoEnabled && isset($_FILES['fondo_publico_media']) && (($_FILES['fondo_publico_media']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE);

                if ($fondoAnimadoEnabled) {
                    $fondoPublicoModo = store_config_normalize_public_background_mode((string) ($_POST['fondo_publico_modo'] ?? 'normal'));
                    $fondoPublicoOverlayColor = store_config_normalize_hex_color((string) ($_POST['fondo_publico_overlay_color'] ?? '#081018'), '#081018');
                    $fondoPublicoOverlayOpacity = (string) store_config_normalize_percentage($_POST['fondo_publico_overlay_opacity'] ?? '52', 52);
                    $fondoPublicoAudioActivo = isset($_POST['fondo_publico_audio_activo']) ? '1' : '0';
                    $fondoPublicoVolumen = (string) store_config_normalize_percentage($_POST['fondo_publico_volumen'] ?? '35', 35);
                }

                if ($nombrePrefijo === '' || $nombreTienda === '' || $nombreTiendaSubtitulo === '' || $metaTitulo === '' || $metaDescripcion === '') {
                    admin_set_flash('error', 'Completa el nombre prefijo, el nombre de la tienda, el subtítulo del navegador y los datos SEO.');
                    define('ADMIN_CONFIG_POST_HANDLED', true);
                    admin_redirect('configuracion', ['tab' => 'cabecera']);
                }

                if ($hasBackgroundUpload) {
                    $upload = store_config_store_public_background_media_upload($_FILES['fondo_publico_media']);
                    if (!$upload['success']) {
                        admin_set_flash('error', $upload['message']);
                        define('ADMIN_CONFIG_POST_HANDLED', true);
                        admin_redirect('configuracion', ['tab' => 'cabecera']);
                    }
                    if (!empty($upload['path'])) {
                        $nextBackgroundMedia = $upload['path'];
                    }
                } elseif ($fondoAnimadoEnabled && isset($_POST['eliminar_fondo_publico_media'])) {
                    $nextBackgroundMedia = '';
                }

                if ($fondoAnimadoEnabled && $fondoPublicoModo === 'media' && $nextBackgroundMedia === '') {
                    admin_set_flash('error', 'Debes subir una imagen, GIF o video para usar el fondo multimedia.');
                    define('ADMIN_CONFIG_POST_HANDLED', true);
                    admin_redirect('configuracion', ['tab' => 'cabecera']);
                }

                if ($hasUpload) {
                    $upload = store_config_store_logo_upload($_FILES['logo_tienda']);
                    if (!$upload['success']) {
                        admin_set_flash('error', $upload['message']);
                        define('ADMIN_CONFIG_POST_HANDLED', true);
                        admin_redirect('configuracion', ['tab' => 'cabecera']);
                    }
                    if (!empty($upload['path'])) {
                        $nextLogo = $upload['path'];
                    }
                } elseif (isset($_POST['eliminar_logo_tienda'])) {
                    $nextLogo = '';
                }

                store_config_upsert('nombre_prefijo', $nombrePrefijo);
                store_config_upsert('nombre_tienda', $nombreTienda);
                store_config_upsert('nombre_tienda_subtitulo', $nombreTiendaSubtitulo);
                store_config_upsert('meta_titulo', $metaTitulo);
                store_config_upsert('meta_descripcion', $metaDescripcion);
                if ($fondoAnimadoEnabled) {
                    store_config_upsert('fondo_publico_modo', $fondoPublicoModo);
                    store_config_upsert('fondo_publico_overlay_color', $fondoPublicoOverlayColor);
                    store_config_upsert('fondo_publico_overlay_opacity', $fondoPublicoOverlayOpacity);
                    store_config_upsert('fondo_publico_audio_activo', $fondoPublicoAudioActivo);
                    store_config_upsert('fondo_publico_volumen', $fondoPublicoVolumen);
                }
                store_config_upsert('instrucciones_influencer', $instruccionesInfluencer);
                store_config_upsert('google_analytics_activo', $googleAnalyticsActivo);
                store_config_upsert('google_analytics_script', $googleAnalyticsScript);
                if ($nextLogo === '') {
                    store_config_delete('logo_tienda');
                } else {
                    store_config_upsert('logo_tienda', $nextLogo);
                }
                if ($fondoAnimadoEnabled) {
                    if ($nextBackgroundMedia === '') {
                        store_config_delete('fondo_publico_media');
                    } else {
                        store_config_upsert('fondo_publico_media', $nextBackgroundMedia);
                    }
                }

                if ($currentLogo !== '' && $currentLogo !== $nextLogo) {
                    store_config_delete_logo_file($currentLogo);
                }
                if ($fondoAnimadoEnabled && $currentBackgroundMedia !== '' && $currentBackgroundMedia !== $nextBackgroundMedia) {
                    store_config_delete_public_background_media_file($currentBackgroundMedia);
                }

                admin_set_flash('success', 'Datos de cabecera actualizados.');
            }

            if ($activeTab === 'notificaciones-recargas') {
                if (isset($_POST['save_win_points_notification_position'])) {
                    $notificationPosition = win_points_normalize_notification_position($_POST['win_points_notification_position'] ?? 'bottom-left');
                    store_config_upsert('win_points_notification_position', $notificationPosition);
                    admin_set_flash('success', 'Posición de notificaciones flotantes actualizada.');
                    define('ADMIN_CONFIG_POST_HANDLED', true);
                    admin_redirect('configuracion', ['tab' => 'notificaciones-recargas']);
                }

                $currentLogo = store_config_get('recarga_notificaciones_logo', '');
                $nextLogo = $currentLogo;
                $hasUpload = isset($_FILES['recarga_notificaciones_logo'])
                    && (($_FILES['recarga_notificaciones_logo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE);

                if ($hasUpload) {
                    $upload = store_config_store_recharge_notification_logo_upload($_FILES['recarga_notificaciones_logo']);
                    if (!$upload['success']) {
                        admin_set_flash('error', $upload['message']);
                        define('ADMIN_CONFIG_POST_HANDLED', true);
                        admin_redirect('configuracion', ['tab' => 'notificaciones-recargas']);
                    }
                    if (!empty($upload['path'])) {
                        $nextLogo = $upload['path'];
                    }
                } elseif (isset($_POST['eliminar_recarga_notificaciones_logo'])) {
                    $nextLogo = '';
                }

                store_config_upsert('recarga_notificaciones_activas', isset($_POST['recarga_notificaciones_activas']) ? '1' : '0');
                if ($nextLogo === '') {
                    store_config_delete('recarga_notificaciones_logo');
                } else {
                    store_config_upsert('recarga_notificaciones_logo', $nextLogo);
                }

                if ($currentLogo !== '' && $currentLogo !== $nextLogo) {
                    store_config_delete_logo_file($currentLogo);
                }

                admin_set_flash('success', 'Configuración de notificaciones de recargas actualizada.');
            }

            if ($activeTab === 'sociales') {
                $facebook = store_config_normalize_social_url((string) ($_POST['facebook'] ?? ''));
                $instagram = store_config_normalize_social_url((string) ($_POST['instagram'] ?? ''));
                $tiktok = store_config_normalize_social_url((string) ($_POST['tiktok'] ?? ''));
                $whatsapp = store_config_normalize_whatsapp((string) ($_POST['whatsapp'] ?? ''));
                $whatsappMessage = store_config_normalize_whatsapp_message((string) ($_POST['mensaje_whatsapp'] ?? ''));
                $whatsappFloatingEnabled = isset($_POST['whatsapp_flotante_activo']) ? '1' : '0';
                $whatsappChannel = store_config_normalize_social_url((string) ($_POST['whatsapp_channel'] ?? ''));
                $whatsappChannelFloatingEnabled = isset($_POST['whatsapp_channel_flotante_activo']) ? '1' : '0';
                $googleClientId = trim((string) ($_POST['google_client_id'] ?? ''));
                $googleClientSecret = trim((string) ($_POST['google_client_secret'] ?? ''));

                if ($facebook !== '' && !store_config_is_valid_social_url($facebook)) {
                    admin_set_flash('error', 'El enlace de Facebook no es válido. Usa una URL completa con http:// o https://');
                    define('ADMIN_CONFIG_POST_HANDLED', true);
                    admin_redirect('configuracion', ['tab' => 'sociales']);
                }

                if ($instagram !== '' && !store_config_is_valid_social_url($instagram)) {
                    admin_set_flash('error', 'El enlace de Instagram no es válido. Usa una URL completa con http:// o https://');
                    define('ADMIN_CONFIG_POST_HANDLED', true);
                    admin_redirect('configuracion', ['tab' => 'sociales']);
                }

                if ($tiktok !== '' && !store_config_is_valid_social_url($tiktok)) {
                    admin_set_flash('error', 'El enlace de TikTok no es válido. Usa una URL completa con http:// o https://');
                    define('ADMIN_CONFIG_POST_HANDLED', true);
                    admin_redirect('configuracion', ['tab' => 'sociales']);
                }

                if ($whatsapp !== '' && !store_config_is_valid_whatsapp($whatsapp)) {
                    admin_set_flash('error', 'El número de WhatsApp debe incluir código de país y número telefónico, por ejemplo: +584121234567.');
                    define('ADMIN_CONFIG_POST_HANDLED', true);
                    admin_redirect('configuracion', ['tab' => 'sociales']);
                }

                if ($whatsappChannel !== '' && !store_config_is_valid_social_url($whatsappChannel)) {
                    admin_set_flash('error', 'El enlace de WhatsApp Channel no es válido. Usa una URL completa con http:// o https://');
                    define('ADMIN_CONFIG_POST_HANDLED', true);
                    admin_redirect('configuracion', ['tab' => 'sociales']);
                }

                store_config_upsert('facebook', $facebook);
                store_config_upsert('instagram', $instagram);
                store_config_upsert('tiktok', $tiktok);
                store_config_upsert('whatsapp', $whatsapp);
                store_config_upsert('mensaje_whatsapp', $whatsappMessage);
                store_config_upsert('whatsapp_flotante_activo', $whatsappFloatingEnabled);
                store_config_upsert('whatsapp_channel', $whatsappChannel);
                store_config_upsert('whatsapp_channel_flotante_activo', $whatsappChannelFloatingEnabled);
                store_config_upsert('google_client_id', $googleClientId);
                store_config_upsert('google_client_secret', $googleClientSecret);
                admin_set_flash('success', 'Redes sociales actualizadas.');
            }

            if ($activeTab === 'api-banco') {
                $ffBankApiBaseUrl = store_config_normalize_bank_api_base_url((string) ($_POST['ff_bank_api_base_url'] ?? 'https://pagonorte.net'));
                $ffBankPosicion = trim((string) ($_POST['ff_bank_posicion'] ?? ''));
                $ffBankToken = trim((string) ($_POST['ff_bank_token'] ?? ''));
                $ffBankClave = trim((string) ($_POST['ff_bank_clave'] ?? ''));

                if (!store_config_is_valid_bank_api_base_url((string) ($_POST['ff_bank_api_base_url'] ?? 'https://pagonorte.net'))) {
                    admin_set_flash('error', 'El enlace base de la API del banco debe ser una URL válida.');
                    define('ADMIN_CONFIG_POST_HANDLED', true);
                    admin_redirect('configuracion', ['tab' => 'api-banco']);
                }

                if ($ffBankPosicion !== '' && !in_array($ffBankPosicion, ['0', '1', '2', '3', '4', '5'], true)) {
                    admin_set_flash('error', 'La Posicion debe estar entre 0 y 5.');
                    define('ADMIN_CONFIG_POST_HANDLED', true);
                    admin_redirect('configuracion', ['tab' => 'api-banco']);
                }

                $hasAnyBankCredential = $ffBankPosicion !== '' || $ffBankToken !== '' || $ffBankClave !== '';
                $hasCompleteBankCredential = $ffBankPosicion !== '' && $ffBankToken !== '' && $ffBankClave !== '';

                if ($hasAnyBankCredential && !$hasCompleteBankCredential) {
                    admin_set_flash('error', 'Para activar la conexión bancaria automática debes completar Posicion, Token y Clave, o dejar los tres campos vacíos.');
                    define('ADMIN_CONFIG_POST_HANDLED', true);
                    admin_redirect('configuracion', ['tab' => 'api-banco']);
                }

                if ($ffBankClave !== '' && preg_match('/^[A-Za-z0-9._!-]+$/', $ffBankClave) !== 1) {
                    admin_set_flash('error', 'La Clave del banco solo puede contener letras, números y estos caracteres especiales: . - _ !, sin espacios.');
                    define('ADMIN_CONFIG_POST_HANDLED', true);
                    admin_redirect('configuracion', ['tab' => 'api-banco']);
                }

                store_config_upsert('ff_bank_api_base_url', $ffBankApiBaseUrl);
                store_config_upsert('ff_bank_posicion', $ffBankPosicion);
                store_config_upsert('ff_bank_token', $ffBankToken);
                store_config_upsert('ff_bank_clave', $ffBankClave);
                admin_set_flash('success', 'Datos de conexión del banco actualizados.');
            }

            if ($activeTab === 'api-free-fire') {
                $recargasApiKey = trim((string) ($_POST['recargas_api_key'] ?? ''));

                store_config_upsert('recargas_api_key', $recargasApiKey);
                admin_set_flash('success', 'Datos API actualizados.');
            }

            if ($activeTab === 'api-binance') {
                $paymentMethodDiscountsEnabled = trim((string) store_config_get('descuento_metodo_pago', '0')) === '1';
                $binanceUserEnabled = isset($_POST['api_binance_usuario_present'])
                    ? (isset($_POST['api_binance_usuario']) && (string) $_POST['api_binance_usuario'] === '1' ? '1' : '0')
                    : trim((string) store_config_get('api_binance_usuario', '1'));
                $currentBinanceImage = trim((string) store_config_get('binance_pay_image', ''));
                $nextBinanceImage = $currentBinanceImage;
                $currentBinanceCornerImage = trim((string) store_config_get('binance_pay_corner_image', ''));
                $nextBinanceCornerImage = $currentBinanceCornerImage;
                $merchantNo = trim((string) ($_POST['binance_pay_merchant_no'] ?? ''));
                $secretKey = trim((string) ($_POST['binance_pay_secret_key'] ?? ''));
                $storeId = trim((string) ($_POST['binance_pay_store_id'] ?? ''));
                $accessToken = trim((string) ($_POST['binance_pay_access_token'] ?? ''));
                $storeUrl = trim((string) ($_POST['binance_pay_store_url'] ?? ''));
                $binanceDiscountRaw = trim((string) ($_POST['binance_pay_descuento'] ?? '0'));
                $binanceDiscount = $paymentMethodDiscountsEnabled
                    ? payment_methods_normalize_discount_percentage($binanceDiscountRaw)
                    : 0.0;
                $hasBinanceImageUpload = isset($_FILES['binance_pay_image'])
                    && (($_FILES['binance_pay_image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE);
                $hasBinanceCornerImageUpload = isset($_FILES['binance_pay_corner_image'])
                    && (($_FILES['binance_pay_corner_image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE);

                if ($merchantNo !== '' && preg_match('/^\d+$/', $merchantNo) !== 1) {
                    admin_set_flash('error', 'El Merchant No debe contener solo números.');
                    define('ADMIN_CONFIG_POST_HANDLED', true);
                    admin_redirect('configuracion', ['tab' => 'api-binance']);
                }

                if ($storeId !== '' && preg_match('/^\d+$/', $storeId) !== 1) {
                    admin_set_flash('error', 'El Store ID debe contener solo números.');
                    define('ADMIN_CONFIG_POST_HANDLED', true);
                    admin_redirect('configuracion', ['tab' => 'api-binance']);
                }

                if ($storeUrl !== '' && !store_config_is_valid_social_url($storeUrl)) {
                    admin_set_flash('error', 'La URL registrada en CoinPal debe ser una URL válida con http:// o https://');
                    define('ADMIN_CONFIG_POST_HANDLED', true);
                    admin_redirect('configuracion', ['tab' => 'api-binance']);
                }

                if ($paymentMethodDiscountsEnabled && $binanceDiscountRaw !== '' && !is_numeric(str_replace(',', '.', $binanceDiscountRaw))) {
                    admin_set_flash('error', 'El descuento de Binance Pay debe ser numérico.');
                    define('ADMIN_CONFIG_POST_HANDLED', true);
                    admin_redirect('configuracion', ['tab' => 'api-binance']);
                }

                if ($hasBinanceImageUpload) {
                    $upload = store_config_store_named_logo_upload($_FILES['binance_pay_image'], 'binance-pay-method');
                    if (!($upload['success'] ?? false)) {
                        admin_set_flash('error', (string) ($upload['message'] ?? 'No se pudo cargar la imagen de Binance Pay.'));
                        define('ADMIN_CONFIG_POST_HANDLED', true);
                        admin_redirect('configuracion', ['tab' => 'api-binance']);
                    }
                    if (!empty($upload['path'])) {
                        $nextBinanceImage = (string) $upload['path'];
                    }
                } elseif (isset($_POST['remove_binance_pay_image'])) {
                    $nextBinanceImage = '';
                }

                if ($hasBinanceCornerImageUpload) {
                    $cornerUpload = store_config_store_named_logo_upload($_FILES['binance_pay_corner_image'], 'binance-pay-corner');
                    if (!($cornerUpload['success'] ?? false)) {
                        if ($nextBinanceImage !== '' && $nextBinanceImage !== $currentBinanceImage) {
                            store_config_delete_logo_file($nextBinanceImage);
                        }
                        admin_set_flash('error', (string) ($cornerUpload['message'] ?? 'No se pudo cargar la imagen promocional de Binance Pay.'));
                        define('ADMIN_CONFIG_POST_HANDLED', true);
                        admin_redirect('configuracion', ['tab' => 'api-binance']);
                    }
                    if (!empty($cornerUpload['path'])) {
                        $nextBinanceCornerImage = (string) $cornerUpload['path'];
                    }
                } elseif (isset($_POST['remove_binance_pay_corner_image'])) {
                    $nextBinanceCornerImage = '';
                }

                store_config_upsert('api_binance_usuario', $binanceUserEnabled);
                store_config_upsert('binance_pay_merchant_no', $merchantNo);
                store_config_upsert('binance_pay_secret_key', $secretKey);
                store_config_upsert('binance_pay_store_id', $storeId);
                store_config_upsert('binance_pay_access_token', $accessToken);
                store_config_upsert('binance_pay_store_url', $storeUrl);
                if ($nextBinanceImage === '') {
                    store_config_delete('binance_pay_image');
                } else {
                    store_config_upsert('binance_pay_image', $nextBinanceImage);
                }
                if ($nextBinanceCornerImage === '') {
                    store_config_delete('binance_pay_corner_image');
                } else {
                    store_config_upsert('binance_pay_corner_image', $nextBinanceCornerImage);
                }
                store_config_upsert('binance_pay_descuento', rtrim(rtrim(number_format($binanceDiscount, 2, '.', ''), '0'), '.'));
                if ($currentBinanceImage !== '' && $currentBinanceImage !== $nextBinanceImage) {
                    store_config_delete_logo_file($currentBinanceImage);
                }
                if ($currentBinanceCornerImage !== '' && $currentBinanceCornerImage !== $nextBinanceCornerImage) {
                    store_config_delete_logo_file($currentBinanceCornerImage);
                }
                admin_set_flash('success', 'Configuración de Binance Pay actualizada.');
            }

            if ($activeTab === 'verificacion-binance') {
                $paymentMethodDiscountsEnabled = trim((string) store_config_get('descuento_metodo_pago', '0')) === '1';
                $binancePagonorteEnabled = isset($_POST['binance_pagonorte_activo_present'])
                    ? (isset($_POST['binance_pagonorte_activo']) && (string) $_POST['binance_pagonorte_activo'] === '1' ? '1' : '0')
                    : trim((string) store_config_get('binance_pagonorte_activo', '0'));
                $binancePagonorteToken = trim((string) ($_POST['binance_pagonorte_token'] ?? ''));
                $binancePagonorteData = trim((string) ($_POST['binance_pagonorte_datos'] ?? ''));
                $binancePagonorteDiscountRaw = trim((string) ($_POST['binance_pagonorte_descuento'] ?? '0'));
                $binancePagonorteReferenceDigitsRaw = trim((string) ($_POST['binance_pagonorte_referencia_digitos'] ?? '0'));
                $currentBinancePagonorteImage = trim((string) store_config_get('binance_pagonorte_image', ''));
                $nextBinancePagonorteImage = $currentBinancePagonorteImage;
                $currentBinancePagonorteCornerImage = trim((string) store_config_get('binance_pagonorte_corner_image', ''));
                $nextBinancePagonorteCornerImage = $currentBinancePagonorteCornerImage;
                $currentBinancePagonorteQrImage = trim((string) store_config_get('binance_pagonorte_qr_image', ''));
                $nextBinancePagonorteQrImage = $currentBinancePagonorteQrImage;
                $hasBinancePagonorteImageUpload = isset($_FILES['binance_pagonorte_image'])
                    && (($_FILES['binance_pagonorte_image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE);
                $hasBinancePagonorteCornerImageUpload = isset($_FILES['binance_pagonorte_corner_image'])
                    && (($_FILES['binance_pagonorte_corner_image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE);
                $hasBinancePagonorteQrImageUpload = isset($_FILES['binance_pagonorte_qr_image'])
                    && (($_FILES['binance_pagonorte_qr_image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE);

                if ($paymentMethodDiscountsEnabled && $binancePagonorteDiscountRaw !== '' && !is_numeric(str_replace(',', '.', $binancePagonorteDiscountRaw))) {
                    admin_set_flash('error', 'El descuento de Verificación Binance debe ser numérico.');
                    define('ADMIN_CONFIG_POST_HANDLED', true);
                    admin_redirect('configuracion', ['tab' => 'verificacion-binance']);
                }

                if ($binancePagonorteReferenceDigitsRaw !== '' && preg_match('/^\d+$/', $binancePagonorteReferenceDigitsRaw) !== 1) {
                    admin_set_flash('error', 'La cantidad de dígitos para la referencia de Binance debe ser un número entero.');
                    define('ADMIN_CONFIG_POST_HANDLED', true);
                    admin_redirect('configuracion', ['tab' => 'verificacion-binance']);
                }

                if ($hasBinancePagonorteImageUpload) {
                    $upload = store_config_store_named_logo_upload($_FILES['binance_pagonorte_image'], 'binance-pagonorte-method');
                    if (!($upload['success'] ?? false)) {
                        admin_set_flash('error', (string) ($upload['message'] ?? 'No se pudo cargar la imagen de Binance.'));
                        define('ADMIN_CONFIG_POST_HANDLED', true);
                        admin_redirect('configuracion', ['tab' => 'verificacion-binance']);
                    }
                    if (!empty($upload['path'])) {
                        $nextBinancePagonorteImage = (string) $upload['path'];
                    }
                } elseif (isset($_POST['remove_binance_pagonorte_image'])) {
                    $nextBinancePagonorteImage = '';
                }

                if ($hasBinancePagonorteCornerImageUpload) {
                    $cornerUpload = store_config_store_named_logo_upload($_FILES['binance_pagonorte_corner_image'], 'binance-pagonorte-corner');
                    if (!($cornerUpload['success'] ?? false)) {
                        if ($nextBinancePagonorteImage !== '' && $nextBinancePagonorteImage !== $currentBinancePagonorteImage) {
                            store_config_delete_logo_file($nextBinancePagonorteImage);
                        }
                        admin_set_flash('error', (string) ($cornerUpload['message'] ?? 'No se pudo cargar la imagen promocional de Binance.'));
                        define('ADMIN_CONFIG_POST_HANDLED', true);
                        admin_redirect('configuracion', ['tab' => 'verificacion-binance']);
                    }
                    if (!empty($cornerUpload['path'])) {
                        $nextBinancePagonorteCornerImage = (string) $cornerUpload['path'];
                    }
                } elseif (isset($_POST['remove_binance_pagonorte_corner_image'])) {
                    $nextBinancePagonorteCornerImage = '';
                }

                if ($hasBinancePagonorteQrImageUpload) {
                    $qrUpload = store_config_store_named_logo_upload($_FILES['binance_pagonorte_qr_image'], 'binance-pagonorte-qr');
                    if (!($qrUpload['success'] ?? false)) {
                        if ($nextBinancePagonorteImage !== '' && $nextBinancePagonorteImage !== $currentBinancePagonorteImage) {
                            store_config_delete_logo_file($nextBinancePagonorteImage);
                        }
                        if ($nextBinancePagonorteCornerImage !== '' && $nextBinancePagonorteCornerImage !== $currentBinancePagonorteCornerImage) {
                            store_config_delete_logo_file($nextBinancePagonorteCornerImage);
                        }
                        admin_set_flash('error', (string) ($qrUpload['message'] ?? 'No se pudo cargar el QR de Binance.'));
                        define('ADMIN_CONFIG_POST_HANDLED', true);
                        admin_redirect('configuracion', ['tab' => 'verificacion-binance']);
                    }
                    if (!empty($qrUpload['path'])) {
                        $nextBinancePagonorteQrImage = (string) $qrUpload['path'];
                    }
                } elseif (isset($_POST['remove_binance_pagonorte_qr_image'])) {
                    $nextBinancePagonorteQrImage = '';
                }

                $binancePagonorteDiscount = $paymentMethodDiscountsEnabled
                    ? max(0, min(100, payment_methods_normalize_discount_percentage($binancePagonorteDiscountRaw)))
                    : 0.0;
                $binancePagonorteReferenceDigits = max(0, min(120, (int) ($binancePagonorteReferenceDigitsRaw !== '' ? $binancePagonorteReferenceDigitsRaw : '0')));
                store_config_upsert('binance_pagonorte_token', $binancePagonorteToken);
                store_config_upsert('binance_pagonorte_activo', $binancePagonorteEnabled);
                store_config_upsert('binance_pagonorte_datos', $binancePagonorteData);
                if ($nextBinancePagonorteImage === '') {
                    store_config_delete('binance_pagonorte_image');
                } else {
                    store_config_upsert('binance_pagonorte_image', $nextBinancePagonorteImage);
                }
                if ($nextBinancePagonorteCornerImage === '') {
                    store_config_delete('binance_pagonorte_corner_image');
                } else {
                    store_config_upsert('binance_pagonorte_corner_image', $nextBinancePagonorteCornerImage);
                }
                if ($nextBinancePagonorteQrImage === '') {
                    store_config_delete('binance_pagonorte_qr_image');
                } else {
                    store_config_upsert('binance_pagonorte_qr_image', $nextBinancePagonorteQrImage);
                }
                store_config_upsert('binance_pagonorte_descuento', rtrim(rtrim(number_format($binancePagonorteDiscount, 2, '.', ''), '0'), '.'));
                store_config_upsert('binance_pagonorte_referencia_digitos', (string) $binancePagonorteReferenceDigits);

                if ($binancePagonorteToken === '') {
                    store_config_upsert('binance_pagonorte_dias_disponibles', '');
                    store_config_upsert('binance_pagonorte_fecha_corte', '');
                }

                $balanceWarning = '';
                if ($binancePagonorteToken !== '') {
                    try {
                        admin_fetch_binance_pagonorte_balance_from_api([
                            'binance_pagonorte_token' => $binancePagonorteToken,
                        ]);
                    } catch (Throwable $e) {
                        $balanceWarning = ' No se pudo consultar el saldo de Binance en este momento.';
                    }
                }

                if ($currentBinancePagonorteImage !== '' && $currentBinancePagonorteImage !== $nextBinancePagonorteImage) {
                    store_config_delete_logo_file($currentBinancePagonorteImage);
                }
                if ($currentBinancePagonorteCornerImage !== '' && $currentBinancePagonorteCornerImage !== $nextBinancePagonorteCornerImage) {
                    store_config_delete_logo_file($currentBinancePagonorteCornerImage);
                }
                if ($currentBinancePagonorteQrImage !== '' && $currentBinancePagonorteQrImage !== $nextBinancePagonorteQrImage) {
                    store_config_delete_logo_file($currentBinancePagonorteQrImage);
                }

                admin_set_flash('success', 'Configuración de Verificación Binance actualizada.' . $balanceWarning);
            }

            if ($activeTab === 'paypal') {
                $paypalCheckoutEnabled = isset($_POST['paypal_activo_present'])
                    ? (isset($_POST['paypal_activo']) && (string) $_POST['paypal_activo'] === '1' ? '1' : '0')
                    : trim((string) store_config_get('paypal_activo', '1'));
                $environment = strtolower(trim((string) ($_POST['paypal_environment'] ?? 'sandbox')));
                $environment = in_array($environment, ['sandbox', 'live'], true) ? $environment : 'sandbox';
                $clientId = trim((string) ($_POST['paypal_client_id'] ?? ''));
                $clientSecret = trim((string) ($_POST['paypal_client_secret'] ?? ''));
                $webhookId = trim((string) ($_POST['paypal_webhook_id'] ?? ''));
                $brandName = trim((string) ($_POST['paypal_brand_name'] ?? ''));
                $paypalTaxRaw = trim((string) ($_POST['paypal_impuesto'] ?? '0'));
                if ($paypalTaxRaw !== '' && !is_numeric(str_replace(',', '.', $paypalTaxRaw))) {
                    admin_set_flash('error', 'El Impuesto Paypal debe ser un número válido entre 0 y 100.');
                    define('ADMIN_CONFIG_POST_HANDLED', true);
                    admin_redirect('configuracion', ['tab' => 'paypal']);
                }
                $paypalTax = max(0, min(100, payment_methods_normalize_discount_percentage($paypalTaxRaw)));

                $currentPaypalImage = trim((string) store_config_get('paypal_image', ''));
                $nextPaypalImage = $currentPaypalImage;
                $currentPaypalCornerImage = trim((string) store_config_get('paypal_corner_image', ''));
                $nextPaypalCornerImage = $currentPaypalCornerImage;
                $currentPaypalQrImage = trim((string) store_config_get('paypal_qr_image', ''));
                $nextPaypalQrImage = $currentPaypalQrImage;

                $hasPaypalImageUpload = isset($_FILES['paypal_image'])
                    && (($_FILES['paypal_image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE);
                $hasPaypalCornerImageUpload = isset($_FILES['paypal_corner_image'])
                    && (($_FILES['paypal_corner_image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE);
                $hasPaypalQrImageUpload = isset($_FILES['paypal_qr_image'])
                    && (($_FILES['paypal_qr_image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE);

                if ($hasPaypalImageUpload) {
                    $upload = store_config_store_named_logo_upload($_FILES['paypal_image'], 'paypal-method');
                    if (!($upload['success'] ?? false)) {
                        admin_set_flash('error', (string) ($upload['message'] ?? 'No se pudo cargar la imagen de PayPal.'));
                        define('ADMIN_CONFIG_POST_HANDLED', true);
                        admin_redirect('configuracion', ['tab' => 'paypal']);
                    }
                    if (!empty($upload['path'])) {
                        $nextPaypalImage = (string) $upload['path'];
                    }
                } elseif (isset($_POST['remove_paypal_image'])) {
                    $nextPaypalImage = '';
                }

                if ($hasPaypalCornerImageUpload) {
                    $cornerUpload = store_config_store_named_logo_upload($_FILES['paypal_corner_image'], 'paypal-corner');
                    if (!($cornerUpload['success'] ?? false)) {
                        if ($nextPaypalImage !== '' && $nextPaypalImage !== $currentPaypalImage) {
                            store_config_delete_logo_file($nextPaypalImage);
                        }
                        admin_set_flash('error', (string) ($cornerUpload['message'] ?? 'No se pudo cargar la imagen promocional de PayPal.'));
                        define('ADMIN_CONFIG_POST_HANDLED', true);
                        admin_redirect('configuracion', ['tab' => 'paypal']);
                    }
                    if (!empty($cornerUpload['path'])) {
                        $nextPaypalCornerImage = (string) $cornerUpload['path'];
                    }
                } elseif (isset($_POST['remove_paypal_corner_image'])) {
                    $nextPaypalCornerImage = '';
                }

                if ($hasPaypalQrImageUpload) {
                    $qrUpload = store_config_store_named_logo_upload($_FILES['paypal_qr_image'], 'paypal-qr');
                    if (!($qrUpload['success'] ?? false)) {
                        if ($nextPaypalImage !== '' && $nextPaypalImage !== $currentPaypalImage) {
                            store_config_delete_logo_file($nextPaypalImage);
                        }
                        if ($nextPaypalCornerImage !== '' && $nextPaypalCornerImage !== $currentPaypalCornerImage) {
                            store_config_delete_logo_file($nextPaypalCornerImage);
                        }
                        admin_set_flash('error', (string) ($qrUpload['message'] ?? 'No se pudo cargar el QR de PayPal.'));
                        define('ADMIN_CONFIG_POST_HANDLED', true);
                        admin_redirect('configuracion', ['tab' => 'paypal']);
                    }
                    if (!empty($qrUpload['path'])) {
                        $nextPaypalQrImage = (string) $qrUpload['path'];
                    }
                } elseif (isset($_POST['remove_paypal_qr_image'])) {
                    $nextPaypalQrImage = '';
                }

                store_config_upsert('paypal_activo', $paypalCheckoutEnabled);
                store_config_upsert('paypal_environment', $environment);
                store_config_upsert('paypal_client_id', $clientId);
                store_config_upsert('paypal_client_secret', $clientSecret);
                store_config_upsert('paypal_webhook_id', $webhookId);
                store_config_upsert('paypal_brand_name', $brandName);
                store_config_upsert('paypal_impuesto', rtrim(rtrim(number_format($paypalTax, 2, '.', ''), '0'), '.'));
                if ($nextPaypalImage === '') {
                    store_config_delete('paypal_image');
                } else {
                    store_config_upsert('paypal_image', $nextPaypalImage);
                }
                if ($nextPaypalCornerImage === '') {
                    store_config_delete('paypal_corner_image');
                } else {
                    store_config_upsert('paypal_corner_image', $nextPaypalCornerImage);
                }
                if ($nextPaypalQrImage === '') {
                    store_config_delete('paypal_qr_image');
                } else {
                    store_config_upsert('paypal_qr_image', $nextPaypalQrImage);
                }

                if ($currentPaypalImage !== '' && $currentPaypalImage !== $nextPaypalImage) {
                    store_config_delete_logo_file($currentPaypalImage);
                }
                if ($currentPaypalCornerImage !== '' && $currentPaypalCornerImage !== $nextPaypalCornerImage) {
                    store_config_delete_logo_file($currentPaypalCornerImage);
                }
                if ($currentPaypalQrImage !== '' && $currentPaypalQrImage !== $nextPaypalQrImage) {
                    store_config_delete_logo_file($currentPaypalQrImage);
                }

                admin_set_flash('success', 'Configuración de PayPal actualizada.');
            }

            if ($activeTab === 'api-discord') {
                $webhookUrl = trim((string) ($_POST['api_discord_webhook_url'] ?? ''));
                $timeout = (string) api_discord_normalize_timeout($_POST['api_discord_timeout'] ?? '10');
                $username = api_discord_normalize_username((string) ($_POST['api_discord_username'] ?? ''));
                $avatarUrl = trim((string) ($_POST['api_discord_avatar_url'] ?? ''));
                $dryRun = isset($_POST['api_discord_dry_run']) ? '1' : '0';
                $probeCommandKey = trim((string) ($_POST['api_discord_probe_command'] ?? 'mobile_legends_price'));
                $existingListenerToken = api_discord_normalize_listener_token((string) store_config_get('api_discord_listener_token', ''));
                $listenerToken = api_discord_normalize_listener_token((string) ($_POST['api_discord_listener_token'] ?? $existingListenerToken));
                if ($listenerToken === '') {
                    $listenerToken = $existingListenerToken !== '' ? $existingListenerToken : api_discord_generate_listener_token();
                }

                if ($webhookUrl !== '' && !api_discord_validate_webhook_url($webhookUrl)) {
                    admin_set_flash('error', 'El webhook de Discord no tiene un formato válido. Debe ser una URL https://discord.com/api/webhooks/...');
                    define('ADMIN_CONFIG_POST_HANDLED', true);
                    admin_redirect('configuracion', ['tab' => 'api-discord']);
                }

                if ($avatarUrl !== '' && !store_config_is_valid_social_url($avatarUrl)) {
                    admin_set_flash('error', 'El avatar opcional de API Discord debe ser una URL válida con http:// o https://');
                    define('ADMIN_CONFIG_POST_HANDLED', true);
                    admin_redirect('configuracion', ['tab' => 'api-discord']);
                }

                store_config_upsert('api_discord_webhook_url', $webhookUrl);
                store_config_upsert('api_discord_timeout', $timeout);
                store_config_upsert('api_discord_username', $username);
                store_config_upsert('api_discord_avatar_url', $avatarUrl);
                store_config_upsert('api_discord_dry_run', $dryRun);
                store_config_upsert('api_discord_probe_command', $probeCommandKey);
                store_config_upsert('api_discord_listener_token', $listenerToken);

                if (isset($_POST['api_discord_probe_submit']) && (string) $_POST['api_discord_probe_submit'] === '1') {
                    $probe = api_discord_run_probe($probeCommandKey);
                    admin_set_flash($probe['ok'] ? 'success' : 'error', (string) ($probe['message'] ?? 'No se pudo ejecutar la prueba de API Discord.'));
                    define('ADMIN_CONFIG_POST_HANDLED', true);
                    admin_redirect('configuracion', ['tab' => 'api-discord']);
                }

                admin_set_flash('success', 'Configuración de API Discord actualizada.');
            }

            if ($activeTab === 'personalizar-colores') {
                if (isset($_POST['restore_theme_defaults'])) {
                    if (!store_theme_restore_defaults()) {
                        admin_set_flash('error', 'No se pudo restaurar la paleta base.');
                    } else {
                        admin_set_flash('success', 'La paleta editable fue restaurada desde los valores base.');
                    }
                    define('ADMIN_CONFIG_POST_HANDLED', true);
                    admin_redirect('configuracion', ['tab' => 'personalizar-colores']);
                }

                $validation = store_theme_validate_payload($_POST);
                if (!$validation['is_valid']) {
                    admin_set_flash('error', implode(' ', $validation['errors']));
                    define('ADMIN_CONFIG_POST_HANDLED', true);
                    admin_redirect('configuracion', ['tab' => 'personalizar-colores']);
                }

                if (!store_theme_save_values($validation['data'])) {
                    admin_set_flash('error', 'No se pudo guardar la paleta editable.');
                    define('ADMIN_CONFIG_POST_HANDLED', true);
                    admin_redirect('configuracion', ['tab' => 'personalizar-colores']);
                }

                $configDescriptions = store_config_descriptions();
                if (array_key_exists('ventana_pago_enviando_titulo', $_POST)) {
                    $sendingTitle = trim((string) ($_POST['ventana_pago_enviando_titulo'] ?? ''));
                    if ($sendingTitle === '') {
                        $sendingTitle = 'Enviando orden...';
                    }
                    if (!store_config_upsert('ventana_pago_enviando_titulo', $sendingTitle, $configDescriptions['ventana_pago_enviando_titulo'] ?? 'Texto principal que se muestra en el modal Enviando orden.')) {
                        admin_set_flash('error', 'No se pudo guardar el texto principal del modal Enviando orden.');
                        define('ADMIN_CONFIG_POST_HANDLED', true);
                        admin_redirect('configuracion', ['tab' => 'personalizar-colores']);
                    }
                }

                if (array_key_exists('ventana_pago_enviando_mensaje', $_POST)) {
                    $sendingMessage = trim((string) ($_POST['ventana_pago_enviando_mensaje'] ?? ''));
                    if ($sendingMessage === '') {
                        $sendingMessage = 'Estamos registrando tu comprobante y procesando la orden según la moneda del pedido. No cierres esta ventana.';
                    }
                    if (!store_config_upsert('ventana_pago_enviando_mensaje', $sendingMessage, $configDescriptions['ventana_pago_enviando_mensaje'] ?? 'Texto explicativo que se muestra debajo del título en el modal Enviando orden.')) {
                        admin_set_flash('error', 'No se pudo guardar el texto explicativo del modal Enviando orden.');
                        define('ADMIN_CONFIG_POST_HANDLED', true);
                        admin_redirect('configuracion', ['tab' => 'personalizar-colores']);
                    }
                }

                if (array_key_exists('ventana_pago_exitoso_titulo', $_POST)) {
                    $successTitle = trim((string) ($_POST['ventana_pago_exitoso_titulo'] ?? ''));
                    if ($successTitle === '') {
                        $successTitle = 'Pago exitoso';
                    }
                    if (!store_config_upsert('ventana_pago_exitoso_titulo', $successTitle, $configDescriptions['ventana_pago_exitoso_titulo'] ?? 'Texto principal que se muestra en el modal final cuando el pago es exitoso.')) {
                        admin_set_flash('error', 'No se pudo guardar el título del modal Pago exitoso.');
                        define('ADMIN_CONFIG_POST_HANDLED', true);
                        admin_redirect('configuracion', ['tab' => 'personalizar-colores']);
                    }
                }

                if (array_key_exists('ventana_pago_exitoso_mensaje_extra', $_POST)) {
                    $successExtraMessage = trim((string) ($_POST['ventana_pago_exitoso_mensaje_extra'] ?? ''));
                    if (!store_config_upsert('ventana_pago_exitoso_mensaje_extra', $successExtraMessage, $configDescriptions['ventana_pago_exitoso_mensaje_extra'] ?? 'Texto adicional opcional que se muestra debajo del mensaje principal en el modal final exitoso.')) {
                        admin_set_flash('error', 'No se pudo guardar el texto adicional del modal Pago exitoso.');
                        define('ADMIN_CONFIG_POST_HANDLED', true);
                        admin_redirect('configuracion', ['tab' => 'personalizar-colores']);
                    }
                }

                admin_set_flash('success', 'Paleta de colores actualizada.');
            }

            if ($activeTab === 'ventana-inicial') {
                $popupMode = trim((string) ($_POST['inicio_popup_modo'] ?? 'none'));
                $popupFrequency = trim((string) ($_POST['inicio_popup_frecuencia'] ?? 'per_session'));
                $popupChannelName = trim((string) ($_POST['inicio_popup_nombre_canal'] ?? 'DanisA Gamer Store'));
                $popupNormalEnabled = store_config_get('inicio_popup_activo', '1') === '1';
                $popupVideoEnabled = store_config_get('inicio_popup_video_activo', '0') === '1';
                $popupGalleryEnabled = store_config_get('inicio_popup_galeria', '0') === '1';
                $popupVideoUrl = $popupVideoEnabled
                    ? store_config_normalize_youtube_url((string) ($_POST['inicio_popup_video_url'] ?? ''))
                    : store_config_normalize_youtube_url((string) store_config_get('inicio_popup_video_url', ''));
                $channelUrl = store_config_normalize_social_url(store_config_get('whatsapp_channel', ''));
                $currentGalleryImages = admin_startup_popup_gallery_images();
                $availableModes = ['none'];
                if ($popupNormalEnabled) {
                    $availableModes[] = 'normal';
                }
                if ($popupVideoEnabled) {
                    $availableModes[] = 'video';
                }
                if ($popupGalleryEnabled) {
                    $availableModes[] = 'gallery';
                }

                if (!in_array($popupMode, ['none', 'normal', 'video', 'gallery'], true)) {
                    admin_set_flash('error', 'Selecciona un modo válido para la ventana inicial.');
                    define('ADMIN_CONFIG_POST_HANDLED', true);
                    admin_redirect('configuracion', ['tab' => 'ventana-inicial']);
                }

                if (!in_array($popupFrequency, ['always', 'per_entry', 'per_session'], true)) {
                    admin_set_flash('error', 'Selecciona una frecuencia válida para la ventana inicial.');
                    define('ADMIN_CONFIG_POST_HANDLED', true);
                    admin_redirect('configuracion', ['tab' => 'ventana-inicial']);
                }

                if (!in_array($popupMode, $availableModes, true)) {
                    admin_set_flash('error', 'El modo seleccionado no está habilitado dentro de los procesos disponibles de la ventana inicial.');
                    define('ADMIN_CONFIG_POST_HANDLED', true);
                    admin_redirect('configuracion', ['tab' => 'ventana-inicial']);
                }

                if ($popupMode === 'normal' && $popupChannelName === '') {
                    admin_set_flash('error', 'Debes indicar el nombre del canal para la ventana inicial.');
                    define('ADMIN_CONFIG_POST_HANDLED', true);
                    admin_redirect('configuracion', ['tab' => 'ventana-inicial']);
                }

                if (in_array($popupMode, ['normal', 'video', 'gallery'], true) && !store_config_is_valid_social_url($channelUrl)) {
                    admin_set_flash('error', 'Debes configurar primero un enlace válido en Redes Sociales > Whatsapp Channel para usar cualquier ventana inicial.');
                    define('ADMIN_CONFIG_POST_HANDLED', true);
                    admin_redirect('configuracion', ['tab' => 'ventana-inicial']);
                }

                if ($popupMode === 'video' && $popupVideoUrl === '') {
                    admin_set_flash('error', 'Debes indicar un enlace válido de YouTube para activar la ventana inicial con video.');
                    define('ADMIN_CONFIG_POST_HANDLED', true);
                    admin_redirect('configuracion', ['tab' => 'ventana-inicial']);
                }

                if ($popupMode === 'gallery' && count($currentGalleryImages) === 0) {
                    admin_set_flash('error', 'Debes cargar al menos una imagen para activar la ventana inicial de galería.');
                    define('ADMIN_CONFIG_POST_HANDLED', true);
                    admin_redirect('configuracion', ['tab' => 'ventana-inicial']);
                }

                store_config_upsert('inicio_popup_modo', $popupMode);
                store_config_upsert('inicio_popup_frecuencia', $popupFrequency);
                store_config_upsert('inicio_popup_nombre_canal', $popupChannelName !== '' ? $popupChannelName : 'DanisA Gamer Store');
                store_config_upsert('inicio_popup_video_url', $popupVideoUrl);

                admin_set_flash('success', 'Configuración de la ventana inicial actualizada.');
            }

            if ($activeTab === 'galeria') {
                $galleryId = isset($_POST['gallery_id']) ? intval($_POST['gallery_id']) : 0;
                $existingItem = $galleryId > 0 ? home_gallery_find($galleryId) : null;
                if ($galleryId > 0 && $existingItem === null) {
                    admin_set_flash('error', 'El elemento de galería que intentas editar no existe.');
                    define('ADMIN_CONFIG_POST_HANDLED', true);
                    admin_redirect('configuracion', ['tab' => 'galeria']);
                }

                $validation = home_gallery_validate_form($_POST, $_FILES, $existingItem);
                if (!$validation['is_valid']) {
                    $newImage = (string) ($validation['data']['imagen'] ?? '');
                    $existingImage = (string) ($existingItem['imagen'] ?? '');
                    if ($newImage !== '' && $newImage !== $existingImage) {
                        home_gallery_delete_image_file($newImage);
                    }
                    admin_set_flash('error', implode(' ', $validation['errors']));
                    define('ADMIN_CONFIG_POST_HANDLED', true);
                    $query = ['tab' => 'galeria'];
                    if ($galleryId > 0) {
                        $query['editar_galeria'] = $galleryId;
                    }
                    admin_redirect('configuracion', $query);
                }

                $saved = home_gallery_save($validation['data'], $galleryId > 0 ? $galleryId : null);
                if ($saved) {
                    $replacedImage = (string) ($validation['replaced_image'] ?? '');
                    $newImage = (string) ($validation['data']['imagen'] ?? '');
                    if ($replacedImage !== '' && $replacedImage !== $newImage) {
                        home_gallery_delete_image_file($replacedImage);
                    }
                    admin_set_flash('success', $galleryId > 0 ? 'Elemento de galería actualizado.' : 'Elemento de galería creado.');
                    define('ADMIN_CONFIG_POST_HANDLED', true);
                    admin_redirect('configuracion', ['tab' => 'galeria']);
                }

                $newImage = (string) ($validation['data']['imagen'] ?? '');
                $existingImage = (string) ($existingItem['imagen'] ?? '');
                if ($newImage !== '' && $newImage !== $existingImage) {
                    home_gallery_delete_image_file($newImage);
                }
                admin_set_flash('error', 'No se pudo guardar el elemento de galería.');
            }

            if ($activeTab === 'metodos-pago') {
                $paymentId = isset($_POST['payment_method_id']) ? intval($_POST['payment_method_id']) : 0;
                $existingMethod = $paymentId > 0 ? payment_methods_find($paymentId) : null;
                if ($paymentId > 0 && $existingMethod === null) {
                    admin_set_flash('error', 'El método de pago que intentas editar no existe.');
                    define('ADMIN_CONFIG_POST_HANDLED', true);
                    admin_redirect('configuracion', ['tab' => 'metodos-pago']);
                }

                $validation = payment_methods_validate_form($_POST);
                if (!$validation['is_valid']) {
                    admin_set_flash('error', implode(' ', $validation['errors']));
                    define('ADMIN_CONFIG_POST_HANDLED', true);
                    $query = ['tab' => 'metodos-pago'];
                    if ($paymentId > 0) {
                        $query['editar_metodo_pago'] = $paymentId;
                    }
                    admin_redirect('configuracion', $query);
                }

                $methodImagePath = (string) ($validation['data']['image_path'] ?? '');
                $methodQrImagePath = (string) ($validation['data']['qr_image_path'] ?? '');
                $methodCornerImagePath = (string) ($validation['data']['corner_image_path'] ?? '');

                if (!empty($_FILES['imagen_metodo_pago']) && (int) ($_FILES['imagen_metodo_pago']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    $imageUpload = payment_methods_store_uploaded_image($_FILES['imagen_metodo_pago'], 'method');
                    if (empty($imageUpload['ok'])) {
                        admin_set_flash('error', (string) ($imageUpload['error'] ?? 'No se pudo subir la imagen del método.'));
                        define('ADMIN_CONFIG_POST_HANDLED', true);
                        $query = ['tab' => 'metodos-pago'];
                        if ($paymentId > 0) {
                            $query['editar_metodo_pago'] = $paymentId;
                        }
                        admin_redirect('configuracion', $query);
                    }
                    $methodImagePath = (string) ($imageUpload['path'] ?? '');
                }

                if (!empty($_FILES['imagen_qr_metodo_pago']) && (int) ($_FILES['imagen_qr_metodo_pago']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    $qrUpload = payment_methods_store_uploaded_image($_FILES['imagen_qr_metodo_pago'], 'qr');
                    if (empty($qrUpload['ok'])) {
                        if ($methodImagePath !== '' && $methodImagePath !== (string) ($validation['data']['image_path'] ?? '')) {
                            payment_methods_delete_asset_file($methodImagePath);
                        }
                        admin_set_flash('error', (string) ($qrUpload['error'] ?? 'No se pudo subir la imagen QR del método.'));
                        define('ADMIN_CONFIG_POST_HANDLED', true);
                        $query = ['tab' => 'metodos-pago'];
                        if ($paymentId > 0) {
                            $query['editar_metodo_pago'] = $paymentId;
                        }
                        admin_redirect('configuracion', $query);
                    }
                    $methodQrImagePath = (string) ($qrUpload['path'] ?? '');
                }

                if (!empty($_FILES['imagen_promocion_metodo_pago']) && (int) ($_FILES['imagen_promocion_metodo_pago']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    $cornerUpload = payment_methods_store_uploaded_image($_FILES['imagen_promocion_metodo_pago'], 'corner');
                    if (empty($cornerUpload['ok'])) {
                        if ($methodImagePath !== '' && $methodImagePath !== (string) ($validation['data']['image_path'] ?? '')) {
                            payment_methods_delete_asset_file($methodImagePath);
                        }
                        if ($methodQrImagePath !== '' && $methodQrImagePath !== (string) ($validation['data']['qr_image_path'] ?? '')) {
                            payment_methods_delete_asset_file($methodQrImagePath);
                        }
                        admin_set_flash('error', (string) ($cornerUpload['error'] ?? 'No se pudo subir la imagen promocional del método.'));
                        define('ADMIN_CONFIG_POST_HANDLED', true);
                        $query = ['tab' => 'metodos-pago'];
                        if ($paymentId > 0) {
                            $query['editar_metodo_pago'] = $paymentId;
                        }
                        admin_redirect('configuracion', $query);
                    }
                    $methodCornerImagePath = (string) ($cornerUpload['path'] ?? '');
                }

                $validation['data']['image_path'] = $methodImagePath;
                $validation['data']['qr_image_path'] = $methodQrImagePath;
                $validation['data']['corner_image_path'] = $methodCornerImagePath;

                if (payment_methods_save($validation['data'], $paymentId > 0 ? $paymentId : null)) {
                    if ($existingMethod) {
                        $oldImagePath = (string) ($existingMethod['image_path'] ?? '');
                        $oldQrPath = (string) ($existingMethod['qr_image_path'] ?? '');
                        $oldCornerPath = (string) ($existingMethod['corner_image_path'] ?? '');
                        if ($methodImagePath !== '' && $methodImagePath !== $oldImagePath) {
                            payment_methods_delete_asset_file($oldImagePath);
                        }
                        if ($methodQrImagePath !== '' && $methodQrImagePath !== $oldQrPath) {
                            payment_methods_delete_asset_file($oldQrPath);
                        }
                        if ($methodCornerImagePath !== '' && $methodCornerImagePath !== $oldCornerPath) {
                            payment_methods_delete_asset_file($oldCornerPath);
                        }
                    }
                    admin_set_flash('success', $paymentId > 0 ? 'Método de pago actualizado.' : 'Método de pago creado.');
                    define('ADMIN_CONFIG_POST_HANDLED', true);
                    admin_redirect('configuracion', ['tab' => 'metodos-pago']);
                }

                if ($methodImagePath !== '' && $methodImagePath !== (string) ($existingMethod['image_path'] ?? '')) {
                    payment_methods_delete_asset_file($methodImagePath);
                }
                if ($methodQrImagePath !== '' && $methodQrImagePath !== (string) ($existingMethod['qr_image_path'] ?? '')) {
                    payment_methods_delete_asset_file($methodQrImagePath);
                }
                if ($methodCornerImagePath !== '' && $methodCornerImagePath !== (string) ($existingMethod['corner_image_path'] ?? '')) {
                    payment_methods_delete_asset_file($methodCornerImagePath);
                }

                admin_set_flash('error', 'No se pudo guardar el método de pago.');
            }

            define('ADMIN_CONFIG_POST_HANDLED', true);
            admin_redirect('configuracion', ['tab' => $activeTab]);
        }

        define('ADMIN_CONFIG_ACTIVE_TAB', $activeTab);
        define('ADMIN_CONFIG_POST_HANDLED', true);

    case 'movimientos':
    case 'movimientos-binance':
        require_once __DIR__ . '/includes/db.php';
        require_once __DIR__ . '/includes/store_config.php';
        $isBinanceMovementsSection = $seccion === 'movimientos-binance';
        if ($isBinanceMovementsSection && store_config_get('api_binance_pagonorte', '0') !== '1') {
            admin_set_flash('error', 'La verificación Binance no está activa en esta tienda.');
            admin_redirect(admin_default_section_for_role($adminUserRole));
        }

        if ($seccion === 'movimientos' || $seccion === 'movimientos-binance') {
            if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['actualizar_movimientos_api'])) {
                $redirectQuery = admin_movement_query_from_input($_POST);

                try {
                    if ($isBinanceMovementsSection) {
                        $movements = admin_fetch_binance_pagonorte_movements_from_api([
                            'binance_pagonorte_token' => store_config_get('binance_pagonorte_token', ''),
                        ]);
                    } else {
                        $bankConfig = [
                            'ff_bank_api_base_url' => store_config_get('ff_bank_api_base_url', 'https://pagonorte.net'),
                            'ff_bank_posicion' => store_config_get('ff_bank_posicion', ''),
                            'ff_bank_token' => store_config_get('ff_bank_token', ''),
                            'ff_bank_clave' => store_config_get('ff_bank_clave', ''),
                        ];
                        $movements = admin_fetch_bank_movements_from_api($bankConfig);
                    }
                    $syncSummary = admin_sync_bank_movements($pdo, $movements);
                    $hasNewMovements = (int) ($syncSummary['inserted'] ?? 0) > 0;
                    $hasUpdatedMovements = (int) ($syncSummary['updated'] ?? 0) > 0;
                    $hasSyncChanges = $hasNewMovements || $hasUpdatedMovements;
                    $syncMessage = $hasNewMovements && $hasUpdatedMovements
                        ? 'Se registraron ' . (int) ($syncSummary['inserted'] ?? 0) . ' movimientos nuevos y se actualizaron ' . (int) ($syncSummary['updated'] ?? 0) . ' existentes.'
                        : ($hasNewMovements
                            ? 'Se encontraron ' . (int) ($syncSummary['inserted'] ?? 0) . ' movimientos nuevos y ya fueron registrados.'
                            : ($hasUpdatedMovements
                                ? 'Se actualizaron ' . (int) ($syncSummary['updated'] ?? 0) . ' movimientos existentes desde la API.'
                                : 'No hay movimientos nuevos para actualizar.'));

                    if (admin_is_ajax_request()) {
                        header('Content-Type: application/json; charset=utf-8');
                        $availableDays = trim((string) store_config_get($isBinanceMovementsSection ? 'binance_pagonorte_dias_disponibles' : 'ff_bank_dias_disponibles', ''));
                        echo json_encode([
                            'ok' => true,
                            'has_sync_changes' => $hasSyncChanges,
                            'has_new_movements' => $hasNewMovements,
                            'inserted' => (int) ($syncSummary['inserted'] ?? 0),
                            'updated' => (int) ($syncSummary['updated'] ?? 0),
                            'processed' => (int) ($syncSummary['processed'] ?? 0),
                            'dias_disponibles' => $availableDays,
                            'message' => $syncMessage,
                        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                        exit();
                    }

                    $availableDays = trim((string) store_config_get($isBinanceMovementsSection ? 'binance_pagonorte_dias_disponibles' : 'ff_bank_dias_disponibles', ''));
                    $daysSuffix = $availableDays !== ''
                        ? ' La API ' . ($isBinanceMovementsSection ? 'Binance' : 'bancaria') . ' reporta ' . $availableDays . ' dias disponibles.'
                        : '';

                    if ($hasSyncChanges) {
                        admin_set_flash('success', $syncMessage . $daysSuffix);
                    } else {
                        admin_set_flash('info', 'No hay movimientos nuevos para actualizar.' . $daysSuffix);
                    }
                } catch (Throwable $e) {
                    if (admin_is_ajax_request()) {
                        http_response_code(500);
                        header('Content-Type: application/json; charset=utf-8');
                        echo json_encode([
                            'ok' => false,
                            'message' => $e->getMessage(),
                        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                        exit();
                    }

                    admin_set_flash('error', $e->getMessage());
                }

                admin_redirect($isBinanceMovementsSection ? 'movimientos-binance' : 'movimientos', $redirectQuery);
            }
            if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verificar_movimiento'])) {
                $movementId = (int) ($_POST['movimiento_id'] ?? 0);
                $redirectQuery = admin_movement_query_from_input($_POST);

                if ($movementId > 0) {
                    $stmt = $pdo->prepare('UPDATE movimientos SET checked = 1 WHERE id = ? AND (checked IS NULL OR checked = 0)');
                    $stmt->execute([$movementId]);
                    if ($stmt->rowCount() > 0) {
                        if (admin_is_ajax_request()) {
                            header('Content-Type: application/json; charset=utf-8');
                            echo json_encode([
                                'ok' => true,
                                'movement_id' => $movementId,
                                'checked' => 1,
                                'message' => 'Movimiento verificado correctamente.',
                            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                            exit();
                        }
                        admin_set_flash('success', 'Movimiento verificado correctamente.');
                    } else {
                        $checkStmt = $pdo->prepare('SELECT checked FROM movimientos WHERE id = ? LIMIT 1');
                        $checkStmt->execute([$movementId]);
                        $existingMovement = $checkStmt->fetch(PDO::FETCH_ASSOC);

                        if ($existingMovement) {
                            if (admin_is_ajax_request()) {
                                header('Content-Type: application/json; charset=utf-8');
                                echo json_encode([
                                    'ok' => true,
                                    'movement_id' => $movementId,
                                    'checked' => (int) ($existingMovement['checked'] ?? 0) === 1 ? 1 : 0,
                                    'message' => 'El movimiento ya estaba verificado.',
                                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                                exit();
                            }
                            admin_set_flash('info', 'El movimiento ya estaba verificado.');
                        } else {
                            if (admin_is_ajax_request()) {
                                http_response_code(404);
                                header('Content-Type: application/json; charset=utf-8');
                                echo json_encode([
                                    'ok' => false,
                                    'message' => 'El movimiento no existe.',
                                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                                exit();
                            }
                            admin_set_flash('error', 'El movimiento no existe.');
                        }
                    }
                } else {
                    if (admin_is_ajax_request()) {
                        http_response_code(422);
                        header('Content-Type: application/json; charset=utf-8');
                        echo json_encode([
                            'ok' => false,
                            'message' => 'Movimiento inválido para verificar.',
                        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                        exit();
                    }
                    admin_set_flash('error', 'Movimiento inválido para verificar.');
                }

                admin_redirect($isBinanceMovementsSection ? 'movimientos-binance' : 'movimientos', $redirectQuery);
            }
            break;
        }
        break;
}

if ($seccion === 'usuarios' && admin_is_ajax_request() && isset($_GET['usuarios_live_filter'])) {
    require_once __DIR__ . '/includes/db.php';

    $userFilters = admin_user_filters_from_input($_GET);
    $usuarios = admin_fetch_users($pdo, $userFilters);

    admin_json_response([
        'ok' => true,
        'html' => admin_render_users_results($usuarios),
    ]);
}

define('ADMIN_LAYOUT_EMBEDDED', true);

$influencerInstructionsEnabled = store_config_get('instrucciones_influencer', '0') === '1';
$adminInfluencerInstructionsPath = app_path('/admin/instrucciones-influencer');
$adminExtraFeaturesPath = app_path('/admin/comprar-funciones-extra');
// Header y menú igual al inicio
require_once __DIR__ . '/includes/header.php';
?>
<body class="min-h-screen text-slate-100">
<div class="relative min-h-screen overflow-hidden">

<div class="container-lg min-vh-100 d-flex flex-column align-items-center justify-content-center px-2">
    <div class="w-100 mt-5">
        <?php if ($seccion === 'dashboard' && in_array($adminUserRole, admin_allowed_roles(), true)): ?>
        <div class="mb-5 text-center">
            <h1 class="display-4 fw-bold text-info mb-4">Panel de Administración</h1>
            <h2 class="h3 fw-semibold mb-3">Bienvenido al panel de administración</h2>
            <p class="mb-4">Selecciona una sección para comenzar.</p>
            <div class="d-flex flex-wrap justify-content-center gap-3">
                <a href="/admin/pedidos" class="btn btn-outline-info btn-lg d-flex align-items-center gap-2"><span>📋</span>Pedidos</a>
                <a href="/admin/movimientos" class="btn btn-outline-info btn-lg d-flex align-items-center gap-2"><span>💳</span>Movimientos Bancarios</a>
                <?php if (store_config_get('api_binance_pagonorte', '0') === '1'): ?>
                <a href="/admin/movimientos-binance" class="btn btn-outline-info btn-lg d-flex align-items-center gap-2"><span>🟡</span>Movimientos Binance</a>
                <?php endif; ?>
                <?php if (admin_has_full_access($adminUserRole)): ?>
                <a href="/admin/usuarios" class="btn btn-outline-info btn-lg d-flex align-items-center gap-2"><span>👤</span>Usuarios</a>
                <a href="/admin/juegos" class="btn btn-outline-info btn-lg d-flex align-items-center gap-2"><span>🎮</span>Juegos</a>
                <a href="/admin/monedas" class="btn btn-outline-info btn-lg d-flex align-items-center gap-2"><span>💵</span>Monedas</a>
                <?php if (trim((string) store_config_get('win_points', '0')) === '1'): ?>
                <a href="/admin/win-points" class="btn btn-outline-info btn-lg d-flex align-items-center gap-2"><span>🏆</span>Win Points</a>
                <?php endif; ?>
                <a href="/admin/cupones" class="btn btn-outline-info btn-lg d-flex align-items-center gap-2"><span>✏️</span>Cupones</a>
                <a href="<?= htmlspecialchars($adminExtraFeaturesPath, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline-info btn-lg d-flex align-items-center gap-2"><span>🧩</span>Comprar Funciones Extra</a>
                <?php if ($influencerInstructionsEnabled): ?>
                <a href="<?= htmlspecialchars($adminInfluencerInstructionsPath, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline-info btn-lg d-flex align-items-center gap-2"><span>🤝</span>Instrucciones Influencer</a>
                <?php endif; ?>
                <?php if (trim((string) store_config_get('ventana_inicio_juego', '0')) === '1'): ?>
                <?php endif; ?>
                <a href="/admin/configuracion" class="btn btn-outline-info btn-lg d-flex align-items-center gap-2"><span>⚙️</span>Configuración</a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
        <div class="relative mb-8">
        <?php
        switch ($seccion) {
            case 'usuarios':
                require_once __DIR__ . '/includes/db.php';
                echo '<h2 class="display-6 fw-bold text-info mb-4">Gestión de Usuarios</h2>';
                // Borrar usuario
                if (isset($_GET['borrar_usuario'])) {
                    $id = intval($_GET['borrar_usuario']);
                    if (!admin_is_protected_user($pdo, $id)) {
                        $pdo->prepare('DELETE FROM usuarios WHERE id = ?')->execute([$id]);
                        echo '<div class="text-green-400 mb-2">Usuario eliminado.</div>';
                    } else {
                        echo '<div class="text-danger mb-2">No puedes eliminar este usuario protegido.</div>';
                    }
                }
                // Edición de usuario (solo nombre y rol)
                if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['editar_usuario'])) {
                    $id = intval($_POST['id']);
                    $nombre = trim($_POST['nombre'] ?? '');
                    $rol = $_POST['rol'] ?? 'usuario';
                    if (admin_is_protected_user($pdo, $id)) {
                        echo '<div class="text-danger mb-2">No puedes modificar este usuario protegido.</div>';
                    } elseif ($id && $nombre && array_key_exists($rol, admin_manageable_user_roles())) {
                        $pdo->prepare('UPDATE usuarios SET nombre = ?, rol = ? WHERE id = ?')->execute([$nombre, $rol, $id]);
                        echo '<div class="text-green-400 mb-2">Usuario actualizado.</div>';
                    }
                }
                        $userFilters = admin_user_filters_from_input($_GET);
                        $usuarios = admin_fetch_users($pdo, $userFilters);

                                echo '<form method="GET" action="' . htmlspecialchars(app_path('/admin/usuarios')) . '" class="row g-3 mb-4 align-items-end" data-user-filter-form="1" style="background:#10141a; border-radius:16px; border:2px solid #00fff7; box-shadow:0 0 24px #00fff733; padding:1rem;">';
                                echo '<input type="hidden" name="seccion" value="usuarios">';
                                echo '<div class="col-md-3">';
                                echo '<label class="form-label" style="color:#00fff7;">Nombre</label>';
                                echo '<input type="text" name="filtro_nombre" value="' . htmlspecialchars($userFilters['nombre']) . '" class="form-control" placeholder="Buscar por nombre" style="background:#222c3a; color:#00fff7; border:1px solid #00fff7;">';
                                echo '</div>';
                                echo '<div class="col-md-3">';
                                echo '<label class="form-label" style="color:#00fff7;">Correo</label>';
                                echo '<input type="text" name="filtro_correo" value="' . htmlspecialchars($userFilters['correo']) . '" class="form-control" placeholder="Buscar por correo" style="background:#222c3a; color:#00fff7; border:1px solid #00fff7;">';
                                echo '</div>';
                                echo '<div class="col-md-2">';
                                echo '<label class="form-label" style="color:#00fff7;">Rol</label>';
                                echo '<select name="filtro_rol" class="form-select" style="background:#222c3a; color:#00fff7; border:1px solid #00fff7;">';
                                echo '<option value="">Todos</option>';
                                foreach (admin_manageable_user_roles() as $rolVal => $rolTxt) {
                                        $selected = $userFilters['rol'] === $rolVal ? 'selected' : '';
                                        echo "<option value='$rolVal' $selected>$rolTxt</option>";
                                }
                                echo '</select>';
                                echo '</div>';
                                echo '<div class="col-md-2">';
                                echo '<label class="form-label" style="color:#00fff7;">Desde</label>';
                                echo '<input type="date" name="filtro_fecha_desde" value="' . htmlspecialchars($userFilters['fecha_desde']) . '" class="form-control" style="background:#222c3a; color:#00fff7; border:1px solid #00fff7;">';
                                echo '</div>';
                                echo '<div class="col-md-2">';
                                echo '<label class="form-label" style="color:#00fff7;">Hasta</label>';
                                echo '<input type="date" name="filtro_fecha_hasta" value="' . htmlspecialchars($userFilters['fecha_hasta']) . '" class="form-control" style="background:#222c3a; color:#00fff7; border:1px solid #00fff7;">';
                                echo '</div>';
                                echo '<div class="col-12 d-flex justify-content-end">';
                                echo '<button type="button" class="btn btn-outline-info" data-user-filter-clear="1" style="border-color:#00fff7; color:#00fff7;">Limpiar filtros</button>';
                                echo '</div>';
                                echo '</form>';

                                echo '<div data-user-results="1">' . admin_render_users_results($usuarios) . '</div>';
                                echo '<script>
(() => {
    const form = document.querySelector("[data-user-filter-form=\"1\"]");
    const results = document.querySelector("[data-user-results=\"1\"]");
    const clearButton = document.querySelector("[data-user-filter-clear=\"1\"]");
    if (!form || !results) {
        return;
    }

    let debounceTimer = null;
    let requestController = null;

    const runFilter = () => {
        window.clearTimeout(debounceTimer);
        debounceTimer = window.setTimeout(async () => {
            const formData = new FormData(form);
            formData.set("usuarios_live_filter", "1");
            formData.set("ajax", "1");
            const params = new URLSearchParams(formData);
            const requestUrl = `${form.action}?${params.toString()}`;
            const visibleParams = new URLSearchParams(new FormData(form));
            const visibleUrl = visibleParams.toString() ? `${form.action}?${visibleParams.toString()}` : form.action;

            if (requestController) {
                requestController.abort();
            }

            requestController = new AbortController();

            try {
                const response = await fetch(requestUrl, {
                    headers: {
                        "X-Requested-With": "XMLHttpRequest",
                        "Accept": "application/json, text/plain, */*"
                    },
                    signal: requestController.signal
                });
                const payload = await response.json();
                if (!response.ok || !payload.ok) {
                    throw new Error(payload.message || "No se pudo filtrar los usuarios.");
                }
                results.innerHTML = payload.html || "";
                window.history.replaceState(null, "", visibleUrl);
            } catch (error) {
                if (error.name !== "AbortError") {
                    console.error(error);
                }
            }
        }, 250);
    };

    form.querySelectorAll("input, select").forEach((field) => {
        const eventName = field.tagName === "SELECT" || field.type === "date" ? "change" : "input";
        field.addEventListener(eventName, runFilter);
    });

    form.addEventListener("submit", (event) => {
        event.preventDefault();
        runFilter();
    });

    if (clearButton) {
        clearButton.addEventListener("click", () => {
            form.reset();
            runFilter();
        });
    }
})();
</script>';
                break;
            case 'comprar-funciones-extra':
                $isRootViewer = admin_is_root_role($adminUserRole);
                $extraFeatures = admin_fetch_extra_features();
                $extraFeaturesWhatsappNumber = admin_extra_features_whatsapp_number();
                echo '<h2 class="display-6 fw-bold text-info mb-4">Comprar Funciones Extra</h2>';
                echo '<div class="config-section-note mb-4">';
                echo $isRootViewer
                    ? 'Aquí controlas qué funciones extra están activas para este tenant y cuánto se suma de comisión al precio mostrado.'
                    : 'Aquí se muestran las funciones extra disponibles para este tenant. El precio visible ya incluye la comisión configurada para la venta.';
                echo '</div>';
                if ($isRootViewer) {
                    echo admin_render_extra_features_whatsapp_settings($extraFeaturesWhatsappNumber);
                }
                echo admin_render_extra_features_table($extraFeatures, $isRootViewer, $extraFeaturesWhatsappNumber);
                break;
            case 'juegos':
                echo '<h2 class="text-2xl font-semibold mb-8 text-cyan-300">Gestión de Juegos</h2>';
                if (file_exists(__DIR__ . "/includes/db.php")) {
                    require_once __DIR__ . "/includes/db.php";
                } else {
                    echo '<div class="text-red-400">Error: No se encontró el archivo de conexión a la base de datos (includes/db.php).</div>';
                    break;
                }
                // Alta de juego
                if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['nuevo_juego'])) {
                    $nombre = $_POST['nombre'] ?? '';
                    $descripcion = $_POST['descripcion'] ?? '';
                    $precio = $_POST['precio'] ?? 0;
                    $imagen = $_POST['imagen'] ?? '';
                    $stmt = $pdo->prepare("INSERT INTO juegos (nombre, descripcion, slug, precio, imagen) VALUES (?, ?, ?, ?, ?)");
                    $stmt->execute([$nombre, $descripcion, slugify($nombre), $precio, $imagen]);
                    echo '<div class="text-green-400 mb-2">Juego agregado correctamente.</div>';
                }
                // Borrado de juego
                if (isset($_GET['borrar_juego'])) {
                    $id = intval($_GET['borrar_juego']);
                    $pdo->prepare("DELETE FROM juegos WHERE id = ?")->execute([$id]);
                    echo '<div class="text-green-400 mb-2">Juego eliminado.</div>';
                }
                // Listado de juegos
                $juegos = $pdo->query("SELECT * FROM juegos ORDER BY creado_en DESC")->fetchAll(PDO::FETCH_ASSOC);
                echo '<form method="POST" action="/admin/juegos" enctype="multipart/form-data" class="bg-slate-900 rounded-xl p-8 max-w-lg w-full relative mb-8" style="box-shadow:0 0 2rem #22d3ee33;">';
                echo '<h3 class="text-xl font-bold mb-4 text-cyan-300">Registrar juego</h3>';
                echo '<label class="block text-slate-300 font-medium mb-2"><input type="checkbox" name="popular" value="1" class="accent-cyan-600"> Marcar como popular</label>';
                echo '<input type="text" name="nombre" placeholder="Nombre del juego" class="block w-full text-xl px-4 py-3 rounded bg-gray-800 text-white focus:outline-none focus:ring-2 focus:ring-cyan-400 mb-2" required />';
                echo '<textarea name="descripcion" placeholder="Descripción" class="block w-full text-base px-4 py-3 rounded bg-gray-800 text-white focus:outline-none focus:ring-2 focus:ring-cyan-400 mb-2"></textarea>';
                echo '<label class="block text-slate-300 font-medium mb-2">Imagen principal:';
                echo '<input type="file" name="imagen" accept="image/*" class="block w-full mt-2 mb-2" /></label>';
                echo '<label class="block text-slate-300 font-medium mb-2">Imagen común para paquetes:';
                echo '<input type="file" name="imagen_paquete" accept="image/*" class="block w-full mt-2 mb-2" /></label>';
                echo '<label class="block text-slate-300 font-medium mb-2">Moneda fija o variable:';
                echo '<select name="moneda" class="block w-full text-base px-4 py-3 rounded bg-gray-800 text-white focus:outline-none focus:ring-2 focus:ring-cyan-400 mb-2">';
                echo '<option value="">Moneda variable (usuario elige)</option>';
                echo '<option value="USD">Dólar estadounidense</option>';
                echo '<option value="VES">Bolívares</option>';
                echo '</select></label>';
                echo '<label class="block text-slate-300 font-medium mb-2">Seleccionar características existentes:';
                echo '<select name="caracteristicas[]" multiple class="block w-full text-base px-4 py-3 rounded bg-gray-800 text-white focus:outline-none focus:ring-2 focus:ring-cyan-400 mb-2">';
                echo '<option value="Entrega Inmediata">Entrega Inmediata</option>';
                echo '<option value="Global">Global</option>';
                echo '</select></label>';
                echo '<input type="text" name="nueva_caracteristica" placeholder="Nueva característica" class="block w-full text-base px-4 py-3 rounded bg-gray-800 text-white focus:outline-none focus:ring-2 focus:ring-cyan-400 mb-2" />';
                echo '<button type="submit" class="w-full bg-cyan-600 hover:bg-cyan-700 text-white text-xl px-4 py-4 rounded font-semibold transition">Registrar juego</button>';
                echo '</form>';
                if (count($juegos) === 0) {
                    echo '<div class="text-gray-400">No hay juegos registrados.</div>';
                } else {
                    // Tabla para desktop
                    echo '<div class="overflow-x-auto hidden sm:block">';
                    echo '<table class="w-full text-left text-sm min-w-[800px]">';
                    echo '<thead><tr class="text-cyan-300">'
                        .'<th>Imagen</th>'
                        .'<th>Nombre</th>'
                        .'<th>Popular</th>'
                        .'<th>Imagen Paquete</th>'
                        .'<th>Descripción</th>'
                        .'<th>Moneda</th>'
                        .'<th>Características</th>'
                        .'<th>Acciones</th>'
                        .'</tr></thead><tbody>';
                    foreach ($juegos as $juego) {
                        echo '<tr class="border-b border-gray-700">';
                        // Imagen principal
                        echo '<td>';
                        if (!empty($juego['imagen'])) {
                            $imgSrc = '/' . ltrim($juego['imagen'], '/');
                            echo '<img src="'.htmlspecialchars($imgSrc).'" alt="img" class="w-16 h-16 object-cover rounded" />';
                        } else {
                            echo '<span class="text-gray-400">Sin imagen</span>';
                        }
                        echo '</td>';
                        // Nombre
                        echo '<td>' . htmlspecialchars($juego['nombre']) . '</td>';
                        // Popular
                        echo '<td>' . ((isset($juego['popular']) && $juego['popular']) ? '<span class="text-green-400">★</span>' : '—') . '</td>';
                        // Imagen Paquete
                        echo '<td>';
                        if (!empty($juego['imagen_paquete'])) {
                            $imgPaqSrc = '/' . ltrim($juego['imagen_paquete'], '/');
                            echo '<img src="'.htmlspecialchars($imgPaqSrc).'" alt="img" class="w-16 h-16 object-cover rounded" />';
                        } else {
                            echo '<span class="text-gray-400">Sin imagen</span>';
                        }
                        echo '</td>';
                        // Descripción
                        echo '<td>' . htmlspecialchars($juego['descripcion'] ?? '') . '</td>';
                        // Moneda
                        echo '<td>' . htmlspecialchars($juego['moneda'] ?? '') . '</td>';
                        // Características
                        echo '<td>' . htmlspecialchars($juego['caracteristicas'] ?? '') . '</td>';
                        // Acciones
                        echo '<td>';
                        echo '<a href="?seccion=juegos&editar_juego=' . $juego['id'] . '" class="text-green-400 hover:underline mr-2">Editar</a>';
                        echo '<a href="?seccion=paquetes&juego_id=' . $juego['id'] . '" class="text-cyan-400 hover:underline mr-2">Paquetes</a>';
                        echo '<a href="?seccion=juegos&borrar_juego=' . $juego['id'] . '" class="text-red-400 hover:underline" onclick="return confirm(\'¿Eliminar este juego?\')">Eliminar</a>';
                        echo '</td>';
                        echo '</tr>';
                    }
                    echo '</tbody></table>';
                    echo '</div>';

                    // Cards para móvil
                    echo '<div class="sm:hidden flex flex-col gap-4">';
                    foreach ($juegos as $juego) {
                        echo '<div class="rounded-xl border border-slate-700 bg-gray-900 p-4 flex flex-col gap-2 shadow">';
                        // Imagen principal
                        if (!empty($juego['imagen'])) {
                            $imgSrc = '/' . ltrim($juego['imagen'], '/');
                            echo '<img src="'.htmlspecialchars($imgSrc).'" alt="img" class="w-full h-32 object-cover rounded mb-2" />';
                        } else {
                            echo '<span class="text-gray-400 mb-2">Sin imagen</span>';
                        }
                        echo '<div class="font-bold text-lg text-cyan-200">' . htmlspecialchars($juego['nombre']) . '</div>';
                        if (isset($juego['popular']) && $juego['popular']) {
                            echo '<div class="text-green-400 text-sm">★ Popular</div>';
                        }
                        // Imagen Paquete
                        if (!empty($juego['imagen_paquete'])) {
                            $imgPaqSrc = '/' . ltrim($juego['imagen_paquete'], '/');
                            echo '<img src="'.htmlspecialchars($imgPaqSrc).'" alt="img" class="w-full h-16 object-cover rounded mb-2" />';
                        } else {
                            echo '<span class="text-gray-400 mb-2">Sin imagen de paquete</span>';
                        }
                        echo '<div class="text-sm text-slate-300"><strong>Descripción:</strong> ' . htmlspecialchars($juego['descripcion'] ?? '') . '</div>';
                        echo '<div class="text-sm text-slate-300"><strong>Moneda:</strong> ' . htmlspecialchars($juego['moneda'] ?? '') . '</div>';
                        echo '<div class="text-sm text-slate-300"><strong>Características:</strong> ' . htmlspecialchars($juego['caracteristicas'] ?? '') . '</div>';
                        echo '<div class="flex gap-4 mt-2">';
                        echo '<a href="?seccion=juegos&editar_juego=' . $juego['id'] . '" class="text-green-400 hover:underline">Editar</a>';
                        echo '<a href="?seccion=paquetes&juego_id=' . $juego['id'] . '" class="text-cyan-400 hover:underline">Paquetes</a>';
                        echo '<a href="?seccion=juegos&borrar_juego=' . $juego['id'] . '" class="text-red-400 hover:underline" onclick="return confirm(\'¿Eliminar este juego?\')">Eliminar</a>';
                        echo '</div>';
                        echo '</div>';
                    }
                    echo '</div>';
                }
                break;
            case 'cupones':
                require_once __DIR__ . '/includes/db.php';
                admin_coupon_ensure_points_column($pdo);
                admin_coupon_ensure_game_scope_column($pdo);
                influencer_coupon_ensure_sales_table_pdo($pdo);
                sync_coupon_usage_counts_pdo($pdo);
                backfill_influencer_sales_pdo($pdo);
                backfill_influencer_order_payment_status_pdo($pdo);
                $couponAdminTab = $_GET['tab'] ?? 'cupones';
                if (!in_array($couponAdminTab, ['cupones', 'influencers'], true)) {
                    $couponAdminTab = 'cupones';
                }
                if ($isInfluencerViewer) {
                    $couponAdminTab = 'influencers';
                }
                $cupones = $pdo->query('SELECT * FROM cupones ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC);
                $couponInfluencerSalesCounts = admin_fetch_influencer_sales_counts_by_coupon($pdo);
                $influencerUsers = admin_fetch_influencer_users($pdo);
                $couponGameScopeEnabled = admin_coupons_game_scope_enabled();
                $couponGameOptions = admin_fetch_coupon_game_options($pdo);
                $couponGameOptionsById = [];
                foreach ($couponGameOptions as $couponGameOption) {
                    $couponGameOptionsById[(int) ($couponGameOption['id'] ?? 0)] = $couponGameOption;
                }
                $influencerPaymentFilter = $influencerFiltersEnabled ? admin_normalize_influencer_payment_filter($_GET['filtro_estado_pago'] ?? 'pendiente') : 'todos';
                $influencerDateFrom = $influencerFiltersEnabled ? admin_normalize_date_filter($_GET['fecha_desde'] ?? null) : null;
                $influencerDateTo = $influencerFiltersEnabled ? admin_normalize_date_filter($_GET['fecha_hasta'] ?? null) : null;
                $selectedInfluencerFilterId = ($isInfluencerViewer || !$influencerFiltersEnabled) ? '' : admin_normalize_influencer_user_filter($_GET['filtro_influencer_usuario'] ?? '', $influencerUsers);
                $selectedInfluencerFilterUser = null;
                if ($selectedInfluencerFilterId !== '') {
                    foreach ($influencerUsers as $influencerUser) {
                        if ((string) ($influencerUser['id'] ?? '') === $selectedInfluencerFilterId) {
                            $selectedInfluencerFilterUser = $influencerUser;
                            break;
                        }
                    }
                }
                $influencerSalesDataset = admin_fetch_influencer_sales_dataset(
                    $pdo,
                    $influencerUsers,
                    $isInfluencerViewer,
                    $adminUser,
                    $influencerDateFrom,
                    $influencerDateTo,
                    $selectedInfluencerFilterId,
                    $influencerPaymentFilter
                );
                $influencerSalesAllStates = $influencerSalesDataset['allStates'];
                $influencerSalesTotals = $influencerSalesDataset['totals'];
                $influencerSales = $influencerSalesDataset['sales'];
                $edit_cupon = null;
                if (isset($_GET['editar_cupon'])) {
                    $edit_id = intval($_GET['editar_cupon']);
                    foreach ($cupones as $cupon) {
                        if ((int) $cupon['id'] === $edit_id) {
                            $edit_cupon = $cupon;
                            break;
                        }
                    }
                    $couponAdminTab = 'cupones';
                }
                $couponTabLink = '?seccion=cupones&tab=cupones';
                $influencerTabLink = '?seccion=cupones&tab=influencers';
                $selectedInfluencerUserId = admin_match_coupon_influencer_user_id($influencerUsers, $edit_cupon);
                $selectedCouponGameIds = $edit_cupon ? admin_coupon_game_ids_from_storage($edit_cupon['juegos_restringidos_json'] ?? null) : [];
                $currentInfluencerName = $edit_cupon ? trim((string) ($edit_cupon['nombre_influencer'] ?? '')) : '';
                $currentInfluencerPhone = $edit_cupon ? trim((string) ($edit_cupon['telefono_influencer'] ?? '')) : '';
                $currentInfluencerEmail = $edit_cupon ? trim((string) ($edit_cupon['email_influencer'] ?? '')) : '';
                $hasLegacyInfluencer = $selectedInfluencerUserId === '' && ($currentInfluencerName !== '' || $currentInfluencerPhone !== '' || $currentInfluencerEmail !== '');
                $winPointsCouponToggleEnabled = admin_coupons_win_points_enabled();
                $selectedInfluencerFilterLabel = 'Todos los influencers';
                if ($isInfluencerViewer) {
                    $selectedInfluencerFilterLabel = trim((string) ($adminUser['full_name'] ?? $adminUser['nombre'] ?? $adminUser['email'] ?? 'Tus comisiones'));
                    if ($selectedInfluencerFilterLabel === '') {
                        $selectedInfluencerFilterLabel = 'Tus comisiones';
                    }
                } elseif ($selectedInfluencerFilterUser) {
                    $selectedInfluencerFilterLabel = trim((string) ($selectedInfluencerFilterUser['nombre'] ?? ''));
                    if ($selectedInfluencerFilterLabel === '') {
                        $selectedInfluencerFilterLabel = trim((string) ($selectedInfluencerFilterUser['email'] ?? ''));
                    }
                    if ($selectedInfluencerFilterLabel === '') {
                        $selectedInfluencerFilterLabel = 'Influencer seleccionado';
                    }
                }
                ?>
                <h2 class="text-center mb-4" style="color:#00fff7;">Gestión de Cupones</h2>

                <?php if (!$isInfluencerViewer): ?>
                <div class="d-flex flex-wrap justify-content-center gap-2 mb-4">
                    <a href="<?= htmlspecialchars($couponTabLink) ?>" class="btn rounded-pill px-4 py-2 fw-semibold <?= $couponAdminTab === 'cupones' ? 'btn-info' : 'btn-outline-info' ?>" style="<?= $couponAdminTab === 'cupones' ? 'background:#00fff7;color:#181f2a;border:2px solid #00fff7;box-shadow:0 0 12px #00fff7;' : 'border:2px solid #00fff7;color:#00fff7;background:#181f2a;' ?>">Cupones</a>
                    <a href="<?= htmlspecialchars($influencerTabLink) ?>" class="btn rounded-pill px-4 py-2 fw-semibold <?= $couponAdminTab === 'influencers' ? 'btn-info' : 'btn-outline-info' ?>" style="<?= $couponAdminTab === 'influencers' ? 'background:#00fff7;color:#181f2a;border:2px solid #00fff7;box-shadow:0 0 12px #00fff7;' : 'border:2px solid #00fff7;color:#00fff7;background:#181f2a;' ?>">Cupones de Influencers</a>
                </div>
                <?php endif; ?>

                <?php if ($couponAdminTab === 'cupones'): ?>
                    <form method="POST" action="" class="row g-3 mb-4" style="background:#181f2a; border-radius:16px; border:2px solid #00fff7; box-shadow:0 0 24px #00fff733; padding:2rem;">
                        <?php if ($edit_cupon): ?>
                            <input type="hidden" name="editar_cupon" value="1">
                            <input type="hidden" name="id" value="<?= htmlspecialchars((string) $edit_cupon['id']) ?>">
                        <?php else: ?>
                            <input type="hidden" name="nuevo_cupon" value="1">
                        <?php endif; ?>
                        <div class="col-12">
                            <h3 class="h5 mb-0" style="color:#00fff7;"><?= $edit_cupon ? 'Editar cupón' : 'Crear cupón' ?></h3>
                            <p class="mb-0 mt-2" style="color:#b2f6ff;">Si completas el bloque del influencer, el cupón generará registros de comisión cuando el pedido pase a pagado o enviado.</p>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" style="color:#00fff7;">Código del cupón</label>
                            <input type="text" name="codigo" value="<?= $edit_cupon ? htmlspecialchars((string) $edit_cupon['codigo']) : '' ?>" required pattern="[A-Za-z0-9]+" inputmode="text" autocomplete="off" autocapitalize="characters" spellcheck="false" oninput="this.value=this.value.replace(/[^A-Za-z0-9]/g,'').toUpperCase()" title="Solo letras y números, sin espacios, acentos ni caracteres especiales." class="form-control" style="background:#222c3a; color:#00fff7; border:1px solid #00fff7;">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" style="color:#00fff7;">Tipo de descuento</label>
                            <select name="tipo_descuento" class="form-select" style="background:#222c3a; color:#00fff7; border:1px solid #00fff7;">
                                <option value="porcentaje" <?= $edit_cupon && ($edit_cupon['tipo_descuento'] ?? '') === 'porcentaje' ? 'selected' : '' ?>>Porcentaje (%)</option>
                                <option value="fijo" <?= $edit_cupon && ($edit_cupon['tipo_descuento'] ?? '') === 'fijo' ? 'selected' : '' ?>>Monto fijo</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" style="color:#00fff7;">Valor del descuento</label>
                            <input type="number" name="valor_descuento" step="0.01" min="0" value="<?= $edit_cupon ? htmlspecialchars((string) $edit_cupon['valor_descuento']) : '' ?>" required class="form-control" style="background:#222c3a; color:#00fff7; border:1px solid #00fff7;">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" style="color:#00fff7;">Fecha expiración</label>
                            <input type="datetime-local" name="fecha_expiracion" value="<?= $edit_cupon && !empty($edit_cupon['fecha_expiracion']) ? htmlspecialchars(date('Y-m-d\TH:i', strtotime((string) $edit_cupon['fecha_expiracion']))) : '' ?>" class="form-control" style="background:#222c3a; color:#00fff7; border:1px solid #00fff7;">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" style="color:#00fff7;">Límite de usos</label>
                            <input type="number" name="limite_usos" min="0" value="<?= $edit_cupon ? htmlspecialchars((string) ($edit_cupon['limite_usos'] ?? '')) : '' ?>" placeholder="0 = ilimitado" class="form-control" style="background:#222c3a; color:#00fff7; border:1px solid #00fff7;">
                        </div>
                        <div class="col-md-4 d-flex align-items-end">
                            <div class="form-check">
                                <input type="checkbox" name="activo" class="form-check-input" id="activoCheck" <?= $edit_cupon ? (!empty($edit_cupon['activo']) ? 'checked' : '') : 'checked' ?>>
                                <label class="form-check-label" for="activoCheck" style="color:#00fff7;">Cupón activo</label>
                            </div>
                        </div>
                        <?php if ($winPointsCouponToggleEnabled): ?>
                        <div class="col-md-4 d-flex align-items-end">
                            <div class="form-check">
                                <input type="checkbox" name="permitir_acumular_puntos" class="form-check-input" id="permitirAcumularPuntosCheck" <?= $edit_cupon ? (!empty($edit_cupon['permitir_acumular_puntos']) ? 'checked' : '') : 'checked' ?>>
                                <label class="form-check-label" for="permitirAcumularPuntosCheck" style="color:#00fff7;">Permitir Acumular Puntos</label>
                            </div>
                        </div>
                        <?php endif; ?>
                        <?php if ($couponGameScopeEnabled): ?>
                        <div class="col-12">
                            <label class="form-label" style="color:#00fff7;">Juegos permitidos</label>
                            <div data-coupon-game-picker="1">
                                <select name="juegos_restringidos[]" multiple data-coupon-game-native="1" style="display:none;">
                                    <?php foreach ($couponGameOptions as $couponGameOption): ?>
                                        <?php $couponGameOptionId = (int) ($couponGameOption['id'] ?? 0); ?>
                                        <option value="<?= htmlspecialchars((string) $couponGameOptionId) ?>" <?= in_array($couponGameOptionId, $selectedCouponGameIds, true) ? 'selected' : '' ?>><?= htmlspecialchars((string) ($couponGameOption['nombre'] ?? ('Juego #' . $couponGameOptionId))) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <div data-coupon-game-control="1" class="form-control d-flex flex-wrap align-items-center gap-2" style="background:#222c3a; color:#00fff7; border:1px solid #00fff7; min-height:58px; padding:0.6rem 0.75rem; cursor:text;">
                                    <div data-coupon-game-tags="1" class="d-flex flex-wrap align-items-center gap-2" style="flex:1 1 auto;"></div>
                                    <input type="text" data-coupon-game-input="1" autocomplete="off" placeholder="Escribe para buscar o haz clic para ver todos" style="flex:1 1 220px; min-width:220px; background:transparent; border:none; outline:none; box-shadow:none; color:#00fff7; padding:0;">
                                    <button type="button" data-coupon-game-toggle="1" aria-label="Mostrar juegos" style="background:transparent; border:none; color:#00fff7; font-size:1.1rem; line-height:1; padding:0 0.15rem;">▾</button>
                                </div>
                                <div data-coupon-game-dropdown="1" style="display:none; margin-top:0.5rem; max-height:220px; overflow:auto; background:#1b2430; border:1px solid #00fff7; border-radius:12px; box-shadow:0 14px 30px rgba(0, 255, 247, 0.18);"></div>
                            </div>
                            <div class="mt-2" style="color:#7dd3fc; font-size:0.95rem;">Selecciona uno o varios juegos. Si no eliges ninguno, el cupón funcionará normalmente en todos los juegos.</div>
                            <script>
                            (() => {
                                const widgets = document.querySelectorAll('[data-coupon-game-picker="1"]');
                                widgets.forEach((root) => {
                                    if (root.dataset.initialized === '1') {
                                        return;
                                    }
                                    root.dataset.initialized = '1';

                                    const nativeSelect = root.querySelector('[data-coupon-game-native="1"]');
                                    const control = root.querySelector('[data-coupon-game-control="1"]');
                                    const tagsContainer = root.querySelector('[data-coupon-game-tags="1"]');
                                    const input = root.querySelector('[data-coupon-game-input="1"]');
                                    const toggle = root.querySelector('[data-coupon-game-toggle="1"]');
                                    const dropdown = root.querySelector('[data-coupon-game-dropdown="1"]');
                                    if (!nativeSelect || !control || !tagsContainer || !input || !toggle || !dropdown) {
                                        return;
                                    }

                                    const normalize = (value) => value.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
                                    let isOpen = false;

                                    const getOptions = () => Array.from(nativeSelect.options);

                                    const renderTags = () => {
                                        tagsContainer.innerHTML = '';
                                        getOptions().filter((option) => option.selected).forEach((option) => {
                                            const tag = document.createElement('span');
                                            tag.style.cssText = 'display:inline-flex;align-items:center;gap:0.45rem;background:#00fff7;color:#0f172a;border-radius:999px;padding:0.32rem 0.72rem;font-size:0.92rem;font-weight:600;';
                                            tag.textContent = option.text;

                                            const removeButton = document.createElement('button');
                                            removeButton.type = 'button';
                                            removeButton.textContent = 'x';
                                            removeButton.setAttribute('aria-label', 'Quitar ' + option.text);
                                            removeButton.style.cssText = 'background:rgba(15,23,42,0.22);border:none;border-radius:999px;color:#0f172a;font-weight:700;line-height:1;padding:0.1rem 0.38rem;cursor:pointer;';
                                            removeButton.addEventListener('click', (event) => {
                                                event.preventDefault();
                                                event.stopPropagation();
                                                option.selected = false;
                                                render();
                                                openDropdown();
                                                input.focus();
                                            });

                                            tag.appendChild(removeButton);
                                            tagsContainer.appendChild(tag);
                                        });
                                    };

                                    const renderDropdown = () => {
                                        dropdown.innerHTML = '';
                                        if (!isOpen) {
                                            dropdown.style.display = 'none';
                                            return;
                                        }

                                        const query = normalize(input.value.trim());
                                        const visibleOptions = getOptions().filter((option) => !option.selected && (query === '' || normalize(option.text).includes(query)));

                                        if (visibleOptions.length === 0) {
                                            const emptyState = document.createElement('div');
                                            emptyState.textContent = 'No hay juegos que coincidan con la búsqueda.';
                                            emptyState.style.cssText = 'padding:0.8rem 1rem;color:#7dd3fc;';
                                            dropdown.appendChild(emptyState);
                                        } else {
                                            visibleOptions.forEach((option) => {
                                                const optionButton = document.createElement('button');
                                                optionButton.type = 'button';
                                                optionButton.textContent = option.text;
                                                optionButton.style.cssText = 'display:block;width:100%;text-align:left;background:transparent;border:none;color:#00fff7;padding:0.72rem 0.95rem;cursor:pointer;';
                                                optionButton.addEventListener('mouseenter', () => {
                                                    optionButton.style.background = 'rgba(0,255,247,0.12)';
                                                });
                                                optionButton.addEventListener('mouseleave', () => {
                                                    optionButton.style.background = 'transparent';
                                                });
                                                optionButton.addEventListener('click', () => {
                                                    option.selected = true;
                                                    input.value = '';
                                                    render();
                                                    openDropdown();
                                                    input.focus();
                                                });
                                                dropdown.appendChild(optionButton);
                                            });
                                        }

                                        dropdown.style.display = 'block';
                                    };

                                    const render = () => {
                                        renderTags();
                                        renderDropdown();
                                    };

                                    const openDropdown = () => {
                                        isOpen = true;
                                        renderDropdown();
                                    };

                                    const closeDropdown = () => {
                                        isOpen = false;
                                        renderDropdown();
                                    };

                                    control.addEventListener('click', () => {
                                        input.focus();
                                        openDropdown();
                                    });

                                    toggle.addEventListener('click', (event) => {
                                        event.preventDefault();
                                        event.stopPropagation();
                                        if (isOpen) {
                                            closeDropdown();
                                        } else {
                                            input.focus();
                                            openDropdown();
                                        }
                                    });

                                    input.addEventListener('focus', openDropdown);
                                    input.addEventListener('input', openDropdown);
                                    input.addEventListener('keydown', (event) => {
                                        if (event.key === 'Backspace' && input.value === '') {
                                            const selectedOptions = getOptions().filter((option) => option.selected);
                                            const lastSelected = selectedOptions[selectedOptions.length - 1];
                                            if (lastSelected) {
                                                lastSelected.selected = false;
                                                render();
                                            }
                                            return;
                                        }

                                        if (event.key === 'Enter') {
                                            const firstVisibleOption = getOptions().find((option) => {
                                                return !option.selected && (input.value.trim() === '' || normalize(option.text).includes(normalize(input.value.trim())));
                                            });
                                            if (firstVisibleOption) {
                                                event.preventDefault();
                                                firstVisibleOption.selected = true;
                                                input.value = '';
                                                render();
                                                openDropdown();
                                            }
                                        }

                                        if (event.key === 'Escape') {
                                            closeDropdown();
                                        }
                                    });

                                    document.addEventListener('click', (event) => {
                                        if (!root.contains(event.target)) {
                                            closeDropdown();
                                        }
                                    });

                                    render();
                                });
                            })();
                            </script>
                        </div>
                        <?php endif; ?>
                        <div class="col-12 mt-2">
                            <div style="background:#0f172a; border:1px solid rgba(0,255,247,0.3); border-radius:16px; padding:1.25rem;">
                                <div class="d-flex flex-column flex-xl-row justify-content-between align-items-xl-end gap-3 mb-3">
                                    <div>
                                        <h4 class="h6 mb-1" style="color:#00fff7;">Configuración del influencer</h4>
                                        <p class="mb-0" style="color:#7dd3fc; font-size:0.95rem;">Selecciona un usuario influencer registrado para completar sus datos automáticamente.</p>
                                    </div>
                                    <div class="row g-2" style="width:min(100%, 520px);">
                                        <div class="col-12 col-md-6">
                                            <label class="form-label mb-1" style="color:#00fff7;">Buscar influencer</label>
                                            <input type="text" data-influencer-user-search="1" class="form-control form-control-sm" placeholder="Nombre, correo o teléfono" style="background:#222c3a; color:#00fff7; border:1px solid #00fff7;">
                                        </div>
                                        <div class="col-12 col-md-6">
                                            <label class="form-label mb-1" style="color:#00fff7;">Influencer registrado</label>
                                            <select name="influencer_user_id" data-influencer-user-select="1" class="form-select form-select-sm" style="background:#222c3a; color:#00fff7; border:1px solid #00fff7;">
                                                <option value="">Selecciona un influencer</option>
                                                <?php if ($hasLegacyInfluencer): ?>
                                                    <option value="legacy" data-name="<?= htmlspecialchars($currentInfluencerName, ENT_QUOTES, 'UTF-8') ?>" data-phone="<?= htmlspecialchars($currentInfluencerPhone, ENT_QUOTES, 'UTF-8') ?>" data-email="<?= htmlspecialchars($currentInfluencerEmail, ENT_QUOTES, 'UTF-8') ?>" selected>Actual: <?= htmlspecialchars($currentInfluencerName !== '' ? $currentInfluencerName : ($currentInfluencerEmail !== '' ? $currentInfluencerEmail : 'Influencer actual')) ?></option>
                                                <?php endif; ?>
                                                <?php foreach ($influencerUsers as $influencerUser): ?>
                                                    <?php
                                                    $optionId = (string) ($influencerUser['id'] ?? '');
                                                    $optionName = trim((string) ($influencerUser['nombre'] ?? ''));
                                                    $optionEmail = trim((string) ($influencerUser['email'] ?? ''));
                                                    $optionPhone = trim((string) ($influencerUser['telefono'] ?? ''));
                                                    $optionLabel = $optionName !== '' ? $optionName : $optionEmail;
                                                    if ($optionEmail !== '' && $optionEmail !== $optionLabel) {
                                                        $optionLabel .= ' | ' . $optionEmail;
                                                    }
                                                    if ($optionPhone !== '') {
                                                        $optionLabel .= ' | ' . $optionPhone;
                                                    }
                                                    ?>
                                                    <option value="<?= htmlspecialchars($optionId) ?>" data-name="<?= htmlspecialchars($optionName, ENT_QUOTES, 'UTF-8') ?>" data-phone="<?= htmlspecialchars($optionPhone, ENT_QUOTES, 'UTF-8') ?>" data-email="<?= htmlspecialchars($optionEmail, ENT_QUOTES, 'UTF-8') ?>" <?= $selectedInfluencerUserId === $optionId ? 'selected' : '' ?>><?= htmlspecialchars($optionLabel) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                                <div class="row g-3">
                                    <div class="col-md-4">
                                        <label class="form-label" style="color:#00fff7;">Nombre influencer</label>
                                        <input type="text" name="nombre_influencer" data-influencer-target="name" value="<?= $edit_cupon ? htmlspecialchars((string) ($edit_cupon['nombre_influencer'] ?? '')) : '' ?>" readonly class="form-control" style="background:#1b2430; color:#00fff7; border:1px solid #00fff7;">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label" style="color:#00fff7;">Teléfono influencer</label>
                                        <input type="text" name="telefono_influencer" data-influencer-target="phone" value="<?= $edit_cupon ? htmlspecialchars((string) ($edit_cupon['telefono_influencer'] ?? '')) : '' ?>" readonly class="form-control" style="background:#1b2430; color:#00fff7; border:1px solid #00fff7;">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label" style="color:#00fff7;">Correo influencer</label>
                                        <input type="email" name="email_influencer" data-influencer-target="email" value="<?= $edit_cupon ? htmlspecialchars((string) ($edit_cupon['email_influencer'] ?? '')) : '' ?>" readonly class="form-control" style="background:#1b2430; color:#00fff7; border:1px solid #00fff7;">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label" style="color:#00fff7;">Comisión influencer (%)</label>
                                        <input type="number" name="comision_influencer" step="0.01" min="0" max="100" value="<?= $edit_cupon ? htmlspecialchars((string) ($edit_cupon['comision_influencer'] ?? '0')) : '0' ?>" class="form-control" style="background:#222c3a; color:#00fff7; border:1px solid #00fff7;">
                                    </div>
                                </div>
                                <script>
                                (() => {
                                    const searchInput = document.querySelector('[data-influencer-user-search="1"]');
                                    const select = document.querySelector('[data-influencer-user-select="1"]');
                                    const nameField = document.querySelector('[data-influencer-target="name"]');
                                    const phoneField = document.querySelector('[data-influencer-target="phone"]');
                                    const emailField = document.querySelector('[data-influencer-target="email"]');
                                    if (!searchInput || !select || !nameField || !phoneField || !emailField) {
                                        return;
                                    }

                                    const optionData = Array.from(select.options).map((option) => ({
                                        value: option.value,
                                        label: option.textContent || '',
                                        name: option.dataset.name || '',
                                        phone: option.dataset.phone || '',
                                        email: option.dataset.email || '',
                                        search: `${option.textContent || ''} ${option.dataset.name || ''} ${option.dataset.phone || ''} ${option.dataset.email || ''}`.toLowerCase(),
                                    }));

                                    const rebuildOptions = () => {
                                        const query = searchInput.value.trim().toLowerCase();
                                        const currentValue = select.value;
                                        const filteredOptions = optionData.filter((option) => option.value === '' || option.value === currentValue || option.search.includes(query));

                                        select.innerHTML = '';
                                        filteredOptions.forEach((option) => {
                                            const optionElement = document.createElement('option');
                                            optionElement.value = option.value;
                                            optionElement.textContent = option.label;
                                            if (option.name) {
                                                optionElement.dataset.name = option.name;
                                            }
                                            if (option.phone) {
                                                optionElement.dataset.phone = option.phone;
                                            }
                                            if (option.email) {
                                                optionElement.dataset.email = option.email;
                                            }
                                            if (option.value === currentValue) {
                                                optionElement.selected = true;
                                            }
                                            select.appendChild(optionElement);
                                        });
                                    };

                                    const applySelectedUser = () => {
                                        const selectedOption = select.options[select.selectedIndex];
                                        if (!selectedOption || !selectedOption.value) {
                                            nameField.value = '';
                                            phoneField.value = '';
                                            emailField.value = '';
                                            return;
                                        }

                                        nameField.value = selectedOption.dataset.name || '';
                                        phoneField.value = selectedOption.dataset.phone || '';
                                        emailField.value = selectedOption.dataset.email || '';
                                    };

                                    searchInput.addEventListener('input', () => {
                                        rebuildOptions();
                                        applySelectedUser();
                                    });
                                    select.addEventListener('change', applySelectedUser);
                                    applySelectedUser();
                                })();
                                </script>
                            </div>
                        </div>
                        <div class="col-12 d-flex flex-column flex-md-row gap-2">
                            <button type="submit" class="btn btn-info flex-fill" style="background:#00fff7; color:#222; border:none; box-shadow:0 0 8px #00fff7;"><?= $edit_cupon ? 'Guardar cambios' : 'Crear cupón' ?></button>
                            <?php if ($edit_cupon): ?>
                                <a href="<?= htmlspecialchars($couponTabLink) ?>" class="btn btn-outline-light flex-fill">Cancelar edición</a>
                            <?php endif; ?>
                        </div>
                    </form>

                    <h3 class="text-info mt-5 mb-3">Cupones existentes</h3>
                    <div class="table-responsive d-none d-md-block">
                        <table class="table align-middle" style="background:#181f2a; color:#00fff7; border-radius:12px;">
                            <thead style="background:#181f2a; color:#00fff7; border-bottom:2px solid #00fff7;">
                                <tr>
                                    <th style="color:#00fff7; background:#181f2a;">Código</th>
                                    <th style="color:#00fff7; background:#181f2a;">Descuento</th>
                                    <?php if ($couponGameScopeEnabled): ?>
                                    <th style="color:#00fff7; background:#181f2a;">Juegos</th>
                                    <?php endif; ?>
                                    <th style="color:#00fff7; background:#181f2a;">Influencer</th>
                                    <th style="color:#00fff7; background:#181f2a;">Comisión</th>
                                    <?php if ($winPointsCouponToggleEnabled): ?>
                                    <th style="color:#00fff7; background:#181f2a;">Acumula puntos</th>
                                    <?php endif; ?>
                                    <th style="color:#00fff7; background:#181f2a;">Usos</th>
                                    <th style="color:#00fff7; background:#181f2a;">Activo</th>
                                    <th style="color:#00fff7; background:#181f2a;">Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($cupones as $c): ?>
                                    <?php $displayUsageCount = influencer_coupon_has_owner($c) ? (int) ($couponInfluencerSalesCounts[(int) ($c['id'] ?? 0)] ?? 0) : (int) ($c['usos_actuales'] ?? 0); ?>
                                    <tr style="background:#181f2a; color:#fff;">
                                        <td style="background:#181f2a; color:#00fff7; font-weight:bold;">
                                            <div><?= htmlspecialchars((string) $c['codigo']) ?></div>
                                            <div style="color:#b2f6ff; font-size:0.9em;">ID: <?= htmlspecialchars((string) $c['id']) ?></div>
                                        </td>
                                        <td style="background:#181f2a; color:#b2f6ff;">
                                            <div><?= htmlspecialchars((string) $c['tipo_descuento']) ?></div>
                                            <div><?= htmlspecialchars((string) $c['valor_descuento']) ?></div>
                                        </td>
                                        <?php if ($couponGameScopeEnabled): ?>
                                        <?php
                                        $couponGameLabels = admin_coupon_game_labels(admin_coupon_game_ids_from_storage($c['juegos_restringidos_json'] ?? null), $couponGameOptionsById);
                                        $couponGamePreview = array_slice($couponGameLabels, 0, 2);
                                        $couponGameHasMore = count($couponGameLabels) > 2;
                                        $couponGameLabelsJson = htmlspecialchars((string) json_encode($couponGameLabels, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8');
                                        ?>
                                        <td style="background:#181f2a; color:#b2f6ff; min-width:220px;">
                                            <?php foreach ($couponGamePreview as $couponGameLabel): ?>
                                            <div><?= htmlspecialchars($couponGameLabel) ?></div>
                                            <?php endforeach; ?>
                                            <?php if ($couponGameHasMore): ?>
                                            <button type="button" class="btn btn-sm btn-outline-info mt-2" data-coupon-games-modal="1" data-coupon-games='<?= $couponGameLabelsJson ?>' style="border-color:#00fff7; color:#00fff7;">Ver más</button>
                                            <?php endif; ?>
                                        </td>
                                        <?php endif; ?>
                                        <td style="background:#181f2a; color:#b2f6ff;">
                                            <div><?= htmlspecialchars(admin_display_value($c['nombre_influencer'] ?? null)) ?></div>
                                            <div style="color:#8bd3ff; font-size:0.9em;"><?= htmlspecialchars(admin_display_value($c['email_influencer'] ?? null)) ?></div>
                                        </td>
                                        <td style="background:#181f2a; color:#b2f6ff;"><?= htmlspecialchars(admin_display_value(isset($c['comision_influencer']) && (float) $c['comision_influencer'] > 0 ? admin_format_money($c['comision_influencer']) . '%' : null)) ?></td>
                                        <?php if ($winPointsCouponToggleEnabled): ?>
                                        <td style="background:#181f2a; color:#b2f6ff;"><?= !empty($c['permitir_acumular_puntos']) ? 'Sí' : 'No' ?></td>
                                        <?php endif; ?>
                                        <td style="background:#181f2a; color:#b2f6ff;"><?= htmlspecialchars((string) $displayUsageCount) ?> / <?= htmlspecialchars(admin_display_value($c['limite_usos'] ?? null, '∞')) ?></td>
                                        <td style="background:#181f2a; color:#b2f6ff;"><?= !empty($c['activo']) ? 'Sí' : 'No' ?></td>
                                        <td style="background:#181f2a;">
                                            <a href="?seccion=cupones&tab=cupones&editar_cupon=<?= urlencode((string) $c['id']) ?>" style="color:#00fff7; text-decoration:underline; margin-right:1em;">Editar</a>
                                            <a href="?seccion=cupones&tab=cupones&toggle_cupon=<?= urlencode((string) $c['id']) ?>" style="color:#00fff7; text-decoration:underline; margin-right:1em;"><?= !empty($c['activo']) ? 'Desactivar' : 'Activar' ?></a>
                                            <a href="?seccion=cupones&tab=cupones&borrar_cupon=<?= urlencode((string) $c['id']) ?>" style="color:#ff0059; text-decoration:underline;" onclick="return confirm('¿Eliminar este cupón?')">Eliminar</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if ($couponGameScopeEnabled): ?>
                    <div id="coupon-games-modal" style="display:none; position:fixed; inset:0; background:rgba(2,6,23,0.78); z-index:1055; padding:1rem; align-items:center; justify-content:center;">
                        <div style="width:min(100%, 520px); background:#181f2a; border:1px solid #00fff7; border-radius:18px; box-shadow:0 18px 50px rgba(0,255,247,0.2); overflow:hidden;">
                            <div class="d-flex align-items-center justify-content-between" style="padding:1rem 1.25rem; border-bottom:1px solid rgba(0,255,247,0.2);">
                                <h4 class="mb-0" style="color:#00fff7; font-size:1.1rem;">Juegos activos del cupón</h4>
                                <button type="button" id="coupon-games-modal-close" style="background:transparent; border:none; color:#00fff7; font-size:1.4rem; line-height:1;">×</button>
                            </div>
                            <div id="coupon-games-modal-body" style="padding:1rem 1.25rem; max-height:60vh; overflow:auto; color:#b2f6ff;"></div>
                        </div>
                    </div>
                    <script>
                    (() => {
                        const modal = document.getElementById('coupon-games-modal');
                        const modalBody = document.getElementById('coupon-games-modal-body');
                        const closeButton = document.getElementById('coupon-games-modal-close');
                        if (!modal || !modalBody || !closeButton) {
                            return;
                        }

                        const closeModal = () => {
                            modal.style.display = 'none';
                            modalBody.innerHTML = '';
                        };

                        document.querySelectorAll('[data-coupon-games-modal="1"]').forEach((button) => {
                            button.addEventListener('click', () => {
                                let labels = [];
                                try {
                                    labels = JSON.parse(button.getAttribute('data-coupon-games') || '[]');
                                } catch (error) {
                                    labels = [];
                                }

                                modalBody.innerHTML = '';
                                const list = document.createElement('div');
                                list.style.display = 'grid';
                                list.style.gap = '0.6rem';

                                labels.forEach((label) => {
                                    const item = document.createElement('div');
                                    item.textContent = label;
                                    item.style.cssText = 'padding:0.7rem 0.85rem;border:1px solid rgba(0,255,247,0.2);border-radius:12px;background:#0f172a;';
                                    list.appendChild(item);
                                });

                                modalBody.appendChild(list);
                                modal.style.display = 'flex';
                            });
                        });

                        closeButton.addEventListener('click', closeModal);
                        modal.addEventListener('click', (event) => {
                            if (event.target === modal) {
                                closeModal();
                            }
                        });
                        document.addEventListener('keydown', (event) => {
                            if (event.key === 'Escape' && modal.style.display === 'flex') {
                                closeModal();
                            }
                        });
                    })();
                    </script>
                    <?php endif; ?>

                    <div class="d-block d-md-none">
                        <?php foreach ($cupones as $c): ?>
                            <?php $displayUsageCount = influencer_coupon_has_owner($c) ? (int) ($couponInfluencerSalesCounts[(int) ($c['id'] ?? 0)] ?? 0) : (int) ($c['usos_actuales'] ?? 0); ?>
                            <div style="background:#181f2a; border-radius:16px; border:2px solid #00fff7; box-shadow:0 0 24px #00fff733; padding:1rem; color:#00fff7; margin-bottom:1.2rem;">
                                <div style="font-weight:bold; font-size:1.15em; color:#00fff7;"><?= htmlspecialchars((string) $c['codigo']) ?></div>
                                <div style="margin-top:0.45rem; color:#b2f6ff;">Tipo: <?= htmlspecialchars((string) $c['tipo_descuento']) ?> | Valor: <?= htmlspecialchars((string) $c['valor_descuento']) ?></div>
                                <?php if ($couponGameScopeEnabled): ?>
                                <div style="margin-top:0.45rem; color:#b2f6ff;">Juegos: <?= htmlspecialchars(admin_coupon_game_ids_label(admin_coupon_game_ids_from_storage($c['juegos_restringidos_json'] ?? null), $couponGameOptionsById)) ?></div>
                                <?php endif; ?>
                                <div style="margin-top:0.45rem; color:#b2f6ff;">
                                    <div>Influencer: <?= htmlspecialchars(admin_display_value($c['nombre_influencer'] ?? null)) ?></div>
                                    <div style="color:#8bd3ff; font-size:0.9em;"><?= htmlspecialchars(admin_display_value($c['email_influencer'] ?? null)) ?></div>
                                </div>
                                <div style="margin-top:0.45rem; color:#b2f6ff;">Comisión: <?= htmlspecialchars(admin_display_value(isset($c['comision_influencer']) && (float) $c['comision_influencer'] > 0 ? admin_format_money($c['comision_influencer']) . '%' : null)) ?></div>
                                <?php if ($winPointsCouponToggleEnabled): ?>
                                <div style="margin-top:0.45rem; color:#b2f6ff;">Acumula puntos: <?= !empty($c['permitir_acumular_puntos']) ? 'Sí' : 'No' ?></div>
                                <?php endif; ?>
                                <div style="margin-top:0.45rem; color:#b2f6ff;">Usos: <?= htmlspecialchars((string) $displayUsageCount) ?> / <?= htmlspecialchars(admin_display_value($c['limite_usos'] ?? null, '∞')) ?></div>
                                <div style="margin-top:0.45rem; color:#b2f6ff;">Activo: <?= !empty($c['activo']) ? 'Sí' : 'No' ?></div>
                                <div style="display:flex; gap:1rem; margin-top:1rem; flex-wrap:wrap;">
                                    <a href="?seccion=cupones&tab=cupones&editar_cupon=<?= urlencode((string) $c['id']) ?>" style="color:#00fff7; text-decoration:underline; font-weight:bold;">Editar</a>
                                    <a href="?seccion=cupones&tab=cupones&toggle_cupon=<?= urlencode((string) $c['id']) ?>" style="color:#00fff7; text-decoration:underline; font-weight:bold;"><?= !empty($c['activo']) ? 'Desactivar' : 'Activar' ?></a>
                                    <a href="?seccion=cupones&tab=cupones&borrar_cupon=<?= urlencode((string) $c['id']) ?>" style="color:#ff0059; text-decoration:underline; font-weight:bold;" onclick="return confirm('¿Eliminar este cupón?')">Eliminar</a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div style="background:#181f2a; border-radius:16px; border:2px solid #00fff7; box-shadow:0 0 24px #00fff733; padding:1.5rem;">
                        <div class="d-flex justify-content-between align-items-center gap-3 mb-3 flex-wrap">
                            <div>
                                <h3 class="h5 mb-1" style="color:#00fff7;">Cupones de Influencers</h3>
                                <p class="mb-0" style="color:#b2f6ff;"><?= $isInfluencerViewer ? 'Consulta tus ventas confirmadas y el estado de pago de tus comisiones.' : 'Ventas confirmadas con cupones asociados a influencers.' ?></p>
                            </div>
                        </div>
                        <?php if ($influencerFiltersEnabled): ?>
                        <form method="GET" action="" class="row g-3 align-items-end mb-4">
                            <input type="hidden" name="seccion" value="cupones">
                            <input type="hidden" name="tab" value="influencers">
                            <?php if (!$isInfluencerViewer): ?>
                            <div class="col-lg-4 col-md-6">
                                <label class="form-label" style="color:#00fff7;">Filtrar usuario</label>
                                <select name="filtro_influencer_usuario" class="form-select" onchange="this.form.submit()" style="background:#222c3a; color:#00fff7; border:1px solid #00fff7;">
                                    <option value="">Todos los influencers</option>
                                    <?php foreach ($influencerUsers as $influencerUser): ?>
                                        <?php
                                        $filterOptionId = (string) ($influencerUser['id'] ?? '');
                                        $filterOptionName = trim((string) ($influencerUser['nombre'] ?? ''));
                                        $filterOptionEmail = trim((string) ($influencerUser['email'] ?? ''));
                                        $filterOptionPhone = trim((string) ($influencerUser['telefono'] ?? ''));
                                        $filterOptionLabel = $filterOptionName !== '' ? $filterOptionName : $filterOptionEmail;
                                        if ($filterOptionEmail !== '' && $filterOptionEmail !== $filterOptionLabel) {
                                            $filterOptionLabel .= ' | ' . $filterOptionEmail;
                                        }
                                        if ($filterOptionPhone !== '') {
                                            $filterOptionLabel .= ' | ' . $filterOptionPhone;
                                        }
                                        ?>
                                        <option value="<?= htmlspecialchars($filterOptionId) ?>" <?= $selectedInfluencerFilterId === $filterOptionId ? 'selected' : '' ?>><?= htmlspecialchars($filterOptionLabel) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php endif; ?>
                            <div class="<?= $isInfluencerViewer ? 'col-lg-4 col-md-4' : 'col-lg-3 col-md-6' ?>">
                                <label class="form-label" style="color:#00fff7;">Filtrar estado</label>
                                <select name="filtro_estado_pago" class="form-select" onchange="this.form.submit()" style="background:#222c3a; color:#00fff7; border:1px solid #00fff7;">
                                    <option value="pendiente" <?= $influencerPaymentFilter === 'pendiente' ? 'selected' : '' ?>>Mostrar Solo Pendientes</option>
                                    <option value="pagado" <?= $influencerPaymentFilter === 'pagado' ? 'selected' : '' ?>>Mostrar Solo Pagados</option>
                                    <option value="todos" <?= $influencerPaymentFilter === 'todos' ? 'selected' : '' ?>>Mostrar Todos</option>
                                </select>
                            </div>
                            <div class="<?= $isInfluencerViewer ? 'col-lg-3 col-md-4' : 'col-lg-2 col-md-6' ?>">
                                <label class="form-label" style="color:#00fff7;">Desde</label>
                                <input type="date" name="fecha_desde" value="<?= htmlspecialchars((string) ($influencerDateFrom ?? '')) ?>" class="form-control" style="background:#222c3a; color:#00fff7; border:1px solid #00fff7;">
                            </div>
                            <div class="<?= $isInfluencerViewer ? 'col-lg-3 col-md-4' : 'col-lg-2 col-md-6' ?>">
                                <label class="form-label" style="color:#00fff7;">Hasta</label>
                                <input type="date" name="fecha_hasta" value="<?= htmlspecialchars((string) ($influencerDateTo ?? '')) ?>" class="form-control" style="background:#222c3a; color:#00fff7; border:1px solid #00fff7;">
                            </div>
                            <div class="col-12 d-flex gap-2 justify-content-md-end">
                                <button type="submit" class="btn btn-info flex-fill" style="background:#00fff7; color:#181f2a; border:none; box-shadow:0 0 8px #00fff7;">Filtrar</button>
                                <a href="<?= htmlspecialchars($influencerTabLink) ?>" class="btn btn-outline-info flex-fill" style="border:1px solid #00fff7; color:#00fff7;">Limpiar</a>
                            </div>
                        </form>
                        <?php
                        $influencerSummaryCards = [
                            'pendiente' => ['title' => 'Pendientes', 'accent' => '#f59e0b', 'shadow' => 'rgba(245, 158, 11, 0.28)'],
                            'pagado' => ['title' => 'Pagados', 'accent' => '#22c55e', 'shadow' => 'rgba(34, 197, 94, 0.24)'],
                            'todos' => ['title' => 'Todos', 'accent' => '#00fff7', 'shadow' => 'rgba(0, 255, 247, 0.22)'],
                        ];
                        ?>
                        <div class="mb-3" style="color:#7dd3fc;">
                            Resumen actual: <?= htmlspecialchars($selectedInfluencerFilterLabel) ?>
                        </div>
                        <div class="row g-3 mb-4">
                            <?php foreach ($influencerSummaryCards as $summaryKey => $summaryConfig): ?>
                                <?php $summaryBucket = $influencerSalesTotals[$summaryKey] ?? ['count' => 0, 'amounts' => []]; ?>
                                <div class="col-12 col-md-4">
                                    <div style="height:100%; background:linear-gradient(135deg, rgba(15,23,42,0.96), rgba(24,31,42,0.92)); border:1px solid <?= htmlspecialchars($summaryConfig['accent']) ?>; border-radius:16px; padding:1rem 1.1rem; box-shadow:0 0 20px <?= htmlspecialchars($summaryConfig['shadow']) ?>;">
                                        <div style="font-size:0.82rem; letter-spacing:0.08em; text-transform:uppercase; color:<?= htmlspecialchars($summaryConfig['accent']) ?>; font-weight:700;"><?= htmlspecialchars($summaryConfig['title']) ?></div>
                                        <div style="margin-top:0.55rem; color:#ffffff; font-size:1.2rem; font-weight:700;"><?= htmlspecialchars(admin_format_influencer_total_amounts($summaryBucket['amounts'] ?? [])) ?></div>
                                        <div style="margin-top:0.35rem; color:#b2f6ff; font-size:0.92rem;"><?= (int) ($summaryBucket['count'] ?? 0) ?> venta(s)</div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <?php if (!$isInfluencerViewer && $influencerPaymentFilter === 'pendiente' && !empty($influencerSales)): ?>
                        <form method="POST" action="" class="mb-4" onsubmit="return confirm('¿Cambiar estos pendientes visibles a pagado?');">
                            <input type="hidden" name="actualizar_pendientes_filtrados_influencer" value="1">
                            <input type="hidden" name="filtro_estado_pago" value="<?= htmlspecialchars($influencerPaymentFilter) ?>">
                            <input type="hidden" name="fecha_desde" value="<?= htmlspecialchars((string) ($influencerDateFrom ?? '')) ?>">
                            <input type="hidden" name="fecha_hasta" value="<?= htmlspecialchars((string) ($influencerDateTo ?? '')) ?>">
                            <input type="hidden" name="filtro_influencer_usuario" value="<?= htmlspecialchars($selectedInfluencerFilterId) ?>">
                            <button type="submit" class="btn btn-warning fw-semibold" style="border:none; color:#181f2a; box-shadow:0 0 18px rgba(245,158,11,0.28);">
                                Cambiar estos Pendientes a Pagado
                            </button>
                        </form>
                        <?php endif; ?>
                        <?php endif; ?>
                        <?php if (empty($influencerSales)): ?>
                            <p class="mb-0" style="color:#b2f6ff;">Aún no hay ventas registradas para cupones de influencers.</p>
                        <?php else: ?>
                            <div class="table-responsive d-none d-md-block">
                                <table class="table align-middle" style="background:#181f2a; color:#00fff7; border-radius:12px;">
                                    <thead style="background:#181f2a; color:#00fff7; border-bottom:2px solid #00fff7;">
                                        <tr>
                                            <th style="color:#00fff7; background:#181f2a;">Nombre Influencer</th>
                                            <th style="color:#00fff7; background:#181f2a;">Cupón</th>
                                            <th style="color:#00fff7; background:#181f2a;">Teléfono</th>
                                            <th style="color:#00fff7; background:#181f2a;">Correo</th>
                                            <th style="color:#00fff7; background:#181f2a;">Comisión</th>
                                            <th style="color:#00fff7; background:#181f2a;">Paquete Vendido</th>
                                            <th style="color:#00fff7; background:#181f2a;">Fecha</th>
                                            <th style="color:#00fff7; background:#181f2a;">Pendiente/Pagado</th>
                                            <th style="color:#00fff7; background:#181f2a;">Total</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($influencerSales as $sale): ?>
                                            <tr style="background:#181f2a; color:#fff;">
                                                <td style="background:#181f2a; color:#00fff7; font-weight:bold;"><?= htmlspecialchars(admin_display_value($sale['nombre_influencer'] ?? null)) ?></td>
                                                <td style="background:#181f2a; color:#b2f6ff;"><?= htmlspecialchars(admin_display_value($sale['codigo_cupon'] ?? null)) ?></td>
                                                <td style="background:#181f2a; color:#b2f6ff;"><?= htmlspecialchars(admin_display_value($sale['telefono_influencer'] ?? null)) ?></td>
                                                <td style="background:#181f2a; color:#b2f6ff;"><?= htmlspecialchars(admin_display_value($sale['email_influencer'] ?? null)) ?></td>
                                                <td style="background:#181f2a; color:#b2f6ff;"><?= htmlspecialchars(admin_format_money($sale['comision_porcentaje'] ?? 0)) ?>%</td>
                                                <td style="background:#181f2a; color:#b2f6ff;">
                                                    <div><?= htmlspecialchars(admin_display_value($sale['paquete_vendido'] ?? null)) ?></div>
                                                    <div style="color:#00fff7; font-size:0.92em; margin-top:0.2rem;"><?= htmlspecialchars(admin_display_value($sale['juego_nombre'] ?? null)) ?></div>
                                                    <div style="color:#b2f6ff; font-size:0.88em; margin-top:0.2rem;">Pedido #<?= htmlspecialchars((string) ($sale['pedido_id'] ?? '')) ?></div>
                                                </td>
                                                <td style="background:#181f2a; color:#b2f6ff;"><?= htmlspecialchars(admin_display_value($sale['creado_en'] ?? null)) ?></td>
                                                <td style="background:#181f2a; color:#b2f6ff; min-width:170px;">
                                                    <?php if ($isInfluencerViewer): ?>
                                                        <div class="form-control form-control-sm" style="background:#222c3a; color:#00fff7; border:1px solid #00fff7;"><?= htmlspecialchars(($sale['estado_pago_influencer'] ?? 'pendiente') === 'pagado' ? 'Pagado' : 'Pendiente') ?></div>
                                                    <?php else: ?>
                                                        <form method="POST" action="" class="m-0">
                                                            <input type="hidden" name="actualizar_estado_pago_influencer" value="1">
                                                            <input type="hidden" name="pedido_id" value="<?= htmlspecialchars((string) $sale['pedido_id']) ?>">
                                                            <input type="hidden" name="filtro_estado_pago" value="<?= htmlspecialchars($influencerPaymentFilter) ?>">
                                                            <input type="hidden" name="fecha_desde" value="<?= htmlspecialchars((string) ($influencerDateFrom ?? '')) ?>">
                                                            <input type="hidden" name="fecha_hasta" value="<?= htmlspecialchars((string) ($influencerDateTo ?? '')) ?>">
                                                            <input type="hidden" name="filtro_influencer_usuario" value="<?= htmlspecialchars($selectedInfluencerFilterId) ?>">
                                                            <select name="estado_pago_influencer" class="form-select form-select-sm" onchange="this.form.submit()" style="background:#222c3a; color:#00fff7; border:1px solid #00fff7;">
                                                                <option value="pendiente" <?= ($sale['estado_pago_influencer'] ?? 'pendiente') === 'pendiente' ? 'selected' : '' ?>>Pendiente</option>
                                                                <option value="pagado" <?= ($sale['estado_pago_influencer'] ?? 'pendiente') === 'pagado' ? 'selected' : '' ?>>Pagado</option>
                                                            </select>
                                                        </form>
                                                    <?php endif; ?>
                                                </td>
                                                <td style="background:#181f2a; color:#00ffb3; font-weight:bold;"><?= htmlspecialchars(admin_display_value($sale['moneda'] ?? null, '')) ?> <?= htmlspecialchars(admin_format_money($sale['total_comision'] ?? 0)) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <div class="d-block d-md-none">
                                <?php foreach ($influencerSales as $sale): ?>
                                    <div style="background:#181f2a; border-radius:16px; border:2px solid #00fff7; box-shadow:0 0 24px #00fff733; padding:1rem; color:#00fff7; margin-bottom:1.2rem;">
                                        <div style="font-weight:bold; font-size:1.1em; color:#00fff7;"><?= htmlspecialchars(admin_display_value($sale['nombre_influencer'] ?? null)) ?></div>
                                        <div style="margin-top:0.45rem; color:#b2f6ff;">Cupón: <?= htmlspecialchars(admin_display_value($sale['codigo_cupon'] ?? null)) ?></div>
                                        <div style="margin-top:0.45rem; color:#b2f6ff;">Teléfono: <?= htmlspecialchars(admin_display_value($sale['telefono_influencer'] ?? null)) ?></div>
                                        <div style="margin-top:0.45rem; color:#b2f6ff;">Correo: <?= htmlspecialchars(admin_display_value($sale['email_influencer'] ?? null)) ?></div>
                                        <div style="margin-top:0.45rem; color:#b2f6ff;">Comisión: <?= htmlspecialchars(admin_format_money($sale['comision_porcentaje'] ?? 0)) ?>%</div>
                                        <div style="margin-top:0.45rem; color:#b2f6ff;">Paquete vendido: <?= htmlspecialchars(admin_display_value($sale['paquete_vendido'] ?? null)) ?></div>
                                        <div style="margin-top:0.2rem; color:#00fff7;">Juego: <?= htmlspecialchars(admin_display_value($sale['juego_nombre'] ?? null)) ?></div>
                                        <div style="margin-top:0.2rem; color:#b2f6ff;">Pedido: #<?= htmlspecialchars((string) ($sale['pedido_id'] ?? '')) ?></div>
                                        <div style="margin-top:0.2rem; color:#b2f6ff;">Fecha: <?= htmlspecialchars(admin_display_value($sale['creado_en'] ?? null)) ?></div>
                                        <?php if ($isInfluencerViewer): ?>
                                            <div style="margin-top:0.45rem; color:#b2f6ff;">Estado: <span class="form-control form-control-sm d-inline-block" style="width:auto; background:#222c3a; color:#00fff7; border:1px solid #00fff7;"><?= htmlspecialchars(($sale['estado_pago_influencer'] ?? 'pendiente') === 'pagado' ? 'Pagado' : 'Pendiente') ?></span></div>
                                        <?php else: ?>
                                            <form method="POST" action="" class="mt-2">
                                                <input type="hidden" name="actualizar_estado_pago_influencer" value="1">
                                                <input type="hidden" name="pedido_id" value="<?= htmlspecialchars((string) $sale['pedido_id']) ?>">
                                                <input type="hidden" name="filtro_estado_pago" value="<?= htmlspecialchars($influencerPaymentFilter) ?>">
                                                <input type="hidden" name="fecha_desde" value="<?= htmlspecialchars((string) ($influencerDateFrom ?? '')) ?>">
                                                <input type="hidden" name="fecha_hasta" value="<?= htmlspecialchars((string) ($influencerDateTo ?? '')) ?>">
                                                <input type="hidden" name="filtro_influencer_usuario" value="<?= htmlspecialchars($selectedInfluencerFilterId) ?>">
                                                <label class="form-label" style="color:#00fff7;">Pendiente/Pagado</label>
                                                <select name="estado_pago_influencer" class="form-select form-select-sm" onchange="this.form.submit()" style="background:#222c3a; color:#00fff7; border:1px solid #00fff7;">
                                                    <option value="pendiente" <?= ($sale['estado_pago_influencer'] ?? 'pendiente') === 'pendiente' ? 'selected' : '' ?>>Pendiente</option>
                                                    <option value="pagado" <?= ($sale['estado_pago_influencer'] ?? 'pendiente') === 'pagado' ? 'selected' : '' ?>>Pagado</option>
                                                </select>
                                            </form>
                                        <?php endif; ?>
                                        <div style="margin-top:0.45rem; color:#00ffb3; font-weight:bold;">Total: <?= htmlspecialchars(admin_display_value($sale['moneda'] ?? null, '')) ?> <?= htmlspecialchars(admin_format_money($sale['total_comision'] ?? 0)) ?></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                <?php
                break;
            case 'movimientos':
            case 'movimientos-binance':
                require_once __DIR__ . '/includes/db.php';
                $isBinanceMovementsSection = $seccion === 'movimientos-binance';
                $movementSourceTitle = $isBinanceMovementsSection ? 'Movimientos Binance' : 'Movimientos Bancarios';
                $movementSourceLabel = $isBinanceMovementsSection ? 'Binance' : 'bancaria';
                $movementFixedCurrency = $isBinanceMovementsSection ? 'USDT' : 'VES';
                $movementAvailableDaysKey = $isBinanceMovementsSection ? 'binance_pagonorte_dias_disponibles' : 'ff_bank_dias_disponibles';
                $movementReference = trim((string) ($_GET['referencia'] ?? ''));
                $movementDateFrom = admin_normalize_date_filter($_GET['fecha_desde'] ?? null);
                $movementDateTo = admin_normalize_date_filter($_GET['fecha_hasta'] ?? null);
                $movementCurrency = strtoupper(trim((string) ($_GET['moneda'] ?? '')));
                $movementCheckedFilter = admin_normalize_movement_checked_filter($_GET['estado_verificacion'] ?? 'no_verificados');
                $movementOrderLinkFilter = admin_normalize_movement_order_link_filter($_GET['pedido_relacionado'] ?? 'todos');
                $movementSort = admin_normalize_movement_sort_column($_GET['orden'] ?? 'fecha_movimiento');
                $movementDirection = admin_normalize_sort_direction($_GET['direccion'] ?? 'desc');
                $movementPage = admin_normalize_positive_page($_GET['pagina'] ?? 1);
                $movementPerPage = admin_normalize_per_page($_GET['por_pagina'] ?? 15);
                if ($movementCurrency !== '' && preg_match('/^[A-Z0-9_-]{1,20}$/', $movementCurrency) !== 1) {
                    $movementCurrency = '';
                }

                $currencies = [$movementFixedCurrency];

                $movementBaseSql = ' FROM movimientos m LEFT JOIN pedidos p ON p.id = m.pedido_id WHERE 1=1';
                $movementParams = [$movementFixedCurrency];
                $movementBaseSql .= ' AND m.moneda = ?';
                if ($movementReference !== '') {
                    $movementBaseSql .= ' AND m.referencia LIKE ?';
                    $movementParams[] = '%' . $movementReference . '%';
                }
                if ($movementDateFrom !== null) {
                    $movementBaseSql .= ' AND DATE(m.fecha_movimiento) >= ?';
                    $movementParams[] = $movementDateFrom;
                }
                if ($movementDateTo !== null) {
                    $movementBaseSql .= ' AND DATE(m.fecha_movimiento) <= ?';
                    $movementParams[] = $movementDateTo;
                }
                if ($movementCurrency !== '') {
                    $movementBaseSql .= ' AND m.moneda = ?';
                    $movementParams[] = $movementCurrency;
                }
                if ($movementOrderLinkFilter === 'con_pedido') {
                    $movementBaseSql .= ' AND m.pedido_id IS NOT NULL AND m.pedido_id > 0';
                } elseif ($movementOrderLinkFilter === 'sin_pedido') {
                    $movementBaseSql .= ' AND (m.pedido_id IS NULL OR m.pedido_id = 0)';
                }

                $movementStatsSql = 'SELECT COUNT(*) AS total, '
                    . 'SUM(CASE WHEN COALESCE(m.checked, 0) = 1 THEN 1 ELSE 0 END) AS checked_count, '
                    . 'SUM(CASE WHEN COALESCE(m.checked, 0) = 0 THEN 1 ELSE 0 END) AS unchecked_count'
                    . $movementBaseSql;
                $movementStatsStmt = $pdo->prepare($movementStatsSql);
                $movementStatsStmt->execute($movementParams);
                $movementStats = $movementStatsStmt->fetch(PDO::FETCH_ASSOC) ?: [];
                $movementCheckedCount = (int) ($movementStats['checked_count'] ?? 0);
                $movementUncheckedCount = (int) ($movementStats['unchecked_count'] ?? 0);
                $movementAllCount = (int) ($movementStats['total'] ?? 0);

                if ($movementCheckedFilter === 'verificados') {
                    $movementBaseSql .= ' AND COALESCE(m.checked, 0) = 1';
                } elseif ($movementCheckedFilter === 'no_verificados') {
                    $movementBaseSql .= ' AND COALESCE(m.checked, 0) = 0';
                }

                $movementCountStmt = $pdo->prepare('SELECT COUNT(*)' . $movementBaseSql);
                $movementCountStmt->execute($movementParams);
                $movementTotal = (int) $movementCountStmt->fetchColumn();
                $movementTotalPages = max(1, (int) ceil($movementTotal / $movementPerPage));
                if ($movementPage > $movementTotalPages) {
                    $movementPage = $movementTotalPages;
                }
                $movementOffset = ($movementPage - 1) * $movementPerPage;

                $movementSortColumns = [
                    'referencia' => 'm.referencia',
                    'descripcion' => 'm.descripcion',
                    'fecha_movimiento' => 'm.fecha_movimiento',
                    'monto' => 'm.monto',
                    'moneda' => 'm.moneda',
                ];
                $movementOrderBy = $movementSortColumns[$movementSort] ?? 'm.fecha_movimiento';
                $movementsSql = 'SELECT m.id, m.referencia, m.descripcion, m.fecha_movimiento, m.monto, m.moneda, m.pedido_id, m.checked, p.estado AS pedido_estado'
                    . $movementBaseSql
                    . ' ORDER BY ' . $movementOrderBy . ' ' . strtoupper($movementDirection) . ', m.fecha_movimiento DESC, m.referencia DESC'
                    . ' LIMIT ' . (int) $movementPerPage . ' OFFSET ' . (int) $movementOffset;
                $movementsStmt = $pdo->prepare($movementsSql);
                $movementsStmt->execute($movementParams);
                $movimientos = $movementsStmt->fetchAll(PDO::FETCH_ASSOC);

                $movementBaseQuery = [
                    'referencia' => $movementReference,
                    'fecha_desde' => $movementDateFrom,
                    'fecha_hasta' => $movementDateTo,
                    'moneda' => $movementCurrency,
                    'estado_verificacion' => $movementCheckedFilter,
                    'pedido_relacionado' => $movementOrderLinkFilter,
                    'orden' => $movementSort,
                    'direccion' => $movementDirection,
                    'por_pagina' => $movementPerPage,
                ];
                $movementSortLabels = [
                    'referencia' => 'Referencia',
                    'descripcion' => 'Descripción',
                    'fecha_movimiento' => 'Fecha movimiento',
                    'monto' => 'Monto',
                    'moneda' => 'Moneda',
                ];
                $movementCheckedLabels = [
                    'no_verificados' => 'No verificados',
                    'verificados' => 'Verificados',
                    'todos' => 'Todos',
                ];
                $movementOrderLinkLabels = [
                    'todos' => 'Todos',
                    'con_pedido' => 'Con pedido',
                    'sin_pedido' => 'Sin pedido',
                ];
                $movementRangeStart = $movementTotal > 0 ? $movementOffset + 1 : 0;
                $movementRangeEnd = $movementTotal > 0 ? min($movementOffset + count($movimientos), $movementTotal) : 0;
                $adminMovementsPath = app_path($isBinanceMovementsSection ? '/admin/movimientos-binance' : '/admin/movimientos');
                $adminOrdersPath = app_path('/admin/pedidos');

                echo '<h2 class="display-6 fw-bold text-info mb-3">' . htmlspecialchars($movementSourceTitle, ENT_QUOTES, 'UTF-8') . '</h2>';
                echo '<p class="text-secondary mb-4">Listado de movimientos registrados en la tabla movimientos.</p>';

                echo '<form method="GET" action="' . htmlspecialchars($adminMovementsPath) . '" class="row g-3 mb-4 align-items-end" data-movement-filter-form="1" style="background:#181f2a; border-radius:16px; border:2px solid #00fff7; box-shadow:0 0 24px #00fff733; padding:1.5rem;">';
                echo '<div class="col-12 col-lg-4">';
                echo '<label class="form-label" style="color:#00fff7;">Buscar por referencia</label>';
                echo '<input type="search" name="referencia" value="' . htmlspecialchars($movementReference) . '" class="form-control" placeholder="Ej. 5398344305" style="background:#222c3a; color:#00fff7; border:1px solid #00fff7;">';
                echo '</div>';
                echo '<div class="col-6 col-lg-2">';
                echo '<label class="form-label" style="color:#00fff7;">Desde</label>';
                echo '<input type="date" name="fecha_desde" value="' . htmlspecialchars((string) ($movementDateFrom ?? '')) . '" class="form-control" style="background:#222c3a; color:#00fff7; border:1px solid #00fff7;">';
                echo '</div>';
                echo '<div class="col-6 col-lg-2">';
                echo '<label class="form-label" style="color:#00fff7;">Hasta</label>';
                echo '<input type="date" name="fecha_hasta" value="' . htmlspecialchars((string) ($movementDateTo ?? '')) . '" class="form-control" style="background:#222c3a; color:#00fff7; border:1px solid #00fff7;">';
                echo '</div>';
                echo '<div class="col-12 col-lg-2">';
                echo '<label class="form-label" style="color:#00fff7;">Moneda</label>';
                echo '<select name="moneda" class="form-select" style="background:#222c3a; color:#00fff7; border:1px solid #00fff7;">';
                echo '<option value="">Todas</option>';
                foreach ($currencies as $currencyOption) {
                    $currencyOption = trim((string) $currencyOption);
                    if ($currencyOption === '') {
                        continue;
                    }
                    $selected = $movementCurrency === strtoupper($currencyOption) ? ' selected' : '';
                    echo '<option value="' . htmlspecialchars($currencyOption) . '"' . $selected . '>' . htmlspecialchars($currencyOption) . '</option>';
                }
                echo '</select>';
                echo '</div>';
                echo '<div class="col-6 col-lg-2">';
                echo '<label class="form-label" style="color:#00fff7;">Ver movimientos</label>';
                echo '<select name="estado_verificacion" class="form-select" style="background:#222c3a; color:#00fff7; border:1px solid #00fff7;">';
                foreach ($movementCheckedLabels as $checkedKey => $checkedLabel) {
                    $selected = $movementCheckedFilter === $checkedKey ? ' selected' : '';
                    echo '<option value="' . htmlspecialchars($checkedKey) . '"' . $selected . '>' . htmlspecialchars($checkedLabel) . '</option>';
                }
                echo '</select>';
                echo '</div>';
                echo '<div class="col-6 col-lg-2">';
                echo '<label class="form-label" style="color:#00fff7;">Pedidos</label>';
                echo '<select name="pedido_relacionado" class="form-select" style="background:#222c3a; color:#00fff7; border:1px solid #00fff7;">';
                foreach ($movementOrderLinkLabels as $orderFilterKey => $orderFilterLabel) {
                    $selected = $movementOrderLinkFilter === $orderFilterKey ? ' selected' : '';
                    echo '<option value="' . htmlspecialchars($orderFilterKey) . '"' . $selected . '>' . htmlspecialchars($orderFilterLabel) . '</option>';
                }
                echo '</select>';
                echo '</div>';
                echo '<div class="col-6 col-lg-2">';
                echo '<label class="form-label" style="color:#00fff7;">Ordenar por</label>';
                echo '<select name="orden" class="form-select" style="background:#222c3a; color:#00fff7; border:1px solid #00fff7;">';
                foreach ($movementSortLabels as $sortKey => $sortLabel) {
                    $selected = $movementSort === $sortKey ? ' selected' : '';
                    echo '<option value="' . htmlspecialchars($sortKey) . '"' . $selected . '>' . htmlspecialchars($sortLabel) . '</option>';
                }
                echo '</select>';
                echo '</div>';
                echo '<div class="col-6 col-lg-2">';
                echo '<label class="form-label" style="color:#00fff7;">Dirección</label>';
                echo '<select name="direccion" class="form-select" style="background:#222c3a; color:#00fff7; border:1px solid #00fff7;">';
                echo '<option value="desc"' . ($movementDirection === 'desc' ? ' selected' : '') . '>Descendente</option>';
                echo '<option value="asc"' . ($movementDirection === 'asc' ? ' selected' : '') . '>Ascendente</option>';
                echo '</select>';
                echo '</div>';
                echo '<div class="col-6 col-lg-2">';
                echo '<label class="form-label" style="color:#00fff7;">Por página</label>';
                echo '<select name="por_pagina" class="form-select" style="background:#222c3a; color:#00fff7; border:1px solid #00fff7;">';
                foreach ([15, 30, 50] as $perPageOption) {
                    $selected = $movementPerPage === $perPageOption ? ' selected' : '';
                    echo '<option value="' . $perPageOption . '"' . $selected . '>' . $perPageOption . '</option>';
                }
                echo '</select>';
                echo '</div>';
                echo '<div class="col-12 col-lg-2 d-flex gap-2">';
                echo '<button type="submit" class="btn btn-info flex-fill fw-bold" style="background:#00fff7; color:#181f2a; border:none; box-shadow:0 0 8px #00fff7;">Filtrar</button>';
                echo '<a href="' . htmlspecialchars($adminMovementsPath) . '" data-movement-filter-clear="1" class="btn btn-outline-info flex-fill fw-bold" style="border:1px solid #00fff7; color:#00fff7; background:#181f2a;">Limpiar</a>';
                echo '</div>';
                echo '</form>';

                echo '<div data-movements-refresh-root="1">';

                $bankAvailableDays = trim((string) store_config_get($movementAvailableDaysKey, ''));
                if ($bankAvailableDays !== '') {
                    echo '<div class="alert alert-info rounded-4 mb-4" role="status" data-bank-available-days="' . htmlspecialchars($bankAvailableDays, ENT_QUOTES, 'UTF-8') . '" style="border:1px solid rgba(34,211,238,0.32); background:rgba(8,145,178,0.14); color:#cffafe;">';
                    echo 'La API ' . htmlspecialchars($movementSourceLabel, ENT_QUOTES, 'UTF-8') . ' reporta actualmente <strong>' . htmlspecialchars($bankAvailableDays, ENT_QUOTES, 'UTF-8') . ' días disponibles</strong> en la consulta de movimientos.';
                    echo '</div>';
                }

                echo '<div class="mb-4" style="background:#111827; border-radius:16px; border:1px solid rgba(0,255,247,0.24); box-shadow:0 0 18px rgba(0,255,247,0.08); padding:1rem 1.1rem;">';
                echo '<div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3">';
                echo '<div>';
                echo '<div class="text-uppercase small fw-semibold" style="color:#7dd3fc; letter-spacing:0.08em;">Sincronización manual</div>';
                echo '<div class="text-secondary small">Consulta la API ' . htmlspecialchars($movementSourceLabel, ENT_QUOTES, 'UTF-8') . ' e inserta solo los movimientos nuevos en la tabla.</div>';
                echo '</div>';
                echo '<form method="POST" action="' . htmlspecialchars($adminMovementsPath) . '" class="m-0" data-sync-movements-form="1">';
                echo '<input type="hidden" name="actualizar_movimientos_api" value="1">';
                foreach ($movementBaseQuery as $queryKey => $queryValue) {
                    echo '<input type="hidden" name="' . htmlspecialchars((string) $queryKey) . '" value="' . htmlspecialchars((string) $queryValue) . '">';
                }
                echo '<input type="hidden" name="pagina" value="' . $movementPage . '">';
                echo '<button type="submit" class="btn btn-info fw-bold" data-sync-movements-button="1" style="min-width:240px; background:linear-gradient(135deg, #00fff7, #2dd4bf); color:#0f172a; border:none; box-shadow:0 0 16px rgba(0,255,247,0.28);">Actualizar Movimientos API</button>';
                echo '</form>';
                echo '</div>';
                echo '<div class="mt-3 d-none" data-sync-movements-status style="border-radius:14px; padding:0.9rem 1rem; border:1px solid rgba(0,255,247,0.22); background:rgba(15,23,42,0.88);">';
                echo '<div class="d-flex align-items-center gap-3">';
                echo '<span class="d-inline-flex align-items-center justify-content-center d-none" data-sync-movements-spinner="1" style="width:18px; height:18px; border-radius:999px; border:2px solid rgba(0,255,247,0.24); border-top-color:#00fff7; animation:movement-sync-spin 0.9s linear infinite;"></span>';
                echo '<div>';
                echo '<div class="fw-semibold" data-sync-movements-title style="color:#00fff7;">Listo para actualizar</div>';
                echo '<div class="small text-secondary" data-sync-movements-message>Presiona el botón para consultar la API bancaria.</div>';
                echo '</div>';
                echo '</div>';
                echo '</div>';
                echo '</div>';

                echo '<div class="d-flex flex-wrap gap-2 align-items-center mb-3">';
                echo '<span class="small text-uppercase fw-semibold" style="color:#7dd3fc; letter-spacing:0.08em;">Verificación rápida</span>';
                foreach ($movementCheckedLabels as $checkedKey => $checkedLabel) {
                    $chipQuery = $movementBaseQuery;
                    $chipQuery['estado_verificacion'] = $checkedKey;
                    $chipQuery['pagina'] = 1;
                    $chipCount = $checkedKey === 'verificados' ? $movementCheckedCount : ($checkedKey === 'no_verificados' ? $movementUncheckedCount : $movementAllCount);
                    $isActiveChip = $movementCheckedFilter === $checkedKey;
                    $chipStyle = $isActiveChip
                        ? 'background:#00fff7; color:#181f2a; border:1px solid #00fff7; box-shadow:0 0 10px #00fff7;'
                        : 'background:#181f2a; color:#00fff7; border:1px solid rgba(0,255,247,0.45);';
                    echo '<a href="' . htmlspecialchars(admin_build_url($adminMovementsPath, $chipQuery)) . '" data-movements-ajax-link="1" class="btn btn-sm rounded-pill fw-semibold" style="' . $chipStyle . '">';
                    echo htmlspecialchars($checkedLabel) . ': <span data-movement-count-label="' . htmlspecialchars($checkedKey) . '">' . $chipCount . '</span>';
                    echo '</a>';
                }
                echo '</div>';

                echo '<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">';
                echo '<div class="text-info fw-semibold">Resultados: <span data-movement-results-total>' . $movementTotal . '</span></div>';
                echo '<div class="text-secondary small">Orden actual: ' . htmlspecialchars($movementSortLabels[$movementSort] ?? 'Fecha movimiento') . ' (' . strtoupper($movementDirection) . ')</div>';
                echo '<div class="text-secondary small">Estado: ' . htmlspecialchars($movementCheckedLabels[$movementCheckedFilter] ?? 'No verificados') . '</div>';
                echo '<div class="text-secondary small">Pedidos: ' . htmlspecialchars($movementOrderLinkLabels[$movementOrderLinkFilter] ?? 'Todos') . '</div>';
                if ($movementReference !== '' || $movementDateFrom !== null || $movementDateTo !== null || $movementCurrency !== '' || $movementCheckedFilter !== 'no_verificados' || $movementOrderLinkFilter !== 'todos') {
                    echo '<div class="text-secondary small">Filtros activos aplicados a la tabla de movimientos.</div>';
                }
                echo '</div>';

                if ($movementTotal === 0) {
                    echo '<div class="text-secondary">No hay movimientos registrados.</div>';
                    echo '</div>';
                    break;
                }

                echo '<div class="mb-3" style="background:linear-gradient(135deg, rgba(0,255,247,0.14), rgba(0,255,179,0.08)); border:1px solid rgba(0,255,247,0.35); border-radius:16px; padding:1rem 1.1rem; box-shadow:0 0 18px rgba(0,255,247,0.12);">';
                echo '<div class="d-flex justify-content-between align-items-center flex-wrap gap-2">';
                echo '<div>';
                echo '<div class="text-uppercase small fw-semibold" style="color:#7dd3fc; letter-spacing:0.08em;">Rango visible</div>';
                echo '<div class="fw-bold" data-movement-range-label style="color:#00fff7; font-size:1.05rem;">Mostrando ' . $movementRangeStart . ' - ' . $movementRangeEnd . ' de ' . $movementTotal . '</div>';
                echo '</div>';
                echo '<div class="text-end">';
                echo '<div class="text-uppercase small fw-semibold" style="color:#7dd3fc; letter-spacing:0.08em;">Paginación</div>';
                echo '<div class="fw-semibold" style="color:#b2f6ff;">Página ' . $movementPage . ' de ' . $movementTotalPages . '</div>';
                echo '</div>';
                echo '</div>';
                echo '</div>';

                echo '<div class="table-responsive mb-4 d-none d-md-block" style="background:#10141a; border-radius:16px; border:2px solid #00fff7; box-shadow:0 0 24px #00fff733; padding:1rem;">';
                echo '<table class="table align-middle mb-0" style="background:#181f2a; color:#00fff7; border-radius:12px;">';
                echo '<thead style="background:#181f2a; color:#00fff7; border-bottom:2px solid #00fff7;">';
                echo '<tr>';
                foreach (['referencia', 'descripcion', 'fecha_movimiento', 'monto', 'moneda'] as $movementColumnKey) {
                    $columnDirection = $movementSort === $movementColumnKey && $movementDirection === 'asc' ? 'desc' : 'asc';
                    $columnArrow = $movementSort === $movementColumnKey ? ($movementDirection === 'asc' ? ' ↑' : ' ↓') : '';
                    $columnQuery = $movementBaseQuery;
                    $columnQuery['orden'] = $movementColumnKey;
                    $columnQuery['direccion'] = $columnDirection;
                    $columnQuery['pagina'] = 1;
                    $minWidth = $movementColumnKey === 'descripcion' ? '260px' : ($movementColumnKey === 'fecha_movimiento' ? '180px' : ($movementColumnKey === 'monto' ? '130px' : ($movementColumnKey === 'moneda' ? '90px' : '160px')));
                    echo '<th style="color:#00fff7; background:#181f2a; min-width:' . $minWidth . ';">';
                    echo '<a href="' . htmlspecialchars(admin_build_url($adminMovementsPath, $columnQuery)) . '" data-movements-ajax-link="1" style="color:#00fff7; text-decoration:none; display:inline-flex; align-items:center; gap:0.35rem;">' . htmlspecialchars($movementSortLabels[$movementColumnKey]) . '<span style="opacity:0.9;">' . htmlspecialchars($columnArrow) . '</span></a>';
                    echo '</th>';
                }
                echo '<th style="color:#00fff7; background:#181f2a; min-width:140px;">Verificar</th>';
                echo '<th style="color:#00fff7; background:#181f2a; min-width:170px;">Pedido relacionado</th>';
                echo '</tr>';
                echo '</thead>';
                echo '<tbody>';

                $rowAlt = false;
                foreach ($movimientos as $movimiento) {
                    $isChecked = (int) ($movimiento['checked'] ?? 0) === 1;
                    $cellBackground = $isChecked ? '#183f2b' : ($rowAlt ? '#151a24' : '#181f2a');
                    $rowStyle = $isChecked ? 'box-shadow: inset 0 0 0 1px rgba(125, 211, 252, 0.08);' : '';
                    $relatedOrderId = (int) ($movimiento['pedido_id'] ?? 0);
                    $relatedOrderStatus = trim((string) ($movimiento['pedido_estado'] ?? ''));
                    $relatedTab = in_array($relatedOrderStatus, ['pendiente', 'pagado', 'enviado', 'cancelado'], true) ? $relatedOrderStatus : 'pendiente';
                    $relatedOrderHref = $adminOrdersPath . '?pedido=' . $relatedOrderId . '&order_search=' . urlencode((string) $relatedOrderId) . '&tab=' . urlencode($relatedTab) . '#pedido-' . $relatedOrderId;
                    echo '<tr data-movement-row="' . (int) ($movimiento['id'] ?? 0) . '" data-movement-checked="' . ($isChecked ? '1' : '0') . '" style="' . $rowStyle . ' color:#fff;">';
                    echo '<td data-movement-cell="reference" style="background:' . $cellBackground . '; color:' . ($isChecked ? '#d9ffe8' : '#00fff7') . '; font-weight:600;">' . htmlspecialchars(admin_display_value(admin_display_movement_reference((string) ($movimiento['referencia'] ?? '')))) . '</td>';
                    echo '<td data-movement-cell="description" style="background:' . $cellBackground . '; color:' . ($isChecked ? '#ecfff2' : '#fff') . ';">' . htmlspecialchars(admin_display_value($movimiento['descripcion'] ?? null)) . '</td>';
                    echo '<td data-movement-cell="date" style="background:' . $cellBackground . '; color:' . ($isChecked ? '#d7ffe6' : '#b2f6ff') . ';">' . htmlspecialchars(admin_display_value($movimiento['fecha_movimiento'] ?? null)) . '</td>';
                    echo '<td data-movement-cell="amount" style="background:' . $cellBackground . '; color:#9effbd; font-weight:bold;">' . htmlspecialchars(admin_format_money($movimiento['monto'] ?? 0)) . '</td>';
                    echo '<td data-movement-cell="currency" style="background:' . $cellBackground . '; color:' . ($isChecked ? '#d7ffe6' : '#b2f6ff') . '; font-weight:600;">' . htmlspecialchars(admin_display_value($movimiento['moneda'] ?? null)) . '</td>';
                    echo '<td data-movement-cell="verify" data-movement-verify-cell="' . (int) ($movimiento['id'] ?? 0) . '" style="background:' . $cellBackground . '; color:#b2f6ff;">';
                    if ($isChecked) {
                        echo '<span class="fw-bold" data-movement-verified-text="' . (int) ($movimiento['id'] ?? 0) . '" style="color:#a8ffbf;">Verificado</span>';
                    } else {
                        echo '<form method="POST" action="' . htmlspecialchars($adminMovementsPath) . '" class="m-0 d-inline-flex align-items-center" data-verify-movement-form="' . (int) ($movimiento['id'] ?? 0) . '">';
                        echo '<input type="hidden" name="verificar_movimiento" value="1">';
                        echo '<input type="hidden" name="movimiento_id" value="' . (int) ($movimiento['id'] ?? 0) . '">';
                        foreach ($movementBaseQuery as $queryKey => $queryValue) {
                            echo '<input type="hidden" name="' . htmlspecialchars((string) $queryKey) . '" value="' . htmlspecialchars((string) $queryValue) . '">';
                        }
                        echo '<input type="hidden" name="pagina" value="' . $movementPage . '">';
                        echo '<button type="submit" class="btn btn-sm fw-bold" data-verify-movement-button="' . (int) ($movimiento['id'] ?? 0) . '" title="Verificar movimiento" style="min-width:52px; min-height:44px; border:1px solid #34d399; color:#ffffff; background:#15803d; box-shadow:0 0 12px rgba(52,211,153,0.35); font-size:1.35rem; line-height:1;">&#10003;</button>';
                        echo '</form>';
                    }
                    echo '</td>';
                    echo '<td data-movement-cell="order" style="background:' . $cellBackground . '; color:#b2f6ff;">';
                    if ($relatedOrderId > 0) {
                        echo '<a href="' . htmlspecialchars($relatedOrderHref) . '" class="btn btn-outline-info btn-sm fw-semibold" style="border-color:#00fff7; color:#00fff7; background:#181f2a;">Ver pedido #' . htmlspecialchars((string) $relatedOrderId) . '</a>';
                    } else {
                        echo '<span class="text-secondary">Sin pedido</span>';
                    }
                    echo '</td>';
                    echo '</tr>';
                    $rowAlt = !$rowAlt;
                }

                echo '</tbody></table>';
                echo '</div>';

                echo '<div class="d-block d-md-none">';
                foreach ($movimientos as $movimiento) {
                    $isChecked = (int) ($movimiento['checked'] ?? 0) === 1;
                    $relatedOrderId = (int) ($movimiento['pedido_id'] ?? 0);
                    $relatedOrderStatus = trim((string) ($movimiento['pedido_estado'] ?? ''));
                    $relatedTab = in_array($relatedOrderStatus, ['pendiente', 'pagado', 'enviado', 'cancelado'], true) ? $relatedOrderStatus : 'pendiente';
                    $relatedOrderHref = $adminOrdersPath . '?pedido=' . $relatedOrderId . '&order_search=' . urlencode((string) $relatedOrderId) . '&tab=' . urlencode($relatedTab) . '#pedido-' . $relatedOrderId;
                    $cardBackground = $isChecked ? 'linear-gradient(135deg, #183f2b, #1f5a35)' : '#181f2a';
                    $cardBorder = $isChecked ? '#7ee787' : '#00fff7';
                    echo '<div data-movement-card="' . (int) ($movimiento['id'] ?? 0) . '" data-movement-checked="' . ($isChecked ? '1' : '0') . '" style="background:' . $cardBackground . '; border-radius:16px; border:2px solid ' . $cardBorder . '; box-shadow:0 0 24px ' . ($isChecked ? 'rgba(126,231,135,0.24)' : '#00fff733') . '; padding:1rem; color:#00fff7; margin-bottom:1rem;">';
                    echo '<div style="display:grid; grid-template-columns:1fr; gap:0.75rem;">';
                    echo '<div style="padding-bottom:0.6rem; border-bottom:1px solid rgba(0,255,247,0.18);">';
                    echo '<div style="font-size:0.8rem; text-transform:uppercase; letter-spacing:0.08em; color:#7dd3fc;">Referencia</div>';
                    echo '<div style="font-weight:700; color:#00fff7; word-break:break-word;">' . htmlspecialchars(admin_display_value(admin_display_movement_reference((string) ($movimiento['referencia'] ?? '')))) . '</div>';
                    echo '</div>';
                    echo '<div style="padding-bottom:0.6rem; border-bottom:1px solid rgba(0,255,247,0.18);">';
                    echo '<div style="font-size:0.8rem; text-transform:uppercase; letter-spacing:0.08em; color:#7dd3fc;">Descripción</div>';
                    echo '<div style="color:#ffffff;">' . htmlspecialchars(admin_display_value($movimiento['descripcion'] ?? null)) . '</div>';
                    echo '</div>';
                    echo '<div style="padding-bottom:0.6rem; border-bottom:1px solid rgba(0,255,247,0.18);">';
                    echo '<div style="font-size:0.8rem; text-transform:uppercase; letter-spacing:0.08em; color:#7dd3fc;">Fecha movimiento</div>';
                    echo '<div style="color:#b2f6ff;">' . htmlspecialchars(admin_display_value($movimiento['fecha_movimiento'] ?? null)) . '</div>';
                    echo '</div>';
                    echo '<div style="padding-bottom:0.6rem; border-bottom:1px solid rgba(0,255,247,0.18);">';
                    echo '<div style="font-size:0.8rem; text-transform:uppercase; letter-spacing:0.08em; color:#7dd3fc;">Monto</div>';
                    echo '<div style="color:#00ffb3; font-weight:700;">' . htmlspecialchars(admin_format_money($movimiento['monto'] ?? 0)) . '</div>';
                    echo '</div>';
                    echo '<div>';
                    echo '<div style="font-size:0.8rem; text-transform:uppercase; letter-spacing:0.08em; color:#7dd3fc;">Moneda</div>';
                    echo '<div style="color:#b2f6ff; font-weight:600;">' . htmlspecialchars(admin_display_value($movimiento['moneda'] ?? null)) . '</div>';
                    echo '</div>';
                    echo '<div style="padding-top:0.4rem;">';
                    echo '<div style="font-size:0.8rem; text-transform:uppercase; letter-spacing:0.08em; color:#7dd3fc; margin-bottom:0.4rem;">Verificar</div>';
                    if ($isChecked) {
                        echo '<div class="fw-bold" data-movement-card-verified-text="' . (int) ($movimiento['id'] ?? 0) . '" style="color:#a8ffbf;">Verificado</div>';
                    } else {
                        echo '<form method="POST" action="' . htmlspecialchars($adminMovementsPath) . '" class="m-0" data-verify-movement-form="' . (int) ($movimiento['id'] ?? 0) . '">';
                        echo '<input type="hidden" name="verificar_movimiento" value="1">';
                        echo '<input type="hidden" name="movimiento_id" value="' . (int) ($movimiento['id'] ?? 0) . '">';
                        foreach ($movementBaseQuery as $queryKey => $queryValue) {
                            echo '<input type="hidden" name="' . htmlspecialchars((string) $queryKey) . '" value="' . htmlspecialchars((string) $queryValue) . '">';
                        }
                        echo '<input type="hidden" name="pagina" value="' . $movementPage . '">';
                        echo '<button type="submit" class="btn fw-bold" data-verify-movement-button="' . (int) ($movimiento['id'] ?? 0) . '" style="min-width:64px; min-height:48px; border:1px solid #34d399; color:#ffffff; background:#15803d; box-shadow:0 0 12px rgba(52,211,153,0.35); font-size:1.5rem; line-height:1;">&#10003;</button>';
                        echo '</form>';
                    }
                    echo '</div>';
                    echo '<div style="padding-top:0.4rem;">';
                    echo '<div style="font-size:0.8rem; text-transform:uppercase; letter-spacing:0.08em; color:#7dd3fc; margin-bottom:0.4rem;">Pedido relacionado</div>';
                    if ($relatedOrderId > 0) {
                        echo '<a href="' . htmlspecialchars($relatedOrderHref) . '" class="btn btn-outline-info btn-sm fw-semibold" style="border-color:#00fff7; color:#00fff7; background:#181f2a;">Ver pedido #' . htmlspecialchars((string) $relatedOrderId) . '</a>';
                    } else {
                        echo '<div class="text-secondary">Sin pedido</div>';
                    }
                    echo '</div>';
                    echo '</div>';
                    echo '</div>';
                }
                echo '</div>';

                if ($movementTotalPages > 1) {
                    echo '<div class="mt-4" style="background:#181f2a; border-radius:16px; border:1px solid rgba(0,255,247,0.3); box-shadow:0 0 18px rgba(0,255,247,0.08); padding:1rem;">';
                    echo '<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">';
                    echo '<div class="text-secondary small">Página actual: ' . $movementPage . ' / ' . $movementTotalPages . '</div>';
                    echo '<div class="text-secondary small">Movimientos por página: ' . $movementPerPage . '</div>';
                    echo '</div>';
                    echo '<div class="d-grid d-sm-flex flex-wrap justify-content-center gap-2">';

                    $previousQuery = $movementBaseQuery;
                    $previousQuery['pagina'] = max(1, $movementPage - 1);
                    $nextQuery = $movementBaseQuery;
                    $nextQuery['pagina'] = min($movementTotalPages, $movementPage + 1);

                    if ($movementPage > 1) {
                        echo '<a href="' . htmlspecialchars(admin_build_url($adminMovementsPath, $previousQuery)) . '" data-movements-ajax-link="1" class="btn btn-outline-info btn-sm fw-semibold" style="min-width:110px; border-color:#00fff7; color:#00fff7; background:#181f2a;">Anterior</a>';
                    }

                    $pageStart = max(1, $movementPage - 2);
                    $pageEnd = min($movementTotalPages, $movementPage + 2);
                    for ($pageNumber = $pageStart; $pageNumber <= $pageEnd; $pageNumber++) {
                        $pageQuery = $movementBaseQuery;
                        $pageQuery['pagina'] = $pageNumber;
                        $isActivePage = $pageNumber === $movementPage;
                        $pageStyle = $isActivePage
                            ? 'background:#00fff7; color:#181f2a; border:1px solid #00fff7; box-shadow:0 0 8px #00fff7;'
                            : 'border-color:#00fff7; color:#00fff7; background:#181f2a;';
                        echo '<a href="' . htmlspecialchars(admin_build_url($adminMovementsPath, $pageQuery)) . '" data-movements-ajax-link="1" class="btn btn-sm fw-semibold ' . ($isActivePage ? 'btn-info' : 'btn-outline-info') . '" style="min-width:44px; ' . $pageStyle . '">' . $pageNumber . '</a>';
                    }

                    if ($movementPage < $movementTotalPages) {
                        echo '<a href="' . htmlspecialchars(admin_build_url($adminMovementsPath, $nextQuery)) . '" data-movements-ajax-link="1" class="btn btn-outline-info btn-sm fw-semibold" style="min-width:110px; border-color:#00fff7; color:#00fff7; background:#181f2a;">Siguiente</a>';
                    }

                    echo '</div>';
                    echo '</div>';
                }
                echo '</div>';
                break;
            case 'pedidos':
                echo '<h2 class="text-2xl font-semibold mb-4 text-cyan-300">Gestión de Pedidos</h2>';
                echo '<p class="text-gray-400">Aquí se listarán y gestionarán los pedidos.</p>';
                break;
            case 'instrucciones-influencer':
                require_once __DIR__ . '/admin_influencer_instructions.php';
                break;
            case 'ventana-inicial-juegos':
                require_once __DIR__ . '/admin_game_entry_window_per_game.php';
                break;
            case 'configuracion':
                require_once __DIR__ . '/admin_configuracion.php';
                break;
        }
        ?>
    </div>
</div>
<?php if ($seccion === 'movimientos'): ?>
<style>
    .movement-toast {
        position: fixed;
        right: 24px;
        bottom: 24px;
        z-index: 1400;
        min-width: 240px;
        max-width: min(90vw, 360px);
        padding: 0.95rem 1rem;
        border-radius: 14px;
        border: 1px solid rgba(126, 231, 135, 0.65);
        background: linear-gradient(135deg, rgba(24, 63, 43, 0.98), rgba(31, 90, 53, 0.98));
        color: #eafff0;
        box-shadow: 0 0 24px rgba(126, 231, 135, 0.28);
        font-weight: 700;
        opacity: 0;
        transform: translateY(14px);
        pointer-events: none;
        transition: opacity 180ms ease, transform 220ms ease;
    }
    .movement-toast.is-visible {
        opacity: 1;
        transform: translateY(0);
    }
    @keyframes movement-sync-spin {
        from {
            transform: rotate(0deg);
        }
        to {
            transform: rotate(360deg);
        }
    }
    [data-movement-row],
    [data-movement-card] {
        transition: opacity 220ms ease, transform 260ms ease, box-shadow 220ms ease, filter 220ms ease;
    }
    .movement-removing {
        opacity: 0;
        transform: translateY(-8px) scale(0.985);
        filter: saturate(0.88);
    }
</style>
<script>
(() => {
    const filterForm = document.querySelector('[data-movement-filter-form]');
    const filterClearLink = document.querySelector('[data-movement-filter-clear]');
    const movementRootSelector = '[data-movements-refresh-root]';
    const movementForms = document.querySelectorAll('[data-verify-movement-form]');
    const syncForm = document.querySelector('[data-sync-movements-form]');
    if (!filterForm && !movementForms.length && !syncForm) {
        return;
    }

    let filterAutoSubmitTimer = 0;
    let movementsRequestController = null;

    function wait(ms) {
        return new Promise((resolve) => window.setTimeout(resolve, ms));
    }

    function getMovementRoot() {
        return document.querySelector(movementRootSelector);
    }

    function getCurrentCheckedFilter() {
        const checkedField = filterForm ? filterForm.querySelector('[name="estado_verificacion"]') : null;
        return checkedField ? String(checkedField.value || 'no_verificados') : 'no_verificados';
    }

    function getSyncElements() {
        return {
            syncStatus: document.querySelector('[data-sync-movements-status]'),
            syncStatusTitle: document.querySelector('[data-sync-movements-title]'),
            syncStatusMessage: document.querySelector('[data-sync-movements-message]'),
            syncSpinner: document.querySelector('[data-sync-movements-spinner]')
        };
    }

    function syncFilterFormFromDocument(doc) {
        if (!filterForm) {
            return;
        }

        const nextFilterForm = doc.querySelector('[data-movement-filter-form]');
        if (!nextFilterForm) {
            return;
        }

        const nextValuesByName = new Map();
        nextFilterForm.querySelectorAll('[name]').forEach((control) => {
            nextValuesByName.set(control.getAttribute('name') || '', control.value);
        });

        filterForm.querySelectorAll('[name]').forEach((control) => {
            const controlName = control.getAttribute('name') || '';
            if (!nextValuesByName.has(controlName)) {
                return;
            }
            control.value = nextValuesByName.get(controlName);
        });
    }

    function buildFilterUrl() {
        if (!filterForm) {
            return window.location.href;
        }

        const formData = new FormData(filterForm);
        const params = new URLSearchParams();

        formData.forEach((value, key) => {
            const normalizedValue = String(value ?? '').trim();
            if (normalizedValue !== '') {
                params.set(key, normalizedValue);
            }
        });

        params.set('pagina', '1');

        const action = filterForm.getAttribute('action') || window.location.pathname;
        const queryString = params.toString();
        return queryString ? `${action}?${queryString}` : action;
    }

    async function refreshMovementsContent(url, updateHistory = true) {
        const movementRoot = getMovementRoot();
        if (!movementRoot) {
            window.location.assign(url);
            return;
        }

        if (movementsRequestController) {
            movementsRequestController.abort();
        }
        movementsRequestController = new AbortController();

        movementRoot.style.opacity = '0.55';
        movementRoot.style.pointerEvents = 'none';

        try {
            const response = await fetch(url, {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'text/html'
                },
                credentials: 'same-origin',
                signal: movementsRequestController.signal
            });

            if (!response.ok) {
                throw new Error('No se pudo actualizar la tabla de movimientos.');
            }

            const html = await response.text();
            const doc = new DOMParser().parseFromString(html, 'text/html');
            const nextRoot = doc.querySelector(movementRootSelector);
            if (!nextRoot) {
                throw new Error('La respuesta no contiene la tabla de movimientos.');
            }

            movementRoot.replaceWith(nextRoot);
            syncFilterFormFromDocument(doc);

            if (updateHistory) {
                window.history.replaceState({}, '', url);
            }
        } catch (error) {
            if (error && error.name === 'AbortError') {
                return;
            }
            window.location.assign(url);
        } finally {
            const activeRoot = getMovementRoot();
            if (activeRoot) {
                activeRoot.style.opacity = '';
                activeRoot.style.pointerEvents = '';
            }
            movementsRequestController = null;
        }
    }

    function submitFilterFormImmediately() {
        const nextUrl = buildFilterUrl();
        const currentUrl = `${window.location.pathname}${window.location.search}`;
        if (nextUrl === currentUrl) {
            return;
        }

        refreshMovementsContent(nextUrl, true);
    }

    function scheduleFilterAutoSubmit(delay) {
        window.clearTimeout(filterAutoSubmitTimer);
        filterAutoSubmitTimer = window.setTimeout(() => {
            submitFilterFormImmediately();
        }, delay);
    }

    function setSyncStatus(type, title, message, isLoading) {
        const { syncStatus, syncStatusTitle, syncStatusMessage, syncSpinner } = getSyncElements();
        if (!syncStatus || !syncStatusTitle || !syncStatusMessage) {
            return;
        }

        syncStatus.classList.remove('d-none');
        syncStatus.style.borderColor = type === 'success'
            ? 'rgba(126,231,135,0.35)'
            : (type === 'error' ? 'rgba(248,113,113,0.35)' : 'rgba(0,255,247,0.24)');
        syncStatus.style.background = type === 'success'
            ? 'rgba(24,63,43,0.92)'
            : (type === 'error' ? 'rgba(69,22,22,0.92)' : 'rgba(15,23,42,0.88)');
        syncStatusTitle.style.color = type === 'success'
            ? '#a8ffbf'
            : (type === 'error' ? '#fca5a5' : '#00fff7');
        syncStatusTitle.textContent = title;
        syncStatusMessage.textContent = message;

        if (syncSpinner) {
            syncSpinner.classList.toggle('d-none', !isLoading);
        }
    }

    function showMovementToast(message) {
        let toast = document.querySelector('[data-movement-toast]');
        if (!toast) {
            toast = document.createElement('div');
            toast.className = 'movement-toast';
            toast.setAttribute('data-movement-toast', '1');
            document.body.appendChild(toast);
        }

        toast.textContent = message;
        toast.classList.add('is-visible');

        window.clearTimeout(showMovementToast._timerId);
        showMovementToast._timerId = window.setTimeout(() => {
            toast.classList.remove('is-visible');
        }, 2000);
    }

    function applyVerifiedDesktopState(movementId) {
        const row = document.querySelector(`[data-movement-row="${movementId}"]`);
        if (!row) {
            return;
        }

        row.dataset.movementChecked = '1';
        row.style.boxShadow = 'inset 0 0 0 1px rgba(125, 211, 252, 0.08)';

        const desktopColors = {
            reference: '#d9ffe8',
            description: '#ecfff2',
            date: '#d7ffe6',
            amount: '#9effbd',
            currency: '#d7ffe6',
            verify: '#b2f6ff',
            order: '#b2f6ff'
        };

        row.querySelectorAll('[data-movement-cell]').forEach((cell) => {
            const cellKey = cell.getAttribute('data-movement-cell') || '';
            cell.style.background = '#183f2b';
            if (desktopColors[cellKey]) {
                cell.style.color = desktopColors[cellKey];
            }
        });

        const verifyCell = row.querySelector(`[data-movement-verify-cell="${movementId}"]`);
        if (verifyCell) {
            verifyCell.innerHTML = '<span class="fw-bold" style="color:#a8ffbf;">Verificado</span>';
        }
    }

    function applyVerifiedMobileState(movementId) {
        const card = document.querySelector(`[data-movement-card="${movementId}"]`);
        if (!card) {
            return;
        }

        card.dataset.movementChecked = '1';
        card.style.background = 'linear-gradient(135deg, #183f2b, #1f5a35)';
        card.style.borderColor = '#7ee787';
        card.style.boxShadow = '0 0 24px rgba(126,231,135,0.24)';

        const form = card.querySelector('[data-verify-movement-form]');
        if (form) {
            form.outerHTML = '<div class="fw-bold" style="color:#a8ffbf;">Verificado</div>';
        }
    }

    function updateMovementCountersAfterVerify() {
        const uncheckedLabel = document.querySelector('[data-movement-count-label="no_verificados"]');
        const checkedLabel = document.querySelector('[data-movement-count-label="verificados"]');

        if (uncheckedLabel) {
            const nextValue = Math.max(0, Number(uncheckedLabel.textContent || '0') - 1);
            uncheckedLabel.textContent = String(nextValue);
        }
        if (checkedLabel) {
            checkedLabel.textContent = String(Number(checkedLabel.textContent || '0') + 1);
        }
    }

    function updateVisibleResultsAfterRemoval() {
        const resultsTotal = document.querySelector('[data-movement-results-total]');
        const rangeLabel = document.querySelector('[data-movement-range-label]');
        if (resultsTotal) {
            const nextValue = Math.max(0, Number(resultsTotal.textContent || '0') - 1);
            resultsTotal.textContent = String(nextValue);
            if (rangeLabel) {
                const currentText = rangeLabel.textContent || '';
                rangeLabel.textContent = currentText.replace(/de\s+\d+$/, 'de ' + nextValue);
            }
        }
    }

    function removeMovementFromView(movementId) {
        const row = document.querySelector(`[data-movement-row="${movementId}"]`);
        if (row) {
            row.classList.add('movement-removing');
        }

        const card = document.querySelector(`[data-movement-card="${movementId}"]`);
        if (card) {
            card.classList.add('movement-removing');
        }

        window.setTimeout(() => {
            if (row) {
                row.remove();
            }

            if (card) {
                card.remove();
            }

            updateVisibleResultsAfterRemoval();
        }, 320);
    }

    async function animateVerifiedMovement(movementId, shouldRemoveAfterAnimation) {
        applyVerifiedDesktopState(movementId);
        applyVerifiedMobileState(movementId);
        showMovementToast('Movimiento verificado');

        if (shouldRemoveAfterAnimation) {
            await wait(900);
            removeMovementFromView(movementId);
        }
    }

    async function verifyMovement(form) {
        const button = form.querySelector('[data-verify-movement-button]');
        const formData = new FormData(form);
        const movementId = formData.get('movimiento_id');

        window.clearTimeout(filterAutoSubmitTimer);

        if (button) {
            button.disabled = true;
            button.style.opacity = '0.7';
        }

        try {
            const response = await fetch(form.action, {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json, text/plain, */*'
                },
                body: formData
            });

            const data = await response.json();
            if (!response.ok || !data.ok) {
                throw new Error(data.message || 'No se pudo verificar el movimiento.');
            }

            updateMovementCountersAfterVerify();
            if (getCurrentCheckedFilter() === 'no_verificados') {
                await animateVerifiedMovement(String(movementId), true);
            } else {
                await animateVerifiedMovement(String(movementId), false);
            }
        } catch (error) {
            const errorMessage = error && error.message ? String(error.message) : '';
            if (/failed to fetch/i.test(errorMessage)) {
                if (button) {
                    button.disabled = false;
                    button.style.opacity = '1';
                }
                HTMLFormElement.prototype.submit.call(form);
                return;
            }

            if (button) {
                button.disabled = false;
                button.style.opacity = '1';
            }
            alert(error.message || 'No se pudo verificar el movimiento.');
        }
    }

    if (filterForm) {
        const filterControls = filterForm.querySelectorAll('input[name], select[name]');
        filterControls.forEach((control) => {
            const controlType = (control.getAttribute('type') || '').toLowerCase();
            const isTypingControl = control.tagName === 'INPUT' && (controlType === 'search' || controlType === 'text' || controlType === 'number');
            const autoSubmitDelay = isTypingControl ? 2000 : 180;

            control.addEventListener('input', () => {
                scheduleFilterAutoSubmit(autoSubmitDelay);
            });

            control.addEventListener('change', () => {
                scheduleFilterAutoSubmit(180);
            });
        });

        filterForm.addEventListener('submit', () => {
            window.clearTimeout(filterAutoSubmitTimer);
        });
    }

    document.addEventListener('click', (event) => {
        const ajaxLink = event.target instanceof Element ? event.target.closest('a[data-movements-ajax-link="1"]') : null;
        if (ajaxLink) {
            event.preventDefault();
            refreshMovementsContent(ajaxLink.href, true);
            return;
        }

        const clearLink = event.target instanceof Element ? event.target.closest('a[data-movement-filter-clear="1"]') : null;
        if (clearLink && filterForm) {
            event.preventDefault();
            const defaults = {
                referencia: '',
                fecha_desde: '',
                fecha_hasta: '',
                moneda: '',
                estado_verificacion: 'no_verificados',
                pedido_relacionado: 'todos',
                orden: 'fecha_movimiento',
                direccion: 'desc',
                por_pagina: '15'
            };

            Object.keys(defaults).forEach((fieldName) => {
                const field = filterForm.querySelector(`[name="${fieldName}"]`);
                if (field) {
                    field.value = defaults[fieldName];
                }
            });

            refreshMovementsContent(clearLink.href, true);
        }
    });

    document.addEventListener('submit', async (event) => {
        const target = event.target;
        if (!(target instanceof HTMLFormElement)) {
            return;
        }

        if (filterForm && target === filterForm) {
            event.preventDefault();
            window.clearTimeout(filterAutoSubmitTimer);
            submitFilterFormImmediately();
            return;
        }

        if (target.matches('[data-verify-movement-form]')) {
            event.preventDefault();
            verifyMovement(target);
            return;
        }

        if (target.matches('[data-sync-movements-form]')) {
            event.preventDefault();
            window.clearTimeout(filterAutoSubmitTimer);

            const formData = new FormData(target);
            const submitButton = target.querySelector('[data-sync-movements-button]');
            if (submitButton) {
                submitButton.disabled = true;
                submitButton.style.opacity = '0.75';
            }

            setSyncStatus('loading', 'Consultando API', 'Buscando nuevos movimientos y registrando cambios en la tabla movimientos...', true);

            try {
                const response = await fetch(target.action, {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json, text/plain, */*'
                    },
                    body: formData
                });

                const data = await response.json();
                if (!response.ok || !data.ok) {
                    throw new Error(data.message || 'No se pudo actualizar los movimientos desde la API.');
                }

                const availableDaysSuffix = data.dias_disponibles ? ` La API reporta ${data.dias_disponibles} días disponibles.` : '';

                if (data.has_sync_changes) {
                    const successTitle = data.has_new_movements ? 'Nuevos movimientos disponibles' : 'Movimientos sincronizados';
                    setSyncStatus('success', successTitle, (data.message || 'La tabla movimientos fue sincronizada con la API.') + availableDaysSuffix, false);
                    showMovementToast('Movimientos actualizados desde la API');
                    await wait(1500);
                    await refreshMovementsContent(`${window.location.pathname}${window.location.search}`, false);
                    return;
                }

                setSyncStatus('info', 'Sin movimientos nuevos', (data.message || 'No hay movimientos nuevos para actualizar.') + availableDaysSuffix, false);
                await wait(3000);
                const { syncStatus } = getSyncElements();
                if (syncStatus) {
                    syncStatus.classList.add('d-none');
                }
            } catch (error) {
                setSyncStatus('error', 'No se pudo actualizar', error.message || 'Ocurrió un error al consultar la API.', false);
            } finally {
                if (submitButton) {
                    submitButton.disabled = false;
                    submitButton.style.opacity = '1';
                }
            }
        }
    });
})();
</script>
<?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
