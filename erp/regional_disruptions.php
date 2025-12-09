<?php
// erp/regional_disruptions.php - Regional Disruption Overview
// viewing where all the problems are happening geographically

require_once '../config.php';
requireLogin();

// security check
if (!hasRole('SeniorManager')) {
    header('Location: ../scm/dashboard.php');
    exit;
}

$pdo = getPDO();

// --- 1. GET FILTER VALUES ---
// getting these from url or setting defaults
$startDate = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d', strtotime('-1 year'));
$endDate = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');
$impactLevel = isset($_GET['impact']) ? $_GET['impact'] : '';
$regionFilter = isset($_GET['region']) ? $_GET['region'] : '';

$params = array(
    ':start' => $startDate, 
    ':end' => $endDate
);

// --- 2. MAIN SQL QUERY ---
// counting up all the disruptions and breaking them down by severity
// doing this in sql so we don't have to loop in php
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

// adding filters if the user selected them
if (!empty($impactLevel)) {
    $sql .= " AND ic.ImpactLevel = :impact";
    $params[':impact'] = $impactLevel;
}

if (!empty($regionFilter)) {
    $sql .= " AND l.ContinentName = :region";
    $params[':region'] = $regionFilter;
}

// grouping by region so we get one row per continent
$sql .= " GROUP BY l.ContinentName ORDER BY totalDisruptions DESC";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $regions = $stmt->fetchAll();
} catch (PDOException $e) {
    if (isset($_GET['ajax'])) {
        die(json_encode(array('success' => false, 'message' => $e->getMessage())));
    }
}

// --- 3. CALCULATE SUMMARY STATS ---
// looping through results to get the top level numbers
$totalDisruptions = 0; 
$totalHighImpact = 0; 
$totalCompaniesAffected = 0; 
$maxRegionDisruptions = 0; 
$maxRegionName = '';

foreach ($regions as $r) {
    $totalDisruptions += intval($r['totalDisruptions']);
    $totalHighImpact += intval($r['highImpactCount']);
    $totalCompaniesAffected += intval($r['companiesAffected']);
    
    // checking if this is the worst region so far
    if (intval($r['totalDisruptions']) > $maxRegionDisruptions) {
        $maxRegionDisruptions = intval($r['totalDisruptions']);
        $maxRegionName = $r['region'];
    }
}

// --- 4. CATEGORY BREAKDOWN ---
// this query gets the specific types of problems (cyber attack, weather, etc) per region
// i'm doing a separate query here to keep things cleaner
$categorySql = "SELECT l.ContinentName as region, dc.CategoryName, COUNT(DISTINCT de.EventID) as eventCount
                FROM DisruptionEvent de
                JOIN DisruptionCategory dc ON de.CategoryID = dc.CategoryID
                JOIN ImpactsCompany ic ON de.EventID = ic.EventID
                JOIN Company c ON ic.AffectedCompanyID = c.CompanyID
                JOIN Location l ON c.LocationID = l.LocationID
                WHERE de.EventDate BETWEEN :start AND :end";

// resetting params for this second query
$catParams = array(
    ':start' => $startDate, 
    ':end' => $endDate
);

if (!empty($impactLevel)) {
    $categorySql .= " AND ic.ImpactLevel = :impact";
    $catParams[':impact'] = $impactLevel;
}

if (!empty($regionFilter)) {
    $categorySql .= " AND l.ContinentName = :region";
    $catParams[':region'] = $regionFilter;
}

$categorySql .= " GROUP BY l.ContinentName, dc.CategoryName
                  ORDER BY l.ContinentName, eventCount DESC";

$stmt2 = $pdo->prepare($categorySql);
$stmt2->execute($catParams);
$categoryBreakdown = $stmt2->fetchAll();

// reorganizing the array so it's easier to use in javascript later
$categoryByRegion = array();
foreach ($categoryBreakdown as $cat) {
    $reg = $cat['region'];
    if (!isset($categoryByRegion[$reg])) {
        $categoryByRegion[$reg] = array();
    }
    $categoryByRegion[$reg][] = $cat;
}

// --- 5. AJAX HANDLER ---
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    echo json_encode(array(
        'success' => true,
        'data' => array(
            'regions' => $regions,
            'summary' => array(
                'totalDisruptions' => $totalDisruptions, 
                'totalHighImpact' => $totalHighImpact,
                'totalCompaniesAffected' => $totalCompaniesAffected, 
                'maxRegionName' => $maxRegionName,
                'maxRegionDisruptions' => $maxRegionDisruptions
            ),
            'categoryByRegion' => $categoryByRegion
        )
    ));
    exit;
}

