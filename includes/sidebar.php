<?php
// File: includes/sidebar.php

// Kode ini akan dieksekusi setiap kali sidebar dimuat
// Pastikan variabel $conn dan $user sudah tersedia dari file PHP utama yang memanggil sidebar.php

$max_consecutive_absent_sidebar = 0; // Inisialisasi penghitung absen beruntun untuk sidebar

// Lakukan perhitungan absensi hanya jika pengguna sudah login dan variabel $user serta $conn tersedia
if (isset($_SESSION['user_id']) && isset($conn) && isset($user)) {
    $employee_id_sidebar = $user['id'];

    // Tentukan periode rekap (minggu berjalan: dari Senin minggu ini hingga hari ini)
    $start_date_obj_sidebar = new DateTime('this week monday');
    $end_date_obj_sidebar = new DateTime('today');

    // Buat periode iterasi harian, termasuk tanggal akhir
    $end_date_for_period_sidebar = clone $end_date_obj_sidebar;
    $end_date_for_period_sidebar->modify('+1 day'); 
    $interval_sidebar = DateInterval::createFromDateString('1 day');
    $period_sidebar = new DatePeriod($start_date_obj_sidebar, $interval_sidebar, $end_date_for_period_sidebar);

    $dates_in_week_sidebar = [];
    foreach ($period_sidebar as $dt) {
        $dates_in_week_sidebar[] = $dt->format('Y-m-d');
    }

    // Mengambil log duty yang completed untuk periode ini (hanya untuk user yang login)
    $duty_logs_by_date_sidebar = [];
    $stmt_duty_sidebar = $conn->prepare("SELECT DATE(duty_start) as duty_date FROM duty_logs WHERE employee_id = ? AND duty_start >= ? AND duty_start <= ? AND status = 'completed'");
    if ($stmt_duty_sidebar) {
        $stmt_duty_sidebar->bind_param("iss", $employee_id_sidebar, $start_date_obj_sidebar->format('Y-m-d 00:00:00'), $end_date_obj_sidebar->format('Y-m-d 23:59:59'));
        $stmt_duty_sidebar->execute();
        $result_duty_sidebar = $stmt_duty_sidebar->get_result();
        if ($result_duty_sidebar instanceof mysqli_result) {
            while ($row = $result_duty_sidebar->fetch_assoc()) {
                $duty_logs_by_date_sidebar[$row['duty_date']] = true;
            }
            $result_duty_sidebar->free();
        }
        $stmt_duty_sidebar->close();
    }

    // Mengambil permohonan cuti yang disetujui untuk periode ini (hanya untuk user yang login)
    $leave_requests_sidebar = [];
    $stmt_leave_sidebar = $conn->prepare("SELECT start_date, end_date FROM leave_requests WHERE employee_id = ? AND (start_date <= ? AND end_date >= ?) AND status = 'approved'");
    if ($stmt_leave_sidebar) {
        $stmt_leave_sidebar->bind_param("iss", $employee_id_sidebar, $end_date_obj_sidebar->format('Y-m-d'), $start_date_obj_sidebar->format('Y-m-d'));
        $stmt_leave_sidebar->execute();
        $result_leave_sidebar = $stmt_leave_sidebar->get_result();
        if ($result_leave_sidebar instanceof mysqli_result) {
            while ($row = $result_leave_sidebar->fetch_assoc()) {
                $leave_requests_sidebar[] = [
                    'start' => new DateTime($row['start_date']),
                    'end' => new DateTime($row['end_date'])
                ];
            }
            $result_leave_sidebar->free();
        }
        $stmt_leave_sidebar->close();
    }

    $current_consecutive_absent_sidebar = 0;
    foreach ($dates_in_week_sidebar as $date_str) {
        $status_sidebar = 'Absen'; // Default status
        $current_day_obj_sidebar = new DateTime($date_str);

        // 1. Cek status "Izin" (cuti yang disetujui)
        foreach ($leave_requests_sidebar as $leave) {
            if ($current_day_obj_sidebar >= $leave['start'] && $current_day_obj_sidebar <= $leave['end']) {
                $status_sidebar = 'Izin';
                break;
            }
        }

        // 2. Cek status "Masuk" (ada jam duty) jika belum "Izin"
        if ($status_sidebar === 'Absen') { 
            if (isset($duty_logs_by_date_sidebar[$date_str])) {
                $status_sidebar = 'Masuk';
            }
        }

        // Hitung absen beruntun
        if ($status_sidebar === 'Absen') {
            $current_consecutive_absent_sidebar++;
        } else {
            $current_consecutive_absent_sidebar = 0;
        }

        if ($current_consecutive_absent_sidebar > $max_consecutive_absent_sidebar) {
            $max_consecutive_absent_sidebar = $current_consecutive_absent_sidebar;
        }
    }
    
    // BARU: Hitung jumlah surat peringatan untuk pengguna saat ini
    $my_warnings_count = 0;
    $stmt_my_warnings = $conn->prepare("SELECT COUNT(*) as total FROM warning_letters WHERE employee_id = ?");
    if ($stmt_my_warnings) {
        $stmt_my_warnings->bind_param("i", $employee_id_sidebar);
        $stmt_my_warnings->execute();
        $result_my_warnings = $stmt_my_warnings->get_result();
        $row_my_warnings = $result_my_warnings->fetch_assoc();
        $my_warnings_count = $row_my_warnings['total'];
        $stmt_my_warnings->close();
    }
}

