<?php
session_start();

// Pastikan file koneksi database di-include
require_once '../../../app/auth/auth.php';

// Function untuk mencatat error ke file log
function logError($message)
{
    $log_file = 'error.log';
    $timestamp = date('Y-m-d H:i:s');
    $log_message = "[$timestamp] $message\n";
    error_log($log_message, 3, $log_file);
}

// Check if user is logged in, if not redirect to login page
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header('Location: ../../../login.php');
    exit;
}

date_default_timezone_set('Asia/Jakarta');

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

$error_message = '';
$success_message = '';

if (isset($_POST['logout']) && $_POST['logout'] == 'yes') {
    try {
        if (!isset($pdo) || !($pdo instanceof PDO)) {
            throw new Exception("Koneksi database tidak tersedia");
        }

        if (!isset($_SESSION['id'])) {
            throw new Exception("User ID tidak ditemukan dalam session");
        }

        $user_id = $_SESSION['id'];
        $device_info_legacy = getBrowser();
        $device_info = getDeviceFingerprint();

        $pdo->beginTransaction();

        do {
            $random_id = random_int(100000, 999999);
            $check_sql = "SELECT COUNT(*) FROM log_akses WHERE id = :random_id";
            $check_stmt = $pdo->prepare($check_sql);
            $check_stmt->bindParam(':random_id', $random_id, PDO::PARAM_INT);
            $check_stmt->execute();
            $exists = $check_stmt->fetchColumn();
        } while ($exists > 0);

        $sql_log = "INSERT INTO log_akses (
            id, user_id, waktu, ip_address, device_info, device_hash,
            device_details, status
        ) VALUES (
            :random_id, :user_id, NOW(), :ip_address, :device_info,
            :device_hash, :device_details, 'logout'
        )";

        $stmt_log = $pdo->prepare($sql_log);

        $result = $stmt_log->execute([
            ':random_id'      => $random_id,
            ':user_id'        => $user_id,
            ':ip_address'     => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
            ':device_info'    => $device_info_legacy,
            ':device_hash'    => $device_info['hash'],
            ':device_details' => $device_info['details']
        ]);

        if (!$result) {
            throw new Exception("Gagal mencatat log logout");
        }

        $pdo->commit();

        session_unset();
        session_destroy();

        while (ob_get_level()) {
            ob_end_clean();
        }

        header("Location: ../../../login.php");
        exit;

    } catch (PDOException $e) {
        $pdo->rollBack();
        logError("Database error during logout: " . $e->getMessage());
        $error_message = "Database error: " . $e->getMessage();
    } catch (Exception $e) {
        if (isset($pdo)) {
            $pdo->rollBack();
        }
        logError("General error during logout: " . $e->getMessage());
        $error_message = "Terjadi kesalahan saat proses logout: " . $e->getMessage();
    }
}

if (isset($pdo)) {
    unset($pdo);
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Si Hadir - Logout</title>
    <link rel="icon" type="image/x-icon" href="../../../assets/icon/favicon.ico" />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        *, *::before, *::after {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --blue-600: #2563eb;
            --red-50: #fef2f2;
            --red-100: #fee2e2;
            --red-300: #fca5a5;
            --red-600: #dc2626;
            --red-700: #b91c1c;
            --gray-50: #f9fafb;
            --gray-100: #f3f4f6;
            --gray-200: #e5e7eb;
            --gray-400: #9ca3af;
            --gray-500: #6b7280;
            --gray-700: #374151;
            --gray-900: #111827;
            --white: #ffffff;
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
            max-width: 400px;
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        /* ── Brand ── */
        .brand {
            text-align: center;
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
            font-size: 20px;
            color: var(--white);
        }

        .brand h1 {
            font-size: 24px;
            font-weight: 600;
            letter-spacing: -0.5px;
            color: var(--gray-900);
            margin-bottom: 4px;
        }

        .brand h1 span { color: var(--blue-600); }

        /* ── Card ── */
        .card {
            background: var(--white);
            border: 1px solid var(--gray-200);
            border-radius: var(--radius-lg);
            padding: 2rem 1.75rem;
            text-align: center;
        }

        .logout-icon-wrap {
            width: 56px;
            height: 56px;
            background: var(--red-50);
            border: 1px solid var(--red-100);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1.1rem;
            font-size: 22px;
            color: var(--red-600);
        }

        .card h2 {
            font-size: 17px;
            font-weight: 600;
            color: var(--gray-900);
            margin-bottom: 6px;
        }

        .card p {
            font-size: 13px;
            color: var(--gray-500);
            line-height: 1.6;
            margin-bottom: 1.5rem;
        }

        /* ── Error alert ── */
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
            text-align: left;
            line-height: 1.5;
        }

        .alert i { margin-top: 1px; flex-shrink: 0; }

        /* ── Buttons ── */
        .btn-group {
            display: flex;
            gap: 10px;
        }

        .btn {
            flex: 1;
            height: 42px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            border-radius: var(--radius-md);
            font-size: 14px;
            font-weight: 500;
            font-family: inherit;
            cursor: pointer;
            text-decoration: none;
            border: none;
            transition: background 0.15s ease, transform 0.1s ease;
        }

        .btn:hover { transform: translateY(-1px); }
        .btn:active { transform: translateY(0); }

        .btn-danger {
            background: var(--red-600);
            color: var(--white);
        }

        .btn-danger:hover { background: var(--red-700); }

        .btn-cancel {
            background: var(--gray-100);
            color: var(--gray-700);
            border: 1px solid var(--gray-200);
        }

        .btn-cancel:hover { background: var(--gray-200); }

        /* ── Footer ── */
        .footer-note {
            text-align: center;
            font-size: 11px;
            color: var(--gray-400);
        }

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
        <h1>Si <span>Hadir</span></h1>
    </div>

    <!-- Card -->
    <div class="card">

        <div class="logout-icon-wrap">
            <i class="fas fa-sign-out-alt"></i>
        </div>

        <h2>Keluar dari sistem?</h2>
        <p>Anda akan keluar dari sesi ini. Pastikan semua pekerjaan sudah tersimpan sebelum melanjutkan.</p>

        <?php if (!empty($error_message)): ?>
            <div class="alert">
                <i class="fas fa-exclamation-circle"></i>
                <span><?php echo htmlspecialchars($error_message); ?></span>
            </div>
        <?php endif; ?>

        <div class="btn-group">
            <a href="attendance.php" class="btn btn-cancel">
                <i class="fas fa-arrow-left"></i>
                Kembali
            </a>
            <form action="" method="post" style="flex:1; display:flex;">
                <input type="hidden" name="logout" value="yes">
                <button type="submit" class="btn btn-danger" style="width:100%;">
                    <i class="fas fa-sign-out-alt"></i>
                    Ya, keluar
                </button>
            </form>
        </div>

    </div>

    <p class="footer-note">Si Hadir &mdash; Sing Long Brother</p>

</div>

</body>
</html>