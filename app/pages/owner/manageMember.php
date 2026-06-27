<?php
session_start();

require_once '../../../app/auth/auth.php';

// Check if user is logged in
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header('Location: ../../../login.php');
    exit;
}

// Check if the user role is employee
if (isset($_SESSION['role']) && $_SESSION['role'] !== 'owner') {
    // Unset session variables and destroy session
    session_unset();
    session_destroy();

    // Set headers to prevent caching
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Cache-dControl: post-check=0, pre-check=0', false);
    header('Pragma: no-cache');

    header('Location: ../../../login.php');
    exit;
}

// Fungsi untuk mendapatkan data shift dalam format JSON
if (isset($_GET['get_shift_details']) && isset($_GET['shift_id'])) {
    $stmt = $pdo->prepare("SELECT jam_masuk, jam_keluar FROM shift WHERE id = ?");
    $stmt->execute([$_GET['shift_id']]);
    $shiftDetails = $stmt->fetch(PDO::FETCH_ASSOC);
    header('Content-Type: application/json');
    echo json_encode($shiftDetails);
    exit;
}

// Fetch divisions from database
$stmt = $pdo->prepare("SELECT id, nama_divisi FROM divisi");
$stmt->execute();
$divisi_names = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

// Fetch shifts from database
$stmt = $pdo->prepare("SELECT id, nama_shift FROM shift");
$stmt->execute();
$shifts = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

$jenis_kelamin_options = [
    'laki' => 'Laki-laki',
    'perempuan' => 'Perempuan'
];

