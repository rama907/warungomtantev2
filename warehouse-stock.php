<?php
// File: warehouse-stock.php
require_once 'config.php';

if (!isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$user = getCurrentUser();
$pending_requests_count = getPendingRequestCount();
$is_manager_or_higher = hasRole(['ceo', 'direktur', 'wakil_direktur', 'manager']);

$success = null;
$error = null;

// Tentukan tanggal filter default (Hari Ini)
$selected_date = $_GET['filter_date'] ?? date('Y-m-d');
$filter_date_sql = $selected_date;

// Handle form submission for deposit or withdraw
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = trim($_POST['action']);
    $quantities = $_POST['quantities'] ?? [];
    
    // Filter hanya produk dengan kuantitas > 0
    $items_to_process = array_filter($quantities, fn($qty) => (int)$qty > 0);
    
    if (empty($items_to_process)) {
        $error = "Pilih minimal satu produk dengan jumlah lebih dari 0!";
    } else {
        $conn->begin_transaction();
        $successful_logs = 0;
        $processed_items_list = [];

        try {
            foreach ($items_to_process as $product_name => $quantity) {
                $quantity = (int)$quantity;
                
                // 1. Get current stock (FOR UPDATE prevents race conditions)
                $stmt = $conn->prepare("SELECT quantity FROM warehouse_stock WHERE product_name = ? FOR UPDATE");
                if (!$stmt) {
                    throw new Exception("Gagal menyiapkan query cek stok gudang: " . $conn->error);
                }
                $stmt->bind_param("s", $product_name);
                $stmt->execute();
                $result = $stmt->get_result()->fetch_assoc();
                $current_quantity = $result['quantity'] ?? 0;
                $stmt->close();
    
                $new_quantity = $current_quantity;
                $transaction_type = '';

                if ($action === 'deposit') {
                    $new_quantity += $quantity;
                    $transaction_type = 'deposit';
                } elseif ($action === 'withdraw') {
                    if ($current_quantity < $quantity) {
                        throw new Exception("Stok produk " . str_replace('_', ' ', $product_name) . " tidak mencukupi! Stok: {$current_quantity}.");
                    }
                    $new_quantity -= $quantity;
                    $transaction_type = 'withdraw';
                } else {
                    throw new Exception("Aksi tidak valid.");
                }

                // 2. Update stock
                $stmt = $conn->prepare("UPDATE warehouse_stock SET quantity = ? WHERE product_name = ?");
                if (!$stmt) {
                     throw new Exception("Gagal menyiapkan query update stok gudang: " . $conn->error);
                }
                $stmt->bind_param("is", $new_quantity, $product_name);
                $stmt->execute();
                $stmt->close();

                // 3. Log transaction
                $stmt = $conn->prepare("INSERT INTO warehouse_transactions (product_name, employee_id, transaction_type, quantity) VALUES (?, ?, ?, ?)");
                if (!$stmt) {
                    throw new Exception("Gagal menyiapkan query log transaksi gudang: " . $conn->error);
                }
                // Pastikan binding parameter benar (string, integer, string, integer)
                $stmt->bind_param("sisi", $product_name, $user['id'], $transaction_type, $quantity);
                if (!$stmt->execute()) {
                    throw new Exception("Gagal menyimpan log transaksi gudang: " . $stmt->error);
                }
                $stmt->close();
                
                $successful_logs++;
                $processed_items_list[] = htmlspecialchars(str_replace('_', ' ', $product_name)) . " (x{$quantity})";
            }
            
            $conn->commit();
            $success = "Transaksi " . ucfirst($action) . " berhasil untuk {$successful_logs} item: " . implode(', ', $processed_items_list);
            
            // >>>>>> MODIFIKASI: Kirim notifikasi Discord untuk stok gudang <<<<<<
            sendDiscordNotification([
                'employee_name' => $user['name'],
                'product_list' => $items_to_process, // Kirim array product_name => quantity
            ], "warehouse_{$action}");
            // >>>>>> AKHIR MODIFIKASI <<<<<<
            
            // Redirect untuk menampilkan pesan sukses dan memuat ulang data
            header("Location: warehouse-stock.php?msg=" . urlencode($success) . "&type=success&filter_date=" . urlencode($selected_date));
            exit;

        } catch (Exception $e) {
            $conn->rollback();
            $error = "Gagal memproses transaksi: " . $e->getMessage();
            // Redirect untuk menampilkan pesan error
            header("Location: warehouse-stock.php?msg=" . urlencode($error) . "&type=error&filter_date=" . urlencode($selected_date));
            exit;
        }
    }
}

