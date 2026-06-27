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

// FILTER IZIN #1
function getFilteredDataIzin($pdo, $status)
{
    if ($status === 'approved') {
        $sql = "SELECT * FROM perizinan_view WHERE status = 'disetujui'";
    } elseif ($status === 'rejected') {
        $sql = "SELECT * FROM perizinan_view WHERE status = 'ditolak'";
    } else {
        $sql = "SELECT * FROM perizinan_view WHERE status IN ('disetujui', 'ditolak')";
    }
    $stmt = $pdo->query($sql);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// FILTER IZIN #2
if (isset($_GET['filter_status'])) {
    $status = $_GET['filter_status'];

    // Cek apakah status berasal dari izin atau cuti
    if (strpos($status, 'izin') !== false) {
        $dataIzin = getFilteredDataIzin($pdo, str_replace('izin_', '', $status));
        foreach ($dataIzin as $row) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($row['nama_lengkap']) . "</td>";
            echo "<td>" . htmlspecialchars($row['tanggal']) . "</td>";
            echo "<td>" . htmlspecialchars($row['jenis_izin']) . "</td>";
            echo "<td>" . htmlspecialchars($row['keterangan']) . "</td>";
            $statusClass = $row['status'] == 'disetujui' ? 'badge-slb badge-slb-success' : 'badge-slb badge-slb-danger';
            echo "<td><span class='{$statusClass}'>" . ucfirst(htmlspecialchars($row['status'])) . "</span></td>";
            echo "<td><input type='checkbox' class='row-checkbox'></td>";
            echo "</tr>";
        }
    } elseif (strpos($status, 'cuti') !== false) {
        $dataCuti = getFilteredDataCuti($pdo, str_replace('cuti_', '', $status));
        foreach ($dataCuti as $row) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($row['nama_staff']) . "</td>";
            echo "<td>" . htmlspecialchars($row['tanggal_mulai']) . "</td>";
            echo "<td>" . htmlspecialchars($row['tanggal_selesai']) . "</td>";
            echo "<td>" . htmlspecialchars($row['durasi_cuti']) . "</td>";
            echo "<td>" . htmlspecialchars($row['keterangan']) . "</td>";
            $statusClass = $row['status'] == 'disetujui' ? 'badge-slb badge-slb-success' : 'badge-slb badge-slb-danger';
            echo "<td><span class='{$statusClass}'>" . ucfirst(htmlspecialchars($row['status'])) . "</span></td>";
            echo "<td><input type='checkbox' class='row-checkbox'></td>";
            echo "</tr>";
        }
    }
    exit;
}

// FILTER CUTI #2
function getFilteredDataCuti($pdo, $status)
{
    if ($status === 'approved') {
        $sql = "SELECT * FROM cuti_view WHERE status = 'disetujui'";
    } elseif ($status === 'rejected') {
        $sql = "SELECT * FROM cuti_view WHERE status = 'ditolak'";
    } else {
        $sql = "SELECT * FROM cuti_view WHERE status IN ('disetujui', 'ditolak')";
    }
    $stmt = $pdo->query($sql);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}


// BUTTON CUTI UPDATE STATUS
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id_cuti'], $_POST['status'])) {
    // Proses update status cuti
    $id_cuti = intval($_POST['id_cuti']);
    $status_baru = $_POST['status'];

    try {
        $sql = "UPDATE cuti 
                SET status = :status, 
                    updated_at = NOW() 
                WHERE id = :id";

        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':status', $status_baru);
        $stmt->bindParam(':id', $id_cuti, PDO::PARAM_INT);

        $result = $stmt->execute();

        if ($result) {
            echo json_encode([
                'status' => 'success',
                'message' => 'Pengajuan Cuti Berhasil Diperbarui'
            ]);
            exit;
        } else {
            echo json_encode([
                'status' => 'error',
                'message' => 'Gagal update status cuti'
            ]);
            exit;
        }
    } catch (PDOException $e) {
        echo json_encode([
            'status' => 'error',
            'message' => 'Terjadi kesalahan: ' . $e->getMessage()
        ]);
        exit;
    }
}

// IZIN BUTTON UPDATE STATUS 
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['id_izin'], $_POST['status'], $_POST['action']) &&
    $_POST['action'] === 'update_izin'
) {

    // Proses update status izin
    $id_izin = intval($_POST['id_izin']);
    $status_baru = $_POST['status'];

    try {
        $sql = "UPDATE izin 
                SET status = :status, 
                    updated_at = NOW() 
                WHERE id = :id";

        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':status', $status_baru);
        $stmt->bindParam(':id', $id_izin, PDO::PARAM_INT);

        $result = $stmt->execute();

        if ($result) {
            echo json_encode([
                'status' => 'success',
                'message' => 'Pengajuan Izin Berhasil Diperbarui'
            ]);
            exit;
        } else {
            echo json_encode([
                'status' => 'error',
                'message' => 'Gagal update status izin'
            ]);
            exit;
        }
    } catch (PDOException $e) {
        echo json_encode([
            'status' => 'error',
            'message' => 'Terjadi kesalahan: ' . $e->getMessage()
        ]);
        exit;
    }
}

