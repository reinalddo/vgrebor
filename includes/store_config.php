<?php

require_once __DIR__ . '/tenant.php';

function store_theme_definitions(): array {
    return [
        'theme_bg_main' => [
            'label' => 'Fondo principal',
            'default' => '#0A0F14',
            'description' => 'Color base del fondo general de la tienda',
        ],
        'theme_bg_alt' => [
            'label' => 'Fondo secundario',
            'default' => '#0E1722',
            'description' => 'Color usado en degradados y secciones secundarias',
        ],
        'theme_surface' => [
            'label' => 'Superficie principal',
            'default' => '#111827',
            'description' => 'Color de tarjetas, paneles y modales',
        ],
        'theme_surface_alt' => [
            'label' => 'Superficie alterna',
            'default' => '#181F2A',
            'description' => 'Color alterno para cabecera, dropdowns y paneles internos',
        ],
        'theme_primary' => [
            'label' => 'Neón principal',
            'default' => '#22D3EE',
            'description' => 'Color principal del brillo, bordes y textos destacados',
        ],
        'theme_highlight' => [
            'label' => 'Neón intenso',
            'default' => '#00FFF7',
            'description' => 'Color de realce para brillos y botones destacados',
        ],
        'theme_secondary' => [
            'label' => 'Neón secundario',
            'default' => '#2DD4BF',
            'description' => 'Color secundario para degradados, hover y efectos',
        ],
        'theme_success' => [
            'label' => 'Color de éxito',
            'default' => '#34D399',
            'description' => 'Color para acciones positivas y estados correctos',
        ],
        'theme_warning' => [
            'label' => 'Color de advertencia',
            'default' => '#F59E0B',
            'description' => 'Color para alertas y estados de revisión',
        ],
        'theme_danger' => [
            'label' => 'Color de error',
            'default' => '#F87171',
            'description' => 'Color para cancelaciones, errores y alertas críticas',
        ],
        'theme_text' => [
            'label' => 'Texto principal',
            'default' => '#F8FAFC',
            'description' => 'Color principal del texto en la tienda',
        ],
        'theme_text_muted' => [
            'label' => 'Texto secundario',
            'default' => '#CBD5E1',
            'description' => 'Color de textos secundarios, ayudas y descripciones',
        ],
        'theme_price_text' => [
            'label' => 'Precio principal',
            'default' => '#22D3EE',
            'description' => 'Color del monto principal en precios de juegos y paquetes',
        ],
        'theme_price_muted' => [
            'label' => 'Precio auxiliar',
            'default' => '#94A3B8',
            'description' => 'Color del prefijo "Desde" y la moneda en precios de juegos y paquetes',
        ],
        'theme_border' => [
            'label' => 'Borde base',
            'default' => '#164E63',
            'description' => 'Color base de bordes, separadores y contenedores',
        ],
        'theme_button_primary' => [
            'label' => 'Botón principal',
            'default' => '#22D3EE',
            'description' => 'Color principal para botones, acciones y llamadas principales',
        ],
        'theme_button_secondary' => [
            'label' => 'Botón secundario',
            'default' => '#2DD4BF',
            'description' => 'Color secundario para degradados y hover de botones',
        ],
        'theme_button_surface' => [
            'label' => 'Botón base oscuro',
            'default' => '#0E1722',
            'description' => 'Color base para botones oscuros, menú y tarjetas seleccionables',
        ],
        'theme_account_preview_button_bg' => [
            'label' => 'Ver más fondo',
            'default' => '#0C99B8',
            'description' => 'Color de fondo del botón Ver más para vender cuentas',
        ],
        'theme_account_preview_button_border' => [
            'label' => 'Ver más borde',
            'default' => '#67E8F9',
            'description' => 'Color del borde del botón Ver más para vender cuentas',
        ],
        'theme_account_preview_button_text' => [
            'label' => 'Ver más texto',
            'default' => '#F0FDFF',
            'description' => 'Color del texto del botón Ver más para vender cuentas',
        ],
        'theme_account_preview_button_shadow' => [
            'label' => 'Ver más sombreado',
            'default' => '#06B6D4',
            'description' => 'Color base del sombreado y brillo del botón Ver más para vender cuentas',
        ],
        'theme_topbar_bg' => [
            'label' => 'Barra superior fondo',
            'default' => '#0D1722',
            'description' => 'Color de fondo principal de la barra superior fija del sitio público',
        ],
        'theme_topbar_text' => [
            'label' => 'Barra superior texto',
            'default' => '#F8FAFC',
            'description' => 'Color del prefijo, nombre de tienda y textos principales de la barra superior',
        ],
        'theme_topbar_search_border' => [
            'label' => 'Barra superior buscador borde',
            'default' => '#22D3EE',
            'description' => 'Color del borde del buscador de la barra superior',
        ],
        'theme_topbar_search_bg' => [
            'label' => 'Barra superior buscador fondo',
            'default' => '#101D2A',
            'description' => 'Color de fondo del buscador de la barra superior',
        ],
        'theme_topbar_search_text' => [
            'label' => 'Barra superior buscador texto',
            'default' => '#E2F7FF',
            'description' => 'Color del texto y placeholder del buscador de la barra superior',
        ],
        'theme_topbar_login_bg' => [
            'label' => 'Barra superior botón login fondo',
            'default' => '#22D3EE',
            'description' => 'Color de fondo del botón de acceso en la barra superior',
        ],
        'theme_topbar_login_border' => [
            'label' => 'Barra superior botón login borde',
            'default' => '#67E8F9',
            'description' => 'Color del borde del botón de acceso en la barra superior',
        ],
        'theme_topbar_login_text' => [
            'label' => 'Barra superior botón login texto',
            'default' => '#062033',
            'description' => 'Color del texto del botón de acceso en la barra superior',
        ],
        'theme_game_feature_bg' => [
            'label' => 'Etiqueta juego fondo',
            'default' => '#0E1722',
            'description' => 'Color de fondo para las caracteristicas visibles en la ficha publica del juego',
        ],
        'theme_game_feature_border' => [
            'label' => 'Etiqueta juego borde',
            'default' => '#164E63',
            'description' => 'Color del borde para las caracteristicas visibles en la ficha publica del juego',
        ],
        'theme_game_feature_text' => [
            'label' => 'Etiqueta juego texto',
            'default' => '#22D3EE',
            'description' => 'Color del texto para las caracteristicas visibles en la ficha publica del juego',
        ],
        'theme_package_feature_bg' => [
            'label' => 'Etiqueta paquete fondo',
            'default' => '#0F172A',
            'description' => 'Color de fondo para las caracteristicas del paquete mostradas en el encabezado de pago minimalista',
        ],
        'theme_package_feature_border' => [
            'label' => 'Etiqueta paquete borde',
            'default' => '#164E63',
            'description' => 'Color del borde para las caracteristicas del paquete mostradas en el encabezado de pago minimalista',
        ],
        'theme_package_feature_text' => [
            'label' => 'Etiqueta paquete texto',
            'default' => '#D8FBFF',
            'description' => 'Color del texto e icono para las caracteristicas del paquete mostradas en el encabezado de pago minimalista',
        ],
        'theme_payment_main_overlay_bg' => [
            'label' => 'Pago overlay principal',
            'default' => '#050A14',
            'description' => 'Color del fondo superpuesto de la ventana principal de pago',
        ],
        'theme_payment_main_modal_bg' => [
            'label' => 'Pago modal principal fondo',
            'default' => '#111827',
            'description' => 'Color de fondo del panel principal de la ventana de pago',
        ],
        'theme_payment_main_modal_border' => [
            'label' => 'Pago modal principal borde',
            'default' => '#22D3EE',
            'description' => 'Color del borde del panel principal de la ventana de pago',
        ],
        'theme_payment_main_title' => [
            'label' => 'Pago modal principal títulos',
            'default' => '#F8FAFC',
            'description' => 'Color de los títulos y encabezados dentro de la ventana principal de pago',
        ],
        'theme_payment_main_text' => [
            'label' => 'Pago modal principal texto',
            'default' => '#CBD5E1',
            'description' => 'Color del texto descriptivo dentro de la ventana principal de pago',
        ],
        'theme_payment_main_timer_bg' => [
            'label' => 'Pago temporizador fondo',
            'default' => '#7F1D1D',
            'description' => 'Color de fondo de la franja que muestra el tiempo restante de la orden',
        ],
        'theme_payment_main_timer_border' => [
            'label' => 'Pago temporizador borde',
            'default' => '#F87171',
            'description' => 'Color del borde de la franja que muestra el tiempo restante de la orden',
        ],
        'theme_payment_main_timer_text' => [
            'label' => 'Pago temporizador texto',
            'default' => '#F87171',
            'description' => 'Color del texto del temporizador de la orden en la ventana de pago',
        ],
        'theme_payment_main_card_bg' => [
            'label' => 'Pago tarjetas fondo',
            'default' => '#080F18',
            'description' => 'Color de fondo de las tarjetas internas del modal de pago, como resumen y métodos',
        ],
        'theme_payment_main_card_border' => [
            'label' => 'Pago tarjetas borde',
            'default' => '#164E63',
            'description' => 'Color del borde de las tarjetas internas del modal de pago',
        ],
        'theme_payment_main_input_bg' => [
            'label' => 'Pago campos fondo',
            'default' => '#111827',
            'description' => 'Color de fondo de los campos de entrada y select dentro de la ventana de pago',
        ],
        'theme_payment_main_input_border' => [
            'label' => 'Pago campos borde',
            'default' => '#22D3EE',
            'description' => 'Color del borde de los campos de entrada y select dentro de la ventana de pago',
        ],
        'theme_payment_main_input_text' => [
            'label' => 'Pago campos texto',
            'default' => '#22D3EE',
            'description' => 'Color del texto de los campos de entrada y select dentro de la ventana de pago',
        ],
        'theme_payment_main_button_bg' => [
            'label' => 'Pago botón principal fondo',
            'default' => '#22D3EE',
            'description' => 'Color de fondo del botón principal del modal de pago',
        ],
        'theme_payment_main_button_text' => [
            'label' => 'Pago botón principal texto',
            'default' => '#081018',
            'description' => 'Color del texto del botón principal del modal de pago',
        ],
        'theme_payment_main_cancel_bg' => [
            'label' => 'Pago botón cancelar fondo',
            'default' => '#F87171',
            'description' => 'Color de fondo del botón para cancelar la orden en el modal de pago',
        ],
        'theme_payment_main_cancel_text' => [
            'label' => 'Pago botón cancelar texto',
            'default' => '#F8FAFC',
            'description' => 'Color del texto del botón para cancelar la orden en el modal de pago',
        ],
        'theme_payment_processing_overlay_bg' => [
            'label' => 'Procesando overlay',
            'default' => '#050A14',
            'description' => 'Color del fondo superpuesto para la ventana de Procesando pedido',
        ],
        'theme_payment_processing_modal_bg' => [
            'label' => 'Procesando fondo',
            'default' => '#111827',
            'description' => 'Color de fondo del modal Procesando pedido',
        ],
        'theme_payment_processing_modal_border' => [
            'label' => 'Procesando borde',
            'default' => '#22D3EE',
            'description' => 'Color del borde del modal Procesando pedido',
        ],
        'theme_payment_processing_spinner' => [
            'label' => 'Procesando spinner',
            'default' => '#34D399',
            'description' => 'Color del indicador circular del modal Procesando pedido',
        ],
        'theme_payment_processing_title' => [
            'label' => 'Procesando título',
            'default' => '#22D3EE',
            'description' => 'Color del título del modal Procesando pedido',
        ],
        'theme_payment_processing_text' => [
            'label' => 'Procesando texto',
            'default' => '#F8FAFC',
            'description' => 'Color del texto descriptivo del modal Procesando pedido',
        ],
        'theme_payment_sending_overlay_bg' => [
            'label' => 'Enviando overlay',
            'default' => '#050A14',
            'description' => 'Color del fondo superpuesto para la ventana de Enviando orden',
        ],
        'theme_payment_sending_modal_bg' => [
            'label' => 'Enviando fondo',
            'default' => '#111827',
            'description' => 'Color de fondo del modal Enviando orden',
        ],
        'theme_payment_sending_modal_border' => [
            'label' => 'Enviando borde',
            'default' => '#22D3EE',
            'description' => 'Color del borde del modal Enviando orden',
        ],
        'theme_payment_sending_spinner' => [
            'label' => 'Enviando spinner',
            'default' => '#22D3EE',
            'description' => 'Color del indicador circular del modal Enviando orden',
        ],
        'theme_payment_sending_title' => [
            'label' => 'Enviando título',
            'default' => '#22D3EE',
            'description' => 'Color del título del modal Enviando orden',
        ],
        'theme_payment_sending_text' => [
            'label' => 'Enviando texto',
            'default' => '#F8FAFC',
            'description' => 'Color del texto descriptivo del modal Enviando orden',
        ],
        'theme_payment_status_overlay_bg' => [
            'label' => 'Pago exitoso overlay',
            'default' => '#050A14',
            'description' => 'Color del fondo superpuesto para la ventana final de estado del pago',
        ],
        'theme_payment_status_modal_bg' => [
            'label' => 'Pago exitoso fondo',
            'default' => '#111827',
            'description' => 'Color de fondo del modal final de estado del pago',
        ],
        'theme_payment_status_modal_border' => [
            'label' => 'Pago exitoso borde',
            'default' => '#22D3EE',
            'description' => 'Color del borde del modal final de estado del pago',
        ],
        'theme_payment_status_text' => [
            'label' => 'Pago exitoso texto',
            'default' => '#F8FAFC',
            'description' => 'Color del texto descriptivo del modal final de estado del pago',
        ],
        'theme_payment_status_title_info' => [
            'label' => 'Pago exitoso título info',
            'default' => '#22D3EE',
            'description' => 'Color del título cuando el modal final muestra un estado informativo',
        ],
        'theme_payment_status_title_success' => [
            'label' => 'Pago exitoso título éxito',
            'default' => '#34D399',
            'description' => 'Color del título cuando el modal final muestra un pago exitoso',
        ],
        'theme_payment_status_title_danger' => [
            'label' => 'Pago exitoso título error',
            'default' => '#F87171',
            'description' => 'Color del título cuando el modal final muestra un error o revisión requerida',
        ],
        'theme_payment_status_button_bg' => [
            'label' => 'Pago exitoso botón fondo',
            'default' => '#22D3EE',
            'description' => 'Color de fondo del botón principal del modal final de estado del pago',
        ],
        'theme_payment_status_button_text' => [
            'label' => 'Pago exitoso botón texto',
            'default' => '#081018',
            'description' => 'Color del texto del botón principal del modal final de estado del pago',
        ],
        'theme_payment_difference_underpaid_card_bg' => [
            'label' => 'Diferencia faltante tarjeta',
            'default' => '#78350F',
            'description' => 'Color base de la tarjeta mostrada cuando falta saldo por completar en la diferencia de pagos',
        ],
        'theme_payment_difference_underpaid_text' => [
            'label' => 'Diferencia faltante texto',
            'default' => '#FDE68A',
            'description' => 'Color del texto de la tarjeta cuando falta saldo por completar en la diferencia de pagos',
        ],
        'theme_payment_difference_underpaid_button_bg' => [
            'label' => 'Diferencia faltante botón fondo',
            'default' => '#F59E0B',
            'description' => 'Color de fondo de los botones del caso donde falta saldo por completar',
        ],
        'theme_payment_difference_underpaid_button_text' => [
            'label' => 'Diferencia faltante botón texto',
            'default' => '#111827',
            'description' => 'Color del texto de los botones del caso donde falta saldo por completar',
        ],
        'theme_payment_difference_underpaid_button_hover_bg' => [
            'label' => 'Diferencia faltante hover fondo',
            'default' => '#FBBF24',
            'description' => 'Color de fondo hover de los botones del caso donde falta saldo por completar',
        ],
        'theme_payment_difference_underpaid_button_hover_text' => [
            'label' => 'Diferencia faltante hover texto',
            'default' => '#111827',
            'description' => 'Color del texto hover de los botones del caso donde falta saldo por completar',
        ],
        'theme_payment_difference_overpaid_card_bg' => [
            'label' => 'Diferencia excedente tarjeta',
            'default' => '#064E3B',
            'description' => 'Color base de la tarjeta mostrada cuando queda saldo a favor en la diferencia de pagos',
        ],
        'theme_payment_difference_overpaid_text' => [
            'label' => 'Diferencia excedente texto',
            'default' => '#D1FAE5',
            'description' => 'Color del texto de la tarjeta cuando queda saldo a favor en la diferencia de pagos',
        ],
        'theme_payment_difference_overpaid_button_bg' => [
            'label' => 'Diferencia excedente botón fondo',
            'default' => '#10B981',
            'description' => 'Color de fondo de los botones del caso donde queda saldo a favor',
        ],
        'theme_payment_difference_overpaid_button_text' => [
            'label' => 'Diferencia excedente botón texto',
            'default' => '#052E16',
            'description' => 'Color del texto de los botones del caso donde queda saldo a favor',
        ],
        'theme_payment_difference_overpaid_button_hover_bg' => [
            'label' => 'Diferencia excedente hover fondo',
            'default' => '#34D399',
            'description' => 'Color de fondo hover de los botones del caso donde queda saldo a favor',
        ],
        'theme_payment_difference_overpaid_button_hover_text' => [
            'label' => 'Diferencia excedente hover texto',
            'default' => '#022C22',
            'description' => 'Color del texto hover de los botones del caso donde queda saldo a favor',
        ],
        'theme_float_whatsapp_bg' => [
            'label' => 'Flotante WhatsApp',
            'default' => '#22C55E',
            'description' => 'Color principal del botón flotante de WhatsApp',
        ],
        'theme_float_whatsapp_text' => [
            'label' => 'Texto WhatsApp',
            'default' => '#F8FAFC',
            'description' => 'Color del texto e icono del botón flotante de WhatsApp',
        ],
        'theme_float_channel_bg' => [
            'label' => 'Flotante canal',
            'default' => '#1F2937',
            'description' => 'Color principal del botón flotante del canal de difusión',
        ],
        'theme_float_channel_text' => [
            'label' => 'Texto canal',
            'default' => '#F8FAFC',
            'description' => 'Color del texto e icono del botón flotante del canal de difusión',
        ],
        'theme_startup_popup_surface' => [
            'label' => 'Ventana inicial fondo',
            'default' => '#140D0E',
            'description' => 'Color base del panel principal de la ventana inicial',
        ],
        'theme_startup_popup_border' => [
            'label' => 'Ventana inicial borde',
            'default' => '#3D1C1A',
            'description' => 'Color del borde y contornos de la ventana inicial',
        ],
        'theme_startup_popup_accent' => [
            'label' => 'Ventana inicial acento',
            'default' => '#25D366',
            'description' => 'Color del icono principal, resaltes y brillo de la ventana inicial',
        ],
        'theme_startup_popup_chip' => [
            'label' => 'Ventana inicial insignia',
            'default' => '#0E2B1B',
            'description' => 'Color de fondo para la insignia superior de la ventana inicial',
        ],
        'theme_startup_popup_button_text' => [
            'label' => 'Ventana inicial texto botón',
            'default' => '#F8FAFC',
            'description' => 'Color del texto e icono del botón principal de la ventana inicial',
        ],
        'theme_startup_video_popup_surface' => [
            'label' => 'Ventana video fondo',
            'default' => '#1A2233',
            'description' => 'Color base del panel principal de la ventana inicial con video',
        ],
        'theme_startup_video_popup_border' => [
            'label' => 'Ventana video borde',
            'default' => '#314462',
            'description' => 'Color del borde y contornos de la ventana inicial con video',
        ],
        'theme_startup_video_popup_accent' => [
            'label' => 'Ventana video acento',
            'default' => '#F87171',
            'description' => 'Color de detalles destacados y botón de cierre de la ventana inicial con video',
        ],
        'theme_startup_video_popup_button_bg' => [
            'label' => 'Ventana video botón',
            'default' => '#25D366',
            'description' => 'Color principal del botón del canal en la ventana inicial con video',
        ],
        'theme_startup_video_popup_button_text' => [
            'label' => 'Ventana video texto botón',
            'default' => '#F8FAFC',
            'description' => 'Color del texto e icono del botón principal de la ventana inicial con video',
        ],
        'theme_live_notification_bg' => [
            'label' => 'Notificación recarga fondo',
            'default' => '#0F172A',
            'description' => 'Color de fondo de la notificación flotante de recargas',
        ],
        'theme_live_notification_border' => [
            'label' => 'Notificación recarga borde',
            'default' => '#1D4ED8',
            'description' => 'Color del borde y brillo exterior de la notificación flotante de recargas',
        ],
        'theme_live_notification_accent' => [
            'label' => 'Notificación recarga acento',
            'default' => '#22D3EE',
            'description' => 'Color del acento, punto activo y detalles destacados de la notificación de recargas',
        ],
        'theme_live_notification_text' => [
            'label' => 'Notificación recarga texto',
            'default' => '#F8FAFC',
            'description' => 'Color del texto principal de la notificación flotante de recargas',
        ],
        'theme_live_notification_muted' => [
            'label' => 'Notificación recarga texto secundario',
            'default' => '#BFDBFE',
            'description' => 'Color del texto secundario y complementario de la notificación flotante de recargas',
        ],
    ];
}

