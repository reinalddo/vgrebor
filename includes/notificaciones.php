<?php
// Notificaciones internas para usuarios (clientes).
//
// ⚠️ Contexto importante: en este proyecto NO existía ningún sistema de
// notificaciones para clientes. `crear_notificacion()` en includes/helpers.php
// (línea ~221) esperaba una tabla `notificaciones` que nunca se creó — era
// código muerto heredado de la plantilla original. El sistema que sí existe
// (`stream_notificaciones` / api/_rev_avisos.php) es exclusivo del panel de
// revendedores, no sirve para clientes.
//
// Esta tabla se crea DELIBERADAMENTE compatible con la firma de ese helper
// (`INSERT INTO notificaciones (usuario_id, mensaje, url)`), para que si
// alguien llega a usarlo funcione en vez de romper. Las columnas extra
// (titulo, tipo, leido, creado_en) tienen valor por defecto.

if (!function_exists('notificaciones_ensure_schema')) {
    function notificaciones_ensure_schema(): void {
        static $initialized = false;
        if ($initialized) {
            return;
        }
        $initialized = true;

        if (trim((string) store_config_get('notificaciones_schema_version', '')) === '1') {
            return;
        }

        require_once __DIR__ . '/db_connect.php';
        global $mysqli;
        if (!($mysqli instanceof mysqli)) {
            return;
        }

        // InnoDB explícito: se escribe junto a movimientos de RE Coins dentro
        // de transacciones, y en MyISAM el rollback sería un no-op silencioso.
        $mysqli->query(
            "CREATE TABLE IF NOT EXISTS notificaciones (
                id INT AUTO_INCREMENT PRIMARY KEY,
                usuario_id INT NOT NULL,
                tipo VARCHAR(40) NOT NULL DEFAULT 'general',
                titulo VARCHAR(160) NOT NULL DEFAULT '',
                mensaje VARCHAR(600) NOT NULL,
                url VARCHAR(255) NOT NULL DEFAULT '#',
                leido TINYINT(1) NOT NULL DEFAULT 0,
                creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_notificaciones_usuario (usuario_id),
                INDEX idx_notificaciones_leido (usuario_id, leido)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        // Migración defensiva por si la tabla ya existía con otro esquema.
        $columnas = [
            'usuario_id' => "ALTER TABLE notificaciones ADD COLUMN usuario_id INT NOT NULL AFTER id",
            'tipo'       => "ALTER TABLE notificaciones ADD COLUMN tipo VARCHAR(40) NOT NULL DEFAULT 'general' AFTER usuario_id",
            'titulo'     => "ALTER TABLE notificaciones ADD COLUMN titulo VARCHAR(160) NOT NULL DEFAULT '' AFTER tipo",
            'mensaje'    => "ALTER TABLE notificaciones ADD COLUMN mensaje VARCHAR(600) NOT NULL AFTER titulo",
            'url'        => "ALTER TABLE notificaciones ADD COLUMN url VARCHAR(255) NOT NULL DEFAULT '#' AFTER mensaje",
            'leido'      => "ALTER TABLE notificaciones ADD COLUMN leido TINYINT(1) NOT NULL DEFAULT 0 AFTER url",
            'creado_en'  => "ALTER TABLE notificaciones ADD COLUMN creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER leido",
        ];
        $existentes = [];
        $resultado = $mysqli->query('SHOW COLUMNS FROM notificaciones');
        if ($resultado instanceof mysqli_result) {
            while ($row = $resultado->fetch_assoc()) {
                $existentes[$row['Field']] = true;
            }
        }
        foreach ($columnas as $columna => $sql) {
            if (!isset($existentes[$columna])) {
                $mysqli->query($sql);
            }
        }

        store_config_upsert('notificaciones_schema_version', '1', 'No tocar: marca que la tabla de notificaciones de clientes ya fue creada.');
    }
}

// Crea una notificación. NUNCA lanza: notificar no debe romper la operación
// que la generó (mismo criterio que stream_notif_crear del módulo de
// revendedores). Devuelve true si se guardó.
if (!function_exists('notificaciones_crear')) {
    function notificaciones_crear(mysqli $mysqli, int $usuarioId, string $titulo, string $mensaje, string $tipo = 'general', string $url = '#'): bool {
        if ($usuarioId <= 0 || trim($mensaje) === '') {
            return false;
        }
        try {
            notificaciones_ensure_schema();
            $stmt = $mysqli->prepare('INSERT INTO notificaciones (usuario_id, tipo, titulo, mensaje, url) VALUES (?, ?, ?, ?, ?)');
            if (!$stmt) {
                return false;
            }
            $titulo = mb_substr(trim($titulo), 0, 160, 'UTF-8');
            $mensaje = mb_substr(trim($mensaje), 0, 600, 'UTF-8');
            $tipo = mb_substr(trim($tipo) !== '' ? trim($tipo) : 'general', 0, 40, 'UTF-8');
            $url = mb_substr(trim($url) !== '' ? trim($url) : '#', 0, 255, 'UTF-8');
            $stmt->bind_param('issss', $usuarioId, $tipo, $titulo, $mensaje, $url);
            $stmt->execute();
            $stmt->close();
            return true;
        } catch (Throwable $e) {
            error_log('TVG notificaciones: no se pudo crear (usuario ' . $usuarioId . '): ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('notificaciones_listar')) {
    function notificaciones_listar(mysqli $mysqli, int $usuarioId, int $limite = 30): array {
        if ($usuarioId <= 0) {
            return [];
        }
        notificaciones_ensure_schema();
        $stmt = $mysqli->prepare('SELECT id, tipo, titulo, mensaje, url, leido, creado_en FROM notificaciones WHERE usuario_id = ? ORDER BY creado_en DESC, id DESC LIMIT ?');
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('ii', $usuarioId, $limite);
        $stmt->execute();
        $resultado = $stmt->get_result();

        $items = [];
        if ($resultado instanceof mysqli_result) {
            while ($row = $resultado->fetch_assoc()) {
                $items[] = [
                    'id' => (int) $row['id'],
                    'tipo' => (string) $row['tipo'],
                    'titulo' => (string) $row['titulo'],
                    'mensaje' => (string) $row['mensaje'],
                    'url' => (string) $row['url'],
                    'leido' => (int) $row['leido'] === 1,
                    'creado_en' => (string) $row['creado_en'],
                ];
            }
        }
        $stmt->close();
        return $items;
    }
}

if (!function_exists('notificaciones_no_leidas')) {
    function notificaciones_no_leidas(mysqli $mysqli, int $usuarioId): int {
        if ($usuarioId <= 0) {
            return 0;
        }
        notificaciones_ensure_schema();
        $stmt = $mysqli->prepare('SELECT COUNT(*) AS total FROM notificaciones WHERE usuario_id = ? AND leido = 0');
        if (!$stmt) {
            return 0;
        }
        $stmt->bind_param('i', $usuarioId);
        $stmt->execute();
        $total = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
        $stmt->close();
        return $total;
    }
}

if (!function_exists('notificaciones_marcar_leidas')) {
    function notificaciones_marcar_leidas(mysqli $mysqli, int $usuarioId): int {
        if ($usuarioId <= 0) {
            return 0;
        }
        notificaciones_ensure_schema();
        $stmt = $mysqli->prepare('UPDATE notificaciones SET leido = 1 WHERE usuario_id = ? AND leido = 0');
        if (!$stmt) {
            return 0;
        }
        $stmt->bind_param('i', $usuarioId);
        $stmt->execute();
        $afectadas = $stmt->affected_rows;
        $stmt->close();
        return max(0, $afectadas);
    }
}
