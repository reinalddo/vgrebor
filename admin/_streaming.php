<?php
/**
 * Helpers de datos del Gestor de Streaming (plataformas, proveedores, clientes,
 * cuentas/inventario con stock por perfil, ventas, KPIs) + entrega por WhatsApp.
 * Lo usa admin/streaming.php. Requiere estar en contexto admin (db(), wa).
 */
if (!defined('CONEC_ADMIN')) { http_response_code(403); exit; }

// Integración con el BOT de códigos (asignar/desasignar correo al revendedor). Best-effort, opcional.
require_once __DIR__ . '/../includes/bot_codigos.php';

/** Color + inicial de respaldo por nombre de plataforma (cuando no hay color propio). */
function streamPlatFallback(string $p): array {
  $p = mb_strtolower($p);
  if (str_contains($p, 'netflix'))   return ['#e50914', 'N'];
  if (str_contains($p, 'disney'))    return ['#113ccf', 'D+'];
  if (str_contains($p, 'max') || str_contains($p, 'hbo')) return ['#7b2ff7', 'M'];
  if (str_contains($p, 'spotify'))   return ['#1db954', 'S'];
  if (str_contains($p, 'prime') || str_contains($p, 'amazon')) return ['#00a8e1', 'P'];
  if (str_contains($p, 'youtube'))   return ['#ff0000', 'YT'];
  if (str_contains($p, 'crunchy'))   return ['#f47521', 'C'];
  if (str_contains($p, 'vix'))       return ['#f5b50a', 'V'];
  if (str_contains($p, 'paramount')) return ['#0064ff', 'P+'];
  return ['#64748b', mb_strtoupper(mb_substr($p, 0, 1) ?: '?')];
}

/* Ventas "de revendedor": una venta nueva para un cliente/WhatsApp de un revendedor nace ya con su
   revendedor_id (para no re-etiquetar a mano). Data-driven: configuracion.stream_cliente_rev_map =
   JSON {"clientes":{"74":1162},"wa":{"584246509960":1162}}. Sin config, usa el default de abajo
   (Jesús Figueroa = usuario 1162). Devuelve el revendedor_id o null (null = venta directa normal). */
function st_revendedor_de_cliente(PDO $pdo, ?int $clienteId, ?string $clienteWa): ?int {
  static $map = null;
  if ($map === null) {
    $map = ['clientes' => [74 => 1162], 'wa' => ['584246509960' => 1162]];   // default: Jesús Figueroa
    try {
      $j = $pdo->query("SELECT valor FROM configuracion WHERE clave='stream_cliente_rev_map' LIMIT 1")->fetchColumn();
      if ($j) { $d = json_decode((string) $j, true); if (is_array($d)) $map = ['clientes' => (array) ($d['clientes'] ?? []), 'wa' => (array) ($d['wa'] ?? [])]; }
    } catch (Throwable $e) {}
  }
  $cid = (int) $clienteId;
  if ($cid && isset($map['clientes'][$cid])) return (int) $map['clientes'][$cid];
  $wa = $clienteWa ? preg_replace('/\D+/', '', (string) $clienteWa) : '';
  if ($wa !== '' && isset($map['wa'][$wa])) return (int) $map['wa'][$wa];
  return null;
}

/** Plantillas de mensajes configurables (Gestor → Mensajes automáticos). Guardadas en `configuracion` como stream_tpl_<key>. Vacío = texto por defecto. */
function stream_tpl_get(string $key): string {
  static $c = null;
  if ($c === null) { $c = [];
    try { foreach (db()->query("SELECT clave, valor FROM configuracion WHERE clave LIKE 'stream_tpl_%'") as $r) $c[$r['clave']] = (string) $r['valor']; } catch (Throwable $e) {} }
  return trim((string) ($c['stream_tpl_' . $key] ?? ''));
}
function stream_tpl_render(string $tpl, array $vars): string {
  return preg_replace_callback('/\{(\w+)\}/', function ($m) use ($vars) { return array_key_exists($m[1], $vars) ? (string) $vars[$m[1]] : $m[0]; }, $tpl);
}
/** Variables comunes de una venta para las plantillas de WhatsApp. */
function stream_tpl_vars(array $v): array {
  $venc = !empty($v['fecha_vencimiento']) ? strtotime($v['fecha_vencimiento']) : 0;
  $d = $venc ? (int) floor(($venc - strtotime('today')) / 86400) : 0;
  return [
    'nombre' => trim((string) ($v['cliente_nombre'] ?? '')) ?: 'Hola',
    'plataforma' => ((string) ($v['plataforma'] ?? '')) ?: 'Streaming',
    'correoPlataforma' => (string) ($v['correo'] ?? ''),
    'contrasenaPlataforma' => (string) ($v['clave'] ?? ''),
    'perfil' => (string) ($v['perfil'] ?? ''),
    'pin' => (string) ($v['pin'] ?? ''),
    'monto' => (isset($v['precio']) && $v['precio'] !== null && $v['precio'] !== '') ? '$' . number_format((float) $v['precio'], 2) : '',
    'fechaRenovacion' => $venc ? date('d/m/Y', $venc) : '',
    'diasRestantes' => (string) $d,
  ];
}

/** Enlace privado (token firmado) a la página de datos de acceso.
 *  ANTI-BANEO: las credenciales NO viajan por WhatsApp; se entregan por esta página.
 *  El token se recalcula igual en cuenta/acceso.php. */
function stream_acceso_link(int $ventaId): string {
  $secret = (string) (function_exists('config') ? (config()['api_token'] ?? '') : '');
  $t = hash_hmac('sha256', 'stream-acceso|' . $ventaId, $secret);
  return 'https://conecta2ve.com/cuenta/acceso.php?v=' . $ventaId . '&t=' . $t;
}

/** Mensaje de credenciales para WhatsApp — SIN credenciales crudas (anti-baneo Meta): manda un enlace privado.
 *  Las claves reales las ve el cliente en cuenta/acceso.php (link con token) o por correo. */
function stream_msg_credenciales(array $v): string {
  $plat = ((string) ($v['plataforma'] ?? '')) ?: 'tu servicio';
  $id   = (int) ($v['id'] ?? 0);
  if ($id <= 0) {
    return "✅ ¡Listo! Tu " . $plat . " ya está activo. Escríbenos y te pasamos tus datos de acceso. 🙌";
  }
  $venc    = !empty($v['fecha_vencimiento']) ? date('d/m/Y', strtotime($v['fecha_vencimiento'])) : '';
  $correo  = trim((string) ($v['correo'] ?? ''));
  $clave   = trim((string) ($v['clave'] ?? ''));
  $perfil  = trim((string) ($v['perfil'] ?? ''));
  $pin     = trim((string) ($v['pin'] ?? ''));
  $m  = "✅ ¡Listo! Tu *" . $plat . "* ya está activo.\n\n";
  $m .= "🔐 Tus datos de acceso:\n";
  if ($correo !== '') $m .= "📧 Correo: " . $correo . "\n";
  if ($clave  !== '') $m .= "🔑 Clave: " . $clave . "\n";
  if ($perfil !== '') $m .= "👤 Perfil: " . $perfil . "\n";
  if ($pin    !== '') $m .= "🔢 PIN: " . $pin . "\n";
  if ($correo === '' && $clave === '' && $perfil === '' && $pin === '') {
    // Aún no hay credenciales cargadas en la venta → aviso claro (no link a dominio externo).
    $m .= "(Escríbenos y te pasamos tus datos de acceso.)\n";
  }
  if ($venc !== '') $m .= "\n📅 Válido hasta: " . $venc . "\n";
  $m .= "\n¡Gracias por tu compra! 🙌 Cualquier duda, escríbenos.";
  return $m;
}

/** Envía un texto al cliente por WhatsApp y lo deja en el inbox. Devuelve [ok,error].
 *  El texto LIBRE solo entrega DENTRO de la ventana de 24h (el cliente escribió hace ≤24h).
 *  Fuera de ella Meta lo rechaza y, repetido, puede causar BAN del número → NO lo enviamos:
 *  devolvemos error='ventana_cerrada' para que el panel avise (el empleado lo pasa a mano o usa plantilla). */
function stream_wa_enviar(PDO $pdo, string $wa, string $nombre, string $texto): array {
  if (!function_exists('wa_send_text') || !wa_disponible()) return ['ok' => false, 'error' => 'WhatsApp no configurado'];
  $waid = wa_norm($wa);
  if (strlen($waid) < 10) return ['ok' => false, 'error' => 'número inválido'];
  // Upsert del contacto PRIMERO: hace falta para chequear la ventana de 24h y loguear el mensaje.
  $cid = null;
  try { $cid = wa_upsert_contacto($pdo, $waid, $nombre ?: null); } catch (Throwable $e) {}
  if ($cid !== null && function_exists('wa_ventana_abierta') && !wa_ventana_abierta($pdo, $cid)) {
    return ['ok' => false, 'error' => 'ventana_cerrada'];
  }
  $r = wa_send_text($waid, $texto);
  try {
    if ($cid === null) $cid = wa_upsert_contacto($pdo, $waid, $nombre ?: null);
    wa_guardar_mensaje($pdo, $cid, 'out', ['tipo' => 'text', 'texto' => $texto, 'wam_id' => $r['wam_id'] ?? null, 'estado' => $r['ok'] ? 'enviado' : 'fallido', 'error' => $r['error'] ?? null]);
  } catch (Throwable $e) {}
  return ['ok' => (bool)$r['ok'], 'error' => $r['error'] ?? null];
}

