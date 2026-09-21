<?php
require_once 'config.php';

if (!isLoggedIn() || !hasRole(['ceo', 'direktur', 'wakil_direktur', 'manager'])) {
    header('Location: dashboard.php');
    exit;
}

$user = getCurrentUser(); // Dapatkan user yang sedang login untuk approved_by

// Ambil jumlah permohonan pending untuk indikator sidebar
$pending_requests_count = getPendingRequestCount();

// Inisialisasi variabel feedback
$success = null;
$error = null;

// --- Handle action untuk manual On Duty (NEW LOGIC) ---
if (($_SERVER['REQUEST_METHOD'] === 'POST') && (isset($_POST['action']) && $_POST['action'] === 'manual_on_duty')) {
    $employee_id_to_on_duty = (int)($_POST['employee_id'] ?? 0);

    if ($employee_id_to_on_duty <= 0) {
        $error = "ID anggota tidak valid!";
    } elseif ($employee_id_to_on_duty == $user['id']) {
        $error = "Anda tidak bisa meng-on duty diri sendiri secara manual dari sini. Silakan gunakan tombol On Duty di Dashboard.";
    } else {
        $conn->begin_transaction();
        try {
            $stmt_get_employee = $conn->prepare("SELECT name, is_on_duty FROM employees WHERE id = ? AND status = 'active'");
            if (!$stmt_get_employee) {
                throw new Exception("Gagal menyiapkan query ambil data anggota: " . $conn->error);
            }
            $stmt_get_employee->bind_param("i", $employee_id_to_on_duty);
            $stmt_get_employee->execute();
            $employee_data = $stmt_get_employee->get_result()->fetch_assoc();
            $stmt_get_employee->close();

            if (!$employee_data || $employee_data['is_on_duty']) {
                throw new Exception("Anggota tidak ditemukan atau sudah On Duty.");
            }
            
            // 1. Update status employee
            $stmt_update_employee = $conn->prepare("UPDATE employees SET is_on_duty = TRUE, current_duty_start = NOW() WHERE id = ?");
            if (!$stmt_update_employee) {
                throw new Exception("Gagal menyiapkan query update status anggota: " . $conn->error);
            }
            $stmt_update_employee->bind_param("i", $employee_id_to_on_duty);
            if (!$stmt_update_employee->execute()) {
                throw new Exception("Gagal mengupdate status anggota: " . $stmt_update_employee->error);
            }
            $stmt_update_employee->close();

            // 2. Insert new active duty log (simulating manual clock-in)
            $stmt_insert_log = $conn->prepare("INSERT INTO duty_logs (employee_id, duty_start, is_manual, approved_by, status) VALUES (?, NOW(), 1, ?, 'active')");
            if (!$stmt_insert_log) {
                throw new Exception("Gagal membuat log duty baru: " . $conn->error);
            }
            $stmt_insert_log->bind_param("ii", $employee_id_to_on_duty, $user['id']);
            if (!$stmt_insert_log->execute()) {
                throw new Exception("Gagal membuat log duty baru: " . $stmt_insert_log->error);
            }
            $stmt_insert_log->close();

            $conn->commit();
            $success = "Anggota " . htmlspecialchars($employee_data['name']) . " berhasil diatur **On Duty** secara manual!";

            // Kirim notifikasi Discord
            sendDiscordNotification([
                'employee_name' => htmlspecialchars($employee_data['name']),
                'action_type' => 'Manual On Duty',
                'admin_name' => htmlspecialchars($user['name']),
                'event_type' => 'clock_in', 
            ], 'clock_event');
            
        } catch (Exception $e) {
            $conn->rollback();
            $error = "Terjadi kesalahan saat meng-on duty anggota: " . $e->getMessage();
        }
    }
}
// --- AKHIR LOGIKA MANUAL ON DUTY ---


