<?php
session_start();
require_once '../../../app/auth/auth.php';

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header('Location: ../../../login.php');
    exit;
}

if (isset($_SESSION['role']) && $_SESSION['role'] !== 'karyawan') {
    session_unset();
    session_destroy();
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Cache-Control: post-check=0, pre-check=0', false);
    header('Pragma: no-cache');
    header('Location: ../../../login.php');
    exit;
}

function getBadgeClass($status) {
    switch ($status) {
        case 'hadir':        return 'badge-hadir';
        case 'terlambat':    return 'badge-terlambat';
        case 'pulang_dahulu': return 'badge-pulang-dahulu';
        case 'dalam_shift':  return 'badge-hadir';
        case 'tidak_absen_pulang': return 'badge-tidak-pulang';
        case 'sakit':        return 'badge-sakit';
        case 'izin':         return 'badge-izin';
        case 'alpha':        return 'badge-alpha';
        case 'cuti':         return 'badge-cuti';
        case 'libur':        return 'badge-libur';
        default:             return 'badge-default';
    }
}

function getStatusLabel($status) {
    switch ($status) {
        case 'hadir':        return 'Hadir';
        case 'terlambat':    return 'Terlambat';
        case 'pulang_dahulu': return 'Pulang Lebih Awal';
        case 'dalam_shift':  return 'Hadir';
        case 'tidak_absen_pulang': return 'Tidak Presensi Pulang';
        case 'sakit':        return 'Sakit';
        case 'izin':         return 'Izin';
        case 'alpha':        return 'Alpha';
        case 'cuti':         return 'Cuti';
        case 'libur':        return 'Libur';
        default:             return ucfirst(str_replace('_', ' ', $status));
    }
}

$query = "
    SELECT
        u.nama_lengkap,
        s.nama_shift,
        CONCAT(s.jam_masuk, ' - ', s.jam_keluar) as jadwal_shift,
        s.jam_masuk,
        s.jam_keluar,
        a.waktu_masuk,
        a.waktu_keluar,
        a.status_kehadiran,
        DATE(a.tanggal) as tanggal
    FROM absensi a
    JOIN pegawai p ON a.pegawai_id = p.id
    JOIN users u ON p.user_id = u.id
    JOIN jadwal_shift js ON a.jadwal_shift_id = js.id
    JOIN shift s ON js.shift_id = s.id
    WHERE p.user_id = :user_id
    ORDER BY a.tanggal DESC
";

try {
    $stmt = $pdo->prepare($query);
    $stmt->execute(['user_id' => $_SESSION['id']]);
    $attendances = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Database Error: " . $e->getMessage());
    $attendances = [];
}

