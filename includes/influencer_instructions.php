<?php

require_once __DIR__ . '/store_config.php';

if (!function_exists('influencer_instructions_db')) {
    function influencer_instructions_db(): mysqli {
        global $mysqli;

        if (!isset($mysqli) || !($mysqli instanceof mysqli)) {
            require_once __DIR__ . '/db_connect.php';
        }

        return $mysqli;
    }
}

if (!function_exists('influencer_instructions_defaults')) {
    function influencer_instructions_defaults(): array {
        return [
            'menu_label' => 'Quiero Unirme',
            'hero' => [
                'badge' => 'Programa Influencer',
                'title' => 'Convierte tu comunidad en ventas reales',
                'lead_html' => '<p>Activa tu acceso al programa Influencer y comparte tus enlaces, tus codigos y tus recomendaciones con una presentacion profesional.</p><p>Todo el proceso esta disenado para que puedas empezar rapido, vender con confianza y mantener una comunicacion clara con tu audiencia.</p>',
                'primary_label' => 'Solicitar acceso',
                'primary_url' => '',
                'secondary_label' => 'Ver pasos',
                'secondary_url' => '#pasos-influencer',
                'image' => '',
            ],
            'benefits' => [
                'eyebrow' => 'Beneficios',
                'title' => 'Lo que recibes al entrar al programa',
                'intro_html' => '<p>Configura una propuesta atractiva para tu comunidad y muestra de inmediato por que vale la pena unirse a tu canal de ventas.</p>',
                'items' => [
                    [
                        'title' => 'Comisiones claras',
                        'html' => '<p>Trabaja con una estructura de beneficios definida para que sepas cuanto puedes generar por cada venta referida.</p>',
                    ],
                    [
                        'title' => 'Material listo para publicar',
                        'html' => '<p>Comparte instrucciones, pasos y mensajes con una pagina pensada para explicar el proceso sin friccion.</p>',
                    ],
                    [
                        'title' => 'Escalado ordenado',
                        'html' => '<p>Convierte tus publicaciones en un flujo constante de solicitudes, consultas y ventas mejor organizadas.</p>',
                    ],
                ],
            ],
            'video_rewards' => [
                'eyebrow' => 'RECOMPENSAS EXTRA',
                'title' => 'Bono por Video en HydraUP',
                'intro_html' => '<p>Ademas de la comision por tu codigo, ganas recompensas extra por cada video en TikTok que nos mencione.</p>',
                'tabs' => influencer_instructions_default_reward_tabs(),
            ],
            'steps' => [
                'eyebrow' => 'Paso a paso',
                'title' => 'Como funciona el ingreso',
                'intro_html' => '<p>Explica el recorrido completo en tres bloques para que cualquier creador entienda rapidamente como avanzar.</p>',
                'items' => [
                    [
                        'icon' => 'sparkles',
                        'title' => '1. Envia tu solicitud',
                        'html' => '<p>Comparte tus datos, tus redes y el tipo de comunidad que manejas para evaluar tu ingreso al programa Influencer.</p>',
                    ],
                    [
                        'icon' => 'megaphone',
                        'title' => '2. Recibe tus recursos',
                        'html' => '<p>Una vez aprobado, recibes el material, los lineamientos y la informacion comercial para comenzar a promocionar.</p>',
                    ],
                    [
                        'icon' => 'rocket',
                        'title' => '3. Empieza a vender',
                        'html' => '<p>Publica, comparte tu codigo y centraliza las consultas usando un mensaje y una llamada a la accion bien definidos.</p>',
                    ],
                ],
            ],
            'notes' => [
                'eyebrow' => 'Notas especiales',
                'title' => 'Puntos importantes antes de activar tu cuenta',
                'intro_html' => '<p>Usa este bloque para dejar condiciones, tiempos de respuesta o reglas internas con el mismo peso visual que el ejemplo.</p>',
                'items' => [
                    [
                        'icon' => 'shield',
                        'title' => 'Cumple las reglas del programa',
                        'html' => '<p>Mantener mensajes claros, precios correctos y una comunicacion responsable protege tanto tu marca como la de la tienda.</p>',
                    ],
                    [
                        'icon' => 'gift',
                        'title' => 'Promociones sujetas a campanas',
                        'html' => '<p>Las condiciones de bonos, activaciones o incentivos especiales pueden ajustarse segun la temporada o el objetivo comercial.</p>',
                    ],
                    [
                        'icon' => 'star',
                        'title' => 'Prioriza contenido con conversion',
                        'html' => '<p>El programa Influencer funciona mejor cuando publicas piezas concretas, frecuentes y alineadas con la audiencia correcta.</p>',
                    ],
                ],
            ],
            'closing' => [
                'eyebrow' => 'Listo para empezar',
                'title' => 'Activa tu solicitud hoy',
                'content_html' => '<p>Si ya tienes comunidad, presencia en redes o una audiencia interesada en recargas, este es el momento de formalizar tu entrada al programa Influencer.</p><p>Deja tu llamada a la accion final con el texto exacto que quieras destacar.</p>',
                'button_label' => 'Hablar con soporte',
                'whatsapp_phone' => '',
                'whatsapp_message' => 'Hola, quiero unirme al programa Influencer.',
            ],
            'colors' => [
                'hero_surface' => '#0F172A',
                'hero_accent' => '#22D3EE',
                'hero_title' => '#FFFFFF',
                'hero_text' => '#D7F7FF',
                'hero_button_bg' => '#22C55E',
                'hero_button_text' => '#04110B',
                'hero_secondary_bg' => '#11263A',
                'hero_secondary_text' => '#D7F7FF',
                'closing_surface' => '#0B1120',
                'closing_label' => '#22D3EE',
                'closing_title' => '#FFFFFF',
                'closing_text' => '#DCEBFF',
                'closing_button_bg' => '#22C55E',
                'closing_button_text' => '#04110B',
                'steps_surface' => '#0B1324',
                'steps_label' => '#5EEAD4',
                'steps_title' => '#FFFFFF',
                'steps_text' => '#CFE7FF',
                'steps_card_bg' => '#111B31',
                'steps_card_title' => '#FFFFFF',
                'steps_card_text' => '#A8C7E8',
                'steps_icon_bg' => '#12344A',
                'steps_icon_color' => '#5EEAD4',
                'benefits_surface' => '#111827',
                'benefits_label' => '#FBBF24',
                'benefits_title' => '#FFFFFF',
                'benefits_text' => '#E5E7EB',
                'benefits_card_bg' => '#1F2937',
                'benefits_card_title' => '#F9FAFB',
                'benefits_card_text' => '#D1D5DB',
                'video_rewards_surface' => '#0B1320',
                'video_rewards_title' => '#FFFFFF',
                'video_rewards_text' => '#B7C6DB',
                'notes_surface' => '#101826',
                'notes_label' => '#F59E0B',
                'notes_title' => '#FFFFFF',
                'notes_text' => '#E5E7EB',
                'notes_card_bg' => '#172033',
                'notes_card_title' => '#F9FAFB',
                'notes_card_text' => '#D6DEED',
                'notes_icon_bg' => '#362A12',
                'notes_icon_color' => '#FBBF24',
            ],
        ];
    }
}

