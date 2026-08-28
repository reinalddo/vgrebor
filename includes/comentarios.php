<?php
// Sistema de Comentarios/Reseñas de clientes ("Lo que opinan de nosotros").
// Fase 1: esquema de datos, configuración editable y funciones puras. Sin UI
// ni escritura de comentarios todavía (eso son las fases siguientes).
//
// Mismo patrón que includes/referidos.php: ensure_schema idempotente con un
// flag de versión en store_config para no repetir SHOW COLUMNS en cada
// request, y getters de config con fallback a un default.

// ─────────────────────────────────────────────────────────────────────────
// Estado "Completado" del pedido
// ─────────────────────────────────────────────────────────────────────────
// El cliente habla de pedidos "Completados", pero ese estado NO existe con
// ese nombre en la BD: `pedidos.estado` es ENUM('pendiente','pagado',
// 'enviado','cancelado'). El equivalente real es 'enviado' — de hecho
// includes/recargas_provider_matching.php:21 mapea explícitamente los
// estados 'completado'/'completed'/'success'/'aprobado' que devuelven los
// proveedores al estado interno 'enviado'. O sea: recarga entregada.
// 'pagado' NO cuenta: significa que pagó pero la recarga aún no se entregó.
if (!function_exists('comentarios_estado_pedido_completado')) {
    function comentarios_estado_pedido_completado(): string {
        return 'enviado';
    }
}

// ─────────────────────────────────────────────────────────────────────────
// Configuración editable desde el panel de administración
// ─────────────────────────────────────────────────────────────────────────

if (!function_exists('comentarios_config_entero')) {
    function comentarios_config_entero(string $clave, int $default, int $min, int $max): int {
        $valor = trim((string) store_config_get('comentarios_' . $clave, (string) $default));
        if ($valor === '' || !is_numeric($valor)) {
            return $default;
        }
        $numero = (int) $valor;
        if ($numero < $min || $numero > $max) {
            return $default;
        }
        return $numero;
    }
}

// RE Coins que gana el usuario al publicar una reseña válida.
if (!function_exists('comentarios_recompensa_publicar')) {
    function comentarios_recompensa_publicar(): int {
        return comentarios_config_entero('recompensa_publicar', 3, 0, 1000);
    }
}

// Bono extra cuando el admin marca la reseña como "Destacada".
if (!function_exists('comentarios_bono_destacado')) {
    function comentarios_bono_destacado(): int {
        return comentarios_config_entero('bono_destacado', 3, 0, 1000);
    }
}

// Costo en RE Coins de editar un comentario ya publicado (anti-abuso).
if (!function_exists('comentarios_penalizacion_edicion')) {
    function comentarios_penalizacion_edicion(): int {
        return comentarios_config_entero('penalizacion_edicion', 6, 0, 1000);
    }
}

if (!function_exists('comentarios_min_caracteres')) {
    function comentarios_min_caracteres(): int {
        return comentarios_config_entero('min_caracteres', 15, 1, 5000);
    }
}

if (!function_exists('comentarios_max_caracteres')) {
    function comentarios_max_caracteres(): int {
        return comentarios_config_entero('max_caracteres', 250, 10, 5000);
    }
}

if (!function_exists('comentarios_por_pagina')) {
    function comentarios_por_pagina(): int {
        return comentarios_config_entero('por_pagina', 5, 1, 50);
    }
}

// Lista negra de palabras, editable por el admin como texto (una por línea o
// separadas por coma). Nunca hardcodeada, para que el cliente la ajuste sin
// pedir cambios de código.
if (!function_exists('comentarios_palabras_prohibidas')) {
    function comentarios_palabras_prohibidas(): array {
        $raw = trim((string) store_config_get('comentarios_palabras_prohibidas', ''));
        if ($raw === '') {
            return [];
        }
        $partes = preg_split('/[\r\n,]+/', $raw) ?: [];
        $salida = [];
        foreach ($partes as $palabra) {
            $palabra = comentarios_normalizar_texto(trim($palabra));
            if ($palabra !== '') {
                $salida[$palabra] = true;
            }
        }
        return array_keys($salida);
    }
}

// ─────────────────────────────────────────────────────────────────────────
// Filtro anti-groserías
// ─────────────────────────────────────────────────────────────────────────

