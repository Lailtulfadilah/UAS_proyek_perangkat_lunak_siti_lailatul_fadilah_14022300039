<?php
session_start();
require_once '../../../app/auth/auth.php';

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true || !isset($_SESSION['role']) || $_SESSION['role'] !== 'karyawan') {
    session_unset();
    session_destroy();
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Cache-Control: post-check=0, pre-check=0', false);
    header('Pragma: no-cache');
    header('Location: ../../../login.php');
    exit;
}

if (!isset($_SESSION['id'])) {
    die("Error: User ID tidak ditemukan dalam session.");
}

$user_id = $_SESSION['id'];

try {
    $queryPegawai = "SELECT p.id as pegawai_id FROM pegawai p WHERE p.user_id = :user_id";
    $stmtPegawai = $pdo->prepare($queryPegawai);
    $stmtPegawai->execute(['user_id' => $user_id]);
    $pegawaiData = $stmtPegawai->fetch(PDO::FETCH_ASSOC);

    if (!$pegawaiData) die("Data pegawai tidak ditemukan");

    $pegawai_id = $pegawaiData['pegawai_id'];

    if ($_SERVER['REQUEST_METHOD'] == 'POST') {
        try {
            if ($_POST['form_type'] === 'izin') {
                $tanggal    = $_POST['permitDate'];
                $jenis_izin = $_POST['permitType'];
                $keterangan = $_POST['permitDescription'];

                $stmtCheck = $pdo->prepare("SELECT COUNT(*) as total FROM izin WHERE pegawai_id=:p AND tanggal=:t");
                $stmtCheck->execute(['p' => $pegawai_id, 't' => $tanggal]);
                if ($stmtCheck->fetch(PDO::FETCH_ASSOC)['total'] > 0) {
                    echo json_encode(['status'=>'error','message'=>'Anda sudah mengajukan izin untuk tanggal tersebut!']); exit;
                }

                $stmtCheck2 = $pdo->prepare("SELECT COUNT(*) as total FROM cuti WHERE pegawai_id=:p AND :t BETWEEN tanggal_mulai AND tanggal_selesai");
                $stmtCheck2->execute(['p' => $pegawai_id, 't' => $tanggal]);
                if ($stmtCheck2->fetch(PDO::FETCH_ASSOC)['total'] > 0) {
                    echo json_encode(['status'=>'error','message'=>'Anda sudah memiliki cuti yang mencakup tanggal tersebut!']); exit;
                }

                $stmt = $pdo->prepare("INSERT INTO izin (pegawai_id,tanggal,jenis_izin,keterangan,status,created_at) VALUES (:p,:t,:j,:k,'pending',NOW())");
                $result = $stmt->execute(['p'=>$pegawai_id,'t'=>$tanggal,'j'=>$jenis_izin,'k'=>$keterangan]);
                echo json_encode(['status'=>$result?'success':'error','message'=>$result?'Pengajuan izin berhasil disubmit!':'Gagal menyimpan pengajuan izin']);
            }

            if ($_POST['form_type'] === 'cuti') {
                $tanggal_mulai   = $_POST['leaveStartDate'];
                $tanggal_selesai = $_POST['leaveEndDate'];
                $keterangan      = $_POST['leaveDescription'];

                $s1 = $pdo->prepare("SELECT COUNT(*) as total FROM izin WHERE pegawai_id=:p AND tanggal BETWEEN :a AND :b");
                $s1->execute(['p'=>$pegawai_id,'a'=>$tanggal_mulai,'b'=>$tanggal_selesai]);
                if ($s1->fetch(PDO::FETCH_ASSOC)['total'] > 0) {
                    echo json_encode(['status'=>'error','message'=>'Anda sudah memiliki izin dalam rentang tanggal cuti yang diajukan!']); exit;
                }

                $s2 = $pdo->prepare("SELECT COUNT(*) as total FROM cuti WHERE pegawai_id=:p AND ((tanggal_mulai BETWEEN :a AND :b) OR (tanggal_selesai BETWEEN :a AND :b) OR (:a BETWEEN tanggal_mulai AND tanggal_selesai) OR (:b BETWEEN tanggal_mulai AND tanggal_selesai))");
                $s2->execute(['p'=>$pegawai_id,'a'=>$tanggal_mulai,'b'=>$tanggal_selesai]);
                if ($s2->fetch(PDO::FETCH_ASSOC)['total'] > 0) {
                    echo json_encode(['status'=>'error','message'=>'Terdapat overlap dengan pengajuan cuti yang sudah ada!']); exit;
                }

                $date1 = new DateTime($tanggal_mulai);
                $date2 = new DateTime($tanggal_selesai);
                $durasi = $date1->diff($date2)->days + 1;

                $stmt = $pdo->prepare("INSERT INTO cuti (pegawai_id,tanggal_mulai,tanggal_selesai,durasi_cuti,keterangan,status,created_at) VALUES (:p,:a,:b,:d,:k,'pending',NOW())");
                $result = $stmt->execute(['p'=>$pegawai_id,'a'=>$tanggal_mulai,'b'=>$tanggal_selesai,'d'=>$durasi,'k'=>$keterangan]);
                echo json_encode(['status'=>$result?'success':'error','message'=>$result?'Pengajuan cuti berhasil disubmit!':'Gagal menyimpan pengajuan cuti']);
            }
            exit;
        } catch (PDOException $e) {
            echo json_encode(['status'=>'error','message'=>'Database Error: '.$e->getMessage()]); exit;
        }
    }

    $stmtIzin = $pdo->prepare("SELECT i.*, u.nama_lengkap FROM izin i JOIN pegawai p ON i.pegawai_id=p.id JOIN users u ON p.user_id=u.id WHERE i.pegawai_id=:p ORDER BY i.tanggal DESC");
    $stmtIzin->execute(['p' => $pegawai_id]);
    $dataIzin = $stmtIzin->fetchAll(PDO::FETCH_ASSOC);

    $stmtCuti = $pdo->prepare("SELECT c.*, u.nama_lengkap FROM cuti c JOIN pegawai p ON c.pegawai_id=p.id JOIN users u ON p.user_id=u.id WHERE c.pegawai_id=:p ORDER BY c.tanggal_mulai DESC");
    $stmtCuti->execute(['p' => $pegawai_id]);
    $dataCuti = $stmtCuti->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die("Error: " . $e->getMessage());
}