function store_theme_custom_key(string $baseKey): string {
    if (str_starts_with($baseKey, 'theme_')) {
        return 'theme_custom_' . substr($baseKey, 6);
    }

    return 'theme_custom_' . $baseKey;
}

function store_theme_custom_description(string $baseDescription): string {
    return 'Copia editable: ' . $baseDescription;
}

function store_config_descriptions(): array {
    $descriptions = [
        'correo_corporativo' => 'Correo usado para notificaciones',
        'smtp_host' => 'Host SMTP para envío de correos',
        'smtp_user' => 'Usuario SMTP',
        'smtp_pass' => 'Contraseña SMTP',
        'smtp_port' => 'Puerto SMTP',
        'smtp_secure' => 'Tipo de seguridad SMTP',
        'nombre_prefijo' => 'Texto superior del encabezado de la tienda',
        'nombre_tienda' => 'Nombre principal visible de la tienda',
        'nombre_tienda_subtitulo' => 'Texto complementario usado en el título del inicio y en la instalación de la app',
        'meta_titulo' => 'Título SEO usado en la etiqueta title y en etiquetas Open Graph/Twitter',
        'meta_descripcion' => 'Descripción SEO usada en la etiqueta meta description para Google y redes sociales',
        'logo_tienda' => 'Ruta del logo visible en el encabezado',
        'fondo_animado' => 'Activa o desactiva globalmente la sección de fondo multimedia fijo para el sitio público.',
        'filtros_influencer' => 'Activa o desactiva los filtros avanzados y tarjetas resumen del módulo Cupones de Influencers.',
        'cupon_x_juegos' => 'Activa o desactiva la restricción de cupones por juegos específicos desde el panel administrativo.',
        'guardar_ultimo_id' => 'Activa o desactiva el guardado y reutilización del último identificador de jugador usado por el cliente en sus compras.',
        'diferencia_pago' => 'Activa o desactiva el flujo de diferencia de pago para montos transferidos por encima o por debajo del total del pedido.',
        'fondo_publico_modo' => 'Define si el sitio público usa el fondo normal del tema o un fondo multimedia fijo.',
        'fondo_publico_media' => 'Ruta del archivo multimedia fijo usado como fondo del sitio público.',
        'fondo_publico_overlay_color' => 'Color de la capa superpuesta colocada sobre el fondo multimedia del sitio público.',
        'fondo_publico_overlay_opacity' => 'Nivel de opacidad de la capa superpuesta sobre el fondo multimedia del sitio público.',
        'fondo_publico_audio_activo' => 'Activa o desactiva el audio del video de fondo en el sitio público.',
        'fondo_publico_volumen' => 'Nivel de volumen del video de fondo del sitio público expresado entre 0 y 100.',
        'encabezado_pago' => 'Activa el resumen minimalista con icono, precio y caracteristicas dentro del modal de pago.',
        'ventana_pago_config' => 'Activa la personalización avanzada de colores y textos para la ventana de pago, incluyendo estados previos y posteriores a la compra.',
        'ventana_pago_enviando_titulo' => 'Texto principal que se muestra en el modal Enviando orden.',
        'ventana_pago_enviando_mensaje' => 'Texto explicativo que se muestra debajo del título en el modal Enviando orden.',
        'ventana_pago_exitoso_titulo' => 'Texto principal que se muestra en el modal final cuando el pago o la recarga fueron exitosos.',
        'ventana_pago_exitoso_mensaje_extra' => 'Texto adicional que se muestra debajo del mensaje principal cuando el modal final indica un pago exitoso.',
        'instrucciones_influencer' => 'Activa o desactiva el modulo publico y administrativo de Instrucciones Influencer',
        'google_analytics_activo' => 'Activa o desactiva la inserción del script de Google Analytics o Google Tag en el footer público',
        'google_analytics_script' => 'Código script completo de Google Analytics o Google Tag que se inserta en el footer público',
        'notificaciones_recargas' => 'Activa o desactiva por tenant las notificaciones de recargas en la tienda',
        'verificacion_nombre_api' => 'Activa o desactiva por tenant la verificación de nombres de jugador mediante API',
        'recarga_notificaciones_activas' => 'Activa o desactiva las notificaciones flotantes de recargas en el sitio público',
        'recarga_notificaciones_logo' => 'Ruta del logo usado en la notificación flotante de recargas',
        'facebook' => 'URL de Facebook de la tienda',
        'instagram' => 'URL de Instagram de la tienda',
        'tiktok' => 'URL de TikTok de la tienda',
        'whatsapp' => 'Número o enlace de WhatsApp de la tienda',
        'mensaje_whatsapp' => 'Mensaje predefinido para el botón flotante de WhatsApp',
            'whatsapp_flotante_activo' => 'Activa o desactiva el botón flotante de WhatsApp en el sitio público',
        'whatsapp_channel' => 'URL del canal de WhatsApp de la tienda',
            'whatsapp_channel_flotante_activo' => 'Activa o desactiva el botón flotante del canal de WhatsApp en el sitio público',
        'google_client_id' => 'Client ID de Google para login y registro social',
        'google_client_secret' => 'Client Secret de Google para login y registro social',
        'win_points' => 'Activa o desactiva globalmente el sistema de premios por recarga.',
        'win_points_name' => 'Nombre visible de la moneda de premios usada en el sitio y paneles.',
        'win_points_icon' => 'Ruta del icono usado para representar la moneda de premios.',
        'win_points_payment_image' => 'Ruta de la imagen horizontal usada para representar Win Points como metodo de pago en la pagina de paquetes.',
        'win_points_payment_corner_image' => 'Ruta de la imagen promocional flotante usada en la esquina de la card publica de Win Points.',
        'win_points_notification_position' => 'Posicion de la notificacion flotante de Win Points en la pagina publica.',
        'win_points_default_award' => 'Cantidad de puntos que se asigna por defecto a un paquete cuando no tiene un valor propio configurado.',
        'inicio_popup_tab_habilitado' => 'Activa o desactiva globalmente el tab y la función de la ventana inicial',
        'inicio_popup_activo' => 'Activa o desactiva la disponibilidad de la ventana inicial normal en el index',
        'inicio_popup_video_activo' => 'Activa o desactiva la disponibilidad de la ventana inicial con video en el index',
        'inicio_popup_galeria' => 'Activa o desactiva la disponibilidad de la ventana inicial de galería en el index',
        'inicio_popup_modo' => 'Modo seleccionado para la ventana inicial del index',
        'inicio_popup_frecuencia' => 'Frecuencia con la que debe aparecer la ventana inicial en el index',
        'inicio_popup_nombre_canal' => 'Nombre visible del canal en la ventana inicial',
        'inicio_popup_video_url' => 'Enlace de YouTube usado en la ventana inicial con video',
        'inicio_popup_galeria_imagenes' => 'Listado JSON de imágenes usadas por la ventana inicial de galería',
        'ff_bank_api_base_url' => 'Enlace base de la API del banco para consultar movimientos',
        'ff_bank_dias_disponibles' => 'Dias disponibles reportados por la API del banco al consultar movimientos',
        'ff_bank_posicion' => 'Posicion para la conexion al banco de Free Fire',
        'ff_bank_token' => 'Token para la conexion al banco de Free Fire',
        'ff_bank_clave' => 'Clave para la conexion al banco de Free Fire',
        'ff_api_usuario' => 'Usuario para la API de Free Fire',
        'ff_api_clave' => 'Clave para la API de Free Fire',
        'ff_api_tipo' => 'Tipo para la API de Free Fire',
        'cantidad_paquetes' => 'Activa o desactiva la venta de paquetes por cantidad dentro del checkout público del tenant.',
        'vender_cuentas' => 'Activa o desactiva la venta de cuentas en lugar de recargas para los paquetes marcados con esa modalidad.',
        'barra_superior' => 'Activa o desactiva la barra superior fija con buscador y reubicación del acceso principal del sitio público.',
        'api_binance' => 'Activa o desactiva la configuracion e integracion de Binance Pay via CoinPal para este tenant.',
        'api_binance_pagonorte' => 'Activa o desactiva la verificación de movimientos Binance para este tenant.',
        'api_binance_usuario' => 'Activa o desactiva el uso visible de Binance Pay para clientes y procesos automaticos de la tienda cuando el tenant ya tiene disponible api_binance.',
        'pago_paypal' => 'Activa o desactiva la configuración base de PayPal Checkout para este tenant.',
        'descuento_metodo_pago' => 'Activa o desactiva los descuentos porcentuales por método de pago dentro del checkout público.',
        'api_discord' => 'Activa o desactiva la integración base de API Discord para enviar comandos de consulta y pruebas de recarga desde este tenant.',
        'api_discord_webhook_url' => 'Webhook de Discord usado para enviar mensajes de prueba y comandos controlados al canal configurado.',
        'api_discord_timeout' => 'Tiempo máximo en segundos que la sonda webhook de API Discord esperará por la respuesta HTTP de Discord.',
        'api_discord_username' => 'Nombre visible opcional con el que se publicarán los mensajes enviados por el webhook de API Discord.',
        'api_discord_avatar_url' => 'Avatar opcional usado por el webhook de API Discord al publicar mensajes de prueba o sondeo.',
        'api_discord_dry_run' => 'Activa el modo preventivo para usar primero comandos seguros de precio y pruebas antes de habilitar comandos de recarga reales.',
        'api_discord_probe_command' => 'Clave del comando seguro por defecto usada para la prueba webhook de API Discord desde el panel administrativo.',
        'api_discord_listener_token' => 'Token secreto usado por el listener de API Discord para aceptar correlaciones externas de mensajes y actualizar el estado de las ordenes.',
        'binance_pay_merchant_no' => 'Merchant No configurado para Binance Pay via CoinPal.',
        'binance_pay_secret_key' => 'Secret Key configurada para Binance Pay via CoinPal.',
        'binance_pay_store_id' => 'Store ID registrado en CoinPal para esta tienda.',
        'binance_pay_access_token' => 'Access Token del Store registrado en CoinPal para esta tienda.',
        'binance_pay_store_url' => 'URL o dominio registrado en CoinPal My Store para esta tienda.',
        'binance_pay_image' => 'Ruta de la imagen horizontal usada para representar Binance Pay como metodo de pago en la pagina de paquetes.',
        'binance_pay_corner_image' => 'Ruta de la imagen promocional flotante usada en la esquina de la card publica de Binance Pay.',
        'binance_pay_descuento' => 'Porcentaje de descuento aplicado cuando el cliente paga usando Binance Pay.',
        'binance_pagonorte_token' => 'Token usado para consultar movimientos y saldo en la API de Binance.',
        'binance_pagonorte_dias_disponibles' => 'Dias disponibles reportados por la API de Binance al consultar movimientos.',
        'binance_pagonorte_fecha_corte' => 'Fecha de corte reportada por la API de Binance al consultar el saldo.',
        'binance_pagonorte_activo' => 'Activa o desactiva la visibilidad pública del método Binance dentro del checkout.',
        'binance_pagonorte_image' => 'Ruta de la imagen horizontal usada para representar Binance como metodo de pago en la pagina de paquetes.',
        'binance_pagonorte_corner_image' => 'Ruta de la imagen promocional flotante usada en la esquina de la card publica de Binance.',
        'binance_pagonorte_qr_image' => 'Ruta de la imagen QR mostrada dentro del modal de pago de Binance.',
        'binance_pagonorte_datos' => 'Datos de transferencia mostrados al cliente cuando usa Binance.',
        'binance_pagonorte_descuento' => 'Porcentaje de descuento aplicado cuando se use la verificación de Binance.',
        'binance_pagonorte_referencia_digitos' => 'Cantidad de digitos finales de la referencia que se compararán contra los movimientos de Binance. Si vale 0, se exige la referencia completa.',
        'paypal_environment' => 'Entorno de PayPal usado por la tienda. Puede ser sandbox para pruebas o live para cobros reales.',
        'paypal_activo' => 'Activa o desactiva la visibilidad pública de PayPal dentro de los métodos de pago de los juegos, sin ocultar este tab administrativo.',
        'paypal_client_id' => 'Client ID de la app REST creada en PayPal Developer Dashboard.',
        'paypal_client_secret' => 'Client Secret de la app REST creada en PayPal Developer Dashboard.',
        'paypal_webhook_id' => 'Webhook ID entregado por PayPal para validar notificaciones firmadas del checkout.',
        'paypal_brand_name' => 'Nombre comercial que PayPal mostrará al cliente durante el checkout.',
        'paypal_impuesto' => 'Porcentaje de impuesto adicional aplicado cuando el cliente paga usando PayPal.',
        'paypal_image' => 'Ruta de la imagen horizontal usada para representar PayPal como metodo de pago en la pagina de paquetes.',
        'paypal_corner_image' => 'Ruta de la imagen promocional flotante usada en la esquina de la card publica de PayPal.',
        'paypal_qr_image' => 'Ruta del QR informativo o promocional mostrado dentro del modal de pago de PayPal.',
    ];

    foreach (store_theme_definitions() as $key => $definition) {
        $description = (string) ($definition['description'] ?? 'Color del tema visual');
        $descriptions[$key] = $description;
        $descriptions[store_theme_custom_key($key)] = store_theme_custom_description($description);
    }

    return $descriptions;
}

