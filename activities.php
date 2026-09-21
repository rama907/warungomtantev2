<?php
require_once 'config.php';

if (!isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$user = getCurrentUser();

// Ambil jumlah permohonan pending untuk indikator sidebar
$pending_requests_count = getPendingRequestCount();

// Pastikan fungsi formatDuration ada
if (!function_exists('formatDuration')) {
    function formatDuration($minutes) {
        if ($minutes < 0) return "0j 0m"; 
        $hours = floor($minutes / 60);
        $remainingMinutes = $minutes % 60;
        return "{$hours}j {$remainingMinutes}m";
    }
}

if (!function_exists('getRoleDisplayName')) {
    function getRoleDisplayName($role) {
        $roles = [
            'ceo' => 'CEO',
            'direktur' => 'Direktur',
            'wakil_direktur' => 'Wakil Direktur',
            'manager' => 'Manager',
            'chef' => 'Chef',
            'waiters' => 'Waiters',
            'karyawan' => 'Karyawan',
            'magang' => 'Magang',
        ];
        return $roles[$role] ?? ucfirst(str_replace('_', ' ', $role));
    }
}

$success_message = null;
$error_message = null;

// --- Handle Delete Duty Log ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_duty_log') {
    $duty_log_id = (int)($_POST['duty_log_id'] ?? 0);
    $employee_id_of_log = $user['id']; 

    if ($duty_log_id <= 0) {
        $error_message = "ID log duty tidak valid!";
    } else {
        $conn->begin_transaction();
        try {
            // Ambil detail log
            $stmt_get_log = $conn->prepare("SELECT id, duty_start, duty_end, duration_minutes, status FROM duty_logs WHERE id = ? AND employee_id = ?");
            $stmt_get_log->bind_param("ii", $duty_log_id, $employee_id_of_log);
            $stmt_get_log->execute();
            $log_details = $stmt_get_log->get_result()->fetch_assoc();
            $stmt_get_log->close();

            if (!$log_details) { throw new Exception("Log duty tidak ditemukan."); }

            // Jika log active, matikan status on_duty karyawan
            if ($log_details['status'] === 'active') {
                $stmt = $conn->prepare("UPDATE employees SET is_on_duty = FALSE, current_duty_start = NULL WHERE id = ?");
                $stmt->bind_param("i", $employee_id_of_log);
                $stmt->execute();
                $stmt->close();
            }

            // Hapus log
            $stmt_delete = $conn->prepare("DELETE FROM duty_logs WHERE id = ? AND employee_id = ?");
            $stmt_delete->bind_param("ii", $duty_log_id, $employee_id_of_log);
            
            if ($stmt_delete->execute() && $stmt_delete->affected_rows > 0) {
                $conn->commit();
                $success_message = "Log jam kerja tanggal " . date('d/m/Y H:i', strtotime($log_details['duty_start'])) . " berhasil dihapus.";
                
                // Notifikasi Discord
                sendDiscordNotification([
                    'employee_name' => $user['name'], 
                    'admin_name' => $user['name'], 
                    'duty_start' => $log_details['duty_start'],
                    'duty_end' => $log_details['duty_end'],
                    'duration_minutes' => $log_details['duration_minutes']
                ], 'duty_log_deleted');

            } else {
                throw new Exception("Gagal menghapus log duty.");
            }
            $stmt_delete->close();

        } catch (Exception $e) {
            $conn->rollback();
            $error_message = "Terjadi kesalahan: " . $e->getMessage();
        }
        header("Location: activities.php?msg=" . urlencode($success_message ?? $error_message) . "&type=" . urlencode(isset($success_message) ? 'success' : 'error'));
        exit;
    }
}

// Menampilkan pesan feedback
if (isset($_GET['msg']) && isset($_GET['type'])) {
    if ($_GET['type'] === 'success') {
        $success_message = htmlspecialchars($_GET['msg']);
    } else {
        $error_message = htmlspecialchars($_GET['msg']);
    }
}

// Get activities logs
$stmt = $conn->prepare("SELECT * FROM duty_logs WHERE employee_id = ? ORDER BY duty_start DESC LIMIT 50");
$stmt->bind_param("i", $user['id']);
$stmt->execute();
$activities = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close(); 

