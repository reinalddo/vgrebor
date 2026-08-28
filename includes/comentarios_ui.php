<?php
// Interfaz pública del sistema de Comentarios/Reseñas.
//   - comentarios_render_seccion(): la sección "Lo que opinan de nosotros".
//   - comentarios_render_modales(): el modal de escribir/editar y el pop-up
//     de bloqueo. Se imprimen una sola vez desde includes/footer.php, así
//     están disponibles en cualquier página.
//
// El filtro por estrellas y la paginación son ENLACES normales (recargan la
// página con ?resenas_estrellas=&resenas_pagina= y vuelven al ancla), no
// AJAX: así el contenido queda en el HTML para los buscadores, funciona sin
// JavaScript y no hay que duplicar la lógica de render en PHP y en JS.
require_once __DIR__ . '/comentarios.php';

if (!function_exists('comentarios_ui_estrellas')) {
    function comentarios_ui_estrellas(int $cantidad, string $clase = 'cmt-stars'): string {
        $cantidad = max(0, min(5, $cantidad));
        return '<span class="' . $clase . '" aria-label="' . $cantidad . ' de 5 estrellas">'
            . str_repeat('★', $cantidad) . '<span class="cmt-stars-off">' . str_repeat('★', 5 - $cantidad) . '</span></span>';
    }
}

