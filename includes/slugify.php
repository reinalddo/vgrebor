<?php
// Funciones para URL amigables (slug)
function slugify($text) {
    $text = trim((string) $text);
    if ($text === '') {
        return 'n-a';
    }

    if (class_exists('Transliterator')) {
        $transliterator = Transliterator::create('Any-Latin; Latin-ASCII; [:Nonspacing Mark:] Remove; NFC');
        if ($transliterator instanceof Transliterator) {
            $text = $transliterator->transliterate($text);
        }
    } else {
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        if (is_string($converted) && $converted !== '') {
            $text = $converted;
        }
    }

    $text = strtolower($text);
    $text = preg_replace('~[^a-z0-9]+~', '-', $text) ?? '';
    $text = trim($text, '-');
    $text = preg_replace('~-+~', '-', $text) ?? $text;
    return $text ?: 'n-a';
}

function game_resolve_slug(array $game): string {
    $storedSlug = trim((string) ($game['slug'] ?? ''));
    if ($storedSlug !== '') {
        return slugify($storedSlug);
    }

    return slugify((string) ($game['nombre'] ?? ''));
}

function game_route_segment(array $game): string {
    $gameId = (int) ($game['id'] ?? 0);
    $slug = game_resolve_slug($game);

    return $gameId > 0 ? $gameId . '-' . $slug : $slug;
}

function game_route_path(array $game): string {
    return '/juego/' . game_route_segment($game);
}
