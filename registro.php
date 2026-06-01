<?php
require_once __DIR__ . "/includes/auth.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
  auth_redirect_back("/");
}

$tenantSlug = auth_get_tenant_slug();
$fullName = trim((string) ($_POST["full_name"] ?? ""));
$email = auth_normalize_email($_POST["email"] ?? "");
$phone = trim((string) ($_POST["phone"] ?? ""));
$password = (string) ($_POST["password"] ?? "");

if ($fullName === "" || $email === "" || $password === "") {
  auth_set_flash("error", "Completa los campos requeridos.");
  auth_redirect_back("/");
}

$users = auth_load_users($tenantSlug);
$existing = auth_find_user_by_email($users, $email);

if ($existing !== null) {
  auth_set_flash("error", "Este correo ya está registrado.");
  auth_redirect_back("/");
}

$user = [
  "id" => bin2hex(random_bytes(8)),
  "full_name" => $fullName,
  "email" => $email,
  "phone" => $phone,
  "password_hash" => password_hash($password, PASSWORD_DEFAULT),
  "created_at" => date("c")
];

$users[] = $user;
auth_save_users($tenantSlug, $users);

if (session_status() !== PHP_SESSION_ACTIVE) {
  tenant_start_session();
}

$_SESSION["auth_user"] = [
  "id" => $user["id"],
  "email" => $user["email"],
  "full_name" => $user["full_name"],
  "phone" => $user["phone"],
  "tenant" => $tenantSlug
];

auth_set_flash("success", "Cuenta creada correctamente.");
// Redirect back to the page where the modal was opened.
auth_redirect_back("/");
