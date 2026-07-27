<?php
/**
 * admin/_panel_switch.php — STUB del conmutador de paneles.
 * En CONEC alternaba entre el panel de Recargas y el de Streaming. En Reborxstore el
 * Gestor de Streaming es un módulo dentro del admin, así que aquí solo mostramos un
 * acceso de vuelta al panel principal, en el mismo lugar del topbar.
 */
// Ir al panel principal por su URL amigable REAL (evita el 403 que da /admin como directorio).
$backHref = function_exists('app_path') ? app_path('/admin/dashboard') : '/admin/dashboard';
// Los revendedores no tienen panel principal de la tienda: para ellos, volver a su dashboard.
if (function_exists('stream_ctx') && stream_ctx() === 'revendedor') {
    $backHref = 'dashboard.php';
}
?>
<a href="<?= htmlspecialchars($backHref, ENT_QUOTES, 'UTF-8') ?>" class="iconbtn" title="Volver al panel principal"><i data-lucide="layout-grid"></i></a>
<?php
