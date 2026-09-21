<?php
require_once 'config.php';

// Pastikan hanya manajer dan level di atasnya yang bisa mengakses
if (!isLoggedIn() || !hasRole(['ceo', 'direktur', 'wakil_direktur', 'manager'])) {
    header('Location: dashboard.php');
    exit;
}

$user = getCurrentUser();

// Filter Tanggal & Karyawan
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$filter_employee_id = isset($_GET['employee_id']) ? (int)$_GET['employee_id'] : 'all';

// Ambil daftar karyawan untuk filter
$employees = $conn->query("SELECT id, name FROM employees WHERE status = 'active' ORDER BY name")->fetch_all(MYSQLI_ASSOC);

// --- 1. Query Data Penjualan (Sales Data) ---
$sales_query = "
    SELECT 
        e.name as employee_name,
        SUM(s.paket_abdul) as total_abdul,
        SUM(s.paket_lacosa) as total_lacosa,
        SUM(s.paket_lakse) as total_lakse,
        SUM(s.paket_saiyo) as total_saiyo,
        SUM(s.paket_woku) as total_woku,
        SUM(s.hp) as total_hp,
        SUM(s.radio) as total_radio
    FROM sales_data s
    JOIN employees e ON s.employee_id = e.id
    WHERE s.date BETWEEN ? AND ?
";

// --- 2. Query Data Masak (Cooking Data) ---
// Catatan: Cooking data biasanya tidak mencakup HP dan Radio
$cooking_query = "
    SELECT 
        e.name as employee_name,
        SUM(c.paket_abdul) as total_abdul,
        SUM(c.paket_lacosa) as total_lacosa,
        SUM(c.paket_lakse) as total_lakse,
        SUM(c.paket_saiyo) as total_saiyo,
        SUM(c.paket_woku) as total_woku
    FROM cooking_data c
    JOIN employees e ON c.employee_id = e.id
    WHERE c.date BETWEEN ? AND ?
";

// --- 3. Query Kehadiran ---
$attendance_query = "
    SELECT 
        e.name as employee_name,
        COUNT(d.id) as total_shifts,
        SUM(d.duration_minutes) as total_minutes
    FROM duty_logs d
    JOIN employees e ON d.employee_id = e.id
    WHERE DATE(d.duty_start) BETWEEN ? AND ? AND d.status = 'completed'
";

// Tambahkan filter karyawan jika dipilih
$params = [$start_date, $end_date];
$types = "ss";

if ($filter_employee_id !== 'all') {
    $sales_query .= " AND s.employee_id = ?";
    $cooking_query .= " AND c.employee_id = ?";
    $attendance_query .= " AND d.employee_id = ?";
    $params[] = $filter_employee_id;
    $types .= "i";
}

$sales_query .= " GROUP BY e.id";
$cooking_query .= " GROUP BY e.id";
$attendance_query .= " GROUP BY e.id";

