<?php
// Aviso global del admin: paquetes de RecargasAmérica que todavía apuntan al
// módulo viejo "PINes & Recargas", que RecargasAmérica da de baja el
// 2026-09-20 (ver el asistente de migración en admin/paquetes/{juego} y la
// sección "Catálogo Unificado" de CLAUDE.md).
//
// Solo consulta la base de datos (sin llamadas a la API): un paquete está "sin
// migrar" si es de RecargasAmérica, está activo y su marca recargasamerica_tipo
// es la vieja ('pin' / 'recharge') o está vacía.

if (!defined('RECARGASAMERICA_LEGACY_SHUTDOWN_DATE')) {
    define('RECARGASAMERICA_LEGACY_SHUTDOWN_DATE', '2026-09-20');
}

// Devuelve ['total' => int, 'games' => [['id' => int, 'nombre' => string, 'count' => int], ...]].
// Ante cualquier problema (tabla/columna inexistente, error de BD) devuelve
// vacío: un aviso informativo nunca debe romper una página del admin.
function recargasamerica_migration_pending_summary(mysqli $mysqli): array {
    $empty = ['total' => 0, 'games' => []];

    try {
        $providerCol = $mysqli->query("SHOW COLUMNS FROM juego_paquetes LIKE 'api_provider'");
        if (!($providerCol instanceof mysqli_result) || $providerCol->num_rows === 0) {
            return $empty;
        }

        // Sin la columna de marca ningún paquete pudo haberse migrado todavía.
        $tipoCol = $mysqli->query("SHOW COLUMNS FROM juego_paquetes LIKE 'recargasamerica_tipo'");
        $legacyCondition = ($tipoCol instanceof mysqli_result && $tipoCol->num_rows > 0)
            ? "(jp.recargasamerica_tipo IS NULL OR jp.recargasamerica_tipo IN ('', 'pin', 'recharge'))"
            : '1 = 1';

        $activeCondition = '1 = 1';
        $activeCol = $mysqli->query("SHOW COLUMNS FROM juego_paquetes LIKE 'activo'");
        if ($activeCol instanceof mysqli_result && $activeCol->num_rows > 0) {
            $activeCondition = 'COALESCE(jp.activo, 1) = 1';
        }

        $res = $mysqli->query(
            "SELECT j.id, j.nombre, COUNT(*) AS c
             FROM juego_paquetes jp
             INNER JOIN juegos j ON j.id = jp.juego_id
             WHERE jp.api_provider = 'recargasamerica' AND {$legacyCondition} AND {$activeCondition}
             GROUP BY j.id, j.nombre
             ORDER BY j.nombre ASC"
        );
        if (!($res instanceof mysqli_result)) {
            return $empty;
        }

        $games = [];
        $total = 0;
        while ($row = $res->fetch_assoc()) {
            $count = (int) ($row['c'] ?? 0);
            $games[] = ['id' => (int) $row['id'], 'nombre' => (string) ($row['nombre'] ?? ''), 'count' => $count];
            $total += $count;
        }
        $res->free();

        return ['total' => $total, 'games' => $games];
    } catch (Throwable $e) {
        return $empty;
    }
}

// Días que faltan para la baja (negativo o 0 = ya ocurrió). Se compara por
// fecha de calendario en la zona horaria del servidor.
function recargasamerica_migration_days_left(?string $today = null): int {
    $todayTs = strtotime(($today ?? date('Y-m-d')) . ' 00:00:00');
    $deadlineTs = strtotime(RECARGASAMERICA_LEGACY_SHUTDOWN_DATE . ' 00:00:00');
    if ($todayTs === false || $deadlineTs === false) {
        return 0;
    }

    return (int) round(($deadlineTs - $todayTs) / 86400);
}

// HTML del aviso ('' si no hay nada pendiente). $paquetesUrl recibe el id del
// juego y devuelve la URL de su página de paquetes.
function recargasamerica_migration_notice_html(array $summary, int $daysLeft, callable $paquetesUrl): string {
    if (empty($summary['total'])) {
        return '';
    }

    $e = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    $total = (int) $summary['total'];
    $already = $daysLeft <= 0;

    if ($already) {
        $title = 'RecargasAmérica ya dio de baja el módulo viejo: ' . $total . ' paquete(s) no se pueden vender';
        $detail = 'Estos paquetes siguen apuntando al módulo "PINes & Recargas" y sus compras fallan. Migra cada uno a su producto del Catálogo Unificado:';
        $colors = 'background:#3b1010;border:1px solid #ef4444;color:#fecaca;';
        $linkColor = '#fecaca';
    } else {
        $when = $daysLeft === 1 ? 'mañana' : 'en ' . $daysLeft . ' días';
        $title = 'RecargasAmérica da de baja su módulo viejo ' . $when . ': ' . $total . ' paquete(s) sin migrar';
        $detail = 'Estos paquetes dejarán de venderse el 20 de septiembre de 2026 si no se migran al Catálogo Unificado. Abre cada juego y usa el asistente de migración:';
        $colors = 'background:#3b2a05;border:1px solid #f59e0b;color:#fde68a;';
        $linkColor = '#fde68a';
    }

    $links = [];
    foreach ($summary['games'] as $game) {
        $links[] = '<a href="' . $e((string) $paquetesUrl((int) $game['id'])) . '" style="color:' . $linkColor . ';font-weight:600;text-decoration:underline;">'
            . $e((string) $game['nombre']) . ' (' . (int) $game['count'] . ')</a>';
    }

    return '<div role="alert" data-ra-migration-notice="1" style="' . $colors . 'border-radius:12px;padding:12px 16px;margin:0 0 16px 0;font-size:0.92rem;line-height:1.45;">'
        . '<div style="font-weight:700;margin-bottom:4px;">' . $e($title) . '</div>'
        . '<div style="margin-bottom:6px;">' . $e($detail) . '</div>'
        . '<div>' . implode(' · ', $links) . '</div>'
        . '</div>';
}
