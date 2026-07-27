<?php
/**
 * admin/_auth.php — CAPA DE COMPATIBILIDAD para el Gestor de Streaming portado de CONEC.
 *
 * Los archivos de admin/stream/ vienen TAL CUAL de CONEC y esperan estas funciones
 * (db(), config(), admin_require_login(), admin_es_admin(), csrf_*, h(), user_*, etc.).
 * Aquí las definimos mapeándolas al stack de Reborxstore: PDO de includes/db.php y la
 * sesión $_SESSION['auth_user'] (rol admin/root). WhatsApp/wallet/CRM quedan como stubs.
 *
 * También garantiza el esquema de tablas streaming_* (que en CONEC creaban las
 * migraciones admin/aplicar-migracion-*.php).
 */
if (!defined('CONEC_ADMIN')) { http_response_code(403); exit('Acceso denegado'); }
if (!defined('CONEC_API')) { define('CONEC_API', true); }

require_once __DIR__ . '/../includes/tenant.php';
require_once __DIR__ . '/../includes/db.php'; // define $pdo (PDO), arranca migraciones tolerantes

// En el Gestor de Streaming NO mostramos avisos cosméticos de PHP (Undefined array key, etc.)
// al usuario: ensucian la página. Los errores GRAVES (fatales/excepciones) sí se siguen viendo.
// Esto imita el entorno de producción (display_errors off) sin depender del php.ini del servidor.
error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE & ~E_DEPRECATED & ~E_STRICT);

if (session_status() !== PHP_SESSION_ACTIVE) {
    tenant_start_session();
}

// ─────────────────────────────────────────────────────────────────────────
// Conexión / configuración
// ─────────────────────────────────────────────────────────────────────────
if (!function_exists('db')) {
    function db(): PDO {
        global $pdo;
        return $pdo;
    }
}

if (!function_exists('config')) {
    function config(): array {
        static $cfg = null;
        if ($cfg !== null) {
            return $cfg;
        }
        $dbc = function_exists('tenant_database_config') ? tenant_database_config() : [];
        $cfg = [
            'db' => [
                'host'    => $dbc['host'] ?? 'localhost',
                'name'    => $dbc['name'] ?? '',
                'user'    => $dbc['user'] ?? 'root',
                'pass'    => $dbc['password'] ?? '',
                'charset' => $dbc['charset'] ?? 'utf8mb4',
            ],
            // Secreto estable para firmar links privados de acceso (HMAC). Derivado del nombre de BD.
            'api_token'        => hash('sha256', 'reborxstore-stream::' . ($dbc['name'] ?? 'db')),
            'tasa_bcv_default' => 36.50,
        ];
        return $cfg;
    }
}

// ─────────────────────────────────────────────────────────────────────────
// Escape + CSRF (idénticos a CONEC)
// ─────────────────────────────────────────────────────────────────────────
if (!function_exists('h')) {
    function h($s): string {
        return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('csrf_token')) {
    function csrf_token(): string {
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(16));
        }
        return $_SESSION['csrf'];
    }
}

if (!function_exists('csrf_check')) {
    function csrf_check(): void {
        $t = $_POST['_csrf'] ?? '';
        if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], (string) $t)) {
            http_response_code(403);
            exit('CSRF inválido');
        }
    }
}

if (!function_exists('csrf_check_api')) {
    function csrf_check_api(): void {
        $t = $_SERVER['HTTP_X_CSRF'] ?? ($_POST['_csrf'] ?? '');
        if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], (string) $t)) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'csrf_invalido']);
            exit;
        }
    }
}

// ─────────────────────────────────────────────────────────────────────────
// Contexto: 'admin' (dueño de la tienda, owner_id 0) o 'revendedor' (su propio id).
// Un wrapper en /revendedor/stream/*.php hace define('STREAM_CTX','revendedor') antes
// de incluir el archivo compartido de admin/stream/.
// ─────────────────────────────────────────────────────────────────────────
if (!function_exists('stream_ctx')) {
    function stream_ctx(): string {
        return (defined('STREAM_CTX') && STREAM_CTX === 'revendedor') ? 'revendedor' : 'admin';
    }
}

/** Dueño del inventario en este request: 0 = la tienda (admin); id del revendedor si es su panel. */
if (!function_exists('stream_owner_id')) {
    function stream_owner_id(): int {
        return stream_ctx() === 'revendedor' ? (int) (current_user_id() ?? 0) : 0;
    }
}

// ─────────────────────────────────────────────────────────────────────────
// Sesión / roles — mapeados a $_SESSION['auth_user'] de Reborxstore
// ─────────────────────────────────────────────────────────────────────────
if (!function_exists('_stream_auth_user')) {
    function _stream_auth_user(): array {
        $u = $_SESSION['auth_user'] ?? null;
        return is_array($u) ? $u : [];
    }
}

if (!function_exists('_stream_rol')) {
    function _stream_rol(): string {
        return strtolower(trim((string) (_stream_auth_user()['rol'] ?? '')));
    }
}

if (!function_exists('user_logged_in')) {
    function user_logged_in(): bool {
        return _stream_auth_user() !== [];
    }
}

