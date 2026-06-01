CREATE TABLE IF NOT EXISTS movimientos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    referencia VARCHAR(120) NOT NULL,
    descripcion VARCHAR(255) DEFAULT NULL,
    fecha_raw VARCHAR(120) DEFAULT NULL,
    fecha_movimiento DATETIME DEFAULT NULL,
    tipo VARCHAR(80) DEFAULT NULL,
    monto DECIMAL(14,2) NOT NULL DEFAULT 0,
    moneda VARCHAR(20) NOT NULL DEFAULT 'VES',
    pedido_id INT DEFAULT NULL,
    payload_json LONGTEXT DEFAULT NULL,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    actualizado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_movimientos_referencia (referencia),
    INDEX idx_movimientos_pedido_id (pedido_id),
    INDEX idx_movimientos_monto (monto),
    INDEX idx_movimientos_fecha (fecha_movimiento)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;