// COUNT JUMLAH DATA SECARA REALTIME
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'fetch_status') {
    // Mengambil data pending dan answered dari izin dan cuti
    $pendingQuery = "
        SELECT COUNT(*) AS total_pending FROM (
            SELECT id FROM izin WHERE status = 'pending'
            UNION ALL
            SELECT id FROM cuti WHERE status = 'pending'
        ) AS pending";

    $answeredQuery = "
        SELECT COUNT(*) AS total_answered FROM (
            SELECT id FROM izin WHERE status IN ('disetujui', 'ditolak')
            UNION ALL
            SELECT id FROM cuti WHERE status IN ('disetujui', 'ditolak')
        ) AS answered";

    $totalPending = $pdo->query($pendingQuery)->fetchColumn();
    $totalAnswered = $pdo->query($answeredQuery)->fetchColumn();

    echo json_encode([
        'status' => 'success',
        'total_pending' => $totalPending,
        'total_answered' => $totalAnswered
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'fetch_pending_data') {
    // Mengambil data pending
    $sql = "SELECT 
        i.id AS izin_id,
        u.nama_lengkap AS Nama_Staff,
        i.tanggal AS tanggal,
        i.jenis_izin AS jenis_izin,
        i.keterangan AS keterangan,
        i.status AS status
    FROM 
        izin i
    LEFT JOIN 
        pegawai p ON i.pegawai_id = p.id
    LEFT JOIN 
        users u ON p.user_id = u.id
    WHERE 
        i.status = 'pending'
    ORDER BY 
        i.id";

    $stmt = $pdo->query($sql);
    $dataIzin = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'status' => 'success',
        'data' => $dataIzin
    ]);
    exit; // Hentikan eksekusi script setelah mengembalikan data
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_permit') {
    ob_clean();
    ob_start();

    header('Content-Type: application/json');

    try {
        $pdo->beginTransaction();

        $staffData = json_decode($_POST['staff_data'], true);
        $tableType = $_POST['table_type'];
        $successCount = 0;
        $errors = [];

        foreach ($staffData as $data) {
            if ($tableType === 'izin') {
                $stmt = $pdo->prepare("
                    DELETE i FROM izin i
                    INNER JOIN pegawai p ON i.pegawai_id = p.id
                    INNER JOIN users u ON p.user_id = u.id
                    WHERE u.nama_lengkap = ? AND i.tanggal = ? AND i.jenis_izin = ?
                ");
                $result = $stmt->execute([$data['nama'], $data['tanggal'], $data['jenisIzin']]);
            } else {
                $stmt = $pdo->prepare("
                    DELETE c FROM cuti c
                    INNER JOIN pegawai p ON c.pegawai_id = p.id
                    INNER JOIN users u ON p.user_id = u.id
                    WHERE u.nama_lengkap = ? AND c.tanggal_mulai = ? AND c.tanggal_selesai = ?
                ");
                $result = $stmt->execute([$data['nama'], $data['tanggalMulai'], $data['tanggalSelesai']]);
            }

            if ($result) {
                $successCount++;
            } else {
                $errors[] = "Gagal menghapus data untuk: " . $data['nama'];
            }
        }

        if ($successCount > 0) {
            $pdo->commit();
            echo json_encode([
                'success' => true,
                'message' => "$successCount Data berhasil dihapus",
                'errors' => $errors
            ]);
        } else {
            $pdo->rollBack();
            echo json_encode([
                'success' => false,
                'message' => 'Tidak ada data yang dihapus',
                'errors' => $errors
            ]);
        }

    } catch (PDOException $e) {
        $pdo->rollBack();
        echo json_encode([
            'success' => false,
            'message' => 'Error sistem: ' . $e->getMessage()
        ]);
    }

    exit;
}

// Data untuk render awal halaman
$sqlIzinPending = "SELECT 
        i.id AS izin_id,
        u.nama_lengkap AS Nama_Staff,
        i.tanggal AS tanggal,
        i.jenis_izin AS jenis_izin,
        i.keterangan AS keterangan,
        i.status AS status
    FROM 
        izin i
    LEFT JOIN 
        pegawai p ON i.pegawai_id = p.id
    LEFT JOIN 
        users u ON p.user_id = u.id
    WHERE 
        i.status = 'pending'
    ORDER BY 
        i.id";
$stmt = $pdo->query($sqlIzinPending);
$dataIzin = $stmt->fetchAll(PDO::FETCH_ASSOC);

$sqlCutiPending = "SELECT 
            c.id AS cuti_id,
            c.tanggal_mulai,
            c.tanggal_selesai,
            c.durasi_cuti,
            c.keterangan,
            u.nama_lengkap
        FROM cuti c
        JOIN pegawai p ON c.pegawai_id = p.id
        JOIN users u ON p.user_id = u.id
        WHERE c.status = 'pending'";
$stmt = $pdo->query($sqlCutiPending);
$dataCuti = $stmt->fetchAll(PDO::FETCH_ASSOC);

$dataIzinHistory = getFilteredDataIzin($pdo, 'all');
$dataCutiHistory = getFilteredDataCuti($pdo, 'all');
?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no" />
    <meta name="description" content="SIHADIR - Sistem Kehadiran Sing Long Brother Industrial" />
    <meta name="author" content="Sing Long Brother Industrial" />
    <title>SIHADIR | Cuti &amp; Perizinan</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
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
            margin-bottom: 0;
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
        .btn-slb-danger {
            background: linear-gradient(135deg, #e0334d, #c4263c);
            color: #fff;
            border: none;
            font-weight: 700;
            padding: 10px 20px;
            border-radius: 10px;
            font-size: 13.5px;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .btn-slb-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 18px rgba(224,51,77,0.3);
            color: #fff;
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
        .alert .close, .alert .btn-close {
            position: relative;
            font-size: 20px;
            font-weight: bold;
            color: inherit;
            opacity: 0.7;
        }

        /* ===== STAT CARDS ===== */
        .stat-card-slb {
            background: var(--slb-card);
            border-radius: 16px;
            border: 1px solid #edf0f5;
            box-shadow: 0 2px 8px rgba(16,24,40,0.04);
            padding: 22px;
            display: flex;
            align-items: center;
            gap: 16px;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .stat-card-slb:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 22px rgba(16,24,40,0.08);
        }
        .stat-icon-circle {
            width: 52px;
            height: 52px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            flex-shrink: 0;
        }
        .stat-icon-pending { background: rgba(245,166,35,0.14); color: var(--slb-amber-dark); }
        .stat-icon-answered { background: rgba(15,181,174,0.14); color: var(--slb-teal); }
        .stat-label-slb {
            font-size: 11.5px;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            font-weight: 700;
            color: var(--slb-muted);
            margin-bottom: 4px;
        }
        .stat-value-slb {
            font-size: 28px;
            font-weight: 800;
            color: var(--slb-navy);
            font-family: 'Sora', sans-serif;
            line-height: 1;
        }

        /* ===== VIEW TOGGLE ===== */
        .view-toggle-slb {
            display: inline-flex;
            background: #eef1f6;
            border-radius: 12px;
            padding: 4px;
            gap: 4px;
            margin-bottom: 18px;
        }
        .view-toggle-btn {
            border: none;
            background: transparent;
            padding: 9px 18px;
            border-radius: 9px;
            font-size: 13.5px;
            font-weight: 700;
            color: var(--slb-muted);
            transition: all 0.2s ease;
        }
        .view-toggle-btn:hover { color: var(--slb-navy); }
        .view-toggle-btn.active {
            background: linear-gradient(135deg, var(--slb-amber), var(--slb-amber-dark));
            color: var(--slb-navy);
            box-shadow: 0 4px 12px rgba(245,166,35,0.3);
        }

        /* ===== STATUS BADGES ===== */
        .badge-slb {
            display: inline-block;
            padding: 5px 14px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.3px;
        }
        .badge-slb-success { background: #e9f9f0; color: #0d9d58; }
        .badge-slb-danger { background: #fdebec; color: #e0334d; }

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
        table.slb-table tbody tr:last-child td {
            border-bottom: none;
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

        input[type="checkbox"].row-checkbox {
            width: 16px;
            height: 16px;
            accent-color: var(--slb-amber);
            cursor: pointer;
        }

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
        .modal-subtitle-slb {
            color: rgba(255,255,255,0.65);
            font-size: 11.5px;
            margin-top: 2px;
        }
        .modal-toolbar-slb {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 18px;
        }
        .modal-filters-slb {
            display: flex;
            flex-wrap: wrap;
            gap: 14px;
            margin-bottom: 18px;
        }
        .modal-filters-slb .filter-group-slb { flex: 1; min-width: 160px; }

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
            .slb-card, .stat-card-slb { padding: 16px; border-radius: 14px; }
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
            <a class="nav-item-slb" href="manageMember.php">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="19" height="19" fill="currentColor">
                    <path d="M16 11c1.66 0 3-1.34 3-3s-1.34-3-3-3-3 1.34-3 3 1.34 3 3 3zm-8 0c1.66 0 3-1.34 3-3S9.66 5 8 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V20h14v-3.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 2.02 1.97 3.45V20h6v-3.5c0-2.33-4.67-3.5-7-3.5z" />
                </svg>
                Manajemen Staff
            </a>
            <a class="nav-item-slb active" href="permit.php">
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
                <h1 class="page-heading-slb">Cuti &amp; Perizinan</h1>
                <p class="page-subheading-slb">Kelola pengajuan cuti dan izin karyawan</p>

                <!-- Stat cards -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-5 mb-2" id="status-container">
                    <div class="stat-card-slb">
                        <div class="stat-icon-circle stat-icon-pending"><i class="fa-solid fa-hourglass-half"></i></div>
                        <div>
                            <div class="stat-label-slb">Sedang Dalam Permohonan</div>
                            <div class="stat-value-slb" id="pending-count">0</div>
                        </div>
                    </div>
                    <div class="stat-card-slb">
                        <div class="stat-icon-circle stat-icon-answered"><i class="fa-solid fa-circle-check"></i></div>
                        <div>
                            <div class="stat-label-slb">Telah Dijawab</div>
                            <div class="stat-value-slb" id="answered-count">0</div>
                        </div>
                    </div>
                </div>

                <div class="slb-card" style="margin-top: 22px;">
                    <div class="toolbar-slb" style="margin-bottom: 18px;">
                        <div class="toolbar-left">
                            <button class="btn-slb-secondary" id="historyToggle" data-bs-toggle="modal" data-bs-target="#approvalModal">
                                <i class="fa-solid fa-clock-rotate-left me-1"></i> Riwayat Persetujuan
                            </button>
                        </div>
                        <input type="text" id="searchInput" class="filter-input-slb" placeholder="Cari Nama Staff">
                    </div>

                    <!-- Toggle Izin / Cuti -->
                    <div class="view-toggle-slb" role="tablist">
                        <button type="button" class="view-toggle-btn active" id="btnShowIzin" onclick="setActiveTable('izin')">
                            <i class="fa-solid fa-file-circle-question me-1"></i> Tabel Izin
                        </button>
                        <button type="button" class="view-toggle-btn" id="btnShowCuti" onclick="setActiveTable('cuti')">
                            <i class="fa-solid fa-umbrella-beach me-1"></i> Tabel Cuti
                        </button>
                    </div>

                    <div id="izinTable" class="table-container custom-scrollbar overflow-x-auto">
                        <table class="slb-table min-w-full">
                            <thead>
                                <tr>
                                    <th>Nama Staff</th>
                                    <th>Tanggal</th>
                                    <th>Jenis Izin</th>
                                    <th>Keterangan</th>
                                    <th>Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($dataIzin)): ?>
                                    <tr><td colspan="5" style="color: var(--slb-muted); padding: 26px 16px;">Tidak ada pengajuan izin yang menunggu persetujuan.</td></tr>
                                <?php else: foreach ($dataIzin as $row): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($row['Nama_Staff']); ?></td>
                                        <td><?php echo htmlspecialchars($row['tanggal']); ?></td>
                                        <td><?php echo htmlspecialchars($row['jenis_izin']); ?></td>
                                        <td><?php echo htmlspecialchars($row['keterangan']); ?></td>
                                        <td>
                                            <div class="d-flex gap-2 justify-content-center">
                                                <button class="action-btn-slb action-btn-edit btn-setuju-izin"
                                                    data-id="<?php echo $row['izin_id']; ?>" data-status="disetujui">
                                                    <i class="fa-solid fa-check me-1"></i>Setujui</button>
                                                <button class="action-btn-slb action-btn-delete btn-tolak-izin"
                                                    data-id="<?php echo $row['izin_id']; ?>" data-status="ditolak">
                                                    <i class="fa-solid fa-xmark me-1"></i>Tolak</button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <div id="cutiTable" class="table-container custom-scrollbar overflow-x-auto hidden">
                        <table class="slb-table min-w-full">
                            <thead>
                                <tr>
                                    <th>Nama Staff</th>
                                    <th>Tanggal Mulai</th>
                                    <th>Tanggal Selesai</th>
                                    <th>Durasi Cuti</th>
                                    <th>Keterangan</th>
                                    <th>Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($dataCuti)): ?>
                                    <tr><td colspan="6" style="color: var(--slb-muted); padding: 26px 16px;">Tidak ada pengajuan cuti yang menunggu persetujuan.</td></tr>
                                <?php else: foreach ($dataCuti as $row): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($row['nama_lengkap']); ?></td>
                                        <td><?php echo date('d M Y', strtotime($row['tanggal_mulai'])); ?></td>
                                        <td><?php echo date('d M Y', strtotime($row['tanggal_selesai'])); ?></td>
                                        <td><?php echo htmlspecialchars($row['durasi_cuti'] . ' hari'); ?></td>
                                        <td><?php echo htmlspecialchars($row['keterangan']); ?></td>
                                        <td>
                                            <div class="d-flex gap-2 justify-content-center">
                                                <button class="action-btn-slb action-btn-edit btn-setuju-cuti"
                                                    data-id="<?php echo $row['cuti_id']; ?>" data-status="disetujui">
                                                    <i class="fa-solid fa-check me-1"></i>Setujui</button>
                                                <button class="action-btn-slb action-btn-delete btn-tolak-cuti"
                                                    data-id="<?php echo $row['cuti_id']; ?>" data-status="ditolak">
                                                    <i class="fa-solid fa-xmark me-1"></i>Tolak</button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Riwayat Persetujuan -->
    <div class="modal fade" id="approvalModal" tabindex="-1" aria-labelledby="approvalModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title" id="approvalModalLabel"><i class="fa-solid fa-clock-rotate-left me-2"></i>Riwayat Persetujuan</h5>
                        <div class="modal-subtitle-slb">Daftar pengajuan izin &amp; cuti yang sudah diproses</div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="modal-filters-slb">
                        <div class="filter-group-slb">
                            <label for="tableSelect" class="form-label">Pilih Tabel</label>
                            <select id="tableSelect" class="form-select" onchange="toggleTable()">
                                <option value="izin">Tabel Izin</option>
                                <option value="cuti">Tabel Cuti</option>
                            </select>
                        </div>
                        <div class="filter-group-slb">
                            <label for="approvalFilter" class="form-label">Tampilkan</label>
                            <select id="approvalFilter" class="form-select" onchange="filterTable()">
                                <option value="all">Semua</option>
                                <option value="approved">Disetujui</option>
                                <option value="rejected">Ditolak</option>
                            </select>
                        </div>
                    </div>

                    <div class="modal-toolbar-slb">
                        <button class="btn-slb-secondary" onclick="selectAll()">
                            <i class="fa-solid fa-check-double me-1"></i>Pilih Semua</button>
                        <button class="btn-slb-secondary" onclick="deselectAll()">
                            <i class="fa-regular fa-square me-1"></i>Batal Pilih</button>
                        <button class="btn-slb-danger" onclick="confirmDelete()">
                            <i class="fa-solid fa-trash me-1"></i>Hapus Data</button>
                    </div>

                    <!-- Tabel Izin -->
                    <div id="izinHistoryTable" class="table-container custom-scrollbar max-h-[300px] overflow-y-auto overflow-x-auto">
                        <table class="slb-table min-w-full">
                            <thead>
                                <tr>
                                    <th>Nama Staff</th>
                                    <th>Tanggal</th>
                                    <th>Jenis Izin</th>
                                    <th>Keterangan</th>
                                    <th>Status</th>
                                    <th>Pilih</th>
                                </tr>
                            </thead>
                            <tbody class="izinHistoryBody" id="izinHistoryBody">
                                <?php if (empty($dataIzinHistory)): ?>
                                    <tr><td colspan="6" style="color: var(--slb-muted); padding: 22px 16px;">Belum ada riwayat izin.</td></tr>
                                <?php else: foreach ($dataIzinHistory as $row): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($row['nama_lengkap']); ?></td>
                                        <td><?php echo htmlspecialchars($row['tanggal']); ?></td>
                                        <td><?php echo htmlspecialchars($row['jenis_izin']); ?></td>
                                        <td><?php echo htmlspecialchars($row['keterangan']); ?></td>
                                        <td>
                                            <?php if ($row['status'] == 'disetujui'): ?>
                                                <span class="badge-slb badge-slb-success">Disetujui</span>
                                            <?php else: ?>
                                                <span class="badge-slb badge-slb-danger">Ditolak</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><input type="checkbox" class="row-checkbox"></td>
                                    </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Tabel Cuti -->
                    <div id="cutiHistoryTable" class="hidden table-container custom-scrollbar max-h-[300px] overflow-y-auto overflow-x-auto">
                        <table class="slb-table min-w-full">
                            <thead>
                                <tr>
                                    <th>Nama Staff</th>
                                    <th>Tanggal Mulai</th>
                                    <th>Tanggal Selesai</th>
                                    <th>Durasi Cuti</th>
                                    <th>Keterangan</th>
                                    <th>Status</th>
                                    <th>Pilih</th>
                                </tr>
                            </thead>
                            <tbody class="cutiHistoryBody" id="cutiHistoryBody">
                                <?php if (empty($dataCutiHistory)): ?>
                                    <tr><td colspan="7" style="color: var(--slb-muted); padding: 22px 16px;">Belum ada riwayat cuti.</td></tr>
                                <?php else: foreach ($dataCutiHistory as $row): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($row['nama_staff']); ?></td>
                                        <td><?php echo htmlspecialchars($row['tanggal_mulai']); ?></td>
                                        <td><?php echo htmlspecialchars($row['tanggal_selesai']); ?></td>
                                        <td><?php echo htmlspecialchars($row['durasi_cuti']); ?></td>
                                        <td><?php echo htmlspecialchars($row['keterangan']); ?></td>
                                        <td>
                                            <?php if ($row['status'] == 'disetujui'): ?>
                                                <span class="badge-slb badge-slb-success">Disetujui</span>
                                            <?php else: ?>
                                                <span class="badge-slb badge-slb-danger">Ditolak</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><input type="checkbox" class="row-checkbox"></td>
                                    </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <!-- Live clock di topbar -->
    <script>
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

    <!-- UPDATE JUMLAH DATA PENDING & DIJAWAB SECARA REAL TIME -->
    <script>
        function fetchStatusData() {
            $.ajax({
                url: 'permit.php',
                method: 'POST',
                dataType: 'json',
                data: { action: 'fetch_status' },
                success: function (response) {
                    if (response.status === 'success') {
                        $('#pending-count').text(response.total_pending);
                        $('#answered-count').text(response.total_answered);
                    }
                },
                error: function (xhr, status, error) {
                    console.error('Error fetching data:', error);
                }
            });
        }

        $(document).ready(function () {
            fetchStatusData();
            // Memperbarui data setiap 10 detik
            setInterval(fetchStatusData, 10000);
        });
    </script>

    <!-- Notifikasi terpusat (SweetAlert2) -->
    <script>
        function showAlert(message, type = 'success') {
            const icon = type === 'success' ? 'success' : (type === 'danger' || type === 'error' ? 'error' : 'info');
            Swal.fire({
                toast: true,
                position: 'top-end',
                icon: icon,
                title: message,
                showConfirmButton: false,
                timer: 3000,
                timerProgressBar: true
            });
        }
    </script>

    <!-- Script Izin: setuju / tolak -->
    <script>
        $(document).ready(function () {
            $('.btn-setuju-izin, .btn-tolak-izin').click(function () {
                var $row = $(this).closest('tr');
                var id_izin = $(this).data('id');
                var status = $(this).data('status');
                var aksiText = status === 'disetujui' ? 'menyetujui' : 'menolak';

                Swal.fire({
                    title: 'Konfirmasi',
                    text: 'Apakah Anda yakin ingin ' + aksiText + ' izin ini?',
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonText: 'Ya, Lanjutkan',
                    cancelButtonText: 'Batal',
                    confirmButtonColor: '#0b1f3a',
                    cancelButtonColor: '#6b7280'
                }).then(function (result) {
                    if (!result.isConfirmed) return;

                    $.ajax({
                        url: '',
                        method: 'POST',
                        dataType: 'json',
                        data: {
                            id_izin: id_izin,
                            status: status,
                            action: 'update_izin'
                        },
                        success: function (response) {
                            if (response.status === 'success') {
                                showAlert(response.message, 'success');
                                $row.remove();

                                $.ajax({
                                    url: 'permit.php',
                                    method: 'GET',
                                    data: { refresh_history_izin: true },
                                    success: function (historyIzin) {
                                        var $newHistoryRows = $(historyIzin).find('.izinHistoryBody').html();
                                        $('.izinHistoryBody').html($newHistoryRows);
                                    },
                                    error: function () {
                                        showAlert('Gagal memuat data history izin', 'danger');
                                    }
                                });
                            } else {
                                showAlert(response.message, 'danger');
                            }
                        },
                        error: function (xhr, status, error) {
                            showAlert('Terjadi kesalahan dalam proses update: ' + error, 'danger');
                        }
                    });
                });
            });
        });
    </script>

    <!-- Script Cuti: setuju / tolak -->
    <script>
        $(document).ready(function () {
            $('.btn-setuju-cuti, .btn-tolak-cuti').click(function () {
                var $row = $(this).closest('tr');
                var id_cuti = $(this).data('id');
                var status = $(this).data('status');
                var aksiText = status === 'disetujui' ? 'menyetujui' : 'menolak';

                Swal.fire({
                    title: 'Konfirmasi',
                    text: 'Apakah Anda yakin ingin ' + aksiText + ' cuti ini?',
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonText: 'Ya, Lanjutkan',
                    cancelButtonText: 'Batal',
                    confirmButtonColor: '#0b1f3a',
                    cancelButtonColor: '#6b7280'
                }).then(function (result) {
                    if (!result.isConfirmed) return;

                    $.ajax({
                        url: '',
                        method: 'POST',
                        dataType: 'json',
                        data: {
                            id_cuti: id_cuti,
                            status: status,
                            action: 'update_cuti'
                        },
                        success: function (response) {
                            if (response.status === 'success') {
                                showAlert(response.message, 'success');
                                $row.remove();

                                $.ajax({
                                    url: 'permit.php',
                                    method: 'GET',
                                    data: { refresh_history_cuti: true },
                                    success: function (historyCuti) {
                                        var $newHistoryRows = $(historyCuti).find('.cutiHistoryBody').html();
                                        $('.cutiHistoryBody').html($newHistoryRows);
                                    },
                                    error: function () {
                                        showAlert('Gagal memuat data history cuti', 'danger');
                                    }
                                });
                            } else {
                                showAlert(response.message, 'danger');
                            }
                        },
                        error: function (xhr, status, error) {
                            showAlert('Terjadi kesalahan dalam proses update: ' + error, 'danger');
                        }
                    });
                });
            });
        });
    </script>

    <!-- PENCARIAN NAMA STAFF (hanya tabel utama) -->
    <script>
        document.getElementById('searchInput').addEventListener('keyup', function () {
            const searchValue = this.value.toLowerCase();
            const rows = document.querySelectorAll('#izinTable tbody tr, #cutiTable tbody tr');

            rows.forEach(row => {
                if (!row.cells.length) return;
                const namaStaff = row.cells[0].textContent.toLowerCase();
                row.style.display = namaStaff.includes(searchValue) ? '' : 'none';
            });
        });
    </script>

    <!-- Toggle tabel utama Izin / Cuti -->
    <script>
        function setActiveTable(type) {
            document.getElementById('izinTable').classList.toggle('hidden', type !== 'izin');
            document.getElementById('cutiTable').classList.toggle('hidden', type !== 'cuti');
            document.getElementById('btnShowIzin').classList.toggle('active', type === 'izin');
            document.getElementById('btnShowCuti').classList.toggle('active', type === 'cuti');
        }
    </script>

    <!-- JS FILTER TABEL DI DALAM MODAL, DEFAULT FILTER "SEMUA" -->
    <script>
        function toggleTable() {
            const selectedTable = document.getElementById('tableSelect').value;
            const izinTable = document.getElementById('izinHistoryTable');
            const cutiTable = document.getElementById('cutiHistoryTable');
            const approvalFilter = document.getElementById('approvalFilter');

            if (selectedTable === 'izin') {
                izinTable.classList.remove('hidden');
                cutiTable.classList.add('hidden');
            } else if (selectedTable === 'cuti') {
                cutiTable.classList.remove('hidden');
                izinTable.classList.add('hidden');
            }

            approvalFilter.value = "all";
            filterTable();
        }

        function filterTable() {
            const approvalStatus = document.getElementById("approvalFilter").value;
            const selectedTable = document.getElementById("tableSelect").value;
            const xhr = new XMLHttpRequest();

            let filterValue = `${selectedTable}_${approvalStatus}`;

            xhr.open("GET", `?filter_status=${filterValue}`, true);
            xhr.onload = function () {
                if (this.status === 200) {
                    if (selectedTable === 'izin') {
                        document.getElementById("izinHistoryTable").querySelector("tbody").innerHTML = this.responseText;
                    } else if (selectedTable === 'cuti') {
                        document.getElementById("cutiHistoryTable").querySelector("tbody").innerHTML = this.responseText;
                    }
                }
            };
            xhr.send();
        }

        document.addEventListener("DOMContentLoaded", function () {
            toggleTable();

            const tableSelect = document.getElementById('tableSelect');
            tableSelect.addEventListener('change', toggleTable);

            const approvalFilter = document.getElementById('approvalFilter');
            approvalFilter.addEventListener('change', filterTable);

            const approvalModal = document.getElementById('approvalModal');
            approvalModal.addEventListener('hide.bs.modal', function () {
                approvalFilter.value = "all";
            });

            approvalModal.addEventListener('show.bs.modal', function () {
                toggleTable();
            });
        });
    </script>

    <!-- Pilih semua / hapus data riwayat -->
    <script>
        function getActiveTable() {
            const izinTable = document.querySelector('#izinHistoryTable');
            const cutiTable = document.querySelector('#cutiHistoryTable');

            if (izinTable && !izinTable.classList.contains('hidden')) {
                return 'izin';
            } else if (cutiTable && !cutiTable.classList.contains('hidden')) {
                return 'cuti';
            }
            return null;
        }

        function selectAll() {
            const activeTable = getActiveTable();
            const scope = activeTable === 'izin' ? '#izinHistoryTable' : '#cutiHistoryTable';
            document.querySelectorAll(scope + ' .row-checkbox').forEach(checkbox => {
                checkbox.checked = true;
            });
        }

        function deselectAll() {
            const activeTable = getActiveTable();
            const scope = activeTable === 'izin' ? '#izinHistoryTable' : '#cutiHistoryTable';
            document.querySelectorAll(scope + ' .row-checkbox').forEach(checkbox => {
                checkbox.checked = false;
            });
        }

        function getRowData(row, tableType) {
            if (tableType === 'izin') {
                return {
                    nama: row.cells[0].textContent.trim(),
                    tanggal: row.cells[1].textContent.trim(),
                    jenisIzin: row.cells[2].textContent.trim(),
                    type: 'izin'
                };
            } else {
                return {
                    nama: row.cells[0].textContent.trim(),
                    tanggalMulai: row.cells[1].textContent.trim(),
                    tanggalSelesai: row.cells[2].textContent.trim(),
                    type: 'cuti'
                };
            }
        }

        function confirmDelete(button) {
            const isIndividual = button && button.closest;
            let staffData = [];
            let rowsToDelete = [];
            const activeTable = document.querySelector('#izinHistoryTable:not(.hidden)') ? 'izin' : 'cuti';

            if (isIndividual) {
                const row = button.closest('tr');
                staffData.push(getRowData(row, activeTable));
                rowsToDelete.push(row);
            } else {
                const checkboxes = document.querySelectorAll('.row-checkbox:checked');

                if (checkboxes.length === 0) {
                    Swal.fire({
                        icon: 'info',
                        title: 'Belum ada data dipilih',
                        text: 'Silakan pilih data yang ingin dihapus terlebih dahulu.',
                        confirmButtonColor: '#0b1f3a'
                    });
                    return;
                }

                checkboxes.forEach(checkbox => {
                    const row = checkbox.closest('tr');
                    staffData.push(getRowData(row, activeTable));
                    rowsToDelete.push(row);
                });
            }

            const confirmText = isIndividual
                ? `Hapus data untuk ${staffData[0].nama}?`
                : `Anda yakin ingin menghapus ${staffData.length} data yang dipilih?`;

            Swal.fire({
                title: 'Konfirmasi Hapus',
                text: confirmText,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Ya, Hapus',
                cancelButtonText: 'Batal',
                confirmButtonColor: '#e0334d',
                cancelButtonColor: '#6b7280'
            }).then(function (result) {
                if (result.isConfirmed) {
                    sendDeleteRequest(staffData, rowsToDelete, activeTable);
                }
            });
        }

        function sendDeleteRequest(staffData, rowsToDelete, tableType) {
            const xhr = new XMLHttpRequest();
            xhr.open('POST', window.location.href, true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');

            xhr.onload = function () {
                try {
                    const response = JSON.parse(xhr.responseText);

                    if (response.success) {
                        rowsToDelete.forEach(row => row.remove());

                        const selectAllCheckbox = document.querySelector('#select-all');
                        if (selectAllCheckbox) {
                            selectAllCheckbox.checked = false;
                        }

                        showAlert(response.message, 'success');
                    } else {
                        showAlert(response.message, 'danger');
                        if (response.errors) {
                            console.log('Errors:', response.errors);
                        }
                    }
                } catch (error) {
                    console.error('Error:', error);
                    showAlert('Terjadi kesalahan dalam pemrosesan. Silakan coba lagi.', 'danger');
                }
            };

            xhr.onerror = function () {
                console.error('Network Error');
                showAlert('Terjadi kesalahan jaringan. Silakan cek koneksi Anda.', 'danger');
            };

            const data = `action=delete_permit&staff_data=${encodeURIComponent(JSON.stringify(staffData))}&table_type=${tableType}`;
            xhr.send(data);
        }

        document.addEventListener('DOMContentLoaded', function () {
            const rowCheckboxes = document.querySelectorAll('.row-checkbox');
            const selectAllCheckbox = document.querySelector('#select-all');

            rowCheckboxes.forEach(checkbox => {
                checkbox.addEventListener('change', function () {
                    if (selectAllCheckbox) {
                        selectAllCheckbox.checked =
                            Array.from(rowCheckboxes).every(cb => cb.checked);
                    }
                });
            });
        });
    </script>

    <!-- Bootstrap core JS-->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../../../assets/js/scripts.js"></script>

    <!-- Sidebar toggle (responsive: desktop collapse, mobile off-canvas) -->
    <script>
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