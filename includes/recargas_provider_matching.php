<?php
// Lógica de comparación para encontrar, en las listas de pedidos/transacciones
// recientes de TiendaGiftVen, cuál corresponde a un pedido local cuando no se
// guardó el ID que el proveedor le asignó (típicamente porque la compra
// original truncó por timeout de red antes de recibir respuesta). Vive en
// includes/ (no en api/pedidos.php) para poder reutilizarse también desde la
// sincronización oportunista de pedidos "confirmados por timeout"
// (recargas_api_reverify_timeout_assumed_orders, includes/recargas_api.php),
// sin depender del script de la API de pedidos. Relocada desde
// api/pedidos.php (misma lógica, sin cambios de comportamiento) — las
// funciones que quedaron allá (sync_local_order_with_provider_detail,
// try_recover_uncertain_provider_purchase, etc.) siguen funcionando igual
// porque las recogen de aquí vía el require_once ya existente de
// includes/recargas_api.php.

if (!function_exists('provider_status_to_local_status')) {
    function provider_status_to_local_status(string $providerStatus): ?string {
        $normalized = strtolower(trim($providerStatus));

        return match ($normalized) {
            'completado', 'completed', 'success', 'aprobado' => 'enviado',
            'procesando', 'processing', 'pending' => 'pagado',
            'cancelado', 'cancelled', 'canceled' => 'cancelado',
            default => null,
        };
    }
}

if (!function_exists('recargas_api_extract_provider_order_id')) {
    function recargas_api_extract_provider_order_id(array $response): string {
        $value = trim((string) ($response['id'] ?? $response['pedido_id'] ?? $response['referencia'] ?? ''));
        return substr($value, 0, 120);
    }
}

if (!function_exists('order_provider_request_payload')) {
    function order_provider_request_payload(array $order): array {
        $payload = json_decode((string) ($order['ff_api_payload'] ?? ''), true);
        if (!is_array($payload)) {
            return [];
        }

        $requestPayload = $payload['request_payload'] ?? null;
        return is_array($requestPayload) ? $requestPayload : [];
    }
}

if (!function_exists('provider_normalize_match_value')) {
    function provider_normalize_match_value($value): string {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        $normalized = trim((string) $value);
        if ($normalized === '') {
            return '';
        }

        $normalized = mb_strtolower($normalized, 'UTF-8');
        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;
        return trim($normalized);
    }
}

if (!function_exists('provider_collect_match_values')) {
    function provider_collect_match_values($value, int $depth = 0): array {
        if ($depth > 4) {
            return [];
        }

        $values = [];

        if (is_array($value)) {
            foreach ($value as $item) {
                foreach (provider_collect_match_values($item, $depth + 1) as $candidate => $enabled) {
                    if ($enabled) {
                        $values[$candidate] = true;
                    }
                }
            }
            return $values;
        }

        if (is_object($value)) {
            return provider_collect_match_values((array) $value, $depth + 1);
        }

        $normalized = provider_normalize_match_value($value);
        if ($normalized !== '') {
            $values[$normalized] = true;
        }

        return $values;
    }
}

if (!function_exists('provider_expected_match_values')) {
    function provider_expected_match_values(array $requestPayload): array {
        $expected = [];

        foreach ($requestPayload as $key => $value) {
            if (in_array((string) $key, ['producto_id', 'request_payload', 'exception'], true)) {
                continue;
            }

            foreach (provider_collect_match_values($value) as $candidate => $enabled) {
                if ($enabled) {
                    $expected[$candidate] = true;
                }
            }
        }

        return $expected;
    }
}

if (!function_exists('provider_candidate_product_id')) {
    function provider_candidate_product_id(array $providerCandidate): int {
        foreach (['producto_id', 'product_id', 'id_producto'] as $key) {
            if (isset($providerCandidate[$key]) && is_numeric($providerCandidate[$key])) {
                return (int) $providerCandidate[$key];
            }
        }

        return 0;
    }
}

if (!function_exists('provider_candidate_timestamp')) {
    function provider_candidate_timestamp(array $providerCandidate): ?int {
        foreach (['fecha', 'fecha_creacion', 'creado_en', 'created_at', 'updated_at', 'fecha_actualizacion'] as $key) {
            $raw = trim((string) ($providerCandidate[$key] ?? ''));
            if ($raw === '') {
                continue;
            }

            $timestamp = strtotime($raw);
            if ($timestamp !== false) {
                return $timestamp;
            }
        }

        return null;
    }
}

if (!function_exists('provider_candidate_score_for_order')) {
    function provider_candidate_score_for_order(array $order, array $providerCandidate): int {
        $requestPayload = order_provider_request_payload($order);
        if (!$requestPayload) {
            return 0;
        }

        $expectedValues = provider_expected_match_values($requestPayload);
        if (!$expectedValues) {
            return 0;
        }

        $providerValues = provider_collect_match_values($providerCandidate);
        if (!$providerValues) {
            return 0;
        }

        $expectedProductId = isset($requestPayload['producto_id']) && is_numeric($requestPayload['producto_id'])
            ? (int) $requestPayload['producto_id']
            : (int) ($order['paquete_api'] ?? 0);
        $providerProductId = provider_candidate_product_id($providerCandidate);
        if ($expectedProductId > 0 && $providerProductId > 0 && $expectedProductId !== $providerProductId) {
            return 0;
        }

        $matchedValues = 0;
        foreach (array_keys($expectedValues) as $expectedValue) {
            if (isset($providerValues[$expectedValue])) {
                $matchedValues++;
            }
        }

        if ($matchedValues === 0) {
            return 0;
        }

        $score = $matchedValues * 10;
        if ($expectedProductId > 0 && $providerProductId === $expectedProductId) {
            $score += 20;
        }

        $orderCreatedTs = isset($order['creado_en_ts']) ? (int) $order['creado_en_ts'] : 0;
        $providerTimestamp = provider_candidate_timestamp($providerCandidate);
        if ($orderCreatedTs > 0 && $providerTimestamp !== null) {
            $timeDiff = abs($providerTimestamp - $orderCreatedTs);
            if ($timeDiff <= 1800) {
                $score += 5;
            } elseif ($timeDiff > 86400) {
                $score -= 20;
            }
        }

        if (recargas_api_extract_provider_order_id($providerCandidate) !== '') {
            $score += 3;
        }

        return $score;
    }
}

if (!function_exists('find_provider_candidate_for_local_order')) {
    function find_provider_candidate_for_local_order(array $order): ?array {
        $bestCandidate = null;
        $bestScore = 0;
        $requestPayload = order_provider_request_payload($order);
        if (!$requestPayload) {
            return null;
        }

        $expectedValueCount = count(provider_expected_match_values($requestPayload));
        $minimumScore = $expectedValueCount <= 1 ? 10 : 20;
        $sources = [
            'orders' => 'recargas_api_fetch_recent_orders',
            'transactions' => 'recargas_api_fetch_transactions',
        ];
        $lastError = null;

        foreach ($sources as $sourceCallback) {
            try {
                $items = $sourceCallback();
            } catch (Throwable $e) {
                $lastError = $e;
                continue;
            }

            foreach ($items as $providerCandidate) {
                if (!is_array($providerCandidate)) {
                    continue;
                }

                $score = provider_candidate_score_for_order($order, $providerCandidate);
                if ($score < $minimumScore || $score <= $bestScore) {
                    continue;
                }

                $bestCandidate = $providerCandidate;
                $bestScore = $score;
            }
        }

        if (is_array($bestCandidate)) {
            return $bestCandidate;
        }

        if ($lastError !== null) {
            throw $lastError;
        }

        return null;
    }
}