date_default_timezone_set('Asia/Jakarta');
$today = date('Y-m-d');

// Count stats
$totalIzin   = count($dataIzin);
$totalCuti   = count($dataCuti);
$pendingIzin = count(array_filter($dataIzin, fn($r) => $r['status'] === 'pending'));
$pendingCuti = count(array_filter($dataCuti, fn($r) => $r['status'] === 'pending'));
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1"/>
    <title>Si Hadir — Cuti &amp; Perizinan</title>
    <link rel="icon" type="image/x-icon" href="../../../assets/favicon.ico"/>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css" rel="stylesheet">

    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --bg-base:     #090d1a;
            --bg-surface:  #0f1629;
            --bg-card:     #131c35;
            --bg-input:    #1a2340;
            --bg-input-focus: #1d2848;
            --border:      #1e2d50;
            --border-glow: rgba(0,212,255,0.25);
            --teal:        #00d4ff;
            --teal-dim:    rgba(0,212,255,0.10);
            --teal-mid:    rgba(0,212,255,0.2);
            --green:       #00e5a0;
            --green-dim:   rgba(0,229,160,0.10);
            --red:         #ff4d6d;
            --red-dim:     rgba(255,77,109,0.10);
            --amber:       #ffb84d;
            --amber-dim:   rgba(255,184,77,0.10);
            --purple:      #a78bfa;
            --purple-dim:  rgba(167,139,250,0.10);
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
            width: var(--sidebar-w); min-height: 100vh;
            background: var(--bg-surface); border-right: 1px solid var(--border);
            display: flex; flex-direction: column; flex-shrink: 0;
            position: fixed; top: 0; left: 0; z-index: 200;
            transition: transform 0.3s cubic-bezier(0.4,0,0.2,1);
        }
        .sidebar-brand { padding: 1.5rem 1.25rem 1rem; border-bottom: 1px solid var(--border); }
        .brand-mark { display: flex; align-items: center; gap: 0.625rem; }
        .brand-icon { width:32px;height:32px;background:linear-gradient(135deg,var(--teal),#0090b3);border-radius:8px;display:flex;align-items:center;justify-content:center; }
        .brand-icon svg { width:18px;height:18px;fill:#090d1a; }
        .brand-name { font-size:1.125rem;font-weight:800;letter-spacing:-0.5px;color:var(--text-primary); }
        .brand-name span { color:var(--teal); }
        .sidebar-nav { flex:1;padding:0.75rem 0; }
        .nav-item-link { display:flex;align-items:center;gap:0.75rem;padding:0.75rem 1.25rem;color:var(--text-secondary);text-decoration:none;font-size:0.875rem;font-weight:500;border-left:2px solid transparent;transition:all 0.18s ease; }
        .nav-item-link:hover { color:var(--text-primary);background:var(--teal-dim);border-left-color:var(--teal); }
        .nav-item-link.active { color:var(--teal);background:var(--teal-dim);border-left-color:var(--teal); }
        .sidebar-footer { padding:0.75rem;border-top:1px solid var(--border); }
        .logout-link { display:flex;align-items:center;gap:0.75rem;padding:0.625rem 0.875rem;color:var(--text-secondary);text-decoration:none;font-size:0.875rem;border-radius:8px;transition:all 0.18s ease; }
        .logout-link:hover { color:var(--red);background:var(--red-dim); }

        /* ── TOPBAR ── */
        #topbar {
            position:fixed;top:0;left:var(--sidebar-w);right:0;height:56px;
            background:rgba(9,13,26,0.85);backdrop-filter:blur(12px);
            border-bottom:1px solid var(--border);
            display:flex;align-items:center;justify-content:space-between;
            padding:0 1.5rem;z-index:100;
            transition:left 0.3s cubic-bezier(0.4,0,0.2,1);
        }
        .topbar-left { display:flex;align-items:center;gap:0.75rem; }
        #menuToggle { width:36px;height:36px;background:var(--bg-card);border:1px solid var(--border);border-radius:8px;display:flex;align-items:center;justify-content:center;cursor:pointer;color:var(--text-secondary);transition:all 0.18s ease; }
        #menuToggle:hover { color:var(--teal);border-color:var(--teal);background:var(--teal-dim); }
        .topbar-title { font-size:0.875rem;font-weight:600;color:var(--text-secondary); }
        .topbar-right { display:flex;gap:0.75rem; }

        .action-btn {
            display:inline-flex;align-items:center;gap:0.5rem;
            padding:0.4375rem 0.875rem;
            border-radius:8px;border:none;cursor:pointer;
            font-family:'Inter',sans-serif;font-size:0.8125rem;font-weight:600;
            transition:all 0.18s ease;
        }
        .action-btn.izin { background:var(--teal-dim);color:var(--teal);border:1px solid var(--teal-mid); }
        .action-btn.izin:hover { background:rgba(0,212,255,0.18);box-shadow:0 4px 12px rgba(0,212,255,0.15); }
        .action-btn.cuti { background:var(--purple-dim);color:var(--purple);border:1px solid rgba(167,139,250,0.2); }
        .action-btn.cuti:hover { background:rgba(167,139,250,0.18);box-shadow:0 4px 12px rgba(167,139,250,0.15); }

        /* ── PAGE ── */
        #page-content-wrapper { margin-left:var(--sidebar-w);margin-top:56px;flex:1;min-height:calc(100vh - 56px);transition:margin-left 0.3s cubic-bezier(0.4,0,0.2,1); }
        .page-bg { min-height:calc(100vh - 56px);padding:2.5rem 2rem;position:relative;overflow:hidden; }
        .orb { position:absolute;border-radius:50%;filter:blur(80px);pointer-events:none;animation:orb-drift 12s ease-in-out infinite alternate; }
        .orb-1 { width:500px;height:500px;background:radial-gradient(circle,rgba(0,212,255,0.06) 0%,transparent 70%);top:-100px;right:-80px; }
        .orb-2 { width:350px;height:350px;background:radial-gradient(circle,rgba(167,139,250,0.05) 0%,transparent 70%);bottom:0;left:5%;animation-delay:-6s; }
        @keyframes orb-drift { 0%{transform:translate(0,0) scale(1);}100%{transform:translate(25px,15px) scale(1.05);} }
        .inner { max-width:960px;margin:0 auto;position:relative;z-index:1; }

        /* ── HEADING ── */
        .page-heading { margin-bottom:2rem; }
        .page-heading .eyebrow { font-size:0.75rem;font-weight:600;letter-spacing:2px;text-transform:uppercase;color:var(--teal);margin-bottom:0.375rem; }
        .page-heading h1 { font-size:1.75rem;font-weight:800;letter-spacing:-0.75px;color:var(--text-primary); }
        .page-heading p { font-size:0.875rem;color:var(--text-secondary);margin-top:0.25rem; }

        /* ── STAT CHIPS ── */
        .stat-row { display:grid;grid-template-columns:repeat(4,1fr);gap:1rem;margin-bottom:1.75rem; }
        .stat-chip { background:var(--bg-card);border:1px solid var(--border);border-radius:14px;padding:1rem 1.25rem;display:flex;align-items:center;gap:0.875rem;transition:border-color 0.2s; }
        .stat-chip:hover { border-color:var(--border-glow); }
        .stat-icon { width:36px;height:36px;border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0; }
        .stat-icon.teal   { background:var(--teal-dim);  color:var(--teal);  }
        .stat-icon.purple { background:var(--purple-dim);color:var(--purple);}
        .stat-icon.amber  { background:var(--amber-dim); color:var(--amber); }
        .stat-icon.green  { background:var(--green-dim); color:var(--green); }
        .stat-label { font-size:0.6875rem;text-transform:uppercase;letter-spacing:1px;color:var(--text-muted);font-weight:600; }
        .stat-value { font-size:1.375rem;font-weight:800;color:var(--text-primary);line-height:1.2; }

        /* ── TAB SWITCHER ── */
        .tab-switcher { display:inline-flex;background:var(--bg-card);border:1px solid var(--border);border-radius:10px;padding:4px;margin-bottom:1.25rem; }
        .tab-btn {
            padding:0.5rem 1.25rem;border-radius:7px;border:none;cursor:pointer;
            font-family:'Inter',sans-serif;font-size:0.8125rem;font-weight:600;
            color:var(--text-secondary);background:transparent;
            transition:all 0.2s ease;display:flex;align-items:center;gap:0.5rem;
        }
        .tab-btn.active { background:var(--bg-input);color:var(--text-primary);box-shadow:0 2px 8px rgba(0,0,0,0.3); }
        .tab-badge { font-size:0.625rem;background:var(--border);color:var(--text-muted);border-radius:999px;padding:0.1rem 0.4rem;font-weight:700; }
        .tab-btn.active .tab-badge { background:var(--amber-dim);color:var(--amber); }

        /* ── SEARCH BAR ── */
        .search-wrap { position:relative;width:220px; }
        .search-wrap svg { position:absolute;left:0.75rem;top:50%;transform:translateY(-50%);color:var(--text-muted);pointer-events:none; }
        .search-input { width:100%;background:var(--bg-card);border:1px solid var(--border);border-radius:8px;padding:0.5rem 0.75rem 0.5rem 2.25rem;font-family:'Inter',sans-serif;font-size:0.8125rem;color:var(--text-primary);outline:none;transition:all 0.2s; }
        .search-input::placeholder { color:var(--text-muted); }
        .search-input:focus { border-color:var(--teal);background:var(--bg-input); }

        /* ── TOOLBAR ── */
        .toolbar { display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem;flex-wrap:wrap;gap:0.75rem; }

        /* ── TABLE CARD ── */
        .table-card { background:var(--bg-card);border:1px solid var(--border);border-radius:20px;overflow:hidden; }
        .table-card-header { padding:1.25rem 1.5rem;border-bottom:1px solid var(--border); }
        .table-card-header h3 { font-size:0.9375rem;font-weight:700;color:var(--text-primary); }

        .data-table { width:100%;border-collapse:collapse; }
        .data-table thead th { padding:0.75rem 1.25rem;font-size:0.6875rem;font-weight:600;text-transform:uppercase;letter-spacing:1.5px;color:var(--text-muted);text-align:left;background:var(--bg-surface);border-bottom:1px solid var(--border); }
        .data-table thead th:first-child { padding-left:1.5rem; }
        .data-table thead th:last-child  { padding-right:1.5rem; }
        .data-table tbody tr { border-bottom:1px solid rgba(30,45,80,0.5);transition:background 0.15s; }
        .data-table tbody tr:last-child { border-bottom:none; }
        .data-table tbody tr:hover { background:rgba(0,212,255,0.03); }
        .data-table tbody td { padding:0.9375rem 1.25rem;vertical-align:middle;font-size:0.875rem;color:var(--text-secondary); }
        .data-table tbody td:first-child { padding-left:1.5rem;color:var(--text-primary);font-weight:600; }
        .data-table tbody td:last-child  { padding-right:1.5rem; }

        .status-pill { display:inline-flex;align-items:center;gap:0.375rem;padding:0.3125rem 0.75rem;border-radius:999px;font-size:0.75rem;font-weight:700; }
        .status-pill .dot { width:5px;height:5px;border-radius:50%;background:currentColor; }
        .status-pill.pending   { background:var(--amber-dim);  color:var(--amber);  border:1px solid rgba(255,184,77,0.2); }
        .status-pill.disetujui { background:var(--green-dim);  color:var(--green);  border:1px solid rgba(0,229,160,0.2); }
        .status-pill.ditolak   { background:var(--red-dim);    color:var(--red);    border:1px solid rgba(255,77,109,0.2); }

        .jenis-pill { display:inline-flex;align-items:center;padding:0.1875rem 0.625rem;border-radius:6px;font-size:0.75rem;font-weight:600;background:var(--teal-dim);color:var(--teal);border:1px solid var(--teal-mid); }

        .keterangan-cell { max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap; }

        .empty-row td { text-align:center;padding:3rem 1.5rem; }
        .empty-icon-sm { width:48px;height:48px;background:var(--bg-input);border:1px solid var(--border);border-radius:14px;display:flex;align-items:center;justify-content:center;margin:0 auto 0.875rem;color:var(--text-muted); }
        .empty-row h4 { font-size:0.9375rem;font-weight:700;color:var(--text-primary);margin-bottom:0.25rem; }
        .empty-row p  { font-size:0.8125rem;color:var(--text-secondary); }

        /* ══════════════════════════
           MODAL SISTEM
        ══════════════════════════ */
        .modal-overlay {
            position:fixed;inset:0;
            background:rgba(0,0,0,0.75);
            backdrop-filter:blur(6px);
            z-index:9000;
            display:flex;align-items:center;justify-content:center;
            opacity:0;visibility:hidden;
            transition:opacity 0.25s ease, visibility 0.25s ease;
            padding:1rem;
        }
        .modal-overlay.open { opacity:1;visibility:visible; }

        .modal-box {
            background:var(--bg-card);
            border:1px solid var(--border);
            border-radius:24px;
            width:100%;max-width:480px;
            transform:scale(0.9) translateY(24px);
            transition:transform 0.35s cubic-bezier(0.34,1.56,0.64,1);
            overflow:hidden;
        }
        .modal-overlay.open .modal-box { transform:scale(1) translateY(0); }

        .modal-header-strip {
            height:4px;
            width:100%;
        }
        .modal-header-strip.izin  { background:linear-gradient(90deg, var(--teal),   #0090b3); }
        .modal-header-strip.cuti  { background:linear-gradient(90deg, var(--purple), #7c3aed); }

        .modal-header {
            padding:1.5rem 1.5rem 0;
            display:flex;align-items:flex-start;justify-content:space-between;
        }
        .modal-header-left { display:flex;align-items:center;gap:0.875rem; }
        .modal-icon {
            width:44px;height:44px;border-radius:12px;
            display:flex;align-items:center;justify-content:center;flex-shrink:0;
        }
        .modal-icon.izin  { background:var(--teal-dim);  color:var(--teal);  border:1px solid var(--teal-mid); }
        .modal-icon.cuti  { background:var(--purple-dim);color:var(--purple);border:1px solid rgba(167,139,250,0.2); }
        .modal-title  { font-size:1.0625rem;font-weight:800;color:var(--text-primary);line-height:1.2; }
        .modal-subtitle { font-size:0.75rem;color:var(--text-secondary);margin-top:0.125rem; }

        .modal-close {
            width:32px;height:32px;border-radius:8px;
            background:var(--bg-input);border:1px solid var(--border);
            display:flex;align-items:center;justify-content:center;
            cursor:pointer;color:var(--text-secondary);flex-shrink:0;
            transition:all 0.15s;
        }
        .modal-close:hover { color:var(--text-primary);border-color:var(--text-muted); }

        .modal-body { padding:1.5rem; }

        /* Form Fields */
        .form-row { display:grid;grid-template-columns:1fr 1fr;gap:1rem; }
        .form-group { margin-bottom:1.125rem; }
        .form-group:last-child { margin-bottom:0; }
        .field-label { display:block;font-size:0.75rem;font-weight:600;letter-spacing:0.5px;color:var(--text-secondary);text-transform:uppercase;margin-bottom:0.5rem; }
        .field-input, .field-select, .field-textarea {
            width:100%;
            background:var(--bg-input);
            border:1.5px solid var(--border);
            border-radius:10px;
            padding:0.75rem 0.875rem;
            font-family:'Inter',sans-serif;
            font-size:0.875rem;
            color:var(--text-primary);
            outline:none;
            transition:all 0.2s ease;
        }
        .field-input:focus, .field-select:focus, .field-textarea:focus {
            border-color:var(--teal);
            background:var(--bg-input-focus);
            box-shadow:0 0 0 3px rgba(0,212,255,0.08);
        }
        .field-select option { background:var(--bg-card);color:var(--text-primary); }
        .field-select.unset { color:var(--text-muted); }
        .field-textarea { resize:none;min-height:90px;line-height:1.5; }
        .field-input[type="date"]::-webkit-calendar-picker-indicator { filter:invert(0.6);cursor:pointer; }
        .field-input.invalid, .field-select.invalid, .field-textarea.invalid {
            border-color:var(--red);
            box-shadow:0 0 0 3px rgba(255,77,109,0.08);
        }

        .char-count { font-size:0.6875rem;color:var(--text-muted);text-align:right;margin-top:0.25rem; }

        /* Duration preview */
        .duration-preview {
            background:var(--bg-input);
            border:1px solid var(--border);
            border-radius:8px;
            padding:0.5rem 0.875rem;
            font-size:0.8125rem;
            color:var(--text-secondary);
            margin-top:0.5rem;
            display:none;
        }
        .duration-preview.visible { display:block; }
        .duration-preview strong { color:var(--teal); }

        .modal-footer {
            padding:1.25rem 1.5rem;
            border-top:1px solid var(--border);
            display:flex;justify-content:flex-end;gap:0.75rem;
        }
        .btn-cancel-modal {
            padding:0.625rem 1.25rem;
            border-radius:10px;
            border:1px solid var(--border);
            background:var(--bg-input);
            color:var(--text-secondary);
            font-family:'Inter',sans-serif;
            font-size:0.875rem;font-weight:600;
            cursor:pointer;transition:all 0.18s;
        }
        .btn-cancel-modal:hover { color:var(--text-primary);border-color:var(--text-muted); }

        .btn-submit-modal {
            padding:0.625rem 1.5rem;
            border-radius:10px;border:none;
            font-family:'Inter',sans-serif;
            font-size:0.875rem;font-weight:700;
            cursor:pointer;
            display:flex;align-items:center;gap:0.5rem;
            transition:all 0.2s ease;
            position:relative;overflow:hidden;
        }
        .btn-submit-modal.izin { background:linear-gradient(135deg, var(--teal), #0090b3); color:#090d1a; }
        .btn-submit-modal.cuti { background:linear-gradient(135deg, var(--purple), #7c3aed); color:#fff; }
        .btn-submit-modal:hover { transform:translateY(-1px); }
        .btn-submit-modal.izin:hover { box-shadow:0 4px 16px rgba(0,212,255,0.25); }
        .btn-submit-modal.cuti:hover { box-shadow:0 4px 16px rgba(167,139,250,0.25); }
        .btn-submit-modal:disabled { opacity:0.5;cursor:not-allowed;transform:none;box-shadow:none; }
        .btn-submit-modal .btn-loader { display:none;width:16px;height:16px;border:2px solid rgba(0,0,0,0.2);border-top-color:currentColor;border-radius:50%;animation:spin 0.6s linear infinite; }
        .btn-submit-modal.loading .btn-text { display:none; }
        .btn-submit-modal.loading .btn-loader { display:block; }
        @keyframes spin { to{transform:rotate(360deg);} }

        /* ══════════════════════════
           TOAST STACK
        ══════════════════════════ */
        .toast-stack { position:fixed;top:72px;right:1.25rem;z-index:9999;display:flex;flex-direction:column;gap:0.625rem;pointer-events:none; }
        .toast-item {
            background:var(--bg-card);border:1px solid var(--border);border-radius:14px;
            padding:0.9375rem 1rem;
            display:flex;align-items:flex-start;gap:0.75rem;
            min-width:300px;max-width:360px;
            pointer-events:all;
            animation:toast-in 0.4s cubic-bezier(0.34,1.56,0.64,1) forwards;
            box-shadow:0 12px 40px rgba(0,0,0,0.5);
            position:relative;overflow:hidden;
        }
        .toast-item.removing { animation:toast-out 0.25s ease forwards; }
        .toast-progress {
            position:absolute;bottom:0;left:0;height:3px;
            border-radius:0 0 14px 14px;
            animation:progress-shrink 5s linear forwards;
        }
        .toast-item.success .toast-progress { background:var(--green); }
        .toast-item.error   .toast-progress { background:var(--red);   }
        .toast-item.warning .toast-progress { background:var(--amber); }
        .toast-item.info    .toast-progress { background:var(--teal);  }
        @keyframes progress-shrink { from{width:100%;}to{width:0;} }
        @keyframes toast-in  { from{opacity:0;transform:translateX(60px) scale(0.9);}to{opacity:1;transform:translateX(0) scale(1);} }
        @keyframes toast-out { from{opacity:1;transform:translateX(0);}to{opacity:0;transform:translateX(60px);} }
        .toast-icon { width:34px;height:34px;border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0;margin-top:1px; }
        .toast-item.success { border-left:3px solid var(--green); }
        .toast-item.success .toast-icon { background:var(--green-dim);color:var(--green); }
        .toast-item.error   { border-left:3px solid var(--red); }
        .toast-item.error   .toast-icon { background:var(--red-dim);  color:var(--red);   }
        .toast-item.warning { border-left:3px solid var(--amber); }
        .toast-item.warning .toast-icon { background:var(--amber-dim);color:var(--amber); }
        .toast-item.info    { border-left:3px solid var(--teal); }
        .toast-item.info    .toast-icon { background:var(--teal-dim); color:var(--teal);  }
        .toast-body { flex:1;min-width:0; }
        .toast-title { font-size:0.8125rem;font-weight:700;color:var(--text-primary); }
        .toast-msg   { font-size:0.75rem;color:var(--text-secondary);margin-top:0.1875rem;line-height:1.4; }
        .toast-close { width:22px;height:22px;background:none;border:none;color:var(--text-muted);cursor:pointer;display:flex;align-items:center;justify-content:center;border-radius:4px;transition:color 0.15s;padding:0;flex-shrink:0; }
        .toast-close:hover { color:var(--text-primary); }

        /* ── Sidebar overlay ── */
        .sidebar-overlay { display:none;position:fixed;inset:0;background:rgba(0,0,0,0.6);z-index:199; }

        /* ── ANIMATIONS ── */
        .fade-up { opacity:0;transform:translateY(16px);animation:fadeUp 0.5s ease forwards; }
        @keyframes fadeUp { to{opacity:1;transform:translateY(0);} }
        .d1{animation-delay:0.04s;}.d2{animation-delay:0.12s;}.d3{animation-delay:0.2s;}

        /* ── RESPONSIVE ── */
        @media (max-width:900px) { .stat-row { grid-template-columns:1fr 1fr; } }
        @media (max-width:768px) {
            #sidebar-wrapper { transform:translateX(calc(-1 * var(--sidebar-w))); }
            #sidebar-wrapper.open { transform:translateX(0); }
            .sidebar-overlay { display:block;opacity:0;visibility:hidden;transition:all 0.25s; }
            .sidebar-overlay.open { opacity:1;visibility:visible; }
            #topbar { left:0; }
            #page-content-wrapper { margin-left:0; }
            .page-bg { padding:1.5rem 1rem; }
            .topbar-right { gap:0.5rem; }
            .action-btn span { display:none; }
        }
        @media (max-width:540px) {
            .stat-row { grid-template-columns:1fr 1fr; }
            .form-row { grid-template-columns:1fr; }
            .toolbar { flex-direction:column;align-items:flex-start; }
            .search-wrap { width:100%; }
            .toast-stack { right:0.75rem;left:0.75rem; }
            .toast-item { min-width:0;max-width:100%; }
        }
        @media (max-width:400px) { .stat-row { grid-template-columns:1fr; } }
    </style>
</head>
<body>

<div class="sidebar-overlay" id="sidebarOverlay"></div>
<div class="toast-stack"    id="toastStack"></div>

<!-- ── MODAL IZIN ── -->
<div class="modal-overlay" id="modalIzin">
    <div class="modal-box">
        <div class="modal-header-strip izin"></div>
        <div class="modal-header">
            <div class="modal-header-left">
                <div class="modal-icon izin">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="12" y1="18" x2="12" y2="12"/><line x1="9" y1="15" x2="15" y2="15"/></svg>
                </div>
                <div>
                    <div class="modal-title">Pengajuan Izin</div>
                    <div class="modal-subtitle">Isi detail izin Anda di bawah ini</div>
                </div>
            </div>
            <button class="modal-close" id="closeModalIzin">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
        <div class="modal-body">
            <form id="formIzin" novalidate>
                <input type="hidden" name="form_type" value="izin">
                <div class="form-group">
                    <label class="field-label">Tanggal Izin</label>
                    <input type="date" class="field-input" id="permitDate" name="permitDate" required>
                </div>
                <div class="form-group">
                    <label class="field-label">Jenis Izin</label>
                    <select class="field-select unset" id="permitType" name="permitType" required>
                        <option value="" disabled selected>Pilih jenis izin...</option>
                        <option value="dinas_luar">Dinas Luar</option>
                        <option value="keperluan_pribadi">Keperluan Pribadi</option>
                        <option value="sakit">Sakit</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="field-label">Keterangan</label>
                    <textarea class="field-textarea" id="permitDescription" name="permitDescription" placeholder="Jelaskan alasan izin Anda..." maxlength="300" required></textarea>
                    <div class="char-count"><span id="izinCharCount">0</span>/300</div>
                </div>
            </form>
        </div>
        <div class="modal-footer">
            <button class="btn-cancel-modal" id="cancelModalIzin">Batal</button>
            <button class="btn-submit-modal izin" id="submitIzin">
                <span class="btn-text" style="display:flex;align-items:center;gap:0.375rem;">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                    Submit Izin
                </span>
                <div class="btn-loader"></div>
            </button>
        </div>
    </div>
</div>

<!-- ── MODAL CUTI ── -->
<div class="modal-overlay" id="modalCuti">
    <div class="modal-box">
        <div class="modal-header-strip cuti"></div>
        <div class="modal-header">
            <div class="modal-header-left">
                <div class="modal-icon cuti">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/></svg>
                </div>
                <div>
                    <div class="modal-title">Pengajuan Cuti</div>
                    <div class="modal-subtitle">Tentukan rentang tanggal cuti Anda</div>
                </div>
            </div>
            <button class="modal-close" id="closeModalCuti">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
        <div class="modal-body">
            <form id="formCuti" novalidate>
                <input type="hidden" name="form_type" value="cuti">
                <div class="form-row">
                    <div class="form-group">
                        <label class="field-label">Tanggal Mulai</label>
                        <input type="date" class="field-input" id="leaveStartDate" name="leaveStartDate" required>
                    </div>
                    <div class="form-group">
                        <label class="field-label">Tanggal Selesai</label>
                        <input type="date" class="field-input" id="leaveEndDate" name="leaveEndDate" required>
                    </div>
                </div>
                <div class="duration-preview" id="durationPreview">
                    Durasi cuti: <strong id="durationDays">-</strong>
                </div>
                <div class="form-group" style="margin-top:1rem;">
                    <label class="field-label">Keterangan</label>
                    <textarea class="field-textarea" id="leaveDescription" name="leaveDescription" placeholder="Jelaskan keperluan cuti Anda..." maxlength="300" required></textarea>
                    <div class="char-count"><span id="cutiCharCount">0</span>/300</div>
                </div>
            </form>
        </div>
        <div class="modal-footer">
            <button class="btn-cancel-modal" id="cancelModalCuti">Batal</button>
            <button class="btn-submit-modal cuti" id="submitCuti">
                <span class="btn-text" style="display:flex;align-items:center;gap:0.375rem;">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                    Submit Cuti
                </span>
                <div class="btn-loader"></div>
            </button>
        </div>
    </div>
</div>

<div id="wrapper">
    <!-- ── SIDEBAR ── -->
    <div id="sidebar-wrapper">
        <div class="sidebar-brand">
            <div class="brand-mark">
                <div class="brand-icon"><svg viewBox="0 0 24 24"><path d="M12 2C6.477 2 2 6.477 2 12s4.477 10 10 10 10-4.477 10-10S17.523 2 12 2zm0 18a8 8 0 110-16 8 8 0 010 16zm.5-13H11v6l5.25 3.15.75-1.23-4.5-2.67V7z"/></svg></div>
                <span class="brand-name">Si<span>Hadir</span></span>
            </div>
        </div>
        <nav class="sidebar-nav">
            <a class="nav-item-link" href="attendance.php">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg>
                <span class="nav-label">Presensi</span>
            </a>
            <a class="nav-item-link" href="schedule.php">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                <span class="nav-label">Jadwal</span>
            </a>
            <a class="nav-item-link" href="attendanceHistory.php">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                <span class="nav-label">Riwayat</span>
            </a>
            <a class="nav-item-link active" href="permit.php">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
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
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
                </button>
                <span class="topbar-title">Cuti &amp; Perizinan</span>
            </div>
            <div class="topbar-right">
                <button class="action-btn izin" id="btnOpenIzin">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    <span>Ajukan Izin</span>
                </button>
                <button class="action-btn cuti" id="btnOpenCuti">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    <span>Ajukan Cuti</span>
                </button>
            </div>
        </div>

        <!-- PAGE -->
        <div class="page-bg">
            <div class="orb orb-1"></div>
            <div class="orb orb-2"></div>
            <div class="inner">

                <!-- HEADING -->
                <div class="page-heading fade-up">
                    <div class="eyebrow">Manajemen</div>
                    <h1>Cuti &amp; Perizinan</h1>
                    <p>Ajukan dan pantau status izin serta cuti Anda secara real-time.</p>
                </div>

                <!-- STAT CHIPS -->
                <div class="stat-row fade-up d1">
                    <div class="stat-chip">
                        <div class="stat-icon teal">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/></svg>
                        </div>
                        <div>
                            <div class="stat-label">Total Izin</div>
                            <div class="stat-value"><?= $totalIzin ?></div>
                        </div>
                    </div>
                    <div class="stat-chip">
                        <div class="stat-icon purple">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
                        </div>
                        <div>
                            <div class="stat-label">Total Cuti</div>
                            <div class="stat-value"><?= $totalCuti ?></div>
                        </div>
                    </div>
                    <div class="stat-chip">
                        <div class="stat-icon amber">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                        </div>
                        <div>
                            <div class="stat-label">Menunggu</div>
                            <div class="stat-value"><?= $pendingIzin + $pendingCuti ?></div>
                        </div>
                    </div>
                    <div class="stat-chip">
                        <div class="stat-icon green">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                        </div>
                        <div>
                            <div class="stat-label">Disetujui</div>
                            <div class="stat-value"><?= count(array_filter($dataIzin,fn($r)=>$r['status']==='disetujui')) + count(array_filter($dataCuti,fn($r)=>$r['status']==='disetujui')) ?></div>
                        </div>
                    </div>
                </div>

                <!-- TOOLBAR -->
                <div class="toolbar fade-up d2">
                    <div class="tab-switcher">
                        <button class="tab-btn active" id="tabIzin" onclick="switchTab('izin')">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/></svg>
                            Riwayat Izin
                            <?php if($pendingIzin > 0): ?>
                            <span class="tab-badge"><?= $pendingIzin ?></span>
                            <?php endif; ?>
                        </button>
                        <button class="tab-btn" id="tabCuti" onclick="switchTab('cuti')">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                            Riwayat Cuti
                            <?php if($pendingCuti > 0): ?>
                            <span class="tab-badge"><?= $pendingCuti ?></span>
                            <?php endif; ?>
                        </button>
                    </div>
                    <div class="search-wrap">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                        <input type="text" class="search-input" id="searchInput" placeholder="Cari tanggal atau keterangan...">
                    </div>
                </div>

                <!-- TABLE: IZIN -->
                <div class="table-card fade-up d3" id="tableIzin">
                    <div class="table-card-header">
                        <h3>Riwayat Pengajuan Izin</h3>
                    </div>
                    <div style="overflow-x:auto;">
                        <table class="data-table" id="izinTableEl">
                            <thead>
                                <tr>
                                    <th>Tanggal</th>
                                    <th>Jenis Izin</th>
                                    <th>Keterangan</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if(empty($dataIzin)): ?>
                                <tr class="empty-row"><td colspan="4">
                                    <div class="empty-icon-sm"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/></svg></div>
                                    <h4>Belum Ada Izin</h4>
                                    <p>Klik "Ajukan Izin" untuk membuat pengajuan baru.</p>
                                </td></tr>
                            <?php else: ?>
                                <?php foreach($dataIzin as $row): ?>
                                <tr>
                                    <td><?= htmlspecialchars(date('d M Y', strtotime($row['tanggal']))) ?></td>
                                    <td><span class="jenis-pill"><?= htmlspecialchars(str_replace('_',' ', ucfirst($row['jenis_izin']))) ?></span></td>
                                    <td><span class="keterangan-cell" title="<?= htmlspecialchars($row['keterangan']) ?>"><?= htmlspecialchars(mb_strimwidth($row['keterangan'],0,60,'...')) ?></span></td>
                                    <td>
                                        <?php $s = $row['status']; ?>
                                        <span class="status-pill <?= $s ?>"><span class="dot"></span><?= ucfirst($s) ?></span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- TABLE: CUTI -->
                <div class="table-card fade-up d3" id="tableCuti" style="display:none;">
                    <div class="table-card-header">
                        <h3>Riwayat Pengajuan Cuti</h3>
                    </div>
                    <div style="overflow-x:auto;">
                        <table class="data-table" id="cutiTableEl">
                            <thead>
                                <tr>
                                    <th>Tanggal Mulai</th>
                                    <th>Tanggal Selesai</th>
                                    <th>Durasi</th>
                                    <th>Keterangan</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if(empty($dataCuti)): ?>
                                <tr class="empty-row"><td colspan="5">
                                    <div class="empty-icon-sm"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/></svg></div>
                                    <h4>Belum Ada Cuti</h4>
                                    <p>Klik "Ajukan Cuti" untuk membuat pengajuan baru.</p>
                                </td></tr>
                            <?php else: ?>
                                <?php foreach($dataCuti as $row): ?>
                                <tr>
                                    <td><?= htmlspecialchars(date('d M Y', strtotime($row['tanggal_mulai']))) ?></td>
                                    <td><?= htmlspecialchars(date('d M Y', strtotime($row['tanggal_selesai']))) ?></td>
                                    <td style="font-weight:700;color:var(--teal)"><?= htmlspecialchars($row['durasi_cuti']) ?> hari</td>
                                    <td><span class="keterangan-cell" title="<?= htmlspecialchars($row['keterangan']) ?>"><?= htmlspecialchars(mb_strimwidth($row['keterangan'],0,60,'...')) ?></span></td>
                                    <td>
                                        <?php $s = $row['status']; ?>
                                        <span class="status-pill <?= $s ?>"><span class="dot"></span><?= ucfirst($s) ?></span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div><!-- /inner -->
        </div><!-- /page-bg -->
    </div><!-- /page-content-wrapper -->
</div><!-- /wrapper -->

<script>
(function(){
    /* ── SIDEBAR ── */
    const sidebar = document.getElementById('sidebar-wrapper');
    const overlay = document.getElementById('sidebarOverlay');
    const menuBtn = document.getElementById('menuToggle');
    const isMobile = () => window.innerWidth <= 768;
    menuBtn.addEventListener('click', () => {
        if(isMobile()) { sidebar.classList.toggle('open'); overlay.classList.toggle('open'); }
        else {
            const hidden = sidebar.style.transform === 'translateX(-240px)';
            sidebar.style.transform = hidden ? '' : 'translateX(-240px)';
            document.getElementById('page-content-wrapper').style.marginLeft = hidden ? '' : '0';
            document.getElementById('topbar').style.left = hidden ? '' : '0';
        }
    });
    overlay.addEventListener('click', () => { sidebar.classList.remove('open'); overlay.classList.remove('open'); });

    /* ── TOAST ── */
    const toastStack = document.getElementById('toastStack');
    const ICONS = {
        success: `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>`,
        error:   `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>`,
        warning: `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>`,
        info:    `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>`
    };
    const TITLES = { success:'Berhasil!', error:'Gagal!', warning:'Perhatian', info:'Info' };

    function showToast(type, msg, title) {
        const el = document.createElement('div');
        el.className = `toast-item ${type}`;
        el.innerHTML = `
            <div class="toast-icon">${ICONS[type]||ICONS.info}</div>
            <div class="toast-body">
                <div class="toast-title">${title || TITLES[type] || 'Notifikasi'}</div>
                <div class="toast-msg">${msg}</div>
            </div>
            <button class="toast-close" onclick="removeToast(this.parentElement)">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
            <div class="toast-progress"></div>`;
        toastStack.appendChild(el);
        setTimeout(() => removeToast(el), 5000);
    }

    window.removeToast = function(el) {
        el.classList.add('removing');
        el.addEventListener('animationend', () => el.remove());
    };

    /* ── MODAL helpers ── */
    function openModal(id)  { document.getElementById(id).classList.add('open'); document.body.style.overflow='hidden'; }
    function closeModal(id) { document.getElementById(id).classList.remove('open'); document.body.style.overflow=''; }

    document.getElementById('btnOpenIzin').addEventListener('click',  () => openModal('modalIzin'));
    document.getElementById('closeModalIzin').addEventListener('click', () => closeModal('modalIzin'));
    document.getElementById('cancelModalIzin').addEventListener('click', () => closeModal('modalIzin'));
    document.getElementById('btnOpenCuti').addEventListener('click',  () => openModal('modalCuti'));
    document.getElementById('closeModalCuti').addEventListener('click', () => closeModal('modalCuti'));
    document.getElementById('cancelModalCuti').addEventListener('click', () => closeModal('modalCuti'));

    // Close on backdrop click
    ['modalIzin','modalCuti'].forEach(id => {
        document.getElementById(id).addEventListener('click', e => { if(e.target.id === id) closeModal(id); });
    });

    // ESC key
    document.addEventListener('keydown', e => {
        if(e.key === 'Escape') { closeModal('modalIzin'); closeModal('modalCuti'); }
    });

    /* ── DATE MIN ── */
    const today = new Date().toISOString().split('T')[0];
    document.getElementById('permitDate').min = today;
    document.getElementById('leaveStartDate').min = today;
    document.getElementById('leaveEndDate').min = today;

    /* ── SELECT style fix ── */
    document.getElementById('permitType').addEventListener('change', function(){ this.classList.remove('unset'); });

    /* ── CHAR COUNT ── */
    document.getElementById('permitDescription').addEventListener('input', function(){
        document.getElementById('izinCharCount').textContent = this.value.length;
    });
    document.getElementById('leaveDescription').addEventListener('input', function(){
        document.getElementById('cutiCharCount').textContent = this.value.length;
    });

    /* ── DURATION PREVIEW ── */
    function updateDuration() {
        const start = document.getElementById('leaveStartDate').value;
        const end   = document.getElementById('leaveEndDate').value;
        const preview = document.getElementById('durationPreview');
        if(start && end && end >= start) {
            const d1 = new Date(start), d2 = new Date(end);
            const days = Math.round((d2-d1)/(1000*60*60*24)) + 1;
            document.getElementById('durationDays').textContent = days + ' hari';
            preview.classList.add('visible');
        } else {
            preview.classList.remove('visible');
        }
    }
    document.getElementById('leaveStartDate').addEventListener('change', function(){
        document.getElementById('leaveEndDate').min = this.value;
        if(document.getElementById('leaveEndDate').value < this.value) document.getElementById('leaveEndDate').value='';
        updateDuration();
    });
    document.getElementById('leaveEndDate').addEventListener('change', updateDuration);

    /* ── FORM SUBMIT: IZIN ── */
    document.getElementById('submitIzin').addEventListener('click', async function(){
        const form = document.getElementById('formIzin');
        const date = document.getElementById('permitDate').value;
        const type = document.getElementById('permitType').value;
        const desc = document.getElementById('permitDescription').value.trim();

        // Validate
        let valid = true;
        [['permitDate',date],['permitDescription',desc]].forEach(([id,val])=>{
            const el = document.getElementById(id);
            el.classList.toggle('invalid', !val);
            if(!val) valid = false;
        });
        if(!type){ document.getElementById('permitType').classList.add('invalid'); valid=false; }
        if(!valid){ showToast('warning','Harap lengkapi semua field yang diperlukan.'); return; }

        this.classList.add('loading'); this.disabled = true;

        try {
            const fd = new FormData(form);
            const res = await fetch('permit.php', { method:'POST', body:fd });
            const data = await res.json();

            closeModal('modalIzin');
            showToast(data.status, data.message);

            if(data.status === 'success') {
                form.reset();
                document.getElementById('izinCharCount').textContent = '0';
                setTimeout(() => location.reload(), 1500);
            }
        } catch(err) {
            showToast('error', 'Koneksi gagal. Silakan coba lagi.');
        } finally {
            this.classList.remove('loading'); this.disabled = false;
        }
    });

    /* ── FORM SUBMIT: CUTI ── */
    document.getElementById('submitCuti').addEventListener('click', async function(){
        const form = document.getElementById('formCuti');
        const start = document.getElementById('leaveStartDate').value;
        const end   = document.getElementById('leaveEndDate').value;
        const desc  = document.getElementById('leaveDescription').value.trim();

        let valid = true;
        if(!start){ document.getElementById('leaveStartDate').classList.add('invalid'); valid=false; }
        if(!end)  { document.getElementById('leaveEndDate').classList.add('invalid');   valid=false; }
        if(!desc) { document.getElementById('leaveDescription').classList.add('invalid'); valid=false; }
        if(end && start && end < start){ showToast('warning','Tanggal selesai tidak boleh sebelum tanggal mulai.'); return; }
        if(!valid){ showToast('warning','Harap lengkapi semua field yang diperlukan.'); return; }

        this.classList.add('loading'); this.disabled = true;

        try {
            const fd = new FormData(form);
            const res = await fetch('permit.php', { method:'POST', body:fd });
            const data = await res.json();

            closeModal('modalCuti');
            showToast(data.status, data.message);

            if(data.status === 'success') {
                form.reset();
                document.getElementById('cutiCharCount').textContent = '0';
                document.getElementById('durationPreview').classList.remove('visible');
                setTimeout(() => location.reload(), 1500);
            }
        } catch(err) {
            showToast('error', 'Koneksi gagal. Silakan coba lagi.');
        } finally {
            this.classList.remove('loading'); this.disabled = false;
        }
    });

    // Remove invalid class on input
    document.querySelectorAll('.field-input,.field-select,.field-textarea').forEach(el => {
        el.addEventListener('input', () => el.classList.remove('invalid'));
        el.addEventListener('change', () => el.classList.remove('invalid'));
    });

    /* ── TAB SWITCH ── */
    window.switchTab = function(tab) {
        document.getElementById('tabIzin').classList.toggle('active', tab==='izin');
        document.getElementById('tabCuti').classList.toggle('active', tab==='cuti');
        document.getElementById('tableIzin').style.display = tab==='izin' ? '' : 'none';
        document.getElementById('tableCuti').style.display = tab==='cuti' ? '' : 'none';
        document.getElementById('searchInput').value = '';
        filterRows('');
        window._activeTab = tab;
    };
    window._activeTab = 'izin';

    /* ── SEARCH ── */
    document.getElementById('searchInput').addEventListener('input', function(){
        filterRows(this.value.toLowerCase());
    });

    function filterRows(q) {
        const tableId = window._activeTab === 'izin' ? 'izinTableEl' : 'cutiTableEl';
        document.querySelectorAll(`#${tableId} tbody tr:not(.empty-row)`).forEach(tr => {
            const text = tr.textContent.toLowerCase();
            tr.style.display = text.includes(q) ? '' : 'none';
        });
    }

    /* ── URL params ── */
    const urlParams = new URLSearchParams(window.location.search);
    if(urlParams.get('status') === 'success') showToast('success','Pengajuan berhasil disubmit!');
    else if(urlParams.get('status') === 'error') showToast('error', urlParams.get('message') || 'Terjadi kesalahan.');

})();
</script>
</body>
</html>