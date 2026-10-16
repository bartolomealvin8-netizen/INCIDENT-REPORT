<?php
declare(strict_types=1);

// System account definitions for authentication and role access.
const SYSTEM_ACCOUNTS = [
    'admin' => ['login_identifier' => 'admin@school.example', 'password_hash' => '$2y$10$.69Gmlz/a0.9aj.0a2s/aeSPqDDyiyZ20p4DMM5ed9E8gIE/5Cr0a', 'role' => 'admin'],
    'supervisor' => ['login_identifier' => 'supervisor@school.example', 'password_hash' => '$2y$10$QyTXckR0epyJ8aUdJUkLn.XHms0zly0vQT3xOv4fde09SeE2eS7QO', 'role' => 'supervisor'],
    'guard' => ['login_identifier' => 'guard@school.example', 'password_hash' => '$2y$10$cEwDWEI5yu1pvWn33rHZGu24IulTcP45RNkh0IEAcQNi9.35M.AhS', 'role' => 'guard'],
    'staff' => ['login_identifier' => 'staff@school.example', 'password_hash' => '$2y$10$Cwpn1X5FxbXSnnHDP3py.Ov5O1xJI9DGpfe4fO.pdITWU2Ljx5.1C', 'role' => 'staff'],
    'student' => ['login_identifier' => '0319-3904-479', 'password_hash' => '$2y$10$cRZzLS23bQ/u4T85Ty2i9OMzodAu0APbz8FPgiQglDAQQfldwfgvy', 'role' => 'student'],
];

function find_system_account(string $identifier): ?array
{
    $normalizedIdentifier = strtolower(trim($identifier));

    foreach (SYSTEM_ACCOUNTS as $username => $account) {
        if (
            hash_equals(strtolower($account['login_identifier']), $normalizedIdentifier)
            || hash_equals(strtolower($username), $normalizedIdentifier)
        ) {
            return ['username' => $username] + $account;
        }
    }

    return null;
}

function start_app_session(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
}

function require_login(): void
{
    start_app_session();

    if (($_SESSION['authenticated'] ?? false) !== true) {
        header('Location: login.php');
        exit;
    }
}

function require_role(string $role): void
{
    require_login();

    if (($_SESSION['role'] ?? '') !== $role) {
        header('Location: Dashboard.php');
        exit;
    }
}
