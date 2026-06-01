<?php
require_once __DIR__ . '/includes/game_entry_window_per_game.php';

if (!function_exists('render_game_entry_window_html_editor')) {
    function render_game_entry_window_html_editor(string $name, string $value, string $templateName, int $rows = 6, string $placeholder = ''): void {
        ?>
        <div class="influencer-html-editor" data-html-editor>
          <div class="influencer-html-toolbar" role="toolbar" aria-label="Herramientas HTML">
            <button type="button" class="btn btn-sm btn-outline-info" data-editor-action="paragraph">P</button>
            <button type="button" class="btn btn-sm btn-outline-info" data-editor-action="bold"><strong>B</strong></button>
            <button type="button" class="btn btn-sm btn-outline-info" data-editor-action="italic"><em>I</em></button>
            <button type="button" class="btn btn-sm btn-outline-info" data-editor-action="underline"><span style="text-decoration:underline;">U</span></button>
            <label class="influencer-html-color-picker" title="Color del texto">
              <span>T</span>
              <input type="color" value="#dffcff" data-editor-color-picker>
            </label>
            <button type="button" class="btn btn-sm btn-outline-info" data-editor-action="heading">H2</button>
            <button type="button" class="btn btn-sm btn-outline-info" data-editor-action="subheading">H3</button>
            <button type="button" class="btn btn-sm btn-outline-info" data-editor-action="list">Lista</button>
            <button type="button" class="btn btn-sm btn-outline-info" data-editor-action="ordered-list">1.</button>
            <button type="button" class="btn btn-sm btn-outline-info" data-editor-action="quote">Cita</button>
            <button type="button" class="btn btn-sm btn-outline-info" data-editor-action="link">Link</button>
            <button type="button" class="btn btn-sm btn-outline-info" data-editor-action="unlink">Quitar link</button>
            <button type="button" class="btn btn-sm btn-outline-info" data-editor-action="break">Salto</button>
            <button type="button" class="btn btn-sm btn-outline-info" data-editor-action="clear">Limpiar</button>
            <button type="button" class="btn btn-sm btn-outline-success ms-auto" data-editor-action="preview">Vista previa</button>
          </div>
          <div
            class="influencer-html-surface"
            contenteditable="true"
            spellcheck="true"
            data-editor-visual
            data-placeholder="<?= htmlspecialchars($placeholder !== '' ? $placeholder : 'Escribe aqui el contenido de la tarjeta', ENT_QUOTES, 'UTF-8') ?>"
            style="min-height:<?= max(180, (int) $rows * 28) ?>px;"
          ><?= $value ?></div>
          <textarea name="<?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>" data-name-template="<?= htmlspecialchars($templateName, ENT_QUOTES, 'UTF-8') ?>" rows="<?= (int) $rows ?>" class="d-none" data-editor-textarea><?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?></textarea>
          <div class="influencer-html-preview d-none" data-editor-preview></div>
        </div>
        <?php
    }
}

if (!function_exists('render_game_entry_window_card_editor')) {
    function render_game_entry_window_card_editor(string $token, array $card): void {
        $cardId = (int) ($card['id'] ?? 0);
        $active = !empty($card['activo']);
        $order = max(1, (int) ($card['orden'] ?? 1));
        $color = store_config_normalize_hex_color((string) ($card['color'] ?? '#233A73'), '#233A73');
        $backgroundColor = store_config_normalize_hex_color((string) ($card['background_color'] ?? '#121a2f'), '#121a2f');
        $contentHtml = (string) ($card['content_html'] ?? '');
    $mediaPath = trim((string) ($card['media_path'] ?? ''));
    $mediaEmbedUrl = trim((string) ($card['media_embed_url'] ?? ''));
    $hasMedia = $mediaPath !== '' || $mediaEmbedUrl !== '';
        $previewMarkup = game_entry_window_render_card_markup($card);
        ?>
        <div class="game-entry-card-editor" data-game-entry-card data-current-media-path="<?= htmlspecialchars($mediaPath, ENT_QUOTES, 'UTF-8') ?>" data-current-media-embed="<?= htmlspecialchars($mediaEmbedUrl, ENT_QUOTES, 'UTF-8') ?>">
          <input type="hidden" name="cards[<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>][id]" data-name-template="cards[__INDEX__][id]" value="<?= $cardId ?>">
          <div class="game-entry-card-header">
            <div>
              <div class="game-entry-card-kicker" data-card-order-label>Tarjeta <?= $order ?></div>
              <div class="small text-secondary">Configura borde, fondo, contenido HTML y vista previa en la misma fila.</div>
            </div>
            <button type="button" class="btn btn-sm btn-outline-danger" data-remove-card>Eliminar tarjeta</button>
          </div>
          <div class="row g-4 align-items-start">
            <div class="col-xl-7 d-grid gap-3">
              <div class="row g-3 align-items-end">
                <div class="col-lg-3 col-md-6">
                  <label class="form-label">Orden</label>
                  <input type="number" min="1" name="cards[<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>][order]" data-name-template="cards[__INDEX__][order]" value="<?= $order ?>" class="form-control" data-card-order-input>
                </div>
                <div class="col-lg-3 col-md-6">
                  <label class="form-label">Color de borde</label>
                  <input type="color" name="cards[<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>][color]" data-name-template="cards[__INDEX__][color]" value="<?= htmlspecialchars($color, ENT_QUOTES, 'UTF-8') ?>" class="form-control form-control-color w-100" style="height:3rem;" data-card-color-input>
                </div>
                <div class="col-lg-3 col-md-6">
                  <label class="form-label">Color de fondo</label>
                  <input type="color" name="cards[<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>][background_color]" data-name-template="cards[__INDEX__][background_color]" value="<?= htmlspecialchars($backgroundColor, ENT_QUOTES, 'UTF-8') ?>" class="form-control form-control-color w-100" style="height:3rem;" data-card-background-input>
                </div>
                <div class="col-lg-3 col-md-6">
                  <div class="form-check form-switch mt-lg-4 pt-lg-2">
                    <input class="form-check-input" type="checkbox" name="cards[<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>][active]" data-name-template="cards[__INDEX__][active]" value="1" <?= $active ? 'checked' : '' ?> data-card-active-input>
                    <label class="form-check-label">Tarjeta activa</label>
                  </div>
                </div>
              </div>
              <div class="row g-3">
                <div class="col-12">
                  <label class="form-label">Multimedia de la tarjeta</label>
                  <input type="file" name="cards_media[<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>]" data-name-template="cards_media[__INDEX__]" accept="image/png,image/jpeg,image/webp,image/gif,video/mp4,video/webm,video/ogg,video/quicktime" class="form-control" data-card-media-file-input>
                  <div class="form-text">Sube una imagen o video compatible. Si cargas un archivo nuevo, reemplazará la multimedia actual de esta tarjeta.</div>
                </div>
                <div class="col-12">
                  <label class="form-label">URL embed de YouTube o TikTok</label>
                  <input type="url" name="cards[<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>][media_embed_url]" data-name-template="cards[__INDEX__][media_embed_url]" value="<?= htmlspecialchars($mediaEmbedUrl, ENT_QUOTES, 'UTF-8') ?>" class="form-control" placeholder="https://www.youtube.com/watch?v=... o https://www.tiktok.com/..." data-card-media-embed-input>
                  <div class="form-text">Si completas esta URL, el embed tendrá prioridad y el helper limpiará el archivo anterior al guardar.</div>
                </div>
                <?php if ($hasMedia): ?>
                  <div class="col-12">
                    <div class="small text-info mb-2">Multimedia actual de la tarjeta</div>
                    <div class="p-3 rounded border border-info-subtle bg-dark-subtle">
                      <?= game_entry_window_render_media_html($mediaPath, $mediaEmbedUrl) ?>
                    </div>
                    <div class="form-check mt-3">
                      <input class="form-check-input" type="checkbox" name="cards[<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>][media_remove]" data-name-template="cards[__INDEX__][media_remove]" value="1" id="cardMediaRemove<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>" data-card-media-remove-input>
                      <label class="form-check-label" for="cardMediaRemove<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">Eliminar la multimedia actual al guardar</label>
                    </div>
                  </div>
                <?php endif; ?>
              </div>
              <div>
                <label class="form-label">Contenido HTML</label>
                <?php render_game_entry_window_html_editor('cards[' . $token . '][content_html]', $contentHtml, 'cards[__INDEX__][content_html]', 7, 'Escribe aqui las reglas o avisos de esta tarjeta'); ?>
              </div>
            </div>
            <div class="col-xl-5">
              <label class="form-label">Vista previa</label>
              <div class="game-entry-card-preview-wrap">
                <div class="game-entry-card-preview" data-card-preview style="--card-preview-color: <?= htmlspecialchars($color, ENT_QUOTES, 'UTF-8') ?>; --card-preview-background: <?= htmlspecialchars($backgroundColor, ENT_QUOTES, 'UTF-8') ?>;">
                  <?= $previewMarkup !== '' ? $previewMarkup : '<p><strong>Vista previa</strong></p><p>Escribe contenido para ver cómo quedará la tarjeta.</p>' ?>
                </div>
              </div>
            </div>
          </div>
        </div>
        <?php
    }
}

