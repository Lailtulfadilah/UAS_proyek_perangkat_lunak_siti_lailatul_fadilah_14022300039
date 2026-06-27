<<<<<<< HEAD
<?php
session_start();

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

require_once '../../../app/auth/auth.php';

function getEmployeeSchedule($pdo, $userId)
{
    try {
        $stmt = $pdo->prepare("SELECT id, hari_libur FROM pegawai WHERE user_id = ?");
        $stmt->execute([$userId]);
        $employee = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$employee) return null;

        $stmt = $pdo->prepare("
            SELECT s.nama_shift, s.jam_masuk, s.jam_keluar 
            FROM jadwal_shift js 
            JOIN shift s ON js.shift_id = s.id 
            WHERE js.pegawai_id = ? AND js.status = 'aktif' 
            LIMIT 1
        ");
        $stmt->execute([$employee['id']]);
        $shift = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$shift) return null;

        $weekDays = ['senin', 'selasa', 'rabu', 'kamis', 'jumat', 'sabtu', 'minggu'];
        $schedule = [];

        foreach ($weekDays as $day) {
            $isOff = ($day === $employee['hari_libur']);
            $schedule[$day] = [
                'status'     => $isOff ? 'Libur' : 'Masuk',
                'shift_name' => $isOff ? '-' : $shift['nama_shift'],
                'jam_masuk'  => $isOff ? '-' : $shift['jam_masuk'],
                'jam_keluar' => $isOff ? '-' : $shift['jam_keluar'],
            ];
        }

        return $schedule;
    } catch (Exception $e) {
        error_log("Schedule error: " . $e->getMessage());
        return null;
    }
}

try {
    $schedule = isset($_SESSION['id']) ? getEmployeeSchedule($pdo, $_SESSION['id']) : null;
} catch (Exception $e) {
    error_log("Error getting schedule: " . $e->getMessage());
    $schedule = null;
}

