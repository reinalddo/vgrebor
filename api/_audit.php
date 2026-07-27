<?php
/**
 * api/_audit.php — STUB de auditoría para Reborxstore.
 * El Gestor de Streaming llama audit_log() dentro de try/catch. Aquí es no-op.
 */

if (!function_exists('audit_log')) {
    function audit_log(PDO $pdo, string $accion, string $entidad = '', $entidadId = '', string $detalle = '', bool $alerta = false): void {
        // no-op
    }
}
