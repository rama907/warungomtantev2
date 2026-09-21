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

// Jika pengguna memiliki peran admin, ambil daftar semua karyawan untuk dropdown
$all_employees = [];
if ($is_admin_or_manager) {
    $all_employees = $conn->query("SELECT id, name, role FROM employees WHERE status = 'active' ORDER BY name")->fetch_all(MYSQLI_ASSOC);
    if (isset($_GET['employee_id']) && !empty($_GET['employee_id'])) {
        $employee_id_to_submit = (int)$_GET['employee_id'];
        foreach ($all_employees as $emp) {
            if ($emp['id'] === $employee_id_to_submit) {
                $selected_employee_name = htmlspecialchars($emp['name']);
                break;
            }
        }
    }
}

$pending_requests_count = getPendingRequestCount();

$success_message = null;
$error_message = null;

// === KONFIGURASI RESEP (DIHITUNG PER 2 PAKET) ===
$RECIPES_PER_2_UNIT = [
    'paket_abdul' => [
        'Susu' => 2,
        'Tomat Kemasan' => 2,
        'Beras Kemasan' => 2,
        'Garam' => 2,
        'Gula Kemasan' => 2,
        'Cabai Kemasan' => 2,
        'Gelas Plastik' => 2,
        'Box Styrofoam' => 2,
        'Paket Ayam' => 4
    ],
    'paket_lacosa' => [
        'Jagung Kemasan' => 2,
        'Garam' => 2,
        'Gula Kemasan' => 2,
        'Kentang Kemasan' => 2,
        'Susu' => 2,
        'Gelas Plastik' => 2,
        'Box Styrofoam' => 2,
        'Cabai Kemasan' => 2,
        'Daging' => 4,
        'Tomat Kemasan' => 4
    ],
    'paket_lakse' => [
        'Kentang Kemasan' => 2,
        'Jagung Kemasan' => 2,
        'Tomat Kemasan' => 2,
        'Beras Kemasan' => 2,
        'Garam' => 2,
        'Gula Kemasan' => 2,
        'Susu' => 2,
        'Gelas Plastik' => 2,
        'Box Styrofoam' => 2,
        'Cabai Kemasan' => 2,
        'Ikan Tuna' => 4
    ],
    'paket_saiyo' => [
        'Susu' => 2,
        'Tomat Kemasan' => 2,
        'Beras Kemasan' => 2,
        'Garam' => 2,
        'Gula Kemasan' => 2,
        'Gelas Plastik' => 2,
        'Box Styrofoam' => 2,
        'Cabai Kemasan' => 2,
        'Lobster' => 4
    ],
    'paket_woku' => [
        'Susu' => 2,
        'Tomat Kemasan' => 2,
        'Beras Kemasan' => 2,
        'Ikan Salmon' => 2,
        'Gula Kemasan' => 2,
        'Kentang Kemasan' => 2,
        'Garam' => 2,
        'Gelas Plastik' => 2,
        'Box Styrofoam' => 2,
        'Cabai Kemasan' => 2
    ]
];

// Mapping Nama Input Form ke Nama Display
$PACK_MAPPING = [
    'paket_abdul' => 'Abdul Pack',
    'paket_lacosa' => 'Lacosa Pack',
    'paket_lakse' => 'Lakse Pack',
    'paket_saiyo' => 'Saiyo Pack',
    'paket_woku' => 'Woku Pack'
];

$MIN_INPUT_UNIT = 2; // Kelipatan minimal

// --- Handle Delete Masak Entry ---
if (($_SERVER['REQUEST_METHOD'] === 'POST') && (isset($_POST['action']) && $_POST['action'] === 'delete_masak_entry')) {
    $cooking_entry_id = (int)($_POST['cooking_entry_id'] ?? 0);

    if ($cooking_entry_id <= 0) {
        $error_message = "ID entri masak tidak valid!";
    } else {
        $conn->begin_transaction();
        try {
            $stmt_get_entry = $conn->prepare("SELECT id, input_time, employee_id FROM cooking_data WHERE id = ?");
            $stmt_get_entry->bind_param("i", $cooking_entry_id);
            $stmt_get_entry->execute();
            $entry_details = $stmt_get_entry->get_result()->fetch_assoc();
            $stmt_get_entry->close();

            if (!$entry_details) { throw new Exception("Entri masak tidak ditemukan."); }
            
            $stmt_delete = $conn->prepare("DELETE FROM cooking_data WHERE id = ?");
            $stmt_delete->bind_param("i", $cooking_entry_id);
            
            if ($stmt_delete->execute() && $stmt_delete->affected_rows > 0) {
                $conn->commit();
                $success_message = "Entri masak tanggal " . date('d/m/Y H:i', strtotime($entry_details['input_time'])) . " berhasil dihapus.";
            } else {
                throw new Exception("Gagal menghapus entri masak.");
            }
            $stmt_delete->close();

        } catch (Exception $e) {
            $conn->rollback();
            $error_message = "Terjadi kesalahan: " . $e->getMessage();
        }
        header("Location: data-masak.php?msg=" . urlencode($success_message ?? $error_message) . "&type=" . urlencode(isset($success_message) ? 'success' : 'error') . "&employee_id=" . $employee_id_to_submit);
        exit;
    }
}

