<?php

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Este script solo se puede ejecutar por CLI.\n");
    exit(1);
}

function bootstrap_usage(): void {
    $script = basename(__FILE__);
    $usage = <<<TXT
Uso:
  php database/{$script} <tenant-slug> [--admin-email=correo] [--admin-password=clave]

Ejemplos:
  php database/{$script} virtualgaming
  php database/{$script} nuevodominio1 --admin-email=admin@nuevodominio1.com --admin-password=CambiaEsto123!

TXT;

    fwrite(STDOUT, $usage);
}

function bootstrap_fail(string $message, int $exitCode = 1): void {
    fwrite(STDERR, $message . PHP_EOL);
    exit($exitCode);
}

function bootstrap_cli_options(array $argv): array {
    $tenantSlug = '';
    $options = [
        'admin-email' => '',
        'admin-password' => '',
    ];

    foreach (array_slice($argv, 1) as $argument) {
        if ($argument === '--help' || $argument === '-h') {
            bootstrap_usage();
            exit(0);
        }

        if (str_starts_with($argument, '--')) {
            $parts = explode('=', $argument, 2);
            $key = ltrim($parts[0], '-');
            $value = $parts[1] ?? '';
            if (array_key_exists($key, $options)) {
                $options[$key] = trim($value);
                continue;
            }

            bootstrap_fail('Opcion no reconocida: ' . $argument);
        }

        if ($tenantSlug === '') {
            $tenantSlug = trim($argument);
            continue;
        }

        bootstrap_fail('Argumento no reconocido: ' . $argument);
    }

    if ($tenantSlug === '') {
        bootstrap_usage();
        bootstrap_fail('Debes indicar el slug del tenant.');
    }

    return [
        'tenant_slug' => $tenantSlug,
        'admin_email' => $options['admin-email'],
        'admin_password' => $options['admin-password'],
    ];
}

$cli = bootstrap_cli_options($argv);

$_GET['tenant'] = $cli['tenant_slug'];

require_once dirname(__DIR__) . '/includes/tenant.php';

$tenantSlug = tenant_slugify($cli['tenant_slug']);
if ($tenantSlug === '') {
    bootstrap_fail('El slug del tenant no es valido.');
}

if (!tenant_directory_exists($tenantSlug)) {
    bootstrap_fail('No existe la carpeta del tenant: ' . $tenantSlug);
}

$tenantData = tenant_load_data_file($tenantSlug);
if ($tenantData === []) {
    bootstrap_fail('No se encontro un data.json valido para el tenant ' . $tenantSlug . '.');
}

$config = tenant_config();
$dbConfig = $config['database'] ?? [];
$dbHost = (string) ($dbConfig['host'] ?? 'localhost');
$dbName = (string) ($dbConfig['name'] ?? '');
$dbUser = (string) ($dbConfig['user'] ?? 'root');
$dbPassword = (string) ($dbConfig['password'] ?? '');
$dbCharset = (string) ($dbConfig['charset'] ?? 'utf8mb4');

if ($dbName === '') {
    bootstrap_fail('El data.json del tenant no define un nombre de base de datos utilizable.');
}

function bootstrap_connect_server(string $host, string $user, string $password): mysqli {
    $mysqli = mysqli_init();
    if (!$mysqli) {
        bootstrap_fail('No se pudo inicializar mysqli.');
    }

    $connected = @$mysqli->real_connect($host, $user, $password, null);
    if (!$connected) {
        bootstrap_fail('No se pudo conectar al servidor MySQL: ' . $mysqli->connect_error);
    }

    return $mysqli;
}

function bootstrap_exec(mysqli $mysqli, string $sql, string $context): void {
    if ($mysqli->query($sql) === false) {
        bootstrap_fail($context . ': ' . $mysqli->error . PHP_EOL . 'SQL: ' . $sql);
    }
}

function bootstrap_table_has_column(mysqli $mysqli, string $table, string $column): bool {
    $safeTable = $mysqli->real_escape_string($table);
    $safeColumn = $mysqli->real_escape_string($column);
    $result = $mysqli->query("SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeColumn}'");
    return $result instanceof mysqli_result && $result->num_rows > 0;
}

