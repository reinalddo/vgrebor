<?php
require_once __DIR__ . '/includes/influencer_instructions.php';

if (!function_exists('render_influencer_html_editor')) {
  function render_influencer_html_editor(string $name, string $value, int $rows = 4, string $placeholder = ''): void {
    ?>
    <div class="influencer-html-editor" data-html-editor>
      <div class="influencer-html-toolbar" role="toolbar" aria-label="Herramientas HTML">
        <button type="button" class="btn btn-sm btn-outline-info" data-editor-action="paragraph">P</button>
        <button type="button" class="btn btn-sm btn-outline-info" data-editor-action="bold"><strong>B</strong></button>
        <button type="button" class="btn btn-sm btn-outline-info" data-editor-action="italic"><em>I</em></button>
        <button type="button" class="btn btn-sm btn-outline-info" data-editor-action="underline"><span style="text-decoration:underline;">U</span></button>
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
        data-placeholder="<?= htmlspecialchars($placeholder !== '' ? $placeholder : 'Escribe aqui el contenido con formato visual', ENT_QUOTES, 'UTF-8') ?>"
        style="min-height:<?= max(180, (int) $rows * 28) ?>px;"
      ><?= $value ?></div>
      <textarea name="<?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>" rows="<?= (int) $rows ?>" class="d-none" data-editor-textarea><?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?></textarea>
      <div class="influencer-html-preview d-none" data-editor-preview></div>
      <div class="form-text mt-2">Edita visualmente el contenido. Al guardar, el formato se almacena como HTML sin mostrar etiquetas en el campo.</div>
    </div>
    <?php
  }
}

if (!function_exists('render_influencer_color_fields')) {
  function render_influencer_color_fields(array $fieldLabels, array $colors): void {
    ?>
    <div class="row g-3">
      <?php foreach ($fieldLabels as $colorKey => $colorLabel): ?>
        <div class="col-md-6">
          <label class="form-label small text-uppercase fw-semibold" style="letter-spacing:0.06em;">
            <?= htmlspecialchars($colorLabel, ENT_QUOTES, 'UTF-8') ?>
          </label>
          <input
            type="color"
            name="colors[<?= htmlspecialchars($colorKey, ENT_QUOTES, 'UTF-8') ?>]"
            value="<?= htmlspecialchars((string) ($colors[$colorKey] ?? '#000000'), ENT_QUOTES, 'UTF-8') ?>"
            class="form-control form-control-color w-100"
            style="height:3rem;"
          >
        </div>
      <?php endforeach; ?>
    </div>
    <?php
  }
}

if (!function_exists('influencer_reward_tab_color_labels')) {
  function influencer_reward_tab_color_labels(): array {
    return [
      'active_bg' => 'Tab activo fondo',
      'active_text' => 'Tab activo texto',
      'table_border' => 'Borde de tabla',
      'header_bg' => 'Cabecera fondo',
      'header_text' => 'Cabecera texto',
      'body_bg' => 'Tabla fondo',
      'body_text' => 'Tabla texto',
      'highlight_bg' => 'Fila destacada fondo',
      'highlight_text' => 'Fila destacada texto',
    ];
  }
}

if (!function_exists('render_influencer_reward_row_editor')) {
  function render_influencer_reward_row_editor(string $tabIndexToken, string $rowIndexToken, array $rewardRow): void {
    $viewsName = 'video_rewards_tabs[' . $tabIndexToken . '][rows][' . $rowIndexToken . '][views]';
    $rewardName = 'video_rewards_tabs[' . $tabIndexToken . '][rows][' . $rowIndexToken . '][reward]';
    ?>
    <div class="influencer-admin-reward-row" data-reward-row>
      <div class="influencer-admin-reward-row-header">
        <span class="influencer-admin-item-label mb-0" data-reward-row-order>Fila</span>
        <button type="button" class="btn btn-sm btn-outline-danger" data-remove-reward-row>Eliminar fila</button>
      </div>
      <div class="row g-3 align-items-end">
        <div class="col-md-6">
          <label class="form-label" data-reward-views-label>Rango de vistas</label>
          <input
            type="text"
            name="<?= htmlspecialchars($viewsName, ENT_QUOTES, 'UTF-8') ?>"
            data-name-template="video_rewards_tabs[__TAB_INDEX__][rows][__ROW_INDEX__][views]"
            value="<?= htmlspecialchars((string) ($rewardRow['views'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
            class="form-control"
            placeholder="1,000 - 3,999"
          >
        </div>
        <div class="col-md-6">
          <label class="form-label" data-reward-prize-label>Premio</label>
          <input
            type="text"
            name="<?= htmlspecialchars($rewardName, ENT_QUOTES, 'UTF-8') ?>"
            data-name-template="video_rewards_tabs[__TAB_INDEX__][rows][__ROW_INDEX__][reward]"
            value="<?= htmlspecialchars((string) ($rewardRow['reward'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
            class="form-control"
            placeholder="110 💎"
          >
        </div>
      </div>
    </div>
    <?php
  }
}