// Get users with their division names (excluding owner role)
$stmt = $pdo->prepare("
    SELECT DISTINCT u.*, p.divisi_id, p.hari_libur, d.nama_divisi,
    js.shift_id, s.nama_shift, o.otp_code
    FROM users u 
    LEFT JOIN pegawai p ON u.id = p.user_id 
    LEFT JOIN divisi d ON p.divisi_id = d.id
    LEFT JOIN jadwal_shift js ON p.id = js.pegawai_id
    LEFT JOIN shift s ON js.shift_id = s.id
    LEFT JOIN otp_code o ON u.id_otp = o.id
    WHERE u.role != 'owner'
    AND (js.tanggal = CURRENT_DATE OR js.tanggal IS NULL)
");

$stmt->execute();
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_user'])) {
    try {
        $pdo->beginTransaction();

        // Check for duplicate nama_lengkap (case insensitive)
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE LOWER(nama_lengkap) = LOWER(:nama_lengkap)");
        $stmt->execute(['nama_lengkap' => $_POST['nama_lengkap']]);
        if ($stmt->fetchColumn() > 0) {
            throw new Exception("Nama staff sudah terdaftar");
        }

        // Check for duplicate email (case insensitive)
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE LOWER(email) = LOWER(:email)");
        $stmt->execute(['email' => $_POST['email']]);
        if ($stmt->fetchColumn() > 0) {
            throw new Exception("Email sudah terdaftar");
        }

        // Check for duplicate username (case insensitive)
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE LOWER(username) = LOWER(:username)");
        $stmt->execute(['username' => $_POST['username']]);
        if ($stmt->fetchColumn() > 0) {
            throw new Exception("Username sudah digunakan");
        }

        // Check for duplicate phone number
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE no_telp = :no_telp");
        $stmt->execute(['no_telp' => $_POST['no_telp']]);
        if ($stmt->fetchColumn() > 0) {
            throw new Exception("Nomor telepon sudah terdaftar");
        }

        // First, insert into otp_code table
        $stmt = $pdo->prepare("INSERT INTO otp_code (otp_code) VALUES ('000000')");
        $stmt->execute();
        $otpId = $pdo->lastInsertId();

        // Insert into users table with id_otp
        $stmt = $pdo->prepare("
            INSERT INTO users (nama_lengkap, email, username, jenis_kelamin, password, no_telp, role, id_otp)
            VALUES (:nama_lengkap, :email, :username, :jenis_kelamin, :password, :no_telp, 'karyawan', :id_otp)
        ");

        $stmt->execute([
            'nama_lengkap' => $_POST['nama_lengkap'],
            'email' => $_POST['email'],
            'username' => $_POST['username'],
            'password' => password_hash($_POST['password'], PASSWORD_DEFAULT),
            'no_telp' => $_POST['no_telp'],
            'jenis_kelamin' => $_POST['jenis_kelamin'],
            'id_otp' => $otpId
        ]);

        $userId = $pdo->lastInsertId();

        // Validate hari_libur
        $validHariLibur = array('senin', 'selasa', 'rabu', 'kamis', 'jumat', 'sabtu', 'minggu');
        $hari_libur = strtolower(trim($_POST['hari_libur']));

        if (!in_array($hari_libur, $validHariLibur)) {
            throw new Exception("Nilai hari libur tidak valid. Nilai yang diperbolehkan: " . implode(', ', $validHariLibur));
        }

        // Insert into pegawai table with hari_libur
        $stmt = $pdo->prepare("
            INSERT INTO pegawai (user_id, divisi_id, hari_libur, status_aktif)
            VALUES (:user_id, :divisi_id, :hari_libur, 'aktif')
        ");

        $stmt->execute([
            'user_id' => $userId,
            'divisi_id' => $_POST['divisi_id'],
            'hari_libur' => $hari_libur
        ]);

        $pegawaiId = $pdo->lastInsertId();

        // Insert into jadwal_shift table (without hari_libur)
        $stmt = $pdo->prepare("
            INSERT INTO jadwal_shift (pegawai_id, shift_id, tanggal, status)
            VALUES (:pegawai_id, :shift_id, CURRENT_DATE, 'aktif')
        ");

        $stmt->execute([
            'pegawai_id' => $pegawaiId,
            'shift_id' => $_POST['shift_id']
        ]);

        $pdo->commit();
        $_SESSION['alert'] = [
            'type' => 'success',
            'message' => 'Member berhasil ditambahkan!'
        ];
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['alert'] = [
            'type' => 'danger',
            'message' => 'Gagal menambahkan member: ' . $e->getMessage()
        ];
    }
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Add division
if (isset($_POST['add_division'])) {
    try {
        $stmt = $pdo->prepare("INSERT INTO divisi (nama_divisi) VALUES (:nama_divisi)");
        $stmt->execute(['nama_divisi' => $_POST['nama_divisi']]);

        $_SESSION['toast'] = [
            'type' => 'success',
            'message' => 'Divisi berhasil ditambahkan!'
        ];
    } catch (PDOException $e) {
        $_SESSION['toast'] = [
            'type' => 'error',
            'message' => 'Gagal menambahkan divisi: ' . $e->getMessage()
        ];
    }
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Delete division
if (isset($_POST['delete_division'])) {
    try {
        $stmt = $pdo->prepare("DELETE FROM divisi WHERE id = :id");
        $stmt->execute(['id' => $_POST['division_id']]);

        $_SESSION['toast'] = [
            'type' => 'success',
            'message' => 'Divisi berhasil dihapus!'
        ];
    } catch (PDOException $e) {
        $_SESSION['toast'] = [
            'type' => 'error',
            'message' => 'Gagal menghapus divisi: ' . $e->getMessage()
        ];
    }
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Update user with password
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_user'])) {
    try {
        $pdo->beginTransaction();

        // Check for duplicate nama_lengkap (case insensitive)
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE LOWER(nama_lengkap) = LOWER(:nama_lengkap) AND id != :user_id");
        $stmt->execute([
            'nama_lengkap' => $_POST['nama_lengkap'],
            'user_id' => $_POST['user_id']
        ]);
        if ($stmt->fetchColumn() > 0) {
            throw new Exception("Nama staff sudah terdaftar");
        }

        // Check for duplicate email (case insensitive)
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE LOWER(email) = LOWER(:email) AND id != :user_id");
        $stmt->execute([
            'email' => $_POST['email'],
            'user_id' => $_POST['user_id']
        ]);
        if ($stmt->fetchColumn() > 0) {
            throw new Exception("Email sudah terdaftar");
        }

        // Check for duplicate username (case insensitive)
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE LOWER(username) = LOWER(:username) AND id != :user_id");
        $stmt->execute([
            'username' => $_POST['username'],
            'user_id' => $_POST['user_id']
        ]);
        if ($stmt->fetchColumn() > 0) {
            throw new Exception("Username sudah digunakan");
        }

        // Check for duplicate phone number
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE no_telp = :no_telp AND id != :user_id");
        $stmt->execute([
            'no_telp' => $_POST['no_telp'],
            'user_id' => $_POST['user_id']
        ]);
        if ($stmt->fetchColumn() > 0) {
            throw new Exception("Nomor telepon sudah terdaftar");
        }

        // Update user table
        $stmt = $pdo->prepare("
         UPDATE users
         SET nama_lengkap = :nama_lengkap,
             email = :email,
             username = :username,
             jenis_kelamin = :jenis_kelamin,
             no_telp = :no_telp
         WHERE id = :user_id
        ");

        $stmt->execute([
            'nama_lengkap' => $_POST['nama_lengkap'],
            'email' => $_POST['email'],
            'username' => $_POST['username'],
            'no_telp' => $_POST['no_telp'],
            'jenis_kelamin' => $_POST['jenis_kelamin'],
            'user_id' => $_POST['user_id']
        ]);

        // Validate hari_libur
        $validHariLibur = array('senin', 'selasa', 'rabu', 'kamis', 'jumat', 'sabtu', 'minggu');
        $hari_libur = strtolower(trim($_POST['edit_hari_libur']));

        if (!in_array($hari_libur, $validHariLibur)) {
            throw new Exception("Nilai hari libur tidak valid. Nilai yang diperbolehkan: " . implode(', ', $validHariLibur));
        }

        // Update pegawai table with hari_libur
        $stmt = $pdo->prepare("
         UPDATE pegawai
         SET divisi_id = :divisi_id,
             hari_libur = :hari_libur
         WHERE user_id = :user_id
        ");
        $stmt->execute([
            'divisi_id' => $_POST['divisi_id'],
            'hari_libur' => $hari_libur,
            'user_id' => $_POST['user_id']
        ]);

        // Update shift in jadwal_shift (without hari_libur)
        $stmt = $pdo->prepare("
         UPDATE jadwal_shift
         SET shift_id = :shift_id
         WHERE pegawai_id = (
             SELECT id 
             FROM pegawai
             WHERE user_id = :user_id
         )
         AND tanggal = CURRENT_DATE
     ");

        $stmt->execute([
            'shift_id' => $_POST['shift_id'],
            'user_id' => $_POST['user_id']
        ]);

        $pdo->commit();
        $_SESSION['alert'] = [
            'type' => 'success',
            'message' => 'Member berhasil diupdate!'
        ];
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['alert'] = [
            'type' => 'danger',
            'message' => 'Gagal mengupdate member: ' . $e->getMessage()
        ];
    }
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}


if (isset($_POST['delete_user'])) {
    try {
        $pdo->beginTransaction();

        // Get pegawai_id first since we'll need it for other deletions
        $stmt = $pdo->prepare("SELECT id FROM pegawai WHERE user_id = :user_id");
        $stmt->execute(['user_id' => $_POST['user_id']]);
        $pegawai = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($pegawai) {
            // Delete from izin first (references pegawai)
            $stmt = $pdo->prepare("DELETE FROM izin WHERE pegawai_id = :pegawai_id");
            $stmt->execute(['pegawai_id' => $pegawai['id']]);

            // Delete from cuti (references pegawai)
            $stmt = $pdo->prepare("DELETE FROM cuti WHERE pegawai_id = :pegawai_id");
            $stmt->execute(['pegawai_id' => $pegawai['id']]);

            // Delete from absensi (references pegawai)
            $stmt = $pdo->prepare("DELETE FROM absensi WHERE pegawai_id = :pegawai_id");
            $stmt->execute(['pegawai_id' => $pegawai['id']]);

            // Delete from jadwal_shift (references pegawai)
            $stmt = $pdo->prepare("DELETE FROM jadwal_shift WHERE pegawai_id = :pegawai_id");
            $stmt->execute(['pegawai_id' => $pegawai['id']]);

            // Delete from pegawai (references user)
            $stmt = $pdo->prepare("DELETE FROM pegawai WHERE id = :pegawai_id");
            $stmt->execute(['pegawai_id' => $pegawai['id']]);
        }

        // Delete from log_akses (references user)
        $stmt = $pdo->prepare("DELETE FROM log_akses WHERE user_id = :user_id");
        $stmt->execute(['user_id' => $_POST['user_id']]);

        // Finally delete from users
        $stmt = $pdo->prepare("DELETE FROM users WHERE id = :user_id");
        $stmt->execute(['user_id' => $_POST['user_id']]);

        if ($otpId) {
            $stmt = $pdo->prepare("DELETE FROM otp_code WHERE id = :otp_id");
            $stmt->execute(['otp_id' => $otpId]);
        }

        $pdo->commit();
        $_SESSION['alert'] = [
            'type' => 'success',
            'message' => 'User dan semua data terkait berhasil dihapus!'
        ];
    } catch (PDOException $e) {
        $pdo->rollBack();
        $_SESSION['alert'] = [
            'type' => 'danger',
            'message' => 'Gagal menghapus user: ' . $e->getMessage()
        ];
    }
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Di bagian remove device
if (isset($_POST['remove_device'])) {
    try {
        $stmt = $pdo->prepare("DELETE FROM log_akses WHERE user_id = :user_id");
        $stmt->execute(['user_id' => $_POST['user_id']]);

        $_SESSION['alert'] = [
            'type' => 'success',
            'message' => 'Device berhasil dihapus!'
        ];
    } catch (PDOException $e) {
        $_SESSION['alert'] = [
            'type' => 'danger',
            'message' => 'Gagal menghapus device: ' . $e->getMessage()
        ];
    }
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}
?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no" />
    <meta name="description" content="SIHADIR - Sistem Kehadiran Sing Long Brother Industrial" />
    <meta name="author" content="Sing Long Brother Industrial" />
    <title>SIHADIR | Manajemen Staff</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <!-- Script untuk Bootstrap JS (jika perlu) -->
    <script src="https://code.jquery.com/jquery-3.5.1.slim.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.6/dist/umd/popper.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
    <!-- Favicon-->
    <link rel="icon" type="image/x-icon" href="../../../assets/icon/favicon.ico" />
    <!-- Core theme CSS (includes Bootstrap)-->
    <link href="../../../assets/css/styles.css" rel="stylesheet" />
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Sora:wght@600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

    <style>
        :root {
            --slb-navy: #0b1f3a;
            --slb-navy-light: #122a4f;
            --slb-amber: #f5a623;
            --slb-amber-dark: #d98c0f;
            --slb-teal: #0fb5ae;
            --slb-bg: #f3f5f9;
            --slb-card: #ffffff;
            --slb-text: #1f2937;
            --slb-muted: #6b7280;
            --sbw: 268px;
        }

        * { font-family: 'Plus Jakarta Sans', sans-serif; }
        h1, h2, h3, h5, .brand-font { font-family: 'Sora', sans-serif; }

        html, body {
            background: var(--slb-bg);
            color: var(--slb-text);
        }

        /* ===== LAYOUT ===== */
        #wrapper {
            display: flex;
            min-height: 100vh;
        }

        /* ===== SIDEBAR ===== */
        #sidebar-wrapper {
            width: var(--sbw);
            min-width: var(--sbw);
            background: linear-gradient(180deg, var(--slb-navy) 0%, var(--slb-navy-light) 100%);
            color: #e5e9f2;
            position: sticky;
            top: 0;
            height: 100vh;
            overflow-y: auto;
            transition: margin-left 0.3s ease;
            z-index: 1040;
        }

        #sidebar-wrapper.collapsed {
            margin-left: calc(var(--sbw) * -1);
        }

        .sidebar-brand {
            padding: 28px 22px 22px;
            border-bottom: 1px solid rgba(255,255,255,0.08);
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .sidebar-brand .logo-badge {
            width: 42px;
            height: 42px;
            border-radius: 12px;
            background: linear-gradient(135deg, var(--slb-amber), var(--slb-amber-dark));
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            color: var(--slb-navy);
            font-size: 18px;
            flex-shrink: 0;
            box-shadow: 0 4px 10px rgba(245,166,35,0.35);
        }

        .sidebar-brand .brand-title {
            font-size: 19px;
            font-weight: 800;
            color: #fff;
            line-height: 1.1;
            letter-spacing: 0.5px;
        }

        .sidebar-brand .brand-sub {
            font-size: 10.5px;
            color: var(--slb-amber);
            text-transform: uppercase;
            letter-spacing: 1px;
            font-weight: 600;
        }

        .sidebar-section-label {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 1.2px;
            color: #6f87ad;
            padding: 18px 22px 8px;
            font-weight: 700;
        }

        .nav-item-slb {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 11px 22px;
            margin: 2px 12px;
            border-radius: 10px;
            color: #cdd7ea;
            text-decoration: none;
            font-size: 14.5px;
            font-weight: 500;
            transition: all 0.2s ease;
        }

        .nav-item-slb svg, .nav-item-slb i {
            width: 19px;
            text-align: center;
            opacity: 0.85;
        }

        .nav-item-slb:hover {
            background: rgba(255,255,255,0.08);
            color: #fff;
            transform: translateX(2px);
        }

        .nav-item-slb.active {
            background: linear-gradient(135deg, var(--slb-amber), var(--slb-amber-dark));
            color: var(--slb-navy);
            font-weight: 700;
            box-shadow: 0 4px 12px rgba(245,166,35,0.3);
        }

        .nav-item-slb.logout-link {
            color: #ff9a9a;
        }
        .nav-item-slb.logout-link:hover {
            background: rgba(255,80,80,0.12);
            color: #ffb3b3;
        }

        .sidebar-divider-slb {
            height: 1px;
            background: rgba(255,255,255,0.08);
            margin: 14px 22px;
        }

        /* ===== TOPBAR ===== */
        #page-content-wrapper {
            flex: 1;
            min-width: 0;
            display: flex;
            flex-direction: column;
        }

        .topbar-slb {
            background: var(--slb-card);
            border-bottom: 1px solid #e9ecf2;
            padding: 14px 28px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 1020;
            box-shadow: 0 1px 3px rgba(16,24,40,0.04);
        }

        .burger-btn {
            background: var(--slb-navy);
            border: none;
            color: #fff;
            width: 40px;
            height: 40px;
            border-radius: 10px;
            font-size: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background 0.2s;
        }
        .burger-btn:hover { background: var(--slb-navy-light); }

        .topbar-title {
            font-weight: 700;
            color: var(--slb-navy);
            font-size: 15px;
        }
        .topbar-date {
            font-size: 12.5px;
            color: var(--slb-muted);
        }

        /* ===== PAGE CONTENT ===== */
        .page-body-slb {
            padding: 26px;
        }

        .page-heading-slb {
            font-weight: 800;
            font-size: 26px;
            color: var(--slb-navy);
            margin-bottom: 2px;
        }
        .page-subheading-slb {
            color: var(--slb-muted);
            font-size: 13.5px;
            margin-bottom: 22px;
        }

        /* ===== CARDS ===== */
        .slb-card {
            background: var(--slb-card);
            border-radius: 16px;
            border: 1px solid #edf0f5;
            box-shadow: 0 2px 8px rgba(16,24,40,0.04);
            padding: 22px;
            margin-bottom: 22px;
        }

        /* ===== TOOLBAR ===== */
        .toolbar-slb {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 20px;
        }
        .toolbar-left {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }

        .btn-slb-primary {
            background: linear-gradient(135deg, var(--slb-amber), var(--slb-amber-dark));
            color: var(--slb-navy);
            border: none;
            font-weight: 700;
            padding: 10px 20px;
            border-radius: 10px;
            font-size: 13.5px;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .btn-slb-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 18px rgba(245,166,35,0.35);
            color: var(--slb-navy);
        }
        .btn-slb-secondary {
            background: #e7f1fd;
            color: #1c7ed6;
            border: none;
            font-weight: 700;
            padding: 10px 20px;
            border-radius: 10px;
            font-size: 13.5px;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .btn-slb-secondary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 18px rgba(28,126,214,0.25);
            color: #1c7ed6;
        }

        .filter-input-slb {
            border: 1px solid #e2e6ee;
            border-radius: 10px;
            padding: 9px 14px;
            font-size: 13.5px;
            background: #fff;
            color: var(--slb-text);
            width: 100%;
            max-width: 260px;
        }
        .filter-input-slb:focus {
            outline: none;
            border-color: var(--slb-amber);
            box-shadow: 0 0 0 3px rgba(245,166,35,0.15);
        }

        /* ===== ALERTS ===== */
        .alert {
            padding: 14px 18px;
            margin-bottom: 18px;
            border: 1px solid transparent;
            border-radius: 12px;
            font-size: 13.5px;
            position: relative;
            transition: opacity 0.5s ease;
        }
        .alert-success {
            color: #0d9d58;
            background-color: #e9f9f0;
            border-color: #c9eedb;
        }
        .alert-danger {
            color: #e0334d;
            background-color: #fdebec;
            border-color: #f8d0d6;
        }
        .alert .close {
            position: relative;
            font-size: 20px;
            font-weight: bold;
            color: inherit;
            text-decoration: none;
            opacity: 0.7;
            cursor: pointer;
            background: none;
            border: none;
            padding: 0 0 0 15px;
        }
        .alert .close:hover { opacity: 1; }
        .fade-out { opacity: 0; }

        /* ===== TABLE ===== */
        .custom-scrollbar::-webkit-scrollbar { display: none; }
        .custom-scrollbar {
            -ms-overflow-style: none;
            scrollbar-width: none;
        }
        table.slb-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            font-size: 13.5px;
        }
        table.slb-table thead th {
            background: #f6f8fb;
            color: var(--slb-muted);
            text-transform: uppercase;
            font-size: 11px;
            letter-spacing: 0.6px;
            font-weight: 700;
            padding: 12px 16px;
            text-align: center;
            white-space: nowrap;
        }
        table.slb-table thead th:first-child { border-radius: 10px 0 0 10px; }
        table.slb-table thead th:last-child { border-radius: 0 10px 10px 0; }
        table.slb-table tbody td {
            padding: 13px 16px;
            text-align: center;
            white-space: nowrap;
            border-bottom: 1px solid #f1f3f7;
        }
        table.slb-table tbody tr:hover td {
            background: #fbfbfd;
        }

        .action-btn-slb {
            border: none;
            border-radius: 999px;
            padding: 8px 16px;
            font-size: 12.5px;
            font-weight: 600;
            transition: all 0.2s;
        }
        .action-btn-edit { background: #e9f9f0; color: #0d9d58; }
        .action-btn-edit:hover { background: #d3f1e2; }
        .action-btn-delete { background: #fdebec; color: #e0334d; }
        .action-btn-delete:hover { background: #fbd6d9; }
        .action-btn-device { background: #e7f1fd; color: #1c7ed6; }
        .action-btn-device:hover { background: #d3e6fb; }

        /* ===== MODAL — DIPERCANTIK ===== */
        .modal-content {
            border: none;
            border-radius: 18px;
            overflow: hidden;
            box-shadow: 0 24px 60px rgba(11,31,58,0.25);
        }
        .modal-header {
            background: linear-gradient(135deg, var(--slb-navy) 0%, var(--slb-navy-light) 100%);
            border-bottom: none;
            padding: 20px 24px;
            position: relative;
        }
        .modal-header::after {
            content: '';
            position: absolute;
            left: 24px;
            bottom: 0;
            width: 44px;
            height: 3px;
            background: var(--slb-amber);
            border-radius: 4px 4px 0 0;
        }
        .modal-title {
            color: #fff;
            font-weight: 700;
            font-size: 17px;
            font-family: 'Sora', sans-serif;
        }
        .modal-header .btn-close {
            filter: invert(1) grayscale(100%) brightness(200%);
            opacity: 0.85;
        }
        .modal-header .btn-close:hover { opacity: 1; }
        .modal-body {
            padding: 24px;
            background: #fbfcfe;
        }
        .modal-footer {
            border-top: 1px solid #eef0f5;
            background: #fff;
            padding: 16px 24px;
        }
        .modal .form-label {
            font-size: 12.5px;
            font-weight: 700;
            color: var(--slb-navy);
            text-transform: uppercase;
            letter-spacing: 0.4px;
            margin-bottom: 6px;
        }
        .modal .form-control,
        .modal .form-select {
            border: 1px solid #e2e6ee;
            border-radius: 10px;
            padding: 9px 14px;
            font-size: 13.5px;
            background: #fff;
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        .modal .form-control:focus,
        .modal .form-select:focus {
            border-color: var(--slb-amber);
            box-shadow: 0 0 0 3px rgba(245,166,35,0.15);
        }
        .modal .btn-primary {
            background: linear-gradient(135deg, var(--slb-amber), var(--slb-amber-dark));
            border: none;
            color: var(--slb-navy);
            font-weight: 700;
            border-radius: 10px;
            padding: 8px 20px;
        }
        .modal .btn-primary:hover {
            box-shadow: 0 8px 18px rgba(245,166,35,0.3);
        }
        .modal .btn-secondary {
            background: #eef0f4;
            border: none;
            color: var(--slb-muted);
            font-weight: 600;
            border-radius: 10px;
            padding: 8px 20px;
        }
        .modal .btn-secondary:hover { background: #e2e5eb; }
        .modal .btn-danger {
            background: linear-gradient(135deg, #e0334d, #c4263c);
            border: none;
            font-weight: 700;
            border-radius: 10px;
            padding: 8px 20px;
        }
        .modal .btn-danger:hover {
            box-shadow: 0 8px 18px rgba(224,51,77,0.3);
        }

        /* Division list inside modal */
        .division-row {
            background: #fff;
            border: 1px solid #eef0f5;
            border-radius: 10px;
            padding: 10px 14px;
            margin-bottom: 8px;
        }
        .division-row span {
            font-weight: 600;
            color: var(--slb-text);
            font-size: 13.5px;
        }
        .division-row .btn-danger {
            padding: 5px 14px;
            font-size: 12px;
        }

        /* ===== Overlay for mobile sidebar ===== */
        .sidebar-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(11,31,58,0.5);
            z-index: 1030;
        }
        .sidebar-overlay.show { display: block; }

        /* ===== RESPONSIVE ===== */
        @media (max-width: 991.98px) {
            #sidebar-wrapper {
                position: fixed;
                left: 0;
                margin-left: calc(var(--sbw) * -1);
            }
            #sidebar-wrapper.mobile-open {
                margin-left: 0;
                box-shadow: 10px 0 30px rgba(0,0,0,0.25);
            }
        }

        @media (max-width: 575.98px) {
            .topbar-slb { padding: 12px 16px; }
            .page-body-slb { padding: 16px; }
            .page-heading-slb { font-size: 21px; }
            .slb-card { padding: 16px; border-radius: 14px; }
            .topbar-date { display: none; }
            .toolbar-slb {
                flex-direction: column;
                align-items: stretch;
            }
            .filter-input-slb {
                max-width: 100%;
            }
        }
    </style>

</head>

<body>
    <div id="wrapper">

        <!-- Overlay for mobile -->
        <div class="sidebar-overlay" id="sidebarOverlay"></div>

        <!-- Sidebar-->
        <div id="sidebar-wrapper">
            <div class="sidebar-brand">
                <div class="logo-badge">SB</div>
                <div>
                    <div class="brand-title">SIHADIR</div>
                    <div class="brand-sub">Sing Long Brother</div>
                </div>
            </div>

            <div class="sidebar-section-label">Menu Utama</div>
            <a class="nav-item-slb" href="dashboard.php">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 -960 960 960" width="19" height="19" fill="currentColor">
                    <path d="M520-600v-240h320v240H520ZM120-440v-400h320v400H120Zm400 320v-400h320v400H520Zm-400 0v-240h320v240H120Zm80-400h160v-240H200v240Zm400 320h160v-240H600v240Zm0-480h160v-80H600v80ZM200-200h160v-80H200v80Zm160-320Zm240-160Zm0 240ZM360-280Z" />
                </svg>
                Dashboard
            </a>
            <a class="nav-item-slb" href="attendanceMonitor.php">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 -960 960 960" width="19" height="19" fill="currentColor">
                    <path d="M160-80q-33 0-56.5-23.5T80-160v-440q0-33 23.5-56.5T160-680h200v-120q0-33 23.5-56.5T440-880h80q33 0 56.5 23.5T600-800v120h200q33 0 56.5 23.5T880-600v440q0 33-23.5 56.5T800-80H160Zm0-80h640v-440H600q0 33-23.5 56.5T520-520h-80q-33 0-56.5-23.5T360-600H160v440Zm80-80h240v-18q0-17-9.5-31.5T444-312q-20-9-40.5-13.5T360-330q-23 0-43.5 4.5T276-312q-17 8-26.5 22.5T240-258v18Zm320-60h160v-60H560v60Zm-200-60q25 0 42.5-17.5T420-420q0-25-17.5-42.5T360-480q-25 0-42.5 17.5T300-420q0 25 17.5 42.5T360-360Zm200-60h160v-60H560v60ZM440-600h80v-200h-80v200Zm40 220Z" />
                </svg>
                Monitor Presensi
            </a>
            <a class="nav-item-slb" href="schedule.php">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="19" height="19" fill="currentColor">
                    <path d="M19 4h-1V2h-2v2H8V2H6v2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 16H5V10h14v10zm0-12H5V6h14v2z" />
                </svg>
                Jadwal Shift
            </a>

            <div class="sidebar-section-label">Manajemen</div>
            <a class="nav-item-slb active" href="manageMember.php">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="19" height="19" fill="currentColor">
                    <path d="M16 11c1.66 0 3-1.34 3-3s-1.34-3-3-3-3 1.34-3 3 1.34 3 3 3zm-8 0c1.66 0 3-1.34 3-3S9.66 5 8 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V20h14v-3.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 2.02 1.97 3.45V20h6v-3.5c0-2.33-4.67-3.5-7-3.5z" />
                </svg>
                Manajemen Staff
            </a>
            <a class="nav-item-slb" href="permit.php">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 -960 960 960" width="19" height="19" fill="currentColor">
                    <path d="M160-200v-440 440-15 15Zm0 80q-33 0-56.5-23.5T80-200v-440q0-33 23.5-56.5T160-720h160v-80q0-33 23.5-56.5T400-880h160q33 0 56.5 23.5T640-800v80h160q33 0 56.5 23.5T880-640v171q-18-13-38-22.5T800-508v-132H160v440h283q3 21 9 41t15 39H160Zm240-600h160v-80H400v80ZM720-40q-83 0-141.5-58.5T520-240q0-83 58.5-141.5T720-440q83 0 141.5 58.5T920-240q0 83-58.5 141.5T720-40Zm20-208v-112h-40v128l86 86 28-28-74-74Z" />
                </svg>
                Cuti & Perizinan
            </a>
            <a class="nav-item-slb" href="report.php">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="19" height="19" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M6 2C5.44772 2 5 2.44772 5 3V21C5 21.5523 5.44772 22 6 22H18C18.5523 22 19 21.5523 19 21V7L14 2H6Z" />
                    <path d="M13 2V7H19" />
                </svg>
                Laporan
            </a>

            <div class="sidebar-divider-slb"></div>
            <a class="nav-item-slb logout-link" href="logout.php">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 -960 960 960" width="19" height="19" fill="currentColor">
                    <path d="M200-120q-33 0-56.5-23.5T120-200v-560q0-33 23.5-56.5T200-840h280v80H200v560h280v80H200Zm440-160-55-58 102-102H360v-80h327L585-622l55-58 200 200-200 200Z" />
                </svg>
                Log out
            </a>
        </div>

        <!-- Page content wrapper-->
        <div id="page-content-wrapper">

            <!-- Top navigation-->
            <nav class="topbar-slb">
                <div class="d-flex align-items-center gap-3">
                    <button class="burger-btn" id="sidebarToggle" aria-label="Toggle sidebar">
                        <i class="fa-solid fa-bars"></i>
                    </button>
                    <div>
                        <div class="topbar-title">SIHADIR &mdash; Sing Long Brother Industrial</div>
                        <div class="topbar-date" id="liveClock"></div>
                    </div>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <i class="fa-regular fa-bell text-secondary"></i>
                </div>
            </nav>

            <!-- Page content -->
            <div class="page-body-slb">
                <h1 class="page-heading-slb">Manajemen Staff</h1>
                <p class="page-subheading-slb">Kelola data staff, divisi, dan akses perangkat</p>

                <div class="slb-card">
                    <div class="toolbar-slb">
                        <div class="toolbar-left">
                            <button class="btn-slb-primary" data-bs-toggle="modal" data-bs-target="#addMemberModal">
                                <i class="fa-solid fa-user-plus me-1"></i> Tambah Member
                            </button>
                            <button class="btn-slb-secondary" data-bs-toggle="modal" data-bs-target="#addDivisionModal">
                                <i class="fa-solid fa-sitemap me-1"></i> Atur Divisi
                            </button>
                        </div>
                        <input type="text" id="searchInput" class="filter-input-slb" placeholder="Cari Username"
                            value="<?= isset($_GET['search']) ? htmlspecialchars($_GET['search']) : '' ?>">
                    </div>

                    <?php if (isset($_SESSION['alert'])): ?>
                        <div class="alert alert-<?= $_SESSION['alert']['type'] ?>" id="alert-message" role="alert"
                            style="display: flex; justify-content: space-between; align-items: center;">
                            <span><?= $_SESSION['alert']['message'] ?></span>
                            <button type="button" class="close" onclick="closeAlert(this)" aria-label="Close">&times;</button>
                        </div>
                        <?php unset($_SESSION['alert']); ?>
                    <?php endif; ?>

                    <!-- Table content -->
                    <div class="overflow-x-auto custom-scrollbar">
                        <table class="slb-table min-w-full">
                            <thead>
                                <tr>
                                    <th>Nama Staff</th>
                                    <th>Username</th>
                                    <th>Email</th>
                                    <th>Divisi</th>
                                    <th>No Telepon</th>
                                    <th>Hari Libur</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($users as $user): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($user['nama_lengkap']) ?></td>
                                        <td><?= htmlspecialchars($user['username']) ?></td>
                                        <td><?= htmlspecialchars($user['email']) ?></td>
                                        <td><?= htmlspecialchars($user['nama_divisi'] ?? '-') ?></td>
                                        <td><?= htmlspecialchars($user['no_telp']) ?></td>
                                        <td><?= htmlspecialchars($user['hari_libur'] ?? '-') ?></td>
                                        <td>
                                            <div class="flex space-x-2 justify-center">
                                                <button onclick="editUser(<?= htmlspecialchars(json_encode($user)) ?>)"
                                                    class="action-btn-slb action-btn-edit">
                                                    Edit
                                                </button>
                                                <button
                                                    onclick="showDeleteConfirm(<?= $user['id'] ?>, '<?= htmlspecialchars($user['nama_lengkap']) ?>')"
                                                    class="action-btn-slb action-btn-delete">
                                                    Hapus
                                                </button>
                                                <button
                                                    onclick="showRemoveDeviceConfirm(<?= $user['id'] ?>, '<?= htmlspecialchars($user['nama_lengkap']) ?>')"
                                                    class="action-btn-slb action-btn-device">
                                                    Clear Device
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Add Member Modal -->
            <div class="modal fade" id="addMemberModal" tabindex="-1">
                <div class="modal-dialog modal-md">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title"><i class="fa-solid fa-user-plus me-2"></i>Tambah Member</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <form id="addMemberForm" action="" method="POST">
                                <input type="hidden" name="add_user" value="1">

                                <!-- Nama Lengkap -->
                                <div class="mb-2">
                                    <label class="form-label">Nama Lengkap</label>
                                    <input type="text" class="form-control form-control-sm" name="nama_lengkap"
                                        required>
                                </div>

                                <!-- Username -->
                                <div class="mb-2">
                                    <label class="form-label">Username</label>
                                    <input type="text" class="form-control form-control-sm" name="username" required>
                                </div>

                                <div class="mb-2">
                                    <label class="form-label">Jenis Kelamin</label>
                                    <select name="jenis_kelamin" id="jenis_kelamin" class="form-control form-select"
                                        required>
                                        <option value="">Pilih Jenis Kelamin</option>
                                        <?php foreach ($jenis_kelamin_options as $value => $label): ?>
                                            <option value="<?= $value ?>"><?= htmlspecialchars($label) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <!-- Email -->
                                <div class="mb-2">
                                    <label class="form-label">Email</label>
                                    <input type="email" class="form-control form-control-sm" name="email" required>
                                </div>

                                <!-- Password -->
                                <div class="mb-2">
                                    <label class="form-label">Password</label>
                                    <input type="password" class="form-control form-control-sm" name="password"
                                        required>
                                </div>

                                <!-- No Telepon -->
                                <div class="mb-2">
                                    <label class="form-label">No Telepon</label>
                                    <input type="text" class="form-control form-control-sm" name="no_telp"
                                        id="add_no_telp" required>
                                </div>

                                <!-- Shift -->
                                <div class="form-group mb-2">
                                    <label class="form-label">Shift</label>
                                    <select name="shift_id" id="shift_id" class="form-control form-select" required>
                                        <option value="">Pilih Shift</option>
                                        <?php foreach ($shifts as $id => $nama_shift): ?>
                                            <option value="<?= $id ?>"><?= htmlspecialchars($nama_shift) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <!-- Divisi -->
                                <div class="mb-2">
                                    <label class="form-label">Divisi</label>
                                    <select class="form-control form-select" name="divisi_id" required>
                                        <option value="">Pilih Divisi</option>
                                        <?php foreach ($divisi_names as $id => $nama): ?>
                                            <option value="<?= $id ?>"><?= htmlspecialchars($nama) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="mb-2">
                                    <label class="form-label">Hari Libur</label>
                                    <select class="form-control form-select" name="hari_libur" id="hari_libur" required>
                                        <option value="">Pilih Hari</option>
                                        <option value="senin">Senin</option>
                                        <option value="selasa">Selasa</option>
                                        <option value="rabu">Rabu</option>
                                        <option value="kamis">Kamis</option>
                                        <option value="jumat">Jumat</option>
                                        <option value="sabtu">Sabtu</option>
                                        <option value="minggu">Minggu</option>
                                    </select>
                                </div>

                                <!-- Modal Footer -->
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary btn-sm"
                                        data-bs-dismiss="modal">Tutup</button>
                                    <button type="submit" class="btn btn-primary btn-sm">Tambah</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <!-- Edit Member Modal -->
    <div class="modal fade" id="editMemberModal" tabindex="-1">
        <div class="modal-dialog modal-md">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fa-solid fa-user-pen me-2"></i>Edit Member</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="editMemberForm" action="" method="POST">
                        <input type="hidden" name="update_user" value="1">
                        <input type="hidden" name="user_id" id="edit_user_id">

                        <!-- Nama Lengkap -->
                        <div class="mb-2">
                            <label class="form-label">Nama Lengkap</label>
                            <input type="text" class="form-control form-control-sm" name="nama_lengkap"
                                id="edit_nama_lengkap" required>
                        </div>

                        <!-- Username -->
                        <div class="mb-2">
                            <label class="form-label">Username</label>
                            <input type="text" class="form-control form-control-sm" name="username" id="edit_username"
                                required>
                        </div>

                        <div class="mb-2">
                            <label class="form-label">Jenis Kelamin</label>
                            <select class="form-control form-select" name="jenis_kelamin" id="edit_jenis_kelamin">
                                <option value="">Pilih Jenis Kelamin</option>
                                <?php foreach ($jenis_kelamin_options as $value => $label): ?>
                                    <option value="<?= $value ?>"><?= htmlspecialchars($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Email -->
                        <div class="mb-2">
                            <label class="form-label">Email</label>
                            <input type="email" class="form-control form-control-sm" name="email" id="edit_email"
                                required>
                        </div>

                        <!-- Password Baru -->
                        <div class="mb-2">
                            <label class="form-label">Password Baru</label>
                            <input type="password" class="form-control form-control-sm" name="password">
                        </div>

                        <!-- No Telepon -->
                        <div class="mb-2">
                            <label class="form-label">No Telepon</label>
                            <input type="text" class="form-control form-control-sm" name="no_telp" id="edit_no_telp"
                                required>
                        </div>

                        <!-- Divisi -->
                        <div class="mb-2">
                            <label class="form-label">Divisi</label>
                            <select class="form-control form-select" name="divisi_id" id="edit_divisi_id" required>
                                <option value="">Pilih Divisi</option>
                                <?php foreach ($divisi_names as $id => $nama): ?>
                                    <option value="<?= $id ?>"><?= htmlspecialchars($nama) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Shift -->
                        <div class="form-group mb-2">
                            <label class="form-label">Shift</label>
                            <select name="shift_id" id="edit_shift_id" class="form-control form-select" required>
                                <option value="">Pilih Shift</option>
                                <?php foreach ($shifts as $id => $nama_shift): ?>
                                    <option value="<?= $id ?>"><?= htmlspecialchars($nama_shift) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-2">
                            <label class="form-label">Hari Libur</label>
                            <select class="form-control form-select" name="edit_hari_libur" id="edit_hari_libur"
                                required>
                                <option value="">Pilih Hari</option>
                                <option value="senin">Senin</option>
                                <option value="selasa">Selasa</option>
                                <option value="rabu">Rabu</option>
                                <option value="kamis">Kamis</option>
                                <option value="jumat">Jumat</option>
                                <option value="sabtu">Sabtu</option>
                                <option value="minggu">Minggu</option>
                            </select>
                        </div>

                        <!-- Modal Footer -->
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary btn-sm"
                                data-bs-dismiss="modal">Tutup</button>
                            <button type="submit" class="btn btn-primary btn-sm">Simpan Perubahan</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal fade" id="deleteConfirmModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fa-solid fa-triangle-exclamation me-2"></i>Konfirmasi Hapus</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Apakah Anda yakin ingin menghapus user <span id="delete_user_name" class="font-semibold"></span>?
                    </p>
                </div>
                <div class="modal-footer">
                    <form id="deleteForm" action="" method="POST">
                        <input type="hidden" name="delete_user" value="1">
                        <input type="hidden" name="user_id" id="delete_user_id">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-danger">Hapus</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Modify the Add Division Modal -->
    <div class="modal fade" id="addDivisionModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fa-solid fa-sitemap me-2"></i>Manajemen Divisi</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <!-- Add Division Form -->
                    <form id="addDivisionForm" action="" method="POST" class="mb-4">
                        <input type="hidden" name="add_division" value="1">
                        <div class="mb-3">
                            <label class="form-label">Tambah Divisi Baru</label>
                            <input type="text" class="form-control" name="nama_divisi" required>
                        </div>
                        <button type="submit" class="btn btn-primary">Tambah Divisi</button>
                    </form>

                    <!-- Division List -->
                    <div class="mt-4">
                        <h6 class="mb-3" style="color: var(--slb-navy); font-weight: 700;">Daftar Divisi</h6>
                        <?php foreach ($divisi_names as $id => $nama): ?>
                            <div class="division-row d-flex justify-content-between align-items-center">
                                <span><?= htmlspecialchars($nama) ?></span>
                                <form action="" method="POST" class="d-inline">
                                    <input type="hidden" name="delete_division" value="1">
                                    <input type="hidden" name="division_id" value="<?= $id ?>">
                                    <button type="submit" class="btn btn-danger btn-sm"
                                        onclick="return confirm('Yakin ingin menghapus divisi ini?')">
                                        Hapus
                                    </button>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Remove Device Confirmation Modal -->
    <div class="modal fade" id="removeDeviceConfirmModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fa-solid fa-mobile-screen-button me-2"></i>Konfirmasi Hapus Device</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Apakah Anda yakin ingin menghapus semua device untuk user <span id="remove_device_user_name"
                            class="font-semibold"></span>?</p>
                </div>
                <div class="modal-footer">
                    <form id="removeDeviceForm" action="" method="POST">
                        <input type="hidden" name="remove_device" value="1">
                        <input type="hidden" name="user_id" id="remove_device_user_id">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-danger">Hapus Device</button>
                    </form>
                </div>
            </div>
        </div>
    </div>


    <!-- Scripts -->
    <script>
        // Live clock di topbar
        function updateClock() {
            const el = document.getElementById('liveClock');
            if (!el) return;
            const now = new Date();
            const opts = { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' };
            const dateStr = now.toLocaleDateString('id-ID', opts);
            const timeStr = now.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
            el.textContent = dateStr + ' • ' + timeStr + ' WIB';
        }
        updateClock();
        setInterval(updateClock, 1000);
    </script>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const searchInput = document.getElementById('searchInput');
            const tableRows = document.querySelectorAll('tbody tr');

            function performSearch() {
                const searchTerm = searchInput.value.toLowerCase();

                tableRows.forEach(row => {
                    // Mengambil semua cell dalam row kecuali kolom terakhir (action)
                    const cells = Array.from(row.getElementsByTagName('td')).slice(0, -1);

                    // Mencari di semua kolom
                    const matches = cells.some(cell => {
                        const text = cell.textContent.toLowerCase();
                        return text.includes(searchTerm);
                    });

                    // Tampilkan atau sembunyikan row berdasarkan hasil pencarian
                    row.style.display = matches ? '' : 'none';
                });
            }

            // Menambahkan event listener untuk input
            searchInput.addEventListener('input', performSearch);

            // Menambahkan event listener untuk keyup pada dokumen
            document.addEventListener('keyup', function (event) {
                if (event.target === searchInput) {
                    performSearch();
                }
            });

            // Melakukan pencarian awal (jika ada nilai default di input)
            performSearch();
        });

        // Event listener untuk DOMContentLoaded
        document.addEventListener('DOMContentLoaded', function () {
            // Jalankan autoCloseAlert
            autoCloseAlert();

            // Tambahkan event listener untuk tombol close
            var closeButtons = document.querySelectorAll('.alert .close');
            closeButtons.forEach(function (button) {
                button.addEventListener('click', function () {
                    closeAlert(this);
                });
            });
        });

        // Function to handle edit user
        function editUser(user) {
            document.getElementById('edit_user_id').value = user.id;
            document.getElementById('edit_nama_lengkap').value = user.nama_lengkap;
            document.getElementById('edit_email').value = user.email;
            document.getElementById('edit_username').value = user.username;
            document.getElementById('edit_jenis_kelamin').value = user.jenis_kelamin;
            document.getElementById('edit_no_telp').value = user.no_telp;
            document.getElementById('edit_divisi_id').value = user.divisi_id || '';
            document.getElementById('edit_shift_id').value = user.shift_id || '';
            document.getElementById('edit_hari_libur').value = user.hari_libur || '';
            // Show the modal
            new bootstrap.Modal(document.getElementById('editMemberModal')).show();
        }

        function showDeleteConfirm(userId, userName) {
            document.getElementById('delete_user_id').value = userId;
            document.getElementById('delete_user_name').textContent = userName;
            new bootstrap.Modal(document.getElementById('deleteConfirmModal')).show();
        }

        function showRemoveDeviceConfirm(userId, userName) {
            document.getElementById('remove_device_user_id').value = userId;
            document.getElementById('remove_device_user_name').textContent = userName;
            new bootstrap.Modal(document.getElementById('removeDeviceConfirmModal')).show();
        }

        // Function to handle delete user
        function deleteUser(userId, userName) {
            document.getElementById('delete_user_id').value = userId;
            document.getElementById('delete_user_name').textContent = userName;

            // Show the modal
            new bootstrap.Modal(document.getElementById('deleteConfirmModal')).show();
        }

        function removeDevice(userId, userName) {
            if (confirm(`Apakah Anda yakin ingin menghapus device untuk user ${userName}?`)) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.style.display = 'none';

                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'remove_device';
                input.value = '1';

                const userIdInput = document.createElement('input');
                userIdInput.type = 'hidden';
                userIdInput.name = 'user_id';
                userIdInput.value = userId;

                form.appendChild(input);
                form.appendChild(userIdInput);
                document.body.appendChild(form);
                form.submit();
            }
        }

        // Fungsi untuk menutup alert
        function closeAlert(element) {
            var alert = element.closest('.alert');
            alert.style.opacity = '0';
            setTimeout(function () {
                alert.style.display = 'none';
            }, 300);
        }

        // Fungsi untuk otomatis menghilangkan alert setelah beberapa detik
        function autoCloseAlert() {
            var alerts = document.querySelectorAll('.alert'); // Mengambil semua alert
            alerts.forEach(function (alert) {
                setTimeout(function () {
                    alert.style.transition = 'opacity 0.3s ease-in-out';
                    alert.style.opacity = '0';
                    setTimeout(function () {
                        alert.style.display = 'none';
                    }, 300);
                }, 2000); // Alert akan hilang setelah 3 detik
            });
        }

        // Prevent form resubmission on page refresh
        if (window.history.replaceState) {
            window.history.replaceState(null, null, window.location.href);
        }
    </script>

    <!-- ALERT INPUT ANGKA DI KOLOM NO TELEPON -->
    <script>
        // Fungsi untuk menangani validasi input hanya angka
        function validatePhoneInput(inputId) {
            const inputElement = document.getElementById(inputId);

            inputElement.addEventListener('input', function () {
                if (/\D/.test(this.value)) { // Jika ada karakter non-angka
                    alert("Nomor telepon hanya boleh mengandung angka.");
                    this.value = this.value.replace(/\D/g, ''); // Hapus karakter non-angka
                }
            });
        }

        // Terapkan validasi untuk kedua input
        validatePhoneInput('edit_no_telp');
        validatePhoneInput('add_no_telp');
    </script>

    <!-- Bootstrap and other scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../../../assets/js/scripts.js"></script>

    <script>
        // Sidebar toggle (responsive: desktop collapse, mobile off-canvas)
        const sidebarToggle = document.getElementById('sidebarToggle');
        const sidebarWrapper = document.getElementById('sidebar-wrapper');
        const sidebarOverlay = document.getElementById('sidebarOverlay');

        function isMobile() {
            return window.innerWidth < 992;
        }

        sidebarToggle.addEventListener('click', function () {
            if (isMobile()) {
                sidebarWrapper.classList.toggle('mobile-open');
                sidebarOverlay.classList.toggle('show');
            } else {
                sidebarWrapper.classList.toggle('collapsed');
            }
        });

        sidebarOverlay.addEventListener('click', function () {
            sidebarWrapper.classList.remove('mobile-open');
            sidebarOverlay.classList.remove('show');
        });

        window.addEventListener('resize', function () {
            if (!isMobile()) {
                sidebarWrapper.classList.remove('mobile-open');
                sidebarOverlay.classList.remove('show');
            }
        });
    </script>
</body>

</html>