function bootstrap_table_has_index(mysqli $mysqli, string $table, string $index): bool {
    $safeTable = $mysqli->real_escape_string($table);
    $safeIndex = $mysqli->real_escape_string($index);
    $result = $mysqli->query("SHOW INDEX FROM `{$safeTable}` WHERE Key_name = '{$safeIndex}'");
    return $result instanceof mysqli_result && $result->num_rows > 0;
}

function bootstrap_ensure_admin(mysqli $mysqli, string $tenantSlug, string $adminEmail, string $adminPassword): array {
    $resolvedEmail = trim($adminEmail);
    if ($resolvedEmail === '') {
        $resolvedEmail = 'admin@' . $tenantSlug . '.local';
    }

    if (filter_var($resolvedEmail, FILTER_VALIDATE_EMAIL) === false) {
        bootstrap_fail('El correo del admin no es valido: ' . $resolvedEmail);
    }

    $stmt = $mysqli->prepare("SELECT id, email FROM usuarios WHERE rol = 'admin' ORDER BY id ASC LIMIT 1");
    if (!$stmt) {
        bootstrap_fail('No se pudo preparar la consulta del admin: ' . $mysqli->error);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    $existingAdmin = $res instanceof mysqli_result ? $res->fetch_assoc() : null;
    $stmt->close();

    if ($existingAdmin) {
        return [
            'created' => false,
            'email' => (string) ($existingAdmin['email'] ?? $resolvedEmail),
            'password' => '',
        ];
    }

    $resolvedPassword = trim($adminPassword);
    if ($resolvedPassword === '') {
        $resolvedPassword = 'admin123';
    }

    if (strlen($resolvedPassword) < 6) {
        bootstrap_fail('La clave del admin debe tener al menos 6 caracteres.');
    }

    $username = $resolvedEmail;
    $fullName = 'Administrador';
    $passwordHash = password_hash($resolvedPassword, PASSWORD_DEFAULT);
    $role = 'admin';
    $insert = $mysqli->prepare('INSERT INTO usuarios (username, password, nombre, email, rol, creado_en) VALUES (?, ?, ?, ?, ?, NOW())');
    if (!$insert) {
        bootstrap_fail('No se pudo preparar la insercion del admin: ' . $mysqli->error);
    }
    $insert->bind_param('sssss', $username, $passwordHash, $fullName, $resolvedEmail, $role);
    if (!$insert->execute()) {
        $insert->close();
        bootstrap_fail('No se pudo crear el admin inicial: ' . $mysqli->error);
    }
    $insert->close();

    return [
        'created' => true,
        'email' => $resolvedEmail,
        'password' => $resolvedPassword,
    ];
}

function bootstrap_ensure_upload_directories(string $tenantSlug): void {
    $directories = [
        tenant_directory_path($tenantSlug) . DIRECTORY_SEPARATOR . 'uploads',
        tenant_directory_path($tenantSlug) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'store',
        tenant_directory_path($tenantSlug) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'gallery',
        tenant_directory_path($tenantSlug) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'juegos',
        tenant_directory_path($tenantSlug) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'paquetes',
    ];

    foreach ($directories as $directory) {
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            bootstrap_fail('No se pudo crear la carpeta: ' . $directory);
        }
    }
}

$server = bootstrap_connect_server($dbHost, $dbUser, $dbPassword);
$charset = $server->real_escape_string($dbCharset !== '' ? $dbCharset : 'utf8mb4');
$databaseName = $server->real_escape_string($dbName);
bootstrap_exec(
    $server,
    "CREATE DATABASE IF NOT EXISTS `{$databaseName}` CHARACTER SET {$charset} COLLATE {$charset}_unicode_ci",
    'No se pudo crear la base de datos'
);
$server->close();

require_once dirname(__DIR__) . '/includes/db_connect.php';

if (!$mysqli instanceof mysqli) {
    bootstrap_fail('La conexion principal del tenant no quedo disponible.');
}

