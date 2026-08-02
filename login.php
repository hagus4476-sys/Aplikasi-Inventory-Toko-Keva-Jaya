<?php
session_start();
require_once 'config/database.php';
if (isset($_SESSION['user'])) {
    if ($_SESSION['user']['role'] == 'admin') header('Location: admin/dashboard.php');
    else header('Location: owner/dashboard.php');
    exit;
}
$error = '';
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = $_POST['username'];
    $password = $_POST['password'];
    $stmt = $conn->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows == 1) {
        $user = $result->fetch_assoc();
        if ($password === $user['password']) {
            $_SESSION['user'] = $user;
            if ($user['role'] == 'admin') header('Location: admin/dashboard.php');
            else header('Location: owner/dashboard.php');
            exit;
        } else $error = 'Password salah!';
    } else $error = 'Username tidak ditemukan!';
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login — Keva Jaya</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        :root {
            --bg: #f5f4f0;
            --surface: #ffffff;
            --surface-2: #f9f8f5;
            --border: #e8e6e0;
            --border-strong: #d4d0c8;
            --text-primary: #1a1916;
            --text-secondary: #6b6860;
            --text-muted: #9c9890;
            --accent: #2d6a4f;
            --accent-light: #e8f4ee;
            --accent-hover: #245a42;
            --danger: #c0392b;
            --danger-light: #fdecea;
            --radius: 10px;
            --radius-sm: 6px;
            --shadow-sm: 0 1px 3px rgba(0,0,0,0.06), 0 1px 2px rgba(0,0,0,0.04);
            --shadow: 0 4px 24px rgba(0,0,0,0.08), 0 1px 4px rgba(0,0,0,0.04);
            --shadow-lg: 0 20px 60px rgba(0,0,0,0.10), 0 4px 16px rgba(0,0,0,0.06);
            --transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        }

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: var(--bg);
            color: var(--text-primary);
            min-height: 100vh;
            font-size: 14px;
            line-height: 1.6;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            overflow: hidden;
        }

        /* ─── BACKGROUND PATTERN ─── */
        body::before {
            content: '';
            position: fixed;
            inset: 0;
            background:
                radial-gradient(ellipse 80% 50% at 20% 20%, rgba(45,106,79,0.06) 0%, transparent 60%),
                radial-gradient(ellipse 60% 60% at 80% 80%, rgba(45,106,79,0.04) 0%, transparent 60%);
            pointer-events: none;
        }

        /* subtle dot grid */
        body::after {
            content: '';
            position: fixed;
            inset: 0;
            background-image: radial-gradient(circle, var(--border-strong) 1px, transparent 1px);
            background-size: 28px 28px;
            opacity: 0.45;
            pointer-events: none;
        }

        /* ─── WRAPPER ─── */
        .login-wrapper {
            position: relative;
            z-index: 1;
            width: 100%;
            max-width: 420px;
            padding: 20px;
            animation: fadeUp 0.4s cubic-bezier(0.34, 1.56, 0.64, 1) both;
        }
        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(24px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        /* ─── CARD ─── */
        .login-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 16px;
            box-shadow: var(--shadow-lg);
            overflow: hidden;
        }

        /* ─── CARD HEADER ─── */
        .login-header {
            background: var(--text-primary);
            padding: 32px 36px 28px;
            text-align: center;
            position: relative;
            overflow: hidden;
        }
        .login-header::before {
            content: '';
            position: absolute;
            top: -40px; right: -40px;
            width: 140px; height: 140px;
            border-radius: 50%;
            background: rgba(255,255,255,0.03);
        }
        .login-header::after {
            content: '';
            position: absolute;
            bottom: -30px; left: -20px;
            width: 100px; height: 100px;
            border-radius: 50%;
            background: rgba(255,255,255,0.03);
        }

        .brand-icon {
            width: 52px; height: 52px;
            background: var(--accent);
            border-radius: var(--radius-sm);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            color: white;
            margin-bottom: 14px;
            position: relative;
            z-index: 1;
        }
        .brand-name {
            font-size: 20px;
            font-weight: 700;
            color: white;
            letter-spacing: -0.4px;
            display: block;
            position: relative;
            z-index: 1;
        }
        .brand-sub {
            font-size: 12.5px;
            color: rgba(255,255,255,0.4);
            font-weight: 400;
            letter-spacing: 0.3px;
            display: block;
            margin-top: 3px;
            position: relative;
            z-index: 1;
        }

        /* ─── CARD BODY ─── */
        .login-body {
            padding: 28px 32px 32px;
        }

        .login-title {
            font-size: 15px;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 4px;
        }
        .login-desc {
            font-size: 13px;
            color: var(--text-muted);
            margin-bottom: 24px;
        }

        /* ─── ALERT ─── */
        .alert {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 11px 14px;
            border-radius: var(--radius);
            font-size: 13px;
            font-weight: 450;
            margin-bottom: 20px;
            animation: shake 0.35s ease both;
        }
        @keyframes shake {
            0%,100% { transform: translateX(0); }
            20%      { transform: translateX(-6px); }
            40%      { transform: translateX(6px); }
            60%      { transform: translateX(-4px); }
            80%      { transform: translateX(4px); }
        }
        .alert-danger {
            background: var(--danger-light);
            color: var(--danger);
            border: 1px solid #f5b7b1;
        }
        .alert i { font-size: 15px; flex-shrink: 0; }

        /* ─── FORM ─── */
        .form-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
            margin-bottom: 16px;
        }
        .form-label {
            font-size: 12.5px;
            font-weight: 600;
            color: var(--text-secondary);
            letter-spacing: 0.2px;
        }

        .input-wrap {
            position: relative;
        }
        .input-icon {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 15px;
            color: var(--text-muted);
            pointer-events: none;
            transition: var(--transition);
        }
        .form-control {
            width: 100%;
            padding: 10px 12px 10px 38px;
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            font-size: 13.5px;
            font-family: inherit;
            color: var(--text-primary);
            background: var(--surface);
            transition: var(--transition);
        }
        .form-control:focus {
            outline: none;
            border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(45,106,79,0.1);
        }
        .form-control:focus + .input-icon,
        .input-wrap:focus-within .input-icon {
            color: var(--accent);
        }
        .form-control::placeholder { color: var(--text-muted); }

        /* password toggle */
        .input-wrap .toggle-pw {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            cursor: pointer;
            color: var(--text-muted);
            font-size: 15px;
            padding: 4px;
            line-height: 1;
            transition: var(--transition);
        }
        .input-wrap .toggle-pw:hover { color: var(--text-secondary); }
        .form-control.has-toggle { padding-right: 38px; }

        /* ─── SUBMIT BUTTON ─── */
        .btn-login {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            width: 100%;
            padding: 11px 16px;
            background: var(--accent);
            color: white;
            border: none;
            border-radius: var(--radius-sm);
            font-size: 14px;
            font-weight: 700;
            font-family: inherit;
            cursor: pointer;
            transition: var(--transition);
            margin-top: 8px;
            letter-spacing: 0.1px;
        }
        .btn-login:hover {
            background: var(--accent-hover);
            transform: translateY(-1px);
            box-shadow: 0 4px 14px rgba(45,106,79,0.3);
        }
        .btn-login:active { transform: translateY(0); }
        .btn-login i { font-size: 16px; }

        /* ─── FOOTER ─── */
        .login-footer {
            text-align: center;
            margin-top: 24px;
            font-size: 12px;
            color: var(--text-muted);
        }
        .login-footer span {
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        .login-footer i { font-size: 11px; }

        /* ─── SCROLLBAR ─── */
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: var(--border-strong); border-radius: 99px; }

        @media (max-width: 480px) {
            .login-body  { padding: 24px 20px 28px; }
            .login-header { padding: 28px 20px 24px; }
        }
    </style>
