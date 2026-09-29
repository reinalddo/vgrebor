<?php
// Baúl de Giftcards — inventario propio de códigos que el dueño de la tienda compra por su cuenta
// (donde sea) y carga a mano, para venderlos sin depender de que la API externa tenga stock en el
// momento. Cada paquete de la tienda se vincula a UN producto del baúl (juego_paquetes.paquete_api
// = bau_productos.id, api_provider = 'baul' — mismo campo que ya usan GiftVen/RecargasAmérica/CONEC
// para guardar "el id del producto en esa fuente", solo que aquí la fuente es esta misma tienda).
//
// Reglas de negocio acordadas con el cliente (ver CLAUDE.md, sección "Baúl de Giftcards"):
//  - Un código es solo texto; opcionalmente lleva también un serial (ambos se entregan si están).
//  - El costo se guarda POR LOTE (por código, no por producto): cada carga puede tener un costo
//    distinto, así Estadísticas calcula la ganancia con el costo real de lo que se entregó.
//  - Si la compra pide más unidades de las que hay, se entrega lo que haya (nunca se avisa
//    disponibilidad de antemano al cliente de la tienda) y el pedido se DIVIDE en dos (ver
//    bau_dispatch_and_split): uno "enviado" con lo entregado, otro "pagado" con lo pendiente.
//  - Un pedido pendiente NUNCA se vuelve a intentar solo. Solo se completa cuando un admin lo elige
//    a mano desde admin/baul.php (botón "Completar entregas pendientes").
//  - Los revendedores ven el stock exacto y NO reciben entrega parcial: si no alcanza, no se les
//    cobra nada (ver bau_dispatch_all_or_nothing, usado solo desde api/revendedor/api.php).
//  - Solo admin y root administran el baúl.

require_once __DIR__ . '/store_config.php';