// Figure out today's day name in Indonesian
date_default_timezone_set('Asia/Jakarta');
$todayMap = [
    'Monday'    => 'senin',
    'Tuesday'   => 'selasa',
    'Wednesday' => 'rabu',
    'Thursday'  => 'kamis',
    'Friday'    => 'jumat',
    'Saturday'  => 'sabtu',
    'Sunday'    => 'minggu',
];
$todayKey = $todayMap[date('l')] ?? '';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Si Hadir — Jadwal</title>
    <link rel="icon" type="image/x-icon" href="../../../assets/favicon.ico" />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css" rel="stylesheet">

    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --bg-base:      #090d1a;
            --bg-surface:   #0f1629;
            --bg-card:      #131c35;
            --bg-input:     #1a2340;
            --border:       #1e2d50;
            --border-glow:  rgba(0,212,255,0.25);
            --teal:         #00d4ff;
            --teal-dim:     rgba(0,212,255,0.10);
            --green:        #00e5a0;
            --green-dim:    rgba(0,229,160,0.10);
            --red:          #ff4d6d;
            --red-dim:      rgba(255,77,109,0.10);
            --amber:        #ffb84d;
            --amber-dim:    rgba(255,184,77,0.10);
            --text-primary:   #f0f4ff;
            --text-secondary: #8892a4;
            --text-muted:     #3d4f6e;
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

        .sidebar-brand { padding: 1.5rem 1.25rem 1rem; border-bottom: 1px solid var(--border); }
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
            display: flex; align-items: center; gap: 0.75rem;
            padding: 0.75rem 1.25rem;
            color: var(--text-secondary);
            text-decoration: none;
            font-size: 0.875rem; font-weight: 500;
            border-left: 2px solid transparent;
            transition: all 0.18s ease;
        }
        .nav-item-link:hover { color: var(--text-primary); background: var(--teal-dim); border-left-color: var(--teal); }
        .nav-item-link.active { color: var(--teal); background: var(--teal-dim); border-left-color: var(--teal); }
        .nav-label { white-space: nowrap; }

        .sidebar-footer { padding: 0.75rem; border-top: 1px solid var(--border); }
        .logout-link {
            display: flex; align-items: center; gap: 0.75rem;
            padding: 0.625rem 0.875rem;
            color: var(--text-secondary); text-decoration: none;
            font-size: 0.875rem; border-radius: 8px;
            transition: all 0.18s ease;
        }
        .logout-link:hover { color: var(--red); background: var(--red-dim); }

        /* ── TOPBAR ── */
        #topbar {
            position: fixed; top: 0;
            left: var(--sidebar-w); right: 0;
            height: 56px;
            background: rgba(9,13,26,0.85);
            backdrop-filter: blur(12px);
            border-bottom: 1px solid var(--border);
            display: flex; align-items: center; justify-content: space-between;
            padding: 0 1.5rem;
            z-index: 100;
            transition: left 0.3s cubic-bezier(0.4,0,0.2,1);
        }
        .topbar-left { display: flex; align-items: center; gap: 0.75rem; }
        #menuToggle {
            width: 36px; height: 36px;
            background: var(--bg-card); border: 1px solid var(--border); border-radius: 8px;
            display: flex; align-items: center; justify-content: center;
            cursor: pointer; color: var(--text-secondary);
            transition: all 0.18s ease;
        }
        #menuToggle:hover { color: var(--teal); border-color: var(--teal); background: var(--teal-dim); }
        .topbar-title { font-size: 0.875rem; font-weight: 600; color: var(--text-secondary); }

        /* week pill */
        .week-pill {
            display: flex; align-items: center; gap: 0.375rem;
            padding: 0.25rem 0.75rem;
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 999px;
            font-size: 0.75rem;
            color: var(--text-secondary);
            font-weight: 500;
        }
        .week-pill svg { color: var(--teal); }

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
            position: absolute; border-radius: 50%;
            filter: blur(80px); pointer-events: none;
            animation: orb-drift 12s ease-in-out infinite alternate;
        }
        .orb-1 { width: 500px; height: 500px; background: radial-gradient(circle, rgba(0,212,255,0.06) 0%, transparent 70%); top: -100px; right: -80px; }
        .orb-2 { width: 350px; height: 350px; background: radial-gradient(circle, rgba(0,229,160,0.04) 0%, transparent 70%); bottom: 0; left: 5%; animation-delay: -6s; }
        @keyframes orb-drift { 0% { transform: translate(0,0) scale(1); } 100% { transform: translate(25px,15px) scale(1.05); } }

        /* ── INNER LAYOUT ── */
        .inner {
            max-width: 900px;
            margin: 0 auto;
            position: relative;
            z-index: 1;
        }

        /* ── PAGE HEADING ── */
        .page-heading { margin-bottom: 2rem; }
        .page-heading .eyebrow { font-size: 0.75rem; font-weight: 600; letter-spacing: 2px; text-transform: uppercase; color: var(--teal); margin-bottom: 0.375rem; }
        .page-heading h1 { font-size: 1.75rem; font-weight: 800; letter-spacing: -0.75px; color: var(--text-primary); }
        .page-heading p { font-size: 0.875rem; color: var(--text-secondary); margin-top: 0.25rem; }

        /* ── SUMMARY ROW ── */
        .summary-row {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1rem;
            margin-bottom: 1.75rem;
        }

        .summary-chip {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 1rem 1.25rem;
            display: flex;
            align-items: center;
            gap: 0.875rem;
            transition: border-color 0.2s;
        }
        .summary-chip:hover { border-color: var(--border-glow); }

        .chip-icon {
            width: 36px; height: 36px;
            border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0;
        }
        .chip-icon.teal   { background: var(--teal-dim);  color: var(--teal); }
        .chip-icon.green  { background: var(--green-dim); color: var(--green); }
        .chip-icon.red    { background: var(--red-dim);   color: var(--red); }

        .chip-body {}
        .chip-label { font-size: 0.6875rem; text-transform: uppercase; letter-spacing: 1px; color: var(--text-muted); font-weight: 600; }
        .chip-value { font-size: 1.375rem; font-weight: 800; color: var(--text-primary); line-height: 1.2; }

        /* ── SCHEDULE TABLE CARD ── */
        .table-card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 20px;
            overflow: hidden;
        }

        .table-card-header {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .table-card-header h3 {
            font-size: 0.9375rem;
            font-weight: 700;
            color: var(--text-primary);
        }

        .header-right {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.75rem;
            color: var(--text-muted);
        }

        /* legend */
        .legend { display: flex; gap: 1rem; align-items: center; }
        .legend-item { display: flex; align-items: center; gap: 0.375rem; font-size: 0.75rem; color: var(--text-secondary); }
        .legend-dot { width: 8px; height: 8px; border-radius: 50%; }
        .legend-dot.green { background: var(--green); }
        .legend-dot.red   { background: var(--red); }

        /* ── TABLE ── */
        .schedule-table {
            width: 100%;
            border-collapse: collapse;
        }

        .schedule-table thead th {
            padding: 0.75rem 1.25rem;
            font-size: 0.6875rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            color: var(--text-muted);
            text-align: left;
            background: var(--bg-surface);
            border-bottom: 1px solid var(--border);
        }

        .schedule-table thead th:first-child { padding-left: 1.5rem; }
        .schedule-table thead th:last-child  { padding-right: 1.5rem; }

        .schedule-table tbody tr {
            border-bottom: 1px solid rgba(30,45,80,0.5);
            transition: background 0.15s ease;
        }
        .schedule-table tbody tr:last-child { border-bottom: none; }
        .schedule-table tbody tr:hover { background: rgba(0,212,255,0.03); }
        .schedule-table tbody tr.today-row { background: rgba(0,212,255,0.05); }
        .schedule-table tbody tr.today-row:hover { background: rgba(0,212,255,0.08); }

        .schedule-table tbody td {
            padding: 1rem 1.25rem;
            vertical-align: middle;
        }
        .schedule-table tbody td:first-child { padding-left: 1.5rem; }
        .schedule-table tbody td:last-child  { padding-right: 1.5rem; }

        /* Day cell */
        .day-wrap { display: flex; align-items: center; gap: 0.625rem; }

        .today-indicator {
            width: 6px; height: 6px;
            background: var(--teal);
            border-radius: 50%;
            box-shadow: 0 0 6px var(--teal);
            animation: pulse-teal 2s infinite;
        }

        @keyframes pulse-teal {
            0%   { box-shadow: 0 0 0 0 rgba(0,212,255,0.5); }
            70%  { box-shadow: 0 0 0 6px rgba(0,212,255,0); }
            100% { box-shadow: 0 0 0 0 rgba(0,212,255,0); }
        }

        .day-name {
            font-size: 0.9375rem;
            font-weight: 700;
            color: var(--text-primary);
        }

        .today-label {
            font-size: 0.6875rem;
            font-weight: 600;
            color: var(--teal);
            background: var(--teal-dim);
            border: 1px solid rgba(0,212,255,0.2);
            border-radius: 999px;
            padding: 0.1rem 0.5rem;
            letter-spacing: 0.5px;
        }

        /* Status badge */
        .status-pill {
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
            padding: 0.3125rem 0.75rem;
            border-radius: 999px;
            font-size: 0.75rem;
            font-weight: 700;
            letter-spacing: 0.25px;
        }
        .status-pill.masuk  { background: var(--green-dim); color: var(--green); border: 1px solid rgba(0,229,160,0.2); }
        .status-pill.libur  { background: var(--red-dim);   color: var(--red);   border: 1px solid rgba(255,77,109,0.2); }
        .status-pill .dot   { width: 5px; height: 5px; border-radius: 50%; background: currentColor; }

        /* Shift name */
        .shift-text { font-size: 0.875rem; font-weight: 600; color: var(--teal); }
        .shift-text.off { color: var(--text-muted); font-weight: 400; }

        /* Time cells */
        .time-text {
            font-size: 0.9375rem;
            font-weight: 700;
            color: var(--text-primary);
            font-variant-numeric: tabular-nums;
            letter-spacing: 0.5px;
        }
        .time-text.off { color: var(--text-muted); font-weight: 400; font-size: 0.875rem; }

        /* Time with label */
        .time-wrap { display: flex; flex-direction: column; gap: 0.125rem; }
        .time-sub  { font-size: 0.6875rem; color: var(--text-muted); }

        /* Duration bar */
        .duration-bar {
            height: 4px;
            background: var(--border);
            border-radius: 2px;
            overflow: hidden;
            margin-top: 0.375rem;
            width: 80px;
        }
        .duration-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--teal), var(--green));
            border-radius: 2px;
        }

        /* EMPTY STATE */
        .empty-state {
            padding: 4rem 2rem;
            text-align: center;
        }
        .empty-icon {
            width: 64px; height: 64px;
            background: var(--bg-input);
            border: 1px solid var(--border);
            border-radius: 18px;
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 1.25rem;
            color: var(--text-muted);
        }
        .empty-state h3 { font-size: 1rem; font-weight: 700; color: var(--text-primary); }
        .empty-state p  { font-size: 0.875rem; color: var(--text-secondary); margin-top: 0.375rem; }

        /* MOBILE OVERLAY */
        .sidebar-overlay {
            display: none;
            position: fixed; inset: 0;
            background: rgba(0,0,0,0.6);
            z-index: 199;
        }

        /* ── ENTRANCE ANIMS ── */
        .fade-up { opacity: 0; transform: translateY(16px); animation: fadeUp 0.5s ease forwards; }
        @keyframes fadeUp { to { opacity: 1; transform: translateY(0); } }
        .d1 { animation-delay: 0.04s; }
        .d2 { animation-delay: 0.12s; }
        .d3 { animation-delay: 0.2s; }

        /* ── RESPONSIVE ── */
        @media (max-width: 768px) {
            #sidebar-wrapper { transform: translateX(calc(-1 * var(--sidebar-w))); }
            #sidebar-wrapper.open { transform: translateX(0); }
            .sidebar-overlay { display: block; opacity: 0; visibility: hidden; transition: all 0.25s; }
            .sidebar-overlay.open { opacity: 1; visibility: visible; }
            #topbar { left: 0; }
            #page-content-wrapper { margin-left: 0; }
            .page-bg { padding: 1.5rem 1rem; }
            .summary-row { grid-template-columns: 1fr 1fr; }
            .table-card-header { flex-direction: column; align-items: flex-start; gap: 0.75rem; }
        }

        @media (max-width: 520px) {
            .summary-row { grid-template-columns: 1fr; }
            .schedule-table thead th:nth-child(3),
            .schedule-table tbody td:nth-child(3) { display: none; } /* hide shift col on tiny screens */
            .legend { display: none; }
        }
    </style>
