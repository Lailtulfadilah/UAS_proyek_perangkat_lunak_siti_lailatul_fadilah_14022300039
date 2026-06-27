<?php
session_start();

include 'app/auth/auth.php';

// Set timezone
date_default_timezone_set('Asia/Jakarta');

// Check if there are any users in the database
$sql_check_users = "SELECT COUNT(*) FROM users";
$stmt_check_users = $pdo->prepare($sql_check_users);
$stmt_check_users->execute();
$user_count = $stmt_check_users->fetchColumn();

// Redirect if no users in the database
if ($user_count == 0) {
    $_SESSION['setup'] = true;
    header('Location: start.php');
    exit;
}

// Define variables and initialize with empty values
$username = "";
$password = "";
$error_message = "";
$show_error = false;

// Browser detection function
function getBrowser()
{
    $userAgent = $_SERVER['HTTP_USER_AGENT'];
    $browser = "Unknown Browser";
    $os = "Unknown OS";

    if (preg_match('/MSIE/i', $userAgent) || preg_match('/Trident/i', $userAgent)) {
        $browser = 'Internet Explorer';
    } elseif (preg_match('/Firefox/i', $userAgent)) {
        $browser = 'Mozilla Firefox';
    } elseif (preg_match('/Chrome/i', $userAgent)) {
        $browser = 'Google Chrome';
    } elseif (preg_match('/Safari/i', $userAgent) && !preg_match('/Chrome/i', $userAgent)) {
        $browser = 'Apple Safari';
    } elseif (preg_match('/Opera/i', $userAgent) || preg_match('/OPR/i', $userAgent)) {
        $browser = 'Opera';
    } elseif (preg_match('/Edge/i', $userAgent)) {
        $browser = 'Microsoft Edge';
    }

    if (preg_match('/win/i', $userAgent)) {
        $os = 'Windows';
    } elseif (preg_match('/macintosh|mac os x/i', $userAgent)) {
        $os = 'Mac OS';
    } elseif (preg_match('/linux/i', $userAgent)) {
        if (preg_match('/android/i', $userAgent)) {
            $os = 'Android';
        } else {
            $os = 'Linux';
        }
    } elseif (preg_match('/iphone os/i', $userAgent)) {
        $os = 'iOS (iPhone)';
    } elseif (preg_match('/ipad/i', $userAgent)) {
        $os = 'iPadOS';
    } elseif (preg_match('/ipod/i', $userAgent)) {
        $os = 'iOS (iPod)';
    } elseif (preg_match('/windows phone/i', $userAgent)) {
        $os = 'Windows Phone';
    }

    return "$browser | $os";
}

// Device fingerprinting function
function getDeviceFingerprint()
{
    $fingerprint = [];
    $userAgent = $_SERVER['HTTP_USER_AGENT'];

    if (preg_match('/\((.*?)\)/', $userAgent, $matches)) {
        $fingerprint['platform'] = $matches[1];
    }

    $fingerprint['screen'] = '<script>document.write(screen.width+"x"+screen.height+"x"+screen.colorDepth);</script>';
    $fingerprint['timezone'] = date_default_timezone_get();

    if (isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
        $fingerprint['languages'] = $_SERVER['HTTP_ACCEPT_LANGUAGE'];
    }

    $headers = ['HTTP_ACCEPT', 'HTTP_ACCEPT_ENCODING', 'HTTP_ACCEPT_CHARSET'];
    foreach ($headers as $header) {
        if (isset($_SERVER[$header])) {
            $fingerprint[$header] = $_SERVER[$header];
        }
    }

    $fingerprint['is_mobile'] = preg_match('/(android|bb\d+|meego).+mobile|avantgo|bada\/|blackberry|blazer|compal|elaine|fennec|hiptop|iemobile|ip(hone|od)|iris|kindle|lge |maemo|midp|mmp|mobile.+firefox|netfront|opera m(ob|in)i|palm( os)?|phone|p(ixi|re)\/|plucker|pocket|psp|series(4|6)0|symbian|treo|up\.(browser|link)|vodafone|wap|windows ce|xda|xiino/i', $userAgent) ? 'true' : 'false';

    $deviceString = implode('|', array_filter($fingerprint));
    $deviceHash = hash('sha256', $deviceString);

    return ['hash' => $deviceHash, 'details' => json_encode($fingerprint)];
}

function hasRegisteredDevice($pdo, $user_id)
{
    $sql = "SELECT COUNT(*) FROM log_akses WHERE user_id = :user_id AND device_hash IS NOT NULL";
    $stmt = $pdo->prepare($sql);
    $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchColumn() > 0;
}

function isMatchingDevice($pdo, $user_id, $device_hash)
{
    $sql = "SELECT device_hash FROM log_akses 
            WHERE user_id = :user_id 
            AND device_hash IS NOT NULL 
            ORDER BY waktu ASC LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
    $stmt->execute();
    $registeredHash = $stmt->fetchColumn();
    return $device_hash === $registeredHash;
}

