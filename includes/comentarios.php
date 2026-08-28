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
// Penalización de RE Coins por editar
// ─────────────────────────────────────────────────────────────────────────
// win_points_record_transaction() NO limita el saldo a cero: restar 6 a
// alguien con 2 lo dejaría en -4. Decisión del cliente: en vez de cobrar
// menos, se BLOQUEA la edición y se le avisa al usuario que no le alcanza.
// Nunca se deja un saldo negativo ni se cobra un monto parcial.
if (!function_exists('comentarios_puede_pagar_edicion')) {
    function comentarios_puede_pagar_edicion(int $saldoActual, int $penalizacion): bool {
        if ($penalizacion <= 0) {
            return true; // edición gratis si el admin configuró 0
        }
        return $saldoActual >= $penalizacion;
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

// ─────────────────────────────────────────────────────────────────────────
// Pedidos hechos como INVITADO en esta sesión del navegador
// ─────────────────────────────────────────────────────────────────────────
// Un pedido de invitado queda con `cliente_usuario_id = NULL`, así que no
// hay forma de saber por BD que "este navegador hizo ese pedido". Sin esto,
// aceptar un pedido_id desde el navegador dejaría comentar el pedido de
// CUALQUIERA. La única prueba confiable es del lado del servidor: se anota
// el id del pedido en la sesión PHP en el momento de crearlo.
//
// ⚠️ El que ESCRIBE esta clave es api/pedidos.php (en pedidos_insert_order),
// con un bloque pequeño y sin dependencias — a propósito, porque ese archivo
// lo pisa periódicamente el paquete FTP del otro desarrollador y así es
// trivial de restaurar. Si el flujo de "registrarse y comentar" deja de
// funcionar, revisar PRIMERO que ese bloque siga ahí.
if (!function_exists('comentarios_clave_sesion_pedidos')) {
    function comentarios_clave_sesion_pedidos(): string {
        return 'comentarios_pedidos_sesion';
    }
}

if (!function_exists('comentarios_pedidos_de_sesion')) {
    function comentarios_pedidos_de_sesion(): array {
        $ids = $_SESSION[comentarios_clave_sesion_pedidos()] ?? [];
        if (!is_array($ids)) {
            return [];
        }
        return array_values(array_filter(array_map('intval', $ids), static fn($id) => $id > 0));
    }
}

// Vincula a una cuenta los pedidos que este navegador hizo como invitado.
// Se usa al registrarse o iniciar sesión desde el flujo post-compra: la
// persona que acaba de recargar ahora tiene cuenta, así que ese pedido pasa
// a ser suyo (aparece en "Mis Pedidos" y puede comentarlo).
//
// Solo toca pedidos con `cliente_usuario_id IS NULL`: si el pedido ya tenía
// dueño, no se le roba a nadie. No dispara premios ni comisiones
// retroactivas — esas se otorgan al confirmar el pedido, no escaneando la
// tabla, así que cambiar el dueño después es seguro.
if (!function_exists('comentarios_vincular_pedidos_de_sesion')) {
    function comentarios_vincular_pedidos_de_sesion(mysqli $mysqli, int $usuarioId): int {
        if ($usuarioId <= 0) {
            return 0;
        }
        $ids = comentarios_pedidos_de_sesion();
        if (empty($ids)) {
            return 0;
        }

        $vinculados = 0;
        $stmt = $mysqli->prepare('UPDATE pedidos SET cliente_usuario_id = ? WHERE id = ? AND cliente_usuario_id IS NULL');
        if (!$stmt) {
            return 0;
        }
        foreach ($ids as $pedidoId) {
            $stmt->bind_param('ii', $usuarioId, $pedidoId);
            $stmt->execute();
            $vinculados += $stmt->affected_rows > 0 ? 1 : 0;
        }
        $stmt->close();

        return $vinculados;
    }
}

// ─────────────────────────────────────────────────────────────────────────
// FASE 2: escritura (publicar, editar, "útil") + RE Coins
// ─────────────────────────────────────────────────────────────────────────
// Tipos propios de transacción de RE Coins. `transaction_type` es
// VARCHAR(50) libre (win_points.php:330), y ya hay precedentes de tipos
// propios ('mission_daily', 'daily_mission_chest', 'roulette_earn'), así
// que se sigue ese mismo patrón. Ojo: al NO ser 'earn'/'admin_adjustment',
// estas transacciones no refrescan la fecha de vencimiento de los puntos
// (win_points_transaction_refreshes_expiration, línea 712) — que es
// justamente lo correcto: comentar no debería alargar la vida de los
// puntos, eso solo lo hace recargar.
if (!function_exists('comentarios_tipo_tx')) {
    function comentarios_tipo_tx(string $evento): string {
        $tipos = [
            'recompensa'  => 'comentario_recompensa',
            'destacado'   => 'comentario_destacado',
            'penalizacion' => 'comentario_edicion',
            'reverso'     => 'comentario_reverso',
        ];
        return $tipos[$evento] ?? 'comentario_recompensa';
    }
}

// ⚠️ OBLIGATORIO llamar esto ANTES de abrir una transacción que después vaya
// a mover RE Coins.
//
// win_points_record_transaction() llama internamente a
// win_points_ensure_schema(), que en su primera ejecución del request corre
// CREATE TABLE/ALTER TABLE. En MySQL el DDL hace un **COMMIT IMPLÍCITO**:
// si eso pasa dentro de nuestra transacción, la cierra en silencio y a
// partir de ahí todo queda auto-commiteado — el rollback ya no revierte
// nada. Se detectó en la prueba real de la Fase 2: el rollback dejaba el
// comentario insertado y los RE Coins acreditados.
//
// Llamándolo antes, el DDL ocurre fuera de la transacción y adentro solo
// quedan INSERT/UPDATE, que sí se revierten.
if (!function_exists('comentarios_precalentar_win_points')) {
    function comentarios_precalentar_win_points(mysqli $mysqli, int $usuarioId): void {
        if (!function_exists('win_points_ensure_schema')) {
            return;
        }
        try {
            win_points_ensure_schema();
            if ($usuarioId > 0 && function_exists('win_points_ensure_wallet')) {
                win_points_ensure_wallet($mysqli, $usuarioId);
            }
        } catch (Throwable $e) {
            error_log('TVG comentarios: no se pudo precalentar win_points: ' . $e->getMessage());
        }
    }
}

// Registra un movimiento de RE Coins ligado a un comentario. Devuelve los
// puntos realmente movidos (0 si el programa está apagado o el monto es 0).
// Nunca lanza: si el sistema de premios falla, el comentario NO debe
// perderse por eso — se registra en el log y se sigue.
if (!function_exists('comentarios_mover_recoins')) {
    function comentarios_mover_recoins(mysqli $mysqli, int $usuarioId, int $delta, string $evento, string $descripcion, ?int $pedidoId = null): int {
        if ($usuarioId <= 0 || $delta === 0) {
            return 0;
        }
        if (function_exists('win_points_enabled') && !win_points_enabled()) {
            return 0;
        }
        try {
            win_points_record_transaction(
                $mysqli,
                $usuarioId,
                $delta,
                comentarios_tipo_tx($evento),
                $descripcion,
                $pedidoId,
                null,
                null,
                null,
                ['source' => 'comentarios']
            );
            return $delta;
        } catch (Throwable $e) {
            error_log('TVG comentarios: no se pudo mover RE Coins (usuario ' . $usuarioId . ', delta ' . $delta . '): ' . $e->getMessage());
            return 0;
        }
    }
}

// Publica un comentario nuevo sobre un pedido completado.
// Devuelve ['ok' => bool, 'message' => string, 'comentario_id' => int, 'recoins' => int].
if (!function_exists('comentarios_publicar')) {
    function comentarios_publicar(mysqli $mysqli, int $usuarioId, int $pedidoId, $estrellasRaw, string $textoRaw): array {
        comentarios_ensure_schema();

        if ($usuarioId <= 0) {
            return ['ok' => false, 'message' => 'Debes iniciar sesión para comentar.'];
        }

        $estrellas = comentarios_validar_estrellas($estrellasRaw);
        if ($estrellas === 0) {
            return ['ok' => false, 'message' => 'Selecciona una calificación de 1 a 5 estrellas.'];
        }

        $validacion = comentarios_validar_texto($textoRaw);
        if (!$validacion['ok']) {
            // Si se bloqueó por groserías no se emiten RE Coins (regla del
            // cliente) — como ni siquiera se llega a insertar, se cumple solo.
            return ['ok' => false, 'message' => $validacion['message']];
        }
        $texto = $validacion['texto'];

        // El pedido debe ser de ESTE usuario, estar completado y no tener
        // comentario todavía. Se valida contra la BD, nunca confiando en lo
        // que mande el navegador.
        $estadoCompletado = comentarios_estado_pedido_completado();
        $stmt = $mysqli->prepare(
            'SELECT p.id FROM pedidos p
             LEFT JOIN comentarios_clientes c ON c.pedido_id = p.id
             WHERE p.id = ? AND p.cliente_usuario_id = ? AND p.estado = ? AND c.id IS NULL
             LIMIT 1'
        );
        if (!$stmt) {
            return ['ok' => false, 'message' => 'No se pudo validar tu pedido en este momento.'];
        }
        $stmt->bind_param('iis', $pedidoId, $usuarioId, $estadoCompletado);
        $stmt->execute();
        $pedidoValido = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$pedidoValido) {
            return ['ok' => false, 'message' => 'Este pedido no está disponible para comentar (o ya lo comentaste).'];
        }

        $recompensa = comentarios_recompensa_publicar();

        // Antes de abrir la transacción (ver comentarios_precalentar_win_points:
        // el DDL de win_points haría un commit implícito y anularía el rollback).
        comentarios_precalentar_win_points($mysqli, $usuarioId);

        $mysqli->begin_transaction();
        try {
            $insert = $mysqli->prepare(
                "INSERT INTO comentarios_clientes (usuario_id, pedido_id, estrellas, texto, estado, recoins_otorgados)
                 VALUES (?, ?, ?, ?, 'pendiente', 0)"
            );
            if (!$insert) {
                throw new RuntimeException('No se pudo preparar el guardado del comentario.');
            }
            $insert->bind_param('iiis', $usuarioId, $pedidoId, $estrellas, $texto);
            $insert->execute();
            $comentarioId = (int) $mysqli->insert_id;
            $insert->close();

            $otorgados = 0;
            if ($recompensa > 0) {
                $otorgados = comentarios_mover_recoins(
                    $mysqli,
                    $usuarioId,
                    $recompensa,
                    'recompensa',
                    'Recompensa por publicar tu reseña',
                    $pedidoId
                );
                if ($otorgados > 0) {
                    // Se guarda el monto REAL otorgado, no el configurado:
                    // si mañana el admin cambia la recompensa a 10, revertir
                    // este comentario debe descontar 3, no 10.
                    $upd = $mysqli->prepare('UPDATE comentarios_clientes SET recoins_otorgados = ? WHERE id = ?');
                    $upd->bind_param('ii', $otorgados, $comentarioId);
                    $upd->execute();
                    $upd->close();
                }
            }

            $mysqli->commit();

            return [
                'ok' => true,
                'message' => $otorgados > 0
                    ? 'Tu reseña fue enviada y ganaste ' . $otorgados . ' RE Coins. Quedará visible cuando el administrador la apruebe.'
                    : 'Tu reseña fue enviada y quedará visible cuando el administrador la apruebe.',
                'comentario_id' => $comentarioId,
                'recoins' => $otorgados,
            ];
        } catch (Throwable $e) {
            $mysqli->rollback();
            error_log('TVG comentarios: fallo al publicar (usuario ' . $usuarioId . ', pedido ' . $pedidoId . '): ' . $e->getMessage());
            return ['ok' => false, 'message' => 'No se pudo publicar tu comentario. Intenta de nuevo.'];
        }
    }
}

// Edita un comentario propio. Cuesta RE Coins (configurable) y, por
// decisión del cliente, NO vuelve a moderación: si estaba aprobado, sigue
// aprobado. Si el saldo no alcanza para pagar la edición, se bloquea y se
// avisa — nunca se deja saldo negativo ni se cobra parcial.
if (!function_exists('comentarios_editar')) {
    function comentarios_editar(mysqli $mysqli, int $usuarioId, int $comentarioId, $estrellasRaw, string $textoRaw): array {
        comentarios_ensure_schema();

        if ($usuarioId <= 0) {
            return ['ok' => false, 'message' => 'Debes iniciar sesión para editar tu comentario.'];
        }

        $estrellas = comentarios_validar_estrellas($estrellasRaw);
        if ($estrellas === 0) {
            return ['ok' => false, 'message' => 'Selecciona una calificación de 1 a 5 estrellas.'];
        }

        $validacion = comentarios_validar_texto($textoRaw);
        if (!$validacion['ok']) {
            return ['ok' => false, 'message' => $validacion['message']];
        }
        $texto = $validacion['texto'];

        $stmt = $mysqli->prepare('SELECT id, usuario_id, pedido_id, estado, texto, estrellas FROM comentarios_clientes WHERE id = ? LIMIT 1');
        if (!$stmt) {
            return ['ok' => false, 'message' => 'No se pudo cargar tu comentario.'];
        }
        $stmt->bind_param('i', $comentarioId);
        $stmt->execute();
        $comentario = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$comentario || (int) $comentario['usuario_id'] !== $usuarioId) {
            return ['ok' => false, 'message' => 'No encontramos ese comentario en tu cuenta.'];
        }
        if ((string) $comentario['estado'] === 'rechazado') {
            return ['ok' => false, 'message' => 'Este comentario fue rechazado por el administrador y no puede editarse.'];
        }

        // Si no cambió nada, no se cobra ni se toca la BD.
        if (trim((string) $comentario['texto']) === $texto && (int) $comentario['estrellas'] === $estrellas) {
            return ['ok' => true, 'message' => 'No hiciste cambios en tu comentario.', 'recoins' => 0, 'sin_cambios' => true];
        }

        $penalizacion = comentarios_penalizacion_edicion();
        // Antes de la transacción, por el commit implícito del DDL de
        // win_points (ver comentarios_precalentar_win_points).
        comentarios_precalentar_win_points($mysqli, $usuarioId);
        $saldo = function_exists('win_points_wallet_balance') ? win_points_wallet_balance($mysqli, $usuarioId) : 0;

        if (!comentarios_puede_pagar_edicion($saldo, $penalizacion)) {
            return [
                'ok' => false,
                'saldo_insuficiente' => true,
                'message' => 'Editar tu comentario cuesta ' . $penalizacion . ' RE Coins y solo tienes ' . $saldo . '. Recarga para poder editarlo.',
            ];
        }

        $mysqli->begin_transaction();
        try {
            $upd = $mysqli->prepare(
                'UPDATE comentarios_clientes
                 SET texto = ?, estrellas = ?, editado_en = NOW(), veces_editado = veces_editado + 1
                 WHERE id = ? AND usuario_id = ?'
            );
            if (!$upd) {
                throw new RuntimeException('No se pudo preparar la edición.');
            }
            $upd->bind_param('siii', $texto, $estrellas, $comentarioId, $usuarioId);
            $upd->execute();
            $upd->close();

            $cobrado = 0;
            if ($penalizacion > 0) {
                $cobrado = comentarios_mover_recoins(
                    $mysqli,
                    $usuarioId,
                    -$penalizacion,
                    'penalizacion',
                    'Costo por editar tu reseña',
                    (int) $comentario['pedido_id']
                );
            }

            $mysqli->commit();

            return [
                'ok' => true,
                'message' => $cobrado !== 0
                    ? 'Comentario actualizado. Se descontaron ' . abs($cobrado) . ' RE Coins por la edición.'
                    : 'Comentario actualizado.',
                'recoins' => $cobrado,
            ];
        } catch (Throwable $e) {
            $mysqli->rollback();
            error_log('TVG comentarios: fallo al editar (comentario ' . $comentarioId . '): ' . $e->getMessage());
            return ['ok' => false, 'message' => 'No se pudo actualizar tu comentario. Intenta de nuevo.'];
        }
    }
}

