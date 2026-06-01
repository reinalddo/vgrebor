<?php

require_once __DIR__ . '/store_config.php';

function recargas_api_decode_response_body(?string $body): ?array {
    $body = trim((string) $body);
    if ($body === '') {
        return null;
    }

    $data = json_decode($body, true);
    return is_array($data) ? $data : null;
}

function recargas_api_response_snippet(?string $body, int $limit = 240): string {
    $body = trim((string) $body);
    if ($body === '') {
        return '[empty body]';
    }

    $body = preg_replace('/\s+/u', ' ', $body) ?? $body;
    if (function_exists('mb_substr')) {
        return mb_substr($body, 0, $limit, 'UTF-8');
    }

    return substr($body, 0, $limit);
}

function recargas_api_invalid_json_exception(string $url, ?int $status, ?string $body): RuntimeException {
    $statusLabel = $status !== null && $status > 0 ? (string) $status : 'n/a';
    $snippet = recargas_api_response_snippet($body);
    error_log('TVG recargas invalid JSON response [' . $statusLabel . '] ' . $url . ' :: ' . $snippet);

    if (trim((string) $body) === '') {
        return new RuntimeException('La API de recargas devolvió una respuesta vacía o incompleta.');
    }

    return new RuntimeException('La API de recargas no devolvió un JSON válido.');
}

function recargas_api_error_message_from_response(?array $data, int $status): string {
    if (is_array($data)) {
        $candidates = [
            $data['mensaje'] ?? null,
            $data['message'] ?? null,
            $data['error'] ?? null,
            $data['detalle'] ?? null,
            $data['detail'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            $text = trim((string) $candidate);
            if ($text !== '') {
                return $text;
            }
        }

        if (isset($data['errors']) && is_array($data['errors'])) {
            $flatErrors = [];
            foreach ($data['errors'] as $key => $value) {
                if (is_array($value)) {
                    foreach ($value as $item) {
                        $itemText = trim((string) $item);
                        if ($itemText !== '') {
                            $flatErrors[] = $itemText;
                        }
                    }
                    continue;
                }

                $valueText = trim((string) $value);
                if ($valueText !== '') {
                    $label = is_string($key) ? trim($key) : '';
                    $flatErrors[] = $label !== '' ? ($label . ': ' . $valueText) : $valueText;
                }
            }

            if ($flatErrors) {
                return implode(' | ', $flatErrors);
            }
        }
    }

    return 'La API de recargas respondió con código HTTP ' . $status . '.';
}

function recargas_api_base_url(): string {
    return 'https://tiendagiftven.tech/api/v1';
}

function recargas_api_key(): string {
    return trim(store_config_get('recargas_api_key', ''));
}

function recargas_api_is_configured(): bool {
    return recargas_api_key() !== '';
}

function recargas_api_connect_timeout_seconds(): int {
    return 10;
}

function recargas_api_products_timeout_seconds(): int {
    return 30;
}

function recargas_api_purchase_timeout_seconds(): int {
    return 60;
}

function recargas_api_lookup_timeout_seconds(): int {
    return 35;
}

function recargas_api_http_get_json(string $url, array $headers = [], int $timeout = 20, bool $verifySsl = true): array {
    $body = null;
    $connectTimeout = min(recargas_api_connect_timeout_seconds(), max(1, $timeout));
    $status = null;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => $connectTimeout,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => $verifySsl,
            CURLOPT_SSL_VERIFYHOST => $verifySsl ? 2 : 0,
        ]);
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new RuntimeException('No se pudo consultar la API de recargas: ' . $error);
        }

        $body = $response;
    } else {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => $timeout,
                'ignore_errors' => true,
                'header' => implode("\r\n", $headers),
            ],
            'ssl' => [
                'verify_peer' => $verifySsl,
                'verify_peer_name' => $verifySsl,
            ],
        ]);
        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            throw new RuntimeException('No se pudo consultar la API de recargas.');
        }

        $body = $response;
    }

    $data = recargas_api_decode_response_body((string) $body);
    if (!is_array($data)) {
        throw recargas_api_invalid_json_exception($url, $status, (string) $body);
    }

    if (isset($status) && $status >= 400) {
        throw new RuntimeException(recargas_api_error_message_from_response($data, $status));
    }

    return $data;
}