function store_config_defaults(): array {
    $defaults = [
        'correo_corporativo' => '',
        'smtp_host' => '',
        'smtp_user' => '',
        'smtp_pass' => '',
        'smtp_port' => '587',
        'smtp_secure' => 'tls',
        'nombre_prefijo' => 'TIENDA',
        'nombre_tienda' => 'TVirtualGaming',
        'nombre_tienda_subtitulo' => 'Tienda de monedas digitales',
        'meta_titulo' => 'TVirtualGaming | Tienda de monedas digitales',
        'meta_descripcion' => 'Compra monedas y recargas digitales en TVirtualGaming. Recibe ofertas, promociones y novedades directamente en tu WhatsApp.',
        'logo_tienda' => '',
        'fondo_animado' => '0',
        'filtros_influencer' => '0',
        'cupon_x_juegos' => '0',
        'guardar_ultimo_id' => '0',
        'diferencia_pago' => '0',
        'fondo_publico_modo' => 'normal',
        'fondo_publico_media' => '',
        'fondo_publico_overlay_color' => '#081018',
        'fondo_publico_overlay_opacity' => '52',
        'fondo_publico_audio_activo' => '0',
        'fondo_publico_volumen' => '35',
        'encabezado_pago' => '0',
        'ventana_pago_config' => '0',
        'ventana_pago_enviando_titulo' => 'Enviando orden...',
        'ventana_pago_enviando_mensaje' => 'Estamos registrando tu comprobante y procesando la orden según la moneda del pedido. No cierres esta ventana.',
        'ventana_pago_exitoso_titulo' => 'Pago exitoso',
        'ventana_pago_exitoso_mensaje_extra' => '',
        'instrucciones_influencer' => '0',
        'google_analytics_activo' => '0',
        'google_analytics_script' => '',
        'notificaciones_recargas' => '0',
        'verificacion_nombre_api' => '0',
        'recarga_notificaciones_activas' => '1',
        'recarga_notificaciones_logo' => '',
        'facebook' => '',
        'instagram' => '',
        'tiktok' => '',
        'whatsapp' => '',
        'mensaje_whatsapp' => '',
            'whatsapp_flotante_activo' => '1',
        'whatsapp_channel' => '',
            'whatsapp_channel_flotante_activo' => '1',
        'google_client_id' => '',
        'google_client_secret' => '',
        'win_points' => '0',
        'win_points_name' => 'Win Points',
        'win_points_icon' => '',
        'win_points_payment_image' => '',
        'win_points_payment_corner_image' => '',
        'win_points_notification_position' => 'bottom-left',
        'win_points_default_award' => '0',
        'inicio_popup_tab_habilitado' => '1',
        'inicio_popup_activo' => '1',
        'inicio_popup_video_activo' => '0',
        'inicio_popup_galeria' => '0',
        'inicio_popup_modo' => '',
        'inicio_popup_frecuencia' => 'per_session',
        'inicio_popup_nombre_canal' => 'DanisA Gamer Store',
        'inicio_popup_video_url' => '',
        'inicio_popup_galeria_imagenes' => '[]',
        'ff_bank_api_base_url' => 'https://pagonorte.net',
        'ff_bank_dias_disponibles' => '',
        'ff_api_usuario' => '',
        'ff_api_clave' => '',
        'ff_api_tipo' => 'recargaFreefire',
        'cantidad_paquetes' => '0',
        'vender_cuentas' => '0',
        'barra_superior' => '0',
        'api_binance' => '0',
        'api_binance_pagonorte' => '0',
        'api_binance_usuario' => '1',
        'pago_paypal' => '0',
        'descuento_metodo_pago' => '0',
        'api_discord' => '0',
        'api_discord_webhook_url' => '',
        'api_discord_timeout' => '10',
        'api_discord_username' => 'VirtualGaming API',
        'api_discord_avatar_url' => '',
        'api_discord_dry_run' => '1',
        'api_discord_probe_command' => 'mobile_legends_price',
        'api_discord_listener_token' => '',
        'binance_pay_merchant_no' => '',
        'binance_pay_secret_key' => '',
        'binance_pay_store_id' => '',
        'binance_pay_access_token' => '',
        'binance_pay_store_url' => '',
        'binance_pay_image' => '',
        'binance_pay_corner_image' => '',
        'binance_pay_descuento' => '0',
        'binance_pagonorte_token' => '',
        'binance_pagonorte_dias_disponibles' => '',
        'binance_pagonorte_fecha_corte' => '',
        'binance_pagonorte_activo' => '0',
        'binance_pagonorte_image' => '',
        'binance_pagonorte_corner_image' => '',
        'binance_pagonorte_qr_image' => '',
        'binance_pagonorte_datos' => '',
        'binance_pagonorte_descuento' => '0',
        'binance_pagonorte_referencia_digitos' => '0',
        'paypal_environment' => 'sandbox',
        'paypal_activo' => '1',
        'paypal_client_id' => '',
        'paypal_client_secret' => '',
        'paypal_webhook_id' => '',
        'paypal_brand_name' => '',
        'paypal_impuesto' => '0',
        'paypal_image' => '',
        'paypal_corner_image' => '',
        'paypal_qr_image' => '',
    ];

    foreach (store_theme_definitions() as $key => $definition) {
        $defaultValue = (string) ($definition['default'] ?? '#000000');
        $defaults[$key] = $defaultValue;
        $defaults[store_theme_custom_key($key)] = $defaultValue;
    }

    return $defaults;
}

