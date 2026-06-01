<?php
require_once __DIR__ . '/includes/tenant.php';
require_once __DIR__ . '/includes/influencer_instructions.php';

if (!influencer_instructions_public_enabled()) {
    header('Location: ' . app_path('/'));
    exit();
}

$influencerData = influencer_instructions_get();
$colors = $influencerData['colors'] ?? [];
$hero = $influencerData['hero'] ?? [];
$benefits = $influencerData['benefits'] ?? [];
$videoRewards = $influencerData['video_rewards'] ?? [];
$rewardTabs = $videoRewards['tabs'] ?? [];
$steps = $influencerData['steps'] ?? [];
$notes = $influencerData['notes'] ?? [];
$closing = $influencerData['closing'] ?? [];

$pageTitle = store_config_get('nombre_tienda', 'TVirtualGaming') . ' | ' . trim(strip_tags((string) ($hero['title'] ?? 'Programa Influencer')));
$heroImageUrl = influencer_instructions_asset_url((string) ($hero['image'] ?? ''));
$fallbackContactUrl = influencer_instructions_default_contact_url();
$closingUrl = influencer_instructions_closing_whatsapp_link($closing);
$heroPrimaryUrl = influencer_instructions_link_url((string) ($hero['primary_url'] ?? ''));
if ($heroPrimaryUrl === '') {
  $heroPrimaryUrl = $closingUrl;
}
if ($heroPrimaryUrl === '') {
    $heroPrimaryUrl = $fallbackContactUrl;
}
$heroSecondaryUrl = influencer_instructions_link_url((string) ($hero['secondary_url'] ?? ''));
if ($closingUrl === '') {
    $closingUrl = $fallbackContactUrl;
}

