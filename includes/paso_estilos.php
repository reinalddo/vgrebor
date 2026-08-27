<?php
// Estilos configurables de los títulos "PASO 1/2/3" y del verificador de
// jugador (campo, botón, resultado exitoso, resultado fallido) en game.php.
// Mismo patrón que includes/ayuda.php: getters con fallback a un default,
// modo 'original'/'personalizado' por zona (igual que el 'defecto' de
// Soporte/Canal en Ayuda) para que el cliente pueda volver al diseño de
// siempre con un solo clic sin perder lo que ya configuró.

if (!function_exists('paso_estilo_zonas_validas')) {
    function paso_estilo_zonas_validas(): array {
        return ['paso1', 'paso2', 'paso3', 'campo', 'boton', 'exito', 'fallo'];
    }
}

// paso1/paso2/paso3 son 3 zonas independientes a propósito (no una sola
// "paso" compartida) — el cliente pidió poder diferenciar cada línea, y que
// el fondo cubra el título completo ("PASO 1: Ingrese su información..."),
// no solo la palabra "PASO 1".
if (!function_exists('paso_estilo_zonas_con_texto')) {
    function paso_estilo_zonas_con_texto(): array {
        return ['paso1', 'paso2', 'paso3'];
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
                'fuente_tamano' => '1.6rem',
                'color_texto' => '#0B1420',
                'borde_neon_activo' => '1',
                'color_borde' => '#22D3EE',
            ],
            'paso2' => [
                'texto' => 'PASO 2: Seleccione su producto',
                'fondo_tipo' => 'solido',
                'color_fondo' => '#22D3EE',
                'color_fondo2' => '#0EA5B7',
                'fuente_familia' => 'heredado',
                'fuente_tamano' => '1.6rem',
                'color_texto' => '#0B1420',
                'borde_neon_activo' => '1',
                'color_borde' => '#22D3EE',
            ],
            'paso3' => [
                'texto' => 'PASO 3: Configure su pago y continúe con la compra',
                'fondo_tipo' => 'solido',
                'color_fondo' => '#22D3EE',
                'color_fondo2' => '#0EA5B7',
                'fuente_familia' => 'heredado',
                'fuente_tamano' => '1.6rem',
                'color_texto' => '#0B1420',
                'borde_neon_activo' => '1',
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
                'fondo_tipo' => 'solido',
                'color_fondo' => '#0B1420',
                'color_fondo2' => '#111827',
                'fuente_familia' => 'heredado',
                'fuente_tamano' => '1rem',
                'color_texto' => '#22D3EE',
                'borde_neon_activo' => '1',
                'color_borde' => '#22D3EE',
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
            ],
        ];

        $zonaDefaults = $defaults[$zona] ?? $defaults['paso1'];
        // Grosor de borde y de brillo neón son iguales para las 7 zonas por
        // defecto (los valores que ya estaban hardcodeados: 1px de borde,
        // 14px de brillo) — se agregan acá en vez de repetirlos en cada
        // bloque de arriba. `+` no pisa una clave que ya exista a la izq.
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

// Texto editable — solo paso1/paso2/paso3 lo usan (el título completo, no
// solo la palabra "PASO N"). Para las demás zonas devuelve '' y no se
// muestra ningún campo de texto en el admin.
if (!function_exists('paso_estilo_texto')) {
    function paso_estilo_texto(string $zona): string {
        if (!paso_estilo_zona_tiene_texto($zona)) {
            return '';
        }
        $defaults = paso_estilo_defaults($zona);
        $texto = trim((string) store_config_get('paso_estilo_' . $zona . '_texto', $defaults['texto']));
        return $texto !== '' ? $texto : $defaults['texto'];
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

// 'personalizado' es una 4ta opción además de las 3 predefinidas — el admin
// escribe su propio emoji/símbolo en vez de elegir uno de la galería.
if (!function_exists('paso_estilo_icono_clave')) {
    function paso_estilo_icono_clave(string $zona): string {
        $disponibles = paso_estilo_iconos_disponibles($zona);
        if (empty($disponibles)) {
            return '';
        }
        $defaults = paso_estilo_defaults($zona);
        $clave = trim((string) store_config_get('paso_estilo_' . $zona . '_icono', $defaults['icono'] ?? ''));
        if ($clave === 'personalizado' || array_key_exists($clave, $disponibles)) {
            return $clave;
        }
        return array_key_first($disponibles);
    }
}

if (!function_exists('paso_estilo_icono_personalizado')) {
    function paso_estilo_icono_personalizado(string $zona): string {
        $disponibles = paso_estilo_iconos_disponibles($zona);
        $defaultEmoji = $disponibles[array_key_first($disponibles)] ?? '';
        $emoji = trim((string) store_config_get('paso_estilo_' . $zona . '_icono_personalizado', $defaultEmoji));
        return $emoji !== '' ? $emoji : $defaultEmoji;
    }
}

if (!function_exists('paso_estilo_icono_emoji')) {
    function paso_estilo_icono_emoji(string $zona): string {
        $clave = paso_estilo_icono_clave($zona);
        if ($clave === 'personalizado') {
            return paso_estilo_icono_personalizado($zona);
        }
        $disponibles = paso_estilo_iconos_disponibles($zona);
        return $disponibles[$clave] ?? '';
    }
}
