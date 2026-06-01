<?php

require_once __DIR__ . '/tenant.php';
require_once __DIR__ . '/slugify.php';

function public_catalog_search_normalize_text(string $value): string {
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    $lowered = function_exists('mb_strtolower')
        ? mb_strtolower($value, 'UTF-8')
        : strtolower($value);

    return preg_replace('/\s+/u', ' ', $lowered) ?? $lowered;
}

function public_catalog_search_escape_like(string $value): string {
    return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
}

function public_catalog_search_money(?float $value, bool $prefixFrom = false): string {
    $numericValue = (float) ($value ?? 0);
    if ($numericValue <= 0) {
        return '';
    }

    $formatted = number_format($numericValue, 2, '.', ',');
    return $prefixFrom ? 'Desde ' . $formatted : $formatted;
}

function public_catalog_search_image_url(?string $path): string {
    $assetPath = trim((string) $path);
    if ($assetPath === '') {
        return '';
    }

    return app_path('/' . ltrim($assetPath, '/'));
}

function public_catalog_search_game_url(array $game, int $packageId = 0): string {
    $url = app_path(game_route_path($game));
    if ($packageId > 0) {
        $url .= '?package_id=' . rawurlencode((string) $packageId);
    }

    return $url;
}

function public_catalog_search_games(mysqli $mysqli, string $query, int $limit = 12): array {
    $normalizedQuery = public_catalog_search_normalize_text($query);
    if ($normalizedQuery === '') {
        return [];
    }

    $safeLimit = max(1, min(60, $limit));
    $escapedLike = '%' . public_catalog_search_escape_like($normalizedQuery) . '%';
    $sql = "SELECT
                j.id,
                j.nombre,
                j.slug,
                j.imagen,
                j.imagen_paquete,
                COALESCE(j.popular, 0) AS popular,
                (
                    SELECT MIN(jp.precio)
                    FROM juego_paquetes jp
                    WHERE jp.juego_id = j.id AND COALESCE(jp.activo, 1) = 1
                ) AS min_price
            FROM juegos j
            WHERE COALESCE(j.activo, 1) = 1
              AND (
                    LOWER(COALESCE(j.nombre, '')) LIKE ? ESCAPE '\\\\'
                 OR LOWER(COALESCE(j.slug, '')) LIKE ? ESCAPE '\\\\'
              )
            ORDER BY
                CASE
                    WHEN LOWER(COALESCE(j.nombre, '')) = ? THEN 0
                    WHEN LOWER(COALESCE(j.slug, '')) = ? THEN 1
                    ELSE 2
                END,
                COALESCE(j.popular, 0) DESC,
                COALESCE(j.orden, 999999) ASC,
                j.nombre ASC
            LIMIT ?";

    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        return [];
    }

    $stmt->bind_param('ssssi', $escapedLike, $escapedLike, $normalizedQuery, $normalizedQuery, $safeLimit);
    $stmt->execute();
    $result = $stmt->get_result();
    $games = [];
    while ($row = $result->fetch_assoc()) {
        $game = [
            'id' => (int) ($row['id'] ?? 0),
            'nombre' => (string) ($row['nombre'] ?? ''),
            'slug' => (string) ($row['slug'] ?? ''),
        ];
        $games[] = [
            'type' => 'game',
            'id' => (int) ($row['id'] ?? 0),
            'name' => (string) ($row['nombre'] ?? ''),
            'game_name' => (string) ($row['nombre'] ?? ''),
            'image_url' => public_catalog_search_image_url((string) ($row['imagen'] ?? '')),
            'price_label' => public_catalog_search_money(isset($row['min_price']) ? (float) $row['min_price'] : null, true),
            'badge' => 'Juego',
            'url' => public_catalog_search_game_url($game),
            'exact_match' => public_catalog_search_normalize_text((string) ($row['nombre'] ?? '')) === $normalizedQuery
                || public_catalog_search_normalize_text((string) ($row['slug'] ?? '')) === $normalizedQuery,
        ];
    }

    $stmt->close();
    return $games;
}

