<?php
/**
 * api/wallet/_helpers.php — Billetera de saldo (para revendedores).
 * Saldo en usuarios.saldo + auditoría en wallet_movimientos. Débito ATÓMICO condicionado
 * (WHERE saldo >= monto) para que nunca quede negativo.
 */

if (!function_exists('wallet_saldo')) {
    function wallet_saldo(PDO $pdo, int $usuarioId): float {
        try {
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
