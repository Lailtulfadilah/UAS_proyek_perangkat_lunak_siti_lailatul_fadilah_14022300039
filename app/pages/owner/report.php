<?php
session_start();

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

require '../../../vendor/autoload.php'; // Pastikan path ini sesuai
require_once '../../../app/auth/auth.php';

use Dompdf\Dompdf;
use Dompdf\Options;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Font;
use PhpOffice\PhpSpreadsheet\Style\Border;

// Function to get valid date range with actual attendance data
function getValidAttendanceDateRange($pdo)
{
    $query = $pdo->query("
        SELECT 
            MIN(tanggal) as earliest_date, 
            MAX(tanggal) as latest_date
        FROM absensi 
        WHERE 
            (status_kehadiran IS NOT NULL) AND 
            (status_kehadiran != '') AND
            (status_kehadiran IN ('hadir', 'alpha', 'sakit', 'cuti', 'izin', 'terlambat', 'pulang_dahulu', 'tidak_absen_pulang'))
    ");

    $result = $query->fetch(PDO::FETCH_ASSOC);

    return [
        'min_date' => $result['earliest_date'] ? date('Y-m-d', strtotime($result['earliest_date'])) : null,
        'max_date' => $result['latest_date'] ? date('Y-m-d', strtotime($result['latest_date'])) : null
    ];
}

$dateRange = getValidAttendanceDateRange($pdo);

// If no valid dates found, handle accordingly
if ($dateRange['min_date'] === null || $dateRange['max_date'] === null) {
    // No attendance data available
    $minDate = date('Y-m-d');
    $maxDate = date('Y-m-d');
} else {
    $minDate = $dateRange['min_date'];
    $maxDate = $dateRange['max_date'];
}

$earliest_date_query = $pdo->query("
    SELECT MIN(tanggal) as earliest_date 
    FROM absensi
");
$earliest_date_result = $earliest_date_query->fetch(PDO::FETCH_ASSOC);
$minDate = $earliest_date_result['earliest_date'] ? date('Y-m-d', strtotime($earliest_date_result['earliest_date'])) : date('Y-m-d');

$start_date = isset($_POST['start_date']) ? $_POST['start_date'] : null;
$end_date = isset($_POST['end_date']) ? $_POST['end_date'] : null;

if (!empty($start_date) && !empty($end_date)) {
    // Pengecekan format tanggal
    if (!DateTime::createFromFormat('Y-m-d', $start_date) || !DateTime::createFromFormat('Y-m-d', $end_date)) {
        die('Invalid date format. Please use YYYY-MM-DD.');
    }

    // Menambahkan waktu ke tanggal
    $start_date_with_time = $start_date . ' 00:00:00';
    $end_date_with_time = $end_date . ' 23:59:59';

    // Menyiapkan query dengan filter tanggal
    $stmt = $pdo->prepare("
    SELECT 
        u.nama_lengkap AS nama_staff,
        u.jenis_kelamin AS jenis_kelamin,
        SUM(CASE WHEN a.status_kehadiran IN ('hadir', 'terlambat', 'pulang_dahulu', 'tidak_absen_pulang') THEN 1 ELSE 0 END) AS hadir,
        SUM(CASE WHEN a.status_kehadiran = 'alpha' THEN 1 ELSE 0 END) AS alpha,
        SUM(CASE WHEN a.status_kehadiran = 'sakit' THEN 1 ELSE 0 END) AS sakit,
        SUM(CASE WHEN a.status_kehadiran = 'cuti' THEN 1 ELSE 0 END) AS cuti,
        SUM(CASE WHEN a.status_kehadiran = 'izin' THEN 1 ELSE 0 END) AS izin
    FROM 
        absensi a
    JOIN 
        pegawai p ON a.pegawai_id = p.id
    JOIN 
        users u ON p.user_id = u.id
    WHERE 
        a.tanggal BETWEEN :start_date AND :end_date  -- Menggunakan kolom tanggal untuk filter
    GROUP BY 
        u.id  -- Mengelompokkan hanya berdasarkan id pegawai
    ORDER BY 
        u.nama_lengkap ASC;
");

    // Mengikat parameter tanggal ke query
    $stmt->bindParam(':start_date', $start_date_with_time);
    $stmt->bindParam(':end_date', $end_date_with_time);
} else {
    // Menyiapkan query tanpa filter tanggal
    $stmt = $pdo->prepare("
        SELECT 
            u.nama_lengkap AS nama_staff,
            u.jenis_kelamin AS jenis_kelamin,
            SUM(CASE WHEN a.status_kehadiran IN ('hadir', 'terlambat', 'pulang_dahulu', 'tidak_absen_pulang') THEN 1 ELSE 0 END) AS hadir,
            SUM(CASE WHEN a.status_kehadiran = 'alpha' THEN 1 ELSE 0 END) AS alpha,
            SUM(CASE WHEN a.status_kehadiran = 'sakit' THEN 1 ELSE 0 END) AS sakit,
            SUM(CASE WHEN a.status_kehadiran = 'cuti' THEN 1 ELSE 0 END) AS cuti,
            SUM(CASE WHEN a.status_kehadiran = 'izin' THEN 1 ELSE 0 END) AS izin
        FROM 
            absensi a
        JOIN 
            pegawai p ON a.pegawai_id = p.id
        JOIN 
            users u ON p.user_id = u.id
        GROUP BY 
            u.id
        ORDER BY 
            u.nama_lengkap ASC;
    ");
}

// Eksekusi query setelah disiapkan
$stmt->execute();

// Mengambil hasil query
$attendanceDetails = $stmt->fetchAll(PDO::FETCH_ASSOC);


//PDF
if (isset($_GET['action'])) {
    $action = $_GET['action'];

    // Get date range from URL parameters
    $start_date = isset($_GET['start_date']) ? $_GET['start_date'] : null;
    $end_date = isset($_GET['end_date']) ? $_GET['end_date'] : null;

    // Prepare the query based on date range
    if (!empty($start_date) && !empty($end_date)) {
        $start_date_with_time = $start_date . ' 00:00:00';
        $end_date_with_time = $end_date . ' 23:59:59';

        $stmt = $pdo->prepare("
            SELECT 
                u.nama_lengkap AS nama_staff,
                u.jenis_kelamin AS jenis_kelamin,
                SUM(CASE WHEN a.status_kehadiran IN ('hadir', 'terlambat', 'pulang_dahulu', 'tidak_absen_pulang') THEN 1 ELSE 0 END) AS hadir,
                SUM(CASE WHEN a.status_kehadiran = 'alpha' THEN 1 ELSE 0 END) AS alpha,
                SUM(CASE WHEN a.status_kehadiran = 'sakit' THEN 1 ELSE 0 END) AS sakit,
                SUM(CASE WHEN a.status_kehadiran = 'cuti' THEN 1 ELSE 0 END) AS cuti,
                SUM(CASE WHEN a.status_kehadiran = 'izin' THEN 1 ELSE 0 END) AS izin
            FROM 
                absensi a
            JOIN 
                pegawai p ON a.pegawai_id = p.id
            JOIN 
                users u ON p.user_id = u.id
            WHERE 
                a.tanggal BETWEEN :start_date AND :end_date
            GROUP BY 
                u.id, u.nama_lengkap, u.jenis_kelamin
            ORDER BY 
                u.nama_lengkap ASC
        ");

        $stmt->bindParam(':start_date', $start_date_with_time);
        $stmt->bindParam(':end_date', $end_date_with_time);
    } else {
        // Query without date filter remains the same
        $stmt = $pdo->prepare("
            SELECT 
                u.nama_lengkap AS nama_staff,
                u.jenis_kelamin AS jenis_kelamin,
                SUM(CASE WHEN a.status_kehadiran IN ('hadir', 'terlambat', 'pulang_dahulu', 'tidak_absen_pulang') THEN 1 ELSE 0 END) AS hadir,
                SUM(CASE WHEN a.status_kehadiran = 'alpha' THEN 1 ELSE 0 END) AS alpha,
                SUM(CASE WHEN a.status_kehadiran = 'sakit' THEN 1 ELSE 0 END) AS sakit,
                SUM(CASE WHEN a.status_kehadiran = 'cuti' THEN 1 ELSE 0 END) AS cuti,
                SUM(CASE WHEN a.status_kehadiran = 'izin' THEN 1 ELSE 0 END) AS izin
            FROM 
                absensi a
            JOIN 
                pegawai p ON a.pegawai_id = p.id
            JOIN 
                users u ON p.user_id = u.id
            GROUP BY 
                u.id, u.nama_lengkap, u.jenis_kelamin
            ORDER BY 
                u.nama_lengkap ASC
        ");
    }

    $stmt->execute();
    $exportData = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($action === 'print') {
        // Configure Dompdf
        $options = new Options();
        $options->set('defaultFont', 'Courier');
        $dompdf = new Dompdf($options);

        // Add date range to the title if available
        $dateRangeTitle = '';
        if (!empty($start_date) && !empty($end_date)) {
            $dateRangeTitle = '<p style="text-align: left;">Periode: ' . date('d/m/Y', strtotime($start_date)) . ' - ' . date('d/m/Y', strtotime($end_date)) . '</p>';
        }

        $html = '
        <!DOCTYPE html>
        <html lang="id">
        <head>
            <meta charset="UTF-8">
            <title>Laporan Presensi</title>
            <style>
                body { font-family: Arial, sans-serif; }
                h1 { text-align: center; }
                table { width: 100%; border-collapse: collapse; margin-top: 20px; }
                th, td { border: 1px solid #000; padding: 8px; text-align: center; }
                th { background-color: #f2f2f2; }
            </style>
        </head>
        <body>
            <h1>Laporan Presensi Karyawan</h1>
            ' . $dateRangeTitle . '
            <table>
                <thead>
                    <tr>
                        <th>No.</th>
                        <th>Nama Karyawan</th>
                        <th>Jenis Kelamin</th>
                        <th>Hadir</th>
                        <th>Alpha</th>
                        <th>Sakit</th>
                        <th>Cuti</th>
                        <th>Izin</th>
                    </tr>
                </thead>
                <tbody>';

        if (!empty($exportData)) {
            $no = 1;
            foreach ($exportData as $row) {
                $html .= '<tr>';
                $html .= '<td>' . $no++ . '</td>';
                $html .= '<td>' . htmlspecialchars($row['nama_staff']) . '</td>';
                $html .= '<td>' . htmlspecialchars(ucwords($row['jenis_kelamin'])) . '</td>';
                $html .= '<td>' . htmlspecialchars($row['hadir']) . '</td>';
                $html .= '<td>' . htmlspecialchars($row['alpha']) . '</td>';
                $html .= '<td>' . htmlspecialchars($row['sakit']) . '</td>';
                $html .= '<td>' . htmlspecialchars($row['cuti']) . '</td>';
                $html .= '<td>' . htmlspecialchars($row['izin']) . '</td>';
                $html .= '</tr>';
            }
        } else {
            $html .= '<tr><td colspan="8" style="text-align: center;">Tidak Ada Data Presensi Karyawan</td></tr>';
        }

        $html .= '
                </tbody>
            </table>
        </body>
        </html>';

        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        $dompdf->stream('laporan_presensi.pdf', array('Attachment' => true));
        exit;

    } elseif ($action === 'excel') {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        // Add title and date range if available
        $sheet->setCellValue('A1', 'LAPORAN PRESENSI KARYAWAN');
        $sheet->mergeCells('A1:H1');

        $currentRow = 2;
        if (!empty($start_date) && !empty($end_date)) {
            $sheet->setCellValue('A2', 'Periode: ' . date('d/m/Y', strtotime($start_date)) . ' - ' . date('d/m/Y', strtotime($end_date)));
            $sheet->mergeCells('A2:H2');
            $currentRow = 3;
        }

        // Add headers
        $currentRow++; // Move to next row for headers
        $headers = ['No.', 'Nama Karyawan', 'Jenis Kelamin', 'Hadir', 'Alpha', 'Sakit', 'Cuti', 'Izin'];
        $sheet->fromArray($headers, NULL, 'A' . $currentRow);

        // Add data
        if (!empty($exportData)) {
            $dataRow = $currentRow + 1;
            $no = 1;
            foreach ($exportData as $data) {
                $sheet->setCellValue('A' . $dataRow, $no++);
                $sheet->setCellValue('B' . $dataRow, $data['nama_staff']);
                $sheet->setCellValue('C' . $dataRow, ucwords($data['jenis_kelamin']));
                $sheet->setCellValue('D' . $dataRow, $data['hadir']);
                $sheet->setCellValue('E' . $dataRow, $data['alpha']);
                $sheet->setCellValue('F' . $dataRow, $data['sakit']);
                $sheet->setCellValue('G' . $dataRow, $data['cuti']);
                $sheet->setCellValue('H' . $dataRow, $data['izin']);
                $dataRow++;
            }
            $lastRow = $dataRow - 1;
        } else {
            $dataRow = $currentRow + 1;
            $sheet->setCellValue('A' . $dataRow, 'Tidak Ada Data Presensi Karyawan');
            $sheet->mergeCells('A' . $dataRow . ':H' . $dataRow);
            $lastRow = $dataRow;
        }

        // Style the Excel file
        // Header style
        $headerRange = 'A' . $currentRow . ':H' . $currentRow;
        $sheet->getStyle($headerRange)->getFont()->setBold(true);
        $sheet->getStyle($headerRange)->getFont()->getColor()->setRGB(Color::COLOR_WHITE);
        $sheet->getStyle($headerRange)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID);
        $sheet->getStyle($headerRange)->getFill()->getStartColor()->setRGB('4F81BD');

        // Title style
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        if (!empty($start_date) && !empty($end_date)) {
            $sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        }

        // Auto-size columns
        foreach (range('A', 'H') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        // Set alignment for all data cells
        $sheet->getStyle('A' . $currentRow . ':H' . $lastRow)->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER);

        // Add borders to all cells
        $sheet->getStyle('A' . $currentRow . ':H' . $lastRow)->getBorders()
            ->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

        // Set headers for download
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="laporan_presensi.xlsx"');
        header('Cache-Control: max-age=0');

        // Save to output
        $writer = new Xlsx($spreadsheet);
        $writer->save('php://output');
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no" />
    <meta name="description" content="SIHADIR - Sistem Kehadiran Sing Long Brother Industrial" />
    <meta name="author" content="Sing Long Brother Industrial" />
    <title>SIHADIR | Laporan</title>
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
        .slb-card-heading {
            font-family: 'Sora', sans-serif;
            font-weight: 700;
            font-size: 15.5px;
            color: var(--slb-navy);
            margin-bottom: 4px;
        }

        /* ===== BUTTONS ===== */
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
        .btn-slb-pdf {
            background: linear-gradient(135deg, #ef4444, #c4263c);
            color: #fff;
            border: none;
            font-weight: 700;
            padding: 10px 20px;
            border-radius: 10px;
            font-size: 13.5px;
            transition: transform 0.2s, box-shadow 0.2s, opacity 0.2s;
            display: inline-flex;
            align-items: center;
        }
        .btn-slb-pdf:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 8px 18px rgba(224,51,77,0.3);
            color: #fff;
        }
        .btn-slb-excel {
            background: linear-gradient(135deg, #1aa260, #0d9d58);
            color: #fff;
            border: none;
            font-weight: 700;
            padding: 10px 20px;
            border-radius: 10px;
            font-size: 13.5px;
            transition: transform 0.2s, box-shadow 0.2s, opacity 0.2s;
            display: inline-flex;
            align-items: center;
        }
        .btn-slb-excel:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 8px 18px rgba(13,157,88,0.3);
            color: #fff;
        }
        .btn-slb-pdf:disabled, .btn-slb-excel:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none !important;
            box-shadow: none !important;
        }

        /* ===== FORM CONTROLS (outside modal) ===== */
        .label-slb {
            font-size: 12px;
            font-weight: 700;
            color: var(--slb-navy);
            text-transform: uppercase;
            letter-spacing: 0.4px;
            margin-bottom: 6px;
            display: block;
        }
        .control-slb {
            border: 1px solid #e2e6ee;
            border-radius: 10px;
            padding: 9px 14px;
            font-size: 13.5px;
            background: #fff;
            color: var(--slb-text);
            width: 100%;
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        .control-slb:focus {
            outline: none;
            border-color: var(--slb-amber);
            box-shadow: 0 0 0 3px rgba(245,166,35,0.15);
        }

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
            <a class="nav-item-slb" href="permit.php">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 -960 960 960" width="19" height="19" fill="currentColor">
                    <path d="M160-200v-440 440-15 15Zm0 80q-33 0-56.5-23.5T80-200v-440q0-33 23.5-56.5T160-720h160v-80q0-33 23.5-56.5T400-880h160q33 0 56.5 23.5T640-800v80h160q33 0 56.5 23.5T880-640v171q-18-13-38-22.5T800-508v-132H160v440h283q3 21 9 41t15 39H160Zm240-600h160v-80H400v80ZM720-40q-83 0-141.5-58.5T520-240q0-83 58.5-141.5T720-440q83 0 141.5 58.5T920-240q0 83-58.5 141.5T720-40Zm20-208v-112h-40v128l86 86 28-28-74-74Z" />
                </svg>
                Cuti & Perizinan
            </a>
            <a class="nav-item-slb active" href="report.php">
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
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-1">
                    <div>
                        <h1 class="page-heading-slb">Laporan Presensi</h1>
                        <p class="page-subheading-slb" style="margin-bottom: 0;">Rekap kehadiran karyawan, siap diunduh dalam format PDF atau Excel</p>
                    </div>
                    <div class="d-flex gap-2 flex-wrap">
                        <a id="pdfDownloadLink" href="#" class="pointer-events-none">
                            <button id="pdfDownloadBtn" class="btn-slb-pdf" disabled>
                                <i class="fa-solid fa-file-pdf me-2"></i>Download PDF
                            </button>
                        </a>
                        <a id="excelDownloadLink" href="#" class="pointer-events-none">
                            <button id="excelDownloadBtn" class="btn-slb-excel" disabled>
                                <i class="fa-solid fa-file-excel me-2"></i>Download Excel
                            </button>
                        </a>
                    </div>
                </div>

                <!-- Filter Section -->
                <div class="slb-card" style="margin-top: 22px;">
                    <div class="slb-card-heading">Filter Periode</div>
                    <p class="page-subheading-slb" style="margin-bottom: 16px;">Pilih rentang tanggal untuk menyaring data presensi</p>
                    <form method="POST" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>">
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
                            <div>
                                <label for="start_date" class="label-slb">Tanggal Mulai</label>
                                <input type="date" name="start_date" id="start_date" class="control-slb"
                                    min="<?php echo $minDate; ?>"
                                    max="<?php echo isset($end_date) ? $end_date : ''; ?>"
                                    value="<?php echo isset($start_date) ? $start_date : ''; ?>">
                            </div>
                            <div>
                                <label for="end_date" class="label-slb">Tanggal Akhir</label>
                                <input type="date" name="end_date" id="end_date" class="control-slb"
                                    min="<?php echo isset($start_date) ? $start_date : $minDate; ?>"
                                    max="<?php echo date('Y-m-d'); ?>"
                                    value="<?php echo isset($end_date) ? $end_date : ''; ?>">
                            </div>
                            <div class="d-flex align-items-end">
                                <button type="submit" class="btn-slb-primary w-100" style="justify-content: center; display: flex; align-items: center;">
                                    <i class="fa-solid fa-filter me-2"></i>Filter Data
                                </button>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- Table Section -->
                <div class="slb-card">
                    <div class="slb-card-heading" style="margin-bottom: 16px;">Detail Presensi Karyawan</div>
                    <div class="table-container custom-scrollbar overflow-x-auto">
                        <table id="reportTable" class="slb-table min-w-full">
                            <thead>
                                <tr>
                                    <th>Nama Karyawan</th>
                                    <th>Jenis Kelamin</th>
                                    <th>Hadir</th>
                                    <th>Alpha</th>
                                    <th>Sakit</th>
                                    <th>Cuti</th>
                                    <th>Izin</th>
                                </tr>
                            </thead>
                            <tbody id="reportTableBody">
                                <?php
                                if (!empty($attendanceDetails)) {
                                    foreach ($attendanceDetails as $detail) {
                                        echo "<tr>";
                                        echo "<td>" . htmlspecialchars($detail['nama_staff']) . "</td>";
                                        echo "<td>" . htmlspecialchars(ucwords($detail['jenis_kelamin'])) . "</td>";
                                        echo "<td>" . htmlspecialchars($detail['hadir']) . "</td>";
                                        echo "<td>" . htmlspecialchars($detail['alpha']) . "</td>";
                                        echo "<td>" . htmlspecialchars($detail['sakit']) . "</td>";
                                        echo "<td>" . htmlspecialchars($detail['cuti']) . "</td>";
                                        echo "<td>" . htmlspecialchars($detail['izin']) . "</td>";
                                        echo "</tr>";
                                    }
                                } else {
                                    echo "<tr><td colspan='7' style='color: var(--slb-muted); padding: 26px 16px;'>Tidak ada data presensi karyawan</td></tr>";
                                }
                                ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

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
        // Update batasan tanggal secara dinamis dengan JavaScript
        const startDateInput = document.getElementById('start_date');
        const endDateInput = document.getElementById('end_date');

        // Saat tanggal akhir berubah, perbarui max dari tanggal mulai
        endDateInput.addEventListener('change', () => {
            startDateInput.max = endDateInput.value;
        });

        // Saat tanggal mulai berubah, perbarui min dari tanggal akhir
        startDateInput.addEventListener('change', () => {
            endDateInput.min = startDateInput.value || "<?php echo $minDate; ?>";
        });
    </script>

    <script>
        // Add table row hover effect (CSS already handles this via .slb-table, kept for safety)
        document.querySelectorAll('#reportTable tbody tr').forEach(row => {
            row.addEventListener('mouseover', () => {
                row.style.background = '#fbfbfd';
            });
            row.addEventListener('mouseout', () => {
                row.style.background = '';
            });
        });
    </script>

    <!-- Bootstrap core JS-->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../../../assets/js/scripts.js"></script>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const filterForm = document.querySelector('form');
            const pdfDownloadLink = document.getElementById('pdfDownloadLink');
            const excelDownloadLink = document.getElementById('excelDownloadLink');
            const pdfDownloadBtn = document.getElementById('pdfDownloadBtn');
            const excelDownloadBtn = document.getElementById('excelDownloadBtn');

            // Dates with attendance data (provided by PHP)
            const minDate = "<?php echo $minDate; ?>"; // Earliest date with attendance data
            const maxDate = "<?php echo $maxDate; ?>"; // Latest date with attendance data

            // Set initial constraints
            startDateInput.min = minDate;
            startDateInput.max = maxDate;
            endDateInput.min = minDate;
            endDateInput.max = maxDate;

            // Utility function to disable download buttons
            function disableDownloadButtons() {
                pdfDownloadLink.href = '#';
                excelDownloadLink.href = '#';
                pdfDownloadLink.classList.add('pointer-events-none');
                excelDownloadLink.classList.add('pointer-events-none');
                pdfDownloadBtn.disabled = true;
                excelDownloadBtn.disabled = true;
            }

            // Utility function to enable download buttons
            function enableDownloadButtons(startDate, endDate) {
                pdfDownloadLink.href = `?action=print&start_date=${encodeURIComponent(startDate)}&end_date=${encodeURIComponent(endDate)}`;
                excelDownloadLink.href = `?action=excel&start_date=${encodeURIComponent(startDate)}&end_date=${encodeURIComponent(endDate)}`;
                pdfDownloadLink.classList.remove('pointer-events-none');
                excelDownloadLink.classList.remove('pointer-events-none');
                pdfDownloadBtn.disabled = false;
                excelDownloadBtn.disabled = false;
            }

            // Validate date inputs and enforce constraints
            function validateDateInputs() {
                if (!startDateInput.value) {
                    endDateInput.value = '';
                    return;
                }

                // Ensure start date is within valid range
                if (startDateInput.value < minDate) {
                    startDateInput.value = minDate;
                }
                if (startDateInput.value > maxDate) {
                    startDateInput.value = maxDate;
                }

                // Update end date constraints
                endDateInput.min = startDateInput.value;

                // Ensure end date is valid
                if (endDateInput.value) {
                    if (endDateInput.value < startDateInput.value) {
                        endDateInput.value = startDateInput.value;
                    }
                    if (endDateInput.value > maxDate) {
                        endDateInput.value = maxDate;
                    }
                }
            }

            // Event listener for start date input
            startDateInput.addEventListener('change', function () {
                validateDateInputs();
            });

            // Event listener for end date input
            endDateInput.addEventListener('change', function () {
                validateDateInputs();
            });

            // Prevent manual input of invalid dates
            function preventInvalidInput(input) {
                input.addEventListener('input', function () {
                    this.value = this.value.replace(/[^0-9-]/g, '');
                });
            }

            preventInvalidInput(startDateInput);
            preventInvalidInput(endDateInput);

            // Handle form submission
            filterForm.addEventListener('submit', function (e) {
                e.preventDefault();

                if (startDateInput.value && endDateInput.value) {
                    const startDate = startDateInput.value;
                    const endDate = endDateInput.value;

                    // Enable download buttons after successful filter
                    enableDownloadButtons(startDate, endDate);

                    // Simulate form submission (uncomment if real submission is needed)
                    // this.submit();
                } else {
                    alert('Silakan pilih tanggal yang valid sebelum mengirim filter.');
                }
            });

            // Initial setup on page load
            disableDownloadButtons();
        });
    </script>

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