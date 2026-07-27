<?php
/**
 * Helpers de datos del Gestor de Streaming (plataformas, proveedores, clientes,
 * cuentas/inventario con stock por perfil, ventas, KPIs) + entrega por WhatsApp.
 * Lo usa admin/streaming.php. Requiere estar en contexto admin (db(), wa).
 */
if (!defined('CONEC_ADMIN')) { http_response_code(403); exit; }

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
  $venc = !empty($v['fecha_vencimiento']) ? date('d/m/Y', strtotime($v['fecha_vencimiento'])) : '';
  $m  = "✅ ¡Listo! Tu *" . $plat . "* ya está activo.\n\n";
  $m .= "👉 Consulta tus datos de acceso aquí (privado, solo para ti):\n" . stream_acceso_link($id) . "\n";
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
function stream_msg_recordatorio(array $v): string {
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

function st_kpis(PDO $pdo): array {
  $o = (int) stream_owner_id();
  $hoy = date('Y-m-d');
  $k = ['hoy' => 0, 'semana' => 0, 'vencidas' => 0, 'activas' => 0, 'mes' => 0, 'costo_mes' => 0, 'ganancia_mes' => 0, 'deuda' => 0, 'libres' => 0, 'perfiles' => 0, 'cuentas' => 0];
  try {
    $k['hoy']      = (int)$pdo->query("SELECT COUNT(*) FROM streaming_ventas WHERE owner_id=$o AND estado='activa' AND fecha_vencimiento='$hoy'")->fetchColumn();
    $k['semana']   = (int)$pdo->query("SELECT COUNT(*) FROM streaming_ventas WHERE owner_id=$o AND estado='activa' AND fecha_vencimiento BETWEEN '$hoy' AND DATE_ADD('$hoy', INTERVAL 7 DAY)")->fetchColumn();
    $k['vencidas'] = (int)$pdo->query("SELECT COUNT(*) FROM streaming_ventas WHERE owner_id=$o AND (estado='vencida' OR (estado='activa' AND fecha_vencimiento < '$hoy'))")->fetchColumn();
    $k['activas']  = (int)$pdo->query("SELECT COUNT(*) FROM streaming_ventas WHERE owner_id=$o AND estado='activa' AND fecha_vencimiento >= '$hoy'")->fetchColumn();
    $k['mes']      = (float)$pdo->query("SELECT COALESCE(SUM(precio),0) FROM streaming_ventas WHERE owner_id=$o AND MONTH(creado_en)=MONTH(NOW()) AND YEAR(creado_en)=YEAR(NOW())")->fetchColumn();
    // Ganancia = facturado MENOS costo, SOLO sobre ventas ligadas a una cuenta del inventario (las únicas costeables).
    // Las ventas a mano sin cuenta no se pueden costear → NO entran en la ganancia (sí en "Ventas del mes").
    // GREATEST(perfiles_total,1) = mismo criterio que el margen por fila (evita div/0 y descuadre).
    // Costo por venta: usa el costo propio de la cuenta; si no tiene, cae al costo POR PERFIL del tipo
    // (streaming_plataformas.costo). 'cuenta' entera = costo/perfil × perfiles; 'perfil' = costo/perfil.
    $gm = $pdo->query("SELECT
        COALESCE(SUM(v.precio),0) AS fact,
        COALESCE(SUM(CASE WHEN v.tipo='cuenta'
            THEN COALESCE(NULLIF(c.costo,0), pl.costo*GREATEST(c.perfiles_total,1), 0)
            ELSE COALESCE(NULLIF(c.costo,0)/GREATEST(c.perfiles_total,1), pl.costo, 0) END),0) AS costo
      FROM streaming_ventas v JOIN streaming_cuentas c ON c.id=v.cuenta_id AND c.owner_id=v.owner_id
      LEFT JOIN streaming_plataformas pl ON pl.id=c.plataforma_id AND pl.owner_id=c.owner_id
      WHERE v.owner_id=$o AND MONTH(v.creado_en)=MONTH(NOW()) AND YEAR(v.creado_en)=YEAR(NOW())")->fetch(PDO::FETCH_ASSOC) ?: ['fact'=>0,'costo'=>0];
    $k['costo_mes']    = (float)$gm['costo'];
    $k['ganancia_mes'] = (float)$gm['fact'] - $k['costo_mes'];
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
        WHERE v.owner_id=$o AND v.creado_por IS NOT NULL AND MONTH(v.creado_en)=MONTH(NOW()) AND YEAR(v.creado_en)=YEAR(NOW())
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

    $q = $pdo->prepare("SELECT COUNT(*) FROM streaming_ventas WHERE cuenta_id=? AND estado='activa'");
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
