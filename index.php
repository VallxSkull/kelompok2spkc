<?php
session_start();

// ============================================================
// FUNGSI-FUNGSI PEMBANTU (tanpa PhpSpreadsheet)
// ============================================================

/**
 * Format angka sebagai USD
 */
function formatPriceUsd($val) {
    if ($val === null || $val === '') return '';
    return '$' . number_format((int)$val, 0, '', ',');
}

/**
 * Mapping CPU ke skor performa
 */
function getCpuScore($cpuStr) {
    $cpu = strtolower((string)$cpuStr);
    if (strpos($cpu, 'm4 max') !== false) return 8;
    if (strpos($cpu, 'm4 pro') !== false) return 7;
    if (strpos($cpu, 'm4') !== false) return 6;
    if (strpos($cpu, 'ryzen 9') !== false) return 6;
    if (strpos($cpu, 'ryzen 7') !== false || strpos($cpu, 'ryzen ai 7') !== false) return 5;
    if (strpos($cpu, 'ryzen 5') !== false || strpos($cpu, 'ryzen ai 5') !== false) return 4;
    if (strpos($cpu, 'core ultra 9') !== false) return 6;
    if (strpos($cpu, 'core ultra 7') !== false) return 5;
    if (strpos($cpu, 'core ultra 5') !== false) return 4;
    if (strpos($cpu, 'core i7') !== false) return 4;
    if (strpos($cpu, 'core i5') !== false) return 3;
    if (strpos($cpu, 'snapdragon x') !== false) return 4;
    if (strpos($cpu, 'intel n100') !== false) return 1;
    if (strpos($cpu, 'mediatek') !== false) return 1;
    return 2;
}

/**
 * Mapping GPU ke skor performa
 */
function getGpuScore($gpuStr) {
    $gpu = strtolower((string)$gpuStr);
    if (strpos($gpu, 'rtx 5080') !== false) return 6;
    if (strpos($gpu, 'rtx 5070') !== false) return 5;
    if (strpos($gpu, 'rtx 5060') !== false) return 4;
    if (strpos($gpu, 'rtx 5050') !== false) return 3;
    if (strpos($gpu, 'rtx 4050') !== false) return 2;
    if (strpos($gpu, 'integrated') !== false) return 0;
    return 0;
}

/**
 * Preprocessing data: normalisasi kolom, buat skor CPU/GPU, dll.
 */
function preprocessData($rows) {
    if (empty($rows)) return null;
    $clean = [];
    // Ambil header dan lowercased + underscore
    $header = array_keys($rows[0]);
    $headerLower = array_map(function($col) {
        $col = strtolower(trim($col));
        $col = str_replace(' ', '_', $col);
        $col = preg_replace('/[^\w_]/', '', $col);
        return $col;
    }, $header);
    $headerMap = array_combine($header, $headerLower);

    // Mapping nama kolom yang diinginkan
    $renameMap = [
        'brand'        => 'brand_name',
        'model'        => 'model',
        'price_usd'    => 'price',
        'ram_gb'       => 'ram_num',
        'storage_gb'   => 'memory_size',
        'cpu'          => 'cpu_str',
        'gpu'          => 'gpu_str',
        'category'     => 'category'
    ];
    // Terapkan rename pada headerLower
    $finalHeader = [];
    foreach ($headerLower as $idx => $col) {
        $finalHeader[$idx] = $renameMap[$col] ?? $col;
    }

    // Proses setiap baris
    foreach ($rows as $rowIdx => $row) {
        $newRow = [];
        foreach ($row as $colIdx => $value) {
            $colName = $finalHeader[$colIdx];
            $newRow[$colName] = $value;
        }
        // Pastikan kolom wajib ada
        $required = ['brand_name', 'model', 'price', 'ram_num', 'memory_size', 'cpu_str', 'gpu_str'];
        $missing = array_diff($required, array_keys($newRow));
        if (!empty($missing)) {
            continue;
        }
        // Buat Nama Laptop
        $newRow['Nama Laptop'] = $newRow['brand_name'] . ' ' . $newRow['model'];
        // Konversi numerik
        $newRow['price'] = (float)$newRow['price'];
        $newRow['ram_num'] = (float)$newRow['ram_num'];
        $newRow['memory_size'] = (float)$newRow['memory_size'];
        // Skor CPU/GPU
        $newRow['cpu_score'] = getCpuScore($newRow['cpu_str']);
        $newRow['gpu_score'] = getGpuScore($newRow['gpu_str']);
        $clean[] = $newRow;
    }
    // Hapus duplikat berdasarkan Nama Laptop
    $unique = [];
    $seen = [];
    foreach ($clean as $row) {
        $name = $row['Nama Laptop'];
        if (!in_array($name, $seen)) {
            $seen[] = $name;
            $unique[] = $row;
        }
    }
    return $unique;
}

