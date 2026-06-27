<<<<<<< HEAD
<?php
session_start();
require_once '../../../app/auth/auth.php';

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header('Location: ../../../login.php');
    exit;
}

// Check if the user role is employee
if (isset($_SESSION['role']) && $_SESSION['role'] !== 'owner') {
    session_unset();
    session_destroy();

    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Cache-Control: post-check=0, pre-check=0', false);
    header('Pragma: no-cache');

    header('Location: ../../../login.php');
    exit;
}

function shiftNameExists($pdo, $nama_shift, $excludeId = null)
{
    $sql = "SELECT COUNT(*) FROM shift WHERE LOWER(nama_shift) = LOWER(?)";
    $params = [$nama_shift];

    if ($excludeId !== null) {
        $sql .= " AND id != ?";
        $params[] = $excludeId;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn() > 0;
}

function shiftInUse($pdo, $shiftId)
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM jadwal_shift WHERE shift_id = ?");
    $stmt->execute([$shiftId]);
    return $stmt->fetchColumn() > 0;
}

function validateShiftTimes($jam_masuk, $jam_keluar)
{
    $masuk = strtotime($jam_masuk);
    $keluar = strtotime($jam_keluar);

    if ($keluar <= $masuk) {
        $keluar_next_day = $keluar + (24 * 60 * 60);
        if ($keluar_next_day <= $masuk) {
            return ['valid' => false, 'message' => 'Periksa kembali jam masuk dan jam pulang anda, jam pulang tidak boleh kurang dari atau sama dengan jam masuk.'];
        }
    }

    return ['valid' => true];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add':
                try {
                    if (shiftNameExists($pdo, $_POST['nama_shift'])) {
                        $_SESSION['error'] = "Nama jadwal sudah ada (tidak memperhatikan huruf besar/kecil). Gunakan nama yang berbeda.";
                        break;
                    }

                    $timeValidation = validateShiftTimes($_POST['jam_masuk'], $_POST['jam_keluar']);
                    if (!$timeValidation['valid']) {
                        $_SESSION['error'] = $timeValidation['message'];
                        break;
                    }

                    $stmt = $pdo->prepare("INSERT INTO shift (nama_shift, jam_masuk, jam_keluar) VALUES (?, ?, ?)");
                    $stmt->execute([$_POST['nama_shift'], $_POST['jam_masuk'], $_POST['jam_keluar']]);
                    $_SESSION['success'] = "Jadwal berhasil ditambahkan.";
                } catch (PDOException $e) {
                    $_SESSION['error'] = "Error menambahkan jadwal: " . $e->getMessage();
                }
                break;

            case 'edit':
                try {
                    if (shiftNameExists($pdo, $_POST['nama_shift'], $_POST['id'])) {
                        $_SESSION['error'] = "Nama jadwal sudah ada (tidak memperhatikan huruf besar/kecil). Gunakan nama yang berbeda.";
                        break;
                    }

                    $timeValidation = validateShiftTimes($_POST['jam_masuk'], $_POST['jam_keluar']);
                    if (!$timeValidation['valid']) {
                        $_SESSION['error'] = $timeValidation['message'];
                        break;
                    }

                    $stmt = $pdo->prepare("UPDATE shift SET nama_shift = ?, jam_masuk = ?, jam_keluar = ? WHERE id = ?");
                    $stmt->execute([$_POST['nama_shift'], $_POST['jam_masuk'], $_POST['jam_keluar'], $_POST['id']]);
                    $_SESSION['success'] = "Jadwal berhasil diperbarui.";
                } catch (PDOException $e) {
                    $_SESSION['error'] = "Error memperbarui jadwal: " . $e->getMessage();
                }
                break;

            case 'delete':
                try {
                    if (shiftInUse($pdo, $_POST['id'])) {
                        $_SESSION['error'] = "Jadwal tidak dapat dihapus karena sedang digunakan dalam penjadwalan staff.";
                        break;
                    }

                    $stmt = $pdo->prepare("DELETE FROM shift WHERE id = ?");
                    $stmt->execute([$_POST['id']]);
                    $_SESSION['success'] = "Jadwal berhasil dihapus.";
                } catch (PDOException $e) {
                    $_SESSION['error'] = "Error menghapus jadwal: " . $e->getMessage();
                }
                break;
        }
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
}

