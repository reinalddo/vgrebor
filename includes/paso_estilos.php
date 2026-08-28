<?php
// Estilos configurables de los títulos "PASO 1/2/3" y del verificador de
// jugador (campo, botón, resultado exitoso, resultado fallido) en game.php.
// Mismo patrón que includes/ayuda.php: getters con fallback a un default,
// modo 'original'/'personalizado' por zona (igual que el 'defecto' de
// Soporte/Canal en Ayuda) para que el cliente pueda volver al diseño de
// siempre con un solo clic sin perder lo que ya configuró.

// paso1/paso2/paso3 son la "insignia" (PASO N) de cada línea; paso1_resto/
// paso2_resto/paso3_resto son el resto de la frase — 2 recuadros
// independientes por título, pedido explícito del cliente. El texto se
// sigue editando en UN solo campo (el de paso1/2/3, el título completo) y
// se reparte entre insignia/resto con paso_estilo_partir_texto_paso().
if (!function_exists('paso_estilo_zonas_validas')) {
    function paso_estilo_zonas_validas(): array {
        return [
            'paso1', 'paso1_resto',
            'paso2', 'paso2_resto',
            'paso3', 'paso3_resto',
            'campo', 'boton', 'exito', 'fallo',
        ];
    }
}

if (!function_exists('paso_estilo_zonas_con_texto')) {
    function paso_estilo_zonas_con_texto(): array {
        return ['paso1', 'paso2', 'paso3', 'boton'];
    }
}

if (!function_exists('paso_estilo_zona_tiene_texto')) {
    function paso_estilo_zona_tiene_texto(string $zona): bool {
        return in_array($zona, paso_estilo_zonas_con_texto(), true);
    }
}

if (!function_exists('paso_estilo_zona_valida')) {
    function paso_estilo_zona_valida(string $zona): bool {
        return in_array($zona, paso_estilo_zonas_validas(), true);
    }
}

// Zonas que tienen su propio sistema de ícono (preset/emoji/imagen) — las
// demás (paso1/2/3, sus "_resto" y el campo de texto) no llevan ícono.
if (!function_exists('paso_estilo_zona_tiene_icono')) {
    function paso_estilo_zona_tiene_icono(string $zona): bool {
        return in_array($zona, ['boton', 'exito', 'fallo'], true);
    }
}

// Mapa de familias de fuente permitidas — lista corta y fija (no texto
// libre) para no romper el diseño ni cargar fuentes que el sitio no tiene
// enlazadas. 'heredado' = no forzar nada, seguir usando la fuente del
// elemento tal cual la define el CSS general de la página.
if (!function_exists('paso_estilo_fuentes_disponibles')) {
    function paso_estilo_fuentes_disponibles(): array {
        return [
            'heredado' => '',
            'oxanium' => "'Oxanium', sans-serif",
            'space_grotesk' => "'Space Grotesk', sans-serif",
            'sans' => "system-ui, -apple-system, 'Segoe UI', sans-serif",
            'serif' => "Georgia, 'Times New Roman', serif",
        ];
    }
}

