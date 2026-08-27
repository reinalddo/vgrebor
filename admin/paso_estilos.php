<?php
require_once __DIR__ . '/../includes/tenant.php';
tenant_start_session();
$adminRole = trim((string) ($_SESSION['auth_user']['rol'] ?? ''));
if (!isset($_SESSION['auth_user']) || !in_array($adminRole, ['admin', 'root'], true)) {
    header('Location: ' . app_path('/login.php'));
    exit();
}
require_once __DIR__ . '/../includes/auth.php';
csrf_verify_soft();

require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/store_config.php';
require_once __DIR__ . '/../includes/paso_estilos.php';

$etiquetasZonas = [
    'paso1' => 'Título "PASO 1"',
    'paso2' => 'Título "PASO 2"',
    'paso3' => 'Título "PASO 3"',
    'campo' => 'Campo de texto (ID de usuario)',
    'boton' => 'Botón de verificación',
    'exito' => 'Resultado exitoso',
    'fallo' => 'Resultado fallido',
];

$flashMessage = '';
$flashType = 'success';
$isAjax = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';

// ── Acción: guardar una zona de estilo ──────────────────────────────────
// Un solo handler genérico para las 7 zonas — todas comparten los mismos
// campos base. El "modo" (original/personalizado) es independiente de los
// demás valores: cambiarlo NUNCA borra lo que ya estaba guardado, para que
// el cliente pueda ir y volver del diseño personalizado sin perder nada.
// Se guarda por AJAX (fetch desde el JS de abajo) para no recargar toda la
// página ni perder lo que el admin esté ajustando en las otras tarjetas —
// el POST normal (sin JS) sigue funcionando como respaldo.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'guardar_zona_estilo') {
    $zona = trim((string) ($_POST['zona'] ?? ''));
    if (!paso_estilo_zona_valida($zona)) {
        $flashMessage = 'Zona inválida.';
        $flashType = 'danger';
    } else {
        $defaults = paso_estilo_defaults($zona);

        $nuevoModo = ($_POST['modo'] ?? 'original') === 'personalizado' ? 'personalizado' : 'original';
        $nuevoFondoTipo = ($_POST['fondo_tipo'] ?? 'solido') === 'degradado' ? 'degradado' : 'solido';
        $nuevoColorFondo = store_config_normalize_hex_color((string) ($_POST['color_fondo'] ?? ''), $defaults['color_fondo']);
        $nuevoColorFondo2 = store_config_normalize_hex_color((string) ($_POST['color_fondo2'] ?? ''), $defaults['color_fondo2']);
        $nuevoColorTexto = store_config_normalize_hex_color((string) ($_POST['color_texto'] ?? ''), $defaults['color_texto']);
        $nuevoColorBorde = store_config_normalize_hex_color((string) ($_POST['color_borde'] ?? ''), $defaults['color_borde']);

        $fuentesDisponibles = paso_estilo_fuentes_disponibles();
        $nuevaFuente = trim((string) ($_POST['fuente_familia'] ?? ''));
        if (!array_key_exists($nuevaFuente, $fuentesDisponibles)) {
            $nuevaFuente = $defaults['fuente_familia'];
        }

        $tamanoNumero = (float) str_replace(',', '.', trim((string) ($_POST['fuente_tamano'] ?? '')));
        if ($tamanoNumero < 0.6 || $tamanoNumero > 3) {
            $tamanoNumero = (float) rtrim($defaults['fuente_tamano'], 'rem');
        }
        $nuevoTamano = round($tamanoNumero, 2) . 'rem';

        $nuevoBordeActivo = isset($_POST['borde_neon_activo']) ? '1' : '0';

        $grosorNumero = (int) trim((string) ($_POST['borde_grosor'] ?? ''));
        if ($grosorNumero < 1 || $grosorNumero > 6) {
            $grosorNumero = (int) $defaults['borde_grosor'];
        }
        $brilloNumero = (int) trim((string) ($_POST['borde_brillo'] ?? ''));
        if ($brilloNumero < 2 || $brilloNumero > 40) {
            $brilloNumero = (int) $defaults['borde_brillo'];
        }

        store_config_upsert('paso_estilo_' . $zona . '_modo', $nuevoModo, 'Rediseño Paso/Verificador: modo (original/personalizado) de la zona "' . $zona . '".');
        store_config_upsert('paso_estilo_' . $zona . '_fondo_tipo', $nuevoFondoTipo, 'Rediseño Paso/Verificador: tipo de fondo (sólido/degradado) de "' . $zona . '".');
        store_config_upsert('paso_estilo_' . $zona . '_color_fondo', $nuevoColorFondo, 'Rediseño Paso/Verificador: color de fondo (o color 1 del degradado) de "' . $zona . '".');
        store_config_upsert('paso_estilo_' . $zona . '_color_fondo2', $nuevoColorFondo2, 'Rediseño Paso/Verificador: color 2 del degradado de "' . $zona . '".');
        store_config_upsert('paso_estilo_' . $zona . '_color_texto', $nuevoColorTexto, 'Rediseño Paso/Verificador: color de letra de "' . $zona . '".');
        store_config_upsert('paso_estilo_' . $zona . '_fuente_familia', $nuevaFuente, 'Rediseño Paso/Verificador: familia de letra de "' . $zona . '".');
        store_config_upsert('paso_estilo_' . $zona . '_fuente_tamano', $nuevoTamano, 'Rediseño Paso/Verificador: tamaño de letra de "' . $zona . '".');
        store_config_upsert('paso_estilo_' . $zona . '_borde_neon_activo', $nuevoBordeActivo, 'Rediseño Paso/Verificador: si el borde neón está activo en "' . $zona . '".');
        store_config_upsert('paso_estilo_' . $zona . '_color_borde', $nuevoColorBorde, 'Rediseño Paso/Verificador: color del borde neón de "' . $zona . '".');
        store_config_upsert('paso_estilo_' . $zona . '_borde_grosor', (string) $grosorNumero, 'Rediseño Paso/Verificador: grosor en px del borde neón de "' . $zona . '".');
        store_config_upsert('paso_estilo_' . $zona . '_borde_brillo', (string) $brilloNumero, 'Rediseño Paso/Verificador: tamaño en px del brillo neón de "' . $zona . '".');

        if (paso_estilo_zona_tiene_texto($zona)) {
            $nuevoTexto = trim((string) ($_POST['texto'] ?? ''));
            if ($nuevoTexto === '') {
                $nuevoTexto = $defaults['texto'];
            }
            store_config_upsert('paso_estilo_' . $zona . '_texto', $nuevoTexto, 'Rediseño Paso/Verificador: texto del título de "' . $zona . '".');
        }

        $iconosDisponibles = paso_estilo_iconos_disponibles($zona);
        if (!empty($iconosDisponibles)) {
            $nuevoIcono = trim((string) ($_POST['icono'] ?? ''));
            if ($nuevoIcono !== 'personalizado' && !array_key_exists($nuevoIcono, $iconosDisponibles)) {
                $nuevoIcono = $defaults['icono'] ?? array_key_first($iconosDisponibles);
            }
            store_config_upsert('paso_estilo_' . $zona . '_icono', $nuevoIcono, 'Rediseño Paso/Verificador: ícono predefinido (o "personalizado") de "' . $zona . '".');

            if ($nuevoIcono === 'personalizado') {
                $nuevoIconoPersonalizado = trim((string) ($_POST['icono_personalizado'] ?? ''));
                if ($nuevoIconoPersonalizado === '') {
                    $nuevoIconoPersonalizado = $iconosDisponibles[array_key_first($iconosDisponibles)];
                }
                // mb_substr por si el admin pega más de un emoji/caracter — se
                // guarda solo lo que entra en el espacio pensado para 1 ícono.
                $nuevoIconoPersonalizado = mb_substr($nuevoIconoPersonalizado, 0, 8);
                store_config_upsert('paso_estilo_' . $zona . '_icono_personalizado', $nuevoIconoPersonalizado, 'Rediseño Paso/Verificador: ícono personalizado (emoji libre) de "' . $zona . '".');
            }
        }

        $flashMessage = 'Diseño de "' . $etiquetasZonas[$zona] . '" guardado correctamente.';
        $flashType = 'success';
    }

    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => $flashType === 'success', 'message' => $flashMessage], JSON_UNESCAPED_UNICODE);
        exit();
    }
}

