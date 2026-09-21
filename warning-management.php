<?php
require_once 'config.php';

// Cek hak akses. Hanya direktur, wakil direktur, dan manajer yang bisa mengakses.
if (!isLoggedIn() || !hasRole(['ceo', 'direktur', 'wakil_direktur', 'manager'])) {
    header('Location: dashboard.php');
    exit;
}

$user = getCurrentUser();
$pending_requests_count = getPendingRequestCount(); // Untuk sidebar

$success = null;
$error = null;
$selected_employee_id = null;
$selected_employee_name = 'Pilih Anggota';
$warning_history = [];

// --- Logika Penghapusan Otomatis (Surat Peringatan Lebih dari 1 bulan) ---
$one_month_ago = date('Y-m-d H:i:s', strtotime('-1 month'));
$stmt_auto_delete = $conn->prepare("DELETE FROM warning_letters WHERE issued_at < ?");
if ($stmt_auto_delete) {
    $stmt_auto_delete->bind_param("s", $one_month_ago);
    $stmt_auto_delete->execute();
    if ($stmt_auto_delete->affected_rows > 0) {
        // Notifikasi Discord bisa ditambahkan di sini jika diperlukan
    }
    $stmt_auto_delete->close();
}

// --- Logika Penghapusan Manual per Entri ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_sp') {
    $sp_id_to_delete = (int)($_POST['sp_id'] ?? 0);
    $employee_id_of_sp = (int)($_POST['employee_id'] ?? 0);

    if ($sp_id_to_delete <= 0) {
        $error = "ID surat peringatan tidak valid!";
    } else {
        try {
            $stmt = $conn->prepare("DELETE FROM warning_letters WHERE id = ? AND employee_id = ?");
            if (!$stmt) {
                throw new Exception("Gagal menyiapkan query: " . $conn->error);
            }
            $stmt->bind_param("ii", $sp_id_to_delete, $employee_id_of_sp);

            if ($stmt->execute()) {
                $success = "Surat peringatan berhasil dihapus.";
                 // Kirim notifikasi Discord
                $employee_name = getEmployeeNameById($employee_id_of_sp);
                sendDiscordNotification([
                    'employee_name' => $employee_name,
                    'admin_name' => $user['name'],
                    'sp_id' => $sp_id_to_delete
                ], 'warning_letter_deleted');

            } else {
                throw new Exception("Gagal menghapus surat peringatan: " . $stmt->error);
            }
            $stmt->close();
        } catch (Exception $e) {
            $error = "Terjadi kesalahan: " . $e->getMessage();
        }
    }
}


