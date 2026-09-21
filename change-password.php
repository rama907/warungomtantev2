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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'change_password') {
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
        $error = "Semua field harus diisi.";
    } elseif ($new_password !== $confirm_password) {
        $error = "Kata sandi baru dan konfirmasi kata sandi tidak cocok.";
    } elseif (strlen($new_password) < 6) {
        $error = "Kata sandi baru minimal harus 6 karakter.";
    } else {
        // Lakukan verifikasi kata sandi lama
        $stmt = $conn->prepare("SELECT password FROM employees WHERE id = ?");
        $stmt->bind_param("i", $user['id']);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        
        if ($result && password_verify($current_password, $result['password'])) {
            // Hash kata sandi baru dan perbarui di database
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            $update_stmt = $conn->prepare("UPDATE employees SET password = ? WHERE id = ?");
            $update_stmt->bind_param("si", $hashed_password, $user['id']);
            
            if ($update_stmt->execute()) {
                $success = "Kata sandi berhasil diubah!";
                sendDiscordNotification([
                    'employee_name' => $user['name'],
                    'action_type' => 'change_password',
                ], 'employee_action');
            } else {
                $error = "Gagal mengubah kata sandi: " . $conn->error;
            }
            $update_stmt->close();
        } else {
            $error = "Kata sandi lama tidak valid.";
        }
        $stmt->close();
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ubah Kata Sandi - Warung Om Tante V2</title>
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

        /* Efek Glow di sudut header */
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
            max-width: 700px; /* Form di tengah, tidak terlalu lebar */
            margin-left: auto;
            margin-right: auto;
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

        .modern-input-wrapper {
            position: relative;
            display: flex;
            align-items: center;
            width: 100%;
        }
        
        .modern-input-icon {
            position: absolute;
            left: 1.25rem;
            font-size: 1.2rem;
            opacity: 0.6;
            pointer-events: none;
            z-index: 2;
        }

        .modern-input {
            width: 100%;
            background: rgba(0, 0, 0, 0.4);
            border: 1px solid rgba(255, 255, 255, 0.1);
            color: white;
            padding: 1.1rem 3rem 1.1rem 3.5rem; /* Padding ekstra di kanan untuk icon mata */
            border-radius: 14px;
            font-size: 1rem;
            font-family: inherit;
            transition: all 0.3s ease;
        }

        .modern-input:focus {
            outline: none;
            border-color: var(--primary-color);
            background: rgba(0, 0, 0, 0.6);
            box-shadow: 0 0 0 3px rgba(255, 193, 7, 0.15);
        }

        /* Styling spesifik untuk class bawaan password-toggle dari script.js */
        .password-toggle {
            position: absolute;
            right: 1rem;
            background: none;
            border: none;
            color: var(--text-muted);
            cursor: pointer;
            font-size: 1.2rem;
            z-index: 2;
            transition: color 0.3s ease;
            padding: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            outline: none;
        }
        .password-toggle:hover, .password-toggle.active {
            color: var(--primary-color);
        }

        .form-help-modern {
            display: block;
            margin-top: 0.5rem;
            font-size: 0.8rem;
            color: var(--text-muted);
            font-style: italic;
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
            margin-top: 1.5rem;
            box-shadow: 0 8px 20px rgba(255, 193, 7, 0.25);
        }
        .modern-btn-submit:hover {
            transform: translateY(-3px);
            box-shadow: 0 12px 25px rgba(255, 193, 7, 0.4);
        }
        .modern-btn-submit:active { transform: translateY(0); }

        /* --- Alerts --- */
        .error-alert { background: rgba(220, 53, 69, 0.15); border: 1px solid rgba(220, 53, 69, 0.4); color: #fca5a5; padding: 1rem 1.2rem; border-radius: 14px; margin-bottom: 1.5rem; display: flex; align-items: center; gap: 0.75rem; font-size: 0.95rem; font-weight: 500; animation: shake 0.5s ease-in-out; max-width: 700px; margin-left: auto; margin-right: auto;}
        .success-alert { background: rgba(52, 211, 153, 0.15); border: 1px solid rgba(52, 211, 153, 0.4); color: #6ee7b7; padding: 1rem 1.2rem; border-radius: 14px; margin-bottom: 1.5rem; display: flex; align-items: center; gap: 0.75rem; font-size: 0.95rem; font-weight: 500; animation: slideDown 0.4s ease-out; max-width: 700px; margin-left: auto; margin-right: auto;}

        @keyframes slideDown { from { opacity: 0; transform: translateY(-20px); } to { opacity: 1; transform: translateY(0); } }
        @keyframes slideUp { from { opacity: 0; transform: translateY(30px); } to { opacity: 1; transform: translateY(0); } }
        @keyframes shake { 0%, 100% { transform: translateX(0); } 25% { transform: translateX(-5px); } 75% { transform: translateX(5px); } }

        /* Responsive */
        @media (max-width: 768px) {
            .modern-page-header { padding: 1.5rem; text-align: center; }
            .header-content-wrapper { flex-direction: column; gap: 1rem; }
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
                    <div class="header-icon-wrapper">🔒</div>
                    <div class="header-text-wrapper">
                        <h1>Ubah Kata Sandi</h1>
                        <p>Kelola dan perbarui kata sandi akun Anda demi keamanan.</p>
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
                    <h3><span>🔑</span> Formulir Perubahan</h3>
                </div>
                
                <div class="form-inner-wrapper">
                    <form method="POST" class="change-password-form" id="changePasswordForm">
                        <input type="hidden" name="action" value="change_password">
                        
                        <div class="modern-form-group">
                            <label for="current_password">Kata Sandi Lama</label>
                            <div class="modern-input-wrapper">
                                <span class="modern-input-icon">🛡️</span>
                                <input type="password" name="current_password" id="current_password" class="modern-input" placeholder="Masukkan kata sandi lama" required>
                                <button type="button" class="password-toggle" data-target="current_password" aria-label="Tampilkan Kata Sandi">
                                    <span class="icon">👁️</span>
                                </button>
                            </div>
                        </div>
                        
                        <div class="modern-form-group">
                            <label for="new_password">Kata Sandi Baru</label>
                            <div class="modern-input-wrapper">
                                <span class="modern-input-icon">✨</span>
                                <input type="password" name="new_password" id="new_password" class="modern-input" placeholder="Masukkan kata sandi baru" required>
                                <button type="button" class="password-toggle" data-target="new_password" aria-label="Tampilkan Kata Sandi">
                                    <span class="icon">👁️</span>
                                </button>
                            </div>
                            <small class="form-help-modern">Minimal 6 karakter kombinasi huruf dan angka disarankan.</small>
                        </div>
                        
                        <div class="modern-form-group">
                            <label for="confirm_password">Konfirmasi Kata Sandi Baru</label>
                            <div class="modern-input-wrapper">
                                <span class="modern-input-icon">✅</span>
                                <input type="password" name="confirm_password" id="confirm_password" class="modern-input" placeholder="Konfirmasi kata sandi baru" required>
                                <button type="button" class="password-toggle" data-target="confirm_password" aria-label="Tampilkan Kata Sandi">
                                    <span class="icon">👁️</span>
                                </button>
                            </div>
                        </div>
                        
                        <button type="submit" class="modern-btn-submit">
                            <span>Perbarui Kata Sandi</span> 💾
                        </button>
                    </form>
                </div>
            </div>

        </main>
    </div>

    <script src="script.js"></script>
</body>
</html>