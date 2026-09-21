<?php
require_once 'config.php';

// Batasi akses: Wakil Direktur ke atas
if (!isLoggedIn() || !hasRole(['ceo', 'direktur', 'wakil_direktur'])) {
    header('Location: dashboard.php');
    exit;
}

$user = getCurrentUser();
$pending_requests_count = getPendingRequestCount();

// Menggunakan format Dollar sesuai instruksi pembaruan sistem
function formatDollar($amount) {
    return '$ ' . number_format($amount, 0, ',', '.');
}

// Fallback functions in case they are not in config.php
if (!function_exists('formatDuration')) {
    function formatDuration($minutes) {
        if ($minutes < 0) return "0j 0m";
        $hours = floor($minutes / 60);
        $remainingMinutes = $minutes % 60;
        return "{$hours}j {$remainingMinutes}m";
    }
}

if (!function_exists('getRoleDisplayName')) {
    function getRoleDisplayName($role) {
        $roles = [
            'ceo' => 'CEO', 'direktur' => 'Direktur', 'wakil_direktur' => 'Wakil Direktur',
            'manager' => 'Manager', 'chef' => 'Chef', 'waiters' => 'Waiters',
            'karyawan' => 'Karyawan', 'magang' => 'Magang',
        ];
        return $roles[$role] ?? ucfirst(str_replace('_', ' ', $role));
    }
}

// Inisialisasi variabel filter dan sorting
$filter_role = $_GET['role'] ?? '';
$filter_date = $_GET['backup_date'] ?? '';
$sort_by = $_GET['sort_by'] ?? 'week_start';
$sort_order = $_GET['sort_order'] ?? 'DESC';

// Pastikan sort_order valid
$sort_order = strtoupper($sort_order) === 'ASC' ? 'ASC' : 'DESC';

// Kumpulan role yang mungkin
$available_roles = [
    'ceo' => 'CEO',
    'direktur' => 'Direktur',
    'wakil_direktur' => 'Wakil Direktur',
    'manager' => 'Manager',
    'chef' => 'Chef',
    'waiters' => 'Waiters',
    'karyawan' => 'Karyawan',
    'magang' => 'Magang',
];

// Siapkan query dengan filtering dan sorting
$sql = "
    SELECT 
        w.*,
        e.role as employee_role
    FROM weekly_salary_backup w
    JOIN employees e ON w.employee_id = e.id
    WHERE 1=1
";

$params = [];
$types = '';

// Filter Jabatan (Role)
if (!empty($filter_role)) {
    $sql .= " AND e.role = ?";
    $types .= 's';
    $params[] = $filter_role;
}

// Filter Tanggal Backup
if (!empty($filter_date)) {
    // Cari data yang di-backup pada tanggal tertentu
    $sql .= " AND DATE(w.backup_date) = ?";
    $types .= 's';
    $params[] = $filter_date;
}

// Sorting
// Pastikan kolom sorting valid untuk menghindari SQL Injection
$allowed_sorts = ['week_start', 'employee_name', 'employee_role', 'total_net_salary', 'backup_date'];
if (in_array($sort_by, $allowed_sorts)) {
    $sql .= " ORDER BY {$sort_by} {$sort_order}, w.week_start DESC, w.employee_name ASC";
} else {
    $sql .= " ORDER BY w.week_start DESC, w.employee_name ASC";
}

// Eksekusi query
if (!empty($params)) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $backup_result = $stmt->get_result();
    $backup_data = $backup_result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} else {
    $backup_data = $conn->query($sql)->fetch_all(MYSQLI_ASSOC);
}


// Fungsi untuk membantu menentukan class sorting
function getSortClass($column, $current_sort_by, $current_sort_order) {
    if ($column === $current_sort_by) {
        return $current_sort_order === 'ASC' ? 'sorted-asc' : 'sorted-desc';
    }
    return '';
}

// Fungsi untuk mendapatkan URL sorting baru
function getSortUrl($column, $current_sort_by, $current_sort_order, $filter_role, $filter_date) {
    $new_order = 'ASC';
    if ($column === $current_sort_by && $current_sort_order === 'ASC') {
        $new_order = 'DESC';
    }
    $query = http_build_query([
        'role' => $filter_role,
        'backup_date' => $filter_date,
        'sort_by' => $column,
        'sort_order' => $new_order
    ]);
    return '?' . $query;
}