if (!function_exists('current_user_id')) {
    function current_user_id(): ?int {
        $id = (int) (_stream_auth_user()['id'] ?? 0);
        return $id > 0 ? $id : null;
    }
}

if (!function_exists('user_is_admin')) {
    function user_is_admin(): bool {
        // "Dueño legítimo" de ESTE panel:
        //  - contexto admin      → admin / root (dueño de la tienda)
        //  - contexto revendedor → rol 'revendedor' (dueño de su propio inventario)
        // En ambos casos ve TODAS las funciones de su espacio (costos, proveedores, etc.).
        if (stream_ctx() === 'revendedor') {
            return _stream_rol() === 'revendedor';
        }
        return in_array(_stream_rol(), ['admin', 'root'], true);
    }
}

if (!function_exists('user_rol_admin')) {
    function user_rol_admin(): ?string {
        return user_is_admin() ? 'admin' : null;
    }
}

if (!function_exists('user_wa_area')) {
    function user_wa_area(): string {
        return 'ambas';
    }
}

if (!function_exists('get_current_user_data')) {
    function get_current_user_data(): ?array {
        if (!user_logged_in()) {
            return null;
        }
        $u = _stream_auth_user();
        return [
            'id'             => (int) ($u['id'] ?? 0),
            'email'          => (string) ($u['email'] ?? ''),
            'nombre'         => (string) ($u['full_name'] ?? $u['username'] ?? 'Admin'),
            'telefono'       => (string) ($u['telefono'] ?? ''),
            'vcoins_saldo'   => 0.0,
            'loyalty_puntos' => 0,
            'es_admin'       => user_is_admin(),
            'es_revendedor'  => false,
            'is_sub'         => false,
        ];
    }
}

// Rol del panel: 'admin' (dueño/root) — no manejamos empleados en el gestor de streaming.
if (!function_exists('admin_rol')) {
    function admin_rol(): string {
        return user_rol_admin() ?: 'empleado';
    }
}
if (!function_exists('admin_es_admin')) {
    function admin_es_admin(): bool {
        return admin_rol() === 'admin';
    }
}
if (!function_exists('admin_es_empleado')) {
    function admin_es_empleado(): bool {
        return admin_rol() === 'empleado';
    }
}
if (!function_exists('admin_es_supervisor')) {
    function admin_es_supervisor(): bool {
        return admin_es_admin();
    }
}
if (!function_exists('admin_wa_area')) {
    function admin_wa_area(): string {
        return 'ambas';
    }
}
if (!function_exists('admin_tope_aprobacion')) {
    function admin_tope_aprobacion(): float {
        return admin_es_admin() ? INF : 0.0;
    }
}

if (!function_exists('admin_logged_in')) {
    function admin_logged_in(): bool {
        return user_logged_in() && user_is_admin();
    }
}

if (!function_exists('admin_require_login')) {
    function admin_require_login(): void {
        if (!user_logged_in()) {
            $to = function_exists('app_path') ? app_path('/login.php') : '/login.php';
            header('Location: ' . $to);
            exit;
        }
        if (!user_is_admin()) {
            // Un usuario logueado pero del rol equivocado para este panel.
            if (stream_ctx() === 'revendedor') {
                $to = function_exists('app_path') ? app_path('/') : '/';
                header('Location: ' . $to);
                exit;
            }
            http_response_code(403);
            exit('Acceso denegado: necesitas privilegios de administrador.');
        }
    }
}

if (!function_exists('admin_require_admin')) {
    function admin_require_admin(): void {
        admin_require_login();
        if (!admin_es_admin()) {
            http_response_code(403);
            exit('Solo administradores.');
        }
    }
}

if (!function_exists('admin_require_supervisor')) {
    function admin_require_supervisor(): void {
        admin_require_login();
    }
}

// ─────────────────────────────────────────────────────────────────────────
// Contexto de revendedor / sub-usuario — no aplica en el gestor del admin
// ─────────────────────────────────────────────────────────────────────────
if (!function_exists('rev_is_sub')) {
    function rev_is_sub(): bool { return false; }
}
if (!function_exists('rev_actor_id')) {
    function rev_actor_id(): int { return (int) (current_user_id() ?? 0); }
}
if (!function_exists('rev_actor_nombre')) {
    function rev_actor_nombre(): string { return ''; }
}
if (!function_exists('rev_perms')) {
    function rev_perms() { return '*'; }
}
if (!function_exists('rev_has')) {
    function rev_has(string $p): bool { return true; }
}
if (!function_exists('rev_gate')) {
    function rev_gate(string $p): void { /* dueño: todo permitido */ }
}
if (!function_exists('conec_set_staff_cookie')) {
    function conec_set_staff_cookie(bool $on): void { /* no-op en Reborxstore */ }
}

// ─────────────────────────────────────────────────────────────────────────
// Esquema de tablas streaming_* (lo que en CONEC creaban las migraciones)
// ─────────────────────────────────────────────────────────────────────────
require_once __DIR__ . '/_streaming_schema.php';
stream_ensure_schema(db());
// Revendedor nuevo: sembrar plataformas por defecto (owner 0 = admin no se toca).
stream_seed_owner_platforms(db(), stream_owner_id());
