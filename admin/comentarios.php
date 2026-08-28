<?php
// Panel de moderación de Comentarios/Reseñas.
// Toda la lógica de negocio vive en includes/comentarios.php — acá solo hay
// guard de sesión, despacho de acciones y presentación (mismo criterio que
// admin/referidos.php y admin/paso_estilos.php).
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
require_once __DIR__ . '/../includes/win_points.php';
require_once __DIR__ . '/../includes/comentarios.php';

$adminId = (int) ($_SESSION['auth_user']['id'] ?? 0);
$isAjax = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';
$flashMessage = '';
$flashType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = trim((string) ($_POST['accion'] ?? ''));
    $comentarioId = (int) ($_POST['comentario_id'] ?? 0);
    $resultado = null;

    switch ($accion) {
        case 'moderar':
            $resultado = comentarios_admin_moderar($mysqli, $comentarioId, (string) ($_POST['estado'] ?? ''), $adminId);
            break;

        case 'destacar':
            $resultado = comentarios_admin_destacar($mysqli, $comentarioId, $adminId, ($_POST['destacar'] ?? '0') === '1');
            break;

        case 'eliminar':
            $resultado = comentarios_admin_eliminar($mysqli, $comentarioId, $adminId);
            break;

        case 'responder':
            $resultado = comentarios_admin_responder($mysqli, $comentarioId, $adminId, (string) ($_POST['respuesta'] ?? ''));
            // El nombre del admin no viaja en el resultado de la función (esa
            // vive en includes/comentarios.php sin acceso a la sesión) — se
            // agrega acá para que la tarjeta pública (comentarios_render_seccion())
            // pueda insertar el bloque de respuesta sin recargar la página.
            if (!empty($resultado['ok'])) {
                $resultado['admin_nombre'] = trim((string) ($_SESSION['auth_user']['nombre'] ?? '')) !== ''
                    ? trim((string) $_SESSION['auth_user']['nombre'])
                    : 'Soporte';
            }
            break;

        case 'guardar_configuracion': {
            // Montos y límites editables (pedido explícito del cliente: que
            // la recompensa y el cobro se puedan cambiar sin tocar código).
            $campos = [
                'comentarios_recompensa_publicar'   => ['valor' => $_POST['recompensa_publicar'] ?? '', 'min' => 0,  'max' => 1000, 'desc' => 'Comentarios: RE Coins que gana el usuario al publicar una reseña.'],
                'comentarios_bono_destacado'        => ['valor' => $_POST['bono_destacado'] ?? '',      'min' => 0,  'max' => 1000, 'desc' => 'Comentarios: RE Coins extra cuando el admin destaca una reseña.'],
                'comentarios_penalizacion_edicion'  => ['valor' => $_POST['penalizacion_edicion'] ?? '','min' => 0,  'max' => 1000, 'desc' => 'Comentarios: RE Coins que cuesta editar una reseña ya publicada.'],
                'comentarios_min_caracteres'        => ['valor' => $_POST['min_caracteres'] ?? '',      'min' => 1,  'max' => 5000, 'desc' => 'Comentarios: mínimo de caracteres permitido.'],
                'comentarios_max_caracteres'        => ['valor' => $_POST['max_caracteres'] ?? '',      'min' => 10, 'max' => 5000, 'desc' => 'Comentarios: máximo de caracteres permitido.'],
                'comentarios_por_pagina'            => ['valor' => $_POST['por_pagina'] ?? '',          'min' => 1,  'max' => 50,   'desc' => 'Comentarios: cuántas reseñas se muestran por página en la web.'],
            ];
            $errores = [];
            foreach ($campos as $clave => $config) {
                $valor = trim((string) $config['valor']);
                if ($valor === '' || !is_numeric($valor)) {
                    $errores[] = $clave;
                    continue;
                }
                $numero = (int) $valor;
                if ($numero < $config['min'] || $numero > $config['max']) {
                    $errores[] = $clave;
                    continue;
                }
                store_config_upsert($clave, (string) $numero, $config['desc']);
            }

            // La lista negra es texto libre (una palabra por línea o separadas
            // por coma) — se guarda tal cual, la normalización la hace
            // comentarios_palabras_prohibidas() al leerla.
            store_config_upsert(
                'comentarios_palabras_prohibidas',
                trim((string) ($_POST['palabras_prohibidas'] ?? '')),
                'Comentarios: lista negra de palabras que bloquean la publicación de una reseña.'
            );

            // Validación cruzada: el mínimo no puede superar al máximo.
            if (comentarios_min_caracteres() > comentarios_max_caracteres()) {
                store_config_upsert('comentarios_min_caracteres', '15', 'Comentarios: mínimo de caracteres permitido.');
                $errores[] = 'min_mayor_que_max';
            }

            $resultado = empty($errores)
                ? ['ok' => true, 'message' => 'Configuración guardada correctamente.']
                : ['ok' => false, 'message' => 'Se guardó lo válido, pero algunos valores estaban fuera de rango y se ignoraron.'];
            break;
        }

        default:
            $resultado = ['ok' => false, 'message' => 'Acción no reconocida.'];
    }

    $flashMessage = $resultado['message'] ?? '';
    $flashType = !empty($resultado['ok']) ? 'success' : 'danger';

    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array_merge(['success' => !empty($resultado['ok'])], $resultado), JSON_UNESCAPED_UNICODE);
        exit();
    }
}

