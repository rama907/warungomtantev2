<?php
require_once 'config.php';

if (!isLoggedIn() || !hasRole(['ceo', 'direktur', 'wakil_direktur'])) {
    header('Location: dashboard.php');
    exit;
}

$user = getCurrentUser();

// Ambil jumlah permohonan pending untuk indikator sidebar
$pending_requests_count = getPendingRequestCount();

// Inisialisasi variabel feedback
$success = null;
$error = null;

// Handle admin actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    switch ($action) {
        
        // --- FITUR BARU: TOGGLE MAINTENANCE ---
        case 'toggle_maintenance':
            $is_maintenance = isset($_POST['maintenance_status']) ? '1' : '0';
            // Simpan status ke dalam file text agar tidak perlu merombak database
            file_put_contents('maintenance_mode.txt', $is_maintenance);
            $success = "Mode Maintenance berhasil " . ($is_maintenance ? 'DIAKTIFKAN' : 'DIMATIKAN') . "!";
            break;

        case 'add_employee':
            $name = $_POST['name'] ?? '';
            $role = $_POST['role'] ?? '';
            $password_input = $_POST['password'] ?? '';
            
            $password_to_hash = empty($password_input) ? 'password' : $password_input;
            $password = password_hash($password_to_hash, PASSWORD_DEFAULT);
            
            if ($name && $role) {
                $stmt = $conn->prepare("INSERT INTO employees (name, role, password) VALUES (?, ?, ?)");
                if (!$stmt) {
                    $error = "Gagal menyiapkan query tambah anggota: " . $conn->error;
                } else {
                    $stmt->bind_param("sss", $name, $role, $password);
                    if ($stmt->execute()) {
                        $success = "Anggota baru berhasil ditambahkan! Password default: 'password'";
                        sendDiscordNotification([
                            'action_type' => 'add_employee',
                            'target_employee_name' => $name,
                            'role' => $role,
                            'admin_name' => $user['name']
                        ], 'admin_employee_action');
                    } else {
                        $error = "Gagal menambahkan anggota baru: " . $stmt->error;
                    }
                    $stmt->close();
                }
            } else {
                $error = "Nama dan jabatan harus diisi!";
            }
            break;

        case 'update_role':
            $employee_id = (int)($_POST['employee_id'] ?? 0);
            $new_role = $_POST['new_role'] ?? '';
            
            if ($employee_id && $new_role) {
                $stmt = $conn->prepare("SELECT name, role FROM employees WHERE id = ? AND status = 'active'");
                if (!$stmt) {
                    $error = "Gagal menyiapkan query cek anggota: " . $conn->error;
                } else {
                    $stmt->bind_param("i", $employee_id);
                    $stmt->execute();
                    $employee_data = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                    
                    if ($employee_data) {
                        if ($employee_data['role'] !== $new_role) {
                            $stmt_update = $conn->prepare("UPDATE employees SET role = ? WHERE id = ? AND status = 'active'");
                            if (!$stmt_update) {
                                $error = "Gagal menyiapkan query update jabatan: " . $conn->error;
                            } else {
                                $stmt_update->bind_param("si", $new_role, $employee_id);
                                if ($stmt_update->execute() && $stmt_update->affected_rows > 0) {
                                    $success = "Jabatan " . htmlspecialchars($employee_data['name']) . " berhasil diubah!";
                                    sendDiscordNotification([
                                        'action_type' => 'update_role',
                                        'target_employee_name' => $employee_data['name'],
                                        'old_value' => $employee_data['role'],
                                        'new_value' => $new_role,
                                        'admin_name' => $user['name']
                                    ], 'admin_employee_action');
                                } else {
                                    $error = "Gagal mengubah jabatan: " . $stmt_update->error;
                                }
                                $stmt_update->close();
                            }
                        } else {
                            $error = "Jabatan yang dipilih sama dengan jabatan saat ini!";
                        }
                    } else {
                        $error = "Anggota tidak ditemukan atau tidak aktif!";
                    }
                }
            } else {
                $error = "Data tidak lengkap untuk mengubah jabatan!";
            }
            break;

        case 'deactivate_employee':
            $employee_id = (int)($_POST['employee_id'] ?? 0);
            
            if ($employee_id && $employee_id != $user['id']) {
                $stmt = $conn->prepare("SELECT name, is_on_duty FROM employees WHERE id = ? AND status = 'active'");
                if (!$stmt) {
                    $error = "Gagal menyiapkan query cek anggota: " . $conn->error;
                } else {
                    $stmt->bind_param("i", $employee_id);
                    $stmt->execute();
                    $employee_data = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                    
                    if ($employee_data) {
                        $conn->begin_transaction();
                        try {
                            if (isset($employee_data['is_on_duty']) && $employee_data['is_on_duty']) {
                                $stmt_get_active_log = $conn->prepare("SELECT id, duty_start FROM duty_logs WHERE employee_id = ? AND duty_end IS NULL ORDER BY id DESC LIMIT 1");
                                $stmt_get_active_log->bind_param("i", $employee_id);
                                $stmt_get_active_log->execute();
                                $active_log = $stmt_get_active_log->get_result()->fetch_assoc();
                                $stmt_get_active_log->close();

                                if ($active_log) {
                                    $duty_start_dt = new DateTime($active_log['duty_start']);
                                    $now_dt = new DateTime();
                                    $duration_minutes = ($now_dt->getTimestamp() - $duty_start_dt->getTimestamp()) / 60;
                                    
                                    $stmt_update_log = $conn->prepare("UPDATE duty_logs SET duty_end = NOW(), duration_minutes = ?, status = 'completed', approved_by = ? WHERE id = ?");
                                    $stmt_update_log->bind_param("iii", $duration_minutes, $user['id'], $active_log['id']);
                                    $stmt_update_log->execute();
                                    $stmt_update_log->close();
                                }
                                $stmt_reset_duty_status = $conn->prepare("UPDATE employees SET is_on_duty = FALSE, current_duty_start = NULL WHERE id = ?");
                                $stmt_reset_duty_status->bind_param("i", $employee_id);
                                $stmt_reset_duty_status->execute();
                                $stmt_reset_duty_status->close();
                            }

                            $stmt_update = $conn->prepare("UPDATE employees SET status = 'inactive' WHERE id = ?");
                            $stmt_update->bind_param("i", $employee_id);
                            if ($stmt_update->execute()) {
                                $conn->commit();
                                $success = "Anggota " . htmlspecialchars($employee_data['name']) . " berhasil dinonaktifkan!";
                                sendDiscordNotification([
                                    'action_type' => 'deactivate_employee',
                                    'target_employee_name' => $employee_data['name'],
                                    'admin_name' => $user['name']
                                ], 'admin_employee_action');
                            } else {
                                throw new Exception("Gagal menonaktifkan anggota: " . $stmt_update->error);
                            }
                            $stmt_update->close();
                            
                        } catch (Exception $e) {
                            $conn->rollback();
                            $error = "Terjadi kesalahan saat menonaktifkan anggota: " . $e->getMessage();
                        }
                    } else {
                        $error = "Anggota tidak ditemukan atau sudah tidak aktif!";
                    }
                }
            } else {
                $error = "Tidak dapat menonaktifkan diri sendiri atau ID tidak valid!";
            }
            break;
            
        case 'reactivate_employee':
            $employee_id = (int)($_POST['employee_id'] ?? 0);
            if ($employee_id) {
                $stmt = $conn->prepare("SELECT name FROM employees WHERE id = ? AND status = 'inactive'");
                $stmt->bind_param("i", $employee_id);
                $stmt->execute();
                $employee_data = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if ($employee_data) {
                    $stmt_update = $conn->prepare("UPDATE employees SET status = 'active' WHERE id = ?");
                    $stmt_update->bind_param("i", $employee_id);
                    if ($stmt_update->execute()) {
                        $success = "Akun " . htmlspecialchars($employee_data['name']) . " berhasil diaktifkan kembali!";
                        sendDiscordNotification([
                            'action_type' => 'reactivate_employee',
                            'target_employee_name' => $employee_data['name'],
                            'admin_name' => $user['name']
                        ], 'admin_employee_action');
                    } else {
                        $error = "Gagal mengaktifkan kembali anggota: " . $stmt_update->error;
                    }
                    $stmt_update->close();
                } else {
                    $error = "Anggota tidak ditemukan atau sudah berstatus aktif.";
                }
            }
            break;

        case 'delete_employee':
            $employee_id = (int)($_POST['employee_id'] ?? 0);
            if ($employee_id) {
                $stmt = $conn->prepare("SELECT name FROM employees WHERE id = ? AND status = 'inactive'");
                $stmt->bind_param("i", $employee_id);
                $stmt->execute();
                $employee_data = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if ($employee_data) {
                    try {
                        $stmt_del = $conn->prepare("DELETE FROM employees WHERE id = ? AND status = 'inactive'");
                        $stmt_del->bind_param("i", $employee_id);
                        if ($stmt_del->execute()) {
                            $success = "Akun " . htmlspecialchars($employee_data['name']) . " beserta datanya berhasil dihapus permanen.";
                        } else {
                            $error = "Gagal menghapus. Kemungkinan akun ini masih memiliki data riwayat aktivitas yang terikat di sistem.";
                        }
                        $stmt_del->close();
                    } catch (Exception $e) {
                        $error = "Gagal menghapus: Sistem mendeteksi ada riwayat aktivitas (Sales/Duty) yang masih terikat dengan akun ini.";
                    }
                } else {
                    $error = "Data anggota tidak ditemukan.";
                }
            }
            break;

        case 'update_discord_id':
            $employee_id = (int)($_POST['employee_id'] ?? 0);
            $new_discord_id = trim($_POST['new_discord_id'] ?? '');

            if ($employee_id <= 0) {
                $error = "ID anggota tidak valid!";
            } else {
                $stmt_get = $conn->prepare("SELECT name, discord_id FROM employees WHERE id = ?");
                $stmt_get->bind_param("i", $employee_id);
                $stmt_get->execute();
                $employee_data = $stmt_get->get_result()->fetch_assoc();
                $stmt_get->close();

                if ($employee_data) {
                    $old_discord_id = $employee_data['discord_id'] ?? '';
                    $discord_id_to_save = empty($new_discord_id) ? NULL : $new_discord_id;
                    
                    $stmt_update = $conn->prepare("UPDATE employees SET discord_id = ? WHERE id = ?");
                    if (!$stmt_update) {
                        $error = "Gagal menyiapkan query update Discord ID: " . $conn->error;
                    } else {
                        $stmt_update->bind_param("si", $discord_id_to_save, $employee_id);
                        if ($stmt_update->execute()) {
                            $action_desc = empty($new_discord_id) ? 'dihapus' : (empty($old_discord_id) ? 'ditambahkan' : 'diubah');
                            $success = "Discord ID untuk " . htmlspecialchars($employee_data['name']) . " berhasil {$action_desc}!";
                            
                            sendDiscordNotification([
                                'action_type' => 'update_discord_id',
                                'target_employee_name' => $employee_data['name'],
                                'old_value' => $old_discord_id,
                                'new_value' => $new_discord_id,
                                'admin_name' => $user['name']
                            ], 'admin_employee_action');
                        } else {
                            $error = "Gagal memperbarui Discord ID: " . $stmt_update->error;
                        }
                        $stmt_update->close();
                    }
                } else {
                    $error = "Anggota tidak ditemukan!";
                }
            }
            break;

        default:
            $error = "Aksi tidak dikenal.";
            break;
    }
}


