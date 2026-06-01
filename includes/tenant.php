<?php

if (!function_exists('app_timezone_name')) {
    function app_timezone_name(): string {
        return 'America/Caracas';
    }
}

if (!function_exists('app_configure_timezone')) {
    function app_configure_timezone(): void {
        static $configured = false;

        if ($configured) {
            return;
        }

        date_default_timezone_set(app_timezone_name());
        $configured = true;
    }
}

app_configure_timezone();

if (!function_exists('tenant_normalize_host')) {
    function tenant_normalize_host(?string $host = null): string {
        $resolvedHost = $host ?? (string) ($_SERVER['HTTP_HOST'] ?? '');
        $resolvedHost = strtolower(trim($resolvedHost));
        $resolvedHost = preg_replace('/:[0-9]+$/', '', $resolvedHost) ?? $resolvedHost;
        return $resolvedHost;
    }
}

if (!function_exists('tenant_slugify')) {
    function tenant_slugify(string $value): string {
        $normalized = strtolower(trim($value));
        $normalized = str_replace(['.', ' '], '-', $normalized);
        $normalized = preg_replace('/[^a-z0-9_-]+/', '-', $normalized) ?? $normalized;
        $normalized = preg_replace('/-+/', '-', $normalized) ?? $normalized;
        return trim($normalized, '-');
    }
}

if (!function_exists('tenant_base_directory')) {
    function tenant_base_directory(): string {
        return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'tenants';
    }
}

if (!function_exists('tenant_directory_path')) {
    function tenant_directory_path(string $slug): string {
        return tenant_base_directory() . DIRECTORY_SEPARATOR . $slug;
    }
}

if (!function_exists('tenant_data_file_path')) {
    function tenant_data_file_path(string $slug): string {
        return tenant_directory_path($slug) . DIRECTORY_SEPARATOR . 'data.json';
    }
}

if (!function_exists('tenant_directory_exists')) {
    function tenant_directory_exists(string $slug): bool {
        return $slug !== '' && is_dir(tenant_directory_path($slug));
    }
}

if (!function_exists('tenant_known_local_hosts')) {
    function tenant_known_local_hosts(): array {
        return ['localhost', '127.0.0.1', 'vgrebor'];
    }
}

if (!function_exists('tenant_available_slugs')) {
    function tenant_available_slugs(): array {
        static $cache = null;

        if ($cache !== null) {
            return $cache;
        }

        $cache = [];
        $baseDirectory = tenant_base_directory();
        if (!is_dir($baseDirectory)) {
            return $cache;
        }

        $entries = scandir($baseDirectory);
        if (!is_array($entries)) {
            return $cache;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            if (is_dir($baseDirectory . DIRECTORY_SEPARATOR . $entry)) {
                $cache[] = $entry;
            }
        }

        return $cache;
    }
}

if (!function_exists('tenant_load_data_file')) {
    function tenant_load_data_file(string $slug): array {
        static $cache = [];

        if (isset($cache[$slug])) {
            return $cache[$slug];
        }

        $filePath = tenant_data_file_path($slug);
        if (!is_file($filePath)) {
            $cache[$slug] = [];
            return $cache[$slug];
        }

        $content = file_get_contents($filePath);
        if (!is_string($content) || $content === '') {
            $cache[$slug] = [];
            return $cache[$slug];
        }

        $decoded = json_decode($content, true);
        $cache[$slug] = is_array($decoded) ? $decoded : [];
        return $cache[$slug];
    }
}

if (!function_exists('vgrebor_single_tenant_slug')) {
    function vgrebor_single_tenant_slug(): string {
        return 'reborxstore';
    }
}

if (!function_exists('vgrebor_local_runtime_slug')) {
    function vgrebor_local_runtime_slug(): string {
        return 'localhost';
    }
}

if (!function_exists('vgrebor_current_environment')) {
    function vgrebor_current_environment(): string {
        $configuredEnv = strtolower(trim((string) getenv('VGREBOR_APP_ENV')));
        if (in_array($configuredEnv, ['local', 'development', 'dev'], true)) {
            return 'local';
        }

        if (in_array($configuredEnv, ['production', 'prod'], true)) {
            return 'production';
        }

        $host = tenant_normalize_host();
        if ($host !== '' && in_array($host, tenant_known_local_hosts(), true)) {
            return 'local';
        }

        if ($host === '' && PHP_SAPI === 'cli') {
            $appRoot = str_replace('\\', '/', dirname(__DIR__));
            if (preg_match('#^[a-z]:/wamp64/www/#i', $appRoot) === 1) {
                return 'local';
            }
        }

        return 'production';
    }
}

