CREATE DATABASE IF NOT EXISTS tvirtualgaming
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE tvirtualgaming;

-- Placeholder for future schema

-- Tabla de usuarios para administración
CREATE TABLE IF NOT EXISTS usuarios (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50) NOT NULL UNIQUE,
  password VARCHAR(255) NOT NULL,
  nombre VARCHAR(100),
  email VARCHAR(100),
  telefono VARCHAR(50) DEFAULT NULL,
  foto_perfil VARCHAR(255) DEFAULT NULL,
  rol ENUM('admin','usuario','empleado','influencer','root') DEFAULT 'usuario',
  creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Usuario admin por defecto (clave: admin123)
INSERT INTO usuarios (username, password, nombre, email, rol)
VALUES ('admin', '$2y$10$wH8QwQwQwQwQwQwQwQwQwOQwQwQwQwQwQwQwQwQwQwQwQwQwQw', 'Administrador', 'admin@localhost', 'admin')
ON DUPLICATE KEY UPDATE username=username;

-- Nota: El password está hasheado con password_hash('admin123', PASSWORD_DEFAULT)

-- Tabla de juegos
CREATE TABLE IF NOT EXISTS juegos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(100) NOT NULL,
  descripcion TEXT,
  precio DECIMAL(10,2) NOT NULL,
  imagen VARCHAR(255),
  creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS ventana_inicio_juego_configuracion (
  id INT AUTO_INCREMENT PRIMARY KEY,
  juego_id INT NOT NULL,
  activa TINYINT(1) NOT NULL DEFAULT 0,
  titulo VARCHAR(255) NOT NULL,
  icono TEXT NOT NULL,
  descripcion TEXT NOT NULL,
  check_texto TEXT NOT NULL,
  boton_texto VARCHAR(255) NOT NULL,
  modal_background VARCHAR(7) NOT NULL DEFAULT '#18101e',
  title_color VARCHAR(7) NOT NULL DEFAULT '#f8b53d',
  check_text_color VARCHAR(7) NOT NULL DEFAULT '#e2e8f0',
  check_background_color VARCHAR(7) NOT NULL DEFAULT '#1e293b',
  button_text_color VARCHAR(7) NOT NULL DEFAULT '#0b0f18',
  button_background_color VARCHAR(7) NOT NULL DEFAULT '#c99712',
  button_disabled_text_color VARCHAR(7) NOT NULL DEFAULT '#0b0f18',
  button_disabled_background_color VARCHAR(7) NOT NULL DEFAULT '#c99712',
  creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  actualizado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_juego (juego_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ventana_inicio_juego_tarjetas (
  id INT AUTO_INCREMENT PRIMARY KEY,
  juego_id INT NOT NULL DEFAULT 0,
  content_html LONGTEXT NOT NULL,
  color VARCHAR(7) NOT NULL DEFAULT '#233A73',
  background_color VARCHAR(7) NOT NULL DEFAULT '#121a2f',
  media_path TEXT NULL,
  media_embed_url TEXT NULL,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  orden INT NOT NULL DEFAULT 1,
  creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  actualizado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_game_active_order (juego_id, activo, orden)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla de pedidos
CREATE TABLE IF NOT EXISTS pedidos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT NOT NULL,
  juego_id INT NOT NULL,
  cantidad INT NOT NULL DEFAULT 1,
  total DECIMAL(10,2) NOT NULL,
  estado ENUM('pendiente','pagado','enviado','cancelado') DEFAULT 'pendiente',
  creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id),
  FOREIGN KEY (juego_id) REFERENCES juegos(id)
);
