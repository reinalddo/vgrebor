<?php
// Verificación de stock de pases BLOOD STRIKE PASS (TiendaGiftVen).
// Consulta una API externa que, dado un ID de jugador, devuelve los ítems
// de pase que ese jugador puede comprar en este momento (los que faltan en
// la respuesta están agotados). Es una capa informativa pre-compra:
// cualquier falla se trata como "no bloquear nada" (fail-open) para no
// interferir jamás con el proceso normal de venta.

require_once __DIR__ . '/store_config.php';

function bs_pass_stock_api_key(): string {
    return trim((string) store_config_get('bs_pass_stock_api_key', ''));
}

function bs_pass_stock_is_configured(): bool {
    return bs_pass_stock_api_key() !== '';
}

function bs_pass_stock_api_url(): string {
    $url = trim((string) store_config_get('bs_pass_stock_api_url', ''));
    return $url !== '' ? $url : 'http://2.24.197.52/api/validate-packages';
}

/** Solo aplica a esta categoría exacta de TiendaGiftVen. */
function bs_pass_stock_is_pass_category(string $category): bool {
    $normalized = strtoupper(trim(preg_replace('/\s+/', ' ', $category) ?? $category));
    return $normalized === 'BLOOD STRIKE PASS';
}

function bs_pass_stock_normalize_name(string $value): string {
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

/**
 * Catálogo conocido de ítems del validador (id => alias de nombre).
 * Sirve para detectar "agotado": un ítem emparejado con un id del catálogo
 * que NO venga en la respuesta de la API está fuera de stock.
 */
function bs_pass_stock_catalog(): array {
    return [
        'levelup_pass'       => ['Pase de Nivel', 'Level-Up Pass', 'Level Up Pass', 'Levelup Pass'],
        'strike_pass_elite'  => ['Strike Pass Elite', 'Strike Pass Elite (Basico)', 'Strike Pass Elite Basico'],
        'strike_pass_premium'=> ['Strike Pass Premium'],
        'season_pass_opm'    => ['OPM Pase de Temporada', 'Pase de Temporada OPM', 'OPM Season Pass'],
        'lucky_bag_opm'      => ['OPM Bolsa de Suerte', 'OPM Bolsa de Suerte (Normal)', 'OPM Bolsa de Suerte Normal', 'OPM Lucky Bag'],
        'lucky_bag_exclusive'=> ['OPM Bolsa de Suerte Exclusiva', 'Lucky Bag Exclusive', 'OPM Lucky Bag Exclusive'],
        'deal_049'           => ['Cofre Ultra Skin', 'Cofre Ultra Skin (Oferta Especial)', 'Cofre Ultra Skin Oferta Especial'],
        'enzo'               => ['Cofre Upgrade Enzo'],
        'upgrade_chest_opm'  => ['OPM Cofre Camuflaje x10', 'OPM Cofre Camuflaje Puntos Mejora x10', 'OPM Cofre Camuflaje'],
        'valor_voucher_opm'  => ['OPM Cupon de Valor x10', 'OPM Cupon de Valor'],
        'maestro_voucher'    => ['Maestro Voucher x10', 'Maestro Voucher'],
    ];
}

/**
 * Mapeo directo producto giftven (juego_paquetes.paquete_api) => id del
 * validador. Es la vía PRINCIPAL de emparejamiento (determinista, verificada
 * contra el catálogo real de giftven); el matching por nombre queda solo
 * como respaldo para productos futuros aún no mapeados aquí.
 */
function bs_pass_stock_provider_id_map(): array {
    return [
        294 => 'levelup_pass',        // Level-Up Pass
        295 => 'strike_pass_elite',   // Pase Élite de Strike
        296 => 'strike_pass_premium', // Pase Premium Strike
        297 => 'deal_049',            // Cofre Suerte Ultra Skin
        325 => 'lucky_bag_opm',       // Semana de la Bolsa de la Suerte
        326 => 'season_pass_opm',     // Pase de Temporada (Season Pass)
        348 => 'lucky_bag_exclusive', // Semana exclusiva de la bolsa de la suerte de One-Punch Man
        349 => 'valor_voucher_opm',   // One-Punch Man Vale Destacado x10
        350 => 'upgrade_chest_opm',   // Cofre de la suerte de Punto de Mejora (OPM) x10
        351 => 'enzo',                // "Enzo: El Siguiente" Cofre de la Suerte de Puntos de Mejora
        352 => 'maestro_voucher',     // Vale de reserva destacado de Maestro x10
    ];
}

/**
 * Empareja un nombre normalizado contra el catálogo (id => [alias normalizados]).
 * Prioriza igualdad exacta; con contención (en cualquiera de las dos
 * direcciones) gana el alias más largo. Empate entre ids distintos = ambiguo
 * = null (fail-open).
 */
function bs_pass_stock_match_catalog_id(string $normalizedName, array $normalizedCatalog): ?string {
    if ($normalizedName === '') {
        return null;
    }

    $bestId = null;
    $bestScore = 0;
    $ambiguous = false;

    foreach ($normalizedCatalog as $catalogId => $aliases) {
        foreach ($aliases as $alias) {
            if ($alias === '') {
                continue;
            }
            $score = 0;
            if ($alias === $normalizedName) {
                $score = 10000 + strlen($alias);
            } elseif (strpos($normalizedName, $alias) !== false || strpos($alias, $normalizedName) !== false) {
                $score = min(strlen($alias), strlen($normalizedName));
            }
            if ($score <= 0) {
                continue;
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestId = (string) $catalogId;
                $ambiguous = false;
            } elseif ($score === $bestScore && $bestId !== null && $bestId !== (string) $catalogId) {
                $ambiguous = true;
            }
        }
    }

    return (!$ambiguous && $bestScore > 0) ? $bestId : null;
}

function bs_pass_stock_http_get(string $url, int $timeout = 75): array {
    $body = '';
    $status = 0;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => ['Accept: application/json,text/plain,*/*', 'User-Agent: TVirtualGaming/1.0'],
        ]);
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($response === false) {
            throw new RuntimeException('No se pudo consultar el validador de pases: ' . $error);
        }
        $body = (string) $response;
    } else {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => $timeout,
                'ignore_errors' => true,
                'header' => "Accept: application/json,text/plain,*/*\r\nUser-Agent: TVirtualGaming/1.0",
            ],
        ]);
        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            throw new RuntimeException('No se pudo consultar el validador de pases.');
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

    return ['status' => $status, 'body' => $body];
}

