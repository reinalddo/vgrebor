<?php
// Módulo "Ayuda": un solo botón flotante que despliega 3 opciones
// (Soporte, Canal de difusión, Tutoriales). Este archivo solo maneja la
// CONFIGURACIÓN visual (textos, colores, íconos) y la lista de videos de
// Tutoriales. La lógica de habilitado/URL de Soporte y Canal de difusión
// (whatsapp/whatsapp_channel) NO se toca — sigue viviendo donde siempre,
// en includes/store_config.php + includes/footer.php.
//
// Mismo patrón que includes/referidos.php (banner con ícono emoji/imagen
// configurable y subida de imagen vía tenant_upload_*): se reutiliza a
// propósito para no introducir un mecanismo nuevo.

if (!function_exists('ayuda_botones_validos')) {
    function ayuda_botones_validos(): array {
        return ['principal', 'soporte', 'canal', 'tutoriales'];
    }
}

// Valores por defecto de cada botón. 'defecto' en icono_tipo es exclusivo
// de soporte/canal: significa "usa el SVG y los colores originales que ya
// existían antes de este módulo" (así se cumple "deja en default como está
// ahora" sin tener que imitar el ícono con un emoji). color_fondo/color_texto
// en '' también significa "no forzar nada, usar el estilo original".
if (!function_exists('ayuda_boton_defaults')) {
    function ayuda_boton_defaults(string $boton): array {
        $defaults = [
            'principal' => [
                'texto' => 'Ayuda',
                'icono_tipo' => 'emoji',
                'icono_emoji' => '❔',
                'color_fondo' => '#0b1420',
                'color_texto' => '#22d3ee',
            ],
            'soporte' => [
                'texto' => 'Soporte',
                'icono_tipo' => 'defecto',
                'icono_emoji' => '🎧',
                'color_fondo' => '',
                'color_texto' => '',
            ],
            'canal' => [
                'texto' => 'Canal de difusión',
                'icono_tipo' => 'defecto',
                'icono_emoji' => '📣',
                'color_fondo' => '',
                'color_texto' => '',
            ],
            'tutoriales' => [
                'texto' => 'Tutoriales',
                'icono_tipo' => 'emoji',
                'icono_emoji' => '🎬',
                'color_fondo' => '#7c3aed',
                'color_texto' => '#ffffff',
            ],
        ];

        return $defaults[$boton] ?? $defaults['tutoriales'];
    }
}

if (!function_exists('ayuda_boton_texto')) {
    function ayuda_boton_texto(string $boton): string {
        $defaults = ayuda_boton_defaults($boton);
        $texto = trim((string) store_config_get('ayuda_' . $boton . '_texto', $defaults['texto']));
        return $texto !== '' ? $texto : $defaults['texto'];
    }
}

if (!function_exists('ayuda_boton_icono_tipo')) {
    function ayuda_boton_icono_tipo(string $boton): string {
        $defaults = ayuda_boton_defaults($boton);
        $tipo = trim((string) store_config_get('ayuda_' . $boton . '_icono_tipo', $defaults['icono_tipo']));
        $validos = $defaults['icono_tipo'] === 'defecto'
            ? ['defecto', 'emoji', 'imagen']
            : ['emoji', 'imagen'];
        return in_array($tipo, $validos, true) ? $tipo : $defaults['icono_tipo'];
    }
}

if (!function_exists('ayuda_boton_icono_emoji')) {
    function ayuda_boton_icono_emoji(string $boton): string {
        $defaults = ayuda_boton_defaults($boton);
        $emoji = trim((string) store_config_get('ayuda_' . $boton . '_icono_emoji', $defaults['icono_emoji']));
        return $emoji !== '' ? $emoji : $defaults['icono_emoji'];
    }
}

if (!function_exists('ayuda_boton_icono_imagen')) {
    function ayuda_boton_icono_imagen(string $boton): string {
        return trim((string) store_config_get('ayuda_' . $boton . '_icono_imagen', ''));
    }
}

if (!function_exists('ayuda_boton_color_fondo')) {
    function ayuda_boton_color_fondo(string $boton): string {
        $defaults = ayuda_boton_defaults($boton);
        $color = trim((string) store_config_get('ayuda_' . $boton . '_color_fondo', $defaults['color_fondo']));
        if ($color === '') {
            return '';
        }
        return store_config_normalize_hex_color($color, $defaults['color_fondo'] !== '' ? $defaults['color_fondo'] : '#000000');
    }
}

