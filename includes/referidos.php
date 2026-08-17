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