require_once __DIR__ . '/../includes/header.php';

// Valores actuales de cada zona (para precargar los formularios y la vista previa inicial).
$zonasActuales = [];
foreach (paso_estilo_zonas_validas() as $zona) {
    $zonasActuales[$zona] = [
        'modo' => paso_estilo_modo($zona),
        'texto' => paso_estilo_texto($zona),
        'fondo_tipo' => paso_estilo_fondo_tipo($zona),
        'color_fondo' => paso_estilo_color_fondo($zona),
        'color_fondo2' => paso_estilo_color_fondo2($zona),
        'color_texto' => paso_estilo_color_texto($zona),
        'fuente_familia' => paso_estilo_fuente_familia($zona),
        'fuente_tamano' => paso_estilo_fuente_tamano($zona),
        'borde_neon_activo' => paso_estilo_borde_neon_activo($zona),
        'color_borde' => paso_estilo_color_borde($zona),
        'borde_grosor' => paso_estilo_borde_grosor($zona),
        'borde_brillo' => paso_estilo_borde_brillo($zona),
        'icono' => paso_estilo_icono_clave($zona),
        'icono_personalizado' => paso_estilo_icono_personalizado($zona),
    ];
}

$fuentesDisponibles = paso_estilo_fuentes_disponibles();
$fuentesEtiquetas = [
    'heredado' => 'Igual que el resto de la página',
    'oxanium' => 'Oxanium (gaming)',
    'space_grotesk' => 'Space Grotesk',
    'sans' => 'Sans-serif estándar',
    'serif' => 'Serif clásica',
];

