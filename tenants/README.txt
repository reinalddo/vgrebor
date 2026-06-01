Tenant directories live under /tenants/{slug}.

Each tenant can reuse the same PHP codebase while isolating:
- Database credentials via /tenants/{slug}/data.json
- Dynamic uploads via /tenants/{slug}/uploads/

Minimum recommended data.json structure:

{
	"tenant": {
		"slug": "virtualgaming",
		"domains": ["virtualgaming.tvirtualshop.com", "virtualgaming"]
	},
	"database": {
		"host": "localhost",
		"name": "tvirtualgaming",
		"user": "root",
		"password": "",
		"charset": "utf8mb4"
	}
}

Suggested upload buckets created on demand:
- /tenants/{slug}/uploads/store/
- /tenants/{slug}/uploads/gallery/
- /tenants/{slug}/uploads/juegos/
- /tenants/{slug}/uploads/paquetes/

If you add a new parked domain, create its tenant folder and data.json first so it does not inherit another tenant's database.
