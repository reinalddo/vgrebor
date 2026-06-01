CREATE TABLE IF NOT EXISTS configuracion (
  id INT AUTO_INCREMENT PRIMARY KEY,
  correo_corporativo VARCHAR(180) NOT NULL,
  smtp_host VARCHAR(120) NOT NULL,
  smtp_user VARCHAR(120) NOT NULL,
  smtp_pass VARCHAR(120) NOT NULL,
  smtp_port INT NOT NULL DEFAULT 587,
  smtp_secure VARCHAR(10) NOT NULL DEFAULT 'tls',
  creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Ejemplo de inserción inicial
INSERT INTO configuracion (correo_corporativo, smtp_host, smtp_user, smtp_pass, smtp_port, smtp_secure)
VALUES ('', '', '', '', 587, 'tls');
