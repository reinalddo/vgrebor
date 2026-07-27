<?php
/**
 * admin/stream/mi-api.php — «Mi API»: el revendedor ve/gestiona su API key para vender desde su
 * propia página (catálogo, saldo, comprar streaming, comprar recargas). Solo contexto revendedor.
 */
define('CONEC_ADMIN', true);
require __DIR__ . '/../_auth.php';
require __DIR__ . '/_layout.php';
admin_require_login();

if (stream_ctx() !== 'revendedor') { header('Location: dashboard.php'); exit; }
$pdo = db();
$uid = (int) current_user_id();

// Asegurar/gestionar la API key.
try { $pdo->exec("ALTER TABLE usuarios ADD COLUMN api_key VARCHAR(64) NULL"); } catch (Throwable $e) {}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (($_POST['accion'] ?? '') === 'regenerar') {
        $nueva = bin2hex(random_bytes(24));
        $pdo->prepare("UPDATE usuarios SET api_key=? WHERE id=?")->execute([$nueva, $uid]);
    }
    header('Location: mi-api.php'); exit;
}
$row = $pdo->prepare("SELECT api_key FROM usuarios WHERE id=?"); $row->execute([$uid]);
$apiKey = (string) ($row->fetchColumn() ?: '');
if ($apiKey === '') { // generar la primera vez
    $apiKey = bin2hex(random_bytes(24));
    $pdo->prepare("UPDATE usuarios SET api_key=? WHERE id=?")->execute([$apiKey, $uid]);
}
$endpoint = (function_exists('app_url') ? app_url('/api/revendedor/api.php') : '/api/revendedor/api.php');

stream_head('Mi API', 'mi-api');
?>
<div class="pagehd">
  <div><h1>Mi <span class="nm">API</span></h1><p>Vende desde tu propia página usando tu saldo. Autentícate con tu API key.</p></div>
</div>

<div class="card" style="padding:18px;margin-bottom:16px">
  <label style="display:block;font-size:12px;color:var(--muted);font-weight:600;margin-bottom:6px">Tu API key (secreta — no la compartas)</label>
  <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
    <input id="apik" value="<?= h($apiKey) ?>" readonly style="flex:1;min-width:260px;padding:9px;border:1px solid var(--border);border-radius:9px;background:var(--surface-2);color:var(--text);font-family:monospace">
    <button class="btn ghost" type="button" onclick="navigator.clipboard.writeText(document.getElementById('apik').value);this.innerHTML='<i data-lucide=&quot;check&quot;></i> Copiado';if(window.lucide)lucide.createIcons()"><i data-lucide="copy"></i> Copiar</button>
    <form method="post" style="display:inline" onsubmit="return confirm('¿Regenerar la key? La anterior dejará de funcionar.')">
      <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="accion" value="regenerar">
      <button class="btn ghost" style="color:var(--bad)" type="submit"><i data-lucide="refresh-cw"></i> Regenerar</button>
    </form>
  </div>
  <div style="margin-top:12px;font-size:12.5px;color:var(--muted)">Endpoint: <code style="background:var(--surface-2);padding:2px 6px;border-radius:6px"><?= h($endpoint) ?></code></div>
</div>

<div class="card" style="padding:0;overflow:hidden">
  <div class="card-hd" style="padding:12px 16px"><i data-lucide="code"></i><h2>Cómo usarla</h2></div>
  <div style="padding:16px;font-size:13px;line-height:1.6">
    <p style="margin:0 0 10px">Envía <b>POST</b> (o GET) al endpoint con <code>api_key</code> y <code>action</code>. Respuestas en JSON. Todo se paga con tu <b>saldo</b>.</p>
    <table class="dtable" style="width:100%">
      <thead><tr><th>action</th><th>Parámetros</th><th>Devuelve</th></tr></thead>
      <tbody style="font-family:monospace;font-size:12px">
        <tr><td><b>saldo</b></td><td>—</td><td>{ ok, saldo }</td></tr>
        <tr><td><b>catalogo</b></td><td>—</td><td>{ ok, streaming:[…], recargas:[…] } con tus precios</td></tr>
        <tr><td><b>comprar_streaming</b></td><td>plataforma_id</td><td>{ ok, correo, clave, perfil, pin, vencimiento, saldo }</td></tr>
        <tr><td><b>comprar_recarga</b></td><td>game_id, package_id, user_identifier, quantity?</td><td>{ ok, order_id, estado, codigo, saldo }</td></tr>
      </tbody>
    </table>
    <p style="margin:14px 0 6px;font-weight:700">Ejemplo (cURL):</p>
    <pre style="background:var(--surface-2);padding:12px;border-radius:9px;overflow:auto;font-size:11.5px"><code>curl -X POST "<?= h($endpoint) ?>" \
  -d "api_key=<?= h(substr($apiKey, 0, 12)) ?>..." \
  -d "action=catalogo"</code></pre>
    <p style="margin:8px 0 0;font-size:11.5px;color:var(--faint)">Nota: valida siempre el <code>ok</code>. Si tu saldo no alcanza, devuelve error 402. La key va secreta en tu servidor, nunca en el navegador del cliente.</p>
  </div>
</div>
<?php stream_foot();
