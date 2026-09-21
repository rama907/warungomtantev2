<?php
require_once 'config.php';

// Hanya direktur, wakil_direktur, dan manager yang bisa mengakses halaman ini
if (!isLoggedIn() || !hasRole(['ceo', 'direktur', 'wakil_direktur', 'manager'])) {
    header('Location: dashboard.php');
    exit;
}

$user = getCurrentUser();
$pending_requests_count = getPendingRequestCount(); // Untuk sidebar

global $conn; // Mengakses koneksi database global

// --- PENGATURAN TANGGAL ---
// Tentukan periode rekap (minggu berjalan: dari Senin minggu ini hingga hari ini)
$start_date_obj = new DateTime('this week monday'); // Mulai dari Senin minggu ini
$end_date_obj = new DateTime('today');   // Sampai hari ini

if ($start_date_obj > $end_date_obj) {
    $start_date_obj = clone $end_date_obj; 
}

$end_date_for_period = clone $end_date_obj; 
$end_date_for_period->modify('+1 day'); 

$interval = DateInterval::createFromDateString('1 day');
$period = new DatePeriod($start_date_obj, $interval, $end_date_for_period);

$dates_in_period = [];
foreach ($period as $dt) {
    $dates_in_period[] = $dt->format('Y-m-d');
}

// Mengambil semua anggota aktif
$stmt_employees = $conn->query("SELECT id, name FROM employees WHERE status = 'active' ORDER BY name");
$employees = $stmt_employees->fetch_all(MYSQLI_ASSOC);


// --- LOGIKA 1: DETAIL JAM DUTY (Tabel Atas) ---
$selected_employee = isset($_GET['employee_id']) ? $_GET['employee_id'] : 'all';

$week_start = new DateTime('this week monday');
$week_end = new DateTime('this week sunday');

$detailed_duty_logs = [];
// Mengambil is_manual untuk membedakan input manual vs realtime
$stmt_detailed = $conn->prepare("SELECT employee_id, duty_start, duty_end, status, is_manual FROM duty_logs WHERE duty_start >= ? AND duty_start <= ? AND status IN ('completed', 'active')");
$stmt_detailed->bind_param("ss", $week_start->format('Y-m-d 00:00:00'), $week_end->format('Y-m-d 23:59:59'));
$stmt_detailed->execute();
$result_detailed = $stmt_detailed->get_result();

if ($result_detailed instanceof mysqli_result) {
    while ($row = $result_detailed->fetch_assoc()) {
        $date_key = date('Y-m-d', strtotime($row['duty_start']));
        $start_time = date('H:i', strtotime($row['duty_start']));
        
        if ($row['status'] === 'active') {
            // Sedang On Duty (Tambahkan span untuk animasi titik berkedip)
            $text = "<span class='live-dot'></span>" . $start_time . ' - On Duty';
            $badge_class = 'time-badge-active';
        } else {
            // Selesai Duty
            $end_time = $row['duty_end'] ? date('H:i', strtotime($row['duty_end'])) : '...';
            
            // Cek apakah input manual
            if ($row['is_manual']) {
                $text = $start_time . ' - ' . $end_time . ' (Manual)';
                $badge_class = 'time-badge-manual';
            } else {
                $text = $start_time . ' - ' . $end_time;
                $badge_class = 'time-badge-completed';
            }
        }
        
        $detailed_duty_logs[$row['employee_id']][$date_key][] = [
            'text' => $text,
            'class' => $badge_class
        ];
    }
    $result_detailed->free();
}
$stmt_detailed->close();

$full_week_dates = [];
$period_full = new DatePeriod($week_start, new DateInterval('P1D'), (clone $week_end)->modify('+1 day'));
foreach ($period_full as $dt) {
    $full_week_dates[] = $dt->format('Y-m-d');
}

$hari_indo = [
    'Monday' => 'Senin', 'Tuesday' => 'Selasa', 'Wednesday' => 'Rabu',
    'Thursday' => 'Kamis', 'Friday' => 'Jumat', 'Saturday' => 'Sabtu', 'Sunday' => 'Minggu'
];

// --- LOGIKA BARU: DETEKSI KANDIDAT SP 1 (TIDAK ADA DUTY / IZIN DALAM 7 HARI TERAKHIR) ---
$sp1_candidates = [];
$query_sp1 = "
    SELECT id FROM employees 
    WHERE status = 'active' 
    AND id NOT IN (SELECT employee_id FROM duty_logs WHERE duty_start >= DATE(SUBDATE(NOW(), INTERVAL 7 DAY))) 
    AND id NOT IN (SELECT employee_id FROM leave_requests WHERE status='approved' AND end_date >= DATE(SUBDATE(NOW(), INTERVAL 7 DAY)))