if (!function_exists('ayuda_boton_color_texto')) {
    function ayuda_boton_color_texto(string $boton): string {
        $defaults = ayuda_boton_defaults($boton);
        $color = trim((string) store_config_get('ayuda_' . $boton . '_color_texto', $defaults['color_texto']));
        if ($color === '') {
            return '';
        }
        return store_config_normalize_hex_color($color, $defaults['color_texto'] !== '' ? $defaults['color_texto'] : '#ffffff');
    }
}

// ── Degradado de fondo (opcional, sobre el color de fondo de arriba) ──────
if (!function_exists('ayuda_boton_fondo_modo')) {
    function ayuda_boton_fondo_modo(string $boton): string {
        $modo = trim((string) store_config_get('ayuda_' . $boton . '_fondo_modo', 'solido'));
        return $modo === 'degradado' ? 'degradado' : 'solido';
    }
}

if (!function_exists('ayuda_boton_color_fondo2')) {
    function ayuda_boton_color_fondo2(string $boton): string {
        $color = trim((string) store_config_get('ayuda_' . $boton . '_color_fondo2', ''));
        if ($color === '') {
            return '';
        }
        $defaults = ayuda_boton_defaults($boton);
        $fallback = $defaults['color_fondo'] !== '' ? $defaults['color_fondo'] : '#000000';
        return store_config_normalize_hex_color($color, $fallback);
    }
}

// Valor final para el CSS `background`: un solo color, o el degradado de 2
// colores si el admin activó ese modo. ayuda_boton_color_fondo() (arriba)
// sigue siendo el getter "crudo" que precarga el selector de color del
// admin — este es el que se usa para pintar el botón de verdad.
if (!function_exists('ayuda_boton_fondo_css')) {
    function ayuda_boton_fondo_css(string $boton): string {
        $fondo = ayuda_boton_color_fondo($boton);
        if ($fondo === '') {
            return '';
        }
        if (ayuda_boton_fondo_modo($boton) === 'degradado') {
            $fondo2 = ayuda_boton_color_fondo2($boton);
            if ($fondo2 !== '') {
                return 'linear-gradient(135deg, ' . $fondo . ', ' . $fondo2 . ')';
            }
        }
        return $fondo;
    }
}

// Mismo patrón exacto que referidos_banner_store_image_upload() /
// home_gallery_store_image_upload(): misma validación, mismo bucket de
// subida por tenant, carpeta propia ('ayuda').
if (!function_exists('ayuda_boton_store_image_upload')) {
    function ayuda_boton_store_image_upload(string $boton, array $file): array {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return ['success' => true, 'path' => ''];
        }

        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            return ['success' => false, 'message' => 'No se pudo cargar la imagen del botón.'];
        }

        $tmpName = (string) ($file['tmp_name'] ?? '');
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            return ['success' => false, 'message' => 'El archivo del botón no es válido.'];
        }

        if (($file['size'] ?? 0) > 4 * 1024 * 1024) {
            return ['success' => false, 'message' => 'La imagen del botón no puede superar 4 MB.'];
        }

        $imageInfo = @getimagesize($tmpName);
        if ($imageInfo === false) {
            return ['success' => false, 'message' => 'La imagen del botón debe ser una imagen válida.'];
        }

        $mime = (string) ($imageInfo['mime'] ?? '');
        $extensions = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
        ];
        if (!isset($extensions[$mime])) {
            return ['success' => false, 'message' => 'Formato no permitido. Usa JPG, PNG, WEBP o GIF.'];
        }

        $targetDir = tenant_upload_absolute_dir('ayuda');
        if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
            return ['success' => false, 'message' => 'No se pudo crear la carpeta de íconos de Ayuda.'];
        }

        $safeBoton = preg_replace('/[^a-z]/', '', $boton) ?: 'boton';
        $fileName = $safeBoton . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $extensions[$mime];
        $targetPath = $targetDir . DIRECTORY_SEPARATOR . $fileName;
        if (!move_uploaded_file($tmpName, $targetPath)) {
            return ['success' => false, 'message' => 'No se pudo guardar la imagen del botón en el servidor.'];
        }

        return ['success' => true, 'path' => tenant_upload_public_path('ayuda', $fileName, true)];
    }
}

