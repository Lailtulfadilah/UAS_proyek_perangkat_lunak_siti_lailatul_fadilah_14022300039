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
    header('Cache-Control: post-check=0, pre-check=0', false);
    header('Pragma: no-cache');

    header('Location: ../../../login.php');
    exit;
}

function getEarliestAttendanceDate($pdo)
{
    try {
        $query = "SELECT MIN(DATE(tanggal)) as earliest_date FROM absensi";
        $stmt = $pdo->query($query);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result['earliest_date'] ?? date('Y-m-d');
    } catch (PDOException $e) {
        error_log("Database error in getEarliestAttendanceDate: " . $e->getMessage());
        return date('Y-m-d');
    }
}


// Update the getAttendanceStats function to combine cuti and izin
function getAttendanceStats($pdo, $date, $shift = 'all')
{
    $params = ['date' => $date];
    $query = "SELECT status_kehadiran, COUNT(*) as count 
              FROM absensi a
              LEFT JOIN jadwal_shift js ON (a.pegawai_id = js.pegawai_id AND DATE(a.tanggal) = js.tanggal)
              WHERE DATE(a.tanggal) = :date";

    if ($shift !== 'all' && is_numeric($shift)) {
        $query .= " AND js.shift_id = :shift_id";
        $params['shift_id'] = $shift;
    }

    $query .= " GROUP BY status_kehadiran";

    $stats = array(
        'hadir' => 0,
        'terlambat' => 0,
        'alpha' => 0,
        'cuti' => 0,
        'izin' => 0,
        'pulang_dahulu' => 0,
        'tidak_absen_pulang' => 0
    );

    try {
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (isset($stats[$row['status_kehadiran']])) {
                $stats[$row['status_kehadiran']] = $row['count'];
            }
        }
    } catch (PDOException $e) {
        error_log("Database error in getAttendanceStats: " . $e->getMessage());
    }

    return $stats;
}

// Function to get detailed attendance records
function getAttendanceRecords($pdo, $date, $search = '', $shift = 'all')
{
    $params = ['date' => $date];
    $query = "SELECT 
                a.*, 
                u.nama_lengkap as nama_pegawai,
                s.nama_shift,
                s.jam_masuk,
                s.jam_keluar
              FROM absensi a 
              LEFT JOIN pegawai p ON a.pegawai_id = p.id
              LEFT JOIN users u ON p.user_id = u.id
              LEFT JOIN jadwal_shift js ON (a.pegawai_id = js.pegawai_id AND DATE(a.tanggal) = js.tanggal)
              LEFT JOIN shift s ON js.shift_id = s.id
              WHERE DATE(a.tanggal) = :date";

    if (!empty($search)) {
        $query .= " AND (u.nama_lengkap LIKE :search OR u.email LIKE :search)";
        $params['search'] = "%$search%";
    }

    if ($shift !== 'all' && is_numeric($shift)) {
        $query .= " AND js.shift_id = :shift_id";
        $params['shift_id'] = $shift;
    }

    $query .= " ORDER BY a.waktu_masuk ASC";

    try {
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        return $stmt;
    } catch (PDOException $e) {
        error_log("Database error in getAttendanceRecords: " . $e->getMessage());
        return $pdo->prepare("SELECT 1 WHERE 1=0");
    }
}

// Function to get shifts for the dropdown
function getShifts($pdo)
{
    try {
        $query = "SELECT id, nama_shift, TIME_FORMAT(jam_masuk, '%H:%i') as jam_masuk, 
                         TIME_FORMAT(jam_keluar, '%H:%i') as jam_keluar 
                  FROM shift 
                  ORDER BY jam_masuk";
        $stmt = $pdo->query($query);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Database error in getShifts: " . $e->getMessage());
        return array();
    }
}

$earliestDate = getEarliestAttendanceDate($pdo);

// Input validation with earliest date check
$selectedDate = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDate) || $selectedDate < $earliestDate) {
    $selectedDate = date('Y-m-d');
}

$searchQuery = isset($_GET['search']) ? trim($_GET['search']) : '';
$selectedShift = isset($_GET['shift']) ? $_GET['shift'] : 'all';