// Handle form submission for issuing a new warning letter
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'issue_sp') {
    $employee_id = (int)($_POST['employee_id'] ?? 0);
    $sp_type = $_POST['sp_type'] ?? '';
    $reason = $_POST['reason'] ?? '';

    if ($employee_id <= 0 || empty($sp_type) || empty($reason)) {
        $error = "Semua field (Anggota, Tipe SP, dan Alasan) harus diisi!";
    } else {
        try {
            $stmt = $conn->prepare("
                INSERT INTO warning_letters (employee_id, issued_by_employee_id, sp_type, reason)
                VALUES (?, ?, ?, ?)
            ");
            if (!$stmt) {
                throw new Exception("Gagal menyiapkan query: " . $conn->error);
            }
            $stmt->bind_param("iiss", $employee_id, $user['id'], $sp_type, $reason);

            if ($stmt->execute()) {
                $success = "Surat peringatan **" . htmlspecialchars($sp_type) . "** berhasil dikeluarkan.";
                
                $employee_name = getEmployeeNameById($employee_id);
                sendDiscordNotification([
                    'employee_name' => $employee_name,
                    'sp_type' => $sp_type,
                    'reason' => $reason,
                    'admin_name' => $user['name']
                ], 'warning_letter_issued');

            } else {
                throw new Exception("Gagal mengeluarkan surat peringatan: " . $stmt->error);
            }
            $stmt->close();
        } catch (Exception $e) {
            $error = "Terjadi kesalahan: " . $e->getMessage();
        }
    }
}

// Get all active employees for the dropdown
$all_employees = $conn->query("SELECT id, name, role FROM employees WHERE status = 'active' ORDER BY name")->fetch_all(MYSQLI_ASSOC);

// Handle employee selection to show history
if (isset($_GET['employee_id']) && !empty($_GET['employee_id'])) {
    $selected_employee_id = (int)$_GET['employee_id'];
    
    foreach ($all_employees as $emp) {
        if ($emp['id'] === $selected_employee_id) {
            $selected_employee_name = htmlspecialchars($emp['name']);
            break;
        }
    }

    $stmt_history = $conn->prepare("
        SELECT wl.*, issued_by.name as issued_by_name
        FROM warning_letters wl
        LEFT JOIN employees issued_by ON wl.issued_by_employee_id = issued_by.id
        WHERE wl.employee_id = ?
        ORDER BY wl.issued_at DESC
    ");
    if ($stmt_history) {
        $stmt_history->bind_param("i", $selected_employee_id);
        $stmt_history->execute();
        $warning_history = $stmt_history->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt_history->close();
    } else {
        $error = "Gagal mengambil riwayat surat peringatan: " . $conn->error;
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manajemen Surat Peringatan - Warung Om Tante V2</title>
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
            content: ''; position: absolute; top: -20%; right: -5%; width: 300px; height: 300px;
            background: radial-gradient(circle, rgba(239, 68, 68, 0.15), transparent 70%);
            pointer-events: none; z-index: 0;
        }

        .header-content-wrapper { display: flex; align-items: center; gap: 1.25rem; z-index: 2; position: relative; }
        .header-icon-wrapper {
            background: rgba(255, 255, 255, 0.05); border: 1px solid rgba(255, 255, 255, 0.1);
            width: 60px; height: 60px; border-radius: 16px; display: flex; align-items: center;
            justify-content: center; font-size: 1.8rem; box-shadow: inset 0 0 15px rgba(0,0,0,0.3);
            flex-shrink: 0; color: #ef4444; /* Merah Peringatan */
        }

        .header-text-wrapper h1 { color: #fff; font-weight: 800; font-size: 2.2rem; letter-spacing: -0.02em; margin: 0; }
        .header-text-wrapper p { color: var(--text-secondary); margin: 0.25rem 0 0 0; font-size: 1.05rem; }

        /* --- Cards (Glassmorphism) --- */
        .modern-card {
            background: rgba(30, 41, 59, 0.6) !important; backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.08) !important; border-radius: 24px;
            box-shadow: 0 15px 35px rgba(0,0,0,0.3) !important; padding: 2rem;
            animation: slideUp 0.6s ease-out backwards; margin-bottom: 2rem;
        }
        .modern-card-header {
            border-bottom: 1px solid rgba(255, 255, 255, 0.1); padding-bottom: 1rem; margin-bottom: 1.5rem;
        }
        .modern-card-header h3 { color: #fff; font-size: 1.25rem; font-weight: 700; margin: 0; display: flex; align-items: center; gap: 10px; }

        /* --- Form Inner Wrapper --- */
        .form-inner-wrapper {
            background: linear-gradient(145deg, rgba(30, 41, 59, 0.4), rgba(15, 23, 42, 0.6));
            border: 1px solid rgba(255, 255, 255, 0.05); border-radius: 20px; padding: 1.75rem;
            box-shadow: inset 0 0 20px rgba(0,0,0,0.2);
        }

        .modern-form-group { margin-bottom: 1.5rem; }
        .modern-form-group label { display: block; font-size: 0.9rem; font-weight: 600; color: var(--text-secondary); margin-bottom: 0.6rem; }
        
        .modern-input-wrapper { position: relative; display: flex; align-items: center; }
        .textarea-wrapper { align-items: flex-start; }
        .modern-input-icon { position: absolute; left: 1.25rem; font-size: 1.2rem; opacity: 0.6; pointer-events: none; z-index: 2; }
        .textarea-wrapper .modern-input-icon { top: 1.1rem; }
        
        .modern-select, .modern-textarea {
            width: 100%; background: rgba(0, 0, 0, 0.4); border: 1px solid rgba(255, 255, 255, 0.1);
            color: white; padding: 1.1rem 1.1rem 1.1rem 3.5rem; border-radius: 14px; font-size: 0.95rem; transition: all 0.3s ease;
        }
        .modern-select { appearance: none; cursor: pointer; }
        .modern-select option { background: #0f172a; color: white; }
        .modern-textarea { min-height: 120px; resize: vertical; line-height: 1.6; font-family: inherit; }
        
        .modern-select:focus, .modern-textarea:focus {
            outline: none; border-color: #ef4444; background: rgba(0, 0, 0, 0.6); box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.15);
        }

        .sp-form-grid { display: grid; grid-template-columns: 1fr; gap: 1.5rem; }
        @media (min-width: 768px) { .sp-form-grid { grid-template-columns: 1fr 1fr; } }

        /* --- Buttons --- */
        .modern-btn-submit {
            width: 100%; background: linear-gradient(135deg, #ef4444, #b91c1c); color: #fff; border: none;
            padding: 1.2rem; border-radius: 14px; font-size: 1rem; font-weight: 800; text-transform: uppercase;
            letter-spacing: 0.05em; cursor: pointer; transition: all 0.3s ease; display: flex; align-items: center;
            justify-content: center; gap: 0.75rem; margin-top: 1rem; box-shadow: 0 8px 20px rgba(239, 68, 68, 0.3);
        }
        .modern-btn-submit:hover { transform: translateY(-3px); box-shadow: 0 12px 25px rgba(239, 68, 68, 0.5); }
        .modern-btn-submit:active { transform: translateY(0); }

        .btn-del-modern {
            background: rgba(239, 68, 68, 0.1); color: #fca5a5; border: 1px solid rgba(239, 68, 68, 0.3);
            padding: 8px 16px; border-radius: 10px; cursor: pointer; font-weight: 700; font-size: 0.85rem;
            transition: all 0.2s; display: inline-flex; align-items: center; gap: 6px; margin-top: 1rem;
        }
        .btn-del-modern:hover { background: #ef4444; color: #fff; box-shadow: 0 5px 15px rgba(239, 68, 68, 0.3); }

        /* ========================================================
           HISTORY ITEMS UI (DAFTAR SP)
           ======================================================== */
        .history-list { display: flex; flex-direction: column; gap: 1.5rem; }

        .history-item-modern {
            background: linear-gradient(145deg, rgba(30, 41, 59, 0.4), rgba(15, 23, 42, 0.6));
            border: 1px solid rgba(255, 255, 255, 0.05); border-radius: 20px; padding: 1.5rem;
            transition: all 0.3s ease; position: relative; display: flex; flex-direction: column; gap: 1rem;
        }
        .history-item-modern:hover {
            transform: translateY(-3px); box-shadow: 0 10px 25px rgba(0,0,0,0.4);
            background: linear-gradient(145deg, rgba(30, 41, 59, 0.6), rgba(15, 23, 42, 0.8)); border-color: rgba(255, 255, 255, 0.1);
        }

        /* Garis Aksen Kiri berdasarkan tipe SP */
        .history-item-modern::before {
            content: ''; position: absolute; left: -1px; top: 1.5rem; bottom: 1.5rem; width: 4px; border-radius: 0 4px 4px 0;
        }
        .type-sp1::before { background: #eab308; box-shadow: 0 0 10px #eab308; } /* Yellow */
        .type-sp2::before { background: #f97316; box-shadow: 0 0 10px #f97316; } /* Orange */
        .type-sp3::before { background: #ef4444; box-shadow: 0 0 10px #ef4444; } /* Red */
        .type-phk::before { background: #991b1b; box-shadow: 0 0 10px #991b1b; } /* Dark Red */

        .req-header { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; }
        .req-dates-wrapper { display: flex; align-items: center; gap: 15px; }
        
        .req-icon {
            width: 48px; height: 48px; border-radius: 14px; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; box-shadow: inset 0 0 10px rgba(0,0,0,0.3);
        }
        .type-sp1 .req-icon { background: rgba(234, 179, 8, 0.1); border: 1px solid rgba(234, 179, 8, 0.3); color: #eab308; }
        .type-sp2 .req-icon { background: rgba(249, 115, 22, 0.1); border: 1px solid rgba(249, 115, 22, 0.3); color: #f97316; }
        .type-sp3 .req-icon { background: rgba(239, 68, 68, 0.1); border: 1px solid rgba(239, 68, 68, 0.3); color: #ef4444; }
        .type-phk .req-icon { background: rgba(153, 27, 27, 0.1); border: 1px solid rgba(153, 27, 27, 0.3); color: #fca5a5; }

        .req-dates-info { display: flex; flex-direction: column; }
        .req-dates-label { font-size: 0.8rem; color: #cbd5e1; font-weight: 600;}
        .req-dates-value { font-size: 0.85rem; color: var(--text-muted); margin-top: 2px;}

        /* Badge Status */
        .sp-badge-modern {
            display: inline-flex; align-items: center; padding: 6px 14px; border-radius: 30px; font-size: 0.8rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; box-shadow: 0 4px 10px rgba(0,0,0,0.2);
        }
        .badge-sp1 { background: rgba(234, 179, 8, 0.15); color: #fde047; border: 1px solid rgba(234, 179, 8, 0.4); }
        .badge-sp2 { background: rgba(249, 115, 22, 0.15); color: #fdba74; border: 1px solid rgba(249, 115, 22, 0.4); }
        .badge-sp3 { background: rgba(239, 68, 68, 0.15); color: #fca5a5; border: 1px solid rgba(239, 68, 68, 0.4); }
        .badge-phk { background: rgba(153, 27, 27, 0.3); color: #fecaca; border: 1px solid rgba(153, 27, 27, 0.6); }

        /* Body Detail */
        .req-body {
            background: rgba(0, 0, 0, 0.25); border-radius: 12px; padding: 1.25rem; display: flex; flex-direction: column; gap: 8px; border: 1px solid rgba(255,255,255,0.03);
            border-left: 3px solid #ef4444; /* Indikasi bahwa ini adalah pelanggaran */
        }
        .reason-title { font-size: 0.75rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-secondary); }
        .reason-text { font-size: 0.95rem; color: #e2e8f0; line-height: 1.6; white-space: pre-wrap;}

        /* Alerts */
        .error-alert { background: rgba(220, 53, 69, 0.15); border: 1px solid rgba(220, 53, 69, 0.4); color: #fca5a5; padding: 1rem 1.2rem; border-radius: 14px; margin-bottom: 1.5rem; display: flex; align-items: center; gap: 0.75rem; font-size: 0.95rem; font-weight: 500; animation: shake 0.5s ease-in-out; }
        .success-alert { background: rgba(52, 211, 153, 0.15); border: 1px solid rgba(52, 211, 153, 0.4); color: #6ee7b7; padding: 1rem 1.2rem; border-radius: 14px; margin-bottom: 1.5rem; display: flex; align-items: center; gap: 0.75rem; font-size: 0.95rem; font-weight: 500; animation: slideDown 0.4s ease-out; }

        @keyframes slideDown { from { opacity: 0; transform: translateY(-20px); } to { opacity: 1; transform: translateY(0); } }
        @keyframes slideUp { from { opacity: 0; transform: translateY(30px); } to { opacity: 1; transform: translateY(0); } }
        @keyframes shake { 0%, 100% { transform: translateX(0); } 25% { transform: translateX(-5px); } 75% { transform: translateX(5px); } }

        @media (max-width: 768px) {
            .modern-page-header { padding: 1.5rem; text-align: center; }
            .header-content-wrapper { flex-direction: column; }
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
                        <h1>Manajemen Surat Peringatan</h1>
                        <p>Kelola penerbitan dan riwayat surat peringatan atau PHK untuk anggota.</p>
                    </div>
                </div>
            </div>

            <?php if (isset($success)): ?>
                <div class="success-alert"><span>🎉</span> <?= htmlspecialchars($success) ?></div>
            <?php endif; ?>
            
            <?php if (isset($error)): ?>
                <div class="error-alert"><span>❌</span> <?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <div class="modern-card">
                <div class="modern-card-header">
                    <h3><span>👤</span> Pilih Anggota</h3>
                </div>
                <div class="form-inner-wrapper">
                    <form method="GET" action="warning-management.php">
                        <div class="modern-form-group" style="margin-bottom: 0;">
                            <div class="modern-input-wrapper">
                                <span class="modern-input-icon">🔍</span>
                                <select name="employee_id" class="modern-select" onchange="this.form.submit()">
                                    <option value="">-- Cari & Pilih Anggota --</option>
                                    <?php foreach ($all_employees as $emp): ?>
                                        <option value="<?= $emp['id'] ?>" <?= ($selected_employee_id === $emp['id']) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($emp['name']) ?> (<?= getRoleDisplayName($emp['role']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <?php if ($selected_employee_id): ?>
                
                <div class="modern-card">
                    <div class="modern-card-header">
                        <h3><span>📝</span> Berikan SP untuk: <span style="color:#fca5a5;"><?= $selected_employee_name ?></span></h3>
                    </div>
                    <div class="form-inner-wrapper">
                        <form method="POST" action="warning-management.php?employee_id=<?= $selected_employee_id ?>">
                            <input type="hidden" name="action" value="issue_sp">
                            <input type="hidden" name="employee_id" value="<?= $selected_employee_id ?>">
                            
                            <div class="sp-form-grid">
                                <div class="modern-form-group">
                                    <label for="sp_type">Tipe Surat Peringatan</label>
                                    <div class="modern-input-wrapper">
                                        <span class="modern-input-icon">🏷️</span>
                                        <select name="sp_type" id="sp_type" class="modern-select" required>
                                            <option value="">Pilih Tipe SP</option>
                                            <option value="SP1">SP1 (Surat Peringatan Pertama)</option>
                                            <option value="SP2">SP2 (Surat Peringatan Kedua)</option>
                                            <option value="SP3">SP3 (Surat Peringatan Ketiga)</option>
                                            <option value="PHK">PHK (Pemutusan Hubungan Kerja)</option>
                                        </select>
                                    </div>
                                </div>
                                
                                <div class="modern-form-group">
                                    <label for="reason">Alasan Pelanggaran</label>
                                    <div class="modern-input-wrapper textarea-wrapper">
                                        <span class="modern-input-icon">💬</span>
                                        <textarea name="reason" id="reason" class="modern-textarea" placeholder="Jelaskan secara detail alasan SP ini diberikan..." required></textarea>
                                    </div>
                                </div>
                            </div>
                            
                            <button type="submit" class="modern-btn-submit" onclick="return confirm('Apakah Anda yakin ingin mengeluarkan Surat Peringatan ini?')">
                                <span>Keluarkan Peringatan</span> 🚀
                            </button>
                        </form>
                    </div>
                </div>

                <div class="modern-card">
                    <div class="modern-card-header">
                        <h3><span>📜</span> Riwayat SP: <?= $selected_employee_name ?></h3>
                    </div>
                    <div class="card-content">
                        <?php if (empty($warning_history)): ?>
                            <div class="no-data" style="color: var(--success-color); text-align: center; padding: 3rem 0; background: rgba(16, 185, 129, 0.05); border-radius: 16px; border: 1px dashed rgba(16, 185, 129, 0.3);">
                                <span style="font-size: 3rem; display: block; margin-bottom: 10px;">🌟</span>
                                <strong style="font-size: 1.2rem;">Catatan Bersih!</strong><br>
                                Tidak ada riwayat surat peringatan untuk anggota ini.
                            </div>
                        <?php else: ?>
                            <div class="history-list">
                                <?php foreach ($warning_history as $sp): ?>
                                    <?php 
                                        $type_class = 'type-' . strtolower($sp['sp_type']); 
                                        $badge_class = 'badge-' . strtolower($sp['sp_type']);
                                    ?>
                                    <div class="history-item-modern <?= $type_class ?>">
                                        
                                        <div class="req-header">
                                            <div class="req-dates-wrapper">
                                                <div class="req-icon">
                                                    <?= ($sp['sp_type'] === 'PHK') ? '⛔' : '⚠️' ?>
                                                </div>
                                                <div class="req-dates-info">
                                                    <span class="req-dates-label">Dikeluarkan pada:</span>
                                                    <div class="req-dates-value"><?= date('d F Y - H:i WIB', strtotime($sp['issued_at'])) ?></div>
                                                </div>
                                            </div>
                                            <span class="sp-badge-modern <?= $badge_class ?>">
                                                <?= htmlspecialchars($sp['sp_type']) ?>
                                            </span>
                                        </div>
                                        
                                        <div class="req-body">
                                            <span class="reason-title">Alasan Peringatan:</span>
                                            <div class="reason-text">"<?= htmlspecialchars($sp['reason']) ?>"</div>
                                        </div>
                                        
                                        <div class="req-header" style="margin-top: 0.5rem; border-top: 1px dashed rgba(255,255,255,0.1); padding-top: 1rem;">
                                            <div style="font-size: 0.85rem; color: #94a3b8;">
                                                Dikeluarkan oleh: <strong style="color:#fff;"><?= htmlspecialchars($sp['issued_by_name'] ?? 'Sistem') ?></strong>
                                            </div>
                                            <form method="POST" onsubmit="return confirm('Yakin ingin menghapus surat peringatan ini secara permanen?')">
                                                <input type="hidden" name="action" value="delete_sp">
                                                <input type="hidden" name="sp_id" value="<?= $sp['id'] ?>">
                                                <input type="hidden" name="employee_id" value="<?= $selected_employee_id ?>">
                                                <button type="submit" class="btn-del-modern">
                                                    <span>🗑️</span> Hapus SP
                                                </button>
                                            </form>
                                        </div>

                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

            <?php endif; ?>
            
        </main>
    </div>

    <script src="script.js"></script>
</body>
</html>