function store_config_normalize_hex_color(string $value, string $fallback = '#000000'): string {
    $normalized = strtoupper(trim($value));
    if ($normalized === '') {
        return strtoupper($fallback);
    }

    if ($normalized[0] !== '#') {
        $normalized = '#' . $normalized;
    }

    if (!preg_match('/^#([A-F0-9]{3}|[A-F0-9]{6})$/', $normalized)) {
        return strtoupper($fallback);
    }

    if (strlen($normalized) === 4) {
        return '#'
            . $normalized[1] . $normalized[1]
            . $normalized[2] . $normalized[2]
            . $normalized[3] . $normalized[3];
    }

    return $normalized;
}

function store_config_normalize_public_background_mode(string $value): string {
    return trim(strtolower($value)) === 'media' ? 'media' : 'normal';
}

function store_config_public_background_enabled(bool $refresh = false): bool {
    $config = store_config_all($refresh);
    return trim((string) ($config['fondo_animado'] ?? '0')) === '1';
}

function store_config_normalize_percentage($value, int $default = 0): int {
    if ($value === null || $value === '') {
        return max(0, min(100, $default));
    }

    if (!is_numeric($value)) {
        return max(0, min(100, $default));
    }

    return max(0, min(100, (int) round((float) $value)));
}

function store_config_public_background_media_type_from_path(string $path): string {
    $cleanPath = (string) (parse_url($path, PHP_URL_PATH) ?: $path);
    $extension = strtolower(pathinfo($cleanPath, PATHINFO_EXTENSION));

    if (in_array($extension, ['mp4', 'webm', 'ogg'], true)) {
        return 'video';
    }

    if (in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
        return 'image';
    }

    return '';
}