// Menampilkan pesan feedback
if (isset($_GET['msg']) && isset($_GET['type'])) {
    if ($_GET['type'] === 'success') {
        $success_message = htmlspecialchars($_GET['msg']);
    } else {
        $error_message = htmlspecialchars($_GET['msg']);
    }
}

// --- Handle form submission (Update Masak) ---
if (($_SERVER['REQUEST_METHOD'] === 'POST') && (isset($_POST['action']) && $_POST['action'] === 'update_masak')) {
    $employee_id_from_form = (int)($_POST['employee_id'] ?? $user['id']);
    $date_input = $_POST['date'] ?? '';

    $input_quantities = [
        'paket_abdul' => (int)($_POST['paket_abdul'] ?? 0),
        'paket_lacosa' => (int)($_POST['paket_lacosa'] ?? 0),
        'paket_lakse' => (int)($_POST['paket_lakse'] ?? 0),
        'paket_saiyo' => (int)($_POST['paket_saiyo'] ?? 0),
        'paket_woku' => (int)($_POST['paket_woku'] ?? 0)
    ];
    
    // Validasi Tanggal
    if (empty($date_input)) { $error_message = "Tanggal harus diisi!"; }
    else {
        $date_obj = DateTime::createFromFormat('Y-m-d', $date_input);
        if (!$date_obj) $date_obj = DateTime::createFromFormat('d/m/Y', $date_input);
        
        if (!$date_obj) { $error_message = "Format tanggal tidak valid!"; }
        else {
            $formatted_date = $date_obj->format('Y-m-d');
            if ($date_obj > new DateTime('tomorrow')) { $error_message = "Tanggal tidak boleh di masa depan!"; }
            if ($date_obj < new DateTime('-30 days')) { $error_message = "Maksimal H-30!"; }
        }
    }

    // Validasi Jumlah
    $total_new_prep = array_sum($input_quantities);
    if ($total_new_prep === 0 && !isset($error_message)) {
        $error_message = "Harap masukkan minimal {$MIN_INPUT_UNIT} paket!";
    }
    foreach ($input_quantities as $key => $qty) {
        if ($qty % $MIN_INPUT_UNIT !== 0) {
            $error_message = "Jumlah {$PACK_MAPPING[$key]} harus kelipatan {$MIN_INPUT_UNIT} (2, 4, 6, dst).";
        }
    }

    if (isset($error_message)) {
        header("Location: data-masak.php?msg=" . urlencode($error_message) . "&type=error" . "&employee_id=" . $employee_id_to_submit);
        exit;
    }

    $week_number = (int)$date_obj->format('W');
    $year = (int)$date_obj->format('Y');
    $input_time = date('Y-m-d H:i:s'); 
    
    // --- START TRANSACTION ---
    $conn->begin_transaction();
    $withdrawn_products = [];
    $deposited_products = []; 

    try {
        // 1. Withdraw Gudang
        foreach ($input_quantities as $pack_key => $input_qty) {
            if ($input_qty > 0) {
                $recipe_multiplier = $input_qty / 2;
                foreach ($RECIPES_PER_2_UNIT[$pack_key] as $stock_name => $qty_per_2_unit) {
                    $qty_to_withdraw = $recipe_multiplier * $qty_per_2_unit;
                    
                    // Update & Cek Stok
                    $stmt = $conn->prepare("UPDATE warehouse_stock SET quantity = quantity - ? WHERE product_name = ? AND quantity >= ?");
                    $stmt->bind_param("isi", $qty_to_withdraw, $stock_name, $qty_to_withdraw);
                    $stmt->execute();
                    
                    if ($stmt->affected_rows === 0) {
                        $stmt->close();
                        throw new Exception("Stok gudang '{$stock_name}' tidak cukup! (Butuh: {$qty_to_withdraw})");
                    }
                    $stmt->close();

                    // Log Warehouse
                    $stmt = $conn->prepare("INSERT INTO warehouse_transactions (product_name, employee_id, transaction_type, quantity) VALUES (?, ?, 'withdraw', ?)");
                    $stmt->bind_param("sii", $stock_name, $employee_id_from_form, $qty_to_withdraw);
                    $stmt->execute();
                    $stmt->close();
                    
                    $withdrawn_products[$stock_name] = ($withdrawn_products[$stock_name] ?? 0) + $qty_to_withdraw;
                }
            }
        }
        
        // 2. Deposit Kulkas
        foreach ($input_quantities as $pack_key => $qty_to_deposit) {
            if ($qty_to_deposit > 0) {
                $stock_name = $PACK_MAPPING[$pack_key]; 
                
                $stmt = $conn->prepare("UPDATE refrigerator_stock SET quantity = quantity + ? WHERE product_name = ?");
                $stmt->bind_param("is", $qty_to_deposit, $stock_name);
                $stmt->execute();
                $stmt->close();

                $stmt = $conn->prepare("INSERT INTO refrigerator_transactions (product_name, employee_id, transaction_type, quantity) VALUES (?, ?, 'deposit', ?)");
                $stmt->bind_param("sii", $stock_name, $employee_id_from_form, $qty_to_deposit);
                $stmt->execute();
                $stmt->close();
                
                $deposited_products[$stock_name] = $qty_to_deposit;
            }
        }
        
        // 3. Insert Log Masak
        $stmt = $conn->prepare("
            INSERT INTO cooking_data (
                employee_id, date, input_time, week_number, year, 
                paket_abdul, paket_lacosa, paket_lakse, paket_saiyo, paket_woku
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param("issiiiiiii", 
            $employee_id_from_form, $formatted_date, $input_time, $week_number, $year,              
            $input_quantities['paket_abdul'], $input_quantities['paket_lacosa'],
            $input_quantities['paket_lakse'], $input_quantities['paket_saiyo'],
            $input_quantities['paket_woku']
        );
        $stmt->execute();
        $stmt->close();
        
        $conn->commit(); 
        
        // 4. Notifikasi
        if (!empty($deposited_products)) sendDiscordNotification(['employee_name' => getEmployeeNameById($employee_id_from_form), 'product_list' => $deposited_products], "refrigerator_deposit");
        if (!empty($withdrawn_products)) sendDiscordNotification(['employee_name' => getEmployeeNameById($employee_id_from_form), 'product_list' => $withdrawn_products], "warehouse_withdraw");
        
        header("Location: data-masak.php?msg=Sukses menyimpan data masak!&type=success&employee_id=" . $employee_id_to_submit);
        exit;

    } catch (Exception $e) {
        $conn->rollback();
        header("Location: data-masak.php?msg=" . urlencode("Error: " . $e->getMessage()) . "&type=error&employee_id=" . $employee_id_to_submit);
        exit;
    } 
}

// === FILTER RIWAYAT ===
$filter_start_date = $_GET['start_date'] ?? date('Y-m-d');
$filter_end_date = $_GET['end_date'] ?? date('Y-m-d');

// Query Riwayat dengan Filter
$stmt = $conn->prepare("
    SELECT id, input_time, paket_abdul, paket_lacosa, paket_lakse, paket_saiyo, paket_woku
    FROM cooking_data 
    WHERE employee_id = ? AND date BETWEEN ? AND ?
    ORDER BY input_time DESC
");
$stmt->bind_param("iss", $employee_id_to_submit, $filter_start_date, $filter_end_date);
$stmt->execute();
$history_data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Query Total Akumulasi (All Time)
$stmt = $conn->prepare("
    SELECT SUM(paket_abdul) as abdul, SUM(paket_lacosa) as lacosa, SUM(paket_lakse) as lakse, 
           SUM(paket_saiyo) as saiyo, SUM(paket_woku) as woku
    FROM cooking_data WHERE employee_id = ?
");
$stmt->bind_param("i", $employee_id_to_submit);
$stmt->execute();
$overall_summary = $stmt->get_result()->fetch_assoc();
$stmt->close();
$total_overall_prep = array_sum($overall_summary ?? []);

?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Data Masak - Warung Om Tante V2</title>
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
            background: radial-gradient(circle, rgba(245, 158, 11, 0.15), transparent 70%); pointer-events: none; z-index: 0;
        }
        .header-content-wrapper { display: flex; align-items: center; gap: 1.25rem; z-index: 2; position: relative; }
        .header-icon-wrapper {
            background: rgba(255, 255, 255, 0.05); border: 1px solid rgba(255, 255, 255, 0.1);
            width: 60px; height: 60px; border-radius: 16px; display: flex; align-items: center;
            justify-content: center; font-size: 1.8rem; box-shadow: inset 0 0 15px rgba(0,0,0,0.3); flex-shrink: 0; color: #fbbf24;
        }
        .header-text-wrapper h1 { color: #fff; font-weight: 800; font-size: 2.2rem; letter-spacing: -0.02em; margin: 0; }
        .header-text-wrapper p { color: var(--text-secondary); margin: 0.25rem 0 0 0; font-size: 1.05rem; }

        /* --- Alerts --- */
        .success-alert { background: rgba(52, 211, 153, 0.15); border: 1px solid rgba(52, 211, 153, 0.4); color: #6ee7b7; padding: 1rem 1.2rem; border-radius: 14px; margin-bottom: 1.5rem; display: flex; align-items: center; gap: 0.75rem; font-size: 0.95rem; font-weight: 500; animation: slideDown 0.4s ease-out; }
        .error-alert { background: rgba(220, 53, 69, 0.15); border: 1px solid rgba(220, 53, 69, 0.4); color: #fca5a5; padding: 1rem 1.2rem; border-radius: 14px; margin-bottom: 1.5rem; display: flex; align-items: center; gap: 0.75rem; font-size: 0.95rem; font-weight: 500; animation: shake 0.5s ease-in-out; }

        /* --- Layout --- */
        .cooking-layout {
            display: grid; grid-template-columns: 2fr 1fr; gap: 1.5rem; align-items: start; margin-bottom: 2rem;
        }
        @media (max-width: 900px) { .cooking-layout { grid-template-columns: 1fr; } }

        /* --- Cards (Glassmorphism) --- */
        .modern-card {
            background: rgba(30, 41, 59, 0.6) !important; backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.08) !important; border-radius: 24px;
            box-shadow: 0 15px 35px rgba(0,0,0,0.3) !important; padding: 2rem;
            animation: slideUp 0.6s ease-out 0.2s backwards;
        }
        .modern-card-header {
            display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;
            margin-bottom: 1.5rem; border-bottom: 1px solid rgba(255, 255, 255, 0.1); padding-bottom: 1rem;
        }
        .modern-card-header h3 { color: #fff; font-size: 1.25rem; font-weight: 700; margin: 0; display: flex; align-items: center; gap: 10px;}
        .current-time-modern { background: rgba(245, 158, 11, 0.15); color: #fbbf24; padding: 6px 12px; border-radius: 20px; font-size: 0.85rem; font-weight: 700; font-family: monospace; border: 1px solid rgba(245, 158, 11, 0.3);}

        /* --- Form Filter (Inner Wrapper) --- */
        .form-inner-wrapper {
            background: linear-gradient(145deg, rgba(30, 41, 59, 0.4), rgba(15, 23, 42, 0.6));
            border: 1px solid rgba(255, 255, 255, 0.05); border-radius: 20px; padding: 1.5rem;
            box-shadow: inset 0 0 20px rgba(0,0,0,0.2); margin-bottom: 1.5rem;
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
        .modern-input:focus, .modern-select:focus { outline: none; border-color: #fbbf24; background: rgba(0,0,0,0.6); box-shadow: 0 0 0 3px rgba(245, 158, 11, 0.15);}
        input[type="date"].modern-input::-webkit-calendar-picker-indicator { filter: invert(1); cursor: pointer; opacity: 0.6; }

        /* --- Info Box Modern --- */
        .modern-info-box {
            background: rgba(59, 130, 246, 0.1); border: 1px solid rgba(59, 130, 246, 0.3);
            border-radius: 14px; padding: 1rem 1.25rem; color: #93c5fd; font-size: 0.9rem; line-height: 1.6;
            display: flex; align-items: center; gap: 15px; margin-bottom: 1.5rem;
        }

        /* --- Product Grid --- */
        .sales-input-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 1rem; }
        .product-card-modern {
            background: rgba(0, 0, 0, 0.25); border: 1px solid rgba(255, 255, 255, 0.05); border-radius: 16px; padding: 1.25rem;
            display: flex; flex-direction: column; gap: 8px; position: relative; transition: 0.3s;
        }
        .product-card-modern:hover { border-color: rgba(255,255,255,0.15); background: rgba(0,0,0,0.4); transform: translateY(-2px); }
        
        .product-card-modern label { font-size: 0.95rem; font-weight: 800; color: #fff; margin: 0; }
        .quantity-group-modern { display: flex; align-items: center; gap: 10px; margin-top: 10px; }
        .quantity-group-modern label { font-size: 0.8rem; color: #cbd5e1; font-weight: 600; text-transform: uppercase; }
        .qty-input {
            width: 100%; background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); color: #fff;
            padding: 10px; border-radius: 8px; text-align: center; font-size: 1.1rem; font-weight: 700; transition: 0.3s; outline: none;
        }
        .qty-input:focus { border-color: #fbbf24; background: rgba(245, 158, 11, 0.1); }

        /* --- Buttons --- */
        .btn-modern-submit {
            background: linear-gradient(135deg, #f59e0b, #d97706); color: #fff; border: none;
            padding: 1.2rem 2rem; border-radius: 14px; font-size: 1rem; font-weight: 800; text-transform: uppercase;
            letter-spacing: 0.05em; cursor: pointer; transition: all 0.3s ease; display: inline-flex; align-items: center; justify-content: center; gap: 10px; width: 100%; margin-top: 1.5rem;
            box-shadow: 0 8px 20px rgba(245, 158, 11, 0.3);
        }
        .btn-modern-submit:hover { transform: translateY(-3px); box-shadow: 0 12px 25px rgba(245, 158, 11, 0.5); }

        .btn-filter-modern { background: rgba(59, 130, 246, 0.15); color: #60a5fa; border: 1px solid rgba(59, 130, 246, 0.4); padding: 0.8rem 1.2rem; border-radius: 10px; font-weight: 700; cursor: pointer; transition: 0.3s; }
        .btn-filter-modern:hover { background: #3b82f6; color: #fff; }
        .btn-reset-modern { background: rgba(239, 68, 68, 0.15); color: #fca5a5; border: 1px solid rgba(239, 68, 68, 0.4); padding: 0.8rem 1.2rem; border-radius: 10px; font-weight: 700; cursor: pointer; transition: 0.3s; text-decoration: none;}
        .btn-reset-modern:hover { background: #ef4444; color: #fff; }

        /* === KARTU ESTIMASI (DARK MODE STYLE) === */
        .estimate-card-modern {
            background: linear-gradient(145deg, rgba(16, 185, 129, 0.05), rgba(0,0,0,0.4));
            border: 1px solid rgba(16, 185, 129, 0.3); border-radius: 20px; padding: 1.5rem;
            box-shadow: 0 10px 25px rgba(0,0,0,0.2); position: sticky; top: 100px;
        }
        .estimate-header-modern {
            color: #34d399; font-size: 1.1rem; font-weight: 800; display: flex; align-items: center; gap: 8px; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 1.2rem; padding-bottom: 1rem; border-bottom: 1px dashed rgba(16, 185, 129, 0.3);
        }
        .ingredient-list { list-style: none; padding: 0; margin: 0; }
        .ingredient-item { display: flex; justify-content: space-between; align-items: center; padding: 8px 0; border-bottom: 1px dashed rgba(255,255,255,0.05); }
        .ingredient-item:last-child { border-bottom: none; }
        .ingredient-name { color: #cbd5e1; font-weight: 600; font-size: 0.95rem; }
        .ingredient-qty { font-weight: 800; color: #fff; font-size: 1.1rem; background: rgba(16, 185, 129, 0.2); padding: 2px 10px; border-radius: 6px; border: 1px solid rgba(16, 185, 129, 0.4);}
        .empty-estimate { text-align: center; color: #64748b; font-style: italic; padding: 20px 0; font-size: 0.9rem; }

        /* --- Modern Table --- */
        .modern-table-wrapper {
            background: rgba(0, 0, 0, 0.25); border-radius: 18px; border: 1px solid rgba(255, 255, 255, 0.05); overflow-x: auto;
        }
        .report-table { width: 100%; border-collapse: collapse; font-size: 0.9rem; min-width: 800px; color: #e2e8f0; }
        .report-table th, .report-table td {
            padding: 1rem; border-bottom: 1px solid rgba(255, 255, 255, 0.03); text-align: center; vertical-align: middle;
        }
        .report-table th { background: rgba(255, 255, 255, 0.03); font-weight: 600; text-transform: uppercase; font-size: 0.75rem; letter-spacing: 0.05em; color: #94a3b8; }
        .report-table td:first-child { text-align: left; }
        .report-table tr:hover td { background: rgba(255, 255, 255, 0.03); }

        /* --- Stats Grid Modern --- */
        .stats-grid-modern { display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 1rem; margin-bottom: 2rem; }
        .stat-card-modern {
            background: rgba(30, 41, 59, 0.5); backdrop-filter: blur(16px); border: 1px solid rgba(255, 255, 255, 0.05); border-radius: 16px; padding: 1.25rem; text-align: center; transition: 0.3s;
        }
        .stat-card-modern:hover { transform: translateY(-3px); border-color: rgba(255,255,255,0.15); background: rgba(30, 41, 59, 0.8); }
        .stat-card-modern h4 { font-size: 0.8rem; color: #94a3b8; margin: 0 0 5px 0; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; }
        .stat-card-modern .value { font-size: 1.8rem; font-weight: 800; color: #fff; }

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
            border: 1px solid rgba(245, 158, 11, 0.3); border-top: 4px solid #f59e0b;
            border-radius: 24px; padding: 2rem; width: 90%; max-width: 450px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
            transform: translateY(30px); transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .modal-overlay.active .modal-modern { transform: translateY(0); }
        
        .modal-header-modern { display: flex; align-items: center; gap: 12px; margin-bottom: 1.5rem; border-bottom: 1px solid rgba(255,255,255,0.1); padding-bottom: 1rem;}
        .modal-icon { font-size: 2rem; background: rgba(245, 158, 11, 0.15); width: 50px; height: 50px; display: flex; align-items: center; justify-content: center; border-radius: 14px; border: 1px solid rgba(245, 158, 11, 0.3); }
        .modal-header-modern h3 { margin: 0; color: #fff; font-size: 1.3rem; font-weight: 800; }
        
        .modal-body-modern { margin-bottom: 2rem; }
        .modal-list-item { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px dashed rgba(255,255,255,0.05); color: #cbd5e1; font-size: 0.95rem;}
        .modal-list-item span:last-child { font-weight: 700; color: #fde68a; }

        .modal-footer-modern { display: flex; gap: 10px; }
        .modal-btn { flex: 1; padding: 1rem; border-radius: 12px; font-weight: 800; font-size: 0.95rem; cursor: pointer; text-transform: uppercase; border: none; transition: 0.2s;}
        .btn-cancel { background: rgba(255,255,255,0.05); color: #cbd5e1; border: 1px solid rgba(255,255,255,0.1); }
        .btn-cancel:hover { background: rgba(255,255,255,0.1); color: #fff; }
        .btn-confirm { background: linear-gradient(135deg, #f59e0b, #d97706); color: #fff; box-shadow: 0 5px 15px rgba(245, 158, 11, 0.3); }
        .btn-confirm:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(245, 158, 11, 0.4); }

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
                    <div class="header-icon-wrapper">🔪</div>
                    <div class="header-text-wrapper">
                        <h1>Data Masak & Produksi</h1>
                        <p>Input paket masak harian. Stok bahan di gudang akan terpotong secara otomatis.</p>
                    </div>
                </div>
            </div>

            <?php if (isset($success_message)): ?>
                <div class="success-alert"><span>🎉</span> <?= htmlspecialchars($success_message) ?></div>
            <?php endif; ?>
            <?php if (isset($error_message)): ?>
                <div class="error-alert"><span>❌</span> <?= htmlspecialchars($error_message) ?></div>
            <?php endif; ?>

            <div class="cooking-layout">
                
                <div class="left-column">
                    <div class="modern-card" style="margin-bottom: 0;">
                        <div class="modern-card-header">
                            <h3><span>👨‍🍳</span> Input Masak Baru</h3>
                            <div class="current-time-modern"><span id="current-time"><?= date('H:i:s') ?></span> WIB</div>
                        </div>
                        
                        <div class="modern-info-box">
                            <span class="icon">💡</span>
                            <div>Input wajib dalam <strong>kelipatan 2</strong> (2, 4, 6...). Perhatikan estimasi bahan di sebelah kanan sebelum memproses.</div>
                        </div>

                        <div class="form-inner-wrapper" style="box-shadow: none; border: none; padding: 0; background: transparent;">
                            <form method="POST" id="sales-form">
                                <input type="hidden" name="action" value="update_masak">
                                <input type="hidden" name="employee_id" value="<?= $employee_id_to_submit ?>">
                                
                                <?php if ($is_admin_or_manager): ?>
                                <div class="modern-form-group">
                                    <label>Pilih Koki / Staff</label>
                                    <div class="modern-input-wrapper">
                                        <span class="modern-input-icon">👤</span>
                                        <select name="employee_id_select" class="modern-select" onchange="window.location.href='data-masak.php?employee_id=' + this.value">
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
                                </div>
                                <?php endif; ?>
                                
                                <div class="modern-form-group">
                                    <label>Tanggal Produksi</label>
                                    <div class="modern-input-wrapper">
                                        <span class="modern-input-icon">📅</span>
                                        <input type="date" name="date" value="<?= htmlspecialchars(date('Y-m-d')) ?>" class="modern-input" required max="<?= date('Y-m-d') ?>">
                                    </div>
                                </div>
                                
                                <div class="sales-input-grid">
                                    <div class="product-card-modern">
                                        <label>ABDUL PACK</label>
                                        <div class="quantity-group-modern">
                                            <label>Jml:</label>
                                            <input type="number" id="inp_abdul" name="paket_abdul" class="qty-input" data-recipe="paket_abdul" value="0" min="0" step="2">
                                        </div>
                                    </div>
                                    <div class="product-card-modern">
                                        <label>LACOSA PACK</label>
                                        <div class="quantity-group-modern">
                                            <label>Jml:</label>
                                            <input type="number" id="inp_lacosa" name="paket_lacosa" class="qty-input" data-recipe="paket_lacosa" value="0" min="0" step="2">
                                        </div>
                                    </div>
                                    <div class="product-card-modern">
                                        <label>LAKSE PACK</label>
                                        <div class="quantity-group-modern">
                                            <label>Jml:</label>
                                            <input type="number" id="inp_lakse" name="paket_lakse" class="qty-input" data-recipe="paket_lakse" value="0" min="0" step="2">
                                        </div>
                                    </div>
                                    <div class="product-card-modern">
                                        <label>SAIYO PACK</label>
                                        <div class="quantity-group-modern">
                                            <label>Jml:</label>
                                            <input type="number" id="inp_saiyo" name="paket_saiyo" class="qty-input" data-recipe="paket_saiyo" value="0" min="0" step="2">
                                        </div>
                                    </div>
                                    <div class="product-card-modern">
                                        <label>WOKU PACK</label>
                                        <div class="quantity-group-modern">
                                            <label>Jml:</label>
                                            <input type="number" id="inp_woku" name="paket_woku" class="qty-input" data-recipe="paket_woku" value="0" min="0" step="2">
                                        </div>
                                    </div>
                                </div>
                                
                                <button type="button" class="btn-modern-submit" id="btn-pre-submit">
                                    <span>Proses Masak & Update Stok</span> 🔥
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="right-column">
                    <div class="estimate-card-modern" id="estimate-card">
                        <div class="estimate-header-modern">
                            <span>📋</span> Estimasi Bahan Dibutuhkan
                        </div>
                        <div id="ingredient-estimate-content">
                            <div class="empty-estimate">
                                Masukkan jumlah paket di form sebelah kiri untuk melihat rincian bahan yang akan ditarik dari gudang.
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="modern-card">
                <div class="modern-card-header">
                    <h3><span>📜</span> Riwayat Data Masak</h3>
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
                            <a href="data-masak.php" class="btn-reset-modern">✖ Reset</a>
                        </div>
                    </form>
                </div>

                <div class="card-content">
                    <?php if (empty($history_data)): ?>
                        <div class="no-data" style="text-align:center; padding: 2rem; color:#64748b; font-style:italic;">Tidak ada data masak pada rentang tanggal ini.</div>
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
                                        <th style="text-align:center;">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($history_data as $entry): ?>
                                    <tr>
                                        <td>
                                            <div style="color:#fff; font-weight:600;"><?= date('d/m/Y', strtotime($entry['input_time'])) ?></div>
                                            <div style="color:#64748b; font-size:0.8rem;"><?= date('H:i', strtotime($entry['input_time'])) ?> WIB</div>
                                        </td>
                                        <td><?= $entry['paket_abdul'] ?: '-' ?></td>
                                        <td><?= $entry['paket_lacosa'] ?: '-' ?></td>
                                        <td><?= $entry['paket_lakse'] ?: '-' ?></td>
                                        <td><?= $entry['paket_saiyo'] ?: '-' ?></td>
                                        <td><?= $entry['paket_woku'] ?: '-' ?></td>
                                        <td style="text-align:center;">
                                            <form method="POST" onsubmit="return confirm('Yakin menghapus log ini? Stok bahan gudang tidak akan kembali secara otomatis.')" style="margin:0;">
                                                <input type="hidden" name="action" value="delete_masak_entry">
                                                <input type="hidden" name="cooking_entry_id" value="<?= $entry['id'] ?>">
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
            
            <div class="modern-card">
                <div class="modern-card-header" style="border-bottom: none; margin-bottom: 0; padding-bottom: 0;">
                    <h3><span>📈</span> Total Produksi (All Time)</h3>
                    <span style="background: rgba(255,255,255,0.05); padding: 5px 12px; border-radius: 20px; font-size: 0.85rem; color: #94a3b8; font-weight: 600;">Total: <?= $total_overall_prep ?? 0 ?> Paket</span>
                </div>
                <div class="stats-grid-modern" style="margin-top: 1.5rem; margin-bottom: 0;">
                    <div class="stat-card-modern"><h4>Abdul</h4><div class="value"><?= $overall_summary['abdul'] ?? 0 ?></div></div>
                    <div class="stat-card-modern"><h4>Lacosa</h4><div class="value"><?= $overall_summary['lacosa'] ?? 0 ?></div></div>
                    <div class="stat-card-modern"><h4>Lakse</h4><div class="value"><?= $overall_summary['lakse'] ?? 0 ?></div></div>
                    <div class="stat-card-modern"><h4>Saiyo</h4><div class="value"><?= $overall_summary['saiyo'] ?? 0 ?></div></div>
                    <div class="stat-card-modern"><h4>Woku</h4><div class="value"><?= $overall_summary['woku'] ?? 0 ?></div></div>
                </div>
            </div>

        </main>
    </div>

    <div id="confirmation-modal" class="modal-overlay">
        <div class="modal-modern">
            <div class="modal-header-modern">
                <div class="modal-icon">🔥</div>
                <h3>Konfirmasi Produksi Masak</h3>
            </div>
            
            <div style="font-size: 0.9rem; color: #94a3b8; margin-bottom: 1rem; line-height: 1.5;">
                Pastikan paket yang diinput sudah benar. <strong>Stok bahan baku gudang akan terpotong secara permanen</strong> dan berpindah ke Kulkas Resto.
            </div>

            <div class="modal-body-modern" id="modal-items-list">
                </div>

            <div class="modal-footer-modern">
                <button type="button" class="modal-btn btn-cancel" onclick="closeModal()">Batal</button>
                <button type="button" class="modal-btn btn-confirm" onclick="submitRealForm()">Ya, Masak Sekarang</button>
            </div>
        </div>
    </div>

    <script src="script.js"></script> 
    <script>
        // === 1. DATA RESEP DARI PHP ===
        const recipes = <?= json_encode($RECIPES_PER_2_UNIT) ?>;
        
        // === 2. FUNGSI UPDATE ESTIMASI ===
        function updateIngredientEstimate() {
            let totalIngredients = {};
            let hasInput = false;

            document.querySelectorAll('.qty-input').forEach(input => {
                const qty = parseInt(input.value) || 0;
                const recipeKey = input.dataset.recipe;

                if (qty > 0 && recipes[recipeKey]) {
                    hasInput = true;
                    // Rumus: (Input / 2) * Bahan Per 2 Paket
                    const multiplier = qty / 2;

                    for (const [ingredient, amountPerUnit] of Object.entries(recipes[recipeKey])) {
                        const totalNeeded = amountPerUnit * multiplier;
                        if (!totalIngredients[ingredient]) totalIngredients[ingredient] = 0;
                        totalIngredients[ingredient] += totalNeeded;
                    }
                }
            });

            // Render ke HTML
            const container = document.getElementById('ingredient-estimate-content');
            if (!hasInput) {
                container.innerHTML = '<div class="empty-estimate">Masukkan jumlah paket untuk melihat total bahan.</div>';
                return;
            }

            let html = '<ul class="ingredient-list">';
            
            // --- LOGIKA PENGURUTAN (ASCENDING BY QUANTITY) ---
            const sortedIngredients = Object.keys(totalIngredients).sort((a, b) => {
                return totalIngredients[a] - totalIngredients[b];
            });
            // --------------------------------------------------
            
            sortedIngredients.forEach(ing => {
                html += `
                    <li class="ingredient-item">
                        <span class="ingredient-name">${ing}</span>
                        <span class="ingredient-qty">${totalIngredients[ing]}</span>
                    </li>
                `;
            });
            html += '</ul>';
            container.innerHTML = html;
        }

        // === 3. EVENT LISTENER ===
        document.querySelectorAll('.qty-input').forEach(input => {
            input.addEventListener('input', function() {
                updateIngredientEstimate();
            });
            // Auto correct step on change
            input.addEventListener('change', function() {
                let val = parseInt(this.value) || 0;
                if(val % 2 !== 0) {
                    this.value = Math.round(val/2)*2;
                    updateIngredientEstimate();
                }
            });
        });

        // Clock Update
        function updateTime() {
            document.getElementById('current-time').textContent = new Date().toLocaleTimeString('id-ID', { hour12: false });
        }
        setInterval(updateTime, 1000);
        updateTime();

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
                'inp_woku': 'Woku Pack'
            };

            for (const [id, name] of Object.entries(names)) {
                const val = parseInt(document.getElementById(id).value) || 0;
                if (val > 0) {
                    items.push({name: name, qty: val});
                    totalItems += val;
                }
            }

            if (totalItems === 0) {
                alert('Harap masukkan minimal 2 paket makanan (kelipatan 2)!');
                return;
            }

            // Build Modal List
            let html = '';
            items.forEach(i => {
                html += `<div class="modal-list-item"><span>${i.name}</span><span>${i.qty} Paket</span></div>`;
            });

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