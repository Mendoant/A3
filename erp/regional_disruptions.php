<?php
// erp/regional_disruptions.php - Regional Disruption Overview with AJAX
require_once '../config.php';
requireLogin();

// only senior managers can access erp module
if (!hasRole('SeniorManager')) {
    header('Location: ../scm/dashboard.php');
    exit;
}

$pdo = getPDO();

// Get filters
$startDate = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d', strtotime('-1 year'));
$endDate = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');
$impactLevel = isset($_GET['impact']) ? $_GET['impact'] : '';

$params = array(':start' => $startDate, ':end' => $endDate);

// Query: Total disruptions by region
$sql = "SELECT 
            l.ContinentName as region,
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

// add impact filter if selected
if (!empty($impactLevel)) {
    $sql .= " AND ic.ImpactLevel = :impact";
    $params[':impact'] = $impactLevel;
}

$sql .= " GROUP BY l.ContinentName
          ORDER BY totalDisruptions DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$regions = $stmt->fetchAll();

// Calculate summary stats
$totalDisruptions = 0;
$totalHighImpact = 0;
$totalCompaniesAffected = 0;
$maxRegionDisruptions = 0;
$maxRegionName = '';

foreach ($regions as $r) {
    $totalDisruptions += intval($r['totalDisruptions']);
    $totalHighImpact += intval($r['highImpactCount']);
    $totalCompaniesAffected += intval($r['companiesAffected']);
    
    if (intval($r['totalDisruptions']) > $maxRegionDisruptions) {
        $maxRegionDisruptions = intval($r['totalDisruptions']);
        $maxRegionName = $r['region'];
    }
}

// Disruption categories by region (for detailed breakdown)
$categorySql = "SELECT 
                    l.ContinentName as region,
                    dc.CategoryName,
                    COUNT(DISTINCT de.EventID) as eventCount
                FROM DisruptionEvent de
                JOIN DisruptionCategory dc ON de.CategoryID = dc.CategoryID
                JOIN ImpactsCompany ic ON de.EventID = ic.EventID
                JOIN Company c ON ic.AffectedCompanyID = c.CompanyID
                JOIN Location l ON c.LocationID = l.LocationID
                WHERE de.EventDate BETWEEN :start AND :end
                GROUP BY l.ContinentName, dc.CategoryName
                ORDER BY l.ContinentName, eventCount DESC";

$stmt2 = $pdo->prepare($categorySql);
$stmt2->execute(array(':start' => $startDate, ':end' => $endDate));
$categoryBreakdown = $stmt2->fetchAll();

// organize by region for easier processing
$categoryByRegion = array();
foreach ($categoryBreakdown as $cat) {
    $region = $cat['region'];
    if (!isset($categoryByRegion[$region])) {
        $categoryByRegion[$region] = array();
    }
    $categoryByRegion[$region][] = $cat;
}