</head>
<body>

<div class="sidebar-overlay" id="sidebarOverlay"></div>

<div id="wrapper">

    <!-- ── SIDEBAR ── -->
    <div id="sidebar-wrapper">
        <div class="sidebar-brand">
            <div class="brand-mark">
                <div class="brand-icon">
                    <svg viewBox="0 0 24 24"><path d="M12 2C6.477 2 2 6.477 2 12s4.477 10 10 10 10-4.477 10-10S17.523 2 12 2zm0 18a8 8 0 110-16 8 8 0 010 16zm.5-13H11v6l5.25 3.15.75-1.23-4.5-2.67V7z"/></svg>
                </div>
                <span class="brand-name">Si<span>Hadir</span></span>
            </div>
        </div>
        <nav class="sidebar-nav">
            <a class="nav-item-link" href="attendance.php">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg>
                <span class="nav-label">Presensi</span>
            </a>
            <a class="nav-item-link active" href="schedule.php">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                <span class="nav-label">Jadwal</span>
            </a>
            <a class="nav-item-link" href="attendanceHistory.php">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                <span class="nav-label">Riwayat</span>
            </a>
            <a class="nav-item-link" href="permit.php">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                <span class="nav-label">Cuti &amp; Izin</span>
            </a>
        </nav>
        <div class="sidebar-footer">
            <a class="logout-link" href="logout.php">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                <span>Keluar</span>
            </a>
        </div>
    </div>

    <!-- ── MAIN ── -->
    <div id="page-content-wrapper">

        <!-- TOPBAR -->
        <div id="topbar">
            <div class="topbar-left">
                <button id="menuToggle" aria-label="Toggle sidebar">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                        <line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/>
                    </svg>
                </button>
                <span class="topbar-title">Jadwal Kerja</span>
            </div>
            <div class="week-pill">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                <?php echo date('d M Y'); ?>
            </div>
        </div>

        <!-- PAGE -->
        <div class="page-bg">
            <div class="orb orb-1"></div>
            <div class="orb orb-2"></div>

            <div class="inner">

                <!-- HEADING -->
                <div class="page-heading fade-up">
                    <div class="eyebrow">Mingguan</div>
                    <h1>Jadwal Kerja</h1>
                    <p>Rincian shift dan jam kerja Anda untuk setiap hari dalam seminggu.</p>
                </div>

                <?php if ($schedule): ?>

                <?php
                // Calculate summary
                $totalMasuk = 0; $totalLibur = 0;
                $shiftNama = ''; $shiftJam = '';
                foreach ($schedule as $day => $info) {
                    if ($info['status'] === 'Masuk') {
                        $totalMasuk++;
                        if (!$shiftNama) $shiftNama = $info['shift_name'];
                        if (!$shiftJam && $info['jam_masuk'] !== '-') {
                            $shiftJam = $info['jam_masuk'] . ' – ' . $info['jam_keluar'];
                        }
                    } else {
                        $totalLibur++;
                    }
                }
                ?>

                <!-- SUMMARY CHIPS -->
                <div class="summary-row fade-up d1">
                    <div class="summary-chip">
                        <div class="chip-icon teal">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                        </div>
                        <div class="chip-body">
                            <div class="chip-label">Hari Kerja</div>
                            <div class="chip-value"><?= $totalMasuk ?> <span style="font-size:0.875rem;color:var(--text-secondary);font-weight:500">hari</span></div>
                        </div>
                    </div>
                    <div class="summary-chip">
                        <div class="chip-icon red">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8h1a4 4 0 010 8h-1"/><path d="M2 8h16v9a4 4 0 01-4 4H6a4 4 0 01-4-4V8z"/><line x1="6" y1="1" x2="6" y2="4"/><line x1="10" y1="1" x2="10" y2="4"/><line x1="14" y1="1" x2="14" y2="4"/></svg>
                        </div>
                        <div class="chip-body">
                            <div class="chip-label">Hari Libur</div>
                            <div class="chip-value"><?= $totalLibur ?> <span style="font-size:0.875rem;color:var(--text-secondary);font-weight:500">hari</span></div>
                        </div>
                    </div>
                    <div class="summary-chip">
                        <div class="chip-icon green">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                        </div>
                        <div class="chip-body">
                            <div class="chip-label">Jam Shift</div>
                            <div class="chip-value" style="font-size:1rem"><?= $shiftJam ?: '—' ?></div>
                        </div>
                    </div>
                </div>

                <!-- TABLE CARD -->
                <div class="table-card fade-up d2">
                    <div class="table-card-header">
                        <h3>Rincian Per Hari</h3>
                        <div class="header-right">
                            <div class="legend">
                                <div class="legend-item"><div class="legend-dot green"></div>Hari Kerja</div>
                                <div class="legend-item"><div class="legend-dot red"></div>Hari Libur</div>
                            </div>
                        </div>
                    </div>

                    <table class="schedule-table">
                        <thead>
                            <tr>
                                <th>Hari</th>
                                <th>Status</th>
                                <th>Shift</th>
                                <th>Masuk</th>
                                <th>Keluar</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($schedule as $day => $info):
                            $isToday  = ($day === $todayKey);
                            $isOff    = ($info['status'] === 'Libur');
                            $rowClass = $isToday ? 'today-row' : '';
                            $dayLabel = ucfirst($day);

                            // duration bar width (assume 8h shift = 100%)
                            $barWidth = '0%';
                            if (!$isOff && $info['jam_masuk'] !== '-') {
                                $in  = strtotime($info['jam_masuk']);
                                $out = strtotime($info['jam_keluar']);
                                if ($out > $in) {
                                    $hours = ($out - $in) / 3600;
                                    $barWidth = min(100, round($hours / 8 * 100)) . '%';
                                }
                            }
                        ?>
                            <tr class="<?= $rowClass ?>">
                                <!-- Day -->
                                <td>
                                    <div class="day-wrap">
                                        <?php if ($isToday): ?><div class="today-indicator"></div><?php endif; ?>
                                        <span class="day-name"><?= $dayLabel ?></span>
                                        <?php if ($isToday): ?><span class="today-label">Hari ini</span><?php endif; ?>
                                    </div>
                                </td>

                                <!-- Status -->
                                <td>
                                    <span class="status-pill <?= $isOff ? 'libur' : 'masuk' ?>">
                                        <span class="dot"></span>
                                        <?= $info['status'] ?>
                                    </span>
                                </td>

                                <!-- Shift -->
                                <td>
                                    <span class="shift-text <?= $isOff ? 'off' : '' ?>"><?= htmlspecialchars($info['shift_name']) ?></span>
                                </td>

                                <!-- Jam Masuk -->
                                <td>
                                    <?php if (!$isOff && $info['jam_masuk'] !== '-'): ?>
                                        <div class="time-wrap">
                                            <span class="time-text"><?= htmlspecialchars(substr($info['jam_masuk'],0,5)) ?></span>
                                            <span class="time-sub">WIB</span>
                                        </div>
                                    <?php else: ?>
                                        <span class="time-text off">—</span>
                                    <?php endif; ?>
                                </td>

                                <!-- Jam Keluar -->
                                <td>
                                    <?php if (!$isOff && $info['jam_keluar'] !== '-'): ?>
                                        <div class="time-wrap">
                                            <span class="time-text"><?= htmlspecialchars(substr($info['jam_keluar'],0,5)) ?></span>
                                            <div class="duration-bar"><div class="duration-fill" style="width:<?= $barWidth ?>"></div></div>
                                        </div>
                                    <?php else: ?>
                                        <span class="time-text off">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- footnote -->
                <div class="fade-up d3" style="margin-top:1rem;display:flex;align-items:center;gap:0.5rem;font-size:0.75rem;color:var(--text-muted);">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                    Jadwal bersifat tetap setiap minggu. Perubahan jadwal dikonfirmasi oleh admin.
                </div>

                <?php else: ?>
                <!-- EMPTY STATE -->
                <div class="table-card fade-up d1">
                    <div class="empty-state">
                        <div class="empty-icon">
                            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                        </div>
                        <h3>Belum Ada Jadwal</h3>
                        <p>Jadwal kerja Anda belum dikonfigurasi. Silakan hubungi admin.</p>
                    </div>
                </div>
                <?php endif; ?>

            </div><!-- /inner -->
        </div><!-- /page-bg -->
    </div><!-- /page-content-wrapper -->
