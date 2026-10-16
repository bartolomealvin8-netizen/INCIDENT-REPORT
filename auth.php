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

function account_store_path(): string
{
    $configuredDirectory = getenv('INCIDENT_REPORT_DATA_DIR');
    $directory = $configuredDirectory !== false && trim($configuredDirectory) !== ''
        ? trim($configuredDirectory)
        : dirname(__DIR__) . DIRECTORY_SEPARATOR . basename(__DIR__) . '-data';

    if (!str_starts_with($directory, DIRECTORY_SEPARATOR)) {
        throw new RuntimeException('INCIDENT_REPORT_DATA_DIR must be an absolute path.');
    }

    return rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'accounts.json';
}

function read_stored_accounts($stream, bool $alreadyLocked = false): array
{
    if (!$alreadyLocked && !flock($stream, LOCK_SH)) {
        throw new RuntimeException('Could not lock the account store for reading.');
    }

    try {
        $contents = stream_get_contents($stream);
        if ($contents === false) {
            throw new RuntimeException('Could not read the account store.');
        }
        if (trim($contents) === '') {
            return [];
        }

        try {
            $accounts = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('The account store contains invalid JSON.', 0, $exception);
        }

        if (!is_array($accounts)) {
            throw new RuntimeException('The account store has an invalid format.');
        }

        foreach ($accounts as $username => $account) {
            if (
                !is_string($username)
                || preg_match('/\A[a-zA-Z][a-zA-Z0-9._-]{2,31}\z/', $username) !== 1
                || !is_array($account)
                || ($account['username'] ?? null) !== $username
                || !isset($account['display_name'], $account['login_identifier'], $account['password_hash'], $account['role'], $account['guard_id'])
                || !is_string($account['display_name'])
                || $account['display_name'] === ''
                || strlen($account['display_name']) > 100
                || !is_string($account['login_identifier'])
                || filter_var($account['login_identifier'], FILTER_VALIDATE_EMAIL) === false
                || !is_string($account['password_hash'])
                || ($account['role'] ?? null) !== 'guard'
                || !is_string($account['guard_id'])
                || preg_match('/\AG\d{3,}\z/', $account['guard_id']) !== 1
            ) {
                throw new RuntimeException('The account store contains an invalid account record.');
            }
        }

        return $accounts;
    } finally {
        if (!$alreadyLocked) {
            flock($stream, LOCK_UN);
        }
    }
}

function system_accounts(): array
{
    $path = account_store_path();
    if (!is_file($path)) {
        return SYSTEM_ACCOUNTS;
    }

    $stream = fopen($path, 'rb');
    if ($stream === false) {
        throw new RuntimeException('Could not open the account store.');
    }

    try {
        $storedAccounts = read_stored_accounts($stream);
    } finally {
        fclose($stream);
    }

    return SYSTEM_ACCOUNTS + $storedAccounts;
}