function public_catalog_search_packages(mysqli $mysqli, string $query, int $limit = 12): array {
    $normalizedQuery = public_catalog_search_normalize_text($query);
    if ($normalizedQuery === '') {
        return [];
    }

    $safeLimit = max(1, min(60, $limit));
    $escapedLike = '%' . public_catalog_search_escape_like($normalizedQuery) . '%';
    $sql = "SELECT
                jp.id,
                jp.nombre,
                jp.precio,
                jp.imagen_icono,
                j.id AS juego_id,
                j.nombre AS juego_nombre,
                j.slug AS juego_slug,
                j.imagen AS juego_imagen,
                j.imagen_paquete AS juego_imagen_paquete
            FROM juego_paquetes jp
            INNER JOIN juegos j ON j.id = jp.juego_id
            WHERE COALESCE(j.activo, 1) = 1
              AND COALESCE(jp.activo, 1) = 1
              AND (
                    LOWER(COALESCE(jp.nombre, '')) LIKE ? ESCAPE '\\\\'
                 OR LOWER(COALESCE(j.nombre, '')) LIKE ? ESCAPE '\\\\'
              )
            ORDER BY
                CASE WHEN LOWER(COALESCE(jp.nombre, '')) = ? THEN 0 ELSE 1 END,
                COALESCE(j.popular, 0) DESC,
                COALESCE(jp.orden, 999999) ASC,
                jp.nombre ASC
            LIMIT ?";

    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        return [];
    }

    $stmt->bind_param('sssi', $escapedLike, $escapedLike, $normalizedQuery, $safeLimit);
    $stmt->execute();
    $result = $stmt->get_result();
    $packages = [];
    while ($row = $result->fetch_assoc()) {
        $game = [
            'id' => (int) ($row['juego_id'] ?? 0),
            'nombre' => (string) ($row['juego_nombre'] ?? ''),
            'slug' => (string) ($row['juego_slug'] ?? ''),
        ];
        $packages[] = [
            'type' => 'package',
            'id' => (int) ($row['id'] ?? 0),
            'name' => (string) ($row['nombre'] ?? ''),
            'game_name' => (string) ($row['juego_nombre'] ?? ''),
            'image_url' => public_catalog_search_image_url((string) (($row['imagen_icono'] ?? '') !== '' ? $row['imagen_icono'] : (($row['juego_imagen_paquete'] ?? '') !== '' ? $row['juego_imagen_paquete'] : ($row['juego_imagen'] ?? '')))),
            'price_label' => public_catalog_search_money(isset($row['precio']) ? (float) $row['precio'] : null, false),
            'badge' => 'Paquete',
            'url' => public_catalog_search_game_url($game, (int) ($row['id'] ?? 0)),
            'exact_match' => public_catalog_search_normalize_text((string) ($row['nombre'] ?? '')) === $normalizedQuery,
        ];
    }

    $stmt->close();
    return $packages;
}

function public_catalog_search_results(mysqli $mysqli, string $query, int $limitPerType = 12): array {
    $games = public_catalog_search_games($mysqli, $query, $limitPerType);
    $packages = public_catalog_search_packages($mysqli, $query, $limitPerType);
    $items = array_merge($games, $packages);

    usort($items, static function (array $left, array $right): int {
        $leftWeight = !empty($left['exact_match']) ? 0 : 1;
        $rightWeight = !empty($right['exact_match']) ? 0 : 1;
        if ($leftWeight !== $rightWeight) {
            return $leftWeight <=> $rightWeight;
        }

        if (($left['type'] ?? '') !== ($right['type'] ?? '')) {
            return ($left['type'] ?? '') <=> ($right['type'] ?? '');
        }

        return strcmp((string) ($left['name'] ?? ''), (string) ($right['name'] ?? ''));
    });

    return [
        'query' => trim($query),
        'games' => $games,
        'packages' => $packages,
        'items' => $items,
    ];
}