// Stats
$totalHadir   = 0; $totalTerlambat = 0; $totalAlpha = 0; $totalIzinCuti = 0;
foreach ($attendances as $a) {
    $s = $a['status_kehadiran'];
    if (in_array($s, ['hadir', 'dalam_shift'])) $totalHadir++;
    elseif ($s === 'terlambat') $totalTerlambat++;
    elseif ($s === 'alpha') $totalAlpha++;
    elseif (in_array($s, ['izin', 'cuti', 'sakit'])) $totalIzinCuti++;
}
$totalRecords = count($attendances);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Si Hadir — Riwayat Kehadiran</title>
    <link rel="icon" type="image/x-icon" href="../../../assets/favicon.ico" />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css" rel="stylesheet">

    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --bg-base:     #090d1a;
            --bg-surface:  #0f1629;
            --bg-card:     #131c35;
            --bg-input:    #1a2340;
            --border:      #1e2d50;
            --border-glow: rgba(0, 212, 255, 0.25);
            --teal:        #00d4ff;
            --teal-dim:    rgba(0, 212, 255, 0.12);
            --teal-mid:    rgba(0, 212, 255, 0.4);
            --green:       #00e5a0;
            --green-dim:   rgba(0, 229, 160, 0.12);
            --amber:       #ffb84d;
            --amber-dim:   rgba(255, 184, 77, 0.12);
            --red:         #ff4d6d;
            --red-dim:     rgba(255, 77, 109, 0.12);
            --blue:        #4d9fff;
            --blue-dim:    rgba(77, 159, 255, 0.12);
            --purple:      #b47dff;
            --purple-dim:  rgba(180, 125, 255, 0.12);
            --text-primary:   #f0f4ff;
            --text-secondary: #8892a4;
            --text-muted:     #4a5568;
            --sidebar-w: 240px;
        }

        html, body { height: 100%; background: var(--bg-base); color: var(--text-primary); font-family: 'Inter', sans-serif; overflow-x: hidden; }

        ::-webkit-scrollbar { width: 4px; height: 4px; }
        ::-webkit-scrollbar-track { background: var(--bg-base); }
        ::-webkit-scrollbar-thumb { background: var(--border); border-radius: 4px; }

        #wrapper { display: flex; min-height: 100vh; }

        /* ── SIDEBAR ── */
        #sidebar-wrapper {
            width: var(--sidebar-w);
            min-height: 100vh;
            background: var(--bg-surface);
            border-right: 1px solid var(--border);
            display: flex;
            flex-direction: column;
            flex-shrink: 0;
            position: fixed;
            top: 0; left: 0;
            z-index: 200;
            transition: transform 0.3s cubic-bezier(0.4,0,0.2,1);
        }

        .sidebar-brand {
            padding: 1.5rem 1.25rem 1rem;
            border-bottom: 1px solid var(--border);
        }

        .brand-mark { display: flex; align-items: center; gap: 0.625rem; }

        .brand-icon {
            width: 32px; height: 32px;
            background: linear-gradient(135deg, var(--teal), #0090b3);
            border-radius: 8px;
            display: flex; align-items: center; justify-content: center;
        }

        .brand-icon svg { width: 18px; height: 18px; fill: #090d1a; }

        .brand-name { font-size: 1.125rem; font-weight: 800; letter-spacing: -0.5px; color: var(--text-primary); }
        .brand-name span { color: var(--teal); }

        .sidebar-nav { flex: 1; padding: 0.75rem 0; }

        .nav-item-link {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem 1.25rem;
            color: var(--text-secondary);
            text-decoration: none;
            font-size: 0.875rem;
            font-weight: 500;
            border-left: 2px solid transparent;
            transition: all 0.18s ease;
        }

        .nav-item-link:hover { color: var(--text-primary); background: var(--teal-dim); border-left-color: var(--teal); }
        .nav-item-link.active { color: var(--teal); background: var(--teal-dim); border-left-color: var(--teal); }
        .nav-item-link svg { flex-shrink: 0; }
        .nav-label { white-space: nowrap; }

        .sidebar-footer { padding: 0.75rem; border-top: 1px solid var(--border); }

        .logout-link {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.625rem 0.875rem;
            color: var(--text-secondary);
            text-decoration: none;
            font-size: 0.875rem;
            border-radius: 8px;
            transition: all 0.18s ease;
        }

        .logout-link:hover { color: var(--red); background: var(--red-dim); }

        /* ── TOPBAR ── */
        #topbar {
            position: fixed;
            top: 0;
            left: var(--sidebar-w);
            right: 0;
            height: 56px;
            background: rgba(9, 13, 26, 0.85);
            backdrop-filter: blur(12px);
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 1.5rem;
            z-index: 100;
            transition: left 0.3s cubic-bezier(0.4,0,0.2,1);
        }

        .topbar-left { display: flex; align-items: center; gap: 0.75rem; }

        #menuToggle {
            width: 36px; height: 36px;
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 8px;
            display: flex; align-items: center; justify-content: center;
            cursor: pointer;
            color: var(--text-secondary);
            transition: all 0.18s ease;
        }

        #menuToggle:hover { color: var(--teal); border-color: var(--teal); background: var(--teal-dim); }

        .topbar-title { font-size: 0.875rem; font-weight: 600; color: var(--text-secondary); }

        /* ── PAGE CONTENT ── */
        #page-content-wrapper {
            margin-left: var(--sidebar-w);
            margin-top: 56px;
            flex: 1;
            min-height: calc(100vh - 56px);
            transition: margin-left 0.3s cubic-bezier(0.4,0,0.2,1);
        }

        .page-bg {
            min-height: calc(100vh - 56px);
            padding: 2.5rem 2rem;
            position: relative;
            overflow: hidden;
        }

        .orb {
            position: absolute;
            border-radius: 50%;
            filter: blur(80px);
            pointer-events: none;
            animation: orb-drift 12s ease-in-out infinite alternate;
        }

        .orb-1 {
            width: 500px; height: 500px;
            background: radial-gradient(circle, rgba(0,212,255,0.07) 0%, transparent 70%);
            top: -150px; right: -100px;
        }

        .orb-2 {
            width: 400px; height: 400px;
            background: radial-gradient(circle, rgba(0,229,160,0.05) 0%, transparent 70%);
            bottom: -100px; left: 10%;
            animation-delay: -6s;
        }

        @keyframes orb-drift {
            0%   { transform: translate(0,0) scale(1); }
            100% { transform: translate(30px, 20px) scale(1.06); }
        }

        .content-wrap {
            max-width: 1080px;
            margin: 0 auto;
            position: relative;
            z-index: 1;
        }

        /* ── PAGE HEADING ── */
        .page-heading { margin-bottom: 2rem; }

        .page-heading .eyebrow {
            font-size: 0.75rem;
            font-weight: 600;
            letter-spacing: 2px;
            text-transform: uppercase;
            color: var(--teal);
            margin-bottom: 0.375rem;
        }

        .page-heading h1 {
            font-size: 1.75rem;
            font-weight: 800;
            letter-spacing: -0.75px;
            color: var(--text-primary);
        }

        .page-heading p {
            font-size: 0.875rem;
            color: var(--text-secondary);
            margin-top: 0.25rem;
        }

        /* ── STAT CARDS ── */
        .stats-row {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .stat-card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 1.25rem 1.25rem 1rem;
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
            transition: border-color 0.2s;
        }

        .stat-card:hover { border-color: var(--border-glow); }

        .stat-icon {
            width: 36px; height: 36px;
            border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
        }

        .stat-icon.green  { background: var(--green-dim);  color: var(--green);  }
        .stat-icon.amber  { background: var(--amber-dim);  color: var(--amber);  }
        .stat-icon.red    { background: var(--red-dim);    color: var(--red);    }
        .stat-icon.blue   { background: var(--blue-dim);   color: var(--blue);   }

        .stat-value { font-size: 1.75rem; font-weight: 800; color: var(--text-primary); line-height: 1; }
        .stat-label { font-size: 0.75rem; color: var(--text-muted); font-weight: 500; text-transform: uppercase; letter-spacing: 0.5px; }

        /* ── TABLE CARD ── */
        .table-card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 16px;
            overflow: hidden;
        }

        .table-card-header {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
        }

        .table-card-header h2 {
            font-size: 1rem;
            font-weight: 700;
            color: var(--text-primary);
        }

        .table-card-header p {
            font-size: 0.75rem;
            color: var(--text-muted);
            margin-top: 0.125rem;
        }

        /* Search */
        .search-wrap { position: relative; }

        .search-input {
            background: var(--bg-input);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 0.5rem 1rem 0.5rem 2.25rem;
            font-size: 0.8125rem;
            color: var(--text-primary);
            font-family: 'Inter', sans-serif;
            outline: none;
            width: 200px;
            transition: all 0.2s;
        }

        .search-input::placeholder { color: var(--text-muted); }
        .search-input:focus { border-color: var(--teal); background: rgba(0,212,255,0.04); width: 240px; }

        .search-icon {
            position: absolute;
            left: 0.625rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
            pointer-events: none;
        }

        /* Table */
        .tbl-wrap { overflow-x: auto; }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        thead th {
            padding: 0.875rem 1.25rem;
            text-align: left;
            font-size: 0.6875rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: var(--text-muted);
            background: var(--bg-input);
            border-bottom: 1px solid var(--border);
            white-space: nowrap;
        }

        tbody tr {
            border-bottom: 1px solid rgba(30, 45, 80, 0.6);
            transition: background 0.15s;
        }

        tbody tr:last-child { border-bottom: none; }
        tbody tr:hover { background: rgba(0, 212, 255, 0.03); }

        td {
            padding: 1rem 1.25rem;
            font-size: 0.875rem;
            color: var(--text-secondary);
            white-space: nowrap;
            vertical-align: middle;
        }

        td.date-col { font-weight: 600; color: var(--text-primary); }
        td.time-col { font-family: 'Inter', monospace; font-weight: 600; color: var(--text-primary); }
        td.shift-col { color: var(--text-secondary); }

        .time-dash { color: var(--text-muted); }

        /* ── BADGES ── */
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
            padding: 0.25rem 0.625rem;
            border-radius: 999px;
            font-size: 0.6875rem;
            font-weight: 700;
            letter-spacing: 0.25px;
            text-transform: uppercase;
        }

        .badge::before {
            content: '';
            width: 5px; height: 5px;
            border-radius: 50%;
            background: currentColor;
            display: inline-block;
        }

        .badge-hadir       { background: var(--green-dim);  color: var(--green);  border: 1px solid rgba(0,229,160,0.2); }
        .badge-terlambat   { background: var(--amber-dim);  color: var(--amber);  border: 1px solid rgba(255,184,77,0.2); }
        .badge-pulang-dahulu { background: var(--amber-dim); color: var(--amber); border: 1px solid rgba(255,184,77,0.2); }
        .badge-tidak-pulang { background: var(--red-dim);   color: var(--red);    border: 1px solid rgba(255,77,109,0.2); }
        .badge-sakit       { background: var(--blue-dim);   color: var(--blue);   border: 1px solid rgba(77,159,255,0.2); }
        .badge-izin        { background: var(--purple-dim); color: var(--purple); border: 1px solid rgba(180,125,255,0.2); }
        .badge-alpha       { background: var(--red-dim);    color: var(--red);    border: 1px solid rgba(255,77,109,0.2); }
        .badge-cuti        { background: var(--teal-dim);   color: var(--teal);   border: 1px solid rgba(0,212,255,0.2); }
        .badge-libur       { background: rgba(74,85,104,0.2); color: var(--text-muted); border: 1px solid rgba(74,85,104,0.3); }
        .badge-default     { background: var(--bg-input);   color: var(--text-muted); border: 1px solid var(--border); }

        /* Empty state */
        .empty-state {
            padding: 4rem 2rem;
            text-align: center;
        }

        .empty-icon {
            width: 56px; height: 56px;
            background: var(--bg-input);
            border: 1px solid var(--border);
            border-radius: 16px;
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 1rem;
            color: var(--text-muted);
        }

        .empty-state h3 { font-size: 0.9375rem; font-weight: 700; color: var(--text-secondary); }
        .empty-state p  { font-size: 0.8125rem; color: var(--text-muted); margin-top: 0.375rem; }

        /* ── Pagination info ── */
        .table-footer {
            padding: 0.875rem 1.5rem;
            border-top: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 0.75rem;
            color: var(--text-muted);
        }

        .table-footer strong { color: var(--text-secondary); }

        /* ── MOBILE SIDEBAR OVERLAY ── */
        .sidebar-overlay {
            display: none;
            position: fixed; inset: 0;
            background: rgba(0,0,0,0.6);
            z-index: 199;
        }

        /* ── ENTRANCE ANIMATIONS ── */
        .fade-up {
            opacity: 0;
            transform: translateY(16px);
            animation: fadeUp 0.5s ease forwards;
        }

        @keyframes fadeUp {
            to { opacity: 1; transform: translateY(0); }
        }

        .delay-1 { animation-delay: 0.05s; }
        .delay-2 { animation-delay: 0.12s; }
        .delay-3 { animation-delay: 0.2s; }

        /* ── RESPONSIVE ── */
        @media (max-width: 900px) {
            .stats-row { grid-template-columns: repeat(2, 1fr); }
        }

        @media (max-width: 768px) {
            #sidebar-wrapper { transform: translateX(calc(-1 * var(--sidebar-w))); }
            #sidebar-wrapper.open { transform: translateX(0); }
            .sidebar-overlay { display: block; opacity: 0; visibility: hidden; transition: all 0.25s; }
            .sidebar-overlay.open { opacity: 1; visibility: visible; }
            #topbar { left: 0; }
            #page-content-wrapper { margin-left: 0; }
            .page-bg { padding: 1.5rem 1rem; }
            .table-card-header { flex-direction: column; align-items: flex-start; }
            .search-input { width: 100%; }
            .search-input:focus { width: 100%; }
            .search-wrap { width: 100%; }
        }

        @media (max-width: 480px) {
            .stats-row { grid-template-columns: 1fr 1fr; gap: 0.75rem; }
        }
    </style>