function recargas_api_http_post_json(string $url, array $payload, array $headers = [], int $timeout = 25, bool $verifySsl = true): array {
    $body = null;
    $connectTimeout = min(recargas_api_connect_timeout_seconds(), max(1, $timeout));
    $status = null;
    $requestBody = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($requestBody)) {
        throw new RuntimeException('No se pudo serializar la solicitud JSON para la API de recargas.');
    }

    $httpHeaders = array_merge(['Content-Type: application/json'], $headers);

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => $connectTimeout,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $requestBody,
            CURLOPT_HTTPHEADER => $httpHeaders,
            CURLOPT_SSL_VERIFYPEER => $verifySsl,
            CURLOPT_SSL_VERIFYHOST => $verifySsl ? 2 : 0,
        ]);
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new RuntimeException('No se pudo consultar la API de recargas: ' . $error);
        }

        $body = $response;
    } else {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'timeout' => $timeout,
                'ignore_errors' => true,
                'header' => implode("\r\n", $httpHeaders),
                'content' => $requestBody,
            ],
            'ssl' => [
                'verify_peer' => $verifySsl,
                'verify_peer_name' => $verifySsl,
            ],
        ]);
        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            throw new RuntimeException('No se pudo consultar la API de recargas.');
        }

        $body = $response;
    }

    $data = recargas_api_decode_response_body((string) $body);
    if (!is_array($data)) {
        throw recargas_api_invalid_json_exception($url, $status, (string) $body);
    }

    if (isset($status) && $status >= 400) {
        throw new RuntimeException(recargas_api_error_message_from_response($data, $status));
    }

    return $data;
}

function recargas_api_fetch_products(): array {
    static $cachedProducts = null;

    if ($cachedProducts !== null) {
        return $cachedProducts;
    }

    $apiKey = recargas_api_key();
    if ($apiKey === '') {
        throw new RuntimeException('Configura primero la API KEY de recargas.');
    }

    try {
        $data = recargas_api_http_get_json(
            'https://tiendagiftven.tech/api/v1/productos',
            ['X-API-Key: ' . $apiKey],
            recargas_api_products_timeout_seconds(),
            true
        );
    } catch (Throwable $e) {
        $message = (string) $e->getMessage();
        $sslIssue = stripos($message, 'SSL certificate problem') !== false
            || stripos($message, 'unable to get local issuer certificate') !== false;

        if (!$sslIssue) {
            throw $e;
        }

        $data = recargas_api_http_get_json(
            'https://tiendagiftven.tech/api/v1/productos',
            ['X-API-Key: ' . $apiKey],
            recargas_api_products_timeout_seconds(),
            false
        );
    }

    $products = $data['productos'] ?? null;
    if (!is_array($products)) {
        throw new RuntimeException('La API de recargas no devolvió una lista válida de productos.');
    }

    $cachedProducts = $products;
    return $cachedProducts;
}

function recargas_api_fetch_categories(): array {
    $products = recargas_api_fetch_products();
    $categories = [];

    foreach ($products as $product) {
        if (!is_array($product)) {
            continue;
        }

        $category = trim((string) ($product['categoria'] ?? ''));
        if ($category === '') {
            continue;
        }

        $categories[$category] = $category;
    }

    natcasesort($categories);
    return array_values($categories);
}

function recargas_api_fetch_products_by_category(string $category): array {
    $normalizedCategory = mb_strtolower(trim($category), 'UTF-8');
    if ($normalizedCategory === '') {
        return [];
    }

    $matches = [];
    foreach (recargas_api_fetch_products() as $product) {
        if (!is_array($product)) {
            continue;
        }

        $productCategory = mb_strtolower(trim((string) ($product['categoria'] ?? '')), 'UTF-8');
        if ($productCategory !== $normalizedCategory) {
            continue;
        }

        $matches[] = $product;
    }

    usort($matches, static function (array $left, array $right): int {
        return strcmp((string) ($left['nombre'] ?? ''), (string) ($right['nombre'] ?? ''));
    });

    return $matches;
}

function recargas_api_fetch_product_by_id(int $productId): ?array {
    if ($productId <= 0) {
        return null;
    }

    foreach (recargas_api_fetch_products() as $product) {
        if (!is_array($product)) {
            continue;
        }

        if ((int) ($product['id'] ?? 0) === $productId) {
            return $product;
        }
    }

    return null;
}

