INSERT INTO configuracion_general (clave, valor, descripcion) VALUES
('theme_bg_main', '#0A0F14', 'Color base del fondo general de la tienda'),
('theme_bg_alt', '#0E1722', 'Color usado en degradados y secciones secundarias'),
('theme_surface', '#111827', 'Color de tarjetas, paneles y modales'),
('theme_surface_alt', '#181F2A', 'Color alterno para cabecera, dropdowns y paneles internos'),
('theme_primary', '#22D3EE', 'Color principal del brillo, bordes y textos destacados'),
('theme_highlight', '#00FFF7', 'Color de realce para brillos y botones destacados'),
('theme_secondary', '#2DD4BF', 'Color secundario para degradados, hover y efectos'),
('theme_success', '#34D399', 'Color para acciones positivas y estados correctos'),
('theme_warning', '#F59E0B', 'Color para alertas y estados de revisión'),
('theme_danger', '#F87171', 'Color para cancelaciones, errores y alertas críticas'),
('theme_text', '#F8FAFC', 'Color principal del texto en la tienda'),
('theme_text_muted', '#CBD5E1', 'Color de textos secundarios, ayudas y descripciones'),
('theme_price_text', '#22D3EE', 'Color del monto principal en precios de juegos y paquetes'),
('theme_price_muted', '#94A3B8', 'Color del prefijo "Desde" y la moneda en precios de juegos y paquetes'),
('theme_border', '#164E63', 'Color base de bordes, separadores y contenedores'),
('theme_button_primary', '#22D3EE', 'Color principal para botones, acciones y llamadas principales'),
('theme_button_secondary', '#2DD4BF', 'Color secundario para degradados y hover de botones'),
('theme_button_surface', '#0E1722', 'Color base para botones oscuros, menú y tarjetas seleccionables'),
('theme_game_feature_bg', '#0E1722', 'Color de fondo para las caracteristicas visibles en la ficha publica del juego'),
('theme_game_feature_border', '#164E63', 'Color del borde para las caracteristicas visibles en la ficha publica del juego'),
('theme_game_feature_text', '#22D3EE', 'Color del texto para las caracteristicas visibles en la ficha publica del juego'),
('theme_package_feature_bg', '#0F172A', 'Color de fondo para las caracteristicas del paquete mostradas en el encabezado de pago minimalista'),
('theme_package_feature_border', '#164E63', 'Color del borde para las caracteristicas del paquete mostradas en el encabezado de pago minimalista'),
('theme_package_feature_text', '#D8FBFF', 'Color del texto e icono para las caracteristicas del paquete mostradas en el encabezado de pago minimalista'),
('theme_float_whatsapp_bg', '#22C55E', 'Color principal del botón flotante de WhatsApp'),
('theme_float_whatsapp_text', '#F8FAFC', 'Color del texto e icono del botón flotante de WhatsApp'),
('theme_float_channel_bg', '#1F2937', 'Color principal del botón flotante del canal de difusión'),
('theme_float_channel_text', '#F8FAFC', 'Color del texto e icono del botón flotante del canal de difusión')

ON DUPLICATE KEY UPDATE
valor = VALUES(valor),
descripcion = VALUES(descripcion);

