<?php
require_once 'config.php';

if (!isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$user = getCurrentUser();

// Ambil jumlah permohonan pending untuk indikator sidebar
$pending_requests_count = getPendingRequestCount();

// Ambil data surat peringatan milik user yang sedang login
// Menghubungkan ke tabel employees untuk mendapatkan nama yang memberikan SP
$warnings = [];
$stmt = $conn->prepare("
    SELECT w.*, e.name as issued_by_name 
    FROM warning_letters w
    LEFT JOIN employees e ON w.issued_by = e.id
    WHERE w.employee_id = ?
    ORDER BY w.issued_at DESC
");

if ($stmt) {
    $stmt->bind_param("i", $user['id']);
    $stmt->execute();
    $warnings = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Surat Peringatan Saya - Warung Om Tante V2</title>
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

        /* Efek Glow Kuning/Peringatan di sudut header */
        .modern-page-header::after {
            content: '';
            position: absolute;
            top: -20%; right: -5%;
            width: 300px; height: 300px;
            background: radial-gradient(circle, rgba(245, 158, 11, 0.15), transparent 70%);
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
            color: #fbbf24; /* Warna Peringatan */
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

        /* --- INFO BOX --- */
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
            margin-bottom: 2rem;
        }
        .modern-info-box span.icon { font-size: 1.5rem; flex-shrink: 0; }
        .modern-info-box strong { color: #bfdbfe; }

        /* ========================================================
           HISTORY ITEMS UI (DAFTAR SP)
           ======================================================== */
        .history-list {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
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

        /* Garis Aksen Kiri berdasarkan tipe SP */
        .history-item-modern::before {
            content: '';
            position: absolute;
            left: -1px; top: 1.5rem; bottom: 1.5rem; width: 4px;
            border-radius: 0 4px 4px 0;
        }
        .history-item-modern.type-sp1::before { background: #eab308; box-shadow: 0 0 10px #eab308; } /* Yellow */
        .history-item-modern.type-sp2::before { background: #f97316; box-shadow: 0 0 10px #f97316; } /* Orange */
        .history-item-modern.type-sp3::before { background: #ef4444; box-shadow: 0 0 10px #ef4444; } /* Red */

        /* --- Header History --- */
        .req-header { 
            display: flex; justify-content: space-between; align-items: center; 
            flex-wrap: wrap; gap: 10px; 
        }
        
        .req-dates-wrapper { 
            display: flex; align-items: center; gap: 15px; 
        }
        
        /* Ikon Dinamis berdasarkan Tipe SP */
        .req-icon {
            width: 48px; height: 48px; border-radius: 14px; 
            display: flex; align-items: center; justify-content: center; font-size: 1.5rem;
            box-shadow: inset 0 0 10px rgba(0,0,0,0.3);
        }
        .type-sp1 .req-icon { background: rgba(234, 179, 8, 0.1); border: 1px solid rgba(234, 179, 8, 0.3); color: #eab308; }
        .type-sp2 .req-icon { background: rgba(249, 115, 22, 0.1); border: 1px solid rgba(249, 115, 22, 0.3); color: #f97316; }
        .type-sp3 .req-icon { background: rgba(239, 68, 68, 0.1); border: 1px solid rgba(239, 68, 68, 0.3); color: #ef4444; }

        .req-dates-info { display: flex; flex-direction: column; }
        .req-dates-label { 
            font-size: 0.75rem; color: var(--text-muted); text-transform: uppercase; 
            letter-spacing: 0.05em; margin-bottom: 2px; font-weight: 600;
        }
        .req-dates-value { font-size: 1.1rem; font-weight: 800; color: #fff; letter-spacing: 0.02em;}

        /* --- Badge SP --- */
        .req-badge {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 6px 14px; border-radius: 30px; font-size: 0.8rem; font-weight: 800; 
            text-transform: uppercase; letter-spacing: 0.05em; box-shadow: 0 4px 10px rgba(0,0,0,0.2);
        }
        
        .badge-sp1 { background: rgba(234, 179, 8, 0.15); color: #fde047; border: 1px solid rgba(234, 179, 8, 0.4); }
        .badge-sp2 { background: rgba(249, 115, 22, 0.15); color: #fdba74; border: 1px solid rgba(249, 115, 22, 0.4); }
        .badge-sp3 { background: rgba(239, 68, 68, 0.15); color: #fca5a5; border: 1px solid rgba(239, 68, 68, 0.4); }

        /* --- Body History (Alasan) --- */
        .req-body {
            background: rgba(0, 0, 0, 0.25); border-radius: 14px; padding: 1.25rem;
            display: flex; flex-direction: column; gap: 1rem; border: 1px solid rgba(255,255,255,0.03);
        }
        
        .req-reason-block { display: flex; flex-direction: column; gap: 8px; }
        .reason-type {
            display: inline-flex; align-items: center; gap: 5px;
            font-size: 0.75rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em;
            width: fit-content; padding: 4px 10px; border-radius: 8px; border: 1px solid transparent;
            background: rgba(255,255,255,0.05); color: var(--text-secondary);
        }
        .reason-text { font-size: 0.95rem; color: #cbd5e1; line-height: 1.6; margin-left: 2px;}

        /* --- Footer History (Info Tambahan) --- */
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
            width: 26px; height: 26px; border-radius: 50%; background: var(--text-muted);
            color: #000; display: flex; align-items: center; justify-content: center; font-size: 0.75rem; font-weight: 800;
        }
        /* Penyesuaian warna avatar berdasar SP */
        .type-sp1 .reviewer-avatar { background: #fde047; }
        .type-sp2 .reviewer-avatar { background: #fdba74; }
        .type-sp3 .reviewer-avatar { background: #fca5a5; }

        @keyframes slideDown { from { opacity: 0; transform: translateY(-20px); } to { opacity: 1; transform: translateY(0); } }
        @keyframes slideUp { from { opacity: 0; transform: translateY(30px); } to { opacity: 1; transform: translateY(0); } }

        /* Responsive */
        @media (max-width: 768px) {
            .modern-page-header { padding: 1.5rem; }
            .header-content-wrapper { flex-direction: column; text-align: center; gap: 1rem; }
            .modern-page-header h1 { font-size: 1.8rem; }
            .header-icon-wrapper { width: 50px; height: 50px; font-size: 1.5rem; }
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
                    <div class="header-icon-wrapper">⚠️</div>
                    <div class="header-text-wrapper">
                        <h1>Surat Peringatan Saya</h1>
                        <p>Riwayat surat peringatan atau teguran indisipliner yang diberikan kepada Anda.</p>
                    </div>
                </div>
            </div>

            <div class="modern-card">
                <div class="modern-card-header">
                    <h3><span>📜</span> Daftar Riwayat SP</h3>
                </div>
                <div class="card-content">
                    
                    <div class="modern-info-box">
                        <span class="icon">💡</span>
                        <div>
                            <strong>Informasi:</strong> Akumulasi Surat Peringatan (SP) dapat memengaruhi penilaian kinerja dan status keanggotaan Anda di Warung Om Tante. Selalu patuhi peraturan kota (IC) maupun SOP manajemen (OOC).
                        </div>
                    </div>

                    <?php if (empty($warnings)): ?>
                        <div class="no-data" style="color: var(--success-color); text-align: center; padding: 3rem 0; background: rgba(16, 185, 129, 0.05); border-radius: 16px; border: 1px dashed rgba(16, 185, 129, 0.3);">
                            <span style="font-size: 3rem; display: block; margin-bottom: 10px;">🌟</span>
                            <strong style="font-size: 1.2rem;">Luar Biasa!</strong><br>
                            Anda tidak memiliki riwayat Surat Peringatan (SP). Pertahankan kinerja baik Anda!
                        </div>
                    <?php else: ?>
                        <div class="history-list">
                            <?php foreach ($warnings as $warning): ?>
                            <?php 
                                // Tentukan styling berdasarkan tipe SP (SP1, SP2, SP3)
                                $type_class = 'type-' . strtolower(str_replace(' ', '', $warning['sp_type'])); 
                                $badge_class = 'badge-' . strtolower(str_replace(' ', '', $warning['sp_type']));
                                
                                // Jika nama tipe tidak sesuai standar (misal bukan SP1, SP2, SP3), fallback ke SP1
                                if(!in_array($type_class, ['type-sp1', 'type-sp2', 'type-sp3'])) {
                                    $type_class = 'type-sp1';
                                    $badge_class = 'badge-sp1';
                                }

                                $issuer_name = $warning['issued_by_name'] ?? 'Manajemen';
                            ?>
                            <div class="history-item-modern <?= $type_class ?>">
                                
                                <div class="req-header">
                                    <div class="req-dates-wrapper">
                                        <div class="req-icon">
                                            <?= ($type_class === 'type-sp3') ? '⛔' : '⚠️' ?>
                                        </div>
                                        <div class="req-dates-info">
                                            <span class="req-dates-label">Tanggal Dikeluarkan</span>
                                            <div class="req-dates-value">
                                                <?= date('d F Y', strtotime($warning['issued_at'])) ?>
                                            </div>
                                        </div>
                                    </div>
                                    <span class="req-badge <?= $badge_class ?>">
                                        <?= htmlspecialchars($warning['sp_type']) ?>
                                    </span>
                                </div>
                                
                                <div class="req-body">
                                    <div class="req-reason-block">
                                        <span class="reason-type">📝 Keterangan / Alasan Pelanggaran</span>
                                        <div class="reason-text">
                                            "<?= nl2br(htmlspecialchars($warning['reason'] ?? 'Tidak ada keterangan spesifik.')) ?>"
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="req-footer">
                                    <div class="req-meta-item">
                                        <span>🕒</span> Dikeluarkan pada: <?= date('H:i WIB', strtotime($warning['issued_at'])) ?>
                                    </div>
                                    
                                    <div class="reviewer-stamp" title="Diberikan oleh <?= htmlspecialchars($issuer_name) ?>">
                                        <div class="reviewer-avatar">
                                            <?= strtoupper(substr($issuer_name, 0, 1)) ?>
                                        </div>
                                        Dikeluarkan oleh: <?= htmlspecialchars($issuer_name) ?>
                                    </div>
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
</body>
</html>