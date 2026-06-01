<?php

function influencer_coupon_clean_text($value, int $max = 255): ?string {
    $clean = trim((string) $value);
    if ($clean === '') {
        return null;
    }

    return substr($clean, 0, $max);
}

function influencer_coupon_commission_percent($value): float {
    $numeric = is_numeric($value) ? (float) $value : 0.0;
    $numeric = max(0, min(100, $numeric));
    return round($numeric, 2);
}

function influencer_coupon_commission_total(float $saleAmount, float $commissionPercent): float {
    $saleAmount = max(0, $saleAmount);
    $commissionPercent = max(0, $commissionPercent);
    return round($saleAmount * ($commissionPercent / 100), 2);
}

function influencer_coupon_has_owner(array $coupon): bool {
    return influencer_coupon_clean_text($coupon['nombre_influencer'] ?? null, 100) !== null;
}

function influencer_coupon_payload_from_input(array $input): array {
    return [
        'nombre_influencer' => influencer_coupon_clean_text($input['nombre_influencer'] ?? null, 100),
        'telefono_influencer' => influencer_coupon_clean_text($input['telefono_influencer'] ?? null, 50),
        'email_influencer' => influencer_coupon_clean_text($input['email_influencer'] ?? null, 100),
        'comision_influencer' => influencer_coupon_commission_percent($input['comision_influencer'] ?? 0),
    ];
}

function influencer_coupon_validate_payload(array $payload): array {
    $errors = [];
    $hasInfluencerName = $payload['nombre_influencer'] !== null;
    $hasAdditionalData = ($payload['telefono_influencer'] !== null)
        || ($payload['email_influencer'] !== null)
        || ((float) ($payload['comision_influencer'] ?? 0) > 0);

    if (!$hasInfluencerName && $hasAdditionalData) {
        $errors[] = 'Para configurar un cupón de influencer debes indicar el nombre del influencer.';
    }

    if ($hasInfluencerName && (float) ($payload['comision_influencer'] ?? 0) <= 0) {
        $errors[] = 'La comisión del influencer debe ser mayor a 0%.';
    }

    if ($payload['email_influencer'] !== null && filter_var($payload['email_influencer'], FILTER_VALIDATE_EMAIL) === false) {
        $errors[] = 'El correo del influencer no es válido.';
    }

    return $errors;
}

function influencer_coupon_sales_table_sql(): string {
    return "CREATE TABLE IF NOT EXISTS cupones_influencer_ventas (
        id INT AUTO_INCREMENT PRIMARY KEY,
        cupon_id INT NOT NULL,
        pedido_id INT NOT NULL,
        nombre_influencer VARCHAR(100) DEFAULT NULL,
        codigo_cupon VARCHAR(60) NOT NULL,
        telefono_influencer VARCHAR(50) DEFAULT NULL,
        email_influencer VARCHAR(100) DEFAULT NULL,
        comision_porcentaje DECIMAL(5,2) NOT NULL DEFAULT 0,
        paquete_vendido VARCHAR(180) DEFAULT NULL,
        moneda VARCHAR(20) DEFAULT NULL,
        total_pedido DECIMAL(12,2) NOT NULL DEFAULT 0,
        total_comision DECIMAL(12,2) NOT NULL DEFAULT 0,
        estado_pago ENUM('pendiente','pagado') NOT NULL DEFAULT 'pendiente',
        creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_influencer_sale_order (pedido_id),
        KEY idx_influencer_sale_coupon (cupon_id),
        KEY idx_influencer_sale_code (codigo_cupon),
        KEY idx_influencer_sale_payment_state (estado_pago)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
}

function influencer_coupon_ensure_sales_table_pdo(PDO $pdo): void {
    $pdo->exec(influencer_coupon_sales_table_sql());
}

function influencer_coupon_ensure_sales_table_mysqli(mysqli $mysqli): void {
    $mysqli->query(influencer_coupon_sales_table_sql());
}