if (!function_exists('influencer_instructions_default_reward_tabs')) {
    function influencer_instructions_default_reward_tabs(): array {
        return [
            [
                'label' => 'Free Fire',
                'tab_emoji' => '',
                'views_label' => 'VISTAS',
                'views_emoji' => 'o',
                'reward_label' => 'DIAMANTES',
                'reward_emoji' => '💎',
                'active_bg' => '#22C55E',
                'active_text' => '#FFFFFF',
                'table_border' => '#8A6A1F',
                'header_bg' => '#3A311A',
                'header_text' => '#FACC15',
                'body_bg' => '#182132',
                'body_text' => '#FFFFFF',
                'highlight_bg' => '#342B1E',
                'highlight_text' => '#FACC15',
                'rows' => [
                    ['views' => '1,000 - 3,999', 'reward' => '110 💎'],
                    ['views' => '4,000 - 9,999', 'reward' => '341 💎'],
                    ['views' => '10,000 - 29,999', 'reward' => '572 💎'],
                    ['views' => '30,000 - 49,999', 'reward' => '1160 💎'],
                    ['views' => '50,000 - 99,999', 'reward' => '2398 💎'],
                    ['views' => '100,000 o mas', 'reward' => '6160 💎'],
                ],
            ],
            [
                'label' => 'Blood Strike',
                'tab_emoji' => '',
                'views_label' => 'VISTAS',
                'views_emoji' => 'o',
                'reward_label' => 'GOLD',
                'reward_emoji' => '⚡',
                'active_bg' => '#EF4444',
                'active_text' => '#FFFFFF',
                'table_border' => '#8A5A1F',
                'header_bg' => '#4A2B1F',
                'header_text' => '#FACC15',
                'body_bg' => '#182132',
                'body_text' => '#FFFFFF',
                'highlight_bg' => '#39271F',
                'highlight_text' => '#FACC15',
                'rows' => [
                    ['views' => '1,000 - 3,999', 'reward' => '100 GOLD'],
                    ['views' => '4,000 - 9,999', 'reward' => '300 GOLD'],
                    ['views' => '10,000 - 29,999', 'reward' => '500 GOLD'],
                    ['views' => '30,000 - 49,999', 'reward' => '1000 GOLD'],
                    ['views' => '50,000 - 99,999', 'reward' => '2000 GOLD'],
                    ['views' => '100,000 o mas', 'reward' => '5000 GOLD'],
                ],
            ],
            [
                'label' => 'Dinero Real',
                'tab_emoji' => '',
                'views_label' => 'VISTAS',
                'views_emoji' => 'o',
                'reward_label' => 'RECOMPENSA',
                'reward_emoji' => '$',
                'active_bg' => '#22C55E',
                'active_text' => '#FFFFFF',
                'table_border' => '#1D6B52',
                'header_bg' => '#17453F',
                'header_text' => '#34D399',
                'body_bg' => '#182132',
                'body_text' => '#FFFFFF',
                'highlight_bg' => '#123A34',
                'highlight_text' => '#34D399',
                'rows' => [
                    ['views' => '1,000 - 3,999', 'reward' => '$0.86 USD'],
                    ['views' => '4,000 - 9,999', 'reward' => '$2.84 USD'],
                    ['views' => '10,000 - 29,999', 'reward' => '$4.02 USD'],
                    ['views' => '30,000 - 49,999', 'reward' => '$7.46 USD'],
                    ['views' => '50,000 - 99,999', 'reward' => '$14.65 USD'],
                    ['views' => '100,000 o mas', 'reward' => '$36.90 USD'],
                ],
            ],
        ];
    }
}