if (!function_exists('paso_estilo_defaults')) {
    function paso_estilo_defaults(string $zona): array {
        $defaults = [
            'paso1' => [
                'texto' => 'PASO 1: Ingrese su información de jugador',
                'fondo_tipo' => 'solido',
                'color_fondo' => '#22D3EE',
                'color_fondo2' => '#0EA5B7',
                'fuente_familia' => 'heredado',
                'fuente_tamano' => '2.35rem',
                'color_texto' => '#0B1420',
                'borde_neon_activo' => '1',
                'color_borde' => '#22D3EE',
            ],
            'paso1_resto' => [
                'fondo_tipo' => 'solido',
                'color_fondo' => '#0B1420',
                'color_fondo2' => '#111827',
                'fuente_familia' => 'heredado',
                'fuente_tamano' => '2.35rem',
                'color_texto' => '#22D3EE',
                'borde_neon_activo' => '0',
                'color_borde' => '#22D3EE',
            ],
            'paso2' => [
                'texto' => 'PASO 2: Seleccione su producto',
                'fondo_tipo' => 'solido',
                'color_fondo' => '#22D3EE',
                'color_fondo2' => '#0EA5B7',
                'fuente_familia' => 'heredado',
                'fuente_tamano' => '2.35rem',
                'color_texto' => '#0B1420',
                'borde_neon_activo' => '1',
                'color_borde' => '#22D3EE',
            ],
            'paso2_resto' => [
                'fondo_tipo' => 'solido',
                'color_fondo' => '#0B1420',
                'color_fondo2' => '#111827',
                'fuente_familia' => 'heredado',
                'fuente_tamano' => '2.35rem',
                'color_texto' => '#22D3EE',
                'borde_neon_activo' => '0',
                'color_borde' => '#22D3EE',
            ],
            'paso3' => [
                'texto' => 'PASO 3: Configure su pago y continúe con la compra',
                'fondo_tipo' => 'solido',
                'color_fondo' => '#22D3EE',
                'color_fondo2' => '#0EA5B7',
                'fuente_familia' => 'heredado',
                'fuente_tamano' => '2.35rem',
                'color_texto' => '#0B1420',
                'borde_neon_activo' => '1',
                'color_borde' => '#22D3EE',
            ],
            'paso3_resto' => [
                'fondo_tipo' => 'solido',
                'color_fondo' => '#0B1420',
                'color_fondo2' => '#111827',
                'fuente_familia' => 'heredado',
                'fuente_tamano' => '2.35rem',
                'color_texto' => '#22D3EE',
                'borde_neon_activo' => '0',
                'color_borde' => '#22D3EE',
            ],
            'campo' => [
                'fondo_tipo' => 'solido',
                'color_fondo' => '#0B1420',
                'color_fondo2' => '#111827',
                'fuente_familia' => 'heredado',
                'fuente_tamano' => '1rem',
                'color_texto' => '#22D3EE',
                'borde_neon_activo' => '1',
                'color_borde' => '#22D3EE',
            ],
            'boton' => [
                'texto' => 'Verificar nombre del jugador',
                'fondo_tipo' => 'solido',
                'color_fondo' => '#0B1420',
                'color_fondo2' => '#111827',
                'fuente_familia' => 'heredado',
                'fuente_tamano' => '1rem',
                'color_texto' => '#22D3EE',
                'borde_neon_activo' => '1',
                'color_borde' => '#22D3EE',
                'icono' => 'ninguno',
            ],
            'exito' => [
                'fondo_tipo' => 'degradado',
                'color_fondo' => '#052E1F',
                'color_fondo2' => '#10B981',
                'fuente_familia' => 'heredado',
                'fuente_tamano' => '1rem',
                'color_texto' => '#FFFFFF',
                'borde_neon_activo' => '1',
                'color_borde' => '#34D399',
                'icono' => 'escudo',
                'badge_texto' => 'VERIFICADO',
                'badge_color_fondo' => '#34D399',
                'badge_color_texto' => '#052E1F',
            ],
            'fallo' => [
                'fondo_tipo' => 'degradado',
                'color_fondo' => '#450A0A',
                'color_fondo2' => '#EF4444',
                'fuente_familia' => 'heredado',
                'fuente_tamano' => '1rem',
                'color_texto' => '#FFFFFF',
                'borde_neon_activo' => '1',
                'color_borde' => '#F87171',
                'icono' => 'bloqueo',
                'badge_texto' => 'INVALIDO',
                'badge_color_fondo' => '#F87171',
                'badge_color_texto' => '#450A0A',
                'mensaje_modo' => 'api',
                'mensaje_personalizado' => '',
            ],
        ];

        $zonaDefaults = $defaults[$zona] ?? $defaults['paso1'];
        // Grosor de borde y de brillo neón son iguales para todas las zonas
        // por defecto (los valores que ya estaban hardcodeados: 1px de
        // borde, 14px de brillo) — se agregan acá en vez de repetirlos en
        // cada bloque de arriba. `+` no pisa una clave que ya exista a la izq.
        return $zonaDefaults + ['borde_grosor' => '1', 'borde_brillo' => '14'];
    }
}

