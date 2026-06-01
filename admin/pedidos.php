<?php
require_once __DIR__ . '/../includes/tenant.php';
tenant_start_session();
$adminRole = trim((string) ($_SESSION['auth_user']['rol'] ?? ''));
if (!isset($_SESSION['auth_user']) || !in_array($adminRole, ['admin', 'empleado', 'root'], true)) {
  header('Location: ' . app_path('/login.php'));
    exit();
}

require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/recargas_api.php';
require_once __DIR__ . '/../includes/binance_pay.php';
require_once __DIR__ . '/../includes/api_discord.php';
require_once __DIR__ . '/../includes/header.php';

$ordersApiUrl = app_path('/api/pedidos.php');

$statuses = ['pendiente','pagado','enviado','cancelado'];
$ordersPerPage = 20;
$ordersByStatus = array_fill_keys($statuses, []);
$statusTotals = array_fill_keys($statuses, 0);
$statusPages = array_fill_keys($statuses, 1);
$statusTotalPages = array_fill_keys($statuses, 1);

function format_money($amount): string {
    return number_format((float)$amount, 2, '.', ',');
}

function order_meta_value($value): string {
  $text = trim((string) $value);
  return $text !== '' ? $text : '—';
}

function order_preview_text_admin(?string $text, int $limit = 180): string {
  if (!is_string($text) || trim($text) === '') {
    return '';
  }

  $preview = preg_replace('/\s+/u', ' ', $text) ?? $text;
  $preview = trim($preview);
  if ($preview === '') {
    return '';
  }

  $wasTrimmed = function_exists('mb_strlen') ? mb_strlen($preview) > $limit : strlen($preview) > $limit;
  if (function_exists('mb_substr')) {
    $preview = mb_substr($preview, 0, $limit);
  } else {
    $preview = substr($preview, 0, $limit);
  }

  return $preview . ($wasTrimmed ? '...' : '');
}

function order_purchase_quantity_admin(array $order): int {
  $quantity = (int) ($order['cantidad_compra'] ?? 1);
  return $quantity > 0 ? $quantity : 1;
}

function order_purchase_quantity_label_admin(array $order): string {
  $quantity = order_purchase_quantity_admin($order);
  return $quantity === 1 ? '1 recarga' : $quantity . ' recargas';
}

function order_player_fields_from_json_admin(?string $json): array {
  if (!is_string($json) || trim($json) === '') {
    return [];
  }

  $decoded = json_decode($json, true);
  return is_array($decoded) ? $decoded : [];
}

function order_player_fields_lines(array $order): array {
  $primaryValue = trim((string) ($order['user_identifier'] ?? ''));
  $lines = [];

  foreach (order_player_fields_from_json_admin($order['player_fields_json'] ?? null) as $fieldName => $fieldValue) {
    $value = trim((string) $fieldValue);
    if ($value === '' || ($primaryValue !== '' && $value === $primaryValue)) {
      continue;
    }

    $lines[] = recargas_api_field_label((string) $fieldName) . ': ' . $value;
  }

  return $lines;
}

function order_is_account_sale_admin(array $order): bool {
  return (int) ($order['vender_cuenta'] ?? 0) === 1;
}

function order_account_sale_gallery_admin(array $order): array {
  $decoded = json_decode((string) ($order['cuenta_galeria_json'] ?? ''), true);
  return is_array($decoded) ? array_values(array_filter($decoded, 'is_array')) : [];
}

function order_account_sale_detail_lines(array $order): array {
  if (!order_is_account_sale_admin($order)) {
    return [];
  }

  $lines = ['Modo de entrega: Venta de cuenta'];
  $accountText = trim((string) ($order['cuenta_entrega_texto'] ?? ''));
  if ($accountText !== '') {
    $preview = preg_replace('/\s+/u', ' ', $accountText) ?? $accountText;
    $preview = trim($preview);
    if (function_exists('mb_substr')) {
      $preview = mb_substr($preview, 0, 180);
    } else {
      $preview = substr($preview, 0, 180);
    }
    if ($preview !== '') {
      $lines[] = 'Datos de cuenta: ' . $preview . (strlen($preview) >= 180 ? '...' : '');
    }
  }

  $galleryCount = count(order_account_sale_gallery_admin($order));
  if ($galleryCount > 0) {
    $lines[] = 'Galería registrada: ' . $galleryCount . ' imagen(es)';
  }

  return $lines;
}

function order_retry_button_label(array $order): string {
  if (trim((string) ($order['api_discord_command_key'] ?? '')) !== '') {
    return 'Enviar Discord';
  }

  return order_is_account_sale_admin($order) ? 'Enviar cuenta' : 'Enviar recarga';
}

function order_retry_confirm_message(array $order): string {
  if (trim((string) ($order['api_discord_command_key'] ?? '')) !== '') {
    return 'Se enviará el comando configurado de Discord para este pedido verificado. Usa esta acción solo cuando necesites disparar o repetir la prueba real por webhook.';
  }

  return order_is_account_sale_admin($order)
    ? 'Se enviará nuevamente la cuenta para este pedido. Usa esta acción solo si necesitas reenviar la entrega al cliente.'
    : 'Se enviara nuevamente la recarga para este pedido verificado. Usa esta accion solo si la recarga anterior no se proceso realmente.';
}

function order_provider_status_label(array $order): string {
  $providerOrderId = trim((string) ($order['recargas_api_pedido_id'] ?? ''));
  if ($providerOrderId === '') {
    return '';
  }

  $providerStatus = trim((string) ($order['recargas_api_estado'] ?? ''));
  return 'Proveedor: ' . ($providerStatus !== '' ? $providerStatus : 'sin estado') . ' | ID externo: ' . $providerOrderId;
}

function order_provider_detail_lines(array $order): array {
  $lines = [];

  $providerMessage = trim((string) ($order['ff_api_mensaje'] ?? ''));
  if ($providerMessage !== '') {
    $lines[] = 'Respuesta API: ' . $providerMessage;
  }

  $providerCode = trim((string) ($order['recargas_api_codigo_entregado'] ?? ''));
  if ($providerCode !== '') {
    $lines[] = 'Código entregado: ' . $providerCode;
  }

  $refundAmount = isset($order['recargas_api_reembolso']) ? (float) $order['recargas_api_reembolso'] : 0.0;
  if ($refundAmount > 0) {
    $lines[] = 'Reembolso API: ' . format_money($refundAmount);
  }

  return $lines;
}

function order_provider_history_lines(array $order, int $limit = 3): array {
  $json = $order['recargas_api_historial_json'] ?? null;
  if (!is_string($json) || trim($json) === '') {
    return [];
  }

  $decoded = json_decode($json, true);
  if (!is_array($decoded)) {
    return [];
  }

  $entries = array_slice(array_values(array_filter($decoded, 'is_array')), -$limit);
  $lines = [];

  foreach ($entries as $entry) {
    $parts = [];
    $recordedAt = trim((string) ($entry['recorded_at'] ?? ''));
    $source = trim((string) ($entry['source'] ?? ''));
    $providerStatus = trim((string) ($entry['provider_status'] ?? ''));
    $localStatus = trim((string) ($entry['local_status'] ?? ''));
    $providerMessage = trim((string) ($entry['provider_message'] ?? ''));

    if ($recordedAt !== '') {
      $parts[] = $recordedAt;
    }
    if ($source !== '') {
      $parts[] = $source;
    }
    if ($providerStatus !== '') {
      $parts[] = 'API: ' . $providerStatus;
    }
    if ($localStatus !== '') {
      $parts[] = 'Local: ' . $localStatus;
    }
    if ($providerMessage !== '') {
      $parts[] = $providerMessage;
    }

    if ($parts) {
      $lines[] = 'Historial: ' . implode(' | ', $parts);
    }
  }

  return $lines;
}

function order_has_binance_tracking(array $order): bool {
  return trim((string) ($order['binance_pay_reference'] ?? '')) !== ''
    || trim((string) ($order['binance_pay_order_no'] ?? '')) !== ''
    || trim((string) ($order['binance_pay_request_id'] ?? '')) !== '';
}

function order_binance_reference(array $order): string {
  $reference = trim((string) ($order['binance_pay_reference'] ?? ''));
  if ($reference !== '') {
    return $reference;
  }

  $orderNo = trim((string) ($order['binance_pay_order_no'] ?? ''));
  if ($orderNo !== '') {
    return $orderNo;
  }

  return trim((string) ($order['binance_pay_request_id'] ?? ''));
}

function order_binance_status_label(array $order): string {
  if (!order_has_binance_tracking($order)) {
    return '';
  }

  $status = trim((string) ($order['binance_pay_status'] ?? ''));
  $reference = order_binance_reference($order);
  $parts = ['Binance Pay: ' . ($status !== '' ? $status : 'sin estado')];

  if ($reference !== '') {
    $parts[] = 'Ref: ' . $reference;
  }

  return implode(' | ', $parts);
}

function order_binance_detail_lines(array $order): array {
  if (!order_has_binance_tracking($order)) {
    return [];
  }

  $lines = [];
  $message = trim((string) ($order['binance_pay_message'] ?? ''));
  if ($message !== '') {
    $lines[] = 'Respuesta Binance: ' . $message;
  }

  $paidAmount = isset($order['binance_pay_paid_amount']) ? (float) $order['binance_pay_paid_amount'] : 0.0;
  $paidCurrency = trim((string) ($order['binance_pay_paid_currency'] ?? ''));
  if ($paidAmount > 0 || $paidCurrency !== '') {
    $amountLabel = format_money($paidAmount);
    if ($paidCurrency !== '') {
      $amountLabel .= ' ' . $paidCurrency;
    }
    $lines[] = 'Monto confirmado: ' . trim($amountLabel);
  }

  $checkedAt = trim((string) ($order['binance_pay_ultimo_check'] ?? ''));
  if ($checkedAt !== '') {
    $lines[] = 'Ultimo check Binance: ' . $checkedAt;
  }

  $checkoutUrl = binance_pay_normalize_checkout_url((string) ($order['binance_pay_checkout_url'] ?? ''));
  if ($checkoutUrl !== '') {
    $lines[] = 'Checkout Binance activo';
  }

  return $lines;
}