?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rekap Gaji Mingguan - Warung Om Tante V2</title>
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

        /* --- Info Box Modern --- */
        .modern-info-box {
            background: rgba(59, 130, 246, 0.1); border: 1px solid rgba(59, 130, 246, 0.3);
            border-radius: 14px; padding: 1rem 1.25rem; color: #93c5fd; font-size: 0.95rem; line-height: 1.6;
            display: flex; align-items: center; gap: 15px; margin-bottom: 2rem;
        }

        /* --- Cards (Glassmorphism) --- */
        .modern-card {
            background: rgba(30, 41, 59, 0.6) !important; backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.08) !important; border-radius: 24px;
            box-shadow: 0 15px 35px rgba(0,0,0,0.3) !important; padding: 2rem;
            animation: slideUp 0.6s ease-out 0.2s backwards; margin-bottom: 2rem;
        }
        .modern-card-header {
            display: flex; justify-content: space-between; align-items: center; 
            margin-bottom: 1.5rem; border-bottom: 1px solid rgba(255, 255, 255, 0.1); padding-bottom: 1rem;
        }
        .modern-card-header h3 { color: #fff; font-size: 1.25rem; font-weight: 700; margin: 0; display: flex; align-items: center; gap: 10px;}

        /* --- Form Filter (Inner Wrapper) --- */
        .form-inner-wrapper {
            background: linear-gradient(145deg, rgba(30, 41, 59, 0.4), rgba(15, 23, 42, 0.6));
            border: 1px solid rgba(255, 255, 255, 0.05); border-radius: 20px; padding: 1.5rem;
            box-shadow: inset 0 0 20px rgba(0,0,0,0.2); margin-bottom: 2rem;
        }
        .form-row-modern {
            display: grid; grid-template-columns: 1fr 1fr auto auto; gap: 1.5rem; align-items: end;
        }
        .modern-form-group label {
            display: block; font-size: 0.85rem; font-weight: 600; color: var(--text-secondary); margin-bottom: 0.6rem; text-transform: uppercase; letter-spacing: 0.05em;
        }
        .modern-input-wrapper { position: relative; display: flex; align-items: center; }
        .modern-input-icon { position: absolute; left: 1.25rem; font-size: 1.2rem; opacity: 0.6; pointer-events: none; z-index: 2; }
        .modern-input, .modern-select {
            width: 100%; background: rgba(0, 0, 0, 0.4); border: 1px solid rgba(255, 255, 255, 0.1);
            color: white; padding: 1.1rem 1rem 1.1rem 3.5rem; border-radius: 14px; font-size: 0.95rem; transition: 0.3s;
        }
        .modern-select { appearance: none; cursor: pointer; }
        .modern-select option { background: #0f172a; color: white; }
        .modern-input:focus, .modern-select:focus { outline: none; border-color: var(--primary-color); background: rgba(0,0,0,0.6); box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15);}
        input[type="date"].modern-input::-webkit-calendar-picker-indicator { filter: invert(1); cursor: pointer; opacity: 0.6; }

        .btn-modern-submit {
            background: linear-gradient(135deg, var(--primary-color), var(--primary-hover)); color: #121212; border: none;
            padding: 1.1rem 1.5rem; border-radius: 14px; font-size: 0.95rem; font-weight: 800; text-transform: uppercase;
            cursor: pointer; transition: 0.3s; height: 100%; display: flex; align-items: center; gap: 8px;
        }
        .btn-modern-submit:hover { transform: translateY(-3px); box-shadow: 0 10px 20px rgba(59, 130, 246, 0.3); }

        .btn-modern-reset {
            background: rgba(255,255,255,0.05); color: #cbd5e1; border: 1px solid rgba(255,255,255,0.1);
            padding: 1.1rem 1.5rem; border-radius: 14px; font-size: 0.95rem; font-weight: 700; text-decoration: none;
            display: flex; align-items: center; justify-content: center; transition: 0.3s; height: 100%; text-transform: uppercase;
        }
        .btn-modern-reset:hover { background: rgba(255,255,255,0.1); color: #fff; transform: translateY(-3px); }

        /* --- Modern Table --- */
        .modern-table-wrapper {
            background: rgba(0, 0, 0, 0.25); border-radius: 18px; border: 1px solid rgba(255, 255, 255, 0.05); overflow-x: auto;
        }
        .report-table { width: 100%; border-collapse: collapse; font-size: 0.9rem; min-width: 1000px; color: #e2e8f0; }
        .report-table th, .report-table td {
            padding: 1.2rem 1rem; border-bottom: 1px solid rgba(255, 255, 255, 0.03); text-align: left; vertical-align: middle; white-space: nowrap;
        }
        .report-table th { background: rgba(255, 255, 255, 0.03); font-weight: 600; text-transform: uppercase; font-size: 0.8rem; letter-spacing: 0.05em; color: #94a3b8; }
        
        .report-table tr:hover td { background: rgba(255, 255, 255, 0.05); }
        .report-table tr:last-child td { border-bottom: none; }

        /* Sorting Links inside Headers */
        .sortable a { color: inherit; text-decoration: none; display: flex; align-items: center; gap: 5px; transition: color 0.2s; }
        .sortable a:hover { color: #fff; }
        .sortable.sorted-asc a { color: var(--primary-color); }
        .sortable.sorted-desc a { color: var(--primary-color); }
        .sortable.sorted-asc a::after { content: '▲'; font-size: 0.7em; }
        .sortable.sorted-desc a::after { content: '▼'; font-size: 0.7em; }

        /* Custom Table Elements */
        .emp-name { font-weight: 700; color: #fff; font-size: 1rem; }
        .duty-time-modern { color: #60a5fa; font-weight: 700; }
        .net-salary-modern { color: #10b981; font-weight: 800; font-size: 1.1rem; text-shadow: 0 0 10px rgba(16,185,129,0.3); }

        .role-badge-modern {
            background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); color: #cbd5e1;
            font-size: 0.7rem; font-weight: 800; padding: 4px 8px; border-radius: 6px; text-transform: uppercase; letter-spacing: 0.05em;
        }

        .status-badge-pill {
            display: inline-flex; align-items: center; padding: 6px 12px; border-radius: 30px; font-size: 0.8rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em;
        }
        .sb-Pending { background: rgba(245, 158, 11, 0.15); color: #fbbf24; border: 1px solid rgba(245, 158, 11, 0.3); }
        .sb-Paid { background: rgba(16, 185, 129, 0.15); color: #34d399; border: 1px solid rgba(16, 185, 129, 0.3); }
        .sb-Error { background: rgba(239, 68, 68, 0.15); color: #fca5a5; border: 1px solid rgba(239, 68, 68, 0.3); }

        @keyframes slideDown { from { opacity: 0; transform: translateY(-20px); } to { opacity: 1; transform: translateY(0); } }
        @keyframes slideUp { from { opacity: 0; transform: translateY(30px); } to { opacity: 1; transform: translateY(0); } }

        @media (max-width: 1024px) {
            .form-row-modern { grid-template-columns: 1fr 1fr; }
        }
        @media (max-width: 768px) {
            .modern-page-header { padding: 1.5rem; text-align: center; }
            .header-content-wrapper { flex-direction: column; }
            .form-row-modern { grid-template-columns: 1fr; }
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
                    <div class="header-icon-wrapper">🗓️</div>
                    <div class="header-text-wrapper">
                        <h1>Rekap Gaji Mingguan</h1>
                        <p>Data histori nominal gaji anggota per minggu yang dicadangkan oleh sistem.</p>
                    </div>
                </div>
            </div>

            <div class="modern-info-box">
                <span class="icon">💡</span>
                <div>
                    <strong>Jadwal Backup Otomatis:</strong> Sistem mencatat dan menyimpan rekapitulasi gaji pada <strong>Hari Senin, Pukul 12:00 WIB</strong> setiap minggunya.
                </div>
            </div>

            <div class="modern-card">
                <div class="modern-card-header">
                    <h3><span>📜</span> Riwayat Backup Gaji</h3>
                </div>
                
                <div class="form-inner-wrapper">
                    <form method="GET" class="report-form">
                        <div class="form-row-modern">
                            <div class="modern-form-group" style="margin-bottom: 0;">
                                <label for="role_filter">Filter Jabatan</label>
                                <div class="modern-input-wrapper">
                                    <span class="modern-input-icon">🏷️</span>
                                    <select name="role" id="role_filter" class="modern-select">
                                        <option value="">-- Semua Jabatan --</option>
                                        <?php foreach ($available_roles as $role_key => $role_name): ?>
                                            <option value="<?= $role_key ?>" <?= $filter_role === $role_key ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($role_name) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="modern-form-group" style="margin-bottom: 0;">
                                <label for="backup_date_filter">Filter Tanggal Backup</label>
                                <div class="modern-input-wrapper">
                                    <span class="modern-input-icon">📅</span>
                                    <input type="date" name="backup_date" id="backup_date_filter" class="modern-input" value="<?= htmlspecialchars($filter_date) ?>">
                                </div>
                            </div>
                            
                            <button type="submit" class="btn-modern-submit">
                                <span>🔍</span> Terapkan Filter
                            </button>

                            <?php if (!empty($filter_role) || !empty($filter_date)): ?>
                                <a href="weekly-salary-recap.php" class="btn-modern-reset">
                                    ✖ Reset
                                </a>
                            <?php endif; ?>

                            <?php if (in_array($sort_by, $allowed_sorts) && !empty($sort_by)): ?>
                                <input type="hidden" name="sort_by" value="<?= htmlspecialchars($sort_by) ?>">
                                <input type="hidden" name="sort_order" value="<?= htmlspecialchars($sort_order) ?>">
                            <?php endif; ?>
                        </div>
                    </form>
                </div>

                <div class="card-content">
                    <?php if (empty($backup_data)): ?>
                        <div class="no-data" style="text-align:center; padding: 3rem; font-style:italic; color: #64748b;">
                            <span style="font-size: 3rem; display: block; margin-bottom: 10px;">🍃</span>
                            Belum ada data gaji mingguan yang di-backup sesuai kriteria.
                        </div>
                    <?php else: ?>
                        <div class="modern-table-wrapper">
                            <table class="report-table">
                                <thead>
                                    <tr>
                                        <th class="sortable <?= getSortClass('week_start', $sort_by, $sort_order) ?>">
                                            <a href="<?= getSortUrl('week_start', $sort_by, $sort_order, $filter_role, $filter_date) ?>">Periode Minggu</a>
                                        </th>
                                        <th class="sortable <?= getSortClass('employee_name', $sort_by, $sort_order) ?>">
                                            <a href="<?= getSortUrl('employee_name', $sort_by, $sort_order, $filter_role, $filter_date) ?>">Nama Anggota</a>
                                        </th>
                                        <th class="sortable <?= getSortClass('employee_role', $sort_by, $sort_order) ?>">
                                            <a href="<?= getSortUrl('employee_role', $sort_by, $sort_order, $filter_role, $filter_date) ?>">Jabatan</a>
                                        </th>
                                        <th>Jam Asli</th>
                                        <th>Jam Bulat</th>
                                        <th>Gaji Pokok</th>
                                        <th class="sortable <?= getSortClass('total_net_salary', $sort_by, $sort_order) ?>">
                                            <a href="<?= getSortUrl('total_net_salary', $sort_by, $sort_order, $filter_role, $filter_date) ?>">Total Bersih</a>
                                        </th>
                                        <th>Status</th>
                                        <th class="sortable <?= getSortClass('backup_date', $sort_by, $sort_order) ?>">
                                            <a href="<?= getSortUrl('backup_date', $sort_by, $sort_order, $filter_role, $filter_date) ?>">Waktu Backup</a>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($backup_data as $data): ?>
                                    <tr>
                                        <td style="color: #94a3b8;">
                                            <?= date('d/m', strtotime($data['week_start'])) ?> <span style="color: var(--primary-color);">→</span> <?= date('d/m/y', strtotime($data['week_end'])) ?>
                                        </td>
                                        <td class="emp-name"><?= htmlspecialchars($data['employee_name']) ?></td>
                                        <td><span class="role-badge-modern"><?= getRoleDisplayName($data['employee_role']) ?></span></td>
                                        
                                        <td style="color: #cbd5e1;"><?= formatDuration($data['duty_minutes_actual']) ?></td>
                                        <td class="duty-time-modern"><?= formatDuration($data['duty_minutes_rounded']) ?></td>
                                        
                                        <td style="color: #cbd5e1;"><?= formatDollar($data['base_salary_nominal']) ?></td>
                                        <td class="net-salary-modern"><?= formatDollar($data['total_net_salary']) ?></td>
                                        
                                        <td>
                                            <span class="status-badge-pill sb-<?= htmlspecialchars($data['payment_status']) ?>">
                                                <?= htmlspecialchars($data['payment_status']) ?>
                                            </span>
                                        </td>
                                        
                                        <td style="color: #64748b; font-size: 0.85rem;">
                                            <?= date('d/m/y H:i', strtotime($data['backup_date'])) ?>
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

    <script src="script.js"></script>
</body>
</html>