// Normaliza para comparar: minúsculas y sin acentos, de modo que "PUTÁ" y
// "puta" se detecten igual. No se usa para guardar, solo para comparar.
if (!function_exists('comentarios_normalizar_texto')) {
    function comentarios_normalizar_texto(string $texto): string {
        $texto = mb_strtolower(trim($texto), 'UTF-8');
        $mapa = [
            'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a', 'ã' => 'a',
            'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
            'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i',
            'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o', 'õ' => 'o',
            'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u',
            'ñ' => 'n',
        ];
        return strtr($texto, $mapa);
    }
}

// Devuelve las palabras prohibidas encontradas en el texto.
//
// IMPORTANTE: se compara por PALABRA COMPLETA, no por subcadena. Buscar
// "puta" con strpos daría un falso positivo en "computadora" (com-PUTA-dora),
// "escaso" en otros idiomas, etc. Por eso se usa un límite de palabra que
// además acepta los separadores típicos con los que se intenta evadir el
// filtro (p.ej. "p u t a" no se detecta, pero "puta." o "¡puta!" sí).
if (!function_exists('comentarios_detectar_groserias')) {
    function comentarios_detectar_groserias(string $texto): array {
        $prohibidas = comentarios_palabras_prohibidas();
        if (empty($prohibidas)) {
            return [];
        }

        $normalizado = comentarios_normalizar_texto($texto);
        $encontradas = [];
        foreach ($prohibidas as $palabra) {
            $patron = '/(?<![\p{L}\p{N}])' . preg_quote($palabra, '/') . '(?![\p{L}\p{N}])/u';
            if (preg_match($patron, $normalizado) === 1) {
                $encontradas[] = $palabra;
            }
        }
        return $encontradas;
    }
}

// Valida longitud + groserías. Devuelve ['ok' => bool, 'message' => string].
if (!function_exists('comentarios_validar_texto')) {
    function comentarios_validar_texto(string $texto): array {
        $limpio = trim($texto);
        $largo = mb_strlen($limpio, 'UTF-8');
        $min = comentarios_min_caracteres();
        $max = comentarios_max_caracteres();

        if ($largo < $min) {
            return ['ok' => false, 'message' => 'Tu comentario debe tener al menos ' . $min . ' caracteres.'];
        }
        if ($largo > $max) {
            return ['ok' => false, 'message' => 'Tu comentario no puede superar los ' . $max . ' caracteres.'];
        }

        $groserias = comentarios_detectar_groserias($limpio);
        if (!empty($groserias)) {
            return [
                'ok' => false,
                'message' => 'Tu comentario contiene lenguaje no permitido. Edítalo para poder publicarlo.',
                'groserias' => $groserias,
            ];
        }

        return ['ok' => true, 'message' => '', 'texto' => $limpio];
    }
}

if (!function_exists('comentarios_validar_estrellas')) {
    function comentarios_validar_estrellas($valor): int {
        $estrellas = (int) $valor;
        if ($estrellas < 1 || $estrellas > 5) {
            return 0; // 0 = inválido
        }
        return $estrellas;
    }
}

// ─────────────────────────────────────────────────────────────────────────
// Penalización segura de RE Coins
// ─────────────────────────────────────────────────────────────────────────
// win_points_record_transaction() NO limita el saldo a cero: restar 6 a
// alguien con 2 lo dejaría en -4. Esta función acota la penalización al
// saldo realmente disponible, para no dejar saldos negativos.
if (!function_exists('comentarios_penalizacion_aplicable')) {
    function comentarios_penalizacion_aplicable(int $saldoActual, int $penalizacion): int {
        if ($penalizacion <= 0 || $saldoActual <= 0) {
            return 0;
        }
        return min($penalizacion, $saldoActual);
    }
}

// ─────────────────────────────────────────────────────────────────────────
// Esquema
// ─────────────────────────────────────────────────────────────────────────

