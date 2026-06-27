<?php
session_start(); // Mulai session

// Cek apakah session 'setup' telah diset, dan jika tidak, redirect ke halaman login atau dashboard
if (!isset($_SESSION['setup']) || $_SESSION['setup'] !== true) {
    header('Location: login.php'); // Atau redirect ke halaman lain, misalnya dashboard jika login berhasil
    exit;
}

?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Si Hadir - Sistem Informasi Kehadiran</title>
    <link rel="icon" type="image/x-icon" href="assets/icon/favicon.ico" />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        *, *::before, *::after {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --blue-50: #eff6ff;
            --blue-100: #dbeafe;
            --blue-500: #3b82f6;
            --blue-600: #2563eb;
            --blue-700: #1d4ed8;
            --gray-50: #f9fafb;
            --gray-100: #f3f4f6;
            --gray-200: #e5e7eb;
            --gray-400: #9ca3af;
            --gray-500: #6b7280;
            --gray-600: #4b5563;
            --gray-700: #374151;
            --gray-800: #1f2937;
            --gray-900: #111827;
            --white: #ffffff;
            --radius-sm: 6px;
            --radius-md: 10px;
            --radius-lg: 14px;
            --radius-xl: 18px;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background-color: var(--gray-50);
            color: var(--gray-900);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
            line-height: 1.6;
        }

        .page {
            width: 100%;
            max-width: 760px;
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        /* ── Hero ── */
        .hero {
            background: var(--white);
            border: 1px solid var(--gray-200);
            border-radius: var(--radius-xl);
            padding: 3rem 2.5rem 2.5rem;
            text-align: center;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: var(--blue-50);
            color: var(--blue-600);
            border: 1px solid var(--blue-100);
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
            padding: 4px 12px;
            margin-bottom: 1.5rem;
        }

        .hero-title {
            font-size: clamp(2.5rem, 8vw, 3.75rem);
            font-weight: 600;
            letter-spacing: -2px;
            line-height: 1;
            margin-bottom: 0.5rem;
            color: var(--gray-900);
        }

        .hero-title span {
            color: var(--blue-600);
        }

        .hero-tagline {
            font-size: 13px;
            font-weight: 500;
            color: var(--gray-400);
            letter-spacing: 2px;
            text-transform: uppercase;
            margin-bottom: 1.25rem;
        }

        .hero-desc {
            font-size: 15px;
            color: var(--gray-500);
            max-width: 400px;
            margin: 0 auto 2rem;
            line-height: 1.7;
        }

        .hero-actions {
            display: flex;
            gap: 10px;
            justify-content: center;
            flex-wrap: wrap;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 0 20px;
            height: 42px;
            border-radius: var(--radius-md);
            font-size: 14px;
            font-weight: 500;
            text-decoration: none;
            cursor: pointer;
            border: none;
            transition: all 0.15s ease;
            white-space: nowrap;
        }

        .btn-primary {
            background: var(--blue-600);
            color: var(--white);
        }

        .btn-primary:hover {
            background: var(--blue-700);
            transform: translateY(-1px);
        }

        .btn-secondary {
            background: var(--white);
            color: var(--gray-700);
            border: 1px solid var(--gray-200);
        }

        .btn-secondary:hover {
            background: var(--gray-50);
            border-color: var(--gray-300);
            transform: translateY(-1px);
        }

        /* ── Stats row ── */
        .stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1rem;
        }

        .stat-card {
            background: var(--white);
            border: 1px solid var(--gray-200);
            border-radius: var(--radius-lg);
            padding: 1.25rem 1rem;
            text-align: center;
        }

        .stat-icon {
            width: 40px;
            height: 40px;
            background: var(--blue-50);
            border-radius: var(--radius-md);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 0.6rem;
            font-size: 18px;
            color: var(--blue-600);
        }

        .stat-label {
            font-size: 12px;
            color: var(--gray-500);
            font-weight: 500;
        }

        /* ── Features ── */
        .features {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 1rem;
        }

        .feature-card {
            background: var(--white);
            border: 1px solid var(--gray-200);
            border-radius: var(--radius-lg);
            padding: 1.25rem;
            transition: border-color 0.15s ease, transform 0.15s ease;
        }

        .feature-card:hover {
            border-color: var(--blue-500);
            transform: translateY(-2px);
        }

        .feature-icon {
            width: 36px;
            height: 36px;
            border-radius: var(--radius-sm);
            background: var(--blue-50);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            color: var(--blue-600);
            margin-bottom: 0.75rem;
        }

        .feature-card h3 {
            font-size: 13px;
            font-weight: 600;
            color: var(--gray-800);
            margin-bottom: 0.3rem;
        }

        .feature-card p {
            font-size: 12px;
            color: var(--gray-500);
            line-height: 1.55;
        }

        /* ── How to start ── */
        .how-to {
            background: var(--white);
            border: 1px solid var(--gray-200);
            border-radius: var(--radius-lg);
            padding: 1.5rem;
        }

        .section-label {
            font-size: 11px;
            font-weight: 600;
            color: var(--gray-400);
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 1.1rem;
        }

        .steps {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .step {
            display: flex;
            align-items: center;
            gap: 9px;
            background: var(--gray-50);
            border: 1px solid var(--gray-200);
            border-radius: var(--radius-md);
            padding: 9px 13px;
            flex: 1;
            min-width: 130px;
        }

        .step-num {
            width: 22px;
            height: 22px;
            border-radius: 50%;
            background: var(--blue-600);
            color: var(--white);
            font-size: 11px;
            font-weight: 600;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .step-text {
            font-size: 12px;
            color: var(--gray-600);
            font-weight: 500;
            line-height: 1.3;
        }

        .step-arrow {
            color: var(--gray-300);
            font-size: 12px;
            flex-shrink: 0;
        }

        /* ── CTA Footer ── */
        .cta-footer {
            background: var(--white);
            border: 1px solid var(--gray-200);
            border-radius: var(--radius-lg);
            padding: 1.75rem 1.5rem;
            text-align: center;
        }

        .cta-footer .hero-actions {
            margin-bottom: 1rem;
        }

        .cta-note {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 12px;
            color: var(--gray-400);
        }

        /* ── Responsive ── */
        @media (max-width: 560px) {
            body { padding: 1rem; }

            .hero { padding: 2rem 1.5rem; }

            .stats { grid-template-columns: repeat(3, 1fr); gap: 0.6rem; }

            .stat-card { padding: 1rem 0.6rem; }

            .features { grid-template-columns: 1fr 1fr; }

            .steps { flex-direction: column; align-items: stretch; }

            .step-arrow { display: none; }

            .step { min-width: unset; }

            .btn { flex: 1; justify-content: center; min-width: 140px; }
        }

        @media (max-width: 380px) {
            .features { grid-template-columns: 1fr; }
            .stats { grid-template-columns: 1fr; }
        }
    </style>
</head>

<body>
    <main class="page">

        <!-- Hero -->
        <section class="hero">
            <div class="badge">
                <i class="fas fa-bolt"></i>
                Sistem Presensi Modern
            </div>
            <h1 class="hero-title">Si <span>Hadir</span></h1>
            <p class="hero-tagline">Sing Long Brother</p>
            <p class="hero-desc">
                Kelola kehadiran karyawan dengan mudah, cepat, dan akurat — langsung dari genggaman tangan Anda.
            </p>
            <div class="hero-actions">
                <a href="agreement.php" class="btn btn-primary">
                    <i class="fas fa-user-plus"></i>
                    Daftar sebagai owner
                </a>
                <a href="/build/si_hadir.apk" class="btn btn-secondary" download>
                    <i class="fas fa-download"></i>
                    Download aplikasi
                </a>
            </div>
        </section>

        <!-- Stats row -->
        <div class="stats">
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-mobile-alt"></i></div>
                <div class="stat-label">Presensi mobile</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-clock"></i></div>
                <div class="stat-label">Real-time</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-shield-alt"></i></div>
                <div class="stat-label">Data aman</div>
            </div>
        </div>

        <!-- Features -->
        <div class="features">
            <div class="feature-card">
                <div class="feature-icon"><i class="fas fa-mobile-alt"></i></div>
                <h3>Presensi mobile</h3>
                <p>Absen kapan saja lewat aplikasi Android yang ringan dan mudah digunakan.</p>
            </div>
            <div class="feature-card">
                <div class="feature-icon"><i class="fas fa-chart-line"></i></div>
                <h3>Real-time tracking</h3>
                <p>Pantau kehadiran seluruh karyawan secara langsung via dashboard.</p>
            </div>
            <div class="feature-card">
                <div class="feature-icon"><i class="fas fa-lock"></i></div>
                <h3>Keamanan data</h3>
                <p>Data kehadiran disimpan dengan standar keamanan tinggi.</p>
            </div>
            <div class="feature-card">
                <div class="feature-icon"><i class="fas fa-users"></i></div>
                <h3>Manajemen tim</h3>
                <p>Daftarkan seluruh karyawan dan kelola data mereka dalam satu tempat.</p>
            </div>
        </div>

        <!-- How to start -->
        <div class="how-to">
            <p class="section-label">Cara mulai</p>
            <div class="steps">
                <div class="step">
                    <div class="step-num">1</div>
                    <span class="step-text">Daftar sebagai owner</span>
                </div>
                <span class="step-arrow"><i class="fas fa-arrow-right"></i></span>
                <div class="step">
                    <div class="step-num">2</div>
                    <span class="step-text">Download aplikasi Android</span>
                </div>
                <span class="step-arrow"><i class="fas fa-arrow-right"></i></span>
                <div class="step">
                    <div class="step-num">3</div>
                    <span class="step-text">Daftarkan karyawan</span>
                </div>
                <span class="step-arrow"><i class="fas fa-arrow-right"></i></span>
                <div class="step">
                    <div class="step-num">4</div>
                    <span class="step-text">Mulai presensi!</span>
                </div>
            </div>
        </div>

        <!-- CTA footer -->
        <div class="cta-footer">
            <div class="hero-actions">
                <a href="agreement.php" class="btn btn-primary">
                    <i class="fas fa-user-plus"></i>
                    Daftar sebagai owner
                </a>
                <a href="/build/si_hadir.apk" class="btn btn-secondary" download>
                    <i class="fas fa-download"></i>
                    Download aplikasi
                </a>
            </div>
            <p class="cta-note">
                <i class="fas fa-info-circle"></i>
                Daftar sebagai owner terlebih dahulu untuk mendaftarkan karyawan Anda
            </p>
        </div>

    </main>
</body>

</html>