if (!function_exists('influencer_instructions_reward_color_defaults')) {
    function influencer_instructions_reward_color_defaults(): array {
        return [
            'active_bg' => '#22C55E',
            'active_text' => '#FFFFFF',
            'table_border' => '#8A6A1F',
            'header_bg' => '#3A311A',
            'header_text' => '#FACC15',
            'body_bg' => '#182132',
            'body_text' => '#FFFFFF',
            'highlight_bg' => '#342B1E',
            'highlight_text' => '#FACC15',
        ];
    }
}

if (!function_exists('influencer_instructions_reward_color_keys')) {
    function influencer_instructions_reward_color_keys(): array {
        return array_keys(influencer_instructions_reward_color_defaults());
    }
}

if (!function_exists('influencer_instructions_reward_tab_template')) {
    function influencer_instructions_reward_tab_template(): array {
        return [
            'label' => '',
            'tab_emoji' => '',
            'views_label' => 'VISTAS',
            'views_emoji' => 'o',
            'reward_label' => 'PREMIO',
            'reward_emoji' => '💎',
            'active_bg' => '#22C55E',
            'active_text' => '#FFFFFF',
            'table_border' => '#8A6A1F',
            'header_bg' => '#3A311A',
            'header_text' => '#FACC15',
            'body_bg' => '#182132',
            'body_text' => '#FFFFFF',
            'highlight_bg' => '#342B1E',
            'highlight_text' => '#FACC15',
            'rows' => [],
        ];
    }
}