$mysqli = store_config_db();
$gameId = game_entry_window_game_id($_POST['game_id'] ?? $_GET['game_id'] ?? 0);
$game = game_entry_window_fetch_game($mysqli, $gameId);

if ($gameId <= 0 || !$game) {
    ?>
    <div class="container py-4">
      <div class="alert alert-danger">No se encontró el juego seleccionado para configurar su ventana inicial.</div>
    </div>
    <?php
    return;
}

$defaults = game_entry_window_defaults();
$config = game_entry_window_fetch_config($mysqli, $gameId);
$cards = game_entry_window_fetch_cards($mysqli, $gameId, false);
if ($cards === []) {
    $cards = [game_entry_window_default_card_template()];
}

$defaultWindowIcon = game_entry_window_default_icon_path();
$windowIcon = game_entry_window_resolve_icon_path((string) ($config['icon'] ?? ''));
$usingDefaultWindowIcon = trim($windowIcon) === '' || $windowIcon === $defaultWindowIcon;
$windowTitle = trim((string) ($config['title'] ?? $defaults['title']));
$windowCopy = trim((string) ($config['copy'] ?? $defaults['copy']));
$checkText = trim((string) ($config['check_text'] ?? $defaults['check_text']));
$buttonText = trim((string) ($config['button_text'] ?? $defaults['button_text']));
$modalBackground = store_config_normalize_hex_color((string) ($config['modal_background'] ?? $defaults['modal_background']), $defaults['modal_background']);
$modalBorderColor = store_config_normalize_hex_color((string) ($config['modal_border_color'] ?? $defaults['modal_border_color']), $defaults['modal_border_color']);
$titleColor = store_config_normalize_hex_color((string) ($config['title_color'] ?? $defaults['title_color']), $defaults['title_color']);
$checkTextColor = store_config_normalize_hex_color((string) ($config['check_text_color'] ?? $defaults['check_text_color']), $defaults['check_text_color']);
$checkBackgroundColor = store_config_normalize_hex_color((string) ($config['check_background_color'] ?? $defaults['check_background_color']), $defaults['check_background_color']);
$buttonTextColor = store_config_normalize_hex_color((string) ($config['button_text_color'] ?? $defaults['button_text_color']), $defaults['button_text_color']);
$buttonBackgroundColor = store_config_normalize_hex_color((string) ($config['button_background_color'] ?? $defaults['button_background_color']), $defaults['button_background_color']);
$buttonDisabledTextColor = store_config_normalize_hex_color((string) ($config['button_disabled_text_color'] ?? $defaults['button_disabled_text_color']), $defaults['button_disabled_text_color']);
$buttonDisabledBackgroundColor = store_config_normalize_hex_color((string) ($config['button_disabled_background_color'] ?? $defaults['button_disabled_background_color']), $defaults['button_disabled_background_color']);
$windowEnabled = !empty($config['enabled']);
$gameName = trim((string) ($game['nombre'] ?? 'Juego')) ?: 'Juego';
$adminGamesUrl = app_path('/admin/juegos');
?>
<style>
  .influencer-html-editor {
    display: grid;
    gap: 0.65rem;
  }
  .influencer-html-toolbar {
    display: flex;
    gap: 0.45rem;
    flex-wrap: wrap;
  }
  .influencer-html-color-picker {
    display: inline-flex;
    align-items: center;
    gap: 0.45rem;
    padding: 0.35rem 0.55rem;
    border: 1px solid rgba(34, 211, 238, 0.65);
    border-radius: 0.45rem;
    color: #67e8f9;
    background: rgba(8, 16, 26, 0.92);
    cursor: pointer;
    min-height: 32px;
  }
  .influencer-html-color-picker input {
    width: 1.7rem;
    height: 1.7rem;
    padding: 0;
    border: 0;
    background: transparent;
    cursor: pointer;
  }
  .influencer-html-toolbar .btn {
    min-width: 3rem;
  }
  .influencer-html-surface {
    padding: 1rem;
    border: 1px solid rgba(0,255,247,0.25);
    border-radius: 0.85rem;
    background: rgba(9, 16, 26, 0.92);
    color: #dffcff;
    outline: none;
    line-height: 1.6;
    overflow-y: auto;
  }
  .influencer-html-surface:focus {
    border-color: rgba(34, 211, 238, 0.75);
    box-shadow: 0 0 0 0.25rem rgba(34, 211, 238, 0.12);
  }
  .influencer-html-surface[data-placeholder]:empty::before {
    content: attr(data-placeholder);
    color: #7aa7b3;
  }
  .influencer-html-surface h2,
  .influencer-html-surface h3,
  .influencer-html-surface p,
  .influencer-html-surface ul,
  .influencer-html-surface ol,
  .influencer-html-surface blockquote {
    margin-bottom: 0.8rem;
  }
  .influencer-html-surface blockquote {
    border-left: 3px solid rgba(34, 211, 238, 0.55);
    padding-left: 0.9rem;
    color: #bfeef6;
  }
  .influencer-html-preview {
    min-height: 120px;
    padding: 1rem;
    border: 1px solid rgba(0,255,247,0.25);
    border-radius: 0.85rem;
    background: rgba(9, 16, 26, 0.92);
    color: #dffcff;
  }
  .game-entry-window-shell {
    display: grid;
    gap: 1.5rem;
  }
  .game-entry-window-hero,
  .game-entry-card-editor,
  .game-entry-window-preview-shell {
    border: 1px solid rgba(34, 211, 238, 0.2);
    border-radius: 1.35rem;
    padding: 1.35rem;
    background: rgba(8, 15, 25, 0.96);
    box-shadow: 0 16px 34px rgba(2, 6, 23, 0.22);
  }
  .game-entry-window-hero {
    background: linear-gradient(135deg, rgba(10, 29, 39, 0.98), rgba(8, 16, 26, 0.98));
  }
  .game-entry-card-editor {
    background: linear-gradient(135deg, rgba(12, 18, 31, 0.98), rgba(8, 14, 25, 0.98));
  }
  .game-entry-window-kicker,
  .game-entry-card-kicker {
    font-size: 0.78rem;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: #7dd3fc;
    font-weight: 700;
    margin-bottom: 0.35rem;
  }
  .game-entry-card-header {
    display: flex;
    justify-content: space-between;
    gap: 1rem;
    align-items: flex-start;
    flex-wrap: wrap;
    margin-bottom: 1rem;
  }
  .game-entry-card-preview-wrap {
    position: sticky;
    top: 1rem;
  }
  .game-entry-card-preview {
    border-radius: 1rem;
    padding: 1rem;
    min-height: 200px;
    border: 1px solid var(--card-preview-color, #233A73);
    background: var(--card-preview-background, #121a2f);
    box-shadow: inset 0 0 0 1px rgba(255,255,255,0.02);
    color: #e7f1ff;
  }
  .game-entry-card-preview p:last-child,
  .game-entry-card-preview ul:last-child,
  .game-entry-card-preview ol:last-child,
  .game-entry-card-preview blockquote:last-child,
  .game-entry-card-preview h2:last-child,
  .game-entry-card-preview h3:last-child {
    margin-bottom: 0;
  }
  .game-entry-window-preview-shell {
    background: linear-gradient(135deg, rgba(16, 14, 24, 0.98), rgba(7, 11, 20, 0.98));
  }
  .game-entry-window-modal-preview {
    width: min(100%, 340px);
    margin: 0 auto;
    border-radius: 22px;
    border: 1px solid var(--window-modal-border, #fb923c);
    background: linear-gradient(180deg, rgba(255,255,255,0.04), rgba(255,255,255,0.015)), var(--window-modal-background, #18101e);
    overflow: hidden;
    box-shadow: 0 18px 48px rgba(0, 0, 0, 0.4);
  }
  .game-entry-window-modal-preview-header {
    padding: 0.9rem 0.9rem 0.65rem;
    text-align: center;
    border-bottom: 1px solid rgba(255,255,255,0.06);
  }
  .game-entry-window-modal-icon {
    width: 56px;
    height: 56px;
    margin: 0 auto 0.65rem;
    border-radius: 999px;
    background: rgba(34, 211, 238, 0.18);
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
    color: #fff;
    font-size: 1.75rem;
    box-shadow: 0 0 0 7px rgba(34, 211, 238, 0.08);
  }
  .game-entry-window-modal-icon img {
    width: 100%;
    height: 100%;
    object-fit: cover;
  }
  .game-entry-window-modal-title {
    color: var(--window-title-color, #f8b53d);
    font-family: 'Oxanium', 'Montserrat', sans-serif;
    font-size: 1.34rem;
    line-height: 1.02;
    font-weight: 700;
    margin: 0;
  }
  .game-entry-window-modal-copy {
    margin: 0.45rem 0 0;
    color: #dbe6f3;
    font-size: 0.82rem;
    line-height: 1.45;
  }
  .game-entry-window-modal-body {
    padding: 0.85rem;
    display: grid;
    gap: 0.8rem;
  }
  .game-entry-window-modal-preview-cards {
    display: grid;
    gap: 1rem;
  }
  .game-entry-window-modal-card {
    padding: 0.95rem;
    border-radius: 1rem;
    border: 1px solid var(--preview-card-color, #233A73);
    background: var(--preview-card-background, #121a2f);
    color: #e5edf7;
  }
  .game-entry-window-modal-card p:last-child,
  .game-entry-window-modal-card ul:last-child,
  .game-entry-window-modal-card ol:last-child,
  .game-entry-window-modal-card blockquote:last-child,
  .game-entry-window-modal-card h2:last-child,
  .game-entry-window-modal-card h3:last-child {
    margin-bottom: 0;
  }
  .game-entry-window-card-media {
    margin-bottom: 0.85rem;
  }
  .game-entry-window-card-image,
  .game-entry-window-card-video,
  .game-entry-window-card-embed {
    display: block;
    width: 100%;
    border: 0;
    border-radius: 0.9rem;
    background: rgba(2, 6, 23, 0.55);
    overflow: hidden;
  }
  .game-entry-window-card-image,
  .game-entry-window-card-video {
    max-height: 240px;
    object-fit: cover;
  }
  .game-entry-window-card-embed {
    aspect-ratio: 16 / 9;
    min-height: 220px;
  }
  .game-entry-window-card-embed-tiktok {
    min-height: 420px;
    aspect-ratio: auto;
  }
  .game-entry-window-modal-check {
    padding: 0.7rem 0.8rem;
    border-radius: 0.8rem;
    background: var(--window-check-background, #1e293b);
    border: 1px solid rgba(255,255,255,0.06);
    color: var(--window-check-color, #e2e8f0);
  }
  .game-entry-window-modal-check-toggle {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    width: 100%;
    margin: 0;
    color: inherit;
    cursor: pointer;
  }
  .game-entry-window-modal-check-toggle span {
    font-size: 0.78rem;
    line-height: 1.35;
  }
  .game-entry-window-modal-check-switch {
    width: 2.2rem;
    height: 1.15rem;
    margin: 0;
    flex: 0 0 auto;
    float: none;
    background-color: rgba(7, 18, 28, 0.85);
    border-color: rgba(255,255,255,0.24);
    box-shadow: none;
  }
  .game-entry-window-modal-check-switch:checked {
    background-color: var(--window-button-background, #c99712);
    border-color: var(--window-button-background, #c99712);
  }
  .game-entry-window-modal-button {
    width: 100%;
    border: 0;
    border-radius: 0.95rem;
    min-height: 2.85rem;
    background: var(--window-button-disabled-background, #c99712);
    color: var(--window-button-disabled-color, #0b0f18);
    font-weight: 700;
    font-size: 0.88rem;
    opacity: 0.72;
    transition: background 0.2s ease, color 0.2s ease, opacity 0.2s ease;
  }
  .game-entry-window-modal-button.is-active {
    background: var(--window-button-background, #c99712);
    color: var(--window-button-color, #0b0f18);
    opacity: 1;
  }
</style>

<div class="container py-4">
  <div class="game-entry-window-shell">
    <div class="game-entry-window-hero">
      <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap mb-3">
        <div>
          <div class="game-entry-window-kicker">Ventana Inicial por Juego</div>
          <h2 class="display-6 fw-bold text-info mb-2">Configura la ventana inicial de <?= htmlspecialchars($gameName, ENT_QUOTES, 'UTF-8') ?></h2>
          <p class="text-light mb-0">Esta configuración aplica solo al juego seleccionado. En VirtualGaming, el funcionamiento final sigue dependiendo de la clave global <strong>ventana_inicio_juego</strong>.</p>
        </div>
        <a href="<?= htmlspecialchars($adminGamesUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline-info">Volver a juegos</a>
      </div>
    </div>

    <form method="POST" enctype="multipart/form-data" class="d-grid gap-4">
      <input type="hidden" name="game_entry_window_save" value="1">
      <input type="hidden" name="game_id" value="<?= $gameId ?>">

      <div class="game-entry-window-hero">
        <div class="row g-4 align-items-start">
          <div class="col-xl-7 d-grid gap-3">
            <div class="form-check form-switch">
              <input class="form-check-input" type="checkbox" name="ventana_inicio_juego_activa" id="ventanaInicioJuegoActiva" value="1" <?= $windowEnabled ? 'checked' : '' ?>>
              <label class="form-check-label fw-semibold" for="ventanaInicioJuegoActiva">Mostrar esta ventana cuando el usuario entre a <?= htmlspecialchars($gameName, ENT_QUOTES, 'UTF-8') ?></label>
            </div>
            <div>
              <label class="form-label">Título de la ventana</label>
              <input type="text" name="ventana_inicio_juego_titulo" value="<?= htmlspecialchars($windowTitle !== '' ? $windowTitle : $defaults['title'], ENT_QUOTES, 'UTF-8') ?>" class="form-control" data-window-title-input>
            </div>
            <div>
              <label class="form-label">Texto descriptivo</label>
              <textarea name="ventana_inicio_juego_descripcion" rows="2" class="form-control" data-window-copy-input><?= htmlspecialchars($windowCopy !== '' ? $windowCopy : $defaults['copy'], ENT_QUOTES, 'UTF-8') ?></textarea>
            </div>
            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label">Texto del check</label>
                <input type="text" name="ventana_inicio_juego_check_texto" value="<?= htmlspecialchars($checkText !== '' ? $checkText : $defaults['check_text'], ENT_QUOTES, 'UTF-8') ?>" class="form-control" data-window-check-input>
              </div>
              <div class="col-md-6">
                <label class="form-label">Texto del botón</label>
                <input type="text" name="ventana_inicio_juego_boton_texto" value="<?= htmlspecialchars($buttonText !== '' ? $buttonText : $defaults['button_text'], ENT_QUOTES, 'UTF-8') ?>" class="form-control" data-window-button-input>
              </div>
            </div>
            <div class="row g-3">
              <div class="col-md-4">
                <label class="form-label">Fondo del modal</label>
                <input type="color" name="ventana_inicio_juego_modal_background" value="<?= htmlspecialchars($modalBackground, ENT_QUOTES, 'UTF-8') ?>" class="form-control form-control-color w-100" style="height:3rem;" data-window-modal-background-input>
              </div>
              <div class="col-md-4">
                <label class="form-label">Borde del modal</label>
                <input type="color" name="ventana_inicio_juego_modal_border_color" value="<?= htmlspecialchars($modalBorderColor, ENT_QUOTES, 'UTF-8') ?>" class="form-control form-control-color w-100" style="height:3rem;" data-window-modal-border-input>
              </div>
              <div class="col-md-4">
                <label class="form-label">Color del título</label>
                <input type="color" name="ventana_inicio_juego_title_color" value="<?= htmlspecialchars($titleColor, ENT_QUOTES, 'UTF-8') ?>" class="form-control form-control-color w-100" style="height:3rem;" data-window-title-color-input>
              </div>
              <div class="col-md-4">
                <label class="form-label">Color del texto del check</label>
                <input type="color" name="ventana_inicio_juego_check_text_color" value="<?= htmlspecialchars($checkTextColor, ENT_QUOTES, 'UTF-8') ?>" class="form-control form-control-color w-100" style="height:3rem;" data-window-check-text-color-input>
              </div>
              <div class="col-md-4">
                <label class="form-label">Fondo del check</label>
                <input type="color" name="ventana_inicio_juego_check_background_color" value="<?= htmlspecialchars($checkBackgroundColor, ENT_QUOTES, 'UTF-8') ?>" class="form-control form-control-color w-100" style="height:3rem;" data-window-check-background-input>
              </div>
              <div class="col-md-4">
                <label class="form-label">Color del texto del botón activo</label>
                <input type="color" name="ventana_inicio_juego_button_text_color" value="<?= htmlspecialchars($buttonTextColor, ENT_QUOTES, 'UTF-8') ?>" class="form-control form-control-color w-100" style="height:3rem;" data-window-button-text-color-input>
              </div>
              <div class="col-md-4">
                <label class="form-label">Fondo del botón activo</label>
                <input type="color" name="ventana_inicio_juego_button_background_color" value="<?= htmlspecialchars($buttonBackgroundColor, ENT_QUOTES, 'UTF-8') ?>" class="form-control form-control-color w-100" style="height:3rem;" data-window-button-background-input>
              </div>
              <div class="col-md-4">
                <label class="form-label">Color del texto del botón inactivo</label>
                <input type="color" name="ventana_inicio_juego_button_disabled_text_color" value="<?= htmlspecialchars($buttonDisabledTextColor, ENT_QUOTES, 'UTF-8') ?>" class="form-control form-control-color w-100" style="height:3rem;" data-window-button-disabled-text-color-input>
              </div>
              <div class="col-md-4">
                <label class="form-label">Fondo del botón inactivo</label>
                <input type="color" name="ventana_inicio_juego_button_disabled_background_color" value="<?= htmlspecialchars($buttonDisabledBackgroundColor, ENT_QUOTES, 'UTF-8') ?>" class="form-control form-control-color w-100" style="height:3rem;" data-window-button-disabled-background-input>
              </div>
            </div>
            <div>
              <label class="form-label">Ícono de la ventana</label>
              <input type="hidden" name="ventana_inicio_juego_icono_default" value="0" data-window-icon-default-input>
              <input type="file" name="ventana_inicio_juego_icono" accept="image/png,image/jpeg,image/webp,image/gif" class="form-control" data-window-icon-input>
              <div class="d-flex flex-wrap gap-2 mt-2">
                <button type="button" class="btn btn-sm btn-outline-info" data-window-icon-default-trigger>Usar icono default</button>
              </div>
              <div class="form-text mt-2">Puedes subir JPG, PNG, WEBP o GIF. Si luego quieres volver al original, usa el icono default.</div>
            </div>
            <?php if (!$usingDefaultWindowIcon): ?>
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="ventana_inicio_juego_icono_eliminar" id="ventanaInicioJuegoIconoEliminar" value="1">
                <label class="form-check-label" for="ventanaInicioJuegoIconoEliminar">Eliminar icono personalizado al guardar</label>
              </div>
            <?php endif; ?>
          </div>
          <div class="col-xl-5">
            <div class="game-entry-window-preview-shell">
              <div class="game-entry-window-kicker">Vista previa de <?= htmlspecialchars($gameName, ENT_QUOTES, 'UTF-8') ?></div>
              <div class="game-entry-window-modal-preview" data-window-modal-preview style="--window-modal-background: <?= htmlspecialchars($modalBackground, ENT_QUOTES, 'UTF-8') ?>; --window-modal-border: <?= htmlspecialchars($modalBorderColor, ENT_QUOTES, 'UTF-8') ?>; --window-title-color: <?= htmlspecialchars($titleColor, ENT_QUOTES, 'UTF-8') ?>; --window-check-color: <?= htmlspecialchars($checkTextColor, ENT_QUOTES, 'UTF-8') ?>; --window-check-background: <?= htmlspecialchars($checkBackgroundColor, ENT_QUOTES, 'UTF-8') ?>; --window-button-color: <?= htmlspecialchars($buttonTextColor, ENT_QUOTES, 'UTF-8') ?>; --window-button-background: <?= htmlspecialchars($buttonBackgroundColor, ENT_QUOTES, 'UTF-8') ?>; --window-button-disabled-color: <?= htmlspecialchars($buttonDisabledTextColor, ENT_QUOTES, 'UTF-8') ?>; --window-button-disabled-background: <?= htmlspecialchars($buttonDisabledBackgroundColor, ENT_QUOTES, 'UTF-8') ?>;">
                <div class="game-entry-window-modal-preview-header">
                  <div class="game-entry-window-modal-icon" data-window-icon-preview>
                    <img src="<?= htmlspecialchars($windowIcon, ENT_QUOTES, 'UTF-8') ?>" alt="Icono de la ventana">
                  </div>
                  <h3 class="game-entry-window-modal-title" data-window-title-preview><?= htmlspecialchars($windowTitle !== '' ? $windowTitle : $defaults['title'], ENT_QUOTES, 'UTF-8') ?></h3>
                  <p class="game-entry-window-modal-copy" data-window-copy-preview><?= htmlspecialchars($windowCopy !== '' ? $windowCopy : $defaults['copy'], ENT_QUOTES, 'UTF-8') ?></p>
                </div>
                <div class="game-entry-window-modal-body">
                  <div class="game-entry-window-modal-preview-cards" data-window-cards-preview>
                    <?php foreach ($cards as $card): ?>
                      <?php if (empty($card['activo'])) { continue; } ?>
                      <div class="game-entry-window-modal-card" style="--preview-card-color: <?= htmlspecialchars((string) ($card['color'] ?? '#233A73'), ENT_QUOTES, 'UTF-8') ?>; --preview-card-background: <?= htmlspecialchars((string) ($card['background_color'] ?? '#121a2f'), ENT_QUOTES, 'UTF-8') ?>;"><?= game_entry_window_render_card_markup(is_array($card) ? $card : []) ?></div>
                    <?php endforeach; ?>
                  </div>
                  <div class="game-entry-window-modal-check">
                    <label class="game-entry-window-modal-check-toggle">
                      <input type="checkbox" class="form-check-input game-entry-window-modal-check-switch" data-window-preview-toggle>
                      <span data-window-check-preview><?= htmlspecialchars($checkText !== '' ? $checkText : $defaults['check_text'], ENT_QUOTES, 'UTF-8') ?></span>
                    </label>
                  </div>
                  <button type="button" class="game-entry-window-modal-button" data-window-button-preview disabled><?= htmlspecialchars($buttonText !== '' ? $buttonText : $defaults['button_text'], ENT_QUOTES, 'UTF-8') ?></button>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
          <div class="game-entry-window-kicker mb-1">Tarjetas informativas</div>
          <p class="text-light mb-0">Agrega, ordena, activa o elimina todas las tarjetas que necesites para este juego. Cada fila muestra su editor y la vista previa al lado.</p>
        </div>
        <button type="button" class="btn btn-outline-info" data-add-card>Agregar tarjeta</button>
      </div>

      <div class="d-grid gap-4" data-game-entry-cards-list>
        <?php foreach ($cards as $index => $card): ?>
          <?php render_game_entry_window_card_editor((string) $index, is_array($card) ? $card : game_entry_window_default_card_template($index + 1)); ?>
        <?php endforeach; ?>
      </div>

      <template id="gameEntryWindowCardTemplate">
        <?php render_game_entry_window_card_editor('__INDEX__', game_entry_window_default_card_template()); ?>
      </template>

      <button type="submit" class="neon-btn w-100 py-3">Guardar Ventana Inicial de <?= htmlspecialchars($gameName, ENT_QUOTES, 'UTF-8') ?></button>
    </form>
  </div>
</div>

<script>
  (function () {
    const cardsList = document.querySelector('[data-game-entry-cards-list]');
    const cardTemplate = document.getElementById('gameEntryWindowCardTemplate');
    const addCardButton = document.querySelector('[data-add-card]');
    const titleInput = document.querySelector('[data-window-title-input]');
    const titlePreview = document.querySelector('[data-window-title-preview]');
    const copyInput = document.querySelector('[data-window-copy-input]');
    const copyPreview = document.querySelector('[data-window-copy-preview]');
    const checkInput = document.querySelector('[data-window-check-input]');
    const checkPreview = document.querySelector('[data-window-check-preview]');
    const buttonInput = document.querySelector('[data-window-button-input]');
    const buttonPreview = document.querySelector('[data-window-button-preview]');
    const modalPreview = document.querySelector('[data-window-modal-preview]');
    const modalBackgroundInput = document.querySelector('[data-window-modal-background-input]');
    const modalBorderInput = document.querySelector('[data-window-modal-border-input]');
    const titleColorInput = document.querySelector('[data-window-title-color-input]');
    const checkTextColorInput = document.querySelector('[data-window-check-text-color-input]');
    const checkBackgroundInput = document.querySelector('[data-window-check-background-input]');
    const buttonTextColorInput = document.querySelector('[data-window-button-text-color-input]');
    const buttonBackgroundInput = document.querySelector('[data-window-button-background-input]');
    const buttonDisabledTextColorInput = document.querySelector('[data-window-button-disabled-text-color-input]');
    const buttonDisabledBackgroundInput = document.querySelector('[data-window-button-disabled-background-input]');
    const iconInput = document.querySelector('[data-window-icon-input]');
    const iconPreview = document.querySelector('[data-window-icon-preview]');
    const iconDefaultInput = document.querySelector('[data-window-icon-default-input]');
    const iconDefaultTrigger = document.querySelector('[data-window-icon-default-trigger]');
    const iconDeleteCheckbox = document.getElementById('ventanaInicioJuegoIconoEliminar');
    const cardsPreview = document.querySelector('[data-window-cards-preview]');
    const previewToggleInput = document.querySelector('[data-window-preview-toggle]');
    const defaultIconPath = <?= json_encode($defaultWindowIcon, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;

    function focusVisual(visual) {
      visual.focus();
      const selection = window.getSelection();
      if (!selection) {
        return;
      }
      const range = document.createRange();
      range.selectNodeContents(visual);
      range.collapse(false);
      selection.removeAllRanges();
      selection.addRange(range);
    }

    function isSelectionInside(visual) {
      const selection = window.getSelection();
      if (!selection || selection.rangeCount === 0) {
        return false;
      }

      const range = selection.getRangeAt(0);
      const container = range.commonAncestorContainer;
      return visual === container || visual.contains(container);
    }

    function exec(command, value) {
      document.execCommand(command, false, value);
    }

    function sortCardsForPreview() {
      if (!cardsList) {
        return [];
      }

      return Array.from(cardsList.querySelectorAll('[data-game-entry-card]')).map(function (card, index) {
        const orderInput = card.querySelector('[data-card-order-input]');
        return {
          card: card,
          index: index,
          order: Math.max(1, Number(orderInput && orderInput.value ? orderInput.value : index + 1) || (index + 1))
        };
      }).sort(function (left, right) {
        if (left.order === right.order) {
          return left.index - right.index;
        }
        return left.order - right.order;
      }).map(function (entry) {
        return entry.card;
      });
    }

    function syncWindowStylePreview() {
      if (!modalPreview) {
        return;
      }

      modalPreview.style.setProperty('--window-modal-background', modalBackgroundInput && modalBackgroundInput.value ? modalBackgroundInput.value : '#18101e');
      modalPreview.style.setProperty('--window-modal-border', modalBorderInput && modalBorderInput.value ? modalBorderInput.value : '#fb923c');
      modalPreview.style.setProperty('--window-title-color', titleColorInput && titleColorInput.value ? titleColorInput.value : '#f8b53d');
      modalPreview.style.setProperty('--window-check-color', checkTextColorInput && checkTextColorInput.value ? checkTextColorInput.value : '#e2e8f0');
      modalPreview.style.setProperty('--window-check-background', checkBackgroundInput && checkBackgroundInput.value ? checkBackgroundInput.value : '#1e293b');
      modalPreview.style.setProperty('--window-button-color', buttonTextColorInput && buttonTextColorInput.value ? buttonTextColorInput.value : '#0b0f18');
      modalPreview.style.setProperty('--window-button-background', buttonBackgroundInput && buttonBackgroundInput.value ? buttonBackgroundInput.value : '#c99712');
      modalPreview.style.setProperty('--window-button-disabled-color', buttonDisabledTextColorInput && buttonDisabledTextColorInput.value ? buttonDisabledTextColorInput.value : '#0b0f18');
      modalPreview.style.setProperty('--window-button-disabled-background', buttonDisabledBackgroundInput && buttonDisabledBackgroundInput.value ? buttonDisabledBackgroundInput.value : '#c99712');
      syncPreviewButtonState();
    }

    function syncPreviewButtonState() {
      if (!buttonPreview) {
        return;
      }

      const active = !!(previewToggleInput && previewToggleInput.checked);
      buttonPreview.disabled = !active;
      buttonPreview.classList.toggle('is-active', active);
    }

    function hexToRgba(hex, alpha) {
      const normalized = String(hex || '').trim();
      const match = normalized.match(/^#?([0-9a-f]{6})$/i);
      if (!match) {
        return 'rgba(35, 58, 115, ' + alpha + ')';
      }

      const value = match[1];
      const red = parseInt(value.slice(0, 2), 16);
      const green = parseInt(value.slice(2, 4), 16);
      const blue = parseInt(value.slice(4, 6), 16);
      return 'rgba(' + red + ', ' + green + ', ' + blue + ', ' + alpha + ')';
    }

    function escapeHtml(value) {
      return String(value || '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
    }

    function extractYouTubeId(url) {
      const value = String(url || '').trim();
      if (!value) {
        return null;
      }

      try {
        const parsed = new URL(value, window.location.origin);
        const host = parsed.hostname.replace(/^www\./, '').toLowerCase();
        const path = parsed.pathname.replace(/^\/+|\/+$/g, '');
        if (host === 'youtu.be') {
          return /^[A-Za-z0-9_-]{6,20}$/.test(path) ? path : null;
        }

        if (host.indexOf('youtube.com') !== -1) {
          const candidate = (parsed.searchParams.get('v') || '').trim();
          if (candidate !== '') {
            return /^[A-Za-z0-9_-]{6,20}$/.test(candidate) ? candidate : null;
          }

          const match = path.match(/^(shorts|embed)\/([A-Za-z0-9_-]{6,20})$/);
          if (match) {
            return match[2];
          }
        }
      } catch (error) {
        return null;
      }

      return null;
    }

    function extractTikTokId(url) {
      const value = String(url || '').trim();
      const match = value.match(/\/(?:video|embed\/v2|player\/v1)\/(\d+)/);
      return match ? match[1] : null;
    }

    function isVideoPath(path) {
      return /\.(mp4|webm|ogv|ogg|mov)(\?.*)?$/i.test(String(path || '').trim());
    }

    function renderSavedMediaMarkup(mediaPath, embedUrl) {
      const normalizedEmbed = String(embedUrl || '').trim();
      if (normalizedEmbed) {
        const youtubeId = extractYouTubeId(normalizedEmbed);
        if (youtubeId) {
          return '<div class="game-entry-window-card-media"><iframe class="game-entry-window-card-embed" src="https://www.youtube.com/embed/' + escapeHtml(youtubeId) + '" title="Video informativo" loading="lazy" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" referrerpolicy="strict-origin-when-cross-origin" allowfullscreen></iframe></div>';
        }

        const tiktokId = extractTikTokId(normalizedEmbed);
        if (tiktokId) {
          return '<div class="game-entry-window-card-media"><iframe class="game-entry-window-card-embed game-entry-window-card-embed-tiktok" src="https://www.tiktok.com/player/v1/' + escapeHtml(tiktokId) + '" title="Video informativo" loading="lazy" allow="autoplay; encrypted-media; fullscreen; picture-in-picture" allowfullscreen referrerpolicy="strict-origin-when-cross-origin"></iframe></div>';
        }
      }

      const normalizedPath = String(mediaPath || '').trim();
      if (!normalizedPath) {
        return '';
      }

      if (isVideoPath(normalizedPath)) {
        return '<div class="game-entry-window-card-media"><video class="game-entry-window-card-video" src="' + escapeHtml(normalizedPath) + '" controls playsinline preload="metadata"></video></div>';
      }

      return '<div class="game-entry-window-card-media"><img class="game-entry-window-card-image" src="' + escapeHtml(normalizedPath) + '" alt="Multimedia informativa"></div>';
    }

    function renderUploadMediaMarkup(fileUrl, mimeType) {
      const normalizedUrl = String(fileUrl || '').trim();
      if (!normalizedUrl) {
        return '';
      }

      if (String(mimeType || '').toLowerCase().indexOf('video/') === 0) {
        return '<div class="game-entry-window-card-media"><video class="game-entry-window-card-video" src="' + escapeHtml(normalizedUrl) + '" controls playsinline preload="metadata"></video></div>';
      }

      return '<div class="game-entry-window-card-media"><img class="game-entry-window-card-image" src="' + escapeHtml(normalizedUrl) + '" alt="Multimedia informativa"></div>';
    }

    function getCardMediaMarkup(card) {
      const embedInput = card.querySelector('[data-card-media-embed-input]');
      const removeInput = card.querySelector('[data-card-media-remove-input]');
      const currentMediaPath = card.dataset.currentMediaPath || '';
      const currentMediaEmbed = card.dataset.currentMediaEmbed || '';
      const embedValue = embedInput ? embedInput.value.trim() : '';
      if (embedValue !== '') {
        return renderSavedMediaMarkup('', embedValue);
      }

      if (card.dataset.uploadPreviewUrl) {
        return renderUploadMediaMarkup(card.dataset.uploadPreviewUrl, card.dataset.uploadPreviewMime || '');
      }

      if (removeInput && removeInput.checked) {
        return '';
      }

      return renderSavedMediaMarkup(currentMediaPath, currentMediaEmbed);
    }

    function setIconPreview(src) {
      if (!iconPreview) {
        return;
      }

      iconPreview.innerHTML = '<img src="' + String(src || defaultIconPath) + '" alt="Icono de la ventana">';
    }

    function syncTextarea(editor) {
      const visual = editor.querySelector('[data-editor-visual]');
      const textarea = editor.querySelector('[data-editor-textarea]');
      if (!visual || !textarea) {
        return;
      }
      textarea.value = visual.innerHTML.trim();
      const card = editor.closest('[data-game-entry-card]');
      if (card) {
        syncCardPreview(card);
      }
    }

    function renderPreview(editor) {
      const visual = editor.querySelector('[data-editor-visual]');
      const preview = editor.querySelector('[data-editor-preview]');
      if (!visual || !preview) {
        return;
      }
      preview.innerHTML = visual.innerHTML.trim() === ''
        ? '<em class="text-secondary">Sin contenido HTML.</em>'
        : visual.innerHTML;
    }

    function syncGlobalCardsPreview() {
      if (!cardsList || !cardsPreview) {
        return;
      }

      const fragments = [];
      sortCardsForPreview().forEach(function (card) {
        const active = card.querySelector('[data-card-active-input]');
        const color = card.querySelector('[data-card-color-input]');
        const background = card.querySelector('[data-card-background-input]');
        const htmlField = card.querySelector('[data-editor-textarea]');
        const mediaMarkup = getCardMediaMarkup(card);
        if (!active || !color || !background || !htmlField || !active.checked || (htmlField.value.trim() === '' && mediaMarkup === '')) {
          return;
        }
        fragments.push('<div class="game-entry-window-modal-card" style="--preview-card-color:' + (color.value || '#233A73') + '; --preview-card-background:' + (background.value || '#121a2f') + ';">' + mediaMarkup + htmlField.value + '</div>');
      });

      cardsPreview.innerHTML = fragments.length
        ? fragments.join('')
        : '<div class="game-entry-window-modal-card" style="--preview-card-color:#233A73; --preview-card-background:#121a2f;"><p><strong>Sin tarjetas activas</strong></p><p>Activa al menos una tarjeta para verla en la ventana inicial.</p></div>';
    }

    function syncCardPreview(card) {
      const htmlField = card.querySelector('[data-editor-textarea]');
      const colorInput = card.querySelector('[data-card-color-input]');
      const backgroundInput = card.querySelector('[data-card-background-input]');
      const preview = card.querySelector('[data-card-preview]');
      const mediaMarkup = getCardMediaMarkup(card);
      if (!htmlField || !colorInput || !backgroundInput || !preview) {
        return;
      }

      preview.style.setProperty('--card-preview-color', colorInput.value || '#233A73');
      preview.style.setProperty('--card-preview-background', backgroundInput.value || '#121a2f');
      preview.style.boxShadow = '0 16px 34px ' + hexToRgba(colorInput.value || '#233A73', 0.16) + ', inset 0 0 0 1px rgba(255,255,255,0.02)';
      preview.innerHTML = (mediaMarkup !== '' || htmlField.value.trim() !== '')
        ? mediaMarkup + htmlField.value
        : '<p><strong>Vista previa</strong></p><p>Escribe contenido para ver cómo quedará la tarjeta.</p>';
      syncGlobalCardsPreview();
    }

    function refreshCardOrderLabels() {
      if (!cardsList) {
        return;
      }

      cardsList.querySelectorAll('[data-game-entry-card]').forEach(function (card, index) {
        const label = card.querySelector('[data-card-order-label]');
        if (label) {
          label.textContent = 'Tarjeta ' + (index + 1);
        }
      });
    }

    function initEditor(editor) {
      if (!editor || editor.dataset.editorBound === '1') {
        return;
      }

      const visual = editor.querySelector('[data-editor-visual]');
      const textarea = editor.querySelector('[data-editor-textarea]');
      const preview = editor.querySelector('[data-editor-preview]');
      const previewButton = editor.querySelector('[data-editor-action="preview"]');
      const colorPicker = editor.querySelector('[data-editor-color-picker]');
      if (!visual || !textarea || !preview || !previewButton) {
        return;
      }

      let savedRange = null;

      function saveSelection() {
        const selection = window.getSelection();
        if (!selection || selection.rangeCount === 0 || !isSelectionInside(visual)) {
          return;
        }
        savedRange = selection.getRangeAt(0).cloneRange();
      }

      function restoreSelection() {
        if (!savedRange) {
          return false;
        }
        const selection = window.getSelection();
        if (!selection) {
          return false;
        }
        selection.removeAllRanges();
        selection.addRange(savedRange);
        visual.focus();
        return true;
      }

      editor.dataset.editorBound = '1';
      if (visual.innerHTML.trim() === '') {
        visual.innerHTML = '';
      }

      syncTextarea(editor);
      renderPreview(editor);

      editor.querySelectorAll('[data-editor-action]').forEach(function (button) {
        button.addEventListener('mousedown', function (event) {
          if (button.getAttribute('data-editor-action') !== 'preview') {
            event.preventDefault();
          }
        });
      });

      editor.addEventListener('click', function (event) {
        const button = event.target.closest('[data-editor-action]');
        if (!button) {
          return;
        }

        const action = button.getAttribute('data-editor-action');
        if (action !== 'preview') {
          event.preventDefault();
          if (!restoreSelection()) {
            focusVisual(visual);
          }
        }

        switch (action) {
          case 'paragraph':
            exec('formatBlock', 'p');
            break;
          case 'bold':
            exec('bold');
            break;
          case 'italic':
            exec('italic');
            break;
          case 'underline':
            exec('underline');
            break;
          case 'heading':
            exec('formatBlock', 'h2');
            break;
          case 'subheading':
            exec('formatBlock', 'h3');
            break;
          case 'list':
            exec('insertUnorderedList');
            break;
          case 'ordered-list':
            exec('insertOrderedList');
            break;
          case 'quote':
            exec('formatBlock', 'blockquote');
            break;
          case 'link': {
            const url = window.prompt('URL del enlace', 'https://');
            if (!url) {
              return;
            }
            restoreSelection();
            exec('createLink', url);
            break;
          }
          case 'unlink':
            exec('unlink');
            break;
          case 'clear':
            exec('removeFormat');
            exec('formatBlock', 'p');
            break;
          case 'break':
            exec('insertHTML', '<br>');
            break;
          case 'preview':
            preview.classList.toggle('d-none');
            previewButton.textContent = preview.classList.contains('d-none') ? 'Vista previa' : 'Ocultar vista';
            renderPreview(editor);
            return;
          default:
            return;
        }

        saveSelection();
        syncTextarea(editor);
        renderPreview(editor);
      });

      visual.addEventListener('input', function () {
        saveSelection();
        syncTextarea(editor);
        renderPreview(editor);
      });

      visual.addEventListener('mouseup', saveSelection);
      visual.addEventListener('keyup', saveSelection);
      visual.addEventListener('focus', saveSelection);

      if (colorPicker) {
        colorPicker.addEventListener('input', function () {
          if (!restoreSelection()) {
            focusVisual(visual);
          }
          try {
            document.execCommand('styleWithCSS', false, true);
          } catch (error) {
          }
          exec('foreColor', colorPicker.value || '#dffcff');
          saveSelection();
          syncTextarea(editor);
          renderPreview(editor);
        });
      }

      visual.addEventListener('blur', function () {
        syncTextarea(editor);
      });

      visual.addEventListener('paste', function () {
        window.setTimeout(function () {
          syncTextarea(editor);
          renderPreview(editor);
        }, 0);
      });

      const form = editor.closest('form');
      if (form && form.dataset.editorSubmitBound !== '1') {
        form.dataset.editorSubmitBound = '1';
        form.addEventListener('submit', function () {
          form.querySelectorAll('[data-html-editor]').forEach(function (innerEditor) {
            syncTextarea(innerEditor);
          });
        });
      }
    }

    function wireCard(card) {
      if (!card) {
        return;
      }

      const removeButton = card.querySelector('[data-remove-card]');
      const colorInput = card.querySelector('[data-card-color-input]');
      const backgroundInput = card.querySelector('[data-card-background-input]');
      const activeInput = card.querySelector('[data-card-active-input]');
      const orderInput = card.querySelector('[data-card-order-input]');
      const mediaFileInput = card.querySelector('[data-card-media-file-input]');
      const mediaEmbedInput = card.querySelector('[data-card-media-embed-input]');
      const mediaRemoveInput = card.querySelector('[data-card-media-remove-input]');

      if (removeButton) {
        removeButton.addEventListener('click', function () {
          const cards = cardsList ? cardsList.querySelectorAll('[data-game-entry-card]') : [];
          if (cards.length <= 1) {
            window.alert('Debe existir al menos una tarjeta.');
            return;
          }
          card.remove();
          refreshCardOrderLabels();
          syncGlobalCardsPreview();
        });
      }

      if (colorInput) {
        colorInput.addEventListener('input', function () {
          syncCardPreview(card);
        });
      }

      if (backgroundInput) {
        backgroundInput.addEventListener('input', function () {
          syncCardPreview(card);
        });
      }

      if (activeInput) {
        activeInput.addEventListener('change', function () {
          syncCardPreview(card);
        });
      }

      if (orderInput) {
        orderInput.addEventListener('input', function () {
          refreshCardOrderLabels();
          syncGlobalCardsPreview();
        });
      }

      if (mediaFileInput) {
        mediaFileInput.addEventListener('change', function () {
          const file = mediaFileInput.files && mediaFileInput.files[0] ? mediaFileInput.files[0] : null;
          if (!file) {
            delete card.dataset.uploadPreviewUrl;
            delete card.dataset.uploadPreviewMime;
            syncCardPreview(card);
            return;
          }

          const reader = new FileReader();
          reader.onload = function (event) {
            card.dataset.uploadPreviewUrl = String(event.target && event.target.result ? event.target.result : '');
            card.dataset.uploadPreviewMime = String(file.type || '');
            syncCardPreview(card);
          };
          reader.readAsDataURL(file);
        });
      }

      if (mediaEmbedInput) {
        mediaEmbedInput.addEventListener('input', function () {
          syncCardPreview(card);
        });
      }

      if (mediaRemoveInput) {
        mediaRemoveInput.addEventListener('change', function () {
          syncCardPreview(card);
        });
      }

      card.querySelectorAll('[data-html-editor]').forEach(function (editor) {
        initEditor(editor);
      });
      syncCardPreview(card);
    }

    if (cardsList) {
      cardsList.querySelectorAll('[data-game-entry-card]').forEach(function (card) {
        wireCard(card);
      });
    }

    if (addCardButton && cardsList && cardTemplate) {
      addCardButton.addEventListener('click', function () {
        const nextIndex = cardsList.querySelectorAll('[data-game-entry-card]').length;
        const html = cardTemplate.innerHTML.replace(/__INDEX__/g, String(nextIndex));
        const wrapper = document.createElement('div');
        wrapper.innerHTML = html.trim();
        const card = wrapper.firstElementChild;
        if (!card) {
          return;
        }

        const orderInput = card.querySelector('[data-card-order-input]');
        if (orderInput) {
          orderInput.value = String(nextIndex + 1);
        }

        cardsList.appendChild(card);
        wireCard(card);
        refreshCardOrderLabels();
        syncGlobalCardsPreview();
      });
    }

    if (titleInput && titlePreview) {
      titleInput.addEventListener('input', function () {
        titlePreview.textContent = titleInput.value.trim() || 'ANTES DE CONTINUAR';
      });
    }

    if (copyInput && copyPreview) {
      copyInput.addEventListener('input', function () {
        copyPreview.textContent = copyInput.value.trim() || 'Lee la información antes de continuar con la recarga.';
      });
    }

    if (checkInput && checkPreview) {
      checkInput.addEventListener('input', function () {
        checkPreview.textContent = checkInput.value.trim() || 'He leído y entiendo las condiciones del servicio';
      });
    }

    if (buttonInput && buttonPreview) {
      buttonInput.addEventListener('input', function () {
        buttonPreview.textContent = buttonInput.value.trim() || 'Aceptar y continuar';
      });
    }

    [modalBackgroundInput, modalBorderInput, titleColorInput, checkTextColorInput, checkBackgroundInput, buttonTextColorInput, buttonBackgroundInput, buttonDisabledTextColorInput, buttonDisabledBackgroundInput].forEach(function (input) {
      if (!input) {
        return;
      }
      input.addEventListener('input', syncWindowStylePreview);
    });

    if (previewToggleInput) {
      previewToggleInput.addEventListener('change', syncPreviewButtonState);
    }

    if (iconInput && iconPreview) {
      iconInput.addEventListener('change', function () {
        const file = iconInput.files && iconInput.files[0] ? iconInput.files[0] : null;
        if (!file) {
          return;
        }
        if (iconDefaultInput) {
          iconDefaultInput.value = '0';
        }
        if (iconDeleteCheckbox) {
          iconDeleteCheckbox.checked = false;
        }
        const reader = new FileReader();
        reader.onload = function (event) {
          setIconPreview(String(event.target && event.target.result ? event.target.result : defaultIconPath));
        };
        reader.readAsDataURL(file);
      });
    }

    if (iconDefaultTrigger) {
      iconDefaultTrigger.addEventListener('click', function () {
        if (iconInput) {
          iconInput.value = '';
        }
        if (iconDeleteCheckbox) {
          iconDeleteCheckbox.checked = false;
        }
        if (iconDefaultInput) {
          iconDefaultInput.value = '1';
        }
        setIconPreview(defaultIconPath);
      });
    }

    if (iconDeleteCheckbox) {
      iconDeleteCheckbox.addEventListener('change', function () {
        if (iconDeleteCheckbox.checked) {
          if (iconInput) {
            iconInput.value = '';
          }
          if (iconDefaultInput) {
            iconDefaultInput.value = '0';
          }
          setIconPreview(defaultIconPath);
        }
      });
    }

    document.querySelectorAll('[data-html-editor]').forEach(function (editor) {
      initEditor(editor);
    });
    setIconPreview(<?= json_encode($windowIcon, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>);
    syncWindowStylePreview();
    syncPreviewButtonState();
    refreshCardOrderLabels();
    syncGlobalCardsPreview();
  })();
</script>