if (!function_exists('comentarios_ensure_schema')) {
    function comentarios_ensure_schema(): void {
        static $initialized = false;
        if ($initialized) {
            return;
        }
        $initialized = true;

        // Flag persistido: evita repetir SHOW COLUMNS/SHOW INDEX en cada
        // request una vez que el esquema ya se creó (mismo truco que
        // referidos_schema_version).
        if (trim((string) store_config_get('comentarios_schema_version', '')) === '1') {
            return;
        }

        require_once __DIR__ . '/db_connect.php';
        global $mysqli;
        if (!($mysqli instanceof mysqli)) {
            return;
        }

        // ENGINE=InnoDB explícito: las transacciones son silenciosamente
        // ignoradas en MyISAM, y estas tablas mueven RE Coins (dinero
        // virtual), así que el rollback tiene que funcionar de verdad.
        $mysqli->query(
            "CREATE TABLE IF NOT EXISTS comentarios_clientes (
                id INT AUTO_INCREMENT PRIMARY KEY,
                usuario_id INT NOT NULL,
                pedido_id INT NOT NULL,
                estrellas TINYINT NOT NULL,
                texto VARCHAR(500) NOT NULL,
                estado ENUM('pendiente','aprobado','rechazado','oculto') NOT NULL DEFAULT 'pendiente',
                destacado TINYINT(1) NOT NULL DEFAULT 0,
                recoins_otorgados INT NOT NULL DEFAULT 0,
                veces_editado INT NOT NULL DEFAULT 0,
                creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                editado_en DATETIME NULL DEFAULT NULL,
                moderado_por INT NULL DEFAULT NULL,
                moderado_en DATETIME NULL DEFAULT NULL,
                UNIQUE KEY uniq_comentarios_pedido (pedido_id),
                INDEX idx_comentarios_usuario (usuario_id),
                INDEX idx_comentarios_estado (estado),
                INDEX idx_comentarios_estrellas (estrellas),
                INDEX idx_comentarios_destacado (destacado)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        // Respuesta oficial de la tienda: 1 por comentario (UNIQUE).
        $mysqli->query(
            "CREATE TABLE IF NOT EXISTS comentarios_respuestas (
                id INT AUTO_INCREMENT PRIMARY KEY,
                comentario_id INT NOT NULL,
                admin_usuario_id INT NOT NULL,
                texto VARCHAR(600) NOT NULL,
                creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                actualizado_en DATETIME NULL DEFAULT NULL,
                UNIQUE KEY uniq_comentarios_respuesta (comentario_id),
                INDEX idx_comentarios_respuesta_admin (admin_usuario_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        // "Útil" (manito): 1 like por usuario por comentario.
        $mysqli->query(
            "CREATE TABLE IF NOT EXISTS comentarios_likes (
                id INT AUTO_INCREMENT PRIMARY KEY,
                comentario_id INT NOT NULL,
                usuario_id INT NOT NULL,
                creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_comentarios_like (comentario_id, usuario_id),
                INDEX idx_comentarios_like_comentario (comentario_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        // Migración defensiva por si alguna tabla ya existía con otro esquema.
        $columnasPorTabla = [
            'comentarios_clientes' => [
                'usuario_id'        => "ALTER TABLE comentarios_clientes ADD COLUMN usuario_id INT NOT NULL AFTER id",
                'pedido_id'         => "ALTER TABLE comentarios_clientes ADD COLUMN pedido_id INT NOT NULL AFTER usuario_id",
                'estrellas'         => "ALTER TABLE comentarios_clientes ADD COLUMN estrellas TINYINT NOT NULL AFTER pedido_id",
                'texto'             => "ALTER TABLE comentarios_clientes ADD COLUMN texto VARCHAR(500) NOT NULL AFTER estrellas",
                'estado'            => "ALTER TABLE comentarios_clientes ADD COLUMN estado ENUM('pendiente','aprobado','rechazado','oculto') NOT NULL DEFAULT 'pendiente' AFTER texto",
                'destacado'         => "ALTER TABLE comentarios_clientes ADD COLUMN destacado TINYINT(1) NOT NULL DEFAULT 0 AFTER estado",
                'recoins_otorgados' => "ALTER TABLE comentarios_clientes ADD COLUMN recoins_otorgados INT NOT NULL DEFAULT 0 AFTER destacado",
                'veces_editado'     => "ALTER TABLE comentarios_clientes ADD COLUMN veces_editado INT NOT NULL DEFAULT 0 AFTER recoins_otorgados",
                'creado_en'         => "ALTER TABLE comentarios_clientes ADD COLUMN creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER veces_editado",
                'editado_en'        => "ALTER TABLE comentarios_clientes ADD COLUMN editado_en DATETIME NULL DEFAULT NULL AFTER creado_en",
                'moderado_por'      => "ALTER TABLE comentarios_clientes ADD COLUMN moderado_por INT NULL DEFAULT NULL AFTER editado_en",
                'moderado_en'       => "ALTER TABLE comentarios_clientes ADD COLUMN moderado_en DATETIME NULL DEFAULT NULL AFTER moderado_por",
            ],
            'comentarios_respuestas' => [
                'comentario_id'    => "ALTER TABLE comentarios_respuestas ADD COLUMN comentario_id INT NOT NULL AFTER id",
                'admin_usuario_id' => "ALTER TABLE comentarios_respuestas ADD COLUMN admin_usuario_id INT NOT NULL AFTER comentario_id",
                'texto'            => "ALTER TABLE comentarios_respuestas ADD COLUMN texto VARCHAR(600) NOT NULL AFTER admin_usuario_id",
                'creado_en'        => "ALTER TABLE comentarios_respuestas ADD COLUMN creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER texto",
                'actualizado_en'   => "ALTER TABLE comentarios_respuestas ADD COLUMN actualizado_en DATETIME NULL DEFAULT NULL AFTER creado_en",
            ],
        ];
        foreach ($columnasPorTabla as $tabla => $columnas) {
            $existentes = [];
            $resultado = $mysqli->query('SHOW COLUMNS FROM ' . $tabla);
            if ($resultado instanceof mysqli_result) {
                while ($row = $resultado->fetch_assoc()) {
                    $existentes[$row['Field']] = true;
                }
            }
            foreach ($columnas as $columna => $sql) {
                if (!isset($existentes[$columna])) {
                    $mysqli->query($sql);
                }
            }
        }

        store_config_upsert('comentarios_schema_version', '1', 'No tocar: marca que el esquema del sistema de Comentarios ya fue creado, para no repetir SHOW COLUMNS en cada request.');
    }
}

// ─────────────────────────────────────────────────────────────────────────
// Elegibilidad: qué pedidos puede comentar un usuario
// ─────────────────────────────────────────────────────────────────────────
// Regla del cliente: exactamente UN comentario por cada pedido completado.
// Un usuario con 5 pedidos entregados puede dejar hasta 5 comentarios (uno
// por pedido), nunca 2 sobre el mismo pedido — eso lo garantiza además el
// UNIQUE KEY sobre pedido_id, no solo esta consulta.
if (!function_exists('comentarios_pedidos_disponibles')) {
    function comentarios_pedidos_disponibles(mysqli $mysqli, int $usuarioId, int $limite = 20): array {
        if ($usuarioId <= 0) {
            return [];
        }
        comentarios_ensure_schema();

        $estadoCompletado = comentarios_estado_pedido_completado();
        $sql = 'SELECT p.id, p.juego_nombre, p.paquete_nombre, p.creado_en
                FROM pedidos p
                LEFT JOIN comentarios_clientes c ON c.pedido_id = p.id
                WHERE p.cliente_usuario_id = ?
                  AND p.estado = ?
                  AND c.id IS NULL
                ORDER BY p.creado_en DESC
                LIMIT ?';
        $stmt = $mysqli->prepare($sql);
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('isi', $usuarioId, $estadoCompletado, $limite);
        $stmt->execute();
        $resultado = $stmt->get_result();

        $pedidos = [];
        if ($resultado instanceof mysqli_result) {
            while ($row = $resultado->fetch_assoc()) {
                $pedidos[] = [
                    'id' => (int) $row['id'],
                    'juego_nombre' => trim((string) ($row['juego_nombre'] ?? '')),
                    'paquete_nombre' => trim((string) ($row['paquete_nombre'] ?? '')),
                    'etiqueta' => comentarios_etiqueta_pedido($row),
                    'creado_en' => (string) ($row['creado_en'] ?? ''),
                ];
            }
        }
        $stmt->close();
        return $pedidos;
    }
}

// "FREE FIRE - 100 + 10" — se arma con las columnas denormalizadas que el
// pedido ya guardó al momento de la compra (juego_nombre/paquete_nombre), no
// con un JOIN a juegos/paquetes: así la reseña sigue mostrando lo correcto
// aunque después se renombre o elimine ese paquete del catálogo.
if (!function_exists('comentarios_etiqueta_pedido')) {
    function comentarios_etiqueta_pedido(array $pedido): string {
        $juego = trim((string) ($pedido['juego_nombre'] ?? ''));
        $paquete = trim((string) ($pedido['paquete_nombre'] ?? ''));
        if ($juego !== '' && $paquete !== '') {
            return $juego . ' - ' . $paquete;
        }
        return $juego !== '' ? $juego : $paquete;
    }
}

if (!function_exists('comentarios_usuario_puede_comentar')) {
    function comentarios_usuario_puede_comentar(mysqli $mysqli, int $usuarioId): bool {
        return !empty(comentarios_pedidos_disponibles($mysqli, $usuarioId, 1));
    }
}

// ─────────────────────────────────────────────────────────────────────────
// Panel de calificación general (columna izquierda)
// ─────────────────────────────────────────────────────────────────────────
// Devuelve promedio, total y el desglose por estrella con su porcentaje —
// exactamente lo que dibuja el panel izquierdo (barras 5★→1★ con su %).
// Solo cuenta comentarios 'aprobado' (los que se ven públicamente).
if (!function_exists('comentarios_resumen_calificaciones')) {
    function comentarios_resumen_calificaciones(mysqli $mysqli): array {
        comentarios_ensure_schema();

        $conteo = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0];
        $total = 0;
        $suma = 0;

        $resultado = $mysqli->query(
            "SELECT estrellas, COUNT(*) AS cantidad
             FROM comentarios_clientes
             WHERE estado = 'aprobado'
             GROUP BY estrellas"
        );
        if ($resultado instanceof mysqli_result) {
            while ($row = $resultado->fetch_assoc()) {
                $estrella = (int) $row['estrellas'];
                $cantidad = (int) $row['cantidad'];
                if ($estrella >= 1 && $estrella <= 5) {
                    $conteo[$estrella] = $cantidad;
                    $total += $cantidad;
                    $suma += $estrella * $cantidad;
                }
            }
        }

        $desglose = [];
        foreach ([5, 4, 3, 2, 1] as $estrella) {
            $desglose[$estrella] = [
                'estrellas' => $estrella,
                'cantidad' => $conteo[$estrella],
                'porcentaje' => $total > 0 ? (int) round(($conteo[$estrella] / $total) * 100) : 0,
            ];
        }

        return [
            'total' => $total,
            'promedio' => $total > 0 ? round($suma / $total, 1) : 0.0,
            'desglose' => $desglose,
        ];
    }
}

// ─────────────────────────────────────────────────────────────────────────
// Listado público paginado (columna derecha)
// ─────────────────────────────────────────────────────────────────────────
// $filtroEstrellas: 0 = todas, 1-5 = solo esa calificación (lo que pasa al
// hacer clic en una barra del panel izquierdo).
// Los destacados van primero, luego por fecha descendente.
if (!function_exists('comentarios_listar_publicos')) {
    function comentarios_listar_publicos(mysqli $mysqli, int $filtroEstrellas = 0, int $pagina = 1, int $usuarioId = 0): array {
        comentarios_ensure_schema();

        $porPagina = comentarios_por_pagina();
        $pagina = max(1, $pagina);
        $filtro = ($filtroEstrellas >= 1 && $filtroEstrellas <= 5) ? $filtroEstrellas : 0;

        // Total para la paginación
        if ($filtro > 0) {
            $totalStmt = $mysqli->prepare("SELECT COUNT(*) AS total FROM comentarios_clientes WHERE estado = 'aprobado' AND estrellas = ?");
            $totalStmt->bind_param('i', $filtro);
        } else {
            $totalStmt = $mysqli->prepare("SELECT COUNT(*) AS total FROM comentarios_clientes WHERE estado = 'aprobado'");
        }
        if (!$totalStmt) {
            return ['items' => [], 'total' => 0, 'pagina' => 1, 'paginas' => 1, 'filtro' => $filtro];
        }
        $totalStmt->execute();
        $totalRow = $totalStmt->get_result()->fetch_assoc();
        $totalStmt->close();
        $total = (int) ($totalRow['total'] ?? 0);

        $paginas = $total > 0 ? (int) ceil($total / $porPagina) : 1;
        if ($pagina > $paginas) {
            $pagina = $paginas;
        }
        $offset = ($pagina - 1) * $porPagina;

        $sql = "SELECT c.id, c.usuario_id, c.pedido_id, c.estrellas, c.texto, c.destacado,
                       c.creado_en, c.editado_en,
                       u.nombre AS usuario_nombre, u.foto_perfil,
                       p.juego_nombre, p.paquete_nombre,
                       r.texto AS respuesta_texto, r.creado_en AS respuesta_creado_en,
                       ra.nombre AS respuesta_admin_nombre,
                       (SELECT COUNT(*) FROM comentarios_likes l WHERE l.comentario_id = c.id) AS likes,
                       " . ($usuarioId > 0
                            ? "(SELECT COUNT(*) FROM comentarios_likes l2 WHERE l2.comentario_id = c.id AND l2.usuario_id = ?) AS yo_di_like"
                            : "0 AS yo_di_like") . "
                FROM comentarios_clientes c
                LEFT JOIN usuarios u ON u.id = c.usuario_id
                LEFT JOIN pedidos p ON p.id = c.pedido_id
                LEFT JOIN comentarios_respuestas r ON r.comentario_id = c.id
                LEFT JOIN usuarios ra ON ra.id = r.admin_usuario_id
                WHERE c.estado = 'aprobado'";
        if ($filtro > 0) {
            $sql .= " AND c.estrellas = ?";
        }
        $sql .= " ORDER BY c.destacado DESC, c.creado_en DESC LIMIT ? OFFSET ?";

        $stmt = $mysqli->prepare($sql);
        if (!$stmt) {
            return ['items' => [], 'total' => $total, 'pagina' => $pagina, 'paginas' => $paginas, 'filtro' => $filtro];
        }

        if ($usuarioId > 0 && $filtro > 0) {
            $stmt->bind_param('iiii', $usuarioId, $filtro, $porPagina, $offset);
        } elseif ($usuarioId > 0) {
            $stmt->bind_param('iii', $usuarioId, $porPagina, $offset);
        } elseif ($filtro > 0) {
            $stmt->bind_param('iii', $filtro, $porPagina, $offset);
        } else {
            $stmt->bind_param('ii', $porPagina, $offset);
        }

        $stmt->execute();
        $resultado = $stmt->get_result();

        $items = [];
        if ($resultado instanceof mysqli_result) {
            while ($row = $resultado->fetch_assoc()) {
                $items[] = [
                    'id' => (int) $row['id'],
                    'usuario_id' => (int) $row['usuario_id'],
                    'usuario_nombre' => trim((string) ($row['usuario_nombre'] ?? '')) !== '' ? trim((string) $row['usuario_nombre']) : 'Usuario',
                    'foto_perfil' => trim((string) ($row['foto_perfil'] ?? '')),
                    'estrellas' => (int) $row['estrellas'],
                    'texto' => (string) $row['texto'],
                    'destacado' => (int) $row['destacado'] === 1,
                    'creado_en' => (string) ($row['creado_en'] ?? ''),
                    'editado_en' => (string) ($row['editado_en'] ?? ''),
                    'pedido_etiqueta' => comentarios_etiqueta_pedido($row),
                    'likes' => (int) ($row['likes'] ?? 0),
                    'yo_di_like' => (int) ($row['yo_di_like'] ?? 0) > 0,
                    'respuesta' => trim((string) ($row['respuesta_texto'] ?? '')) !== '' ? [
                        'texto' => (string) $row['respuesta_texto'],
                        'admin_nombre' => trim((string) ($row['respuesta_admin_nombre'] ?? '')) !== '' ? trim((string) $row['respuesta_admin_nombre']) : 'Soporte',
                        'creado_en' => (string) ($row['respuesta_creado_en'] ?? ''),
                    ] : null,
                ];
            }
        }
        $stmt->close();

        return [
            'items' => $items,
            'total' => $total,
            'pagina' => $pagina,
            'paginas' => $paginas,
            'filtro' => $filtro,
            'por_pagina' => $porPagina,
        ];
    }
}
