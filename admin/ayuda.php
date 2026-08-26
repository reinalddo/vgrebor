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
require_once __DIR__ . '/../includes/ayuda.php';
require_once __DIR__ . '/../includes/header.php';

$flashMessage = '';
$flashType = 'success';

// Botones cuyo icono admite el modo "defecto" (= no tocar nada, dejar el
// SVG/colores originales que ya existían antes de este módulo).
$botonesConDefecto = ['soporte', 'canal'];

// ── Acción: guardar un botón (principal / soporte / canal / tutoriales) ──
// Un solo handler genérico para los 4, porque comparten los mismos campos
// (texto, tipo de ícono, emoji o imagen, colores) — solo cambia qué modos
// de ícono/color tiene disponibles cada uno.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'guardar_boton_ayuda') {
    $boton = trim((string) ($_POST['boton'] ?? ''));
    if (!in_array($boton, ayuda_botones_validos(), true)) {
        $flashMessage = 'Botón inválido.';
        $flashType = 'danger';
    } else {
        $defaults = ayuda_boton_defaults($boton);
        $tieneDefecto = in_array($boton, $botonesConDefecto, true);

        $nuevoTexto = trim((string) ($_POST['texto'] ?? ''));
        $nuevoTipo = trim((string) ($_POST['icono_tipo'] ?? ''));
        $tiposPermitidos = $tieneDefecto ? ['defecto', 'emoji', 'imagen'] : ['emoji', 'imagen'];
        if (!in_array($nuevoTipo, $tiposPermitidos, true)) {
            $nuevoTipo = $defaults['icono_tipo'];
        }
        $nuevoEmoji = trim((string) ($_POST['icono_emoji'] ?? ''));
        $quitarImagen = isset($_POST['quitar_imagen']);
        $personalizarColores = !$tieneDefecto || isset($_POST['personalizar_colores']);

        if ($nuevoTexto === '') {
            $flashMessage = 'El texto del botón no puede quedar vacío.';
            $flashType = 'danger';
        } elseif ($nuevoTipo === 'emoji' && $nuevoEmoji === '') {
            $flashMessage = 'Debes indicar un emoji para el ícono.';
            $flashType = 'danger';
        } else {
            $imagenActual = ayuda_boton_icono_imagen($boton);
            $subidaOk = true;

            if ($quitarImagen) {
                ayuda_boton_delete_image_file($imagenActual);
                $imagenActual = '';
                if ($nuevoTipo === 'imagen') {
                    $nuevoTipo = $tieneDefecto ? 'defecto' : 'emoji';
                }
            }

            if ($nuevoTipo === 'imagen' && !empty($_FILES['icono_imagen']['tmp_name'])) {
                $upload = ayuda_boton_store_image_upload($boton, $_FILES['icono_imagen']);
                if (!$upload['success']) {
                    $flashMessage = $upload['message'];
                    $flashType = 'danger';
                    $subidaOk = false;
                } else {
                    ayuda_boton_delete_image_file($imagenActual);
                    $imagenActual = $upload['path'];
                }
            }

            if ($subidaOk && $nuevoTipo === 'imagen' && $imagenActual === '') {
                $flashMessage = 'Debes subir una imagen antes de activar el modo "Imagen".';
                $flashType = 'danger';
                $subidaOk = false;
            }

            if ($subidaOk) {
                $nuevoColorFondo = '';
                $nuevoColorTexto = '';
                if ($personalizarColores) {
                    $colorFondoPost = trim((string) ($_POST['color_fondo'] ?? ''));
                    $colorTextoPost = trim((string) ($_POST['color_texto'] ?? ''));
                    $fallbackFondo = $defaults['color_fondo'] !== '' ? $defaults['color_fondo'] : '#000000';
                    $fallbackTexto = $defaults['color_texto'] !== '' ? $defaults['color_texto'] : '#ffffff';
                    $nuevoColorFondo = store_config_normalize_hex_color($colorFondoPost, $fallbackFondo);
                    $nuevoColorTexto = store_config_normalize_hex_color($colorTextoPost, $fallbackTexto);
                }

                store_config_upsert('ayuda_' . $boton . '_texto', $nuevoTexto, 'Módulo Ayuda: texto del botón "' . $boton . '".');
                store_config_upsert('ayuda_' . $boton . '_icono_tipo', $nuevoTipo, 'Módulo Ayuda: tipo de ícono del botón "' . $boton . '" (defecto/emoji/imagen).');
                store_config_upsert('ayuda_' . $boton . '_icono_emoji', $nuevoEmoji !== '' ? $nuevoEmoji : $defaults['icono_emoji'], 'Módulo Ayuda: emoji del botón "' . $boton . '".');
                store_config_upsert('ayuda_' . $boton . '_icono_imagen', $imagenActual, 'Módulo Ayuda: ruta de imagen del ícono del botón "' . $boton . '".');
                store_config_upsert('ayuda_' . $boton . '_color_fondo', $nuevoColorFondo, 'Módulo Ayuda: color de fondo del botón "' . $boton . '" (vacío = usar el estilo original).');
                store_config_upsert('ayuda_' . $boton . '_color_texto', $nuevoColorTexto, 'Módulo Ayuda: color de letra del botón "' . $boton . '" (vacío = usar el estilo original).');
                $flashMessage = 'Botón actualizado correctamente.';
                $flashType = 'success';
            }
        }
    }
}