if (!function_exists('influencer_instructions_normalize_reward_tabs')) {
    function influencer_instructions_normalize_reward_tabs(array $tabs, array $fallbackTabs = []): array {
        $normalized = [];
        $template = influencer_instructions_reward_tab_template();
        $colorDefaults = influencer_instructions_reward_color_defaults();

        foreach ($tabs as $tabIndex => $tab) {
            if (!is_array($tab)) {
                continue;
            }

            $fallbackTab = is_array($fallbackTabs[$tabIndex] ?? null) ? $fallbackTabs[$tabIndex] : [];

            $label = trim((string) ($tab['label'] ?? ($fallbackTab['label'] ?? '')));
            $viewsLabel = trim((string) ($tab['views_label'] ?? ($fallbackTab['views_label'] ?? $template['views_label'])));
            $rewardLabel = trim((string) ($tab['reward_label'] ?? ($fallbackTab['reward_label'] ?? $template['reward_label'])));

            $normalizedTab = [
                'label' => $label !== '' ? $label : 'Tab ' . (count($normalized) + 1),
                'tab_emoji' => trim((string) ($tab['tab_emoji'] ?? ($fallbackTab['tab_emoji'] ?? $template['tab_emoji']))),
                'views_label' => $viewsLabel !== '' ? $viewsLabel : (string) $template['views_label'],
                'views_emoji' => trim((string) ($tab['views_emoji'] ?? ($fallbackTab['views_emoji'] ?? $template['views_emoji']))),
                'reward_label' => $rewardLabel !== '' ? $rewardLabel : (string) $template['reward_label'],
                'reward_emoji' => trim((string) ($tab['reward_emoji'] ?? ($fallbackTab['reward_emoji'] ?? $template['reward_emoji']))),
                'rows' => [],
            ];

            foreach ($colorDefaults as $colorKey => $defaultColor) {
                $fallbackColor = (string) ($fallbackTab[$colorKey] ?? $defaultColor);
                $normalizedTab[$colorKey] = influencer_instructions_normalize_hex((string) ($tab[$colorKey] ?? $fallbackColor), $fallbackColor);
            }

            $rows = is_array($tab['rows'] ?? null) ? $tab['rows'] : [];
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $views = trim((string) ($row['views'] ?? ''));
                $reward = trim((string) ($row['reward'] ?? ''));
                if ($views === '' && $reward === '') {
                    continue;
                }

                $normalizedTab['rows'][] = [
                    'views' => $views,
                    'reward' => $reward,
                ];
            }

            $normalized[] = $normalizedTab;
        }

        if ($normalized === []) {
            $fallback = [];
            foreach ($fallbackTabs as $candidate) {
                if (is_array($candidate)) {
                    $fallback[] = $candidate;
                    break;
                }
            }

            if ($fallback === []) {
                $fallback = [influencer_instructions_reward_tab_template()];
            }

            return influencer_instructions_normalize_reward_tabs($fallback, []);
        }

        return array_values($normalized);
    }
}

if (!function_exists('influencer_instructions_icon_options')) {
    function influencer_instructions_icon_options(): array {
        return [
            'sparkles' => 'Brillo',
            'megaphone' => 'Megafono',
            'rocket' => 'Cohete',
            'shield' => 'Escudo',
            'gift' => 'Regalo',
            'star' => 'Estrella',
            'users' => 'Comunidad',
        ];
    }
}

if (!function_exists('influencer_instructions_deep_merge')) {
    function influencer_instructions_deep_merge(array $defaults, array $overrides): array {
        foreach ($defaults as $key => $value) {
            if (!array_key_exists($key, $overrides)) {
                $overrides[$key] = $value;
                continue;
            }

            if (!is_array($value) || !is_array($overrides[$key])) {
                continue;
            }

            $isList = array_keys($value) === range(0, count($value) - 1);
            if ($isList) {
                continue;
            }

            $overrides[$key] = influencer_instructions_deep_merge($value, $overrides[$key]);
        }

        return $overrides;
    }
}

if (!function_exists('influencer_instructions_encode')) {
    function influencer_instructions_encode(array $data): string {
        $encoded = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return is_string($encoded) ? $encoded : '{}';
    }
}

