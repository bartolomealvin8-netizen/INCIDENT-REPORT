<?php
require_once __DIR__ . '/auth.php';
require_login();

$accounts = system_accounts();
$username = (string) ($_SESSION['username'] ?? 'admin');
$role = (string) ($_SESSION['role'] ?? 'admin');
$studentIdentifier = (string) ($accounts[$username]['login_identifier'] ?? $username);
$roleNames = [
    'admin' => 'Admin',
    'supervisor' => 'Supervisor',
    'guard' => 'Guard',
    'staff' => 'Registrar / Staff',
    'student' => 'Student',
];
$roleName = $roleNames[$role] ?? 'User';
$isAdmin = $role === 'admin';
$isSupervisor = $role === 'supervisor';
$isGuard = $role === 'guard';
$isStaff = $role === 'staff';
$isStudent = $role === 'student';
$canViewGuardMonitoring = $isAdmin || $isSupervisor;
$canReviewViolations = $isAdmin || $isSupervisor;
$canRecordViolations = $isGuard || $isStaff || $isStudent;
$canSeeAllStudentRecords = $isAdmin || $isSupervisor || $isStaff || $isStudent;
$guardViolationTypes = [
    'Uniform / dress code',
    'Physical altercation',
    'Bullying / harassment concern',
    'Property damage',
    'Unauthorized area / access',
    'Disruptive or unsafe behavior',
    'Other',
];
$studentIncidentTypes = ['Bullying', 'Theft', 'Harassment', 'Safety concern', 'Other'];
$incidentTypes = array_values(array_unique(array_merge($guardViolationTypes, $studentIncidentTypes)));

$pageNames = [
    'dashboard' => 'Dashboard',
    'guards' => $isAdmin ? 'Guard Oversight' : 'Guard Monitoring',
    'guard-details' => 'Guard Details',
    'my-shift' => 'Duty Log',
    'violation-form' => $isStudent ? 'Report an Incident' : 'Report a Violation',
    'violation-records' => 'Violation Records',
    'student-records' => $isStudent ? 'My Reports' : 'Student Violation Records',
    'reports' => 'Reports',
    'settings' => 'Settings',
];
$pageAccess = [
    'guards' => $canViewGuardMonitoring,
    'guard-details' => $canViewGuardMonitoring,
    'my-shift' => $isGuard,
    'violation-form' => $canRecordViolations,
    'violation-records' => $canReviewViolations || $isStaff || $isGuard,
    'student-records' => $canSeeAllStudentRecords || $isStudent,
    'reports' => !$isStudent && ($canReviewViolations || $isStaff),
    'settings' => $isAdmin,
];
$requestedPage = $_GET['page'] ?? 'dashboard';
$page = is_string($requestedPage) && isset($pageNames[$requestedPage]) ? $requestedPage : 'dashboard';
if (isset($pageAccess[$page]) && !$pageAccess[$page]) {
    $page = 'dashboard';
}
$pageTitle = $pageNames[$page];
$notice = '';
$error = '';
$attendanceError = '';
$attendanceData = ['current' => [], 'events' => []];
try {
    $attendanceData = guard_attendance();
} catch (RuntimeException $exception) {
    error_log($exception->getMessage());
    $attendanceError = 'Shared attendance could not be loaded. Check the server data-storage configuration.';
}

if (!isset($_SESSION['system_csrf_token'])) {
    $_SESSION['system_csrf_token'] = bin2hex(random_bytes(32));
}
if (!isset($_SESSION['system_violations']) || !is_array($_SESSION['system_violations'])) {
    $_SESSION['system_violations'] = [];
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
    } elseif ($action === 'check_in' && $isGuard) {
        try {
            $attendanceData = record_guard_attendance($username, $action);
            $notice = 'Check-in recorded successfully.';
        } catch (DomainException $exception) {
            $error = $exception->getMessage();
        } catch (RuntimeException $exception) {
            error_log($exception->getMessage());
            $error = 'Your check-in could not be saved. Please try again or contact an administrator.';
        }
    } elseif ($action === 'check_out' && $isGuard) {
        try {
            $attendanceData = record_guard_attendance($username, $action);
            $notice = 'Check-out recorded successfully.';
        } catch (DomainException $exception) {
            $error = $exception->getMessage();
        } catch (RuntimeException $exception) {
            error_log($exception->getMessage());
            $error = 'Your check-out could not be saved. Please try again or contact an administrator.';
        }
    } elseif ($action === 'record_violation' && $canRecordViolations) {
        $student = $_POST['student_name'] ?? '';
        $type = $_POST['violation_type'] ?? '';
        $location = $_POST['location'] ?? '';
        $details = $_POST['violation_details'] ?? '';
        $occurrence = $_POST['occurrence_at'] ?? '';
        $personInvolved = $_POST['person_involved'] ?? '';
        $anonymous = $isStudent && ($_POST['submit_anonymously'] ?? '') === '1';
        $student = is_string($student) ? trim($student) : '';
        $type = is_string($type) ? trim($type) : '';
        $location = is_string($location) ? trim($location) : '';
        $details = is_string($details) ? trim($details) : '';
        $occurrence = is_string($occurrence) ? trim($occurrence) : '';
        $personInvolved = is_string($personInvolved) ? trim($personInvolved) : '';
        $types = $isStudent ? $studentIncidentTypes : $guardViolationTypes;
        if ($student === '' || !in_array($type, $types, true) || $location === '' || $details === '') {
            $error = 'Complete the student, violation type, location, and details fields.';
        } elseif (strlen($student) > 100 || strlen($location) > 120 || strlen($details) > 1000 || strlen($personInvolved) > 100) {
            $error = 'One or more fields are too long. Please shorten your entry and try again.';
        } else {
            $_SESSION['system_violations'][] = [
                'id' => 'V' . str_pad((string) (count($_SESSION['system_violations']) + 1), 3, '0', STR_PAD_LEFT),
                'student' => $student,
                'type' => $type,
                'location' => $location,
                'details' => $details,
                'person_involved' => $personInvolved,
                'occurrence_at' => $occurrence !== '' ? str_replace('T', ' ', $occurrence) : date('Y-m-d H:i'),
                'reported_at' => date('Y-m-d g:i A'),
                'reporter' => $anonymous ? 'Anonymous' : $username,
                'submitted_by' => $username,
                'anonymous' => $anonymous,
                'status' => 'Pending',
            ];
            $notice = 'Incident report submitted successfully.';
        }
    } elseif ($action === 'review_violation' && $canReviewViolations) {
        $violationId = $_POST['violation_id'] ?? '';
        $found = false;
        foreach ($_SESSION['system_violations'] as &$violation) {
            if (is_array($violation) && is_string($violationId) && ($violation['id'] ?? '') === $violationId) {
                $violation['status'] = 'Reviewed';
                $found = true;
                break;
            }
        }
        unset($violation);
        if ($found) {
            $notice = 'Report marked as reviewed.';
        } else {
            $error = 'That report could not be found.';
        }
    } elseif ($action === 'create_guard_account' && $isAdmin) {
        $guardUsernameInput = $_POST['guard_username'] ?? '';
        $guardNameInput = $_POST['guard_name'] ?? '';
        $guardEmailInput = $_POST['guard_email'] ?? '';
        $guardPasswordInput = $_POST['guard_password'] ?? '';
        $guardUsername = is_string($guardUsernameInput) ? trim($guardUsernameInput) : '';
        $guardName = is_string($guardNameInput) ? trim($guardNameInput) : '';
        $guardEmail = is_string($guardEmailInput) ? strtolower(trim($guardEmailInput)) : '';
        $guardPassword = is_string($guardPasswordInput) ? $guardPasswordInput : '';

        if (
            preg_match('/\A[a-zA-Z][a-zA-Z0-9._-]{2,31}\z/', $guardUsername) !== 1
            || $guardName === ''
            || strlen($guardName) > 100
            || filter_var($guardEmail, FILTER_VALIDATE_EMAIL) === false
            || strlen($guardEmail) > 254
            || strlen($guardPassword) < 12
            || strlen($guardPassword) > 72
        ) {
            $error = 'Enter a valid username, guard name, school email, and a password between 12 and 72 bytes.';
        } else {
            try {
                $guardId = create_guard_account($guardUsername, $guardName, $guardEmail, $guardPassword);
                $accounts = system_accounts();
                $notice = "Guard account created successfully (ID: {$guardId}). Share the initial password with the guard securely.";
            } catch (DomainException $exception) {
                $error = $exception->getMessage();
            } catch (RuntimeException $exception) {
                error_log($exception->getMessage());
                $error = 'The guard account could not be saved. Check the server account-storage configuration and try again.';
            }
        }
    } elseif ($action === 'save_settings' && $isAdmin) {
        $school = $_POST['school_name'] ?? '';
        $address = $_POST['school_address'] ?? '';
        $year = $_POST['academic_year'] ?? '';
        $school = is_string($school) ? trim($school) : '';
        $address = is_string($address) ? trim($address) : '';
        $year = is_string($year) ? trim($year) : '';
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
    } elseif ($action === 'generate_report' && ($canReviewViolations || $isStaff)) {
        $type = $_POST['report_type'] ?? '';
        $allowedReportTypes = $canReviewViolations ? ['violations', 'attendance', 'summary'] : ['violations', 'summary'];
        if (!is_string($type) || !in_array($type, $allowedReportTypes, true)) {
            $error = 'Select a valid report type.';
        } else {
            $_SESSION['system_report'] = [
                'type' => $type,
                'generated_at' => date('M j, Y g:i A'),
                'count' => count($_SESSION['system_violations']),
            ];
            $notice = 'Report generated successfully.';
        }
    } else {
        $error = 'That action is not permitted for this account.';
    }
}

