<?php

require_once __DIR__ . '/store_config.php';

function player_verification_normalize_text(string $value): string {
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if (is_string($converted) && $converted !== '') {
            $value = $converted;
        }
    }

    $value = strtolower($value);
    $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? $value;

    return trim($value);
}

function player_verification_definition_for_game(array $game): ?array {
    $name = player_verification_normalize_text((string) ($game['nombre'] ?? ''));
    $slug = player_verification_normalize_text((string) ($game['slug'] ?? ''));
    $haystack = trim($name . ' ' . $slug);

    if ($haystack === '') {
        return null;
    }

    if (
        strpos($haystack, 'mobile legends') !== false
        || strpos($haystack, 'mobile legend') !== false
        || strpos($haystack, 'mlbb') !== false
    ) {
        return [
            'key' => 'mobile_legends',
            'button_label' => 'Verificar nombre del jugador',
            'default_fields' => [
                [
                    'name' => 'input1',
                    'label' => 'ID del jugador',
                    'placeholder' => 'Ingresa ID del Jugador',
                    'inputMode' => 'numeric',
                    'maxLength' => 32,
                ],
                [
                    'name' => 'input2',
                    'label' => 'Zona ID',
                    'placeholder' => 'Ingresa Zona ID',
                    'inputMode' => 'numeric',
                    'maxLength' => 20,
                ],
            ],
        ];
    }

    if (strpos($haystack, 'blood strike') !== false) {
        return [
            'key' => 'blood_strike',
            'button_label' => 'Verificar nombre del jugador',
            'default_fields' => [
                [
                    'name' => 'input1',
                    'label' => 'ID del jugador',
                    'placeholder' => 'Ingresa ID del Jugador',
                    'inputMode' => 'numeric',
                    'maxLength' => 32,
                ],
            ],
        ];
    }

    if (strpos($haystack, 'free fire') !== false && strpos($haystack, 'indonesia') !== false) {
        return [
            'key' => 'free_fire_indonesia',
            'button_label' => 'Verificar nombre del jugador',
            'default_fields' => [
                [
                    'name' => 'input1',
                    'label' => 'ID del jugador',
                    'placeholder' => 'Ingresa ID del Jugador',
                    'inputMode' => 'numeric',
                    'maxLength' => 32,
                ],
            ],
        ];
    }

    if (strpos($haystack, 'free fire') !== false && strpos($haystack, 'pines') === false && strpos($haystack, 'hype') === false) {
        return [
            'key' => 'free_fire_latam',
            'button_label' => 'Verificar nombre del jugador',
            'default_fields' => [
                [
                    'name' => 'input1',
                    'label' => 'ID del jugador',
                    'placeholder' => 'Ingresa ID del Jugador',
                    'inputMode' => 'numeric',
                    'maxLength' => 32,
                ],
            ],
        ];
    }

    return null;
}

function player_verification_is_enabled(): bool {
    return store_config_get('verificacion_nombre_api', '0') === '1';
}

function player_verification_frontend_config(array $game): ?array {
    $definition = player_verification_definition_for_game($game);
    if (!$definition) {
        return null;
    }

    return [
        'enabled' => true,
        'gameKey' => $definition['key'],
        'buttonLabel' => $definition['button_label'],
        'defaultFields' => $definition['default_fields'],
        'requiresZone' => $definition['key'] === 'mobile_legends',
    ];
}

function player_verification_http_get(string $url, array $headers = [], int $timeout = 20, bool $verifySsl = true): array {
    $body = '';
    $status = 0;
    $connectTimeout = max(1, min(10, $timeout));

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
            throw new RuntimeException('No se pudo consultar el servicio de verificación: ' . $error);
        }

        $body = (string) $response;
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
            throw new RuntimeException('No se pudo consultar el servicio de verificación.');
        }

        $body = (string) $response;
        if (isset($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $headerLine) {
                if (preg_match('/^HTTP\/\S+\s+(\d+)/i', (string) $headerLine, $matches)) {
                    $status = (int) ($matches[1] ?? 0);
                }
            }
        }
    }

    return [
        'status' => $status,
        'body' => $body,
    ];
}

