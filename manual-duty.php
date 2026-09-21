<?php
require_once 'config.php';

if (!isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$user = getCurrentUser();

// Tentukan apakah pengguna memiliki peran admin yang diizinkan
$is_admin_or_manager = hasRole(['ceo', 'direktur', 'wakil_direktur', 'manager']);

// Inisialisasi ID karyawan yang akan diinput datanya. Defaultnya adalah user yang login.
$employee_id_to_submit = $user['id'];
$selected_employee_name = $user['name'];

// Jika pengguna memiliki peran admin, ambil daftar semua karyawan untuk dropdown
$all_employees = [];
if ($is_admin_or_manager) {
    $all_employees = $conn->query("SELECT id, name, role FROM employees WHERE status = 'active' ORDER BY name")->fetch_all(MYSQLI_ASSOC);
    // Jika ada ID anggota yang dipilih dari URL, gunakan ID tersebut
    if (isset($_GET['employee_id']) && !empty($_GET['employee_id'])) {
        $employee_id_to_submit = (int)$_GET['employee_id'];
        foreach ($all_employees as $emp) {
            if ($emp['id'] === $employee_id_to_submit) {
                $selected_employee_name = htmlspecialchars($emp['name']);
                break;
            }
        }
    }
}

// Ambil jumlah permohonan pending untuk indikator sidebar
$pending_requests_count = getPendingRequestCount();

// Inisialisasi variabel feedback
$success = null;
$error = null;

// Handle form submission
if ($_POST['action'] ?? '' === 'submit_manual_duty') {
    $employee_id_from_form = (int)($_POST['employee_id'] ?? $user['id']);
    $duty_date = $_POST['duty_date'] ?? '';
    $start_time = $_POST['start_time'] ?? '';
    $end_time = $_POST['end_time'] ?? '';
    $reason = $_POST['reason'] ?? '';
    
    // Inisialisasi $error_message untuk setiap kali submit form
    $error = null;
    
    if ($duty_date && $start_time && $end_time && $reason) {
        // Validate time logic
        $start_timestamp = strtotime($start_time);
        $end_timestamp = strtotime($end_time);
        
        // Calculate duration considering overnight shifts
        if ($end_timestamp <= $start_timestamp) {
            // Overnight shift - add 24 hours to end time
            $end_timestamp += 24 * 60 * 60;
        }
        
        $duration_minutes = ($end_timestamp - $start_timestamp) / 60;
        
        // Validate reasonable duration (max 24 hours)
        if ($duration_minutes > 1440) { // 24 hours = 1440 minutes
            $error = "Durasi kerja tidak boleh lebih dari 24 jam!";
        } elseif ($duration_minutes < 15) { // minimum 15 minutes
            $error = "Durasi kerja minimal 15 menit!";
        } else {
            $stmt = $conn->prepare("
                INSERT INTO manual_duty_requests (employee_id, duty_date, start_time, end_time, reason)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->bind_param("issss", $employee_id_from_form, $duty_date, $start_time, $end_time, $reason);
            
            if ($stmt->execute()) {
                $duration_hours = floor($duration_minutes / 60);
                $duration_mins = $duration_minutes % 60;
                $duration_text = $duration_hours . "j " . $duration_mins . "m";
                
                sendDiscordNotification([
                    'employee_name' => getEmployeeNameById($employee_id_from_form),
                    'duty_date' => $duty_date,
                    'start_time' => $start_time,
                    'end_time' => $end_time,
                    'duration_text' => $duration_text,
                    'reason' => $reason
                ], 'manual_duty_request_submitted');
                $success = "Permohonan input jam manual berhasil diajukan untuk **" . getEmployeeNameById($employee_id_from_form) . "**! Durasi: " . $duration_text;
            } else {
                $error = "Gagal mengajukan permohonan input jam manual!";
            }
        }
    } else {
        $error = "Semua field harus diisi!";
    }
}

// Menampilkan pesan feedback setelah redirect
if (isset($_GET['msg']) && isset($_GET['type'])) {
    $feedback_message = htmlspecialchars($_GET['msg']);
    $feedback_type = htmlspecialchars($_GET['type']);
    if ($feedback_type === 'success') {
        $success = $feedback_message;
    } else {
        $error = $feedback_message;
    }
}


// Get user's manual duty requests
$manual_requests = [];
$stmt = $conn->prepare("
    SELECT mdr.*, e.name as approved_by_name 
    FROM manual_duty_requests mdr
    LEFT JOIN employees e ON mdr.approved_by = e.id
    WHERE mdr.employee_id = ?
    ORDER BY mdr.created_at DESC
");
$stmt->bind_param("i", $employee_id_to_submit);
$stmt->execute();
$manual_requests = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Input Jam Manual - Warung Om Tante V2</title>
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
            color: #60a5fa; 
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

        /* --- Cards (Glassmorphism) --- */
        .modern-card {
            background: rgba(30, 41, 59, 0.6) !important;
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.08) !important;
            border-radius: 24px;
            box-shadow: 0 15px 35px rgba(0,0,0,0.3) !important;
            padding: 2rem;
            animation: slideUp 0.6s ease-out backwards;
            margin-bottom: 2rem;
        }

        .modern-card:nth-child(2) { animation-delay: 0.2s; }

        .modern-card-header h3 {
            color: #fff;
            font-size: 1.25rem;
            font-weight: 700;
            margin-bottom: 1.5rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            padding-bottom: 0.75rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        /* --- Form Inner Wrapper --- */
        .form-inner-wrapper {
            background: linear-gradient(145deg, rgba(30, 41, 59, 0.4), rgba(15, 23, 42, 0.6));
            border: 1px solid rgba(255, 255, 255, 0.05);
            border-radius: 20px;
            padding: 1.75rem;
            box-shadow: inset 0 0 20px rgba(0,0,0,0.2);
        }

        /* --- Modern Info Box --- */
        .modern-info-box {
            background: rgba(59, 130, 246, 0.1);
            border: 1px solid rgba(59, 130, 246, 0.3);
            border-radius: 12px;
            padding: 1.25rem;
            color: #93c5fd;
            font-size: 0.95rem;
            line-height: 1.6;
            display: flex;
            align-items: flex-start;
            gap: 12px;
            margin-bottom: 1.5rem;
        }
        .modern-info-box span.icon { font-size: 1.5rem; flex-shrink: 0; }
        .modern-info-box strong { color: #bfdbfe; }

        .duration-preview-modern {
            background: rgba(16, 185, 129, 0.1);
            border: 1px solid rgba(16, 185, 129, 0.3);
            border-radius: 12px;
            padding: 1rem 1.25rem;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 10px;
            color: #a7f3d0;
            font-size: 1rem;
        }
        .duration-preview-modern span.icon { font-size: 1.2rem; }

        /* --- Modern Form Elements --- */
        .modern-form-group { margin-bottom: 1.5rem; }
        .modern-form-group label {
            display: block; font-size: 0.9rem; font-weight: 600; color: var(--text-secondary); margin-bottom: 0.6rem;
        }
        
        .form-row-modern {
            display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem;
        }

        .modern-input-wrapper {
            position: relative; display: flex; align-items: center;
        }
        .textarea-wrapper { align-items: flex-start; }
        
        .modern-input-icon {
            position: absolute; left: 1.25rem; font-size: 1.2rem; opacity: 0.6; pointer-events: none; z-index: 2;
        }
        .textarea-wrapper .modern-input-icon { top: 1.1rem; }
        
        .modern-input, .modern-textarea, .modern-select {
            width: 100%;
            background: rgba(0, 0, 0, 0.4);
            border: 1px solid rgba(255, 255, 255, 0.1);
            color: white;
            padding: 1.1rem 1.1rem 1.1rem 3.5rem;
            border-radius: 14px;
            font-size: 1rem;
            font-family: inherit;
            transition: all 0.3s ease;
        }
        
        .modern-select {
            appearance: none;
            cursor: pointer;
        }
        .modern-select option { background: var(--bg-secondary); color: white; }

        input[type="date"].modern-input, input[type="time"].modern-input {
            padding-right: 1.5rem;
        }
        input[type="date"]::-webkit-calendar-picker-indicator, input[type="time"]::-webkit-calendar-picker-indicator {
            filter: invert(1); cursor: pointer; opacity: 0.6; transition: 0.2s;
        }
        input[type="date"]::-webkit-calendar-picker-indicator:hover, input[type="time"]::-webkit-calendar-picker-indicator:hover {
            opacity: 1;
        }

        .modern-textarea { min-height: 120px; resize: vertical; line-height: 1.5; }

        .modern-input:focus, .modern-textarea:focus, .modern-select:focus {
            outline: none;
            border-color: var(--primary-color);
            background: rgba(0, 0, 0, 0.6);
            box-shadow: 0 0 0 3px rgba(255, 193, 7, 0.15);
        }

        .form-help-modern {
            display: block; margin-top: 0.5rem; font-size: 0.8rem; color: var(--text-muted); font-style: italic;
        }

        .modern-btn-submit {
            width: 100%; background: linear-gradient(135deg, var(--primary-color), var(--primary-hover));
            color: #121212; border: none; padding: 1.2rem; border-radius: 14px; font-size: 1.1rem;
            font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; cursor: pointer;
            transition: all 0.3s ease; display: flex; align-items: center; justify-content: center; gap: 0.75rem;
            margin-top: 1rem; box-shadow: 0 8px 20px rgba(255, 193, 7, 0.25);
        }
        .modern-btn-submit:hover { transform: translateY(-3px); box-shadow: 0 12px 25px rgba(255, 193, 7, 0.4); }
        .modern-btn-submit:active { transform: translateY(0); }

        /* ========================================================
           HISTORY ITEMS UI
           ======================================================== */
        .history-list { display: flex; flex-direction: column; gap: 1.25rem; }

        .history-item-modern {
            background: linear-gradient(145deg, rgba(30, 41, 59, 0.4), rgba(15, 23, 42, 0.6));
            border: 1px solid rgba(255, 255, 255, 0.05);
            border-radius: 20px; padding: 1.5rem; transition: all 0.3s ease;
            position: relative; display: flex; flex-direction: column; gap: 1.2rem;
        }

        .history-item-modern:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.4);
            background: linear-gradient(145deg, rgba(30, 41, 59, 0.6), rgba(15, 23, 42, 0.8));
            border-color: rgba(255, 255, 255, 0.1);
        }

        .history-item-modern::before {
            content: ''; position: absolute; left: -1px; top: 1.5rem; bottom: 1.5rem; width: 4px; border-radius: 0 4px 4px 0;
        }
        .history-item-modern.status-pending::before { background: var(--warning-color); box-shadow: 0 0 10px var(--warning-color); }
        .history-item-modern.status-approved::before { background: var(--success-color); box-shadow: 0 0 10px var(--success-color); }
        .history-item-modern.status-rejected::before { background: var(--danger-color); box-shadow: 0 0 10px var(--danger-color); }

        .req-header { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; }
        
        .req-dates-wrapper { display: flex; align-items: center; gap: 15px; }
        .req-calendar-icon {
            background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1);
            width: 48px; height: 48px; border-radius: 14px; 
            display: flex; align-items: center; justify-content: center; font-size: 1.5rem;
            box-shadow: inset 0 0 10px rgba(0,0,0,0.5);
        }
        .req-dates-info { display: flex; flex-direction: column; }
        .req-dates-label { font-size: 0.75rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 2px; font-weight: 600; }
        .req-dates-value { font-size: 1.1rem; font-weight: 800; color: #fff; letter-spacing: 0.02em;}
        .req-dates-separator { color: var(--primary-color); margin: 0 5px; font-weight: normal;}

        .req-badge {
            display: inline-flex; align-items: center; gap: 6px; padding: 6px 14px; border-radius: 30px; font-size: 0.8rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; box-shadow: 0 4px 10px rgba(0,0,0,0.2);
        }
        .badge-dot { width: 6px; height: 6px; border-radius: 50%; }
        
        .badge-pending { background: rgba(245, 158, 11, 0.1); color: #fbbf24; border: 1px solid rgba(245, 158, 11, 0.3); }
        .badge-pending .badge-dot { background: #fbbf24; box-shadow: 0 0 8px #fbbf24; animation: pulse-live 1.5s infinite; }
        
        .badge-approved { background: rgba(16, 185, 129, 0.1); color: #34d399; border: 1px solid rgba(16, 185, 129, 0.3); }
        .badge-approved .badge-dot { background: #34d399; box-shadow: 0 0 8px #34d399; }
        
        .badge-rejected { background: rgba(239, 68, 68, 0.1); color: #f87171; border: 1px solid rgba(239, 68, 68, 0.3); }
        .badge-rejected .badge-dot { background: #f87171; box-shadow: 0 0 8px #f87171; }

        @keyframes pulse-live { 0% { transform: scale(0.95); opacity: 0.8; } 50% { transform: scale(1.5); opacity: 1; } 100% { transform: scale(0.95); opacity: 0.8; } }

        .req-body {
            background: rgba(0, 0, 0, 0.25); border-radius: 14px; padding: 1.25rem;
            display: flex; flex-direction: column; gap: 1rem; border: 1px solid rgba(255,255,255,0.03);
        }
        
        .req-details-pill-wrapper { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 0.5rem; }
        .req-detail-pill {
            background: rgba(255, 255, 255, 0.05); border: 1px solid rgba(255, 255, 255, 0.1); color: #cbd5e1;
            padding: 5px 12px; border-radius: 8px; font-size: 0.85rem; font-weight: 600; display: inline-flex; align-items: center; gap: 6px;
        }

        .req-reason-block { display: flex; flex-direction: column; gap: 6px; }
        .reason-type {
            display: inline-flex; align-items: center; gap: 5px; font-size: 0.75rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; width: fit-content; padding: 4px 10px; border-radius: 8px; border: 1px solid transparent; background: rgba(255,255,255,0.05); color: #fff;
        }
        .reason-text { font-size: 0.95rem; color: #cbd5e1; line-height: 1.6; margin-left: 2px;}

        .req-footer {
            display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;
            padding-top: 1rem; border-top: 1px dashed rgba(255,255,255,0.1);
        }
        .req-meta-item { display: flex; align-items: center; gap: 6px; font-size: 0.85rem; color: var(--text-muted); font-weight: 500; }
        
        .reviewer-stamp {
            display: inline-flex; align-items: center; gap: 8px; background: rgba(255,255,255,0.05); padding: 4px 14px 4px 4px; border-radius: 30px; border: 1px solid rgba(255,255,255,0.1); font-size: 0.85rem; color: #fff; font-weight: 600;
        }
        .reviewer-avatar {
            width: 26px; height: 26px; border-radius: 50%; background: var(--primary-color);
            color: #000; display: flex; align-items: center; justify-content: center; font-size: 0.75rem; font-weight: 800;
        }

        /* --- Alerts --- */
        .error-alert { background: rgba(220, 53, 69, 0.15); border: 1px solid rgba(220, 53, 69, 0.4); color: #fca5a5; padding: 1rem 1.2rem; border-radius: 14px; margin-bottom: 1.5rem; display: flex; align-items: center; gap: 0.75rem; font-size: 0.95rem; font-weight: 500; animation: shake 0.5s ease-in-out; }
        .success-alert { background: rgba(52, 211, 153, 0.15); border: 1px solid rgba(52, 211, 153, 0.4); color: #6ee7b7; padding: 1rem 1.2rem; border-radius: 14px; margin-bottom: 1.5rem; display: flex; align-items: center; gap: 0.75rem; font-size: 0.95rem; font-weight: 500; animation: slideDown 0.4s ease-out; }

        @keyframes slideDown { from { opacity: 0; transform: translateY(-20px); } to { opacity: 1; transform: translateY(0); } }
        @keyframes slideUp { from { opacity: 0; transform: translateY(30px); } to { opacity: 1; transform: translateY(0); } }
        @keyframes shake { 0%, 100% { transform: translateX(0); } 25% { transform: translateX(-5px); } 75% { transform: translateX(5px); } }

        /* Grid Layout */
        .content-grid-modern {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 2rem;
            align-items: start;
        }
        
        /* Mobile Responsiveness */
        @media (max-width: 1024px) {
            .content-grid-modern { grid-template-columns: 1fr; gap: 1.5rem; }
        }
        
        @media (max-width: 768px) {
            .modern-page-header { padding: 1.5rem; }
            .header-content-wrapper { flex-direction: column; text-align: center; gap: 1rem; }
            .modern-page-header h1 { font-size: 1.8rem; }
            .header-icon-wrapper { width: 50px; height: 50px; font-size: 1.5rem; }
            .form-row-modern { grid-template-columns: 1fr; gap: 0; }
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
                        <h1>Input Jam Manual</h1>
                        <p>Ajukan permohonan input jam kerja ke dalam sistem secara manual.</p>
                    </div>
                </div>
            </div>

            <?php if (isset($success)): ?>
                <div class="success-alert"><span>🎉</span> <?= $success ?></div>
            <?php endif; ?>
            
            <?php if (isset($error)): ?>
                <div class="error-alert"><span>⚠️</span> <?= $error ?></div>
            <?php endif; ?>

            <div class="content-grid-modern">
                <div class="modern-card">
                    <div class="modern-card-header">
                        <h3>
                            <span>📋</span> 
                            Form Input Jam
                            <?= ($is_admin_or_manager && $employee_id_to_submit !== $user['id']) ? ' (' . $selected_employee_name . ')' : '' ?>
                        </h3>
                    </div>
                    <div class="form-inner-wrapper">
                        
                        <div class="modern-info-box">
                            <span class="icon">💡</span>
                            <div>
                                <strong>Informasi Penting:</strong> Input jam manual memerlukan persetujuan dari Direktur atau Wakil Direktur. Untuk shift malam (misal: 20:00 - 02:00), sistem akan otomatis menghitung durasi dengan benar.
                            </div>
                        </div>

                        <form method="POST" class="manual-duty-form" id="manual-duty-form">
                            <input type="hidden" name="action" value="submit_manual_duty">
                            <input type="hidden" name="employee_id" value="<?= $employee_id_to_submit ?>">
                            
                            <?php if ($is_admin_or_manager): ?>
                            <div class="modern-form-group">
                                <label for="employee_id_select">Untuk Anggota</label>
                                <div class="modern-input-wrapper">
                                    <span class="modern-input-icon">👤</span>
                                    <select name="employee_id_select" id="employee_id_select" class="modern-select" onchange="window.location.href='manual-duty.php?employee_id=' + this.value">
                                        <option value="<?= $user['id'] ?>" <?= ($employee_id_to_submit == $user['id']) ? 'selected' : '' ?>>-- Untuk Diri Sendiri --</option>
                                        <?php foreach ($all_employees as $emp): ?>
                                            <option value="<?= $emp['id'] ?>" <?= ($employee_id_to_submit == $emp['id']) ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($emp['name']) ?> (<?= getRoleDisplayName($emp['role']) ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <div class="modern-form-group">
                                <label for="duty_date">Tanggal Shift</label>
                                <div class="modern-input-wrapper">
                                    <span class="modern-input-icon">🗓️</span>
                                    <input type="date" name="duty_date" id="duty_date" class="modern-input" required>
                                </div>
                            </div>
                            
                            <div class="form-row-modern">
                                <div class="modern-form-group">
                                    <label for="start_time">Jam Mulai</label>
                                    <div class="modern-input-wrapper">
                                        <span class="modern-input-icon">🕒</span>
                                        <input type="time" name="start_time" id="start_time" class="modern-input" required>
                                    </div>
                                </div>
                                <div class="modern-form-group">
                                    <label for="end_time">Jam Selesai</label>
                                    <div class="modern-input-wrapper">
                                        <span class="modern-input-icon">🕒</span>
                                        <input type="time" name="end_time" id="end_time" class="modern-input" required>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="duration-preview-modern" id="duration-preview" style="display: none;">
                                <span class="icon">⌛</span>
                                <div>
                                    <strong>Estimasi Durasi:</strong> <span id="duration-text">-</span>
                                </div>
                            </div>
                            
                            <div class="modern-form-group">
                                <label for="reason">Alasan Input Manual</label>
                                <div class="modern-input-wrapper textarea-wrapper">
                                    <span class="modern-input-icon">📝</span>
                                    <textarea name="reason" id="reason" class="modern-textarea" placeholder="Jelaskan alasan mengapa perlu menginput jam secara manual..." required></textarea>
                                </div>
                            </div>
                            
                            <button type="submit" class="modern-btn-submit">
                                <span>Ajukan Permohonan</span> 🚀
                            </button>
                        </form>
                    </div>
                </div>

                <div class="modern-card">
                    <div class="modern-card-header">
                        <h3>
                            <span>🕰️</span> 
                            Riwayat Permohonan
                            <?= ($is_admin_or_manager && $employee_id_to_submit !== $user['id']) ? ' (' . $selected_employee_name . ')' : '' ?>
                        </h3>
                    </div>
                    <div class="card-content">
                        <?php if (empty($manual_requests)): ?>
                            <div class="no-data" style="color: var(--text-muted); font-style: italic; text-align: center; padding: 2rem 0; background: rgba(0,0,0,0.2); border-radius: 16px;">
                                <span style="font-size: 2rem; display: block; margin-bottom: 10px;">🍃</span>
                                Belum ada riwayat permohonan input jam manual.
                            </div>
                        <?php else: ?>
                            <div class="history-list">
                                <?php foreach ($manual_requests as $request): ?>
                                <?php 
                                    // Calculate duration for display
                                    $start_timestamp = strtotime($request['start_time']);
                                    $end_timestamp = strtotime($request['end_time']);
                                    if ($end_timestamp <= $start_timestamp) {
                                        $end_timestamp += 24 * 60 * 60;
                                    }
                                    $duration_minutes = ($end_timestamp - $start_timestamp) / 60;
                                    $duration_display = formatDuration($duration_minutes);

                                    $status_class = 'status-' . strtolower($request['status']); 
                                    $badge_class = 'badge-' . strtolower($request['status']);
                                    
                                    $status_text = [
                                        'pending' => 'Menunggu',
                                        'approved' => 'Disetujui', 
                                        'rejected' => 'Ditolak'
                                    ];
                                    $display_status = $status_text[$request['status']] ?? ucfirst($request['status']);
                                ?>
                                <div class="history-item-modern <?= $status_class ?>">
                                    
                                    <div class="req-header">
                                        <div class="req-dates-wrapper">
                                            <div class="req-calendar-icon">⏱️</div>
                                            <div class="req-dates-info">
                                                <span class="req-dates-label">Tanggal & Jam Shift</span>
                                                <div class="req-dates-value">
                                                    <?= date('d M Y', strtotime($request['duty_date'])) ?> 
                                                    <span class="req-dates-separator">|</span> 
                                                    <span style="font-size: 0.95rem; font-weight: normal; color: #cbd5e1;">
                                                        <?= date('H:i', strtotime($request['start_time'])) ?> - <?= date('H:i', strtotime($request['end_time'])) ?>
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                        <span class="req-badge <?= $badge_class ?>">
                                            <span class="badge-dot"></span>
                                            <?= $display_status ?>
                                        </span>
                                    </div>
                                    
                                    <div class="req-body">
                                        <div class="req-details-pill-wrapper">
                                            <span class="req-detail-pill" style="color: var(--success-color); border-color: rgba(16, 185, 129, 0.3);">
                                                ⏳ Total Durasi: <?= $duration_display ?>
                                            </span>
                                        </div>
                                        
                                        <hr style="border: none; border-top: 1px solid rgba(255,255,255,0.05); margin: 0.5rem 0;">

                                        <div class="req-reason-block">
                                            <span class="reason-type">📝 Keterangan</span>
                                            <div class="reason-text">"<?= nl2br(htmlspecialchars($request['reason'])) ?>"</div>
                                        </div>
                                    </div>
                                    
                                    <div class="req-footer">
                                        <div class="req-meta-item">
                                            <span>🕒</span> Diajukan: <?= date('d M Y, H:i', strtotime($request['created_at'])) ?>
                                        </div>
                                        
                                        <?php if ($request['approved_by_name']): ?>
                                            <div class="reviewer-stamp" title="<?= $request['status'] == 'approved' ? 'Disetujui' : 'Ditolak' ?> oleh <?= htmlspecialchars($request['approved_by_name']) ?>">
                                                <div class="reviewer-avatar">
                                                    <?= strtoupper(substr($request['approved_by_name'], 0, 1)) ?>
                                                </div>
                                                <?= htmlspecialchars($request['approved_by_name']) ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script src="script.js"></script>
    <script>
        // Real-time duration calculation
        function calculateDuration() {
            const startTime = document.getElementById('start_time').value;
            const endTime = document.getElementById('end_time').value;
            const preview = document.getElementById('duration-preview');
            const durationText = document.getElementById('duration-text');
            
            if (startTime && endTime) {
                const start = new Date('2000-01-01 ' + startTime);
                let end = new Date('2000-01-01 ' + endTime);
                
                // Handle overnight shift
                if (end <= start) {
                    end.setDate(end.getDate() + 1);
                }
                
                const diffMs = end - start;
                const diffMinutes = Math.floor(diffMs / (1000 * 60));
                
                if (diffMinutes > 1440) { // More than 24 hours
                    durationText.textContent = 'Durasi terlalu panjang (max 24 jam)';
                    durationText.style.color = '#fca5a5';
                    preview.style.backgroundColor = 'rgba(239, 68, 68, 0.1)';
                    preview.style.borderColor = 'rgba(239, 68, 68, 0.3)';
                } else if (diffMinutes < 15) { // Less than 15 minutes
                    durationText.textContent = 'Durasi terlalu pendek (min 15 menit)';
                    durationText.style.color = '#fca5a5';
                    preview.style.backgroundColor = 'rgba(239, 68, 68, 0.1)';
                    preview.style.borderColor = 'rgba(239, 68, 68, 0.3)';
                } else {
                    const hours = Math.floor(diffMinutes / 60);
                    const minutes = diffMinutes % 60;
                    durationText.textContent = hours + 'j ' + minutes + 'm';
                    durationText.style.color = '#6ee7b7';
                    preview.style.backgroundColor = 'rgba(16, 185, 129, 0.1)';
                    preview.style.borderColor = 'rgba(16, 185, 129, 0.3)';
                    
                    // Show overnight indicator
                    if (end.getDate() > start.getDate()) {
                        durationText.textContent += ' (Shift Malam)';
                    }
                }
                
                preview.style.display = 'flex';
            } else {
                preview.style.display = 'none';
            }
        }
        
        // Add event listeners
        document.addEventListener('DOMContentLoaded', function() {
            const startInput = document.getElementById('start_time');
            const endInput = document.getElementById('end_time');
            if (startInput && endInput) {
                startInput.addEventListener('change', calculateDuration);
                endInput.addEventListener('change', calculateDuration);
            }
        });
    </script>
</body>
</html>