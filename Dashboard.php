<?php
require_once __DIR__ . '/auth.php';
require_login();

$accounts = system_accounts();
$username = (string) ($_SESSION['username'] ?? '');
$role = (string) ($_SESSION['role'] ?? '');
if (!isset($accounts[$username]) || !in_array($role, ['admin', 'staff'], true) || $accounts[$username]['role'] !== $role) {
    $_SESSION = [];
    session_destroy();
    header('Location: login.php');
    exit;
}

$isAdmin = $role === 'admin';
$displayName = (string) ($accounts[$username]['display_name'] ?? ucfirst($username));
$incidentTypes = [
    'Bullying / harassment',
    'Theft',
    'Physical altercation',
    'Property damage',
    'Safety concern',
    'Unauthorized area / access',
    'Disruptive or unsafe behavior',
    'Other',
];
$pages = [
    'dashboard' => 'Dashboard',
    'violation-form' => 'Incident Form',
    'violation-records' => 'Incident Reports',
    'settings' => 'Settings',
];
$requestedPage = $_GET['page'] ?? 'dashboard';
$page = is_string($requestedPage) && isset($pages[$requestedPage]) ? $requestedPage : 'dashboard';
if ($page === 'settings' && !$isAdmin) {
    $page = 'dashboard';
}
$pageTitle = $pages[$page];
$notice = '';
$error = '';
$dataError = '';
$incidents = [];

try {
    $incidents = incident_records();
} catch (RuntimeException $exception) {
    error_log($exception->getMessage());
    $dataError = 'Incident records could not be loaded. Check the server data-storage configuration.';
}