</div><!-- /wrapper -->

<script>
(function(){
    const sidebar = document.getElementById('sidebar-wrapper');
    const overlay = document.getElementById('sidebarOverlay');
    const menuBtn = document.getElementById('menuToggle');
    const isMobile = () => window.innerWidth <= 768;

    menuBtn.addEventListener('click', () => {
        if(isMobile()) {
            sidebar.classList.toggle('open');
            overlay.classList.toggle('open');
        } else {
            const hidden = sidebar.style.transform === 'translateX(-240px)';
            sidebar.style.transform = hidden ? '' : 'translateX(-240px)';
            document.getElementById('page-content-wrapper').style.marginLeft = hidden ? '' : '0';
            document.getElementById('topbar').style.left = hidden ? '' : '0';
        }
    });

    overlay.addEventListener('click', () => {
        sidebar.classList.remove('open');
        overlay.classList.remove('open');
    });
})();
</script>
</body>
=======
<?php
session_start();

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

require_once '../../../app/auth/auth.php';

function getEmployeeSchedule($pdo, $userId)
{
    try {
        $stmt = $pdo->prepare("SELECT id, hari_libur FROM pegawai WHERE user_id = ?");
        $stmt->execute([$userId]);
        $employee = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$employee) return null;

        $stmt = $pdo->prepare("
            SELECT s.nama_shift, s.jam_masuk, s.jam_keluar 
            FROM jadwal_shift js 
            JOIN shift s ON js.shift_id = s.id 
            WHERE js.pegawai_id = ? AND js.status = 'aktif' 
            LIMIT 1
        ");
        $stmt->execute([$employee['id']]);
        $shift = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$shift) return null;

        $weekDays = ['senin', 'selasa', 'rabu', 'kamis', 'jumat', 'sabtu', 'minggu'];
        $schedule = [];

        foreach ($weekDays as $day) {
            $isOff = ($day === $employee['hari_libur']);
            $schedule[$day] = [
                'status'     => $isOff ? 'Libur' : 'Masuk',
                'shift_name' => $isOff ? '-' : $shift['nama_shift'],
                'jam_masuk'  => $isOff ? '-' : $shift['jam_masuk'],
                'jam_keluar' => $isOff ? '-' : $shift['jam_keluar'],
            ];
        }

        return $schedule;
    } catch (Exception $e) {
        error_log("Schedule error: " . $e->getMessage());
        return null;
    }
}