function bau_ensure_schema(mysqli $mysqli): void {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $mysqli->query(
        "CREATE TABLE IF NOT EXISTS bau_productos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nombre VARCHAR(150) NOT NULL,
            activo TINYINT(1) NOT NULL DEFAULT 1,
            creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_activo (activo)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $mysqli->query(
        "CREATE TABLE IF NOT EXISTS bau_codigos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            producto_id INT NOT NULL,
            codigo VARCHAR(255) NOT NULL,
            serial VARCHAR(255) DEFAULT NULL,
            costo DECIMAL(12,4) DEFAULT NULL,
            estado ENUM('disponible','vendido','anulado') NOT NULL DEFAULT 'disponible',
            pedido_id INT DEFAULT NULL,
            cargado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            entregado_en DATETIME DEFAULT NULL,
            UNIQUE KEY uq_producto_codigo (producto_id, codigo),
            INDEX idx_producto_estado (producto_id, estado),
            INDEX idx_pedido_id (pedido_id),
            CONSTRAINT fk_bau_codigos_producto FOREIGN KEY (producto_id) REFERENCES bau_productos(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function bau_e(string $value, int $max = 255): string {
    $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    return function_exists('mb_substr') ? mb_substr($value, 0, $max, 'UTF-8') : substr($value, 0, $max);
}

// ── Productos ────────────────────────────────────────────────────────────

function bau_create_product(mysqli $mysqli, string $nombre): int {
    $nombre = bau_e($nombre, 150);
    if ($nombre === '') {
        throw new RuntimeException('El nombre del producto no puede estar vacío.');
    }
    $stmt = $mysqli->prepare('INSERT INTO bau_productos (nombre) VALUES (?)');
    $stmt->bind_param('s', $nombre);
    $stmt->execute();
    $id = (int) $mysqli->insert_id;
    $stmt->close();
    return $id;
}

function bau_set_product_active(mysqli $mysqli, int $productId, bool $active): void {
    $stmt = $mysqli->prepare('UPDATE bau_productos SET activo = ? WHERE id = ?');
    $flag = $active ? 1 : 0;
    $stmt->bind_param('ii', $flag, $productId);
    $stmt->execute();
    $stmt->close();
}

function bau_product_stock_count(mysqli $mysqli, int $productId): int {
    $stmt = $mysqli->prepare("SELECT COUNT(*) c FROM bau_codigos WHERE producto_id = ? AND estado = 'disponible'");
    $stmt->bind_param('i', $productId);
    $stmt->execute();
    $count = (int) ($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    $stmt->close();
    return $count;
}

// Costo del PRÓXIMO código que se entregaría (el más antiguo disponible, orden FIFO) — es el dato
// útil para decidir el precio de venta al crear el paquete, ya que el costo se guarda por lote.
function bau_product_next_cost(mysqli $mysqli, int $productId): ?float {
    $stmt = $mysqli->prepare("SELECT costo FROM bau_codigos WHERE producto_id = ? AND estado = 'disponible' ORDER BY id ASC LIMIT 1");
    $stmt->bind_param('i', $productId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return ($row !== null && $row['costo'] !== null) ? (float) $row['costo'] : null;
}

/** Productos del baúl con su stock, opcionalmente filtrados por texto (búsqueda libre por nombre). */
function bau_fetch_products(mysqli $mysqli, string $search = '', bool $onlyActive = true): array {
    bau_ensure_schema($mysqli);
    $sql = "SELECT p.id, p.nombre, p.activo,
                   (SELECT COUNT(*) FROM bau_codigos c WHERE c.producto_id = p.id AND c.estado = 'disponible') AS stock,
                   (SELECT c.costo FROM bau_codigos c WHERE c.producto_id = p.id AND c.estado = 'disponible' ORDER BY c.id ASC LIMIT 1) AS proximo_costo
            FROM bau_productos p WHERE 1=1";
    $params = [];
    $types = '';
    if ($onlyActive) {
        $sql .= ' AND p.activo = 1';
    }
    $search = trim($search);
    if ($search !== '') {
        $sql .= ' AND p.nombre LIKE ?';
        $params[] = '%' . $search . '%';
        $types .= 's';
    }
    $sql .= ' ORDER BY p.nombre ASC';

    $stmt = $mysqli->prepare($sql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return array_map(static function (array $row): array {
        $row['id'] = (int) $row['id'];
        $row['activo'] = (int) $row['activo'];
        $row['stock'] = (int) $row['stock'];
        $row['proximo_costo'] = $row['proximo_costo'] !== null ? (float) $row['proximo_costo'] : null;
        return $row;
    }, $rows);
}

function bau_fetch_product_by_id(mysqli $mysqli, int $productId): ?array {
    if ($productId <= 0) {
        return null;
    }
    bau_ensure_schema($mysqli);
    $stmt = $mysqli->prepare('SELECT id, nombre, activo FROM bau_productos WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $productId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        return null;
    }
    $row['id'] = (int) $row['id'];
    $row['activo'] = (int) $row['activo'];
    $row['stock'] = bau_product_stock_count($mysqli, $productId);
    $row['proximo_costo'] = bau_product_next_cost($mysqli, $productId);
    return $row;
}

// Etiqueta para el selector de admin/paquetes.php: nombre, stock y costo de referencia.
function bau_product_label(array $product): string {
    $nombre = trim((string) ($product['nombre'] ?? 'Producto'));
    $stock = (int) ($product['stock'] ?? 0);
    $costo = $product['proximo_costo'] ?? null;
    $label = $nombre . ' — ' . ($stock > 0 ? $stock . ' disponible' . ($stock === 1 ? '' : 's') : 'agotado');
    if ($costo !== null) {
        $label .= ' — costo próx. $' . number_format((float) $costo, 4, '.', '');
    }
    return $label;
}

// ── Códigos ──────────────────────────────────────────────────────────────

// Una línea es "codigo" o "codigo|serial". Líneas vacías se ignoran. No valida duplicados contra lo
// ya cargado (eso lo hace bau_load_codes al insertar); sí detecta duplicados DENTRO del mismo pegado.
function bau_parse_bulk_codes(string $raw): array {
    $raw = str_replace("\r\n", "\n", $raw);
    $lines = array_map('trim', explode("\n", $raw));
    $items = [];
    $seen = [];
    $duplicatesInPaste = 0;
    foreach ($lines as $line) {
        if ($line === '') {
            continue;
        }
        $parts = array_map('trim', explode('|', $line, 2));
        $codigo = bau_e($parts[0] ?? '', 255);
        $serial = isset($parts[1]) ? bau_e($parts[1], 255) : '';
        if ($codigo === '') {
            continue;
        }
        $key = mb_strtolower($codigo, 'UTF-8');
        if (isset($seen[$key])) {
            $duplicatesInPaste++;
            continue;
        }
        $seen[$key] = true;
        $items[] = ['codigo' => $codigo, 'serial' => $serial !== '' ? $serial : null];
    }
    return ['items' => $items, 'duplicates_in_paste' => $duplicatesInPaste];
}

/**
 * Carga un lote de códigos a un producto, todos con el mismo costo (el costo de ESTE lote). Los que
 * ya existan en ese producto (mismo texto de código) se omiten — nunca se sobreescribe uno existente
 * ni se duplica. Devuelve ['insertados' => int, 'omitidos_duplicados' => int].
 */
function bau_load_codes(mysqli $mysqli, int $productId, array $items, ?float $costo): array {
    bau_ensure_schema($mysqli);
    if ($productId <= 0 || empty($items)) {
        return ['insertados' => 0, 'omitidos_duplicados' => 0];
    }

    $stmt = $mysqli->prepare('INSERT IGNORE INTO bau_codigos (producto_id, codigo, serial, costo) VALUES (?, ?, ?, ?)');
    $inserted = 0;
    foreach ($items as $item) {
        $codigo = (string) ($item['codigo'] ?? '');
        if ($codigo === '') {
            continue;
        }
        $serial = $item['serial'] ?? null;
        $stmt->bind_param('issd', $productId, $codigo, $serial, $costo);
        $stmt->execute();
        $inserted += $stmt->affected_rows > 0 ? 1 : 0;
    }
    $stmt->close();

    return ['insertados' => $inserted, 'omitidos_duplicados' => count($items) - $inserted];
}

function bau_list_codes(mysqli $mysqli, int $productId, string $estado = '', int $limit = 300): array {
    $sql = 'SELECT id, codigo, serial, costo, estado, pedido_id, cargado_en, entregado_en FROM bau_codigos WHERE producto_id = ?';
    $params = [$productId];
    $types = 'i';
    if ($estado !== '') {
        $sql .= ' AND estado = ?';
        $params[] = $estado;
        $types .= 's';
    }
    $sql .= ' ORDER BY id DESC LIMIT ?';
    $params[] = $limit;
    $types .= 'i';

    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

// Anula un código TODAVÍA disponible (p.ej. se cargó mal). Nunca toca uno ya vendido: eso es historial.
function bau_void_code(mysqli $mysqli, int $codeId): bool {
    $stmt = $mysqli->prepare("UPDATE bau_codigos SET estado = 'anulado' WHERE id = ? AND estado = 'disponible'");
    $stmt->bind_param('i', $codeId);
    $stmt->execute();
    $changed = $stmt->affected_rows > 0;
    $stmt->close();
    return $changed;
}

// ── Formato de entrega ───────────────────────────────────────────────────

// Igual que el resto del sistema (ver recargasamerica_catalog_format_delivery): si ningún código
// trae serial, una línea por código; si alguno trae, se etiquetan "Código:"/"Serial:" para no
// confundir cuál es cuál, separando cada tarjeta con una línea en blanco cuando hay más de una.
function bau_format_delivery_text(array $items): string {
    if (empty($items)) {
        return '';
    }
    $anySerial = false;
    foreach ($items as $item) {
        if (trim((string) ($item['serial'] ?? '')) !== '') {
            $anySerial = true;
            break;
        }
    }

    $blocks = [];
    foreach ($items as $item) {
        $codigo = trim((string) ($item['codigo'] ?? ''));
        if ($codigo === '') {
            continue;
        }
        $serial = trim((string) ($item['serial'] ?? ''));
        if (!$anySerial) {
            $blocks[] = $codigo;
        } else {
            $block = 'Código: ' . $codigo;
            if ($serial !== '') {
                $block .= "\nSerial: " . $serial;
            }
            $blocks[] = $block;
        }
    }

    return implode($anySerial ? "\n\n" : "\n", $blocks);
}

// ── Despacho (reserva atómica de códigos) ────────────────────────────────

/**
 * Reserva hasta $needed códigos DISPONIBLES del producto, los marca 'vendido' y los liga a
 * $orderId. Atómico (FOR UPDATE): dos compras a la vez nunca pueden llevarse el mismo código.
 * Devuelve los códigos recién reservados (puede ser MENOS de lo pedido si no había suficientes, o
 * vacío si no había ninguno) — nunca lanza por falta de stock, eso lo decide quien llama.
 */
function bau_claim_codes(mysqli $mysqli, int $productId, int $needed, int $orderId): array {
    if ($needed <= 0) {
        return [];
    }

    $mysqli->begin_transaction();
    try {
        $sel = $mysqli->prepare("SELECT id, codigo, serial FROM bau_codigos WHERE producto_id = ? AND estado = 'disponible' ORDER BY id ASC LIMIT ? FOR UPDATE");
        $sel->bind_param('ii', $productId, $needed);
        $sel->execute();
        $rows = $sel->get_result()->fetch_all(MYSQLI_ASSOC);
        $sel->close();

        if (empty($rows)) {
            $mysqli->commit();
            return [];
        }

        $ids = array_map(static fn (array $r): int => (int) $r['id'], $rows);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $upd = $mysqli->prepare("UPDATE bau_codigos SET estado='vendido', pedido_id=?, entregado_en=NOW() WHERE id IN ($placeholders) AND estado='disponible'");
        $types = 'i' . str_repeat('i', count($ids));
        $upd->bind_param($types, $orderId, ...$ids);
        $upd->execute();
        $changed = $upd->affected_rows;
        $upd->close();

        if ($changed !== count($ids)) {
            // Alguien más se adelantó pese al FOR UPDATE (no debería pasar; se aborta por seguridad
            // en vez de arriesgar un conteo incorrecto).
            $mysqli->rollback();
            return [];
        }

        $mysqli->commit();
        return array_map(static fn (array $r): array => ['codigo' => $r['codigo'], 'serial' => $r['serial']], $rows);
    } catch (Throwable $e) {
        @$mysqli->rollback();
        return [];
    }
}

/** Códigos ya asignados a este pedido en un intento anterior (para el reintento seguro, sin duplicar). */
function bau_codes_already_assigned(mysqli $mysqli, int $orderId): array {
    $stmt = $mysqli->prepare("SELECT codigo, serial FROM bau_codigos WHERE pedido_id = ? AND estado = 'vendido' ORDER BY id ASC");
    $stmt->bind_param('i', $orderId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

/**
 * Punto único de despacho para pedidos de la TIENDA (no revendedores): entrega lo que haya, hasta
 * la cantidad pedida. Reintento seguro: primero revisa qué códigos ya se le asignaron a este pedido
 * (de un intento anterior) y solo reserva los que falten — nunca vuelve a pedir la cantidad completa
 * de cero. Devuelve:
 *   ['delivered' => [...codigos], 'delivered_count' => int, 'requested' => int]
 * $requested se pasa explícito (no se recalcula aquí) porque, en el caso de un pedido YA dividido
 * (bau_dispatch_and_split lo vuelve a llamar al completar uno pendiente), la cantidad a cubrir es la
 * del pedido "hijo", que ya es la que falta — no la del pedido original completo.
 */
function bau_dispatch_claim(mysqli $mysqli, int $orderId, int $productId, int $requested): array {
    $already = bau_codes_already_assigned($mysqli, $orderId);
    $alreadyCount = count($already);
    if ($alreadyCount >= $requested) {
        return ['delivered' => $already, 'delivered_count' => $alreadyCount, 'requested' => $requested];
    }

    $newlyClaimed = bau_claim_codes($mysqli, $productId, $requested - $alreadyCount, $orderId);
    $delivered = array_merge($already, $newlyClaimed);

    return ['delivered' => $delivered, 'delivered_count' => count($delivered), 'requested' => $requested];
}

/**
 * Reserva TODO o NADA: si no hay suficientes códigos para cubrir $requested completo, no reserva
 * nada (se puede llamar de nuevo sin riesgo). Para revendedores, que no reciben entrega parcial.
 * Devuelve ['ok' => bool, 'delivered' => [...], 'available' => int] — 'available' solo se informa
 * cuando ok=false, para poder decirle al revendedor cuánto hay de verdad.
 */
function bau_dispatch_all_or_nothing(mysqli $mysqli, int $productId, int $requested, int $orderId): array {
    if ($requested <= 0) {
        return ['ok' => false, 'delivered' => [], 'available' => 0];
    }

    $mysqli->begin_transaction();
    try {
        $countStmt = $mysqli->prepare("SELECT COUNT(*) c FROM bau_codigos WHERE producto_id = ? AND estado = 'disponible' FOR UPDATE");
        $countStmt->bind_param('i', $productId);
        $countStmt->execute();
        $available = (int) ($countStmt->get_result()->fetch_assoc()['c'] ?? 0);
        $countStmt->close();

        if ($available < $requested) {
            $mysqli->rollback();
            return ['ok' => false, 'delivered' => [], 'available' => $available];
        }

        $sel = $mysqli->prepare("SELECT id, codigo, serial FROM bau_codigos WHERE producto_id = ? AND estado = 'disponible' ORDER BY id ASC LIMIT ? FOR UPDATE");
        $sel->bind_param('ii', $productId, $requested);
        $sel->execute();
        $rows = $sel->get_result()->fetch_all(MYSQLI_ASSOC);
        $sel->close();

        $ids = array_map(static fn (array $r): int => (int) $r['id'], $rows);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $upd = $mysqli->prepare("UPDATE bau_codigos SET estado='vendido', pedido_id=?, entregado_en=NOW() WHERE id IN ($placeholders) AND estado='disponible'");
        $types = 'i' . str_repeat('i', count($ids));
        $upd->bind_param($types, $orderId, ...$ids);
        $upd->execute();
        $changed = $upd->affected_rows;
        $upd->close();

        if ($changed !== count($ids)) {
            $mysqli->rollback();
            return ['ok' => false, 'delivered' => [], 'available' => bau_product_stock_count($mysqli, $productId)];
        }

        $mysqli->commit();
        return ['ok' => true, 'delivered' => array_map(static fn (array $r): array => ['codigo' => $r['codigo'], 'serial' => $r['serial']], $rows), 'available' => $requested];
    } catch (Throwable $e) {
        @$mysqli->rollback();
        return ['ok' => false, 'delivered' => [], 'available' => 0];
    }
}

// ── Pedidos pendientes (entrega parcial / respaldo incierto) ─────────────

/**
 * Pedidos pendientes del Baúl (nunca se tocan solos; ver admin/baul.php). Incluye dos estados
 * distintos en bau_estado:
 *  - 'pendiente_manual': el Baúl (y su respaldo, si tenía) no entregaron nada — es seguro reintentar
 *    con el botón "Completar" en cuanto haya stock.
 *  - 'respaldo_incierto': una fuente de respaldo quedó "procesando" (Fase 2) — NO es seguro reintentar
 *    a ciegas (podría comprar dos veces en esa fuente); el admin debe verificar con el proveedor y
 *    luego cerrar el pedido a mano (ver bau_cancel_pending_order).
 */
function bau_fetch_pending_orders(mysqli $mysqli): array {
    $sql = "SELECT p.id, p.paquete_nombre, p.cantidad_compra, p.precio, p.moneda, p.email, p.user_identifier,
                   p.creado_en, p.bau_pedido_origen_id, p.bau_estado, p.bau_fallback_provider_used, p.ff_api_mensaje,
                   jp.paquete_api AS producto_id, bp.nombre AS producto_nombre,
                   (SELECT COUNT(*) FROM bau_codigos c WHERE c.producto_id = jp.paquete_api AND c.estado = 'disponible') AS stock_actual
            FROM pedidos p
            LEFT JOIN juego_paquetes jp ON jp.id = p.paquete_id
            LEFT JOIN bau_productos bp ON bp.id = jp.paquete_api
            WHERE p.api_provider = 'baul' AND p.estado = 'pagado' AND p.bau_estado IN ('pendiente_manual', 'respaldo_incierto')
            ORDER BY p.creado_en ASC";
    $result = $mysqli->query($sql);
    return $result instanceof mysqli_result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

/** Cierra un pedido pendiente sin entregarlo (p.ej. el admin ya resolvió/devolvió fuera del sistema). */
function bau_cancel_pending_order(mysqli $mysqli, int $orderId): bool {
    $stmt = $mysqli->prepare("UPDATE pedidos SET estado = 'cancelado' WHERE id = ? AND api_provider = 'baul' AND estado = 'pagado' AND bau_estado IN ('pendiente_manual', 'respaldo_incierto')");
    $stmt->bind_param('i', $orderId);
    $stmt->execute();
    $changed = $stmt->affected_rows > 0;
    $stmt->close();
    return $changed;
}