// Devuelve el comentario que el usuario dejó sobre un pedido puntual (o
// null). Sirve para precargar el formulario en modo edición.
if (!function_exists('comentarios_obtener_de_usuario')) {
    function comentarios_obtener_de_usuario(mysqli $mysqli, int $usuarioId, int $comentarioId): ?array {
        comentarios_ensure_schema();
        if ($usuarioId <= 0 || $comentarioId <= 0) {
            return null;
        }
        $stmt = $mysqli->prepare(
            'SELECT c.id, c.pedido_id, c.estrellas, c.texto, c.estado, c.veces_editado,
                    p.juego_nombre, p.paquete_nombre
             FROM comentarios_clientes c
             LEFT JOIN pedidos p ON p.id = c.pedido_id
             WHERE c.id = ? AND c.usuario_id = ? LIMIT 1'
        );
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('ii', $comentarioId, $usuarioId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            return null;
        }
        return [
            'id' => (int) $row['id'],
            'pedido_id' => (int) $row['pedido_id'],
            'estrellas' => (int) $row['estrellas'],
            'texto' => (string) $row['texto'],
            'estado' => (string) $row['estado'],
            'veces_editado' => (int) $row['veces_editado'],
            'pedido_etiqueta' => comentarios_etiqueta_pedido($row),
        ];
    }
}

