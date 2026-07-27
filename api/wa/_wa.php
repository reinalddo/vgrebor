<?php
/**
 * api/wa/_wa.php — STUB de WhatsApp para Reborxstore.
 * El Gestor de Streaming portado de CONEC incluye este archivo y llama wa_*(). Aquí
 * lo dejamos DESHABILITADO (sin CRM): la entrega de datos es solo por panel. Las
 * funciones existen para no romper, pero no envían nada.
 */

if (!function_exists('wa_norm')) {
    /** Normaliza un teléfono a solo dígitos (E.164 sin '+'). */
    function wa_norm(string $numero): string {
        $d = preg_replace('/\D+/', '', $numero) ?? '';
        return (string) $d;
    }
}

if (!function_exists('wa_disponible')) {
    function wa_disponible(): bool { return false; }
}

if (!function_exists('wa_send_text')) {
    function wa_send_text(string $to, string $texto, bool $preview = true): array {
        return ['ok' => false, 'wam_id' => null, 'error' => 'WhatsApp deshabilitado'];
    }
}

if (!function_exists('wa_upsert_contacto')) {
    function wa_upsert_contacto(PDO $pdo, $waid, ?string $pushName = null): int { return 0; }
}

if (!function_exists('wa_ventana_abierta')) {
    function wa_ventana_abierta(PDO $pdo, $contactoId): bool { return false; }
}

if (!function_exists('wa_guardar_mensaje')) {
    function wa_guardar_mensaje(PDO $pdo, $contactoId, $dir, array $m): int { return 0; }
}
