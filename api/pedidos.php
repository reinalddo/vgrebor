<?php
require_once __DIR__ . '/../includes/tenant.php';
tenant_start_session();
if (ob_get_level() === 0) {
    ob_start();
}
header('Content-Type: application/json');
@ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/currency.php';
require_once __DIR__ . '/../includes/influencer_coupons.php';
require_once __DIR__ . '/../includes/store_config.php';
require_once __DIR__ . '/../includes/payment_methods.php';
require_once __DIR__ . '/../includes/binance_pay.php';
require_once __DIR__ . '/../includes/paypal_pay.php';
require_once __DIR__ . '/../includes/api_discord.php';
require_once __DIR__ . '/../includes/package_account_sales.php';
require_once __DIR__ . '/../includes/recargas_api.php';
require_once __DIR__ . '/../includes/recharge_availability.php';
require_once __DIR__ . '/../includes/recharge_notifications.php';
require_once __DIR__ . '/../includes/win_points.php';
require_once __DIR__ . '/../includes/payment_difference.php';

if (!function_exists('create_app_mysqli_connection')) {
    function create_app_mysqli_connection(): mysqli {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

        $tenantDatabase = tenant_database_config();
        $host = (string) ($tenantDatabase['host'] ?? 'localhost');
        $user = (string) ($tenantDatabase['user'] ?? 'root');
        $pass = (string) ($tenantDatabase['password'] ?? '');
        $name = (string) ($tenantDatabase['name'] ?? 'tvirtualgaming');

        $connection = new mysqli($host, $user, $pass, $name);
        $connection->set_charset((string) ($tenantDatabase['charset'] ?? 'utf8mb4'));
        $connection->query("SET time_zone = '-04:00'");

        return $connection;
    }
}

if (!function_exists('ensure_mysqli_connection')) {
    function ensure_mysqli_connection(?mysqli $connection = null): mysqli {
        if ($connection instanceof mysqli) {
            try {
                $connection->query('SELECT 1');
                return $connection;
            } catch (Throwable $e) {
                error_log('TVG MySQL reconnect triggered: ' . $e->getMessage());
                try {
                    $connection->close();
                } catch (Throwable $closeError) {
                }
            }
        }

        $newConnection = create_app_mysqli_connection();
        $GLOBALS['mysqli'] = $newConnection;

        return $newConnection;
    }
}

currency_ensure_schema();
if (trim((string) store_config_get('binance_pagonorte_activo', '0')) === '1') {
    currency_ensure_code('USDT', 'Tether USD', 1.0, true, true);
}
payment_methods_ensure_table();
recharge_availability_ensure_columns($mysqli);
package_account_sales_ensure_schema($mysqli);

function ensure_pedidos_table(mysqli $mysqli): void {
    $create = "CREATE TABLE IF NOT EXISTS pedidos (
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
        precio_descuento_metodo_pago_base DECIMAL(12,2) DEFAULT NULL,
        descuento_metodo_pago_porcentaje DECIMAL(5,2) NOT NULL DEFAULT 0,
        descuento_metodo_pago_monto DECIMAL(12,2) NOT NULL DEFAULT 0,
        precio_original DECIMAL(12,2) DEFAULT NULL,
        diferencia_pago_credito_aplicado DECIMAL(12,2) NOT NULL DEFAULT 0,
        diferencia_pago_credito_origen_pedido_id INT DEFAULT NULL,
        diferencia_pago_credito_activado TINYINT(1) NOT NULL DEFAULT 0,
        user_identifier VARCHAR(150) DEFAULT NULL,
        player_fields_json LONGTEXT DEFAULT NULL,
        email VARCHAR(180) DEFAULT NULL,
        cliente_usuario_id INT DEFAULT NULL,
        numero_referencia VARCHAR(120) DEFAULT NULL,
        telefono_contacto VARCHAR(40) DEFAULT NULL,
        metodo_pago VARCHAR(160) DEFAULT NULL,
        payment_method_id INT NOT NULL DEFAULT 0,
        cupon VARCHAR(60) DEFAULT NULL,
        cantidad_compra INT NOT NULL DEFAULT 1,
        vender_cuenta TINYINT(1) NOT NULL DEFAULT 0,
        cuenta_entrega_texto LONGTEXT DEFAULT NULL,
        cuenta_galeria_json LONGTEXT DEFAULT NULL,
        win_points_awarded INT NOT NULL DEFAULT 0,
        win_points_payment_mode VARCHAR(20) DEFAULT NULL,
        api_discord_command_key VARCHAR(120) DEFAULT NULL,
        api_discord_command_template VARCHAR(255) DEFAULT NULL,
        api_discord_status VARCHAR(40) DEFAULT NULL,
        api_discord_message_id VARCHAR(120) DEFAULT NULL,
        api_discord_http_status INT DEFAULT NULL,
        api_discord_response_body LONGTEXT DEFAULT NULL,
        api_discord_attempts INT NOT NULL DEFAULT 0,
        api_discord_requires_review TINYINT(1) NOT NULL DEFAULT 0,
        api_discord_last_attempt_at DATETIME DEFAULT NULL,
        api_discord_sent_at DATETIME DEFAULT NULL,
        ff_api_referencia VARCHAR(120) DEFAULT NULL,
        ff_api_mensaje VARCHAR(255) DEFAULT NULL,
        ff_api_payload LONGTEXT DEFAULT NULL,
        recargas_api_pedido_id VARCHAR(120) DEFAULT NULL,
        recargas_api_estado VARCHAR(40) DEFAULT NULL,
        recargas_api_codigo_entregado LONGTEXT DEFAULT NULL,
        recargas_api_reembolso DECIMAL(12,2) DEFAULT NULL,
        recargas_api_ultimo_check DATETIME DEFAULT NULL,
        recargas_api_historial_json LONGTEXT DEFAULT NULL,
        binance_pay_request_id VARCHAR(120) DEFAULT NULL,
        binance_pay_order_no VARCHAR(120) DEFAULT NULL,
        binance_pay_reference VARCHAR(120) DEFAULT NULL,
        binance_pay_status VARCHAR(40) DEFAULT NULL,
        binance_pay_message VARCHAR(255) DEFAULT NULL,
        binance_pay_checkout_url VARCHAR(255) DEFAULT NULL,
        binance_pay_payload LONGTEXT DEFAULT NULL,
        binance_pay_paid_amount DECIMAL(12,2) DEFAULT NULL,
        binance_pay_paid_currency VARCHAR(20) DEFAULT NULL,
        binance_pay_ultimo_check DATETIME DEFAULT NULL,
        binance_pay_historial_json LONGTEXT DEFAULT NULL,
        paypal_order_id VARCHAR(120) DEFAULT NULL,
        paypal_capture_id VARCHAR(120) DEFAULT NULL,
        paypal_payer_id VARCHAR(120) DEFAULT NULL,
        paypal_status VARCHAR(40) DEFAULT NULL,
        paypal_message VARCHAR(255) DEFAULT NULL,
        paypal_checkout_url VARCHAR(255) DEFAULT NULL,
        paypal_payload LONGTEXT DEFAULT NULL,
        paypal_paid_amount DECIMAL(12,2) DEFAULT NULL,
        paypal_paid_currency VARCHAR(20) DEFAULT NULL,
        paypal_ultimo_check DATETIME DEFAULT NULL,
        paypal_historial_json LONGTEXT DEFAULT NULL,
        estado ENUM('pendiente','pagado','enviado','cancelado') NOT NULL DEFAULT 'pendiente',
        creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        pago_expira_en DATETIME DEFAULT NULL,
        actualizado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_estado (estado),
        INDEX idx_email (email),
        INDEX idx_cliente_usuario_id (cliente_usuario_id),
        INDEX idx_api_discord_status (api_discord_status),
        INDEX idx_api_discord_message_id (api_discord_message_id),
        INDEX idx_binance_pay_reference (binance_pay_reference),
        INDEX idx_binance_pay_order_no (binance_pay_order_no),
        INDEX idx_binance_pay_status (binance_pay_status),
        INDEX idx_paypal_order_id (paypal_order_id),
        INDEX idx_paypal_capture_id (paypal_capture_id),
        INDEX idx_paypal_status (paypal_status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $mysqli->query($create);

    // Migración defensiva: asegurar columnas si la tabla ya existía con otro esquema
    $neededCols = [
        'tenant_slug' => "ALTER TABLE pedidos ADD COLUMN tenant_slug VARCHAR(80) NULL AFTER id",
        'juego_id' => "ALTER TABLE pedidos ADD COLUMN juego_id INT NULL AFTER tenant_slug",
        'paquete_id' => "ALTER TABLE pedidos ADD COLUMN paquete_id INT NULL AFTER juego_id",
        'juego_nombre' => "ALTER TABLE pedidos ADD COLUMN juego_nombre VARCHAR(180) NULL AFTER juego_id",
        'paquete_nombre' => "ALTER TABLE pedidos ADD COLUMN paquete_nombre VARCHAR(180) NULL AFTER juego_nombre",
        'paquete_cantidad' => "ALTER TABLE pedidos ADD COLUMN paquete_cantidad VARCHAR(80) NULL AFTER paquete_nombre",
        'monto_ff' => "ALTER TABLE pedidos ADD COLUMN monto_ff VARCHAR(20) NULL AFTER paquete_cantidad",
        'paquete_api' => "ALTER TABLE pedidos ADD COLUMN paquete_api INT NULL AFTER monto_ff",
        'api_provider' => "ALTER TABLE pedidos ADD COLUMN api_provider VARCHAR(30) NULL AFTER paquete_api",
        'moneda' => "ALTER TABLE pedidos ADD COLUMN moneda VARCHAR(20) NULL AFTER paquete_cantidad",
        'precio' => "ALTER TABLE pedidos ADD COLUMN precio DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER moneda",
        'precio_descuento_metodo_pago_base' => "ALTER TABLE pedidos ADD COLUMN precio_descuento_metodo_pago_base DECIMAL(12,2) NULL AFTER precio",
        'descuento_metodo_pago_porcentaje' => "ALTER TABLE pedidos ADD COLUMN descuento_metodo_pago_porcentaje DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER precio_descuento_metodo_pago_base",
        'descuento_metodo_pago_monto' => "ALTER TABLE pedidos ADD COLUMN descuento_metodo_pago_monto DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER descuento_metodo_pago_porcentaje",
        'precio_original' => "ALTER TABLE pedidos ADD COLUMN precio_original DECIMAL(12,2) NULL AFTER descuento_metodo_pago_monto",
        'diferencia_pago_credito_aplicado' => "ALTER TABLE pedidos ADD COLUMN diferencia_pago_credito_aplicado DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER precio_original",
        'diferencia_pago_credito_origen_pedido_id' => "ALTER TABLE pedidos ADD COLUMN diferencia_pago_credito_origen_pedido_id INT NULL AFTER diferencia_pago_credito_aplicado",
        'diferencia_pago_credito_activado' => "ALTER TABLE pedidos ADD COLUMN diferencia_pago_credito_activado TINYINT(1) NOT NULL DEFAULT 0 AFTER diferencia_pago_credito_origen_pedido_id",
        'user_identifier' => "ALTER TABLE pedidos ADD COLUMN user_identifier VARCHAR(150) NULL AFTER precio",
        'player_fields_json' => "ALTER TABLE pedidos ADD COLUMN player_fields_json LONGTEXT NULL AFTER user_identifier",
        'email' => "ALTER TABLE pedidos ADD COLUMN email VARCHAR(180) NULL AFTER user_identifier",
        'cantidad' => "ALTER TABLE pedidos ADD COLUMN cantidad INT NOT NULL DEFAULT 1 AFTER cupon",
        'cliente_usuario_id' => "ALTER TABLE pedidos ADD COLUMN cliente_usuario_id INT NULL AFTER email",
        'numero_referencia' => "ALTER TABLE pedidos ADD COLUMN numero_referencia VARCHAR(120) NULL AFTER cliente_usuario_id",
        'telefono_contacto' => "ALTER TABLE pedidos ADD COLUMN telefono_contacto VARCHAR(40) NULL AFTER numero_referencia",
        'metodo_pago' => "ALTER TABLE pedidos ADD COLUMN metodo_pago VARCHAR(160) NULL AFTER telefono_contacto",
        'payment_method_id' => "ALTER TABLE pedidos ADD COLUMN payment_method_id INT NOT NULL DEFAULT 0 AFTER metodo_pago",
        'cupon' => "ALTER TABLE pedidos ADD COLUMN cupon VARCHAR(60) NULL AFTER payment_method_id",
        'cantidad_compra' => "ALTER TABLE pedidos ADD COLUMN cantidad_compra INT NOT NULL DEFAULT 1 AFTER cupon",
        'vender_cuenta' => "ALTER TABLE pedidos ADD COLUMN vender_cuenta TINYINT(1) NOT NULL DEFAULT 0 AFTER cantidad_compra",
        'cuenta_entrega_texto' => "ALTER TABLE pedidos ADD COLUMN cuenta_entrega_texto LONGTEXT NULL AFTER vender_cuenta",
        'cuenta_galeria_json' => "ALTER TABLE pedidos ADD COLUMN cuenta_galeria_json LONGTEXT NULL AFTER cuenta_entrega_texto",
        'win_points_awarded' => "ALTER TABLE pedidos ADD COLUMN win_points_awarded INT NOT NULL DEFAULT 0 AFTER cuenta_galeria_json",
        'win_points_payment_mode' => "ALTER TABLE pedidos ADD COLUMN win_points_payment_mode VARCHAR(20) NULL AFTER win_points_awarded",
        'api_discord_command_key' => "ALTER TABLE pedidos ADD COLUMN api_discord_command_key VARCHAR(120) NULL AFTER win_points_payment_mode",
        'api_discord_command_template' => "ALTER TABLE pedidos ADD COLUMN api_discord_command_template VARCHAR(255) NULL AFTER api_discord_command_key",
        'api_discord_status' => "ALTER TABLE pedidos ADD COLUMN api_discord_status VARCHAR(40) NULL AFTER api_discord_command_template",
        'api_discord_message_id' => "ALTER TABLE pedidos ADD COLUMN api_discord_message_id VARCHAR(120) NULL AFTER api_discord_status",
        'api_discord_http_status' => "ALTER TABLE pedidos ADD COLUMN api_discord_http_status INT NULL AFTER api_discord_message_id",
        'api_discord_response_body' => "ALTER TABLE pedidos ADD COLUMN api_discord_response_body LONGTEXT NULL AFTER api_discord_http_status",
        'api_discord_attempts' => "ALTER TABLE pedidos ADD COLUMN api_discord_attempts INT NOT NULL DEFAULT 0 AFTER api_discord_response_body",
        'api_discord_requires_review' => "ALTER TABLE pedidos ADD COLUMN api_discord_requires_review TINYINT(1) NOT NULL DEFAULT 0 AFTER api_discord_attempts",
        'api_discord_last_attempt_at' => "ALTER TABLE pedidos ADD COLUMN api_discord_last_attempt_at DATETIME NULL AFTER api_discord_requires_review",
        'api_discord_sent_at' => "ALTER TABLE pedidos ADD COLUMN api_discord_sent_at DATETIME NULL AFTER api_discord_last_attempt_at",
        'ff_api_referencia' => "ALTER TABLE pedidos ADD COLUMN ff_api_referencia VARCHAR(120) NULL AFTER cupon",
        'ff_api_mensaje' => "ALTER TABLE pedidos ADD COLUMN ff_api_mensaje VARCHAR(255) NULL AFTER ff_api_referencia",
        'ff_api_payload' => "ALTER TABLE pedidos ADD COLUMN ff_api_payload LONGTEXT NULL AFTER ff_api_mensaje",
        'recargas_api_pedido_id' => "ALTER TABLE pedidos ADD COLUMN recargas_api_pedido_id VARCHAR(120) NULL AFTER ff_api_payload",
        'recargas_api_estado' => "ALTER TABLE pedidos ADD COLUMN recargas_api_estado VARCHAR(40) NULL AFTER recargas_api_pedido_id",
        'recargas_api_codigo_entregado' => "ALTER TABLE pedidos ADD COLUMN recargas_api_codigo_entregado LONGTEXT NULL AFTER recargas_api_estado",
        'recargas_api_reembolso' => "ALTER TABLE pedidos ADD COLUMN recargas_api_reembolso DECIMAL(12,2) NULL AFTER recargas_api_codigo_entregado",
        'recargas_api_ultimo_check' => "ALTER TABLE pedidos ADD COLUMN recargas_api_ultimo_check DATETIME NULL AFTER recargas_api_reembolso",
        'recargas_api_historial_json' => "ALTER TABLE pedidos ADD COLUMN recargas_api_historial_json LONGTEXT NULL AFTER recargas_api_ultimo_check",
        'binance_pay_request_id' => "ALTER TABLE pedidos ADD COLUMN binance_pay_request_id VARCHAR(120) NULL AFTER recargas_api_historial_json",
        'binance_pay_order_no' => "ALTER TABLE pedidos ADD COLUMN binance_pay_order_no VARCHAR(120) NULL AFTER binance_pay_request_id",
        'binance_pay_reference' => "ALTER TABLE pedidos ADD COLUMN binance_pay_reference VARCHAR(120) NULL AFTER binance_pay_order_no",
        'binance_pay_status' => "ALTER TABLE pedidos ADD COLUMN binance_pay_status VARCHAR(40) NULL AFTER binance_pay_reference",
        'binance_pay_message' => "ALTER TABLE pedidos ADD COLUMN binance_pay_message VARCHAR(255) NULL AFTER binance_pay_status",
        'binance_pay_checkout_url' => "ALTER TABLE pedidos ADD COLUMN binance_pay_checkout_url VARCHAR(255) NULL AFTER binance_pay_message",
        'binance_pay_payload' => "ALTER TABLE pedidos ADD COLUMN binance_pay_payload LONGTEXT NULL AFTER binance_pay_checkout_url",
        'binance_pay_paid_amount' => "ALTER TABLE pedidos ADD COLUMN binance_pay_paid_amount DECIMAL(12,2) NULL AFTER binance_pay_payload",
        'binance_pay_paid_currency' => "ALTER TABLE pedidos ADD COLUMN binance_pay_paid_currency VARCHAR(20) NULL AFTER binance_pay_paid_amount",
        'binance_pay_ultimo_check' => "ALTER TABLE pedidos ADD COLUMN binance_pay_ultimo_check DATETIME NULL AFTER binance_pay_paid_currency",
        'binance_pay_historial_json' => "ALTER TABLE pedidos ADD COLUMN binance_pay_historial_json LONGTEXT NULL AFTER binance_pay_ultimo_check",
        'paypal_order_id' => "ALTER TABLE pedidos ADD COLUMN paypal_order_id VARCHAR(120) NULL AFTER binance_pay_historial_json",
        'paypal_capture_id' => "ALTER TABLE pedidos ADD COLUMN paypal_capture_id VARCHAR(120) NULL AFTER paypal_order_id",
        'paypal_payer_id' => "ALTER TABLE pedidos ADD COLUMN paypal_payer_id VARCHAR(120) NULL AFTER paypal_capture_id",
        'paypal_status' => "ALTER TABLE pedidos ADD COLUMN paypal_status VARCHAR(40) NULL AFTER paypal_payer_id",
        'paypal_message' => "ALTER TABLE pedidos ADD COLUMN paypal_message VARCHAR(255) NULL AFTER paypal_status",
        'paypal_checkout_url' => "ALTER TABLE pedidos ADD COLUMN paypal_checkout_url VARCHAR(255) NULL AFTER paypal_message",
        'paypal_payload' => "ALTER TABLE pedidos ADD COLUMN paypal_payload LONGTEXT NULL AFTER paypal_checkout_url",
        'paypal_paid_amount' => "ALTER TABLE pedidos ADD COLUMN paypal_paid_amount DECIMAL(12,2) NULL AFTER paypal_payload",
        'paypal_paid_currency' => "ALTER TABLE pedidos ADD COLUMN paypal_paid_currency VARCHAR(20) NULL AFTER paypal_paid_amount",
        'paypal_ultimo_check' => "ALTER TABLE pedidos ADD COLUMN paypal_ultimo_check DATETIME NULL AFTER paypal_paid_currency",
        'paypal_historial_json' => "ALTER TABLE pedidos ADD COLUMN paypal_historial_json LONGTEXT NULL AFTER paypal_ultimo_check",
        'estado_pago_influencer' => "ALTER TABLE pedidos ADD COLUMN estado_pago_influencer ENUM('pendiente','pagado') NOT NULL DEFAULT 'pendiente' AFTER cupon",
        'estado' => "ALTER TABLE pedidos ADD COLUMN estado ENUM('pendiente','pagado','enviado','cancelado') NOT NULL DEFAULT 'pendiente' AFTER cupon",
        'creado_en' => "ALTER TABLE pedidos ADD COLUMN creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER estado",
        'pago_expira_en' => "ALTER TABLE pedidos ADD COLUMN pago_expira_en DATETIME NULL AFTER creado_en",
        'actualizado_en' => "ALTER TABLE pedidos ADD COLUMN actualizado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER creado_en"
    ];
    $colResult = $mysqli->query("SHOW COLUMNS FROM pedidos");
    $existing = [];
    if ($colResult) {
        while ($row = $colResult->fetch_assoc()) {
            $existing[$row['Field']] = true;
        }
    }
    foreach ($neededCols as $col => $alterSql) {
        if (!isset($existing[$col])) {
            $mysqli->query($alterSql);
        }
    }

    $indexes = [
        'idx_estado' => 'ALTER TABLE pedidos ADD INDEX idx_estado (estado)',
        'idx_email' => 'ALTER TABLE pedidos ADD INDEX idx_email (email)',
        'idx_cliente_usuario_id' => 'ALTER TABLE pedidos ADD INDEX idx_cliente_usuario_id (cliente_usuario_id)',
        'idx_api_discord_status' => 'ALTER TABLE pedidos ADD INDEX idx_api_discord_status (api_discord_status)',
        'idx_api_discord_message_id' => 'ALTER TABLE pedidos ADD INDEX idx_api_discord_message_id (api_discord_message_id)',
        'idx_binance_pay_reference' => 'ALTER TABLE pedidos ADD INDEX idx_binance_pay_reference (binance_pay_reference)',
        'idx_binance_pay_order_no' => 'ALTER TABLE pedidos ADD INDEX idx_binance_pay_order_no (binance_pay_order_no)',
        'idx_binance_pay_status' => 'ALTER TABLE pedidos ADD INDEX idx_binance_pay_status (binance_pay_status)',
        'idx_paypal_order_id' => 'ALTER TABLE pedidos ADD INDEX idx_paypal_order_id (paypal_order_id)',
        'idx_paypal_capture_id' => 'ALTER TABLE pedidos ADD INDEX idx_paypal_capture_id (paypal_capture_id)',
        'idx_paypal_status' => 'ALTER TABLE pedidos ADD INDEX idx_paypal_status (paypal_status)',
    ];
    foreach ($indexes as $indexName => $sql) {
        $indexResult = $mysqli->query("SHOW INDEX FROM pedidos WHERE Key_name = '" . $mysqli->real_escape_string($indexName) . "'");
        if (!($indexResult instanceof mysqli_result) || $indexResult->num_rows === 0) {
            $mysqli->query($sql);
        }
    }
}

function ensure_juego_paquetes_monto_ff_column(mysqli $mysqli): void {
    $result = $mysqli->query("SHOW COLUMNS FROM juego_paquetes LIKE 'monto_ff'");
    if (!($result instanceof mysqli_result) || $result->num_rows === 0) {
        $mysqli->query("ALTER TABLE juego_paquetes ADD COLUMN monto_ff VARCHAR(20) NULL AFTER clave");
    }
}

function ensure_juego_paquetes_paquete_api_column(mysqli $mysqli): void {
    $result = $mysqli->query("SHOW COLUMNS FROM juego_paquetes LIKE 'paquete_api'");
    if (!($result instanceof mysqli_result) || $result->num_rows === 0) {
        $mysqli->query("ALTER TABLE juego_paquetes ADD COLUMN paquete_api INT NULL AFTER monto_ff");
    }
}

function ensure_juego_paquetes_api_provider_column(mysqli $mysqli): void {
    $result = $mysqli->query("SHOW COLUMNS FROM juego_paquetes LIKE 'api_provider'");
    if (!($result instanceof mysqli_result) || $result->num_rows === 0) {
        $mysqli->query("ALTER TABLE juego_paquetes ADD COLUMN api_provider VARCHAR(30) NULL AFTER paquete_api");
    }
}

function ensure_movimientos_table(mysqli $mysqli): void {
    $create = "CREATE TABLE IF NOT EXISTS movimientos (
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
        INDEX idx_movimientos_pedido_id (pedido_id),
        INDEX idx_movimientos_monto (monto),
        INDEX idx_movimientos_fecha (fecha_movimiento)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $mysqli->query($create);

    $neededCols = [
        'referencia' => "ALTER TABLE movimientos ADD COLUMN referencia VARCHAR(120) NOT NULL AFTER id",
        'descripcion' => "ALTER TABLE movimientos ADD COLUMN descripcion VARCHAR(255) NULL AFTER referencia",
        'fecha_raw' => "ALTER TABLE movimientos ADD COLUMN fecha_raw VARCHAR(120) NULL AFTER descripcion",
        'fecha_movimiento' => "ALTER TABLE movimientos ADD COLUMN fecha_movimiento DATETIME NULL AFTER fecha_raw",
        'tipo' => "ALTER TABLE movimientos ADD COLUMN tipo VARCHAR(80) NULL AFTER fecha_movimiento",
        'monto' => "ALTER TABLE movimientos ADD COLUMN monto DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER tipo",
        'moneda' => "ALTER TABLE movimientos ADD COLUMN moneda VARCHAR(20) NOT NULL DEFAULT 'VES' AFTER monto",
        'pedido_id' => "ALTER TABLE movimientos ADD COLUMN pedido_id INT NULL AFTER moneda",
        'payload_json' => "ALTER TABLE movimientos ADD COLUMN payload_json LONGTEXT NULL AFTER pedido_id",
        'creado_en' => "ALTER TABLE movimientos ADD COLUMN creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER payload_json",
        'actualizado_en' => "ALTER TABLE movimientos ADD COLUMN actualizado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER creado_en",
    ];

    $colResult = $mysqli->query("SHOW COLUMNS FROM movimientos");
    $existing = [];
    if ($colResult instanceof mysqli_result) {
        while ($row = $colResult->fetch_assoc()) {
            $existing[$row['Field']] = true;
        }
    }

    foreach ($neededCols as $col => $alterSql) {
        if (!isset($existing[$col])) {
            $mysqli->query($alterSql);
        }
    }

    $indexes = [
        'uniq_movimientos_referencia' => 'ALTER TABLE movimientos ADD UNIQUE KEY uniq_movimientos_referencia (referencia)',
        'idx_movimientos_pedido_id' => 'ALTER TABLE movimientos ADD INDEX idx_movimientos_pedido_id (pedido_id)',
        'idx_movimientos_monto' => 'ALTER TABLE movimientos ADD INDEX idx_movimientos_monto (monto)',
        'idx_movimientos_fecha' => 'ALTER TABLE movimientos ADD INDEX idx_movimientos_fecha (fecha_movimiento)',
    ];
    foreach ($indexes as $indexName => $sql) {
        $indexResult = $mysqli->query("SHOW INDEX FROM movimientos WHERE Key_name = '" . $mysqli->real_escape_string($indexName) . "'");
        if (!($indexResult instanceof mysqli_result) || $indexResult->num_rows === 0) {
            $mysqli->query($sql);
        }
    }
}

function ensure_juegos_api_free_fire_column(mysqli $mysqli): void {
    $result = $mysqli->query("SHOW COLUMNS FROM juegos LIKE 'api_free_fire'");
    if (!($result instanceof mysqli_result) || $result->num_rows === 0) {
        $mysqli->query("ALTER TABLE juegos ADD COLUMN api_free_fire TINYINT(1) NOT NULL DEFAULT 0 AFTER popular");
    }
}

function ensure_juegos_categoria_api_column(mysqli $mysqli): void {
    $result = $mysqli->query("SHOW COLUMNS FROM juegos LIKE 'categoria_api'");
    if (!($result instanceof mysqli_result) || $result->num_rows === 0) {
        $mysqli->query("ALTER TABLE juegos ADD COLUMN categoria_api VARCHAR(100) NULL AFTER api_free_fire");
    }
}
function ensure_juegos_categoria_api_discord_column(mysqli $mysqli): void {
    $result = $mysqli->query("SHOW COLUMNS FROM juegos LIKE 'categoria_api_discord'");
    if (!($result instanceof mysqli_result) || $result->num_rows === 0) {
        $mysqli->query("ALTER TABLE juegos ADD COLUMN categoria_api_discord VARCHAR(120) NULL AFTER categoria_api");
    }
}

function ensure_juegos_api_discord_catalog_columns(mysqli $mysqli): void {
    $columns = [
        'api_discord_catalog_json' => "ALTER TABLE juegos ADD COLUMN api_discord_catalog_json LONGTEXT NULL AFTER categoria_api_discord",
        'api_discord_catalog_raw' => "ALTER TABLE juegos ADD COLUMN api_discord_catalog_raw LONGTEXT NULL AFTER api_discord_catalog_json",
        'api_discord_catalog_status' => "ALTER TABLE juegos ADD COLUMN api_discord_catalog_status VARCHAR(40) NULL AFTER api_discord_catalog_raw",
        'api_discord_catalog_message_id' => "ALTER TABLE juegos ADD COLUMN api_discord_catalog_message_id VARCHAR(120) NULL AFTER api_discord_catalog_status",
        'api_discord_catalog_updated_at' => "ALTER TABLE juegos ADD COLUMN api_discord_catalog_updated_at DATETIME NULL AFTER api_discord_catalog_message_id",
    ];

    foreach ($columns as $columnName => $statement) {
        $result = $mysqli->query("SHOW COLUMNS FROM juegos LIKE '" . $mysqli->real_escape_string($columnName) . "'");
        if (!($result instanceof mysqli_result) || $result->num_rows === 0) {
            $mysqli->query($statement);
        }
    }
}

function coupon_table_exists(mysqli $mysqli): bool {
    $res = $mysqli->query("SHOW TABLES LIKE 'cupones'");
    return $res && $res->num_rows > 0;
}

function coupon_ensure_points_column(mysqli $mysqli): void {
    if (!coupon_table_exists($mysqli)) {
        return;
    }

    $result = $mysqli->query("SHOW COLUMNS FROM cupones LIKE 'permitir_acumular_puntos'");
    if (!($result instanceof mysqli_result) || $result->num_rows === 0) {
        $mysqli->query("ALTER TABLE cupones ADD COLUMN permitir_acumular_puntos TINYINT(1) NOT NULL DEFAULT 1 AFTER activo");
    }
}

function coupon_ensure_game_scope_column(mysqli $mysqli): void {
    if (!coupon_table_exists($mysqli)) {
        return;
    }

    $result = $mysqli->query("SHOW COLUMNS FROM cupones LIKE 'juegos_restringidos_json'");
    if (!($result instanceof mysqli_result) || $result->num_rows === 0) {
        $mysqli->query("ALTER TABLE cupones ADD COLUMN juegos_restringidos_json LONGTEXT NULL AFTER permitir_acumular_puntos");
    }
}

function coupon_game_scope_enabled(): bool {
    return trim((string) store_config_get('cupon_x_juegos', '0')) === '1';
}

function coupon_selected_game_ids(?string $json): array {
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

function coupon_applies_to_game(?array $coupon, int $gameId): bool {
    if (!$coupon) {
        return false;
    }

    if (!coupon_game_scope_enabled()) {
        return true;
    }

    $selectedGameIds = coupon_selected_game_ids($coupon['juegos_restringidos_json'] ?? null);
    if (empty($selectedGameIds)) {
        return true;
    }

    return $gameId > 0 && in_array($gameId, $selectedGameIds, true);
}

function coupon_allows_points_accumulation(?array $coupon): bool {
    if (!win_points_enabled()) {
        return false;
    }

    if (!$coupon) {
        return true;
    }

    return !isset($coupon['permitir_acumular_puntos']) || (int) $coupon['permitir_acumular_puntos'] === 1;
}

function table_exists(mysqli $mysqli, string $tableName): bool {
    $safeName = $mysqli->real_escape_string($tableName);
    $res = $mysqli->query("SHOW TABLES LIKE '{$safeName}'");
    return $res && $res->num_rows > 0;
}

function table_has_column(mysqli $mysqli, string $tableName, string $columnName): bool {
    static $cache = [];

    $cacheKey = $tableName . '.' . $columnName;
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    if (!table_exists($mysqli, $tableName)) {
        $cache[$cacheKey] = false;
        return false;
    }

    try {
        $safeTable = $mysqli->real_escape_string($tableName);
        $safeColumn = $mysqli->real_escape_string($columnName);
        $result = $mysqli->query("SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeColumn}'");
        $cache[$cacheKey] = $result instanceof mysqli_result && $result->num_rows > 0;
    } catch (Throwable $e) {
        error_log('TVG table_has_column failed for ' . $cacheKey . ': ' . $e->getMessage());
        $cache[$cacheKey] = false;
    }

    return $cache[$cacheKey];
}

function pedidos_existing_columns(mysqli $mysqli): array {
    static $cache = null;

    if (is_array($cache)) {
        return $cache;
    }

    $cache = [];
    if (!table_exists($mysqli, 'pedidos')) {
        return $cache;
    }

    try {
        $result = $mysqli->query('SHOW COLUMNS FROM pedidos');
        if ($result instanceof mysqli_result) {
            while ($row = $result->fetch_assoc()) {
                $field = (string) ($row['Field'] ?? '');
                if ($field !== '') {
                    $cache[$field] = true;
                }
            }
        }
    } catch (Throwable $e) {
        error_log('TVG pedidos_existing_columns failed: ' . $e->getMessage());
    }

    return $cache;
}

function pedidos_sql_value(mysqli $mysqli, $value): string {
    if ($value === null) {
        return 'NULL';
    }

    if (is_bool($value)) {
        return $value ? '1' : '0';
    }

    if (is_int($value)) {
        return (string) $value;
    }

    if (is_float($value)) {
        $normalized = rtrim(rtrim(sprintf('%.10F', $value), '0'), '.');
        return $normalized !== '' ? $normalized : '0';
    }

    return "'" . $mysqli->real_escape_string((string) $value) . "'";
}

function pedidos_insert_order(mysqli $mysqli, array $data): int {
    $availableColumns = pedidos_existing_columns($mysqli);
    $columns = [];
    $values = [];

    foreach ($data as $column => $value) {
        if (!isset($availableColumns[$column])) {
            continue;
        }

        $columns[] = $column;
        $values[] = pedidos_sql_value($mysqli, $value);
    }

    if (empty($columns)) {
        throw new RuntimeException('La tabla pedidos no tiene columnas compatibles para registrar la orden.');
    }

    $sql = 'INSERT INTO pedidos (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ')';
    $mysqli->query($sql);

    return (int) $mysqli->insert_id;
}

function sync_coupon_usage_counts_safe(mysqli $mysqli): void {
    if (!coupon_table_exists($mysqli)) {
        return;
    }

    try {
        sync_coupon_usage_counts_mysqli($mysqli);
    } catch (Throwable $e) {
        error_log('TVG coupon usage sync skipped: ' . $e->getMessage());
    }
}

function load_mail_settings(mysqli $mysqli): array {
    $settings = [
        'correo_corporativo' => '',
        'smtp_host' => '',
        'smtp_user' => '',
        'smtp_pass' => '',
        'smtp_port' => 587,
        'smtp_secure' => 'tls',
    ];

    if (table_exists($mysqli, 'configuracion_general')) {
        $res = $mysqli->query("SELECT clave, valor FROM configuracion_general");
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $key = $row['clave'] ?? '';
                if ($key !== '' && array_key_exists($key, $settings)) {
                    $settings[$key] = $row['valor'];
                }
            }
        }
    } elseif (table_exists($mysqli, 'configuracion')) {
        $res = $mysqli->query("SELECT * FROM configuracion ORDER BY id DESC LIMIT 1");
        if ($res && ($row = $res->fetch_assoc())) {
            foreach ($settings as $key => $defaultValue) {
                if (isset($row[$key]) && $row[$key] !== '') {
                    $settings[$key] = $row[$key];
                }
            }
        }
    }

    $settings['smtp_port'] = (int) ($settings['smtp_port'] ?: 587);
    $settings['smtp_secure'] = strtolower(trim((string) $settings['smtp_secure']));
    if (!in_array($settings['smtp_secure'], ['ssl', 'tls'], true)) {
        $settings['smtp_secure'] = 'tls';
    }

    return $settings;
}

function resolve_admin_email(mysqli $mysqli): ?string {
    $mysqli = ensure_mysqli_connection($mysqli);

    $settings = load_mail_settings($mysqli);
    foreach (['correo_corporativo', 'smtp_user'] as $key) {
        $candidate = trim((string) ($settings[$key] ?? ''));
        if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_EMAIL)) {
            return $candidate;
        }
    }

    if (table_exists($mysqli, 'usuarios')) {
        $resAdmin = $mysqli->query("SELECT email FROM usuarios WHERE rol='admin' AND email IS NOT NULL AND email != '' ORDER BY id ASC LIMIT 1");
        if ($resAdmin && ($rowAdmin = $resAdmin->fetch_assoc())) {
            $adminEmail = trim((string) ($rowAdmin['email'] ?? ''));
            if ($adminEmail !== '' && filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
                return $adminEmail;
            }
        }
    }

    $envEmail = trim((string) getenv('TVG_ADMIN_EMAIL'));
    if ($envEmail !== '' && filter_var($envEmail, FILTER_VALIDATE_EMAIL)) {
        return $envEmail;
    }

    return null;
}

function fetch_valid_coupon(mysqli $mysqli, string $code, int $gameId = 0): ?array {
    if ($code === '' || !coupon_table_exists($mysqli)) {
        return null;
    }
    coupon_ensure_points_column($mysqli);
    coupon_ensure_game_scope_column($mysqli);
    $stmt = $mysqli->prepare("SELECT * FROM cupones WHERE codigo = ? LIMIT 1");
    if (!$stmt) return null;
    $stmt->bind_param('s', $code);
    $stmt->execute();
    $res = $stmt->get_result();
    $coupon = $res ? $res->fetch_assoc() : null;
    if (!$coupon) return null;
    if (empty($coupon['activo'])) return null;
    if (!empty($coupon['fecha_expiracion']) && strtotime($coupon['fecha_expiracion']) < time()) return null;
    if (!empty($coupon['limite_usos']) && isset($coupon['usos_actuales']) && $coupon['usos_actuales'] >= $coupon['limite_usos']) return null;
    if (!coupon_applies_to_game($coupon, $gameId)) return null;
    return $coupon;
}

function fetch_coupon_by_code(mysqli $mysqli, string $code): ?array {
    if ($code === '') {
        return null;
    }

    coupon_ensure_points_column($mysqli);
    coupon_ensure_game_scope_column($mysqli);

    $stmt = $mysqli->prepare('SELECT * FROM cupones WHERE codigo = ? LIMIT 1');
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('s', $code);
    $stmt->execute();
    $res = $stmt->get_result();
    $coupon = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    return $coupon ?: null;
}

function register_influencer_coupon_sale(mysqli $mysqli, array $order): void {
    $orderId = (int) ($order['id'] ?? 0);
    $couponCode = normalize_coupon_code((string) ($order['cupon'] ?? ''));
    if ($orderId <= 0 || $couponCode === '') {
        return;
    }

    $coupon = fetch_coupon_by_code($mysqli, $couponCode);
    if (!$coupon || !influencer_coupon_has_owner($coupon)) {
        return;
    }

    influencer_coupon_ensure_sales_table_mysqli($mysqli);

    $existsStmt = $mysqli->prepare('SELECT id FROM cupones_influencer_ventas WHERE pedido_id = ? LIMIT 1');
    if (!$existsStmt) {
        return;
    }
    $existsStmt->bind_param('i', $orderId);
    $existsStmt->execute();
    $existing = $existsStmt->get_result();
    $alreadyExists = $existing && $existing->fetch_assoc();
    $existsStmt->close();
    if ($alreadyExists) {
        return;
    }

    $couponId = (int) ($coupon['id'] ?? 0);
    if ($couponId <= 0) {
        return;
    }

    $influencerName = influencer_coupon_clean_text($coupon['nombre_influencer'] ?? null, 100);
    $phone = influencer_coupon_clean_text($coupon['telefono_influencer'] ?? null, 50);
    $email = influencer_coupon_clean_text($coupon['email_influencer'] ?? null, 100);
    $commissionPercent = influencer_coupon_commission_percent($coupon['comision_influencer'] ?? 0);
    $packageName = influencer_coupon_clean_text($order['paquete_nombre'] ?? null, 180);
    $currency = influencer_coupon_clean_text($order['moneda'] ?? null, 20);
    $totalSale = round((float) ($order['precio'] ?? 0), 2);
    $commissionTotal = influencer_coupon_commission_total($totalSale, $commissionPercent);
    $paymentState = 'pendiente';

    $insert = $mysqli->prepare('INSERT INTO cupones_influencer_ventas (cupon_id, pedido_id, nombre_influencer, codigo_cupon, telefono_influencer, email_influencer, comision_porcentaje, paquete_vendido, moneda, total_pedido, total_comision, estado_pago) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    if (!$insert) {
        return;
    }

    $insert->bind_param(
        'iissssdssdds',
        $couponId,
        $orderId,
        $influencerName,
        $couponCode,
        $phone,
        $email,
        $commissionPercent,
        $packageName,
        $currency,
        $totalSale,
        $commissionTotal,
        $paymentState
    );
    $insert->execute();
    $insert->close();

    $updateOrder = $mysqli->prepare("UPDATE pedidos SET estado_pago_influencer = CASE WHEN estado_pago_influencer = 'pagado' THEN 'pagado' ELSE 'pendiente' END WHERE id = ?");
    if ($updateOrder) {
        $updateOrder->bind_param('i', $orderId);
        $updateOrder->execute();
        $updateOrder->close();
    }
}

function apply_coupon_to_price(float $price, array $coupon): float {
    $discounted = $price;
    $value = floatval($coupon['valor_descuento'] ?? 0);
    $type = $coupon['tipo_descuento'] ?? 'porcentaje';
    if ($value <= 0) return $price;
    if ($type === 'fijo') {
        $discounted = max(0, $price - $value);
    } else {
        $discounted = max(0, $price - ($price * ($value / 100)));
    }
    return $discounted;
}

function format_order_price_value(float $price, string $currencyCode): string {
    $currency = currency_find_by_code($currencyCode);
    $label = strtoupper(trim($currencyCode));

    if (is_array($currency) && !empty($currency['clave'])) {
        $label = trim((string) $currency['clave']);
    }

    $normalizedLabel = strtoupper(str_replace(['.', ' '], '', $label));
    if ($normalizedLabel === 'VES' || $normalizedLabel === 'VEF' || $normalizedLabel === 'BS' || $normalizedLabel === 'BSS') {
        $label = 'Bs.';
    }

    return trim($label) . ' ' . currency_format_amount($price, $currency);
}

function order_purchase_quantity(array $order): int {
    $quantity = (int) ($order['cantidad_compra'] ?? 1);
    return $quantity > 0 ? $quantity : 1;
}

function order_purchase_quantity_text(int $quantity): string {
    $safeQuantity = max(1, $quantity);
    return $safeQuantity === 1 ? '1 recarga' : $safeQuantity . ' recargas';
}

function api_discord_orders_enabled(): bool {
    return trim((string) store_config_get('api_discord', '0')) === '1';
}

function normalize_api_discord_order_status(?string $status): string {
    $normalized = strtolower(trim((string) $status));
    $allowed = ['ready', 'queued', 'sent', 'processing', 'confirmed', 'failed', 'review', 'cancelled'];
    return in_array($normalized, $allowed, true) ? $normalized : '';
}

function api_discord_order_status_default_message(string $status): string {
    switch (normalize_api_discord_order_status($status)) {
        case 'ready':
            return 'La orden quedó preparada para enviarse al flujo de Discord.';
        case 'queued':
            return 'La orden quedó en cola para enviarse al canal de Discord.';
        case 'sent':
            return 'El comando fue enviado al canal de Discord y está pendiente de respuesta.';
        case 'processing':
            return 'El flujo de Discord reporta que la orden sigue en proceso.';
        case 'confirmed':
            return 'El flujo de Discord confirmó la orden.';
        case 'failed':
            return 'El flujo de Discord reportó un fallo al procesar la orden.';
        case 'review':
            return 'La orden requiere revisión manual en el flujo de Discord.';
        case 'cancelled':
            return 'El flujo de Discord reportó que la orden fue cancelada.';
        default:
            return '';
    }
}

function build_api_discord_order_insert_data(mysqli $mysqli, int $gameId, string $packageProvider = ''): array {
    if ($gameId <= 0 || $packageProvider !== 'discord' || !api_discord_orders_enabled()) {
        return [];
    }

    $commandKey = game_discord_api_command($mysqli, $gameId);
    if ($commandKey === '') {
        return [];
    }

    $commandDefinition = api_discord_find_command($commandKey);
    if (!is_array($commandDefinition) || trim((string) ($commandDefinition['kind'] ?? '')) !== 'topup') {
        return [];
    }

    return [
        'api_discord_command_key' => $commandKey,
        'api_discord_command_template' => trim((string) ($commandDefinition['template'] ?? '')),
        'api_discord_status' => 'ready',
        'api_discord_attempts' => 0,
        'api_discord_requires_review' => 0,
    ];
}

function order_uses_api_discord(array $order): bool {
    return trim((string) ($order['api_discord_command_key'] ?? '')) !== '';
}

function build_api_discord_order_payload(array $order): ?array {
    if (!order_uses_api_discord($order)) {
        return null;
    }

    return [
        'enabled' => true,
        'command_key' => trim((string) ($order['api_discord_command_key'] ?? '')),
        'command_template' => trim((string) ($order['api_discord_command_template'] ?? '')),
        'status' => normalize_api_discord_order_status($order['api_discord_status'] ?? '') ?: 'ready',
        'message_id' => trim((string) ($order['api_discord_message_id'] ?? '')),
        'http_status' => isset($order['api_discord_http_status']) ? (int) $order['api_discord_http_status'] : 0,
        'attempts' => max(0, (int) ($order['api_discord_attempts'] ?? 0)),
        'requires_review' => (int) ($order['api_discord_requires_review'] ?? 0) === 1,
        'last_attempt_at' => trim((string) ($order['api_discord_last_attempt_at'] ?? '')),
        'sent_at' => trim((string) ($order['api_discord_sent_at'] ?? '')),
    ];
}

function append_api_discord_response(array $payload, array $order): array {
    $discordPayload = build_api_discord_order_payload($order);
    if ($discordPayload !== null) {
        $payload['discord'] = $discordPayload;
    }

    return $payload;
}

function api_discord_order_player_value(array $playerFields, array $aliases): string {
    foreach ($aliases as $alias) {
        $normalizedAlias = normalize_player_field_key($alias);
        if ($normalizedAlias === '') {
            continue;
        }

        $value = trim((string) ($playerFields[$normalizedAlias] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }

    return '';
}

function api_discord_order_package_uses_named_amount(array $order): bool {
    $packageName = trim((string) ($order['paquete_nombre'] ?? ''));
    if ($packageName === '') {
        return false;
    }

    $normalizedName = function_exists('mb_strtolower')
        ? mb_strtolower($packageName, 'UTF-8')
        : strtolower($packageName);

    foreach ([
        'pase',
        'pass',
        'weekly',
        'semanal',
        'monthly',
        'mensual',
        'starlight',
        'blessing',
        'membership',
        'member',
        'membres',
        'tarjeta',
        'card',
        'supply',
        'privilege',
        'prime',
        'suscripcion',
        'subscription',
    ] as $keyword) {
        if (str_contains($normalizedName, $keyword)) {
            return true;
        }
    }

    return false;
}

function api_discord_order_package_amount_from_name(array $order): string {
    $packageName = trim((string) ($order['paquete_nombre'] ?? ''));
    if ($packageName === '') {
        return '';
    }

    if (preg_match('/(\d+\s*\+\s*\d+)/u', $packageName, $compoundMatch) === 1) {
        return preg_replace('/\s+/u', '', (string) ($compoundMatch[1] ?? '')) ?? '';
    }

    if (preg_match('/\b(\d{1,6})\b/u', $packageName, $numericMatch) === 1) {
        return trim((string) ($numericMatch[1] ?? ''));
    }

    return '';
}

function api_discord_normalize_package_token(string $value): string {
    $normalized = function_exists('mb_strtolower')
        ? mb_strtolower(trim($value), 'UTF-8')
        : strtolower(trim($value));

    $normalized = strtr($normalized, [
        'á' => 'a',
        'é' => 'e',
        'í' => 'i',
        'ó' => 'o',
        'ú' => 'u',
        'ü' => 'u',
        'ñ' => 'n',
    ]);
    $normalized = preg_replace('/\s*\+\s*/u', '+', $normalized) ?? $normalized;

    return preg_replace('/[^a-z0-9\+]+/u', '', $normalized) ?? '';
}

function api_discord_mobile_legends_amount_token(array $order): string {
    $candidates = [
        trim((string) ($order['paquete_nombre'] ?? '')),
        trim((string) ($order['paquete_cantidad'] ?? '')),
    ];

    foreach ($candidates as $candidate) {
        $normalized = api_discord_normalize_package_token($candidate);
        if ($normalized === '') {
            continue;
        }

        if ($normalized === '172+pase' || (str_contains($normalized, '172') && str_contains($normalized, 'pase'))) {
            return '172+pase';
        }
        if ($normalized === '86+doble' || (str_contains($normalized, '86') && str_contains($normalized, 'doble'))) {
            return '86+doble';
        }
        if (str_contains($normalized, 'semanalelite')) {
            return 'semanalelite';
        }
        if (str_contains($normalized, 'mensualelite')) {
            return 'mensualelite';
        }
        if (str_contains($normalized, 'triple')) {
            return 'triple';
        }
        if (str_contains($normalized, 'doble')) {
            return 'doble';
        }
        if (str_contains($normalized, 'crep')) {
            return 'crep';
        }
        if (str_contains($normalized, 'pase')) {
            return 'pase';
        }
        if ($normalized !== '1' && preg_match('/^\d+(?:\+\d+)?$/', $normalized) === 1) {
            return $normalized;
        }
    }

    $packageAmount = trim((string) ($order['paquete_cantidad'] ?? ''));
    if ($packageAmount !== '' && $packageAmount !== '1') {
        return $packageAmount;
    }

    return '';
}

function resolve_api_discord_order_param_value(array $order, array $playerFields, string $param): string {
    $normalizedParam = normalize_player_field_key($param);
    if ($normalizedParam === '') {
        return '';
    }

    $directValue = trim((string) ($playerFields[$normalizedParam] ?? ''));
    if ($directValue !== '') {
        return $directValue;
    }

    switch ($normalizedParam) {
        case 'cantidad':
        case 'amount':
            if (trim((string) ($order['api_discord_command_key'] ?? '')) === 'mobile_legends_topup') {
                $mobileLegendsAmount = api_discord_mobile_legends_amount_token($order);
                if ($mobileLegendsAmount !== '') {
                    return $mobileLegendsAmount;
                }
            }

            $packageAmount = trim((string) ($order['paquete_cantidad'] ?? ''));

            if (api_discord_order_package_uses_named_amount($order)) {
                if ($packageAmount !== '' && $packageAmount !== '1') {
                    return $packageAmount;
                }

                $packageName = trim((string) ($order['paquete_nombre'] ?? ''));
                if ($packageName !== '') {
                    return $packageName;
                }
            }

            if ($packageAmount !== '' && $packageAmount !== '1') {
                return $packageAmount;
            }

            $derivedAmount = api_discord_order_package_amount_from_name($order);
            if ($derivedAmount !== '') {
                return $derivedAmount;
            }

            if ($packageAmount !== '') {
                return $packageAmount;
            }

            $legacyAmount = trim((string) ($order['monto_ff'] ?? ''));
            if ($legacyAmount !== '') {
                return $legacyAmount;
            }

            return trim((string) ($order['cantidad'] ?? ''));

        case 'correo':
        case 'email':
        case 'mail':
            return trim((string) ($order['email'] ?? ''));

        case 'uid':
        case 'id':
        case 'userid':
        case 'playerid':
        case 'user':
            $identifier = api_discord_order_player_value($playerFields, ['uid', 'id', 'player_id', 'playerid', 'user_id', 'userid', 'id_juego', 'input1']);
            if ($identifier !== '') {
                return $identifier;
            }

            return trim((string) ($order['user_identifier'] ?? ''));

        case 'sv':
        case 'server':
        case 'servidor':
        case 'zone':
        case 'zona':
            return api_discord_order_player_value($playerFields, ['sv', 'server', 'server_id', 'serverid', 'zone', 'zone_id', 'zoneid', 'zona', 'input2']);
    }

    $orderValue = trim((string) ($order[$normalizedParam] ?? ''));
    if ($orderValue !== '') {
        return $orderValue;
    }

    return api_discord_order_player_value($playerFields, [$normalizedParam]);
}

function build_api_discord_order_command_values(array $order): array {
    $playerFields = order_player_fields_from_json((string) ($order['player_fields_json'] ?? ''));
    $command = api_discord_find_command((string) ($order['api_discord_command_key'] ?? ''));
    $params = is_array($command['params'] ?? null) ? array_values($command['params']) : [];
    $values = [];

    foreach ($params as $param) {
        $normalizedParam = normalize_player_field_key((string) $param);
        if ($normalizedParam === '') {
            continue;
        }

        $values[$normalizedParam] = resolve_api_discord_order_param_value($order, $playerFields, $normalizedParam);
    }

    return $values;
}

function api_discord_dispatch_response_preview(?string $body): string {
    if (!is_string($body) || trim($body) === '') {
        return '';
    }

    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        return trim($body);
    }

    $message = trim((string) ($decoded['message'] ?? ''));
    if ($message !== '') {
        return $message;
    }

    return trim($body);
}

function execute_api_discord_order_dispatch(array $order): array {
    $config = api_discord_config();
    $commandKey = trim((string) ($order['api_discord_command_key'] ?? ''));
    $command = api_discord_find_command($commandKey);
    $purchaseQuantity = order_purchase_quantity($order);
    $dispatchTimestamp = date('Y-m-d H:i:s');

    if (!$config['enabled']) {
        $payload = ['ok' => false, 'message' => 'API Discord no está activa para esta tienda.', 'command_key' => $commandKey];
        return [
            'ok' => true,
            'sent' => false,
            'provider_flow' => 'manual_review',
            'provider_status' => 'review',
            'provider_reference' => '',
            'provider_message' => 'API Discord no está activa para esta tienda.',
            'http_status' => 0,
            'message_id' => '',
            'requires_review' => 1,
            'attempts_delta' => 0,
            'last_attempt_at' => $dispatchTimestamp,
            'sent_at' => '',
            'response_body' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
        ];
    }

    if ($config['webhook_url'] === '' || !api_discord_validate_webhook_url($config['webhook_url'])) {
        $payload = ['ok' => false, 'message' => 'El webhook de API Discord no está configurado correctamente.', 'command_key' => $commandKey];
        return [
            'ok' => true,
            'sent' => false,
            'provider_flow' => 'manual_review',
            'provider_status' => 'review',
            'provider_reference' => '',
            'provider_message' => 'El webhook de API Discord no está configurado correctamente.',
            'http_status' => 0,
            'message_id' => '',
            'requires_review' => 1,
            'attempts_delta' => 0,
            'last_attempt_at' => $dispatchTimestamp,
            'sent_at' => '',
            'response_body' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
        ];
    }

    if (!is_array($command) || trim((string) ($command['kind'] ?? '')) !== 'topup') {
        $payload = ['ok' => false, 'message' => 'La orden no tiene un comando Discord de recarga válido.', 'command_key' => $commandKey];
        return [
            'ok' => true,
            'sent' => false,
            'provider_flow' => 'manual_review',
            'provider_status' => 'review',
            'provider_reference' => '',
            'provider_message' => 'La orden no tiene un comando Discord de recarga válido.',
            'http_status' => 0,
            'message_id' => '',
            'requires_review' => 1,
            'attempts_delta' => 0,
            'last_attempt_at' => $dispatchTimestamp,
            'sent_at' => '',
            'response_body' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
        ];
    }

    $renderedCommand = api_discord_render_command_text($command, build_api_discord_order_command_values($order));
    $commandText = trim((string) ($renderedCommand['command_text'] ?? ''));
    if (empty($renderedCommand['ok']) || $commandText === '') {
        $payload = [
            'ok' => false,
            'message' => trim((string) ($renderedCommand['message'] ?? 'No se pudo construir el comando Discord.')),
            'command_key' => $commandKey,
            'missing_params' => array_values((array) ($renderedCommand['missing_params'] ?? [])),
        ];
        return [
            'ok' => true,
            'sent' => false,
            'provider_flow' => 'manual_review',
            'provider_status' => 'review',
            'provider_reference' => '',
            'provider_message' => trim((string) ($renderedCommand['message'] ?? 'No se pudo construir el comando Discord.')),
            'http_status' => 0,
            'message_id' => '',
            'requires_review' => 1,
            'attempts_delta' => 0,
            'last_attempt_at' => $dispatchTimestamp,
            'sent_at' => '',
            'response_body' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
        ];
    }

    if (!empty($config['dry_run'])) {
        $dryRunMessage = 'Modo preventivo activo: el comando Discord fue generado pero no se envio. Desactiva "Mantener modo preventivo" en Configuracion > API Discord para permitir recargas automaticas reales.';
        $payload = [
            'ok' => true,
            'dry_run' => true,
            'message' => $dryRunMessage,
            'command_key' => $commandKey,
            'command_text' => $commandText,
            'quantity' => $purchaseQuantity,
        ];
        return [
            'ok' => true,
            'sent' => false,
            'provider_flow' => 'manual_review',
            'provider_status' => 'review',
            'provider_reference' => '',
            'provider_message' => $dryRunMessage,
            'http_status' => 0,
            'message_id' => '',
            'requires_review' => 1,
            'attempts_delta' => 0,
            'last_attempt_at' => $dispatchTimestamp,
            'sent_at' => '',
            'response_body' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
        ];
    }

    $attemptResults = [];
    $attemptsDelta = 0;
    $successCount = 0;
    $lastHttpStatus = 0;
    $lastMessageId = '';
    $sentAt = '';

    for ($index = 1; $index <= $purchaseQuantity; $index++) {
        $response = api_discord_send_webhook_message($config['webhook_url'], $commandText, [
            'timeout' => $config['timeout'],
            'username' => $config['username'],
            'avatar_url' => $config['avatar_url'],
            'wait' => true,
        ]);
        $attemptsDelta++;
        $lastHttpStatus = (int) ($response['status'] ?? 0);
        $responseMessageId = api_discord_extract_message_id($response);
        if ($responseMessageId !== '') {
            $lastMessageId = $responseMessageId;
        }

        $attemptResults[] = [
            'index' => $index,
            'ok' => !empty($response['ok']),
            'http_status' => $lastHttpStatus,
            'message_id' => $responseMessageId,
            'body' => trim((string) ($response['body'] ?? '')),
            'error' => trim((string) ($response['error'] ?? '')),
            'ssl_fallback_used' => !empty($response['ssl_fallback_used']),
        ];

        if (!empty($response['ok'])) {
            $successCount++;
            if ($sentAt === '') {
                $sentAt = $dispatchTimestamp;
            }
        }
    }

    $responsePayload = [
        'ok' => $successCount > 0,
        'dry_run' => false,
        'command_key' => $commandKey,
        'command_text' => $commandText,
        'quantity' => $purchaseQuantity,
        'attempts' => $attemptResults,
    ];

    if ($successCount === $purchaseQuantity) {
        $responsePayload['message'] = 'Tu pago fue verificado y la orden fue enviada al proveedor para ' . order_purchase_quantity_text($purchaseQuantity) . '. Estamos esperando la confirmación final automática.';
        return [
            'ok' => true,
            'sent' => true,
            'provider_flow' => 'accepted',
            'provider_status' => 'sent',
            'provider_reference' => $lastMessageId,
            'provider_message' => $responsePayload['message'],
            'http_status' => $lastHttpStatus,
            'message_id' => $lastMessageId,
            'requires_review' => 0,
            'attempts_delta' => $attemptsDelta,
            'last_attempt_at' => $dispatchTimestamp,
            'sent_at' => $sentAt,
            'response_body' => json_encode($responsePayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
        ];
    }

    if ($successCount > 0) {
        $responsePayload['message'] = 'Discord aceptó ' . $successCount . ' de ' . $purchaseQuantity . ' envíos. La orden quedó para revisión manual.';
        return [
            'ok' => true,
            'sent' => true,
            'provider_flow' => 'manual_review',
            'provider_status' => 'review',
            'provider_reference' => $lastMessageId,
            'provider_message' => $responsePayload['message'],
            'http_status' => $lastHttpStatus,
            'message_id' => $lastMessageId,
            'requires_review' => 1,
            'attempts_delta' => $attemptsDelta,
            'last_attempt_at' => $dispatchTimestamp,
            'sent_at' => $sentAt,
            'response_body' => json_encode($responsePayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
        ];
    }

    $lastAttempt = $attemptResults[count($attemptResults) - 1] ?? [];
    $errorDetail = trim((string) ($lastAttempt['error'] ?? ''));
    if ($errorDetail === '') {
        $errorDetail = trim((string) ($lastAttempt['body'] ?? ''));
    }
    $responsePayload['message'] = 'No se pudo enviar el comando Discord.' . ($errorDetail !== '' ? ' ' . $errorDetail : '');

    return [
        'ok' => true,
        'sent' => false,
        'provider_flow' => 'manual_review',
        'provider_status' => 'failed',
        'provider_reference' => '',
        'provider_message' => $responsePayload['message'],
        'http_status' => $lastHttpStatus,
        'message_id' => '',
        'requires_review' => 1,
        'attempts_delta' => $attemptsDelta,
        'last_attempt_at' => $dispatchTimestamp,
        'sent_at' => '',
        'response_body' => json_encode($responsePayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
    ];
}

function persist_api_discord_dispatch_result(mysqli $mysqli, array $order, array $dispatch, string $expectedState, ?string $targetState = null, ?string $reference = null, ?string $phone = null): bool {
    $orderId = (int) ($order['id'] ?? 0);
    if ($orderId <= 0) {
        return false;
    }

    $targetState = $targetState ?? trim((string) ($order['estado'] ?? $expectedState));
    $referenceToStore = trim((string) ($reference ?? ($order['numero_referencia'] ?? '')));
    $phoneToStore = trim((string) ($phone ?? ($order['telefono_contacto'] ?? '')));
    $messageId = trim((string) ($dispatch['message_id'] ?? ''));
    if ($messageId === '') {
        $messageId = trim((string) ($order['api_discord_message_id'] ?? ''));
    }

    $sentAt = trim((string) ($dispatch['sent_at'] ?? ''));
    if ($sentAt === '') {
        $sentAt = trim((string) ($order['api_discord_sent_at'] ?? ''));
    }

    $lastAttemptAt = trim((string) ($dispatch['last_attempt_at'] ?? ''));
    if ($lastAttemptAt === '') {
        $lastAttemptAt = trim((string) ($order['api_discord_last_attempt_at'] ?? ''));
    }

    $attemptsTotal = max(0, (int) ($order['api_discord_attempts'] ?? 0) + (int) ($dispatch['attempts_delta'] ?? 0));
    $status = normalize_api_discord_order_status((string) ($dispatch['provider_status'] ?? '')) ?: 'review';
    $httpStatus = (int) ($dispatch['http_status'] ?? 0);
    $responseBody = trim((string) ($dispatch['response_body'] ?? ''));
    $requiresReview = !empty($dispatch['requires_review']) ? 1 : 0;

    $stmt = $mysqli->prepare("UPDATE pedidos SET numero_referencia = ?, telefono_contacto = ?, api_discord_status = ?, api_discord_message_id = ?, api_discord_http_status = ?, api_discord_response_body = ?, api_discord_attempts = ?, api_discord_requires_review = ?, api_discord_last_attempt_at = ?, api_discord_sent_at = ?, estado = ? WHERE id = ? AND estado = ? LIMIT 1");
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('ssssisiisssis', $referenceToStore, $phoneToStore, $status, $messageId, $httpStatus, $responseBody, $attemptsTotal, $requiresReview, $lastAttemptAt, $sentAt, $targetState, $orderId, $expectedState);
    $ok = $stmt->execute();
    $stmt->close();

    return $ok;
}

function api_discord_listener_payload_from_request(): array {
    $payload = [];
    $rawInput = file_get_contents('php://input');
    if (is_string($rawInput) && trim($rawInput) !== '') {
        $decoded = json_decode($rawInput, true);
        if (is_array($decoded)) {
            $payload = $decoded;
        }
    }

    if (!empty($_POST)) {
        $payload = array_merge($payload, $_POST);
    }

    return is_array($payload) ? $payload : [];
}

function api_discord_listener_token_from_request(array $payload): string {
    $authorization = trim((string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
    $bearerToken = '';
    if ($authorization !== '' && preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)) {
        $bearerToken = trim((string) ($matches[1] ?? ''));
    }

    $candidates = [
        trim((string) ($_SERVER['HTTP_X_DISCORD_LISTENER_TOKEN'] ?? '')),
        trim((string) ($_SERVER['HTTP_X_API_DISCORD_TOKEN'] ?? '')),
        $bearerToken,
        trim((string) ($payload['listener_token'] ?? '')),
        trim((string) ($payload['token'] ?? '')),
    ];

    foreach ($candidates as $candidate) {
        $normalized = api_discord_normalize_listener_token($candidate);
        if ($normalized !== '') {
            return $normalized;
        }
    }

    return '';
}

function api_discord_listener_bool($value): int {
    if (is_bool($value)) {
        return $value ? 1 : 0;
    }

    $normalized = strtolower(trim((string) $value));
    return in_array($normalized, ['1', 'true', 'yes', 'si', 'on'], true) ? 1 : 0;
}

function fetch_game_by_id(mysqli $mysqli, int $gameId): ?array {
    if ($gameId <= 0) {
        return null;
    }

    $stmt = $mysqli->prepare("SELECT * FROM juegos WHERE id = ? LIMIT 1");
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('i', $gameId);
    if (!$stmt->execute()) {
        $stmt->close();
        return null;
    }

    $result = $stmt->get_result();
    $game = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    return is_array($game) ? $game : null;
}

function find_game_by_discord_catalog_message_id(mysqli $mysqli, string $messageId): ?array {
    $normalizedMessageId = trim($messageId);
    if ($normalizedMessageId === '') {
        return null;
    }

    $stmt = $mysqli->prepare("SELECT * FROM juegos WHERE api_discord_catalog_message_id = ? ORDER BY id DESC LIMIT 1");
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('s', $normalizedMessageId);
    if (!$stmt->execute()) {
        $stmt->close();
        return null;
    }

    $result = $stmt->get_result();
    $game = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    return is_array($game) ? $game : null;
}

function api_discord_catalog_text_from_payload(array $payload): string {
    foreach (['catalog_text', 'provider_message', 'message', 'detail', 'content', 'raw_text'] as $key) {
        $value = trim((string) ($payload[$key] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }

    $embedTexts = [];
    $embeds = $payload['embeds'] ?? $payload['embed'] ?? [];
    if (is_array($embeds)) {
        $embedList = array_keys($embeds) === range(0, count($embeds) - 1) ? $embeds : [$embeds];
        foreach ($embedList as $embed) {
            if (!is_array($embed)) {
                continue;
            }

            foreach (['title', 'description'] as $fieldKey) {
                $value = trim((string) ($embed[$fieldKey] ?? ''));
                if ($value !== '') {
                    $embedTexts[] = $value;
                }
            }

            foreach ((array) ($embed['fields'] ?? []) as $field) {
                if (!is_array($field)) {
                    continue;
                }

                $value = trim((string) ($field['value'] ?? ''));
                if ($value !== '') {
                    $embedTexts[] = $value;
                }
            }
        }
    }

    return trim(implode("\n", $embedTexts));
}

function api_discord_catalog_items_from_payload(array $payload): array {
    $rawItems = $payload['catalog_items'] ?? $payload['items'] ?? [];
    if (is_string($rawItems) && trim($rawItems) !== '') {
        $decodedItems = json_decode($rawItems, true);
        if (is_array($decodedItems)) {
            $rawItems = $decodedItems;
        }
    }

    if (is_array($rawItems) && $rawItems !== []) {
        $normalizedItems = api_discord_normalize_catalog_items($rawItems);
        if ($normalizedItems !== []) {
            return $normalizedItems;
        }
    }

    $rawText = api_discord_catalog_text_from_payload($payload);
    if ($rawText === '') {
        return [];
    }

    return api_discord_parse_catalog_text($rawText);
}

function persist_api_discord_game_catalog(mysqli $mysqli, array $game, array $payload): array {
    $gameId = (int) ($game['id'] ?? 0);
    if ($gameId <= 0) {
        throw new RuntimeException('Juego inválido para sincronizar catálogo Discord.');
    }

    $catalogItems = api_discord_catalog_items_from_payload($payload);
    if ($catalogItems === []) {
        throw new RuntimeException('No se encontraron paquetes válidos en la respuesta de precios de Discord.');
    }

    $rawText = api_discord_catalog_text_from_payload($payload);
    $messageId = trim((string) ($payload['source_message_id'] ?? $payload['message_id'] ?? $payload['discord_message_id'] ?? $game['api_discord_catalog_message_id'] ?? ''));
    $catalogStatus = trim((string) ($payload['catalog_status'] ?? 'ready')) ?: 'ready';
    $catalogJson = json_encode($catalogItems, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($catalogJson) || $catalogJson === '') {
        throw new RuntimeException('No se pudo serializar el catálogo de precios de Discord.');
    }

    $stmt = $mysqli->prepare("UPDATE juegos SET api_discord_catalog_json = ?, api_discord_catalog_raw = ?, api_discord_catalog_status = ?, api_discord_catalog_message_id = ?, api_discord_catalog_updated_at = NOW() WHERE id = ? LIMIT 1");
    if (!$stmt) {
        throw new RuntimeException('No se pudo preparar la actualización del catálogo de Discord.');
    }

    $stmt->bind_param('ssssi', $catalogJson, $rawText, $catalogStatus, $messageId, $gameId);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('No se pudo guardar el catálogo de precios de Discord.');
    }
    $stmt->close();

    return [
        'items' => $catalogItems,
        'message_id' => $messageId,
        'status' => $catalogStatus,
    ];
}

function resolve_api_discord_listener_order(mysqli $mysqli, int $orderId, string $messageId): ?array {
    if ($orderId > 0) {
        $order = fetch_order_by_id($mysqli, $orderId);
        return is_array($order) && order_uses_api_discord($order) ? $order : null;
    }

    if ($messageId === '') {
        return null;
    }

    $stmt = $mysqli->prepare("SELECT * FROM pedidos WHERE api_discord_message_id = ? ORDER BY id DESC LIMIT 1");
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('s', $messageId);
    if (!$stmt->execute()) {
        $stmt->close();
        return null;
    }

    $result = $stmt->get_result();
    $order = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    return is_array($order) && order_uses_api_discord($order) ? $order : null;
}

function api_discord_listener_resolve_local_status(string $discordStatus, string $requestedStatus, array $order): string {
    switch ($discordStatus) {
        case 'confirmed':
            return 'enviado';
        case 'cancelled':
            return 'cancelado';
    }

    $normalizedRequested = strtolower(trim($requestedStatus));
    if (in_array($normalizedRequested, ['pendiente', 'pagado', 'enviado', 'cancelado'], true)) {
        return $normalizedRequested;
    }

    $current = strtolower(trim((string) ($order['estado'] ?? 'pagado')));
    return in_array($current, ['pendiente', 'pagado', 'enviado', 'cancelado'], true) ? $current : 'pagado';
}

function persist_api_discord_listener_update(mysqli $mysqli, array $order, array $payload, string $source = 'discord_listener'): array {
    $orderId = (int) ($order['id'] ?? 0);
    if ($orderId <= 0) {
        throw new RuntimeException('Pedido inválido para la correlación de Discord.');
    }

    $discordStatus = normalize_api_discord_order_status((string) ($payload['status'] ?? $payload['api_discord_status'] ?? ''));
    if ($discordStatus === '') {
        throw new RuntimeException('El listener de Discord requiere un estado válido.');
    }

    $currentMessageId = trim((string) ($order['api_discord_message_id'] ?? ''));
    $messageId = trim((string) ($payload['source_message_id'] ?? $payload['message_id'] ?? $payload['discord_message_id'] ?? ''));
    if ($messageId === '') {
        $messageId = $currentMessageId;
    }

    $providerMessage = trim((string) ($payload['provider_message'] ?? $payload['message'] ?? $payload['detail'] ?? ''));
    if ($providerMessage === '') {
        $providerMessage = api_discord_order_status_default_message($discordStatus);
    }

    $httpStatus = (int) ($payload['http_status'] ?? $payload['status_code'] ?? ($order['api_discord_http_status'] ?? 0));
    $requiresReview = array_key_exists('requires_review', $payload)
        ? api_discord_listener_bool($payload['requires_review'])
        : (in_array($discordStatus, ['failed', 'review'], true) ? 1 : 0);
    $targetLocalStatus = api_discord_listener_resolve_local_status($discordStatus, (string) ($payload['local_status'] ?? ''), $order);
    $sentAt = trim((string) ($order['api_discord_sent_at'] ?? ''));
    if ($sentAt === '' && in_array($discordStatus, ['sent', 'processing', 'confirmed'], true)) {
        $sentAt = date('Y-m-d H:i:s');
    }

    $listenerSnapshot = [
        'source' => trim($source) !== '' ? trim($source) : 'discord_listener',
        'received_at' => date('Y-m-d H:i:s'),
        'status' => $discordStatus,
        'message' => $providerMessage,
        'payload' => $payload,
    ];
    $previousResponse = trim((string) ($order['api_discord_response_body'] ?? ''));
    if ($previousResponse !== '') {
        $decodedPrevious = json_decode($previousResponse, true);
        $listenerSnapshot['previous'] = is_array($decodedPrevious) ? $decodedPrevious : $previousResponse;
    }
    $responseBody = json_encode($listenerSnapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';

    $lastAttemptAt = date('Y-m-d H:i:s');
    $stmt = $mysqli->prepare("UPDATE pedidos SET estado = ?, api_discord_status = ?, api_discord_message_id = ?, api_discord_http_status = ?, api_discord_response_body = ?, api_discord_requires_review = ?, api_discord_last_attempt_at = ?, api_discord_sent_at = ?, ff_api_mensaje = ? WHERE id = ? LIMIT 1");
    if (!$stmt) {
        throw new RuntimeException('No se pudo preparar la actualización del listener de Discord.');
    }

    $stmt->bind_param('sssisssssi', $targetLocalStatus, $discordStatus, $messageId, $httpStatus, $responseBody, $requiresReview, $lastAttemptAt, $sentAt, $providerMessage, $orderId);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('No se pudo guardar la correlación de Discord en la orden.');
    }
    $stmt->close();

    return [
        'local_status' => $targetLocalStatus,
        'provider_status' => $discordStatus,
        'provider_message' => $providerMessage,
        'provider_reference' => $messageId,
        'requires_review' => $requiresReview,
    ];
}

function handle_api_discord_order_status_side_effects(mysqli $mysqli, string $previousLocalStatus, array $updatedOrder, array $statusResult): void {
    $updatedLocalStatus = strtolower(trim((string) ($updatedOrder['estado'] ?? ($statusResult['local_status'] ?? ''))));
    $providerReference = trim((string) ($statusResult['provider_reference'] ?? ''));
    $providerMessage = trim((string) ($statusResult['provider_message'] ?? ''));

    if ($updatedLocalStatus === 'enviado' && $previousLocalStatus !== 'enviado') {
        $paymentMethodName = trim((string) ($updatedOrder['metodo_pago'] ?? 'Pago'));
        $referenceNumber = trim((string) ($updatedOrder['numero_referencia'] ?? ''));
        $phone = trim((string) ($updatedOrder['telefono_contacto'] ?? ''));
        win_points_handle_order_status_change($mysqli, (int) ($updatedOrder['id'] ?? 0), 'enviado');
        recharge_notifications_emit_for_order($mysqli, $updatedOrder);
        notify_free_fire_recharge_success($mysqli, $updatedOrder, $paymentMethodName, $referenceNumber, $phone, $providerReference, $providerMessage);
        return;
    }

    if ($updatedLocalStatus === 'cancelado' && $previousLocalStatus !== 'cancelado') {
        recharge_notifications_emit_for_order($mysqli, $updatedOrder);
        notify_catalog_purchase_cancelled($mysqli, $updatedOrder, $providerReference, $providerMessage, null);
    }
}

function json_error(string $message, int $code = 400): void {
    json_response(['ok' => false, 'message' => $message], $code);
}

function json_response(array $payload, int $code = 200, ?callable $afterSend = null): void {
    http_response_code($code);
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        http_response_code(500);
        $json = json_encode([
            'ok' => false,
            'message' => 'No se pudo generar una respuesta JSON válida.',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{"ok":false,"message":"No se pudo generar una respuesta JSON válida."}';
    }

    while (ob_get_level() > 0) {
        @ob_end_clean();
    }

    header('Content-Type: application/json');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Content-Length: ' . strlen($json));

    if (session_status() === PHP_SESSION_ACTIVE) {
        @session_write_close();
    }

    echo $json;

    if ($afterSend === null) {
        exit;
    }

    ignore_user_abort(true);

    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        flush();
    }

    if ($afterSend !== null) {
        try {
            $afterSend();
        } catch (Throwable $e) {
            error_log('TVG background task error: ' . $e->getMessage());
        }
    }

    exit;
}

set_exception_handler(static function (Throwable $e): void {
    error_log('TVG pedidos uncaught exception: ' . $e->getMessage());
    json_response([
        'ok' => false,
        'message' => 'Ocurrió un error interno al procesar la solicitud.',
    ], 500);
});

register_shutdown_function(static function (): void {
    $error = error_get_last();
    if (!$error) {
        return;
    }

    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (!in_array($error['type'] ?? 0, $fatalTypes, true)) {
        return;
    }

    error_log('TVG pedidos fatal shutdown: ' . ($error['message'] ?? 'Fatal error'));

    if (!headers_sent()) {
        http_response_code(500);
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        header('Content-Type: application/json');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        echo json_encode([
            'ok' => false,
            'message' => 'Ocurrió un error fatal al procesar la solicitud.',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
});

function inline_embedded_images_for_html(string $html, array $embeddedImages): string {
    foreach ($embeddedImages as $image) {
        $cid = trim((string) ($image['cid'] ?? ''));
        $path = (string) ($image['path'] ?? '');
        $mime = trim((string) ($image['mime'] ?? ''));
        if ($cid === '' || $path === '' || !is_file($path) || !is_readable($path)) {
            continue;
        }

        $binary = @file_get_contents($path);
        if ($binary === false) {
            continue;
        }

        $detectedMime = $mime !== '' ? $mime : detect_local_file_mime_type($path);
        $html = str_replace('cid:' . $cid, 'data:' . $detectedMime . ';base64,' . base64_encode($binary), $html);
    }

    return $html;
}

function send_app_mail(string $to, string $subject, string $html, ?string $from = null, array $embeddedImages = []): void {
    global $mysqli;
    $baseUrl = app_base_url();
    $baseHost = strtolower(trim((string) parse_url($baseUrl, PHP_URL_HOST)));
    if ($baseHost !== '' && !is_public_callback_host($baseHost)) {
        error_log('TVG mail skipped on local/private host: ' . $subject);
        return;
    }

    $settings = isset($mysqli) && $mysqli instanceof mysqli
        ? load_mail_settings($mysqli)
        : [
            'correo_corporativo' => '',
            'smtp_host' => '',
            'smtp_user' => '',
            'smtp_pass' => '',
            'smtp_port' => 587,
            'smtp_secure' => 'tls',
        ];
    $fromAddr = $from ?: ($settings['correo_corporativo'] ?: $settings['smtp_user']);

    try {
        require_once __DIR__ . '/../includes/PHPMailerAutoload.php';
        if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
            throw new RuntimeException('PHPMailer no disponible');
        }

        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        $mail->CharSet = 'UTF-8';
        $smtp_host = $settings['smtp_host'];
        $smtp_user = $settings['smtp_user'];
        $smtp_pass = $settings['smtp_pass'];
        $smtp_port = $settings['smtp_port'];
        $smtp_secure = $settings['smtp_secure'];
        $branding = email_branding();
        $senderName = trim((string) ($branding['name'] ?? 'TVirtualGaming')) ?: 'TVirtualGaming';

        $mail->isSMTP();
        $mail->Host = $smtp_host;
        $mail->SMTPAuth = true;
        $mail->Username = $smtp_user;
        $mail->Password = $smtp_pass;
        $mail->SMTPSecure = $smtp_secure;
        $mail->Port = $smtp_port;
        $mail->Timeout = 12;
        $mail->Timelimit = 12;
        $mail->SMTPKeepAlive = false;
        $mail->setFrom($fromAddr, $senderName);
        $mail->addAddress($to);
        foreach ($embeddedImages as $image) {
            $path = (string) ($image['path'] ?? '');
            $cid = trim((string) ($image['cid'] ?? ''));
            if ($path === '' || $cid === '' || !is_file($path)) {
                continue;
            }
            $mail->addEmbeddedImage($path, $cid, basename($path), 'base64', detect_local_file_mime_type($path));
        }
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $html;
        $mail->send();
    } catch (Throwable $e) {
        error_log('TVG mail error: ' . $e->getMessage());
        try {
            $branding = email_branding();
            $senderName = trim((string) ($branding['name'] ?? 'TVirtualGaming')) ?: 'TVirtualGaming';
            send_app_mail_via_smtp_socket($to, $subject, inline_embedded_images_for_html($html, $embeddedImages), $fromAddr, $settings, $senderName);
        } catch (Throwable $smtpError) {
            error_log('TVG SMTP fallback error: ' . $smtpError->getMessage());
        }
    }
}

function smtp_read_response($socket): string {
    $response = '';
    while (!feof($socket)) {
        $line = fgets($socket, 515);
        if ($line === false) {
            break;
        }
        $response .= $line;
        if (preg_match('/^\d{3}\s/', $line) === 1) {
            break;
        }
    }
    return $response;
}

function smtp_expect_ok($socket, array $allowedCodes, string $context): string {
    $response = smtp_read_response($socket);
    $code = (int) substr(trim($response), 0, 3);
    if (!in_array($code, $allowedCodes, true)) {
        throw new RuntimeException($context . ': ' . trim($response));
    }
    return $response;
}

function smtp_send_command($socket, string $command, array $allowedCodes, string $context): string {
    fwrite($socket, $command . "\r\n");
    return smtp_expect_ok($socket, $allowedCodes, $context);
}

function send_app_mail_via_smtp_socket(string $to, string $subject, string $html, string $fromAddr, array $settings, string $senderName = 'TVirtualGaming'): void {
    $host = (string) ($settings['smtp_host'] ?? '');
    $port = (int) ($settings['smtp_port'] ?? 587);
    $secure = strtolower((string) ($settings['smtp_secure'] ?? 'tls'));
    $username = (string) ($settings['smtp_user'] ?? '');
    $password = (string) ($settings['smtp_pass'] ?? '');

    if ($host === '' || $username === '' || $password === '') {
        throw new RuntimeException('Configuración SMTP incompleta');
    }

    $transport = $secure === 'ssl' ? 'ssl://' : 'tcp://';
    $socket = @stream_socket_client(
        $transport . $host . ':' . $port,
        $errno,
        $errstr,
        20,
        STREAM_CLIENT_CONNECT
    );

    if (!$socket) {
        throw new RuntimeException('No se pudo conectar al servidor SMTP: ' . $errstr . ' (' . $errno . ')');
    }

    stream_set_timeout($socket, 20);

    try {
        smtp_expect_ok($socket, [220], 'Conexión SMTP');
        smtp_send_command($socket, 'EHLO localhost', [250], 'EHLO inicial');

        if ($secure === 'tls') {
            smtp_send_command($socket, 'STARTTLS', [220], 'STARTTLS');
            $cryptoEnabled = stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            if ($cryptoEnabled !== true) {
                throw new RuntimeException('No se pudo habilitar TLS');
            }
            smtp_send_command($socket, 'EHLO localhost', [250], 'EHLO tras TLS');
        }

        smtp_send_command($socket, 'AUTH LOGIN', [334], 'AUTH LOGIN');
        smtp_send_command($socket, base64_encode($username), [334], 'SMTP usuario');
        smtp_send_command($socket, base64_encode($password), [235], 'SMTP contraseña');
        smtp_send_command($socket, 'MAIL FROM:<' . $fromAddr . '>', [250], 'MAIL FROM');
        smtp_send_command($socket, 'RCPT TO:<' . $to . '>', [250, 251], 'RCPT TO');
        smtp_send_command($socket, 'DATA', [354], 'DATA');

        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $headers = [
            'Date: ' . date(DATE_RFC2822),
            'From: ' . str_replace(["\r", "\n"], '', $senderName) . ' <' . $fromAddr . '>',
            'To: <' . $to . '>',
            'Subject: ' . $encodedSubject,
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
        ];

        $body = implode("\r\n", $headers) . "\r\n\r\n" . $html;
        $body = str_replace(["\r\n.", "\n."], ["\r\n..", "\n.."], $body);
        fwrite($socket, $body . "\r\n.\r\n");
        smtp_expect_ok($socket, [250], 'Envío de mensaje');
        smtp_send_command($socket, 'QUIT', [221], 'QUIT');
    } finally {
        fclose($socket);
    }
}

function sanitize_str(?string $value, int $max = 255): ?string {
    if ($value === null) return null;
    $clean = trim($value);
    if ($clean === '') return null;
    return substr($clean, 0, $max);
}

function should_store_last_purchase_identifier(): bool {
    static $enabled = null;

    if ($enabled !== null) {
        return $enabled;
    }

    $enabled = trim((string) store_config_get('guardar_ultimo_id', '0')) === '1';

    return $enabled;
}

function update_user_last_purchase_details(mysqli $mysqli, int $userId, ?string $userIdentifier = null, ?string $phone = null): void {
    if ($userId <= 0) {
        return;
    }

    $normalizedIdentifier = sanitize_str($userIdentifier, 150);
    $normalizedPhone = sanitize_str($phone, 50);
    if ($normalizedIdentifier === null && $normalizedPhone === null) {
        return;
    }

    if ($normalizedIdentifier !== null && $normalizedPhone !== null) {
        $stmt = $mysqli->prepare('UPDATE usuarios SET last_purchase_user_identifier = ?, last_purchase_phone = ? WHERE id = ? LIMIT 1');
        if (!$stmt) {
            return;
        }
        $stmt->bind_param('ssi', $normalizedIdentifier, $normalizedPhone, $userId);
    } elseif ($normalizedIdentifier !== null) {
        $stmt = $mysqli->prepare('UPDATE usuarios SET last_purchase_user_identifier = ? WHERE id = ? LIMIT 1');
        if (!$stmt) {
            return;
        }
        $stmt->bind_param('si', $normalizedIdentifier, $userId);
    } else {
        $stmt = $mysqli->prepare('UPDATE usuarios SET last_purchase_phone = ? WHERE id = ? LIMIT 1');
        if (!$stmt) {
            return;
        }
        $stmt->bind_param('si', $normalizedPhone, $userId);
    }

    $stmt->execute();
    $stmt->close();

    if (!empty($_SESSION['auth_user']) && (int) ($_SESSION['auth_user']['id'] ?? 0) === $userId) {
        if ($normalizedIdentifier !== null) {
            $_SESSION['auth_user']['last_purchase_user_identifier'] = $normalizedIdentifier;
        }
        if ($normalizedPhone !== null) {
            $_SESSION['auth_user']['last_purchase_phone'] = $normalizedPhone;
        }
    }
}

function email_escape(?string $value): string {
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function order_visual_status_label(?string $status): string {
    $normalized = strtolower(trim((string) $status));
    return match ($normalized) {
        'pendiente' => 'No Verificado',
        'pagado' => 'Verificado',
        default => trim((string) $status),
    };
}

function app_base_url(): string {
    return rtrim(app_url('/'), '/');
}

function is_public_callback_host(string $host): bool {
    $host = strtolower(trim($host, '[] '));
    if ($host === '' || $host === 'localhost' || $host === '::1') {
        return false;
    }
    if (str_contains($host, '.') === false) {
        return false;
    }
    if (str_contains($host, '.local') || str_contains($host, '.test')) {
        return false;
    }
    if (preg_match('/^127\./', $host) === 1 || preg_match('/^10\./', $host) === 1 || preg_match('/^192\.168\./', $host) === 1) {
        return false;
    }
    if (preg_match('/^172\.(1[6-9]|2[0-9]|3[0-1])\./', $host) === 1) {
        return false;
    }

    return true;
}

function current_public_app_base_url(): ?string {
    $candidate = rtrim(app_base_url(), '/');
    if (!preg_match('#^https?://#i', $candidate)) {
        return null;
    }

    $host = parse_url($candidate, PHP_URL_HOST);
    if (!is_string($host) || !is_public_callback_host($host)) {
        return null;
    }

    return $candidate;
}

function tenant_public_hosts(): array {
    $config = tenant_config();
    $hosts = [];

    foreach ((array) (($config['tenant']['domains'] ?? [])) as $domain) {
        $normalized = tenant_normalize_host((string) $domain);
        if ($normalized !== '' && is_public_callback_host($normalized)) {
            $hosts[] = $normalized;
        }
    }

    $currentHost = tenant_normalize_host();
    if ($currentHost !== '' && is_public_callback_host($currentHost)) {
        $hosts[] = $currentHost;
    }

    return array_values(array_unique($hosts));
}

function is_tenant_public_host(string $host): bool {
    $normalizedHost = tenant_normalize_host($host);
    if ($normalizedHost === '' || !is_public_callback_host($normalizedHost)) {
        return false;
    }

    return in_array($normalizedHost, tenant_public_hosts(), true);
}

function resolve_provider_webhook_url(): ?string {
    $configuredUrl = trim((string) getenv('TVG_RECARGAS_WEBHOOK_URL'));
    if ($configuredUrl === '') {
        $configuredUrl = trim(store_config_get('recargas_webhook_url', ''));
    }

    $candidate = $configuredUrl !== '' ? $configuredUrl : app_url('/api/recargas_webhook.php');
    if (!preg_match('#^https?://#i', $candidate)) {
        return null;
    }

    $host = parse_url($candidate, PHP_URL_HOST);
    if (!is_string($host) || !is_public_callback_host($host)) {
        return null;
    }

    return rtrim($candidate, '/');
}

function extract_registered_provider_webhook_url(array $response): string {
    $candidates = [
        $response['url'] ?? null,
        $response['webhook'] ?? null,
        is_array($response['webhook'] ?? null) ? ($response['webhook']['url'] ?? null) : null,
        is_array($response['data'] ?? null) ? ($response['data']['url'] ?? null) : null,
    ];

    foreach ($candidates as $candidate) {
        if (is_array($candidate)) {
            $candidate = $candidate['url'] ?? null;
        }

        $value = trim((string) $candidate);
        if ($value !== '') {
            return rtrim($value, '/');
        }
    }

    return '';
}

function ensure_provider_webhook_registration(): void {
    $desiredUrl = resolve_provider_webhook_url();
    if ($desiredUrl === null) {
        error_log('TVG provider webhook registration skipped: no public callback URL available.');
        return;
    }

    try {
        $current = recargas_api_get_webhook();
        $currentUrl = extract_registered_provider_webhook_url($current);
        if ($currentUrl === $desiredUrl) {
            return;
        }
    } catch (Throwable $e) {
        error_log('TVG provider webhook lookup failed: ' . $e->getMessage());
    }

    try {
        recargas_api_register_webhook($desiredUrl);
        error_log('TVG provider webhook registered: ' . $desiredUrl);
    } catch (Throwable $e) {
        error_log('TVG provider webhook registration failed: ' . $e->getMessage());
    }
}

function detect_local_file_mime_type(string $filePath): string {
    if (function_exists('mime_content_type')) {
        $mime = @mime_content_type($filePath);
        if (is_string($mime) && $mime !== '') {
            return $mime;
        }
    }

    $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    return match ($extension) {
        'jpg', 'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'svg' => 'image/svg+xml',
        default => 'image/png',
    };
}

function resolve_store_logo_file_path(string $brandLogo): ?string {
    $brandLogo = trim($brandLogo);
    if ($brandLogo === '' || preg_match('#^https?://#i', $brandLogo) === 1) {
        return null;
    }

    $logoPath = $brandLogo;
    $urlPath = parse_url($brandLogo, PHP_URL_PATH);
    if (is_string($urlPath) && $urlPath !== '') {
        $logoPath = $urlPath;
    }

    $relativePath = ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $logoPath), DIRECTORY_SEPARATOR);
    if ($relativePath === '') {
        return null;
    }

    $absolutePath = dirname(__DIR__) . DIRECTORY_SEPARATOR . $relativePath;
    return is_file($absolutePath) ? $absolutePath : null;
}

function email_branding_logo_asset(?string $logoPath, string $logoUrl = ''): array {
    if (!is_string($logoPath) || $logoPath === '' || !is_file($logoPath)) {
        return [
            'path' => '',
            'mime' => '',
            'url' => $logoUrl,
        ];
    }

    $mime = detect_local_file_mime_type($logoPath);
    if ($mime !== 'image/webp' || !function_exists('imagecreatefromwebp') || !function_exists('imagepng')) {
        return [
            'path' => $logoPath,
            'mime' => $mime,
            'url' => $logoUrl,
        ];
    }

    $targetPath = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
        . 'tvg-mail-logo-' . sha1($logoPath . '|' . (string) @filemtime($logoPath)) . '.png';

    if (!is_file($targetPath)) {
        $image = @imagecreatefromwebp($logoPath);
        if ($image !== false) {
            if (function_exists('imagepalettetotruecolor')) {
                @imagepalettetotruecolor($image);
            }
            @imagesavealpha($image, true);
            @imagepng($image, $targetPath, 6);
            @imagedestroy($image);
        }
    }

    if (is_file($targetPath)) {
        return [
            'path' => $targetPath,
            'mime' => 'image/png',
            'url' => $logoUrl,
        ];
    }

    return [
        'path' => $logoPath,
        'mime' => $mime,
        'url' => $logoUrl,
    ];
}

function email_branding(): array {
    $brandPrefix = trim(store_config_get('nombre_prefijo', 'TIENDA'));
    $brandName = trim(store_config_get('nombre_tienda', 'TVirtualGaming'));
    $brandLogo = trim(store_config_get('logo_tienda', ''));
    $logoUrl = '';
    $logoPath = resolve_store_logo_file_path($brandLogo);

    if ($brandLogo !== '') {
        if (preg_match('#^https?://#i', $brandLogo) === 1) {
            $logoUrl = $brandLogo;
        } elseif (str_starts_with($brandLogo, '/')) {
            $logoUrl = app_base_url() . $brandLogo;
        }
    }

    $logoAsset = email_branding_logo_asset($logoPath, $logoUrl);

    return [
        'prefix' => $brandPrefix !== '' ? $brandPrefix : 'TIENDA',
        'name' => $brandName !== '' ? $brandName : 'TVirtualGaming',
        'logo_url' => $logoAsset['url'] ?? $logoUrl,
        'logo_path' => $logoAsset['path'] ?? '',
        'logo_mime' => $logoAsset['mime'] ?? '',
    ];
}

function email_branding_embedded_images(): array {
    $branding = email_branding();
    $logoPath = $branding['logo_path'] ?? null;
    if (!is_string($logoPath) || $logoPath === '' || !is_file($logoPath)) {
        return [];
    }

    return [[
        'path' => $logoPath,
        'cid' => 'store-logo',
        'mime' => $branding['logo_mime'] ?? '',
    ]];
}

function default_payment_method_for_currency(string $currencyCode): ?array {
    $currencyCode = strtoupper(trim($currencyCode));
    if ($currencyCode === '') {
        return null;
    }

    $methodsByCurrency = payment_methods_active_by_currency();
    $methods = $methodsByCurrency[$currencyCode] ?? [];
    if (!is_array($methods) || empty($methods)) {
        return null;
    }

    $method = $methods[0];
    return is_array($method) ? $method : null;
}

function active_currencies_for_checkout(): array {
    static $cached = null;

    if (is_array($cached)) {
        return $cached;
    }

    $cached = [];
    $mysqli = ensure_mysqli_connection(currency_db());
    $res = $mysqli->query('SELECT * FROM monedas WHERE COALESCE(activo, 1) = 1 ORDER BY es_base DESC, nombre ASC, id ASC');
    if ($res instanceof mysqli_result) {
        while ($row = $res->fetch_assoc()) {
            $cached[] = $row;
        }
    }

    return $cached;
}

function active_currency_for_code(?string $currencyCode): ?array {
    $rawCode = strtoupper(trim((string) $currencyCode));
    $normalizedCode = normalize_currency_code($currencyCode);
    if ($rawCode === '' && $normalizedCode === '') {
        return null;
    }

    foreach (active_currencies_for_checkout() as $currency) {
        $currencyRawCode = strtoupper(trim((string) ($currency['clave'] ?? '')));
        if ($rawCode !== '' && $currencyRawCode === $rawCode) {
            return $currency;
        }
        if ($normalizedCode !== '' && normalize_currency_code($currencyRawCode) === $normalizedCode) {
            return $currency;
        }
    }

    return null;
}

function currency_convert_amount_between_codes(float $amount, ?string $fromCode, ?string $toCode): float {
    $fromNormalized = currency_normalize_code((string) $fromCode);
    $toNormalized = currency_normalize_code((string) $toCode);

    if ($amount <= 0) {
        return 0.0;
    }

    if ($fromNormalized === '' || $toNormalized === '') {
        return round($amount, 2);
    }

    $toCurrency = active_currency_for_code($toCode);
    if ($toCurrency === null) {
        return round($amount, 2);
    }

    if ($fromNormalized === $toNormalized) {
        return currency_apply_amount_rule($amount, $toCurrency);
    }

    $fromCurrency = active_currency_for_code($fromCode);
    if ($fromCurrency === null) {
        return currency_apply_amount_rule($amount, $toCurrency);
    }

    $fromRate = (float) ($fromCurrency['tasa'] ?? 0);
    if ($fromRate <= 0) {
        return currency_apply_amount_rule($amount, $toCurrency);
    }

    $baseAmount = $amount / $fromRate;
    return currency_convert_from_base($baseAmount, $toCurrency);
}

function preferred_binance_checkout_currency(): ?array {
    $activeCurrencies = active_currencies_for_checkout();
    if ($activeCurrencies === []) {
        return null;
    }

    $byCode = [];
    $firstNonBankCurrency = null;
    $fallbackCurrency = null;

    foreach ($activeCurrencies as $currency) {
        $code = normalize_currency_code((string) ($currency['clave'] ?? ''));
        if ($code === '') {
            continue;
        }

        if (!isset($byCode[$code])) {
            $byCode[$code] = $currency;
        }
        if ($fallbackCurrency === null) {
            $fallbackCurrency = $currency;
        }
        if ($code !== 'VES' && $firstNonBankCurrency === null) {
            $firstNonBankCurrency = $currency;
        }
    }

    foreach ($activeCurrencies as $currency) {
        $code = normalize_currency_code((string) ($currency['clave'] ?? ''));
        if ($code !== '' && $code !== 'VES' && (int) ($currency['es_base'] ?? 0) === 1) {
            return $currency;
        }
    }

    foreach (['USDT', 'USD', 'EUR', 'BRL', 'COP', 'MXN', 'CLP', 'PEN'] as $preferredCode) {
        if (isset($byCode[$preferredCode])) {
            return $byCode[$preferredCode];
        }
    }

    return $firstNonBankCurrency ?: $fallbackCurrency;
}

function resolve_binance_checkout_money(array $order): array {
    $orderCurrencyCode = normalize_currency_code((string) ($order['moneda'] ?? ''));
    $targetCurrency = preferred_binance_checkout_currency();
    $targetCurrencyCode = normalize_currency_code((string) ($targetCurrency['clave'] ?? $orderCurrencyCode));
    $sourceAmount = payment_difference_normalize_amount((float) ($order['precio'] ?? 0));
    $checkoutAmount = $sourceAmount;

    if (!is_array($targetCurrency)) {
        $targetCurrency = active_currency_for_code($targetCurrencyCode);
    }

    if ($targetCurrencyCode !== '' && $orderCurrencyCode !== '' && $targetCurrencyCode !== $orderCurrencyCode) {
        $checkoutAmount = currency_convert_amount_between_codes($sourceAmount, $orderCurrencyCode, $targetCurrencyCode);
    } elseif (is_array($targetCurrency)) {
        $checkoutAmount = currency_apply_amount_rule($sourceAmount, $targetCurrency);
    }

    return [
        'currency' => $targetCurrencyCode,
        'amount' => round($checkoutAmount, 2),
        'text' => $targetCurrencyCode !== '' ? ($targetCurrencyCode . ' ' . currency_format_amount($checkoutAmount, $targetCurrency)) : '',
        'uses_order_currency' => $targetCurrencyCode !== '' && $targetCurrencyCode === $orderCurrencyCode,
    ];
}

function payment_method_discount_feature_enabled(): bool {
    return trim((string) store_config_get('descuento_metodo_pago', '0')) === '1';
}

function payment_method_binance_discount_percentage(): float {
    return payment_methods_normalize_discount_percentage(store_config_get('binance_pay_descuento', '0'));
}

function payment_method_paypal_tax_percentage(): float {
    return max(0, min(100, payment_methods_normalize_discount_percentage(store_config_get('paypal_impuesto', '0'))));
}

function resolve_order_payment_discount_base_amount(array $order): float {
    $baseAmount = payment_difference_normalize_amount((float) ($order['precio_descuento_metodo_pago_base'] ?? 0));
    if ($baseAmount > 0) {
        return $baseAmount;
    }

    return payment_difference_normalize_amount((float) ($order['precio'] ?? 0));
}

function resolve_order_payment_discount_snapshot(array $order, string $paymentMode, ?array $method = null): array {
    $baseAmount = resolve_order_payment_discount_base_amount($order);
    $normalizedMode = in_array($paymentMode, ['money', 'binance_pagonorte', 'binance', 'paypal'], true) ? $paymentMode : '';
    $percentage = 0.0;
    $taxPercentage = 0.0;
    $methodName = '';
    $paymentMethodId = 0;

    if ($normalizedMode === 'money' && is_array($method)) {
        $methodName = trim((string) ($method['nombre'] ?? 'Método de pago'));
        $paymentMethodId = max(0, (int) ($method['id'] ?? 0));
        if (payment_method_discount_feature_enabled()) {
            $percentage = payment_methods_normalize_discount_percentage($method['descuento_porcentaje'] ?? 0);
        }
    } elseif ($normalizedMode === 'binance_pagonorte') {
        $methodName = 'Binance';
        if (payment_method_discount_feature_enabled()) {
            $percentage = payment_methods_normalize_discount_percentage(store_config_get('binance_pagonorte_descuento', '0'));
        }
    } elseif ($normalizedMode === 'binance') {
        $methodName = 'Binance Pay';
        if (payment_method_discount_feature_enabled()) {
            $percentage = payment_method_binance_discount_percentage();
        }
    } elseif ($normalizedMode === 'paypal') {
        $methodName = 'PayPal';
        $taxPercentage = payment_method_paypal_tax_percentage();
    }

    $discountAmount = payment_difference_normalize_amount(($baseAmount * $percentage) / 100);
    if ($discountAmount > $baseAmount) {
        $discountAmount = $baseAmount;
    }

    $subtotalAmount = payment_difference_normalize_amount(max(0, $baseAmount - $discountAmount));
    $taxAmount = payment_difference_normalize_amount(($subtotalAmount * $taxPercentage) / 100);

    return [
        'base_amount' => $baseAmount,
        'discount_percentage' => $percentage,
        'discount_amount' => $discountAmount,
        'tax_percentage' => $taxPercentage,
        'tax_amount' => $taxAmount,
        'final_amount' => payment_difference_normalize_amount($subtotalAmount + $taxAmount),
        'method_name' => $methodName,
        'payment_method_id' => $paymentMethodId,
    ];
}

function persist_order_payment_selection(mysqli $mysqli, array $order, string $paymentMode, ?array $method = null): array {
    $orderId = (int) ($order['id'] ?? 0);
    $snapshot = resolve_order_payment_discount_snapshot($order, $paymentMode, $method);
    if ($orderId <= 0) {
        return [
            'snapshot' => $snapshot,
            'order' => $order,
            'price_changed' => false,
        ];
    }

    $stmt = $mysqli->prepare("UPDATE pedidos SET precio_descuento_metodo_pago_base = ?, descuento_metodo_pago_porcentaje = ?, descuento_metodo_pago_monto = ?, precio = ?, metodo_pago = ?, payment_method_id = ? WHERE id = ? AND estado = 'pendiente' LIMIT 1");
    if ($stmt) {
        $methodName = $snapshot['method_name'];
        $paymentMethodId = (int) ($snapshot['payment_method_id'] ?? 0);
        $stmt->bind_param(
            'ddddsii',
            $snapshot['base_amount'],
            $snapshot['discount_percentage'],
            $snapshot['discount_amount'],
            $snapshot['final_amount'],
            $methodName,
            $paymentMethodId,
            $orderId
        );
        $stmt->execute();
        $stmt->close();
    }

    $updatedOrder = fetch_order_by_id($mysqli, $orderId) ?: $order;

    return [
        'snapshot' => $snapshot,
        'order' => $updatedOrder,
        'price_changed' => abs(((float) ($order['precio'] ?? 0)) - ((float) ($updatedOrder['precio'] ?? 0))) > 0.009,
    ];
}

function build_binance_checkout_money_payload(array $order): array {
    $money = resolve_binance_checkout_money($order);
    $currencyCode = trim((string) ($money['currency'] ?? ''));
    if ($currencyCode === '') {
        return [];
    }

    return [
        'binance_currency' => $currencyCode,
        'binance_amount' => (float) ($money['amount'] ?? 0),
        'binance_total_text' => trim((string) ($money['text'] ?? '')),
    ];
}

function payment_method_details_html(?array $method): string {
    if (!$method) {
        return '<p style="margin:14px 0 0;color:#fca5a5;">Aún no hay un método de pago activo configurado para esta moneda. Nuestro equipo revisará tu pedido para indicarte cómo completar el pago.</p>';
    }

    $name = email_escape($method['nombre'] ?? 'Método de pago');
    $details = trim((string) ($method['datos'] ?? ''));
    $formattedDetails = $details !== ''
        ? nl2br(email_escape($details), false)
        : 'Sin detalles adicionales.';

    return '<div style="margin-top:16px;padding:18px 20px;background:#0f172a;border:1px solid #1e293b;border-radius:16px;">'
        . '<div style="color:#67e8f9;font-size:13px;font-weight:700;letter-spacing:1px;text-transform:uppercase;margin-bottom:10px;">Método de pago disponible</div>'
        . '<div style="color:#f8fafc;font-size:18px;font-weight:700;margin-bottom:10px;">' . $name . '</div>'
        . '<div style="color:#cbd5e1;font-size:14px;line-height:1.7;">' . $formattedDetails . '</div>'
        . '</div>';
}

function render_order_email(string $title, string $eyebrow, string $messageHtml, array $orderData, string $accent = '#22d3ee'): string {
    $branding = email_branding();
    $orderId = email_escape((string) ($orderData['order_id'] ?? ''));
    $gameName = email_escape($orderData['game_name'] ?? '');
    $packName = email_escape($orderData['pack_name'] ?? '');
    $packAmount = email_escape($orderData['pack_amount'] ?? '');
    $currency = email_escape($orderData['currency'] ?? '');
    $price = email_escape($orderData['price'] ?? '');
    $userIdentifier = email_escape($orderData['user_identifier'] ?? '');
    $email = email_escape($orderData['email'] ?? '');
    $paymentMethod = email_escape($orderData['payment_method'] ?? '');
    $referenceNumber = email_escape($orderData['reference_number'] ?? '');
    $phoneNumber = email_escape($orderData['phone'] ?? '');
    $coupon = trim((string) ($orderData['coupon'] ?? ''));
    $status = email_escape(order_visual_status_label($orderData['status'] ?? ''));
    $couponRow = $coupon !== ''
        ? '<tr><td style="padding:10px 0;color:#94a3b8;font-size:14px;border-bottom:1px solid #1e293b;">Cupón</td><td style="padding:10px 0;color:#e2e8f0;font-size:14px;text-align:right;border-bottom:1px solid #1e293b;">' . email_escape($coupon) . '</td></tr>'
        : '';
    $statusRow = $status !== ''
        ? '<tr><td style="padding:10px 0;color:#94a3b8;font-size:14px;border-bottom:1px solid #1e293b;">Estado</td><td style="padding:10px 0;color:#e2e8f0;font-size:14px;text-align:right;border-bottom:1px solid #1e293b;">' . $status . '</td></tr>'
        : '';
    $paymentMethodRow = $paymentMethod !== ''
        ? '<tr><td style="padding:10px 0;color:#94a3b8;font-size:14px;border-bottom:1px solid #1e293b;">Método de pago</td><td style="padding:10px 0;color:#e2e8f0;font-size:14px;text-align:right;border-bottom:1px solid #1e293b;">' . $paymentMethod . '</td></tr>'
        : '';
    $referenceRow = $referenceNumber !== ''
        ? '<tr><td style="padding:10px 0;color:#94a3b8;font-size:14px;border-bottom:1px solid #1e293b;">Referencia</td><td style="padding:10px 0;color:#e2e8f0;font-size:14px;text-align:right;border-bottom:1px solid #1e293b;">' . $referenceNumber . '</td></tr>'
        : '';
    $phoneRow = $phoneNumber !== ''
        ? '<tr><td style="padding:10px 0;color:#94a3b8;font-size:14px;border-bottom:1px solid #1e293b;">Teléfono</td><td style="padding:10px 0;color:#e2e8f0;font-size:14px;text-align:right;border-bottom:1px solid #1e293b;">' . $phoneNumber . '</td></tr>'
        : '';
    $brandingLogo = trim((string) (($branding['logo_path'] ?? '') !== '' ? 'cid:store-logo' : ($branding['logo_url'] ?? '')));
    $brandingLogoHtml = $brandingLogo !== ''
        ? '<div style="margin:0 auto 16px;width:72px;height:72px;border-radius:18px;overflow:hidden;border:1px solid rgba(103,232,249,0.65);box-shadow:0 0 18px rgba(34,211,238,0.18);background:rgba(8,15,24,0.65);">'
            . '<img src="' . email_escape($brandingLogo) . '" alt="Logo de la tienda" style="display:block;width:100%;height:100%;object-fit:cover;">'
            . '</div>'
        : '';
    $brandingPrefix = email_escape($branding['prefix'] ?? 'TIENDA');
    $brandingName = email_escape($branding['name'] ?? 'TVirtualGaming');

    return '<!doctype html>'
        . '<html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>' . email_escape($title) . '</title></head>'
        . '<body style="margin:0;padding:0;background:#0a0f14;font-family:Arial,Helvetica,sans-serif;color:#e2e8f0;">'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#0a0f14;padding:24px 12px;">'
        . '<tr><td align="center">'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:640px;background:#111827;border:1px solid #164e63;border-radius:20px;overflow:hidden;box-shadow:0 0 0 1px rgba(34,211,238,0.08),0 20px 40px rgba(0,0,0,0.35);">'
        . '<tr><td style="padding:28px 32px;background:linear-gradient(135deg,#0b1220 0%,#102133 55%,#0f3b46 100%);text-align:center;">'
        . $brandingLogoHtml
        . '<div style="color:#67e8f9;font-size:12px;letter-spacing:4px;text-transform:uppercase;margin-bottom:10px;">' . $brandingPrefix . '</div>'
        . '<div style="color:#ffffff;font-size:32px;line-height:1.2;font-weight:700;margin-bottom:8px;">' . $brandingName . '</div>'
        . '<div style="display:inline-block;padding:6px 14px;border:1px solid ' . $accent . ';border-radius:999px;color:' . $accent . ';font-size:12px;font-weight:700;letter-spacing:1px;text-transform:uppercase;">Notificación de pedido</div>'
        . '<div style="color:#cbd5e1;font-size:12px;letter-spacing:4px;text-transform:uppercase;margin-top:12px;">' . email_escape($eyebrow) . '</div>'
        . '</td></tr>'
        . '<tr><td style="padding:32px;">'
        . '<h1 style="margin:0 0 14px;color:#f8fafc;font-size:28px;line-height:1.2;">' . email_escape($title) . '</h1>'
        . '<div style="color:#cbd5e1;font-size:15px;line-height:1.7;margin-bottom:24px;">' . $messageHtml . '</div>'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;background:#0f172a;border:1px solid #1e293b;border-radius:16px;overflow:hidden;">'
        . '<tr><td colspan="2" style="padding:16px 20px;background:#0b1220;color:#67e8f9;font-size:16px;font-weight:700;">Pedido #' . $orderId . '</td></tr>'
        . '<tr><td style="padding:10px 0 10px 20px;color:#94a3b8;font-size:14px;border-bottom:1px solid #1e293b;">Juego</td><td style="padding:10px 20px 10px 0;color:#e2e8f0;font-size:14px;text-align:right;border-bottom:1px solid #1e293b;">' . $gameName . '</td></tr>'
        . '<tr><td style="padding:10px 0 10px 20px;color:#94a3b8;font-size:14px;border-bottom:1px solid #1e293b;">Paquete</td><td style="padding:10px 20px 10px 0;color:#e2e8f0;font-size:14px;text-align:right;border-bottom:1px solid #1e293b;">' . $packName . ($packAmount !== '' ? ' (' . $packAmount . ')' : '') . '</td></tr>'
        . '<tr><td style="padding:10px 0 10px 20px;color:#94a3b8;font-size:14px;border-bottom:1px solid #1e293b;">Total</td><td style="padding:10px 20px 10px 0;color:' . $accent . ';font-size:18px;font-weight:700;text-align:right;border-bottom:1px solid #1e293b;">' . $currency . ' ' . $price . '</td></tr>'
        . '<tr><td style="padding:10px 0 10px 20px;color:#94a3b8;font-size:14px;border-bottom:1px solid #1e293b;">Cliente</td><td style="padding:10px 20px 10px 0;color:#e2e8f0;font-size:14px;text-align:right;border-bottom:1px solid #1e293b;">' . $userIdentifier . '</td></tr>'
        . '<tr><td style="padding:10px 0 10px 20px;color:#94a3b8;font-size:14px;border-bottom:1px solid #1e293b;">Correo</td><td style="padding:10px 20px 10px 0;color:#e2e8f0;font-size:14px;text-align:right;border-bottom:1px solid #1e293b;">' . $email . '</td></tr>'
        . $paymentMethodRow
        . $referenceRow
        . $phoneRow
        . $couponRow
        . $statusRow
        . '</table>'
        . '<div style="margin-top:24px;padding:16px 18px;background:#0b1220;border:1px solid #1e293b;border-radius:14px;color:#94a3b8;font-size:13px;line-height:1.6;">'
        . 'Este correo fue generado automáticamente por ' . $brandingName . '. Si necesitas revisar el pedido, ingresa al panel o responde desde los canales de soporte configurados.'
        . '</div>'
        . '</td></tr>'
        . '</table>'
        . '</td></tr>'
        . '</table>'
        . '</body></html>';
}

function normalize_coupon_code(string $value): string {
    return strtoupper(trim($value));
}

function is_valid_coupon_code(string $value): bool {
    return $value !== '' && preg_match('/^[A-Za-z0-9]+$/', $value) === 1;
}

function order_expiration_seconds(): int {
    return 1800;
}

function order_expiration_timestamp(array $order): int {
    $expiresAtOverride = trim((string) ($order['pago_expira_en'] ?? ''));
    if ($expiresAtOverride !== '') {
        $overrideTimestamp = strtotime($expiresAtOverride);
        if ($overrideTimestamp !== false && $overrideTimestamp > 0) {
            return $overrideTimestamp;
        }
    }

    $createdAt = isset($order['creado_en_ts']) ? (int) $order['creado_en_ts'] : 0;
    if ($createdAt <= 0) {
        $createdAt = strtotime((string) ($order['creado_en'] ?? ''));
        if ($createdAt === false) {
            $createdAt = time();
        }
    }
    return $createdAt + order_expiration_seconds();
}

function order_is_expired(array $order): bool {
    return time() >= order_expiration_timestamp($order);
}

function order_expiration_iso(array $order): string {
    return date(DATE_ATOM, order_expiration_timestamp($order));
}

function fetch_order_by_id(mysqli $mysqli, int $orderId): ?array {
    $mysqli = ensure_mysqli_connection($mysqli);

    $stmt = $mysqli->prepare('SELECT pedidos.*, UNIX_TIMESTAMP(creado_en) AS creado_en_ts FROM pedidos WHERE id = ? LIMIT 1');
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $orderId);
    $stmt->execute();
    $res = $stmt->get_result();
    $order = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    return $order ?: null;
}

function find_local_order_by_provider_identifiers(mysqli $mysqli, ?string $providerOrderId, ?string $providerReference): ?array {
    $providerOrderId = trim((string) $providerOrderId);
    $providerReference = trim((string) $providerReference);

    if ($providerOrderId !== '') {
        $stmt = $mysqli->prepare('SELECT pedidos.*, UNIX_TIMESTAMP(creado_en) AS creado_en_ts FROM pedidos WHERE recargas_api_pedido_id = ? LIMIT 1');
        if ($stmt) {
            $stmt->bind_param('s', $providerOrderId);
            $stmt->execute();
            $res = $stmt->get_result();
            $order = $res ? $res->fetch_assoc() : null;
            $stmt->close();
            if ($order) {
                return $order;
            }
        }
    }

    if ($providerReference !== '') {
        $stmt = $mysqli->prepare('SELECT pedidos.*, UNIX_TIMESTAMP(creado_en) AS creado_en_ts FROM pedidos WHERE ff_api_referencia = ? ORDER BY id DESC LIMIT 1');
        if ($stmt) {
            $stmt->bind_param('s', $providerReference);
            $stmt->execute();
            $res = $stmt->get_result();
            $order = $res ? $res->fetch_assoc() : null;
            $stmt->close();
            if ($order) {
                return $order;
            }
        }
    }

    return null;
}

function find_local_order_by_binance_identifiers(mysqli $mysqli, ?string $reference, ?string $orderNo = null, ?string $requestId = null): ?array {
    $reference = trim((string) $reference);
    $orderNo = trim((string) $orderNo);
    $requestId = trim((string) $requestId);

    if ($reference !== '') {
        $stmt = $mysqli->prepare('SELECT pedidos.*, UNIX_TIMESTAMP(creado_en) AS creado_en_ts FROM pedidos WHERE binance_pay_reference = ? ORDER BY id DESC LIMIT 1');
        if ($stmt) {
            $stmt->bind_param('s', $reference);
            $stmt->execute();
            $res = $stmt->get_result();
            $order = $res ? $res->fetch_assoc() : null;
            $stmt->close();
            if ($order) {
                return $order;
            }
        }
    }

    if ($orderNo !== '') {
        $stmt = $mysqli->prepare('SELECT pedidos.*, UNIX_TIMESTAMP(creado_en) AS creado_en_ts FROM pedidos WHERE binance_pay_order_no = ? ORDER BY id DESC LIMIT 1');
        if ($stmt) {
            $stmt->bind_param('s', $orderNo);
            $stmt->execute();
            $res = $stmt->get_result();
            $order = $res ? $res->fetch_assoc() : null;
            $stmt->close();
            if ($order) {
                return $order;
            }
        }
    }

    if ($requestId !== '') {
        $stmt = $mysqli->prepare('SELECT pedidos.*, UNIX_TIMESTAMP(creado_en) AS creado_en_ts FROM pedidos WHERE binance_pay_request_id = ? ORDER BY id DESC LIMIT 1');
        if ($stmt) {
            $stmt->bind_param('s', $requestId);
            $stmt->execute();
            $res = $stmt->get_result();
            $order = $res ? $res->fetch_assoc() : null;
            $stmt->close();
            if ($order) {
                return $order;
            }
        }
    }

    return null;
}

function provider_history_from_json(?string $json): array {
    if (!is_string($json) || trim($json) === '') {
        return [];
    }

    $decoded = json_decode($json, true);
    return is_array($decoded) ? array_values(array_filter($decoded, 'is_array')) : [];
}

function provider_history_to_json(array $entries): string {
    $normalized = array_values(array_filter($entries, 'is_array'));
    $encoded = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return is_string($encoded) ? $encoded : '[]';
}

function build_provider_history_entry(string $source, string $providerStatus, string $localStatus, string $providerMessage, string $providerReference = '', ?string $providerOrderId = null, ?string $providerCode = null, $refundAmount = null): array {
    $entry = [
        'recorded_at' => date('Y-m-d H:i:s'),
        'source' => trim($source) !== '' ? trim($source) : 'system',
        'provider_status' => trim($providerStatus),
        'local_status' => trim($localStatus),
        'provider_message' => trim($providerMessage),
        'provider_reference' => trim($providerReference),
    ];

    $providerOrderId = trim((string) $providerOrderId);
    if ($providerOrderId !== '') {
        $entry['provider_order_id'] = $providerOrderId;
    }

    $providerCode = trim((string) $providerCode);
    if ($providerCode !== '') {
        $entry['provider_code'] = $providerCode;
    }

    if ($refundAmount !== null && is_numeric($refundAmount)) {
        $entry['refund_amount'] = round((float) $refundAmount, 2);
    }

    return $entry;
}

function append_provider_history(?string $existingJson, array $entry, int $limit = 5): string {
    $history = provider_history_from_json($existingJson);
    $history[] = $entry;

    if (count($history) > $limit) {
        $history = array_slice($history, -$limit);
    }

    return provider_history_to_json($history);
}

function binance_pay_append_history(?string $existingJson, string $source, array $payload, string $localStatus, int $limit = 10): string {
    $entry = build_provider_history_entry(
        $source,
        binance_pay_normalize_status($payload['status'] ?? ''),
        $localStatus,
        binance_pay_extract_message($payload),
        binance_pay_extract_reference($payload),
        binance_pay_extract_order_no($payload)
    );

    return append_provider_history($existingJson, $entry, $limit);
}

function persist_order_binance_pay_checkout(mysqli $mysqli, int $orderId, array $requestPayload, array $responsePayload, string $expectedStatus = 'pendiente'): bool {
    $order = fetch_order_by_id($mysqli, $orderId);
    if (!$order) {
        return false;
    }

    $requestId = binance_pay_extract_request_id($responsePayload);
    if ($requestId === '') {
        $requestId = trim((string) ($requestPayload['requestId'] ?? ''));
    }
    $orderNo = binance_pay_extract_order_no($responsePayload);
    if ($orderNo === '') {
        $orderNo = trim((string) ($requestPayload['orderNo'] ?? ''));
    }

    $historyJson = binance_pay_append_history(
        $order['binance_pay_historial_json'] ?? null,
        'checkout_create',
        $responsePayload,
        (string) ($order['estado'] ?? 'pendiente')
    );
    $payloadJson = binance_pay_payload_json([
        'request' => $requestPayload,
        'response' => $responsePayload,
    ]);
    $status = binance_pay_normalize_status($responsePayload['status'] ?? 'created');
    $message = binance_pay_extract_message($responsePayload);
    $checkoutUrl = binance_pay_extract_checkout_url($responsePayload);
    $reference = binance_pay_extract_reference($responsePayload);
    $paidAmount = binance_pay_extract_paid_amount($responsePayload);
    $paidCurrency = binance_pay_extract_order_currency($responsePayload);

    $stmt = $mysqli->prepare("UPDATE pedidos SET binance_pay_request_id = ?, binance_pay_order_no = ?, binance_pay_reference = ?, binance_pay_status = ?, binance_pay_message = ?, binance_pay_checkout_url = ?, binance_pay_payload = ?, binance_pay_paid_amount = ?, binance_pay_paid_currency = ?, binance_pay_ultimo_check = NOW(), binance_pay_historial_json = ? WHERE id = ? AND estado = ? LIMIT 1");
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('sssssssissis', $requestId, $orderNo, $reference, $status, $message, $checkoutUrl, $payloadJson, $paidAmount, $paidCurrency, $historyJson, $orderId, $expectedStatus);
    $ok = $stmt->execute();
    $stmt->close();

    return $ok;
}

function persist_order_binance_pay_snapshot(mysqli $mysqli, int $orderId, array $payload, string $source = 'sync'): bool {
    $order = fetch_order_by_id($mysqli, $orderId);
    if (!$order) {
        return false;
    }

    $requestId = binance_pay_extract_request_id($payload);
    if ($requestId === '') {
        $requestId = trim((string) ($order['binance_pay_request_id'] ?? ''));
    }
    $orderNo = binance_pay_extract_order_no($payload);
    if ($orderNo === '') {
        $orderNo = trim((string) ($order['binance_pay_order_no'] ?? ''));
    }
    $reference = binance_pay_extract_reference($payload);
    if ($reference === '') {
        $reference = trim((string) ($order['binance_pay_reference'] ?? ''));
    }
    $status = binance_pay_normalize_status($payload['status'] ?? $order['binance_pay_status'] ?? '');
    $message = binance_pay_extract_message($payload);
    if ($message === '') {
        $message = trim((string) ($order['binance_pay_message'] ?? ''));
    }
    $checkoutUrl = binance_pay_extract_checkout_url($payload);
    if ($checkoutUrl === '' || !binance_pay_is_coinpal_checkout_url($checkoutUrl)) {
        $checkoutUrl = binance_pay_normalize_checkout_url((string) ($order['binance_pay_checkout_url'] ?? ''));
    }
    $paidAmount = binance_pay_extract_paid_amount($payload);
    if ($paidAmount === null && isset($order['binance_pay_paid_amount']) && is_numeric($order['binance_pay_paid_amount'])) {
        $paidAmount = round((float) $order['binance_pay_paid_amount'], 2);
    }
    $paidCurrency = binance_pay_extract_order_currency($payload);
    if ($paidCurrency === '') {
        $paidCurrency = strtoupper(trim((string) ($order['binance_pay_paid_currency'] ?? '')));
    }

    $historyJson = binance_pay_append_history(
        $order['binance_pay_historial_json'] ?? null,
        $source,
        $payload,
        (string) ($order['estado'] ?? 'pendiente')
    );
    $payloadJson = binance_pay_payload_json($payload);

    $stmt = $mysqli->prepare("UPDATE pedidos SET binance_pay_request_id = ?, binance_pay_order_no = ?, binance_pay_reference = ?, binance_pay_status = ?, binance_pay_message = ?, binance_pay_checkout_url = ?, binance_pay_payload = ?, binance_pay_paid_amount = ?, binance_pay_paid_currency = ?, binance_pay_ultimo_check = NOW(), binance_pay_historial_json = ? WHERE id = ? LIMIT 1");
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('sssssssissi', $requestId, $orderNo, $reference, $status, $message, $checkoutUrl, $payloadJson, $paidAmount, $paidCurrency, $historyJson, $orderId);
    $ok = $stmt->execute();
    $stmt->close();

    return $ok;
}

function order_provider_dispatch_lock_name(int $orderId): string {
    return 'pedido_provider_dispatch_' . max(0, $orderId);
}

function acquire_order_provider_dispatch_lock(mysqli $mysqli, int $orderId, int $timeoutSeconds = 0): bool {
    if ($orderId <= 0) {
        return false;
    }

    $lockName = order_provider_dispatch_lock_name($orderId);
    $timeoutSeconds = max(0, $timeoutSeconds);
    $stmt = $mysqli->prepare('SELECT GET_LOCK(?, ?)');
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('si', $lockName, $timeoutSeconds);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_row() : null;
    $stmt->close();

    return isset($row[0]) && (int) $row[0] === 1;
}

function release_order_provider_dispatch_lock(mysqli $mysqli, int $orderId): void {
    if ($orderId <= 0) {
        return;
    }

    $lockName = order_provider_dispatch_lock_name($orderId);
    $stmt = $mysqli->prepare('SELECT RELEASE_LOCK(?)');
    if (!$stmt) {
        return;
    }

    $stmt->bind_param('s', $lockName);
    $stmt->execute();
    $stmt->close();
}

function clear_order_binance_pay_tracking(mysqli $mysqli, int $orderId): bool {
    $stmt = $mysqli->prepare("UPDATE pedidos SET binance_pay_request_id = NULL, binance_pay_order_no = NULL, binance_pay_reference = NULL, binance_pay_status = NULL, binance_pay_message = NULL, binance_pay_checkout_url = NULL, binance_pay_payload = NULL, binance_pay_paid_amount = NULL, binance_pay_paid_currency = NULL, binance_pay_ultimo_check = NULL, binance_pay_historial_json = NULL WHERE id = ? AND estado = 'pendiente' LIMIT 1");
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('i', $orderId);
    $ok = $stmt->execute();
    $stmt->close();

    return $ok;
}

function build_binance_pay_notify_url(): ?string {
    $baseUrl = current_public_app_base_url();
    if ($baseUrl === null) {
        return null;
    }

    $candidate = $baseUrl . '/api/pedidos.php?action=binance_notify';
    $host = parse_url($candidate, PHP_URL_HOST);
    if (!is_string($host) || !is_public_callback_host($host)) {
        return null;
    }

    return $candidate;
}

function build_binance_pay_redirect_url(): string {
    $config = binance_pay_config();
    $candidate = trim((string) ($config['store_url'] ?? ''));
    $candidateHost = is_string(parse_url($candidate, PHP_URL_HOST)) ? (string) parse_url($candidate, PHP_URL_HOST) : '';

    if ($candidate === '' || !preg_match('#^https?://#i', $candidate) || !is_tenant_public_host($candidateHost)) {
        $fallbackBaseUrl = current_public_app_base_url();
        if ($fallbackBaseUrl !== null) {
            $candidate = $fallbackBaseUrl;
        }
    }

    return rtrim($candidate, '/');
}

function build_binance_checkout_response_payload(array $order): array {
    $message = trim((string) ($order['binance_pay_message'] ?? ''));
    if ($message === '') {
        $message = 'Abre Binance Pay y completa el pago para continuar con tu pedido.';
    }

    return array_merge([
        'payment_mode' => 'binance',
        'payment_gateway' => 'binance_pay',
        'provider_flow' => 'binance_checkout',
        'provider_status' => trim((string) ($order['binance_pay_status'] ?? 'created')),
        'provider_reference' => trim((string) ($order['binance_pay_reference'] ?? '')),
        'provider_message' => $message,
        'checkout_url' => binance_pay_normalize_checkout_url((string) ($order['binance_pay_checkout_url'] ?? '')),
        'remaining_seconds' => max(0, order_expiration_timestamp($order) - time()),
    ], build_binance_checkout_money_payload($order));
}

function build_paypal_pay_webhook_url(): ?string {
    $baseUrl = current_public_app_base_url();
    if ($baseUrl === null) {
        return null;
    }

    $candidate = $baseUrl . '/api/pedidos.php?action=paypal_webhook';
    $host = parse_url($candidate, PHP_URL_HOST);
    if (!is_string($host) || !is_public_callback_host($host)) {
        return null;
    }

    return $candidate;
}

function build_paypal_pay_return_url(): ?string {
    $baseUrl = current_public_app_base_url();
    if ($baseUrl === null) {
        return null;
    }

    $candidate = $baseUrl . '/api/pedidos.php?action=paypal_return';
    $host = parse_url($candidate, PHP_URL_HOST);
    if (!is_string($host) || !is_public_callback_host($host)) {
        return null;
    }

    return $candidate;
}

function build_paypal_pay_cancel_url(): ?string {
    $baseUrl = current_public_app_base_url();
    if ($baseUrl === null) {
        return null;
    }

    $candidate = $baseUrl . '/api/pedidos.php?action=paypal_cancel';
    $host = parse_url($candidate, PHP_URL_HOST);
    if (!is_string($host) || !is_public_callback_host($host)) {
        return null;
    }

    return $candidate;
}

function build_paypal_checkout_response_payload(array $order): array {
    $message = trim((string) ($order['paypal_message'] ?? ''));
    if ($message === '') {
        $message = 'Abre PayPal y completa el pago para continuar con tu pedido.';
    }

    $providerReference = trim((string) ($order['paypal_capture_id'] ?? ''));
    if ($providerReference === '') {
        $providerReference = trim((string) ($order['paypal_order_id'] ?? ''));
    }

    return [
        'payment_mode' => 'paypal',
        'payment_gateway' => 'paypal',
        'provider_flow' => 'paypal_checkout',
        'provider_status' => trim((string) ($order['paypal_status'] ?? 'created')),
        'provider_reference' => $providerReference,
        'provider_message' => $message,
        'checkout_url' => trim((string) ($order['paypal_checkout_url'] ?? '')),
        'remaining_seconds' => max(0, order_expiration_timestamp($order) - time()),
    ];
}

function find_local_order_by_paypal_identifiers(mysqli $mysqli, ?string $paypalOrderId, ?string $paypalCaptureId = null): ?array {
    $paypalOrderId = trim((string) $paypalOrderId);
    $paypalCaptureId = trim((string) $paypalCaptureId);

    if ($paypalOrderId !== '') {
        $stmt = $mysqli->prepare('SELECT pedidos.*, UNIX_TIMESTAMP(creado_en) AS creado_en_ts FROM pedidos WHERE paypal_order_id = ? ORDER BY id DESC LIMIT 1');
        if ($stmt) {
            $stmt->bind_param('s', $paypalOrderId);
            $stmt->execute();
            $res = $stmt->get_result();
            $order = $res ? $res->fetch_assoc() : null;
            $stmt->close();
            if ($order) {
                return $order;
            }
        }
    }

    if ($paypalCaptureId !== '') {
        $stmt = $mysqli->prepare('SELECT pedidos.*, UNIX_TIMESTAMP(creado_en) AS creado_en_ts FROM pedidos WHERE paypal_capture_id = ? ORDER BY id DESC LIMIT 1');
        if ($stmt) {
            $stmt->bind_param('s', $paypalCaptureId);
            $stmt->execute();
            $res = $stmt->get_result();
            $order = $res ? $res->fetch_assoc() : null;
            $stmt->close();
            if ($order) {
                return $order;
            }
        }
    }

    return null;
}

function paypal_pay_append_history(?string $existingJson, string $source, array $payload, string $localStatus, int $limit = 10): string {
    $entry = build_provider_history_entry(
        $source,
        paypal_pay_extract_status($payload),
        $localStatus,
        paypal_pay_extract_message($payload),
        paypal_pay_extract_capture_id($payload) !== '' ? paypal_pay_extract_capture_id($payload) : paypal_pay_extract_order_id($payload),
        paypal_pay_extract_order_id($payload)
    );

    return append_provider_history($existingJson, $entry, $limit);
}

function persist_order_paypal_checkout(mysqli $mysqli, int $orderId, array $requestPayload, array $responsePayload, string $expectedStatus = 'pendiente'): bool {
    $order = fetch_order_by_id($mysqli, $orderId);
    if (!$order) {
        return false;
    }

    $paypalOrderId = paypal_pay_extract_order_id($responsePayload);
    $paypalCaptureId = paypal_pay_extract_capture_id($responsePayload);
    $paypalPayerId = paypal_pay_extract_payer_id($responsePayload);
    $historyJson = paypal_pay_append_history(
        $order['paypal_historial_json'] ?? null,
        'checkout_create',
        $responsePayload,
        (string) ($order['estado'] ?? 'pendiente')
    );
    $payloadJson = paypal_pay_payload_json([
        'request' => $requestPayload,
        'response' => $responsePayload,
    ]);
    $status = paypal_pay_extract_status($responsePayload);
    if ($status === '') {
        $status = 'created';
    }
    $message = paypal_pay_extract_message($responsePayload);
    if ($message === '') {
        $message = 'Abre PayPal y completa el pago para continuar con tu pedido.';
    }
    $checkoutUrl = paypal_pay_extract_approval_url($responsePayload);
    $paidAmount = paypal_pay_extract_paid_amount($responsePayload);
    $paidCurrency = paypal_pay_extract_paid_currency($responsePayload);
    $paidAmountText = $paidAmount !== null ? number_format($paidAmount, 2, '.', '') : null;

    $stmt = $mysqli->prepare("UPDATE pedidos SET paypal_order_id = ?, paypal_capture_id = ?, paypal_payer_id = ?, paypal_status = ?, paypal_message = ?, paypal_checkout_url = ?, paypal_payload = ?, paypal_paid_amount = ?, paypal_paid_currency = ?, paypal_ultimo_check = NOW(), paypal_historial_json = ? WHERE id = ? AND estado = ? LIMIT 1");
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('ssssssssssis', $paypalOrderId, $paypalCaptureId, $paypalPayerId, $status, $message, $checkoutUrl, $payloadJson, $paidAmountText, $paidCurrency, $historyJson, $orderId, $expectedStatus);
    $ok = $stmt->execute();
    $stmt->close();

    return $ok;
}

function persist_order_paypal_snapshot(mysqli $mysqli, int $orderId, array $payload, string $source = 'sync'): bool {
    $order = fetch_order_by_id($mysqli, $orderId);
    if (!$order) {
        return false;
    }

    $paypalOrderId = paypal_pay_extract_order_id($payload);
    if ($paypalOrderId === '') {
        $paypalOrderId = trim((string) ($order['paypal_order_id'] ?? ''));
    }
    $paypalCaptureId = paypal_pay_extract_capture_id($payload);
    if ($paypalCaptureId === '') {
        $paypalCaptureId = trim((string) ($order['paypal_capture_id'] ?? ''));
    }
    $paypalPayerId = paypal_pay_extract_payer_id($payload);
    if ($paypalPayerId === '') {
        $paypalPayerId = trim((string) ($order['paypal_payer_id'] ?? ''));
    }
    $status = paypal_pay_extract_status($payload);
    if ($status === '') {
        $status = strtolower(trim((string) ($order['paypal_status'] ?? '')));
    }
    $message = paypal_pay_extract_message($payload);
    if ($message === '') {
        $message = trim((string) ($order['paypal_message'] ?? ''));
    }
    $checkoutUrl = paypal_pay_extract_approval_url($payload);
    if ($checkoutUrl === '') {
        $checkoutUrl = trim((string) ($order['paypal_checkout_url'] ?? ''));
    }
    $paidAmount = paypal_pay_extract_paid_amount($payload);
    if ($paidAmount === null && isset($order['paypal_paid_amount']) && is_numeric($order['paypal_paid_amount'])) {
        $paidAmount = round((float) $order['paypal_paid_amount'], 2);
    }
    $paidCurrency = paypal_pay_extract_paid_currency($payload);
    if ($paidCurrency === '') {
        $paidCurrency = strtoupper(trim((string) ($order['paypal_paid_currency'] ?? '')));
    }
    $paidAmountText = $paidAmount !== null ? number_format($paidAmount, 2, '.', '') : null;
    $historyJson = paypal_pay_append_history(
        $order['paypal_historial_json'] ?? null,
        $source,
        $payload,
        (string) ($order['estado'] ?? 'pendiente')
    );
    $payloadJson = paypal_pay_payload_json($payload);

    $stmt = $mysqli->prepare("UPDATE pedidos SET paypal_order_id = ?, paypal_capture_id = ?, paypal_payer_id = ?, paypal_status = ?, paypal_message = ?, paypal_checkout_url = ?, paypal_payload = ?, paypal_paid_amount = ?, paypal_paid_currency = ?, paypal_ultimo_check = NOW(), paypal_historial_json = ? WHERE id = ? LIMIT 1");
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('ssssssssssi', $paypalOrderId, $paypalCaptureId, $paypalPayerId, $status, $message, $checkoutUrl, $payloadJson, $paidAmountText, $paidCurrency, $historyJson, $orderId);
    $ok = $stmt->execute();
    $stmt->close();

    return $ok;
}

function clear_order_paypal_tracking(mysqli $mysqli, int $orderId): bool {
    $stmt = $mysqli->prepare("UPDATE pedidos SET paypal_order_id = NULL, paypal_capture_id = NULL, paypal_payer_id = NULL, paypal_status = NULL, paypal_message = NULL, paypal_checkout_url = NULL, paypal_payload = NULL, paypal_paid_amount = NULL, paypal_paid_currency = NULL, paypal_ultimo_check = NULL, paypal_historial_json = NULL WHERE id = ? AND estado = 'pendiente' LIMIT 1");
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('i', $orderId);
    $ok = $stmt->execute();
    $stmt->close();

    return $ok;
}

function account_sale_feature_enabled(): bool {
    return trim((string) store_config_get('vender_cuentas', '0')) === '1';
}

function order_is_account_sale(array $order): bool {
    return account_sale_feature_enabled() && (int) ($order['vender_cuenta'] ?? 0) === 1;
}

function order_account_sale_gallery(array $order): array {
    $gallery = json_decode((string) ($order['cuenta_galeria_json'] ?? ''), true);
    if (!is_array($gallery)) {
        return [];
    }

    $normalized = [];
    foreach ($gallery as $item) {
        if (!is_array($item)) {
            continue;
        }

        $imagePath = trim((string) ($item['image_path'] ?? ''));
        if ($imagePath === '') {
            continue;
        }

        $normalized[] = [
            'image_path' => $imagePath,
            'description' => package_account_sales_normalize_caption((string) ($item['description'] ?? '')),
            'order' => max(1, (int) ($item['order'] ?? 1)),
        ];
    }

    return $normalized;
}

function build_account_sale_response_payload(array $order): ?array {
    if (!order_is_account_sale($order)) {
        return null;
    }

    return [
        'enabled' => true,
        'delivered' => strtolower(trim((string) ($order['estado'] ?? ''))) === 'enviado',
        'account_text' => package_account_sales_normalize_text((string) ($order['cuenta_entrega_texto'] ?? '')),
        'gallery' => order_account_sale_gallery($order),
    ];
}

function append_account_sale_response(array $payload, array $order): array {
    $accountSale = build_account_sale_response_payload($order);
    if ($accountSale !== null) {
        $payload['account_sale'] = $accountSale;
    }

    return $payload;
}

function build_order_status_response_payload(array $order, int $orderId): array {
    $providerReference = trim((string) ($order['ff_api_referencia'] ?? ''));
    if ($providerReference === '') {
        $providerReference = trim((string) ($order['binance_pay_reference'] ?? ''));
    }
    if ($providerReference === '') {
        $providerReference = trim((string) ($order['paypal_capture_id'] ?? ''));
    }
    if ($providerReference === '') {
        $providerReference = trim((string) ($order['paypal_order_id'] ?? ''));
    }
    if ($providerReference === '' && order_uses_api_discord($order)) {
        $providerReference = trim((string) ($order['api_discord_message_id'] ?? ''));
    }

    $providerMessage = trim((string) ($order['ff_api_mensaje'] ?? ''));
    if ($providerMessage === '') {
        $providerMessage = trim((string) ($order['binance_pay_message'] ?? ''));
    }
    if ($providerMessage === '') {
        $providerMessage = trim((string) ($order['paypal_message'] ?? ''));
    }
    if ($providerMessage === '' && order_uses_api_discord($order)) {
        $providerMessage = api_discord_dispatch_response_preview($order['api_discord_response_body'] ?? null);
    }
    if ($providerMessage === '' && order_uses_api_discord($order)) {
        $providerMessage = api_discord_order_status_default_message((string) ($order['api_discord_status'] ?? ''));
    }

    $providerStatus = trim((string) ($order['recargas_api_estado'] ?? ''));
    if ($providerStatus === '') {
        $providerStatus = trim((string) ($order['binance_pay_status'] ?? ''));
    }
    if ($providerStatus === '') {
        $providerStatus = trim((string) ($order['paypal_status'] ?? ''));
    }
    if ($providerStatus === '' && order_uses_api_discord($order)) {
        $providerStatus = normalize_api_discord_order_status((string) ($order['api_discord_status'] ?? ''));
    }

    $paymentGateway = '';
    if (
        trim((string) ($order['binance_pay_reference'] ?? '')) !== ''
        || trim((string) ($order['binance_pay_order_no'] ?? '')) !== ''
        || trim((string) ($order['binance_pay_request_id'] ?? '')) !== ''
    ) {
        $paymentGateway = 'binance_pay';
    } elseif (
        trim((string) ($order['paypal_order_id'] ?? '')) !== ''
        || trim((string) ($order['paypal_capture_id'] ?? '')) !== ''
    ) {
        $paymentGateway = 'paypal';
    }

    $checkoutUrl = '';
    if ($paymentGateway === 'binance_pay') {
        $checkoutUrl = binance_pay_normalize_checkout_url((string) ($order['binance_pay_checkout_url'] ?? ''));
    } elseif ($paymentGateway === 'paypal') {
        $checkoutUrl = trim((string) ($order['paypal_checkout_url'] ?? ''));
    }

    $payload = array_merge([
        'ok' => true,
        'order_id' => $orderId,
        'estado' => (string) ($order['estado'] ?? ''),
        'provider_flow' => order_provider_flow_from_row($order),
        'provider_status' => $providerStatus,
        'provider_reference' => $providerReference,
        'provider_message' => $providerMessage,
        'provider_code' => (string) ($order['recargas_api_codigo_entregado'] ?? ''),
        'checkout_url' => $checkoutUrl,
        'payment_gateway' => $paymentGateway,
        'remaining_seconds' => max(0, order_expiration_timestamp($order) - time()),
    ], $paymentGateway === 'binance_pay' ? build_binance_checkout_money_payload($order) : []);

    return append_account_sale_response(append_api_discord_response($payload, $order), $order);
}

function sync_local_order_with_binance_payload(mysqli $mysqli, array $order, array $payload, string $source = 'sync'): array {
    $orderId = (int) ($order['id'] ?? 0);
    if ($orderId <= 0) {
        return [
            'order' => $order,
            'local_status' => trim((string) ($order['estado'] ?? '')),
            'provider_flow' => order_provider_flow_from_row($order),
        ];
    }

    persist_order_binance_pay_snapshot($mysqli, $orderId, $payload, $source);
    $order = fetch_order_by_id($mysqli, $orderId) ?: $order;

    $binanceStatus = binance_pay_normalize_status($payload['status'] ?? ($order['binance_pay_status'] ?? ''));
    $reference = binance_pay_extract_reference($payload);
    if ($reference === '') {
        $reference = trim((string) ($order['binance_pay_reference'] ?? ''));
    }
    $message = trim(binance_pay_extract_message($payload));
    if ($message === '') {
        $message = trim((string) ($order['binance_pay_message'] ?? ''));
    }

    if ($message === '') {
        if (binance_pay_is_paid_status($binanceStatus)) {
            $message = 'Pago aprobado correctamente por Binance Pay.';
        } elseif (binance_pay_is_failed_status($binanceStatus)) {
            $message = 'El pago fue rechazado o cancelado en Binance Pay.';
        } else {
            $message = 'El pago sigue pendiente de confirmación en Binance Pay.';
        }
    }

    $localStatus = trim((string) ($order['estado'] ?? ''));
    if ($localStatus === 'enviado' || $localStatus === 'cancelado') {
        return [
            'order' => $order,
            'local_status' => $localStatus,
            'provider_flow' => order_provider_flow_from_row($order),
        ];
    }

    if ($binanceStatus === '' || binance_pay_is_pending_status($binanceStatus)) {
        return [
            'order' => $order,
            'local_status' => $localStatus,
            'provider_flow' => order_provider_flow_from_row($order),
        ];
    }

    if (binance_pay_is_failed_status($binanceStatus)) {
        if ($localStatus === 'pendiente') {
            $verifiedReference = $reference !== '' ? $reference : trim((string) ($order['numero_referencia'] ?? ''));
            $cancelStatus = 'cancelado';
            $stmt = $mysqli->prepare("UPDATE pedidos SET numero_referencia = ?, estado = ? WHERE id = ? AND estado = 'pendiente' LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('ssi', $verifiedReference, $cancelStatus, $orderId);
                $stmt->execute();
                $stmt->close();
            }
            $order = fetch_order_by_id($mysqli, $orderId) ?: $order;
        }

        return [
            'order' => $order,
            'local_status' => trim((string) ($order['estado'] ?? 'cancelado')),
            'provider_flow' => order_provider_flow_from_row($order),
        ];
    }

    if (!binance_pay_is_paid_status($binanceStatus) || $localStatus !== 'pendiente') {
        return [
            'order' => $order,
            'local_status' => trim((string) ($order['estado'] ?? $localStatus)),
            'provider_flow' => order_provider_flow_from_row($order),
        ];
    }

    $verifiedReference = $reference !== '' ? $reference : trim((string) ($order['numero_referencia'] ?? ''));
    $phone = substr(trim((string) ($order['telefono_contacto'] ?? '')), 0, 40);
    $paymentMethodName = 'Binance Pay';
    $updatedOrder = $order;
    $usesCatalogApi = order_uses_catalog_api_provider($updatedOrder);

    if (order_is_account_sale($updatedOrder)) {
        try {
            $sentOrder = mark_account_sale_as_sent($mysqli, $updatedOrder, 'pendiente', $verifiedReference, $phone);
        } catch (Throwable $e) {
            return [
                'order' => fetch_order_by_id($mysqli, $orderId) ?: $updatedOrder,
                'local_status' => trim((string) ($updatedOrder['estado'] ?? 'pendiente')),
                'provider_flow' => order_provider_flow_from_row($updatedOrder),
                'sync_error' => $e->getMessage(),
            ];
        }

        if (trim((string) ($sentOrder['estado'] ?? '')) === 'enviado') {
            win_points_handle_order_status_change($mysqli, $orderId, 'enviado');
            recharge_notifications_emit_for_order($mysqli, $sentOrder);
            register_influencer_coupon_sale($mysqli, $sentOrder);
            notify_account_sale_delivery($mysqli, $sentOrder, $paymentMethodName, $verifiedReference, $phone);
        }

        return [
            'order' => $sentOrder,
            'local_status' => trim((string) ($sentOrder['estado'] ?? 'enviado')),
            'provider_flow' => order_provider_flow_from_row($sentOrder),
        ];
    }

    if (order_uses_api_discord($updatedOrder)) {
        $dispatchResult = execute_api_discord_order_dispatch($updatedOrder);
        persist_api_discord_dispatch_result($mysqli, $updatedOrder, $dispatchResult, 'pagado', 'pagado', $verifiedReference, $phone);

        $paidOrder = fetch_order_by_id($mysqli, $orderId) ?: $updatedOrder;
        recharge_notifications_emit_for_order($mysqli, $paidOrder);
        register_influencer_coupon_sale($mysqli, $paidOrder);
        if (!empty($dispatchResult['sent'])) {
            notify_catalog_purchase_pending($mysqli, $paidOrder, $paymentMethodName, $verifiedReference, $phone, trim((string) ($dispatchResult['provider_reference'] ?? '')), trim((string) ($dispatchResult['provider_message'] ?? '')));
        } else {
            notify_bank_payment_verified_paid($mysqli, $paidOrder, $paymentMethodName, $verifiedReference, $phone);
        }

        return [
            'order' => $paidOrder,
            'local_status' => trim((string) ($paidOrder['estado'] ?? 'pagado')),
            'provider_flow' => order_provider_flow_from_row($paidOrder),
        ];
    }

    if (!$usesCatalogApi) {
        $paidStatus = 'pagado';
        $stmt = $mysqli->prepare("UPDATE pedidos SET numero_referencia = ?, estado = ? WHERE id = ? AND estado = 'pendiente' LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('ssi', $verifiedReference, $paidStatus, $orderId);
            $stmt->execute();
            $stmt->close();
        }

        $paidOrder = fetch_order_by_id($mysqli, $orderId) ?: $updatedOrder;
        if (trim((string) ($paidOrder['estado'] ?? '')) === 'pagado') {
            recharge_notifications_emit_for_order($mysqli, $paidOrder);
            register_influencer_coupon_sale($mysqli, $paidOrder);
            notify_bank_payment_verified_paid($mysqli, $paidOrder, $paymentMethodName, $verifiedReference, $phone);
        }

        return [
            'order' => $paidOrder,
            'local_status' => trim((string) ($paidOrder['estado'] ?? 'pagado')),
            'provider_flow' => order_provider_flow_from_row($paidOrder),
        ];
    }

    if (!acquire_order_provider_dispatch_lock($mysqli, $orderId, 0)) {
        $lockedOrder = fetch_order_by_id($mysqli, $orderId) ?: $updatedOrder;
        return [
            'order' => $lockedOrder,
            'local_status' => trim((string) ($lockedOrder['estado'] ?? $localStatus)),
            'provider_flow' => order_provider_flow_from_row($lockedOrder),
        ];
    }

    try {
        $updatedOrder = fetch_order_by_id($mysqli, $orderId) ?: $updatedOrder;
        $latestLocalStatus = trim((string) ($updatedOrder['estado'] ?? $localStatus));
        if ($latestLocalStatus === 'enviado' || $latestLocalStatus === 'cancelado') {
            return [
                'order' => $updatedOrder,
                'local_status' => $latestLocalStatus,
                'provider_flow' => order_provider_flow_from_row($updatedOrder),
            ];
        }

        $hasProviderTracking = trim((string) ($updatedOrder['recargas_api_pedido_id'] ?? '')) !== ''
            || trim((string) ($updatedOrder['ff_api_referencia'] ?? '')) !== '';
        if ($latestLocalStatus === 'pagado' && $hasProviderTracking) {
            return [
                'order' => $updatedOrder,
                'local_status' => $latestLocalStatus,
                'provider_flow' => order_provider_flow_from_row($updatedOrder),
            ];
        }

        $claimStatus = 'pagado';
        $claimStmt = $mysqli->prepare("UPDATE pedidos SET numero_referencia = ?, telefono_contacto = ?, estado = ? WHERE id = ? AND estado = 'pendiente' LIMIT 1");
        if ($claimStmt) {
            $claimStmt->bind_param('sssi', $verifiedReference, $phone, $claimStatus, $orderId);
            $claimStmt->execute();
            $claimStmt->close();
        }

        $updatedOrder = fetch_order_by_id($mysqli, $orderId) ?: $updatedOrder;
        if (trim((string) ($updatedOrder['estado'] ?? '')) !== 'pagado') {
            return [
                'order' => $updatedOrder,
                'local_status' => trim((string) ($updatedOrder['estado'] ?? $latestLocalStatus)),
                'provider_flow' => order_provider_flow_from_row($updatedOrder),
            ];
        }

        $packageApiId = (int) ($updatedOrder['paquete_api'] ?? 0);
        $orderPlayerFields = order_player_fields_from_json((string) ($updatedOrder['player_fields_json'] ?? ''));

        try {
            $providerResult = execute_catalog_api_purchase($packageApiId, (string) ($updatedOrder['user_identifier'] ?? ''), $orderPlayerFields, order_purchase_quantity($updatedOrder));
        } catch (Throwable $e) {
            $providerResult = [
                'success' => false,
                'accepted' => false,
                'message' => $e->getMessage(),
                'reference' => '',
                'payload' => ['exception' => $e->getMessage()],
            ];
        }

        $mysqli = ensure_mysqli_connection($mysqli);
        $providerPayloadData = (array) ($providerResult['payload'] ?? []);
        $providerReference = (string) ($providerResult['reference'] ?? '');
        $providerMessage = provider_order_status_message($providerPayloadData, (string) ($providerResult['message'] ?? 'No se recibió mensaje del proveedor.'));
        $providerPayload = json_encode($providerPayloadData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $providerOrderId = recargas_api_extract_provider_order_id($providerPayloadData);
        $providerState = strtolower(trim((string) (($providerPayloadData['estado'] ?? ''))));
        $providerCode = provider_delivered_code_text($providerPayloadData);

        if (!empty($providerResult['success'])) {
            $verifiedStatus = 'enviado';
            $providerHistoryJson = append_provider_history(
                $updatedOrder['recargas_api_historial_json'] ?? null,
                build_provider_history_entry(
                    'purchase',
                    $providerState,
                    $verifiedStatus,
                    $providerMessage,
                    $providerReference,
                    $providerOrderId,
                    $providerCode
                )
            );
            $verifyStmt = $mysqli->prepare("UPDATE pedidos SET numero_referencia = ?, ff_api_referencia = ?, ff_api_mensaje = ?, ff_api_payload = ?, recargas_api_pedido_id = ?, recargas_api_estado = ?, recargas_api_codigo_entregado = ?, recargas_api_ultimo_check = NOW(), recargas_api_historial_json = ?, estado = ? WHERE id = ? AND estado = 'pagado'");
            if ($verifyStmt) {
                $verifyStmt->bind_param('sssssssssi', $verifiedReference, $providerReference, $providerMessage, $providerPayload, $providerOrderId, $providerState, $providerCode, $providerHistoryJson, $verifiedStatus, $orderId);
                $verifyStmt->execute();
                $verifyStmt->close();
            }

            $verifiedOrder = fetch_order_by_id($mysqli, $orderId) ?: $updatedOrder;
            if (trim((string) ($verifiedOrder['estado'] ?? '')) === 'enviado') {
                win_points_handle_order_status_change($mysqli, $orderId, 'enviado');
                recharge_notifications_emit_for_order($mysqli, $verifiedOrder);
                ensure_provider_webhook_registration();
                register_influencer_coupon_sale($mysqli, $verifiedOrder);
                notify_free_fire_recharge_success($mysqli, $verifiedOrder, $paymentMethodName, $verifiedReference, $phone, $providerReference, $providerMessage);
            }

            return [
                'order' => $verifiedOrder,
                'local_status' => trim((string) ($verifiedOrder['estado'] ?? 'enviado')),
                'provider_flow' => order_provider_flow_from_row($verifiedOrder),
            ];
        }

        $inventoryShortage = recharge_availability_message_indicates_inventory_shortage($providerMessage);
        if ($inventoryShortage) {
            try {
                $shortageResult = mark_order_inventory_shortage_review(
                    $mysqli,
                    $updatedOrder,
                    'pagado',
                    $verifiedReference,
                    $phone,
                    $providerReference,
                    $providerMessage,
                    $providerPayload,
                    $providerOrderId,
                    $providerCode
                );
            } catch (Throwable $e) {
                return [
                    'order' => fetch_order_by_id($mysqli, $orderId) ?: $updatedOrder,
                    'local_status' => trim((string) ($updatedOrder['estado'] ?? 'pagado')),
                    'provider_flow' => order_provider_flow_from_row($updatedOrder),
                    'sync_error' => $e->getMessage(),
                ];
            }

            $paidOrder = $shortageResult['order'];
            recharge_notifications_emit_for_order($mysqli, $paidOrder);
            register_influencer_coupon_sale($mysqli, $paidOrder);
            notify_provider_inventory_shortage($mysqli, $paidOrder, $paymentMethodName, $verifiedReference, $phone, $providerMessage, $shortageResult['lock'] ?? []);

            return [
                'order' => $paidOrder,
                'local_status' => trim((string) ($paidOrder['estado'] ?? 'pagado')),
                'provider_flow' => order_provider_flow_from_row($paidOrder),
            ];
        }

        $manualProcessing = !empty($providerResult['manual_processing']);
        $acceptedLike = !empty($providerResult['accepted'])
            || ($manualProcessing && provider_message_indicates_pending_lookup($providerMessage));
        $trackingFollowUp = provider_message_indicates_transport_timeout($providerMessage);

        if ($trackingFollowUp || $acceptedLike) {
            $paidStatus = 'pagado';
            $trackedState = $providerState !== '' ? $providerState : 'pending_confirmation';
            $providerHistoryJson = append_provider_history(
                $updatedOrder['recargas_api_historial_json'] ?? null,
                build_provider_history_entry(
                    $trackingFollowUp ? 'purchase_timeout' : 'purchase',
                    $trackedState,
                    $paidStatus,
                    $providerMessage,
                    $providerReference,
                    $providerOrderId,
                    $providerCode
                )
            );
            $paidStmt = $mysqli->prepare("UPDATE pedidos SET numero_referencia = ?, ff_api_referencia = ?, ff_api_mensaje = ?, ff_api_payload = ?, recargas_api_pedido_id = ?, recargas_api_estado = ?, recargas_api_codigo_entregado = ?, recargas_api_ultimo_check = NOW(), recargas_api_historial_json = ?, estado = ? WHERE id = ? AND estado = 'pagado'");
            if ($paidStmt) {
                $paidStmt->bind_param('sssssssssi', $verifiedReference, $providerReference, $providerMessage, $providerPayload, $providerOrderId, $trackedState, $providerCode, $providerHistoryJson, $paidStatus, $orderId);
                $paidStmt->execute();
                $paidStmt->close();
            }

            $paidOrder = fetch_order_by_id($mysqli, $orderId) ?: $updatedOrder;
            recharge_notifications_emit_for_order($mysqli, $paidOrder);
            ensure_provider_webhook_registration();
            register_influencer_coupon_sale($mysqli, $paidOrder);
            notify_catalog_purchase_pending($mysqli, $paidOrder, $paymentMethodName, $verifiedReference, $phone, $providerReference, $providerMessage);

            if ($trackingFollowUp) {
                continue_provider_follow_up_in_background($mysqli, (int) ($paidOrder['id'] ?? $orderId), 8, 8);
            } elseif ($acceptedLike) {
                $autoSyncAttempts = $manualProcessing ? 5 : 3;
                $autoSyncDelaySeconds = $manualProcessing ? 4 : 2;
                try {
                    $autoSyncResult = try_auto_sync_provider_order($mysqli, $paidOrder, $autoSyncAttempts, $autoSyncDelaySeconds);
                    if (is_array($autoSyncResult['order'] ?? null)) {
                        $paidOrder = $autoSyncResult['order'];
                    } else {
                        $paidOrder = fetch_order_by_id($mysqli, $orderId) ?: $paidOrder;
                    }
                } catch (Throwable $e) {
                    $paidOrder = fetch_order_by_id($mysqli, $orderId) ?: $paidOrder;
                }
            }

            return [
                'order' => $paidOrder,
                'local_status' => trim((string) ($paidOrder['estado'] ?? 'pagado')),
                'provider_flow' => order_provider_flow_from_row($paidOrder),
            ];
        }

        $cancelStatus = 'cancelado';
        $failedHistoryJson = append_provider_history(
            $updatedOrder['recargas_api_historial_json'] ?? null,
            build_provider_history_entry(
                'purchase_failed',
                $providerState,
                $cancelStatus,
                $providerMessage,
                $providerReference,
                $providerOrderId,
                $providerCode
            )
        );
        $cancelStmt = $mysqli->prepare("UPDATE pedidos SET numero_referencia = ?, ff_api_referencia = ?, ff_api_mensaje = ?, ff_api_payload = ?, recargas_api_pedido_id = ?, recargas_api_estado = ?, recargas_api_codigo_entregado = ?, recargas_api_ultimo_check = NOW(), recargas_api_historial_json = ?, estado = ? WHERE id = ? AND estado = 'pagado'");
        if ($cancelStmt) {
            $cancelStmt->bind_param('sssssssssi', $verifiedReference, $providerReference, $providerMessage, $providerPayload, $providerOrderId, $providerState, $providerCode, $failedHistoryJson, $cancelStatus, $orderId);
            $cancelStmt->execute();
            $cancelStmt->close();
        }

        $cancelledOrder = fetch_order_by_id($mysqli, $orderId) ?: $updatedOrder;
        return [
            'order' => $cancelledOrder,
            'local_status' => trim((string) ($cancelledOrder['estado'] ?? 'cancelado')),
            'provider_flow' => order_provider_flow_from_row($cancelledOrder),
        ];
    } finally {
        release_order_provider_dispatch_lock($mysqli, $orderId);
    }
}

function sync_local_order_with_paypal_payload(mysqli $mysqli, array $order, array $payload, string $source = 'sync'): array {
    $orderId = (int) ($order['id'] ?? 0);
    if ($orderId <= 0) {
        return [
            'order' => $order,
            'local_status' => trim((string) ($order['estado'] ?? '')),
            'provider_flow' => order_provider_flow_from_row($order),
        ];
    }

    persist_order_paypal_snapshot($mysqli, $orderId, $payload, $source);
    $order = fetch_order_by_id($mysqli, $orderId) ?: $order;

    $paypalStatus = paypal_pay_extract_status($payload);
    if ($paypalStatus === '') {
        $paypalStatus = strtolower(trim((string) ($order['paypal_status'] ?? '')));
    }
    $reference = paypal_pay_extract_capture_id($payload);
    if ($reference === '') {
        $reference = trim((string) ($order['paypal_capture_id'] ?? ''));
    }
    if ($reference === '') {
        $reference = paypal_pay_extract_order_id($payload);
    }
    if ($reference === '') {
        $reference = trim((string) ($order['paypal_order_id'] ?? ''));
    }

    $message = trim(paypal_pay_extract_message($payload));
    if ($message === '') {
        $message = trim((string) ($order['paypal_message'] ?? ''));
    }
    if ($message === '') {
        if (paypal_pay_is_completed_status($paypalStatus)) {
            $message = 'Pago aprobado correctamente por PayPal.';
        } elseif (paypal_pay_is_failed_status($paypalStatus)) {
            $message = 'El pago fue rechazado o cancelado en PayPal.';
        } else {
            $message = 'El pago sigue pendiente de confirmación en PayPal.';
        }
    }

    $localStatus = trim((string) ($order['estado'] ?? ''));
    if ($localStatus === 'enviado' || $localStatus === 'cancelado') {
        return [
            'order' => $order,
            'local_status' => $localStatus,
            'provider_flow' => order_provider_flow_from_row($order),
        ];
    }

    if ($paypalStatus === '' || paypal_pay_is_pending_status($paypalStatus)) {
        return [
            'order' => $order,
            'local_status' => $localStatus,
            'provider_flow' => order_provider_flow_from_row($order),
        ];
    }

    if (paypal_pay_is_failed_status($paypalStatus)) {
        if ($localStatus === 'pendiente') {
            $verifiedReference = $reference !== '' ? $reference : trim((string) ($order['numero_referencia'] ?? ''));
            $cancelStatus = 'cancelado';
            $stmt = $mysqli->prepare("UPDATE pedidos SET numero_referencia = ?, estado = ? WHERE id = ? AND estado = 'pendiente' LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('ssi', $verifiedReference, $cancelStatus, $orderId);
                $stmt->execute();
                $stmt->close();
            }
            $order = fetch_order_by_id($mysqli, $orderId) ?: $order;
        }

        return [
            'order' => $order,
            'local_status' => trim((string) ($order['estado'] ?? 'cancelado')),
            'provider_flow' => order_provider_flow_from_row($order),
        ];
    }

    if (!paypal_pay_is_completed_status($paypalStatus) || $localStatus !== 'pendiente') {
        return [
            'order' => $order,
            'local_status' => trim((string) ($order['estado'] ?? $localStatus)),
            'provider_flow' => order_provider_flow_from_row($order),
        ];
    }

    $verifiedReference = $reference !== '' ? $reference : trim((string) ($order['numero_referencia'] ?? ''));
    $phone = substr(trim((string) ($order['telefono_contacto'] ?? '')), 0, 40);
    $paymentMethodName = 'PayPal';
    $updatedOrder = $order;
    $usesCatalogApi = order_uses_catalog_api_provider($updatedOrder);

    if (order_is_account_sale($updatedOrder)) {
        try {
            $sentOrder = mark_account_sale_as_sent($mysqli, $updatedOrder, 'pendiente', $verifiedReference, $phone);
        } catch (Throwable $e) {
            return [
                'order' => fetch_order_by_id($mysqli, $orderId) ?: $updatedOrder,
                'local_status' => trim((string) ($updatedOrder['estado'] ?? 'pendiente')),
                'provider_flow' => order_provider_flow_from_row($updatedOrder),
                'sync_error' => $e->getMessage(),
            ];
        }

        if (trim((string) ($sentOrder['estado'] ?? '')) === 'enviado') {
            win_points_handle_order_status_change($mysqli, $orderId, 'enviado');
            recharge_notifications_emit_for_order($mysqli, $sentOrder);
            register_influencer_coupon_sale($mysqli, $sentOrder);
            notify_account_sale_delivery($mysqli, $sentOrder, $paymentMethodName, $verifiedReference, $phone);
        }

        return [
            'order' => $sentOrder,
            'local_status' => trim((string) ($sentOrder['estado'] ?? 'enviado')),
            'provider_flow' => order_provider_flow_from_row($sentOrder),
        ];
    }

    if (order_uses_api_discord($updatedOrder)) {
        $dispatchResult = execute_api_discord_order_dispatch($updatedOrder);
        persist_api_discord_dispatch_result($mysqli, $updatedOrder, $dispatchResult, 'pagado', 'pagado', $verifiedReference, $phone);

        $paidOrder = fetch_order_by_id($mysqli, $orderId) ?: $updatedOrder;
        recharge_notifications_emit_for_order($mysqli, $paidOrder);
        register_influencer_coupon_sale($mysqli, $paidOrder);
        if (!empty($dispatchResult['sent'])) {
            notify_catalog_purchase_pending($mysqli, $paidOrder, $paymentMethodName, $verifiedReference, $phone, trim((string) ($dispatchResult['provider_reference'] ?? '')), trim((string) ($dispatchResult['provider_message'] ?? '')));
        } else {
            notify_bank_payment_verified_paid($mysqli, $paidOrder, $paymentMethodName, $verifiedReference, $phone);
        }

        return [
            'order' => $paidOrder,
            'local_status' => trim((string) ($paidOrder['estado'] ?? 'pagado')),
            'provider_flow' => order_provider_flow_from_row($paidOrder),
        ];
    }

    if (!$usesCatalogApi) {
        $paidStatus = 'pagado';
        $stmt = $mysqli->prepare("UPDATE pedidos SET numero_referencia = ?, estado = ? WHERE id = ? AND estado = 'pendiente' LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('ssi', $verifiedReference, $paidStatus, $orderId);
            $stmt->execute();
            $stmt->close();
        }

        $paidOrder = fetch_order_by_id($mysqli, $orderId) ?: $updatedOrder;
        if (trim((string) ($paidOrder['estado'] ?? '')) === 'pagado') {
            recharge_notifications_emit_for_order($mysqli, $paidOrder);
            register_influencer_coupon_sale($mysqli, $paidOrder);
            notify_bank_payment_verified_paid($mysqli, $paidOrder, $paymentMethodName, $verifiedReference, $phone);
        }

        return [
            'order' => $paidOrder,
            'local_status' => trim((string) ($paidOrder['estado'] ?? 'pagado')),
            'provider_flow' => order_provider_flow_from_row($paidOrder),
        ];
    }

    if (!acquire_order_provider_dispatch_lock($mysqli, $orderId, 0)) {
        $lockedOrder = fetch_order_by_id($mysqli, $orderId) ?: $updatedOrder;
        return [
            'order' => $lockedOrder,
            'local_status' => trim((string) ($lockedOrder['estado'] ?? $localStatus)),
            'provider_flow' => order_provider_flow_from_row($lockedOrder),
        ];
    }

    try {
        $updatedOrder = fetch_order_by_id($mysqli, $orderId) ?: $updatedOrder;
        $latestLocalStatus = trim((string) ($updatedOrder['estado'] ?? $localStatus));
        if ($latestLocalStatus === 'enviado' || $latestLocalStatus === 'cancelado') {
            return [
                'order' => $updatedOrder,
                'local_status' => $latestLocalStatus,
                'provider_flow' => order_provider_flow_from_row($updatedOrder),
            ];
        }

        $hasProviderTracking = trim((string) ($updatedOrder['recargas_api_pedido_id'] ?? '')) !== ''
            || trim((string) ($updatedOrder['ff_api_referencia'] ?? '')) !== '';
        if ($latestLocalStatus === 'pagado' && $hasProviderTracking) {
            return [
                'order' => $updatedOrder,
                'local_status' => $latestLocalStatus,
                'provider_flow' => order_provider_flow_from_row($updatedOrder),
            ];
        }

        $claimStatus = 'pagado';
        $claimStmt = $mysqli->prepare("UPDATE pedidos SET numero_referencia = ?, telefono_contacto = ?, estado = ? WHERE id = ? AND estado = 'pendiente' LIMIT 1");
        if ($claimStmt) {
            $claimStmt->bind_param('sssi', $verifiedReference, $phone, $claimStatus, $orderId);
            $claimStmt->execute();
            $claimStmt->close();
        }

        $updatedOrder = fetch_order_by_id($mysqli, $orderId) ?: $updatedOrder;
        if (trim((string) ($updatedOrder['estado'] ?? '')) !== 'pagado') {
            return [
                'order' => $updatedOrder,
                'local_status' => trim((string) ($updatedOrder['estado'] ?? $latestLocalStatus)),
                'provider_flow' => order_provider_flow_from_row($updatedOrder),
            ];
        }

        $packageApiId = (int) ($updatedOrder['paquete_api'] ?? 0);
        $orderPlayerFields = order_player_fields_from_json((string) ($updatedOrder['player_fields_json'] ?? ''));

        try {
            $providerResult = execute_catalog_api_purchase($packageApiId, (string) ($updatedOrder['user_identifier'] ?? ''), $orderPlayerFields, order_purchase_quantity($updatedOrder));
        } catch (Throwable $e) {
            $providerResult = [
                'success' => false,
                'accepted' => false,
                'message' => $e->getMessage(),
                'reference' => '',
                'payload' => ['exception' => $e->getMessage()],
            ];
        }

        $mysqli = ensure_mysqli_connection($mysqli);
        $providerPayloadData = (array) ($providerResult['payload'] ?? []);
        $providerReference = (string) ($providerResult['reference'] ?? '');
        $providerMessage = provider_order_status_message($providerPayloadData, (string) ($providerResult['message'] ?? 'No se recibió mensaje del proveedor.'));
        $providerPayload = json_encode($providerPayloadData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $providerOrderId = recargas_api_extract_provider_order_id($providerPayloadData);
        $providerState = strtolower(trim((string) (($providerPayloadData['estado'] ?? ''))));
        $providerCode = provider_delivered_code_text($providerPayloadData);

        if (!empty($providerResult['success'])) {
            $verifiedStatus = 'enviado';
            $providerHistoryJson = append_provider_history(
                $updatedOrder['recargas_api_historial_json'] ?? null,
                build_provider_history_entry(
                    'purchase',
                    $providerState,
                    $verifiedStatus,
                    $providerMessage,
                    $providerReference,
                    $providerOrderId,
                    $providerCode
                )
            );
            $verifyStmt = $mysqli->prepare("UPDATE pedidos SET numero_referencia = ?, ff_api_referencia = ?, ff_api_mensaje = ?, ff_api_payload = ?, recargas_api_pedido_id = ?, recargas_api_estado = ?, recargas_api_codigo_entregado = ?, recargas_api_ultimo_check = NOW(), recargas_api_historial_json = ?, estado = ? WHERE id = ? AND estado = 'pagado'");
            if ($verifyStmt) {
                $verifyStmt->bind_param('sssssssssi', $verifiedReference, $providerReference, $providerMessage, $providerPayload, $providerOrderId, $providerState, $providerCode, $providerHistoryJson, $verifiedStatus, $orderId);
                $verifyStmt->execute();
                $verifyStmt->close();
            }

            $verifiedOrder = fetch_order_by_id($mysqli, $orderId) ?: $updatedOrder;
            if (trim((string) ($verifiedOrder['estado'] ?? '')) === 'enviado') {
                win_points_handle_order_status_change($mysqli, $orderId, 'enviado');
                recharge_notifications_emit_for_order($mysqli, $verifiedOrder);
                ensure_provider_webhook_registration();
                register_influencer_coupon_sale($mysqli, $verifiedOrder);
                notify_free_fire_recharge_success($mysqli, $verifiedOrder, $paymentMethodName, $verifiedReference, $phone, $providerReference, $providerMessage);
            }

            return [
                'order' => $verifiedOrder,
                'local_status' => trim((string) ($verifiedOrder['estado'] ?? 'enviado')),
                'provider_flow' => order_provider_flow_from_row($verifiedOrder),
            ];
        }

        $inventoryShortage = recharge_availability_message_indicates_inventory_shortage($providerMessage);
        if ($inventoryShortage) {
            try {
                $shortageResult = mark_order_inventory_shortage_review(
                    $mysqli,
                    $updatedOrder,
                    'pagado',
                    $verifiedReference,
                    $phone,
                    $providerReference,
                    $providerMessage,
                    $providerPayload,
                    $providerOrderId,
                    $providerCode
                );
            } catch (Throwable $e) {
                return [
                    'order' => fetch_order_by_id($mysqli, $orderId) ?: $updatedOrder,
                    'local_status' => trim((string) ($updatedOrder['estado'] ?? 'pagado')),
                    'provider_flow' => order_provider_flow_from_row($updatedOrder),
                    'sync_error' => $e->getMessage(),
                ];
            }

            $paidOrder = $shortageResult['order'];
            recharge_notifications_emit_for_order($mysqli, $paidOrder);
            register_influencer_coupon_sale($mysqli, $paidOrder);
            notify_provider_inventory_shortage($mysqli, $paidOrder, $paymentMethodName, $verifiedReference, $phone, $providerMessage, $shortageResult['lock'] ?? []);

            return [
                'order' => $paidOrder,
                'local_status' => trim((string) ($paidOrder['estado'] ?? 'pagado')),
                'provider_flow' => order_provider_flow_from_row($paidOrder),
            ];
        }

        $manualProcessing = !empty($providerResult['manual_processing']);
        $acceptedLike = !empty($providerResult['accepted'])
            || ($manualProcessing && provider_message_indicates_pending_lookup($providerMessage));
        $trackingFollowUp = provider_message_indicates_transport_timeout($providerMessage);

        if ($trackingFollowUp || $acceptedLike) {
            $paidStatus = 'pagado';
            $trackedState = $providerState !== '' ? $providerState : 'pending_confirmation';
            $providerHistoryJson = append_provider_history(
                $updatedOrder['recargas_api_historial_json'] ?? null,
                build_provider_history_entry(
                    $trackingFollowUp ? 'purchase_timeout' : 'purchase',
                    $trackedState,
                    $paidStatus,
                    $providerMessage,
                    $providerReference,
                    $providerOrderId,
                    $providerCode
                )
            );
            $paidStmt = $mysqli->prepare("UPDATE pedidos SET numero_referencia = ?, ff_api_referencia = ?, ff_api_mensaje = ?, ff_api_payload = ?, recargas_api_pedido_id = ?, recargas_api_estado = ?, recargas_api_codigo_entregado = ?, recargas_api_ultimo_check = NOW(), recargas_api_historial_json = ?, estado = ? WHERE id = ? AND estado = 'pagado'");
            if ($paidStmt) {
                $paidStmt->bind_param('sssssssssi', $verifiedReference, $providerReference, $providerMessage, $providerPayload, $providerOrderId, $trackedState, $providerCode, $providerHistoryJson, $paidStatus, $orderId);
                $paidStmt->execute();
                $paidStmt->close();
            }

            $paidOrder = fetch_order_by_id($mysqli, $orderId) ?: $updatedOrder;
            recharge_notifications_emit_for_order($mysqli, $paidOrder);
            ensure_provider_webhook_registration();
            register_influencer_coupon_sale($mysqli, $paidOrder);
            notify_catalog_purchase_pending($mysqli, $paidOrder, $paymentMethodName, $verifiedReference, $phone, $providerReference, $providerMessage);

            if ($trackingFollowUp) {
                continue_provider_follow_up_in_background($mysqli, (int) ($paidOrder['id'] ?? $orderId), 8, 8);
            } elseif ($acceptedLike) {
                $autoSyncAttempts = $manualProcessing ? 5 : 3;
                $autoSyncDelaySeconds = $manualProcessing ? 4 : 2;
                try {
                    $autoSyncResult = try_auto_sync_provider_order($mysqli, $paidOrder, $autoSyncAttempts, $autoSyncDelaySeconds);
                    if (is_array($autoSyncResult['order'] ?? null)) {
                        $paidOrder = $autoSyncResult['order'];
                    } else {
                        $paidOrder = fetch_order_by_id($mysqli, $orderId) ?: $paidOrder;
                    }
                } catch (Throwable $e) {
                    $paidOrder = fetch_order_by_id($mysqli, $orderId) ?: $paidOrder;
                }
            }

            return [
                'order' => $paidOrder,
                'local_status' => trim((string) ($paidOrder['estado'] ?? 'pagado')),
                'provider_flow' => order_provider_flow_from_row($paidOrder),
            ];
        }

        $cancelStatus = 'cancelado';
        $failedHistoryJson = append_provider_history(
            $updatedOrder['recargas_api_historial_json'] ?? null,
            build_provider_history_entry(
                'purchase_failed',
                $providerState,
                $cancelStatus,
                $providerMessage,
                $providerReference,
                $providerOrderId,
                $providerCode
            )
        );
        $cancelStmt = $mysqli->prepare("UPDATE pedidos SET numero_referencia = ?, ff_api_referencia = ?, ff_api_mensaje = ?, ff_api_payload = ?, recargas_api_pedido_id = ?, recargas_api_estado = ?, recargas_api_codigo_entregado = ?, recargas_api_ultimo_check = NOW(), recargas_api_historial_json = ?, estado = ? WHERE id = ? AND estado = 'pagado'");
        if ($cancelStmt) {
            $cancelStmt->bind_param('sssssssssi', $verifiedReference, $providerReference, $providerMessage, $providerPayload, $providerOrderId, $providerState, $providerCode, $failedHistoryJson, $cancelStatus, $orderId);
            $cancelStmt->execute();
            $cancelStmt->close();
        }

        $cancelledOrder = fetch_order_by_id($mysqli, $orderId) ?: $updatedOrder;
        return [
            'order' => $cancelledOrder,
            'local_status' => trim((string) ($cancelledOrder['estado'] ?? 'cancelado')),
            'provider_flow' => order_provider_flow_from_row($cancelledOrder),
        ];
    } finally {
        release_order_provider_dispatch_lock($mysqli, $orderId);
    }
}

function sync_or_capture_paypal_order(mysqli $mysqli, array $order, string $source = 'sync'): array {
    if (!paypal_pay_is_enabled()) {
        return [
            'order' => $order,
            'local_status' => trim((string) ($order['estado'] ?? '')),
            'provider_flow' => order_provider_flow_from_row($order),
        ];
    }

    $paypalOrderId = trim((string) ($order['paypal_order_id'] ?? ''));
    if ($paypalOrderId === '') {
        return [
            'order' => $order,
            'local_status' => trim((string) ($order['estado'] ?? '')),
            'provider_flow' => order_provider_flow_from_row($order),
        ];
    }

    $payload = paypal_pay_get_order($paypalOrderId);
    $status = paypal_pay_extract_status($payload);
    if ($status === 'approved') {
        try {
            $payload = paypal_pay_capture_order($paypalOrderId);
        } catch (Throwable $e) {
            $latestPayload = paypal_pay_get_order($paypalOrderId);
            if (paypal_pay_extract_status($latestPayload) !== 'completed') {
                throw $e;
            }
            $payload = $latestPayload;
        }
    }

    return sync_local_order_with_paypal_payload($mysqli, $order, $payload, $source);
}

function try_auto_sync_binance_order(mysqli $mysqli, array $order, string $source = 'sync'): array {
    if (!binance_pay_is_enabled()) {
        return [
            'order' => $order,
            'local_status' => trim((string) ($order['estado'] ?? '')),
            'provider_flow' => order_provider_flow_from_row($order),
        ];
    }

    $reference = trim((string) ($order['binance_pay_reference'] ?? ''));
    if ($reference === '') {
        return [
            'order' => $order,
            'local_status' => trim((string) ($order['estado'] ?? '')),
            'provider_flow' => order_provider_flow_from_row($order),
        ];
    }

    $payload = binance_pay_query_payment($reference);
    return sync_local_order_with_binance_payload($mysqli, $order, $payload, $source);
}

function fetch_active_payment_method(mysqli $mysqli, int $methodId): ?array {
    $mysqli = ensure_mysqli_connection($mysqli);

    $stmt = $mysqli->prepare("SELECT pm.*, m.nombre AS moneda_nombre, m.clave AS moneda_clave
        FROM payment_methods pm
        INNER JOIN monedas m ON m.id = pm.moneda_id
        WHERE pm.id = ? AND pm.activo = 1
        LIMIT 1");
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $methodId);
    $stmt->execute();
    $res = $stmt->get_result();
    $method = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    return $method ?: null;
}

function binance_pagonorte_payment_enabled(): bool {
    return trim((string) store_config_get('binance_pagonorte_activo', '0')) === '1';
}

function binance_pagonorte_is_configured(): bool {
    return trim((string) store_config_get('binance_pagonorte_token', '')) !== '';
}

function binance_pagonorte_reference_digits(): int {
    return max(0, min(120, (int) store_config_get('binance_pagonorte_referencia_digitos', '0')));
}

function binance_pagonorte_reference_prefix(): string {
    return 'BINANCE:';
}

function binance_pagonorte_store_reference(string $reference): string {
    $trimmed = trim($reference);
    if ($trimmed === '') {
        return '';
    }

    return binance_pagonorte_reference_prefix() . $trimmed;
}

function normalize_currency_code(?string $currencyCode): string {
    $normalized = strtoupper(trim((string) $currencyCode));
    if ($normalized === '') {
        return '';
    }

    $normalized = preg_replace('/[^A-Z0-9]+/u', '', $normalized) ?? '';
    $normalized = str_replace(['Á', 'À', 'Ä', 'Â'], 'A', $normalized);
    $normalized = str_replace(['É', 'È', 'Ë', 'Ê'], 'E', $normalized);
    $normalized = str_replace(['Í', 'Ì', 'Ï', 'Î'], 'I', $normalized);
    $normalized = str_replace(['Ó', 'Ò', 'Ö', 'Ô'], 'O', $normalized);
    $normalized = str_replace(['Ú', 'Ù', 'Ü', 'Û'], 'U', $normalized);

    if (
        $normalized === 'BS'
        || $normalized === 'BSS'
        || str_contains($normalized, 'VES')
        || str_contains($normalized, 'VEF')
        || str_contains($normalized, 'BOLIVAR')
        || str_contains($normalized, 'BOLIVARES')
        || str_ends_with($normalized, 'BS')
    ) {
        return 'VES';
    }

    return $normalized;
}

function order_currency_uses_bank_api(?string $currencyCode): bool {
    $normalized = normalize_currency_code($currencyCode);
    return $normalized === 'VES';
}

function game_uses_free_fire_api(mysqli $mysqli, int $gameId): bool {
    if ($gameId <= 0) {
        return false;
    }

    if (!table_has_column($mysqli, 'juegos', 'api_free_fire')) {
        return false;
    }

    $stmt = $mysqli->prepare('SELECT api_free_fire FROM juegos WHERE id = ? LIMIT 1');
    if (!$stmt) {
        return false;
    }

    try {
        $stmt->bind_param('i', $gameId);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();
    } catch (Throwable $e) {
        error_log('TVG game_uses_free_fire_api failed: ' . $e->getMessage());
        try {
            $stmt->close();
        } catch (Throwable $closeError) {
        }
        return false;
    }

    return !empty($row['api_free_fire']);
}

function game_api_category(mysqli $mysqli, int $gameId): string {
    if ($gameId <= 0) {
        return '';
    }

    if (!table_has_column($mysqli, 'juegos', 'categoria_api')) {
        return '';
    }

    $stmt = $mysqli->prepare('SELECT categoria_api FROM juegos WHERE id = ? LIMIT 1');
    if (!$stmt) {
        return '';
    }

    try {
        $stmt->bind_param('i', $gameId);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();
    } catch (Throwable $e) {
        error_log('TVG game_api_category failed: ' . $e->getMessage());
        try {
            $stmt->close();
        } catch (Throwable $closeError) {
        }
        return '';
    }

    return trim((string) ($row['categoria_api'] ?? ''));
}

function game_uses_catalog_api(mysqli $mysqli, int $gameId): bool {
    return game_api_category($mysqli, $gameId) !== '';
}
function game_discord_api_command(mysqli $mysqli, int $gameId): string {
    if ($gameId <= 0) {
        return '';
    }

    $stmt = $mysqli->prepare('SELECT categoria_api_discord FROM juegos WHERE id = ? LIMIT 1');
    if (!$stmt) {
        return '';
    }

    $stmt->bind_param('i', $gameId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    return trim((string) ($row['categoria_api_discord'] ?? ''));
}

function game_uses_discord_api(mysqli $mysqli, int $gameId): bool {
    return game_discord_api_command($mysqli, $gameId) !== '';
}

function normalize_api_provider_value($value): string {
    $normalized = strtolower(trim((string) $value));
    return in_array($normalized, ['giftven', 'discord', 'free_fire'], true) ? $normalized : '';
}

function package_api_provider_from_row(array $package, array $game = []): string {
    $storedProvider = normalize_api_provider_value($package['api_provider'] ?? '');
    if ($storedProvider !== '') {
        return $storedProvider;
    }

    if ((int) ($package['paquete_api'] ?? 0) > 0) {
        return 'giftven';
    }

    if (trim((string) ($package['monto_ff'] ?? '')) !== '') {
        return 'free_fire';
    }

    if (trim((string) ($game['categoria_api_discord'] ?? '')) !== '') {
        return 'discord';
    }

    return '';
}

function order_api_provider(array $order): string {
    $storedProvider = normalize_api_provider_value($order['api_provider'] ?? '');
    if ($storedProvider !== '') {
        return $storedProvider;
    }

    if ((int) ($order['paquete_api'] ?? 0) > 0) {
        return 'giftven';
    }

    if (trim((string) ($order['monto_ff'] ?? '')) !== '') {
        return 'free_fire';
    }

    if (trim((string) ($order['api_discord_command_key'] ?? '')) !== '') {
        return 'discord';
    }

    return '';
}

function order_uses_catalog_api_provider(array $order): bool {
    return order_api_provider($order) === 'giftven';
}

function order_uses_free_fire_api_provider(array $order): bool {
    return order_api_provider($order) === 'free_fire';
}

function fetch_game_package(mysqli $mysqli, int $packageId, int $gameId): ?array {
    if ($packageId <= 0 || $gameId <= 0) {
        return null;
    }

    $stmt = $mysqli->prepare('SELECT * FROM juego_paquetes WHERE id = ? AND juego_id = ? LIMIT 1');
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('ii', $packageId, $gameId);
    $stmt->execute();
    $res = $stmt->get_result();
    $package = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    return $package ?: null;
}

function normalize_player_field_key(string $key): string {
    $normalized = strtolower(trim($key));
    return preg_replace('/[^a-z0-9_]+/u', '', $normalized) ?? '';
}

function player_field_aliases(string $fieldName): array {
    $normalized = normalize_player_field_key($fieldName);
    if ($normalized === '') {
        return [];
    }

    $aliasGroups = [
        ['id_juego', 'player_id', 'playerid', 'user_id', 'userid', 'input1'],
        ['zone_id', 'zoneid', 'zona', 'zone', 'server_id', 'serverid', 'input2'],
    ];

    foreach ($aliasGroups as $group) {
        if (in_array($normalized, $group, true)) {
            return $group;
        }
    }

    return [$normalized];
}

function resolve_player_field_value(array $submittedFields, string $requiredFieldName, ?string $fallbackValue = null): string {
    foreach (player_field_aliases($requiredFieldName) as $alias) {
        $value = trim((string) ($submittedFields[$alias] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }

    return trim((string) $fallbackValue);
}

function parse_player_fields_request($raw): array {
    if (is_string($raw)) {
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return [];
        }

        $decoded = json_decode($trimmed, true);
        if (!is_array($decoded)) {
            return [];
        }
    } elseif (is_array($raw)) {
        $decoded = $raw;
    } else {
        return [];
    }

    $fields = [];
    foreach ($decoded as $key => $value) {
        $normalizedKey = normalize_player_field_key((string) $key);
        if ($normalizedKey === '') {
            continue;
        }

        $sanitizedValue = sanitize_str((string) $value, 180);
        if ($sanitizedValue === null || $sanitizedValue === '') {
            continue;
        }

        $fields[$normalizedKey] = $sanitizedValue;
        if (count($fields) >= 8) {
            break;
        }
    }

    return $fields;
}

function order_player_fields_from_json(?string $json): array {
    if (!is_string($json) || trim($json) === '') {
        return [];
    }

    $decoded = json_decode($json, true);
    return is_array($decoded) ? parse_player_fields_request($decoded) : [];
}

function build_catalog_player_fields(array $product, ?string $userIdentifier, array $submittedFields): array {
    $playerFields = [];
    $requiredFields = recargas_api_normalize_required_fields($product['campos_requeridos'] ?? []);

    foreach ($requiredFields as $index => $fieldMeta) {
        $fieldName = normalize_player_field_key((string) ($fieldMeta['name'] ?? ''));
        if ($fieldName === '') {
            continue;
        }

        $fallbackValue = $index === 0 ? $userIdentifier : null;
        $value = resolve_player_field_value($submittedFields, $fieldName, $fallbackValue);

        if ($value === '') {
            throw new RuntimeException('Falta el campo requerido: ' . recargas_api_field_label($fieldName) . '.');
        }

        $playerFields[$fieldName] = $value;
    }

    foreach ($submittedFields as $fieldName => $value) {
        if ($value === '' || isset($playerFields[$fieldName])) {
            continue;
        }

        $playerFields[$fieldName] = $value;
    }

    return $playerFields;
}

function catalog_provider_payload_key(array $product, array $fieldMeta): string {
    $providerName = normalize_player_field_key((string) ($fieldMeta['provider_name'] ?? ''));
    $canonicalName = normalize_player_field_key((string) ($fieldMeta['name'] ?? ''));

    if ($providerName === 'input1' && $canonicalName === 'id_juego') {
        return 'id_juego';
    }

    return $providerName !== '' ? $providerName : $canonicalName;
}

function primary_player_identifier_from_fields(array $playerFields): ?string {
    foreach ($playerFields as $value) {
        $normalized = trim((string) $value);
        if ($normalized !== '') {
            return sanitize_str($normalized, 150);
        }
    }

    return null;
}

function recargas_api_extract_provider_order_id(array $response): string {
    return sanitize_str((string) ($response['id'] ?? $response['pedido_id'] ?? $response['referencia'] ?? ''), 120) ?? '';
}

function provider_is_sequential_list(array $value): bool {
    $expectedIndex = 0;
    foreach (array_keys($value) as $key) {
        if ($key !== $expectedIndex) {
            return false;
        }

        $expectedIndex++;
    }

    return true;
}

function provider_stringify_delivered_code_value($value): array {
    if (is_array($value)) {
        $codes = [];
        foreach (['codigo', 'pin', 'serial', 'voucher', 'code', 'clave'] as $preferredKey) {
            if (!array_key_exists($preferredKey, $value)) {
                continue;
            }

            foreach (provider_stringify_delivered_code_value($value[$preferredKey]) as $candidate) {
                $codes[$candidate] = true;
            }
        }

        if (!empty($codes)) {
            return array_keys($codes);
        }

        if (!provider_is_sequential_list($value)) {
            $parts = [];
            foreach ($value as $key => $item) {
                if (is_array($item)) {
                    continue;
                }

                $normalizedItem = trim((string) $item);
                if ($normalizedItem === '') {
                    continue;
                }

                $label = trim((string) $key);
                $parts[] = $label !== '' ? $label . ': ' . $normalizedItem : $normalizedItem;
            }

            if (!empty($parts)) {
                return [implode(' | ', $parts)];
            }
        }

        foreach ($value as $item) {
            foreach (provider_stringify_delivered_code_value($item) as $candidate) {
                $codes[$candidate] = true;
            }
        }

        return array_keys($codes);
    }

    $normalized = trim((string) $value);
    return $normalized !== '' ? [$normalized] : [];
}

function provider_extract_delivered_codes(array $detail): array {
    $codes = [];

    foreach (['codigos', 'codigos_entregados', 'codigo_entregado', 'codigo', 'pin', 'serial', 'voucher'] as $key) {
        if (!array_key_exists($key, $detail)) {
            continue;
        }

        foreach (provider_stringify_delivered_code_value($detail[$key]) as $candidate) {
            $codes[$candidate] = true;
        }
    }

    return array_keys($codes);
}

function provider_delivered_code_text(array $detail): string {
    return implode("\n", provider_extract_delivered_codes($detail));
}

function provider_status_to_local_status(string $providerStatus): ?string {
    $normalized = strtolower(trim($providerStatus));

    return match ($normalized) {
        'completado', 'completed', 'success' => 'enviado',
        'procesando', 'processing', 'pending' => 'pagado',
        'cancelado', 'cancelled', 'canceled' => 'cancelado',
        default => null,
    };
}

function extract_provider_order_detail(array $response): array {
    if (isset($response['pedido']) && is_array($response['pedido'])) {
        return $response['pedido'];
    }

    return $response;
}

function provider_order_display_reference(array $detail, string $fallback = ''): string {
    $reference = sanitize_str((string) ($detail['referencia'] ?? $detail['pedido_id'] ?? ''), 120);
    if ($reference === null || $reference === '') {
        return $fallback;
    }

    return $reference;
}

function provider_order_status_message(array $detail, string $fallback = ''): string {
    $parts = [];
    foreach (['razon', 'nombre_jugador'] as $key) {
        $value = trim((string) ($detail[$key] ?? ''));
        if ($value !== '') {
            $parts[] = $value;
        }
    }

    $deliveredCodes = provider_extract_delivered_codes($detail);
    if (!empty($deliveredCodes)) {
        $parts[] = 'Codigos entregados: ' . implode(' / ', $deliveredCodes);
    }

    $deliveredCount = (int) ($detail['cantidad_entregada'] ?? 0);
    if ($deliveredCount > 1) {
        $parts[] = 'Cantidad entregada: ' . $deliveredCount;
    }

    if (!empty($parts)) {
        return implode(' | ', $parts);
    }

    return $fallback;
}

function free_fire_api_config(): array {
    return [
        'usuario' => trim(store_config_get('ff_api_usuario', '')),
        'clave' => trim(store_config_get('ff_api_clave', '')),
        'tipo' => trim(store_config_get('ff_api_tipo', 'recargaFreefire')),
    ];
}

function free_fire_api_config_is_complete(array $config): bool {
    return trim((string) ($config['usuario'] ?? '')) !== ''
        && trim((string) ($config['clave'] ?? '')) !== ''
        && trim((string) ($config['tipo'] ?? '')) !== '';
}

function free_fire_api_alert_is_success(?string $alert): bool {
    $normalized = strtolower(trim((string) $alert));
    return in_array($normalized, ['green', 'success', 'ok'], true);
}

function execute_free_fire_recharge(array $config, string $monto, string $numero): array {
    if (!free_fire_api_config_is_complete($config)) {
        throw new RuntimeException('La configuración de la API de Free Fire está incompleta.');
    }

    if ($monto === '') {
        throw new RuntimeException('El paquete seleccionado no tiene monto_ff configurado.');
    }

    if ($numero === '') {
        throw new RuntimeException('El ID del jugador es obligatorio para la recarga de Free Fire.');
    }

    $url = 'https://www.tiendagiftven.net/conexion_api/api.php?' . http_build_query([
        'action' => 'recarga',
        'usuario' => $config['usuario'],
        'clave' => $config['clave'],
        'tipo' => $config['tipo'],
        'monto' => $monto,
        'numero' => $numero,
    ]);

    $response = http_get_json($url, 25, true);
    $message = trim((string) ($response['mensaje'] ?? ''));

    return [
        'success' => free_fire_api_alert_is_success($response['alerta'] ?? null),
        'message' => $message !== '' ? $message : 'Respuesta recibida desde la API de Free Fire.',
        'reference' => sanitize_str((string) ($response['referencia'] ?? ''), 120),
        'payload' => $response,
    ];
}

function recargas_api_result_is_success(array $response): bool {
    return recargas_api_purchase_is_completed($response);
}

function provider_message_indicates_pending_lookup(string $message): bool {
    $normalized = mb_strtolower(trim(strip_tags($message)), 'UTF-8');
    if ($normalized === '') {
        return false;
    }

    return str_contains($normalized, 'not found order by referenceno')
        || str_contains($normalized, 'no found order by referenceno')
        || str_contains($normalized, 'not found order')
        || str_contains($normalized, 'reference no');
}

function provider_message_indicates_transport_timeout(string $message): bool {
    $normalized = mb_strtolower(trim(strip_tags($message)), 'UTF-8');
    if ($normalized === '') {
        return false;
    }

    return str_contains($normalized, 'operation timed out')
        || str_contains($normalized, 'timed out')
        || str_contains($normalized, 'timeout')
        || str_contains($normalized, '0 bytes received')
        || str_contains($normalized, 'respuesta vacía')
        || str_contains($normalized, 'respuesta vacia')
        || str_contains($normalized, 'no devolvió un json válido')
        || str_contains($normalized, 'no devolvio un json valido')
        || str_contains($normalized, 'no devolvió un json')
        || str_contains($normalized, 'no devolvio un json')
        || str_contains($normalized, 'incompleta')
        || str_contains($normalized, 'empty reply from server')
        || str_contains($normalized, 'connection reset by peer')
        || str_contains($normalized, 'failed to connect');
}

function order_provider_flow_from_row(array $order): string {
    $localStatus = strtolower(trim((string) ($order['estado'] ?? '')));
    if (order_is_account_sale($order) && $localStatus === 'enviado') {
        return 'account_sale';
    }
    if ($localStatus === 'enviado') {
        return 'completed';
    }
    if ($localStatus === 'cancelado') {
        return 'cancelled';
    }

    if (order_uses_api_discord($order) && $localStatus === 'pagado') {
        $discordStatus = normalize_api_discord_order_status((string) ($order['api_discord_status'] ?? ''));
        if ($discordStatus === 'confirmed') {
            return 'completed';
        }
        if ($discordStatus === 'processing' || $discordStatus === 'queued') {
            return 'tracking';
        }
        if ($discordStatus === 'sent') {
            return 'accepted';
        }
        if (in_array($discordStatus, ['failed', 'review', 'ready'], true)) {
            return 'manual_review';
        }
        if ($discordStatus === 'cancelled') {
            return 'cancelled';
        }

        return 'accepted';
    }

    if (trim((string) ($order['recargas_api_codigo_entregado'] ?? '')) !== '') {
        return 'completed';
    }

    $providerStatus = strtolower(trim((string) ($order['recargas_api_estado'] ?? '')));
    if ($providerStatus === 'inventory_shortage' || recharge_availability_message_indicates_inventory_shortage((string) ($order['ff_api_mensaje'] ?? ''))) {
        return 'inventory_shortage';
    }
    if ($providerStatus === 'pending_confirmation') {
        return 'tracking';
    }

    $hasProviderTracking = trim((string) ($order['ff_api_referencia'] ?? '')) !== ''
        || trim((string) ($order['recargas_api_pedido_id'] ?? '')) !== ''
        || trim((string) ($order['recargas_api_estado'] ?? '')) !== '';
    if ($hasProviderTracking && $localStatus === 'pagado') {
        return 'accepted';
    }

    $hasBinanceTracking = trim((string) ($order['binance_pay_reference'] ?? '')) !== ''
        || trim((string) ($order['binance_pay_order_no'] ?? '')) !== ''
        || trim((string) ($order['binance_pay_request_id'] ?? '')) !== '';
    if ($hasBinanceTracking && $localStatus === 'pendiente') {
        $binanceStatus = binance_pay_normalize_status($order['binance_pay_status'] ?? '');
        if (binance_pay_is_failed_status($binanceStatus)) {
            return 'cancelled';
        }
        return 'binance_checkout';
    }

    $hasPaypalTracking = trim((string) ($order['paypal_order_id'] ?? '')) !== ''
        || trim((string) ($order['paypal_capture_id'] ?? '')) !== '';
    if ($hasPaypalTracking && $localStatus === 'pendiente') {
        $paypalStatus = paypal_pay_extract_status(['status' => $order['paypal_status'] ?? '']);
        if (paypal_pay_is_failed_status($paypalStatus)) {
            return 'cancelled';
        }
        return 'paypal_checkout';
    }

    return '';
}

function build_provider_sync_snapshot(array $order, ?string $syncError = null): array {
    return [
        'order' => $order,
        'provider_status' => trim((string) ($order['recargas_api_estado'] ?? '')),
        'local_status' => trim((string) ($order['estado'] ?? 'pagado')),
        'provider_reference' => trim((string) ($order['ff_api_referencia'] ?? '')),
        'provider_message' => trim((string) ($order['ff_api_mensaje'] ?? '')),
        'refund_amount' => isset($order['recargas_api_reembolso']) ? (float) $order['recargas_api_reembolso'] : null,
        'provider_code' => trim((string) ($order['recargas_api_codigo_entregado'] ?? '')),
        'sync_error' => $syncError !== null ? trim($syncError) : '',
    ];
}

function order_provider_request_payload(array $order): array {
    $payload = json_decode((string) ($order['ff_api_payload'] ?? ''), true);
    if (!is_array($payload)) {
        return [];
    }

    $requestPayload = $payload['request_payload'] ?? null;
    return is_array($requestPayload) ? $requestPayload : [];
}

function provider_normalize_match_value($value): string {
    if ($value === null) {
        return '';
    }

    if (is_bool($value)) {
        return $value ? '1' : '0';
    }

    $normalized = trim((string) $value);
    if ($normalized === '') {
        return '';
    }

    $normalized = mb_strtolower($normalized, 'UTF-8');
    $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;
    return trim($normalized);
}

function provider_collect_match_values($value, int $depth = 0): array {
    if ($depth > 4) {
        return [];
    }

    $values = [];

    if (is_array($value)) {
        foreach ($value as $item) {
            foreach (provider_collect_match_values($item, $depth + 1) as $candidate => $enabled) {
                if ($enabled) {
                    $values[$candidate] = true;
                }
            }
        }
        return $values;
    }

    if (is_object($value)) {
        return provider_collect_match_values((array) $value, $depth + 1);
    }

    $normalized = provider_normalize_match_value($value);
    if ($normalized !== '') {
        $values[$normalized] = true;
    }

    return $values;
}

function provider_expected_match_values(array $requestPayload): array {
    $expected = [];

    foreach ($requestPayload as $key => $value) {
        if (in_array((string) $key, ['producto_id', 'request_payload', 'exception'], true)) {
            continue;
        }

        foreach (provider_collect_match_values($value) as $candidate => $enabled) {
            if ($enabled) {
                $expected[$candidate] = true;
            }
        }
    }

    return $expected;
}

function provider_candidate_product_id(array $providerCandidate): int {
    foreach (['producto_id', 'product_id', 'id_producto'] as $key) {
        if (isset($providerCandidate[$key]) && is_numeric($providerCandidate[$key])) {
            return (int) $providerCandidate[$key];
        }
    }

    return 0;
}

function provider_candidate_timestamp(array $providerCandidate): ?int {
    foreach (['fecha', 'fecha_creacion', 'creado_en', 'created_at', 'updated_at', 'fecha_actualizacion'] as $key) {
        $raw = trim((string) ($providerCandidate[$key] ?? ''));
        if ($raw === '') {
            continue;
        }

        $timestamp = strtotime($raw);
        if ($timestamp !== false) {
            return $timestamp;
        }
    }

    return null;
}

function provider_candidate_score_for_order(array $order, array $providerCandidate): int {
    $requestPayload = order_provider_request_payload($order);
    if (!$requestPayload) {
        return 0;
    }

    $expectedValues = provider_expected_match_values($requestPayload);
    if (!$expectedValues) {
        return 0;
    }

    $providerValues = provider_collect_match_values($providerCandidate);
    if (!$providerValues) {
        return 0;
    }

    $expectedProductId = isset($requestPayload['producto_id']) && is_numeric($requestPayload['producto_id'])
        ? (int) $requestPayload['producto_id']
        : (int) ($order['paquete_api'] ?? 0);
    $providerProductId = provider_candidate_product_id($providerCandidate);
    if ($expectedProductId > 0 && $providerProductId > 0 && $expectedProductId !== $providerProductId) {
        return 0;
    }

    $matchedValues = 0;
    foreach (array_keys($expectedValues) as $expectedValue) {
        if (isset($providerValues[$expectedValue])) {
            $matchedValues++;
        }
    }

    if ($matchedValues === 0) {
        return 0;
    }

    $score = $matchedValues * 10;
    if ($expectedProductId > 0 && $providerProductId === $expectedProductId) {
        $score += 20;
    }

    $orderCreatedTs = isset($order['creado_en_ts']) ? (int) $order['creado_en_ts'] : 0;
    $providerTimestamp = provider_candidate_timestamp($providerCandidate);
    if ($orderCreatedTs > 0 && $providerTimestamp !== null) {
        $timeDiff = abs($providerTimestamp - $orderCreatedTs);
        if ($timeDiff <= 1800) {
            $score += 5;
        } elseif ($timeDiff > 86400) {
            $score -= 20;
        }
    }

    if (recargas_api_extract_provider_order_id($providerCandidate) !== '') {
        $score += 3;
    }

    return $score;
}

function find_provider_candidate_for_local_order(array $order): ?array {
    $bestCandidate = null;
    $bestScore = 0;
    $requestPayload = order_provider_request_payload($order);
    if (!$requestPayload) {
        return null;
    }

    $expectedValueCount = count(provider_expected_match_values($requestPayload));
    $minimumScore = $expectedValueCount <= 1 ? 10 : 20;
    $sources = [
        'orders' => 'recargas_api_fetch_recent_orders',
        'transactions' => 'recargas_api_fetch_transactions',
    ];
    $lastError = null;

    foreach ($sources as $sourceCallback) {
        try {
            $items = $sourceCallback();
        } catch (Throwable $e) {
            $lastError = $e;
            continue;
        }

        foreach ($items as $providerCandidate) {
            if (!is_array($providerCandidate)) {
                continue;
            }

            $score = provider_candidate_score_for_order($order, $providerCandidate);
            if ($score < $minimumScore || $score <= $bestScore) {
                continue;
            }

            $bestCandidate = $providerCandidate;
            $bestScore = $score;
        }
    }

    if (is_array($bestCandidate)) {
        return $bestCandidate;
    }

    if ($lastError !== null) {
        throw $lastError;
    }

    return null;
}

function try_recover_uncertain_provider_purchase(mysqli $mysqli, array $order, int $attempts = 6, int $delaySeconds = 8): ?array {
    $orderId = (int) ($order['id'] ?? 0);
    if ($orderId <= 0) {
        return null;
    }

    $attempts = max(1, $attempts);
    $delaySeconds = max(0, $delaySeconds);
    $latestResult = build_provider_sync_snapshot($order);

    for ($attempt = 1; $attempt <= $attempts; $attempt++) {
        $mysqli = ensure_mysqli_connection($mysqli);
        $order = fetch_order_by_id($mysqli, $orderId) ?: $order;

        if (in_array((string) ($order['estado'] ?? ''), ['enviado', 'cancelado'], true)) {
            return build_provider_sync_snapshot($order);
        }

        $providerOrderId = trim((string) ($order['recargas_api_pedido_id'] ?? ''));
        if ($providerOrderId !== '') {
            return try_auto_sync_provider_order($mysqli, $order, max(1, $attempts - $attempt + 1), $delaySeconds);
        }

        try {
            $providerCandidate = find_provider_candidate_for_local_order($order);
            if (is_array($providerCandidate)) {
                $candidateIdentifier = recargas_api_extract_provider_order_id($providerCandidate);
                $providerDetail = $providerCandidate;

                if ($candidateIdentifier !== '') {
                    try {
                        $providerDetail = recargas_api_fetch_order_detail($candidateIdentifier);
                    } catch (Throwable $detailError) {
                    }
                }

                $latestResult = sync_local_order_with_provider_detail($mysqli, $order, $providerDetail, true);
                $order = is_array($latestResult['order'] ?? null)
                    ? $latestResult['order']
                    : (fetch_order_by_id($mysqli, $orderId) ?: $order);

                if (in_array((string) ($latestResult['local_status'] ?? ''), ['enviado', 'cancelado'], true)) {
                    return $latestResult;
                }

                $providerOrderId = trim((string) ($order['recargas_api_pedido_id'] ?? ''));
                if ($providerOrderId !== '') {
                    return try_auto_sync_provider_order($mysqli, $order, max(1, $attempts - $attempt + 1), $delaySeconds);
                }
            }
        } catch (Throwable $e) {
            $latestResult = build_provider_sync_snapshot($order, $e->getMessage());
        }

        if ($attempt < $attempts && $delaySeconds > 0) {
            sleep($delaySeconds);
        }
    }

    return $latestResult;
}

function continue_provider_follow_up_in_background(mysqli $mysqli, int $orderId, int $attempts = 8, int $delaySeconds = 8): void {
    if ($orderId <= 0) {
        return;
    }

    try {
        $mysqli = ensure_mysqli_connection($mysqli);
        $order = fetch_order_by_id($mysqli, $orderId);
        if (!$order) {
            return;
        }

        if (in_array((string) ($order['estado'] ?? ''), ['enviado', 'cancelado'], true)) {
            return;
        }

        try_recover_uncertain_provider_purchase($mysqli, $order, $attempts, $delaySeconds);
    } catch (Throwable $e) {
        error_log('TVG provider follow-up error for order #' . $orderId . ': ' . $e->getMessage());
    }
}

function execute_catalog_api_purchase_once(int $productId, ?string $userIdentifier, array $playerFields = []): array {
    if ($productId <= 0) {
        throw new RuntimeException('El paquete seleccionado no tiene un producto API configurado.');
    }

    if (!recargas_api_is_configured()) {
        throw new RuntimeException('La API KEY de recargas no está configurada.');
    }

    $product = recargas_api_fetch_product_by_id($productId);
    if ($product === null) {
        throw new RuntimeException('El producto API configurado ya no está disponible en el catálogo remoto.');
    }

    $payload = [
        'producto_id' => $productId,
    ];

    $normalizedFields = build_catalog_player_fields($product, $userIdentifier, $playerFields);
    foreach (recargas_api_normalize_required_fields($product['campos_requeridos'] ?? []) as $fieldMeta) {
        $canonicalName = normalize_player_field_key((string) ($fieldMeta['name'] ?? ''));
        if ($canonicalName === '' || !isset($normalizedFields[$canonicalName])) {
            continue;
        }

        $payload[catalog_provider_payload_key($product, $fieldMeta)] = $normalizedFields[$canonicalName];
    }

    foreach ($normalizedFields as $fieldName => $fieldValue) {
        if ($fieldValue === '' || isset($payload[$fieldName])) {
            continue;
        }

        $payload[$fieldName] = $fieldValue;
    }

    try {
        $response = recargas_api_post_json_with_fallback(
            'https://tiendagiftven.tech/api/v1/comprar',
            $payload,
            ['X-API-Key: ' . recargas_api_key()],
            recargas_api_purchase_timeout_seconds()
        );
    } catch (Throwable $e) {
        return [
            'success' => false,
            'accepted' => false,
            'message' => trim((string) $e->getMessage()) !== ''
                ? trim((string) $e->getMessage())
                : 'La API de recargas rechazó la compra.',
            'reference' => '',
            'payload' => [
                'exception' => $e->getMessage(),
                'request_payload' => $payload,
            ],
        ];
    }

    $message = trim((string) ($response['mensaje'] ?? $response['error'] ?? ''));

    return [
        'success' => recargas_api_result_is_success($response),
        'accepted' => recargas_api_purchase_is_accepted($response),
        'manual_processing' => !empty($product['procesamiento_manual']),
        'message' => $message !== '' ? $message : 'Respuesta recibida desde la API de recargas.',
        'reference' => sanitize_str((string) ($response['referencia'] ?? $response['pedido_id'] ?? ''), 120),
        'payload' => $response,
    ];
}

function summarize_catalog_api_purchase_results(array $attemptResults, int $quantity): array {
    $successCount = 0;
    $acceptedCount = 0;
    $manualProcessing = false;
    $references = [];
    $providerOrderIds = [];
    $deliveredCodes = [];
    $attemptPayloads = [];
    $messages = [];

    foreach ($attemptResults as $attemptResult) {
        if (!empty($attemptResult['success'])) {
            $successCount++;
        }
        if (!empty($attemptResult['accepted'])) {
            $acceptedCount++;
        }
        if (!empty($attemptResult['manual_processing'])) {
            $manualProcessing = true;
        }

        $attemptPayload = (array) ($attemptResult['payload'] ?? []);
        $attemptPayloads[] = [
            'intento' => (int) ($attemptResult['attempt'] ?? (count($attemptPayloads) + 1)),
            'success' => !empty($attemptResult['success']),
            'accepted' => !empty($attemptResult['accepted']),
            'manual_processing' => !empty($attemptResult['manual_processing']),
            'message' => trim((string) ($attemptResult['message'] ?? '')),
            'reference' => trim((string) ($attemptResult['reference'] ?? '')),
            'payload' => $attemptPayload,
        ];

        $message = trim((string) ($attemptResult['message'] ?? ''));
        if ($message !== '') {
            $messages[] = $message;
        }

        $reference = sanitize_str((string) ($attemptResult['reference'] ?? ''), 120) ?? '';
        if ($reference !== '') {
            $references[$reference] = true;
        }

        $providerOrderId = recargas_api_extract_provider_order_id($attemptPayload);
        if ($providerOrderId !== '') {
            $providerOrderIds[$providerOrderId] = true;
        }

        foreach (provider_extract_delivered_codes($attemptPayload) as $code) {
            $normalizedCode = trim((string) $code);
            if ($normalizedCode !== '') {
                $deliveredCodes[$normalizedCode] = true;
            }
        }
    }

    $failureCount = max(0, $quantity - $successCount - $acceptedCount);
    $partialSuccess = $successCount > 0 && $successCount < $quantity;
    $overallSuccess = $successCount === $quantity;
    $overallAccepted = !$overallSuccess && ($acceptedCount > 0 || $partialSuccess);

    if ($overallSuccess) {
        $message = $quantity === 1
            ? trim((string) ($attemptResults[0]['message'] ?? ''))
            : 'Se procesaron correctamente las ' . $quantity . ' recargas solicitadas.';
    } elseif ($partialSuccess) {
        $message = 'Se procesaron ' . $successCount . ' de ' . $quantity . ' recargas. El resto quedo pendiente de revision manual.';
    } elseif ($overallAccepted) {
        $message = 'La compra por cantidad quedo en seguimiento mientras el proveedor confirma las ' . $quantity . ' recargas.';
    } else {
        $message = $quantity === 1
            ? trim((string) ($attemptResults[0]['message'] ?? ''))
            : 'No se pudo procesar ninguna de las ' . $quantity . ' recargas solicitadas.';
    }

    if ($message === '' && !empty($messages)) {
        $message = $messages[0];
    }
    if ($message === '') {
        $message = 'No se recibio un mensaje detallado del proveedor.';
    }

    return [
        'success' => $overallSuccess,
        'accepted' => $overallAccepted,
        'manual_processing' => $manualProcessing || $partialSuccess,
        'partial_success' => $partialSuccess,
        'message' => $message,
        'reference' => array_key_first($references) ?: '',
        'payload' => [
            'pedido_id' => array_key_first($providerOrderIds) ?: '',
            'referencia' => array_key_first($references) ?: '',
            'estado' => $overallSuccess ? 'completed' : ($overallAccepted ? 'processing' : 'failed'),
            'mensaje' => $message,
            'cantidad_compra' => $quantity,
            'codigos_entregados' => array_keys($deliveredCodes),
            'resumen' => [
                'success_count' => $successCount,
                'accepted_count' => $acceptedCount,
                'failed_count' => $failureCount,
                'partial_success' => $partialSuccess,
            ],
            'intentos' => $attemptPayloads,
        ],
    ];
}

function execute_catalog_api_purchase(int $productId, ?string $userIdentifier, array $playerFields = [], int $quantity = 1): array {
    $purchaseQuantity = max(1, $quantity);
    if ($purchaseQuantity === 1) {
        return execute_catalog_api_purchase_once($productId, $userIdentifier, $playerFields);
    }

    $attemptResults = [];
    for ($attempt = 1; $attempt <= $purchaseQuantity; $attempt++) {
        $attemptResult = execute_catalog_api_purchase_once($productId, $userIdentifier, $playerFields);
        $attemptResult['attempt'] = $attempt;
        $attemptResults[] = $attemptResult;
    }

    return summarize_catalog_api_purchase_results($attemptResults, $purchaseQuantity);
}

function parse_bank_movement_datetime(?string $value): ?string {
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

function normalize_bank_amount($value): float {
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

function http_get_json(string $url, int $timeout = 20, bool $verifySsl = true): array {
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

function fetch_bank_movements(array $config): array {
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

    $data = http_get_json($url, 20, false);
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
            'descripcion' => sanitize_str((string) ($movement['descripcion'] ?? ''), 255),
            'fecha_raw' => sanitize_str((string) ($movement['fecha'] ?? ''), 120),
            'fecha_movimiento' => parse_bank_movement_datetime((string) ($movement['fecha'] ?? '')),
            'tipo' => sanitize_str((string) ($movement['tipo'] ?? ''), 80),
            'monto' => normalize_bank_amount($movement['monto'] ?? 0),
            'moneda' => 'VES',
            'payload_json' => json_encode($movement, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
    }

    return $normalized;
}

function fetch_binance_pagonorte_movements(array $config): array {
    $token = trim((string) ($config['binance_pagonorte_token'] ?? ''));
    if ($token === '') {
        throw new RuntimeException('La conexión automática para Binance no está configurada completamente.');
    }

    $url = store_config_build_binance_pagonorte_movements_url($token);
    $data = http_get_json($url, 20, false);
    $availableDays = isset($data['dias_disponibles']) ? max(0, (int) $data['dias_disponibles']) : null;
    $cutoffDate = trim((string) ($data['fecha_corte'] ?? ''));
    store_config_upsert('binance_pagonorte_dias_disponibles', $availableDays !== null ? (string) $availableDays : '');
    store_config_upsert('binance_pagonorte_fecha_corte', $cutoffDate);

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
            'referencia' => substr(binance_pagonorte_store_reference($reference), 0, 120),
            'descripcion' => sanitize_str((string) ($movement['descripcion'] ?? ''), 255),
            'fecha_raw' => sanitize_str((string) ($movement['fecha'] ?? ''), 120),
            'fecha_movimiento' => parse_bank_movement_datetime((string) ($movement['fecha'] ?? '')),
            'tipo' => sanitize_str((string) ($movement['tipo'] ?? ''), 80),
            'monto' => normalize_bank_amount($movement['monto'] ?? 0),
            'moneda' => 'USDT',
            'payload_json' => json_encode($movement, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
    }

    return $normalized;
}

function fetch_and_sync_bank_movements(mysqli $mysqli, array $config): array {
    $movements = fetch_bank_movements($config);
    sync_bank_movements($mysqli, $movements);
    return $movements;
}

function fetch_and_sync_binance_pagonorte_movements(mysqli $mysqli, array $config): array {
    $movements = fetch_binance_pagonorte_movements($config);
    sync_bank_movements($mysqli, $movements);
    return $movements;
}

function sync_bank_movements(mysqli $mysqli, array $movements): void {
    if (empty($movements)) {
        return;
    }

    $mysqli = ensure_mysqli_connection($mysqli);

    $stmt = $mysqli->prepare(
        'INSERT INTO movimientos (referencia, descripcion, fecha_raw, fecha_movimiento, tipo, monto, moneda, payload_json) VALUES (?, ?, ?, ?, ?, ?, ?, ?) '
        . 'ON DUPLICATE KEY UPDATE descripcion = VALUES(descripcion), fecha_raw = VALUES(fecha_raw), fecha_movimiento = COALESCE(VALUES(fecha_movimiento), fecha_movimiento), tipo = VALUES(tipo), monto = VALUES(monto), moneda = VALUES(moneda), payload_json = VALUES(payload_json)'
    );
    if (!$stmt) {
        throw new RuntimeException('No se pudo preparar el registro de movimientos bancarios.');
    }

    foreach ($movements as $movement) {
        $reference = (string) ($movement['referencia'] ?? '');
        $description = (string) ($movement['descripcion'] ?? '');
        $rawDate = (string) ($movement['fecha_raw'] ?? '');
        $movementDate = $movement['fecha_movimiento'] !== null ? (string) $movement['fecha_movimiento'] : null;
        $type = (string) ($movement['tipo'] ?? '');
        $amount = (float) ($movement['monto'] ?? 0);
        $currency = (string) ($movement['moneda'] ?? 'VES');
        $payloadJson = (string) ($movement['payload_json'] ?? '');

        if (!$stmt->bind_param(
            'sssssdss',
            $reference,
            $description,
            $rawDate,
            $movementDate,
            $type,
            $amount,
            $currency,
            $payloadJson
        )) {
            throw new RuntimeException('No se pudieron enlazar los datos del movimiento bancario: ' . $stmt->error);
        }

        if (!$stmt->execute()) {
            throw new RuntimeException('No se pudo registrar el movimiento bancario ' . $reference . ': ' . $stmt->error);
        }
    }

    $stmt->close();
}

function movement_reference_matches(string $fullReference, string $reportedReference, int $requiredDigits): bool {
    if ($reportedReference === '') {
        return false;
    }

    if ($requiredDigits > 0) {
        $normalizedReportedReference = strlen($reportedReference) > $requiredDigits
            ? substr($reportedReference, -$requiredDigits)
            : $reportedReference;

        return $fullReference === $reportedReference
            || substr($fullReference, -$requiredDigits) === $normalizedReportedReference;
    }

    return $fullReference === $reportedReference;
}

function find_reference_reuse_conflict(mysqli $mysqli, string $reportedReference, int $requiredDigits, int $orderId): ?array {
    $mysqli = ensure_mysqli_connection($mysqli);

    if ($reportedReference === '') {
        return null;
    }

    if ($requiredDigits > 0) {
        $stmt = $mysqli->prepare(
            "SELECT id, numero_referencia, estado
             FROM pedidos
             WHERE id <> ?
               AND numero_referencia IS NOT NULL
               AND TRIM(numero_referencia) <> ''
               AND estado IN ('enviado', 'cancelado')
               AND RIGHT(TRIM(numero_referencia), ?) = ?
             ORDER BY id DESC
             LIMIT 1"
        );
        if ($stmt) {
            $stmt->bind_param('iis', $orderId, $requiredDigits, $reportedReference);
        }
    } else {
        $stmt = $mysqli->prepare(
            "SELECT id, numero_referencia, estado
             FROM pedidos
             WHERE id <> ?
               AND numero_referencia = ?
               AND estado IN ('enviado', 'cancelado')
             ORDER BY id DESC
             LIMIT 1"
        );
        if ($stmt) {
            $stmt->bind_param('is', $orderId, $reportedReference);
        }
    }

    if ($stmt) {
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();
        if ($row) {
            return [
                'type' => 'closed_order_reference',
                'order_id' => (int) ($row['id'] ?? 0),
                'status' => trim((string) ($row['estado'] ?? '')),
                'reference' => trim((string) ($row['numero_referencia'] ?? '')),
            ];
        }
    }

    if ($requiredDigits > 0) {
        $stmt = $mysqli->prepare(
            "SELECT m.id, m.referencia, COALESCE(m.checked, 0) AS checked, COALESCE(m.pedido_id, 0) AS pedido_id, COALESCE(p.estado, '') AS pedido_estado
             FROM movimientos m
             LEFT JOIN pedidos p ON p.id = m.pedido_id
             WHERE RIGHT(TRIM(m.referencia), ?) = ?
             ORDER BY m.id DESC"
        );
        if ($stmt) {
            $stmt->bind_param('is', $requiredDigits, $reportedReference);
        }
    } else {
        $stmt = $mysqli->prepare(
            "SELECT m.id, m.referencia, COALESCE(m.checked, 0) AS checked, COALESCE(m.pedido_id, 0) AS pedido_id, COALESCE(p.estado, '') AS pedido_estado
             FROM movimientos m
             LEFT JOIN pedidos p ON p.id = m.pedido_id
             WHERE m.referencia = ?
             ORDER BY m.id DESC"
        );
        if ($stmt) {
            $stmt->bind_param('s', $reportedReference);
        }
    }

    if (!$stmt) {
        return null;
    }

    $stmt->execute();
    $result = $stmt->get_result();
    while ($result && ($row = $result->fetch_assoc())) {
        $linkedOrderId = (int) ($row['pedido_id'] ?? 0);
        $isChecked = (int) ($row['checked'] ?? 0) === 1;
        $linkedStatus = trim((string) ($row['pedido_estado'] ?? ''));

        if ($linkedOrderId === $orderId) {
            continue;
        }

        if ($isChecked) {
            $stmt->close();
            return [
                'type' => 'verified_movement',
                'movement_id' => (int) ($row['id'] ?? 0),
                'reference' => trim((string) ($row['referencia'] ?? '')),
                'order_id' => $linkedOrderId,
                'status' => $linkedStatus,
            ];
        }

        if ($linkedOrderId > 0 && in_array($linkedStatus, ['enviado', 'cancelado'], true)) {
            $stmt->close();
            return [
                'type' => 'closed_order_movement',
                'movement_id' => (int) ($row['id'] ?? 0),
                'reference' => trim((string) ($row['referencia'] ?? '')),
                'order_id' => $linkedOrderId,
                'status' => $linkedStatus,
            ];
        }
    }
    $stmt->close();

    return null;
}

function reference_reuse_conflict_message(array $conflict): string {
    $orderId = (int) ($conflict['order_id'] ?? 0);
    $status = trim((string) ($conflict['status'] ?? ''));

    if (($conflict['type'] ?? '') === 'verified_movement') {
        if ($orderId > 0) {
            return 'La referencia ingresada ya fue verificada anteriormente y está asociada al pedido #' . $orderId . '. No puede reutilizarse para otra recarga.';
        }

        return 'La referencia ingresada ya fue verificada anteriormente en Movimientos para una recarga manual y no puede reutilizarse en la web.';
    }

    if ($orderId > 0 && $status !== '') {
        return 'La referencia ingresada ya está asociada al pedido #' . $orderId . ' en estado ' . $status . ' y no puede reutilizarse.';
    }

    return 'La referencia ingresada ya fue usada en otra recarga y no puede reutilizarse.';
}

function movement_is_available_for_order(mysqli $mysqli, string $reference, int $orderId): bool {
    $mysqli = ensure_mysqli_connection($mysqli);

    $stmt = $mysqli->prepare('SELECT pedido_id, COALESCE(checked, 0) AS checked FROM movimientos WHERE referencia = ? LIMIT 1');
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('s', $reference);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    if (!$row) {
        return false;
    }

    $linkedOrderId = isset($row['pedido_id']) ? (int) $row['pedido_id'] : 0;
    $isChecked = (int) ($row['checked'] ?? 0) === 1;

    if ($linkedOrderId !== 0 && $linkedOrderId !== $orderId) {
        return false;
    }

    if ($isChecked && $linkedOrderId !== $orderId) {
        return false;
    }

    return true;
}

function bank_amount_matches_order_total(float $movementAmount, float $orderAmount): bool {
    return (int) floor($movementAmount) === (int) floor($orderAmount);
}

function bank_mismatch_failure_type(bool $referenceMatch, bool $amountMatch): string {
    if (!$referenceMatch && $amountMatch) {
        return 'reference_mismatch';
    }
    if ($referenceMatch && !$amountMatch) {
        return 'amount_mismatch';
    }
    if ($referenceMatch && $amountMatch) {
        return 'server_partial_response';
    }
    return 'server_or_data_mismatch';
}

function bank_movement_business_day(?string $movementDate): ?string {
    $parsedDate = parse_bank_movement_datetime($movementDate);
    if ($parsedDate === null) {
        return null;
    }

    return substr($parsedDate, 0, 10);
}

function resolve_bank_movement_business_day(array $movement): ?string {
    $movementDay = bank_movement_business_day((string) ($movement['fecha_movimiento'] ?? ''));
    if ($movementDay !== null) {
        return $movementDay;
    }

    return bank_movement_business_day((string) ($movement['fecha_raw'] ?? ''));
}

function bank_movement_is_valid_for_today(array $movement): bool {
    $movementDay = resolve_bank_movement_business_day($movement);
    if ($movementDay === null) {
        return false;
    }

    return $movementDay === date('Y-m-d');
}

function format_bank_movement_business_day(?string $movementDay): string {
    $raw = trim((string) $movementDay);
    if ($raw === '') {
        return '';
    }

    $date = DateTime::createFromFormat('Y-m-d', $raw);
    return $date ? $date->format('d/m/Y') : $raw;
}

function find_expired_bank_movement_by_reference(array $movements, string $reportedReference, int $requiredDigits): ?array {
    foreach ($movements as $movement) {
        $reference = (string) ($movement['referencia'] ?? '');
        if ($reference === '') {
            continue;
        }
        if (!movement_reference_matches($reference, $reportedReference, $requiredDigits)) {
            continue;
        }

        $movementDay = resolve_bank_movement_business_day($movement);
        if ($movementDay === null || $movementDay === date('Y-m-d')) {
            continue;
        }

        return [
            'movement' => $movement,
            'movement_day' => $movementDay,
        ];
    }

    return null;
}

function find_matching_bank_movement(mysqli $mysqli, array $movements, string $reportedReference, float $orderAmount, int $requiredDigits, int $orderId): ?array {
    foreach ($movements as $movement) {
        $reference = (string) ($movement['referencia'] ?? '');
        if ($reference === '') {
            continue;
        }
        if (!movement_reference_matches($reference, $reportedReference, $requiredDigits)) {
            continue;
        }
        if (!bank_movement_is_valid_for_today($movement)) {
            continue;
        }
        if (!bank_amount_matches_order_total((float) ($movement['monto'] ?? 0), $orderAmount)) {
            continue;
        }
        if (!movement_is_available_for_order($mysqli, $reference, $orderId)) {
            continue;
        }
        return $movement;
    }

    return null;
}

function find_bank_movement_by_reference(mysqli $mysqli, array $movements, string $reportedReference, int $requiredDigits, int $orderId): ?array {
    foreach ($movements as $movement) {
        $reference = (string) ($movement['referencia'] ?? '');
        if ($reference === '') {
            continue;
        }
        if (!movement_reference_matches($reference, $reportedReference, $requiredDigits)) {
            continue;
        }
        if (!bank_movement_is_valid_for_today($movement)) {
            continue;
        }
        if (!movement_is_available_for_order($mysqli, $reference, $orderId)) {
            continue;
        }
        return $movement;
    }

    return null;
}

function find_matching_bank_movement_with_retry(
    mysqli $mysqli,
    array $bankConfig,
    string $reportedReference,
    float $orderAmount,
    int $requiredDigits,
    int $orderId,
    int $attempts = 2,
    int $delaySeconds = 8,
    ?array $initialMovements = null
): array {
    $attempts = max(1, $attempts);
    $delaySeconds = max(0, $delaySeconds);
    $latestMovements = is_array($initialMovements) ? $initialMovements : [];
    $match = null;

    for ($attempt = 1; $attempt <= $attempts; $attempt++) {
        if ($attempt > 1 || empty($latestMovements)) {
            $latestMovements = fetch_and_sync_bank_movements($mysqli, $bankConfig);
        }

        $match = find_matching_bank_movement(
            $mysqli,
            $latestMovements,
            $reportedReference,
            $orderAmount,
            $requiredDigits,
            $orderId
        );

        if ($match !== null) {
            return [
                'match' => $match,
                'movements' => $latestMovements,
                'attempts' => $attempt,
            ];
        }

        if ($attempt < $attempts && $delaySeconds > 0) {
            sleep($delaySeconds);
        }
    }

    return [
        'match' => null,
        'movements' => $latestMovements,
        'attempts' => $attempts,
    ];
}

function find_matching_binance_pagonorte_movement_with_retry(
    mysqli $mysqli,
    array $config,
    string $reportedReference,
    float $orderAmount,
    int $requiredDigits,
    int $orderId,
    int $attempts = 2,
    int $delaySeconds = 8,
    ?array $initialMovements = null
): array {
    $attempts = max(1, $attempts);
    $delaySeconds = max(0, $delaySeconds);
    $latestMovements = is_array($initialMovements) ? $initialMovements : [];
    $match = null;

    for ($attempt = 1; $attempt <= $attempts; $attempt++) {
        if ($attempt > 1 || empty($latestMovements)) {
            $latestMovements = fetch_and_sync_binance_pagonorte_movements($mysqli, $config);
        }

        $match = find_matching_bank_movement(
            $mysqli,
            $latestMovements,
            $reportedReference,
            $orderAmount,
            $requiredDigits,
            $orderId
        );

        if ($match !== null) {
            return [
                'match' => $match,
                'movements' => $latestMovements,
                'attempts' => $attempt,
            ];
        }

        if ($attempt < $attempts && $delaySeconds > 0) {
            sleep($delaySeconds);
        }
    }

    return [
        'match' => null,
        'movements' => $latestMovements,
        'attempts' => $attempts,
    ];
}

function find_bank_movement_by_reference_with_retry(
    mysqli $mysqli,
    array $bankConfig,
    string $reportedReference,
    int $requiredDigits,
    int $orderId,
    int $attempts = 2,
    int $delaySeconds = 8,
    ?array $initialMovements = null
): array {
    $attempts = max(1, $attempts);
    $delaySeconds = max(0, $delaySeconds);
    $latestMovements = is_array($initialMovements) ? $initialMovements : [];
    $match = null;

    for ($attempt = 1; $attempt <= $attempts; $attempt++) {
        if ($attempt > 1 || empty($latestMovements)) {
            $latestMovements = fetch_and_sync_bank_movements($mysqli, $bankConfig);
        }

        $match = find_bank_movement_by_reference(
            $mysqli,
            $latestMovements,
            $reportedReference,
            $requiredDigits,
            $orderId
        );

        if ($match !== null) {
            return [
                'match' => $match,
                'movements' => $latestMovements,
                'attempts' => $attempt,
            ];
        }

        if ($attempt < $attempts && $delaySeconds > 0) {
            sleep($delaySeconds);
        }
    }

    return [
        'match' => null,
        'movements' => $latestMovements,
        'attempts' => $attempts,
    ];
}

function explain_bank_movement_mismatch(array $movements, string $reportedReference, float $orderAmount, int $requiredDigits): array {
    $referenceMatch = false;
    $amountMatch = false;

    foreach ($movements as $movement) {
        $reference = (string) ($movement['referencia'] ?? '');
        $amount = (float) ($movement['monto'] ?? 0);
        if ($reference !== '' && movement_reference_matches($reference, $reportedReference, $requiredDigits)) {
            $referenceMatch = true;
        }
        if (bank_amount_matches_order_total($amount, $orderAmount)) {
            $amountMatch = true;
        }
    }

    $reasons = [];
    if (!$referenceMatch) {
        $reasons[] = 'La referencia ingresada no coincide con ningún movimiento encontrado en la API bancaria.';
    }
    if (!$amountMatch) {
        $reasons[] = 'El monto del pago no coincide con el total esperado del pedido.';
    }
    if ($referenceMatch && $amountMatch) {
        $reasons[] = 'Existe coincidencia parcial en referencia y monto, pero no en un mismo movimiento bancario.';
    }

    return [
        'reference_match' => $referenceMatch,
        'amount_match' => $amountMatch,
        'failure_type' => bank_mismatch_failure_type($referenceMatch, $amountMatch),
        'reasons' => $reasons,
    ];
}

function link_movement_to_order(mysqli $mysqli, string $reference, int $orderId): void {
    $mysqli = ensure_mysqli_connection($mysqli);

    $stmt = $mysqli->prepare('UPDATE movimientos SET pedido_id = ? WHERE referencia = ? AND (pedido_id IS NULL OR pedido_id = 0 OR pedido_id = ?)');
    if (!$stmt) {
        throw new RuntimeException('No se pudo asociar el movimiento al pedido.');
    }
    $stmt->bind_param('isi', $orderId, $reference, $orderId);
    $stmt->execute();
    $stmt->close();
}

function sum_linked_movement_amount_for_order(mysqli $mysqli, int $orderId): float {
    $mysqli = ensure_mysqli_connection($mysqli);

    $stmt = $mysqli->prepare('SELECT COALESCE(SUM(monto), 0) AS total FROM movimientos WHERE pedido_id = ?');
    if (!$stmt) {
        return 0.0;
    }

    $stmt->bind_param('i', $orderId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    return payment_difference_normalize_amount($row['total'] ?? 0);
}

function update_order_payment_deadline(mysqli $mysqli, int $orderId, int $seconds = 1800): void {
    if ($orderId <= 0) {
        return;
    }

    $deadline = date('Y-m-d H:i:s', time() + max(60, $seconds));
    $stmt = $mysqli->prepare('UPDATE pedidos SET pago_expira_en = ? WHERE id = ? LIMIT 1');
    if (!$stmt) {
        return;
    }

    $stmt->bind_param('si', $deadline, $orderId);
    $stmt->execute();
    $stmt->close();
}

function payment_difference_response_payload(array $order, float $overpaymentAmount): ?array {
    $overpaymentAmount = payment_difference_normalize_amount($overpaymentAmount);
    if (!payment_difference_feature_enabled() || $overpaymentAmount <= 0) {
        return null;
    }

    $creditApplied = payment_difference_normalize_amount($order['diferencia_pago_credito_aplicado'] ?? 0);
    $canActivateCredit = $creditApplied <= 0 && (int) ($order['diferencia_pago_credito_activado'] ?? 0) === 0;

    return [
        'status' => 'overpaid',
        'overpayment_amount' => $overpaymentAmount,
        'currency' => strtoupper(trim((string) ($order['moneda'] ?? 'VES'))) !== '' ? strtoupper(trim((string) ($order['moneda'] ?? 'VES'))) : 'VES',
        'source_order_id' => (int) ($order['id'] ?? 0),
        'can_activate_credit' => $canActivateCredit,
        'credit_already_used' => !$canActivateCredit,
    ];
}

function append_payment_difference_response(array $payload, array $order, float $overpaymentAmount): array {
    $differencePayload = payment_difference_response_payload($order, $overpaymentAmount);
    if ($differencePayload === null) {
        return $payload;
    }

    $payload['payment_difference'] = $differencePayload;
    return $payload;
}

function cancel_expired_order(mysqli $mysqli, array $order): array {
    $orderId = (int) ($order['id'] ?? 0);
    if ($orderId <= 0) {
        return ['changed' => false, 'message' => 'Pedido inválido.'];
    }
    if (($order['estado'] ?? '') !== 'pendiente') {
        return ['changed' => false, 'message' => 'El pedido ya no está pendiente.'];
    }
    if (!order_is_expired($order)) {
        return ['changed' => false, 'message' => 'El pedido aún no ha expirado.'];
    }

    $stmt = $mysqli->prepare("UPDATE pedidos SET estado = 'cancelado' WHERE id = ? AND estado = 'pendiente'");
    if (!$stmt) {
        return ['changed' => false, 'message' => 'No se pudo actualizar el pedido.'];
    }
    $stmt->bind_param('i', $orderId);
    $stmt->execute();
    $changed = $stmt->affected_rows > 0;
    $stmt->close();

    if (!$changed) {
        return ['changed' => false, 'message' => 'El pedido ya fue actualizado.'];
    }

    $adminEmail = resolve_admin_email($mysqli);
    $customerMessage = '<p style="margin:0 0 10px;">La orden superó el tiempo límite de 30 minutos sin confirmación de pago.</p>'
        . '<p style="margin:0;">El pedido fue cancelado automáticamente y deberás generar uno nuevo si deseas continuar con la compra.</p>';
    $adminMessage = '<p style="margin:0 0 10px;">Una orden no verificada superó el tiempo límite de 30 minutos sin confirmación de pago.</p>'
        . '<p style="margin:0;">El pedido fue cancelado automáticamente por vencimiento.</p>';
    $customerHtml = render_order_email('Orden vencida', 'Cliente', $customerMessage, [
        'order_id' => $orderId,
        'game_name' => $order['juego_nombre'] ?? '',
        'pack_name' => $order['paquete_nombre'] ?? '',
        'pack_amount' => $order['paquete_cantidad'] ?? '',
        'currency' => $order['moneda'] ?? '',
        'price' => number_format((float) ($order['precio'] ?? 0), 2, '.', ','),
        'user_identifier' => $order['user_identifier'] ?? '',
        'email' => $order['email'] ?? '',
        'coupon' => $order['cupon'] ?? null,
        'status' => 'Cancelado por tiempo',
    ], '#f87171');
    $adminHtml = render_order_email('Orden vencida', 'Administrador', $adminMessage, [
        'order_id' => $orderId,
        'game_name' => $order['juego_nombre'] ?? '',
        'pack_name' => $order['paquete_nombre'] ?? '',
        'pack_amount' => $order['paquete_cantidad'] ?? '',
        'currency' => $order['moneda'] ?? '',
        'price' => number_format((float) ($order['precio'] ?? 0), 2, '.', ','),
        'user_identifier' => $order['user_identifier'] ?? '',
        'email' => $order['email'] ?? '',
        'coupon' => $order['cupon'] ?? null,
        'status' => 'Cancelado por tiempo',
    ], '#f87171');

    $brandingImages = email_branding_embedded_images();
    if (!empty($order['email']) && filter_var($order['email'], FILTER_VALIDATE_EMAIL)) {
            send_app_mail((string) $order['email'], "Orden vencida #{$orderId}", $customerHtml, null, $brandingImages);
    }
    if ($adminEmail !== null) {
            send_app_mail($adminEmail, "Orden vencida #{$orderId}", $adminHtml, null, $brandingImages);
    }

    win_points_handle_order_status_change($mysqli, $orderId, 'cancelado');

    return ['changed' => true, 'message' => 'La orden expiró y fue cancelada automáticamente.'];
}

function cancel_pending_order_by_customer(mysqli $mysqli, array $order): array {
    $orderId = (int) ($order['id'] ?? 0);
    if ($orderId <= 0) {
        return ['changed' => false, 'message' => 'Pedido inválido.'];
    }
    if (($order['estado'] ?? '') !== 'pendiente') {
        return ['changed' => false, 'message' => 'La orden ya no se puede cancelar.'];
    }

    $stmt = $mysqli->prepare("UPDATE pedidos SET estado = 'cancelado' WHERE id = ? AND estado = 'pendiente'");
    if (!$stmt) {
        return ['changed' => false, 'message' => 'No se pudo cancelar la orden.'];
    }
    $stmt->bind_param('i', $orderId);
    $stmt->execute();
    $changed = $stmt->affected_rows > 0;
    $stmt->close();

    if (!$changed) {
        return ['changed' => false, 'message' => 'La orden ya fue actualizada previamente.'];
    }

    $adminEmail = resolve_admin_email($mysqli);
    $brandingImages = email_branding_embedded_images();

    $customerHtml = render_order_email('Orden cancelada', 'Cliente',
        '<p style="margin:0 0 10px;">Cancelaste la orden desde la ventana de pago.</p><p style="margin:0;">Si deseas continuar, deberás generar una nueva orden.</p>', [
            'order_id' => $orderId,
            'game_name' => $order['juego_nombre'] ?? '',
            'pack_name' => $order['paquete_nombre'] ?? '',
            'pack_amount' => $order['paquete_cantidad'] ?? '',
            'currency' => $order['moneda'] ?? '',
            'price' => number_format((float) ($order['precio'] ?? 0), 2, '.', ','),
            'user_identifier' => $order['user_identifier'] ?? '',
            'email' => $order['email'] ?? '',
            'coupon' => $order['cupon'] ?? null,
            'status' => 'Cancelado',
        ], '#f87171');
    $adminHtml = render_order_email('Orden cancelada por cliente', 'Administrador',
        '<p style="margin:0 0 10px;">El cliente canceló la orden desde la ventana de pago.</p><p style="margin:0;">No se requiere validación adicional para este pedido.</p>', [
            'order_id' => $orderId,
            'game_name' => $order['juego_nombre'] ?? '',
            'pack_name' => $order['paquete_nombre'] ?? '',
            'pack_amount' => $order['paquete_cantidad'] ?? '',
            'currency' => $order['moneda'] ?? '',
            'price' => number_format((float) ($order['precio'] ?? 0), 2, '.', ','),
            'user_identifier' => $order['user_identifier'] ?? '',
            'email' => $order['email'] ?? '',
            'coupon' => $order['cupon'] ?? null,
            'status' => 'Cancelado',
        ], '#f87171');

    if (!empty($order['email']) && filter_var($order['email'], FILTER_VALIDATE_EMAIL)) {
        send_app_mail((string) $order['email'], "Orden cancelada #{$orderId}", $customerHtml, null, $brandingImages);
    }
    if ($adminEmail !== null) {
        send_app_mail($adminEmail, "Orden cancelada por cliente #{$orderId}", $adminHtml, null, $brandingImages);
    }

    win_points_handle_order_status_change($mysqli, $orderId, 'cancelado');

    return ['changed' => true, 'message' => 'La orden fue cancelada correctamente.'];
}

function notify_payment_validation_failed_cancellation(
    mysqli $mysqli,
    array $order,
    string $paymentMethodName,
    string $referenceNumber,
    string $phone
): void {
    $orderId = (int) ($order['id'] ?? 0);
    if ($orderId <= 0) {
        return;
    }

    $adminEmail = resolve_admin_email($mysqli);
    $brandingImages = email_branding_embedded_images();
    $customerMessage = '<p style="margin:0 0 10px;">No pudimos validar automáticamente tu pago con la referencia y el monto enviados.</p>'
        . '<p style="margin:0;">La orden fue cancelada. Debes generar una nueva orden si deseas volver a intentarlo.</p>';
    $adminMessage = '<p style="margin:0 0 10px;">La verificación automática del pago no encontró coincidencia entre monto y referencia.</p>'
        . '<p style="margin:0;">El pedido fue cancelado automáticamente para evitar una validación errónea.</p>';

    $customerHtml = render_order_email('Pago no verificado', 'Cliente', $customerMessage, [
        'order_id' => $orderId,
        'game_name' => $order['juego_nombre'] ?? '',
        'pack_name' => $order['paquete_nombre'] ?? '',
        'pack_amount' => $order['paquete_cantidad'] ?? '',
        'currency' => $order['moneda'] ?? '',
        'price' => number_format((float) ($order['precio'] ?? 0), 2, '.', ','),
        'user_identifier' => $order['user_identifier'] ?? '',
        'email' => $order['email'] ?? '',
        'coupon' => $order['cupon'] ?? null,
        'payment_method' => $paymentMethodName,
        'reference_number' => $referenceNumber,
        'phone' => $phone,
        'status' => 'Cancelado',
    ], '#f87171');
    $adminHtml = render_order_email('Pago no verificado', 'Administrador', $adminMessage, [
        'order_id' => $orderId,
        'game_name' => $order['juego_nombre'] ?? '',
        'pack_name' => $order['paquete_nombre'] ?? '',
        'pack_amount' => $order['paquete_cantidad'] ?? '',
        'currency' => $order['moneda'] ?? '',
        'price' => number_format((float) ($order['precio'] ?? 0), 2, '.', ','),
        'user_identifier' => $order['user_identifier'] ?? '',
        'email' => $order['email'] ?? '',
        'coupon' => $order['cupon'] ?? null,
        'payment_method' => $paymentMethodName,
        'reference_number' => $referenceNumber,
        'phone' => $phone,
        'status' => 'Cancelado',
    ], '#f87171');

    $customerEmail = trim((string) ($order['email'] ?? ''));
    if ($customerEmail !== '' && filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
        send_app_mail($customerEmail, "Pago no verificado #{$orderId}", $customerHtml, null, $brandingImages);
    } else {
        error_log("TVG payment validation cancel mail skipped for order #{$orderId}: invalid customer email");
    }

    if ($adminEmail !== null) {
        send_app_mail($adminEmail, "Pedido cancelado por validación #{$orderId}", $adminHtml, null, $brandingImages);
    } else {
        error_log("TVG payment validation cancel mail skipped for order #{$orderId}: admin email not configured");
    }
}

function notify_free_fire_recharge_success(
    mysqli $mysqli,
    array $order,
    string $paymentMethodName,
    string $referenceNumber,
    string $phone,
    string $providerReference,
    string $providerMessage
): void {
    $orderId = (int) ($order['id'] ?? 0);
    if ($orderId <= 0) {
        return;
    }

    $adminEmail = resolve_admin_email($mysqli);
    $brandingImages = email_branding_embedded_images();
    $providerReferenceText = $providerReference !== '' ? '<p style="margin:0 0 10px;">Referencia del proveedor: <strong>' . email_escape($providerReference) . '</strong></p>' : '';
    $providerMessageText = '<p style="margin:0;">Respuesta del proveedor: <strong>' . email_escape($providerMessage) . '</strong></p>';
    $gameName = trim((string) ($order['juego_nombre'] ?? 'tu juego')) ?: 'tu juego';

    $customerHtml = render_order_email('Pago verificado y recarga enviada', 'Cliente',
        '<p style="margin:0 0 10px;">Tu pago fue validado automáticamente y la recarga de ' . email_escape($gameName) . ' fue procesada con éxito.</p>'
        . $providerReferenceText
        . $providerMessageText,
        [
            'order_id' => $orderId,
            'game_name' => $order['juego_nombre'] ?? '',
            'pack_name' => $order['paquete_nombre'] ?? '',
            'pack_amount' => $order['paquete_cantidad'] ?? '',
            'currency' => $order['moneda'] ?? '',
            'price' => number_format((float) ($order['precio'] ?? 0), 2, '.', ','),
            'user_identifier' => $order['user_identifier'] ?? '',
            'email' => $order['email'] ?? '',
            'coupon' => $order['cupon'] ?? null,
            'payment_method' => $paymentMethodName,
            'reference_number' => $referenceNumber,
            'phone' => $phone,
            'status' => 'Enviado',
        ],
        '#34d399'
    );
    $adminHtml = render_order_email('Recarga automatica enviada', 'Administrador',
        '<p style="margin:0 0 10px;">El pago fue validado automáticamente y la API de recargas respondió exitosamente para ' . email_escape($gameName) . '.</p>'
        . $providerReferenceText
        . $providerMessageText,
        [
            'order_id' => $orderId,
            'game_name' => $order['juego_nombre'] ?? '',
            'pack_name' => $order['paquete_nombre'] ?? '',
            'pack_amount' => $order['paquete_cantidad'] ?? '',
            'currency' => $order['moneda'] ?? '',
            'price' => number_format((float) ($order['precio'] ?? 0), 2, '.', ','),
            'user_identifier' => $order['user_identifier'] ?? '',
            'email' => $order['email'] ?? '',
            'coupon' => $order['cupon'] ?? null,
            'payment_method' => $paymentMethodName,
            'reference_number' => $referenceNumber,
            'phone' => $phone,
            'status' => 'Enviado',
        ],
        '#34d399'
    );

    if (!empty($order['email']) && filter_var($order['email'], FILTER_VALIDATE_EMAIL)) {
        send_app_mail((string) $order['email'], "Recarga enviada #{$orderId}", $customerHtml, null, $brandingImages);
    }
    if ($adminEmail !== null) {
        send_app_mail($adminEmail, "Recarga automatica enviada #{$orderId}", $adminHtml, null, $brandingImages);
    }
}

function render_account_sale_gallery_email_html(array $gallery): string {
    if (empty($gallery)) {
        return '';
    }

    $baseUrl = current_public_app_base_url();
    $cards = [];
    foreach ($gallery as $item) {
        $imagePath = trim((string) ($item['image_path'] ?? ''));
        if ($imagePath === '') {
            continue;
        }

        $publicUrl = $imagePath;
        if ($baseUrl !== null && !preg_match('#^https?://#i', $imagePath)) {
            $publicUrl = rtrim($baseUrl, '/') . '/' . ltrim($imagePath, '/');
        }

        $description = package_account_sales_normalize_caption((string) ($item['description'] ?? ''));
        $cards[] = '<div style="margin:0 0 16px;padding:14px;border-radius:18px;border:1px solid #1e3a5f;background:#081018;">'
            . '<img src="' . email_escape($publicUrl) . '" alt="Imagen de la cuenta" style="display:block;width:100%;max-width:420px;border-radius:14px;border:1px solid #164e63;background:#020617;">'
            . ($description !== '' ? '<p style="margin:12px 0 0;color:#cbd5e1;font-size:14px;">' . email_escape($description) . '</p>' : '')
            . '</div>';
    }

    if (empty($cards)) {
        return '';
    }

    return '<div style="margin:16px 0 0;"><p style="margin:0 0 10px;color:#e2e8f0;font-weight:700;">Galería incluida en la cuenta:</p>' . implode('', $cards) . '</div>';
}

function notify_account_sale_delivery(mysqli $mysqli, array $order, string $paymentMethodName, string $referenceNumber, string $phone, bool $resent = false): void {
    $orderId = (int) ($order['id'] ?? 0);
    if ($orderId <= 0) {
        return;
    }

    $adminEmail = resolve_admin_email($mysqli);
    $brandingImages = email_branding_embedded_images();
    $accountText = nl2br(email_escape(package_account_sales_normalize_text((string) ($order['cuenta_entrega_texto'] ?? ''))));
    $galleryHtml = render_account_sale_gallery_email_html(order_account_sale_gallery($order));
    $customerTitle = $resent ? 'Cuenta reenviada' : 'Cuenta entregada';
    $adminTitle = $resent ? 'Cuenta reenviada manualmente' : 'Cuenta entregada automáticamente';

    $customerHtml = render_order_email($customerTitle, 'Cliente',
        '<p style="margin:0 0 10px;">Tu pago fue verificado y la cuenta comprada ya está disponible.</p>'
        . '<div style="margin:0 0 12px;padding:16px;border-radius:18px;border:1px solid #164e63;background:#081018;color:#e2e8f0;line-height:1.6;">' . $accountText . '</div>'
        . $galleryHtml,
        [
            'order_id' => $orderId,
            'game_name' => $order['juego_nombre'] ?? '',
            'pack_name' => $order['paquete_nombre'] ?? '',
            'pack_amount' => $order['paquete_cantidad'] ?? '',
            'currency' => $order['moneda'] ?? '',
            'price' => number_format((float) ($order['precio'] ?? 0), 2, '.', ','),
            'email' => $order['email'] ?? '',
            'coupon' => $order['cupon'] ?? null,
            'payment_method' => $paymentMethodName,
            'reference_number' => $referenceNumber,
            'phone' => $phone,
            'status' => 'Enviado',
        ],
        '#34d399'
    );
    $adminHtml = render_order_email($adminTitle, 'Administrador',
        '<p style="margin:0 0 10px;">La orden de venta de cuenta quedó entregada al cliente.</p>'
        . '<div style="margin:0 0 12px;padding:16px;border-radius:18px;border:1px solid #164e63;background:#081018;color:#e2e8f0;line-height:1.6;">' . $accountText . '</div>'
        . $galleryHtml,
        [
            'order_id' => $orderId,
            'game_name' => $order['juego_nombre'] ?? '',
            'pack_name' => $order['paquete_nombre'] ?? '',
            'pack_amount' => $order['paquete_cantidad'] ?? '',
            'currency' => $order['moneda'] ?? '',
            'price' => number_format((float) ($order['precio'] ?? 0), 2, '.', ','),
            'email' => $order['email'] ?? '',
            'coupon' => $order['cupon'] ?? null,
            'payment_method' => $paymentMethodName,
            'reference_number' => $referenceNumber,
            'phone' => $phone,
            'status' => 'Enviado',
        ],
        '#34d399'
    );

    if (!empty($order['email']) && filter_var($order['email'], FILTER_VALIDATE_EMAIL)) {
        send_app_mail((string) $order['email'], ($resent ? 'Cuenta reenviada' : 'Cuenta entregada') . " #{$orderId}", $customerHtml, null, $brandingImages);
    }
    if ($adminEmail !== null) {
        send_app_mail($adminEmail, ($resent ? 'Cuenta reenviada' : 'Cuenta entregada') . " #{$orderId}", $adminHtml, null, $brandingImages);
    }
}

function mark_account_sale_as_sent(mysqli $mysqli, array $order, string $expectedStatus, string $referenceNumber, string $phone): array {
    $orderId = (int) ($order['id'] ?? 0);
    if ($orderId <= 0) {
        throw new RuntimeException('Pedido inválido para la entrega de cuenta.');
    }

    $providerState = 'account_sale';
    $sentStatus = 'enviado';
    $providerMessage = $expectedStatus === 'pagado'
        ? 'Venta de cuenta reenviada manualmente al cliente.'
        : 'Venta de cuenta entregada correctamente al cliente.';
    $historyJson = append_provider_history(
        $order['recargas_api_historial_json'] ?? null,
        build_provider_history_entry(
            $expectedStatus === 'pagado' ? 'account_sale_resend' : 'account_sale_delivery',
            $providerState,
            $sentStatus,
            $providerMessage,
            '',
            '',
            ''
        )
    );

    $stmt = $mysqli->prepare("UPDATE pedidos SET numero_referencia = ?, telefono_contacto = ?, ff_api_mensaje = ?, recargas_api_estado = ?, recargas_api_ultimo_check = NOW(), recargas_api_historial_json = ?, estado = ? WHERE id = ? AND estado = ?");
    if (!$stmt) {
        throw new RuntimeException('No se pudo preparar la entrega de la cuenta.');
    }

    $stmt->bind_param('ssssssis', $referenceNumber, $phone, $providerMessage, $providerState, $historyJson, $sentStatus, $orderId, $expectedStatus);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('No se pudo actualizar la entrega de la cuenta.');
    }
    $stmt->close();

    $packageId = (int) ($order['paquete_id'] ?? 0);
    if ((int) ($order['vender_cuenta'] ?? 0) === 1 && $packageId > 0) {
        $deactivateStmt = $mysqli->prepare("UPDATE juego_paquetes SET activo = 0 WHERE id = ?");
        if ($deactivateStmt) {
            $deactivateStmt->bind_param('i', $packageId);
            $deactivateStmt->execute();
            $deactivateStmt->close();
        }
    }

    $updatedOrder = fetch_order_by_id($mysqli, $orderId);
    if ($updatedOrder === null) {
        throw new RuntimeException('No se pudo recuperar la orden entregada.');
    }

    return $updatedOrder;
}

function notify_free_fire_recharge_failure(
    mysqli $mysqli,
    array $order,
    string $paymentMethodName,
    string $referenceNumber,
    string $phone,
    string $providerMessage
): void {
    $orderId = (int) ($order['id'] ?? 0);
    if ($orderId <= 0) {
        return;
    }

    $adminEmail = resolve_admin_email($mysqli);
    $brandingImages = email_branding_embedded_images();
    $providerMessageText = '<p style="margin:0;">Respuesta del proveedor: <strong>' . email_escape($providerMessage) . '</strong></p>';
    $gameName = trim((string) ($order['juego_nombre'] ?? 'tu juego')) ?: 'tu juego';

    $customerHtml = render_order_email('Pago verificado, recarga en revisión', 'Cliente',
        '<p style="margin:0 0 10px;">Tu pago sí fue validado automáticamente, pero la recarga de ' . email_escape($gameName) . ' no pudo completarse de forma inmediata.</p>'
        . '<p style="margin:0 0 10px;">Nuestro equipo ya fue notificado para revisar el caso manualmente.</p>'
        . $providerMessageText,
        [
            'order_id' => $orderId,
            'game_name' => $order['juego_nombre'] ?? '',
            'pack_name' => $order['paquete_nombre'] ?? '',
            'pack_amount' => $order['paquete_cantidad'] ?? '',
            'currency' => $order['moneda'] ?? '',
            'price' => number_format((float) ($order['precio'] ?? 0), 2, '.', ','),
            'user_identifier' => $order['user_identifier'] ?? '',
            'email' => $order['email'] ?? '',
            'coupon' => $order['cupon'] ?? null,
            'payment_method' => $paymentMethodName,
            'reference_number' => $referenceNumber,
            'phone' => $phone,
            'status' => 'Verificado',
        ],
        '#f59e0b'
    );
    $adminHtml = render_order_email('Pago verificado, recarga automatica fallida', 'Administrador',
        '<p style="margin:0 0 10px;">El pago fue validado automáticamente, pero la API de recargas no completó la recarga de ' . email_escape($gameName) . '.</p>'
        . '<p style="margin:0 0 10px;">El pedido quedó en estado verificado para revisión manual.</p>'
        . $providerMessageText,
        [
            'order_id' => $orderId,
            'game_name' => $order['juego_nombre'] ?? '',
            'pack_name' => $order['paquete_nombre'] ?? '',
            'pack_amount' => $order['paquete_cantidad'] ?? '',
            'currency' => $order['moneda'] ?? '',
            'price' => number_format((float) ($order['precio'] ?? 0), 2, '.', ','),
            'user_identifier' => $order['user_identifier'] ?? '',
            'email' => $order['email'] ?? '',
            'coupon' => $order['cupon'] ?? null,
            'payment_method' => $paymentMethodName,
            'reference_number' => $referenceNumber,
            'phone' => $phone,
            'status' => 'Verificado',
        ],
        '#f59e0b'
    );

    if (!empty($order['email']) && filter_var($order['email'], FILTER_VALIDATE_EMAIL)) {
        send_app_mail((string) $order['email'], "Pago verificado, recarga en revisión #{$orderId}", $customerHtml, null, $brandingImages);
    }
    if ($adminEmail !== null) {
        send_app_mail($adminEmail, "Recarga automatica en revisión #{$orderId}", $adminHtml, null, $brandingImages);
    }
}

function build_order_support_whatsapp_link(array $order): string {
    $whatsapp = trim((string) store_config_get('whatsapp', ''));
    if ($whatsapp === '') {
        return '';
    }

    $message = implode("\n", [
        'Hola, necesito apoyo con una recarga pendiente por falta de disponibilidad.',
        'Pedido: #' . (int) ($order['id'] ?? 0),
        'Juego: ' . trim((string) ($order['juego_nombre'] ?? '-')),
        'Paquete: ' . trim((string) ($order['paquete_nombre'] ?? '-')),
        'ID Jugador: ' . trim((string) ($order['user_identifier'] ?? '-')),
        'Referencia: ' . trim((string) ($order['numero_referencia'] ?? '-')),
        'Correo: ' . trim((string) ($order['email'] ?? '-')),
    ]);

    return store_config_whatsapp_link_with_message($whatsapp, $message);
}

function notify_provider_inventory_shortage(
    mysqli $mysqli,
    array $order,
    string $paymentMethodName,
    string $referenceNumber,
    string $phone,
    string $providerMessage,
    array $lockInfo = []
): void {
    $orderId = (int) ($order['id'] ?? 0);
    if ($orderId <= 0) {
        return;
    }

    $adminEmail = resolve_admin_email($mysqli);
    $brandingImages = email_branding_embedded_images();
    $gameName = trim((string) ($order['juego_nombre'] ?? 'tu juego')) ?: 'tu juego';
    $providerMessageText = '<p style="margin:0 0 10px;">Respuesta del proveedor: <strong>' . email_escape($providerMessage) . '</strong></p>';
    $whatsappLink = build_order_support_whatsapp_link($order);
    $whatsappHtml = $whatsappLink !== ''
        ? '<p style="margin:0 0 10px;"><a href="' . email_escape($whatsappLink) . '" target="_blank" rel="noopener noreferrer" style="display:inline-block;padding:12px 18px;border-radius:999px;background:#25d366;color:#081018;text-decoration:none;font-weight:700;">Hablar por WhatsApp con el administrador</a></p>'
        : '';
    $packagesUpdated = max(0, (int) ($lockInfo['packages_updated'] ?? 0));
    $activePackagesRemaining = max(0, (int) ($lockInfo['active_packages_remaining'] ?? 0));
    $gameDeactivated = !empty($lockInfo['game_deactivated']);
    if ($packagesUpdated > 0) {
        $lockSummary = 'Paquete afectado desactivado automáticamente. Paquetes activos restantes: ' . $activePackagesRemaining . '.';
    } else {
        $lockSummary = 'No se realizaron cambios automáticos en otros paquetes.';
    }
    if ($gameDeactivated) {
        $lockSummary .= ' El juego también se desactivó porque ya no quedaron paquetes activos.';
    }

    $customerHtml = render_order_email('Pago verificado, recarga pendiente por disponibilidad', 'Cliente',
        '<p style="margin:0 0 10px;">Tu pago fue verificado correctamente, pero por los momentos no hay suficientes recargas disponibles para completar la entrega automática de ' . email_escape($gameName) . '.</p>'
        . '<p style="margin:0 0 10px;">Tu pedido quedó en estado <strong style="color:#f59e0b;">Verificado</strong> y enviaremos la recarga en cuanto tengamos disponibilidad.</p>'
        . '<p style="margin:0 0 10px;">Si deseas acelerar la atención, puedes escribirnos por WhatsApp y compartir tu comprobante.</p>'
        . $whatsappHtml
        . $providerMessageText,
        [
            'order_id' => $orderId,
            'game_name' => $order['juego_nombre'] ?? '',
            'pack_name' => $order['paquete_nombre'] ?? '',
            'pack_amount' => $order['paquete_cantidad'] ?? '',
            'currency' => $order['moneda'] ?? '',
            'price' => number_format((float) ($order['precio'] ?? 0), 2, '.', ','),
            'user_identifier' => $order['user_identifier'] ?? '',
            'email' => $order['email'] ?? '',
            'coupon' => $order['cupon'] ?? null,
            'payment_method' => $paymentMethodName,
            'reference_number' => $referenceNumber,
            'phone' => $phone,
            'status' => 'Verificado',
        ],
        '#f59e0b'
    );
    $adminHtml = render_order_email('Pago verificado, disponibilidad insuficiente', 'Administrador',
        '<p style="margin:0 0 10px;">El pago fue verificado, pero el proveedor reportó disponibilidad insuficiente para ' . email_escape($gameName) . '.</p>'
        . '<p style="margin:0 0 10px;">El pedido quedó en estado <strong style="color:#f59e0b;">Verificado</strong> para entrega manual y la disponibilidad del paquete afectado se ajustó automáticamente por prevención.</p>'
        . '<p style="margin:0 0 10px;">' . email_escape($lockSummary) . '</p>'
        . '<p style="margin:0 0 10px;">Reactiva luego el paquete cuando repongas saldo. Si todos los paquetes quedaron inactivos, al reactivar uno el juego volverá a publicarse.</p>'
        . $providerMessageText,
        [
            'order_id' => $orderId,
            'game_name' => $order['juego_nombre'] ?? '',
            'pack_name' => $order['paquete_nombre'] ?? '',
            'pack_amount' => $order['paquete_cantidad'] ?? '',
            'currency' => $order['moneda'] ?? '',
            'price' => number_format((float) ($order['precio'] ?? 0), 2, '.', ','),
            'user_identifier' => $order['user_identifier'] ?? '',
            'email' => $order['email'] ?? '',
            'coupon' => $order['cupon'] ?? null,
            'payment_method' => $paymentMethodName,
            'reference_number' => $referenceNumber,
            'phone' => $phone,
            'status' => 'Verificado',
        ],
        '#f59e0b'
    );

    if (!empty($order['email']) && filter_var($order['email'], FILTER_VALIDATE_EMAIL)) {
        send_app_mail((string) $order['email'], "Pago verificado, recarga pendiente #{$orderId}", $customerHtml, null, $brandingImages);
    }
    if ($adminEmail !== null) {
        send_app_mail($adminEmail, "Disponibilidad insuficiente #{$orderId}", $adminHtml, null, $brandingImages);
    }
}

function mark_order_inventory_shortage_review(
    mysqli $mysqli,
    array $order,
    string $expectedStatus,
    string $referenceNumber,
    string $phone,
    string $providerReference,
    string $providerMessage,
    string $providerPayload,
    string $providerOrderId = '',
    string $providerCode = ''
): array {
    $orderId = (int) ($order['id'] ?? 0);
    if ($orderId <= 0) {
        throw new RuntimeException('Pedido inválido para revisión por disponibilidad.');
    }

    $paidStatus = 'pagado';
    $providerState = 'inventory_shortage';
    $providerHistoryJson = append_provider_history(
        $order['recargas_api_historial_json'] ?? null,
        build_provider_history_entry(
            'inventory_shortage',
            $providerState,
            $paidStatus,
            $providerMessage,
            $providerReference,
            $providerOrderId,
            $providerCode
        )
    );

    $stmt = $mysqli->prepare("UPDATE pedidos SET numero_referencia = ?, telefono_contacto = ?, ff_api_referencia = ?, ff_api_mensaje = ?, ff_api_payload = ?, recargas_api_pedido_id = ?, recargas_api_estado = ?, recargas_api_codigo_entregado = ?, recargas_api_ultimo_check = NOW(), recargas_api_historial_json = ?, estado = ? WHERE id = ? AND estado = ?");
    if (!$stmt) {
        throw new RuntimeException('No se pudo preparar el pedido para revisión por disponibilidad.');
    }

    $stmt->bind_param('ssssssssssis', $referenceNumber, $phone, $providerReference, $providerMessage, $providerPayload, $providerOrderId, $providerState, $providerCode, $providerHistoryJson, $paidStatus, $orderId, $expectedStatus);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('No se pudo actualizar el pedido para revisión por disponibilidad.');
    }
    $stmt->close();

    $updatedOrder = fetch_order_by_id($mysqli, $orderId) ?: $order;
    $lockInfo = recharge_availability_lock_package_for_inventory_shortage(
        $mysqli,
        (int) ($updatedOrder['juego_id'] ?? 0),
        (int) ($updatedOrder['paquete_id'] ?? 0)
    );

    return [
        'order' => $updatedOrder,
        'lock' => $lockInfo,
        'provider_status' => $providerState,
    ];
}

function notify_catalog_purchase_pending(
    mysqli $mysqli,
    array $order,
    string $paymentMethodName,
    string $referenceNumber,
    string $phone,
    string $providerReference,
    string $providerMessage
): void {
    $orderId = (int) ($order['id'] ?? 0);
    if ($orderId <= 0) {
        return;
    }

    $adminEmail = resolve_admin_email($mysqli);
    $brandingImages = email_branding_embedded_images();
    $providerReferenceText = $providerReference !== '' ? '<p style="margin:0 0 10px;">Referencia del proveedor: <strong>' . email_escape($providerReference) . '</strong></p>' : '';
    $providerMessageText = '<p style="margin:0;">Respuesta del proveedor: <strong>' . email_escape($providerMessage) . '</strong></p>';
    $gameName = trim((string) ($order['juego_nombre'] ?? 'tu juego')) ?: 'tu juego';

    $customerHtml = render_order_email('Pago verificado, compra en proceso', 'Cliente',
        '<p style="margin:0 0 10px;">Tu pago fue validado y la orden para ' . email_escape($gameName) . ' fue aceptada por el proveedor.</p>'
        . '<p style="margin:0 0 10px;">La recarga quedó en proceso o revisión, y nuestro equipo hará seguimiento hasta completarla.</p>'
        . $providerReferenceText
        . $providerMessageText,
        [
            'order_id' => $orderId,
            'game_name' => $order['juego_nombre'] ?? '',
            'pack_name' => $order['paquete_nombre'] ?? '',
            'pack_amount' => $order['paquete_cantidad'] ?? '',
            'currency' => $order['moneda'] ?? '',
            'price' => number_format((float) ($order['precio'] ?? 0), 2, '.', ','),
            'user_identifier' => $order['user_identifier'] ?? '',
            'email' => $order['email'] ?? '',
            'coupon' => $order['cupon'] ?? null,
            'payment_method' => $paymentMethodName,
            'reference_number' => $referenceNumber,
            'phone' => $phone,
            'status' => 'Verificado',
        ],
        '#f59e0b'
    );
    $adminHtml = render_order_email('Pago verificado, compra API en proceso', 'Administrador',
        '<p style="margin:0 0 10px;">El pago fue validado y la API aceptó la compra para ' . email_escape($gameName) . '.</p>'
        . '<p style="margin:0 0 10px;">La orden quedó en estado verificado para seguimiento hasta que el proveedor la complete.</p>'
        . $providerReferenceText
        . $providerMessageText,
        [
            'order_id' => $orderId,
            'game_name' => $order['juego_nombre'] ?? '',
            'pack_name' => $order['paquete_nombre'] ?? '',
            'pack_amount' => $order['paquete_cantidad'] ?? '',
            'currency' => $order['moneda'] ?? '',
            'price' => number_format((float) ($order['precio'] ?? 0), 2, '.', ','),
            'user_identifier' => $order['user_identifier'] ?? '',
            'email' => $order['email'] ?? '',
            'coupon' => $order['cupon'] ?? null,
            'payment_method' => $paymentMethodName,
            'reference_number' => $referenceNumber,
            'phone' => $phone,
            'status' => 'Verificado',
        ],
        '#f59e0b'
    );

    if (!empty($order['email']) && filter_var($order['email'], FILTER_VALIDATE_EMAIL)) {
        send_app_mail((string) $order['email'], "Pago verificado, compra en proceso #{$orderId}", $customerHtml, null, $brandingImages);
    }
    if ($adminEmail !== null) {
        send_app_mail($adminEmail, "Compra API en proceso #{$orderId}", $adminHtml, null, $brandingImages);
    }
}

function notify_catalog_purchase_cancelled(
    mysqli $mysqli,
    array $order,
    string $providerReference,
    string $providerMessage,
    ?float $refundAmount = null
): void {
    $orderId = (int) ($order['id'] ?? 0);
    if ($orderId <= 0) {
        return;
    }

    $adminEmail = resolve_admin_email($mysqli);
    $brandingImages = email_branding_embedded_images();
    $gameName = trim((string) ($order['juego_nombre'] ?? 'tu juego')) ?: 'tu juego';
    $providerReferenceText = $providerReference !== '' ? '<p style="margin:0 0 10px;">Referencia del proveedor: <strong>' . email_escape($providerReference) . '</strong></p>' : '';
    $refundText = $refundAmount !== null ? '<p style="margin:0 0 10px;">Reembolso informado por el proveedor: <strong>' . email_escape(number_format($refundAmount, 2, '.', ',')) . '</strong></p>' : '';
    $providerMessageText = '<p style="margin:0;">Detalle del proveedor: <strong>' . email_escape($providerMessage !== '' ? $providerMessage : 'No se recibió detalle adicional.') . '</strong></p>';

    $customerHtml = render_order_email('Compra cancelada por proveedor', 'Cliente',
        '<p style="margin:0 0 10px;">La compra de ' . email_escape($gameName) . ' fue cancelada por el proveedor.</p>'
        . '<p style="margin:0 0 10px;">Tu pedido quedó cancelado y debes revisar el detalle antes de ofrecer una nueva gestión al cliente.</p>'
        . $providerReferenceText
        . $refundText
        . $providerMessageText,
        [
            'order_id' => $orderId,
            'game_name' => $order['juego_nombre'] ?? '',
            'pack_name' => $order['paquete_nombre'] ?? '',
            'pack_amount' => $order['paquete_cantidad'] ?? '',
            'currency' => $order['moneda'] ?? '',
            'price' => number_format((float) ($order['precio'] ?? 0), 2, '.', ','),
            'user_identifier' => $order['user_identifier'] ?? '',
            'email' => $order['email'] ?? '',
            'coupon' => $order['cupon'] ?? null,
            'status' => 'Cancelado',
        ],
        '#ef4444'
    );
    $adminHtml = render_order_email('Compra API cancelada', 'Administrador',
        '<p style="margin:0 0 10px;">El proveedor canceló la compra de ' . email_escape($gameName) . '.</p>'
        . '<p style="margin:0 0 10px;">El pedido local quedó cancelado.</p>'
        . $providerReferenceText
        . $refundText
        . $providerMessageText,
        [
            'order_id' => $orderId,
            'game_name' => $order['juego_nombre'] ?? '',
            'pack_name' => $order['paquete_nombre'] ?? '',
            'pack_amount' => $order['paquete_cantidad'] ?? '',
            'currency' => $order['moneda'] ?? '',
            'price' => number_format((float) ($order['precio'] ?? 0), 2, '.', ','),
            'user_identifier' => $order['user_identifier'] ?? '',
            'email' => $order['email'] ?? '',
            'coupon' => $order['cupon'] ?? null,
            'status' => 'Cancelado',
        ],
        '#ef4444'
    );

    if (!empty($order['email']) && filter_var($order['email'], FILTER_VALIDATE_EMAIL)) {
        send_app_mail((string) $order['email'], "Compra cancelada #{$orderId}", $customerHtml, null, $brandingImages);
    }
    if ($adminEmail !== null) {
        send_app_mail($adminEmail, "Compra API cancelada #{$orderId}", $adminHtml, null, $brandingImages);
    }
}

function sync_local_order_with_provider_detail(mysqli $mysqli, array $order, array $providerDetail, bool $notify = true): array {
    $mysqli = ensure_mysqli_connection($mysqli);

    $orderId = (int) ($order['id'] ?? 0);
    if ($orderId <= 0) {
        throw new RuntimeException('Pedido local inválido para sincronizar.');
    }

    $providerStatus = strtolower(trim((string) ($providerDetail['estado'] ?? '')));
    $providerOrderId = sanitize_str((string) ($providerDetail['id'] ?? $order['recargas_api_pedido_id'] ?? ''), 120) ?? '';
    $providerReference = provider_order_display_reference($providerDetail, (string) ($order['ff_api_referencia'] ?? ''));
    $providerMessage = provider_order_status_message($providerDetail, (string) ($order['ff_api_mensaje'] ?? ''));
    $providerCode = provider_delivered_code_text($providerDetail);
    $refundAmount = isset($providerDetail['reembolso']) && is_numeric($providerDetail['reembolso'])
        ? round((float) $providerDetail['reembolso'], 2)
        : null;
    $localStatus = provider_status_to_local_status($providerStatus) ?? (string) ($order['estado'] ?? 'pagado');
    $payloadJson = json_encode($providerDetail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($payloadJson)) {
        $payloadJson = (string) ($order['ff_api_payload'] ?? '');
    }
    $historyJson = append_provider_history(
        $order['recargas_api_historial_json'] ?? null,
        build_provider_history_entry(
            'sync',
            $providerStatus,
            $localStatus,
            $providerMessage,
            $providerReference,
            $providerOrderId,
            $providerCode,
            $refundAmount
        )
    );

    $stmt = $mysqli->prepare("UPDATE pedidos SET ff_api_referencia = ?, ff_api_mensaje = ?, ff_api_payload = ?, recargas_api_pedido_id = ?, recargas_api_estado = ?, recargas_api_codigo_entregado = ?, recargas_api_reembolso = ?, recargas_api_ultimo_check = NOW(), recargas_api_historial_json = ?, estado = ? WHERE id = ? LIMIT 1");
    if (!$stmt) {
        throw new RuntimeException('No se pudo preparar la sincronización del pedido local.');
    }

    $stmt->bind_param(
        'ssssssdssi',
        $providerReference,
        $providerMessage,
        $payloadJson,
        $providerOrderId,
        $providerStatus,
        $providerCode,
        $refundAmount,
        $historyJson,
        $localStatus,
        $orderId
    );
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('No se pudo actualizar el pedido local con el estado del proveedor.');
    }
    $stmt->close();

    $updatedOrder = fetch_order_by_id($mysqli, $orderId) ?: $order;
    $previousStatus = (string) ($order['estado'] ?? '');
    if ($localStatus !== $previousStatus) {
        win_points_handle_order_status_change($mysqli, $orderId, $localStatus);
    }
    if (in_array($localStatus, ['pagado', 'enviado'], true)) {
        recharge_notifications_emit_for_order($mysqli, $updatedOrder);
    }
    if ($notify && $localStatus !== $previousStatus) {
        if ($localStatus === 'enviado') {
            notify_free_fire_recharge_success(
                $mysqli,
                $updatedOrder,
                'Proveedor API',
                (string) ($updatedOrder['numero_referencia'] ?? ''),
                (string) ($updatedOrder['telefono_contacto'] ?? ''),
                $providerReference,
                $providerMessage !== '' ? $providerMessage : 'Pedido completado por el proveedor.'
            );
        } elseif ($localStatus === 'cancelado') {
            notify_catalog_purchase_cancelled($mysqli, $updatedOrder, $providerReference, $providerMessage, $refundAmount);
        }
    }

    return [
        'order' => $updatedOrder,
        'provider_status' => $providerStatus,
        'local_status' => $localStatus,
        'provider_reference' => $providerReference,
        'provider_message' => $providerMessage,
        'refund_amount' => $refundAmount,
        'provider_code' => $providerCode,
    ];
}

function try_auto_sync_provider_order(mysqli $mysqli, array $order, int $attempts = 3, int $delaySeconds = 2): ?array {
    $providerOrderId = trim((string) ($order['recargas_api_pedido_id'] ?? ''));
    if ($providerOrderId === '') {
        return null;
    }

    $attempts = max(1, $attempts);
    $delaySeconds = max(0, $delaySeconds);
    $latestSync = null;

    for ($attempt = 1; $attempt <= $attempts; $attempt++) {
        try {
            $mysqli = ensure_mysqli_connection($mysqli);
            $providerDetail = recargas_api_fetch_order_detail($providerOrderId);
            $latestSync = sync_local_order_with_provider_detail($mysqli, $order, $providerDetail, true);

            if (in_array((string) ($latestSync['local_status'] ?? ''), ['enviado', 'cancelado'], true)) {
                return $latestSync;
            }

            $order = is_array($latestSync['order'] ?? null) ? $latestSync['order'] : (fetch_order_by_id($mysqli, (int) ($order['id'] ?? 0)) ?: $order);
        } catch (Throwable $e) {
            $latestSync = [
                'order' => $order,
                'provider_status' => trim((string) ($order['recargas_api_estado'] ?? '')),
                'local_status' => trim((string) ($order['estado'] ?? 'pagado')),
                'provider_reference' => trim((string) ($order['ff_api_referencia'] ?? '')),
                'provider_message' => trim((string) ($order['ff_api_mensaje'] ?? '')),
                'sync_error' => trim((string) $e->getMessage()),
                'refund_amount' => isset($order['recargas_api_reembolso']) ? (float) $order['recargas_api_reembolso'] : null,
                'provider_code' => trim((string) ($order['recargas_api_codigo_entregado'] ?? '')),
            ];
        }

        if ($attempt < $attempts && $delaySeconds > 0) {
            sleep($delaySeconds);
        }
    }

    return $latestSync;
}

function notify_bank_payment_verified_paid(
    mysqli $mysqli,
    array $order,
    string $paymentMethodName,
    string $referenceNumber,
    string $phone
): void {
    $orderId = (int) ($order['id'] ?? 0);
    if ($orderId <= 0) {
        return;
    }

    $adminEmail = resolve_admin_email($mysqli);
    $brandingImages = email_branding_embedded_images();

    $customerHtml = render_order_email('Pago verificado', 'Cliente',
        '<p style="margin:0 0 10px;">Tu pago fue verificado automáticamente contra los movimientos bancarios.</p>'
        . '<p style="margin:0;">La orden quedó en estado <strong style="color:#f59e0b;">Verificado</strong> para continuar con la gestión manual del producto.</p>',
        [
            'order_id' => $orderId,
            'game_name' => $order['juego_nombre'] ?? '',
            'pack_name' => $order['paquete_nombre'] ?? '',
            'pack_amount' => $order['paquete_cantidad'] ?? '',
            'currency' => $order['moneda'] ?? '',
            'price' => number_format((float) ($order['precio'] ?? 0), 2, '.', ','),
            'user_identifier' => $order['user_identifier'] ?? '',
            'email' => $order['email'] ?? '',
            'coupon' => $order['cupon'] ?? null,
            'payment_method' => $paymentMethodName,
            'reference_number' => $referenceNumber,
            'phone' => $phone,
            'status' => 'Verificado',
        ],
        '#f59e0b'
    );
    $adminHtml = render_order_email('Pago verificado automáticamente', 'Administrador',
        '<p style="margin:0 0 10px;">El pago del cliente fue validado automáticamente con la API bancaria.</p>'
        . '<p style="margin:0;">La orden quedó en estado <strong style="color:#f59e0b;">Verificado</strong> para gestión manual.</p>',
        [
            'order_id' => $orderId,
            'game_name' => $order['juego_nombre'] ?? '',
            'pack_name' => $order['paquete_nombre'] ?? '',
            'pack_amount' => $order['paquete_cantidad'] ?? '',
            'currency' => $order['moneda'] ?? '',
            'price' => number_format((float) ($order['precio'] ?? 0), 2, '.', ','),
            'user_identifier' => $order['user_identifier'] ?? '',
            'email' => $order['email'] ?? '',
            'coupon' => $order['cupon'] ?? null,
            'payment_method' => $paymentMethodName,
            'reference_number' => $referenceNumber,
            'phone' => $phone,
            'status' => 'Verificado',
        ],
        '#f59e0b'
    );

    if (!empty($order['email']) && filter_var($order['email'], FILTER_VALIDATE_EMAIL)) {
        send_app_mail((string) $order['email'], "Pago verificado #{$orderId}", $customerHtml, null, $brandingImages);
    }
    if ($adminEmail !== null) {
        send_app_mail($adminEmail, "Pago verificado automáticamente #{$orderId}", $adminHtml, null, $brandingImages);
    }
}

function notify_bank_payment_pending_mismatch(
    mysqli $mysqli,
    array $order,
    string $paymentMethodName,
    string $referenceNumber,
    string $phone,
    array $reasons
): void {
    $orderId = (int) ($order['id'] ?? 0);
    if ($orderId <= 0) {
        return;
    }

    $adminEmail = resolve_admin_email($mysqli);
    $brandingImages = email_branding_embedded_images();
    $reasonHtml = '';
    if (!empty($reasons)) {
        $items = array_map(static fn ($reason) => '<li>' . email_escape((string) $reason) . '</li>', $reasons);
        $reasonHtml = '<ul style="margin:10px 0 0 18px;padding:0;color:#fecaca;">' . implode('', $items) . '</ul>';
    }

    $customerHtml = render_order_email('Pago no verificado', 'Cliente',
        '<p style="margin:0 0 10px;">No pudimos confirmar automáticamente tu pago con los datos enviados.</p>'
        . '<p style="margin:0 0 10px;">La orden se mantiene en estado <strong style="color:#22d3ee;">No Verificado</strong> para que puedas verificar la referencia e intentarlo nuevamente.</p>'
        . $reasonHtml,
        [
            'order_id' => $orderId,
            'game_name' => $order['juego_nombre'] ?? '',
            'pack_name' => $order['paquete_nombre'] ?? '',
            'pack_amount' => $order['paquete_cantidad'] ?? '',
            'currency' => $order['moneda'] ?? '',
            'price' => number_format((float) ($order['precio'] ?? 0), 2, '.', ','),
            'user_identifier' => $order['user_identifier'] ?? '',
            'email' => $order['email'] ?? '',
            'coupon' => $order['cupon'] ?? null,
            'payment_method' => $paymentMethodName,
            'reference_number' => $referenceNumber,
            'phone' => $phone,
            'status' => 'No Verificado',
        ],
        '#22d3ee'
    );
    $adminHtml = render_order_email('Pago no verificado automáticamente', 'Administrador',
        '<p style="margin:0 0 10px;">La API bancaria no encontró coincidencia para este pago reportado.</p>'
        . '<p style="margin:0 0 10px;">La orden se mantiene en estado <strong style="color:#22d3ee;">No Verificado</strong>.</p>'
        . $reasonHtml,
        [
            'order_id' => $orderId,
            'game_name' => $order['juego_nombre'] ?? '',
            'pack_name' => $order['paquete_nombre'] ?? '',
            'pack_amount' => $order['paquete_cantidad'] ?? '',
            'currency' => $order['moneda'] ?? '',
            'price' => number_format((float) ($order['precio'] ?? 0), 2, '.', ','),
            'user_identifier' => $order['user_identifier'] ?? '',
            'email' => $order['email'] ?? '',
            'coupon' => $order['cupon'] ?? null,
            'payment_method' => $paymentMethodName,
            'reference_number' => $referenceNumber,
            'phone' => $phone,
            'status' => 'No Verificado',
        ],
        '#22d3ee'
    );

    if (!empty($order['email']) && filter_var($order['email'], FILTER_VALIDATE_EMAIL)) {
        send_app_mail((string) $order['email'], "Pago no verificado #{$orderId}", $customerHtml, null, $brandingImages);
    }
    if ($adminEmail !== null) {
        send_app_mail($adminEmail, "Pago no verificado automáticamente #{$orderId}", $adminHtml, null, $brandingImages);
    }
}

function paypal_popup_bridge_html(string $title, string $message, string $state = 'info', array $payload = []): string {
    $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $safeMessage = nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8'), false);
    $safeState = in_array($state, ['success', 'danger', 'warning', 'info'], true) ? $state : 'info';
    $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($payloadJson)) {
        $payloadJson = '{}';
    }

    return '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . $safeTitle . '</title><style>'
        . 'body{margin:0;font-family:Arial,sans-serif;background:#081018;color:#e2e8f0;display:flex;align-items:center;justify-content:center;min-height:100vh;padding:24px}'
        . '.card{width:min(720px,100%);background:#111827;border:1px solid rgba(96,165,250,.45);border-radius:24px;padding:32px;box-shadow:0 24px 60px rgba(0,0,0,.35)}'
        . 'h1{margin:0 0 12px;font-size:28px;color:' . ($safeState === 'success' ? '#34d399' : ($safeState === 'danger' ? '#f87171' : '#60a5fa')) . '}'
        . 'p{margin:0 0 12px;line-height:1.7;font-size:16px}.hint{margin-top:18px;color:#94a3b8;font-size:13px}.btn{display:inline-block;margin-top:16px;padding:12px 18px;border-radius:12px;background:#0f172a;border:1px solid #334155;color:#f8fafc;text-decoration:none}'
        . '</style></head><body><div class="card"><h1>' . $safeTitle . '</h1><p>' . $safeMessage . '</p><p class="hint">Puedes cerrar esta ventana. La tienda seguirá sincronizando el pedido automáticamente.</p><a class="btn" href="#" onclick="window.close();return false;">Cerrar ventana</a></div><script>'
        . 'const payload=' . $payloadJson . ';'
        . 'try{if(window.opener&&!window.opener.closed){window.opener.postMessage({source:"paypal-checkout",payload},"*");}}catch(_){ }'
        . 'setTimeout(()=>{try{window.close();}catch(_){ }},1800);'
        . '</script></body></html>';
}

function paypal_event_order_identifiers(array $event): array {
    $eventType = strtoupper(trim((string) ($event['event_type'] ?? '')));
    $resource = is_array($event['resource'] ?? null) ? $event['resource'] : [];
    $orderId = trim((string) ($resource['supplementary_data']['related_ids']['order_id'] ?? ''));
    $captureId = '';

    if (str_starts_with($eventType, 'CHECKOUT.ORDER.')) {
        if ($orderId === '') {
            $orderId = trim((string) ($resource['id'] ?? ''));
        }
    }

    if (str_starts_with($eventType, 'PAYMENT.CAPTURE.')) {
        $captureId = trim((string) ($resource['id'] ?? ''));
    }

    return [
        'order_id' => $orderId,
        'capture_id' => $captureId,
    ];
}

$action = $_POST['action'] ?? $_GET['action'] ?? null;
if (!$action) {
    json_error('Acción no especificada', 422);
}

try {
    ensure_pedidos_table($mysqli);
} catch (Throwable $e) {
    error_log('TVG ensure_pedidos_table skipped: ' . $e->getMessage());
}
try {
    ensure_movimientos_table($mysqli);
} catch (Throwable $e) {
    error_log('TVG ensure_movimientos_table skipped: ' . $e->getMessage());
}
try {
    ensure_juegos_api_free_fire_column($mysqli);
} catch (Throwable $e) {
    error_log('TVG ensure_juegos_api_free_fire_column skipped: ' . $e->getMessage());
}
try {
    ensure_juegos_categoria_api_column($mysqli);
} catch (Throwable $e) {
    error_log('TVG ensure_juegos_categoria_api_column skipped: ' . $e->getMessage());
}
try {
    ensure_juegos_categoria_api_discord_column($mysqli);
} catch (Throwable $e) {
    error_log('TVG ensure_juegos_categoria_api_discord_column skipped: ' . $e->getMessage());
}
try {
    ensure_juegos_api_discord_catalog_columns($mysqli);
} catch (Throwable $e) {
    error_log('TVG ensure_juegos_api_discord_catalog_columns skipped: ' . $e->getMessage());
}
try {
    ensure_juego_paquetes_monto_ff_column($mysqli);
} catch (Throwable $e) {
    error_log('TVG ensure_juego_paquetes_monto_ff_column skipped: ' . $e->getMessage());
}
try {
    ensure_juego_paquetes_paquete_api_column($mysqli);
} catch (Throwable $e) {
    error_log('TVG ensure_juego_paquetes_paquete_api_column skipped: ' . $e->getMessage());
}
try {
    ensure_juego_paquetes_api_provider_column($mysqli);
} catch (Throwable $e) {
    error_log('TVG ensure_juego_paquetes_api_provider_column skipped: ' . $e->getMessage());
}
try {
    influencer_coupon_ensure_sales_table_mysqli($mysqli);
} catch (Throwable $e) {
    error_log('TVG influencer_coupon_ensure_sales_table_mysqli skipped: ' . $e->getMessage());
}
sync_coupon_usage_counts_safe($mysqli);
try {
    win_points_ensure_schema();
} catch (Throwable $e) {
    error_log('TVG win_points_ensure_schema skipped: ' . $e->getMessage());
}

if ($action === 'create') {
    $game_id = isset($_POST['game_id']) ? intval($_POST['game_id']) : null;
    $package_id = isset($_POST['package_id']) ? intval($_POST['package_id']) : 0;
    // Si no viene game_name, intentar obtenerlo por ID
    $game_name = sanitize_str($_POST['game_name'] ?? null, 180);
    $pack_name = sanitize_str($_POST['pack_name'] ?? null, 180);
    $pack_amount_text = sanitize_str($_POST['pack_amount'] ?? null, 80); // texto descriptivo
    $monto_ff = null;
    $paquete_api = null;
    $pack_amount_num = 1;
    $purchaseQuantityEnabled = trim((string) store_config_get('cantidad_paquetes', '0')) === '1';
    $purchaseQuantityRaw = trim((string) ($_POST['quantity'] ?? '1'));
    if ($purchaseQuantityEnabled) {
        if ($purchaseQuantityRaw === '' || preg_match('/^[1-9]\d*$/', $purchaseQuantityRaw) !== 1) {
            json_error('La cantidad a comprar debe ser un numero entero mayor a cero.');
        }
        $purchaseQuantity = (int) $purchaseQuantityRaw;
    } else {
        $purchaseQuantity = 1;
    }
    if ($pack_amount_text !== null && is_numeric($pack_amount_text)) {
        $pack_amount_num = intval($pack_amount_text);
    }
    $currency = sanitize_str($_POST['currency'] ?? null, 20);
    $price_raw = str_replace([',', ' '], '', $_POST['price'] ?? '0');
    $price = is_numeric($price_raw) ? floatval($price_raw) : 0;
    $user_identifier = sanitize_str($_POST['user_identifier'] ?? null, 150);
    $player_fields = parse_player_fields_request($_POST['player_fields_json'] ?? '');
    $player_fields_json = null;
    $email = sanitize_str($_POST['email'] ?? null, 180);
    $cuponInput = sanitize_str($_POST['coupon'] ?? null, 60);
    $cupon = null;
    $cliente_usuario_id = isset($_SESSION['auth_user']['id']) ? intval($_SESSION['auth_user']['id']) : null;
    if ($cuponInput !== null) {
        if (!is_valid_coupon_code($cuponInput)) {
            json_error('El cupón solo puede contener letras y números, sin espacios ni caracteres especiales.');
        }
        $cupon = normalize_coupon_code($cuponInput);
    }
    $tenant_slug = resolve_tenant_slug();
    $usesCatalogApi = false;
    $usesDiscordApi = false;
    $usesFreeFireApi = false;
    $packageApiProvider = '';
    $discordCommandKey = '';
    $discordCheckoutRequiredFields = [];
    $catalogProduct = null;
    $selectedPackageIsAccountSale = false;
    $accountSaleTextSnapshot = null;
    $accountSaleGalleryJson = null;

    $missing = [];
    if (!$game_name && $game_id) {
        $stmtG = $mysqli->prepare('SELECT nombre FROM juegos WHERE id=? LIMIT 1');
        if ($stmtG) {
            $stmtG->bind_param('i', $game_id);
            $stmtG->execute();
            $resG = $stmtG->get_result();
            $rowG = $resG ? $resG->fetch_assoc() : null;
            if ($rowG && !empty($rowG['nombre'])) {
                $game_name = $rowG['nombre'];
            }
        }
    }

    $selectedPackage = null;
    if ($package_id > 0 && $game_id) {
        $selectedPackage = fetch_game_package($mysqli, $package_id, (int) $game_id);
        if ($selectedPackage) {
            $pack_name = sanitize_str((string) ($selectedPackage['nombre'] ?? $pack_name), 180);
            $pack_amount_text = sanitize_str((string) ($selectedPackage['cantidad'] ?? $pack_amount_text), 80);
            $monto_ff = sanitize_str((string) ($selectedPackage['monto_ff'] ?? ''), 20);
            $paquete_api = isset($selectedPackage['paquete_api']) ? (int) $selectedPackage['paquete_api'] : null;
            $packageApiProvider = package_api_provider_from_row($selectedPackage, [
                'categoria_api_discord' => game_discord_api_command($mysqli, (int) $game_id),
            ]);
            $usesCatalogApi = $packageApiProvider === 'giftven';
            $usesDiscordApi = $packageApiProvider === 'discord';
            $usesFreeFireApi = $packageApiProvider === 'free_fire';
            $discordCommandKey = $usesDiscordApi ? game_discord_api_command($mysqli, (int) $game_id) : '';
            $discordCheckoutRequiredFields = $discordCommandKey !== '' ? api_discord_checkout_required_fields($discordCommandKey) : [];
            if ($pack_amount_text !== null && is_numeric($pack_amount_text)) {
                $pack_amount_num = intval($pack_amount_text);
            }
            $selectedPackageIsAccountSale = package_account_sales_is_enabled_for_package($selectedPackage, account_sale_feature_enabled());
            if ($selectedPackageIsAccountSale) {
                $accountSaleSnapshot = package_account_sales_build_snapshot(
                    $selectedPackage,
                    package_account_sales_fetch_gallery($mysqli, $package_id)
                );
                $accountSaleTextSnapshot = $accountSaleSnapshot['account_text'] !== '' ? $accountSaleSnapshot['account_text'] : null;
                $encodedAccountSaleGallery = json_encode($accountSaleSnapshot['gallery'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $accountSaleGalleryJson = is_string($encodedAccountSaleGallery) ? $encodedAccountSaleGallery : null;
            }
        }
    }

    if (!$selectedPackage) {
        json_error('El paquete seleccionado no existe para este juego.');
    }
    if (!recharge_availability_is_game_active($mysqli, (int) $game_id)) {
        json_error('Este juego no está disponible en este momento.');
    }
    if (!recharge_availability_is_package_active($mysqli, $package_id, (int) $game_id)) {
        json_error('Este paquete no está disponible en este momento.');
    }

    $winPointsAward = 0;
    $winPointsEligible = win_points_enabled() && $cliente_usuario_id !== null && $cliente_usuario_id > 0;
    $winPointsAllowedForOrder = $winPointsEligible;
    if ($winPointsAllowedForOrder) {
        $winPointsAward = win_points_package_reward($selectedPackage) * $purchaseQuantity;
    }

    $selectedCurrency = currency_find_by_code((string) $currency);
    if (!$selectedCurrency) {
        json_error('La moneda seleccionada no es válida.');
    }
    $currency = currency_normalize_code((string) ($selectedCurrency['clave'] ?? $currency));
    $unitPrice = currency_convert_from_base((float) ($selectedPackage['precio'] ?? 0), $selectedCurrency);
    $price = currency_apply_amount_rule($unitPrice * $purchaseQuantity, $selectedCurrency);
    if ($price <= 0) {
        json_error('El paquete seleccionado no tiene un precio válido para la moneda elegida.');
    }

    if (!$selectedPackageIsAccountSale && $usesCatalogApi && ($paquete_api === null || $paquete_api <= 0)) {
        json_error('Este paquete no tiene un producto API configurado.');
    }

    if (!$selectedPackageIsAccountSale && $usesFreeFireApi && ($monto_ff === null || $monto_ff === '')) {
        json_error('Este paquete no tiene un monto API configurado para Free Fire.');
    }

    if (!$selectedPackageIsAccountSale && $usesDiscordApi && $discordCommandKey === '') {
        json_error('Este paquete usa Discord pero el juego no tiene un comando base configurado.');
    }

    if (!$selectedPackageIsAccountSale && $usesCatalogApi) {
        try {
            $catalogProduct = recargas_api_fetch_product_by_id((int) $paquete_api);
        } catch (Throwable $e) {
            json_error($e->getMessage());
        }

        if ($catalogProduct === null) {
            json_error('El producto API configurado ya no está disponible en el catálogo remoto.');
        }

        try {
            $player_fields = build_catalog_player_fields($catalogProduct, $user_identifier, $player_fields);
        } catch (Throwable $e) {
            json_error($e->getMessage());
        }

        $user_identifier = primary_player_identifier_from_fields($player_fields) ?? $user_identifier;
        $player_fields_json = json_encode($player_fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($player_fields_json)) {
            $player_fields_json = null;
        }
    }

    if (!$selectedPackageIsAccountSale && $usesDiscordApi && $discordCheckoutRequiredFields !== []) {
        $discordMissingFields = [];
        foreach ($discordCheckoutRequiredFields as $fieldConfig) {
            if (!is_array($fieldConfig)) {
                continue;
            }

            $fieldName = normalize_player_field_key((string) ($fieldConfig['name'] ?? ''));
            if ($fieldName === '') {
                continue;
            }

            $fieldValue = '';
            switch ($fieldName) {
                case 'uid':
                case 'id':
                    $fieldValue = api_discord_order_player_value($player_fields, ['uid', 'id', 'player_id', 'playerid', 'user_id', 'userid', 'id_juego', 'input1']);
                    if ($fieldValue === '') {
                        $fieldValue = trim((string) $user_identifier);
                    }
                    break;

                case 'sv':
                    $fieldValue = api_discord_order_player_value($player_fields, ['sv', 'server', 'server_id', 'serverid', 'zone', 'zone_id', 'zoneid', 'zona', 'input2']);
                    break;

                default:
                    $fieldValue = trim((string) ($player_fields[$fieldName] ?? ''));
                    break;
            }

            $pattern = trim((string) ($fieldConfig['pattern'] ?? ''));
            if ($fieldValue === '' || ($pattern !== '' && preg_match('/^' . $pattern . '$/u', $fieldValue) !== 1)) {
                $discordMissingFields[] = trim((string) ($fieldConfig['label'] ?? $fieldName));
            }
        }

        if ($discordMissingFields !== []) {
            json_error('Faltan datos obligatorios para la recarga por API Discord: ' . implode(', ', $discordMissingFields));
        }
    }

    if ($player_fields_json === null && $player_fields !== []) {
        $player_fields_json = json_encode($player_fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($player_fields_json)) {
            $player_fields_json = null;
        }
    }

    if (!$game_name) $missing[] = 'game_name';
    if ($package_id <= 0) $missing[] = 'package_id';
    if (!$pack_name) $missing[] = 'pack_name';
    if (!$currency) $missing[] = 'currency';
    if (!$selectedPackageIsAccountSale && !$usesCatalogApi && !$user_identifier) $missing[] = 'user_identifier';
    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) $missing[] = 'email';
    if (!empty($missing)) {
        json_error('Faltan datos obligatorios del pedido: ' . implode(', ', $missing));
    }

    // Validar y aplicar cupón si existe
    if ($cupon) {
        $couponData = fetch_valid_coupon($mysqli, $cupon, (int) $game_id);
        if (!$couponData) {
            json_error('Cupón inválido o vencido');
        }
        if (!coupon_allows_points_accumulation($couponData)) {
            $winPointsAllowedForOrder = false;
            $winPointsAward = 0;
        }
        $price = currency_apply_amount_rule(apply_coupon_to_price($price, $couponData), $selectedCurrency);
        // Registrar uso del cupón (best effort)
        if (isset($couponData['id'])) {
            $upd = $mysqli->prepare("UPDATE cupones SET usos_actuales = COALESCE(usos_actuales,0) + 1 WHERE id = ?");
            if ($upd) {
                $upd->bind_param('i', $couponData['id']);
                $upd->execute();
            }
        }
        // Aseguramos que el cupón se inserte como string, no como null
        $cupon = $couponData['codigo'];
    } else {
        $cupon = null;
    }

    $priceBeforeDifferenceCredit = payment_difference_normalize_amount($price);
    $paymentDifferenceCredit = payment_difference_get_credit();
    $paymentDifferenceCreditApplied = 0.0;
    $paymentDifferenceCreditSourceOrderId = 0;
    if ($paymentDifferenceCredit !== null && strtoupper(trim((string) ($paymentDifferenceCredit['currency'] ?? ''))) === strtoupper(trim((string) $currency))) {
        $availableCredit = payment_difference_normalize_amount($paymentDifferenceCredit['available_amount'] ?? 0);
        if ($availableCredit >= $priceBeforeDifferenceCredit) {
            json_error('Selecciona un paquete cuyo monto sea mayor al saldo a favor disponible para completar la recarga.');
        }

        $paymentDifferenceCreditApplied = min($availableCredit, $priceBeforeDifferenceCredit);
        $paymentDifferenceCreditSourceOrderId = (int) ($paymentDifferenceCredit['source_order_id'] ?? 0);
        $price = payment_difference_normalize_amount($priceBeforeDifferenceCredit - $paymentDifferenceCreditApplied);
    }

    $winPointsPaymentMode = 'money';
    $apiDiscordOrderData = build_api_discord_order_insert_data($mysqli, (int) $game_id, $packageApiProvider);
    try {
        $order_id = pedidos_insert_order($mysqli, array_merge([
            'tenant_slug' => $tenant_slug,
            'juego_id' => $game_id,
            'paquete_id' => $package_id,
            'juego_nombre' => $game_name,
            'paquete_nombre' => $pack_name,
            'paquete_cantidad' => $pack_amount_text,
            'monto_ff' => $monto_ff,
            'paquete_api' => $paquete_api,
            'api_provider' => $packageApiProvider !== '' ? $packageApiProvider : null,
            'moneda' => $currency,
            'precio' => $price,
            'precio_descuento_metodo_pago_base' => $price,
            'descuento_metodo_pago_porcentaje' => 0,
            'descuento_metodo_pago_monto' => 0,
            'precio_original' => $priceBeforeDifferenceCredit,
            'diferencia_pago_credito_aplicado' => $paymentDifferenceCreditApplied,
            'diferencia_pago_credito_origen_pedido_id' => $paymentDifferenceCreditSourceOrderId > 0 ? $paymentDifferenceCreditSourceOrderId : null,
            'user_identifier' => $user_identifier,
            'player_fields_json' => $player_fields_json,
            'email' => $email,
            'cliente_usuario_id' => $cliente_usuario_id,
            'cupon' => $cupon,
            'cantidad_compra' => $purchaseQuantity,
            'cantidad' => $pack_amount_num,
            'vender_cuenta' => $selectedPackageIsAccountSale ? 1 : 0,
            'cuenta_entrega_texto' => $accountSaleTextSnapshot,
            'cuenta_galeria_json' => $accountSaleGalleryJson,
            'win_points_awarded' => $winPointsAward,
            'win_points_payment_mode' => $winPointsPaymentMode,
            'estado' => 'pendiente',
        ], $apiDiscordOrderData));
    } catch (Throwable $e) {
        error_log('TVG pedidos_insert_order failed: ' . $e->getMessage());
        json_error('No se pudo guardar el pedido', 500);
    }
    if ($paymentDifferenceCreditApplied > 0) {
        payment_difference_consume_credit();
    }
    update_user_last_purchase_details(
        $mysqli,
        (int) ($cliente_usuario_id ?? 0),
        should_store_last_purchase_identifier() ? $user_identifier : null,
        null
    );
    sync_coupon_usage_counts_safe($mysqli);
    $storedOrder = fetch_order_by_id($mysqli, $order_id);
    if ($storedOrder === null) {
        json_error('No se pudo recuperar el pedido recién creado.', 500);
    }
    $adminEmail = resolve_admin_email($mysqli);
    $defaultPaymentMethod = default_payment_method_for_currency($currency ?? '');

    $customerMessage = '<p style="margin:0 0 10px;">Tu pedido fue creado correctamente y quedó no verificado hasta confirmar el pago.</p>'
        . '<p style="margin:0;">Debes realizar el pago usando el método disponible para la moneda seleccionada y luego enviar tu referencia desde la pantalla de pago para que el administrador pueda revisarla.</p>'
        . payment_method_details_html($defaultPaymentMethod);
    $adminMessage = '<p style="margin:0 0 10px;">Se generó un nuevo pedido y ya está disponible para revisión en el panel administrativo.</p>'
        . '<p style="margin:0;">Valida los datos del cliente y procede con la gestión correspondiente.</p>';
    $customerHtml = render_order_email('Pedido creado, no verificado', 'Cliente', $customerMessage, [
        'order_id' => $order_id,
        'game_name' => $game_name,
        'pack_name' => $pack_name,
        'pack_amount' => $pack_amount_text,
        'quantity' => order_purchase_quantity_text($purchaseQuantity),
        'currency' => $currency,
        'price' => format_order_price_value((float) $price, $currency),
        'user_identifier' => $user_identifier,
        'email' => $email,
        'coupon' => $cupon,
        'payment_method' => $defaultPaymentMethod['nombre'] ?? '',
        'status' => 'No Verificado',
    ]);
    $adminHtml = render_order_email('Nuevo pedido', 'Administrador', $adminMessage, [
        'order_id' => $order_id,
        'game_name' => $game_name,
        'pack_name' => $pack_name,
        'pack_amount' => $pack_amount_text,
        'quantity' => order_purchase_quantity_text($purchaseQuantity),
        'currency' => $currency,
        'price' => format_order_price_value((float) $price, $currency),
        'user_identifier' => $user_identifier,
        'email' => $email,
        'coupon' => $cupon,
        'status' => 'No Verificado',
    ], '#34d399');
    // These notifications are intentionally excluded from the synchronous checkout
    // create flow so the payment window can open immediately after the order is stored.

    $createOrderPayload = [
        'ok' => true,
        'message' => 'Pedido registrado',
        'order_id' => $order_id,
        'provider_flow' => order_provider_flow_from_row($storedOrder),
        'total_text' => format_order_price_value((float) ($storedOrder['precio'] ?? $price), (string) ($storedOrder['moneda'] ?? $currency)),
        'estado' => 'pendiente',
        'created_at' => date(DATE_ATOM, isset($storedOrder['creado_en_ts']) ? (int) $storedOrder['creado_en_ts'] : time()),
        'expires_at' => order_expiration_iso($storedOrder),
        'remaining_seconds' => max(0, order_expiration_timestamp($storedOrder) - time()),
        'payment_difference' => $paymentDifferenceCreditApplied > 0 ? [
            'status' => 'credit_applied',
            'source_order_id' => $paymentDifferenceCreditSourceOrderId,
            'applied_amount' => payment_difference_normalize_amount($paymentDifferenceCreditApplied),
            'original_total' => payment_difference_normalize_amount($priceBeforeDifferenceCredit),
            'remaining_amount' => payment_difference_normalize_amount($price),
            'currency' => strtoupper(trim((string) $currency)) !== '' ? strtoupper(trim((string) $currency)) : 'VES',
        ] : null,
        'win_points' => win_points_response_payload($mysqli, $winPointsEligible && $cliente_usuario_id !== null ? (int) $cliente_usuario_id : null, [
            'enabled' => win_points_enabled(),
            'eligible' => $winPointsAllowedForOrder,
            'award' => $winPointsAward,
        ])
    ];

    $discordPayload = build_api_discord_order_payload($storedOrder);
    if ($discordPayload !== null) {
        $createOrderPayload['discord'] = $discordPayload;
    }

    json_response($createOrderPayload);
}

if ($action === 'submit_payment') {
    $mysqli = ensure_mysqli_connection($mysqli);

    $orderId = intval($_POST['order_id'] ?? 0);
    $paymentMode = trim((string) ($_POST['payment_mode'] ?? 'money'));
    $paymentMode = in_array($paymentMode, ['money', 'points', 'binance_pagonorte', 'binance', 'paypal'], true) ? $paymentMode : 'money';
    $paymentMethodId = intval($_POST['payment_method_id'] ?? 0);
    $referenceNumberRaw = trim((string) ($_POST['reference_number'] ?? ''));
    $phoneRaw = trim((string) ($_POST['phone'] ?? ''));

    if ($orderId <= 0) {
        json_error('Pedido inválido.');
    }

    $order = fetch_order_by_id($mysqli, $orderId);
    if (!$order) {
        json_error('Pedido no encontrado.', 404);
    }
    if (($order['estado'] ?? '') !== 'pendiente') {
        json_error('El pedido ya no admite confirmación de pago.', 409);
    }
    if (order_is_expired($order)) {
        $expiration = cancel_expired_order($mysqli, $order);
        json_error($expiration['message'] ?: 'La orden ya expiró.', 409);
    }

    if ($paymentMode !== 'binance' && trim((string) ($order['binance_pay_reference'] ?? '')) !== '') {
        clear_order_binance_pay_tracking($mysqli, $orderId);
        $order = fetch_order_by_id($mysqli, $orderId) ?: $order;
    }
    if ($paymentMode !== 'paypal' && trim((string) ($order['paypal_order_id'] ?? '')) !== '') {
        clear_order_paypal_tracking($mysqli, $orderId);
        $order = fetch_order_by_id($mysqli, $orderId) ?: $order;
    }

    if ($paymentMode === 'binance') {
        $discountSync = persist_order_payment_selection($mysqli, $order, 'binance');
        $order = is_array($discountSync['order'] ?? null) ? $discountSync['order'] : $order;
        if (!empty($discountSync['price_changed']) && trim((string) ($order['binance_pay_reference'] ?? '')) !== '') {
            clear_order_binance_pay_tracking($mysqli, $orderId);
            $order = fetch_order_by_id($mysqli, $orderId) ?: $order;
        }

        if (!binance_pay_is_enabled()) {
            json_error('Binance Pay no está activo en esta tienda.', 409);
        }
        if (!binance_pay_is_configured()) {
            json_error('Faltan credenciales de CoinPal para usar Binance Pay.', 409);
        }

        $storedCheckoutUrl = trim((string) ($order['binance_pay_checkout_url'] ?? ''));
        $existingCheckoutUrl = binance_pay_normalize_checkout_url($storedCheckoutUrl);
        $existingStatus = binance_pay_normalize_status($order['binance_pay_status'] ?? '');
        if (binance_pay_is_coinpal_checkout_url($existingCheckoutUrl) && ($existingStatus === '' || binance_pay_is_pending_status($existingStatus))) {
            if ($existingCheckoutUrl !== '' && $existingCheckoutUrl !== $storedCheckoutUrl) {
                $stmt = $mysqli->prepare("UPDATE pedidos SET binance_pay_checkout_url = ? WHERE id = ? LIMIT 1");
                if ($stmt) {
                    $stmt->bind_param('si', $existingCheckoutUrl, $orderId);
                    $stmt->execute();
                    $stmt->close();
                }
            }

            $existingCheckoutIssue = binance_pay_checkout_issue_message($existingCheckoutUrl);
            if ($existingCheckoutIssue !== '') {
                clear_order_binance_pay_tracking($mysqli, $orderId);
                $order = fetch_order_by_id($mysqli, $orderId) ?: $order;
            } else {
            $existingOrder = fetch_order_by_id($mysqli, $orderId) ?: $order;
            json_response(array_merge([
                'ok' => true,
                'message' => 'Tu checkout de Binance Pay sigue activo. Continúa el pago para completar tu pedido.',
                'order_id' => $orderId,
                'estado' => (string) ($existingOrder['estado'] ?? 'pendiente'),
            ], build_binance_checkout_response_payload($existingOrder)));
            }
        }

        $notifyUrl = build_binance_pay_notify_url();
        if ($notifyUrl === null) {
            json_error('La tienda no tiene una URL pública válida para recibir confirmaciones de Binance Pay.', 409);
        }

        $binanceCheckoutMoney = resolve_binance_checkout_money($order);
        $currencyCode = strtoupper(trim((string) ($binanceCheckoutMoney['currency'] ?? '')));
        if ($currencyCode === '') {
            json_error('La orden no tiene una moneda válida para Binance Pay.', 409);
        }

        $orderAmount = round((float) ($binanceCheckoutMoney['amount'] ?? 0), 2);
        if ($orderAmount <= 0) {
            json_error('La orden no tiene un monto válido para Binance Pay.', 409);
        }

        $checkoutInput = [
            'request_id' => binance_pay_generate_request_id(),
            'order_no' => binance_pay_make_order_no('VG'),
            'order_currency_type' => 'fiat',
            'order_currency' => $currencyCode,
            'order_amount' => number_format($orderAmount, 2, '.', ''),
            'notify_url' => $notifyUrl,
            'redirect_url' => build_binance_pay_redirect_url(),
            'payer_ip' => binance_pay_client_ip($_SERVER),
            'order_description' => trim((string) ($order['juego_nombre'] ?? 'Pedido')) . ' - ' . trim((string) ($order['paquete_nombre'] ?? ('#' . $orderId))),
            'remark' => 'pedido:' . $orderId,
        ];

        try {
            $requestPayload = binance_pay_build_checkout_payload($checkoutInput);
            $responsePayload = binance_pay_http_post_form(
                binance_pay_checkout_url(),
                $requestPayload,
                [],
                binance_pay_checkout_timeout_seconds(),
                true
            );
            if (!binance_pay_response_is_success($responsePayload)) {
                throw new RuntimeException(binance_pay_error_message_from_response($responsePayload, 200));
            }
        } catch (Throwable $e) {
            $rawMessage = trim((string) $e->getMessage());
            $customerMessage = binance_pay_customer_message($rawMessage, $currencyCode);
            $loweredMessage = strtolower($rawMessage);
            $statusCode = (str_contains($loweredMessage, 'signature verification failed') || str_contains($loweredMessage, 'payment in this currency is not supported')) ? 409 : 502;
            error_log('TVG Binance Pay checkout failed for order #' . $orderId . ': ' . $rawMessage);
            json_error($customerMessage, $statusCode);
        }

        $checkoutIssue = binance_pay_checkout_issue_message(binance_pay_extract_checkout_url($responsePayload));
        if ($checkoutIssue !== '') {
            clear_order_binance_pay_tracking($mysqli, $orderId);
            error_log('TVG Binance Pay checkout unavailable for order #' . $orderId . ': ' . $checkoutIssue);
            json_error($checkoutIssue, 502);
        }

        if (!persist_order_binance_pay_checkout($mysqli, $orderId, $requestPayload, $responsePayload)) {
            $latestOrder = fetch_order_by_id($mysqli, $orderId) ?: $order;
            if (trim((string) ($latestOrder['estado'] ?? '')) !== 'pendiente') {
                json_response(array_merge([
                    'ok' => true,
                    'message' => 'La orden cambió de estado mientras se preparaba el checkout.',
                ], build_order_status_response_payload($latestOrder, $orderId)));
            }
            json_error('No se pudo registrar el checkout de Binance Pay en el pedido.', 500);
        }

        $checkoutOrder = fetch_order_by_id($mysqli, $orderId) ?: $order;
        json_response(array_merge([
            'ok' => true,
            'message' => 'Abre Binance Pay y completa el pago para continuar con tu pedido.',
            'order_id' => $orderId,
            'estado' => 'pendiente',
        ], build_binance_checkout_response_payload($checkoutOrder)));
    }

    if ($paymentMode === 'paypal') {
        $discountSync = persist_order_payment_selection($mysqli, $order, 'paypal');
        $order = is_array($discountSync['order'] ?? null) ? $discountSync['order'] : $order;
        if (!empty($discountSync['price_changed']) && trim((string) ($order['paypal_order_id'] ?? '')) !== '') {
            clear_order_paypal_tracking($mysqli, $orderId);
            $order = fetch_order_by_id($mysqli, $orderId) ?: $order;
        }

        if (!paypal_pay_checkout_is_enabled()) {
            json_error('PayPal no está activo en esta tienda.', 409);
        }
        if (!paypal_pay_is_configured()) {
            json_error('Faltan credenciales de PayPal para usar este método.', 409);
        }

        $orderCurrency = strtoupper(trim((string) ($order['moneda'] ?? '')));
        if (!paypal_pay_supports_currency($orderCurrency)) {
            json_error('PayPal no admite pagos en ' . $orderCurrency . ' para esta orden.', 409);
        }

        $existingCheckoutUrl = trim((string) ($order['paypal_checkout_url'] ?? ''));
        $existingStatus = paypal_pay_extract_status(['status' => $order['paypal_status'] ?? '']);
        if ($existingCheckoutUrl !== '' && trim((string) ($order['paypal_order_id'] ?? '')) !== '' && ($existingStatus === '' || paypal_pay_is_pending_status($existingStatus))) {
            $existingOrder = fetch_order_by_id($mysqli, $orderId) ?: $order;
            json_response(array_merge([
                'ok' => true,
                'message' => 'Tu checkout de PayPal sigue activo. Continúa el pago para completar tu pedido.',
                'order_id' => $orderId,
                'estado' => (string) ($existingOrder['estado'] ?? 'pendiente'),
            ], build_paypal_checkout_response_payload($existingOrder)));
        }

        $returnUrl = build_paypal_pay_return_url();
        $cancelUrl = build_paypal_pay_cancel_url();
        if ($returnUrl === null || $cancelUrl === null) {
            json_error('La tienda no tiene una URL pública válida para procesar el retorno de PayPal.', 409);
        }

        $orderAmount = round((float) ($order['precio'] ?? 0), 2);
        if ($orderAmount <= 0) {
            json_error('La orden no tiene un monto válido para PayPal.', 409);
        }

        $requestPayload = paypal_pay_build_create_order_payload([
            'local_order_id' => $orderId,
            'currency' => $orderCurrency,
            'amount' => $orderAmount,
            'description' => trim((string) ($order['juego_nombre'] ?? 'Pedido')) . ' - ' . trim((string) ($order['paquete_nombre'] ?? ('#' . $orderId))),
            'return_url' => $returnUrl,
            'cancel_url' => $cancelUrl,
            'brand_name' => trim((string) store_config_get('paypal_brand_name', '')),
        ]);

        try {
            $responsePayload = paypal_pay_api_request(
                'POST',
                '/v2/checkout/orders',
                $requestPayload,
                [
                    'Prefer: return=representation',
                    'PayPal-Request-Id: VG-PAYPAL-' . $orderId . '-' . time(),
                ]
            );
        } catch (Throwable $e) {
            $rawMessage = trim((string) $e->getMessage());
            error_log('TVG PayPal checkout failed for order #' . $orderId . ': ' . $rawMessage);
            json_error(paypal_pay_customer_message($rawMessage, $orderCurrency), 502);
        }

        if (trim((string) paypal_pay_extract_approval_url($responsePayload)) === '') {
            json_error('PayPal no devolvió una URL válida para continuar el checkout.', 502);
        }

        if (!persist_order_paypal_checkout($mysqli, $orderId, $requestPayload, $responsePayload)) {
            $latestOrder = fetch_order_by_id($mysqli, $orderId) ?: $order;
            if (trim((string) ($latestOrder['estado'] ?? '')) !== 'pendiente') {
                json_response(array_merge([
                    'ok' => true,
                    'message' => 'La orden cambió de estado mientras se preparaba el checkout.',
                ], build_order_status_response_payload($latestOrder, $orderId)));
            }
            json_error('No se pudo registrar el checkout de PayPal en el pedido.', 500);
        }

        $checkoutOrder = fetch_order_by_id($mysqli, $orderId) ?: $order;
        json_response(array_merge([
            'ok' => true,
            'message' => 'Abre PayPal y completa el pago para continuar con tu pedido.',
            'order_id' => $orderId,
            'estado' => 'pendiente',
        ], build_paypal_checkout_response_payload($checkoutOrder)));
    }

    if ($paymentMode === 'points') {
        if (!win_points_enabled()) {
            json_error('El sistema de premios no está activo.', 409);
        }

        $sessionUserId = isset($_SESSION['auth_user']['id']) ? intval($_SESSION['auth_user']['id']) : 0;
        if ($sessionUserId <= 0) {
            json_error('Debes iniciar sesión para canjear tus premios.', 403);
        }
        if ($sessionUserId !== (int) ($order['cliente_usuario_id'] ?? 0)) {
            json_error('Solo el dueño de la orden puede canjear premios en este pedido.', 403);
        }

        try {
            $redemption = win_points_assign_pending_order_redemption($mysqli, $orderId, $sessionUserId);
        } catch (Throwable $e) {
            json_error($e->getMessage(), 409);
        }

        $updatedOrder = fetch_order_by_id($mysqli, $orderId) ?: $order;
        $usesCatalogApi = order_uses_catalog_api_provider($updatedOrder);
        $requiredPoints = max(0, (int) ($redemption['required_points'] ?? 0));

        if (order_is_account_sale($updatedOrder)) {
            try {
                $sentOrder = mark_account_sale_as_sent($mysqli, $updatedOrder, 'pendiente', '', '');
            } catch (Throwable $e) {
                json_error($e->getMessage(), 500);
            }

            win_points_handle_order_status_change($mysqli, $orderId, 'enviado');
            recharge_notifications_emit_for_order($mysqli, $sentOrder);
            json_response(append_account_sale_response([
                'ok' => true,
                'message' => 'Canje realizado y cuenta entregada correctamente para ' . order_purchase_quantity_text(order_purchase_quantity($sentOrder)) . '.',
                'order_id' => $orderId,
                'estado' => 'enviado',
                'verified' => true,
                'payment_mode' => 'points',
                'provider_flow' => 'account_sale',
                'provider_status' => 'account_sale',
                'win_points' => win_points_response_payload($mysqli, $sessionUserId, [
                    'spent' => $requiredPoints,
                ]),
            ], $sentOrder), 200, static function () use ($mysqli, $sentOrder): void {
                notify_account_sale_delivery($mysqli, $sentOrder, 'Canje por premios', '', '');
            });
        }

        if (!$usesCatalogApi) {
            $paidStatus = 'pagado';
            $paidStmt = $mysqli->prepare("UPDATE pedidos SET estado = ? WHERE id = ? AND estado = 'pendiente'");
            if (!$paidStmt) {
                json_error('No se pudo registrar el canje del pedido.', 500);
            }
            $paidStmt->bind_param('si', $paidStatus, $orderId);
            if (!$paidStmt->execute()) {
                $paidStmt->close();
                json_error('No se pudo actualizar el pedido tras canjear premios.', 500);
            }
            $paidStmt->close();

            $paidOrder = fetch_order_by_id($mysqli, $orderId) ?: $updatedOrder;
            recharge_notifications_emit_for_order($mysqli, $paidOrder);
            json_response([
                'ok' => true,
                'message' => 'Canje realizado. Tu pedido quedó pagado y pendiente de entrega manual para ' . order_purchase_quantity_text(order_purchase_quantity($paidOrder)) . '.',
                'order_id' => $orderId,
                'estado' => 'pagado',
                'verified' => true,
                'payment_mode' => 'points',
                'win_points' => win_points_response_payload($mysqli, $sessionUserId, [
                    'spent' => $requiredPoints,
                ]),
            ]);
        }

        $packageApiId = (int) ($updatedOrder['paquete_api'] ?? 0);
        $orderPlayerFields = order_player_fields_from_json((string) ($updatedOrder['player_fields_json'] ?? ''));

        try {
            $providerResult = execute_catalog_api_purchase($packageApiId, (string) ($updatedOrder['user_identifier'] ?? ''), $orderPlayerFields, order_purchase_quantity($updatedOrder));
        } catch (Throwable $e) {
            $providerResult = [
                'success' => false,
                'accepted' => false,
                'message' => $e->getMessage(),
                'reference' => '',
                'payload' => ['exception' => $e->getMessage()],
            ];
        }

        $providerPayloadData = (array) ($providerResult['payload'] ?? []);
        $providerReference = (string) ($providerResult['reference'] ?? '');
        $providerMessage = provider_order_status_message($providerPayloadData, (string) ($providerResult['message'] ?? 'No se recibió mensaje del proveedor.'));
        $providerPayload = json_encode($providerPayloadData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $providerOrderId = recargas_api_extract_provider_order_id($providerPayloadData);
        $providerState = strtolower(trim((string) (($providerPayloadData['estado'] ?? ''))));
        $providerCode = provider_delivered_code_text($providerPayloadData);

        if (!empty($providerResult['success'])) {
            $verifiedStatus = 'enviado';
            $providerHistoryJson = append_provider_history(
                $updatedOrder['recargas_api_historial_json'] ?? null,
                build_provider_history_entry(
                    'points_purchase',
                    $providerState,
                    $verifiedStatus,
                    $providerMessage,
                    $providerReference,
                    $providerOrderId,
                    $providerCode
                )
            );
            $verifyStmt = $mysqli->prepare("UPDATE pedidos SET ff_api_referencia = ?, ff_api_mensaje = ?, ff_api_payload = ?, recargas_api_pedido_id = ?, recargas_api_estado = ?, recargas_api_codigo_entregado = ?, recargas_api_ultimo_check = NOW(), recargas_api_historial_json = ?, estado = ? WHERE id = ? AND estado = 'pendiente'");
            if (!$verifyStmt) {
                json_error('No se pudo confirmar la recarga por premios.', 500);
            }
            $verifyStmt->bind_param('ssssssssi', $providerReference, $providerMessage, $providerPayload, $providerOrderId, $providerState, $providerCode, $providerHistoryJson, $verifiedStatus, $orderId);
            if (!$verifyStmt->execute()) {
                $verifyStmt->close();
                json_error('No se pudo actualizar el pedido tras procesar el canje.', 500);
            }
            $verifyStmt->close();

            $verifiedOrder = fetch_order_by_id($mysqli, $orderId) ?: $updatedOrder;
            win_points_handle_order_status_change($mysqli, $orderId, 'enviado');
            recharge_notifications_emit_for_order($mysqli, $verifiedOrder);
            json_response([
                'ok' => true,
                'message' => 'Canje realizado y recarga procesada correctamente para ' . order_purchase_quantity_text(order_purchase_quantity($verifiedOrder)) . '.',
                'order_id' => $orderId,
                'estado' => 'enviado',
                'verified' => true,
                'payment_mode' => 'points',
                'provider_flow' => 'completed',
                'provider_reference' => $providerReference,
                'provider_message' => $providerMessage,
                'provider_code' => $providerCode,
                'win_points' => win_points_response_payload($mysqli, $sessionUserId, [
                    'spent' => $requiredPoints,
                ]),
            ]);
        }

        $inventoryShortage = recharge_availability_message_indicates_inventory_shortage($providerMessage);
        if ($inventoryShortage) {
            try {
                $shortageResult = mark_order_inventory_shortage_review(
                    $mysqli,
                    $updatedOrder,
                    'pendiente',
                    '',
                    '',
                    $providerReference,
                    $providerMessage,
                    $providerPayload,
                    $providerOrderId,
                    $providerCode
                );
            } catch (Throwable $e) {
                json_error($e->getMessage(), 500);
            }

            $paidOrder = $shortageResult['order'];
            recharge_notifications_emit_for_order($mysqli, $paidOrder);
            json_response([
                'ok' => true,
                'message' => 'Por los momentos no hay suficientes recargas disponibles. Tu pedido quedó verificado y enviaremos la recarga en unos momentos.',
                'order_id' => $orderId,
                'estado' => 'pagado',
                'verified' => true,
                'payment_mode' => 'points',
                'provider_flow' => 'inventory_shortage',
                'provider_status' => 'inventory_shortage',
                'reasons' => [$providerMessage],
                'provider_reference' => $providerReference,
                'provider_message' => $providerMessage,
                'provider_code' => $providerCode,
                'win_points' => win_points_response_payload($mysqli, $sessionUserId, [
                    'spent' => $requiredPoints,
                ]),
            ], 200, static function () use ($mysqli, $paidOrder, $providerMessage, $shortageResult): void {
                notify_provider_inventory_shortage($mysqli, $paidOrder, 'Canje por premios', '', '', $providerMessage, $shortageResult['lock'] ?? []);
            });
        }

        $inventoryShortage = recharge_availability_message_indicates_inventory_shortage($providerMessage);
        if ($inventoryShortage) {
            try {
                $shortageResult = mark_order_inventory_shortage_review(
                    $mysqli,
                    $order,
                    'pagado',
                    $verifiedReference,
                    $phone,
                    $providerReference,
                    $providerMessage,
                    $providerPayload,
                    $providerOrderId
                );
            } catch (Throwable $e) {
                json_error($e->getMessage(), 500);
            }

            json_response([
                'ok' => true,
                'message' => 'No hay suficientes recargas disponibles. El pedido sigue verificado y el paquete afectado fue restringido por prevención.',
                'order_id' => $orderId,
                'estado' => 'pagado',
                'provider_flow' => 'inventory_shortage',
                'provider_status' => 'inventory_shortage',
                'provider_reference' => $providerReference,
                'provider_message' => $providerMessage,
            ]);
        }

        $manualProcessing = !empty($providerResult['manual_processing']);
        $acceptedLike = !empty($providerResult['accepted'])
            || ($manualProcessing && provider_message_indicates_pending_lookup($providerMessage));
        $trackingFollowUp = provider_message_indicates_transport_timeout($providerMessage);

        if ($trackingFollowUp || $acceptedLike) {
            $paidStatus = 'pagado';
            $providerHistoryJson = append_provider_history(
                $updatedOrder['recargas_api_historial_json'] ?? null,
                build_provider_history_entry(
                    $trackingFollowUp ? 'points_purchase_timeout' : 'points_purchase_accepted',
                    $providerState !== '' ? $providerState : 'pending_confirmation',
                    $paidStatus,
                    $providerMessage,
                    $providerReference,
                    $providerOrderId,
                    $providerCode
                )
            );
            $paidStmt = $mysqli->prepare("UPDATE pedidos SET ff_api_referencia = ?, ff_api_mensaje = ?, ff_api_payload = ?, recargas_api_pedido_id = ?, recargas_api_estado = ?, recargas_api_codigo_entregado = ?, recargas_api_ultimo_check = NOW(), recargas_api_historial_json = ?, estado = ? WHERE id = ? AND estado = 'pendiente'");
            if (!$paidStmt) {
                json_error('No se pudo dejar el canje en seguimiento.', 500);
            }
            $trackedState = $providerState !== '' ? $providerState : 'pending_confirmation';
            $paidStmt->bind_param('ssssssssi', $providerReference, $providerMessage, $providerPayload, $providerOrderId, $trackedState, $providerCode, $providerHistoryJson, $paidStatus, $orderId);
            if (!$paidStmt->execute()) {
                $paidStmt->close();
                json_error('No se pudo actualizar el pedido tras aceptar el canje.', 500);
            }
            $paidStmt->close();

            $paidOrder = fetch_order_by_id($mysqli, $orderId) ?: $updatedOrder;
            recharge_notifications_emit_for_order($mysqli, $paidOrder);

            if ($acceptedLike) {
                $autoSyncAttempts = $manualProcessing ? 5 : 3;
                $autoSyncDelaySeconds = $manualProcessing ? 4 : 2;
                $autoSyncResult = try_auto_sync_provider_order($mysqli, $paidOrder, $autoSyncAttempts, $autoSyncDelaySeconds);
                if (is_array($autoSyncResult)) {
                    $providerMessage = trim((string) ($autoSyncResult['provider_message'] ?? $providerMessage));
                    $providerReference = trim((string) ($autoSyncResult['provider_reference'] ?? $providerReference));
                    $providerCode = trim((string) ($autoSyncResult['provider_code'] ?? $providerCode));
                    $resolvedStatus = trim((string) ($autoSyncResult['local_status'] ?? ''));

                    if ($resolvedStatus === 'enviado') {
                        json_response([
                            'ok' => true,
                            'message' => 'Canje realizado y recarga procesada correctamente para ' . order_purchase_quantity_text(order_purchase_quantity($paidOrder)) . '.',
                            'order_id' => $orderId,
                            'estado' => 'enviado',
                            'verified' => true,
                            'payment_mode' => 'points',
                            'provider_flow' => 'completed',
                            'provider_reference' => $providerReference,
                            'provider_message' => $providerMessage,
                            'provider_code' => $providerCode,
                            'win_points' => win_points_response_payload($mysqli, $sessionUserId, [
                                'spent' => $requiredPoints,
                            ]),
                        ]);
                    }

                    if ($resolvedStatus === 'cancelado') {
                        $cancelStmt = $mysqli->prepare("UPDATE pedidos SET estado = 'cancelado' WHERE id = ? AND estado = 'pagado'");
                        if ($cancelStmt) {
                            $cancelStmt->bind_param('i', $orderId);
                            $cancelStmt->execute();
                            $cancelStmt->close();
                        }
                        win_points_handle_order_status_change($mysqli, $orderId, 'cancelado');
                        json_response([
                            'ok' => true,
                            'message' => 'El proveedor rechazó el canje. Tus premios fueron reembolsados.',
                            'order_id' => $orderId,
                            'estado' => 'cancelado',
                            'verified' => true,
                            'payment_mode' => 'points',
                            'provider_flow' => 'cancelled',
                            'reasons' => [$providerMessage],
                            'provider_reference' => $providerReference,
                            'provider_message' => $providerMessage,
                            'win_points' => win_points_response_payload($mysqli, $sessionUserId, [
                                'spent' => 0,
                            ]),
                        ]);
                    }
                }
            }

            json_response([
                'ok' => true,
                'message' => $trackingFollowUp
                    ? 'Canje aceptado. La compra quedó en seguimiento automático mientras el proveedor confirma la entrega.'
                    : 'Canje aceptado. La recarga quedó en proceso para seguimiento.',
                'order_id' => $orderId,
                'estado' => 'pagado',
                'verified' => true,
                'payment_mode' => 'points',
                'provider_flow' => $trackingFollowUp ? 'tracking' : 'accepted',
                'reasons' => [$providerMessage],
                'provider_reference' => $providerReference,
                'provider_message' => $providerMessage,
                'provider_code' => $providerCode,
                'win_points' => win_points_response_payload($mysqli, $sessionUserId, [
                    'spent' => $requiredPoints,
                ]),
            ]);
        }

        $cancelStatus = 'cancelado';
        $failedHistoryJson = append_provider_history(
            $updatedOrder['recargas_api_historial_json'] ?? null,
            build_provider_history_entry(
                'points_purchase_failed',
                $providerState,
                $cancelStatus,
                $providerMessage,
                $providerReference,
                $providerOrderId,
                $providerCode
            )
        );
        $cancelStmt = $mysqli->prepare("UPDATE pedidos SET ff_api_referencia = ?, ff_api_mensaje = ?, ff_api_payload = ?, recargas_api_pedido_id = ?, recargas_api_estado = ?, recargas_api_codigo_entregado = ?, recargas_api_ultimo_check = NOW(), recargas_api_historial_json = ?, estado = ? WHERE id = ? AND estado = 'pendiente'");
        if (!$cancelStmt) {
            json_error('No se pudo cerrar el canje fallido.', 500);
        }
        $cancelStmt->bind_param('ssssssssi', $providerReference, $providerMessage, $providerPayload, $providerOrderId, $providerState, $providerCode, $failedHistoryJson, $cancelStatus, $orderId);
        if (!$cancelStmt->execute()) {
            $cancelStmt->close();
            json_error('No se pudo cancelar el pedido tras fallar el canje.', 500);
        }
        $cancelStmt->close();

        win_points_handle_order_status_change($mysqli, $orderId, 'cancelado');
        json_response([
            'ok' => true,
            'message' => 'No se pudo procesar el canje con el proveedor. Tus premios fueron reembolsados.',
            'order_id' => $orderId,
            'estado' => 'cancelado',
            'verified' => true,
            'payment_mode' => 'points',
            'provider_flow' => 'cancelled',
            'reasons' => [$providerMessage],
            'provider_reference' => $providerReference,
            'provider_message' => $providerMessage,
            'win_points' => win_points_response_payload($mysqli, $sessionUserId, [
                'spent' => 0,
            ]),
        ]);
    }

    $binancePagonorteMode = $paymentMode === 'binance_pagonorte';

    if ($binancePagonorteMode) {
        if (!binance_pagonorte_payment_enabled()) {
            json_error('Binance no está activo en esta tienda.', 409);
        }
        if (!binance_pagonorte_is_configured()) {
            json_error('Falta configurar el token de Binance para usar este método.', 409);
        }
    }

    if (!$binancePagonorteMode && $paymentMethodId <= 0) {
        json_error('Debes seleccionar un método de pago.');
    }
    if ($referenceNumberRaw === '') {
        json_error('Debes ingresar el número de referencia.');
    }
    if ($phoneRaw === '') {
        json_error('Debes ingresar un número de teléfono.');
    }
    if (preg_match('/^\d+$/', $referenceNumberRaw) !== 1) {
        json_error('El número de referencia solo puede contener dígitos.');
    }

    if ($binancePagonorteMode) {
        $usdtCurrency = currency_find_by_code('USDT');
        $method = [
            'id' => 0,
            'nombre' => 'Binance',
            'moneda_nombre' => (string) ($usdtCurrency['nombre'] ?? 'Tether USD'),
            'moneda_clave' => 'USDT',
            'referencia_digitos' => binance_pagonorte_reference_digits(),
            'descuento_porcentaje' => payment_methods_normalize_discount_percentage(store_config_get('binance_pagonorte_descuento', '0')),
        ];
    } else {
        $method = fetch_active_payment_method($mysqli, $paymentMethodId);
        if (!$method) {
            json_error('El método de pago seleccionado no está disponible.');
        }
    }

    $orderCurrencyCode = normalize_currency_code((string) ($order['moneda'] ?? ''));
    $methodCurrencyCode = normalize_currency_code((string) ($method['moneda_clave'] ?? ''));
    $orderSupportsBankApi = order_currency_uses_bank_api($orderCurrencyCode);
    $methodSupportsBankApi = order_currency_uses_bank_api($methodCurrencyCode);
    $currencyMatchesOrder = strcasecmp($methodCurrencyCode, $orderCurrencyCode) === 0;

    if ($binancePagonorteMode && $orderCurrencyCode !== 'USDT') {
        json_error('El pedido debe estar en USDT para usar Binance.', 409);
    }

    if ($orderSupportsBankApi && !$currencyMatchesOrder) {
        json_error('El método de pago no corresponde a la moneda del pedido.');
    }

    $referenceDigitsLimit = max(0, (int) ($method['referencia_digitos'] ?? 0));
    if ($binancePagonorteMode && $referenceDigitsLimit > 0 && strlen($referenceNumberRaw) < $referenceDigitsLimit) {
        json_error('Debes escribir la referencia completa o al menos los últimos ' . $referenceDigitsLimit . ' dígitos.');
    }
    if (!$binancePagonorteMode && $referenceDigitsLimit > 0 && strlen($referenceNumberRaw) !== $referenceDigitsLimit) {
        json_error('La referencia debe contener exactamente ' . $referenceDigitsLimit . ' dígitos.');
    }

    $discountSync = persist_order_payment_selection($mysqli, $order, $binancePagonorteMode ? 'binance_pagonorte' : 'money', $binancePagonorteMode ? null : $method);
    $order = is_array($discountSync['order'] ?? null) ? $discountSync['order'] : $order;

    $phone = substr($phoneRaw, 0, 40);
    $referenceNumber = substr($referenceNumberRaw, 0, 120);
    $referenceMatchDigits = $binancePagonorteMode
        ? ($referenceDigitsLimit > 0 ? $referenceDigitsLimit : strlen($referenceNumber))
        : $referenceDigitsLimit;
    $bankFlowRequested = $orderSupportsBankApi || $methodSupportsBankApi;
    $usesBankValidation = $orderSupportsBankApi && $methodSupportsBankApi && $currencyMatchesOrder;
    $usesBinancePagonorteValidation = $binancePagonorteMode && $currencyMatchesOrder && $methodCurrencyCode === 'USDT';
    $paymentDifferenceEnabled = payment_difference_feature_enabled() && $usesBankValidation;
    $overpaymentAmount = 0.0;

    $bankConfig = [
        'ff_bank_api_base_url' => store_config_get('ff_bank_api_base_url', 'https://pagonorte.net'),
        'ff_bank_posicion' => store_config_get('ff_bank_posicion', ''),
        'ff_bank_token' => store_config_get('ff_bank_token', ''),
        'ff_bank_clave' => store_config_get('ff_bank_clave', ''),
    ];
    $binancePagonorteConfig = [
        'binance_pagonorte_token' => store_config_get('binance_pagonorte_token', ''),
    ];
    $bankMovements = [];

    if ($bankFlowRequested || $usesBinancePagonorteValidation) {
        try {
            $bankMovements = $usesBinancePagonorteValidation
                ? fetch_and_sync_binance_pagonorte_movements($mysqli, $binancePagonorteConfig)
                : fetch_and_sync_bank_movements($mysqli, $bankConfig);
        } catch (Throwable $e) {
            json_error('Su Pago está en proceso, Espere 1 min y vuelva a intentar', 502);
        }

        if ($bankFlowRequested && !$usesBankValidation && !$usesBinancePagonorteValidation) {
            error_log('TVG bank validation skipped for order #' . $orderId
                . ' order_currency=' . ($order['moneda'] ?? '')
                . ' normalized_order_currency=' . $orderCurrencyCode
                . ' method_currency=' . (($method['moneda_clave'] ?? ''))
                . ' normalized_method_currency=' . $methodCurrencyCode
                . ' bank_flow_requested=' . ($bankFlowRequested ? '1' : '0'));
        }
    }

    $preselectedMatchingMovement = null;
    if ($usesBankValidation || $usesBinancePagonorteValidation) {
        $preselectedMatchingMovement = $paymentDifferenceEnabled
            ? find_bank_movement_by_reference(
                $mysqli,
                $bankMovements,
                $referenceNumber,
                $referenceMatchDigits,
                $orderId
            )
            : find_matching_bank_movement(
                $mysqli,
                $bankMovements,
                $referenceNumber,
                (float) ($order['precio'] ?? 0),
                $referenceMatchDigits,
                $orderId
            );
    }

    $referenceConflict = null;
    if ($usesBankValidation || $usesBinancePagonorteValidation) {
        if ($preselectedMatchingMovement !== null) {
            $referenceConflict = find_reference_reuse_conflict(
                $mysqli,
                (string) ($preselectedMatchingMovement['referencia'] ?? $referenceNumber),
                0,
                $orderId
            );
        }
    } else {
        $referenceConflict = find_reference_reuse_conflict($mysqli, $referenceNumber, $referenceDigitsLimit, $orderId);
    }

    if ($referenceConflict !== null) {
        json_error(reference_reuse_conflict_message($referenceConflict), 409);
    }

    $stmt = $mysqli->prepare('UPDATE pedidos SET numero_referencia = ?, telefono_contacto = ? WHERE id = ? AND estado = ?');
    if (!$stmt) {
        json_error('No se pudo actualizar el pedido.', 500);
    }
    $expectedStatus = 'pendiente';
    $stmt->bind_param('ssis', $referenceNumber, $phone, $orderId, $expectedStatus);
    if (!$stmt->execute()) {
        $stmt->close();
        json_error('No se pudieron guardar los datos del pago.', 500);
    }
    $stmt->close();
    update_user_last_purchase_details(
        $mysqli,
        (int) ($order['cliente_usuario_id'] ?? 0),
        should_store_last_purchase_identifier() ? (string) ($order['user_identifier'] ?? '') : null,
        $phone
    );

    $updatedOrder = fetch_order_by_id($mysqli, $orderId) ?: $order;
    $gameId = (int) ($updatedOrder['juego_id'] ?? 0);
    $adminEmail = resolve_admin_email($mysqli);
    $paymentMethodName = (string) ($method['nombre'] ?? 'Método de pago');
    $brandingImages = email_branding_embedded_images();
    $usesCatalogApi = order_uses_catalog_api_provider($updatedOrder);

    if ($usesBankValidation || $usesBinancePagonorteValidation) {
        $matchingMovement = $preselectedMatchingMovement;

        if ($matchingMovement === null) {
            try {
                $retryResult = $paymentDifferenceEnabled
                    ? find_bank_movement_by_reference_with_retry(
                        $mysqli,
                        $bankConfig,
                        $referenceNumber,
                        $referenceMatchDigits,
                        $orderId,
                        3,
                        5,
                        $bankMovements
                    )
                    : ($usesBinancePagonorteValidation
                        ? find_matching_binance_pagonorte_movement_with_retry(
                            $mysqli,
                            $binancePagonorteConfig,
                            $referenceNumber,
                            (float) ($updatedOrder['precio'] ?? 0),
                            $referenceMatchDigits,
                            $orderId,
                            3,
                            5,
                            $bankMovements
                        )
                        : find_matching_bank_movement_with_retry(
                            $mysqli,
                            $bankConfig,
                            $referenceNumber,
                            (float) ($updatedOrder['precio'] ?? 0),
                            $referenceMatchDigits,
                            $orderId,
                            3,
                            5,
                            $bankMovements
                        ));
                $matchingMovement = $retryResult['match'];
                $bankMovements = $retryResult['movements'];
                error_log('TVG bank validation attempts for order #' . $orderId . ': ' . (int) ($retryResult['attempts'] ?? 1));
            } catch (Throwable $e) {
                json_error('Su Pago está en proceso, Espere 1 min y vuelva a intentar', 502);
            }
        }

        if ($matchingMovement === null) {
            $expiredReference = find_expired_bank_movement_by_reference($bankMovements, $referenceNumber, $referenceMatchDigits);
            if ($expiredReference !== null) {
                $expiredDayLabel = format_bank_movement_business_day((string) ($expiredReference['movement_day'] ?? ''));
                $expiredReasons = [];
                if ($expiredDayLabel !== '') {
                    $expiredReasons[] = 'La referencia corresponde a un pago registrado el ' . $expiredDayLabel . ' y ya no puede usarse hoy en la web.';
                } else {
                    $expiredReasons[] = 'La referencia corresponde a un pago de otro día y ya no puede usarse hoy en la web.';
                }
                $expiredReasons[] = 'Los pagos reportados en esta ventana solo son válidos el mismo día en que se realizan.';
                $expiredReasons[] = 'Debes comunicarte con el administrador por WhatsApp para revisar tu caso.';

                json_response([
                    'ok' => true,
                    'message' => 'Esta referencia ya caducó. Los pagos solo son válidos el mismo día en que se realizan. Comunícate con el administrador por WhatsApp.',
                    'order_id' => $orderId,
                    'estado' => 'pendiente',
                    'verified' => false,
                    'bank_checked' => true,
                    'reasons' => $expiredReasons,
                    'reference_match' => true,
                    'amount_match' => false,
                    'failure_type' => 'expired_reference',
                ]);
            }
        }

        if ($matchingMovement !== null) {
            $mysqli = ensure_mysqli_connection($mysqli);
            $verifiedReference = (string) ($matchingMovement['referencia'] ?? $referenceNumber);
            link_movement_to_order($mysqli, $verifiedReference, $orderId);

            if ($paymentDifferenceEnabled) {
                $totalPaidAmount = sum_linked_movement_amount_for_order($mysqli, $orderId);
                $expectedAmount = payment_difference_normalize_amount($updatedOrder['precio'] ?? 0);
                $overpaymentAmount = payment_difference_normalize_amount($totalPaidAmount - $expectedAmount);

                if ($totalPaidAmount + 0.0001 < $expectedAmount) {
                    update_order_payment_deadline($mysqli, $orderId, 1800);
                    $pendingDifferenceOrder = fetch_order_by_id($mysqli, $orderId) ?: $updatedOrder;
                    json_response([
                        'ok' => true,
                        'message' => 'El monto transferido fue menor al esperado. Tu recarga sigue pendiente hasta completar el saldo faltante.',
                        'order_id' => $orderId,
                        'estado' => 'pendiente',
                        'verified' => false,
                        'bank_checked' => true,
                        'failure_type' => 'amount_shortfall',
                        'reasons' => ['El monto acumulado recibido todavía es menor al total esperado del pedido.'],
                        'remaining_seconds' => max(0, order_expiration_timestamp($pendingDifferenceOrder) - time()),
                        'payment_difference' => [
                            'status' => 'underpaid',
                            'expected_total' => $expectedAmount,
                            'paid_total' => $totalPaidAmount,
                            'remaining_amount' => payment_difference_normalize_amount($expectedAmount - $totalPaidAmount),
                            'currency' => strtoupper(trim((string) ($updatedOrder['moneda'] ?? 'VES'))) !== '' ? strtoupper(trim((string) ($updatedOrder['moneda'] ?? 'VES'))) : 'VES',
                            'can_complete_same_order' => true,
                        ],
                    ]);
                }
            }

            if (order_is_account_sale($updatedOrder)) {
                try {
                    $sentOrder = mark_account_sale_as_sent($mysqli, $updatedOrder, 'pendiente', $verifiedReference, $phone);
                } catch (Throwable $e) {
                    json_error($e->getMessage(), 500);
                }

                win_points_handle_order_status_change($mysqli, $orderId, 'enviado');
                recharge_notifications_emit_for_order($mysqli, $sentOrder);
                json_response(append_account_sale_response(append_payment_difference_response([
                    'ok' => true,
                    'message' => 'Pago verificado y cuenta entregada correctamente para ' . order_purchase_quantity_text(order_purchase_quantity($sentOrder)) . '.',
                    'order_id' => $orderId,
                    'estado' => 'enviado',
                    'verified' => true,
                    'provider_flow' => 'account_sale',
                    'provider_status' => 'account_sale',
                ], $sentOrder, $overpaymentAmount), $sentOrder), 200, static function () use ($mysqli, $sentOrder, $paymentMethodName, $verifiedReference, $phone): void {
                    register_influencer_coupon_sale($mysqli, $sentOrder);
                    notify_account_sale_delivery($mysqli, $sentOrder, $paymentMethodName, $verifiedReference, $phone);
                });
            }

            if (order_uses_api_discord($updatedOrder)) {
                $dispatchResult = execute_api_discord_order_dispatch($updatedOrder);
                if (!persist_api_discord_dispatch_result($mysqli, $updatedOrder, $dispatchResult, 'pendiente', 'pagado', $verifiedReference, $phone)) {
                    json_error('No se pudo guardar el resultado del envío Discord.', 500);
                }

                $paidOrder = fetch_order_by_id($mysqli, $orderId) ?: $updatedOrder;
                recharge_notifications_emit_for_order($mysqli, $paidOrder);
                json_response(append_api_discord_response(append_payment_difference_response([
                    'ok' => true,
                    'message' => trim((string) ($dispatchResult['provider_message'] ?? 'El pago fue verificado y la orden quedó registrada para Discord.')),
                    'order_id' => $orderId,
                    'estado' => trim((string) ($paidOrder['estado'] ?? 'pagado')),
                    'verified' => true,
                    'provider_flow' => trim((string) ($dispatchResult['provider_flow'] ?? 'manual_review')),
                    'provider_status' => trim((string) ($dispatchResult['provider_status'] ?? 'review')),
                    'provider_reference' => trim((string) ($dispatchResult['provider_reference'] ?? '')),
                    'provider_message' => trim((string) ($dispatchResult['provider_message'] ?? '')),
                ], $paidOrder, $overpaymentAmount), $paidOrder), 200, static function () use ($mysqli, $paidOrder, $paymentMethodName, $verifiedReference, $phone, $dispatchResult): void {
                    register_influencer_coupon_sale($mysqli, $paidOrder);
                    if (!empty($dispatchResult['sent'])) {
                        notify_catalog_purchase_pending($mysqli, $paidOrder, $paymentMethodName, $verifiedReference, $phone, trim((string) ($dispatchResult['provider_reference'] ?? '')), trim((string) ($dispatchResult['provider_message'] ?? '')));
                        return;
                    }

                    notify_bank_payment_verified_paid($mysqli, $paidOrder, $paymentMethodName, $verifiedReference, $phone);
                });
            }

            if (!$usesCatalogApi) {
                $paidStatus = 'pagado';
                $paidStmt = $mysqli->prepare("UPDATE pedidos SET numero_referencia = ?, telefono_contacto = ?, estado = ? WHERE id = ? AND estado = 'pendiente'");
                if (!$paidStmt) {
                    json_error('No se pudo confirmar el pago automáticamente.', 500);
                }
                $paidStmt->bind_param('sssi', $verifiedReference, $phone, $paidStatus, $orderId);
                if (!$paidStmt->execute()) {
                    $paidStmt->close();
                    json_error('No se pudo actualizar el pedido tras validar el pago.', 500);
                }
                $paidStmt->close();

                $paidOrder = fetch_order_by_id($mysqli, $orderId) ?: $updatedOrder;
                recharge_notifications_emit_for_order($mysqli, $paidOrder);
                json_response(append_payment_difference_response([
                    'ok' => true,
                    'message' => 'Pago verificado automáticamente. Tu pedido quedó en estado verificado.',
                    'order_id' => $orderId,
                    'estado' => 'pagado',
                    'verified' => true,
                ], $paidOrder, $overpaymentAmount), 200, static function () use ($mysqli, $paidOrder, $paymentMethodName, $verifiedReference, $phone): void {
                    register_influencer_coupon_sale($mysqli, $paidOrder);
                    notify_bank_payment_verified_paid($mysqli, $paidOrder, $paymentMethodName, $verifiedReference, $phone);
                });
            }

            $packageApiId = (int) ($updatedOrder['paquete_api'] ?? 0);

            $orderPlayerFields = order_player_fields_from_json((string) ($updatedOrder['player_fields_json'] ?? ''));

            try {
                $freeFireResult = execute_catalog_api_purchase($packageApiId, (string) ($updatedOrder['user_identifier'] ?? ''), $orderPlayerFields, order_purchase_quantity($updatedOrder));
            } catch (Throwable $e) {
                $freeFireResult = [
                    'success' => false,
                    'accepted' => false,
                    'message' => $e->getMessage(),
                    'reference' => '',
                    'payload' => ['exception' => $e->getMessage()],
                ];
            }

            $mysqli = ensure_mysqli_connection($mysqli);

            $providerPayloadData = (array) ($freeFireResult['payload'] ?? []);
            $providerReference = (string) ($freeFireResult['reference'] ?? '');
            $providerMessage = provider_order_status_message($providerPayloadData, (string) ($freeFireResult['message'] ?? 'No se recibió mensaje del proveedor.'));
            $providerPayload = json_encode($providerPayloadData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $providerOrderId = recargas_api_extract_provider_order_id($providerPayloadData);
            $providerState = strtolower(trim((string) (($providerPayloadData['estado'] ?? ''))));
            $providerCode = provider_delivered_code_text($providerPayloadData);

            if (!empty($freeFireResult['success'])) {
                $verifiedStatus = 'enviado';
                $providerHistoryJson = append_provider_history(
                    $updatedOrder['recargas_api_historial_json'] ?? null,
                    build_provider_history_entry(
                        'purchase',
                        $providerState,
                        $verifiedStatus,
                        $providerMessage,
                        $providerReference,
                        $providerOrderId,
                        $providerCode
                    )
                );
                $verifyStmt = $mysqli->prepare("UPDATE pedidos SET numero_referencia = ?, telefono_contacto = ?, ff_api_referencia = ?, ff_api_mensaje = ?, ff_api_payload = ?, recargas_api_pedido_id = ?, recargas_api_estado = ?, recargas_api_codigo_entregado = ?, recargas_api_ultimo_check = NOW(), recargas_api_historial_json = ?, estado = ? WHERE id = ? AND estado = 'pendiente'");
                if (!$verifyStmt) {
                    json_error('No se pudo confirmar la recarga automáticamente.', 500);
                }
                $verifyStmt->bind_param('ssssssssssi', $verifiedReference, $phone, $providerReference, $providerMessage, $providerPayload, $providerOrderId, $providerState, $providerCode, $providerHistoryJson, $verifiedStatus, $orderId);
                if (!$verifyStmt->execute()) {
                    $verifyStmt->close();
                    json_error('No se pudo actualizar el pedido tras procesar la recarga.', 500);
                }
                $verifyStmt->close();

                $verifiedOrder = fetch_order_by_id($mysqli, $orderId) ?: $updatedOrder;
                win_points_handle_order_status_change($mysqli, $orderId, 'enviado');
                recharge_notifications_emit_for_order($mysqli, $verifiedOrder);
                json_response(append_payment_difference_response([
                    'ok' => true,
                    'message' => 'Pago verificado y recarga procesada correctamente para ' . order_purchase_quantity_text(order_purchase_quantity($verifiedOrder)) . '.',
                    'order_id' => $orderId,
                    'estado' => 'enviado',
                    'verified' => true,
                    'provider_flow' => 'completed',
                    'provider_reference' => $providerReference,
                    'provider_message' => $providerMessage,
                    'provider_code' => $providerCode,
                ], $verifiedOrder, $overpaymentAmount), 200, static function () use ($mysqli, $verifiedOrder, $paymentMethodName, $verifiedReference, $phone, $providerReference, $providerMessage): void {
                    ensure_provider_webhook_registration();
                    register_influencer_coupon_sale($mysqli, $verifiedOrder);
                    notify_free_fire_recharge_success($mysqli, $verifiedOrder, $paymentMethodName, $verifiedReference, $phone, $providerReference, $providerMessage);
                });
            }

            $inventoryShortage = recharge_availability_message_indicates_inventory_shortage($providerMessage);
            if ($inventoryShortage) {
                try {
                    $shortageResult = mark_order_inventory_shortage_review(
                        $mysqli,
                        $updatedOrder,
                        'pendiente',
                        $verifiedReference,
                        $phone,
                        $providerReference,
                        $providerMessage,
                        $providerPayload,
                        $providerOrderId,
                        $providerCode
                    );
                } catch (Throwable $e) {
                    json_error($e->getMessage(), 500);
                }

                $paidOrder = $shortageResult['order'];
                recharge_notifications_emit_for_order($mysqli, $paidOrder);
                json_response(append_payment_difference_response([
                    'ok' => true,
                    'message' => 'Por los momentos no hay suficientes recargas disponibles. Tu pedido quedó verificado y enviaremos la recarga en unos momentos.',
                    'order_id' => $orderId,
                    'estado' => 'pagado',
                    'verified' => true,
                    'provider_flow' => 'inventory_shortage',
                    'provider_status' => 'inventory_shortage',
                    'reasons' => [$providerMessage],
                    'provider_reference' => $providerReference,
                    'provider_message' => $providerMessage,
                    'provider_code' => $providerCode,
                ], $paidOrder, $overpaymentAmount), 200, static function () use ($mysqli, $paidOrder, $paymentMethodName, $verifiedReference, $phone, $providerMessage, $shortageResult): void {
                    register_influencer_coupon_sale($mysqli, $paidOrder);
                    notify_provider_inventory_shortage($mysqli, $paidOrder, $paymentMethodName, $verifiedReference, $phone, $providerMessage, $shortageResult['lock'] ?? []);
                });
            }

            $manualProcessing = !empty($freeFireResult['manual_processing']);
            $acceptedLike = !empty($freeFireResult['accepted'])
                || ($manualProcessing && provider_message_indicates_pending_lookup($providerMessage));
            $trackingFollowUp = provider_message_indicates_transport_timeout($providerMessage);

            if ($trackingFollowUp) {
                $paidStatus = 'pagado';
                $providerTrackedState = $providerState !== '' ? $providerState : 'pending_confirmation';
                $providerHistoryJson = append_provider_history(
                    $updatedOrder['recargas_api_historial_json'] ?? null,
                    build_provider_history_entry(
                        'purchase_timeout',
                        $providerTrackedState,
                        $paidStatus,
                        $providerMessage,
                        $providerReference,
                        $providerOrderId,
                        $providerCode
                    )
                );
                $paidStmt = $mysqli->prepare("UPDATE pedidos SET numero_referencia = ?, telefono_contacto = ?, ff_api_referencia = ?, ff_api_mensaje = ?, ff_api_payload = ?, recargas_api_pedido_id = ?, recargas_api_estado = ?, recargas_api_codigo_entregado = ?, recargas_api_ultimo_check = NOW(), recargas_api_historial_json = ?, estado = ? WHERE id = ? AND estado = 'pendiente'");
                if (!$paidStmt) {
                    json_error('No se pudo actualizar el pedido tras validar el pago.', 500);
                }
                $paidStmt->bind_param('ssssssssssi', $verifiedReference, $phone, $providerReference, $providerMessage, $providerPayload, $providerOrderId, $providerTrackedState, $providerCode, $providerHistoryJson, $paidStatus, $orderId);
                if (!$paidStmt->execute()) {
                    $paidStmt->close();
                    json_error('No se pudo marcar el pedido como pagado.', 500);
                }
                $paidStmt->close();

                $paidOrder = fetch_order_by_id($mysqli, $orderId) ?: $updatedOrder;
                recharge_notifications_emit_for_order($mysqli, $paidOrder);
                json_response(append_payment_difference_response([
                    'ok' => true,
                    'message' => 'El pago fue verificado. La compra quedo en seguimiento automatico mientras confirmamos la respuesta del proveedor.',
                    'order_id' => $orderId,
                    'estado' => 'pagado',
                    'verified' => true,
                    'provider_flow' => 'tracking',
                    'reasons' => [$providerMessage],
                    'provider_reference' => $providerReference,
                    'provider_message' => $providerMessage,
                ], $paidOrder, $overpaymentAmount), 200, static function () use ($mysqli, $paidOrder, $paymentMethodName, $verifiedReference, $phone, $providerReference, $providerMessage, $orderId): void {
                    ensure_provider_webhook_registration();
                    register_influencer_coupon_sale($mysqli, $paidOrder);
                    notify_catalog_purchase_pending($mysqli, $paidOrder, $paymentMethodName, $verifiedReference, $phone, $providerReference, $providerMessage);
                    continue_provider_follow_up_in_background($mysqli, (int) ($paidOrder['id'] ?? $orderId), 8, 8);
                });
            }

            if ($acceptedLike) {
                $paidStatus = 'pagado';
                $providerHistoryJson = append_provider_history(
                    $updatedOrder['recargas_api_historial_json'] ?? null,
                    build_provider_history_entry(
                        'purchase',
                        $providerState,
                        $paidStatus,
                        $providerMessage,
                        $providerReference,
                        $providerOrderId,
                        $providerCode
                    )
                );
                $paidStmt = $mysqli->prepare("UPDATE pedidos SET numero_referencia = ?, telefono_contacto = ?, ff_api_referencia = ?, ff_api_mensaje = ?, ff_api_payload = ?, recargas_api_pedido_id = ?, recargas_api_estado = ?, recargas_api_codigo_entregado = ?, recargas_api_ultimo_check = NOW(), recargas_api_historial_json = ?, estado = ? WHERE id = ? AND estado = 'pendiente'");
                if (!$paidStmt) {
                    json_error('No se pudo actualizar el pedido tras validar el pago.', 500);
                }
                $paidStmt->bind_param('ssssssssssi', $verifiedReference, $phone, $providerReference, $providerMessage, $providerPayload, $providerOrderId, $providerState, $providerCode, $providerHistoryJson, $paidStatus, $orderId);
                if (!$paidStmt->execute()) {
                    $paidStmt->close();
                    json_error('No se pudo marcar el pedido como pagado.', 500);
                }
                $paidStmt->close();

                $paidOrder = fetch_order_by_id($mysqli, $orderId) ?: $updatedOrder;
                recharge_notifications_emit_for_order($mysqli, $paidOrder);

                $autoSyncAttempts = $manualProcessing ? 5 : 3;
                $autoSyncDelaySeconds = $manualProcessing ? 4 : 2;
                $autoSyncResult = try_auto_sync_provider_order($mysqli, $paidOrder, $autoSyncAttempts, $autoSyncDelaySeconds);
                if (is_array($autoSyncResult)) {
                    $paidOrder = is_array($autoSyncResult['order'] ?? null) ? $autoSyncResult['order'] : $paidOrder;
                    $providerMessage = trim((string) ($autoSyncResult['provider_message'] ?? $providerMessage));
                    $providerReference = trim((string) ($autoSyncResult['provider_reference'] ?? $providerReference));
                    $providerCode = trim((string) ($autoSyncResult['provider_code'] ?? $providerCode));
                    $resolvedStatus = trim((string) ($autoSyncResult['local_status'] ?? ''));

                    if ($resolvedStatus === 'enviado') {
                        json_response(append_payment_difference_response([
                            'ok' => true,
                            'message' => 'Pago verificado y recarga procesada correctamente para ' . order_purchase_quantity_text(order_purchase_quantity($paidOrder)) . '.',
                            'order_id' => $orderId,
                            'estado' => 'enviado',
                            'verified' => true,
                            'provider_flow' => 'completed',
                            'provider_reference' => $providerReference,
                            'provider_message' => $providerMessage,
                            'provider_code' => $providerCode,
                        ], $paidOrder, $overpaymentAmount));
                    }

                    if ($resolvedStatus === 'cancelado') {
                        json_response([
                            'ok' => true,
                            'message' => 'El pago fue verificado, pero el proveedor canceló la compra. Nuestro equipo revisará tu pedido.',
                            'order_id' => $orderId,
                            'estado' => 'cancelado',
                            'verified' => true,
                            'provider_flow' => 'cancelled',
                            'reasons' => [$providerMessage],
                            'provider_reference' => $providerReference,
                            'provider_message' => $providerMessage,
                        ]);
                    }
                }

                json_response(append_payment_difference_response([
                    'ok' => true,
                    'message' => 'El pago fue verificado y la compra fue aceptada por la API. Quedó en proceso para seguimiento.',
                    'order_id' => $orderId,
                    'estado' => 'pagado',
                    'verified' => true,
                    'provider_flow' => 'accepted',
                    'reasons' => [$providerMessage],
                    'provider_reference' => $providerReference,
                    'provider_message' => $providerMessage,
                    'provider_code' => $providerCode,
                ], $paidOrder, $overpaymentAmount), 200, static function () use ($mysqli, $paidOrder, $paymentMethodName, $verifiedReference, $phone, $providerReference, $providerMessage): void {
                    ensure_provider_webhook_registration();
                    register_influencer_coupon_sale($mysqli, $paidOrder);
                    notify_catalog_purchase_pending($mysqli, $paidOrder, $paymentMethodName, $verifiedReference, $phone, $providerReference, $providerMessage);
                    continue_provider_follow_up_in_background($mysqli, (int) ($paidOrder['id'] ?? 0), 8, 8);
                });
            }

            $paidStatus = 'pagado';
            $providerHistoryJson = append_provider_history(
                $updatedOrder['recargas_api_historial_json'] ?? null,
                build_provider_history_entry(
                    'purchase',
                    $providerState,
                    $paidStatus,
                    $providerMessage,
                    $providerReference,
                    $providerOrderId,
                    $providerCode
                )
            );
            $paidStmt = $mysqli->prepare("UPDATE pedidos SET numero_referencia = ?, telefono_contacto = ?, ff_api_referencia = ?, ff_api_mensaje = ?, ff_api_payload = ?, recargas_api_codigo_entregado = ?, recargas_api_historial_json = ?, estado = ? WHERE id = ? AND estado = 'pendiente'");
            if (!$paidStmt) {
                json_error('No se pudo actualizar el pedido tras validar el pago.', 500);
            }
            $paidStmt->bind_param('ssssssssi', $verifiedReference, $phone, $providerReference, $providerMessage, $providerPayload, $providerCode, $providerHistoryJson, $paidStatus, $orderId);
            if (!$paidStmt->execute()) {
                $paidStmt->close();
                json_error('No se pudo marcar el pedido como pagado.', 500);
            }
            $paidStmt->close();

            $paidOrder = fetch_order_by_id($mysqli, $orderId) ?: $updatedOrder;
            recharge_notifications_emit_for_order($mysqli, $paidOrder);
            json_response(append_payment_difference_response([
                'ok' => true,
                'message' => 'El pago fue verificado, pero la recarga no pudo completarse automáticamente. Nuestro equipo revisará tu pedido.',
                'order_id' => $orderId,
                'estado' => 'pagado',
                'verified' => true,
                'provider_flow' => 'manual_review',
                'reasons' => [$providerMessage],
                'provider_reference' => $providerReference,
                'provider_message' => $providerMessage,
            ], $paidOrder, $overpaymentAmount), 200, static function () use ($mysqli, $paidOrder, $paymentMethodName, $verifiedReference, $phone, $providerMessage): void {
                register_influencer_coupon_sale($mysqli, $paidOrder);
                notify_free_fire_recharge_failure($mysqli, $paidOrder, $paymentMethodName, $verifiedReference, $phone, $providerMessage);
            });
        }

        $mismatch = explain_bank_movement_mismatch($bankMovements, $referenceNumber, (float) ($updatedOrder['precio'] ?? 0), $referenceMatchDigits);
        $pendingOrder = fetch_order_by_id($mysqli, $orderId) ?: $updatedOrder;
        json_response([
            'ok' => true,
            'message' => 'Su Pago está en proceso, Espere 1 min y vuelva a intentar',
            'order_id' => $orderId,
            'estado' => 'pendiente',
            'verified' => false,
            'bank_checked' => true,
            'reasons' => $mismatch['reasons'],
            'reference_match' => $mismatch['reference_match'],
            'amount_match' => $mismatch['amount_match'],
            'failure_type' => $mismatch['failure_type'],
        ], 200, static function () use ($mysqli, $pendingOrder, $paymentMethodName, $referenceNumber, $phone, $mismatch): void {
            notify_bank_payment_pending_mismatch(
                $mysqli,
                $pendingOrder,
                $paymentMethodName,
                $referenceNumber,
                $phone,
                $mismatch['reasons']
            );
        });
    }

    $customerMessage = '<p style="margin:0 0 10px;">Recibimos tu pago reportado y ya quedó enviado al equipo administrativo para validación.</p>'
        . '<p style="margin:0;">Cuando el administrador lo revise y apruebe, te notificaremos el siguiente cambio de estado.</p>';
    $adminMessage = '<p style="margin:0 0 10px;">El cliente reportó el pago de este pedido y quedó no verificado hasta la aprobación administrativa.</p>'
        . '<p style="margin:0;">Valida la referencia y el teléfono de contacto antes de aprobar la orden.</p>';

    $customerHtml = render_order_email('Pago reportado', 'Cliente', $customerMessage, [
        'order_id' => $orderId,
        'game_name' => $updatedOrder['juego_nombre'] ?? '',
        'pack_name' => $updatedOrder['paquete_nombre'] ?? '',
        'pack_amount' => $updatedOrder['paquete_cantidad'] ?? '',
        'currency' => $updatedOrder['moneda'] ?? '',
        'price' => number_format((float) ($updatedOrder['precio'] ?? 0), 2, '.', ','),
        'user_identifier' => $updatedOrder['user_identifier'] ?? '',
        'email' => $updatedOrder['email'] ?? '',
        'coupon' => $updatedOrder['cupon'] ?? null,
        'payment_method' => $paymentMethodName,
        'reference_number' => $referenceNumber,
        'phone' => $phone,
        'status' => 'Pago enviado para revisión',
    ], '#f59e0b');
    $adminHtml = render_order_email('Pago recibido para revisión', 'Administrador', $adminMessage, [
        'order_id' => $orderId,
        'game_name' => $updatedOrder['juego_nombre'] ?? '',
        'pack_name' => $updatedOrder['paquete_nombre'] ?? '',
        'pack_amount' => $updatedOrder['paquete_cantidad'] ?? '',
        'currency' => $updatedOrder['moneda'] ?? '',
        'price' => number_format((float) ($updatedOrder['precio'] ?? 0), 2, '.', ','),
        'user_identifier' => $updatedOrder['user_identifier'] ?? '',
        'email' => $updatedOrder['email'] ?? '',
        'coupon' => $updatedOrder['cupon'] ?? null,
        'payment_method' => $paymentMethodName,
        'reference_number' => $referenceNumber,
        'phone' => $phone,
        'status' => 'No Verificado',
    ], '#f59e0b');

    json_response([
        'ok' => true,
        'message' => 'Datos de pago enviados correctamente. Tu pedido sigue no verificado.',
        'order_id' => $orderId,
        'estado' => 'pendiente'
    ], 200, static function () use ($mysqli, $updatedOrder, $adminEmail, $customerHtml, $adminHtml, $brandingImages, $orderId): void {
        register_influencer_coupon_sale($mysqli, $updatedOrder);
        if (!empty($updatedOrder['email']) && filter_var($updatedOrder['email'], FILTER_VALIDATE_EMAIL)) {
            send_app_mail((string) $updatedOrder['email'], "Pago reportado #{$orderId}", $customerHtml, null, $brandingImages);
        }
        if ($adminEmail !== null) {
            send_app_mail($adminEmail, "Pago reportado #{$orderId}", $adminHtml, null, $brandingImages);
        }
    });
}

if ($action === 'expire_order') {
    $orderId = intval($_POST['order_id'] ?? 0);
    if ($orderId <= 0) {
        json_error('Pedido inválido.');
    }
    $order = fetch_order_by_id($mysqli, $orderId);
    if (!$order) {
        json_error('Pedido no encontrado.', 404);
    }

    if (($order['estado'] ?? '') !== 'pendiente') {
        json_response([
            'ok' => true,
            'expired' => ($order['estado'] ?? '') === 'cancelado',
            'message' => 'El pedido ya fue procesado previamente.',
            'estado' => $order['estado'] ?? ''
        ]);
    }

    if (!order_is_expired($order)) {
        json_response([
            'ok' => true,
            'expired' => false,
            'message' => 'La orden aún sigue activa.',
            'remaining_seconds' => max(0, order_expiration_timestamp($order) - time())
        ]);
    }

    $result = cancel_expired_order($mysqli, $order);
    json_response([
        'ok' => true,
        'expired' => true,
        'message' => $result['message']
    ]);
}

if ($action === 'activate_payment_difference_credit') {
    if (!payment_difference_feature_enabled()) {
        json_error('El flujo de diferencia de pago no está activo.', 409);
    }

    $orderId = intval($_POST['order_id'] ?? 0);
    if ($orderId <= 0) {
        json_error('Pedido inválido.');
    }

    $order = fetch_order_by_id($mysqli, $orderId);
    if (!$order) {
        json_error('Pedido no encontrado.', 404);
    }

    if (payment_difference_normalize_amount($order['diferencia_pago_credito_aplicado'] ?? 0) > 0 || (int) ($order['diferencia_pago_credito_activado'] ?? 0) === 1) {
        json_error('Este pedido ya usó su oportunidad de completar otra recarga con saldo a favor.', 409);
    }

    $totalPaid = sum_linked_movement_amount_for_order($mysqli, $orderId);
    $expectedAmount = payment_difference_normalize_amount($order['precio'] ?? 0);
    $overpaymentAmount = payment_difference_normalize_amount($totalPaid - $expectedAmount);
    if ($overpaymentAmount <= 0) {
        json_error('Este pedido no tiene saldo a favor disponible para completar otra recarga.', 409);
    }

    $stmt = $mysqli->prepare('UPDATE pedidos SET diferencia_pago_credito_activado = 1 WHERE id = ? LIMIT 1');
    if (!$stmt) {
        json_error('No se pudo activar el saldo a favor.', 500);
    }

    $stmt->bind_param('i', $orderId);
    if (!$stmt->execute()) {
        $stmt->close();
        json_error('No se pudo guardar el saldo a favor.', 500);
    }
    $stmt->close();

    $credit = payment_difference_activate_credit(
        $orderId,
        $overpaymentAmount,
        (string) ($order['moneda'] ?? 'VES'),
        1800,
        'Tienes un saldo a favor de una sola oportunidad para completar otra recarga.'
    );

    json_response([
        'ok' => true,
        'message' => 'Tu saldo a favor quedó activo durante 30 minutos para completar otra recarga.',
        'payment_difference' => [
            'status' => 'credit_activated',
            'source_order_id' => $orderId,
            'available_amount' => payment_difference_normalize_amount($credit['available_amount'] ?? 0),
            'currency' => strtoupper(trim((string) ($credit['currency'] ?? ($order['moneda'] ?? 'VES')))) !== '' ? strtoupper(trim((string) ($credit['currency'] ?? ($order['moneda'] ?? 'VES')))) : 'VES',
            'remaining_seconds' => max(0, (int) ($credit['remaining_seconds'] ?? 0)),
        ],
    ]);
}

if ($action === 'cancel_order') {
    $orderId = intval($_POST['order_id'] ?? 0);
    if ($orderId <= 0) {
        json_error('Pedido inválido.');
    }

    $order = fetch_order_by_id($mysqli, $orderId);
    if (!$order) {
        json_error('Pedido no encontrado.', 404);
    }

    $result = cancel_pending_order_by_customer($mysqli, $order);
    if (!$result['changed']) {
        json_error($result['message'], 409);
    }

    json_response([
        'ok' => true,
        'message' => $result['message'],
        'estado' => 'cancelado',
        'order_id' => $orderId,
    ]);
}

if ($action === 'discord_listener') {
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        json_error('Método no permitido.', 405);
    }

    $payload = api_discord_listener_payload_from_request();
    $configuredListenerToken = api_discord_normalize_listener_token((string) store_config_get('api_discord_listener_token', ''));
    if ($configuredListenerToken === '') {
        json_error('El listener de API Discord no está configurado en esta tienda.', 409);
    }

    $providedToken = api_discord_listener_token_from_request($payload);
    if ($providedToken === '' || !hash_equals($configuredListenerToken, $providedToken)) {
        json_error('Token de listener inválido.', 403);
    }

    $orderId = (int) ($payload['order_id'] ?? 0);
    $messageId = trim((string) ($payload['source_message_id'] ?? $payload['message_id'] ?? $payload['discord_message_id'] ?? ''));
    $order = resolve_api_discord_listener_order($mysqli, $orderId, $messageId);
    if (!$order) {
        json_error('No se encontró una orden API Discord que coincida con la correlación recibida.', 404);
    }

    $previousLocalStatus = strtolower(trim((string) ($order['estado'] ?? '')));

    try {
        $listenerResult = persist_api_discord_listener_update($mysqli, $order, $payload);
    } catch (Throwable $e) {
        json_error($e->getMessage(), 500);
    }

    $updatedOrder = fetch_order_by_id($mysqli, (int) ($order['id'] ?? 0)) ?: $order;
    handle_api_discord_order_status_side_effects($mysqli, $previousLocalStatus, $updatedOrder, $listenerResult);

    json_response(array_merge([
        'ok' => true,
        'message' => 'Correlación de Discord aplicada correctamente.',
    ], build_order_status_response_payload($updatedOrder, (int) ($updatedOrder['id'] ?? 0))));
}

if ($action === 'discord_catalog_listener') {
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        json_error('Método no permitido.', 405);
    }

    $payload = api_discord_listener_payload_from_request();
    $configuredListenerToken = api_discord_normalize_listener_token((string) store_config_get('api_discord_listener_token', ''));
    if ($configuredListenerToken === '') {
        json_error('El listener de API Discord no está configurado en esta tienda.', 409);
    }

    $providedToken = api_discord_listener_token_from_request($payload);
    if ($providedToken === '' || !hash_equals($configuredListenerToken, $providedToken)) {
        json_error('Token de listener inválido.', 403);
    }

    $gameId = (int) ($payload['game_id'] ?? 0);
    $messageId = trim((string) ($payload['source_message_id'] ?? $payload['message_id'] ?? $payload['discord_message_id'] ?? ''));
    $game = $gameId > 0 ? fetch_game_by_id($mysqli, $gameId) : find_game_by_discord_catalog_message_id($mysqli, $messageId);
    if (!$game) {
        json_error('No se encontró un juego Discord que coincida con la correlación recibida.', 404);
    }

    if (trim((string) ($game['categoria_api_discord'] ?? '')) === '') {
        json_error('El juego encontrado no usa catálogo de Discord.', 409);
    }

    try {
        $catalogResult = persist_api_discord_game_catalog($mysqli, $game, $payload);
    } catch (Throwable $e) {
        json_error($e->getMessage(), 500);
    }

    json_response([
        'ok' => true,
        'message' => 'Catálogo de precios de Discord sincronizado correctamente.',
        'game_id' => (int) ($game['id'] ?? 0),
        'items_count' => count((array) ($catalogResult['items'] ?? [])),
        'message_id' => trim((string) ($catalogResult['message_id'] ?? '')),
        'status' => trim((string) ($catalogResult['status'] ?? 'ready')),
    ]);
}

if ($action === 'admin_update_discord_status') {
    $adminRole = trim((string) ($_SESSION['auth_user']['rol'] ?? ''));
    if (!isset($_SESSION['auth_user']) || !in_array($adminRole, ['admin', 'empleado'], true)) {
        json_error('No autorizado', 403);
    }

    $orderId = intval($_POST['order_id'] ?? $_GET['order_id'] ?? 0);
    if ($orderId <= 0) {
        json_error('Pedido inválido.');
    }

    $order = fetch_order_by_id($mysqli, $orderId);
    if (!$order) {
        json_error('Pedido no encontrado.', 404);
    }

    if (!order_uses_api_discord($order)) {
        json_error('Este pedido no usa el flujo de API Discord.', 409);
    }

    $localStatus = strtolower(trim((string) ($order['estado'] ?? '')));
    if ($localStatus === 'pendiente') {
        json_error('La orden aún no está pagada. No se puede actualizar su estado Discord desde admin.', 409);
    }

    $discordStatus = normalize_api_discord_order_status((string) ($_POST['discord_status'] ?? $_GET['discord_status'] ?? ''));
    $allowedAdminStatuses = ['queued', 'sent', 'processing', 'confirmed', 'review', 'failed', 'cancelled'];
    if (!in_array($discordStatus, $allowedAdminStatuses, true)) {
        json_error('Estado Discord inválido para actualización manual.', 422);
    }

    $providerMessage = sanitize_str($_POST['provider_message'] ?? $_GET['provider_message'] ?? null, 500);
    $payload = [
        'status' => $discordStatus,
        'provider_message' => trim((string) ($providerMessage ?? '')),
    ];
    if (trim((string) ($order['api_discord_message_id'] ?? '')) !== '') {
        $payload['message_id'] = trim((string) ($order['api_discord_message_id'] ?? ''));
    }

    try {
        $statusResult = persist_api_discord_listener_update($mysqli, $order, $payload, 'admin_panel');
    } catch (Throwable $e) {
        json_error($e->getMessage(), 500);
    }

    $updatedOrder = fetch_order_by_id($mysqli, $orderId) ?: $order;
    handle_api_discord_order_status_side_effects($mysqli, $localStatus, $updatedOrder, $statusResult);

    json_response(array_merge([
        'ok' => true,
        'message' => 'Estado Discord actualizado manualmente desde el panel.',
    ], build_order_status_response_payload($updatedOrder, $orderId)));
}

if ($action === 'order_status') {
    $orderId = intval($_POST['order_id'] ?? $_GET['order_id'] ?? 0);
    if ($orderId <= 0) {
        json_error('Pedido inválido.');
    }

    $order = fetch_order_by_id($mysqli, $orderId);
    if (!$order) {
        json_error('Pedido no encontrado.', 404);
    }

    $sessionUserId = isset($_SESSION['auth_user']['id']) ? (int) $_SESSION['auth_user']['id'] : 0;
    $sessionUserRole = trim((string) ($_SESSION['auth_user']['rol'] ?? ''));
    $providedEmail = sanitize_str($_POST['email'] ?? $_GET['email'] ?? null, 180);
    $emailMatches = $providedEmail !== null
        && strcasecmp(trim((string) ($order['email'] ?? '')), trim((string) $providedEmail)) === 0;
    $ownsOrder = $sessionUserId > 0 && $sessionUserId === (int) ($order['cliente_usuario_id'] ?? 0);
    $isStaff = in_array($sessionUserRole, ['admin', 'empleado'], true);

    if (!$emailMatches && !$ownsOrder && !$isStaff) {
        json_error('No autorizado.', 403);
    }

    $attemptSync = !empty($_POST['attempt_sync']) || !empty($_GET['attempt_sync']);
    if ($attemptSync) {
        $localStatus = trim((string) ($order['estado'] ?? ''));
        $providerOrderId = trim((string) ($order['recargas_api_pedido_id'] ?? ''));
        $binanceReference = trim((string) ($order['binance_pay_reference'] ?? ''));
        $paypalOrderId = trim((string) ($order['paypal_order_id'] ?? ''));

        try {
            if ($localStatus === 'pagado' && order_provider_flow_from_row($order) !== 'inventory_shortage' && ($providerOrderId !== '' || trim((string) ($order['ff_api_referencia'] ?? '')) !== '')) {
                if ($providerOrderId !== '') {
                    $syncResult = try_auto_sync_provider_order($mysqli, $order, 1, 0);
                    $order = is_array($syncResult['order'] ?? null) ? $syncResult['order'] : (fetch_order_by_id($mysqli, $orderId) ?: $order);
                } else {
                    $syncResult = try_recover_uncertain_provider_purchase($mysqli, $order, 1, 0);
                    $order = is_array($syncResult['order'] ?? null) ? $syncResult['order'] : (fetch_order_by_id($mysqli, $orderId) ?: $order);
                }
            } elseif (binance_pay_is_enabled() && $binanceReference !== '' && in_array($localStatus, ['pendiente', 'pagado'], true)) {
                $syncResult = try_auto_sync_binance_order($mysqli, $order, 'status_poll');
                $order = is_array($syncResult['order'] ?? null) ? $syncResult['order'] : (fetch_order_by_id($mysqli, $orderId) ?: $order);
            } elseif (paypal_pay_is_enabled() && $paypalOrderId !== '' && in_array($localStatus, ['pendiente', 'pagado'], true)) {
                $syncResult = sync_or_capture_paypal_order($mysqli, $order, 'status_poll');
                $order = is_array($syncResult['order'] ?? null) ? $syncResult['order'] : (fetch_order_by_id($mysqli, $orderId) ?: $order);
            }
        } catch (Throwable $e) {
            error_log('TVG order_status sync attempt failed for order #' . $orderId . ': ' . $e->getMessage());
            $order = fetch_order_by_id($mysqli, $orderId) ?: $order;
        }
    }

    json_response(build_order_status_response_payload($order, $orderId));
}

if ($action === 'sync_provider_status') {
    $adminRole = trim((string) ($_SESSION['auth_user']['rol'] ?? ''));
    if (!isset($_SESSION['auth_user']) || !in_array($adminRole, ['admin', 'empleado'], true)) {
        json_error('No autorizado', 403);
    }

    $orderId = intval($_POST['order_id'] ?? $_GET['order_id'] ?? 0);
    if ($orderId <= 0) {
        json_error('Pedido inválido.');
    }

    $order = fetch_order_by_id($mysqli, $orderId);
    if (!$order) {
        json_error('Pedido no encontrado.', 404);
    }

    $hasBinanceTracking = trim((string) ($order['binance_pay_reference'] ?? '')) !== ''
        || trim((string) ($order['binance_pay_order_no'] ?? '')) !== ''
        || trim((string) ($order['binance_pay_request_id'] ?? '')) !== '';
    $hasPaypalTracking = trim((string) ($order['paypal_order_id'] ?? '')) !== ''
        || trim((string) ($order['paypal_capture_id'] ?? '')) !== '';
    $syncGateway = 'provider';

    try {
        $providerOrderId = trim((string) ($order['recargas_api_pedido_id'] ?? ''));
        if ($providerOrderId !== '') {
            $providerDetail = recargas_api_fetch_order_detail($providerOrderId);
            $syncResult = sync_local_order_with_provider_detail($mysqli, $order, $providerDetail, true);
        } elseif ($hasBinanceTracking) {
            if (!binance_pay_is_enabled()) {
                json_error('Binance Pay no está activo en esta tienda.', 409);
            }
            $syncGateway = 'binance_pay';
            $syncResult = try_auto_sync_binance_order($mysqli, $order, 'admin_sync');
        } elseif ($hasPaypalTracking) {
            if (!paypal_pay_is_enabled()) {
                json_error('PayPal no está activo en esta tienda.', 409);
            }
            $syncGateway = 'paypal';
            $syncResult = sync_or_capture_paypal_order($mysqli, $order, 'admin_sync');
        } else {
            $syncResult = try_recover_uncertain_provider_purchase($mysqli, $order, 2, 2);
            if (!is_array($syncResult)) {
                json_error('Este pedido aun no pudo vincularse con una orden externa del proveedor.', 404);
            }
        }
    } catch (Throwable $e) {
        json_error($e->getMessage(), 502);
    }

    $syncedOrder = is_array($syncResult['order'] ?? null)
        ? $syncResult['order']
        : (fetch_order_by_id($mysqli, $orderId) ?: $order);
    $resolvedProviderOrderId = trim((string) ($syncedOrder['recargas_api_pedido_id'] ?? ''));
    $resolvedProviderStatus = trim((string) ($syncResult['provider_status'] ?? ''));
    if ($resolvedProviderStatus === '') {
        $resolvedProviderStatus = trim((string) ($syncedOrder['recargas_api_estado'] ?? ''));
    }
    if ($resolvedProviderStatus === '') {
        $resolvedProviderStatus = trim((string) ($syncedOrder['binance_pay_status'] ?? ''));
    }
    if ($resolvedProviderStatus === '') {
        $resolvedProviderStatus = trim((string) ($syncedOrder['paypal_status'] ?? ''));
    }

    $resolvedProviderReference = trim((string) ($syncResult['provider_reference'] ?? ''));
    if ($resolvedProviderReference === '') {
        $resolvedProviderReference = trim((string) ($syncedOrder['ff_api_referencia'] ?? ''));
    }
    if ($resolvedProviderReference === '') {
        $resolvedProviderReference = trim((string) ($syncedOrder['binance_pay_reference'] ?? ''));
    }
    if ($resolvedProviderReference === '') {
        $resolvedProviderReference = trim((string) ($syncedOrder['paypal_capture_id'] ?? ''));
    }
    if ($resolvedProviderReference === '') {
        $resolvedProviderReference = trim((string) ($syncedOrder['paypal_order_id'] ?? ''));
    }

    $resolvedProviderMessage = trim((string) ($syncResult['provider_message'] ?? ''));
    if ($resolvedProviderMessage === '') {
        $resolvedProviderMessage = trim((string) ($syncedOrder['ff_api_mensaje'] ?? ''));
    }
    if ($resolvedProviderMessage === '') {
        $resolvedProviderMessage = trim((string) ($syncedOrder['binance_pay_message'] ?? ''));
    }
    if ($resolvedProviderMessage === '') {
        $resolvedProviderMessage = trim((string) ($syncedOrder['paypal_message'] ?? ''));
    }

    $resolvedProviderCode = trim((string) ($syncResult['provider_code'] ?? ''));
    if ($resolvedProviderCode === '') {
        $resolvedProviderCode = trim((string) ($syncedOrder['recargas_api_codigo_entregado'] ?? ''));
    }

    $resolvedPaymentGateway = $syncGateway === 'binance_pay' ? 'binance_pay' : '';
    if ($resolvedPaymentGateway === '' && $syncGateway === 'paypal') {
        $resolvedPaymentGateway = 'paypal';
    }
    if ($resolvedPaymentGateway === '' && $hasBinanceTracking && $resolvedProviderOrderId === '') {
        $resolvedPaymentGateway = 'binance_pay';
    }
    if ($resolvedPaymentGateway === '' && $hasPaypalTracking && $resolvedProviderOrderId === '') {
        $resolvedPaymentGateway = 'paypal';
    }

    if ($syncGateway === 'binance_pay') {
        if ($resolvedProviderStatus === '' && $resolvedProviderReference === '' && $resolvedProviderMessage === '') {
            json_error('No se pudo obtener una respuesta util de Binance Pay para este pedido.', 404);
        }
    } elseif ($syncGateway === 'paypal') {
        if ($resolvedProviderStatus === '' && $resolvedProviderReference === '' && $resolvedProviderMessage === '') {
            json_error('No se pudo obtener una respuesta útil de PayPal para este pedido.', 404);
        }
    } elseif ($resolvedProviderOrderId === '' && $resolvedProviderStatus === '' && $resolvedProviderReference === '') {
        json_error('Aun no se encontro un pedido externo asociado a esta orden para sincronizar.', 404);
    }

    json_response([
        'ok' => true,
        'message' => $syncGateway === 'binance_pay'
            ? 'Pedido sincronizado correctamente con Binance Pay.'
            : 'Pedido sincronizado correctamente con el proveedor.',
        'order_id' => $orderId,
        'estado' => trim((string) ($syncResult['local_status'] ?? ($syncedOrder['estado'] ?? ''))),
        'provider_status' => $resolvedProviderStatus,
        'provider_reference' => $resolvedProviderReference,
        'provider_message' => $resolvedProviderMessage,
        'provider_code' => $resolvedProviderCode,
        'refund_amount' => $syncResult['refund_amount'] ?? null,
        'provider_flow' => order_provider_flow_from_row($syncedOrder),
        'payment_gateway' => $resolvedPaymentGateway,
        'checkout_url' => $resolvedPaymentGateway === 'paypal'
            ? trim((string) ($syncedOrder['paypal_checkout_url'] ?? ''))
            : binance_pay_normalize_checkout_url((string) ($syncedOrder['binance_pay_checkout_url'] ?? '')),
    ]);
}

if ($action === 'admin_retry_recharge') {
    $adminRole = trim((string) ($_SESSION['auth_user']['rol'] ?? ''));
    if (!isset($_SESSION['auth_user']) || !in_array($adminRole, ['admin', 'empleado'], true)) {
        json_error('No autorizado', 403);
    }

    $orderId = intval($_POST['order_id'] ?? $_GET['order_id'] ?? 0);
    if ($orderId <= 0) {
        json_error('Pedido invalido.');
    }

    $order = fetch_order_by_id($mysqli, $orderId);
    if (!$order) {
        json_error('Pedido no encontrado.', 404);
    }

    $orderStatus = (string) ($order['estado'] ?? '');
    $accountSaleResendAllowed = order_is_account_sale($order) && in_array($orderStatus, ['pagado', 'enviado'], true);
    if (!$accountSaleResendAllowed && $orderStatus !== 'pagado') {
        json_error('Solo se pueden reenviar recargas de pedidos verificados.', 409);
    }

    if (order_is_account_sale($order)) {
        $verifiedReference = (string) ($order['numero_referencia'] ?? '');
        $phone = (string) ($order['telefono_contacto'] ?? '');

        try {
            $updatedOrder = mark_account_sale_as_sent($mysqli, $order, $orderStatus !== '' ? $orderStatus : 'enviado', $verifiedReference, $phone);
        } catch (Throwable $e) {
            json_error($e->getMessage(), 500);
        }

        win_points_handle_order_status_change($mysqli, $orderId, 'enviado');
        recharge_notifications_emit_for_order($mysqli, $updatedOrder);
        json_response(append_account_sale_response([
            'ok' => true,
            'message' => 'La cuenta fue reenviada correctamente al cliente.',
            'order_id' => $orderId,
            'estado' => 'enviado',
            'provider_flow' => 'account_sale',
            'provider_status' => 'account_sale',
        ], $updatedOrder), 200, static function () use ($mysqli, $updatedOrder, $verifiedReference, $phone): void {
            notify_account_sale_delivery($mysqli, $updatedOrder, 'Panel administrativo', $verifiedReference, $phone, true);
        });
    }

    if (trim((string) ($order['recargas_api_pedido_id'] ?? '')) !== '') {
        json_error('Este pedido ya tiene una orden API asociada. Usa Sincronizar API.', 409);
    }

    $gameId = (int) ($order['juego_id'] ?? 0);
    $verifiedReference = (string) ($order['numero_referencia'] ?? '');
    $phone = (string) ($order['telefono_contacto'] ?? '');
    $paymentMethodName = 'Panel administrativo';

    if (order_uses_api_discord($order)) {
        $dispatchResult = execute_api_discord_order_dispatch($order);
        if (!persist_api_discord_dispatch_result($mysqli, $order, $dispatchResult, 'pagado', 'pagado', $verifiedReference, $phone)) {
            json_error('No se pudo guardar el resultado del envío Discord.', 500);
        }

        $updatedOrder = fetch_order_by_id($mysqli, $orderId) ?: $order;
        json_response(append_api_discord_response([
            'ok' => true,
            'message' => trim((string) ($dispatchResult['provider_message'] ?? 'La orden quedó actualizada para Discord.')),
            'order_id' => $orderId,
            'estado' => trim((string) ($updatedOrder['estado'] ?? 'pagado')),
            'provider_flow' => trim((string) ($dispatchResult['provider_flow'] ?? 'manual_review')),
            'provider_status' => trim((string) ($dispatchResult['provider_status'] ?? 'review')),
            'provider_reference' => trim((string) ($dispatchResult['provider_reference'] ?? '')),
            'provider_message' => trim((string) ($dispatchResult['provider_message'] ?? '')),
        ], $updatedOrder), 200, static function () use ($mysqli, $updatedOrder, $paymentMethodName, $verifiedReference, $phone, $dispatchResult): void {
            if (!empty($dispatchResult['sent'])) {
                notify_catalog_purchase_pending($mysqli, $updatedOrder, $paymentMethodName, $verifiedReference, $phone, trim((string) ($dispatchResult['provider_reference'] ?? '')), trim((string) ($dispatchResult['provider_message'] ?? '')));
            }
        });
    }

    if (order_uses_catalog_api_provider($order)) {
        $packageApiId = (int) ($order['paquete_api'] ?? 0);
        if ($packageApiId <= 0) {
            json_error('Este pedido no tiene un producto API configurado.', 409);
        }

        $orderPlayerFields = order_player_fields_from_json((string) ($order['player_fields_json'] ?? ''));

        try {
            $providerResult = execute_catalog_api_purchase($packageApiId, (string) ($order['user_identifier'] ?? ''), $orderPlayerFields, order_purchase_quantity($order));
        } catch (Throwable $e) {
            $providerResult = [
                'success' => false,
                'accepted' => false,
                'message' => $e->getMessage(),
                'reference' => '',
                'payload' => ['exception' => $e->getMessage()],
            ];
        }

        $mysqli = ensure_mysqli_connection($mysqli);
        $providerReference = (string) ($providerResult['reference'] ?? '');
        $providerMessage = (string) ($providerResult['message'] ?? 'No se recibio mensaje del proveedor.');
        $providerPayload = json_encode($providerResult['payload'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($providerPayload)) {
            $providerPayload = '{}';
        }
        $providerOrderId = recargas_api_extract_provider_order_id((array) ($providerResult['payload'] ?? []));
        $providerState = strtolower(trim((string) (($providerResult['payload']['estado'] ?? ''))));

        if (!empty($providerResult['success'])) {
            $sentStatus = 'enviado';
            $providerHistoryJson = append_provider_history(
                $order['recargas_api_historial_json'] ?? null,
                build_provider_history_entry('admin_retry_purchase', $providerState, $sentStatus, $providerMessage, $providerReference, $providerOrderId)
            );
            $stmt = $mysqli->prepare("UPDATE pedidos SET ff_api_referencia = ?, ff_api_mensaje = ?, ff_api_payload = ?, recargas_api_pedido_id = ?, recargas_api_estado = ?, recargas_api_ultimo_check = NOW(), recargas_api_historial_json = ?, estado = ? WHERE id = ? AND estado = 'pagado'");
            if (!$stmt) {
                json_error('No se pudo actualizar el pedido tras reenviar la recarga.', 500);
            }
            $stmt->bind_param('ssssssssi', $providerReference, $providerMessage, $providerPayload, $providerOrderId, $providerState, $providerHistoryJson, $sentStatus, $orderId);
            if (!$stmt->execute()) {
                $stmt->close();
                json_error('No se pudo guardar el resultado de la recarga.', 500);
            }
            $stmt->close();

            $updatedOrder = fetch_order_by_id($mysqli, $orderId) ?: $order;
            recharge_notifications_emit_for_order($mysqli, $updatedOrder);
            json_response([
                'ok' => true,
                'message' => 'La recarga fue reenviada y procesada correctamente.',
                'order_id' => $orderId,
                'estado' => 'enviado',
                'provider_flow' => 'completed',
                'provider_reference' => $providerReference,
                'provider_status' => $providerState,
                'provider_message' => $providerMessage,
            ], 200, static function () use ($mysqli, $updatedOrder, $paymentMethodName, $verifiedReference, $phone, $providerReference, $providerMessage): void {
                ensure_provider_webhook_registration();
                notify_free_fire_recharge_success($mysqli, $updatedOrder, $paymentMethodName, $verifiedReference, $phone, $providerReference, $providerMessage);
            });
        }

        $manualProcessing = !empty($providerResult['manual_processing']);
        $acceptedLike = !empty($providerResult['accepted'])
            || ($manualProcessing && provider_message_indicates_pending_lookup($providerMessage));
        $trackingFollowUp = provider_message_indicates_transport_timeout($providerMessage);

        if ($trackingFollowUp || $acceptedLike) {
            $trackedState = $providerState !== '' ? $providerState : ($trackingFollowUp ? 'pending_confirmation' : 'accepted');
            $providerHistoryJson = append_provider_history(
                $order['recargas_api_historial_json'] ?? null,
                build_provider_history_entry(
                    $trackingFollowUp ? 'admin_retry_purchase_timeout' : 'admin_retry_purchase',
                    $trackedState,
                    'pagado',
                    $providerMessage,
                    $providerReference,
                    $providerOrderId
                )
            );
            $stmt = $mysqli->prepare("UPDATE pedidos SET ff_api_referencia = ?, ff_api_mensaje = ?, ff_api_payload = ?, recargas_api_pedido_id = ?, recargas_api_estado = ?, recargas_api_ultimo_check = NOW(), recargas_api_historial_json = ? WHERE id = ? AND estado = 'pagado'");
            if (!$stmt) {
                json_error('No se pudo actualizar el pedido para seguimiento.', 500);
            }
            $stmt->bind_param('ssssssi', $providerReference, $providerMessage, $providerPayload, $providerOrderId, $trackedState, $providerHistoryJson, $orderId);
            if (!$stmt->execute()) {
                $stmt->close();
                json_error('No se pudo guardar el seguimiento del proveedor.', 500);
            }
            $stmt->close();

            $updatedOrder = fetch_order_by_id($mysqli, $orderId) ?: $order;
            $autoSyncAttempts = $manualProcessing ? 5 : 3;
            $autoSyncDelaySeconds = $manualProcessing ? 4 : 2;
            $autoSyncResult = try_auto_sync_provider_order($mysqli, $updatedOrder, $autoSyncAttempts, $autoSyncDelaySeconds);
            if (is_array($autoSyncResult)) {
                $updatedOrder = is_array($autoSyncResult['order'] ?? null) ? $autoSyncResult['order'] : $updatedOrder;
                $providerMessage = trim((string) ($autoSyncResult['provider_message'] ?? $providerMessage));
                $providerReference = trim((string) ($autoSyncResult['provider_reference'] ?? $providerReference));
                $resolvedStatus = trim((string) ($autoSyncResult['local_status'] ?? ''));

                if ($resolvedStatus === 'enviado') {
                    json_response([
                        'ok' => true,
                        'message' => 'La recarga fue reenviada y procesada correctamente.',
                        'order_id' => $orderId,
                        'estado' => 'enviado',
                        'provider_flow' => 'completed',
                        'provider_reference' => $providerReference,
                        'provider_message' => $providerMessage,
                    ]);
                }

                if ($resolvedStatus === 'cancelado') {
                    json_response([
                        'ok' => true,
                        'message' => 'El proveedor rechazo la recarga reenviada. El pedido sigue para revision manual.',
                        'order_id' => $orderId,
                        'estado' => 'cancelado',
                        'provider_flow' => 'cancelled',
                        'provider_reference' => $providerReference,
                        'provider_message' => $providerMessage,
                    ]);
                }
            }

            json_response([
                'ok' => true,
                'message' => $trackingFollowUp
                    ? 'La recarga fue reenviada y quedo en seguimiento automatico del proveedor.'
                    : 'La recarga fue reenviada y aceptada por la API. Quedo en proceso para seguimiento.',
                'order_id' => $orderId,
                'estado' => 'pagado',
                'provider_flow' => $trackingFollowUp ? 'tracking' : 'accepted',
                'provider_reference' => $providerReference,
                'provider_status' => $trackedState,
                'provider_message' => $providerMessage,
            ], 200, static function () use ($mysqli, $updatedOrder, $paymentMethodName, $verifiedReference, $phone, $providerReference, $providerMessage): void {
                ensure_provider_webhook_registration();
                notify_catalog_purchase_pending($mysqli, $updatedOrder, $paymentMethodName, $verifiedReference, $phone, $providerReference, $providerMessage);
                continue_provider_follow_up_in_background($mysqli, (int) ($updatedOrder['id'] ?? 0), 8, 8);
            });
        }

        $providerHistoryJson = append_provider_history(
            $order['recargas_api_historial_json'] ?? null,
            build_provider_history_entry('admin_retry_purchase', $providerState, 'pagado', $providerMessage, $providerReference, $providerOrderId)
        );
        $inventoryShortage = recharge_availability_message_indicates_inventory_shortage($providerMessage);
        if ($inventoryShortage) {
            try {
                $shortageResult = mark_order_inventory_shortage_review(
                    $mysqli,
                    $order,
                    'pagado',
                    $verifiedReference,
                    $phone,
                    $providerReference,
                    $providerMessage,
                    $providerPayload
                );
            } catch (Throwable $e) {
                json_error($e->getMessage(), 500);
            }

            json_response([
                'ok' => true,
                'message' => 'No hay suficientes recargas disponibles. El pedido sigue verificado y el paquete afectado fue restringido por prevención.',
                'order_id' => $orderId,
                'estado' => 'pagado',
                'provider_flow' => 'inventory_shortage',
                'provider_status' => 'inventory_shortage',
                'provider_reference' => $providerReference,
                'provider_message' => $providerMessage,
            ]);
        }

        $stmt = $mysqli->prepare("UPDATE pedidos SET ff_api_referencia = ?, ff_api_mensaje = ?, ff_api_payload = ?, recargas_api_historial_json = ? WHERE id = ? AND estado = 'pagado'");
        if (!$stmt) {
            json_error('No se pudo guardar el intento de recarga.', 500);
        }
        $stmt->bind_param('ssssi', $providerReference, $providerMessage, $providerPayload, $providerHistoryJson, $orderId);
        if (!$stmt->execute()) {
            $stmt->close();
            json_error('No se pudo guardar el error del proveedor.', 500);
        }
        $stmt->close();

        json_error('La recarga no pudo completarse automaticamente: ' . $providerMessage, 409);
    }

    if (order_uses_free_fire_api_provider($order)) {
        $monto = sanitize_str((string) ($order['monto_ff'] ?? ''), 20) ?? '';
        $numero = sanitize_str((string) ($order['user_identifier'] ?? ''), 150) ?? '';
        if ($monto === '') {
            json_error('Este pedido no tiene monto configurado para la recarga de Free Fire.', 409);
        }

        try {
            $providerResult = execute_free_fire_recharge(free_fire_api_config(), $monto, $numero);
        } catch (Throwable $e) {
            $providerResult = [
                'success' => false,
                'message' => $e->getMessage(),
                'reference' => '',
                'payload' => ['exception' => $e->getMessage()],
            ];
        }

        $mysqli = ensure_mysqli_connection($mysqli);
        $providerReference = (string) ($providerResult['reference'] ?? '');
        $providerMessage = (string) ($providerResult['message'] ?? 'No se recibio mensaje de la API de Free Fire.');
        $providerPayload = json_encode($providerResult['payload'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($providerPayload)) {
            $providerPayload = '{}';
        }
        $providerHistoryJson = append_provider_history(
            $order['recargas_api_historial_json'] ?? null,
            build_provider_history_entry('admin_retry_legacy_free_fire', '', !empty($providerResult['success']) ? 'enviado' : 'pagado', $providerMessage, $providerReference)
        );

        if (!empty($providerResult['success'])) {
            $sentStatus = 'enviado';
            $stmt = $mysqli->prepare("UPDATE pedidos SET ff_api_referencia = ?, ff_api_mensaje = ?, ff_api_payload = ?, recargas_api_historial_json = ?, estado = ? WHERE id = ? AND estado = 'pagado'");
            if (!$stmt) {
                json_error('No se pudo actualizar el pedido tras reenviar la recarga.', 500);
            }
            $stmt->bind_param('sssssi', $providerReference, $providerMessage, $providerPayload, $providerHistoryJson, $sentStatus, $orderId);
            if (!$stmt->execute()) {
                $stmt->close();
                json_error('No se pudo guardar el resultado de la recarga.', 500);
            }
            $stmt->close();

            $updatedOrder = fetch_order_by_id($mysqli, $orderId) ?: $order;
            recharge_notifications_emit_for_order($mysqli, $updatedOrder);
            json_response([
                'ok' => true,
                'message' => 'La recarga de Free Fire fue enviada correctamente.',
                'order_id' => $orderId,
                'estado' => 'enviado',
                'provider_flow' => 'completed',
                'provider_reference' => $providerReference,
                'provider_message' => $providerMessage,
            ], 200, static function () use ($mysqli, $updatedOrder, $paymentMethodName, $verifiedReference, $phone, $providerReference, $providerMessage): void {
                notify_free_fire_recharge_success($mysqli, $updatedOrder, $paymentMethodName, $verifiedReference, $phone, $providerReference, $providerMessage);
            });
        }

        $stmt = $mysqli->prepare("UPDATE pedidos SET ff_api_referencia = ?, ff_api_mensaje = ?, ff_api_payload = ?, recargas_api_historial_json = ? WHERE id = ? AND estado = 'pagado'");
        if (!$stmt) {
            json_error('No se pudo registrar el intento de recarga.', 500);
        }
        $stmt->bind_param('ssssi', $providerReference, $providerMessage, $providerPayload, $providerHistoryJson, $orderId);
        if (!$stmt->execute()) {
            $stmt->close();
            json_error('No se pudo guardar el error de la recarga.', 500);
        }
        $stmt->close();

        json_error('La recarga de Free Fire no pudo completarse automaticamente: ' . $providerMessage, 409);
    }

    json_error('Este pedido no tiene una recarga automatica configurable para reintentar.', 409);
}

if ($action === 'provider_recent_orders') {
    $adminRole = trim((string) ($_SESSION['auth_user']['rol'] ?? ''));
    if (!isset($_SESSION['auth_user']) || !in_array($adminRole, ['admin', 'empleado'], true)) {
        json_error('No autorizado', 403);
    }

    try {
        $orders = recargas_api_fetch_recent_orders();
    } catch (Throwable $e) {
        json_error($e->getMessage(), 502);
    }

    json_response(['ok' => true, 'pedidos' => $orders]);
}

if ($action === 'provider_transactions') {
    $adminRole = trim((string) ($_SESSION['auth_user']['rol'] ?? ''));
    if (!isset($_SESSION['auth_user']) || !in_array($adminRole, ['admin', 'empleado'], true)) {
        json_error('No autorizado', 403);
    }

    try {
        $transactions = recargas_api_fetch_transactions();
    } catch (Throwable $e) {
        json_error($e->getMessage(), 502);
    }

    json_response(['ok' => true, 'transacciones' => $transactions]);
}

if ($action === 'provider_get_webhook') {
    $adminRole = trim((string) ($_SESSION['auth_user']['rol'] ?? ''));
    if (!isset($_SESSION['auth_user']) || !in_array($adminRole, ['admin', 'empleado'], true)) {
        json_error('No autorizado', 403);
    }

    try {
        $webhook = recargas_api_get_webhook();
    } catch (Throwable $e) {
        json_error($e->getMessage(), 502);
    }

    json_response($webhook);
}

if ($action === 'provider_register_webhook') {
    $adminRole = trim((string) ($_SESSION['auth_user']['rol'] ?? ''));
    if (!isset($_SESSION['auth_user']) || !in_array($adminRole, ['admin', 'empleado'], true)) {
        json_error('No autorizado', 403);
    }

    $url = trim((string) ($_POST['url'] ?? ''));
    try {
        $response = recargas_api_register_webhook($url);
    } catch (Throwable $e) {
        json_error($e->getMessage(), 502);
    }

    json_response($response);
}

if ($action === 'provider_webhook') {
    $providerOrderId = sanitize_str((string) ($_POST['pedido_id'] ?? ''), 120);
    $providerStatus = trim((string) ($_POST['estado'] ?? ''));
    $providerReference = trim((string) ($_POST['referencia'] ?? ''));
    $providerReason = trim((string) ($_POST['razon'] ?? ''));
    $providerName = trim((string) ($_POST['nombre_jugador'] ?? ''));
    $providerCode = trim((string) ($_POST['codigo_entregado'] ?? ''));
    $refundAmount = isset($_POST['reembolso']) && is_numeric($_POST['reembolso']) ? (float) $_POST['reembolso'] : null;

    if (($providerOrderId === null || $providerOrderId === '') && $providerReference === '') {
        json_error('Webhook sin identificador del pedido externo.', 422);
    }

    $order = find_local_order_by_provider_identifiers($mysqli, $providerOrderId, $providerReference);

    if (!$order) {
        json_error('No se encontró un pedido local asociado a ese pedido externo.', 404);
    }

    $providerDetail = [
        'id' => $providerOrderId !== '' ? $providerOrderId : (string) ($order['recargas_api_pedido_id'] ?? ''),
        'estado' => $providerStatus,
        'referencia' => $providerReference,
        'razon' => $providerReason,
        'nombre_jugador' => $providerName,
        'codigo_entregado' => $providerCode,
        'reembolso' => $refundAmount,
    ];

    try {
        $syncResult = sync_local_order_with_provider_detail($mysqli, $order, $providerDetail, true);
    } catch (Throwable $e) {
        json_error($e->getMessage(), 500);
    }

    json_response([
        'ok' => true,
        'message' => 'Webhook procesado correctamente.',
        'order_id' => (int) ($order['id'] ?? 0),
        'estado' => $syncResult['local_status'],
    ]);
}

if ($action === 'paypal_webhook') {
    if (!paypal_pay_is_enabled()) {
        http_response_code(503);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'disabled';
        exit;
    }

    $rawBody = file_get_contents('php://input');
    $event = json_decode((string) $rawBody, true);
    if (!is_array($event)) {
        http_response_code(422);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'invalid';
        exit;
    }

    try {
        $headers = paypal_pay_webhook_headers_from_server($_SERVER);
        if (!paypal_pay_verify_webhook_signature($headers, $event)) {
            throw new RuntimeException('Invalid PayPal webhook signature.');
        }
    } catch (Throwable $e) {
        error_log('TVG PayPal webhook signature failed: ' . $e->getMessage());
        http_response_code(422);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'invalid';
        exit;
    }

    $identifiers = paypal_event_order_identifiers($event);
    $order = find_local_order_by_paypal_identifiers($mysqli, $identifiers['order_id'] ?? '', $identifiers['capture_id'] ?? '');
    if (!$order) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'missing';
        exit;
    }

    try {
        sync_or_capture_paypal_order($mysqli, $order, 'webhook');
        http_response_code(200);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'success';
    } catch (Throwable $e) {
        error_log('TVG PayPal webhook failed for order #' . (int) ($order['id'] ?? 0) . ': ' . $e->getMessage());
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'error';
    }
    exit;
}

if ($action === 'paypal_return') {
    header('Content-Type: text/html; charset=UTF-8');

    $paypalOrderId = trim((string) ($_GET['token'] ?? ''));
    if ($paypalOrderId === '') {
        http_response_code(400);
        echo paypal_popup_bridge_html('Retorno inválido', 'PayPal no devolvió un token de orden válido.', 'danger', [
            'state' => 'error',
        ]);
        exit;
    }

    $order = find_local_order_by_paypal_identifiers($mysqli, $paypalOrderId, null);
    if (!$order) {
        http_response_code(404);
        echo paypal_popup_bridge_html('Pedido no encontrado', 'No se encontró una orden local asociada al retorno de PayPal.', 'danger', [
            'state' => 'error',
            'paypal_order_id' => $paypalOrderId,
        ]);
        exit;
    }

    try {
        $syncResult = sync_or_capture_paypal_order($mysqli, $order, 'return');
        $updatedOrder = is_array($syncResult['order'] ?? null) ? $syncResult['order'] : (fetch_order_by_id($mysqli, (int) ($order['id'] ?? 0)) ?: $order);
        $localStatus = trim((string) ($syncResult['local_status'] ?? ($updatedOrder['estado'] ?? 'pendiente')));
        $message = trim((string) ($updatedOrder['paypal_message'] ?? ''));
        if ($message === '') {
            $message = $localStatus === 'cancelado'
                ? 'El pago fue cancelado o rechazado en PayPal.'
                : ($localStatus === 'pendiente'
                    ? 'La orden fue aprobada en PayPal y seguimos confirmando el resultado final.'
                    : 'El pago con PayPal fue procesado correctamente.');
        }
        echo paypal_popup_bridge_html(
            $localStatus === 'cancelado' ? 'Pago cancelado' : ($localStatus === 'pendiente' ? 'Pago aprobado en PayPal' : 'Pago procesado'),
            $message,
            $localStatus === 'cancelado' ? 'danger' : ($localStatus === 'pendiente' ? 'info' : 'success'),
            [
                'state' => $localStatus,
                'order_id' => (int) ($updatedOrder['id'] ?? 0),
                'paypal_order_id' => $paypalOrderId,
            ]
        );
    } catch (Throwable $e) {
        error_log('TVG PayPal return failed for order #' . (int) ($order['id'] ?? 0) . ': ' . $e->getMessage());
        http_response_code(500);
        echo paypal_popup_bridge_html('No se pudo confirmar el pago', paypal_pay_customer_message($e->getMessage(), (string) ($order['moneda'] ?? '')), 'danger', [
            'state' => 'error',
            'order_id' => (int) ($order['id'] ?? 0),
            'paypal_order_id' => $paypalOrderId,
        ]);
    }
    exit;
}

if ($action === 'paypal_cancel') {
    header('Content-Type: text/html; charset=UTF-8');

    $paypalOrderId = trim((string) ($_GET['token'] ?? ''));
    $order = $paypalOrderId !== '' ? find_local_order_by_paypal_identifiers($mysqli, $paypalOrderId, null) : null;

    if ($order && trim((string) ($order['estado'] ?? '')) === 'pendiente') {
        $cancelStatus = 'cancelado';
        $paypalStatus = 'cancelled';
        $paypalMessage = 'El cliente canceló el checkout desde PayPal.';
        $stmt = $mysqli->prepare("UPDATE pedidos SET paypal_status = ?, paypal_message = ?, estado = ? WHERE id = ? AND estado = 'pendiente' LIMIT 1");
        if ($stmt) {
            $orderId = (int) ($order['id'] ?? 0);
            $stmt->bind_param('sssi', $paypalStatus, $paypalMessage, $cancelStatus, $orderId);
            $stmt->execute();
            $stmt->close();
            $order = fetch_order_by_id($mysqli, $orderId) ?: $order;
        }
    }

    echo paypal_popup_bridge_html(
        'Pago cancelado',
        'Cancelaste el checkout de PayPal. Puedes volver a la tienda y elegir otro método si lo deseas.',
        'warning',
        [
            'state' => 'cancelado',
            'order_id' => (int) ($order['id'] ?? 0),
            'paypal_order_id' => $paypalOrderId,
        ]
    );
    exit;
}

if ($action === 'binance_notify') {
    if (!binance_pay_is_enabled()) {
        http_response_code(503);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'disabled';
        exit;
    }

    try {
        $payload = binance_pay_notify_payload($_POST);
    } catch (Throwable $e) {
        http_response_code(422);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'invalid';
        exit;
    }

    $order = find_local_order_by_binance_identifiers(
        $mysqli,
        binance_pay_extract_reference($payload),
        binance_pay_extract_order_no($payload),
        binance_pay_extract_request_id($payload)
    );

    if (!$order) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'missing';
        exit;
    }

    try {
        sync_local_order_with_binance_payload($mysqli, $order, $payload, 'webhook');
        http_response_code(200);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'success';
    } catch (Throwable $e) {
        error_log('TVG Binance Pay webhook failed for order #' . (int) ($order['id'] ?? 0) . ': ' . $e->getMessage());
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'error';
    }
    exit;
}

if ($action === 'update_status') {
    $adminRole = trim((string) ($_SESSION['auth_user']['rol'] ?? ''));
    if (!isset($_SESSION['auth_user']) || !in_array($adminRole, ['admin', 'empleado'], true)) {
        json_error('No autorizado', 403);
    }
    $order_id = intval($_POST['order_id'] ?? 0);
    $new_status = sanitize_str($_POST['estado'] ?? null, 20);
    $valid = ['pendiente','pagado','enviado','cancelado'];
    if (!$order_id || !in_array($new_status, $valid, true)) {
        json_error('Datos de estado inválidos');
    }

    $res = $mysqli->prepare('SELECT id, email, user_identifier, juego_nombre, paquete_nombre, paquete_cantidad, moneda, precio, estado, cupon FROM pedidos WHERE id=? LIMIT 1');
    $res->bind_param('i', $order_id);
    $res->execute();
    $order = $res->get_result()->fetch_assoc();
    if (!$order) {
        json_error('Pedido no encontrado', 404);
    }

    $stmt = $mysqli->prepare('UPDATE pedidos SET estado=? WHERE id=?');
    $stmt->bind_param('si', $new_status, $order_id);
    $stmt->execute();
    win_points_handle_order_status_change($mysqli, $order_id, $new_status);

    if (in_array($new_status, ['pagado', 'enviado'], true) && !in_array((string) ($order['estado'] ?? ''), ['pagado', 'enviado'], true)) {
        register_influencer_coupon_sale($mysqli, [
            'id' => $order_id,
            'cupon' => $order['cupon'] ?? null,
            'paquete_nombre' => $order['paquete_nombre'] ?? null,
            'moneda' => $order['moneda'] ?? null,
            'precio' => $order['precio'] ?? 0,
        ]);
    }

    if (in_array($new_status, ['pagado', 'enviado'], true)) {
        recharge_notifications_emit_for_order($mysqli, array_merge($order, [
            'id' => $order_id,
            'estado' => $new_status,
        ]));
    }

    $adminEmail = resolve_admin_email($mysqli);
    $statusLabel = ucfirst($new_status);
    $customerStatusMessage = '<p style="margin:0 0 10px;">El estado de tu pedido fue actualizado correctamente.</p>'
        . '<p style="margin:0;">Estado actual: <strong style="color:#22d3ee;">' . email_escape($statusLabel) . '</strong>.</p>';
    $adminStatusMessage = '<p style="margin:0 0 10px;">Se actualizó el estado de un pedido desde el panel administrativo.</p>'
        . '<p style="margin:0;">Estado actual: <strong style="color:#34d399;">' . email_escape($statusLabel) . '</strong>.</p>';
    $customerStatusHtml = render_order_email('Estado actualizado', 'Cliente', $customerStatusMessage, [
        'order_id' => $order_id,
        'game_name' => $order['juego_nombre'],
        'pack_name' => $order['paquete_nombre'],
        'pack_amount' => $order['paquete_cantidad'],
        'currency' => $order['moneda'],
        'price' => number_format((float) $order['precio'], 2, '.', ','),
        'user_identifier' => $order['user_identifier'],
        'email' => $order['email'],
        'status' => $statusLabel,
    ]);
    $adminStatusHtml = render_order_email('Pedido actualizado', 'Administrador', $adminStatusMessage, [
        'order_id' => $order_id,
        'game_name' => $order['juego_nombre'],
        'pack_name' => $order['paquete_nombre'],
        'pack_amount' => $order['paquete_cantidad'],
        'currency' => $order['moneda'],
        'price' => number_format((float) $order['precio'], 2, '.', ','),
        'user_identifier' => $order['user_identifier'],
        'email' => $order['email'],
        'coupon' => null,
        'status' => $statusLabel,
    ], '#34d399');
    $brandingImages = email_branding_embedded_images();
    send_app_mail($order['email'], "Estado actualizado #{$order_id}", $customerStatusHtml, null, $brandingImages);
    if ($adminEmail !== null) {
        send_app_mail($adminEmail, "Pedido #{$order_id} cambiado a {$new_status}", $adminStatusHtml, null, $brandingImages);
    }

    json_response(['ok' => true, 'message' => 'Estado actualizado', 'estado' => $new_status, 'order_id' => $order_id]);
}

json_error('Acción no soportada', 422);
?>
