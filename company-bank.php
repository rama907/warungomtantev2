<?php
require_once 'config.php';

// 1. Cek Hak Akses (Hanya CEO, Direktur, Wakil Direktur)
if (!isLoggedIn() || !hasRole(['ceo', 'direktur', 'wakil_direktur'])) {
    // Jika Manager mencoba akses, kembalikan ke dashboard
    header('Location: dashboard.php');
    exit;
}

$user = getCurrentUser();
$pending_requests_count = getPendingRequestCount();

// --- KONFIGURASI HARGA PENJUALAN (DOLLAR) ---
$PRICE_FOOD_PACK = 150; 
$PRICE_HP = 35;
$PRICE_RADIO = 60;

// --- KONFIGURASI GAJI BARU (DOLLAR) ---
$BASE_SALARIES = [
    'ceo' => 10000,
    'direktur' => 10000,
    'wakil_direktur' => 8000,
    'manager' => 6500,
    'chef' => 5000,
    'waiters' => 3000,
    'karyawan' => 3000,
    'magang' => 2000
];
$MIN_DUTY_HOURS = 10;
$BONUS_DUTY_HOURS = 21;
$BONUS_DUTY_AMOUNT = 1000;
$BONUS_SALES_TIER_1 = 150; 
$BONUS_SALES_TIER_2 = 300; 
$BONUS_SALES_AMOUNT = 1000; 

// Helper Format Uang
function formatMoney($amount) {
    return '$ ' . number_format($amount, 0, ',', '.');
}

$success_message = null;
$error_message = null;