</head>
<body>

<div class="login-wrapper">
    <div class="login-card">

        <!-- HEADER -->
        <div class="login-header">
            <div class="brand-icon"><i class="bi bi-box-seam"></i></div>
            <span class="brand-name">Keva Jaya</span>
            <span class="brand-sub">Inventory System</span>
        </div>

        <!-- BODY -->
        <div class="login-body">
            <div class="login-title">Masuk ke Sistem</div>
            <div class="login-desc">Gunakan akun yang diberikan oleh administrator</div>

            <?php if ($error): ?>
            <div class="alert alert-danger">
                <i class="bi bi-exclamation-circle-fill"></i>
                <?= htmlspecialchars($error) ?>
            </div>
            <?php endif; ?>

            <form method="POST" autocomplete="off">

                <div class="form-group">
                    <label class="form-label" for="username">Username</label>
                    <div class="input-wrap">
                        <input
                            type="text"
                            id="username"
                            name="username"
                            class="form-control"
                            placeholder="Masukkan username"
                            value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                            required
                            autofocus
                        >
                        <i class="bi bi-person input-icon"></i>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="password">Password</label>
                    <div class="input-wrap">
                        <input
                            type="password"
                            id="password"
                            name="password"
                            class="form-control has-toggle"
                            placeholder="Masukkan password"
                            required
                        >
                        <i class="bi bi-lock input-icon"></i>
                        <button type="button" class="toggle-pw" onclick="togglePassword()" title="Tampilkan/sembunyikan password">
                            <i class="bi bi-eye" id="eyeIcon"></i>
                        </button>
                    </div>
                </div>

                <button type="submit" class="btn-login">
                    <i class="bi bi-box-arrow-in-right"></i> Masuk ke Sistem
                </button>

            </form>
        </div>
    </div>

    <!-- FOOTER -->
    <div class="login-footer">
        <span><i class="bi bi-shield-check"></i> Akses terbatas — hanya untuk pengguna terdaftar</span>
    </div>
</div>

<script>
    function togglePassword() {
        const pw  = document.getElementById('password');
        const ico = document.getElementById('eyeIcon');
        if (pw.type === 'password') {
            pw.type = 'text';
            ico.className = 'bi bi-eye-slash';
        } else {
            pw.type = 'password';
            ico.className = 'bi bi-eye';
        }
    }
</script>
</body>
</html>