if (!function_exists('ayuda_boton_delete_image_file')) {
    function ayuda_boton_delete_image_file(string $relativePath): void {
        if ($relativePath === '' || !tenant_is_managed_path($relativePath, 'ayuda')) {
            return;
        }
        $absolutePath = tenant_resolve_public_path($relativePath);
        if ($absolutePath !== null && is_file($absolutePath)) {
            @unlink($absolutePath);
        }
    }
}

// ── Tutoriales: lista de videos (YouTube o TikTok) ──────────────────────

if (!function_exists('ayuda_tiktok_extraer_id')) {
    function ayuda_tiktok_extraer_id(string $url): string {
        $url = trim($url);
        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return '';
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $host = preg_replace('/^www\./', '', $host);
        if ($host === null || strpos($host, 'tiktok.com') === false) {
            return '';
        }

        $path = (string) parse_url($url, PHP_URL_PATH);
        if (preg_match('#/video/(\d+)#', $path, $m) === 1) {
            return $m[1];
        }

        // Links cortos (vm.tiktok.com, vt.tiktok.com, o /t/xxxx) no traen el
        // ID en la URL: hay que seguir la redirección para resolverlo.
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_NOBODY => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_TIMEOUT => 6,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; AyudaBot/1.0)',
            ]);
            curl_exec($ch);
            $resolvedUrl = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
            curl_close($ch);

            if ($resolvedUrl !== '' && $resolvedUrl !== $url) {
                $resolvedPath = (string) parse_url($resolvedUrl, PHP_URL_PATH);
                if (preg_match('#/video/(\d+)#', $resolvedPath, $m) === 1) {
                    return $m[1];
                }
            }
        }

        return '';
    }
}

if (!function_exists('ayuda_tiktok_embed_url')) {
    function ayuda_tiktok_embed_url(string $tiktokId): string {
        if (!preg_match('/^\d+$/', $tiktokId)) {
            return '';
        }
        return 'https://www.tiktok.com/embed/v2/' . $tiktokId;
    }
}

// TikTok no expone una URL de miniatura predecible como YouTube — hay que
// pedirla a su endpoint público de oEmbed (no requiere autenticación). Es
// "mejor esfuerzo": si TikTok no responde o bloquea la IP del servidor, se
// guarda con thumbnail_url vacío y el frontend cae a un ícono genérico.
if (!function_exists('ayuda_tiktok_oembed_thumbnail')) {
    function ayuda_tiktok_oembed_thumbnail(string $tiktokId): string {
        if (!function_exists('curl_init') || !preg_match('/^\d+$/', $tiktokId)) {
            return '';
        }

        $watchUrl = 'https://www.tiktok.com/@tiktok/video/' . $tiktokId;
        $ch = curl_init('https://www.tiktok.com/oembed?url=' . urlencode($watchUrl));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 6,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; AyudaBot/1.0)',
        ]);
        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || $response === false) {
            return '';
        }
        $data = json_decode((string) $response, true);
        return is_array($data) ? trim((string) ($data['thumbnail_url'] ?? '')) : '';
    }
}

if (!function_exists('ayuda_tutoriales_resolver_enlace')) {
    function ayuda_tutoriales_resolver_enlace(string $url): array {
        $url = trim($url);
        if ($url === '') {
            return ['ok' => false, 'message' => 'El enlace del video es obligatorio.'];
        }

        $youtubeId = store_config_extract_youtube_video_id($url);
        if ($youtubeId !== '') {
            // /shorts/ es formato vertical (9:16), el resto es horizontal (16:9).
            $path = (string) parse_url($url, PHP_URL_PATH);
            $esVertical = strpos($path, '/shorts/') !== false;
            return [
                'ok' => true,
                'tipo' => 'youtube',
                'video_id' => $youtubeId,
                'enlace' => store_config_normalize_youtube_url($url),
                'embed_url' => store_config_youtube_embed_url($url),
                'orientacion' => $esVertical ? 'portrait' : 'landscape',
                'thumbnail_url' => 'https://img.youtube.com/vi/' . $youtubeId . '/hqdefault.jpg',
            ];
        }

        $tiktokId = ayuda_tiktok_extraer_id($url);
        if ($tiktokId !== '') {
            return [
                'ok' => true,
                'tipo' => 'tiktok',
                'video_id' => $tiktokId,
                'enlace' => 'https://www.tiktok.com/embed/v2/' . $tiktokId,
                'embed_url' => ayuda_tiktok_embed_url($tiktokId),
                'orientacion' => 'portrait',
                'thumbnail_url' => ayuda_tiktok_oembed_thumbnail($tiktokId),
            ];
        }

        return ['ok' => false, 'message' => 'El enlace debe ser un video válido de YouTube o TikTok.'];
    }
}