function store_config_public_background_settings(bool $refresh = false): array {
    $config = store_config_all($refresh);
    $isEnabled = store_config_public_background_enabled($refresh);
    $mode = store_config_normalize_public_background_mode((string) ($config['fondo_publico_modo'] ?? 'normal'));
    $assetPath = trim((string) ($config['fondo_publico_media'] ?? ''));
    $mediaType = store_config_public_background_media_type_from_path($assetPath);
    $soundEnabled = ((string) ($config['fondo_publico_audio_activo'] ?? '0')) === '1';
    $volume = store_config_normalize_percentage($config['fondo_publico_volumen'] ?? '35', 35);

    if (!$isEnabled) {
        $mode = 'normal';
        $assetPath = '';
        $mediaType = '';
        $soundEnabled = false;
        $volume = 0;
    }

    return [
        'enabled' => $isEnabled,
        'mode' => $mode,
        'asset_path' => $assetPath,
        'has_media' => $assetPath !== '' && $mediaType !== '',
        'media_type' => $mediaType,
        'overlay_color' => store_config_normalize_hex_color((string) ($config['fondo_publico_overlay_color'] ?? '#081018'), '#081018'),
        'overlay_opacity' => store_config_normalize_percentage($config['fondo_publico_overlay_opacity'] ?? '52', 52),
        'sound_enabled' => $soundEnabled,
        'volume' => $volume,
        'volume_ratio' => max(0, min(1, $volume / 100)),
    ];
}

function store_theme_base_values(bool $refresh = false): array {
    $config = store_config_all($refresh);
    $values = [];

    foreach (store_theme_definitions() as $key => $definition) {
        $values[$key] = store_config_normalize_hex_color(
            (string) ($config[$key] ?? ''),
            (string) ($definition['default'] ?? '#000000')
        );
    }

    return $values;
}

function store_theme_values(bool $refresh = false): array {
    $config = store_config_all($refresh);
    $baseValues = store_theme_base_values($refresh);
    $values = [];

    foreach (store_theme_definitions() as $key => $definition) {
        $customKey = store_theme_custom_key($key);
        $values[$key] = store_config_normalize_hex_color(
            (string) ($config[$customKey] ?? ''),
            $baseValues[$key] ?? (string) ($definition['default'] ?? '#000000')
        );
    }

    return $values;
}

function store_theme_validate_payload(array $input): array {
    $data = [];
    $errors = [];

    foreach (store_theme_definitions() as $key => $definition) {
        $rawValue = trim((string) ($input[$key] ?? ''));
        if ($rawValue === '') {
            $errors[] = 'Debes indicar un color para ' . strtolower((string) ($definition['label'] ?? $key)) . '.';
            continue;
        }

        $normalized = store_config_normalize_hex_color($rawValue, '');
        if ($normalized === '') {
            $errors[] = 'El color de ' . strtolower((string) ($definition['label'] ?? $key)) . ' no es válido. Usa formato hexadecimal, por ejemplo: #22D3EE.';
            continue;
        }

        $data[$key] = $normalized;
    }

    return [
        'is_valid' => empty($errors),
        'errors' => $errors,
        'data' => $data,
    ];
}

function store_theme_save_values(array $values): bool {
    $descriptions = store_config_descriptions();

    foreach (store_theme_definitions() as $baseKey => $definition) {
        if (!array_key_exists($baseKey, $values)) {
            continue;
        }

        $customKey = store_theme_custom_key($baseKey);
        $description = $descriptions[$customKey] ?? store_theme_custom_description((string) ($definition['description'] ?? 'Color del tema visual'));
        if (!store_config_upsert($customKey, (string) $values[$baseKey], $description)) {
            return false;
        }
    }

    return true;
}

function store_theme_restore_defaults(): bool {
    return store_theme_save_values(store_theme_base_values(true));
}

function store_theme_hex_to_rgb(string $hex): array {
    $normalized = store_config_normalize_hex_color($hex, '#000000');
    return [
        hexdec(substr($normalized, 1, 2)),
        hexdec(substr($normalized, 3, 2)),
        hexdec(substr($normalized, 5, 2)),
    ];
}

function store_theme_rgb_string(string $hex): string {
    $rgb = store_theme_hex_to_rgb($hex);
    return implode(', ', $rgb);
}

function store_theme_rgba(string $hex, float $alpha): string {
    $rgb = store_theme_hex_to_rgb($hex);
    $safeAlpha = max(0, min(1, $alpha));
    return 'rgba(' . implode(', ', $rgb) . ', ' . rtrim(rtrim(number_format($safeAlpha, 2, '.', ''), '0'), '.') . ')';
}

function store_theme_mix(string $baseHex, string $mixHex, float $ratio): string {
    $base = store_theme_hex_to_rgb($baseHex);
    $mix = store_theme_hex_to_rgb($mixHex);
    $weight = max(0, min(1, $ratio));
    $channels = [];

    foreach ([0, 1, 2] as $index) {
        $channels[$index] = (int) round(($base[$index] * (1 - $weight)) + ($mix[$index] * $weight));
    }

    return sprintf('#%02X%02X%02X', $channels[0], $channels[1], $channels[2]);
}

function store_theme_contrast_text(string $backgroundHex): string {
    [$red, $green, $blue] = store_theme_hex_to_rgb($backgroundHex);
    $luminance = ((0.299 * $red) + (0.587 * $green) + (0.114 * $blue)) / 255;
    return $luminance > 0.6 ? '#081018' : '#F8FAFC';
}