if (!function_exists('render_influencer_reward_tab_editor')) {
  function render_influencer_reward_tab_editor(string $tabIndexToken, array $rewardTab, bool $renderRows = true): void {
    $prefix = 'video_rewards_tabs[' . $tabIndexToken . ']';
    $rows = is_array($rewardTab['rows'] ?? null) ? $rewardTab['rows'] : [];
    if ($renderRows && $rows === []) {
      $rows = [['views' => '', 'reward' => '']];
    }
    ?>
    <div class="influencer-admin-reward-tab-card" data-reward-tab>
      <div class="influencer-admin-reward-tab-card-header">
        <div>
          <div class="influencer-admin-reward-tab-kicker" data-reward-tab-order>Tab</div>
          <h4 class="influencer-admin-reward-tab-title" data-reward-tab-title><?= htmlspecialchars((string) ($rewardTab['label'] ?? ''), ENT_QUOTES, 'UTF-8') ?></h4>
        </div>
        <button type="button" class="btn btn-sm btn-outline-warning" data-remove-reward-tab>Eliminar tab</button>
      </div>

      <div class="row g-3 mb-4">
        <div class="col-md-4">
          <label class="form-label">Nombre del tab</label>
          <input
            type="text"
            name="<?= htmlspecialchars($prefix . '[label]', ENT_QUOTES, 'UTF-8') ?>"
            data-name-template="video_rewards_tabs[__TAB_INDEX__][label]"
            data-reward-tab-field="label"
            value="<?= htmlspecialchars((string) ($rewardTab['label'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
            class="form-control"
          >
        </div>
        <div class="col-md-2">
          <label class="form-label">Emoji del tab</label>
          <input
            type="text"
            name="<?= htmlspecialchars($prefix . '[tab_emoji]', ENT_QUOTES, 'UTF-8') ?>"
            data-name-template="video_rewards_tabs[__TAB_INDEX__][tab_emoji]"
            value="<?= htmlspecialchars((string) ($rewardTab['tab_emoji'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
            class="form-control"
            maxlength="8"
            placeholder="🔥"
          >
        </div>
        <div class="col-md-3">
          <label class="form-label">Titulo columna izquierda</label>
          <input
            type="text"
            name="<?= htmlspecialchars($prefix . '[views_label]', ENT_QUOTES, 'UTF-8') ?>"
            data-name-template="video_rewards_tabs[__TAB_INDEX__][views_label]"
            value="<?= htmlspecialchars((string) ($rewardTab['views_label'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
            class="form-control"
          >
        </div>
        <div class="col-md-3">
          <label class="form-label">Emoji columna izquierda</label>
          <input
            type="text"
            name="<?= htmlspecialchars($prefix . '[views_emoji]', ENT_QUOTES, 'UTF-8') ?>"
            data-name-template="video_rewards_tabs[__TAB_INDEX__][views_emoji]"
            value="<?= htmlspecialchars((string) ($rewardTab['views_emoji'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
            class="form-control"
            maxlength="8"
            placeholder="👁️"
          >
        </div>
        <div class="col-md-6">
          <label class="form-label">Titulo columna premio</label>
          <input
            type="text"
            name="<?= htmlspecialchars($prefix . '[reward_label]', ENT_QUOTES, 'UTF-8') ?>"
            data-name-template="video_rewards_tabs[__TAB_INDEX__][reward_label]"
            value="<?= htmlspecialchars((string) ($rewardTab['reward_label'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
            class="form-control"
          >
        </div>
        <div class="col-md-6">
          <label class="form-label">Emoji columna premio</label>
          <input
            type="text"
            name="<?= htmlspecialchars($prefix . '[reward_emoji]', ENT_QUOTES, 'UTF-8') ?>"
            data-name-template="video_rewards_tabs[__TAB_INDEX__][reward_emoji]"
            value="<?= htmlspecialchars((string) ($rewardTab['reward_emoji'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
            class="form-control"
            maxlength="8"
            placeholder="💎"
          >
        </div>
      </div>

      <div class="row g-3 mb-4">
        <?php foreach (influencer_reward_tab_color_labels() as $colorKey => $colorLabel): ?>
          <div class="col-md-4 col-xl-3">
            <label class="form-label small text-uppercase fw-semibold" style="letter-spacing:0.06em;"><?= htmlspecialchars($colorLabel, ENT_QUOTES, 'UTF-8') ?></label>
            <input
              type="color"
              name="<?= htmlspecialchars($prefix . '[' . $colorKey . ']', ENT_QUOTES, 'UTF-8') ?>"
              data-name-template="video_rewards_tabs[__TAB_INDEX__][<?= htmlspecialchars($colorKey, ENT_QUOTES, 'UTF-8') ?>]"
              value="<?= htmlspecialchars((string) ($rewardTab[$colorKey] ?? '#000000'), ENT_QUOTES, 'UTF-8') ?>"
              class="form-control form-control-color w-100"
              style="height:3rem;"
            >
          </div>
        <?php endforeach; ?>
      </div>

      <div class="influencer-admin-reward-rows" data-reward-rows>
        <?php if ($renderRows): ?>
          <?php foreach ($rows as $rowIndex => $rewardRow): ?>
            <?php render_influencer_reward_row_editor($tabIndexToken, (string) $rowIndex, is_array($rewardRow) ? $rewardRow : []); ?>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>

      <div class="d-flex justify-content-end mt-3">
        <button type="button" class="btn btn-sm btn-outline-info" data-add-reward-row>Agregar fila</button>
      </div>
    </div>
    <?php
  }
}

$influencerData = influencer_instructions_get();
$iconOptions = influencer_instructions_icon_options();
$hero = $influencerData['hero'] ?? [];
$benefits = $influencerData['benefits'] ?? [];
$videoRewards = $influencerData['video_rewards'] ?? [];
$rewardTabs = is_array($videoRewards['tabs'] ?? null) ? $videoRewards['tabs'] : [];
$rewardTabs = $rewardTabs !== [] ? array_values($rewardTabs) : [influencer_instructions_reward_tab_template()];
$steps = $influencerData['steps'] ?? [];
$notes = $influencerData['notes'] ?? [];
$closing = $influencerData['closing'] ?? [];
$colors = $influencerData['colors'] ?? [];
$heroImagePreview = influencer_instructions_asset_url((string) ($hero['image'] ?? ''));

$heroColorFields = [
  'hero_surface' => 'Fondo del hero',
  'hero_accent' => 'Acento superior',
  'hero_title' => 'Titulo principal',
  'hero_text' => 'Texto del hero',
  'hero_button_bg' => 'Boton principal fondo',
  'hero_button_text' => 'Boton principal texto',
  'hero_secondary_bg' => 'Boton secundario fondo',
  'hero_secondary_text' => 'Boton secundario texto',
];

$stepsColorFields = [
  'steps_surface' => 'Fondo de seccion',
  'steps_label' => 'Etiqueta superior',
  'steps_title' => 'Titulo de seccion',
  'steps_text' => 'Texto descriptivo',
  'steps_card_bg' => 'Tarjetas fondo',
  'steps_card_title' => 'Tarjetas titulo',
  'steps_card_text' => 'Tarjetas texto',
  'steps_icon_bg' => 'Iconos fondo',
  'steps_icon_color' => 'Iconos color',
];

$benefitsColorFields = [
  'benefits_surface' => 'Fondo de seccion',
  'benefits_label' => 'Etiqueta superior',
  'benefits_title' => 'Titulo de seccion',
  'benefits_text' => 'Texto descriptivo',
  'benefits_card_bg' => 'Tarjetas fondo',
  'benefits_card_title' => 'Tarjetas titulo',
  'benefits_card_text' => 'Tarjetas texto',
];

$videoRewardsColorFields = [
  'video_rewards_surface' => 'Fondo de seccion',
  'video_rewards_title' => 'Titulo principal',
  'video_rewards_text' => 'Texto descriptivo',
];

$notesColorFields = [
  'notes_surface' => 'Fondo de seccion',
  'notes_label' => 'Etiqueta superior',
  'notes_title' => 'Titulo de seccion',
  'notes_text' => 'Texto descriptivo',
  'notes_card_bg' => 'Tarjetas fondo',
  'notes_card_title' => 'Tarjetas titulo',
  'notes_card_text' => 'Tarjetas texto',
  'notes_icon_bg' => 'Iconos fondo',
  'notes_icon_color' => 'Iconos color',
];

