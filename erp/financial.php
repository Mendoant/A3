<?php
// erp/financial.php - Financial Health Overview
// Updated: Company Dropdown & Sortable Columns

require_once '../config.php';
requireLogin();

// security check
if (!hasRole('SeniorManager')) {
    header('Location: ../scm/dashboard.php');
    exit;
}

$pdo = getPDO();

// --- 1. GET FILTER VALUES ---
// using company_id instead of text search now
$companyId = isset($_GET['company_id']) ? $_GET['company_id'] : '';
$region = isset($_GET['region']) ? $_GET['region'] : '';
$tierLevel = isset($_GET['tier']) ? $_GET['tier'] : '';
$companyType = isset($_GET['type']) ? $_GET['type'] : ''; 
$minHealth = isset($_GET['min_health']) ? $_GET['min_health'] : '';
$maxHealth = isset($_GET['max_health']) ? $_GET['max_health'] : ''; 

// date handling
$startDate = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d', strtotime('-5 years'));
$endDate = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');

$startYear = date('Y', strtotime($startDate));
$endYear = date('Y', strtotime($endDate));

// --- 2. BUILD SQL QUERY ---
$where = array('1=1');
$params = array();

// Filter: Specific Company
if (!empty($companyId)) { 
    $where[] = "c.CompanyID = :companyId"; 
    $params[':companyId'] = $companyId; 
}
// Filter: Region
if (!empty($region)) { 
    $where[] = "l.ContinentName = :region"; 
    $params[':region'] = $region; 
}
// Filter: Tier
if (!empty($tierLevel)) { 
    $where[] = "c.TierLevel = :tier"; 
    $params[':tier'] = $tierLevel; 
}
// Filter: Type
if (!empty($companyType)) {
    $where[] = "c.Type = :type";
    $params[':type'] = $companyType;
}

// Filter: Date Range (Years)
$where[] = "fr.RepYear >= :startYear AND fr.RepYear <= :endYear";
$params[':startYear'] = $startYear;
$params[':endYear'] = $endYear;

// --- 3. EXECUTE QUERY ---
// calculating averages in SQL for performance
$sql = "SELECT 
            c.CompanyID, 
            c.CompanyName, 
            c.Type, 
            c.TierLevel, 
            l.ContinentName, 
            AVG(fr.HealthScore) as AvgHealthScore,
            COUNT(fr.CompanyID) as ReportCount
        FROM Company c
        JOIN Location l ON c.LocationID = l.LocationID
        JOIN FinancialReport fr ON c.CompanyID = fr.CompanyID
        WHERE " . implode(' AND ', $where) . "
        GROUP BY c.CompanyID, c.CompanyName, c.Type, c.TierLevel, l.ContinentName
        ORDER BY AvgHealthScore DESC";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rawCompanies = $stmt->fetchAll();
} catch (PDOException $e) {
    if (isset($_GET['ajax'])) {
        echo json_encode(array('success' => false, 'message' => $e->getMessage()));
        exit;
    }
    die("Database Error: " . $e->getMessage());
}

// --- 4. FILTERING & STATS (PHP Layer) ---
$finalCompanies = array();
$globalSum = 0; 
$healthyCount = 0; 
$warningCount = 0; 
$criticalCount = 0;

$typeAgg = array(); 
$tierAgg = array();

foreach ($rawCompanies as $comp) {
    $avgScore = floatval($comp['AvgHealthScore']);
    
    // min health check
    if (!empty($minHealth) && $avgScore < floatval($minHealth)) continue; 
    
    // max health check 
    if (!empty($maxHealth) && $avgScore > floatval($maxHealth)) continue;
    
    $finalCompanies[] = $comp;
    
    // stats math
    $globalSum += $avgScore;
    
    if ($avgScore >= 75) $healthyCount++;
    elseif ($avgScore >= 50) $warningCount++;
    else $criticalCount++;
    
    // buckets for type chart
    $type = $comp['Type'];
    if (!isset($typeAgg[$type])) { $typeAgg[$type] = array('sum' => 0, 'count' => 0); }
    $typeAgg[$type]['sum'] += $avgScore;
    $typeAgg[$type]['count']++;
    
    // buckets for tier chart
    $tier = $comp['TierLevel'];
    if (!isset($tierAgg[$tier])) { $tierAgg[$tier] = array('sum' => 0, 'count' => 0); }
    $tierAgg[$tier]['sum'] += $avgScore;
    $tierAgg[$tier]['count']++;
}

