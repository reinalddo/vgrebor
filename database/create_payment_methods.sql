CREATE TABLE IF NOT EXISTS payment_methods (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(160) NOT NULL,
    datos TEXT NOT NULL,
    moneda_id INT NULL,
    referencia_digitos INT NOT NULL DEFAULT 0,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    actualizado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_payment_methods_activo (activo),
    INDEX idx_payment_methods_nombre (nombre),
    INDEX idx_payment_methods_moneda_id (moneda_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;