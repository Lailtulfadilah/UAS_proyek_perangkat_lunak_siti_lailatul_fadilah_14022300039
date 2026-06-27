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

date_default_timezone_set('Asia/Jakarta');

function checkHolidayStatus($pdo, $employeeId, $date)
{
    $query = "SELECT status_kehadiran FROM absensi WHERE pegawai_id = ? AND DATE(tanggal) = ? AND status_kehadiran = 'libur'";
    $stmt = $pdo->prepare($query);
    $stmt->execute([$employeeId, $date]);
    return $stmt->fetch(PDO::FETCH_ASSOC) !== false;
}

function checkEmployeeRole($pdo, $userId)
{
    $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    return $user && $user['role'] === 'karyawan';
}

function verifyUniqueCode($pdo, $uniqueCode)
{
    $query = "SELECT * FROM qr_code WHERE kode_unik = ?";
    $stmt = $pdo->prepare($query);
    $stmt->execute([$uniqueCode]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function getActiveShiftSchedule($pdo, $employeeId, $date)
{
    $query = "SELECT js.id as jadwal_shift_id, js.status as jadwal_status, s.id as shift_id, s.nama_shift, s.jam_masuk, s.jam_keluar 
              FROM jadwal_shift js JOIN shift s ON js.shift_id = s.id 
              WHERE js.pegawai_id = ? AND js.tanggal = ? AND js.status = 'aktif'";
    $stmt = $pdo->prepare($query);
    $stmt->execute([$employeeId, $date]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function getOrCreateAttendanceRecord($pdo, $employeeId, $date, $shiftId)
{
    $checkQuery = "SELECT id, status_kehadiran FROM absensi WHERE pegawai_id = ? AND DATE(tanggal) = ? AND status_kehadiran IN ('cuti', 'izin', 'libur')";
    $checkStmt = $pdo->prepare($checkQuery);
    $checkStmt->execute([$employeeId, $date]);
    $existingLeave = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if ($existingLeave) {
        return ['status' => 'unavailable', 'message' => 'Anda tidak dapat melakukan presensi karena status ' . $existingLeave['status_kehadiran']];
    }

    $query = "SELECT id, waktu_masuk, waktu_keluar, status_kehadiran, jadwal_shift_id, keterangan, kode_unik FROM absensi WHERE pegawai_id = ? AND DATE(tanggal) = ?";
    $stmt = $pdo->prepare($query);
    $stmt->execute([$employeeId, $date]);
    $record = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$record) {
        $query = "INSERT INTO absensi (pegawai_id, tanggal, waktu_masuk, waktu_keluar, status_kehadiran, jadwal_shift_id, kode_unik) VALUES (?, ?, '00:00:00', '00:00:00', '', ?, '000000')";
        $stmt = $pdo->prepare($query);
        $stmt->execute([$employeeId, $date, $shiftId]);

        $stmt = $pdo->prepare("SELECT id, waktu_masuk, waktu_keluar, status_kehadiran, jadwal_shift_id, keterangan, kode_unik FROM absensi WHERE pegawai_id = ? AND DATE(tanggal) = ?");
        $stmt->execute([$employeeId, $date]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    return ['status' => 'success', 'data' => $record];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $uniqueCode = $_POST['unique_code'] ?? null;
        $confirmEarlyLeave = isset($_POST['confirm_early_leave']) && $_POST['confirm_early_leave'] === 'true';
        $attendanceId = $_POST['attendance_id'] ?? null;
        $userId = $_SESSION['id'];
        $currentDate = date('Y-m-d');
        $currentTime = new DateTime();

        $stmt = $pdo->prepare("SELECT id, status_aktif FROM pegawai WHERE user_id = ?");
        $stmt->execute([$userId]);
        $employee = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$employee) throw new Exception('Data pegawai tidak ditemukan.');
        if ($employee['status_aktif'] !== 'aktif') throw new Exception('Status pegawai tidak aktif.');
        if (checkHolidayStatus($pdo, $employee['id'], $currentDate)) throw new Exception('Anda tidak dapat melakukan presensi pada hari libur.');

        $pdo->beginTransaction();

        if (!$uniqueCode) throw new Exception('Kode unik harus diisi.');

        $validCode = verifyUniqueCode($pdo, $uniqueCode);
        if (!$validCode) throw new Exception('Kode unik tidak valid atau sudah tidak aktif.');
        if (!checkEmployeeRole($pdo, $userId)) throw new Exception('Akses ditolak. Hanya karyawan yang dapat melakukan presensi.');

        $employeeId = $employee['id'];
        $shiftSchedule = getActiveShiftSchedule($pdo, $employeeId, $currentDate);
        if (!$shiftSchedule) throw new Exception('Tidak ada shift untuk hari ini.');

        $attendance = getOrCreateAttendanceRecord($pdo, $employeeId, $currentDate, $shiftSchedule['jadwal_shift_id']);
        if ($attendance['status'] === 'unavailable') throw new Exception($attendance['message']);

        $attendance = $attendance['data'];

        if ($attendance['waktu_masuk'] != '00:00:00' && $attendance['waktu_keluar'] != '00:00:00') {
            throw new Exception('Anda sudah melakukan presensi masuk dan keluar untuk hari ini.');
        }

        $shiftStart = new DateTime($currentDate . ' ' . $shiftSchedule['jam_masuk']);
        $shiftEnd = new DateTime($currentDate . ' ' . $shiftSchedule['jam_keluar']);

        if ($uniqueCode) {
            if ($attendance['waktu_masuk'] == '00:00:00') {
                $earliestCheckInTime = (clone $shiftStart)->modify('-45 minutes');
                if ($currentTime < $earliestCheckInTime) throw new Exception('Terlalu awal untuk presensi. Presensi dimulai 45 menit sebelum jadwal shift pada pukul ' . $earliestCheckInTime->format('H:i'));
                if ($currentTime > $shiftEnd) throw new Exception('Anda melewati jam keluar shift dan tidak diperbolehkan presensi.');

                $status = ($currentTime <= $shiftStart) ? 'dalam_shift' : 'terlambat';
                $query = "UPDATE absensi SET waktu_masuk = CURRENT_TIME(), status_kehadiran = ?, kode_unik = ? WHERE id = ?";
                $stmt = $pdo->prepare($query);
                if (!$stmt->execute([$status, $uniqueCode, $attendance['id']])) throw new Exception('Gagal mencatat presensi masuk.');

                $message = ['status' => 'success', 'text' => 'Presensi masuk berhasil dicatat.'];
            } else if ($attendance['waktu_keluar'] == '00:00:00') {
                if ($currentTime < $shiftEnd) {
                    if (!isset($_POST['confirm_early_leave'])) {
                        $message = ['status' => 'confirm', 'text' => 'Anda akan melakukan pulang lebih awal dari jadwal shift. Apakah Anda yakin?', 'attendance_id' => $attendance['id']];
                    } else {
                        $stmt = $pdo->prepare("UPDATE absensi SET waktu_keluar = CURRENT_TIME(), status_kehadiran = 'pulang_dahulu', keterangan = 'Pulang lebih awal dari jadwal', kode_unik = ? WHERE id = ?");
                        if (!$stmt->execute([$uniqueCode, $attendance['id']])) throw new Exception('Gagal mengupdate presensi pulang dahulu.');
                        $message = ['status' => 'success', 'text' => 'Presensi pulang dahulu berhasil dicatat.'];
                    }
                } else {
                    $stmt = $pdo->prepare("UPDATE absensi SET waktu_keluar = CURRENT_TIME(), status_kehadiran = 'hadir', kode_unik = ? WHERE id = ?");
                    if (!$stmt->execute([$uniqueCode, $attendance['id']])) throw new Exception('Gagal mengupdate presensi keluar.');
                    $message = ['status' => 'success', 'text' => 'Presensi keluar berhasil dicatat.'];
                }
            }
        }
        $pdo->commit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $message = ['status' => 'error', 'text' => $e->getMessage()];
    }

    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode($message);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Si Hadir — Presensi</title>
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
            --red:         #ff4d6d;
            --red-dim:     rgba(255, 77, 109, 0.12);
            --text-primary:   #f0f4ff;
            --text-secondary: #8892a4;
            --text-muted:     #4a5568;
            --sidebar-w: 240px;
        }

        html, body { height: 100%; background: var(--bg-base); color: var(--text-primary); font-family: 'Inter', sans-serif; overflow-x: hidden; }

        /* ── SCROLLBAR ── */
        ::-webkit-scrollbar { width: 4px; }
        ::-webkit-scrollbar-track { background: var(--bg-base); }
        ::-webkit-scrollbar-thumb { background: var(--border); border-radius: 4px; }

        /* ── LAYOUT WRAPPER ── */
        #wrapper { display: flex; min-height: 100vh; }

        /* ══════════════════════════════════════
           SIDEBAR
        ══════════════════════════════════════ */
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

        .brand-mark {
            display: flex;
            align-items: center;
            gap: 0.625rem;
        }

        .brand-icon {
            width: 32px; height: 32px;
            background: linear-gradient(135deg, var(--teal), #0090b3);
            border-radius: 8px;
            display: flex; align-items: center; justify-content: center;
        }

        .brand-icon svg { width: 18px; height: 18px; fill: #090d1a; }

        .brand-name {
            font-size: 1.125rem;
            font-weight: 800;
            letter-spacing: -0.5px;
            color: var(--text-primary);
        }

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
            position: relative;
        }

        .nav-item-link:hover {
            color: var(--text-primary);
            background: var(--teal-dim);
            border-left-color: var(--teal);
        }

        .nav-item-link.active {
            color: var(--teal);
            background: var(--teal-dim);
            border-left-color: var(--teal);
        }

        .nav-item-link svg { flex-shrink: 0; }

        .nav-label { white-space: nowrap; }

        .sidebar-footer {
            padding: 0.75rem;
            border-top: 1px solid var(--border);
        }

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

        /* ══════════════════════════════════════
           TOPBAR
        ══════════════════════════════════════ */
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

        .topbar-right { display: flex; align-items: center; gap: 0.75rem; }

        .live-badge {
            display: flex;
            align-items: center;
            gap: 0.375rem;
            padding: 0.25rem 0.625rem;
            background: var(--green-dim);
            border: 1px solid rgba(0,229,160,0.2);
            border-radius: 999px;
            font-size: 0.75rem;
            color: var(--green);
            font-weight: 600;
        }

        .live-dot {
            width: 6px; height: 6px;
            background: var(--green);
            border-radius: 50%;
            animation: pulse-green 2s infinite;
        }

        @keyframes pulse-green {
            0%   { box-shadow: 0 0 0 0 rgba(0,229,160,0.5); }
            70%  { box-shadow: 0 0 0 6px rgba(0,229,160,0); }
            100% { box-shadow: 0 0 0 0 rgba(0,229,160,0); }
        }

        /* ══════════════════════════════════════
           PAGE CONTENT
        ══════════════════════════════════════ */
        #page-content-wrapper {
            margin-left: var(--sidebar-w);
            margin-top: 56px;
            flex: 1;
            min-height: calc(100vh - 56px);
            transition: margin-left 0.3s cubic-bezier(0.4,0,0.2,1);
        }

        /* ── AMBIENT BG ── */
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

        /* ══════════════════════════════════════
           MAIN GRID
        ══════════════════════════════════════ */
        .main-grid {
            display: grid;
            grid-template-columns: 1fr 1.1fr;
            gap: 1.5rem;
            max-width: 1080px;
            margin: 0 auto;
            position: relative;
            z-index: 1;
        }

        /* ── PAGE HEADING ── */
        .page-heading {
            grid-column: 1 / -1;
            margin-bottom: 0.5rem;
        }

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

        /* ══════════════════════════════════════
           CARDS — LEFT COLUMN
        ══════════════════════════════════════ */
        .left-col { display: flex; flex-direction: column; gap: 1.25rem; }

        .info-card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 1.5rem;
            transition: border-color 0.2s;
        }

        .info-card:hover { border-color: var(--border-glow); }

        /* Clock Card */
        .clock-card { position: relative; overflow: hidden; }

        .clock-ring {
            width: 130px; height: 130px;
            border-radius: 50%;
            border: 2px solid var(--border);
            display: flex; align-items: center; justify-content: center;
            position: relative;
            margin: 0 auto 1.25rem;
        }

        .clock-ring::before {
            content: '';
            position: absolute;
            inset: -1px;
            border-radius: 50%;
            border: 2px solid transparent;
            border-top-color: var(--teal);
            animation: spin 4s linear infinite;
        }

        @keyframes spin { to { transform: rotate(360deg); } }

        .clock-time {
            font-size: 2rem;
            font-weight: 800;
            letter-spacing: -1px;
            color: var(--text-primary);
            text-align: center;
            line-height: 1;
        }

        .clock-seconds {
            font-size: 1rem;
            color: var(--teal);
            font-weight: 600;
        }

        .clock-date {
            text-align: center;
            font-size: 0.8125rem;
            color: var(--text-secondary);
            margin-top: 0.5rem;
        }

        .clock-label {
            text-align: center;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            color: var(--text-muted);
            margin-bottom: 1rem;
        }

        /* Shift Card */
        .shift-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
            padding: 0.25rem 0.625rem;
            background: var(--teal-dim);
            border: 1px solid rgba(0,212,255,0.2);
            border-radius: 999px;
            font-size: 0.75rem;
            color: var(--teal);
            font-weight: 600;
            margin-bottom: 1rem;
        }

        .shift-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.625rem 0;
            border-bottom: 1px solid var(--border);
        }

        .shift-row:last-child { border-bottom: none; }

        .shift-row-label {
            font-size: 0.75rem;
            color: var(--text-muted);
            font-weight: 500;
        }

        .shift-row-value {
            font-size: 0.875rem;
            font-weight: 700;
            color: var(--text-primary);
        }

        /* Status card */
        .status-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem; }

        .status-chip {
            background: var(--bg-input);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 0.875rem 1rem;
            display: flex;
            flex-direction: column;
            gap: 0.375rem;
        }

        .status-chip-icon {
            width: 28px; height: 28px;
            border-radius: 8px;
            display: flex; align-items: center; justify-content: center;
            margin-bottom: 0.25rem;
        }

        .chip-in  .status-chip-icon { background: var(--green-dim); }
        .chip-out .status-chip-icon { background: var(--teal-dim); }

        .status-chip-label { font-size: 0.6875rem; color: var(--text-muted); font-weight: 500; text-transform: uppercase; letter-spacing: 1px; }
        .status-chip-value { font-size: 1.125rem; font-weight: 800; color: var(--text-primary); }

        /* ══════════════════════════════════════
           FORM CARD — RIGHT COLUMN
        ══════════════════════════════════════ */
        .form-card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 20px;
            padding: 2rem;
            display: flex;
            flex-direction: column;
            gap: 1.75rem;
            align-self: start;
            position: sticky;
            top: calc(56px + 2.5rem);
        }

        .form-header h2 {
            font-size: 1.25rem;
            font-weight: 800;
            letter-spacing: -0.5px;
            color: var(--text-primary);
        }

        .form-header p {
            font-size: 0.8125rem;
            color: var(--text-secondary);
            margin-top: 0.25rem;
            line-height: 1.5;
        }

        .divider {
            height: 1px;
            background: var(--border);
        }

        /* Input */
        .field-label {
            display: block;
            font-size: 0.75rem;
            font-weight: 600;
            letter-spacing: 0.5px;
            color: var(--text-secondary);
            text-transform: uppercase;
            margin-bottom: 0.625rem;
        }

        .code-input-wrap {
            position: relative;
        }

        .code-input {
            width: 100%;
            background: var(--bg-input);
            border: 1.5px solid var(--border);
            border-radius: 12px;
            padding: 0.875rem 3rem 0.875rem 1.125rem;
            font-size: 1.25rem;
            font-weight: 700;
            letter-spacing: 6px;
            color: var(--text-primary);
            text-transform: uppercase;
            transition: all 0.2s ease;
            outline: none;
        }

        .code-input::placeholder {
            letter-spacing: 3px;
            font-weight: 400;
            color: var(--text-muted);
            font-size: 1rem;
        }

        .code-input:focus {
            border-color: var(--teal);
            background: rgba(0, 212, 255, 0.04);
            box-shadow: 0 0 0 3px rgba(0, 212, 255, 0.1);
        }

        .code-input.is-invalid {
            border-color: var(--red);
            box-shadow: 0 0 0 3px rgba(255,77,109,0.1);
        }

        .input-count {
            position: absolute;
            right: 1rem;
            top: 50%;
            transform: translateY(-50%);
            font-size: 0.75rem;
            color: var(--text-muted);
            font-weight: 600;
            pointer-events: none;
            transition: color 0.2s;
        }

        .input-hint {
            margin-top: 0.5rem;
            font-size: 0.75rem;
            color: var(--text-muted);
            display: flex;
            align-items: center;
            gap: 0.375rem;
        }

        /* Char boxes — visual indicator */
        .char-boxes {
            display: flex;
            gap: 0.375rem;
            margin-top: 0.875rem;
        }

        .char-box {
            flex: 1;
            height: 4px;
            background: var(--border);
            border-radius: 2px;
            transition: background 0.2s ease, transform 0.2s ease;
        }

        .char-box.filled { background: var(--teal); }
        .char-box.complete { background: var(--green); transform: scaleY(1.5); }

        /* Submit button */
        .submit-btn {
            width: 100%;
            padding: 0.9375rem;
            border: none;
            border-radius: 12px;
            background: linear-gradient(135deg, var(--teal) 0%, #0096b3 100%);
            color: #090d1a;
            font-family: 'Inter', sans-serif;
            font-size: 0.9375rem;
            font-weight: 800;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            letter-spacing: 0.25px;
            transition: all 0.2s ease;
            position: relative;
            overflow: hidden;
        }

        .submit-btn::after {
            content: '';
            position: absolute;
            inset: 0;
            background: rgba(255,255,255,0.1);
            opacity: 0;
            transition: opacity 0.2s;
        }

        .submit-btn:hover::after { opacity: 1; }
        .submit-btn:hover { transform: translateY(-2px); box-shadow: 0 8px 24px rgba(0, 212, 255, 0.25); }
        .submit-btn:active { transform: translateY(0); }
        .submit-btn:disabled { opacity: 0.5; cursor: not-allowed; transform: none; box-shadow: none; }

        .submit-btn-loader {
            display: none;
            width: 18px; height: 18px;
            border: 2px solid rgba(0,0,0,0.2);
            border-top-color: #090d1a;
            border-radius: 50%;
            animation: spin 0.6s linear infinite;
        }

        .submit-btn.loading .btn-text { display: none; }
        .submit-btn.loading .submit-btn-loader { display: block; }

        /* ══════════════════════════════════════
           ALERT / TOAST
        ══════════════════════════════════════ */
        .toast-stack {
            position: fixed;
            top: 72px;
            right: 1.25rem;
            z-index: 9999;
            display: flex;
            flex-direction: column;
            gap: 0.625rem;
            pointer-events: none;
        }

        .toast-item {
            background: var(--bg-card);
            border-radius: 12px;
            border: 1px solid var(--border);
            padding: 0.875rem 1rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            min-width: 280px;
            max-width: 340px;
            pointer-events: all;
            animation: toast-in 0.35s cubic-bezier(0.34,1.56,0.64,1) forwards;
            box-shadow: 0 8px 32px rgba(0,0,0,0.4);
        }

        .toast-item.removing { animation: toast-out 0.25s ease forwards; }

        @keyframes toast-in {
            from { opacity: 0; transform: translateX(60px); }
            to   { opacity: 1; transform: translateX(0); }
        }

        @keyframes toast-out {
            from { opacity: 1; transform: translateX(0); }
            to   { opacity: 0; transform: translateX(60px); }
        }

        .toast-icon {
            width: 32px; height: 32px;
            border-radius: 8px;
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0;
        }

        .toast-item.success .toast-icon { background: var(--green-dim); color: var(--green); }
        .toast-item.success { border-left: 3px solid var(--green); }
        .toast-item.error   .toast-icon { background: var(--red-dim);   color: var(--red);   }
        .toast-item.error   { border-left: 3px solid var(--red); }
        .toast-item.warning .toast-icon { background: rgba(255,184,77,0.12); color: var(--amber); }
        .toast-item.warning { border-left: 3px solid var(--amber); }

        .toast-body { flex: 1; }
        .toast-title { font-size: 0.8125rem; font-weight: 700; color: var(--text-primary); }
        .toast-msg   { font-size: 0.75rem; color: var(--text-secondary); margin-top: 0.125rem; }

        .toast-close {
            width: 20px; height: 20px;
            background: none; border: none;
            color: var(--text-muted);
            cursor: pointer;
            display: flex; align-items: center; justify-content: center;
            border-radius: 4px;
            transition: color 0.15s;
            padding: 0;
        }
        .toast-close:hover { color: var(--text-primary); }

        /* ══════════════════════════════════════
           CONFIRM MODAL
        ══════════════════════════════════════ */
        .modal-overlay {
            position: fixed; inset: 0;
            background: rgba(0,0,0,0.7);
            backdrop-filter: blur(4px);
            z-index: 9000;
            display: flex; align-items: center; justify-content: center;
            opacity: 0; visibility: hidden;
            transition: all 0.2s ease;
        }

        .modal-overlay.open { opacity: 1; visibility: visible; }

        .modal-box {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 20px;
            padding: 2rem;
            width: 90%;
            max-width: 400px;
            transform: scale(0.92) translateY(16px);
            transition: transform 0.3s cubic-bezier(0.34,1.56,0.64,1);
        }

        .modal-overlay.open .modal-box { transform: scale(1) translateY(0); }

        .modal-icon-wrap {
            width: 56px; height: 56px;
            background: rgba(255,184,77,0.12);
            border: 1px solid rgba(255,184,77,0.25);
            border-radius: 16px;
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 1.25rem;
            color: var(--amber);
        }

        .modal-box h3 { font-size: 1.125rem; font-weight: 800; text-align: center; color: var(--text-primary); }
        .modal-box p  { font-size: 0.875rem; color: var(--text-secondary); text-align: center; margin-top: 0.5rem; line-height: 1.6; }

        .modal-actions { display: flex; gap: 0.75rem; margin-top: 1.5rem; }

        .btn-cancel {
            flex: 1;
            padding: 0.75rem;
            border-radius: 10px;
            border: 1px solid var(--border);
            background: var(--bg-input);
            color: var(--text-secondary);
            font-family: 'Inter', sans-serif;
            font-size: 0.875rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.18s;
        }

        .btn-cancel:hover { color: var(--text-primary); border-color: var(--text-muted); }

        .btn-confirm-early {
            flex: 1;
            padding: 0.75rem;
            border-radius: 10px;
            border: none;
            background: linear-gradient(135deg, var(--amber), #e69000);
            color: #090d1a;
            font-family: 'Inter', sans-serif;
            font-size: 0.875rem;
            font-weight: 800;
            cursor: pointer;
            transition: all 0.18s;
        }

        .btn-confirm-early:hover { transform: translateY(-1px); box-shadow: 0 4px 16px rgba(255,184,77,0.3); }

        /* ══════════════════════════════════════
           MOBILE OVERLAY
        ══════════════════════════════════════ */
        .sidebar-overlay {
            display: none;
            position: fixed; inset: 0;
            background: rgba(0,0,0,0.6);
            z-index: 199;
        }

        /* ══════════════════════════════════════
           RESPONSIVE
        ══════════════════════════════════════ */
        @media (max-width: 900px) {
            .main-grid { grid-template-columns: 1fr; }
            .form-card { position: static; }
        }

        @media (max-width: 768px) {
            #sidebar-wrapper { transform: translateX(calc(-1 * var(--sidebar-w))); }
            #sidebar-wrapper.open { transform: translateX(0); }
            .sidebar-overlay { display: block; opacity: 0; visibility: hidden; transition: all 0.25s; }
            .sidebar-overlay.open { opacity: 1; visibility: visible; }
            #topbar { left: 0; }
            #page-content-wrapper { margin-left: 0; }
            .page-bg { padding: 1.5rem 1rem; }
            .clock-time { font-size: 1.625rem; }
        }

        @media (max-width: 480px) {
            .status-grid { grid-template-columns: 1fr; }
            .form-card { padding: 1.5rem; }
            .toast-stack { right: 0.75rem; left: 0.75rem; }
            .toast-item { min-width: 0; max-width: 100%; }
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
        .delay-4 { animation-delay: 0.28s; }

        /* card hover glow */
        .info-card, .form-card {
            position: relative;
        }

        .info-card::before, .form-card::before {
            content: '';
            position: absolute;
            inset: -1px;
            border-radius: inherit;
            opacity: 0;
            transition: opacity 0.3s;
            background: linear-gradient(135deg, rgba(0,212,255,0.1), transparent);
            pointer-events: none;
            z-index: 0;
        }

        .info-card:hover::before, .form-card:hover::before { opacity: 1; }

        .info-card > *, .form-card > * { position: relative; z-index: 1; }
    </style>
</head>
<body>

<!-- SIDEBAR OVERLAY (mobile) -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<!-- TOAST STACK -->
<div class="toast-stack" id="toastStack"></div>

<!-- CONFIRM MODAL -->
<div class="modal-overlay" id="confirmModal">
    <div class="modal-box">
        <div class="modal-icon-wrap">
            <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>
            </svg>
        </div>
        <h3>Pulang Lebih Awal?</h3>
        <p>Shift Anda belum selesai. Presensi ini akan dicatat sebagai <strong style="color:var(--amber)">Pulang Dahulu</strong>. Lanjutkan?</p>
        <div class="modal-actions">
            <button class="btn-cancel" id="modalCancel">Batal</button>
            <button class="btn-confirm-early" id="modalConfirm">Ya, Pulang Sekarang</button>
        </div>
    </div>
</div>

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
            <a class="nav-item-link active" href="attendance.php">
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
            <a class="nav-item-link" href="attendanceHistory.php">
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
                <span class="topbar-title">Presensi Harian</span>
            </div>
            <div class="topbar-right">
                <div class="live-badge"><div class="live-dot"></div>Live</div>
            </div>
        </div>

        <!-- PAGE -->
        <div class="page-bg">
            <div class="orb orb-1"></div>
            <div class="orb orb-2"></div>

            <?php if (isset($message) && !empty($_SERVER['HTTP_X_REQUESTED_WITH']) === false): ?>
                <script>
                    window.__initMsg = <?php echo json_encode($message); ?>;
                </script>
            <?php endif; ?>

            <div class="main-grid">

                <!-- PAGE HEADING -->
                <div class="page-heading fade-up">
                    <div class="eyebrow">Dashboard</div>
                    <h1>Presensi Hari Ini</h1>
                    <p>Masukkan kode unik 6 karakter dari admin untuk mencatat kehadiran Anda.</p>
                </div>

                <!-- LEFT COL -->
                <div class="left-col">

                    <!-- CLOCK -->
                    <div class="info-card clock-card fade-up delay-1">
                        <div class="clock-label">Waktu Sistem</div>
                        <div class="clock-ring">
                            <div style="text-align:center">
                                <div class="clock-time" id="clockHM">00:00<span class="clock-seconds" id="clockS">:00</span></div>
                            </div>
                        </div>
                        <div class="clock-date" id="clockDate">—</div>
                    </div>

                    <!-- SHIFT INFO -->
                    <div class="info-card fade-up delay-2">
                        <div class="shift-badge">
                            <svg width="10" height="10" viewBox="0 0 10 10" fill="currentColor"><circle cx="5" cy="5" r="5"/></svg>
                            Shift Aktif
                        </div>
                        <div class="shift-row">
                            <span class="shift-row-label">Nama Shift</span>
                            <span class="shift-row-value" id="shiftName">—</span>
                        </div>
                        <div class="shift-row">
                            <span class="shift-row-label">Jam Masuk</span>
                            <span class="shift-row-value" id="shiftStart">—</span>
                        </div>
                        <div class="shift-row">
                            <span class="shift-row-label">Jam Keluar</span>
                            <span class="shift-row-value" id="shiftEnd">—</span>
                        </div>
                    </div>

                    <!-- STATUS CHIPS -->
                    <div class="info-card fade-up delay-3">
                        <div style="font-size:0.75rem;font-weight:600;text-transform:uppercase;letter-spacing:1.5px;color:var(--text-muted);margin-bottom:0.875rem;">Rekap Hari Ini</div>
                        <div class="status-grid">
                            <div class="status-chip chip-in">
                                <div class="status-chip-icon">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--green)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                        <circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>
                                    </svg>
                                </div>
                                <span class="status-chip-label">Masuk</span>
                                <span class="status-chip-value" id="timeIn">—</span>
                            </div>
                            <div class="status-chip chip-out">
                                <div class="status-chip-icon">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--teal)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                        <circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>
                                    </svg>
                                </div>
                                <span class="status-chip-label">Keluar</span>
                                <span class="status-chip-value" id="timeOut">—</span>
                            </div>
                        </div>
                    </div>

                </div>

                <!-- FORM CARD -->
                <div class="form-card fade-up delay-2">
                    <div class="form-header">
                        <h2>Submit Presensi</h2>
                        <p>Kode unik bersifat sensitif dan berlaku untuk hari ini saja. Dapatkan dari admin atau papan informasi.</p>
                    </div>

                    <div class="divider"></div>

                    <form id="attendanceForm" autocomplete="off">
                        <label for="unique_code" class="field-label">Kode Unik</label>
                        <div class="code-input-wrap">
                            <input
                                type="text"
                                class="code-input"
                                id="unique_code"
                                name="unique_code"
                                placeholder="• • • • • •"
                                maxlength="6"
                                required
                                spellcheck="false"
                            />
                            <span class="input-count" id="charCount">0/6</span>
                        </div>
                        <div class="char-boxes" id="charBoxes">
                            <div class="char-box"></div>
                            <div class="char-box"></div>
                            <div class="char-box"></div>
                            <div class="char-box"></div>
                            <div class="char-box"></div>
                            <div class="char-box"></div>
                        </div>
                        <div class="input-hint">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/>
                            </svg>
                            6 karakter — huruf dan angka
                        </div>

                        <div style="margin-top:1.5rem;">
                            <button type="submit" class="submit-btn" id="submitBtn">
                                <span class="btn-text" style="display:flex;align-items:center;gap:0.5rem;">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                        <polyline points="20 6 9 17 4 12"/>
                                    </svg>
                                    Catat Presensi
                                </span>
                                <div class="submit-btn-loader"></div>
                            </button>
                        </div>
                    </form>

                    <div class="divider"></div>

                    <div style="font-size:0.75rem;color:var(--text-muted);line-height:1.6;display:flex;gap:0.5rem;align-items:flex-start;">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;margin-top:1px">
                            <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                        </svg>
                        Presensi dicatat dengan timestamp server untuk keakuratan data. Hubungi admin jika terdapat kendala.
                    </div>
                </div>

            </div><!-- /main-grid -->
        </div><!-- /page-bg -->
    </div><!-- /page-content-wrapper -->
</div><!-- /wrapper -->

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
            // on desktop, toggle sidebar visibility by shifting content
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

    /* ── CLOCK ── */
    function updateClock() {
        const now = new Date();
        const hh = String(now.getHours()).padStart(2,'0');
        const mm = String(now.getMinutes()).padStart(2,'0');
        const ss = String(now.getSeconds()).padStart(2,'0');
        document.getElementById('clockHM').childNodes[0].textContent = hh + ':' + mm;
        document.getElementById('clockS').textContent = ':' + ss;
        document.getElementById('clockDate').textContent = now.toLocaleDateString('id-ID',{
            weekday:'long', day:'numeric', month:'long', year:'numeric'
        });
    }
    updateClock();
    setInterval(updateClock, 1000);

    /* ── CHAR BOXES ── */
    const codeInput  = document.getElementById('unique_code');
    const charCount  = document.getElementById('charCount');
    const charBoxes  = document.querySelectorAll('.char-box');

    codeInput.addEventListener('input', () => {
        const len = codeInput.value.length;
        charCount.textContent = len + '/6';
        charBoxes.forEach((box, i) => {
            box.className = 'char-box' + (i < len ? (len === 6 ? ' complete' : ' filled') : '');
        });
        if(len === 6) charCount.style.color = 'var(--green)';
        else charCount.style.color = 'var(--text-muted)';
    });

    /* ── TOAST ── */
    const toastStack = document.getElementById('toastStack');

    function showToast(type, title, msg) {
        const icons = {
            success: `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>`,
            error:   `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>`,
            warning: `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>`
        };
        const el = document.createElement('div');
        el.className = `toast-item ${type}`;
        el.innerHTML = `
            <div class="toast-icon">${icons[type]||icons.error}</div>
            <div class="toast-body">
                <div class="toast-title">${title}</div>
                <div class="toast-msg">${msg}</div>
            </div>
            <button class="toast-close" aria-label="Tutup">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>`;
        toastStack.appendChild(el);

        el.querySelector('.toast-close').addEventListener('click', () => removeToast(el));
        setTimeout(() => removeToast(el), 5000);
    }

    function removeToast(el) {
        el.classList.add('removing');
        el.addEventListener('animationend', () => el.remove());
    }

    /* ── MODAL ── */
    const modalOverlay = document.getElementById('confirmModal');
    const modalCancel  = document.getElementById('modalCancel');
    let   pendingConfirmCb = null;

    function openModal(cb) {
        pendingConfirmCb = cb;
        modalOverlay.classList.add('open');
    }

    function closeModal() {
        modalOverlay.classList.remove('open');
        pendingConfirmCb = null;
    }

    modalCancel.addEventListener('click', closeModal);
    modalOverlay.addEventListener('click', e => { if(e.target === modalOverlay) closeModal(); });

    document.getElementById('modalConfirm').addEventListener('click', () => {
        closeModal();
        if(pendingConfirmCb) pendingConfirmCb();
    });

    /* ── FORM SUBMIT ── */
    const form      = document.getElementById('attendanceForm');
    const submitBtn = document.getElementById('submitBtn');

    form.addEventListener('submit', async function(e) {
        e.preventDefault();
        const code = codeInput.value.trim();
        if(code.length !== 6) {
            codeInput.classList.add('is-invalid');
            showToast('error', 'Kode Tidak Valid', 'Kode harus terdiri dari tepat 6 karakter.');
            setTimeout(() => codeInput.classList.remove('is-invalid'), 2000);
            return;
        }

        submitBtn.classList.add('loading');
        submitBtn.disabled = true;

        try {
            const fd = new FormData(form);
            const res = await fetch(window.location.href, {
                method: 'POST',
                body: fd,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            const data = await res.json();

            if(data.status === 'confirm') {
                submitBtn.classList.remove('loading');
                submitBtn.disabled = false;

                openModal(async () => {
                    submitBtn.classList.add('loading');
                    submitBtn.disabled = true;
                    try {
                        const params = new URLSearchParams({
                            confirm_early_leave: 'true',
                            attendance_id: data.attendance_id,
                            unique_code: fd.get('unique_code')
                        });
                        const r2 = await fetch(window.location.href, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded',
                                'X-Requested-With': 'XMLHttpRequest'
                            },
                            body: params.toString()
                        });
                        const d2 = await r2.json();
                        handleResponse(d2);
                    } catch(err) {
                        showToast('error', 'Kesalahan', 'Gagal memproses pulang dahulu.');
                    } finally {
                        submitBtn.classList.remove('loading');
                        submitBtn.disabled = false;
                    }
                });
            } else {
                handleResponse(data);
                submitBtn.classList.remove('loading');
                submitBtn.disabled = false;
            }
        } catch(err) {
            showToast('error', 'Kesalahan Koneksi', 'Tidak dapat terhubung ke server. Coba lagi.');
            submitBtn.classList.remove('loading');
            submitBtn.disabled = false;
        }
    });

    function handleResponse(data) {
        if(data.status === 'success') {
            showToast('success', 'Berhasil!', data.text);
            form.reset();
            charBoxes.forEach(b => b.className = 'char-box');
            charCount.textContent = '0/6';
            charCount.style.color = 'var(--text-muted)';
        } else {
            showToast('error', 'Presensi Gagal', data.text);
        }
    }

    /* ── PHP INIT MSG (non-AJAX page load) ── */
    if(window.__initMsg) {
        const m = window.__initMsg;
        const type = m.status === 'success' ? 'success' : 'error';
        showToast(type, m.status === 'success' ? 'Berhasil!' : 'Perhatian', m.text);
    }

})();
</script>
</body>
</html>