if (!function_exists('vgrebor_is_local_environment')) {
    function vgrebor_is_local_environment(): bool {
        return vgrebor_current_environment() === 'local';
    }
}

if (!function_exists('vgrebor_single_tenant_raw_config')) {
    function vgrebor_single_tenant_raw_config(): array {
        return tenant_load_data_file(vgrebor_single_tenant_slug());
    }
}

if (!function_exists('vgrebor_local_runtime_raw_config')) {
    function vgrebor_local_runtime_raw_config(): array {
        return tenant_load_data_file(vgrebor_local_runtime_slug());
    }
}

if (!function_exists('vgrebor_managed_upload_slugs')) {
    function vgrebor_managed_upload_slugs(): array {
        static $cache = null;

        if ($cache !== null) {
            return $cache;
        }

        $cache = [];
        foreach ([vgrebor_single_tenant_slug(), vgrebor_local_runtime_slug()] as $slug) {
            $normalizedSlug = tenant_slugify($slug);
            if ($normalizedSlug !== '') {
                $cache[] = $normalizedSlug;
            }
        }

        $cache = array_values(array_unique($cache));
        return $cache;
    }
}

if (!function_exists('vgrebor_candidate_public_relative_paths')) {
    function vgrebor_candidate_public_relative_paths(string $path): array {
        $normalizedPath = trim(str_replace('\\', '/', $path), '/');
        if ($normalizedPath === '') {
            return [];
        }

        $candidates = [$normalizedPath];

        // Primary candidate is the path as-is; prefer the unified uploads/ layout.
        // If the path targets uploads/ we keep it; otherwise the caller may
        // provide other relative paths to be resolved.
        if (str_starts_with($normalizedPath, 'uploads/')) {
            // already normalized to the unified uploads layout
            $candidates = [$normalizedPath];
        }

        return array_values(array_unique(array_filter($candidates, static fn ($candidate) => $candidate !== '')));
    }
}

if (!function_exists('tenant_host_to_slug_index')) {
    function tenant_host_to_slug_index(): array {
        static $cache = null;

        if ($cache !== null) {
            return $cache;
        }

        $cache = [];
        foreach (tenant_available_slugs() as $slug) {
            $config = tenant_load_data_file($slug);
            $tenant = is_array($config['tenant'] ?? null) ? $config['tenant'] : [];
            $domains = $tenant['domains'] ?? $tenant['hosts'] ?? [];

            if (is_string($domains) && $domains !== '') {
                $domains = [$domains];
            }

            if (!is_array($domains)) {
                $domains = [];
            }

            foreach ($domains as $domain) {
                $host = tenant_normalize_host((string) $domain);
                if ($host !== '') {
                    $cache[$host] = $slug;
                }
            }
        }

        return $cache;
    }
}

if (!function_exists('tenant_candidate_slugs_for_host')) {
    function tenant_candidate_slugs_for_host(string $host): array {
        $normalizedHost = tenant_normalize_host($host);
        if ($normalizedHost === '') {
            return [];
        }

        $candidates = [];
        $candidates[] = tenant_slugify($normalizedHost);

        if (str_starts_with($normalizedHost, 'www.')) {
            $candidates[] = tenant_slugify(substr($normalizedHost, 4));
        }

        if (str_contains($normalizedHost, '.')) {
            $segments = explode('.', $normalizedHost);
            if (($segments[0] ?? '') !== '') {
                $candidates[] = tenant_slugify($segments[0]);
            }
        }

        if (in_array($normalizedHost, tenant_known_local_hosts(), true)) {
            $candidates[] = 'virtualgaming';
            $candidates[] = 'localhost';
        }

        return array_values(array_unique(array_filter($candidates, static fn ($value) => $value !== '')));
    }
}

if (!function_exists('resolve_tenant_slug')) {
    function resolve_tenant_slug(): string {
        return vgrebor_single_tenant_slug();
    }
}

