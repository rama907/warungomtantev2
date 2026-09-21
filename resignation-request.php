<?php
require_once 'config.php';

if (!isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$user = getCurrentUser();

// Handle form submission
if ($_POST['action'] ?? '' === 'submit_resignation') {
    $resignation_date = $_POST['resignation_date'] ?? '';
    $reason_ooc = $_POST['reason_ooc'] ?? '';
    $reason_ic = $_POST['reason_ic'] ?? '';
    $passport = $_POST['passport'] ?? '';
    $cid = $_POST['cid'] ?? '';
    
    if ($resignation_date && $passport && $cid) {
        $stmt = $conn->prepare("
            INSERT INTO resignation_requests (employee_id, start_date, end_date, reason_ooc, reason_ic, passport, cid)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        // Use the same date for both start_date and end_date for compatibility with existing database structure
        $stmt->bind_param("issssss", $user['id'], $resignation_date, $resignation_date, $reason_ooc, $reason_ic, $passport, $cid);
        
        if ($stmt->execute()) {
            sendDiscordNotification([
                'employee_name' => $user['name'],
                'resignation_date' => $resignation_date,
                'passport' => $passport,
                'cid' => $cid,
                'reason_ooc' => $reason_ooc,
                'reason_ic' => $reason_ic
            ], 'resignation_request_submitted');
            $success = "Permohonan resign berhasil diajukan!";
        } else {
            $error = "Gagal mengajukan permohonan resign!";
        }
    } else {
        $error = "Tanggal resign, passport, dan CID wajib diisi!";
    }
}

// Get user's resignation requests
$stmt = $conn->prepare("
    SELECT rr.*, e.name as approved_by_name 
    FROM resignation_requests rr
    LEFT JOIN employees e ON rr.approved_by = e.id
    WHERE rr.employee_id = ?
    ORDER BY rr.created_at DESC
");
$stmt->bind_param("i", $user['id']);
$stmt->execute();
$resignation_requests = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Permohonan Resign - Warung Om Tante V2</title>
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

        /* Efek Glow di sudut header (Warna Merah Halus untuk Resign) */
        .modern-page-header::after {
            content: '';
            position: absolute;
            top: -20%; right: -5%;
            width: 300px; height: 300px;
            background: radial-gradient(circle, rgba(239, 68, 68, 0.1), transparent 70%);
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
            color: #ef4444; /* Warna merah untuk ikon resign */
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
            border-color: #ef4444; /* Fokus warna merah untuk form resign */
            background: rgba(0, 0, 0, 0.6);
            box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.15);
        }

        .form-help-modern {
            display: block;
            margin-top: 0.5rem;
            font-size: 0.8rem;
            color: var(--text-muted);
            font-style: italic;
        }

        .modern-warning-box {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.3);
            border-radius: 12px;
            padding: 1.25rem;
            color: #fca5a5;
            font-size: 0.95rem;
            line-height: 1.5;
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 1.5rem;
        }
        .modern-warning-box span { font-size: 1.5rem; flex-shrink: 0; }

        .modern-btn-submit.btn-danger-modern {
            width: 100%;
            background: linear-gradient(135deg, #ef4444, #dc2626); /* Gradient merah */
            color: #fff;
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
            box-shadow: 0 8px 20px rgba(239, 68, 68, 0.25);
        }
        .modern-btn-submit.btn-danger-modern:hover {
            transform: translateY(-3px);
            box-shadow: 0 12px 25px rgba(239, 68, 68, 0.4);
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
            background: rgba(239, 68, 68, 0.1); border: 1px solid rgba(239, 68, 68, 0.2);
            width: 48px; height: 48px; border-radius: 14px; 
            display: flex; align-items: center; justify-content: center; font-size: 1.5rem;
            color: #ef4444;
            box-shadow: inset 0 0 10px rgba(0,0,0,0.3);
        }
        .req-dates-info { display: flex; flex-direction: column; }
        .req-dates-label { 
            font-size: 0.75rem; color: var(--text-muted); text-transform: uppercase; 
            letter-spacing: 0.05em; margin-bottom: 2px; font-weight: 600;
        }
        .req-dates-value { font-size: 1.1rem; font-weight: 800; color: #fff; letter-spacing: 0.02em;}

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
        
        .req-details-pill-wrapper {
            display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 0.5rem;
        }
        .req-detail-pill {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            color: #cbd5e1;
            padding: 5px 12px;
            border-radius: 8px;
            font-size: 0.85rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 6px;
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
            width: 26px; height: 26px; border-radius: 50%; background: #ef4444; /* Merah untuk admin aksi resign */
            color: #fff; display: flex; align-items: center; justify-content: center; font-size: 0.75rem; font-weight: 800;
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
                    <div class="header-icon-wrapper">📄</div>
                    <div class="header-text-wrapper">
                        <h1>Pengajuan Resign</h1>
                        <p>Ajukan permohonan pengunduran diri dari Warung Om Tante di sini.</p>
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
                        <form method="POST" class="resignation-form">
                            <input type="hidden" name="action" value="submit_resignation">
                            
                            <div class="modern-form-group">
                                <label for="resignation_date">Tanggal Resign</label>
                                <div class="modern-input-wrapper">
                                    <span class="modern-input-icon">🗓️</span>
                                    <input type="date" name="resignation_date" id="resignation_date" class="modern-input" required>
                                </div>
                                <small class="form-help-modern">Pilih tanggal efektif pengunduran diri Anda.</small>
                            </div>

                            <div class="form-row-modern">
                                <div class="modern-form-group">
                                    <label for="passport">Nomor Passport</label>
                                    <div class="modern-input-wrapper">
                                        <span class="modern-input-icon">🛂</span>
                                        <input type="text" name="passport" id="passport" class="modern-input" placeholder="Masukkan nomor passport" required>
                                    </div>
                                </div>
                                <div class="modern-form-group">
                                    <label for="cid">Nomor CID</label>
                                    <div class="modern-input-wrapper">
                                        <span class="modern-input-icon">🆔</span>
                                        <input type="text" name="cid" id="cid" class="modern-input" placeholder="Masukkan CID" required>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="modern-form-group">
                                <label for="reason_ooc">Alasan OOC (Dunia Nyata)</label>
                                <div class="modern-input-wrapper textarea-wrapper">
                                    <span class="modern-input-icon">🌍</span>
                                    <textarea name="reason_ooc" id="reason_ooc" class="modern-textarea" placeholder="Jelaskan alasan OOC untuk resign..."></textarea>
                                </div>
                            </div>
                            
                            <div class="modern-form-group">
                                <label for="reason_ic">Alasan IC (Dalam Kota)</label>
                                <div class="modern-input-wrapper textarea-wrapper">
                                    <span class="modern-input-icon">🏙️</span>
                                    <textarea name="reason_ic" id="reason_ic" class="modern-textarea" placeholder="Jelaskan alasan IC untuk resign..."></textarea>
                                </div>
                            </div>
                            
                            <div class="modern-warning-box">
                                <span>⚠️</span>
                                <div>
                                    <strong>Peringatan Penting:</strong> Permohonan resign yang telah disetujui tidak dapat dibatalkan.
                                </div>
                            </div>
                            
                            <button type="submit" class="modern-btn-submit btn-danger-modern">
                                <span>Ajukan Pengunduran Diri</span> 🚀
                            </button>
                        </form>
                    </div>
                </div>

                <div class="modern-card">
                    <div class="modern-card-header">
                        <h3><span>🕰️</span> Riwayat Permohonan Saya</h3>
                    </div>
                    <div class="card-content">
                        <?php if (empty($resignation_requests)): ?>
                            <div class="no-data" style="color: var(--text-muted); font-style: italic; text-align: center; padding: 2rem 0; background: rgba(0,0,0,0.2); border-radius: 16px;">
                                <span style="font-size: 2rem; display: block; margin-bottom: 10px;">🍃</span>
                                Belum ada riwayat permohonan resign.
                            </div>
                        <?php else: ?>
                            <div class="history-list">
                                <?php foreach ($resignation_requests as $request): ?>
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
                                                <span class="req-dates-label">Tanggal Resign</span>
                                                <div class="req-dates-value">
                                                    <?= date('d M Y', strtotime($request['start_date'])) ?>
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
                                            <span class="req-detail-pill">🛂 Passport: <?= htmlspecialchars($request['passport']) ?></span>
                                            <span class="req-detail-pill">🆔 CID: <?= htmlspecialchars($request['cid']) ?></span>
                                        </div>
                                        
                                        <hr style="border: none; border-top: 1px solid rgba(255,255,255,0.05); margin: 0.5rem 0;">

                                        <?php if ($request['reason_ooc']): ?>
                                            <div class="req-reason-block">
                                                <span class="reason-type type-ooc">🌍 Out of Character</span>
                                                <div class="reason-text">"<?= nl2br(htmlspecialchars($request['reason_ooc'])) ?>"</div>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <?php if ($request['reason_ooc'] && $request['reason_ic']): ?>
                                            <hr style="border: none; border-top: 1px dashed rgba(255,255,255,0.05); margin: 0.5rem 0;">
                                        <?php endif; ?>

                                        <?php if ($request['reason_ic']): ?>
                                            <div class="req-reason-block">
                                                <span class="reason-type type-ic">🏙️ In Character</span>
                                                <div class="reason-text">"<?= nl2br(htmlspecialchars($request['reason_ic'])) ?>"</div>
                                            </div>
                                        <?php endif; ?>
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
</body>
</html>