/**
 * Paquetes locales activos del juego que pertenecen a la categoría
 * BLOOD STRIKE PASS de TiendaGiftVen. Cada elemento: id, nombre local y
 * nombre del producto giftven (si se pudo resolver).
 */
function bs_pass_stock_game_pass_packages(mysqli $mysqli, int $gameId): array {
    if ($gameId <= 0) {
        return [];
    }

    $stmt = $mysqli->prepare('SELECT id, nombre, paquete_api, api_provider, api_source_key FROM juego_paquetes WHERE juego_id = ? AND COALESCE(activo, 1) = 1');
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param('i', $gameId);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();

    // Productos giftven (para resolver categoría de paquetes antiguos sin
    // api_source_key y para obtener el nombre oficial del producto).
    $productsById = null;
    if (function_exists('recargas_api_is_configured') && recargas_api_is_configured()) {
        try {
            foreach (recargas_api_fetch_products() as $product) {
                if (is_array($product)) {
                    $productsById[(int) ($product['id'] ?? 0)] = $product;
                }
            }
        } catch (Throwable $e) {
            $productsById = null;
        }
    }

    $passPackages = [];
    foreach ($rows as $row) {
        $provider = strtolower(trim((string) ($row['api_provider'] ?? '')));
        $apiId = (int) ($row['paquete_api'] ?? 0);
        if ($provider === '' && $apiId > 0) {
            $provider = 'giftven';
        }
        if ($provider !== 'giftven' || $apiId <= 0) {
            continue;
        }

        $category = trim((string) ($row['api_source_key'] ?? ''));
        $giftvenName = '';
        if (is_array($productsById) && isset($productsById[$apiId])) {
            $giftvenName = trim((string) ($productsById[$apiId]['nombre'] ?? ''));
            if ($category === '') {
                $category = trim((string) ($productsById[$apiId]['categoria'] ?? ''));
            }
        }

        if (!bs_pass_stock_is_pass_category($category)) {
            continue;
        }

        $passPackages[] = [
            'id' => (int) $row['id'],
            'api_id' => $apiId,
            'nombre' => trim((string) ($row['nombre'] ?? '')),
            'giftven_name' => $giftvenName,
        ];
    }

    return $passPackages;
}

/**
 * Consulta principal. Devuelve:
 *  - ['ok'=>true,'applicable'=>false] si el juego no tiene paquetes PASS.
 *  - ['ok'=>false,'reason'=>...] si el validador falló (el frontend no bloquea nada).
 *  - ['ok'=>true,'applicable'=>true,'player_name','blocked'=>[],'available'=>[],'unmatched'=>[]]
 */