function loginUser($pdo, $user, $device_info, $status)
{
    $_SESSION['loggedin'] = true;
    $_SESSION['username'] = $user['username'];
    $_SESSION['role'] = $user['role'];
    $_SESSION['id'] = $user['id'];

    $random_id = random_int(100000, 999999);
    $device_info_legacy = getBrowser();

    $sql_log = "INSERT INTO log_akses (id, user_id, waktu, ip_address, device_info, device_hash, device_details, status) 
                VALUES (:random_id, :user_id, NOW(), :ip_address, :device_info, :device_hash, :device_details, :status)";
    $stmt_log = $pdo->prepare($sql_log);
    $stmt_log->execute([
        ':random_id'      => $random_id,
        ':user_id'        => $user['id'],
        ':ip_address'     => $_SERVER['REMOTE_ADDR'],
        ':device_info'    => $device_info_legacy,
        ':device_hash'    => $device_info['hash'],
        ':device_details' => $device_info['details'],
        ':status'         => $status
    ]);

    header('Location: ' . ($user['role'] == 'owner' ? 'app/pages/owner/dashboard.php' : 'app/pages/staff/attendance.php'));
    exit;
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['login'])) {
    $username = trim($_POST["username"]);
    $password = trim($_POST["password"]);
    $device_info = getDeviceFingerprint();

    if (empty($username)) {
        $error_message = "Mohon masukkan username.";
        $show_error = true;
    } elseif (empty($password)) {
        $error_message = "Mohon masukkan password.";
        $show_error = true;
        $password = "";
    } else {
        $sql = "SELECT id, username, password, role FROM users WHERE username = :username";
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->bindParam(":username", $username, PDO::PARAM_STR);
            $stmt->execute();

            if ($stmt->rowCount() == 1) {
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if (password_verify($password, $row['password'])) {
                    if (hasRegisteredDevice($pdo, $row['id'])) {
                        if (!isMatchingDevice($pdo, $row['id'], $device_info['hash'])) {
                            $error_message = "Perangkat tidak dikenal. Mohon gunakan perangkat yang sudah terdaftar atau hubungi owner.";
                            $show_error = true;
                            $username = "";
                            $password = "";
                        } else {
                            loginUser($pdo, $row, $device_info, 'login');
                        }
                    } else {
                        loginUser($pdo, $row, $device_info, 'first_registration');
                    }
                } else {
                    $error_message = "Username atau password salah.";
                    $show_error = true;
                    $username = "";
                    $password = "";
                }
            } else {
                $error_message = "Username atau password salah.";
                $show_error = true;
                $username = "";
                $password = "";
            }
        } catch (PDOException $e) {
            $error_message = "Terjadi kesalahan sistem. Silakan coba lagi.";
            $show_error = true;
            $username = "";
            $password = "";
        }
    }
} else {
    $error_message = "";
    $show_error = false;
}