include __DIR__ . '/includes/header.php';
?>
<style>
  .influencer-page {
    --influencer-hero-surface: <?= htmlspecialchars((string) ($colors['hero_surface'] ?? '#0F172A'), ENT_QUOTES, 'UTF-8') ?>;
    --influencer-hero-accent: <?= htmlspecialchars((string) ($colors['hero_accent'] ?? '#22D3EE'), ENT_QUOTES, 'UTF-8') ?>;
    --influencer-hero-title: <?= htmlspecialchars((string) ($colors['hero_title'] ?? '#FFFFFF'), ENT_QUOTES, 'UTF-8') ?>;
    --influencer-hero-text: <?= htmlspecialchars((string) ($colors['hero_text'] ?? '#D7F7FF'), ENT_QUOTES, 'UTF-8') ?>;
    --influencer-hero-button-bg: <?= htmlspecialchars((string) ($colors['hero_button_bg'] ?? '#22C55E'), ENT_QUOTES, 'UTF-8') ?>;
    --influencer-hero-button-text: <?= htmlspecialchars((string) ($colors['hero_button_text'] ?? '#04110B'), ENT_QUOTES, 'UTF-8') ?>;
    --influencer-hero-secondary-bg: <?= htmlspecialchars((string) ($colors['hero_secondary_bg'] ?? '#11263A'), ENT_QUOTES, 'UTF-8') ?>;
    --influencer-hero-secondary-text: <?= htmlspecialchars((string) ($colors['hero_secondary_text'] ?? '#D7F7FF'), ENT_QUOTES, 'UTF-8') ?>;
    --influencer-closing-surface: <?= htmlspecialchars((string) ($colors['closing_surface'] ?? '#0B1120'), ENT_QUOTES, 'UTF-8') ?>;
    --influencer-closing-label: <?= htmlspecialchars((string) ($colors['closing_label'] ?? '#22D3EE'), ENT_QUOTES, 'UTF-8') ?>;
    --influencer-closing-title: <?= htmlspecialchars((string) ($colors['closing_title'] ?? '#FFFFFF'), ENT_QUOTES, 'UTF-8') ?>;
    --influencer-closing-text: <?= htmlspecialchars((string) ($colors['closing_text'] ?? '#DCEBFF'), ENT_QUOTES, 'UTF-8') ?>;
    --influencer-closing-button-bg: <?= htmlspecialchars((string) ($colors['closing_button_bg'] ?? '#22C55E'), ENT_QUOTES, 'UTF-8') ?>;
    --influencer-closing-button-text: <?= htmlspecialchars((string) ($colors['closing_button_text'] ?? '#04110B'), ENT_QUOTES, 'UTF-8') ?>;
    --influencer-steps-surface: <?= htmlspecialchars((string) ($colors['steps_surface'] ?? '#0B1324'), ENT_QUOTES, 'UTF-8') ?>;
    --influencer-steps-label: <?= htmlspecialchars((string) ($colors['steps_label'] ?? '#5EEAD4'), ENT_QUOTES, 'UTF-8') ?>;
    --influencer-steps-title: <?= htmlspecialchars((string) ($colors['steps_title'] ?? '#FFFFFF'), ENT_QUOTES, 'UTF-8') ?>;
    --influencer-steps-text: <?= htmlspecialchars((string) ($colors['steps_text'] ?? '#CFE7FF'), ENT_QUOTES, 'UTF-8') ?>;
    --influencer-steps-card-bg: <?= htmlspecialchars((string) ($colors['steps_card_bg'] ?? '#111B31'), ENT_QUOTES, 'UTF-8') ?>;
    --influencer-steps-card-title: <?= htmlspecialchars((string) ($colors['steps_card_title'] ?? '#FFFFFF'), ENT_QUOTES, 'UTF-8') ?>;
    --influencer-steps-card-text: <?= htmlspecialchars((string) ($colors['steps_card_text'] ?? '#A8C7E8'), ENT_QUOTES, 'UTF-8') ?>;
    --influencer-steps-icon-bg: <?= htmlspecialchars((string) ($colors['steps_icon_bg'] ?? '#12344A'), ENT_QUOTES, 'UTF-8') ?>;
    --influencer-steps-icon-color: <?= htmlspecialchars((string) ($colors['steps_icon_color'] ?? '#5EEAD4'), ENT_QUOTES, 'UTF-8') ?>;
    --influencer-benefits-surface: <?= htmlspecialchars((string) ($colors['benefits_surface'] ?? '#111827'), ENT_QUOTES, 'UTF-8') ?>;
    --influencer-benefits-label: <?= htmlspecialchars((string) ($colors['benefits_label'] ?? '#FBBF24'), ENT_QUOTES, 'UTF-8') ?>;
    --influencer-benefits-title: <?= htmlspecialchars((string) ($colors['benefits_title'] ?? '#FFFFFF'), ENT_QUOTES, 'UTF-8') ?>;
    --influencer-benefits-text: <?= htmlspecialchars((string) ($colors['benefits_text'] ?? '#E5E7EB'), ENT_QUOTES, 'UTF-8') ?>;
    --influencer-benefits-card-bg: <?= htmlspecialchars((string) ($colors['benefits_card_bg'] ?? '#1F2937'), ENT_QUOTES, 'UTF-8') ?>;
    --influencer-benefits-card-title: <?= htmlspecialchars((string) ($colors['benefits_card_title'] ?? '#F9FAFB'), ENT_QUOTES, 'UTF-8') ?>;
    --influencer-benefits-card-text: <?= htmlspecialchars((string) ($colors['benefits_card_text'] ?? '#D1D5DB'), ENT_QUOTES, 'UTF-8') ?>;
    --influencer-video-rewards-surface: <?= htmlspecialchars((string) ($colors['video_rewards_surface'] ?? '#0B1320'), ENT_QUOTES, 'UTF-8') ?>;
    --influencer-video-rewards-title: <?= htmlspecialchars((string) ($colors['video_rewards_title'] ?? '#FFFFFF'), ENT_QUOTES, 'UTF-8') ?>;
    --influencer-video-rewards-text: <?= htmlspecialchars((string) ($colors['video_rewards_text'] ?? '#B7C6DB'), ENT_QUOTES, 'UTF-8') ?>;
    --influencer-notes-surface: <?= htmlspecialchars((string) ($colors['notes_surface'] ?? '#101826'), ENT_QUOTES, 'UTF-8') ?>;
    --influencer-notes-label: <?= htmlspecialchars((string) ($colors['notes_label'] ?? '#F59E0B'), ENT_QUOTES, 'UTF-8') ?>;
    --influencer-notes-title: <?= htmlspecialchars((string) ($colors['notes_title'] ?? '#FFFFFF'), ENT_QUOTES, 'UTF-8') ?>;
    --influencer-notes-text: <?= htmlspecialchars((string) ($colors['notes_text'] ?? '#E5E7EB'), ENT_QUOTES, 'UTF-8') ?>;
    --influencer-notes-card-bg: <?= htmlspecialchars((string) ($colors['notes_card_bg'] ?? '#172033'), ENT_QUOTES, 'UTF-8') ?>;
    --influencer-notes-card-title: <?= htmlspecialchars((string) ($colors['notes_card_title'] ?? '#F9FAFB'), ENT_QUOTES, 'UTF-8') ?>;
    --influencer-notes-card-text: <?= htmlspecialchars((string) ($colors['notes_card_text'] ?? '#D6DEED'), ENT_QUOTES, 'UTF-8') ?>;
    --influencer-notes-icon-bg: <?= htmlspecialchars((string) ($colors['notes_icon_bg'] ?? '#362A12'), ENT_QUOTES, 'UTF-8') ?>;
    --influencer-notes-icon-color: <?= htmlspecialchars((string) ($colors['notes_icon_color'] ?? '#FBBF24'), ENT_QUOTES, 'UTF-8') ?>;
  }
  .influencer-shell {
    display: grid;
    gap: 1.5rem;
  }
  .influencer-panel {
    border: 1px solid rgba(255, 255, 255, 0.08);
    border-radius: 28px;
    padding: 1.5rem;
    box-shadow: 0 18px 48px rgba(2, 8, 23, 0.28);
    overflow: hidden;
  }
  .influencer-hero {
    background:
      radial-gradient(circle at top right, rgba(255, 255, 255, 0.14), transparent 28%),
      linear-gradient(135deg, var(--influencer-hero-surface), rgba(15, 23, 42, 0.94));
    color: var(--influencer-hero-text);
  }
  .influencer-tag {
    display: inline-flex;
    align-items: center;
    gap: 0.45rem;
    padding: 0.55rem 0.95rem;
    border-radius: 999px;
    background: rgba(255, 255, 255, 0.08);
    border: 1px solid rgba(255, 255, 255, 0.12);
    color: var(--influencer-hero-accent);
    font-size: 0.82rem;
    letter-spacing: 0.16em;
    text-transform: uppercase;
    font-weight: 700;
  }
  .influencer-hero-title,
  .influencer-section-title {
    font-family: 'Oxanium', 'Space Grotesk', sans-serif;
    font-weight: 700;
    line-height: 1.05;
  }
  .influencer-hero-title {
    font-size: clamp(2rem, 4vw, 4rem);
    margin: 0;
    color: var(--influencer-hero-title);
  }
  .influencer-html p:last-child {
    margin-bottom: 0;
  }
  .influencer-section-copy {
    max-width: 760px;
  }
  .influencer-section-label {
    text-transform: uppercase;
    letter-spacing: 0.2em;
    font-size: 0.76rem;
    font-weight: 700;
  }
  .influencer-cta,
  .influencer-cta-secondary {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    padding: 0.95rem 1.3rem;
    border-radius: 16px;
    font-weight: 700;
    text-decoration: none;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
  }
  .influencer-cta:hover,
  .influencer-cta-secondary:hover {
    transform: translateY(-2px);
  }
  .influencer-cta {
    box-shadow: 0 12px 30px rgba(34, 197, 94, 0.24);
  }
  .influencer-cta--hero {
    background: var(--influencer-hero-button-bg);
    color: var(--influencer-hero-button-text);
  }
  .influencer-cta--closing {
    background: var(--influencer-closing-button-bg);
    color: var(--influencer-closing-button-text);
  }
  .influencer-cta-secondary {
    border: 1px solid rgba(255, 255, 255, 0.12);
    color: var(--influencer-hero-secondary-text);
    background: var(--influencer-hero-secondary-bg);
  }
  .influencer-image-wrap {
    background: linear-gradient(180deg, rgba(255, 255, 255, 0.08), rgba(15, 23, 42, 0.32));
    border: 1px solid rgba(255, 255, 255, 0.08);
    border-radius: 24px;
    min-height: 280px;
    display: flex;
    align-items: stretch;
    justify-content: center;
    overflow: hidden;
  }
  .influencer-image-wrap img {
    width: 100%;
    height: 100%;
    object-fit: cover;
  }
  .influencer-image-empty {
    display: flex;
    flex-direction: column;
    justify-content: center;
    align-items: center;
    width: 100%;
    padding: 2rem;
    text-align: center;
    color: rgba(226, 248, 255, 0.8);
    background: linear-gradient(135deg, rgba(34, 211, 238, 0.12), rgba(15, 23, 42, 0.12));
  }
  .influencer-grid-3 {
    display: grid;
    gap: 1rem;
    grid-template-columns: repeat(3, minmax(0, 1fr));
  }
  .influencer-card {
    border-radius: 24px;
    padding: 1.25rem;
    border: 1px solid rgba(255, 255, 255, 0.08);
    background: rgba(255, 255, 255, 0.04);
    height: 100%;
  }
  .influencer-card--benefit {
    background: linear-gradient(180deg, var(--influencer-benefits-card-bg), rgba(17, 24, 39, 0.92));
  }
  .influencer-card--step {
    background: linear-gradient(180deg, var(--influencer-steps-card-bg), rgba(8, 18, 34, 0.92));
  }
  .influencer-card--note {
    background: linear-gradient(180deg, var(--influencer-notes-card-bg), rgba(12, 20, 34, 0.92));
  }
  .influencer-icon {
    width: 3rem;
    height: 3rem;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 16px;
    margin-bottom: 1rem;
  }
  .influencer-icon svg {
    width: 1.5rem;
    height: 1.5rem;
  }
  .influencer-hero .influencer-tag {
    color: var(--influencer-hero-accent);
  }
  .influencer-hero .influencer-html {
    color: var(--influencer-hero-text);
  }
  .influencer-steps {
    background: linear-gradient(180deg, var(--influencer-steps-surface), rgba(8, 18, 34, 0.94));
  }
  .influencer-steps .influencer-section-label {
    color: var(--influencer-steps-label);
  }
  .influencer-steps .influencer-section-title {
    color: var(--influencer-steps-title);
  }
  .influencer-steps .influencer-section-copy {
    color: var(--influencer-steps-text);
  }
  .influencer-steps .influencer-card--step h3 {
    color: var(--influencer-steps-card-title);
  }
  .influencer-steps .influencer-card--step .influencer-html {
    color: var(--influencer-steps-card-text);
  }
  .influencer-steps .influencer-icon {
    background: var(--influencer-steps-icon-bg);
    color: var(--influencer-steps-icon-color);
  }
  .influencer-benefits {
    background: linear-gradient(180deg, var(--influencer-benefits-surface), rgba(17,24,39,0.95));
  }
  .influencer-benefits .influencer-section-label {
    color: var(--influencer-benefits-label);
  }
  .influencer-benefits .influencer-section-title {
    color: var(--influencer-benefits-title);
  }
  .influencer-benefits .influencer-section-copy {
    color: var(--influencer-benefits-text);
  }
  .influencer-benefits .influencer-card--benefit h3 {
    color: var(--influencer-benefits-card-title);
  }
  .influencer-benefits .influencer-card--benefit .influencer-html {
    color: var(--influencer-benefits-card-text);
  }
  .influencer-video-rewards {
    background: linear-gradient(180deg, var(--influencer-video-rewards-surface), rgba(9, 15, 24, 0.96));
  }
  .influencer-video-rewards .influencer-section-label {
    color: #facc15;
  }
  .influencer-video-rewards .influencer-section-title {
    color: var(--influencer-video-rewards-title);
  }
  .influencer-video-rewards .influencer-section-copy {
    color: var(--influencer-video-rewards-text);
  }
  .influencer-reward-tabs {
    display: flex;
    gap: 0.85rem;
    flex-wrap: wrap;
    margin: 0 0 1.5rem;
  }
  .influencer-reward-tab-button {
    border: 1px solid rgba(148, 163, 184, 0.16);
    background: rgba(15, 23, 42, 0.72);
    color: #dce7f6;
    border-radius: 14px;
    padding: 0.85rem 1.35rem;
    font-weight: 700;
    transition: transform 0.2s ease, border-color 0.2s ease, box-shadow 0.2s ease;
  }
  .influencer-reward-tab-button:hover {
    transform: translateY(-1px);
  }
  .influencer-reward-tab-button.is-active {
    color: var(--reward-tab-active-text, #ffffff);
    background: var(--reward-tab-active-bg, #22c55e);
    border-color: var(--reward-tab-active-bg, #22c55e);
    box-shadow: 0 10px 24px rgba(15, 23, 42, 0.28);
  }
  .influencer-reward-panel {
    display: none;
  }
  .influencer-reward-panel.is-active {
    display: block;
  }
  .influencer-reward-table-wrap {
    border: 1px solid var(--reward-table-border, #475569);
    border-radius: 22px;
    overflow: hidden;
    background: var(--reward-body-bg, #182132);
    box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.02);
  }
  .influencer-reward-table {
    width: 100%;
    border-collapse: collapse;
  }
  .influencer-reward-table th,
  .influencer-reward-table td {
    width: 50%;
    padding: 1.25rem 1rem;
    text-align: center;
    border-bottom: 1px solid rgba(255, 255, 255, 0.06);
  }
  .influencer-reward-table th {
    background: var(--reward-header-bg, #3a311a);
    color: var(--reward-header-text, #facc15);
    font-size: clamp(1rem, 1.5vw, 1.15rem);
    letter-spacing: 0.03em;
  }
  .influencer-reward-table th + th,
  .influencer-reward-table td + td {
    border-left: 1px solid rgba(255, 255, 255, 0.08);
  }
  .influencer-reward-table tbody tr {
    background: var(--reward-body-bg, #182132);
    color: var(--reward-body-text, #ffffff);
  }
  .influencer-reward-table tbody tr:last-child {
    background: var(--reward-highlight-bg, #342b1e);
    color: var(--reward-highlight-text, #facc15);
  }
  .influencer-reward-table tbody tr:last-child td {
    border-bottom: 0;
  }
  .influencer-reward-value {
    font-size: clamp(1.2rem, 2vw, 1.8rem);
    font-weight: 800;
    line-height: 1.1;
  }
  .influencer-reward-caption {
    font-size: clamp(1.15rem, 2vw, 1.65rem);
    font-weight: 800;
    line-height: 1.1;
  }
  .influencer-reward-emoji {
    margin-right: 0.45rem;
  }
  .influencer-notes {
    background: linear-gradient(180deg, var(--influencer-notes-surface), rgba(16,24,38,0.95));
  }
  .influencer-notes .influencer-section-label {
    color: var(--influencer-notes-label);
  }
  .influencer-notes .influencer-section-title {
    color: var(--influencer-notes-title);
  }
  .influencer-notes .influencer-section-copy {
    color: var(--influencer-notes-text);
  }
  .influencer-notes .influencer-card--note h3 {
    color: var(--influencer-notes-card-title);
  }
  .influencer-notes .influencer-card--note .influencer-html {
    color: var(--influencer-notes-card-text);
  }
  .influencer-notes .influencer-icon {
    background: var(--influencer-notes-icon-bg);
    color: var(--influencer-notes-icon-color);
  }
  .influencer-closing {
    background:
      radial-gradient(circle at top left, rgba(34, 211, 238, 0.14), transparent 32%),
      linear-gradient(145deg, var(--influencer-closing-surface), rgba(11, 17, 32, 0.96));
  }
  .influencer-closing .influencer-section-label {
    color: var(--influencer-closing-label);
  }
  .influencer-closing .influencer-section-title {
    color: var(--influencer-closing-title);
  }
  .influencer-closing .influencer-section-copy {
    color: var(--influencer-closing-text);
  }
  @media (max-width: 991.98px) {
    .influencer-grid-3 {
      grid-template-columns: 1fr;
    }
    .influencer-reward-table th,
    .influencer-reward-table td {
      padding: 1rem 0.75rem;
    }
  }
</style>

<section class="influencer-page mt-4 mb-5">
  <div class="influencer-shell">
    <div class="influencer-panel influencer-hero">
      <div class="row g-4 align-items-center">
        <div class="col-lg-7 d-grid gap-3">
          <?php if (trim((string) ($hero['badge'] ?? '')) !== ''): ?>
            <div class="influencer-tag"><?= $hero['badge'] ?></div>
          <?php endif; ?>
          <div class="influencer-hero-title"><?= $hero['title'] ?></div>
          <?php if (trim((string) ($hero['lead_html'] ?? '')) !== ''): ?>
            <div class="influencer-html influencer-section-copy fs-5"><?= $hero['lead_html'] ?></div>
          <?php endif; ?>
          <div class="d-flex flex-wrap gap-3 pt-2">
            <?php if ($heroPrimaryUrl !== '' && trim((string) ($hero['primary_label'] ?? '')) !== ''): ?>
              <a href="<?= htmlspecialchars($heroPrimaryUrl, ENT_QUOTES, 'UTF-8') ?>" class="influencer-cta influencer-cta--hero"><?= $hero['primary_label'] ?></a>
            <?php endif; ?>
            <?php if ($heroSecondaryUrl !== '' && trim((string) ($hero['secondary_label'] ?? '')) !== ''): ?>
              <a href="<?= htmlspecialchars($heroSecondaryUrl, ENT_QUOTES, 'UTF-8') ?>" class="influencer-cta-secondary"><?= $hero['secondary_label'] ?></a>
            <?php endif; ?>
          </div>
        </div>
        <div class="col-lg-5">
          <div class="influencer-image-wrap">
            <?php if ($heroImageUrl !== ''): ?>
              <img src="<?= htmlspecialchars($heroImageUrl, ENT_QUOTES, 'UTF-8') ?>" alt="Programa Influencer">
            <?php else: ?>
              <div class="influencer-image-empty">
                <div class="influencer-tag mb-3">Imagen principal</div>
                <div class="fw-semibold">Carga una imagen desde el admin para completar esta cabecera.</div>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>

    <div id="pasos-influencer" class="influencer-panel influencer-steps">
      <div class="d-grid gap-3 mb-4">
        <div class="influencer-section-label"><?= $steps['eyebrow'] ?? '' ?></div>
        <div class="influencer-section-title h2 mb-0"><?= $steps['title'] ?? '' ?></div>
        <?php if (trim((string) ($steps['intro_html'] ?? '')) !== ''): ?>
          <div class="influencer-html influencer-section-copy"><?= $steps['intro_html'] ?></div>
        <?php endif; ?>
      </div>
      <div class="influencer-grid-3">
        <?php foreach (($steps['items'] ?? []) as $item): ?>
          <div class="influencer-card influencer-card--step">
            <div class="influencer-icon"><?= influencer_instructions_icon_svg((string) ($item['icon'] ?? 'sparkles')) ?></div>
            <h3 class="h5 fw-bold mb-3"><?= $item['title'] ?? '' ?></h3>
            <div class="influencer-html"><?= $item['html'] ?? '' ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="influencer-panel influencer-benefits">
      <div class="d-grid gap-3 mb-4">
        <div class="influencer-section-label"><?= $benefits['eyebrow'] ?? '' ?></div>
        <div class="influencer-section-title h2 mb-0"><?= $benefits['title'] ?? '' ?></div>
        <?php if (trim((string) ($benefits['intro_html'] ?? '')) !== ''): ?>
          <div class="influencer-html influencer-section-copy"><?= $benefits['intro_html'] ?></div>
        <?php endif; ?>
      </div>
      <div class="influencer-grid-3">
        <?php foreach (($benefits['items'] ?? []) as $item): ?>
          <div class="influencer-card influencer-card--benefit">
            <h3 class="h5 fw-bold mb-3"><?= $item['title'] ?? '' ?></h3>
            <div class="influencer-html"><?= $item['html'] ?? '' ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <?php if (!empty($rewardTabs)): ?>
      <div class="influencer-panel influencer-video-rewards">
        <div class="d-grid gap-3 mb-4 text-center">
          <div class="influencer-section-label"><?= $videoRewards['eyebrow'] ?? '' ?></div>
          <div class="influencer-section-title h2 mb-0"><?= $videoRewards['title'] ?? '' ?></div>
          <?php if (trim((string) ($videoRewards['intro_html'] ?? '')) !== ''): ?>
            <div class="influencer-html influencer-section-copy"><?= $videoRewards['intro_html'] ?></div>
          <?php endif; ?>
        </div>

        <div class="d-flex justify-content-center">
          <div class="influencer-reward-tabs" role="tablist" aria-label="Recompensas por juego">
            <?php foreach ($rewardTabs as $tabIndex => $rewardTab): ?>
              <button
                type="button"
                class="influencer-reward-tab-button<?= $tabIndex === 0 ? ' is-active' : '' ?>"
                data-influencer-reward-tab="reward-tab-<?= $tabIndex ?>"
                style="--reward-tab-active-bg: <?= htmlspecialchars((string) ($rewardTab['active_bg'] ?? '#22C55E'), ENT_QUOTES, 'UTF-8') ?>; --reward-tab-active-text: <?= htmlspecialchars((string) ($rewardTab['active_text'] ?? '#FFFFFF'), ENT_QUOTES, 'UTF-8') ?>;"
              >
                <?php if (trim((string) ($rewardTab['tab_emoji'] ?? '')) !== ''): ?><span class="me-2"><?= htmlspecialchars((string) ($rewardTab['tab_emoji'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
                <span><?= htmlspecialchars((string) ($rewardTab['label'] ?? 'Tab'), ENT_QUOTES, 'UTF-8') ?></span>
              </button>
            <?php endforeach; ?>
          </div>
        </div>

        <?php foreach ($rewardTabs as $tabIndex => $rewardTab): ?>
          <div
            class="influencer-reward-panel<?= $tabIndex === 0 ? ' is-active' : '' ?>"
            data-influencer-reward-panel="reward-tab-<?= $tabIndex ?>"
            style="--reward-table-border: <?= htmlspecialchars((string) ($rewardTab['table_border'] ?? '#475569'), ENT_QUOTES, 'UTF-8') ?>; --reward-header-bg: <?= htmlspecialchars((string) ($rewardTab['header_bg'] ?? '#3A311A'), ENT_QUOTES, 'UTF-8') ?>; --reward-header-text: <?= htmlspecialchars((string) ($rewardTab['header_text'] ?? '#FACC15'), ENT_QUOTES, 'UTF-8') ?>; --reward-body-bg: <?= htmlspecialchars((string) ($rewardTab['body_bg'] ?? '#182132'), ENT_QUOTES, 'UTF-8') ?>; --reward-body-text: <?= htmlspecialchars((string) ($rewardTab['body_text'] ?? '#FFFFFF'), ENT_QUOTES, 'UTF-8') ?>; --reward-highlight-bg: <?= htmlspecialchars((string) ($rewardTab['highlight_bg'] ?? '#342B1E'), ENT_QUOTES, 'UTF-8') ?>; --reward-highlight-text: <?= htmlspecialchars((string) ($rewardTab['highlight_text'] ?? '#FACC15'), ENT_QUOTES, 'UTF-8') ?>;"
          >
            <div class="influencer-reward-table-wrap">
              <table class="influencer-reward-table">
                <thead>
                  <tr>
                    <th>
                      <?php if (trim((string) ($rewardTab['views_emoji'] ?? '')) !== ''): ?><span class="influencer-reward-emoji"><?= htmlspecialchars((string) ($rewardTab['views_emoji'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
                      <?= htmlspecialchars((string) ($rewardTab['views_label'] ?? 'VISTAS'), ENT_QUOTES, 'UTF-8') ?>
                    </th>
                    <th>
                      <?php if (trim((string) ($rewardTab['reward_emoji'] ?? '')) !== ''): ?><span class="influencer-reward-emoji"><?= htmlspecialchars((string) ($rewardTab['reward_emoji'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
                      <?= htmlspecialchars((string) ($rewardTab['reward_label'] ?? 'RECOMPENSA'), ENT_QUOTES, 'UTF-8') ?>
                    </th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach (($rewardTab['rows'] ?? []) as $rewardRow): ?>
                    <tr>
                      <td><div class="influencer-reward-caption"><?= htmlspecialchars((string) ($rewardRow['views'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div></td>
                      <td><div class="influencer-reward-value"><?= htmlspecialchars((string) ($rewardRow['reward'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <div class="influencer-panel influencer-notes">
      <div class="d-grid gap-3 mb-4">
        <div class="influencer-section-label"><?= $notes['eyebrow'] ?? '' ?></div>
        <div class="influencer-section-title h2 mb-0"><?= $notes['title'] ?? '' ?></div>
        <?php if (trim((string) ($notes['intro_html'] ?? '')) !== ''): ?>
          <div class="influencer-html influencer-section-copy"><?= $notes['intro_html'] ?></div>
        <?php endif; ?>
      </div>
      <div class="influencer-grid-3">
        <?php foreach (($notes['items'] ?? []) as $item): ?>
          <div class="influencer-card influencer-card--note">
            <div class="influencer-icon"><?= influencer_instructions_icon_svg((string) ($item['icon'] ?? 'shield')) ?></div>
            <h3 class="h5 fw-bold mb-3"><?= $item['title'] ?? '' ?></h3>
            <div class="influencer-html"><?= $item['html'] ?? '' ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="influencer-panel influencer-closing text-light">
      <div class="row g-4 align-items-center">
        <div class="col-lg-8 d-grid gap-3">
          <div class="influencer-section-label"><?= $closing['eyebrow'] ?? '' ?></div>
          <div class="influencer-section-title h2 mb-0"><?= $closing['title'] ?? '' ?></div>
          <?php if (trim((string) ($closing['content_html'] ?? '')) !== ''): ?>
            <div class="influencer-html influencer-section-copy"><?= $closing['content_html'] ?></div>
          <?php endif; ?>
        </div>
        <div class="col-lg-4 d-flex justify-content-lg-end">
          <?php if ($closingUrl !== '' && trim((string) ($closing['button_label'] ?? '')) !== ''): ?>
            <a href="<?= htmlspecialchars($closingUrl, ENT_QUOTES, 'UTF-8') ?>" class="influencer-cta influencer-cta--closing w-100 w-lg-auto"><?= $closing['button_label'] ?></a>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</section>

<script>
  (function () {
    const rewardTabs = Array.from(document.querySelectorAll('[data-influencer-reward-tab]'));
    const rewardPanels = Array.from(document.querySelectorAll('[data-influencer-reward-panel]'));
    if (!rewardTabs.length || !rewardPanels.length) {
      return;
    }

    const activateRewardTab = function (targetKey) {
      rewardTabs.forEach(function (button) {
        button.classList.toggle('is-active', button.getAttribute('data-influencer-reward-tab') === targetKey);
      });

      rewardPanels.forEach(function (panel) {
        panel.classList.toggle('is-active', panel.getAttribute('data-influencer-reward-panel') === targetKey);
      });
    };

    rewardTabs.forEach(function (button) {
      button.addEventListener('click', function () {
        activateRewardTab(button.getAttribute('data-influencer-reward-tab'));
      });
    });
  }());
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>