if (!function_exists('influencer_instructions_ensure_table')) {
    function influencer_instructions_ensure_table(): void {
        static $initialized = false;

        if ($initialized) {
            return;
        }

        $mysqli = influencer_instructions_db();
        $sql = <<<'SQL'
CREATE TABLE IF NOT EXISTS influencer_instructions (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    content_json LONGTEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;
        $mysqli->query($sql);

        $row = $mysqli->query('SELECT id FROM influencer_instructions WHERE id = 1 LIMIT 1');
        $exists = $row instanceof mysqli_result && $row->fetch_assoc();
        if (!$exists) {
            $stmt = $mysqli->prepare('INSERT INTO influencer_instructions (id, content_json) VALUES (1, ?)');
            if ($stmt) {
                $content = influencer_instructions_encode(influencer_instructions_defaults());
                $stmt->bind_param('s', $content);
                $stmt->execute();
                $stmt->close();
            }
        }

        $initialized = true;
    }
}

if (!function_exists('influencer_instructions_get')) {
    function influencer_instructions_get(): array {
        influencer_instructions_ensure_table();

        $mysqli = influencer_instructions_db();
        $result = $mysqli->query('SELECT content_json FROM influencer_instructions WHERE id = 1 LIMIT 1');
        $row = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
        $decoded = json_decode((string) ($row['content_json'] ?? '{}'), true);
        if (!is_array($decoded)) {
            $decoded = [];
        }

        $data = influencer_instructions_deep_merge(influencer_instructions_defaults(), $decoded);
        $data['video_rewards']['tabs'] = influencer_instructions_normalize_reward_tabs(
            is_array($data['video_rewards']['tabs'] ?? null) ? $data['video_rewards']['tabs'] : [],
            influencer_instructions_default_reward_tabs()
        );

        return $data;
    }
}

if (!function_exists('influencer_instructions_public_enabled')) {
    function influencer_instructions_public_enabled(): bool {
        return store_config_get('instrucciones_influencer', '0') === '1';
    }
}

if (!function_exists('influencer_instructions_normalize_icon')) {
    function influencer_instructions_normalize_icon(string $value, string $fallback = 'sparkles'): string {
        $normalized = strtolower(trim($value));
        return array_key_exists($normalized, influencer_instructions_icon_options()) ? $normalized : $fallback;
    }
}

if (!function_exists('influencer_instructions_normalize_hex')) {
    function influencer_instructions_normalize_hex(string $value, string $fallback): string {
        return function_exists('store_config_normalize_hex_color')
            ? store_config_normalize_hex_color($value, $fallback)
            : strtoupper($fallback);
    }
}

if (!function_exists('influencer_instructions_is_managed_image_path')) {
    function influencer_instructions_is_managed_image_path(string $relativePath): bool {
        return function_exists('tenant_is_managed_path')
            ? tenant_is_managed_path($relativePath, 'influencer')
            : str_starts_with($relativePath, '/assets/img/influencer/');
    }
}

if (!function_exists('influencer_instructions_delete_image_file')) {
    function influencer_instructions_delete_image_file(string $relativePath): void {
        if ($relativePath === '' || !influencer_instructions_is_managed_image_path($relativePath)) {
            return;
        }

        $absolutePath = function_exists('tenant_resolve_public_path')
            ? tenant_resolve_public_path($relativePath)
            : dirname(__DIR__) . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);

        if ($absolutePath !== null && is_file($absolutePath)) {
            @unlink($absolutePath);
        }
    }
}

if (!function_exists('influencer_instructions_store_image_upload')) {
    function influencer_instructions_store_image_upload(array $file): array {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return ['success' => true, 'path' => ''];
        }

        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            return ['success' => false, 'message' => 'No se pudo cargar la imagen del modulo.'];
        }

        $tmpName = $file['tmp_name'] ?? '';
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            return ['success' => false, 'message' => 'El archivo de la imagen no es valido.'];
        }

        if (($file['size'] ?? 0) > 4 * 1024 * 1024) {
            return ['success' => false, 'message' => 'La imagen no puede superar 4 MB.'];
        }

        $imageInfo = @getimagesize($tmpName);
        if ($imageInfo === false) {
            return ['success' => false, 'message' => 'La imagen debe ser un archivo valido.'];
        }

        $mime = $imageInfo['mime'] ?? '';
        $extensions = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
        ];

        if (!isset($extensions[$mime])) {
            return ['success' => false, 'message' => 'Formato no permitido. Usa JPG, PNG, WEBP o GIF.'];
        }

        $targetDir = function_exists('tenant_upload_absolute_dir')
            ? tenant_upload_absolute_dir('influencer')
            : dirname(__DIR__) . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'img' . DIRECTORY_SEPARATOR . 'influencer';

        if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
            return ['success' => false, 'message' => 'No se pudo crear la carpeta de imagenes.'];
        }

        $fileName = 'influencer-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $extensions[$mime];
        $targetPath = $targetDir . DIRECTORY_SEPARATOR . $fileName;

        if (!move_uploaded_file($tmpName, $targetPath)) {
            return ['success' => false, 'message' => 'No se pudo guardar la imagen en el servidor.'];
        }

        $publicPath = function_exists('tenant_upload_public_path')
            ? tenant_upload_public_path('influencer', $fileName, true)
            : '/assets/img/influencer/' . $fileName;

        return ['success' => true, 'path' => $publicPath];
    }
}

