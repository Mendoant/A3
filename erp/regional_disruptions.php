<?php
// regional_disruptions.php - Regional Disruption Overview
require_once '../config.php';
requireLogin();

if (!hasRole('SeniorManager')) {
    header('Location: ../scm/dashboard.php');
    exit;
}

$pdo = getPDO();

// --- 1. GET FILTER VALUES ---
$startDate = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d', strtotime('-1 year'));
$endDate = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');
$impactLevel = isset($_GET['impact']) ? $_GET['impact'] : '';
$regionFilter = isset($_GET['region']) ? $_GET['region'] : '';

$params = array(':start' => $startDate, ':end' => $endDate);

// --- 2. MAIN SQL QUERY ---
$sql = "SELECT l.ContinentName as region,
            COUNT(DISTINCT de.EventID) as totalDisruptions,
            SUM(CASE WHEN ic.ImpactLevel = 'High' THEN 1 ELSE 0 END) as highImpactCount,
            SUM(CASE WHEN ic.ImpactLevel = 'Medium' THEN 1 ELSE 0 END) as mediumImpactCount,
            SUM(CASE WHEN ic.ImpactLevel = 'Low' THEN 1 ELSE 0 END) as lowImpactCount,
            COUNT(DISTINCT ic.AffectedCompanyID) as companiesAffected
        FROM DisruptionEvent de
        JOIN ImpactsCompany ic ON de.EventID = ic.EventID
        JOIN Company c ON ic.AffectedCompanyID = c.CompanyID
        JOIN Location l ON c.LocationID = l.LocationID
        WHERE de.EventDate BETWEEN :start AND :end";

if (!empty($impactLevel)) {
    $sql .= " AND ic.ImpactLevel = :impact";
    $params[':impact'] = $impactLevel;
}
if (!empty($regionFilter)) {
    $sql .= " AND l.ContinentName = :region";
    $params[':region'] = $regionFilter;
}
$sql .= " GROUP BY l.ContinentName ORDER BY totalDisruptions DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$regions = $stmt->fetchAll();

// --- 3. CATEGORY DATA FOR NEW CHART ---
// Aggregating categories per region
$catParams = $params; // Copy params from above
$categorySql = "SELECT l.ContinentName as region, dc.CategoryName, COUNT(DISTINCT de.EventID) as eventCount
                FROM DisruptionEvent de
                JOIN DisruptionCategory dc ON de.CategoryID = dc.CategoryID
                JOIN ImpactsCompany ic ON de.EventID = ic.EventID
                JOIN Company c ON ic.AffectedCompanyID = c.CompanyID
                JOIN Location l ON c.LocationID = l.LocationID
                WHERE de.EventDate BETWEEN :start AND :end";

if (!empty($impactLevel)) {
    $categorySql .= " AND ic.ImpactLevel = :impact";
}
if (!empty($regionFilter)) {
    $categorySql .= " AND l.ContinentName = :region";
}
$categorySql .= " GROUP BY l.ContinentName, dc.CategoryName ORDER BY eventCount DESC";

$stmtCat = $pdo->prepare($categorySql);
$stmtCat->execute($catParams);
$rawCategories = $stmtCat->fetchAll();

// Organize categories by region for JS
$catsByRegion = array();
foreach($rawCategories as $rc) {
    $r = $rc['region'];
    if(!isset($catsByRegion[$r])) $catsByRegion[$r] = array();
    $catsByRegion[$r][] = array('label' => $rc['CategoryName'], 'val' => intval($rc['eventCount']));
}

// Stats Calculation
$totalDisruptions = 0;
$totalHighImpact = 0;
$maxRegionDisruptions = 0;
$maxRegionName = '';

foreach ($regions as $r) {
    $totalDisruptions += intval($r['totalDisruptions']);
    $totalHighImpact += intval($r['highImpactCount']);
    if (intval($r['totalDisruptions']) > $maxRegionDisruptions) {
        $maxRegionDisruptions = intval($r['totalDisruptions']);
        $maxRegionName = $r['region'];
    }
}

// AJAX Handler
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    echo json_encode(array(
        'success' => true,
        'data' => array(
            'regions' => $regions,
            'catsByRegion' => $catsByRegion,
            'summary' => array(
                'totalDisruptions' => $totalDisruptions,
                'totalHighImpact' => $totalHighImpact,
                'maxRegionName' => $maxRegionName
            )
        )
    ));
    exit;
}

