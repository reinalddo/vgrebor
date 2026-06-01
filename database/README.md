Tenant database bootstrap

- `init_localhost.sql` sigue siendo util como referencia historica, pero ya no es el flujo recomendado para tenants nuevos.
- Usa el script CLI `database/bootstrap_tenant.php` para crear la base del tenant, las tablas minimas reales del proyecto, la moneda base `USD`, la configuracion inicial y las carpetas de uploads separadas por tenant.

Uso

- `php database/bootstrap_tenant.php virtualgaming`
- `php database/bootstrap_tenant.php nuevodominio1 --admin-email=admin@nuevodominio1.com --admin-password=CambiaEsto123!`

Que deja listo

- Base de datos del tenant segun `tenants/<slug>/data.json`
- Tablas base: `usuarios`, `monedas`, `juegos`, `juego_caracteristicas`, `juego_paquetes`, `pedidos`, `movimientos`, `configuracion`, `configuracion_general`, `home_gallery`, `payment_methods`, `cupones`, `cupones_usuarios`, `cupones_influencer_ventas`
- Moneda base `USD`
- Configuracion general inicial mediante `store_config_ensure_defaults()`
- Carpetas: `tenants/<slug>/uploads/store`, `gallery`, `juegos`, `paquetes`

Notas

- El script es idempotente: se puede ejecutar mas de una vez para normalizar un tenant ya existente.
- Si no existe un usuario admin, crea uno. Por defecto usa `admin@<slug>.local` y clave temporal `admin123`.
- Si usas la clave temporal, cambiala inmediatamente despues del primer acceso.