function st_plataformas(PDO $pdo, bool $soloActivas = false): array {
  $o = (int) stream_owner_id();
  try { return $pdo->query("SELECT * FROM streaming_plataformas WHERE owner_id=$o " . ($soloActivas ? "AND activo=1 " : "") . "ORDER BY orden, nombre")->fetchAll(PDO::FETCH_ASSOC); }
  catch (Throwable $e) { return []; }
}
function st_proveedores(PDO $pdo): array {
  $o = (int) stream_owner_id();
  try { return $pdo->query("SELECT * FROM streaming_proveedores WHERE owner_id=$o ORDER BY activo DESC, nombre")->fetchAll(PDO::FETCH_ASSOC); }
  catch (Throwable $e) { return []; }
}
function st_clientes(PDO $pdo): array {
  $o = (int) stream_owner_id();
  try {
    return $pdo->query("SELECT cl.*,
        (SELECT COUNT(*) FROM streaming_ventas v WHERE v.cliente_id=cl.id) AS ventas_n
      FROM streaming_clientes cl WHERE cl.owner_id=$o ORDER BY cl.nombre")->fetchAll(PDO::FETCH_ASSOC);
  } catch (Throwable $e) { return []; }
}

/** Cuentas del inventario con datos de plataforma/proveedor + conteo de perfiles. */
function st_cuentas(PDO $pdo): array {
  $o = (int) stream_owner_id();
  try {
    return $pdo->query("SELECT c.*, p.nombre AS plat_nombre, p.color AS plat_color, p.emoji AS plat_emoji,
              pr.nombre AS prov_nombre,
              (SELECT COUNT(*) FROM streaming_perfiles sp WHERE sp.cuenta_id=c.id AND sp.estado<>'inactivo') AS perfiles_n,
              (SELECT COUNT(*) FROM streaming_perfiles sp WHERE sp.cuenta_id=c.id AND sp.estado='libre') AS libres_n
        FROM streaming_cuentas c
        LEFT JOIN streaming_plataformas p ON p.id=c.plataforma_id AND p.owner_id=c.owner_id
        LEFT JOIN streaming_proveedores pr ON pr.id=c.proveedor_id AND pr.owner_id=c.owner_id
        WHERE c.owner_id=$o
        ORDER BY COALESCE(p.nombre, c.plataforma), c.id")->fetchAll(PDO::FETCH_ASSOC);
  } catch (Throwable $e) { return []; }
}

/** Perfiles de una cuenta (con el nombre del cliente si está vendido). */
function st_perfiles_por_cuenta(PDO $pdo): array {
  $o = (int) stream_owner_id();
  $out = [];
  try {
    $rows = $pdo->query("SELECT sp.id, sp.cuenta_id, sp.etiqueta, sp.pin, sp.estado, sp.venta_id,
              v.cliente_nombre, v.fecha_vencimiento
        FROM streaming_perfiles sp
        JOIN streaming_cuentas c ON c.id=sp.cuenta_id AND c.owner_id=$o
        LEFT JOIN streaming_ventas v ON v.id=sp.venta_id
        WHERE sp.estado<>'inactivo'
        ORDER BY sp.cuenta_id, sp.id")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) $out[(int)$r['cuenta_id']][] = $r;
  } catch (Throwable $e) {}
  return $out;
}

/** Ventas (filtro: 'venc' = vencen ≤7d o vencidas; 'todas'). */
function st_ventas(PDO $pdo, string $filtro = 'todas'): array {
  $o = (int) stream_owner_id();
  $hoy = date('Y-m-d');
  $where = $filtro === 'venc'
    ? "v.estado='activa' AND v.fecha_vencimiento <= DATE_ADD('$hoy', INTERVAL 7 DAY)"
    : "1=1";
  $order = $filtro === 'venc' ? "v.fecha_vencimiento ASC" : "v.id DESC";
  try {
    // Los JOIN van scopeados por dueño: en compras del stock del admin, v.cuenta_id apunta a una
    // cuenta del admin (owner 0) — sin este AND, el revendedor vería el COSTO del admin (fuga).
    return $pdo->query("SELECT v.*, cl.nombre AS cliente_reg, c.plataforma AS cuenta_plat, c.costo AS cuenta_costo, c.perfiles_total AS cuenta_perfiles
        FROM streaming_ventas v
        LEFT JOIN streaming_clientes cl ON cl.id=v.cliente_id AND cl.owner_id=v.owner_id
        LEFT JOIN streaming_cuentas c ON c.id=v.cuenta_id AND c.owner_id=v.owner_id
        WHERE v.owner_id=$o AND ($where) ORDER BY $order LIMIT 5000")->fetchAll(PDO::FETCH_ASSOC);
  } catch (Throwable $e) { return []; }
}

/** Una venta por id (para editar) — scopeada al dueño del contexto. */
function st_venta(PDO $pdo, int $id): ?array {
  $o = (int) stream_owner_id();
  try { $s = $pdo->prepare("SELECT * FROM streaming_ventas WHERE id=? AND owner_id=?"); $s->execute([$id, $o]); return $s->fetch(PDO::FETCH_ASSOC) ?: null; }
  catch (Throwable $e) { return null; }
}

/** Mensaje de RECORDATORIO de renovación (distinto al de credenciales). */
/** Mensaje de renovación configurado POR PLATAFORMA (en Tipos). Vacío si no hay. Cacheado por request. */
function stream_plat_msg_renovacion(string $plataforma): string {
  $plataforma = trim($plataforma);
  if ($plataforma === '') return '';
  static $cache = [];
  $k = mb_strtolower($plataforma);
  if (array_key_exists($k, $cache)) return $cache[$k];
  $m = '';
  try { $st = db()->prepare("SELECT msg_renovacion FROM streaming_plataformas WHERE owner_id=0 AND LOWER(nombre)=? LIMIT 1"); $st->execute([$k]); $m = trim((string) ($st->fetchColumn() ?: '')); } catch (Throwable $e) { $m = ''; }
  return $cache[$k] = $m;
}
/** #15: mensaje PARA el REVENDEDOR (para que él avise a su propio cliente). */
function stream_msg_aviso_revendedor(array $v): string {
  $d = !empty($v['fecha_vencimiento']) ? (int) floor((strtotime($v['fecha_vencimiento']) - strtotime('today')) / 86400) : 0;
  $cuando = $d < 0 ? ('venció hace ' . abs($d) . ' día(s)') : ($d === 0 ? 'vence HOY' : "vence en $d día(s)");
  $cli = trim((string) ($v['cliente_nombre'] ?? '')) ?: 'tu cliente';
  $m  = "👋 ¡Hola! Aviso de vencimiento para que le avises a tu cliente:\n\n";
  $m .= "El *" . ($v['plataforma'] ?: 'Streaming') . "* de *" . $cli . "* " . $cuando . " 📅";
  if (!empty($v['fecha_vencimiento'])) $m .= " (" . date('d/m/Y', strtotime($v['fecha_vencimiento'])) . ")";
  $m .= ".\n\nAvísale para que renueve a tiempo. 🙌";
  return $m;
}
function stream_msg_recordatorio(array $v): string {
  // 1) Mensaje POR PLATAFORMA (configurado en Tipos → «Mensaje de renovación»), si existe.
  $pm = stream_plat_msg_renovacion((string) ($v['plataforma'] ?? ''));
  if ($pm !== '') return stream_tpl_render($pm, stream_tpl_vars($v));
  // 2) Plantilla global.
  $tpl = stream_tpl_get('recordatorio');
  if ($tpl !== '') return stream_tpl_render($tpl, stream_tpl_vars($v));
  $d = isset($v['fecha_vencimiento']) ? (int)floor((strtotime($v['fecha_vencimiento']) - strtotime('today')) / 86400) : 0;
  $cuando = $d < 0 ? ('venció hace ' . abs($d) . ' día(s)') : ($d === 0 ? 'vence HOY' : "vence en $d día(s)");
  $m  = "👋 ¡Hola" . (!empty($v['cliente_nombre']) ? ' ' . explode(' ', trim($v['cliente_nombre']))[0] : '') . "!\n\n";
  $m .= "Tu *" . ($v['plataforma'] ?: 'Streaming') . "* " . $cuando . " 📅\n";
  if (!empty($v['fecha_vencimiento'])) $m .= "(" . date('d/m/Y', strtotime($v['fecha_vencimiento'])) . ")\n";
  $m .= "\n¿Te lo renovamos? Avísanos y seguimos 🙌";
  return $m;
}

/** Código de pedido LEGIBLE de una venta, derivado de su id único (que ya existe y nunca se repite).
 *  No toca la BD ni las rutas de cobro: es solo presentación (formato RBX-000123). El mismo id que ya
 *  usa el sistema internamente sale ahora como número de pedido para ubicar la compra en el reporte. */
function stream_cod_pedido($id): string {
  $n = (int) $id;
  return $n > 0 ? 'RBX-' . str_pad((string) $n, 6, '0', STR_PAD_LEFT) : '—';
}

function st_kpis(PDO $pdo): array {
  $o = (int) stream_owner_id();
  $hoy = date('Y-m-d');
  // Las ventas del revendedor (incluidas las que el admin le asignó, que ahora nacen con owner=él vía
  // st_rev_entregar) son owner=él → con owner alcanza para sus estadísticas.
  $scope = "owner_id=$o";
  $k = ['hoy' => 0, 'semana' => 0, 'vencidas' => 0, 'activas' => 0, 'mes' => 0, 'costo_mes' => 0, 'ganancia_mes' => 0, 'deuda' => 0, 'libres' => 0, 'perfiles' => 0, 'cuentas' => 0];
  try {
    $k['hoy']      = (int)$pdo->query("SELECT COUNT(*) FROM streaming_ventas WHERE $scope AND estado='activa' AND fecha_vencimiento='$hoy'")->fetchColumn();
    $k['semana']   = (int)$pdo->query("SELECT COUNT(*) FROM streaming_ventas WHERE $scope AND estado='activa' AND fecha_vencimiento BETWEEN '$hoy' AND DATE_ADD('$hoy', INTERVAL 7 DAY)")->fetchColumn();
    $k['vencidas'] = (int)$pdo->query("SELECT COUNT(*) FROM streaming_ventas WHERE $scope AND (estado='vencida' OR (estado='activa' AND fecha_vencimiento < '$hoy'))")->fetchColumn();
    $k['activas']  = (int)$pdo->query("SELECT COUNT(*) FROM streaming_ventas WHERE $scope AND estado='activa' AND fecha_vencimiento >= '$hoy'")->fetchColumn();
    $k['mes']      = (float)$pdo->query("SELECT COALESCE(SUM(precio),0) FROM streaming_ventas WHERE $scope AND estado<>'cancelada' AND MONTH(creado_en)=MONTH(NOW()) AND YEAR(creado_en)=YEAR(NOW())")->fetchColumn();
    // Ganancia = INGRESOS por ventas − COSTO TOTAL del stock (lo invertido). Así el stock comprado y NO
    // vendido RESTA (inversión en rojo) y cada venta SUMA. Para el REVENDEDOR (o>0) el ingreso es solo lo
    // que vendió a un CLIENTE (las entregas del admin sin cliente aún no son ingreso); para el ADMIN (o=0)
    // cualquier venta suya (a clientes o a revendedores) es ingreso. (Petición del cliente 01/08.)
    if ($o > 0) {
      $income = (float)$pdo->query("SELECT COALESCE(SUM(precio),0) FROM streaming_ventas WHERE owner_id=$o AND estado<>'cancelada' AND ((cliente_nombre IS NOT NULL AND cliente_nombre<>'') OR COALESCE(cliente_id,0)>0)")->fetchColumn();
    } else {
      $income = (float)$pdo->query("SELECT COALESCE(SUM(precio),0) FROM streaming_ventas WHERE owner_id=$o AND estado<>'cancelada'")->fetchColumn();
    }
    $invest = (float)$pdo->query("SELECT COALESCE(SUM(costo),0) FROM streaming_cuentas WHERE owner_id=$o AND costo>0")->fetchColumn();
    $k['costo_mes']    = $invest;
    $k['ganancia_mes'] = $income - $invest;
  } catch (Throwable $e) {}
  // Deuda a proveedores = costo de cuentas activas aún no marcadas como pagadas (columna costo_pagado puede no existir)
  try { $k['deuda'] = (float)$pdo->query("SELECT COALESCE(SUM(costo),0) FROM streaming_cuentas WHERE owner_id=$o AND estado='activa' AND COALESCE(costo_pagado,0)=0 AND costo>0")->fetchColumn(); } catch (Throwable $e) {}
  try {
    $k['libres']   = (int)$pdo->query("SELECT COUNT(*) FROM streaming_perfiles sp JOIN streaming_cuentas c ON c.id=sp.cuenta_id WHERE c.owner_id=$o AND sp.estado='libre'")->fetchColumn();
    $k['perfiles'] = (int)$pdo->query("SELECT COUNT(*) FROM streaming_perfiles sp JOIN streaming_cuentas c ON c.id=sp.cuenta_id WHERE c.owner_id=$o AND sp.estado<>'inactivo'")->fetchColumn();
    $k['cuentas']  = (int)$pdo->query("SELECT COUNT(*) FROM streaming_cuentas WHERE owner_id=$o")->fetchColumn();
  } catch (Throwable $e) {}
  return $k;
}

/** Ventas y $ por asesor (empleado que creó la venta) este mes. */
function st_asesores(PDO $pdo): array {
  $o = (int) stream_owner_id();
  try {
    return $pdo->query("SELECT COALESCE(u.nombre, CONCAT('Usuario #', v.creado_por)) AS nombre, COUNT(*) n, COALESCE(SUM(v.precio),0) total
        FROM streaming_ventas v LEFT JOIN usuarios u ON u.id = v.creado_por
        WHERE v.owner_id=$o AND v.estado<>'cancelada' AND v.creado_por IS NOT NULL AND MONTH(v.creado_en)=MONTH(NOW()) AND YEAR(v.creado_en)=YEAR(NOW())
        GROUP BY v.creado_por ORDER BY total DESC")->fetchAll(PDO::FETCH_ASSOC);
  } catch (Throwable $e) { return []; }
}

/** Vencimientos de los próximos 14 días: [YYYY-MM-DD => cuántas vencen]. */
function st_agenda(PDO $pdo): array {
  $o = (int) stream_owner_id();
  $out = [];
  try {
    foreach ($pdo->query("SELECT fecha_vencimiento d, COUNT(*) n FROM streaming_ventas
        WHERE owner_id=$o AND estado='activa' AND fecha_vencimiento BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 14 DAY)
        GROUP BY fecha_vencimiento ORDER BY fecha_vencimiento")->fetchAll(PDO::FETCH_ASSOC) as $r)
      $out[substr($r['d'], 0, 10)] = (int)$r['n'];
  } catch (Throwable $e) {}
  return $out;
}

/** Datos para la pestaña "Web": por plataforma activa, su producto del catálogo + planes + stock libre. */
function st_web_data(PDO $pdo): array {
  $out = [];
  $plats = st_plataformas($pdo, true);
  $stock = [];
  try {
    foreach ($pdo->query("SELECT c.plataforma_id, COUNT(*) n FROM streaming_perfiles sp
            JOIN streaming_cuentas c ON c.id=sp.cuenta_id
            WHERE sp.estado='libre' AND c.estado='activa' AND c.plataforma_id IS NOT NULL
            GROUP BY c.plataforma_id") as $r) $stock[(int)$r['plataforma_id']] = (int)$r['n'];
  } catch (Throwable $e) {}
  $prodById = []; $vars = [];
  try { foreach ($pdo->query("SELECT id, activo FROM productos WHERE provider='streaming'")->fetchAll(PDO::FETCH_ASSOC) as $pr) $prodById[$pr['id']] = $pr; } catch (Throwable $e) {}
  try {
    foreach ($pdo->query("SELECT id, producto_id, nombre, precio, activo, provider_product_code FROM variantes WHERE producto_id LIKE 'stream-%' ORDER BY producto_id, orden, id")->fetchAll(PDO::FETCH_ASSOC) as $r)
      $vars[$r['producto_id']][] = $r;
  } catch (Throwable $e) {}
  foreach ($plats as $p) {
    $pid = 'stream-' . $p['id'];
    $out[] = ['plataforma' => $p, 'producto_id' => $pid,
      'publicado' => isset($prodById[$pid]), 'producto_activo' => isset($prodById[$pid]) ? (int)$prodById[$pid]['activo'] : 0,
      'planes' => $vars[$pid] ?? [], 'stock' => $stock[(int)$p['id']] ?? 0];
  }
  return $out;
}

/** Asigna N perfiles libres de una cuenta a una venta (los marca vendidos). Devuelve cuántos asignó DE VERDAD.
 *  Claim ATÓMICO por perfil (UPDATE ... WHERE estado='libre' + rowCount): antes el UPDATE no llevaba el
 *  guard 'libre', así que dos ventas concurrentes de la misma cuenta podían reclamar el MISMO perfil
 *  (mismas credenciales a dos clientes). Con el guard, el perdedor de la carrera afecta 0 filas y no cuenta. */
function st_asignar_perfiles(PDO $pdo, int $cuentaId, int $ventaId, int $n = 1): int {
  $n = max(1, $n);
  $ids = $pdo->prepare("SELECT id FROM streaming_perfiles WHERE cuenta_id=? AND estado='libre' ORDER BY id LIMIT $n");
  $ids->execute([$cuentaId]);
  $libres = $ids->fetchAll(PDO::FETCH_COLUMN);
  $ok = 0;
  $up = $pdo->prepare("UPDATE streaming_perfiles SET estado='vendido', venta_id=? WHERE id=? AND estado='libre'");
  foreach ($libres as $pid) { $up->execute([$ventaId, $pid]); if ($up->rowCount() === 1) $ok++; }
  return $ok;   // solo reclamos reales (no candidatos que otro ya tomó)
}

/* Propaga las credenciales de una CUENTA a sus VENTAS activas. Devuelve cuántas ventas sincronizó.
 *
 * POR QUÉ EXISTE (no borrar en el próximo rework): streaming_ventas guarda una COPIA de correo/clave
 * del momento de la venta (DDL: "-- credenciales entregadas"). Si solo se actualiza streaming_cuentas,
 * el CLIENTE sigue viendo la clave VIEJA en cuenta/acceso.php (el portal del link de WhatsApp) y no
 * puede entrar → reclamos/reembolsos. El panel viejo ya lo hacía (streaming.php:304 y :316); el rework
 * "estilo PAC" copió el editar_cuenta sin esta parte y se perdió. Todo escritor de streaming_cuentas
 * .correo/.clave DEBE llamar a este helper (incluido sync-pac.php, que si no re-desincroniza solo).
 *
 * REGLAS DE SEGURIDAD (verificadas en código, no asumidas):
 *  - Solo toca correo y clave. NUNCA email_activar (= correo del CLIENTE en Canva/email_manual),
 *    ni perfil, ni pin.
 *  - En Canva/email_manual v.correo/v.clave SÍ son el login maestro de la cuenta → sincronizar es correcto.
 *  - Un valor vacío NO pisa una credencial buena (NULLIF-en-PHP + COALESCE en SQL).
 *  - Solo estado='activa': reescribir ventas canceladas/vencidas falsearía el histórico de qué se entregó.
 */
/* Elimina una cuenta DE VERDAD (decisión del dueño), pero SIN dejar ventas zombis.
 *
 * EL BUG QUE ARREGLA: el borrado viejo hacía DELETE de perfiles + cuenta y NO tocaba
 * streaming_ventas — y no hay ni una FOREIGN KEY. Resultado: ventas 'activa' de clientes que YA
 * PAGARON apuntando a un cuenta_id muerto, que seguían mostrando credenciales y recibiendo
 * recordatorios; el margen se falseaba (LEFT JOIN) o desaparecían del reporte (JOIN interno); y
 * sync-pac las RESUCITABA por pac_id creando perfiles nuevos 'libre' → SOBREVENTA.
 *
 * Reglas: la VENTA NUNCA se borra (es historial de dinero) → se cancela y se anota qué pasó.
 * Preflight: si hay ventas activas, ABORTA salvo $forzar (para que no borres sin saber).
 * Devuelve ['ok'=>bool, 'msg'=>string, 'ventas'=>int].
 */
function st_eliminar_cuenta(PDO $pdo, int $cuentaId, bool $forzar = false): array {
  if ($cuentaId <= 0) return ['ok' => false, 'msg' => '⚠ Cuenta inválida.', 'ventas' => 0];
  $o = (int) stream_owner_id();

  try {
    // TODO dentro de UNA transacción, y bloqueando la cuenta ANTES de contar: si el preflight
    // corriera fuera, un revendedor podría comprar en esos milisegundos y su venta recién PAGADA se
    // cancelaría en silencio (su claim usa FOR UPDATE sobre la cuenta, así que este candado colisiona
    // con él y uno de los dos espera).
    // owner_id: la cuenta DEBE ser del dueño del contexto (anti-IDOR en borrado simple/masivo).
    $pdo->beginTransaction();
    $lock = $pdo->prepare("SELECT id FROM streaming_cuentas WHERE id=? AND owner_id=? FOR UPDATE");
    $lock->execute([$cuentaId, $o]);
    if ($lock->fetch() === false) {
      $pdo->rollBack();
      return ['ok' => false, 'msg' => '⚠ Cuenta no encontrada.', 'ventas' => 0];
    }

    // Para el ADMIN (owner 0): las ventas que son COMPRAS DE REVENDEDORES (revendedor_id>0) NO exigen
    // «Forzar» — justamente al borrar la cuenta hay que quitarles su stock espejo. Antes CUALQUIER venta
    // activa (incl. compras de revendedor) bloqueaba → el admin no borraba y quedaba la cuenta fantasma.
    // Para el REVENDEDOR (owner>0) se cuentan TODAS sus ventas (su protección de Forzar se mantiene).
    $blockExtra = ($o === 0) ? " AND (revendedor_id IS NULL OR revendedor_id=0)" : "";
    $q = $pdo->prepare("SELECT COUNT(*) FROM streaming_ventas WHERE cuenta_id=? AND estado='activa'" . $blockExtra);
    $q->execute([$cuentaId]);
    $vivas = (int) $q->fetchColumn();

    if ($vivas > 0 && !$forzar) {
      $pdo->rollBack();
      return ['ok' => false, 'ventas' => $vivas,
              'msg' => "⚠ NO borré nada: esta cuenta tiene $vivas venta(s) ACTIVA(S) de clientes que pagaron. "
                     . "Marca «Forzar» si igual quieres borrarla (esas ventas quedarán canceladas)."];
    }

    // 1) La venta NO se borra: se desvincula, se cancela y se deja constancia.
    $upd = $pdo->prepare("UPDATE streaming_ventas
                             SET cuenta_id = NULL, estado = 'cancelada',
                                 notas = CONCAT(COALESCE(notas,''), ' [cuenta #', ?, ' eliminada ', ?, ']')
                           WHERE cuenta_id = ? AND estado = 'activa'");
    $upd->execute([$cuentaId, date('Y-m-d H:i'), $cuentaId]);
    $canceladas = $upd->rowCount();   // el número REAL, no el del preflight
    // 2) Ahora sí, perfiles y cuenta.
    $pdo->prepare("DELETE FROM streaming_perfiles WHERE cuenta_id=?")->execute([$cuentaId]);
    $pdo->prepare("DELETE FROM streaming_cuentas WHERE id=?")->execute([$cuentaId]);
    // 2b) STOCK ESPEJO DEL REVENDEDOR (Fase 2): al borrar la cuenta, quítale también el stock espejo
    //     que nació de ella (cuentas + perfiles) y cancela sus ventas activas ligadas. Si no, el
    //     revendedor se quedaba con stock fantasma de una cuenta que ya no existe.
    try {
      $esp = $pdo->prepare("SELECT id, correo, owner_id FROM streaming_cuentas WHERE origen_cuenta_id=?");
      $esp->execute([$cuentaId]);
      foreach ($esp->fetchAll(PDO::FETCH_ASSOC) as $er) {
        $eid = (int) $er['id'];
        // BOT de códigos: al quitarle el stock al revendedor, DESASIGNAR el correo de la cuenta de su correo.
        if (function_exists('bot_codigos_desasignar')) { try { bot_codigos_desasignar($pdo, (string) ($er['correo'] ?? ''), (int) ($er['owner_id'] ?? 0)); } catch (Throwable $e) {} }
        // BORRAR (no cancelar) las ventas del espejo: "si el admin la elimina, a ella se le elimina en
        // TODOS lados" (stock de cuentas, perfiles y ventas), sin dejar fantasmas.
        try { $pdo->prepare("DELETE FROM streaming_ventas WHERE cuenta_id=?")->execute([$eid]); } catch (Throwable $e) {}
        try { $pdo->prepare("DELETE FROM streaming_perfiles WHERE cuenta_id=?")->execute([$eid]); } catch (Throwable $e) {}
        try { $pdo->prepare("DELETE FROM streaming_cuentas WHERE id=?")->execute([$eid]); } catch (Throwable $e) {}
      }
    } catch (Throwable $e) {}
    $pdo->commit();
  } catch (Throwable $e) {
    try { if ($pdo->inTransaction()) $pdo->rollBack(); } catch (Throwable $e2) {}
    return ['ok' => false, 'msg' => '⚠ No se pudo eliminar: ' . $e->getMessage(), 'ventas' => 0];
  }

  return ['ok' => true, 'ventas' => $canceladas,
          'msg' => '✓ Cuenta eliminada.' . ($canceladas > 0 ? " $canceladas venta(s) quedaron CANCELADAS (no se borró el historial)." : '')];
}

function st_propagar_credenciales(PDO $pdo, int $cuentaId, ?string $correo, ?string $clave): int {
  $correo = ($correo !== null && trim($correo) !== '') ? trim($correo) : null;
  $clave  = ($clave  !== null && trim($clave)  !== '') ? trim($clave)  : null;
  if ($cuentaId <= 0 || ($correo === null && $clave === null)) return 0;
  $o = (int) stream_owner_id();
  try {
    $st = $pdo->prepare("UPDATE streaming_ventas SET correo = COALESCE(?, correo), clave = COALESCE(?, clave)
                          WHERE cuenta_id = ? AND owner_id = ? AND estado = 'activa'");
    $st->execute([$correo, $clave, $cuentaId, $o]);
    return $st->rowCount();
  } catch (Throwable $e) { return 0; }
}

/* ============================================================================
 * FASE 2 — STOCK PROPIO DEL REVENDEDOR
 * ----------------------------------------------------------------------------
 * Cuando un revendedor COMPRA del stock del admin, lo comprado pasa a ser SU inventario
 * (una cuenta/perfiles "espejo" con owner_id = su id), no una venta cerrada. Así puede:
 *   - verlo en sus Perfiles / Cuentas,
 *   - vendérselo a su cliente cuando quiera,
 *   - si borra esa venta, el perfil vuelve A SU stock (mientras le queden días).
 * Al ADMIN, en cambio, le queda una VENTA al revendedor desde el momento de la compra
 * (pase lo que pase después con ese perfil del lado del revendedor).
 *
 * Las columnas origen_* enlazan el espejo con el original para poder sincronizar credenciales.
 * ========================================================================== */

/** Columnas de enlace espejo↔original (idempotente). */
function st_rev_stock_schema(PDO $pdo): void {
  static $done = false;
  if ($done) return;
  // OJO (MySQL): ALTER/CREATE hacen COMMIT IMPLÍCITO. Si esto corriera DENTRO de una transacción
  // (compra / venta / asignación manual), la cerraría a la mitad y el $pdo->commit() de después
  // reventaba con "There is no active transaction" (la compra igual quedaba a medias → ese era el
  // error "No se pudo completar la compra"). Por eso: si YA hay una transacción activa, no tocamos el
  // esquema aquí y NO marcamos $done — se asegura en la carga de la página (fuera de transacción),
  // que es donde SIEMPRE se llama antes de cualquier beginTransaction.
  if ($pdo->inTransaction()) return;
  $done = true;
  try { $pdo->exec("ALTER TABLE streaming_cuentas ADD COLUMN origen_cuenta_id INT NULL"); } catch (Throwable $e) {}
  // rev_editable=1 → el revendedor compró la CUENTA COMPLETA y sí puede editar su clave/perfiles/PINes.
  try { $pdo->exec("ALTER TABLE streaming_cuentas ADD COLUMN rev_editable TINYINT(1) NOT NULL DEFAULT 0"); } catch (Throwable $e) {}
  try { $pdo->exec("ALTER TABLE streaming_perfiles ADD COLUMN origen_perfil_id INT NULL"); } catch (Throwable $e) {}
  // cambios_np = cuántas veces el revendedor cambió nombre/PIN de un PERFIL suelto (máx 2, se reinicia
  // al renovar). Solo aplica a perfiles de cuenta NO editable (compartida); la cuenta completa no limita.
  try { $pdo->exec("ALTER TABLE streaming_perfiles ADD COLUMN cambios_np TINYINT(1) NOT NULL DEFAULT 0"); } catch (Throwable $e) {}
  // usuarios: activo (pausar sin borrar) + puede_exportar (anti-secuestro).
  try { $pdo->exec("ALTER TABLE usuarios ADD COLUMN rev_activo TINYINT(1) NOT NULL DEFAULT 1"); } catch (Throwable $e) {}
  try { $pdo->exec("ALTER TABLE usuarios ADD COLUMN rev_export TINYINT(1) NOT NULL DEFAULT 1"); } catch (Throwable $e) {}
  // Plataforma por INVITACIÓN (Canva/Spotify): al crearla no pide credenciales; al comprarla el
  // revendedor pone su correo (invitación) o correo+clave (activación).
  try { $pdo->exec("ALTER TABLE streaming_plataformas ADD COLUMN tipo_entrega VARCHAR(20) NOT NULL DEFAULT 'stock'"); } catch (Throwable $e) {}
  // El tipo de venta ya no es solo perfil/cuenta: también 'invitacion' y 'activacion' (entrega manual).
  // Se amplía el ENUM a VARCHAR para no romper las ventas de esas plataformas.
  try { $pdo->exec("ALTER TABLE streaming_ventas MODIFY COLUMN tipo VARCHAR(20) NOT NULL DEFAULT 'perfil'"); } catch (Throwable $e) {}
  // modo_entrega también: de ENUM('perfil','email_manual') → VARCHAR para aceptar invitacion/activacion.
  try { $pdo->exec("ALTER TABLE streaming_plataformas MODIFY COLUMN modo_entrega VARCHAR(20) NOT NULL DEFAULT 'perfil'"); } catch (Throwable $e) {}
  // Enlace venta del REVENDEDOR → venta del ADMIN (invitación/activación): al aprobar el admin la suya,
  // se auto-aprueba la del revendedor (compró a la tienda). Ver comprar.php / pendientes.php.
  try { $pdo->exec("ALTER TABLE streaming_ventas ADD COLUMN origen_venta_id INT NULL"); } catch (Throwable $e) {}
  // COMPLETA POR ACTIVACIÓN (Spotify Familiar / YouTube Premium Familiar): nº de cupos/perfiles de la
  // cuenta completa. Cuando el admin APRUEBA una compra completa por activación, se le crea al revendedor
  // una cuenta con estos cupos en su STOCK. cupos NULL/0 = activación de PERFIL (se queda solo en Ventas).
  try { $pdo->exec("ALTER TABLE streaming_ventas ADD COLUMN cupos INT NULL"); } catch (Throwable $e) {}
  // Columnas que usa la compra por INVITACIÓN/ACTIVACIÓN (Canva/Spotify). Estaban solo en el CREATE TABLE,
  // así que en una tabla VIEJA del cliente faltaban → el INSERT de invitación fallaba ("No se pudo
  // completar la compra") aunque las compras NORMALES (stock) sí funcionaban (no las usan). email_activar
  // es la clave: solo la usa la invitación. Se aseguran aquí, FUERA de la transacción.
  try { $pdo->exec("ALTER TABLE streaming_ventas ADD COLUMN email_activar VARCHAR(160) NULL"); } catch (Throwable $e) {}
  try { $pdo->exec("ALTER TABLE streaming_ventas ADD COLUMN precio_venta_cliente DECIMAL(10,2) NULL"); } catch (Throwable $e) {}
  try { $pdo->exec("ALTER TABLE streaming_ventas ADD COLUMN cliente_id INT NULL"); } catch (Throwable $e) {}
  try { $pdo->exec("ALTER TABLE streaming_ventas ADD COLUMN cliente_wa VARCHAR(30) NULL"); } catch (Throwable $e) {}
  // BOT de códigos: bandera para asignar el correo al revendedor EXACTAMENTE UNA VEZ (prycorreos cuenta
  // perfiles; ver bot_codigos_flush). 0 = falta asignar; 1 = ya asignado (o cuenta que no aplica).
  try { $pdo->exec("ALTER TABLE streaming_cuentas ADD COLUMN bot_asignado TINYINT(1) NOT NULL DEFAULT 0"); } catch (Throwable $e) {}
  // Migración ÚNICA: marca las cuentas espejo YA EXISTENTES como asignadas (bot_asignado=1) para NO
  // dispararle a prycorreos un "asignar" masivo de todo el stock viejo (que descuadraría sus conteos).
  // De aquí en adelante, cada cuenta espejo nueva nace en 0 y bot_codigos_flush la asigna una sola vez.
  try {
    $colOk = (bool) $pdo->query("SHOW COLUMNS FROM streaming_cuentas LIKE 'bot_asignado'")->fetch();
    if ($colOk) {
      $mig = '';
      try { $mig = (string) ($pdo->query("SELECT valor FROM configuracion WHERE clave='bot_asig_migrado' LIMIT 1")->fetchColumn() ?: ''); } catch (Throwable $e) {}
      if ($mig !== '1') {
        try { $pdo->exec("UPDATE streaming_cuentas SET bot_asignado=1 WHERE COALESCE(owner_id,0)>0 AND COALESCE(origen_cuenta_id,0)>0"); } catch (Throwable $e) {}
        try { $pdo->exec("CREATE TABLE IF NOT EXISTS configuracion (clave VARCHAR(64) NOT NULL PRIMARY KEY, valor TEXT NULL)"); } catch (Throwable $e) {}
        try {
          $ex = $pdo->prepare("SELECT COUNT(*) FROM configuracion WHERE clave='bot_asig_migrado'"); $ex->execute();
          if ((int) $ex->fetchColumn() > 0) $pdo->prepare("UPDATE configuracion SET valor='1' WHERE clave='bot_asig_migrado'")->execute();
          else $pdo->prepare("INSERT INTO configuracion (clave,valor) VALUES ('bot_asig_migrado','1')")->execute();
        } catch (Throwable $e) {}
      }
    }
  } catch (Throwable $e) {}
}

/** Busca (o crea) en el inventario del revendedor la cuenta espejo de una cuenta del admin.
 *  Agrupa por origen para no llenarle el panel de cuentas de 1 perfil cada una. */
function st_rev_mirror_cuenta(PDO $pdo, int $revId, array $cta, float $costoUnit, bool $editable = false, ?string $vencimiento = null): int {
  st_rev_stock_schema($pdo);
  $origen = (int) ($cta['id'] ?? 0);
  if ($revId <= 0 || $origen <= 0) return 0;
  // Fecha de vencimiento DEL REVENDEDOR (desde que compró): si se pasa, se usa esa (no la del admin).
  $venc = ($vencimiento !== null && $vencimiento !== '') ? $vencimiento : ($cta['vencimiento'] ?? null);
  try {
    $q = $pdo->prepare("SELECT id FROM streaming_cuentas WHERE owner_id=? AND origen_cuenta_id=? LIMIT 1");
    $q->execute([$revId, $origen]);
    $ya = (int) ($q->fetchColumn() ?: 0);
    if ($ya > 0) {
      // Si ahora compró la cuenta completa, se le habilita la edición sobre la que ya tenía.
      if ($editable) { try { $pdo->prepare("UPDATE streaming_cuentas SET rev_editable=1 WHERE id=?")->execute([$ya]); } catch (Throwable $e) {} }
      // Refresca su fecha de vencimiento a la de esta compra (la más reciente).
      if ($venc !== null) { try { $pdo->prepare("UPDATE streaming_cuentas SET vencimiento=? WHERE id=?")->execute([$venc, $ya]); } catch (Throwable $e) {} }
      return $ya;
    }
  } catch (Throwable $e) {}
  try {
    $pdo->prepare("INSERT INTO streaming_cuentas
        (owner_id, plataforma, plataforma_id, correo, clave, perfiles_total, usa_pin, costo, vencimiento, estado, origen_cuenta_id, rev_editable)
        VALUES (?,?,?,?,?,0,?,?,?, 'activa', ?, ?)")
      ->execute([
        $revId,
        (string) ($cta['plataforma'] ?? ''),
        ((int) ($cta['plataforma_id'] ?? 0)) ?: null,
        (string) ($cta['correo'] ?? ''),
        (string) ($cta['clave'] ?? ''),
        (int) ($cta['usa_pin'] ?? 0),
        round($costoUnit, 2),
        $venc,
        $origen,
        $editable ? 1 : 0,
      ]);
    return (int) $pdo->lastInsertId();
  } catch (Throwable $e) { return 0; }
}

/** Crea en el stock del revendedor el perfil espejo de un perfil del admin. Devuelve su id. */
function st_rev_mirror_perfil(PDO $pdo, int $mirrorCuentaId, array $perfilOrigen, float $costoUnit = 0.0): int {
  st_rev_stock_schema($pdo);
  if ($mirrorCuentaId <= 0) return 0;
  try {
    $pdo->prepare("INSERT INTO streaming_perfiles (cuenta_id, etiqueta, pin, estado, origen_perfil_id) VALUES (?,?,?, 'libre', ?)")
      ->execute([
        $mirrorCuentaId,
        (string) ($perfilOrigen['etiqueta'] ?? ''),
        (string) ($perfilOrigen['pin'] ?? ''),
        ((int) ($perfilOrigen['id'] ?? 0)) ?: null,
      ]);
    $pid = (int) $pdo->lastInsertId();
    // perfiles_total del espejo = cuántos perfiles tiene de verdad.
    try { $pdo->prepare("UPDATE streaming_cuentas SET perfiles_total=(SELECT COUNT(*) FROM streaming_perfiles WHERE cuenta_id=?) WHERE id=?")->execute([$mirrorCuentaId, $mirrorCuentaId]); } catch (Throwable $e) {}
    // COSTO TOTAL de la cuenta espejo = costo por perfil × nº de perfiles. Así el dashboard imputa
    // bien el costo por venta (costo/perfiles_total = costoUnit) y la ganancia cuenta LOS DOS perfiles,
    // no uno. (Antes el costo quedaba fijo al de 1 perfil y se dividía entre 2 → ganancia inflada/mal.)
    if ($costoUnit > 0) {
      try { $pdo->prepare("UPDATE streaming_cuentas SET costo = ROUND(? * GREATEST(perfiles_total,1), 2) WHERE id=?")->execute([round($costoUnit, 2), $mirrorCuentaId]); } catch (Throwable $e) {}
    }
    return $pid;
  } catch (Throwable $e) { return 0; }
}

/** ¿El revendedor del contexto está PAUSADO (rev_activo=0)? Se usa para bloquear comprar/recargar/vender
 *  sin borrarle nada. El admin nunca está pausado. */
function st_rev_pausado(PDO $pdo): bool {
  if (!function_exists('stream_ctx') || stream_ctx() !== 'revendedor') return false;
  $uid = (int) (function_exists('current_user_id') ? (current_user_id() ?? 0) : 0);
  if ($uid <= 0) return false;
  // OJO: NO usar `fetchColumn() ?: 1` — cuando rev_activo=0 el 0 es "falsy" y `?: 1` lo volvía 1
  // (→ nunca detectaba la pausa). Se lee el valor tal cual y solo se cae a "no pausado" si NO hay dato.
  try {
    $v = $pdo->query("SELECT COALESCE(rev_activo,1) FROM usuarios WHERE id=$uid")->fetchColumn();
    if ($v === false || $v === null) return false;
    return ((int) $v) === 0;
  } catch (Throwable $e) { return false; }
}

/** ¿El revendedor del contexto puede EXPORTAR/descargar (rev_export)? El admin siempre puede. */
function st_rev_puede_exportar(PDO $pdo): bool {
  if (!function_exists('stream_ctx') || stream_ctx() !== 'revendedor') return true;
  $uid = (int) (function_exists('current_user_id') ? (current_user_id() ?? 0) : 0);
  if ($uid <= 0) return true;
  // MISMO BUG que arriba: `fetchColumn() ?: 1` convertía rev_export=0 en 1 → el bloqueo de exportar
  // NUNCA funcionaba. Se lee el valor real; solo se permite por defecto si no hay dato o falla la query.
  try {
    $v = $pdo->query("SELECT COALESCE(rev_export,1) FROM usuarios WHERE id=$uid")->fetchColumn();
    if ($v === false || $v === null) return true;
    return ((int) $v) === 1;
  } catch (Throwable $e) { return true; }
}

/** LIMPIEZA DE FANTASMAS (standalone, robusta, sin dependencias). Borra del stock de CUALQUIER revendedor
 *  las cuentas ESPEJO cuya cuenta ORIGEN del admin ya no existe → se van cuentas + perfiles + ventas.
 *  Se llama SIEMPRE al inicio de cuentas.php / perfiles.php (aislada, en su propio try, para que ni un
 *  error de schema ni el barrido de vencidas la bloqueen). Devuelve cuántas limpió. Nunca lanza. */
function st_rev_limpiar_fantasmas(PDO $pdo): int {
  $n = 0;
  try {
    // Solo si la columna origen_cuenta_id existe (si no, no hay espejos que limpiar).
    $tieneCol = false;
    try { $tieneCol = (bool) $pdo->query("SHOW COLUMNS FROM streaming_cuentas LIKE 'origen_cuenta_id'")->fetch(); } catch (Throwable $e) { return 0; }
    if (!$tieneCol) return 0;
    $orf = $pdo->query("SELECT c.id, c.correo, c.owner_id FROM streaming_cuentas c
                        WHERE COALESCE(c.owner_id,0) > 0
                          AND c.origen_cuenta_id IS NOT NULL AND c.origen_cuenta_id > 0
                          AND NOT EXISTS (SELECT 1 FROM streaming_cuentas o WHERE o.id = c.origen_cuenta_id)")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($orf as $er) {
      $eid = (int) $er['id'];
      // BOT de códigos: desasignar el correo de la cuenta del correo del revendedor.
      if (function_exists('bot_codigos_desasignar')) { try { bot_codigos_desasignar($pdo, (string) ($er['correo'] ?? ''), (int) ($er['owner_id'] ?? 0)); } catch (Throwable $e) {} }
      try { $pdo->prepare("DELETE FROM streaming_ventas WHERE cuenta_id=?")->execute([$eid]); } catch (Throwable $e) {}
      try { $pdo->prepare("DELETE FROM streaming_perfiles WHERE cuenta_id=?")->execute([$eid]); } catch (Throwable $e) {}
      try { $pdo->prepare("DELETE FROM streaming_cuentas WHERE id=?")->execute([$eid]); } catch (Throwable $e) {}
      $n++;
    }
  } catch (Throwable $e) {}
  return $n;
}

/** Quita del stock del REVENDEDOR lo que corresponde a una VENTA del admin eliminada, y DESASIGNA el
 *  correo en el bot con cuidado. Se usa cuando el ADMIN elimina una VENTA a un revendedor: "al eliminar
 *  la venta ya no la tienen" (su perfil del admin, aparte, vuelve al stock del admin).
 *
 *  $adminPerfilIds = los perfiles del ADMIN que cubría esa venta. Si se pasan, el quitado es POR PERFIL:
 *  se le quita al revendedor SOLO el/los perfil(es) espejo de esos perfiles — así, si el revendedor tiene
 *  DOS perfiles de la MISMA cuenta (mismo correo) y borras una venta, se le quita SOLO ese perfil, no el
 *  otro. Si la cuenta espejo se queda sin perfiles, se borra entera. Si NO se pasan perfiles (venta vieja
 *  sin enlace), cae al comportamiento anterior: quita la cuenta espejo completa.
 *
 *  BOT: solo se DESASIGNA el correo cuando al revendedor YA NO le queda NINGÚN perfil de ese correo (así
 *  no le quitamos el acceso a los códigos mientras aún tenga otro perfil de la misma cuenta). Nunca lanza. */
function st_rev_quitar_por_origen(PDO $pdo, int $revId, int $adminCuentaId, array $adminPerfilIds = []): int {
  if ($revId <= 0 || $adminCuentaId <= 0) return 0;
  $quitados = 0;
  try {
    $esp = $pdo->prepare("SELECT id, correo FROM streaming_cuentas WHERE owner_id=? AND origen_cuenta_id=?");
    $esp->execute([$revId, $adminCuentaId]);
    $mirrors = $esp->fetchAll(PDO::FETCH_ASSOC);
    $pids = array_values(array_filter(array_map('intval', $adminPerfilIds), static fn($x) => $x > 0));

    foreach ($mirrors as $er) {
      $mid    = (int) $er['id'];
      $correo = (string) ($er['correo'] ?? '');

      if ($pids) {
        // POR PERFIL: solo los perfiles espejo que nacieron de esos perfiles del admin (origen_perfil_id).
        $in = implode(',', $pids);
        $mps = $pdo->query("SELECT id, COALESCE(venta_id,0) AS vid FROM streaming_perfiles WHERE cuenta_id=$mid AND origen_perfil_id IN ($in)")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($mps as $mp) {
          $mpid = (int) $mp['id']; $rvta = (int) $mp['vid'];
          // si el revendedor ya se lo había vendido a su cliente, borra también esa venta suya.
          if ($rvta > 0) { try { $pdo->prepare("DELETE FROM streaming_ventas WHERE id=? AND owner_id=?")->execute([$rvta, $revId]); } catch (Throwable $e) {} }
          try { $pdo->prepare("DELETE FROM streaming_perfiles WHERE id=?")->execute([$mpid]); } catch (Throwable $e) {}
          $quitados++;
        }
        // Si la cuenta espejo se quedó SIN perfiles, se borra entera (con sus ventas sueltas).
        $rest = (int) ($pdo->query("SELECT COUNT(*) FROM streaming_perfiles WHERE cuenta_id=$mid")->fetchColumn() ?: 0);
        if ($rest === 0) {
          try { $pdo->prepare("DELETE FROM streaming_ventas WHERE cuenta_id=?")->execute([$mid]); } catch (Throwable $e) {}
          try { $pdo->prepare("DELETE FROM streaming_cuentas WHERE id=?")->execute([$mid]); } catch (Throwable $e) {}
        }
      } else {
        // SIN datos de perfiles (venta vieja): comportamiento anterior → cuenta espejo completa.
        try { $pdo->prepare("DELETE FROM streaming_ventas WHERE cuenta_id=?")->execute([$mid]); } catch (Throwable $e) {}
        try { $pdo->prepare("DELETE FROM streaming_perfiles WHERE cuenta_id=?")->execute([$mid]); } catch (Throwable $e) {}
        try { $pdo->prepare("DELETE FROM streaming_cuentas WHERE id=?")->execute([$mid]); } catch (Throwable $e) {}
        $quitados++;
      }

      // BOT: desasignar el correo SOLO si al revendedor YA NO le queda NINGÚN perfil de ese correo.
      // (Cuenta sobre TODO su inventario, después de quitar. Ante cualquier duda, NO se desasigna.)
      if ($correo !== '' && function_exists('bot_codigos_desasignar')) {
        $sigue = 1;
        try {
          $c = $pdo->prepare("SELECT COUNT(*) FROM streaming_perfiles p JOIN streaming_cuentas c ON c.id=p.cuenta_id WHERE c.owner_id=? AND c.correo=?");
          $c->execute([$revId, $correo]);
          $sigue = (int) $c->fetchColumn();
        } catch (Throwable $e) { $sigue = 1; }
        if ($sigue === 0) { try { bot_codigos_desasignar($pdo, $correo, $revId); } catch (Throwable $e) {} }
      }
    }
  } catch (Throwable $e) {}
  return $quitados;
}

/** BARRIDO: el stock del revendedor que COMPRÓ (cuenta espejo) tiene una fecha de vencimiento = su
 *  periodo pagado. Si venció y NO lo renovó (y no tiene perfiles vendidos a un cliente), se le quita
 *  del stock y le llega una notificación al ADMIN ("el revendedor X no renovó tal cuenta"). Sin cron:
 *  se llama al abrir Perfiles (a lo sumo 1 vez/hora por un guard en `configuracion`). Nunca lanza. */
function st_rev_sweep_vencidos(PDO $pdo): int {
  try {
    st_rev_stock_schema($pdo);
    $n = 0;
    // ── (A) LIMPIEZA DE FANTASMAS — corre SIEMPRE (es barata) ─────────────────────────────────────
    // Cuentas espejo del revendedor cuya cuenta ORIGEN del admin YA NO EXISTE. El cliente pidió que
    // "si el admin la elimina, a ella también se le elimine" → se borran SIEMPRE, aunque tengan
    // perfiles vendidos (la cuenta base desapareció; sus ventas se cancelan). Así no quedan fantasmas.
    try {
      $orf = $pdo->query("SELECT c.id FROM streaming_cuentas c
                          WHERE COALESCE(c.owner_id,0)>0 AND c.origen_cuenta_id IS NOT NULL
                            AND NOT EXISTS (SELECT 1 FROM streaming_cuentas o WHERE o.id=c.origen_cuenta_id)")->fetchAll(PDO::FETCH_COLUMN);
      foreach ($orf as $eid) {
        $eid = (int) $eid;
        // Se BORRAN las ventas del espejo (no solo cancelar) para que NO queden fantasmas en "Ventas".
        try { $pdo->prepare("DELETE FROM streaming_ventas WHERE cuenta_id=?")->execute([$eid]); } catch (Throwable $e) {}
        try { $pdo->prepare("DELETE FROM streaming_perfiles WHERE cuenta_id=?")->execute([$eid]); } catch (Throwable $e) {}
        try { $pdo->prepare("DELETE FROM streaming_cuentas WHERE id=?")->execute([$eid]); } catch (Throwable $e) {}
        $n++;
      }
    } catch (Throwable $e) {}
    // ── (B) BARRIDO DE VENCIDAS NO RENOVADAS — guardado 1 vez/hora (más pesado, avisa al admin) ─────
    $doExpired = true;
    try {
      $last = (int) ($pdo->query("SELECT valor FROM configuracion WHERE clave='stream_rev_last_sweep' LIMIT 1")->fetchColumn() ?: 0);
      if ($last > 0 && (time() - $last) < 3600) $doExpired = false;
    } catch (Throwable $e) {}
    if ($doExpired) {
      try { $pdo->prepare("INSERT INTO configuracion (clave, valor) VALUES ('stream_rev_last_sweep', ?) ON DUPLICATE KEY UPDATE valor=VALUES(valor)")->execute([(string) time()]); } catch (Throwable $e) {}
      $rows = $pdo->query("SELECT c.id, c.owner_id, c.plataforma, c.correo
                           FROM streaming_cuentas c
                           WHERE COALESCE(c.owner_id,0)>0 AND c.origen_cuenta_id IS NOT NULL
                             AND c.vencimiento IS NOT NULL AND c.vencimiento < CURDATE()")->fetchAll(PDO::FETCH_ASSOC);
      foreach ($rows as $c) {
        $cid = (int) $c['id']; $rev = (int) $c['owner_id'];
        // Respeta lo que ya vendió a un cliente: si tiene perfiles vendidos, no lo auto-borra.
        $vivas = (int) $pdo->query("SELECT COUNT(*) FROM streaming_perfiles WHERE cuenta_id=$cid AND estado='vendido'")->fetchColumn();
        if ($vivas > 0) continue;
        try { $pdo->prepare("DELETE FROM streaming_perfiles WHERE cuenta_id=?")->execute([$cid]); } catch (Throwable $e) {}
        try { $pdo->prepare("DELETE FROM streaming_cuentas WHERE id=?")->execute([$cid]); } catch (Throwable $e) {}
        $n++;
        try {
          if (!function_exists('stream_notif_crear')) { @require_once __DIR__ . '/../api/_rev_avisos.php'; }
          if (function_exists('stream_notif_crear')) {
            $rn = '';
            try { $rn = (string) ($pdo->query("SELECT COALESCE(NULLIF(nombre,''),username) FROM usuarios WHERE id=$rev")->fetchColumn() ?: ''); } catch (Throwable $e) {}
            stream_notif_crear($pdo, 0, 'vencimiento',
              'Stock vencido no renovado · ' . ($c['plataforma'] ?: 'cuenta'),
              'El revendedor ' . ($rn !== '' ? $rn : '#' . $rev) . ' NO renovó ' . ($c['plataforma'] ?: 'una cuenta') . ' (' . ($c['correo'] ?: '') . '). Se quitó de su stock.',
              'revendedores.php');
          }
        } catch (Throwable $e) {}
      }
    }
    return $n;
  } catch (Throwable $e) { return 0; }
}

/** Propaga correo/clave de una cuenta del admin a las cuentas ESPEJO de los revendedores
 *  (y a sus ventas activas). Se llama cuando el admin cambia las credenciales. */
function st_rev_propagar_a_espejos(PDO $pdo, int $cuentaOrigenId, ?string $correo, ?string $clave): int {
  st_rev_stock_schema($pdo);
  $correo = ($correo !== null && trim($correo) !== '') ? trim($correo) : null;
  $clave  = ($clave  !== null && trim($clave)  !== '') ? trim($clave)  : null;
  if ($cuentaOrigenId <= 0 || ($correo === null && $clave === null)) return 0;
  $n = 0;
  try {
    $q = $pdo->prepare("SELECT id, owner_id FROM streaming_cuentas WHERE origen_cuenta_id=?");
    $q->execute([$cuentaOrigenId]);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $esp) {
      $eid = (int) $esp['id'];
      try {
        $pdo->prepare("UPDATE streaming_cuentas SET correo=COALESCE(?,correo), clave=COALESCE(?,clave) WHERE id=?")
            ->execute([$correo, $clave, $eid]);
        $pdo->prepare("UPDATE streaming_ventas SET correo=COALESCE(?,correo), clave=COALESCE(?,clave) WHERE cuenta_id=? AND estado='activa'")
            ->execute([$correo, $clave, $eid]);
        $n++;
      } catch (Throwable $e) {}
    }
  } catch (Throwable $e) {}
  return $n;
}

/** Propaga una nueva FECHA DE VENCIMIENTO a las cuentas ESPEJO del revendedor (y a sus ventas ligadas).
 *  Se llama al RENOVAR una venta/cuenta del admin que era de un revendedor: sin esto, el espejo del
 *  revendedor se quedaba con la fecha vieja → (1) al revendedor NO se le veía renovado y (2) el barrido
 *  de vencidos (st_rev_sweep_vencidos) lo borraba por estar vencido → "desaparecía de todos lados".
 *  Si se pasan $adminPerfilIds, solo toca las ventas de esos perfiles espejo; si no, todas las del espejo.
 *  Nunca lanza. Devuelve cuántas ventas del revendedor actualizó. */
function st_rev_propagar_vencimiento(PDO $pdo, int $adminCuentaId, ?string $newVenc, array $adminPerfilIds = []): int {
  if ($adminCuentaId <= 0 || $newVenc === null || trim((string) $newVenc) === '') return 0;
  $n = 0;
  try {
    $esp = $pdo->prepare("SELECT id, owner_id FROM streaming_cuentas WHERE origen_cuenta_id=? AND COALESCE(owner_id,0)>0");
    $esp->execute([$adminCuentaId]);
    $pids = array_values(array_filter(array_map('intval', $adminPerfilIds), static fn($x) => $x > 0));
    foreach ($esp->fetchAll(PDO::FETCH_ASSOC) as $er) {
      $mid = (int) $er['id']; $rev = (int) $er['owner_id'];
      // 1) La cuenta ESPEJO: extiende su vencimiento (evita que el barrido la borre por vencida).
      try { $pdo->prepare("UPDATE streaming_cuentas SET vencimiento=? WHERE id=?")->execute([$newVenc, $mid]); } catch (Throwable $e) {}
      // 2) Las ventas del REVENDEDOR ligadas a esos perfiles espejo → nueva fecha (así la ve renovada).
      if ($pids) {
        $in = implode(',', $pids);
        foreach ($pdo->query("SELECT DISTINCT COALESCE(venta_id,0) v FROM streaming_perfiles WHERE cuenta_id=$mid AND origen_perfil_id IN ($in)")->fetchAll(PDO::FETCH_COLUMN) as $rvid) {
          if ((int) $rvid > 0) { try { $pdo->prepare("UPDATE streaming_ventas SET fecha_vencimiento=?, estado='activa', recordado=0 WHERE id=? AND owner_id=?")->execute([$newVenc, (int) $rvid, $rev]); $n++; } catch (Throwable $e) {} }
        }
      } else {
        try { $pdo->prepare("UPDATE streaming_ventas SET fecha_vencimiento=?, estado='activa', recordado=0 WHERE cuenta_id=?")->execute([$newVenc, $mid]); $n++; } catch (Throwable $e) {}
      }
    }
  } catch (Throwable $e) {}
  return $n;
}

/** Reasigna UNA venta a otro REVENDEDOR ($nuevoRev; 0 = quitar vendedor). Mueve la cuenta ESPEJO del
 *  revendedor viejo al nuevo (quitar + entregar) y actualiza la venta del admin. Reutilizable desde
 *  Ventas/Cuentas/Perfiles. Devuelve el id del revendedor nuevo (>0) para hacerle flush del bot, o 0.
 *  Nunca lanza. */
function st_rev_reasignar_venta(PDO $pdo, int $ventaId, int $nuevoRev): int {
  if ($ventaId <= 0) return 0;
  try {
    $v = $pdo->query("SELECT revendedor_id, cuenta_id, fecha_vencimiento FROM streaming_ventas WHERE id=" . (int) $ventaId)->fetch(PDO::FETCH_ASSOC);
    if (!$v) return 0;
    $oldRev = (int) ($v['revendedor_id'] ?? 0); $cuentaId = (int) ($v['cuenta_id'] ?? 0);
    if ($nuevoRev === $oldRev || $cuentaId <= 0) return 0;
    $perfIds = array_map('intval', $pdo->query("SELECT id FROM streaming_perfiles WHERE venta_id=" . (int) $ventaId)->fetchAll(PDO::FETCH_COLUMN));
    if ($oldRev > 0 && function_exists('st_rev_quitar_por_origen')) { try { st_rev_quitar_por_origen($pdo, $oldRev, $cuentaId, $perfIds); } catch (Throwable $e) {} }
    $flush = 0;
    if ($nuevoRev > 0 && $perfIds && function_exists('st_rev_entregar')) {
      $in = implode(',', $perfIds);
      $pf = $pdo->query("SELECT id, etiqueta, pin FROM streaming_perfiles WHERE id IN ($in)")->fetchAll(PDO::FETCH_ASSOC);
      if ($pf) { try { st_rev_entregar($pdo, $nuevoRev, $cuentaId, $pf, 0.0, false, ((string) ($v['fecha_vencimiento'] ?? '') ?: null)); } catch (Throwable $e) {} $flush = $nuevoRev; }
    }
    try { $pdo->prepare("UPDATE streaming_ventas SET revendedor_id=? WHERE id=?")->execute([($nuevoRev > 0 ? $nuevoRev : null), $ventaId]); } catch (Throwable $e) {}
    return $flush;
  } catch (Throwable $e) { return 0; }
}

/** El ADMIN le entrega perfiles a un REVENDEDOR (venta asignada o compra del stock): además de la
 *  venta del admin, el revendedor recibe ESOS perfiles en SU inventario (cuenta/perfiles espejo).
 *  Así los ve en «Perfiles», los vende a su cliente cuando quiera y, si borra esa venta suya, el
 *  perfil vuelve a su stock. Devuelve cuántos perfiles espejo creó. Nunca lanza. */
function st_rev_entregar(PDO $pdo, int $revId, int $cuentaAdminId, array $perfilesAdmin, float $costoUnit = 0.0, bool $editable = false, ?string $vencimiento = null): int {
  if ($revId <= 0 || $cuentaAdminId <= 0 || !$perfilesAdmin) return 0;
  try {
    st_rev_stock_schema($pdo);
    $q = $pdo->prepare("SELECT id, plataforma, plataforma_id, correo, clave, usa_pin, vencimiento FROM streaming_cuentas WHERE id=?");
    $q->execute([$cuentaAdminId]);
    $cta = $q->fetch(PDO::FETCH_ASSOC);
    if (!$cta) return 0;
    $mirror = st_rev_mirror_cuenta($pdo, $revId, $cta, $costoUnit, $editable, $vencimiento);
    if ($mirror <= 0) return 0;
    // BOT de códigos: NO se asigna aquí (esto corre dentro de una transacción y, además, mandaría un
    // "asignar" por CADA perfil → conteo doble en prycorreos). La cuenta espejo nace con bot_asignado=0
    // y se asigna UNA sola vez con bot_codigos_flush($pdo,$revId), que el llamador ejecuta tras el commit.
    $venc = ($vencimiento !== null && $vencimiento !== '') ? $vencimiento : ($cta['vencimiento'] ?? null);
    // Venta DIRECTA del revendedor por cada perfil entregado: la ve en SU «Ventas», y el perfil espejo
    // queda VENDIDO ligado a ella → en Perfiles/Cuentas sale como "vendido". Si BORRA la venta, el
    // eliminar_venta libera el perfil (estado='libre') → vuelve a su stock disponible.
    $insV = $pdo->prepare("INSERT INTO streaming_ventas (owner_id,plataforma,tipo,cuenta_id,correo,clave,perfil,pin,precio,precio_renovacion,fecha_inicio,fecha_vencimiento,estado,entregada,creado_por) VALUES (?,?,?,?,?,?,?,?,?,?,CURDATE(),?, 'activa', 1, ?)");
    // 1) Crea los perfiles espejo (libres); recoge los que de verdad se crearon.
    $mps = [];
    foreach ($perfilesAdmin as $pf) {
      $pid = (int) ($pf['id'] ?? 0);
      if ($pid > 0) {
        try { $chk = $pdo->prepare("SELECT COUNT(*) FROM streaming_perfiles WHERE cuenta_id=? AND origen_perfil_id=?"); $chk->execute([$mirror, $pid]); if ((int) $chk->fetchColumn() > 0) continue; } catch (Throwable $e) {}
      }
      $mpid = st_rev_mirror_perfil($pdo, $mirror, $pf, $costoUnit);
      if ($mpid <= 0) continue;
      $mps[] = ['mpid' => $mpid, 'etq' => (string) ($pf['etiqueta'] ?? ''), 'pin' => (string) ($pf['pin'] ?? '')];
    }
    if (!$mps) return 0;
    $plt = (string) ($cta['plataforma'] ?? ''); $cor = (string) ($cta['correo'] ?? ''); $clv = (string) ($cta['clave'] ?? '');
    if ($editable) {
      // CUENTA COMPLETA → UNA sola venta (tipo='cuenta') por TODOS los perfiles → 1 línea en Ventas.
      $nombres = implode(', ', array_map(static fn($x) => $x['etq'], $mps));
      $precioT = round($costoUnit * count($mps), 2);
      try {
        $insV->execute([$revId, $plt, 'cuenta', $mirror, $cor, $clv, $nombres, (string) ($mps[0]['pin'] ?? ''), $precioT, $precioT, $venc, $revId]);
        $vid = (int) $pdo->lastInsertId();
        foreach ($mps as $m) { $pdo->prepare("UPDATE streaming_perfiles SET estado='vendido', venta_id=? WHERE id=? AND estado='libre'")->execute([$vid, (int) $m['mpid']]); }
      } catch (Throwable $e) {}
    } else {
      // Por PERFIL → una venta por cada uno.
      foreach ($mps as $m) {
        try {
          $insV->execute([$revId, $plt, 'perfil', $mirror, $cor, $clv, $m['etq'], $m['pin'], round($costoUnit, 2), round($costoUnit, 2), $venc, $revId]);
          $vid = (int) $pdo->lastInsertId();
          $pdo->prepare("UPDATE streaming_perfiles SET estado='vendido', venta_id=? WHERE id=? AND estado='libre'")->execute([$vid, (int) $m['mpid']]);
        } catch (Throwable $e) {}
      }
    }
    return count($mps);
  } catch (Throwable $e) { return 0; }
}
