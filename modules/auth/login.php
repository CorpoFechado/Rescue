<?php
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../includes/session_guard.php';

// Redirect if already logged in
if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    if ($_SESSION['role'] === 'official') {
        header("Location: " . BASE_URL . "/modules/official/dashboard.php");
    } else {
        header("Location: " . BASE_URL . "/modules/resident/dashboard.php");
    }
    exit();
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../../config/database.php';

    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        $error = 'Please enter your email and password.';
    } else {
        $stmt = $conn->prepare("SELECT user_id, email, password, role, is_active FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        // TEMPORARY: plain text password check
        // TODO: replace with password_verify() before deployment
        if ($user && $password === $user['password']) {
            if (!$user['is_active']) {
                $error = 'Your account has been deactivated. Please contact the Barangay office.';
            } else {
                session_regenerate_id(true);
                $_SESSION['logged_in'] = true;
                $_SESSION['user_id']   = $user['user_id'];
                $_SESSION['role']      = $user['role'];
                $_SESSION['email']     = $user['email'];

                if ($user['role'] === 'official') {
                    header("Location: " . BASE_URL . "/modules/official/dashboard.php");
                } else {
                    header("Location: " . BASE_URL . "/modules/resident/dashboard.php");
                }
                exit();
            }
        } else {
            $error = 'Invalid email or password.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login &mdash; RESCUE</title>

    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/bootstrap-icons/bootstrap-icons.css">
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">

    <style>
        :root {
            --red:        #B03428;
            --red-dark:   #963022;
            --red-light:  #FADBD8;
            --bg:         #F0F0F0;
            --text:       #1A1A1A;
            --muted:      #6C757D;
            --border:     #E0E0E0;
            --white:      #FFFFFF;
        }

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: linear-gradient(135deg, #1a0a09 0%, #3d1410 30%, #6b2318 55%, #2d1a3a 80%, #0f0a1a 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
            position: relative;
        }

        /* Home button — sits inside brand panel above logo */
        .btn-home {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 0.75rem;
            font-weight: 600;
            color: rgba(255,255,255,0.75);
            text-decoration: none;
            background: rgba(255,255,255,0.1);
            border: 1px solid rgba(255,255,255,0.18);
            border-radius: 20px;
            padding: 5px 12px;
            margin-bottom: 1.5rem;
            transition: background 0.2s, color 0.2s;
            position: relative;
            z-index: 1;
            align-self: flex-start;
        }

        .btn-home:hover {
            background: rgba(255,255,255,0.18);
            color: #fff;
        }

        body::before {
            content: '';
            position: fixed;
            inset: 0;
            background:
                radial-gradient(ellipse at 20% 50%, rgba(176,52,40,0.25) 0%, transparent 60%),
                radial-gradient(ellipse at 80% 20%, rgba(100,30,80,0.2) 0%, transparent 50%),
                radial-gradient(ellipse at 60% 80%, rgba(50,20,100,0.15) 0%, transparent 50%);
            pointer-events: none;
            z-index: 0;
        }

        /* ── Card wrapper ── */
        .login-card {
            display: flex;
            width: 100%;
            max-width: 860px;
            min-height: 520px;
            border-radius: 18px;
            overflow: hidden;
            box-shadow: 0 24px 60px rgba(0,0,0,0.45);
            position: relative;
            z-index: 1;
        }

        /* ── Left: Branding ── */
        .brand-panel {
            flex: 1;
            background-color: var(--red);
            display: flex;
            flex-direction: column;
            justify-content: center;
            padding: 2.75rem;
            position: relative;
            overflow: hidden;
        }

        .brand-panel::before {
            content: '';
            position: absolute;
            width: 280px; height: 280px;
            border-radius: 50%;
            background: rgba(255,255,255,0.07);
            top: -80px; right: -80px;
        }

        .brand-panel::after {
            content: '';
            position: absolute;
            width: 160px; height: 160px;
            border-radius: 50%;
            background: rgba(255,255,255,0.05);
            bottom: -40px; left: 30px;
        }

        .brand-logo {
            display: flex;
            align-items: center;
            gap: 11px;
            margin-bottom: 2rem;
            position: relative;
            z-index: 1;
        }

        .brand-logo-icon {
            width: 46px; height: 46px;
            background: rgba(255,255,255,0.18);
            border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            font-size: 22px; flex-shrink: 0;
        }

        .brand-logo-text h1 {
            font-size: 1.4rem; font-weight: 700;
            color: #fff; letter-spacing: 1px; line-height: 1;
        }

        .brand-logo-text p {
            font-size: 0.68rem;
            color: rgba(255,255,255,0.72);
            margin-top: 3px;
        }

        .brand-tagline { position: relative; z-index: 1; }

        .brand-tagline h2 {
            font-size: 1.75rem; font-weight: 700;
            color: #fff; line-height: 1.25;
            margin-bottom: 0.6rem;
        }

        .brand-tagline > p {
            font-size: 0.83rem;
            color: rgba(255,255,255,0.78);
            line-height: 1.6; max-width: 280px;
        }

        .brand-badges {
            display: flex; flex-wrap: wrap;
            gap: 7px; margin-top: 1.4rem;
            position: relative; z-index: 1;
        }

        .brand-badge {
            background: rgba(255,255,255,0.13);
            border: 1px solid rgba(255,255,255,0.22);
            color: #fff;
            font-size: 0.7rem; font-weight: 500;
            padding: 4px 11px;
            border-radius: 20px;
            display: flex; align-items: center; gap: 5px;
        }

        /* ── Right: Form ── */
        .form-panel {
            width: 360px;
            flex-shrink: 0;
            background: var(--white);
            display: flex;
            flex-direction: column;
            justify-content: center;
            padding: 2.5rem 2rem;
        }

        .form-header { margin-bottom: 1.5rem; }

        .form-header h3 {
            font-size: 1.3rem; font-weight: 700;
            color: var(--text); margin-bottom: 4px;
        }

        .form-header p { font-size: 0.8rem; color: var(--muted); }

        /* Error alert */
        .alert-error {
            background: var(--red-light);
            border: 1px solid #F1948A;
            color: #922B21;
            border-radius: 9px;
            padding: 0.65rem 0.875rem;
            font-size: 0.8rem;
            display: flex; align-items: flex-start; gap: 7px;
            margin-bottom: 1rem;
        }

        /* Form fields */
        .field { margin-bottom: 0.875rem; }
        .field:last-of-type { margin-bottom: 1.25rem; }

        .field label {
            display: block;
            font-size: 0.775rem; font-weight: 600;
            color: var(--text);
            margin-bottom: 5px;
        }

        .field input {
            width: 100%;
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 0.875rem;
            padding: 0.6rem 0.9rem;
            border: 1.5px solid var(--border);
            border-radius: 9px;
            color: var(--text);
            background: #FAFAFA;
            outline: none;
            transition: border-color 0.2s, background 0.2s, box-shadow 0.2s;
        }

        .field input:focus {
            border-color: var(--red);
            background: #fff;
            box-shadow: 0 0 0 3px rgba(176,52,40,0.1);
        }

        .field input.is-invalid { border-color: var(--red); }

        /* Password wrapper */
        .pw-wrap { position: relative; }
        .pw-wrap input { padding-right: 2.6rem; }

        .pw-toggle {
            position: absolute;
            right: 0.7rem; top: 50%;
            transform: translateY(-50%);
            background: none; border: none;
            cursor: pointer; padding: 0;
            color: var(--muted);
            font-size: 1rem;
            display: flex; align-items: center;
            line-height: 1;
        }

        .pw-toggle:hover { color: var(--red); }

        /* Submit button */
        .btn-login {
            width: 100%;
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 0.875rem; font-weight: 600;
            background: var(--red);
            color: #fff;
            border: none; border-radius: 9px;
            padding: 0.68rem 1.25rem;
            cursor: pointer;
            display: flex; align-items: center; justify-content: center; gap: 7px;
            transition: background 0.2s, transform 0.1s;
        }

        .btn-login:hover { background: var(--red-dark); }
        .btn-login:active { transform: scale(0.98); }
        .btn-login:disabled { opacity: 0.7; cursor: not-allowed; }

        /* Divider */
        .divider {
            border: none;
            border-top: 1px solid #EBEBEB;
            margin: 1.4rem 0;
        }

        /* Footer note */
        .account-note { text-align: center; }
        .account-note p { font-size: 0.75rem; color: var(--muted); margin-bottom: 3px; }
        .account-note .account-name { font-size: 0.78rem; color: var(--text); font-weight: 500; }
        .account-note .account-sub { font-size: 0.72rem; color: var(--muted); margin-top: 3px; }

        /* Emergency note */
        .emergency-note {
            background: #FFFBF0;
            border: 1px solid #FAD7A0;
            border-radius: 9px;
            padding: 0.65rem 0.875rem;
            font-size: 0.75rem;
            color: #7D6608;
            display: flex; align-items: flex-start; gap: 7px;
            margin-top: 1.25rem;
        }

        /* ── Mobile: stacked ── */
        @media (max-width: 640px) {
            body { padding: 0; align-items: stretch; }

            .login-card {
                flex-direction: column;
                border-radius: 0;
                box-shadow: none;
                min-height: 100vh;
            }

            .brand-panel {
                padding: 1.75rem 1.5rem;
            }

            .brand-panel::before,
            .brand-panel::after { display: none; }

            .brand-tagline h2 { font-size: 1.3rem; }

            .form-panel {
                width: 100%;
                padding: 2rem 1.5rem;
                flex: 1;
                justify-content: flex-start;
                padding-top: 2rem;
            }
        }
    </style>
</head>
<body>

<div class="login-card">

    <!-- ── Left: Branding ── -->
    <div class="brand-panel">

        <a href="<?= BASE_URL ?>/index.php" class="btn-home">
            <i class="bi bi-arrow-left"></i>
            Back to Home
        </a>

        <div class="brand-logo">
            <div class="brand-logo-icon">
                <i class="bi bi-shield-fill-exclamation" style="color:#fff;"></i>
            </div>
            <div class="brand-logo-text">
                <h1>RESCUE</h1>
                <p>Risk Reduction &amp; Emergency Management</p>
            </div>
        </div>

        <div class="brand-tagline">
            <h2>Stay Safe,<br>Stay Informed.</h2>
            <p>Real-time disaster alerts and emergency response system for Barangay 12.</p>

            <div class="brand-badges">
                <span class="brand-badge">
                    <i class="bi bi-bell-fill" style="font-size:10px;"></i> Live Alerts
                </span>
                <span class="brand-badge">
                    <i class="bi bi-map-fill" style="font-size:10px;"></i> Hazard Map
                </span>
                <span class="brand-badge">
                    <i class="bi bi-people-fill" style="font-size:10px;"></i> Resident Monitoring
                </span>
                <span class="brand-badge">
                    <i class="bi bi-house-fill" style="font-size:10px;"></i> Evacuation Centers
                </span>
            </div>
        </div>

    </div>

    <!-- ── Right: Form ── -->
    <div class="form-panel">

        <div class="form-header">
            <h3>Welcome back</h3>
            <p>Sign in to your RESCUE account to continue.</p>
        </div>

        <?php if (!empty($error)): ?>
        <div class="alert-error" role="alert">
            <i class="bi bi-exclamation-circle-fill" style="flex-shrink:0; margin-top:1px;"></i>
            <span><?= htmlspecialchars($error) ?></span>
        </div>
        <?php endif; ?>

        <form method="POST" action="" id="loginForm" novalidate>

            <div class="field">
                <label for="email">Email Address</label>
                <input
                    type="email"
                    id="email"
                    name="email"
                    placeholder="yourname@email.com"
                    value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                    autocomplete="email"
                    class="<?= !empty($error) ? 'is-invalid' : '' ?>"
                    required
                >
            </div>

            <div class="field">
                <label for="password">Password</label>
                <div class="pw-wrap">
                    <input
                        type="password"
                        id="password"
                        name="password"
                        placeholder="Enter your password"
                        autocomplete="current-password"
                        class="<?= !empty($error) ? 'is-invalid' : '' ?>"
                        required
                    >
                    <button type="button" class="pw-toggle" id="pwToggle" aria-label="Toggle password visibility">
                        <i class="bi bi-eye" id="pwIcon"></i>
                    </button>
                </div>
            </div>

            <button type="submit" class="btn-login" id="loginBtn">
                <span id="btnText">Sign In</span>
                <i class="bi bi-arrow-right" id="btnIcon"></i>
            </button>

        </form>

        <hr class="divider">

        <div class="account-note">
            <p>Don't have an account?</p>
            <p class="account-name">Contact the Barangay 12 office to request access.</p>
            <p class="account-sub">Accounts are issued and managed by barangay officials only.</p>
        </div>

        <div class="emergency-note">
            <i class="bi bi-telephone-fill" style="flex-shrink:0; margin-top:1px; color:#B7770D;"></i>
            <span>
                For emergencies, call <strong>911</strong> or the Barangay Hall directly. Do not wait for system access.
            </span>
        </div>

    </div>

</div>

<!-- Bootstrap Icons JS not needed — icons load via CSS -->

<script>
    // Password toggle
    const pwToggle = document.getElementById('pwToggle');
    const pwInput  = document.getElementById('password');
    const pwIcon   = document.getElementById('pwIcon');

    pwToggle.addEventListener('click', () => {
        const isPass    = pwInput.type === 'password';
        pwInput.type    = isPass ? 'text' : 'password';
        pwIcon.className = isPass ? 'bi bi-eye-slash' : 'bi bi-eye';
    });

    // Loading state on submit
    const loginForm = document.getElementById('loginForm');
    const loginBtn  = document.getElementById('loginBtn');
    const btnText   = document.getElementById('btnText');
    const btnIcon   = document.getElementById('btnIcon');

    loginForm.addEventListener('submit', () => {
        loginBtn.disabled   = true;
        btnText.textContent = 'Signing in...';
        btnIcon.className   = 'bi bi-hourglass-split';
    });
</script>

</body>
</html>