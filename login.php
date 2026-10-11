<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/session.php';
include "../config/db.php";

// Login CSRF token
if (empty($_SESSION['login_csrf'])) {
    $_SESSION['login_csrf'] = bin2hex(random_bytes(32));
}

function loginRateFile(string $key): string {
    return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'ricevaly_login_' . hash('sha256', $key) . '.json';
}

function loginRateLimited(string $email): bool {
    $key = strtolower(trim($email)) . '|' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $file = loginRateFile($key);
    $now = time();
    $window = 900; // 15 minutes
    $maxAttempts = 5;
    $data = ['attempts' => [], 'blocked_until' => 0];
    $fp = @fopen($file, 'c+');
    if (!$fp) return false;
    flock($fp, LOCK_EX);
    $raw = stream_get_contents($fp);
    if ($raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) $data = array_merge($data, $decoded);
    }
    $data['attempts'] = array_values(array_filter($data['attempts'], fn($ts) => (int)$ts > $now - $window));
    $blocked = (int)($data['blocked_until'] ?? 0) > $now;
    flock($fp, LOCK_UN);
    fclose($fp);
    return $blocked || count($data['attempts']) >= $maxAttempts;
}

function recordLoginFailure(string $email): void {
    $key = strtolower(trim($email)) . '|' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $file = loginRateFile($key);
    $now = time(); $window = 900; $maxAttempts = 5;
    $fp = @fopen($file, 'c+');
    if (!$fp) return;
    flock($fp, LOCK_EX);
    $raw = stream_get_contents($fp);
    $data = $raw ? json_decode($raw, true) : [];
    if (!is_array($data)) $data = [];
    $attempts = array_values(array_filter($data['attempts'] ?? [], fn($ts) => (int)$ts > $now - $window));
    $attempts[] = $now;
    $data = ['attempts' => $attempts, 'blocked_until' => count($attempts) >= $maxAttempts ? $now + $window : 0];
    ftruncate($fp, 0); rewind($fp); fwrite($fp, json_encode($data)); fflush($fp);
    flock($fp, LOCK_UN); fclose($fp);
}

function clearLoginFailures(string $email): void {
    $key = strtolower(trim($email)) . '|' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    @unlink(loginRateFile($key));
}

$error = "";
$registration_success = isset($_GET['registered']) && $_GET['registered'] === '1';

// =============================================
// শুধু মাত্র Admin ইউজারের ফটো আনা
// =============================================
$admin_image = "kholil.jpeg"; // ডিফল্ট
$admin_name = "Ricevaly Admin";
$admin_role = "Admin";

$admin_query = "SELECT image, name, role FROM users WHERE role = 'admin' AND status = 1 LIMIT 1";
$admin_result = $conn->query($admin_query);

if ($admin_result && $admin_result->num_rows > 0) {
    $admin = $admin_result->fetch_assoc();
    if (!empty($admin['image'])) {
        $admin_image = $admin['image'];
    }
    $admin_name = $admin['name'];
    $admin_role = ucfirst($admin['role']);
}