if (!function_exists('tenant_config')) {
    function tenant_config(): array {
        static $cache = [];

        $environment = vgrebor_current_environment();
        if (isset($cache[$environment])) {
            return $cache[$environment];
        }

        $slug = vgrebor_single_tenant_slug();
        $storeRawConfig = vgrebor_single_tenant_raw_config();
        $localRawConfig = vgrebor_local_runtime_raw_config();
        $database = vgrebor_is_local_environment()
            ? (is_array($localRawConfig['database'] ?? null) ? $localRawConfig['database'] : [])
            : (is_array($storeRawConfig['database'] ?? null) ? $storeRawConfig['database'] : []);
        $tenant = is_array($storeRawConfig['tenant'] ?? null) ? $storeRawConfig['tenant'] : [];
        $brand = is_array($storeRawConfig['brand'] ?? null) ? $storeRawConfig['brand'] : [];

        $envPrefix = strtoupper(str_replace('-', '_', $slug));
        $defaultDatabaseName = vgrebor_is_local_environment() ? 'vgrebor' : 'u680460687_reborxstore';

        $databaseHost = trim((string) (getenv('TVG_' . $envPrefix . '_DB_HOST') ?: getenv('TVG_DB_HOST') ?: ($database['host'] ?? 'localhost')));
        $databaseName = trim((string) (getenv('TVG_' . $envPrefix . '_DB_NAME') ?: getenv('TVG_DB_NAME') ?: ($database['name'] ?? $defaultDatabaseName)));
        $databaseUser = trim((string) (getenv('TVG_' . $envPrefix . '_DB_USER') ?: getenv('TVG_DB_USER') ?: ($database['user'] ?? 'root')));
        $databasePassword = (string) (getenv('TVG_' . $envPrefix . '_DB_PASSWORD') ?: getenv('TVG_DB_PASSWORD') ?: ($database['password'] ?? ''));
        $databaseCharset = trim((string) ($database['charset'] ?? 'utf8mb4'));

        $domains = $tenant['domains'] ?? [];
        if (is_string($domains) && $domains !== '') {
            $domains = [$domains];
        }

        $normalizedDomains = [];
        if (is_array($domains)) {
            foreach ($domains as $domain) {
                $normalized = tenant_normalize_host((string) $domain);
                if ($normalized !== '') {
                    $normalizedDomains[] = $normalized;
                }
            }
        }

        $cache[$environment] = [
            'tenant' => [
                'slug' => $slug,
                'host' => tenant_normalize_host(),
                'domains' => array_values(array_unique($normalizedDomains)),
            ],
            'brand' => [
                'name' => trim((string) ($brand['name'] ?? 'Reborxstore')),
            ],
            'database' => [
                'host' => $databaseHost !== '' ? $databaseHost : 'localhost',
                'name' => $databaseName !== '' ? $databaseName : $defaultDatabaseName,
                'user' => $databaseUser !== '' ? $databaseUser : 'root',
                'password' => $databasePassword,
                'charset' => $databaseCharset !== '' ? $databaseCharset : 'utf8mb4',
            ],
        ];

        return $cache[$environment];
    }
}

if (!function_exists('tenant_database_config')) {
    function tenant_database_config(): array {
        return tenant_config()['database'];
    }
}

if (!function_exists('tenant_public_prefix')) {
    function tenant_public_prefix(): string {
        // No tenant prefix in single-tenant mode; keep empty for cleaner URLs.
        return '';
    }
}

if (!function_exists('app_base_path')) {
    function app_base_path(): string {
        static $resolvedBasePath = null;

        if ($resolvedBasePath !== null) {
            return $resolvedBasePath;
        }

        $appRoot = realpath(dirname(__DIR__));
        $documentRoot = realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));

        if (is_string($appRoot) && $appRoot !== '' && is_string($documentRoot) && $documentRoot !== '') {
            $normalizedAppRoot = str_replace('\\', '/', $appRoot);
            $normalizedDocumentRoot = rtrim(str_replace('\\', '/', $documentRoot), '/');

            if ($normalizedAppRoot === $normalizedDocumentRoot) {
                $resolvedBasePath = '';
                return $resolvedBasePath;
            }

            if (str_starts_with($normalizedAppRoot, $normalizedDocumentRoot . '/')) {
                $relativePath = substr($normalizedAppRoot, strlen($normalizedDocumentRoot));
                $resolvedBasePath = rtrim(str_replace('\\', '/', $relativePath), '/');
                return $resolvedBasePath;
            }
        }

        $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/'));
        $scriptDirectory = str_replace('\\', '/', dirname($scriptName));
        if ($scriptDirectory === '/' || $scriptDirectory === '.') {
            $resolvedBasePath = '';
            return $resolvedBasePath;
        }

        if (preg_match('#^/(admin|api)(?:/|$)#', $scriptDirectory) === 1) {
            $resolvedBasePath = '';
            return $resolvedBasePath;
        }

        $resolvedBasePath = rtrim($scriptDirectory, '/');
        return $resolvedBasePath;
    }
}

if (!function_exists('app_path')) {
    function app_path(string $path = '/'): string {
        $basePath = app_base_path();
        $normalizedPath = '/' . ltrim($path, '/');
        if ($normalizedPath === '/index.php') {
            $normalizedPath = '/';
        }

        if ($basePath === '') {
            return $normalizedPath;
        }

        return rtrim($basePath, '/') . ($normalizedPath === '/' ? '/' : $normalizedPath);
    }
}