// ── Acción: guardar la lista de videos de Tutoriales ──────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'guardar_tutoriales_videos') {
    $titulos = $_POST['tutorial_titulo'] ?? [];
    $enlaces = $_POST['tutorial_enlace'] ?? [];
    $items = [];
    if (is_array($titulos)) {
        foreach ($titulos as $i => $titulo) {
            $items[] = [
                'titulo' => (string) $titulo,
                'enlace' => (string) ($enlaces[$i] ?? ''),
            ];
        }
    }

    $resultado = ayuda_tutoriales_guardar($items);
    $flashMessage = $resultado['success'] ? 'Lista de tutoriales actualizada correctamente.' : $resultado['message'];
    $flashType = $resultado['success'] ? 'success' : 'danger';
}

// Valores actuales de cada botón (para precargar los formularios).
$botonesActuales = [];
foreach (ayuda_botones_validos() as $boton) {
    $botonesActuales[$boton] = [
        'texto' => ayuda_boton_texto($boton),
        'icono_tipo' => ayuda_boton_icono_tipo($boton),
        'icono_emoji' => ayuda_boton_icono_emoji($boton),
        'icono_imagen' => ayuda_boton_icono_imagen($boton),
        'color_fondo' => ayuda_boton_color_fondo($boton),
        'color_texto' => ayuda_boton_color_texto($boton),
    ];
}

$tutorialesActuales = ayuda_tutoriales_listar();

