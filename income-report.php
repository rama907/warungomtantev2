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

function formatCurrency($amount) {
    return '$ ' . number_format($amount, 0, ',', '.');
}

// --- Configuration & Constants ---
// Gaji & Bonus (Sistem Baru)
$BASE_SALARIES = [
    'ceo' => 10000,
    'direktur' => 10000,
    'wakil_direktur' => 8000,
    'manager' => 6500,
    'chef' => 5000,
    'waiters' => 3000,
    'karyawan' => 3000,
    'magang' => 2000
];

$MIN_DUTY_HOURS = 10;
$BONUS_DUTY_HOURS = 21;
$BONUS_DUTY_AMOUNT = 1000;

$BONUS_SALES_TIER_1 = 150; // Lebih dari 150 paket
$BONUS_SALES_TIER_2 = 300; // Lebih dari 300 paket
$BONUS_SALES_AMOUNT = 1000; // Per tier kelipatan

// Harga Jual
$PRICE_ABDUL = 150;
$PRICE_LACOSA = 150;
$PRICE_LAKSE = 150;
$PRICE_SAIYO = 150;
$PRICE_WOKU = 150;
$PRICE_HP = 35;
$PRICE_RADIO = 60;

// Rasio Pembagian
$company_ratio = 0.8; 
$employee_ratio = 0.2; 

// --- 1. Calculate Total Payroll Expenditure (Gaji Sistem Baru) ---
$total_payroll_expenditure = 0;