if (!function_exists('app_url')) {
    function app_url(string $path = '/'): string {
        $https = $_SERVER['HTTPS'] ?? '';
        $forwardedProto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
        $scheme = ($https === 'on' || $https === '1' || strtolower((string) $forwardedProto) === 'https') ? 'https' : 'http';
        return $scheme . '://' . tenant_normalize_host() . app_path($path);
    }
}

if (!function_exists('tenant_upload_absolute_dir')) {
    function tenant_upload_absolute_dir(string $bucket): string {
        $bucketName = trim(trim($bucket), '/\\');
        // Store uploads in a unified `uploads/` directory at project root.
        $uploadsRoot = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads';
        return $uploadsRoot
            . ($bucketName !== '' ? DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $bucketName) : '');
    }
}

if (!function_exists('tenant_upload_public_path')) {
    function tenant_upload_public_path(string $bucket, string $fileName, bool $leadingSlash = true): string {
        $bucketName = trim(trim($bucket), '/\\');
        $relativePath = 'uploads';
        if ($bucketName !== '') {
            $relativePath .= '/' . trim(str_replace('\\', '/', $bucketName), '/');
        }
        $relativePath .= '/' . ltrim(str_replace('\\', '/', $fileName), '/');
        return $leadingSlash ? '/' . $relativePath : $relativePath;
    }
}

if (!function_exists('tenant_resolve_public_path')) {
    function tenant_resolve_public_path(string $path): ?string {
        $candidate = trim($path);
        if ($candidate === '' || preg_match('#^https?://#i', $candidate) === 1) {
            return null;
        }

        $urlPath = parse_url($candidate, PHP_URL_PATH);
        if (is_string($urlPath) && $urlPath !== '') {
            $candidate = $urlPath;
        }

        $relativePath = ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $candidate), DIRECTORY_SEPARATOR);
        if ($relativePath === '') {
            return null;
        }

        $relativeCandidates = vgrebor_candidate_public_relative_paths(str_replace(DIRECTORY_SEPARATOR, '/', $relativePath));
        if ($relativeCandidates === []) {
            $relativeCandidates = [str_replace(DIRECTORY_SEPARATOR, '/', $relativePath)];
        }

        $fallbackPath = null;
        foreach ($relativeCandidates as $relativeCandidate) {
            $absolutePath = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeCandidate);
            if ($fallbackPath === null) {
                $fallbackPath = $absolutePath;
            }

            if (file_exists($absolutePath)) {
                return $absolutePath;
            }
        }

        return $fallbackPath;
    }
}

if (!function_exists('tenant_is_managed_path')) {
    function tenant_is_managed_path(string $path, ?string $bucket = null): bool {
        $normalizedPath = '/' . ltrim(str_replace('\\', '/', trim($path)), '/');
        // New unified uploads path
        $uploadsPrefix = '/uploads';
        if ($bucket !== null && trim($bucket) !== '') {
            $uploadsPrefix .= '/' . trim(str_replace('\\', '/', $bucket), '/');
        }
        if (str_starts_with($normalizedPath, $uploadsPrefix . '/')) {
            return true;
        }

        return false;
    }
}

if (!function_exists('tenant_session_name')) {
    function tenant_session_name(): string {
        $slug = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', resolve_tenant_slug()) ?? '');
        if ($slug === '') {
            $slug = strtoupper(substr(hash('sha256', tenant_normalize_host()), 0, 12));
        }

        return 'TVGSESSID_' . substr($slug, 0, 20);
    }
}

if (!function_exists('tenant_session_cookie_lifetime')) {
    function tenant_session_cookie_lifetime(): int {
        return 60 * 60 * 24 * 30;
    }
}

if (!function_exists('tenant_session_is_secure')) {
    function tenant_session_is_secure(): bool {
        $https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
        $forwardedProto = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));

        return $https === 'on' || $https === '1' || $forwardedProto === 'https';
    }
}

if (!function_exists('tenant_start_session')) {
    function tenant_start_session(): void {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $lifetime = tenant_session_cookie_lifetime();
        ini_set('session.gc_maxlifetime', (string) $lifetime);
        ini_set('session.cookie_lifetime', (string) $lifetime);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');

        session_name(tenant_session_name());
        session_set_cookie_params([
            'lifetime' => $lifetime,
            'path' => '/',
            'secure' => tenant_session_is_secure(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

if (!function_exists('tenant_forget_session_cookie')) {
    function tenant_forget_session_cookie(): void {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'] ?? '/',
            'domain' => $params['domain'] ?? '',
            'secure' => (bool) ($params['secure'] ?? false),
            'httponly' => (bool) ($params['httponly'] ?? true),
            'samesite' => $params['samesite'] ?? 'Lax',
        ]);
    }
}