try {
    $schedule = isset($_SESSION['id']) ? getEmployeeSchedule($pdo, $_SESSION['id']) : null;
} catch (Exception $e) {
    error_log("Error getting schedule: " . $e->getMessage());
    $schedule = null;
}

// Figure out today's day name in Indonesian
date_default_timezone_set('Asia/Jakarta');
$todayMap = [
    'Monday'    => 'senin',
    'Tuesday'   => 'selasa',
    'Wednesday' => 'rabu',
    'Thursday'  => 'kamis',
    'Friday'    => 'jumat',
    'Saturday'  => 'sabtu',
    'Sunday'    => 'minggu',
];
$todayKey = $todayMap[date('l')] ?? '';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Si Hadir — Jadwal</title>
    <link rel="icon" type="image/x-icon" href="../../../assets/favicon.ico" />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css" rel="stylesheet">

    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --bg-base:      #090d1a;
            --bg-surface:   #0f1629;
            --bg-card:      #131c35;
            --bg-input:     #1a2340;
            --border:       #1e2d50;
            --border-glow:  rgba(0,212,255,0.25);
            --teal:         #00d4ff;
            --teal-dim:     rgba(0,212,255,0.10);
            --green:        #00e5a0;
            --green-dim:    rgba(0,229,160,0.10);
            --red:          #ff4d6d;
            --red-dim:      rgba(255,77,109,0.10);
            --amber:        #ffb84d;
            --amber-dim:    rgba(255,184,77,0.10);
            --text-primary:   #f0f4ff;
            --text-secondary: #8892a4;
            --text-muted:     #3d4f6e;
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

        .sidebar-brand { padding: 1.5rem 1.25rem 1rem; border-bottom: 1px solid var(--border); }
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
            display: flex; align-items: center; gap: 0.75rem;
            padding: 0.75rem 1.25rem;
            color: var(--text-secondary);
            text-decoration: none;
            font-size: 0.875rem; font-weight: 500;
            border-left: 2px solid transparent;
            transition: all 0.18s ease;
        }
        .nav-item-link:hover { color: var(--text-primary); background: var(--teal-dim); border-left-color: var(--teal); }
        .nav-item-link.active { color: var(--teal); background: var(--teal-dim); border-left-color: var(--teal); }
        .nav-label { white-space: nowrap; }

        .sidebar-footer { padding: 0.75rem; border-top: 1px solid var(--border); }
        .logout-link {
            display: flex; align-items: center; gap: 0.75rem;
            padding: 0.625rem 0.875rem;
            color: var(--text-secondary); text-decoration: none;
            font-size: 0.875rem; border-radius: 8px;
            transition: all 0.18s ease;
        }
        .logout-link:hover { color: var(--red); background: var(--red-dim); }

        /* ── TOPBAR ── */
        #topbar {
            position: fixed; top: 0;
            left: var(--sidebar-w); right: 0;
            height: 56px;
            background: rgba(9,13,26,0.85);
            backdrop-filter: blur(12px);
            border-bottom: 1px solid var(--border);
            display: flex; align-items: center; justify-content: space-between;
            padding: 0 1.5rem;
            z-index: 100;
            transition: left 0.3s cubic-bezier(0.4,0,0.2,1);
        }
        .topbar-left { display: flex; align-items: center; gap: 0.75rem; }
        #menuToggle {
            width: 36px; height: 36px;
            background: var(--bg-card); border: 1px solid var(--border); border-radius: 8px;
            display: flex; align-items: center; justify-content: center;
            cursor: pointer; color: var(--text-secondary);
            transition: all 0.18s ease;
        }
        #menuToggle:hover { color: var(--teal); border-color: var(--teal); background: var(--teal-dim); }
        .topbar-title { font-size: 0.875rem; font-weight: 600; color: var(--text-secondary); }

        /* week pill */
        .week-pill {
            display: flex; align-items: center; gap: 0.375rem;
            padding: 0.25rem 0.75rem;
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 999px;
            font-size: 0.75rem;
            color: var(--text-secondary);
            font-weight: 500;
        }
        .week-pill svg { color: var(--teal); }

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
            position: absolute; border-radius: 50%;
            filter: blur(80px); pointer-events: none;
            animation: orb-drift 12s ease-in-out infinite alternate;
        }
        .orb-1 { width: 500px; height: 500px; background: radial-gradient(circle, rgba(0,212,255,0.06) 0%, transparent 70%); top: -100px; right: -80px; }
        .orb-2 { width: 350px; height: 350px; background: radial-gradient(circle, rgba(0,229,160,0.04) 0%, transparent 70%); bottom: 0; left: 5%; animation-delay: -6s; }
        @keyframes orb-drift { 0% { transform: translate(0,0) scale(1); } 100% { transform: translate(25px,15px) scale(1.05); } }

        /* ── INNER LAYOUT ── */
        .inner {
            max-width: 900px;
            margin: 0 auto;
            position: relative;
            z-index: 1;
        }

        /* ── PAGE HEADING ── */
        .page-heading { margin-bottom: 2rem; }
        .page-heading .eyebrow { font-size: 0.75rem; font-weight: 600; letter-spacing: 2px; text-transform: uppercase; color: var(--teal); margin-bottom: 0.375rem; }
        .page-heading h1 { font-size: 1.75rem; font-weight: 800; letter-spacing: -0.75px; color: var(--text-primary); }
        .page-heading p { font-size: 0.875rem; color: var(--text-secondary); margin-top: 0.25rem; }

        /* ── SUMMARY ROW ── */
        .summary-row {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1rem;
            margin-bottom: 1.75rem;
        }

        .summary-chip {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 1rem 1.25rem;
            display: flex;
            align-items: center;
            gap: 0.875rem;
            transition: border-color 0.2s;
        }
        .summary-chip:hover { border-color: var(--border-glow); }

        .chip-icon {
            width: 36px; height: 36px;
            border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0;
        }
        .chip-icon.teal   { background: var(--teal-dim);  color: var(--teal); }
        .chip-icon.green  { background: var(--green-dim); color: var(--green); }
        .chip-icon.red    { background: var(--red-dim);   color: var(--red); }

        .chip-body {}
        .chip-label { font-size: 0.6875rem; text-transform: uppercase; letter-spacing: 1px; color: var(--text-muted); font-weight: 600; }
        .chip-value { font-size: 1.375rem; font-weight: 800; color: var(--text-primary); line-height: 1.2; }

        /* ── SCHEDULE TABLE CARD ── */
        .table-card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 20px;
            overflow: hidden;
        }

        .table-card-header {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .table-card-header h3 {
            font-size: 0.9375rem;
            font-weight: 700;
            color: var(--text-primary);
        }

        .header-right {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.75rem;
            color: var(--text-muted);
        }

        /* legend */
        .legend { display: flex; gap: 1rem; align-items: center; }
        .legend-item { display: flex; align-items: center; gap: 0.375rem; font-size: 0.75rem; color: var(--text-secondary); }
        .legend-dot { width: 8px; height: 8px; border-radius: 50%; }
        .legend-dot.green { background: var(--green); }
        .legend-dot.red   { background: var(--red); }

        /* ── TABLE ── */
        .schedule-table {
            width: 100%;
            border-collapse: collapse;
        }

        .schedule-table thead th {
            padding: 0.75rem 1.25rem;
            font-size: 0.6875rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            color: var(--text-muted);
            text-align: left;
            background: var(--bg-surface);
            border-bottom: 1px solid var(--border);
        }

        .schedule-table thead th:first-child { padding-left: 1.5rem; }
        .schedule-table thead th:last-child  { padding-right: 1.5rem; }

        .schedule-table tbody tr {
            border-bottom: 1px solid rgba(30,45,80,0.5);
            transition: background 0.15s ease;
        }
        .schedule-table tbody tr:last-child { border-bottom: none; }
        .schedule-table tbody tr:hover { background: rgba(0,212,255,0.03); }
        .schedule-table tbody tr.today-row { background: rgba(0,212,255,0.05); }
        .schedule-table tbody tr.today-row:hover { background: rgba(0,212,255,0.08); }

        .schedule-table tbody td {
            padding: 1rem 1.25rem;
            vertical-align: middle;
        }
        .schedule-table tbody td:first-child { padding-left: 1.5rem; }
        .schedule-table tbody td:last-child  { padding-right: 1.5rem; }

        /* Day cell */
        .day-wrap { display: flex; align-items: center; gap: 0.625rem; }

        .today-indicator {
            width: 6px; height: 6px;
            background: var(--teal);
            border-radius: 50%;
            box-shadow: 0 0 6px var(--teal);
            animation: pulse-teal 2s infinite;
        }

        @keyframes pulse-teal {
            0%   { box-shadow: 0 0 0 0 rgba(0,212,255,0.5); }
            70%  { box-shadow: 0 0 0 6px rgba(0,212,255,0); }
            100% { box-shadow: 0 0 0 0 rgba(0,212,255,0); }
        }

        .day-name {
            font-size: 0.9375rem;
            font-weight: 700;
            color: var(--text-primary);
        }

        .today-label {
            font-size: 0.6875rem;
            font-weight: 600;
            color: var(--teal);
            background: var(--teal-dim);
            border: 1px solid rgba(0,212,255,0.2);
            border-radius: 999px;
            padding: 0.1rem 0.5rem;
            letter-spacing: 0.5px;
        }

        /* Status badge */
        .status-pill {
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
            padding: 0.3125rem 0.75rem;
            border-radius: 999px;
            font-size: 0.75rem;
            font-weight: 700;
            letter-spacing: 0.25px;
        }
        .status-pill.masuk  { background: var(--green-dim); color: var(--green); border: 1px solid rgba(0,229,160,0.2); }
        .status-pill.libur  { background: var(--red-dim);   color: var(--red);   border: 1px solid rgba(255,77,109,0.2); }
        .status-pill .dot   { width: 5px; height: 5px; border-radius: 50%; background: currentColor; }

        /* Shift name */
        .shift-text { font-size: 0.875rem; font-weight: 600; color: var(--teal); }
        .shift-text.off { color: var(--text-muted); font-weight: 400; }

        /* Time cells */
        .time-text {
            font-size: 0.9375rem;
            font-weight: 700;
            color: var(--text-primary);
            font-variant-numeric: tabular-nums;
            letter-spacing: 0.5px;
        }
        .time-text.off { color: var(--text-muted); font-weight: 400; font-size: 0.875rem; }

        /* Time with label */
        .time-wrap { display: flex; flex-direction: column; gap: 0.125rem; }
        .time-sub  { font-size: 0.6875rem; color: var(--text-muted); }

        /* Duration bar */
        .duration-bar {
            height: 4px;
            background: var(--border);
            border-radius: 2px;
            overflow: hidden;
            margin-top: 0.375rem;
            width: 80px;
        }
        .duration-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--teal), var(--green));
            border-radius: 2px;
        }

        /* EMPTY STATE */
        .empty-state {
            padding: 4rem 2rem;
            text-align: center;
        }
        .empty-icon {
            width: 64px; height: 64px;
            background: var(--bg-input);
            border: 1px solid var(--border);
            border-radius: 18px;
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 1.25rem;
            color: var(--text-muted);
        }
        .empty-state h3 { font-size: 1rem; font-weight: 700; color: var(--text-primary); }
        .empty-state p  { font-size: 0.875rem; color: var(--text-secondary); margin-top: 0.375rem; }

        /* MOBILE OVERLAY */
        .sidebar-overlay {
            display: none;
            position: fixed; inset: 0;
            background: rgba(0,0,0,0.6);
            z-index: 199;
        }

        /* ── ENTRANCE ANIMS ── */
        .fade-up { opacity: 0; transform: translateY(16px); animation: fadeUp 0.5s ease forwards; }
        @keyframes fadeUp { to { opacity: 1; transform: translateY(0); } }
        .d1 { animation-delay: 0.04s; }
        .d2 { animation-delay: 0.12s; }
        .d3 { animation-delay: 0.2s; }

        /* ── RESPONSIVE ── */
        @media (max-width: 768px) {
            #sidebar-wrapper { transform: translateX(calc(-1 * var(--sidebar-w))); }
            #sidebar-wrapper.open { transform: translateX(0); }
            .sidebar-overlay { display: block; opacity: 0; visibility: hidden; transition: all 0.25s; }
            .sidebar-overlay.open { opacity: 1; visibility: visible; }
            #topbar { left: 0; }
            #page-content-wrapper { margin-left: 0; }
            .page-bg { padding: 1.5rem 1rem; }
            .summary-row { grid-template-columns: 1fr 1fr; }
            .table-card-header { flex-direction: column; align-items: flex-start; gap: 0.75rem; }
        }

        @media (max-width: 520px) {
            .summary-row { grid-template-columns: 1fr; }
            .schedule-table thead th:nth-child(3),
            .schedule-table tbody td:nth-child(3) { display: none; } /* hide shift col on tiny screens */
            .legend { display: none; }
        }
    </style>
