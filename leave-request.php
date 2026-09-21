<?php
require_once 'config.php';

if (!isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$user = getCurrentUser();

// Handle form submission
if ($_POST['action'] ?? '' === 'submit_leave') {
    $start_date = $_POST['start_date'] ?? '';
    $end_date = $_POST['end_date'] ?? '';
    $reason_ooc = $_POST['reason_ooc'] ?? '';
    $reason_ic = $_POST['reason_ic'] ?? '';
    
    if ($start_date && $end_date) {
        $stmt = $conn->prepare("
            INSERT INTO leave_requests (employee_id, start_date, end_date, reason_ooc, reason_ic)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->bind_param("issss", $user['id'], $start_date, $end_date, $reason_ooc, $reason_ic);
        
        if ($stmt->execute()) {
            sendDiscordNotification([
                'employee_name' => $user['name'],
                'start_date' => $start_date,
                'end_date' => $end_date,
                'reason_ooc' => $reason_ooc,
                'reason_ic' => $reason_ic
            ], 'leave_request_submitted');
            $success = "Permohonan Izin berhasil diajukan!";
        } else {
            $error = "Gagal mengajukan permohonan Izin!";
        }
    } else {
        $error = "Tanggal mulai dan selesai harus diisi!";
    }
}

// Get user's leave requests
$stmt = $conn->prepare("
    SELECT lr.*, e.name as approved_by_name 
    FROM leave_requests lr
    LEFT JOIN employees e ON lr.approved_by = e.id
    WHERE lr.employee_id = ?
    ORDER BY lr.created_at DESC
");
$stmt->bind_param("i", $user['id']);
$stmt->execute();
$leave_requests = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Permohonan Izin - Warung Om Tante V2</title>
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

        /* --- Modern Page Header (FLEXBOX ALIGNMENT FIX) --- */
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

        /* Efek Glow di sudut header */
        .modern-page-header::after {
            content: '';
            position: absolute;
            top: -20%; right: -5%;
            width: 300px; height: 300px;
            background: radial-gradient(circle, rgba(255, 193, 7, 0.1), transparent 70%);
            pointer-events: none;
            z-index: 0;
        }

        /* Wrapper untuk menyejajarkan Ikon dengan Blok Teks */
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

        .modern-card:nth-child(2) {
            animation-delay: 0.2s;
        }

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

        /* --- Modern Form Elements --- */
        .modern-form-group {
            margin-bottom: 1.5rem;
        }
        .modern-form-group label {
            display: block;
            font-size: 0.9rem;
            font-weight: 600;
            color: var(--text-secondary);
            margin-bottom: 0.6rem;
        }
        
        .form-row-modern {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
        }

        .modern-input-wrapper {
            position: relative;
            display: flex;
            align-items: center;
        }
        .textarea-wrapper {
            align-items: flex-start;
        }
        
        .modern-input-icon {
            position: absolute;
            left: 1.25rem;
            font-size: 1.2rem;
            opacity: 0.6;
            pointer-events: none;
            z-index: 2;
        }
        .textarea-wrapper .modern-input-icon {
            top: 1.1rem;
        }
        
        .modern-input, .modern-textarea {
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
        
        input[type="date"].modern-input {
            padding-right: 1.5rem;
        }
        input[type="date"]::-webkit-calendar-picker-indicator {
            filter: invert(1);
            cursor: pointer;
            opacity: 0.6;
            transition: 0.2s;
        }
        input[type="date"]::-webkit-calendar-picker-indicator:hover {
            opacity: 1;
        }

        .modern-textarea {
            min-height: 120px;
            resize: vertical;
            line-height: 1.5;
        }

        .modern-input:focus, .modern-textarea:focus {
            outline: none;
            border-color: var(--primary-color);
            background: rgba(0, 0, 0, 0.6);
            box-shadow: 0 0 0 3px rgba(255, 193, 7, 0.15);
        }

        .modern-btn-submit {
            width: 100%;
            background: linear-gradient(135deg, var(--primary-color), var(--primary-hover));
            color: #121212;
            border: none;
            padding: 1.2rem;
            border-radius: 14px;
            font-size: 1.1rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.75rem;
            margin-top: 1rem;
            box-shadow: 0 8px 20px rgba(255, 193, 7, 0.25);
        }
        .modern-btn-submit:hover {
            transform: translateY(-3px);
            box-shadow: 0 12px 25px rgba(255, 193, 7, 0.4);
        }
        .modern-btn-submit:active { transform: translateY(0); }

        /* ========================================================
           HISTORY ITEMS UI
           ======================================================== */
        .history-list {
            display: flex;
            flex-direction: column;
            gap: 1.25rem;
        }

        .history-item-modern {
            background: linear-gradient(145deg, rgba(30, 41, 59, 0.4), rgba(15, 23, 42, 0.6));
            border: 1px solid rgba(255, 255, 255, 0.05);
            border-radius: 20px;
            padding: 1.5rem;
            transition: all 0.3s ease;
            position: relative;
            display: flex;
            flex-direction: column;
            gap: 1.2rem;
        }

        .history-item-modern:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.4);
            background: linear-gradient(145deg, rgba(30, 41, 59, 0.6), rgba(15, 23, 42, 0.8));
            border-color: rgba(255, 255, 255, 0.1);
        }

        .history-item-modern::before {
            content: '';
            position: absolute;
            left: -1px; top: 1.5rem; bottom: 1.5rem; width: 4px;
            border-radius: 0 4px 4px 0;
        }
        .history-item-modern.status-pending::before { background: var(--warning-color); box-shadow: 0 0 10px var(--warning-color); }
        .history-item-modern.status-approved::before { background: var(--success-color); box-shadow: 0 0 10px var(--success-color); }
        .history-item-modern.status-rejected::before { background: var(--danger-color); box-shadow: 0 0 10px var(--danger-color); }

        .req-header { 
            display: flex; justify-content: space-between; align-items: center; 
            flex-wrap: wrap; gap: 10px; 
        }
        
        .req-dates-wrapper { 
            display: flex; align-items: center; gap: 15px; 
        }
        .req-calendar-icon {
            background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1);
            width: 48px; height: 48px; border-radius: 14px; 
            display: flex; align-items: center; justify-content: center; font-size: 1.5rem;
            box-shadow: inset 0 0 10px rgba(0,0,0,0.5);
        }
        .req-dates-info { display: flex; flex-direction: column; }
        .req-dates-label { 
            font-size: 0.75rem; color: var(--text-muted); text-transform: uppercase; 
            letter-spacing: 0.05em; margin-bottom: 2px; font-weight: 600;
        }
        .req-dates-value { font-size: 1.1rem; font-weight: 800; color: #fff; letter-spacing: 0.02em;}
        .req-dates-separator { color: var(--primary-color); margin: 0 5px; font-weight: normal;}

        .req-badge {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 6px 14px; border-radius: 30px; font-size: 0.8rem; font-weight: 800; 
            text-transform: uppercase; letter-spacing: 0.05em; box-shadow: 0 4px 10px rgba(0,0,0,0.2);
        }
        .badge-dot { width: 6px; height: 6px; border-radius: 50%; }
        
        .badge-pending { background: rgba(245, 158, 11, 0.1); color: #fbbf24; border: 1px solid rgba(245, 158, 11, 0.3); }
        .badge-pending .badge-dot { background: #fbbf24; box-shadow: 0 0 8px #fbbf24; animation: pulse-live 1.5s infinite; }
        
        .badge-approved { background: rgba(16, 185, 129, 0.1); color: #34d399; border: 1px solid rgba(16, 185, 129, 0.3); }
        .badge-approved .badge-dot { background: #34d399; box-shadow: 0 0 8px #34d399; }
        
        .badge-rejected { background: rgba(239, 68, 68, 0.1); color: #f87171; border: 1px solid rgba(239, 68, 68, 0.3); }
        .badge-rejected .badge-dot { background: #f87171; box-shadow: 0 0 8px #f87171; }

        @keyframes pulse-live {
            0% { transform: scale(0.95); opacity: 0.8; }
            50% { transform: scale(1.5); opacity: 1; }
            100% { transform: scale(0.95); opacity: 0.8; }
        }

        .req-body {
            background: rgba(0, 0, 0, 0.25); border-radius: 14px; padding: 1.25rem;
            display: flex; flex-direction: column; gap: 1rem; border: 1px solid rgba(255,255,255,0.03);
        }
        .req-reason-block { display: flex; flex-direction: column; gap: 6px; }
        .reason-type {
            display: inline-flex; align-items: center; gap: 5px;
            font-size: 0.75rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em;
            width: fit-content; padding: 4px 10px; border-radius: 8px; border: 1px solid transparent;
        }
        .type-ooc { background: rgba(59, 130, 246, 0.1); color: #60a5fa; border-color: rgba(59, 130, 246, 0.2); }
        .type-ic { background: rgba(168, 85, 247, 0.1); color: #c084fc; border-color: rgba(168, 85, 247, 0.2); }
        .reason-text { font-size: 0.95rem; color: #cbd5e1; line-height: 1.6; margin-left: 2px;}

        .req-footer {
            display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;
            padding-top: 1rem; border-top: 1px dashed rgba(255,255,255,0.1);
        }
        .req-meta-item { display: flex; align-items: center; gap: 6px; font-size: 0.85rem; color: var(--text-muted); font-weight: 500; }
        
        .reviewer-stamp {
            display: inline-flex; align-items: center; gap: 8px;
            background: rgba(255,255,255,0.05); padding: 4px 14px 4px 4px; border-radius: 30px;
            border: 1px solid rgba(255,255,255,0.1); font-size: 0.85rem; color: #fff; font-weight: 600;
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
                    <div class="header-icon-wrapper">📝</div>
                    <div class="header-text-wrapper">
                        <h1>Pengajuan Izin / Cuti</h1>
                        <p>Ajukan permohonan ketidakhadiran untuk keperluan OOC maupun IC Anda di sini.</p>
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
                        <h3><span>📋</span> Form Permohonan Baru</h3>
                    </div>
                    <div class="form-inner-wrapper">
                        <form method="POST" class="leave-form">
                            <input type="hidden" name="action" value="submit_leave">
                            
                            <div class="form-row-modern">
                                <div class="modern-form-group">
                                    <label for="start_date">Tanggal Mulai</label>
                                    <div class="modern-input-wrapper">
                                        <span class="modern-input-icon">🗓️</span>
                                        <input type="date" name="start_date" id="start_date" class="modern-input" required>
                                    </div>
                                </div>
                                <div class="modern-form-group">
                                    <label for="end_date">Tanggal Selesai</label>
                                    <div class="modern-input-wrapper">
                                        <span class="modern-input-icon">🗓️</span>
                                        <input type="date" name="end_date" id="end_date" class="modern-input" required>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="modern-form-group">
                                <label for="reason_ooc">Alasan OOC (Dunia Nyata)</label>
                                <div class="modern-input-wrapper textarea-wrapper">
                                    <span class="modern-input-icon">🌍</span>
                                    <textarea name="reason_ooc" id="reason_ooc" class="modern-textarea" placeholder="Jelaskan alasan OOC untuk izin... (Opsional)"></textarea>
                                </div>
                            </div>
                            
                            <div class="modern-form-group">
                                <label for="reason_ic">Alasan IC (Dalam Kota)</label>
                                <div class="modern-input-wrapper textarea-wrapper">
                                    <span class="modern-input-icon">🏙️</span>
                                    <textarea name="reason_ic" id="reason_ic" class="modern-textarea" placeholder="Jelaskan alasan IC untuk izin... (Opsional)"></textarea>
                                </div>
                            </div>
                            
                            <button type="submit" class="modern-btn-submit">
                                <span>Kirim Permohonan</span> 🚀
                            </button>
                        </form>
                    </div>
                </div>

                <div class="modern-card">
                    <div class="modern-card-header">
                        <h3><span>🕰️</span> Riwayat Permohonan Saya</h3>
                    </div>
                    <div class="card-content">
                        <?php if (empty($leave_requests)): ?>
                            <div class="no-data" style="color: var(--text-muted); font-style: italic; text-align: center; padding: 2rem 0; background: rgba(0,0,0,0.2); border-radius: 16px;">
                                <span style="font-size: 2rem; display: block; margin-bottom: 10px;">🍃</span>
                                Belum ada riwayat permohonan izin.
                            </div>
                        <?php else: ?>
                            <div class="history-list">
                                <?php foreach ($leave_requests as $request): ?>
                                <?php 
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
                                            <div class="req-calendar-icon">📅</div>
                                            <div class="req-dates-info">
                                                <span class="req-dates-label">Periode Izin</span>
                                                <div class="req-dates-value">
                                                    <?= date('d M Y', strtotime($request['start_date'])) ?> 
                                                    <span class="req-dates-separator">→</span> 
                                                    <?= date('d M Y', strtotime($request['end_date'])) ?>
                                                </div>
                                            </div>
                                        </div>
                                        <span class="req-badge <?= $badge_class ?>">
                                            <span class="badge-dot"></span>
                                            <?= $display_status ?>
                                        </span>
                                    </div>
                                    
                                    <?php if (!empty($request['reason_ooc']) || !empty($request['reason_ic'])): ?>
                                    <div class="req-body">
                                        <?php if ($request['reason_ooc']): ?>
                                            <div class="req-reason-block">
                                                <span class="reason-type type-ooc">🌍 Out of Character</span>
                                                <div class="reason-text">"<?= nl2br(htmlspecialchars($request['reason_ooc'])) ?>"</div>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <?php if ($request['reason_ooc'] && $request['reason_ic']): ?>
                                            <hr style="border: none; border-top: 1px solid rgba(255,255,255,0.05); margin: 0.5rem 0;">
                                        <?php endif; ?>

                                        <?php if ($request['reason_ic']): ?>
                                            <div class="req-reason-block">
                                                <span class="reason-type type-ic">🏙️ In Character</span>
                                                <div class="reason-text">"<?= nl2br(htmlspecialchars($request['reason_ic'])) ?>"</div>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <?php endif; ?>
                                    
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
</body>
</html>