if (!function_exists('ayuda_tutoriales_listar')) {
    function ayuda_tutoriales_listar(): array {
        $raw = trim((string) store_config_get('ayuda_tutoriales_videos', ''));
        if ($raw === '') {
            return [];
        }
        $items = json_decode($raw, true);
        if (!is_array($items)) {
            return [];
        }

        $out = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $titulo = trim((string) ($item['titulo'] ?? ''));
            $tipo = (string) ($item['tipo'] ?? '');
            $videoId = (string) ($item['video_id'] ?? '');
            $embedUrl = $tipo === 'youtube'
                ? store_config_youtube_embed_url((string) ($item['enlace'] ?? $videoId))
                : ayuda_tiktok_embed_url($videoId);
            if ($titulo === '' || $embedUrl === '') {
                continue;
            }
            // orientacion/thumbnail_url no existían antes de agregar las
            // miniaturas — si un video se guardó antes de eso, se recalculan
            // acá con un valor razonable por defecto (sin pedirle nada a
            // TikTok de nuevo en cada carga de página, solo al guardar).
            $orientacion = (string) ($item['orientacion'] ?? '');
            if ($orientacion !== 'portrait' && $orientacion !== 'landscape') {
                $orientacion = $tipo === 'tiktok' ? 'portrait' : 'landscape';
            }
            $thumbnailUrl = trim((string) ($item['thumbnail_url'] ?? ''));
            if ($thumbnailUrl === '' && $tipo === 'youtube' && $videoId !== '') {
                $thumbnailUrl = 'https://img.youtube.com/vi/' . $videoId . '/hqdefault.jpg';
            }
            $out[] = [
                'titulo' => $titulo,
                'tipo' => $tipo,
                'video_id' => $videoId,
                'enlace' => (string) ($item['enlace'] ?? ''),
                'embed_url' => $embedUrl,
                'orientacion' => $orientacion,
                'thumbnail_url' => $thumbnailUrl,
            ];
        }

        return $out;
    }
}

// $items: array de ['titulo' => string, 'enlace' => string, 'orientacion' =>
// string opcional] (crudo, tal cual viene del formulario). Valida y resuelve
// cada enlace; si alguno no es válido, devuelve el error puntual sin guardar
// nada (todo o nada).
//
// La "orientacion" (landscape/portrait) NO se puede detectar de forma
// confiable solo con la URL: un YouTube Short se puede compartir con un
// link normal de /watch?v=... indistinguible de un video horizontal (el
// hint de /shorts/ en la URL solo funciona si el admin pegó ESE formato de
// link puntual). Por eso el admin puede elegirla a mano por cada video — si
// no manda un valor válido, se usa la sugerencia automática de
// ayuda_tutoriales_resolver_enlace() como respaldo.
if (!function_exists('ayuda_tutoriales_guardar')) {
    function ayuda_tutoriales_guardar(array $items): array {
        $out = [];
        foreach ($items as $i => $item) {
            $titulo = trim((string) ($item['titulo'] ?? ''));
            $enlace = trim((string) ($item['enlace'] ?? ''));
            if ($titulo === '' && $enlace === '') {
                continue; // fila vacía del repetidor, se ignora
            }
            if ($titulo === '') {
                return ['success' => false, 'message' => 'Falta el título del video #' . ($i + 1) . '.'];
            }

            $resuelto = ayuda_tutoriales_resolver_enlace($enlace);
            if (!$resuelto['ok']) {
                return ['success' => false, 'message' => 'Video "' . $titulo . '": ' . $resuelto['message']];
            }

            $orientacionForm = strtolower(trim((string) ($item['orientacion'] ?? '')));
            $orientacion = in_array($orientacionForm, ['landscape', 'portrait'], true)
                ? $orientacionForm
                : $resuelto['orientacion'];

            $out[] = [
                'titulo' => $titulo,
                'tipo' => $resuelto['tipo'],
                'video_id' => $resuelto['video_id'],
                'enlace' => $resuelto['enlace'],
                'orientacion' => $orientacion,
                'thumbnail_url' => $resuelto['thumbnail_url'],
            ];
        }

        $ok = store_config_upsert('ayuda_tutoriales_videos', json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return ['success' => $ok, 'message' => $ok ? '' : 'No se pudo guardar la lista de tutoriales.', 'items' => $out];
    }
}