// 'original' = usar el diseño de siempre (el que ya existe en game.php),
// ignorando todo lo demás de esta zona. Es el default hasta que el admin
// active 'personalizado' — así nunca cambia nada a menos que se toque a
// propósito, y se puede volver atrás con un solo clic sin perder los
// valores personalizados guardados.
if (!function_exists('paso_estilo_modo')) {
    function paso_estilo_modo(string $zona): string {
        $modo = trim((string) store_config_get('paso_estilo_' . $zona . '_modo', 'original'));
        return $modo === 'personalizado' ? 'personalizado' : 'original';
    }
}

if (!function_exists('paso_estilo_esta_personalizado')) {
    function paso_estilo_esta_personalizado(string $zona): bool {
        return paso_estilo_modo($zona) === 'personalizado';
    }
}

if (!function_exists('paso_estilo_fondo_tipo')) {
    function paso_estilo_fondo_tipo(string $zona): string {
        $defaults = paso_estilo_defaults($zona);
        $tipo = trim((string) store_config_get('paso_estilo_' . $zona . '_fondo_tipo', $defaults['fondo_tipo']));
        return $tipo === 'degradado' ? 'degradado' : 'solido';
    }
}

if (!function_exists('paso_estilo_color_fondo')) {
    function paso_estilo_color_fondo(string $zona): string {
        $defaults = paso_estilo_defaults($zona);
        $color = trim((string) store_config_get('paso_estilo_' . $zona . '_color_fondo', $defaults['color_fondo']));
        return store_config_normalize_hex_color($color, $defaults['color_fondo']);
    }
}

if (!function_exists('paso_estilo_color_fondo2')) {
    function paso_estilo_color_fondo2(string $zona): string {
        $defaults = paso_estilo_defaults($zona);
        $color = trim((string) store_config_get('paso_estilo_' . $zona . '_color_fondo2', $defaults['color_fondo2']));
        return store_config_normalize_hex_color($color, $defaults['color_fondo2']);
    }
}

// Valor final para el CSS `background:` — un color plano o el degradado de
// los 2 colores, según el tipo de fondo elegido. Mismo patrón que
// ayuda_boton_fondo_css() en includes/ayuda.php.
if (!function_exists('paso_estilo_fondo_css')) {
    function paso_estilo_fondo_css(string $zona): string {
        $color1 = paso_estilo_color_fondo($zona);
        if (paso_estilo_fondo_tipo($zona) === 'degradado') {
            $color2 = paso_estilo_color_fondo2($zona);
            return 'linear-gradient(135deg, ' . $color1 . ', ' . $color2 . ')';
        }
        return $color1;
    }
}

if (!function_exists('paso_estilo_color_texto')) {
    function paso_estilo_color_texto(string $zona): string {
        $defaults = paso_estilo_defaults($zona);
        $color = trim((string) store_config_get('paso_estilo_' . $zona . '_color_texto', $defaults['color_texto']));
        return store_config_normalize_hex_color($color, $defaults['color_texto']);
    }
}

if (!function_exists('paso_estilo_fuente_familia')) {
    function paso_estilo_fuente_familia(string $zona): string {
        $defaults = paso_estilo_defaults($zona);
        $clave = trim((string) store_config_get('paso_estilo_' . $zona . '_fuente_familia', $defaults['fuente_familia']));
        $disponibles = paso_estilo_fuentes_disponibles();
        return array_key_exists($clave, $disponibles) ? $clave : $defaults['fuente_familia'];
    }
}

// El valor CSS real de `font-family` para la clave guardada — '' si es
// 'heredado' (no forzar nada).
if (!function_exists('paso_estilo_fuente_familia_css')) {
    function paso_estilo_fuente_familia_css(string $zona): string {
        $disponibles = paso_estilo_fuentes_disponibles();
        return $disponibles[paso_estilo_fuente_familia($zona)] ?? '';
    }
}