function ensure_influencer_payment_status_column_pdo(PDO $pdo): void {
    $columns = $pdo->query("SHOW COLUMNS FROM pedidos LIKE 'estado_pago_influencer'");
    $exists = $columns ? $columns->fetch(PDO::FETCH_ASSOC) : false;
    if (!$exists) {
        $pdo->exec("ALTER TABLE pedidos ADD COLUMN estado_pago_influencer ENUM('pendiente','pagado') NOT NULL DEFAULT 'pendiente' AFTER cupon");
    }
}

function sync_coupon_usage_counts_pdo(PDO $pdo): void {
    $sql = "UPDATE cupones c
        LEFT JOIN (
            SELECT UPPER(TRIM(cupon)) AS codigo_normalizado, COUNT(*) AS total_uses
            FROM pedidos
            WHERE cupon IS NOT NULL AND TRIM(cupon) <> ''
            GROUP BY UPPER(TRIM(cupon))
        ) p ON p.codigo_normalizado = UPPER(TRIM(c.codigo))
        SET c.usos_actuales = COALESCE(p.total_uses, 0)";
    $pdo->exec($sql);
}

function sync_coupon_usage_counts_mysqli(mysqli $mysqli): void {
    $sql = "UPDATE cupones c
        LEFT JOIN (
            SELECT UPPER(TRIM(cupon)) AS codigo_normalizado, COUNT(*) AS total_uses
            FROM pedidos
            WHERE cupon IS NOT NULL AND TRIM(cupon) <> ''
            GROUP BY UPPER(TRIM(cupon))
        ) p ON p.codigo_normalizado = UPPER(TRIM(c.codigo))
        SET c.usos_actuales = COALESCE(p.total_uses, 0)";
    $mysqli->query($sql);
}

function backfill_influencer_sales_pdo(PDO $pdo): void {
    $sql = "INSERT INTO cupones_influencer_ventas (
            cupon_id,
            pedido_id,
            nombre_influencer,
            codigo_cupon,
            telefono_influencer,
            email_influencer,
            comision_porcentaje,
            paquete_vendido,
            moneda,
            total_pedido,
            total_comision,
            estado_pago
        )
        SELECT
            c.id,
            p.id,
            c.nombre_influencer,
            c.codigo,
            c.telefono_influencer,
            c.email_influencer,
            COALESCE(c.comision_influencer, 0),
            p.paquete_nombre,
            p.moneda,
            COALESCE(p.precio, 0),
            ROUND(COALESCE(p.precio, 0) * (COALESCE(c.comision_influencer, 0) / 100), 2),
            'pendiente'
        FROM pedidos p
        INNER JOIN cupones c ON UPPER(TRIM(c.codigo)) = UPPER(TRIM(p.cupon))
        LEFT JOIN cupones_influencer_ventas s ON s.pedido_id = p.id
        WHERE s.id IS NULL
            AND c.nombre_influencer IS NOT NULL
            AND TRIM(c.nombre_influencer) <> ''
            AND (
                (p.numero_referencia IS NOT NULL AND TRIM(p.numero_referencia) <> '')
                OR (p.telefono_contacto IS NOT NULL AND TRIM(p.telefono_contacto) <> '')
                OR p.estado IN ('pagado', 'enviado')
            )";
    $pdo->exec($sql);
}

function backfill_influencer_order_payment_status_pdo(PDO $pdo): void {
    $sql = "UPDATE pedidos p
        INNER JOIN cupones c ON UPPER(TRIM(c.codigo)) = UPPER(TRIM(p.cupon))
        SET p.estado_pago_influencer = CASE
            WHEN p.estado_pago_influencer = 'pagado' THEN 'pagado'
            ELSE 'pendiente'
        END
        WHERE c.nombre_influencer IS NOT NULL
            AND TRIM(c.nombre_influencer) <> ''
            AND (
                p.estado IN ('pagado', 'enviado')
                OR (p.numero_referencia IS NOT NULL AND TRIM(p.numero_referencia) <> '')
                OR (p.telefono_contacto IS NOT NULL AND TRIM(p.telefono_contacto) <> '')
            )";
    $pdo->exec($sql);
}