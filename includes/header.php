<?php
// File: includes/header.php
global $conn;

// === CEK STATUS MAINTENANCE ===
$maintenance_file_path = __DIR__ . '/../maintenance_mode.txt';
$is_maintenance_active = file_exists($maintenance_file_path) && trim(file_get_contents($maintenance_file_path)) === '1';

// === START: LOGIKA NOTIFIKASI ===
$is_management = hasRole(['ceo', 'direktur', 'wakil_direktur', 'manager']);
$current_user_id = $_SESSION['id'] ?? 0;
$notifications = [];

if ($is_management) {
    // 1. Notifikasi Permohonan Pending
    $total_pending = getPendingRequestCount(); 
    if ($total_pending > 0) {
        $notifications[] = [
            'type' => 'info',
            'icon' => '📋',
            'title' => 'Permohonan Tertunda',
            'message' => "Terdapat $total_pending permohonan yang perlu ditinjau.",
            'time' => 'Sistem'
        ];
    }

    // 2. Notifikasi Overtime (> 7 Jam / 420 Menit) - Dalam 2 hari terakhir
    $ot_query = "SELECT e.name, dl.duration_minutes FROM duty_logs dl JOIN employees e ON dl.employee_id = e.id WHERE dl.duration_minutes > 420 AND dl.duty_start >= DATE(SUBDATE(NOW(), INTERVAL 2 DAY)) GROUP BY e.id LIMIT 3";
    $ot_res = $conn->query($ot_query);
    if ($ot_res && $ot_res->num_rows > 0) {
        while($ot = $ot_res->fetch_assoc()) {
            $hrs = floor($ot['duration_minutes']/60);
            $notifications[] = [
                'type' => 'warning',
                'icon' => '⏰',
                'title' => 'Peringatan Overtime',
                'message' => "{$ot['name']} tercatat overtime ({$hrs} jam).",
                'time' => 'Terbaru'
            ];
        }
    }

    // 3. Notifikasi Mangkir 3 Hari Berturut-turut
    $abs_query = "SELECT name FROM employees WHERE status='active' 
                  AND id NOT IN (SELECT employee_id FROM duty_logs WHERE duty_start >= DATE(SUBDATE(NOW(), INTERVAL 3 DAY))) 
                  AND id NOT IN (SELECT employee_id FROM leave_requests WHERE status='approved' AND end_date >= DATE(SUBDATE(NOW(), INTERVAL 3 DAY))) LIMIT 3";
    $abs_res = $conn->query($abs_query);
    if ($abs_res && $abs_res->num_rows > 0) {
         while($abs = $abs_res->fetch_assoc()) {
             $notifications[] = [
                'type' => 'danger',
                'icon' => '⚠️',
                'title' => 'Peringatan Absen',
                'message' => "{$abs['name']} absen 3 hari berturut-turut tanpa izin.",
                'time' => 'Penting'
             ];
         }
    }
} else {
    // NOTIFIKASI UNTUK KARYAWAN BIASA
    // 1. Notifikasi SP
    $sp_query = "SELECT sp_type FROM warning_letters WHERE employee_id = $current_user_id ORDER BY issued_at DESC LIMIT 1";
    $sp_res = $conn->query($sp_query);
    if ($sp_res && $sp_res->num_rows > 0) {
        $sp = $sp_res->fetch_assoc();
        $notifications[] = [
            'type' => 'danger',
            'icon' => '🚨',
            'title' => "Surat Peringatan ({$sp['sp_type']})",
            'message' => 'Anda mendapatkan Surat Peringatan. Harap perhatikan kinerja Anda.',
            'time' => 'Penting'
        ];
    }

    // 2. Notifikasi Teguran Mangkir (Diri Sendiri)
    $my_abs_query = "SELECT id FROM employees WHERE id=$current_user_id AND status='active' 
                     AND id NOT IN (SELECT employee_id FROM duty_logs WHERE duty_start >= DATE(SUBDATE(NOW(), INTERVAL 3 DAY))) 
                     AND id NOT IN (SELECT employee_id FROM leave_requests WHERE status='approved' AND end_date >= DATE(SUBDATE(NOW(), INTERVAL 3 DAY)))";
    $my_abs_res = $conn->query($my_abs_query);
    if ($my_abs_res && $my_abs_res->num_rows > 0) {
         $notifications[] = [
            'type' => 'warning',
            'icon' => '⚠️',
            'title' => 'Teguran Sistem',
            'message' => 'Anda tercatat tidak hadir 3 hari berturut-turut tanpa surat izin.',
            'time' => 'Segera'
         ];
    }
}

