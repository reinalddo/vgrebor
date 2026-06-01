USE tvirtualgaming;
CREATE TABLE IF NOT EXISTS cupones (
    id INT AUTO_INCREMENT PRIMARY KEY,
    codigo VARCHAR(50) NOT NULL UNIQUE,
    tipo_descuento ENUM('porcentaje', 'fijo') NOT NULL,
    valor_descuento DECIMAL(10,2) NOT NULL,
    fecha_expiracion DATETIME NULL,
    limite_usos INT NULL DEFAULT 0,
    usos_actuales INT NOT NULL DEFAULT 0,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    fecha_creacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- Tabla para registrar el uso de cupones por usuario
CREATE TABLE IF NOT EXISTS cupones_usuarios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_cupon INT NOT NULL,
    id_usuario INT NOT NULL,
    fecha_uso DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_cupon_usuario (id_cupon, id_usuario),
    FOREIGN KEY (id_cupon) REFERENCES cupones(id) ON DELETE CASCADE
    -- FOREIGN KEY (id_usuario) REFERENCES usuarios(id) ON DELETE CASCADE
);