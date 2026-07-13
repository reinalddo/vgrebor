<?php
// Prueba temporal de la lógica de emparejamiento de bs_pass_stock (borrar al terminar).
require_once __DIR__ . '/includes/db_connect.php';
require_once __DIR__ . '/includes/tenant.php';
require_once __DIR__ . '/includes/bs_pass_stock.php';
header('Content-Type: text/plain; charset=utf-8');

$pass = 0; $fail = 0;
function check(string $label, $expected, $actual): void {
    global $pass, $fail;
    $ok = $expected === $actual;
    echo ($ok ? 'OK  ' : 'FAIL') . " {$label} => " . var_export($actual, true) . ($ok ? '' : ' (esperado ' . var_export($expected, true) . ')') . "\n";
    if ($ok) $pass++; else $fail++;
}

// Categoría
check('categoria exacta', true, bs_pass_stock_is_pass_category('BLOOD STRIKE PASS'));
check('categoria minusculas/espacios', true, bs_pass_stock_is_pass_category('  blood strike  pass '));
check('categoria BLOOD STRIKE normal', false, bs_pass_stock_is_pass_category('BLOOD STRIKE'));
check('categoria BLOOD STRIKE 2.0', false, bs_pass_stock_is_pass_category('BLOOD STRIKE 2.0'));

// Catálogo normalizado (simulando la respuesta real de la API con 7 items en stock)
$catalog = bs_pass_stock_catalog();
$apiResponsePackages = [
    ['id' => 'strike_pass_elite', 'name' => 'Strike Pass Elite'],
    ['id' => 'strike_pass_premium', 'name' => 'Strike Pass Premium'],
    ['id' => 'valor_voucher_opm', 'name' => 'OPM Cupón de Valor x10'],
    ['id' => 'upgrade_chest_opm', 'name' => 'OPM Cofre Camuflaje Puntos Mejora x10'],
    ['id' => 'enzo', 'name' => 'Cofre Upgrade Enzo'],
    ['id' => 'maestro_voucher', 'name' => 'Maestro Voucher x10'],
    ['id' => 'deal_049', 'name' => 'Cofre Ultra Skin'],
];
foreach ($apiResponsePackages as $p) { $catalog[$p['id']][] = $p['name']; }
$normalized = [];
foreach ($catalog as $id => $aliases) {
    foreach ($aliases as $a) { $n = bs_pass_stock_normalize_name($a); if ($n !== '') $normalized[$id][] = $n; }
    $normalized[$id] = array_values(array_unique($normalized[$id]));
}

function m(string $name, array $cat): ?string { return bs_pass_stock_match_catalog_id(bs_pass_stock_normalize_name($name), $cat); }

check('exacto: Strike Pass Elite', 'strike_pass_elite', m('Strike Pass Elite', $normalized));
check('con acento/parentesis: Strike Pass Elite (Básico)', 'strike_pass_elite', m('Strike Pass Elite (Básico)', $normalized));
check('premium', 'strike_pass_premium', m('Strike Pass Premium', $normalized));
check('cupon valor con acento', 'valor_voucher_opm', m('OPM Cupón de Valor x10', $normalized));
check('cofre camuflaje corto vs largo', 'upgrade_chest_opm', m('OPM Cofre Camuflaje x10', $normalized));
check('ultra skin oferta', 'deal_049', m('Cofre Ultra Skin (Oferta Especial)', $normalized));
check('enzo', 'enzo', m('Cofre Upgrade Enzo', $normalized));
check('pase de nivel (agotado, no en respuesta)', 'levelup_pass', m('Pase de Nivel', $normalized));
check('bolsa suerte exclusiva gana a normal', 'lucky_bag_exclusive', m('OPM Bolsa de Suerte Exclusiva', $normalized));
check('bolsa suerte normal', 'lucky_bag_opm', m('OPM Bolsa de Suerte (Normal)', $normalized));
check('nombre local sin match: 50 GOLD', null, m('50 GOLD', $normalized));
check('nombre local sin match: Bolsa de la Suerte Semanal', null, m('Bolsa de la Suerte Semanal', $normalized));
check('vacio', null, m('', $normalized));

// Decisión stock (los 4 ausentes de la respuesta = bloqueados)
$inStock = [];
foreach ($apiResponsePackages as $p) { $inStock[$p['id']] = true; }
check('elite en stock', true, isset($inStock[(string) m('Strike Pass Elite', $normalized)]));
check('pase de nivel bloqueado', false, isset($inStock[(string) m('Pase de Nivel', $normalized)]));
check('pase temporada bloqueado', false, isset($inStock[(string) m('OPM Pase de Temporada', $normalized)]));

echo "\nRESULTADO: {$pass} OK, {$fail} FAIL\n";