// Buat hash unik berdasarkan isi notifikasi untuk fitur "Mark as Read"
$notif_hash = md5(json_encode($notifications));
$notif_count = count($notifications);
// === END: LOGIKA NOTIFIKASI ===
?>
<style>
    /* === MODERN HEADER STYLING (Sinkron dengan Tema Beachside F&B) === */
    
    /* Override class header bawaan */
    .header.modern-header {
        background: rgba(15, 23, 42, 0.8) !important;
        backdrop-filter: blur(16px) !important;
        -webkit-backdrop-filter: blur(16px) !important;
        border-bottom: 1px solid rgba(255, 255, 255, 0.08) !important;
        position: sticky;
        top: 0;
        z-index: 1000;
        padding: 0;
        height: auto;
        box-shadow: 0 4px 20px -5px rgba(0, 0, 0, 0.3);
        display: flex;
        flex-direction: column; /* Allow banner to sit on top of content */
    }

    /* === MAINTENANCE BANNER STYLES === */
    .maintenance-banner-modern {
        background: rgba(245, 158, 11, 0.15);
        backdrop-filter: blur(10px);
        -webkit-backdrop-filter: blur(10px);
        border-bottom: 1px solid rgba(245, 158, 11, 0.3);
        padding: 8px 20px;
        display: flex;
        justify-content: center;
        align-items: center;
        gap: 12px;
        z-index: 1002;
    }
    .mab-icon { 
        font-size: 1.1rem; 
        animation: pulse-mab 1.5s infinite; 
        flex-shrink: 0;
    }
    .mab-text { 
        color: #fde68a; 
        font-size: 0.85rem; 
        font-weight: 500; 
        text-align: center; 
        line-height: 1.4; 
    }
    .mab-text strong { 
        color: #fff; 
        font-weight: 800; 
        background: #f59e0b; 
        padding: 2px 6px; 
        border-radius: 4px; 
        font-size: 0.75rem; 
        margin-right: 5px; 
        letter-spacing: 0.05em;
    }
    @keyframes pulse-mab {
        0% { transform: scale(1); opacity: 1; text-shadow: 0 0 10px rgba(245, 158, 11, 0.5); }
        50% { transform: scale(1.2); opacity: 0.7; text-shadow: 0 0 20px rgba(245, 158, 11, 0.8); }
        100% { transform: scale(1); opacity: 1; text-shadow: 0 0 10px rgba(245, 158, 11, 0.5); }
    }

    .modern-header-content {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 0.75rem 1.5rem;
        max-width: 100%;
        width: 100%;
        margin: 0 auto;
    }

    /* Kiri: Toggle Sidebar & Logo */
    .modern-header-left {
        display: flex;
        align-items: center;
        gap: 1.25rem;
    }

    .modern-sidebar-toggle {
        background: rgba(255, 255, 255, 0.05);
        border: 1px solid rgba(255, 255, 255, 0.1);
        color: #f8f9fa;
        border-radius: 10px;
        width: 38px;
        height: 38px;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        padding: 0;
    }

    .modern-sidebar-toggle:hover {
        background: rgba(255, 193, 7, 0.15);
        border-color: rgba(255, 193, 7, 0.4);
        color: var(--primary-color);
        transform: scale(1.05);
        box-shadow: 0 0 10px rgba(255, 193, 7, 0.2);
    }

    .modern-logo {
        display: flex;
        align-items: center;
        gap: 12px;
        text-decoration: none;
        transition: transform 0.3s ease;
        cursor: pointer;
    }

    .modern-logo:hover {
        transform: translateY(-2px);
    }

    .modern-logo img {
        height: 40px;
        width: auto;
        object-fit: contain;
        filter: drop-shadow(0 2px 8px rgba(255, 193, 7, 0.3));
        animation: float-logo-header 3s ease-in-out infinite alternate;
    }

    @keyframes float-logo-header {
        0% { transform: translateY(0); }
        100% { transform: translateY(-3px); }
    }

    .modern-logo-text {
        color: #fff;
        font-weight: 800;
        font-size: 1.1rem;
        letter-spacing: 0.02em;
        display: flex;
        flex-direction: column;
        line-height: 1.1;
    }

    .modern-logo-text span {
        font-size: 0.65rem;
        color: var(--primary-color);
        text-transform: uppercase;
        letter-spacing: 0.1em;
        font-weight: 700;
        opacity: 0.9;
    }
    
    /* Kanan: Notifikasi, User Menu & Actions */
    .modern-header-actions {
        display: flex;
        align-items: center;
        gap: 1.25rem;
    }

    /* === NOTIFICATION STYLES === */
    .notif-wrapper { position: relative; }
    
    .notif-bell-modern {
        background: rgba(255, 255, 255, 0.05);
        border: 1px solid rgba(255, 255, 255, 0.1);
        width: 38px; height: 38px; border-radius: 50%;
        display: flex; align-items: center; justify-content: center;
        color: #fff; cursor: pointer; transition: 0.3s; position: relative;
    }
    .notif-bell-modern:hover { 
        background: rgba(255, 255, 255, 0.15); 
        transform: translateY(-2px); 
        box-shadow: 0 0 15px rgba(255, 255, 255, 0.1);
    }
    
    .notif-badge-modern {
        position: absolute; top: -2px; right: -2px; background: #ef4444; color: white; font-size: 0.65rem;
        font-weight: 800; width: 16px; height: 16px; border-radius: 50%; display: flex; align-items: center; justify-content: center;
        box-shadow: 0 0 10px #ef4444; display: none; /* Hidden by default, JS handles it */
    }

    .notif-dropdown-modern {
        position: absolute; top: 50px; right: 0; width: 340px;
        background: rgba(15, 23, 42, 0.95); backdrop-filter: blur(16px); -webkit-backdrop-filter: blur(16px);
        border: 1px solid rgba(255, 255, 255, 0.1); border-radius: 20px; box-shadow: 0 15px 35px rgba(0,0,0,0.5);
        display: none; flex-direction: column; z-index: 1000; overflow: hidden; opacity: 0; transform: translateY(10px);
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    }
    .notif-dropdown-modern.show { display: flex; opacity: 1; transform: translateY(0); }
    
    .notif-header-modern {
        display: flex; justify-content: space-between; align-items: center; padding: 15px 20px;
        border-bottom: 1px solid rgba(255, 255, 255, 0.1); background: rgba(255, 255, 255, 0.02);
    }
    .notif-header-modern h4 { margin: 0; font-size: 1rem; color: #fff; font-weight: 800; }
    
    .mark-read-btn {
        background: none; border: none; color: #60a5fa; font-size: 0.75rem; font-weight: 700; cursor: pointer; transition: 0.2s; padding: 0; text-transform: uppercase; letter-spacing: 0.05em;
    }
    .mark-read-btn:hover { color: #93c5fd; text-decoration: underline; }

    .notif-body-modern { max-height: 350px; overflow-y: auto; padding: 15px; display: flex; flex-direction: column; gap: 10px; }
    .notif-body-modern::-webkit-scrollbar { width: 5px; }
    .notif-body-modern::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.1); border-radius: 10px; }

    .notif-item-modern {
        display: flex; gap: 15px; padding: 15px; border-radius: 14px; background: rgba(255, 255, 255, 0.03);
        border: 1px solid rgba(255, 255, 255, 0.05); border-left: 4px solid transparent; transition: 0.2s;
    }
    .notif-item-modern:hover { background: rgba(255, 255, 255, 0.06); transform: translateX(3px); border-right-color: rgba(255,255,255,0.1); }
    
    .notif-item-modern.type-info { border-left-color: #3b82f6; }
    .notif-item-modern.type-warning { border-left-color: #f59e0b; }
    .notif-item-modern.type-danger { border-left-color: #ef4444; }

    .notif-icon-modern { font-size: 1.4rem; flex-shrink: 0; display: flex; align-items: flex-start; margin-top: 2px;}
    .notif-content-modern { display: flex; flex-direction: column; gap: 5px; }
    .notif-title-modern { font-size: 0.9rem; font-weight: 800; color: #e2e8f0; margin: 0; }
    .notif-desc-modern { font-size: 0.8rem; color: #94a3b8; line-height: 1.5; margin: 0; }
    .notif-time-modern { font-size: 0.7rem; color: #64748b; font-weight: 700; margin-top: 2px;}

    .notif-empty-modern { padding: 3rem 1rem; text-align: center; color: #64748b; font-size: 0.9rem; font-style: italic; }

    /* === USER MENU === */
    .modern-user-menu {
        display: flex;
        align-items: center;
        gap: 1rem;
        background: rgba(0, 0, 0, 0.25);
        padding: 0.4rem 0.4rem 0.4rem 1rem;
        border-radius: 50px;
        border: 1px solid rgba(255, 255, 255, 0.05);
        transition: all 0.3s ease;
    }
    
    .modern-user-menu:hover {
        background: rgba(0, 0, 0, 0.4);
        border-color: rgba(255, 255, 255, 0.1);
    }

    .modern-user-info {
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .modern-user-avatar {
        width: 30px;
        height: 30px;
        background: linear-gradient(135deg, rgba(255, 193, 7, 0.2), rgba(255, 193, 7, 0.05));
        color: var(--primary-color);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 0.9rem;
        font-weight: 800;
        border: 1px solid rgba(255, 193, 7, 0.4);
        box-shadow: 0 0 10px rgba(255, 193, 7, 0.1);
    }

    .modern-user-name {
        color: #f8f9fa;
        font-weight: 600;
        font-size: 0.9rem;
        letter-spacing: 0.01em;
    }

    .modern-btn-logout {
        background: rgba(239, 68, 68, 0.1);
        color: #fca5a5;
        border: 1px solid rgba(239, 68, 68, 0.3);
        padding: 0.4rem 1.2rem;
        border-radius: 20px;
        font-size: 0.85rem;
        font-weight: 700;
        text-decoration: none;
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        display: flex;
        align-items: center;
        gap: 6px;
    }

    .modern-btn-logout:hover {
        background: var(--danger-color);
        color: #fff;
        border-color: var(--danger-color);
        box-shadow: 0 4px 15px rgba(239, 68, 68, 0.4);
        transform: translateY(-1px);
    }

    .modern-btn-logout svg {
        transition: transform 0.3s ease;
    }

    .modern-btn-logout:hover svg {
        transform: translateX(3px);
    }

    /* Responsif untuk Mobile */
    @media (max-width: 768px) {
        .modern-logo-text { display: none; }
        .modern-header-content { padding: 0.6rem 1rem; }
        .modern-user-name { display: none; }
        
        .modern-header-actions { gap: 0.75rem; }
        
        .modern-user-menu { 
            padding: 0.3rem; 
            border-radius: 50%; 
            background: transparent; 
            border: none; 
            gap: 0;
        }
        
        .notif-dropdown-modern {
            width: 280px;
            right: -60px; /* Adjust so it doesn't bleed off screen */
        }
        
        .modern-btn-logout {
            padding: 0.5rem;
            border-radius: 50%;
        }
        .modern-btn-logout span {
            display: none;
        }
        .modern-btn-logout svg {
            margin: 0;
        }
        .mab-text { font-size: 0.75rem; }
    }
</style>

<header class="header modern-header">
    
    <?php if ($is_maintenance_active): ?>
    <div class="maintenance-banner-modern">
        <span class="mab-icon">🚧</span>
        <div class="mab-text">
            <strong>PENGUMUMAN SISTEM:</strong> Website saat ini sedang dalam Mode Pemeliharaan <i>(Maintenance)</i>. Anda mungkin akan mengalami sedikit penurunan performa.
        </div>
    </div>
    <?php endif; ?>

    <div class="modern-header-content">
        <div class="modern-header-left">
            <button class="modern-sidebar-toggle" id="sidebar-toggle" aria-label="Toggle Menu" title="Buka/Tutup Menu">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="3" y1="12" x2="21" y2="12"></line>
                    <line x1="3" y1="6" x2="21" y2="6"></line>
                    <line x1="3" y1="18" x2="21" y2="18"></line>
                </svg>
            </button>
            
            <div class="modern-logo" onclick="window.location.href='dashboard'">
                <img src="LOGO_WOT.png" alt="Warung Om Tante Logo">
                <div class="modern-logo-text">
                    Warung Om Tante
                    <span>Manajemen V2</span>
                </div>
            </div>
        </div>

        <div class="modern-header-actions">
            
            <div class="notif-wrapper" id="notifWrapper">
                <button class="notif-bell-modern" id="notifBell" title="Notifikasi">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path>
                        <path d="M13.73 21a2 2 0 0 1-3.46 0"></path>
                    </svg>
                    <span class="notif-badge-modern" id="notifBadge"><?= $notif_count ?></span>
                </button>
                
                <div class="notif-dropdown-modern" id="notifDropdown">
                    <div class="notif-header-modern">
                        <h4>Pusat Notifikasi</h4>
                        <?php if ($notif_count > 0): ?>
                            <button class="mark-read-btn" id="markReadBtn">✔️ Tandai Dibaca</button>
                        <?php endif; ?>
                    </div>
                    <div class="notif-body-modern">
                        <?php if (empty($notifications)): ?>
                            <div class="notif-empty-modern">
                                <span style="font-size: 2rem; display: block; margin-bottom: 10px; opacity: 0.5;">🔕</span>
                                Tidak ada notifikasi baru untuk Anda saat ini.
                            </div>
                        <?php else: ?>
                            <?php foreach ($notifications as $n): ?>
                                <div class="notif-item-modern type-<?= $n['type'] ?>">
                                    <div class="notif-icon-modern"><?= $n['icon'] ?></div>
                                    <div class="notif-content-modern">
                                        <p class="notif-title-modern"><?= htmlspecialchars($n['title']) ?></p>
                                        <p class="notif-desc-modern"><?= htmlspecialchars($n['message']) ?></p>
                                        <span class="notif-time-modern"><?= $n['time'] ?></span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="modern-user-menu">
                <div class="modern-user-info">
                    <div class="modern-user-avatar">
                        <?= strtoupper(substr($_SESSION['name'], 0, 1)) ?>
                    </div>
                    <span class="modern-user-name"><?= htmlspecialchars($_SESSION['name']) ?></span>
                </div>
                
                <a href="logout.php" class="modern-btn-logout" title="Keluar dari sistem">
                    <span>Keluar</span>
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
                        <polyline points="16 17 21 12 16 7"></polyline>
                        <line x1="21" y1="12" x2="9" y2="12"></line>
                    </svg>
                </a>
            </div>
        </div>
    </div>
</header>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const notifBell = document.getElementById('notifBell');
    const notifDropdown = document.getElementById('notifDropdown');
    const notifBadge = document.getElementById('notifBadge');
    const markReadBtn = document.getElementById('markReadBtn');
    
    // Hash saat ini dari server (berubah jika ada data notif baru/hilang)
    const currentHash = "<?= $notif_hash ?>";
    const notifCount = <?= $notif_count ?>;

    // Cek localStorage: apakah hash ini sudah pernah ditandai dibaca?
    const savedHash = localStorage.getItem('wot_read_notif_hash');
    
    if (notifCount > 0 && savedHash !== currentHash) {
        // Tampilkan badge merah jika ada notif dan belum ditandai dibaca
        if(notifBadge) notifBadge.style.display = 'flex';
    }

    // Toggle Dropdown
    if(notifBell && notifDropdown) {
        notifBell.addEventListener('click', function(e) {
            e.stopPropagation();
            notifDropdown.classList.toggle('show');
        });
    }

    // Mark as Read
    if (markReadBtn) {
        markReadBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            // Simpan hash saat ini ke local storage
            localStorage.setItem('wot_read_notif_hash', currentHash);
            // Sembunyikan badge
            if(notifBadge) notifBadge.style.display = 'none';
            // Efek visual tombol
            markReadBtn.innerHTML = '✨ Bersih';
            markReadBtn.style.color = '#34d399';
            markReadBtn.style.textDecoration = 'none';
            markReadBtn.style.cursor = 'default';
        });
    }

    // Tutup dropdown jika klik di luar
    document.addEventListener('click', function(e) {
        if (notifBell && notifDropdown && !notifBell.contains(e.target) && !notifDropdown.contains(e.target)) {
            notifDropdown.classList.remove('show');
        }
    });
});
</script>