// BARU: Ambil hitungan pending yang terpisah
$all_pending_counts = getPendingRequestCounts();
$pending_requests_count = $all_pending_counts['employee_requests']; // Permohonan (Cuti, Resign, dll)
$pending_bookings_count = $all_pending_counts['booking_requests']; // Kelola Pemesanan
$total_pending_all = $all_pending_counts['total']; // Total semua pending

// Hitung total surat peringatan untuk notifikasi "Semua Surat Peringatan"
$all_warnings_count = 0;
if (isset($conn) && isLoggedIn()) { 
    $stmt_warnings_count = $conn->query("SELECT COUNT(*) as total FROM warning_letters");
    if ($stmt_warnings_count) {
        $result_warnings = $stmt_warnings_count->fetch_assoc();
        $all_warnings_count = $result_warnings['total'];
        $stmt_warnings_count->close();
    }
}
?>

<style>
    /* === MODERN SIDEBAR STYLING (FLEXBOX FIX) === */
    .sidebar.modern-sidebar {
        background: rgba(15, 23, 42, 0.95) !important; /* Dark slate blur */
        backdrop-filter: blur(16px) !important;
        -webkit-backdrop-filter: blur(16px) !important;
        border-right: 1px solid rgba(255, 255, 255, 0.08) !important;
        box-shadow: 4px 0 24px rgba(0, 0, 0, 0.3);
        transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        z-index: 990;
    }

    .modern-sidebar .sidebar-content {
        /* SOLUSI: Menambah padding atas menjadi 2.8rem agar menu tidak menabrak header */
        padding: 2.8rem 1rem 2rem 1rem;
        overflow-y: auto;
        /* Sembunyikan scrollbar tapi tetap bisa scroll */
        scrollbar-width: none; 
        -ms-overflow-style: none;
    }
    .modern-sidebar .sidebar-content::-webkit-scrollbar {
        display: none; 
    }

    /* Container untuk setiap baris navigasi */
    .modern-sidebar .nav-item {
        display: flex;
        align-items: center;
        padding: 0.8rem 1rem;
        margin-bottom: 0.4rem;
        border-radius: 12px;
        color: var(--text-secondary);
        text-decoration: none;
        transition: all 0.3s ease;
        border: 1px solid transparent;
        background: transparent;
    }

    /* Hover Effect */
    .modern-sidebar .nav-item:hover {
        background: rgba(255, 255, 255, 0.05);
        color: #fff;
        border-color: rgba(255, 255, 255, 0.08);
        transform: translateX(4px);
    }

    /* Active Effect */
    .modern-sidebar .nav-item.active {
        background: linear-gradient(90deg, rgba(255, 193, 7, 0.15), rgba(255, 193, 7, 0.05));
        color: var(--primary-color);
        border-color: rgba(255, 193, 7, 0.2);
        box-shadow: inset 3px 0 0 var(--primary-color);
        font-weight: 600;
    }

    /* Icon Setup */
    .modern-sidebar .nav-icon {
        margin-right: 12px;
        font-size: 1.15rem;
        transition: transform 0.3s ease, filter 0.3s ease;
        display: flex;
        align-items: center;
        justify-content: center;
        width: 24px;
        flex-shrink: 0; /* Agar icon tidak mengecil saat teks panjang */
    }

    .modern-sidebar .nav-item.active .nav-icon {
        transform: scale(1.1);
        filter: drop-shadow(0 0 5px rgba(255, 193, 7, 0.5));
    }
    .modern-sidebar .nav-item:hover .nav-icon {
        transform: scale(1.1) rotate(5deg);
    }

    /* Text Setup dengan Flex Grow */
    .modern-sidebar .nav-text {
        flex-grow: 1; /* Teks akan mengambil sisa ruang yang ada, mendorong notifikasi ke kanan */
        font-size: 0.95rem;
        letter-spacing: 0.01em;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis; /* Jika teks sangat panjang, akan dipotong dengan "..." */
        transition: opacity 0.3s ease;
    }

    .modern-sidebar .nav-divider {
        height: 1px;
        background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.1), transparent);
        margin: 1.25rem 0;
        border: none;
    }

    /* INDIKATOR / NOTIFIKASI SETUP (Bukan Absolute Lagi) */
    .modern-sidebar .pending-indicator,
    .modern-sidebar .warning-indicator,
    .modern-sidebar .absent-warning-indicator {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 22px;
        height: 22px;
        padding: 0 6px;
        border-radius: 20px; /* Berbentuk pil jika angkanya dua digit */
        font-size: 0.75rem;
        font-weight: 800;
        box-shadow: 0 2px 8px rgba(0,0,0,0.3);
        flex-shrink: 0; /* Mencegah badge tergencet */
        margin-left: 8px; /* Jarak aman dari teks */
    }

    .modern-sidebar .pending-indicator {
        background: var(--danger-color);
        color: white;
        animation: pulse-danger 2s infinite;
    }

    .modern-sidebar .warning-indicator, 
    .modern-sidebar .absent-warning-indicator {
        background: var(--primary-color);
        color: #000;
        animation: pulse-warning 2s infinite;
    }

    @keyframes pulse-danger {
        0% { box-shadow: 0 0 0 0 rgba(248, 113, 113, 0.6); transform: scale(1); }
        70% { box-shadow: 0 0 0 6px rgba(248, 113, 113, 0); transform: scale(1.05); }
        100% { box-shadow: 0 0 0 0 rgba(248, 113, 113, 0); transform: scale(1); }
    }

    @keyframes pulse-warning {
        0% { box-shadow: 0 0 0 0 rgba(255, 193, 7, 0.6); transform: scale(1); }
        70% { box-shadow: 0 0 0 6px rgba(255, 193, 7, 0); transform: scale(1.05); }
        100% { box-shadow: 0 0 0 0 rgba(255, 193, 7, 0); transform: scale(1); }
    }

    /* === COLLAPSE LOGIC FOR DESKTOP === */
    .modern-sidebar.collapsed {
        width: 80px; /* Lebar saat ditutup */
    }
    
    /* Sembunyikan elemen dengan aman tanpa merusak flex layout */
    .modern-sidebar.collapsed .nav-text,
    .modern-sidebar.collapsed .pending-indicator,
    .modern-sidebar.collapsed .warning-indicator,
    .modern-sidebar.collapsed .absent-warning-indicator {
        opacity: 0;
        width: 0;
        height: 0;
        margin: 0;
        padding: 0;
        overflow: hidden;
        visibility: hidden;
    }
    
    .modern-sidebar.collapsed .nav-item {
        justify-content: center;
        padding: 0.8rem 0;
    }
    
    .modern-sidebar.collapsed .nav-icon {
        margin-right: 0;
    }

    /* Penyesuaian Main Content agar bereaksi dengan Sidebar (Desktop) */
    @media (min-width: 1025px) {
        .main-content {
            transition: margin-left 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }
        /* Asumsi default lebar sidebar adalah 260px (di style.css). Jika collapsed, sisa 80px */
        .main-content.expanded {
            margin-left: 80px !important; 
        }
    }
