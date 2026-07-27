<?php
/**
 * api/_rev_avisos.php — STUB de avisos a revendedores para Reborxstore.
 * El Gestor de Streaming lo incluye de forma perezosa (cuentas.php/ventas.php) para avisar a
 * revendedores afectados al cambiar credenciales o reemplazar una cuenta. Reborxstore aún no
 * tiene revendedores (Fase 2); estas funciones existen para no romper y no hacen nada.
 */

if (!function_exists('rev_avisar_credenciales')) {
    function rev_avisar_credenciales(PDO $pdo, int $cuentaId, string $plataforma = ''): int {
        return 0; // 0 revendedores avisados
    }
}

if (!function_exists('rev_aviso_crear')) {
    function rev_aviso_crear(PDO $pdo, int $revendedorId, string $tipo, string $titulo, string $mensaje, string $url = '/revendedor/'): bool {
        return false;
    }
}