$usersSql = <<<'SQL'
CREATE TABLE IF NOT EXISTS usuarios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(120) NOT NULL,
    password VARCHAR(255) NOT NULL,
    nombre VARCHAR(120) DEFAULT NULL,
    email VARCHAR(180) DEFAULT NULL,
    telefono VARCHAR(50) DEFAULT NULL,
    foto_perfil VARCHAR(255) DEFAULT NULL,
    last_purchase_user_identifier VARCHAR(150) DEFAULT NULL,
    last_purchase_phone VARCHAR(50) DEFAULT NULL,
    rol ENUM('admin','empleado','usuario') NOT NULL DEFAULT 'usuario',
    reset_requested_at DATETIME DEFAULT NULL,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_usuarios_username (username),
    UNIQUE KEY uq_usuarios_email (email),
    KEY idx_usuarios_rol (rol)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;
bootstrap_exec($mysqli, $usersSql, 'No se pudo asegurar la tabla usuarios');
bootstrap_exec(
    $mysqli,
    "ALTER TABLE usuarios MODIFY rol ENUM('admin','empleado','usuario') NOT NULL DEFAULT 'usuario'",
    'No se pudo actualizar el enum del rol en usuarios'
);
if (!bootstrap_table_has_column($mysqli, 'usuarios', 'telefono')) {
    bootstrap_exec($mysqli, "ALTER TABLE usuarios ADD COLUMN telefono VARCHAR(50) NULL AFTER email", 'No se pudo asegurar la columna telefono en usuarios');
}
if (!bootstrap_table_has_column($mysqli, 'usuarios', 'foto_perfil')) {
    bootstrap_exec($mysqli, "ALTER TABLE usuarios ADD COLUMN foto_perfil VARCHAR(255) NULL AFTER telefono", 'No se pudo asegurar la columna foto_perfil en usuarios');
}
if (!bootstrap_table_has_column($mysqli, 'usuarios', 'last_purchase_user_identifier')) {
    bootstrap_exec($mysqli, "ALTER TABLE usuarios ADD COLUMN last_purchase_user_identifier VARCHAR(150) NULL AFTER telefono", 'No se pudo asegurar la columna last_purchase_user_identifier en usuarios');
}
if (!bootstrap_table_has_column($mysqli, 'usuarios', 'last_purchase_phone')) {
    bootstrap_exec($mysqli, "ALTER TABLE usuarios ADD COLUMN last_purchase_phone VARCHAR(50) NULL AFTER last_purchase_user_identifier", 'No se pudo asegurar la columna last_purchase_phone en usuarios');
}