// Get the statistics and records with error handling
try {
    $stats = getAttendanceStats($pdo, $selectedDate, $selectedShift);
    $records = getAttendanceRecords($pdo, $selectedDate, $searchQuery, $selectedShift);
    $shifts = getShifts($pdo);

    // Format date for display
    $displayDate = date('l, d F Y', strtotime($selectedDate));
} catch (Exception $e) {
    error_log("General error in attendance monitor: " . $e->getMessage());
    $stats = array('hadir' => 0, 'alpha' => 0, 'izin' => 0, 'terlambat' => 0, 'cuti' => 0, 'pulang_dahulu' => 0);
    $records = $pdo->prepare("SELECT 1 WHERE 1=0");
    $shifts = array();
    $displayDate = date('l, d F Y');
}
?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no" />
    <meta name="description" content="SIHADIR - Sistem Kehadiran Sing Long Brother Industrial" />
    <meta name="author" content="Sing Long Brother Industrial" />
    <title>SIHADIR | Monitor Presensi</title>
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

        .slb-card-title {
            font-weight: 700;
            color: var(--slb-navy);
            font-size: 15px;
            margin-bottom: 18px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .slb-card-title i {
            color: var(--slb-amber);
        }

        /* ===== FILTER BAR ===== */
        .filter-bar-slb {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 20px;
        }
        .filter-bar-slb .filter-left {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }
        .filter-input-slb {
            border: 1px solid #e2e6ee;
            border-radius: 10px;
            padding: 9px 14px;
            font-size: 13.5px;
            background: #fff;
            color: var(--slb-text);
        }
        .filter-input-slb:focus {
            outline: none;
            border-color: var(--slb-amber);
            box-shadow: 0 0 0 3px rgba(245,166,35,0.15);
        }
        .search-input-slb {
            width: 100%;
            max-width: 280px;
        }

        /* ===== STATUS CARDS ===== */
        .status-grid-slb {
            display: grid;
            grid-template-columns: repeat(6, 1fr);
            gap: 16px;
        }
        .status-card {
            border-radius: 14px;
            padding: 16px;
            text-align: center;
            transition: transform 0.25s, box-shadow 0.25s;
            border: 1px solid transparent;
        }
        .status-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 10px 20px rgba(16,24,40,0.08);
        }
        .status-card p.label { font-size: 12.5px; color: var(--slb-muted); margin-bottom: 6px; font-weight: 600; }
        .status-card p.value { font-size: 26px; font-weight: 800; margin: 0; font-family: 'Sora', sans-serif; }

        .status-hadir { background: #e9f9f0; }
        .status-hadir .value { color: #0d9d58; }
        .status-terlambat { background: #fff6e0; }
        .status-terlambat .value { color: #b07a05; }
        .status-alpha { background: #fdebec; }
        .status-alpha .value { color: #e0334d; }
        .status-cuti { background: #f1ecfb; }
        .status-cuti .value { color: #7c4fda; }
        .status-pulang { background: #e7f1fd; }
        .status-pulang .value { color: #1c7ed6; }
        .status-tidakabsen { background: #ffe9da; }
        .status-tidakabsen .value { color: #c4581a; }

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

        .badge-status {
            display: inline-block;
            padding: 5px 14px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 600;
        }
        .badge-hadir { background: #e3f9ee; color: #0d9d58; }
        .badge-terlambat { background: #fff6e0; color: #b07a05; }
        .badge-pulangawal { background: #e7f1fd; color: #1c7ed6; }
        .badge-alpha { background: #fdebec; color: #e0334d; }
        .badge-izin { background: #f1ecfb; color: #7c4fda; }
        .badge-cuti { background: #ffe9da; color: #c4581a; }
        .badge-default { background: #eef0f4; color: #6b7280; }

        .action-btn-slb {
            border: none;
            border-radius: 999px;
            padding: 8px 16px;
            font-size: 12.5px;
            font-weight: 600;
            transition: all 0.2s;
        }
        .action-btn-emergency {
            background: #fdebec;
            color: #e0334d;
        }
        .action-btn-emergency:hover:not(:disabled) {
            background: #fbd6d9;
        }
        .action-btn-reset {
            background: #e7f1fd;
            color: #1c7ed6;
        }
        .action-btn-reset:hover:not(:disabled) {
            background: #d3e6fb;
        }
        .action-btn-slb:disabled {
            opacity: 0.45;
            cursor: not-allowed;
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
            .status-grid-slb {
                grid-template-columns: repeat(3, 1fr);
            }
        }

        @media (max-width: 575.98px) {
            .topbar-slb { padding: 12px 16px; }
            .page-body-slb { padding: 16px; }
            .page-heading-slb { font-size: 21px; }
            .slb-card { padding: 16px; border-radius: 14px; }
            .topbar-date { display: none; }
            .status-grid-slb {
                grid-template-columns: repeat(2, 1fr);
            }
            .filter-bar-slb {
                flex-direction: column;
                align-items: stretch;
            }
            .search-input-slb {
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
            <a class="nav-item-slb active" href="attendanceMonitor.php">
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

            <!-- Page content -->
            <div class="page-body-slb">
                <h1 class="page-heading-slb">Monitor Presensi</h1>
                <p class="page-subheading-slb">Pantau status kehadiran staff secara langsung</p>

                <!-- Alert Messages Container -->
                <div id="alertContainer" class="fixed top-4 right-4 z-50"></div>

                <!-- Search and filter form -->
                <div class="slb-card">
                    <form class="filter-bar-slb" method="GET">
                        <div class="filter-left">
                            <input type="date" name="date" class="filter-input-slb"
                                value="<?php echo $selectedDate; ?>" min="<?php echo $earliestDate; ?>"
                                max="<?php echo date('Y-m-d'); ?>" onchange="this.form.submit()">
                            <select name="shift" class="filter-input-slb" onchange="this.form.submit()">
                                <option value="all" <?php echo ($selectedShift === 'all') ? 'selected' : ''; ?>>Semua Shift
                                </option>
                                <?php foreach ($shifts as $shift): ?>
                                    <option value="<?php echo $shift['id']; ?>" <?php echo ($selectedShift == $shift['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($shift['nama_shift']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <input type="text" name="search" id="searchInput" class="filter-input-slb search-input-slb"
                            placeholder="Cari nama/email/kode staff" value="<?php echo htmlspecialchars($searchQuery); ?>">
                    </form>
                </div>

                <!-- Status cards -->
                <div class="slb-card">
                    <div class="slb-card-title">
                        <i class="fa-solid fa-chart-pie"></i>
                        Status Presensi Staff Hari Ini (<?php echo $displayDate; ?>)
                    </div>
                    <div class="status-grid-slb">
                        <div class="status-card status-hadir">
                            <p class="label">Hadir Tepat Waktu</p>
                            <p class="value"><?php echo $stats['hadir']; ?></p>
                        </div>
                        <div class="status-card status-terlambat">
                            <p class="label">Terlambat</p>
                            <p class="value"><?php echo $stats['terlambat']; ?></p>
                        </div>
                        <div class="status-card status-alpha">
                            <p class="label">Tidak Masuk</p>
                            <p class="value"><?php echo $stats['alpha']; ?></p>
                        </div>
                        <div class="status-card status-cuti">
                            <p class="label">Cuti dan Izin</p>
                            <p class="value"><?php echo $stats['cuti'] + $stats['izin']; ?></p>
                        </div>
                        <div class="status-card status-pulang">
                            <p class="label">Pulang Lebih Awal</p>
                            <p class="value"><?php echo $stats['pulang_dahulu']; ?></p>
                        </div>
                        <div class="status-card status-tidakabsen">
                            <p class="label">Tidak Presensi Pulang</p>
                            <p class="value"><?php echo $stats['tidak_absen_pulang']; ?></p>
                        </div>
                    </div>
                </div>

                <!-- Attendance table -->
                <div class="slb-card">
                    <div class="slb-card-title"><i class="fa-solid fa-list-check"></i> Aktivitas</div>
                    <div class="table-wrap-slb">
                        <table class="slb-table min-w-full">
                            <thead>
                                <tr>
                                    <th>Nama Staff</th>
                                    <th>Nama Shift</th>
                                    <th>Status</th>
                                    <th>Jam Masuk</th>
                                    <th>Jam Pulang</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($record = $records->fetch(PDO::FETCH_ASSOC)): ?>
                                    <tr>
                                        <td>
                                            <?php echo htmlspecialchars($record['nama_pegawai']); ?>
                                        </td>
                                        <td>
                                            <?php
                                            echo $record['nama_shift']
                                                ? htmlspecialchars($record['nama_shift']) . ' (' .
                                                substr($record['jam_masuk'], 0, 5) . ' - ' .
                                                substr($record['jam_keluar'], 0, 5) . ')'
                                                : 'Belum Ditentukan';
                                            ?>
                                        </td>
                                        <td>
                                            <?php
                                            switch ($record['status_kehadiran']) {
                                                case 'hadir':
                                                    $statusKehadiran = "Hadir";
                                                    $statusClass = "badge-status badge-hadir";
                                                    break;
                                                case 'dalam_shift':
                                                    $statusKehadiran = "Dalam Shift";
                                                    $statusClass = "badge-status badge-hadir";
                                                    break;
                                                case 'terlambat':
                                                    $statusKehadiran = "Terlambat";
                                                    $statusClass = "badge-status badge-terlambat";
                                                    break;
                                                case 'pulang_dahulu':
                                                    $statusKehadiran = "Pulang Lebih Awal";
                                                    $statusClass = "badge-status badge-pulangawal";
                                                    break;
                                                case 'alpha':
                                                    $statusKehadiran = "Tidak Masuk";
                                                    $statusClass = "badge-status badge-alpha";
                                                    break;
                                                case 'libur':
                                                    $statusKehadiran = "Libur";
                                                    $statusClass = "badge-status badge-alpha";
                                                    break;
                                                case 'izin':
                                                    $statusKehadiran = "Izin";
                                                    $statusClass = "badge-status badge-izin";
                                                    break;
                                                case 'cuti':
                                                    $statusKehadiran = "Cuti";
                                                    $statusClass = "badge-status badge-cuti";
                                                    break;
                                                case 'tidak_absen_pulang':
                                                    $statusKehadiran = "Tidak Presensi Pulang";
                                                    $statusClass = "badge-status badge-cuti";
                                                    break;
                                                default:
                                                    $statusKehadiran = "Tidak Diketahui";
                                                    $statusClass = "badge-status badge-default";
                                                    break;
                                            }
                                            ?>
                                            <span class="<?php echo $statusClass; ?>">
                                                <?php echo $statusKehadiran; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php echo $record['waktu_masuk'] ? date('H:i', strtotime($record['waktu_masuk'])) : '-'; ?>
                                        </td>
                                        <td>
                                            <?php echo $record['waktu_keluar'] ? date('H:i', strtotime($record['waktu_keluar'])) : '-'; ?>
                                        </td>
                                        <?php
                                        // Tambahkan debug untuk memastikan
                                        $today = date('Y-m-d');
                                        $isToday = $selectedDate === $today;

                                        // Debug logging
                                        error_log("Selected Date: " . $selectedDate);
                                        error_log("Today's Date: " . $today);
                                        error_log("Is Today: " . ($isToday ? 'Yes' : 'No'));
                                        ?>

                                        <!-- Di dalam loop tabel, ubah bagian tombol menjadi: -->
                                        <td>
                                            <div class="flex space-x-2 justify-center">
                                                <button onclick="showEmergencyLeaveModal(<?php echo $record['pegawai_id']; ?>)"
                                                    class="action-btn-slb action-btn-emergency" <?php
                                                    echo (!$isToday || $record['status_kehadiran'] !== 'alpha')
                                                        ? 'disabled'
                                                        : '';
                                                    ?>>
                                                    Izin darurat
                                                </button>
                                                <button onclick="showResetEntryModal(<?php echo $record['pegawai_id']; ?>)"
                                                    class="action-btn-slb action-btn-reset" <?php
                                                    echo !$isToday
                                                        ? 'disabled'
                                                        : '';
                                                    ?>>
                                                    Reset Entry
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Emergency Leave Modal -->
                <div class="modal fade" id="emergencyLeaveModal" tabindex="-1"
                    aria-labelledby="emergencyLeaveModalLabel" aria-hidden="true">
                    <div class="modal-dialog modal-md">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title" id="emergencyLeaveModalLabel">Pengajuan Izin Darurat</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"
                                    aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <form id="emergencyLeaveForm" method="POST">
                                    <input type="hidden" id="employeeId" name="employeeId">
                                    <input type="hidden" name="selectedDate" value="<?php echo $selectedDate; ?>">

                                    <!-- Jenis Izin -->
                                    <div class="mb-3">
                                        <label class="form-label">Jenis Izin</label>
                                        <select name="leaveType" class="form-select" required>
                                            <option value="keperluan_pribadi">Keperluan Pribadi</option>
                                            <option value="dinas_luar">Dinas Luar</option>
                                            <option value="sakit">Sakit</option>
                                        </select>
                                    </div>

                                    <!-- Keterangan -->
                                    <div class="mb-3">
                                        <label class="form-label">Keterangan</label>
                                        <textarea name="description" class="form-control" rows="4" required></textarea>
                                    </div>

                                    <!-- Modal Footer -->
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-secondary"
                                            data-bs-dismiss="modal">Batal</button>
                                        <button type="submit" class="btn btn-primary">Simpan</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Reset Entry Modal -->
                <div class="modal fade" id="resetEntryModal" tabindex="-1" aria-labelledby="resetEntryModalLabel"
                    aria-hidden="true">
                    <div class="modal-dialog modal-md"> <!-- Mengurangi ukuran modal menjadi medium -->
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title" id="resetEntryModalLabel">Reset Entry Presensi</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"
                                    aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <form id="resetEntryForm" method="POST">
                                    <input type="hidden" id="resetEmployeeId" name="employeeId">
                                    <input type="hidden" name="selectedDate"
                                        value="<?php echo htmlspecialchars($selectedDate); ?>">

                                    <p class="mb-4 text-sm text-gray-600">
                                        Apakah Anda yakin ingin mereset entry presensi ini? Tindakan ini akan menghapus
                                        data izin dan presensi untuk hari ini.
                                    </p>
                                </form>
                            </div>
                            <!-- Modal Footer -->
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary btn-sm"
                                    data-bs-dismiss="modal">Batal</button>
                                <button type="submit" form="resetEntryForm" class="btn btn-danger btn-sm">Reset</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap core JS-->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js"></script>
    <!-- Core theme JS-->
    <script src="../../../assets/js/scripts.js"></script>

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
            const dateInput = document.querySelector('input[name="date"]');
            const earliestDate = '<?php echo $earliestDate; ?>';
            const today = new Date().toISOString().split('T')[0];

            // Ensure the date is within valid range when changed
            dateInput.addEventListener('change', function () {
                const selectedDate = this.value;
                if (selectedDate < earliestDate) {
                    this.value = earliestDate;
                } else if (selectedDate > today) {
                    this.value = today;
                }
            });
        });
    </script>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const searchInput = document.getElementById('searchInput');
            const dateInput = document.querySelector('input[name="date"]');
            const shiftSelect = document.querySelector('select[name="shift"]');

            function updateResults() {
                const searchQuery = searchInput.value;
                const selectedDate = dateInput.value;
                const selectedShift = shiftSelect.value;

                // Membuat URL dengan parameter pencarian
                const url = new URL(window.location.href);
                url.searchParams.set('search', searchQuery);
                url.searchParams.set('date', selectedDate);
                url.searchParams.set('shift', selectedShift);

                // Melakukan request AJAX
                fetch(url)
                    .then(response => response.text())
                    .then(html => {
                        const parser = new DOMParser();
                        const doc = parser.parseFromString(html, 'text/html');

                        // Update tabel hasil
                        const newTable = doc.querySelector('.min-w-full');
                        const currentTable = document.querySelector('.min-w-full');
                        currentTable.innerHTML = newTable.innerHTML;

                        // Update status cards
                        const newStatusCards = doc.querySelectorAll('.status-card');
                        const currentStatusCards = document.querySelectorAll('.status-card');
                        currentStatusCards.forEach((card, index) => {
                            card.innerHTML = newStatusCards[index].innerHTML;
                        });
                    })
                    .catch(error => console.error('Error:', error));
            }

            // Menambahkan event listener untuk input pencarian
            searchInput.addEventListener('input', updateResults);

            // Menambahkan event listener untuk perubahan tanggal dan shift
            dateInput.addEventListener('change', updateResults);
            shiftSelect.addEventListener('change', updateResults);
        });

        function showAlert(message, type) {
            const alertDiv = document.createElement('div');
            alertDiv.classList.add('alert',
                type === 'success' ? 'alert-success' : 'alert-danger',
                'alert-dismissible',
                'fade',
                'show'
            );
            alertDiv.innerHTML = `
                ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            `;

            document.getElementById('alertContainer').appendChild(alertDiv);

            // Automatically remove the alert after a few seconds
            const alert = new bootstrap.Alert(alertDiv);
            setTimeout(() => {
                alert.close();
            }, 5000);
        }

        function showEmergencyLeaveModal(employeeId) {
            // Reset form sebelum menampilkan modal
            const form = document.getElementById('emergencyLeaveForm');
            form.reset();

            // Set employee ID dan tampilkan modal
            document.getElementById('employeeId').value = employeeId;
            const modal = new bootstrap.Modal(document.getElementById('emergencyLeaveModal'));
            modal.show();
        }

        function closeEmergencyLeaveModal() {
            const modal = document.getElementById('emergencyLeaveModal');
            modal.classList.add('scale-0', 'opacity-0');
            modal.querySelector('.bg-white').classList.add('scale-95');
            setTimeout(() => {
                modal.classList.remove('flex');
                modal.classList.add('hidden');
            }, 300);
        }

        function showResetEntryModal(employeeId) {
            // Reset form sebelum menampilkan modal
            const form = document.getElementById('resetEntryForm');
            form.reset();

            // Set employee ID dan tampilkan modal
            document.getElementById('resetEmployeeId').value = employeeId;
            const modal = new bootstrap.Modal(document.getElementById('resetEntryModal'));
            modal.show();
        }

        function closeResetEntryModal() {
            const modal = document.getElementById('resetEntryModal');
            modal.classList.add('scale-0', 'opacity-0');
            modal.querySelector('.bg-white').classList.add('scale-95');
            setTimeout(() => {
                modal.classList.remove('flex');
                modal.classList.add('hidden');
            }, 300);
        }

        document.addEventListener('DOMContentLoaded', function () {
            const emergencyLeaveForm = document.getElementById('emergencyLeaveForm');
            const resetEntryForm = document.getElementById('resetEntryForm');
            const searchInput = document.getElementById('searchInput');
            const dateInput = document.querySelector('input[name="date"]');
            const shiftSelect = document.querySelector('select[name="shift"]');

            function clearForm(form) {
                form.reset();
                const textareas = form.querySelectorAll('textarea');
                const selects = form.querySelectorAll('select');

                textareas.forEach(textarea => {
                    textarea.value = '';
                });

                selects.forEach(select => {
                    select.selectedIndex = 0;
                });
            }

            // Handler untuk form izin mendadak
            emergencyLeaveForm.addEventListener('submit', function (e) {
                e.preventDefault();
                const formData = new FormData(this);
                const selectedDate = dateInput.value; // Mengambil tanggal yang dipilih
                formData.append('selectedDate', selectedDate); // Menambahkan tanggal ke FormData

                fetch('../../handler/emergency_leave_handler.php', {
                    method: 'POST',
                    body: formData
                })
                    .then(response => response.json())
                    .then(data => {
                        const modal = bootstrap.Modal.getInstance(document.getElementById('emergencyLeaveModal'));
                        modal.hide();
                        clearForm(emergencyLeaveForm); // Clear form setelah submit

                        if (data.success) {
                            showAlert(data.message, 'success');
                            // Refresh halaman setelah sukses
                            setTimeout(() => {
                                window.location.reload();
                            }, 1500);
                        } else {
                            showAlert(data.message, 'error');
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        const modal = bootstrap.Modal.getInstance(document.getElementById('emergencyLeaveModal'));
                        modal.hide();
                        clearForm(emergencyLeaveForm); // Clear form meskipun error
                        showAlert('Terjadi kesalahan saat memproses izin mendadak', 'error');
                    });
            });

            // Handler untuk form reset entry
            resetEntryForm.addEventListener('submit', function (e) {
                e.preventDefault();
                const formData = new FormData(this);
                const selectedDate = dateInput.value; // Mengambil tanggal yang dipilih
                formData.append('selectedDate', selectedDate); // Menambahkan tanggal ke FormData

                fetch('../../handler/reset_entry_handler.php', {
                    method: 'POST',
                    body: formData
                })
                    .then(response => response.json())
                    .then(data => {
                        const modal = bootstrap.Modal.getInstance(document.getElementById('resetEntryModal'));
                        modal.hide();
                        clearForm(resetEntryForm); // Clear form setelah submit

                        if (data.success) {
                            showAlert(data.message, 'success');
                            // Refresh halaman setelah sukses
                            setTimeout(() => {
                                window.location.reload();
                            }, 1500);
                        } else {
                            showAlert(data.message, 'error');
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        const modal = bootstrap.Modal.getInstance(document.getElementById('resetEntryModal'));
                        modal.hide();
                        clearForm(resetEntryForm); // Clear form meskipun error
                        showAlert('Terjadi kesalahan saat mereset entry', 'error');
                    });
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

</html>