function order_binance_history_lines(array $order, int $limit = 3): array {
  $json = $order['binance_pay_historial_json'] ?? null;
  if (!is_string($json) || trim($json) === '') {
    return [];
  }

  $decoded = json_decode($json, true);
  if (!is_array($decoded)) {
    return [];
  }

  $entries = array_slice(array_values(array_filter($decoded, 'is_array')), -$limit);
  $lines = [];

  foreach ($entries as $entry) {
    $parts = [];
    $recordedAt = trim((string) ($entry['recorded_at'] ?? ''));
    $source = trim((string) ($entry['source'] ?? ''));
    $providerStatus = trim((string) ($entry['provider_status'] ?? ''));
    $localStatus = trim((string) ($entry['local_status'] ?? ''));
    $providerMessage = trim((string) ($entry['provider_message'] ?? ''));

    if ($recordedAt !== '') {
      $parts[] = $recordedAt;
    }
    if ($source !== '') {
      $parts[] = $source;
    }
    if ($providerStatus !== '') {
      $parts[] = 'Binance: ' . $providerStatus;
    }
    if ($localStatus !== '') {
      $parts[] = 'Local: ' . $localStatus;
    }
    if ($providerMessage !== '') {
      $parts[] = $providerMessage;
    }

    if ($parts) {
      $lines[] = 'Historial Binance: ' . implode(' | ', $parts);
    }
  }

  return $lines;
}

function order_has_api_discord_tracking(array $order): bool {
  return trim((string) ($order['api_discord_command_key'] ?? '')) !== ''
    || trim((string) ($order['api_discord_status'] ?? '')) !== ''
    || trim((string) ($order['api_discord_message_id'] ?? '')) !== ''
    || (int) ($order['api_discord_attempts'] ?? 0) > 0
    || (int) ($order['api_discord_requires_review'] ?? 0) === 1;
}

function order_discord_command_label(array $order): string {
  $commandKey = trim((string) ($order['api_discord_command_key'] ?? ''));
  if ($commandKey === '') {
    return '';
  }

  $definition = api_discord_find_command($commandKey);
  $label = trim((string) ($definition['label'] ?? ''));
  return $label !== '' ? $label : $commandKey;
}

function order_discord_status_label(array $order): string {
  if (!order_has_api_discord_tracking($order)) {
    return '';
  }

  $status = trim((string) ($order['api_discord_status'] ?? ''));
  $parts = ['Discord: ' . ($status !== '' ? $status : 'sin estado')];
  $commandLabel = order_discord_command_label($order);
  if ($commandLabel !== '') {
    $parts[] = 'Comando: ' . $commandLabel;
  }

  return implode(' | ', $parts);
}

function order_discord_detail_lines(array $order): array {
  if (!order_has_api_discord_tracking($order)) {
    return [];
  }

  $lines = [];
  $commandKey = trim((string) ($order['api_discord_command_key'] ?? ''));
  if ($commandKey !== '') {
    $lines[] = 'Clave Discord: ' . $commandKey;
  }

  $templatePreview = order_preview_text_admin($order['api_discord_command_template'] ?? null, 140);
  if ($templatePreview !== '') {
    $lines[] = 'Template Discord: ' . $templatePreview;
  }

  $attempts = max(0, (int) ($order['api_discord_attempts'] ?? 0));
  $httpStatus = (int) ($order['api_discord_http_status'] ?? 0);
  if ($attempts > 0 || $httpStatus > 0) {
    $attemptLabel = 'Intentos Discord: ' . $attempts;
    if ($httpStatus > 0) {
      $attemptLabel .= ' | HTTP: ' . $httpStatus;
    }
    $lines[] = $attemptLabel;
  }

  $messageId = trim((string) ($order['api_discord_message_id'] ?? ''));
  if ($messageId !== '') {
    $lines[] = 'Message ID Discord: ' . $messageId;
  }

  $sentAt = trim((string) ($order['api_discord_sent_at'] ?? ''));
  if ($sentAt !== '') {
    $lines[] = 'Enviado a Discord: ' . $sentAt;
  }

  $lastAttemptAt = trim((string) ($order['api_discord_last_attempt_at'] ?? ''));
  if ($lastAttemptAt !== '') {
    $lines[] = 'Ultimo intento Discord: ' . $lastAttemptAt;
  }

  if ((int) ($order['api_discord_requires_review'] ?? 0) === 1) {
    $lines[] = 'Discord requiere revision manual';
  }

  $responsePreview = order_preview_text_admin($order['api_discord_response_body'] ?? null, 200);
  if ($responsePreview !== '') {
    $lines[] = 'Respuesta Discord: ' . $responsePreview;
  }

  return $lines;
}

function order_can_sync_gateway(array $order): bool {
  if (($order['estado'] ?? '') === 'enviado') {
    return false;
  }

  if (trim((string) ($order['recargas_api_pedido_id'] ?? '')) !== '') {
    return true;
  }

  return binance_pay_is_enabled() && order_has_binance_tracking($order);
}

function order_sync_button_label(array $order): string {
  if (order_has_binance_tracking($order) && trim((string) ($order['recargas_api_pedido_id'] ?? '')) === '') {
    return 'Sincronizar Binance';
  }

  return 'Sincronizar API';
}

function order_can_retry_recharge(array $order): bool {
  $status = (string) ($order['estado'] ?? '');
  if (order_is_account_sale_admin($order)) {
    return in_array($status, ['pagado', 'enviado'], true);
  }

  if ($status !== 'pagado') {
    return false;
  }

  if (trim((string) ($order['api_discord_command_key'] ?? '')) !== '') {
    $discordStatus = trim((string) ($order['api_discord_status'] ?? ''));
    return !in_array($discordStatus, ['sent', 'processing', 'confirmed'], true);
  }

  $providerOrderId = trim((string) ($order['recargas_api_pedido_id'] ?? ''));
  if ($providerOrderId !== '') {
    return false;
  }

  $packageApiId = (int) ($order['paquete_api'] ?? 0);
  $legacyMonto = trim((string) ($order['monto_ff'] ?? ''));

  return $packageApiId > 0 || $legacyMonto !== '';
}

function order_can_manage_discord_status(array $order): bool {
  if (trim((string) ($order['api_discord_command_key'] ?? '')) === '') {
    return false;
  }

  $status = trim((string) ($order['estado'] ?? ''));
  return in_array($status, ['pagado', 'enviado', 'cancelado'], true);
}

function order_discord_admin_status_options(): array {
  return [
    'queued' => 'Discord en cola',
    'sent' => 'Discord aceptado',
    'processing' => 'Discord en proceso',
    'confirmed' => 'Discord confirmado',
    'review' => 'Discord en revisión',
    'failed' => 'Discord fallido',
    'cancelled' => 'Discord cancelado',
  ];
}

function order_normalize_date_query($value): string {
  $date = trim((string) $value);
  return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? $date : '';
}

function order_normalize_search_query($value): string {
  $search = trim((string) $value);
  if (preg_match('/^#(\d+)$/', $search, $matches) === 1) {
    return $matches[1];
  }

  return $search;
}

function orders_admin_page_param(string $status): string {
  return 'page_' . $status;
}

function orders_admin_bind_params(mysqli_stmt $stmt, string $types, array $params): void {
  if ($types === '' || $params === []) {
    return;
  }

  $bindParams = [$types];
  foreach ($params as $index => $value) {
    $bindParams[] = &$params[$index];
  }

  call_user_func_array([$stmt, 'bind_param'], $bindParams);
}

function orders_admin_fetch_all(mysqli $mysqli, string $sql, string $types = '', array $params = []): array {
  $stmt = $mysqli->prepare($sql);
  if (!$stmt) {
    return [];
  }

  orders_admin_bind_params($stmt, $types, $params);
  $stmt->execute();
  $result = $stmt->get_result();
  $rows = $result instanceof mysqli_result ? $result->fetch_all(MYSQLI_ASSOC) : [];
  $stmt->close();

  return is_array($rows) ? $rows : [];
}

function orders_admin_fetch_one(mysqli $mysqli, string $sql, string $types = '', array $params = []): ?array {
  $rows = orders_admin_fetch_all($mysqli, $sql, $types, $params);
  return $rows[0] ?? null;
}

function orders_admin_fetch_value(mysqli $mysqli, string $sql, string $types = '', array $params = [], string $column = 'total'): int {
  $row = orders_admin_fetch_one($mysqli, $sql, $types, $params);
  return (int) ($row[$column] ?? 0);
}