// Tamaño de letra en rem, acotado a un rango razonable (0.6rem–3rem) para
// que no se pueda dejar un texto invisible o gigantesco por error.
if (!function_exists('paso_estilo_fuente_tamano')) {
    function paso_estilo_fuente_tamano(string $zona): string {
        $defaults = paso_estilo_defaults($zona);
        $valor = trim((string) store_config_get('paso_estilo_' . $zona . '_fuente_tamano', $defaults['fuente_tamano']));
        if (preg_match('/^(\d+(?:\.\d+)?)rem$/', $valor, $m) !== 1) {
            return $defaults['fuente_tamano'];
        }
        $numero = (float) $m[1];
        if ($numero < 0.6 || $numero > 3) {
            return $defaults['fuente_tamano'];
        }
        return $numero . 'rem';
    }
}

if (!function_exists('paso_estilo_borde_neon_activo')) {
    function paso_estilo_borde_neon_activo(string $zona): bool {
        $defaults = paso_estilo_defaults($zona);
        $valor = trim((string) store_config_get('paso_estilo_' . $zona . '_borde_neon_activo', $defaults['borde_neon_activo']));
        return $valor === '1';
    }
}

if (!function_exists('paso_estilo_color_borde')) {
    function paso_estilo_color_borde(string $zona): string {
        $defaults = paso_estilo_defaults($zona);
        $color = trim((string) store_config_get('paso_estilo_' . $zona . '_color_borde', $defaults['color_borde']));
        return store_config_normalize_hex_color($color, $defaults['color_borde']);
    }
}

// Grosor de la línea del borde neón, en px (1-6). Fuera de rango o no
// numérico cae al default (1px, el que ya estaba hardcodeado).
if (!function_exists('paso_estilo_borde_grosor')) {
    function paso_estilo_borde_grosor(string $zona): int {
        $defaults = paso_estilo_defaults($zona);
        $valor = (int) trim((string) store_config_get('paso_estilo_' . $zona . '_borde_grosor', $defaults['borde_grosor']));
        if ($valor < 1 || $valor > 6) {
            return (int) $defaults['borde_grosor'];
        }
        return $valor;
    }
}

// Tamaño del brillo (blur del box-shadow), en px (2-40). El brillo interior
// (inset) se deriva de este como la mitad, igual proporción que ya estaba
// hardcodeada (14px afuera / 6px adentro ≈ mitad).
if (!function_exists('paso_estilo_borde_brillo')) {
    function paso_estilo_borde_brillo(string $zona): int {
        $defaults = paso_estilo_defaults($zona);
        $valor = (int) trim((string) store_config_get('paso_estilo_' . $zona . '_borde_brillo', $defaults['borde_brillo']));
        if ($valor < 2 || $valor > 40) {
            return (int) $defaults['borde_brillo'];
        }
        return $valor;
    }
}

// Texto editable — solo paso1/paso2/paso3/boton lo usan. Para las demás
// zonas (incluidas paso1_resto/paso2_resto/paso3_resto, que derivan su
// texto del título completo con paso_estilo_partir_texto_paso()) devuelve
// '' y no se muestra ningún campo de texto en el admin.
if (!function_exists('paso_estilo_texto')) {
    function paso_estilo_texto(string $zona): string {
        if (!paso_estilo_zona_tiene_texto($zona)) {
            return '';
        }
        $defaults = paso_estilo_defaults($zona);
        $default = $defaults['texto'] ?? '';
        $texto = trim((string) store_config_get('paso_estilo_' . $zona . '_texto', $default));
        return $texto !== '' ? $texto : $default;
    }
}

// Separa "PASO 1: Ingrese su información de jugador" en la insignia
// ("PASO 1") y el resto de la frase ("Ingrese su información de jugador")
// — cada mitad tiene su propio recuadro/estilo configurable (paso_N y
// paso_N_resto). Si el texto no empieza con "PASO N" (el admin lo borró o
// escribió otra cosa), todo se trata como insignia y el resto queda vacío.
if (!function_exists('paso_estilo_partir_texto_paso')) {
    function paso_estilo_partir_texto_paso(string $textoCompleto): array {
        if (preg_match('/^\s*(PASO\s*\d+)\s*:?\s*(.*)$/iu', $textoCompleto, $m) === 1) {
            return ['badge' => trim($m[1]), 'resto' => trim($m[2])];
        }
        return ['badge' => trim($textoCompleto), 'resto' => ''];
    }
}