function create_guard_account(string $username, string $displayName, string $email, string $password): string
{
    if (
        preg_match('/\A[a-zA-Z][a-zA-Z0-9._-]{2,31}\z/', $username) !== 1
        || trim($displayName) === ''
        || strlen($displayName) > 100
        || filter_var($email, FILTER_VALIDATE_EMAIL) === false
        || strlen($email) > 254
        || strlen($password) < 12
        || strlen($password) > 72
    ) {
        throw new InvalidArgumentException('Guard account details are invalid.');
    }

    $path = account_store_path();
    $directory = dirname($path);
    if (!is_dir($directory)) {
        $previousUmask = umask(0077);
        try {
            $directoryCreated = mkdir($directory, 0700, true);
        } finally {
            umask($previousUmask);
        }
        if (!$directoryCreated && !is_dir($directory)) {
            throw new RuntimeException('Could not create the account data directory.');
        }
    }

    $previousUmask = umask(0077);
    try {
        $stream = fopen($path, 'c+');
    } finally {
        umask($previousUmask);
    }
    if ($stream === false) {
        throw new RuntimeException('Could not open the account store for writing.');
    }
    if (!chmod($path, 0600)) {
        fclose($stream);
        throw new RuntimeException('Could not restrict permissions on the account store.');
    }

    if (!flock($stream, LOCK_EX)) {
        fclose($stream);
        throw new RuntimeException('Could not lock the account store for writing.');
    }

    try {
        rewind($stream);
        $storedAccounts = read_stored_accounts($stream, true);
        $accounts = SYSTEM_ACCOUNTS + $storedAccounts;
        foreach ($accounts as $existingUsername => $account) {
            if (
                strcasecmp((string) $existingUsername, $username) === 0
                || strcasecmp((string) $account['login_identifier'], $email) === 0
            ) {
                throw new DomainException('An account with that username or email already exists.');
            }
        }

        $nextGuardNumber = 1;
        foreach ($accounts as $account) {
            if (($account['role'] ?? '') !== 'guard') {
                continue;
            }
            $guardId = $account['guard_id'] ?? 'G001';
            if (is_string($guardId) && preg_match('/\AG(\d+)\z/', $guardId, $matches) === 1) {
                $nextGuardNumber = max($nextGuardNumber, (int) $matches[1] + 1);
            }
        }

        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        if ($passwordHash === false) {
            throw new RuntimeException('Could not hash the new guard account password.');
        }

        $storedAccounts[$username] = [
            'username' => $username,
            'display_name' => $displayName,
            'login_identifier' => $email,
            'password_hash' => $passwordHash,
            'role' => 'guard',
            'guard_id' => 'G' . str_pad((string) $nextGuardNumber, 3, '0', STR_PAD_LEFT),
        ];

        try {
            $contents = json_encode($storedAccounts, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Could not encode the account store.', 0, $exception);
        }

        rewind($stream);
        if (!ftruncate($stream, 0) || fwrite($stream, $contents) !== strlen($contents) || !fflush($stream)) {
            throw new RuntimeException('Could not save the new guard account.');
        }
        return $storedAccounts[$username]['guard_id'];
    } finally {
        flock($stream, LOCK_UN);
        fclose($stream);
    }
}

function read_guard_attendance_store($stream, bool $alreadyLocked = false): array
{
    if (!$alreadyLocked && !flock($stream, LOCK_SH)) {
        throw new RuntimeException('Could not lock the attendance store for reading.');
    }

    try {
        $contents = stream_get_contents($stream);
        if ($contents === false) {
            throw new RuntimeException('Could not read the attendance store.');
        }
        if (trim($contents) === '') {
            return ['current' => [], 'events' => []];
        }

        try {
            $attendance = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('The attendance store contains invalid JSON.', 0, $exception);
        }

        if (
            !is_array($attendance)
            || !isset($attendance['current'], $attendance['events'])
            || !is_array($attendance['current'])
            || !is_array($attendance['events'])
        ) {
            throw new RuntimeException('The attendance store has an invalid format.');
        }

        foreach ($attendance['current'] as $username => $record) {
            if (
                !is_string($username)
                || !is_array($record)
                || !in_array($record['status'] ?? null, ['on_duty', 'off_duty'], true)
                || !array_key_exists('checked_in_at', $record)
                || !array_key_exists('checked_out_at', $record)
                || ($record['checked_in_at'] !== null && !is_string($record['checked_in_at']))
                || ($record['checked_out_at'] !== null && !is_string($record['checked_out_at']))
            ) {
                throw new RuntimeException('The attendance store contains an invalid status record.');
            }
        }
        foreach ($attendance['events'] as $event) {
            if (
                !is_array($event)
                || !isset($event['username'], $event['action'], $event['occurred_at'])
                || !is_string($event['username'])
                || !in_array($event['action'], ['check_in', 'check_out'], true)
                || !is_string($event['occurred_at'])
            ) {
                throw new RuntimeException('The attendance store contains an invalid event.');
            }
        }

        return $attendance;
    } finally {
        if (!$alreadyLocked) {
            flock($stream, LOCK_UN);
        }
    }
}

function guard_attendance(): array
{
    $path = dirname(account_store_path()) . DIRECTORY_SEPARATOR . 'attendance.json';
    if (!is_file($path)) {
        return ['current' => [], 'events' => []];
    }

    $stream = fopen($path, 'rb');
    if ($stream === false) {
        throw new RuntimeException('Could not open the attendance store.');
    }

    try {
        return read_guard_attendance_store($stream);
    } finally {
        fclose($stream);
    }
}

function record_guard_attendance(string $username, string $action): array
{
    if (!in_array($action, ['check_in', 'check_out'], true)) {
        throw new InvalidArgumentException('Attendance action is invalid.');
    }

    $account = system_accounts()[$username] ?? null;
    if (!is_array($account) || ($account['role'] ?? '') !== 'guard') {
        throw new DomainException('Only guard accounts can record guard attendance.');
    }

    $directory = dirname(account_store_path());
    if (!is_dir($directory)) {
        $previousUmask = umask(0077);
        try {
            $directoryCreated = mkdir($directory, 0700, true);
        } finally {
            umask($previousUmask);
        }
        if (!$directoryCreated && !is_dir($directory)) {
            throw new RuntimeException('Could not create the attendance data directory.');
        }
    }

    $path = $directory . DIRECTORY_SEPARATOR . 'attendance.json';
    $previousUmask = umask(0077);
    try {
        $stream = fopen($path, 'c+');
    } finally {
        umask($previousUmask);
    }
    if ($stream === false) {
        throw new RuntimeException('Could not open the attendance store for writing.');
    }
    if (!chmod($path, 0600)) {
        fclose($stream);
        throw new RuntimeException('Could not restrict permissions on the attendance store.');
    }
    if (!flock($stream, LOCK_EX)) {
        fclose($stream);
        throw new RuntimeException('Could not lock the attendance store for writing.');
    }

    try {
        rewind($stream);
        $attendance = read_guard_attendance_store($stream, true);
        $currentStatus = $attendance['current'][$username]['status'] ?? 'off_duty';
        if ($action === 'check_in' && $currentStatus === 'on_duty') {
            throw new DomainException('You are already checked in.');
        }
        if ($action === 'check_out' && $currentStatus !== 'on_duty') {
            throw new DomainException('You are not currently checked in.');
        }

        $timestamp = date(DATE_ATOM);
        $attendance['current'][$username] ??= [
            'status' => 'off_duty',
            'checked_in_at' => null,
            'checked_out_at' => null,
        ];
        if ($action === 'check_in') {
            $attendance['current'][$username]['status'] = 'on_duty';
            $attendance['current'][$username]['checked_in_at'] = $timestamp;
        } else {
            $attendance['current'][$username]['status'] = 'off_duty';
            $attendance['current'][$username]['checked_out_at'] = $timestamp;
        }
        $attendance['events'][] = [
            'username' => $username,
            'action' => $action,
            'occurred_at' => $timestamp,
        ];

        try {
            $contents = json_encode($attendance, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Could not encode the attendance store.', 0, $exception);
        }

        rewind($stream);
        if (!ftruncate($stream, 0) || fwrite($stream, $contents) !== strlen($contents) || !fflush($stream)) {
            throw new RuntimeException('Could not save the attendance record.');
        }

        return $attendance;
    } finally {
        flock($stream, LOCK_UN);
        fclose($stream);
    }
}

function find_system_account(string $identifier): ?array
{
    $normalizedIdentifier = strtolower(trim($identifier));

    foreach (system_accounts() as $username => $account) {
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
