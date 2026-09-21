<?php
require_once 'config.php';

if (!isLoggedIn() || !hasRole(['ceo', 'direktur', 'wakil_direktur'])) {
    header('Location: dashboard.php');
    exit;
}

$user = getCurrentUser();
$pending_requests_count = getPendingRequestCount();

$success = null;
$error = null;
$selected_employee_id = null;
$employee_duty_logs = [];
$selected_employee_name = 'Pilih Anggota';

// Pastikan fungsi formatDuration ada
if (!function_exists('formatDuration')) {
    function formatDuration($minutes) {
        if ($minutes < 0) return "0j 0m";
        $hours = floor($minutes / 60);
        $remainingMinutes = $minutes % 60;
        return "{$hours}j {$remainingMinutes}m";
    }
}

// Handle delete duty log (multiple or single)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_duty_logs') {
    $duty_log_ids = $_POST['duty_log_ids'] ?? [];
    $employee_id_of_log = (int)($_POST['employee_id_of_log'] ?? 0);

    if ($employee_id_of_log <= 0) {
        $error = "ID anggota tidak valid!";
    } elseif (empty($duty_log_ids)) {
        $error = "Tidak ada log jam kerja yang dipilih untuk dihapus.";
    } else {
        $conn->begin_transaction();
        try {
            $deleted_count = 0;
            $deleted_logs = [];

            $placeholders = implode(',', array_fill(0, count($duty_log_ids), '?'));
            $types = str_repeat('i', count($duty_log_ids)) . 'i'; 
            $params = $duty_log_ids;
            $params[] = $employee_id_of_log;

            // Ambil detail log sebelum dihapus untuk notifikasi
            $stmt_get_logs = $conn->prepare("
                SELECT dl.*, e.name as employee_name
                FROM duty_logs dl
                JOIN employees e ON dl.employee_id = e.id
                WHERE dl.id IN ($placeholders) AND dl.employee_id = ?
            ");
            if (!$stmt_get_logs) {
                throw new Exception("Gagal menyiapkan query ambil detail log: " . $conn->error);
            }
            $stmt_get_logs->bind_param($types, ...$params); 
            $stmt_get_logs->execute();
            $result = $stmt_get_logs->get_result();
            while ($row = $result->fetch_assoc()) {
                $deleted_logs[] = $row;
            }
            $stmt_get_logs->close();

            // Hapus log duty
            $delete_stmt_sql = "DELETE FROM duty_logs WHERE id IN ($placeholders) AND employee_id = ?";
            $stmt_delete = $conn->prepare($delete_stmt_sql);
            if (!$stmt_delete) {
                throw new Exception("Gagal menyiapkan query hapus log duty: " . $conn->error);
            }
            $stmt_delete->bind_param($types, ...$params);
            
            if ($stmt_delete->execute()) {
                $deleted_count = $stmt_delete->affected_rows;
                $conn->commit();
                $success = "Berhasil menghapus {$deleted_count} log jam kerja untuk **" . htmlspecialchars($deleted_logs[0]['employee_name']) . "**. Saran: Informasikan anggota untuk menginput ulang jam kerja ini dengan `Input Manual` jika diperlukan.";

                foreach ($deleted_logs as $log_details) {
                    sendDiscordNotification([
                        'employee_name' => $log_details['employee_name'],
                        'admin_name' => $user['name'],
                        'duty_start' => $log_details['duty_start'],
                        'duty_end' => $log_details['duty_end'],
                        'duration_minutes' => $log_details['duration_minutes']
                    ], 'duty_log_deleted');
                }

            } else {
                throw new Exception("Gagal menghapus log duty. Mungkin sudah dihapus atau tidak ada perubahan.");
            }
            $stmt_delete->close();

        } catch (Exception $e) {
            $conn->rollback();
            $error = "Terjadi kesalahan: " . $e->getMessage();
        }
    }
}

// Get all active employees for the dropdown
$all_employees = $conn->query("SELECT id, name, role FROM employees WHERE status = 'active' ORDER BY name")->fetch_all(MYSQLI_ASSOC);