// --- Handle action untuk manual Off Duty (EXISTING LOGIC) ---
if (($_SERVER['REQUEST_METHOD'] === 'POST') && (isset($_POST['action']) && $_POST['action'] === 'manual_off_duty')) {
    $employee_id_to_off_duty = (int)($_POST['employee_id'] ?? 0);

    if ($employee_id_to_off_duty <= 0) {
        $error = "ID anggota tidak valid!";
    } elseif ($employee_id_to_off_duty == $user['id']) {
        $error = "Anda tidak bisa meng-off duty diri sendiri secara manual dari sini. Silakan gunakan tombol Off Duty di Dashboard.";
    } else {
        $conn->begin_transaction();
        try {
            $stmt_get_employee = $conn->prepare("SELECT name, is_on_duty, current_duty_start FROM employees WHERE id = ? AND status = 'active'");
            if (!$stmt_get_employee) {
                throw new Exception("Gagal menyiapkan query ambil data anggota: " . $conn->error);
            }
            $stmt_get_employee->bind_param("i", $employee_id_to_off_duty);
            $stmt_get_employee->execute();
            $employee_data = $stmt_get_employee->get_result()->fetch_assoc();
            $stmt_get_employee->close();

            if (!$employee_data || !$employee_data['is_on_duty']) {
                throw new Exception("Anggota tidak ditemukan atau tidak sedang On Duty.");
            }

            // 1. Update duty_logs
            $stmt_get_log = $conn->prepare("SELECT id, duty_start FROM duty_logs WHERE employee_id = ? AND duty_end IS NULL ORDER BY id DESC LIMIT 1");
            if (!$stmt_get_log) {
                throw new Exception("Gagal menyiapkan query ambil log duty: " . $conn->error);
            }
            $stmt_get_log->bind_param("i", $employee_id_to_off_duty);
            $stmt_get_log->execute();
            $active_log = $stmt_get_log->get_result()->fetch_assoc();
            $stmt_get_log->close();

            if ($active_log) {
                $duty_start_dt = new DateTime($active_log['duty_start']);
                $now_dt = new DateTime();
                $duration_minutes = ($now_dt->getTimestamp() - $duty_start_dt->getTimestamp()) / 60;

                $stmt_update_log = $conn->prepare("UPDATE duty_logs SET duty_end = NOW(), duration_minutes = ?, status = 'completed', approved_by = ? WHERE id = ?");
                if (!$stmt_update_log) {
                    throw new Exception("Gagal menyiapkan query update log duty: " . $conn->error);
                }
                $stmt_update_log->bind_param("iii", $duration_minutes, $user['id'], $active_log['id']);
                if (!$stmt_update_log->execute()) {
                    throw new Exception("Gagal mengupdate log duty: " . $stmt_update_log->error);
                }
                $stmt_update_log->close();
            } else {
                error_log("Warning: Employee " . $employee_data['name'] . " is_on_duty=TRUE but no active duty_log found.");
            }

            // 2. Update status employee
            $stmt_update_employee = $conn->prepare("UPDATE employees SET is_on_duty = FALSE, current_duty_start = NULL WHERE id = ?");
            if (!$stmt_update_employee) {
                throw new Exception("Gagal menyiapkan query update status anggota: " . $conn->error);
            }
            $stmt_update_employee->bind_param("i", $employee_id_to_off_duty);
            if (!$stmt_update_employee->execute()) {
                throw new Exception("Gagal mengupdate status anggota: " . $stmt_update_employee->error);
            }
            $stmt_update_employee->close();

            $conn->commit();
            $success = "Anggota " . htmlspecialchars($employee_data['name']) . " berhasil diatur **Off Duty** secara manual!";

            sendDiscordNotification([
                'employee_name' => htmlspecialchars($employee_data['name']),
                'request_type' => 'Manual Off Duty',
                'status' => 'completed',
                'approved_by_name' => htmlspecialchars($user['name']),
            ], 'request_status_update'); 
            
        } catch (Exception $e) {
            $conn->rollback();
            $error = "Terjadi kesalahan saat meng-off duty anggota: " . $e->getMessage();
        }
    }
}
// --- AKHIR LOGIKA MANUAL OFF DUTY ---