// Get all active employees for management
$employees = $conn->query("
    SELECT * FROM employees 
    WHERE status = 'active' 
    ORDER BY 
        CASE role 
            WHEN 'ceo' THEN 1
            WHEN 'direktur' THEN 2
            WHEN 'wakil_direktur' THEN 3
            WHEN 'manager' THEN 4
            WHEN 'chef' THEN 5
            WHEN 'waiters' THEN 6
            WHEN 'karyawan' THEN 7
            WHEN 'magang' THEN 8
        END,
        name
")->fetch_all(MYSQLI_ASSOC);

// Get all inactive employees
$inactive_employees = $conn->query("SELECT * FROM employees WHERE status = 'inactive' ORDER BY name")->fetch_all(MYSQLI_ASSOC);

// Get all active employees along with their discord_id for the management form
$stmt_discord_list = $conn->query("
    SELECT id, name, role, discord_id 
    FROM employees 
    WHERE status = 'active'
    ORDER BY 
        CASE WHEN discord_id IS NULL OR discord_id = '' THEN 0 ELSE 1 END,
        name
");
$discord_management_list = $stmt_discord_list->fetch_all(MYSQLI_ASSOC);

// Get system statistics
$stats = [];
$stats['total_employees'] = $conn->query("SELECT COUNT(*) as count FROM employees WHERE status = 'active'")->fetch_assoc()['count'];
$stats['on_duty_count'] = $conn->query("SELECT COUNT(*) as count FROM employees WHERE is_on_duty = 1")->fetch_assoc()['count'];
$stats['pending_requests'] = $conn->query("
    SELECT 
        (SELECT COUNT(*) FROM leave_requests WHERE status = 'pending') +
        (SELECT COUNT(*) FROM resignation_requests WHERE status = 'pending') +
        (SELECT COUNT(*) FROM manual_duty_requests WHERE status = 'pending') as count
")->fetch_assoc()['count'];

// Cek status maintenance
$maintenance_file = 'maintenance_mode.txt';
$is_maintenance_active = file_exists($maintenance_file) && trim(file_get_contents($maintenance_file)) === '1';

?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Panel - Warung Om Tante V2</title>
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
            background: radial-gradient(circle, rgba(168, 85, 247, 0.15), transparent 70%); pointer-events: none; z-index: 0;
        }
        .header-content-wrapper { display: flex; align-items: center; gap: 1.25rem; z-index: 2; position: relative; }
        .header-icon-wrapper {
            background: rgba(255, 255, 255, 0.05); border: 1px solid rgba(255, 255, 255, 0.1);
            width: 60px; height: 60px; border-radius: 16px; display: flex; align-items: center;
            justify-content: center; font-size: 1.8rem; box-shadow: inset 0 0 15px rgba(0,0,0,0.3); flex-shrink: 0; color: #c084fc;
        }
        .header-text-wrapper h1 { color: #fff; font-weight: 800; font-size: 2.2rem; letter-spacing: -0.02em; margin: 0; }
        .header-text-wrapper p { color: var(--text-secondary); margin: 0.25rem 0 0 0; font-size: 1.05rem; }

        /* --- Alerts --- */
        .success-alert { background: rgba(52, 211, 153, 0.15); border: 1px solid rgba(52, 211, 153, 0.4); color: #6ee7b7; padding: 1rem 1.2rem; border-radius: 14px; margin-bottom: 1.5rem; display: flex; align-items: center; gap: 0.75rem; font-size: 0.95rem; font-weight: 500; animation: slideDown 0.4s ease-out; }
        .error-alert { background: rgba(220, 53, 69, 0.15); border: 1px solid rgba(220, 53, 69, 0.4); color: #fca5a5; padding: 1rem 1.2rem; border-radius: 14px; margin-bottom: 1.5rem; display: flex; align-items: center; gap: 0.75rem; font-size: 0.95rem; font-weight: 500; animation: shake 0.5s ease-in-out; }

        /* --- Stats Grid Modern --- */
        .stats-grid-modern {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1.5rem; margin-bottom: 2rem;
            animation: slideUp 0.6s ease-out 0.1s backwards;
        }
        .stat-card-modern {
            background: rgba(30, 41, 59, 0.5); backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.05); border-radius: 20px; padding: 1.5rem;
            position: relative; overflow: hidden; transition: 0.3s;
        }
        .stat-card-modern:hover { transform: translateY(-5px); border-color: rgba(255,255,255,0.15); box-shadow: 0 15px 30px rgba(0,0,0,0.3); }
        .stat-card-modern::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 4px; }
        .sc-users::before { background: #3b82f6; box-shadow: 0 0 10px #3b82f6; }
        .sc-duty::before { background: #10b981; box-shadow: 0 0 10px #10b981; }
        .sc-pending::before { background: #f59e0b; box-shadow: 0 0 10px #f59e0b; }

        .stat-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 10px; }
        .stat-header h4 { font-size: 0.95rem; color: #cbd5e1; margin: 0; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; }
        .stat-icon { font-size: 1.5rem; opacity: 0.8; }
        .stat-val { font-size: 2.2rem; font-weight: 800; color: #fff; }

        /* --- Tabs Modern --- */
        .modern-tabs {
            display: flex; gap: 12px; margin-bottom: 2rem; overflow-x: auto; padding-bottom: 5px; scrollbar-width: none;
        }
        .modern-tabs::-webkit-scrollbar { display: none; }
        .modern-tab-btn {
            background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); color: #94a3b8;
            padding: 10px 20px; border-radius: 16px; font-weight: 700; cursor: pointer; transition: 0.3s; white-space: nowrap; font-size: 0.95rem;
        }
        .modern-tab-btn:hover { background: rgba(255,255,255,0.1); color: #fff; }
        .modern-tab-btn.active {
            background: rgba(168, 85, 247, 0.15); color: #c084fc; border-color: rgba(168, 85, 247, 0.4); box-shadow: 0 0 15px rgba(168, 85, 247, 0.2);
        }

        /* --- Section Container --- */
        .tab-content { display: none; animation: fadeIn 0.4s ease-out; }
        .tab-content.active { display: block; }

        /* --- Cards (Glassmorphism) --- */
        .modern-card {
            background: rgba(30, 41, 59, 0.6) !important; backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.08) !important; border-radius: 24px;
            box-shadow: 0 15px 35px rgba(0,0,0,0.3) !important; padding: 2rem; margin-bottom: 2rem;
        }
        .modern-card-header { margin-bottom: 1.5rem; border-bottom: 1px solid rgba(255, 255, 255, 0.1); padding-bottom: 1rem; }
        .modern-card-header h3 { color: #fff; font-size: 1.25rem; font-weight: 700; margin: 0; }

        /* --- Form Inner Wrapper --- */
        .form-inner-wrapper {
            background: linear-gradient(145deg, rgba(30, 41, 59, 0.4), rgba(15, 23, 42, 0.6));
            border: 1px solid rgba(255, 255, 255, 0.05); border-radius: 20px; padding: 1.75rem; box-shadow: inset 0 0 20px rgba(0,0,0,0.2);
        }
        .modern-form-group { margin-bottom: 1.5rem; }
        .modern-form-group label { display: block; font-size: 0.9rem; font-weight: 600; color: var(--text-secondary); margin-bottom: 0.6rem; }
        
        .modern-input-wrapper { position: relative; display: flex; align-items: center; }
        .modern-input-icon { position: absolute; left: 1.25rem; font-size: 1.2rem; opacity: 0.6; pointer-events: none; z-index: 2; }
        .modern-input, .modern-select {
            width: 100%; background: rgba(0, 0, 0, 0.4); border: 1px solid rgba(255, 255, 255, 0.1);
            color: white; padding: 1.1rem 1rem 1.1rem 3.5rem; border-radius: 14px; font-size: 0.95rem; transition: all 0.3s ease;
        }
        .modern-select { appearance: none; cursor: pointer; }
        .modern-select option { background: #0f172a; color: white; }
        .modern-input:focus, .modern-select:focus { outline: none; border-color: var(--primary-color); background: rgba(0, 0, 0, 0.6); box-shadow: 0 0 0 3px rgba(168, 85, 247, 0.15); }
        
        .sp-form-grid { display: grid; grid-template-columns: 1fr; gap: 1.5rem; }
        @media (min-width: 768px) { .sp-form-grid { grid-template-columns: 1fr 1fr; } }
        
        .form-help-modern { display: block; margin-top: 0.5rem; font-size: 0.8rem; color: var(--text-muted); font-style: italic; }

        .modern-btn-submit {
            background: linear-gradient(135deg, var(--primary-color), var(--primary-hover)); color: #121212; border: none;
            padding: 1.2rem 2rem; border-radius: 14px; font-size: 1rem; font-weight: 800; text-transform: uppercase;
            letter-spacing: 0.05em; cursor: pointer; transition: all 0.3s ease; display: inline-flex; align-items: center;
            justify-content: center; gap: 0.75rem; box-shadow: 0 8px 20px rgba(255, 193, 7, 0.25); width: 100%;
        }
        .modern-btn-submit:hover { transform: translateY(-3px); box-shadow: 0 12px 25px rgba(255, 193, 7, 0.4); }
        .modern-btn-submit:disabled { opacity: 0.7; cursor: not-allowed; }

        /* --- Employee Cards Grid --- */
        .modern-employees-grid {
            display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 1.5rem;
        }
        .employee-card-modern {
            background: rgba(0, 0, 0, 0.25); border: 1px solid rgba(255, 255, 255, 0.05); border-radius: 16px; padding: 1.5rem;
            transition: all 0.3s ease; position: relative; display: flex; flex-direction: column; gap: 1.2rem;
        }
        .employee-card-modern:hover { transform: translateY(-3px); box-shadow: 0 10px 25px rgba(0,0,0,0.3); border-color: rgba(255, 255, 255, 0.1); background: rgba(0, 0, 0, 0.4); }

        .ec-header { display: flex; align-items: center; gap: 12px; }
        .ec-avatar { width: 45px; height: 45px; border-radius: 50%; background: rgba(255,255,255,0.05); display: flex; align-items: center; justify-content: center; font-size: 1.2rem; border: 1px solid rgba(255,255,255,0.1); flex-shrink: 0;}
        .ec-info { display: flex; flex-direction: column; gap: 4px; overflow: hidden; width: 100%;}
        .ec-name { font-size: 1.05rem; font-weight: 800; color: #fff; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        
        .ec-badges { display: flex; gap: 8px; align-items: center; flex-wrap: wrap;}
        .role-badge-modern { background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); color: var(--primary-color); font-size: 0.65rem; font-weight: 800; padding: 2px 6px; border-radius: 6px; text-transform: uppercase; letter-spacing: 0.05em; }
        
        .status-badge-modern { display: inline-flex; align-items: center; gap: 4px; padding: 2px 8px; border-radius: 30px; font-size: 0.65rem; font-weight: 800; text-transform: uppercase; }
        .sb-onduty { background: rgba(16, 185, 129, 0.15); color: #34d399; border: 1px solid rgba(16, 185, 129, 0.3); }
        .sb-offduty { background: rgba(255, 255, 255, 0.05); color: #94a3b8; border: 1px solid rgba(255, 255, 255, 0.1); }
        .sb-inactive { background: rgba(239, 68, 68, 0.15); color: #fca5a5; border: 1px solid rgba(239, 68, 68, 0.3); }
        
        .status-dot { width: 6px; height: 6px; border-radius: 50%; }
        .sb-onduty .status-dot { background: #34d399; box-shadow: 0 0 8px #34d399; animation: pulse-live 1.5s infinite; }
        .sb-offduty .status-dot { background: #64748b; }
        .sb-inactive .status-dot { background: #fca5a5; }

        .ec-actions { display: flex; flex-direction: column; gap: 10px; margin-top: auto; padding-top: 10px; border-top: 1px dashed rgba(255,255,255,0.05);}
        .role-change-group { display: flex; gap: 8px; }
        .role-change-group .modern-select { padding: 0.6rem 0.5rem 0.6rem 1rem; border-radius: 8px; font-size: 0.8rem; }
        
        .btn-action-sm { border: none; padding: 0.6rem 1rem; border-radius: 8px; font-weight: 700; font-size: 0.8rem; cursor: pointer; transition: 0.3s; display: inline-flex; align-items: center; justify-content: center; width: 100%; gap: 6px; }
        .btn-info { background: rgba(59, 130, 246, 0.15); color: #60a5fa; border: 1px solid rgba(59, 130, 246, 0.4); width: auto; }
        .btn-info:hover { background: #3b82f6; color: #fff; }
        .btn-danger { background: rgba(239, 68, 68, 0.15); color: #fca5a5; border: 1px solid rgba(239, 68, 68, 0.4); }
        .btn-danger:hover { background: #ef4444; color: #fff; }
        .btn-success { background: rgba(16, 185, 129, 0.15); color: #34d399; border: 1px solid rgba(16, 185, 129, 0.4); }
        .btn-success:hover { background: #10b981; color: #fff; }

        /* System Settings & Toggle */
        .system-action-item {
            background: rgba(0,0,0,0.2); border: 1px solid rgba(255,255,255,0.05); border-radius: 16px; padding: 1.5rem;
            display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;
        }
        .system-action-info h4 { margin: 0 0 5px 0; color: #fff; font-size: 1.1rem; }
        .system-action-info p { margin: 0; color: #94a3b8; font-size: 0.9rem; }
        
        /* Modern Toggle Switch */
        .modern-toggle-switch {
            position: relative; display: inline-block; width: 54px; height: 28px;
        }
        .modern-toggle-switch input { opacity: 0; width: 0; height: 0; }
        .modern-toggle-switch .slider {
            position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0;
            background-color: rgba(255,255,255,0.1); transition: .4s; border-radius: 34px; border: 1px solid rgba(255,255,255,0.2);
        }
        .modern-toggle-switch .slider:before {
            position: absolute; content: ""; height: 20px; width: 20px; left: 3px; bottom: 3px;
            background-color: #94a3b8; transition: .4s; border-radius: 50%;
        }
        .modern-toggle-switch input:checked + .slider {
            background-color: rgba(245, 158, 11, 0.2); border-color: rgba(245, 158, 11, 0.5);
        }
        .modern-toggle-switch input:checked + .slider:before {
            transform: translateX(26px); background-color: #fbbf24; box-shadow: 0 0 10px #fbbf24;
        }

        .btn-disabled { background: rgba(255,255,255,0.05); color: #64748b; cursor: not-allowed; border: 1px solid rgba(255,255,255,0.1); padding: 0.8rem 1.5rem; border-radius: 10px; font-weight: 600;}

        .spinner { display: inline-block; width: 14px; height: 14px; border: 2px solid rgba(255,255,255,0.3); border-radius: 50%; border-top-color: #fff; animation: spin 1s ease-in-out infinite; margin-right: 5px;}
        
        @keyframes spin { to { transform: rotate(360deg); } }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        @keyframes slideDown { from { opacity: 0; transform: translateY(-20px); } to { opacity: 1; transform: translateY(0); } }
        @keyframes slideUp { from { opacity: 0; transform: translateY(30px); } to { opacity: 1; transform: translateY(0); } }
        @keyframes pulse-live { 0% { transform: scale(0.95); opacity: 0.8; } 50% { transform: scale(1.5); opacity: 1; } 100% { transform: scale(0.95); opacity: 0.8; } }
        @keyframes shake { 0%, 100% { transform: translateX(0); } 25% { transform: translateX(-5px); } 75% { transform: translateX(5px); } }

        @media (max-width: 768px) {
            .modern-page-header { padding: 1.5rem; text-align: center; }
            .header-content-wrapper { flex-direction: column; }
            .sp-form-grid { grid-template-columns: 1fr; }
            .system-action-item { flex-direction: column; gap: 15px; text-align: center; }
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
                    <div class="header-icon-wrapper">⚙️</div>
                    <div class="header-text-wrapper">
                        <h1>Admin Panel</h1>
                        <p>Kelola sistem, hak akses, dan manajemen anggota Warung Om Tante V2.</p>
                    </div>
                </div>
            </div>

            <?php if (isset($success)): ?>
                <div class="success-alert"><span>🎉</span> <?= htmlspecialchars($success) ?></div>
            <?php endif; ?>
            <?php if (isset($error)): ?>
                <div class="error-alert"><span>❌</span> <?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <div class="stats-grid-modern">
                <div class="stat-card-modern sc-users">
                    <div class="stat-header">
                        <h4>Total Anggota</h4>
                        <span class="stat-icon">👥</span>
                    </div>
                    <div class="stat-val" style="color: #60a5fa;"><?= $stats['total_employees'] ?></div>
                </div>
                <div class="stat-card-modern sc-duty">
                    <div class="stat-header">
                        <h4>Sedang On Duty</h4>
                        <span class="stat-icon">🟢</span>
                    </div>
                    <div class="stat-val" style="color: #34d399;"><?= $stats['on_duty_count'] ?></div>
                </div>
                <div class="stat-card-modern sc-pending">
                    <div class="stat-header">
                        <h4>Permohonan Pending</h4>
                        <span class="stat-icon">📋</span>
                    </div>
                    <div class="stat-val" style="color: #fbbf24;"><?= $stats['pending_requests'] ?></div>
                </div>
            </div>

            <div class="modern-tabs">
                <button class="modern-tab-btn active" onclick="showAdminTab('employees', this)">Kelola Anggota</button>
                <button class="modern-tab-btn" onclick="showAdminTab('inactive', this)">Anggota Nonaktif</button>
                <button class="modern-tab-btn" onclick="showAdminTab('system', this)">Pengaturan Sistem</button>
            </div>

            <div id="employees-admin-tab" class="tab-content active">
                
                <div class="modern-card">
                    <div class="modern-card-header">
                        <h3>Tambah Anggota Baru</h3>
                    </div>
                    <div class="form-inner-wrapper">
                        <form method="POST" id="add_employee_form">
                            <input type="hidden" name="action" value="add_employee">
                            
                            <div class="sp-form-grid">
                                <div class="modern-form-group">
                                    <label for="name">Nama Panggilan</label>
                                    <div class="modern-input-wrapper">
                                        <span class="modern-input-icon">👤</span>
                                        <input type="text" name="name" id="name" class="modern-input" placeholder="Masukkan nama..." required>
                                    </div>
                                </div>
                                <div class="modern-form-group">
                                    <label for="role">Jabatan</label>
                                    <div class="modern-input-wrapper">
                                        <span class="modern-input-icon">🏷️</span>
                                        <select name="role" id="role" class="modern-select" required>
                                            <option value="">Pilih Jabatan</option>
                                            <option value="ceo">CEO</option>
                                            <option value="direktur">Direktur</option>
                                            <option value="wakil_direktur">Wakil Direktur</option>
                                            <option value="manager">Manager</option>
                                            <option value="chef">Chef</option>
                                            <option value="waiters">Waiters</option>
                                            <option value="karyawan">Karyawan</option>
                                            <option value="magang">Magang</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="modern-form-group">
                                <label for="password">Password (Opsional)</label>
                                <div class="modern-input-wrapper">
                                    <span class="modern-input-icon">🔑</span>
                                    <input type="password" name="password" id="password" class="modern-input" placeholder="Kosongkan untuk default password">
                                </div>
                                <small class="form-help-modern">Jika dikosongkan, password default adalah: <strong>password</strong></small>
                            </div>
                            
                            <button type="submit" class="modern-btn-submit">
                                <span>Tambah Anggota</span> ➕
                            </button>
                        </form>
                    </div>
                </div>

                <div class="modern-card">
                    <div class="modern-card-header">
                        <h3>Daftar Anggota Aktif</h3>
                    </div>
                    <div class="card-content">
                        <div class="modern-employees-grid">
                            <?php foreach ($employees as $employee): ?>
                            <div class="employee-card-modern">
                                <div class="ec-header">
                                    <div class="ec-avatar">👤</div>
                                    <div class="ec-info">
                                        <div class="ec-name" title="<?= htmlspecialchars($employee['name']) ?>"><?= htmlspecialchars($employee['name']) ?></div>
                                        <div class="ec-badges">
                                            <span class="role-badge-modern"><?= getRoleDisplayName($employee['role']) ?></span>
                                            <span class="status-badge-modern <?= $employee['is_on_duty'] ? 'sb-onduty' : 'sb-offduty' ?>">
                                                <span class="status-dot"></span>
                                                <?= $employee['is_on_duty'] ? 'On Duty' : 'Off Duty' ?>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="ec-actions">
                                    <form method="POST" class="role-change-form" id="update_role_form_<?= $employee['id'] ?>" onsubmit="event.preventDefault(); return confirmRoleChange('<?= htmlspecialchars($employee['name']) ?>', this)">
                                        <input type="hidden" name="action" value="update_role">
                                        <input type="hidden" name="employee_id" value="<?= $employee['id'] ?>">
                                        <div class="role-change-group">
                                            <select name="new_role" class="modern-select" style="padding-left: 10px;" required>
                                                <option value="">Ubah Jabatan...</option>
                                                <option value="ceo" <?= $employee['role'] == 'ceo' ? 'selected' : '' ?>>CEO</option>
                                                <option value="direktur" <?= $employee['role'] == 'direktur' ? 'selected' : '' ?>>Direktur</option>
                                                <option value="wakil_direktur" <?= $employee['role'] == 'wakil_direktur' ? 'selected' : '' ?>>Wakil Direktur</option>
                                                <option value="manager" <?= $employee['role'] == 'manager' ? 'selected' : '' ?>>Manager</option>
                                                <option value="chef" <?= $employee['role'] == 'chef' ? 'selected' : '' ?>>Chef</option>
                                                <option value="waiters" <?= $employee['role'] == 'waiters' ? 'selected' : '' ?>>Waiters</option>
                                                <option value="karyawan" <?= $employee['role'] == 'karyawan' ? 'selected' : '' ?>>Karyawan</option>
                                                <option value="magang" <?= $employee['role'] == 'magang' ? 'selected' : '' ?>>Magang</option>
                                            </select>
                                            <button type="submit" class="btn-action-sm btn-info">Ubah</button>
                                        </div>
                                    </form>
                                    
                                    <?php if ($employee['id'] != $user['id']): ?>
                                    <form method="POST" class="deactivate-form" id="deactivate_form_<?= $employee['id'] ?>" onsubmit="event.preventDefault(); return confirmDeactivation(this, '<?= htmlspecialchars($employee['name']) ?>')">
                                        <input type="hidden" name="action" value="deactivate_employee">
                                        <input type="hidden" name="employee_id" value="<?= $employee['id'] ?>">
                                        <button type="submit" class="btn-action-sm btn-danger">🚫 Nonaktifkan Anggota</button>
                                    </form>
                                    <?php else: ?>
                                    <div style="text-align: center; padding: 0.4rem; background: rgba(255,255,255,0.05); border-radius: 8px; font-size: 0.8rem; color: #64748b;">
                                        (Anda Sendiri)
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

            </div>

            <div id="inactive-admin-tab" class="tab-content">
                <div class="modern-card">
                    <div class="modern-card-header">
                        <h3>Daftar Anggota Nonaktif</h3>
                    </div>
                    <div class="card-content">
                        <?php if (empty($inactive_employees)): ?>
                            <div style="text-align: center; padding: 3rem 0; color: #64748b; font-style: italic;">
                                <span style="font-size: 3rem; display: block; margin-bottom: 10px;">🍃</span>
                                Tidak ada anggota yang dinonaktifkan.
                            </div>
                        <?php else: ?>
                            <div class="modern-employees-grid">
                                <?php foreach ($inactive_employees as $inactive): ?>
                                <div class="employee-card-modern" style="border-color: rgba(239, 68, 68, 0.2);">
                                    <div class="ec-header">
                                        <div class="ec-avatar" style="color:#fca5a5;">👤</div>
                                        <div class="ec-info">
                                            <div class="ec-name" style="color:#cbd5e1;" title="<?= htmlspecialchars($inactive['name']) ?>"><?= htmlspecialchars($inactive['name']) ?></div>
                                            <div class="ec-badges">
                                                <span class="role-badge-modern" style="color:#94a3b8; border-color: rgba(255,255,255,0.05);"><?= getRoleDisplayName($inactive['role']) ?></span>
                                                <span class="status-badge-modern sb-inactive">
                                                    <span class="status-dot"></span> Nonaktif
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="ec-actions">
                                        <form method="POST" onsubmit="return confirm('Yakin ingin MENGAKTIFKAN KEMBALI akun <?= htmlspecialchars($inactive['name']) ?>?')">
                                            <input type="hidden" name="action" value="reactivate_employee">
                                            <input type="hidden" name="employee_id" value="<?= $inactive['id'] ?>">
                                            <button type="submit" class="btn-action-sm btn-success">
                                                <span>🔄</span> Aktifkan Kembali
                                            </button>
                                        </form>
                                        
                                        <form method="POST" onsubmit="return confirm('⚠️ PERINGATAN: Yakin ingin MENGHAPUS PERMANEN akun <?= htmlspecialchars($inactive['name']) ?>? \n\nAksi ini bisa gagal jika data anggota tersebut masih memiliki riwayat (seperti data penjualan).')">
                                            <input type="hidden" name="action" value="delete_employee">
                                            <input type="hidden" name="employee_id" value="<?= $inactive['id'] ?>">
                                            <button type="submit" class="btn-action-sm btn-danger" style="background: transparent; border: 1px solid #ef4444;">
                                                <span>🗑️</span> Hapus Permanen
                                            </button>
                                        </form>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div id="system-admin-tab" class="tab-content">
                
                <div class="modern-card" style="border-color: rgba(245, 158, 11, 0.3); background: linear-gradient(145deg, rgba(245, 158, 11, 0.05), rgba(15, 23, 42, 0.6)) !important;">
                    <div class="modern-card-header" style="border-bottom-color: rgba(245, 158, 11, 0.2);">
                        <h3 style="color: #fde68a;"><span>🚧</span> Kontrol Status Website</h3>
                    </div>
                    <div class="card-content">
                        <div class="system-action-item" style="background: rgba(0,0,0,0.3); border-color: rgba(245, 158, 11, 0.2);">
                            <div class="system-action-info">
                                <h4 style="color: #fde68a;">Mode Maintenance (Pemeliharaan)</h4>
                                <p>Jika diaktifkan, akan muncul banner peringatan kuning di bagian atas layar semua anggota yang memberitahukan bahwa sistem sedang dalam perbaikan dan mungkin akan terasa lambat.</p>
                            </div>
                            <form method="POST" style="margin:0;">
                                <input type="hidden" name="action" value="toggle_maintenance">
                                <label class="modern-toggle-switch">
                                    <input type="checkbox" name="maintenance_status" onchange="this.form.submit()" <?= $is_maintenance_active ? 'checked' : '' ?>>
                                    <span class="slider round"></span>
                                </label>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="modern-card">
                    <div class="modern-card-header">
                        <h3>Kelola Discord ID Anggota</h3>
                    </div>
                    <div class="card-content">
                        <div style="background: rgba(59, 130, 246, 0.1); border: 1px solid rgba(59, 130, 246, 0.3); border-radius: 12px; padding: 1rem 1.25rem; color: #93c5fd; font-size: 0.9rem; margin-bottom: 1.5rem; display: flex; gap: 10px;">
                            <span style="font-size: 1.2rem;">💡</span>
                            <div>Tambahkan atau perbarui Discord User ID (contoh: 123456789012345678). Anggota yang belum di-link akan muncul paling atas. Kosongkan ID untuk menghapus.</div>
                        </div>
                        
                        <div class="form-inner-wrapper">
                            <form method="POST">
                                <input type="hidden" name="action" value="update_discord_id">
                                
                                <div class="sp-form-grid">
                                    <div class="modern-form-group">
                                        <label for="employee_id_discord">Pilih Anggota</label>
                                        <div class="modern-input-wrapper">
                                            <span class="modern-input-icon">👤</span>
                                            <select name="employee_id" id="employee_id_discord" class="modern-select" required>
                                                <option value="">-- Pilih Anggota --</option>
                                                <?php foreach ($discord_management_list as $emp): ?>
                                                    <option value="<?= $emp['id'] ?>">
                                                        <?= htmlspecialchars($emp['name']) ?> (<?= getRoleDisplayName($emp['role']) ?>) 
                                                        [ID: <?= empty($emp['discord_id']) ? '❌ Belum Ada' : htmlspecialchars($emp['discord_id']) ?>]
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    
                                    <div class="modern-form-group">
                                        <label for="new_discord_id">Discord User ID Baru</label>
                                        <div class="modern-input-wrapper">
                                            <span class="modern-input-icon">💬</span>
                                            <input type="text" name="new_discord_id" id="new_discord_id" class="modern-input" placeholder="Masukkan ID angka...">
                                        </div>
                                    </div>
                                </div>
                                
                                <button type="submit" class="modern-btn-submit">
                                    <span>Simpan Discord ID</span> 💾
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="modern-card">
                    <div class="modern-card-header">
                        <h3>Pengaturan Sistem Lainnya</h3>
                    </div>
                    <div class="card-content">
                        <div class="system-action-item">
                            <div class="system-action-info">
                                <h4>Backup Database</h4>
                                <p>Buat backup database keseluruhan (Fitur akan segera tersedia).</p>
                            </div>
                            <button class="btn-disabled" disabled>Backup</button>
                        </div>
                        
                        <div class="system-action-item">
                            <div class="system-action-info">
                                <h4>Pengaturan Bot Discord</h4>
                                <p>Konfigurasi webhook dan notifikasi (Fitur akan segera tersedia).</p>
                            </div>
                            <button class="btn-disabled" disabled>Konfigurasi</button>
                        </div>
                    </div>
                </div>

            </div>

        </main>
    </div>

    <script src="script.js"></script>
    <script>
        function showAdminTab(tabName, btnElement) {
            // Hide all tab contents
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Remove active class from all tab buttons
            document.querySelectorAll('.modern-tab-btn').forEach(button => {
                button.classList.remove('active');
            });
            
            // Show selected tab content
            document.getElementById(tabName + '-admin-tab').classList.add('active');
            
            // Add active class to clicked button
            if(btnElement) btnElement.classList.add('active');
        }
        
        function confirmRoleChange(employeeName, form) {
            const newRoleSelect = form.querySelector('select[name="new_role"]');
            const newRole = newRoleSelect.value;
            
            // Cari text dari option yang ter-select asli
            const currentSelectedOption = Array.from(newRoleSelect.options).find(opt => opt.defaultSelected);
            const currentRole = currentSelectedOption ? currentSelectedOption.value : null;
            
            const roleNames = {
                'ceo': 'CEO', 'direktur': 'Direktur', 'wakil_direktur': 'Wakil Direktur', 
                'manager': 'Manager', 'chef': 'Chef', 'waiters': 'Waiters',
                'karyawan': 'Karyawan', 'magang': 'Magang'
            };

            if (!newRole) {
                alert('Pilih jabatan baru terlebih dahulu!');
                return false;
            }

            if (currentRole && currentRole === newRole) {
                alert('Jabatan yang dipilih sama dengan jabatan saat ini!');
                return false;
            }

            if (confirm(`Yakin ingin mengubah jabatan ${employeeName} menjadi ${roleNames[newRole]}?`)) {
                const submitBtn = form.querySelector('button[type="submit"]');
                submitBtn.innerHTML = '<span class="spinner"></span>...';
                submitBtn.disabled = true;
                form.submit();
                return true;
            }
            return false;
        }

        function confirmDeactivation(form, employeeName) {
            if (confirm(`Yakin ingin menonaktifkan ${employeeName}?\n\nAnggota yang dinonaktifkan tidak dapat login lagi.`)) {
                const submitBtn = form.querySelector('button[type="submit"]');
                submitBtn.innerHTML = '<span class="spinner"></span>...';
                submitBtn.disabled = true;
                form.submit();
                return true;
            }
            return false;
        }
        
        document.addEventListener('DOMContentLoaded', function() {
            // Loader untuk Form Tambah Anggota
            const addEmployeeForm = document.getElementById('add_employee_form');
            if (addEmployeeForm) {
                addEmployeeForm.addEventListener('submit', function(e) {
                    const submitBtn = this.querySelector('button[type="submit"]');
                    if (submitBtn.disabled) { e.preventDefault(); return; }

                    submitBtn.disabled = true;
                    submitBtn.innerHTML = '<span class="spinner"></span> Memproses...';
                });
            }

            // Loader untuk Form Discord (form lain yang bukan role/deactivate/add/toggle)
            const otherForms = document.querySelectorAll('form:not(#add_employee_form):not(.role-change-form):not(.deactivate-form):not(:has(input[value="toggle_maintenance"]))');
            otherForms.forEach(form => {
                form.addEventListener('submit', function(e) {
                    const submitBtn = this.querySelector('button[type="submit"]');
                    if (submitBtn && !submitBtn.disabled) {
                        submitBtn.disabled = true;
                        submitBtn.innerHTML = '<span class="spinner"></span> Memproses...';
                    }
                });
            });

            // Auto hide alerts
            const alertBoxes = document.querySelectorAll('.success-alert, .error-alert');
            alertBoxes.forEach(box => {
                setTimeout(() => {
                    box.style.transition = 'opacity 0.5s ease';
                    box.style.opacity = '0';
                    setTimeout(() => box.remove(), 500);
                }, 4000);
            });
        });
    </script>
</body>
</html>