/**
 * Membaca file CSV (hanya CSV, tidak Excel)
 * Mengembalikan array asosiatif
 */
function readCsvFile($file) {
    $tmpPath = $file['tmp_name'];
    // Coba berbagai encoding
    $encodings = ['UTF-8', 'ISO-8859-1', 'CP1252', 'LATIN1'];
    $content = file_get_contents($tmpPath);
    if ($content === false) return null;

    foreach ($encodings as $enc) {
        if ($enc !== 'UTF-8') {
            $converted = mb_convert_encoding($content, 'UTF-8', $enc);
        } else {
            $converted = $content;
        }
        // Coba parse dengan delimiter koma, titik koma, tab
        $delimiters = [',', ';', "\t"];
        foreach ($delimiters as $delim) {
            $lines = explode("\n", $converted);
            $rows = [];
            $header = null;
            $valid = true;
            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line)) continue;
                $fields = str_getcsv($line, $delim, '"', '\\');
                if ($header === null) {
                    $header = $fields;
                } else {
                    if (count($fields) == count($header)) {
                        $rows[] = array_combine($header, $fields);
                    } else {
                        $valid = false;
                        break;
                    }
                }
            }
            if ($valid && !empty($rows)) {
                return $rows;
            }
        }
    }
    return null;
}

// ============================================================
// INISIALISASI SESSION STATE
// ============================================================
if (!isset($_SESSION['df_raw'])) $_SESSION['df_raw'] = null;
if (!isset($_SESSION['df_clean'])) $_SESSION['df_clean'] = null;
if (!isset($_SESSION['selected_criteria'])) $_SESSION['selected_criteria'] = [];
if (!isset($_SESSION['weights'])) $_SESSION['weights'] = [];
if (!isset($_SESSION['profile'])) $_SESSION['profile'] = 'Custom';
if (!isset($_SESSION['apply_profile'])) $_SESSION['apply_profile'] = false;
if (!isset($_SESSION['profile_to_apply'])) $_SESSION['profile_to_apply'] = null;

// ============================================================
// PROSES UPLOAD FILE
// ============================================================
$errorMsg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['dataset'])) {
    $file = $_FILES['dataset'];
    if ($file['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($ext === 'csv') {
            $data = readCsvFile($file);
            if ($data !== null) {
                $_SESSION['df_raw'] = $data;
                $clean = preprocessData($data);
                if ($clean !== null) {
                    $_SESSION['df_clean'] = $clean;
                    $_SESSION['selected_criteria'] = [];
                    $_SESSION['weights'] = [];
                } else {
                    $errorMsg = 'Gagal preprocessing data. Periksa format kolom.';
                }
            } else {
                $errorMsg = 'Gagal membaca file CSV. Pastikan format benar.';
            }
        } else {
            $errorMsg = 'Hanya file CSV yang didukung (tanpa PhpSpreadsheet). Silakan konversi ke CSV.';
        }
    } else {
        $errorMsg = 'Terjadi error saat upload file.';
    }
}

// ============================================================
// PROSES FILTER (price range, brand) & KRITERIA/BOBOT
// ============================================================
$dfClean = $_SESSION['df_clean'] ?? [];
$filtered = $dfClean;

$priceMin = isset($_GET['price_min']) ? (float)$_GET['price_min'] : null;
$priceMax = isset($_GET['price_max']) ? (float)$_GET['price_max'] : null;
$selectedBrands = isset($_GET['brands']) ? (array)$_GET['brands'] : [];
$rowsLimit = isset($_GET['rows_limit']) ? (int)$_GET['rows_limit'] : 10;

if (empty($dfClean)) {
    $showUpload = true;
} else {
    $showUpload = false;
    $prices = array_column($dfClean, 'price');
    $minPriceAll = min($prices);
    $maxPriceAll = max($prices);
    if ($priceMin === null || $priceMax === null) {
        $priceMin = max($minPriceAll, 200);
        $priceMax = min($maxPriceAll, 2000);
        if ($priceMin >= $priceMax) {
            $priceMin = $minPriceAll;
            $priceMax = $maxPriceAll;
        }
    }
    $filtered = array_filter($dfClean, function($row) use ($priceMin, $priceMax, $selectedBrands) {
        if ($row['price'] < $priceMin || $row['price'] > $priceMax) return false;
        if (!empty($selectedBrands)) {
            if (!in_array($row['brand_name'], $selectedBrands)) return false;
        }
        return true;
    });
    if (count($filtered) > $rowsLimit) {
        $filtered = array_slice($filtered, 0, $rowsLimit);
    }
    if (empty($filtered)) {
        $filtered = array_slice($dfClean, 0, 1);
    }
    $_SESSION['filtered'] = $filtered;
}