</style>

<nav class="sidebar modern-sidebar" id="sidebar">
    <div class="sidebar-content">
        <a href="dashboard" class="nav-item <?= basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'active' : '' ?>" title="Dashboard">
            <span class="nav-icon">🏠</span>
            <span class="nav-text">Dashboard</span>
        </a>
        <a href="sales" class="nav-item <?= basename($_SERVER['PHP_SELF']) == 'sales.php' ? 'active' : '' ?>" title="Data Penjualan">
            <span class="nav-icon">💰</span>
            <span class="nav-text">Data Penjualan</span>
        </a>
        <a href="data-masak" class="nav-item <?= basename($_SERVER['PHP_SELF']) == 'data-masak.php' ? 'active' : '' ?>" title="Data Masak">
            <span class="nav-icon">🍳</span>
            <span class="nav-text">Data Masak</span>
        </a>
        <a href="refrigerator-stock" class="nav-item <?= basename($_SERVER['PHP_SELF']) == 'refrigerator-stock.php' ? 'active' : '' ?>" title="Stok Kulkas">
            <span class="nav-icon">🍛</span>
            <span class="nav-text">Stok Kulkas</span>
        </a>    
        <a href="warehouse-stock" class="nav-item <?= basename($_SERVER['PHP_SELF']) == 'warehouse-stock.php' ? 'active' : '' ?>" title="Stok Gudang">
            <span class="nav-icon">📦</span>
            <span class="nav-text">Stok Gudang</span>
        </a>     
        <a href="manual-duty" class="nav-item <?= basename($_SERVER['PHP_SELF']) == 'manual-duty.php' ? 'active' : '' ?>" title="Input Manual">
            <span class="nav-icon">⏱️</span>
            <span class="nav-text">Input Manual</span>
        </a>
        <a href="leave-request" class="nav-item <?= basename($_SERVER['PHP_SELF']) == 'leave-request.php' ? 'active' : '' ?>" title="Surat Izin">
            <span class="nav-icon">📝</span>
            <span class="nav-text">Surat Izin</span>
        </a>
        <a href="activities" class="nav-item <?= basename($_SERVER['PHP_SELF']) == 'activities.php' ? 'active' : '' ?>" title="Aktivitas Saya">
            <span class="nav-icon">📊</span>
            <span class="nav-text">Aktivitas Saya</span>
        </a>
        <a href="my-payslip" class="nav-item <?= basename($_SERVER['PHP_SELF']) == 'my-payslip.php' ? 'active' : '' ?>" title="Slip Gaji Saya">
            <span class="nav-icon">📄</span>
            <span class="nav-text">Slip Gaji Saya</span>
        </a>
        <a href="my-warnings" class="nav-item <?= basename($_SERVER['PHP_SELF']) == 'my-warnings.php' ? 'active' : '' ?>" title="Surat Peringatan">
            <span class="nav-icon">⚠️</span>
            <span class="nav-text">Surat Peringatan</span>
            <?php if (isset($my_warnings_count) && $my_warnings_count > 0): ?>
                <span class="warning-indicator">!</span>
            <?php endif; ?>
        </a>
        <a href="my-attendance" class="nav-item <?= basename($_SERVER['PHP_SELF']) == 'my-attendance.php' ? 'active' : '' ?>" title="Absensi Saya">
            <span class="nav-icon">📅</span>
            <span class="nav-text">Absensi Saya</span>
            <?php if (isset($max_consecutive_absent_sidebar) && $max_consecutive_absent_sidebar >= 2): ?>
                <span class="absent-warning-indicator">!</span>
            <?php endif; ?>
        </a>
        
        <div class="nav-divider"></div>

        <a href="all-requests" class="nav-item <?= basename($_SERVER['PHP_SELF']) == 'all-requests.php' ? 'active' : '' ?>" title="Semua Permohonan">
            <span class="nav-icon">📋</span>
            <span class="nav-text">Semua Permohonan</span>
        </a>
        <a href="all-warnings" class="nav-item <?= basename($_SERVER['PHP_SELF']) == 'all-warnings.php' ? 'active' : '' ?>" title="Semua Surat Peringatan">
            <span class="nav-icon">📜</span>
            <span class="nav-text">Semua Surat Peringatan</span>
            <?php if (isset($all_warnings_count) && $all_warnings_count > 0): ?>
                <span class="pending-indicator"><?= $all_warnings_count ?></span>
            <?php endif; ?>
        </a>
        <a href="suggestions" class="nav-item <?= basename($_SERVER['PHP_SELF']) == 'suggestions.php' ? 'active' : '' ?>" title="Saran & Kritik">
            <span class="nav-icon">🚨</span>
            <span class="nav-text">Saran & Kritik (Anonim)</span>
        </a>
        <a href="change-password" class="nav-item <?= basename($_SERVER['PHP_SELF']) == 'change-password.php' ? 'active' : '' ?>" title="Ubah Kata Sandi">
            <span class="nav-icon">🔑</span>
            <span class="nav-text">Ubah Kata Sandi</span>
        </a>
        
        <?php if (hasRole(['ceo', 'direktur', 'wakil_direktur', 'manager'])): ?>
        <div class="nav-divider"></div>
        <a href="employee-report" class="nav-item <?= basename($_SERVER['PHP_SELF']) == 'employee-report.php' ? 'active' : '' ?>" title="Laporan Karyawan">
            <span class="nav-icon">🌎</span>
            <span class="nav-text">Laporan Karyawan</span>
        </a>
        <a href="employees" class="nav-item <?= basename($_SERVER['PHP_SELF']) == 'employees.php' ? 'active' : '' ?>" title="Daftar Anggota">
            <span class="nav-icon">👥</span>
            <span class="nav-text">Daftar Anggota</span>
        </a>
        <a href="employee-activities" class="nav-item <?= basename($_SERVER['PHP_SELF']) == 'employee-activities.php' ? 'active' : '' ?>" title="Aktivitas Anggota">
            <span class="nav-icon">📈</span>
            <span class="nav-text">Aktivitas Anggota</span>
        </a>
        <a href="income-report" class="nav-item <?= basename($_SERVER['PHP_SELF']) == 'income-report.php' ? 'active' : '' ?>" title="Laporan Pemasukan">
            <span class="nav-icon">💳</span>
            <span class="nav-text">Laporan Pemasukan</span>
        </a>
        <a href="salary-recap" class="nav-item <?= basename($_SERVER['PHP_SELF']) == 'salary-recap.php' ? 'active' : '' ?>" title="Rekap Gaji">
            <span class="nav-icon">💸</span>
            <span class="nav-text">Rekap Gaji</span>
        </a>
        <a href="attendance-recap" class="nav-item <?= basename($_SERVER['PHP_SELF']) == 'attendance-recap.php' ? 'active' : '' ?>" title="Rekap Absensi">
            <span class="nav-icon">📅</span>
            <span class="nav-text">Rekap Absensi</span>
        </a>
        <a href="warning-management" class="nav-item <?= basename($_SERVER['PHP_SELF']) == 'warning-management.php' ? 'active' : '' ?>" title="Manajemen SP">
            <span class="nav-icon">⚠️</span>
            <span class="nav-text">Manajemen SP</span>
        </a>

        <a href="manage-bookings" class="nav-item <?= basename($_SERVER['PHP_SELF']) == 'manage-bookings.php' ? 'active' : '' ?>" title="Kelola Pemesanan">
            <span class="nav-icon">🛎️</span>
            <span class="nav-text">Kelola Pemesanan</span>
            <?php if (isset($pending_bookings_count) && $pending_bookings_count > 0): ?>
                <span class="pending-indicator"><?= $pending_bookings_count ?></span>
            <?php endif; ?>
        </a>
        <?php endif; ?>
        
        <?php if (hasRole(['ceo', 'direktur', 'wakil_direktur'])): ?>
        <div class="nav-divider"></div>

        <a href="requests" class="nav-item <?= basename($_SERVER['PHP_SELF']) == 'requests.php' ? 'active' : '' ?>" title="Permohonan">
            <span class="nav-icon">📋</span>
            <span class="nav-text">Permohonan</span>
            <?php if (isset($pending_requests_count) && $pending_requests_count > 0): ?>
                <span class="pending-indicator"><?= $pending_requests_count ?></span>
            <?php endif; ?>
        </a>
        <a href="admin" class="nav-item <?= basename($_SERVER['PHP_SELF']) == 'admin.php' ? 'active' : '' ?>" title="Admin Panel">
            <span class="nav-icon">⚙️</span>
            <span class="nav-text">Admin Panel</span>
        </a>
        <a href="company-bank" class="nav-item <?= basename($_SERVER['PHP_SELF']) == 'company-bank.php' ? 'active' : '' ?>" title="Brangkas Perusahaan">
            <span class="nav-icon">💰</span>
            <span class="nav-text">Brangkas Perusahaan</span>
        </a>
        <a href="duty-history-management" class="nav-item <?= basename($_SERVER['PHP_SELF']) == 'duty-history-management.php' ? 'active' : '' ?>" title="Manajemen Jam Duty">
            <span class="nav-icon">⏱️</span>
            <span class="nav-text">Manajemen Jam Duty</span>
        </a>
        <a href="weekly-salary-recap" class="nav-item <?= basename($_SERVER['PHP_SELF']) == 'weekly-salary-recap.php' ? 'active' : '' ?>" title="Backup Gaji Mingguan">
            <span class="nav-icon">📘</span>
            <span class="nav-text">Backup Gaji Mingguan</span>
        </a>
        <?php endif; ?>
    </div>
</nav>