if (!function_exists('influencer_instructions_asset_url')) {
    function influencer_instructions_asset_url(string $path): string {
        $candidate = trim($path);
        if ($candidate === '') {
            return '';
        }

        if (preg_match('#^https?://#i', $candidate) === 1) {
            return $candidate;
        }

        $normalized = '/' . ltrim($candidate, '/');
        return function_exists('app_path') ? app_path($normalized) : $normalized;
    }
}

if (!function_exists('influencer_instructions_link_url')) {
    function influencer_instructions_link_url(string $path): string {
        $candidate = trim($path);
        if ($candidate === '') {
            return '';
        }

        if (preg_match('#^(https?:)?//#i', $candidate) === 1 || str_starts_with($candidate, '#')) {
            return $candidate;
        }

        if ($candidate[0] === '/') {
            return function_exists('app_path') ? app_path($candidate) : $candidate;
        }

        return $candidate;
    }
}

if (!function_exists('influencer_instructions_default_contact_url')) {
    function influencer_instructions_default_contact_url(): string {
        return store_config_whatsapp_link_with_message(
            store_config_get('whatsapp', ''),
            'Hola, quiero unirme al programa Influencer.'
        );
    }
}

if (!function_exists('influencer_instructions_closing_whatsapp_link')) {
    function influencer_instructions_closing_whatsapp_link(array $closing): string {
        $phone = trim((string) ($closing['whatsapp_phone'] ?? ''));
        $message = trim((string) ($closing['whatsapp_message'] ?? ''));

        if ($phone !== '') {
            $link = store_config_whatsapp_link_with_message($phone, $message);
            if ($link !== '') {
                return $link;
            }
        }

        $legacyLink = influencer_instructions_link_url((string) ($closing['button_url'] ?? ''));
        if ($legacyLink !== '') {
            return $legacyLink;
        }

        return influencer_instructions_default_contact_url();
    }
}

if (!function_exists('influencer_instructions_icon_svg')) {
    function influencer_instructions_icon_svg(string $icon): string {
        switch (influencer_instructions_normalize_icon($icon)) {
            case 'megaphone':
                return '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M4 13V11C4 9.9 4.9 9 6 9H8L16 5V19L8 15H6C4.9 15 4 14.1 4 13Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="M8 15L9.5 20" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><path d="M18.5 9.5C19.4 10.2 20 11.3 20 12.5C20 13.7 19.4 14.8 18.5 15.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>';
            case 'rocket':
                return '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M14.5 4.5C17.5 5.2 18.8 8.2 18.1 11.2L17.3 14.7L13.8 13.9C10.8 13.2 7.8 11.9 8.5 8.9L9.3 5.4L12.8 4.6L14.5 4.5Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="M7.5 16.5L4.5 19.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><path d="M9 15L6 18" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>';
            case 'shield':
                return '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 3L18 5.5V11C18 15 15.7 18.7 12 20.5C8.3 18.7 6 15 6 11V5.5L12 3Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="M9.5 12.3L11.2 14L14.8 10.4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>';
            case 'gift':
                return '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M5 10H19V19H5V10Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="M3.5 10H20.5V7H3.5V10Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="M12 7V19" stroke="currentColor" stroke-width="1.8"/><path d="M9.5 7C8.1 7 7 5.9 7 4.5C7 3.7 7.7 3 8.5 3C10.2 3 12 5 12 7" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><path d="M14.5 7C15.9 7 17 5.9 17 4.5C17 3.7 16.3 3 15.5 3C13.8 3 12 5 12 7" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>';
            case 'star':
                return '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 4L14.5 9L20 9.8L16 13.7L17 19.2L12 16.5L7 19.2L8 13.7L4 9.8L9.5 9L12 4Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>';
            case 'users':
                return '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M9 11C10.7 11 12 9.7 12 8C12 6.3 10.7 5 9 5C7.3 5 6 6.3 6 8C6 9.7 7.3 11 9 11Z" stroke="currentColor" stroke-width="1.8"/><path d="M15.5 10C16.9 10 18 8.9 18 7.5C18 6.1 16.9 5 15.5 5C14.1 5 13 6.1 13 7.5C13 8.9 14.1 10 15.5 10Z" stroke="currentColor" stroke-width="1.8"/><path d="M4.5 18C5.3 15.8 7 14.5 9 14.5C11 14.5 12.7 15.8 13.5 18" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><path d="M13.5 16.5C14.1 15.3 15.2 14.5 16.5 14.5C17.8 14.5 18.9 15.3 19.5 16.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>';
            case 'sparkles':
            default:
                return '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 3L13.7 8.3L19 10L13.7 11.7L12 17L10.3 11.7L5 10L10.3 8.3L12 3Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="M18.5 4.5L19.1 6.4L21 7L19.1 7.6L18.5 9.5L17.9 7.6L16 7L17.9 6.4L18.5 4.5Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/><path d="M6 15.5L6.6 17.4L8.5 18L6.6 18.6L6 20.5L5.4 18.6L3.5 18L5.4 17.4L6 15.5Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/></svg>';
        }
    }
}