function recargas_api_canonical_field_name(string $rawName, string $rawDescription = ''): string {
    $name = strtolower(trim($rawName));
    $name = preg_replace('/[^a-z0-9_]+/u', '', $name) ?? '';
    if ($name === '') {
        return '';
    }

    if (!in_array($name, ['input1', 'input2', 'input3', 'input4'], true)) {
        return $name;
    }

    $description = mb_strtolower(trim($rawDescription), 'UTF-8');
    $description = preg_replace('/\s+/u', ' ', $description) ?? $description;

    if (
        str_contains($description, 'user id')
        || str_contains($description, 'player id')
        || str_contains($description, 'id pengguna')
        || str_contains($description, 'pengguna id')
        || str_contains($description, 'id del jugador')
        || str_contains($description, 'id de jugador')
        || str_contains($description, 'id de usuario')
    ) {
        return 'id_juego';
    }

    if (
        str_contains($description, 'zone id')
        || str_contains($description, 'id de zona')
        || $description === 'zona'
        || $description === 'zone'
    ) {
        return 'zone_id';
    }

    if (
        str_contains($description, 'server id')
        || str_contains($description, 'id de servidor')
        || $description === 'servidor'
        || $description === 'server'
    ) {
        return 'server_id';
    }

    if (str_contains($description, 'correo') || str_contains($description, 'email')) {
        return 'email';
    }

    if (str_contains($description, 'telefono') || str_contains($description, 'phone')) {
        return 'telefono';
    }

    return $name;
}

function recargas_api_extract_required_field_meta($field): ?array {
    if (is_array($field)) {
        $rawName = trim((string) ($field['nombre'] ?? $field['name'] ?? ''));
        $rawDescription = trim((string) ($field['descripcion'] ?? $field['label'] ?? ''));
        $rawType = strtolower(trim((string) ($field['tipo'] ?? $field['type'] ?? 'string')));
        $options = $field['opciones'] ?? [];
    } else {
        $rawName = trim((string) $field);
        $rawDescription = '';
        $rawType = 'string';
        $options = [];
    }

    $providerName = strtolower(trim($rawName));
    $providerName = preg_replace('/[^a-z0-9_]+/u', '', $providerName) ?? '';
    $name = recargas_api_canonical_field_name($rawName, $rawDescription);
    if ($name === '') {
        return null;
    }

    if (!is_array($options)) {
        $options = [];
    }

    return [
        'name' => $name,
        'provider_name' => $providerName !== '' ? $providerName : $name,
        'description' => $rawDescription,
        'type' => $rawType !== '' ? $rawType : 'string',
        'options' => $options,
    ];
}

function recargas_api_normalize_required_fields($fields): array {
    if (!is_array($fields)) {
        return [];
    }

    $normalized = [];
    foreach ($fields as $field) {
        $fieldMeta = recargas_api_extract_required_field_meta($field);
        if ($fieldMeta === null || isset($normalized[$fieldMeta['name']])) {
            continue;
        }

        $normalized[$fieldMeta['name']] = $fieldMeta;
    }

    return array_values($normalized);
}

function recargas_api_field_label(string $fieldName): string {
    $normalized = strtolower(trim($fieldName));

    return match ($normalized) {
        'id_juego', 'player_id' => 'ID del jugador',
        'user_id' => 'ID del usuario',
        'input1' => 'Dato principal',
        'input2' => 'Dato adicional',
        'zona', 'zone' => 'Zona',
        'zone_id' => 'ID de zona',
        'server' => 'Servidor',
        'server_id' => 'ID de servidor',
        'gamepoint' => 'Game Point',
        'email' => 'Correo',
        'telefono', 'phone' => 'Telefono',
        default => ucwords(str_replace('_', ' ', $normalized !== '' ? $normalized : 'dato')),
    };
}

function recargas_api_field_placeholder(string $fieldName): string {
    $normalized = strtolower(trim($fieldName));

    return match ($normalized) {
        'id_juego', 'player_id' => 'Ingresa el ID del jugador',
        'user_id' => 'Ingresa el ID del usuario',
        'input1' => 'Ingresa el dato principal',
        'input2' => 'Ingresa el dato adicional',
        'zona', 'zone' => 'Ingresa la zona',
        'zone_id' => 'Ingresa el ID de zona',
        'server' => 'Ingresa el servidor',
        'server_id' => 'Ingresa el ID de servidor',
        'gamepoint' => 'Ingresa el Game Point',
        'email' => 'Ingresa el correo',
        'telefono', 'phone' => 'Ingresa el telefono',
        default => 'Ingresa ' . strtolower(recargas_api_field_label($normalized)),
    };
}