// Íconos predefinidos del resultado de verificación — 3 opciones fijas por
// zona (no texto/emoji libre) para que el cliente elija con un clic, además
// de poder seguir personalizando colores/fondo como ya podía.
if (!function_exists('paso_estilo_iconos_disponibles')) {
    function paso_estilo_iconos_disponibles(string $zona): array {
        if ($zona === 'exito') {
            return ['escudo' => '🛡️', 'check' => '✅', 'estrella' => '⭐'];
        }
        if ($zona === 'fallo') {
            return ['bloqueo' => '⛔', 'equis' => '❌', 'alerta' => '⚠️'];
        }
        return [];
    }
}

// Claves válidas para el selector de ícono de una zona: los presets fijos
// (si los tiene) + 'personalizado' (emoji libre) + 'imagen' (subida propia).
// 'boton' no tiene presets, así que además admite 'ninguno' (sin ícono).
if (!function_exists('paso_estilo_icono_claves_validas')) {
    function paso_estilo_icono_claves_validas(string $zona): array {
        $presets = array_keys(paso_estilo_iconos_disponibles($zona));
        if (empty($presets)) {
            return ['ninguno', 'personalizado', 'imagen'];
        }
        return array_merge($presets, ['personalizado', 'imagen']);
    }
}

if (!function_exists('paso_estilo_icono_clave')) {
    function paso_estilo_icono_clave(string $zona): string {
        $validas = paso_estilo_icono_claves_validas($zona);
        $disponibles = paso_estilo_iconos_disponibles($zona);
        $defaults = paso_estilo_defaults($zona);
        $defaultClave = $defaults['icono'] ?? (empty($disponibles) ? 'ninguno' : array_key_first($disponibles));
        $clave = trim((string) store_config_get('paso_estilo_' . $zona . '_icono', $defaultClave));
        return in_array($clave, $validas, true) ? $clave : $defaultClave;
    }
}

if (!function_exists('paso_estilo_icono_personalizado')) {
    function paso_estilo_icono_personalizado(string $zona): string {
        $disponibles = paso_estilo_iconos_disponibles($zona);
        $defaultEmoji = !empty($disponibles) ? $disponibles[array_key_first($disponibles)] : '✏️';
        $emoji = trim((string) store_config_get('paso_estilo_' . $zona . '_icono_personalizado', $defaultEmoji));
        return $emoji !== '' ? $emoji : $defaultEmoji;
    }
}

// Ruta (relativa, gestionada por tenant_upload_*) de la imagen subida como
// ícono — solo tiene valor cuando paso_estilo_icono_clave() === 'imagen'.
if (!function_exists('paso_estilo_icono_imagen')) {
    function paso_estilo_icono_imagen(string $zona): string {
        return trim((string) store_config_get('paso_estilo_' . $zona . '_icono_imagen', ''));
    }
}

if (!function_exists('paso_estilo_icono_emoji')) {
    function paso_estilo_icono_emoji(string $zona): string {
        $clave = paso_estilo_icono_clave($zona);
        if ($clave === 'personalizado') {
            return paso_estilo_icono_personalizado($zona);
        }
        if ($clave === 'imagen' || $clave === 'ninguno') {
            return '';
        }
        $disponibles = paso_estilo_iconos_disponibles($zona);
        return $disponibles[$clave] ?? '';
    }
}