";
$result_sp1 = $conn->query($query_sp1);
if ($result_sp1) {
    while ($row = $result_sp1->fetch_assoc()) {
        $sp1_candidates[] = $row['id'];
    }
}


// --- LOGIKA 2: REKAP ABSENSI HARIAN (Tabel Bawah) ---
$attendance_data = [];

// Mengambil semua log duty yang completed untuk periode ini
$duty_logs_by_employee_date = [];
$stmt_duty = $conn->prepare("SELECT employee_id, DATE(duty_start) as duty_date FROM duty_logs WHERE duty_start >= ? AND duty_start <= ? AND status = 'completed'");
$stmt_duty->bind_param("ss", $start_date_obj->format('Y-m-d 00:00:00'), $end_date_obj->format('Y-m-d 23:59:59'));
$stmt_duty->execute();
$result_duty = $stmt_duty->get_result();

if ($result_duty instanceof mysqli_result) { 
    $row_duty = $result_duty->fetch_assoc(); 
    while ($row_duty !== null) { 
        $duty_logs_by_employee_date[$row_duty['employee_id']][$row_duty['duty_date']] = true;
        $row_duty = $result_duty->fetch_assoc(); 
    }
    $result_duty->free(); 
}
$stmt_duty->close();

// Mengambil semua permohonan cuti yang disetujui untuk periode ini
$leave_requests_by_employee = [];
$stmt_leave = $conn->prepare("SELECT employee_id, start_date, end_date FROM leave_requests WHERE (start_date <= ? AND end_date >= ?) AND status = 'approved'");
$stmt_leave->bind_param("ss", $end_date_obj->format('Y-m-d'), $start_date_obj->format('Y-m-d'));
$stmt_leave->execute();
$result_leave = $stmt_leave->get_result();

if ($result_leave instanceof mysqli_result) { 
    $row_leave = $result_leave->fetch_assoc(); 
    while ($row_leave !== null) { 
        $leave_requests_by_employee[$row_leave['employee_id']][] = [
            'start' => new DateTime($row_leave['start_date']),
            'end' => new DateTime($row_leave['end_date'])
        ];
        $row_leave = $result_leave->fetch_assoc(); 
    }
    $result_leave->free(); 
}
$stmt_leave->close();

// Proses data absensi per karyawan per hari
foreach ($employees as $employee) {
    $employee_id = $employee['id'];
    $daily_statuses = [];
    $max_consecutive_absent = 0; 
    $current_consecutive_absent = 0; 

    foreach ($dates_in_period as $date_str) {
        $status = 'Absen'; 
        $current_day_obj = new DateTime($date_str);

        if (isset($leave_requests_by_employee[$employee_id])) {
            foreach ($leave_requests_by_employee[$employee_id] as $leave) {
                if ($current_day_obj >= $leave['start'] && $current_day_obj <= $leave['end']) {
                    $status = 'Izin';
                    break;
                }
            }
        }

        if ($status === 'Absen') { 
            if (isset($duty_logs_by_employee_date[$employee_id][$date_str])) {
                $status = 'Masuk';
            }
        }
        
        if ($status === 'Absen') {
            $current_consecutive_absent++;
        } else {
            $current_consecutive_absent = 0; 
        }

        if ($current_consecutive_absent > $max_consecutive_absent) {
            $max_consecutive_absent = $current_consecutive_absent;
        }

        $daily_statuses[$date_str] = $status;
    }

    $attendance_data[] = [
        'employee_id' => $employee_id,
        'employee_name' => htmlspecialchars($employee['name']),
        'daily_statuses' => $daily_statuses,
        'max_consecutive_absent' => $max_consecutive_absent
    ];
}

