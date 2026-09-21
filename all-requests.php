<?php
require_once 'config.php';

if (!isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$user = getCurrentUser();
$pending_requests_count = getPendingRequestCount(); 

if (!function_exists('formatDuration')) {
    function formatDuration($minutes) {
        if ($minutes < 0) return "0j 0m";
        $hours = floor($minutes / 60);
        $mins = $minutes % 60;
        return "{$hours}j {$mins}m";
    }
}

// 1. Fetch Leave Requests
$stmt_leave = $conn->prepare("
    SELECT lr.id, lr.employee_id, lr.start_date, lr.end_date, lr.reason_ooc, lr.reason_ic, NULL as passport, NULL as cid, NULL as start_time, NULL as end_time, NULL as reason, lr.status, lr.created_at, e.name as employee_name, a.name as approved_by_name 
    FROM leave_requests lr 
    JOIN employees e ON lr.employee_id = e.id 
    LEFT JOIN employees a ON lr.approved_by = a.id 
    ORDER BY lr.created_at DESC LIMIT 100
");
$stmt_leave->execute();
$leaves = $stmt_leave->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt_leave->close();

// 2. Fetch Resignation Requests
$stmt_resign = $conn->prepare("
    SELECT rr.id, rr.employee_id, rr.start_date, rr.end_date, rr.reason_ooc, rr.reason_ic, rr.passport, rr.cid, NULL as start_time, NULL as end_time, NULL as reason, rr.status, rr.created_at, e.name as employee_name, a.name as approved_by_name 
    FROM resignation_requests rr 
    JOIN employees e ON rr.employee_id = e.id 
    LEFT JOIN employees a ON rr.approved_by = a.id 
    ORDER BY rr.created_at DESC LIMIT 100
");
$stmt_resign->execute();
$resigns = $stmt_resign->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt_resign->close();

// 3. Fetch Manual Duty Requests
$stmt_manual = $conn->prepare("
    SELECT mr.id, mr.employee_id, mr.duty_date as start_date, mr.duty_date as end_date, NULL as reason_ooc, NULL as reason_ic, NULL as passport, NULL as cid, mr.start_time, mr.end_time, mr.reason, mr.status, mr.created_at, e.name as employee_name, a.name as approved_by_name 
    FROM manual_duty_requests mr 
    JOIN employees e ON mr.employee_id = e.id 
    LEFT JOIN employees a ON mr.approved_by = a.id 
    ORDER BY mr.created_at DESC LIMIT 100
");
$stmt_manual->execute();
$manuals = $stmt_manual->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt_manual->close();

// Gabungkan semua request
$all_requests = [];
foreach ($leaves as $l) { $l['req_type'] = 'leave'; $all_requests[] = $l; }
foreach ($resigns as $r) { $r['req_type'] = 'resign'; $all_requests[] = $r; }
foreach ($manuals as $m) { $m['req_type'] = 'manual'; $all_requests[] = $m; }

// Urutkan berdasarkan waktu pembuatan terbaru
usort($all_requests, function($a, $b) {
    return strtotime($b['created_at']) - strtotime($a['created_at']);
});

?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Semua Permohonan - Warung Om Tante V2</title>
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
            background: radial-gradient(circle, rgba(168, 85, 247, 0.15), transparent 70%); z-index: 0;
        }
        .header-content-wrapper { display: flex; align-items: center; gap: 1.25rem; z-index: 2; position: relative; }
        .header-icon-wrapper {
            background: rgba(255, 255, 255, 0.05); border: 1px solid rgba(255, 255, 255, 0.1);
            width: 60px; height: 60px; border-radius: 16px; display: flex; align-items: center;
            justify-content: center; font-size: 1.8rem; box-shadow: inset 0 0 15px rgba(0,0,0,0.3); flex-shrink: 0; color: #c084fc;
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

        /* --- Toolbar Filter & Search --- */
        .modern-toolbar {
            display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem; margin-bottom: 2rem;
        }
        .modern-search-wrapper {
            position: relative; flex-grow: 1; max-width: 350px;
        }
        .modern-search-icon {
            position: absolute; left: 1rem; top: 50%; transform: translateY(-50%); opacity: 0.6;
        }
        .modern-search-input {
            width: 100%; background: rgba(0, 0, 0, 0.3); border: 1px solid rgba(255, 255, 255, 0.1);
            color: #fff; padding: 0.8rem 1rem 0.8rem 2.5rem; border-radius: 14px; font-size: 0.95rem; transition: 0.3s;
        }
        .modern-search-input:focus {
            outline: none; border-color: var(--primary-color); background: rgba(0, 0, 0, 0.5); box-shadow: 0 0 0 3px rgba(255, 193, 7, 0.15);
        }

        .modern-tabs {
            display: flex; gap: 10px; overflow-x: auto; padding-bottom: 5px; scrollbar-width: none;
        }
        .modern-tabs::-webkit-scrollbar { display: none; }
        .modern-tab-btn {
            background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); color: #94a3b8;
            padding: 8px 18px; border-radius: 20px; font-weight: 600; cursor: pointer; transition: all 0.3s; white-space: nowrap; font-size: 0.9rem;
        }
        .modern-tab-btn:hover { background: rgba(255,255,255,0.1); color: #fff; }
        .modern-tab-btn.active {
            background: rgba(59, 130, 246, 0.15); color: #60a5fa; border-color: rgba(59, 130, 246, 0.4); box-shadow: 0 0 10px rgba(59, 130, 246, 0.2);
        }

        /* ========================================================
           HISTORY ITEMS UI (DAFTAR PERMOHONAN)
           ======================================================== */
        .history-list { display: flex; flex-direction: column; gap: 1.25rem; }

        .history-item-modern {
            background: linear-gradient(145deg, rgba(30, 41, 59, 0.4), rgba(15, 23, 42, 0.6));
            border: 1px solid rgba(255, 255, 255, 0.05); border-radius: 20px; padding: 1.5rem;
            transition: all 0.3s ease; position: relative; display: flex; flex-direction: column; gap: 1.2rem;
        }

        .history-item-modern:hover {
            transform: translateY(-3px); box-shadow: 0 10px 25px rgba(0,0,0,0.4);
            background: linear-gradient(145deg, rgba(30, 41, 59, 0.6), rgba(15, 23, 42, 0.8)); border-color: rgba(255, 255, 255, 0.1);
        }

        /* Garis Kiri Status */
        .history-item-modern::before {
            content: ''; position: absolute; left: -1px; top: 1.5rem; bottom: 1.5rem; width: 4px; border-radius: 0 4px 4px 0;
        }
        .status-pending::before { background: var(--warning-color); box-shadow: 0 0 10px var(--warning-color); }
        .status-approved::before { background: var(--success-color); box-shadow: 0 0 10px var(--success-color); }
        .status-rejected::before { background: var(--danger-color); box-shadow: 0 0 10px var(--danger-color); }

        .req-header { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; }
        
        .req-dates-wrapper { display: flex; align-items: center; gap: 15px; }
        
        /* Ikon Type */
        .req-icon {
            width: 48px; height: 48px; border-radius: 14px; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; box-shadow: inset 0 0 10px rgba(0,0,0,0.3);
        }
        .icon-leave { background: rgba(16, 185, 129, 0.1); border: 1px solid rgba(16, 185, 129, 0.2); color: #34d399; }
        .icon-resign { background: rgba(239, 68, 68, 0.1); border: 1px solid rgba(239, 68, 68, 0.2); color: #ef4444; }
        .icon-manual { background: rgba(59, 130, 246, 0.1); border: 1px solid rgba(59, 130, 246, 0.2); color: #60a5fa; }

        .req-dates-info { display: flex; flex-direction: column; }
        .req-dates-label { font-size: 0.75rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 2px; font-weight: 600; display: flex; align-items: center; gap: 5px;}
        .req-dates-label b { color: #fff; }
        .req-dates-value { font-size: 1.1rem; font-weight: 800; color: #fff; letter-spacing: 0.02em;}

        /* Badge Status */
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

        /* Body Details */
        .req-body {
            background: rgba(0, 0, 0, 0.25); border-radius: 14px; padding: 1.25rem; display: flex; flex-direction: column; gap: 1rem; border: 1px solid rgba(255,255,255,0.03);
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
        .type-ooc { background: rgba(59, 130, 246, 0.1); color: #60a5fa; border-color: rgba(59, 130, 246, 0.2); }
        .type-ic { background: rgba(168, 85, 247, 0.1); color: #c084fc; border-color: rgba(168, 85, 247, 0.2); }
        .reason-text { font-size: 0.95rem; color: #cbd5e1; line-height: 1.6; margin-left: 2px;}

        /* Footer */
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

        @keyframes slideDown { from { opacity: 0; transform: translateY(-20px); } to { opacity: 1; transform: translateY(0); } }
        @keyframes slideUp { from { opacity: 0; transform: translateY(30px); } to { opacity: 1; transform: translateY(0); } }

        @media (max-width: 768px) {
            .modern-page-header { padding: 1.5rem; text-align: center; }
            .header-content-wrapper { flex-direction: column; }
            .header-text-wrapper h1 { font-size: 1.8rem; }
            .modern-toolbar { flex-direction: column-reverse; align-items: stretch;}
            .modern-search-wrapper { max-width: 100%; }
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
                    <div class="header-icon-wrapper">📋</div>
                    <div class="header-text-wrapper">
                        <h1>Semua Permohonan</h1>
                        <p>Daftar seluruh riwayat permohonan publik dari anggota (Izin/Cuti, Resign, Input Jam).</p>
                    </div>
                </div>
            </div>

            <div class="modern-card">
                
                <div class="modern-toolbar">
                    <div class="modern-tabs">
                        <button class="modern-tab-btn active" onclick="filterRequests('all', this)">Semua</button>
                        <button class="modern-tab-btn" onclick="filterRequests('leave', this)">Izin / Cuti</button>
                        <button class="modern-tab-btn" onclick="filterRequests('resign', this)">Resign</button>
                        <button class="modern-tab-btn" onclick="filterRequests('manual', this)">Input Jam Manual</button>
                    </div>
                    
                    <div class="modern-search-wrapper">
                        <span class="modern-search-icon">🔍</span>
                        <input type="text" id="searchInput" class="modern-search-input" placeholder="Cari nama atau keterangan..." onkeyup="searchRequests()">
                    </div>
                </div>

                <div class="card-content">
                    <?php if (empty($all_requests)): ?>
                        <div class="no-data" style="color: var(--text-muted); font-style: italic; text-align: center; padding: 3rem 0; background: rgba(0,0,0,0.2); border-radius: 16px;">
                            <span style="font-size: 3rem; display: block; margin-bottom: 10px;">🍃</span>
                            Belum ada riwayat permohonan apapun.
                        </div>
                    <?php else: ?>
                        <div class="history-list" id="requestsContainer">
                            <?php foreach ($all_requests as $req): ?>
                            <?php 
                                $status_class = 'status-' . strtolower($req['status']); 
                                $badge_class = 'badge-' . strtolower($req['status']);
                                $status_text = ['pending' => 'Menunggu', 'approved' => 'Disetujui', 'rejected' => 'Ditolak'][$req['status']] ?? ucfirst($req['status']);
                                
                                $emp_name = htmlspecialchars($req['employee_name']);
                                $type_class = 'req-item-' . $req['req_type'];

                                // Variabel Dinamis Berdasarkan Tipe
                                if ($req['req_type'] === 'leave') {
                                    $icon = '📅'; $icon_class = 'icon-leave'; $type_label = 'Izin / Cuti';
                                    $dates_val = date('d M Y', strtotime($req['start_date'])) . ' <span style="color:var(--primary-color)">→</span> ' . date('d M Y', strtotime($req['end_date']));
                                } elseif ($req['req_type'] === 'resign') {
                                    $icon = '📄'; $icon_class = 'icon-resign'; $type_label = 'Pengajuan Resign';
                                    $dates_val = 'Efektif: ' . date('d M Y', strtotime($req['start_date']));
                                } else { // manual duty
                                    $icon = '⏱️'; $icon_class = 'icon-manual'; $type_label = 'Input Jam Manual';
                                    $dates_val = date('d M Y', strtotime($req['start_date'])) . ' | <span style="font-weight:normal;color:#cbd5e1;">' . date('H:i', strtotime($req['start_time'])) . ' - ' . date('H:i', strtotime($req['end_time'])) . '</span>';
                                    
                                    // Hitung Durasi Manual
                                    $s = strtotime($req['start_time']); $e = strtotime($req['end_time']);
                                    if ($e <= $s) $e += 86400;
                                    $dur_mins = ($e - $s) / 60;
                                    $dur_display = formatDuration($dur_mins);
                                }
                            ?>
                            <div class="history-item-modern req-item <?= $type_class ?> <?= $status_class ?>">
                                
                                <div class="req-header">
                                    <div class="req-dates-wrapper">
                                        <div class="req-icon <?= $icon_class ?>"><?= $icon ?></div>
                                        <div class="req-dates-info">
                                            <span class="req-dates-label">
                                                <?= $type_label ?> • <b style="margin-left:5px;"><?= $emp_name ?></b>
                                            </span>
                                            <div class="req-dates-value">
                                                <?= $dates_val ?>
                                            </div>
                                        </div>
                                    </div>
                                    <span class="req-badge <?= $badge_class ?>">
                                        <span class="badge-dot"></span>
                                        <?= $status_text ?>
                                    </span>
                                </div>
                                
                                <div class="req-body">
                                    <?php if ($req['req_type'] === 'resign'): ?>
                                        <div class="req-details-pill-wrapper">
                                            <span class="req-detail-pill">🛂 Passport: <?= htmlspecialchars($req['passport']) ?></span>
                                            <span class="req-detail-pill">🆔 CID: <?= htmlspecialchars($req['cid']) ?></span>
                                        </div>
                                        <hr style="border: none; border-top: 1px solid rgba(255,255,255,0.05); margin: 0.5rem 0;">
                                    <?php endif; ?>

                                    <?php if ($req['req_type'] === 'manual'): ?>
                                        <div class="req-details-pill-wrapper">
                                            <span class="req-detail-pill" style="color: #60a5fa; border-color: rgba(59, 130, 246, 0.3);">
                                                ⏳ Total Durasi: <?= $dur_display ?>
                                            </span>
                                        </div>
                                        <hr style="border: none; border-top: 1px solid rgba(255,255,255,0.05); margin: 0.5rem 0;">
                                        <div class="req-reason-block">
                                            <span class="reason-type">📝 Alasan Input</span>
                                            <div class="reason-text">"<?= nl2br(htmlspecialchars($req['reason'])) ?>"</div>
                                        </div>
                                    <?php endif; ?>

                                    <?php if ($req['req_type'] !== 'manual'): ?>
                                        <?php if (!empty($req['reason_ooc'])): ?>
                                            <div class="req-reason-block">
                                                <span class="reason-type type-ooc">🌍 Out of Character</span>
                                                <div class="reason-text">"<?= nl2br(htmlspecialchars($req['reason_ooc'])) ?>"</div>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <?php if (!empty($req['reason_ooc']) && !empty($req['reason_ic'])): ?>
                                            <hr style="border: none; border-top: 1px dashed rgba(255,255,255,0.05); margin: 0.2rem 0;">
                                        <?php endif; ?>

                                        <?php if (!empty($req['reason_ic'])): ?>
                                            <div class="req-reason-block">
                                                <span class="reason-type type-ic">🏙️ In Character</span>
                                                <div class="reason-text">"<?= nl2br(htmlspecialchars($req['reason_ic'])) ?>"</div>
                                            </div>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="req-footer">
                                    <div class="req-meta-item">
                                        <span>🕒</span> Diajukan: <?= date('d M Y, H:i', strtotime($req['created_at'])) ?>
                                    </div>
                                    
                                    <?php if ($req['approved_by_name']): ?>
                                        <div class="reviewer-stamp" title="<?= $req['status'] == 'approved' ? 'Disetujui' : 'Ditolak' ?> oleh <?= htmlspecialchars($req['approved_by_name']) ?>">
                                            <div class="reviewer-avatar">
                                                <?= strtoupper(substr($req['approved_by_name'], 0, 1)) ?>
                                            </div>
                                            <?= htmlspecialchars($req['approved_by_name']) ?>
                                        </div>
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
        // === Logika Tab & Pencarian ===
        let currentFilterType = 'all';

        function filterRequests(type, btn) {
            currentFilterType = type;
            
            // Ubah state active pada tombol
            document.querySelectorAll('.modern-tab-btn').forEach(t => t.classList.remove('active'));
            btn.classList.add('active');
            
            applyFilters();
        }

        function searchRequests() {
            applyFilters();
        }

        function applyFilters() {
            const query = document.getElementById('searchInput').value.toLowerCase();
            const items = document.querySelectorAll('.req-item');

            items.forEach(item => {
                // Teks yang akan dicari (nama, alasan, dll ada di innerText)
                const text = item.innerText.toLowerCase();
                
                // Cek apakah item sesuai dengan Tab Filter yang dipilih
                const matchesType = (currentFilterType === 'all' || item.classList.contains('req-item-' + currentFilterType));
                
                // Cek apakah item sesuai dengan teks pencarian
                const matchesSearch = text.includes(query);

                if (matchesType && matchesSearch) {
                    item.style.display = 'flex'; // kembalikan ke flex box
                } else {
                    item.style.display = 'none';
                }
            });
        }
    </script>
</body>
</html>