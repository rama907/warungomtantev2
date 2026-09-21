<?php
require_once 'config.php';

// PENTING: Aktifkan ini untuk debugging. Pastikan untuk menonaktifkan atau membatasinya di produksi.
// ini_set('display_errors', 1);
// ini_set('display_startup_errors', 1);
// error_reporting(E_ALL);

if (!isLoggedIn() || !hasRole(['ceo', 'direktur', 'wakil_direktur'])) {
    header('Location: dashboard.php');
    exit;
}

$user = getCurrentUser(); // Pastikan user selalu diambil di awal

// Ambil jumlah permohonan pending
$pending_requests_count = getPendingRequestCount();

// Inisialisasi variabel feedback
$success = null;
$error = null;
$message_type = 'success'; // Default type for success messages

// Handle approval/rejection actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'] ?? '';
    $request_id = (int)($_POST['request_id'] ?? 0);

    if ($request_id <= 0) {
        $error = "ID permohonan tidak valid!";
        $message_type = 'error';
    } else {
        switch ($action) {
            case 'approve_leave':
            case 'reject_leave':
                $table = 'leave_requests';
                $employee_field = 'employee_id';
                $status_field = 'status';
                $action_type = ($action === 'approve_leave') ? 'approved' : 'rejected';
                $notification_prefix = "Permohonan cuti";
                $success_msg_prefix = "Permohonan cuti dari ";
                $error_msg_prefix = "permohonan cuti";
                break;

            case 'approve_resignation':
            case 'reject_resignation':
                $table = 'resignation_requests';
                $employee_field = 'employee_id';
                $status_field = 'status';
                $action_type = ($action === 'approve_resignation') ? 'approved' : 'rejected';
                $notification_prefix = "Permohonan resign";
                $success_msg_prefix = "Permohonan resign dari ";
                $error_msg_prefix = "permohonan resign";
                break;

            case 'approve_manual_duty':
            case 'reject_manual_duty':
                $table = 'manual_duty_requests';
                $employee_field = 'employee_id';
                $status_field = 'status';
                $action_type = ($action === 'approve_manual_duty') ? 'approved' : 'rejected';
                $notification_prefix = "Input jam manual";
                $success_msg_prefix = "Permohonan input jam manual dari ";
                $error_msg_prefix = "permohonan input jam manual";
                break;

            case 'approve_add_employee':
            case 'reject_add_employee':
                $table = 'add_employee_requests';
                $employee_field = 'employee_name'; // Perhatikan, ini nama, bukan id
                $status_field = 'status';
                $action_type = ($action === 'approve_add_employee') ? 'approved' : 'rejected';
                $notification_prefix = "Permintaan anggota baru";
                $success_msg_prefix = "Permintaan anggota baru dari ";
                $error_msg_prefix = "permintaan anggota baru";
                break;
            
            case 'approve_password_reset':
            case 'reject_password_reset':
                $table = 'password_reset_requests';
                $employee_field = 'employee_id';
                $status_field = 'status';
                $action_type = ($action === 'approve_password_reset') ? 'approved' : 'rejected';
                $notification_prefix = "Permintaan reset password";
                $success_msg_prefix = "Permintaan reset password dari ";
                $error_msg_prefix = "permintaan reset password";
                break;
                
            default:
                $error = "Aksi tidak dikenal.";
                $message_type = 'error';
                break;
        }

        if (isset($table) && !$error) { // Lanjutkan hanya jika tabel terdefinisi dan tidak ada error awal
            // Pertama, cek apakah permohonan ada dan statusnya pending
            $check_stmt_sql = "SELECT r.*, e.name as employee_name FROM {$table} r JOIN employees e ON r.employee_id = e.id WHERE r.id = ? AND r.status = 'pending'";

            // Khusus untuk add_employee_requests karena employee_id tidak ada di tabel ini
            if ($table === 'add_employee_requests') {
                 $check_stmt_sql = "SELECT * FROM {$table} WHERE id = ? AND status = 'pending'";
            }
            
            $check_stmt = $conn->prepare($check_stmt_sql);
            if (!$check_stmt) {
                $error = "Gagal menyiapkan query cek {$error_msg_prefix}: " . $conn->error;
                $message_type = 'error';
            } else {
                $check_stmt->bind_param("i", $request_id);
                $check_stmt->execute();
                $request_data = $check_stmt->get_result()->fetch_assoc();
                $check_stmt->close();
                
                if ($request_data) {
                    $conn->begin_transaction(); // Mulai transaksi

                    try {
                        if ($action_type === 'approved') {
                            if ($table === 'manual_duty_requests') {
                                // Logika khusus untuk approve manual duty
                                $start_datetime_str = $request_data['duty_date'] . ' ' . $request_data['start_time'];
                                $end_datetime_str = $request_data['duty_date'] . ' ' . $request_data['end_time'];
                                
                                $start_timestamp = strtotime($start_datetime_str);
                                $end_timestamp = strtotime($end_datetime_str);
                                
                                if ($end_timestamp <= $start_timestamp) {
                                    $end_timestamp += 24 * 60 * 60; 
                                    $end_datetime_str = date('Y-m-d H:i:s', $end_timestamp);
                                }
                                
                                $duration_minutes = ($end_timestamp - $start_timestamp) / 60;
                                
                                if ($duration_minutes < 1 || $duration_minutes > (24 * 60)) { // Tambahan validasi durasi
                                    throw new Exception("Durasi jam manual tidak valid. Pastikan durasi antara 1 menit dan 24 jam.");
                                }

                                $insert_duty_stmt = $conn->prepare("
                                    INSERT INTO duty_logs (employee_id, duty_start, duty_end, duration_minutes, is_manual, approved_by, status)
                                    VALUES (?, ?, ?, ?, 1, ?, 'completed')
                                ");
                                if (!$insert_duty_stmt) {
                                    throw new Exception("Gagal menyiapkan query insert duty logs: " . $conn->error);
                                }
                                $insert_duty_stmt->bind_param("issii", $request_data['employee_id'], $start_datetime_str, $end_datetime_str, $duration_minutes, $user['id']);
                                
                                if (!$insert_duty_stmt->execute()) {
                                    throw new Exception("Gagal menambahkan ke duty logs: " . $insert_duty_stmt->error);
                                }
                                $insert_duty_stmt->close();
                                
                            } elseif ($table === 'add_employee_requests') {
                                // Logika khusus untuk approve permintaan anggota baru
                                $insert_employee_stmt = $conn->prepare("
                                    INSERT INTO employees (name, role, password)
                                    VALUES (?, ?, ?)
                                ");
                                if (!$insert_employee_stmt) {
                                    throw new Exception("Gagal menyiapkan query tambah anggota: " . $conn->error);
                                }
                                $insert_employee_stmt->bind_param("sss", $request_data['employee_name'], $request_data['requested_role'], $request_data['requested_password']);
                                if (!$insert_employee_stmt->execute()) {
                                    throw new Exception("Gagal menambahkan anggota baru: " . $insert_employee_stmt->error);
                                }
                                $insert_employee_stmt->close();

                            } elseif ($table === 'password_reset_requests') {
                                // Logika khusus untuk approve permintaan reset password
                                $update_password_stmt = $conn->prepare("
                                    UPDATE employees SET password = ? WHERE id = ?
                                ");
                                if (!$update_password_stmt) {
                                    throw new Exception("Gagal menyiapkan query update password: " . $conn->error);
                                }
                                $update_password_stmt->bind_param("si", $request_data['requested_password'], $request_data['employee_id']);
                                if (!$update_password_stmt->execute()) {
                                    throw new Exception("Gagal mengubah password anggota: " . $update_password_stmt->error);
                                }
                                $update_password_stmt->close();
                            }
                        }

                        // Update status permohonan di tabel utama
                        $update_stmt_sql = "UPDATE {$table} SET {$status_field} = ?, approved_by = ?, approved_at = NOW() WHERE id = ? AND {$status_field} = 'pending'";
                        $update_stmt = $conn->prepare($update_stmt_sql);
                        if (!$update_stmt) {
                            throw new Exception("Gagal menyiapkan query update status {$error_msg_prefix}: " . $conn->error);
                        }
                        $update_stmt->bind_param("sii", $action_type, $user['id'], $request_id);
                        
                        if ($update_stmt->execute() && $update_stmt->affected_rows > 0) {
                            $conn->commit(); // Commit transaksi
                            $success = $success_msg_prefix . htmlspecialchars($request_data['employee_name'] ?? $request_data['employee_name']) . " berhasil di" . ($action_type === 'approved' ? "setujui" : "tolak") . "!";
                            
                            // Tentukan tipe pesan berdasarkan aksi
                            if ($action_type === 'rejected') {
                                $message_type = 'error'; // Ubah menjadi 'error' (merah)
                            } else {
                                $message_type = 'success'; // Tetap 'success' (hijau)
                            }
                            
                            // Kirim notifikasi Discord
                            if ($table === 'add_employee_requests') {
                                sendDiscordNotification([
                                    'employee_name' => getEmployeeNameById($request_data['requested_by']),
                                    'target_employee_name' => $request_data['employee_name'],
                                    'status' => $action_type,
                                    'approved_by_name' => $user['name'],
                                ], 'request_status_update');
                            } elseif ($table === 'password_reset_requests') {
                                sendDiscordNotification([
                                    'employee_name' => getEmployeeNameById($request_data['requested_by']),
                                    'target_employee_name' => getEmployeeNameById($request_data['employee_id']),
                                    'status' => $action_type,
                                    'approved_by_name' => $user['name'],
                                ], 'request_status_update');
                            } else {
                                sendDiscordNotification([
                                    'employee_name' => $request_data['employee_name'],
                                    'request_type' => ($table === 'leave_requests' ? 'Cuti' : ($table === 'resignation_requests' ? 'Resign' : 'Input Jam Manual')),
                                    'status' => $action_type,
                                    'approved_by_name' => $user['name'],
                                ], 'request_status_update');
                            }

                        } else {
                            throw new Exception("Gagal mengupdate status {$error_msg_prefix}! Mungkin sudah diproses sebelumnya. Error: " . $update_stmt->error);
                        }
                        $update_stmt->close();

                    } catch (Exception $e) {
                        $conn->rollback(); // Rollback transaksi jika ada error
                        $error = "Terjadi kesalahan saat memproses {$error_msg_prefix}: " . $e->getMessage();
                        $message_type = 'error';
                    }
                } else {
                    $error = "Permohonan tidak ditemukan atau sudah diproses!";
                    $message_type = 'error';
                }
            }
        }
    }
}


// --- Pengambilan Data Permohonan untuk Tampilan ---

// Get pending leave requests
$leave_requests = [];
$query_leave = "
    SELECT lr.*, e.name as employee_name, e.role as employee_role
    FROM leave_requests lr
    JOIN employees e ON lr.employee_id = e.id
    WHERE lr.status = 'pending'
    ORDER BY lr.created_at ASC
";
$result_leave = $conn->query($query_leave);
if ($result_leave === false) {
    $error = "Gagal mengambil permohonan cuti: " . $conn->error;
    $message_type = 'error';
} else {
    $leave_requests = $result_leave->fetch_all(MYSQLI_ASSOC);
    $result_leave->free(); // Bebaskan hasil query
}

// Get pending resignation requests
$resignation_requests = [];
$query_resignation = "
    SELECT rr.*, e.name as employee_name, e.role as employee_role
    FROM resignation_requests rr
    JOIN employees e ON rr.employee_id = e.id
    WHERE rr.status = 'pending'
    ORDER BY rr.created_at ASC
";
$result_resignation = $conn->query($query_resignation);
if ($result_resignation === false) {
    $error = "Gagal mengambil permohonan resign: " . $conn->error;
    $message_type = 'error';
} else {
    $resignation_requests = $result_resignation->fetch_all(MYSQLI_ASSOC);
    $result_resignation->free(); // Bebaskan hasil query
}

// Get pending manual duty requests
$manual_duty_requests = [];
$query_manual = "
    SELECT mdr.*, e.name as employee_name, e.role as employee_role
    FROM manual_duty_requests mdr
    JOIN employees e ON mdr.employee_id = e.id
    WHERE mdr.status = 'pending'
    ORDER BY mdr.created_at ASC
";
$result_manual = $conn->query($query_manual);
if ($result_manual === false) {
    $error = "Gagal mengambil permohonan input jam manual: " . $conn->error;
    $message_type = 'error';
} else {
    $manual_duty_requests = $result_manual->fetch_all(MYSQLI_ASSOC);
    $result_manual->free(); // Bebaskan hasil query
}

// Get pending new employee requests
$new_employee_requests = [];
$query_new_employee = "
    SELECT ae.*, e.name AS requested_by_name
    FROM add_employee_requests ae
    LEFT JOIN employees e ON ae.requested_by = e.id
    WHERE ae.status = 'pending'
    ORDER BY ae.created_at ASC
";
$result_new_employee = $conn->query($query_new_employee);
if ($result_new_employee === false) {
    $error = "Gagal mengambil permohonan anggota baru: " . $conn->error;
    $message_type = 'error';
} else {
    $new_employee_requests = $result_new_employee->fetch_all(MYSQLI_ASSOC);
    $result_new_employee->free();
}

// Get pending password reset requests
$password_reset_requests = [];
$query_password_reset = "
    SELECT pr.*, e_req.name AS requested_by_name, e_target.name AS employee_name
    FROM password_reset_requests pr
    LEFT JOIN employees e_req ON pr.requested_by = e_req.id
    LEFT JOIN employees e_target ON pr.employee_id = e_target.id
    WHERE pr.status = 'pending'
    ORDER BY pr.created_at ASC
";
$result_password_reset = $conn->query($query_password_reset);
if ($result_password_reset === false) {
    $error = "Gagal mengambil permohonan reset password: " . $conn->error;
    $message_type = 'error';
} else {
    $password_reset_requests = $result_password_reset->fetch_all(MYSQLI_ASSOC);
    $result_password_reset->free();
}

// --- Helper Duration ---
if (!function_exists('formatDuration')) {
    function formatDuration($minutes) {
        if ($minutes < 0) return "0j 0m";
        $hours = floor($minutes / 60);
        $mins = $minutes % 60;
        return "{$hours}j {$mins}m";
    }
}

?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manajemen Permohonan - Warung Om Tante V2</title>
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
            background: radial-gradient(circle, rgba(59, 130, 246, 0.15), transparent 70%); pointer-events: none; z-index: 0;
        }
        .header-content-wrapper { display: flex; align-items: center; gap: 1.25rem; z-index: 2; position: relative; }
        .header-icon-wrapper {
            background: rgba(255, 255, 255, 0.05); border: 1px solid rgba(255, 255, 255, 0.1);
            width: 60px; height: 60px; border-radius: 16px; display: flex; align-items: center;
            justify-content: center; font-size: 1.8rem; box-shadow: inset 0 0 15px rgba(0,0,0,0.3); flex-shrink: 0; color: #60a5fa;
        }
        .header-text-wrapper h1 { color: #fff; font-weight: 800; font-size: 2.2rem; letter-spacing: -0.02em; margin: 0; }
        .header-text-wrapper p { color: var(--text-secondary); margin: 0.25rem 0 0 0; font-size: 1.05rem; }

        /* --- Alerts --- */
        .success-alert { background: rgba(52, 211, 153, 0.15); border: 1px solid rgba(52, 211, 153, 0.4); color: #6ee7b7; padding: 1rem 1.2rem; border-radius: 14px; margin-bottom: 1.5rem; display: flex; align-items: center; gap: 0.75rem; font-size: 0.95rem; font-weight: 500; animation: slideDown 0.4s ease-out; }
        .error-alert { background: rgba(220, 53, 69, 0.15); border: 1px solid rgba(220, 53, 69, 0.4); color: #fca5a5; padding: 1rem 1.2rem; border-radius: 14px; margin-bottom: 1.5rem; display: flex; align-items: center; gap: 0.75rem; font-size: 0.95rem; font-weight: 500; animation: shake 0.5s ease-in-out; }

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
            background: rgba(59, 130, 246, 0.15); color: #60a5fa; border-color: rgba(59, 130, 246, 0.4); box-shadow: 0 0 15px rgba(59, 130, 246, 0.2);
        }
        .badge-count { background: #3b82f6; color: #fff; border-radius: 20px; padding: 2px 6px; font-size: 0.75rem; margin-left: 6px; }
        .modern-tab-btn.active .badge-count { background: #60a5fa; color: #0f172a; }

        /* --- Section Container --- */
        .tab-content { display: none; animation: fadeIn 0.4s ease-out; }
        .tab-content.active { display: block; }

        /* --- Cards (Glassmorphism Wrapper) --- */
        .modern-card {
            background: rgba(30, 41, 59, 0.6) !important; backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.08) !important; border-radius: 24px;
            box-shadow: 0 15px 35px rgba(0,0,0,0.3) !important; padding: 2rem;
            margin-bottom: 2rem;
        }
        .modern-card-header {
            margin-bottom: 1.5rem; border-bottom: 1px solid rgba(255, 255, 255, 0.1); padding-bottom: 1rem;
        }
        .modern-card-header h3 { color: #fff; font-size: 1.25rem; font-weight: 700; margin: 0; }

        /* --- History Items UI (Ticket Style) --- */
        .history-list { display: flex; flex-direction: column; gap: 1.5rem; }

        .history-item-modern {
            background: linear-gradient(145deg, rgba(30, 41, 59, 0.4), rgba(15, 23, 42, 0.6));
            border: 1px solid rgba(255, 255, 255, 0.05); border-radius: 20px; padding: 1.5rem;
            transition: all 0.3s ease; position: relative; display: flex; flex-direction: column; gap: 1.2rem;
        }
        .history-item-modern:hover {
            transform: translateY(-3px); box-shadow: 0 10px 25px rgba(0,0,0,0.4); border-color: rgba(255, 255, 255, 0.1);
        }

        /* Status Accents (Default Yellow for Pending) */
        .history-item-modern::before {
            content: ''; position: absolute; left: -1px; top: 1.5rem; bottom: 1.5rem; width: 4px; border-radius: 0 4px 4px 0;
            background: #fbbf24; box-shadow: 0 0 10px #fbbf24;
        }

        /* Item Header */
        .req-header { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; }
        .req-user-info { display: flex; align-items: center; gap: 12px; }
        .req-avatar {
            width: 45px; height: 45px; border-radius: 12px; background: rgba(255,255,255,0.05);
            display: flex; align-items: center; justify-content: center; font-size: 1.4rem; border: 1px solid rgba(255,255,255,0.1);
        }
        .req-user-details { display: flex; flex-direction: column; }
        .req-user-name { font-size: 1.1rem; font-weight: 800; color: #fff; }
        .req-type-label { font-size: 0.75rem; color: var(--text-muted); text-transform: uppercase; font-weight: 700; letter-spacing: 0.05em; margin-top: 4px;}

        /* Role Badge */
        .role-badge-modern {
            background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); color: var(--primary-color);
            font-size: 0.65rem; font-weight: 800; padding: 2px 6px; border-radius: 6px; text-transform: uppercase; letter-spacing: 0.05em; display: inline-block;
        }

        /* Body Details */
        .req-body {
            background: rgba(0, 0, 0, 0.25); border-radius: 14px; padding: 1.25rem; display: flex; flex-direction: column; gap: 1rem; border: 1px solid rgba(255,255,255,0.03);
        }
        .req-data-row { display: flex; flex-wrap: wrap; gap: 20px; }
        .req-data-item { display: flex; flex-direction: column; gap: 4px; }
        .req-data-label { font-size: 0.75rem; color: #94a3b8; font-weight: 600; text-transform: uppercase; }
        .req-data-value { font-size: 1rem; font-weight: 700; color: #fff; }

        /* Reasons */
        .req-reasons { display: flex; flex-direction: column; gap: 10px; padding-top: 10px; border-top: 1px dashed rgba(255,255,255,0.1); }
        .reason-block { display: flex; flex-direction: column; gap: 5px; }
        .reason-tag { font-size: 0.7rem; font-weight: 800; padding: 2px 8px; border-radius: 4px; width: fit-content; text-transform: uppercase; }
        .tag-ooc { background: rgba(59, 130, 246, 0.1); color: #60a5fa; }
        .tag-ic { background: rgba(168, 85, 247, 0.1); color: #c084fc; }
        .reason-text { font-size: 0.95rem; color: #cbd5e1; line-height: 1.6; font-style: italic; }

        /* Footer / Actions */
        .req-footer {
            display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; margin-top: 5px;
        }
        .req-time { font-size: 0.85rem; color: #64748b; font-weight: 500; display: flex; align-items: center; gap: 5px;}
        .req-actions { display: flex; gap: 10px; }

        .btn-action-modern {
            border: none; padding: 8px 16px; border-radius: 10px; font-weight: 700; font-size: 0.85rem; cursor: pointer; transition: 0.3s; display: inline-flex; align-items: center; gap: 6px; text-transform: uppercase; letter-spacing: 0.05em;
        }
        .btn-approve { background: rgba(16, 185, 129, 0.15); color: #34d399; border: 1px solid rgba(16, 185, 129, 0.4); }
        .btn-approve:hover { background: #10b981; color: #fff; box-shadow: 0 0 15px rgba(16, 185, 129, 0.4); transform: translateY(-2px); }
        
        .btn-reject { background: rgba(239, 68, 68, 0.15); color: #fca5a5; border: 1px solid rgba(239, 68, 68, 0.4); }
        .btn-reject:hover { background: #ef4444; color: #fff; box-shadow: 0 0 15px rgba(239, 68, 68, 0.4); transform: translateY(-2px); }

        .spinner { display: inline-block; width: 14px; height: 14px; border: 2px solid rgba(255,255,255,0.3); border-radius: 50%; border-top-color: #fff; animation: spin 1s ease-in-out infinite; margin-right: 5px;}

        @keyframes spin { to { transform: rotate(360deg); } }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        @keyframes slideDown { from { opacity: 0; transform: translateY(-20px); } to { opacity: 1; transform: translateY(0); } }
        @keyframes slideUp { from { opacity: 0; transform: translateY(30px); } to { opacity: 1; transform: translateY(0); } }
        @keyframes shake { 0%, 100% { transform: translateX(0); } 25% { transform: translateX(-5px); } 75% { transform: translateX(5px); } }

        @media (max-width: 768px) {
            .modern-page-header { padding: 1.5rem; text-align: center; }
            .header-content-wrapper { flex-direction: column; }
            .req-header { flex-direction: column; align-items: flex-start; }
            .req-footer { flex-direction: column; align-items: stretch; }
            .req-actions { display: grid; grid-template-columns: 1fr 1fr; }
            .btn-action-modern { justify-content: center; }
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
                    <div class="header-icon-wrapper">📩</div>
                    <div class="header-text-wrapper">
                        <h1>Pusat Permohonan</h1>
                        <p>Tinjau dan kelola permohonan tertunda (pending) dari anggota manajemen.</p>
                    </div>
                </div>
            </div>

            <?php 
            if (isset($success) && $message_type === 'success'): ?>
                <div class="success-alert"><span>🎉</span> <?= htmlspecialchars($success) ?></div>
            <?php elseif (isset($success) && $message_type === 'error'): ?>
                <div class="error-alert"><span>❌</span> <?= htmlspecialchars($success) ?></div>
            <?php elseif (isset($error)): ?>
                <div class="error-alert"><span>❌</span> <?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <div class="modern-tabs">
                <button class="modern-tab-btn active" onclick="showTab('manual', this)">
                    Input Manual <span class="badge-count"><?= count($manual_duty_requests) ?></span>
                </button>
                <button class="modern-tab-btn" onclick="showTab('leave', this)">
                    Cuti <span class="badge-count"><?= count($leave_requests) ?></span>
                </button>
                <button class="modern-tab-btn" onclick="showTab('resignation', this)">
                    Resign <span class="badge-count"><?= count($resignation_requests) ?></span>
                </button>
                <button class="modern-tab-btn" onclick="showTab('new-employee', this)">
                    Anggota Baru <span class="badge-count"><?= count($new_employee_requests) ?></span>
                </button>
                <button class="modern-tab-btn" onclick="showTab('password-reset', this)">
                    Reset Sandi <span class="badge-count"><?= count($password_reset_requests) ?></span>
                </button>
            </div>

            <div id="manual-tab" class="tab-content active">
                <div class="modern-card">
                    <div class="modern-card-header">
                        <h3>Permohonan Input Jam Manual</h3>
                    </div>
                    <div class="card-content">
                        <?php if (empty($manual_duty_requests)): ?>
                            <div style="text-align:center; padding:3rem; color:#64748b; font-style:italic;">Tidak ada permohonan input jam yang tertunda.</div>
                        <?php else: ?>
                            <div class="history-list">
                                <?php foreach ($manual_duty_requests as $request): ?>
                                <?php 
                                    $start_timestamp = strtotime($request['start_time']);
                                    $end_timestamp = strtotime($request['end_time']);
                                    if ($end_timestamp <= $start_timestamp) { $end_timestamp += 24 * 60 * 60; }
                                    $duration_minutes = ($end_timestamp - $start_timestamp) / 60;
                                    
                                    // Panggil helper function formatDuration
                                    $hours = floor($duration_minutes / 60);
                                    $mins = $duration_minutes % 60;
                                    $dur_display = "{$hours}j {$mins}m";
                                    
                                    $is_overnight = $end_timestamp > strtotime($request['start_time']) + 12 * 60 * 60;
                                ?>
                                <div class="history-item-modern">
                                    <div class="req-header">
                                        <div class="req-user-info">
                                            <div class="req-avatar" style="color:#fbbf24;">⏱️</div>
                                            <div class="req-user-details">
                                                <span class="req-user-name"><?= htmlspecialchars($request['employee_name']) ?></span>
                                                <div style="display:flex; gap:8px; align-items:center;">
                                                    <span class="role-badge-modern"><?= getRoleDisplayName($request['employee_role']) ?></span>
                                                    <span class="req-type-label">Koreksi Jam Kerja</span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="req-body">
                                        <div class="req-data-row">
                                            <div class="req-data-item">
                                                <span class="req-data-label">Tanggal Duty</span>
                                                <span class="req-data-value"><?= date('d M Y', strtotime($request['duty_date'])) ?></span>
                                            </div>
                                            <div class="req-data-item">
                                                <span class="req-data-label">Waktu Klaim</span>
                                                <span class="req-data-value"><?= date('H:i', strtotime($request['start_time'])) ?> - <?= date('H:i', strtotime($request['end_time'])) ?></span>
                                            </div>
                                            <div class="req-data-item">
                                                <span class="req-data-label">Durasi Terhitung</span>
                                                <span class="req-data-value" style="color:#60a5fa;"><?= $dur_display ?><?= $is_overnight ? ' (Shift Malam)' : '' ?></span>
                                            </div>
                                        </div>
                                        <div class="req-reasons">
                                            <div class="reason-block">
                                                <span class="reason-tag" style="background:rgba(255,255,255,0.05); color:#fff;">Alasan Lupa Clock-In/Out</span>
                                                <p class="reason-text">"<?= htmlspecialchars($request['reason']) ?>"</p>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="req-footer">
                                        <span class="req-time"><span>🕒</span> Diajukan: <?= date('d/m/Y H:i', strtotime($request['created_at'])) ?></span>
                                        <div class="req-actions">
                                            <form method="POST" style="margin: 0;" onsubmit="return confirm('Yakin MENYETUJUI penambahan <?= $dur_display ?> jam kerja ini?')">
                                                <input type="hidden" name="action" value="approve_manual_duty">
                                                <input type="hidden" name="request_id" value="<?= $request['id'] ?>">
                                                <button type="submit" class="btn-action-modern btn-approve"><span>✔️</span> Setujui</button>
                                            </form>
                                            <form method="POST" style="margin: 0;" onsubmit="return confirm('Yakin MENOLAK input jam manual ini?')">
                                                <input type="hidden" name="action" value="reject_manual_duty">
                                                <input type="hidden" name="request_id" value="<?= $request['id'] ?>">
                                                <button type="submit" class="btn-action-modern btn-reject"><span>❌</span> Tolak</button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div id="leave-tab" class="tab-content">
                <div class="modern-card">
                    <div class="modern-card-header">
                        <h3>Permohonan Cuti / Izin</h3>
                    </div>
                    <div class="card-content">
                        <?php if (empty($leave_requests)): ?>
                            <div style="text-align:center; padding:3rem; color:#64748b; font-style:italic;">Tidak ada permohonan cuti yang tertunda.</div>
                        <?php else: ?>
                            <div class="history-list">
                                <?php foreach ($leave_requests as $request): ?>
                                <div class="history-item-modern">
                                    <div class="req-header">
                                        <div class="req-user-info">
                                            <div class="req-avatar">🏖️</div>
                                            <div class="req-user-details">
                                                <span class="req-user-name"><?= htmlspecialchars($request['employee_name']) ?></span>
                                                <div style="display:flex; gap:8px; align-items:center;">
                                                    <span class="role-badge-modern"><?= getRoleDisplayName($request['employee_role']) ?></span>
                                                    <span class="req-type-label">Izin / Cuti</span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="req-body">
                                        <div class="req-data-row">
                                            <div class="req-data-item">
                                                <span class="req-data-label">Periode Cuti</span>
                                                <span class="req-data-value">
                                                    <?= date('d M Y', strtotime($request['start_date'])) ?> <span style="color:var(--primary-color);">→</span> <?= date('d M Y', strtotime($request['end_date'])) ?>
                                                </span>
                                            </div>
                                        </div>
                                        <div class="req-reasons">
                                            <?php if ($request['reason_ooc']): ?>
                                                <div class="reason-block">
                                                    <span class="reason-tag tag-ooc">Alasan OOC</span>
                                                    <p class="reason-text">"<?= htmlspecialchars($request['reason_ooc']) ?>"</p>
                                                </div>
                                            <?php endif; ?>
                                            <?php if ($request['reason_ic']): ?>
                                                <div class="reason-block">
                                                    <span class="reason-tag tag-ic">Alasan IC</span>
                                                    <p class="reason-text">"<?= htmlspecialchars($request['reason_ic']) ?>"</p>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    
                                    <div class="req-footer">
                                        <span class="req-time"><span>🕒</span> Diajukan: <?= date('d/m/Y H:i', strtotime($request['created_at'])) ?></span>
                                        <div class="req-actions">
                                            <form method="POST" style="margin: 0;" onsubmit="return confirm('Yakin ingin MENYETUJUI permohonan cuti ini?')">
                                                <input type="hidden" name="action" value="approve_leave">
                                                <input type="hidden" name="request_id" value="<?= $request['id'] ?>">
                                                <button type="submit" class="btn-action-modern btn-approve"><span>✔️</span> Setujui</button>
                                            </form>
                                            <form method="POST" style="margin: 0;" onsubmit="return confirm('Yakin ingin MENOLAK permohonan cuti ini?')">
                                                <input type="hidden" name="action" value="reject_leave">
                                                <input type="hidden" name="request_id" value="<?= $request['id'] ?>">
                                                <button type="submit" class="btn-action-modern btn-reject"><span>❌</span> Tolak</button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div id="resignation-tab" class="tab-content">
                <div class="modern-card">
                    <div class="modern-card-header">
                        <h3>Permohonan Resign</h3>
                    </div>
                    <div class="card-content">
                        <?php if (empty($resignation_requests)): ?>
                            <div style="text-align:center; padding:3rem; color:#64748b; font-style:italic;">Tidak ada permohonan resign yang tertunda.</div>
                        <?php else: ?>
                            <div class="history-list">
                                <?php foreach ($resignation_requests as $request): ?>
                                <div class="history-item-modern">
                                    <div class="req-header">
                                        <div class="req-user-info">
                                            <div class="req-avatar">📄</div>
                                            <div class="req-user-details">
                                                <span class="req-user-name"><?= htmlspecialchars($request['employee_name']) ?></span>
                                                <div style="display:flex; gap:8px; align-items:center;">
                                                    <span class="role-badge-modern"><?= getRoleDisplayName($request['employee_role']) ?></span>
                                                    <span class="req-type-label">Resign</span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="req-body">
                                        <div class="req-data-row">
                                            <div class="req-data-item">
                                                <span class="req-data-label">Tanggal Efektif</span>
                                                <span class="req-data-value"><?= date('d M Y', strtotime($request['start_date'])) ?></span>
                                            </div>
                                            <div class="req-data-item">
                                                <span class="req-data-label">Passport</span>
                                                <span class="req-data-value"><?= htmlspecialchars($request['passport']) ?></span>
                                            </div>
                                            <div class="req-data-item">
                                                <span class="req-data-label">CID</span>
                                                <span class="req-data-value"><?= htmlspecialchars($request['cid']) ?></span>
                                            </div>
                                        </div>
                                        <div class="req-reasons">
                                            <?php if ($request['reason_ooc']): ?>
                                                <div class="reason-block">
                                                    <span class="reason-tag tag-ooc">Alasan OOC</span>
                                                    <p class="reason-text">"<?= htmlspecialchars($request['reason_ooc']) ?>"</p>
                                                </div>
                                            <?php endif; ?>
                                            <?php if ($request['reason_ic']): ?>
                                                <div class="reason-block">
                                                    <span class="reason-tag tag-ic">Alasan IC</span>
                                                    <p class="reason-text">"<?= htmlspecialchars($request['reason_ic']) ?>"</p>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    
                                    <div class="req-footer">
                                        <span class="req-time"><span>🕒</span> Diajukan: <?= date('d/m/Y H:i', strtotime($request['created_at'])) ?></span>
                                        <div class="req-actions">
                                            <form method="POST" style="margin: 0;" onsubmit="return confirm('Yakin ingin MENYETUJUI permohonan resign ini?')">
                                                <input type="hidden" name="action" value="approve_resignation">
                                                <input type="hidden" name="request_id" value="<?= $request['id'] ?>">
                                                <button type="submit" class="btn-action-modern btn-approve"><span>✔️</span> Setujui</button>
                                            </form>
                                            <form method="POST" style="margin: 0;" onsubmit="return confirm('Yakin ingin MENOLAK permohonan resign ini?')">
                                                <input type="hidden" name="action" value="reject_resignation">
                                                <input type="hidden" name="request_id" value="<?= $request['id'] ?>">
                                                <button type="submit" class="btn-action-modern btn-reject"><span>❌</span> Tolak</button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div id="new-employee-tab" class="tab-content">
                <div class="modern-card">
                    <div class="modern-card-header">
                        <h3>Permintaan Tambah Anggota</h3>
                    </div>
                    <div class="card-content">
                        <?php if (empty($new_employee_requests)): ?>
                            <div style="text-align:center; padding:3rem; color:#64748b; font-style:italic;">Tidak ada permohonan anggota baru yang tertunda.</div>
                        <?php else: ?>
                            <div class="history-list">
                                <?php foreach ($new_employee_requests as $request): ?>
                                <div class="history-item-modern">
                                    <div class="req-header">
                                        <div class="req-user-info">
                                            <div class="req-avatar" style="color:#a855f7;">👤</div>
                                            <div class="req-user-details">
                                                <span class="req-user-name"><?= htmlspecialchars($request['employee_name']) ?></span>
                                                <div style="display:flex; gap:8px; align-items:center;">
                                                    <span class="role-badge-modern" style="color:#a855f7; border-color:rgba(168,85,247,0.3);"><?= getRoleDisplayName($request['requested_role']) ?></span>
                                                    <span class="req-type-label">Karyawan Baru</span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="req-body">
                                        <div class="req-data-row">
                                            <div class="req-data-item">
                                                <span class="req-data-label">Pengaju</span>
                                                <span class="req-data-value"><?= htmlspecialchars($request['requested_by_name']) ?></span>
                                            </div>
                                            <div class="req-data-item">
                                                <span class="req-data-label">Status Sandi Awal</span>
                                                <span class="req-data-value" style="color:#34d399;"><?= empty($request['requested_password']) ? 'Default (123456)' : 'Custom Password' ?></span>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="req-footer">
                                        <span class="req-time"><span>🕒</span> Diajukan: <?= date('d/m/Y H:i', strtotime($request['created_at'])) ?></span>
                                        <div class="req-actions">
                                            <form method="POST" style="margin: 0;" onsubmit="return confirm('Yakin MENYETUJUI dan menambahkan anggota ini ke sistem?')">
                                                <input type="hidden" name="action" value="approve_add_employee">
                                                <input type="hidden" name="request_id" value="<?= $request['id'] ?>">
                                                <button type="submit" class="btn-action-modern btn-approve"><span>✔️</span> Setujui</button>
                                            </form>
                                            <form method="POST" style="margin: 0;" onsubmit="return confirm('Yakin MENOLAK anggota baru ini?')">
                                                <input type="hidden" name="action" value="reject_add_employee">
                                                <input type="hidden" name="request_id" value="<?= $request['id'] ?>">
                                                <button type="submit" class="btn-action-modern btn-reject"><span>❌</span> Tolak</button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div id="password-reset-tab" class="tab-content">
                <div class="modern-card">
                    <div class="modern-card-header">
                        <h3>Permintaan Reset Kata Sandi</h3>
                    </div>
                    <div class="card-content">
                        <?php if (empty($password_reset_requests)): ?>
                            <div style="text-align:center; padding:3rem; color:#64748b; font-style:italic;">Tidak ada permohonan reset sandi yang tertunda.</div>
                        <?php else: ?>
                            <div class="history-list">
                                <?php foreach ($password_reset_requests as $request): ?>
                                <div class="history-item-modern">
                                    <div class="req-header">
                                        <div class="req-user-info">
                                            <div class="req-avatar" style="color:#ec4899;">🔐</div>
                                            <div class="req-user-details">
                                                <span class="req-user-name"><?= htmlspecialchars($request['employee_name']) ?></span>
                                                <div style="display:flex; gap:8px; align-items:center;">
                                                    <span class="role-badge-modern" style="color:#ec4899; border-color:rgba(236,72,153,0.3);">Target Reset</span>
                                                    <span class="req-type-label">Ganti Sandi</span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="req-body">
                                        <div class="req-data-row">
                                            <div class="req-data-item">
                                                <span class="req-data-label">Pengaju Reset</span>
                                                <span class="req-data-value"><?= htmlspecialchars($request['requested_by_name']) ?></span>
                                            </div>
                                            <div class="req-data-item">
                                                <span class="req-data-label">Tipe Perubahan</span>
                                                <span class="req-data-value" style="color:#facc15;"><?= $request['reset_type'] == 'default' ? 'Kembali ke Default (123456)' : 'Ganti Password Baru' ?></span>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="req-footer">
                                        <span class="req-time"><span>🕒</span> Diajukan: <?= date('d/m/Y H:i', strtotime($request['created_at'])) ?></span>
                                        <div class="req-actions">
                                            <form method="POST" style="margin: 0;" onsubmit="return confirm('Yakin MENYETUJUI dan mengubah kata sandi akun ini?')">
                                                <input type="hidden" name="action" value="approve_password_reset">
                                                <input type="hidden" name="request_id" value="<?= $request['id'] ?>">
                                                <button type="submit" class="btn-action-modern btn-approve"><span>✔️</span> Setujui</button>
                                            </form>
                                            <form method="POST" style="margin: 0;" onsubmit="return confirm('Yakin MENOLAK perubahan sandi ini?')">
                                                <input type="hidden" name="action" value="reject_password_reset">
                                                <input type="hidden" name="request_id" value="<?= $request['id'] ?>">
                                                <button type="submit" class="btn-action-modern btn-reject"><span>❌</span> Tolak</button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

        </main>
    </div>

    <script src="script.js"></script>
    <script>
        // === TABS LOGIC ===
        function showTab(tabName, btnElement) {
            // Sembunyikan semua tab
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Hapus kelas aktif dari semua tombol tab
            document.querySelectorAll('.modern-tab-btn').forEach(btn => {
                btn.classList.remove('active');
            });
            
            // Tampilkan tab yang dipilih
            document.getElementById(tabName + '-tab').classList.add('active');
            
            // Tambahkan kelas aktif ke tombol yang ditekan
            if(btnElement) {
                btnElement.classList.add('active');
            }
        }
        
        // Prevent double submission and show loading state
        document.addEventListener('DOMContentLoaded', function() {
            const forms = document.querySelectorAll('form');
            
            forms.forEach(form => {
                form.addEventListener('submit', function(e) {
                    const submitBtn = this.querySelector('button[type="submit"]');
                    if (submitBtn) {
                        // Simpan HTML asli
                        const originalHTML = submitBtn.innerHTML;
                        
                        // Disable tombol
                        submitBtn.disabled = true;
                        submitBtn.style.opacity = '0.7';
                        submitBtn.style.cursor = 'wait';
                        
                        // Tampilkan loading text
                        submitBtn.innerHTML = '<span class="spinner"></span> Proses...';
                        
                        // Re-enable setelah 4 detik (jika request lama)
                        setTimeout(() => {
                            submitBtn.disabled = false;
                            submitBtn.style.opacity = '1';
                            submitBtn.style.cursor = 'pointer';
                            submitBtn.innerHTML = originalHTML;
                        }, 4000);
                    }
                });
            });
            
            // Auto hide success/error messages
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