function orders_admin_filter_parts(?string $status, string $search, string $dateFrom, string $dateTo): array {
  $clauses = [];
  $types = '';
  $params = [];

  if ($status !== null && $status !== '') {
    $clauses[] = 'estado = ?';
    $types .= 's';
    $params[] = $status;
  }

  if ($dateFrom !== '') {
    $clauses[] = 'DATE(creado_en) >= ?';
    $types .= 's';
    $params[] = $dateFrom;
  }

  if ($dateTo !== '') {
    $clauses[] = 'DATE(creado_en) <= ?';
    $types .= 's';
    $params[] = $dateTo;
  }

  $normalizedSearch = order_normalize_search_query($search);
  if ($normalizedSearch !== '') {
    $searchClauses = [];
    $searchTypes = '';
    $searchParams = [];

    if (ctype_digit($normalizedSearch)) {
      $searchClauses[] = 'id = ?';
      $searchTypes .= 'i';
      $searchParams[] = (int) $normalizedSearch;
    }

    $searchLike = '%' . $normalizedSearch . '%';
    $searchExpressions = [
      'CAST(id AS CHAR)',
      'user_identifier',
      'email',
      'numero_referencia',
      'telefono_contacto',
      'juego_nombre',
      'paquete_nombre',
      'paquete_cantidad',
      'moneda',
      'cupon',
      'estado',
      'CAST(precio AS CHAR)',
      'binance_pay_reference',
      'binance_pay_order_no',
      'binance_pay_request_id',
      'binance_pay_status',
      'api_discord_command_key',
      'api_discord_command_template',
      'api_discord_status',
      'api_discord_message_id',
      'api_discord_response_body',
      'recargas_api_pedido_id',
      'recargas_api_estado',
      'ff_api_mensaje',
      'recargas_api_codigo_entregado',
      'player_fields_json',
      'recargas_api_historial_json',
      'binance_pay_historial_json',
    ];

    foreach ($searchExpressions as $expression) {
      $searchClauses[] = $expression . ' LIKE ?';
      $searchTypes .= 's';
      $searchParams[] = $searchLike;
    }

    $clauses[] = '(' . implode(' OR ', $searchClauses) . ')';
    $types .= $searchTypes;
    $params = array_merge($params, $searchParams);
  }

  return [
    'clauses' => $clauses,
    'types' => $types,
    'params' => $params,
  ];
}

function orders_admin_where_sql(array $clauses): string {
  return $clauses ? ' WHERE ' . implode(' AND ', $clauses) : '';
}

function orders_admin_query_string(array $overrides = [], array $remove = []): string {
  $params = $_GET;

  foreach ($remove as $key) {
    unset($params[$key]);
  }

  foreach ($overrides as $key => $value) {
    if ($value === null || $value === '') {
      unset($params[$key]);
      continue;
    }

    $params[$key] = (string) $value;
  }

  $query = http_build_query($params);
  return $query !== '' ? '?' . $query : '';
}

function orders_admin_summary_text(int $rendered, int $total, int $page, int $totalPages): string {
  if ($total <= 0) {
    return 'Total: 0 pedidos';
  }

  $summary = 'Mostrando ' . $rendered . ' de ' . $total . ' pedidos';
  if ($totalPages > 1) {
    $summary .= ' | Página ' . $page . ' de ' . $totalPages;
  }

  return $summary;
}

function orders_admin_render_pagination(string $status, int $currentPage, int $totalPages): string {
  if ($totalPages <= 1) {
    return '';
  }

  $pageParam = orders_admin_page_param($status);
  $startPage = max(1, $currentPage - 2);
  $endPage = min($totalPages, $currentPage + 2);

  if (($endPage - $startPage) < 4) {
    $missing = 4 - ($endPage - $startPage);
    $startPage = max(1, $startPage - $missing);
    $endPage = min($totalPages, $endPage + $missing);
  }

  $html = '<nav class="orders-pagination" aria-label="Paginación ' . htmlspecialchars(order_status_label($status), ENT_QUOTES, 'UTF-8') . '">';
  $html .= '<div class="orders-pagination-links">';

  if ($currentPage > 1) {
    $prevUrl = app_path('/admin/pedidos') . orders_admin_query_string([
      'tab' => $status,
      $pageParam => $currentPage - 1,
    ]);
    $html .= '<a class="orders-pagination-link" href="' . htmlspecialchars($prevUrl, ENT_QUOTES, 'UTF-8') . '">Anterior</a>';
  }

  for ($page = $startPage; $page <= $endPage; $page++) {
    if ($page === $currentPage) {
      $html .= '<span class="orders-pagination-link is-active">' . $page . '</span>';
      continue;
    }

    $pageUrl = app_path('/admin/pedidos') . orders_admin_query_string([
      'tab' => $status,
      $pageParam => $page,
    ]);
    $html .= '<a class="orders-pagination-link" href="' . htmlspecialchars($pageUrl, ENT_QUOTES, 'UTF-8') . '">' . $page . '</a>';
  }

  if ($currentPage < $totalPages) {
    $nextUrl = app_path('/admin/pedidos') . orders_admin_query_string([
      'tab' => $status,
      $pageParam => $currentPage + 1,
    ]);
    $html .= '<a class="orders-pagination-link" href="' . htmlspecialchars($nextUrl, ENT_QUOTES, 'UTF-8') . '">Siguiente</a>';
  }

  $html .= '</div>';
  $html .= '</nav>';

  return $html;
}

function order_search_index(array $order): string {
  $playerFieldLines = order_player_fields_lines($order);
  $accountSaleDetailLines = order_account_sale_detail_lines($order);
  $discordDetailLines = order_discord_detail_lines($order);
  $binanceDetailLines = order_binance_detail_lines($order);
  $binanceHistoryLines = order_binance_history_lines($order);
  $parts = [
    '#' . ($order['id'] ?? ''),
    $order['creado_en'] ?? '',
    $order['user_identifier'] ?? '',
    $order['email'] ?? '',
    $order['numero_referencia'] ?? '',
    $order['binance_pay_reference'] ?? '',
    $order['binance_pay_order_no'] ?? '',
    $order['binance_pay_request_id'] ?? '',
    $order['binance_pay_status'] ?? '',
    $order['telefono_contacto'] ?? '',
    $order['juego_nombre'] ?? '',
    $order['paquete_nombre'] ?? '',
    $order['paquete_cantidad'] ?? '',
    order_purchase_quantity_label_admin($order),
    $order['moneda'] ?? '',
    $order['precio'] ?? '',
    $order['cupon'] ?? '',
    $order['estado'] ?? '',
    implode(' ', $playerFieldLines),
    implode(' ', $accountSaleDetailLines),
    order_discord_status_label($order),
    implode(' ', $discordDetailLines),
    implode(' ', order_provider_detail_lines($order)),
    implode(' ', order_provider_history_lines($order)),
    implode(' ', $binanceDetailLines),
    implode(' ', $binanceHistoryLines),
  ];

  return strtolower(trim(implode(' ', array_map(static fn ($value) => trim((string) $value), $parts))));
}

function order_status_color(string $status): string {
  return match ($status) {
    'pendiente' => '#ffc107',
    'pagado' => '#00ffb3',
    'enviado' => '#2196f3',
    'cancelado' => '#ff0059',
    default => '#00fff7',
  };
}

function order_status_label(string $status): string {
  return match ($status) {
    'pendiente' => 'No Verificado',
    'pagado' => 'Verificado',
    'enviado' => 'Enviado',
    'cancelado' => 'Cancelado',
    default => ucfirst($status),
  };
}

function order_status_button_style(string $status, bool $isActive = false): string {
  $color = order_status_color($status);
  $background = $isActive ? $color : ($status === 'pendiente' ? 'rgba(255, 193, 7, 0.08)' : ($status === 'pagado' ? 'rgba(0, 255, 179, 0.08)' : ($status === 'enviado' ? 'rgba(33, 150, 243, 0.08)' : 'rgba(255, 0, 89, 0.08)')));
  $textColor = $isActive
    ? ($status === 'pendiente' ? '#181f2a' : '#ffffff')
    : $color;
  $shadow = $isActive ? '0 0 12px ' . $color . '66' : 'none';

  return 'border:1px solid ' . $color . '; background:' . $background . '; color:' . $textColor . '; box-shadow:' . $shadow . ';';
}

$requestedOrderId = max(0, (int) ($_GET['pedido'] ?? 0));
$initialOrderSearch = order_normalize_search_query($_GET['order_search'] ?? '');
if ($requestedOrderId > 0 && $initialOrderSearch === '') {
  $initialOrderSearch = (string) $requestedOrderId;
}
$initialDateFrom = order_normalize_date_query($_GET['date_from'] ?? '');
$initialDateTo = order_normalize_date_query($_GET['date_to'] ?? '');
$countFilterParts = orders_admin_filter_parts(null, $initialOrderSearch, $initialDateFrom, $initialDateTo);
$countSql = 'SELECT estado, COUNT(*) AS total FROM pedidos' . orders_admin_where_sql($countFilterParts['clauses']) . ' GROUP BY estado';
foreach (orders_admin_fetch_all($mysqli, $countSql, $countFilterParts['types'], $countFilterParts['params']) as $statusRow) {
  $statusKey = (string) ($statusRow['estado'] ?? '');
  if (isset($statusTotals[$statusKey])) {
    $statusTotals[$statusKey] = (int) ($statusRow['total'] ?? 0);
  }
}

$targetOrder = null;
if ($requestedOrderId > 0) {
  $targetOrder = orders_admin_fetch_one(
    $mysqli,
    'SELECT id, estado, creado_en FROM pedidos WHERE id = ? LIMIT 1',
    'i',
    [$requestedOrderId]
  );
}

$initialTab = trim((string) ($_GET['tab'] ?? ''));
if (!in_array($initialTab, $statuses, true)) {
  $initialTab = '';
}
if ($targetOrder && $initialTab === '') {
  $targetStatus = trim((string) ($targetOrder['estado'] ?? ''));
  if (in_array($targetStatus, $statuses, true)) {
    $initialTab = $targetStatus;
  }
}
if ($initialTab === '') {
  foreach ($statuses as $statusKey) {
    if (($statusTotals[$statusKey] ?? 0) > 0) {
      $initialTab = $statusKey;
      break;
    }
  }
}
if ($initialTab === '') {
  $initialTab = 'pendiente';
}

