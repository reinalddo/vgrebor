<?php

require_once __DIR__ . '/store_config.php';

if (!function_exists('game_entry_window_feature_default')) {
    function game_entry_window_feature_default(): string {
        return '0';
    }
}

if (!function_exists('game_entry_window_defaults')) {
    function game_entry_window_defaults(): array {
        return [
            'title' => 'ANTES DE CONTINUAR',
            'icon' => game_entry_window_default_icon_path(),
            'copy' => 'Lee la informacion antes de continuar con la recarga.',
            'check_text' => 'He leido y entiendo las condiciones del servicio',
            'button_text' => 'Aceptar y continuar',
            'modal_background' => '#18101e',
            'modal_border_color' => '#fb923c',
            'title_color' => '#f8b53d',
            'check_text_color' => '#e2e8f0',
            'check_background_color' => '#1e293b',
            'button_text_color' => '#0b0f18',
            'button_background_color' => '#c99712',
            'button_disabled_text_color' => '#0b0f18',
            'button_disabled_background_color' => '#c99712',
        ];
    }
}

if (!function_exists('game_entry_window_global_config_descriptions')) {
    function game_entry_window_global_config_descriptions(): array {
        return [
            'ventana_inicio_juego' => 'Activa o desactiva la funcion de ventanas iniciales por juego.',
        ];
    }
}

if (!function_exists('game_entry_window_default_icon_path')) {
    function game_entry_window_default_icon_path(): string {
        return function_exists('app_path') ? app_path('/assets/img/game-entry-window-default-icon.svg') : '/assets/img/game-entry-window-default-icon.svg';
    }
}

if (!function_exists('game_entry_window_resolve_icon_path')) {
    function game_entry_window_resolve_icon_path(?string $path = null): string {
        $candidate = trim((string) $path);
        if ($candidate !== '') {
            return $candidate;
        }

        return game_entry_window_default_icon_path();
    }
}

if (!function_exists('game_entry_window_feature_available')) {
    function game_entry_window_feature_available(): bool {
        game_entry_window_ensure_global_config_defaults();
        return trim((string) store_config_get('ventana_inicio_juego', game_entry_window_feature_default())) === '1';
    }
}

if (!function_exists('game_entry_window_enabled')) {
    function game_entry_window_enabled(): bool {
        return game_entry_window_feature_available();
    }
}

if (!function_exists('game_entry_window_ensure_global_config_defaults')) {
    function game_entry_window_ensure_global_config_defaults(): void {
        $config = store_config_all();
        if (array_key_exists('ventana_inicio_juego', $config)) {
            return;
        }

        $descriptions = game_entry_window_global_config_descriptions();
        store_config_upsert('ventana_inicio_juego', game_entry_window_feature_default(), $descriptions['ventana_inicio_juego'] ?? null);
    }
}

if (!function_exists('game_entry_window_config_table_name')) {
    function game_entry_window_config_table_name(): string {
        return 'ventana_inicio_juego_configuracion';
    }
}

if (!function_exists('game_entry_window_table_name')) {
    function game_entry_window_table_name(): string {
        return 'ventana_inicio_juego_tarjetas';
    }
}

if (!function_exists('game_entry_window_default_card_template')) {
    function game_entry_window_default_card_template(int $order = 1): array {
        return [
            'id' => 0,
            'juego_id' => 0,
            'activo' => 1,
            'orden' => $order,
            'color' => '#233A73',
            'background_color' => '#121a2f',
            'media_path' => '',
            'media_embed_url' => '',
            'content_html' => '<p><strong>Que es este servicio?</strong></p><p>Esta pagina ofrece <strong>recargas automaticas</strong> para los juegos disponibles. Todo el proceso se valida desde el sistema.</p>',
        ];
    }
}

if (!function_exists('game_entry_window_game_id')) {
    function game_entry_window_game_id($value): int {
        return max(0, (int) $value);
    }
}

if (!function_exists('game_entry_window_fetch_game')) {
    function game_entry_window_fetch_game(mysqli $mysqli, int $gameId): ?array {
        if ($gameId <= 0) {
            return null;
        }

        $stmt = $mysqli->prepare('SELECT id, nombre, slug, activo FROM juegos WHERE id = ? LIMIT 1');
        if (!$stmt) {
            return null;
        }

        $stmt->bind_param('i', $gameId);
        $stmt->execute();
        $result = $stmt->get_result();
        $game = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
        $stmt->close();

        return is_array($game) ? $game : null;
    }
}

