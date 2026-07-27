<?php
/**
 * admin/_streaming_schema.php — Esquema de las tablas del Gestor de Streaming.
 * Reproduce lo que en CONEC creaban admin/aplicar-migracion-streaming*.php y
 * aplicar-migracion-revendedor.php, en un solo ensure idempotente (CREATE TABLE IF NOT EXISTS
 * con TODAS las columnas que el código espera, incluidas las "perezosas").
 */
if (!function_exists('stream_ensure_schema')) {
    function stream_ensure_schema(PDO $pdo): void {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        $tables = [
            "CREATE TABLE IF NOT EXISTS streaming_plataformas (
                id INT AUTO_INCREMENT PRIMARY KEY,
                owner_id INT NOT NULL DEFAULT 0,
                nombre VARCHAR(60) NOT NULL,
                tipo ENUM('perfil','cuenta','ambos') NOT NULL DEFAULT 'ambos',
                categoria VARCHAR(40) NULL,
                etiqueta_comercial VARCHAR(60) NULL,
                color VARCHAR(9) NULL,
                emoji VARCHAR(8) NULL,
                logo_url VARCHAR(300) NULL,
                precio_sugerido DECIMAL(10,2) NULL,
                precio_publico DECIMAL(10,2) NULL,
                precio_distribuidor DECIMAL(10,2) NULL,
                costo DECIMAL(10,2) NULL,
                dias_default INT NOT NULL DEFAULT 30,
                condiciones_servicio VARCHAR(1000) NULL,
                renovable_manual TINYINT(1) NOT NULL DEFAULT 0,
                en_tienda TINYINT(1) NOT NULL DEFAULT 1,
                modo_entrega ENUM('perfil','email_manual') NOT NULL DEFAULT 'perfil',
                activo TINYINT(1) NOT NULL DEFAULT 1,
                orden INT NOT NULL DEFAULT 0,
                creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_activo (activo, orden)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS streaming_proveedores (
                id INT AUTO_INCREMENT PRIMARY KEY,
                owner_id INT NOT NULL DEFAULT 0,
                nombre VARCHAR(80) NOT NULL,
                contacto VARCHAR(120) NULL,
                contacto_tipo VARCHAR(10) NULL,
                metodo_pago VARCHAR(40) NULL,
                notas VARCHAR(300) NULL,
                activo TINYINT(1) NOT NULL DEFAULT 1,
                creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS streaming_clientes (
                id INT AUTO_INCREMENT PRIMARY KEY,
                owner_id INT NOT NULL DEFAULT 0,
                nombre VARCHAR(120) NOT NULL,
                wa VARCHAR(30) NULL,
                email VARCHAR(160) NULL,
                notas VARCHAR(300) NULL,
                tipo VARCHAR(20) NOT NULL DEFAULT 'cliente',
                recordatorios_auto TINYINT(1) NOT NULL DEFAULT 1,
                usuario_id INT NULL,
                wa_contacto_id BIGINT NULL,
                creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_wa (wa)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS streaming_cuentas (
                id INT AUTO_INCREMENT PRIMARY KEY,
                owner_id INT NOT NULL DEFAULT 0,
                plataforma VARCHAR(60) NOT NULL,
                plataforma_id INT NULL,
                proveedor_id INT NULL,
                correo VARCHAR(160) NULL,
                clave VARCHAR(160) NULL,
                perfiles_total INT NOT NULL DEFAULT 1,
                perfiles_extra INT NOT NULL DEFAULT 0,
                usa_pin TINYINT(1) NOT NULL DEFAULT 1,
                costo DECIMAL(10,2) NULL,
                costo_pagado TINYINT(1) NOT NULL DEFAULT 0,
                vencimiento DATE NULL,
                estado ENUM('activa','inactiva') NOT NULL DEFAULT 'activa',
                notas VARCHAR(300) NULL,
                pac_id INT NULL,
                creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_plat (plataforma, estado),
                INDEX idx_plat_id (plataforma_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS streaming_perfiles (
                id INT AUTO_INCREMENT PRIMARY KEY,
                cuenta_id INT NOT NULL,
                etiqueta VARCHAR(60) NULL,
                pin VARCHAR(20) NULL,
                estado ENUM('libre','vendido','inactivo') NOT NULL DEFAULT 'libre',
                venta_id INT NULL,
                creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_cuenta (cuenta_id, estado)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS streaming_ventas (
                id INT AUTO_INCREMENT PRIMARY KEY,
                owner_id INT NOT NULL DEFAULT 0,
                plataforma VARCHAR(60) NOT NULL,
                tipo ENUM('perfil','cuenta') NOT NULL DEFAULT 'perfil',
                cuenta_id INT NULL,
                perfil_id INT NULL,
                cliente_id INT NULL,
                cliente_nombre VARCHAR(120) NULL,
                cliente_wa VARCHAR(30) NULL,
                wa_contacto_id BIGINT NULL,
                usuario_id INT NULL,
                correo VARCHAR(160) NULL,
                clave VARCHAR(160) NULL,
                perfil VARCHAR(60) NULL,
                pin VARCHAR(20) NULL,
                precio DECIMAL(10,2) NULL,
                fecha_inicio DATE NULL,
                fecha_vencimiento DATE NOT NULL,
                estado ENUM('activa','vencida','renovada','cancelada') NOT NULL DEFAULT 'activa',
                recordado TINYINT NOT NULL DEFAULT 0,
                recordado_at DATETIME NULL,
                entregada TINYINT NOT NULL DEFAULT 0,
                notas VARCHAR(300) NULL,
                metodo_pago VARCHAR(40) NULL,
                referencia VARCHAR(80) NULL,
                comprobante_url VARCHAR(300) NULL,
                email_activar VARCHAR(160) NULL,
                precio_venta_cliente DECIMAL(10,2) NULL,
                estado_cobro VARCHAR(20) NOT NULL DEFAULT 'cobrado',
                api_merchant_ref VARCHAR(80) NULL,
                pac_id INT NULL,
                revendedor_id INT NULL,
                revendedor_cliente_id INT NULL,
                creado_por INT NULL,
                creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_venc (estado, fecha_vencimiento),
                INDEX idx_cuenta (cuenta_id),
                INDEX idx_cliente (cliente_id),
                INDEX idx_rev (revendedor_id, fecha_vencimiento),
                INDEX idx_wa (cliente_wa)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS streaming_tareas (
                id INT AUTO_INCREMENT PRIMARY KEY,
                usuario_id INT NOT NULL,
                texto VARCHAR(300) NOT NULL,
                es_recordatorio TINYINT NOT NULL DEFAULT 0,
                hecho TINYINT NOT NULL DEFAULT 0,
                creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_user (usuario_id, hecho)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS streaming_venta_registro (
                id INT AUTO_INCREMENT PRIMARY KEY,
                venta_id INT NOT NULL,
                evento VARCHAR(30) NOT NULL,
                descripcion VARCHAR(400) NULL,
                usuario_id INT NULL,
                creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_venta (venta_id, creado_en)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS streaming_venta_pagos (
                id INT AUTO_INCREMENT PRIMARY KEY,
                venta_id INT NOT NULL,
                tipo ENUM('venta','renovacion') NOT NULL DEFAULT 'venta',
                metodo VARCHAR(40) NULL,
                referencia VARCHAR(80) NULL,
                monto DECIMAL(10,2) NULL,
                meses INT NULL,
                comprobante_url VARCHAR(300) NULL,
                creado_por INT NULL,
                creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_venta (venta_id, creado_en)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS streaming_venta_notas (
                id INT AUTO_INCREMENT PRIMARY KEY,
                venta_id INT NOT NULL,
                nota VARCHAR(600) NOT NULL,
                autor_id INT NULL,
                creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_venta (venta_id, creado_en)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            // Config clave/valor que usa el módulo (stream_tpl_*, stream_cliente_rev_map, tasa_bcv).
            // Independiente de configuracion_general de Reborxstore.
            "CREATE TABLE IF NOT EXISTS configuracion (
                clave VARCHAR(64) NOT NULL PRIMARY KEY,
                valor TEXT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            // Billetera de revendedores: movimientos de saldo (compra de stock, recargas de saldo, ajustes).
            "CREATE TABLE IF NOT EXISTS wallet_movimientos (
                id INT AUTO_INCREMENT PRIMARY KEY,
                usuario_id INT NOT NULL,
                tipo VARCHAR(30) NOT NULL,
                monto DECIMAL(12,2) NOT NULL,
                descripcion VARCHAR(300) NULL,
                referencia VARCHAR(120) NULL,
                creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_user (usuario_id, creado_en)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            // Recargas de saldo iniciadas por el revendedor (top-up) — para la pasarela de pago.
            "CREATE TABLE IF NOT EXISTS wallet_recargas (
                id INT AUTO_INCREMENT PRIMARY KEY,
                usuario_id INT NOT NULL,
                monto DECIMAL(12,2) NOT NULL,
                metodo VARCHAR(30) NULL,
                referencia VARCHAR(160) NULL,
                pasarela_ref VARCHAR(160) NULL,
                estado ENUM('pendiente','aprobada','rechazada') NOT NULL DEFAULT 'pendiente',
                creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                resuelto_en DATETIME NULL,
                INDEX idx_user (usuario_id, estado),
                INDEX idx_pasarela (pasarela_ref)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ];

        foreach ($tables as $sql) {
            try {
                $pdo->exec($sql);
            } catch (Throwable $e) {
                // Nunca romper el request por el ensure de una tabla (p.ej. `configuracion` legacy).
                error_log('stream_ensure_schema: ' . $e->getMessage());
            }
        }

        // ── Fase 2: aislamiento por dueño (owner_id) en tablas ya existentes ──
        $ensureCol = function (string $table, string $col, string $alter) use ($pdo): void {
            try {
                $st = $pdo->query("SHOW COLUMNS FROM `$table` LIKE " . $pdo->quote($col));
                if ($st && $st->fetch() === false) {
                    $pdo->exec($alter);
                }
            } catch (Throwable $e) {
                error_log('stream_ensure_schema col ' . $table . '.' . $col . ': ' . $e->getMessage());
            }
        };
        $ensureIndex = function (string $table, string $index, string $alter) use ($pdo): void {
            try {
                $st = $pdo->query("SHOW INDEX FROM `$table` WHERE Key_name = " . $pdo->quote($index));
                if ($st && $st->fetch() === false) {
                    $pdo->exec($alter);
                }
            } catch (Throwable $e) {
                error_log('stream_ensure_schema idx ' . $table . '.' . $index . ': ' . $e->getMessage());
            }
        };

        foreach (['streaming_plataformas', 'streaming_proveedores', 'streaming_clientes', 'streaming_cuentas', 'streaming_ventas'] as $t) {
            $ensureCol($t, 'owner_id', "ALTER TABLE `$t` ADD COLUMN owner_id INT NOT NULL DEFAULT 0");
            $ensureIndex($t, 'idx_owner', "ALTER TABLE `$t` ADD INDEX idx_owner (owner_id)");
        }

        // Precio de renovación por venta (lo que se cobra al renovar; puede diferir del precio de venta).
        $ensureCol('streaming_ventas', 'precio_renovacion', "ALTER TABLE streaming_ventas ADD COLUMN precio_renovacion DECIMAL(10,2) NULL");

        // Billetera del revendedor: saldo en usuarios.
        $ensureCol('usuarios', 'saldo', "ALTER TABLE usuarios ADD COLUMN saldo DECIMAL(12,2) NOT NULL DEFAULT 0");
        // Referencia de la pasarela (id de orden PayPal/Binance) en recargas ya existentes.
        $ensureCol('wallet_recargas', 'pasarela_ref', "ALTER TABLE wallet_recargas ADD COLUMN pasarela_ref VARCHAR(160) NULL");
        // Precio de revendedor por paquete de recarga (lo fija el admin en admin/paquetes.php).
        try {
            $chk = $pdo->query("SHOW TABLES LIKE 'juego_paquetes'");
            if ($chk && $chk->fetch() !== false) {
                $ensureCol('juego_paquetes', 'precio_revendedor', "ALTER TABLE juego_paquetes ADD COLUMN precio_revendedor DECIMAL(12,2) NULL");
                // Vínculo de un producto de la tienda con una plataforma de streaming (venta automática).
                $ensureCol('juego_paquetes', 'streaming_plataforma_id', "ALTER TABLE juego_paquetes ADD COLUMN streaming_plataforma_id INT NULL");
            }
        } catch (Throwable $e) {}

        // API key por usuario (para que los revendedores vendan desde su propia página).
        try {
            $chk2 = $pdo->query("SHOW TABLES LIKE 'usuarios'");
            if ($chk2 && $chk2->fetch() !== false) {
                $ensureCol('usuarios', 'api_key', "ALTER TABLE usuarios ADD COLUMN api_key VARCHAR(64) NULL");
            }
        } catch (Throwable $e) {}

        // (seed de plataformas por revendedor: ver stream_seed_owner_platforms)

        // ── Rol 'revendedor' en usuarios (para el panel /revendedor/) ──
        try {
            $col = $pdo->query("SHOW COLUMNS FROM usuarios LIKE 'rol'");
            $row = $col ? $col->fetch() : false;
            $type = strtolower((string) ($row['Type'] ?? ''));
            if ($type !== '' && strpos($type, "'revendedor'") === false) {
                $pdo->exec("ALTER TABLE usuarios MODIFY rol ENUM('admin','usuario','empleado','influencer','root','revendedor') NOT NULL DEFAULT 'usuario'");
            }
        } catch (Throwable $e) {
            error_log('stream_ensure_schema rol revendedor: ' . $e->getMessage());
        }
    }
}

if (!function_exists('stream_seed_owner_platforms')) {
    /**
     * Siembra plataformas por defecto para un REVENDEDOR nuevo (owner > 0) cuya lista está vacía,
     * para que pueda cargar cuentas de inmediato. El admin (owner 0) crea las suyas a mano (no se toca).
     */
    function stream_seed_owner_platforms(PDO $pdo, int $owner): void {
        if ($owner <= 0) {
            return;
        }
        try {
            $st = $pdo->prepare("SELECT COUNT(*) FROM streaming_plataformas WHERE owner_id=?");
            $st->execute([$owner]);
            if ((int) $st->fetchColumn() > 0) {
                return;
            }
            $seed = [
                ['Netflix', '#e50914', 30], ['Disney+', '#113ccf', 30], ['Max', '#7b2ff7', 30],
                ['Prime Video', '#00a8e1', 30], ['Spotify', '#1db954', 30], ['Vix', '#f5b50a', 30],
                ['Crunchyroll', '#f47521', 30], ['Paramount+', '#0064ff', 30],
            ];
            $ins = $pdo->prepare("INSERT INTO streaming_plataformas (owner_id, nombre, color, dias_default, activo, orden) VALUES (?,?,?,?,1,?)");
            $orden = 0;
            foreach ($seed as $p) {
                $ins->execute([$owner, $p[0], $p[1], $p[2], $orden++]);
            }
        } catch (Throwable $e) {
            error_log('stream_seed_owner_platforms: ' . $e->getMessage());
        }
    }
}