unset($pdo);
?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Si Hadir - Login</title>
    <link rel="icon" type="image/x-icon" href="assets/icon/favicon.ico" />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        *, *::before, *::after {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --blue-50: #eff6ff;
            --blue-100: #dbeafe;
            --blue-500: #3b82f6;
            --blue-600: #2563eb;
            --blue-700: #1d4ed8;
            --red-50: #fef2f2;
            --red-100: #fee2e2;
            --red-300: #fca5a5;
            --red-700: #b91c1c;
            --gray-50: #f9fafb;
            --gray-100: #f3f4f6;
            --gray-200: #e5e7eb;
            --gray-300: #d1d5db;
            --gray-400: #9ca3af;
            --gray-500: #6b7280;
            --gray-600: #4b5563;
            --gray-700: #374151;
            --gray-800: #1f2937;
            --gray-900: #111827;
            --white: #ffffff;
            --radius-sm: 6px;
            --radius-md: 10px;
            --radius-lg: 14px;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background-color: var(--gray-50);
            color: var(--gray-900);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
        }

        .page {
            width: 100%;
            max-width: 420px;
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        /* ── Brand header ── */
        .brand {
            text-align: center;
            padding-bottom: 0.25rem;
        }

        .brand-logo {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 48px;
            height: 48px;
            background: var(--blue-600);
            border-radius: var(--radius-lg);
            margin-bottom: 0.9rem;
            font-size: 22px;
            color: var(--white);
        }

        .brand h1 {
            font-size: 26px;
            font-weight: 600;
            letter-spacing: -0.5px;
            color: var(--gray-900);
            margin-bottom: 4px;
        }

        .brand h1 span { color: var(--blue-600); }

        .brand p {
            font-size: 13px;
            color: var(--gray-500);
        }

        /* ── Card ── */
        .card {
            background: var(--white);
            border: 1px solid var(--gray-200);
            border-radius: var(--radius-lg);
            padding: 2rem 1.75rem;
        }

        /* ── Alert ── */
        .alert {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            background: var(--red-50);
            border: 1px solid var(--red-300);
            border-radius: var(--radius-md);
            padding: 0.75rem 1rem;
            margin-bottom: 1.25rem;
            font-size: 13px;
            color: var(--red-700);
            line-height: 1.5;
        }

        .alert i {
            margin-top: 1px;
            flex-shrink: 0;
            font-size: 14px;
        }

        /* ── Form ── */
        .form {
            display: flex;
            flex-direction: column;
            gap: 1.1rem;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .label {
            font-size: 13px;
            font-weight: 500;
            color: var(--gray-700);
        }

        .input-wrap {
            position: relative;
        }

        .input-icon {
            position: absolute;
            left: 13px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 14px;
            color: var(--gray-400);
            pointer-events: none;
        }

        .input {
            width: 100%;
            height: 42px;
            padding: 0 13px 0 38px;
            border-radius: var(--radius-md);
            border: 1px solid var(--gray-200);
            background: var(--gray-50);
            font-size: 14px;
            font-family: inherit;
            color: var(--gray-900);
            outline: none;
            transition: border-color 0.15s ease, background 0.15s ease;
        }

        .input:focus {
            border-color: var(--blue-500);
            background: var(--white);
        }

        .input::placeholder { color: var(--gray-400); }

        /* ── Toggle password ── */
        .toggle-pw {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            cursor: pointer;
            color: var(--gray-400);
            font-size: 14px;
            padding: 0;
            line-height: 1;
        }

        .toggle-pw:hover { color: var(--gray-600); }

        /* ── Forgot link ── */
        .forgot {
            text-align: right;
            margin-top: -4px;
        }

        .forgot a {
            font-size: 12px;
            color: var(--blue-600);
            text-decoration: none;
            font-weight: 500;
        }

        .forgot a:hover { text-decoration: underline; }

        /* ── Submit btn ── */
        .btn-submit {
            margin-top: 0.5rem;
            width: 100%;
            height: 42px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            background: var(--blue-600);
            color: var(--white);
            border: none;
            border-radius: var(--radius-md);
            font-size: 14px;
            font-weight: 500;
            font-family: inherit;
            cursor: pointer;
            transition: background 0.15s ease, transform 0.1s ease;
        }

        .btn-submit:hover {
            background: var(--blue-700);
            transform: translateY(-1px);
        }

        .btn-submit:active { transform: translateY(0); }

        /* ── Footer note ── */
        .footer-note {
            text-align: center;
            font-size: 11px;
            color: var(--gray-400);
            padding-top: 0.25rem;
        }

        /* ── Responsive ── */
        @media (max-width: 480px) {
            .card { padding: 1.5rem 1.25rem; }
        }
    </style>
</head>

<body>
    <div class="page">

        <!-- Brand -->
        <div class="brand">
            <div class="brand-logo">
                <i class="fas fa-user-check"></i>
            </div>
            <h1>SI<span>HADIR</span></h1>
            <h3>SING<span>LONG BROTHER</span></h3>
            <p>Sistem Informasi Kehadiran</p>
        </div>

        <!-- Card -->
        <div class="card">

            <?php if (!empty($error_message)): ?>
                <div class="alert" id="error-alert">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?php echo htmlspecialchars($error_message); ?></span>
                </div>
                <script>
                    var errorAlert = document.getElementById('error-alert');
                    if (errorAlert) {
                        setTimeout(function () {
                            errorAlert.style.opacity = '0';
                            errorAlert.style.transition = 'opacity 0.4s ease';
                            setTimeout(function () { errorAlert.style.display = 'none'; }, 400);
                        }, 4000);
                    }
                </script>
            <?php endif; ?>

            <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post" class="form">

                <div class="form-group">
                    <label for="username" class="label">Username</label>
                    <div class="input-wrap">
                        <i class="fas fa-user input-icon"></i>
                        <input
                            type="text"
                            id="username"
                            name="username"
                            class="input"
                            placeholder="Masukkan username"
                            value="<?php echo htmlspecialchars($username); ?>"
                            autocomplete="username"
                            required>
                    </div>
                </div>

                <div class="form-group">
                    <label for="password" class="label">Password</label>
                    <div class="input-wrap">
                        <i class="fas fa-lock input-icon"></i>
                        <input
                            type="password"
                            id="password"
                            name="password"
                            class="input"
                            placeholder="Masukkan password"
                            autocomplete="current-password"
                            required>
                        <button type="button" class="toggle-pw" onclick="togglePassword()" aria-label="Tampilkan password">
                            <i class="fas fa-eye" id="pw-icon"></i>
                        </button>
                    </div>
                </div>

                <div class="forgot">
                    <a href="app/recovery/forgotPassword.php">Lupa username atau password?</a>
                </div>

                <button type="submit" name="login" value="1" class="btn-submit">
                    <i class="fas fa-sign-in-alt"></i>
                    Masuk
                </button>

            </form>
        </div>

        <p class="footer-note">Si Hadir &mdash; Sing Long Brother</p>

    </div>

    <script>
        function togglePassword() {
            var input = document.getElementById('password');
            var icon = document.getElementById('pw-icon');
            if (input.type === 'password') {
                input.type = 'text';
                icon.className = 'fas fa-eye-slash';
            } else {
                input.type = 'password';
                icon.className = 'fas fa-eye';
            }
        }
    </script>
</body>

</html>