function player_verification_decode_json(string $body): ?array {
    $decoded = json_decode($body, true);
    return is_array($decoded) ? $decoded : null;
}

function player_verification_result(bool $ok, string $status, string $message, array $extra = []): array {
    return array_merge([
        'ok' => $ok,
        'status' => $status,
        'message' => $message,
        'http_status' => $ok ? 200 : ($status === 'unavailable' ? 502 : 200),
    ], $extra);
}

function player_verification_extract_value_by_path(array $data, array $path): string {
    $value = $data;
    foreach ($path as $segment) {
        if (!is_array($value) || !array_key_exists($segment, $value)) {
            return '';
        }
        $value = $value[$segment];
    }

    return is_scalar($value) ? trim((string) $value) : '';
}

function player_verification_extract_zone_value(array $playerFields): string {
    $normalizedCandidates = ['input2', 'zoneid', 'zone_id', 'zone', 'serverid', 'server_id', 'server'];
    $fallbackValues = [];

    foreach ($playerFields as $fieldName => $fieldValue) {
        $value = trim((string) $fieldValue);
        if ($value === '') {
            continue;
        }

        $normalizedFieldName = strtolower(preg_replace('/[^a-z0-9]+/', '', (string) $fieldName) ?? '');
        if (in_array($normalizedFieldName, $normalizedCandidates, true)) {
            return $value;
        }

        $fallbackValues[] = $value;
    }

    return $fallbackValues[0] ?? '';
}

