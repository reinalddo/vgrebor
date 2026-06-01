<?php
require_once __DIR__ . '/tenant.php';

function create_app_mysqli_connection(): mysqli {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

    $tenantDatabase = tenant_database_config();
    $connection = new mysqli(
        (string) ($tenantDatabase['host'] ?? 'localhost'),
        (string) ($tenantDatabase['user'] ?? 'root'),
        (string) ($tenantDatabase['password'] ?? ''),
        (string) ($tenantDatabase['name'] ?? 'tvirtualgaming')
    );
    $connection->set_charset((string) ($tenantDatabase['charset'] ?? 'utf8mb4'));
    $connection->query("SET time_zone = '-04:00'");

    return $connection;
}

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

// Conexión a la base de datos para todos los módulos
$mysqli = create_app_mysqli_connection();
if ($mysqli->connect_errno) {
    die('Error de conexión: ' . $mysqli->connect_error);
}

$roleColumnResult = $mysqli->query("SHOW COLUMNS FROM usuarios LIKE 'rol'");
if ($roleColumnResult instanceof mysqli_result) {
    $roleColumn = $roleColumnResult->fetch_assoc();
    $roleColumnResult->free();
    $roleType = strtolower((string) ($roleColumn['Type'] ?? ''));
    if (
        $roleType !== ''
        && (
            strpos($roleType, "'empleado'") === false
            || strpos($roleType, "'influencer'") === false
            || strpos($roleType, "'root'") === false
        )
    ) {
        $mysqli->query("ALTER TABLE usuarios MODIFY rol ENUM('admin','usuario','empleado','influencer','root') NOT NULL DEFAULT 'usuario'");
    }
}

$phoneColumnResult = $mysqli->query("SHOW COLUMNS FROM usuarios LIKE 'telefono'");
if ($phoneColumnResult instanceof mysqli_result) {
    $phoneColumnExists = $phoneColumnResult->fetch_assoc();
    $phoneColumnResult->free();
    if (!$phoneColumnExists) {
        $mysqli->query("ALTER TABLE usuarios ADD COLUMN telefono VARCHAR(50) NULL AFTER email");
    }
}

$profileImageColumnResult = $mysqli->query("SHOW COLUMNS FROM usuarios LIKE 'foto_perfil'");
if ($profileImageColumnResult instanceof mysqli_result) {
    $profileImageColumnExists = $profileImageColumnResult->fetch_assoc();
    $profileImageColumnResult->free();
    if (!$profileImageColumnExists) {
        $mysqli->query("ALTER TABLE usuarios ADD COLUMN foto_perfil VARCHAR(255) NULL AFTER telefono");
    }
}

$lastPurchaseIdentifierColumnResult = $mysqli->query("SHOW COLUMNS FROM usuarios LIKE 'last_purchase_user_identifier'");
if ($lastPurchaseIdentifierColumnResult instanceof mysqli_result) {
    $lastPurchaseIdentifierColumnExists = $lastPurchaseIdentifierColumnResult->fetch_assoc();
    $lastPurchaseIdentifierColumnResult->free();
    if (!$lastPurchaseIdentifierColumnExists) {
        $mysqli->query("ALTER TABLE usuarios ADD COLUMN last_purchase_user_identifier VARCHAR(150) NULL AFTER telefono");
    }
}

$lastPurchasePhoneColumnResult = $mysqli->query("SHOW COLUMNS FROM usuarios LIKE 'last_purchase_phone'");
if ($lastPurchasePhoneColumnResult instanceof mysqli_result) {
    $lastPurchasePhoneColumnExists = $lastPurchasePhoneColumnResult->fetch_assoc();
    $lastPurchasePhoneColumnResult->free();
    if (!$lastPurchasePhoneColumnExists) {
        $mysqli->query("ALTER TABLE usuarios ADD COLUMN last_purchase_phone VARCHAR(50) NULL AFTER last_purchase_user_identifier");
    }
}

$extraFeatureColumns = [
    'mostrar_a_cliente' => "ALTER TABLE configuracion_general ADD COLUMN mostrar_a_cliente TINYINT(1) DEFAULT 0 NULL AFTER actualizado_en",
    'funcion_venta' => "ALTER TABLE configuracion_general ADD COLUMN funcion_venta VARCHAR(255) NULL AFTER mostrar_a_cliente",
    'descripcion_venta' => "ALTER TABLE configuracion_general ADD COLUMN descripcion_venta VARCHAR(255) NULL AFTER funcion_venta",
    'precio' => "ALTER TABLE configuracion_general ADD COLUMN precio INT NULL AFTER descripcion_venta",
    'comision_venta' => "ALTER TABLE configuracion_general ADD COLUMN comision_venta INT NULL AFTER precio",
];
foreach ($extraFeatureColumns as $columnName => $alterSql) {
    $columnResult = $mysqli->query("SHOW COLUMNS FROM configuracion_general LIKE '" . $mysqli->real_escape_string($columnName) . "'");
    if ($columnResult instanceof mysqli_result) {
        $columnExists = $columnResult->fetch_assoc();
        $columnResult->free();
        if (!$columnExists) {
            $mysqli->query($alterSql);
        }
    }
}