foreach ($statuses as $statusKey) {
  $pageParam = orders_admin_page_param($statusKey);
  $statusPages[$statusKey] = max(1, (int) ($_GET[$pageParam] ?? 1));
}

if ($targetOrder) {
  $targetStatus = trim((string) ($targetOrder['estado'] ?? ''));
  if (in_array($targetStatus, $statuses, true)) {
    $targetPageParam = orders_admin_page_param($targetStatus);
    if (!isset($_GET[$targetPageParam])) {
      $positionFilterParts = orders_admin_filter_parts($targetStatus, $initialOrderSearch, $initialDateFrom, $initialDateTo);
      $positionFilterParts['clauses'][] = '(creado_en > ? OR (creado_en = ? AND id >= ?))';
      $positionFilterParts['types'] .= 'ssi';
      $positionFilterParts['params'][] = (string) ($targetOrder['creado_en'] ?? '');
      $positionFilterParts['params'][] = (string) ($targetOrder['creado_en'] ?? '');
      $positionFilterParts['params'][] = (int) ($targetOrder['id'] ?? 0);

      $positionSql = 'SELECT COUNT(*) AS total FROM pedidos' . orders_admin_where_sql($positionFilterParts['clauses']);
      $position = orders_admin_fetch_value($mysqli, $positionSql, $positionFilterParts['types'], $positionFilterParts['params']);
      if ($position > 0) {
        $statusPages[$targetStatus] = max(1, (int) ceil($position / $ordersPerPage));
      }
    }
  }
}

