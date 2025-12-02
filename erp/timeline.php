<?php
// erp/timeline.php - Disruption Timeline Analysis with AJAX
require_once '../config.php';
requireLogin();

// only senior managers can access erp module
if (!hasRole('SeniorManager')) {
    header('Location: ../scm/dashboard.php');
    exit;
}

$pdo = getPDO();

// Get filters
$startDate = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d', strtotime('-2 years'));
$endDate = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');
$category = isset($_GET['category']) ? $_GET['category'] : '';
$region = isset($_GET['region']) ? $_GET['region'] : '';
$groupBy = isset($_GET['group_by']) ? $_GET['group_by'] : 'month';  // month, quarter, year

// Query: Disruption frequency over time
// group by the selected time period
$timeFormat = '';
if ($groupBy == 'month') {
    $timeFormat = '%Y-%m';
} elseif ($groupBy == 'quarter') {
    $timeFormat = '%Y-Q';  // we'll calculate quarter manually
} else {
    $timeFormat = '%Y';
}

// Get all disruption events with dates
$sql = "SELECT 
            de.EventID,
            de.EventDate,
            dc.CategoryName,
            dc.CategoryID
        FROM DisruptionEvent de
        JOIN DisruptionCategory dc ON de.CategoryID = dc.CategoryID
        WHERE de.EventDate BETWEEN :start AND :end";

$params = array(':start' => $startDate, ':end' => $endDate);

if (!empty($category)) {
    $sql .= " AND dc.CategoryID = :category";
    $params[':category'] = $category;
}

// if region filter is set, need to join with companies
if (!empty($region)) {
    $sql .= " AND EXISTS (
                SELECT 1 FROM ImpactsCompany ic
                JOIN Company c ON ic.AffectedCompanyID = c.CompanyID
                JOIN Location l ON c.LocationID = l.LocationID
                WHERE ic.EventID = de.EventID AND l.ContinentName = :region
              )";
    $params[':region'] = $region;
}

$sql .= " ORDER BY de.EventDate ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$allEvents = $stmt->fetchAll();

// Group events by time period manually
$timelineData = array();

foreach ($allEvents as $event) {
    $eventDate = $event['EventDate'];
    
    // calculate the time period key
    if ($groupBy == 'month') {
        $periodKey = date('Y-m', strtotime($eventDate));
        $periodLabel = date('M Y', strtotime($eventDate));
    } elseif ($groupBy == 'quarter') {
        $year = date('Y', strtotime($eventDate));
        $month = intval(date('m', strtotime($eventDate)));
        $quarter = ceil($month / 3);
        $periodKey = $year . '-Q' . $quarter;
        $periodLabel = 'Q' . $quarter . ' ' . $year;
    } else {
        $periodKey = date('Y', strtotime($eventDate));
        $periodLabel = $periodKey;
    }
    
    if (!isset($timelineData[$periodKey])) {
        $timelineData[$periodKey] = array(
            'period' => $periodLabel,
            'count' => 0,
            'events' => array()
        );
    }
    
    $timelineData[$periodKey]['count']++;
    $timelineData[$periodKey]['events'][] = $event;
}

// Sort by period key
ksort($timelineData);

// Calculate summary stats
$totalEvents = count($allEvents);
$totalPeriods = count($timelineData);
$avgPerPeriod = $totalPeriods > 0 ? round($totalEvents / $totalPeriods, 1) : 0;

// find peak period
$peakPeriod = '';
$peakCount = 0;
foreach ($timelineData as $key => $data) {
    if ($data['count'] > $peakCount) {
        $peakCount = $data['count'];
        $peakPeriod = $data['period'];
    }
}

// Get category breakdown for the entire period
$categorySql = "SELECT 
                    dc.CategoryName,
                    COUNT(DISTINCT de.EventID) as eventCount
                FROM DisruptionEvent de
                JOIN DisruptionCategory dc ON de.CategoryID = dc.CategoryID
                WHERE de.EventDate BETWEEN :start AND :end";

$categoryParams = array(':start' => $startDate, ':end' => $endDate);

