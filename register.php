<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f3f6fb">
    <title>Create account | Incident System</title>
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
        .form-footer a:focus-visible {
            outline: 3px solid rgba(57, 116, 196, 0.35);
            outline-offset: 3px;
        }

        .form-footer {
            margin: 20px 0 0;
            color: #718096;
            font-size: 13px;
            text-align: center;
        }

        .form-footer a {
            color: #3974c4;
            font-weight: 600;
            text-decoration: none;
        }

        .form-footer a:hover {
            text-decoration: underline;
        }

        .notice {
            margin: 0 0 20px;
            padding: 14px 16px;
            border: 1px solid #d8e5f5;
            border-radius: 8px;
            background: #f4f8fd;
            color: #526782;
            font-size: 14px;
            line-height: 1.6;
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
    </style>
</head>
<body>
    <main class="login-card">
        <section class="brand-panel" aria-labelledby="brand-title">
            <div class="brand-content">
                <div class="brand-mark" aria-hidden="true">
                    <svg viewBox="0 0 40 40" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M20 4.5 33 9.5v9.2c0 8.2-5.3 13.8-13 17.3C12.3 32.5 7 26.9 7 18.7V9.5l13-5Z" stroke="white" stroke-width="2.3" stroke-linejoin="round"/>
                        <path d="m14.5 20 3.6 3.6 7.7-8" stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </div>
                <h1 id="brand-title">Incident System</h1>
            </div>
        </section>

        <section class="credentials-panel" aria-labelledby="register-title">
            <p class="eyebrow">Get started</p>
            <h2 id="register-title">Registration unavailable</h2>
            <p class="intro">New accounts are not being saved yet.</p>

            <div class="notice" role="status">
                Account registration is currently unavailable. Please contact the school administrator to set up a new account.
            </div>

            <p class="form-footer">Already have an account? <a href="login.php">Sign in</a></p>
        </section>
    </main>
</body>
</html>