foreach ($statuses as $statusKey) {
  $total = (int) ($statusTotals[$statusKey] ?? 0);
  $totalPages = $total > 0 ? (int) ceil($total / $ordersPerPage) : 1;
  $statusTotalPages[$statusKey] = max(1, $totalPages);
  $statusPages[$statusKey] = min(max(1, $statusPages[$statusKey]), $statusTotalPages[$statusKey]);

  if ($total === 0) {
    $ordersByStatus[$statusKey] = [];
    continue;
  }

  $offset = ($statusPages[$statusKey] - 1) * $ordersPerPage;
  $statusFilterParts = orders_admin_filter_parts($statusKey, $initialOrderSearch, $initialDateFrom, $initialDateTo);
  $statusSql = 'SELECT * FROM pedidos'
    . orders_admin_where_sql($statusFilterParts['clauses'])
    . ' ORDER BY creado_en DESC, id DESC LIMIT ? OFFSET ?';
  $statusTypes = $statusFilterParts['types'] . 'ii';
  $statusParams = array_merge($statusFilterParts['params'], [$ordersPerPage, $offset]);
  $ordersByStatus[$statusKey] = orders_admin_fetch_all($mysqli, $statusSql, $statusTypes, $statusParams);
}
?>
<main class="container-lg mt-5 mb-5 px-2">
  <style>
    .hidden { display: none !important; }
    .table { background: #181f2a !important; border-radius:12px !important; border:2px solid #00fff7 !important; box-shadow:0 0 24px #00fff733 !important; }
    .tab-btn.active, .tab-btn.border-cyan-400 {
      border:2px solid #00fff7 !important;
      color:#00fff7 !important;
      box-shadow:0 0 8px #00fff7;
      background:#181f2a !important;
    }
    .tab-panel { margin-top: 0.5rem !important; }
    .admin-loading-modal {
      display: none;
      position: fixed;
      inset: 0;
      z-index: 1080;
      align-items: center;
      justify-content: center;
      padding: 1rem;
      background: rgba(5, 10, 20, 0.78);
      backdrop-filter: blur(4px);
    }
    .admin-loading-modal.is-visible {
      display: flex;
    }
    .admin-loading-card {
      width: min(92vw, 25rem);
      border-radius: 18px;
      border: 2px solid #00fff7;
      background: linear-gradient(135deg, rgba(11, 17, 32, 0.97), rgba(24, 31, 42, 0.96));
      box-shadow: 0 0 24px rgba(0, 255, 247, 0.25);
      padding: 1.75rem 1.5rem;
      text-align: center;
      color: #b2f6ff;
    }
    .admin-loading-spinner {
      width: 3rem;
      height: 3rem;
      margin: 0 auto 1rem;
      border: 4px solid rgba(0, 255, 247, 0.22);
      border-top-color: #00fff7;
      border-radius: 50%;
      animation: adminSpin 0.85s linear infinite;
    }
    body.admin-loading-open {
      overflow: hidden;
    }
    @keyframes adminSpin {
      to { transform: rotate(360deg); }
    }
    .order-status-actions {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 0.6rem;
      margin-top: 1rem;
    }
    .order-status-btn {
      border-radius: 10px;
      font-weight: 700;
      padding: 0.55rem 0.75rem;
      transition: transform 0.18s ease, opacity 0.18s ease, box-shadow 0.18s ease;
    }
    .order-status-btn:disabled {
      opacity: 0.65;
      cursor: not-allowed;
    }
    .order-status-btn:not(:disabled):active {
      transform: scale(0.98);
    }
    .order-target-highlight {
      border-color: #00ffb3 !important;
      box-shadow: 0 0 0 2px rgba(0, 255, 179, 0.2), 0 0 24px rgba(0, 255, 179, 0.32) !important;
    }
    .orders-pagination {
      margin-top: 1.25rem;
      display: flex;
      justify-content: center;
    }
    .orders-pagination-links {
      display: flex;
      flex-wrap: wrap;
      gap: 0.5rem;
      justify-content: center;
    }
    .orders-pagination-link {
      min-width: 2.5rem;
      padding: 0.45rem 0.8rem;
      border-radius: 999px;
      border: 1px solid #00fff7;
      color: #00fff7;
      background: #181f2a;
      text-decoration: none;
      font-weight: 700;
      text-align: center;
      box-shadow: 0 0 8px rgba(0, 255, 247, 0.2);
    }
    .orders-pagination-link.is-active {
      color: #181f2a;
      background: #00fff7;
      box-shadow: 0 0 12px rgba(0, 255, 247, 0.45);
    }
  </style>
  <div class="row mb-4">
    <div class="col-12 text-center">
      <p class="text-uppercase text-info mb-1">Panel</p>
      <h1 class="display-5 fw-bold text-info mb-2">Gestión de Pedidos</h1>
      <p class="text-secondary">Administra estados y revisa el histórico de compras.</p>
    </div>
  </div>

  <div class="row mb-3" id="tabs">
    <div class="col-auto d-flex flex-wrap gap-2 justify-content-center" style="margin-bottom:0.5rem;">
      <?php foreach ($statuses as $st): ?>
        <button data-tab="<?= $st ?>" class="btn btn-outline-info rounded-pill px-4 py-2 fw-semibold tab-btn" type="button">
          <?= htmlspecialchars(order_status_label($st)) ?>
        </button>
      <?php endforeach; ?>
    </div>
    <div class="col-12 mt-3">
      <form id="date-filter-form" method="get" action="<?= htmlspecialchars(app_path('/admin/pedidos'), ENT_QUOTES, 'UTF-8') ?>" class="row g-2 align-items-center justify-content-center" style="margin-bottom:0.5rem;">
        <input type="hidden" id="current-tab-input" name="tab" value="<?= htmlspecialchars($initialTab, ENT_QUOTES, 'UTF-8') ?>">
        <div class="col-auto">
          <label class="form-label mb-0" style="color:#00fff7;">Desde:</label>
          <input type="date" id="date-from" name="date_from" class="form-control form-control-sm" style="background:#222c3a; color:#00fff7; border:1px solid #00fff7;">
        </div>
        <div class="col-auto">
          <label class="form-label mb-0" style="color:#00fff7;">Hasta:</label>
          <input type="date" id="date-to" name="date_to" class="form-control form-control-sm" style="background:#222c3a; color:#00fff7; border:1px solid #00fff7;">
        </div>
        <div class="col-auto">
          <label class="form-label mb-0" style="color:#00fff7;">Buscar pedido:</label>
          <input type="search" id="order-search" name="order_search" class="form-control form-control-sm" placeholder="ID, cliente, email, referencia..." style="min-width:260px; background:#222c3a; color:#00fff7; border:1px solid #00fff7;">
        </div>
        <div class="col-auto d-flex align-items-end gap-2">
          <button type="submit" class="btn btn-info btn-sm fw-bold" style="background:#00fff7; color:#222c3a; border:none; box-shadow:0 0 8px #00fff7;">Filtrar</button>
          <button type="button" id="clear-date-filter" class="btn btn-outline-info btn-sm fw-bold" style="border:1px solid #00fff7; color:#00fff7; background:#181f2a; box-shadow:0 0 8px #00fff7; display:flex; align-items:center; gap:4px;">
            <svg width="14" height="14" fill="none" viewBox="0 0 14 14"><circle cx="7" cy="7" r="6" stroke="#00fff7" stroke-width="1.2"/><path d="M4 4l6 6M10 4l-6 6" stroke="#00fff7" stroke-width="1.2" stroke-linecap="round"/></svg>
            <span style="color:#00fff7; font-weight:bold;">Limpiar</span>
          </button>
        </div>
      </form>
    </div>
  </div>

  <?php foreach ($statuses as $st): ?>
    <?php $list = $ordersByStatus[$st] ?? []; ?>
    <section
      data-panel="<?= $st ?>"
      data-total-count="<?= (int) ($statusTotals[$st] ?? 0) ?>"
      data-current-page="<?= (int) ($statusPages[$st] ?? 1) ?>"
      data-total-pages="<?= (int) ($statusTotalPages[$st] ?? 1) ?>"
      class="tab-panel mt-6<?= ($st !== ($initialTab ?? 'pendiente')) ? ' hidden' : '' ?>"
    >
      <div style="border-radius:16px; border:2px solid #00fff7; background:#181f2a; box-shadow:0 0 24px #00fff733; padding:1.5rem; margin-bottom:2rem;">
        <div style="display:flex; align-items:center; justify-content:space-between; gap:1rem;">
          <div style="display:flex; align-items:center; gap:0.75rem;">
            <span style="display:inline-block; height:10px; width:10px; border-radius:50%; background:<?= order_status_color($st) ?>;"></span>
            <h2 style="font-size:1.2em; font-weight:bold; color:#00fff7;">Estado: <?= htmlspecialchars(order_status_label($st)) ?></h2>
          </div>
          <p data-total-label style="font-size:1em; color:#b2f6ff;"><?= htmlspecialchars(orders_admin_summary_text(count($list), (int) ($statusTotals[$st] ?? 0), (int) ($statusPages[$st] ?? 1), (int) ($statusTotalPages[$st] ?? 1)), ENT_QUOTES, 'UTF-8') ?></p>
        </div>

        <?php if (($statusTotals[$st] ?? 0) === 0): ?>
          <p style="margin-top:1.5rem; color:#b2f6ff; font-size:1em;">No hay pedidos en este estado con los filtros actuales.</p>
        <?php else: ?>
          <div class="table-responsive d-none d-md-block" style="margin-top:1.5rem;">
            <table class="table align-middle" style="background:#181f2a; color:#00fff7; border-radius:12px; border:2px solid #00fff7; box-shadow:0 0 24px #00fff733;">
              <thead style="background:#181f2a; color:#00fff7; border-bottom:2px solid #00fff7;">
                <tr>
                  <th style="color:#00fff7; background:#181f2a; min-width:150px;">Pedido / Fecha</th>
                  <th style="color:#00fff7; background:#181f2a; min-width:240px;">Cliente / Email</th>
                  <th style="color:#00fff7; background:#181f2a;">Referencia</th>
                  <th style="color:#00fff7; background:#181f2a;">Teléfono</th>
                  <th style="color:#00fff7; background:#181f2a; min-width:210px;">Juego / Paquete</th>
                  <th style="color:#00fff7; background:#181f2a;">Total</th>
                  <th style="color:#00fff7; background:#181f2a;">Cupón</th>
                  <th style="color:#00fff7; background:#181f2a;">Estado</th>
                </tr>
              </thead>
              <tbody id="table-body-<?= $st ?>">
                <?php foreach ($list as $order): ?>
                  <?php $playerFieldLines = order_player_fields_lines($order); ?>
                  <?php $accountSaleDetailLines = order_account_sale_detail_lines($order); ?>
                  <?php $discordStatusLine = order_discord_status_label($order); ?>
                  <?php $discordDetailLines = order_discord_detail_lines($order); ?>
                  <?php $providerStatusLine = order_provider_status_label($order); ?>
                  <?php $providerDetailLines = order_provider_detail_lines($order); ?>
                  <?php $providerHistoryLines = order_provider_history_lines($order); ?>
                  <?php $binanceStatusLine = order_binance_status_label($order); ?>
                  <?php $binanceDetailLines = order_binance_detail_lines($order); ?>
                  <?php $binanceHistoryLines = order_binance_history_lines($order); ?>
                  <tr id="pedido-<?= $order['id'] ?>" data-order-row="<?= $order['id'] ?>" data-status="<?= $st ?>" data-created-date="<?= htmlspecialchars(substr((string) ($order['creado_en'] ?? ''), 0, 10)) ?>" data-search-text="<?= htmlspecialchars(order_search_index($order)) ?>" style="background:#181f2a; color:#fff;">
                    <td style="background:#181f2a; color:#00fff7;">
                      <div style="font-weight:bold;">#<?= $order['id'] ?></div>
                      <div style="color:#b2f6ff; margin-top:0.2rem;"><?= htmlspecialchars($order['creado_en']) ?></div>
                    </td>
                    <td style="background:#181f2a; color:#00fff7;">
                      <div style="font-weight:bold;"><?= htmlspecialchars(order_meta_value(order_is_account_sale_admin($order) ? 'Entrega de cuenta' : ($order['user_identifier'] ?? ''))) ?></div>
                      <div style="color:#b2f6ff; margin-top:0.2rem;"><?= htmlspecialchars($order['email']) ?></div>
                      <?php foreach ($playerFieldLines as $playerFieldLine): ?>
                        <div style="color:#7dd3fc; margin-top:0.2rem; font-size:0.9em;"><?= htmlspecialchars($playerFieldLine) ?></div>
                      <?php endforeach; ?>
                      <?php foreach ($accountSaleDetailLines as $accountSaleDetailLine): ?>
                        <div style="color:#67e8f9; margin-top:0.2rem; font-size:0.85em;"><?= htmlspecialchars($accountSaleDetailLine) ?></div>
                      <?php endforeach; ?>
                      <?php if ($discordStatusLine !== ''): ?>
                        <div style="color:#f9a8d4; margin-top:0.2rem; font-size:0.85em;"><?= htmlspecialchars($discordStatusLine) ?></div>
                      <?php endif; ?>
                      <?php if ($providerStatusLine !== ''): ?>
                        <div style="color:#fbbf24; margin-top:0.2rem; font-size:0.85em;"><?= htmlspecialchars($providerStatusLine) ?></div>
                      <?php endif; ?>
                      <?php if ($binanceStatusLine !== ''): ?>
                        <div style="color:#86efac; margin-top:0.2rem; font-size:0.85em;"><?= htmlspecialchars($binanceStatusLine) ?></div>
                      <?php endif; ?>
                      <?php foreach ($discordDetailLines as $discordDetailLine): ?>
                        <div style="color:#f5d0fe; margin-top:0.2rem; font-size:0.85em;"><?= htmlspecialchars($discordDetailLine) ?></div>
                      <?php endforeach; ?>
                      <?php foreach ($providerDetailLines as $providerDetailLine): ?>
                        <div style="color:#fca5a5; margin-top:0.2rem; font-size:0.85em;"><?= htmlspecialchars($providerDetailLine) ?></div>
                      <?php endforeach; ?>
                      <?php foreach ($binanceDetailLines as $binanceDetailLine): ?>
                        <div style="color:#93c5fd; margin-top:0.2rem; font-size:0.85em;"><?= htmlspecialchars($binanceDetailLine) ?></div>
                      <?php endforeach; ?>
                      <?php foreach ($providerHistoryLines as $providerHistoryLine): ?>
                        <div style="color:#c4b5fd; margin-top:0.2rem; font-size:0.8em;"><?= htmlspecialchars($providerHistoryLine) ?></div>
                      <?php endforeach; ?>
                      <?php foreach ($binanceHistoryLines as $binanceHistoryLine): ?>
                        <div style="color:#a7f3d0; margin-top:0.2rem; font-size:0.8em;"><?= htmlspecialchars($binanceHistoryLine) ?></div>
                      <?php endforeach; ?>
                    </td>
                    <td style="background:#181f2a; color:#b2f6ff;">
                      <div><?= htmlspecialchars(order_meta_value($order['numero_referencia'] ?? '')) ?></div>
                      <?php if (order_has_binance_tracking($order)): ?>
                        <div style="color:#86efac; margin-top:0.2rem; font-size:0.85em;">Binance: <?= htmlspecialchars(order_meta_value(order_binance_reference($order))) ?></div>
                      <?php endif; ?>
                    </td>
                    <td style="background:#181f2a; color:#b2f6ff;"><?= htmlspecialchars(order_meta_value($order['telefono_contacto'] ?? '')) ?></td>
                    <td style="background:#181f2a; color:#00fff7;">
                      <div style="font-weight:bold;">
                        <?php
                          $juegoTexto = htmlspecialchars($order['juego_nombre']);
                          if ($juegoTexto === '' || str_contains($order['juego_nombre'], '<?')) {
                            $juegoTexto = 'Juego #' . htmlspecialchars((string)($order['juego_id'] ?? '')); }
                          echo $juegoTexto;
                        ?>
                      </div>
                      <div style="color:#b2f6ff; margin-top:0.2rem;"><?= htmlspecialchars($order['paquete_nombre'] ?? '') ?></div>
                      <div style="color:#7dd3fc; margin-top:0.2rem; font-size:0.85em;">Cantidad comprada: <?= htmlspecialchars(order_purchase_quantity_label_admin($order)) ?></div>
                    </td>
                    <td style="background:#181f2a; color:#00ffb3; font-weight:bold;"><?= htmlspecialchars($order['moneda'] ?? '') ?> <?= format_money($order['precio']) ?></td>
                    <td style="background:#181f2a; color:#b2f6ff;">
                      <?= !empty($order['cupon']) ? htmlspecialchars($order['cupon']) : '—' ?>
                    </td>
                    <td style="background:#181f2a;">
                      <select class="js-status" style="border-radius:8px; border:1px solid #00fff7; background:#222c3a; color:#00fff7; font-weight:bold; padding:0.25em 0.5em;" data-order-id="<?= $order['id'] ?>" data-status="<?= $st ?>">
                        <option value="" selected disabled>Cambiar estado...</option>
                        <?php foreach ($statuses as $opt): ?>
                          <?php if ($opt === $st) { continue; } ?>
                          <option value="<?= $opt ?>"><?= htmlspecialchars(order_status_label($opt)) ?></option>
                        <?php endforeach; ?>
                      </select>
                      <?php if (order_can_retry_recharge($order)): ?>
                        <button type="button" class="btn btn-outline-info btn-sm mt-2 js-retry-recharge" data-order-id="<?= (int) $order['id'] ?>" data-confirm-message="<?= htmlspecialchars(order_retry_confirm_message($order), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(order_retry_button_label($order)) ?></button>
                      <?php endif; ?>
                      <?php if (order_can_sync_gateway($order)): ?>
                        <button type="button" class="btn btn-outline-warning btn-sm mt-2 js-sync-provider" data-order-id="<?= (int) $order['id'] ?>" data-sync-label="<?= htmlspecialchars(order_sync_button_label($order)) ?>"><?= htmlspecialchars(order_sync_button_label($order)) ?></button>
                      <?php endif; ?>
                      <?php if (order_can_manage_discord_status($order)): ?>
                        <select class="form-select form-select-sm mt-2 js-discord-status-select" data-order-id="<?= (int) $order['id'] ?>" style="border-radius:8px; border:1px solid #f9a8d4; background:#222c3a; color:#f9a8d4; font-weight:bold; padding:0.25em 0.5em;">
                          <option value="">Aplicar estado Discord...</option>
                          <?php foreach (order_discord_admin_status_options() as $discordStatusValue => $discordStatusLabel): ?>
                            <?php if ($discordStatusValue === trim((string) ($order['api_discord_status'] ?? ''))) { continue; } ?>
                            <option value="<?= htmlspecialchars($discordStatusValue, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($discordStatusLabel) ?></option>
                          <?php endforeach; ?>
                        </select>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>

          <!-- Mobile Cards -->
          <div id="cards-<?= $st ?>" class="d-block d-md-none" style="margin-top:1.5rem;">
            <?php foreach ($list as $order): ?>
              <?php $playerFieldLines = order_player_fields_lines($order); ?>
              <?php $accountSaleDetailLines = order_account_sale_detail_lines($order); ?>
              <?php $discordStatusLine = order_discord_status_label($order); ?>
              <?php $discordDetailLines = order_discord_detail_lines($order); ?>
              <?php $providerStatusLine = order_provider_status_label($order); ?>
              <?php $providerDetailLines = order_provider_detail_lines($order); ?>
              <?php $providerHistoryLines = order_provider_history_lines($order); ?>
              <?php $binanceStatusLine = order_binance_status_label($order); ?>
              <?php $binanceDetailLines = order_binance_detail_lines($order); ?>
              <?php $binanceHistoryLines = order_binance_history_lines($order); ?>
              <div id="pedido-card-<?= $order['id'] ?>" data-order-card="<?= $order['id'] ?>" data-status="<?= $st ?>" data-created-date="<?= htmlspecialchars(substr((string) ($order['creado_en'] ?? ''), 0, 10)) ?>" data-search-text="<?= htmlspecialchars(order_search_index($order)) ?>" style="background:#181f2a; border-radius:16px; border:2px solid #00fff7; box-shadow:0 0 24px #00fff733; padding:1rem; color:#00fff7; margin-bottom:1.5rem;">
                <div style="display:flex; align-items:center; justify-content:space-between;">
                  <div style="font-weight:bold; font-size:1.1em; color:#00fff7;">#<?= $order['id'] ?></div>
                  <div style="font-size:0.95em; color:#b2f6ff;"><?= htmlspecialchars($order['creado_en']) ?></div>
                </div>
                <div style="margin-top:0.5em; color:#00fff7; font-size:1em;">Cliente: <span style="color:#b2f6ff; font-weight:bold;"><?= htmlspecialchars(order_meta_value(order_is_account_sale_admin($order) ? 'Entrega de cuenta' : ($order['user_identifier'] ?? ''))) ?></span></div>
                <div style="color:#b2f6ff; font-size:1em;">Email: <?= htmlspecialchars($order['email']) ?></div>
                <?php foreach ($playerFieldLines as $playerFieldLine): ?>
                  <div style="color:#7dd3fc; font-size:0.95em;"><?= htmlspecialchars($playerFieldLine) ?></div>
                <?php endforeach; ?>
                <?php foreach ($accountSaleDetailLines as $accountSaleDetailLine): ?>
                  <div style="color:#67e8f9; font-size:0.9em;"><?= htmlspecialchars($accountSaleDetailLine) ?></div>
                <?php endforeach; ?>
                <?php if ($discordStatusLine !== ''): ?>
                  <div style="color:#f9a8d4; font-size:0.9em;"><?= htmlspecialchars($discordStatusLine) ?></div>
                <?php endif; ?>
                <?php if ($providerStatusLine !== ''): ?>
                  <div style="color:#fbbf24; font-size:0.9em;"><?= htmlspecialchars($providerStatusLine) ?></div>
                <?php endif; ?>
                <?php if ($binanceStatusLine !== ''): ?>
                  <div style="color:#86efac; font-size:0.9em;"><?= htmlspecialchars($binanceStatusLine) ?></div>
                <?php endif; ?>
                <?php foreach ($discordDetailLines as $discordDetailLine): ?>
                  <div style="color:#f5d0fe; font-size:0.9em;"><?= htmlspecialchars($discordDetailLine) ?></div>
                <?php endforeach; ?>
                <?php foreach ($providerDetailLines as $providerDetailLine): ?>
                  <div style="color:#fca5a5; font-size:0.9em;"><?= htmlspecialchars($providerDetailLine) ?></div>
                <?php endforeach; ?>
                <?php foreach ($binanceDetailLines as $binanceDetailLine): ?>
                  <div style="color:#93c5fd; font-size:0.9em;"><?= htmlspecialchars($binanceDetailLine) ?></div>
                <?php endforeach; ?>
                <?php foreach ($providerHistoryLines as $providerHistoryLine): ?>
                  <div style="color:#c4b5fd; font-size:0.85em;"><?= htmlspecialchars($providerHistoryLine) ?></div>
                <?php endforeach; ?>
                <?php foreach ($binanceHistoryLines as $binanceHistoryLine): ?>
                  <div style="color:#a7f3d0; font-size:0.85em;"><?= htmlspecialchars($binanceHistoryLine) ?></div>
                <?php endforeach; ?>
                <div style="color:#b2f6ff; font-size:1em;">Referencia: <?= htmlspecialchars(order_meta_value($order['numero_referencia'] ?? '')) ?></div>
                <?php if (order_has_binance_tracking($order)): ?>
                  <div style="color:#86efac; font-size:0.95em;">Ref Binance: <?= htmlspecialchars(order_meta_value(order_binance_reference($order))) ?></div>
                <?php endif; ?>
                <div style="color:#b2f6ff; font-size:1em;">Teléfono: <?= htmlspecialchars(order_meta_value($order['telefono_contacto'] ?? '')) ?></div>
                <div style="margin-top:0.5em; color:#00fff7; font-size:1em;">Juego: <span style="color:#b2f6ff; font-weight:bold;">
                  <?php
                    $juegoTexto = htmlspecialchars($order['juego_nombre']);
                    if ($juegoTexto === '' || str_contains($order['juego_nombre'], '<?')) {
                      $juegoTexto = 'Juego #' . htmlspecialchars((string)($order['juego_id'] ?? '')); }
                    echo $juegoTexto;
                  ?>
                </span></div>
                <div style="color:#b2f6ff; font-size:1em;">Paquete: <span style="color:#00fff7; font-weight:bold;"><?= htmlspecialchars($order['paquete_nombre'] ?? '') ?></span></div>
                <div style="color:#7dd3fc; font-size:0.95em;">Cantidad comprada: <?= htmlspecialchars(order_purchase_quantity_label_admin($order)) ?></div>
                <div style="color:#00ffb3; font-weight:bold; margin-top:0.5em;">Total: <?= htmlspecialchars($order['moneda'] ?? '') ?> <?= format_money($order['precio']) ?></div>
                <div style="color:#b2f6ff; font-size:0.95em; margin-top:0.5em;">Cupón: <?= !empty($order['cupon']) ? htmlspecialchars($order['cupon']) : '—' ?></div>
                <div class="order-status-actions" data-order-actions="<?= $order['id'] ?>">
                  <?php foreach ($statuses as $opt): ?>
                    <button
                      type="button"
                      class="order-status-btn js-status-btn"
                      data-order-id="<?= $order['id'] ?>"
                      data-status="<?= $st ?>"
                      data-status-value="<?= $opt ?>"
                      style="<?= htmlspecialchars(order_status_button_style($opt, false)) ?>;<?= $opt === $st ? ' display:none;' : '' ?>"
                    ><?= htmlspecialchars(order_status_label($opt)) ?></button>
                  <?php endforeach; ?>
                </div>
                <?php if (order_can_retry_recharge($order)): ?>
                  <button type="button" class="btn btn-outline-info btn-sm mt-3 js-retry-recharge" data-order-id="<?= (int) $order['id'] ?>" data-confirm-message="<?= htmlspecialchars(order_retry_confirm_message($order), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(order_retry_button_label($order)) ?></button>
                <?php endif; ?>
                <?php if (order_can_sync_gateway($order)): ?>
                  <button type="button" class="btn btn-outline-warning btn-sm mt-3 js-sync-provider" data-order-id="<?= (int) $order['id'] ?>" data-sync-label="<?= htmlspecialchars(order_sync_button_label($order)) ?>"><?= htmlspecialchars(order_sync_button_label($order)) ?></button>
                <?php endif; ?>
                <?php if (order_can_manage_discord_status($order)): ?>
                  <select class="form-select form-select-sm mt-3 js-discord-status-select" data-order-id="<?= (int) $order['id'] ?>" style="border-radius:8px; border:1px solid #f9a8d4; background:#222c3a; color:#f9a8d4; font-weight:bold; padding:0.45em 0.6em;">
                    <option value="">Aplicar estado Discord...</option>
                    <?php foreach (order_discord_admin_status_options() as $discordStatusValue => $discordStatusLabel): ?>
                      <?php if ($discordStatusValue === trim((string) ($order['api_discord_status'] ?? ''))) { continue; } ?>
                      <option value="<?= htmlspecialchars($discordStatusValue, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($discordStatusLabel) ?></option>
                    <?php endforeach; ?>
                  </select>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
          <?= orders_admin_render_pagination($st, (int) ($statusPages[$st] ?? 1), (int) ($statusTotalPages[$st] ?? 1)) ?>
        <?php endif; ?>
      </div>
    </section>
  <?php endforeach; ?>

  <div id="admin-loading-modal" class="admin-loading-modal" aria-hidden="true">
    <div class="admin-loading-card">
      <div class="admin-loading-spinner" aria-hidden="true"></div>
      <h3 class="h5 fw-bold text-info mb-2">Actualizando pedido...</h3>
      <p class="mb-0">Espera mientras se procesa el cambio de estado y se envian las notificaciones.</p>
    </div>
  </div>
</main>

<script>
// Forzar ocultamiento inicial y mostrar solo el tab activo
(function(){
  // Detectar tab inicial
  var serverInitialTab = <?php echo json_encode($initialTab, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
  var initialTab = serverInitialTab || localStorage.getItem('tvg_tab') || 'pendiente';
  var serverInitialSearch = <?php echo json_encode($initialOrderSearch, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
  var serverDateFrom = <?php echo json_encode($initialDateFrom, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
  var serverDateTo = <?php echo json_encode($initialDateTo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
  var targetOrderId = <?php echo json_encode($requestedOrderId); ?>;
  window.initialTab = initialTab;
  // Filtro de rango de fecha
  const dateForm = document.getElementById('date-filter-form');
  const dateFrom = document.getElementById('date-from');
  const dateTo = document.getElementById('date-to');
  const orderSearch = document.getElementById('order-search');
  const clearBtn = document.getElementById('clear-date-filter');
  const currentTabInput = document.getElementById('current-tab-input');
  const calendarFromBtn = document.getElementById('calendar-from-btn');
  const calendarToBtn = document.getElementById('calendar-to-btn');

  // Abrir selector de fecha al hacer clic en el ícono
  if (calendarFromBtn) {
    calendarFromBtn.addEventListener('click', function(){ dateFrom.showPicker && dateFrom.showPicker(); dateFrom.focus(); });
  }
  if (calendarToBtn) {
    calendarToBtn.addEventListener('click', function(){ dateTo.showPicker && dateTo.showPicker(); dateTo.focus(); });
  }

  // Responsive: los inputs y botones ocupan todo el ancho en móvil
  function adjustDateFilterResponsive(){
    if (window.innerWidth < 600) {
      dateForm.classList.add('flex-col','items-stretch');
      dateForm.classList.remove('ml-4');
      dateForm.style.minWidth = '0';
      dateForm.parentElement.style.overflowX = 'auto';
      dateForm.parentElement.style.width = '100%';
      dateForm.querySelectorAll('input,button').forEach(el => {
        el.classList.add('w-full','mb-1');
        el.style.minWidth = '0';
      });
    } else {
      dateForm.classList.remove('flex-col','items-stretch');
      dateForm.classList.add('ml-4');
      dateForm.style.minWidth = '320px';
      dateForm.parentElement.style.overflowX = '';
      dateForm.parentElement.style.width = '';
      dateForm.querySelectorAll('input,button').forEach(el => {
        el.classList.remove('w-full','mb-1');
        el.style.minWidth = '';
      });
    }
  }
  window.addEventListener('resize', function(){
    adjustDateFilterResponsive();
    updateTabCounts();
  });
  adjustDateFilterResponsive();

  function buildOrdersUrl(overrides = {}, resetPageParams = false) {
    const url = new URL(window.location.href);

    if (resetPageParams) {
      STATUS_ORDER.forEach(status => {
        url.searchParams.delete(`page_${status}`);
      });
    }

    Object.entries(overrides).forEach(([key, value]) => {
      if (value === null || value === undefined || value === '') {
        url.searchParams.delete(key);
        return;
      }

      url.searchParams.set(key, String(value));
    });

    return url.toString();
  }

  function applyFilters(){
    window.location.href = buildOrdersUrl({
      date_from: dateFrom ? dateFrom.value : '',
      date_to: dateTo ? dateTo.value : '',
      order_search: orderSearch ? orderSearch.value.trim() : '',
      tab: currentTabInput ? currentTabInput.value : initialTab,
      pedido: null,
    }, true);
  }

  function highlightTargetOrder() {
    if (!targetOrderId) {
      return;
    }
    const desktopTarget = document.querySelector(`[data-order-row="${targetOrderId}"]`);
    const mobileTarget = document.querySelector(`[data-order-card="${targetOrderId}"]`);
    const target = window.innerWidth >= 768 ? desktopTarget : mobileTarget;
    if (!target || target.style.display === 'none') {
      return;
    }
    target.classList.add('order-target-highlight');
    target.scrollIntoView({ behavior: 'smooth', block: 'center' });
  }

  dateForm.addEventListener('submit', function(e){
    e.preventDefault();
    applyFilters();
  });

  clearBtn.addEventListener('click', function(){
    dateFrom.value = '';
    dateTo.value = '';
    if (orderSearch) {
      orderSearch.value = '';
    }
    applyFilters();
  });
  const tabs = Array.from(document.querySelectorAll('.tab-btn'));
  const panels = Array.from(document.querySelectorAll('.tab-panel'));
  const adminLoadingModal = document.getElementById('admin-loading-modal');
  const STATUS_ORDER = ['pendiente', 'pagado', 'enviado', 'cancelado'];
  const STATUS_LABELS = {
    pendiente: 'No Verificado',
    pagado: 'Verificado',
    enviado: 'Enviado',
    cancelado: 'Cancelado'
  };

  function setAdminLoadingVisible(visible) {
    if (!adminLoadingModal) {
      return;
    }
    adminLoadingModal.classList.toggle('is-visible', visible);
    adminLoadingModal.setAttribute('aria-hidden', visible ? 'false' : 'true');
    document.body.classList.toggle('admin-loading-open', visible);
  }

  function showTab(tab){
    panels.forEach(p => p.classList.add('hidden'));
    const activePanel = panels.find(p => p.dataset.panel === tab);
    if (activePanel) activePanel.classList.remove('hidden');
    tabs.forEach(b => b.classList.remove('active','border-cyan-400','text-cyan-200'));
    const activeTab = tabs.find(b => b.dataset.tab === tab);
    if (activeTab) {
      activeTab.classList.add('active','border-cyan-400','text-cyan-200');
    }
    if (currentTabInput) {
      currentTabInput.value = tab;
    }
    window.history.replaceState({}, '', buildOrdersUrl({ tab }));
    localStorage.setItem('tvg_tab', tab);
  }
  if (dateFrom && serverDateFrom) {
    dateFrom.value = serverDateFrom;
  }
  if (dateTo && serverDateTo) {
    dateTo.value = serverDateTo;
  }
  if (orderSearch && serverInitialSearch) {
    orderSearch.value = serverInitialSearch;
  }

  const initial = initialTab || localStorage.getItem('tvg_tab') || 'pendiente';
  showTab(initial);
  updateTabCounts();
  setTimeout(highlightTargetOrder, 120);
  tabs.forEach(btn => btn.addEventListener('click', () => showTab(btn.dataset.tab)));

  function moveOrder(id, newStatus){
    const row = document.querySelector(`[data-order-row="${id}"]`);
    const card = document.querySelector(`[data-order-card="${id}"]`);
    if (row) {
      row.dataset.status = newStatus;
      const targetBody = document.getElementById('table-body-' + newStatus);
      if (targetBody) targetBody.prepend(row);
      const selectRow = row.querySelector('select');
      if (selectRow) selectRow.value = newStatus;
    }
    if (card) {
      card.dataset.status = newStatus;
      const targetCards = document.getElementById('cards-' + newStatus);
      if (targetCards) targetCards.prepend(card);
    }
  }

  function buildDesktopStatusOptions(currentStatus) {
    const placeholder = '<option value="" selected disabled>Cambiar estado...</option>';
    const options = STATUS_ORDER
      .filter(status => status !== currentStatus)
      .map(status => `<option value="${status}">${STATUS_LABELS[status] || status}</option>`)
      .join('');

    return placeholder + options;
  }

  function refreshDesktopStatusSelects(orderId, currentStatus) {
    document.querySelectorAll(`.js-status[data-order-id="${orderId}"]`).forEach(select => {
      select.dataset.status = currentStatus;
      select.innerHTML = buildDesktopStatusOptions(currentStatus);
      select.value = '';
    });
  }

  function statusColor(status) {
    switch (status) {
      case 'pendiente':
        return '#ffc107';
      case 'pagado':
        return '#00ffb3';
      case 'enviado':
        return '#2196f3';
      case 'cancelado':
        return '#ff0059';
      default:
        return '#00fff7';
    }
  }

  function applyButtonState(button, isActive) {
    const status = button.dataset.statusValue || '';
    const color = statusColor(status);
    button.style.borderColor = color;
    button.style.background = isActive
      ? color
      : (status === 'pendiente'
        ? 'rgba(255, 193, 7, 0.08)'
        : (status === 'pagado'
          ? 'rgba(0, 255, 179, 0.08)'
          : (status === 'enviado' ? 'rgba(33, 150, 243, 0.08)' : 'rgba(255, 0, 89, 0.08)')));
    button.style.color = isActive
      ? (status === 'pendiente' ? '#181f2a' : '#ffffff')
      : color;
    button.style.boxShadow = isActive ? `0 0 12px ${color}66` : 'none';
  }

  function updateCardStatusButtons(orderId, newStatus) {
    document.querySelectorAll(`.js-status-btn[data-order-id="${orderId}"]`).forEach(button => {
      button.dataset.status = newStatus;
      const isCurrent = button.dataset.statusValue === newStatus;
      button.style.display = isCurrent ? 'none' : '';
      applyButtonState(button, false);
    });
  }

  function updateTabCounts() {
    panels.forEach(panel => {
      const renderedRows = panel.querySelectorAll('[data-order-row]').length;
      const renderedCards = panel.querySelectorAll('[data-order-card]').length;
      const count = Math.max(renderedRows, renderedCards);
      const totalCount = parseInt(panel.dataset.totalCount || String(count), 10) || 0;
      const currentPage = parseInt(panel.dataset.currentPage || '1', 10) || 1;
      const totalPages = parseInt(panel.dataset.totalPages || '1', 10) || 1;
      const totalLabel = panel.querySelector('[data-total-label]');
      if (totalLabel) {
        let summary = totalCount > 0 ? `Mostrando ${count} de ${totalCount} pedidos` : 'Total: 0 pedidos';
        if (totalCount > 0 && totalPages > 1) {
          summary += ` | Página ${currentPage} de ${totalPages}`;
        }
        totalLabel.textContent = summary;
      }
    });
  }

  async function submitStatusChange(orderId, prevStatus, newStatus) {
    const fd = new FormData();
    fd.append('action','update_status');
    fd.append('order_id', orderId);
    fd.append('estado', newStatus);

    const res = await fetch(window.__TVG_API_PEDIDOS || <?php echo json_encode($ordersApiUrl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>, { method: 'POST', body: fd });
    const txt = await res.text();
    let data;
    try {
      data = JSON.parse(txt);
    } catch (_) {
      throw new Error('Respuesta no válida del servidor');
    }
    if (!data.ok) {
      throw new Error(data.message || 'Error');
    }

    window.location.href = buildOrdersUrl({
      tab: newStatus,
      pedido: orderId,
    }, true);
  }

  function bindStatusSelectors(){
    document.querySelectorAll('.js-status').forEach(sel => {
      sel.addEventListener('change', async () => {
        const orderId = sel.dataset.orderId;
        const prevStatus = sel.dataset.status;
        const newStatus = sel.value;
        if (!newStatus) {
          return;
        }
        sel.disabled = true;
        setAdminLoadingVisible(true);
        try {
          await submitStatusChange(orderId, prevStatus, newStatus);
          sel.dataset.status = newStatus;
        } catch(err){
          refreshDesktopStatusSelects(orderId, prevStatus);
          alert(err.message || 'No se pudo cambiar el estado');
        } finally {
          sel.disabled = false;
          setAdminLoadingVisible(false);
        }
      });
    });

    document.querySelectorAll('.js-status-btn').forEach(button => {
      applyButtonState(button, button.dataset.statusValue === button.dataset.status);
      button.addEventListener('click', async () => {
        const orderId = button.dataset.orderId;
        const prevStatus = button.dataset.status;
        const newStatus = button.dataset.statusValue;
        if (!orderId || !newStatus || prevStatus === newStatus) {
          return;
        }

        const relatedButtons = document.querySelectorAll(`.js-status-btn[data-order-id="${orderId}"]`);
        relatedButtons.forEach(item => { item.disabled = true; });
        setAdminLoadingVisible(true);
        try {
          await submitStatusChange(orderId, prevStatus, newStatus);
        } catch (err) {
          updateCardStatusButtons(orderId, prevStatus);
          alert(err.message || 'No se pudo cambiar el estado');
        } finally {
          relatedButtons.forEach(item => { item.disabled = false; });
          setAdminLoadingVisible(false);
        }
      });
    });

    document.querySelectorAll('.js-sync-provider').forEach(button => {
      button.addEventListener('click', async () => {
        const orderId = button.dataset.orderId;
        const syncLabel = button.dataset.syncLabel || 'Sincronizar pedido';
        if (!orderId) {
          return;
        }

        button.disabled = true;
        setAdminLoadingVisible(true);
        try {
          const fd = new FormData();
          fd.append('action', 'sync_provider_status');
          fd.append('order_id', orderId);
          const res = await fetch(window.__TVG_API_PEDIDOS || <?php echo json_encode($ordersApiUrl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>, { method: 'POST', body: fd });
          const data = await res.json();
          if (!res.ok || !data.ok) {
            throw new Error((data && data.message) ? data.message : 'No se pudo sincronizar el pedido con la API.');
          }
          const syncNotes = [data.message || 'Pedido sincronizado correctamente.'];
          if (data.provider_status) {
            const statusLabel = data.payment_gateway === 'binance_pay' ? 'Estado Binance' : 'Estado proveedor';
            syncNotes.push(`${statusLabel}: ${data.provider_status}`);
          }
          if (data.provider_reference) {
            const referenceLabel = data.payment_gateway === 'binance_pay' ? 'Referencia Binance' : 'Referencia proveedor';
            syncNotes.push(`${referenceLabel}: ${data.provider_reference}`);
          }
          if (data.provider_message) {
            syncNotes.push(`Detalle API: ${data.provider_message}`);
          }
          alert(syncNotes.join('\n'));
          window.location.reload();
        } catch (err) {
          alert(err.message || `No se pudo completar ${syncLabel.toLowerCase()}.`);
        } finally {
          button.disabled = false;
          setAdminLoadingVisible(false);
        }
      });
    });

    document.querySelectorAll('.js-retry-recharge').forEach(button => {
      button.addEventListener('click', async () => {
        const orderId = button.dataset.orderId;
        if (!orderId) {
          return;
        }

        const confirmed = window.confirm(button.dataset.confirmMessage || 'Se enviara nuevamente la recarga para este pedido verificado. Usa esta accion solo si la recarga anterior no se proceso realmente.');
        if (!confirmed) {
          return;
        }

        button.disabled = true;
        setAdminLoadingVisible(true);
        try {
          const fd = new FormData();
          fd.append('action', 'admin_retry_recharge');
          fd.append('order_id', orderId);
          const res = await fetch(window.__TVG_API_PEDIDOS || <?php echo json_encode($ordersApiUrl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>, { method: 'POST', body: fd });
          const data = await res.json();
          if (!res.ok || !data.ok) {
            throw new Error((data && data.message) ? data.message : 'No se pudo enviar nuevamente la recarga.');
          }

          const notes = [data.message || 'Pedido reenviado correctamente.'];
          if (data.provider_status) {
            notes.push(`Estado proveedor: ${data.provider_status}`);
          }
          if (data.provider_message) {
            notes.push(`Detalle API: ${data.provider_message}`);
          }
          alert(notes.join('\n'));
          window.location.reload();
        } catch (err) {
          alert(err.message || 'No se pudo enviar nuevamente la recarga.');
        } finally {
          button.disabled = false;
          setAdminLoadingVisible(false);
        }
      });
    });

    const discordStatusLabels = {
      queued: 'Discord en cola',
      sent: 'Discord aceptado',
      processing: 'Discord en proceso',
      confirmed: 'Discord confirmado',
      review: 'Discord en revisión',
      failed: 'Discord fallido',
      cancelled: 'Discord cancelado'
    };

    document.querySelectorAll('.js-discord-status-select').forEach(select => {
      select.addEventListener('change', async () => {
        const orderId = select.dataset.orderId;
        const discordStatus = String(select.value || '').trim();
        if (!orderId || !discordStatus) {
          return;
        }

        const statusLabel = discordStatusLabels[discordStatus] || discordStatus;
        const confirmed = window.confirm(`Se aplicará manualmente el estado ${statusLabel} a este pedido Discord.`);
        if (!confirmed) {
          select.value = '';
          return;
        }

        const providerMessage = window.prompt('Detalle opcional para registrar en el historial de Discord:', '');
        if (providerMessage === null) {
          select.value = '';
          return;
        }

        select.disabled = true;
        setAdminLoadingVisible(true);
        try {
          const fd = new FormData();
          fd.append('action', 'admin_update_discord_status');
          fd.append('order_id', orderId);
          fd.append('discord_status', discordStatus);
          if (String(providerMessage).trim() !== '') {
            fd.append('provider_message', String(providerMessage).trim());
          }

          const res = await fetch(window.__TVG_API_PEDIDOS || <?php echo json_encode($ordersApiUrl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>, { method: 'POST', body: fd });
          const data = await res.json();
          if (!res.ok || !data.ok) {
            throw new Error((data && data.message) ? data.message : 'No se pudo actualizar el estado Discord.');
          }

          const notes = [data.message || 'Estado Discord actualizado correctamente.'];
          if (data.provider_status) {
            notes.push(`Estado Discord: ${data.provider_status}`);
          }
          if (data.provider_message) {
            notes.push(`Detalle Discord: ${data.provider_message}`);
          }
          alert(notes.join('\n'));
          window.location.reload();
        } catch (err) {
          alert(err.message || 'No se pudo actualizar el estado Discord.');
          select.value = '';
        } finally {
          select.disabled = false;
          setAdminLoadingVisible(false);
        }
      });
    });
  }
  updateTabCounts();
  bindStatusSelectors();
})();
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
