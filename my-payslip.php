<?php
require_once 'config.php';

if (!isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$user = getCurrentUser();
$pending_requests_count = getPendingRequestCount(); 

// --- Helper Functions ---
function roundToNearestHour($minutes) {
    return round($minutes / 60);
}
function formatDollar($amount) {
    return '$ ' . number_format($amount, 0, ',', '.');
}

// --- KONFIGURASI GAJI BARU ---
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

// --- CEK HAK AKSES CEO / DIREKTUR ---
$is_top_management = hasRole(['ceo', 'direktur']);

// Tentukan ID Target (Default: Diri Sendiri)
$target_employee_id = $user['id'];

// Jika CEO/Direktur memilih anggota lain dari dropdown
if ($is_top_management && isset($_GET['employee_id']) && !empty($_GET['employee_id'])) {
    $target_employee_id = (int)$_GET['employee_id'];
}

// --- AMBIL DATA KARYAWAN TARGET ---
$stmt_emp = $conn->prepare("SELECT name, role, is_paid FROM employees WHERE id = ?");
$stmt_emp->bind_param("i", $target_employee_id);
$stmt_emp->execute();
$target_emp_data = $stmt_emp->get_result()->fetch_assoc();
$stmt_emp->close();

// Fallback jika tidak ditemukan
if (!$target_emp_data) {
    $target_emp_data = ['name' => 'Data Tidak Ditemukan', 'role' => 'karyawan', 'is_paid' => 0];
}

$employee_name = $target_emp_data['name'];
$employee_role = strtolower($target_emp_data['role']);
$is_paid = (bool) $target_emp_data['is_paid'];

// Ambil daftar semua anggota khusus untuk Dropdown CEO/Direktur
$all_employees = [];
if ($is_top_management) {
    $all_employees = $conn->query("SELECT id, name, role FROM employees WHERE status = 'active' ORDER BY name")->fetch_all(MYSQLI_ASSOC);
}

// --- AMBIL DATA AKTIVITAS TARGET ---
// Ambil Total Jam Duty
$stmt_duty = $conn->prepare("SELECT COALESCE(SUM(duration_minutes), 0) as total_minutes FROM duty_logs WHERE employee_id = ? AND status = 'completed'");
$stmt_duty->bind_param("i", $target_employee_id);
$stmt_duty->execute();
$total_duty_minutes = (int) $stmt_duty->get_result()->fetch_assoc()['total_minutes'];
$stmt_duty->close();

$rounded_duty_hours = roundToNearestHour($total_duty_minutes);

// Ambil Total Penjualan Paket
$stmt_sales = $conn->prepare("
    SELECT COALESCE((SUM(paket_abdul) + SUM(paket_lacosa) + SUM(paket_lakse) + SUM(paket_saiyo) + SUM(paket_woku)), 0) as total_sales 
    FROM sales_data 
    WHERE employee_id = ?
");
$stmt_sales->bind_param("i", $target_employee_id);
$stmt_sales->execute();
$total_sales_packages = (int) $stmt_sales->get_result()->fetch_assoc()['total_sales'];
$stmt_sales->close();

// --- LOGIKA PERHITUNGAN ---
$gaji_pokok = 0;
$bonus_duty = 0;
$bonus_penjualan = 0;

$kualifikasi_gaji_pokok = false;

// 1. Gaji Pokok & Bonus Duty
if ($rounded_duty_hours >= $MIN_DUTY_HOURS) {
    $kualifikasi_gaji_pokok = true;
    $gaji_pokok = $BASE_SALARIES[$employee_role] ?? 3000;
    
    if ($rounded_duty_hours >= $BONUS_DUTY_HOURS) {
        $bonus_duty = $BONUS_DUTY_AMOUNT;
    }
}

// 2. Bonus Penjualan
if ($total_sales_packages >= $BONUS_SALES_TIER_2) {
    $bonus_penjualan = $BONUS_SALES_AMOUNT * 2;
} elseif ($total_sales_packages >= $BONUS_SALES_TIER_1) {
    $bonus_penjualan = $BONUS_SALES_AMOUNT;
}

$total_gajian = $gaji_pokok + $bonus_duty + $bonus_penjualan;

?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Slip Gaji - Warung Om Tante V2</title>
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
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 20px;
        }
        .modern-page-header::after {
            content: ''; position: absolute; top: -20%; right: -5%; width: 300px; height: 300px;
            background: radial-gradient(circle, rgba(52, 211, 153, 0.15), transparent 70%); pointer-events: none; z-index: 0;
        }
        .header-content-wrapper { display: flex; align-items: center; gap: 1.25rem; z-index: 2; position: relative; }
        .header-icon-wrapper {
            background: rgba(255, 255, 255, 0.05); border: 1px solid rgba(255, 255, 255, 0.1);
            width: 60px; height: 60px; border-radius: 16px; display: flex; align-items: center;
            justify-content: center; font-size: 1.8rem; box-shadow: inset 0 0 15px rgba(0,0,0,0.3); flex-shrink: 0; color: #34d399;
        }
        .header-text-wrapper h1 { color: #fff; font-weight: 800; font-size: 2.2rem; letter-spacing: -0.02em; margin: 0; }
        .header-text-wrapper p { color: var(--text-secondary); margin: 0.25rem 0 0 0; font-size: 1.05rem; }

        /* Print Button */
        .btn-print-modern {
            background: linear-gradient(135deg, #3b82f6, #2563eb); color: #fff; border: none;
            padding: 1rem 1.5rem; border-radius: 14px; font-size: 0.95rem; font-weight: 800; text-transform: uppercase;
            letter-spacing: 0.05em; cursor: pointer; transition: all 0.3s ease; display: inline-flex; align-items: center; gap: 10px;
            box-shadow: 0 8px 20px rgba(59, 130, 246, 0.3); position: relative; z-index: 2; text-decoration: none;
        }
        .btn-print-modern:hover { transform: translateY(-3px); box-shadow: 0 12px 25px rgba(59, 130, 246, 0.5); }

        /* --- Filter Card untuk CEO/Direktur --- */
        .modern-filter-card {
            background: rgba(30, 41, 59, 0.6); backdrop-filter: blur(16px);
            border: 1px solid rgba(59, 130, 246, 0.3); border-radius: 16px; padding: 1.5rem;
            margin-bottom: 2rem; position: relative;
        }
        .modern-filter-header { font-size: 1rem; color: #93c5fd; font-weight: 700; margin-bottom: 10px; display: flex; align-items: center; gap: 8px; text-transform: uppercase; letter-spacing: 0.05em;}
        .modern-input-wrapper { position: relative; display: flex; align-items: center; width: 100%; max-width: 400px;}
        .modern-input-icon { position: absolute; left: 1rem; font-size: 1.2rem; opacity: 0.6; pointer-events: none; }
        .modern-select {
            width: 100%; background: rgba(0, 0, 0, 0.4); border: 1px solid rgba(255, 255, 255, 0.1);
            color: white; padding: 0.8rem 1rem 0.8rem 3rem; border-radius: 12px; font-size: 0.95rem; transition: 0.3s;
            appearance: none; cursor: pointer;
        }
        .modern-select option { background: #0f172a; color: white; }
        .modern-select:focus { outline: none; border-color: #3b82f6; background: rgba(0,0,0,0.6); box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15);}

        /* --- PAYSLIP CARD (DIGITAL RECEIPT) --- */
        .payslip-container {
            display: flex; justify-content: center; padding-bottom: 3rem;
        }
        
        .payslip-card {
            background: linear-gradient(145deg, rgba(30, 41, 59, 0.8), rgba(15, 23, 42, 0.95));
            backdrop-filter: blur(20px); border: 1px solid rgba(255, 255, 255, 0.1); border-top: 5px solid #10b981;
            border-radius: 24px; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
            width: 100%; max-width: 600px; padding: 2.5rem; position: relative; overflow: hidden;
            animation: slideUp 0.6s ease-out 0.2s backwards;
        }
        
        /* Watermark */
        .payslip-card::before {
            content: 'CONFIDENTIAL'; position: absolute; top: 40%; left: 50%; transform: translate(-50%, -50%) rotate(-30deg);
            font-size: 5rem; font-weight: 900; color: rgba(255,255,255,0.02); pointer-events: none; z-index: 0; white-space: nowrap;
        }

        .payslip-header { text-align: center; margin-bottom: 2rem; position: relative; z-index: 2; border-bottom: 1px dashed rgba(255,255,255,0.1); padding-bottom: 1.5rem;}
        .payslip-header img { height: 60px; margin-bottom: 15px; filter: drop-shadow(0 0 10px rgba(255,255,255,0.2));}
        .payslip-header h2 { margin: 0; font-size: 1.8rem; color: #fff; font-weight: 800; letter-spacing: 0.05em; text-transform: uppercase;}
        .payslip-header p { margin: 5px 0 0 0; color: #94a3b8; font-size: 0.9rem;}

        .employee-info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 2rem; position: relative; z-index: 2; background: rgba(0,0,0,0.2); padding: 1.25rem; border-radius: 16px; border: 1px solid rgba(255,255,255,0.03);}
        .info-item { display: flex; flex-direction: column; gap: 5px; }
        .info-label { font-size: 0.75rem; color: #64748b; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; }
        .info-value { font-size: 1.05rem; font-weight: 800; color: #fff; }
        
        .role-badge { display: inline-block; background: rgba(59, 130, 246, 0.15); color: #60a5fa; padding: 2px 8px; border-radius: 6px; font-size: 0.8rem; border: 1px solid rgba(59, 130, 246, 0.3); width: fit-content; text-transform: capitalize;}

        /* Status Badge */
        .payment-status { grid-column: 1 / -1; display: flex; justify-content: space-between; align-items: center; border-top: 1px dashed rgba(255,255,255,0.1); padding-top: 15px; margin-top: 5px;}
        .status-pill { padding: 6px 15px; border-radius: 30px; font-size: 0.85rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; display: inline-flex; align-items: center; gap: 6px;}
        .status-paid { background: rgba(16, 185, 129, 0.15); color: #34d399; border: 1px solid rgba(16, 185, 129, 0.4); box-shadow: 0 0 15px rgba(16, 185, 129, 0.2); }
        .status-unpaid { background: rgba(245, 158, 11, 0.15); color: #fbbf24; border: 1px solid rgba(245, 158, 11, 0.4); box-shadow: 0 0 15px rgba(245, 158, 11, 0.2); }

        /* Salary Breakdown */
        .breakdown-section { position: relative; z-index: 2; margin-bottom: 2rem;}
        .section-title { font-size: 0.9rem; color: #cbd5e1; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 15px; display: flex; align-items: center; gap: 8px;}
        
        .breakdown-list { display: flex; flex-direction: column; gap: 12px; }
        .bd-item { display: flex; justify-content: space-between; align-items: flex-start; padding-bottom: 12px; border-bottom: 1px solid rgba(255,255,255,0.05); }
        .bd-item:last-child { border-bottom: none; padding-bottom: 0; }
        
        .bd-desc { display: flex; flex-direction: column; gap: 4px; }
        .bd-name { font-size: 1rem; font-weight: 700; color: #fff; }
        .bd-note { font-size: 0.8rem; color: #94a3b8; font-style: italic;}
        
        .bd-amount { font-size: 1.1rem; font-weight: 800; color: #e2e8f0; font-family: monospace;}
        .amount-highlight { color: #34d399; }
        .amount-zero { color: #64748b; opacity: 0.5; }

        /* Total Section */
        .total-section {
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.15), rgba(16, 185, 129, 0.05));
            border: 1px solid rgba(16, 185, 129, 0.4); border-radius: 16px; padding: 1.5rem;
            display: flex; justify-content: space-between; align-items: center; position: relative; z-index: 2;
            box-shadow: inset 0 0 20px rgba(16, 185, 129, 0.1);
        }
        .total-label { font-size: 1.1rem; font-weight: 800; color: #fff; text-transform: uppercase; letter-spacing: 0.05em; }
        .total-value { font-size: 2.2rem; font-weight: 900; color: #10b981; text-shadow: 0 0 20px rgba(16, 185, 129, 0.4); font-family: monospace; letter-spacing: -1px;}

        .payslip-footer { text-align: center; margin-top: 2rem; position: relative; z-index: 2; padding-top: 1.5rem; border-top: 1px dashed rgba(255,255,255,0.1); color: #64748b; font-size: 0.8rem; line-height: 1.5;}

        /* Badges for conditions */
        .cond-badge { font-size: 0.7rem; padding: 2px 6px; border-radius: 4px; font-weight: 700; text-transform: uppercase; margin-top: 4px; display: inline-block; width: fit-content;}
        .cb-success { background: rgba(16, 185, 129, 0.2); color: #34d399; }
        .cb-fail { background: rgba(239, 68, 68, 0.2); color: #fca5a5; }

        @keyframes slideDown { from { opacity: 0; transform: translateY(-20px); } to { opacity: 1; transform: translateY(0); } }
        @keyframes slideUp { from { opacity: 0; transform: translateY(30px); } to { opacity: 1; transform: translateY(0); } }

        /* =========================================
           PRINT SPECIFIC STYLES - SOLUSI AGAR TIDAK TER-CROP
           ========================================= */
        @media print {
            @page {
                size: A4 portrait; /* Paksa kertas ke A4 portrait */
                margin: 15mm;      /* Beri margin yang aman untuk printer */
            }

            /* Sembunyikan elemen UI Dashboard yang tidak perlu dicetak */
            .sidebar, 
            .header, 
            .modern-page-header, 
            .btn-print-modern, 
            .sidebar-toggle,
            .no-print {
                display: none !important;
            }

            /* Hapus background dan margin dari container agar rata di kertas */
            body, .dashboard-container, .main-content {
                background: #fff !important;
                margin: 0 !important;
                padding: 0 !important;
                min-height: auto !important;
                width: 100% !important;
            }

            .payslip-container {
                width: 100% !important;
                justify-content: flex-start !important;
                padding: 0 !important;
                margin: 0 !important;
            }

            /* Reset styling Card khusus untuk kertas */
            .payslip-card {
                background: #fff !important; 
                color: #000 !important; 
                border: 1px solid #ccc !important;
                box-shadow: none !important; 
                padding: 20px !important; 
                border-top: 5px solid #000 !important; 
                border-radius: 0 !important;
                max-width: 100% !important; /* Gunakan seluruh area kertas */
                width: 100% !important;
                margin: 0 !important;
            }
            
            /* Warna Teks Putih/Gelap jadi Hitam */
            .payslip-header h2, .info-value, .bd-name, .total-label, .bd-amount, .total-value { color: #000 !important; text-shadow: none !important; }
            .payslip-header p, .info-label, .bd-note, .payslip-footer { color: #555 !important; }
            
            /* Invert logo karena background menjadi putih */
            .payslip-header img { filter: invert(1) grayscale(1) !important; }
            
            /* Kotak Grid & Total */
            .employee-info-grid { background: #f9f9f9 !important; border: 1px solid #ddd !important; }
            .total-section { background: #f0f0f0 !important; border: 2px solid #000 !important; box-shadow: none !important; }
            
            /* Badges & Pills */
            .role-badge, .status-pill, .cond-badge { border: 1px solid #000 !important; color: #000 !important; background: transparent !important; box-shadow: none !important; }
            .amount-zero { color: #999 !important; }
            
            /* Watermark tipis banget agar tidak mengganggu bacaan saat print */
            .payslip-card::before { color: rgba(0,0,0,0.03) !important; }
        }

        @media (max-width: 768px) {
            .modern-page-header { flex-direction: column; text-align: center; }
            .header-content-wrapper { flex-direction: column; }
            .employee-info-grid { grid-template-columns: 1fr; gap: 10px; }
            .payslip-card { padding: 1.5rem; }
            .total-section { flex-direction: column; gap: 10px; text-align: center; }
            .total-value { font-size: 2rem; }
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
                    <div class="header-icon-wrapper">📄</div>
                    <div class="header-text-wrapper">
                        <h1>Slip Gaji</h1>
                        <p>Rincian perhitungan gaji, performa, dan bonus periode saat ini.</p>
                    </div>
                </div>
                <button class="btn-print-modern" onclick="window.print()">
                    <span>🖨️</span> Cetak / Simpan PDF
                </button>
            </div>

            <?php if ($is_top_management): ?>
            <div class="modern-filter-card no-print">
                <div class="modern-filter-header">
                    <span>🕵️‍♂️</span> Mode Pengecekan Dokumen (Akses Manajemen)
                </div>
                <form method="GET" style="margin: 0;">
                    <div class="modern-input-wrapper">
                        <span class="modern-input-icon">👤</span>
                        <select name="employee_id" class="modern-select" onchange="this.form.submit()">
                            <option value="<?= $user['id'] ?>" <?= ($target_employee_id == $user['id']) ? 'selected' : '' ?>>-- Slip Gaji Saya Sendiri --</option>
                            <?php foreach ($all_employees as $emp): ?>
                                <?php if ($emp['id'] != $user['id']): ?>
                                    <option value="<?= $emp['id'] ?>" <?= ($target_employee_id == $emp['id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($emp['name']) ?> (<?= getRoleDisplayName($emp['role']) ?>)
                                    </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </form>
            </div>
            <?php endif; ?>

            <div class="payslip-container">
                <div class="payslip-card">
                    
                    <div class="payslip-header">
                        <img src="LOGO_WOT.png" alt="Logo Warung Om Tante">
                        <h2>Payslip Pegawai</h2>
                        <p>Dokumen Rahasia & Konfidensial</p>
                    </div>

                    <div class="employee-info-grid">
                        <div class="info-item">
                            <span class="info-label">Nama Karyawan</span>
                            <span class="info-value"><?= htmlspecialchars($employee_name) ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Jabatan Struktural</span>
                            <span class="role-badge"><?= getRoleDisplayName($employee_role) ?></span>
                        </div>
                        <div class="payment-status">
                            <div class="info-item">
                                <span class="info-label">Status Pembayaran</span>
                            </div>
                            <?php if ($is_paid): ?>
                                <span class="status-pill status-paid">✔️ SUDAH DIBAYAR (LUNAS)</span>
                            <?php else: ?>
                                <span class="status-pill status-unpaid">⏳ BELUM DIBAYAR (PENDING)</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="breakdown-section">
                        <div class="section-title"><span>📈</span> Ringkasan Performa (Aktivitas)</div>
                        <div class="breakdown-list">
                            <div class="bd-item">
                                <div class="bd-desc">
                                    <span class="bd-name">Total Durasi Jam Kerja</span>
                                    <span class="bd-note">Diambil dari log duty sistem (Status: Completed)</span>
                                </div>
                                <div class="bd-amount"><?= $rounded_duty_hours ?> Jam</div>
                            </div>
                            <div class="bd-item">
                                <div class="bd-desc">
                                    <span class="bd-name">Total Penjualan Paket Masak</span>
                                    <span class="bd-note">Tidak termasuk barang elektronik (HP/Radio)</span>
                                </div>
                                <div class="bd-amount"><?= $total_sales_packages ?> Item</div>
                            </div>
                        </div>
                    </div>

                    <div class="breakdown-section">
                        <div class="section-title"><span>💲</span> Rincian Kompensasi & Bonus</div>
                        <div class="breakdown-list">
                            
                            <div class="bd-item">
                                <div class="bd-desc">
                                    <span class="bd-name">Gaji Pokok (Base Salary)</span>
                                    <span class="bd-note">Sesuai standar jabatan <?= getRoleDisplayName($employee_role) ?></span>
                                    <?php if ($kualifikasi_gaji_pokok): ?>
                                        <span class="cond-badge cb-success">Syarat Jam Minimal: Terpenuhi</span>
                                    <?php else: ?>
                                        <span class="cond-badge cb-fail">Syarat Jam Minimal: Tidak Terpenuhi</span>
                                    <?php endif; ?>
                                </div>
                                <div class="bd-amount <?= $gaji_pokok > 0 ? 'amount-highlight' : 'amount-zero' ?>">
                                    <?= formatDollar($gaji_pokok) ?>
                                </div>
                            </div>

                            <div class="bd-item">
                                <div class="bd-desc">
                                    <span class="bd-name">Bonus Dedikasi Waktu (Over-Duty)</span>
                                    <span class="bd-note">Diberikan jika mencapai target jam kerja ekstra.</span>
                                    <?php if ($bonus_duty > 0): ?>
                                        <span class="cond-badge cb-success">Kualifikasi: Lulus</span>
                                    <?php else: ?>
                                        <span class="cond-badge cb-fail">Kualifikasi: Belum Memenuhi</span>
                                    <?php endif; ?>
                                </div>
                                <div class="bd-amount <?= $bonus_duty > 0 ? 'amount-highlight' : 'amount-zero' ?>">
                                    <?= formatDollar($bonus_duty) ?>
                                </div>
                            </div>

                            <div class="bd-item">
                                <div class="bd-desc">
                                    <span class="bd-name">Bonus Performa Penjualan</span>
                                    <span class="bd-note">Diberikan secara bertahap berdasarkan volume penjualan.</span>
                                    <?php if ($bonus_penjualan > 0): ?>
                                        <span class="cond-badge cb-success">Kualifikasi: Lulus (Cair)</span>
                                    <?php else: ?>
                                        <span class="cond-badge cb-fail">Kualifikasi: Belum Memenuhi</span>
                                    <?php endif; ?>
                                </div>
                                <div class="bd-amount <?= $bonus_penjualan > 0 ? 'amount-highlight' : 'amount-zero' ?>">
                                    <?= formatDollar($bonus_penjualan) ?>
                                </div>
                            </div>

                        </div>
                    </div>

                    <div class="total-section">
                        <span class="total-label">Total Penerimaan (Take Home Pay)</span>
                        <span class="total-value"><?= formatDollar($total_gajian) ?></span>
                    </div>

                    <div class="payslip-footer">
                        Dokumen ini dihasilkan secara otomatis oleh sistem Manajemen Warung Om Tante V2.<br>
                        Syarat dan ketentuan bonus ditetapkan secara tertutup oleh Manajemen dan dihitung secara otomatis oleh sistem berdasarkan aktivitas log Anda.
                    </div>

                </div>
            </div>

        </main>
    </div>

    <script src="script.js"></script>
</body>
</html>