// =============================================
// লগইন প্রসেস
// =============================================
if (isset($_POST['login'])) {

    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $csrf = (string)($_POST['csrf_token'] ?? '');

    if (!hash_equals($_SESSION['login_csrf'] ?? '', $csrf)) {
        $error = 'Invalid request. Please reload the page and try again.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
        $error = 'Invalid email or password.';
    } elseif (loginRateLimited($email)) {
        $error = 'Too many login attempts. Please try again in 15 minutes.';
    } else {

    $stmt = $conn->prepare("
        SELECT id, shop_id, name, email, password, role, status, image
        FROM users
        WHERE email = ?
        LIMIT 1
    ");

    $stmt->bind_param("s", $email);
    $stmt->execute();

    $result = $stmt->get_result();
    $user = $result->fetch_assoc();

    if ($user) {

        if ((int)$user['status'] !== 1) {
            // Do not reveal whether an email is registered.
            recordLoginFailure($email);
            $error = "Invalid email or password.";
        }
        elseif (password_verify($password, $user['password'])) {

            // Prevent an old account/shop cart or session identifier from carrying over.
            session_regenerate_id(true);
            unset($_SESSION['cart']);

            $_SESSION['user_id'] = $user['id'];
            $_SESSION['shop_id'] = $user['shop_id'];
            $_SESSION['name']    = $user['name'];
            $_SESSION['email']   = $user['email'];
            $_SESSION['role']    = $user['role'];
            $_SESSION['image']   = $user['image'] ?? 'default.png';
            $_SESSION['login_csrf'] = bin2hex(random_bytes(32));
            clearLoginFailures($email);

            if ($user['role'] == 'admin' || $user['role'] == 'sr') {
                header("Location: ../pos/index.php");
            }
            else {
                header("Location: ../manager/index.php");
            }
            exit;

        } else {
            recordLoginFailure($email);
            usleep(250000);
            $error = "Invalid email or password.";
        }

    } else {
        recordLoginFailure($email);
        usleep(250000);
        $error = "Invalid email or password.";
    }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Ricevaly Login</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    <style>
        * {
            font-family: 'Poppins', sans-serif;
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            min-height: 100vh;
            background: linear-gradient(135deg, #0f5132 0%, #198754 50%, #d4e9d6 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .login-wrapper {
            width: 100%;
            max-width: 1050px;
            height: 620px;
            background: #ffffff;
            border-radius: 30px;
            box-shadow: 0 30px 80px rgba(0, 0, 0, 0.20);
            display: flex;
            overflow: hidden;
        }

        /* =============================================
                   LEFT SIDE - ADMIN PHOTO FROM DATABASE
                   ============================================= */
        .login-left {
            flex: 1.2;
            background: 
                linear-gradient(rgba(15, 81, 50, 0.20), rgba(15, 81, 50, 0.55)),
                url('assets/images/<?= htmlspecialchars($admin_image) ?>');
            background-size: cover;
            background-position: center;
            display: flex;
            align-items: flex-end;
            padding: 45px 40px;
            position: relative;
        }

        .login-left::before {
            content: "";
            position: absolute;
            inset: 0;
            background: linear-gradient(to top, rgba(15,81,50,0.7) 0%, transparent 50%);
        }

        .login-left .overlay-text {
            position: relative;
            z-index: 2;
            color: #fff;
            width: 100%;
        }

        .login-left .overlay-text .admin-badge {
            display: inline-block;
            background: rgba(255,255,255,0.12);
            backdrop-filter: blur(8px);
            padding: 5px 16px;
            border-radius: 30px;
            font-size: 11px;
            font-weight: 500;
            letter-spacing: 1px;
            text-transform: uppercase;
            border: 1px solid rgba(255,255,255,0.08);
            margin-bottom: 10px;
        }

        .login-left .overlay-text .admin-badge .role-badge {
            background: #ffd700;
            color: #0f5132;
            padding: 2px 10px;
            border-radius: 12px;
            margin-left: 6px;
            font-size: 10px;
            font-weight: 700;
        }

        .login-left .overlay-text h1 {
            font-size: 34px;
            font-weight: 700;
            margin-bottom: 2px;
            text-shadow: 0 2px 20px rgba(0,0,0,0.2);
        }

        .login-left .overlay-text h1 span {
            color: #7ddfa0;
        }

        .login-left .overlay-text .admin-name {
            font-size: 22px;
            font-weight: 600;
            margin: 0;
            text-shadow: 0 2px 10px rgba(0,0,0,0.3);
        }

        .login-left .overlay-text .admin-role-text {
            font-size: 14px;
            opacity: 0.8;
            margin: 0;
            font-weight: 300;
        }

        .login-left .overlay-text .badge-group {
            display: flex;
            gap: 10px;
            margin-top: 12px;
            flex-wrap: wrap;
        }

        .login-left .overlay-text .badge-group span {
            font-size: 11px;
            background: rgba(255,255,255,0.12);
            padding: 4px 14px;
            border-radius: 20px;
            backdrop-filter: blur(4px);
            border: 1px solid rgba(255,255,255,0.06);
        }

        /* =============================================
                   RIGHT SIDE - LOGO + LOGIN FORM
                   ============================================= */
        .login-right {
            flex: 0.9;
            background: #ffffff;
            padding: 50px 45px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .login-card {
            width: 100%;
            max-width: 380px;
        }

        .login-card .logo-area {
            text-align: center;
            margin-bottom: 30px;
        }

        .login-card .logo-area img {
            width: 85px;
            height: 85px;
            object-fit: contain;
            background: #f0f5f2;
            padding: 12px;
            border-radius: 20px;
            margin-bottom: 10px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.06);
        }

        .login-card .logo-area h2 {
            font-size: 26px;
            font-weight: 800;
            color: #0f5132;
            margin: 0;
            letter-spacing: -0.5px;
        }

        .login-card .logo-area h2 span {
            color: #198754;
        }

        .login-card .logo-area p {
            color: #6c757d;
            font-size: 13px;
            margin: 0;
            font-weight: 300;
        }

        .login-card h3 {
            font-weight: 700;
            color: #183b2d;
            margin-bottom: 4px;
            font-size: 22px;
        }

        .login-card .subtitle {
            color: #6c757d;
            font-size: 14px;
            margin-bottom: 25px;
        }

        .form-label {
            font-size: 13px;
            font-weight: 500;
            color: #344054;
            margin-bottom: 6px;
        }

        .form-control {
            height: 50px;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            padding: 10px 16px;
            font-size: 14px;
            transition: all 0.25s ease;
            background: #fafbfc;
        }

        .form-control:focus {
            border-color: #198754;
            box-shadow: 0 0 0 4px rgba(25, 135, 84, 0.10);
            background: #fff;
        }

        .btn-login {
            height: 50px;
            border: none;
            border-radius: 12px;
            background: linear-gradient(135deg, #0f5132, #198754);
            color: #fff;
            font-weight: 600;
            font-size: 15px;
            transition: all 0.25s ease;
            width: 100%;
        }

        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(25, 135, 84, 0.30);
            background: linear-gradient(135deg, #0b3d26, #157347);
            color: #fff;
        }

        .alert {
            border-radius: 12px;
            font-size: 13px;
            padding: 12px 14px;
        }

        .signup-link {
            margin-top: 18px;
            text-align: center;
            font-size: 13px;
            color: #667085;
        }

        .signup-link a {
            color: #198754;
            font-weight: 700;
            text-decoration: none;
        }

        .signup-link a:hover {
            color: #0f5132;
            text-decoration: underline;
        }

        .footer-text {
            margin-top: 22px;
            text-align: center;
            font-size: 12px;
            color: #98a2b3;
        }

        .footer-text strong {
            color: #198754;
        }

        /* =============================================
                   RESPONSIVE
                   ============================================= */
        @media (max-width: 820px) {
            .login-wrapper {
                flex-direction: column;
                max-width: 440px;
                height: auto;
                min-height: 600px;
                border-radius: 24px;
            }

            .login-left {
                height: 300px;
                padding: 30px 25px;
                border-radius: 24px 24px 0 0;
                align-items: flex-end;
            }

            .login-left .overlay-text h1 {
                font-size: 26px;
            }

            .login-right {
                padding: 35px 25px;
                border-radius: 0 0 24px 24px;
            }
        }

        @media (max-width: 480px) {
            .login-left {
                height: 240px;
                padding: 20px;
            }

            .login-left .overlay-text h1 {
                font-size: 22px;
            }

            .login-left .overlay-text .admin-name {
                font-size: 18px;
            }

            .login-left .overlay-text .badge-group span {
                font-size: 10px;
                padding: 3px 10px;
            }

            .login-right {
                padding: 25px 18px;
            }

            .login-card .logo-area img {
                width: 65px;
                height: 65px;
            }

            .login-card .logo-area h2 {
                font-size: 22px;
            }
        }
    </style>
</head>

<body>

<div class="login-wrapper">

    <!-- ============================================= -->
    <!-- LEFT SIDE - ADMIN PHOTO FROM DATABASE          -->
    <!-- ============================================= -->
    <div class="login-left">
        <div class="overlay-text">
            <div class="admin-badge">
                👑 System Administrator
                <span class="role-badge">ADMIN</span>
            </div>
            <h1>Kholilur<span>Rahman</span></h1>
 
            <p class="admin-role-text"><?= htmlspecialchars($admin_role) ?> ·CEO & Managing Director of RiceValy and Uttara Khaddo Vandar</p>
            <div class="badge-group">
                <span>🍚 Basmati</span>
                <span>🍚 Chinglia</span>
                <span>🍚 Miniketi</span>
                <span>🍚 Katarhong</span>
                <span>🍚 Atap</span>
            </div>
        </div>
    </div>

    <!-- ============================================= -->
    <!-- RIGHT SIDE - LOGO + LOGIN FORM               -->
    <!-- ============================================= -->
    <div class="login-right">
        <div class="login-card">

            <!-- Logo Area - আপনার logo.png ব্যবহার করছে -->
            <div class="logo-area">
                <img src="assets/images/logo.png" alt="Ricevaly Logo">
                <h2>Rice<span>Valy</span></h2>
                <p>ALL TYPES OF RICE</p>
            </div>

            <h3>Welcome Back</h3>
            <p class="subtitle">Login to manage your business</p>

            <?php if ($registration_success) { ?>
                <div class="alert alert-success">
                    Shop and admin account created successfully. Please login.
                </div>
            <?php } ?>

            <?php if ($error) { ?>
                <div class="alert alert-danger">
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php } ?>

            <form method="POST" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['login_csrf'] ?? '', ENT_QUOTES, 'UTF-8') ?>">

                <div class="mb-3">
                    <label class="form-label">Email Address</label>
                    <input 
                        type="email" 
                        name="email" 
                        class="form-control" 
                        placeholder="Enter your email"
                        required
                    >
                </div>

                <div class="mb-4">
                    <label class="form-label">Password</label>
                    <input 
                        type="password" 
                        name="password" 
                        class="form-control" 
                        placeholder="Enter your password"
                        required
                    >
                </div>

                <button name="login" type="submit" class="btn-login">
                    🔐 Login Now
                </button>

            </form>

            <div class="signup-link">
                Don't have a shop account? <a href="signup.php">Create Shop &amp; Sign Up</a>
            </div>

            <div class="footer-text">
                Powered by <strong>RiceValy</strong> v2.0
            </div>

        </div>
    </div>

</div>

</body>
</html>