$violations = $_SESSION['system_violations'];
$guardAttendance = $attendanceData['current'][$username] ?? [];
$isCheckedIn = ($guardAttendance['status'] ?? '') === 'on_duty';
$csrf = htmlspecialchars((string) $_SESSION['system_csrf_token'], ENT_QUOTES, 'UTF-8');
$safeUsername = htmlspecialchars($username, ENT_QUOTES, 'UTF-8');
$initial = htmlspecialchars(strtoupper(substr($username, 0, 1)), ENT_QUOTES, 'UTF-8');
$query = $_GET['q'] ?? '';
$typeFilter = $_GET['type'] ?? '';
$query = is_string($query) ? trim($query) : '';
$typeFilter = is_string($typeFilter) ? trim($typeFilter) : '';
$filteredViolations = array_values(array_filter($violations, static function ($item) use ($query, $typeFilter): bool {
    if (!is_array($item)) {
        return false;
    }
    $text = implode(' ', [(string) ($item['id'] ?? ''), (string) ($item['student'] ?? ''), (string) ($item['type'] ?? ''), (string) ($item['location'] ?? '')]);
    return ($query === '' || stripos($text, $query) !== false) && ($typeFilter === '' || ($item['type'] ?? '') === $typeFilter);
}));
$studentQuery = $_GET['student'] ?? '';
$studentQuery = is_string($studentQuery) ? trim($studentQuery) : '';
$studentViolations = array_values(array_filter($violations, static function ($item) use ($studentQuery, $isStudent, $username, $studentIdentifier): bool {
    if (!is_array($item)) {
        return false;
    }
    if ($isStudent) {
        $isAboutStudent = strcasecmp((string) ($item['student'] ?? ''), $studentIdentifier) === 0;
        $wasSubmittedByStudent = strcasecmp((string) ($item['submitted_by'] ?? $item['reporter'] ?? ''), $username) === 0;
        return $isAboutStudent || $wasSubmittedByStudent;
    }
    return $studentQuery === '' || strcasecmp((string) ($item['student'] ?? ''), $studentQuery) === 0;
}));
$guardSearch = $_GET['guard_q'] ?? '';
$guardSearch = is_string($guardSearch) ? trim($guardSearch) : '';
$settingsTab = $_GET['tab'] ?? 'system';
$settingsTab = is_string($settingsTab) && in_array($settingsTab, ['system', 'users', 'areas'], true) ? $settingsTab : 'system';
$systemGuards = [];
foreach ($accounts as $systemName => $systemAccount) {
    if ($systemAccount['role'] === 'guard') {
        $systemGuards[] = [
            'username' => $systemName,
            'name' => $systemAccount['display_name'] ?? ucfirst($systemName),
            'id' => $systemAccount['guard_id'] ?? 'G001',
            'post' => 'Unassigned',
        ];
    }
}
$filteredGuards = array_values(array_filter($systemGuards, static function ($guard) use ($guardSearch): bool {
    return $guardSearch === '' || stripos(implode(' ', $guard), $guardSearch) !== false;
}));
$guardAttendanceEvents = array_values(array_filter(
    $attendanceData['events'],
    static fn ($event): bool => is_array($event) && ($event['username'] ?? '') === $username
));
$guardAttendanceEvents = array_slice(array_reverse($guardAttendanceEvents), 0, 10);
$pendingCount = count(array_filter($violations, static function ($item): bool {
    return is_array($item) && ($item['status'] ?? '') === 'Pending';
}));
$navItems = [];
$navItems[] = ['dashboard', 'Dashboard'];
if ($isStudent) {
    $navItems[] = ['violation-form', 'Report an Incident'];
    $navItems[] = ['student-records', 'My Reports'];
} else {
    if ($canViewGuardMonitoring) {
        $navItems[] = ['guards', $isAdmin ? 'Guard Oversight' : 'Guard Monitoring'];
    }
    if ($isGuard) {
        $navItems[] = ['my-shift', 'Duty Log'];
    }
    if ($canRecordViolations) {
        $navItems[] = ['violation-form', $isGuard ? 'Report Violation' : 'Add Violation'];
    }
    if ($canReviewViolations || $isStaff || $isGuard) {
        $navItems[] = ['violation-records', $isGuard ? 'My Reports' : 'Violation Records'];
    }
    if ($canSeeAllStudentRecords || $isStudent) {
        $navItems[] = ['student-records', $isStudent ? 'My Records' : 'Student Records'];
    }
    if ($canReviewViolations || $isStaff) {
        $navItems[] = ['reports', 'Reports'];
    }
    if ($isAdmin) {
        $navItems[] = ['settings', 'Settings'];
    }
}
$esc = static function ($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f3f6fb">
    <title><?= $esc($pageTitle) ?> | Incident System</title>
    <style>
        :root{color-scheme:light;font-family:Inter,"Segoe UI",Arial,sans-serif;color:#17243b;background:#f3f6fb;font-synthesis:none;text-rendering:optimizeLegibility}
        *{box-sizing:border-box}
        body{min-width:320px;min-height:100vh;margin:0;background:#f3f6fb}
        .app-shell{display:grid;grid-template-columns:258px minmax(0,1fr);min-height:100vh}
        .sidebar{position:fixed;top:0;left:0;z-index:10;display:flex;width:258px;height:100vh;flex-direction:column;overflow-y:auto;padding:28px 18px 20px;background:linear-gradient(180deg,#173b70 0%,#204f8d 100%);color:#fff}
        .brand{display:flex;align-items:center;gap:12px;padding:0 10px 30px;color:#fff;text-decoration:none}
        .brand-mark{display:grid;width:44px;height:44px;flex:0 0 44px;place-items:center;border:1px solid rgba(255,255,255,.34);border-radius:13px;background:rgba(255,255,255,.12)}
        .brand-mark svg{width:27px;height:27px}
        .brand-name{font-size:15px;font-weight:700;letter-spacing:-.2px;line-height:1.35}
        .nav-label{margin:0 12px 10px;color:rgba(255,255,255,.58);font-size:10px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase}
        .sidebar-nav{display:grid;gap:6px}
        .nav-link{display:flex;min-height:44px;align-items:center;gap:12px;padding:0 13px;border-radius:8px;color:rgba(255,255,255,.78);font-size:13px;font-weight:600;text-decoration:none;transition:background-color 150ms ease,color 150ms ease}
        .nav-link:hover,.nav-link.active{background:rgba(255,255,255,.14);color:#fff}
        .nav-link.active{box-shadow:inset 3px 0 #79adff}
        .nav-icon{width:19px;height:19px;flex:0 0 19px;fill:none;stroke:currentColor;stroke-linecap:round;stroke-linejoin:round;stroke-width:1.8}
        .sidebar-spacer{flex:1;min-height:36px}
        .sidebar-note{margin:0 8px 18px;padding:14px;border:1px solid rgba(255,255,255,.13);border-radius:10px;background:rgba(255,255,255,.07);color:rgba(255,255,255,.72);font-size:11px;line-height:1.55}
        .sidebar-note strong{display:block;margin-bottom:4px;color:#fff;font-size:12px}
        .sidebar-account{display:flex;min-width:0;align-items:center;gap:11px;margin:0 8px 10px;padding:12px;border:1px solid rgba(255,255,255,.18);border-radius:8px;background:rgba(255,255,255,.09);color:#fff}
        .sidebar-account-avatar{display:grid;width:38px;height:38px;flex:0 0 38px;place-items:center;border:1px solid rgba(255,255,255,.32);border-radius:50%;background:rgba(255,255,255,.14);color:#fff;font-size:13px;font-weight:700}
        .sidebar-account-text{min-width:0;overflow-wrap:anywhere;font-size:13px;font-weight:600}
        .sidebar-account-role{display:block;margin-top:3px;color:rgba(255,255,255,.68);font-size:10px;font-weight:500}
        .sidebar-logout{display:flex;min-height:44px;align-items:center;gap:12px;margin:0 8px;padding:0 12px;border:1px solid rgba(255,255,255,.22);border-radius:8px;color:#fff;font-size:13px;font-weight:600;text-decoration:none}
        .sidebar-logout:hover{background:rgba(255,255,255,.12)}
        .main-area{grid-column:2;min-width:0;padding:0 38px 40px}
        .page-content{width:min(1180px,100%);margin:0 auto}
        .page-heading{display:flex;align-items:flex-end;justify-content:space-between;gap:24px;padding:54px 0 26px}
        .eyebrow{margin:0 0 8px;color:#3974c4;font-size:11px;font-weight:700;letter-spacing:1.4px;text-transform:uppercase}
        h1,h2,h3,p{margin-top:0}
        h1{margin-bottom:7px;color:#17243b;font-size:clamp(25px,3vw,32px);letter-spacing:-.9px}
        .page-heading p{margin:0;color:#718096;font-size:14px;line-height:1.6}
        .date-label{padding:9px 12px;border:1px solid #e4eaf2;border-radius:8px;background:#fff;color:#64748b;font-size:12px;white-space:nowrap}
        .system-banner{display:flex;align-items:flex-start;gap:12px;margin-bottom:22px;padding:14px 16px;border:1px solid #d8e5f5;border-radius:10px;background:#f4f8fd;color:#526782;font-size:12px;line-height:1.6}
        .system-banner strong{display:block;margin-bottom:2px;color:#263650;font-size:13px}
        .info-icon{display:grid;width:22px;height:22px;flex:0 0 22px;place-items:center;border-radius:50%;background:#e0ecfb;color:#2866b5;font-size:13px;font-weight:700}
        .notice,.error-message{margin:0 0 18px;padding:11px 13px;border:1px solid #c9e8d4;border-radius:8px;background:#f1fbf4;color:#287144;font-size:12px;line-height:1.5}
        .error-message{border-color:#f1c9c9;background:#fff5f5;color:#a33232}
        .section-title{margin:0 0 14px;color:#263650;font-size:15px;font-weight:700}
        .stats-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px;margin-bottom:22px}
        .stat-card,.panel{border:1px solid #1b1b1b;border-radius:12px;background:#fff;box-shadow:0 5px 18px rgba(0,0,0,.08)}
        .stat-card{min-height:128px;padding:18px}
        .stat-topline{display:flex;align-items:center;justify-content:space-between;gap:12px}
        .stat-label{color:#4b4b4b;font-size:12px;font-weight:600}
        .stat-icon{display:grid;width:34px;height:34px;place-items:center;border-radius:9px;background:#111;color:#fff}
        .stat-icon svg{width:18px;height:18px;fill:none;stroke:currentColor;stroke-linecap:round;stroke-linejoin:round;stroke-width:1.8}
        .stat-value{margin:12px 0 0;color:#111;font-size:26px;font-weight:700;letter-spacing:-.8px}
        .stat-caption{margin:3px 0 0;color:#666;font-size:11px}
        .content-grid{display:grid;grid-template-columns:minmax(0,1.2fr) minmax(280px,.8fr);gap:18px;align-items:start}
        .panel{margin-bottom:18px;overflow:hidden}
        .panel-header{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:17px 20px;border-bottom:1px solid #d6d6d6}
        .panel-header h2{margin:0;color:#111;font-size:14px;font-weight:700}
        .status-pill{padding:5px 9px;border:1px solid #bdbdbd;border-radius:999px;background:#f2f2f2;color:#333;font-size:10px;font-weight:600}
        .monitoring-content{padding:18px 20px 20px}
        .monitoring-status{display:flex;align-items:center;gap:10px;padding:12px;border:1px solid #e4eaf2;border-radius:9px;background:#f8fafc;color:#526782;font-size:12px;line-height:1.5}
        .status-dot{width:9px;height:9px;flex:0 0 9px;border-radius:50%;background:#94a3b8}
        .monitoring-content p,.subtle-copy{margin:13px 0 0;color:#8995a6;font-size:11px;line-height:1.6}
        .system-form{display:grid;gap:14px;padding:20px}
        .system-form label{display:block;margin-bottom:7px;color:#263650;font-size:12px;font-weight:600}
        .system-form input,.system-form select,.system-form textarea,.toolbar input,.toolbar select{width:100%;min-height:41px;padding:9px 12px;border:1px solid #d9e1eb;border-radius:8px;outline:none;background:#fff;color:#17243b;font:inherit;font-size:13px}
        .system-form input[type=checkbox]{width:16px;height:16px;min-height:16px;margin:0 8px 0 0;padding:0;accent-color:#175b40;vertical-align:middle}
        .system-form textarea{min-height:100px;resize:vertical}
        .system-form input:focus,.system-form select:focus,.system-form textarea:focus,.toolbar input:focus,.toolbar select:focus{border-color:#3974c4;box-shadow:0 0 0 3px rgba(57,116,196,.14)}
        .form-button{display:inline-flex;min-height:40px;align-items:center;justify-content:center;padding:0 15px;border:0;border-radius:8px;background:#2866b5;color:#fff;cursor:pointer;font:inherit;font-size:12px;font-weight:700;text-decoration:none}
        .form-button:hover{background:#20569b}
        .form-button.secondary{border:1px solid #d9e1eb;background:#fff;color:#263650}
        .form-button.secondary:hover{background:#f3f6fb}
        .form-button:disabled{background:#aab4c1;cursor:not-allowed}
        .toolbar{display:flex;flex-wrap:wrap;align-items:center;gap:10px;padding:15px 20px;border-bottom:1px solid #edf0f5}
        .toolbar input[type=search]{flex:1 1 220px;width:auto}
        .toolbar select{width:auto;min-width:130px}
        .table-wrap{width:100%;overflow-x:auto}
        .data-table{width:100%;border-collapse:collapse;text-align:left}
        .data-table th,.data-table td{padding:12px 14px;border-bottom:1px solid #edf0f5;color:#4b5563;font-size:12px;white-space:nowrap}
        .data-table th{background:#f8fafc;color:#263650;font-size:10px;font-weight:700;letter-spacing:.35px;text-transform:uppercase}
        .data-table tr:last-child td{border-bottom:0}
        .data-table td strong{color:#17243b;font-weight:600}
        .table-link{color:#2866b5;font-size:11px;font-weight:700;text-decoration:none}
        .table-link:hover{text-decoration:underline}
        .empty-state{display:grid;min-height:180px;align-content:center;justify-items:center;padding:24px;text-align:center}
        .empty-state-icon{display:grid;width:44px;height:44px;margin-bottom:12px;place-items:center;border-radius:13px;background:#111;color:#fff}
        .empty-state-icon svg{width:23px;height:23px;fill:none;stroke:currentColor;stroke-linecap:round;stroke-linejoin:round;stroke-width:1.7}
        .empty-state h3{margin:0 0 5px;color:#263650;font-size:13px}
        .empty-state p{max-width:430px;margin:0;color:#8995a6;font-size:12px;line-height:1.6}
        .detail-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;padding:20px}
        .detail-item{padding:14px;border:1px solid #e4eaf2;border-radius:9px;background:#fff}
        .detail-item span{display:block;margin-bottom:6px;color:#8995a6;font-size:10px;font-weight:700;text-transform:uppercase}
        .detail-item strong{color:#263650;font-size:13px;font-weight:600}
        .report-tabs{display:flex;flex-wrap:wrap;gap:8px;padding:16px 20px 0}
        .report-tab{padding:9px 12px;border:1px solid #d9e1eb;border-radius:7px;color:#526782;font-size:11px;font-weight:600;text-decoration:none}
        .report-tab.active,.report-tab:hover{border-color:#2866b5;background:#2866b5;color:#fff}
        .account-list{display:grid;gap:0}
        .account-row{display:flex;align-items:center;gap:11px;padding:14px 20px;border-bottom:1px solid #edf0f5}
        .account-row:last-child{border-bottom:0}
        .account-avatar{display:grid;width:36px;height:36px;flex:0 0 36px;place-items:center;border-radius:50%;background:#edf4fd;color:#2866b5;font-size:12px;font-weight:700}
        .account-name{color:#263650;font-size:12px;font-weight:600}
        .account-role{display:block;margin-top:3px;color:#8995a6;font-size:10px}
        .account-status{margin-left:auto;color:#8995a6;font-size:10px}
        .incident-item{padding:15px 20px;border-bottom:1px solid #edf0f5}
        .incident-item:last-child{border-bottom:0}
        .incident-item h3{margin:0 0 5px;color:#263650;font-size:13px}
        .incident-item p{margin:0;color:#718096;font-size:12px;line-height:1.55;overflow-wrap:anywhere}
        .incident-item time{display:block;margin-top:8px;color:#8995a6;font-size:10px}
        .back-link{color:#2866b5;font-size:12px;font-weight:600;text-decoration:none}
        @media(max-width:1050px){.app-shell{grid-template-columns:230px minmax(0,1fr)}.sidebar{width:230px}.main-area{padding-right:26px;padding-left:26px}.stats-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.content-grid{grid-template-columns:minmax(0,1fr)}}
        @media(max-width:720px){.app-shell{grid-template-columns:1fr}.sidebar{position:relative;top:auto;left:auto;width:auto;height:auto;min-height:auto;overflow:visible;padding:14px 16px 12px}.brand{padding:0 4px 13px}.brand-mark{width:38px;height:38px;flex-basis:38px}.brand-mark svg{width:23px;height:23px}.nav-label,.sidebar-spacer,.sidebar-note{display:none}.sidebar-nav{display:flex;gap:6px;overflow-x:auto;padding-bottom:2px}.nav-link{min-height:40px;flex:0 0 auto;padding:0 10px;font-size:12px}.nav-link.active{box-shadow:inset 0 -3px #79adff}.sidebar-account{margin:10px 0 8px;padding:9px 10px}.sidebar-logout{position:static;min-height:40px;margin:0;padding:0 10px}.main-area{grid-column:1;padding:0 20px 28px}}
        @media(max-width:520px){.page-heading{align-items:flex-start;padding:46px 0 20px}.date-label{display:none}.stats-grid{grid-template-columns:1fr;gap:10px}.stat-card{min-height:105px;padding:15px 17px}.stat-value{margin-top:8px;font-size:24px}.main-area{padding-right:16px;padding-left:16px}.detail-grid{grid-template-columns:1fr}.system-banner{padding:12px}}
        :root{color:#26372f;background:#eef2ed}
        body{background:#eef2ed}
        .sidebar{background:linear-gradient(180deg,#175b40 0%,#124d37 100%)}
        .brand-mark{border-radius:8px;background:rgba(255,255,255,.1)}
        .nav-label{color:rgba(232,245,236,.68)}
        .nav-link{border-radius:5px;color:rgba(245,250,246,.82)}
        .nav-link:hover{background:rgba(255,255,255,.12);color:#fff}
        .nav-link.active{background:#f2f6f1;color:#14583e;box-shadow:inset 3px 0 #a8d18c}
        .sidebar-note{border-color:rgba(255,255,255,.17);border-radius:6px;background:rgba(255,255,255,.08)}
        .sidebar-logout{border-radius:5px}
        .sidebar-logout:hover{background:rgba(255,255,255,.12)}
        .main-area{background:#eef2ed}
        .sidebar-account{border-color:rgba(255,255,255,.2);background:rgba(255,255,255,.09)}
        .sidebar-account-role{color:rgba(239,248,241,.72)}
        .eyebrow{color:#176344}
        h1{color:#1d3027}
        .page-heading p{color:#718078}
        .date-label{border-color:#dce4dc;border-radius:6px;color:#627369}
        .notice{border-color:#c8dfce;background:#eef7ef;color:#286c45}
        .section-title{color:#26372f}
        .stat-card,.panel{border:1px solid #d5ded5;border-radius:8px;box-shadow:0 2px 8px rgba(31,55,39,.05)}
        .stat-label{color:#53645a}
        .stat-icon,.empty-state-icon{border-radius:7px;background:#175b40}
        .stat-value{color:#1b3127}
        .stat-caption{color:#78877d}
        .panel-header{border-color:#e1e7e1}
        .panel-header h2{color:#26372f}
        .status-pill{border-color:#d6e0d6;background:#f2f6f1;color:#4b6253}
        .monitoring-status{border-color:#dce5dc;border-radius:6px;background:#f5f8f4;color:#53665a}
        .status-dot{background:#5d9870}
        .monitoring-content p,.subtle-copy{color:#839087}
        .system-form label{color:#34483b}
        .system-form input,.system-form select,.system-form textarea,.toolbar input,.toolbar select{border-color:#d8e1d8;border-radius:5px;color:#26372f}
        .system-form input:focus,.system-form select:focus,.system-form textarea:focus,.toolbar input:focus,.toolbar select:focus{border-color:#39805a;box-shadow:0 0 0 3px rgba(37,112,72,.13)}
        .form-button{border-radius:5px;background:#175b40}
        .form-button:hover{background:#104a35}
        .form-button.secondary{border-color:#d8e1d8;background:#fff;color:#34483b}
        .form-button.secondary:hover{background:#f2f6f1}
        .toolbar{border-color:#e5ebe5}
        .data-table th,.data-table td{border-color:#e7ece7;color:#526158}
        .data-table th{background:#f5f8f4;color:#43564a}
        .data-table td strong{color:#26372f}
        .table-link,.back-link{color:#176344}
        .detail-item{border-color:#dce5dc;border-radius:6px;background:#fbfcfa}
        .detail-item span{color:#7d8b80}
        .detail-item strong{color:#34483b}
        .report-tab{border-color:#d8e1d8;border-radius:5px;color:#53665a}
        .report-tab.active,.report-tab:hover{border-color:#175b40;background:#175b40;color:#fff}
        .account-row,.incident-item{border-color:#e7ece7}
        .account-avatar{background:#e5eee6;color:#175b40}
        .account-name,.incident-item h3{color:#34483b}
        .account-role,.account-status,.incident-item time{color:#839087}
        .incident-item p{color:#718078}
        @media(max-width:720px){.nav-link.active{box-shadow:inset 0 -3px #a8d18c}}
    </style>
</head>
<body>
<div class="app-shell">
    <aside class="sidebar" aria-label="Main navigation">
        <a class="brand" href="Dashboard.php?page=dashboard" aria-label="Incident System dashboard">
            <span class="brand-mark" aria-hidden="true">
                <svg viewBox="0 0 40 40" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M20 4.5 33 9.5v9.2c0 8.2-5.3 13.8-13 17.3C12.3 32.5 7 26.9 7 18.7V9.5l13-5Z" stroke="white" stroke-width="2.3" stroke-linejoin="round"/><path d="m14.5 20 3.6 3.6 7.7-8" stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </span>
            <span class="brand-name">Incident<br>System</span>
        </a>
        <p class="nav-label"><?= $isAdmin || $isSupervisor ? 'Administration' : ($isStaff ? 'Staff' : ($isStudent ? 'Student' : 'Guard')) ?></p>
        <nav class="sidebar-nav">
            <?php foreach ($navItems as [$targetPage, $label]): ?>
                <?php
                $icon = match ($targetPage) {
                    'guards' => '<path d="M12 3 20 6v5.5c0 5-3.2 8.4-8 10.5-4.8-2.1-8-5.5-8-10.5V6l8-3Z"/><path d="M12 8v4l2.5 1.5"/>',
                    'my-shift' => '<path d="M12 3 20 6v5.5c0 5-3.2 8.4-8 10.5-4.8-2.1-8-5.5-8-10.5V6l8-3Z"/><path d="M12 8v4l2.5 1.5"/>',
                    'violation-form', 'violation-records' => '<path d="M12 3 2.8 19h18.4L12 3Z"/><path d="M12 9v4"/><path d="M12 16.5h.01"/>',
                    'student-records' => '<path d="M3 9 12 4l9 5-9 5-9-5Z"/><path d="M7 12v5c3 2 7 2 10 0v-5"/>',
                    'reports' => '<path d="M4 5h16v15H4z"/><path d="M8 9h8M8 13h8M8 17h5"/>',
                    'settings' => '<circle cx="12" cy="12" r="3"/><path d="M19 12a7 7 0 0 0-.1-1.2l1.4-1.1-1.4-2.4-1.7.6a7 7 0 0 0-2.1-1.2L14.8 5h-2.8l-.3 1.7a7 7 0 0 0-2.1 1.2l-1.7-.6-1.4 2.4 1.4 1.1a7 7 0 0 0 0 2.4l-1.4 1.1 1.4 2.4 1.7-.6a7 7 0 0 0 2.1 1.2l.3 1.7h2.8l.3-1.7a7 7 0 0 0 2.1-1.2l1.7.6 1.4-2.4-1.4-1.1A7 7 0 0 0 19 12Z"/>',
                    default => '<rect x="3" y="3" width="8" height="8" rx="1.5"/><rect x="13" y="3" width="8" height="5" rx="1.5"/><rect x="13" y="10" width="8" height="11" rx="1.5"/><rect x="3" y="13" width="8" height="8" rx="1.5"/>',
                };
                $active = $page === $targetPage || ($page === 'guard-details' && $targetPage === 'guards');
                ?>
                <a class="nav-link<?= $active ? ' active' : '' ?>" href="Dashboard.php?page=<?= rawurlencode($targetPage) ?>"<?= $active ? ' aria-current="page"' : '' ?>>
                    <svg class="nav-icon" viewBox="0 0 24 24" aria-hidden="true"><?= $icon ?></svg><?= $esc($label) ?>
                </a>
            <?php endforeach; ?>
        </nav>
        <div class="sidebar-spacer"></div>
        <div class="sidebar-account" aria-label="Signed in as <?= $safeUsername ?>. Role: <?= $esc($roleName) ?>.">
            <span class="sidebar-account-avatar" aria-hidden="true"><?= $initial ?></span>
            <span class="sidebar-account-text"><?= $safeUsername ?><small class="sidebar-account-role"><?= $esc($roleName) ?></small></span>
        </div>
        <a class="sidebar-logout" href="logout.php"><svg class="nav-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M10 17 15 12 10 7"/><path d="M15 12H3"/><path d="M12 3h6a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-6"/></svg>Sign out</a>
    </aside>

    <main class="main-area">
        <div class="page-content">
            <section class="page-heading">
                <div><p class="eyebrow">Incident System</p><h1><?= $isStudent && $page === 'dashboard' ? 'Student Dashboard' : $esc($pageTitle) ?></h1><p><?= $page === 'dashboard' ? ($isStudent ? 'Track your submissions and the status of each incident report.' : 'Overview of school security activity.') : 'Manage school security activity.' ?></p></div>
            </section>
            <?php if ($notice !== ''): ?><p class="notice" role="status"><?= $esc($notice) ?></p><?php endif; ?>
            <?php if ($error !== ''): ?><p class="error-message" role="alert"><?= $esc($error) ?></p><?php endif; ?>
            <?php if ($attendanceError !== ''): ?><p class="error-message" role="alert"><?= $esc($attendanceError) ?></p><?php endif; ?>

            <?php if ($page === 'dashboard'): ?>
                <?php if ($isStudent): ?>
                    <h2 class="section-title">Student overview</h2>
                    <div class="stats-grid">
                        <article class="stat-card">
                            <div class="stat-topline"><span class="stat-label">My reports</span><span class="stat-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 5h10l6 6v8a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2Z"/><path d="M14 5v6h6"/><path d="M8 13h8M8 17h8"/></svg></span></div>
                            <p class="stat-value"><?= count($studentViolations) ?></p>
                            <p class="stat-caption">Submitted reports</p>
                        </article>
                        <article class="stat-card">
                            <div class="stat-topline"><span class="stat-label">Pending review</span><span class="stat-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg></span></div>
                            <p class="stat-value"><?= count(array_filter($studentViolations, static fn ($item) => is_array($item) && ($item['status'] ?? '') === 'Pending')) ?></p>
                            <p class="stat-caption">Awaiting response</p>
                        </article>
                        <article class="stat-card">
                            <div class="stat-topline"><span class="stat-label">Resolved</span><span class="stat-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M5 12l4 4 10-10"/></svg></span></div>
                            <p class="stat-value"><?= count(array_filter($studentViolations, static fn ($item) => is_array($item) && ($item['status'] ?? '') === 'Reviewed')) ?></p>
                            <p class="stat-caption">Closed cases</p>
                        </article>
                        <article class="stat-card">
                            <div class="stat-topline"><span class="stat-label">Next action</span><span class="stat-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg></span></div>
                            <p class="stat-value" style="font-size:20px">Submit</p>
                            <p class="stat-caption">Submit a concern</p>
                        </article>
                    </div>

                    <div class="content-grid">
                        <section class="panel">
                            <div class="panel-header"><h2>Recent submissions</h2><span class="status-pill">My activity</span></div>
                            <?php if ($studentViolations === []): ?>
                                <div class="empty-state">
                                    <h3>No reports yet</h3>
                                    <p>Your submitted incident reports will appear here once you submit your first form.</p>
                                </div>
                            <?php else: ?>
                                <div class="account-list">
                                    <?php foreach (array_slice(array_reverse($studentViolations), 0, 5) as $item): ?>
                                        <div class="incident-item">
                                            <h3><?= $esc($item['type']) ?> incident</h3>
                                            <p><?= $esc($item['location']) ?> · <?= $esc($item['status']) ?></p>
                                            <time><?= $esc($item['reported_at']) ?></time>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </section>

                        <section class="panel">
                            <div class="panel-header"><h2>Quick actions</h2><span class="status-pill">Student</span></div>
                            <div class="system-form">
                                <a class="form-button" href="Dashboard.php?page=violation-form">Report an incident</a>
                                <a class="form-button secondary" href="Dashboard.php?page=student-records">View my reports</a>
                            </div>
                        </section>
                    </div>
                <?php else: ?>
                    <h2 class="section-title">Overview</h2>
                    <div class="stats-grid">
                        <article class="stat-card"><div class="stat-topline"><span class="stat-label">Violation records</span><span class="stat-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 3 2.8 19h18.4L12 3Z"/><path d="M12 9v4"/><path d="M12 16.5h.01"/></svg></span></div><p class="stat-value"><?= count($violations) ?></p><p class="stat-caption">Active records</p></article>
                        <article class="stat-card"><div class="stat-topline"><span class="stat-label">Pending reports</span><span class="stat-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg></span></div><p class="stat-value"><?= $pendingCount ?></p><p class="stat-caption">Awaiting review</p></article>
                        <?php if ($isGuard): ?><article class="stat-card"><div class="stat-topline"><span class="stat-label">My duty status</span><span class="stat-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 3 20 6v5.5c0 5-3.2 8.4-8 10.5-4.8-2.1-8-5.5-8-10.5V6l8-3Z"/></svg></span></div><p class="stat-value" style="font-size:20px"><?= $isCheckedIn ? 'On duty' : 'Off duty' ?></p><p class="stat-caption">Current shift status</p></article><?php else: ?><article class="stat-card"><div class="stat-topline"><span class="stat-label">Accounts</span><span class="stat-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="9" cy="8" r="3.5"/><path d="M3 20v-1.5a6 6 0 0 1 12 0V20"/></svg></span></div><p class="stat-value"><?= count($accounts) ?></p><p class="stat-caption">Configured accounts</p></article><?php endif; ?>
                        <article class="stat-card"><div class="stat-topline"><span class="stat-label">Record storage</span><span class="stat-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v14c0 1.7 3.6 3 8 3s8-1.3 8-3V5M4 12c0 1.7 3.6 3 8 3s8-1.3 8-3"/></svg></span></div><p class="stat-value" style="font-size:20px">System</p><p class="stat-caption">Active records</p></article>
                    </div>
                    <div class="content-grid">
                        <section class="panel"><div class="panel-header"><h2>Recent activity</h2><span class="status-pill">Live feed</span></div><?php if ($violations === []): ?><div class="empty-state"><h3>No activity yet</h3><p>Submitted violation records will appear here once they are recorded.</p></div><?php else: ?><div class="account-list"><?php foreach (array_slice(array_reverse($violations), 0, 5) as $item): ?><div class="incident-item"><h3><?= $esc($item['student']) ?> — <?= $esc($item['type']) ?></h3><p><?= $esc($item['location']) ?> · <?= $esc($item['status']) ?></p><time><?= $esc($item['reported_at']) ?></time></div><?php endforeach; ?></div><?php endif; ?></section>
                        <section class="panel"><div class="panel-header"><h2>Quick actions</h2><span class="status-pill"><?= $esc($roleName) ?></span></div><div class="system-form"><?php if ($canViewGuardMonitoring): ?><a class="form-button" href="Dashboard.php?page=guards"><?= $isAdmin ? 'View Guard Oversight' : 'Open Guard Monitoring' ?></a><?php endif; ?><?php if ($canRecordViolations): ?><a class="form-button" href="Dashboard.php?page=violation-form">Report a Violation</a><?php endif; ?><?php if ($canReviewViolations || $isStaff || $isGuard): ?><a class="form-button secondary" href="Dashboard.php?page=violation-records">View Violation Records</a><?php endif; ?><?php if ($isStudent): ?><a class="form-button secondary" href="Dashboard.php?page=student-records">View My Records</a><?php endif; ?></div></section>
                    </div>
                <?php endif; ?>

            <?php elseif ($page === 'guards' && $canViewGuardMonitoring): ?>
                <section class="panel">
                    <div class="panel-header">
                        <h2><?= $esc($pageTitle) ?></h2>
                        <span class="status-pill"><?= $isAdmin ? 'Admin oversight' : 'Supervisor view' ?></span>
                    </div>
                    <form class="toolbar" method="get" action="Dashboard.php">
                        <input type="hidden" name="page" value="guards">
                        <input type="search" name="guard_q" value="<?= $esc($guardSearch) ?>" placeholder="Search guard name, ID, or post" aria-label="Search guard name, ID, or post">
                        <button class="form-button">Search</button>
                        <?php if ($isAdmin): ?><a class="form-button" href="Dashboard.php?page=settings&amp;tab=users">+ Add Guard Account</a><?php endif; ?>
                    </form>
                    <div class="table-wrap">
                        <table class="data-table">
                            <thead><tr><th>ID</th><th>Name</th><th>Post / Area</th><th>Status</th><th>Check-in</th><th>Actions</th></tr></thead>
                            <tbody>
                                <?php foreach ($filteredGuards as $guard): ?>
                                    <tr>
                                        <td><?= $esc($guard['id']) ?></td>
                                        <td><strong><?= $esc($guard['name']) ?></strong></td>
                                        <td><?= $esc($guard['post']) ?></td>
                                        <?php $currentGuardAttendance = $attendanceData['current'][$guard['username']] ?? []; ?>
                                        <td><?= ($currentGuardAttendance['status'] ?? '') === 'on_duty' ? 'On duty' : (($currentGuardAttendance['status'] ?? '') === 'off_duty' ? 'Off duty' : 'No check-in yet') ?></td>
                                        <td><?= $esc($currentGuardAttendance['checked_in_at'] ?? '—') ?></td>
                                        <td><a class="table-link" href="Dashboard.php?page=guard-details&amp;guard=<?= rawurlencode($guard['username']) ?>">View</a></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if ($filteredGuards === []): ?><tr><td colspan="6">No matching guards.</td></tr><?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>

            <?php elseif ($page === 'guard-details' && $canViewGuardMonitoring): ?>
                <?php
                $guardName = $_GET['guard'] ?? 'guard';
                $guardName = is_string($guardName) && isset($accounts[$guardName]) && $accounts[$guardName]['role'] === 'guard' ? $guardName : 'guard';
                $guardAccount = $accounts[$guardName];
                $selectedGuardAttendance = $attendanceData['current'][$guardName] ?? [];
                $selectedGuardEvents = array_values(array_filter(
                    $attendanceData['events'],
                    static fn ($event): bool => is_array($event) && ($event['username'] ?? '') === $guardName
                ));
                $selectedGuardEvents = array_slice(array_reverse($selectedGuardEvents), 0, 20);
                ?>
                <section class="panel">
                    <div class="panel-header"><h2>Guard Profile</h2><div><a class="back-link" href="Dashboard.php?page=guards">← Back to guards</a></div></div>
                    <div class="detail-grid">
                        <div class="detail-item"><span>Name</span><strong><?= $esc($guardAccount['display_name'] ?? ucfirst($guardName)) ?></strong></div>
                        <div class="detail-item"><span>Guard ID</span><strong><?= $esc($guardAccount['guard_id'] ?? 'G001') ?></strong></div>
                        <div class="detail-item"><span>Post / Area</span><strong>Unassigned</strong></div>
                        <div class="detail-item"><span>Status</span><strong><?= ($selectedGuardAttendance['status'] ?? '') === 'on_duty' ? 'On duty' : (($selectedGuardAttendance['status'] ?? '') === 'off_duty' ? 'Off duty' : 'No check-in yet') ?></strong></div>
                        <div class="detail-item"><span>Last check-in</span><strong><?= $esc($selectedGuardAttendance['checked_in_at'] ?? '—') ?></strong></div>
                        <div class="detail-item"><span>Last check-out</span><strong><?= $esc($selectedGuardAttendance['checked_out_at'] ?? '—') ?></strong></div>
                        <div class="detail-item"><span>Contact</span><strong><?= $esc($guardAccount['login_identifier']) ?></strong></div>
                        <div class="detail-item"><span>Role</span><strong>Security Guard</strong></div>
                    </div>
                    <div class="panel-header"><h2>Duty Log / Attendance</h2><span class="status-pill"><?= count($selectedGuardEvents) ?> recent event(s)</span></div>
                    <?php if ($selectedGuardEvents === []): ?>
                        <div class="empty-state"><h3>No attendance records</h3><p>Check-in and check-out events will appear here.</p></div>
                    <?php else: ?>
                        <div class="table-wrap">
                            <table class="data-table">
                                <thead><tr><th>Action</th><th>Date and time</th></tr></thead>
                                <tbody>
                                    <?php foreach ($selectedGuardEvents as $event): ?>
                                        <tr>
                                            <td><?= ($event['action'] ?? '') === 'check_in' ? 'Checked in' : 'Checked out' ?></td>
                                            <td><?= $esc($event['occurred_at'] ?? '') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </section>

            <?php elseif ($page === 'my-shift' && $isGuard): ?>
                <section class="panel">
                    <div class="panel-header"><h2>My Duty Log</h2><span class="status-pill"><?= $isCheckedIn ? 'On Duty' : 'Off Duty' ?></span></div>
                    <div class="monitoring-content">
                        <div class="monitoring-status"><span class="status-dot" aria-hidden="true"></span><?= $isCheckedIn ? 'Checked in at ' . $esc($guardAttendance['checked_in_at'] ?? '') : 'You have not checked in for a shift yet.' ?></div>
                        <p>This attendance status is shared with supervisors.</p>
                    </div>
                    <form class="system-form" method="post" action="Dashboard.php?page=my-shift">
                        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                        <button class="form-button<?= $isCheckedIn ? ' secondary' : '' ?>" name="action" value="<?= $isCheckedIn ? 'check_out' : 'check_in' ?>"><?= $isCheckedIn ? 'End shift' : 'Check in for shift' ?></button>
                    </form>
                    <div class="panel-header"><h2>Recent attendance</h2><span class="status-pill"><?= count($guardAttendanceEvents) ?> recent event(s)</span></div>
                    <?php if ($guardAttendanceEvents === []): ?>
                        <div class="empty-state"><h3>No attendance events yet</h3><p>Your check-in and check-out history will appear here.</p></div>
                    <?php else: ?>
                        <div class="table-wrap">
                            <table class="data-table">
                                <thead><tr><th>Action</th><th>Date and time</th></tr></thead>
                                <tbody>
                                    <?php foreach ($guardAttendanceEvents as $event): ?>
                                        <tr>
                                            <td><?= ($event['action'] ?? '') === 'check_in' ? 'Checked in' : 'Checked out' ?></td>
                                            <td><?= $esc($event['occurred_at'] ?? '') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </section>

            <?php elseif ($page === 'violation-form' && $canRecordViolations): ?>
                <section class="panel"><div class="panel-header"><h2><?= $isStudent ? 'Report an Incident' : 'Report a Violation' ?></h2></div><form class="system-form" method="post" action="Dashboard.php?page=violation-form"><input type="hidden" name="csrf_token" value="<?= $csrf ?>"><div><label for="occurrence-at">Date &amp; Time</label><input id="occurrence-at" name="occurrence_at" type="datetime-local" required></div><?php if ($isStudent): ?><div><label for="student-name">Student name or ID</label><input id="student-name" name="student_name" maxlength="100" required placeholder="Enter your name or student ID"></div><div><label for="violation-type">Incident type</label><select id="violation-type" name="violation_type" required><option value="">Select incident type</option><?php foreach ($studentIncidentTypes as $typeOption): ?><option value="<?= $esc($typeOption) ?>"><?= $esc($typeOption) ?></option><?php endforeach; ?></select></div><div><label for="location">Location</label><input id="location" name="location" maxlength="120" required placeholder="Where did this happen?"></div><div><label for="violation-details">Description</label><textarea id="violation-details" name="violation_details" maxlength="1000" required placeholder="Describe what happened and any details you want to share"></textarea></div><div><label for="person-involved">Person(s) involved (optional)</label><input id="person-involved" name="person_involved" maxlength="100" placeholder="Add a name or description if relevant"></div><div><label><input type="checkbox" name="submit_anonymously" value="1"> Submit anonymously</label></div><?php else: ?><div><label for="student-name">Student Name or ID</label><input id="student-name" name="student_name" maxlength="100" required placeholder="Enter student name or ID"></div><div><label for="violation-type">Type of Violation</label><select id="violation-type" name="violation_type" required><option value="">Select violation type</option><?php foreach ($guardViolationTypes as $typeOption): ?><option value="<?= $esc($typeOption) ?>"><?= $esc($typeOption) ?></option><?php endforeach; ?></select><small>Choose what you directly observed. In the description, include the time, location, and facts; avoid assumptions.</small></div><div><label for="location">Location</label><input id="location" name="location" maxlength="120" required placeholder="Select or enter location"></div><div><label for="violation-details">Description</label><textarea id="violation-details" name="violation_details" maxlength="1000" required placeholder="Describe only what you observed, including relevant details"></textarea></div><?php endif; ?><button class="form-button" name="action" value="record_violation"><?= $isStudent ? 'Submit Incident' : 'Submit Report' ?></button></form></section>

            <?php elseif ($page === 'violation-records' && ($canReviewViolations || $isStaff || $isGuard)): ?>
                <section class="panel"><div class="panel-header"><h2><?= $isGuard ? 'My Reports' : 'Violation Records' ?></h2><span class="status-pill"><?= count($filteredViolations) ?> shown</span></div><form class="toolbar" method="get" action="Dashboard.php"><input type="hidden" name="page" value="violation-records"><input type="search" name="q" value="<?= $esc($query) ?>" placeholder="Search student name, ID, type, or location" aria-label="Search violation records"><select name="type" aria-label="Filter violation type"><option value="">All Types</option><?php foreach ($incidentTypes as $option): ?><option value="<?= $esc($option) ?>"<?= $typeFilter === $option ? ' selected' : '' ?>><?= $esc($option) ?></option><?php endforeach; ?></select><button class="form-button">Search</button><?php if ($canRecordViolations): ?><a class="table-link" href="Dashboard.php?page=violation-form">+ Add Report</a><?php endif; ?></form><?php if ($filteredViolations === []): ?><div class="empty-state"><h3>No violation records found</h3><p>No records match the current filters.</p></div><?php else: ?><div class="table-wrap"><table class="data-table"><thead><tr><th>ID</th><th>Date &amp; Time</th><th>Student</th><th>Type</th><th>Location</th><th>Status</th><th>Actions</th></tr></thead><tbody><?php foreach (array_reverse($filteredViolations) as $item): ?><tr><td><?= $esc($item['id']) ?></td><td><?= $esc($item['occurrence_at'] ?? $item['reported_at']) ?></td><td><strong><?= $esc($item['student']) ?></strong></td><td><?= $esc($item['type']) ?></td><td><?= $esc($item['location']) ?></td><td><?= $esc($item['status']) ?></td><td><?php if ($canReviewViolations && $item['status'] === 'Pending'): ?><form method="post" action="Dashboard.php?page=violation-records"><input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="violation_id" value="<?= $esc($item['id']) ?>"><button class="form-button secondary" name="action" value="review_violation">Review</button></form><?php else: ?>View<?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section>

            <?php elseif ($page === 'student-records' && ($canSeeAllStudentRecords || $isStudent)): ?>
                <section class="panel"><div class="panel-header"><h2><?= $isStudent ? 'My Reports' : 'Student Violation Records' ?></h2><span class="status-pill"><?= count($studentViolations) ?> reports</span></div><?php if (!$isStudent): ?><form class="toolbar" method="get" action="Dashboard.php"><input type="hidden" name="page" value="student-records"><input type="search" name="student" value="<?= $esc($studentQuery) ?>" placeholder="Search student name or ID" aria-label="Search student"><button class="form-button">Search</button></form><?php endif; ?><?php if ($studentViolations === []): ?><div class="empty-state"><h3>No reports found</h3><p>No matching reports are available.</p></div><?php else: ?><div class="table-wrap"><table class="data-table"><thead><tr><th>ID</th><th>Date submitted</th><th>Incident type</th><th>Location</th><th>Status</th></tr></thead><tbody><?php foreach (array_reverse($studentViolations) as $item): ?><tr><td><?= $esc($item['id']) ?></td><td><?= $esc($item['occurrence_at'] ?? $item['reported_at']) ?></td><td><?= $esc($item['type']) ?></td><td><?= $esc($item['location']) ?></td><td><?php
    $status = $item['status'] ?? 'Pending';
    $statusStyle = match ($status) {
        'Pending' => 'background:#fff7df; border-color:#f3d98b; color:#8a6400;',
        'Reviewed' => 'background:#eef5ff; border-color:#bfd6ff; color:#174ea6;',
        'Resolved' => 'background:#eef7ef; border-color:#cfe6d3; color:#275d3a;',
        default => 'background:#f3f5f7; border-color:#d6dbe1; color:#465468;'
    };
    echo '<span class="status-pill" style="display:inline-block; ' . $statusStyle . '">' . $esc($status) . '</span>';
?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section>

            <?php elseif ($page === 'reports' && ($canReviewViolations || $isStaff)): ?>
                <?php $generatedReport = $_SESSION['system_report'] ?? null; $reportTab = $_GET['report'] ?? 'violations'; ?>
                <section class="panel"><div class="panel-header"><h2>Reports</h2></div><nav class="report-tabs" aria-label="Report type"><a class="report-tab<?= $reportTab === 'violations' ? ' active' : '' ?>" href="Dashboard.php?page=reports&amp;report=violations">Violation Report</a><?php if (!$isStaff): ?><a class="report-tab<?= $reportTab === 'attendance' ? ' active' : '' ?>" href="Dashboard.php?page=reports&amp;report=attendance">Guard Attendance</a><?php endif; ?><a class="report-tab<?= $reportTab === 'summary' ? ' active' : '' ?>" href="Dashboard.php?page=reports&amp;report=summary">Summary Report</a></nav><form class="toolbar" method="post" action="Dashboard.php?page=reports"><input type="hidden" name="csrf_token" value="<?= $csrf ?>"><select name="report_type" aria-label="Report type"><option value="violations">Violation Report</option><?php if (!$isStaff): ?><option value="attendance">Guard Attendance</option><?php endif; ?><option value="summary">Summary Report</option></select><input type="date" aria-label="Start date"><select aria-label="Violation type"><option>All types</option><?php foreach ($incidentTypes as $option): ?><option><?= $esc($option) ?></option><?php endforeach; ?></select><button class="form-button" name="action" value="generate_report">Generate Report</button></form><div class="content-grid" style="padding:20px"><section class="panel"><div class="panel-header"><h2>Report Data</h2></div><div class="monitoring-content"><div class="monitoring-status"><span class="status-dot" aria-hidden="true"></span><?= count($violations) ?> violation record(s) in the system</div></div></section><section class="panel"><div class="panel-header"><h2>Report Preview</h2></div><div class="empty-state"><h3><?= $generatedReport ? $esc(ucfirst($generatedReport['type']) . ' report generated') : 'Generate a report' ?></h3><p><?= $generatedReport ? (int) $generatedReport['count'] . ' record(s) in the system, generated ' . $esc($generatedReport['generated_at']) . '.' : 'Choose a report type to preview the summary.' ?></p></div></section></div></section>

            <?php elseif ($page === 'settings' && $isAdmin): ?>
                <section class="panel">
                    <div class="panel-header"><h2>Settings</h2><span class="status-pill">System</span></div>
                    <nav class="report-tabs" aria-label="Settings categories">
                        <a class="report-tab<?= $settingsTab === 'system' ? ' active' : '' ?>" href="Dashboard.php?page=settings&amp;tab=system">System</a>
                        <a class="report-tab<?= $settingsTab === 'users' ? ' active' : '' ?>" href="Dashboard.php?page=settings&amp;tab=users">Users</a>
                        <a class="report-tab<?= $settingsTab === 'areas' ? ' active' : '' ?>" href="Dashboard.php?page=settings&amp;tab=areas">Posts / Areas</a>
                    </nav>
                    <?php if ($settingsTab === 'system'): ?>
                        <form class="system-form" method="post" action="Dashboard.php?page=settings&amp;tab=system">
                            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                            <div><label for="school-name">School Name</label><input id="school-name" name="school_name" maxlength="120" value="<?= $esc($_SESSION['system_settings']['school_name']) ?>" required></div>
                            <div><label for="school-address">School Address</label><input id="school-address" name="school_address" maxlength="200" value="<?= $esc($_SESSION['system_settings']['school_address']) ?>" required></div>
                            <div><label for="academic-year">Academic Year</label><input id="academic-year" name="academic_year" maxlength="30" value="<?= $esc($_SESSION['system_settings']['academic_year']) ?>" required></div>
                            <button class="form-button" name="action" value="save_settings">Save Settings</button>
                        </form>
                    <?php elseif ($settingsTab === 'users'): ?>
                        <div class="panel-header"><h3>Add a guard account</h3></div>
                        <p class="subtle-copy" style="padding:0 20px">Create the guard's sign-in account after hiring. Give the initial password to the guard securely. Supervisors can view guard accounts but cannot create them.</p>
                        <form class="system-form" method="post" action="Dashboard.php?page=settings&amp;tab=users">
                            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                            <div><label for="guard-name">Guard's full name</label><input id="guard-name" name="guard_name" maxlength="100" autocomplete="name" required></div>
                            <div><label for="guard-username">Username</label><input id="guard-username" name="guard_username" minlength="3" maxlength="32" pattern="[A-Za-z][A-Za-z0-9._-]*" autocomplete="username" required></div>
                            <div><label for="guard-email">School email</label><input id="guard-email" name="guard_email" type="email" maxlength="254" autocomplete="email" required></div>
                            <div><label for="guard-password">Initial password</label><input id="guard-password" name="guard_password" type="password" minlength="12" maxlength="72" autocomplete="new-password" required><small>Use 12-72 bytes (ASCII recommended). Passwords are stored as hashes.</small></div>
                            <button class="form-button" name="action" value="create_guard_account">Create Guard Account</button>
                        </form>
                        <div class="table-wrap">
                            <table class="data-table">
                                <thead><tr><th>Name</th><th>Username</th><th>Login email / ID</th><th>Role</th><th>Guard ID</th></tr></thead>
                                <tbody>
                                    <?php foreach ($accounts as $systemName => $systemAccount): ?>
                                        <tr>
                                            <td><strong><?= $esc($systemAccount['display_name'] ?? ucfirst($systemName)) ?></strong></td>
                                            <td><?= $esc($systemName) ?></td>
                                            <td><?= $esc($systemAccount['login_identifier']) ?></td>
                                            <td><?= $esc(ucfirst($systemAccount['role'])) ?></td>
                                            <td><?= $systemAccount['role'] === 'guard' ? $esc($systemAccount['guard_id'] ?? 'G001') : '—' ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="table-wrap"><table class="data-table"><thead><tr><th>Post / Area</th><th>Description</th><th>Status</th></tr></thead><tbody><tr><td><strong>Main Gate</strong></td><td>Primary entrance</td><td>Active</td></tr><tr><td><strong>Building A</strong></td><td>Academic building</td><td>Monitoring</td></tr><tr><td><strong>School Ground</strong></td><td>Outdoor area</td><td>Active</td></tr></tbody></table></div>
                    <?php endif; ?>
                </section>

            <?php else: ?>
                <section class="panel"><div class="empty-state"><h3>Page not found</h3><p>You may not have permission to view this page.</p></div></section>
            <?php endif; ?>
        </div>
    </main>
</div>
</body>
</html>