function player_verification_verify(array $game, string $userIdentifier, array $playerFields = []): array {
    $definition = player_verification_definition_for_game($game);
    if (!$definition) {
        return player_verification_result(false, 'unsupported', 'Este juego no tiene verificación automática de jugador.', ['http_status' => 422]);
    }

    $userIdentifier = trim($userIdentifier);
    if ($userIdentifier === '') {
        return player_verification_result(false, 'invalid', 'Debes ingresar el ID del jugador.', ['http_status' => 422]);
    }

    try {
        switch ($definition['key']) {
            case 'free_fire_latam':
                $response = player_verification_http_get('https://tiendagiftven.net/conexion_api/api.php?action=ValidarParametros&id=' . rawurlencode($userIdentifier), [
                    'Accept: application/json,text/plain,*/*',
                    'User-Agent: TVirtualGaming/1.0',
                ]);
                $data = player_verification_decode_json($response['body']);
                if (!is_array($data)) {
                    return player_verification_result(false, 'unavailable', 'No se pudo verificar el jugador en este momento.');
                }

                $nickname = trim((string) ($data['nickname'] ?? ''));
                if ($nickname !== '') {
                    return player_verification_result(true, 'verified', 'Jugador encontrado: ' . $nickname, ['player_name' => $nickname]);
                }

                $message = trim((string) ($data['mensaje'] ?? ''));
                return player_verification_result(false, 'not_found', $message !== '' ? $message : 'ID del jugador no encontrado.');

            case 'free_fire_indonesia':
                $response = player_verification_http_get('https://freefire-api-six.vercel.app/get_player_personal_show?server=id&uid=' . rawurlencode($userIdentifier), [
                    'Accept: application/json,text/plain,*/*',
                    'User-Agent: TVirtualGaming/1.0',
                ]);
                $data = player_verification_decode_json($response['body']);
                if (!is_array($data)) {
                    return player_verification_result(false, 'unavailable', 'No se pudo verificar el jugador en este momento.');
                }

                $nickname = player_verification_extract_value_by_path($data, ['basicinfo', 'nickname']);
                if ($nickname !== '') {
                    return player_verification_result(true, 'verified', 'Jugador encontrado: ' . $nickname, ['player_name' => $nickname]);
                }

                $providerCode = trim((string) ($data['code'] ?? ''));
                $providerMessage = trim((string) ($data['message'] ?? $data['error'] ?? ''));
                if ($providerCode === 'PLAYER_DATA_NOT_FOUND' || $response['status'] === 404) {
                    return player_verification_result(false, 'not_found', $providerMessage !== '' ? $providerMessage : 'ID del jugador no encontrado.');
                }

                return player_verification_result(false, 'unavailable', $providerMessage !== '' ? $providerMessage : 'No se pudo verificar el jugador en este momento.');

            case 'blood_strike':
                $response = player_verification_http_get('https://pay.neteasegames.com/gameclub/bloodstrike/-1/login-role?roleid=' . rawurlencode($userIdentifier) . '&client_type=gameclub', [
                    'Accept: application/json,text/plain,*/*',
                    'User-Agent: TVirtualGaming/1.0',
                ]);
                $data = player_verification_decode_json($response['body']);
                if (!is_array($data)) {
                    return player_verification_result(false, 'unavailable', 'No se pudo verificar el jugador en este momento.');
                }

                $roleName = player_verification_extract_value_by_path($data, ['data', 'rolename']);
                if (trim((string) ($data['code'] ?? '')) === '0000' && $roleName !== '') {
                    return player_verification_result(true, 'verified', 'Jugador encontrado: ' . $roleName, ['player_name' => $roleName]);
                }

                $providerMessage = trim((string) ($data['msg'] ?? ''));
                return player_verification_result(false, 'not_found', $providerMessage !== '' ? $providerMessage : 'ID del jugador no encontrado.');

            case 'mobile_legends':
                $zoneValue = player_verification_extract_zone_value($playerFields);
                if ($zoneValue === '') {
                    return player_verification_result(false, 'invalid', 'Debes ingresar la Zona ID.', ['http_status' => 422]);
                }

                $response = player_verification_http_get('https://api.isan.eu.org/nickname/ml?id=' . rawurlencode($userIdentifier) . '&zone=' . rawurlencode($zoneValue), [
                    'Accept: application/json,text/plain,*/*',
                    'User-Agent: TVirtualGaming/1.0',
                ]);
                $body = trim((string) $response['body']);
                $bodyLower = strtolower($body);

                if ($response['status'] >= 500 && strpos($bodyLower, '1101') !== false) {
                    return player_verification_result(false, 'not_found', 'ID o Zone ID no encontrado.');
                }

                $data = player_verification_decode_json($body);
                if (is_array($data)) {
                    $nicknamePaths = [
                        ['nickname'],
                        ['name'],
                        ['username'],
                        ['data', 'nickname'],
                        ['data', 'name'],
                        ['data', 'username'],
                        ['result', 'nickname'],
                        ['result', 'name'],
                        ['result', 'username'],
                    ];
                    foreach ($nicknamePaths as $path) {
                        $nickname = player_verification_extract_value_by_path($data, $path);
                        if ($nickname !== '') {
                            return player_verification_result(true, 'verified', 'Jugador encontrado: ' . $nickname, ['player_name' => $nickname]);
                        }
                    }

                    $providerMessage = trim((string) ($data['message'] ?? $data['error'] ?? ''));
                    if ($providerMessage !== '') {
                        if (stripos($providerMessage, 'not found') !== false || stripos($providerMessage, '1101') !== false) {
                            return player_verification_result(false, 'not_found', 'ID o Zone ID no encontrado.');
                        }

                        return player_verification_result(false, 'unavailable', $providerMessage);
                    }
                }

                if ($body !== '' && strpos($bodyLower, 'error code') === false && strpos($bodyLower, 'not found') === false && strlen($body) <= 120) {
                    return player_verification_result(true, 'verified', 'Jugador encontrado: ' . $body, ['player_name' => $body]);
                }

                if (strpos($bodyLower, '1101') !== false || strpos($bodyLower, 'not found') !== false) {
                    return player_verification_result(false, 'not_found', 'ID o Zone ID no encontrado.');
                }

                return player_verification_result(false, 'unavailable', 'No se pudo verificar el jugador en este momento.');
        }
    } catch (Throwable $exception) {
        return player_verification_result(false, 'unavailable', 'No se pudo verificar el jugador en este momento.');
    }

    return player_verification_result(false, 'unsupported', 'Este juego no tiene verificación automática de jugador.', ['http_status' => 422]);
}