function store_theme_css_variables(): string {
    $theme = store_theme_values();
    $bodyGlow = store_theme_mix($theme['theme_bg_alt'], '#123247', 0.18);
    $bodyDeep = store_theme_mix($theme['theme_bg_main'], '#000000', 0.28);
    $panelGlow = store_theme_mix($theme['theme_primary'], $theme['theme_secondary'], 0.25);
    $panelBg = store_theme_rgba($theme['theme_bg_alt'], 0.97);
    $panelGradient = 'linear-gradient(135deg, ' . store_theme_rgba($theme['theme_bg_alt'], 0.98) . ' 80%, ' . store_theme_rgba($theme['theme_primary'], 0.08) . ' 100%)';
    $overlayStrong = store_theme_rgba('#0C1522', 0.7);
    $overlaySoft = store_theme_rgba('#0C1522', 0.86);
    $primarySoft = store_theme_rgba($theme['theme_primary'], 0.15);
    $primaryGlow = store_theme_rgba($theme['theme_primary'], 0.22);
    $bgElevated = store_theme_rgba('#081018', 0.82);
    $buttonPrimaryMix = store_theme_mix($theme['theme_button_primary'], $theme['theme_button_secondary'], 0.5);
    $buttonSecondaryMix = store_theme_mix($theme['theme_button_secondary'], $theme['theme_button_primary'], 0.5);
    $buttonSurfaceGlow = store_theme_mix($theme['theme_button_surface'], $theme['theme_button_primary'], 0.18);
    $buttonSurfaceBorder = store_theme_mix($theme['theme_button_primary'], $theme['theme_button_secondary'], 0.35);

    $variables = [
        '--theme-bg-main' => $theme['theme_bg_main'],
        '--theme-bg-alt' => $theme['theme_bg_alt'],
        '--theme-bg-deep' => $bodyDeep,
        '--theme-surface' => $theme['theme_surface'],
        '--theme-surface-alt' => $theme['theme_surface_alt'],
        '--theme-primary' => $theme['theme_primary'],
        '--theme-highlight' => $theme['theme_highlight'],
        '--theme-secondary' => $theme['theme_secondary'],
        '--theme-success' => $theme['theme_success'],
        '--theme-warning' => $theme['theme_warning'],
        '--theme-danger' => $theme['theme_danger'],
        '--theme-text' => $theme['theme_text'],
        '--theme-text-muted' => $theme['theme_text_muted'],
        '--theme-price-text' => $theme['theme_price_text'],
        '--theme-price-muted' => $theme['theme_price_muted'],
        '--theme-border' => $theme['theme_border'],
        '--theme-button-primary' => $theme['theme_button_primary'],
        '--theme-button-secondary' => $theme['theme_button_secondary'],
        '--theme-button-surface' => $theme['theme_button_surface'],
        '--theme-game-feature-bg' => $theme['theme_game_feature_bg'],
        '--theme-game-feature-border' => $theme['theme_game_feature_border'],
        '--theme-game-feature-text' => $theme['theme_game_feature_text'],
        '--theme-package-feature-bg' => $theme['theme_package_feature_bg'],
        '--theme-package-feature-border' => $theme['theme_package_feature_border'],
        '--theme-package-feature-text' => $theme['theme_package_feature_text'],
        '--theme-float-whatsapp-bg' => $theme['theme_float_whatsapp_bg'],
        '--theme-float-whatsapp-text' => $theme['theme_float_whatsapp_text'],
        '--theme-float-channel-bg' => $theme['theme_float_channel_bg'],
        '--theme-float-channel-text' => $theme['theme_float_channel_text'],
        '--theme-startup-popup-surface' => $theme['theme_startup_popup_surface'],
        '--theme-startup-popup-border' => $theme['theme_startup_popup_border'],
        '--theme-startup-popup-accent' => $theme['theme_startup_popup_accent'],
        '--theme-startup-popup-chip' => $theme['theme_startup_popup_chip'],
        '--theme-startup-popup-button-text' => $theme['theme_startup_popup_button_text'],
        '--theme-startup-video-popup-surface' => $theme['theme_startup_video_popup_surface'],
        '--theme-startup-video-popup-border' => $theme['theme_startup_video_popup_border'],
        '--theme-startup-video-popup-accent' => $theme['theme_startup_video_popup_accent'],
        '--theme-startup-video-popup-button-bg' => $theme['theme_startup_video_popup_button_bg'],
        '--theme-startup-video-popup-button-text' => $theme['theme_startup_video_popup_button_text'],
        '--theme-live-notification-bg' => $theme['theme_live_notification_bg'],
        '--theme-live-notification-border' => $theme['theme_live_notification_border'],
        '--theme-live-notification-accent' => $theme['theme_live_notification_accent'],
        '--theme-live-notification-text' => $theme['theme_live_notification_text'],
        '--theme-live-notification-muted' => $theme['theme_live_notification_muted'],
        '--theme-body-glow' => $bodyGlow,
        '--theme-panel-glow' => $panelGlow,
        '--theme-panel-bg' => $panelBg,
        '--theme-panel-gradient' => $panelGradient,
        '--theme-overlay-strong' => $overlayStrong,
        '--theme-overlay-soft' => $overlaySoft,
        '--theme-primary-soft' => $primarySoft,
        '--theme-primary-glow' => $primaryGlow,
        '--theme-bg-elevated' => $bgElevated,
        '--theme-button-surface-glow' => $buttonSurfaceGlow,
        '--theme-button-surface-border' => $buttonSurfaceBorder,
        '--theme-shadow-primary' => '0 0 32px ' . store_theme_rgba($theme['theme_primary'], 0.95),
        '--theme-shadow-secondary' => '0 0 8px ' . store_theme_rgba($theme['theme_secondary'], 0.9),
        '--theme-primary-rgb' => store_theme_rgb_string($theme['theme_primary']),
        '--theme-highlight-rgb' => store_theme_rgb_string($theme['theme_highlight']),
        '--theme-secondary-rgb' => store_theme_rgb_string($theme['theme_secondary']),
        '--theme-button-primary-rgb' => store_theme_rgb_string($theme['theme_button_primary']),
        '--theme-button-secondary-rgb' => store_theme_rgb_string($theme['theme_button_secondary']),
        '--theme-button-surface-rgb' => store_theme_rgb_string($theme['theme_button_surface']),
        '--theme-game-feature-bg-rgb' => store_theme_rgb_string($theme['theme_game_feature_bg']),
        '--theme-game-feature-border-rgb' => store_theme_rgb_string($theme['theme_game_feature_border']),
        '--theme-game-feature-text-rgb' => store_theme_rgb_string($theme['theme_game_feature_text']),
        '--theme-package-feature-bg-rgb' => store_theme_rgb_string($theme['theme_package_feature_bg']),
        '--theme-package-feature-border-rgb' => store_theme_rgb_string($theme['theme_package_feature_border']),
        '--theme-package-feature-text-rgb' => store_theme_rgb_string($theme['theme_package_feature_text']),
        '--theme-float-whatsapp-bg-rgb' => store_theme_rgb_string($theme['theme_float_whatsapp_bg']),
        '--theme-float-whatsapp-text-rgb' => store_theme_rgb_string($theme['theme_float_whatsapp_text']),
        '--theme-float-channel-bg-rgb' => store_theme_rgb_string($theme['theme_float_channel_bg']),
        '--theme-float-channel-text-rgb' => store_theme_rgb_string($theme['theme_float_channel_text']),
        '--theme-startup-popup-surface-rgb' => store_theme_rgb_string($theme['theme_startup_popup_surface']),
        '--theme-startup-popup-border-rgb' => store_theme_rgb_string($theme['theme_startup_popup_border']),
        '--theme-startup-popup-accent-rgb' => store_theme_rgb_string($theme['theme_startup_popup_accent']),
        '--theme-startup-popup-chip-rgb' => store_theme_rgb_string($theme['theme_startup_popup_chip']),
        '--theme-startup-popup-button-text-rgb' => store_theme_rgb_string($theme['theme_startup_popup_button_text']),
        '--theme-startup-video-popup-surface-rgb' => store_theme_rgb_string($theme['theme_startup_video_popup_surface']),
        '--theme-startup-video-popup-border-rgb' => store_theme_rgb_string($theme['theme_startup_video_popup_border']),
        '--theme-startup-video-popup-accent-rgb' => store_theme_rgb_string($theme['theme_startup_video_popup_accent']),
        '--theme-startup-video-popup-button-bg-rgb' => store_theme_rgb_string($theme['theme_startup_video_popup_button_bg']),
        '--theme-startup-video-popup-button-text-rgb' => store_theme_rgb_string($theme['theme_startup_video_popup_button_text']),
        '--theme-live-notification-bg-rgb' => store_theme_rgb_string($theme['theme_live_notification_bg']),
        '--theme-live-notification-border-rgb' => store_theme_rgb_string($theme['theme_live_notification_border']),
        '--theme-live-notification-accent-rgb' => store_theme_rgb_string($theme['theme_live_notification_accent']),
        '--theme-live-notification-text-rgb' => store_theme_rgb_string($theme['theme_live_notification_text']),
        '--theme-live-notification-muted-rgb' => store_theme_rgb_string($theme['theme_live_notification_muted']),
        '--theme-success-rgb' => store_theme_rgb_string($theme['theme_success']),
        '--theme-warning-rgb' => store_theme_rgb_string($theme['theme_warning']),
        '--theme-danger-rgb' => store_theme_rgb_string($theme['theme_danger']),
        '--theme-text-rgb' => store_theme_rgb_string($theme['theme_text']),
        '--theme-text-muted-rgb' => store_theme_rgb_string($theme['theme_text_muted']),
        '--theme-price-text-rgb' => store_theme_rgb_string($theme['theme_price_text']),
        '--theme-price-muted-rgb' => store_theme_rgb_string($theme['theme_price_muted']),
        '--theme-border-rgb' => store_theme_rgb_string($theme['theme_border']),
        '--theme-bg-main-rgb' => store_theme_rgb_string($theme['theme_bg_main']),
        '--theme-bg-alt-rgb' => store_theme_rgb_string($theme['theme_bg_alt']),
        '--theme-surface-rgb' => store_theme_rgb_string($theme['theme_surface']),
        '--theme-surface-alt-rgb' => store_theme_rgb_string($theme['theme_surface_alt']),
        '--theme-button-text' => store_theme_contrast_text($buttonSecondaryMix),
        '--theme-button-text-strong' => store_theme_contrast_text($buttonPrimaryMix),
        '--theme-button-surface-text' => store_theme_contrast_text($theme['theme_button_surface']),
        '--theme-success-text' => store_theme_contrast_text($theme['theme_success']),
        '--theme-danger-text' => store_theme_contrast_text($theme['theme_danger']),
        '--bs-body-bg' => $theme['theme_bg_main'],
        '--bs-body-color' => $theme['theme_text'],
        '--bs-dark' => $theme['theme_surface'],
        '--bs-dark-rgb' => store_theme_rgb_string($theme['theme_surface']),
        '--bs-info' => $theme['theme_primary'],
        '--bs-info-rgb' => store_theme_rgb_string($theme['theme_primary']),
        '--bs-success' => $theme['theme_success'],
        '--bs-success-rgb' => store_theme_rgb_string($theme['theme_success']),
        '--bs-warning' => $theme['theme_warning'],
        '--bs-warning-rgb' => store_theme_rgb_string($theme['theme_warning']),
        '--bs-danger' => $theme['theme_danger'],
        '--bs-danger-rgb' => store_theme_rgb_string($theme['theme_danger']),
        '--bs-secondary-color' => $theme['theme_text_muted'],
        '--bs-secondary-color-rgb' => store_theme_rgb_string($theme['theme_text_muted']),
        '--bs-border-color' => store_theme_rgba($theme['theme_border'], 0.68),
        '--bs-border-color-translucent' => store_theme_rgba($theme['theme_border'], 0.28),
        '--bs-heading-color' => $theme['theme_text'],
        '--bs-emphasis-color' => $theme['theme_text'],
        '--bs-link-color' => $theme['theme_primary'],
        '--bs-link-hover-color' => $theme['theme_highlight'],
    ];

    foreach ($theme as $key => $value) {
        if (!str_starts_with($key, 'theme_')) {
            continue;
        }

        $cssBaseName = str_replace('_', '-', substr($key, 6));
        $variables['--theme-' . $cssBaseName] = $value;
        $variables['--theme-' . $cssBaseName . '-rgb'] = store_theme_rgb_string($value);
    }

    $lines = [];
    foreach ($variables as $name => $value) {
        $lines[] = '      ' . $name . ': ' . $value . ';';
    }

    return implode("\n", $lines);
}

function store_config_db(): mysqli {
    global $mysqli;

    if (!isset($mysqli) || !($mysqli instanceof mysqli)) {
        require_once __DIR__ . '/db_connect.php';
    }

    return $mysqli;
}

function store_config_all(bool $refresh = false): array {
    static $cache = null;

    if ($refresh || $cache === null) {
        store_config_ensure_defaults();
        $cache = store_config_defaults();
        $mysqli = store_config_db();
        $res = $mysqli->query('SELECT clave, valor FROM configuracion_general');
        if ($res instanceof mysqli_result) {
            while ($row = $res->fetch_assoc()) {
                $cache[$row['clave']] = $row['valor'];
            }
        }
    }

    return $cache;
}

function store_config_table_columns(): array {
    static $columns = null;

    if ($columns !== null) {
        return $columns;
    }

    $columns = [];
    $mysqli = store_config_db();
    $result = $mysqli->query('SHOW COLUMNS FROM configuracion_general');
    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            $field = strtolower((string) ($row['Field'] ?? ''));
            if ($field !== '') {
                $columns[$field] = true;
            }
        }
        $result->free();
    }

    if ($columns === []) {
        $columns = [
            'clave' => true,
            'valor' => true,
            'descripcion' => true,
        ];
    }

    return $columns;
}

function store_config_insert_missing_default(mysqli $mysqli, string $key, string $value, ?string $description = null, array $extraData = []): void {
    $tableColumns = store_config_table_columns();
    if (!isset($tableColumns['clave']) || !isset($tableColumns['valor'])) {
        return;
    }

    $insertData = [
        'clave' => $key,
        'valor' => $value,
    ];

    if (isset($tableColumns['descripcion'])) {
        $insertData['descripcion'] = $description;
    }

    foreach ($extraData as $column => $fieldValue) {
        $normalizedColumn = strtolower((string) $column);
        if (!isset($tableColumns[$normalizedColumn])) {
            continue;
        }

        $insertData[$normalizedColumn] = $fieldValue;
    }

    $quotedColumns = [];
    $quotedValues = [];
    foreach ($insertData as $column => $fieldValue) {
        $quotedColumns[] = '`' . $column . '`';
        $quotedValues[] = $fieldValue === null
            ? 'NULL'
            : "'" . $mysqli->real_escape_string((string) $fieldValue) . "'";
    }

    $sql = 'INSERT IGNORE INTO configuracion_general ('
        . implode(', ', $quotedColumns)
        . ') VALUES ('
        . implode(', ', $quotedValues)
        . ')';

    $mysqli->query($sql);
}