// getting list for the region dropdown
$allRegions = $pdo->query("SELECT DISTINCT ContinentName FROM Location ORDER BY ContinentName")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Regional Disruptions - ERP</title>
    <script src="../assets/forward_protection.js"></script>
    <link rel="stylesheet" href="../assets/styles.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        /* consistent scroll window */
        .card-scroll-window {
            height: 600px;
            overflow-y: auto;
            overflow-x: hidden;
            border: 1px solid rgba(207, 185, 145, 0.2);
            border-radius: 8px;
            background: rgba(0, 0, 0, 0.3);
            padding: 20px; 
        }
        /* dynamic sizing for big numbers so they don't overflow */
        .stat-card h3 {
            font-size: clamp(1.5rem, 4vw, 3rem);
            word-break: break-word;
            line-height: 1.1;
        }
        /* 4 column grid for filters */
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
            <nav style="display: flex; align-items: center; gap: 15px;">
                <span style="color: white;">Welcome, <?= htmlspecialchars($_SESSION['FullName']) ?> (Senior Manager)</span>
                <a href="../logout.php" class="logout-btn">Logout</a>
            </nav>
        </div>
    </header>

    <nav class="container sub-nav">
        <a href="dashboard.php">Dashboard</a>
        <a href="financial.php">Financial Health</a>
        <a href="regional_disruptions.php" class="active">Regional Disruptions</a>
        <a href="critical_companies.php">Critical Companies</a>
        <a href="timeline.php">Disruption Timeline</a>
        <a href="companies.php">Company List</a>
        <a href="distributors.php">Distributors</a>
        <a href="disruptions.php">Disruption Analysis</a>
    </nav>

    <div class="container">
        <h2>Regional Disruption Overview</h2>

        <div class="filter-section">
            <h3>Filter Data</h3>
            <form id="filterForm" onsubmit="return false;">
                <div class="filter-grid">
                    <div class="filter-group">
                        <label>Start Date:</label>
                        <input type="date" id="start_date" value="<?= htmlspecialchars($startDate) ?>">
                    </div>
                    <div class="filter-group">
                        <label>End Date:</label>
                        <input type="date" id="end_date" value="<?= htmlspecialchars($endDate) ?>">
                    </div>
                    <div class="filter-group">
                        <label>Region:</label>
                        <select id="region">
                            <option value="">All Regions</option>
                            <?php foreach ($allRegions as $r): ?>
                                <option value="<?= $r['ContinentName'] ?>" <?= $regionFilter == $r['ContinentName'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($r['ContinentName']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label>Impact Level:</label>
                        <select id="impact">
                            <option value="">All Levels</option>
                            <option value="High" <?= $impactLevel == 'High' ? 'selected' : '' ?>>High Impact</option>
                            <option value="Medium" <?= $impactLevel == 'Medium' ? 'selected' : '' ?>>Medium Impact</option>
                            <option value="Low" <?= $impactLevel == 'Low' ? 'selected' : '' ?>>Low Impact</option>
                        </select>
                    </div>
                </div>
                <div class="flex gap-sm mt-sm" style="justify-content: flex-end;">
                    <button type="button" id="clearBtn" class="btn-reset">Reset Filters</button>
                </div>
            </form>
        </div>

        <div class="stats-grid">
            <div class="stat-card">
                <h3 id="stat-total"><?= $totalDisruptions ?></h3>
                <p>Total Disruptions</p>
                <small>In selected filters</small>
            </div>
            <div class="stat-card critical">
                <h3 id="stat-high"><?= $totalHighImpact ?></h3>
                <p>High Impact Events</p>
                <small>Critical disruptions</small>
            </div>
            <div class="stat-card">
                <h3 id="stat-companies"><?= $totalCompaniesAffected ?></h3>
                <p>Companies Affected</p>
                <small>Unique companies</small>
            </div>
            <div class="stat-card peak">
                <h3 id="stat-max"><?= htmlspecialchars($maxRegionName ?: 'N/A') ?></h3>
                <p>Highest Risk Region</p>
                <small id="stat-max-count"><?= $maxRegionDisruptions ?> disruptions</small>
            </div>
        </div>

        <div class="chart-container">
            <h3>Disruptions by Region (Stacked by Impact Level)</h3>
            <div class="chart-wrapper">
                <canvas id="regionalChart"></canvas>
            </div>
        </div>

        <h3>Regional Breakdown</h3>
        <div id="regionCards" class="card-scroll-window">
            <?php foreach ($regions as $region): ?>
            <div class="region-card">
                <h4><?= htmlspecialchars($region['region']) ?> - <?= $region['totalDisruptions'] ?> Total Disruptions</h4>
                
                <div class="impact-row">
                    <div class="impact-box impact-high">
                        <strong><?= $region['highImpactCount'] ?></strong><br>
                        <small>High Impact</small>
                    </div>
                    <div class="impact-box impact-medium">
                        <strong><?= $region['mediumImpactCount'] ?></strong><br>
                        <small>Medium Impact</small>
                    </div>
                    <div class="impact-box impact-low">
                        <strong><?= $region['lowImpactCount'] ?></strong><br>
                        <small>Low Impact</small>
                    </div>
                </div>
                
                <p class="text-light mt-sm">
                    <strong>Companies Affected:</strong> <?= $region['companiesAffected'] ?>
                </p>
                
                <?php if (isset($categoryByRegion[$region['region']])): ?>
                <details class="mt-sm">
                    <summary class="text-gold" style="cursor:pointer; font-weight:bold;">View Category Breakdown</summary>
                    <ul class="text-light mt-xs">
                        <?php foreach ($categoryByRegion[$region['region']] as $cat): ?>
                        <li><?= htmlspecialchars($cat['CategoryName']) ?>: <?= $cat['eventCount'] ?> events</li>
                        <?php endforeach; ?>
                    </ul>
                </details>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        var regionalChart = null;
        var timeout = null;
        
        // checking local storage to restore filters
        if(sessionStorage.getItem('reg_start')) document.getElementById('start_date').value = sessionStorage.getItem('reg_start');
        if(sessionStorage.getItem('reg_end')) document.getElementById('end_date').value = sessionStorage.getItem('reg_end');
        if(sessionStorage.getItem('reg_region')) document.getElementById('region').value = sessionStorage.getItem('reg_region');
        if(sessionStorage.getItem('reg_impact')) document.getElementById('impact').value = sessionStorage.getItem('reg_impact');

        // Initial Load
        initChart(<?= json_encode($regions) ?>);

        // Adding listeners to all inputs
        var inputs = document.querySelectorAll('#filterForm input, #filterForm select');
        for(var i=0; i<inputs.length; i++) {
            inputs[i].addEventListener('change', load);
        }
        
        document.getElementById('clearBtn').addEventListener('click', function() {
            // clear the memory so filters reset
            sessionStorage.removeItem('reg_start');
            sessionStorage.removeItem('reg_end');
            sessionStorage.removeItem('reg_region');
            sessionStorage.removeItem('reg_impact');

            document.getElementById('start_date').value = '<?= date('Y-m-d', strtotime('-1 year')) ?>';
            document.getElementById('end_date').value = '<?= date('Y-m-d') ?>';
            document.getElementById('impact').value = '';
            document.getElementById('region').value = '';
            load();
        });
        
        function initChart(regionData) {
            var labels = []; 
            var highData = []; 
            var mediumData = []; 
            var lowData = [];
            
            // breaking down data for chartjs arrays
            for (var i = 0; i < regionData.length; i++) {
                labels.push(regionData[i].region);
                highData.push(parseInt(regionData[i].highImpactCount));
                mediumData.push(parseInt(regionData[i].mediumImpactCount));
                lowData.push(parseInt(regionData[i].lowImpactCount));
            }
            
            if (regionalChart) regionalChart.destroy();
            var ctx = document.getElementById('regionalChart').getContext('2d');
            regionalChart = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: [
                        { label: 'High Impact', data: highData, backgroundColor: '#f44336' }, 
                        { label: 'Medium Impact', data: mediumData, backgroundColor: '#ff9800' }, 
                        { label: 'Low Impact', data: lowData, backgroundColor: '#4caf50' }
                    ]
                },
                options: {
                    responsive: true, 
                    maintainAspectRatio: false,
                    scales: {
                        x: { stacked: true, ticks: { color: 'white' }, grid: { color: 'rgba(207,185,145,0.1)' } },
                        y: { stacked: true, beginAtZero: true, ticks: { color: 'white' }, grid: { color: 'rgba(207,185,145,0.1)' } }
                    },
                    plugins: { legend: { labels: { color: 'white' } } }
                }
            });
        }
        
        function load() {
            // fade out cards to show activity
            document.getElementById('regionCards').style.opacity = '0.5';
            
            // grab inputs
            var sDate = document.getElementById('start_date').value;
            var eDate = document.getElementById('end_date').value;
            var reg = document.getElementById('region').value;
            var imp = document.getElementById('impact').value;

            // save session
            sessionStorage.setItem('reg_start', sDate);
            sessionStorage.setItem('reg_end', eDate);
            sessionStorage.setItem('reg_region', reg);
            sessionStorage.setItem('reg_impact', imp);

            var params = 'ajax=1&start_date=' + encodeURIComponent(sDate) +
                        '&end_date=' + encodeURIComponent(eDate) +
                        '&region=' + encodeURIComponent(reg) +
                        '&impact=' + encodeURIComponent(imp);
            
            var xhr = new XMLHttpRequest();
            xhr.open('GET', 'regional_disruptions.php?' + params, true);
            xhr.onload = function() {
                if (xhr.status === 200) {
                    try {
                        // CRITICAL: The Vaccination Check
                        if (xhr.responseText.trim().indexOf('{') !== 0) {
                            console.error("Received HTML instead of JSON. Session likely expired.");
                            // alert("Session timed out."); 
                            return;
                        }

                        var r = JSON.parse(xhr.responseText);
                        if (r.success) {
                            var d = r.data; var s = d.summary;
                            
                            // update stats
                            document.getElementById('stat-total').textContent = s.totalDisruptions;
                            document.getElementById('stat-high').textContent = s.totalHighImpact;
                            document.getElementById('stat-companies').textContent = s.totalCompaniesAffected;
                            document.getElementById('stat-max').textContent = s.maxRegionName || 'N/A';
                            document.getElementById('stat-max-count').textContent = s.maxRegionDisruptions + ' disruptions';
                            
                            // update chart
                            initChart(d.regions);
                            
                            // rebuild cards
                            buildCards(d.regions, d.categoryByRegion);
                            document.getElementById('regionCards').style.opacity = '1';
                        }
                    } catch(e) {
                        console.error("JSON Error", e);
                    }
                }
            };
            xhr.send();
        }
        
        function buildCards(regions, categoryByRegion) {
            if (regions.length === 0) {
                document.getElementById('regionCards').innerHTML = '<p class="no-data">No regional data found.</p>';
                return;
            }
            var html = '';
            for (var i = 0; i < regions.length; i++) {
                var reg = regions[i];
                html += '<div class="region-card">' +
                    '<h4>' + esc(reg.region) + ' - ' + reg.totalDisruptions + ' Total Disruptions</h4>' +
                    '<div class="impact-row">' +
                    '<div class="impact-box impact-high"><strong>' + reg.highImpactCount + '</strong><br><small>High Impact</small></div>' +
                    '<div class="impact-box impact-medium"><strong>' + reg.mediumImpactCount + '</strong><br><small>Medium Impact</small></div>' +
                    '<div class="impact-box impact-low"><strong>' + reg.lowImpactCount + '</strong><br><small>Low Impact</small></div>' +
                    '</div>' +
                    '<p class="text-light mt-sm"><strong>Companies Affected:</strong> ' + reg.companiesAffected + '</p>';
                
                // add dropdown for categories if data exists
                if (categoryByRegion[reg.region]) {
                    html += '<details class="mt-sm"><summary class="text-gold" style="cursor:pointer;font-weight:bold">View Category Breakdown</summary><ul class="text-light mt-xs">';
                    for (var j = 0; j < categoryByRegion[reg.region].length; j++) {
                        var cat = categoryByRegion[reg.region][j];
                        html += '<li>' + esc(cat.CategoryName) + ': ' + cat.eventCount + ' events</li>';
                    }
                    html += '</ul></details>';
                }
                html += '</div>';
            }
            document.getElementById('regionCards').innerHTML = html;
        }
        
        function esc(t) { if (!t) return ''; var d = document.createElement('div'); d.textContent = t; return d.innerHTML; }
    });
    </script>
</body>
</html>