// --- HANDLE POST ACTIONS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $conn->begin_transaction();

    try {
        // A. HANDLE MANUAL DEPOSIT / WITHDRAW
        if ($action === 'manual_transaction') {
            $type = $_POST['type']; // 'deposit' atau 'withdraw'
            $amount = (float)$_POST['amount'];
            $description = trim($_POST['description']);
            
            if ($amount <= 0) throw new Exception("Nominal harus lebih dari 0.");
            if (empty($description)) throw new Exception("Keterangan wajib diisi!");

            // Cek saldo jika withdraw
            if ($type === 'withdraw') {
                $stmt_bal = $conn->query("SELECT (COALESCE(SUM(CASE WHEN type='deposit' THEN amount ELSE 0 END), 0) - COALESCE(SUM(CASE WHEN type='withdraw' THEN amount ELSE 0 END), 0)) as balance FROM company_bank_transactions");
                $current_balance = $stmt_bal->fetch_assoc()['balance'];
                if ($current_balance < $amount) throw new Exception("Saldo brangkas tidak cukup!");
            }

            $category = ($type === 'deposit') ? 'Manual Deposit' : 'Operational Withdraw';
            
            $stmt = $conn->prepare("INSERT INTO company_bank_transactions (type, amount, category, description, performed_by, transaction_date) VALUES (?, ?, ?, ?, ?, NOW())");
            $stmt->bind_param("sdssi", $type, $amount, $category, $description, $user['id']);
            $stmt->execute();
            $stmt->close();

            $success_message = "Transaksi berhasil dicatat.";
        }

        // B. HANDLE SYNC SALES REVENUE (AUTO DEPOSIT DARI PENJUALAN)
        elseif ($action === 'sync_sales_revenue') {
            $stmt = $conn->prepare("
                SELECT 
                    SUM(paket_abdul + paket_lacosa + paket_lakse + paket_saiyo + paket_woku) as total_food,
                    SUM(hp) as total_hp,
                    SUM(radio) as total_radio
                FROM sales_data 
                WHERE is_deposited_to_bank = 0
            ");
            $stmt->execute();
            $res = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            $total_food = (int)$res['total_food'];
            $total_hp = (int)$res['total_hp'];
            $total_radio = (int)$res['total_radio'];

            $revenue_food = $total_food * $PRICE_FOOD_PACK;
            $revenue_hp = $total_hp * $PRICE_HP;
            $revenue_radio = $total_radio * $PRICE_RADIO;
            $total_gross_revenue = $revenue_food + $revenue_hp + $revenue_radio;

            $company_share = $total_gross_revenue * 0.8;

            if ($company_share > 0) {
                $desc = "Auto Deposit Hasil Penjualan (80% Kas): {$total_food} Pkt Makanan, {$total_hp} HP, {$total_radio} Radio. (Total Kotor: $" . number_format($total_gross_revenue, 0, ',', '.') . ")";
                $type = 'deposit';
                $cat = 'Sales Revenue';
                
                $stmt_ins = $conn->prepare("INSERT INTO company_bank_transactions (type, amount, category, description, performed_by, transaction_date) VALUES (?, ?, ?, ?, ?, NOW())");
                $stmt_ins->bind_param("sdssi", $type, $company_share, $cat, $desc, $user['id']);
                $stmt_ins->execute();
                $stmt_ins->close();

                $conn->query("UPDATE sales_data SET is_deposited_to_bank = 1 WHERE is_deposited_to_bank = 0");
                $success_message = "Berhasil menarik pendapatan perusahaan sebesar " . formatMoney($company_share) . " (80% dari total omset) ke Brangkas.";
            } else {
                throw new Exception("Tidak ada pendapatan penjualan baru untuk disetor.");
            }
        }
        
        // C. HANDLE SYNC PAYROLL WITHDRAW (AUTO WITHDRAW UNTUK GAJI PAID)
        elseif ($action === 'sync_payroll_withdraw') {
            $total_wd_payroll = (float)$_POST['total_payroll_amount'];
            $employee_count = (int)$_POST['paid_employee_count'];

            if ($total_wd_payroll > 0) {
                $stmt_bal = $conn->query("SELECT (COALESCE(SUM(CASE WHEN type='deposit' THEN amount ELSE 0 END), 0) - COALESCE(SUM(CASE WHEN type='withdraw' THEN amount ELSE 0 END), 0)) as balance FROM company_bank_transactions");
                $current_balance = $stmt_bal->fetch_assoc()['balance'];
                
                if ($current_balance < $total_wd_payroll) {
                    throw new Exception("Saldo brangkas tidak cukup untuk membayar gaji! (Butuh: " . formatMoney($total_wd_payroll) . ")");
                }

                $desc = "Auto Withdraw Pembayaran Gaji ({$employee_count} Orang dengan status 'Paid' di Rekap Gaji).";
                $type = 'withdraw';
                $cat = 'Payroll Withdraw';
                
                $stmt_ins = $conn->prepare("INSERT INTO company_bank_transactions (type, amount, category, description, performed_by, transaction_date) VALUES (?, ?, ?, ?, ?, NOW())");
                $stmt_ins->bind_param("sdssi", $type, $total_wd_payroll, $cat, $desc, $user['id']);
                $stmt_ins->execute();
                $stmt_ins->close();

                $success_message = "Berhasil menarik dana gaji sebesar " . formatMoney($total_wd_payroll) . " dari Brangkas.";
            } else {
                throw new Exception("Tidak ada akumulasi gaji yang bisa ditarik.");
            }
        }

        $conn->commit();
    } catch (Exception $e) {
        $conn->rollback();
        $error_message = $e->getMessage();
    }
}

// --- QUERY DATA TAMPILAN ---