// Get all employees AND SPLIT THEM
$stmt = $conn->query("
    SELECT e.*, 
           CASE WHEN e.is_on_duty THEN 'On Duty' ELSE 'Off Duty' END as duty_status
    FROM employees e 
    WHERE e.status = 'active'
    ORDER BY 
        e.is_on_duty DESC, /* Prioritaskan On Duty di tingkat query juga */
        CASE e.role 
            WHEN 'ceo' THEN 1
            WHEN 'direktur' THEN 2
            WHEN 'wakil_direktur' THEN 3
            WHEN 'manager' THEN 4
            WHEN 'chef' THEN 5
            WHEN 'waiters' THEN 6
            WHEN 'karyawan' THEN 7
            WHEN 'magang' THEN 8
        END,
        e.name
");
$all_employees = $stmt->fetch_all(MYSQLI_ASSOC);

$on_duty_employees = [];
$off_duty_employees = [];
$long_duty_members_info = [];

// Proses pemisahan array dan kalkulasi long duty
foreach ($all_employees as $key => $employee) {
    // Cek durasi (Long duty warning)
    if ($employee['is_on_duty'] && $employee['current_duty_start']) {
        $start_time = new DateTime($employee['current_duty_start']);
        $now_time = new DateTime();
        $duty_duration_seconds = $now_time->getTimestamp() - $start_time->getTimestamp();
        
        if ($duty_duration_seconds > (5 * 3600)) { // Lebih dari 5 jam
            $long_duty_members_info[] = [
                'name' => htmlspecialchars($employee['name']),
                'duration' => formatDuration($duty_duration_seconds / 60)
            ];
            $employee['is_long_duty'] = true;
        }
    }

    // Pisahkan berdasarkan status
    if ($employee['is_on_duty']) {
        $on_duty_employees[] = $employee;
    } else {
        $off_duty_employees[] = $employee;
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Daftar Anggota - Warung Om Tante V2</title>
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
            background: radial-gradient(circle, rgba(16, 185, 129, 0.15), transparent 70%); pointer-events: none; z-index: 0;
        }
        
        .header-content-wrapper { display: flex; align-items: center; gap: 1.25rem; z-index: 2; position: relative; }
        .header-icon-wrapper {
            background: rgba(255, 255, 255, 0.05); border: 1px solid rgba(255, 255, 255, 0.1);
            width: 60px; height: 60px; border-radius: 16px; display: flex; align-items: center;
            justify-content: center; font-size: 1.8rem; box-shadow: inset 0 0 15px rgba(0,0,0,0.3); flex-shrink: 0; color: #34d399;
        }
        .header-text-wrapper h1 { color: #fff; font-weight: 800; font-size: 2.2rem; letter-spacing: -0.02em; margin: 0; }
        .header-text-wrapper p { color: var(--text-secondary); margin: 0.25rem 0 0 0; font-size: 1.05rem; }

        /* --- Cards (Glassmorphism) --- */
        .modern-card {
            background: rgba(30, 41, 59, 0.6) !important; backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.08) !important; border-radius: 24px;
            box-shadow: 0 15px 35px rgba(0,0,0,0.3) !important; padding: 2rem;
            margin-bottom: 2rem;
        }
        .card-anim-1 { animation: slideUp 0.6s ease-out 0.1s backwards; }
        .card-anim-2 { animation: slideUp 0.6s ease-out 0.3s backwards; opacity: 0.95; } /* Sedikit transparan untuk off-duty */

        .modern-card-header {
            display: flex; justify-content: space-between; align-items: center; 
            margin-bottom: 1.5rem; border-bottom: 1px solid rgba(255, 255, 255, 0.1); padding-bottom: 1rem;
            flex-wrap: wrap; gap: 15px;
        }
        .modern-card-header h3 { color: #fff; font-size: 1.25rem; font-weight: 700; margin: 0; display: flex; align-items: center; gap: 10px;}
        .member-count-modern {
            background: rgba(255,255,255,0.05); padding: 5px 15px; border-radius: 20px;
            font-size: 0.9rem; font-weight: 600; color: #cbd5e1; border: 1px solid rgba(255,255,255,0.1);
        }

        /* --- Employees Grid --- */
        .modern-employees-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 1.5rem;
        }

        /* --- Employee Card --- */
        .employee-card-modern {
            background: linear-gradient(145deg, rgba(30, 41, 59, 0.4), rgba(15, 23, 42, 0.6));
            border: 1px solid rgba(255, 255, 255, 0.05); border-radius: 20px; padding: 1.5rem;
            transition: all 0.3s ease; position: relative; display: flex; flex-direction: column; gap: 1.2rem;
        }
        .employee-card-modern:hover {
            transform: translateY(-5px); box-shadow: 0 10px 25px rgba(0,0,0,0.4);
            background: linear-gradient(145deg, rgba(30, 41, 59, 0.6), rgba(15, 23, 42, 0.8));
            border-color: rgba(255, 255, 255, 0.15);
        }

        /* Card Header (Avatar + Info) */
        .ec-header { display: flex; align-items: center; gap: 15px; }
        .ec-avatar {
            width: 50px; height: 50px; border-radius: 50%; background: rgba(255,255,255,0.05);
            display: flex; align-items: center; justify-content: center; font-size: 1.5rem;
            border: 1px solid rgba(255,255,255,0.1); flex-shrink: 0; box-shadow: inset 0 0 10px rgba(0,0,0,0.5);
        }
        .ec-info { display: flex; flex-direction: column; gap: 4px; overflow: hidden;}
        .ec-name { font-size: 1.1rem; font-weight: 800; color: #fff; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .ec-role {
            background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); color: var(--primary-color);
            font-size: 0.7rem; font-weight: 800; padding: 3px 8px; border-radius: 6px; text-transform: uppercase; letter-spacing: 0.05em; width: fit-content;
        }

        /* Card Body (Status & Timer) */
        .ec-body {
            background: rgba(0,0,0,0.2); border-radius: 12px; padding: 1rem; border: 1px solid rgba(255,255,255,0.02);
            display: flex; flex-direction: column; gap: 10px;
        }
        .ec-status-row { display: flex; justify-content: space-between; align-items: center; }
        
        .status-badge-modern {
            display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px; border-radius: 30px;
            font-size: 0.8rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em;
        }
        .sb-onduty { background: rgba(16, 185, 129, 0.15); color: #34d399; border: 1px solid rgba(16, 185, 129, 0.3); }
        .sb-offduty { background: rgba(255, 255, 255, 0.05); color: #94a3b8; border: 1px solid rgba(255, 255, 255, 0.1); }
        
        .status-dot { width: 8px; height: 8px; border-radius: 50%; }
        .sb-onduty .status-dot { background: #34d399; box-shadow: 0 0 8px #34d399; animation: pulse-live 1.5s infinite; }
        .sb-offduty .status-dot { background: #64748b; }

        .duty-timer-modern {
            font-family: 'SF Mono', 'Roboto Mono', monospace; font-size: 1rem; font-weight: 800; color: #f8fafc; letter-spacing: 1px;
        }
        .duty-timer-modern.active-time { color: #facc15; text-shadow: 0 0 10px rgba(250, 204, 21, 0.4); }

        /* Card Footer (Actions) */
        .ec-footer { margin-top: auto; }
        
        .btn-modern-action {
            width: 100%; border: none; padding: 0.8rem; border-radius: 12px; font-size: 0.9rem; font-weight: 700;
            cursor: pointer; transition: all 0.3s ease; display: flex; align-items: center; justify-content: center; gap: 8px;
        }
        .btn-success-modern { background: rgba(16, 185, 129, 0.15); color: #34d399; border: 1px solid rgba(16, 185, 129, 0.4); }
        .btn-success-modern:hover { background: rgba(16, 185, 129, 0.3); transform: translateY(-2px); box-shadow: 0 5px 15px rgba(16, 185, 129, 0.2); border-color: #34d399; }
        
        .btn-danger-modern { background: rgba(239, 68, 68, 0.15); color: #fca5a5; border: 1px solid rgba(239, 68, 68, 0.4); }
        .btn-danger-modern:hover { background: rgba(239, 68, 68, 0.3); transform: translateY(-2px); box-shadow: 0 5px 15px rgba(239, 68, 68, 0.2); border-color: #fca5a5; }

        /* --- Alerts --- */
        .success-alert { background: rgba(52, 211, 153, 0.15); border: 1px solid rgba(52, 211, 153, 0.4); color: #6ee7b7; padding: 1rem 1.2rem; border-radius: 14px; margin-bottom: 1.5rem; display: flex; align-items: center; gap: 0.75rem; font-size: 0.95rem; font-weight: 500; animation: slideDown 0.4s ease-out; }
        .error-alert { background: rgba(220, 53, 69, 0.15); border: 1px solid rgba(220, 53, 69, 0.4); color: #fca5a5; padding: 1rem 1.2rem; border-radius: 14px; margin-bottom: 1.5rem; display: flex; align-items: center; gap: 0.75rem; font-size: 0.95rem; font-weight: 500; animation: shake 0.5s ease-in-out; }
        
        .warning-alert-modern {
            background: rgba(245, 158, 11, 0.15); border: 1px solid rgba(245, 158, 11, 0.4); color: #fde68a;
            padding: 1.25rem 1.5rem; border-radius: 16px; margin-bottom: 2rem; box-shadow: 0 10px 25px rgba(0,0,0,0.2);
            animation: slideDown 0.5s ease-out backwards;
        }
        .warning-alert-modern h4 { margin: 0 0 10px 0; color: #fbbf24; display: flex; align-items: center; gap: 8px; font-size: 1.1rem; }
        .warning-alert-modern ul { margin: 0; padding-left: 20px; font-size: 0.95rem; line-height: 1.6; }
        .warning-alert-modern li { margin-bottom: 4px; }
        .warning-alert-modern p { margin: 10px 0 0 0; font-size: 0.9rem; color: #fbbf24; opacity: 0.9; }

        .over-duty-badge {
            background: rgba(245, 158, 11, 0.2); border: 1px solid rgba(245, 158, 11, 0.4); color: #fbbf24;
            font-size: 0.75rem; padding: 4px 8px; border-radius: 6px; display: inline-flex; align-items: center; gap: 5px; font-weight: 700;
        }

        @keyframes pulse-live { 0% { transform: scale(0.95); opacity: 0.8; } 50% { transform: scale(1.5); opacity: 1; } 100% { transform: scale(0.95); opacity: 0.8; } }
        @keyframes slideDown { from { opacity: 0; transform: translateY(-20px); } to { opacity: 1; transform: translateY(0); } }
        @keyframes slideUp { from { opacity: 0; transform: translateY(30px); } to { opacity: 1; transform: translateY(0); } }
        @keyframes shake { 0%, 100% { transform: translateX(0); } 25% { transform: translateX(-5px); } 75% { transform: translateX(5px); } }

        @media (max-width: 768px) {
            .modern-page-header { padding: 1.5rem; text-align: center; }
            .header-content-wrapper { flex-direction: column; }
            .header-text-wrapper h1 { font-size: 1.8rem; }
            .modern-employees-grid { grid-template-columns: 1fr; }
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
                    <div class="header-icon-wrapper">👥</div>
                    <div class="header-text-wrapper">
                        <h1>Daftar Anggota</h1>
                        <p>Daftar semua anggota Warung Om Tante V2 beserta status presensi saat ini.</p>
                    </div>
                </div>
            </div>

            <?php if (isset($success)): ?>
                <div class="success-alert"><span>🎉</span> <?= $success ?></div>
            <?php endif; ?>
            
            <?php if (isset($error)): ?>
                <div class="error-alert"><span>⚠️</span> <?= $error ?></div>
            <?php endif; ?>

            <?php if (!empty($long_duty_members_info)): ?>
                <div class="warning-alert-modern">
                    <h4><span>⚠️</span> Perhatian: Anggota Over Duty (> 5 Jam)</h4>
                    <ul>
                        <?php foreach ($long_duty_members_info as $member): ?>
                            <li><strong><?= $member['name'] ?></strong> (Durasi: <?= $member['duration'] ?>)</li>
                        <?php endforeach; ?>
                    </ul>
                    <p>Pertimbangkan untuk menghubungi mereka atau melakukan "Off Duty Manual" di bawah.</p>
                </div>
            <?php endif; ?>

            <div class="modern-card card-anim-1">
                <div class="modern-card-header" style="border-bottom-color: rgba(16, 185, 129, 0.3);">
                    <h3 style="color: #34d399;"><span>🟢</span> Sedang Bertugas (On Duty)</h3>
                    <span class="member-count-modern" style="background: rgba(16, 185, 129, 0.15); color: #34d399; border-color: rgba(16, 185, 129, 0.3);">
                        <?= count($on_duty_employees) ?> Anggota
                    </span>
                </div>
                <div class="card-content">
                    <?php if(empty($on_duty_employees)): ?>
                        <div style="text-align: center; padding: 2rem 0; color: #64748b; font-style: italic;">
                            <span style="font-size: 2rem; display: block; margin-bottom: 10px;">🏖️</span>
                            Belum ada anggota yang sedang bertugas saat ini.
                        </div>
                    <?php else: ?>
                        <div class="modern-employees-grid">
                            <?php foreach ($on_duty_employees as $employee): ?>
                            <div class="employee-card-modern" style="border-color: rgba(16, 185, 129, 0.2);">
                                
                                <div class="ec-header">
                                    <div class="ec-avatar" style="color: #34d399; background: rgba(16, 185, 129, 0.1);">👤</div>
                                    <div class="ec-info">
                                        <div class="ec-name" title="<?= htmlspecialchars($employee['name']) ?>"><?= htmlspecialchars($employee['name']) ?></div>
                                        <div class="ec-role"><?= getRoleDisplayName($employee['role']) ?></div>
                                    </div>
                                </div>
                                
                                <div class="ec-body">
                                    <div class="ec-status-row">
                                        <span class="status-badge-modern sb-onduty">
                                            <span class="status-dot"></span>
                                            <?= $employee['duty_status'] ?>
                                        </span>
                                        <div class="duty-timer duty-timer-modern active-time" data-start-time="<?= $employee['current_duty_start'] ?>">
                                            00:00:00
                                        </div>
                                    </div>
                                    
                                    <?php if (isset($employee['is_long_duty']) && $employee['is_long_duty']): ?>
                                        <div class="over-duty-badge">
                                            <span>⏰</span> Sudah On Duty > 5 jam
                                        </div>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="ec-footer">
                                    <?php if ($employee['id'] != $user['id']): ?>
                                        <form method="POST" onsubmit="return confirm('Yakin ingin mengakhiri sesi On Duty untuk <?= htmlspecialchars($employee['name']) ?> sekarang? Tindakan ini akan mencatat waktu Off Duty saat ini.');">
                                            <input type="hidden" name="action" value="manual_off_duty">
                                            <input type="hidden" name="employee_id" value="<?= $employee['id'] ?>">
                                            <button type="submit" class="btn-modern-action btn-danger-modern">
                                                <span>⏹️</span> Off Duty Manual
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <button class="btn-modern-action" style="background: rgba(255,255,255,0.05); color: #64748b; cursor: not-allowed;" disabled>
                                            Gunakan Dashboard Sendiri
                                        </button>
                                    <?php endif; ?>
                                </div>
                                
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="modern-card card-anim-2">
                <div class="modern-card-header">
                    <h3><span>💤</span> Sedang Istirahat (Off Duty)</h3>
                    <span class="member-count-modern">
                        <?= count($off_duty_employees) ?> Anggota
                    </span>
                </div>
                <div class="card-content">
                    <?php if(empty($off_duty_employees)): ?>
                        <div style="text-align: center; padding: 2rem 0; color: #64748b; font-style: italic;">
                            Semua anggota sedang bertugas.
                        </div>
                    <?php else: ?>
                        <div class="modern-employees-grid">
                            <?php foreach ($off_duty_employees as $employee): ?>
                            <div class="employee-card-modern">
                                
                                <div class="ec-header">
                                    <div class="ec-avatar">👤</div>
                                    <div class="ec-info">
                                        <div class="ec-name" title="<?= htmlspecialchars($employee['name']) ?>"><?= htmlspecialchars($employee['name']) ?></div>
                                        <div class="ec-role"><?= getRoleDisplayName($employee['role']) ?></div>
                                    </div>
                                </div>
                                
                                <div class="ec-body">
                                    <div class="ec-status-row">
                                        <span class="status-badge-modern sb-offduty">
                                            <span class="status-dot"></span>
                                            <?= $employee['duty_status'] ?>
                                        </span>
                                        <div class="duty-timer-modern" style="color: #64748b;">- - -</div>
                                    </div>
                                </div>
                                
                                <div class="ec-footer">
                                    <?php if ($employee['id'] != $user['id']): ?>
                                        <form method="POST" onsubmit="return confirm('Yakin ingin memulai sesi On Duty manual untuk <?= htmlspecialchars($employee['name']) ?> sekarang?');">
                                            <input type="hidden" name="action" value="manual_on_duty">
                                            <input type="hidden" name="employee_id" value="<?= $employee['id'] ?>">
                                            <button type="submit" class="btn-modern-action btn-success-modern">
                                                <span>▶️</span> On Duty Manual
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <button class="btn-modern-action" style="background: rgba(255,255,255,0.05); color: #64748b; cursor: not-allowed;" disabled>
                                            Gunakan Dashboard Sendiri
                                        </button>
                                    <?php endif; ?>
                                </div>
                                
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
        </main>
    </div>

    <script src="script.js"></script>
    <script>
        // Update duty timers for all employees
        function updateAllDutyTimers() {
            const timers = document.querySelectorAll('.duty-timer');
            timers.forEach(timer => {
                const rawStartTime = timer.dataset.startTime;
                if (rawStartTime) {
                    // Penyesuaian kecil agar aman di semua browser (Safari/iOS)
                    const safeStartTime = rawStartTime.replace(' ', 'T'); 
                    const start = new Date(safeStartTime);
                    const now = new Date();
                    
                    // Pastikan tidak minus jika jam server & lokal berbeda
                    const diff = Math.max(0, now - start); 
                    
                    const hours = Math.floor(diff / (1000 * 60 * 60));
                    const minutes = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60));
                    const seconds = Math.floor((diff % (1000 * 60)) / 1000);
                    
                    timer.textContent = `${hours.toString().padStart(2, '0')}:${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
                }
            });
        }
        
        setInterval(updateAllDutyTimers, 1000);
        updateAllDutyTimers(); // Panggil sekali saat dimuat agar tidak delay 1 detik
    </script>
</body>
</html>