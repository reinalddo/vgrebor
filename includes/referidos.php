<?php
// Sistema de Referidos ("invita un amigo"): el que invita gana un % de las
// recargas de las personas que invitó, subiendo de nivel según el monto
// acumulado (de por vida) recargado por todos sus invitados juntos. El
// invitado recibe, aparte, un cupón de bienvenida (3% de descuento, un solo
// uso, mínimo $3, con anti-fraude por ID de jugador ya recargado antes).
//
// FASE 1 de la implementación: solo modelo de datos (columnas/tablas +
// constantes de nivel + funciones puras de cálculo de nivel). Todavía no se
// conecta a ningún flujo real (registro, checkout, panel de cliente) — eso
// llega en las fases siguientes. Este archivo no se requiere/incluye desde
// ningún otro archivo todavía.

require_once __DIR__ . '/tenant.php';
require_once __DIR__ . '/store_config.php';

if (!function_exists('referidos_db')) {
    function referidos_db(): mysqli {
        global $mysqli;

        if (!isset($mysqli) || !($mysqli instanceof mysqli)) {
            require_once __DIR__ . '/db_connect.php';
        }

        return $mysqli;
    }
}

// Niveles fijos, NO editables por el admin (instrucción explícita del
// cliente: "eso no es editable, simplemente ya se lo colocas tú de una
// vez"). La meta es el monto EN DÓLARES recargado de por vida por TODOS los
// invitados de ese referidor, sumado.
if (!function_exists('referidos_niveles')) {
    function referidos_niveles(): array {
        return [
            1 => ['nombre' => 'Básico',  'meta' => 0.0,    'porcentaje' => 1.0],
            2 => ['nombre' => 'Élite',   'meta' => 500.0,  'porcentaje' => 2.0],
            3 => ['nombre' => 'Épico',   'meta' => 1500.0, 'porcentaje' => 3.0],
            4 => ['nombre' => 'Leyenda', 'meta' => 3000.0, 'porcentaje' => 4.0],
            5 => ['nombre' => 'Mítico',  'meta' => 5000.0, 'porcentaje' => 5.0],
        ];
    }
}

if (!function_exists('referidos_nivel_maximo')) {
    function referidos_nivel_maximo(): int {
        return max(array_keys(referidos_niveles()));
    }
}

// Dado el monto acumulado de recargas de los invitados de un referidor,
// determina su nivel actual (el más alto cuya meta ya alcanzó) y el
// progreso hacia el siguiente — esto es lo que alimenta la barra de
// progreso y el encabezado "Nivel X — Y% de ganancia" del panel de cliente.
if (!function_exists('referidos_nivel_para_monto')) {
    function referidos_nivel_para_monto(float $montoAcumulado): array {
        $montoAcumulado = max(0.0, $montoAcumulado);
        $niveles = referidos_niveles();

        $nivelActual = 1;
        foreach ($niveles as $nivelNum => $data) {
            if ($montoAcumulado >= $data['meta']) {
                $nivelActual = $nivelNum;
            }
        }

        $nivelMaximo = referidos_nivel_maximo();
        $siguienteNivel = $nivelActual < $nivelMaximo ? $nivelActual + 1 : null;
        $metaSiguiente = $siguienteNivel !== null ? $niveles[$siguienteNivel]['meta'] : null;
        $metaNivelActual = $niveles[$nivelActual]['meta'];

        return [
            'nivel' => $nivelActual,
            'nombre' => $niveles[$nivelActual]['nombre'],
            'porcentaje' => $niveles[$nivelActual]['porcentaje'],
            'monto_acumulado' => round($montoAcumulado, 2),
            'meta_nivel_actual' => $metaNivelActual,
            'siguiente_nivel' => $siguienteNivel,
            'siguiente_nombre' => $siguienteNivel !== null ? $niveles[$siguienteNivel]['nombre'] : null,
            'siguiente_porcentaje' => $siguienteNivel !== null ? $niveles[$siguienteNivel]['porcentaje'] : null,
            'meta_siguiente' => $metaSiguiente,
            // Progreso normalizado 0-100 DENTRO del tramo actual (entre la meta del nivel
            // actual y la del siguiente) — es lo que llena la barra visualmente, no el
            // porcentaje del monto total acumulado contra la meta final.
            'progreso_porcentaje' => $siguienteNivel !== null && $metaSiguiente > $metaNivelActual
                ? round(min(100.0, max(0.0, (($montoAcumulado - $metaNivelActual) / ($metaSiguiente - $metaNivelActual)) * 100)), 1)
                : 100.0,
            'progreso_restante' => $metaSiguiente !== null ? round(max(0.0, $metaSiguiente - $montoAcumulado), 2) : 0.0,
            'es_nivel_maximo' => $siguienteNivel === null,
        ];
    }
}

