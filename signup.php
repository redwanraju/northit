<?php
session_start();
include "../config/db.php";

$error = "";
$old = [
    'name'      => '',
    'shop_name' => '',
    'email'     => '',
    'phone'     => '',
    'address'   => ''
];

if (empty($_SESSION['signup_csrf'])) {
    $_SESSION['signup_csrf'] = bin2hex(random_bytes(32));
}

/**
 * Preserve the existing shop_id=1 data.
 * The supplied database dump has users and business data under shop_id=1,
 * but the shops table may not yet contain row ID 1. Before creating a new
 * shop, reserve ID 1 for the existing RiceValy installation.
 */
function ensureExistingMainShop(mysqli $conn): void
{
    $check = $conn->prepare("SELECT id FROM shops WHERE id = 1 LIMIT 1");
    $check->execute();
    $exists = $check->get_result()->fetch_assoc();
    $check->close();

    if ($exists) {
        return;
    }

    $legacyOwnerId = null;
    $ownerCheck = $conn->prepare("\n        SELECT id\n        FROM users\n        WHERE shop_id = 1\n          AND role = 'admin'\n        ORDER BY id ASC\n        LIMIT 1\n    ");
    $ownerCheck->execute();
    $owner = $ownerCheck->get_result()->fetch_assoc();
    $ownerCheck->close();

    if ($owner) {
        $legacyOwnerId = (int) $owner['id'];
    }

    $shopName = 'RiceValy Main Shop';
    $phone = null;
    $address = null;

    $insert = $conn->prepare("\n        INSERT INTO shops (id, shop_name, owner_id, phone, address)\n        VALUES (1, ?, ?, ?, ?)\n    ");
    $insert->bind_param('siss', $shopName, $legacyOwnerId, $phone, $address);
    $insert->execute();
    $insert->close();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['signup'])) {
    $old['name']      = trim($_POST['name'] ?? '');
    $old['shop_name'] = trim($_POST['shop_name'] ?? '');
    $old['email']     = strtolower(trim($_POST['email'] ?? ''));
    $old['phone']     = trim($_POST['phone'] ?? '');
    $old['address']   = trim($_POST['address'] ?? '');

    $password        = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    $csrf             = $_POST['csrf_token'] ?? '';

    if (!hash_equals($_SESSION['signup_csrf'] ?? '', $csrf)) {
        $error = 'Invalid request. Please reload the page and try again.';
    } elseif (
        $old['name'] === '' ||
        $old['shop_name'] === '' ||
        $old['email'] === '' ||
        $old['phone'] === '' ||
        $old['address'] === '' ||
        $password === '' ||
        $confirmPassword === ''
    ) {
        $error = 'Please complete all required fields.';
    } elseif (!filter_var($old['email'], FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (mb_strlen($old['name']) > 100) {
        $error = 'Your name cannot exceed 100 characters.';
    } elseif (mb_strlen($old['shop_name']) > 150) {
        $error = 'Shop name cannot exceed 150 characters.';
    } elseif (mb_strlen($old['phone']) > 20) {
        $error = 'Phone number cannot exceed 20 characters.';
    } elseif (strlen($password) < 6) {
        $error = 'Password must contain at least 6 characters.';
    } elseif ($password !== $confirmPassword) {
        $error = 'Password and confirm password do not match.';
    } else {
        try {
            $conn->set_charset('utf8mb4');
            $conn->begin_transaction();

            // Protect all existing shop_id=1 users and records.
            ensureExistingMainShop($conn);

            // Email must remain unique across the whole system.
            $emailCheck = $conn->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
            $emailCheck->bind_param('s', $old['email']);
            $emailCheck->execute();
            $emailExists = $emailCheck->get_result()->fetch_assoc();
            $emailCheck->close();

            if ($emailExists) {
                throw new RuntimeException('This email address is already registered.');
            }

            // Step 1: Create the shop. owner_id is filled after creating the user.
            $shopInsert = $conn->prepare("\n                INSERT INTO shops (shop_name, owner_id, phone, address)\n                VALUES (?, NULL, ?, ?)\n            ");
            $shopInsert->bind_param(
                'sss',
                $old['shop_name'],
                $old['phone'],
                $old['address']
            );
            $shopInsert->execute();
            $shopId = (int) $conn->insert_id;
            $shopInsert->close();

            if ($shopId <= 0) {
                throw new RuntimeException('The shop could not be created.');
            }

            // Step 2: Create the shop's admin using the existing role system.
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            $role = 'admin';
            $status = 1;
            $image = null;

            $userInsert = $conn->prepare("\n                INSERT INTO users\n                    (shop_id, name, email, password, role, status, image)\n                VALUES (?, ?, ?, ?, ?, ?, ?)\n            ");
            $userInsert->bind_param(
                'issssis',
                $shopId,
                $old['name'],
                $old['email'],
                $passwordHash,
                $role,
                $status,
                $image
            );
            $userInsert->execute();
            $userId = (int) $conn->insert_id;
            $userInsert->close();

            if ($userId <= 0) {
                throw new RuntimeException('The admin account could not be created.');
            }

            // Step 3: Connect the created user as this shop's owner.
            $ownerUpdate = $conn->prepare("\n                UPDATE shops\n                SET owner_id = ?\n                WHERE id = ?\n                LIMIT 1\n            ");
            $ownerUpdate->bind_param('ii', $userId, $shopId);
            $ownerUpdate->execute();
            $ownerUpdate->close();

            $conn->commit();

            // Rotate token so the same signup request cannot be replayed.
            $_SESSION['signup_csrf'] = bin2hex(random_bytes(32));

            header('Location: login.php?registered=1');
            exit;

        } catch (Throwable $e) {
            $conn->rollback();

            // Friendly duplicate email handling, including a race condition.
            if ((int) $e->getCode() === 1062) {
                $error = 'This email address is already registered.';
            } elseif ($e instanceof RuntimeException) {
                $error = $e->getMessage();
            } else {
                error_log('RiceValy signup error: ' . $e->getMessage());
                $error = 'Signup could not be completed. Please try again.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Create RiceValy Shop</title>
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

        .signup-wrapper {
            width: 100%;
            max-width: 1120px;
            min-height: 720px;
            background: #fff;
            border-radius: 30px;
            box-shadow: 0 30px 80px rgba(0, 0, 0, .20);
            display: flex;
            overflow: hidden;
        }

        .signup-left {
            flex: .85;
            position: relative;
            display: flex;
            align-items: flex-end;
            padding: 48px 42px;
            color: #fff;
            background:
                linear-gradient(rgba(15, 81, 50, .22), rgba(15, 81, 50, .88)),
                url('assets/images/kholil.jpeg');
            background-size: cover;
            background-position: center;
        }

        .signup-left::after {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(to top, rgba(4, 45, 25, .76), transparent 65%);
        }

        .left-content {
            position: relative;
            z-index: 2;
        }

        .left-content .badge-text {
            display: inline-block;
            padding: 6px 15px;
            margin-bottom: 14px;
            border: 1px solid rgba(255,255,255,.18);
            border-radius: 30px;
            background: rgba(255,255,255,.12);
            backdrop-filter: blur(8px);
            font-size: 11px;
            font-weight: 600;
            letter-spacing: .8px;
            text-transform: uppercase;
        }

        .left-content h1 {
            max-width: 440px;
            margin-bottom: 12px;
            font-size: 38px;
            font-weight: 800;
            line-height: 1.16;
        }

        .left-content h1 span {
            color: #8ce5ab;
        }

        .left-content p {
            max-width: 450px;
            margin: 0;
            color: rgba(255,255,255,.86);
            font-size: 14px;
            line-height: 1.75;
        }

        .feature-list {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
            margin-top: 22px;
        }

        .feature-list span {
            padding: 8px 12px;
            border: 1px solid rgba(255,255,255,.10);
            border-radius: 12px;
            background: rgba(255,255,255,.10);
            font-size: 11px;
            backdrop-filter: blur(5px);
        }

        .signup-right {
            flex: 1.15;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 42px 46px;
            background: #fff;
        }

        .signup-card {
            width: 100%;
            max-width: 560px;
        }

        .logo-area {
            display: flex;
            align-items: center;
            gap: 14px;
            margin-bottom: 24px;
        }

        .logo-area img {
            width: 68px;
            height: 68px;
            object-fit: contain;
            padding: 9px;
            border-radius: 17px;
            background: #f0f5f2;
            box-shadow: 0 4px 15px rgba(0,0,0,.06);
        }

        .logo-area h2 {
            margin: 0;
            color: #0f5132;
            font-size: 27px;
            font-weight: 800;
            letter-spacing: -.5px;
        }

        .logo-area h2 span {
            color: #198754;
        }

        .logo-area p {
            margin: 2px 0 0;
            color: #7a8492;
            font-size: 12px;
        }

        .signup-card h3 {
            margin-bottom: 4px;
            color: #183b2d;
            font-size: 24px;
            font-weight: 750;
        }

        .subtitle {
            margin-bottom: 23px;
            color: #6c757d;
            font-size: 13px;
        }

        .form-label {
            margin-bottom: 6px;
            color: #344054;
            font-size: 12px;
            font-weight: 600;
        }

        .form-control {
            min-height: 48px;
            border: 1px solid #e2e8f0;
            border-radius: 11px;
            padding: 10px 14px;
            background: #fafbfc;
            font-size: 13px;
            transition: .25s ease;
        }

        textarea.form-control {
            min-height: 78px;
            resize: vertical;
        }

        .form-control:focus {
            border-color: #198754;
            background: #fff;
            box-shadow: 0 0 0 4px rgba(25, 135, 84, .10);
        }

        .btn-signup {
            width: 100%;
            height: 50px;
            margin-top: 4px;
            border: 0;
            border-radius: 12px;
            color: #fff;
            background: linear-gradient(135deg, #0f5132, #198754);
            font-size: 14px;
            font-weight: 650;
            transition: .25s ease;
        }

        .btn-signup:hover {
            color: #fff;
            background: linear-gradient(135deg, #0b3d26, #157347);
            box-shadow: 0 10px 25px rgba(25, 135, 84, .28);
            transform: translateY(-2px);
        }

        .alert {
            border-radius: 12px;
            padding: 11px 14px;
            font-size: 12px;
        }

        .login-link {
            margin-top: 17px;
            text-align: center;
            color: #667085;
            font-size: 13px;
        }

        .login-link a {
            color: #198754;
            font-weight: 700;
            text-decoration: none;
        }

        .login-link a:hover {
            color: #0f5132;
            text-decoration: underline;
        }

        .footer-text {
            margin-top: 17px;
            text-align: center;
            color: #98a2b3;
            font-size: 11px;
        }

        .footer-text strong {
            color: #198754;
        }

        @media (max-width: 900px) {
            .signup-wrapper {
                max-width: 620px;
                min-height: auto;
                flex-direction: column;
            }

            .signup-left {
                min-height: 300px;
                padding: 32px 28px;
            }

            .left-content h1 {
                font-size: 30px;
            }

            .signup-right {
                padding: 35px 28px;
            }
        }

        @media (max-width: 520px) {
            body {
                padding: 10px;
            }

            .signup-wrapper {
                border-radius: 22px;
            }

            .signup-left {
                min-height: 245px;
                padding: 25px 20px;
            }

            .left-content h1 {
                font-size: 25px;
            }

            .left-content p {
                font-size: 12px;
            }

            .feature-list {
                grid-template-columns: 1fr;
            }

            .signup-right {
                padding: 28px 18px;
            }

            .logo-area img {
                width: 58px;
                height: 58px;
            }

            .logo-area h2 {
                font-size: 23px;
            }
        }
    </style>
</head>
<body>
<div class="signup-wrapper">
    <section class="signup-left">
        <div class="left-content">
            <span class="badge-text">New Business Registration</span>
            <h1>Create your own <span>RiceValy Shop</span></h1>
            <p>
                Sign up once to create a separate shop and its administrator account.
                Your existing admin, manager and SR role system remains unchanged.
            </p>
            <div class="feature-list">
                <span>🏪 Separate Shop</span>
                <span>👤 Admin Account</span>
                <span>🔐 Secure Password</span>
                <span>📊 Existing Dashboard</span>
            </div>
        </div>
    </section>

    <section class="signup-right">
        <div class="signup-card">
            <div class="logo-area">
                <img src="assets/images/logo.png" alt="RiceValy Logo">
                <div>
                    <h2>Rice<span>Valy</span></h2>
                    <p>ALL TYPES OF RICE</p>
                </div>
            </div>

            <h3>Create Shop Account</h3>
            <p class="subtitle">The signup user will become the admin of the newly created shop.</p>

            <?php if ($error !== '') { ?>
                <div class="alert alert-danger">
                    <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
                </div>
            <?php } ?>

            <form method="POST" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['signup_csrf'], ENT_QUOTES, 'UTF-8') ?>">

                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Your Name *</label>
                        <input
                            type="text"
                            name="name"
                            class="form-control"
                            maxlength="100"
                            value="<?= htmlspecialchars($old['name'], ENT_QUOTES, 'UTF-8') ?>"
                            placeholder="Enter your full name"
                            required
                        >
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Shop Name *</label>
                        <input
                            type="text"
                            name="shop_name"
                            class="form-control"
                            maxlength="150"
                            value="<?= htmlspecialchars($old['shop_name'], ENT_QUOTES, 'UTF-8') ?>"
                            placeholder="Enter shop name"
                            required
                        >
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Email Address *</label>
                        <input
                            type="email"
                            name="email"
                            class="form-control"
                            maxlength="100"
                            value="<?= htmlspecialchars($old['email'], ENT_QUOTES, 'UTF-8') ?>"
                            placeholder="Enter login email"
                            required
                        >
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Phone Number *</label>
                        <input
                            type="text"
                            name="phone"
                            class="form-control"
                            maxlength="20"
                            value="<?= htmlspecialchars($old['phone'], ENT_QUOTES, 'UTF-8') ?>"
                            placeholder="Enter phone number"
                            required
                        >
                    </div>

                    <div class="col-12">
                        <label class="form-label">Shop Address *</label>
                        <textarea
                            name="address"
                            class="form-control"
                            placeholder="Enter complete shop address"
                            required
                        ><?= htmlspecialchars($old['address'], ENT_QUOTES, 'UTF-8') ?></textarea>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Password *</label>
                        <input
                            type="password"
                            name="password"
                            class="form-control"
                            minlength="6"
                            placeholder="Minimum 6 characters"
                            required
                        >
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Confirm Password *</label>
                        <input
                            type="password"
                            name="confirm_password"
                            class="form-control"
                            minlength="6"
                            placeholder="Enter password again"
                            required
                        >
                    </div>

                    <div class="col-12">
                        <button name="signup" type="submit" class="btn-signup">
                            🏪 Create Shop &amp; Admin Account
                        </button>
                    </div>
                </div>
            </form>

            <div class="login-link">
                Already have an account? <a href="login.php">Back to Login</a>
            </div>

            <div class="footer-text">
                Powered by <strong>RiceValy</strong> v2.0
            </div>
        </div>
    </section>
</div>
</body>
</html>
