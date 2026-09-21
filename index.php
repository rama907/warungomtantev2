<?php
require_once 'config.php';

// Handle login
if (($_POST['action'] ?? '') === 'login') {
    $name = $_POST['name'] ?? '';
    $password = $_POST['password'] ?? '';
    
    $stmt = $conn->prepare("SELECT * FROM employees WHERE name = ? AND status = 'active'");
    $stmt->bind_param("s", $name);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    
    if ($user && password_verify($password, $user['password'])) {
        session_regenerate_id(true); // cegah session fixation
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['name'] = $user['name'];
        $_SESSION['role'] = $user['role'];
        header('Location: dashboard');
        exit;
    } else {
        $error = "Nama atau password salah!";
    }
}

// Get all employees for dropdown
$employees = $conn->query("SELECT id, name, role, is_on_duty FROM employees WHERE status = 'active' ORDER BY name")->fetch_all(MYSQLI_ASSOC);

// Get total active employees
$total_employees = $conn->query("SELECT COUNT(*) as count FROM employees WHERE status = 'active'")->fetch_assoc()['count'];

// Get explicitly on duty employees and their names for the tooltip
$stmt_on_duty = $conn->query("SELECT name FROM employees WHERE is_on_duty = 1 ORDER BY name ASC");
$on_duty_employees_list = $stmt_on_duty->fetch_all(MYSQLI_ASSOC);
$on_duty_employees_count = count($on_duty_employees_list);
$off_duty_employees_count = $total_employees - $on_duty_employees_count;

// Determine if the club is open or closed and get the relevant timestamp
$status_is_open = $on_duty_employees_count > 0;
$status_timestamp = null;
$status_label = '';

if ($status_is_open) {
    $stmt = $conn->query("SELECT MIN(current_duty_start) AS first_on_duty_time FROM employees WHERE is_on_duty = 1");
    $status_timestamp = $stmt->fetch_assoc()['first_on_duty_time'];
    $status_label = 'Buka Sejak';
} else {
    $stmt = $conn->query("SELECT MAX(duty_end) AS last_off_duty_time FROM duty_logs WHERE status = 'completed'");
    $status_timestamp = $stmt->fetch_assoc()['last_off_duty_time'];
    $status_label = 'Tutup Sejak';
}