// Eksekusi Sales
$stmt = $conn->prepare($sales_query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$sales_result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Eksekusi Cooking
$stmt = $conn->prepare($cooking_query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$cooking_result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Eksekusi Attendance
$stmt = $conn->prepare($attendance_query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$attendance_result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// --- Pengolahan Data untuk Tampilan ---
$report_data = [];

function initEmployeeData(&$data, $name) {
    if (!isset($data[$name])) {
        $data[$name] = [
            'sales' => [
                'abdul' => 0, 'lacosa' => 0, 'lakse' => 0, 'saiyo' => 0, 
                'woku' => 0, 'hp' => 0, 'radio' => 0, 'total' => 0
            ],
            'cooking' => [
                'abdul' => 0, 'lacosa' => 0, 'lakse' => 0, 'saiyo' => 0, 
                'woku' => 0, 'total' => 0
            ],
            'attendance' => ['shifts' => 0, 'hours' => 0]
        ];
    }
}

// Proses Sales
$grand_total_sales = 0;
foreach ($sales_result as $row) {
    initEmployeeData($report_data, $row['employee_name']);
    $report_data[$row['employee_name']]['sales']['abdul'] = (int)$row['total_abdul'];
    $report_data[$row['employee_name']]['sales']['lacosa'] = (int)$row['total_lacosa'];
    $report_data[$row['employee_name']]['sales']['lakse'] = (int)$row['total_lakse'];
    $report_data[$row['employee_name']]['sales']['saiyo'] = (int)$row['total_saiyo'];
    $report_data[$row['employee_name']]['sales']['woku'] = (int)$row['total_woku'];
    $report_data[$row['employee_name']]['sales']['hp'] = (int)$row['total_hp'];
    $report_data[$row['employee_name']]['sales']['radio'] = (int)$row['total_radio'];
    
    $subtotal = $row['total_abdul'] + $row['total_lacosa'] + $row['total_lakse'] + 
                $row['total_saiyo'] + $row['total_woku'] + $row['total_hp'] + $row['total_radio'];
    
    $report_data[$row['employee_name']]['sales']['total'] = $subtotal;
    $grand_total_sales += $subtotal;
}

// Proses Cooking
$grand_total_cooking = 0;
foreach ($cooking_result as $row) {
    initEmployeeData($report_data, $row['employee_name']);
    $report_data[$row['employee_name']]['cooking']['abdul'] = (int)$row['total_abdul'];
    $report_data[$row['employee_name']]['cooking']['lacosa'] = (int)$row['total_lacosa'];
    $report_data[$row['employee_name']]['cooking']['lakse'] = (int)$row['total_lakse'];
    $report_data[$row['employee_name']]['cooking']['saiyo'] = (int)$row['total_saiyo'];
    $report_data[$row['employee_name']]['cooking']['woku'] = (int)$row['total_woku'];

    $subtotal = $row['total_abdul'] + $row['total_lacosa'] + $row['total_lakse'] + 
                $row['total_saiyo'] + $row['total_woku'];
                
    $report_data[$row['employee_name']]['cooking']['total'] = $subtotal;
    $grand_total_cooking += $subtotal;
}

// Proses Attendance
$grand_total_hours = 0;
foreach ($attendance_result as $row) {
    initEmployeeData($report_data, $row['employee_name']);
    $report_data[$row['employee_name']]['attendance']['shifts'] = (int)$row['total_shifts'];
    $hours = round((int)$row['total_minutes'] / 60, 1);
    $report_data[$row['employee_name']]['attendance']['hours'] = $hours;
    $grand_total_hours += $hours;
}

// --- Data untuk Chart (Agregat per Menu) ---
$chart_menu_labels = ['Abdul Pack', 'Lacosa Pack', 'Lakse Pack', 'Saiyo Pack', 'Woku Pack', 'HP', 'Radio'];
$chart_sales_data = [0, 0, 0, 0, 0, 0, 0];
$chart_cooking_data = [0, 0, 0, 0, 0, 0, 0];

foreach ($report_data as $emp) {
    // Sales aggregation
    $chart_sales_data[0] += $emp['sales']['abdul'];
    $chart_sales_data[1] += $emp['sales']['lacosa'];
    $chart_sales_data[2] += $emp['sales']['lakse'];
    $chart_sales_data[3] += $emp['sales']['saiyo'];
    $chart_sales_data[4] += $emp['sales']['woku'];
    $chart_sales_data[5] += $emp['sales']['hp'];
    $chart_sales_data[6] += $emp['sales']['radio'];

    // Cooking aggregation (HP & Radio are 0)
    $chart_cooking_data[0] += $emp['cooking']['abdul'];
    $chart_cooking_data[1] += $emp['cooking']['lacosa'];
    $chart_cooking_data[2] += $emp['cooking']['lakse'];
    $chart_cooking_data[3] += $emp['cooking']['saiyo'];
    $chart_cooking_data[4] += $emp['cooking']['woku'];
}

?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laporan Kinerja Karyawan - Warung Om Tante</title>
    <link rel="icon" href="LOGO_WOT.png" type="image/png">
    <link rel="stylesheet" href="style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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

        /* --- Form Filter (Inner Wrapper) --- */
        .form-inner-wrapper {
            background: linear-gradient(145deg, rgba(30, 41, 59, 0.4), rgba(15, 23, 42, 0.6));
            border: 1px solid rgba(255, 255, 255, 0.05); border-radius: 20px; padding: 1.5rem;
            box-shadow: inset 0 0 20px rgba(0,0,0,0.2); margin-bottom: 2rem;
        }
        .form-row-modern {
            display: grid; grid-template-columns: 1fr 1fr 1fr auto; gap: 1.5rem; align-items: end;
        }
        .modern-form-group label {
            display: block; font-size: 0.9rem; font-weight: 600; color: var(--text-secondary); margin-bottom: 0.6rem;
        }
        .modern-input-wrapper { position: relative; display: flex; align-items: center; }
        .modern-input-icon { position: absolute; left: 1.25rem; font-size: 1.2rem; opacity: 0.6; pointer-events: none; z-index: 2; }
        .modern-input, .modern-select {
            width: 100%; background: rgba(0, 0, 0, 0.4); border: 1px solid rgba(255, 255, 255, 0.1);
            color: white; padding: 1.1rem 1rem 1.1rem 3.5rem; border-radius: 14px; font-size: 0.95rem; transition: 0.3s;
        }
        .modern-select { appearance: none; cursor: pointer; }
        .modern-select option { background: #0f172a; color: white; }
        .modern-input:focus, .modern-select:focus { outline: none; border-color: var(--primary-color); background: rgba(0,0,0,0.6); }
        input[type="date"].modern-input::-webkit-calendar-picker-indicator { filter: invert(1); cursor: pointer; opacity: 0.6; }
        
        .modern-btn-submit {
            background: linear-gradient(135deg, var(--primary-color), var(--primary-hover));
            color: #121212; border: none; padding: 1.1rem 2rem; border-radius: 14px; font-size: 1rem;
            font-weight: 800; text-transform: uppercase; cursor: pointer; transition: 0.3s; height: 100%;
        }
        .modern-btn-submit:hover { transform: translateY(-3px); box-shadow: 0 10px 20px rgba(255, 193, 7, 0.3); }

        /* --- Stats Grid Modern --- */
        .stats-grid-modern {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1.5rem; margin-bottom: 2rem;
            animation: slideUp 0.6s ease-out 0.3s backwards;
        }
        .stat-card-modern {
            background: rgba(30, 41, 59, 0.5); backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.05); border-radius: 20px; padding: 1.5rem;
            position: relative; overflow: hidden; transition: 0.3s; text-align: center;
        }
        .stat-card-modern:hover { transform: translateY(-5px); border-color: rgba(255,255,255,0.15); box-shadow: 0 15px 30px rgba(0,0,0,0.3); }
        
        .stat-card-modern::before { content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 4px; }
        .sc-sales::before { background: #3b82f6; box-shadow: 0 0 10px #3b82f6; }
        .sc-cook::before { background: #10b981; box-shadow: 0 0 10px #10b981; }
        .sc-hours::before { background: #8b5cf6; box-shadow: 0 0 10px #8b5cf6; }

        .stat-card-modern h3 { font-size: 0.95rem; color: #cbd5e1; margin-bottom: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; }
        .stat-card-modern .value { font-size: 2.5rem; font-weight: 800; color: #fff; }
        
        .sc-sales .value { color: #60a5fa; }
        .sc-cook .value { color: #34d399; }
        .sc-hours .value { color: #a78bfa; }

        /* --- Chart Container --- */
        .chart-wrapper {
            background: rgba(0, 0, 0, 0.3); border-radius: 16px; border: 1px solid rgba(255,255,255,0.05);
            padding: 1rem; position: relative; height: 400px; width: 100%;
        }

        /* --- Modern Table --- */
        .modern-table-wrapper {
            background: rgba(0, 0, 0, 0.25); border-radius: 18px; border: 1px solid rgba(255, 255, 255, 0.05); overflow-x: auto;
        }
        .report-table { width: 100%; border-collapse: collapse; font-size: 0.9rem; min-width: 1000px; color: #e2e8f0; }
        .report-table th, .report-table td {
            padding: 1rem; border: 1px solid rgba(255, 255, 255, 0.05); text-align: center; vertical-align: middle;
        }
        .report-table th { background: rgba(255, 255, 255, 0.03); font-weight: 600; text-transform: uppercase; font-size: 0.8rem; letter-spacing: 0.05em; color: #94a3b8; }
        .report-table td:first-child { text-align: left; font-weight: 700; color: #fff; background: rgba(255,255,255,0.02); position: sticky; left: 0; z-index: 1;}
        .report-table tr:hover td { background: rgba(255, 255, 255, 0.05); }

        /* Table Group Headers - Dark mode friendly colors */
        .group-header-sales { background-color: rgba(59, 130, 246, 0.15) !important; color: #60a5fa !important; }
        .group-header-cook { background-color: rgba(16, 185, 129, 0.15) !important; color: #34d399 !important; }
        .col-sales { background-color: rgba(59, 130, 246, 0.05); }
        .col-cook { background-color: rgba(16, 185, 129, 0.05); }

        @keyframes slideDown { from { opacity: 0; transform: translateY(-20px); } to { opacity: 1; transform: translateY(0); } }
        @keyframes slideUp { from { opacity: 0; transform: translateY(30px); } to { opacity: 1; transform: translateY(0); } }

        @media (max-width: 1024px) {
            .form-row-modern { grid-template-columns: 1fr 1fr; }
            .modern-btn-submit { grid-column: span 2; }
        }
        @media (max-width: 768px) {
            .modern-page-header { padding: 1.5rem; text-align: center; }
            .header-content-wrapper { flex-direction: column; }
            .form-row-modern { grid-template-columns: 1fr; }
            .modern-btn-submit { grid-column: span 1; }
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
                    <div class="header-icon-wrapper">📈</div>
                    <div class="header-text-wrapper">
                        <h1>Laporan Kinerja Karyawan</h1>
                        <p>Analisis data penjualan, aktivitas masak, dan jam kerja.</p>
                    </div>
                </div>
            </div>

            <div class="modern-card" style="padding-bottom: 0.5rem;">
                <div class="modern-card-header" style="margin-bottom: 1rem;">
                    <h3><span>🔍</span> Filter Laporan</h3>
                </div>
                <div class="form-inner-wrapper">
                    <form method="GET" class="report-form">
                        <div class="form-row-modern">
                            <div class="modern-form-group">
                                <label for="start_date">Dari Tanggal</label>
                                <div class="modern-input-wrapper">
                                    <span class="modern-input-icon">🗓️</span>
                                    <input type="date" name="start_date" id="start_date" class="modern-input" value="<?= $start_date ?>">
                                </div>
                            </div>
                            <div class="modern-form-group">
                                <label for="end_date">Sampai Tanggal</label>
                                <div class="modern-input-wrapper">
                                    <span class="modern-input-icon">🗓️</span>
                                    <input type="date" name="end_date" id="end_date" class="modern-input" value="<?= $end_date ?>">
                                </div>
                            </div>
                            <div class="modern-form-group">
                                <label for="employee_id">Karyawan</label>
                                <div class="modern-input-wrapper">
                                    <span class="modern-input-icon">👤</span>
                                    <select name="employee_id" id="employee_id" class="modern-select">
                                        <option value="all">Semua Karyawan</option>
                                        <?php foreach ($employees as $emp): ?>
                                            <option value="<?= $emp['id'] ?>" <?= ($filter_employee_id == $emp['id']) ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($emp['name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="modern-form-group" style="margin-bottom: 0;">
                                <button type="submit" class="modern-btn-submit">Tampilkan</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <div class="stats-grid-modern">
                <div class="stat-card-modern sc-sales">
                    <h3>Total Penjualan</h3>
                    <div class="value"><?= number_format($grand_total_sales) ?> <span style="font-size:1rem;color:#94a3b8;font-weight:500;">Item</span></div>
                </div>
                <div class="stat-card-modern sc-cook">
                    <h3>Total Masak</h3>
                    <div class="value"><?= number_format($grand_total_cooking) ?> <span style="font-size:1rem;color:#94a3b8;font-weight:500;">Paket</span></div>
                </div>
                <div class="stat-card-modern sc-hours">
                    <h3>Total Jam Kerja</h3>
                    <div class="value"><?= number_format($grand_total_hours, 1) ?> <span style="font-size:1rem;color:#94a3b8;font-weight:500;">Jam</span></div>
                </div>
            </div>

            <div class="modern-card">
                <div class="modern-card-header">
                    <h3><span>📊</span> Grafik Komparasi</h3>
                </div>
                <div class="chart-wrapper">
                    <canvas id="performanceChart"></canvas>
                </div>
            </div>

            <div class="modern-card">
                <div class="modern-card-header">
                    <h3><span>📋</span> Rincian Per Karyawan</h3>
                </div>
                <div class="modern-table-wrapper">
                    <table class="report-table">
                        <thead>
                            <tr>
                                <th rowspan="2">Nama Karyawan</th>
                                <th colspan="8" class="group-header-sales">Penjualan (Sales)</th>
                                <th colspan="6" class="group-header-cook">Masak (Cooking)</th>
                                <th rowspan="2">Jam Kerja</th>
                            </tr>
                            <tr>
                                <th class="col-sales">Abdul</th>
                                <th class="col-sales">Lacosa</th>
                                <th class="col-sales">Lakse</th>
                                <th class="col-sales">Saiyo</th>
                                <th class="col-sales">Woku</th>
                                <th class="col-sales">HP</th>
                                <th class="col-sales">Radio</th>
                                <th class="col-sales" style="color:#fff;">Total</th>
                                
                                <th class="col-cook">Abdul</th>
                                <th class="col-cook">Lacosa</th>
                                <th class="col-cook">Lakse</th>
                                <th class="col-cook">Saiyo</th>
                                <th class="col-cook">Woku</th>
                                <th class="col-cook" style="color:#fff;">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($report_data)): ?>
                                <tr>
                                    <td colspan="16" style="text-align:center; padding:2rem; font-style:italic; color:#94a3b8;">Tidak ada data untuk periode ini.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($report_data as $name => $data): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($name) ?></td>
                                        
                                        <td class="col-sales"><?= $data['sales']['abdul'] ?></td>
                                        <td class="col-sales"><?= $data['sales']['lacosa'] ?></td>
                                        <td class="col-sales"><?= $data['sales']['lakse'] ?></td>
                                        <td class="col-sales"><?= $data['sales']['saiyo'] ?></td>
                                        <td class="col-sales"><?= $data['sales']['woku'] ?></td>
                                        <td class="col-sales"><?= $data['sales']['hp'] ?></td>
                                        <td class="col-sales"><?= $data['sales']['radio'] ?></td>
                                        <td class="col-sales" style="color:#60a5fa; font-weight:bold; font-size:1.1rem;"><?= $data['sales']['total'] ?></td>
                                        
                                        <td class="col-cook"><?= $data['cooking']['abdul'] ?></td>
                                        <td class="col-cook"><?= $data['cooking']['lacosa'] ?></td>
                                        <td class="col-cook"><?= $data['cooking']['lakse'] ?></td>
                                        <td class="col-cook"><?= $data['cooking']['saiyo'] ?></td>
                                        <td class="col-cook"><?= $data['cooking']['woku'] ?></td>
                                        <td class="col-cook" style="color:#34d399; font-weight:bold; font-size:1.1rem;"><?= $data['cooking']['total'] ?></td>
                                        
                                        <td style="color:#a78bfa; font-weight:bold;"><?= $data['attendance']['hours'] ?> Jam</td>
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
    <script>
        // Setup Chart.js for Dark Mode
        Chart.defaults.color = '#94a3b8';
        Chart.defaults.font.family = "'Inter', 'Segoe UI', sans-serif";

        const ctx = document.getElementById('performanceChart').getContext('2d');
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: <?= json_encode($chart_menu_labels) ?>,
                datasets: [
                    {
                        label: 'Total Penjualan',
                        data: <?= json_encode($chart_sales_data) ?>,
                        backgroundColor: 'rgba(59, 130, 246, 0.7)', // Blue
                        borderColor: 'rgba(59, 130, 246, 1)',
                        borderWidth: 1,
                        borderRadius: 4
                    },
                    {
                        label: 'Total Masak',
                        data: <?= json_encode($chart_cooking_data) ?>,
                        backgroundColor: 'rgba(16, 185, 129, 0.7)', // Green
                        borderColor: 'rgba(16, 185, 129, 1)',
                        borderWidth: 1,
                        borderRadius: 4
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    title: {
                        display: false,
                    },
                    legend: {
                        position: 'top',
                        labels: {
                            padding: 20,
                            font: { size: 13, weight: 'bold' }
                        }
                    },
                    tooltip: {
                        backgroundColor: 'rgba(15, 23, 42, 0.9)',
                        titleColor: '#fff',
                        bodyColor: '#cbd5e1',
                        borderColor: 'rgba(255,255,255,0.1)',
                        borderWidth: 1,
                        padding: 10
                    }
                },
                scales: {
                    x: {
                        grid: { color: 'rgba(255, 255, 255, 0.05)', drawBorder: false },
                        ticks: { font: { weight: '600' } }
                    },
                    y: {
                        beginAtZero: true,
                        grid: { color: 'rgba(255, 255, 255, 0.05)', drawBorder: false },
                        title: {
                            display: true,
                            text: 'Jumlah Paket / Item',
                            color: '#64748b',
                            font: { weight: 'bold' }
                        }
                    }
                }
            }
        });
    </script>
</body>
</html>