// Lista los comentarios que ya dejó el usuario (para su panel).
if (!function_exists('comentarios_mis_comentarios')) {
    function comentarios_mis_comentarios(mysqli $mysqli, int $usuarioId): array {
        comentarios_ensure_schema();
        if ($usuarioId <= 0) {
            return [];
        }
        $stmt = $mysqli->prepare(
            'SELECT c.id, c.pedido_id, c.estrellas, c.texto, c.estado, c.destacado, c.creado_en, c.editado_en,
                    p.juego_nombre, p.paquete_nombre
             FROM comentarios_clientes c
             LEFT JOIN pedidos p ON p.id = c.pedido_id
             WHERE c.usuario_id = ?
             ORDER BY c.creado_en DESC'
        );
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('i', $usuarioId);
        $stmt->execute();
        $resultado = $stmt->get_result();

        $items = [];
        if ($resultado instanceof mysqli_result) {
            while ($row = $resultado->fetch_assoc()) {
                $items[] = [
                    'id' => (int) $row['id'],
                    'pedido_id' => (int) $row['pedido_id'],
                    'estrellas' => (int) $row['estrellas'],
                    'texto' => (string) $row['texto'],
                    'estado' => (string) $row['estado'],
                    'destacado' => (int) $row['destacado'] === 1,
                    'creado_en' => (string) ($row['creado_en'] ?? ''),
                    'editado_en' => (string) ($row['editado_en'] ?? ''),
                    'pedido_etiqueta' => comentarios_etiqueta_pedido($row),
                ];
            }
        }
        $stmt->close();
        return $items;
    }
}