// Fecha legible en español sin depender de la configuración regional del
// servidor (setlocale/IntlDateFormatter no están garantizados en Hostinger).
if (!function_exists('comentarios_ui_fecha')) {
    function comentarios_ui_fecha(string $fecha): string {
        $ts = strtotime($fecha);
        if ($ts === false) {
            return '';
        }
        $meses = ['', 'ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
        return date('j', $ts) . ' ' . ($meses[(int) date('n', $ts)] ?? '') . ' ' . date('Y', $ts);
    }
}

if (!function_exists('comentarios_ui_avatar')) {
    function comentarios_ui_avatar(array $item): string {
        $foto = trim((string) ($item['foto_perfil'] ?? ''));
        if ($foto !== '') {
            $src = preg_match('#^(?:https?:)?//#i', $foto) === 1 ? $foto : app_path('/' . ltrim($foto, '/'));
            return '<img class="cmt-avatar" src="' . htmlspecialchars($src, ENT_QUOTES, 'UTF-8') . '" alt="" loading="lazy">';
        }
        $inicial = mb_strtoupper(mb_substr(trim((string) ($item['usuario_nombre'] ?? 'U')), 0, 1, 'UTF-8'), 'UTF-8');
        return '<span class="cmt-avatar cmt-avatar-inicial">' . htmlspecialchars($inicial, ENT_QUOTES, 'UTF-8') . '</span>';
    }
}

// "Aullmaryz Guerrero" -> "Aullmaryz G." — solo para el slider del home,
// donde el espacio de cada tarjeta es chico (la sección completa de
// game.php sigue mostrando el nombre entero).
if (!function_exists('comentarios_ui_nombre_corto')) {
    function comentarios_ui_nombre_corto(string $nombreCompleto): string {
        $partes = preg_split('/\s+/', trim($nombreCompleto), -1, PREG_SPLIT_NO_EMPTY);
        if (empty($partes)) {
            return 'Usuario';
        }
        if (count($partes) === 1) {
            return $partes[0];
        }
        $inicial = mb_strtoupper(mb_substr($partes[1], 0, 1, 'UTF-8'), 'UTF-8');
        return $partes[0] . ' ' . $inicial . '.';
    }
}

// Color del anillo del avatar en el slider del home — determinístico por
// usuario (mismo color siempre, no cambia entre recargas de página) para
// que cada tarjeta se vea distinta, igual que la referencia del cliente.
if (!function_exists('comentarios_ui_color_avatar')) {
    function comentarios_ui_color_avatar(int $usuarioId): string {
        $paleta = ['#22D3EE', '#A78BFA', '#34D399', '#F472B6', '#FBBF24', '#60A5FA'];
        return $paleta[$usuarioId % count($paleta)];
    }
}

// Construye una URL conservando los demás parámetros de la página actual.
if (!function_exists('comentarios_ui_url')) {
    function comentarios_ui_url(array $cambios): string {
        $params = $_GET;
        foreach ($cambios as $clave => $valor) {
            if ($valor === null || $valor === '') {
                unset($params[$clave]);
            } else {
                $params[$clave] = $valor;
            }
        }
        $query = http_build_query($params);
        return ($query !== '' ? '?' . $query : '?') . '#resenas';
    }
}

// $juegoId > 0: sección de game.php, todo queda filtrado a reseñas de
// compras de ESE juego (destacadas y no destacadas mezcladas, igual que
// antes) — pedido explícito del cliente. 0 = comportamiento de siempre
// (toda la tienda), por si algo más además de game.php la necesita.
if (!function_exists('comentarios_render_seccion')) {
    function comentarios_render_seccion(mysqli $mysqli, int $juegoId = 0): void {
        $usuarioId = (int) ($_SESSION['auth_user']['id'] ?? 0);
        $filtro = (int) ($_GET['resenas_estrellas'] ?? 0);
        $pagina = (int) ($_GET['resenas_pagina'] ?? 1);

        $resumen = comentarios_resumen_calificaciones($mysqli, $juegoId);
        $listado = comentarios_listar_publicos($mysqli, $filtro, $pagina, $usuarioId, $juegoId);

        $puedeComentar = $usuarioId > 0 && comentarios_usuario_puede_comentar($mysqli, $usuarioId, $juegoId);
        // El botón siempre se muestra; el estado decide qué pasa al pulsarlo
        // (abrir el formulario o el pop-up explicando el requisito).
        $botonEstado = $usuarioId <= 0 ? 'invitado' : ($puedeComentar ? 'puede' : 'sin-compras');
        ?>
        <section id="resenas" class="container mt-5 mb-4" data-aos="fade-up">
          <h2 class="cmt-titulo">Lo que opinan de nosotros</h2>

          <div class="cmt-layout">
            <!-- ── Columna izquierda: calificación general ── -->
            <aside class="cmt-panel">
              <div class="cmt-panel-label">Calificación general</div>
              <div class="cmt-promedio"><?= number_format((float) $resumen['promedio'], 1) ?></div>
              <?= comentarios_ui_estrellas((int) round((float) $resumen['promedio']), 'cmt-stars cmt-stars-lg') ?>
              <div class="cmt-panel-total">Basado en <?= (int) $resumen['total'] ?> reseña<?= $resumen['total'] === 1 ? '' : 's' ?></div>

              <div class="cmt-barras">
                <?php foreach ($resumen['desglose'] as $estrella => $dato):
                  $activo = $filtro === $estrella;
                  $sinDatos = $dato['cantidad'] === 0;
                ?>
                  <a href="<?= htmlspecialchars(comentarios_ui_url(['resenas_estrellas' => $activo ? null : $estrella, 'resenas_pagina' => null]), ENT_QUOTES, 'UTF-8') ?>"
                     class="cmt-barra-fila<?= $activo ? ' activo' : '' ?><?= $sinDatos ? ' vacia' : '' ?>"
                     title="<?= $activo ? 'Quitar filtro' : 'Ver solo reseñas de ' . $estrella . ' estrellas' ?>">
                    <span class="cmt-barra-num"><?= $estrella ?></span>
                    <span class="cmt-barra-riel"><span class="cmt-barra-relleno" style="width:<?= (int) $dato['porcentaje'] ?>%;"></span></span>
                    <span class="cmt-barra-pct"><?= (int) $dato['porcentaje'] ?>%</span>
                  </a>
                <?php endforeach; ?>
              </div>

              <?php if ($filtro > 0): ?>
                <a href="<?= htmlspecialchars(comentarios_ui_url(['resenas_estrellas' => null, 'resenas_pagina' => null]), ENT_QUOTES, 'UTF-8') ?>" class="cmt-quitar-filtro">✕ Quitar filtro de <?= $filtro ?> estrellas</a>
              <?php endif; ?>

              <button type="button" class="cmt-btn-principal" data-cmt-abrir="<?= $botonEstado ?>">
                <span aria-hidden="true">✎</span> Deja un comentario
              </button>
            </aside>

            <!-- ── Columna derecha: lista de comentarios ── -->
            <div class="cmt-lista">
              <?php if (empty($listado['items'])): ?>
                <div class="cmt-vacio">
                  <?php if ($filtro > 0): ?>
                    Todavía no hay reseñas con esta calificación.
                  <?php elseif ($juegoId > 0): ?>
                    Todavía no hay reseñas de este juego. ¡Sé el primero en opinar!
                  <?php else: ?>
                    Todavía no hay reseñas publicadas. ¡Sé el primero en opinar!
                  <?php endif; ?>
                </div>
              <?php else: foreach ($listado['items'] as $item): ?>
                <article class="cmt-item<?= $item['destacado'] ? ' destacado' : '' ?>">
                  <div class="cmt-item-head">
                    <?= comentarios_ui_avatar($item) ?>
                    <div class="cmt-item-ident">
                      <div class="cmt-item-nombre">
                        <?= htmlspecialchars($item['usuario_nombre'], ENT_QUOTES, 'UTF-8') ?>
                        <span class="cmt-verificada">Verificada</span>
                        <?php if ($item['destacado']): ?><span class="cmt-destacada">★ Destacada</span><?php endif; ?>
                      </div>
                      <div class="cmt-item-meta">
                        <?= htmlspecialchars(comentarios_ui_fecha($item['creado_en']), ENT_QUOTES, 'UTF-8') ?>
                        <?php if ($item['pedido_etiqueta'] !== ''): ?>
                          <span class="cmt-sep">•</span>
                          <span class="cmt-compra"><?= htmlspecialchars($item['pedido_etiqueta'], ENT_QUOTES, 'UTF-8') ?></span>
                        <?php endif; ?>
                      </div>
                    </div>
                    <?= comentarios_ui_estrellas($item['estrellas']) ?>
                  </div>

                  <p class="cmt-item-texto"><?= htmlspecialchars($item['texto'], ENT_QUOTES, 'UTF-8') ?></p>

                  <?php if ($item['respuesta']): ?>
                    <div class="cmt-respuesta">
                      <div class="cmt-respuesta-head">
                        <span class="cmt-respuesta-avatar">GM</span>
                        <span class="cmt-respuesta-nombre"><?= htmlspecialchars($item['respuesta']['admin_nombre'], ENT_QUOTES, 'UTF-8') ?></span>
                        <span class="cmt-respuesta-badge">Soporte oficial</span>
                      </div>
                      <p class="cmt-respuesta-texto"><?= htmlspecialchars($item['respuesta']['texto'], ENT_QUOTES, 'UTF-8') ?></p>
                    </div>
                  <?php endif; ?>

                  <div class="cmt-item-pie">
                    <button type="button" class="cmt-util<?= $item['yo_di_like'] ? ' activo' : '' ?>"
                            data-cmt-like="<?= (int) $item['id'] ?>"
                            data-cmt-logueado="<?= $usuarioId > 0 ? '1' : '0' ?>">
                      <span aria-hidden="true">👍</span> Me gusta <span class="cmt-util-n"><?= (int) $item['likes'] > 0 ? '(' . (int) $item['likes'] . ')' : '' ?></span>
                    </button>
                  </div>
                </article>
              <?php endforeach; endif; ?>

              <?php if ($listado['paginas'] > 1): ?>
                <nav class="cmt-paginacion" aria-label="Paginación de reseñas">
                  <a class="cmt-pag-btn<?= $listado['pagina'] <= 1 ? ' deshabilitado' : '' ?>"
                     href="<?= $listado['pagina'] <= 1 ? '#resenas' : htmlspecialchars(comentarios_ui_url(['resenas_pagina' => $listado['pagina'] - 1]), ENT_QUOTES, 'UTF-8') ?>">‹ Anterior</a>

                  <?php
                  // Ventana de páginas: 1 … (actual-1, actual, actual+1) … última
                  $paginas = [];
                  for ($p = 1; $p <= $listado['paginas']; $p++) {
                      if ($p === 1 || $p === $listado['paginas'] || abs($p - $listado['pagina']) <= 1) {
                          $paginas[] = $p;
                      }
                  }
                  $anterior = 0;
                  foreach ($paginas as $p):
                      if ($anterior && $p - $anterior > 1): ?><span class="cmt-pag-puntos">…</span><?php endif;
                      $anterior = $p;
                  ?>
                    <a class="cmt-pag-num<?= $p === $listado['pagina'] ? ' actual' : '' ?>"
                       href="<?= htmlspecialchars(comentarios_ui_url(['resenas_pagina' => $p]), ENT_QUOTES, 'UTF-8') ?>"><?= $p ?></a>
                  <?php endforeach; ?>

                  <a class="cmt-pag-btn<?= $listado['pagina'] >= $listado['paginas'] ? ' deshabilitado' : '' ?>"
                     href="<?= $listado['pagina'] >= $listado['paginas'] ? '#resenas' : htmlspecialchars(comentarios_ui_url(['resenas_pagina' => $listado['pagina'] + 1]), ENT_QUOTES, 'UTF-8') ?>">Siguiente ›</a>
                </nav>
                <div class="cmt-pag-info">Página <?= $listado['pagina'] ?> de <?= $listado['paginas'] ?> (<?= $listado['total'] ?> reseñas)</div>
              <?php endif; ?>
            </div>
          </div>
        </section>

        <style>
          .cmt-titulo { font-family:'Oxanium',sans-serif; font-weight:800; font-size:clamp(1.3rem,2.6vw,1.9rem); letter-spacing:0.03em; color:var(--theme-text); text-transform:uppercase; margin-bottom:1.2rem; }
          .cmt-layout { display:grid; grid-template-columns:minmax(240px,300px) 1fr; gap:1.25rem; align-items:start; }
          @media (max-width:860px) { .cmt-layout { grid-template-columns:1fr; } }

          /* Panel de calificación */
          .cmt-panel { background:var(--theme-panel-gradient); border:1px solid rgba(var(--theme-primary-rgb),0.22); border-radius:16px; padding:1.4rem 1.2rem; text-align:center; position:sticky; top:1rem; }
          @media (max-width:860px) { .cmt-panel { position:static; } }
          .cmt-panel-label { font-size:0.72rem; text-transform:uppercase; letter-spacing:0.18em; color:var(--theme-text-muted); }
          .cmt-promedio { font-family:'Oxanium',sans-serif; font-weight:800; font-size:3.4rem; line-height:1; color:var(--theme-warning); margin:0.4rem 0 0.3rem; text-shadow:0 0 22px rgba(var(--theme-warning-rgb),0.35); }
          .cmt-panel-total { font-size:0.75rem; color:var(--theme-text-muted); text-transform:uppercase; letter-spacing:0.08em; margin-top:0.4rem; }
          .cmt-stars { color:var(--theme-warning); letter-spacing:0.12em; white-space:nowrap; }
          .cmt-stars-lg { font-size:1.3rem; }
          .cmt-stars-off { color:rgba(var(--theme-text-muted-rgb),0.32); }

          .cmt-barras { display:flex; flex-direction:column; gap:0.35rem; margin:1.1rem 0 1.2rem; }
          .cmt-barra-fila { display:flex; align-items:center; gap:0.5rem; text-decoration:none; padding:0.18rem 0.35rem; border-radius:8px; border:1px solid transparent; transition:background 0.15s, border-color 0.15s; }
          .cmt-barra-fila:hover { background:rgba(var(--theme-primary-rgb),0.07); border-color:rgba(var(--theme-primary-rgb),0.3); }
          .cmt-barra-fila.activo { background:rgba(var(--theme-primary-rgb),0.12); border-color:rgba(var(--theme-primary-rgb),0.55); }
          .cmt-barra-fila.vacia { opacity:0.5; }
          .cmt-barra-num { color:var(--theme-text-muted); font-size:0.8rem; width:0.8rem; }
          .cmt-barra-riel { flex:1; height:7px; background:rgba(var(--theme-text-muted-rgb),0.16); border-radius:999px; overflow:hidden; }
          .cmt-barra-relleno { display:block; height:100%; background:var(--theme-primary); border-radius:999px; box-shadow:0 0 8px rgba(var(--theme-primary-rgb),0.5); }
          .cmt-barra-pct { color:var(--theme-text-muted); font-size:0.72rem; width:2.4rem; text-align:right; font-variant-numeric:tabular-nums; }
          .cmt-quitar-filtro { display:block; font-size:0.75rem; color:var(--theme-primary); text-decoration:none; margin-bottom:0.9rem; }

          .cmt-btn-principal { width:100%; border:none; border-radius:12px; padding:0.8rem 1rem; font-weight:800; font-size:0.95rem; color:var(--theme-button-text); background:var(--theme-primary); box-shadow:0 0 18px rgba(var(--theme-primary-rgb),0.45); cursor:pointer; transition:filter 0.15s, transform 0.15s; }
          .cmt-btn-principal:hover { filter:brightness(1.08); transform:translateY(-1px); }

          /* Lista */
          .cmt-lista { display:flex; flex-direction:column; gap:0.85rem; min-width:0; }
          .cmt-vacio { background:var(--theme-panel-gradient); border:1px dashed rgba(var(--theme-primary-rgb),0.28); border-radius:14px; padding:2.2rem 1rem; text-align:center; color:var(--theme-text-muted); }
          .cmt-item { background:var(--theme-panel-gradient); border:1px solid rgba(var(--theme-primary-rgb),0.18); border-radius:14px; padding:1rem 1.15rem; }
          .cmt-item.destacado { border-color:rgba(var(--theme-warning-rgb),0.5); box-shadow:0 0 16px rgba(var(--theme-warning-rgb),0.12); }
          .cmt-item-head { display:flex; align-items:flex-start; gap:0.75rem; }
          .cmt-avatar { flex-shrink:0; width:46px; height:46px; border-radius:50%; object-fit:cover; border:1px solid rgba(var(--theme-primary-rgb),0.45); }
          .cmt-avatar-inicial { display:flex; align-items:center; justify-content:center; font-family:'Oxanium',sans-serif; font-weight:800; font-size:1.15rem; color:var(--theme-primary); background:rgba(var(--theme-bg-main-rgb),0.55); }
          .cmt-item-ident { flex:1; min-width:0; }
          .cmt-item-nombre { font-weight:700; color:var(--theme-text); display:flex; align-items:center; gap:0.45rem; flex-wrap:wrap; font-size:0.98rem; }
          .cmt-verificada { font-size:0.6rem; text-transform:uppercase; letter-spacing:0.08em; font-weight:800; color:var(--theme-primary); border:1px solid rgba(var(--theme-primary-rgb),0.55); border-radius:6px; padding:0.1rem 0.4rem; }
          .cmt-destacada { font-size:0.6rem; text-transform:uppercase; letter-spacing:0.08em; font-weight:800; color:var(--theme-warning); border:1px solid rgba(var(--theme-warning-rgb),0.55); border-radius:6px; padding:0.1rem 0.4rem; }
          .cmt-item-meta { font-size:0.76rem; color:var(--theme-text-muted); margin-top:0.15rem; }
          .cmt-sep { opacity:0.5; margin:0 0.2rem; }
          .cmt-compra { color:var(--theme-primary); font-weight:600; }
          .cmt-item-texto { color:var(--theme-text); margin:0.7rem 0 0; font-size:0.93rem; line-height:1.5; overflow-wrap:anywhere; }

          /* Respuesta oficial del admin: acento morado-azul fijo (no ligado al
             tema del tenant) para que se distinga siempre de todo lo demás
             de la reseña, igual que la referencia del cliente. */
          .cmt-respuesta { margin-top:0.8rem; background:linear-gradient(135deg, #7C3AED, #3B82F6); border:1px solid rgba(124,58,237,0.55); border-left-width:3px; border-radius:0 10px 10px 0; padding:0.7rem 0.9rem; }
          .cmt-respuesta-head { display:flex; align-items:center; gap:0.5rem; flex-wrap:wrap; }
          .cmt-respuesta-avatar { font-size:0.62rem; font-weight:800; letter-spacing:0.05em; background:rgba(255,255,255,0.22); color:#fff; border-radius:5px; padding:0.1rem 0.35rem; }
          .cmt-respuesta-nombre { font-weight:700; color:#fff; font-size:0.85rem; }
          .cmt-respuesta-badge { font-size:0.58rem; text-transform:uppercase; letter-spacing:0.08em; font-weight:800; color:#fff; border:1px solid rgba(255,255,255,0.55); border-radius:5px; padding:0.08rem 0.35rem; }
          .cmt-respuesta-texto { margin:0.45rem 0 0; font-size:0.86rem; color:rgba(255,255,255,0.92); line-height:1.5; overflow-wrap:anywhere; }

          .cmt-item-pie { margin-top:0.8rem; padding-top:0.7rem; border-top:1px solid rgba(var(--theme-text-muted-rgb),0.12); }
          .cmt-util { background:transparent; border:1px solid rgba(var(--theme-text-muted-rgb),0.3); border-radius:999px; color:var(--theme-text-muted); font-size:0.78rem; font-weight:600; padding:0.32rem 0.85rem; cursor:pointer; transition:border-color 0.15s, color 0.15s; }
          .cmt-util:hover { border-color:var(--theme-primary); color:var(--theme-primary); }
          .cmt-util.activo { border-color:var(--theme-primary); color:var(--theme-primary); background:rgba(var(--theme-primary-rgb),0.1); }

          .cmt-paginacion { display:flex; align-items:center; justify-content:center; gap:0.35rem; flex-wrap:wrap; margin-top:0.6rem; padding:0.6rem; background:var(--theme-panel-gradient); border:1px solid rgba(var(--theme-primary-rgb),0.16); border-radius:999px; }
          .cmt-pag-btn, .cmt-pag-num { text-decoration:none; color:var(--theme-text-muted); font-size:0.82rem; font-weight:600; padding:0.35rem 0.7rem; border-radius:999px; }
          .cmt-pag-btn:hover, .cmt-pag-num:hover { color:var(--theme-primary); background:rgba(var(--theme-primary-rgb),0.1); }
          .cmt-pag-btn.deshabilitado { opacity:0.35; pointer-events:none; }
          .cmt-pag-num.actual { background:var(--theme-warning); color:#1a1206; font-weight:800; box-shadow:0 0 12px rgba(var(--theme-warning-rgb),0.45); }
          .cmt-pag-puntos { color:var(--theme-text-muted); opacity:0.6; }
          .cmt-pag-info { text-align:center; font-size:0.72rem; color:var(--theme-text-muted); text-transform:uppercase; letter-spacing:0.08em; margin-top:0.5rem; }
        </style>
        <?php
    }
}

// ─────────────────────────────────────────────────────────────────────────
// Slider del home: solo reseñas DESTACADAS de cualquier juego, sin panel de
// calificación ni paginación — la lista completa filtrada por juego vive
// ahora en comentarios_render_seccion(), llamada desde cada game.php.
// ─────────────────────────────────────────────────────────────────────────
if (!function_exists('comentarios_render_destacados_home')) {
    function comentarios_render_destacados_home(mysqli $mysqli): void {
        $resumen = comentarios_resumen_calificaciones($mysqli);
        $destacados = comentarios_destacados_home($mysqli);

        // Vitrina de marketing: si todavía no hay ninguna reseña destacada,
        // no tiene sentido mostrar un slider vacío en el home — el CTA para
        // "sé el primero en opinar" ya vive en cada game.php.
        if (empty($destacados)) {
            return;
        }
        ?>
        <section id="resenas" class="container mt-5 mb-4" data-aos="fade-up">
          <div class="cmt-home-head">
            <h2 class="cmt-home-titulo">Lo que dicen nuestros clientes</h2>
            <?php if ($resumen['total'] > 0): ?>
              <div class="cmt-home-resumen">
                <?= comentarios_ui_estrellas((int) round((float) $resumen['promedio']), 'cmt-stars cmt-home-resumen-stars') ?>
                <strong><?= number_format((float) $resumen['promedio'], 1) ?></strong>
                <span class="cmt-sep">·</span>
                <?= number_format((int) $resumen['total'], 0, ',', '.') ?> reseña<?= $resumen['total'] === 1 ? '' : 's' ?>
              </div>
            <?php endif; ?>
          </div>

          <div class="cmt-home-slider" data-cmt-home-slider>
            <?php foreach ($destacados as $item): ?>
              <article class="cmt-home-slide">
                <div class="cmt-home-slide-head">
                  <span class="cmt-home-slide-avatar" style="border-color:<?= htmlspecialchars(comentarios_ui_color_avatar($item['usuario_id']), ENT_QUOTES, 'UTF-8') ?>;color:<?= htmlspecialchars(comentarios_ui_color_avatar($item['usuario_id']), ENT_QUOTES, 'UTF-8') ?>;"><?= htmlspecialchars(mb_strtoupper(mb_substr($item['usuario_nombre'], 0, 1, 'UTF-8'), 'UTF-8'), ENT_QUOTES, 'UTF-8') ?></span>
                  <span class="cmt-home-slide-nombre"><?= htmlspecialchars(comentarios_ui_nombre_corto($item['usuario_nombre']), ENT_QUOTES, 'UTF-8') ?></span>
                </div>
                <?= comentarios_ui_estrellas($item['estrellas'], 'cmt-stars cmt-home-slide-stars') ?>
                <p class="cmt-home-slide-texto">&ldquo;<?= htmlspecialchars($item['texto'], ENT_QUOTES, 'UTF-8') ?>&rdquo;</p>
              </article>
            <?php endforeach; ?>
          </div>
        </section>

        <style>
          .cmt-home-head { display:flex; align-items:baseline; justify-content:space-between; gap:1rem; flex-wrap:wrap; margin-bottom:1rem; }
          .cmt-home-titulo { font-family:'Oxanium',sans-serif; font-weight:800; font-size:clamp(1.2rem,2.4vw,1.7rem); letter-spacing:0.03em; color:var(--theme-text); text-transform:uppercase; margin:0; }
          .cmt-home-resumen { display:flex; align-items:center; gap:0.4rem; font-size:0.85rem; color:var(--theme-text-muted); white-space:nowrap; }
          .cmt-home-resumen strong { color:var(--theme-text); }
          .cmt-home-resumen-stars { font-size:0.85rem; }

          .cmt-home-slider { display:flex; gap:1rem; overflow-x:auto; padding-bottom:0.4rem; scroll-snap-type:x proximity; scrollbar-width:none; }
          .cmt-home-slider::-webkit-scrollbar { display:none; }
          .cmt-home-slide { flex:0 0 auto; width:clamp(210px,26vw,270px); scroll-snap-align:start; background:var(--theme-panel-gradient); border:1px solid rgba(var(--theme-primary-rgb),0.18); border-radius:14px; padding:1rem 1.1rem; }
          .cmt-home-slide-head { display:flex; align-items:center; gap:0.6rem; margin-bottom:0.4rem; }
          .cmt-home-slide-avatar { flex-shrink:0; width:38px; height:38px; border-radius:50%; border:1.5px solid; display:flex; align-items:center; justify-content:center; font-family:'Oxanium',sans-serif; font-weight:800; font-size:0.95rem; }
          .cmt-home-slide-nombre { font-weight:700; color:var(--theme-text); font-size:0.92rem; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
          .cmt-home-slide-stars { font-size:0.78rem; }
          .cmt-home-slide-texto { margin:0.5rem 0 0; font-size:0.85rem; line-height:1.45; color:var(--theme-text-muted); display:-webkit-box; -webkit-line-clamp:3; -webkit-box-orient:vertical; overflow:hidden; }
        </style>
        <?php
    }
}

// ─────────────────────────────────────────────────────────────────────────
// Modales (se imprimen una sola vez, desde includes/footer.php)
// ─────────────────────────────────────────────────────────────────────────
// $juegoId > 0 (pasado desde includes/footer.php cuando $game está en
// contexto, ver game.php): el modal solo ofrece pedidos/reseñas propias de
// ESE juego — el botón "Deja un comentario" debe funcionar solo para el
// juego actual, pedido explícito del cliente.
if (!function_exists('comentarios_render_modales')) {
    function comentarios_render_modales(mysqli $mysqli, int $juegoId = 0): void {
        static $yaImpreso = false;
        if ($yaImpreso) {
            return;
        }
        $yaImpreso = true;

        $usuarioId = (int) ($_SESSION['auth_user']['id'] ?? 0);
        $pedidos = $usuarioId > 0 ? comentarios_pedidos_disponibles($mysqli, $usuarioId, 20, $juegoId) : [];
        $mis = $usuarioId > 0 ? comentarios_mis_comentarios($mysqli, $usuarioId, $juegoId) : [];
        $apiUrl = app_path('/api/comentarios.php');
        ?>
        <!-- Modal: escribir / editar reseña -->
        <div id="cmt-modal" class="cmt-overlay d-none" role="dialog" aria-modal="true" aria-labelledby="cmt-modal-titulo">
          <div class="cmt-overlay-fondo" data-cmt-cerrar></div>
          <div class="cmt-dialogo">
            <div class="cmt-dialogo-head">
              <div>
                <div class="cmt-dialogo-eyebrow">Tu opinión</div>
                <h3 class="cmt-dialogo-titulo" id="cmt-modal-titulo">Deja un comentario</h3>
              </div>
              <button type="button" class="cmt-cerrar" data-cmt-cerrar aria-label="Cerrar">✕</button>
            </div>
            <div class="cmt-dialogo-body">
              <div class="cmt-aviso d-none" data-cmt-aviso></div>

              <form id="cmt-form" novalidate>
                <label class="cmt-label">¿Sobre cuál de tus compras?</label>
                <select class="cmt-select" name="pedido_id" data-cmt-pedido>
                  <?php foreach ($pedidos as $p): ?>
                    <option value="<?= (int) $p['id'] ?>"><?= htmlspecialchars($p['etiqueta'] !== '' ? $p['etiqueta'] : ('Pedido #' . $p['id']), ENT_QUOTES, 'UTF-8') ?></option>
                  <?php endforeach; ?>
                </select>

                <?php if (!empty($mis)): ?>
                  <div class="cmt-mis">
                    <div class="cmt-label mb-1">O edita una reseña que ya publicaste</div>
                    <?php foreach ($mis as $m): ?>
                      <button type="button" class="cmt-mi-item" data-cmt-editar="<?= (int) $m['id'] ?>"
                              data-cmt-estrellas="<?= (int) $m['estrellas'] ?>"
                              data-cmt-texto="<?= htmlspecialchars($m['texto'], ENT_QUOTES, 'UTF-8') ?>">
                        <span class="cmt-mi-estrellas"><?= str_repeat('★', (int) $m['estrellas']) ?></span>
                        <span class="cmt-mi-texto"><?= htmlspecialchars(mb_substr($m['texto'], 0, 60), ENT_QUOTES, 'UTF-8') ?><?= mb_strlen($m['texto']) > 60 ? '…' : '' ?></span>
                        <span class="cmt-mi-estado cmt-mi-<?= htmlspecialchars($m['estado'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(ucfirst($m['estado']), ENT_QUOTES, 'UTF-8') ?></span>
                      </button>
                    <?php endforeach; ?>
                    <div class="cmt-nota">Editar una reseña publicada cuesta <?= comentarios_penalizacion_edicion() ?> RE Coins.</div>
                  </div>
                <?php endif; ?>

                <label class="cmt-label mt-3">Tu calificación</label>
                <div class="cmt-estrellas-selector" data-cmt-selector>
                  <?php for ($i = 1; $i <= 5; $i++): ?>
                    <button type="button" class="cmt-estrella" data-cmt-estrella="<?= $i ?>" aria-label="<?= $i ?> estrella<?= $i > 1 ? 's' : '' ?>">★</button>
                  <?php endfor; ?>
                </div>
                <input type="hidden" name="estrellas" value="5" data-cmt-estrellas-valor>

                <label class="cmt-label mt-3" for="cmt-texto">Tu comentario</label>
                <textarea id="cmt-texto" class="cmt-textarea" name="texto" rows="4"
                          minlength="<?= comentarios_min_caracteres() ?>" maxlength="<?= comentarios_max_caracteres() ?>"
                          placeholder="Cuéntanos cómo te fue con tu recarga..."></textarea>
                <div class="cmt-contador"><span data-cmt-contador>0</span>/<?= comentarios_max_caracteres() ?> · mínimo <?= comentarios_min_caracteres() ?></div>

                <button type="submit" class="cmt-btn-principal mt-3" data-cmt-enviar>Publicar comentario</button>
                <div class="cmt-nota mt-2">Tu reseña la revisa el administrador antes de publicarse. Al publicarla ganas <?= comentarios_recompensa_publicar() ?> RE Coins.</div>
              </form>
            </div>
          </div>
        </div>

        <!-- Modal: notificaciones del usuario -->
        <div id="cmt-notificaciones" class="cmt-overlay d-none" role="dialog" aria-modal="true" aria-labelledby="cmt-notif-titulo">
          <div class="cmt-overlay-fondo" data-cmt-cerrar-notif></div>
          <div class="cmt-dialogo">
            <div class="cmt-dialogo-head">
              <div>
                <div class="cmt-dialogo-eyebrow">Mi cuenta</div>
                <h3 class="cmt-dialogo-titulo" id="cmt-notif-titulo">Notificaciones</h3>
              </div>
              <button type="button" class="cmt-cerrar" data-cmt-cerrar-notif aria-label="Cerrar">✕</button>
            </div>
            <div class="cmt-dialogo-body">
              <div data-cmt-notif-lista class="cmt-notif-lista">
                <div class="cmt-notif-cargando">Cargando...</div>
              </div>
            </div>
          </div>
        </div>

        <!-- Pop-up: no cumple los requisitos -->
        <div id="cmt-bloqueo" class="cmt-overlay d-none" role="dialog" aria-modal="true">
          <div class="cmt-overlay-fondo" data-cmt-cerrar-bloqueo></div>
          <div class="cmt-dialogo cmt-dialogo-chico">
            <button type="button" class="cmt-cerrar cmt-cerrar-flotante" data-cmt-cerrar-bloqueo aria-label="Cerrar">✕</button>
            <div class="cmt-bloqueo-body">
              <div class="cmt-bloqueo-icono" aria-hidden="true">💬</div>
              <h3 class="cmt-dialogo-titulo">¡Queremos saber tu experiencia!</h3>
              <p class="cmt-bloqueo-texto" data-cmt-bloqueo-texto>
                Realiza tu primera recarga para poder dejar un comentario y ayudar a otros.
              </p>
              <div class="cmt-bloqueo-acciones" data-cmt-bloqueo-acciones></div>
            </div>
          </div>
        </div>

        <style>
          .cmt-overlay { position:fixed; inset:0; z-index:13300; display:flex; align-items:flex-start; justify-content:center; padding:1rem; overflow-y:auto; }
          @media (min-width:768px) { .cmt-overlay { align-items:center; } }
          .cmt-overlay.d-none { display:none !important; }
          .cmt-overlay-fondo { position:absolute; inset:0; background:var(--theme-overlay-soft); backdrop-filter:blur(6px); }
          .cmt-dialogo { position:relative; z-index:1; width:100%; max-width:520px; background:var(--theme-panel-gradient); border:1px solid rgba(var(--theme-primary-rgb),0.5); border-radius:18px; box-shadow:0 0 34px var(--theme-primary-glow); overflow:hidden; }
          .cmt-dialogo-chico { max-width:420px; }
          .cmt-dialogo-head { display:flex; align-items:center; justify-content:space-between; gap:1rem; padding:1rem 1.3rem; border-bottom:1px solid rgba(var(--theme-primary-rgb),0.22); }
          .cmt-dialogo-eyebrow { font-size:0.65rem; text-transform:uppercase; letter-spacing:0.3em; color:var(--theme-primary); }
          .cmt-dialogo-titulo { font-family:'Oxanium',sans-serif; font-weight:800; font-size:1.25rem; color:var(--theme-text); margin:0.2rem 0 0; }
          .cmt-cerrar { background:transparent; border:1px solid rgba(var(--theme-primary-rgb),0.5); color:var(--theme-primary); border-radius:50%; width:38px; height:38px; font-size:1rem; cursor:pointer; flex-shrink:0; }
          .cmt-cerrar:hover { background:rgba(var(--theme-primary-rgb),0.12); }
          .cmt-cerrar-flotante { position:absolute; top:0.8rem; right:0.8rem; z-index:2; }
          .cmt-dialogo-body { padding:1.2rem 1.3rem 1.4rem; }
          .cmt-label { display:block; font-size:0.72rem; text-transform:uppercase; letter-spacing:0.14em; color:var(--theme-primary); margin-bottom:0.35rem; }
          .cmt-select, .cmt-textarea { width:100%; background:rgba(var(--theme-bg-main-rgb),0.6); color:var(--theme-text); border:1px solid rgba(var(--theme-primary-rgb),0.4); border-radius:10px; padding:0.6rem 0.8rem; font-size:0.9rem; }
          .cmt-select:focus, .cmt-textarea:focus { outline:none; border-color:var(--theme-primary); box-shadow:0 0 0 3px rgba(var(--theme-primary-rgb),0.18); }
          .cmt-textarea { resize:vertical; }
          .cmt-contador { text-align:right; font-size:0.72rem; color:var(--theme-text-muted); margin-top:0.25rem; }
          .cmt-estrellas-selector { display:flex; gap:0.3rem; }
          .cmt-estrella { background:transparent; border:none; font-size:1.9rem; line-height:1; cursor:pointer; color:rgba(var(--theme-text-muted-rgb),0.32); padding:0; transition:color 0.12s, transform 0.12s; }
          .cmt-estrella:hover { transform:scale(1.12); }
          .cmt-estrella.activa { color:var(--theme-warning); text-shadow:0 0 12px rgba(var(--theme-warning-rgb),0.5); }
          .cmt-nota { font-size:0.74rem; color:var(--theme-text-muted); }
          .cmt-aviso { border-radius:10px; padding:0.6rem 0.85rem; font-size:0.83rem; font-weight:600; margin-bottom:0.9rem; }
          .cmt-aviso.ok { background:rgba(var(--theme-success-rgb),0.14); color:var(--theme-success); border:1px solid rgba(var(--theme-success-rgb),0.45); }
          .cmt-aviso.error { background:rgba(var(--theme-danger-rgb),0.14); color:var(--theme-danger); border:1px solid rgba(var(--theme-danger-rgb),0.45); }
          .cmt-mis { margin-top:0.9rem; display:flex; flex-direction:column; gap:0.35rem; }
          .cmt-mi-item { display:flex; align-items:center; gap:0.5rem; text-align:left; background:rgba(var(--theme-bg-main-rgb),0.45); border:1px solid rgba(var(--theme-primary-rgb),0.25); border-radius:9px; padding:0.45rem 0.6rem; cursor:pointer; color:var(--theme-text-muted); font-size:0.8rem; }
          .cmt-mi-item:hover { border-color:var(--theme-primary); color:var(--theme-text); }
          .cmt-mi-estrellas { color:var(--theme-warning); flex-shrink:0; }
          .cmt-mi-texto { flex:1; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
          .cmt-mi-estado { font-size:0.6rem; text-transform:uppercase; letter-spacing:0.05em; border-radius:5px; padding:0.08rem 0.35rem; flex-shrink:0; }
          .cmt-mi-aprobado { color:var(--theme-success); border:1px solid rgba(var(--theme-success-rgb),0.5); }
          .cmt-mi-pendiente { color:var(--theme-warning); border:1px solid rgba(var(--theme-warning-rgb),0.5); }
          .cmt-mi-rechazado, .cmt-mi-oculto { color:var(--theme-danger); border:1px solid rgba(var(--theme-danger-rgb),0.5); }
          .cmt-bloqueo-body { padding:2rem 1.6rem 1.6rem; text-align:center; }
          .cmt-bloqueo-icono { font-size:2.6rem; margin-bottom:0.6rem; }
          .cmt-bloqueo-texto { color:var(--theme-text-muted); font-size:0.92rem; line-height:1.5; margin:0.6rem 0 1.2rem; }
          .cmt-bloqueo-acciones { display:flex; flex-direction:column; gap:0.5rem; }
          .cmt-bloqueo-acciones .cmt-btn-principal { margin:0; }
          .cmt-btn-secundario { width:100%; border-radius:12px; padding:0.7rem 1rem; font-weight:700; font-size:0.9rem; background:transparent; color:var(--theme-primary); border:1px solid rgba(var(--theme-primary-rgb),0.6); cursor:pointer; }
          .cmt-btn-secundario:hover { background:rgba(var(--theme-primary-rgb),0.1); }
          .cmt-notif-lista { display:flex; flex-direction:column; gap:0.5rem; max-height:60vh; overflow-y:auto; }
          .cmt-notif-cargando, .cmt-notif-vacio { text-align:center; color:var(--theme-text-muted); padding:1.6rem 0; font-size:0.88rem; }
          .cmt-notif-item { background:rgba(var(--theme-bg-main-rgb),0.5); border:1px solid rgba(var(--theme-primary-rgb),0.22); border-radius:11px; padding:0.7rem 0.9rem; }
          .cmt-notif-item.no-leida { border-color:rgba(var(--theme-primary-rgb),0.6); background:rgba(var(--theme-primary-rgb),0.08); }
          .cmt-notif-titulo-item { font-weight:700; color:var(--theme-text); font-size:0.9rem; display:flex; align-items:center; gap:0.4rem; }
          .cmt-notif-punto { width:7px; height:7px; border-radius:50%; background:var(--theme-primary); flex-shrink:0; box-shadow:0 0 6px var(--theme-primary); }
          .cmt-notif-msg { color:var(--theme-text-muted); font-size:0.83rem; line-height:1.45; margin-top:0.2rem; }
          .cmt-notif-fecha { color:var(--theme-text-muted); opacity:0.7; font-size:0.7rem; margin-top:0.3rem; }
        </style>

        <script>
        (function () {
          var API = <?= json_encode($apiUrl, JSON_UNESCAPED_SLASHES) ?>;
          var LOGUEADO = <?= $usuarioId > 0 ? 'true' : 'false' ?>;
          var SOLO_JUEGO = <?= $juegoId > 0 ? 'true' : 'false' ?>;
          var modal = document.getElementById('cmt-modal');
          var bloqueo = document.getElementById('cmt-bloqueo');
          if (!modal || !bloqueo) return;

          var form = document.getElementById('cmt-form');
          var textarea = document.getElementById('cmt-texto');
          var contador = modal.querySelector('[data-cmt-contador]');
          var aviso = modal.querySelector('[data-cmt-aviso]');
          var inputEstrellas = modal.querySelector('[data-cmt-estrellas-valor]');
          var selectPedido = modal.querySelector('[data-cmt-pedido]');
          var btnEnviar = modal.querySelector('[data-cmt-enviar]');
          var tituloModal = document.getElementById('cmt-modal-titulo');
          // Cuando se está editando, guarda el id; si es null, es una reseña nueva.
          var editandoId = null;

          function abrir(el) { el.classList.remove('d-none'); document.body.style.overflow = 'hidden'; }
          function cerrar(el) { el.classList.add('d-none'); document.body.style.overflow = ''; }

          function mostrarAviso(texto, ok) {
            if (!aviso) return;
            aviso.textContent = texto;
            aviso.className = 'cmt-aviso ' + (ok ? 'ok' : 'error');
          }
          function limpiarAviso() {
            if (aviso) aviso.className = 'cmt-aviso d-none';
          }

          function pintarEstrellas(n) {
            modal.querySelectorAll('[data-cmt-estrella]').forEach(function (b) {
              b.classList.toggle('activa', parseInt(b.dataset.cmtEstrella, 10) <= n);
            });
            if (inputEstrellas) inputEstrellas.value = String(n);
          }

          function actualizarContador() {
            if (contador && textarea) contador.textContent = String(textarea.value.length);
          }

          function modoNuevo() {
            editandoId = null;
            if (tituloModal) tituloModal.textContent = 'Deja un comentario';
            if (btnEnviar) btnEnviar.textContent = 'Publicar comentario';
            if (textarea) textarea.value = '';
            pintarEstrellas(5);
            actualizarContador();
            limpiarAviso();
          }

          // Botón principal "Deja un comentario"
          document.querySelectorAll('[data-cmt-abrir]').forEach(function (btn) {
            btn.addEventListener('click', function () {
              var estado = btn.dataset.cmtAbrir;
              if (estado === 'puede') {
                modoNuevo();
                abrir(modal);
                return;
              }
              // Sin sesión o sin compras completadas: pop-up explicativo
              var texto = bloqueo.querySelector('[data-cmt-bloqueo-texto]');
              var acciones = bloqueo.querySelector('[data-cmt-bloqueo-acciones]');
              if (acciones) acciones.innerHTML = '';

              if (estado === 'invitado') {
                if (texto) texto.textContent = (SOLO_JUEGO
                  ? 'Realiza tu primera recarga de este juego para poder dejar un comentario y ayudar a otros. '
                  : 'Realiza tu primera recarga para poder dejar un comentario y ayudar a otros. ') + 'Si ya tienes cuenta, inicia sesión.';
                if (acciones) {
                  var bReg = document.createElement('button');
                  bReg.type = 'button';
                  bReg.className = 'cmt-btn-principal';
                  bReg.textContent = 'Ven y regístrate';
                  bReg.addEventListener('click', function () {
                    cerrar(bloqueo);
                    if (typeof window.openAuthModal === 'function') window.openAuthModal('register');
                  });
                  var bLog = document.createElement('button');
                  bLog.type = 'button';
                  bLog.className = 'cmt-btn-secundario';
                  bLog.textContent = 'Ya tengo cuenta, iniciar sesión';
                  bLog.addEventListener('click', function () {
                    cerrar(bloqueo);
                    if (typeof window.openAuthModal === 'function') window.openAuthModal('login');
                  });
                  acciones.appendChild(bReg);
                  acciones.appendChild(bLog);
                }
              } else {
                if (texto) texto.textContent = SOLO_JUEGO
                  ? 'Para dejar un comentario sobre este juego necesitas al menos una recarga completada aquí. ¡Haz tu primera recarga y cuéntanos tu experiencia!'
                  : 'Para dejar un comentario necesitas al menos una recarga completada. ¡Haz tu primera recarga y cuéntanos tu experiencia!';
                if (acciones) {
                  var bIr = document.createElement('button');
                  bIr.type = 'button';
                  bIr.className = 'cmt-btn-principal';
                  bIr.textContent = 'Ver juegos y recargar';
                  bIr.addEventListener('click', function () { window.location.href = <?= json_encode(app_path('/'), JSON_UNESCAPED_SLASHES) ?>; });
                  acciones.appendChild(bIr);
                }
              }
              abrir(bloqueo);
            });
          });

          // Cerrar
          modal.querySelectorAll('[data-cmt-cerrar]').forEach(function (el) {
            el.addEventListener('click', function () { cerrar(modal); });
          });
          bloqueo.querySelectorAll('[data-cmt-cerrar-bloqueo]').forEach(function (el) {
            el.addEventListener('click', function () { cerrar(bloqueo); });
          });
          document.addEventListener('keydown', function (e) {
            if (e.key !== 'Escape') return;
            if (!modal.classList.contains('d-none')) cerrar(modal);
            else if (!bloqueo.classList.contains('d-none')) cerrar(bloqueo);
          });

          // Estrellas
          modal.querySelectorAll('[data-cmt-estrella]').forEach(function (b) {
            b.addEventListener('click', function () { pintarEstrellas(parseInt(b.dataset.cmtEstrella, 10)); });
          });
          pintarEstrellas(5);

          if (textarea) textarea.addEventListener('input', actualizarContador);

          // Editar una reseña existente
          modal.querySelectorAll('[data-cmt-editar]').forEach(function (btn) {
            btn.addEventListener('click', function () {
              editandoId = btn.dataset.cmtEditar;
              if (tituloModal) tituloModal.textContent = 'Editar tu comentario';
              if (btnEnviar) btnEnviar.textContent = 'Guardar cambios';
              if (textarea) textarea.value = btn.dataset.cmtTexto || '';
              pintarEstrellas(parseInt(btn.dataset.cmtEstrellas, 10) || 5);
              actualizarContador();
              limpiarAviso();
              if (textarea) textarea.focus();
            });
          });

          // Enviar
          if (form) {
            form.addEventListener('submit', function (e) {
              e.preventDefault();
              limpiarAviso();
              var datos = {
                estrellas: inputEstrellas ? inputEstrellas.value : '5',
                texto: textarea ? textarea.value : '',
              };
              var accion;
              if (editandoId) {
                accion = 'editar';
                datos.comentario_id = editandoId;
              } else {
                accion = 'publicar';
                datos.pedido_id = selectPedido ? selectPedido.value : '';
              }

              btnEnviar.disabled = true;
              var textoOriginal = btnEnviar.textContent;
              btnEnviar.textContent = 'Enviando...';

              fetch(API + '?action=' + accion, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(datos),
              })
                .then(function (r) { return r.json().then(function (d) { return { status: r.status, data: d }; }); })
                .then(function (res) {
                  var d = res.data || {};
                  if (d.ok) {
                    mostrarAviso(d.message || 'Listo.', true);
                    window.setTimeout(function () { window.location.reload(); }, 1800);
                  } else {
                    mostrarAviso(d.message || 'No se pudo enviar tu comentario.', false);
                    btnEnviar.disabled = false;
                    btnEnviar.textContent = textoOriginal;
                  }
                })
                .catch(function () {
                  mostrarAviso('Error de conexión. Intenta de nuevo.', false);
                  btnEnviar.disabled = false;
                  btnEnviar.textContent = textoOriginal;
                });
            });
          }

          // ── Notificaciones ───────────────────────────────────────────
          (function () {
            if (!LOGUEADO) return;
            var modalNotif = document.getElementById('cmt-notificaciones');
            var lista = modalNotif ? modalNotif.querySelector('[data-cmt-notif-lista]') : null;
            var badges = document.querySelectorAll('[data-cmt-notif-badge]');
            var botones = document.querySelectorAll('[data-cmt-abrir-notificaciones]');
            if (!modalNotif || !lista) return;

            function pintarBadge(n) {
              badges.forEach(function (b) {
                b.textContent = n > 99 ? '99+' : String(n);
                b.classList.toggle('d-none', n <= 0);
              });
            }

            function escapar(s) {
              var d = document.createElement('div');
              d.textContent = String(s == null ? '' : s);
              return d.innerHTML;
            }

            function fechaCorta(iso) {
              var t = Date.parse((iso || '').replace(' ', 'T'));
              if (isNaN(t)) return '';
              var d = new Date(t);
              var meses = ['ene','feb','mar','abr','may','jun','jul','ago','sep','oct','nov','dic'];
              return d.getDate() + ' ' + meses[d.getMonth()] + ' ' + d.getFullYear();
            }

            function cargar(marcarLeidas) {
              fetch(API + '?action=notificaciones', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                  if (!d.ok) return;
                  pintarBadge(d.no_leidas || 0);
                  if (!marcarLeidas) return;

                  if (!d.items || d.items.length === 0) {
                    lista.innerHTML = '<div class="cmt-notif-vacio">Todavía no tienes notificaciones.</div>';
                  } else {
                    lista.innerHTML = d.items.map(function (n) {
                      return '<div class="cmt-notif-item' + (n.leido ? '' : ' no-leida') + '">'
                        + '<div class="cmt-notif-titulo-item">'
                        + (n.leido ? '' : '<span class="cmt-notif-punto"></span>')
                        + escapar(n.titulo || 'Notificación') + '</div>'
                        + '<div class="cmt-notif-msg">' + escapar(n.mensaje) + '</div>'
                        + '<div class="cmt-notif-fecha">' + escapar(fechaCorta(n.creado_en)) + '</div>'
                        + '</div>';
                    }).join('');
                  }

                  // Al abrirlas se marcan como leídas y se apaga el contador.
                  if (d.no_leidas > 0) {
                    fetch(API + '?action=notificaciones_leidas', {
                      method: 'POST',
                      headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    }).then(function () { pintarBadge(0); }).catch(function () {});
                  }
                })
                .catch(function () {
                  if (marcarLeidas) lista.innerHTML = '<div class="cmt-notif-vacio">No se pudieron cargar tus notificaciones.</div>';
                });
            }

            botones.forEach(function (b) {
              b.addEventListener('click', function () {
                lista.innerHTML = '<div class="cmt-notif-cargando">Cargando...</div>';
                abrir(modalNotif);
                cargar(true);
              });
            });
            modalNotif.querySelectorAll('[data-cmt-cerrar-notif]').forEach(function (el) {
              el.addEventListener('click', function () { cerrar(modalNotif); });
            });
            document.addEventListener('keydown', function (e) {
              if (e.key === 'Escape' && !modalNotif.classList.contains('d-none')) cerrar(modalNotif);
            });

            // Contador al cargar la página (sin abrir el modal).
            cargar(false);
          })();

          // ── Registro con comentario integrado ────────────────────────
          // Lo llama el bloque post-compra de game.php cuando un INVITADO
          // acaba de recargar y elige registrarse: revela el campo de reseña
          // dentro del formulario de registro y le pasa el pedido.
          window.cmtPrepararRegistroConComentario = function (pedidoId) {
            var bloque = document.getElementById('registro-comentario-bloque');
            var inputPedido = document.getElementById('registro-comentario-pedido');
            if (!bloque || !inputPedido) return;
            inputPedido.value = String(pedidoId || '');
            bloque.classList.remove('d-none');
            if (typeof window.openAuthModal === 'function') window.openAuthModal('register');
          };

          // Estrellas del formulario de registro (bloque de arriba)
          (function () {
            var cont = document.getElementById('registro-comentario-estrellas');
            var valor = document.getElementById('registro-comentario-estrellas-valor');
            if (!cont || !valor) return;
            var botones = cont.querySelectorAll('[data-registro-estrella]');
            function pintar(n) {
              botones.forEach(function (b) {
                var activa = parseInt(b.dataset.registroEstrella, 10) <= n;
                b.style.color = activa ? 'var(--theme-warning)' : 'rgba(var(--theme-text-muted-rgb),0.35)';
              });
              valor.value = String(n);
            }
            botones.forEach(function (b) {
              b.addEventListener('click', function () { pintar(parseInt(b.dataset.registroEstrella, 10)); });
            });
            pintar(5);
          })();

          // Botón "Me gusta" (clase cmt-util sin cambios, es solo el texto visible)
          document.querySelectorAll('[data-cmt-like]').forEach(function (btn) {
            btn.addEventListener('click', function () {
              if (btn.dataset.cmtLogueado !== '1') {
                if (typeof window.openAuthModal === 'function') window.openAuthModal('login');
                return;
              }
              btn.disabled = true;
              fetch(API + '?action=like', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ comentario_id: btn.dataset.cmtLike }),
              })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                  if (d.ok) {
                    btn.classList.toggle('activo', !!d.activo);
                    var n = btn.querySelector('.cmt-util-n');
                    if (n) n.textContent = d.likes > 0 ? '(' + d.likes + ')' : '';
                  }
                })
                .catch(function () {})
                .finally(function () { btn.disabled = false; });
            });
          });
        })();
        </script>
        <?php
    }
}