</head>
<body>

<div class="sidebar-overlay" id="sidebarOverlay"></div>

<div id="wrapper">
    <!-- ══ SIDEBAR ══ -->
    <div id="sidebar-wrapper">
        <div class="sidebar-brand">
            <div class="brand-mark">
                <div class="brand-icon">
                    <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                        <path d="M12 2C6.477 2 2 6.477 2 12s4.477 10 10 10 10-4.477 10-10S17.523 2 12 2zm0 18a8 8 0 110-16 8 8 0 010 16zm.5-13H11v6l5.25 3.15.75-1.23-4.5-2.67V7z"/>
                    </svg>
                </div>
                <span class="brand-name">Si<span>Hadir</span></span>
            </div>
        </div>

        <nav class="sidebar-nav">
            <a class="nav-item-link" href="attendance.php">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/>
                </svg>
                <span class="nav-label">Presensi</span>
            </a>
            <a class="nav-item-link" href="schedule.php">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>
                </svg>
                <span class="nav-label">Jadwal</span>
            </a>
            <a class="nav-item-link active" href="attendanceHistory.php">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>
                </svg>
                <span class="nav-label">Riwayat</span>
            </a>
            <a class="nav-item-link" href="permit.php">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/>
                </svg>
                <span class="nav-label">Cuti &amp; Izin</span>
            </a>
        </nav>

        <div class="sidebar-footer">
            <a class="logout-link" href="logout.php">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>
                </svg>
                <span>Keluar</span>
            </a>
        </div>
    </div>

    <!-- ══ MAIN ══ -->
    <div id="page-content-wrapper">

        <!-- TOPBAR -->
        <div id="topbar">
            <div class="topbar-left">
                <button id="menuToggle" aria-label="Toggle sidebar">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                        <line x1="3" y1="6"  x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/>
                    </svg>
                </button>
                <span class="topbar-title">Riwayat Kehadiran</span>
            </div>
        </div>

        <!-- PAGE -->
        <div class="page-bg">
            <div class="orb orb-1"></div>
            <div class="orb orb-2"></div>

            <div class="content-wrap">

                <!-- HEADING -->
                <div class="page-heading fade-up">
                    <div class="eyebrow">Laporan</div>
                    <h1>Riwayat Kehadiran</h1>
                    <p>Rekap lengkap data presensi Anda dari waktu ke waktu.</p>
                </div>

                <!-- STAT CARDS -->
                <div class="stats-row fade-up delay-1">
                    <div class="stat-card">
                        <div class="stat-icon green">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                <polyline points="20 6 9 17 4 12"/>
                            </svg>
                        </div>
                        <div>
                            <div class="stat-value"><?= $totalHadir ?></div>
                            <div class="stat-label">Hadir</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon amber">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>
                            </svg>
                        </div>
                        <div>
                            <div class="stat-value"><?= $totalTerlambat ?></div>
                            <div class="stat-label">Terlambat</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon red">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                            </svg>
                        </div>
                        <div>
                            <div class="stat-value"><?= $totalAlpha ?></div>
                            <div class="stat-label">Alpha</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon blue">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/>
                            </svg>
                        </div>
                        <div>
                            <div class="stat-value"><?= $totalIzinCuti ?></div>
                            <div class="stat-label">Izin / Cuti</div>
                        </div>
                    </div>
                </div>

                <!-- TABLE CARD -->
                <div class="table-card fade-up delay-2">
                    <div class="table-card-header">
                        <div>
                            <h2>Semua Catatan Presensi</h2>
                            <p>Total <?= $totalRecords ?> catatan ditemukan</p>
                        </div>
                        <div class="search-wrap">
                            <svg class="search-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
                            </svg>
                            <input type="text" class="search-input" id="searchInput" placeholder="Cari tanggal atau status…">
                        </div>
                    </div>

                    <div class="tbl-wrap">
                        <table id="historyTable">
                            <thead>
                                <tr>
                                    <th>Tanggal</th>
                                    <th>Shift</th>
                                    <th>Jadwal</th>
                                    <th>Masuk</th>
                                    <th>Keluar</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody id="tableBody">
                                <?php if (empty($attendances)): ?>
                                    <tr>
                                        <td colspan="6">
                                            <div class="empty-state">
                                                <div class="empty-icon">
                                                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                        <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
                                                    </svg>
                                                </div>
                                                <h3>Belum ada data kehadiran</h3>
                                                <p>Presensi Anda akan muncul di sini setelah tercatat.</p>
                                            </div>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($attendances as $a): ?>
                                        <tr>
                                            <td class="date-col">
                                                <?= htmlspecialchars(date('d M Y', strtotime($a['tanggal']))) ?>
                                            </td>
                                            <td class="shift-col">
                                                <?= htmlspecialchars($a['nama_shift']) ?>
                                            </td>
                                            <td class="shift-col">
                                                <?= htmlspecialchars($a['jam_masuk']) ?> – <?= htmlspecialchars($a['jam_keluar']) ?>
                                            </td>
                                            <td class="time-col">
                                                <?= ($a['waktu_masuk'] && $a['waktu_masuk'] !== '00:00:00') ? htmlspecialchars(date('H:i', strtotime($a['waktu_masuk']))) : '<span class="time-dash">—</span>' ?>
                                            </td>
                                            <td class="time-col">
                                                <?= ($a['waktu_keluar'] && $a['waktu_keluar'] !== '00:00:00') ? htmlspecialchars(date('H:i', strtotime($a['waktu_keluar']))) : '<span class="time-dash">—</span>' ?>
                                            </td>
                                            <td>
                                                <span class="badge <?= getBadgeClass($a['status_kehadiran']) ?>">
                                                    <?= htmlspecialchars(getStatusLabel($a['status_kehadiran'])) ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if (!empty($attendances)): ?>
                    <div class="table-footer">
                        <span id="visibleCount">Menampilkan <strong><?= $totalRecords ?></strong> dari <strong><?= $totalRecords ?></strong> catatan</span>
                        <span>Diperbarui otomatis setiap 5 menit</span>
                    </div>
                    <?php endif; ?>
                </div>

            </div>
        </div>
    </div>