function store_config_ensure_defaults(): void {
    static $ensuring = false;
    static $done = false;

    if ($done || $ensuring) {
        return;
    }

    $ensuring = true;
    $mysqli = store_config_db();
    $descriptions = store_config_descriptions();
    $quantityFeatureKey = 'cantidad_paquetes';
    $quantityFeatureName = 'Vender Paquetes por Cantidad';
    $quantityFeatureDescription = 'Vende tus paquetes por cantidad en una sola venta usando un campo para multiplicar el precio del paquete tantas veces seleccione la cantidad el usuario';
    $accountSaleFeatureKey = 'vender_cuentas';
    $accountSaleFeatureName = 'Vender Cuentas';
    $accountSaleFeatureDescription = 'Entrega cuentas en lugar de recargas para los paquetes marcados con la opcion Vender Cuenta dentro del panel de paquetes';
    $discordFeatureKey = 'api_discord';
    $discordFeatureName = 'API Discord';
    $discordFeatureDescription = 'Automatiza pruebas webhook y futuras integraciones de comandos de Discord para consultas de precios y recargas controladas.';
    $paymentDiscountFeatureKey = 'descuento_metodo_pago';
    $paymentDiscountFeatureName = 'Descuento por Método de Pago';
    $paymentDiscountFeatureDescription = 'Permite ofrecer descuentos porcentuales por cada método de pago configurado y mostrarlos al cliente durante el checkout.';
    $startupPopupGalleryFeatureKey = 'inicio_popup_galeria';
    $startupPopupGalleryFeatureName = 'Ventana Inicial Galería';
    $startupPopupGalleryFeatureDescription = 'Permite mostrar una ventana inicial tipo galería con imágenes promocionales, acceso al canal de WhatsApp y cierre controlado al entrar al index.';

    foreach (store_config_defaults() as $key => $value) {
        $description = $descriptions[$key] ?? null;
        if ($key === $quantityFeatureKey) {
            store_config_insert_missing_default(
                $mysqli,
                $key,
                $value,
                $description,
                [
                    'mostrar_a_cliente' => '1',
                    'funcion_venta' => $quantityFeatureName,
                    'descripcion_venta' => $quantityFeatureDescription,
                    'precio' => '25',
                    'comision_venta' => '5',
                ]
            );
            continue;
        }

        if ($key === $accountSaleFeatureKey) {
            store_config_insert_missing_default(
                $mysqli,
                $key,
                $value,
                $description,
                [
                    'mostrar_a_cliente' => '1',
                    'funcion_venta' => $accountSaleFeatureName,
                    'descripcion_venta' => $accountSaleFeatureDescription,
                    'precio' => '25',
                    'comision_venta' => '5',
                ]
            );
            continue;
        }

        if ($key === $discordFeatureKey) {
            store_config_insert_missing_default(
                $mysqli,
                $key,
                $value,
                $description,
                [
                    'mostrar_a_cliente' => '1',
                    'funcion_venta' => $discordFeatureName,
                    'descripcion_venta' => $discordFeatureDescription,
                    'precio' => '60',
                    'comision_venta' => '0',
                ]
            );
            continue;
        }

        if ($key === $paymentDiscountFeatureKey) {
            store_config_insert_missing_default(
                $mysqli,
                $key,
                $value,
                $description,
                [
                    'mostrar_a_cliente' => '1',
                    'funcion_venta' => $paymentDiscountFeatureName,
                    'descripcion_venta' => $paymentDiscountFeatureDescription,
                    'precio' => '20',
                    'comision_venta' => '0',
                ]
            );
            continue;
        }

        if ($key === $startupPopupGalleryFeatureKey) {
            store_config_insert_missing_default(
                $mysqli,
                $key,
                $value,
                $description,
                [
                    'mostrar_a_cliente' => '1',
                    'funcion_venta' => $startupPopupGalleryFeatureName,
                    'descripcion_venta' => $startupPopupGalleryFeatureDescription,
                    'precio' => '15',
                ]
            );
            continue;
        }

        store_config_insert_missing_default($mysqli, $key, $value, $description);
    }

    $ensuring = false;
    $done = true;
}

function store_config_get(string $key, ?string $default = null): string {
    $config = store_config_all();
    if (array_key_exists($key, $config)) {
        return (string) $config[$key];
    }

    return $default ?? '';
}

function store_config_normalize_social_url(string $value): string {
    return trim($value);
}

function store_config_normalize_bank_api_base_url(string $value): string {
    $candidate = trim($value);
    if ($candidate === '') {
        return 'https://pagonorte.net';
    }

    if (preg_match('~^https?://~i', $candidate) !== 1) {
        $candidate = 'https://' . ltrim($candidate, '/');
    }

    if (filter_var($candidate, FILTER_VALIDATE_URL) === false) {
        return '';
    }

    $scheme = strtolower((string) parse_url($candidate, PHP_URL_SCHEME));
    if (!in_array($scheme, ['http', 'https'], true)) {
        return '';
    }

    $host = trim((string) parse_url($candidate, PHP_URL_HOST));
    if ($host === '') {
        return '';
    }

    $port = parse_url($candidate, PHP_URL_PORT);
    $path = trim((string) parse_url($candidate, PHP_URL_PATH));
    $path = preg_replace('~/+~', '/', $path) ?? '';
    $path = rtrim($path, '/');

    if ($path === '/recargas' || $path === '/recargas/movimientos.jsp') {
        $path = '';
    } elseif (str_ends_with($path, '/recargas/movimientos.jsp')) {
        $path = substr($path, 0, -strlen('/recargas/movimientos.jsp')) ?: '';
    } elseif (str_ends_with($path, '/recargas')) {
        $path = substr($path, 0, -strlen('/recargas')) ?: '';
    }

    $normalized = $scheme . '://' . $host;
    if (is_int($port) && $port > 0) {
        $normalized .= ':' . $port;
    }
    if ($path !== '') {
        $normalized .= $path;
    }

    return rtrim($normalized, '/');
}

function store_config_is_valid_bank_api_base_url(string $value): bool {
    return store_config_normalize_bank_api_base_url($value) !== '';
}

function store_config_build_bank_movements_url(string $baseUrl, array $queryParams): string {
    $normalizedBaseUrl = store_config_normalize_bank_api_base_url($baseUrl);
    if ($normalizedBaseUrl === '') {
        $normalizedBaseUrl = 'https://pagonorte.net';
    }

    $path = trim((string) parse_url($normalizedBaseUrl, PHP_URL_PATH));
    $endpointUrl = preg_match('/\.jsp$/i', $path) === 1
        ? $normalizedBaseUrl
        : ($normalizedBaseUrl . '/recargas/movimientos.jsp');

    return $endpointUrl . '?' . http_build_query($queryParams);
}

function store_config_build_binance_pagonorte_movements_url(string $token): string {
    return 'https://apicentral.pro/apis/movimientos_binance.jsp?' . http_build_query([
        'token' => trim($token),
    ]);
}

function store_config_build_binance_pagonorte_balance_url(string $token): string {
    return 'https://apicentral.pro/apis/saldo_binance.jsp?' . http_build_query([
        'token' => trim($token),
    ]);
}

function store_config_extract_youtube_video_id(string $value): string {
    $candidate = trim($value);
    if ($candidate === '') {
        return '';
    }

    if (preg_match('/^[A-Za-z0-9_-]{11}$/', $candidate) === 1) {
        return $candidate;
    }

    if (filter_var($candidate, FILTER_VALIDATE_URL) === false) {
        return '';
    }

    $host = strtolower((string) parse_url($candidate, PHP_URL_HOST));
    $host = preg_replace('/^(www|m)\./', '', $host);
    $path = trim((string) parse_url($candidate, PHP_URL_PATH), '/');

    if ($host === 'youtu.be') {
        $segments = $path === '' ? [] : explode('/', $path);
        $videoId = $segments[0] ?? '';
        return preg_match('/^[A-Za-z0-9_-]{11}$/', $videoId) === 1 ? $videoId : '';
    }

    if (!in_array($host, ['youtube.com', 'youtube-nocookie.com'], true)) {
        return '';
    }

    if ($path === 'watch') {
        parse_str((string) parse_url($candidate, PHP_URL_QUERY), $queryParams);
        $videoId = trim((string) ($queryParams['v'] ?? ''));
        return preg_match('/^[A-Za-z0-9_-]{11}$/', $videoId) === 1 ? $videoId : '';
    }

    $segments = $path === '' ? [] : explode('/', $path);
    if (count($segments) >= 2 && in_array($segments[0], ['shorts', 'embed', 'live'], true)) {
        $videoId = trim((string) $segments[1]);
        return preg_match('/^[A-Za-z0-9_-]{11}$/', $videoId) === 1 ? $videoId : '';
    }

    return '';
}

function store_config_normalize_youtube_url(string $value): string {
    $videoId = store_config_extract_youtube_video_id($value);
    if ($videoId === '') {
        return '';
    }

    return 'https://www.youtube.com/watch?v=' . $videoId;
}

function store_config_is_valid_youtube_url(string $value): bool {
    return store_config_extract_youtube_video_id($value) !== '';
}

function store_config_youtube_embed_url(string $value): string {
    $videoId = store_config_extract_youtube_video_id($value);
    if ($videoId === '') {
        return '';
    }

    return 'https://www.youtube-nocookie.com/embed/' . $videoId . '?rel=0&modestbranding=1&playsinline=1';
}

function store_config_is_valid_social_url(string $value): bool {
    $normalized = store_config_normalize_social_url($value);
    if ($normalized === '') {
        return false;
    }

    if (filter_var($normalized, FILTER_VALIDATE_URL) === false) {
        return false;
    }

    $scheme = strtolower((string) parse_url($normalized, PHP_URL_SCHEME));
    return in_array($scheme, ['http', 'https'], true);
}

function store_config_normalize_whatsapp(string $value): string {
    $trimmed = trim($value);
    if ($trimmed === '') {
        return '';
    }

    $digits = preg_replace('/\D+/', '', $trimmed);
    if ($digits === null || $digits === '') {
        return '';
    }

    return '+' . $digits;
}

function store_config_is_valid_whatsapp(string $value): bool {
    $normalized = store_config_normalize_whatsapp($value);
    if ($normalized === '') {
        return false;
    }

    return preg_match('/^\+[1-9]\d{9,14}$/', $normalized) === 1;
}

function store_config_whatsapp_link(string $value): string {
    if (!store_config_is_valid_whatsapp($value)) {
        return '';
    }

    $normalized = store_config_normalize_whatsapp($value);
    return 'https://wa.me/' . ltrim($normalized, '+');
}

function store_config_normalize_whatsapp_message(string $value): string {
    $normalized = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    return $normalized;
}

function store_config_whatsapp_link_with_message(string $value, string $message = ''): string {
    $baseLink = store_config_whatsapp_link($value);
    if ($baseLink === '') {
        return '';
    }

    $normalizedMessage = store_config_normalize_whatsapp_message($message);
    if ($normalizedMessage === '') {
        return $baseLink;
    }

    return $baseLink . '?text=' . rawurlencode($normalizedMessage);
}

