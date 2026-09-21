<?php
require_once 'config.php';

if (!isLoggedIn() || !hasRole(['ceo', 'direktur', 'wakil_direktur', 'manager'])) {
    header('Location: dashboard.php');
    exit;
}

$user = getCurrentUser();
$pending_requests_count = getPendingRequestCount();

// --- Helper Functions ---
function roundToNearestHour($minutes) {
    return round($minutes / 60);
}

// --- KONFIGURASI GAJI BARU (Dalam Dollar) ---
$BASE_SALARIES = [
    'ceo' => 10000,
    'direktur' => 10000,
    'wakil_direktur' => 8000,
    'manager' => 6500,
    'chef' => 5000,
    'waiters' => 3000, // Asumsi Waiters setara Karyawan
    'karyawan' => 3000,
    'magang' => 2000
];

$MIN_DUTY_HOURS = 10;
$BONUS_DUTY_HOURS = 21;
$BONUS_DUTY_AMOUNT = 1000;

$BONUS_SALES_TIER_1 = 150; // Lebih dari 150 paket
$BONUS_SALES_TIER_2 = 300; // Lebih dari 300 paket
$BONUS_SALES_AMOUNT = 1000; // Per tier kelipatan

$success_message = null;
$error_message = null;

// --- Handle Payment Status Actions ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $employee_id = (int)($_POST['employee_id'] ?? 0);

    $conn->begin_transaction();
    try {
        if ($action === 'reset_all_paid_status') {
            $stmt = $conn->prepare("UPDATE employees SET is_paid = FALSE WHERE status = 'active'");
            if ($stmt->execute() && $stmt->affected_rows > 0) {
                $conn->commit();
                $success_message = "Semua status gaji anggota berhasil diubah menjadi **Belum Dibayar**.";
                sendDiscordNotification(['admin_name' => $user['name']], 'salary_unpaid_all');
            } else {
                throw new Exception("Gagal mereset atau tidak ada yang perlu direset.");
            }
            $stmt->close();
            
        } elseif ($action === 'delete_all_activity_data') {
            if (!hasRole(['ceo', 'direktur', 'wakil_direktur'])) throw new Exception("Tidak ada izin.");

            // Hapus Sales & Cooking
            $conn->query("DELETE FROM sales_data");
            $deleted_sales_count = $conn->affected_rows;
            
            // Hapus Cooking Data
            $conn->query("DELETE FROM cooking_data"); 

            // Hapus Duty Logs Completed
            $conn->query("DELETE FROM duty_logs WHERE status = 'completed'");
            $deleted_duty_count = $conn->affected_rows;

            $conn->commit();
            $success_message = "Semua data aktivitas berhasil dihapus.";

            sendDiscordNotification([
                'admin_name' => $user['name'],
                'deleted_sales' => $deleted_sales_count,
                'deleted_duty_logs' => $deleted_duty_count,
                'action_type' => 'mass_activity_delete'
            ], 'admin_system_action');
        
        } else {
            if ($employee_id <= 0) throw new Exception("ID anggota tidak valid.");
            $employee_name = getEmployeeNameById($employee_id);

            if ($action === 'mark_paid') {
                $stmt = $conn->prepare("UPDATE employees SET is_paid = TRUE WHERE id = ?");
                $stmt->bind_param("i", $employee_id);
                $stmt->execute();
                $stmt->close();
                $conn->commit();
                $success_message = "Status gaji **$employee_name** -> Sudah Dibayar.";
                sendDiscordNotification(['employee_name' => $employee_name, 'status' => 'Sudah Dibayar', 'admin_name' => $user['name']], 'salary_paid_single');
                
            } elseif ($action === 'mark_unpaid') {
                $stmt = $conn->prepare("UPDATE employees SET is_paid = FALSE WHERE id = ?");
                $stmt->bind_param("i", $employee_id);
                $stmt->execute();
                $stmt->close();
                $conn->commit();
                $success_message = "Status gaji **$employee_name** -> Belum Dibayar.";
                sendDiscordNotification(['employee_name' => $employee_name, 'status' => 'Belum Dibayar', 'admin_name' => $user['name']], 'salary_unpaid_single');

            } elseif ($action === 'delete_sales_data') {
                $stmt = $conn->prepare("DELETE FROM sales_data WHERE employee_id = ?");
                $stmt->bind_param("i", $employee_id);
                $stmt->execute(); $stmt->close();
                
                // Hapus juga data masak
                $stmt = $conn->prepare("DELETE FROM cooking_data WHERE employee_id = ?");
                $stmt->bind_param("i", $employee_id);
                $stmt->execute(); $stmt->close();

                $stmt = $conn->prepare("DELETE FROM duty_logs WHERE employee_id = ? AND status = 'completed'");
                $stmt->bind_param("i", $employee_id);
                $stmt->execute(); $stmt->close();

                $conn->commit();
                $success_message = "Data aktivitas **$employee_name** berhasil direset.";
            }
        }

    } catch (Exception $e) {
        $conn->rollback();
        $error_message = "Error: " . $e->getMessage();
    }
    header("Location: salary-recap.php?msg=" . urlencode($success_message ?? $error_message) . "&type=" . urlencode(isset($success_message) ? 'success' : 'error'));
    exit;
}