$currenciesSql = <<<'SQL'
CREATE TABLE IF NOT EXISTS monedas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL,
    clave VARCHAR(10) NOT NULL,
    tasa DECIMAL(16,6) NOT NULL DEFAULT 1,
    es_base TINYINT(1) NOT NULL DEFAULT 0,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    mostrar_decimales TINYINT(1) NOT NULL DEFAULT 1,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_monedas_clave (clave),
    KEY idx_monedas_base (es_base),
    KEY idx_monedas_activo (activo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;
bootstrap_exec($mysqli, $currenciesSql, 'No se pudo asegurar la tabla monedas');

$gamesSql = <<<'SQL'
CREATE TABLE IF NOT EXISTS juegos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(160) NOT NULL,
    descripcion TEXT DEFAULT NULL,
    precio DECIMAL(12,2) NOT NULL DEFAULT 0,
    imagen VARCHAR(255) DEFAULT NULL,
    imagen_paquete VARCHAR(255) DEFAULT NULL,
    moneda_fija_id INT DEFAULT NULL,
    popular TINYINT(1) NOT NULL DEFAULT 0,
    api_free_fire TINYINT(1) NOT NULL DEFAULT 0,
    categoria_api VARCHAR(100) DEFAULT NULL,
    categoria_api_discord VARCHAR(120) DEFAULT NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    actualizado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_juegos_popular (popular),
    KEY idx_juegos_activo (activo),
    KEY idx_juegos_moneda_fija (moneda_fija_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;
bootstrap_exec($mysqli, $gamesSql, 'No se pudo asegurar la tabla juegos');

$featuresSql = <<<'SQL'
CREATE TABLE IF NOT EXISTS juego_caracteristicas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    juego_id INT NOT NULL,
    caracteristica VARCHAR(255) NOT NULL,
    KEY idx_juego_caracteristicas_juego (juego_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;
bootstrap_exec($mysqli, $featuresSql, 'No se pudo asegurar la tabla juego_caracteristicas');

$packsSql = <<<'SQL'
CREATE TABLE IF NOT EXISTS juego_paquetes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    juego_id INT NOT NULL,
    nombre VARCHAR(180) NOT NULL,
    clave VARCHAR(80) NOT NULL,
    monto_ff VARCHAR(20) DEFAULT NULL,
    paquete_api INT DEFAULT NULL,
    api_provider VARCHAR(30) DEFAULT NULL,
    cantidad VARCHAR(80) NOT NULL DEFAULT '1',
    precio DECIMAL(12,2) NOT NULL DEFAULT 0,
    imagen_icono VARCHAR(255) DEFAULT NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    actualizado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_juego_paquetes_juego (juego_id),
    KEY idx_juego_paquetes_activo (activo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;
bootstrap_exec($mysqli, $packsSql, 'No se pudo asegurar la tabla juego_paquetes');

$ordersSql = <<<'SQL'
CREATE TABLE IF NOT EXISTS pedidos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_slug VARCHAR(80) DEFAULT NULL,
    juego_id INT DEFAULT NULL,
    paquete_id INT DEFAULT NULL,
    juego_nombre VARCHAR(180) DEFAULT NULL,
    paquete_nombre VARCHAR(180) DEFAULT NULL,
    paquete_cantidad VARCHAR(80) DEFAULT NULL,
    monto_ff VARCHAR(20) DEFAULT NULL,
    paquete_api INT DEFAULT NULL,
    api_provider VARCHAR(30) DEFAULT NULL,
    moneda VARCHAR(20) DEFAULT NULL,
    precio DECIMAL(12,2) NOT NULL DEFAULT 0,
    user_identifier VARCHAR(150) DEFAULT NULL,
    email VARCHAR(180) DEFAULT NULL,
    cliente_usuario_id INT DEFAULT NULL,
    numero_referencia VARCHAR(120) DEFAULT NULL,
    telefono_contacto VARCHAR(40) DEFAULT NULL,
    cupon VARCHAR(60) DEFAULT NULL,
    cantidad INT NOT NULL DEFAULT 1,
    estado_pago_influencer ENUM('pendiente','pagado') NOT NULL DEFAULT 'pendiente',
    ff_api_referencia VARCHAR(120) DEFAULT NULL,
    ff_api_mensaje VARCHAR(255) DEFAULT NULL,
    ff_api_payload LONGTEXT DEFAULT NULL,
    estado ENUM('pendiente','pagado','enviado','cancelado') NOT NULL DEFAULT 'pendiente',
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    actualizado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_pedidos_estado (estado),
    KEY idx_pedidos_email (email),
    KEY idx_pedidos_cliente_usuario (cliente_usuario_id),
    KEY idx_pedidos_tenant (tenant_slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;
bootstrap_exec($mysqli, $ordersSql, 'No se pudo asegurar la tabla pedidos');

$movementsSql = <<<'SQL'
CREATE TABLE IF NOT EXISTS movimientos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    referencia VARCHAR(120) NOT NULL,
    descripcion VARCHAR(255) DEFAULT NULL,
    fecha_raw VARCHAR(120) DEFAULT NULL,
    fecha_movimiento DATETIME DEFAULT NULL,
    tipo VARCHAR(80) DEFAULT NULL,
    monto DECIMAL(14,2) NOT NULL DEFAULT 0,
    moneda VARCHAR(20) NOT NULL DEFAULT 'VES',
    pedido_id INT DEFAULT NULL,
    payload_json LONGTEXT DEFAULT NULL,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    actualizado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_movimientos_referencia (referencia),
    KEY idx_movimientos_pedido (pedido_id),
    KEY idx_movimientos_fecha (fecha_movimiento)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;
bootstrap_exec($mysqli, $movementsSql, 'No se pudo asegurar la tabla movimientos');

$configGeneralSql = <<<'SQL'
CREATE TABLE IF NOT EXISTS configuracion_general (
    id INT AUTO_INCREMENT PRIMARY KEY,
    clave VARCHAR(64) NOT NULL UNIQUE,
    valor TEXT NOT NULL,
    descripcion VARCHAR(255) DEFAULT NULL,
    actualizado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    mostrar_a_cliente TINYINT(1) DEFAULT 0 NULL,
    funcion_venta VARCHAR(255) DEFAULT NULL,
    descripcion_venta VARCHAR(255) DEFAULT NULL,
    precio INT DEFAULT NULL,
    comision_venta INT DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;
bootstrap_exec($mysqli, $configGeneralSql, 'No se pudo asegurar la tabla configuracion_general');

$configLegacySql = <<<'SQL'
CREATE TABLE IF NOT EXISTS configuracion (
    id INT AUTO_INCREMENT PRIMARY KEY,
    correo_corporativo VARCHAR(180) NOT NULL DEFAULT '',
    smtp_host VARCHAR(120) NOT NULL DEFAULT '',
    smtp_user VARCHAR(120) NOT NULL DEFAULT '',
    smtp_pass VARCHAR(120) NOT NULL DEFAULT '',
    smtp_port INT NOT NULL DEFAULT 587,
    smtp_secure VARCHAR(10) NOT NULL DEFAULT 'tls',
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;
bootstrap_exec($mysqli, $configLegacySql, 'No se pudo asegurar la tabla configuracion');

$gallerySql = <<<'SQL'
CREATE TABLE IF NOT EXISTS home_gallery (
    id INT AUTO_INCREMENT PRIMARY KEY,
    titulo VARCHAR(160) NOT NULL,
    descripcion1 VARCHAR(255) NOT NULL,
    descripcion2 VARCHAR(255) NOT NULL,
    imagen VARCHAR(255) NOT NULL,
    url VARCHAR(500) DEFAULT NULL,
    abrir_nueva_pestana TINYINT(1) NOT NULL DEFAULT 0,
    destacado TINYINT(1) NOT NULL DEFAULT 0,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    actualizado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_home_gallery_destacado (destacado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;
bootstrap_exec($mysqli, $gallerySql, 'No se pudo asegurar la tabla home_gallery');

$paymentMethodsSql = <<<'SQL'
CREATE TABLE IF NOT EXISTS payment_methods (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(160) NOT NULL,
    datos TEXT NOT NULL,
    moneda_id INT DEFAULT NULL,
    referencia_digitos INT NOT NULL DEFAULT 0,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    actualizado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_payment_methods_activo (activo),
    KEY idx_payment_methods_moneda_id (moneda_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;
bootstrap_exec($mysqli, $paymentMethodsSql, 'No se pudo asegurar la tabla payment_methods');

$couponsSql = <<<'SQL'
CREATE TABLE IF NOT EXISTS cupones (
    id INT AUTO_INCREMENT PRIMARY KEY,
    codigo VARCHAR(50) NOT NULL,
    tipo_descuento ENUM('porcentaje', 'fijo') NOT NULL,
    valor_descuento DECIMAL(10,2) NOT NULL,
    fecha_expiracion DATETIME DEFAULT NULL,
    limite_usos INT DEFAULT 0,
    usos_actuales INT NOT NULL DEFAULT 0,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    permitir_acumular_puntos TINYINT(1) NOT NULL DEFAULT 1,
    nombre_influencer VARCHAR(100) DEFAULT NULL,
    telefono_influencer VARCHAR(50) DEFAULT NULL,
    email_influencer VARCHAR(100) DEFAULT NULL,
    comision_influencer DECIMAL(5,2) NOT NULL DEFAULT 0,
    fecha_creacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_cupones_codigo (codigo),
    KEY idx_cupones_activo (activo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;
bootstrap_exec($mysqli, $couponsSql, 'No se pudo asegurar la tabla cupones');

$couponUsersSql = <<<'SQL'
CREATE TABLE IF NOT EXISTS cupones_usuarios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_cupon INT NOT NULL,
    id_usuario INT NOT NULL,
    fecha_uso DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_cupones_usuarios (id_cupon, id_usuario),
    KEY idx_cupones_usuarios_usuario (id_usuario)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;
bootstrap_exec($mysqli, $couponUsersSql, 'No se pudo asegurar la tabla cupones_usuarios');

$influencerSalesSql = <<<'SQL'
CREATE TABLE IF NOT EXISTS cupones_influencer_ventas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cupon_id INT NOT NULL,
    pedido_id INT NOT NULL,
    nombre_influencer VARCHAR(100) DEFAULT NULL,
    codigo_cupon VARCHAR(60) NOT NULL,
    telefono_influencer VARCHAR(50) DEFAULT NULL,
    email_influencer VARCHAR(100) DEFAULT NULL,
    comision_porcentaje DECIMAL(5,2) NOT NULL DEFAULT 0,
    paquete_vendido VARCHAR(180) DEFAULT NULL,
    moneda VARCHAR(20) DEFAULT NULL,
    total_pedido DECIMAL(12,2) NOT NULL DEFAULT 0,
    total_comision DECIMAL(12,2) NOT NULL DEFAULT 0,
    estado_pago ENUM('pendiente','pagado') NOT NULL DEFAULT 'pendiente',
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_influencer_sale_order (pedido_id),
    KEY idx_influencer_sale_coupon (cupon_id),
    KEY idx_influencer_sale_code (codigo_cupon)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;
bootstrap_exec($mysqli, $influencerSalesSql, 'No se pudo asegurar la tabla cupones_influencer_ventas');

bootstrap_exec(
    $mysqli,
    "INSERT INTO monedas (nombre, clave, tasa, es_base, activo, mostrar_decimales) VALUES ('Dolar estadounidense', 'USD', 1, 1, 1, 1) ON DUPLICATE KEY UPDATE tasa = VALUES(tasa), es_base = VALUES(es_base), activo = VALUES(activo)",
    'No se pudo sembrar la moneda base'
);

$legacyConfigResult = $mysqli->query('SELECT id FROM configuracion ORDER BY id ASC LIMIT 1');
$hasLegacyConfig = $legacyConfigResult instanceof mysqli_result && $legacyConfigResult->num_rows > 0;
if (!$hasLegacyConfig) {
    bootstrap_exec(
        $mysqli,
        "INSERT INTO configuracion (correo_corporativo, smtp_host, smtp_user, smtp_pass, smtp_port, smtp_secure) VALUES ('', '', '', '', 587, 'tls')",
        'No se pudo sembrar la configuracion legacy'
    );
}

require_once dirname(__DIR__) . '/includes/currency.php';
require_once dirname(__DIR__) . '/includes/home_gallery.php';
require_once dirname(__DIR__) . '/includes/payment_methods.php';
require_once dirname(__DIR__) . '/includes/store_config.php';
require_once dirname(__DIR__) . '/includes/influencer_coupons.php';

currency_ensure_schema();
home_gallery_ensure_table();
payment_methods_ensure_table();
store_config_ensure_defaults();
influencer_coupon_ensure_sales_table_mysqli($mysqli);

$adminStatus = bootstrap_ensure_admin($mysqli, $tenantSlug, $cli['admin_email'], $cli['admin_password']);
bootstrap_ensure_upload_directories($tenantSlug);

fwrite(STDOUT, 'Tenant preparado: ' . $tenantSlug . PHP_EOL);
fwrite(STDOUT, 'Base de datos: ' . $dbName . PHP_EOL);
fwrite(STDOUT, 'Host MySQL: ' . $dbHost . PHP_EOL);
fwrite(STDOUT, 'Uploads: ' . tenant_directory_path($tenantSlug) . DIRECTORY_SEPARATOR . 'uploads' . PHP_EOL);

if ($adminStatus['created']) {
    fwrite(STDOUT, 'Admin inicial creado: ' . $adminStatus['email'] . PHP_EOL);
    fwrite(STDOUT, 'Clave temporal: ' . $adminStatus['password'] . PHP_EOL);
} else {
    fwrite(STDOUT, 'Admin existente detectado: ' . $adminStatus['email'] . PHP_EOL);
}
