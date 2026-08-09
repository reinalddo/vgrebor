<?php
/**
 * api/_rev_avisos.php — Avisos / historial de notificaciones del Gestor de Streaming.
 *
 * ANTES era un stub que no hacía nada. Ahora es el sistema real: cada movimiento importante
 * (recarga de saldo, compra del stock, venta, renovación, solicitud de cambio de perfil/PIN,
 * edición de una cuenta completa, cambio de credenciales) deja una NOTIFICACIÓN dirigida a un
 * destinatario:
 *    owner_id = 0  → el ADMIN / dueño de la tienda
 *    owner_id > 0  → ese REVENDEDOR
 *
 * Se ven en «Notificaciones» (admin/stream/notificaciones.php) con badge de no leídas.
 *
 * REGLA DE ORO: notificar NUNCA debe romper la operación que la generó. Todo va en try/catch y
 * devuelve false si falla; una notificación perdida no puede tumbar una venta o una recarga.
 */

if (!function_exists('stream_notif_schema')) {
    /** Crea la tabla si no existe (idempotente, cacheada por request). */
    function stream_notif_schema(PDO $pdo): bool {
        static $ok = null;
        if ($ok !== null) return $ok;
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS stream_notificaciones (
                id INT AUTO_INCREMENT PRIMARY KEY,
                owner_id INT NOT NULL DEFAULT 0,
                actor_id INT NULL,
                actor_nombre VARCHAR(120) NULL,
                tipo VARCHAR(40) NOT NULL,
                titulo VARCHAR(180) NOT NULL,
                mensaje TEXT NULL,
                url VARCHAR(255) NULL,
                ref_id INT NULL,
                leido TINYINT(1) NOT NULL DEFAULT 0,
                creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_owner_leido (owner_id, leido),
                INDEX idx_owner_fecha (owner_id, creado_en)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $ok = true;
        } catch (Throwable $e) { $ok = false; }
        return $ok;
    }
}

if (!function_exists('stream_notif_crear')) {
    /** Deja una notificación para $ownerDest (0=admin, >0=revendedor). Nunca lanza. */
    function stream_notif_crear(PDO $pdo, int $ownerDest, string $tipo, string $titulo, string $mensaje = '', string $url = '', ?int $actorId = null, ?int $refId = null): bool {
        if (!stream_notif_schema($pdo)) return false;
        try {
            $actorNombre = null;
            if ($actorId !== null && $actorId > 0) {
                try {
                    $st = $pdo->prepare("SELECT COALESCE(NULLIF(nombre,''), username, email) FROM usuarios WHERE id=?");
                    $st->execute([$actorId]);
                    $actorNombre = ($st->fetchColumn() ?: null);
                } catch (Throwable $e) { $actorNombre = null; }
            }
            $pdo->prepare("INSERT INTO stream_notificaciones (owner_id, actor_id, actor_nombre, tipo, titulo, mensaje, url, ref_id) VALUES (?,?,?,?,?,?,?,?)")
                ->execute([
                    $ownerDest,
                    ($actorId !== null && $actorId > 0) ? $actorId : null,
                    $actorNombre !== null ? mb_substr((string) $actorNombre, 0, 120) : null,
                    mb_substr($tipo, 0, 40),
                    mb_substr($titulo, 0, 180),
                    $mensaje !== '' ? $mensaje : null,
                    $url !== '' ? mb_substr($url, 0, 255) : null,
                    ($refId !== null && $refId > 0) ? $refId : null,
                ]);
            return true;
        } catch (Throwable $e) { return false; }
    }
}

if (!function_exists('stream_notif_listar')) {
    /** Historial del destinatario, más recientes primero. */
    function stream_notif_listar(PDO $pdo, int $owner, int $limit = 300, bool $soloNoLeidas = false): array {
        if (!stream_notif_schema($pdo)) return [];
        $limit = max(1, min(1000, $limit));
        try {
            $sql = "SELECT * FROM stream_notificaciones WHERE owner_id=?" . ($soloNoLeidas ? " AND leido=0" : "") . " ORDER BY id DESC LIMIT $limit";
            $st = $pdo->prepare($sql);
            $st->execute([$owner]);
            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) { return []; }
    }
}

if (!function_exists('stream_notif_no_leidas')) {
    function stream_notif_no_leidas(PDO $pdo, int $owner): int {
        if (!stream_notif_schema($pdo)) return 0;
        try {
            $st = $pdo->prepare("SELECT COUNT(*) FROM stream_notificaciones WHERE owner_id=? AND leido=0");
            $st->execute([$owner]);
            return (int) $st->fetchColumn();
        } catch (Throwable $e) { return 0; }
    }
}

if (!function_exists('stream_notif_marcar_leidas')) {
    /** Marca leídas: todas las del destinatario, o solo una ($id). Anti-IDOR: siempre filtra por owner. */
    function stream_notif_marcar_leidas(PDO $pdo, int $owner, ?int $id = null): void {
        if (!stream_notif_schema($pdo)) return;
        try {
            if ($id !== null && $id > 0) {
                $pdo->prepare("UPDATE stream_notificaciones SET leido=1 WHERE id=? AND owner_id=?")->execute([$id, $owner]);
            } else {
                $pdo->prepare("UPDATE stream_notificaciones SET leido=1 WHERE owner_id=? AND leido=0")->execute([$owner]);
            }
        } catch (Throwable $e) {}
    }
}

if (!function_exists('rev_aviso_crear')) {
    /** Compatibilidad con las llamadas que ya existían (ventas.php). $revendedorId = destinatario. */
    function rev_aviso_crear(PDO $pdo, int $revendedorId, string $tipo, string $titulo, string $mensaje, string $url = '/revendedor/'): bool {
        return stream_notif_crear($pdo, $revendedorId, $tipo, $titulo, $mensaje, $url);
    }
}

if (!function_exists('rev_avisar_credenciales')) {
    /** Avisa a los revendedores afectados por un cambio de credenciales de una cuenta del admin.
     *  Afectados = tienen una venta de esa cuenta, o una cuenta espejo en su stock que nació de ella. */
    function rev_avisar_credenciales(PDO $pdo, int $cuentaId, string $plataforma = ''): int {
        if ($cuentaId <= 0) return 0;
        $revs = [];
        try {
            $st = $pdo->prepare("SELECT DISTINCT revendedor_id FROM streaming_ventas WHERE cuenta_id=? AND COALESCE(revendedor_id,0)>0");
            $st->execute([$cuentaId]);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $r) { $revs[(int) $r] = true; }
        } catch (Throwable $e) {}
        try { // cuentas espejo del stock del revendedor (Fase 2)
            $st = $pdo->prepare("SELECT DISTINCT owner_id FROM streaming_cuentas WHERE origen_cuenta_id=? AND COALESCE(owner_id,0)>0");
            $st->execute([$cuentaId]);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $r) { $revs[(int) $r] = true; }
        } catch (Throwable $e) {}
        $plat = trim($plataforma) !== '' ? trim($plataforma) : 'una cuenta';
        $n = 0;
        foreach (array_keys($revs) as $rid) {
            if ($rid <= 0) continue;
            if (stream_notif_crear($pdo, $rid, 'credenciales',
                'Cambiaron los datos de acceso de ' . $plat,
                'El administrador actualizó el correo o la clave de ' . $plat . '. Revisa tus ventas y avísale a tus clientes.',
                'ventas.php')) { $n++; }
        }
        return $n;
    }
}