// ============================================================
// PROSES KRITERIA & BOBOT (POST)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'set_criteria') {
        $selected = isset($_POST['criteria']) ? (array)$_POST['criteria'] : [];
        $_SESSION['selected_criteria'] = $selected;
        $weights = [];
        foreach ($selected as $crit) {
            $w = isset($_POST["weight_$crit"]) ? (float)$_POST["weight_$crit"] : 0.2;
            $benefit = isset($_POST["benefit_$crit"]) ? true : false;
            $weights[$crit] = ['weight' => $w, 'benefit' => $benefit];
        }
        $total = array_sum(array_column($weights, 'weight'));
        if ($total == 0) $total = 1;
        foreach ($weights as &$w) {
            $w['weight'] /= $total;
        }
        $_SESSION['weights'] = $weights;
    }
    if ($_POST['action'] === 'apply_profile') {
        $profileName = $_POST['profile_name'] ?? '';
        $_SESSION['profile_to_apply'] = $profileName;
        $_SESSION['apply_profile'] = true;
        header('Location: ' . $_SERVER['PHP_SELF'] . '?' . http_build_query($_GET));
        exit;
    }
}

if ($_SESSION['apply_profile'] && !empty($_SESSION['selected_criteria'])) {
    $profileName = $_SESSION['profile_to_apply'];
    $profiles = [
        "Mahasiswa (Harga murah)" => ['price'=>0.50, 'ram_num'=>0.15, 'memory_size'=>0.15, 'cpu_score'=>0.15, 'gpu_score'=>0.05],
        "Pekerja Kantoran (Fokus Keseimbangan)" => ['price'=>0.35, 'ram_num'=>0.20, 'memory_size'=>0.20, 'cpu_score'=>0.20, 'gpu_score'=>0.05],
        "Desainer/Gamer (Fokus Performa)" => ['price'=>0.10, 'ram_num'=>0.20, 'memory_size'=>0.15, 'cpu_score'=>0.25, 'gpu_score'=>0.30],
    ];
    $selected = $_SESSION['selected_criteria'];
    if (isset($profiles[$profileName])) {
        $prof = $profiles[$profileName];
        $filled = [];
        foreach ($selected as $crit) {
            $filled[$crit] = $prof[$crit] ?? 0.0;
        }
        $total = array_sum($filled);
        if ($total > 0) {
            foreach ($filled as &$v) $v /= $total;
            $weights = $_SESSION['weights'];
            foreach ($selected as $crit) {
                $weights[$crit]['weight'] = $filled[$crit];
            }
            $_SESSION['weights'] = $weights;
        }
    }
    $_SESSION['apply_profile'] = false;
    $_SESSION['profile_to_apply'] = null;
    header('Location: ' . $_SERVER['PHP_SELF'] . '?' . http_build_query($_GET));
    exit;
}

// ============================================================
// FUNGSI PERHITUNGAN SAW
// ============================================================
function calculateSAW($data, $criteria, $weights) {
    if (empty($data) || empty($criteria) || empty($weights)) return null;

    $result = [];
    foreach ($data as $idx => $row) {
        $alt = [
            'Nama Laptop' => $row['Nama Laptop'],
            'criteria' => []
        ];
        foreach ($criteria as $crit) {
            $alt['criteria'][$crit] = $row[$crit];
        }
        $result[$idx] = $alt;
    }

    $norm = [];
    foreach ($criteria as $crit) {
        $values = array_column($result, 'criteria', $crit);
        $values = array_column($values, $crit);
        $maxVal = max($values);
        $minVal = min($values);
        $benefit = $weights[$crit]['benefit'];
        foreach ($result as $idx => &$alt) {
            $val = $alt['criteria'][$crit];
            if ($benefit) {
                $normVal = ($maxVal == 0) ? 0 : $val / $maxVal;
            } else {
                $normVal = ($val == 0) ? 0 : $minVal / $val;
            }
            $alt['norm'][$crit] = $normVal;
        }
    }

    foreach ($result as &$alt) {
        $score = 0;
        foreach ($criteria as $crit) {
            $score += $alt['norm'][$crit] * $weights[$crit]['weight'];
        }
        $alt['score'] = $score;
    }

    usort($result, function($a, $b) {
        return $b['score'] <=> $a['score'];
    });

    return $result;
}

// ============================================================
// AMBIL DATA UNTUK TAMPILAN
// ============================================================
$dfDisplay = $_SESSION['filtered'] ?? [];
$criteriaList = $_SESSION['selected_criteria'] ?? [];
$weightSettings = $_SESSION['weights'] ?? [];