function store_config_upsert(string $key, string $value, ?string $description = null): bool {
    $mysqli = store_config_db();
    $descriptions = store_config_descriptions();
    $resolvedDescription = $description ?? ($descriptions[$key] ?? null);

    $stmt = $mysqli->prepare(
        'INSERT INTO configuracion_general (clave, valor, descripcion) VALUES (?, ?, ?) '
        . 'ON DUPLICATE KEY UPDATE valor = VALUES(valor), descripcion = COALESCE(VALUES(descripcion), descripcion)'
    );
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('sss', $key, $value, $resolvedDescription);
    $ok = $stmt->execute();
    $stmt->close();

    if ($ok) {
        store_config_all(true);
    }

    return $ok;
}

function store_config_delete(string $key): bool {
    $mysqli = store_config_db();
    $stmt = $mysqli->prepare('DELETE FROM configuracion_general WHERE clave = ?');
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('s', $key);
    $ok = $stmt->execute();
    $stmt->close();

    if ($ok) {
        store_config_all(true);
    }

    return $ok;
}

function store_config_is_managed_logo_path(string $relativePath): bool {
    return tenant_is_managed_path($relativePath, 'store');
}

function store_config_is_managed_public_background_path(string $relativePath): bool {
    return tenant_is_managed_path($relativePath, 'store/backgrounds');
}

function store_config_delete_logo_file(string $relativePath): void {
    if ($relativePath === '' || !store_config_is_managed_logo_path($relativePath)) {
        return;
    }

    $absolutePath = tenant_resolve_public_path($relativePath);
    if ($absolutePath !== null && is_file($absolutePath)) {
        @unlink($absolutePath);
    }
}

function store_config_delete_public_background_media_file(string $relativePath): void {
    if ($relativePath === '' || !store_config_is_managed_public_background_path($relativePath)) {
        return;
    }

    $absolutePath = tenant_resolve_public_path($relativePath);
    if ($absolutePath !== null && is_file($absolutePath)) {
        @unlink($absolutePath);
    }
}

function store_config_is_managed_startup_popup_gallery_path(string $relativePath): bool {
    return tenant_is_managed_path($relativePath, 'store/startup-popup-gallery');
}

function store_config_delete_startup_popup_gallery_file(string $relativePath): void {
    if ($relativePath === '' || !store_config_is_managed_startup_popup_gallery_path($relativePath)) {
        return;
    }

    $absolutePath = tenant_resolve_public_path($relativePath);
    if ($absolutePath !== null && is_file($absolutePath)) {
        @unlink($absolutePath);
    }
}

function store_config_startup_popup_gallery_images(?string $rawJson = null): array {
    $json = $rawJson;
    if ($json === null) {
        $json = (string) store_config_get('inicio_popup_galeria_imagenes', '[]');
    }

    $decoded = json_decode((string) $json, true);
    if (!is_array($decoded)) {
        return [];
    }

    $items = [];
    foreach ($decoded as $item) {
        $path = '';
        $linkUrl = '';
        $linkTarget = '_self';

        if (is_array($item)) {
            $path = trim((string) ($item['path'] ?? ''));
            $linkUrl = trim((string) ($item['link_url'] ?? ''));
            $rawTarget = trim((string) ($item['link_target'] ?? '_self'));
            $linkTarget = $rawTarget === '_blank' ? '_blank' : '_self';
        } else {
            $path = trim((string) $item);
        }

        if ($path === '') {
            continue;
        }

        if (isset($items[$path])) {
            continue;
        }

        $items[$path] = [
            'path' => $path,
            'link_url' => $linkUrl,
            'link_target' => $linkTarget,
        ];
    }

    return array_values($items);
}

function store_config_store_named_logo_upload(array $file, string $prefix = 'store-logo'): array {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['success' => true, 'path' => ''];
    }

    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        return ['success' => false, 'message' => 'No se pudo cargar el logo.'];
    }

    $tmpName = $file['tmp_name'] ?? '';
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        return ['success' => false, 'message' => 'El archivo del logo no es válido.'];
    }

    if (($file['size'] ?? 0) > 2 * 1024 * 1024) {
        return ['success' => false, 'message' => 'El logo no puede superar 2 MB.'];
    }

    $imageInfo = @getimagesize($tmpName);
    if ($imageInfo === false) {
        return ['success' => false, 'message' => 'El logo debe ser una imagen válida.'];
    }

    $mime = $imageInfo['mime'] ?? '';
    $extensions = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];

    if (!isset($extensions[$mime])) {
        return ['success' => false, 'message' => 'Formato de logo no permitido. Usa JPG, PNG, WEBP o GIF.'];
    }

    $targetDir = tenant_upload_absolute_dir('store');
    if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
        return ['success' => false, 'message' => 'No se pudo crear la carpeta del logo.'];
    }

    $safePrefix = preg_replace('/[^a-z0-9\-]+/i', '-', trim($prefix)) ?: 'store-logo';
    $fileName = $safePrefix . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $extensions[$mime];
    $targetPath = $targetDir . DIRECTORY_SEPARATOR . $fileName;

    if (!move_uploaded_file($tmpName, $targetPath)) {
        return ['success' => false, 'message' => 'No se pudo guardar el logo en el servidor.'];
    }

    return ['success' => true, 'path' => tenant_upload_public_path('store', $fileName, true)];
}

function store_config_store_logo_upload(array $file): array {
    return store_config_store_named_logo_upload($file, 'store-logo');
}

function store_config_store_recharge_notification_logo_upload(array $file): array {
    return store_config_store_named_logo_upload($file, 'recharge-notification-logo');
}

function store_config_store_public_background_media_upload(array $file): array {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['success' => true, 'path' => ''];
    }

    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        return ['success' => false, 'message' => 'No se pudo cargar el fondo multimedia.'];
    }

    $tmpName = $file['tmp_name'] ?? '';
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        return ['success' => false, 'message' => 'El archivo del fondo multimedia no es válido.'];
    }

    if (($file['size'] ?? 0) > 25 * 1024 * 1024) {
        return ['success' => false, 'message' => 'El fondo multimedia no puede superar 25 MB.'];
    }

    $mime = '';
    if (function_exists('finfo_open')) {
        $finfo = @finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo !== false) {
            $mime = (string) (@finfo_file($finfo, $tmpName) ?: '');
            finfo_close($finfo);
        }
    }

    if ($mime === '' && function_exists('mime_content_type')) {
        $mime = (string) (@mime_content_type($tmpName) ?: '');
    }

    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
        'video/mp4' => 'mp4',
        'video/webm' => 'webm',
        'video/ogg' => 'ogg',
    ];
    $extensionMap = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'gif' => 'image/gif',
        'mp4' => 'video/mp4',
        'webm' => 'video/webm',
        'ogg' => 'video/ogg',
    ];

    if (!isset($allowed[$mime])) {
        $extension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        if ($extension === '' || !isset($extensionMap[$extension])) {
            return ['success' => false, 'message' => 'Formato de fondo no permitido. Usa MP4, WEBM, OGG, JPG, PNG, WEBP o GIF.'];
        }
        $mime = $extensionMap[$extension];
    }

    if (str_starts_with($mime, 'image/')) {
        $imageInfo = @getimagesize($tmpName);
        if ($imageInfo === false) {
            return ['success' => false, 'message' => 'La imagen de fondo no es válida.'];
        }
    }

    $targetDir = tenant_upload_absolute_dir('store/backgrounds');
    if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
        return ['success' => false, 'message' => 'No se pudo crear la carpeta del fondo multimedia.'];
    }

    $fileName = 'site-background-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $allowed[$mime];
    $targetPath = $targetDir . DIRECTORY_SEPARATOR . $fileName;

    if (!move_uploaded_file($tmpName, $targetPath)) {
        return ['success' => false, 'message' => 'No se pudo guardar el fondo multimedia en el servidor.'];
    }

    return ['success' => true, 'path' => tenant_upload_public_path('store/backgrounds', $fileName, true)];
}

function store_config_store_startup_popup_gallery_upload(array $file): array {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['success' => true, 'path' => ''];
    }

    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        return ['success' => false, 'message' => 'No se pudo cargar una imagen de la galería de la ventana inicial.'];
    }

    $tmpName = $file['tmp_name'] ?? '';
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        return ['success' => false, 'message' => 'Una imagen de la galería de la ventana inicial no es válida.'];
    }

    if (($file['size'] ?? 0) > 6 * 1024 * 1024) {
        return ['success' => false, 'message' => 'Cada imagen de la galería de la ventana inicial no puede superar 6 MB.'];
    }

    $imageInfo = @getimagesize($tmpName);
    if ($imageInfo === false) {
        return ['success' => false, 'message' => 'Cada archivo de la galería de la ventana inicial debe ser una imagen válida.'];
    }

    $mime = $imageInfo['mime'] ?? '';
    $extensions = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];

    if (!isset($extensions[$mime])) {
        return ['success' => false, 'message' => 'Formato no permitido para la galería de la ventana inicial. Usa JPG, PNG, WEBP o GIF.'];
    }

    $targetDir = tenant_upload_absolute_dir('store/startup-popup-gallery');
    if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
        return ['success' => false, 'message' => 'No se pudo crear la carpeta de la galería de la ventana inicial.'];
    }

    $fileName = 'startup-popup-gallery-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $extensions[$mime];
    $targetPath = $targetDir . DIRECTORY_SEPARATOR . $fileName;

    if (!move_uploaded_file($tmpName, $targetPath)) {
        return ['success' => false, 'message' => 'No se pudo guardar una imagen de la galería de la ventana inicial en el servidor.'];
    }

    return ['success' => true, 'path' => tenant_upload_public_path('store/startup-popup-gallery', $fileName, true)];
}

function store_config_store_startup_popup_gallery_uploads(array $files): array {
    $normalizedFiles = [];

    if (isset($files['name']) && is_array($files['name'])) {
        $total = count($files['name']);
        for ($index = 0; $index < $total; $index++) {
            $normalizedFiles[] = [
                'name' => $files['name'][$index] ?? '',
                'type' => $files['type'][$index] ?? '',
                'tmp_name' => $files['tmp_name'][$index] ?? '',
                'error' => $files['error'][$index] ?? UPLOAD_ERR_NO_FILE,
                'size' => $files['size'][$index] ?? 0,
            ];
        }
    } elseif ($files !== []) {
        $normalizedFiles[] = $files;
    }

    $paths = [];
    foreach ($normalizedFiles as $file) {
        $upload = store_config_store_startup_popup_gallery_upload($file);
        if (!$upload['success']) {
            foreach ($paths as $path) {
                store_config_delete_startup_popup_gallery_file($path);
            }
            return $upload;
        }

        $path = trim((string) ($upload['path'] ?? ''));
        if ($path !== '') {
            $paths[] = $path;
        }
    }

    return ['success' => true, 'paths' => $paths];
}