// Botón "Útil" (manito): alterna el like del usuario sobre un comentario.
// Devuelve el conteo actualizado para refrescar la UI sin recargar.
if (!function_exists('comentarios_alternar_like')) {
    function comentarios_alternar_like(mysqli $mysqli, int $usuarioId, int $comentarioId): array {
        comentarios_ensure_schema();

        if ($usuarioId <= 0) {
            return ['ok' => false, 'message' => 'Inicia sesión para marcar comentarios como útiles.'];
        }

        // Solo se puede dar "útil" a comentarios visibles públicamente.
        $stmt = $mysqli->prepare("SELECT id FROM comentarios_clientes WHERE id = ? AND estado = 'aprobado' LIMIT 1");
        if (!$stmt) {
            return ['ok' => false, 'message' => 'No se pudo procesar tu voto.'];
        }
        $stmt->bind_param('i', $comentarioId);
        $stmt->execute();
        $existe = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$existe) {
            return ['ok' => false, 'message' => 'Ese comentario ya no está disponible.'];
        }

        $del = $mysqli->prepare('DELETE FROM comentarios_likes WHERE comentario_id = ? AND usuario_id = ?');
        $del->bind_param('ii', $comentarioId, $usuarioId);
        $del->execute();
        $quitado = $del->affected_rows > 0;
        $del->close();

        if (!$quitado) {
            try {
                $ins = $mysqli->prepare('INSERT INTO comentarios_likes (comentario_id, usuario_id) VALUES (?, ?)');
                $ins->bind_param('ii', $comentarioId, $usuarioId);
                $ins->execute();
                $ins->close();
            } catch (Throwable $e) {
                // Carrera con otro clic simultáneo: el UNIQUE lo bloqueó, no
                // es un error real para el usuario.
                error_log('TVG comentarios: like duplicado ignorado: ' . $e->getMessage());
            }
        }

        $conteoStmt = $mysqli->prepare('SELECT COUNT(*) AS total FROM comentarios_likes WHERE comentario_id = ?');
        $conteoStmt->bind_param('i', $comentarioId);
        $conteoStmt->execute();
        $conteo = (int) ($conteoStmt->get_result()->fetch_assoc()['total'] ?? 0);
        $conteoStmt->close();

        return ['ok' => true, 'likes' => $conteo, 'activo' => !$quitado];
    }
}