INSERT INTO configuracion_general (clave, valor, descripcion)
SELECT 'theme_custom_bg_main', valor, 'Copia editable: Color base del fondo general de la tienda' FROM configuracion_general WHERE clave = 'theme_bg_main'
UNION ALL
SELECT 'theme_custom_bg_alt', valor, 'Copia editable: Color usado en degradados y secciones secundarias' FROM configuracion_general WHERE clave = 'theme_bg_alt'
UNION ALL
SELECT 'theme_custom_surface', valor, 'Copia editable: Color de tarjetas, paneles y modales' FROM configuracion_general WHERE clave = 'theme_surface'
UNION ALL
SELECT 'theme_custom_surface_alt', valor, 'Copia editable: Color alterno para cabecera, dropdowns y paneles internos' FROM configuracion_general WHERE clave = 'theme_surface_alt'
UNION ALL
SELECT 'theme_custom_primary', valor, 'Copia editable: Color principal del brillo, bordes y textos destacados' FROM configuracion_general WHERE clave = 'theme_primary'
UNION ALL
SELECT 'theme_custom_highlight', valor, 'Copia editable: Color de realce para brillos y botones destacados' FROM configuracion_general WHERE clave = 'theme_highlight'
UNION ALL
SELECT 'theme_custom_secondary', valor, 'Copia editable: Color secundario para degradados, hover y efectos' FROM configuracion_general WHERE clave = 'theme_secondary'
UNION ALL
SELECT 'theme_custom_success', valor, 'Copia editable: Color para acciones positivas y estados correctos' FROM configuracion_general WHERE clave = 'theme_success'
UNION ALL
SELECT 'theme_custom_warning', valor, 'Copia editable: Color para alertas y estados de revisión' FROM configuracion_general WHERE clave = 'theme_warning'
UNION ALL
SELECT 'theme_custom_danger', valor, 'Copia editable: Color para cancelaciones, errores y alertas críticas' FROM configuracion_general WHERE clave = 'theme_danger'
UNION ALL
SELECT 'theme_custom_text', valor, 'Copia editable: Color principal del texto en la tienda' FROM configuracion_general WHERE clave = 'theme_text'
UNION ALL
SELECT 'theme_custom_text_muted', valor, 'Copia editable: Color de textos secundarios, ayudas y descripciones' FROM configuracion_general WHERE clave = 'theme_text_muted'
UNION ALL
SELECT 'theme_custom_price_text', valor, 'Copia editable: Color del monto principal en precios de juegos y paquetes' FROM configuracion_general WHERE clave = 'theme_price_text'
UNION ALL
SELECT 'theme_custom_price_muted', valor, 'Copia editable: Color del prefijo "Desde" y la moneda en precios de juegos y paquetes' FROM configuracion_general WHERE clave = 'theme_price_muted'
UNION ALL
SELECT 'theme_custom_border', valor, 'Copia editable: Color base de bordes, separadores y contenedores' FROM configuracion_general WHERE clave = 'theme_border'
UNION ALL
SELECT 'theme_custom_button_primary', valor, 'Copia editable: Color principal para botones, acciones y llamadas principales' FROM configuracion_general WHERE clave = 'theme_button_primary'
UNION ALL
SELECT 'theme_custom_button_secondary', valor, 'Copia editable: Color secundario para degradados y hover de botones' FROM configuracion_general WHERE clave = 'theme_button_secondary'
UNION ALL
SELECT 'theme_custom_button_surface', valor, 'Copia editable: Color base para botones oscuros, menú y tarjetas seleccionables' FROM configuracion_general WHERE clave = 'theme_button_surface'
UNION ALL
SELECT 'theme_custom_game_feature_bg', valor, 'Copia editable: Color de fondo para las caracteristicas visibles en la ficha publica del juego' FROM configuracion_general WHERE clave = 'theme_game_feature_bg'
UNION ALL
SELECT 'theme_custom_game_feature_border', valor, 'Copia editable: Color del borde para las caracteristicas visibles en la ficha publica del juego' FROM configuracion_general WHERE clave = 'theme_game_feature_border'
UNION ALL
SELECT 'theme_custom_game_feature_text', valor, 'Copia editable: Color del texto para las caracteristicas visibles en la ficha publica del juego' FROM configuracion_general WHERE clave = 'theme_game_feature_text'
UNION ALL
SELECT 'theme_custom_package_feature_bg', valor, 'Copia editable: Color de fondo para las caracteristicas del paquete mostradas en el encabezado de pago minimalista' FROM configuracion_general WHERE clave = 'theme_package_feature_bg'
UNION ALL
SELECT 'theme_custom_package_feature_border', valor, 'Copia editable: Color del borde para las caracteristicas del paquete mostradas en el encabezado de pago minimalista' FROM configuracion_general WHERE clave = 'theme_package_feature_border'
UNION ALL
SELECT 'theme_custom_package_feature_text', valor, 'Copia editable: Color del texto e icono para las caracteristicas del paquete mostradas en el encabezado de pago minimalista' FROM configuracion_general WHERE clave = 'theme_package_feature_text'
UNION ALL
SELECT 'theme_custom_float_whatsapp_bg', valor, 'Copia editable: Color principal del botón flotante de WhatsApp' FROM configuracion_general WHERE clave = 'theme_float_whatsapp_bg'
UNION ALL
SELECT 'theme_custom_float_whatsapp_text', valor, 'Copia editable: Color del texto e icono del botón flotante de WhatsApp' FROM configuracion_general WHERE clave = 'theme_float_whatsapp_text'
UNION ALL
SELECT 'theme_custom_float_channel_bg', valor, 'Copia editable: Color principal del botón flotante del canal de difusión' FROM configuracion_general WHERE clave = 'theme_float_channel_bg'
UNION ALL
SELECT 'theme_custom_float_channel_text', valor, 'Copia editable: Color del texto e icono del botón flotante del canal de difusión' FROM configuracion_general WHERE clave = 'theme_float_channel_text'
ON DUPLICATE KEY UPDATE
valor = VALUES(valor),
descripcion = VALUES(descripcion);