// Umbral y ventana del bloqueo de retiro de ganancias — MISMO mecanismo que
// el bloqueo de RECoins (win_points_monthly_min_spend / _user_monthly_spent
// en includes/win_points.php: recarga mínima en los últimos 30 días
// corridos, no mes calendario), pero con su propio config key para poder
// ajustarlo sin afectar el de RECoins. Confirmado con el cliente: $10 en los
// últimos 30 días, igual ventana que RECoins.
if (!function_exists('referidos_retiro_minimo_recarga')) {
    function referidos_retiro_minimo_recarga(): float {
        return max(0.0, round((float) str_replace(',', '.', (string) store_config_get('referidos_retiro_minimo_recarga', '10.00')), 2));
    }
}

// Código corto para el link de invitación (?ref=CODIGO). 8 caracteres
// alfanuméricos en mayúscula; la columna usuarios.referido_codigo es UNIQUE,
// así que un choque real (extremadamente improbable) simplemente se
// resuelve regenerando en el punto donde se asigna (Fase 2).
if (!function_exists('referidos_codigo_generar')) {
    function referidos_codigo_generar(): string {
        return strtoupper(bin2hex(random_bytes(4)));
    }
}

// Datos del cupón de bienvenida que recibe la persona INVITADA: 3% por
// defecto, un solo uso (limite_usos=1, se apoya en el mecanismo de
// usos_actuales/limite_usos que YA existe para cupones normales — no hace
// falta inventar nada nuevo para el "un solo uso"), y solo aplica a
// recargas MAYORES a este monto (no "al menos").
if (!function_exists('referidos_cupon_bienvenida_porcentaje')) {
    function referidos_cupon_bienvenida_porcentaje(): float {
        return max(0.0, round((float) str_replace(',', '.', (string) store_config_get('referidos_cupon_bienvenida_porcentaje', '3.00')), 2));
    }
}

if (!function_exists('referidos_cupon_bienvenida_monto_minimo')) {
    function referidos_cupon_bienvenida_monto_minimo(): float {
        return max(0.0, round((float) str_replace(',', '.', (string) store_config_get('referidos_cupon_bienvenida_monto_minimo', '3.00')), 2));
    }
}