// Total Jam Kerja
$stmt = $conn->prepare("SELECT SUM(duration_minutes) as total_minutes FROM duty_logs WHERE employee_id = ? AND status = 'completed'");
$stmt->bind_param("i", $user['id']);
$stmt->execute();
$total_duty_minutes = $stmt->get_result()->fetch_assoc()['total_minutes'] ?? 0;
$stmt->close();

// === RINGKASAN DATA PENJUALAN (SALES_DATA) ===
$sales_summary = [
    'abdul' => 0, 'lacosa' => 0, 'lakse' => 0, 'saiyo' => 0, 'woku' => 0, 'hp' => 0, 'radio' => 0
];
$stmt = $conn->prepare("
    SELECT 
        SUM(paket_abdul) as abdul, SUM(paket_lacosa) as lacosa, 
        SUM(paket_lakse) as lakse, SUM(paket_saiyo) as saiyo, 
        SUM(paket_woku) as woku, SUM(hp) as hp, SUM(radio) as radio
    FROM sales_data WHERE employee_id = ?
");
$stmt->bind_param("i", $user['id']);
$stmt->execute();
$res_sales = $stmt->get_result()->fetch_assoc();
if ($res_sales) {
    foreach ($res_sales as $k => $v) $sales_summary[$k] = (int)$v;
}
$stmt->close();
$total_sales_count = array_sum($sales_summary);

// === RINGKASAN DATA MASAK (COOKING_DATA) ===
$cooking_summary = [
    'abdul' => 0, 'lacosa' => 0, 'lakse' => 0, 'saiyo' => 0, 'woku' => 0
];
$stmt = $conn->prepare("
    SELECT 
        SUM(paket_abdul) as abdul, SUM(paket_lacosa) as lacosa, 
        SUM(paket_lakse) as lakse, SUM(paket_saiyo) as saiyo, 
        SUM(paket_woku) as woku
    FROM cooking_data WHERE employee_id = ?
");
$stmt->bind_param("i", $user['id']);
$stmt->execute();
$res_cook = $stmt->get_result()->fetch_assoc();
if ($res_cook) {
    foreach ($res_cook as $k => $v) $cooking_summary[$k] = (int)$v;
}
$stmt->close();
$total_cooking_count = array_sum($cooking_summary);

?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Aktivitas Saya - Warung Om Tante V2</title>
    <link rel="icon" href="LOGO_WOT.png" type="image/png">
    <link rel="shortcut icon" href="favicon.ico" type="image/x-icon">
    <link rel="stylesheet" href="style.css">
    <style>
        /* === MODERN UI OVERRIDES (Night Beach Theme) === */
        
        .main-content {
            background: linear-gradient(135deg, #0f172a 0%, #1e1b4b 100%) !important;
            color: #f8f9fa;
            min-height: 100vh;
        }

        /* --- Modern Page Header --- */
        .modern-page-header {
            background: linear-gradient(145deg, rgba(30, 41, 59, 0.7), rgba(15, 23, 42, 0.9)) !important;
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.08) !important;
            border-radius: 24px;
            box-shadow: 0 15px 35px rgba(0,0,0,0.3) !important;
            padding: 2rem 2.5rem;
            margin-bottom: 2rem;
            animation: slideDown 0.6s ease-out backwards;
            position: relative;
            overflow: hidden;
        }

        .modern-page-header::after {
            content: '';
            position: absolute;
            top: -20%; right: -5%;
            width: 300px; height: 300px;
            background: radial-gradient(circle, rgba(59, 130, 246, 0.15), transparent 70%);
            pointer-events: none;
            z-index: 0;
        }

        .header-content-wrapper {
            display: flex;
            align-items: center;
            gap: 1.25rem;
            position: relative;
            z-index: 2;
        }

        .header-icon-wrapper {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            width: 60px; height: 60px;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8rem;
            box-shadow: inset 0 0 15px rgba(0,0,0,0.3);
            flex-shrink: 0;
        }

        .header-text-wrapper {
            display: flex;
            flex-direction: column;
        }

        .modern-page-header h1 {
            color: #fff;
            font-weight: 800;
            font-size: 2.2rem;
            letter-spacing: -0.02em;
            margin: 0;
        }

        .modern-page-header p {
            color: var(--text-secondary);
            margin: 0.25rem 0 0 0;
            font-size: 1.05rem;
        }

        /* --- Stats Grid Modern --- */
        .stats-grid-modern {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card-modern {
            background: rgba(30, 41, 59, 0.6);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 24px;
            padding: 1.5rem;
            box-shadow: 0 10px 25px rgba(0,0,0,0.2);
            transition: transform 0.3s, box-shadow 0.3s;
            display: flex;
            flex-direction: column;
            animation: slideUp 0.6s ease-out backwards;
        }
        
        .stat-card-modern:nth-child(1) { animation-delay: 0.1s; }
        .stat-card-modern:nth-child(2) { animation-delay: 0.2s; }
        .stat-card-modern:nth-child(3) { animation-delay: 0.3s; }

        .stat-card-modern:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 35px rgba(0,0,0,0.4);
            border-color: rgba(255, 255, 255, 0.15);
        }

        .stat-header {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 1rem;
        }

        .stat-icon-wrapper {
            width: 50px; height: 50px;
            border-radius: 14px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.5rem;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .stat-title {
            display: flex;
            flex-direction: column;
        }
        .stat-title h4 {
            margin: 0; font-size: 0.9rem; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 0.05em; font-weight: 600;
        }
        .stat-title .stat-main-val {
            margin: 0; font-size: 1.6rem; font-weight: 800; color: #fff;
        }

        .stat-breakdown-modern {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
            margin-top: 10px;
            border-top: 1px dashed rgba(255,255,255,0.1);
            padding-top: 15px;
        }

        .breakdown-pill {
            background: rgba(0, 0, 0, 0.25);
            border: 1px solid rgba(255, 255, 255, 0.05);
            border-radius: 10px;
            padding: 6px 12px;
            font-size: 0.8rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            color: var(--text-secondary);
        }
        .breakdown-pill b {
            color: #fff;
            font-size: 0.9rem;
        }

        /* --- Cards (Glassmorphism) for Table --- */
        .modern-card {
            background: rgba(30, 41, 59, 0.6) !important;
            backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.08) !important;
            border-radius: 24px;
            box-shadow: 0 15px 35px rgba(0,0,0,0.3) !important;
            padding: 2rem;
            animation: slideUp 0.6s ease-out 0.4s backwards;
            margin-bottom: 2rem;
        }

        .modern-card-header h3 {
            color: #fff; font-size: 1.25rem; font-weight: 700; margin-bottom: 1.5rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1); padding-bottom: 0.75rem;
            display: flex; align-items: center; gap: 10px;
        }

        .modern-info-box {
            background: rgba(245, 158, 11, 0.1);
            border: 1px solid rgba(245, 158, 11, 0.3);
            border-radius: 12px; padding: 1rem 1.25rem; color: #fcd34d;
            font-size: 0.95rem; display: flex; align-items: center; gap: 10px;
            margin-bottom: 1.5rem;
        }

        /* --- Modern Table --- */
        .modern-table-wrapper {
            background: rgba(0, 0, 0, 0.25);
            border-radius: 16px;
            border: 1px solid rgba(255, 255, 255, 0.05);
            overflow-x: auto;
        }

        .modern-table {
            width: 100%;
            border-collapse: collapse;
            color: #e2e8f0;
            text-align: left;
        }

        .modern-table th {
            background: rgba(255, 255, 255, 0.05);
            padding: 1.2rem 1rem;
            font-weight: 600;
            color: #94a3b8;
            text-transform: uppercase;
            font-size: 0.8rem;
            letter-spacing: 0.05em;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            white-space: nowrap;
        }

        .modern-table td {
            padding: 1rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.03);
            vertical-align: middle;
            font-size: 0.95rem;
        }

        .modern-table tr:last-child td { border-bottom: none; }
        .modern-table tr:hover td { background: rgba(255, 255, 255, 0.03); }

        /* Highlight untuk jam kerja panjang */
        .modern-table tr.long-duty-row-modern td {
            background: rgba(245, 158, 11, 0.08);
        }
        .modern-table tr.long-duty-row-modern td:first-child {
            position: relative;
        }
        .modern-table tr.long-duty-row-modern td:first-child::before {
            content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 4px; background: var(--warning-color);
        }

        .alert-badge {
            background: rgba(245, 158, 11, 0.2); color: #fbbf24; border: 1px solid rgba(245, 158, 11, 0.4);
            padding: 2px 6px; border-radius: 6px; font-size: 0.7rem; font-weight: 800; margin-left: 8px; vertical-align: middle;
        }

        /* --- Badges --- */
        .badge-pill {
            display: inline-flex; align-items: center; padding: 4px 10px; border-radius: 20px;
            font-size: 0.8rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em;
        }
        .b-info { background: rgba(59, 130, 246, 0.15); color: #60a5fa; border: 1px solid rgba(59, 130, 246, 0.3); }
        .b-warning { background: rgba(245, 158, 11, 0.15); color: #fbbf24; border: 1px solid rgba(245, 158, 11, 0.3); }
        .b-primary { background: rgba(168, 85, 247, 0.15); color: #c084fc; border: 1px solid rgba(168, 85, 247, 0.3); }
        
        .b-completed { background: rgba(16, 185, 129, 0.15); color: #34d399; border: 1px solid rgba(16, 185, 129, 0.3); }
        .b-active { background: rgba(239, 68, 68, 0.15); color: #f87171; border: 1px solid rgba(239, 68, 68, 0.3); animation: pulse-red 2s infinite; }
        .b-pending { background: rgba(245, 158, 11, 0.15); color: #fbbf24; border: 1px solid rgba(245, 158, 11, 0.3); }

        @keyframes pulse-red { 0% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.4); } 70% { box-shadow: 0 0 0 6px rgba(239, 68, 68, 0); } 100% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0); } }

        /* Button Hapus Modern */
        .btn-del-modern {
            background: rgba(239, 68, 68, 0.1); color: #f87171; border: 1px solid rgba(239, 68, 68, 0.3);
            padding: 6px 12px; border-radius: 8px; cursor: pointer; font-weight: 600; font-size: 0.8rem; transition: all 0.2s;
        }
        .btn-del-modern:hover { background: #ef4444; color: #fff; box-shadow: 0 4px 10px rgba(239, 68, 68, 0.3); }

        /* --- Alerts --- */
        .error-alert { background: rgba(220, 53, 69, 0.15); border: 1px solid rgba(220, 53, 69, 0.4); color: #fca5a5; padding: 1rem 1.2rem; border-radius: 14px; margin-bottom: 1.5rem; display: flex; align-items: center; gap: 0.75rem; font-size: 0.95rem; font-weight: 500; animation: shake 0.5s ease-in-out; }
        .success-alert { background: rgba(52, 211, 153, 0.15); border: 1px solid rgba(52, 211, 153, 0.4); color: #6ee7b7; padding: 1rem 1.2rem; border-radius: 14px; margin-bottom: 1.5rem; display: flex; align-items: center; gap: 0.75rem; font-size: 0.95rem; font-weight: 500; animation: slideDown 0.4s ease-out; }

        @keyframes slideDown { from { opacity: 0; transform: translateY(-20px); } to { opacity: 1; transform: translateY(0); } }
        @keyframes slideUp { from { opacity: 0; transform: translateY(30px); } to { opacity: 1; transform: translateY(0); } }
        @keyframes shake { 0%, 100% { transform: translateX(0); } 25% { transform: translateX(-5px); } 75% { transform: translateX(5px); } }

        /* Responsive Table */
        @media (max-width: 768px) {
            .modern-page-header { padding: 1.5rem; }
            .header-content-wrapper { flex-direction: column; text-align: center; gap: 1rem; }
            .modern-page-header h1 { font-size: 1.8rem; }
            .modern-page-header p { margin: 0; }
            
            .modern-table thead { display: none; }
            .modern-table tr { display: block; border-bottom: 2px solid rgba(255,255,255,0.1); margin-bottom: 10px; padding-bottom: 10px; }
            .modern-table td { display: flex; justify-content: space-between; align-items: center; padding: 0.5rem 1rem; border-bottom: none; text-align: right; }
            .modern-table td::before { content: attr(data-label); font-weight: 600; color: #94a3b8; text-transform: uppercase; font-size: 0.75rem; margin-right: 15px; }
            .modern-table tr.long-duty-row-modern td:first-child::before { display: none; } /* hide accent line on mobile td */
            .modern-table tr.long-duty-row-modern { border-left: 4px solid var(--warning-color); border-radius: 8px; }
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <?php include 'includes/header.php'; ?>
        <?php include 'includes/sidebar.php'; ?>

        <main class="main-content">
            
            <div class="modern-page-header">
                <div class="header-content-wrapper">
                    <div class="header-icon-wrapper">📊</div>
                    <div class="header-text-wrapper">
                        <h1>Aktivitas Saya</h1>
                        <p>Riwayat aktivitas jam kerja dan ringkasan performa Anda.</p>
                    </div>
                </div>
            </div>

            <?php if (isset($success_message)): ?>
                <div class="success-alert"><span>🎉</span> <?= htmlspecialchars($success_message) ?></div>
            <?php endif; ?>
            <?php if (isset($error_message)): ?>
                <div class="error-alert"><span>⚠️</span> <?= htmlspecialchars($error_message) ?></div>
            <?php endif; ?>

            <div class="stats-grid-modern">
                
                <div class="stat-card-modern">
                    <div class="stat-header">
                        <div class="stat-icon-wrapper" style="color: #60a5fa; border-color: rgba(59, 130, 246, 0.3);">⏰</div>
                        <div class="stat-title">
                            <h4>Total Jam Kerja</h4>
                            <p class="stat-main-val"><?= formatDuration($total_duty_minutes) ?></p>
                        </div>
                    </div>
                </div>

                <div class="stat-card-modern">
                    <div class="stat-header">
                        <div class="stat-icon-wrapper" style="color: #34d399; border-color: rgba(16, 185, 129, 0.3);">💰</div>
                        <div class="stat-title">
                            <h4>Total Penjualan</h4>
                            <p class="stat-main-val"><?= $total_sales_count ?> <span style="font-size: 1rem; font-weight: 500; color: var(--text-muted);">Item</span></p>
                        </div>
                    </div>
                    <div class="stat-breakdown-modern">
                        <div class="breakdown-pill">Abdul <b><?= $sales_summary['abdul'] ?></b></div>
                        <div class="breakdown-pill">Lacosa <b><?= $sales_summary['lacosa'] ?></b></div>
                        <div class="breakdown-pill">Lakse <b><?= $sales_summary['lakse'] ?></b></div>
                        <div class="breakdown-pill">Saiyo <b><?= $sales_summary['saiyo'] ?></b></div>
                        <div class="breakdown-pill">Woku <b><?= $sales_summary['woku'] ?></b></div>
                        <div class="breakdown-pill" style="border-color: rgba(59,130,246,0.3); color: #93c5fd;">HP <b><?= $sales_summary['hp'] ?></b></div>
                        <div class="breakdown-pill" style="border-color: rgba(59,130,246,0.3); color: #93c5fd;">Radio <b><?= $sales_summary['radio'] ?></b></div>
                    </div>
                </div>

                <div class="stat-card-modern">
                    <div class="stat-header">
                        <div class="stat-icon-wrapper" style="color: #fbbf24; border-color: rgba(245, 158, 11, 0.3);">🍳</div>
                        <div class="stat-title">
                            <h4>Total Masak</h4>
                            <p class="stat-main-val"><?= $total_cooking_count ?> <span style="font-size: 1rem; font-weight: 500; color: var(--text-muted);">Paket</span></p>
                        </div>
                    </div>
                    <div class="stat-breakdown-modern">
                        <div class="breakdown-pill">Abdul <b><?= $cooking_summary['abdul'] ?></b></div>
                        <div class="breakdown-pill">Lacosa <b><?= $cooking_summary['lacosa'] ?></b></div>
                        <div class="breakdown-pill">Lakse <b><?= $cooking_summary['lakse'] ?></b></div>
                        <div class="breakdown-pill">Saiyo <b><?= $cooking_summary['saiyo'] ?></b></div>
                        <div class="breakdown-pill">Woku <b><?= $cooking_summary['woku'] ?></b></div>
                    </div>
                </div>

            </div>

            <div class="modern-card">
                <div class="modern-card-header">
                    <h3><span>🕰️</span> Riwayat Jam Kerja</h3>
                </div>
                <div class="card-content">
                    <div class="modern-info-box">
                        <span class="icon">💡</span>
                        <div>Baris dengan sorotan kuning menandakan durasi jam kerja melebihi batas normal (> 7 Jam).</div>
                    </div>
                    
                    <?php if (empty($activities)): ?>
                        <div class="no-data" style="color: var(--text-muted); font-style: italic; text-align: center; padding: 2rem 0; background: rgba(0,0,0,0.2); border-radius: 16px;">
                            <span style="font-size: 2rem; display: block; margin-bottom: 10px;">🍃</span>
                            Belum ada aktivitas.
                        </div>
                    <?php else: ?>
                        <div class="modern-table-wrapper">
                            <table class="modern-table">
                                <thead>
                                    <tr>
                                        <th>Tanggal</th>
                                        <th>Mulai</th>
                                        <th>Selesai</th>
                                        <th>Durasi</th>
                                        <th>Tipe Input</th>
                                        <th>Status</th>
                                        <th>Aksi</th> 
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($activities as $activity): ?>
                                    <?php
                                    $is_long_duty = ($activity['status'] === 'completed' && $activity['duration_minutes'] > 420);
                                    $is_active = $activity['status'] === 'active';
                                    
                                    // Badge Tipe
                                    $type_badge = 'b-info';
                                    $display_type = 'Otomatis';
                                    if ($activity['is_manual'] == 1) {
                                        $display_type = 'Manual (Web)';
                                        $type_badge = 'b-warning';
                                    } elseif ($activity['is_manual'] == 2) {
                                        $display_type = 'Discord/Bot';
                                        $type_badge = 'b-primary';
                                    }
                                    
                                    // Badge Status
                                    $status_badge = 'b-pending';
                                    if($activity['status'] == 'completed') $status_badge = 'b-completed';
                                    if($activity['status'] == 'active') $status_badge = 'b-active';

                                    $display_duration = $is_active ? 'Berlangsung...' : formatDuration($activity['duration_minutes']);
                                    $display_end_time = $activity['duty_end'] ? date('H:i', strtotime($activity['duty_end'])) : '-';
                                    $display_status = ucfirst($activity['status']);
                                    ?>
                                    <tr class="<?= $is_long_duty ? 'long-duty-row-modern' : '' ?>">
                                        <td data-label="Tanggal">
                                            <div style="display: flex; align-items: center; gap: 8px;">
                                                📅 <strong><?= date('d M Y', strtotime($activity['duty_start'])) ?></strong>
                                            </div>
                                        </td>
                                        <td data-label="Mulai"><?= date('H:i', strtotime($activity['duty_start'])) ?></td>
                                        <td data-label="Selesai"><?= $display_end_time ?></td>
                                        <td data-label="Durasi" style="<?= $is_active ? 'color: #f87171;' : 'color: #6ee7b7;' ?>">
                                            <strong><?= $display_duration ?></strong>
                                            <?php if ($is_long_duty): ?>
                                                <span class="alert-badge" title="Durasi lebih dari 7 jam">⚠️ >7 Jam</span>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="Tipe"><span class="badge-pill <?= $type_badge ?>"><?= $display_type ?></span></td>
                                        <td data-label="Status"><span class="badge-pill <?= $status_badge ?>"><?= $display_status ?></span></td>
                                        <td data-label="Aksi">
                                            <?php if ($activity['status'] !== 'pending_approval'): ?>
                                            <form method="POST" onsubmit="return confirm('Hapus log jam kerja ini? Aksi tidak dapat dibatalkan.')">
                                                <input type="hidden" name="action" value="delete_duty_log">
                                                <input type="hidden" name="duty_log_id" value="<?= $activity['id'] ?>">
                                                <button type="submit" class="btn-del-modern">Hapus</button>
                                            </form>
                                            <?php else: ?> 
                                                <span style="color: var(--text-muted);">-</span> 
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <script src="script.js"></script>
</body>
</html>