$totalCompanies = count($finalCompanies);
$globalAvg = $totalCompanies > 0 ? round($globalSum / $totalCompanies, 1) : 0;

// preparing chart json data
$typeHealth = array();
foreach ($typeAgg as $type => $data) {
    $typeHealth[] = array('Type' => $type, 'avgHealthScore' => $data['sum'] / $data['count']);
}

$tierHealth = array();
ksort($tierAgg); 
foreach ($tierAgg as $tier => $data) {
    $tierHealth[] = array('TierLevel' => $tier, 'avgHealthScore' => $data['sum'] / $data['count']);
}

// --- AJAX RETURN ---
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    echo json_encode(array(
        'success' => true,
        'data' => array(
            'companies' => $finalCompanies,
            'summary' => array(
                'totalCompanies' => $totalCompanies, 
                'avgHealth' => $globalAvg, 
                'healthyCount' => $healthyCount, 
                'warningCount' => $warningCount, 
                'criticalCount' => $criticalCount
            ),
            'typeHealth' => $typeHealth,
            'tierHealth' => $tierHealth
        )
    ));
    exit;
}

// Fetch Dropdown Data
$allRegions = $pdo->query("SELECT DISTINCT ContinentName FROM Location ORDER BY ContinentName")->fetchAll();
$allCompanies = $pdo->query("SELECT CompanyID, CompanyName FROM Company ORDER BY CompanyName")->fetchAll();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Financial Health - ERP</title>
    <script src="../assets/forward_protection.js"></script>
    <link rel="stylesheet" href="../assets/styles.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .table-scroll-window {
            height: 500px;
            overflow-y: auto;
            overflow-x: hidden;
            border: 1px solid rgba(207, 185, 145, 0.2);
            border-radius: 8px;
            background: rgba(0, 0, 0, 0.3);
        }
        .table-scroll-window table {
            width: 100%;
            margin-top: 0;
            table-layout: auto; 
        }
        .table-scroll-window thead th {
            position: sticky;
            top: 0;
            z-index: 10;
            background: #1a1a1a; 
            border-bottom: 3px solid var(--purdue-gold);
            box-shadow: 0 2px 5px rgba(0,0,0,0.5);
            color: #ffffff;
            cursor: pointer; /* Clickable */
            user-select: none;
        }
        .table-scroll-window thead th:hover {
            background-color: #333;
        }
        .sort-indicator {
            margin-left: 5px;
            font-size: 0.8em;
            color: var(--purdue-gold);
        }
        .chart-wrapper.full-width {
            height: 400px;
        }
        .tier-col {
            min-width: 100px;
        }
        .filter-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 15px;
            align-items: end;
        }
        @media (max-width: 1200px) {
            .filter-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
    </style>
</head>
<body>
    <header>
        <div class="container">
            <h1>Enterprise Resource Planning Portal</h1>
            <nav>
                <span class="text-white">Welcome, <?= htmlspecialchars($_SESSION['FullName']) ?> (Senior Manager)</span>
                <a href="../logout.php" class="logout-btn">Logout</a>
            </nav>
        </div>
    </header>

    <nav class="container sub-nav">
        <a href="dashboard.php">Dashboard</a>
        <a href="companies.php">Company Financial Health</a>
        <a href="financial.php" class="active">Financial Health</a>
        <a href="critical_companies.php">Critical Companies</a>
        <a href="regional_disruptions.php">Regional Disruptions</a>
        <a href="timeline.php">Disruption Timeline</a>
        <a href="disruptions.php">Disruption Analysis</a>
        <a href="distributors.php">Distributors</a>
        <a href="add_company.php">Add Company</a>
    </nav>

    <div class="container">
        <h2>Financial Health Overview</h2>

        <div class="filter-section">
            <h3>Filter Companies</h3>
            <form id="filterForm">
                <div class="filter-grid">
                    <div class="filter-group">
                        <label>Company Name:</label>
                        <select id="company_id">
                            <option value="">All Companies</option>
                            <?php foreach ($allCompanies as $c): ?>
                                <option value="<?= $c['CompanyID'] ?>" <?= $companyId == $c['CompanyID'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($c['CompanyName']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label>Company Type:</label>
                        <select id="type">
                            <option value="">All Types</option>
                            <option value="Manufacturer" <?= $companyType == 'Manufacturer' ? 'selected' : '' ?>>Manufacturer</option>
                            <option value="Distributor" <?= $companyType == 'Distributor' ? 'selected' : '' ?>>Distributor</option>
                            <option value="Retailer" <?= $companyType == 'Retailer' ? 'selected' : '' ?>>Retailer</option>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label>Tier Level:</label>
                        <select id="tier">
                            <option value="">All Tiers</option>
                            <option value="1" <?= $tierLevel == '1' ? 'selected' : '' ?>>Tier 1</option>
                            <option value="2" <?= $tierLevel == '2' ? 'selected' : '' ?>>Tier 2</option>
                            <option value="3" <?= $tierLevel == '3' ? 'selected' : '' ?>>Tier 3</option>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label>Region:</label>
                        <select id="region">
                            <option value="">All Regions</option>
                            <?php foreach ($allRegions as $r): ?>
                                <option value="<?= $r['ContinentName'] ?>" <?= $region == $r['ContinentName'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($r['ContinentName']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label>Start Date:</label>
                        <input type="date" id="start_date" value="<?= htmlspecialchars($startDate) ?>">
                    </div>
                    
                    <div class="filter-group">
                        <label>End Date:</label>
                        <input type="date" id="end_date" value="<?= htmlspecialchars($endDate) ?>">
                    </div>

                    <div class="filter-group">
                        <label>Min Health Score:</label>
                        <input type="number" id="min_health" min="0" max="100" step="1" placeholder="0" value="<?= htmlspecialchars($minHealth) ?>">
                    </div>

                    <div class="filter-group">
                        <label>Max Health Score:</label>
                        <input type="number" id="max_health" min="0" max="100" step="1" placeholder="100" value="<?= htmlspecialchars($maxHealth) ?>">
                    </div>
                </div>
                
                <div class="flex gap-sm mt-sm" style="justify-content: flex-end;">
                    <button type="button" id="clearBtn" class="btn-reset">Reset Filters</button>
                </div>
            </form>
        </div>

        <div class="stats-grid">
            <div class="stat-card">
                <h3 id="stat-total"><?= $totalCompanies ?></h3>
                <p>Total Companies</p>
            </div>
            <div class="stat-card">
                <h3 id="stat-avg"><?= $globalAvg ?></h3>
                <p>Avg Health (Period)</p>
            </div>
            <div class="stat-card healthy">
                <h3 id="stat-healthy"><?= $healthyCount ?></h3>
                <p>Healthy (≥75)</p>
            </div>
            <div class="stat-card warning">
                <h3 id="stat-warning"><?= $warningCount ?></h3>
                <p>Warning (50-74)</p>
            </div>
            <div class="stat-card critical">
                <h3 id="stat-critical"><?= $criticalCount ?></h3>
                <p>Critical (&lt;50)</p>
            </div>
        </div>

        <div class="chart-container">
            <h3>Company Financial Health Ranking</h3>
            <div class="chart-wrapper full-width">
                <canvas id="companyChart"></canvas>
            </div>
        </div>

        <div class="charts-row">
            <div class="chart-container">
                <h3>Avg Health Score by Company Type</h3>
                <div class="chart-wrapper">
                    <canvas id="typeChart"></canvas>
                </div>
            </div>
            <div class="chart-container">
                <h3>Avg Health Score by Tier Level</h3>
                <div class="chart-wrapper">
                    <canvas id="tierChart"></canvas>
                </div>
            </div>
        </div>

        <div class="content-section">
            <h3>Companies Ranked by Avg Health (<span id="recordCount"><?= count($finalCompanies) ?></span> companies)</h3>
            <div id="tableWrapper" class="table-scroll-window">
                <table style="margin-top:0;">
                    <thead>
                        <tr>
                            <th onclick="sortTable(0)" data-sort="asc">Rank <span class="sort-indicator"></span></th>
                            <th onclick="sortTable(1)" data-sort="asc">Company <span class="sort-indicator"></span></th>
                            <th onclick="sortTable(2)" data-sort="asc">Type <span class="sort-indicator"></span></th>
                            <th onclick="sortTable(3)" data-sort="asc" class="tier-col">Tier <span class="sort-indicator"></span></th>
                            <th onclick="sortTable(4)" data-sort="asc">Region <span class="sort-indicator"></span></th>
                            <th onclick="sortTable(5)" data-sort="asc">Avg Score <span class="sort-indicator"></span></th>
                            <th onclick="sortTable(6)" data-sort="asc">Reports <span class="sort-indicator"></span></th>
                        </tr>
                    </thead>
                    <tbody id="tableBody">
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
    (function() {
        var typeChart = null; 
        var tierChart = null;
        var companyChart = null; 
        
        // We store the full list here so we can sort in JS without reloading
        var currentDataList = [];

        // restore filters
        if(sessionStorage.getItem('fin_comp')) document.getElementById('company_id').value = sessionStorage.getItem('fin_comp');
        if(sessionStorage.getItem('fin_type')) document.getElementById('type').value = sessionStorage.getItem('fin_type');
        if(sessionStorage.getItem('fin_region')) document.getElementById('region').value = sessionStorage.getItem('fin_region');
        if(sessionStorage.getItem('fin_tier')) document.getElementById('tier').value = sessionStorage.getItem('fin_tier');
        if(sessionStorage.getItem('fin_min')) document.getElementById('min_health').value = sessionStorage.getItem('fin_min');
        if(sessionStorage.getItem('fin_max')) document.getElementById('max_health').value = sessionStorage.getItem('fin_max');
        if(sessionStorage.getItem('fin_start')) document.getElementById('start_date').value = sessionStorage.getItem('fin_start');
        if(sessionStorage.getItem('fin_end')) document.getElementById('end_date').value = sessionStorage.getItem('fin_end');
        
        load(); 

        // attach listeners
        var inputs = document.querySelectorAll('#filterForm input, #filterForm select');
        inputs.forEach(function(input) {
            input.addEventListener('change', load);
        });

        document.getElementById('filterForm').addEventListener('submit', function(e) { e.preventDefault(); load(); return false; });

        function load() {
            var tableBody = document.getElementById('tableBody');
            if(tableBody) tableBody.parentElement.style.opacity = '0.5';
            
            // grab inputs
            var compId = document.getElementById('company_id').value;
            var typVal = document.getElementById('type').value;
            var rVal = document.getElementById('region').value;
            var tVal = document.getElementById('tier').value;
            var sDate = document.getElementById('start_date').value;
            var eDate = document.getElementById('end_date').value;
            var minVal = document.getElementById('min_health').value;
            var maxVal = document.getElementById('max_health').value;

            // save inputs
            sessionStorage.setItem('fin_comp', compId);
            sessionStorage.setItem('fin_type', typVal);
            sessionStorage.setItem('fin_region', rVal);
            sessionStorage.setItem('fin_tier', tVal);
            sessionStorage.setItem('fin_start', sDate);
            sessionStorage.setItem('fin_end', eDate);
            sessionStorage.setItem('fin_min', minVal);
            sessionStorage.setItem('fin_max', maxVal);

            var params = 'ajax=1' + 
                        '&company_id=' + encodeURIComponent(compId) +
                        '&type=' + encodeURIComponent(typVal) +
                        '&region=' + encodeURIComponent(rVal) +
                        '&tier=' + encodeURIComponent(tVal) +
                        '&start_date=' + encodeURIComponent(sDate) +
                        '&end_date=' + encodeURIComponent(eDate) +
                        '&min_health=' + encodeURIComponent(minVal) +
                        '&max_health=' + encodeURIComponent(maxVal);
            
            var xhr = new XMLHttpRequest();
            xhr.open('GET', 'financial.php?' + params, true);
            xhr.onload = function() {
                if (xhr.status === 200) {
                    try {
                        var r = JSON.parse(xhr.responseText);
                        if (r.success) {
                            var d = r.data; var s = d.summary;
                            
                            // Update Stats
                            document.getElementById('stat-total').textContent = s.totalCompanies;
                            document.getElementById('stat-avg').textContent = s.avgHealth;
                            document.getElementById('stat-healthy').textContent = s.healthyCount;
                            document.getElementById('stat-warning').textContent = s.warningCount;
                            document.getElementById('stat-critical').textContent = s.criticalCount;
                            
                            updateCharts(d);
                            
                            // Save data for sorting and build table
                            currentDataList = d.companies;
                            // Reset sort indicators when data reloads
                            resetSortIndicators();
                            buildTable(currentDataList);
                            
                            if(tableBody) tableBody.parentElement.style.opacity = '1';
                        } else {
                            if(tableBody) tableBody.innerHTML = '<tr><td colspan="7" class="error">Error: ' + r.message + '</td></tr>';
                        }
                    } catch(e) {
                        console.error("JSON Error:", e);
                    }
                }
            };
            xhr.send();
        }

        // --- SORTING FUNCTIONALITY ---
        window.sortTable = function(colIndex) {
            var headers = document.querySelectorAll('.table-scroll-window thead th');
            var currentTh = headers[colIndex];
            var currentSort = currentTh.getAttribute('data-sort');
            var newSort = (currentSort === 'asc') ? 'desc' : 'asc';
            
            // Clear other indicators
            headers.forEach(function(th) {
                th.querySelector('.sort-indicator').textContent = '';
                // Optional: reset their data-sort to 'asc' for next click? 
                // usually we just leave them.
            });
            
            // Set new indicator
            currentTh.setAttribute('data-sort', newSort);
            currentTh.querySelector('.sort-indicator').textContent = (newSort === 'asc') ? '▲' : '▼';
            
            // Perform Sort
            currentDataList.sort(function(a, b) {
                var valA, valB;
                
                // Map column index to data property
                switch(colIndex) {
                    case 0: // Rank - we don't really sort by rank, we sort by current index? 
                            // Actually rank is derived from index, so this might sort by original loaded order or ID?
                            // Let's sort by ID for stability
                            valA = parseInt(a.CompanyID); valB = parseInt(b.CompanyID); 
                            break;
                    case 1: // Company Name
                            valA = a.CompanyName.toLowerCase(); valB = b.CompanyName.toLowerCase(); 
                            break;
                    case 2: // Type
                            valA = a.Type.toLowerCase(); valB = b.Type.toLowerCase(); 
                            break;
                    case 3: // Tier
                            valA = parseInt(a.TierLevel); valB = parseInt(b.TierLevel); 
                            break;
                    case 4: // Region
                            valA = (a.ContinentName || '').toLowerCase(); valB = (b.ContinentName || '').toLowerCase(); 
                            break;
                    case 5: // Avg Score
                            valA = parseFloat(a.AvgHealthScore); valB = parseFloat(b.AvgHealthScore); 
                            break;
                    case 6: // Reports
                            valA = parseInt(a.ReportCount); valB = parseInt(b.ReportCount); 
                            break;
                }
                
                if(valA < valB) return (newSort === 'asc') ? -1 : 1;
                if(valA > valB) return (newSort === 'asc') ? 1 : -1;
                return 0;
            });
            
            buildTable(currentDataList);
        };
        
        function resetSortIndicators() {
            var headers = document.querySelectorAll('.table-scroll-window thead th');
            headers.forEach(function(th) {
                th.setAttribute('data-sort', 'asc');
                th.querySelector('.sort-indicator').textContent = '';
            });
        }

        function buildTable(companies) {
            document.getElementById('recordCount').textContent = companies.length;
            var tbody = document.getElementById('tableBody');
            
            if (companies.length === 0) { 
                tbody.innerHTML = '<tr><td colspan="7" class="no-data">No financial data found.</td></tr>'; 
                return; 
            }
            
            var html = '';
            for (var i = 0; i < companies.length; i++) {
                var c = companies[i];
                var score = parseFloat(c.AvgHealthScore);
                var badgeClass = 'health-bad';
                if (score >= 75) badgeClass = 'health-good';
                else if (score >= 50) badgeClass = 'health-warning';
                
                html += '<tr><td><strong>' + (i + 1) + '</strong></td>' +
                    '<td>' + esc(c.CompanyName) + '</td>' +
                    '<td>' + esc(c.Type) + '</td>' +
                    '<td>Tier ' + c.TierLevel + '</td>' +
                    '<td>' + esc(c.ContinentName) + '</td>' +
                    '<td><span class="health-badge ' + badgeClass + '">' + score.toFixed(1) + '</span></td>' +
                    '<td>' + c.ReportCount + '</td></tr>';
            }
            tbody.innerHTML = html;
        }

        function updateCharts(d) {
            // 1. Company Bar Chart
            var companyData = d.companies.slice(0, 20); // Top 20 currently in list
            var compLabels = []; 
            var compScores = [];
            var compColors = [];

            for(var i=0; i<companyData.length; i++) {
                compLabels.push(companyData[i].CompanyName);
                var sc = parseFloat(companyData[i].AvgHealthScore);
                compScores.push(sc);
                if (sc >= 75) compColors.push('#4caf50');
                else if (sc >= 50) compColors.push('#ff9800');
                else compColors.push('#f44336');
            }

            if (companyChart) companyChart.destroy();
            var ctx0 = document.getElementById('companyChart').getContext('2d');
            companyChart = new Chart(ctx0, {
                type: 'bar',
                data: {
                    labels: compLabels,
                    datasets: [{
                        label: 'Avg Health Score',
                        data: compScores,
                        backgroundColor: compColors,
                        borderWidth: 0
                    }]
                },
                options: {
                    indexAxis: 'y',
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: { 
                        x: { beginAtZero: true, max: 100, ticks: { color: 'white' }, grid: { color: 'rgba(207,185,145,0.1)' } }, 
                        y: { ticks: { color: 'white' }, grid: { display: false } } 
                    },
                    plugins: { legend: { display: false } }
                }
            });

            // 2. Type Chart
            var allTypes = ['Manufacturer', 'Distributor', 'Retailer'];
            var typeScores = [0, 0, 0];
            
            for (var i = 0; i < d.typeHealth.length; i++) {
                var dbType = d.typeHealth[i].Type;
                var score = parseFloat(d.typeHealth[i].avgHealthScore);
                var index = allTypes.indexOf(dbType);
                if (index !== -1) typeScores[index] = score;
            }
            
            if (typeChart) typeChart.destroy();
            var ctx1 = document.getElementById('typeChart').getContext('2d');
            typeChart = new Chart(ctx1, {
                type: 'bar',
                data: {
                    labels: allTypes,
                    datasets: [{
                        label: 'Avg Health Score',
                        data: typeScores,
                        backgroundColor: ['#4caf50', '#2196f3', '#ff9800'],
                        borderColor: 'transparent'
                    }]
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    scales: { y: { beginAtZero: true, max: 100, ticks: { color: 'white' }, grid: { color: 'rgba(207,185,145,0.1)' } }, x: { ticks: { color: 'white' }, grid: { color: 'rgba(207,185,145,0.1)' } } },
                    plugins: { legend: { display: false } }
                }
            });
            
            // 3. Tier Chart
            var tierLabels = []; var tierScores = [];
            for (var i = 0; i < d.tierHealth.length; i++) {
                tierLabels.push('Tier ' + d.tierHealth[i].TierLevel);
                tierScores.push(parseFloat(d.tierHealth[i].avgHealthScore));
            }
            
            if (tierChart) tierChart.destroy();
            var ctx2 = document.getElementById('tierChart').getContext('2d');
            tierChart = new Chart(ctx2, {
                type: 'bar',
                data: {
                    labels: tierLabels,
                    datasets: [{
                        label: 'Avg Health Score',
                        data: tierScores,
                        backgroundColor: ['#CFB991', '#d4c49e', '#e0d7b8']
                    }]
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    scales: { y: { beginAtZero: true, max: 100, ticks: { color: 'white' }, grid: { color: 'rgba(207,185,145,0.1)' } }, x: { ticks: { color: 'white' }, grid: { color: 'rgba(207,185,145,0.1)' } } },
                    plugins: { legend: { display: false } }
                }
            });
        }
        
        function esc(t) { if (!t) return ''; var d = document.createElement('div'); d.textContent = t; return d.innerHTML; }
        
        document.getElementById('clearBtn').addEventListener('click', function() {
            sessionStorage.removeItem('fin_comp');
            sessionStorage.removeItem('fin_type');
            sessionStorage.removeItem('fin_region');
            sessionStorage.removeItem('fin_tier');
            sessionStorage.removeItem('fin_min');
            sessionStorage.removeItem('fin_max');
            sessionStorage.removeItem('fin_start');
            sessionStorage.removeItem('fin_end');

            document.getElementById('company_id').value = '';
            document.getElementById('type').value = '';
            document.getElementById('region').value = '';
            document.getElementById('tier').value = '';
            document.getElementById('min_health').value = '';
            document.getElementById('max_health').value = '';
            document.getElementById('start_date').value = '<?= date('Y-m-d', strtotime('-5 years')) ?>';
            document.getElementById('end_date').value = '<?= date('Y-m-d') ?>';
            load();
        });
    })();
    </script>
</body>
</html>