$closingColorFields = [
  'closing_surface' => 'Fondo de cierre',
  'closing_label' => 'Etiqueta superior',
  'closing_title' => 'Titulo de cierre',
  'closing_text' => 'Texto de cierre',
  'closing_button_bg' => 'Boton final fondo',
  'closing_button_text' => 'Boton final texto',
];
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
  .influencer-html-preview :last-child {
    margin-bottom: 0;
  }
  .influencer-admin-form {
    display: grid;
    gap: 1.5rem;
  }
  .influencer-admin-intro {
    border: 1px solid rgba(34, 211, 238, 0.18);
    border-radius: 1.1rem;
    padding: 1rem 1.15rem;
    background: linear-gradient(135deg, rgba(12, 29, 41, 0.95), rgba(11, 19, 30, 0.98));
  }
  .influencer-admin-section {
    border: 1px solid rgba(148, 163, 184, 0.16);
    border-radius: 1.35rem;
    padding: 1.35rem;
    background: rgba(8, 15, 25, 0.96);
    box-shadow: 0 16px 34px rgba(2, 6, 23, 0.22);
  }
  .influencer-admin-section--hero {
    background: linear-gradient(135deg, rgba(10, 29, 39, 0.98), rgba(8, 16, 26, 0.98));
    border-color: rgba(34, 211, 238, 0.26);
  }
  .influencer-admin-section--steps {
    background: linear-gradient(135deg, rgba(9, 26, 33, 0.98), rgba(10, 18, 28, 0.98));
    border-color: rgba(94, 234, 212, 0.22);
  }
  .influencer-admin-section--benefits {
    background: linear-gradient(135deg, rgba(32, 23, 7, 0.95), rgba(12, 16, 24, 0.98));
    border-color: rgba(251, 191, 36, 0.22);
  }
  .influencer-admin-section--notes {
    background: linear-gradient(135deg, rgba(39, 24, 9, 0.92), rgba(12, 18, 28, 0.98));
    border-color: rgba(245, 158, 11, 0.24);
  }
  .influencer-admin-section--rewards {
    background: linear-gradient(135deg, rgba(13, 18, 33, 0.98), rgba(7, 12, 24, 0.98));
    border-color: rgba(250, 204, 21, 0.2);
  }
  .influencer-admin-section--closing {
    background: linear-gradient(135deg, rgba(11, 21, 36, 0.98), rgba(6, 13, 24, 0.98));
    border-color: rgba(34, 197, 94, 0.22);
  }
  .influencer-admin-section-header {
    display: flex;
    gap: 1rem;
    align-items: flex-start;
    margin-bottom: 1.15rem;
  }
  .influencer-admin-section-order {
    width: 2.35rem;
    height: 2.35rem;
    border-radius: 999px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 0.95rem;
    color: #06131a;
    background: linear-gradient(135deg, #22d3ee, #34d399);
    box-shadow: 0 0 0 4px rgba(34, 211, 238, 0.08);
    flex: 0 0 auto;
  }
  .influencer-admin-section--benefits .influencer-admin-section-order {
    background: linear-gradient(135deg, #fbbf24, #f59e0b);
  }
  .influencer-admin-section--notes .influencer-admin-section-order {
    background: linear-gradient(135deg, #f59e0b, #fb7185);
  }
  .influencer-admin-section--rewards .influencer-admin-section-order {
    background: linear-gradient(135deg, #facc15, #fb923c);
  }
  .influencer-admin-section--closing .influencer-admin-section-order {
    background: linear-gradient(135deg, #34d399, #22d3ee);
  }
  .influencer-admin-section-title {
    margin: 0;
    font-family: 'Oxanium', 'Montserrat', 'Arial', sans-serif;
    letter-spacing: 0.04em;
  }
  .influencer-admin-section-desc {
    margin: 0.3rem 0 0;
    color: #bfeef6;
  }
  .influencer-admin-subcard {
    border: 1px solid rgba(148, 163, 184, 0.15);
    border-radius: 1rem;
    padding: 1rem;
    background: rgba(6, 11, 19, 0.44);
  }
  .influencer-admin-subcard--colors {
    background: rgba(4, 16, 24, 0.72);
  }
  .influencer-admin-subtitle {
    margin: 0 0 0.85rem;
    font-size: 1rem;
    font-weight: 700;
    color: #dffcff;
  }
  .influencer-admin-item-card {
    border: 1px solid rgba(148, 163, 184, 0.16);
    border-radius: 1rem;
    padding: 1rem;
    background: rgba(7, 12, 20, 0.66);
  }
  .influencer-admin-item-card + .influencer-admin-item-card {
    margin-top: 1rem;
  }
  .influencer-admin-item-label {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.35rem 0.7rem;
    border-radius: 999px;
    background: rgba(34, 211, 238, 0.08);
    color: #8af3ff;
    font-size: 0.78rem;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    font-weight: 700;
    margin-bottom: 0.85rem;
  }
  .influencer-admin-field-note {
    color: #91c8d4;
    font-size: 0.88rem;
    margin-top: 0.35rem;
  }
  .influencer-admin-reward-tab-card {
    border: 1px solid rgba(250, 204, 21, 0.14);
    border-radius: 1rem;
    padding: 1rem;
    background: rgba(9, 14, 24, 0.84);
  }
  .influencer-admin-reward-builder-toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    flex-wrap: wrap;
    margin-bottom: 1rem;
  }
  .influencer-admin-reward-builder-note {
    margin: 0;
    color: #bfeef6;
  }
  .influencer-admin-reward-nav {
    display: flex;
    gap: 0.75rem;
    flex-wrap: nowrap;
    overflow-x: auto;
    padding-bottom: 0.4rem;
    margin-bottom: 1rem;
    scrollbar-width: thin;
    scrollbar-color: rgba(34, 211, 238, 0.65) rgba(8, 15, 25, 0.45);
  }
  .influencer-admin-reward-nav::-webkit-scrollbar {
    height: 8px;
  }
  .influencer-admin-reward-nav::-webkit-scrollbar-thumb {
    background: rgba(34, 211, 238, 0.65);
    border-radius: 999px;
  }
  .influencer-admin-reward-nav::-webkit-scrollbar-track {
    background: rgba(8, 15, 25, 0.45);
    border-radius: 999px;
  }
  .influencer-admin-reward-nav-button {
    border: 1px solid rgba(34, 211, 238, 0.28);
    background: rgba(7, 12, 20, 0.86);
    color: #bfeef6;
    border-radius: 999px;
    padding: 0.72rem 1rem;
    min-width: 150px;
    max-width: 260px;
    text-align: left;
    display: grid;
    gap: 0.2rem;
    flex: 0 0 auto;
    transition: border-color 0.2s ease, background 0.2s ease, color 0.2s ease, transform 0.2s ease;
  }
  .influencer-admin-reward-nav-button:hover {
    border-color: rgba(250, 204, 21, 0.55);
    color: #fef3c7;
    transform: translateY(-1px);
  }
  .influencer-admin-reward-nav-button.is-active {
    border-color: rgba(250, 204, 21, 0.75);
    background: linear-gradient(135deg, rgba(59, 48, 18, 0.95), rgba(18, 24, 35, 0.96));
    color: #fff6cf;
    box-shadow: 0 10px 20px rgba(2, 6, 23, 0.24);
  }
  .influencer-admin-reward-nav-index {
    font-size: 0.72rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: #7dd3fc;
  }
  .influencer-admin-reward-nav-button.is-active .influencer-admin-reward-nav-index {
    color: #fcd34d;
  }
  .influencer-admin-reward-nav-label {
    font-size: 0.95rem;
    font-weight: 700;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }
  .influencer-admin-reward-tab-card-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 1rem;
    margin-bottom: 0.85rem;
    flex-wrap: wrap;
  }
  .influencer-admin-reward-tab-kicker {
    font-size: 0.78rem;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: #fcd34d;
    font-weight: 700;
    margin-bottom: 0.35rem;
  }
  .influencer-admin-reward-tab-title {
    margin: 0 0 0.85rem;
    color: #fde68a;
    font-size: 1rem;
    font-weight: 700;
  }
  .influencer-admin-reward-tab-card.is-hidden {
    display: none;
  }
  .influencer-admin-reward-rows {
    display: grid;
    gap: 0.75rem;
  }
  .influencer-admin-reward-row {
    border: 1px solid rgba(148, 163, 184, 0.12);
    border-radius: 0.9rem;
    padding: 0.85rem;
    background: rgba(5, 10, 18, 0.7);
  }
  .influencer-admin-reward-row-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.75rem;
    flex-wrap: wrap;
    margin-bottom: 0.85rem;
  }
  .influencer-hero-image-preview {
    width: 100%;
    aspect-ratio: 1 / 1;
    min-height: 0 !important;
    border-radius: 1rem;
    overflow: hidden;
    background: rgba(6, 11, 19, 0.88);
    border: 1px solid rgba(148, 163, 184, 0.16);
    display: flex;
    align-items: center;
    justify-content: center;
  }
  .influencer-hero-image-preview img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
  }
  .influencer-hero-image-empty {
    color: #91c8d4;
    font-weight: 600;
    text-align: center;
    padding: 1.25rem;
  }
</style>
<div class="card neon-card mb-4">
  <div class="card-header text-center py-4" style="background: linear-gradient(90deg, var(--theme-highlight) 0%, var(--theme-success) 100%); color: var(--theme-button-text-strong); border-radius: 16px 16px 0 0;">
    <h2 class="h4 fw-bold mb-0" style="font-family: 'Oxanium', 'Montserrat', 'Arial', sans-serif; letter-spacing: 0.08em;">
      Instrucciones Influencer
    </h2>
  </div>
  <div class="card-body p-4">
    <form method="post" enctype="multipart/form-data" class="influencer-admin-form">
      <input type="hidden" name="influencer_instructions_save" value="1">
      <div class="influencer-admin-intro">
        <div class="config-section-note mb-0">
          Este modulo ahora esta ordenado en el mismo recorrido que sigue la landing publica: Hero principal, Como funciona, Beneficios, Notas especiales y cierre final. Edita cada bloque de arriba hacia abajo y los colores de cada seccion dentro de su propio bloque.
        </div>
      </div>

      <section class="influencer-admin-section influencer-admin-section--hero">
        <div class="influencer-admin-section-header">
          <span class="influencer-admin-section-order">1</span>
          <div>
            <h3 class="influencer-admin-section-title h4 text-info">Hero principal y boton del menu</h3>
            <p class="influencer-admin-section-desc">Este es el primer bloque que ve el usuario. Aqui configuras el boton destacado del header, la cabecera principal, la imagen y los dos botones de accion.</p>
          </div>
        </div>

        <div class="row g-4 align-items-start">
          <div class="col-xl-8 d-grid gap-4">
            <div class="influencer-admin-subcard">
              <h4 class="influencer-admin-subtitle">Acceso desde el header</h4>
              <label class="form-label">Texto del boton destacado del menu</label>
              <input type="text" name="menu_label" value="<?= htmlspecialchars($influencerData['menu_label'] ?? 'Quiero Unirme', ENT_QUOTES, 'UTF-8') ?>" class="form-control">
              <div class="influencer-admin-field-note">Este texto es el que se muestra en el boton publico del menu/header.</div>
            </div>

            <div class="influencer-admin-subcard d-grid gap-3">
              <h4 class="influencer-admin-subtitle">Contenido del hero</h4>
              <div>
                <label class="form-label">Etiqueta superior</label>
                <input type="text" name="hero_badge" value="<?= htmlspecialchars($hero['badge'] ?? '', ENT_QUOTES, 'UTF-8') ?>" class="form-control">
              </div>
              <div>
                <label class="form-label">Titulo principal</label>
                <input type="text" name="hero_title" value="<?= htmlspecialchars($hero['title'] ?? '', ENT_QUOTES, 'UTF-8') ?>" class="form-control">
              </div>
              <div>
                <label class="form-label">Texto descriptivo del hero</label>
                <?php render_influencer_html_editor('hero_lead_html', (string) ($hero['lead_html'] ?? ''), 6, 'Describe la propuesta principal del programa'); ?>
              </div>
            </div>

            <div class="influencer-admin-subcard d-grid gap-3">
              <h4 class="influencer-admin-subtitle">Botones del hero</h4>
              <div class="row g-3">
                <div class="col-md-6">
                  <label class="form-label">Texto boton principal</label>
                  <input type="text" name="hero_primary_label" value="<?= htmlspecialchars($hero['primary_label'] ?? '', ENT_QUOTES, 'UTF-8') ?>" class="form-control">
                </div>
                <div class="col-md-6">
                  <label class="form-label">URL boton principal</label>
                  <input type="text" name="hero_primary_url" value="<?= htmlspecialchars($hero['primary_url'] ?? '', ENT_QUOTES, 'UTF-8') ?>" class="form-control" placeholder="https://... o /ruta o #ancla">
                </div>
                <div class="col-md-6">
                  <label class="form-label">Texto boton secundario</label>
                  <input type="text" name="hero_secondary_label" value="<?= htmlspecialchars($hero['secondary_label'] ?? '', ENT_QUOTES, 'UTF-8') ?>" class="form-control">
                </div>
                <div class="col-md-6">
                  <label class="form-label">URL boton secundario</label>
                  <input type="text" name="hero_secondary_url" value="<?= htmlspecialchars($hero['secondary_url'] ?? '', ENT_QUOTES, 'UTF-8') ?>" class="form-control" placeholder="#pasos-influencer">
                </div>
              </div>
            </div>
          </div>

          <div class="col-xl-4 d-grid gap-4">
            <div class="influencer-admin-subcard">
              <h4 class="influencer-admin-subtitle">Imagen principal</h4>
              <div
                class="header-logo-preview influencer-hero-image-preview mb-3"
                data-hero-image-preview
                data-default-src="<?= htmlspecialchars($heroImagePreview, ENT_QUOTES, 'UTF-8') ?>"
              >
                <?php if ($heroImagePreview !== ''): ?>
                  <img src="<?= htmlspecialchars($heroImagePreview, ENT_QUOTES, 'UTF-8') ?>" alt="Imagen Influencer" data-hero-image-preview-img>
                <?php else: ?>
                  <span class="header-logo-empty influencer-hero-image-empty" data-hero-image-empty>Sin imagen</span>
                <?php endif; ?>
              </div>
              <input type="file" name="hero_image" accept="image/png,image/jpeg,image/webp,image/gif" class="form-control" data-hero-image-input>
              <div class="form-text mt-2">Formatos permitidos: JPG, PNG, WEBP o GIF. Tamano maximo: 4 MB.</div>
              <div class="form-check mt-3">
                <input class="form-check-input" type="checkbox" value="1" id="removeHeroImage" name="remove_hero_image" data-hero-image-remove>
                <label class="form-check-label" for="removeHeroImage">Eliminar imagen actual</label>
              </div>
            </div>

            <div class="influencer-admin-subcard influencer-admin-subcard--colors">
              <h4 class="influencer-admin-subtitle">Colores del hero</h4>
              <?php render_influencer_color_fields($heroColorFields, $colors); ?>
            </div>
          </div>
        </div>
      </section>

      <section class="influencer-admin-section influencer-admin-section--steps">
        <div class="influencer-admin-section-header">
          <span class="influencer-admin-section-order">2</span>
          <div>
            <h3 class="influencer-admin-section-title h4 text-info">Como funciona</h3>
            <p class="influencer-admin-section-desc">Este es el segundo bloque de la landing. Debe explicar el proceso en orden y de forma inmediata para que el usuario entienda los pasos sin leer de mas.</p>
          </div>
        </div>

        <div class="row g-4">
          <div class="col-xl-7 d-grid gap-4">
            <div class="influencer-admin-subcard d-grid gap-3">
              <h4 class="influencer-admin-subtitle">Encabezado de la seccion</h4>
              <div>
                <label class="form-label">Etiqueta superior</label>
                <input type="text" name="steps_eyebrow" value="<?= htmlspecialchars($steps['eyebrow'] ?? '', ENT_QUOTES, 'UTF-8') ?>" class="form-control" placeholder="Etiqueta superior">
              </div>
              <div>
                <label class="form-label">Titulo de la seccion</label>
                <input type="text" name="steps_title" value="<?= htmlspecialchars($steps['title'] ?? '', ENT_QUOTES, 'UTF-8') ?>" class="form-control" placeholder="Titulo del bloque">
              </div>
              <div>
                <label class="form-label">Texto introductorio</label>
                <?php render_influencer_html_editor('steps_intro_html', (string) ($steps['intro_html'] ?? ''), 4, 'Explica brevemente como funciona el proceso'); ?>
              </div>
            </div>

            <?php foreach (($steps['items'] ?? []) as $index => $item): ?>
              <div class="influencer-admin-item-card">
                <div class="influencer-admin-item-label">Paso <?= $index + 1 ?></div>
                <div class="row g-3">
                  <div class="col-md-4">
                    <label class="form-label">Icono</label>
                    <select name="steps_items[<?= $index ?>][icon]" class="form-select">
                      <?php foreach ($iconOptions as $iconKey => $iconLabel): ?>
                        <option value="<?= htmlspecialchars($iconKey, ENT_QUOTES, 'UTF-8') ?>" <?= ($item['icon'] ?? '') === $iconKey ? 'selected' : '' ?>><?= htmlspecialchars($iconLabel, ENT_QUOTES, 'UTF-8') ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="col-md-8">
                    <label class="form-label">Titulo del paso</label>
                    <input type="text" name="steps_items[<?= $index ?>][title]" value="<?= htmlspecialchars($item['title'] ?? '', ENT_QUOTES, 'UTF-8') ?>" class="form-control">
                  </div>
                  <div class="col-12">
                    <label class="form-label">Contenido del paso</label>
                    <?php render_influencer_html_editor('steps_items[' . $index . '][html]', (string) ($item['html'] ?? ''), 4); ?>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>

          <div class="col-xl-5">
            <div class="influencer-admin-subcard influencer-admin-subcard--colors">
              <h4 class="influencer-admin-subtitle">Colores de Como funciona</h4>
              <?php render_influencer_color_fields($stepsColorFields, $colors); ?>
            </div>
          </div>
        </div>
      </section>

      <section class="influencer-admin-section influencer-admin-section--benefits">
        <div class="influencer-admin-section-header">
          <span class="influencer-admin-section-order">3</span>
          <div>
            <h3 class="influencer-admin-section-title h4 text-info">Beneficios y propuesta de valor</h3>
            <p class="influencer-admin-section-desc">Este bloque va despues de Como funciona. Sirve para explicar comisiones, ventajas o cualquier razon comercial para unirse al programa.</p>
          </div>
        </div>

        <div class="row g-4">
          <div class="col-xl-7 d-grid gap-4">
            <div class="influencer-admin-subcard d-grid gap-3">
              <h4 class="influencer-admin-subtitle">Encabezado de beneficios</h4>
              <div>
                <label class="form-label">Etiqueta superior</label>
                <input type="text" name="benefits_eyebrow" value="<?= htmlspecialchars($benefits['eyebrow'] ?? '', ENT_QUOTES, 'UTF-8') ?>" class="form-control" placeholder="Etiqueta superior">
              </div>
              <div>
                <label class="form-label">Titulo del bloque</label>
                <input type="text" name="benefits_title" value="<?= htmlspecialchars($benefits['title'] ?? '', ENT_QUOTES, 'UTF-8') ?>" class="form-control" placeholder="Titulo del bloque">
              </div>
              <div>
                <label class="form-label">Texto introductorio</label>
                <?php render_influencer_html_editor('benefits_intro_html', (string) ($benefits['intro_html'] ?? ''), 4, 'Describe rapidamente que gana el usuario'); ?>
              </div>
            </div>

            <?php foreach (($benefits['items'] ?? []) as $index => $item): ?>
              <div class="influencer-admin-item-card">
                <div class="influencer-admin-item-label">Tarjeta beneficio <?= $index + 1 ?></div>
                <label class="form-label">Titulo de la tarjeta</label>
                <input type="text" name="benefits_items[<?= $index ?>][title]" value="<?= htmlspecialchars($item['title'] ?? '', ENT_QUOTES, 'UTF-8') ?>" class="form-control mb-3">
                <label class="form-label">Contenido de la tarjeta</label>
                <?php render_influencer_html_editor('benefits_items[' . $index . '][html]', (string) ($item['html'] ?? ''), 4); ?>
              </div>
            <?php endforeach; ?>
          </div>

          <div class="col-xl-5">
            <div class="influencer-admin-subcard influencer-admin-subcard--colors">
              <h4 class="influencer-admin-subtitle">Colores de beneficios</h4>
              <?php render_influencer_color_fields($benefitsColorFields, $colors); ?>
            </div>
          </div>
        </div>
      </section>

      <section class="influencer-admin-section influencer-admin-section--notes">
        <div class="influencer-admin-section-header">
          <span class="influencer-admin-section-order">4</span>
          <div>
            <h3 class="influencer-admin-section-title h4 text-info">Notas especiales, reglas o condiciones</h3>
            <p class="influencer-admin-section-desc">Usa esta zona para requisitos, aclaratorias o reglas del programa. Cada tarjeta debe responder a una duda importante antes del cierre final.</p>
          </div>
        </div>

        <div class="row g-4">
          <div class="col-xl-7 d-grid gap-4">
            <div class="influencer-admin-subcard d-grid gap-3">
              <h4 class="influencer-admin-subtitle">Encabezado de notas</h4>
              <div>
                <label class="form-label">Etiqueta superior</label>
                <input type="text" name="notes_eyebrow" value="<?= htmlspecialchars($notes['eyebrow'] ?? '', ENT_QUOTES, 'UTF-8') ?>" class="form-control" placeholder="Etiqueta superior">
              </div>
              <div>
                <label class="form-label">Titulo del bloque</label>
                <input type="text" name="notes_title" value="<?= htmlspecialchars($notes['title'] ?? '', ENT_QUOTES, 'UTF-8') ?>" class="form-control" placeholder="Titulo del bloque">
              </div>
              <div>
                <label class="form-label">Texto introductorio</label>
                <?php render_influencer_html_editor('notes_intro_html', (string) ($notes['intro_html'] ?? ''), 4, 'Introduce las condiciones o aclaratorias'); ?>
              </div>
            </div>

            <?php foreach (($notes['items'] ?? []) as $index => $item): ?>
              <div class="influencer-admin-item-card">
                <div class="influencer-admin-item-label">Nota <?= $index + 1 ?></div>
                <div class="row g-3">
                  <div class="col-md-4">
                    <label class="form-label">Icono</label>
                    <select name="notes_items[<?= $index ?>][icon]" class="form-select">
                      <?php foreach ($iconOptions as $iconKey => $iconLabel): ?>
                        <option value="<?= htmlspecialchars($iconKey, ENT_QUOTES, 'UTF-8') ?>" <?= ($item['icon'] ?? '') === $iconKey ? 'selected' : '' ?>><?= htmlspecialchars($iconLabel, ENT_QUOTES, 'UTF-8') ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="col-md-8">
                    <label class="form-label">Titulo de la nota</label>
                    <input type="text" name="notes_items[<?= $index ?>][title]" value="<?= htmlspecialchars($item['title'] ?? '', ENT_QUOTES, 'UTF-8') ?>" class="form-control">
                  </div>
                  <div class="col-12">
                    <label class="form-label">Contenido de la nota</label>
                    <?php render_influencer_html_editor('notes_items[' . $index . '][html]', (string) ($item['html'] ?? ''), 4); ?>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>

          <div class="col-xl-5">
            <div class="influencer-admin-subcard influencer-admin-subcard--colors">
              <h4 class="influencer-admin-subtitle">Colores de notas especiales</h4>
              <?php render_influencer_color_fields($notesColorFields, $colors); ?>
            </div>
          </div>
        </div>
      </section>

      <section class="influencer-admin-section influencer-admin-section--rewards">
        <div class="influencer-admin-section-header">
          <span class="influencer-admin-section-order">5</span>
          <div>
            <h3 class="influencer-admin-section-title h4 text-info">Tabla de recompensas por video</h3>
            <p class="influencer-admin-section-desc">Configura una serie de tabs con tablas de rangos por juego, incluyendo colores, emojis y el premio que recibe el influencer segun las vistas.</p>
          </div>
        </div>

        <div class="row g-4 mb-4">
          <div class="col-xl-7 d-grid gap-4">
            <div class="influencer-admin-subcard d-grid gap-3">
              <h4 class="influencer-admin-subtitle">Encabezado de la tabla</h4>
              <div>
                <label class="form-label">Etiqueta superior</label>
                <input type="text" name="video_rewards_eyebrow" value="<?= htmlspecialchars($videoRewards['eyebrow'] ?? '', ENT_QUOTES, 'UTF-8') ?>" class="form-control" placeholder="RECOMPENSAS EXTRA">
              </div>
              <div>
                <label class="form-label">Titulo del bloque</label>
                <input type="text" name="video_rewards_title" value="<?= htmlspecialchars($videoRewards['title'] ?? '', ENT_QUOTES, 'UTF-8') ?>" class="form-control" placeholder="Bono por Video en HydraUP">
              </div>
              <div>
                <label class="form-label">Texto introductorio</label>
                <?php render_influencer_html_editor('video_rewards_intro_html', (string) ($videoRewards['intro_html'] ?? ''), 4, 'Explica como funcionan las recompensas por visualizaciones'); ?>
              </div>
            </div>
          </div>
          <div class="col-xl-5">
            <div class="influencer-admin-subcard influencer-admin-subcard--colors">
              <h4 class="influencer-admin-subtitle">Colores del bloque</h4>
              <?php render_influencer_color_fields($videoRewardsColorFields, $colors); ?>
            </div>
          </div>
        </div>

        <div class="influencer-admin-reward-builder" data-reward-builder>
          <div class="influencer-admin-reward-builder-toolbar">
            <p class="influencer-admin-reward-builder-note">Crea todos los tabs que necesites y agrega fila por fila los rangos de vistas y sus premios dentro de cada uno.</p>
            <button type="button" class="btn btn-outline-info" data-add-reward-tab>Agregar nuevo tab</button>
          </div>

          <div class="influencer-admin-reward-nav" data-reward-tab-nav></div>

          <div class="d-grid gap-4" data-reward-tabs-list>
            <?php foreach ($rewardTabs as $tabIndex => $rewardTab): ?>
              <?php render_influencer_reward_tab_editor((string) $tabIndex, is_array($rewardTab) ? $rewardTab : []); ?>
            <?php endforeach; ?>
          </div>

          <template id="influencerRewardTabTemplate">
            <?php render_influencer_reward_tab_editor('__TAB_INDEX__', influencer_instructions_reward_tab_template(), false); ?>
          </template>
          <template id="influencerRewardRowTemplate">
            <?php render_influencer_reward_row_editor('__TAB_INDEX__', '__ROW_INDEX__', ['views' => '', 'reward' => '']); ?>
          </template>
        </div>
      </section>

      <section class="influencer-admin-section influencer-admin-section--closing">
        <div class="influencer-admin-section-header">
          <span class="influencer-admin-section-order">6</span>
          <div>
            <h3 class="influencer-admin-section-title h4 text-info">Cierre final y llamada a la accion</h3>
            <p class="influencer-admin-section-desc">Este es el ultimo bloque de la landing. Debe cerrar la propuesta y empujar al usuario a escribir o solicitar acceso.</p>
          </div>
        </div>

        <div class="row g-4">
          <div class="col-xl-7 d-grid gap-4">
            <div class="influencer-admin-subcard d-grid gap-3">
              <h4 class="influencer-admin-subtitle">Contenido del cierre</h4>
              <div>
                <label class="form-label">Etiqueta superior</label>
                <input type="text" name="closing_eyebrow" value="<?= htmlspecialchars($closing['eyebrow'] ?? '', ENT_QUOTES, 'UTF-8') ?>" class="form-control" placeholder="Etiqueta superior">
              </div>
              <div>
                <label class="form-label">Titulo del cierre</label>
                <input type="text" name="closing_title" value="<?= htmlspecialchars($closing['title'] ?? '', ENT_QUOTES, 'UTF-8') ?>" class="form-control" placeholder="Titulo del cierre">
              </div>
              <div>
                <label class="form-label">Texto final</label>
                <?php render_influencer_html_editor('closing_content_html', (string) ($closing['content_html'] ?? ''), 8, 'Escribe el texto que empuja al usuario a tomar accion'); ?>
              </div>
            </div>

            <div class="influencer-admin-subcard">
              <h4 class="influencer-admin-subtitle">Boton final</h4>
              <div class="row g-3">
                <div class="col-md-6">
                  <label class="form-label">Texto del boton final</label>
                  <input type="text" name="closing_button_label" value="<?= htmlspecialchars($closing['button_label'] ?? '', ENT_QUOTES, 'UTF-8') ?>" class="form-control">
                </div>
                <div class="col-md-6">
                  <label class="form-label">Telefono de WhatsApp</label>
                  <input type="text" name="closing_whatsapp_phone" value="<?= htmlspecialchars($closing['whatsapp_phone'] ?? '', ENT_QUOTES, 'UTF-8') ?>" class="form-control" placeholder="+584121234567">
                </div>
                <div class="col-12">
                  <label class="form-label">Mensaje de WhatsApp</label>
                  <input type="text" name="closing_whatsapp_message" value="<?= htmlspecialchars($closing['whatsapp_message'] ?? '', ENT_QUOTES, 'UTF-8') ?>" class="form-control" placeholder="Hola, quiero unirme al programa Influencer.">
                  <div class="form-text mt-2">Con este telefono y mensaje se construye automaticamente el enlace del boton final de WhatsApp.</div>
                </div>
              </div>
            </div>
          </div>

          <div class="col-xl-5">
            <div class="influencer-admin-subcard influencer-admin-subcard--colors">
              <h4 class="influencer-admin-subtitle">Colores del cierre</h4>
              <?php render_influencer_color_fields($closingColorFields, $colors); ?>
            </div>
          </div>
        </div>
      </section>

      <button type="submit" class="neon-btn w-100 py-3">Guardar modulo Influencer</button>
    </form>
  </div>
</div>
<script>
  (function () {
    const editors = document.querySelectorAll('[data-html-editor]');
    if (!editors.length) {
      return;
    }

    const focusVisual = function (visual) {
      visual.focus();
      const selection = window.getSelection();
      if (!selection || selection.rangeCount > 0) {
        return;
      }
      const range = document.createRange();
      range.selectNodeContents(visual);
      range.collapse(false);
      selection.removeAllRanges();
      selection.addRange(range);
    };

    const syncTextarea = function (editor) {
      const visual = editor.querySelector('[data-editor-visual]');
      const textarea = editor.querySelector('[data-editor-textarea]');
      textarea.value = visual.innerHTML.trim();
    };

    const renderPreview = function (editor) {
      const visual = editor.querySelector('[data-editor-visual]');
      const preview = editor.querySelector('[data-editor-preview]');
      preview.innerHTML = visual.innerHTML.trim() === '' ? '<em class="text-secondary">Sin contenido HTML.</em>' : visual.innerHTML;
    };

    const exec = function (command, value) {
      document.execCommand(command, false, value);
    };

    editors.forEach(function (editor) {
      const visual = editor.querySelector('[data-editor-visual]');
      const textarea = editor.querySelector('[data-editor-textarea]');
      const preview = editor.querySelector('[data-editor-preview]');
      const previewButton = editor.querySelector('[data-editor-action="preview"]');

      if (visual.innerHTML.trim() === '') {
        visual.innerHTML = '';
      }

      syncTextarea(editor);
      renderPreview(editor);

      editor.addEventListener('click', function (event) {
        const button = event.target.closest('[data-editor-action]');
        if (!button) {
          return;
        }

        const action = button.getAttribute('data-editor-action');
        if (action !== 'preview') {
          event.preventDefault();
          focusVisual(visual);
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

        syncTextarea(editor);
        renderPreview(editor);
      });

      visual.addEventListener('input', function () {
        syncTextarea(editor);
        renderPreview(editor);
      });

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
      if (form) {
        form.addEventListener('submit', function () {
          syncTextarea(editor);
        });
      }

      textarea.addEventListener('input', function () {
        visual.innerHTML = textarea.value;
        renderPreview(editor);
      });
    });

    const rewardBuilder = document.querySelector('[data-reward-builder]');
    if (rewardBuilder) {
      const rewardTabsList = rewardBuilder.querySelector('[data-reward-tabs-list]');
      const rewardTabNav = rewardBuilder.querySelector('[data-reward-tab-nav]');
      const rewardTabTemplate = document.getElementById('influencerRewardTabTemplate');
      const rewardRowTemplate = document.getElementById('influencerRewardRowTemplate');
      let activeRewardTabIndex = 0;

      const setActiveRewardTab = function (tabIndex) {
        const tabCards = rewardTabsList ? rewardTabsList.querySelectorAll('[data-reward-tab]') : [];
        if (!tabCards.length) {
          activeRewardTabIndex = 0;
          return;
        }

        const normalizedIndex = Math.max(0, Math.min(tabIndex, tabCards.length - 1));
        activeRewardTabIndex = normalizedIndex;

        tabCards.forEach(function (tabCard, index) {
          tabCard.classList.toggle('is-hidden', index !== normalizedIndex);
        });

        if (!rewardTabNav) {
          return;
        }

        rewardTabNav.querySelectorAll('[data-reward-tab-trigger]').forEach(function (button, index) {
          const isActive = index === normalizedIndex;
          button.classList.toggle('is-active', isActive);
          button.setAttribute('aria-selected', isActive ? 'true' : 'false');
        });
      };

      const renderRewardTabNav = function () {
        if (!rewardTabNav) {
          return;
        }

        const tabCards = rewardTabsList ? rewardTabsList.querySelectorAll('[data-reward-tab]') : [];
        rewardTabNav.innerHTML = '';

        tabCards.forEach(function (tabCard, index) {
          const labelInput = tabCard.querySelector('[data-reward-tab-field="label"]');
          const tabLabel = labelInput ? labelInput.value.trim() : '';
          const button = document.createElement('button');
          button.type = 'button';
          button.className = 'influencer-admin-reward-nav-button';
          button.setAttribute('data-reward-tab-trigger', String(index));
          button.setAttribute('aria-selected', index === activeRewardTabIndex ? 'true' : 'false');
          button.innerHTML = '<span class="influencer-admin-reward-nav-index">Tab ' + (index + 1) + '</span><span class="influencer-admin-reward-nav-label"></span>';
          button.querySelector('.influencer-admin-reward-nav-label').textContent = tabLabel !== '' ? tabLabel : 'Nuevo tab';
          if (index === activeRewardTabIndex) {
            button.classList.add('is-active');
          }
          rewardTabNav.appendChild(button);
        });
      };

      const applyRewardNameTemplate = function (element, tabIndex, rowIndex) {
        const template = element.getAttribute('data-name-template');
        if (!template) {
          return;
        }

        let name = template.replace(/__TAB_INDEX__/g, String(tabIndex));
        name = name.replace(/__ROW_INDEX__/g, String(rowIndex));
        element.name = name;
      };

      const createRewardRow = function (tabCard) {
        if (!rewardRowTemplate) {
          return null;
        }

        const rowFragment = rewardRowTemplate.content.cloneNode(true);
        const row = rowFragment.firstElementChild;
        const rowsContainer = tabCard.querySelector('[data-reward-rows]');
        if (!row || !rowsContainer) {
          return null;
        }

        rowsContainer.appendChild(row);
        return row;
      };

      const createRewardTab = function () {
        if (!rewardTabTemplate || !rewardTabsList) {
          return null;
        }

        const tabFragment = rewardTabTemplate.content.cloneNode(true);
        const tabCard = tabFragment.firstElementChild;
        if (!tabCard) {
          return null;
        }

        rewardTabsList.appendChild(tabCard);
        createRewardRow(tabCard);
        return tabCard;
      };

      const syncRewardBuilder = function () {
        const tabCards = rewardTabsList ? rewardTabsList.querySelectorAll('[data-reward-tab]') : [];

        if (!tabCards.length) {
          activeRewardTabIndex = 0;
        } else if (activeRewardTabIndex > tabCards.length - 1) {
          activeRewardTabIndex = tabCards.length - 1;
        }

        tabCards.forEach(function (tabCard, tabIndex) {
          const tabOrder = tabCard.querySelector('[data-reward-tab-order]');
          const tabTitle = tabCard.querySelector('[data-reward-tab-title]');
          const labelInput = tabCard.querySelector('[data-reward-tab-field="label"]');
          const tabLabel = labelInput ? labelInput.value.trim() : '';

          if (tabOrder) {
            tabOrder.textContent = 'Tab ' + (tabIndex + 1);
          }

          if (tabTitle) {
            tabTitle.textContent = tabLabel !== '' ? tabLabel : 'Nuevo tab';
          }

          tabCard.querySelectorAll('[data-name-template]').forEach(function (field) {
            const rowElement = field.closest('[data-reward-row]');
            const rowIndex = rowElement ? Array.prototype.indexOf.call(rowElement.parentNode.children, rowElement) : 0;
            applyRewardNameTemplate(field, tabIndex, rowIndex);
          });

          const rows = tabCard.querySelectorAll('[data-reward-row]');
          rows.forEach(function (row, rowIndex) {
            const rowOrder = row.querySelector('[data-reward-row-order]');
            const viewsLabel = row.querySelector('[data-reward-views-label]');
            const prizeLabel = row.querySelector('[data-reward-prize-label]');

            if (rowOrder) {
              rowOrder.textContent = 'Fila ' + (rowIndex + 1);
            }
            if (viewsLabel) {
              viewsLabel.textContent = 'Rango de vistas ' + (rowIndex + 1);
            }
            if (prizeLabel) {
              prizeLabel.textContent = 'Premio ' + (rowIndex + 1);
            }

            row.querySelectorAll('[data-name-template]').forEach(function (field) {
              applyRewardNameTemplate(field, tabIndex, rowIndex);
            });
          });
        });

        renderRewardTabNav();
        setActiveRewardTab(activeRewardTabIndex);
      };

      rewardBuilder.addEventListener('click', function (event) {
        const tabTrigger = event.target.closest('[data-reward-tab-trigger]');
        if (tabTrigger) {
          event.preventDefault();
          setActiveRewardTab(Number(tabTrigger.getAttribute('data-reward-tab-trigger') || '0'));
          return;
        }

        const addTabButton = event.target.closest('[data-add-reward-tab]');
        if (addTabButton) {
          event.preventDefault();
          const newTab = createRewardTab();
          activeRewardTabIndex = Math.max(0, rewardTabsList.querySelectorAll('[data-reward-tab]').length - 1);
          syncRewardBuilder();
          const focusInput = newTab ? newTab.querySelector('[data-reward-tab-field="label"]') : null;
          if (focusInput) {
            focusInput.focus();
          }
          return;
        }

        const addRowButton = event.target.closest('[data-add-reward-row]');
        if (addRowButton) {
          event.preventDefault();
          const tabCard = addRowButton.closest('[data-reward-tab]');
          if (!tabCard) {
            return;
          }

          const newRow = createRewardRow(tabCard);
          syncRewardBuilder();
          const focusInput = newRow ? newRow.querySelector('input') : null;
          if (focusInput) {
            focusInput.focus();
          }
          return;
        }

        const removeRowButton = event.target.closest('[data-remove-reward-row]');
        if (removeRowButton) {
          event.preventDefault();
          const row = removeRowButton.closest('[data-reward-row]');
          const tabCard = removeRowButton.closest('[data-reward-tab]');
          if (!row || !tabCard) {
            return;
          }

          row.remove();
          if (!tabCard.querySelector('[data-reward-row]')) {
            createRewardRow(tabCard);
          }
          syncRewardBuilder();
          return;
        }

        const removeTabButton = event.target.closest('[data-remove-reward-tab]');
        if (removeTabButton) {
          event.preventDefault();
          const tabCard = removeTabButton.closest('[data-reward-tab]');
          if (!tabCard) {
            return;
          }

          tabCard.remove();
          if (!rewardTabsList.querySelector('[data-reward-tab]')) {
            createRewardTab();
            activeRewardTabIndex = 0;
          } else {
            activeRewardTabIndex = Math.max(0, Math.min(activeRewardTabIndex, rewardTabsList.querySelectorAll('[data-reward-tab]').length - 1));
          }
          syncRewardBuilder();
        }
      });

      rewardBuilder.addEventListener('input', function (event) {
        if (event.target.matches('[data-reward-tab-field="label"]')) {
          syncRewardBuilder();
        }
      });

      const rewardForm = rewardBuilder.closest('form');
      if (rewardForm) {
        rewardForm.addEventListener('submit', function () {
          syncRewardBuilder();
        });
      }

      if (!rewardTabsList.querySelector('[data-reward-tab]')) {
        createRewardTab();
      }
      syncRewardBuilder();
    }

    const heroImagePreview = document.querySelector('[data-hero-image-preview]');
    const heroImageInput = document.querySelector('[data-hero-image-input]');
    const heroImageRemove = document.querySelector('[data-hero-image-remove]');

    if (!heroImagePreview || !heroImageInput) {
      return;
    }

    const defaultSrc = heroImagePreview.getAttribute('data-default-src') || '';

    const renderHeroImagePreview = function (src) {
      let image = heroImagePreview.querySelector('[data-hero-image-preview-img]');
      let empty = heroImagePreview.querySelector('[data-hero-image-empty]');

      if (src) {
        if (!image) {
          image = document.createElement('img');
          image.setAttribute('alt', 'Imagen Influencer');
          image.setAttribute('data-hero-image-preview-img', '1');
          heroImagePreview.appendChild(image);
        }
        image.src = src;

        if (empty) {
          empty.remove();
        }
        return;
      }

      if (image) {
        image.remove();
      }
      if (!empty) {
        empty = document.createElement('span');
        empty.className = 'header-logo-empty influencer-hero-image-empty';
        empty.setAttribute('data-hero-image-empty', '1');
        empty.textContent = 'Sin imagen';
        heroImagePreview.appendChild(empty);
      }
    };

    const syncHeroPreview = function () {
      if (heroImageRemove && heroImageRemove.checked) {
        renderHeroImagePreview('');
        return;
      }

      const file = heroImageInput.files && heroImageInput.files[0] ? heroImageInput.files[0] : null;
      if (file) {
        const reader = new FileReader();
        reader.onload = function (event) {
          renderHeroImagePreview(String(event.target && event.target.result ? event.target.result : ''));
        };
        reader.readAsDataURL(file);
        return;
      }

      renderHeroImagePreview(defaultSrc);
    };

    heroImageInput.addEventListener('change', function () {
      if (heroImageRemove) {
        heroImageRemove.checked = false;
      }
      syncHeroPreview();
    });

    if (heroImageRemove) {
      heroImageRemove.addEventListener('change', function () {
        syncHeroPreview();
      });
    }

    syncHeroPreview();
  }());
</script>