?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rekap Absensi - Warung Om Tante V2</title>
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

        /* --- Info Box Modern --- */
        .modern-info-box {
            background: rgba(59, 130, 246, 0.1); border: 1px solid rgba(59, 130, 246, 0.3);
            border-radius: 16px; padding: 1.25rem 1.5rem; color: #93c5fd; font-size: 0.95rem; line-height: 1.6;
            display: flex; align-items: flex-start; gap: 15px; margin-bottom: 1rem; animation: slideUp 0.6s ease-out 0.1s backwards;
        }
        .modern-info-box span.icon { font-size: 1.8rem; flex-shrink: 0; margin-top: 2px;}
        .modern-info-box strong { color: #bfdbfe; }

        /* Info Box Danger (SP 1) */
        .modern-info-box.danger {
            background: rgba(239, 68, 68, 0.1); border-color: rgba(239, 68, 68, 0.3); color: #fca5a5;
        }

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

        /* --- Toolbar (Filter & Legend) --- */
        .toolbar-modern {
            display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem; margin-bottom: 1.5rem;
        }
        .filter-container-modern { display: flex; align-items: center; gap: 10px; }
        .filter-container-modern label { font-weight: 600; color: #cbd5e1; font-size: 0.9rem; }
        .filter-select-modern {
            background: rgba(0, 0, 0, 0.3); border: 1px solid rgba(255, 255, 255, 0.1); color: #fff;
            padding: 0.5rem 1rem; border-radius: 10px; font-size: 0.9rem; cursor: pointer; outline: none; transition: 0.3s;
        }
        .filter-select-modern:focus { border-color: var(--primary-color); }
        .filter-select-modern option { background: #0f172a; color: #fff; }

        .legend-container-modern { display: flex; gap: 15px; flex-wrap: wrap; }
        .legend-item-modern { display: flex; align-items: center; gap: 6px; font-size: 0.85rem; font-weight: 600; color: #94a3b8; }
        .legend-color-modern { width: 14px; height: 14px; border-radius: 4px; }
        
        /* Warna Legend */
        .lc-success { background-color: rgba(16, 185, 129, 0.8); border: 1px solid #10b981; }
        .lc-secondary { background-color: rgba(255, 255, 255, 0.1); border: 1px solid rgba(255, 255, 255, 0.2); }
        .lc-warning { background-color: rgba(245, 158, 11, 0.8); border: 1px solid #f59e0b; }

        /* --- Modern Table --- */
        .modern-table-wrapper {
            background: rgba(0, 0, 0, 0.25); border-radius: 18px; border: 1px solid rgba(255, 255, 255, 0.05); overflow-x: auto;
        }
        .report-table { width: 100%; border-collapse: collapse; font-size: 0.9rem; min-width: 900px; color: #e2e8f0; }
        .report-table th, .report-table td {
            padding: 1.2rem 1rem; border-bottom: 1px solid rgba(255, 255, 255, 0.03); text-align: center; vertical-align: middle;
        }
        .report-table th { background: rgba(255, 255, 255, 0.03); font-weight: 600; text-transform: uppercase; font-size: 0.75rem; letter-spacing: 0.05em; color: #94a3b8; }
        
        /* Sticky Name Column */
        .report-table th:first-child, .report-table td:first-child { 
            position: sticky; left: 0; z-index: 2; background: rgba(15, 23, 42, 0.95); text-align: left; font-weight: 600;
            box-shadow: 2px 0 5px rgba(0,0,0,0.1);
        }
        .report-table tr:hover td { background: rgba(255, 255, 255, 0.05); }
        .report-table tr:hover td:first-child { background: rgba(30, 41, 59, 0.95); }
        .report-table tr:last-child td { border-bottom: none; }

        /* Badge Waktu Duty */
        .time-badge {
            display: inline-flex; align-items: center; justify-content: center;
            padding: 0.4em 0.8em; margin: 0.2em 0; border-radius: 8px; font-size: 0.8rem; white-space: nowrap; font-weight: 600; width: 100%;
        }
        .time-badge-completed { background-color: rgba(255, 255, 255, 0.05); color: #cbd5e1; border: 1px solid rgba(255, 255, 255, 0.1); }
        .time-badge-active {
            background-color: rgba(16, 185, 129, 0.15); color: #34d399; border: 1px solid #10b981;
            animation: border-pulse 2s infinite ease-in-out; font-weight: 800; box-shadow: 0 0 10px rgba(16, 185, 129, 0.2);
        }
        .time-badge-manual { background-color: rgba(245, 158, 11, 0.15); color: #fbbf24; border: 1px solid rgba(245, 158, 11, 0.4); }

        .live-dot {
            display: inline-block; width: 6px; height: 6px; background-color: #34d399; border-radius: 50%;
            margin-right: 6px; box-shadow: 0 0 8px #34d399; animation: dot-blink 1s infinite;
        }

        /* Status Kehadiran (M, I, A) */
        .status-cell-modern {
            font-weight: 800; color: white; border-radius: 8px; padding: 0.4rem 0.8rem;
            display: inline-flex; align-items: center; justify-content: center; font-size: 0.85rem; box-shadow: 0 2px 5px rgba(0,0,0,0.2);
        }
        .status-Masuk { background: linear-gradient(135deg, #10b981, #059669); }
        .status-Izin { background: linear-gradient(135deg, #f59e0b, #d97706); }
        .status-Absen { background: linear-gradient(135deg, #ef4444, #dc2626); }

        /* Peringatan & Kandidat SP 1 */
        .alert-icon-modern {
            display: inline-flex; align-items: center; justify-content: center; width: 22px; height: 22px;
            border-radius: 50%; background: #ef4444; color: #fff; font-size: 0.75rem; font-weight: 900;
            margin-left: 8px; box-shadow: 0 0 10px #ef4444; animation: dot-blink 1.5s infinite;
        }

        .badge-sp1-suggestion {
            background: linear-gradient(135deg, #ef4444, #b91c1c); color: #fff; padding: 4px 8px; border-radius: 6px;
            font-size: 0.7rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; display: inline-flex;
            align-items: center; gap: 4px; box-shadow: 0 0 10px rgba(239, 68, 68, 0.4); animation: pulse-danger 2s infinite;
            text-decoration: none; margin-top: 6px; border: 1px solid rgba(255,255,255,0.2);
        }
        .badge-sp1-suggestion:hover { transform: scale(1.05); }

        .consecutive-absent-cell { font-weight: 800; color: #fca5a5; }
        .consecutive-absent-cell.green { color: #34d399; }

        @keyframes dot-blink { 0%, 100% { opacity: 1; transform: scale(1); } 50% { opacity: 0.5; transform: scale(0.85); } }
        @keyframes border-pulse { 0%, 100% { border-color: rgba(16, 185, 129, 0.4); box-shadow: 0 0 5px rgba(16, 185, 129, 0.1); } 50% { border-color: rgba(16, 185, 129, 1); box-shadow: 0 0 12px rgba(16, 185, 129, 0.4); } }
        @keyframes pulse-danger { 0% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.7); } 70% { box-shadow: 0 0 0 6px rgba(239, 68, 68, 0); } 100% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0); } }
        @keyframes slideDown { from { opacity: 0; transform: translateY(-20px); } to { opacity: 1; transform: translateY(0); } }
        @keyframes slideUp { from { opacity: 0; transform: translateY(30px); } to { opacity: 1; transform: translateY(0); } }

        @media (max-width: 768px) {
            .modern-page-header { padding: 1.5rem; text-align: center; }
            .header-content-wrapper { flex-direction: column; }
            .toolbar-modern { flex-direction: column; align-items: stretch; }
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
                    <div class="header-icon-wrapper">📅</div>
                    <div class="header-text-wrapper">
                        <h1>Rekap Absensi Anggota</h1>
                        <p>Ikhtisar status kehadiran anggota untuk periode minggu ini (<strong><?= $start_date_obj->format('d M') ?></strong> - <strong><?= $end_date_obj->format('d M Y') ?></strong>).</p>
                    </div>
                </div>
            </div>
            
            <div class="modern-info-box">
                <span class="icon">ℹ️</span>
                <div>
                    <strong>Informasi:</strong> Tanda seru <span style="color:#ef4444; font-weight:900;">(!)</span> yang berkedip merah di sebelah nama menunjukkan bahwa anggota tersebut memiliki lebih dari 3 hari absen berturut-turut dalam periode minggu ini.
                </div>
            </div>

            <div class="modern-info-box danger" style="margin-bottom: 2rem;">
                <span class="icon">🚨</span>
                <div>
                    <strong>Tindakan Dibutuhkan:</strong> Anggota dengan label <span style="background: #ef4444; color: #fff; padding: 2px 6px; border-radius: 4px; font-size: 0.7rem; font-weight: bold; border: 1px solid rgba(255,255,255,0.3);">⚠️ KANDIDAT SP 1</span> tidak memiliki riwayat kehadiran (duty) maupun surat izin yang disetujui dalam <strong>7 hari (1 Minggu) penuh terakhir</strong> ke belakang. Klik tombol tersebut untuk langsung menuju halaman pemberian SP.
                </div>
            </div>

            <div class="modern-card">
                <div class="modern-card-header">
                    <h3><span>⏱️</span> Detail Jam Duty (Senin - Minggu)</h3>
                </div>

                <div class="toolbar-modern">
                    <form method="GET" action="" class="filter-container-modern">
                        <label for="employee_id">Filter Nama Anggota:</label>
                        <select name="employee_id" id="employee_id" class="filter-select-modern" onchange="this.form.submit()">
                            <option value="all">Semua Anggota</option>
                            <?php foreach ($employees as $emp): ?>
                                <option value="<?= $emp['id'] ?>" <?= $selected_employee == $emp['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($emp['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                    
                    <div class="legend-container-modern">
                        <div class="legend-item-modern"><div class="legend-color-modern lc-success"></div> Sedang On Duty</div>
                        <div class="legend-item-modern"><div class="legend-color-modern lc-secondary"></div> Duty Normal</div>
                        <div class="legend-item-modern"><div class="legend-color-modern lc-warning"></div> Input Manual</div>
                    </div>
                </div>

                <div class="modern-table-wrapper">
                    <table class="report-table">
                        <thead>
                            <tr>
                                <th>Anggota</th>
                                <?php foreach ($full_week_dates as $date_str): ?>
                                    <th>
                                        <?= $hari_indo[date('l', strtotime($date_str))] ?><br>
                                        <span style="font-size: 0.7rem; color: var(--text-muted); font-weight: normal;"><?= date('d/m', strtotime($date_str)) ?></span>
                                    </th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $filtered_employees = $selected_employee === 'all' 
                                ? $employees 
                                : array_filter($employees, function($e) use ($selected_employee) { return $e['id'] == $selected_employee; });

                            if (empty($filtered_employees)): 
                            ?>
                                <tr>
                                    <td colspan="<?= count($full_week_dates) + 1 ?>" style="text-align: center; padding: 2rem; font-style: italic; color: #64748b;">Tidak ada data untuk ditampilkan.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($filtered_employees as $emp): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($emp['name']) ?></td>
                                        <?php foreach ($full_week_dates as $date_str): ?>
                                            <td style="white-space: normal; padding: 0.5rem;">
                                                <div style="display: flex; flex-direction: column; gap: 4px;">
                                                <?php 
                                                if (isset($detailed_duty_logs[$emp['id']][$date_str])) {
                                                    foreach ($detailed_duty_logs[$emp['id']][$date_str] as $log) {
                                                        echo "<div class='time-badge {$log['class']}'>{$log['text']}</div>";
                                                    }
                                                } else {
                                                    echo "<span style='color: rgba(255,255,255,0.2);'>-</span>";
                                                }
                                                ?>
                                                </div>
                                            </td>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="modern-card">
                <div class="modern-card-header">
                    <h3><span>📅</span> Rekap Kehadiran Harian & Peringatan</h3>
                </div>
                
                <div class="modern-table-wrapper">
                    <table class="report-table">
                        <thead>
                            <tr>
                                <th>Anggota</th>
                                <?php foreach ($dates_in_period as $date_str): ?>
                                    <th><?= date('d/m', strtotime($date_str)) ?></th>
                                <?php endforeach; ?>
                                <th>Maks. Absen Beruntun</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($employees)): ?>
                                <tr>
                                    <td colspan="<?= count($dates_in_period) + 2 ?>" style="text-align: center; padding: 2rem; font-style: italic; color: #64748b;">Tidak ada anggota aktif untuk ditampilkan.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($attendance_data as $data): ?>
                                    <tr>
                                        <td style="border-bottom: none; vertical-align: top;">
                                            <div style="display: flex; flex-direction: column; gap: 6px;">
                                                <div style="display: flex; align-items: center; justify-content: space-between; width: 100%;">
                                                    <span style="font-weight: 700; color: #fff;"><?= htmlspecialchars($data['employee_name']) ?></span>
                                                    <?php if ($data['max_consecutive_absent'] > 3 && !in_array($data['employee_id'], $sp1_candidates)): ?>
                                                        <span class="alert-icon-modern" title="Peringatan: Absen > 3 Hari Berturut-turut!">!</span>
                                                    <?php endif; ?>
                                                </div>
                                                
                                                <?php if (in_array($data['employee_id'], $sp1_candidates)): ?>
                                                    <a href="warning-management.php?employee_id=<?= $data['employee_id'] ?>" class="badge-sp1-suggestion" title="Proses Peringatan SP 1">
                                                        <span>⚠️</span> Kandidat SP 1
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        
                                        <?php foreach ($data['daily_statuses'] as $date_str => $status): ?>
                                            <td>
                                                <span class="status-cell-modern status-<?= $status ?>" title="<?= $status ?>">
                                                    <?= substr($status, 0, 1) ?>
                                                </span>
                                            </td>
                                        <?php endforeach; ?>
                                        
                                        <td class="consecutive-absent-cell <?= $data['max_consecutive_absent'] == 0 ? 'green' : '' ?>">
                                             <?= $data['max_consecutive_absent'] ?> hari
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