if (!function_exists('influencer_instructions_save_from_request')) {
    function influencer_instructions_save_from_request(array $input, array $files): array {
        influencer_instructions_ensure_table();

        $current = influencer_instructions_get();
        $data = $current;

        $data['menu_label'] = trim((string) ($input['menu_label'] ?? $data['menu_label']));
        $data['hero']['badge'] = trim((string) ($input['hero_badge'] ?? ''));
        $data['hero']['title'] = trim((string) ($input['hero_title'] ?? ''));
        $data['hero']['lead_html'] = trim((string) ($input['hero_lead_html'] ?? ''));
        $data['hero']['primary_label'] = trim((string) ($input['hero_primary_label'] ?? ''));
        $data['hero']['primary_url'] = trim((string) ($input['hero_primary_url'] ?? ''));
        $data['hero']['secondary_label'] = trim((string) ($input['hero_secondary_label'] ?? ''));
        $data['hero']['secondary_url'] = trim((string) ($input['hero_secondary_url'] ?? ''));

        foreach (['benefits', 'steps', 'notes', 'closing'] as $sectionKey) {
            $data[$sectionKey]['eyebrow'] = trim((string) ($input[$sectionKey . '_eyebrow'] ?? ($data[$sectionKey]['eyebrow'] ?? '')));
            $data[$sectionKey]['title'] = trim((string) ($input[$sectionKey . '_title'] ?? ($data[$sectionKey]['title'] ?? '')));
        }

        $data['benefits']['intro_html'] = trim((string) ($input['benefits_intro_html'] ?? ''));
        $data['video_rewards']['eyebrow'] = trim((string) ($input['video_rewards_eyebrow'] ?? ($data['video_rewards']['eyebrow'] ?? '')));
        $data['video_rewards']['title'] = trim((string) ($input['video_rewards_title'] ?? ($data['video_rewards']['title'] ?? '')));
        $data['video_rewards']['intro_html'] = trim((string) ($input['video_rewards_intro_html'] ?? ($data['video_rewards']['intro_html'] ?? '')));
        $data['steps']['intro_html'] = trim((string) ($input['steps_intro_html'] ?? ''));
        $data['notes']['intro_html'] = trim((string) ($input['notes_intro_html'] ?? ''));
        $data['closing']['content_html'] = trim((string) ($input['closing_content_html'] ?? ''));
        $data['closing']['button_label'] = trim((string) ($input['closing_button_label'] ?? ''));

        $closingWhatsappPhoneInput = trim((string) ($input['closing_whatsapp_phone'] ?? ''));
        if ($closingWhatsappPhoneInput !== '' && !store_config_is_valid_whatsapp($closingWhatsappPhoneInput)) {
            return ['success' => false, 'message' => 'El telefono de WhatsApp del cierre final no es valido. Usa codigo de pais y entre 10 y 15 digitos.'];
        }

        $data['closing']['whatsapp_phone'] = store_config_normalize_whatsapp($closingWhatsappPhoneInput);
        $data['closing']['whatsapp_message'] = store_config_normalize_whatsapp_message((string) ($input['closing_whatsapp_message'] ?? ''));

        $benefitItems = $input['benefits_items'] ?? [];
        foreach ($data['benefits']['items'] as $index => $item) {
            $row = is_array($benefitItems[$index] ?? null) ? $benefitItems[$index] : [];
            $data['benefits']['items'][$index]['title'] = trim((string) ($row['title'] ?? $item['title']));
            $data['benefits']['items'][$index]['html'] = trim((string) ($row['html'] ?? $item['html']));
        }

        $rewardTabs = is_array($input['video_rewards_tabs'] ?? null) ? $input['video_rewards_tabs'] : [];
        $rewardFallbackTabs = $rewardTabs === []
            ? []
            : (is_array($current['video_rewards']['tabs'] ?? null) ? $current['video_rewards']['tabs'] : influencer_instructions_default_reward_tabs());
        $data['video_rewards']['tabs'] = influencer_instructions_normalize_reward_tabs(
            $rewardTabs,
            $rewardFallbackTabs
        );

        $stepItems = $input['steps_items'] ?? [];
        foreach ($data['steps']['items'] as $index => $item) {
            $row = is_array($stepItems[$index] ?? null) ? $stepItems[$index] : [];
            $data['steps']['items'][$index]['icon'] = influencer_instructions_normalize_icon((string) ($row['icon'] ?? $item['icon']), (string) $item['icon']);
            $data['steps']['items'][$index]['title'] = trim((string) ($row['title'] ?? $item['title']));
            $data['steps']['items'][$index]['html'] = trim((string) ($row['html'] ?? $item['html']));
        }

        $noteItems = $input['notes_items'] ?? [];
        foreach ($data['notes']['items'] as $index => $item) {
            $row = is_array($noteItems[$index] ?? null) ? $noteItems[$index] : [];
            $data['notes']['items'][$index]['icon'] = influencer_instructions_normalize_icon((string) ($row['icon'] ?? $item['icon']), (string) $item['icon']);
            $data['notes']['items'][$index]['title'] = trim((string) ($row['title'] ?? $item['title']));
            $data['notes']['items'][$index]['html'] = trim((string) ($row['html'] ?? $item['html']));
        }

        $colorInput = is_array($input['colors'] ?? null) ? $input['colors'] : [];
        $defaultColors = influencer_instructions_defaults()['colors'] ?? [];
        $data['colors'] = [];
        foreach ($defaultColors as $colorKey => $fallbackColor) {
            $currentColor = (string) (($current['colors'][$colorKey] ?? $fallbackColor));
            $data['colors'][$colorKey] = influencer_instructions_normalize_hex((string) ($colorInput[$colorKey] ?? $currentColor), (string) $fallbackColor);
        }

        $currentImage = trim((string) ($current['hero']['image'] ?? ''));
        $nextImage = $currentImage;
        $hasUpload = isset($files['hero_image']) && (($files['hero_image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE);

        if ($hasUpload) {
            $upload = influencer_instructions_store_image_upload($files['hero_image']);
            if (!$upload['success']) {
                return ['success' => false, 'message' => $upload['message']];
            }
            if (!empty($upload['path'])) {
                $nextImage = $upload['path'];
            }
        } elseif (isset($input['remove_hero_image'])) {
            $nextImage = '';
        }

        $data['hero']['image'] = $nextImage;

        if ($data['menu_label'] === '' || $data['hero']['title'] === '' || $data['steps']['title'] === '' || $data['closing']['title'] === '') {
            return ['success' => false, 'message' => 'Completa el nombre del boton publico, el titulo principal, el bloque de pasos y el cierre final.'];
        }

        $content = influencer_instructions_encode($data);
        $mysqli = influencer_instructions_db();
        $stmt = $mysqli->prepare('UPDATE influencer_instructions SET content_json = ? WHERE id = 1');
        if (!$stmt) {
            return ['success' => false, 'message' => 'No se pudo preparar el guardado del modulo.'];
        }

        $stmt->bind_param('s', $content);
        $ok = $stmt->execute();
        $stmt->close();

        if (!$ok) {
            return ['success' => false, 'message' => 'No se pudo guardar la configuracion del modulo.'];
        }

        if ($currentImage !== '' && $currentImage !== $nextImage) {
            influencer_instructions_delete_image_file($currentImage);
        }

        return ['success' => true, 'message' => 'Modulo Instrucciones Influencer actualizado.', 'data' => $data];
    }
}