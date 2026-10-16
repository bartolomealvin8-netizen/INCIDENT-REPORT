<?php
require_once __DIR__ . '/auth.php';
start_app_session();

if (($_SESSION['authenticated'] ?? false) === true) {
    header('Location: Dashboard.php');
    exit;
}

$error = '';
$identifier = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identifierInput = $_POST['identifier'] ?? '';
    $passwordInput = $_POST['password'] ?? '';
    $identifier = is_string($identifierInput) ? trim($identifierInput) : '';
    $password = is_string($passwordInput) ? $passwordInput : '';

    $account = find_system_account($identifier);

    if ($account !== null && password_verify($password, $account['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['authenticated'] = true;
        $_SESSION['username'] = $account['username'];
        $_SESSION['role'] = $account['role'];
        header('Location: Dashboard.php');
        exit;
    }

    $error = 'The school email, student ID, or password is incorrect.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#eef2ed">
    <title>Sign in | Incident System</title>
    <style>
        :root {
            color-scheme: light;
            font-family: Inter, "Segoe UI", Arial, sans-serif;
            color: #17243b;
            background: #f3f6fb;
            font-synthesis: none;
            text-rendering: optimizeLegibility;
        }

        * {
            box-sizing: border-box;
        }

        body {
            min-width: 320px;
            min-height: 100vh;
            margin: 0;
            display: grid;
            place-items: center;
            padding: 32px 20px;
            background:
                radial-gradient(ellipse at 15% 10%, rgba(50, 111, 218, 0.08), transparent 34%),
                #f3f6fb;
        }

        .login-card {
            display: grid;
            grid-template-columns: 1fr 1fr;
            width: min(920px, 100%);
            min-height: 540px;
            overflow: hidden;
            background: #fff;
            border: 1px solid #e4eaf2;
            border-radius: 20px;
            box-shadow: 0 24px 70px rgba(30, 51, 82, 0.12);
        }

        .brand-panel,
        .credentials-panel {
            display: flex;
            flex-direction: column;
            justify-content: center;
            padding: 64px;
        }

        .brand-panel {
            position: relative;
            align-items: flex-start;
            overflow: hidden;
            color: #fff;
            background: linear-gradient(145deg, #173b70 0%, #2459a0 60%, #3175c7 100%);
        }

        .brand-panel::before,
        .brand-panel::after {
            position: absolute;
            content: "";
            border: 1px solid rgba(255, 255, 255, 0.12);
            border-radius: 50%;
            pointer-events: none;
        }

        .brand-panel::before {
            width: 390px;
            height: 390px;
            right: -205px;
            bottom: -185px;
        }

        .brand-panel::after {
            width: 280px;
            height: 280px;
            right: -150px;
            bottom: -130px;
        }

        .brand-content {
            position: relative;
            z-index: 1;
        }

        .brand-mark {
            display: grid;
            width: 64px;
            height: 64px;
            margin-bottom: 32px;
            place-items: center;
            border: 1px solid rgba(255, 255, 255, 0.34);
            border-radius: 18px;
            background: rgba(255, 255, 255, 0.12);
        }

        .brand-mark svg {
            width: 35px;
            height: 35px;
        }

        .brand-panel h1 {
            max-width: 300px;
            margin: 0;
            font-size: clamp(30px, 4vw, 40px);
            font-weight: 700;
            letter-spacing: -1.3px;
            line-height: 1.15;
        }

        .brand-panel p {
            max-width: 310px;
            margin: 18px 0 0;
            color: rgba(255, 255, 255, 0.78);
            font-size: 15px;
            line-height: 1.7;
        }

        .credentials-panel {
            border-left: 1px solid #e6ebf2;
        }

        .credentials-mark {
            display: grid;
            width: 80px;
            height: 80px;
            margin-bottom: 20px;
            place-items: center;
        }

        .credentials-mark svg {
            width: 30px;
            height: 30px;
        }

        .credentials-mark img {
            display: block;
            width: 100%;
            height: 100%;
            object-fit: contain;
        }

        .eyebrow {
            margin: 0 0 10px;
            color: #3974c4;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 1.4px;
            text-transform: uppercase;
        }

        .credentials-panel h2 {
            margin: 0;
            color: #17243b;
            font-size: 30px;
            letter-spacing: -0.8px;
        }

        .intro {
            margin: 10px 0 32px;
            color: #718096;
            font-size: 14px;
            line-height: 1.6;
        }

        .form-field {
            margin-bottom: 20px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            color: #263650;
            font-size: 13px;
            font-weight: 600;
        }

        input {
            width: 100%;
            height: 48px;
            padding: 0 14px;
            border: 1px solid #d9e1eb;
            border-radius: 8px;
            outline: none;
            background: #fff;
            color: #17243b;
            font: inherit;
            font-size: 14px;
            transition: border-color 150ms ease, box-shadow 150ms ease;
        }

        input::placeholder {
            color: #9aa7b8;
        }

        input:focus {
            border-color: #3974c4;
            box-shadow: 0 0 0 3px rgba(57, 116, 196, 0.14);
        }

        .password-field {
            position: relative;
        }

        .password-field input {
            padding-right: 48px;
        }

        .password-toggle {
            position: absolute;
            top: 50%;
            right: 10px;
            display: grid;
            width: 32px;
            height: 32px;
            padding: 0;
            place-items: center;
            transform: translateY(-50%);
            border: 0;
            border-radius: 6px;
            background: transparent;
            color: #718096;
            cursor: pointer;
        }

        .password-toggle:hover {
            color: #263650;
        }

        .password-toggle:focus-visible {
            outline: 3px solid rgba(57, 116, 196, 0.35);
            outline-offset: 1px;
        }

        .password-toggle svg {
            width: 18px;
            height: 18px;
            fill: none;
            stroke: currentColor;
            stroke-linecap: round;
            stroke-linejoin: round;
            stroke-width: 1.8;
        }

        .password-toggle .eye-closed {
            display: none;
        }

        .password-toggle[aria-pressed="true"] .eye-open {
            display: none;
        }

        .password-toggle[aria-pressed="true"] .eye-closed {
            display: initial;
        }

        .form-options {
            display: grid;
            justify-items: stretch;
            margin: 18px 0 0;
        }

        .forgot-password-link {
            justify-self: center;
            color: #070707;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
        }

        .forgot-password-link:hover {
            text-decoration: underline;
        }

        .submit-button {
            width: 100%;
            min-height: 48px;
            border: 0;
            border-radius: 8px;
            background: #2866b5;
            color: #fff;
            cursor: pointer;
            font: inherit;
            font-size: 14px;
            font-weight: 700;
            transition: background-color 150ms ease, transform 150ms ease;
        }

        .submit-button:hover {
            background: #20569b;
        }

        .submit-button:active {
            transform: translateY(1px);
        }

        .submit-button:focus-visible,
        .forgot-password-link:focus-visible {
            outline: 3px solid rgba(57, 116, 196, 0.35);
            outline-offset: 3px;
        }

        .security-note {
            margin: 24px 0 0;
            color: #8995a6;
            font-size: 12px;
            line-height: 1.5;
            text-align: center;
        }

        .error-message {
            margin: 0 0 18px;
            padding: 11px 13px;
            border: 1px solid #f1c9c9;
            border-radius: 8px;
            background: #fff5f5;
            color: #a33232;
            font-size: 13px;
            line-height: 1.5;
        }

        .system-note {
            margin: 18px 0 0;
            color: #8995a6;
            font-size: 12px;
            line-height: 1.5;
            text-align: center;
        }

        .system-note a {
            color: #3974c4;
            font-weight: 600;
            text-decoration: none;
        }

        .system-note a:hover {
            text-decoration: underline;
        }

        .system-note strong {
            color: #526782;
        }

        @media (max-width: 700px) {
            body {
                padding: 20px 16px;
            }

            .login-card {
                grid-template-columns: 1fr;
                max-width: 480px;
                min-height: 0;
            }

            .brand-panel {
                min-height: 235px;
                padding: 32px;
            }

            .brand-mark {
                width: 50px;
                height: 50px;
                margin-bottom: 20px;
                border-radius: 14px;
            }

            .brand-mark svg {
                width: 28px;
                height: 28px;
            }

            .brand-panel h1 {
                font-size: 29px;
            }

            .brand-panel p {
                margin-top: 10px;
            }

            .credentials-panel {
                padding: 36px 32px 32px;
                border-top: 1px solid #e6ebf2;
                border-left: 0;
            }
        }

        @media (max-width: 380px) {
            .brand-panel,
            .credentials-panel {
                padding-right: 24px;
                padding-left: 24px;
            }
        }

        :root {
            color: #26372f;
            background: #eef2ed;
        }

        body {
            background:
                radial-gradient(ellipse at 15% 10%, rgba(23, 91, 64, 0.08), transparent 34%),
                #eef2ed;
        }

        .login-card {
            border-color: #d5ded5;
            border-radius: 10px;
            box-shadow: 0 12px 36px rgba(31, 55, 39, 0.1);
        }

        .brand-panel {
            background: linear-gradient(145deg, #175b40 0%, #124d37 100%);
        }

        .brand-mark {
            border-radius: 9px;
            background: rgba(255, 255, 255, 0.1);
        }

        .credentials-panel {
            border-left-color: #e1e7e1;
            align-items: center;
            text-align: center;
        }

        .eyebrow {
            color: #176344;
        }

        .credentials-panel h2 {
            color: #1d3027;
        }

        .intro {
            color: #718078;
        }

        .credentials-panel .intro {
            width: min(100%, 480px);
        }

        label {
            color: #34483b;
            text-align: left;
        }

        input {
            border-color: #d8e1d8;
            border-radius: 5px;
            color: #26372f;
        }

        input::placeholder {
            color: #909b92;
        }

        input:focus {
            border-color: #39805a;
            box-shadow: 0 0 0 3px rgba(37, 112, 72, 0.13);
        }

        .forgot-password-link {
            color: #176344;
        }

        .submit-button {
            border-radius: 5px;
            background: #175b40;
        }

        .submit-button:hover {
            background: #104a35;
        }

        .submit-button:focus-visible,
        .forgot-password-link:focus-visible {
            outline-color: rgba(37, 112, 72, 0.35);
        }

        .security-note,
        .system-note {
            color: #839087;
        }

        .system-note a {
            color: #176344;
        }

        .system-note strong {
            color: #526158;
        }

        .error-message {
            border-color: #ebcaca;
            background: #fff5f4;
            color: #963a34;
            text-align: left;
        }

        body {
            display: block;
            padding: 0;
            background: #eef2ed;
        }

        .login-card {
            width: 100%;
            min-height: 100vh;
            border: 0;
            border-radius: 0;
            box-shadow: none;
        }

        .brand-panel,
        .credentials-panel {
            padding: clamp(40px, 7vw, 110px);
        }

        .brand-content {
            width: min(100%, 520px);
        }

        .credentials-panel form,
        .credentials-panel .system-note,
        .credentials-panel .security-note {
            width: min(100%, 480px);
        }

        @media (max-width: 700px) {
            body {
                padding: 0;
            }

            .login-card {
                min-height: 100vh;
            }

            .brand-panel {
                min-height: 32vh;
                padding: 36px clamp(24px, 7vw, 52px);
            }

            .brand-mark {
                margin-bottom: 18px;
            }

            .credentials-panel {
                min-height: 68vh;
                justify-content: flex-start;
                padding: 36px clamp(24px, 7vw, 52px) 48px;
                border-top: 1px solid #e1e7e1;
            }
        }

        @media (max-width: 380px) {
            .credentials-panel {
                padding-right: 24px;
                padding-left: 24px;
            }
        }
    </style>
</head>
<body>
    <main class="login-card">
        <section class="brand-panel" aria-labelledby="brand-title">
            <div class="brand-content">
                <h1 id="brand-title">Incident System</h1>
            </div>
        </section>

        <section class="credentials-panel" aria-labelledby="login-title">
            <div class="credentials-mark" aria-hidden="true">
                <img src="olfu%20logo%20transparent.png" alt="">
            </div>
            <p class="eyebrow">Welcome back</p>
            <h2 id="login-title">Sign in</h2>
            <p class="intro">Enter your credentials to access your account.</p>

            <form action="" method="post">
                <?php if ($error !== ''): ?>
                    <p class="error-message" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
                <?php endif; ?>

                <div class="form-field">
                    <label for="identifier">School email or student ID</label>
                    <input
                        type="text"
                        id="identifier"
                        name="identifier"
                        value="<?= htmlspecialchars($identifier, ENT_QUOTES, 'UTF-8') ?>"
                        autocomplete="username"
                        autocapitalize="none"
                        spellcheck="false"
                        maxlength="254"
                        placeholder="Enter your school email or student ID"
                        required
                    >
                </div>

                <div class="form-field">
                    <label for="password">Password</label>
                    <div class="password-field">
                        <input
                            type="password"
                            id="password"
                            name="password"
                            autocomplete="current-password"
                            placeholder="Enter your password"
                            required
                        >
                        <button
                            class="password-toggle"
                            type="button"
                            aria-label="Show password"
                            aria-controls="password"
                            aria-pressed="false"
                        >
                            <svg viewBox="0 0 24 24" aria-hidden="true">
                                <path class="eye-open" d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z" />
                                <circle class="eye-open" cx="12" cy="12" r="3" />
                                <path class="eye-closed" d="M3 3l18 18M10.6 5.1A10.8 10.8 0 0 1 12 5c6.4 0 10 7 10 7a15.8 15.8 0 0 1-3.2 4.1M6.2 6.2C3.5 8 2 12 2 12s3.6 7 10 7a10 10 0 0 0 4.1-.9" />
                                <path class="eye-closed" d="M9.9 9.9a3 3 0 0 0 4.2 4.2" />
                            </svg>
                        </button>
                    </div>
                </div>

                <button class="submit-button" type="submit">Sign in</button>

            </form>
        </section>
    </main>
    <script>
        const passwordInput = document.getElementById('password');
        const passwordToggle = document.querySelector('.password-toggle');

        passwordToggle.addEventListener('click', () => {
            const isVisible = passwordInput.type === 'text';
            passwordInput.type = isVisible ? 'password' : 'text';
            passwordToggle.setAttribute('aria-pressed', String(!isVisible));
            passwordToggle.setAttribute('aria-label', isVisible ? 'Show password' : 'Hide password');
        });
    </script>
</body>
</html>