$candidateCriteria = [
    'price'       => ['label' => 'Harga (USD)', 'default_benefit' => false],
    'ram_num'     => ['label' => 'RAM (GB)', 'default_benefit' => true],
    'memory_size' => ['label' => 'Storage (GB)', 'default_benefit' => true],
    'cpu_score'   => ['label' => 'Skor CPU', 'default_benefit' => true],
    'gpu_score'   => ['label' => 'Skor GPU', 'default_benefit' => true],
];

$available = [];
foreach ($candidateCriteria as $key => $info) {
    if (!empty($dfDisplay) && isset($dfDisplay[0][$key])) {
        $available[$key] = $info;
    }
}
if (empty($criteriaList) && !empty($available)) {
    $default = ['price', 'ram_num', 'memory_size', 'cpu_score', 'gpu_score'];
    $criteriaList = array_intersect($default, array_keys($available));
    $_SESSION['selected_criteria'] = $criteriaList;
}
if (empty($weightSettings) && !empty($criteriaList)) {
    foreach ($criteriaList as $crit) {
        $weightSettings[$crit] = ['weight' => 0.2, 'benefit' => $available[$crit]['default_benefit']];
    }
    $total = count($criteriaList) * 0.2;
    if ($total > 0) {
        foreach ($weightSettings as &$w) {
            $w['weight'] /= $total;
        }
    }
    $_SESSION['weights'] = $weightSettings;
}