if (!isset($_SESSION['system_csrf_token'])) {
    $_SESSION['system_csrf_token'] = bin2hex(random_bytes(32));
}
if (!isset($_SESSION['system_settings']) || !is_array($_SESSION['system_settings'])) {
    $_SESSION['system_settings'] = [
        'school_name' => 'Sample High School',
        'school_address' => '123 School St., Sample City',
        'academic_year' => '2025 - 2026',
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    $action = $_POST['action'] ?? '';
    if (!is_string($token) || !is_string($action) || !hash_equals($_SESSION['system_csrf_token'], $token)) {
        $error = 'Your session could not be verified. Refresh the page and try again.';
    } elseif ($action === 'record_incident') {
        $studentInput = $_POST['student_name'] ?? '';
        $typeInput = $_POST['incident_type'] ?? '';
        $locationInput = $_POST['location'] ?? '';
        $detailsInput = $_POST['incident_details'] ?? '';
        $occurrenceInput = $_POST['occurrence_at'] ?? '';
        $personInput = $_POST['person_involved'] ?? '';
        $student = is_string($studentInput) ? trim($studentInput) : '';
        $type = is_string($typeInput) ? trim($typeInput) : '';
        $location = is_string($locationInput) ? trim($locationInput) : '';
        $details = is_string($detailsInput) ? trim($detailsInput) : '';
        $occurrence = is_string($occurrenceInput) ? trim($occurrenceInput) : '';
        $personInvolved = is_string($personInput) ? trim($personInput) : '';

        if ($student === '' || !in_array($type, $incidentTypes, true) || $location === '' || $details === '' || $occurrence === '') {
            $error = 'Complete the student name or ID, incident type, date and time, location, and description.';
        } elseif (
            strlen($student) > 100
            || strlen($location) > 120
            || strlen($details) > 1000
            || strlen($personInvolved) > 100
            || strlen($occurrence) > 30
        ) {
            $error = 'One or more fields are too long. Please shorten your entry and try again.';
        } else {
            try {
                $incident = create_incident_record([
                    'student' => $student,
                    'type' => $type,
                    'location' => $location,
                    'details' => $details,
                    'person_involved' => $personInvolved,
                    'occurrence_at' => str_replace('T', ' ', $occurrence),
                    'reported_at' => date('Y-m-d g:i A'),
                    'reporter' => $displayName,
                    'submitted_by' => $username,
                ]);
                $incidents = incident_records();
                $notice = 'Incident ' . $incident['id'] . ' was submitted to Guidance.';
            } catch (RuntimeException $exception) {
                error_log($exception->getMessage());
                $error = 'The incident could not be saved. Please try again or contact an administrator.';
            }
        }
    } elseif ($action === 'review_incident') {
        $incidentId = $_POST['incident_id'] ?? '';
        try {
            if (is_string($incidentId) && review_incident_record($incidentId)) {
                $incidents = incident_records();
                $notice = 'Incident marked as reviewed.';
            } else {
                $error = 'That incident could not be found or has already been reviewed.';
            }
        } catch (RuntimeException $exception) {
            error_log($exception->getMessage());
            $error = 'The incident status could not be saved. Please try again.';
        }
    } elseif ($action === 'create_staff_account' && $isAdmin) {
        $staffUsernameInput = $_POST['staff_username'] ?? '';
        $staffNameInput = $_POST['staff_name'] ?? '';
        $staffEmailInput = $_POST['staff_email'] ?? '';
        $staffPasswordInput = $_POST['staff_password'] ?? '';
        $staffUsername = is_string($staffUsernameInput) ? trim($staffUsernameInput) : '';
        $staffName = is_string($staffNameInput) ? trim($staffNameInput) : '';
        $staffEmail = is_string($staffEmailInput) ? strtolower(trim($staffEmailInput)) : '';
        $staffPassword = is_string($staffPasswordInput) ? $staffPasswordInput : '';

        if (
            preg_match('/\A[a-zA-Z][a-zA-Z0-9._-]{2,31}\z/', $staffUsername) !== 1
            || $staffName === ''
            || strlen($staffName) > 100
            || filter_var($staffEmail, FILTER_VALIDATE_EMAIL) === false
            || strlen($staffEmail) > 254
            || strlen($staffPassword) < 12
            || strlen($staffPassword) > 72
        ) {
            $error = 'Enter a valid username, staff name, school email, and a password between 12 and 72 bytes.';
        } else {
            try {
                create_staff_account($staffUsername, $staffName, $staffEmail, $staffPassword);
                $accounts = system_accounts();
                $notice = 'Guidance staff account created. Share the initial password securely.';
            } catch (DomainException $exception) {
                $error = $exception->getMessage();
            } catch (RuntimeException $exception) {
                error_log($exception->getMessage());
                $error = 'The staff account could not be saved. Check the server data-storage configuration.';
            }
        }
    } elseif ($action === 'save_settings' && $isAdmin) {
        $schoolInput = $_POST['school_name'] ?? '';
        $addressInput = $_POST['school_address'] ?? '';
        $yearInput = $_POST['academic_year'] ?? '';
        $school = is_string($schoolInput) ? trim($schoolInput) : '';
        $address = is_string($addressInput) ? trim($addressInput) : '';
        $year = is_string($yearInput) ? trim($yearInput) : '';
        if ($school === '' || $address === '' || $year === '' || strlen($school) > 120 || strlen($address) > 200 || strlen($year) > 30) {
            $error = 'Complete the settings fields and keep them within the indicated lengths.';
        } else {
            $_SESSION['system_settings'] = [
                'school_name' => $school,
                'school_address' => $address,
                'academic_year' => $year,
            ];
            $notice = 'Settings saved successfully.';
        }
    } else {
        $error = 'That action is not permitted for this account.';
    }
}

$escape = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$csrf = $escape($_SESSION['system_csrf_token']);
$queryInput = $_GET['q'] ?? '';
$typeInput = $_GET['type'] ?? '';
$query = is_string($queryInput) ? trim($queryInput) : '';
$typeFilter = is_string($typeInput) ? trim($typeInput) : '';
$filteredIncidents = array_values(array_filter($incidents, static function ($incident) use ($query, $typeFilter): bool {
    if (!is_array($incident)) {
        return false;
    }
    $searchable = implode(' ', [
        (string) ($incident['id'] ?? ''),
        (string) ($incident['student'] ?? ''),
        (string) ($incident['type'] ?? ''),
        (string) ($incident['location'] ?? ''),
    ]);
    return ($query === '' || stripos($searchable, $query) !== false)
        && ($typeFilter === '' || ($incident['type'] ?? '') === $typeFilter);
}));
$pendingCount = count(array_filter(
    $incidents,
    static fn (array $incident): bool => ($incident['status'] ?? '') === 'Pending'
));
$currentMonth = date('Y-m');
$reportsThisMonth = count(array_filter(
    $incidents,
    static fn (array $incident): bool => is_string($incident['reported_at'] ?? null)
        && substr($incident['reported_at'], 0, 7) === $currentMonth
));
$navigation = [
    'dashboard' => 'Dashboard',
    'violation-form' => 'Incident Form',
    'violation-records' => 'Incident Reports',
];
if ($isAdmin) {
    $navigation['settings'] = 'Settings';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#eef2ed">
    <title><?= $escape($pageTitle) ?> | Incident System</title>
    <style>
        :root{color-scheme:light;font-family:Inter,"Segoe UI",Arial,sans-serif;color:#26372f;background:#eef2ed;font-synthesis:none;text-rendering:optimizeLegibility}
        *{box-sizing:border-box}
        body{min-width:320px;min-height:100vh;margin:0;background:#eef2ed}
        .app-shell{display:grid;grid-template-columns:258px minmax(0,1fr);min-height:100vh}
        .sidebar{position:fixed;inset:0 auto 0 0;z-index:10;display:flex;width:258px;height:100vh;flex-direction:column;overflow-y:auto;padding:28px 18px 20px;background:linear-gradient(180deg,#175b40 0%,#124d37 100%);color:#fff}
        .brand{display:flex;flex-direction:column;align-items:center;gap:8px;padding:0 10px 30px;color:#fff;text-align:center;text-decoration:none}
        .brand-mark{display:grid;width:104px;height:104px;flex:0 0 104px;place-items:center;overflow:hidden;border-radius:50%;background:#fff}
        .brand-mark img{width:100%;height:100%;object-fit:contain}
        .brand-name{font-size:17px;font-weight:700;line-height:1.35}
        .nav-label{margin:0 12px 10px;color:rgba(232,245,236,.68);font-size:10px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase}
        .sidebar-nav{display:grid;gap:6px}
        .nav-link{display:flex;min-height:44px;align-items:center;gap:12px;padding:0 13px;border-radius:5px;color:rgba(245,250,246,.82);font-size:13px;font-weight:600;text-decoration:none}
        .nav-link:hover{background:rgba(255,255,255,.12);color:#fff}
        .nav-link.active{background:#f2f6f1;color:#14583e;box-shadow:inset 3px 0 #a8d18c}
        .nav-icon{width:19px;height:19px;flex:0 0 19px;fill:none;stroke:currentColor;stroke-linecap:round;stroke-linejoin:round;stroke-width:1.8}
        .sidebar-spacer{flex:1;min-height:36px}
        .sidebar-logout{display:flex;min-height:44px;align-items:center;gap:12px;margin:0 8px;padding:0 12px;border:1px solid rgba(255,255,255,.22);border-radius:5px;color:#fff;font-size:13px;font-weight:600;text-decoration:none}
        .sidebar-logout:hover{background:rgba(255,255,255,.12)}
        .main-area{grid-column:2;min-width:0;padding:0 38px 40px}
        .page-content{width:min(1180px,100%);margin:0 auto}
        .page-heading{padding:54px 0 26px}
        .eyebrow{margin:0 0 8px;color:#176344;font-size:11px;font-weight:700;letter-spacing:1.4px;text-transform:uppercase}
        h1,h2,h3,p{margin-top:0}
        h1{margin-bottom:7px;color:#1d3027;font-size:clamp(25px,3vw,32px);letter-spacing:-.9px}
        .page-heading p{margin:0;color:#718078;font-size:14px;line-height:1.6}
        .notice,.error-message{margin:0 0 18px;padding:11px 13px;border:1px solid #c8dfce;border-radius:8px;background:#eef7ef;color:#286c45;font-size:12px;line-height:1.5}
        .error-message{border-color:#ebcaca;background:#fff5f4;color:#963a34}
        .stats-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px;margin-bottom:22px}
        .stat-card,.panel{border:1px solid #d5ded5;border-radius:8px;background:#fff;box-shadow:0 2px 8px rgba(31,55,39,.05)}
        .stat-card{min-height:120px;padding:18px}
        .stat-label{color:#53645a;font-size:12px;font-weight:600}
        .stat-value{margin:14px 0 0;color:#1b3127;font-size:26px;font-weight:700}
        .stat-caption{margin:4px 0 0;color:#78877d;font-size:11px}
        .panel{margin-bottom:18px;overflow:hidden}
        .panel-header{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:17px 20px;border-bottom:1px solid #e1e7e1}
        .panel-header h2{margin:0;color:#26372f;font-size:15px;font-weight:700}
        .status-pill{padding:5px 9px;border:1px solid #d6e0d6;border-radius:999px;background:#f2f6f1;color:#4b6253;font-size:10px;font-weight:600}
        .system-form{display:grid;gap:14px;padding:20px}
        .system-form label{display:block;margin-bottom:7px;color:#34483b;font-size:12px;font-weight:600}
        .system-form input,.system-form select,.system-form textarea,.toolbar input,.toolbar select{width:100%;min-height:42px;padding:9px 12px;border:1px solid #d8e1d8;border-radius:5px;outline:none;background:#fff;color:#26372f;font:inherit;font-size:13px}
        .system-form textarea{min-height:110px;resize:vertical}
        .system-form input:focus,.system-form select:focus,.system-form textarea:focus,.toolbar input:focus,.toolbar select:focus{border-color:#39805a;box-shadow:0 0 0 3px rgba(37,112,72,.13)}
        .form-button{display:inline-flex;min-height:40px;align-items:center;justify-content:center;padding:0 15px;border:0;border-radius:5px;background:#175b40;color:#fff;cursor:pointer;font:inherit;font-size:12px;font-weight:700;text-decoration:none}
        .form-button:hover{background:#104a35}
        .form-button.secondary{border:1px solid #d8e1d8;background:#fff;color:#34483b}
        .toolbar{display:flex;flex-wrap:wrap;align-items:center;gap:10px;padding:15px 20px;border-bottom:1px solid #e5ebe5}
        .toolbar input[type=search]{flex:1 1 220px;width:auto}
        .toolbar select{width:auto;min-width:160px}
        .table-wrap{width:100%;overflow-x:auto}
        .data-table{width:100%;border-collapse:collapse;text-align:left}
        .data-table th,.data-table td{padding:12px 14px;border-bottom:1px solid #e7ece7;color:#526158;font-size:12px}
        .data-table th{background:#f5f8f4;color:#43564a;font-size:10px;font-weight:700;letter-spacing:.35px;text-transform:uppercase}
        .data-table td strong{color:#26372f}
        .empty-state{padding:36px 20px;text-align:center}
        .empty-state h3{margin:0 0 8px;color:#34483b;font-size:14px}
        .empty-state p{margin:0;color:#839087;font-size:12px;line-height:1.6}
        .detail-copy{padding:18px 20px;color:#718078;font-size:12px;line-height:1.6}
        .report-tabs{display:flex;flex-wrap:wrap;gap:8px;padding:14px 20px;border-bottom:1px solid #e5ebe5}
        .report-tab{padding:8px 12px;border:1px solid #d8e1d8;border-radius:5px;color:#53665a;font-size:12px;font-weight:600;text-decoration:none}
        .report-tab.active,.report-tab:hover{border-color:#175b40;background:#175b40;color:#fff}
        @media(max-width:1050px){.app-shell{grid-template-columns:230px minmax(0,1fr)}.sidebar{width:230px}.main-area{padding-right:26px;padding-left:26px}.stats-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
        @media(max-width:720px){.app-shell{grid-template-columns:1fr}.sidebar{position:relative;width:auto;height:auto;min-height:auto;overflow:visible;padding:14px 16px 12px}.brand{padding:0 4px 13px}.brand-mark{width:64px;height:64px;flex-basis:64px}.nav-label,.sidebar-spacer{display:none}.sidebar-nav{display:flex;gap:6px;overflow-x:auto;padding-bottom:2px}.nav-link{min-height:40px;flex:0 0 auto;padding:0 10px;font-size:12px}.nav-link.active{box-shadow:inset 0 -3px #a8d18c}.sidebar-logout{min-height:40px;margin:0;padding:0 10px}.main-area{grid-column:1;padding:0 20px 28px}}
        @media(max-width:520px){.page-heading{padding:42px 0 20px}.stats-grid{grid-template-columns:1fr;gap:10px}.stat-card{min-height:100px;padding:15px 17px}.main-area{padding-right:16px;padding-left:16px}}
    </style>
</head>
<body>
<div class="app-shell">
    <aside class="sidebar" aria-label="Main navigation">
        <a class="brand" href="Dashboard.php?page=dashboard" aria-label="Incident System dashboard">
            <span class="brand-mark" aria-hidden="true"><img src="olfu%20logo%202.jpg" alt=""></span>
            <span class="brand-name">Incident<br>System</span>
        </a>
        <p class="nav-label"><?= $isAdmin ? 'Administration' : 'Guidance' ?></p>
        <nav class="sidebar-nav">
            <?php foreach ($navigation as $targetPage => $label): ?>
                <?php
                $active = $page === $targetPage;
                $icon = $targetPage === 'settings'
                    ? '<circle cx="12" cy="12" r="3"/><path d="M19 12a7 7 0 0 0-.1-1.2l1.4-1.1-1.4-2.4-1.7.6a7 7 0 0 0-2.1-1.2L14.8 5h-2.8l-.3 1.7a7 7 0 0 0-2.1 1.2l-1.7-.6-1.4 2.4 1.4 1.1a7 7 0 0 0 0 2.4l-1.4 1.1 1.4 2.4 1.7-.6a7 7 0 0 0 2.1 1.2l.3 1.7h2.8l.3-1.7a7 7 0 0 0 2.1-1.2l1.7.6 1.4-2.4-1.4-1.1A7 7 0 0 0 19 12Z"/>'
                    : ($targetPage === 'violation-form'
                        ? '<path d="M12 3 2.8 19h18.4L12 3Z"/><path d="M12 9v4M12 16.5h.01"/>'
                        : '<path d="M4 5h16v15H4z"/><path d="M8 9h8M8 13h8M8 17h5"/>');
                ?>
                <a class="nav-link<?= $active ? ' active' : '' ?>" href="Dashboard.php?page=<?= rawurlencode($targetPage) ?>"<?= $active ? ' aria-current="page"' : '' ?>>
                    <svg class="nav-icon" viewBox="0 0 24 24" aria-hidden="true"><?= $icon ?></svg><?= $escape($label) ?>
                </a>
            <?php endforeach; ?>
        </nav>
        <div class="sidebar-spacer"></div>
        <a class="sidebar-logout" href="logout.php"><svg class="nav-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M10 17 15 12 10 7M15 12H3M12 3h6a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-6"/></svg>Sign out</a>
    </aside>

    <main class="main-area">
        <div class="page-content">
            <section class="page-heading">
                <p class="eyebrow">Incident System</p>
                <h1><?= $escape($pageTitle) ?></h1>
                <p><?= $page === 'dashboard' ? 'Submit and manage incident reports for Guidance.' : 'Shared incident reporting for administrators and Guidance staff.' ?></p>
            </section>
            <?php if ($notice !== ''): ?><p class="notice" role="status"><?= $escape($notice) ?></p><?php endif; ?>
            <?php if ($error !== ''): ?><p class="error-message" role="alert"><?= $escape($error) ?></p><?php endif; ?>
            <?php if ($dataError !== ''): ?><p class="error-message" role="alert"><?= $escape($dataError) ?></p><?php endif; ?>

            <?php if ($page === 'dashboard'): ?>
                <div class="stats-grid">
                    <article class="stat-card"><span class="stat-label">Total incident reports</span><p class="stat-value"><?= count($incidents) ?></p><p class="stat-caption">Shared across the system</p></article>
                    <article class="stat-card"><span class="stat-label">Pending Guidance review</span><p class="stat-value"><?= $pendingCount ?></p><p class="stat-caption">Awaiting review</p></article>
                    <article class="stat-card"><span class="stat-label">Reports This Month</span><p class="stat-value"><?= $reportsThisMonth ?></p><p class="stat-caption">Submitted during <?= $escape(date('F Y')) ?></p></article>
                </div>
                <section class="panel">
                    <div class="panel-header"><h2>Quick actions</h2></div>
                    <div class="report-tabs">
                        <a class="report-tab" href="Dashboard.php?page=violation-form">Submit an incident</a>
                        <a class="report-tab" href="Dashboard.php?page=violation-records">View incident reports</a>
                        <?php if ($isAdmin): ?><a class="report-tab" href="Dashboard.php?page=settings&amp;tab=users">Manage Guidance accounts</a><?php endif; ?>
                    </div>
                </section>
                <section class="panel">
                    <div class="panel-header"><h2>Recent incidents</h2><span class="status-pill"><?= count($incidents) ?> total</span></div>
                    <?php if ($incidents === []): ?>
                        <div class="empty-state"><h3>No incidents submitted yet</h3><p>Use the incident form to submit a report to Guidance.</p></div>
                    <?php else: ?>
                        <div class="table-wrap"><table class="data-table"><thead><tr><th>ID</th><th>Student</th><th>Incident</th><th>Reported</th><th>Status</th></tr></thead><tbody>
                            <?php foreach (array_slice(array_reverse($incidents), 0, 5) as $incident): ?>
                                <tr><td><?= $escape($incident['id']) ?></td><td><strong><?= $escape($incident['student']) ?></strong></td><td><?= $escape($incident['type']) ?></td><td><?= $escape($incident['reported_at']) ?></td><td><?= $escape($incident['status']) ?></td></tr>
                            <?php endforeach; ?>
                        </tbody></table></div>
                    <?php endif; ?>
                </section>

            <?php elseif ($page === 'violation-form'): ?>
                <section class="panel">
                    <div class="panel-header"><h2>Submit incident to Guidance</h2><span class="status-pill">Shared form</span></div>
                    <form class="system-form" method="post" action="Dashboard.php?page=violation-form">
                        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                        <div><label for="occurrence-at">Date and time of incident</label><input id="occurrence-at" name="occurrence_at" type="datetime-local" required></div>
                        <div><label for="student-name">Student name or ID</label><input id="student-name" name="student_name" maxlength="100" required placeholder="Enter the student's name or ID"></div>
                        <div><label for="incident-type">Incident type</label><select id="incident-type" name="incident_type" required><option value="">Select incident type</option><?php foreach ($incidentTypes as $type): ?><option value="<?= $escape($type) ?>"><?= $escape($type) ?></option><?php endforeach; ?></select></div>
                        <div><label for="location">Location</label><input id="location" name="location" maxlength="120" required placeholder="Where did this happen?"></div>
                        <div><label for="incident-details">Description</label><textarea id="incident-details" name="incident_details" maxlength="1000" required placeholder="Describe what happened and include relevant facts"></textarea></div>
                        <div><label for="person-involved">Person(s) involved (optional)</label><input id="person-involved" name="person_involved" maxlength="100" placeholder="Add a name or description if relevant"></div>
                        <button class="form-button" name="action" value="record_incident">Submit</button>
                    </form>
                </section>

            <?php elseif ($page === 'violation-records'): ?>
                <section class="panel">
                    <div class="panel-header"><h2>Incident reports</h2><span class="status-pill"><?= count($filteredIncidents) ?> shown</span></div>
                    <form class="toolbar" method="get" action="Dashboard.php">
                        <input type="hidden" name="page" value="violation-records">
                        <input type="search" name="q" value="<?= $escape($query) ?>" placeholder="Search ID, student, incident, or location" aria-label="Search incidents">
                        <select name="type" aria-label="Filter incident type"><option value="">All incident types</option><?php foreach ($incidentTypes as $type): ?><option value="<?= $escape($type) ?>"<?= $typeFilter === $type ? ' selected' : '' ?>><?= $escape($type) ?></option><?php endforeach; ?></select>
                        <button class="form-button">Search</button>
                        <a class="form-button secondary" href="Dashboard.php?page=violation-form">Submit incident</a>
                    </form>
                    <?php if ($filteredIncidents === []): ?>
                        <div class="empty-state"><h3>No incident reports found</h3><p>No records match the current filters.</p></div>
                    <?php else: ?>
                        <div class="table-wrap"><table class="data-table">
                            <thead><tr><th>ID</th><th>Date and time</th><th>Student</th><th>Incident</th><th>Location</th><th>Submitted by</th><th>Status</th><th>Action</th></tr></thead>
                            <tbody>
                                <?php foreach (array_reverse($filteredIncidents) as $incident): ?>
                                    <tr>
                                        <td><?= $escape($incident['id']) ?></td>
                                        <td><?= $escape($incident['occurrence_at']) ?></td>
                                        <td><strong><?= $escape($incident['student']) ?></strong></td>
                                        <td><?= $escape($incident['type']) ?></td>
                                        <td><?= $escape($incident['location']) ?></td>
                                        <td><?= $escape($incident['reporter']) ?></td>
                                        <td><?= $escape($incident['status']) ?></td>
                                        <td><?php if ($incident['status'] === 'Pending'): ?><form method="post" action="Dashboard.php?page=violation-records"><input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="incident_id" value="<?= $escape($incident['id']) ?>"><button class="form-button secondary" name="action" value="review_incident">Mark reviewed</button></form><?php else: ?>—<?php endif; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table></div>
                    <?php endif; ?>
                </section>

            <?php elseif ($page === 'settings' && $isAdmin): ?>
                <?php $settingsTabInput = $_GET['tab'] ?? 'system'; $settingsTab = is_string($settingsTabInput) && in_array($settingsTabInput, ['system', 'users'], true) ? $settingsTabInput : 'system'; ?>
                <section class="panel">
                    <div class="panel-header"><h2>Admin settings</h2><span class="status-pill">Admin only</span></div>
                    <nav class="report-tabs" aria-label="Settings categories">
                        <a class="report-tab<?= $settingsTab === 'system' ? ' active' : '' ?>" href="Dashboard.php?page=settings&amp;tab=system">System</a>
                        <a class="report-tab<?= $settingsTab === 'users' ? ' active' : '' ?>" href="Dashboard.php?page=settings&amp;tab=users">Guidance users</a>
                    </nav>
                    <?php if ($settingsTab === 'system'): ?>
                        <form class="system-form" method="post" action="Dashboard.php?page=settings&amp;tab=system">
                            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                            <div><label for="school-name">School name</label><input id="school-name" name="school_name" maxlength="120" value="<?= $escape($_SESSION['system_settings']['school_name']) ?>" required></div>
                            <div><label for="school-address">School address</label><input id="school-address" name="school_address" maxlength="200" value="<?= $escape($_SESSION['system_settings']['school_address']) ?>" required></div>
                            <div><label for="academic-year">Academic year</label><input id="academic-year" name="academic_year" maxlength="30" value="<?= $escape($_SESSION['system_settings']['academic_year']) ?>" required></div>
                            <button class="form-button" name="action" value="save_settings">Save settings</button>
                        </form>
                    <?php else: ?>
                        <div class="panel-header"><h2>Add Guidance staff account</h2></div>
                        <p class="detail-copy">Only Admin can create accounts. Guidance staff can submit incident reports and review the shared reports.</p>
                        <form class="system-form" method="post" action="Dashboard.php?page=settings&amp;tab=users">
                            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                            <div><label for="staff-name">Staff member's full name</label><input id="staff-name" name="staff_name" maxlength="100" autocomplete="name" required></div>
                            <div><label for="staff-username">Username</label><input id="staff-username" name="staff_username" minlength="3" maxlength="32" pattern="[A-Za-z][A-Za-z0-9._-]*" autocomplete="username" required></div>
                            <div><label for="staff-email">School email</label><input id="staff-email" name="staff_email" type="email" maxlength="254" autocomplete="email" required></div>
                            <div><label for="staff-password">Initial password</label><input id="staff-password" name="staff_password" type="password" minlength="12" maxlength="72" autocomplete="new-password" required><small>Use 12-72 bytes. Passwords are stored as hashes.</small></div>
                            <button class="form-button" name="action" value="create_staff_account">Create Guidance account</button>
                        </form>
                        <div class="table-wrap"><table class="data-table"><thead><tr><th>Name</th><th>Username</th><th>School email</th><th>Role</th></tr></thead><tbody>
                            <?php foreach ($accounts as $accountName => $account): ?>
                                <tr><td><strong><?= $escape($account['display_name'] ?? ucfirst($accountName)) ?></strong></td><td><?= $escape($accountName) ?></td><td><?= $escape($account['login_identifier']) ?></td><td><?= $account['role'] === 'admin' ? 'Admin' : 'Guidance Staff' ?></td></tr>
                            <?php endforeach; ?>
                        </tbody></table></div>
                    <?php endif; ?>
                </section>
            <?php endif; ?>
        </div>
    </main>
</div>
</body>
</html>
