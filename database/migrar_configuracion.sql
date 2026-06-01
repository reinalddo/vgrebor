INSERT INTO configuracion_general (clave, valor, descripcion)
SELECT 'correo_corporativo', correo_corporativo, 'Correo usado para notificaciones' FROM configuracion WHERE id=1;
INSERT INTO configuracion_general (clave, valor, descripcion)
SELECT 'smtp_host', smtp_host, 'Host SMTP para envío de correos' FROM configuracion WHERE id=1;
INSERT INTO configuracion_general (clave, valor, descripcion)
SELECT 'smtp_user', smtp_user, 'Usuario SMTP' FROM configuracion WHERE id=1;
INSERT INTO configuracion_general (clave, valor, descripcion)
SELECT 'smtp_pass', smtp_pass, 'Contraseña SMTP' FROM configuracion WHERE id=1;
INSERT INTO configuracion_general (clave, valor, descripcion)
SELECT 'smtp_port', smtp_port, 'Puerto SMTP' FROM configuracion WHERE id=1;
INSERT INTO configuracion_general (clave, valor, descripcion)
SELECT 'smtp_secure', smtp_secure, 'Tipo de seguridad SMTP' FROM configuracion WHERE id=1;
INSERT INTO configuracion_general (clave, valor, descripcion)
SELECT 'ventana_inicio_juego', '0', 'Activa o desactiva la funcion de ventanas iniciales por juego.'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM configuracion_general WHERE clave = 'ventana_inicio_juego');
INSERT INTO configuracion_general (clave, valor, descripcion)
SELECT 'barra_superior', '0', 'Activa o desactiva la barra superior fija con buscador y reubicación del acceso principal del sitio público.'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM configuracion_general WHERE clave = 'barra_superior');

