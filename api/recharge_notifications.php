<?php

require_once __DIR__ . '/../includes/tenant.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/recharge_notifications.php';

header('Content-Type: application/json; charset=utf-8');

$cursor = isset($_GET['cursor']) ? max(0, (int) $_GET['cursor']) : null;
try {
    $mysqli = ensure_mysqli_connection($mysqli ?? null);
    $tenantSlug = recharge_notifications_current_tenant_slug();
    $enabled = recharge_notifications_is_enabled();
    $logoPath = recharge_notifications_logo_path();

    if (!$enabled) {
        echo json_encode([
            'ok' => true,
            'enabled' => false,
            'cursor' => recharge_notifications_current_cursor($mysqli, $tenantSlug),
            'logo_path' => $logoPath,
            'notifications' => [],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($cursor === null) {
        $currentCursor = recharge_notifications_current_cursor($mysqli, $tenantSlug);
        echo json_encode([
            'ok' => true,
            'enabled' => true,
            'cursor' => $currentCursor,
            'logo_path' => $logoPath,
            'notifications' => recharge_notifications_fetch_recent($mysqli, $tenantSlug, 3),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $notifications = recharge_notifications_fetch_since($mysqli, $cursor, $tenantSlug, 20);
    $nextCursor = $cursor;
    if ($notifications !== []) {
        $lastNotification = end($notifications);
        $nextCursor = (int) ($lastNotification['id'] ?? $cursor);
    } else {
        $nextCursor = max($cursor, recharge_notifications_current_cursor($mysqli, $tenantSlug));
    }

    echo json_encode([
        'ok' => true,
        'enabled' => true,
        'cursor' => $nextCursor,
        'logo_path' => $logoPath,
        'notifications' => $notifications,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
} catch (Throwable $exception) {
    error_log('TVG recharge notifications API error: ' . $exception->getMessage());
    http_response_code(200);
    echo json_encode([
        'ok' => false,
        'enabled' => false,
        'cursor' => $cursor ?? 0,
        'logo_path' => '',
        'notifications' => [],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}