<?php
require_once __DIR__ . '/tenant.php';

// Conexión PDO para VirtualGaming
$tenantDatabase = tenant_database_config();
$host = (string) ($tenantDatabase['host'] ?? 'localhost');
$db   = (string) ($tenantDatabase['name'] ?? 'tvirtualgaming');
$user = (string) ($tenantDatabase['user'] ?? 'root');
$pass = (string) ($tenantDatabase['password'] ?? '');
$charset = (string) ($tenantDatabase['charset'] ?? 'utf8mb4');
$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];
try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
    die('Error de conexión a la base de datos: ' . $e->getMessage());
}

try {
    $roleColumnStmt = $pdo->query("SHOW COLUMNS FROM usuarios LIKE 'rol'");
    $roleColumn = $roleColumnStmt ? $roleColumnStmt->fetch() : false;
    $roleType = strtolower((string) ($roleColumn['Type'] ?? ''));
    if (
        $roleType !== ''
        && (
            strpos($roleType, "'empleado'") === false
            || strpos($roleType, "'influencer'") === false
            || strpos($roleType, "'root'") === false
        )
    ) {
        $pdo->exec("ALTER TABLE usuarios MODIFY rol ENUM('admin','usuario','empleado','influencer','root') NOT NULL DEFAULT 'usuario'");
    }

    $phoneColumnStmt = $pdo->query("SHOW COLUMNS FROM usuarios LIKE 'telefono'");
    $phoneColumn = $phoneColumnStmt ? $phoneColumnStmt->fetch() : false;
    if (!$phoneColumn) {
        $pdo->exec("ALTER TABLE usuarios ADD COLUMN telefono VARCHAR(50) NULL AFTER email");
    }

    $profileImageColumnStmt = $pdo->query("SHOW COLUMNS FROM usuarios LIKE 'foto_perfil'");
    $profileImageColumn = $profileImageColumnStmt ? $profileImageColumnStmt->fetch() : false;
    if (!$profileImageColumn) {
        $pdo->exec("ALTER TABLE usuarios ADD COLUMN foto_perfil VARCHAR(255) NULL AFTER telefono");
    }

    $lastPurchaseIdentifierColumnStmt = $pdo->query("SHOW COLUMNS FROM usuarios LIKE 'last_purchase_user_identifier'");
    $lastPurchaseIdentifierColumn = $lastPurchaseIdentifierColumnStmt ? $lastPurchaseIdentifierColumnStmt->fetch() : false;
    if (!$lastPurchaseIdentifierColumn) {
        $pdo->exec("ALTER TABLE usuarios ADD COLUMN last_purchase_user_identifier VARCHAR(150) NULL AFTER telefono");
    }

    $lastPurchasePhoneColumnStmt = $pdo->query("SHOW COLUMNS FROM usuarios LIKE 'last_purchase_phone'");
    $lastPurchasePhoneColumn = $lastPurchasePhoneColumnStmt ? $lastPurchasePhoneColumnStmt->fetch() : false;
    if (!$lastPurchasePhoneColumn) {
        $pdo->exec("ALTER TABLE usuarios ADD COLUMN last_purchase_phone VARCHAR(50) NULL AFTER last_purchase_user_identifier");
    }

    $extraFeatureColumns = [
        'mostrar_a_cliente' => "ALTER TABLE configuracion_general ADD COLUMN mostrar_a_cliente TINYINT(1) DEFAULT 0 NULL AFTER actualizado_en",
        'funcion_venta' => "ALTER TABLE configuracion_general ADD COLUMN funcion_venta VARCHAR(255) NULL AFTER mostrar_a_cliente",
        'descripcion_venta' => "ALTER TABLE configuracion_general ADD COLUMN descripcion_venta VARCHAR(255) NULL AFTER funcion_venta",
        'precio' => "ALTER TABLE configuracion_general ADD COLUMN precio INT NULL AFTER descripcion_venta",
        'comision_venta' => "ALTER TABLE configuracion_general ADD COLUMN comision_venta INT NULL AFTER precio",
    ];
    foreach ($extraFeatureColumns as $columnName => $alterSql) {
        $columnStmt = $pdo->query("SHOW COLUMNS FROM configuracion_general LIKE '" . str_replace("'", "''", $columnName) . "'");
        $column = $columnStmt ? $columnStmt->fetch() : false;
        if (!$column) {
            $pdo->exec($alterSql);
        }
    }
} catch (Throwable $exception) {
}