function bs_pass_stock_check(mysqli $mysqli, int $gameId, string $playerId): array {
    $passPackages = bs_pass_stock_game_pass_packages($mysqli, $gameId);
    if ($passPackages === []) {
        return ['ok' => true, 'applicable' => false];
    }

    // Caché corto por jugador: el validador tarda 20-35s, evita repetirlo
    // si el cliente re-verifica el mismo ID enseguida. Se mantiene corto
    // (60s) para que una compra recién hecha se refleje pronto.
    $cacheKey = $gameId . '_' . $playerId;
    if (isset($_SESSION) && is_array($_SESSION['bs_pass_stock_cache'][$cacheKey] ?? null)) {
        $cached = $_SESSION['bs_pass_stock_cache'][$cacheKey];
        if ((time() - (int) ($cached['t'] ?? 0)) < 60 && is_array($cached['result'] ?? null)) {
            return $cached['result'];
        }
    }

    $url = bs_pass_stock_api_url()
        . '?playerId=' . rawurlencode($playerId)
        . '&api_key=' . rawurlencode(bs_pass_stock_api_key());

    // El validador tarda 20-35s: liberar el lock de sesión durante la
    // llamada para no congelar otras peticiones del mismo usuario.
    $sessionWasActive = session_status() === PHP_SESSION_ACTIVE;
    if ($sessionWasActive) {
        session_write_close();
    }

    try {
        $response = bs_pass_stock_http_get($url, 75);
    } catch (Throwable $e) {
        return ['ok' => false, 'reason' => 'unavailable'];
    } finally {
        if ($sessionWasActive && session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
    }

    $data = json_decode((string) $response['body'], true);
    if (!is_array($data)) {
        return ['ok' => false, 'reason' => 'invalid_response'];
    }
    if (empty($data['success'])) {
        return [
            'ok' => false,
            'reason' => $response['status'] === 401 ? 'unauthorized' : 'provider_error',
            'message' => trim((string) ($data['error'] ?? '')),
        ];
    }

    // Ids en stock + alias dinámicos con los nombres que devuelve la API.
    $catalog = bs_pass_stock_catalog();
    $inStockIds = [];
    foreach ((array) ($data['packages'] ?? []) as $apiPackage) {
        if (!is_array($apiPackage)) {
            continue;
        }
        $apiPackageId = trim((string) ($apiPackage['id'] ?? ''));
        if ($apiPackageId === '') {
            continue;
        }
        $inStockIds[$apiPackageId] = true;
        $apiPackageName = trim((string) ($apiPackage['name'] ?? ''));
        if ($apiPackageName !== '') {
            $catalog[$apiPackageId][] = $apiPackageName;
        }
    }

    $normalizedCatalog = [];
    foreach ($catalog as $catalogId => $aliases) {
        $normalizedAliases = [];
        foreach ((array) $aliases as $alias) {
            $normalizedAlias = bs_pass_stock_normalize_name((string) $alias);
            if ($normalizedAlias !== '') {
                $normalizedAliases[] = $normalizedAlias;
            }
        }
        if ($normalizedAliases !== []) {
            $normalizedCatalog[(string) $catalogId] = array_values(array_unique($normalizedAliases));
        }
    }

    $blocked = [];
    $available = [];
    $unmatched = [];
    $providerIdMap = bs_pass_stock_provider_id_map();
    foreach ($passPackages as $passPackage) {
        // Vía principal: mapeo exacto por id del producto giftven.
        $matchId = $providerIdMap[$passPackage['api_id']] ?? null;

        // Respaldo (productos futuros sin mapear): matching por nombre.
        if ($matchId === null) {
            foreach ([$passPackage['giftven_name'], $passPackage['nombre']] as $candidate) {
                $normalizedCandidate = bs_pass_stock_normalize_name((string) $candidate);
                if ($normalizedCandidate === '') {
                    continue;
                }
                $matchId = bs_pass_stock_match_catalog_id($normalizedCandidate, $normalizedCatalog);
                if ($matchId !== null) {
                    break;
                }
            }
        }

        if ($matchId === null) {
            // Sin emparejamiento fiable: nunca bloquear (fail-open).
            $unmatched[] = $passPackage['id'];
            $available[] = $passPackage['id'];
            continue;
        }

        if (isset($inStockIds[$matchId])) {
            $available[] = $passPackage['id'];
        } else {
            $blocked[] = $passPackage['id'];
        }
    }

    $result = [
        'ok' => true,
        'applicable' => true,
        'player_name' => trim((string) ($data['characterName'] ?? '')),
        'blocked' => $blocked,
        'available' => $available,
        'unmatched' => $unmatched,
    ];

    if (isset($_SESSION)) {
        if (!is_array($_SESSION['bs_pass_stock_cache'] ?? null)) {
            $_SESSION['bs_pass_stock_cache'] = [];
        }
        $_SESSION['bs_pass_stock_cache'][$cacheKey] = ['t' => time(), 'result' => $result];
        while (count($_SESSION['bs_pass_stock_cache']) > 8) {
            array_shift($_SESSION['bs_pass_stock_cache']);
        }
    }

    return $result;
}