$mensajesEjemplo = [
    'exito' => 'WOWKILL',
    'fallo' => 'ID no encontrado o no válido en este servidor. Asegúrate de que esté correcto antes de comprar.',
];
$badgeEjemplo = ['exito' => 'VERIFICADO', 'fallo' => 'INVALIDO'];
?>
<main class="container-lg mt-5 mb-5 px-2">
  <style>
    .pe-card { background:#181f2a; border:1px solid #00fff7; border-radius:14px; padding:1.4rem; margin-bottom:1.5rem; }
    .pe-input { background:#222c3a; color:#00fff7; border:1px solid #00fff7; }
    .pe-input:focus { background:#222c3a; color:#00fff7; border-color:#00fff7; box-shadow:0 0 0 0.2rem rgba(0,255,247,0.25); }
    .pe-color-input { width:56px; height:38px; padding:2px; background:#222c3a; border:1px solid #00fff7; border-radius:8px; }
    .pe-preview-box { background:#0b0f18; border:1px dashed rgba(0,255,247,0.35); border-radius:12px; padding:1.5rem; display:flex; align-items:center; justify-content:center; min-height:110px; margin-bottom:1.25rem; overflow-x:auto; }
    .pe-flash { display:none; border-radius:8px; padding:0.5rem 0.9rem; font-size:0.85rem; font-weight:600; margin-bottom:1rem; }
    .pe-flash.show { display:block; }
    .pe-flash.ok { background:rgba(16,185,129,0.15); color:#34d399; border:1px solid rgba(52,211,153,0.4); }
    .pe-flash.error { background:rgba(239,68,68,0.15); color:#f87171; border:1px solid rgba(248,113,113,0.4); }

    /* Vista "original" — reproduce las clases reales que usa game.php hoy */
    .pe-orig-paso { color:#22d3ee; font-weight:900; font-size:1.3rem; letter-spacing:0.02em; }
    .pe-orig-campo-wrap { display:flex; flex-direction:column; gap:0.3rem; width:220px; }
    .pe-orig-campo-wrap label { color:#22d3ee; font-size:0.85rem; }
    .pe-orig-campo-wrap input { background:#0b0f18; color:#22d3ee; border:1px solid #22d3ee; border-radius:6px; padding:0.5rem 0.7rem; }
    .pe-orig-boton { background:transparent; color:#22d3ee; border:1px solid #22d3ee; border-radius:6px; padding:0.5rem 1rem; font-weight:700; }
    .pe-orig-alert { border-radius:6px; padding:0.5rem 0.9rem; font-size:0.85rem; font-weight:600; max-width:360px; }
    .pe-orig-alert.exito { background:#d1e7dd; color:#0f5132; }
    .pe-orig-alert.fallo { background:#f8d7da; color:#842029; }

    /* Vista "personalizado" */
    .pe-linea-custom { display:inline-block; padding:0.55rem 1.3rem; border-radius:14px; font-weight:900; letter-spacing:0.01em; white-space:nowrap; }
    .pe-campo-preview-wrap { display:flex; flex-direction:column; gap:0.3rem; width:220px; }
    .pe-campo-preview-wrap label { color:#8aa0b4; font-size:0.85rem; }
    .pe-campo-custom { border-radius:6px; padding:0.5rem 0.7rem; width:100%; }
    .pe-boton-custom { border-radius:6px; padding:0.5rem 1.1rem; font-weight:700; border-width:1px; border-style:solid; }
    .pe-banner-custom { display:flex; align-items:center; gap:0.75rem; border-radius:12px; padding:0.85rem 1.1rem; max-width:420px; }
    .pe-banner-custom .pe-banner-icon { font-size:1.6rem; flex-shrink:0; }
    .pe-banner-custom .pe-banner-text { flex:1 1 auto; }
    .pe-banner-custom .pe-banner-badge { flex-shrink:0; font-weight:900; font-size:0.75rem; letter-spacing:0.05em; white-space:nowrap; }
    .pe-icono-opciones .form-check { background:#222c3a; border:1px solid rgba(0,255,247,0.25); border-radius:10px; padding:0.5rem 0.9rem 0.5rem 2.2rem; }
    .pe-icono-opciones .form-check-input:checked ~ .form-check-label { color:#00fff7; }
    .pe-icono-opciones label { font-size:1.3rem; cursor:pointer; }
  </style>

  <div class="row mb-4">
    <div class="col-12 text-center">
      <p class="text-uppercase text-info mb-1">Panel</p>
      <h1 class="display-5 fw-bold text-info mb-2">Diseño de Pasos y Verificación</h1>
      <p class="text-secondary">Personaliza cómo se ven los títulos "PASO 1/2/3" y el verificador de ID en la página de recarga. Cada bloque se guarda por separado y tiene su propio interruptor para volver al diseño original en cualquier momento.</p>
    </div>
  </div>

  <?php if ($flashMessage !== ''): ?>
  <!-- Solo se ve si el guardado ocurrió sin JavaScript (respaldo) — con JS
       activado, cada tarjeta muestra su propia confirmación por AJAX. -->
  <div class="alert alert-<?= htmlspecialchars($flashType, ENT_QUOTES, 'UTF-8') ?> text-center" style="background:#181f2a;border:1px solid #00fff7;color:#e2e8f0;">
    <?= htmlspecialchars($flashMessage, ENT_QUOTES, 'UTF-8') ?>
  </div>
  <?php endif; ?>

  <?php foreach (paso_estilo_zonas_validas() as $zona): $actual = $zonasActuales[$zona]; $iconosDisponibles = paso_estilo_iconos_disponibles($zona); ?>
  <div class="pe-card" data-pe-zona="<?= $zona ?>">
    <h2 class="h5 text-info mb-3"><?= htmlspecialchars($etiquetasZonas[$zona], ENT_QUOTES, 'UTF-8') ?></h2>
    <div class="pe-flash" data-pe-flash></div>

    <div class="pe-preview-box">
      <!-- Vista "original" (se muestra cuando el modo = original) -->
      <div data-pe-view="original" style="<?= $actual['modo'] === 'original' ? '' : 'display:none;' ?>">
        <?php if (paso_estilo_zona_tiene_texto($zona)): ?>
          <span class="pe-orig-paso"><?= htmlspecialchars(paso_estilo_defaults($zona)['texto'], ENT_QUOTES, 'UTF-8') ?></span>
        <?php elseif ($zona === 'campo'): ?>
          <div class="pe-orig-campo-wrap">
            <label>ID de usuario</label>
            <input type="text" placeholder="Ej: 12345678" disabled>
          </div>
        <?php elseif ($zona === 'boton'): ?>
          <button type="button" class="pe-orig-boton" disabled>Verificar nombre del jugador</button>
        <?php elseif ($zona === 'exito'): ?>
          <div class="pe-orig-alert exito">WOWKILL verificado correctamente.</div>
        <?php else: ?>
          <div class="pe-orig-alert fallo">ID no encontrado o no válido en este servidor.</div>
        <?php endif; ?>
      </div>

      <!-- Vista "personalizado" (se actualiza en vivo, sin recargar, mientras se edita el formulario) -->
      <div data-pe-view="personalizado" style="<?= $actual['modo'] === 'personalizado' ? '' : 'display:none;' ?>">
        <?php if (paso_estilo_zona_tiene_texto($zona)): ?>
          <div class="pe-linea-custom" data-pe-target data-pe-target-texto><?= htmlspecialchars($actual['texto'], ENT_QUOTES, 'UTF-8') ?></div>
        <?php elseif ($zona === 'campo'): ?>
          <div class="pe-campo-preview-wrap">
            <label>ID de usuario</label>
            <input type="text" class="pe-campo-custom" data-pe-target placeholder="Ej: 12345678" disabled>
          </div>
        <?php elseif ($zona === 'boton'): ?>
          <button type="button" class="pe-boton-custom" data-pe-target disabled>Verificar nombre del jugador</button>
        <?php else: ?>
          <div class="pe-banner-custom" data-pe-target>
            <span class="pe-banner-icon" data-pe-target-icono><?= htmlspecialchars($actual['icono'] !== '' ? $iconosDisponibles[$actual['icono']] : '', ENT_QUOTES, 'UTF-8') ?></span>
            <span class="pe-banner-text" data-pe-target-mensaje><?= $zona === 'exito' ? '<strong>' . htmlspecialchars($mensajesEjemplo[$zona], ENT_QUOTES, 'UTF-8') . '</strong>' : htmlspecialchars($mensajesEjemplo[$zona], ENT_QUOTES, 'UTF-8') ?></span>
            <span class="pe-banner-badge"><?= htmlspecialchars($badgeEjemplo[$zona], ENT_QUOTES, 'UTF-8') ?></span>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <form data-pe-form>
      <input type="hidden" name="action" value="guardar_zona_estilo">
      <input type="hidden" name="zona" value="<?= htmlspecialchars($zona, ENT_QUOTES, 'UTF-8') ?>">

      <div class="row g-3">
        <div class="col-12">
          <div class="d-flex gap-4 flex-wrap">
            <div class="form-check">
              <input class="form-check-input" type="radio" name="modo" id="modo_original_<?= $zona ?>" value="original" data-pe-modo <?= $actual['modo'] === 'original' ? 'checked' : '' ?>>
              <label class="form-check-label text-light" for="modo_original_<?= $zona ?>">Diseño original</label>
            </div>
            <div class="form-check">
              <input class="form-check-input" type="radio" name="modo" id="modo_personalizado_<?= $zona ?>" value="personalizado" data-pe-modo <?= $actual['modo'] === 'personalizado' ? 'checked' : '' ?>>
              <label class="form-check-label text-light" for="modo_personalizado_<?= $zona ?>">Personalizado</label>
            </div>
          </div>
          <p class="text-secondary small mb-0 mt-1">Cambiar entre estas 2 opciones no borra tu diseño personalizado — puedes ir y volver cuando quieras.</p>
        </div>

        <?php if (paso_estilo_zona_tiene_texto($zona)): ?>
        <div class="col-12">
          <label class="form-label text-secondary small mb-1">Texto del título</label>
          <input type="text" name="texto" class="form-control pe-input" data-pe-campo="texto" maxlength="160" value="<?= htmlspecialchars($actual['texto'], ENT_QUOTES, 'UTF-8') ?>">
        </div>
        <?php endif; ?>

        <?php if (!empty($iconosDisponibles)): ?>
        <div class="col-12">
          <label class="form-label text-secondary small mb-1 d-block">Ícono</label>
          <div class="d-flex gap-3 flex-wrap pe-icono-opciones">
            <?php foreach ($iconosDisponibles as $clave => $emoji): ?>
              <div class="form-check">
                <input class="form-check-input" type="radio" name="icono" id="icono_<?= $zona . '_' . $clave ?>" value="<?= $clave ?>" data-pe-icono-radio <?= $actual['icono'] === $clave ? 'checked' : '' ?>>
                <label class="form-check-label" for="icono_<?= $zona . '_' . $clave ?>"><?= $emoji ?></label>
              </div>
            <?php endforeach; ?>
            <div class="form-check">
              <input class="form-check-input" type="radio" name="icono" id="icono_<?= $zona ?>_personalizado" value="personalizado" data-pe-icono-radio data-pe-icono-personalizado-radio <?= $actual['icono'] === 'personalizado' ? 'checked' : '' ?>>
              <label class="form-check-label" for="icono_<?= $zona ?>_personalizado">✏️ Personalizado</label>
            </div>
          </div>
          <div class="mt-2" data-pe-icono-personalizado-field style="<?= $actual['icono'] === 'personalizado' ? '' : 'display:none;' ?>">
            <input type="text" name="icono_personalizado" class="form-control pe-input" data-pe-campo="icono_personalizado" maxlength="8" style="width:120px;font-size:1.4rem;text-align:center;" value="<?= htmlspecialchars($actual['icono_personalizado'], ENT_QUOTES, 'UTF-8') ?>">
            <div class="form-text text-secondary">Pega o escribe cualquier emoji.</div>
          </div>
        </div>
        <?php endif; ?>

        <div class="col-12">
          <label class="form-label text-secondary small mb-1 d-block">Tipo de fondo</label>
          <div class="d-flex gap-4 flex-wrap mb-2">
            <div class="form-check">
              <input class="form-check-input" type="radio" name="fondo_tipo" id="fondo_solido_<?= $zona ?>" value="solido" data-pe-fondo-tipo <?= $actual['fondo_tipo'] === 'degradado' ? '' : 'checked' ?>>
              <label class="form-check-label text-light" for="fondo_solido_<?= $zona ?>">Sólido</label>
            </div>
            <div class="form-check">
              <input class="form-check-input" type="radio" name="fondo_tipo" id="fondo_degradado_<?= $zona ?>" value="degradado" data-pe-fondo-tipo <?= $actual['fondo_tipo'] === 'degradado' ? 'checked' : '' ?>>
              <label class="form-check-label text-light" for="fondo_degradado_<?= $zona ?>">Degradado</label>
            </div>
          </div>
          <div class="d-flex gap-4 flex-wrap align-items-end">
            <div>
              <label class="form-label text-secondary small mb-1 d-block"><span data-pe-label-fondo1><?= $actual['fondo_tipo'] === 'degradado' ? 'Color 1' : 'Color de fondo' ?></span></label>
              <input type="color" name="color_fondo" class="pe-color-input" data-pe-campo="color_fondo" value="<?= htmlspecialchars($actual['color_fondo'], ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div data-pe-fondo2-field style="<?= $actual['fondo_tipo'] === 'degradado' ? '' : 'display:none;' ?>">
              <label class="form-label text-secondary small mb-1 d-block">Color 2</label>
              <input type="color" name="color_fondo2" class="pe-color-input" data-pe-campo="color_fondo2" value="<?= htmlspecialchars($actual['color_fondo2'], ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div>
              <label class="form-label text-secondary small mb-1 d-block">Color de letra</label>
              <input type="color" name="color_texto" class="pe-color-input" data-pe-campo="color_texto" value="<?= htmlspecialchars($actual['color_texto'], ENT_QUOTES, 'UTF-8') ?>">
            </div>
          </div>
        </div>

        <div class="col-12 col-md-4">
          <label class="form-label text-secondary small mb-1">Tipo de letra</label>
          <select name="fuente_familia" class="form-select pe-input" data-pe-campo="fuente_familia">
            <?php foreach ($fuentesEtiquetas as $clave => $etiqueta): ?>
              <option value="<?= $clave ?>" <?= $actual['fuente_familia'] === $clave ? 'selected' : '' ?>><?= htmlspecialchars($etiqueta, ENT_QUOTES, 'UTF-8') ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-12 col-md-4">
          <label class="form-label text-secondary small mb-1">Tamaño de letra (rem)</label>
          <input type="number" step="0.1" min="0.6" max="3" name="fuente_tamano" class="form-control pe-input" data-pe-campo="fuente_tamano" value="<?= htmlspecialchars(rtrim($actual['fuente_tamano'], 'rem'), ENT_QUOTES, 'UTF-8') ?>">
        </div>
        <div class="col-12 col-md-4 d-flex align-items-end">
          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="borde_neon_activo" id="borde_neon_<?= $zona ?>" value="1" data-pe-campo="borde_neon_activo" <?= $actual['borde_neon_activo'] ? 'checked' : '' ?>>
            <label class="form-check-label text-light" for="borde_neon_<?= $zona ?>">Borde neón brillante</label>
          </div>
        </div>
        <div class="col-12 col-md-4" data-pe-borde-color-field style="<?= $actual['borde_neon_activo'] ? '' : 'display:none;' ?>">
          <label class="form-label text-secondary small mb-1">Color del borde neón</label>
          <input type="color" name="color_borde" class="pe-color-input" data-pe-campo="color_borde" value="<?= htmlspecialchars($actual['color_borde'], ENT_QUOTES, 'UTF-8') ?>">
        </div>
        <div class="col-12 col-md-4" data-pe-borde-color-field style="<?= $actual['borde_neon_activo'] ? '' : 'display:none;' ?>">
          <label class="form-label text-secondary small mb-1">Grosor del borde (px)</label>
          <input type="number" step="1" min="1" max="6" name="borde_grosor" class="form-control pe-input" data-pe-campo="borde_grosor" value="<?= htmlspecialchars((string) $actual['borde_grosor'], ENT_QUOTES, 'UTF-8') ?>">
        </div>
        <div class="col-12 col-md-4" data-pe-borde-color-field style="<?= $actual['borde_neon_activo'] ? '' : 'display:none;' ?>">
          <label class="form-label text-secondary small mb-1">Tamaño del brillo (px)</label>
          <input type="number" step="1" min="2" max="40" name="borde_brillo" class="form-control pe-input" data-pe-campo="borde_brillo" value="<?= htmlspecialchars((string) $actual['borde_brillo'], ENT_QUOTES, 'UTF-8') ?>">
        </div>

        <div class="col-12 col-md-4 ms-auto">
          <button type="submit" class="btn btn-info fw-bold w-100" data-pe-submit style="background:#00fff7;color:#181f2a;border:none;box-shadow:0 0 8px #00fff7;">Guardar</button>
        </div>
      </div>
    </form>
  </div>
  <?php endforeach; ?>
</main>

<script>
(function () {
  var fuentesCss = <?= json_encode($fuentesDisponibles, JSON_UNESCAPED_UNICODE) ?>;

  function actualizarVista(card) {
    var zona = card.dataset.peZona;
    var modo = card.querySelector('[data-pe-modo]:checked').value;
    var vistaOriginal = card.querySelector('[data-pe-view="original"]');
    var vistaPersonalizado = card.querySelector('[data-pe-view="personalizado"]');
    vistaOriginal.style.display = modo === 'original' ? '' : 'none';
    vistaPersonalizado.style.display = modo === 'personalizado' ? '' : 'none';

    if (modo !== 'personalizado') return;

    var target = card.querySelector('[data-pe-target]');
    if (!target) return;

    // Texto del título (solo paso1/paso2/paso3)
    var textoInput = card.querySelector('[data-pe-campo="texto"]');
    if (textoInput && target.hasAttribute('data-pe-target-texto')) {
      target.textContent = textoInput.value !== '' ? textoInput.value : target.textContent;
    }

    // Ícono elegido (solo éxito/fallo) — "personalizado" toma el emoji que
    // el admin haya escrito en su propio campo de texto, no el de la label.
    var iconoRadio = card.querySelector('[data-pe-icono-radio]:checked');
    if (iconoRadio) {
      var iconoTarget = card.querySelector('[data-pe-target-icono]');
      if (iconoTarget) {
        if (iconoRadio.value === 'personalizado') {
          var iconoPersonalizadoInput = card.querySelector('[data-pe-campo="icono_personalizado"]');
          iconoTarget.textContent = (iconoPersonalizadoInput && iconoPersonalizadoInput.value) || iconoTarget.textContent;
        } else {
          var labelEl = card.querySelector('label[for="' + iconoRadio.id + '"]');
          if (labelEl) iconoTarget.textContent = labelEl.textContent;
        }
      }
    }

    var fondoTipo = card.querySelector('[data-pe-fondo-tipo]:checked').value;
    var color1 = card.querySelector('[data-pe-campo="color_fondo"]').value;
    var color2 = card.querySelector('[data-pe-campo="color_fondo2"]').value;
    var colorTexto = card.querySelector('[data-pe-campo="color_texto"]').value;
    var fuenteClave = card.querySelector('[data-pe-campo="fuente_familia"]').value;
    var tamano = card.querySelector('[data-pe-campo="fuente_tamano"]').value || '1';
    var bordeActivo = card.querySelector('[data-pe-campo="borde_neon_activo"]').checked;
    var colorBorde = card.querySelector('[data-pe-campo="color_borde"]').value;
    var grosorBorde = parseFloat(card.querySelector('[data-pe-campo="borde_grosor"]').value) || 1;
    var brilloBorde = parseFloat(card.querySelector('[data-pe-campo="borde_brillo"]').value) || 14;

    var fondo = fondoTipo === 'degradado' ? ('linear-gradient(135deg, ' + color1 + ', ' + color2 + ')') : color1;
    var estilo = 'background:' + fondo + ';color:' + colorTexto + ';font-size:' + tamano + 'rem;';
    var fuenteCss = fuentesCss[fuenteClave];
    if (fuenteCss) estilo += 'font-family:' + fuenteCss + ';';
    if (bordeActivo) {
      estilo += 'border:' + grosorBorde + 'px solid ' + colorBorde
        + ';box-shadow:0 0 ' + brilloBorde + 'px ' + colorBorde + ', inset 0 0 ' + Math.round(brilloBorde / 2) + 'px ' + colorBorde + ';';
    } else {
      estilo += 'border:1px solid transparent;box-shadow:none;';
    }
    // El texto de color de letra de un banner (éxito/fallo) no debe pintar
    // el ícono emoji del mismo color de fondo — target ya es el contenedor
    // completo, "color" se hereda a los <span> internos, lo cual está bien
    // porque el ícono es un emoji (su color real no cambia con `color` CSS).
    target.setAttribute('style', estilo);
  }

  document.querySelectorAll('[data-pe-zona]').forEach(function (card) {
    card.addEventListener('input', function () { actualizarVista(card); });
    card.addEventListener('change', function (e) {
      if (e.target.matches('[data-pe-fondo-tipo]')) {
        var esDegradado = e.target.value === 'degradado';
        card.querySelector('[data-pe-fondo2-field]').style.display = esDegradado ? '' : 'none';
        var label1 = card.querySelector('[data-pe-label-fondo1]');
        if (label1) label1.textContent = esDegradado ? 'Color 1' : 'Color de fondo';
      }
      if (e.target.matches('[data-pe-campo="borde_neon_activo"]')) {
        card.querySelectorAll('[data-pe-borde-color-field]').forEach(function (el) {
          el.style.display = e.target.checked ? '' : 'none';
        });
      }
      if (e.target.matches('[data-pe-icono-radio]')) {
        var campoPersonalizado = card.querySelector('[data-pe-icono-personalizado-field]');
        if (campoPersonalizado) campoPersonalizado.style.display = e.target.value === 'personalizado' ? '' : 'none';
      }
      actualizarVista(card);
    });
    actualizarVista(card);

    // Guardado por AJAX: NO recarga la página, así ninguna otra tarjeta
    // pierde lo que el admin tenga a medio ajustar.
    var form = card.querySelector('[data-pe-form]');
    var flash = card.querySelector('[data-pe-flash]');
    var submitBtn = card.querySelector('[data-pe-submit]');
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      submitBtn.disabled = true;
      var textoOriginalBtn = submitBtn.textContent;
      submitBtn.textContent = 'Guardando...';
      fetch(window.location.pathname + window.location.search, {
        method: 'POST',
        body: new FormData(form),
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
      })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          flash.textContent = data.message || (data.success ? 'Guardado correctamente.' : 'No se pudo guardar.');
          flash.className = 'pe-flash show ' + (data.success ? 'ok' : 'error');
        })
        .catch(function () {
          flash.textContent = 'No se pudo guardar — revisa tu conexión e intenta de nuevo.';
          flash.className = 'pe-flash show error';
        })
        .finally(function () {
          submitBtn.disabled = false;
          submitBtn.textContent = textoOriginalBtn;
          window.setTimeout(function () { flash.classList.remove('show'); }, 4000);
        });
    });
  });
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