// BARU: Query untuk mendapatkan ID karyawan yang memiliki log durasi > 7 jam
$long_duty_employees_ids = [];
$stmt_long_duty = $conn->query("SELECT DISTINCT employee_id FROM duty_logs WHERE duration_minutes > 420");
if ($stmt_long_duty) {
    while ($row = $stmt_long_duty->fetch_assoc()) {
        $long_duty_employees_ids[] = $row['employee_id'];
    }
    $stmt_long_duty->close();
}

// BARU: Ambil Data Detail Sesi Over-Duty untuk ditampilkan di Card Khusus
$overtime_logs = [];
$stmt_overtime = $conn->query("
    SELECT dl.id, dl.employee_id, e.name as employee_name, dl.duty_start, dl.duty_end, dl.duration_minutes
    FROM duty_logs dl
    JOIN employees e ON dl.employee_id = e.id
    WHERE dl.duration_minutes > 420
    ORDER BY dl.duty_start DESC
");
if ($stmt_overtime) {
    $overtime_logs = $stmt_overtime->fetch_all(MYSQLI_ASSOC);
    $stmt_overtime->close();
}


// Handle employee selection
if (isset($_GET['employee_id']) && !empty($_GET['employee_id'])) {
    $selected_employee_id = (int)$_GET['employee_id'];

    // Get name of selected employee
    foreach ($all_employees as $emp) {
        if ($emp['id'] === $selected_employee_id) {
            $selected_employee_name = htmlspecialchars($emp['name']);
            break;
        }
    }

    // Fetch duty logs for the selected employee
    $stmt_logs = $conn->prepare("
        SELECT dl.*, a.name as approved_by_name
        FROM duty_logs dl
        LEFT JOIN employees a ON dl.approved_by = a.id
        WHERE dl.employee_id = ?
        ORDER BY dl.duty_start DESC
    ");
    if ($stmt_logs) {
        $stmt_logs->bind_param("i", $selected_employee_id);
        $stmt_logs->execute();
        $employee_duty_logs = $stmt_logs->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt_logs->close();
    } else {
        $error = "Gagal mengambil log duty: " . $conn->error;
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manajemen Riwayat Jam Kerja - Warung Om Tante V2</title>
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

        /* --- Alerts --- */
        .success-alert { background: rgba(52, 211, 153, 0.15); border: 1px solid rgba(52, 211, 153, 0.4); color: #6ee7b7; padding: 1rem 1.2rem; border-radius: 14px; margin-bottom: 1.5rem; display: flex; align-items: center; gap: 0.75rem; font-size: 0.95rem; font-weight: 500; animation: slideDown 0.4s ease-out; }
        .error-alert { background: rgba(220, 53, 69, 0.15); border: 1px solid rgba(220, 53, 69, 0.4); color: #fca5a5; padding: 1rem 1.2rem; border-radius: 14px; margin-bottom: 1.5rem; display: flex; align-items: center; gap: 0.75rem; font-size: 0.95rem; font-weight: 500; animation: shake 0.5s ease-in-out; }

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
            box-shadow: inset 0 0 20px rgba(0,0,0,0.2); margin-bottom: 1rem;
        }
        .modern-form-group label {
            display: block; font-size: 0.9rem; font-weight: 600; color: var(--text-secondary); margin-bottom: 0.6rem;
        }
        .modern-input-wrapper { position: relative; display: flex; align-items: center; }
        .modern-input-icon { position: absolute; left: 1.25rem; font-size: 1.2rem; opacity: 0.6; pointer-events: none; z-index: 2; }
        .modern-select {
            width: 100%; background: rgba(0, 0, 0, 0.4); border: 1px solid rgba(255, 255, 255, 0.1);
            color: white; padding: 1.1rem 1rem 1.1rem 3.5rem; border-radius: 14px; font-size: 0.95rem; transition: 0.3s;
            appearance: none; cursor: pointer;
        }
        .modern-select option { background: #0f172a; color: white; }
        .modern-select:focus { outline: none; border-color: var(--primary-color); background: rgba(0,0,0,0.6); box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15);}

        /* --- Info Box Modern --- */
        .modern-info-box {
            background: rgba(245, 158, 11, 0.1); border: 1px solid rgba(245, 158, 11, 0.3);
            border-radius: 14px; padding: 1rem 1.25rem; color: #fde68a; font-size: 0.9rem; line-height: 1.6;
            display: flex; align-items: flex-start; gap: 15px; margin-bottom: 1.5rem;
        }

        /* --- Overtime Anomaly Cards --- */
        .overtime-scroll-wrapper {
            max-height: 350px; overflow-y: auto; padding-right: 10px; margin-bottom: 1rem;
        }
        .overtime-scroll-wrapper::-webkit-scrollbar { width: 6px; }
        .overtime-scroll-wrapper::-webkit-scrollbar-thumb { background: rgba(239, 68, 68, 0.3); border-radius: 10px; }
        
        .overtime-grid-modern {
            display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 1rem;
        }
        .overtime-card-modern {
            background: rgba(0, 0, 0, 0.25); border: 1px solid rgba(239, 68, 68, 0.2); border-left: 4px solid #ef4444;
            border-radius: 12px; padding: 1.25rem; transition: all 0.3s ease; text-decoration: none; display: flex; flex-direction: column; gap: 10px;
        }
        .overtime-card-modern:hover {
            background: rgba(239, 68, 68, 0.05); transform: translateY(-3px); box-shadow: 0 5px 15px rgba(239, 68, 68, 0.2); border-color: rgba(239, 68, 68, 0.4);
        }
        .otc-header { display: flex; justify-content: space-between; align-items: center; }
        .otc-name { color: #fff; font-weight: 800; font-size: 1.05rem; }
        .otc-duration {
            background: rgba(239, 68, 68, 0.15); color: #fca5a5; padding: 4px 10px; border-radius: 20px; font-size: 0.75rem; font-weight: 800;
            border: 1px solid rgba(239, 68, 68, 0.3); box-shadow: 0 0 10px rgba(239, 68, 68, 0.2);
        }
        .otc-details { display: flex; flex-direction: column; gap: 4px; font-size: 0.85rem; color: #94a3b8; }
        .otc-details span { display: flex; align-items: center; gap: 6px; }

        /* --- Modern Table --- */
        .modern-table-wrapper {
            background: rgba(0, 0, 0, 0.25); border-radius: 18px; border: 1px solid rgba(255, 255, 255, 0.05); overflow-x: auto;
        }
        .report-table { width: 100%; border-collapse: collapse; font-size: 0.9rem; min-width: 800px; color: #e2e8f0; }
        .report-table th, .report-table td {
            padding: 1rem; border-bottom: 1px solid rgba(255, 255, 255, 0.03); text-align: left; vertical-align: middle;
        }
        .report-table th { background: rgba(255, 255, 255, 0.03); font-weight: 600; text-transform: uppercase; font-size: 0.8rem; letter-spacing: 0.05em; color: #94a3b8; }
        .report-table tr:hover td { background: rgba(255, 255, 255, 0.03); }
        .report-table tr:last-child td { border-bottom: none; }

        /* Highlight Row (>7 jam) */
        .long-duty-row-modern td { background: rgba(239, 68, 68, 0.08) !important; }
        .long-duty-row-modern td:first-child { position: relative; }
        .long-duty-row-modern td:first-child::before {
            content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 4px; background: #ef4444;
        }
        .alert-badge { background: rgba(239, 68, 68, 0.2); color: #fca5a5; border: 1px solid rgba(239, 68, 68, 0.4); padding: 2px 6px; border-radius: 6px; font-size: 0.7rem; font-weight: 800; margin-left: 8px; vertical-align: middle; }

        /* Checkbox */
        .modern-checkbox {
            appearance: none; width: 18px; height: 18px; border: 2px solid rgba(255,255,255,0.2); border-radius: 4px; background: rgba(0,0,0,0.3); cursor: pointer; position: relative; transition: 0.2s;
        }
        .modern-checkbox:checked { background: #ef4444; border-color: #ef4444; }
        .modern-checkbox:checked::after { content: '✔'; position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); color: white; font-size: 10px; font-weight: bold; }

        /* Badges */
        .badge-pill { display: inline-flex; align-items: center; padding: 4px 10px; border-radius: 20px; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; }
        .b-primary { background: rgba(59, 130, 246, 0.15); color: #60a5fa; border: 1px solid rgba(59, 130, 246, 0.3); }
        .b-warning { background: rgba(245, 158, 11, 0.15); color: #fbbf24; border: 1px solid rgba(245, 158, 11, 0.3); }
        .b-info { background: rgba(168, 85, 247, 0.15); color: #c084fc; border: 1px solid rgba(168, 85, 247, 0.3); }
        .b-success { background: rgba(16, 185, 129, 0.15); color: #34d399; border: 1px solid rgba(16, 185, 129, 0.3); }
        .b-danger { background: rgba(239, 68, 68, 0.15); color: #fca5a5; border: 1px solid rgba(239, 68, 68, 0.3); }

        /* Delete Button */
        .btn-delete-mass {
            background: linear-gradient(135deg, #ef4444, #b91c1c); color: #fff; border: none;
            padding: 1rem 1.5rem; border-radius: 12px; font-size: 0.95rem; font-weight: 800; text-transform: uppercase;
            letter-spacing: 0.05em; cursor: pointer; transition: all 0.3s ease; display: inline-flex; align-items: center; gap: 8px;
            box-shadow: 0 8px 20px rgba(239, 68, 68, 0.25); float: right; margin-top: 1.5rem;
        }
        .btn-delete-mass:hover:not(:disabled) { transform: translateY(-3px); box-shadow: 0 12px 25px rgba(239, 68, 68, 0.4); }
        .btn-delete-mass:disabled { background: rgba(255,255,255,0.05); color: #64748b; box-shadow: none; border: 1px solid rgba(255,255,255,0.1); cursor: not-allowed; }

        @keyframes slideDown { from { opacity: 0; transform: translateY(-20px); } to { opacity: 1; transform: translateY(0); } }
        @keyframes slideUp { from { opacity: 0; transform: translateY(30px); } to { opacity: 1; transform: translateY(0); } }
        @keyframes shake { 0%, 100% { transform: translateX(0); } 25% { transform: translateX(-5px); } 75% { transform: translateX(5px); } }

        @media (max-width: 768px) {
            .modern-page-header { padding: 1.5rem; text-align: center; }
            .header-content-wrapper { flex-direction: column; }
            .btn-delete-mass { width: 100%; justify-content: center; }
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
                    <div class="header-icon-wrapper">⏱️</div>
                    <div class="header-text-wrapper">
                        <h1>Manajemen Riwayat Jam Kerja</h1>
                        <p>Lihat, evaluasi, dan kelola histori duty log masing-masing anggota.</p>
                    </div>
                </div>
            </div>

            <?php if (isset($success)): ?>
                <div class="success-alert"><span>🎉</span> <?= $success ?></div>
            <?php endif; ?>
            <?php if (isset($error)): ?>
                <div class="error-alert"><span>❌</span> <?= $error ?></div>
            <?php endif; ?>

            <div class="modern-card">
                <div class="modern-card-header">
                    <h3><span>👤</span> Filter Anggota</h3>
                </div>
                
                <div class="form-inner-wrapper">
                    <form method="GET" style="margin: 0;">
                        <div class="modern-form-group" style="margin: 0;">
                            <label for="employee_select">Pilih Anggota Manajemen</label>
                            <div class="modern-input-wrapper">
                                <span class="modern-input-icon">🔍</span>
                                <select name="employee_id" id="employee_select" class="modern-select" onchange="this.form.submit()">
                                    <option value="">-- Ketik atau Pilih Anggota --</option>
                                    <?php foreach ($all_employees as $emp): ?>
                                        <option value="<?= $emp['id'] ?>" <?= ($selected_employee_id === $emp['id']) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($emp['name']) ?> (<?= getRoleDisplayName($emp['role']) ?>)
                                            <?php if (in_array($emp['id'], $long_duty_employees_ids)): ?>
                                                &nbsp; [⚠️ Ada Over-Duty]
                                            <?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </form>
                </div>
                
                <div class="modern-info-box" style="margin-bottom: 0; margin-top: 1rem; background: rgba(59, 130, 246, 0.1); border-color: rgba(59, 130, 246, 0.3); color: #93c5fd;">
                    <span class="icon">ℹ️</span>
                    <div>Tanda <strong>[⚠️ Ada Over-Duty]</strong> di sebelah nama menunjukkan anggota tersebut memiliki sesi kerja tunggal (satu kali shift) yang melebihi <strong>7 Jam</strong> secara beruntun.</div>
                </div>
            </div>

            <?php if (!empty($overtime_logs)): ?>
            <div class="modern-card" style="border-color: rgba(239, 68, 68, 0.3); background: linear-gradient(145deg, rgba(239, 68, 68, 0.05), rgba(15, 23, 42, 0.6)) !important;">
                <div class="modern-card-header" style="border-bottom-color: rgba(239, 68, 68, 0.2);">
                    <h3 style="color: #fca5a5;"><span>🚨</span> Laporan Anomali: Sesi Over-Duty (> 7 Jam)</h3>
                </div>
                <div class="card-content">
                    <div class="modern-info-box" style="background: rgba(239, 68, 68, 0.1); border-color: rgba(239, 68, 68, 0.3); color: #fca5a5; margin-bottom: 1.5rem;">
                        <span class="icon">💡</span>
                        <div>Terdapat sesi jam kerja anggota yang melebihi batas wajar. <strong>Klik pada kartu di bawah ini</strong> untuk memfilter dan melihat detail riwayat anggota tersebut, lalu hapus riwayatnya jika terbukti kelalaian (lupa Off Duty).</div>
                    </div>
                    
                    <div class="overtime-scroll-wrapper">
                        <div class="overtime-grid-modern">
                            <?php foreach ($overtime_logs as $ot): ?>
                                <a href="?employee_id=<?= $ot['employee_id'] ?>" class="overtime-card-modern">
                                    <div class="otc-header">
                                        <span class="otc-name"><?= htmlspecialchars($ot['employee_name']) ?></span>
                                        <span class="otc-duration"><?= formatDuration($ot['duration_minutes']) ?></span>
                                    </div>
                                    <div class="otc-details">
                                        <span>📅 <?= date('d M Y', strtotime($ot['duty_start'])) ?></span>
                                        <span>🕒 <?= date('H:i', strtotime($ot['duty_start'])) ?> - <?= $ot['duty_end'] ? date('H:i', strtotime($ot['duty_end'])) : 'Berlangsung' ?></span>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($selected_employee_id): ?>
                <div class="modern-card">
                    <div class="modern-card-header">
                        <h3><span>📋</span> Log Duty: <span style="color: #60a5fa;"><?= $selected_employee_name ?></span></h3>
                    </div>
                    
                    <div class="modern-info-box" style="background: rgba(245, 158, 11, 0.1); border-color: rgba(245, 158, 11, 0.3); color: #fde68a;">
                        <span class="icon">⚠️</span>
                        <div>Baris tabel dengan <strong>latar belakang kemerahan</strong> mengindikasikan sesi jam kerja tidak wajar yang melebihi batas normal (> 7 Jam). Centang kotak di sebelah kiri untuk menghapusnya.</div>
                    </div>

                    <?php if (empty($employee_duty_logs)): ?>
                        <div style="text-align: center; padding: 3rem 0; color: #64748b; font-style: italic;">
                            <span style="font-size: 3rem; display: block; margin-bottom: 10px;">🍃</span>
                            Belum ada riwayat jam kerja yang tercatat.
                        </div>
                    <?php else: ?>
                        <form method="POST" id="delete-multiple-form">
                            <input type="hidden" name="action" value="delete_duty_logs">
                            <input type="hidden" name="employee_id_of_log" value="<?= $selected_employee_id ?>">
                            
                            <div class="modern-table-wrapper">
                                <table class="report-table">
                                    <thead>
                                        <tr>
                                            <th style="width: 40px; text-align: center;"><input type="checkbox" id="select-all-checkbox" class="modern-checkbox"></th>
                                            <th>Tanggal</th>
                                            <th>Mulai</th>
                                            <th>Selesai</th>
                                            <th>Durasi</th>
                                            <th>Tipe Input</th>
                                            <th>Status</th>
                                            <th>Penyetuju</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($employee_duty_logs as $log): ?>
                                        <?php
                                        // Perhitungan durasi & long duty
                                        $is_long_duty = ($log['duration_minutes'] > 420);
                                        $is_active = $log['status'] === 'active';

                                        // Tentukan TIPE tampilan
                                        $display_type = 'Otomatis';
                                        $type_badge = 'b-info';
                                        if ($log['is_manual'] == 1) {
                                            $display_type = 'Manual (Web)';
                                            $type_badge = 'b-warning';
                                        } elseif ($log['is_manual'] == 2) {
                                            $display_type = 'Discord/Bot';
                                            $type_badge = 'b-primary';
                                        }

                                        // Tentukan Status
                                        $status_badge = 'b-warning'; // pending
                                        if ($log['status'] === 'completed') $status_badge = 'b-success';
                                        if ($log['status'] === 'active') $status_badge = 'b-danger'; // Sedang jalan

                                        // Tentukan Durasi & Selesai
                                        $display_duration = $is_active ? 'Berlangsung' : formatDuration($log['duration_minutes']);
                                        $display_end_time = $log['duty_end'] ? date('H:i', strtotime($log['duty_end'])) : 'Berlangsung...';
                                        $display_status = ucfirst($log['status']);
                                        ?>
                                        <tr class="<?= $is_long_duty ? 'long-duty-row-modern' : '' ?>">
                                            <td style="text-align: center;">
                                                <input type="checkbox" name="duty_log_ids[]" value="<?= $log['id'] ?>" class="modern-checkbox row-checkbox">
                                            </td>
                                            <td style="font-weight: 600; color: #fff;"><?= date('d/m/Y', strtotime($log['duty_start'])) ?></td>
                                            <td><?= date('H:i', strtotime($log['duty_start'])) ?></td>
                                            <td><?= $display_end_time ?></td>
                                            <td style="color: <?= $is_long_duty ? '#fca5a5' : ($is_active ? '#94a3b8' : '#34d399') ?>; font-weight: bold;">
                                                <?= $display_duration ?>
                                                <?php if ($is_long_duty): ?>
                                                    <span class="alert-badge" title="Melebihi batas 7 Jam!">! >7J</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><span class="badge-pill <?= $type_badge ?>"><?= $display_type ?></span></td>
                                            <td><span class="badge-pill <?= $status_badge ?>"><?= $display_status ?></span></td>
                                            <td style="color: #cbd5e1;"><?= $log['approved_by_name'] ? htmlspecialchars($log['approved_by_name']) : '-' ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            
                            <button type="submit" class="btn-delete-mass" id="delete-selected-btn" disabled
                                onclick="return confirm('YAKIN INGIN MENGHAPUS SEMUA LOG YANG DICENTANG?\n\nAksi ini bersifat permanen. Notifikasi penghapusan akan dikirimkan ke Discord.')">
                                <span>🗑️</span> Hapus Data Terpilih
                            </button>
                            <div style="clear: both;"></div> </form>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            
        </main>
    </div>

    <script src="script.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const selectAllCheckbox = document.getElementById('select-all-checkbox');
            const rowCheckboxes = document.querySelectorAll('.row-checkbox');
            const deleteSelectedBtn = document.getElementById('delete-selected-btn');

            if (selectAllCheckbox) {
                selectAllCheckbox.addEventListener('change', function() {
                    rowCheckboxes.forEach(checkbox => {
                        checkbox.checked = this.checked;
                    });
                    updateDeleteButtonState();
                });
            }

            rowCheckboxes.forEach(checkbox => {
                checkbox.addEventListener('change', function() {
                    updateDeleteButtonState();
                    
                    // Uncheck "Select All" if not all are checked
                    if (!this.checked && selectAllCheckbox) {
                        selectAllCheckbox.checked = false;
                    }
                });
            });

            function updateDeleteButtonState() {
                const anyChecked = Array.from(rowCheckboxes).some(checkbox => checkbox.checked);
                if (deleteSelectedBtn) {
                    deleteSelectedBtn.disabled = !anyChecked;
                }
            }
        });
    </script>
</body>
</html>