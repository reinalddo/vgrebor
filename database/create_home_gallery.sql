CREATE TABLE IF NOT EXISTS home_gallery (
    id INT AUTO_INCREMENT PRIMARY KEY,
    titulo VARCHAR(160) NOT NULL,
    descripcion1 VARCHAR(255) NOT NULL,
    descripcion2 VARCHAR(255) NOT NULL,
    imagen VARCHAR(255) NOT NULL,
    url VARCHAR(500) DEFAULT NULL,
    abrir_nueva_pestana TINYINT(1) NOT NULL DEFAULT 0,
    destacado TINYINT(1) NOT NULL DEFAULT 0,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    actualizado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_home_gallery_destacado (destacado),
    INDEX idx_home_gallery_creado (creado_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;