</head>
<body>

<div class="sidebar-overlay" id="sidebarOverlay"></div>

<div id="wrapper">

    <!-- ── SIDEBAR ── -->
    <div id="sidebar-wrapper">
        <div class="sidebar-brand">
            <div class="brand-mark">
                <div class="brand-icon">
                    <svg viewBox="0 0 24 24"><path d="M12 2C6.477 2 2 6.477 2 12s4.477 10 10 10 10-4.477 10-10S17.523 2 12 2zm0 18a8 8 0 110-16 8 8 0 010 16zm.5-13H11v6l5.25 3.15.75-1.23-4.5-2.67V7z"/></svg>
                </div>
                <span class="brand-name">Si<span>Hadir</span></span>
            </div>
        </div>
        <nav class="sidebar-nav">
            <a class="nav-item-link" href="attendance.php">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg>
                <span class="nav-label">Presensi</span>
            </a>
            <a class="nav-item-link active" href="schedule.php">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                <span class="nav-label">Jadwal</span>
            </a>
            <a class="nav-item-link" href="attendanceHistory.php">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                <span class="nav-label">Riwayat</span>
            </a>
            <a class="nav-item-link" href="permit.php">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                <span class="nav-label">Cuti &amp; Izin</span>
            </a>
        </nav>
        <div class="sidebar-footer">
            <a class="logout-link" href="logout.php">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                <span>Keluar</span>
            </a>
        </div>
    </div>

    <!-- ── MAIN ── -->
    <div id="page-content-wrapper">

        <!-- TOPBAR -->
        <div id="topbar">
            <div class="topbar-left">
                <button id="menuToggle" aria-label="Toggle sidebar">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                        <line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/>
                    </svg>
                </button>
                <span class="topbar-title">Jadwal Kerja</span>
            </div>
            <div class="week-pill">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                <?php echo date('d M Y'); ?>
            </div>
        </div>

        <!-- PAGE -->
        <div class="page-bg">
            <div class="orb orb-1"></div>
            <div class="orb orb-2"></div>

            <div class="inner">

                <!-- HEADING -->
                <div class="page-heading fade-up">
                    <div class="eyebrow">Mingguan</div>
                    <h1>Jadwal Kerja</h1>
                    <p>Rincian shift dan jam kerja Anda untuk setiap hari dalam seminggu.</p>
                </div>

                <?php if ($schedule): ?>

                <?php
                // Calculate summary
                $totalMasuk = 0; $totalLibur = 0;
                $shiftNama = ''; $shiftJam = '';
                foreach ($schedule as $day => $info) {
                    if ($info['status'] === 'Masuk') {
                        $totalMasuk++;
                        if (!$shiftNama) $shiftNama = $info['shift_name'];
                        if (!$shiftJam && $info['jam_masuk'] !== '-') {
                            $shiftJam = $info['jam_masuk'] . ' – ' . $info['jam_keluar'];
                        }
                    } else {
                        $totalLibur++;
                    }
                }
                ?>

                <!-- SUMMARY CHIPS -->
                <div class="summary-row fade-up d1">
                    <div class="summary-chip">
                        <div class="chip-icon teal">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                        </div>
                        <div class="chip-body">
                            <div class="chip-label">Hari Kerja</div>
                            <div class="chip-value"><?= $totalMasuk ?> <span style="font-size:0.875rem;color:var(--text-secondary);font-weight:500">hari</span></div>
                        </div>
                    </div>
                    <div class="summary-chip">
                        <div class="chip-icon red">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8h1a4 4 0 010 8h-1"/><path d="M2 8h16v9a4 4 0 01-4 4H6a4 4 0 01-4-4V8z"/><line x1="6" y1="1" x2="6" y2="4"/><line x1="10" y1="1" x2="10" y2="4"/><line x1="14" y1="1" x2="14" y2="4"/></svg>
                        </div>
                        <div class="chip-body">
                            <div class="chip-label">Hari Libur</div>
                            <div class="chip-value"><?= $totalLibur ?> <span style="font-size:0.875rem;color:var(--text-secondary);font-weight:500">hari</span></div>
                        </div>
                    </div>
                    <div class="summary-chip">
                        <div class="chip-icon green">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                        </div>
                        <div class="chip-body">
                            <div class="chip-label">Jam Shift</div>
                            <div class="chip-value" style="font-size:1rem"><?= $shiftJam ?: '—' ?></div>
                        </div>
                    </div>
                </div>

                <!-- TABLE CARD -->
                <div class="table-card fade-up d2">
                    <div class="table-card-header">
                        <h3>Rincian Per Hari</h3>
                        <div class="header-right">
                            <div class="legend">
                                <div class="legend-item"><div class="legend-dot green"></div>Hari Kerja</div>
                                <div class="legend-item"><div class="legend-dot red"></div>Hari Libur</div>
                            </div>
                        </div>
                    </div>

                    <table class="schedule-table">
                        <thead>
                            <tr>
                                <th>Hari</th>
                                <th>Status</th>
                                <th>Shift</th>
                                <th>Masuk</th>
                                <th>Keluar</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($schedule as $day => $info):
                            $isToday  = ($day === $todayKey);
                            $isOff    = ($info['status'] === 'Libur');
                            $rowClass = $isToday ? 'today-row' : '';
                            $dayLabel = ucfirst($day);

                            // duration bar width (assume 8h shift = 100%)
                            $barWidth = '0%';
                            if (!$isOff && $info['jam_masuk'] !== '-') {
                                $in  = strtotime($info['jam_masuk']);
                                $out = strtotime($info['jam_keluar']);
                                if ($out > $in) {
                                    $hours = ($out - $in) / 3600;
                                    $barWidth = min(100, round($hours / 8 * 100)) . '%';
                                }
                            }
                        ?>
                            <tr class="<?= $rowClass ?>">
                                <!-- Day -->
                                <td>
                                    <div class="day-wrap">
                                        <?php if ($isToday): ?><div class="today-indicator"></div><?php endif; ?>
                                        <span class="day-name"><?= $dayLabel ?></span>
                                        <?php if ($isToday): ?><span class="today-label">Hari ini</span><?php endif; ?>
                                    </div>
                                </td>

                                <!-- Status -->
                                <td>
                                    <span class="status-pill <?= $isOff ? 'libur' : 'masuk' ?>">
                                        <span class="dot"></span>
                                        <?= $info['status'] ?>
                                    </span>
                                </td>

                                <!-- Shift -->
                                <td>
                                    <span class="shift-text <?= $isOff ? 'off' : '' ?>"><?= htmlspecialchars($info['shift_name']) ?></span>
                                </td>

                                <!-- Jam Masuk -->
                                <td>
                                    <?php if (!$isOff && $info['jam_masuk'] !== '-'): ?>
                                        <div class="time-wrap">
                                            <span class="time-text"><?= htmlspecialchars(substr($info['jam_masuk'],0,5)) ?></span>
                                            <span class="time-sub">WIB</span>
                                        </div>
                                    <?php else: ?>
                                        <span class="time-text off">—</span>
                                    <?php endif; ?>
                                </td>

                                <!-- Jam Keluar -->
                                <td>
                                    <?php if (!$isOff && $info['jam_keluar'] !== '-'): ?>
                                        <div class="time-wrap">
                                            <span class="time-text"><?= htmlspecialchars(substr($info['jam_keluar'],0,5)) ?></span>
                                            <div class="duration-bar"><div class="duration-fill" style="width:<?= $barWidth ?>"></div></div>
                                        </div>
                                    <?php else: ?>
                                        <span class="time-text off">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- footnote -->
                <div class="fade-up d3" style="margin-top:1rem;display:flex;align-items:center;gap:0.5rem;font-size:0.75rem;color:var(--text-muted);">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                    Jadwal bersifat tetap setiap minggu. Perubahan jadwal dikonfirmasi oleh admin.
                </div>

                <?php else: ?>
                <!-- EMPTY STATE -->
                <div class="table-card fade-up d1">
                    <div class="empty-state">
                        <div class="empty-icon">
                            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                        </div>
                        <h3>Belum Ada Jadwal</h3>
                        <p>Jadwal kerja Anda belum dikonfigurasi. Silakan hubungi admin.</p>
                    </div>
                </div>
                <?php endif; ?>

            </div><!-- /inner -->
        </div><!-- /page-bg -->
    </div><!-- /page-content-wrapper -->
</div><!-- /wrapper -->

<script>
(function(){
    const sidebar = document.getElementById('sidebar-wrapper');
    const overlay = document.getElementById('sidebarOverlay');
    const menuBtn = document.getElementById('menuToggle');
    const isMobile = () => window.innerWidth <= 768;

    menuBtn.addEventListener('click', () => {
        if(isMobile()) {
            sidebar.classList.toggle('open');
            overlay.classList.toggle('open');
        } else {
            const hidden = sidebar.style.transform === 'translateX(-240px)';
            sidebar.style.transform = hidden ? '' : 'translateX(-240px)';
            document.getElementById('page-content-wrapper').style.marginLeft = hidden ? '' : '0';
            document.getElementById('topbar').style.left = hidden ? '' : '0';
        }
    });

    overlay.addEventListener('click', () => {
        sidebar.classList.remove('open');
        overlay.classList.remove('open');
    });
})();
</script>
</body>
>>>>>>> 85f0a544401770b8d40292bda6237083bebe2c83
</html>