if (!function_exists('game_entry_window_legacy_seed_config')) {
    function game_entry_window_legacy_seed_config(): array {
        $defaults = game_entry_window_defaults();

        return [
            'enabled' => 0,
            'title' => trim((string) store_config_get('ventana_inicio_juego_titulo', $defaults['title'])) ?: $defaults['title'],
            'icon' => game_entry_window_resolve_icon_path(store_config_get('ventana_inicio_juego_icono', $defaults['icon'])),
            'copy' => trim((string) store_config_get('ventana_inicio_juego_descripcion', $defaults['copy'])) ?: $defaults['copy'],
            'check_text' => trim((string) store_config_get('ventana_inicio_juego_check_texto', $defaults['check_text'])) ?: $defaults['check_text'],
            'button_text' => trim((string) store_config_get('ventana_inicio_juego_boton_texto', $defaults['button_text'])) ?: $defaults['button_text'],
            'modal_background' => store_config_normalize_hex_color((string) store_config_get('ventana_inicio_juego_modal_background', $defaults['modal_background']), $defaults['modal_background']),
            'modal_border_color' => store_config_normalize_hex_color((string) store_config_get('ventana_inicio_juego_modal_border_color', $defaults['modal_border_color']), $defaults['modal_border_color']),
            'title_color' => store_config_normalize_hex_color((string) store_config_get('ventana_inicio_juego_title_color', $defaults['title_color']), $defaults['title_color']),
            'check_text_color' => store_config_normalize_hex_color((string) store_config_get('ventana_inicio_juego_check_text_color', $defaults['check_text_color']), $defaults['check_text_color']),
            'check_background_color' => store_config_normalize_hex_color((string) store_config_get('ventana_inicio_juego_check_background_color', $defaults['check_background_color']), $defaults['check_background_color']),
            'button_text_color' => store_config_normalize_hex_color((string) store_config_get('ventana_inicio_juego_button_text_color', $defaults['button_text_color']), $defaults['button_text_color']),
            'button_background_color' => store_config_normalize_hex_color((string) store_config_get('ventana_inicio_juego_button_background_color', $defaults['button_background_color']), $defaults['button_background_color']),
            'button_disabled_text_color' => store_config_normalize_hex_color((string) store_config_get('ventana_inicio_juego_button_disabled_text_color', $defaults['button_disabled_text_color']), $defaults['button_disabled_text_color']),
            'button_disabled_background_color' => store_config_normalize_hex_color((string) store_config_get('ventana_inicio_juego_button_disabled_background_color', $defaults['button_disabled_background_color']), $defaults['button_disabled_background_color']),
        ];
    }
}

