Database notes and migration scripts

- The project uses SQL migration files under this directory to create and update schema. Run the SQL files against the target database as needed.
- The legacy tenant bootstrap workflow has been retired in this single-tenant fork. Uploads are now stored in a unified `uploads/` directory at project root.

Usage

- Apply the SQL files (for example `create_*` and `migrar_*.sql`) to initialize or update the database schema.

Uploads

- Public uploads live under `uploads/` with buckets such as `store`, `gallery`, `juegos`, `paquetes`, `influencer`, `users`.

Notes

- If you previously relied on tenant-specific configuration files, environment variables or the default database names are used by the application when no external configuration is present.
- Keep backups before running destructive operations.