function recargas_api_field_input_mode(string $fieldName): string {
    $normalized = strtolower(trim($fieldName));
    $numericFields = ['id_juego', 'player_id', 'user_id', 'zone_id', 'server_id', 'telefono', 'phone'];

    return in_array($normalized, $numericFields, true) ? 'numeric' : 'text';
}

function recargas_api_field_max_length(string $fieldName): int {
    $normalized = strtolower(trim($fieldName));

    return match ($normalized) {
        'telefono', 'phone' => 40,
        default => 180,
    };
}

function recargas_api_normalize_field_options($options): array {
    if (!is_array($options)) {
        return [];
    }

    $normalized = [];
    foreach ($options as $key => $option) {
        if (is_array($option)) {
            $value = trim((string) ($option['value'] ?? $option['id'] ?? $option['codigo'] ?? ''));
            $label = trim((string) ($option['label'] ?? $option['nombre'] ?? $option['descripcion'] ?? $value));
        } elseif (is_string($key) && !is_numeric($key)) {
            $value = trim($key);
            $label = trim((string) $option);
        } else {
            $value = trim((string) $option);
            $label = $value;
        }

        if ($value === '') {
            continue;
        }

        $normalized[] = [
            'value' => $value,
            'label' => $label !== '' ? $label : $value,
        ];
    }

    return $normalized;
}

function recargas_api_describe_required_fields($fields): array {
    $described = [];

    foreach (recargas_api_normalize_required_fields($fields['campos_requeridos'] ?? []) as $fieldMeta) {
        $fieldName = (string) ($fieldMeta['name'] ?? '');
        if ($fieldName === '') {
            continue;
        }

        $description = trim((string) ($fieldMeta['description'] ?? ''));
        $inputMode = recargas_api_field_input_mode($fieldName);
        if (($fieldMeta['type'] ?? '') === 'number') {
            $inputMode = 'numeric';
        }

        $described[] = [
            'name' => $fieldName,
            'label' => $description !== '' ? $description : recargas_api_field_label($fieldName),
            'placeholder' => $description !== '' ? ('Ingresa ' . $description) : recargas_api_field_placeholder($fieldName),
            'inputMode' => $inputMode,
            'maxLength' => recargas_api_field_max_length($fieldName),
            'type' => (string) ($fieldMeta['type'] ?? 'string'),
            'description' => $description,
            'providerName' => (string) ($fieldMeta['provider_name'] ?? $fieldName),
            'options' => recargas_api_normalize_field_options($fieldMeta['options'] ?? []),
        ];
    }

    return $described;
}

function recargas_api_post_json_with_fallback(string $url, array $payload, array $headers = [], int $timeout = 25): array {
    try {
        return recargas_api_http_post_json($url, $payload, $headers, $timeout, true);
    } catch (Throwable $e) {
        $message = (string) $e->getMessage();
        $sslIssue = stripos($message, 'SSL certificate problem') !== false
            || stripos($message, 'unable to get local issuer certificate') !== false;

        if (!$sslIssue) {
            throw $e;
        }

        return recargas_api_http_post_json($url, $payload, $headers, $timeout, false);
    }
}

function recargas_api_response_has_delivered_codes($value): bool {
    if (is_array($value)) {
        foreach ($value as $item) {
            if (recargas_api_response_has_delivered_codes($item)) {
                return true;
            }
        }

        return false;
    }

    return trim((string) $value) !== '';
}

function recargas_api_purchase_is_completed(array $response): bool {
    if (!recargas_api_purchase_is_accepted($response)) {
        return false;
    }

    $status = strtolower(trim((string) ($response['estado'] ?? '')));
    if (in_array($status, ['completado', 'completed', 'success', 'enviado'], true)) {
        return true;
    }

    foreach (['codigo_entregado', 'codigo', 'pin', 'serial', 'voucher'] as $key) {
        $value = trim((string) ($response[$key] ?? ''));
        if ($value !== '') {
            return true;
        }
    }

    foreach (['codigos', 'codigos_entregados'] as $key) {
        if (array_key_exists($key, $response) && recargas_api_response_has_delivered_codes($response[$key])) {
            return true;
        }
    }

    return false;
}

