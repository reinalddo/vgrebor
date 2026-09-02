<?php
/**
 * _conec_setup.php — CONFIGURA la API key de CONEC una sola vez y SE AUTOBORRA.
 *
 * Uso: sube este archivo a la raíz (public_html), ábrelo UNA vez en el navegador
 *      (https://TU-DOMINIO/_conec_setup.php) y desaparece solo. La llave queda guardada
 *      en la configuración del servidor (tabla configuracion_general), NO en el código.
 *
 * Si por lo que sea no se borra solo, BÓRRALO a mano después. No muestra la llave en pantalla.
 */
require __DIR__ . '/includes/db.php';   // $pdo (maneja el tenant)

$KEY  = 'rk_9ad2e68cec5d54471ca621be333edb3a53f56c8a';   // llave REAL del cliente
$BASE = 'https://coneclatam.com/api/reseller/v1';

$ok = true; $err = '';
try {
    $set = function (string $clave, string $valor) use ($pdo) {
        $pdo->prepare("INSERT INTO configuracion_general (clave, valor) VALUES (?, ?)
            ON DUPLICATE KEY UPDATE valor=VALUES(valor)")->execute([$clave, $valor]);
    };
    $set('conec_api_key', $KEY);
    $set('conec_base_url', $BASE);
    $set('conec_enabled', '1');
} catch (Throwable $e) { $ok = false; $err = $e->getMessage(); }

// Autoborrado (para no dejar la llave en un archivo accesible por web).
$borrado = @unlink(__FILE__);

header('Content-Type: text/html; charset=utf-8');
echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
   . '<div style="font-family:system-ui,Segoe UI,Roboto,sans-serif;max-width:520px;margin:60px auto;padding:0 20px;line-height:1.6;color:#0f141c">';
if ($ok) {
    echo '<h2>✅ CONEC configurado</h2>'
       . '<p>La llave real quedó guardada en la configuración del servidor y CONEC quedó <b>activo</b>.</p>'
       . '<p>' . ($borrado ? 'Este archivo <b>se borró solo</b>. ' : '⚠️ No pude borrarme solo: <b>elimina <code>_conec_setup.php</code> a mano</b> por seguridad. ')
       . 'Ya puedes entrar al panel → <b>Recargas CONEC</b>.</p>'
       . '<p style="color:#5c6674;font-size:13px">Recuerda cargar saldo en tu cuenta mayorista de coneclatam para que las recargas funcionen.</p>';
} else {
    echo '<h2>Error al configurar</h2><p>No se pudo guardar la configuración: ' . htmlspecialchars($err) . '</p>'
       . '<p>Puedes pegar la llave a mano en el panel: <b>Recargas CONEC → Configuración</b>. Y borra este archivo.</p>';
}
echo '</div>';
