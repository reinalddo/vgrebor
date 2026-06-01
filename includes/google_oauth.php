<?php

require_once __DIR__ . '/tenant.php';
require_once __DIR__ . '/store_config.php';

function google_oauth_base_url(): string {
    $https = $_SERVER['HTTPS'] ?? '';
    $forwardedProto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
    $scheme = ($https === 'on' || $https === '1' || strtolower((string) $forwardedProto) === 'https') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    if ($scriptDir === '/' || $scriptDir === '.') {
        $scriptDir = '';
    }

    return $scheme . '://' . $host . $scriptDir;
}

function google_oauth_callback_url(): string {
    return google_oauth_base_url() . '/google-callback.php';
}

function google_oauth_login_url(): string {
    return google_oauth_base_url() . '/google-login.php';
}

function google_oauth_home_url(): string {
    return google_oauth_base_url() . '/';
}

function google_oauth_admin_dashboard_url(): string {
    return google_oauth_base_url() . '/admin/dashboard';
}

function google_oauth_is_configured(): bool {
    return trim(store_config_get('google_client_id', '')) !== '' && trim(store_config_get('google_client_secret', '')) !== '';
}

function google_oauth_generate_state(): string {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        tenant_start_session();
    }

    $state = bin2hex(random_bytes(16));
    $_SESSION['google_oauth_state'] = $state;
    return $state;
}

function google_oauth_validate_state(?string $state): bool {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        tenant_start_session();
    }

    $expected = (string) ($_SESSION['google_oauth_state'] ?? '');
    unset($_SESSION['google_oauth_state']);

    return $expected !== '' && $state !== null && hash_equals($expected, $state);
}

function google_oauth_http_post(string $url, array $payload): array {
    $body = http_build_query($payload);
    $headers = [
        'Content-Type: application/x-www-form-urlencoded',
        'Accept: application/json',
    ];

    return google_oauth_http_request($url, 'POST', $headers, $body);
}

function google_oauth_http_get(string $url, array $headers = []): array {
    return google_oauth_http_request($url, 'GET', $headers, null);
}

function google_oauth_http_request(string $url, string $method, array $headers, ?string $body): array {
    $normalizedMethod = strtoupper($method);

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $normalizedMethod);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $responseBody = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($responseBody === false) {
            throw new RuntimeException('No se pudo conectar con Google. ' . $error);
        }

        return ['status' => $httpCode, 'body' => $responseBody];
    }

    $context = stream_context_create([
        'http' => [
            'method' => $normalizedMethod,
            'header' => implode("\r\n", $headers),
            'content' => $body ?? '',
            'timeout' => 20,
            'ignore_errors' => true,
        ],
    ]);

    $responseBody = @file_get_contents($url, false, $context);
    if ($responseBody === false) {
        throw new RuntimeException('No se pudo conectar con Google.');
    }

    $status = 0;
    foreach ($http_response_header ?? [] as $headerLine) {
        if (preg_match('/^HTTP\/\S+\s+(\d{3})\b/', $headerLine, $matches) === 1) {
            $status = (int) $matches[1];
            break;
        }
    }

    return ['status' => $status, 'body' => $responseBody];
}
