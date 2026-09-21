<?php
require_once 'config.php';

// Ambil jumlah permohonan pending untuk indikator sidebar
$pending_requests_count = getPendingRequestCount();

// Pastikan fungsi helper tersedia
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
            'ceo' => 'CEO', 'direktur' => 'Direktur', 'wakil_direktur' => 'Wakil Direktur',
            'manager' => 'Manager', 'chef' => 'Chef', 'waiters' => 'Waiters',
            'karyawan' => 'Karyawan', 'magang' => 'Magang',
        ];
        return $roles[$role] ?? ucfirst(str_replace('_', ' ', $role));
    }
}

if (!isLoggedIn() || !hasRole(['ceo', 'direktur', 'wakil_direktur', 'manager'])) {
    header('Location: dashboard.php');
    exit;
}

// === QUERY UTAMA: Menggabungkan Data Karyawan, Sales, dan Cooking ===
$stmt = $conn->query("
    SELECT
        e.id,
        e.name,
        e.role,
        e.is_on_duty,
        COALESCE(duty_summary.total_duty_minutes, 0) as total_duty_minutes,
        
        -- Kolom Sales (Dari sales_data)
        COALESCE(s.abdul, 0) as sales_abdul,
        COALESCE(s.lacosa, 0) as sales_lacosa,
        COALESCE(s.lakse, 0) as sales_lakse,
        COALESCE(s.saiyo, 0) as sales_saiyo,
        COALESCE(s.woku, 0) as sales_woku,
        COALESCE(s.hp, 0) as sales_hp,
        COALESCE(s.radio, 0) as sales_radio,
        
        -- Kolom Masak (Dari cooking_data)
        COALESCE(c.abdul, 0) as cook_abdul, 
        COALESCE(c.lacosa, 0) as cook_lacosa,  
        COALESCE(c.lakse, 0) as cook_lakse,
        COALESCE(c.saiyo, 0) as cook_saiyo,
        COALESCE(c.woku, 0) as cook_woku

    FROM employees e
    -- Join Jam Kerja
    LEFT JOIN (
        SELECT employee_id, SUM(duration_minutes) as total_duty_minutes
        FROM duty_logs WHERE status = 'completed' GROUP BY employee_id
    ) as duty_summary ON e.id = duty_summary.employee_id
    
    -- Join Sales Data
    LEFT JOIN (
        SELECT
            employee_id,
            SUM(paket_abdul) as abdul, SUM(paket_lacosa) as lacosa,
            SUM(paket_lakse) as lakse, SUM(paket_saiyo) as saiyo,
            SUM(paket_woku) as woku, SUM(hp) as hp, SUM(radio) as radio
        FROM sales_data GROUP BY employee_id
    ) as s ON e.id = s.employee_id
    
    -- Join Cooking Data (Tabel Baru)
    LEFT JOIN (
        SELECT
            employee_id,
            SUM(paket_abdul) as abdul, SUM(paket_lacosa) as lacosa,
            SUM(paket_lakse) as lakse, SUM(paket_saiyo) as saiyo,
            SUM(paket_woku) as woku
        FROM cooking_data GROUP BY employee_id
    ) as c ON e.id = c.employee_id
    
    WHERE e.status = 'active'
    ORDER BY
        CASE e.role
            WHEN 'ceo' THEN 1 WHEN 'direktur' THEN 2 WHEN 'wakil_direktur' THEN 3
            WHEN 'manager' THEN 4 WHEN 'chef' THEN 5 WHEN 'waiters' THEN 6
            WHEN 'karyawan' THEN 7 WHEN 'magang' THEN 8 ELSE 9
        END, e.name
");

if ($stmt === false) die("Gagal menjalankan query: " . $conn->error);
$employee_activities = $stmt->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// --- Hitung Total Keseluruhan (OVERALL SUMMARY) ---
$total_sales_abdul = array_sum(array_column($employee_activities, 'sales_abdul'));
$total_sales_lacosa = array_sum(array_column($employee_activities, 'sales_lacosa'));
$total_sales_lakse = array_sum(array_column($employee_activities, 'sales_lakse'));
$total_sales_saiyo = array_sum(array_column($employee_activities, 'sales_saiyo'));
$total_sales_woku = array_sum(array_column($employee_activities, 'sales_woku'));
$total_sales_hp = array_sum(array_column($employee_activities, 'sales_hp'));
$total_sales_radio = array_sum(array_column($employee_activities, 'sales_radio'));

$total_sales_all = $total_sales_abdul + $total_sales_lacosa + $total_sales_lakse + $total_sales_saiyo + $total_sales_woku + $total_sales_hp + $total_sales_radio;

$total_cook_abdul = array_sum(array_column($employee_activities, 'cook_abdul'));
$total_cook_lacosa = array_sum(array_column($employee_activities, 'cook_lacosa'));
$total_cook_lakse = array_sum(array_column($employee_activities, 'cook_lakse'));
$total_cook_saiyo = array_sum(array_column($employee_activities, 'cook_saiyo'));
$total_cook_woku = array_sum(array_column($employee_activities, 'cook_woku'));

$total_cook_all = $total_cook_abdul + $total_cook_lacosa + $total_cook_lakse + $total_cook_saiyo + $total_cook_woku;


// === START EXPORT LOGIC (CSV) ===
if (isset($_GET['export']) && $_GET['export'] == 'spreadsheet') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="aktivitas_anggota_' . date('Ymd_His') . '.csv"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM

    // Headers CSV
    $headers = [
        'Nama', 'Jabatan', 'Status', 'Total Jam Kerja (Menit)',
        'Total Jual', 'Jual Abdul', 'Jual Lacosa', 'Jual Lakse', 'Jual Saiyo', 'Jual Woku', 'Jual HP', 'Jual Radio',
        'Total Masak', 'Masak Abdul', 'Masak Lacosa', 'Masak Lakse', 'Masak Saiyo', 'Masak Woku'
    ];
    fputcsv($output, $headers);

    foreach ($employee_activities as $row) {
        $tot_sale = $row['sales_abdul'] + $row['sales_lacosa'] + $row['sales_lakse'] + $row['sales_saiyo'] + $row['sales_woku'] + $row['sales_hp'] + $row['sales_radio'];
        $tot_cook = $row['cook_abdul'] + $row['cook_lacosa'] + $row['cook_lakse'] + $row['cook_saiyo'] + $row['cook_woku'];
        
        $data_row = [
            htmlspecialchars_decode($row['name']), 
            getRoleDisplayName($row['role']),
            $row['is_on_duty'] ? 'On Duty' : 'Off Duty',
            $row['total_duty_minutes'],
            $tot_sale,
            $row['sales_abdul'], $row['sales_lacosa'], $row['sales_lakse'], $row['sales_saiyo'], $row['sales_woku'], $row['sales_hp'], $row['sales_radio'],
            $tot_cook,
            $row['cook_abdul'], $row['cook_lacosa'], $row['cook_lakse'], $row['cook_saiyo'], $row['cook_woku']
        ];
        fputcsv($output, $data_row);
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
    <title>Aktivitas Anggota - Warung Om Tante V2</title>
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
            background: radial-gradient(circle, rgba(59, 130, 246, 0.15), transparent 70%); pointer-events: none; z-index: 0;
        }
        
        .header-content-wrapper { display: flex; align-items: center; gap: 1.25rem; z-index: 2; position: relative; }
        .header-icon-wrapper {
            background: rgba(255, 255, 255, 0.05); border: 1px solid rgba(255, 255, 255, 0.1);
            width: 60px; height: 60px; border-radius: 16px; display: flex; align-items: center;
            justify-content: center; font-size: 1.8rem; box-shadow: inset 0 0 15px rgba(0,0,0,0.3); flex-shrink: 0; color: #60a5fa;
        }
        .header-text-wrapper h1 { color: #fff; font-weight: 800; font-size: 2.2rem; letter-spacing: -0.02em; margin: 0; }
        .header-text-wrapper p { color: var(--text-secondary); margin: 0.25rem 0 0 0; font-size: 1.05rem; }

        .btn-modern-export {
            background: linear-gradient(135deg, #0ea5e9, #2563eb); color: #fff; border: none; padding: 1rem 1.5rem;
            border-radius: 14px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; font-size: 0.9rem;
            cursor: pointer; transition: 0.3s; z-index: 2; position: relative; display: inline-flex; align-items: center; gap: 8px; text-decoration: none;
            box-shadow: 0 8px 20px rgba(59, 130, 246, 0.3);
        }
        .btn-modern-export:hover { transform: translateY(-3px); box-shadow: 0 12px 25px rgba(59, 130, 246, 0.5); color: #fff;}

        /* --- Stats Grid Modern --- */
        .stats-grid-modern {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 1.5rem; margin-bottom: 2rem;
            animation: slideUp 0.6s ease-out 0.2s backwards;
        }
        .stat-card-modern {
            background: rgba(30, 41, 59, 0.5); backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.05); border-radius: 20px; padding: 1.5rem;
            position: relative; overflow: hidden; transition: 0.3s;
        }
        .stat-card-modern:hover { transform: translateY(-5px); border-color: rgba(255,255,255,0.15); box-shadow: 0 15px 30px rgba(0,0,0,0.3); }
        
        .stat-card-modern::before { content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 4px; }
        .sc-hours::before { background: #60a5fa; box-shadow: 0 0 10px #60a5fa; }
        .sc-sales::before { background: #3b82f6; box-shadow: 0 0 10px #3b82f6; }
        .sc-cook::before { background: #10b981; box-shadow: 0 0 10px #10b981; }
        .sc-duty::before { background: #34d399; box-shadow: 0 0 10px #34d399; }

        .stat-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 10px; }
        .stat-header h4 { font-size: 0.95rem; color: #cbd5e1; margin: 0; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; }
        .stat-icon { font-size: 1.5rem; opacity: 0.8; }
        .stat-val { font-size: 2.2rem; font-weight: 800; color: #fff; margin-bottom: 15px; }

        .sc-detail-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
        .sc-detail-item {
            background: rgba(255,255,255,0.05); padding: 4px 8px; border-radius: 6px;
            font-size: 0.8rem; color: #94a3b8; display: flex; justify-content: space-between;
        }
        .sc-detail-item b { color: #fff; }

        /* --- Cards (Glassmorphism) --- */
        .modern-card {
            background: rgba(30, 41, 59, 0.6) !important; backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.08) !important; border-radius: 24px;
            box-shadow: 0 15px 35px rgba(0,0,0,0.3) !important; padding: 2rem;
            animation: slideUp 0.6s ease-out 0.4s backwards; margin-bottom: 2rem;
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
        .report-table { width: 100%; border-collapse: collapse; font-size: 0.9rem; min-width: 1200px; color: #e2e8f0; }
        .report-table th, .report-table td {
            padding: 1rem; border: 1px solid rgba(255, 255, 255, 0.05); text-align: center; vertical-align: middle;
        }
        .report-table th { background: rgba(255, 255, 255, 0.03); font-weight: 600; text-transform: uppercase; font-size: 0.8rem; letter-spacing: 0.05em; color: #94a3b8; }
        
        /* Pin First Column */
        .report-table th:first-child, .report-table td:first-child { 
            position: sticky; left: 0; z-index: 1; background: rgba(15, 23, 42, 0.95); text-align: left;
        }
        
        .report-table tr:hover td { background: rgba(255, 255, 255, 0.05); }

        /* Table Group Headers & Colors */
        .group-sales { background-color: rgba(59, 130, 246, 0.1) !important; color: #60a5fa !important; }
        .group-cook { background-color: rgba(16, 185, 129, 0.1) !important; color: #34d399 !important; }
        .col-sales { background-color: rgba(59, 130, 246, 0.03); }
        .col-cook { background-color: rgba(16, 185, 129, 0.03); }

        .emp-name-badge { display: flex; align-items: center; gap: 10px; font-weight: 700; color: #fff;}
        .emp-avatar-mini { width: 30px; height: 30px; border-radius: 50%; background: var(--primary-color); color: #000; display: flex; align-items: center; justify-content: center; font-size: 0.8rem;}

        .role-badge-modern {
            background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); color: #cbd5e1;
            font-size: 0.7rem; font-weight: 800; padding: 4px 10px; border-radius: 6px; text-transform: uppercase; letter-spacing: 0.05em;
        }

        .status-dot-table { width: 10px; height: 10px; border-radius: 50%; display: inline-block; margin-right: 5px; }
        .sdt-on { background: #34d399; box-shadow: 0 0 8px #34d399; animation: pulse-live 1.5s infinite;}
        .sdt-off { background: #64748b; }

        @keyframes slideDown { from { opacity: 0; transform: translateY(-20px); } to { opacity: 1; transform: translateY(0); } }
        @keyframes slideUp { from { opacity: 0; transform: translateY(30px); } to { opacity: 1; transform: translateY(0); } }
        @keyframes pulse-live { 0% { transform: scale(0.95); opacity: 0.8; } 50% { transform: scale(1.5); opacity: 1; } 100% { transform: scale(0.95); opacity: 0.8; } }

        @media (max-width: 768px) {
            .modern-page-header { flex-direction: column; text-align: center; }
            .header-content-wrapper { flex-direction: column; }
            .btn-modern-export { width: 100%; justify-content: center; }
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
                        <h1>Aktivitas Anggota</h1>
                        <p>Ringkasan akumulasi aktivitas dan performa semua anggota.</p>
                    </div>
                </div>
                <a href="employee-activities.php?export=spreadsheet" class="btn-modern-export" target="_blank">
                    <span>⬇️</span> Unduh CSV
                </a>
            </div>

            <div class="stats-grid-modern">
                
                <div class="stat-card-modern sc-hours">
                    <div class="stat-header">
                        <h4>Total Jam Kerja</h4>
                        <span class="stat-icon">⏰</span>
                    </div>
                    <div class="stat-val" style="color: #60a5fa; font-size: 1.8rem;">
                        <?= formatDuration(array_sum(array_column($employee_activities, 'total_duty_minutes'))) ?>
                    </div>
                </div>

                <div class="stat-card-modern sc-sales">
                    <div class="stat-header">
                        <h4>Total Penjualan</h4>
                        <span class="stat-icon">💰</span>
                    </div>
                    <div class="stat-val" style="color: #3b82f6;"><?= $total_sales_all ?> <span style="font-size: 1rem; color: #94a3b8; font-weight: 500;">Item</span></div>
                    <div class="sc-detail-grid">
                        <div class="sc-detail-item"><span>Abdul</span> <b><?= $total_sales_abdul ?></b></div>
                        <div class="sc-detail-item"><span>Lacosa</span> <b><?= $total_sales_lacosa ?></b></div>
                        <div class="sc-detail-item"><span>Lakse</span> <b><?= $total_sales_lakse ?></b></div>
                        <div class="sc-detail-item"><span>Saiyo</span> <b><?= $total_sales_saiyo ?></b></div>
                        <div class="sc-detail-item"><span>Woku</span> <b><?= $total_sales_woku ?></b></div>
                        <div class="sc-detail-item"><span>HP</span> <b><?= $total_sales_hp ?></b></div>
                        <div class="sc-detail-item"><span>Radio</span> <b><?= $total_sales_radio ?></b></div>
                    </div>
                </div>

                <div class="stat-card-modern sc-cook">
                    <div class="stat-header">
                        <h4>Total Masak</h4>
                        <span class="stat-icon">🍳</span>
                    </div>
                    <div class="stat-val" style="color: #34d399;"><?= $total_cook_all ?> <span style="font-size: 1rem; color: #94a3b8; font-weight: 500;">Paket</span></div>
                    <div class="sc-detail-grid">
                        <div class="sc-detail-item"><span>Abdul</span> <b><?= $total_cook_abdul ?></b></div>
                        <div class="sc-detail-item"><span>Lacosa</span> <b><?= $total_cook_lacosa ?></b></div>
                        <div class="sc-detail-item"><span>Lakse</span> <b><?= $total_cook_lakse ?></b></div>
                        <div class="sc-detail-item"><span>Saiyo</span> <b><?= $total_cook_saiyo ?></b></div>
                        <div class="sc-detail-item"><span>Woku</span> <b><?= $total_cook_woku ?></b></div>
                    </div>
                </div>

                <div class="stat-card-modern sc-duty">
                    <div class="stat-header">
                        <h4>Anggota On Duty</h4>
                        <span class="stat-icon">🟢</span>
                    </div>
                    <div class="stat-val" style="color: #10b981;">
                        <?= count(array_filter($employee_activities, function($emp) { return $emp['is_on_duty']; })) ?>
                        <span style="font-size: 1rem; color: #94a3b8; font-weight: 500;">Orang</span>
                    </div>
                </div>

            </div>

            <div class="modern-card">
                <div class="modern-card-header">
                    <h3><span>📋</span> Rincian per Anggota</h3>
                </div>
                <div class="modern-table-wrapper">
                    <table class="report-table">
                        <thead>
                            <tr>
                                <th rowspan="2">Nama Anggota</th>
                                <th rowspan="2">Jabatan</th>
                                <th rowspan="2">Status</th>
                                <th rowspan="2">Jam Kerja</th>
                                <th colspan="8" class="group-sales">Penjualan (Item)</th>
                                <th colspan="6" class="group-cook">Masak (Paket)</th>
                            </tr>
                            <tr>
                                <th class="col-sales">Total</th>
                                <th class="col-sales">Abdul</th>
                                <th class="col-sales">Lacosa</th>
                                <th class="col-sales">Lakse</th>
                                <th class="col-sales">Saiyo</th>
                                <th class="col-sales">Woku</th>
                                <th class="col-sales">HP</th>
                                <th class="col-sales">Radio</th>
                                
                                <th class="col-cook">Total</th>
                                <th class="col-cook">Abdul</th>
                                <th class="col-cook">Lacosa</th>
                                <th class="col-cook">Lakse</th>
                                <th class="col-cook">Saiyo</th>
                                <th class="col-cook">Woku</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($employee_activities)): ?>
                                <tr>
                                    <td colspan="18" style="text-align:center; padding: 3rem; font-style:italic; color: #94a3b8;">Belum ada data aktivitas karyawan.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($employee_activities as $activity): 
                                    $tot_sales = $activity['sales_abdul'] + $activity['sales_lacosa'] + $activity['sales_lakse'] + $activity['sales_saiyo'] + $activity['sales_woku'] + $activity['sales_hp'] + $activity['sales_radio'];
                                    $tot_cook = $activity['cook_abdul'] + $activity['cook_lacosa'] + $activity['cook_lakse'] + $activity['cook_saiyo'] + $activity['cook_woku'];
                                ?>
                                <tr>
                                    <td>
                                        <div class="emp-name-badge">
                                            <div class="emp-avatar-mini"><?= strtoupper(substr($activity['name'], 0, 1)) ?></div>
                                            <?= htmlspecialchars($activity['name']) ?>
                                        </div>
                                    </td>
                                    <td><span class="role-badge-modern"><?= getRoleDisplayName($activity['role']) ?></span></td>
                                    <td>
                                        <span class="status-dot-table <?= $activity['is_on_duty'] ? 'sdt-on' : 'sdt-off' ?>"></span>
                                        <span style="font-weight:600; font-size:0.8rem; color: <?= $activity['is_on_duty'] ? '#34d399' : '#94a3b8' ?>;"><?= $activity['is_on_duty'] ? 'ON' : 'OFF' ?></span>
                                    </td>
                                    <td style="color: #60a5fa; font-weight: 700;"><?= formatDuration($activity['total_duty_minutes']) ?></td>
                                    
                                    <td class="col-sales" style="color: #60a5fa; font-weight: 800; font-size: 1.1rem;"><?= $tot_sales ?></td>
                                    <td class="col-sales"><?= $activity['sales_abdul'] ?: '-' ?></td>
                                    <td class="col-sales"><?= $activity['sales_lacosa'] ?: '-' ?></td>
                                    <td class="col-sales"><?= $activity['sales_lakse'] ?: '-' ?></td>
                                    <td class="col-sales"><?= $activity['sales_saiyo'] ?: '-' ?></td>
                                    <td class="col-sales"><?= $activity['sales_woku'] ?: '-' ?></td>
                                    <td class="col-sales"><?= $activity['sales_hp'] ?: '-' ?></td>
                                    <td class="col-sales"><?= $activity['sales_radio'] ?: '-' ?></td>
                                    
                                    <td class="col-cook" style="color: #34d399; font-weight: 800; font-size: 1.1rem;"><?= $tot_cook ?></td>
                                    <td class="col-cook"><?= $activity['cook_abdul'] ?: '-' ?></td>
                                    <td class="col-cook"><?= $activity['cook_lacosa'] ?: '-' ?></td>
                                    <td class="col-cook"><?= $activity['cook_lakse'] ?: '-' ?></td>
                                    <td class="col-cook"><?= $activity['cook_saiyo'] ?: '-' ?></td>
                                    <td class="col-cook"><?= $activity['cook_woku'] ?: '-' ?></td>
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