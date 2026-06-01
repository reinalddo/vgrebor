<?php

require_once __DIR__ . '/store_config.php';

if (!function_exists('payment_difference_feature_enabled')) {
    function payment_difference_feature_enabled(): bool {
        return trim((string) store_config_get('diferencia_pago', '0')) === '1';
    }
}

if (!function_exists('payment_difference_normalize_amount')) {
    function payment_difference_normalize_amount($value): float {
        $amount = is_numeric($value) ? (float) $value : 0.0;
        if ($amount < 0) {
            $amount = 0.0;
        }

        return round($amount, 2);
    }
}

if (!function_exists('payment_difference_session_key')) {
    function payment_difference_session_key(): string {
        $tenantSlug = function_exists('resolve_tenant_slug') ? trim((string) resolve_tenant_slug()) : '';
        return $tenantSlug !== ''
            ? 'payment_difference_credit:' . $tenantSlug
            : 'payment_difference_credit';
    }
}

if (!function_exists('payment_difference_clear_credit')) {
    function payment_difference_clear_credit(): void {
        unset($_SESSION[payment_difference_session_key()]);
    }
}

if (!function_exists('payment_difference_get_credit')) {
    function payment_difference_get_credit(): ?array {
        if (!payment_difference_feature_enabled()) {
            payment_difference_clear_credit();
            return null;
        }

        $raw = $_SESSION[payment_difference_session_key()] ?? null;
        if (!is_array($raw)) {
            return null;
        }

        $availableAmount = payment_difference_normalize_amount($raw['available_amount'] ?? 0);
        $sourceOrderId = (int) ($raw['source_order_id'] ?? 0);
        $currency = strtoupper(trim((string) ($raw['currency'] ?? 'VES')));
        $expiresAtTs = isset($raw['expires_at_ts']) ? (int) $raw['expires_at_ts'] : 0;
        $createdAtTs = isset($raw['created_at_ts']) ? (int) $raw['created_at_ts'] : time();

        if ($availableAmount <= 0 || $sourceOrderId <= 0 || $expiresAtTs <= time()) {
            payment_difference_clear_credit();
            return null;
        }

        return [
            'source_order_id' => $sourceOrderId,
            'available_amount' => $availableAmount,
            'original_amount' => payment_difference_normalize_amount($raw['original_amount'] ?? $availableAmount),
            'currency' => $currency !== '' ? $currency : 'VES',
            'created_at_ts' => $createdAtTs,
            'expires_at_ts' => $expiresAtTs,
            'remaining_seconds' => max(0, $expiresAtTs - time()),
            'message' => trim((string) ($raw['message'] ?? '')),
        ];
    }
}

if (!function_exists('payment_difference_activate_credit')) {
    function payment_difference_activate_credit(int $sourceOrderId, float $amount, string $currency = 'VES', int $ttlSeconds = 1800, string $message = ''): array {
        $normalizedAmount = payment_difference_normalize_amount($amount);
        if ($sourceOrderId <= 0 || $normalizedAmount <= 0) {
            throw new InvalidArgumentException('Saldo a favor inválido.');
        }

        $ttlSeconds = max(60, $ttlSeconds);
        $now = time();
        $payload = [
            'source_order_id' => $sourceOrderId,
            'available_amount' => $normalizedAmount,
            'original_amount' => $normalizedAmount,
            'currency' => strtoupper(trim($currency)) !== '' ? strtoupper(trim($currency)) : 'VES',
            'created_at_ts' => $now,
            'expires_at_ts' => $now + $ttlSeconds,
            'message' => trim($message),
        ];

        $_SESSION[payment_difference_session_key()] = $payload;

        return payment_difference_get_credit() ?? $payload;
    }
}

if (!function_exists('payment_difference_consume_credit')) {
    function payment_difference_consume_credit(): void {
        payment_difference_clear_credit();
    }
}