$allRegions = $pdo->query("SELECT DISTINCT ContinentName FROM Location ORDER BY ContinentName")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Regional Disruptions</title>
    <script src="../assets/forward_protection.js"></script>
    <link rel="stylesheet" href="../assets/styles.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .card-scroll-window {
            height: 600px;
            overflow-y: auto;
            border: 1px solid rgba(207, 185, 145, 0.2);
            border-radius: 8px;
            background: rgba(0, 0, 0, 0.3);
            padding: 20px;
        }
        .filter-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 15px;
            align-items: end;
        }
        .toggle-container {
            margin-top: 15px;
            text-align: right;
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
        <a href="financial.php">Financial Health</a>
        <a href="critical_companies.php">Critical Companies</a>
        <a href="regional_disruptions.php" class="active">Regional Disruptions</a>
        <a href="timeline.php">Disruption Timeline</a>
        <a href="disruptions.php">Disruption Analysis</a>
        <a href="distributors.php">Distributors</a>
        <a href="add_company.php">Add Company</a>
    </nav>

    <div class="container">
        <h2>Regional Disruption Overview</h2>

        <div class="filter-section">
            <form id="filterForm" onsubmit="return false;">
                <div class="filter-grid">
                    <div class="filter-group">
                        <label>Start Date:</label>
                        <input type="date" id="start_date" value="<?= $startDate ?>">
                    </div>
                    <div class="filter-group">
                        <label>End Date:</label>
                        <input type="date" id="end_date" value="<?= $endDate ?>">
                    </div>
                    <div class="filter-group">
                        <label>Region:</label>
                        <select id="region">
                            <option value="">All Regions</option>
                            <?php foreach ($allRegions as $r): ?>
                                <option value="<?= $r['ContinentName'] ?>"><?= $r['ContinentName'] ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label>Impact Level:</label>
                        <select id="impact">
                            <option value="">All Levels</option>
                            <option value="High">High Impact</option>
                            <option value="Medium">Medium Impact</option>
                            <option value="Low">Low Impact</option>
                        </select>
                    </div>
                </div>
                <div class="flex gap-sm mt-sm" style="justify-content: flex-end;">
                    <button type="button" id="resetBtn" class="btn-reset">Reset Filters</button>
                </div>
            </form>
        </div>

        <div class="chart-container">
            <h3>Disruptions by Region</h3>
            <div class="toggle-container">
                <label style="color:white; font-weight:bold;">
                    <input type="checkbox" id="toggleHigh" checked onchange="toggleHighImpact()"> Show High Impact
                </label>
                <label style="color:white; font-weight:bold; margin-left:15px;">
                    <input type="checkbox" id="toggleMed" checked onchange="toggleHighImpact()"> Show Medium Impact
                </label>
                <label style="color:white; font-weight:bold; margin-left:15px;">
                    <input type="checkbox" id="toggleLow" checked onchange="toggleHighImpact()"> Show Low Impact
                </label>
            </div>
            <div class="chart-wrapper">
                <canvas id="regionalChart"></canvas>
            </div>
        </div>

        <h3>Regional Details & Frequency Analysis</h3>
        <div id="regionCards" class="card-scroll-window">
            </div>
    </div>

    <script>
    var regionalChart = null;
    var regionCharts = {}; 

    document.addEventListener('DOMContentLoaded', function() {
        // Restore State
        if(sessionStorage.getItem('reg_start')) document.getElementById('start_date').value = sessionStorage.getItem('reg_start');
        if(sessionStorage.getItem('reg_end')) document.getElementById('end_date').value = sessionStorage.getItem('reg_end');
        if(sessionStorage.getItem('reg_region')) document.getElementById('region').value = sessionStorage.getItem('reg_region');
        if(sessionStorage.getItem('reg_impact')) document.getElementById('impact').value = sessionStorage.getItem('reg_impact');

        load();
        
        var inputs = document.querySelectorAll('#filterForm input, #filterForm select');
        for(var i=0; i<inputs.length; i++) inputs[i].addEventListener('change', load);

        // Reset Button Listener
        document.getElementById('resetBtn').addEventListener('click', function() {
            sessionStorage.removeItem('reg_start');
            sessionStorage.removeItem('reg_end');
            sessionStorage.removeItem('reg_region');
            sessionStorage.removeItem('reg_impact');

            // Set inputs back to PHP defaults
            document.getElementById('start_date').value = '<?= date('Y-m-d', strtotime('-1 year')) ?>';
            document.getElementById('end_date').value = '<?= date('Y-m-d') ?>';
            document.getElementById('region').value = '';
            document.getElementById('impact').value = '';
            
            load();
        });
    });

    window.toggleHighImpact = function() {
        if(!regionalChart) return;
        var showHigh = document.getElementById('toggleHigh').checked;
        var showMed = document.getElementById('toggleMed').checked;
        var showLow = document.getElementById('toggleLow').checked;

        // Dataset 0 = High, 1 = Med, 2 = Low
        regionalChart.setDatasetVisibility(0, showHigh);
        regionalChart.setDatasetVisibility(1, showMed);
        regionalChart.setDatasetVisibility(2, showLow);
        regionalChart.update();
    };

    function load() {
        var sDate = document.getElementById('start_date').value;
        var eDate = document.getElementById('end_date').value;
        var reg = document.getElementById('region').value;
        var imp = document.getElementById('impact').value;

        sessionStorage.setItem('reg_start', sDate);
        sessionStorage.setItem('reg_end', eDate);
        sessionStorage.setItem('reg_region', reg);
        sessionStorage.setItem('reg_impact', imp);

        var params = 'ajax=1&start_date=' + sDate + '&end_date=' + eDate + '&region=' + reg + '&impact=' + imp;

        var xhr = new XMLHttpRequest();
        xhr.open('GET', 'regional_disruptions.php?' + params, true);
        xhr.onload = function() {
            if (xhr.status === 200) {
                var r = JSON.parse(xhr.responseText);
                if (r.success) {
                    initMainChart(r.data.regions);
                    buildCards(r.data.regions, r.data.catsByRegion);
                }
            }
        };
        xhr.send();
    }

    function initMainChart(data) {
        var labels = [], high = [], med = [], low = [];
        for(var i=0; i<data.length; i++) {
            labels.push(data[i].region);
            high.push(data[i].highImpactCount);
            med.push(data[i].mediumImpactCount);
            low.push(data[i].lowImpactCount);
        }

        if(regionalChart) regionalChart.destroy();
        var ctx = document.getElementById('regionalChart').getContext('2d');
        regionalChart = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [
                    { label: 'High Impact', data: high, backgroundColor: '#f44336' },
                    { label: 'Medium Impact', data: med, backgroundColor: '#ff9800' },
                    { label: 'Low Impact', data: low, backgroundColor: '#4caf50' }
                ]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                scales: { 
                    x: { stacked: true, ticks: { color: 'white' } }, 
                    y: { stacked: true, ticks: { color: 'white' } } 
                },
                plugins: { legend: { labels: { color: 'white' } } }
            }
        });
        toggleHighImpact();
    }

    function buildCards(regions, cats) {
        var container = document.getElementById('regionCards');
        container.innerHTML = '';
        
        regions.forEach(function(r) {
            var card = document.createElement('div');
            card.className = 'region-card';
            card.style.marginBottom = '20px';
            
            var html = '<h4>' + r.region + ' - ' + r.totalDisruptions + ' Events</h4>';
            html += '<div class="grid-2" style="display:grid; grid-template-columns: 1fr 1fr; gap:20px;">';
            
            // Left Col: Stats
            html += '<div><p>Companies Affected: ' + r.companiesAffected + '</p>' +
                    '<div class="impact-row">' +
                    '<div class="impact-box impact-high">' + r.highImpactCount + '<br><small>High</small></div>' +
                    '<div class="impact-box impact-medium">' + r.mediumImpactCount + '<br><small>Med</small></div>' +
                    '<div class="impact-box impact-low">' + r.lowImpactCount + '<br><small>Low</small></div>' +
                    '</div></div>';
            
            // Right Col: Chart Canvas
            html += '<div><div class="chart-wrapper" style="height:150px;"><canvas id="chart-' + r.region.replace(/\s/g,'') + '"></canvas></div></div>';
            html += '</div>';
            
            card.innerHTML = html;
            container.appendChild(card);
            
            // Render Mini Chart for Categories
            if (cats[r.region] && cats[r.region].length > 0) {
                setTimeout(function() {
                    var ctxId = 'chart-' + r.region.replace(/\s/g,'');
                    var ctx = document.getElementById(ctxId).getContext('2d');
                    var cLabels = [], cData = [];
                    
                    cats[r.region].forEach(function(c) {
                        cLabels.push(c.label);
                        cData.push(c.val);
                    });
                    
                    new Chart(ctx, {
                        type: 'bar',
                        data: {
                            labels: cLabels,
                            datasets: [{
                                label: 'Frequency',
                                data: cData,
                                backgroundColor: '#CFB991'
                            }]
                        },
                        options: {
                            indexAxis: 'y', // Horizontal Bar
                            responsive: true, maintainAspectRatio: false,
                            plugins: { legend: { display: false } },
                            scales: {
                                x: { ticks: { color: 'white' } },
                                y: { ticks: { color: 'white' } }
                            }
                        }
                    });
                }, 0);
            }
        });
    }
    </script>
</body>
</html>