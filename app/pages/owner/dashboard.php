<?php
session_start();
require_once '../../../app/auth/auth.php';

date_default_timezone_set('Asia/Jakarta');

// BULANAN
$queryBulanan = $pdo->query("SELECT MONTH(waktu_masuk) AS bulan, COUNT(*) AS total_kehadiran
                                FROM absensi
                                WHERE YEAR(waktu_masuk) = YEAR(NOW()) 
                                AND status_kehadiran IN ('hadir', 'terlambat', 'pulang_dahulu', 'tidak_absen_pulang', 'dalam_shift')
                                GROUP BY bulan
                                ORDER BY bulan;
");
$dataBulanan = $queryBulanan->fetchAll(PDO::FETCH_ASSOC);
$monthlyAttendance = array_fill(0, 12, 0); // Inisialisasi array dengan 12 elemen (0 untuk semua bulan)
$bulanLabel = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

foreach ($dataBulanan as $row) {
    // Set nilai kehadiran untuk bulan yang sesuai (kurangi 1 untuk indeks)
    $monthlyAttendance[$row['bulan'] - 1] = (int) $row['total_kehadiran'];
}


// MINGGUAN
// Mendapatkan hari ini dan tanggal awal minggu (Senin)
$today = new DateTime();
$startOfWeek = clone $today;
$startOfWeek->modify('monday this week'); // Mendapatkan tanggal Senin minggu ini

// Menghitung tanggal akhir minggu (Minggu)
$endOfWeek = clone $startOfWeek;
$endOfWeek->modify('+1 week');

// Siapkan array untuk kehadiran mingguan
$weeklyAttendance = array_fill(0, 7, 0); // Inisialisasi array dengan 7 elemen (0 untuk setiap hari)

// Query untuk mendapatkan kehadiran mingguan
$queryMingguan = $pdo->prepare("
    SELECT DAYOFWEEK(tanggal) AS hari, COUNT(*) AS total_kehadiran
    FROM absensi
    WHERE tanggal >=:startOfWeek
      AND tanggal < :endOfWeek
      AND status_kehadiran IN ('hadir', 'terlambat', 'pulang_dahulu', 'tidak_absen_pulang', 'dalam_shift')
    GROUP BY hari;
");

// Bind parameter dan execute
$queryMingguan->bindValue(':startOfWeek', $startOfWeek->format('Y-m-d')); // Hanya perlu format tanggal
$queryMingguan->bindValue(':endOfWeek', $endOfWeek->format('Y-m-d')); // Hanya perlu format tanggal
$queryMingguan->execute();

// Mengisi data kehadiran mingguan
$dataMingguan = $queryMingguan->fetchAll(PDO::FETCH_ASSOC);
foreach ($dataMingguan as $row) {
    $hariIndex = ($row['hari'] + 6) % 7; // Mengubah DAYOFWEEK ke indeks array (0 untuk Senin, 1 untuk Selasa, dst.)
    $weeklyAttendance[$hariIndex] = (int) $row['total_kehadiran'];
}



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

?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no" />
    <meta name="description" content="SIHADIR - Sistem Kehadiran Sing Long Brother Industrial" />
    <meta name="author" content="Sing Long Brother Industrial" />
    <title>SIHADIR | Dashboard</title>
    <!-- Favicon-->
    <link rel="icon" type="image/x-icon" href="../../../assets/icon/favicon.ico" />
    <!-- Core theme CSS (includes Bootstrap)-->
    <link href="../../../assets/css/styles.css" rel="stylesheet" />
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Sora:wght@600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

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
            transition: transform 0.25s, box-shadow 0.25s;
        }
        .slb-card:hover {
            box-shadow: 0 10px 24px rgba(16,24,40,0.08);
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

        .chart-container {
            position: relative;
            height: 290px;
            width: 100%;
        }

        .charts-grid-slb {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 22px;
            margin-bottom: 22px;
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

        .badge-status {
            display: inline-block;
            padding: 5px 14px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 600;
        }
        .badge-hadir { background: #e3f9ee; color: #0d9d58; }
        .badge-terlambat { background: #fdebec; color: #e0334d; }
        .badge-pulangawal { background: #fff6e0; color: #b07a05; }
        .badge-tidakabsen { background: #ffe9da; color: #c4581a; }

        .activity-text {
            color: var(--slb-teal);
            font-weight: 500;
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
            .charts-grid-slb {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 575.98px) {
            .topbar-slb { padding: 12px 16px; }
            .page-body-slb { padding: 16px; }
            .page-heading-slb { font-size: 21px; }
            .slb-card { padding: 16px; border-radius: 14px; }
            .topbar-date { display: none; }
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
            <a class="nav-item-slb active" href="dashboard.php">
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
                <h1 class="page-heading-slb">Dashboard</h1>
                <p class="page-subheading-slb">Ringkasan presensi karyawan secara real-time</p>

                <!-- Charts Section -->
                <div class="charts-grid-slb">
                    <!-- Monthly Attendance Trend -->
                    <div class="slb-card">
                        <div class="slb-card-title"><i class="fa-solid fa-chart-column"></i> Presensi Bulanan</div>
                        <div class="chart-container">
                            <canvas id="monthlyChart"></canvas>
                        </div>
                    </div>

                    <!-- Weekly Attendance -->
                    <div class="slb-card">
                        <div class="slb-card-title"><i class="fa-solid fa-chart-line"></i> Presensi Seminggu Terakhir</div>
                        <div class="chart-container">
                            <canvas id="weeklyChart"></canvas>
                        </div>
                    </div>
                </div>

                <!-- Tabel Kehadiran -->
                <div id="activityTable" class="slb-card">
                    <div class="slb-card-title"><i class="fa-solid fa-list-check"></i> Aktivitas Hari Ini</div>
                    <div class="table-wrap-slb">
                        <table class="slb-table">
                            <thead>
                                <tr>
                                    <th>Nama Staff</th>
                                    <th>Divisi</th>
                                    <th>Shift</th>
                                    <th>Aktivitas</th>
                                    <th>Status Presensi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                // Get current date
                                $today = date('Y-m-d');

                                // Query untuk mengambil data absensi hari ini
                                $stmt = $pdo->prepare("
                                    SELECT 
                                        u.nama_lengkap AS nama_staff,
                                        d.nama_divisi AS divisi,
                                        u.role AS jabatan,
                                        s.nama_shift AS nama_shift,
                                        a.waktu_masuk,
                                        a.waktu_keluar,
                                        a.status_kehadiran,
                                        DATE(a.tanggal) as tanggal_absen
                                    FROM 
                                        absensi a
                                    JOIN 
                                        pegawai p ON a.pegawai_id = p.id
                                    JOIN 
                                        users u ON p.user_id = u.id
                                    LEFT JOIN 
                                        divisi d ON p.divisi_id = d.id
                                    JOIN 
                                        jadwal_shift js ON a.jadwal_shift_id = js.id
                                    JOIN 
                                        shift s ON js.shift_id = s.id
                                    WHERE 
                                        DATE(a.tanggal) = :today
                                        AND (a.waktu_masuk != '00:00:00' OR a.waktu_keluar != '00:00:00')
                                        AND a.status_kehadiran != ''
                                    ORDER BY 
                                        CASE 
                                            WHEN a.waktu_masuk != '00:00:00' THEN a.waktu_masuk
                                            ELSE a.waktu_keluar
                                        END DESC
                                ");

                                $stmt->execute(['today' => $today]);
                                $todayAbsences = $stmt->fetchAll(PDO::FETCH_ASSOC);

                                // Jika tidak ada data hari ini
                                if (empty($todayAbsences)) {
                                    echo "<tr><td colspan='5' class='px-6 py-4 text-center'>Belum Ada Aktivitas Hari Ini</td></tr>";
                                } else {
                                    foreach ($todayAbsences as $absen) {
                                        // Handle waktu masuk dan keluar
                                        $aktivitas = "";
                                        if ($absen['waktu_masuk'] !== '00:00:00') {
                                            $aktivitas = "Absen Masuk (" . htmlspecialchars($absen['waktu_masuk']) . ")";
                                        }

                                        if ($absen['waktu_keluar'] !== '00:00:00') {
                                            $aktivitas = $aktivitas ? $aktivitas . "<br>" : "";
                                            $aktivitas .= "Absen Keluar (" . htmlspecialchars($absen['waktu_keluar']) . ")";
                                        }

                                        switch ($absen['status_kehadiran']) {
                                            case 'hadir':
                                                $statusKehadiran = "Hadir Tepat Waktu";
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
                                            case 'dalam_shift':
                                                $statusKehadiran = "Hadir Tepat Waktu";
                                                $statusClass = "badge-status badge-hadir";
                                                break;
                                            case 'tidak_absen_pulang':
                                                $statusKehadiran = "Tidak Absen Pulang";
                                                $statusClass = "badge-status badge-tidakabsen";
                                                break;
                                            default:
                                                $statusKehadiran = "";
                                                $statusClass = "";
                                                break;
                                        }

                                        echo "<tr>";
                                        echo "<td>" . htmlspecialchars($absen['nama_staff']) . "</td>";
                                        echo "<td>" . htmlspecialchars($absen['divisi']) . "</td>";
                                        echo "<td>" . htmlspecialchars($absen['nama_shift']) . "</td>";
                                        echo "<td class='activity-text'>" . $aktivitas . "</td>";
                                        echo "<td>" . "<span class='$statusClass'>" . htmlspecialchars($statusKehadiran) . "</span>" . "</td>";
                                        echo "</tr>";
                                    }
                                }
                                ?>
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
    <script src="../../../assets/js/scripts.js"></script>

    <script>
        function checkReset() {
            const now = new Date();

            // Mendapatkan waktu saat ini dalam WIB (UTC+7)
            const currentHour = now.getUTCHours() + 7; // Mengonversi ke WIB
            const currentMinute = now.getUTCMinutes();

            // Memastikan jam tidak melebihi 24
            const adjustedHour = currentHour >= 24 ? currentHour - 24 : currentHour;

            // Cek apakah waktu saat ini adalah 10:15 WIB
            const isTenFifteen = adjustedHour === 12 && currentMinute === 25;

            const tbody = document.querySelector('#activityTable tbody');

            if (isTenFifteen) {
                // Jika sudah jam 10:15, ubah isi tbody menjadi "Tidak ada aktivitas absen"
                tbody.innerHTML = "<tr><td colspan='5' class='px-6 py-4 text-center'>Tidak ada aktivitas absen</td></tr>";
            } else {
                // Logika untuk mengembalikan data jika perlu
                // Misalnya, jika ingin mengupdate data dengan AJAX atau fetch data dari server
                // Anda bisa memanggil fungsi untuk mengambil data baru atau memperbarui tampilan tabel
            }
        }

        // Jalankan sekali saat halaman dimuat
        checkReset();

        // Periksa setiap menit
        setInterval(checkReset, 60000); // 60000 ms = 1 menit
    </script>

    <script>
        // Data untuk grafik bulanan
        const monthlyData = <?php echo json_encode($monthlyAttendance); ?>;

        // Mengubah data bulanan menjadi format yang sesuai
        const monthlyLabels = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

        // Grafik kehadiran bulanan
        const monthlyChartCtx = document.getElementById('monthlyChart').getContext('2d');
        const monthlyChart = new Chart(monthlyChartCtx, {
            type: 'bar',
            data: {
                labels: monthlyLabels,
                datasets: [{
                    label: 'Presensi Bulanan',
                    data: monthlyData, // Pastikan monthlyData berisi 12 angka, satu untuk setiap bulan
                    backgroundColor: 'rgba(245, 166, 35, 0.18)',
                    borderColor: 'rgba(245, 166, 35, 1)',
                    borderWidth: 2,
                    borderRadius: 6,
                    maxBarThickness: 34
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    y: { beginAtZero: true, grid: { color: '#f0f2f6' } },
                    x: { grid: { display: false } }
                }
            }
        });

        // Data untuk grafik mingguan
        const weeklyData = <?php echo json_encode($weeklyAttendance); ?>;

        // Grafik kehadiran mingguan
        const weeklyChartCtx = document.getElementById('weeklyChart').getContext('2d');
        const weeklyChart = new Chart(weeklyChartCtx, {
            type: 'bar',
            data: {
                labels: ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'],
                datasets: [{
                    label: 'Presensi Seminggu Terakhir',
                    data: weeklyData,
                    backgroundColor: 'rgba(15, 181, 174, 0.18)',
                    borderColor: 'rgba(15, 181, 174, 1)',
                    borderWidth: 2,
                    borderRadius: 6,
                    maxBarThickness: 34
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    y: { beginAtZero: true, grid: { color: '#f0f2f6' } },
                    x: { grid: { display: false } }
                }
            }
        });
    </script>

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