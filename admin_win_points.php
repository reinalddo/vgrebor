<?php

$optionalIncludes = [
    __DIR__ . '/includes/app_routes.php',
    __DIR__ . '/includes/app_session.php',
    __DIR__ . '/includes/tenant.php',
];
foreach ($optionalIncludes as $optionalInclude) {
    if (is_file($optionalInclude)) {
        require_once $optionalInclude;
    }
}

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db_connect.php';
require_once __DIR__ . '/includes/store_config.php';
require_once __DIR__ . '/includes/win_points.php';

if (function_exists('app_session_start')) {
    app_session_start();
} elseif (function_exists('tenant_start_session')) {
    tenant_start_session();
}

if (!function_exists('admin_win_points_set_flash')) {
    function admin_win_points_set_flash(string $type, string $message): void {
        $_SESSION['auth_flash'] = [
            'type' => $type,
            'message' => $message,
        ];
    }
}

if (!function_exists('admin_win_points_redirect')) {
    function admin_win_points_redirect(array $query = []): void {
        $target = function_exists('app_path') ? app_path('/admin/win-points') : '/admin/win-points';
        if (!empty($query)) {
            $target .= '?' . http_build_query($query);
        }
        header('Location: ' . $target);
        exit;
    }
}

$adminUser = auth_sync_session_user();
$adminUserRole = trim((string) ($adminUser['rol'] ?? ''));
if (!$adminUser || !in_array($adminUserRole, ['admin', 'root'], true)) {
    admin_win_points_set_flash('error', 'No tienes permisos para acceder a Win Points.');
    $loginPath = function_exists('app_path') ? app_path('/login.php') : '/login.php';
    header('Location: ' . $loginPath);
    exit;
}

if (!win_points_enabled()) {
  admin_win_points_set_flash('error', 'El modulo de Win Points esta desactivado.');
  $dashboardPath = function_exists('app_path') ? app_path('/admin/dashboard') : '/admin/dashboard';
  header('Location: ' . $dashboardPath);
  exit;
}

