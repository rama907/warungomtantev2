<?php
require_once 'config.php';

session_start();

$user = getCurrentUser();
$pending_requests_count = getPendingRequestCount();

$success = null;
$error = null;
$all_employees = $conn->query("SELECT id, name, role FROM employees WHERE status = 'active' ORDER BY name")->fetch_all(MYSQLI_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_password_reset_request') {
    $employee_id = (int)($_POST['employee_id'] ?? 0);
    $new_password = $_POST['new_password'] ?? '';
    $reset_type = $_POST['reset_type'] ?? 'default';

    if ($employee_id <= 0) {
        $error = "Anggota belum dipilih!";
    } elseif ($reset_type === 'new' && empty($new_password)) {
        $error = "Kata sandi baru tidak boleh kosong!";
    } else {
        // Find employee name for notification
        $requested_employee_name = '';
        foreach ($all_employees as $emp) {
            if ($emp['id'] === $employee_id) {
                $requested_employee_name = $emp['name'];
                break;
            }
        }

        $password_to_hash = ($reset_type === 'new') ? $new_password : (defined('DEFAULT_EMPLOYEE_PASSWORD') && DEFAULT_EMPLOYEE_PASSWORD !== '' ? DEFAULT_EMPLOYEE_PASSWORD : bin2hex(random_bytes(6)));
        $hashed_password = password_hash($password_to_hash, PASSWORD_DEFAULT);
        
        $requested_by_id = isset($user['id']) ? $user['id'] : NULL;


        $stmt = $conn->prepare("
            INSERT INTO password_reset_requests (employee_id, requested_password, reset_type, requested_by)
            VALUES (?, ?, ?, ?)
        ");
        
        if (!$stmt) {
            $error = "Gagal menyiapkan query: " . $conn->error;
        } else {
            $stmt->bind_param("issi", $employee_id, $hashed_password, $reset_type, $requested_by_id);

            if ($stmt->execute()) {
                $success = "Permintaan reset kata sandi untuk **" . htmlspecialchars($requested_employee_name) . "** berhasil diajukan! Menunggu persetujuan admin.";
                sendDiscordNotification([
                    'employee_name' => isset($user['name']) ? $user['name'] : 'Pengguna Anonim',
                    'target_employee_name' => $requested_employee_name,
                    'reset_type' => $reset_type,
                ], 'password_reset_request_submitted');
            } else {
                $error = "Gagal mengajukan permintaan reset kata sandi: " . $stmt->error;
            }
            $stmt->close();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Kata Sandi - Warung Om Tante V2</title>
    <link rel="icon" href="LOGO_WOT.png" type="image/png">
    <link rel="shortcut icon" href="favicon.ico" type="image/x-icon">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        /* === Gaya Dasar Modern F&B (Sama dengan index.php) === */
        :root {
            --primary-color: #ffc107;
            --primary-hover: #e0a800;
            --success-color: #34d399;
            --danger-color: #f87171;
            --text-primary: #f8f9fa;
            --text-secondary: #cbd5e1;
            --text-muted: #94a3b8;
            --bg-primary: #0f172a;
            --bg-secondary: #1e293b;
            --border-color: rgba(255, 255, 255, 0.1);
        }

        body.fnb-login-body {
            margin: 0;
            min-height: 100vh;
            background: linear-gradient(135deg, #0f172a 0%, #1e1b4b 50%, #31111d 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Inter', sans-serif;
            padding: 2rem 1rem;
            color: var(--text-primary);
            overflow-x: hidden;
            position: relative;
        }

        .bg-orb {
            position: absolute; border-radius: 50%; filter: blur(80px); z-index: 0;
            animation: float-orb 10s infinite ease-in-out alternate;
        }
        .bg-orb.orb-1 { width: 400px; height: 400px; background: rgba(255, 193, 7, 0.15); top: -10%; left: -5%; }
        .bg-orb.orb-2 { width: 500px; height: 500px; background: rgba(14, 165, 233, 0.1); bottom: -10%; right: -5%; animation-delay: -5s; }

        @keyframes float-orb {
            0% { transform: translate(0, 0) scale(1); }
            100% { transform: translate(30px, -50px) scale(1.1); }
        }

        .fnb-login-container {
            display: flex; flex-direction: column; width: 100%; max-width: 1100px;
            background: rgba(30, 41, 59, 0.6); backdrop-filter: blur(20px);
            border-radius: 24px; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5), 0 0 0 1px rgba(255,255,255,0.1);
            overflow: hidden; position: relative; z-index: 10;
            animation: container-fade-in 0.8s cubic-bezier(0.16, 1, 0.3, 1);
        }

        @keyframes container-fade-in {
            0% { opacity: 0; transform: translateY(40px) scale(0.98); }
            100% { opacity: 1; transform: translateY(0) scale(1); }
        }

        @media (min-width: 900px) {
            .fnb-login-container { flex-direction: row; min-height: 650px; }
        }

        /* --- Left Panel --- */
        .fnb-left-panel {
            flex: 1.1; background: linear-gradient(145deg, rgba(2, 6, 23, 0.8), rgba(15, 23, 42, 0.9));
            padding: 3rem; display: flex; flex-direction: column; justify-content: space-between;
            border-right: 1px solid var(--border-color); position: relative; overflow: hidden;
        }

        .ocean-waves {
            position: absolute; bottom: 0; left: 0; width: 100%; height: 150px;
            overflow: hidden; z-index: 0; opacity: 0.6;
        }
        .wave {
            position: absolute; bottom: 0; left: 0; width: 200%; height: 100px;
            background: url('data:image/svg+xml;utf8,<svg viewBox="0 0 800 100" xmlns="http://www.w3.org/2000/svg"><path d="M0 50 Q 100 0 200 50 T 400 50 T 600 50 T 800 50 L 800 100 L 0 100 Z" fill="rgba(255,193,7,0.1)"/></svg>') repeat-x;
            background-size: 50% 100%; animation: wave-animation 10s linear infinite;
        }
        .wave:nth-child(2) {
            bottom: -15px; opacity: 0.5; animation: wave-animation 15s linear infinite reverse;
            background: url('data:image/svg+xml;utf8,<svg viewBox="0 0 800 100" xmlns="http://www.w3.org/2000/svg"><path d="M0 50 Q 100 20 200 50 T 400 50 T 600 50 T 800 50 L 800 100 L 0 100 Z" fill="rgba(14,165,233,0.15)"/></svg>') repeat-x;
            background-size: 50% 100%;
        }

        @keyframes wave-animation { 0% { transform: translateX(0); } 100% { transform: translateX(-50%); } }

        .fnb-left-content { position: relative; z-index: 1; }
        .fnb-brand { display: flex; align-items: center; gap: 1.5rem; margin-bottom: 2rem; animation: slide-right 0.6s ease-out 0.2s backwards; }
        .fnb-brand img { width: 85px; height: 85px; object-fit: contain; filter: drop-shadow(0 0 15px rgba(255, 193, 7, 0.3)); }
        .fnb-brand h1 { font-size: 2rem; font-weight: 800; color: white; margin: 0 0 0.25rem 0; line-height: 1.1; letter-spacing: -0.02em; }
        .fnb-brand p { font-size: 1rem; color: var(--primary-color); margin: 0; font-weight: 600; text-transform: uppercase; letter-spacing: 0.08em; }

        .beach-quote {
            font-size: 1.1rem; color: var(--text-secondary); font-style: italic; margin-bottom: 3rem; line-height: 1.6;
            animation: slide-right 0.6s ease-out 0.4s backwards;
        }

        .info-widget {
            background: rgba(0, 0, 0, 0.4); border: 1px solid rgba(255, 255, 255, 0.1); border-radius: 20px;
            padding: 1.5rem; backdrop-filter: blur(10px); animation: slide-up 0.6s ease-out 0.6s backwards;
        }
        .info-widget h3 { margin: 0 0 1rem 0; font-size: 1.1rem; color: white; display: flex; align-items: center; gap: 0.5rem; }
        .info-list { display: flex; flex-direction: column; gap: 1rem; }
        .info-item { display: flex; align-items: flex-start; gap: 0.75rem; font-size: 0.9rem; color: var(--text-secondary); line-height: 1.5; }
        .info-item .step-num {
            display: flex; align-items: center; justify-content: center; width: 24px; height: 24px;
            background: rgba(255, 193, 7, 0.2); color: var(--primary-color); border-radius: 50%; font-weight: 700; font-size: 0.75rem; flex-shrink: 0; border: 1px solid rgba(255, 193, 7, 0.5);
        }

        /* --- Right Panel --- */
        .fnb-right-panel {
            flex: 1.2; padding: 3rem 3.5rem; display: flex; flex-direction: column; justify-content: center;
            background: rgba(15, 23, 42, 0.6); overflow-y: auto;
        }

        .login-header-text { margin-bottom: 2.5rem; animation: slide-up 0.6s ease-out 0.2s backwards; }
        .login-header-text h2 { font-size: 2rem; font-weight: 700; color: white; margin: 0 0 0.5rem 0; letter-spacing: -0.02em; }
        .login-header-text p { color: var(--text-secondary); font-size: 1rem; margin: 0; }

        .modern-form-group { margin-bottom: 1.5rem; position: relative; animation: slide-up 0.6s ease-out 0.4s backwards; }
        .modern-form-group label { display: block; font-size: 0.9rem; font-weight: 600; color: var(--text-secondary); margin-bottom: 0.5rem; }
        .modern-input-wrapper { position: relative; display: flex; align-items: center; }
        .modern-input-icon { position: absolute; left: 1.25rem; font-size: 1.2rem; opacity: 0.7; pointer-events: none; z-index: 2; }
        
        .modern-input {
            width: 100%; background: rgba(0, 0, 0, 0.3); border: 2px solid rgba(255, 255, 255, 0.1); color: white;
            padding: 1.1rem 1.1rem 1.1rem 3.5rem; border-radius: 16px; font-size: 1rem; font-family: inherit; transition: all 0.3s ease;
        }
        .modern-input:focus { outline: none; border-color: var(--primary-color); background: rgba(0, 0, 0, 0.5); box-shadow: 0 0 0 4px rgba(255, 193, 7, 0.15); }

        /* --- Custom Select Dropdown UI --- */
        .custom-select-container { position: relative; width: 100%; }
        .custom-select-trigger {
            width: 100%; background: rgba(0, 0, 0, 0.3); border: 2px solid rgba(255, 255, 255, 0.1); color: white;
            padding: 1.1rem 1.1rem 1.1rem 3.5rem; border-radius: 16px; font-size: 1.05rem; cursor: pointer; display: flex; align-items: center; justify-content: space-between; transition: all 0.3s ease; user-select: none;
        }
        .custom-select-trigger:hover, .custom-select-container.open .custom-select-trigger { border-color: var(--primary-color); background: rgba(0, 0, 0, 0.5); }
        .custom-select-container.open .custom-select-trigger { border-bottom-left-radius: 0; border-bottom-right-radius: 0; box-shadow: 0 0 0 4px rgba(255, 193, 7, 0.15); }

        .custom-options {
            position: absolute; top: 100%; left: 0; right: 0; background: rgba(15, 23, 42, 0.95); backdrop-filter: blur(20px);
            border: 2px solid var(--primary-color); border-top: none; border-bottom-left-radius: 16px; border-bottom-right-radius: 16px;
            max-height: 250px; overflow-y: auto; z-index: 100; opacity: 0; visibility: hidden; transform: translateY(-10px); transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1); box-shadow: 0 10px 25px rgba(0,0,0,0.5);
        }
        .custom-select-container.open .custom-options { opacity: 1; visibility: visible; transform: translateY(0); }

        .custom-option { padding: 1rem 1.25rem; display: flex; align-items: center; justify-content: space-between; cursor: pointer; border-bottom: 1px solid rgba(255,255,255,0.05); transition: background 0.2s ease; }
        .custom-option:hover { background: rgba(255, 255, 255, 0.1); }
        .custom-option:last-child { border-bottom: none; }
        .custom-option.selected { background: rgba(255, 193, 7, 0.1); }
        .opt-info { display: flex; flex-direction: column; gap: 4px; }
        .opt-name { font-weight: 600; color: white; }
        .opt-role { font-size: 0.75rem; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 0.05em; }
        
        .custom-options::-webkit-scrollbar { width: 6px; }
        .custom-options::-webkit-scrollbar-track { background: rgba(0,0,0,0.2); }
        .custom-options::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.2); border-radius: 10px; }
        .custom-options::-webkit-scrollbar-thumb:hover { background: var(--primary-color); }

        /* --- Radio Cards --- */
        .radio-group-modern {
            display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-top: 0.5rem;
        }
        .radio-card {
            background: rgba(0, 0, 0, 0.3); border: 2px solid rgba(255, 255, 255, 0.1); border-radius: 16px;
            padding: 1rem; cursor: pointer; transition: all 0.3s ease; display: flex; align-items: center; gap: 0.75rem;
            color: var(--text-secondary); font-size: 0.95rem; font-weight: 500; user-select: none;
        }
        .radio-card:hover { background: rgba(255, 255, 255, 0.05); border-color: rgba(255,255,255,0.2); }
        .radio-card input[type="radio"] { display: none; }
        .radio-card.selected { border-color: var(--primary-color); background: rgba(255, 193, 7, 0.1); color: white; }
        .radio-icon { font-size: 1.25rem; filter: grayscale(1); transition: all 0.3s ease; }
        .radio-card.selected .radio-icon { filter: grayscale(0); transform: scale(1.1); }

        .form-help { display: block; margin-top: 0.5rem; font-size: 0.8rem; color: var(--text-muted); font-style: italic; }

        .password-toggle-modern {
            position: absolute; right: 1.25rem; background: none; border: none; font-size: 1.2rem; opacity: 0.5; cursor: pointer; padding: 0.2rem; transition: all 0.3s ease;
        }
        .password-toggle-modern:hover { opacity: 1; color: var(--primary-color); transform: scale(1.1); }

        .modern-btn-submit {
            width: 100%; background: linear-gradient(135deg, var(--primary-color), var(--primary-hover));
            color: #121212; border: none; padding: 1.2rem; border-radius: 16px; font-size: 1.1rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; cursor: pointer; transition: all 0.3s ease; display: flex; align-items: center; justify-content: center; gap: 0.75rem; margin-top: 1.5rem; box-shadow: 0 10px 25px rgba(255, 193, 7, 0.3); animation: slide-up 0.6s ease-out 0.6s backwards;
        }
        .modern-btn-submit:hover { transform: translateY(-3px); box-shadow: 0 15px 35px rgba(255, 193, 7, 0.5); }
        
        .error-alert { background: rgba(220, 53, 69, 0.15); border: 1px solid rgba(220, 53, 69, 0.4); color: #fca5a5; padding: 1rem 1.2rem; border-radius: 14px; margin-bottom: 1.5rem; display: flex; align-items: center; gap: 0.75rem; font-size: 0.95rem; font-weight: 500; animation: shake 0.5s ease-in-out; }
        .success-alert { background: rgba(52, 211, 153, 0.15); border: 1px solid rgba(52, 211, 153, 0.4); color: #6ee7b7; padding: 1rem 1.2rem; border-radius: 14px; margin-bottom: 1.5rem; display: flex; align-items: center; gap: 0.75rem; font-size: 0.95rem; font-weight: 500; animation: slide-up 0.4s ease-out; }

        .quick-actions { margin-top: 2.5rem; text-align: center; border-top: 1px solid rgba(255,255,255,0.1); padding-top: 1.5rem; animation: slide-up 0.6s ease-out 0.8s backwards; }
        .action-links a { color: var(--text-secondary); text-decoration: none; transition: color 0.3s ease; display: inline-flex; align-items: center; gap: 5px; font-weight: 500; font-size: 0.95rem; }
        .action-links a:hover { color: var(--primary-color); }

        /* Animations */
        @keyframes slide-right { from { opacity: 0; transform: translateX(-20px); } to { opacity: 1; transform: translateX(0); } }
        @keyframes slide-up { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
        @keyframes shake { 0%, 100% { transform: translateX(0); } 25% { transform: translateX(-5px); } 75% { transform: translateX(5px); } }

        @media (max-width: 900px) {
            .fnb-left-panel, .fnb-right-panel { padding: 2.5rem 2rem; }
            .fnb-brand img { width: 65px; height: 65px; }
            .fnb-brand h1 { font-size: 1.5rem; }
            .login-header-text h2 { font-size: 1.75rem; }
            .bg-orb { display: none; }
            .radio-group-modern { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body class="fnb-login-body">
    <div class="bg-orb orb-1"></div>
    <div class="bg-orb orb-2"></div>

    <div class="fnb-login-container">
        
        <div class="fnb-left-panel">
            <div class="fnb-left-content">
                <div class="fnb-brand">
                    <img src="LOGO_WOT.png" alt="Warung Om Tante Logo">
                    <div>
                        <h1>Warung Om Tante</h1>
                        <p>Pemulihan Akses</p>
                    </div>
                </div>

                <div class="beach-quote">
                    "Jangan khawatir, kami akan membantu Anda memulihkan akses agar bisa kembali bertugas."
                </div>

                <div class="info-widget">
                    <h3>💡 Panduan Reset Sandi</h3>
                    <div class="info-list">
                        <div class="info-item">
                            <span class="step-num">1</span>
                            <span>Pilih nama akun yang kata sandinya ingin direset.</span>
                        </div>
                        <div class="info-item">
                            <span class="step-num">2</span>
                            <span>Pilih metode: Kembali ke sandi default atau buat sandi baru.</span>
                        </div>
                        <div class="info-item">
                            <span class="step-num">3</span>
                            <span>Tunggu persetujuan Admin sebelum mencoba masuk kembali.</span>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="ocean-waves">
                <div class="wave"></div>
                <div class="wave"></div>
            </div>
        </div>

        <div class="fnb-right-panel">
            <div class="login-header-text">
                <h2>Reset Kata Sandi</h2>
                <p>Ajukan permohonan untuk mereset kata sandi akun.</p>
            </div>

            <?php if (isset($success)): ?>
                <div class="success-alert">
                    <span>🎉</span>
                    <?= htmlspecialchars($success) ?>
                </div>
            <?php endif; ?>
            
            <?php if (isset($error)): ?>
                <div class="error-alert">
                    <span>⚠️</span>
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <form method="POST" id="resetForm">
                <input type="hidden" name="action" value="submit_password_reset_request">
                <input type="hidden" name="employee_id" id="selected_employee_id" required>
                
                <div class="modern-form-group">
                    <label>Pilih Anggota</label>
                    <div class="modern-input-wrapper">
                        <span class="modern-input-icon">👤</span>
                        
                        <div class="custom-select-container" id="customSelect">
                            <div class="custom-select-trigger" id="selectTrigger">
                                <span class="selected-text" style="color: rgba(255,255,255,0.5);">Pilih akun Anda...</span>
                                <span>▼</span>
                            </div>
                            <div class="custom-options">
                                <?php foreach ($all_employees as $emp): ?>
                                    <div class="custom-option" data-value="<?= $emp['id'] ?>">
                                        <div class="opt-info">
                                            <span class="opt-name"><?= htmlspecialchars($emp['name']) ?></span>
                                            <span class="opt-role"><?= getRoleDisplayName($emp['role']) ?></span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="modern-form-group">
                    <label>Tipe Reset</label>
                    <div class="radio-group-modern">
                        <label class="radio-card selected" id="card_default">
                            <span class="radio-icon">🔄</span>
                            <span>Kembali ke Sandi Default</span>
                            <input type="radio" name="reset_type" value="default" checked onchange="toggleResetType(this)">
                        </label>
                        
                        <label class="radio-card" id="card_new">
                            <span class="radio-icon">🔑</span>
                            <span>Ajukan Sandi Baru</span>
                            <input type="radio" name="reset_type" value="new" onchange="toggleResetType(this)">
                        </label>
                    </div>
                </div>

                <div class="modern-form-group" id="new-password-group" style="display: none; animation: slide-down 0.3s ease;">
                    <label for="new_password">Kata Sandi Baru</label>
                    <div class="modern-input-wrapper">
                        <span class="modern-input-icon">🔒</span>
                        <input type="password" name="new_password" id="new_password" class="modern-input" placeholder="Masukkan kata sandi baru...">
                        <button type="button" class="password-toggle-modern" id="togglePassword" aria-label="Tampilkan Password">
                            👁️
                        </button>
                    </div>
                </div>
                
                <button type="submit" class="modern-btn-submit">
                    <span>Ajukan Reset</span> 📩
                </button>
            </form>

            <div class="quick-actions">
                <div class="action-links">
                    <a href="index.php">← Kembali ke Halaman Login</a>
                </div>
            </div>
        </div>

    </div>

    <script>
        // --- Custom Select Dropdown Logic ---
        const customSelect = document.getElementById('customSelect');
        const selectTrigger = document.getElementById('selectTrigger');
        const options = document.querySelectorAll('.custom-option');
        const hiddenInput = document.getElementById('selected_employee_id');
        const selectedText = selectTrigger.querySelector('.selected-text');

        selectTrigger.addEventListener('click', function() {
            customSelect.classList.toggle('open');
        });

        options.forEach(option => {
            option.addEventListener('click', function() {
                const value = this.getAttribute('data-value');
                const name = this.querySelector('.opt-name').textContent;
                
                hiddenInput.value = value;
                selectedText.textContent = name;
                selectedText.style.color = 'white';

                options.forEach(opt => opt.classList.remove('selected'));
                this.classList.add('selected');

                customSelect.classList.remove('open');
            });
        });

        document.addEventListener('click', function(e) {
            if (!customSelect.contains(e.target)) {
                customSelect.classList.remove('open');
            }
        });

        // --- Radio Cards Logic & Password Input Toggle ---
        function toggleResetType(radioInput) {
            // Update styling on cards
            document.getElementById('card_default').classList.remove('selected');
            document.getElementById('card_new').classList.remove('selected');
            radioInput.parentElement.classList.add('selected');

            // Show/Hide password input
            const newPasswordGroup = document.getElementById('new-password-group');
            const newPasswordInput = document.getElementById('new_password');

            if (radioInput.value === 'new') {
                newPasswordGroup.style.display = 'block';
                newPasswordInput.setAttribute('required', 'required');
            } else {
                newPasswordGroup.style.display = 'none';
                newPasswordInput.removeAttribute('required');
                newPasswordInput.value = ''; // clear input
            }
        }

        // --- Password Toggle Logic ---
        const togglePasswordBtn = document.getElementById('togglePassword');
        const passwordInputElem = document.getElementById('new_password');

        togglePasswordBtn.addEventListener('click', function (e) {
            const type = passwordInputElem.getAttribute('type') === 'password' ? 'text' : 'password';
            passwordInputElem.setAttribute('type', type);
            this.style.opacity = type === 'text' ? '1' : '0.5';
            this.style.color = type === 'text' ? 'var(--primary-color)' : '';
        });
        
        // Prevent form submission if employee not selected
        document.getElementById('resetForm').addEventListener('submit', function(e) {
            if(!hiddenInput.value) {
                e.preventDefault();
                alert('Silakan pilih nama anggota terlebih dahulu.');
                customSelect.classList.add('open');
            }
        });
    </script>
</body>
</html>