<?php
declare(strict_types=1);

// System account definitions for authentication and role access.
const SYSTEM_ACCOUNTS = [
    'admin' => ['login_identifier' => 'admin@school.example', 'password_hash' => '$2y$10$.69Gmlz/a0.9aj.0a2s/aeSPqDDyiyZ20p4DMM5ed9E8gIE/5Cr0a', 'role' => 'admin'],
    'staff' => ['login_identifier' => 'staff@school.example', 'password_hash' => '$2y$10$Cwpn1X5FxbXSnnHDP3py.Ov5O1xJI9DGpfe4fO.pdITWU2Ljx5.1C', 'role' => 'staff'],
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
                || !isset($account['display_name'], $account['login_identifier'], $account['password_hash'], $account['role'])
                || !is_string($account['display_name'])
                || $account['display_name'] === ''
                || strlen($account['display_name']) > 100
                || !is_string($account['login_identifier'])
                || filter_var($account['login_identifier'], FILTER_VALIDATE_EMAIL) === false
                || !is_string($account['password_hash'])
                || !in_array($account['role'] ?? null, ['staff', 'guard'], true)
                || (($account['role'] ?? null) === 'guard' && (
                    !is_string($account['guard_id'] ?? null)
                    || preg_match('/\AG\d{3,}\z/', $account['guard_id']) !== 1
                ))
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

    return SYSTEM_ACCOUNTS + array_filter(
        $storedAccounts,
        static fn (array $account): bool => ($account['role'] ?? '') === 'staff'
    );
}

function create_staff_account(string $username, string $displayName, string $email, string $password): void
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
        throw new InvalidArgumentException('Staff account details are invalid.');
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

        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        if ($passwordHash === false) {
            throw new RuntimeException('Could not hash the new staff account password.');
        }

        $storedAccounts[$username] = [
            'username' => $username,
            'display_name' => $displayName,
            'login_identifier' => $email,
            'password_hash' => $passwordHash,
            'role' => 'staff',
        ];

        try {
            $contents = json_encode($storedAccounts, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Could not encode the account store.', 0, $exception);
        }

        rewind($stream);
        if (!ftruncate($stream, 0) || fwrite($stream, $contents) !== strlen($contents) || !fflush($stream)) {
            throw new RuntimeException('Could not save the new staff account.');
        }
    } finally {
        flock($stream, LOCK_UN);
        fclose($stream);
    }
}

function incident_store_path(): string
{
    return dirname(account_store_path()) . DIRECTORY_SEPARATOR . 'incidents.json';
}

function open_private_store(string $path)
{
    $directory = dirname($path);
    if (!is_dir($directory)) {
        $previousUmask = umask(0077);
        try {
            $directoryCreated = mkdir($directory, 0700, true);
        } finally {
            umask($previousUmask);
        }
        if (!$directoryCreated && !is_dir($directory)) {
            throw new RuntimeException('Could not create the application data directory.');
        }
    }

    $previousUmask = umask(0077);
    try {
        $stream = fopen($path, 'c+');
    } finally {
        umask($previousUmask);
    }
    if ($stream === false) {
        throw new RuntimeException('Could not open the application data store.');
    }
    if (!chmod($path, 0600)) {
        fclose($stream);
        throw new RuntimeException('Could not restrict permissions on the application data store.');
    }

    return $stream;
}

function read_incidents_store($stream, bool $alreadyLocked = false): array
{
    if (!$alreadyLocked && !flock($stream, LOCK_SH)) {
        throw new RuntimeException('Could not lock the incident store for reading.');
    }

    try {
        rewind($stream);
        $contents = stream_get_contents($stream);
        if ($contents === false) {
            throw new RuntimeException('Could not read the incident store.');
        }
        if (trim($contents) === '') {
            return [];
        }

        try {
            $incidents = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('The incident store contains invalid JSON.', 0, $exception);
        }
        if (!is_array($incidents)) {
            throw new RuntimeException('The incident store has an invalid format.');
        }

        foreach ($incidents as $incident) {
            if (
                !is_array($incident)
                || !isset($incident['id'], $incident['student'], $incident['type'], $incident['location'], $incident['details'], $incident['occurrence_at'], $incident['reported_at'], $incident['reporter'], $incident['status'])
                || !is_string($incident['id'])
                || !is_string($incident['student'])
                || !is_string($incident['type'])
                || !is_string($incident['location'])
                || !is_string($incident['details'])
                || !is_string($incident['occurrence_at'])
                || !is_string($incident['reported_at'])
                || !is_string($incident['reporter'])
                || !in_array($incident['status'], ['Pending', 'Reviewed', 'Resolved'], true)
            ) {
                throw new RuntimeException('The incident store contains an invalid record.');
            }
        }

        return array_values($incidents);
    } finally {
        if (!$alreadyLocked) {
            flock($stream, LOCK_UN);
        }
    }
}

function incident_records(): array
{
    $path = incident_store_path();
    if (!is_file($path)) {
        return [];
    }

    $stream = fopen($path, 'rb');
    if ($stream === false) {
        throw new RuntimeException('Could not open the incident store.');
    }
    try {
        return read_incidents_store($stream);
    } finally {
        fclose($stream);
    }
}

function write_incidents_store($stream, array $incidents): void
{
    try {
        $contents = json_encode($incidents, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        throw new RuntimeException('Could not encode the incident store.', 0, $exception);
    }

    rewind($stream);
    if (!ftruncate($stream, 0) || fwrite($stream, $contents) !== strlen($contents) || !fflush($stream)) {
        throw new RuntimeException('Could not save the incident store.');
    }
}

function create_incident_record(array $incident): array
{
    $stream = open_private_store(incident_store_path());
    if (!flock($stream, LOCK_EX)) {
        fclose($stream);
        throw new RuntimeException('Could not lock the incident store for writing.');
    }

    try {
        $incidents = read_incidents_store($stream, true);
        $nextNumber = 1;
        foreach ($incidents as $existingIncident) {
            if (preg_match('/\AV(\d+)\z/', $existingIncident['id'], $matches) === 1) {
                $nextNumber = max($nextNumber, (int) $matches[1] + 1);
            }
        }

        $incident['id'] = 'V' . str_pad((string) $nextNumber, 4, '0', STR_PAD_LEFT);
        $incident['status'] = 'Pending';
        $incidents[] = $incident;
        write_incidents_store($stream, $incidents);

        return $incident;
    } finally {
        flock($stream, LOCK_UN);
        fclose($stream);
    }
}

function review_incident_record(string $incidentId): bool
{
    $stream = open_private_store(incident_store_path());
    if (!flock($stream, LOCK_EX)) {
        fclose($stream);
        throw new RuntimeException('Could not lock the incident store for writing.');
    }

    try {
        $incidents = read_incidents_store($stream, true);
        foreach ($incidents as &$incident) {
            if ($incident['id'] === $incidentId && $incident['status'] === 'Pending') {
                $incident['status'] = 'Reviewed';
                unset($incident);
                write_incidents_store($stream, $incidents);
                return true;
            }
        }
        unset($incident);

        return false;
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