if (!function_exists('game_entry_window_ensure_config_table')) {
    function game_entry_window_ensure_config_table(mysqli $mysqli): void {
        $table = game_entry_window_config_table_name();
        $mysqli->query(
            "CREATE TABLE IF NOT EXISTS {$table} (
                id INT AUTO_INCREMENT PRIMARY KEY,
                juego_id INT NOT NULL,
                activa TINYINT(1) NOT NULL DEFAULT 0,
                titulo VARCHAR(255) NOT NULL,
                icono TEXT NOT NULL,
                descripcion TEXT NOT NULL,
                check_texto TEXT NOT NULL,
                boton_texto VARCHAR(255) NOT NULL,
                modal_background VARCHAR(7) NOT NULL DEFAULT '#18101e',
                modal_border_color VARCHAR(7) NOT NULL DEFAULT '#fb923c',
                title_color VARCHAR(7) NOT NULL DEFAULT '#f8b53d',
                check_text_color VARCHAR(7) NOT NULL DEFAULT '#e2e8f0',
                check_background_color VARCHAR(7) NOT NULL DEFAULT '#1e293b',
                button_text_color VARCHAR(7) NOT NULL DEFAULT '#0b0f18',
                button_background_color VARCHAR(7) NOT NULL DEFAULT '#c99712',
                button_disabled_text_color VARCHAR(7) NOT NULL DEFAULT '#0b0f18',
                button_disabled_background_color VARCHAR(7) NOT NULL DEFAULT '#c99712',
                creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                actualizado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_juego (juego_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $existing = [];
        $columnResult = $mysqli->query("SHOW COLUMNS FROM {$table}");
        if ($columnResult instanceof mysqli_result) {
            while ($row = $columnResult->fetch_assoc()) {
                $existing[$row['Field']] = true;
            }
        }

        $requiredColumns = [
            'juego_id' => "ALTER TABLE {$table} ADD COLUMN juego_id INT NOT NULL AFTER id",
            'activa' => "ALTER TABLE {$table} ADD COLUMN activa TINYINT(1) NOT NULL DEFAULT 0 AFTER juego_id",
            'titulo' => "ALTER TABLE {$table} ADD COLUMN titulo VARCHAR(255) NOT NULL AFTER activa",
            'icono' => "ALTER TABLE {$table} ADD COLUMN icono TEXT NOT NULL AFTER titulo",
            'descripcion' => "ALTER TABLE {$table} ADD COLUMN descripcion TEXT NOT NULL AFTER icono",
            'check_texto' => "ALTER TABLE {$table} ADD COLUMN check_texto TEXT NOT NULL AFTER descripcion",
            'boton_texto' => "ALTER TABLE {$table} ADD COLUMN boton_texto VARCHAR(255) NOT NULL AFTER check_texto",
            'modal_background' => "ALTER TABLE {$table} ADD COLUMN modal_background VARCHAR(7) NOT NULL DEFAULT '#18101e' AFTER boton_texto",
            'modal_border_color' => "ALTER TABLE {$table} ADD COLUMN modal_border_color VARCHAR(7) NOT NULL DEFAULT '#fb923c' AFTER modal_background",
            'title_color' => "ALTER TABLE {$table} ADD COLUMN title_color VARCHAR(7) NOT NULL DEFAULT '#f8b53d' AFTER modal_border_color",
            'check_text_color' => "ALTER TABLE {$table} ADD COLUMN check_text_color VARCHAR(7) NOT NULL DEFAULT '#e2e8f0' AFTER title_color",
            'check_background_color' => "ALTER TABLE {$table} ADD COLUMN check_background_color VARCHAR(7) NOT NULL DEFAULT '#1e293b' AFTER check_text_color",
            'button_text_color' => "ALTER TABLE {$table} ADD COLUMN button_text_color VARCHAR(7) NOT NULL DEFAULT '#0b0f18' AFTER check_background_color",
            'button_background_color' => "ALTER TABLE {$table} ADD COLUMN button_background_color VARCHAR(7) NOT NULL DEFAULT '#c99712' AFTER button_text_color",
            'button_disabled_text_color' => "ALTER TABLE {$table} ADD COLUMN button_disabled_text_color VARCHAR(7) NOT NULL DEFAULT '#0b0f18' AFTER button_background_color",
            'button_disabled_background_color' => "ALTER TABLE {$table} ADD COLUMN button_disabled_background_color VARCHAR(7) NOT NULL DEFAULT '#c99712' AFTER button_disabled_text_color",
            'creado_en' => "ALTER TABLE {$table} ADD COLUMN creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER button_disabled_background_color",
            'actualizado_en' => "ALTER TABLE {$table} ADD COLUMN actualizado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER creado_en",
        ];

        foreach ($requiredColumns as $column => $sql) {
            if (!isset($existing[$column])) {
                $mysqli->query($sql);
            }
        }

        $indexResult = $mysqli->query("SHOW INDEX FROM {$table} WHERE Key_name = 'uniq_juego'");
        if (!($indexResult instanceof mysqli_result) || $indexResult->num_rows === 0) {
            $mysqli->query("ALTER TABLE {$table} ADD UNIQUE KEY uniq_juego (juego_id)");
        }
    }
}

if (!function_exists('game_entry_window_ensure_cards_table')) {
    function game_entry_window_ensure_cards_table(mysqli $mysqli): void {
        $table = game_entry_window_table_name();
        $mysqli->query(
            "CREATE TABLE IF NOT EXISTS {$table} (
                id INT AUTO_INCREMENT PRIMARY KEY,
                juego_id INT NOT NULL DEFAULT 0,
                content_html LONGTEXT NOT NULL,
                color VARCHAR(7) NOT NULL DEFAULT '#233A73',
                background_color VARCHAR(7) NOT NULL DEFAULT '#121a2f',
                media_path TEXT NULL,
                media_embed_url TEXT NULL,
                activo TINYINT(1) NOT NULL DEFAULT 1,
                orden INT NOT NULL DEFAULT 1,
                creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                actualizado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_game_active_order (juego_id, activo, orden)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $existing = [];
        $columnResult = $mysqli->query("SHOW COLUMNS FROM {$table}");
        if ($columnResult instanceof mysqli_result) {
            while ($row = $columnResult->fetch_assoc()) {
                $existing[$row['Field']] = true;
            }
        }

        $requiredColumns = [
            'juego_id' => "ALTER TABLE {$table} ADD COLUMN juego_id INT NOT NULL DEFAULT 0 AFTER id",
            'content_html' => "ALTER TABLE {$table} ADD COLUMN content_html LONGTEXT NOT NULL AFTER juego_id",
            'color' => "ALTER TABLE {$table} ADD COLUMN color VARCHAR(7) NOT NULL DEFAULT '#233A73' AFTER content_html",
            'background_color' => "ALTER TABLE {$table} ADD COLUMN background_color VARCHAR(7) NOT NULL DEFAULT '#121a2f' AFTER color",
            'media_path' => "ALTER TABLE {$table} ADD COLUMN media_path TEXT NULL AFTER background_color",
            'media_embed_url' => "ALTER TABLE {$table} ADD COLUMN media_embed_url TEXT NULL AFTER media_path",
            'activo' => "ALTER TABLE {$table} ADD COLUMN activo TINYINT(1) NOT NULL DEFAULT 1 AFTER media_embed_url",
            'orden' => "ALTER TABLE {$table} ADD COLUMN orden INT NOT NULL DEFAULT 1 AFTER activo",
            'creado_en' => "ALTER TABLE {$table} ADD COLUMN creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER orden",
            'actualizado_en' => "ALTER TABLE {$table} ADD COLUMN actualizado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER creado_en",
        ];

        foreach ($requiredColumns as $column => $sql) {
            if (!isset($existing[$column])) {
                $mysqli->query($sql);
            }
        }

        $indexResult = $mysqli->query("SHOW INDEX FROM {$table} WHERE Key_name = 'idx_game_active_order'");
        if (!($indexResult instanceof mysqli_result) || $indexResult->num_rows === 0) {
            $mysqli->query("ALTER TABLE {$table} ADD INDEX idx_game_active_order (juego_id, activo, orden)");
        }
    }
}

if (!function_exists('game_entry_window_ensure_schema')) {
    function game_entry_window_ensure_schema(mysqli $mysqli): void {
        game_entry_window_ensure_global_config_defaults();
        game_entry_window_ensure_config_table($mysqli);
        game_entry_window_ensure_cards_table($mysqli);
    }
}

if (!function_exists('game_entry_window_media_dir_segment')) {
    function game_entry_window_media_dir_segment(): string {
        return 'store/game-entry-window';
    }
}

if (!function_exists('game_entry_window_media_absolute_dir')) {
    function game_entry_window_media_absolute_dir(): string {
        if (function_exists('tenant_upload_absolute_dir')) {
            return tenant_upload_absolute_dir(game_entry_window_media_dir_segment());
        }

        return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'media' . DIRECTORY_SEPARATOR . 'game-entry-window';
    }
}

if (!function_exists('game_entry_window_media_public_path')) {
    function game_entry_window_media_public_path(string $fileName): string {
        if (function_exists('tenant_upload_public_path')) {
            return tenant_upload_public_path(game_entry_window_media_dir_segment(), $fileName, true);
        }

        return '/assets/media/game-entry-window/' . ltrim($fileName, '/');
    }
}

if (!function_exists('game_entry_window_is_managed_media_path')) {
    function game_entry_window_is_managed_media_path(string $relativePath): bool {
        $path = trim($relativePath);
        if ($path === '') {
            return false;
        }

        return str_contains($path, '/game-entry-window/');
    }
}

if (!function_exists('game_entry_window_delete_media_file')) {
    function game_entry_window_delete_media_file(string $relativePath): void {
        if (!game_entry_window_is_managed_media_path($relativePath)) {
            return;
        }

        $absolutePath = function_exists('tenant_resolve_public_path') ? tenant_resolve_public_path($relativePath) : dirname(__DIR__) . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        if ($absolutePath !== null && is_file($absolutePath)) {
            @unlink($absolutePath);
        }
    }
}

if (!function_exists('game_entry_window_delete_icon')) {
    function game_entry_window_delete_icon(string $relativePath): void {
        if (trim($relativePath) === '' || trim($relativePath) === game_entry_window_default_icon_path()) {
            return;
        }

        store_config_delete_logo_file($relativePath);
    }
}

if (!function_exists('game_entry_window_store_icon_upload')) {
    function game_entry_window_store_icon_upload(array $file, int $gameId): array {
        return store_config_store_named_logo_upload($file, 'game-entry-window-icon-' . $gameId);
    }
}

if (!function_exists('game_entry_window_store_media_upload')) {
    function game_entry_window_store_media_upload(array $file, int $gameId, int $order): array {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return ['success' => true, 'path' => ''];
        }

        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            return ['success' => false, 'message' => 'No se pudo cargar el archivo multimedia.'];
        }

        $tmpName = (string) ($file['tmp_name'] ?? '');
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            return ['success' => false, 'message' => 'El archivo multimedia no es valido.'];
        }

        if (($file['size'] ?? 0) > 20 * 1024 * 1024) {
            return ['success' => false, 'message' => 'La multimedia no puede superar 20 MB.'];
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = (string) $finfo->file($tmpName);
        $extensions = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            'video/mp4' => 'mp4',
            'video/webm' => 'webm',
            'video/ogg' => 'ogv',
            'video/quicktime' => 'mov',
        ];

        if (!isset($extensions[$mime])) {
            return ['success' => false, 'message' => 'Formato multimedia no permitido. Usa imagen o video compatible.'];
        }

        $targetDir = game_entry_window_media_absolute_dir();
        if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
            return ['success' => false, 'message' => 'No se pudo crear la carpeta multimedia.'];
        }

        $fileName = 'game-entry-window-' . $gameId . '-' . $order . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $extensions[$mime];
        $targetPath = $targetDir . DIRECTORY_SEPARATOR . $fileName;
        if (!move_uploaded_file($tmpName, $targetPath)) {
            return ['success' => false, 'message' => 'No se pudo guardar la multimedia en el servidor.'];
        }

        return [
            'success' => true,
            'path' => game_entry_window_media_public_path($fileName),
            'mime' => $mime,
        ];
    }
}

