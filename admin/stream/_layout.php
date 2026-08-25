<?php
/**
 * Shell del Gestor de Streaming — SISTEMA DE DISEÑO ÍNDIGO (igual al panel de recargas).
 * Sidebar claro + topbar + tema claro/oscuro (compartido con el panel de recargas vía la misma
 * clave localStorage 'cc_admin_theme'). Lo usan todas las páginas de admin/stream/.
 * Uso:  stream_head('Título', 'clave-activa');  ... contenido ...  stream_foot();
 *
 * Nota: se mantiene Tailwind (las páginas usan utilidades para el layout). La marca turquesa
 * se remapeó a índigo (--accent) y quedan clases de compatibilidad (btn-brand/grad-brand/
 * text-brand/text-brand-teal) mapeadas a índigo para no romper páginas aún sin migrar.
 */
if (!defined('CONEC_ADMIN')) { http_response_code(403); exit('Acceso denegado'); }

/** Menú lateral — solo las secciones que el dueño pidió (Dashboard, Ventas, Clientes, Plataformas). */
function stream_nav_items(): array {
  $esRev = function_exists('stream_ctx') && stream_ctx() === 'revendedor';
  // Contador de activaciones manuales pendientes (Canva/correo) para el badge del menú.
  // SCOPE por owner del contexto: así el badge cuadra con lo que muestra pendientes.php (antes contaba
  // la venta del admin Y la del revendedor → decía 2 cuando era 1). El ADMIN aprueba; el REVENDEDOR
  // solo VE si las suyas están aprobadas o pendientes (vista de solo lectura).
  $ownPa = (int) (function_exists('stream_owner_id') ? stream_owner_id() : 0);
  $pa = 0;
  try { $pa = (int) db()->query("SELECT COUNT(*) FROM streaming_ventas WHERE owner_id=$ownPa AND entregada=0 AND estado<>'cancelada' AND email_activar IS NOT NULL AND email_activar<>''")->fetchColumn(); } catch (Throwable $e) {}
  $items = [
    ['key' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'layout-dashboard', 'href' => 'dashboard.php'],
  ];
  if ($esRev) {
    // Solo revendedores: comprar del stock de la tienda + su billetera.
    $items[] = ['key' => 'comprar', 'label' => 'Comprar del stock', 'icon' => 'shopping-bag', 'href' => 'comprar.php'];
    $items[] = ['key' => 'saldo', 'label' => 'Mi saldo', 'icon' => 'wallet', 'href' => 'saldo.php'];
  }
  $items = array_merge($items, [
    ['key' => 'ventas', 'label' => 'Ventas' . ($pa ? " ($pa)" : ''), 'icon' => 'shopping-cart', 'href' => 'ventas.php', 'sub' => array_values(array_filter([
      ['key' => 'ventas', 'label' => 'Todas las Ventas', 'href' => 'ventas.php'],
      // ADMIN: activa/aprueba. REVENDEDOR: solo VE si las suyas están aprobadas o pendientes.
      ['key' => 'ventas-pendientes', 'label' => ($esRev ? 'Pendientes' : 'Pendientes de aprobar') . ($pa ? " ($pa)" : ''), 'href' => 'pendientes.php'],
      ['key' => 'ventas-vencimientos', 'label' => 'Vencimientos', 'href' => 'ventas.php?f=vencidas'],
    ]))],
    ['key' => 'clientes', 'label' => 'Clientes', 'icon' => 'users', 'href' => 'clientes.php'],
    ['key' => 'plataformas', 'label' => 'Plataformas', 'icon' => 'layers', 'href' => 'cuentas.php', 'sub' => array_values(array_filter([
      ['key' => 'cuentas', 'label' => 'Cuentas', 'href' => 'cuentas.php'],
      ['key' => 'perfiles', 'label' => 'Perfiles', 'href' => 'perfiles.php'],
      ['key' => 'tipos', 'label' => 'Tipos de Plataforma', 'href' => 'tipos.php'],
      (function_exists('admin_es_admin') && admin_es_admin()) ? ['key' => 'proveedores', 'label' => 'Proveedores', 'href' => 'proveedores.php'] : null,
    ]))],
  ]);
  if (!$esRev && function_exists('admin_es_admin') && admin_es_admin()) {
    $items[] = ['key' => 'revendedores', 'label' => 'Revendedores', 'icon' => 'store', 'href' => 'revendedores.php'];
    $items[] = ['key' => 'precios-recargas', 'label' => 'Precios recargas', 'icon' => 'tags', 'href' => 'precios-recargas.php'];
  }
  if ($esRev) {
    // Lista simple de SUS revendedores (solo el revendedor la ve; el admin tiene su propia gestión aparte).
    $items[] = ['key' => 'mis-revendedores', 'label' => 'Revendedores', 'icon' => 'store', 'href' => 'misrevendedores.php'];
    $items[] = ['key' => 'recargas', 'label' => 'Recargas', 'icon' => 'zap', 'href' => 'recargas.php'];
    $items[] = ['key' => 'mi-api', 'label' => 'Mi API', 'icon' => 'code', 'href' => 'mi-api.php'];
  }
  // Soporte / Tickets (nuevo): el revendedor abre tickets; el admin los ve/resuelve. Badge = pendientes
  // que le tocan al viewer (admin = todos los pendientes; revendedor = los suyos pendientes).
  require_once __DIR__ . '/../../api/_rev_avisos.php';
  if (function_exists('st_tickets_schema')) { try { st_tickets_schema(db()); } catch (Throwable $e) {} }
  $tk = 0;
  try {
    $sqlTk = $esRev ? "SELECT COUNT(*) FROM streaming_tickets WHERE owner_id=$ownPa AND estado='pendiente'"
                    : "SELECT COUNT(*) FROM streaming_tickets WHERE estado='pendiente'";
    $tk = (int) db()->query($sqlTk)->fetchColumn();
  } catch (Throwable $e) { $tk = 0; }
  $items[] = ['key' => 'tickets', 'label' => 'Soporte' . ($tk ? " ($tk)" : ''), 'icon' => 'life-buoy', 'href' => 'tickets.php'];
  // Historial de notificaciones/cambios (Fase 3), con badge de no leídas. Va al final para todos.
  $nn = 0;
  try {
    if (function_exists('stream_notif_no_leidas')) $nn = (int) stream_notif_no_leidas(db(), (int) stream_owner_id());
  } catch (Throwable $e) { $nn = 0; }
  $items[] = ['key' => 'notificaciones', 'label' => 'Notificaciones' . ($nn ? " ($nn)" : ''), 'icon' => 'bell', 'href' => 'notificaciones.php'];
  return $items;
}

