<?php
/**
 * api/wallet/_helpers.php — Billetera de saldo (para revendedores).
 * Saldo en usuarios.saldo + auditoría en wallet_movimientos. Débito ATÓMICO condicionado
 * (WHERE saldo >= monto) para que nunca quede negativo.
 */

if (!function_exists('wallet_ensure_schema')) {
    /** Garantiza que exista la columna usuarios.saldo y la tabla wallet_movimientos. Idempotente y
     *  cacheado por request. SIN esto, en un server donde la columna no existe, acreditar/debitar
     *  fallaban en silencio (la causa del "le doy acreditar y no le acredita nada"). */
    function wallet_ensure_schema(PDO $pdo): void {
        static $done = false;
        if ($done) return;
        // OJO (MySQL): ALTER/CREATE hacen COMMIT IMPLÍCITO. Si esto corre DENTRO de una transacción
        // (p.ej. la COMPRA, que descuenta saldo con wallet_debitar), la cierra a la mitad y el commit()
        // de después revienta con "There is no active transaction" (la compra igual quedaba a medias, y
        // por eso "no asigna" el bot). Si ya hay transacción activa, no tocamos el esquema aquí y NO
        // marcamos $done — se asegura al cargar la página (fuera de transacción), antes de comprar.
        if ($pdo->inTransaction()) return;
        $done = true;
        try { $pdo->exec("ALTER TABLE usuarios ADD COLUMN saldo DECIMAL(12,2) NOT NULL DEFAULT 0"); } catch (Throwable $e) {}
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS wallet_movimientos (
                id INT AUTO_INCREMENT PRIMARY KEY,
                usuario_id INT NOT NULL,
                tipo VARCHAR(40) NOT NULL,
                monto DECIMAL(12,2) NOT NULL DEFAULT 0,
                descripcion VARCHAR(255) NULL,
                referencia VARCHAR(120) NULL,
                creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_usuario (usuario_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Throwable $e) {}
    }
}

if (!function_exists('wallet_saldo')) {
    function wallet_saldo(PDO $pdo, int $usuarioId): float {
        try {
            wallet_ensure_schema($pdo);
            $st = $pdo->prepare("SELECT saldo FROM usuarios WHERE id = ?");
            $st->execute([$usuarioId]);
            return (float) ($st->fetchColumn() ?: 0);
        } catch (Throwable $e) {
            return 0.0;
        }
    }
}

if (!function_exists('wallet_acreditar')) {
    /** Acredita saldo (monto positivo) y registra el movimiento. */
    function wallet_acreditar(PDO $pdo, int $usuarioId, float $monto, string $tipo, string $descripcion, $referencia = null): void {
        $monto = round((float) $monto, 2);
        if ($monto <= 0) {
            return;
        }
        wallet_ensure_schema($pdo);
        $pdo->prepare("UPDATE usuarios SET saldo = COALESCE(saldo,0) + ? WHERE id = ?")->execute([$monto, $usuarioId]);
        $pdo->prepare("INSERT INTO wallet_movimientos (usuario_id, tipo, monto, descripcion, referencia) VALUES (?,?,?,?,?)")
            ->execute([$usuarioId, $tipo, $monto, $descripcion, $referencia !== null ? (string) $referencia : null]);
    }
}

if (!function_exists('wallet_debitar')) {
    /** Debita saldo (monto positivo) SOLO si alcanza. Devuelve false si no hay saldo suficiente. */
    function wallet_debitar(PDO $pdo, int $usuarioId, float $monto, string $tipo, string $descripcion, $referencia = null): bool {
        $monto = round((float) $monto, 2);
        if ($monto <= 0) {
            return true;
        }
        wallet_ensure_schema($pdo);
        $st = $pdo->prepare("UPDATE usuarios SET saldo = saldo - ? WHERE id = ? AND COALESCE(saldo,0) >= ?");
        $st->execute([$monto, $usuarioId, $monto]);
        if ($st->rowCount() < 1) {
            return false; // saldo insuficiente
        }
        $pdo->prepare("INSERT INTO wallet_movimientos (usuario_id, tipo, monto, descripcion, referencia) VALUES (?,?,?,?,?)")
            ->execute([$usuarioId, $tipo, -$monto, $descripcion, $referencia !== null ? (string) $referencia : null]);
        return true;
    }
}