$ranking = null;
if (!empty($dfDisplay) && !empty($criteriaList) && !empty($weightSettings)) {
    $ranking = calculateSAW($dfDisplay, $criteriaList, $weightSettings);
}
?>
<!-- HTML dan CSS sama seperti sebelumnya, hanya beda di bagian upload -->
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SPK Pemilihan Laptop dengan SAW</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        /* (Sama seperti sebelumnya) */
        :root {
            --bg: #EEEEEE;
            --green-light: #6FCF97;
            --green-mid: #2FA084;
            --green-dark: #1F6F5F;
            --white: #ffffff;
            --text-dark: #1a1a2e;
            --text-mid: #2d4a40;
            --text-light: #5a7a72;
            --border: rgba(31,111,95,0.15);
            --shadow: 0 4px 24px rgba(31,111,95,0.10);
            --shadow-hover: 0 12px 40px rgba(31,111,95,0.18);
            --radius: 16px;
            --radius-sm: 10px;
            --transition: all 0.3s cubic-bezier(0.4,0,0.2,1);
        }
        * { box-sizing:border-box; margin:0; padding:0; }
        body { background: var(--bg); font-family: 'Segoe UI', Roboto, sans-serif; color: var(--text-dark); padding:20px; line-height:1.6; }
        .container { max-width:1400px; margin:0 auto; }
        .main-header { font-size:2.5rem; color:var(--green-dark); margin-bottom:0.2rem; font-weight:700; }
        .sub-header { font-size:1.2rem; color:var(--text-light); margin-bottom:1.5rem; }
        .card { background:var(--white); border-radius:var(--radius); box-shadow:var(--shadow); padding:20px 24px; margin-bottom:24px; transition:var(--transition); }
        .card:hover { box-shadow:var(--shadow-hover); }
        .card-header { font-size:1.3rem; font-weight:600; color:var(--text-mid); margin-bottom:16px; border-bottom:2px solid var(--border); padding-bottom:8px; }
        .sidebar { background:var(--white); border-radius:var(--radius); box-shadow:var(--shadow); padding:20px; margin-bottom:24px; }
        .sidebar h2, .sidebar h3 { color:var(--text-mid); }
        .sidebar label { display:block; margin-top:12px; font-weight:500; }
        .sidebar input[type="range"] { width:100%; margin:8px 0; }
        .sidebar select[multiple] { width:100%; min-height:100px; border:1px solid var(--border); border-radius:var(--radius-sm); padding:6px; }
        .sidebar .btn { background:var(--green-mid); color:white; border:none; padding:8px 18px; border-radius:var(--radius-sm); font-weight:600; cursor:pointer; transition:var(--transition); margin-top:12px; }
        .sidebar .btn:hover { background:var(--green-dark); transform:translateY(-2px); }
        .sidebar .btn-secondary { background:var(--bg); color:var(--text-dark); border:1px solid var(--border); }
        .sidebar .btn-secondary:hover { background:#ddd; }
        .row { display:flex; flex-wrap:wrap; gap:24px; }
        .col-3 { flex:0 0 calc(25% - 24px); min-width:250px; }
        .col-9 { flex:1; min-width:300px; }
        .col-12 { flex:0 0 100%; }
        .tab-nav { display:flex; border-bottom:2px solid var(--border); margin-bottom:20px; flex-wrap:wrap; }
        .tab-nav button { background:transparent; border:none; padding:10px 20px; font-size:1rem; font-weight:600; color:var(--text-light); cursor:pointer; transition:var(--transition); border-bottom:3px solid transparent; margin-bottom:-2px; }
        .tab-nav button.active { color:var(--green-dark); border-bottom-color:var(--green-mid); }
        .tab-content { display:none; }
        .tab-content.active { display:block; }
        table { width:100%; border-collapse:collapse; font-size:0.9rem; }
        th, td { padding:10px 12px; text-align:left; border-bottom:1px solid var(--border); }
        th { background:var(--bg); font-weight:600; color:var(--text-mid); }
        tr:hover { background:rgba(111,207,151,0.08); }
        .table-wrap { overflow-x:auto; }
        .metric-group { display:flex; flex-wrap:wrap; gap:16px; margin-bottom:20px; }
        .metric-item { background:var(--bg); padding:12px 18px; border-radius:var(--radius-sm); flex:1 0 150px; }
        .metric-item .label { font-size:0.8rem; color:var(--text-light); text-transform:uppercase; letter-spacing:0.5px; }
        .metric-item .value { font-size:1.4rem; font-weight:700; color:var(--text-dark); }
        .chart-container { position:relative; height:300px; margin:16px 0; }
        .download-btn { background:var(--green-mid); color:white; border:none; padding:10px 24px; border-radius:var(--radius-sm); font-weight:600; cursor:pointer; transition:var(--transition); margin-top:12px; }
        .download-btn:hover { background:var(--green-dark); }
        @media (max-width:768px) { .col-3 { flex:0 0 100%; } .main-header { font-size:1.8rem; } }
    </style>
</head>
<body>
<div class="container">

    <h1 class="main-header">SPK Pemilihan Laptop dengan SAW</h1>
    <p class="sub-header">Metode Simple Additive Weighting – Dataset CSV</p>

    <div class="sidebar">
        <h2>📁 Data dan Pengaturan</h2>
        <form method="post" enctype="multipart/form-data">
            <label for="dataset">Unggah dataset (CSV)</label>
            <input type="file" name="dataset" id="dataset" accept=".csv" required>
            <button type="submit" class="btn">Upload</button>
            <?php if ($errorMsg): ?>
                <p style="color:red;"><?= htmlspecialchars($errorMsg) ?></p>
            <?php endif; ?>
        </form>
        <?php if (!$showUpload && !empty($dfClean)): ?>
        <hr style="margin:20px 0; border-color:var(--border);">
        <form method="get" id="filterForm">
            <h3>Filter Data</h3>
            <label>Rentang Harga (USD)</label>
            <div>
                <input type="range" name="price_min" min="<?= $minPriceAll ?>" max="<?= $maxPriceAll ?>" value="<?= $priceMin ?>" step="10" oninput="document.getElementById('priceMinVal').textContent=this.value">
                <span id="priceMinVal"><?= $priceMin ?></span> –
                <input type="range" name="price_max" min="<?= $minPriceAll ?>" max="<?= $maxPriceAll ?>" value="<?= $priceMax ?>" step="10" oninput="document.getElementById('priceMaxVal').textContent=this.value">
                <span id="priceMaxVal"><?= $priceMax ?></span>
            </div>
            <p><small><?= formatPriceUsd($priceMin) ?> – <?= formatPriceUsd($priceMax) ?></small></p>

            <label>Pilih Merek (Ctrl+klik untuk multi)</label>
            <select name="brands[]" multiple>
                <?php
                $allBrands = array_unique(array_column($dfClean, 'brand_name'));
                sort($allBrands);
                foreach ($allBrands as $brand):
                    $sel = in_array($brand, $selectedBrands) ? 'selected' : '';
                ?>
                <option value="<?= htmlspecialchars($brand) ?>" <?= $sel ?>><?= htmlspecialchars($brand) ?></option>
                <?php endforeach; ?>
            </select>

            <label>Jumlah Laptop Diproses (maks. 10)</label>
            <input type="number" name="rows_limit" min="1" max="10" value="<?= $rowsLimit ?>">

            <button type="submit" class="btn">Terapkan Filter</button>
            <p><small>Menampilkan <?= count($filtered) ?> laptop dari <?= count($dfClean) ?> total.</small></p>
        </form>
        <?php endif; ?>
    </div>

    <?php if ($showUpload || empty($dfClean)): ?>
        <div class="card"><p>Silakan unggah file dataset (CSV) untuk memulai.</p></div>
    <?php else: ?>

    <div class="tab-nav">
        <button class="tab-btn active" data-tab="tab1">Eksplorasi Data</button>
        <button class="tab-btn" data-tab="tab2">Kriteria dan Bobot</button>
        <button class="tab-btn" data-tab="tab3">Perhitungan SAW</button>
        <button class="tab-btn" data-tab="tab4">Peringkat</button>
    </div>

    <!-- TAB 1 -->
    <div id="tab1" class="tab-content active">
        <div class="card">
            <div class="card-header">📊 Eksplorasi Data Laptop</div>
            <div class="metric-group">
                <div class="metric-item"><span class="label">Jumlah Laptop</span><div class="value"><?= count($filtered) ?></div></div>
                <div class="metric-item"><span class="label">Rentang Harga</span><div class="value"><?= formatPriceUsd(min(array_column($filtered, 'price'))) ?> – <?= formatPriceUsd(max(array_column($filtered, 'price'))) ?></div></div>
                <div class="metric-item"><span class="label">Rata-rata RAM</span><div class="value"><?= number_format(array_sum(array_column($filtered, 'ram_num')) / count($filtered), 1) ?> GB</div></div>
                <div class="metric-item"><span class="label">Rata-rata Storage</span><div class="value"><?= number_format(array_sum(array_column($filtered, 'memory_size')) / count($filtered), 0) ?> GB</div></div>
            </div>
            <div class="chart-container"><canvas id="priceHistogram"></canvas></div>
            <div class="chart-container" style="height:350px;"><canvas id="ramPriceScatter"></canvas></div>
            <details>
                <summary style="cursor:pointer; font-weight:600; color:var(--green-dark);">Lihat Data Detail</summary>
                <div class="table-wrap" style="margin-top:12px;">
                    <table>
                        <thead><tr><th>Nama Laptop</th><th>Harga</th><th>RAM</th><th>Storage</th><th>CPU</th><th>GPU</th><th>Skor CPU</th><th>Skor GPU</th></tr></thead>
                        <tbody>
                            <?php foreach ($filtered as $row): ?>
                            <tr>
                                <td><?= htmlspecialchars($row['Nama Laptop']) ?></td>
                                <td><?= formatPriceUsd($row['price']) ?></td>
                                <td><?= $row['ram_num'] ?> GB</td>
                                <td><?= $row['memory_size'] ?> GB</td>
                                <td><?= htmlspecialchars($row['cpu_str']) ?></td>
                                <td><?= htmlspecialchars($row['gpu_str']) ?></td>
                                <td><?= $row['cpu_score'] ?></td>
                                <td><?= $row['gpu_score'] ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </details>
        </div>
    </div>

    <!-- TAB 2 -->
    <div id="tab2" class="tab-content">
        <div class="card">
            <div class="card-header">⚖️ Pilih Kriteria dan Atur Bobot</div>
            <form method="post" action="">
                <input type="hidden" name="action" value="set_criteria">
                <div class="row">
                    <div class="col-12">
                        <label>Pilih kriteria yang akan digunakan:</label><br>
                        <?php foreach ($available as $key => $info): ?>
                            <label style="display:inline-block; margin-right:16px;">
                                <input type="checkbox" name="criteria[]" value="<?= $key ?>"
                                    <?= in_array($key, $criteriaList) ? 'checked' : '' ?>>
                                <?= $info['label'] ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php if (!empty($criteriaList)): ?>
                <div class="row" style="margin-top:20px;">
                    <?php foreach ($criteriaList as $crit): ?>
                    <div class="col-3" style="min-width:180px;">
                        <label><strong><?= $available[$crit]['label'] ?></strong></label>
                        <div>
                            <label style="display:block;">
                                <input type="checkbox" name="benefit_<?= $crit ?>" value="1"
                                    <?= ($weightSettings[$crit]['benefit'] ?? $available[$crit]['default_benefit']) ? 'checked' : '' ?>>
                                Benefit
                            </label>
                        </div>
                        <label>Bobot
                            <input type="number" name="weight_<?= $crit ?>" step="0.01" min="0" max="1"
                                value="<?= number_format($weightSettings[$crit]['weight'] ?? 0.2, 3) ?>"
                                style="width:80px;">
                        </label>
                    </div>
                    <?php endforeach; ?>
                </div>
                <button type="submit" class="btn">Simpan Kriteria & Bobot</button>
                <?php endif; ?>
            </form>

            <hr style="margin:24px 0; border-color:var(--border);">
            <h3>Gunakan Profil Bawaan</h3>
            <form method="post" action="">
                <input type="hidden" name="action" value="apply_profile">
                <label>Pilih profil:</label>
                <select name="profile_name">
                    <option value="Mahasiswa (Harga murah)">Mahasiswa (Harga murah)</option>
                    <option value="Pekerja Kantoran (Fokus Keseimbangan)">Pekerja Kantoran (Fokus Keseimbangan)</option>
                    <option value="Desainer/Gamer (Fokus Performa)">Desainer/Gamer (Fokus Performa)</option>
                </select>
                <button type="submit" class="btn btn-secondary">Terapkan Bobot Profil</button>
            </form>
            <?php if (!empty($_SESSION['weights'])): ?>
                <div style="margin-top:16px;">
                    <p><strong>Bobot saat ini (total = 1):</strong></p>
                    <table>
                        <thead><tr><th>Kriteria</th><th>Bobot</th><th>Tipe</th></tr></thead>
                        <tbody>
                        <?php foreach ($criteriaList as $crit): ?>
                            <tr>
                                <td><?= $available[$crit]['label'] ?></td>
                                <td><?= number_format($_SESSION['weights'][$crit]['weight'], 3) ?></td>
                                <td><?= $_SESSION['weights'][$crit]['benefit'] ? 'Benefit' : 'Cost' ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- TAB 3 -->
    <div id="tab3" class="tab-content">
        <div class="card">
            <div class="card-header">🧮 Matriks Normalisasi dan Perhitungan SAW</div>
            <?php if ($ranking === null): ?>
                <p>Belum ada kriteria atau data. Silakan pilih kriteria di tab sebelumnya.</p>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Nama Laptop</th>
                                <?php foreach ($criteriaList as $crit): ?>
                                    <th><?= $available[$crit]['label'] ?></th>
                                <?php endforeach; ?>
                                <?php foreach ($criteriaList as $crit): ?>
                                    <th>Norm. <?= $available[$crit]['label'] ?></th>
                                <?php endforeach; ?>
                                <th>Skor SAW</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($ranking as $item): ?>
                            <tr>
                                <td><?= htmlspecialchars($item['Nama Laptop']) ?></td>
                                <?php foreach ($criteriaList as $crit): ?>
                                    <td><?= number_format($item['criteria'][$crit], 2) ?></td>
                                <?php endforeach; ?>
                                <?php foreach ($criteriaList as $crit): ?>
                                    <td><?= number_format($item['norm'][$crit], 4) ?></td>
                                <?php endforeach; ?>
                                <td><strong><?= number_format($item['score'], 4) ?></strong></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div style="margin:20px 0;">
                    <p><strong>Formula Normalisasi:</strong></p>
                    <p style="font-family: monospace; background: var(--bg); padding:8px; border-radius:var(--radius-sm);">
                        r_ij = x_ij / max(x_ij) &nbsp;(Benefit) <br>
                        r_ij = min(x_ij) / x_ij &nbsp;(Cost) <br>
                        V_i = Σ (w_j * r_ij)
                    </p>
                </div>
                <div class="chart-container" style="height:400px;"><canvas id="sawChart"></canvas></div>
            <?php endif; ?>
        </div>
    </div>

    <!-- TAB 4 -->
    <div id="tab4" class="tab-content">
        <div class="card">
            <div class="card-header">🏆 Peringkat Laptop Berdasarkan SAW</div>
            <?php if ($ranking === null): ?>
                <p>Belum ada perhitungan. Pastikan kriteria dan data tersedia.</p>
            <?php else: ?>
                <?php if (!empty($ranking)): ?>
                    <p style="font-size:1.2rem; color:var(--green-dark);">
                        🥇 Laptop Terbaik: <strong><?= htmlspecialchars($ranking[0]['Nama Laptop']) ?></strong>
                        (Skor: <?= number_format($ranking[0]['score'], 4) ?>)
                    </p>
                <?php endif; ?>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Peringkat</th>
                                <th>Nama Laptop</th>
                                <?php foreach ($criteriaList as $crit): ?>
                                    <th><?= $available[$crit]['label'] ?></th>
                                <?php endforeach; ?>
                                <th>Skor SAW</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $rank = 1; foreach ($ranking as $item): ?>
                            <tr>
                                <td><?= $rank++ ?></td>
                                <td><?= htmlspecialchars($item['Nama Laptop']) ?></td>
                                <?php foreach ($criteriaList as $crit): ?>
                                    <td><?= number_format($item['criteria'][$crit], 2) ?></td>
                                <?php endforeach; ?>
                                <td><strong><?= number_format($item['score'], 4) ?></strong></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div style="margin-top:24px;">
                    <label>Top N tampilkan: 
                        <input type="number" id="topN" value="5" min="1" max="<?= count($ranking) ?>" style="width:60px;">
                        <button onclick="updateTopN()" class="btn btn-secondary">Tampilkan</button>
                    </label>
                    <div class="chart-container" style="height:350px;"><canvas id="topNChart"></canvas></div>
                </div>
                <button class="download-btn" onclick="alert('Fungsi unduh Excel belum diimplementasikan dalam versi PHP ini.')">
                    📥 Unduh Perhitungan Lengkap (Excel)
                </button>
            <?php endif; ?>
        </div>
    </div>

    <?php endif; ?>

    <div style="text-align:center; margin-top:32px; color:var(--text-light); font-size:0.9rem;">
        Dibuat dengan PHP & Chart.js – Kelompok 2
    </div>

</div>

<script>
// Sama seperti sebelumnya (tab, chart)
document.querySelectorAll('.tab-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
        this.classList.add('active');
        document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
        document.getElementById(this.dataset.tab).classList.add('active');
    });
});

