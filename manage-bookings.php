<?php
require_once 'config.php';

if (!isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$user = getCurrentUser();
$pending_requests_count = getPendingRequestCount(); // Untuk sidebar

// Semua logika perhitungan gaji/pemesanan telah dihapus untuk mengimplementasikan mode maintenance.
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kelola Pemesanan - UNDER MAINTENANCE</title>
    <link rel="icon" href="LOGO_WOT.png" type="image/png">
    <link rel="shortcut icon" href="favicon.ico" type="image/x-icon">
    <link rel="stylesheet" href="style.css">
    <style>
        /* === MODERN UI OVERRIDES (Night Beach Theme) === */
        .main-content {
            background: linear-gradient(135deg, #0f172a 0%, #1e1b4b 100%) !important;
            color: #f8f9fa;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        /* --- Maintenance Container --- */
        .maintenance-container {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            flex-grow: 1;
            padding: 2rem;
            text-align: center;
        }

        /* --- Maintenance Card (Glassmorphism) --- */
        .maintenance-card-modern {
            background: linear-gradient(145deg, rgba(30, 41, 59, 0.6), rgba(15, 23, 42, 0.8));
            backdrop-filter: blur(16px);
            border: 1px solid rgba(245, 158, 11, 0.3); /* Yellow/Warning Border Accent */
            border-top: 4px solid #f59e0b;
            border-radius: 24px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.4), inset 0 0 20px rgba(245, 158, 11, 0.05);
            padding: 3rem 2.5rem;
            max-width: 550px;
            width: 100%;
            animation: slideUp 0.6s ease-out backwards;
            position: relative;
            overflow: hidden;
        }

        .maintenance-card-modern::after {
            content: ''; position: absolute; top: -50%; left: -50%; width: 200%; height: 200%;
            background: radial-gradient(circle, rgba(245, 158, 11, 0.08), transparent 60%);
            pointer-events: none; z-index: 0;
        }

        /* --- Content Elements --- */
        .maintenance-icon-wrapper {
            background: rgba(245, 158, 11, 0.15);
            border: 1px solid rgba(245, 158, 11, 0.3);
            width: 100px; height: 100px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 3.5rem; margin: 0 auto 1.5rem auto;
            box-shadow: 0 0 20px rgba(245, 158, 11, 0.2);
            position: relative; z-index: 2;
            animation: pulse-warning 2s infinite;
        }

        .maintenance-title {
            font-size: 2.2rem;
            font-weight: 800;
            color: #fff;
            margin-bottom: 1rem;
            letter-spacing: -0.02em;
            position: relative; z-index: 2;
        }

        .maintenance-message {
            font-size: 1.05rem;
            color: #cbd5e1;
            line-height: 1.6;
            margin-bottom: 2rem;
            position: relative; z-index: 2;
        }
        
        .maintenance-message strong {
            color: #fde68a;
            font-weight: 700;
        }

        /* --- Modern Button --- */
        .btn-modern-return {
            background: linear-gradient(135deg, #3b82f6, #2563eb);
            color: #fff;
            border: none;
            padding: 1rem 2rem;
            border-radius: 14px;
            font-size: 1rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            text-decoration: none;
            box-shadow: 0 8px 20px rgba(59, 130, 246, 0.3);
            position: relative; z-index: 2;
        }

        .btn-modern-return:hover {
            transform: translateY(-3px);
            box-shadow: 0 12px 25px rgba(59, 130, 246, 0.5);
            color: #fff;
        }

        /* --- Animations --- */
        @keyframes slideUp { 
            from { opacity: 0; transform: translateY(40px); } 
            to { opacity: 1; transform: translateY(0); } 
        }
        @keyframes pulse-warning {
            0% { transform: scale(1); box-shadow: 0 0 15px rgba(245, 158, 11, 0.2); }
            50% { transform: scale(1.05); box-shadow: 0 0 30px rgba(245, 158, 11, 0.5); }
            100% { transform: scale(1); box-shadow: 0 0 15px rgba(245, 158, 11, 0.2); }
        }

        @media (max-width: 768px) {
            .maintenance-card-modern { padding: 2rem 1.5rem; }
            .maintenance-title { font-size: 1.8rem; }
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <?php include 'includes/header.php'; ?>
        <?php include 'includes/sidebar.php'; ?>

        <main class="main-content">
            <div class="maintenance-container">
                <div class="maintenance-card-modern">
                    <div class="maintenance-icon-wrapper">
                        🚧
                    </div>
                    <h2 class="maintenance-title">Sedang Dalam Perawatan</h2>
                    <p class="maintenance-message">
                        Mohon maaf atas ketidaknyamanan ini.<br>
                        Sistem pengelolaan pemesanan (Bookings) sedang dalam tahap peningkatan dan pemeliharaan.
                    </p>
                    <p class="maintenance-message" style="font-size: 0.9rem; padding-bottom: 0.5rem;">
                        Silakan hubungi pihak <strong>Developers By Rinaldi Production</strong> untuk informasi lebih lanjut.
                    </p>
                    <a href="dashboard.php" class="btn-modern-return">
                        <span>⬅️</span> Kembali ke Dashboard
                    </a>
                </div>
            </div>
        </main>
    </div>

    <script src="script.js"></script>
</body>
</html>