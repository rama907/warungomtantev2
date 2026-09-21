<?php
require_once 'config.php';

if (!isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$user = getCurrentUser();
$pending_requests_count = getPendingRequestCount();

$success = null;
$error = null;

// Handle form submission for a new suggestion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_suggestion') {
    $message = trim($_POST['message'] ?? '');

    if (empty($message)) {
        $error = "Pesan saran dan kritik tidak boleh kosong!";
    } else {
        try {
            $stmt = $conn->prepare("INSERT INTO suggestions (message) VALUES (?)");
            if (!$stmt) {
                throw new Exception("Gagal menyiapkan query: " . $conn->error);
            }
            $stmt->bind_param("s", $message);

            if ($stmt->execute()) {
                $success = "Terima kasih atas saran dan kritik Anda. Masukan Anda telah berhasil dikirimkan secara anonim.";
            } else {
                throw new Exception("Gagal mengirimkan saran: " . $stmt->error);
            }
            $stmt->close();
        } catch (Exception $e) {
            $error = "Terjadi kesalahan: " . $e->getMessage();
        }
    }
}

// Fetch all suggestions if the user has permission
$all_suggestions = [];
if (hasRole(['ceo', 'direktur', 'wakil_direktur', 'manager'])) {
    $stmt_suggestions = $conn->query("
        SELECT id, message, submitted_at
        FROM suggestions
        ORDER BY submitted_at DESC
    ");
    if ($stmt_suggestions) {
        $all_suggestions = $stmt_suggestions->fetch_all(MYSQLI_ASSOC);
        $stmt_suggestions->close();
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Saran & Kritik - Warung Om Tante V2</title>
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
            background: radial-gradient(circle, rgba(250, 204, 21, 0.15), transparent 70%); pointer-events: none; z-index: 0;
        }
        
        .header-content-wrapper { display: flex; align-items: center; gap: 1.25rem; z-index: 2; position: relative; }
        .header-icon-wrapper {
            background: rgba(255, 255, 255, 0.05); border: 1px solid rgba(255, 255, 255, 0.1);
            width: 60px; height: 60px; border-radius: 16px; display: flex; align-items: center;
            justify-content: center; font-size: 1.8rem; box-shadow: inset 0 0 15px rgba(0,0,0,0.3); flex-shrink: 0; color: #facc15;
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

        .modern-card-header {
            display: flex; justify-content: space-between; align-items: center; 
            margin-bottom: 1.5rem; border-bottom: 1px solid rgba(255, 255, 255, 0.1); padding-bottom: 1rem;
            flex-wrap: wrap; gap: 15px;
        }
        .modern-card-header h3 { color: #fff; font-size: 1.25rem; font-weight: 700; margin: 0; display: flex; align-items: center; gap: 10px;}

        /* --- Form Inner Wrapper --- */
        .form-inner-wrapper {
            background: linear-gradient(145deg, rgba(30, 41, 59, 0.4), rgba(15, 23, 42, 0.6));
            border: 1px solid rgba(255, 255, 255, 0.05); border-radius: 20px; padding: 1.75rem;
            box-shadow: inset 0 0 20px rgba(0,0,0,0.2);
        }

        /* --- Modern Form Elements --- */
        .modern-form-group { margin-bottom: 1.5rem; }
        .modern-form-group label {
            display: block; font-size: 0.9rem; font-weight: 600; color: var(--text-secondary); margin-bottom: 0.6rem;
        }
        
        .modern-input-wrapper { position: relative; display: flex; align-items: center; }
        .textarea-wrapper { align-items: flex-start; }
        
        .modern-input-icon {
            position: absolute; left: 1.25rem; font-size: 1.2rem; opacity: 0.6; pointer-events: none; z-index: 2;
        }
        .textarea-wrapper .modern-input-icon { top: 1.1rem; }
        
        .modern-textarea {
            width: 100%; background: rgba(0, 0, 0, 0.4); border: 1px solid rgba(255, 255, 255, 0.1);
            color: white; padding: 1.1rem 1.1rem 1.1rem 3.5rem; border-radius: 14px; font-size: 1rem;
            font-family: inherit; transition: all 0.3s ease; min-height: 150px; resize: vertical; line-height: 1.6;
        }
        .modern-textarea:focus {
            outline: none; border-color: var(--primary-color); background: rgba(0, 0, 0, 0.6);
            box-shadow: 0 0 0 3px rgba(255, 193, 7, 0.15);
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
           HISTORY ITEMS UI (DAFTAR SARAN - ADMIN ONLY)
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

        /* Garis Aksen Kiri */
        .history-item-modern::before {
            content: ''; position: absolute; left: -1px; top: 1.5rem; bottom: 1.5rem; width: 4px; border-radius: 0 4px 4px 0;
            background: #94a3b8; box-shadow: 0 0 10px rgba(148, 163, 184, 0.5);
        }

        /* Header Ticket */
        .req-header { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; }
        .req-dates-wrapper { display: flex; align-items: center; gap: 15px; }
        
        .req-icon {
            width: 48px; height: 48px; border-radius: 14px; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; box-shadow: inset 0 0 10px rgba(0,0,0,0.3);
            background: rgba(148, 163, 184, 0.1); border: 1px solid rgba(148, 163, 184, 0.2); color: #94a3b8;
        }

        .req-dates-info { display: flex; flex-direction: column; }
        .req-dates-label { font-size: 0.8rem; color: #cbd5e1; margin-bottom: 2px; font-weight: 600; display: flex; align-items: center; gap: 5px;}
        .req-dates-label b { color: #fff; font-size: 1.1rem;}
        
        .anon-badge {
            display: inline-flex; align-items: center; gap: 6px; padding: 6px 14px; border-radius: 30px; font-size: 0.8rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; box-shadow: 0 4px 10px rgba(0,0,0,0.2);
            background: rgba(255, 255, 255, 0.1); color: #f8f9fa; border: 1px solid rgba(255, 255, 255, 0.2);
        }

        /* Body Detail */
        .req-body {
            background: rgba(0, 0, 0, 0.3); border-radius: 12px; padding: 1.25rem; display: flex; flex-direction: column; gap: 1rem; border: 1px solid rgba(255,255,255,0.03);
            border-left: 3px solid rgba(255,255,255,0.2);
        }
        .reason-text { font-size: 0.95rem; color: #e2e8f0; line-height: 1.6; white-space: pre-wrap;}

        /* Footer */
        .req-footer {
            display: flex; justify-content: flex-start; align-items: center; flex-wrap: wrap; gap: 15px;
            padding-top: 1rem; border-top: 1px dashed rgba(255,255,255,0.1);
        }
        .req-meta-item { display: flex; align-items: center; gap: 6px; font-size: 0.85rem; color: var(--text-muted); font-weight: 500; }

        /* --- Alerts --- */
        .error-alert { background: rgba(220, 53, 69, 0.15); border: 1px solid rgba(220, 53, 69, 0.4); color: #fca5a5; padding: 1rem 1.2rem; border-radius: 14px; margin-bottom: 1.5rem; display: flex; align-items: center; gap: 0.75rem; font-size: 0.95rem; font-weight: 500; animation: shake 0.5s ease-in-out; }
        .success-alert { background: rgba(52, 211, 153, 0.15); border: 1px solid rgba(52, 211, 153, 0.4); color: #6ee7b7; padding: 1rem 1.2rem; border-radius: 14px; margin-bottom: 1.5rem; display: flex; align-items: center; gap: 0.75rem; font-size: 0.95rem; font-weight: 500; animation: slideDown 0.4s ease-out; }

        @keyframes slideDown { from { opacity: 0; transform: translateY(-20px); } to { opacity: 1; transform: translateY(0); } }
        @keyframes slideUp { from { opacity: 0; transform: translateY(30px); } to { opacity: 1; transform: translateY(0); } }
        @keyframes shake { 0%, 100% { transform: translateX(0); } 25% { transform: translateX(-5px); } 75% { transform: translateX(5px); } }

        /* Responsive */
        @media (max-width: 768px) {
            .modern-page-header { padding: 1.5rem; text-align: center; }
            .header-content-wrapper { flex-direction: column; }
            .header-text-wrapper h1 { font-size: 1.8rem; }
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
                    <div class="header-icon-wrapper">💡</div>
                    <div class="header-text-wrapper">
                        <h1>Saran & Kritik</h1>
                        <p>Ruang untuk memberikan masukan anonim demi kemajuan manajemen dan perusahaan.</p>
                    </div>
                </div>
            </div>

            <?php if (isset($success)): ?>
                <div class="success-alert"><span>🎉</span> <?= htmlspecialchars($success) ?></div>
            <?php endif; ?>
            
            <?php if (isset($error)): ?>
                <div class="error-alert"><span>⚠️</span> <?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <div class="modern-card">
                <div class="modern-card-header">
                    <h3><span>✉️</span> Kirim Saran/Kritik Anonim</h3>
                </div>
                
                <div class="form-inner-wrapper">
                    <form method="POST" action="suggestions.php">
                        <input type="hidden" name="action" value="submit_suggestion">
                        
                        <div class="modern-form-group">
                            <label for="message">Pesan Anda</label>
                            <div class="modern-input-wrapper textarea-wrapper">
                                <span class="modern-input-icon">💭</span>
                                <textarea name="message" id="message" class="modern-textarea" placeholder="Tulis saran atau kritik Anda di sini... (Bersifat anonim, jadi jangan ragu!)" required></textarea>
                            </div>
                        </div>
                        
                        <button type="submit" class="modern-btn-submit">
                            <span>Kirimkan Masukan</span> 🚀
                        </button>
                    </form>
                </div>
            </div>

            <?php if (hasRole(['ceo', 'direktur', 'wakil_direktur', 'manager'])): ?>
            <div class="modern-card">
                <div class="modern-card-header">
                    <h3><span>📥</span> Semua Saran & Kritik (Admin View)</h3>
                </div>
                <div class="card-content">
                    <?php if (empty($all_suggestions)): ?>
                        <div class="no-data" style="color: var(--text-muted); text-align: center; padding: 3rem 0; background: rgba(0,0,0,0.2); border-radius: 16px;">
                            <span style="font-size: 3rem; display: block; margin-bottom: 10px;">📭</span>
                            Belum ada saran atau kritik yang masuk.
                        </div>
                    <?php else: ?>
                        <div class="history-list">
                            <?php foreach ($all_suggestions as $suggestion): ?>
                                <div class="history-item-modern">
                                    
                                    <div class="req-header">
                                        <div class="req-dates-wrapper">
                                            <div class="req-icon">🕵️</div>
                                            <div class="req-dates-info">
                                                <span class="req-dates-label">
                                                    Pengirim: <b>Anonim</b>
                                                </span>
                                            </div>
                                        </div>
                                        <span class="anon-badge">
                                            Pesan Rahasia
                                        </span>
                                    </div>
                                    
                                    <div class="req-body">
                                        <div class="reason-text"><?= htmlspecialchars($suggestion['message']) ?></div>
                                    </div>
                                    
                                    <div class="req-footer">
                                        <div class="req-meta-item">
                                            <span>🕒</span> Dikirim pada: <?= date('d M Y, H:i', strtotime($suggestion['submitted_at'])) ?>
                                        </div>
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