// Menampilkan pesan feedback setelah redirect
if (isset($_GET['msg']) && isset($_GET['type'])) {
    $feedback_message = htmlspecialchars($_GET['msg']);
    $feedback_type = htmlspecialchars($_GET['type']);
    if ($feedback_type === 'success') {
        $success = $feedback_message;
    } else {
        $error = $feedback_message;
    }
}

// --- LOGIKA PENGAMBILAN DATA UNTUK FILTER ---

// 1. Get current stock levels (Sorted)
$stock_levels_raw = $conn->query("SELECT * FROM warehouse_stock ORDER BY product_name ASC")->fetch_all(MYSQLI_ASSOC);
// Ubah nama produk untuk tampilan yang lebih rapi di form
$stock_levels = array_map(function($item) {
    return [
        'product_name' => $item['product_name'],
        'display_name' => str_replace('_', ' ', $item['product_name']),
        'quantity' => $item['quantity']
    ];
}, $stock_levels_raw);


// 2. Get Transaction Summary for the selected date
$transaction_summary = [
    'deposit' => 0,
    'withdraw' => 0,
    'details' => []
];

$stmt_summary = $conn->prepare("
    SELECT product_name, transaction_type, SUM(quantity) as total_quantity
    FROM warehouse_transactions
    WHERE DATE(transaction_at) = ?
    GROUP BY product_name, transaction_type
");
if ($stmt_summary) {
    $stmt_summary->bind_param("s", $filter_date_sql);
    $stmt_summary->execute();
    $result_summary = $stmt_summary->get_result();

    while ($row = $result_summary->fetch_assoc()) {
        $type = $row['transaction_type'];
        $product = str_replace('_', ' ', $row['product_name']);
        $qty = (int)$row['total_quantity'];

        $transaction_summary[$type] += $qty;
        
        if (!isset($transaction_summary['details'][$product])) {
            $transaction_summary['details'][$product] = ['deposit' => 0, 'withdraw' => 0];
        }
        $transaction_summary['details'][$product][$type] = $qty;
    }
    $stmt_summary->close();
}


// 3. Get Transaction History for the selected date (only for managers or higher)
$transactions = [];
if ($is_manager_or_higher) {
    $stmt = $conn->prepare("
        SELECT rt.*, e.name as employee_name
        FROM warehouse_transactions rt
        JOIN employees e ON rt.employee_id = e.id
        WHERE DATE(rt.transaction_at) = ?
        ORDER BY rt.transaction_at DESC
        LIMIT 50
    ");
    if ($stmt) {
        $stmt->bind_param("s", $filter_date_sql);
        $stmt->execute();
        $transactions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manajemen Stok Gudang - Warung Om Tante V2</title>
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

        /* --- Info Box Modern --- */
        .modern-info-box {
            background: rgba(59, 130, 246, 0.1); border: 1px solid rgba(59, 130, 246, 0.3);
            border-radius: 14px; padding: 1rem 1.25rem; color: #93c5fd; font-size: 0.95rem; line-height: 1.6;
            display: flex; align-items: flex-start; gap: 15px; margin-bottom: 2rem;
        }

        /* --- Stats Grid Modern --- */
        .stock-grid-modern {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 1.25rem; margin-bottom: 2rem;
        }
        .stock-card-modern {
            background: rgba(30, 41, 59, 0.5); backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.05); border-radius: 20px; padding: 1.5rem;
            position: relative; overflow: hidden; transition: 0.3s; text-align: center;
            display: flex; flex-direction: column; justify-content: center;
        }
        .stock-card-modern:hover { transform: translateY(-5px); border-color: rgba(255,255,255,0.15); box-shadow: 0 15px 30px rgba(0,0,0,0.3); background: rgba(30, 41, 59, 0.8); }
        .stock-card-modern h4 { font-size: 0.9rem; color: #cbd5e1; margin: 0 0 10px 0; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; }
        .stock-card-modern .quantity { font-size: 2.5rem; font-weight: 800; color: #fff; margin: 0;}

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

        /* --- Product Input Grid --- */
        .stock-multi-input-grid {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1.2rem; margin-bottom: 1.5rem;
        }
        .product-input-group-modern {
            background: rgba(0, 0, 0, 0.25); border: 1px solid rgba(255, 255, 255, 0.05); border-radius: 16px; padding: 1.25rem;
            display: flex; flex-direction: column; gap: 8px; position: relative; transition: 0.3s;
        }
        .product-input-group-modern:focus-within { border-color: #f59e0b; background: rgba(245, 158, 11, 0.05); }
        .product-input-group-modern label { font-size: 0.95rem; font-weight: 800; color: #fff; margin: 0; }
        .qty-input {
            width: 100%; background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); color: #fff;
            padding: 10px; border-radius: 8px; text-align: center; font-size: 1.1rem; font-weight: 700; transition: 0.3s; outline: none;
        }
        .qty-input:focus { border-color: #f59e0b; background: rgba(245, 158, 11, 0.1); }

        /* Realtime Counter */
        .realtime-counter-modern {
            background: rgba(0,0,0,0.3); border: 1px dashed rgba(255,255,255,0.2); border-radius: 14px; padding: 1rem;
            text-align: center; margin-bottom: 1.5rem; color: #cbd5e1; font-size: 1rem; font-weight: 600;
        }
        .realtime-counter-modern span { color: #f59e0b; font-weight: 800; font-size: 1.3rem; margin-left: 5px; }

        /* --- Buttons --- */
        .form-actions-modern { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; border-top: 1px solid rgba(255, 255, 255, 0.1); padding-top: 1.5rem; }
        
        .btn-modern-action {
            width: 100%; border: none; padding: 1.2rem; border-radius: 14px; font-size: 0.95rem; font-weight: 800; text-transform: uppercase;
            letter-spacing: 0.05em; cursor: pointer; transition: all 0.3s ease; display: inline-flex; align-items: center; justify-content: center; gap: 10px;
        }
        .btn-deposit-modern { background: linear-gradient(135deg, #10b981, #059669); color: #fff; box-shadow: 0 8px 20px rgba(16, 185, 129, 0.3); }
        .btn-deposit-modern:hover { transform: translateY(-3px); box-shadow: 0 12px 25px rgba(16, 185, 129, 0.5); }
        
        .btn-withdraw-modern { background: linear-gradient(135deg, #ef4444, #b91c1c); color: #fff; box-shadow: 0 8px 20px rgba(239, 68, 68, 0.3); }
        .btn-withdraw-modern:hover { transform: translateY(-3px); box-shadow: 0 12px 25px rgba(239, 68, 68, 0.5); }

        /* --- Transaction History (Manager+) --- */
        .filter-control-modern {
            background: linear-gradient(145deg, rgba(30, 41, 59, 0.4), rgba(15, 23, 42, 0.6));
            border: 1px solid rgba(255, 255, 255, 0.05); border-radius: 20px; padding: 1.5rem;
            box-shadow: inset 0 0 20px rgba(0,0,0,0.2); margin-bottom: 2rem; display: flex; gap: 15px; align-items: flex-end; flex-wrap: wrap;
        }
        .modern-input-wrapper { display: flex; flex-direction: column; gap: 6px; width: 100%; max-width: 300px;}
        .modern-input-wrapper label { font-size: 0.85rem; font-weight: 600; color: #cbd5e1; text-transform: uppercase; letter-spacing: 0.05em; }
        .modern-input {
            width: 100%; background: rgba(0, 0, 0, 0.4); border: 1px solid rgba(255, 255, 255, 0.1);
            color: white; padding: 0.8rem 1rem; border-radius: 12px; font-size: 0.95rem; transition: 0.3s;
        }
        .modern-input:focus { outline: none; border-color: #f59e0b; background: rgba(0,0,0,0.6); }
        input[type="date"].modern-input::-webkit-calendar-picker-indicator { filter: invert(1); cursor: pointer; opacity: 0.6; }
        
        .btn-filter-modern { background: rgba(245, 158, 11, 0.15); color: #fbbf24; border: 1px solid rgba(245, 158, 11, 0.4); padding: 0.8rem 1.5rem; border-radius: 12px; font-weight: 700; cursor: pointer; transition: 0.3s; height: 100%;}
        .btn-filter-modern:hover { background: #f59e0b; color: #fff; }

        /* Summary Cards */
        .daily-summary-cards-modern { display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; margin-bottom: 2rem; }
        .dsc-card {
            background: rgba(0, 0, 0, 0.25); border: 1px solid rgba(255, 255, 255, 0.05); border-radius: 16px; padding: 1.5rem; position: relative; overflow: hidden;
        }
        .dsc-card::before { content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 4px; }
        .dsc-deposit::before { background: #10b981; box-shadow: 0 0 10px #10b981; }
        .dsc-withdraw::before { background: #ef4444; box-shadow: 0 0 10px #ef4444; }
        
        .dsc-title { font-size: 0.9rem; color: #cbd5e1; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; margin: 0 0 5px 0;}
        .dsc-total { font-size: 2rem; font-weight: 800; margin-bottom: 15px; }
        .dsc-deposit .dsc-total { color: #34d399; }
        .dsc-withdraw .dsc-total { color: #fca5a5; }

        .dsc-list { list-style: none; padding: 0; margin: 0; display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
        .dsc-item { background: rgba(255,255,255,0.05); padding: 6px 10px; border-radius: 8px; font-size: 0.85rem; color: #94a3b8; display: flex; justify-content: space-between; }
        .dsc-item strong { color: #fff; }

        /* Log List */
        .history-list-modern { display: flex; flex-direction: column; gap: 10px; }
        .history-item-modern {
            background: rgba(0, 0, 0, 0.25); border: 1px solid rgba(255, 255, 255, 0.03); border-radius: 14px; padding: 1.25rem;
            display: flex; justify-content: space-between; align-items: center; transition: 0.3s; flex-wrap: wrap; gap: 10px;
        }
        .history-item-modern:hover { background: rgba(255, 255, 255, 0.03); transform: translateX(5px); border-color: rgba(255,255,255,0.08); }
        .hi-left { display: flex; flex-direction: column; gap: 4px; }
        .hi-product { font-size: 1rem; font-weight: 800; color: #fff; text-transform: capitalize;}
        .hi-meta { font-size: 0.8rem; color: #94a3b8; }
        .hi-meta strong { color: #cbd5e1; }
        .hi-right { display: flex; align-items: center; gap: 15px; }
        
        .badge-type { font-size: 0.7rem; font-weight: 800; padding: 4px 10px; border-radius: 20px; text-transform: uppercase; letter-spacing: 0.1em; }
        .bt-deposit { background: rgba(16, 185, 129, 0.15); color: #34d399; border: 1px solid rgba(16, 185, 129, 0.3); }
        .bt-withdraw { background: rgba(239, 68, 68, 0.15); color: #fca5a5; border: 1px solid rgba(239, 68, 68, 0.3); }
        .hi-qty { font-size: 1.3rem; font-weight: 900; color: #fff; width: 40px; text-align: right;}

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
            border: 1px solid rgba(255, 255, 255, 0.1); border-top: 4px solid #fbbf24;
            border-radius: 24px; padding: 2rem; width: 90%; max-width: 450px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
            transform: translateY(30px); transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .modal-overlay.active .modal-modern { transform: translateY(0); }
        
        .modal-header-modern { display: flex; align-items: center; gap: 12px; margin-bottom: 1.5rem; border-bottom: 1px solid rgba(255,255,255,0.1); padding-bottom: 1rem;}
        .modal-icon { font-size: 2rem; background: rgba(255, 255, 255, 0.05); width: 50px; height: 50px; display: flex; align-items: center; justify-content: center; border-radius: 14px; border: 1px solid rgba(255, 255, 255, 0.1); }
        .modal-header-modern h3 { margin: 0; color: #fff; font-size: 1.3rem; font-weight: 800; }
        
        .modal-body-modern { margin-bottom: 2rem; }
        .modal-desc { font-size: 0.9rem; color: #94a3b8; margin-bottom: 1rem; line-height: 1.5; }
        .modal-list-item { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px dashed rgba(255,255,255,0.05); color: #cbd5e1; font-size: 0.95rem;}
        .modal-list-item span:last-child { font-weight: 700; color: #fff; }
        .modal-list-total { margin-top: 10px; padding-top: 10px; border-top: 2px solid rgba(255, 255, 255, 0.2); display: flex; justify-content: space-between; font-size: 1.1rem; font-weight: 800; color: #fff; }

        .modal-footer-modern { display: flex; gap: 10px; }
        .modal-btn { flex: 1; padding: 1rem; border-radius: 12px; font-weight: 800; font-size: 0.95rem; cursor: pointer; text-transform: uppercase; border: none; transition: 0.2s;}
        .btn-cancel { background: rgba(255,255,255,0.05); color: #cbd5e1; border: 1px solid rgba(255,255,255,0.1); }
        .btn-cancel:hover { background: rgba(255,255,255,0.1); color: #fff; }
        
        /* Dynamic Button Colors in Modal */
        .btn-confirm-deposit { background: linear-gradient(135deg, #10b981, #059669); color: #fff; box-shadow: 0 5px 15px rgba(16, 185, 129, 0.3); }
        .btn-confirm-deposit:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(16, 185, 129, 0.4); }
        .btn-confirm-withdraw { background: linear-gradient(135deg, #ef4444, #b91c1c); color: #fff; box-shadow: 0 5px 15px rgba(239, 68, 68, 0.3); }
        .btn-confirm-withdraw:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(239, 68, 68, 0.4); }

        @keyframes slideDown { from { opacity: 0; transform: translateY(-20px); } to { opacity: 1; transform: translateY(0); } }
        @keyframes slideUp { from { opacity: 0; transform: translateY(30px); } to { opacity: 1; transform: translateY(0); } }
        @keyframes shake { 0%, 100% { transform: translateX(0); } 25% { transform: translateX(-5px); } 75% { transform: translateX(5px); } }

        @media (max-width: 768px) {
            .modern-page-header { padding: 1.5rem; text-align: center; }
            .header-content-wrapper { flex-direction: column; }
            .form-actions-modern { grid-template-columns: 1fr; }
            .daily-summary-cards-modern { grid-template-columns: 1fr; }
            .dsc-list { grid-template-columns: 1fr; }
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
                    <div class="header-icon-wrapper">📦</div>
                    <div class="header-text-wrapper">
                        <h1>Manajemen Stok Gudang</h1>
                        <p>Kelola jumlah fisik deposit (masuk) dan withdraw (keluar) stok bahan baku.</p>
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
                <div class="modern-card-header" style="border-bottom: none; margin-bottom: 0; padding-bottom: 0;">
                    <h3><span>📈</span> Ringkasan Stok Gudang Saat Ini</h3>
                </div>
                <div class="stock-grid-modern" style="margin-top: 1.5rem; margin-bottom: 0;">
                    <?php foreach ($stock_levels as $stock): ?>
                        <div class="stock-card-modern">
                            <h4><?= htmlspecialchars($stock['display_name']) ?></h4>
                            <div class="quantity"><?= $stock['quantity'] ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="modern-card">
                <div class="modern-card-header">
                    <h3><span>📝</span> Form Transaksi Stok (Unit)</h3>
                </div>
                
                <div class="modern-info-box">
                    <span class="icon">💡</span>
                    <div><strong>Penting:</strong> Masukkan <strong>jumlah per unit/kemasan</strong> yang akan masuk (Deposit) atau keluar (Withdraw) dari stok gudang secara manual.</div>
                </div>

                <div class="card-content">
                    <form method="POST" id="stock-form">
                        <input type="hidden" name="action" id="form-action-input" value="">

                        <div class="stock-multi-input-grid">
                            <?php foreach ($stock_levels as $stock): ?>
                                <div class="product-input-group-modern">
                                    <label for="quantity_<?= $stock['product_name'] ?>"><?= htmlspecialchars($stock['display_name']) ?></label>
                                    <input type="number" 
                                           name="quantities[<?= htmlspecialchars($stock['product_name']) ?>]" 
                                           id="quantity_<?= $stock['product_name'] ?>" 
                                           class="qty-input" 
                                           min="0" 
                                           value="0"
                                           data-name="<?= htmlspecialchars($stock['display_name']) ?>">
                                </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <div class="realtime-counter-modern">
                            Total Item Dipilih: <span id="realtime-total">0</span>
                        </div>
                        
                        <div class="form-actions-modern">
                            <button type="button" class="btn-modern-action btn-deposit-modern" onclick="openModal('deposit')">
                                <span>➕</span> Deposit Item Dipilih
                            </button>
                            <button type="button" class="btn-modern-action btn-withdraw-modern" onclick="openModal('withdraw')">
                                <span>➖</span> Withdraw Item Dipilih
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <?php if ($is_manager_or_higher): ?>
                <div class="modern-card">
                    <div class="modern-card-header">
                        <h3><span>📊</span> Laporan & Riwayat Transaksi</h3>
                    </div>
                    
                    <div class="filter-control-modern">
                        <form method="GET" style="display: flex; gap: 15px; align-items: flex-end; width: 100%; margin: 0;">
                            <div class="modern-input-wrapper">
                                <label for="filter_date">Filter Berdasarkan Tanggal</label>
                                <input type="date" name="filter_date" id="filter_date" class="modern-input" value="<?= htmlspecialchars($selected_date) ?>" required>
                            </div>
                            <button type="submit" class="btn-filter-modern">🔍 Terapkan Filter</button>
                        </form>
                    </div>

                    <h4 style="color: #cbd5e1; font-size: 1.1rem; border-bottom: 1px solid rgba(255,255,255,0.1); padding-bottom: 10px; margin-bottom: 15px;">
                        Ringkasan Tanggal: <span style="color: #fff; font-weight: 800;"><?= date('d/m/Y', strtotime($selected_date)) ?></span>
                    </h4>
                    
                    <div class="daily-summary-cards-modern">
                        <div class="dsc-card dsc-deposit">
                            <h4 class="dsc-title">Total Keseluruhan Deposit</h4>
                            <div class="dsc-total"><?= $transaction_summary['deposit'] ?> <span style="font-size: 1rem; color: #94a3b8; font-weight: 600;">Unit</span></div>
                            <ul class="dsc-list">
                                <?php 
                                foreach ($transaction_summary['details'] as $product => $details):
                                    if (isset($details['deposit']) && $details['deposit'] > 0): ?>
                                        <li class="dsc-item"><span><?= htmlspecialchars($product) ?></span> <strong><?= $details['deposit'] ?></strong></li>
                                    <?php endif;
                                endforeach; ?>
                            </ul>
                        </div>
                        <div class="dsc-card dsc-withdraw">
                            <h4 class="dsc-title">Total Keseluruhan Withdraw</h4>
                            <div class="dsc-total"><?= $transaction_summary['withdraw'] ?> <span style="font-size: 1rem; color: #94a3b8; font-weight: 600;">Unit</span></div>
                            <ul class="dsc-list">
                                <?php 
                                foreach ($transaction_summary['details'] as $product => $details):
                                    if (isset($details['withdraw']) && $details['withdraw'] > 0): ?>
                                        <li class="dsc-item"><span><?= htmlspecialchars($product) ?></span> <strong><?= $details['withdraw'] ?></strong></li>
                                    <?php endif;
                                endforeach; ?>
                            </ul>
                        </div>
                    </div>

                    <h4 style="color: #cbd5e1; font-size: 1.1rem; border-bottom: 1px solid rgba(255,255,255,0.1); padding-bottom: 10px; margin-bottom: 15px; margin-top: 2rem;">
                        Detail Log Transaksi <span style="font-size: 0.9rem; color: #64748b;">(<?= count($transactions) ?> entri terbaru)</span>
                    </h4>
                    
                    <?php if (empty($transactions)): ?>
                        <div style="text-align: center; padding: 2rem; color: #64748b; font-style: italic;">Tidak ada riwayat transaksi untuk tanggal ini.</div>
                    <?php else: ?>
                        <div class="history-list-modern">
                            <?php foreach ($transactions as $log): ?>
                                <div class="history-item-modern">
                                    <div class="hi-left">
                                        <span class="hi-product"><?= htmlspecialchars(str_replace('_', ' ', $log['product_name'])) ?></span>
                                        <div class="hi-meta">
                                            Oleh <strong><?= htmlspecialchars($log['employee_name']) ?></strong> pada <?= date('H:i:s', strtotime($log['transaction_at'])) ?>
                                        </div>
                                    </div>
                                    <div class="hi-right">
                                        <span class="badge-type <?= $log['transaction_type'] === 'deposit' ? 'bt-deposit' : 'bt-withdraw' ?>">
                                            <?= ucfirst($log['transaction_type']) ?>
                                        </span>
                                        <span class="hi-qty"><?= $log['quantity'] ?></span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

        </main>
    </div>

    <div id="confirmation-modal" class="modal-overlay">
        <div class="modal-modern">
            <div class="modal-header-modern">
                <div class="modal-icon" id="modal-icon-display">📦</div>
                <h3 id="modal-title-display">Konfirmasi Transaksi</h3>
            </div>
            
            <div class="modal-desc" id="modal-desc-display">
                Pastikan jumlah bahan yang dimasukkan sudah benar sebelum melanjutkan.
            </div>

            <div class="modal-body-modern" id="modal-items-list">
                </div>

            <div class="modal-footer-modern">
                <button type="button" class="modal-btn btn-cancel" onclick="closeModal()">Batal</button>
                <button type="button" class="modal-btn" id="modal-btn-confirm" onclick="submitRealForm()">Ya, Proses</button>
            </div>
        </div>
    </div>

    <script src="script.js"></script>
    <script>
        // --- LOGIKA REAL-TIME COUNTER ---
        function updateRealtimeCounter() {
            let total = 0;
            document.querySelectorAll('.qty-input').forEach(input => {
                total += parseInt(input.value) || 0;
            });
            document.getElementById('realtime-total').textContent = total;
        }

        document.querySelectorAll('.qty-input').forEach(input => {
            input.addEventListener('input', updateRealtimeCounter);
        });

        // Initialize on load
        updateRealtimeCounter();

        // --- NEW FEATURE: MODAL LOGIC ---
        const modal = document.getElementById('confirmation-modal');
        const form = document.getElementById('stock-form');
        const actionInput = document.getElementById('form-action-input');
        const modalList = document.getElementById('modal-items-list');
        const modalTitle = document.getElementById('modal-title-display');
        const modalIcon = document.getElementById('modal-icon-display');
        const modalBtnConfirm = document.getElementById('modal-btn-confirm');

        function openModal(actionType) {
            let totalItems = 0;
            const items = [];
            
            // Loop through all quantity inputs
            document.querySelectorAll('.qty-input').forEach(input => {
                const val = parseInt(input.value) || 0;
                if (val > 0) {
                    items.push({
                        name: input.getAttribute('data-name'),
                        qty: val
                    });
                    totalItems += val;
                }
            });

            if (totalItems === 0) {
                alert('Harap pilih minimal satu bahan baku dengan jumlah lebih dari 0!');
                return;
            }

            // Set hidden input value for form submission later
            actionInput.value = actionType;

            // Customize modal based on action type
            if (actionType === 'deposit') {
                modalTitle.textContent = 'Konfirmasi Deposit Gudang';
                modalIcon.innerHTML = '➕';
                modalIcon.style.color = '#34d399';
                modalIcon.style.borderColor = 'rgba(16, 185, 129, 0.3)';
                modalIcon.style.background = 'rgba(16, 185, 129, 0.15)';
                
                modalBtnConfirm.className = 'modal-btn btn-confirm-deposit';
                modalBtnConfirm.textContent = 'Ya, Deposit Data';
            } else {
                modalTitle.textContent = 'Konfirmasi Withdraw Gudang';
                modalIcon.innerHTML = '➖';
                modalIcon.style.color = '#fca5a5';
                modalIcon.style.borderColor = 'rgba(239, 68, 68, 0.3)';
                modalIcon.style.background = 'rgba(239, 68, 68, 0.15)';
                
                modalBtnConfirm.className = 'modal-btn btn-confirm-withdraw';
                modalBtnConfirm.textContent = 'Ya, Withdraw Data';
            }

            // Build Modal List HTML
            let html = '';
            items.forEach(i => {
                html += `<div class="modal-list-item"><span>${i.name}</span><span>${i.qty} Unit</span></div>`;
            });
            
            // Add total row
            html += `<div class="modal-list-total" style="border-top-color: ${actionType === 'deposit' ? 'rgba(16, 185, 129, 0.4)' : 'rgba(239, 68, 68, 0.4)'}; color: ${actionType === 'deposit' ? '#10b981' : '#ef4444'};">
                        <span>Total Keseluruhan:</span><span>${totalItems} Unit</span>
                     </div>`;

            modalList.innerHTML = html;
            modal.classList.add('active');
        }

        function closeModal() {
            modal.classList.remove('active');
        }

        function submitRealForm() {
            // Animasi loading pada tombol modal
            modalBtnConfirm.innerHTML = 'Memproses...';
            modalBtnConfirm.style.opacity = '0.7';
            modalBtnConfirm.style.cursor = 'wait';
            
            // Submit the form natively
            form.submit();
        }
    </script>
</body>
</html>