if (!function_exists('game_entry_window_extract_file_from_array')) {
    function game_entry_window_extract_file_from_array(array $files, $key): ?array {
        if (!isset($files['name']) || !is_array($files['name']) || !array_key_exists($key, $files['name'])) {
            return null;
        }

        return [
            'name' => $files['name'][$key] ?? '',
            'type' => $files['type'][$key] ?? '',
            'tmp_name' => $files['tmp_name'][$key] ?? '',
            'error' => $files['error'][$key] ?? UPLOAD_ERR_NO_FILE,
            'size' => $files['size'][$key] ?? 0,
        ];
    }
}

if (!function_exists('game_entry_window_seed_config_for_game')) {
    function game_entry_window_seed_config_for_game(mysqli $mysqli, int $gameId): void {
        if ($gameId <= 0) {
            return;
        }

        $table = game_entry_window_config_table_name();
        $stmt = $mysqli->prepare("SELECT id FROM {$table} WHERE juego_id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $gameId);
            $stmt->execute();
            $result = $stmt->get_result();
            $existing = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
            $stmt->close();
            if ($existing) {
                return;
            }
        }

        $seed = game_entry_window_legacy_seed_config();
        $stmt = $mysqli->prepare(
            "INSERT INTO {$table} (
                juego_id, activa, titulo, icono, descripcion, check_texto, boton_texto,
                modal_background, modal_border_color, title_color, check_text_color, check_background_color,
                button_text_color, button_background_color, button_disabled_text_color, button_disabled_background_color
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        if (!$stmt) {
            return;
        }

        $enabled = (int) $seed['enabled'];
        $stmt->bind_param(
            'iissssssssssssss',
            $gameId,
            $enabled,
            $seed['title'],
            $seed['icon'],
            $seed['copy'],
            $seed['check_text'],
            $seed['button_text'],
            $seed['modal_background'],
            $seed['modal_border_color'],
            $seed['title_color'],
            $seed['check_text_color'],
            $seed['check_background_color'],
            $seed['button_text_color'],
            $seed['button_background_color'],
            $seed['button_disabled_text_color'],
            $seed['button_disabled_background_color']
        );
        $stmt->execute();
        $stmt->close();
    }
}

if (!function_exists('game_entry_window_seed_cards_for_game')) {
    function game_entry_window_seed_cards_for_game(mysqli $mysqli, int $gameId): void {
        if ($gameId <= 0) {
            return;
        }

        $table = game_entry_window_table_name();
        $stmt = $mysqli->prepare("SELECT id FROM {$table} WHERE juego_id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $gameId);
            $stmt->execute();
            $result = $stmt->get_result();
            $existing = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
            $stmt->close();
            if ($existing) {
                return;
            }
        }

        $legacyRows = [];
        $legacyResult = $mysqli->query("SELECT content_html, color, background_color, media_path, media_embed_url, activo, orden FROM {$table} WHERE juego_id = 0 ORDER BY orden ASC, id ASC");
        if ($legacyResult instanceof mysqli_result) {
            while ($row = $legacyResult->fetch_assoc()) {
                $legacyRows[] = $row;
            }
        }

        if ($legacyRows === []) {
            $legacyRows[] = game_entry_window_default_card_template();
        }

        $stmt = $mysqli->prepare("INSERT INTO {$table} (juego_id, content_html, color, background_color, media_path, media_embed_url, activo, orden) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        if (!$stmt) {
            return;
        }

        foreach ($legacyRows as $index => $row) {
            $contentHtml = trim((string) ($row['content_html'] ?? ''));
            $color = store_config_normalize_hex_color((string) ($row['color'] ?? '#233A73'), '#233A73');
            $backgroundColor = store_config_normalize_hex_color((string) ($row['background_color'] ?? '#121a2f'), '#121a2f');
            $mediaPath = trim((string) ($row['media_path'] ?? ''));
            $mediaEmbedUrl = trim((string) ($row['media_embed_url'] ?? ''));
            $active = !empty($row['activo']) ? 1 : 0;
            $order = max(1, (int) ($row['orden'] ?? ($index + 1)));
            $stmt->bind_param('isssssii', $gameId, $contentHtml, $color, $backgroundColor, $mediaPath, $mediaEmbedUrl, $active, $order);
            $stmt->execute();
        }

        $stmt->close();
    }
}

if (!function_exists('game_entry_window_fetch_config')) {
    function game_entry_window_fetch_config(mysqli $mysqli, int $gameId): array {
        game_entry_window_ensure_schema($mysqli);
        game_entry_window_seed_config_for_game($mysqli, $gameId);

        $defaults = game_entry_window_defaults();
        $seed = game_entry_window_legacy_seed_config();
        $table = game_entry_window_config_table_name();
        $stmt = $mysqli->prepare("SELECT * FROM {$table} WHERE juego_id = ? LIMIT 1");
        if (!$stmt) {
            return $seed;
        }

        $stmt->bind_param('i', $gameId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
        $stmt->close();

        if (!is_array($row)) {
            return $seed;
        }

        return [
            'enabled' => !empty($row['activa']) ? 1 : 0,
            'title' => trim((string) ($row['titulo'] ?? '')) ?: $defaults['title'],
            'icon' => game_entry_window_resolve_icon_path((string) ($row['icono'] ?? '')),
            'copy' => trim((string) ($row['descripcion'] ?? '')) ?: $defaults['copy'],
            'check_text' => trim((string) ($row['check_texto'] ?? '')) ?: $defaults['check_text'],
            'button_text' => trim((string) ($row['boton_texto'] ?? '')) ?: $defaults['button_text'],
            'modal_background' => store_config_normalize_hex_color((string) ($row['modal_background'] ?? $defaults['modal_background']), $defaults['modal_background']),
            'modal_border_color' => store_config_normalize_hex_color((string) ($row['modal_border_color'] ?? $defaults['modal_border_color']), $defaults['modal_border_color']),
            'title_color' => store_config_normalize_hex_color((string) ($row['title_color'] ?? $defaults['title_color']), $defaults['title_color']),
            'check_text_color' => store_config_normalize_hex_color((string) ($row['check_text_color'] ?? $defaults['check_text_color']), $defaults['check_text_color']),
            'check_background_color' => store_config_normalize_hex_color((string) ($row['check_background_color'] ?? $defaults['check_background_color']), $defaults['check_background_color']),
            'button_text_color' => store_config_normalize_hex_color((string) ($row['button_text_color'] ?? $defaults['button_text_color']), $defaults['button_text_color']),
            'button_background_color' => store_config_normalize_hex_color((string) ($row['button_background_color'] ?? $defaults['button_background_color']), $defaults['button_background_color']),
            'button_disabled_text_color' => store_config_normalize_hex_color((string) ($row['button_disabled_text_color'] ?? $defaults['button_disabled_text_color']), $defaults['button_disabled_text_color']),
            'button_disabled_background_color' => store_config_normalize_hex_color((string) ($row['button_disabled_background_color'] ?? $defaults['button_disabled_background_color']), $defaults['button_disabled_background_color']),
        ];
    }
}

if (!function_exists('game_entry_window_fetch_cards')) {
    function game_entry_window_fetch_cards(mysqli $mysqli, int $gameId, bool $onlyActive = true): array {
        game_entry_window_ensure_schema($mysqli);
        game_entry_window_seed_cards_for_game($mysqli, $gameId);

        $table = game_entry_window_table_name();
        $sql = "SELECT id, juego_id, content_html, color, background_color, media_path, media_embed_url, activo, orden FROM {$table} WHERE juego_id = ?";
        if ($onlyActive) {
            $sql .= ' AND activo = 1';
        }
        $sql .= ' ORDER BY orden ASC, id ASC';

        $stmt = $mysqli->prepare($sql);
        if (!$stmt) {
            return [];
        }

        $stmt->bind_param('i', $gameId);
        $stmt->execute();
        $result = $stmt->get_result();
        $cards = [];
        if ($result instanceof mysqli_result) {
            while ($row = $result->fetch_assoc()) {
                $cards[] = [
                    'id' => (int) ($row['id'] ?? 0),
                    'juego_id' => (int) ($row['juego_id'] ?? 0),
                    'content_html' => trim((string) ($row['content_html'] ?? '')),
                    'color' => store_config_normalize_hex_color((string) ($row['color'] ?? '#233A73'), '#233A73'),
                    'background_color' => store_config_normalize_hex_color((string) ($row['background_color'] ?? '#121a2f'), '#121a2f'),
                    'media_path' => trim((string) ($row['media_path'] ?? '')),
                    'media_embed_url' => trim((string) ($row['media_embed_url'] ?? '')),
                    'activo' => !empty($row['activo']) ? 1 : 0,
                    'orden' => max(1, (int) ($row['orden'] ?? 1)),
                ];
            }
        }
        $stmt->close();

        return $cards;
    }
}

if (!function_exists('game_entry_window_normalize_embed_url')) {
    function game_entry_window_normalize_embed_url(string $value): string {
        $url = trim($value);
        if ($url === '') {
            return '';
        }

        return filter_var($url, FILTER_VALIDATE_URL) ? $url : '';
    }
}

if (!function_exists('game_entry_window_extract_youtube_id')) {
    function game_entry_window_extract_youtube_id(string $url): ?string {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return null;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = trim((string) ($parts['path'] ?? ''), '/');
        if ($host === 'youtu.be' && $path !== '') {
            return preg_match('/^[A-Za-z0-9_-]{6,20}$/', $path) === 1 ? $path : null;
        }

        if (str_contains($host, 'youtube.com')) {
            parse_str((string) ($parts['query'] ?? ''), $query);
            $candidate = trim((string) ($query['v'] ?? ''));
            if ($candidate !== '') {
                return preg_match('/^[A-Za-z0-9_-]{6,20}$/', $candidate) === 1 ? $candidate : null;
            }

            if (preg_match('~^(shorts|embed)/([A-Za-z0-9_-]{6,20})$~', $path, $matches) === 1) {
                return $matches[2];
            }
        }

        return null;
    }
}

if (!function_exists('game_entry_window_extract_tiktok_id')) {
    function game_entry_window_extract_tiktok_id(string $url): ?string {
        if (preg_match('~/(?:video|embed/v2|player/v1)/(\d+)~', $url, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }
}

if (!function_exists('game_entry_window_is_video_path')) {
    function game_entry_window_is_video_path(string $path): bool {
        return preg_match('/\.(mp4|webm|ogv|ogg|mov)(\?.*)?$/i', $path) === 1;
    }
}

if (!function_exists('game_entry_window_render_media_html')) {
    function game_entry_window_render_media_html(?string $mediaPath = null, ?string $embedUrl = null): string {
        $embedUrl = game_entry_window_normalize_embed_url((string) $embedUrl);
        if ($embedUrl !== '') {
            $youtubeId = game_entry_window_extract_youtube_id($embedUrl);
            if ($youtubeId !== null) {
                return '<div class="game-entry-window-card-media"><iframe class="game-entry-window-card-embed" src="https://www.youtube.com/embed/' . htmlspecialchars($youtubeId, ENT_QUOTES, 'UTF-8') . '" title="Video informativo" loading="lazy" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" referrerpolicy="strict-origin-when-cross-origin" allowfullscreen></iframe></div>';
            }

            $tiktokId = game_entry_window_extract_tiktok_id($embedUrl);
            if ($tiktokId !== null) {
                return '<div class="game-entry-window-card-media"><iframe class="game-entry-window-card-embed game-entry-window-card-embed-tiktok" src="https://www.tiktok.com/player/v1/' . htmlspecialchars($tiktokId, ENT_QUOTES, 'UTF-8') . '" title="Video informativo" loading="lazy" allow="autoplay; encrypted-media; fullscreen; picture-in-picture" allowfullscreen referrerpolicy="strict-origin-when-cross-origin"></iframe></div>';
            }
        }

        $mediaPath = trim((string) $mediaPath);
        if ($mediaPath === '') {
            return '';
        }

        if (game_entry_window_is_video_path($mediaPath)) {
            return '<div class="game-entry-window-card-media"><video class="game-entry-window-card-video" src="' . htmlspecialchars($mediaPath, ENT_QUOTES, 'UTF-8') . '" controls playsinline preload="metadata"></video></div>';
        }

        return '<div class="game-entry-window-card-media"><img class="game-entry-window-card-image" src="' . htmlspecialchars($mediaPath, ENT_QUOTES, 'UTF-8') . '" alt="Multimedia informativa"></div>';
    }
}

if (!function_exists('game_entry_window_render_card_markup')) {
    function game_entry_window_render_card_markup(array $card): string {
        $mediaHtml = game_entry_window_render_media_html((string) ($card['media_path'] ?? ''), (string) ($card['media_embed_url'] ?? ''));
        $contentHtml = trim((string) ($card['content_html'] ?? ''));
        return $mediaHtml . $contentHtml;
    }
}

if (!function_exists('game_entry_window_public_payload')) {
    function game_entry_window_public_payload(mysqli $mysqli, int $gameId): array {
        if ($gameId <= 0) {
            return ['enabled' => false, 'cards' => []];
        }

        $config = game_entry_window_fetch_config($mysqli, $gameId);
        $cards = game_entry_window_fetch_cards($mysqli, $gameId, true);

        return [
            'enabled' => game_entry_window_feature_available() && !empty($config['enabled']) && $cards !== [],
            'title' => $config['title'],
            'icon' => $config['icon'],
            'copy_text' => $config['copy'],
            'check_text' => $config['check_text'],
            'button_text' => $config['button_text'],
            'modal_background' => $config['modal_background'],
            'modal_border_color' => $config['modal_border_color'],
            'title_color' => $config['title_color'],
            'check_text_color' => $config['check_text_color'],
            'check_background_color' => $config['check_background_color'],
            'button_text_color' => $config['button_text_color'],
            'button_background_color' => $config['button_background_color'],
            'button_disabled_text_color' => $config['button_disabled_text_color'],
            'button_disabled_background_color' => $config['button_disabled_background_color'],
            'cards' => $cards,
        ];
    }
}

if (!function_exists('game_entry_window_save_from_request')) {
    function game_entry_window_save_from_request(array $post, array $files = []): array {
        $mysqli = store_config_db();
        game_entry_window_ensure_schema($mysqli);

        $gameId = game_entry_window_game_id($post['game_id'] ?? 0);
        $game = game_entry_window_fetch_game($mysqli, $gameId);
        if ($gameId <= 0 || !$game) {
            return [
                'success' => false,
                'message' => 'Debes seleccionar un juego valido para configurar su ventana inicial.',
            ];
        }

        $defaults = game_entry_window_defaults();
        $currentConfig = game_entry_window_fetch_config($mysqli, $gameId);
        $enabled = !empty($post['ventana_inicio_juego_activa']) ? 1 : 0;
        $title = trim((string) ($post['ventana_inicio_juego_titulo'] ?? $currentConfig['title'] ?? $defaults['title']));
        $copyText = trim((string) ($post['ventana_inicio_juego_descripcion'] ?? $currentConfig['copy'] ?? $defaults['copy']));
        $checkText = trim((string) ($post['ventana_inicio_juego_check_texto'] ?? $currentConfig['check_text'] ?? $defaults['check_text']));
        $buttonText = trim((string) ($post['ventana_inicio_juego_boton_texto'] ?? $currentConfig['button_text'] ?? $defaults['button_text']));
        $modalBackground = store_config_normalize_hex_color((string) ($post['ventana_inicio_juego_modal_background'] ?? $currentConfig['modal_background'] ?? $defaults['modal_background']), $defaults['modal_background']);
        $modalBorderColor = store_config_normalize_hex_color((string) ($post['ventana_inicio_juego_modal_border_color'] ?? $currentConfig['modal_border_color'] ?? $defaults['modal_border_color']), $defaults['modal_border_color']);
        $titleColor = store_config_normalize_hex_color((string) ($post['ventana_inicio_juego_title_color'] ?? $currentConfig['title_color'] ?? $defaults['title_color']), $defaults['title_color']);
        $checkTextColor = store_config_normalize_hex_color((string) ($post['ventana_inicio_juego_check_text_color'] ?? $currentConfig['check_text_color'] ?? $defaults['check_text_color']), $defaults['check_text_color']);
        $checkBackgroundColor = store_config_normalize_hex_color((string) ($post['ventana_inicio_juego_check_background_color'] ?? $currentConfig['check_background_color'] ?? $defaults['check_background_color']), $defaults['check_background_color']);
        $buttonTextColor = store_config_normalize_hex_color((string) ($post['ventana_inicio_juego_button_text_color'] ?? $currentConfig['button_text_color'] ?? $defaults['button_text_color']), $defaults['button_text_color']);
        $buttonBackgroundColor = store_config_normalize_hex_color((string) ($post['ventana_inicio_juego_button_background_color'] ?? $currentConfig['button_background_color'] ?? $defaults['button_background_color']), $defaults['button_background_color']);
        $buttonDisabledTextColor = store_config_normalize_hex_color((string) ($post['ventana_inicio_juego_button_disabled_text_color'] ?? $currentConfig['button_disabled_text_color'] ?? $defaults['button_disabled_text_color']), $defaults['button_disabled_text_color']);
        $buttonDisabledBackgroundColor = store_config_normalize_hex_color((string) ($post['ventana_inicio_juego_button_disabled_background_color'] ?? $currentConfig['button_disabled_background_color'] ?? $defaults['button_disabled_background_color']), $defaults['button_disabled_background_color']);
        $currentIcon = trim((string) ($currentConfig['icon'] ?? ''));
        $defaultIconPath = game_entry_window_default_icon_path();

        if (!empty($post['ventana_inicio_juego_icono_default'])) {
            if ($currentIcon !== '' && $currentIcon !== $defaultIconPath) {
                game_entry_window_delete_icon($currentIcon);
            }
            $currentIcon = $defaultIconPath;
        } elseif (!empty($post['ventana_inicio_juego_icono_eliminar']) && $currentIcon !== '') {
            game_entry_window_delete_icon($currentIcon);
            $currentIcon = $defaultIconPath;
        }

        if (isset($files['ventana_inicio_juego_icono']) && is_array($files['ventana_inicio_juego_icono'])) {
            $upload = game_entry_window_store_icon_upload($files['ventana_inicio_juego_icono'], $gameId);
            if (empty($upload['success'])) {
                return [
                    'success' => false,
                    'message' => (string) ($upload['message'] ?? 'No se pudo cargar el icono de la ventana inicial.'),
                ];
            }

            $uploadedPath = trim((string) ($upload['path'] ?? ''));
            if ($uploadedPath !== '') {
                if ($currentIcon !== '' && $currentIcon !== $uploadedPath && $currentIcon !== $defaultIconPath) {
                    game_entry_window_delete_icon($currentIcon);
                }
                $currentIcon = $uploadedPath;
            }
        }

        $cardsInput = is_array($post['cards'] ?? null) ? $post['cards'] : [];
        $existingCards = game_entry_window_fetch_cards($mysqli, $gameId, false);
        $existingById = [];
        foreach ($existingCards as $card) {
            $existingById[(int) $card['id']] = $card;
        }

        $table = game_entry_window_table_name();
        $keptIds = [];
        $savedCards = 0;
        $position = 0;
        foreach ($cardsInput as $token => $row) {
            if (!is_array($row)) {
                continue;
            }

            $position++;
            $cardId = (int) ($row['id'] ?? 0);
            $contentHtml = trim((string) ($row['content_html'] ?? ''));
            $color = store_config_normalize_hex_color((string) ($row['color'] ?? '#233A73'), '#233A73');
            $backgroundColor = store_config_normalize_hex_color((string) ($row['background_color'] ?? '#121a2f'), '#121a2f');
            $active = !empty($row['active']) ? 1 : 0;
            $order = max(1, (int) ($row['order'] ?? $position));
            $mediaEmbedUrl = game_entry_window_normalize_embed_url((string) ($row['media_embed_url'] ?? ''));
            $removeMedia = !empty($row['media_remove']);
            $currentCard = $cardId > 0 && isset($existingById[$cardId]) ? $existingById[$cardId] : null;
            $resolvedMediaPath = trim((string) ($currentCard['media_path'] ?? ''));
            $resolvedEmbedUrl = trim((string) ($currentCard['media_embed_url'] ?? ''));
            $uploadFile = null;
            if (isset($files['cards_media']) && is_array($files['cards_media'])) {
                $uploadFile = game_entry_window_extract_file_from_array($files['cards_media'], $token);
            }

            if ($removeMedia) {
                if ($resolvedMediaPath !== '') {
                    game_entry_window_delete_media_file($resolvedMediaPath);
                }
                $resolvedMediaPath = '';
                $resolvedEmbedUrl = '';
            }

            if ($mediaEmbedUrl !== '') {
                if ($resolvedMediaPath !== '') {
                    game_entry_window_delete_media_file($resolvedMediaPath);
                }
                $resolvedMediaPath = '';
                $resolvedEmbedUrl = $mediaEmbedUrl;
            } elseif ($uploadFile !== null && (($uploadFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE)) {
                $upload = game_entry_window_store_media_upload($uploadFile, $gameId, $order);
                if (empty($upload['success'])) {
                    return [
                        'success' => false,
                        'message' => (string) ($upload['message'] ?? 'No se pudo cargar la multimedia de una tarjeta.'),
                    ];
                }

                $uploadedPath = trim((string) ($upload['path'] ?? ''));
                if ($uploadedPath !== '') {
                    if ($resolvedMediaPath !== '' && $resolvedMediaPath !== $uploadedPath) {
                        game_entry_window_delete_media_file($resolvedMediaPath);
                    }
                    $resolvedMediaPath = $uploadedPath;
                    $resolvedEmbedUrl = '';
                }
            }

            if ($contentHtml === '' && $resolvedMediaPath === '' && $resolvedEmbedUrl === '') {
                continue;
            }

            if ($cardId > 0 && $currentCard !== null) {
                $stmt = $mysqli->prepare("UPDATE {$table} SET juego_id = ?, content_html = ?, color = ?, background_color = ?, media_path = ?, media_embed_url = ?, activo = ?, orden = ? WHERE id = ? LIMIT 1");
                if ($stmt) {
                    $stmt->bind_param('isssssiii', $gameId, $contentHtml, $color, $backgroundColor, $resolvedMediaPath, $resolvedEmbedUrl, $active, $order, $cardId);
                    $stmt->execute();
                    $stmt->close();
                }
                $keptIds[] = $cardId;
            } else {
                $stmt = $mysqli->prepare("INSERT INTO {$table} (juego_id, content_html, color, background_color, media_path, media_embed_url, activo, orden) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                if ($stmt) {
                    $stmt->bind_param('isssssii', $gameId, $contentHtml, $color, $backgroundColor, $resolvedMediaPath, $resolvedEmbedUrl, $active, $order);
                    $stmt->execute();
                    $keptIds[] = (int) $mysqli->insert_id;
                    $stmt->close();
                }
            }

            $savedCards++;
        }

        foreach ($existingById as $existingId => $existingCard) {
            if (in_array($existingId, $keptIds, true)) {
                continue;
            }

            if (!empty($existingCard['media_path'])) {
                game_entry_window_delete_media_file((string) $existingCard['media_path']);
            }

            $stmt = $mysqli->prepare("DELETE FROM {$table} WHERE id = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('i', $existingId);
                $stmt->execute();
                $stmt->close();
            }
        }

        if ($savedCards === 0) {
            game_entry_window_seed_cards_for_game($mysqli, $gameId);
        }

        $configTable = game_entry_window_config_table_name();
        $stmt = $mysqli->prepare(
            "UPDATE {$configTable}
             SET activa = ?, titulo = ?, icono = ?, descripcion = ?, check_texto = ?, boton_texto = ?,
                 modal_background = ?, modal_border_color = ?, title_color = ?, check_text_color = ?, check_background_color = ?,
                 button_text_color = ?, button_background_color = ?, button_disabled_text_color = ?, button_disabled_background_color = ?
             WHERE juego_id = ? LIMIT 1"
        );
        if (!$stmt) {
            return [
                'success' => false,
                'message' => 'No se pudo guardar la configuracion de la ventana inicial del juego.',
            ];
        }

        $safeTitle = $title !== '' ? $title : $defaults['title'];
        $safeCopy = $copyText !== '' ? $copyText : $defaults['copy'];
        $safeCheckText = $checkText !== '' ? $checkText : $defaults['check_text'];
        $safeButtonText = $buttonText !== '' ? $buttonText : $defaults['button_text'];
        $stmt->bind_param(
            'issssssssssssssi',
            $enabled,
            $safeTitle,
            $currentIcon,
            $safeCopy,
            $safeCheckText,
            $safeButtonText,
            $modalBackground,
            $modalBorderColor,
            $titleColor,
            $checkTextColor,
            $checkBackgroundColor,
            $buttonTextColor,
            $buttonBackgroundColor,
            $buttonDisabledTextColor,
            $buttonDisabledBackgroundColor,
            $gameId
        );
        $ok = $stmt->execute();
        $stmt->close();

        if (!$ok) {
            return [
                'success' => false,
                'message' => 'No se pudo actualizar la ventana inicial del juego.',
            ];
        }

        return [
            'success' => true,
            'message' => 'La ventana inicial del juego fue actualizada correctamente.',
        ];
    }
}

if (!function_exists('game_entry_window_hex_to_rgba')) {
    function game_entry_window_hex_to_rgba(string $hex, float $alpha = 1): string {
        $normalized = ltrim(store_config_normalize_hex_color($hex, '#233A73'), '#');
        if (strlen($normalized) !== 6) {
            $normalized = '233A73';
        }

        $red = hexdec(substr($normalized, 0, 2));
        $green = hexdec(substr($normalized, 2, 2));
        $blue = hexdec(substr($normalized, 4, 2));
        $resolvedAlpha = max(0, min(1, $alpha));

        return 'rgba(' . $red . ', ' . $green . ', ' . $blue . ', ' . $resolvedAlpha . ')';
    }
}