// Asegura que un usuario tenga su propio código de invitación — se usa
// tanto al registrarse (Fase 2) como para "rellenar" el código de cuentas
// que ya existían antes de este feature, la primera vez que abran su panel
// de referidos (Fase 4). Idempotente: si ya tiene código, lo devuelve tal
// cual sin tocar nada.
if (!function_exists('referidos_asegurar_codigo_usuario')) {
    function referidos_asegurar_codigo_usuario(mysqli $mysqli, int $usuarioId): ?string {
        if ($usuarioId <= 0) {
            return null;
        }
        referidos_ensure_schema();

        $stmt = $mysqli->prepare('SELECT referido_codigo FROM usuarios WHERE id = ? LIMIT 1');
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('i', $usuarioId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $codigoActual = trim((string) ($row['referido_codigo'] ?? ''));
        if ($codigoActual !== '') {
            return $codigoActual;
        }

        for ($intento = 0; $intento < 5; $intento++) {
            $candidato = referidos_codigo_generar();
            $checkStmt = $mysqli->prepare('SELECT id FROM usuarios WHERE referido_codigo = ? LIMIT 1');
            if (!$checkStmt) {
                return null;
            }
            $checkStmt->bind_param('s', $candidato);
            $checkStmt->execute();
            $exists = $checkStmt->get_result()->fetch_assoc();
            $checkStmt->close();
            if ($exists) {
                continue;
            }

            $updateStmt = $mysqli->prepare('UPDATE usuarios SET referido_codigo = ? WHERE id = ? AND referido_codigo IS NULL');
            if (!$updateStmt) {
                return null;
            }
            $updateStmt->bind_param('si', $candidato, $usuarioId);
            $updateStmt->execute();
            $affected = $updateStmt->affected_rows;
            $updateStmt->close();
            if ($affected > 0) {
                return $candidato;
            }

            // affected_rows=0 aquí solo puede ser una solicitud concurrente que ya
            // asignó un código a este mismo usuario justo ahora — se relee y se usa ese.
            $rereadStmt = $mysqli->prepare('SELECT referido_codigo FROM usuarios WHERE id = ? LIMIT 1');
            if ($rereadStmt) {
                $rereadStmt->bind_param('i', $usuarioId);
                $rereadStmt->execute();
                $rereadRow = $rereadStmt->get_result()->fetch_assoc();
                $rereadStmt->close();
                $rereadCodigo = trim((string) ($rereadRow['referido_codigo'] ?? ''));
                if ($rereadCodigo !== '') {
                    return $rereadCodigo;
                }
            }
        }

        return null;
    }
}

// Genera (o devuelve el ya existente, por si se reintenta el registro) el
// cupón de bienvenida de un usuario recién invitado. SOLO se llama cuando el
// registro trae un referido_por_id válido — nunca para un registro normal.
if (!function_exists('referidos_generar_cupon_bienvenida')) {
    function referidos_generar_cupon_bienvenida(mysqli $mysqli, int $usuarioId): ?string {
        if ($usuarioId <= 0) {
            return null;
        }
        referidos_ensure_schema();

        $existingStmt = $mysqli->prepare("SELECT codigo FROM cupones WHERE origen = 'referido' AND referido_usuario_id = ? LIMIT 1");
        if ($existingStmt) {
            $existingStmt->bind_param('i', $usuarioId);
            $existingStmt->execute();
            $existingRow = $existingStmt->get_result()->fetch_assoc();
            $existingStmt->close();
            $existingCodigo = trim((string) ($existingRow['codigo'] ?? ''));
            if ($existingCodigo !== '') {
                return $existingCodigo;
            }
        }

        $codigo = '';
        for ($intento = 0; $intento < 5; $intento++) {
            $candidato = 'REF-' . referidos_codigo_generar();
            $checkStmt = $mysqli->prepare('SELECT id FROM cupones WHERE codigo = ? LIMIT 1');
            if (!$checkStmt) {
                break;
            }
            $checkStmt->bind_param('s', $candidato);
            $checkStmt->execute();
            $exists = $checkStmt->get_result()->fetch_assoc();
            $checkStmt->close();
            if (!$exists) {
                $codigo = $candidato;
                break;
            }
        }
        if ($codigo === '') {
            return null;
        }

        $porcentaje = referidos_cupon_bienvenida_porcentaje();
        $montoMinimo = referidos_cupon_bienvenida_monto_minimo();

        $insertStmt = $mysqli->prepare(
            "INSERT INTO cupones (codigo, tipo_descuento, valor_descuento, monto_minimo, limite_usos, usos_actuales, activo, fecha_creacion, origen, referido_usuario_id)
             VALUES (?, 'porcentaje', ?, ?, 1, 0, 1, NOW(), 'referido', ?)"
        );
        if (!$insertStmt) {
            return null;
        }
        $insertStmt->bind_param('sddi', $codigo, $porcentaje, $montoMinimo, $usuarioId);
        $ok = $insertStmt->execute();
        $insertStmt->close();

        return $ok ? $codigo : null;
    }
}

// Anti-fraude explícito del cliente: "si este ID al que se le va a aplicar
// dicho cupón de recarga ya ha sido recargado en otra cuenta de la página,
// el cupón no es aplicable" — se revisa CUALQUIER pedido pagado/enviado de
// CUALQUIER usuario con ese mismo ID de jugador, sin importar quién lo creó.
// Evita que alguien invite/cree cuentas nuevas una y otra vez para seguir
// recargando el MISMO ID de juego con el 3% de descuento cada vez.
if (!function_exists('referidos_id_jugador_ya_recargado')) {
    function referidos_id_jugador_ya_recargado(mysqli $mysqli, string $userIdentifier): bool {
        $userIdentifier = trim($userIdentifier);
        if ($userIdentifier === '') {
            return false;
        }

        $stmt = $mysqli->prepare(
            "SELECT id FROM pedidos WHERE TRIM(user_identifier) = ? AND estado IN ('pagado', 'enviado') LIMIT 1"
        );
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('s', $userIdentifier);
        $stmt->execute();
        $result = $stmt->get_result();
        $exists = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        return $exists !== null;
    }
}

// Registra (si corresponde) la comisión de referido generada por UN pedido
// específico — se llama desde register_influencer_coupon_sale() (ver
// comentario ahí sobre por qué se enganchó justo en esa función) cada vez
// que un pedido queda confirmado como venta real. Idempotente por pedido_id
// (UNIQUE KEY en referidos_comisiones): si dos transiciones de estado del
// MISMO pedido llaman a esto (ej. pagado y después enviado), la segunda
// simplemente no hace nada.
if (!function_exists('referidos_registrar_comision_si_aplica')) {
    function referidos_registrar_comision_si_aplica(mysqli $mysqli, int $orderId): void {
        if ($orderId <= 0) {
            return;
        }
        referidos_ensure_schema();

        $existsStmt = $mysqli->prepare('SELECT id FROM referidos_comisiones WHERE pedido_id = ? LIMIT 1');
        if (!$existsStmt) {
            return;
        }
        $existsStmt->bind_param('i', $orderId);
        $existsStmt->execute();
        $existsResult = $existsStmt->get_result();
        $alreadyExists = $existsResult ? $existsResult->fetch_assoc() : null;
        $existsStmt->close();
        if ($alreadyExists) {
            return;
        }

        $orderStmt = $mysqli->prepare(
            "SELECT precio, cliente_usuario_id, win_points_payment_mode FROM pedidos WHERE id = ? AND estado IN ('pagado', 'enviado') LIMIT 1"
        );
        if (!$orderStmt) {
            return;
        }
        $orderStmt->bind_param('i', $orderId);
        $orderStmt->execute();
        $orderResult = $orderStmt->get_result();
        $order = $orderResult ? $orderResult->fetch_assoc() : null;
        $orderStmt->close();
        if (!$order) {
            return;
        }

        $invitadoUserId = (int) ($order['cliente_usuario_id'] ?? 0);
        if ($invitadoUserId <= 0) {
            // Compra sin cuenta logueada (invitado/guest): no hay a quién atribuirle
            // el referido_por_id, así que no puede generar comisión para nadie.
            return;
        }

        // Solo cuentan recargas pagadas de verdad, no canjeadas con RECoins — mismo
        // criterio que usa win_points_user_monthly_spent() para su propio bloqueo.
        if ((string) ($order['win_points_payment_mode'] ?? 'money') === 'points') {
            return;
        }

        $montoBase = round((float) ($order['precio'] ?? 0), 2);
        if ($montoBase <= 0) {
            return;
        }

        $userStmt = $mysqli->prepare('SELECT referido_por_id FROM usuarios WHERE id = ? LIMIT 1');
        if (!$userStmt) {
            return;
        }
        $userStmt->bind_param('i', $invitadoUserId);
        $userStmt->execute();
        $userResult = $userStmt->get_result();
        $userRow = $userResult ? $userResult->fetch_assoc() : null;
        $userStmt->close();

        $referidorUserId = isset($userRow['referido_por_id']) ? (int) $userRow['referido_por_id'] : 0;
        if ($referidorUserId <= 0) {
            // Este comprador no fue invitado por nadie — no genera comisión.
            return;
        }

        // Nivel/% "congelados" al momento de ESTE pedido: se calculan sobre el
        // acumulado de comisiones YA registradas ANTES de este pedido — NO incluye
        // el monto de este mismo pedido. Así, el pedido que hace cruzar una meta de
        // nivel todavía se paga con el % VIEJO, y el nivel nuevo aplica recién desde
        // el SIGUIENTE pedido. Confirmado con el ejemplo exacto del cliente: 5
        // invitados que suman $500 en total generan $5 de comisión (a 1%, no a 2%
        // — el 2% recién aplica a partir de la siguiente compra), y solo DESPUÉS
        // de esos $500 el referidor pasa a nivel 2.
        //
        // Nota: si dos pedidos del mismo referidor terminan de procesarse casi
        // simultáneamente, en teoría ambos podrían leer el mismo "acumulado antes"
        // (mini-race, sin candado explícito). El impacto real es mínimo — como mucho
        // una comisión puntual queda calculada con el nivel/% inmediato vecino en vez
        // del exacto — no hay riesgo de pérdida de dinero real ni de duplicar una
        // comisión (el UNIQUE KEY en pedido_id sigue protegiendo eso). No se agregó
        // GET_LOCK aquí a propósito: ese mecanismo se reserva para los casos de
        // dinero real de pagos (ver acquire_reference_processing_lock en este mismo
        // archivo), no para un cálculo de nivel de bajo impacto como este.
        $acumuladoStmt = $mysqli->prepare('SELECT COALESCE(SUM(monto_base), 0) AS total FROM referidos_comisiones WHERE referidor_user_id = ?');
        if (!$acumuladoStmt) {
            return;
        }
        $acumuladoStmt->bind_param('i', $referidorUserId);
        $acumuladoStmt->execute();
        $acumuladoResult = $acumuladoStmt->get_result();
        $acumuladoRow = $acumuladoResult ? $acumuladoResult->fetch_assoc() : null;
        $acumuladoStmt->close();
        $acumuladoAntes = (float) ($acumuladoRow['total'] ?? 0);

        $nivelInfo = referidos_nivel_para_monto($acumuladoAntes);
        $porcentaje = (float) $nivelInfo['porcentaje'];
        $nivel = (int) $nivelInfo['nivel'];
        $comision = round($montoBase * ($porcentaje / 100), 2);

        $insertStmt = $mysqli->prepare(
            "INSERT INTO referidos_comisiones (referidor_user_id, invitado_user_id, pedido_id, monto_base, porcentaje_aplicado, comision, nivel_en_momento, estado_pago)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'pendiente')"
        );
        if (!$insertStmt) {
            return;
        }
        $insertStmt->bind_param('iiidddi', $referidorUserId, $invitadoUserId, $orderId, $montoBase, $porcentaje, $comision, $nivel);
        $insertStmt->execute();
        $insertStmt->close();
    }
}

if (!function_exists('referidos_ensure_schema')) {
    function referidos_ensure_schema(): void {
        static $initialized = false;

        if ($initialized) {
            return;
        }
        $initialized = true;

        store_config_ensure_defaults();
        $mysqli = referidos_db();

        // usuarios: atribución de quién invitó a quién (de por vida, se fija una sola vez
        // en el registro) + el código propio de cada usuario para armar su link.
        $userColumns = [
            'referido_por_id' => "ALTER TABLE usuarios ADD COLUMN referido_por_id INT NULL AFTER rol",
            'referido_codigo' => "ALTER TABLE usuarios ADD COLUMN referido_codigo VARCHAR(20) NULL AFTER referido_por_id",
            'referido_en'     => "ALTER TABLE usuarios ADD COLUMN referido_en DATETIME NULL AFTER referido_codigo",
        ];
        $userExisting = [];
        $userColumnResult = $mysqli->query('SHOW COLUMNS FROM usuarios');
        if ($userColumnResult instanceof mysqli_result) {
            while ($row = $userColumnResult->fetch_assoc()) {
                $userExisting[$row['Field']] = true;
            }
        }
        foreach ($userColumns as $column => $sql) {
            if (!isset($userExisting[$column])) {
                $mysqli->query($sql);
            }
        }
        $codeIndexResult = $mysqli->query("SHOW INDEX FROM usuarios WHERE Key_name = 'uniq_usuarios_referido_codigo'");
        if (!($codeIndexResult instanceof mysqli_result) || $codeIndexResult->num_rows === 0) {
            $mysqli->query('ALTER TABLE usuarios ADD UNIQUE KEY uniq_usuarios_referido_codigo (referido_codigo)');
        }
        $referidoPorIndexResult = $mysqli->query("SHOW INDEX FROM usuarios WHERE Key_name = 'idx_usuarios_referido_por'");
        if (!($referidoPorIndexResult instanceof mysqli_result) || $referidoPorIndexResult->num_rows === 0) {
            $mysqli->query('ALTER TABLE usuarios ADD INDEX idx_usuarios_referido_por (referido_por_id)');
        }

        // cupones: monto mínimo de compra (regla de "más de $3", no existía ninguna
        // columna de monto mínimo en el sistema de cupones hasta ahora) + marca de
        // "cupón de bienvenida de referido" (para aplicarle la regla extra anti-fraude
        // SOLO a estos, nunca a los cupones normales que crea el admin a mano) + a qué
        // usuario invitado pertenece (necesario para el control de un solo uso).
        $couponColumns = [
            'monto_minimo'        => "ALTER TABLE cupones ADD COLUMN monto_minimo DECIMAL(10,2) NULL AFTER valor_descuento",
            'origen'              => "ALTER TABLE cupones ADD COLUMN origen ENUM('manual','referido') NOT NULL DEFAULT 'manual' AFTER monto_minimo",
            'referido_usuario_id' => "ALTER TABLE cupones ADD COLUMN referido_usuario_id INT NULL AFTER origen",
        ];
        $couponExisting = [];
        $couponColumnResult = $mysqli->query('SHOW COLUMNS FROM cupones');
        if ($couponColumnResult instanceof mysqli_result) {
            while ($row = $couponColumnResult->fetch_assoc()) {
                $couponExisting[$row['Field']] = true;
            }
        }
        foreach ($couponColumns as $column => $sql) {
            if (!isset($couponExisting[$column])) {
                $mysqli->query($sql);
            }
        }
        $referidoUsuarioIndexResult = $mysqli->query("SHOW INDEX FROM cupones WHERE Key_name = 'idx_cupones_referido_usuario'");
        if (!($referidoUsuarioIndexResult instanceof mysqli_result) || $referidoUsuarioIndexResult->num_rows === 0) {
            $mysqli->query('ALTER TABLE cupones ADD INDEX idx_cupones_referido_usuario (referido_usuario_id)');
        }

        // Ledger de comisiones: una fila por CADA pedido de un invitado que generó
        // comisión a su referidor (nunca se borra ni se recalcula con retroactividad —
        // el % y el nivel quedan "congelados" al momento del pedido, igual que
        // costo_unitario_base en pedidos, para que subir de nivel después no reescriba
        // comisiones ya generadas). estado_pago es el mismo concepto que
        // pedidos.estado_pago_influencer: si el admin ya le pagó (por fuera del sistema,
        // transferencia/etc.) ese monto de comisión al referidor.
        $mysqli->query(
            "CREATE TABLE IF NOT EXISTS referidos_comisiones (
                id INT AUTO_INCREMENT PRIMARY KEY,
                referidor_user_id INT NOT NULL,
                invitado_user_id INT NOT NULL,
                pedido_id INT NOT NULL,
                monto_base DECIMAL(12,2) NOT NULL,
                porcentaje_aplicado DECIMAL(5,2) NOT NULL,
                comision DECIMAL(12,2) NOT NULL,
                nivel_en_momento TINYINT NOT NULL,
                estado_pago ENUM('pendiente','pagado') NOT NULL DEFAULT 'pendiente',
                creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                pagado_en TIMESTAMP NULL DEFAULT NULL,
                UNIQUE KEY uniq_referidos_comisiones_pedido (pedido_id),
                INDEX idx_referidos_comisiones_referidor (referidor_user_id),
                INDEX idx_referidos_comisiones_invitado (invitado_user_id),
                INDEX idx_referidos_comisiones_estado (estado_pago)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $ledgerColumns = [
            'referidor_user_id'   => "ALTER TABLE referidos_comisiones ADD COLUMN referidor_user_id INT NOT NULL AFTER id",
            'invitado_user_id'    => "ALTER TABLE referidos_comisiones ADD COLUMN invitado_user_id INT NOT NULL AFTER referidor_user_id",
            'pedido_id'           => "ALTER TABLE referidos_comisiones ADD COLUMN pedido_id INT NOT NULL AFTER invitado_user_id",
            'monto_base'          => "ALTER TABLE referidos_comisiones ADD COLUMN monto_base DECIMAL(12,2) NOT NULL AFTER pedido_id",
            'porcentaje_aplicado' => "ALTER TABLE referidos_comisiones ADD COLUMN porcentaje_aplicado DECIMAL(5,2) NOT NULL AFTER monto_base",
            'comision'            => "ALTER TABLE referidos_comisiones ADD COLUMN comision DECIMAL(12,2) NOT NULL AFTER porcentaje_aplicado",
            'nivel_en_momento'    => "ALTER TABLE referidos_comisiones ADD COLUMN nivel_en_momento TINYINT NOT NULL AFTER comision",
            'estado_pago'         => "ALTER TABLE referidos_comisiones ADD COLUMN estado_pago ENUM('pendiente','pagado') NOT NULL DEFAULT 'pendiente' AFTER nivel_en_momento",
            'creado_en'           => "ALTER TABLE referidos_comisiones ADD COLUMN creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER estado_pago",
            'pagado_en'           => "ALTER TABLE referidos_comisiones ADD COLUMN pagado_en TIMESTAMP NULL DEFAULT NULL AFTER creado_en",
        ];
        $ledgerExisting = [];
        $ledgerColumnResult = $mysqli->query('SHOW COLUMNS FROM referidos_comisiones');
        if ($ledgerColumnResult instanceof mysqli_result) {
            while ($row = $ledgerColumnResult->fetch_assoc()) {
                $ledgerExisting[$row['Field']] = true;
            }
        }
        foreach ($ledgerColumns as $column => $sql) {
            if (!isset($ledgerExisting[$column])) {
                $mysqli->query($sql);
            }
        }
        foreach ([
            'uniq_referidos_comisiones_pedido'   => 'ALTER TABLE referidos_comisiones ADD UNIQUE KEY uniq_referidos_comisiones_pedido (pedido_id)',
            'idx_referidos_comisiones_referidor' => 'ALTER TABLE referidos_comisiones ADD INDEX idx_referidos_comisiones_referidor (referidor_user_id)',
            'idx_referidos_comisiones_invitado'  => 'ALTER TABLE referidos_comisiones ADD INDEX idx_referidos_comisiones_invitado (invitado_user_id)',
            'idx_referidos_comisiones_estado'    => 'ALTER TABLE referidos_comisiones ADD INDEX idx_referidos_comisiones_estado (estado_pago)',
        ] as $indexName => $sql) {
            $indexResult = $mysqli->query("SHOW INDEX FROM referidos_comisiones WHERE Key_name = '" . $mysqli->real_escape_string($indexName) . "'");
            if (!($indexResult instanceof mysqli_result) || $indexResult->num_rows === 0) {
                $mysqli->query($sql);
            }
        }
    }
}

// ─────────────────────────────────────────────────────────────────────────
// FASE 4: funciones de solo lectura para el panel "Mis Referidos" del cliente
// (api/account.php, action=referrals).
// ─────────────────────────────────────────────────────────────────────────

// Link completo de invitación a partir del código propio del usuario.
if (!function_exists('referidos_link_invitacion')) {
    function referidos_link_invitacion(string $codigo): string {
        if ($codigo === '') {
            return '';
        }
        return rtrim(app_url('/'), '/') . '/?ref=' . rawurlencode($codigo);
    }
}

// Mismo mecanismo de bloqueo que RECoins (win_points_user_monthly_spent:
// recarga propia en los últimos 30 días corridos), reusado tal cual — no se
// duplica la consulta, solo se compara contra el umbral propio de referidos.
if (!function_exists('referidos_estado_retiro')) {
    function referidos_estado_retiro(mysqli $mysqli, int $usuarioId): array {
        $minimo = referidos_retiro_minimo_recarga();
        if ($usuarioId <= 0) {
            return ['elegible' => false, 'recargado' => 0.0, 'requerido' => $minimo, 'restante' => $minimo];
        }

        require_once __DIR__ . '/win_points.php';
        $recargado = round(win_points_user_monthly_spent($mysqli, $usuarioId), 2);
        $elegible = $minimo <= 0 || $recargado >= $minimo;

        return [
            'elegible' => $elegible,
            'recargado' => $recargado,
            'requerido' => $minimo,
            'restante' => $elegible ? 0.0 : round($minimo - $recargado, 2),
        ];
    }
}

// Monto acumulado ACTUAL (incluyendo todo lo ya comisionado) — es lo que
// determina el nivel/barra de progreso que se le muestra al referidor
// AHORA MISMO. Distinto del "acumulado antes de este pedido" que usa
// referidos_registrar_comision_si_aplica() para congelar el % de cada
// comisión individual — aquí sí se incluye todo.
if (!function_exists('referidos_monto_acumulado_actual')) {
    function referidos_monto_acumulado_actual(mysqli $mysqli, int $usuarioId): float {
        if ($usuarioId <= 0) {
            return 0.0;
        }
        referidos_ensure_schema();

        $stmt = $mysqli->prepare('SELECT COALESCE(SUM(monto_base), 0) AS total FROM referidos_comisiones WHERE referidor_user_id = ?');
        if (!$stmt) {
            return 0.0;
        }
        $stmt->bind_param('i', $usuarioId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        return round((float) ($row['total'] ?? 0), 2);
    }
}

// Totales de ganancias del referidor: pendiente (todavía no pagado por el
// admin), pagado (ya liquidado) y el total histórico de ambos.
if (!function_exists('referidos_resumen_ganancias')) {
    function referidos_resumen_ganancias(mysqli $mysqli, int $usuarioId): array {
        $resumen = ['pendiente' => 0.0, 'pagado' => 0.0, 'total' => 0.0, 'total_pedidos' => 0];
        if ($usuarioId <= 0) {
            return $resumen;
        }
        referidos_ensure_schema();

        $stmt = $mysqli->prepare(
            "SELECT estado_pago, COALESCE(SUM(comision), 0) AS total, COUNT(*) AS cantidad
             FROM referidos_comisiones WHERE referidor_user_id = ? GROUP BY estado_pago"
        );
        if (!$stmt) {
            return $resumen;
        }
        $stmt->bind_param('i', $usuarioId);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($result && ($row = $result->fetch_assoc())) {
            $monto = round((float) ($row['total'] ?? 0), 2);
            $cantidad = (int) ($row['cantidad'] ?? 0);
            if (($row['estado_pago'] ?? '') === 'pagado') {
                $resumen['pagado'] = $monto;
            } else {
                $resumen['pendiente'] = $monto;
            }
            $resumen['total'] += $monto;
            $resumen['total_pedidos'] += $cantidad;
        }
        $stmt->close();
        $resumen['total'] = round($resumen['total'], 2);

        return $resumen;
    }
}

// Personas invitadas por este usuario, con lo que cada una lleva recargado
// (solo pedidos que realmente generaron comisión — pagado/enviado, sin
// RECoins) y desde cuándo está afiliada.
if (!function_exists('referidos_listar_invitados')) {
    function referidos_listar_invitados(mysqli $mysqli, int $usuarioId, int $limit = 100): array {
        if ($usuarioId <= 0) {
            return [];
        }
        referidos_ensure_schema();

        $resolvedLimit = max(1, min(300, $limit));
        $stmt = $mysqli->prepare(
            "SELECT u.id, u.nombre, u.email, u.referido_en,
                    COALESCE(c.total_recargado, 0) AS total_recargado,
                    COALESCE(c.total_pedidos, 0) AS total_pedidos
             FROM usuarios u
             LEFT JOIN (
                 SELECT invitado_user_id, SUM(monto_base) AS total_recargado, COUNT(*) AS total_pedidos
                 FROM referidos_comisiones
                 WHERE referidor_user_id = ?
                 GROUP BY invitado_user_id
             ) c ON c.invitado_user_id = u.id
             WHERE u.referido_por_id = ?
             ORDER BY u.referido_en DESC, u.id DESC
             LIMIT {$resolvedLimit}"
        );
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('ii', $usuarioId, $usuarioId);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($result && ($row = $result->fetch_assoc())) {
            $rows[] = [
                'nombre' => trim((string) ($row['nombre'] ?? '')),
                'email' => trim((string) ($row['email'] ?? '')),
                'referido_en' => (string) ($row['referido_en'] ?? ''),
                'total_recargado' => round((float) ($row['total_recargado'] ?? 0), 2),
                'total_pedidos' => (int) ($row['total_pedidos'] ?? 0),
            ];
        }
        $stmt->close();

        return $rows;
    }
}

// Historial de comisiones (una fila por pedido que generó comisión) — para
// el tab de "movimientos" del panel, igual patrón que win_points.
if (!function_exists('referidos_listar_comisiones')) {
    function referidos_listar_comisiones(mysqli $mysqli, int $usuarioId, int $limit = 50): array {
        if ($usuarioId <= 0) {
            return [];
        }
        referidos_ensure_schema();

        $resolvedLimit = max(1, min(200, $limit));
        $stmt = $mysqli->prepare(
            "SELECT rc.pedido_id, rc.monto_base, rc.porcentaje_aplicado, rc.comision, rc.nivel_en_momento,
                    rc.estado_pago, rc.creado_en, u.nombre AS invitado_nombre, u.email AS invitado_email
             FROM referidos_comisiones rc
             LEFT JOIN usuarios u ON u.id = rc.invitado_user_id
             WHERE rc.referidor_user_id = ?
             ORDER BY rc.creado_en DESC, rc.id DESC
             LIMIT {$resolvedLimit}"
        );
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('i', $usuarioId);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($result && ($row = $result->fetch_assoc())) {
            $rows[] = [
                'pedido_id' => (int) ($row['pedido_id'] ?? 0),
                'monto_base' => round((float) ($row['monto_base'] ?? 0), 2),
                'porcentaje_aplicado' => round((float) ($row['porcentaje_aplicado'] ?? 0), 2),
                'comision' => round((float) ($row['comision'] ?? 0), 2),
                'nivel_en_momento' => (int) ($row['nivel_en_momento'] ?? 0),
                'estado_pago' => (string) ($row['estado_pago'] ?? 'pendiente'),
                'creado_en' => (string) ($row['creado_en'] ?? ''),
                'invitado_nombre' => trim((string) ($row['invitado_nombre'] ?? '')),
                'invitado_email' => trim((string) ($row['invitado_email'] ?? '')),
            ];
        }
        $stmt->close();

        return $rows;
    }
}
