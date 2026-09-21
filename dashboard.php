<?php
require_once 'config.php';

if (!isLoggedIn()) {
    header('Location: /');
    exit;
}
$user = getCurrentUser();

// Ambil jumlah permohonan pending untuk indikator sidebar
$pending_requests_count = getPendingRequestCount();

// Ambil status surat peringatan terbaru dari database
$warning_status = null;
$stmt_sp = $conn->prepare("
    SELECT sp_type
    FROM warning_letters
    WHERE employee_id = ?
    ORDER BY issued_at DESC
    LIMIT 1
");
if ($stmt_sp) {
    $stmt_sp->bind_param("i", $user['id']);
    $stmt_sp->execute();
    $result_sp = $stmt_sp->get_result();

    if ($result_sp->num_rows > 0) {
        $warning_status = $result_sp->fetch_assoc()['sp_type'];
    }
    $stmt_sp->close();
}

// --- HANDLE DUTY ACTIONS (CLOCK IN/OUT) ---
if (isset($_POST['action']) && $_POST['action'] === 'on_duty') {
    if (!$user['is_on_duty']) {
        $stmt = $conn->prepare("UPDATE employees SET is_on_duty = TRUE, current_duty_start = NOW() WHERE id = ?");
        $stmt->bind_param("i", $user['id']);
        $stmt->execute();
        
        $stmt = $conn->prepare("INSERT INTO duty_logs (employee_id, duty_start, is_manual, status) VALUES (?, NOW(), 0, 'active')");
        $stmt->bind_param("i", $user['id']);
        $stmt->execute();
        
        sendDiscordNotification(['employee_name' => $user['name'], 'event_type' => 'clock_in'], 'clock_event');
        header('Location: dashboard.php');
        exit;
    }
}

if (isset($_POST['action']) && $_POST['action'] === 'off_duty') {
    if ($user['is_on_duty']) {
        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("SELECT id, duty_start FROM duty_logs WHERE employee_id = ? AND duty_end IS NULL ORDER BY id DESC LIMIT 1");
            $stmt->bind_param("i", $user['id']);
            $stmt->execute();
            $log = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            
            if ($log) {
                $stmt_update_log = $conn->prepare("
                    UPDATE duty_logs 
                    SET duty_end = NOW(), 
                        duration_minutes = TIMESTAMPDIFF(MINUTE, duty_start, NOW()), 
                        status = 'completed' 
                    WHERE id = ?
                ");
                $stmt_update_log->bind_param("i", $log['id']);
                $stmt_update_log->execute();
                $stmt_update_log->close();
            }
            
            $stmt_update_employee = $conn->prepare("UPDATE employees SET is_on_duty = FALSE, current_duty_start = NULL WHERE id = ?");
            $stmt_update_employee->bind_param("i", $user['id']);
            $stmt_update_employee->execute();
            $stmt_update_employee->close();
            
            $conn->commit();

            $stmt_log_duration = $conn->prepare("SELECT duration_minutes FROM duty_logs WHERE employee_id = ? AND status = 'completed' ORDER BY id DESC LIMIT 1");
            $stmt_log_duration->bind_param("i", $user['id']);
            $stmt_log_duration->execute();
            $last_log = $stmt_log_duration->get_result()->fetch_assoc();
            $stmt_log_duration->close();
            
            $duration_text = formatDuration($last_log['duration_minutes'] ?? 0);
            sendDiscordNotification(['employee_name' => $user['name'], 'event_type' => 'clock_out', 'duration' => $duration_text], 'clock_event');
            
            header('Location: dashboard.php');
            exit;

        } catch (Exception $e) {
            $conn->rollback();
            header('Location: dashboard.php?msg=' . urlencode('Error: ' . $e->getMessage()) . '&type=error');
            exit;
        }
    }
}

// Get Total Jam Kerja
$stmt = $conn->prepare("SELECT SUM(duration_minutes) as total_minutes FROM duty_logs WHERE employee_id = ? AND status = 'completed'");
$stmt->bind_param("i", $user['id']);
$stmt->execute();
$total_minutes = $stmt->get_result()->fetch_assoc()['total_minutes'] ?? 0;
$stmt->close();

// === STATISTIK BARU: TOTAL PENJUALAN ===
$total_paket_terjual_dashboard = 0;
$stmt_sales = $conn->prepare("SELECT SUM(paket_abdul + paket_lacosa + paket_lakse + paket_saiyo + paket_woku + hp + radio) as total_sales FROM sales_data WHERE employee_id = ?");
if ($stmt_sales) {
    $stmt_sales->bind_param("i", $user['id']);
    $stmt_sales->execute();
    $res_sales = $stmt_sales->get_result()->fetch_assoc();
    $total_paket_terjual_dashboard = $res_sales['total_sales'] ?? 0;
    $stmt_sales->close();
}

// === STATISTIK BARU: TOTAL MASAK ===
$total_paket_masak_dashboard = 0;
$stmt_cooking = $conn->prepare("SELECT SUM(paket_abdul + paket_lacosa + paket_lakse + paket_saiyo + paket_woku) as total_cooking FROM cooking_data WHERE employee_id = ?");
if ($stmt_cooking) {
    $stmt_cooking->bind_param("i", $user['id']);
    $stmt_cooking->execute();
    $res_cooking = $stmt_cooking->get_result()->fetch_assoc();
    $total_paket_masak_dashboard = $res_cooking['total_cooking'] ?? 0;
    $stmt_cooking->close();
}

// === DATA ANGGOTA YANG SEDANG ON DUTY ===
$stmt_onduty = $conn->prepare("SELECT name, role FROM employees WHERE is_on_duty = 1 ORDER BY current_duty_start DESC");
$stmt_onduty->execute();
$active_onduty_employees = $stmt_onduty->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt_onduty->close();

// Get recent activities
$stmt = $conn->prepare("SELECT * FROM duty_logs WHERE employee_id = ? ORDER BY duty_start DESC LIMIT 5");
$stmt->bind_param("i", $user['id']);
$stmt->execute();
$recent_activities = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Long duty alert check
$long_duty_alert_dashboard = false;
if ($user['is_on_duty'] && $user['current_duty_start']) {
    $start = new DateTime($user['current_duty_start']);
    $now = new DateTime();
    $current_duty_duration_seconds = $now->getTimestamp() - $start->getTimestamp();
    if ($current_duty_duration_seconds > (5 * 3600)) {
        $long_duty_alert_dashboard = true;
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Warung Om Tante V2</title>
    <link rel="icon" href="LOGO_WOT.png" type="image/png">
    <link rel="shortcut icon" href="favicon.ico" type="image/x-icon">
    <link rel="stylesheet" href="style.css">
    <style>
        /* ========================================================
           MODERN DASHBOARD OVERRIDES (Sync with Night Beach Theme)
           ======================================================== */
        
        .main-content {
            background: linear-gradient(135deg, #0f172a 0%, #1e1b4b 100%) !important;
            color: #f8f9fa;
            min-height: 100vh;
            overflow-x: hidden;
        }

        .dashboard-header {
            margin-bottom: 1.5rem;
            animation: slideDown 0.6s cubic-bezier(0.16, 1, 0.3, 1);
        }
        
        .profile-avatar {
            width: 85px; height: 85px;
            background: rgba(255, 255, 255, 0.05) !important;
            border-radius: 50% !important; 
            border: 2px solid rgba(255, 193, 7, 0.4) !important;
            box-shadow: 0 0 20px rgba(255, 193, 7, 0.15) !important;
            padding: 10px; display: flex; align-items: center; justify-content: center;
            overflow: hidden; position: relative; backdrop-filter: blur(5px);
        }
        .profile-avatar img.logo-img {
            width: 100%; height: 100%; object-fit: contain;
            filter: drop-shadow(0 2px 4px rgba(0,0,0,0.5));
            animation: pulse-avatar 3s infinite alternate ease-in-out;
        }
        @keyframes pulse-avatar { 0% { transform: scale(0.95); } 100% { transform: scale(1.05); } }

        .profile-info h1 { font-size: 2rem; font-weight: 800; color: #fff; margin-bottom: var(--spacing-xs); letter-spacing: -0.02em; }
        .user-details { color: var(--text-secondary); font-size: 1rem; }

        /* --- USER CARD (Animated Background & Glassmorphism) --- */
        .user-card {
            background: linear-gradient(-45deg, #0f172a, #1e1b4b, #172554, #31111d) !important;
            background-size: 400% 400% !important;
            animation: gradientBG 15s ease infinite, slideUp 0.6s ease-out 0.1s backwards !important;
            border: 1px solid rgba(255, 255, 255, 0.1) !important;
            border-radius: 24px;
            box-shadow: 0 15px 40px rgba(0,0,0,0.4) !important;
            position: relative;
            overflow: hidden;
            margin-bottom: 2rem;
            width: 100%;
        }
        @keyframes gradientBG {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }

        .user-card::after {
            content: ''; position: absolute; bottom: 0; left: 0; right: 0; height: 120px;
            background: url('data:image/svg+xml;utf8,<svg viewBox="0 0 1440 320" xmlns="http://www.w3.org/2000/svg"><path fill="rgba(255,255,255,0.02)" d="M0,160L48,176C96,192,192,224,288,213.3C384,203,480,149,576,144C672,139,768,181,864,186.7C960,192,1056,160,1152,138.7C1248,117,1344,107,1392,101.3L1440,96L1440,320L1392,320C1344,320,1248,320,1152,320C1056,320,960,320,864,320C768,320,672,320,576,320C480,320,384,320,288,320C192,320,96,320,48,320L0,320Z"></path></svg>') no-repeat bottom;
            background-size: cover; z-index: 0; pointer-events: none;
        }

        .user-card-content { 
            position: relative; z-index: 1; padding: 2.5rem; 
            display: flex; justify-content: space-between; align-items: center; 
            flex-wrap: wrap; gap: 30px; 
        }
        
        .user-info { flex: 1; min-width: 250px; }
        .user-info h2 { color: #fff; font-size: 2rem; font-weight: 800; margin-bottom: 0.25rem; letter-spacing: -0.02em; }
        .user-role { color: var(--primary-color); font-weight: 700; letter-spacing: 0.08em; text-transform: uppercase; font-size: 0.9rem;}
        
        /* --- MODERN DUTY DONGLE (Dynamic Island Style) --- */
        .modern-duty-badge {
            display: inline-flex; align-items: center; background: rgba(0, 0, 0, 0.4);
            border-radius: 50px; padding: 6px 8px 6px 14px; border: 1px solid rgba(255, 255, 255, 0.1);
            margin-top: 1.25rem; backdrop-filter: blur(10px); box-shadow: 0 4px 15px rgba(0,0,0,0.3); transition: all 0.3s ease;
        }
        .modern-duty-badge.active {
            border-color: rgba(16, 185, 129, 0.5); background: linear-gradient(90deg, rgba(16, 185, 129, 0.15), rgba(0, 0, 0, 0.5));
            box-shadow: 0 0 20px rgba(16, 185, 129, 0.2);
        }
        
        .duty-icon { display: flex; align-items: center; justify-content: center; }
        .live-dot-recording {
            display: inline-block; width: 12px; height: 12px; background: #ef4444; 
            border-radius: 50%; box-shadow: 0 0 10px #ef4444; animation: blink-red 1s infinite;
        }
        @keyframes blink-red { 0%, 100% { opacity: 1; } 50% { opacity: 0.3; } }

        .duty-text { font-size: 0.85rem; font-weight: 700; color: #94a3b8; letter-spacing: 0.05em; margin-left: 10px; margin-right: 10px; }
        .modern-duty-badge.active .duty-text { color: #34d399; }
        .duty-divider { width: 1px; height: 24px; background: rgba(255,255,255,0.2); margin: 0 10px; }
        .duty-clock-display {
            background: rgba(0, 0, 0, 0.7); color: #ffc107; font-family: 'SF Mono', 'Roboto Mono', 'Courier New', monospace;
            padding: 6px 14px; border-radius: 30px; font-size: 1rem; font-weight: 800; letter-spacing: 2px;
            box-shadow: inset 0 0 10px rgba(0,0,0,0.8); border: 1px solid rgba(255, 193, 7, 0.3); min-width: 100px; text-align: center;
        }

        /* --- WIDGET WAKTU & VIBE (Kanan) --- */
        .user-card-extra {
            background: rgba(0, 0, 0, 0.25); padding: 1.5rem 2rem; border-radius: 20px;
            border: 1px solid rgba(255, 255, 255, 0.08); text-align: right; backdrop-filter: blur(15px);
            display: flex; flex-direction: column; align-items: flex-end; justify-content: center;
            position: relative; overflow: hidden; box-shadow: inset 0 0 20px rgba(0,0,0,0.3);
            flex: 1 1 auto; max-width: 400px;
        }
        .user-card-extra::after {
            content: ''; position: absolute; top: 0; left: -100%; width: 50%; height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.05), transparent);
            transform: skewX(-20deg); animation: shimmer-box 6s infinite;
        }
        @keyframes shimmer-box { 0% { left: -100%; } 50%, 100% { left: 200%; } }

        .clock-time {
            font-size: 3rem; font-weight: 800; color: #fff; font-family: 'SF Mono', 'Roboto Mono', monospace;
            letter-spacing: 2px; text-shadow: 0 0 20px rgba(255, 193, 7, 0.5); line-height: 1; margin-bottom: 0.5rem; position: relative; z-index: 2;
        }
        .clock-date { color: var(--primary-color); font-size: 0.95rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.15em; margin-bottom: 1rem; position: relative; z-index: 2; }
        .weather-vibe {
            display: inline-flex; align-items: center; gap: 8px; background: rgba(255,255,255,0.1);
            padding: 6px 16px; border-radius: 20px; font-size: 0.85rem; color: #fff; font-weight: 600;
            border: 1px solid rgba(255,255,255,0.15); position: relative; z-index: 2; white-space: nowrap;
        }
        .weather-vibe .animated-icon { font-size: 1.3rem; animation: float-icon 3s ease-in-out infinite alternate; }
        @keyframes float-icon { 0% { transform: translateY(0px) rotate(0deg); } 100% { transform: translateY(-4px) rotate(15deg); } }

        /* --- SISA STYLING DASHBOARD --- */
        .action-buttons { display: flex; gap: 1rem; flex-wrap: wrap; margin-bottom: 2rem; animation: slideUp 0.6s ease-out 0.2s backwards; width: 100%;}
        .action-buttons .btn, .action-buttons button {
            border-radius: 14px; padding: 0.8rem 1.25rem; font-weight: 700; backdrop-filter: blur(10px);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); display: inline-flex; align-items: center; gap: 8px; text-decoration: none; flex: 1 1 auto; justify-content: center;
        }
        .action-buttons form { flex: 1 1 auto; display: flex; }
        .action-buttons form button { width: 100%; }

        .action-buttons .btn-success { background: rgba(16, 185, 129, 0.15); color: #34d399; border: 1px solid rgba(16, 185, 129, 0.4); }
        .action-buttons .btn-success:hover:not(:disabled) { background: rgba(16, 185, 129, 0.3); transform: translateY(-3px); box-shadow: 0 8px 20px rgba(16, 185, 129, 0.2); border-color: #34d399; }
        .action-buttons .btn-warning { background: rgba(245, 158, 11, 0.15); color: #fbbf24; border: 1px solid rgba(245, 158, 11, 0.4); }
        .action-buttons .btn-warning:hover:not(:disabled) { background: rgba(245, 158, 11, 0.3); transform: translateY(-3px); box-shadow: 0 8px 20px rgba(245, 158, 11, 0.2); border-color: #fbbf24; }
        .action-buttons .btn-primary { background: rgba(59, 130, 246, 0.15); color: #60a5fa; border: 1px solid rgba(59, 130, 246, 0.4); }
        .action-buttons .btn-primary:hover { background: rgba(59, 130, 246, 0.3); transform: translateY(-3px); box-shadow: 0 8px 20px rgba(59, 130, 246, 0.2); border-color: #60a5fa; }
        .action-buttons .btn-info { background: rgba(14, 165, 233, 0.15); color: #38bdf8; border: 1px solid rgba(14, 165, 233, 0.4); }
        .action-buttons .btn-info:hover { background: rgba(14, 165, 233, 0.3); transform: translateY(-3px); box-shadow: 0 8px 20px rgba(14, 165, 233, 0.2); border-color: #38bdf8; }
        .action-buttons .btn-danger { background: rgba(239, 68, 68, 0.15); color: #f87171; border: 1px solid rgba(239, 68, 68, 0.4); }
        .action-buttons .btn-danger:hover { background: rgba(239, 68, 68, 0.3); transform: translateY(-3px); box-shadow: 0 8px 20px rgba(239, 68, 68, 0.2); border-color: #f87171; }

        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 1.5rem; margin-bottom: 2rem; width: 100%;}
        .stat-card {
            background: rgba(30, 41, 59, 0.6); backdrop-filter: blur(16px); border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 20px; padding: 1.5rem; transition: transform 0.3s ease, box-shadow 0.3s ease, background 0.3s;
            animation: slideUp 0.6s ease-out 0.3s backwards; display: flex; align-items: center;
        }
        .stat-card:hover { transform: translateY(-5px); box-shadow: 0 15px 30px rgba(0,0,0,0.4); background: rgba(255, 255, 255, 0.05); border-color: rgba(255,193,7,0.3); }
        .stat-icon {
            background: rgba(255, 255, 255, 0.05); width: 50px; height: 50px; border-radius: 14px;
            display: flex; align-items: center; justify-content: center; font-size: 1.5rem; margin-right: 15px; border: 1px solid rgba(255,255,255,0.1);
        }
        .stat-content h3 { color: var(--text-secondary); font-size: 0.9rem; font-weight: 600; text-transform: uppercase; margin-bottom: 0.2rem;}
        .stat-value { color: #fff; font-size: 1.5rem; font-weight: 800; }

        .recent-activities {
            background: rgba(30, 41, 59, 0.6); backdrop-filter: blur(16px); border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 20px; padding: 1.5rem; animation: slideUp 0.6s ease-out 0.4s backwards; width: 100%;
        }
        .recent-activities h3 { color: #fff; border-bottom: 1px solid rgba(255,255,255,0.1); padding-bottom: 1rem; margin-bottom: 1.5rem;}
        .activity-item {
            background: rgba(0, 0, 0, 0.2); border: 1px solid rgba(255, 255, 255, 0.05); border-radius: 14px;
            margin-bottom: 1rem; padding: 1rem 1.2rem; transition: background 0.3s, transform 0.3s;
        }
        .activity-item:hover { background: rgba(255, 255, 255, 0.05); transform: translateX(5px); border-color: rgba(255,255,255,0.1); }
        .activity-date { color: var(--text-secondary); font-weight: 600; margin-bottom: 0.25rem; font-size: 0.9rem;}
        .activity-time { color: #fff; font-size: 1.1rem; font-weight: 700; margin-bottom: 0.5rem; }
        .activity-duration { color: var(--text-muted); font-size: 0.85rem; line-height: 1.6; }

        /* --- LIVE ON DUTY WIDGET --- */
        .live-duty-widget {
            background: linear-gradient(90deg, rgba(16, 185, 129, 0.1), rgba(30, 41, 59, 0.6));
            border: 1px solid rgba(16, 185, 129, 0.3); border-radius: 16px; padding: 12px 20px;
            margin-bottom: var(--spacing-xl); box-shadow: 0 4px 20px rgba(0, 0, 0, 0.2), inset 0 0 10px rgba(16, 185, 129, 0.05);
            display: flex; align-items: center; gap: 15px; position: relative; overflow: hidden;
            animation: slideUp 0.6s ease-out 0.15s backwards; backdrop-filter: blur(10px); width: 100%;
        }
        .live-title-container {
            display: flex; align-items: center; gap: 10px; font-weight: 800; color: #34d399; font-size: 0.95rem;
            white-space: nowrap; border-right: 1px solid rgba(255,255,255,0.1); padding-right: 15px; text-transform: uppercase; letter-spacing: 0.05em;
        }
        .live-dot {
            width: 10px; height: 10px; background-color: var(--success-color); border-radius: 50%;
            box-shadow: 0 0 10px var(--success-color), 0 0 20px var(--success-color); animation: pulse-live 1.5s infinite;
        }
        @keyframes pulse-live { 0% { transform: scale(0.95); opacity: 0.8; } 50% { transform: scale(1.2); opacity: 1; } 100% { transform: scale(0.95); opacity: 0.8; } }
        .live-members-scroll { display: flex; gap: 12px; overflow-x: auto; scrollbar-width: none; -ms-overflow-style: none; padding: 5px 0; flex-grow: 1; scroll-behavior: smooth; }
        .live-members-scroll::-webkit-scrollbar { display: none; }
        .live-badge {
            display: flex; align-items: center; gap: 6px; background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(16, 185, 129, 0.4); padding: 6px 14px; border-radius: 20px; font-size: 0.85rem; font-weight: 600; color: #fff; white-space: nowrap; transition: all 0.3s ease; box-shadow: 0 2px 8px rgba(0,0,0,0.2);
        }
        .live-badge:hover { background: rgba(16, 185, 129, 0.2); transform: translateY(-2px); border-color: #34d399; box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3); }

        /* Discord Popup */
        .discord-popup-overlay {
            position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.8); z-index: 9999; display: none;
            justify-content: center; align-items: center; opacity: 0; transition: opacity 0.3s ease; backdrop-filter: blur(8px);
        }
        .discord-popup-content {
            background: rgba(15, 23, 42, 0.95); color: #fff; padding: 35px 30px; border-radius: 20px; width: 90%; max-width: 450px; text-align: center;
            position: relative; border: 1px solid rgba(88, 101, 242, 0.5); box-shadow: 0 25px 50px -12px rgba(88, 101, 242, 0.3);
            transform: scale(0.9); transition: transform 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
        }
        .discord-popup-overlay.show { display: flex; opacity: 1; }
        .discord-popup-overlay.show .discord-popup-content { transform: scale(1); }
        .close-popup { position: absolute; top: 15px; right: 20px; background: none; border: none; color: #cbd5e1; font-size: 28px; cursor: pointer; transition: color 0.2s; }
        .close-popup:hover { color: #fff; }
        .discord-icon-large { font-size: 54px; margin-bottom: 10px; display: block; animation: float 3s ease-in-out infinite alternate;}
        .btn-discord-join { background-color: #5865F2; color: white; border: none; padding: 12px 24px; border-radius: 12px; cursor: pointer; font-weight: 700; transition: all 0.2s; box-shadow: 0 4px 15px rgba(88, 101, 242, 0.4);}
        .btn-discord-join:hover { background-color: #4752c4; transform: translateY(-2px); box-shadow: 0 8px 20px rgba(88, 101, 242, 0.6);}
        .btn-discord-done { background-color: rgba(255,255,255,0.05); color: #cbd5e1; border: 1px solid rgba(255,255,255,0.1); padding: 12px 24px; border-radius: 12px; cursor: pointer; font-weight: 600; transition: all 0.2s; }
        .btn-discord-done:hover { background-color: rgba(255,255,255,0.1); color: #fff; }

        @keyframes slideDown { from { opacity: 0; transform: translateY(-20px); } to { opacity: 1; transform: translateY(0); } }
        @keyframes slideUp { from { opacity: 0; transform: translateY(30px); } to { opacity: 1; transform: translateY(0); } }
        @keyframes float { 0% { transform: translateY(0); } 100% { transform: translateY(-8px); } }

        /* ========================================================
           RESPONSIVE FIXES UNTUK MOBILE & TABLET
           ======================================================== */
        
        /* TABLET (Layar Menengah) */
        @media (max-width: 1024px) {
            .user-card-content { gap: 20px; padding: 2rem; }
            .user-card-extra { 
                min-width: 100%; 
                flex: 1 1 100%; 
                align-items: center; 
                text-align: center; 
                max-width: none;
            }
        }

        /* MOBILE (Layar Kecil) */
        @media (max-width: 768px) {
            .dashboard-header .user-profile { 
                flex-direction: column; text-align: center; gap: 15px; 
            }
            .profile-info h1 { font-size: 1.6rem; }
            
            .user-card-content { flex-direction: column; padding: 1.5rem 1rem; text-align: center;}
            .user-info { min-width: 100%; }
            .user-info h2 { font-size: 1.6rem; }
            
            /* Dongle Duty Mobile Fix */
            .modern-duty-badge { 
                justify-content: center; width: 100%; flex-wrap: wrap; gap: 10px; padding: 12px; border-radius: 16px;
            }
            .duty-divider { display: none; }
            .duty-clock-display { width: 100%; }
            
            .user-card-extra { padding: 1.5rem 1rem; }
            .clock-time { font-size: 2.2rem; }
            
            /* Action Buttons Fix */
            .action-buttons { flex-direction: column; gap: 10px; }
            .action-buttons .btn, .action-buttons form { width: 100%; }
            
            /* Live Duty Mobile Fix */
            .live-duty-widget { flex-direction: column; align-items: stretch; padding: 15px; border-radius: 16px;}
            .live-title-container { 
                border-right: none; border-bottom: 1px solid rgba(255,255,255,0.1); 
                padding-right: 0; padding-bottom: 10px; justify-content: center; width: 100%; 
            }
            .live-members-scroll { padding-top: 10px; }
            
            /* Stats Grid */
            .stats-grid { grid-template-columns: 1fr; }
            .stat-card { padding: 1.25rem; }
        }

        /* SMARTPHONE KECIL */
        @media (max-width: 480px) {
            .clock-time { font-size: 1.8rem; }
            .profile-avatar { width: 70px; height: 70px; }
            .profile-info h1 { font-size: 1.3rem; }
        }

    </style>
</head>
<body>
    
    <div id="discord-popup" class="discord-popup-overlay">
        <div class="discord-popup-content">
            <button class="close-popup" onclick="closeDiscordPopup()">&times;</button>
            <span class="discord-icon-large">📢</span>
            <h2 style="color: #fff; margin-bottom: 15px; font-size: 1.6rem; font-weight: 800;">Warung Om Tante Family</h2>
            <p style="color: #94a3b8; margin-bottom: 25px; line-height: 1.6; font-size: 0.95rem;">
                Bergabunglah ke Discord kami untuk memantau log absen, input data penjualan, dan input data masak secara realtime!
            </p>
            <div class="popup-actions" style="display: flex; gap: 15px; justify-content: center;">
                <button id="btn-belum" class="btn-discord-join">Belum (Join Sekarang)</button>
                <button id="btn-sudah" class="btn-discord-done">Sudah Gabung</button>
            </div>
        </div>
    </div>

    <div class="dashboard-container">
        <?php include 'includes/header.php'; ?>
        <?php include 'includes/sidebar.php'; ?>

        <main class="main-content">
            <?php if (isset($_GET['msg']) && $_GET['type'] == 'error'): ?>
                <div class="error-message">❌ <?= htmlspecialchars($_GET['msg']) ?></div>
            <?php endif; ?>
            
            <?php if ($long_duty_alert_dashboard): ?>
                <div class="warning-message" style="margin-bottom: var(--spacing-xl); background: rgba(239, 68, 68, 0.15); border-color: #ef4444; color: #fca5a5;">
                    <strong>⚠️ Perhatian:</strong> Anda sudah On Duty lebih dari 5 jam. Pastikan Anda beristirahat yang cukup!
                </div>
            <?php endif; ?>

            <div class="dashboard-header">
                <div class="user-profile" style="background: transparent; padding: 0; border: none; box-shadow: none;">
                    <div class="profile-avatar">
                        <img src="LOGO_WOT.png" alt="Logo" class="logo-img">
                    </div>
                    <div class="profile-info">
                        <h1>Sistem Manajemen Warung Om Tante V2</h1> 
                        <div class="user-details">
                            <span class="user-icon">👤</span>
                            <span class="user-name">Selamat datang kembali, <strong><?= htmlspecialchars($user['name']) ?></strong></span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="user-card">
                <?php if ($warning_status): ?>
                <div class="sp-card-badge" style="top: 20px; right: 20px; z-index: 10;">
                    <span class="sp-badge sp-<?= strtolower($warning_status) ?>" style="box-shadow: 0 0 15px rgba(239,68,68,0.5);">
                        <?= htmlspecialchars($warning_status) ?>
                    </span>
                </div>
                <?php endif; ?>
                
                <div class="user-card-content">
                    <div class="user-info">
                        <h2><?= htmlspecialchars($user['name']) ?></h2>
                        <p class="user-role"><?= getRoleDisplayName($user['role']) ?></p>
                        
                        <div class="modern-duty-badge <?= $user['is_on_duty'] ? 'active' : 'inactive' ?>">
                            <div class="duty-icon">
                                <?php if($user['is_on_duty']): ?>
                                    <span class="live-dot-recording"></span>
                                <?php else: ?>
                                    <span style="font-size: 0.9rem; opacity: 0.6;">💤</span>
                                <?php endif; ?>
                            </div>
                            <div class="duty-text">
                                <?= $user['is_on_duty'] ? 'SEDANG BERTUGAS' : 'SEDANG ISTIRAHAT' ?>
                            </div>
                            
                            <?php if ($user['is_on_duty']): ?>
                                <div class="duty-divider"></div>
                                <div class="duty-clock-display" id="precision-duty-clock" data-start-time="<?= $user['current_duty_start'] ?>">
                                    00:00:00
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="user-card-extra">
                        <div class="clock-time" id="liveTime">00:00:00</div>
                        <div class="clock-date" id="liveDate">Memuat tanggal...</div>
                        <div class="weather-vibe">
                            <span class="animated-icon" id="vibeIcon">🏖️</span> 
                            <span id="vibeText">Menikmati Suasana</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="live-duty-widget">
                <div class="live-title-container">
                    <div class="live-dot"></div>
                    <span>ON DUTY</span>
                </div>
                <div class="live-members-scroll" id="liveScroll">
                    <?php if (empty($active_onduty_employees)): ?>
                        <span class="no-live">Belum ada anggota yang on duty saat ini.</span>
                    <?php else: ?>
                        <?php foreach ($active_onduty_employees as $emp): ?>
                            <div class="live-badge" title="<?= getRoleDisplayName($emp['role']) ?>">
                                👤 <?= htmlspecialchars($emp['name']) ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
            <div class="action-buttons">
                <form method="POST">
                    <input type="hidden" name="action" value="on_duty">
                    <button type="submit" class="btn btn-success" <?= $user['is_on_duty'] ? 'disabled' : '' ?>>
                        <span class="btn-icon">▶️</span> Mulai Duty
                    </button>
                </form>
                <form method="POST">
                    <input type="hidden" name="action" value="off_duty">
                    <button type="submit" class="btn btn-warning" <?= !$user['is_on_duty'] ? 'disabled' : '' ?>>
                        <span class="btn-icon">⏸️</span> Akhiri Duty
                    </button>
                </form>
                <a href="sales" class="btn btn-primary">
                    <span class="btn-icon">💰</span> Input Penjualan
                </a>
                <a href="data-masak" class="btn btn-primary">
                    <span class="btn-icon">🔪</span> Input Data Masak
                </a>
                <a href="leave-request" class="btn btn-info">
                    <span class="btn-icon">📝</span> Ajukan Izin/Cuti
                </a>
                <a href="resignation-request" class="btn btn-danger">
                    <span class="btn-icon">📄</span> Pengajuan Resign
                </a>
                <a href="manual-duty" class="btn btn-primary" style="flex: 1 1 100%;">
                    <span class="btn-icon">⏱️</span> Input Jam Manual
                </a>
            </div>

            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">⏱️</div>
                    <div class="stat-content">
                        <h3>Total Jam Kerja</h3>
                        <p class="stat-value"><?= formatDuration($total_minutes) ?></p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">🛍️</div>
                    <div class="stat-content">
                        <h3>Total Penjualan</h3>
                        <p class="stat-value"><?= $total_paket_terjual_dashboard ?> Item</p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">🍳</div>
                    <div class="stat-content">
                        <h3>Total Memasak</h3>
                        <p class="stat-value"><?= $total_paket_masak_dashboard ?> Paket</p>
                    </div>
                </div>
            </div>

            <div class="recent-activities">
                <h3><span class="section-icon" style="background: rgba(255,255,255,0.1); padding: 5px; border-radius: 8px;">📋</span> Aktivitas Shift Terakhir</h3>
                <div class="activities-list">
                    <?php if (empty($recent_activities)): ?>
                        <div class="no-data" style="color: var(--text-muted);">Belum ada riwayat aktivitas duty.</div>
                    <?php else: ?>
                        <?php foreach ($recent_activities as $activity): ?>
                        <?php 
                        $display_type = 'Otomatis';
                        $status_class = 'info';
                        if ($activity['is_manual'] == 1) {
                            $display_type = 'Manual (Web)';
                            $status_class = 'warning';
                        } elseif ($activity['is_manual'] == 2) {
                            $display_type = 'Discord/Bot';
                            $status_class = 'primary';
                        }

                        $is_active = $activity['status'] === 'active';
                        $display_end_time = $activity['duty_end'] ? date('H:i', strtotime($activity['duty_end'])) : 'Sekarang';
                        $display_duration = $is_active ? '<span style="color: var(--success-color); font-weight: bold; animation: pulse-live 1.5s infinite;">Berlangsung...</span>' : formatDuration($activity['duration_minutes']);
                        $display_status = ucfirst($activity['status']);
                        ?>
                        <div class="activity-item">
                            <div class="activity-date">
                                <span class="date-icon">📅</span>
                                <?= date('d M Y', strtotime($activity['duty_start'])) ?>
                            </div>
                            <div class="activity-time">
                                <span class="time-icon">⏰</span>
                                <?= date('H:i', strtotime($activity['duty_start'])) ?> - <?= $display_end_time ?>
                            </div>
                            <div class="activity-duration">
                                <strong>Input:</strong> <span class="status-badge status-<?= $status_class ?>"><?= $display_type ?></span> | 
                                <strong>Status:</strong> <span class="status-badge status-<?= $activity['status'] ?>"><?= $display_status ?></span> <br>
                                <span style="display:inline-block; margin-top:5px;"><strong>Durasi:</strong> <?= $display_duration ?></span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <script src="script.js"></script>
    <script>
        // === SCRIPT COUNTDOWN DUTY (Akurasi Tinggi) ===
        const dutyTimerEl = document.getElementById('precision-duty-clock');
        if (dutyTimerEl) {
            const rawStartTime = dutyTimerEl.getAttribute('data-start-time');
            if (rawStartTime) {
                // Pastikan parse tanggal aman untuk JS (mengganti spasi ke T jika perlu)
                const safeStartTime = rawStartTime.replace(' ', 'T');
                const startTime = new Date(safeStartTime).getTime();
                
                function updatePrecisionClock() {
                    const now = new Date().getTime();
                    const diff = Math.max(0, now - startTime); // Cegah minus
                    
                    const hours = Math.floor(diff / (1000 * 60 * 60));
                    const minutes = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60));
                    const seconds = Math.floor((diff % (1000 * 60)) / 1000);
                    
                    dutyTimerEl.textContent = 
                        String(hours).padStart(2, '0') + ':' + 
                        String(minutes).padStart(2, '0') + ':' + 
                        String(seconds).padStart(2, '0');
                }
                
                // Panggil setiap detik
                setInterval(updatePrecisionClock, 1000);
                updatePrecisionClock(); // Panggilan pertama langsung
            }
        }

        // === LIVE CLOCK & VIBE LOGIC (Widget Kanan) ===
        function updateLiveClock() {
            const now = new Date();
            const timeEl = document.getElementById('liveTime');
            const dateEl = document.getElementById('liveDate');
            const vibeIconEl = document.getElementById('vibeIcon');
            const vibeTextEl = document.getElementById('vibeText');

            if(timeEl && dateEl) {
                const hours = String(now.getHours()).padStart(2, '0');
                const minutes = String(now.getMinutes()).padStart(2, '0');
                const seconds = String(now.getSeconds()).padStart(2, '0');
                timeEl.textContent = `${hours}:${minutes}:${seconds}`;

                const options = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' };
                dateEl.textContent = now.toLocaleDateString('id-ID', options);

                const hour = now.getHours();
                if (hour >= 5 && hour < 15) {
                    vibeIconEl.textContent = '☀️';
                    vibeTextEl.textContent = 'Siang Cerah di Tepi Pantai';
                } else if (hour >= 15 && hour < 18) {
                    vibeIconEl.textContent = '🌅';
                    vibeTextEl.textContent = 'Senja Menawan';
                } else {
                    vibeIconEl.textContent = '🌙';
                    vibeTextEl.textContent = 'Malam Syahdu di Pantai';
                }
            }
        }
        setInterval(updateLiveClock, 1000);
        updateLiveClock(); 

        // === POPUP LOGIC DENGAN LOCALSTORAGE ===
        const DISCORD_INVITE_LINK = "https://discord.gg/n5Uf3JDu"; 

        function closeDiscordPopup() {
            document.getElementById('discord-popup').classList.remove('show');
        }

        document.addEventListener('DOMContentLoaded', function() {
            const popup = document.getElementById('discord-popup');
            const btnBelum = document.getElementById('btn-belum');
            const btnSudah = document.getElementById('btn-sudah');

            // Cek apakah user sudah pernah klik "Sudah Gabung" atau "Belum (Join Sekarang)"
            const hasJoinedDiscord = localStorage.getItem('discord_joined_v2');

            // Jika belum ada data di localStorage, tampilkan popup
            if (!hasJoinedDiscord) {
                setTimeout(() => {
                    popup.classList.add('show');
                    // Popup hilang otomatis setelah 10 detik (tapi akan muncul lagi di refresh selanjutnya)
                    setTimeout(() => {
                        closeDiscordPopup();
                    }, 10000);
                }, 500);
            }

            if(btnBelum){
                btnBelum.addEventListener('click', function() {
                    window.open(DISCORD_INVITE_LINK, '_blank');
                    // Jika mau, bisa juga set localStorage saat klik tombol ini
                    localStorage.setItem('discord_joined_v2', 'true');
                    closeDiscordPopup();
                });
            }

            if(btnSudah){
                btnSudah.addEventListener('click', function(e) {
                    e.preventDefault();
                    // Simpan status ke localStorage. Popup TIDAK AKAN MUNCUL LAGI.
                    localStorage.setItem('discord_joined_v2', 'true');
                    closeDiscordPopup();
                });
            }

            // Scroll manual drag-to-scroll untuk live members
            const slider = document.getElementById('liveScroll');
            let isDown = false;
            let startX;
            let scrollLeft;

            if(slider) {
                slider.addEventListener('mousedown', (e) => {
                    isDown = true;
                    slider.style.cursor = 'grabbing';
                    startX = e.pageX - slider.offsetLeft;
                    scrollLeft = slider.scrollLeft;
                });
                slider.addEventListener('mouseleave', () => { isDown = false; slider.style.cursor = 'grab'; });
                slider.addEventListener('mouseup', () => { isDown = false; slider.style.cursor = 'grab'; });
                slider.addEventListener('mousemove', (e) => {
                    if (!isDown) return;
                    e.preventDefault();
                    const x = e.pageX - slider.offsetLeft;
                    const walk = (x - startX) * 2; 
                    slider.scrollLeft = scrollLeft - walk;
                });
                slider.style.cursor = 'grab';
            }
        });
    </script>
</body>
</html>