</div>

<script>
(function(){
    /* ── SIDEBAR TOGGLE ── */
    const sidebar  = document.getElementById('sidebar-wrapper');
    const overlay  = document.getElementById('sidebarOverlay');
    const menuBtn  = document.getElementById('menuToggle');
    const isMobile = () => window.innerWidth <= 768;

    menuBtn.addEventListener('click', () => {
        if (isMobile()) {
            sidebar.classList.toggle('open');
            overlay.classList.toggle('open');
        } else {
            const isHidden = sidebar.style.transform === 'translateX(-240px)';
            sidebar.style.transform = isHidden ? '' : 'translateX(-240px)';
            document.getElementById('page-content-wrapper').style.marginLeft = isHidden ? '' : '0';
            document.getElementById('topbar').style.left = isHidden ? '' : '0';
        }
    });

    overlay.addEventListener('click', () => {
        sidebar.classList.remove('open');
        overlay.classList.remove('open');
    });

    /* ── SEARCH FILTER ── */
    const searchInput   = document.getElementById('searchInput');
    const tableBody     = document.getElementById('tableBody');
    const visibleCount  = document.getElementById('visibleCount');
    const total         = tableBody ? tableBody.querySelectorAll('tr').length : 0;

    if (searchInput && tableBody) {
        searchInput.addEventListener('input', () => {
            const q = searchInput.value.toLowerCase();
            let visible = 0;
            tableBody.querySelectorAll('tr').forEach(row => {
                const text = row.textContent.toLowerCase();
                const show = text.includes(q);
                row.style.display = show ? '' : 'none';
                if (show) visible++;
            });
            if (visibleCount) {
                visibleCount.innerHTML = `Menampilkan <strong>${visible}</strong> dari <strong>${total}</strong> catatan`;
            }
        });
    }

    /* ── AUTO REFRESH every 5 min ── */
    setTimeout(() => location.reload(), 300000);
})();
</script>
</body>
</html>