require_once __DIR__ . '/../includes/header.php';

$conteos = comentarios_admin_conteos($mysqli);
// 'todos' por defecto: las reseñas ya se publican solas (ver
// comentarios_publicar()), así que ya no tiene sentido aterrizar en
// "Pendientes" — normalmente estará vacía.
$filtroActual = trim((string) ($_GET['estado'] ?? 'todos'));
if (!in_array($filtroActual, comentarios_estados_moderacion(), true) && $filtroActual !== 'todos') {
    $filtroActual = 'todos';
}
$listado = comentarios_admin_listar($mysqli, $filtroActual === 'todos' ? '' : $filtroActual);
$resumenPublico = comentarios_resumen_calificaciones($mysqli);

$etiquetasEstado = [
    'pendiente' => 'Pendientes',
    'aprobado' => 'Aprobados',
    'rechazado' => 'Rechazados',
    'oculto' => 'Ocultos',
];
?>
<main class="container-lg mt-5 mb-5 px-2">
  <style>
    .cm-card { background:#181f2a; border:1px solid #00fff7; border-radius:14px; padding:1.4rem; margin-bottom:1.5rem; }
    .cm-input { background:#222c3a; color:#00fff7; border:1px solid #00fff7; }
    .cm-input:focus { background:#222c3a; color:#00fff7; border-color:#00fff7; box-shadow:0 0 0 0.2rem rgba(0,255,247,0.25); }
    .cm-item { background:#141c28; border:1px solid #222c3a; border-radius:12px; padding:1rem 1.2rem; margin-bottom:0.85rem; }
    .cm-item.destacado { border-color:rgba(250,204,21,0.55); box-shadow:0 0 12px rgba(250,204,21,0.12); }
    .cm-avatar { flex-shrink:0; width:42px; height:42px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-weight:800; background:rgba(0,255,247,0.12); color:#00fff7; border:1px solid rgba(0,255,247,0.4); }
    .cm-estrellas { color:#facc15; font-size:0.9rem; letter-spacing:0.08em; }
    .cm-texto { color:#e2e8f0; font-size:0.95rem; margin:0.4rem 0 0; }
    .cm-meta { color:#64748b; font-size:0.75rem; }
    .cm-badge { border-radius:999px; padding:0.15rem 0.65rem; font-size:0.7rem; font-weight:700; text-transform:uppercase; letter-spacing:0.03em; }
    .cm-estado-pendiente { background:rgba(250,204,21,0.12); color:#facc15; border:1px solid rgba(250,204,21,0.5); }
    .cm-estado-aprobado  { background:rgba(0,255,179,0.12); color:#00ffb3; border:1px solid rgba(0,255,179,0.5); }
    .cm-estado-rechazado { background:rgba(248,113,113,0.12); color:#f87171; border:1px solid rgba(248,113,113,0.5); }
    .cm-estado-oculto    { background:rgba(148,163,184,0.12); color:#94a3b8; border:1px solid rgba(148,163,184,0.5); }
    .cm-badge-destacado  { background:rgba(250,204,21,0.18); color:#facc15; border:1px solid rgba(250,204,21,0.6); }
    .cm-respuesta { background:rgba(0,255,247,0.05); border-left:3px solid #00fff7; border-radius:0 8px 8px 0; padding:0.6rem 0.9rem; margin-top:0.75rem; }
    .cm-flash { display:none; border-radius:8px; padding:0.5rem 0.9rem; font-size:0.85rem; font-weight:600; margin-bottom:1rem; }
    .cm-flash.show { display:block; }
    .cm-flash.ok { background:rgba(16,185,129,0.15); color:#34d399; border:1px solid rgba(52,211,153,0.4); }
    .cm-flash.error { background:rgba(239,68,68,0.15); color:#f87171; border:1px solid rgba(248,113,113,0.4); }
  </style>

  <div class="row mb-4">
    <div class="col-12 text-center">
      <p class="text-uppercase text-info mb-1">Panel</p>
      <h1 class="display-5 fw-bold text-info mb-2">Comentarios</h1>
      <p class="text-secondary">Las reseñas se publican solas en la página de su juego. Puedes <strong>ocultarlas</strong> o <strong>eliminarlas</strong> si hace falta, y <strong>destacar</strong> las mejores para que también aparezcan en el inicio.</p>
    </div>
  </div>

  <div class="cm-flash" data-cm-flash-global></div>

  <div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
      <div class="cm-card mb-0 text-center">
        <div class="text-secondary small text-uppercase mb-1">Calificación pública</div>
        <div class="h4 fw-bold text-warning mb-0"><?= number_format((float) $resumenPublico['promedio'], 1) ?> ★</div>
        <div class="cm-meta mt-1"><?= (int) $resumenPublico['total'] ?> reseñas visibles</div>
      </div>
    </div>
    <div class="col-6 col-lg-3">
      <div class="cm-card mb-0 text-center">
        <div class="text-secondary small text-uppercase mb-1">Pendientes</div>
        <div class="h4 fw-bold text-warning mb-0"><?= $conteos['pendiente'] ?></div>
      </div>
    </div>
    <div class="col-6 col-lg-3">
      <div class="cm-card mb-0 text-center">
        <div class="text-secondary small text-uppercase mb-1">Aprobados</div>
        <div class="h4 fw-bold text-success mb-0"><?= $conteos['aprobado'] ?></div>
      </div>
    </div>
    <div class="col-6 col-lg-3">
      <div class="cm-card mb-0 text-center">
        <div class="text-secondary small text-uppercase mb-1">Rechazados / Ocultos</div>
        <div class="h4 fw-bold text-danger mb-0"><?= $conteos['rechazado'] + $conteos['oculto'] ?></div>
      </div>
    </div>
  </div>

  <!-- ── Configuración editable ─────────────────────────────────────── -->
  <div class="cm-card">
    <h2 class="h5 text-info mb-2">Recompensas y reglas</h2>
    <p class="text-secondary small mb-3">Los RE Coins se acreditan solos al publicar. El costo de edición se le cobra al usuario; si no le alcanza el saldo, el sistema le impide editar y le avisa.</p>
    <div class="cm-flash" data-cm-flash-config></div>
    <form data-cm-form-config>
      <input type="hidden" name="accion" value="guardar_configuracion">
      <div class="row g-3">
        <div class="col-6 col-md-4">
          <label class="form-label text-secondary small mb-1">Recompensa por publicar</label>
          <div class="input-group">
            <input type="number" min="0" max="1000" name="recompensa_publicar" class="form-control cm-input" value="<?= comentarios_recompensa_publicar() ?>">
            <span class="input-group-text">RE Coins</span>
          </div>
        </div>
        <div class="col-6 col-md-4">
          <label class="form-label text-secondary small mb-1">Bono por destacar</label>
          <div class="input-group">
            <input type="number" min="0" max="1000" name="bono_destacado" class="form-control cm-input" value="<?= comentarios_bono_destacado() ?>">
            <span class="input-group-text">RE Coins</span>
          </div>
        </div>
        <div class="col-6 col-md-4">
          <label class="form-label text-secondary small mb-1">Costo de editar</label>
          <div class="input-group">
            <input type="number" min="0" max="1000" name="penalizacion_edicion" class="form-control cm-input" value="<?= comentarios_penalizacion_edicion() ?>">
            <span class="input-group-text">RE Coins</span>
          </div>
        </div>
        <div class="col-6 col-md-4">
          <label class="form-label text-secondary small mb-1">Mínimo de caracteres</label>
          <input type="number" min="1" max="5000" name="min_caracteres" class="form-control cm-input" value="<?= comentarios_min_caracteres() ?>">
        </div>
        <div class="col-6 col-md-4">
          <label class="form-label text-secondary small mb-1">Máximo de caracteres</label>
          <input type="number" min="10" max="5000" name="max_caracteres" class="form-control cm-input" value="<?= comentarios_max_caracteres() ?>">
        </div>
        <div class="col-6 col-md-4">
          <label class="form-label text-secondary small mb-1">Reseñas por página</label>
          <input type="number" min="1" max="50" name="por_pagina" class="form-control cm-input" value="<?= comentarios_por_pagina() ?>">
        </div>
        <div class="col-12">
          <label class="form-label text-secondary small mb-1">Palabras no permitidas</label>
          <textarea name="palabras_prohibidas" class="form-control cm-input" rows="3" placeholder="Una por línea, o separadas por coma"><?= htmlspecialchars((string) store_config_get('comentarios_palabras_prohibidas', ''), ENT_QUOTES, 'UTF-8') ?></textarea>
          <div class="form-text text-secondary">Si una reseña contiene alguna de estas palabras, no se publica y el usuario no recibe RE Coins. No distingue mayúsculas ni acentos, y solo bloquea la palabra completa (escribir "puta" no bloquea "computadora").</div>
        </div>
        <div class="col-12 col-md-4 ms-auto">
          <button type="submit" class="btn btn-info fw-bold w-100" style="background:#00fff7;color:#181f2a;border:none;box-shadow:0 0 8px #00fff7;">Guardar configuración</button>
        </div>
      </div>
    </form>
  </div>

  <!-- ── Pestañas ───────────────────────────────────────────────────── -->
  <div class="d-flex gap-2 mb-3 flex-wrap">
    <?php foreach ($etiquetasEstado as $estado => $etiqueta): ?>
      <a href="?estado=<?= $estado ?>" class="btn btn-sm <?= $filtroActual === $estado ? 'btn-info active' : 'btn-outline-info' ?>">
        <?= $etiqueta ?> (<?= $conteos[$estado] ?>)
      </a>
    <?php endforeach; ?>
    <a href="?estado=todos" class="btn btn-sm <?= $filtroActual === 'todos' ? 'btn-info active' : 'btn-outline-info' ?>">Todos (<?= $conteos['total'] ?>)</a>
  </div>

  <!-- ── Listado ────────────────────────────────────────────────────── -->
  <div class="cm-card">
    <?php if (empty($listado)): ?>
      <p class="text-secondary text-center mb-0">No hay comentarios en esta categoría.</p>
    <?php else: foreach ($listado as $c): ?>
      <div class="cm-item<?= $c['destacado'] ? ' destacado' : '' ?>" data-cm-item="<?= $c['id'] ?>">
        <div class="d-flex gap-3 align-items-start">
          <span class="cm-avatar"><?= htmlspecialchars(mb_strtoupper(mb_substr($c['usuario_nombre'], 0, 1)), ENT_QUOTES, 'UTF-8') ?></span>
          <div class="flex-grow-1 min-w-0">
            <div class="d-flex align-items-center gap-2 flex-wrap">
              <span class="fw-semibold text-light"><?= htmlspecialchars($c['usuario_nombre'], ENT_QUOTES, 'UTF-8') ?></span>
              <span class="cm-estrellas"><?= str_repeat('★', $c['estrellas']) . str_repeat('☆', 5 - $c['estrellas']) ?></span>
              <span class="cm-badge cm-estado-<?= $c['estado'] ?>" data-cm-estado><?= ucfirst($c['estado']) ?></span>
              <?php if ($c['destacado']): ?><span class="cm-badge cm-badge-destacado" data-cm-destacado-badge>★ Destacado</span><?php endif; ?>
            </div>
            <p class="cm-texto"><?= htmlspecialchars($c['texto'], ENT_QUOTES, 'UTF-8') ?></p>
            <div class="cm-meta mt-2">
              <?= htmlspecialchars(substr($c['creado_en'], 0, 16), ENT_QUOTES, 'UTF-8') ?>
              <?php if (!empty($c['usuario_email'])): ?> · <?= htmlspecialchars($c['usuario_email'], ENT_QUOTES, 'UTF-8') ?><?php endif; ?>
              <?php if ($c['pedido_etiqueta'] !== ''): ?> · <?= htmlspecialchars($c['pedido_etiqueta'], ENT_QUOTES, 'UTF-8') ?><?php endif; ?>
              · Pedido #<?= $c['pedido_id'] ?>
              · 👍 <?= $c['likes'] ?>
              <?php if ($c['recoins_otorgados'] > 0): ?> · <?= $c['recoins_otorgados'] ?> RE Coins otorgados<?php endif; ?>
              <?php if ($c['veces_editado'] > 0): ?> · editado <?= $c['veces_editado'] ?> vez(ces)<?php endif; ?>
            </div>

            <?php if ($c['respuesta'] !== ''): ?>
              <div class="cm-respuesta" data-cm-respuesta-vista>
                <div class="small text-info fw-bold text-uppercase" style="letter-spacing:0.1em;">Soporte oficial</div>
                <div class="text-light small mt-1"><?= htmlspecialchars($c['respuesta'], ENT_QUOTES, 'UTF-8') ?></div>
              </div>
            <?php endif; ?>

            <div class="mt-3">
              <textarea class="form-control cm-input" rows="2" data-cm-respuesta-input placeholder="Responder oficialmente (se muestra bajo la reseña). Vacío borra la respuesta."><?= htmlspecialchars($c['respuesta'], ENT_QUOTES, 'UTF-8') ?></textarea>
              <button type="button" class="btn btn-sm btn-outline-info mt-2" data-cm-accion="responder">Guardar respuesta</button>
            </div>

            <div class="d-flex gap-2 flex-wrap mt-3">
              <?php $estaOculto = in_array($c['estado'], ['oculto', 'rechazado'], true); ?>
              <button type="button" class="btn btn-sm <?= $estaOculto ? 'btn-success' : 'btn-outline-secondary' ?>" data-cm-accion="moderar" data-cm-estado-nuevo="<?= $estaOculto ? 'aprobado' : 'oculto' ?>">
                <?= $estaOculto ? 'Mostrar' : 'Ocultar' ?>
              </button>
              <button type="button" class="btn btn-sm btn-outline-warning" data-cm-accion="destacar" data-cm-destacar="<?= $c['destacado'] ? '0' : '1' ?>">
                <?= $c['destacado'] ? 'Quitar destacado' : ('Destacar' . (!$c['bono_pagado'] && comentarios_bono_destacado() > 0 ? ' (+' . comentarios_bono_destacado() . ' RE Coins)' : '')) ?>
              </button>
              <button type="button" class="btn btn-sm btn-danger ms-auto" data-cm-accion="eliminar">Eliminar</button>
            </div>
            <div class="cm-flash mt-2" data-cm-flash-item></div>
          </div>
        </div>
      </div>
    <?php endforeach; endif; ?>
  </div>
</main>

<script>
(function () {
  var endpoint = window.location.pathname + window.location.search;

  function mostrarFlash(el, texto, ok) {
    if (!el) return;
    el.textContent = texto;
    el.className = 'cm-flash show ' + (ok ? 'ok' : 'error');
    window.setTimeout(function () { el.classList.remove('show'); }, 5000);
  }

  function enviar(datos, flashEl, alTerminar) {
    var body = new FormData();
    Object.keys(datos).forEach(function (k) { body.append(k, datos[k]); });
    return fetch(endpoint, {
      method: 'POST',
      body: body,
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        mostrarFlash(flashEl, data.message || (data.success ? 'Listo.' : 'No se pudo completar la acción.'), !!data.success);
        if (typeof alTerminar === 'function') alTerminar(data);
      })
      .catch(function () {
        mostrarFlash(flashEl, 'Error de conexión. Intenta de nuevo.', false);
      });
  }

  // Acciones sobre cada comentario
  document.querySelectorAll('[data-cm-item]').forEach(function (item) {
    var comentarioId = item.dataset.cmItem;
    var flashItem = item.querySelector('[data-cm-flash-item]');

    item.querySelectorAll('[data-cm-accion]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var accion = btn.dataset.cmAccion;

        if (accion === 'eliminar' && !window.confirm('¿Eliminar este comentario? Se descontarán al usuario los RE Coins que se le otorgaron por él.')) {
          return;
        }

        var datos = { accion: accion, comentario_id: comentarioId };
        if (accion === 'moderar') {
          datos.estado = btn.dataset.cmEstadoNuevo;
        } else if (accion === 'destacar') {
          datos.destacar = btn.dataset.cmDestacar;
        } else if (accion === 'responder') {
          var input = item.querySelector('[data-cm-respuesta-input]');
          datos.respuesta = input ? input.value : '';
        }

        btn.disabled = true;
        enviar(datos, flashItem, function (data) {
          btn.disabled = false;
          if (!data.success) return;

          if (accion === 'eliminar') {
            item.style.transition = 'opacity 0.3s';
            item.style.opacity = '0';
            window.setTimeout(function () { item.remove(); }, 300);
            return;
          }

          if (accion === 'moderar') {
            var badge = item.querySelector('[data-cm-estado]');
            if (badge) {
              badge.className = 'cm-badge cm-estado-' + data.estado;
              badge.textContent = data.estado.charAt(0).toUpperCase() + data.estado.slice(1);
            }
            // El botón es un toggle Ocultar/Mostrar (ya no hay "Aprobar" ni
            // "Rechazar" por separado) — se recalcula según el nuevo estado.
            var estaOcultoAhora = data.estado === 'oculto' || data.estado === 'rechazado';
            btn.textContent = estaOcultoAhora ? 'Mostrar' : 'Ocultar';
            btn.dataset.cmEstadoNuevo = estaOcultoAhora ? 'aprobado' : 'oculto';
            btn.classList.toggle('btn-success', estaOcultoAhora);
            btn.classList.toggle('btn-outline-secondary', !estaOcultoAhora);
          }

          if (accion === 'destacar') {
            var ahoraDestacado = !!data.destacado;
            btn.dataset.cmDestacar = ahoraDestacado ? '0' : '1';
            btn.textContent = ahoraDestacado ? 'Quitar destacado' : 'Destacar';
            item.classList.toggle('destacado', ahoraDestacado);
          }

          if (accion === 'responder') {
            var vista = item.querySelector('[data-cm-respuesta-vista]');
            if (vista) {
              if (data.respuesta) {
                vista.querySelector('div:last-child').textContent = data.respuesta;
              } else {
                vista.remove();
              }
            }
          }
        });
      });
    });
  });

  // Formulario de configuración
  var formConfig = document.querySelector('[data-cm-form-config]');
  if (formConfig) {
    var flashConfig = document.querySelector('[data-cm-flash-config]');
    formConfig.addEventListener('submit', function (e) {
      e.preventDefault();
      var datos = {};
      new FormData(formConfig).forEach(function (v, k) { datos[k] = v; });
      var btn = formConfig.querySelector('button[type="submit"]');
      if (btn) btn.disabled = true;
      enviar(datos, flashConfig, function () { if (btn) btn.disabled = false; });
    });
  }
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
