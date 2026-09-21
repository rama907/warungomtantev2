<?php
require_once 'config.php';

// Cek hanya apakah pengguna sudah login, tanpa memeriksa peran.
if (!isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$user = getCurrentUser(); // Untuk mendapatkan data pengguna yang sedang login

// Ambil jumlah permohonan pending untuk indikator sidebar
$pending_requests_count = getPendingRequestCount();

// Mengambil semua surat peringatan dari database (MENGGUNAKAN LOGIKA ASLI GITHUB)
$warning_letters = [];
$stmt = $conn->query("
    SELECT 
        wl.*,
        employee.name as employee_name,
        employee.role as employee_role,
        issued_by.name as issued_by_name
    FROM warning_letters wl
    JOIN employees employee ON wl.employee_id = employee.id
    LEFT JOIN employees issued_by ON wl.issued_by_employee_id = issued_by.id
    ORDER BY wl.issued_at DESC
");

if ($stmt) {
    $warning_letters = $stmt->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// Menghitung statistik untuk ringkasan (MENGGUNAKAN LOGIKA ASLI GITHUB)
$sp1_count = 0;
$sp2_count = 0;
$sp3_count = 0;
$phk_count = 0;

foreach ($warning_letters as $warning) {
    switch ($warning['sp_type']) {
        case 'SP1':
            $sp1_count++;
            break;
        case 'SP2':
            $sp2_count++;
            break;
        case 'SP3':
            $sp3_count++;
            break;
        case 'PHK':
            $phk_count++;
            break;
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Semua Surat Peringatan - Warung Om Tante V2</title>
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
            background: radial-gradient(circle, rgba(239, 68, 68, 0.15), transparent 70%); pointer-events: none; z-index: 0;
        }
        
        .header-content-wrapper { display: flex; align-items: center; gap: 1.25rem; z-index: 2; position: relative; }
        .header-icon-wrapper {
            background: rgba(255, 255, 255, 0.05); border: 1px solid rgba(255, 255, 255, 0.1);
            width: 60px; height: 60px; border-radius: 16px; display: flex; align-items: center;
            justify-content: center; font-size: 1.8rem; box-shadow: inset 0 0 15px rgba(0,0,0,0.3); flex-shrink: 0; color: #fbbf24;
        }
        .header-text-wrapper h1 { color: #fff; font-weight: 800; font-size: 2.2rem; letter-spacing: -0.02em; margin: 0; }
        .header-text-wrapper p { color: var(--text-secondary); margin: 0.25rem 0 0 0; font-size: 1.05rem; }

        /* --- Stats Grid (Kartu Statistik SP) --- */
        .stats-grid-modern {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .stat-card-modern {
            background: rgba(30, 41, 59, 0.4) !important;
            backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.05);
            border-radius: 16px;
            padding: 1.5rem;
            text-align: center;
            box-shadow: inset 0 0 15px rgba(0,0,0,0.2), 0 5px 15px rgba(0,0,0,0.2);
            animation: slideUp 0.6s ease-out 0.1s backwards;
            transition: all 0.3s;
        }
        .stat-card-modern:hover { transform: translateY(-3px); border-color: rgba(255,255,255,0.15); background: rgba(30, 41, 59, 0.6) !important; }

        .stat-card-modern h4 { font-size: 0.9rem; color: #cbd5e1; text-transform: uppercase; margin-bottom: 0.5rem; font-weight: 700; letter-spacing: 1px;}
        .stat-card-modern .stat-val { font-size: 2.5rem; font-weight: 800; }
        
        .val-sp1 { color: #eab308; text-shadow: 0 0 15px rgba(234, 179, 8, 0.3); }
        .val-sp2 { color: #f97316; text-shadow: 0 0 15px rgba(249, 115, 22, 0.3); }
        .val-sp3 { color: #ef4444; text-shadow: 0 0 15px rgba(239, 68, 68, 0.3); }
        .val-phk { color: #991b1b; text-shadow: 0 0 15px rgba(153, 27, 27, 0.3); }

        /* --- Cards (Glassmorphism Utama) --- */
        .modern-card {
            background: rgba(30, 41, 59, 0.6) !important; backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.08) !important; border-radius: 24px;
            box-shadow: 0 15px 35px rgba(0,0,0,0.3) !important; padding: 2rem;
            animation: slideUp 0.6s ease-out 0.2s backwards; margin-bottom: 2rem;
        }
        .modern-card-header {
            display: flex; justify-content: space-between; align-items: center; 
            margin-bottom: 1.5rem; border-bottom: 1px solid rgba(255, 255, 255, 0.1); padding-bottom: 1rem;
            flex-wrap: wrap; gap: 15px;
        }
        .modern-card-header h3 { color: #fff; font-size: 1.25rem; font-weight: 700; margin: 0;}

        /* --- Toolbar Filter & Search --- */
        .modern-toolbar { display: flex; align-items: center; gap: 1rem; flex-wrap: wrap; }
        .modern-search-wrapper { position: relative; min-width: 250px; flex-grow: 1;}
        .modern-search-icon { position: absolute; left: 1rem; top: 50%; transform: translateY(-50%); opacity: 0.6; }
        .modern-search-input {
            width: 100%; background: rgba(0, 0, 0, 0.3); border: 1px solid rgba(255, 255, 255, 0.1);
            color: #fff; padding: 0.6rem 1rem 0.6rem 2.5rem; border-radius: 12px; font-size: 0.9rem; transition: 0.3s;
        }
        .modern-search-input:focus { outline: none; border-color: #fbbf24; background: rgba(0, 0, 0, 0.5); }

        .modern-tabs { display: flex; gap: 8px; overflow-x: auto; scrollbar-width: none; }
        .modern-tabs::-webkit-scrollbar { display: none; }
        .modern-tab-btn {
            background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); color: #94a3b8;
            padding: 6px 14px; border-radius: 20px; font-weight: 600; cursor: pointer; transition: all 0.3s; font-size: 0.85rem; white-space: nowrap;
        }
        .modern-tab-btn:hover { background: rgba(255,255,255,0.1); color: #fff; }
        .modern-tab-btn.active { background: rgba(255, 255, 255, 0.15); color: #fff; border-color: rgba(255, 255, 255, 0.3); }

        /* ========================================================
           HISTORY ITEMS UI (TIKET DAFTAR SP)
           ======================================================== */
        .history-list { display: flex; flex-direction: column; gap: 1.5rem; }

        .history-item-modern {
            background: rgba(0, 0, 0, 0.25);
            border: 1px solid rgba(255, 255, 255, 0.05); border-radius: 16px; padding: 1.5rem;
            transition: all 0.3s ease; position: relative; display: flex; flex-direction: column; gap: 1rem;
        }
        .history-item-modern:hover {
            transform: translateY(-2px); box-shadow: 0 10px 25px rgba(0,0,0,0.3); border-color: rgba(255, 255, 255, 0.1);
            background: rgba(0, 0, 0, 0.4);
        }

        /* Garis Aksen Kiri berdasarkan tipe SP */
        .history-item-modern::before {
            content: ''; position: absolute; left: -1px; top: 1.5rem; bottom: 1.5rem; width: 4px; border-radius: 0 4px 4px 0;
        }
        .type-sp1::before { background: #eab308; box-shadow: 0 0 10px #eab308; } 
        .type-sp2::before { background: #f97316; box-shadow: 0 0 10px #f97316; } 
        .type-sp3::before { background: #ef4444; box-shadow: 0 0 10px #ef4444; } 
        .type-phk::before { background: #991b1b; box-shadow: 0 0 10px #991b1b; } 

        /* Top Row: User Profile & Badge */
        .req-user-row { display: flex; justify-content: space-between; align-items: flex-start; }
        .user-profile-mini { display: flex; align-items: center; gap: 12px; }
        .user-avatar-sp {
            width: 40px; height: 40px; border-radius: 50%; background: #fbbf24; color: #1e293b;
            display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 1.2rem;
        }
        .user-info-text { display: flex; flex-direction: column; gap: 4px; }
        .user-info-text .name { font-size: 1.1rem; font-weight: 700; color: #fff; }
        .user-info-text .role-badge {
            background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); color: #cbd5e1;
            font-size: 0.7rem; font-weight: 800; padding: 3px 8px; border-radius: 6px; text-transform: uppercase; letter-spacing: 0.05em; width: fit-content;
        }

        /* Badge Status (SP1, SP2, dll) */
        .sp-badge {
            padding: 6px 16px; border-radius: 8px; font-size: 0.85rem; font-weight: 800; 
            text-transform: uppercase; letter-spacing: 0.05em; box-shadow: 0 4px 10px rgba(0,0,0,0.2);
        }
        .badge-sp1 { background: #fde047; color: #854d0e; }
        .badge-sp2 { background: #fdba74; color: #9a3412; }
        .badge-sp3 { background: #fca5a5; color: #991b1b; }
        .badge-phk { background: #991b1b; color: #fecaca; }

        /* Meta Info (Tanggal) */
        .req-meta-info { font-size: 0.85rem; color: #94a3b8; display: flex; flex-direction: column; gap: 4px; }
        .req-meta-info span { color: #cbd5e1; }

        /* Reason Box dengan border aksen */
        .reason-box {
            background: rgba(255,255,255,0.02); border: 1px solid rgba(255,255,255,0.05); border-left: 4px solid #ef4444;
            border-radius: 8px; padding: 1rem 1.25rem; margin-top: 0.5rem;
        }
        .reason-title { font-size: 0.8rem; font-weight: 800; color: #fff; text-transform: uppercase; margin-bottom: 6px; letter-spacing: 0.05em; }
        .reason-text { font-size: 0.95rem; color: #cbd5e1; line-height: 1.6; }

        @keyframes slideDown { from { opacity: 0; transform: translateY(-20px); } to { opacity: 1; transform: translateY(0); } }
        @keyframes slideUp { from { opacity: 0; transform: translateY(30px); } to { opacity: 1; transform: translateY(0); } }

        /* Responsive */
        @media (max-width: 1024px) {
            .stats-grid-modern { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 768px) {
            .modern-page-header { padding: 1.5rem; text-align: center; }
            .header-content-wrapper { flex-direction: column; }
            .header-text-wrapper h1 { font-size: 1.8rem; }
            .stats-grid-modern { grid-template-columns: repeat(2, 1fr); gap: 1rem; }
            .modern-card-header { flex-direction: column; align-items: stretch; }
            .modern-toolbar { flex-direction: column; align-items: stretch; }
            .modern-search-wrapper { width: 100%; }
        }
        @media (max-width: 480px) {
            .req-user-row { flex-direction: column; gap: 15px; }
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
                        <h1>Semua Surat Peringatan</h1>
                        <p>Daftar semua surat peringatan dan PHK yang pernah dikeluarkan.</p>
                    </div>
                </div>
            </div>

            <div class="stats-grid-modern">
                <div class="stat-card-modern">
                    <h4>SP1</h4>
                    <div class="stat-val val-sp1"><?= $sp1_count ?></div>
                </div>
                <div class="stat-card-modern">
                    <h4>SP2</h4>
                    <div class="stat-val val-sp2"><?= $sp2_count ?></div>
                </div>
                <div class="stat-card-modern">
                    <h4>SP3</h4>
                    <div class="stat-val val-sp3"><?= $sp3_count ?></div>
                </div>
                <div class="stat-card-modern">
                    <h4>PHK</h4>
                    <div class="stat-val val-phk"><?= $phk_count ?></div>
                </div>
            </div>

            <div class="modern-card">
                
                <div class="modern-card-header">
                    <h3>Daftar Surat Peringatan</h3>
                    
                    <div class="modern-toolbar">
                        <div class="modern-tabs">
                            <button class="modern-tab-btn active" onclick="filterWarnings('all', this)">Semua</button>
                            <button class="modern-tab-btn" onclick="filterWarnings('sp1', this)">SP 1</button>
                            <button class="modern-tab-btn" onclick="filterWarnings('sp2', this)">SP 2</button>
                            <button class="modern-tab-btn" onclick="filterWarnings('sp3', this)">SP 3</button>
                            <button class="modern-tab-btn" onclick="filterWarnings('phk', this)">PHK</button>
                        </div>
                        
                        <div class="modern-search-wrapper">
                            <span class="modern-search-icon">🔍</span>
                            <input type="text" id="searchInput" class="modern-search-input" placeholder="Cari nama atau alasan..." onkeyup="searchWarnings()">
                        </div>
                    </div>
                </div>

                <div class="card-content">
                    <?php if (empty($warning_letters)): ?>
                        <div class="no-data" style="color: var(--success-color); text-align: center; padding: 3rem 0; background: rgba(16, 185, 129, 0.05); border-radius: 16px; border: 1px dashed rgba(16, 185, 129, 0.3);">
                            <span style="font-size: 3rem; display: block; margin-bottom: 10px;">🌟</span>
                            <strong style="font-size: 1.2rem;">Sistem Bersih!</strong><br>
                            Belum ada riwayat Surat Peringatan (SP) yang dikeluarkan.
                        </div>
                    <?php else: ?>
                        <div class="history-list" id="warningsContainer">
                            <?php foreach ($warning_letters as $warning): ?>
                            <?php 
                                // Normalisasi tipe kelas
                                $raw_type = strtoupper(str_replace(' ', '', $warning['sp_type'] ?? 'SP1'));
                                $type_class = 'type-' . strtolower($raw_type); 
                                $badge_class = 'badge-' . strtolower($raw_type);
                                
                                // Fallback jika format di database berantakan
                                if(!in_array($type_class, ['type-sp1', 'type-sp2', 'type-sp3', 'type-phk'])) {
                                    if(strpos($raw_type, 'PHK') !== false) {
                                        $type_class = 'type-phk'; $badge_class = 'badge-phk';
                                    } else {
                                        $type_class = 'type-sp1'; $badge_class = 'badge-sp1';
                                    }
                                }

                                // Pemetaan Data (Mencegah error jika data user terhapus)
                                $target_name = $warning['employee_name'] ?? 'Karyawan Dihapus';
                                $target_role = $warning['employee_role'] ?? 'UNKNOWN';
                                $issuer_name = $warning['issued_by_name'] ?? 'Sistem / Admin';
                                $avatar_char = strtoupper(substr($target_name, 0, 1));
                                
                                // Format Role (Hapus underscore)
                                $display_role = str_replace('_', ' ', $target_role);
                                if (function_exists('getRoleDisplayName')) {
                                    $display_role = getRoleDisplayName($target_role);
                                }
                            ?>
                            <div class="history-item-modern warning-item <?= $type_class ?>">
                                
                                <div class="req-user-row">
                                    <div class="user-profile-mini">
                                        <div class="user-avatar-sp"><?= $avatar_char ?></div>
                                        <div class="user-info-text">
                                            <div class="name"><?= htmlspecialchars($target_name) ?></div>
                                            <div class="role-badge"><?= htmlspecialchars($display_role) ?></div>
                                        </div>
                                    </div>
                                    <div class="sp-badge <?= $badge_class ?>">
                                        <?= htmlspecialchars($warning['sp_type']) ?>
                                    </div>
                                </div>
                                
                                <div class="req-meta-info">
                                    <div>Dikeluarkan pada: <span><?= date('d/m/Y H:i', strtotime($warning['issued_at'])) ?></span></div>
                                    <div>Dikeluarkan oleh: <span><?= htmlspecialchars($issuer_name) ?></span></div>
                                </div>
                                
                                <div class="reason-box">
                                    <div class="reason-title">Alasan:</div>
                                    <div class="reason-text">
                                        <?= nl2br(htmlspecialchars($warning['reason'] ?? '-')) ?>
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
    <script>
        // === Logika Tab & Pencarian ===
        let currentFilterType = 'all';

        function filterWarnings(type, btn) {
            currentFilterType = type;
            
            // Ubah state active pada tombol
            document.querySelectorAll('.modern-tab-btn').forEach(t => t.classList.remove('active'));
            btn.classList.add('active');
            
            applyFilters();
        }

        function searchWarnings() {
            applyFilters();
        }

        function applyFilters() {
            const query = document.getElementById('searchInput').value.toLowerCase();
            const items = document.querySelectorAll('.warning-item');

            items.forEach(item => {
                // Teks yang akan dicari (nama anggota, alasan, dll ada di innerText)
                const text = item.innerText.toLowerCase();
                
                // Cek apakah item sesuai dengan Tab Filter yang dipilih (SP1, SP2, SP3, PHK)
                const matchesType = (currentFilterType === 'all' || item.classList.contains('type-' + currentFilterType));
                
                // Cek apakah item sesuai dengan teks pencarian
                const matchesSearch = text.includes(query);

                if (matchesType && matchesSearch) {
                    item.style.display = 'flex'; // kembalikan ke flex column
                } else {
                    item.style.display = 'none';
                }
            });
        }
    </script>
</body>
</html>