/** Lee un valor de la config de la tienda (tabla configuracion_general). Cachea por request. */
function stream_store_cfg(string $clave, string $default = ''): string {
  static $cache = [];
  if (array_key_exists($clave, $cache)) return $cache[$clave];
  $val = $default;
  try {
    $st = db()->prepare("SELECT valor FROM configuracion_general WHERE clave = ? LIMIT 1");
    $st->execute([$clave]);
    $v = $st->fetchColumn();
    if ($v !== false && $v !== null && trim((string) $v) !== '') { $val = (string) $v; }
  } catch (Throwable $e) {}
  return $cache[$clave] = $val;
}

function stream_head(string $title, string $active = '', bool $fullBleed = false): void {
  // GATE de PAUSA: un revendedor DESACTIVADO (rev_activo=0) NO puede usar su panel — se le muestra un
  // aviso y no se carga la página. Conserva todos sus datos; solo el admin lo reactiva. (Antes la pausa
  // solo bloqueaba comprar/recargar; el cliente pidió que "desactivar" corte el acceso completo.)
  if (function_exists('stream_ctx') && stream_ctx() === 'revendedor') {
    $oid = (int) (function_exists('stream_owner_id') ? stream_owner_id() : 0);
    $pausado = false;
    if ($oid > 0) { try { $pausado = ((int) (db()->query("SELECT COALESCE(rev_activo,1) FROM usuarios WHERE id=$oid")->fetchColumn() ?? 1)) === 0; } catch (Throwable $e) { $pausado = false; } }
    if ($pausado) {
      http_response_code(403);
      echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Cuenta en pausa</title></head>'
         . '<body style="font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;background:#0b0f16;color:#e7eaf0;display:grid;place-items:center;min-height:100vh;margin:0">'
         . '<div style="max-width:430px;text-align:center;padding:32px"><div style="font-size:46px">⏸</div>'
         . '<h1 style="font-size:21px;margin:.4em 0">Tu cuenta está en pausa</h1>'
         . '<p style="color:#98a2b3;line-height:1.65">El administrador pausó temporalmente tu acceso al panel. Tus cuentas, ventas y saldo se conservan intactos. Contáctalo para reactivarla.</p>'
         . '<p style="margin-top:22px"><a href="/logout.php" style="color:#8ea2ff">Cerrar sesión</a></p></div></body></html>';
      exit;
    }
  }
  $u = function_exists('get_current_user_data') ? (get_current_user_data() ?: []) : [];
  $nombre = (string) ($u['nombre'] ?? 'Admin');
  $primer = trim(explode(' ', trim($nombre))[0] ?? 'Admin'); if ($primer === '') $primer = 'Admin';
  $ini = mb_strtoupper(mb_substr($nombre, 0, 1));
  // Marca de la TIENDA (no CONEC): nombre, logo y WhatsApp desde la config de la tienda.
  $storeName = stream_store_cfg('nombre_tienda', 'Streaming');
  $storeLogo = stream_store_cfg('logo_tienda', '');
  if ($storeLogo === '') { $storeLogo = '/apple-touch-icon.png'; }
  $storeWaDigits = preg_replace('/\D+/', '', stream_store_cfg('whatsapp', ''));
  ?><!DOCTYPE html>
<html lang="es" data-theme="light"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex">
<title><?= h($title) ?> · <?= h($storeName) ?> Streaming</title>
<script>/* tema temprano, sin parpadeo */(function(){try{if(localStorage.getItem('cc_admin_theme')==='dark')document.documentElement.setAttribute('data-theme','dark');}catch(e){}})();</script>
<script src="https://cdn.tailwindcss.com"></script>
<script>tailwind.config={theme:{extend:{colors:{brand:{DEFAULT:'#3f4fb5',teal:'#3f4fb5',dark:'#33409a'}}}}}</script>
<script src="/js/lucide.min.js?v=1"></script>
<style>
  :root{
    --bg:#f4f6f9;--surface:#fff;--surface-2:#f9fafc;--sidebar:#fff;
    --border:#e7eaf0;--border-strong:#d6dbe4;
    --text:#0f141c;--muted:#5c6674;--faint:#929bab;
    --accent:#3f4fb5;--accent-2:#5866c9;--accent-soft:#eceefb;--accent-ring:rgba(63,79,181,.28);
    --good:#12915a;--good-soft:#e2f4ec;--warn:#b0791a;--warn-soft:#f8efdb;--bad:#cf3a53;--bad-soft:#fbe6ea;
    --err:#cf3a53;--acc:#3f4fb5;
    --shadow:0 1px 2px rgba(16,22,35,.04),0 6px 20px -12px rgba(16,22,35,.14);
    --font:system-ui,-apple-system,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;--sb:248px;
  }
  [data-theme="dark"]{
    --bg:#0c0e13;--surface:#14171f;--surface-2:#181c25;--sidebar:#111420;
    --border:#232834;--border-strong:#2e3441;
    --text:#e6e9f0;--muted:#98a1b1;--faint:#646d7d;
    --accent:#7f8cf5;--accent-2:#93a0ff;--accent-soft:rgba(127,140,245,.14);--accent-ring:rgba(127,140,245,.4);
    --good:#33c98a;--good-soft:rgba(51,201,138,.14);--warn:#e0a94a;--warn-soft:rgba(224,169,74,.14);--bad:#f4657f;--bad-soft:rgba(244,101,127,.15);
    --err:#f4657f;--acc:#7f8cf5;
    --shadow:0 1px 2px rgba(0,0,0,.3),0 10px 30px -18px rgba(0,0,0,.7);
  }
  *{box-sizing:border-box}
  html,body{background:var(--bg)}
  body{margin:0;color:var(--text);font-family:var(--font);font-size:13.5px;line-height:1.5;-webkit-font-smoothing:antialiased}
  a{color:inherit;text-decoration:none}
  .tnum{font-variant-numeric:tabular-nums}
  .thin::-webkit-scrollbar{width:6px;height:6px}.thin::-webkit-scrollbar-thumb{background:var(--border-strong);border-radius:9px}
  /* App shell */
  .app{display:grid;grid-template-columns:var(--sb) 1fr;min-height:100vh}
  .sb{background:var(--sidebar);border-right:1px solid var(--border);display:flex;flex-direction:column;position:sticky;top:0;height:100vh;z-index:30}
  .sb-head{display:flex;align-items:center;gap:10px;padding:14px 16px;border-bottom:1px solid var(--border)}
  .logo{width:30px;height:30px;border-radius:8px;background:linear-gradient(135deg,var(--accent),var(--accent-2));display:grid;place-items:center;color:#fff;font-weight:800;font-size:13px}
  .sb-brand{font-weight:750;font-size:14.5px;letter-spacing:-.2px;color:var(--text)}.sb-brand small{display:block;font-weight:500;font-size:10.5px;color:var(--faint)}
  .sb-nav{flex:1;overflow-y:auto;padding:8px 10px 24px}
  .grp-label{color:var(--faint);font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;padding:12px 10px 5px}
  .lnk{display:flex;align-items:center;gap:10px;padding:9px 10px;border-radius:8px;color:var(--muted);font-weight:500;font-size:13px;position:relative;transition:.12s;cursor:pointer}
  .lnk:hover{background:var(--surface-2);color:var(--text)}
  .lnk.on{background:var(--accent-soft);color:var(--accent);font-weight:650}
  .lnk.on::before{content:"";position:absolute;left:-10px;top:6px;bottom:6px;width:3px;border-radius:0 3px 3px 0;background:var(--accent)}
  .lnk [data-lucide]{width:17px;height:17px;stroke-width:1.9}
  .lnk .chev{margin-left:auto;width:15px;height:15px;transition:.15s}.lnk .chev.open{transform:rotate(180deg)}
  .subnav{display:flex;flex-direction:column;gap:1px;padding-left:30px;margin-top:1px}
  .subnav a{display:block;padding:7px 10px;border-radius:7px;color:var(--muted);font-size:12.5px;font-weight:500}
  .subnav a:hover{background:var(--surface-2);color:var(--text)}
  .subnav a.on{color:var(--accent);font-weight:650}
  .sb-foot{padding:12px 16px;border-top:1px solid var(--border);font-size:10.5px;color:var(--faint)}
  /* Main */
  .main{min-width:0;display:flex;flex-direction:column}
  .top{position:sticky;top:0;z-index:20;background:color-mix(in srgb,var(--bg) 85%,transparent);backdrop-filter:blur(10px);border-bottom:1px solid var(--border);display:flex;align-items:center;gap:12px;padding:11px 20px}
  .iconbtn{width:36px;height:36px;border-radius:9px;border:1px solid var(--border);background:var(--surface);color:var(--muted);display:grid;place-items:center;cursor:pointer;transition:.12s}
  .iconbtn:hover{border-color:var(--border-strong);color:var(--text)}
  .iconbtn [data-lucide]{width:18px;height:18px}
  .hamb{display:none}
  .crumb{display:flex;align-items:center;gap:7px;color:var(--faint);font-size:12px;min-width:0}.crumb b{color:var(--text);font-size:16px;font-weight:720;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  .tbtns{margin-left:auto;display:flex;align-items:center;gap:8px}
  .user{display:flex;align-items:center;gap:9px;padding:4px 12px 4px 4px;border:1px solid var(--border);border-radius:30px;background:var(--surface)}
  .ava{width:28px;height:28px;border-radius:50%;background:linear-gradient(135deg,#6b74d8,#9aa2ee);color:#fff;display:grid;place-items:center;font-weight:800;font-size:12px}
  .user b{font-size:12.5px}.user span{display:block;font-size:10.5px;color:var(--faint)}
  main.wrap{flex:1;padding:22px 26px;width:100%}
  main.wrap.bleed{padding:0;overflow:hidden}
  @media(max-width:640px){main.wrap{padding:16px}}
  /* Sidebar móvil (off-canvas) */
  .sb-ov{position:fixed;inset:0;background:rgba(4,7,13,.5);z-index:29;opacity:0;pointer-events:none;transition:.2s}
  @media(max-width:900px){
    .app{grid-template-columns:1fr}
    .sb{position:fixed;left:0;top:0;height:100dvh;width:250px;transform:translateX(-100%);transition:transform .22s ease;box-shadow:0 0 46px rgba(8,12,20,.4)}
    .sb.open{transform:none}
    .sb-ov.open{opacity:1;pointer-events:auto}
    .hamb{display:grid}
  }
  /* ── Componentes del sistema de diseño (para páginas migradas) ── */
  .pagehd{display:flex;align-items:flex-end;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:18px}
  .pagehd h1{margin:0;font-size:22px;font-weight:760;letter-spacing:-.4px}.pagehd h1 .nm{color:var(--accent)}
  .pagehd p{margin:4px 0 0;color:var(--muted);font-size:13px}
  .datechip{display:inline-flex;align-items:center;gap:7px;font-size:12.5px;color:var(--muted);background:var(--surface);border:1px solid var(--border);border-radius:9px;padding:8px 12px}
  .datechip [data-lucide]{width:15px;height:15px;color:var(--accent)}
  .btn{display:inline-flex;align-items:center;gap:7px;border-radius:9px;padding:9px 14px;font-weight:650;font-size:12.5px;cursor:pointer;border:1px solid transparent;transition:.12s}
  .btn [data-lucide]{width:15px;height:15px}
  .btn.primary{background:var(--accent);color:#fff}.btn.primary:hover{background:var(--accent-2)}
  .btn.ghost{background:var(--surface);border-color:var(--border);color:var(--text)}.btn.ghost:hover{border-color:var(--border-strong)}
  .btn.danger{background:var(--bad);color:#fff}
  .kpis{display:grid;grid-template-columns:repeat(5,1fr);gap:12px}
  @media(max-width:1100px){.kpis{grid-template-columns:repeat(3,1fr)}}
  @media(max-width:640px){.kpis{grid-template-columns:repeat(2,1fr)}}
  .kpi{background:var(--surface);border:1px solid var(--border);border-radius:13px;padding:14px 15px;box-shadow:var(--shadow);position:relative;overflow:hidden}
  .kpi .k-top{display:flex;align-items:center;gap:8px;color:var(--muted);font-size:11.5px;font-weight:600}
  .kpi .k-ic{width:26px;height:26px;border-radius:7px;background:var(--surface-2);display:grid;place-items:center;color:var(--muted)}
  .kpi .k-ic [data-lucide]{width:15px;height:15px}
  .kpi h3{margin:9px 0 2px;font-size:23px;font-weight:770;letter-spacing:-.6px}
  .kpi .k-sub{font-size:11.5px;color:var(--faint)}
  .kpi.acc .k-ic{background:var(--accent-soft);color:var(--accent)}
  .kpi.flag{border-color:color-mix(in srgb,var(--bad) 45%,var(--border))}.kpi.flag .k-ic{background:var(--bad-soft);color:var(--bad)}
  .delta{font-size:11px;font-weight:750;padding:2px 6px;border-radius:6px;display:inline-flex;align-items:center;gap:3px}
  .delta.up{color:var(--good);background:var(--good-soft)}.delta.dn{color:var(--bad);background:var(--bad-soft)}
  .card{background:var(--surface);border:1px solid var(--border);border-radius:14px;box-shadow:var(--shadow);overflow:hidden}
  .card-hd{display:flex;align-items:center;gap:10px;padding:14px 16px;border-bottom:1px solid var(--border)}
  .card-hd [data-lucide]{width:16px;height:16px;color:var(--muted)}
  .card-hd h2{margin:0;font-size:14px;font-weight:700}
  .card-hd .pill-count{font-size:11px;font-weight:700;color:var(--muted);background:var(--surface-2);border:1px solid var(--border);border-radius:20px;padding:2px 9px}
  .card-hd a.more{margin-left:auto;color:var(--accent);font-size:12px;font-weight:650}
  .card-bd{padding:16px}
  .dtable{width:100%;border-collapse:collapse}
  .dtable th{text-align:left;font-size:10.5px;text-transform:uppercase;letter-spacing:.05em;color:var(--faint);font-weight:700;padding:9px 16px;border-bottom:1px solid var(--border);background:var(--surface-2)}
  .dtable td{padding:11px 16px;border-bottom:1px solid var(--border);font-size:12.8px;vertical-align:middle}
  .dtable tr:last-child td{border-bottom:0}.dtable tbody tr:hover{background:var(--surface-2)}
  .cli{display:flex;flex-direction:column}.cli b{font-weight:600}.cli span{font-size:11px;color:var(--faint)}
  .pill{display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:700;padding:3px 9px;border-radius:20px}
  .pill::before{content:"";width:6px;height:6px;border-radius:50%;background:currentColor}
  .pill.ok{color:var(--good);background:var(--good-soft)}.pill.wait{color:var(--warn);background:var(--warn-soft)}.pill.err{color:var(--bad);background:var(--bad-soft)}.pill.acc{color:var(--accent);background:var(--accent-soft)}
  .amt{font-weight:700;color:var(--good);font-variant-numeric:tabular-nums}
  .tag{font-size:11px;font-weight:600;color:var(--muted);background:var(--surface-2);border:1px solid var(--border);border-radius:6px;padding:2px 7px}
  .field{display:flex;flex-direction:column;gap:5px;margin-bottom:12px}
  .field label{font-size:11.5px;font-weight:650;color:var(--muted)}
  .input,.dtable input,select.input{width:100%;border:1px solid var(--border);border-radius:9px;padding:9px 11px;font-size:13px;background:var(--surface);color:var(--text);font-family:inherit}
  .input:focus{outline:none;border-color:var(--accent);box-shadow:0 0 0 3px var(--accent-ring)}
  .empty{padding:26px 16px;text-align:center;color:var(--faint);font-size:12.5px}
  .banner{display:flex;gap:12px;align-items:center;background:var(--accent-soft);border:1px solid color-mix(in srgb,var(--accent) 26%,transparent);border-radius:12px;padding:13px 16px;font-size:13px;color:var(--muted)}
  .banner [data-lucide]{width:20px;height:20px;color:var(--accent);flex:0 0 auto}.banner b{color:var(--text)}
  /* Grids de tarjetas */
  .cols{display:grid;grid-template-columns:1.6fr 1fr;gap:16px}@media(max-width:1100px){.cols{grid-template-columns:1fr}}
  .cols3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px}@media(max-width:1100px){.cols3{grid-template-columns:1fr}}
  /* Lista simple (últimas ventas, etc.) */
  .lrow{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:10px 16px;border-bottom:1px solid var(--border);font-size:13px}
  .lrow:last-child{border-bottom:0}.card .lrow:hover{background:var(--surface-2)}
  .lrow .l-main b{font-weight:600}.lrow .l-main span{display:block;font-size:11px;color:var(--faint)}
  /* Checklist / tareas */
  .taskform{display:flex;gap:8px;padding:12px 16px;border-bottom:1px solid var(--border)}
  .taskform input{flex:1;height:38px;border:1px solid var(--border);border-radius:9px;padding:0 12px;font-size:12.5px;background:var(--surface-2);color:var(--text)}
  .taskform input:focus{outline:none;border-color:var(--accent);box-shadow:0 0 0 3px var(--accent-ring)}
  .addbtn{flex:0 0 38px;height:38px;border-radius:9px;border:0;background:var(--accent);color:#fff;display:grid;place-items:center;cursor:pointer;transition:.12s}
  .addbtn:hover{background:var(--accent-2)}.addbtn [data-lucide]{width:16px;height:16px;stroke-width:2.4}
  .task{display:flex;align-items:center;gap:10px;padding:9px 16px;border-bottom:1px solid var(--border);font-size:13px}
  .task:last-child{border-bottom:0}
  .task .chk{width:18px;height:18px;border-radius:5px;border:1.5px solid var(--border-strong);flex:0 0 auto;display:grid;place-items:center;background:var(--surface);cursor:pointer;padding:0}
  .task .chk.done{background:var(--good);border-color:var(--good)}.task .chk.done [data-lucide]{width:12px;height:12px;color:#fff;stroke-width:3}
  .task .t-tx{flex:1}.task.done .t-tx{text-decoration:line-through;color:var(--faint)}
  .task .t-del{opacity:.35;color:var(--bad);background:none;border:0;cursor:pointer;padding:2px}.task:hover .t-del{opacity:1}
  /* Alertas */
  .alertgrid{display:grid;grid-template-columns:1fr 1fr;gap:14px;padding:16px}@media(max-width:700px){.alertgrid{grid-template-columns:1fr}}
  .alertbox{border:1px solid var(--border);border-radius:11px;padding:13px 14px;background:var(--surface-2)}
  .alertbox .ah{display:flex;align-items:center;gap:8px;font-weight:650;font-size:13px;margin-bottom:7px}
  .alertbox .ah [data-lucide]{width:16px;height:16px}
  .alertbox.warn .ah{color:var(--warn)}.alertbox.bad .ah{color:var(--bad)}
  .alertbox p,.alertbox div{margin:0;font-size:12.5px;color:var(--muted)}.alertbox a{color:var(--accent);font-weight:650}
  /* Barras horizontales */
  .bars{display:flex;flex-direction:column;gap:11px;padding:16px}
  .bar-row .bt{display:flex;justify-content:space-between;font-size:12px;margin-bottom:4px}.bar-row .bt b{font-weight:600}.bar-row .bt span{color:var(--faint);font-variant-numeric:tabular-nums}
  .bar{height:8px;border-radius:6px;background:var(--surface-2);overflow:hidden}
  .bar i{display:block;height:100%;border-radius:6px;background:linear-gradient(90deg,var(--accent),var(--accent-2))}
  /* ── Compatibilidad (páginas aún sin migrar): marca turquesa → índigo ── */
  .grad-brand{background:linear-gradient(135deg,var(--accent),var(--accent-2))!important}
  .btn-brand{background:var(--accent)!important;color:#fff!important;font-weight:700;border:0}
  .btn-brand:hover{background:var(--accent-2)!important}
  .text-brand,.text-brand-teal{color:var(--accent)!important}
  .border-brand{border-color:var(--accent)!important}
  .accent-teal-500{accent-color:var(--accent)}
  /* Tour guiado (onboarding) */
  #tour-ov{position:fixed;inset:0;z-index:60;display:none}
  #tour-hole{position:absolute;border-radius:12px;box-shadow:0 0 0 9999px rgba(6,9,16,.66);transition:all .22s ease;pointer-events:none}
  #tour-card{position:absolute;width:330px;max-width:calc(100vw - 24px);background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:16px 18px;box-shadow:0 20px 50px rgba(0,0,0,.35);z-index:61}
  #tour-card h4{font-weight:800;color:var(--accent);margin:0 0 6px;font-size:15px}
  #tour-card p{color:var(--muted);font-size:13px;line-height:1.5;margin:0 0 12px}
  #tour-card .tour-dots{display:flex;gap:4px;margin-bottom:10px}
  #tour-card .tour-dots i{width:6px;height:6px;border-radius:9px;background:var(--border-strong);transition:all .2s}
  #tour-card .tour-dots i.on{background:var(--accent);width:16px}
  #tour-card .tour-btns{display:flex;align-items:center;gap:8px}
  #tour-card .tour-skip{margin-right:auto;color:var(--faint);font-size:12px;font-weight:600;background:none;border:0;cursor:pointer}
  #tour-card .tour-prev{color:var(--muted);font-size:13px;font-weight:700;background:var(--surface-2);border:1px solid var(--border);border-radius:9px;padding:7px 12px;cursor:pointer}
  #tour-card .tour-next{color:#fff;font-size:13px;font-weight:800;background:var(--accent);border:0;border-radius:9px;padding:7px 14px;cursor:pointer}
</style>
</head>
<body>
<div class="app">
  <!-- SIDEBAR -->
  <aside id="sb" class="sb thin">
    <div class="sb-head">
      <div class="logo"><img src="<?= h($storeLogo) ?>" alt="<?= h($storeName) ?>" style="width:100%;height:100%;object-fit:cover;border-radius:inherit;display:block"></div>
      <div class="sb-brand"><?= h($storeName) ?><small>Gestor de Streaming</small></div>
    </div>
    <nav class="sb-nav thin">
      <div class="grp-label">Gestión</div>
      <?php foreach (stream_nav_items() as $it):
        if ($it['key'] === 'mensajeria' || $it['key'] === 'mensajes') { /* separador Atención */
          static $atLbl = false; if (!$atLbl) { $atLbl = true; echo '<div class="grp-label">Atención</div>'; }
        }
        $hasSub = !empty($it['sub']);
        $subKeys = $hasSub ? array_column($it['sub'], 'key') : [];
        $isActive = ($active === $it['key']);
        $openSub = $hasSub && ($isActive || in_array($active, $subKeys, true));
      ?>
        <a href="<?= h($it['href']) ?>"
           class="lnk <?= ($isActive && !$hasSub) ? 'on' : '' ?> <?= $hasSub ? 'sb-parent' : '' ?>"
           <?= $hasSub ? 'data-sub="' . h($it['key']) . '"' : '' ?>>
          <i data-lucide="<?= h($it['icon']) ?>"></i>
          <span style="flex:1"><?= h($it['label']) ?></span>
          <?php if ($hasSub): ?><i data-lucide="chevron-down" class="chev <?= $openSub ? 'open' : '' ?>"></i><?php endif; ?>
        </a>
        <?php if ($hasSub): ?>
          <div class="subnav <?= $openSub ? '' : 'hidden' ?>" id="sub-<?= h($it['key']) ?>">
            <?php foreach ($it['sub'] as $s): ?>
              <a href="<?= h($s['href']) ?>" class="<?= $active === $s['key'] ? 'on' : '' ?>"><?= h($s['label']) ?></a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      <?php endforeach; ?>
    </nav>
    <div class="sb-foot">Gestor de Streaming · v5</div>
  </aside>
  <div id="sb-ov" class="sb-ov"></div>

  <!-- MAIN -->
  <div class="main">
    <header class="top">
      <button id="hambBtn" class="hamb iconbtn" aria-label="Menú"><i data-lucide="menu"></i></button>
      <button class="iconbtn" onclick="if(history.length>1)history.back();else location.href='dashboard.php'" title="Atrás"><i data-lucide="arrow-left"></i></button>
      <div class="crumb">Streaming <span>/</span> <b><?= h($title) ?></b></div>
      <div class="tbtns">
        <?php $PANEL_ACTIVO = 'streaming'; include dirname(__DIR__) . '/_panel_switch.php'; ?>
        <a href="https://streaming.reborxstore.com" target="_blank" rel="noopener" class="iconbtn" title="Gestión de códigos (bot)"><i data-lucide="terminal-square"></i></a>
        <button id="theme-toggle" class="iconbtn" title="Cambiar tema"><i data-lucide="moon"></i></button>
        <?php if ($storeWaDigits !== ''): ?><a href="https://wa.me/<?= h($storeWaDigits) ?>" target="_blank" rel="noopener" class="iconbtn" style="color:#16a34a" title="WhatsApp de la tienda"><i data-lucide="message-circle"></i></a><?php endif; ?>
        <div class="user"><div class="ava"><?= h($ini) ?></div><div><b><?= h($primer) ?></b><span>Streaming</span></div></div>
      </div>
    </header>
    <main class="wrap <?= $fullBleed ? 'bleed' : '' ?>">
<?php
}

function stream_foot(): void {
  ?>
    </main>
  </div>
</div>
<script>
  if(window.lucide) lucide.createIcons();

  // Tema claro/oscuro (compartido con el panel de recargas: misma clave cc_admin_theme)
  (function(){
    var root=document.documentElement, KEY='cc_admin_theme', b=document.getElementById('theme-toggle');
    function upd(){ var dark=root.getAttribute('data-theme')==='dark'; if(b) b.innerHTML='<i data-lucide="'+(dark?'sun':'moon')+'"></i>'; if(window.lucide)lucide.createIcons(); }
    if(b) b.onclick=function(){ var dark=root.getAttribute('data-theme')==='dark'; if(dark){root.removeAttribute('data-theme');}else{root.setAttribute('data-theme','dark');} try{localStorage.setItem(KEY,dark?'light':'dark');}catch(e){} upd(); };
    upd();
  })();

  // Submenús del sidebar: el padre togglea sin navegar.
  document.querySelectorAll('.sb-parent').forEach(function(p){
    p.addEventListener('click', function(e){
      e.preventDefault();
      var k=p.getAttribute('data-sub'), sub=document.getElementById('sub-'+k);
      if(sub){ sub.classList.toggle('hidden'); var ch=p.querySelector('.chev'); if(ch) ch.classList.toggle('open'); }
    });
  });

  // Sidebar móvil (off-canvas)
  (function(){
    var sb=document.getElementById('sb'), ov=document.getElementById('sb-ov'), h=document.getElementById('hambBtn');
    function close(){ sb.classList.remove('open'); ov.classList.remove('open'); }
    function open(){ sb.classList.add('open'); ov.classList.add('open'); }
    if(h) h.addEventListener('click', function(){ sb.classList.contains('open')?close():open(); });
    if(ov) ov.addEventListener('click', close);
  })();

  // Anti doble-submit: al enviar cualquier form, deshabilita su botón (evita doble WhatsApp / doble cobro).
  document.addEventListener('submit', function(e){
    var f=e.target; if(!(f instanceof HTMLFormElement)) return;
    if(f.dataset.sbusy){ e.preventDefault(); return; }
    f.dataset.sbusy='1';
    var b=f.querySelector('button[type=submit], button:not([type])');
    if(b){ if(!b.dataset.t) b.dataset.t=b.innerHTML; b.disabled=true; b.innerHTML='⏳ Enviando…'; }
    setTimeout(function(){ f.dataset.sbusy=''; if(b){ b.disabled=false; if(b.dataset.t) b.innerHTML=b.dataset.t; } }, 8000);
  }, true);

  // ─── Tour guiado (onboarding) ───────────────────────────────────────────
  (function(){
    let steps=[], idx=0, key='', ov=null, hole=null, card=null;
    function build(){ ov=document.createElement('div'); ov.id='tour-ov'; hole=document.createElement('div'); hole.id='tour-hole'; card=document.createElement('div'); card.id='tour-card'; ov.appendChild(hole); ov.appendChild(card); document.body.appendChild(ov); }
    function done(){ try{ if(key) localStorage.setItem(key,'1'); }catch(e){} if(ov) ov.style.display='none'; window.removeEventListener('resize',place); window.removeEventListener('keydown',onKey); }
    function onKey(e){ if(e.key==='Escape') done(); else if(e.key==='Enter'||e.key==='ArrowRight'){ e.preventDefault(); go(1);} else if(e.key==='ArrowLeft') go(-1); }
    function go(d){ const n=idx+d; if(n<0) return; if(n>=steps.length){ done(); return; } idx=n; render(); }
    function render(){
      const s=steps[idx]; const el=s.el?document.querySelector(s.el):null;
      const dots=steps.map((_,i)=>'<i class="'+(i===idx?'on':'')+'"></i>').join('');
      card.innerHTML='<div class="tour-dots">'+dots+'</div>'+(s.title?'<h4>'+s.title+'</h4>':'')+'<p>'+(s.text||'')+'</p>'+
        '<div class="tour-btns"><button class="tour-skip" data-act="skip">Saltar</button>'+
        (idx>0?'<button class="tour-prev" data-act="prev">← Atrás</button>':'')+
        '<button class="tour-next" data-act="next">'+(idx===steps.length-1?'¡Listo! ✓':'Siguiente →')+'</button></div>';
      card.querySelector('[data-act=skip]').onclick=done;
      const pv=card.querySelector('[data-act=prev]'); if(pv) pv.onclick=function(){go(-1);};
      card.querySelector('[data-act=next]').onclick=function(){go(1);};
      if(el){ try{ el.scrollIntoView({block:'center',inline:'center'}); }catch(e){} }
      setTimeout(function(){ placeFor(el); }, 130);
    }
    function placeFor(el){
      const pad=8;
      if(el){ const r=el.getBoundingClientRect();
        if(r.width>0 && r.height>0){
          hole.style.display='block'; hole.style.left=(r.left-pad)+'px'; hole.style.top=(r.top-pad)+'px';
          hole.style.width=(r.width+pad*2)+'px'; hole.style.height=(r.height+pad*2)+'px';
          const cw=Math.min(330, window.innerWidth-24), ch=card.offsetHeight||180; let left, top;
          if(r.right < window.innerWidth*0.5){ left=Math.min(r.right+14, window.innerWidth-cw-12); top=Math.max(12, Math.min(r.top, window.innerHeight-ch-12)); }
          else { left=Math.max(12, Math.min(r.left, window.innerWidth-cw-12)); top=(r.bottom+14+ch < window.innerHeight) ? r.bottom+14 : Math.max(12, r.top-ch-14); }
          card.style.left=left+'px'; card.style.top=top+'px'; card.style.transform=''; return;
        }
      }
      hole.style.display='none'; card.style.left='50%'; card.style.top='50%'; card.style.transform='translate(-50%,-50%)';
    }
    function place(){ placeFor(steps[idx] && steps[idx].el ? document.querySelector(steps[idx].el) : null); }
    window.conecTour=function(theSteps, theKey, force){
      if(!force){ try{ if(theKey && localStorage.getItem(theKey)) return; }catch(e){} }
      steps=theSteps||[]; key=theKey||''; idx=0; if(!steps.length) return;
      if(!ov) build(); ov.style.display='block';
      window.addEventListener('resize',place); window.addEventListener('keydown',onKey); render();
    };
    function autoTour(){
      const p=location.pathname;
      if(!(/\/dashboard\.php$/.test(p) || /\/admin\/stream\/(index\.php)?$/.test(p))) return;
      window.conecTour([
        { title:'¡Bienvenido al Gestor de Streaming! 👋', text:'Te muestro lo básico en 30 segundos. Puedes saltarlo cuando quieras.' },
        { el:'a[href="dashboard.php"]', title:'Dashboard', text:'El resumen del día: perfiles libres, qué vence hoy y esta semana, y tus tareas.' },
        { el:'[data-sub="ventas"]', title:'Ventas', text:'Aquí registras y gestionas las ventas. Entra y pulsa "Nueva Venta" para vender.' },
        { el:'a[href="clientes.php"]', title:'Clientes', text:'Tus clientes, sus accesos y los recordatorios de renovación.' },
        { el:'[data-sub="plataformas"]', title:'Plataformas', text:'El inventario: cuentas, tipos de servicio y el stock de perfiles libres.' },
        { title:'¡Listo!', text:'Eso es todo. Cualquier duda, pregúntale al dueño. ¡Éxitos con las ventas!' },
      ], 'conec_tour_stream_v1', false);
    }
    if(document.readyState==='complete') setTimeout(autoTour,700);
    else window.addEventListener('load', function(){ setTimeout(autoTour,700); });
  })();

  // ─── Notificaciones EN TIEMPO REAL con TONO (tickets + cambios/compras) ───────────
  // El cliente notó que las notificaciones no eran en vivo (había que refrescar). Esto sondea cada 25s
  // el contador de no leídas y, cuando SUBE, suena un tono y muestra un aviso flotante. Dos tonos:
  // doble-agudo para TICKETS de soporte, simple para el resto (cambios/compras/etc.). Sin dependencias.
  (function(){
    var last=null, lastTk=0, primed=false, ac=null;
    function ctx(){ try{ if(!ac) ac=new (window.AudioContext||window.webkitAudioContext)(); if(ac.state==='suspended') ac.resume(); }catch(e){} return ac; }
    // El navegador exige un gesto del usuario para permitir audio: lo habilitamos en el primer clic.
    document.addEventListener('click', function(){ ctx(); }, {once:true});
    function beep(freqs){ var a=ctx(); if(!a) return; try{ var t=a.currentTime; freqs.forEach(function(f,i){ var o=a.createOscillator(), g=a.createGain(); o.type='sine'; o.frequency.value=f; o.connect(g); g.connect(a.destination); var s=t+i*0.17; g.gain.setValueAtTime(0.0001,s); g.gain.exponentialRampToValueAtTime(0.2,s+0.02); g.gain.exponentialRampToValueAtTime(0.0001,s+0.15); o.start(s); o.stop(s+0.16); }); }catch(e){} }
    function toast(txt,url,tk){ var d=document.createElement('div'); d.textContent=(tk?'🎫 ':'🔔 ')+txt; d.style.cssText='position:fixed;right:18px;bottom:18px;z-index:9999;max-width:330px;background:var(--surface,#fff);color:var(--text,#111);border:1px solid var(--border,#ddd);border-left:4px solid var(--accent,#3f4fb5);border-radius:11px;padding:12px 15px;box-shadow:0 14px 38px rgba(0,0,0,.3);font-size:13px;font-weight:600;cursor:pointer;animation:tkin .25s ease'; if(url) d.onclick=function(){ location.href=url; }; document.body.appendChild(d); setTimeout(function(){ d.style.transition='opacity .4s'; d.style.opacity='0'; setTimeout(function(){ d.remove(); },420); }, 7000); }
    function updBadge(n){ document.querySelectorAll('.sb-nav a.lnk span').forEach(function(s){ if(/^Notificaciones/.test(s.textContent)) s.textContent='Notificaciones'+(n>0?' ('+n+')':''); }); }
    function poll(){
      fetch('notificaciones.php?ajax=count',{headers:{'X-Requested-With':'fetch'},cache:'no-store'})
        .then(function(r){ return r.ok ? r.json() : null; })
        .then(function(d){
          if(!d) return; var tot=d.total||0, tk=d.ticket||0; updBadge(tot);
          if(!primed){ primed=true; last=tot; lastTk=tk; return; }
          if(tot>last){
            var isTk = tk>lastTk;
            beep(isTk?[900,1200]:[680]);
            var t=(d.last&&d.last.titulo)?d.last.titulo:(isTk?'Nuevo ticket de soporte':'Tienes una notificación nueva');
            toast(t,(d.last&&d.last.url)?d.last.url:'notificaciones.php',isTk);
          }
          last=tot; lastTk=tk;
        }).catch(function(){});
    }
    var st=document.createElement('style'); st.textContent='@keyframes tkin{from{transform:translateY(12px);opacity:0}to{transform:none;opacity:1}}'; document.head.appendChild(st);
    setTimeout(poll, 2500); setInterval(poll, 25000);
  })();
</script>
</body></html>
<?php
}