<?php if (!$showUpload && !empty($filtered)): ?>
    const priceData = <?= json_encode(array_column($filtered, 'price')) ?>;
    const minP = Math.min(...priceData);
    const maxP = Math.max(...priceData);
    const binCount = 20;
    const binWidth = (maxP - minP) / binCount || 1;
    const bins = Array(binCount).fill(0);
    priceData.forEach(p => {
        let idx = Math.floor((p - minP) / binWidth);
        if (idx >= binCount) idx = binCount - 1;
        bins[idx]++;
    });
    const labels = [];
    for (let i = 0; i < binCount; i++) {
        const low = minP + i * binWidth;
        const high = low + binWidth;
        labels.push('$' + Math.round(low) + '–$' + Math.round(high));
    }
    new Chart(document.getElementById('priceHistogram'), {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                label: 'Jumlah Laptop',
                data: bins,
                backgroundColor: 'rgba(47,160,132,0.6)',
                borderColor: 'var(--green-dark)',
                borderWidth: 1
            }]
        },
        options: { responsive: true, maintainAspectRatio: false, plugins: { title: { display: true, text: 'Distribusi Harga (USD)' } }, scales: { y: { beginAtZero: true } } }
    });

    const scatterData = <?= json_encode($filtered) ?>;
    const scatterLabels = scatterData.map(r => r['Nama Laptop']);
    const ramValues = scatterData.map(r => r['ram_num']);
    const priceValues = scatterData.map(r => r['price']);
    const brandColors = ['#1f6f5f','#2fa084','#6fcf97','#e67e22','#3498db','#e74c3c','#9b59b6','#1abc9c','#f1c40f','#34495e'];
    const brandSet = [...new Set(scatterData.map(r => r['brand_name']))];
    const colorMap = {};
    brandSet.forEach((b, i) => colorMap[b] = brandColors[i % brandColors.length]);
    const pointColors = scatterData.map(r => colorMap[r['brand_name']] || '#999');
    new Chart(document.getElementById('ramPriceScatter'), {
        type: 'scatter',
        data: {
            datasets: [{
                label: 'RAM vs Harga',
                data: scatterData.map((r, i) => ({x: r['ram_num'], y: r['price']})),
                backgroundColor: pointColors,
                pointRadius: 6,
                pointHoverRadius: 10,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                title: { display: true, text: 'RAM vs Harga (warna = merek)' },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            const idx = context.dataIndex;
                            return scatterLabels[idx] + ' | RAM: ' + ramValues[idx] + ' GB | Harga: $' + priceValues[idx];
                        }
                    }
                }
            },
            scales: {
                x: { title: { display: true, text: 'RAM (GB)' } },
                y: { title: { display: true, text: 'Harga (USD)' } }
            }
        }
    });

    <?php if ($ranking !== null): ?>
    const sawRanking = <?= json_encode($ranking) ?>;
    const sawLabels = sawRanking.map(r => r['Nama Laptop']);
    const sawScores = sawRanking.map(r => r['score']);
    new Chart(document.getElementById('sawChart'), {
        type: 'bar',
        data: {
            labels: sawLabels,
            datasets: [{
                label: 'Skor SAW',
                data: sawScores,
                backgroundColor: 'rgba(47,160,132,0.7)',
                borderColor: 'var(--green-dark)',
                borderWidth: 1
            }]
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false,
            plugins: { title: { display: true, text: 'Skor SAW setiap Laptop' } },
            scales: { x: { beginAtZero: true } }
        }
    });

    let topNChartInstance = null;
    function updateTopN() {
        const n = parseInt(document.getElementById('topN').value) || 5;
        const topData = sawRanking.slice(0, n);
        const labelsTop = topData.map(r => r['Nama Laptop']);
        const scoresTop = topData.map(r => r['score']);
        const ctx = document.getElementById('topNChart').getContext('2d');
        if (topNChartInstance) topNChartInstance.destroy();
        topNChartInstance = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: labelsTop,
                datasets: [{
                    label: 'Skor SAW',
                    data: scoresTop,
                    backgroundColor: 'rgba(111,207,151,0.7)',
                    borderColor: 'var(--green-dark)',
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { title: { display: true, text: 'Top ' + n + ' Laptop' }, legend: { display: false } },
                scales: { y: { beginAtZero: true } }
            }
        });
    }
    document.addEventListener('DOMContentLoaded', function() { updateTopN(); });
    <?php endif; ?>
<?php endif; ?>
</script>
</body>
</html>