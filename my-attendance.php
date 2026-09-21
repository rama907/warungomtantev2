<?php
require_once 'config.php';

// Redirect ke halaman login jika belum login
if (!isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$user = getCurrentUser(); // Mengambil data pengguna yang sedang login
$pending_requests_count = getPendingRequestCount(); // Untuk indikator sidebar

global $conn; // Mengakses koneksi database global

$employee_id = $user['id']; // Fokus pada pengguna yang sedang login

// --- LOGIKA 1: PENGAMBILAN DATA ABSENSI HARIAN (GRID MINGGU INI) ---
$weekly_attendance_data = [];

// Tentukan periode rekap (minggu berjalan: dari Senin minggu ini hingga hari ini)
$start_date_obj_week = new DateTime('this week monday');
$end_date_obj_week = new DateTime('today');

// Buat periode iterasi harian, termasuk tanggal akhir
$end_date_for_period_week = clone $end_date_obj_week;
$end_date_for_period_week->modify('+1 day'); 
$interval_week = DateInterval::createFromDateString('1 day');
$period_week = new DatePeriod($start_date_obj_week, $interval_week, $end_date_for_period_week);

$dates_in_week = [];
foreach ($period_week as $dt) {
    $dates_in_week[] = $dt->format('Y-m-d');
}

// Mengambil log duty yang completed untuk periode ini (hanya untuk user yang login)
$duty_logs_by_date = [];
$stmt_duty_week = $conn->prepare("SELECT DATE(duty_start) as duty_date FROM duty_logs WHERE employee_id = ? AND duty_start >= ? AND duty_start <= ? AND status = 'completed'");
$stmt_duty_week->bind_param("iss", $employee_id, $start_date_obj_week->format('Y-m-d 00:00:00'), $end_date_obj_week->format('Y-m-d 23:59:59'));
$stmt_duty_week->execute();
$result_duty_week = $stmt_duty_week->get_result();
if ($result_duty_week instanceof mysqli_result) {
    while ($row = $result_duty_week->fetch_assoc()) {
        $duty_logs_by_date[$row['duty_date']] = true;
    }
    $result_duty_week->free();
}
$stmt_duty_week->close();

// Mengambil permohonan cuti yang disetujui untuk periode ini (hanya untuk user yang login)
$leave_requests = [];
$stmt_leave_week = $conn->prepare("SELECT start_date, end_date FROM leave_requests WHERE employee_id = ? AND (start_date <= ? AND end_date >= ?) AND status = 'approved'");
$stmt_leave_week->bind_param("iss", $employee_id, $end_date_obj_week->format('Y-m-d'), $start_date_obj_week->format('Y-m-d'));
$stmt_leave_week->execute();
$result_leave_week = $stmt_leave_week->get_result();
if ($result_leave_week instanceof mysqli_result) {
    while ($row = $result_leave_week->fetch_assoc()) {
        $leave_requests[] = [
            'start' => new DateTime($row['start_date']),
            'end' => new DateTime($row['end_date'])
        ];
    }
    $result_leave_week->free();
}
$stmt_leave_week->close();

// Inisialisasi penghitung absen beruntun dalam minggu ini
$max_consecutive_absent = 0;
$current_consecutive_absent = 0;

// Proses status absensi per hari untuk minggu ini
foreach ($dates_in_week as $date_str) {
    $status = 'Absen'; // Default status
    $current_day_obj = new DateTime($date_str);

    // 1. Cek status "Izin" (cuti yang disetujui)
    foreach ($leave_requests as $leave) {
        if ($current_day_obj >= $leave['start'] && $current_day_obj <= $leave['end']) {
            $status = 'Izin';
            break;
        }
    }

    // 2. Cek status "Masuk" (ada jam duty) jika belum "Izin"
    if ($status === 'Absen') { 
        if (isset($duty_logs_by_date[$date_str])) {
            $status = 'Masuk';
        }
    }
    $weekly_attendance_data[$date_str] = $status;

    // Hitung absen beruntun untuk notifikasi (mingguan)
    if ($status === 'Absen') {
        $current_consecutive_absent++;
    } else {
        $current_consecutive_absent = 0; // Reset jika tidak absen
    }

    if ($current_consecutive_absent > $max_consecutive_absent) {
        $max_consecutive_absent = $current_consecutive_absent;
    }
}

// --- LOGIKA 2: DETEKSI KANDIDAT SP 1 (TIDAK ADA DUTY / IZIN DALAM 7 HARI TERAKHIR FULL) ---
$is_sp1_candidate = false;
$query_sp1 = "
    SELECT id FROM employees 
    WHERE id = $employee_id AND status = 'active' 
    AND id NOT IN (SELECT employee_id FROM duty_logs WHERE duty_start >= DATE(SUBDATE(NOW(), INTERVAL 7 DAY))) 
    AND id NOT IN (SELECT employee_id FROM leave_requests WHERE status='approved' AND end_date >= DATE(SUBDATE(NOW(), INTERVAL 7 DAY)))
";
$result_sp1 = $conn->query($query_sp1);
if ($result_sp1 && $result_sp1->num_rows > 0) {
    $is_sp1_candidate = true;
}

// --- LOGIKA 3: DETAIL JAM DUTY (TABEL) ---
$week_start = new DateTime('this week monday');
$week_end = new DateTime('this week sunday');

$detailed_duty_logs = [];
$stmt_detailed = $conn->prepare("SELECT duty_start, duty_end, status, is_manual FROM duty_logs WHERE employee_id = ? AND duty_start >= ? AND duty_start <= ? AND status IN ('completed', 'active')");
$start_str = $week_start->format('Y-m-d 00:00:00');
$end_str = $week_end->format('Y-m-d 23:59:59');
$stmt_detailed->bind_param("iss", $employee_id, $start_str, $end_str);
$stmt_detailed->execute();
$result_detailed = $stmt_detailed->get_result();

if ($result_detailed instanceof mysqli_result) {
    while ($row = $result_detailed->fetch_assoc()) {
        $date_key = date('Y-m-d', strtotime($row['duty_start']));
        $start_time = date('H:i', strtotime($row['duty_start']));
        
        if ($row['status'] === 'active') {
            // Sedang On Duty
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
        
        $detailed_duty_logs[$date_key][] = [
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

?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Absensi Saya - Warung Om Tante V2</title>
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
            padding: 2rem 2.5rem;
            margin-bottom: 2rem;
            animation: slideDown 0.6s ease-out backwards;
            position: relative;
            overflow: hidden;
        }

        .header-content-wrapper { display: flex; align-items: center; gap: 1.25rem; z-index: 2; position: relative; }
        .header-icon-wrapper {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            width: 60px; height: 60px; border-radius: 16px;
            display: flex; align-items: center; justify-content: center; font-size: 1.8rem;
            box-shadow: inset 0 0 15px rgba(0,0,0,0.3); flex-shrink: 0; color: var(--primary-color);
        }
        .header-text-wrapper h1 { color: #fff; font-weight: 800; font-size: 2.2rem; letter-spacing: -0.02em; margin: 0; }
        .header-text-wrapper p { color: var(--text-secondary); margin: 0.25rem 0 0 0; font-size: 1.05rem; }

        /* --- Cards (Glassmorphism) --- */
        .modern-card {
            background: rgba(30, 41, 59, 0.6) !important;
            backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.08) !important;
            border-radius: 24px;
            box-shadow: 0 15px 35px rgba(0,0,0,0.3) !important;
            padding: 2rem;
            animation: slideUp 0.6s ease-out backwards;
            margin-bottom: 2rem;
        }
        .modern-card-header h3 {
            color: #fff; font-size: 1.25rem; font-weight: 700; margin-bottom: 1.5rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1); padding-bottom: 0.75rem;
            display: flex; align-items: center; gap: 10px;
        }

        /* --- Attendance Grid --- */
        .my-attendance-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(110px, 1fr));
            gap: 1rem;
        }

        .day-status-item {
            background: rgba(0, 0, 0, 0.25);
            border: 1px solid rgba(255, 255, 255, 0.05);
            border-radius: 18px;
            padding: 1.25rem 0.75rem;
            text-align: center;
            transition: all 0.3s ease;
        }
        .day-status-item:hover { transform: translateY(-5px); background: rgba(255, 255, 255, 0.03); }

        .status-indicator-circle {
            width: 45px; height: 45px; border-radius: 50%;
            margin: 0 auto 0.75rem; display: flex; align-items: center; justify-content: center;
            font-size: 1.2rem; box-shadow: 0 4px 15px rgba(0,0,0,0.3);
        }

        .Masuk .status-indicator-circle { background: linear-gradient(135deg, #10b981, #059669); color: white; }
        .Izin .status-indicator-circle { background: linear-gradient(135deg, #f59e0b, #d97706); color: white; }
        .Absen .status-indicator-circle { background: linear-gradient(135deg, #ef4444, #dc2626); color: white; }

        .day-name { font-weight: 800; color: #fff; display: block; margin-bottom: 2px; }
        .day-date { font-size: 0.75rem; color: var(--text-muted); display: block; margin-bottom: 8px; }
        .status-text-label {
            font-size: 0.7rem; font-weight: 900; text-transform: uppercase; letter-spacing: 0.05em;
            padding: 4px 8px; border-radius: 6px;
        }
        .Masuk .status-text-label { background: rgba(16, 185, 129, 0.1); color: #34d399; }
        .Izin .status-text-label { background: rgba(245, 158, 11, 0.1); color: #fbbf24; }
        .Absen .status-text-label { background: rgba(239, 68, 68, 0.1); color: #f87171; }

        /* --- Detailed Table --- */
        .modern-table-wrapper {
            background: rgba(0, 0, 0, 0.25);
            border-radius: 18px;
            border: 1px solid rgba(255, 255, 255, 0.05);
            overflow-x: auto;
        }
        .attendance-table { width: 100%; border-collapse: collapse; min-width: 800px; }
        .attendance-table th {
            background: rgba(255, 255, 255, 0.03);
            padding: 1.25rem 1rem; color: #94a3b8; font-size: 0.8rem;
            text-transform: uppercase; letter-spacing: 0.05em; border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        .attendance-table td { padding: 1.5rem 1rem; border-bottom: 1px solid rgba(255,255,255,0.03); vertical-align: top; text-align: center; }
        .attendance-table tr:hover td { background: rgba(255,255,255,0.02); }

        /* Badge Time */
        .time-badge {
            display: inline-flex; align-items: center; justify-content: center;
            padding: 0.4em 0.8em; margin: 0.2em 0; border-radius: 8px; font-size: 0.8rem; white-space: nowrap; font-weight: 600; width: 100%;
        }
        .time-badge-completed { background: rgba(255,255,255,0.05); color: #cbd5e1; border: 1px solid rgba(255,255,255,0.1); }
        .time-badge-active { 
            background: rgba(16, 185, 129, 0.1); color: #34d399; border: 1px solid rgba(16, 185, 129, 0.4);
            box-shadow: 0 0 10px rgba(16, 185, 129, 0.2); animation: border-pulse 2s infinite;
        }
        .time-badge-manual { background: rgba(245, 158, 11, 0.1); color: #fbbf24; border: 1px solid rgba(245, 158, 11, 0.3); }

        .live-dot {
            width: 8px; height: 8px; background: #34d399; border-radius: 50%;
            margin-right: 8px; box-shadow: 0 0 8px #34d399; animation: dot-blink 1s infinite;
        }

        .legend-modern { display: flex; gap: 1.5rem; justify-content: flex-end; margin-bottom: 1.5rem; flex-wrap: wrap; }
        .legend-item { display: flex; align-items: center; gap: 8px; font-size: 0.85rem; font-weight: 600; color: #94a3b8; }
        .leg-box { width: 12px; height: 12px; border-radius: 3px; }

        /* --- Alert Banner & Popup --- */
        .modern-warning-banner {
            background: rgba(239, 68, 68, 0.1); border: 1px solid rgba(239, 68, 68, 0.3);
            border-radius: 16px; padding: 1.25rem; color: #fca5a5; display: flex; align-items: center;
            gap: 15px; margin-bottom: 2rem; animation: shake 0.5s ease-in-out;
        }
        .modern-warning-banner strong { color: #f87171; text-transform: uppercase; }

        .fatal-warning-banner {
            background: linear-gradient(135deg, rgba(239, 68, 68, 0.2), rgba(185, 28, 28, 0.3));
            border: 2px solid #ef4444; border-radius: 16px; padding: 1.5rem; color: #fff; 
            display: flex; align-items: center; gap: 15px; margin-bottom: 2rem; 
            box-shadow: 0 0 20px rgba(239, 68, 68, 0.3); animation: pulse-danger 2s infinite;
        }

        .warning-popup-overlay {
            position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0, 0, 0, 0.8); z-index: 9999;
            display: flex; justify-content: center; align-items: center;
            backdrop-filter: blur(10px);
        }
        .warning-popup-content {
            background: #0f172a; border: 2px solid #ef4444; border-radius: 24px;
            padding: 2.5rem; width: 90%; max-width: 500px; text-align: center;
            box-shadow: 0 0 50px rgba(239, 68, 68, 0.2);
        }
        .btn-request-leave {
            display: block; width: 100%; background: #ef4444; color: white;
            padding: 1rem; border-radius: 14px; font-weight: 800; text-decoration: none;
            margin-top: 1.5rem; transition: 0.3s; text-transform: uppercase; letter-spacing: 0.05em;
        }
        .btn-request-leave:hover { background: #dc2626; transform: translateY(-3px); box-shadow: 0 10px 20px rgba(239, 68, 68, 0.3);}

        @keyframes dot-blink { 0%, 100% { opacity: 1; } 50% { opacity: 0.4; } }
        @keyframes border-pulse { 0%, 100% { border-color: rgba(16, 185, 129, 0.4); } 50% { border-color: rgba(16, 185, 129, 1); } }
        @keyframes pulse-danger { 0% { box-shadow: 0 0 10px rgba(239, 68, 68, 0.2); } 50% { box-shadow: 0 0 25px rgba(239, 68, 68, 0.6); } 100% { box-shadow: 0 0 10px rgba(239, 68, 68, 0.2); } }
        @keyframes slideDown { from { opacity: 0; transform: translateY(-20px); } to { opacity: 1; transform: translateY(0); } }
        @keyframes slideUp { from { opacity: 0; transform: translateY(30px); } to { opacity: 1; transform: translateY(0); } }
        @keyframes shake { 0%, 100% { transform: translateX(0); } 25% { transform: translateX(-5px); } 75% { transform: translateX(5px); } }

        @media (max-width: 768px) {
            .modern-page-header { padding: 1.5rem; text-align: center; }
            .header-content-wrapper { flex-direction: column; }
            .header-text-wrapper h1 { font-size: 1.8rem; }
            .header-text-wrapper p { margin: 10px 0 0 0; }
        }
    </style>
</head>
<body>
    
    <?php if ($is_sp1_candidate): ?>
        <div id="absence-warning-popup" class="warning-popup-overlay">
            <div class="warning-popup-content" style="border-color: #b91c1c; background: linear-gradient(145deg, #0f172a, #450a0a);">
                <span style="font-size: 4rem; display: block; margin-bottom: 1rem;">🚨</span>
                <h2 style="color: #fca5a5; font-weight: 900; font-size: 1.6rem; margin-bottom: 1rem; text-transform: uppercase; letter-spacing: 0.05em;">PERINGATAN KERAS!<br>KANDIDAT SP 1</h2>
                <p style="color: #cbd5e1; line-height: 1.6;">
                    Sistem mendeteksi bahwa Anda <strong>tidak memiliki riwayat kehadiran (Duty) maupun Surat Izin</strong> selama 7 hari (1 minggu) berturut-turut.<br><br>
                    Data Anda telah diteruskan ke Direktur & Manager. Anda berpotensi besar dikenakan Surat Peringatan (SP 1) atau sanksi lainnya.
                </p>
                <a href="leave-request.php" class="btn-request-leave" style="background: linear-gradient(135deg, #ef4444, #991b1b);">
                    📝 Segera Ajukan Klarifikasi / Izin
                </a>
                <button onclick="closeAbsencePopup()" style="background:none; border:none; color: #94a3b8; margin-top: 1.5rem; cursor:pointer; font-weight: 600;">Tutup Pesan Ini</button>
            </div>
        </div>
    <?php elseif ($max_consecutive_absent >= 3): ?>
        <div id="absence-warning-popup" class="warning-popup-overlay">
            <div class="warning-popup-content">
                <span style="font-size: 4rem; display: block; margin-bottom: 1rem;">⚠️</span>
                <h2 style="color: #ef4444; font-weight: 900; font-size: 1.8rem; margin-bottom: 1rem; text-transform: uppercase;">Peringatan Absensi</h2>
                <p style="color: #94a3b8; line-height: 1.6;">
                    Anda terdeteksi sudah absen selama <strong><?= $max_consecutive_absent ?> hari berturut-turut</strong> dalam minggu ini.<br><br>
                    Segera ajukan surat izin resmi untuk menghindari sanksi atau pemotongan dari manajemen.
                </p>
                <a href="leave-request.php" class="btn-request-leave">
                    📝 Ajukan Surat Izin Sekarang
                </a>
                <button onclick="closeAbsencePopup()" style="background:none; border:none; color: #475569; margin-top: 1.5rem; cursor:pointer; font-weight: 600;">Tutup Sementara</button>
            </div>
        </div>
    <?php endif; ?>


    <div class="dashboard-container">
        <?php include 'includes/header.php'; ?>
        <?php include 'includes/sidebar.php'; ?>

        <main class="main-content">
            
            <div class="modern-page-header">
                <div class="header-content-wrapper">
                    <div class="header-icon-wrapper">📅</div>
                    <div class="header-text-wrapper">
                        <h1>Absensi Saya</h1>
                        <p>Rekap kehadiran mingguan dari <strong><?= $start_date_obj_week->format('d M') ?></strong> s/d <strong><?= $end_date_obj_week->format('d M Y') ?></strong>.</p>
                    </div>
                </div>
            </div>

            <?php if ($is_sp1_candidate): ?>
                <div class="fatal-warning-banner">
                    <span style="font-size: 2rem;">🚨</span>
                    <div>
                        <strong style="font-size: 1.1rem; display: block; margin-bottom: 5px;">PELANGGARAN KEDISIPLINAN: KANDIDAT SP 1</strong>
                        Anda tidak memiliki log kehadiran atau izin selama 1 minggu (7 hari) terakhir. Status Anda sedang dievaluasi oleh Manajemen untuk penjatuhan SP 1.
                    </div>
                </div>
            <?php elseif ($max_consecutive_absent >= 3): ?>
                <div class="modern-warning-banner">
                    <span style="font-size: 1.5rem;">⚠️</span>
                    <div>
                        <strong>Peringatan Sistem:</strong> Anda tidak hadir selama <strong><?= $max_consecutive_absent ?> hari</strong> berturut-turut. Mohon segera melengkapi administrasi izin sebelum mencapai 7 hari.
                    </div>
                </div>
            <?php endif; ?>

            <div class="modern-card">
                <div class="modern-card-header">
                    <h3><span>📊</span> Ringkasan Kehadiran (Minggu Ini)</h3>
                </div>
                <div class="my-attendance-grid">
                    <?php foreach ($dates_in_week as $date_str): ?>
                        <?php
                        $day_short_name = date('l', strtotime($date_str));
                        $day_full_name = $hari_indo[$day_short_name] ?? $day_short_name;
                        $day_num = date('d/m', strtotime($date_str));
                        $status = $weekly_attendance_data[$date_str];
                        
                        $status_icon = ($status == 'Masuk') ? '✔️' : (($status == 'Izin') ? '📝' : '❌');
                        ?>
                        <div class="day-status-item <?= $status ?>">
                            <div class="status-indicator-circle"><?= $status_icon ?></div>
                            <span class="day-name"><?= $day_full_name ?></span>
                            <span class="day-date"><?= $day_num ?></span>
                            <span class="status-text-label"><?= $status ?></span> 
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="modern-card">
                <div class="modern-card-header" style="justify-content: space-between;">
                    <h3><span>🕰️</span> Detail Jam Kerja (Minggu Ini)</h3>
                    <div class="legend-modern">
                        <div class="legend-item"><div class="leg-box" style="background: #34d399;"></div> Aktif</div>
                        <div class="legend-item"><div class="leg-box" style="background: #fbbf24;"></div> Manual</div>
                        <div class="legend-item"><div class="leg-box" style="background: rgba(255,255,255,0.1);"></div> Normal</div>
                    </div>
                </div>

                <div class="modern-table-wrapper">
                    <table class="attendance-table">
                        <thead>
                            <tr>
                                <?php foreach ($full_week_dates as $date_str): ?>
                                    <th>
                                        <?= $hari_indo[date('l', strtotime($date_str))] ?><br>
                                        <span style="font-size: 0.7rem; color: #475569; font-weight: normal;"><?= date('d/m', strtotime($date_str)) ?></span>
                                    </th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <?php foreach ($full_week_dates as $date_str): ?>
                                    <td>
                                        <div style="display: flex; flex-direction: column; gap: 8px;">
                                            <?php 
                                            if (isset($detailed_duty_logs[$date_str])) {
                                                foreach ($detailed_duty_logs[$date_str] as $log) {
                                                    echo "<div class='time-badge {$log['class']}'>{$log['text']}</div>";
                                                }
                                            } else {
                                                echo "<span style='color: #334155; font-size: 1.2rem; margin-top: 5px;'>-</span>";
                                            }
                                            ?>
                                        </div>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

        </main>
    </div>

    <script src="script.js"></script>
    <script>
        function closeAbsencePopup() {
            const popup = document.getElementById('absence-warning-popup');
            if (popup) {
                popup.style.display = 'none';
            }
        }
    </script>
</body>
</html>