try {
    $stmt = $pdo->query("SELECT * FROM shift ORDER BY jam_masuk");
    $shifts = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $_SESSION['error'] = "Error mengambil data jadwal: " . $e->getMessage();
    $shifts = [];
}

$error = isset($_SESSION['error']) ? $_SESSION['error'] : null;
$success = isset($_SESSION['success']) ? $_SESSION['success'] : null;
unset($_SESSION['error'], $_SESSION['success']);
?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no" />
    <meta name="description" content="SIHADIR - Sistem Kehadiran Sing Long Brother Industrial" />
    <meta name="author" content="Sing Long Brother Industrial" />
    <title>SIHADIR | Jadwal Shift</title>
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
        h1, h2, h3, .brand-font { font-family: 'Sora', sans-serif; }

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
        .fade-out {
            opacity: 0;
            transition: opacity 2s;
        }

        /* ===== TABLE ===== */
        .table-wrap-slb {
            overflow-x: auto;
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
        .action-btn-edit {
            background: #e9f9f0;
            color: #0d9d58;
        }
        .action-btn-edit:hover {
            background: #d3f1e2;
        }
        .action-btn-delete {
            background: #fdebec;
            color: #e0334d;
        }
        .action-btn-delete:hover {
            background: #fbd6d9;
        }

        /* ===== FORM INVALID ===== */
        .form-control.is-invalid {
            border-color: #dc3545;
            padding-right: calc(1.5em + 0.75rem);
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 12 12' width='12' height='12' fill='none' stroke='%23dc3545'%3e%3ccircle cx='6' cy='6' r='4.5'/%3e%3cpath stroke-linejoin='round' d='M5.8 3.6h.4L6 6.5z'/%3e%3ccircle cx='6' cy='8.2' r='.6' fill='%23dc3545' stroke='none'/%3e%3c/svg%3e");
            background-repeat: no-repeat;
            background-position: right calc(0.375em + 0.1875rem) center;
            background-size: calc(0.75em + 0.375rem) calc(0.75em + 0.375rem);
        }
        .invalid-feedback {
            display: none;
            color: #dc3545;
            font-size: 0.875em;
            margin-top: 0.25rem;
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
            <a class="nav-item-slb active" href="schedule.php">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="19" height="19" fill="currentColor">
                    <path d="M19 4h-1V2h-2v2H8V2H6v2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 16H5V10h14v10zm0-12H5V6h14v2z" />
                </svg>
                Jadwal Shift
            </a>

            <div class="sidebar-section-label">Manajemen</div>
            <a class="nav-item-slb" href="manageMember.php">
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

            <!-- Page content-->
            <div class="page-body-slb">
                <h1 class="page-heading-slb">Jadwal Shift</h1>
                <p class="page-subheading-slb">Kelola jadwal shift kerja karyawan</p>

                <!-- Add Shift Modal -->
                <div id="addShiftModal" class="modal fade" tabindex="-1">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title">Tambah Jadwal Baru</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <form method="POST" id="addShiftForm" onsubmit="return validateShiftForm(this);">
                                <div class="modal-body">
                                    <input type="hidden" name="action" value="add">
                                    <div class="mb-3">
                                        <label class="form-label">Nama Jadwal</label>
                                        <input type="text" class="form-control" name="nama_shift" required>
                                        <div class="invalid-feedback">
                                            Nama jadwal harus diisi dan unik
                                        </div>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Jam Masuk</label>
                                        <input type="time" class="form-control" name="jam_masuk" required>
                                        <div class="invalid-feedback">

                                        </div>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Jam Pulang</label>
                                        <input type="time" class="form-control" name="jam_keluar" required>
                                        <div class="invalid-feedback">
                                            Periksa kembali jam masuk dan jam pulang anda, jam pulang tidak boleh kurang
                                            dari atau sama dengan jam masuk.
                                        </div>
                                    </div>
                                </div>
                                <div class="modal-footer">

                                    <button type="submit" class="btn btn-primary">Simpan</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Edit Shift Modal -->
                <div id="editShiftModal" class="modal fade" tabindex="-1">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title">Edit Jadwal</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <form method="POST" id="editShiftForm" onsubmit="return validateShiftForm(this);">
                                <div class="modal-body">
                                    <input type="hidden" name="action" value="edit">
                                    <input type="hidden" name="id" id="edit_id">
                                    <div class="mb-3">
                                        <label class="form-label">Nama Jadwal</label>
                                        <input type="text" class="form-control" name="nama_shift" id="edit_nama_shift"
                                            required>
                                        <div class="invalid-feedback">
                                            Nama jadwal harus diisi dan unik
                                        </div>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Jam Masuk</label>
                                        <input type="time" class="form-control" name="jam_masuk" id="edit_jam_masuk"
                                            required>
                                        <div class="invalid-feedback">

                                        </div>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Jam Pulang</label>
                                        <input type="time" class="form-control" name="jam_keluar" id="edit_jam_keluar"
                                            required>
                                        <div class="invalid-feedback">
                                            Periksa kembali jam masuk dan jam pulang anda, jam pulang tidak boleh kurang
                                            dari atau sama dengan jam masuk.
                                        </div>
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="submit" class="btn btn-primary">Simpan</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="slb-card">
                    <div class="toolbar-slb">
                        <button class="btn-slb-primary" data-bs-toggle="modal" data-bs-target="#addShiftModal">
                            <i class="fa-solid fa-plus me-1"></i> Tambah Jadwal
                        </button>
                        <input type="text" id="searchInput" class="filter-input-slb" placeholder="Cari Jadwal">
                    </div>

                    <!-- Alert Messages -->
                    <?php if ($success): ?>
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            <?php echo htmlspecialchars($success); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    <?php endif; ?>

                    <?php if ($error): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <?php echo htmlspecialchars($error); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    <?php endif; ?>

                    <div class="table-wrap-slb">
                        <table class="slb-table">
                            <thead>
                                <tr>
                                    <th>Nama Jadwal</th>
                                    <th>Jam Masuk</th>
                                    <th>Jam Pulang</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($shifts as $shift): ?>
                                    <tr>
                                        <td>
                                            <?php echo htmlspecialchars($shift['nama_shift']); ?>
                                        </td>
                                        <td>
                                            <?php echo date('H:i', strtotime($shift['jam_masuk'])); ?>
                                        </td>
                                        <td>
                                            <?php echo date('H:i', strtotime($shift['jam_keluar'])); ?>
                                        </td>
                                        <td>
                                            <button class="action-btn-slb action-btn-edit edit-btn"
                                                data-bs-toggle="modal" data-bs-target="#editShiftModal"
                                                data-id="<?php echo $shift['id']; ?>"
                                                data-nama="<?php echo htmlspecialchars($shift['nama_shift']); ?>"
                                                data-masuk="<?php echo $shift['jam_masuk']; ?>"
                                                data-keluar="<?php echo $shift['jam_keluar']; ?>">
                                                Edit
                                            </button>
                                            <form method="POST" class="inline-block">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?php echo $shift['id']; ?>">
                                                <button type="submit"
                                                    class="action-btn-slb action-btn-delete"
                                                    onclick="return confirm('Apakah Anda yakin ingin menghapus jadwal ini?')">
                                                    Delete
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap core JS-->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js"></script>
    <!-- Core theme JS-->
    <script src="../../../assets/js/scripts.js "></script>

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
        function validateShiftForm(form) {
            const jamMasuk = form.querySelector('[name="jam_masuk"]');
            const jamKeluar = form.querySelector('[name="jam_keluar"]');

            form.querySelectorAll('.is-invalid').forEach(el => {
                el.classList.remove('is-invalid');
            });

            let isValid = true;

            form.querySelectorAll('[required]').forEach(input => {
                if (!input.value) {
                    input.classList.add('is-invalid');
                    isValid = false;
                }
            });

            if (jamMasuk.value && jamKeluar.value) {
                const masuk = new Date(`2000-01-01T${jamMasuk.value}`);
                const keluar = new Date(`2000-01-01T${jamKeluar.value}`);

                if (masuk >= keluar) {
                    jamMasuk.classList.add('is-invalid');
                    isValid = false;
                }

                if (keluar <= masuk) {
                    jamKeluar.classList.add('is-invalid');
                    jamKeluar.nextElementSibling.textContent = 'Periksa kembali jam masuk dan jam pulang anda, jam pulang tidak boleh kurang dari atau sama dengan jam masuk.';
                    isValid = false;
                }
            }

            return isValid;
        }

        document.addEventListener('DOMContentLoaded', function () {
            const addModal = document.getElementById('addShiftModal');
            addModal.addEventListener('hidden.bs.modal', function () {
                const form = this.querySelector('form');
                form.reset();
                form.querySelectorAll('.is-invalid').forEach(el => {
                    el.classList.remove('is-invalid');
                });
            });

            const editModal = document.getElementById('editShiftModal');
            editModal.addEventListener('hidden.bs.modal', function () {
                const form = this.querySelector('form');
                form.querySelectorAll('.is-invalid').forEach(el => {
                    el.classList.remove('is-invalid');
                });
            });

            setTimeout(function () {
                document.querySelectorAll('.alert').forEach(function (alert) {
                    alert.classList.add('fade-out');
                    setTimeout(() => alert.remove(), 3000);
                });
            }, 5000);
        });

        document.querySelectorAll('.edit-btn').forEach(button => {
            button.addEventListener('click', function () {
                document.getElementById('edit_id').value = this.dataset.id;
                document.getElementById('edit_nama_shift').value = this.dataset.nama;
                document.getElementById('edit_jam_masuk').value = this.dataset.masuk;
                document.getElementById('edit_jam_keluar').value = this.dataset.keluar;
            });
        });

        document.querySelectorAll('form').forEach(form => {
            if (form.querySelector('[name="action"][value="delete"]')) {
                form.onsubmit = function (e) {
                    e.preventDefault();
                    if (confirm('Apakah Anda yakin ingin menghapus jadwal ini? Pastikan tidak ada karyawan yang menggunakan jadwal ini.')) {
                        form.submit();
                    }
                };
            }
        });

        document.getElementById('searchInput').addEventListener('keyup', function () {
            const searchValue = this.value.toLowerCase();
            const rows = document.querySelectorAll('tbody tr');

            rows.forEach(row => {
                const nama = row.cells[0].textContent.toLowerCase();
                row.style.display = nama.includes(searchValue) ? '' : 'none';
            });
        });

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

=======
<?php
session_start();
require_once '../../../app/auth/auth.php';

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header('Location: ../../../login.php');
    exit;
}

// Check if the user role is employee
if (isset($_SESSION['role']) && $_SESSION['role'] !== 'owner') {
    session_unset();
    session_destroy();

    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Cache-Control: post-check=0, pre-check=0', false);
    header('Pragma: no-cache');

    header('Location: ../../../login.php');
    exit;
}

function shiftNameExists($pdo, $nama_shift, $excludeId = null)
{
    $sql = "SELECT COUNT(*) FROM shift WHERE LOWER(nama_shift) = LOWER(?)";
    $params = [$nama_shift];

    if ($excludeId !== null) {
        $sql .= " AND id != ?";
        $params[] = $excludeId;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn() > 0;
}

function shiftInUse($pdo, $shiftId)
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM jadwal_shift WHERE shift_id = ?");
    $stmt->execute([$shiftId]);
    return $stmt->fetchColumn() > 0;
}

function validateShiftTimes($jam_masuk, $jam_keluar)
{
    $masuk = strtotime($jam_masuk);
    $keluar = strtotime($jam_keluar);

    if ($keluar <= $masuk) {
        $keluar_next_day = $keluar + (24 * 60 * 60);
        if ($keluar_next_day <= $masuk) {
            return ['valid' => false, 'message' => 'Periksa kembali jam masuk dan jam pulang anda, jam pulang tidak boleh kurang dari atau sama dengan jam masuk.'];
        }
    }

    return ['valid' => true];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add':
                try {
                    if (shiftNameExists($pdo, $_POST['nama_shift'])) {
                        $_SESSION['error'] = "Nama jadwal sudah ada (tidak memperhatikan huruf besar/kecil). Gunakan nama yang berbeda.";
                        break;
                    }

                    $timeValidation = validateShiftTimes($_POST['jam_masuk'], $_POST['jam_keluar']);
                    if (!$timeValidation['valid']) {
                        $_SESSION['error'] = $timeValidation['message'];
                        break;
                    }

                    $stmt = $pdo->prepare("INSERT INTO shift (nama_shift, jam_masuk, jam_keluar) VALUES (?, ?, ?)");
                    $stmt->execute([$_POST['nama_shift'], $_POST['jam_masuk'], $_POST['jam_keluar']]);
                    $_SESSION['success'] = "Jadwal berhasil ditambahkan.";
                } catch (PDOException $e) {
                    $_SESSION['error'] = "Error menambahkan jadwal: " . $e->getMessage();
                }
                break;

            case 'edit':
                try {
                    if (shiftNameExists($pdo, $_POST['nama_shift'], $_POST['id'])) {
                        $_SESSION['error'] = "Nama jadwal sudah ada (tidak memperhatikan huruf besar/kecil). Gunakan nama yang berbeda.";
                        break;
                    }

                    $timeValidation = validateShiftTimes($_POST['jam_masuk'], $_POST['jam_keluar']);
                    if (!$timeValidation['valid']) {
                        $_SESSION['error'] = $timeValidation['message'];
                        break;
                    }

                    $stmt = $pdo->prepare("UPDATE shift SET nama_shift = ?, jam_masuk = ?, jam_keluar = ? WHERE id = ?");
                    $stmt->execute([$_POST['nama_shift'], $_POST['jam_masuk'], $_POST['jam_keluar'], $_POST['id']]);
                    $_SESSION['success'] = "Jadwal berhasil diperbarui.";
                } catch (PDOException $e) {
                    $_SESSION['error'] = "Error memperbarui jadwal: " . $e->getMessage();
                }
                break;

            case 'delete':
                try {
                    if (shiftInUse($pdo, $_POST['id'])) {
                        $_SESSION['error'] = "Jadwal tidak dapat dihapus karena sedang digunakan dalam penjadwalan staff.";
                        break;
                    }

                    $stmt = $pdo->prepare("DELETE FROM shift WHERE id = ?");
                    $stmt->execute([$_POST['id']]);
                    $_SESSION['success'] = "Jadwal berhasil dihapus.";
                } catch (PDOException $e) {
                    $_SESSION['error'] = "Error menghapus jadwal: " . $e->getMessage();
                }
                break;
        }
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
}