win_points_ensure_schema();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['save_win_points_program'])) {
            $programName = trim((string) ($_POST['win_points_name'] ?? ''));
            $badgeBackgroundColor = win_points_normalize_hex_color($_POST['win_points_badge_background_color'] ?? '', '#3E2D07');
            $badgeTextColor = win_points_normalize_hex_color($_POST['win_points_badge_text_color'] ?? '', '#FCD34D');
            $expirationDays = win_points_normalize_expiration_days($_POST['win_points_expiration_days'] ?? 180);
            $currentIcon = store_config_get('win_points_icon', '');
            $nextIcon = $currentIcon;
          $currentPaymentImage = store_config_get('win_points_payment_image', '');
          $nextPaymentImage = $currentPaymentImage;
          $currentPaymentCornerImage = store_config_get('win_points_payment_corner_image', '');
          $nextPaymentCornerImage = $currentPaymentCornerImage;
            $hasUpload = isset($_FILES['win_points_icon']) && (($_FILES['win_points_icon']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE);
          $hasPaymentImageUpload = isset($_FILES['win_points_payment_image']) && (($_FILES['win_points_payment_image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE);
          $hasPaymentCornerImageUpload = isset($_FILES['win_points_payment_corner_image']) && (($_FILES['win_points_payment_corner_image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE);

            if ($programName === '') {
                throw new RuntimeException('Debes indicar un nombre para la moneda de premios.');
            }

            if ($hasUpload) {
                $upload = win_points_store_icon_upload($_FILES['win_points_icon']);
                if (!($upload['success'] ?? false)) {
                    throw new RuntimeException((string) ($upload['message'] ?? 'No se pudo cargar el icono del programa.'));
                }
                if (!empty($upload['path'])) {
                    $nextIcon = (string) $upload['path'];
                }
            } elseif (isset($_POST['remove_win_points_icon'])) {
                $nextIcon = '';
            }

            if ($hasPaymentImageUpload) {
              $paymentImageUpload = win_points_store_payment_image_upload($_FILES['win_points_payment_image']);
              if (!($paymentImageUpload['success'] ?? false)) {
                throw new RuntimeException((string) ($paymentImageUpload['message'] ?? 'No se pudo cargar la imagen del método de pago.'));
              }
              if (!empty($paymentImageUpload['path'])) {
                $nextPaymentImage = (string) $paymentImageUpload['path'];
              }
            } elseif (isset($_POST['remove_win_points_payment_image'])) {
              $nextPaymentImage = '';
            }

            if ($hasPaymentCornerImageUpload) {
              $paymentCornerImageUpload = win_points_store_payment_corner_image_upload($_FILES['win_points_payment_corner_image']);
              if (!($paymentCornerImageUpload['success'] ?? false)) {
                throw new RuntimeException((string) ($paymentCornerImageUpload['message'] ?? 'No se pudo cargar la imagen promocional del método de pago.'));
              }
              if (!empty($paymentCornerImageUpload['path'])) {
                $nextPaymentCornerImage = (string) $paymentCornerImageUpload['path'];
              }
            } elseif (isset($_POST['remove_win_points_payment_corner_image'])) {
              $nextPaymentCornerImage = '';
            }

            store_config_upsert('win_points_name', $programName);
            store_config_upsert('win_points_badge_background_color', $badgeBackgroundColor);
            store_config_upsert('win_points_badge_text_color', $badgeTextColor);
            store_config_upsert('win_points_expiration_days', (string) $expirationDays);
            store_config_delete('win_points_default_award');

            if ($nextIcon === '') {
                store_config_delete('win_points_icon');
            } else {
                store_config_upsert('win_points_icon', $nextIcon);
            }

            if ($nextPaymentImage === '') {
              store_config_delete('win_points_payment_image');
            } else {
              store_config_upsert('win_points_payment_image', $nextPaymentImage);
            }

            if ($nextPaymentCornerImage === '') {
              store_config_delete('win_points_payment_corner_image');
            } else {
              store_config_upsert('win_points_payment_corner_image', $nextPaymentCornerImage);
            }

            if ($currentIcon !== '' && $currentIcon !== $nextIcon) {
                win_points_delete_icon_file($currentIcon);
            }
            if ($currentPaymentImage !== '' && $currentPaymentImage !== $nextPaymentImage) {
              win_points_delete_payment_image_file($currentPaymentImage);
            }
            if ($currentPaymentCornerImage !== '' && $currentPaymentCornerImage !== $nextPaymentCornerImage) {
              win_points_delete_payment_corner_image_file($currentPaymentCornerImage);
            }

            admin_win_points_set_flash('success', 'Configuracion global de Win Points actualizada.');
        } elseif (isset($_POST['save_win_points_rule'])) {
            $packageId = (int) ($_POST['rule_package_id'] ?? 0);
          $rewardPoints = max(0, (int) ($_POST['rule_reward_points'] ?? 0));
            $requiredPoints = max(0, (int) ($_POST['rule_required_points'] ?? 0));
            $active = isset($_POST['rule_active']);
            $order = trim((string) ($_POST['rule_order'] ?? ''));
            $resolvedOrder = $order === '' ? null : max(1, (int) $order);

          $rule = win_points_upsert_redemption_rule($mysqli, $packageId, $rewardPoints, $requiredPoints, $active, $resolvedOrder);
            admin_win_points_set_flash('success', 'Regla de canje guardada para el paquete seleccionado.');
            admin_win_points_redirect(['rule' => (int) ($rule['id'] ?? 0)]);
        } elseif (isset($_POST['delete_win_points_rule'])) {
            $ruleId = (int) ($_POST['rule_id'] ?? 0);
            if (!win_points_delete_redemption_rule($mysqli, $ruleId)) {
                throw new RuntimeException('No se pudo eliminar la regla de canje seleccionada.');
            }
            admin_win_points_set_flash('success', 'Regla de canje eliminada.');
        } elseif (isset($_POST['adjust_win_points_balance'])) {
            $userId = (int) ($_POST['adjust_user_id'] ?? 0);
            $delta = win_points_normalize_delta($_POST['adjust_points_delta'] ?? 0);
            $reason = trim((string) ($_POST['adjust_reason'] ?? ''));

            if ($reason === '') {
                throw new RuntimeException('Debes indicar el motivo del ajuste manual.');
            }

            win_points_adjust_user_balance($mysqli, $userId, $delta, $reason, (int) ($adminUser['id'] ?? 0));
            admin_win_points_set_flash('success', 'Saldo del usuario ajustado correctamente.');
        } else {
            admin_win_points_set_flash('error', 'No se reconocio la accion solicitada en Win Points.');
        }
    } catch (Throwable $exception) {
        admin_win_points_set_flash('error', $exception->getMessage());
    }

    admin_win_points_redirect();
}

$winPointsConfig = win_points_config();
$winPointsBadgeBackgroundColor = (string) ($winPointsConfig['badge_background_color'] ?? '#3E2D07');
$winPointsBadgeTextColor = (string) ($winPointsConfig['badge_text_color'] ?? '#FCD34D');
$winPointsExpirationDays = (int) ($winPointsConfig['expiration_days'] ?? 180);
$winPointsBadgeBorderColor = win_points_hex_to_rgba($winPointsBadgeTextColor, 0.28);
$winPointsBadgeInsetColor = win_points_hex_to_rgba($winPointsBadgeTextColor, 0.08);
$packageOptions = win_points_fetch_admin_package_options($mysqli);
$adminUsers = win_points_fetch_admin_users($mysqli);
$adminRules = win_points_fetch_admin_rules($mysqli);
$adminWallets = win_points_fetch_admin_wallets($mysqli);
$adminTransactions = win_points_fetch_admin_transactions($mysqli, 80);
$dashboardUrl = function_exists('app_path') ? app_path('/admin/dashboard') : '/admin/dashboard';
$packageOptionsByGame = [];
$totalWalletBalance = 0;

foreach ($packageOptions as $packageOption) {
    $gameName = trim((string) ($packageOption['juego_nombre'] ?? 'Juego'));
    if (!isset($packageOptionsByGame[$gameName])) {
        $packageOptionsByGame[$gameName] = [];
    }
    $packageOptionsByGame[$gameName][] = $packageOption;
}

foreach ($adminWallets as $walletRow) {
    $totalWalletBalance += (int) ($walletRow['balance'] ?? 0);
}

$transactionTypeLabels = [
    'earn' => 'Ganado',
    'redeem' => 'Canjeado',
    'award_reversal' => 'Reverso de premio',
    'redeem_refund' => 'Reembolso de canje',
    'admin_adjustment' => 'Ajuste manual',
  'expiration' => 'Vencimiento',
];

include __DIR__ . '/includes/header.php';
?>
<style>
  .win-points-shell {
    display: grid;
    gap: 1.5rem;
  }
  .win-points-tabs {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem;
    overflow: visible;
    padding: 1rem 0;
  }
  .win-points-tab {
    flex: 0 1 auto;
    border: 1px solid rgba(34, 211, 238, 0.24);
    background: rgba(8, 15, 28, 0.9);
    color: #8cf6ff;
    border-radius: 999px;
    padding: 0.8rem 1.15rem;
    font-weight: 700;
    line-height: 1;
    transition: all 0.2s ease;
  }
  .win-points-tab:hover {
    border-color: rgba(34, 211, 238, 0.4);
    color: #d8fbff;
  }
  .win-points-tab.is-active {
    background: linear-gradient(135deg, rgba(34, 211, 238, 0.18), rgba(34, 197, 94, 0.12));
    border-color: rgba(34, 211, 238, 0.48);
    color: #ffffff;
    box-shadow: 0 10px 24px rgba(34, 211, 238, 0.14);
  }
  .win-points-tab-panel {
    display: none;
    gap: 1.5rem;
  }
  .win-points-tab-panel.is-active {
    display: grid;
  }
  .win-points-panel {
    background: linear-gradient(180deg, rgba(9, 18, 33, 0.96), rgba(14, 25, 45, 0.94));
    border: 1px solid rgba(34, 211, 238, 0.22);
    border-radius: 24px;
    padding: 1.5rem;
    box-shadow: 0 18px 42px rgba(0, 0, 0, 0.22);
  }
  .win-points-kpi {
    height: 100%;
    border-radius: 20px;
    padding: 1.25rem;
    background: rgba(9, 18, 33, 0.88);
    border: 1px solid rgba(34, 211, 238, 0.18);
  }
  .win-points-icon-preview {
    width: 84px;
    aspect-ratio: 1 / 1;
    border-radius: 18px;
    border: 1px solid rgba(34, 211, 238, 0.28);
    background: rgba(8, 15, 28, 0.88);
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
    padding: 0.6rem;
  }
  .win-points-icon-preview img {
    width: 100%;
    height: 100%;
    object-fit: contain;
  }
  .win-points-icon-upload-stage {
    width: 100%;
    max-width: 160px;
    aspect-ratio: 1 / 1;
    border-radius: 22px;
    border: 1px solid rgba(34, 211, 238, 0.28);
    background: rgba(8, 15, 28, 0.88);
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
    padding: 0.85rem;
    margin-left: auto;
  }
  .win-points-icon-upload-stage img {
    width: 100%;
    height: 100%;
    object-fit: contain;
  }
  .win-points-icon-upload-empty {
    color: #8cf6ff;
    font-weight: 700;
    text-align: center;
    opacity: 0.8;
  }
  .win-points-badge-preview-wrap {
    display: flex;
    align-items: center;
    justify-content: flex-start;
    min-height: 100%;
    padding: 1rem;
    border-radius: 18px;
    border: 1px solid rgba(34, 211, 238, 0.16);
    background: rgba(8, 15, 28, 0.78);
  }
  .win-points-badge-preview {
    display: inline-flex;
    align-items: center;
    gap: 0.45rem;
    width: fit-content;
    max-width: 100%;
    padding: 0.45rem 0.75rem;
    border-radius: 999px;
    border: 1px solid var(--wp-badge-border, rgba(250, 204, 21, 0.25));
    background: var(--wp-badge-bg, #3E2D07);
    color: var(--wp-badge-text, #FCD34D);
    font-size: 0.82rem;
    font-weight: 700;
    line-height: 1.15;
    box-shadow: inset 0 0 0 1px var(--wp-badge-shadow, rgba(250, 204, 21, 0.08));
  }
  .win-points-badge-preview img {
    width: 1.35rem;
    height: 1.35rem;
    border-radius: 999px;
    object-fit: cover;
    flex: 0 0 auto;
  }
  .win-points-setup-stack {
    display: grid;
    gap: 1rem;
    align-content: start;
  }
  .win-points-position-actions {
    display: grid;
    gap: 0.75rem;
  }
  .win-points-position-save-wrap {
    display: flex;
    align-items: flex-end;
    justify-content: flex-end;
  }
  .win-points-position-save {
    width: min(100%, 220px);
    min-height: 52px;
    border-radius: 14px;
  }
  .win-points-simulate-btn {
    width: 100%;
    min-height: 58px;
    border: 1px solid rgba(var(--theme-border-rgb), 0.7);
    border-radius: 16px;
    background: linear-gradient(135deg, rgba(var(--theme-button-primary-rgb), 0.98), rgba(var(--theme-button-secondary-rgb), 0.92));
    color: var(--theme-button-text-strong);
    box-shadow: 0 16px 32px rgba(var(--theme-button-primary-rgb), 0.24), 0 0 0 1px rgba(var(--theme-primary-rgb), 0.22);
    transition: transform 0.18s ease, box-shadow 0.18s ease, filter 0.18s ease;
  }
  .win-points-simulate-btn:hover,
  .win-points-simulate-btn:focus {
    color: var(--theme-button-text-strong);
    filter: brightness(1.04);
    transform: translateY(-1px);
    box-shadow: 0 20px 38px rgba(var(--theme-button-primary-rgb), 0.3), 0 0 0 1px rgba(var(--theme-highlight-rgb), 0.3);
  }
  .win-points-simulate-help {
    margin: 0;
    color: var(--theme-text-muted);
    font-size: 0.88rem;
    line-height: 1.45;
  }
  .win-points-live-notification {
    position: fixed;
    width: min(360px, calc(100vw - 1.5rem));
    display: grid;
    grid-template-columns: auto auto 1fr;
    align-items: center;
    gap: 0.85rem;
    padding: 0.8rem 0.95rem;
    border-radius: 18px;
    border: 1px solid rgba(var(--theme-live-notification-border-rgb), 0.72);
    background: linear-gradient(135deg, rgba(var(--theme-live-notification-bg-rgb), 0.98), rgba(var(--theme-live-notification-border-rgb), 0.16));
    box-shadow: 0 18px 45px rgba(2, 6, 23, 0.42), 0 0 18px rgba(var(--theme-live-notification-border-rgb), 0.16);
    backdrop-filter: blur(14px);
    opacity: 0;
    transition: opacity 0.28s ease, transform 0.28s ease;
    z-index: 1200;
    pointer-events: none;
  }
  .win-points-live-notification.is-visible {
    opacity: 1;
  }
  .win-points-live-notification[data-position="bottom-left"] {
    left: 24px;
    bottom: 24px;
    transform: translate3d(0, 18px, 0);
  }
  .win-points-live-notification[data-position="bottom-center"] {
    left: 50%;
    bottom: 24px;
    transform: translate3d(-50%, 18px, 0);
  }
  .win-points-live-notification[data-position="bottom-right"] {
    right: 24px;
    bottom: 24px;
    transform: translate3d(0, 18px, 0);
  }
  .win-points-live-notification[data-position="top-left"] {
    left: 24px;
    top: 24px;
    transform: translate3d(0, -18px, 0);
  }
  .win-points-live-notification[data-position="top-center"] {
    left: 50%;
    top: 24px;
    transform: translate3d(-50%, -18px, 0);
  }
  .win-points-live-notification[data-position="top-right"] {
    right: 24px;
    top: 24px;
    transform: translate3d(0, -18px, 0);
  }
  .win-points-live-notification[data-position="middle-right"] {
    right: 24px;
    top: 50%;
    transform: translate3d(18px, -50%, 0);
  }
  .win-points-live-notification[data-position="middle-left"] {
    left: 24px;
    top: 50%;
    transform: translate3d(-18px, -50%, 0);
  }
  .win-points-live-notification.is-visible[data-position="bottom-left"],
  .win-points-live-notification.is-visible[data-position="bottom-right"],
  .win-points-live-notification.is-visible[data-position="top-left"],
  .win-points-live-notification.is-visible[data-position="top-right"] {
    transform: translate3d(0, 0, 0);
  }
  .win-points-live-notification.is-visible[data-position="bottom-center"],
  .win-points-live-notification.is-visible[data-position="top-center"] {
    transform: translate3d(-50%, 0, 0);
  }
  .win-points-live-notification.is-visible[data-position="middle-left"] {
    transform: translate3d(0, -50%, 0);
  }
  .win-points-live-notification.is-visible[data-position="middle-right"] {
    transform: translate3d(0, -50%, 0);
  }
  .win-points-live-notification__pulse {
    width: 10px;
    height: 10px;
    border-radius: 999px;
    background: var(--theme-live-notification-accent);
    box-shadow: 0 0 0 0 rgba(var(--theme-live-notification-accent-rgb), 0.56);
    animation: win-points-live-pulse 1.9s ease-out infinite;
  }
  .win-points-live-notification__logo-wrap {
    width: 42px;
    height: 42px;
    border-radius: 14px;
    overflow: hidden;
    background: rgba(255, 255, 255, 0.08);
    border: 1px solid rgba(var(--theme-live-notification-border-rgb), 0.34);
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
  }
  .win-points-live-notification__logo {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
  }
  .win-points-live-notification__logo-fallback {
    color: var(--theme-live-notification-text);
    font-weight: 800;
    letter-spacing: 0.05em;
    font-size: 0.82rem;
  }
  .win-points-live-notification__body {
    min-width: 0;
  }
  .win-points-live-notification__title {
    color: var(--theme-live-notification-text);
    font-size: 0.9rem;
    font-weight: 800;
    line-height: 1.2;
    margin-bottom: 0.12rem;
    letter-spacing: 0.01em;
  }
  .win-points-live-notification__detail {
    color: var(--theme-live-notification-muted);
    font-size: 0.78rem;
    line-height: 1.35;
  }
  @keyframes win-points-live-pulse {
    0% {
      box-shadow: 0 0 0 0 rgba(var(--theme-live-notification-accent-rgb), 0.56);
    }
    70% {
      box-shadow: 0 0 0 12px rgba(var(--theme-live-notification-accent-rgb), 0);
    }
    100% {
      box-shadow: 0 0 0 0 rgba(var(--theme-live-notification-accent-rgb), 0);
    }
  }
  .win-points-table td,
  .win-points-table th {
    vertical-align: middle;
  }
  .win-points-rule-inline-form {
    margin: 0;
  }
  .win-points-rule-field {
    min-width: 92px;
  }
  .win-points-rule-active {
    display: inline-flex;
    justify-content: center;
    width: 100%;
  }
  .win-points-rule-actions {
    display: flex;
    justify-content: flex-end;
    gap: 0.5rem;
    flex-wrap: wrap;
  }
  .win-points-pill {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.45rem;
    padding: 0.35rem 0.75rem;
    border-radius: 999px;
    background: rgba(34, 211, 238, 0.14);
    border: 1px solid rgba(34, 211, 238, 0.2);
    color: #8cf6ff;
    font-size: 0.85rem;
    font-weight: 700;
  }
  .win-points-section-tools {
    display: flex;
    flex-wrap: wrap;
    gap: 1rem;
    align-items: end;
    justify-content: space-between;
    margin-bottom: 1.25rem;
  }
  .win-points-filter-group {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem;
    flex: 1 1 720px;
  }
  .win-points-filter-field {
    display: grid;
    gap: 0.35rem;
    flex: 1 1 220px;
  }
  .win-points-filter-field--compact {
    flex-basis: 150px;
    max-width: 190px;
  }
  .win-points-filter-actions {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 0.75rem;
    flex-wrap: wrap;
  }
  .win-points-pagination {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 0.75rem;
    margin-top: 1.25rem;
  }
  .win-points-pagination-nav {
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
  }
  .win-points-hidden {
    display: none !important;
  }
  .win-points-table-responsive {
    width: 100%;
    overflow-x: auto;
  }
  .win-points-mobile-stack {
    display: none;
  }
  @media (max-width: 991.98px) {
    .win-points-tabs {
      gap: 0.5rem;
      padding: 0.85rem 0;
    }
    .win-points-tab {
      padding: 0.75rem 0.95rem;
      font-size: 0.92rem;
    }
    .win-points-panel {
      padding: 1.1rem;
      border-radius: 20px;
    }
    .win-points-icon-upload-stage {
      margin-left: 0;
    }
    .win-points-table-responsive table {
      min-width: 920px;
    }
  }
  @media (max-width: 767.98px) {
    .win-points-tabs {
      gap: 0.5rem;
      padding: 0.85rem 0 1rem;
    }
    .win-points-tab {
      flex: 1 1 calc(50% - 0.5rem);
      text-align: center;
      justify-content: center;
    }
    .win-points-kpi {
      padding: 1rem;
    }
    .win-points-section-tools,
    .win-points-pagination {
      align-items: stretch;
    }
    .win-points-filter-group,
    .win-points-filter-field,
    .win-points-filter-field--compact,
    .win-points-filter-actions {
      flex: 1 1 100%;
      max-width: none;
    }
    .win-points-filter-actions {
      justify-content: space-between;
    }
    .win-points-mobile-stack {
      display: grid;
      gap: 0.9rem;
    }
    .win-points-mobile-card {
      border: 1px solid rgba(34, 211, 238, 0.14);
      border-radius: 18px;
      background: rgba(8, 15, 28, 0.72);
      padding: 1rem;
      display: grid;
      gap: 0.75rem;
    }
    .win-points-position-save {
      width: 100%;
    }
    .win-points-live-notification {
      width: min(312px, calc(100vw - 72px));
      max-width: calc(100vw - 24px);
      gap: 0.62rem;
      padding: 0.64rem 0.75rem;
      border-radius: 16px;
    }
    .win-points-live-notification[data-position="bottom-left"],
    .win-points-live-notification[data-position="top-left"],
    .win-points-live-notification[data-position="middle-left"] {
      left: 12px;
      right: auto;
    }
    .win-points-live-notification[data-position="bottom-right"],
    .win-points-live-notification[data-position="top-right"],
    .win-points-live-notification[data-position="middle-right"] {
      left: auto;
      right: 12px;
    }
    .win-points-live-notification[data-position="bottom-center"] {
      left: 50%;
      right: auto;
      bottom: calc(0.75rem + env(safe-area-inset-bottom));
      transform: translate3d(-50%, 18px, 0);
    }
    .win-points-live-notification[data-position="bottom-left"],
    .win-points-live-notification[data-position="bottom-right"] {
      bottom: calc(0.75rem + env(safe-area-inset-bottom));
      transform: translate3d(0, 18px, 0);
    }
    .win-points-live-notification.is-visible[data-position="bottom-left"],
    .win-points-live-notification.is-visible[data-position="bottom-right"] {
      transform: translate3d(0, 0, 0);
    }
    .win-points-live-notification.is-visible[data-position="bottom-center"] {
      transform: translate3d(-50%, 0, 0);
    }
    .win-points-live-notification[data-position="top-center"] {
      left: 50%;
      right: auto;
      top: calc(0.75rem + env(safe-area-inset-top));
      transform: translate3d(-50%, -18px, 0);
    }
    .win-points-live-notification[data-position="top-left"],
    .win-points-live-notification[data-position="top-right"] {
      top: calc(0.75rem + env(safe-area-inset-top));
      transform: translate3d(0, -18px, 0);
    }
    .win-points-live-notification.is-visible[data-position="top-left"],
    .win-points-live-notification.is-visible[data-position="top-right"] {
      transform: translate3d(0, 0, 0);
    }
    .win-points-live-notification.is-visible[data-position="top-center"] {
      transform: translate3d(-50%, 0, 0);
    }
    .win-points-live-notification[data-position="middle-left"],
    .win-points-live-notification[data-position="middle-right"] {
      top: 50%;
    }
    .win-points-live-notification[data-position="middle-left"] {
      transform: translate3d(-18px, -50%, 0);
    }
    .win-points-live-notification[data-position="middle-right"] {
      transform: translate3d(18px, -50%, 0);
    }
    .win-points-live-notification.is-visible[data-position="middle-left"],
    .win-points-live-notification.is-visible[data-position="middle-right"] {
      transform: translate3d(0, -50%, 0);
    }
    .win-points-live-notification__pulse {
      width: 8px;
      height: 8px;
    }
    .win-points-live-notification__logo-wrap {
      width: 36px;
      height: 36px;
      border-radius: 12px;
    }
    .win-points-live-notification__logo-fallback {
      font-size: 0.72rem;
    }
    .win-points-live-notification__title {
      font-size: 0.82rem;
      margin-bottom: 0.06rem;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }
    .win-points-live-notification__detail {
      font-size: 0.72rem;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }
    .win-points-mobile-field {
      display: grid;
      gap: 0.3rem;
    }
    .win-points-mobile-label {
      color: #7dd3fc;
      font-size: 0.72rem;
      text-transform: uppercase;
      letter-spacing: 0.08em;
      font-weight: 700;
    }
    .win-points-table-responsive {
      display: none;
    }
  }
</style>

<section class="container py-5">
  <div class="win-points-shell">
    <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3">
      <div>
        <h1 class="display-5 fw-bold text-info mb-2">Modulo de <?= htmlspecialchars($winPointsConfig['name'], ENT_QUOTES, 'UTF-8') ?></h1>
        <p class="text-secondary mb-0">Administra el nombre, icono, las reglas de canje por paquete y los ajustes manuales de saldo.</p>
      </div>
      <a href="<?= htmlspecialchars($dashboardUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline-info fw-bold px-4">Volver al panel</a>
    </div>

    <div class="win-points-tabs" role="tablist" aria-label="Secciones Win Points">
      <button type="button" class="win-points-tab is-active" data-win-points-tab="overview">Resumen</button>
      <button type="button" class="win-points-tab" data-win-points-tab="setup">Configuraci&oacute;n</button>
      <button type="button" class="win-points-tab" data-win-points-tab="rules">Reglas</button>
      <button type="button" class="win-points-tab" data-win-points-tab="wallets">Wallets</button>
      <button type="button" class="win-points-tab" data-win-points-tab="ledger">Movimientos</button>
    </div>

    <div class="win-points-tab-panel is-active" data-win-points-tab-panel="overview">
      <div class="row g-3">
      <div class="col-md-4">
        <div class="win-points-kpi">
          <div class="text-secondary small text-uppercase mb-2">Estado global</div>
          <div class="h3 fw-bold <?= $winPointsConfig['enabled'] ? 'text-success' : 'text-danger' ?> mb-1"><?= $winPointsConfig['enabled'] ? 'Activo' : 'Inactivo' ?></div>
          <div class="text-secondary">Indica si el programa de premios por recarga esta habilitado para la tienda.</div>
        </div>
      </div>
      <div class="col-md-4">
        <div class="win-points-kpi">
          <div class="text-secondary small text-uppercase mb-2">Saldo total acumulado</div>
          <div class="h3 fw-bold text-info mb-1"><?= number_format($totalWalletBalance) ?></div>
          <div class="text-secondary">Disponible entre <?= count($adminWallets) ?> usuarios con wallet registrada.</div>
        </div>
      </div>
      <div class="col-md-4">
        <div class="win-points-kpi">
          <div class="text-secondary small text-uppercase mb-2">Reglas de canje</div>
          <div class="h3 fw-bold text-info mb-1"><?= count($adminRules) ?></div>
          <div class="text-secondary">Cada regla define cuantas unidades de <?= htmlspecialchars($winPointsConfig['name'], ENT_QUOTES, 'UTF-8') ?> cuesta un paquete.</div>
        </div>
      </div>
      </div>
    </div>

    <div class="win-points-tab-panel" data-win-points-tab-panel="setup">
      <div class="row g-4">
      <div class="col-xl-5">
        <div class="win-points-panel h-100">
          <div class="d-flex align-items-start justify-content-between gap-3 mb-4">
            <div>
              <h2 class="h4 text-info fw-bold mb-1">Configuracion global</h2>
              <p class="text-secondary mb-0">Nombre, icono y colores del distintivo global de premios por recarga.</p>
            </div>
            <div class="win-points-icon-preview">
              <?php if ($winPointsConfig['icon_url'] !== ''): ?>
                <img src="<?= htmlspecialchars($winPointsConfig['icon_url'], ENT_QUOTES, 'UTF-8') ?>" alt="Icono <?= htmlspecialchars($winPointsConfig['name'], ENT_QUOTES, 'UTF-8') ?>">
              <?php else: ?>
                <span class="text-info fw-bold">WP</span>
              <?php endif; ?>
            </div>
          </div>

          <form method="POST" enctype="multipart/form-data" class="row g-3">
            <input type="hidden" name="save_win_points_program" value="1">
            <div class="col-md-7">
              <label class="form-label text-info">Nombre de la moneda</label>
              <input type="text" name="win_points_name" value="<?= htmlspecialchars($winPointsConfig['name'], ENT_QUOTES, 'UTF-8') ?>" class="form-control bg-dark text-info border-info" required>
            </div>
            <div class="col-md-7">
              <label class="form-label text-info">Icono del programa</label>
              <input type="file" name="win_points_icon" accept="image/png,image/jpeg,image/webp,image/gif,image/svg+xml" class="form-control bg-dark text-info border-info" data-win-points-icon-input>
              <div class="form-text mt-2">La vista previa se muestra en formato cuadrado sin recortar la imagen.</div>
            </div>
            <div class="col-md-7">
              <label class="form-label text-info">Imagen método de pago</label>
              <input type="file" name="win_points_payment_image" accept="image/png,image/jpeg,image/webp,image/gif,image/svg+xml" class="form-control bg-dark text-info border-info">
              <div class="form-text mt-2">Esta imagen se mostrará en los paquetes como método de pago Win Points. Tamaño recomendado: 1200x480 px.</div>
            </div>
            <div class="col-md-7">
              <label class="form-label text-info">Imagen promocional de esquina</label>
              <input type="file" name="win_points_payment_corner_image" accept="image/png,image/jpeg,image/webp,image/gif,image/svg+xml" class="form-control bg-dark text-info border-info">
              <div class="form-text mt-2">Se mostrará flotando en la esquina de la card pública de Win Points. Tamaño recomendado: 320x320 px con transparencia.</div>
            </div>
            <div class="col-md-6">
              <label class="form-label text-info">Color de fondo del badge</label>
              <input type="color" name="win_points_badge_background_color" value="<?= htmlspecialchars($winPointsBadgeBackgroundColor, ENT_QUOTES, 'UTF-8') ?>" class="form-control form-control-color bg-dark border-info w-100" style="height:3rem;" data-win-points-badge-bg-input>
              <div class="form-text mt-2">Fondo del distintivo que aparece en los paquetes.</div>
            </div>
            <div class="col-md-6">
              <label class="form-label text-info">Color del texto del badge</label>
              <input type="color" name="win_points_badge_text_color" value="<?= htmlspecialchars($winPointsBadgeTextColor, ENT_QUOTES, 'UTF-8') ?>" class="form-control form-control-color bg-dark border-info w-100" style="height:3rem;" data-win-points-badge-text-input>
              <div class="form-text mt-2">Color del texto y realce del distintivo.</div>
            </div>
            <div class="col-md-6">
              <label class="form-label text-info">Dias de vencimiento</label>
              <input type="number" min="1" max="3650" name="win_points_expiration_days" value="<?= $winPointsExpirationDays ?>" class="form-control bg-dark text-info border-info" required>
              <div class="form-text mt-2">Cada nueva recarga que otorgue premios reinicia este contador para el saldo del usuario.</div>
            </div>
            <div class="col-md-5">
              <label class="form-label text-info">Vista previa</label>
              <div class="win-points-icon-upload-stage" data-win-points-icon-stage data-default-src="<?= htmlspecialchars($winPointsConfig['icon_url'], ENT_QUOTES, 'UTF-8') ?>">
                <?php if ($winPointsConfig['icon_url'] !== ''): ?>
                  <img src="<?= htmlspecialchars($winPointsConfig['icon_url'], ENT_QUOTES, 'UTF-8') ?>" alt="Preview <?= htmlspecialchars($winPointsConfig['name'], ENT_QUOTES, 'UTF-8') ?>" data-win-points-icon-img>
                <?php else: ?>
                  <span class="win-points-icon-upload-empty" data-win-points-icon-empty>Sin icono</span>
                <?php endif; ?>
              </div>
            </div>
            <div class="col-md-5">
              <label class="form-label text-info">Vista previa método de pago</label>
              <div class="win-points-icon-upload-stage">
                <?php if (!empty($winPointsConfig['payment_image_url'])): ?>
                  <img src="<?= htmlspecialchars((string) $winPointsConfig['payment_image_url'], ENT_QUOTES, 'UTF-8') ?>" alt="Metodo de pago <?= htmlspecialchars($winPointsConfig['name'], ENT_QUOTES, 'UTF-8') ?>">
                <?php else: ?>
                  <span class="win-points-icon-upload-empty">Sin imagen</span>
                <?php endif; ?>
              </div>
            </div>
            <div class="col-md-5">
              <label class="form-label text-info">Vista previa promo esquina</label>
              <div class="win-points-icon-upload-stage">
                <?php if (!empty($winPointsConfig['payment_corner_image_url'])): ?>
                  <img src="<?= htmlspecialchars((string) $winPointsConfig['payment_corner_image_url'], ENT_QUOTES, 'UTF-8') ?>" alt="Promo metodo de pago <?= htmlspecialchars($winPointsConfig['name'], ENT_QUOTES, 'UTF-8') ?>" style="max-width: 180px; margin-inline: auto;">
                <?php else: ?>
                  <span class="win-points-icon-upload-empty">Sin imagen</span>
                <?php endif; ?>
              </div>
            </div>
            <div class="col-12">
              <label class="form-label text-info">Vista previa del distintivo en paquetes</label>
              <div class="win-points-badge-preview-wrap">
                <div class="win-points-badge-preview" data-win-points-badge-preview style="--wp-badge-bg: <?= htmlspecialchars($winPointsBadgeBackgroundColor, ENT_QUOTES, 'UTF-8') ?>; --wp-badge-text: <?= htmlspecialchars($winPointsBadgeTextColor, ENT_QUOTES, 'UTF-8') ?>; --wp-badge-border: <?= htmlspecialchars($winPointsBadgeBorderColor, ENT_QUOTES, 'UTF-8') ?>; --wp-badge-shadow: <?= htmlspecialchars($winPointsBadgeInsetColor, ENT_QUOTES, 'UTF-8') ?>;">
                  <?php if ($winPointsConfig['icon_url'] !== ''): ?>
                    <img src="<?= htmlspecialchars($winPointsConfig['icon_url'], ENT_QUOTES, 'UTF-8') ?>" alt="Badge <?= htmlspecialchars($winPointsConfig['name'], ENT_QUOTES, 'UTF-8') ?>">
                  <?php endif; ?>
                  <span data-win-points-badge-label>+3 <?= htmlspecialchars($winPointsConfig['name'], ENT_QUOTES, 'UTF-8') ?></span>
                </div>
              </div>
            </div>
            <div class="col-12">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" value="1" id="removeWinPointsIcon" name="remove_win_points_icon" data-win-points-icon-remove>
                <label class="form-check-label" for="removeWinPointsIcon">Eliminar icono actual</label>
              </div>
            </div>
            <div class="col-12">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" value="1" id="removeWinPointsPaymentImage" name="remove_win_points_payment_image">
                <label class="form-check-label" for="removeWinPointsPaymentImage">Eliminar imagen actual del método de pago</label>
              </div>
            </div>
            <div class="col-12">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" value="1" id="removeWinPointsPaymentCornerImage" name="remove_win_points_payment_corner_image">
                <label class="form-check-label" for="removeWinPointsPaymentCornerImage">Eliminar imagen promocional actual</label>
              </div>
            </div>
            <div class="col-12 d-grid">
              <button type="submit" class="btn btn-info fw-bold py-3">Guardar configuracion global</button>
            </div>
          </form>
        </div>
      </div>

      <div class="col-xl-7">
        <div class="win-points-setup-stack">
        <div class="win-points-panel">
          <div class="mb-4">
            <h2 class="h4 text-info fw-bold mb-1">Crear o actualizar regla de canje</h2>
            <p class="text-secondary mb-0">Selecciona el paquete, define cuanto premio entrega por compra y cuantos <?= htmlspecialchars($winPointsConfig['name'], ENT_QUOTES, 'UTF-8') ?> cuesta canjearlo.</p>
          </div>

          <form method="POST" class="row g-3">
            <input type="hidden" name="save_win_points_rule" value="1">
            <div class="col-lg-5">
              <label class="form-label text-info">Paquete</label>
              <select name="rule_package_id" class="form-select bg-dark text-info border-info" data-rule-package-select required>
                <option value="">Selecciona un paquete</option>
                <?php foreach ($packageOptionsByGame as $gameName => $gamePackages): ?>
                  <optgroup label="<?= htmlspecialchars($gameName, ENT_QUOTES, 'UTF-8') ?>">
                    <?php foreach ($gamePackages as $gamePackage): ?>
                      <option value="<?= (int) ($gamePackage['id'] ?? 0) ?>" data-current-reward="<?= (int) ($gamePackage['win_points_reward'] ?? 0) ?>">
                        <?= htmlspecialchars((string) ($gamePackage['paquete_nombre'] ?? 'Paquete'), ENT_QUOTES, 'UTF-8') ?> | +<?= (int) ($gamePackage['win_points_reward'] ?? 0) ?> <?= htmlspecialchars($winPointsConfig['name'], ENT_QUOTES, 'UTF-8') ?>
                      </option>
                    <?php endforeach; ?>
                  </optgroup>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-sm-6 col-lg-3">
              <label class="form-label text-info">Premio por compra</label>
              <input type="number" min="0" name="rule_reward_points" class="form-control bg-dark text-info border-info" data-rule-reward-input required>
            </div>
            <div class="col-sm-6 col-lg-2">
              <label class="form-label text-info">Costo en puntos</label>
              <input type="number" min="1" name="rule_required_points" class="form-control bg-dark text-info border-info" required>
            </div>
            <div class="col-sm-4 col-lg-2">
              <label class="form-label text-info">Orden</label>
              <input type="number" min="1" name="rule_order" class="form-control bg-dark text-info border-info" placeholder="1">
            </div>
            <div class="col-sm-4 col-lg-4 d-flex align-items-end">
              <div class="form-check form-switch mb-2">
                <input class="form-check-input" type="checkbox" role="switch" id="ruleActiveNew" name="rule_active" checked>
                <label class="form-check-label fw-semibold" for="ruleActiveNew">Regla activa</label>
              </div>
            </div>
            <div class="col-sm-4 col-lg-4 d-grid ms-lg-auto">
              <button type="submit" class="btn btn-success fw-bold py-3">Guardar regla</button>
            </div>
          </form>
        </div>
        </div>
      </div>
      </div>
    </div>

    <div class="win-points-tab-panel" data-win-points-tab-panel="rules">
    <div class="win-points-panel" data-wp-section="rules">
      <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3 mb-4">
        <div>
          <h2 class="h4 text-info fw-bold mb-1">Reglas actuales</h2>
          <p class="text-secondary mb-0">Puedes editar puntos requeridos, orden o estado directamente sobre cada regla creada.</p>
        </div>
      </div>

      <?php if (empty($adminRules)): ?>
        <div class="text-secondary">Aun no hay reglas de canje creadas.</div>
      <?php else: ?>
        <div class="win-points-section-tools">
          <div class="win-points-filter-group">
            <div class="win-points-filter-field">
              <label class="form-label text-info small mb-0">Buscar</label>
              <input type="search" class="form-control bg-dark text-info border-info" placeholder="Juego, paquete, premio o costo" data-wp-filter-search>
            </div>
            <div class="win-points-filter-field win-points-filter-field--compact">
              <label class="form-label text-info small mb-0">Estado</label>
              <select class="form-select bg-dark text-info border-info" data-wp-filter-extra>
                <option value="all">Todas</option>
                <option value="active">Activas</option>
                <option value="inactive">Inactivas</option>
              </select>
            </div>
            <div class="win-points-filter-field win-points-filter-field--compact">
              <label class="form-label text-info small mb-0">Elementos por pagina</label>
              <select class="form-select bg-dark text-info border-info" data-wp-filter-size>
                <option value="5" selected>5</option>
                <option value="10">10</option>
                <option value="25">25</option>
                <option value="50">50</option>
              </select>
            </div>
          </div>
          <div class="win-points-filter-actions">
            <span class="win-points-pill" data-wp-filter-count data-wp-count-singular="regla visible" data-wp-count-plural="reglas visibles"><?= count($adminRules) ?> reglas visibles</span>
          </div>
        </div>
        <div class="win-points-mobile-stack">
          <?php foreach ($adminRules as $ruleIndex => $rule): ?>
            <?php $mobileEditFormId = 'winPointsRuleMobileForm' . (int) ($rule['id'] ?? 0); ?>
            <div class="win-points-mobile-card" data-wp-item="rules" data-wp-key="rule-<?= (int) $ruleIndex ?>" data-wp-extra="<?= !empty($rule['activo']) ? 'active' : 'inactive' ?>" data-wp-filter="<?= htmlspecialchars(trim((string) (($rule['juego_nombre'] ?? '') . ' ' . ($rule['paquete_nombre'] ?? '') . ' ' . (int) ($rule['win_points_reward'] ?? 0) . ' ' . (int) ($rule['required_points'] ?? 0) . ' ' . (isset($rule['orden']) && $rule['orden'] !== null ? (int) $rule['orden'] : ''))), ENT_QUOTES, 'UTF-8') ?>">
              <form id="<?= htmlspecialchars($mobileEditFormId, ENT_QUOTES, 'UTF-8') ?>" method="POST" class="win-points-rule-inline-form">
                <input type="hidden" name="save_win_points_rule" value="1">
                <input type="hidden" name="rule_package_id" value="<?= (int) ($rule['paquete_id'] ?? 0) ?>">
              </form>
              <div class="win-points-mobile-field">
                <span class="win-points-mobile-label">Juego</span>
                <div><?= htmlspecialchars((string) ($rule['juego_nombre'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
              </div>
              <div class="win-points-mobile-field">
                <span class="win-points-mobile-label">Paquete</span>
                <div><?= htmlspecialchars((string) ($rule['paquete_nombre'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
              </div>
              <div class="row g-3">
                <div class="col-6">
                  <label class="form-label text-info small mb-1">Premio por compra</label>
                  <input type="number" min="0" name="rule_reward_points" value="<?= max(0, (int) ($rule['win_points_reward'] ?? 0)) ?>" form="<?= htmlspecialchars($mobileEditFormId, ENT_QUOTES, 'UTF-8') ?>" class="form-control form-control-sm bg-dark text-info border-info" required>
                </div>
                <div class="col-6">
                  <label class="form-label text-info small mb-1">Costo de canje</label>
                  <input type="number" min="1" name="rule_required_points" value="<?= max(1, (int) ($rule['required_points'] ?? 0)) ?>" form="<?= htmlspecialchars($mobileEditFormId, ENT_QUOTES, 'UTF-8') ?>" class="form-control form-control-sm bg-dark text-info border-info" required>
                </div>
                <div class="col-6">
                  <label class="form-label text-info small mb-1">Orden</label>
                  <input type="number" min="1" name="rule_order" value="<?= isset($rule['orden']) && $rule['orden'] !== null ? (int) $rule['orden'] : '' ?>" form="<?= htmlspecialchars($mobileEditFormId, ENT_QUOTES, 'UTF-8') ?>" class="form-control form-control-sm bg-dark text-info border-info" placeholder="Orden">
                </div>
                <div class="col-6 d-flex align-items-end">
                  <div class="form-check form-switch m-0">
                    <input class="form-check-input" type="checkbox" name="rule_active" form="<?= htmlspecialchars($mobileEditFormId, ENT_QUOTES, 'UTF-8') ?>" <?= !empty($rule['activo']) ? 'checked' : '' ?>>
                    <label class="form-check-label small ms-2">Activa</label>
                  </div>
                </div>
              </div>
              <div class="win-points-rule-actions">
                <button type="submit" form="<?= htmlspecialchars($mobileEditFormId, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-outline-info fw-bold">Guardar</button>
                <form method="POST" onsubmit="return confirm('¿Eliminar esta regla de canje?');" class="win-points-rule-inline-form">
                  <input type="hidden" name="delete_win_points_rule" value="1">
                  <input type="hidden" name="rule_id" value="<?= (int) ($rule['id'] ?? 0) ?>">
                  <button type="submit" class="btn btn-sm btn-outline-danger fw-bold">Eliminar</button>
                </form>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
        <div class="win-points-table-responsive">
          <table class="table table-dark table-hover align-middle win-points-table">
            <thead>
              <tr>
                <th>Juego</th>
                <th>Paquete</th>
                <th class="text-center">Premio por compra</th>
                <th class="text-center">Costo de canje</th>
                <th class="text-center">Orden</th>
                <th class="text-center">Activo</th>
                <th class="text-end">Acciones</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($adminRules as $ruleIndex => $rule): ?>
                <?php $editFormId = 'winPointsRuleForm' . (int) ($rule['id'] ?? 0); ?>
                <tr data-wp-item="rules" data-wp-key="rule-<?= (int) $ruleIndex ?>" data-wp-extra="<?= !empty($rule['activo']) ? 'active' : 'inactive' ?>" data-wp-filter="<?= htmlspecialchars(trim((string) (($rule['juego_nombre'] ?? '') . ' ' . ($rule['paquete_nombre'] ?? '') . ' ' . (int) ($rule['win_points_reward'] ?? 0) . ' ' . (int) ($rule['required_points'] ?? 0) . ' ' . (isset($rule['orden']) && $rule['orden'] !== null ? (int) $rule['orden'] : ''))), ENT_QUOTES, 'UTF-8') ?>">
                  <td data-label="Juego">
                    <form id="<?= htmlspecialchars($editFormId, ENT_QUOTES, 'UTF-8') ?>" method="POST" class="win-points-rule-inline-form">
                      <input type="hidden" name="save_win_points_rule" value="1">
                      <input type="hidden" name="rule_package_id" value="<?= (int) ($rule['paquete_id'] ?? 0) ?>">
                    </form>
                    <?= htmlspecialchars((string) ($rule['juego_nombre'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                  </td>
                  <td data-label="Paquete"><?= htmlspecialchars((string) ($rule['paquete_nombre'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                  <td data-label="Premio por compra">
                    <input type="number" min="0" name="rule_reward_points" value="<?= max(0, (int) ($rule['win_points_reward'] ?? 0)) ?>" form="<?= htmlspecialchars($editFormId, ENT_QUOTES, 'UTF-8') ?>" class="form-control form-control-sm bg-dark text-info border-info win-points-rule-field text-center" required>
                  </td>
                  <td data-label="Costo de canje">
                    <input type="number" min="1" name="rule_required_points" value="<?= max(1, (int) ($rule['required_points'] ?? 0)) ?>" form="<?= htmlspecialchars($editFormId, ENT_QUOTES, 'UTF-8') ?>" class="form-control form-control-sm bg-dark text-info border-info win-points-rule-field text-center" required>
                  </td>
                  <td data-label="Orden">
                    <input type="number" min="1" name="rule_order" value="<?= isset($rule['orden']) && $rule['orden'] !== null ? (int) $rule['orden'] : '' ?>" form="<?= htmlspecialchars($editFormId, ENT_QUOTES, 'UTF-8') ?>" class="form-control form-control-sm bg-dark text-info border-info win-points-rule-field text-center" placeholder="Orden">
                  </td>
                  <td class="text-center" data-label="Activo">
                    <div class="form-check form-switch win-points-rule-active m-0">
                      <input class="form-check-input" type="checkbox" name="rule_active" form="<?= htmlspecialchars($editFormId, ENT_QUOTES, 'UTF-8') ?>" <?= !empty($rule['activo']) ? 'checked' : '' ?>>
                    </div>
                  </td>
                  <td class="text-end" data-label="Acciones">
                    <div class="win-points-rule-actions">
                      <button type="submit" form="<?= htmlspecialchars($editFormId, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-outline-info fw-bold">Guardar</button>
                      <form method="POST" onsubmit="return confirm('¿Eliminar esta regla de canje?');" class="win-points-rule-inline-form">
                        <input type="hidden" name="delete_win_points_rule" value="1">
                        <input type="hidden" name="rule_id" value="<?= (int) ($rule['id'] ?? 0) ?>">
                        <button type="submit" class="btn btn-sm btn-outline-danger fw-bold">Eliminar</button>
                      </form>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <div class="win-points-pagination" data-wp-pagination>
          <div class="text-secondary small" data-wp-pagination-info></div>
          <div class="win-points-pagination-nav">
            <button type="button" class="btn btn-sm btn-outline-info" data-wp-page-prev>Anterior</button>
            <button type="button" class="btn btn-sm btn-outline-info" data-wp-page-next>Siguiente</button>
          </div>
        </div>
      <?php endif; ?>
    </div>
    </div>

    <div class="win-points-tab-panel" data-win-points-tab-panel="wallets">
    <div class="row g-4">
      <div class="col-xl-7">
        <div class="win-points-panel h-100" data-wp-section="wallets">
          <div class="d-flex align-items-center justify-content-between gap-3 mb-4">
            <div>
              <h2 class="h4 text-info fw-bold mb-1">Wallets de usuarios</h2>
              <p class="text-secondary mb-0">Saldo disponible por usuario registrado para usar en proximas recargas.</p>
            </div>
            <span class="win-points-pill"><?= number_format($totalWalletBalance) ?> puntos</span>
          </div>

          <?php if (!empty($adminWallets)): ?>
            <div class="win-points-section-tools">
              <div class="win-points-filter-group">
                <div class="win-points-filter-field">
                  <label class="form-label text-info small mb-0">Buscar</label>
                  <input type="search" class="form-control bg-dark text-info border-info" placeholder="Usuario, correo o telefono" data-wp-filter-search>
                </div>
                <div class="win-points-filter-field win-points-filter-field--compact">
                  <label class="form-label text-info small mb-0">Saldo</label>
                  <select class="form-select bg-dark text-info border-info" data-wp-filter-extra>
                    <option value="all">Todos</option>
                    <option value="positive">Con saldo</option>
                    <option value="zero">Sin saldo</option>
                  </select>
                </div>
                <div class="win-points-filter-field win-points-filter-field--compact">
                  <label class="form-label text-info small mb-0">Elementos por pagina</label>
                  <select class="form-select bg-dark text-info border-info" data-wp-filter-size>
                    <option value="5" selected>5</option>
                    <option value="10">10</option>
                    <option value="25">25</option>
                    <option value="50">50</option>
                  </select>
                </div>
              </div>
              <div class="win-points-filter-actions">
                <span class="win-points-pill" data-wp-filter-count data-wp-count-singular="wallet visible" data-wp-count-plural="wallets visibles"><?= count($adminWallets) ?> wallets visibles</span>
              </div>
            </div>
          <?php endif; ?>

          <div class="win-points-mobile-stack">
            <?php foreach ($adminWallets as $walletIndex => $wallet): ?>
              <div class="win-points-mobile-card" data-wp-item="wallets" data-wp-key="wallet-<?= (int) $walletIndex ?>" data-wp-extra="<?= ((int) ($wallet['balance'] ?? 0)) > 0 ? 'positive' : 'zero' ?>" data-wp-filter="<?= htmlspecialchars(trim((string) (($wallet['nombre'] ?? '') . ' ' . ($wallet['email'] ?? '') . ' ' . ($wallet['telefono'] ?? '') . ' ' . (int) ($wallet['balance'] ?? 0) . ' ' . (int) ($wallet['earned_points'] ?? 0) . ' ' . (int) ($wallet['spent_points'] ?? 0) . ' ' . ($wallet['days_remaining_label'] ?? ''))), ENT_QUOTES, 'UTF-8') ?>">
                <div class="win-points-mobile-field">
                  <span class="win-points-mobile-label">Usuario</span>
                  <div><?= htmlspecialchars((string) ($wallet['nombre'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                </div>
                <div class="win-points-mobile-field">
                  <span class="win-points-mobile-label">Correo</span>
                  <div><?= htmlspecialchars((string) ($wallet['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                </div>
                <div class="win-points-mobile-field">
                  <span class="win-points-mobile-label">Telefono</span>
                  <div><?= htmlspecialchars(trim((string) ($wallet['telefono'] ?? '')) !== '' ? (string) $wallet['telefono'] : 'No disponible', ENT_QUOTES, 'UTF-8') ?></div>
                </div>
                <div class="row g-3">
                  <div class="col-6">
                    <div class="win-points-mobile-field">
                      <span class="win-points-mobile-label">Ganados</span>
                      <div class="text-success fw-bold"><?= number_format((int) ($wallet['earned_points'] ?? 0)) ?></div>
                    </div>
                  </div>
                  <div class="col-6">
                    <div class="win-points-mobile-field">
                      <span class="win-points-mobile-label">Gastados</span>
                      <div class="text-warning fw-bold"><?= number_format((int) ($wallet['spent_points'] ?? 0)) ?></div>
                    </div>
                  </div>
                  <div class="col-6">
                    <div class="win-points-mobile-field">
                      <span class="win-points-mobile-label">Movimientos</span>
                      <div><?= number_format((int) ($wallet['total_transactions'] ?? 0)) ?></div>
                    </div>
                  </div>
                  <div class="col-6">
                    <div class="win-points-mobile-field">
                      <span class="win-points-mobile-label">Saldo</span>
                      <div class="text-info fw-bold"><?= number_format((int) ($wallet['balance'] ?? 0)) ?></div>
                    </div>
                  </div>
                  <div class="col-6">
                    <div class="win-points-mobile-field">
                      <span class="win-points-mobile-label">Dias restantes</span>
                      <div class="<?= !empty($wallet['is_expired']) ? 'text-danger' : 'text-light' ?> fw-bold"><?= htmlspecialchars((string) ($wallet['days_remaining_label'] ?? 'Sin saldo'), ENT_QUOTES, 'UTF-8') ?></div>
                    </div>
                  </div>
                </div>
                <div class="win-points-mobile-field">
                  <span class="win-points-mobile-label">Vence</span>
                  <div><?= htmlspecialchars((string) ($wallet['expires_at_label'] ?? 'Sin saldo'), ENT_QUOTES, 'UTF-8') ?></div>
                </div>
                <div class="win-points-mobile-field">
                  <span class="win-points-mobile-label">Ultimo movimiento</span>
                  <div><?= htmlspecialchars((string) ($wallet['last_transaction_at'] ?? 'Sin movimientos'), ENT_QUOTES, 'UTF-8') ?></div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
          <div class="win-points-table-responsive">
            <table class="table table-dark table-hover align-middle win-points-table">
              <thead>
                <tr>
                  <th>Usuario</th>
                  <th>Correo</th>
                  <th>Telefono</th>
                  <th class="text-end">Ganados</th>
                  <th class="text-end">Gastados</th>
                  <th class="text-end">Movimientos</th>
                  <th class="text-end">Saldo</th>
                  <th>Dias restantes</th>
                  <th>Ultimo movimiento</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($adminWallets as $walletIndex => $wallet): ?>
                  <tr data-wp-item="wallets" data-wp-key="wallet-<?= (int) $walletIndex ?>" data-wp-extra="<?= ((int) ($wallet['balance'] ?? 0)) > 0 ? 'positive' : 'zero' ?>" data-wp-filter="<?= htmlspecialchars(trim((string) (($wallet['nombre'] ?? '') . ' ' . ($wallet['email'] ?? '') . ' ' . ($wallet['telefono'] ?? '') . ' ' . (int) ($wallet['balance'] ?? 0) . ' ' . (int) ($wallet['earned_points'] ?? 0) . ' ' . (int) ($wallet['spent_points'] ?? 0) . ' ' . ($wallet['days_remaining_label'] ?? ''))), ENT_QUOTES, 'UTF-8') ?>">
                    <td data-label="Usuario"><?= htmlspecialchars((string) ($wallet['nombre'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                    <td data-label="Correo"><?= htmlspecialchars((string) ($wallet['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                    <td data-label="Telefono"><?= htmlspecialchars(trim((string) ($wallet['telefono'] ?? '')) !== '' ? (string) $wallet['telefono'] : 'No disponible', ENT_QUOTES, 'UTF-8') ?></td>
                    <td class="text-end text-success fw-bold" data-label="Ganados"><?= number_format((int) ($wallet['earned_points'] ?? 0)) ?></td>
                    <td class="text-end text-warning fw-bold" data-label="Gastados"><?= number_format((int) ($wallet['spent_points'] ?? 0)) ?></td>
                    <td class="text-end text-light" data-label="Movimientos"><?= number_format((int) ($wallet['total_transactions'] ?? 0)) ?></td>
                    <td class="text-end fw-bold text-info" data-label="Saldo"><?= number_format((int) ($wallet['balance'] ?? 0)) ?></td>
                    <td data-label="Dias restantes">
                      <div class="fw-bold <?= !empty($wallet['is_expired']) ? 'text-danger' : 'text-light' ?>"><?= htmlspecialchars((string) ($wallet['days_remaining_label'] ?? 'Sin saldo'), ENT_QUOTES, 'UTF-8') ?></div>
                      <div class="small text-secondary">Vence: <?= htmlspecialchars((string) ($wallet['expires_at_label'] ?? 'Sin saldo'), ENT_QUOTES, 'UTF-8') ?></div>
                    </td>
                    <td data-label="Ultimo movimiento"><?= htmlspecialchars((string) ($wallet['last_transaction_at'] ?? 'Sin movimientos'), ENT_QUOTES, 'UTF-8') ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php if (!empty($adminWallets)): ?>
            <div class="win-points-pagination" data-wp-pagination>
              <div class="text-secondary small" data-wp-pagination-info></div>
              <div class="win-points-pagination-nav">
                <button type="button" class="btn btn-sm btn-outline-info" data-wp-page-prev>Anterior</button>
                <button type="button" class="btn btn-sm btn-outline-info" data-wp-page-next>Siguiente</button>
              </div>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <div class="col-xl-5">
        <div class="win-points-panel h-100">
          <div class="mb-4">
            <h2 class="h4 text-info fw-bold mb-1">Ajuste manual</h2>
            <p class="text-secondary mb-0">Disponible solo para administradores. Usa valores positivos para sumar y negativos para descontar.</p>
          </div>

          <form method="POST" class="row g-3">
            <input type="hidden" name="adjust_win_points_balance" value="1">
            <div class="col-12">
              <label class="form-label text-info">Buscar usuario</label>
              <input type="search" class="form-control bg-dark text-info border-info" placeholder="Escribe nombre, correo o telefono" data-win-points-user-search>
            </div>
            <div class="col-12">
              <label class="form-label text-info">Usuario</label>
              <select name="adjust_user_id" class="form-select bg-dark text-info border-info" data-win-points-user-select required>
                <option value="">Selecciona un usuario</option>
                <?php foreach ($adminUsers as $user): ?>
                  <?php $userSearch = trim((string) (($user['nombre'] ?? '') . ' ' . ($user['email'] ?? '') . ' ' . ($user['telefono'] ?? ''))); ?>
                  <option value="<?= (int) ($user['id'] ?? 0) ?>" data-search="<?= htmlspecialchars(strtolower($userSearch), ENT_QUOTES, 'UTF-8') ?>">
                    <?= htmlspecialchars((string) ($user['nombre'] ?? 'Usuario'), ENT_QUOTES, 'UTF-8') ?> | <?= htmlspecialchars((string) ($user['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?><?= !empty($user['telefono']) ? ' | ' . htmlspecialchars((string) $user['telefono'], ENT_QUOTES, 'UTF-8') : '' ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label text-info">Delta</label>
              <input type="number" name="adjust_points_delta" class="form-control bg-dark text-info border-info" placeholder="-20 o 50" required>
            </div>
            <div class="col-md-8">
              <label class="form-label text-info">Motivo</label>
              <input type="text" name="adjust_reason" class="form-control bg-dark text-info border-info" placeholder="Compensacion, correccion, bono especial" required>
            </div>
            <div class="col-12 d-grid">
              <button type="submit" class="btn btn-warning fw-bold py-3">Aplicar ajuste manual</button>
            </div>
          </form>
        </div>
      </div>
    </div>
    </div>

    <div class="win-points-tab-panel" data-win-points-tab-panel="ledger">
    <div class="win-points-panel" data-wp-section="ledger">
      <div class="d-flex align-items-center justify-content-between gap-3 mb-4">
        <div>
          <h2 class="h4 text-info fw-bold mb-1">Movimientos recientes</h2>
          <p class="text-secondary mb-0">Ultimos movimientos del ledger de premios para auditoria de ganancias, canjes, reversos y ajustes.</p>
        </div>
      </div>

      <?php if (empty($adminTransactions)): ?>
        <div class="text-secondary">Aun no hay movimientos registrados en Win Points.</div>
      <?php else: ?>
        <div class="win-points-section-tools">
          <div class="win-points-filter-group">
            <div class="win-points-filter-field">
              <label class="form-label text-info small mb-0">Buscar</label>
              <input type="search" class="form-control bg-dark text-info border-info" placeholder="Fecha, usuario, pedido o descripcion" data-wp-filter-search>
            </div>
            <div class="win-points-filter-field win-points-filter-field--compact">
              <label class="form-label text-info small mb-0">Tipo</label>
              <select class="form-select bg-dark text-info border-info" data-wp-filter-extra>
                <option value="all">Todos</option>
                <?php foreach ($transactionTypeLabels as $transactionTypeKey => $transactionTypeLabel): ?>
                  <option value="<?= htmlspecialchars((string) $transactionTypeKey, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string) $transactionTypeLabel, ENT_QUOTES, 'UTF-8') ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="win-points-filter-field win-points-filter-field--compact">
              <label class="form-label text-info small mb-0">Elementos por pagina</label>
              <select class="form-select bg-dark text-info border-info" data-wp-filter-size>
                <option value="5" selected>5</option>
                <option value="10">10</option>
                <option value="25">25</option>
                <option value="50">50</option>
              </select>
            </div>
          </div>
          <div class="win-points-filter-actions">
            <span class="win-points-pill" data-wp-filter-count data-wp-count-singular="registro visible" data-wp-count-plural="registros visibles"><?= count($adminTransactions) ?> registros visibles</span>
          </div>
        </div>
        <div class="win-points-mobile-stack">
          <?php foreach ($adminTransactions as $transactionIndex => $transaction): ?>
            <?php $mobileDelta = (int) ($transaction['points_delta'] ?? 0); ?>
            <div class="win-points-mobile-card" data-wp-item="ledger" data-wp-key="ledger-<?= (int) $transactionIndex ?>" data-wp-extra="<?= htmlspecialchars((string) ($transaction['transaction_type'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" data-wp-filter="<?= htmlspecialchars(trim((string) (($transaction['created_at'] ?? '') . ' ' . ($transaction['usuario_nombre'] ?? '') . ' ' . ($transaction['usuario_email'] ?? '') . ' ' . ($transaction['transaction_type'] ?? '') . ' ' . ($transactionTypeLabels[(string) ($transaction['transaction_type'] ?? '')] ?? '') . ' ' . ($transaction['description'] ?? '') . ' ' . ($transaction['juego_nombre'] ?? '') . ' ' . ($transaction['paquete_nombre'] ?? '') . ' ' . (!empty($transaction['order_id']) ? '#' . (int) $transaction['order_id'] : ''))), ENT_QUOTES, 'UTF-8') ?>">
              <div class="win-points-mobile-field">
                <span class="win-points-mobile-label">Fecha</span>
                <div><?= htmlspecialchars((string) ($transaction['created_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
              </div>
              <div class="win-points-mobile-field">
                <span class="win-points-mobile-label">Usuario</span>
                <div class="fw-semibold"><?= htmlspecialchars((string) ($transaction['usuario_nombre'] ?? 'Usuario'), ENT_QUOTES, 'UTF-8') ?></div>
                <div class="small text-secondary"><?= htmlspecialchars((string) ($transaction['usuario_email'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
              </div>
              <div class="row g-3">
                <div class="col-6">
                  <div class="win-points-mobile-field">
                    <span class="win-points-mobile-label">Tipo</span>
                    <div><?= htmlspecialchars($transactionTypeLabels[(string) ($transaction['transaction_type'] ?? '')] ?? (string) ($transaction['transaction_type'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                  </div>
                </div>
                <div class="col-6">
                  <div class="win-points-mobile-field">
                    <span class="win-points-mobile-label">Delta</span>
                    <div class="fw-bold <?= $mobileDelta >= 0 ? 'text-success' : 'text-danger' ?>"><?= $mobileDelta >= 0 ? '+' : '' ?><?= number_format($mobileDelta) ?></div>
                  </div>
                </div>
                <div class="col-6">
                  <div class="win-points-mobile-field">
                    <span class="win-points-mobile-label">Saldo</span>
                    <div class="text-info fw-bold"><?= number_format((int) ($transaction['balance_after'] ?? 0)) ?></div>
                  </div>
                </div>
                <div class="col-6">
                  <div class="win-points-mobile-field">
                    <span class="win-points-mobile-label">Pedido</span>
                    <div><?= !empty($transaction['order_id']) ? '#' . (int) $transaction['order_id'] : '—' ?></div>
                  </div>
                </div>
              </div>
              <div class="win-points-mobile-field">
                <span class="win-points-mobile-label">Descripcion</span>
                <div><?= htmlspecialchars((string) ($transaction['description'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                <?php if (!empty($transaction['juego_nombre']) || !empty($transaction['paquete_nombre'])): ?>
                  <div class="small text-secondary"><?= htmlspecialchars(trim((string) (($transaction['juego_nombre'] ?? '') . ' ' . ($transaction['paquete_nombre'] ?? ''))), ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
        <div class="win-points-table-responsive">
          <table class="table table-dark table-hover align-middle win-points-table">
            <thead>
              <tr>
                <th>Fecha</th>
                <th>Usuario</th>
                <th>Tipo</th>
                <th>Delta</th>
                <th>Saldo</th>
                <th>Pedido</th>
                <th>Descripcion</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($adminTransactions as $transactionIndex => $transaction): ?>
                <?php $delta = (int) ($transaction['points_delta'] ?? 0); ?>
                <tr data-wp-item="ledger" data-wp-key="ledger-<?= (int) $transactionIndex ?>" data-wp-extra="<?= htmlspecialchars((string) ($transaction['transaction_type'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" data-wp-filter="<?= htmlspecialchars(trim((string) (($transaction['created_at'] ?? '') . ' ' . ($transaction['usuario_nombre'] ?? '') . ' ' . ($transaction['usuario_email'] ?? '') . ' ' . ($transaction['transaction_type'] ?? '') . ' ' . ($transactionTypeLabels[(string) ($transaction['transaction_type'] ?? '')] ?? '') . ' ' . ($transaction['description'] ?? '') . ' ' . ($transaction['juego_nombre'] ?? '') . ' ' . ($transaction['paquete_nombre'] ?? '') . ' ' . (!empty($transaction['order_id']) ? '#' . (int) $transaction['order_id'] : ''))), ENT_QUOTES, 'UTF-8') ?>">
                  <td data-label="Fecha"><?= htmlspecialchars((string) ($transaction['created_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                  <td data-label="Usuario">
                    <div class="fw-semibold"><?= htmlspecialchars((string) ($transaction['usuario_nombre'] ?? 'Usuario'), ENT_QUOTES, 'UTF-8') ?></div>
                    <div class="small text-secondary"><?= htmlspecialchars((string) ($transaction['usuario_email'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                  </td>
                  <td data-label="Tipo"><?= htmlspecialchars($transactionTypeLabels[(string) ($transaction['transaction_type'] ?? '')] ?? (string) ($transaction['transaction_type'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                  <td class="fw-bold <?= $delta >= 0 ? 'text-success' : 'text-danger' ?>" data-label="Delta"><?= $delta >= 0 ? '+' : '' ?><?= number_format($delta) ?></td>
                  <td class="text-info fw-bold" data-label="Saldo"><?= number_format((int) ($transaction['balance_after'] ?? 0)) ?></td>
                  <td data-label="Pedido"><?= !empty($transaction['order_id']) ? '#' . (int) $transaction['order_id'] : '—' ?></td>
                  <td data-label="Descripcion">
                    <div><?= htmlspecialchars((string) ($transaction['description'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                    <?php if (!empty($transaction['juego_nombre']) || !empty($transaction['paquete_nombre'])): ?>
                      <div class="small text-secondary">
                        <?= htmlspecialchars(trim((string) (($transaction['juego_nombre'] ?? '') . ' ' . ($transaction['paquete_nombre'] ?? ''))), ENT_QUOTES, 'UTF-8') ?>
                      </div>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <div class="win-points-pagination" data-wp-pagination>
          <div class="text-secondary small" data-wp-pagination-info></div>
          <div class="win-points-pagination-nav">
            <button type="button" class="btn btn-sm btn-outline-info" data-wp-page-prev>Anterior</button>
            <button type="button" class="btn btn-sm btn-outline-info" data-wp-page-next>Siguiente</button>
          </div>
        </div>
      <?php endif; ?>
    </div>
    </div>
  </div>
</section>

<script>
  (function () {
    const tabs = Array.from(document.querySelectorAll('[data-win-points-tab]'));
    const tabPanels = Array.from(document.querySelectorAll('[data-win-points-tab-panel]'));
    const iconInput = document.querySelector('[data-win-points-icon-input]');
    const iconStage = document.querySelector('[data-win-points-icon-stage]');
    const iconRemove = document.querySelector('[data-win-points-icon-remove]');
    const badgeBackgroundInput = document.querySelector('[data-win-points-badge-bg-input]');
    const badgeTextInput = document.querySelector('[data-win-points-badge-text-input]');
    const badgePreview = document.querySelector('[data-win-points-badge-preview]');
    const badgeLabel = document.querySelector('[data-win-points-badge-label]');
    const programNameInput = document.querySelector('[name="win_points_name"]');
    const packageSelect = document.querySelector('[data-rule-package-select]');
    const rewardInput = document.querySelector('[data-rule-reward-input]');
    const adjustmentUserSearch = document.querySelector('[data-win-points-user-search]');
    const adjustmentUserSelect = document.querySelector('[data-win-points-user-select]');

    function activateTab(targetTab) {
      if (!targetTab) {
        return;
      }

      tabs.forEach(function (tabButton) {
        const isActive = tabButton.dataset.winPointsTab === targetTab;
        tabButton.classList.toggle('is-active', isActive);
      });

      tabPanels.forEach(function (panel) {
        const isActive = panel.dataset.winPointsTabPanel === targetTab;
        panel.classList.toggle('is-active', isActive);
      });
    }

    function renderIconPreview(src) {
      if (!iconStage) {
        return;
      }

      let previewImg = iconStage.querySelector('[data-win-points-icon-img]');
      let emptyState = iconStage.querySelector('[data-win-points-icon-empty]');

      if (src) {
        if (!previewImg) {
          previewImg = document.createElement('img');
          previewImg.setAttribute('data-win-points-icon-img', '1');
          iconStage.appendChild(previewImg);
        }
        previewImg.src = src;
        if (emptyState) {
          emptyState.remove();
        }
        return;
      }

      if (previewImg) {
        previewImg.remove();
      }

      if (!emptyState) {
        emptyState = document.createElement('span');
        emptyState.className = 'win-points-icon-upload-empty';
        emptyState.setAttribute('data-win-points-icon-empty', '1');
        emptyState.textContent = 'Sin icono';
        iconStage.appendChild(emptyState);
      }
    }

    function hexToRgba(hexColor, alpha) {
      const value = (hexColor || '').trim();
      if (!/^#([0-9a-f]{6})$/i.test(value)) {
        return 'rgba(0, 0, 0, ' + alpha + ')';
      }

      const red = parseInt(value.slice(1, 3), 16);
      const green = parseInt(value.slice(3, 5), 16);
      const blue = parseInt(value.slice(5, 7), 16);
      return 'rgba(' + red + ', ' + green + ', ' + blue + ', ' + alpha + ')';
    }

    function syncBadgePreview() {
      if (!badgePreview) {
        return;
      }

      const backgroundColor = badgeBackgroundInput ? badgeBackgroundInput.value : '#3E2D07';
      const textColor = badgeTextInput ? badgeTextInput.value : '#FCD34D';
      const programName = programNameInput ? programNameInput.value.trim() : '';

      badgePreview.style.setProperty('--wp-badge-bg', backgroundColor || '#3E2D07');
      badgePreview.style.setProperty('--wp-badge-text', textColor || '#FCD34D');
      badgePreview.style.setProperty('--wp-badge-border', hexToRgba(textColor || '#FCD34D', 0.28));
      badgePreview.style.setProperty('--wp-badge-shadow', hexToRgba(textColor || '#FCD34D', 0.08));

      if (badgeLabel) {
        badgeLabel.textContent = '+3 ' + (programName || 'Win Points');
      }
    }

    function syncSelectedPackageReward() {
      if (!packageSelect || !rewardInput) {
        return;
      }

      const selectedOption = packageSelect.options[packageSelect.selectedIndex];
      rewardInput.value = selectedOption ? (selectedOption.dataset.currentReward || '0') : '0';
    }

    function initUserSelectFilter() {
      if (!adjustmentUserSearch || !adjustmentUserSelect) {
        return;
      }

      const baseOptions = Array.from(adjustmentUserSelect.options).map(function (option) {
        return {
          value: option.value,
          text: option.textContent || '',
          search: (option.dataset.search || option.textContent || '').toLowerCase(),
        };
      });

      function renderUserOptions() {
        const term = adjustmentUserSearch.value.trim().toLowerCase();
        const currentValue = adjustmentUserSelect.value;
        const placeholder = baseOptions[0] || { value: '', text: 'Selecciona un usuario', search: '' };
        const matches = baseOptions.slice(1).filter(function (option) {
          return term === '' || option.search.indexOf(term) !== -1;
        });

        adjustmentUserSelect.innerHTML = '';

        const placeholderOption = document.createElement('option');
        placeholderOption.value = placeholder.value;
        placeholderOption.textContent = matches.length ? placeholder.text : 'No se encontraron usuarios';
        adjustmentUserSelect.appendChild(placeholderOption);

        matches.forEach(function (optionData) {
          const option = document.createElement('option');
          option.value = optionData.value;
          option.textContent = optionData.text;
          option.dataset.search = optionData.search;
          adjustmentUserSelect.appendChild(option);
        });

        if (matches.some(function (option) { return option.value === currentValue; })) {
          adjustmentUserSelect.value = currentValue;
        } else {
          adjustmentUserSelect.value = '';
        }
      }

      adjustmentUserSearch.addEventListener('input', renderUserOptions);
      renderUserOptions();
    }

    function initFilterableSection(sectionName) {
      const section = document.querySelector('[data-wp-section="' + sectionName + '"]');
      if (!section) {
        return;
      }

      const searchInput = section.querySelector('[data-wp-filter-search]');
      const sizeSelect = section.querySelector('[data-wp-filter-size]');
      const extraSelect = section.querySelector('[data-wp-filter-extra]');
      const countBadge = section.querySelector('[data-wp-filter-count]');
      const paginationWrap = section.querySelector('[data-wp-pagination]');
      const paginationInfo = section.querySelector('[data-wp-pagination-info]');
      const prevButton = section.querySelector('[data-wp-page-prev]');
      const nextButton = section.querySelector('[data-wp-page-next]');
      const itemNodes = Array.from(section.querySelectorAll('[data-wp-item="' + sectionName + '"]'));

      if (!itemNodes.length) {
        return;
      }

      const keyedItems = new Map();
      itemNodes.forEach(function (node, index) {
        const key = node.dataset.wpKey || (sectionName + '-' + index);
        if (!keyedItems.has(key)) {
          keyedItems.set(key, {
            key: key,
            elements: [],
            filterText: (node.dataset.wpFilter || '').toLowerCase(),
            extra: node.dataset.wpExtra || 'all'
          });
        }

        const entry = keyedItems.get(key);
        entry.elements.push(node);
        if (!entry.filterText && node.dataset.wpFilter) {
          entry.filterText = node.dataset.wpFilter.toLowerCase();
        }
        if ((!entry.extra || entry.extra === 'all') && node.dataset.wpExtra) {
          entry.extra = node.dataset.wpExtra;
        }
      });

      const entries = Array.from(keyedItems.values());
      let currentPage = 1;

      function renderCount(total) {
        if (!countBadge) {
          return;
        }

        const singular = countBadge.dataset.wpCountSingular || 'resultado visible';
        const plural = countBadge.dataset.wpCountPlural || 'resultados visibles';
        countBadge.textContent = total === 1 ? '1 ' + singular : total + ' ' + plural;
      }

      function applyFilters(resetPage) {
        if (resetPage) {
          currentPage = 1;
        }

        const searchTerm = (searchInput ? searchInput.value : '').trim().toLowerCase();
        const extraValue = extraSelect ? extraSelect.value : 'all';
        const pageSize = Math.max(1, parseInt(sizeSelect ? sizeSelect.value : '5', 10) || 5);

        const filteredEntries = entries.filter(function (entry) {
          const matchesSearch = searchTerm === '' || entry.filterText.indexOf(searchTerm) !== -1;
          const matchesExtra = extraValue === 'all' || entry.extra === extraValue;
          return matchesSearch && matchesExtra;
        });

        const total = filteredEntries.length;
        const totalPages = Math.max(1, Math.ceil(total / pageSize));
        if (currentPage > totalPages) {
          currentPage = totalPages;
        }

        const startIndex = total === 0 ? 0 : (currentPage - 1) * pageSize;
        const endIndex = total === 0 ? 0 : Math.min(startIndex + pageSize, total);
        const visibleKeys = new Set(filteredEntries.slice(startIndex, endIndex).map(function (entry) {
          return entry.key;
        }));

        entries.forEach(function (entry) {
          const isVisible = visibleKeys.has(entry.key);
          entry.elements.forEach(function (element) {
            element.classList.toggle('win-points-hidden', !isVisible);
          });
        });

        renderCount(total);

        if (paginationInfo) {
          paginationInfo.textContent = total === 0
            ? 'Sin resultados para los filtros actuales.'
            : 'Mostrando ' + (startIndex + 1) + '-' + endIndex + ' de ' + total + '.';
        }

        if (prevButton) {
          prevButton.disabled = total === 0 || currentPage <= 1;
        }
        if (nextButton) {
          nextButton.disabled = total === 0 || currentPage >= totalPages;
        }
        if (paginationWrap) {
          paginationWrap.classList.toggle('win-points-hidden', total === 0);
        }
      }

      if (searchInput) {
        searchInput.addEventListener('input', function () {
          applyFilters(true);
        });
      }

      if (sizeSelect) {
        sizeSelect.addEventListener('change', function () {
          applyFilters(true);
        });
      }

      if (extraSelect) {
        extraSelect.addEventListener('change', function () {
          applyFilters(true);
        });
      }

      if (prevButton) {
        prevButton.addEventListener('click', function () {
          if (currentPage <= 1) {
            return;
          }
          currentPage -= 1;
          applyFilters(false);
        });
      }

      if (nextButton) {
        nextButton.addEventListener('click', function () {
          currentPage += 1;
          applyFilters(false);
        });
      }

      applyFilters(true);
    }

    if (iconInput && iconStage) {
      const defaultSrc = iconStage.dataset.defaultSrc || '';

      iconInput.addEventListener('change', function () {
        const file = iconInput.files && iconInput.files[0] ? iconInput.files[0] : null;
        if (!file) {
          renderIconPreview(iconRemove && iconRemove.checked ? '' : defaultSrc);
          return;
        }

        if (iconRemove) {
          iconRemove.checked = false;
        }

        const reader = new FileReader();
        reader.onload = function (event) {
          renderIconPreview(typeof event.target?.result === 'string' ? event.target.result : '');
        };
        reader.readAsDataURL(file);
      });

      if (iconRemove) {
        iconRemove.addEventListener('change', function () {
          if (iconRemove.checked) {
            renderIconPreview('');
            return;
          }

          if (!iconInput.files || !iconInput.files[0]) {
            renderIconPreview(defaultSrc);
          }
        });
      }
    }

    if (packageSelect && rewardInput) {
      syncSelectedPackageReward();
      packageSelect.addEventListener('change', syncSelectedPackageReward);
    }

    if (badgeBackgroundInput) {
      badgeBackgroundInput.addEventListener('input', syncBadgePreview);
      badgeBackgroundInput.addEventListener('change', syncBadgePreview);
    }

    if (badgeTextInput) {
      badgeTextInput.addEventListener('input', syncBadgePreview);
      badgeTextInput.addEventListener('change', syncBadgePreview);
    }

    if (programNameInput) {
      programNameInput.addEventListener('input', syncBadgePreview);
    }

    if (tabs.length && tabPanels.length) {
      tabs.forEach(function (tabButton) {
        tabButton.addEventListener('click', function () {
          activateTab(tabButton.dataset.winPointsTab || 'overview');
        });
      });
    }

    initUserSelectFilter();
    initFilterableSection('rules');
    initFilterableSection('wallets');
    initFilterableSection('ledger');
    syncBadgePreview();
  })();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>