// Query to get Duty Hours and Total Sales Packages per Employee
$employees_raw_data_payroll = $conn->query("
    SELECT e.id, e.name, e.role,
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
");

if ($employees_raw_data_payroll) {
    while ($employee = $employees_raw_data_payroll->fetch_assoc()) {
        $employee_role = strtolower($employee['role']);
        $rounded_duty_hours = roundToNearestHour($employee['total_duty_minutes']);
        $total_sales = (int)$employee['total_sales_packages'];
        
        $gaji_pokok = 0;
        $bonus_duty = 0;
        $bonus_penjualan = 0;

        // A. Gaji Pokok & Bonus Duty
        if ($rounded_duty_hours >= $MIN_DUTY_HOURS) {
            $gaji_pokok = $BASE_SALARIES[$employee_role] ?? 3000;
            
            // Cek Bonus Jam
            if ($rounded_duty_hours >= $BONUS_DUTY_HOURS) {
                $bonus_duty = $BONUS_DUTY_AMOUNT;
            }
        }

        // B. Bonus Penjualan (Hanya Paket Makanan)
        if ($total_sales >= $BONUS_SALES_TIER_2) {
            $bonus_penjualan = $BONUS_SALES_AMOUNT * 2; // $2000
        } elseif ($total_sales >= $BONUS_SALES_TIER_1) {
            $bonus_penjualan = $BONUS_SALES_AMOUNT; // $1000
        }

        $total_payroll_expenditure += ($gaji_pokok + $bonus_duty + $bonus_penjualan);
    }
}

// --- 2. Calculate Total Income & Shares ---
$overall_total_income = 0; 
$company_share_total = 0;  
$employee_commission_total = 0; 

// Query Total Sales Volume by Product
$stmt = $conn->prepare("
    SELECT
        COALESCE(SUM(paket_abdul), 0) as sum_abdul,
        COALESCE(SUM(paket_lacosa), 0) as sum_lacosa,
        COALESCE(SUM(paket_lakse), 0) as sum_lakse,
        COALESCE(SUM(paket_saiyo), 0) as sum_saiyo,
        COALESCE(SUM(paket_woku), 0) as sum_woku,
        COALESCE(SUM(hp), 0) as sum_hp,
        COALESCE(SUM(radio), 0) as sum_radio
    FROM sales_data
");

if ($stmt) {
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if ($result) {
        $overall_total_income = 
            ($result['sum_abdul'] * $PRICE_ABDUL) + 
            ($result['sum_lacosa'] * $PRICE_LACOSA) + 
            ($result['sum_lakse'] * $PRICE_LAKSE) + 
            ($result['sum_saiyo'] * $PRICE_SAIYO) + 
            ($result['sum_woku'] * $PRICE_WOKU) +
            ($result['sum_hp'] * $PRICE_HP) +
            ($result['sum_radio'] * $PRICE_RADIO);
        
        $company_share_total = $overall_total_income * $company_ratio;
        $employee_commission_total = $overall_total_income * $employee_ratio;
    }
}

// Net Income
$net_income = $company_share_total - $total_payroll_expenditure;

// --- 3. Chart Data (Weekly) ---
$chart_data_from_db = [];
$today = new DateTime();
$start_of_week = clone $today;
if ($start_of_week->format('N') != 1) $start_of_week->modify('last Monday');
$end_of_week = clone $start_of_week; $end_of_week->modify('+6 days');

$stmt_daily = $conn->prepare("
    SELECT 
        date, 
        SUM(paket_abdul) as s_abdul, SUM(paket_lacosa) as s_lacosa, 
        SUM(paket_lakse) as s_lakse, SUM(paket_saiyo) as s_saiyo, 
        SUM(paket_woku) as s_woku, SUM(hp) as s_hp, SUM(radio) as s_radio
    FROM sales_data 
    WHERE date BETWEEN ? AND ? 
    GROUP BY date
");

if ($stmt_daily) {
    $stmt_daily->bind_param("ss", $start_of_week->format('Y-m-d'), $end_of_week->format('Y-m-d'));
    $stmt_daily->execute();
    $res = $stmt_daily->get_result();
    while ($r = $res->fetch_assoc()) {
        $omset = ($r['s_abdul'] * $PRICE_ABDUL) + 
                 ($r['s_lacosa'] * $PRICE_LACOSA) + 
                 ($r['s_lakse'] * $PRICE_LAKSE) +
                 ($r['s_saiyo'] * $PRICE_SAIYO) +
                 ($r['s_woku'] * $PRICE_WOKU) +
                 ($r['s_hp'] * $PRICE_HP) +
                 ($r['s_radio'] * $PRICE_RADIO);
        $chart_data_from_db[$r['date']] = $omset * $company_ratio;
    }
}
$chart_labels = []; $chart_data_revenue = [];
for ($i = 0; $i < 7; $i++) {
    $d = clone $start_of_week; $d->modify("+{$i} days");
    $chart_labels[] = $d->format('D, d M');
    $chart_data_revenue[] = $chart_data_from_db[$d->format('Y-m-d')] ?? 0;
}

// --- 4. Logs & Member Summary ---
$omset_logs = [];
$member_summary = []; 

$stmt_logs = $conn->query("
    SELECT sd.date, sd.input_time, e.name as employee_name,
        (
            (sd.paket_abdul * {$PRICE_ABDUL}) + 
            (sd.paket_lacosa * {$PRICE_LACOSA}) + 
            (sd.paket_lakse * {$PRICE_LAKSE}) + 
            (sd.paket_saiyo * {$PRICE_SAIYO}) + 
            (sd.paket_woku * {$PRICE_WOKU}) +
            (sd.hp * {$PRICE_HP}) +
            (sd.radio * {$PRICE_RADIO})
        ) as total_val
    FROM sales_data sd JOIN employees e ON sd.employee_id = e.id
    HAVING total_val > 0
    ORDER BY input_time DESC
");

if ($stmt_logs) {
    while ($row = $stmt_logs->fetch_assoc()) {
        $val = (float)$row['total_val'];
        $emp_val = $val * $employee_ratio;
        $omset_logs[] = [
            'date_time' => date('d/m/Y H:i:s', strtotime($row['input_time'])),
            'employee_name' => $row['employee_name'],
            'omset_kotor' => $val,
            'bagian_perusahaan' => $val * $company_ratio,
            'bagian_karyawan' => $emp_val
        ];
        if (!isset($member_summary[$row['employee_name']])) {
            $member_summary[$row['employee_name']] = ['total_omset' => 0, 'total_komisi' => 0, 'total_transaksi' => 0];
        }
        $member_summary[$row['employee_name']]['total_omset'] += $val;
        $member_summary[$row['employee_name']]['total_komisi'] += $emp_val;
        $member_summary[$row['employee_name']]['total_transaksi'] += 1;
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laporan Pemasukan - Warung Om Tante V2</title>
    <link rel="icon" href="LOGO_WOT.png" type="image/png">
    <link rel="shortcut icon" href="favicon.ico" type="image/x-icon">
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
            background: radial-gradient(circle, rgba(16, 185, 129, 0.15), transparent 70%); pointer-events: none; z-index: 0;
        }
        .header-content-wrapper { display: flex; align-items: center; gap: 1.25rem; z-index: 2; position: relative; }
        .header-icon-wrapper {
            background: rgba(255, 255, 255, 0.05); border: 1px solid rgba(255, 255, 255, 0.1);
            width: 60px; height: 60px; border-radius: 16px; display: flex; align-items: center;
            justify-content: center; font-size: 1.8rem; box-shadow: inset 0 0 15px rgba(0,0,0,0.3); flex-shrink: 0; color: #10b981;
        }
        .header-text-wrapper h1 { color: #fff; font-weight: 800; font-size: 2.2rem; letter-spacing: -0.02em; margin: 0; }
        .header-text-wrapper p { color: var(--text-secondary); margin: 0.25rem 0 0 0; font-size: 1.05rem; }

        /* --- Stats Grid Modern --- */
        .stats-grid-modern {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1.5rem; margin-bottom: 1.5rem;
            animation: slideUp 0.6s ease-out 0.1s backwards;
        }
        .stats-grid-modern.two-cols { grid-template-columns: repeat(auto-fit, minmax(350px, 1fr)); }

        .stat-card-modern {
            background: rgba(30, 41, 59, 0.5); backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.05); border-radius: 20px; padding: 1.75rem;
            position: relative; overflow: hidden; transition: 0.3s; display: flex; flex-direction: column; justify-content: center;
        }
        .stat-card-modern:hover { transform: translateY(-5px); border-color: rgba(255,255,255,0.15); box-shadow: 0 15px 30px rgba(0,0,0,0.3); }
        
        .stat-card-modern::before { content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 4px; }
        .sc-company::before { background: #3b82f6; box-shadow: 0 0 10px #3b82f6; }
        .sc-commission::before { background: #f59e0b; box-shadow: 0 0 10px #f59e0b; }
        .sc-total::before { background: #eab308; box-shadow: 0 0 10px #eab308; } /* Yellow for Omset */
        .sc-payroll::before { background: #ef4444; box-shadow: 0 0 10px #ef4444; }
        .sc-net::before { background: #10b981; box-shadow: 0 0 10px #10b981; }

        .stat-card-modern h4 { font-size: 0.9rem; color: #cbd5e1; margin-bottom: 8px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; display: flex; align-items: center; gap: 8px; }
        .stat-card-modern .value { font-size: 2.2rem; font-weight: 800; margin-bottom: 5px; }
        .stat-card-modern .sub-text { font-size: 0.8rem; color: #64748b; font-weight: 500;}

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

        /* --- Chart Wrapper --- */
        .chart-wrapper {
            background: rgba(0, 0, 0, 0.2); border-radius: 16px; border: 1px solid rgba(255,255,255,0.05);
            padding: 1rem; position: relative; height: 380px; width: 100%;
        }

        /* --- Modern Table --- */
        .modern-table-wrapper {
            background: rgba(0, 0, 0, 0.25); border-radius: 18px; border: 1px solid rgba(255, 255, 255, 0.05); overflow-x: auto;
        }
        .report-table { width: 100%; border-collapse: collapse; font-size: 0.95rem; color: #e2e8f0; }
        .report-table th, .report-table td {
            padding: 1.2rem 1.5rem; border-bottom: 1px solid rgba(255, 255, 255, 0.05); text-align: left; vertical-align: middle;
        }
        .report-table th { background: rgba(255, 255, 255, 0.03); font-weight: 600; text-transform: uppercase; font-size: 0.8rem; letter-spacing: 0.05em; color: #94a3b8; }
        .report-table tr:hover td { background: rgba(255, 255, 255, 0.05); }
        .report-table tr:last-child td { border-bottom: none; }

        .emp-name { font-weight: 700; color: #fff; display: flex; align-items: center; gap: 10px; }
        .emp-avatar-mini { width: 32px; height: 32px; border-radius: 50%; background: rgba(255,255,255,0.1); color: #fff; display: flex; align-items: center; justify-content: center; font-size: 0.8rem; font-weight: bold;}

        /* Badges for Money */
        .badge-money {
            display: inline-flex; align-items: center; padding: 6px 12px; border-radius: 8px; font-weight: 800; font-size: 0.9rem; letter-spacing: 0.5px;
        }
        .b-komisi { background: rgba(245, 158, 11, 0.15); color: #fbbf24; border: 1px solid rgba(245, 158, 11, 0.3); }
        .b-perusahaan { background: rgba(16, 185, 129, 0.15); color: #34d399; border: 1px solid rgba(16, 185, 129, 0.3); }
        .b-omset { background: rgba(59, 130, 246, 0.15); color: #60a5fa; border: 1px solid rgba(59, 130, 246, 0.3); }

        @keyframes slideDown { from { opacity: 0; transform: translateY(-20px); } to { opacity: 1; transform: translateY(0); } }
        @keyframes slideUp { from { opacity: 0; transform: translateY(30px); } to { opacity: 1; transform: translateY(0); } }

        @media (max-width: 768px) {
            .modern-page-header { padding: 1.5rem; text-align: center; }
            .header-content-wrapper { flex-direction: column; }
            .header-text-wrapper h1 { font-size: 1.8rem; }
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
                        <h1>Laporan Keuangan</h1>
                        <p>Distribusi Pendapatan: 80% Kas Perusahaan | 20% Komisi Anggota</p>
                    </div>
                </div>
            </div>

            <div class="stats-grid-modern">
                <div class="stat-card-modern sc-company">
                    <h4>🏢 Bagian Perusahaan (80%)</h4>
                    <div class="value" style="color: #60a5fa;"><?= formatCurrency($company_share_total) ?></div>
                </div>
                <div class="stat-card-modern sc-commission">
                    <h4>🤝 Total Komisi Anggota (20%)</h4>
                    <div class="value" style="color: #fbbf24;"><?= formatCurrency($employee_commission_total) ?></div>
                </div>
                <div class="stat-card-modern sc-total" style="background: rgba(234, 179, 8, 0.1);">
                    <h4 style="color: #fde047;">💰 TOTAL OMSET PENJUALAN (100%)</h4>
                    <div class="value" style="color: #facc15;"><?= formatCurrency($overall_total_income) ?></div>
                </div>
            </div>

            <div class="stats-grid-modern two-cols">
                <div class="stat-card-modern sc-payroll">
                    <h4>💵 Total Pengeluaran Gaji</h4>
                    <div class="value" style="color: #f87171;"><?= formatCurrency($total_payroll_expenditure) ?></div>
                    <div class="sub-text">Syarat Gaji: Min 10J | Bonus: >21J & >150/300 Pkt</div>
                </div>
                <div class="stat-card-modern sc-net">
                    <h4>📈 Profit Bersih (Net)</h4>
                    <div class="value" style="color: #34d399;"><?= formatCurrency($net_income) ?></div>
                    <div class="sub-text">(80% Omset) - (Pengeluaran Gaji)</div>
                </div>
            </div>

            <div class="modern-card">
                <div class="modern-card-header">
                    <h3><span>📊</span> Grafik Pendapatan Kas Perusahaan (Harian)</h3>
                </div>
                <div class="chart-wrapper">
                    <canvas id="dailyRevenueChart"></canvas>
                </div>
            </div>

            <div class="modern-card">
                <div class="modern-card-header">
                    <h3><span>👥</span> Ringkasan Komisi per Anggota</h3>
                </div>
                <div class="modern-table-wrapper">
                    <table class="report-table">
                        <thead>
                            <tr>
                                <th>Nama Anggota</th>
                                <th style="text-align: center;">Total Transaksi</th>
                                <th>Total Penjualan (100%)</th>
                                <th>🏦 Hak Komisi (20%)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($member_summary)): ?>
                                <tr><td colspan="4" style="text-align: center; font-style: italic; color: #64748b;">Belum ada data transaksi.</td></tr>
                            <?php else: ?>
                                <?php foreach ($member_summary as $name => $data): ?>
                                <tr>
                                    <td>
                                        <div class="emp-name">
                                            <div class="emp-avatar-mini"><?= strtoupper(substr($name, 0, 1)) ?></div>
                                            <?= htmlspecialchars($name) ?>
                                        </div>
                                    </td>
                                    <td style="text-align: center; color: #94a3b8;"><?= $data['total_transaksi'] ?> Transaksi</td>
                                    <td><span class="badge-money b-omset"><?= formatCurrency($data['total_omset']) ?></span></td>
                                    <td><span class="badge-money b-komisi"><?= formatCurrency($data['total_komisi']) ?></span></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="modern-card">
                <div class="modern-card-header">
                    <h3><span>📜</span> Logs Detail Transaksi</h3>
                </div>
                <div class="modern-table-wrapper">
                    <table class="report-table">
                        <thead>
                            <tr>
                                <th>Waktu</th>
                                <th>Penjual</th>
                                <th>Omset Kotor</th>
                                <th>🏢 Kas Perusahaan</th>
                                <th>🏦 Komisi Anggota</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($omset_logs)): ?>
                                <tr><td colspan="5" style="text-align: center; font-style: italic; color: #64748b;">Belum ada log transaksi.</td></tr>
                            <?php else: ?>
                                <?php foreach ($omset_logs as $log): ?>
                                <tr>
                                    <td style="color: #94a3b8; font-size: 0.85rem; font-weight: 600;"><?= $log['date_time'] ?></td>
                                    <td style="font-weight: 700; color: #fff;"><?= htmlspecialchars($log['employee_name']) ?></td>
                                    <td><span class="badge-money b-omset" style="font-size: 0.8rem; padding: 4px 8px;"><?= formatCurrency($log['omset_kotor']) ?></span></td>
                                    <td><span class="badge-money b-perusahaan" style="font-size: 0.8rem; padding: 4px 8px;"><?= formatCurrency($log['bagian_perusahaan']) ?></span></td>
                                    <td><span class="badge-money b-komisi" style="font-size: 0.8rem; padding: 4px 8px;"><?= formatCurrency($log['bagian_karyawan']) ?></span></td>
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
        document.addEventListener('DOMContentLoaded', function() {
            // Setup Chart.js for Dark Mode
            Chart.defaults.color = '#94a3b8';
            Chart.defaults.font.family = "'Inter', 'Segoe UI', sans-serif";

            const ctx = document.getElementById('dailyRevenueChart').getContext('2d');
            new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: <?= json_encode($chart_labels) ?>,
                    datasets: [{
                        label: 'Masuk Kas ($)',
                        data: <?= json_encode($chart_data_revenue) ?>,
                        backgroundColor: 'rgba(59, 130, 246, 0.7)', // Blue matching company share
                        borderColor: 'rgba(59, 130, 246, 1)',
                        borderWidth: 1,
                        borderRadius: 6
                    }]
                },
                options: { 
                    responsive: true, 
                    maintainAspectRatio: false, 
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            backgroundColor: 'rgba(15, 23, 42, 0.9)',
                            titleColor: '#fff',
                            bodyColor: '#cbd5e1',
                            borderColor: 'rgba(255,255,255,0.1)',
                            borderWidth: 1,
                            padding: 10,
                            callbacks: {
                                label: function(context) {
                                    let label = context.dataset.label || '';
                                    if (label) {
                                        label += ': ';
                                    }
                                    if (context.parsed.y !== null) {
                                        label += '$ ' + new Intl.NumberFormat('id-ID').format(context.parsed.y);
                                    }
                                    return label;
                                }
                            }
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
                            ticks: {
                                callback: function(value, index, values) {
                                    return '$ ' + new Intl.NumberFormat('id-ID').format(value);
                                }
                            }
                        } 
                    } 
                }
            });
        });
    </script>
</body>
</html>