if (isset($_GET['msg']) && isset($_GET['type'])) {
    if ($_GET['type'] === 'success') $success_message = htmlspecialchars($_GET['msg']);
    else $error_message = htmlspecialchars($_GET['msg']);
}

$employees_data = [];
$total_payroll_expenditure = 0;

// --- QUERY UTAMA ---
// Hanya menghitung penjualan "paket makan minum" untuk syarat bonus penjualan (HP dan Radio tidak masuk).
$stmt = $conn->query("
    SELECT e.id, e.name, e.role, e.is_on_duty, e.is_paid,
           COALESCE(duty_summary.total_duty_minutes, 0) as total_duty_minutes,
           COALESCE(sales_summary.total_sales, 0) as total_sales_packages
    FROM employees e
    LEFT JOIN (
        SELECT employee_id, SUM(duration_minutes) as total_duty_minutes
        FROM duty_logs WHERE status = 'completed' GROUP BY employee_id
    ) as duty_summary ON e.id = duty_summary.employee_id
    LEFT JOIN (
        SELECT employee_id,
            (SUM(paket_abdul) + SUM(paket_lacosa) + SUM(paket_lakse) + 
             SUM(paket_saiyo) + SUM(paket_woku)) as total_sales
        FROM sales_data
        GROUP BY employee_id
    ) as sales_summary ON e.id = sales_summary.employee_id
    WHERE e.status = 'active'
    ORDER BY FIELD(e.role, 'ceo', 'direktur', 'wakil_direktur', 'manager', 'chef', 'waiters', 'karyawan', 'magang'), e.name
");

if ($stmt === false) {
    die("Database Error: " . $conn->error);
}

$employees_raw_data = $stmt->fetch_all(MYSQLI_ASSOC);

foreach ($employees_raw_data as $employee) {
    $employee_role = strtolower($employee['role']);
    $total_duty_minutes = $employee['total_duty_minutes'];
    $rounded_duty_hours = roundToNearestHour($total_duty_minutes);
    $total_sales_packages = (int)$employee['total_sales_packages'];

    // Variabel Gaji
    $gaji_pokok = 0;
    $bonus_duty = 0;
    $bonus_penjualan = 0;
    
    $keterangan_parts = [];
    $is_cut = false; 

    // --- 1. GAJI POKOK & BONUS JAM DUTY ---
    if ($rounded_duty_hours >= $MIN_DUTY_HOURS) {
        $gaji_pokok = $BASE_SALARIES[$employee_role] ?? 3000;
        
        // Cek Bonus Jam
        if ($rounded_duty_hours >= $BONUS_DUTY_HOURS) {
            $bonus_duty = $BONUS_DUTY_AMOUNT;
            $keterangan_parts[] = "Lulus Jam + Bonus Duty";
        } else {
            $keterangan_parts[] = "Lulus Jam";
        }
    } else {
        $keterangan_parts[] = "Gagal Jam (<{$MIN_DUTY_HOURS}j)";
        $is_cut = true;
    }

    // --- 2. BONUS PENJUALAN ---
    if ($total_sales_packages >= $BONUS_SALES_TIER_2) {
        $bonus_penjualan = $BONUS_SALES_AMOUNT * 2; // Total $2000
        $keterangan_parts[] = "Bonus Sales Max (>=300)";
    } elseif ($total_sales_packages >= $BONUS_SALES_TIER_1) {
        $bonus_penjualan = $BONUS_SALES_AMOUNT; // Total $1000
        $keterangan_parts[] = "Bonus Sales (>=150)";
    } else {
        $keterangan_parts[] = "No Bonus Sales";
    }

    $total_gajian = $gaji_pokok + $bonus_duty + $bonus_penjualan;
    $total_payroll_expenditure += $total_gajian;

    $employees_data[] = [
        'id' => $employee['id'],
        'name' => $employee['name'],
        'role' => $employee['role'],
        'is_paid' => (bool)$employee['is_paid'],
        'total_duty_minutes' => $total_duty_minutes,
        'rounded_duty_hours' => $rounded_duty_hours,
        'total_sales_packages' => $total_sales_packages,
        'gaji_pokok' => $gaji_pokok, 
        'bonus_duty' => $bonus_duty,
        'bonus_penjualan' => $bonus_penjualan,
        'total_gajian' => $total_gajian,
        'is_cut' => $is_cut,
        'keterangan_gaji' => implode(", ", $keterangan_parts)
    ];
}

// === EXPORT LOGIC ===
if (isset($_GET['export']) && $_GET['export'] == 'spreadsheet') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="rekap_gajian_' . date('Ymd_His') . '.csv"');
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

    fputcsv($output, ['Nama', 'Jabatan', 'Total Jam Duty', 'Bulat', 'Total Sales (Pkt)', 'Gaji Pokok ($)', 'Bonus Jam ($)', 'Bonus Sales ($)', 'Total Terima ($)', 'Keterangan', 'Status']);

    foreach ($employees_data as $row) {
        fputcsv($output, [
            htmlspecialchars_decode($row['name']), getRoleDisplayName($row['role']),
            number_format($row['total_duty_minutes'] / 60, 2), $row['rounded_duty_hours'],
            $row['total_sales_packages'],
            $row['gaji_pokok'], $row['bonus_duty'], $row['bonus_penjualan'], $row['total_gajian'],
            $row['keterangan_gaji'], $row['is_paid'] ? 'Sudah Dibayar' : 'Belum Dibayar'
        ]);
    }
    fclose($output);
    exit;
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rekap Gaji Terbaru - Warung Om Tante V2</title>
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
            border: 1px solid rgba(255, 255, 255, 0.08) !important;
            border-radius: 24px;
            box-shadow: 0 15px 35px rgba(0,0,0,0.3) !important;
            padding: 2rem 2.5rem;
            margin-bottom: 2rem;
            animation: slideDown 0.6s ease-out backwards;
            position: relative;
            overflow: hidden;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 20px;
        }
        
        .modern-page-header::after {
            content: ''; position: absolute; top: -20%; right: -5%; width: 300px; height: 300px;
            background: radial-gradient(circle, rgba(16, 185, 129, 0.15), transparent 70%); pointer-events: none; z-index: 0;
        }
        
        .header-content-wrapper { display: flex; align-items: center; gap: 1.25rem; z-index: 2; position: relative; }
        .header-icon-wrapper {
            background: rgba(255, 255, 255, 0.05); border: 1px solid rgba(255, 255, 255, 0.1);
            width: 60px; height: 60px; border-radius: 16px; display: flex; align-items: center;
            justify-content: center; font-size: 1.8rem; box-shadow: inset 0 0 15px rgba(0,0,0,0.3); flex-shrink: 0; color: #10b981;
        }
        .header-text-wrapper h1 { color: #fff; font-weight: 800; font-size: 2.2rem; letter-spacing: -0.02em; margin: 0; }
        .header-text-wrapper p { color: var(--text-secondary); margin: 0.25rem 0 0 0; font-size: 0.95rem; }

        .page-actions-modern { display: flex; gap: 10px; flex-wrap: wrap; z-index: 2; position: relative;}
        
        /* Modern Buttons */
        .btn-modern {
            border: none; padding: 0.8rem 1.25rem; border-radius: 14px; font-weight: 700; text-transform: uppercase; 
            letter-spacing: 0.05em; font-size: 0.85rem; cursor: pointer; transition: 0.3s; display: inline-flex; align-items: center; gap: 8px; text-decoration: none;
        }
        .btn-modern-info { background: rgba(59, 130, 246, 0.15); color: #60a5fa; border: 1px solid rgba(59, 130, 246, 0.4); }
        .btn-modern-info:hover { background: rgba(59, 130, 246, 0.3); transform: translateY(-3px); box-shadow: 0 5px 15px rgba(59, 130, 246, 0.2); }
        
        .btn-modern-warning { background: rgba(245, 158, 11, 0.15); color: #fbbf24; border: 1px solid rgba(245, 158, 11, 0.4); }
        .btn-modern-warning:hover { background: rgba(245, 158, 11, 0.3); transform: translateY(-3px); box-shadow: 0 5px 15px rgba(245, 158, 11, 0.2); }
        
        .btn-modern-danger { background: rgba(239, 68, 68, 0.15); color: #fca5a5; border: 1px solid rgba(239, 68, 68, 0.4); }
        .btn-modern-danger:hover { background: rgba(239, 68, 68, 0.3); transform: translateY(-3px); box-shadow: 0 5px 15px rgba(239, 68, 68, 0.2); }

        .btn-modern-success { background: rgba(16, 185, 129, 0.15); color: #34d399; border: 1px solid rgba(16, 185, 129, 0.4); }
        .btn-modern-success:hover { background: rgba(16, 185, 129, 0.3); transform: translateY(-3px); box-shadow: 0 5px 15px rgba(16, 185, 129, 0.2); }

        /* --- Stats Grid Modern --- */
        .stats-grid-modern {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1.5rem; margin-bottom: 2rem;
            animation: slideUp 0.6s ease-out 0.1s backwards;
        }

        .stat-card-modern {
            background: rgba(30, 41, 59, 0.5); backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.05); border-radius: 20px; padding: 1.75rem;
            position: relative; overflow: hidden; transition: 0.3s; display: flex; flex-direction: column; justify-content: center;
        }
        .stat-card-modern:hover { transform: translateY(-5px); border-color: rgba(255,255,255,0.15); box-shadow: 0 15px 30px rgba(0,0,0,0.3); }
        
        .stat-card-modern::before { content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 4px; }
        .sc-money::before { background: #10b981; box-shadow: 0 0 10px #10b981; }
        .sc-people::before { background: #3b82f6; box-shadow: 0 0 10px #3b82f6; }

        .stat-card-modern h4 { font-size: 0.9rem; color: #cbd5e1; margin-bottom: 8px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; display: flex; align-items: center; gap: 8px; }
        .stat-card-modern .value { font-size: 2.5rem; font-weight: 800; margin-bottom: 5px; color: #fff;}

        /* --- Cards (Glassmorphism) --- */
        .modern-card {
            background: rgba(30, 41, 59, 0.6) !important; backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.08) !important; border-radius: 24px;
            box-shadow: 0 15px 35px rgba(0,0,0,0.3) !important; padding: 2rem;
            animation: slideUp 0.6s ease-out 0.2s backwards; margin-bottom: 2rem;
        }
        .modern-card-header {
            display: flex; justify-content: space-between; align-items: center; 
            margin-bottom: 1.5rem; border-bottom: 1px solid rgba(255, 255, 255, 0.1); padding-bottom: 1rem;
        }
        .modern-card-header h3 { color: #fff; font-size: 1.25rem; font-weight: 700; margin: 0; display: flex; align-items: center; gap: 10px;}

        /* --- Modern Table --- */
        .modern-table-wrapper {
            background: rgba(0, 0, 0, 0.25); border-radius: 18px; border: 1px solid rgba(255, 255, 255, 0.05); overflow-x: auto;
        }
        .report-table { width: 100%; border-collapse: collapse; font-size: 0.9rem; min-width: 1100px; color: #e2e8f0; }
        .report-table th, .report-table td {
            padding: 1.2rem 1rem; border-bottom: 1px solid rgba(255, 255, 255, 0.03); text-align: left; vertical-align: middle;
        }
        .report-table th { background: rgba(255, 255, 255, 0.03); font-weight: 600; text-transform: uppercase; font-size: 0.75rem; letter-spacing: 0.05em; color: #94a3b8; }
        .report-table tr:hover td { background: rgba(255, 255, 255, 0.05); }
        .report-table tr:last-child td { border-bottom: none; }

        .emp-name { font-weight: 700; color: #fff; display: flex; align-items: center; gap: 10px; font-size: 1rem;}
        .emp-avatar-mini { width: 32px; height: 32px; border-radius: 50%; background: rgba(255,255,255,0.1); color: #fff; display: flex; align-items: center; justify-content: center; font-size: 0.8rem; font-weight: bold;}
        .role-badge-modern {
            background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); color: #cbd5e1;
            font-size: 0.65rem; font-weight: 800; padding: 3px 8px; border-radius: 6px; text-transform: uppercase; letter-spacing: 0.05em; display: inline-block; margin-top: 4px;
        }

        /* Status & Pay Badges */
        .payslip-status-modern { 
            display: inline-flex; align-items: center; padding: 4px 10px; border-radius: 20px; 
            font-size: 0.75rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; margin-top: 5px;
        }
        .ps-paid { background: rgba(16, 185, 129, 0.15); color: #34d399; border: 1px solid rgba(16, 185, 129, 0.3); }
        .ps-unpaid { background: rgba(239, 68, 68, 0.15); color: #fca5a5; border: 1px solid rgba(239, 68, 68, 0.3); }
        
        .action-column { display: flex; flex-direction: column; gap: 0.5rem; justify-content: center;}
        
        /* Alerts */
        .success-alert { background: rgba(52, 211, 153, 0.15); border: 1px solid rgba(52, 211, 153, 0.4); color: #6ee7b7; padding: 1rem 1.2rem; border-radius: 14px; margin-bottom: 1.5rem; display: flex; align-items: center; gap: 0.75rem; font-size: 0.95rem; font-weight: 500; animation: slideDown 0.4s ease-out; }
        .error-alert { background: rgba(220, 53, 69, 0.15); border: 1px solid rgba(220, 53, 69, 0.4); color: #fca5a5; padding: 1rem 1.2rem; border-radius: 14px; margin-bottom: 1.5rem; display: flex; align-items: center; gap: 0.75rem; font-size: 0.95rem; font-weight: 500; animation: shake 0.5s ease-in-out; }

        @keyframes slideDown { from { opacity: 0; transform: translateY(-20px); } to { opacity: 1; transform: translateY(0); } }
        @keyframes slideUp { from { opacity: 0; transform: translateY(30px); } to { opacity: 1; transform: translateY(0); } }
        @keyframes shake { 0%, 100% { transform: translateX(0); } 25% { transform: translateX(-5px); } 75% { transform: translateX(5px); } }

        @media (max-width: 768px) {
            .modern-page-header { flex-direction: column; text-align: center; }
            .header-content-wrapper { flex-direction: column; }
            .header-text-wrapper h1 { font-size: 1.8rem; }
            .page-actions-modern { justify-content: center; }
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
                    <div class="header-icon-wrapper">💸</div>
                    <div class="header-text-wrapper">
                        <h1>Rekap Gaji (Sistem Baru)</h1>
                        <p>Syarat Gaji Pokok: Min 10 Jam Duty | Bonus Duty: > 21 Jam | Bonus Sales: > 150 & 300 Pkt.</p>
                    </div>
                </div>
                <div class="page-actions-modern">
                    <a href="salary-recap.php?export=spreadsheet" class="btn-modern btn-modern-info" target="_blank">
                        <span>⬇️</span> CSV
                    </a>
                    <form method="POST" style="display: inline;" onsubmit="return confirm('Reset status pembayaran semua anggota menjadi Belum Dibayar?')">
                        <input type="hidden" name="action" value="reset_all_paid_status">
                        <button type="submit" class="btn-modern btn-modern-warning">
                            <span>🔄</span> Reset Status
                        </button>
                    </form>
                    <?php if (hasRole(['ceo', 'direktur', 'wakil_direktur'])): ?>
                    <form method="POST" style="display: inline;" onsubmit="return confirm('⚠️ PERINGATAN FATAL: Yakin ingin menghapus SEMUA data aktivitas (Sales, Masak, Jam Kerja)? Tindakan ini tidak bisa dibatalkan!')">
                        <input type="hidden" name="action" value="delete_all_activity_data">
                        <button type="submit" class="btn-modern btn-modern-danger">
                            <span>🗑️</span> Hapus Aktivitas
                        </button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (isset($success_message)): ?>
                <div class="success-alert"><span>🎉</span> <?= htmlspecialchars($success_message) ?></div>
            <?php endif; ?>
            <?php if (isset($error_message)): ?>
                <div class="error-alert"><span>⚠️</span> <?= htmlspecialchars($error_message) ?></div>
            <?php endif; ?>

            <div class="stats-grid-modern">
                <div class="stat-card-modern sc-money">
                    <h4><span>💲</span> Total Pengeluaran Gaji</h4>
                    <div class="value" style="color: #34d399;">
                        $ <?= number_format($total_payroll_expenditure, 0, ',', '.') ?>
                    </div>
                </div>
                <div class="stat-card-modern sc-people">
                    <h4><span>👥</span> Total Anggota Teregister</h4>
                    <div class="value" style="color: #60a5fa;">
                        <?= count($employees_data) ?> <span style="font-size: 1rem; color: #94a3b8; font-weight: 500;">Orang</span>
                    </div>
                </div>
            </div>

            <div class="modern-card">
                <div class="modern-card-header">
                    <h3><span>📋</span> Detail Perhitungan Gaji</h3>
                </div>
                <div class="modern-table-wrapper">
                    <table class="report-table">
                        <thead>
                            <tr>
                                <th>Nama / Jabatan</th>
                                <th>Jam Duty</th>
                                <th>Penjualan (Pkt)</th>
                                <th>Gaji Pokok</th>
                                <th>Bonus Jam</th>
                                <th>Bonus Sales</th>
                                <th>Total Terima</th>
                                <th>Keterangan</th>
                                <th style="text-align: center;">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($employees_data)): ?>
                                <tr><td colspan="9" style="text-align: center; font-style: italic; color: #64748b; padding: 3rem;">Belum ada data karyawan aktif.</td></tr>
                            <?php else: ?>
                                <?php foreach ($employees_data as $employee): ?>
                                <tr>
                                    <td>
                                        <div class="emp-name">
                                            <div class="emp-avatar-mini"><?= strtoupper(substr($employee['name'], 0, 1)) ?></div>
                                            <div>
                                                <?= htmlspecialchars($employee['name']) ?><br>
                                                <div class="role-badge-modern"><?= getRoleDisplayName($employee['role']) ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <strong style="color: #fff;"><?= $employee['rounded_duty_hours'] ?> Jam</strong><br>
                                        <span style="font-size: 0.75rem; color: #64748b;">(<?= number_format($employee['total_duty_minutes'] / 60, 1) ?> asli)</span>
                                    </td>
                                    <td><strong style="color: #fff;"><?= $employee['total_sales_packages'] ?> Item</strong></td>
                                    <td style="color: #cbd5e1;">$ <?= number_format($employee['gaji_pokok'], 0, ',', '.') ?></td>
                                    <td style="color: #cbd5e1;">$ <?= number_format($employee['bonus_duty'], 0, ',', '.') ?></td>
                                    <td style="color: #cbd5e1;">$ <?= number_format($employee['bonus_penjualan'], 0, ',', '.') ?></td>
                                    <td>
                                        <strong style="font-size: 1.2rem; color: #10b981; text-shadow: 0 0 10px rgba(16,185,129,0.3);">
                                            $ <?= number_format($employee['total_gajian'], 0, ',', '.') ?>
                                        </strong>
                                        <br>
                                        <span class="payslip-status-modern <?= $employee['is_paid'] ? 'ps-paid' : 'ps-unpaid' ?>">
                                            <?= $employee['is_paid'] ? 'Lunas' : 'Belum' ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span style="font-size: 0.8rem; font-weight: 600; color: <?= $employee['is_cut'] ? '#fca5a5' : '#cbd5e1' ?>; line-height: 1.4; display: inline-block;">
                                            <?= $employee['keterangan_gaji'] ?>
                                        </span>
                                    </td>
                                    <td class="action-column">
                                        <?php if (!$employee['is_paid']): ?>
                                        <form method="POST" style="margin: 0;" onsubmit="return confirm('Tandai gaji <?= htmlspecialchars($employee['name']) ?> SUDAH DIBAYAR?')">
                                            <input type="hidden" name="action" value="mark_paid">
                                            <input type="hidden" name="employee_id" value="<?= $employee['id'] ?>">
                                            <button class="btn-modern btn-modern-success" style="padding: 0.4rem 0.8rem; font-size: 0.7rem; width: 100%; justify-content: center;">✔ Bayar</button>
                                        </form>
                                        <?php else: ?>
                                        <form method="POST" style="margin: 0;" onsubmit="return confirm('Batalkan status bayar untuk <?= htmlspecialchars($employee['name']) ?>?')">
                                            <input type="hidden" name="action" value="mark_unpaid">
                                            <input type="hidden" name="employee_id" value="<?= $employee['id'] ?>">
                                            <button class="btn-modern btn-modern-warning" style="padding: 0.4rem 0.8rem; font-size: 0.7rem; width: 100%; justify-content: center;">✖ Batal</button>
                                        </form>
                                        <?php endif; ?>
                                        
                                        <form method="POST" style="margin: 0;" onsubmit="return confirm('Yakin menghapus data aktivitas orang ini?')">
                                            <input type="hidden" name="action" value="delete_sales_data">
                                            <input type="hidden" name="employee_id" value="<?= $employee['id'] ?>">
                                            <button class="btn-modern btn-modern-danger" style="padding: 0.4rem 0.8rem; font-size: 0.7rem; width: 100%; justify-content: center;">🗑️ Reset</button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </main>
    </div>
    <script src="script.js"></script>
</body>
</html>