try {
    $stmt = $pdo->query("SELECT * FROM shift ORDER BY jam_masuk");
    $shifts = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $_SESSION['error'] = "Error mengambil data jadwal: " . $e->getMessage();
    $shifts = [];
}

$error = isset($_SESSION['error']) ? $_SESSION['error'] : null;
$success = isset($_SESSION['success']) ? $_SESSION['success'] : null;
unset($_SESSION['error'], $_SESSION['success']);
?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no" />
    <meta name="description" content="SIHADIR - Sistem Kehadiran Sing Long Brother Industrial" />
    <meta name="author" content="Sing Long Brother Industrial" />
    <title>SIHADIR | Jadwal Shift</title>
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
        h1, h2, h3, .brand-font { font-family: 'Sora', sans-serif; }

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
        .fade-out {
            opacity: 0;
            transition: opacity 2s;
        }

        /* ===== TABLE ===== */
        .table-wrap-slb {
            overflow-x: auto;
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
        .action-btn-edit {
            background: #e9f9f0;
            color: #0d9d58;
        }
        .action-btn-edit:hover {
            background: #d3f1e2;
        }
        .action-btn-delete {
            background: #fdebec;
            color: #e0334d;
        }
        .action-btn-delete:hover {
            background: #fbd6d9;
        }

        /* ===== FORM INVALID ===== */
        .form-control.is-invalid {
            border-color: #dc3545;
            padding-right: calc(1.5em + 0.75rem);
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 12 12' width='12' height='12' fill='none' stroke='%23dc3545'%3e%3ccircle cx='6' cy='6' r='4.5'/%3e%3cpath stroke-linejoin='round' d='M5.8 3.6h.4L6 6.5z'/%3e%3ccircle cx='6' cy='8.2' r='.6' fill='%23dc3545' stroke='none'/%3e%3c/svg%3e");
            background-repeat: no-repeat;
            background-position: right calc(0.375em + 0.1875rem) center;
            background-size: calc(0.75em + 0.375rem) calc(0.75em + 0.375rem);
        }
        .invalid-feedback {
            display: none;
            color: #dc3545;
            font-size: 0.875em;
            margin-top: 0.25rem;
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
            <a class="nav-item-slb active" href="schedule.php">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="19" height="19" fill="currentColor">
                    <path d="M19 4h-1V2h-2v2H8V2H6v2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 16H5V10h14v10zm0-12H5V6h14v2z" />
                </svg>
                Jadwal Shift
            </a>

            <div class="sidebar-section-label">Manajemen</div>
            <a class="nav-item-slb" href="manageMember.php">
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

            <!-- Page content-->
            <div class="page-body-slb">
                <h1 class="page-heading-slb">Jadwal Shift</h1>
                <p class="page-subheading-slb">Kelola jadwal shift kerja karyawan</p>

                <!-- Add Shift Modal -->
                <div id="addShiftModal" class="modal fade" tabindex="-1">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title">Tambah Jadwal Baru</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <form method="POST" id="addShiftForm" onsubmit="return validateShiftForm(this);">
                                <div class="modal-body">
                                    <input type="hidden" name="action" value="add">
                                    <div class="mb-3">
                                        <label class="form-label">Nama Jadwal</label>
                                        <input type="text" class="form-control" name="nama_shift" required>
                                        <div class="invalid-feedback">
                                            Nama jadwal harus diisi dan unik
                                        </div>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Jam Masuk</label>
                                        <input type="time" class="form-control" name="jam_masuk" required>
                                        <div class="invalid-feedback">

                                        </div>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Jam Pulang</label>
                                        <input type="time" class="form-control" name="jam_keluar" required>
                                        <div class="invalid-feedback">
                                            Periksa kembali jam masuk dan jam pulang anda, jam pulang tidak boleh kurang
                                            dari atau sama dengan jam masuk.
                                        </div>
                                    </div>
                                </div>
                                <div class="modal-footer">

                                    <button type="submit" class="btn btn-primary">Simpan</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Edit Shift Modal -->
                <div id="editShiftModal" class="modal fade" tabindex="-1">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title">Edit Jadwal</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <form method="POST" id="editShiftForm" onsubmit="return validateShiftForm(this);">
                                <div class="modal-body">
                                    <input type="hidden" name="action" value="edit">
                                    <input type="hidden" name="id" id="edit_id">
                                    <div class="mb-3">
                                        <label class="form-label">Nama Jadwal</label>
                                        <input type="text" class="form-control" name="nama_shift" id="edit_nama_shift"
                                            required>
                                        <div class="invalid-feedback">
                                            Nama jadwal harus diisi dan unik
                                        </div>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Jam Masuk</label>
                                        <input type="time" class="form-control" name="jam_masuk" id="edit_jam_masuk"
                                            required>
                                        <div class="invalid-feedback">

                                        </div>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Jam Pulang</label>
                                        <input type="time" class="form-control" name="jam_keluar" id="edit_jam_keluar"
                                            required>
                                        <div class="invalid-feedback">
                                            Periksa kembali jam masuk dan jam pulang anda, jam pulang tidak boleh kurang
                                            dari atau sama dengan jam masuk.
                                        </div>
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="submit" class="btn btn-primary">Simpan</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="slb-card">
                    <div class="toolbar-slb">
                        <button class="btn-slb-primary" data-bs-toggle="modal" data-bs-target="#addShiftModal">
                            <i class="fa-solid fa-plus me-1"></i> Tambah Jadwal
                        </button>
                        <input type="text" id="searchInput" class="filter-input-slb" placeholder="Cari Jadwal">
                    </div>

                    <!-- Alert Messages -->
                    <?php if ($success): ?>
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            <?php echo htmlspecialchars($success); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    <?php endif; ?>

                    <?php if ($error): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <?php echo htmlspecialchars($error); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    <?php endif; ?>

                    <div class="table-wrap-slb">
                        <table class="slb-table">
                            <thead>
                                <tr>
                                    <th>Nama Jadwal</th>
                                    <th>Jam Masuk</th>
                                    <th>Jam Pulang</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($shifts as $shift): ?>
                                    <tr>
                                        <td>
                                            <?php echo htmlspecialchars($shift['nama_shift']); ?>
                                        </td>
                                        <td>
                                            <?php echo date('H:i', strtotime($shift['jam_masuk'])); ?>
                                        </td>
                                        <td>
                                            <?php echo date('H:i', strtotime($shift['jam_keluar'])); ?>
                                        </td>
                                        <td>
                                            <button class="action-btn-slb action-btn-edit edit-btn"
                                                data-bs-toggle="modal" data-bs-target="#editShiftModal"
                                                data-id="<?php echo $shift['id']; ?>"
                                                data-nama="<?php echo htmlspecialchars($shift['nama_shift']); ?>"
                                                data-masuk="<?php echo $shift['jam_masuk']; ?>"
                                                data-keluar="<?php echo $shift['jam_keluar']; ?>">
                                                Edit
                                            </button>
                                            <form method="POST" class="inline-block">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?php echo $shift['id']; ?>">
                                                <button type="submit"
                                                    class="action-btn-slb action-btn-delete"
                                                    onclick="return confirm('Apakah Anda yakin ingin menghapus jadwal ini?')">
                                                    Delete
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap core JS-->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js"></script>
    <!-- Core theme JS-->
    <script src="../../../assets/js/scripts.js "></script>

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
        function validateShiftForm(form) {
            const jamMasuk = form.querySelector('[name="jam_masuk"]');
            const jamKeluar = form.querySelector('[name="jam_keluar"]');

            form.querySelectorAll('.is-invalid').forEach(el => {
                el.classList.remove('is-invalid');
            });

            let isValid = true;

            form.querySelectorAll('[required]').forEach(input => {
                if (!input.value) {
                    input.classList.add('is-invalid');
                    isValid = false;
                }
            });

            if (jamMasuk.value && jamKeluar.value) {
                const masuk = new Date(`2000-01-01T${jamMasuk.value}`);
                const keluar = new Date(`2000-01-01T${jamKeluar.value}`);

                if (masuk >= keluar) {
                    jamMasuk.classList.add('is-invalid');
                    isValid = false;
                }

                if (keluar <= masuk) {
                    jamKeluar.classList.add('is-invalid');
                    jamKeluar.nextElementSibling.textContent = 'Periksa kembali jam masuk dan jam pulang anda, jam pulang tidak boleh kurang dari atau sama dengan jam masuk.';
                    isValid = false;
                }
            }

            return isValid;
        }

        document.addEventListener('DOMContentLoaded', function () {
            const addModal = document.getElementById('addShiftModal');
            addModal.addEventListener('hidden.bs.modal', function () {
                const form = this.querySelector('form');
                form.reset();
                form.querySelectorAll('.is-invalid').forEach(el => {
                    el.classList.remove('is-invalid');
                });
            });

            const editModal = document.getElementById('editShiftModal');
            editModal.addEventListener('hidden.bs.modal', function () {
                const form = this.querySelector('form');
                form.querySelectorAll('.is-invalid').forEach(el => {
                    el.classList.remove('is-invalid');
                });
            });

            setTimeout(function () {
                document.querySelectorAll('.alert').forEach(function (alert) {
                    alert.classList.add('fade-out');
                    setTimeout(() => alert.remove(), 3000);
                });
            }, 5000);
        });

        document.querySelectorAll('.edit-btn').forEach(button => {
            button.addEventListener('click', function () {
                document.getElementById('edit_id').value = this.dataset.id;
                document.getElementById('edit_nama_shift').value = this.dataset.nama;
                document.getElementById('edit_jam_masuk').value = this.dataset.masuk;
                document.getElementById('edit_jam_keluar').value = this.dataset.keluar;
            });
        });

        document.querySelectorAll('form').forEach(form => {
            if (form.querySelector('[name="action"][value="delete"]')) {
                form.onsubmit = function (e) {
                    e.preventDefault();
                    if (confirm('Apakah Anda yakin ingin menghapus jadwal ini? Pastikan tidak ada karyawan yang menggunakan jadwal ini.')) {
                        form.submit();
                    }
                };
            }
        });

        document.getElementById('searchInput').addEventListener('keyup', function () {
            const searchValue = this.value.toLowerCase();
            const rows = document.querySelectorAll('tbody tr');

            rows.forEach(row => {
                const nama = row.cells[0].textContent.toLowerCase();
                row.style.display = nama.includes(searchValue) ? '' : 'none';
            });
        });

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

>>>>>>> 85f0a544401770b8d40292bda6237083bebe2c83
</html>