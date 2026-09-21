<?php
require_once 'config.php';

if (!isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$user = getCurrentUser();

// Tentukan apakah pengguna memiliki peran admin yang diizinkan untuk menginput data orang lain
$is_admin_or_manager = hasRole(['ceo', 'direktur', 'wakil_direktur', 'manager']);

// Inisialisasi ID karyawan yang akan diinput datanya. Defaultnya adalah user yang login.
$employee_id_to_submit = $user['id'];
$selected_employee_name = $user['name'];
$selected_employee_role = $user['role'];

// Jika pengguna memiliki peran admin, ambil daftar semua karyawan untuk dropdown
$all_employees = [];
if ($is_admin_or_manager) {
    $all_employees = $conn->query("SELECT id, name, role FROM employees WHERE status = 'active' ORDER BY name")->fetch_all(MYSQLI_ASSOC);
    // Jika ada ID anggota yang dipilih dari form, gunakan ID tersebut
    if (isset($_GET['employee_id']) && !empty($_GET['employee_id'])) {
        $employee_id_to_submit = (int)$_GET['employee_id'];
        foreach ($all_employees as $emp) {
            if ($emp['id'] === $employee_id_to_submit) {
                $selected_employee_name = htmlspecialchars($emp['name']);
                $selected_employee_role = $emp['role'];
                break;
            }
        }
    }
}

// Ambil jumlah permohonan pending untuk indikator sidebar
$pending_requests_count = getPendingRequestCount();

$success_message = null;
$error_message = null;

// --- Handle Delete Sales Entry ---
if (($_SERVER['REQUEST_METHOD'] === 'POST') && (isset($_POST['action']) && $_POST['action'] === 'delete_sales_entry')) {
    $sales_entry_id = (int)($_POST['sales_entry_id'] ?? 0);

    if ($sales_entry_id <= 0) {
        $error_message = "ID entri penjualan tidak valid!";
    } else {
        $conn->begin_transaction();
        try {
            // Ambil detail entri sebelum dihapus untuk notifikasi
            $stmt_get_entry = $conn->prepare("
                SELECT *, date, input_time, employee_id
                FROM sales_data
                WHERE id = ?
            ");
            if (!$stmt_get_entry) {
                throw new Exception("Gagal menyiapkan query ambil detail entri penjualan: " . $conn->error);
            }
            $stmt_get_entry->bind_param("i", $sales_entry_id);
            $stmt_get_entry->execute();
            $entry_details = $stmt_get_entry->get_result()->fetch_assoc();
            $stmt_get_entry->close();

            if (!$entry_details) {
                throw new Exception("Entri penjualan tidak ditemukan.");
            }
            
            // Hapus entri penjualan
            $stmt_delete = $conn->prepare("DELETE FROM sales_data WHERE id = ?");
            if (!$stmt_delete) {
                throw new Exception("Gagal menyiapkan query hapus entri penjualan: " . $conn->error);
            }
            $stmt_delete->bind_param("i", $sales_entry_id);
            
            if ($stmt_delete->execute() && $stmt_delete->affected_rows > 0) {
                $conn->commit();
                $success_message = "Entri penjualan tanggal " . date('d/m/Y H:i', strtotime($entry_details['input_time'])) . " berhasil dihapus.";
                
                // Kirim notifikasi Discord
                sendDiscordNotification([
                    'employee_name' => getEmployeeNameById($entry_details['employee_id']),
                    'sales_date_time' => date('d/m/Y H:i', strtotime($entry_details['input_time'])),
                    'paket_abdul' => $entry_details['paket_abdul'] ?? 0,
                    'hp' => $entry_details['hp'] ?? 0,
                    'radio' => $entry_details['radio'] ?? 0
                ], 'sale_deleted');

            } else {
                throw new Exception("Gagal menghapus entri penjualan. Mungkin sudah dihapus atau tidak ada perubahan.");
            }
            $stmt_delete->close();

        } catch (Exception $e) {
            $conn->rollback();
            $error_message = "Terjadi kesalahan: " . $e->getMessage();
        }
        header("Location: sales.php?msg=" . urlencode($success_message ?? $error_message) . "&type=" . urlencode(isset($success_message) ? 'success' : 'error') . "&employee_id=" . $employee_id_to_submit);
        exit;
    }
}

// Menampilkan pesan feedback setelah redirect
if (isset($_GET['msg']) && isset($_GET['type'])) {
    $feedback_message = htmlspecialchars($_GET['msg']);
    $feedback_type = htmlspecialchars($_GET['type']);
    if ($feedback_type === 'success') {
        $success_message = $feedback_message;
    } else {
        $error_message = $feedback_message;
    }
}

// --- Handle form submission ---
if (($_SERVER['REQUEST_METHOD'] === 'POST') && (isset($_POST['action']) && $_POST['action'] === 'update_sales')) {
    $employee_id_from_form = (int)($_POST['employee_id'] ?? $user['id']);
    $date_input = $_POST['date'] ?? '';

    // === DATA PAKET (MENGURANGI STOK KULKAS) ===
    $paket_abdul = (int)($_POST['paket_abdul'] ?? 0);
    $paket_lacosa = (int)($_POST['paket_lacosa'] ?? 0);
    $paket_lakse = (int)($_POST['paket_lakse'] ?? 0);
    $paket_saiyo = (int)($_POST['paket_saiyo'] ?? 0);
    $paket_woku = (int)($_POST['paket_woku'] ?? 0);

    // === DATA BARANG ELEKTRONIK (TIDAK MENGURANGI STOK) ===
    $hp = (int)($_POST['hp'] ?? 0);
    $radio = (int)($_POST['radio'] ?? 0);
    
    $error_message = null; 

    // --- LOGIKA VALIDASI TANGGAL ---
    $date_obj = null;
    $formatted_date = null;
    if (empty($date_input)) { 
        $error_message = "Tanggal harus diisi!";
    }
    
    if (!isset($error_message)) {
        $date_obj = DateTime::createFromFormat('Y-m-d', $date_input);
        if (!$date_obj) { $date_obj = DateTime::createFromFormat('d/m/Y', $date_input); }

        if (!$date_obj) { $error_message = "Format tanggal tidak valid!"; }
        
        if (!isset($error_message)) {
            $formatted_date = $date_obj->format('Y-m-d');
            $today_limit = new DateTime();
            $today_limit->setTime(23, 59, 59);
            
            if ($date_obj > $today_limit) { $error_message = "Tanggal tidak boleh di masa depan!"; }
            
            $thirty_days_ago = new DateTime();
            $thirty_days_ago->sub(new DateInterval('P30D'));
            $thirty_days_ago->setTime(0, 0, 0);
            
            if ($date_obj < $thirty_days_ago) { $error_message = "Tanggal tidak boleh lebih dari 30 hari yang lalu!"; }
        }
    }

    // PENTING: Cek apakah ada input apapun
    $total_new_packages = $paket_abdul + $paket_lacosa + $paket_lakse + $paket_saiyo + $paket_woku;
    $total_electronics = $hp + $radio;

    if (($total_new_packages + $total_electronics) === 0 && !isset($error_message)) {
        $error_message = "Harap masukkan minimal satu penjualan (Paket, HP, atau Radio)!";
    }

    if (isset($error_message)) {
        header("Location: sales.php?msg=" . urlencode($error_message) . "&type=error" . "&employee_id=" . $employee_id_to_submit);
        exit;
    }

    $week_number = (int)$date_obj->format('W');
    $year = (int)$date_obj->format('Y');
    $input_time = date('Y-m-d H:i:s'); 
    
    // --- START ATOMIC TRANSACTION ---
    $conn->begin_transaction();
    try {
        // --- 1. INSERT INTO sales_data ---
        $stmt = $conn->prepare("
            INSERT INTO sales_data (
                employee_id, date, input_time, week_number, year, 
                paket_abdul, paket_lacosa, paket_lakse, paket_saiyo, paket_woku,
                hp, radio,
                paket_vip_person, paket_special_30min
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0)
        ");
        
        if (!$stmt) {
             throw new Exception("Gagal menyiapkan query insert sales: " . $conn->error);
        }
        
        $stmt->bind_param("issiiiiiiiii", 
            $employee_id_from_form, 
            $formatted_date, 
            $input_time,
            $week_number,
            $year,
            $paket_abdul,
            $paket_lacosa,
            $paket_lakse,
            $paket_saiyo,
            $paket_woku,
            $hp, 
            $radio
        );
        
        if (!$stmt->execute()) {
            throw new Exception("Gagal menyimpan data penjualan: " . $stmt->error);
        }
        $stmt->close();
        
        // --- 2. AUTOMATIC STOCK WITHDRAWAL (KULKAS/RESTO STOCK) ---
        // PENTING: Hanya paket makanan yang mengurangi stok. HP dan Radio TIDAK dimasukkan kesini.
        $withdrawal_map = [
            'Abdul Pack' => $paket_abdul,
            'Lacosa Pack' => $paket_lacosa,
            'Lakse Pack' => $paket_lakse,
            'Saiyo Pack' => $paket_saiyo,
            'Woku Pack' => $paket_woku
        ];

        $total_items_withdrawn = 0;
        $withdrawn_products = [];
        
        foreach ($withdrawal_map as $stock_name => $sold_qty) {
            $qty_to_withdraw = $sold_qty; // 1 Paket = 1 Unit Stok Kulkas
            
            if ($qty_to_withdraw > 0) {
                // a. Update stock (decrement) & Check sufficiency
                $stmt_update_stock = $conn->prepare("
                    UPDATE refrigerator_stock 
                    SET quantity = quantity - ? 
                    WHERE product_name = ? AND quantity >= ?
                ");
                if (!$stmt_update_stock) { 
                    throw new Exception("Gagal query update stok: " . $conn->error); 
                }
                
                $stmt_update_stock->bind_param("isi", $qty_to_withdraw, $stock_name, $qty_to_withdraw);
                $stmt_update_stock->execute();
                
                // Jika affected_rows 0, berarti stok tidak cukup atau barang tidak ada
                if ($stmt_update_stock->affected_rows === 0) {
                    // Cek stok saat ini
                    $stmt_check_current = $conn->prepare("SELECT quantity FROM refrigerator_stock WHERE product_name = ?");
                    if (!$stmt_check_current) { throw new Exception("Error cek stok: " . $conn->error); }
                    $stmt_check_current->bind_param("s", $stock_name);
                    $stmt_check_current->execute();
                    $result = $stmt_check_current->get_result();
                    
                    if ($result->num_rows === 0) {
                         throw new Exception("Produk **{$stock_name}** tidak ditemukan di database stok resto!");
                    }

                    $res_stock = $result->fetch_assoc();
                    $current_qty = $res_stock['quantity'] ?? 0;
                    $stmt_check_current->close();
                    
                    if ($current_qty < $qty_to_withdraw) {
                         throw new Exception("Stok Resto **{$stock_name}** tidak cukup! (Sisa: {$current_qty}, Dijual: {$qty_to_withdraw}). Transaksi batal.");
                    }
                }
                $stmt_update_stock->close();

                // b. Log transaksi refrigerator
                $transaction_type = 'withdraw';
                $stmt_log_trans = $conn->prepare("
                    INSERT INTO refrigerator_transactions (product_name, employee_id, transaction_type, quantity) 
                    VALUES (?, ?, ?, ?)
                ");
                $stmt_log_trans->bind_param("sisi", $stock_name, $employee_id_from_form, $transaction_type, $qty_to_withdraw);
                $stmt_log_trans->execute();
                $stmt_log_trans->close();
                
                $total_items_withdrawn += 1;
                $withdrawn_products[$stock_name] = $qty_to_withdraw;
            }
        }
        
        $conn->commit(); 
        
        $success_message = "Penjualan berhasil disimpan!";
        
        // --- 3. KIRIM NOTIFIKASI DISCORD ---

        // A. Notifikasi Sale Input
        $discord_sale_data = [
            'employee_name' => getEmployeeNameById($employee_id_from_form),
            'date' => $formatted_date,
            'input_time' => $input_time,
            'sales_items' => array_filter([
                'Abdul Pack' => $paket_abdul,
                'Lacosa Pack' => $paket_lacosa,
                'Lakse Pack' => $paket_lakse,
                'Saiyo Pack' => $paket_saiyo,
                'Woku Pack' => $paket_woku,
                'HP' => $hp,
                'Radio' => $radio
            ])
        ];
        
        if (!empty($discord_sale_data['sales_items'])) {
            sendDiscordNotification($discord_sale_data, 'sale_input');
        }

        // B. Notifikasi Refrigerator Withdraw
        if (!empty($withdrawn_products)) {
            sendDiscordNotification([
                'employee_name' => getEmployeeNameById($employee_id_from_form),
                'product_list' => $withdrawn_products, 
            ], "refrigerator_withdraw");
        }
        
        header("Location: sales.php?msg=" . urlencode($success_message) . "&type=success" . "&employee_id=" . $employee_id_to_submit);
        exit;

    } catch (Exception $e) {
        $conn->rollback();
        $error_message = "Error database: " . $e->getMessage();
        header("Location: sales.php?msg=" . urlencode($error_message) . "&type=error" . "&employee_id=" . $employee_id_to_submit);
        exit;
    } 
}

// === QUERY FILTER RIWAYAT ===
$filter_start_date = $_GET['start_date'] ?? date('Y-m-d');
$filter_end_date = $_GET['end_date'] ?? date('Y-m-d');

// Query Riwayat dengan Filter
$stmt = $conn->prepare("
    SELECT id, input_time, paket_abdul, paket_lacosa, paket_lakse, paket_saiyo, paket_woku, hp, radio
    FROM sales_data 
    WHERE employee_id = ? AND date BETWEEN ? AND ?
    ORDER BY input_time DESC
");
$stmt->bind_param("iss", $employee_id_to_submit, $filter_start_date, $filter_end_date);
$stmt->execute();
$history_data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// === QUERY TOTAL AKUMULASI (ALL TIME) ===
$overall_sales_summary = [
    'paket_abdul' => 0, 'paket_lacosa' => 0, 'paket_lakse' => 0, 'paket_saiyo' => 0, 'paket_woku' => 0,
    'hp' => 0, 'radio' => 0
];
$stmt = $conn->prepare("
    SELECT 
        SUM(paket_abdul) as paket_abdul, 
        SUM(paket_lacosa) as paket_lacosa, 
        SUM(paket_lakse) as paket_lakse,
        SUM(paket_saiyo) as paket_saiyo,
        SUM(paket_woku) as paket_woku,
        SUM(hp) as hp,
        SUM(radio) as radio
    FROM sales_data 
    WHERE employee_id = ?
");
$stmt->bind_param("i", $employee_id_to_submit);
$stmt->execute();
$res = $stmt->get_result()->fetch_assoc();
if ($res) { $overall_sales_summary = $res; }
$stmt->close();
$total_overall_sales = array_sum($overall_sales_summary);

?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Input Penjualan - Warung Om Tante</title>
    <link rel="icon" href="LOGO_WOT.png" type="image/png">
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

        /* --- Stats Grid Modern --- */
        .stats-grid-modern {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 1rem; margin-bottom: 2rem;
            animation: slideUp 0.6s ease-out 0.1s backwards;
        }
        .stat-card-modern {
            background: rgba(30, 41, 59, 0.5); backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.05); border-radius: 16px; padding: 1.25rem;
            position: relative; overflow: hidden; transition: 0.3s; text-align: center;
        }
        .stat-card-modern:hover { transform: translateY(-3px); border-color: rgba(255,255,255,0.15); box-shadow: 0 10px 20px rgba(0,0,0,0.3); background: rgba(30, 41, 59, 0.8); }
        .stat-card-modern h4 { font-size: 0.8rem; color: #94a3b8; margin: 0 0 5px 0; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; }
        .stat-card-modern .value { font-size: 1.8rem; font-weight: 800; color: #fff; }

        /* --- Cards (Glassmorphism) --- */
        .modern-card {
            background: rgba(30, 41, 59, 0.6) !important; backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.08) !important; border-radius: 24px;
            box-shadow: 0 15px 35px rgba(0,0,0,0.3) !important; padding: 2rem;
            animation: slideUp 0.6s ease-out 0.2s backwards; margin-bottom: 2rem;
        }
        .modern-card-header {
            display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;
            margin-bottom: 1.5rem; border-bottom: 1px solid rgba(255, 255, 255, 0.1); padding-bottom: 1rem;
        }
        .modern-card-header h3 { color: #fff; font-size: 1.25rem; font-weight: 700; margin: 0; display: flex; align-items: center; gap: 10px;}
        .current-time-modern { background: rgba(59, 130, 246, 0.15); color: #60a5fa; padding: 6px 12px; border-radius: 20px; font-size: 0.85rem; font-weight: 700; font-family: monospace; border: 1px solid rgba(59, 130, 246, 0.3);}

        /* --- Form Filter (Inner Wrapper) --- */
        .form-inner-wrapper {
            background: linear-gradient(145deg, rgba(30, 41, 59, 0.4), rgba(15, 23, 42, 0.6));
            border: 1px solid rgba(255, 255, 255, 0.05); border-radius: 20px; padding: 1.5rem;
            box-shadow: inset 0 0 20px rgba(0,0,0,0.2); margin-bottom: 2rem;
        }
        .modern-form-group { margin-bottom: 1.2rem; }
        .modern-form-group label { display: block; font-size: 0.85rem; font-weight: 600; color: var(--text-secondary); margin-bottom: 0.5rem; text-transform: uppercase; letter-spacing: 0.05em; }
        .modern-input-wrapper { position: relative; display: flex; align-items: center; }
        .modern-input, .modern-select {
            width: 100%; background: rgba(0, 0, 0, 0.4); border: 1px solid rgba(255, 255, 255, 0.1);
            color: white; padding: 1rem 1rem 1rem 1.2rem; border-radius: 12px; font-size: 0.95rem; transition: 0.3s;
        }
        .modern-select { appearance: none; cursor: pointer; }
        .modern-select option { background: #0f172a; color: white; }
        .modern-input:focus, .modern-select:focus { outline: none; border-color: var(--primary-color); background: rgba(0,0,0,0.6); box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15);}
        input[type="date"].modern-input::-webkit-calendar-picker-indicator { filter: invert(1); cursor: pointer; opacity: 0.6; }

        /* --- Product Grid --- */
        .sales-input-grid {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-top: 1.5rem;
        }
        .section-separator {
            grid-column: 1 / -1; margin-top: 1rem; margin-bottom: 0.5rem; padding-bottom: 0.5rem;
            border-bottom: 1px dashed rgba(255,255,255,0.1); font-size: 1rem; font-weight: 700; color: #60a5fa; text-transform: uppercase; letter-spacing: 0.05em;
        }
        .product-card-modern {
            background: rgba(0, 0, 0, 0.25); border: 1px solid rgba(255, 255, 255, 0.05); border-radius: 16px; padding: 1.25rem;
            display: flex; flex-direction: column; gap: 8px; position: relative; transition: 0.3s;
        }
        .product-card-modern:hover { border-color: rgba(255,255,255,0.15); background: rgba(0,0,0,0.4); transform: translateY(-2px); }
        .product-card-modern.no-stock { border: 1px dashed rgba(245, 158, 11, 0.4); background: rgba(245, 158, 11, 0.02); }
        
        .product-card-modern label { font-size: 0.95rem; font-weight: 800; color: #fff; margin: 0; }
        .product-card-modern p { font-size: 0.8rem; color: #94a3b8; margin: 0; }
        .badge-no-deduct { background: rgba(245, 158, 11, 0.2); color: #fbbf24; border: 1px solid rgba(245, 158, 11, 0.4); padding: 2px 6px; border-radius: 4px; font-size: 0.65rem; font-weight: 800; margin-left: 6px; vertical-align: middle;}
        
        .quantity-group-modern { display: flex; align-items: center; gap: 10px; margin-top: 10px; }
        .quantity-group-modern label { font-size: 0.8rem; color: #cbd5e1; font-weight: 600; }
        .sales-input-qty {
            width: 80px; background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); color: #fff;
            padding: 8px; border-radius: 8px; text-align: center; font-size: 1rem; font-weight: 700; transition: 0.3s; outline: none;
        }
        .sales-input-qty:focus { border-color: #3b82f6; background: rgba(59, 130, 246, 0.1); }

        /* --- Invoice Card Modern --- */
        .invoice-modern {
            background: linear-gradient(145deg, rgba(16, 185, 129, 0.1), rgba(0,0,0,0.4));
            border: 1px solid rgba(16, 185, 129, 0.3); border-radius: 20px; padding: 1.5rem; margin-top: 2rem;
            box-shadow: 0 10px 25px rgba(0,0,0,0.2); position: relative; overflow: hidden;
        }
        .invoice-modern h4 { margin: 0 0 1rem 0; color: #34d399; font-size: 1.1rem; display: flex; align-items: center; gap: 8px; text-transform: uppercase; letter-spacing: 0.05em;}
        .invoice-row-modern { display: flex; justify-content: space-between; padding: 8px 0; font-size: 0.95rem; color: #cbd5e1; }
        .invoice-row-modern span:last-child { font-weight: 700; color: #fff; }
        .invoice-row-modern.total-row {
            border-top: 1px dashed rgba(16, 185, 129, 0.4); margin-top: 8px; padding-top: 12px; font-size: 1.2rem; color: #fff;
        }
        .invoice-row-modern.total-row span:last-child { color: #10b981; font-size: 1.4rem; text-shadow: 0 0 10px rgba(16,185,129,0.4); }
        
        .share-row { display: flex; justify-content: space-between; font-size: 0.85rem; margin-top: 8px; padding: 4px 0;}
        .company-share { color: #60a5fa; font-weight: 800; }
        .employee-share { color: #facc15; font-weight: 800; }

        /* --- Buttons --- */
        .btn-modern-submit {
            background: linear-gradient(135deg, #3b82f6, #2563eb); color: #fff; border: none;
            padding: 1.2rem 2rem; border-radius: 14px; font-size: 1rem; font-weight: 800; text-transform: uppercase;
            letter-spacing: 0.05em; cursor: pointer; transition: all 0.3s ease; display: inline-flex; align-items: center; justify-content: center; gap: 10px; width: 100%; margin-top: 1.5rem;
            box-shadow: 0 8px 20px rgba(59, 130, 246, 0.3);
        }
        .btn-modern-submit:hover { transform: translateY(-3px); box-shadow: 0 12px 25px rgba(59, 130, 246, 0.5); }

        .btn-filter-modern { background: rgba(59, 130, 246, 0.15); color: #60a5fa; border: 1px solid rgba(59, 130, 246, 0.4); padding: 0.8rem 1.2rem; border-radius: 10px; font-weight: 700; cursor: pointer; transition: 0.3s; }
        .btn-filter-modern:hover { background: #3b82f6; color: #fff; }
        .btn-reset-modern { background: rgba(239, 68, 68, 0.15); color: #fca5a5; border: 1px solid rgba(239, 68, 68, 0.4); padding: 0.8rem 1.2rem; border-radius: 10px; font-weight: 700; cursor: pointer; transition: 0.3s; text-decoration: none;}
        .btn-reset-modern:hover { background: #ef4444; color: #fff; }

        /* --- Modern Table --- */
        .modern-table-wrapper {
            background: rgba(0, 0, 0, 0.25); border-radius: 18px; border: 1px solid rgba(255, 255, 255, 0.05); overflow-x: auto;
        }
        .report-table { width: 100%; border-collapse: collapse; font-size: 0.9rem; min-width: 900px; color: #e2e8f0; }
        .report-table th, .report-table td {
            padding: 1rem; border-bottom: 1px solid rgba(255, 255, 255, 0.03); text-align: center; vertical-align: middle;
        }
        .report-table th { background: rgba(255, 255, 255, 0.03); font-weight: 600; text-transform: uppercase; font-size: 0.75rem; letter-spacing: 0.05em; color: #94a3b8; }
        .report-table td:first-child { text-align: left; }
        .report-table tr:hover td { background: rgba(255, 255, 255, 0.03); }

        /* ========================================================
           NEW FEATURE: CONFIRMATION MODAL (POP-UP ELEGAN)
           ======================================================== */
        .modal-overlay {
            position: fixed; top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(15, 23, 42, 0.8); backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px);
            display: flex; align-items: center; justify-content: center; z-index: 9999;
            opacity: 0; pointer-events: none; transition: opacity 0.3s ease;
        }
        .modal-overlay.active { opacity: 1; pointer-events: auto; }
        
        .modal-modern {
            background: linear-gradient(145deg, rgba(30, 41, 59, 0.95), rgba(15, 23, 42, 0.98));
            border: 1px solid rgba(59, 130, 246, 0.3); border-top: 4px solid #3b82f6;
            border-radius: 24px; padding: 2rem; width: 90%; max-width: 450px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
            transform: translateY(30px); transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .modal-overlay.active .modal-modern { transform: translateY(0); }
        
        .modal-header-modern { display: flex; align-items: center; gap: 12px; margin-bottom: 1.5rem; border-bottom: 1px solid rgba(255,255,255,0.1); padding-bottom: 1rem;}
        .modal-icon { font-size: 2rem; background: rgba(59, 130, 246, 0.15); width: 50px; height: 50px; display: flex; align-items: center; justify-content: center; border-radius: 14px; border: 1px solid rgba(59, 130, 246, 0.3); }
        .modal-header-modern h3 { margin: 0; color: #fff; font-size: 1.3rem; font-weight: 800; }
        
        .modal-body-modern { margin-bottom: 2rem; }
        .modal-list-item { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px dashed rgba(255,255,255,0.05); color: #cbd5e1; font-size: 0.95rem;}
        .modal-list-item span:last-child { font-weight: 700; color: #fff; }
        .modal-list-total { margin-top: 10px; padding-top: 10px; border-top: 2px solid rgba(16, 185, 129, 0.4); display: flex; justify-content: space-between; font-size: 1.2rem; font-weight: 800; color: #10b981; }

        .modal-footer-modern { display: flex; gap: 10px; }
        .modal-btn { flex: 1; padding: 1rem; border-radius: 12px; font-weight: 800; font-size: 0.95rem; cursor: pointer; text-transform: uppercase; border: none; transition: 0.2s;}
        .btn-cancel { background: rgba(255,255,255,0.05); color: #cbd5e1; border: 1px solid rgba(255,255,255,0.1); }
        .btn-cancel:hover { background: rgba(255,255,255,0.1); color: #fff; }
        .btn-confirm { background: linear-gradient(135deg, #10b981, #059669); color: #fff; box-shadow: 0 5px 15px rgba(16, 185, 129, 0.3); }
        .btn-confirm:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(16, 185, 129, 0.4); }

        @keyframes slideDown { from { opacity: 0; transform: translateY(-20px); } to { opacity: 1; transform: translateY(0); } }
        @keyframes slideUp { from { opacity: 0; transform: translateY(30px); } to { opacity: 1; transform: translateY(0); } }
        @keyframes shake { 0%, 100% { transform: translateX(0); } 25% { transform: translateX(-5px); } 75% { transform: translateX(5px); } }

        @media (max-width: 768px) {
            .modern-page-header { padding: 1.5rem; text-align: center; }
            .header-content-wrapper { flex-direction: column; }
            .modal-modern { width: 95%; padding: 1.5rem; }
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
                    <div class="header-icon-wrapper">🛒</div>
                    <div class="header-text-wrapper">
                        <h1>Input Penjualan</h1>
                        <p>Catat transaksi penjualan harian. Stok Resto akan berkurang secara otomatis.</p>
                    </div>
                </div>
            </div>

            <?php if (isset($success_message)): ?>
                <div class="success-alert"><span>🎉</span> <?= htmlspecialchars($success_message) ?></div>
            <?php endif; ?>
            <?php if (isset($error_message)): ?>
                <div class="error-alert"><span>❌</span> <?= htmlspecialchars($error_message) ?></div>
            <?php endif; ?>

            <div class="modern-card">
                <div class="modern-card-header" style="border-bottom: none; margin-bottom: 0; padding-bottom: 0;">
                    <h3><span>📈</span> Total Akumulasi (All Time)</h3>
                    <span style="background: rgba(255,255,255,0.05); padding: 5px 12px; border-radius: 20px; font-size: 0.85rem; color: #94a3b8; font-weight: 600;">Total: <?= $total_overall_sales ?? 0 ?> Item</span>
                </div>
                <div class="stats-grid-modern" style="margin-top: 1.5rem; margin-bottom: 0;">
                    <div class="stat-card-modern"><h4>Abdul</h4><div class="value"><?= $overall_sales_summary['paket_abdul'] ?? 0 ?></div></div>
                    <div class="stat-card-modern"><h4>Lacosa</h4><div class="value"><?= $overall_sales_summary['paket_lacosa'] ?? 0 ?></div></div>
                    <div class="stat-card-modern"><h4>Lakse</h4><div class="value"><?= $overall_sales_summary['paket_lakse'] ?? 0 ?></div></div>
                    <div class="stat-card-modern"><h4>Saiyo</h4><div class="value"><?= $overall_sales_summary['paket_saiyo'] ?? 0 ?></div></div>
                    <div class="stat-card-modern"><h4>Woku</h4><div class="value"><?= $overall_sales_summary['paket_woku'] ?? 0 ?></div></div>
                    <div class="stat-card-modern"><h4>HP</h4><div class="value"><?= $overall_sales_summary['hp'] ?? 0 ?></div></div>
                    <div class="stat-card-modern"><h4>Radio</h4><div class="value"><?= $overall_sales_summary['radio'] ?? 0 ?></div></div>
                </div>
            </div>

            <div class="modern-card">
                <div class="modern-card-header">
                    <h3><span>📝</span> Form Penjualan</h3>
                    <div class="current-time-modern"><span id="current-time"><?= date('H:i:s') ?></span> WIB</div>
                </div>
                
                <div class="form-inner-wrapper" style="box-shadow: none; border: none; padding: 0; background: transparent;">
                    <form method="POST" id="sales-form">
                        <input type="hidden" name="action" value="update_sales">
                        <input type="hidden" name="employee_id" value="<?= $employee_id_to_submit ?>">
                        
                        <?php if ($is_admin_or_manager): ?>
                        <div class="modern-form-group">
                            <label>Input Atas Nama Anggota</label>
                            <select name="employee_id_select" class="modern-select" onchange="window.location.href='sales.php?employee_id=' + this.value">
                                <option value="<?= $user['id'] ?>" <?= ($employee_id_to_submit == $user['id']) ? 'selected' : '' ?>>-- Saya Sendiri (<?= htmlspecialchars($user['name']) ?>) --</option>
                                <?php foreach ($all_employees as $emp): ?>
                                    <?php if($emp['id'] != $user['id']): ?>
                                    <option value="<?= $emp['id'] ?>" <?= ($employee_id_to_submit == $emp['id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($emp['name']) ?>
                                    </option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                        
                        <div class="modern-form-group">
                            <label>Tanggal Transaksi</label>
                            <input type="date" name="date" id="date" value="<?= htmlspecialchars(date('Y-m-d')) ?>" class="modern-input" required max="<?= date('Y-m-d') ?>">
                        </div>
                        
                        <div class="sales-input-grid">
                            
                            <div class="section-separator">Paket Makanan ($ 150 / Paket)</div>
                            
                            <div class="product-card-modern">
                                <label for="paket_abdul">ABDUL PACK</label>
                                <div class="quantity-group-modern"><label>Jml:</label><input type="number" id="inp_abdul" name="paket_abdul" class="sales-input-qty" value="0" min="0"></div>
                            </div>
                            <div class="product-card-modern">
                                <label for="paket_lacosa">LACOSA PACK</label>
                                <div class="quantity-group-modern"><label>Jml:</label><input type="number" id="inp_lacosa" name="paket_lacosa" class="sales-input-qty" value="0" min="0"></div>
                            </div>
                            <div class="product-card-modern">
                                <label for="paket_lakse">LAKSE PACK</label>
                                <div class="quantity-group-modern"><label>Jml:</label><input type="number" id="inp_lakse" name="paket_lakse" class="sales-input-qty" value="0" min="0"></div>
                            </div>
                            <div class="product-card-modern">
                                <label for="paket_saiyo">SAIYO PACK</label>
                                <div class="quantity-group-modern"><label>Jml:</label><input type="number" id="inp_saiyo" name="paket_saiyo" class="sales-input-qty" value="0" min="0"></div>
                            </div>
                            <div class="product-card-modern">
                                <label for="paket_woku">WOKU PACK</label>
                                <div class="quantity-group-modern"><label>Jml:</label><input type="number" id="inp_woku" name="paket_woku" class="sales-input-qty" value="0" min="0"></div>
                            </div>

                            <div class="section-separator">Lainnya (Tidak Memotong Stok)</div>

                            <div class="product-card-modern no-stock">
                                <label for="hp">HANDPHONE <span class="badge-no-deduct">TANPA STOK</span></label>
                                <p>$ 35 / Unit</p>
                                <div class="quantity-group-modern"><label>Unit:</label><input type="number" id="inp_hp" name="hp" class="sales-input-qty" value="0" min="0"></div>
                            </div>
                            <div class="product-card-modern no-stock">
                                <label for="radio">RADIO <span class="badge-no-deduct">TANPA STOK</span></label>
                                <p>$ 60 / Unit</p>
                                <div class="quantity-group-modern"><label>Unit:</label><input type="number" id="inp_radio" name="radio" class="sales-input-qty" value="0" min="0"></div>
                            </div>

                        </div>

                        <div class="invoice-modern">
                            <h4>🧾 Real-time Invoice</h4>
                            <div class="invoice-row-modern">
                                <span>Total Paket ($150):</span>
                                <span id="inv-qty-paket">0 Pkt</span>
                            </div>
                            <div class="invoice-row-modern">
                                <span>Total Elektronik:</span>
                                <span id="inv-qty-elektronik">0 Unit</span>
                            </div>
                            <div class="invoice-row-modern total-row">
                                <span>Total Omset Penjualan:</span>
                                <span id="inv-total-sales">$ 0</span>
                            </div>
                            <div class="share-row">
                                <span>🏦 Masuk Kas Perusahaan (80%)</span>
                                <span class="company-share" id="inv-company-share">$ 0</span>
                            </div>
                            <div class="share-row">
                                <span>🎁 Bonus Komisi Anda (20%)</span>
                                <span class="employee-share" id="inv-employee-share">$ 0</span>
                            </div>
                        </div>
                        
                        <button type="button" class="btn-modern-submit" id="btn-pre-submit">
                            <span>Simpan Penjualan</span> 💾
                        </button>
                    </form>
                </div>
            </div>

            <div class="modern-card">
                <div class="modern-card-header">
                    <h3><span>📜</span> Riwayat Data Penjualan</h3>
                </div>
                
                <div class="form-inner-wrapper" style="padding: 1rem; margin-bottom: 1.5rem;">
                    <form method="GET" style="display:flex; flex-wrap:wrap; gap:10px; align-items:center; margin:0;">
                        <?php if(isset($_GET['employee_id'])): ?>
                            <input type="hidden" name="employee_id" value="<?= htmlspecialchars($_GET['employee_id']) ?>">
                        <?php endif; ?>
                        
                        <div style="display:flex; flex-direction:column; gap:4px;">
                            <label style="font-size:0.75rem; color:#94a3b8; font-weight:700; text-transform:uppercase;">Dari</label>
                            <input type="date" name="start_date" value="<?= $filter_start_date ?>" class="modern-input" style="padding: 0.6rem 1rem;">
                        </div>
                        <div style="display:flex; flex-direction:column; gap:4px;">
                            <label style="font-size:0.75rem; color:#94a3b8; font-weight:700; text-transform:uppercase;">Sampai</label>
                            <input type="date" name="end_date" value="<?= $filter_end_date ?>" class="modern-input" style="padding: 0.6rem 1rem;">
                        </div>
                        <div style="display:flex; gap:10px; margin-top: 18px;">
                            <button type="submit" class="btn-filter-modern">🔍 Filter</button>
                            <a href="sales.php" class="btn-reset-modern">✖ Reset</a>
                        </div>
                    </form>
                </div>

                <div class="card-content">
                    <?php if (empty($history_data)): ?>
                        <div class="no-data" style="text-align:center; padding: 2rem; color:#64748b; font-style:italic;">Tidak ada riwayat penjualan pada rentang tanggal ini.</div>
                    <?php else: ?>
                        <div class="modern-table-wrapper">
                            <table class="report-table"> 
                                <thead>
                                    <tr>
                                        <th>Waktu Input</th>
                                        <th>Abdul</th>
                                        <th>Lacosa</th>
                                        <th>Lakse</th>
                                        <th>Saiyo</th>
                                        <th>Woku</th>
                                        <th>HP</th>
                                        <th>Radio</th>
                                        <th style="text-align:center;">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($history_data as $sale): ?>
                                    <tr>
                                        <td>
                                            <div style="color:#fff; font-weight:600;"><?= date('d/m/Y', strtotime($sale['input_time'])) ?></div>
                                            <div style="color:#64748b; font-size:0.8rem;"><?= date('H:i', strtotime($sale['input_time'])) ?> WIB</div>
                                        </td>
                                        <td><?= $sale['paket_abdul'] ?: '-' ?></td>
                                        <td><?= $sale['paket_lacosa'] ?: '-' ?></td>
                                        <td><?= $sale['paket_lakse'] ?: '-' ?></td>
                                        <td><?= $sale['paket_saiyo'] ?: '-' ?></td>
                                        <td><?= $sale['paket_woku'] ?: '-' ?></td>
                                        <td style="color:#f59e0b;"><?= $sale['hp'] ?: '-' ?></td>
                                        <td style="color:#f59e0b;"><?= $sale['radio'] ?: '-' ?></td>
                                        <td style="text-align:center;">
                                            <form method="POST" onsubmit="return confirm('Yakin ingin menghapus entri ini? (Stok kulkas tidak akan kembali otomatis)')" style="margin:0;">
                                                <input type="hidden" name="action" value="delete_sales_entry">
                                                <input type="hidden" name="sales_entry_id" value="<?= $sale['id'] ?>">
                                                <button type="submit" style="background:rgba(239,68,68,0.1); color:#fca5a5; border:1px solid rgba(239,68,68,0.3); padding:4px 10px; border-radius:8px; cursor:pointer; font-weight:700; font-size:0.75rem; transition:0.2s;">HAPUS</button>
                                            </form>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <div id="confirmation-modal" class="modal-overlay">
        <div class="modal-modern">
            <div class="modal-header-modern">
                <div class="modal-icon">🛒</div>
                <h3>Konfirmasi Penjualan</h3>
            </div>
            
            <div style="font-size: 0.9rem; color: #94a3b8; margin-bottom: 1rem; line-height: 1.5;">
                Mohon pastikan rincian barang yang akan diinput sudah benar. <strong>Stok Resto akan otomatis berkurang</strong> untuk paket makanan.
            </div>

            <div class="modal-body-modern" id="modal-items-list">
                </div>

            <div class="modal-footer-modern">
                <button type="button" class="modal-btn btn-cancel" onclick="closeModal()">Batal</button>
                <button type="button" class="modal-btn btn-confirm" onclick="submitRealForm()">Ya, Simpan Data</button>
            </div>
        </div>
    </div>

    <script src="script.js"></script> 
    <script>
        function updateCurrentTime() {
            document.getElementById('current-time').textContent = new Date().toLocaleTimeString('id-ID', { hour12: false });
        }
        
        // --- Logika Invoice Realtime ---
        function calculateInvoice() {
            const abdul = parseInt(document.getElementById('inp_abdul').value) || 0;
            const lacosa = parseInt(document.getElementById('inp_lacosa').value) || 0;
            const lakse = parseInt(document.getElementById('inp_lakse').value) || 0;
            const saiyo = parseInt(document.getElementById('inp_saiyo').value) || 0;
            const woku = parseInt(document.getElementById('inp_woku').value) || 0;
            const hp = parseInt(document.getElementById('inp_hp').value) || 0;
            const radio = parseInt(document.getElementById('inp_radio').value) || 0;

            const totalPaket = abdul + lacosa + lakse + saiyo + woku;
            const totalElektronik = hp + radio;

            const grossPaket = totalPaket * 150;
            const grossHp = hp * 35;
            const grossRadio = radio * 60;
            
            const totalPenjualan = grossPaket + grossHp + grossRadio;
            const companyShare = totalPenjualan * 0.8;
            const employeeShare = totalPenjualan * 0.2;

            document.getElementById('inv-qty-paket').textContent = totalPaket + ' Pkt';
            document.getElementById('inv-qty-elektronik').textContent = totalElektronik + ' Unit';
            
            document.getElementById('inv-total-sales').textContent = '$ ' + totalPenjualan.toLocaleString('en-US');
            document.getElementById('inv-company-share').textContent = '$ ' + companyShare.toLocaleString('en-US');
            document.getElementById('inv-employee-share').textContent = '$ ' + employeeShare.toLocaleString('en-US');
        }

        document.querySelectorAll('.sales-input-qty').forEach(input => {
            input.addEventListener('input', calculateInvoice);
        });

        setInterval(updateCurrentTime, 1000);
        updateCurrentTime();
        calculateInvoice();

        // --- NEW FEATURE: MODAL LOGIC ---
        const modal = document.getElementById('confirmation-modal');
        const form = document.getElementById('sales-form');
        const btnPreSubmit = document.getElementById('btn-pre-submit');
        const modalList = document.getElementById('modal-items-list');

        function openModal() {
            // Cek apakah ada input
            let totalItems = 0;
            const items = [];
            
            const names = {
                'inp_abdul': 'Abdul Pack', 'inp_lacosa': 'Lacosa Pack', 
                'inp_lakse': 'Lakse Pack', 'inp_saiyo': 'Saiyo Pack', 
                'inp_woku': 'Woku Pack', 'inp_hp': 'Handphone', 'inp_radio': 'Radio'
            };

            for (const [id, name] of Object.entries(names)) {
                const val = parseInt(document.getElementById(id).value) || 0;
                if (val > 0) {
                    items.push({name: name, qty: val});
                    totalItems += val;
                }
            }

            if (totalItems === 0) {
                alert('Harap masukkan minimal satu item penjualan!');
                return;
            }

            // Build Modal List
            let html = '';
            items.forEach(i => {
                html += `<div class="modal-list-item"><span>${i.name}</span><span>${i.qty}</span></div>`;
            });
            
            const totalRevenue = document.getElementById('inv-total-sales').textContent;
            html += `<div class="modal-list-total"><span>Total Omset:</span><span>${totalRevenue}</span></div>`;

            modalList.innerHTML = html;
            modal.classList.add('active');
        }

        function closeModal() {
            modal.classList.remove('active');
        }

        function submitRealForm() {
            // Animasi loading pada tombol modal
            const btnConfirm = document.querySelector('.btn-confirm');
            btnConfirm.innerHTML = 'Memproses...';
            btnConfirm.style.opacity = '0.7';
            btnConfirm.style.cursor = 'wait';
            
            // Submit form
            form.submit();
        }

        btnPreSubmit.addEventListener('click', openModal);

    </script>
</body>
</html>