function recargas_api_purchase_is_accepted(array $response): bool {
    if (!empty($response['ok'])) {
        return true;
    }

    $status = strtolower(trim((string) ($response['estado'] ?? '')));
    if (in_array($status, ['procesando', 'processing', 'pending', 'accepted', 'en_proceso', 'in_process'], true)) {
        return true;
    }

    $reference = trim((string) ($response['referencia'] ?? $response['pedido_id'] ?? ''));
    $message = mb_strtolower(trim((string) ($response['mensaje'] ?? $response['message'] ?? $response['error'] ?? '')), 'UTF-8');

    if ($reference !== '') {
        return true;
    }

    return str_contains($message, 'pedido enviado')
        || str_contains($message, 'compra aceptada')
        || str_contains($message, 'en proceso')
        || str_contains($message, 'se confirmara automaticamente')
        || str_contains($message, 'se confirmará automáticamente');
}

function recargas_api_product_label(array $product): string {
    $name = trim((string) ($product['nombre'] ?? 'Producto'));
    $id = (int) ($product['id'] ?? 0);
    $price = isset($product['precio']) ? number_format((float) $product['precio'], 4, '.', '') : '0.0000';
    $manual = !empty($product['procesamiento_manual']) ? 'Manual' : 'Automatico';

    return $name . ' [ID ' . $id . '] - $' . $price . ' - ' . $manual;
}

function recargas_api_fetch_order_detail(string $providerOrderId): array {
    $providerOrderId = trim($providerOrderId);
    if ($providerOrderId === '') {
        throw new RuntimeException('Debes indicar el pedido externo para consultar el detalle.');
    }

    $apiKey = recargas_api_key();
    if ($apiKey === '') {
        throw new RuntimeException('Configura primero la API KEY de recargas.');
    }

    try {
        return recargas_api_http_get_json(
            recargas_api_base_url() . '/pedido/' . rawurlencode($providerOrderId),
            ['X-API-Key: ' . $apiKey],
            recargas_api_lookup_timeout_seconds(),
            true
        );
    } catch (Throwable $e) {
        $message = (string) $e->getMessage();
        $sslIssue = stripos($message, 'SSL certificate problem') !== false
            || stripos($message, 'unable to get local issuer certificate') !== false;

        if (!$sslIssue) {
            throw $e;
        }

        return recargas_api_http_get_json(
            recargas_api_base_url() . '/pedido/' . rawurlencode($providerOrderId),
            ['X-API-Key: ' . $apiKey],
            recargas_api_lookup_timeout_seconds(),
            false
        );
    }
}

function recargas_api_fetch_recent_orders(): array {
    $apiKey = recargas_api_key();
    if ($apiKey === '') {
        throw new RuntimeException('Configura primero la API KEY de recargas.');
    }

    $response = recargas_api_http_get_json(
        recargas_api_base_url() . '/pedidos',
        ['X-API-Key: ' . $apiKey],
        recargas_api_lookup_timeout_seconds(),
        true
    );

    $items = $response['pedidos'] ?? $response['data'] ?? $response;
    return is_array($items) ? array_values(array_filter($items, 'is_array')) : [];
}

function recargas_api_fetch_transactions(): array {
    $apiKey = recargas_api_key();
    if ($apiKey === '') {
        throw new RuntimeException('Configura primero la API KEY de recargas.');
    }

    $response = recargas_api_http_get_json(
        recargas_api_base_url() . '/transacciones',
        ['X-API-Key: ' . $apiKey],
        recargas_api_lookup_timeout_seconds(),
        true
    );

    $items = $response['transacciones'] ?? $response['data'] ?? $response;
    return is_array($items) ? array_values(array_filter($items, 'is_array')) : [];
}

function recargas_api_get_webhook(): array {
    $apiKey = recargas_api_key();
    if ($apiKey === '') {
        throw new RuntimeException('Configura primero la API KEY de recargas.');
    }

    return recargas_api_http_get_json(
        recargas_api_base_url() . '/webhook',
        ['X-API-Key: ' . $apiKey],
        recargas_api_lookup_timeout_seconds(),
        true
    );
}

function recargas_api_register_webhook(string $url): array {
    $apiKey = recargas_api_key();
    if ($apiKey === '') {
        throw new RuntimeException('Configura primero la API KEY de recargas.');
    }

    $normalizedUrl = trim($url);
    if ($normalizedUrl === '') {
        throw new RuntimeException('Debes indicar una URL válida para registrar el webhook.');
    }

    return recargas_api_post_json_with_fallback(
        recargas_api_base_url() . '/webhook',
        ['url' => $normalizedUrl],
        ['X-API-Key: ' . $apiKey],
        recargas_api_lookup_timeout_seconds()
    );
}