// AJAX response
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Regional Disruptions - ERP</title>
    <link rel="stylesheet" href="../assets/styles.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .filter-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-top: 20px; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 20px; margin: 30px 0; }
        .stat-card { background: rgba(0,0,0,0.6); padding: 24px; border-radius: 8px; border: 2px solid rgba(207,185,145,0.3); text-align: center; }
        .stat-card h3 { margin: 0; font-size: 2rem; color: var(--purdue-gold); }
        .stat-card p { margin: 8px 0 0 0; color: var(--text-light); }
        .stat-card small { color: rgba(207,185,145,0.7); font-size: 0.85rem; }
        .chart-container { background: rgba(0,0,0,0.6); padding: 24px; border-radius: 12px; border: 2px solid rgba(207,185,145,0.3); margin: 20px 0; }
        .chart-wrapper { position: relative; height: 400px; }
        .region-card { background: rgba(0,0,0,0.6); padding: 20px; border-radius: 8px; border: 2px solid rgba(207,185,145,0.3); margin: 15px 0; }
        .region-card h4 { color: var(--purdue-gold); margin: 0 0 15px 0; }
        .impact-row { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin: 10px 0; }
        .impact-box { text-align: center; padding: 10px; border-radius: 4px; }
        .impact-high { background: rgba(244,67,54,0.2); border: 1px solid #f44336; color: #f44336; }
        .impact-medium { background: rgba(255,152,0,0.2); border: 1px solid #ff9800; color: #ff9800; }
        .impact-low { background: rgba(76,175,80,0.2); border: 1px solid #4caf50; color: #4caf50; }
        .loading { text-align: center; padding: 40px; color: var(--purdue-gold); }
    </style>
</head>
<body>
    <header>
        <div class="container">
            <h1>Enterprise Resource Planning Portal</h1>
            <nav>
                <span style="color: white;">Welcome, <?= htmlspecialchars($_SESSION['FullName']) ?> (Senior Manager)</span>
                <a href="../logout.php">Logout</a>
            </nav>
        </div>
    </header>

    <nav class="container" style="background: rgba(0,0,0,0.8); padding: 15px 30px; margin-bottom: 30px; border-radius: 8px; display: flex; gap: 20px; flex-wrap: wrap;">
        <a href="dashboard.php">Dashboard</a>
        <a href="financial.php">Financial Health</a>
        <a href="regional_disruptions.php" class="active">Regional Disruptions</a>
        <a href="critical_companies.php">Critical Companies</a>
        <a href="timeline.php">Disruption Timeline</a>
        <a href="companies.php">Company List</a>
        <a href="distributors.php">Distributors</a>
    </nav>

    <div class="container">
        <h2>Regional Disruption Overview</h2>

        <div class="content-section">
            <h3>Filter Data</h3>
            <form id="filterForm">
                <div class="filter-grid">
                    <div>
                        <label>Start Date:</label>
                        <input type="date" id="start_date" value="<?= htmlspecialchars($startDate) ?>">
                    </div>
                    <div>
                        <label>End Date:</label>
                        <input type="date" id="end_date" value="<?= htmlspecialchars($endDate) ?>">
                    </div>
                    <div>
                        <label>Impact Level:</label>
                        <select id="impact">
                            <option value="">All Levels</option>
                            <option value="High" <?= $impactLevel == 'High' ? 'selected' : '' ?>>High Impact</option>
                            <option value="Medium" <?= $impactLevel == 'Medium' ? 'selected' : '' ?>>Medium Impact</option>
                            <option value="Low" <?= $impactLevel == 'Low' ? 'selected' : '' ?>>Low Impact</option>
                        </select>
                    </div>
                </div>
                <div style="margin-top: 20px; display: flex; gap: 10px;">
                    <button type="submit">Apply Filters</button>
                    <button type="button" id="clearBtn" class="btn-secondary">Clear</button>
                </div>
            </form>
        </div>

        <!-- Summary Stats -->
        <div class="stats-grid">
            <div class="stat-card">
                <h3 id="stat-total"><?= $totalDisruptions ?></h3>
                <p>Total Disruptions</p>
                <small>Across all regions</small>
            </div>
            <div class="stat-card">
                <h3 id="stat-high" style="color: #f44336;"><?= $totalHighImpact ?></h3>
                <p>High Impact Events</p>
                <small>Critical disruptions</small>
            </div>
            <div class="stat-card">
                <h3 id="stat-companies"><?= $totalCompaniesAffected ?></h3>
                <p>Companies Affected</p>
                <small>Unique companies</small>
            </div>
            <div class="stat-card">
                <h3 id="stat-max" style="font-size: 1.3rem;"><?= htmlspecialchars($maxRegionName) ?></h3>
                <p>Highest Risk Region</p>
                <small id="stat-max-count"><?= $maxRegionDisruptions ?> disruptions</small>
            </div>
        </div>

        <!-- Regional Comparison Chart -->
        <div class="chart-container">
            <h3>Disruptions by Region (Stacked by Impact Level)</h3>
            <div class="chart-wrapper">
                <canvas id="regionalChart"></canvas>
            </div>
        </div>

        <!-- Regional Breakdown Cards -->
        <h3>Regional Breakdown</h3>
        <div id="regionCards">
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
                
                <p style="color: var(--text-light); margin-top: 10px;">
                    <strong>Companies Affected:</strong> <?= $region['companiesAffected'] ?>
                </p>
                
                <?php if (isset($categoryByRegion[$region['region']])): ?>
                <details style="margin-top: 15px;">
                    <summary style="cursor: pointer; color: var(--purdue-gold); font-weight: bold;">View Category Breakdown</summary>
                    <ul style="margin-top: 10px; color: var(--text-light);">
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
    (function() {
        var form = document.getElementById('filterForm');
        var regionalChart = null;
        
        // initialize chart on page load
        initChart();
        
        function initChart() {
            var regionData = <?= json_encode($regions) ?>;
            
            var labels = [];
            var highData = [];
            var mediumData = [];
            var lowData = [];
            
            for (var i = 0; i < regionData.length; i++) {
                labels.push(regionData[i].region);
                highData.push(parseInt(regionData[i].highImpactCount));
                mediumData.push(parseInt(regionData[i].mediumImpactCount));
                lowData.push(parseInt(regionData[i].lowImpactCount));
            }
            
            var ctx = document.getElementById('regionalChart').getContext('2d');
            regionalChart = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'High Impact',
                        data: highData,
                        backgroundColor: '#f44336'
                    }, {
                        label: 'Medium Impact',
                        data: mediumData,
                        backgroundColor: '#ff9800'
                    }, {
                        label: 'Low Impact',
                        data: lowData,
                        backgroundColor: '#4caf50'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        x: { 
                            stacked: true,
                            ticks: { color: 'white' }, 
                            grid: { color: 'rgba(207,185,145,0.1)' } 
                        },
                        y: { 
                            stacked: true,
                            beginAtZero: true,
                            ticks: { color: 'white' }, 
                            grid: { color: 'rgba(207,185,145,0.1)' } 
                        }
                    },
                    plugins: { legend: { labels: { color: 'white' } } }
                }
            });
        }
        
        // load regional data via ajax
        function load() {
            document.getElementById('regionCards').innerHTML = '<div class="loading">Loading...</div>';
            
            var params = 'ajax=1&start_date=' + encodeURIComponent(document.getElementById('start_date').value) +
                        '&end_date=' + encodeURIComponent(document.getElementById('end_date').value) +
                        '&impact=' + encodeURIComponent(document.getElementById('impact').value);
            
            var xhr = new XMLHttpRequest();
            xhr.open('GET', 'regional_disruptions.php?' + params, true);
            xhr.onload = function() {
                if (xhr.status === 200) {
                    var r = JSON.parse(xhr.responseText);
                    if (r.success) {
                        var d = r.data;
                        var s = d.summary;
                        
                        // update summary stats
                        document.getElementById('stat-total').textContent = s.totalDisruptions;
                        document.getElementById('stat-high').textContent = s.totalHighImpact;
                        document.getElementById('stat-companies').textContent = s.totalCompaniesAffected;
                        document.getElementById('stat-max').textContent = s.maxRegionName;
                        document.getElementById('stat-max-count').textContent = s.maxRegionDisruptions + ' disruptions';
                        
                        // update chart
                        var labels = [];
                        var highData = [];
                        var mediumData = [];
                        var lowData = [];
                        
                        for (var i = 0; i < d.regions.length; i++) {
                            labels.push(d.regions[i].region);
                            highData.push(parseInt(d.regions[i].highImpactCount));
                            mediumData.push(parseInt(d.regions[i].mediumImpactCount));
                            lowData.push(parseInt(d.regions[i].lowImpactCount));
                        }
                        
                        if (regionalChart) regionalChart.destroy();
                        
                        var ctx = document.getElementById('regionalChart').getContext('2d');
                        regionalChart = new Chart(ctx, {
                            type: 'bar',
                            data: {
                                labels: labels,
                                datasets: [{
                                    label: 'High Impact',
                                    data: highData,
                                    backgroundColor: '#f44336'
                                }, {
                                    label: 'Medium Impact',
                                    data: mediumData,
                                    backgroundColor: '#ff9800'
                                }, {
                                    label: 'Low Impact',
                                    data: lowData,
                                    backgroundColor: '#4caf50'
                                }]
                            },
                            options: {
                                responsive: true,
                                maintainAspectRatio: false,
                                scales: {
                                    x: { 
                                        stacked: true,
                                        ticks: { color: 'white' }, 
                                        grid: { color: 'rgba(207,185,145,0.1)' } 
                                    },
                                    y: { 
                                        stacked: true,
                                        beginAtZero: true,
                                        ticks: { color: 'white' }, 
                                        grid: { color: 'rgba(207,185,145,0.1)' } 
                                    }
                                },
                                plugins: { legend: { labels: { color: 'white' } } }
                            }
                        });
                        
                        // build region cards
                        buildCards(d.regions, d.categoryByRegion);
                    }
                }
            };
            xhr.send();
        }
        
        // build region breakdown cards
        function buildCards(regions, categoryByRegion) {
            if (regions.length === 0) {
                document.getElementById('regionCards').innerHTML = 
                    '<p style="text-align:center;padding:40px;color:var(--text-light)">No regional data found.</p>';
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
                    '<p style="color:var(--text-light);margin-top:10px"><strong>Companies Affected:</strong> ' + reg.companiesAffected + '</p>';
                
                // add category breakdown if available
                if (categoryByRegion[reg.region]) {
                    html += '<details style="margin-top:15px">' +
                        '<summary style="cursor:pointer;color:var(--purdue-gold);font-weight:bold">View Category Breakdown</summary>' +
                        '<ul style="margin-top:10px;color:var(--text-light)">';
                    
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
        
        // utility function
        function esc(t) { 
            if (!t) return '';
            var d = document.createElement('div'); 
            d.textContent = t; 
            return d.innerHTML; 
        }
        
        // event listeners
        form.addEventListener('submit', function(e) { 
            e.preventDefault(); 
            load(); 
            return false;
        });
        
        document.getElementById('clearBtn').addEventListener('click', function() {
            document.getElementById('start_date').value = '<?= date('Y-m-d', strtotime('-1 year')) ?>';
            document.getElementById('end_date').value = '<?= date('Y-m-d') ?>';
            document.getElementById('impact').value = '';
            load();
        });
        
    })();
    </script>
</body>
</html>