$etiquetasBotones = [
    'principal' => 'Botón principal (Ayuda)',
    'soporte' => 'Opción Soporte (WhatsApp)',
    'canal' => 'Opción Canal de difusión',
    'tutoriales' => 'Opción Tutoriales',
];
?>
<main class="container-lg mt-5 mb-5 px-2">
  <style>
    .ayuda-card { background:#181f2a; border:1px solid #00fff7; border-radius:14px; padding:1.4rem; margin-bottom:1.5rem; }
    .ayuda-input { background:#222c3a; color:#00fff7; border:1px solid #00fff7; }
    .ayuda-input:focus { background:#222c3a; color:#00fff7; border-color:#00fff7; box-shadow:0 0 0 0.2rem rgba(0,255,247,0.25); }
    .ayuda-color-input { width:56px; height:38px; padding:2px; background:#222c3a; border:1px solid #00fff7; border-radius:8px; }
    .ayuda-preview-pill { display:inline-flex; align-items:center; gap:0.5rem; padding:0.6rem 1rem; border-radius:999px; font-weight:700; font-size:0.9rem; border:1px solid rgba(0,255,247,0.4); white-space:nowrap; }
    .ayuda-preview-icon { width:1.3rem; height:1.3rem; object-fit:cover; border-radius:4px; }
    .ayuda-tutorial-row { background:#141c28; border:1px solid #222c3a; border-radius:10px; padding:0.9rem; margin-bottom:0.75rem; }
  </style>

  <div class="row mb-4">
    <div class="col-12 text-center">
      <p class="text-uppercase text-info mb-1">Panel</p>
      <h1 class="display-5 fw-bold text-info mb-2">Módulo Ayuda</h1>
      <p class="text-secondary">Configura el botón flotante "Ayuda" y sus 3 opciones: Soporte, Canal de difusión y Tutoriales.</p>
    </div>
  </div>

  <?php if ($flashMessage !== ''): ?>
  <div class="alert alert-<?= htmlspecialchars($flashType, ENT_QUOTES, 'UTF-8') ?> text-center" style="background:#181f2a;border:1px solid #00fff7;color:#e2e8f0;">
    <?= htmlspecialchars($flashMessage, ENT_QUOTES, 'UTF-8') ?>
  </div>
  <?php endif; ?>

  <?php foreach (ayuda_botones_validos() as $boton):
    $actual = $botonesActuales[$boton];
    $tieneDefecto = in_array($boton, $botonesConDefecto, true);
    $personalizaColoresActual = $actual['color_fondo'] !== '' || $actual['color_texto'] !== '';
    $previewFondo = $actual['color_fondo'] !== '' ? $actual['color_fondo'] : '#181f2a';
    $previewTexto = $actual['color_texto'] !== '' ? $actual['color_texto'] : '#e2e8f0';
    $colorFondoValor = $actual['color_fondo'] !== '' ? $actual['color_fondo'] : ($tieneDefecto ? '#181f2a' : $actual['color_fondo']);
    $colorTextoValor = $actual['color_texto'] !== '' ? $actual['color_texto'] : ($tieneDefecto ? '#e2e8f0' : $actual['color_texto']);
  ?>
  <div class="ayuda-card">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-2">
      <h2 class="h5 text-info mb-0"><?= htmlspecialchars($etiquetasBotones[$boton], ENT_QUOTES, 'UTF-8') ?></h2>
      <span class="ayuda-preview-pill" id="ayuda-preview-<?= $boton ?>" style="background:<?= htmlspecialchars($previewFondo, ENT_QUOTES, 'UTF-8') ?>;color:<?= htmlspecialchars($previewTexto, ENT_QUOTES, 'UTF-8') ?>;">
        <?php if ($actual['icono_tipo'] === 'imagen' && $actual['icono_imagen'] !== ''): ?>
          <img class="ayuda-preview-icon" src="<?= htmlspecialchars(app_path('/' . ltrim($actual['icono_imagen'], '/')), ENT_QUOTES, 'UTF-8') ?>" alt="">
        <?php elseif ($actual['icono_tipo'] === 'emoji'): ?>
          <span id="ayuda-preview-icono-<?= $boton ?>"><?= htmlspecialchars($actual['icono_emoji'], ENT_QUOTES, 'UTF-8') ?></span>
        <?php else: ?>
          <span>●</span>
        <?php endif; ?>
        <span id="ayuda-preview-texto-<?= $boton ?>"><?= htmlspecialchars($actual['texto'], ENT_QUOTES, 'UTF-8') ?></span>
      </span>
    </div>
    <?php if ($tieneDefecto): ?>
      <p class="text-secondary small mb-3">Si no tocas nada aquí, este botón se ve y funciona exactamente igual que antes de este módulo.</p>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data" class="row g-3">
      <input type="hidden" name="action" value="guardar_boton_ayuda">
      <input type="hidden" name="boton" value="<?= htmlspecialchars($boton, ENT_QUOTES, 'UTF-8') ?>">

      <div class="col-12 col-md-6">
        <label class="form-label text-secondary small mb-1">Texto del botón</label>
        <input type="text" maxlength="60" name="texto" class="form-control ayuda-input" data-ayuda-preview-texto="<?= $boton ?>" value="<?= htmlspecialchars($actual['texto'], ENT_QUOTES, 'UTF-8') ?>" required>
      </div>

      <div class="col-12">
        <label class="form-label text-secondary small mb-1 d-block">Ícono</label>
        <div class="d-flex gap-4 flex-wrap mb-2">
          <?php if ($tieneDefecto): ?>
          <div class="form-check">
            <input class="form-check-input" type="radio" name="icono_tipo" id="tipo_defecto_<?= $boton ?>" value="defecto" data-ayuda-tipo-radio="<?= $boton ?>" <?= $actual['icono_tipo'] === 'defecto' ? 'checked' : '' ?>>
            <label class="form-check-label text-light" for="tipo_defecto_<?= $boton ?>">Original (recomendado)</label>
          </div>
          <?php endif; ?>
          <div class="form-check">
            <input class="form-check-input" type="radio" name="icono_tipo" id="tipo_emoji_<?= $boton ?>" value="emoji" data-ayuda-tipo-radio="<?= $boton ?>" <?= $actual['icono_tipo'] === 'emoji' ? 'checked' : '' ?>>
            <label class="form-check-label text-light" for="tipo_emoji_<?= $boton ?>">Emoji</label>
          </div>
          <div class="form-check">
            <input class="form-check-input" type="radio" name="icono_tipo" id="tipo_imagen_<?= $boton ?>" value="imagen" data-ayuda-tipo-radio="<?= $boton ?>" <?= $actual['icono_tipo'] === 'imagen' ? 'checked' : '' ?>>
            <label class="form-check-label text-light" for="tipo_imagen_<?= $boton ?>">Imagen</label>
          </div>
        </div>
        <div id="ayuda-emoji-field-<?= $boton ?>" style="<?= $actual['icono_tipo'] === 'emoji' ? '' : 'display:none;' ?>">
          <input type="text" maxlength="8" name="icono_emoji" class="form-control ayuda-input" style="width:120px;font-size:1.4rem;text-align:center;" data-ayuda-preview-emoji="<?= $boton ?>" value="<?= htmlspecialchars($actual['icono_emoji'], ENT_QUOTES, 'UTF-8') ?>">
        </div>
        <div id="ayuda-imagen-field-<?= $boton ?>" style="<?= $actual['icono_tipo'] === 'imagen' ? '' : 'display:none;' ?>">
          <?php if ($actual['icono_imagen'] !== ''): ?>
            <div class="d-flex align-items-center gap-3 mb-2">
              <img src="<?= htmlspecialchars(app_path('/' . ltrim($actual['icono_imagen'], '/')), ENT_QUOTES, 'UTF-8') ?>" alt="Imagen actual" style="width:48px;height:48px;object-fit:cover;border-radius:10px;border:1px solid #00fff7;">
              <div class="form-check m-0">
                <input class="form-check-input" type="checkbox" name="quitar_imagen" id="quitar_imagen_<?= $boton ?>" value="1">
                <label class="form-check-label text-warning small" for="quitar_imagen_<?= $boton ?>">Quitar esta imagen</label>
              </div>
            </div>
          <?php endif; ?>
          <input type="file" name="icono_imagen" class="form-control ayuda-input" accept="image/jpeg,image/png,image/webp,image/gif">
          <div class="form-text text-secondary">JPG, PNG, WEBP o GIF, máximo 4 MB.<?= $actual['icono_imagen'] !== '' ? ' Deja vacío para conservar la imagen actual.' : '' ?></div>
        </div>
      </div>

      <div class="col-12">
        <?php if ($tieneDefecto): ?>
        <div class="form-check mb-2">
          <input class="form-check-input" type="checkbox" name="personalizar_colores" id="personalizar_colores_<?= $boton ?>" value="1" data-ayuda-colores-toggle="<?= $boton ?>" <?= $personalizaColoresActual ? 'checked' : '' ?>>
          <label class="form-check-label text-light" for="personalizar_colores_<?= $boton ?>">Personalizar colores (si no marcas esto, se usan los colores originales)</label>
        </div>
        <?php endif; ?>
        <div id="ayuda-colores-fields-<?= $boton ?>" class="d-flex gap-4 flex-wrap" style="<?= $tieneDefecto && !$personalizaColoresActual ? 'display:none;' : '' ?>">
          <div>
            <label class="form-label text-secondary small mb-1 d-block">Color de fondo</label>
            <input type="color" name="color_fondo" class="ayuda-color-input" data-ayuda-preview-fondo="<?= $boton ?>" value="<?= htmlspecialchars($colorFondoValor !== '' ? $colorFondoValor : '#181f2a', ENT_QUOTES, 'UTF-8') ?>">
          </div>
          <div>
            <label class="form-label text-secondary small mb-1 d-block">Color de letra</label>
            <input type="color" name="color_texto" class="ayuda-color-input" data-ayuda-preview-color="<?= $boton ?>" value="<?= htmlspecialchars($colorTextoValor !== '' ? $colorTextoValor : '#e2e8f0', ENT_QUOTES, 'UTF-8') ?>">
          </div>
        </div>
      </div>

      <div class="col-12 col-md-4">
        <button type="submit" class="btn btn-info fw-bold w-100" style="background:#00fff7;color:#181f2a;border:none;box-shadow:0 0 8px #00fff7;">Guardar</button>
      </div>
    </form>
  </div>
  <?php endforeach; ?>

  <div class="ayuda-card">
    <h2 class="h5 text-info mb-2">Videos de Tutoriales</h2>
    <p class="text-secondary small mb-3">Pega el enlace de YouTube o TikTok de cada video. Se detecta automáticamente cuál es cuál — no hace falta indicarlo.</p>
    <form method="post" class="row g-3">
      <input type="hidden" name="action" value="guardar_tutoriales_videos">
      <div id="ayuda-tutoriales-lista">
        <?php if (empty($tutorialesActuales)): $tutorialesActuales = [['titulo' => '', 'enlace' => '']]; endif; ?>
        <?php foreach ($tutorialesActuales as $video): ?>
        <div class="ayuda-tutorial-row row g-2 align-items-center">
          <div class="col-12 col-md-4">
            <input type="text" name="tutorial_titulo[]" class="form-control ayuda-input" placeholder="Título del video" maxlength="120" value="<?= htmlspecialchars($video['titulo'], ENT_QUOTES, 'UTF-8') ?>">
          </div>
          <div class="col-12 col-md-6">
            <input type="text" name="tutorial_enlace[]" class="form-control ayuda-input" placeholder="Enlace de YouTube o TikTok" value="<?= htmlspecialchars($video['enlace'], ENT_QUOTES, 'UTF-8') ?>">
          </div>
          <div class="col-12 col-md-2">
            <button type="button" class="btn btn-outline-danger btn-sm w-100" data-ayuda-quitar-fila>Quitar</button>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <div class="col-12">
        <button type="button" class="btn btn-outline-info btn-sm" id="ayuda-agregar-video">+ Agregar video</button>
      </div>
      <div class="col-12 col-md-4">
        <button type="submit" class="btn btn-info fw-bold w-100" style="background:#00fff7;color:#181f2a;border:none;box-shadow:0 0 8px #00fff7;">Guardar tutoriales</button>
      </div>
    </form>
  </div>
</main>

<template id="ayuda-tutorial-row-template">
  <div class="ayuda-tutorial-row row g-2 align-items-center">
    <div class="col-12 col-md-4">
      <input type="text" name="tutorial_titulo[]" class="form-control ayuda-input" placeholder="Título del video" maxlength="120">
    </div>
    <div class="col-12 col-md-6">
      <input type="text" name="tutorial_enlace[]" class="form-control ayuda-input" placeholder="Enlace de YouTube o TikTok">
    </div>
    <div class="col-12 col-md-2">
      <button type="button" class="btn btn-outline-danger btn-sm w-100" data-ayuda-quitar-fila>Quitar</button>
    </div>
  </div>
</template>

<script>
(function () {
  // ── Toggle emoji/imagen por botón ──
  document.querySelectorAll('[data-ayuda-tipo-radio]').forEach(function (radio) {
    radio.addEventListener('change', function () {
      if (!radio.checked) return;
      var boton = radio.dataset.ayudaTipoRadio;
      var emojiField = document.getElementById('ayuda-emoji-field-' + boton);
      var imagenField = document.getElementById('ayuda-imagen-field-' + boton);
      if (emojiField) emojiField.style.display = radio.value === 'emoji' ? '' : 'none';
      if (imagenField) imagenField.style.display = radio.value === 'imagen' ? '' : 'none';
    });
  });

  // ── Toggle "Personalizar colores" (solo soporte/canal) ──
  document.querySelectorAll('[data-ayuda-colores-toggle]').forEach(function (checkbox) {
    checkbox.addEventListener('change', function () {
      var boton = checkbox.dataset.ayudaColoresToggle;
      var fields = document.getElementById('ayuda-colores-fields-' + boton);
      if (fields) fields.style.display = checkbox.checked ? '' : 'none';
    });
  });

  // ── Vista previa en vivo ──
  function bindPreview(selector, apply) {
    document.querySelectorAll(selector).forEach(function (el) {
      el.addEventListener('input', function () { apply(el); });
    });
  }
  bindPreview('[data-ayuda-preview-texto]', function (el) {
    var span = document.getElementById('ayuda-preview-texto-' + el.dataset.ayudaPreviewTexto);
    if (span) span.textContent = el.value;
  });
  bindPreview('[data-ayuda-preview-emoji]', function (el) {
    var span = document.getElementById('ayuda-preview-icono-' + el.dataset.ayudaPreviewEmoji);
    if (span) span.textContent = el.value;
  });
  bindPreview('[data-ayuda-preview-fondo]', function (el) {
    var pill = document.getElementById('ayuda-preview-' + el.dataset.ayudaPreviewFondo);
    if (pill) pill.style.background = el.value;
  });
  bindPreview('[data-ayuda-preview-color]', function (el) {
    var pill = document.getElementById('ayuda-preview-' + el.dataset.ayudaPreviewColor);
    if (pill) pill.style.color = el.value;
  });

  // ── Repetidor de videos de Tutoriales ──
  var lista = document.getElementById('ayuda-tutoriales-lista');
  var template = document.getElementById('ayuda-tutorial-row-template');
  var agregarBtn = document.getElementById('ayuda-agregar-video');
  if (agregarBtn && lista && template) {
    agregarBtn.addEventListener('click', function () {
      lista.appendChild(template.content.cloneNode(true));
    });
  }
  if (lista) {
    lista.addEventListener('click', function (e) {
      var btn = e.target.closest('[data-ayuda-quitar-fila]');
      if (!btn) return;
      var filas = lista.querySelectorAll('.ayuda-tutorial-row');
      if (filas.length <= 1) {
        btn.closest('.ayuda-tutorial-row').querySelectorAll('input').forEach(function (i) { i.value = ''; });
        return;
      }
      btn.closest('.ayuda-tutorial-row').remove();
    });
  }
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