// 1. Hitung Saldo Saat Ini
$stmt = $conn->query("
    SELECT 
        (COALESCE(SUM(CASE WHEN type='deposit' THEN amount ELSE 0 END), 0) - 
         COALESCE(SUM(CASE WHEN type='withdraw' THEN amount ELSE 0 END), 0)) as current_balance 
    FROM company_bank_transactions
");
$current_balance = $stmt->fetch_assoc()['current_balance'] ?? 0;

// 2. Hitung Potensi Pendapatan Pending (Belum ditarik)
$stmt = $conn->prepare("
    SELECT 
        SUM(paket_abdul + paket_lacosa + paket_lakse + paket_saiyo + paket_woku) as total_food,
        SUM(hp) as total_hp,
        SUM(radio) as total_radio
    FROM sales_data 
    WHERE is_deposited_to_bank = 0
");
$stmt->execute();
$pending = $stmt->get_result()->fetch_assoc();

$pending_gross_revenue = ($pending['total_food'] * $PRICE_FOOD_PACK) + ($pending['total_hp'] * $PRICE_HP) + ($pending['total_radio'] * $PRICE_RADIO);
$pending_company_share = $pending_gross_revenue * 0.8;
$pending_employee_commission = $pending_gross_revenue * 0.2;

// 3. Hitung Akumulasi Gaji Karyawan yang "PAID" (Belum di Withdraw)
$total_pending_payroll = 0;
$paid_employee_count = 0;

$stmt_payroll = $conn->query("
    SELECT e.id, e.name, e.role,
           COALESCE(duty_summary.total_duty_minutes, 0) as total_duty_minutes,
           COALESCE(sales_summary.total_sales, 0) as total_sales_packages
    FROM employees e
    LEFT JOIN (
        SELECT employee_id, SUM(duration_minutes) as total_duty_minutes
        FROM duty_logs WHERE status = 'completed' GROUP BY employee_id
    ) as duty_summary ON e.id = duty_summary.employee_id
    LEFT JOIN (
        SELECT employee_id,
            (SUM(paket_abdul) + SUM(paket_lacosa) + SUM(paket_lakse) + 
             SUM(paket_saiyo) + SUM(paket_woku)) as total_sales
        FROM sales_data
        GROUP BY employee_id
    ) as sales_summary ON e.id = sales_summary.employee_id
    WHERE e.status = 'active' AND e.is_paid = 1
");

if ($stmt_payroll) {
    while ($emp = $stmt_payroll->fetch_assoc()) {
        $employee_role = strtolower($emp['role']);
        $rounded_duty_hours = round($emp['total_duty_minutes'] / 60);
        $total_sales = (int)$emp['total_sales_packages'];
        
        $gaji_pokok = 0;
        $bonus_duty = 0;
        $bonus_penjualan = 0;

        if ($rounded_duty_hours >= $MIN_DUTY_HOURS) {
            $gaji_pokok = $BASE_SALARIES[$employee_role] ?? 3000;
            if ($rounded_duty_hours >= $BONUS_DUTY_HOURS) {
                $bonus_duty = $BONUS_DUTY_AMOUNT;
            }
        }

        if ($total_sales >= $BONUS_SALES_TIER_2) {
            $bonus_penjualan = $BONUS_SALES_AMOUNT * 2;
        } elseif ($total_sales >= $BONUS_SALES_TIER_1) {
            $bonus_penjualan = $BONUS_SALES_AMOUNT;
        }

        $total_pending_payroll += ($gaji_pokok + $bonus_duty + $bonus_penjualan);
        $paid_employee_count++;
    }
}

// 4. Riwayat Transaksi Bank
$stmt = $conn->query("
    SELECT cbt.*, e.name as actor_name 
    FROM company_bank_transactions cbt
    LEFT JOIN employees e ON cbt.performed_by = e.id
    ORDER BY cbt.transaction_date DESC 
    LIMIT 20
");
$transactions = $stmt->fetch_all(MYSQLI_ASSOC);

?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Brangkas Perusahaan - Warung Om Tante</title>
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
            background: radial-gradient(circle, rgba(16, 185, 129, 0.15), transparent 70%); pointer-events: none; z-index: 0;
        }
        .header-content-wrapper { display: flex; align-items: center; gap: 1.25rem; z-index: 2; position: relative; }
        .header-icon-wrapper {
            background: rgba(255, 255, 255, 0.05); border: 1px solid rgba(255, 255, 255, 0.1);
            width: 60px; height: 60px; border-radius: 16px; display: flex; align-items: center;
            justify-content: center; font-size: 1.8rem; box-shadow: inset 0 0 15px rgba(0,0,0,0.3); flex-shrink: 0; color: #10b981;
        }
        .header-text-wrapper h1 { color: #fff; font-weight: 800; font-size: 2.2rem; letter-spacing: -0.02em; margin: 0; }
        .header-text-wrapper p { color: var(--text-secondary); margin: 0.25rem 0 0 0; font-size: 1.05rem; }

        /* --- Alerts --- */
        .success-alert { background: rgba(52, 211, 153, 0.15); border: 1px solid rgba(52, 211, 153, 0.4); color: #6ee7b7; padding: 1rem 1.2rem; border-radius: 14px; margin-bottom: 1.5rem; display: flex; align-items: center; gap: 0.75rem; font-size: 0.95rem; font-weight: 500; animation: slideDown 0.4s ease-out; }
        .error-alert { background: rgba(220, 53, 69, 0.15); border: 1px solid rgba(220, 53, 69, 0.4); color: #fca5a5; padding: 1rem 1.2rem; border-radius: 14px; margin-bottom: 1.5rem; display: flex; align-items: center; gap: 0.75rem; font-size: 0.95rem; font-weight: 500; animation: shake 0.5s ease-in-out; }

        /* --- Master Balance Card --- */
        .balance-card-modern {
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.1), rgba(15, 23, 42, 0.8));
            backdrop-filter: blur(16px); border: 1px solid rgba(16, 185, 129, 0.3);
            border-radius: 24px; padding: 3rem 2rem; text-align: center; margin-bottom: 2rem;
            position: relative; overflow: hidden; box-shadow: 0 15px 35px rgba(0,0,0,0.3);
            animation: slideUp 0.6s ease-out 0.1s backwards;
        }
        .balance-card-modern::before {
            content: '$'; position: absolute; right: -20px; bottom: -40px; font-size: 200px;
            opacity: 0.03; font-weight: 900; color: #fff;
        }
        .bc-title { font-size: 1.2rem; color: #cbd5e1; text-transform: uppercase; letter-spacing: 0.1em; font-weight: 700; margin-bottom: 10px;}
        .bc-amount { font-size: 4rem; font-weight: 900; color: #34d399; margin: 0; text-shadow: 0 0 20px rgba(16, 185, 129, 0.4); letter-spacing: -2px;}
        .bc-desc { color: #64748b; font-size: 0.95rem; margin-top: 10px; font-weight: 500;}

        /* --- Grid Layout --- */
        .bank-grid-modern {
            display: grid; grid-template-columns: 1fr 1fr; gap: 2rem;
        }

        /* --- Cards (Glassmorphism) --- */
        .modern-card {
            background: rgba(30, 41, 59, 0.6) !important; backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.08) !important; border-radius: 24px;
            box-shadow: 0 15px 35px rgba(0,0,0,0.3) !important; padding: 2rem; height: 100%;
            animation: slideUp 0.6s ease-out 0.2s backwards; display: flex; flex-direction: column;
        }
        .modern-card-header {
            margin-bottom: 1.5rem; border-bottom: 1px solid rgba(255, 255, 255, 0.1); padding-bottom: 1rem;
        }
        .modern-card-header h3 { color: #fff; font-size: 1.25rem; font-weight: 700; margin: 0; display: flex; align-items: center; gap: 10px;}

        /* --- Box Items --- */
        .pending-box-modern {
            background: rgba(245, 158, 11, 0.1); border: 1px dashed rgba(245, 158, 11, 0.4);
            border-radius: 20px; padding: 2rem 1.5rem; text-align: center; display: flex; flex-direction: column; justify-content: center; height: 100%;
        }
        .pending-title { font-size: 1rem; font-weight: 600; margin-bottom: 10px;}
        .pending-amount-val { font-size: 2.5rem; font-weight: 900; margin-bottom: 15px; }
        
        .pending-breakdown {
            background: rgba(0,0,0,0.25); padding: 12px 15px; border-radius: 12px; margin-bottom: 20px; font-size: 0.85rem; color: #94a3b8; text-align: left; display: inline-block; margin-left: auto; margin-right: auto; width: 100%; max-width: 350px;
        }
        .br-row { display: flex; justify-content: space-between; padding: 4px 0; }
        .br-row.total { border-bottom: 1px dashed rgba(255,255,255,0.1); padding-bottom: 8px; margin-bottom: 4px; }
        
        /* --- Buttons --- */
        .btn-modern-action {
            width: 100%; border: none; padding: 1.2rem; border-radius: 14px; font-size: 1rem; font-weight: 800; text-transform: uppercase;
            letter-spacing: 0.05em; cursor: pointer; transition: all 0.3s ease; display: flex; align-items: center; justify-content: center; gap: 8px;
        }
        .btn-success-gradient { background: linear-gradient(135deg, #10b981, #059669); color: #fff; box-shadow: 0 8px 20px rgba(16, 185, 129, 0.25); }
        .btn-success-gradient:hover { transform: translateY(-3px); box-shadow: 0 12px 25px rgba(16, 185, 129, 0.4); }
        
        .btn-danger-gradient { background: linear-gradient(135deg, #ef4444, #b91c1c); color: #fff; box-shadow: 0 8px 20px rgba(239, 68, 68, 0.25); }
        .btn-danger-gradient:hover { transform: translateY(-3px); box-shadow: 0 12px 25px rgba(239, 68, 68, 0.4); }

        .btn-primary-gradient { background: linear-gradient(135deg, #3b82f6, #2563eb); color: #fff; box-shadow: 0 8px 20px rgba(59, 130, 246, 0.25); }
        .btn-primary-gradient:hover { transform: translateY(-3px); box-shadow: 0 12px 25px rgba(59, 130, 246, 0.4); }
        
        .btn-disabled-modern { background: rgba(255,255,255,0.05); color: #64748b; cursor: not-allowed; border: 1px solid rgba(255,255,255,0.1); }

        /* --- Form Manual Transaction --- */
        .form-inner-wrapper {
            background: linear-gradient(145deg, rgba(30, 41, 59, 0.4), rgba(15, 23, 42, 0.6));
            border: 1px solid rgba(255, 255, 255, 0.05); border-radius: 20px; padding: 1.5rem; box-shadow: inset 0 0 20px rgba(0,0,0,0.2);
        }
        
        .type-selector-modern { display: flex; gap: 10px; margin-bottom: 1.5rem; }
        .type-radio { display: none; }
        .type-label-modern {
            flex: 1; padding: 12px; border: 1px solid rgba(255,255,255,0.1); border-radius: 12px; text-align: center; cursor: pointer;
            font-weight: 700; transition: 0.3s; background: rgba(0,0,0,0.2); color: #94a3b8; font-size: 0.95rem; display: flex; align-items: center; justify-content: center; gap: 8px;
        }
        .type-radio:checked + .type-label-modern.label-deposit { background: rgba(16, 185, 129, 0.15); color: #34d399; border-color: #10b981; box-shadow: 0 0 15px rgba(16, 185, 129, 0.2);}
        .type-radio:checked + .type-label-modern.label-withdraw { background: rgba(239, 68, 68, 0.15); color: #fca5a5; border-color: #ef4444; box-shadow: 0 0 15px rgba(239, 68, 68, 0.2);}

        .modern-form-group { margin-bottom: 1.25rem; }
        .modern-form-group label { display: block; font-size: 0.85rem; font-weight: 600; color: var(--text-secondary); margin-bottom: 0.5rem; text-transform: uppercase; letter-spacing: 0.05em; }
        .modern-input-wrapper { position: relative; display: flex; align-items: center; }
        .modern-input-icon { position: absolute; left: 1.25rem; font-size: 1.2rem; opacity: 0.6; pointer-events: none; z-index: 2; }
        
        .textarea-wrapper { align-items: flex-start; }
        .textarea-wrapper .modern-input-icon { top: 1.1rem; }

        .modern-input, .modern-textarea {
            width: 100%; background: rgba(0, 0, 0, 0.4); border: 1px solid rgba(255, 255, 255, 0.1);
            color: white; padding: 1rem 1rem 1rem 3.5rem; border-radius: 14px; font-size: 1rem; transition: all 0.3s ease; font-family: inherit;
        }
        .modern-textarea { min-height: 100px; resize: vertical; line-height: 1.5; }
        .modern-input:focus, .modern-textarea:focus { outline: none; border-color: #3b82f6; background: rgba(0, 0, 0, 0.6); box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15); }

        /* --- Modern Table (History) --- */
        .history-table-wrapper { overflow-y: auto; flex-grow: 1; padding-right: 5px;}
        .history-table-wrapper::-webkit-scrollbar { width: 6px; }
        .history-table-wrapper::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.1); border-radius: 10px; }
        
        .history-list { display: flex; flex-direction: column; gap: 10px; }
        .history-item {
            background: rgba(0, 0, 0, 0.25); border: 1px solid rgba(255, 255, 255, 0.03); border-radius: 14px; padding: 1.25rem;
            display: flex; justify-content: space-between; align-items: center; transition: 0.3s;
        }
        .history-item:hover { background: rgba(255, 255, 255, 0.03); transform: translateX(5px); border-color: rgba(255,255,255,0.08); }

        .hi-left { display: flex; flex-direction: column; gap: 6px; }
        .hi-cat { font-size: 0.95rem; font-weight: 800; color: #fff; display: flex; align-items: center; gap: 8px;}
        .hi-desc { font-size: 0.85rem; color: #cbd5e1; line-height: 1.4; max-width: 300px;}
        .hi-meta { font-size: 0.75rem; color: #64748b; font-weight: 600; display: flex; gap: 10px;}

        .hi-right { text-align: right; display: flex; flex-direction: column; align-items: flex-end; gap: 5px; }
        .hi-amount { font-size: 1.2rem; font-weight: 900; letter-spacing: 0.5px; }
        .amount-deposit { color: #34d399; }
        .amount-withdraw { color: #fca5a5; }

        .badge-type { font-size: 0.65rem; font-weight: 800; padding: 2px 8px; border-radius: 20px; text-transform: uppercase; letter-spacing: 0.1em; }
        .bt-deposit { background: rgba(16, 185, 129, 0.15); color: #34d399; border: 1px solid rgba(16, 185, 129, 0.3); }
        .bt-withdraw { background: rgba(239, 68, 68, 0.15); color: #fca5a5; border: 1px solid rgba(239, 68, 68, 0.3); }

        @keyframes slideDown { from { opacity: 0; transform: translateY(-20px); } to { opacity: 1; transform: translateY(0); } }
        @keyframes slideUp { from { opacity: 0; transform: translateY(30px); } to { opacity: 1; transform: translateY(0); } }
        @keyframes shake { 0%, 100% { transform: translateX(0); } 25% { transform: translateX(-5px); } 75% { transform: translateX(5px); } }

        @media (max-width: 1024px) {
            .bank-grid-modern { grid-template-columns: 1fr; }
            .history-item { flex-direction: column; align-items: flex-start; gap: 15px; }
            .hi-right { align-items: flex-start; text-align: left; }
        }
        @media (max-width: 768px) {
            .modern-page-header { padding: 1.5rem; text-align: center; }
            .header-content-wrapper { flex-direction: column; }
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
                    <div class="header-icon-wrapper">🏦</div>
                    <div class="header-text-wrapper">
                        <h1>Brangkas Perusahaan</h1>
                        <p>Pusat pengelolaan keuangan, deposit hasil penjualan, dan pengeluaran kas.</p>
                    </div>
                </div>
            </div>

            <?php if ($success_message): ?>
                <div class="success-alert"><span>🎉</span> <?= htmlspecialchars($success_message) ?></div>
            <?php endif; ?>
            <?php if ($error_message): ?>
                <div class="error-alert"><span>❌</span> <?= htmlspecialchars($error_message) ?></div>
            <?php endif; ?>

            <div class="balance-card-modern">
                <div class="bc-title">Total Uang Kas Tersedia</div>
                <div class="bc-amount"><?= formatMoney($current_balance) ?></div>
                <div class="bc-desc">Dana tersimpan aman dan tersinkronisasi di sistem database.</div>
            </div>

            <div class="bank-grid-modern">
                
                <div class="left-col" style="display: flex; flex-direction: column; gap: 2rem;">
                    
                    <div class="modern-card" style="margin-bottom: 0;">
                        <div class="modern-card-header">
                            <h3><span>📥</span> Deposit Penjualan Pending</h3>
                        </div>
                        <div class="pending-box-modern" style="background: rgba(16, 185, 129, 0.05); border-color: rgba(16, 185, 129, 0.3);">
                            <div class="pending-title" style="color: #34d399;">Kas Perusahaan (80%) Belum Disetor</div>
                            <div class="pending-amount-val" style="color: #10b981; text-shadow: 0 0 15px rgba(16, 185, 129, 0.3);">
                                <?= formatMoney($pending_company_share) ?>
                            </div>
                            
                            <div class="pending-breakdown">
                                <div class="br-row total">
                                    <span>Omset Kotor (100%):</span>
                                    <strong style="color: #cbd5e1;"><?= formatMoney($pending_gross_revenue) ?></strong>
                                </div>
                                <div class="br-row">
                                    <span>Komisi Anggota (20%):</span>
                                    <strong style="color: #fca5a5;">- <?= formatMoney($pending_employee_commission) ?></strong>
                                </div>
                                <div class="br-row" style="margin-top: 5px; color: #64748b; font-size: 0.8rem;">
                                    <span>Total Item Terjual:</span>
                                    <strong><?= $pending['total_food'] ?? 0 ?> Pkt | <?= $pending['total_hp'] ?? 0 ?> HP | <?= $pending['total_radio'] ?? 0 ?> Radio</strong>
                                </div>
                            </div>
                            
                            <form method="POST">
                                <input type="hidden" name="action" value="sync_sales_revenue">
                                <?php if ($pending_company_share > 0): ?>
                                    <button type="submit" class="btn-modern-action btn-success-gradient" onclick="return confirm('Tarik <?= formatMoney($pending_company_share) ?> (80% dari penjualan) ke brangkas?')">
                                        <span>💰</span> Tarik ke Brangkas
                                    </button>
                                    <p style="font-size: 0.75rem; margin-top: 15px; color: var(--text-muted); font-style: italic;">
                                        *Data penjualan akan ditandai "Telah Disetor" secara otomatis.
                                    </p>
                                <?php else: ?>
                                    <button type="button" class="btn-modern-action btn-disabled-modern" disabled>
                                        Sistem Bersih (Tidak ada pending)
                                    </button>
                                <?php endif; ?>
                            </form>
                        </div>
                    </div>

                    <div class="modern-card" style="margin-bottom: 0;">
                        <div class="modern-card-header">
                            <h3 style="color: #fca5a5;"><span>💸</span> Withdraw Gajian Pending</h3>
                        </div>
                        <div class="pending-box-modern" style="background: rgba(239, 68, 68, 0.05); border-color: rgba(239, 68, 68, 0.3);">
                            <div class="pending-title" style="color: #fca5a5;">Gaji Anggota (Status: Sudah Dibayar)</div>
                            <div class="pending-amount-val" style="color: #ef4444; text-shadow: 0 0 15px rgba(239, 68, 68, 0.3);">
                                <?= formatMoney($total_pending_payroll) ?>
                            </div>
                            
                            <div class="pending-breakdown" style="border-left: 2px solid #ef4444;">
                                <div class="br-row">
                                    <span>Total Karyawan (Lunas):</span>
                                    <strong style="color: #fff;"><?= $paid_employee_count ?> Orang</strong>
                                </div>
                                <div class="br-row" style="margin-top: 5px; color: #64748b; font-size: 0.8rem;">
                                    <span>Asal Data: Menu Rekap Gaji (is_paid = 1)</span>
                                </div>
                            </div>
                            
                            <form method="POST">
                                <input type="hidden" name="action" value="sync_payroll_withdraw">
                                <input type="hidden" name="total_payroll_amount" value="<?= $total_pending_payroll ?>">
                                <input type="hidden" name="paid_employee_count" value="<?= $paid_employee_count ?>">
                                
                                <?php if ($total_pending_payroll > 0): ?>
                                    <button type="submit" class="btn-modern-action btn-danger-gradient" onclick="return confirm('Yakin menarik dana <?= formatMoney($total_pending_payroll) ?> dari brangkas untuk pembayaran gaji?')">
                                        <span>💳</span> Tarik Saldo Gaji
                                    </button>
                                    <p style="font-size: 0.75rem; margin-top: 15px; color: #fca5a5; font-style: italic;">
                                        *Penting: Klik hanya 1x. Jika dana sudah ditarik, jangan klik lagi hingga minggu depan.
                                    </p>
                                <?php else: ?>
                                    <button type="button" class="btn-modern-action btn-disabled-modern" disabled>
                                        Tidak ada gaji pending
                                    </button>
                                <?php endif; ?>
                            </form>
                        </div>
                    </div>

                    <div class="modern-card" style="margin-bottom: 0;">
                        <div class="modern-card-header">
                            <h3><span>📝</span> Catat Transaksi Manual</h3>
                        </div>
                        <div class="form-inner-wrapper">
                            <form method="POST">
                                <input type="hidden" name="action" value="manual_transaction">
                                
                                <div class="type-selector-modern">
                                    <input type="radio" name="type" id="type_deposit" value="deposit" class="type-radio" checked>
                                    <label for="type_deposit" class="type-label-modern label-deposit">⬇️ Deposit (Masuk)</label>
                                    
                                    <input type="radio" name="type" id="type_withdraw" value="withdraw" class="type-radio">
                                    <label for="type_withdraw" class="type-label-modern label-withdraw">⬆️ Withdraw (Keluar)</label>
                                </div>

                                <div class="modern-form-group">
                                    <label>Nominal ($)</label>
                                    <div class="modern-input-wrapper">
                                        <span class="modern-input-icon">💵</span>
                                        <input type="number" name="amount" class="modern-input" min="1" placeholder="Misal: 500" required>
                                    </div>
                                </div>

                                <div class="modern-form-group">
                                    <label>Keterangan Transaksi</label>
                                    <div class="modern-input-wrapper textarea-wrapper">
                                        <span class="modern-input-icon">💬</span>
                                        <textarea name="description" class="modern-textarea" placeholder="Contoh: Tambah modal, Bayar tukang atap, Beli perlengkapan..." required></textarea>
                                    </div>
                                </div>

                                <button type="submit" class="btn-modern-action btn-primary-gradient" onclick="return confirm('Pastikan data nominal dan keterangan sudah benar. Lanjutkan?')">
                                    <span>💾</span> Proses Transaksi
                                </button>
                            </form>
                        </div>
                    </div>

                </div>

                <div class="modern-card" style="margin-bottom: 0;">
                    <div class="modern-card-header">
                        <h3><span>📜</span> Riwayat Mutasi Brangkas</h3>
                    </div>
                    
                    <?php if (empty($transactions)): ?>
                        <div style="text-align: center; padding: 3rem 0; color: #64748b; font-style: italic;">
                            <span style="font-size: 3rem; display: block; margin-bottom: 10px;">🍃</span>
                            Belum ada riwayat transaksi.
                        </div>
                    <?php else: ?>
                        <div class="history-table-wrapper">
                            <div class="history-list">
                                <?php foreach ($transactions as $trx): 
                                    $is_deposit = $trx['type'] === 'deposit';
                                ?>
                                <div class="history-item">
                                    <div class="hi-left">
                                        <div class="hi-cat">
                                            <?= htmlspecialchars($trx['category']) ?>
                                            <span class="badge-type <?= $is_deposit ? 'bt-deposit' : 'bt-withdraw' ?>">
                                                <?= $is_deposit ? 'Masuk' : 'Keluar' ?>
                                            </span>
                                        </div>
                                        <div class="hi-desc">"<?= htmlspecialchars($trx['description']) ?>"</div>
                                        <div class="hi-meta">
                                            <span>🕒 <?= date('d M Y - H:i', strtotime($trx['transaction_date'])) ?></span>
                                            <span>•</span>
                                            <span>👤 <?= htmlspecialchars($trx['actor_name'] ?? 'Sistem') ?></span>
                                        </div>
                                    </div>
                                    <div class="hi-right">
                                        <div class="hi-amount <?= $is_deposit ? 'amount-deposit' : 'amount-withdraw' ?>">
                                            <?= $is_deposit ? '+' : '-' ?> <?= formatMoney($trx['amount']) ?>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

            </div>
        </main>
    </div>

    <script src="script.js"></script>
    <script>
        // Form submission loading protection
        document.addEventListener('DOMContentLoaded', function() {
            const forms = document.querySelectorAll('form');
            forms.forEach(form => {
                form.addEventListener('submit', function(e) {
                    const submitBtn = this.querySelector('button[type="submit"]');
                    if (submitBtn && !submitBtn.disabled) {
                        const originalHTML = submitBtn.innerHTML;
                        submitBtn.disabled = true;
                        submitBtn.style.opacity = '0.7';
                        submitBtn.innerHTML = '<span>⏳</span> Memproses...';
                        
                        setTimeout(() => {
                            submitBtn.disabled = false;
                            submitBtn.style.opacity = '1';
                            submitBtn.innerHTML = originalHTML;
                        }, 4000);
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