?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Warung Om Tante V2 | Beachside F&B Management</title>
    <link rel="icon" href="LOGO_WOT.png" type="image/png">
    <link rel="shortcut icon" href="favicon.ico" type="image/x-icon">
    <link rel="stylesheet" href="style.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        /* Modern Beachside F&B Professional Login UI */
        body.fnb-login-body {
            margin: 0;
            min-height: 100vh;
            /* Night Beach Theme Gradient */
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

        /* Floating background orbs for modern feel */
        .bg-orb {
            position: absolute;
            border-radius: 50%;
            filter: blur(80px);
            z-index: 0;
            animation: float-orb 10s infinite ease-in-out alternate;
        }
        .bg-orb.orb-1 { width: 400px; height: 400px; background: rgba(255, 193, 7, 0.15); top: -10%; left: -5%; }
        .bg-orb.orb-2 { width: 500px; height: 500px; background: rgba(14, 165, 233, 0.1); bottom: -10%; right: -5%; animation-delay: -5s; }

        @keyframes float-orb {
            0% { transform: translate(0, 0) scale(1); }
            100% { transform: translate(30px, -50px) scale(1.1); }
        }

        .fnb-login-container {
            display: flex;
            flex-direction: column;
            width: 100%;
            max-width: 1100px;
            background: rgba(30, 41, 59, 0.6);
            backdrop-filter: blur(20px);
            border-radius: 24px;
            box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5), 0 0 0 1px rgba(255,255,255,0.1);
            overflow: hidden;
            position: relative;
            z-index: 10;
            animation: container-fade-in 0.8s cubic-bezier(0.16, 1, 0.3, 1);
        }

        @keyframes container-fade-in {
            0% { opacity: 0; transform: translateY(40px) scale(0.98); }
            100% { opacity: 1; transform: translateY(0) scale(1); }
        }

        @media (min-width: 900px) {
            .fnb-login-container {
                flex-direction: row;
                min-height: 650px;
            }
        }

        /* --- Left Panel: Branding & Beach Waves --- */
        .fnb-left-panel {
            flex: 1.2;
            background: linear-gradient(145deg, rgba(2, 6, 23, 0.8), rgba(15, 23, 42, 0.9));
            padding: 3rem;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            border-right: 1px solid rgba(255,255,255,0.05);
            position: relative;
            overflow: hidden;
        }

        /* Animated Waves at the bottom of the left panel */
        .ocean-waves {
            position: absolute;
            bottom: 0;
            left: 0;
            width: 100%;
            height: 150px;
            overflow: hidden;
            z-index: 0;
            opacity: 0.6;
        }
        .wave {
            position: absolute;
            bottom: 0;
            left: 0;
            width: 200%;
            height: 100px;
            background: url('data:image/svg+xml;utf8,<svg viewBox="0 0 800 100" xmlns="http://www.w3.org/2000/svg"><path d="M0 50 Q 100 0 200 50 T 400 50 T 600 50 T 800 50 L 800 100 L 0 100 Z" fill="rgba(255,193,7,0.1)"/></svg>') repeat-x;
            background-size: 50% 100%;
            animation: wave-animation 10s linear infinite;
        }
        .wave:nth-child(2) {
            bottom: -15px;
            opacity: 0.5;
            animation: wave-animation 15s linear infinite reverse;
            background: url('data:image/svg+xml;utf8,<svg viewBox="0 0 800 100" xmlns="http://www.w3.org/2000/svg"><path d="M0 50 Q 100 20 200 50 T 400 50 T 600 50 T 800 50 L 800 100 L 0 100 Z" fill="rgba(14,165,233,0.15)"/></svg>') repeat-x;
            background-size: 50% 100%;
        }

        @keyframes wave-animation {
            0% { transform: translateX(0); }
            100% { transform: translateX(-50%); }
        }

        .fnb-left-content { position: relative; z-index: 1; }

        .fnb-brand {
            display: flex;
            align-items: center;
            gap: 1.5rem;
            margin-bottom: 2rem;
            animation: slide-right 0.6s ease-out 0.2s backwards;
        }

        .fnb-brand img {
            width: 85px;
            height: 85px;
            object-fit: contain;
            filter: drop-shadow(0 0 15px rgba(255, 193, 7, 0.3));
        }

        .fnb-brand h1 {
            font-size: 2rem;
            font-weight: 800;
            color: white;
            margin: 0 0 0.25rem 0;
            line-height: 1.1;
            letter-spacing: -0.02em;
        }

        .fnb-brand p {
            font-size: 1rem;
            color: var(--primary-color);
            margin: 0;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }

        .beach-quote {
            font-size: 1.1rem;
            color: var(--text-secondary);
            font-style: italic;
            margin-bottom: 3rem;
            line-height: 1.6;
            animation: slide-right 0.6s ease-out 0.4s backwards;
        }

        .fnb-status-widget {
            background: rgba(0, 0, 0, 0.4);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 20px;
            padding: 1.5rem;
            backdrop-filter: blur(10px);
            animation: slide-up 0.6s ease-out 0.6s backwards;
        }

        .widget-header {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 1.5rem;
        }

        /* Animasi Resto Buka Teks */
        .text-glow-open {
            color: white;
            animation: text-glow-pulse 2s infinite alternate;
        }

        @keyframes text-glow-pulse {
            0% { text-shadow: 0 0 5px rgba(16, 185, 129, 0.3); }
            100% { text-shadow: 0 0 15px rgba(16, 185, 129, 0.8), 0 0 25px rgba(16, 185, 129, 0.4); }
        }

        .status-dot-large {
            width: 16px;
            height: 16px;
            border-radius: 50%;
        }

        .status-dot-large.open {
            background-color: var(--success-color);
            box-shadow: 0 0 15px var(--success-color);
            animation: pulse-dot 2s infinite;
        }

        .status-dot-large.closed {
            background-color: var(--danger-color);
            box-shadow: 0 0 15px rgba(220, 53, 69, 0.8);
        }

        .widget-header h3 {
            margin: 0;
            font-size: 1.25rem;
            font-weight: 600;
            color: white;
        }

        .widget-body {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            margin-bottom: 1.25rem;
        }

        /* Tooltip Container untuk Daftar On Duty */
        .stat-box {
            background: rgba(255, 255, 255, 0.03);
            padding: 1rem;
            border-radius: 14px;
            border: 1px solid rgba(255, 255, 255, 0.05);
            text-align: center;
            transition: transform 0.3s ease, background 0.3s ease;
            position: relative; /* Untuk Tooltip */
        }
        
        /* Box interaktif jika ada yang on duty */
        .stat-box.interactive {
            cursor: help;
        }

        .stat-box:hover {
            transform: translateY(-2px);
            background: rgba(255, 255, 255, 0.08);
        }

        .stat-box .label {
            display: block;
            font-size: 0.75rem;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 0.5rem;
            font-weight: 600;
        }

        .stat-box .value {
            font-size: 2rem;
            font-weight: 800;
        }
        .text-success { color: #34d399; }
        .text-danger { color: #f87171; }

        /* --- Tooltip CSS --- */
        .duty-tooltip {
            visibility: hidden;
            opacity: 0;
            width: max-content;
            max-width: 250px;
            background: rgba(15, 23, 42, 0.95);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(16, 185, 129, 0.3);
            color: #fff;
            text-align: left;
            border-radius: 12px;
            padding: 0.75rem 1rem;
            position: absolute;
            z-index: 100;
            bottom: 110%; /* Posisi di atas box */
            left: 50%;
            transform: translateX(-50%) translateY(10px);
            transition: opacity 0.3s, transform 0.3s;
            box-shadow: 0 10px 25px rgba(0,0,0,0.6);
            font-size: 0.85rem;
            pointer-events: none;
            max-height: 180px;
            overflow-y: auto;
        }

        /* Panah Tooltip */
        .duty-tooltip::after {
            content: "";
            position: absolute;
            top: 100%;
            left: 50%;
            margin-left: -6px;
            border-width: 6px;
            border-style: solid;
            border-color: rgba(15, 23, 42, 0.95) transparent transparent transparent;
        }

        /* Custom Scrollbar Tooltip */
        .duty-tooltip::-webkit-scrollbar { width: 4px; }
        .duty-tooltip::-webkit-scrollbar-track { background: transparent; }
        .duty-tooltip::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.2); border-radius: 4px; }

        .stat-box.interactive:hover .duty-tooltip {
            visibility: visible;
            opacity: 1;
            transform: translateX(-50%) translateY(0);
        }

        .tooltip-title {
            color: var(--success-color);
            font-weight: 700;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 0.5rem;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            padding-bottom: 0.25rem;
        }

        .tooltip-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .tooltip-list li {
            padding: 4px 0;
            display: flex;
            align-items: center;
            gap: 8px;
            color: #e2e8f0;
        }

        .widget-footer {
            text-align: center;
            font-size: 0.85rem;
            color: var(--text-muted);
            border-top: 1px solid rgba(255, 255, 255, 0.05);
            padding-top: 1rem;
            display: flex;
            justify-content: center;
            gap: 0.5rem;
        }
        
        .widget-footer .time-value {
            color: var(--primary-color);
            font-weight: 600;
            font-family: "SF Mono", "Roboto Mono", monospace;
        }

        /* --- Right Panel: Login Form --- */
        .fnb-right-panel {
            flex: 1;
            padding: 4rem 3.5rem;
            display: flex;
            flex-direction: column;
            justify-content: center;
            background: rgba(15, 23, 42, 0.6);
        }

        .login-header-text {
            margin-bottom: 3rem;
            animation: slide-up 0.6s ease-out 0.2s backwards;
        }

        .login-header-text h2 {
            font-size: 2.25rem;
            font-weight: 700;
            color: white;
            margin: 0 0 0.5rem 0;
            letter-spacing: -0.02em;
        }

        .login-header-text p {
            color: var(--text-secondary);
            font-size: 1.05rem;
            margin: 0;
        }

        .modern-form-group {
            margin-bottom: 1.75rem;
            position: relative;
            animation: slide-up 0.6s ease-out 0.4s backwards;
        }

        .modern-form-group label {
            display: block;
            font-size: 0.9rem;
            font-weight: 600;
            color: var(--text-secondary);
            margin-bottom: 0.5rem;
        }

        .modern-input-wrapper {
            position: relative;
            display: flex;
            align-items: center;
        }

        .modern-input-icon {
            position: absolute;
            left: 1.25rem;
            font-size: 1.2rem;
            opacity: 0.7;
            pointer-events: none;
            z-index: 2;
        }

        .modern-input {
            width: 100%;
            background: rgba(0, 0, 0, 0.3);
            border: 2px solid rgba(255, 255, 255, 0.1);
            color: white;
            padding: 1.1rem 1.1rem 1.1rem 3.5rem;
            border-radius: 16px;
            font-size: 1.05rem;
            font-family: inherit;
            transition: all 0.3s ease;
        }

        .modern-input:focus {
            outline: none;
            border-color: var(--primary-color);
            background: rgba(0, 0, 0, 0.5);
            box-shadow: 0 0 0 4px rgba(255, 193, 7, 0.15);
        }

        /* --- Custom Select Dropdown UI --- */
        .custom-select-container {
            position: relative;
            width: 100%;
        }

        .custom-select-trigger {
            width: 100%;
            background: rgba(0, 0, 0, 0.3);
            border: 2px solid rgba(255, 255, 255, 0.1);
            color: white;
            padding: 1.1rem 1.1rem 1.1rem 3.5rem;
            border-radius: 16px;
            font-size: 1.05rem;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: space-between;
            transition: all 0.3s ease;
            user-select: none;
        }

        .custom-select-trigger:hover, .custom-select-container.open .custom-select-trigger {
            border-color: var(--primary-color);
            background: rgba(0, 0, 0, 0.5);
        }

        .custom-select-container.open .custom-select-trigger {
            border-bottom-left-radius: 0;
            border-bottom-right-radius: 0;
            box-shadow: 0 0 0 4px rgba(255, 193, 7, 0.15);
        }

        .custom-options {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: rgba(15, 23, 42, 0.95);
            backdrop-filter: blur(20px);
            border: 2px solid var(--primary-color);
            border-top: none;
            border-bottom-left-radius: 16px;
            border-bottom-right-radius: 16px;
            max-height: 250px;
            overflow-y: auto;
            z-index: 100;
            opacity: 0;
            visibility: hidden;
            transform: translateY(-10px);
            transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
            box-shadow: 0 10px 25px rgba(0,0,0,0.5);
        }

        .custom-select-container.open .custom-options {
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
        }

        .custom-option {
            padding: 1rem 1.25rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            cursor: pointer;
            border-bottom: 1px solid rgba(255,255,255,0.05);
            transition: background 0.2s ease;
        }

        .custom-option:hover { background: rgba(255, 255, 255, 0.1); }
        .custom-option:last-child { border-bottom: none; }
        .custom-option.selected { background: rgba(255, 193, 7, 0.1); }

        .opt-info { display: flex; flex-direction: column; gap: 4px; }
        .opt-name { font-weight: 600; color: white; }
        .opt-role { font-size: 0.75rem; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 0.05em; }
        
        /* Animated On-Duty Badge inside dropdown */
        .opt-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: rgba(16, 185, 129, 0.15);
            border: 1px solid rgba(16, 185, 129, 0.3);
            color: #34d399;
            padding: 4px 10px;
            border-radius: 99px;
            font-size: 0.75rem;
            font-weight: 700;
        }
        .live-dot {
            width: 8px;
            height: 8px;
            background-color: #34d399;
            border-radius: 50%;
            box-shadow: 0 0 8px #34d399;
            animation: pulse-dot 1.5s infinite;
        }

        /* Custom Scrollbar for options */
        .custom-options::-webkit-scrollbar { width: 6px; }
        .custom-options::-webkit-scrollbar-track { background: rgba(0,0,0,0.2); }
        .custom-options::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.2); border-radius: 10px; }
        .custom-options::-webkit-scrollbar-thumb:hover { background: var(--primary-color); }

        .password-toggle-modern {
            position: absolute;
            right: 1.25rem;
            background: none;
            border: none;
            font-size: 1.2rem;
            opacity: 0.5;
            cursor: pointer;
            padding: 0.2rem;
            transition: all 0.3s ease;
        }
        .password-toggle-modern:hover { opacity: 1; color: var(--primary-color); transform: scale(1.1); }

        .modern-btn-submit {
            width: 100%;
            background: linear-gradient(135deg, var(--primary-color), var(--primary-hover));
            color: #121212;
            border: none;
            padding: 1.2rem;
            border-radius: 16px;
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
            box-shadow: 0 10px 25px rgba(255, 193, 7, 0.3);
            animation: slide-up 0.6s ease-out 0.6s backwards;
        }

        .modern-btn-submit:hover {
            transform: translateY(-3px);
            box-shadow: 0 15px 35px rgba(255, 193, 7, 0.5);
        }
        .modern-btn-submit:active { transform: translateY(0); }

        .error-alert {
            background: rgba(220, 53, 69, 0.15);
            border: 1px solid rgba(220, 53, 69, 0.4);
            color: #fca5a5;
            padding: 1rem 1.2rem;
            border-radius: 14px;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-size: 0.95rem;
            font-weight: 500;
            animation: shake 0.5s ease-in-out;
        }

        .quick-actions {
            margin-top: 3rem;
            text-align: center;
            border-top: 1px solid rgba(255,255,255,0.1);
            padding-top: 1.5rem;
            animation: slide-up 0.6s ease-out 0.8s backwards;
        }

        .action-links {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 1.25rem;
            font-size: 0.95rem;
            font-weight: 500;
        }

        .action-links a {
            color: var(--text-secondary);
            text-decoration: none;
            transition: color 0.3s ease;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .action-links a:hover { color: var(--primary-color); }
        .action-links .divider { color: rgba(255,255,255,0.2); }

        /* Keyframes */
        @keyframes slide-right {
            from { opacity: 0; transform: translateX(-20px); }
            to { opacity: 1; transform: translateX(0); }
        }
        @keyframes slide-up {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-5px); }
            75% { transform: translateX(5px); }
        }

        /* Responsive */
        @media (max-width: 900px) {
            .fnb-left-panel, .fnb-right-panel { padding: 2.5rem 2rem; }
            .fnb-brand img { width: 65px; height: 65px; }
            .fnb-brand h1 { font-size: 1.5rem; }
            .login-header-text h2 { font-size: 1.75rem; }
            .bg-orb { display: none; } /* Hide orbs on mobile for performance */
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
                        <p>Manajemen Warung Om Tante</p>
                    </div>
                </div>

                <div class="beach-quote">
                    "Menyajikan kehangatan rasa dengan hembusan angin pantai dan gemuruh ombak."
                </div>

                <div class="fnb-status-widget">
                    <div class="widget-header">
                        <div class="status-dot-large <?= $status_is_open ? 'open' : 'closed' ?>"></div>
                        <h3 class="<?= $status_is_open ? 'text-glow-open' : '' ?>">
                            <?= $status_is_open ? 'Resto Sedang Buka' : 'Resto Sedang Tutup' ?>
                        </h3>
                    </div>
                    <div class="widget-body">
                        <div class="stat-box <?= $on_duty_employees_count > 0 ? 'interactive' : '' ?>">
                            <span class="label">Anggota On Duty</span>
                            <span class="value text-success"><?= $on_duty_employees_count ?></span>
                            
                            <?php if ($on_duty_employees_count > 0): ?>
                                <div class="duty-tooltip">
                                    <div class="tooltip-title">Sedang Bertugas:</div>
                                    <ul class="tooltip-list">
                                        <?php foreach($on_duty_employees_list as $emp_duty): ?>
                                            <li><span class="live-dot" style="width: 6px; height: 6px;"></span> <?= htmlspecialchars($emp_duty['name']) ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="stat-box">
                            <span class="label">Anggota Off Duty</span>
                            <span class="value text-danger"><?= $off_duty_employees_count ?></span>
                        </div>
                    </div>
                    <?php if ($status_timestamp): ?>
                        <div class="widget-footer">
                            <span><?= $status_label ?> sejak:</span>
                            <span class="time-value"><?= date('d M Y, H:i', strtotime($status_timestamp)) ?></span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="ocean-waves">
                <div class="wave"></div>
                <div class="wave"></div>
            </div>
        </div>

        <div class="fnb-right-panel">
            <div class="login-header-text">
                <h2>Selamat Datang</h2>
                <p>Masuk untuk memulai shift Anda hari ini.</p>
            </div>

            <?php if (isset($error)): ?>
                <div class="error-alert">
                    <span>⚠️</span>
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <form method="POST" id="loginForm">
                <input type="hidden" name="action" value="login">
                <input type="hidden" name="name" id="selected_name" required>
                
                <div class="modern-form-group">
                    <label>Nama Anggota</label>
                    <div class="modern-input-wrapper">
                        <span class="modern-input-icon">👤</span>
                        
                        <div class="custom-select-container" id="customSelect">
                            <div class="custom-select-trigger" id="selectTrigger">
                                <span class="selected-text" style="color: rgba(255,255,255,0.5);">Pilih Nama Anda...</span>
                                <span>▼</span>
                            </div>
                            <div class="custom-options">
                                <?php foreach ($employees as $emp): ?>
                                    <div class="custom-option" data-value="<?= htmlspecialchars($emp['name']) ?>">
                                        <div class="opt-info">
                                            <span class="opt-name"><?= htmlspecialchars($emp['name']) ?></span>
                                            <span class="opt-role"><?= getRoleDisplayName($emp['role']) ?></span>
                                        </div>
                                        <?php if ($emp['is_on_duty']): ?>
                                            <div class="opt-badge">
                                                <span class="live-dot"></span> On Duty
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                    </div>
                </div>

                <div class="modern-form-group">
                    <label for="password">Password</label>
                    <div class="modern-input-wrapper">
                        <span class="modern-input-icon">🔒</span>
                        <input type="password" name="password" id="password" required class="modern-input" placeholder="Masukkan sandi akses...">
                        <button type="button" class="password-toggle-modern" id="togglePassword" aria-label="Tampilkan Password">
                            👁️
                        </button>
                    </div>
                </div>

                <button type="submit" class="modern-btn-submit">
                    <span>Mulai Shift</span> 🏖️
                </button>
            </form>

            <div class="quick-actions">
                <div class="action-links">
                    <a href="add-employee-request">📝 Daftar Baru</a>
                    <span class="divider">|</span>
                    <a href="reset-password-request">🔑 Lupa Sandi?</a>
                </div>
            </div>
        </div>

    </div>

    <script>
        // --- Password Toggle Logic ---
        const togglePassword = document.getElementById('togglePassword');
        const passwordInput = document.getElementById('password');

        togglePassword.addEventListener('click', function (e) {
            const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
            passwordInput.setAttribute('type', type);
            this.style.opacity = type === 'text' ? '1' : '0.5';
            this.style.color = type === 'text' ? 'var(--primary-color)' : '';
        });

        // --- Custom Select Dropdown Logic ---
        const customSelect = document.getElementById('customSelect');
        const selectTrigger = document.getElementById('selectTrigger');
        const options = document.querySelectorAll('.custom-option');
        const hiddenInput = document.getElementById('selected_name');
        const selectedText = selectTrigger.querySelector('.selected-text');

        // Toggle dropdown open/close
        selectTrigger.addEventListener('click', function() {
            customSelect.classList.toggle('open');
        });

        // Handle option click
        options.forEach(option => {
            option.addEventListener('click', function() {
                // Get data
                const value = this.getAttribute('data-value');
                const name = this.querySelector('.opt-name').textContent;
                
                // Update hidden input and trigger text
                hiddenInput.value = value;
                selectedText.textContent = name;
                selectedText.style.color = 'white';

                // Update selected styling
                options.forEach(opt => opt.classList.remove('selected'));
                this.classList.add('selected');

                // Close dropdown
                customSelect.classList.remove('open');
            });
        });

        // Close dropdown when clicking outside
        document.addEventListener('click', function(e) {
            if (!customSelect.contains(e.target)) {
                customSelect.classList.remove('open');
            }
        });
        
        // Prevent form submission if name is empty
        document.getElementById('loginForm').addEventListener('submit', function(e) {
            if(!hiddenInput.value) {
                e.preventDefault();
                alert('Silakan pilih nama anggota terlebih dahulu.');
                customSelect.classList.add('open');
            }
        });
    </script>
</body>
</html>