CREATE TABLE IF NOT EXISTS ventana_inicio_juego_configuracion (
	id INT AUTO_INCREMENT PRIMARY KEY,
	juego_id INT NOT NULL,
	activa TINYINT(1) NOT NULL DEFAULT 0,
	titulo VARCHAR(255) NOT NULL,
	icono TEXT NOT NULL,
	descripcion TEXT NOT NULL,
	check_texto TEXT NOT NULL,
	boton_texto VARCHAR(255) NOT NULL,
	modal_background VARCHAR(7) NOT NULL DEFAULT '#18101e',
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ventana_inicio_juego_tarjetas (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET @schema_name = DATABASE();

SET @column_exists = 0;
SELECT COUNT(*) INTO @column_exists FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'ventana_inicio_juego_configuracion' AND COLUMN_NAME = 'juego_id';
SET @sql = IF(@column_exists = 0, 'ALTER TABLE ventana_inicio_juego_configuracion ADD COLUMN juego_id INT NOT NULL AFTER id', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @column_exists = 0;
SELECT COUNT(*) INTO @column_exists FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'ventana_inicio_juego_configuracion' AND COLUMN_NAME = 'activa';
SET @sql = IF(@column_exists = 0, 'ALTER TABLE ventana_inicio_juego_configuracion ADD COLUMN activa TINYINT(1) NOT NULL DEFAULT 0 AFTER juego_id', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @column_exists = 0;
SELECT COUNT(*) INTO @column_exists FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'ventana_inicio_juego_configuracion' AND COLUMN_NAME = 'titulo';
SET @sql = IF(@column_exists = 0, 'ALTER TABLE ventana_inicio_juego_configuracion ADD COLUMN titulo VARCHAR(255) NOT NULL AFTER activa', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @column_exists = 0;
SELECT COUNT(*) INTO @column_exists FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'ventana_inicio_juego_configuracion' AND COLUMN_NAME = 'icono';
SET @sql = IF(@column_exists = 0, 'ALTER TABLE ventana_inicio_juego_configuracion ADD COLUMN icono TEXT NOT NULL AFTER titulo', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @column_exists = 0;
SELECT COUNT(*) INTO @column_exists FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'ventana_inicio_juego_configuracion' AND COLUMN_NAME = 'descripcion';
SET @sql = IF(@column_exists = 0, 'ALTER TABLE ventana_inicio_juego_configuracion ADD COLUMN descripcion TEXT NOT NULL AFTER icono', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @column_exists = 0;
SELECT COUNT(*) INTO @column_exists FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'ventana_inicio_juego_configuracion' AND COLUMN_NAME = 'check_texto';
SET @sql = IF(@column_exists = 0, 'ALTER TABLE ventana_inicio_juego_configuracion ADD COLUMN check_texto TEXT NOT NULL AFTER descripcion', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @column_exists = 0;
SELECT COUNT(*) INTO @column_exists FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'ventana_inicio_juego_configuracion' AND COLUMN_NAME = 'boton_texto';
SET @sql = IF(@column_exists = 0, 'ALTER TABLE ventana_inicio_juego_configuracion ADD COLUMN boton_texto VARCHAR(255) NOT NULL AFTER check_texto', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @column_exists = 0;
SELECT COUNT(*) INTO @column_exists FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'ventana_inicio_juego_configuracion' AND COLUMN_NAME = 'modal_background';
SET @sql = IF(@column_exists = 0, 'ALTER TABLE ventana_inicio_juego_configuracion ADD COLUMN modal_background VARCHAR(7) NOT NULL DEFAULT ''#18101e'' AFTER boton_texto', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @column_exists = 0;
SELECT COUNT(*) INTO @column_exists FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'ventana_inicio_juego_configuracion' AND COLUMN_NAME = 'title_color';
SET @sql = IF(@column_exists = 0, 'ALTER TABLE ventana_inicio_juego_configuracion ADD COLUMN title_color VARCHAR(7) NOT NULL DEFAULT ''#f8b53d'' AFTER modal_background', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @column_exists = 0;
SELECT COUNT(*) INTO @column_exists FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'ventana_inicio_juego_configuracion' AND COLUMN_NAME = 'check_text_color';
SET @sql = IF(@column_exists = 0, 'ALTER TABLE ventana_inicio_juego_configuracion ADD COLUMN check_text_color VARCHAR(7) NOT NULL DEFAULT ''#e2e8f0'' AFTER title_color', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @column_exists = 0;
SELECT COUNT(*) INTO @column_exists FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'ventana_inicio_juego_configuracion' AND COLUMN_NAME = 'check_background_color';
SET @sql = IF(@column_exists = 0, 'ALTER TABLE ventana_inicio_juego_configuracion ADD COLUMN check_background_color VARCHAR(7) NOT NULL DEFAULT ''#1e293b'' AFTER check_text_color', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @column_exists = 0;
SELECT COUNT(*) INTO @column_exists FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'ventana_inicio_juego_configuracion' AND COLUMN_NAME = 'button_text_color';
SET @sql = IF(@column_exists = 0, 'ALTER TABLE ventana_inicio_juego_configuracion ADD COLUMN button_text_color VARCHAR(7) NOT NULL DEFAULT ''#0b0f18'' AFTER check_background_color', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @column_exists = 0;
SELECT COUNT(*) INTO @column_exists FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'ventana_inicio_juego_configuracion' AND COLUMN_NAME = 'button_background_color';
SET @sql = IF(@column_exists = 0, 'ALTER TABLE ventana_inicio_juego_configuracion ADD COLUMN button_background_color VARCHAR(7) NOT NULL DEFAULT ''#c99712'' AFTER button_text_color', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @column_exists = 0;
SELECT COUNT(*) INTO @column_exists FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'ventana_inicio_juego_configuracion' AND COLUMN_NAME = 'button_disabled_text_color';
SET @sql = IF(@column_exists = 0, 'ALTER TABLE ventana_inicio_juego_configuracion ADD COLUMN button_disabled_text_color VARCHAR(7) NOT NULL DEFAULT ''#0b0f18'' AFTER button_background_color', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @column_exists = 0;
SELECT COUNT(*) INTO @column_exists FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'ventana_inicio_juego_configuracion' AND COLUMN_NAME = 'button_disabled_background_color';
SET @sql = IF(@column_exists = 0, 'ALTER TABLE ventana_inicio_juego_configuracion ADD COLUMN button_disabled_background_color VARCHAR(7) NOT NULL DEFAULT ''#c99712'' AFTER button_disabled_text_color', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @column_exists = 0;
SELECT COUNT(*) INTO @column_exists FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'ventana_inicio_juego_configuracion' AND COLUMN_NAME = 'creado_en';
SET @sql = IF(@column_exists = 0, 'ALTER TABLE ventana_inicio_juego_configuracion ADD COLUMN creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER button_disabled_background_color', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @column_exists = 0;
SELECT COUNT(*) INTO @column_exists FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'ventana_inicio_juego_configuracion' AND COLUMN_NAME = 'actualizado_en';
SET @sql = IF(@column_exists = 0, 'ALTER TABLE ventana_inicio_juego_configuracion ADD COLUMN actualizado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER creado_en', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @index_exists = 0;
SELECT COUNT(*) INTO @index_exists FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'ventana_inicio_juego_configuracion' AND INDEX_NAME = 'uniq_juego';
SET @sql = IF(@index_exists = 0, 'ALTER TABLE ventana_inicio_juego_configuracion ADD UNIQUE KEY uniq_juego (juego_id)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @column_exists = 0;
SELECT COUNT(*) INTO @column_exists FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'ventana_inicio_juego_tarjetas' AND COLUMN_NAME = 'juego_id';
SET @sql = IF(@column_exists = 0, 'ALTER TABLE ventana_inicio_juego_tarjetas ADD COLUMN juego_id INT NOT NULL DEFAULT 0 AFTER id', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @column_exists = 0;
SELECT COUNT(*) INTO @column_exists FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'ventana_inicio_juego_tarjetas' AND COLUMN_NAME = 'content_html';
SET @sql = IF(@column_exists = 0, 'ALTER TABLE ventana_inicio_juego_tarjetas ADD COLUMN content_html LONGTEXT NOT NULL AFTER juego_id', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @column_exists = 0;
SELECT COUNT(*) INTO @column_exists FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'ventana_inicio_juego_tarjetas' AND COLUMN_NAME = 'color';
SET @sql = IF(@column_exists = 0, 'ALTER TABLE ventana_inicio_juego_tarjetas ADD COLUMN color VARCHAR(7) NOT NULL DEFAULT ''#233A73'' AFTER content_html', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @column_exists = 0;
SELECT COUNT(*) INTO @column_exists FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'ventana_inicio_juego_tarjetas' AND COLUMN_NAME = 'background_color';
SET @sql = IF(@column_exists = 0, 'ALTER TABLE ventana_inicio_juego_tarjetas ADD COLUMN background_color VARCHAR(7) NOT NULL DEFAULT ''#121a2f'' AFTER color', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @column_exists = 0;
SELECT COUNT(*) INTO @column_exists FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'ventana_inicio_juego_tarjetas' AND COLUMN_NAME = 'media_path';
SET @sql = IF(@column_exists = 0, 'ALTER TABLE ventana_inicio_juego_tarjetas ADD COLUMN media_path TEXT NULL AFTER background_color', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @column_exists = 0;
SELECT COUNT(*) INTO @column_exists FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'ventana_inicio_juego_tarjetas' AND COLUMN_NAME = 'media_embed_url';
SET @sql = IF(@column_exists = 0, 'ALTER TABLE ventana_inicio_juego_tarjetas ADD COLUMN media_embed_url TEXT NULL AFTER media_path', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @column_exists = 0;
SELECT COUNT(*) INTO @column_exists FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'ventana_inicio_juego_tarjetas' AND COLUMN_NAME = 'activo';
SET @sql = IF(@column_exists = 0, 'ALTER TABLE ventana_inicio_juego_tarjetas ADD COLUMN activo TINYINT(1) NOT NULL DEFAULT 1 AFTER media_embed_url', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @column_exists = 0;
SELECT COUNT(*) INTO @column_exists FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'ventana_inicio_juego_tarjetas' AND COLUMN_NAME = 'orden';
SET @sql = IF(@column_exists = 0, 'ALTER TABLE ventana_inicio_juego_tarjetas ADD COLUMN orden INT NOT NULL DEFAULT 1 AFTER activo', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @column_exists = 0;
SELECT COUNT(*) INTO @column_exists FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'ventana_inicio_juego_tarjetas' AND COLUMN_NAME = 'creado_en';
SET @sql = IF(@column_exists = 0, 'ALTER TABLE ventana_inicio_juego_tarjetas ADD COLUMN creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER orden', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @column_exists = 0;
SELECT COUNT(*) INTO @column_exists FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'ventana_inicio_juego_tarjetas' AND COLUMN_NAME = 'actualizado_en';
SET @sql = IF(@column_exists = 0, 'ALTER TABLE ventana_inicio_juego_tarjetas ADD COLUMN actualizado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER creado_en', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @index_exists = 0;
SELECT COUNT(*) INTO @index_exists FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'ventana_inicio_juego_tarjetas' AND INDEX_NAME = 'idx_game_active_order';
SET @sql = IF(@index_exists = 0, 'ALTER TABLE ventana_inicio_juego_tarjetas ADD INDEX idx_game_active_order (juego_id, activo, orden)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
