<?php
/**
 * admin/_panel_switch.php — STUB del conmutador de paneles.
 * En CONEC alternaba entre el panel de Recargas y el de Streaming. En Reborxstore el
 * Gestor de Streaming es un módulo dentro del admin, así que aquí solo mostramos un
 * acceso de vuelta al panel principal, en el mismo lugar del topbar.
 */
// Ir al panel principal por su URL amigable REAL (evita el 403 que da /admin como directorio).
$backHref = function_exists('app_path') ? app_path('/admin/dashboard') : '/admin/dashboard';
$backTitle = 'Volver al panel principal';
// Los revendedores NO tienen panel de admin: para ellos el botón lleva al INICIO de la tienda
// (para comprar/navegar), no a un dashboard que no usan.
if (function_exists('stream_ctx') && stream_ctx() === 'revendedor') {
    $backHref = function_exists('app_path') ? app_path('/') : '/';
    $backTitle = 'Ir a la tienda';
}
?>
<a href="<?= htmlspecialchars($backHref, ENT_QUOTES, 'UTF-8') ?>" class="iconbtn" title="<?= htmlspecialchars($backTitle, ENT_QUOTES, 'UTF-8') ?>"><i data-lucide="<?= (isset($backTitle) && $backTitle === 'Ir a la tienda') ? 'store' : 'layout-grid' ?>"></i></a>
<?php