// Subida de la imagen del ícono — mismo patrón que
// ayuda_boton_store_image_upload() en includes/ayuda.php (JPG/PNG/WEBP/GIF,
// 4MB máx, nombre aleatorio en el bucket de subidas del tenant).
if (!function_exists('paso_estilo_icono_store_image_upload')) {
    function paso_estilo_icono_store_image_upload(string $zona, array $file): array {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return ['success' => true, 'path' => ''];
        }

        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            return ['success' => false, 'message' => 'No se pudo cargar la imagen del ícono.'];
        }

        $tmpName = (string) ($file['tmp_name'] ?? '');
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            return ['success' => false, 'message' => 'El archivo del ícono no es válido.'];
        }

        if (($file['size'] ?? 0) > 4 * 1024 * 1024) {
            return ['success' => false, 'message' => 'La imagen del ícono no puede superar 4 MB.'];
        }

        $imageInfo = @getimagesize($tmpName);
        if ($imageInfo === false) {
            return ['success' => false, 'message' => 'La imagen del ícono debe ser una imagen válida.'];
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

        $targetDir = tenant_upload_absolute_dir('paso_estilos');
        if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
            return ['success' => false, 'message' => 'No se pudo crear la carpeta de íconos.'];
        }

        $safeZona = preg_replace('/[^a-z0-9]/', '', $zona) ?: 'icono';
        $fileName = $safeZona . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $extensions[$mime];
        $targetPath = $targetDir . DIRECTORY_SEPARATOR . $fileName;
        if (!move_uploaded_file($tmpName, $targetPath)) {
            return ['success' => false, 'message' => 'No se pudo guardar la imagen en el servidor.'];
        }

        return ['success' => true, 'path' => tenant_upload_public_path('paso_estilos', $fileName, true)];
    }
}

if (!function_exists('paso_estilo_icono_delete_image_file')) {
    function paso_estilo_icono_delete_image_file(string $relativePath): void {
        if ($relativePath === '' || !tenant_is_managed_path($relativePath, 'paso_estilos')) {
            return;
        }
        $absolutePath = tenant_resolve_public_path($relativePath);
        if ($absolutePath !== null && is_file($absolutePath)) {
            @unlink($absolutePath);
        }
    }
}

// ── Columna 3 del resultado (éxito/fallo): la insignia fija
// "VERIFICADO"/"INVALIDO", con su propio color de fondo y de letra —
// independiente del mensaje de la columna 2, tal como lo pidió el cliente
// ("ese de verificado sería separado del nick porque tiene otro color"). ──
if (!function_exists('paso_estilo_badge_texto')) {
    function paso_estilo_badge_texto(string $zona): string {
        $defaults = paso_estilo_defaults($zona);
        $default = $defaults['badge_texto'] ?? '';
        $texto = trim((string) store_config_get('paso_estilo_' . $zona . '_badge_texto', $default));
        $texto = mb_substr($texto, 0, 24);
        return $texto !== '' ? $texto : $default;
    }
}

if (!function_exists('paso_estilo_badge_color_fondo')) {
    function paso_estilo_badge_color_fondo(string $zona): string {
        $defaults = paso_estilo_defaults($zona);
        $default = $defaults['badge_color_fondo'] ?? '#FFFFFF';
        $color = trim((string) store_config_get('paso_estilo_' . $zona . '_badge_color_fondo', $default));
        return store_config_normalize_hex_color($color, $default);
    }
}

if (!function_exists('paso_estilo_badge_color_texto')) {
    function paso_estilo_badge_color_texto(string $zona): string {
        $defaults = paso_estilo_defaults($zona);
        $default = $defaults['badge_color_texto'] ?? '#000000';
        $color = trim((string) store_config_get('paso_estilo_' . $zona . '_badge_color_texto', $default));
        return store_config_normalize_hex_color($color, $default);
    }
}

// ── Columna 2 del resultado fallido: usar el mensaje real de la API
// (default) o uno fijo escrito por el admin. Éxito no lleva este selector
// porque siempre debe mostrar el nombre del jugador verificado. ──
if (!function_exists('paso_estilo_mensaje_modo')) {
    function paso_estilo_mensaje_modo(string $zona): string {
        $modo = trim((string) store_config_get('paso_estilo_' . $zona . '_mensaje_modo', 'api'));
        return $modo === 'personalizado' ? 'personalizado' : 'api';
    }
}

if (!function_exists('paso_estilo_mensaje_personalizado')) {
    function paso_estilo_mensaje_personalizado(string $zona): string {
        $texto = trim((string) store_config_get('paso_estilo_' . $zona . '_mensaje_personalizado', ''));
        return mb_substr($texto, 0, 160);
    }
}