if (!empty($region)) {
    $categorySql .= " AND EXISTS (
                        SELECT 1 FROM ImpactsCompany ic
                        JOIN Company c ON ic.AffectedCompanyID = c.CompanyID
                        JOIN Location l ON c.LocationID = l.LocationID
                        WHERE ic.EventID = de.EventID AND l.ContinentName = :region
                      )";
    $categoryParams[':region'] = $region;
}

$categorySql .= " GROUP BY dc.CategoryName ORDER BY eventCount DESC LIMIT 5";

$stmt2 = $pdo->prepare($categorySql);
$stmt2->execute($categoryParams);
$topCategories = $stmt2->fetchAll();

// AJAX response
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    echo json_encode(array(
        'success' => true,
        'data' => array(
            'timeline' => array_values($timelineData),
            'topCategories' => $topCategories,
            'summary' => array(
                'totalEvents' => $totalEvents,
                'totalPeriods' => $totalPeriods,
                'avgPerPeriod' => $avgPerPeriod,
                'peakPeriod' => $peakPeriod,
                'peakCount' => $peakCount
            )
        )
    ));
    exit;
}

// Get filter options (only on initial load)
$allCategories = $pdo->query("SELECT CategoryID, CategoryName FROM DisruptionCategory ORDER BY CategoryName")->fetchAll();
$allRegions = $pdo->query("SELECT DISTINCT ContinentName FROM Location ORDER BY ContinentName")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Disruption Timeline - ERP</title>
    <link rel="stylesheet" href="../assets/styles.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .filter-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-top: 20px; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 20px; margin: 30px 0; }
        .stat-card { background: rgba(0,0,0,0.6); padding: 24px; border-radius: 8px; border: 2px solid rgba(207,185,145,0.3); text-align: center; }
        .stat-card h3 { margin: 0; font-size: 2rem; color: var(--purdue-gold); }
        .stat-card p { margin: 8px 0 0 0; color: var(--text-light); }
        .stat-card.peak h3 { color: #f44336; }
        .chart-container { background: rgba(0,0,0,0.6); padding: 24px; border-radius: 12px; border: 2px solid rgba(207,185,145,0.3); margin: 20px 0; }
        .chart-wrapper { position: relative; height: 400px; }
        .category-list { background: rgba(0,0,0,0.6); padding: 20px; border-radius: 8px; border: 2px solid rgba(207,185,145,0.3); margin: 20px 0; }
        .category-item { display: flex; justify-content: space-between; padding: 10px; margin: 5px 0; background: rgba(207,185,145,0.1); border-radius: 4px; }
        .category-item span:first-child { color: var(--text-light); }
        .category-item span:last-child { color: var(--purdue-gold); font-weight: bold; }
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
        <a href="regional_disruptions.php">Regional Disruptions</a>
        <a href="critical_companies.php">Critical Companies</a>
        <a href="timeline.php" class="active">Disruption Timeline</a>
        <a href="companies.php">Company List</a>
        <a href="distributors.php">Distributors</a>
    </nav>

    <div class="container">
        <h2>Disruption Timeline Analysis</h2>

        <div class="content-section">
            <h3>Filter Timeline</h3>
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
                        <label>Category:</label>
                        <select id="category">
                            <option value="">All Categories</option>
                            <?php foreach ($allCategories as $cat): ?>
                                <option value="<?= $cat['CategoryID'] ?>" <?= $category == $cat['CategoryID'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($cat['CategoryName']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
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
                    <div>
                        <label>Group By:</label>
                        <select id="group_by">
                            <option value="month" <?= $groupBy == 'month' ? 'selected' : '' ?>>Monthly</option>
                            <option value="quarter" <?= $groupBy == 'quarter' ? 'selected' : '' ?>>Quarterly</option>
                            <option value="year" <?= $groupBy == 'year' ? 'selected' : '' ?>>Yearly</option>
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
                <h3 id="stat-total"><?= $totalEvents ?></h3>
                <p>Total Disruptions</p>
                <small>In selected period</small>
            </div>
            <div class="stat-card">
                <h3 id="stat-periods"><?= $totalPeriods ?></h3>
                <p>Time Periods</p>
                <small id="period-label"><?= ucfirst($groupBy) ?>s analyzed</small>
            </div>
            <div class="stat-card">
                <h3 id="stat-avg"><?= $avgPerPeriod ?></h3>
                <p>Avg Per Period</p>
                <small>Disruptions per <?= $groupBy ?></small>
            </div>
            <div class="stat-card peak">
                <h3 id="stat-peak-period"><?= htmlspecialchars($peakPeriod) ?></h3>
                <p>Peak Period</p>
                <small id="stat-peak-count"><?= $peakCount ?> disruptions</small>
            </div>
        </div>

        <!-- Timeline Chart -->
        <div class="chart-container">
            <h3>Disruption Frequency Over Time</h3>
            <div class="chart-wrapper">
                <canvas id="timelineChart"></canvas>
            </div>
        </div>

        <!-- Top Categories -->
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
            <div class="category-list">
                <h3 style="color: var(--purdue-gold); margin: 0 0 15px 0;">Top 5 Disruption Categories</h3>
                <div id="categoryList">
                    <?php foreach ($topCategories as $idx => $cat): ?>
                    <div class="category-item">
                        <span><?= $idx + 1 ?>. <?= htmlspecialchars($cat['CategoryName']) ?></span>
                        <span><?= $cat['eventCount'] ?> events</span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Category Distribution Chart -->
            <div class="chart-container" style="margin: 0;">
                <h3 style="color: var(--purdue-gold); margin: 0 0 15px 0;">Category Distribution</h3>
                <div style="position: relative; height: 300px;">
                    <canvas id="categoryChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <script>
    (function() {
        var form = document.getElementById('filterForm');
        var timelineChart = null;
        var categoryChart = null;
        
        // initialize charts on page load
        initCharts();
        
        function initCharts() {
            var timelineData = <?= json_encode(array_values($timelineData)) ?>;
            var topCats = <?= json_encode($topCategories) ?>;
            
            // timeline line chart
            var labels = [];
            var counts = [];
            
            for (var i = 0; i < timelineData.length; i++) {
                labels.push(timelineData[i].period);
                counts.push(parseInt(timelineData[i].count));
            }
            
            var ctx1 = document.getElementById('timelineChart').getContext('2d');
            timelineChart = new Chart(ctx1, {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'Disruption Events',
                        data: counts,
                        borderColor: '#CFB991',
                        backgroundColor: 'rgba(207,185,145,0.2)',
                        tension: 0.4,
                        fill: true
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: { 
                            beginAtZero: true,
                            ticks: { 
                                color: 'white',
                                stepSize: 1
                            }, 
                            grid: { color: 'rgba(207,185,145,0.1)' } 
                        },
                        x: { 
                            ticks: { color: 'white' }, 
                            grid: { color: 'rgba(207,185,145,0.1)' } 
                        }
                    },
                    plugins: { legend: { labels: { color: 'white' } } }
                }
            });
            
            // category doughnut chart
            var catLabels = [];
            var catCounts = [];
            
            for (var i = 0; i < topCats.length; i++) {
                catLabels.push(topCats[i].CategoryName);
                catCounts.push(parseInt(topCats[i].eventCount));
            }
            
            var ctx2 = document.getElementById('categoryChart').getContext('2d');
            categoryChart = new Chart(ctx2, {
                type: 'doughnut',
                data: {
                    labels: catLabels,
                    datasets: [{
                        data: catCounts,
                        backgroundColor: ['#CFB991', '#f44336', '#ff9800', '#4caf50', '#2196f3']
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { 
                        legend: { 
                            labels: { color: 'white' },
                            position: 'bottom'
                        } 
                    }
                }
            });
        }
        
        // load timeline data via ajax
        function load() {
            var params = 'ajax=1&start_date=' + encodeURIComponent(document.getElementById('start_date').value) +
                        '&end_date=' + encodeURIComponent(document.getElementById('end_date').value) +
                        '&category=' + encodeURIComponent(document.getElementById('category').value) +
                        '&region=' + encodeURIComponent(document.getElementById('region').value) +
                        '&group_by=' + encodeURIComponent(document.getElementById('group_by').value);
            
            var xhr = new XMLHttpRequest();
            xhr.open('GET', 'timeline.php?' + params, true);
            xhr.onload = function() {
                if (xhr.status === 200) {
                    var r = JSON.parse(xhr.responseText);
                    if (r.success) {
                        var d = r.data;
                        var s = d.summary;
                        
                        // update summary stats
                        document.getElementById('stat-total').textContent = s.totalEvents;
                        document.getElementById('stat-periods').textContent = s.totalPeriods;
                        document.getElementById('stat-avg').textContent = s.avgPerPeriod;
                        document.getElementById('stat-peak-period').textContent = s.peakPeriod;
                        document.getElementById('stat-peak-count').textContent = s.peakCount + ' disruptions';
                        
                        var groupBy = document.getElementById('group_by').value;
                        document.getElementById('period-label').textContent = groupBy.charAt(0).toUpperCase() + groupBy.slice(1) + 's analyzed';
                        
                        // update timeline chart
                        var labels = [];
                        var counts = [];
                        
                        for (var i = 0; i < d.timeline.length; i++) {
                            labels.push(d.timeline[i].period);
                            counts.push(parseInt(d.timeline[i].count));
                        }
                        
                        if (timelineChart) timelineChart.destroy();
                        
                        var ctx1 = document.getElementById('timelineChart').getContext('2d');
                        timelineChart = new Chart(ctx1, {
                            type: 'line',
                            data: {
                                labels: labels,
                                datasets: [{
                                    label: 'Disruption Events',
                                    data: counts,
                                    borderColor: '#CFB991',
                                    backgroundColor: 'rgba(207,185,145,0.2)',
                                    tension: 0.4,
                                    fill: true
                                }]
                            },
                            options: {
                                responsive: true,
                                maintainAspectRatio: false,
                                scales: {
                                    y: { 
                                        beginAtZero: true,
                                        ticks: { 
                                            color: 'white',
                                            stepSize: 1
                                        }, 
                                        grid: { color: 'rgba(207,185,145,0.1)' } 
                                    },
                                    x: { 
                                        ticks: { color: 'white' }, 
                                        grid: { color: 'rgba(207,185,145,0.1)' } 
                                    }
                                },
                                plugins: { legend: { labels: { color: 'white' } } }
                            }
                        });
                        
                        // update category list
                        var catHtml = '';
                        for (var i = 0; i < d.topCategories.length; i++) {
                            var cat = d.topCategories[i];
                            catHtml += '<div class="category-item">' +
                                '<span>' + (i + 1) + '. ' + esc(cat.CategoryName) + '</span>' +
                                '<span>' + cat.eventCount + ' events</span>' +
                                '</div>';
                        }
                        document.getElementById('categoryList').innerHTML = catHtml;
                        
                        // update category chart
                        var catLabels = [];
                        var catCounts = [];
                        
                        for (var i = 0; i < d.topCategories.length; i++) {
                            catLabels.push(d.topCategories[i].CategoryName);
                            catCounts.push(parseInt(d.topCategories[i].eventCount));
                        }
                        
                        if (categoryChart) categoryChart.destroy();
                        
                        var ctx2 = document.getElementById('categoryChart').getContext('2d');
                        categoryChart = new Chart(ctx2, {
                            type: 'doughnut',
                            data: {
                                labels: catLabels,
                                datasets: [{
                                    data: catCounts,
                                    backgroundColor: ['#CFB991', '#f44336', '#ff9800', '#4caf50', '#2196f3']
                                }]
                            },
                            options: {
                                responsive: true,
                                maintainAspectRatio: false,
                                plugins: { 
                                    legend: { 
                                        labels: { color: 'white' },
                                        position: 'bottom'
                                    } 
                                }
                            }
                        });
                    }
                }
            };
            xhr.send();
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
            document.getElementById('start_date').value = '<?= date('Y-m-d', strtotime('-2 years')) ?>';
            document.getElementById('end_date').value = '<?= date('Y-m-d') ?>';
            document.getElementById('category').value = '';
            document.getElementById('region').value = '';
            document.getElementById('group_by').value = 'month';
            load();
        });
        
    })();
    </script>
</body>
</html>