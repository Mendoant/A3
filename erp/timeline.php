<?php
// erp/timeline.php - Disruption Timeline Analysis
// tracking when things went wrong over time

require_once '../config.php';
requireLogin();

// strictly checking permissions
if (!hasRole('SeniorManager')) {
    header('Location: ../scm/dashboard.php');
    exit;
}

$pdo = getPDO();

// --- 1. GRAB INPUTS ---
// setting reasonable defaults so the chart isn't empty on load
$startDate = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d', strtotime('-2 years'));
$endDate = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');
$category = isset($_GET['category']) ? $_GET['category'] : '';
$region = isset($_GET['region']) ? $_GET['region'] : '';
$groupBy = isset($_GET['group_by']) ? $_GET['group_by'] : 'month'; // default to monthly view

// --- 2. PREPARE TIMELINE QUERY ---
// we need to fetch all events first, then we can bucket them in php
// doing the grouping in sql is cleaner but php is easier for custom labels like "Q1 2023"
$sql = "SELECT de.EventID, de.EventDate, dc.CategoryName, dc.CategoryID 
        FROM DisruptionEvent de
        JOIN DisruptionCategory dc ON de.CategoryID = dc.CategoryID 
        WHERE de.EventDate BETWEEN :start AND :end";

$params = array(':start' => $startDate, ':end' => $endDate);

// optional filters
if (!empty($category)) { 
    $sql .= " AND dc.CategoryID = :category"; 
    $params[':category'] = $category; 
}

if (!empty($region)) {
    // subquery to check if the event impacted a company in this specific region
    $sql .= " AND EXISTS (
                SELECT 1 FROM ImpactsCompany ic 
                JOIN Company c ON ic.AffectedCompanyID = c.CompanyID
                JOIN Location l ON c.LocationID = l.LocationID 
                WHERE ic.EventID = de.EventID AND l.ContinentName = :region
              )";
    $params[':region'] = $region;
}

$sql .= " ORDER BY de.EventDate ASC";

// execute
try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $allEvents = $stmt->fetchAll();
} catch (PDOException $e) {
    if (isset($_GET['ajax'])) {
        die(json_encode(array('success' => false, 'message' => $e->getMessage())));
    }
}

// --- 3. PROCESS DATA (BUCKETING) ---
$timelineData = array();

foreach ($allEvents as $event) {
    $eventDate = $event['EventDate'];
    
    // decide how to group this event based on user selection
    if ($groupBy == 'month') {
        $periodKey = date('Y-m', strtotime($eventDate));
        $periodLabel = date('M Y', strtotime($eventDate));
    } elseif ($groupBy == 'quarter') {
        // calculating quarter 1-4
        $month = intval(date('m', strtotime($eventDate)));
        $quarter = ceil($month / 3);
        $periodKey = date('Y', strtotime($eventDate)) . '-Q' . $quarter;
        $periodLabel = 'Q' . $quarter . ' ' . date('Y', strtotime($eventDate));
    } else {
        // yearly
        $periodKey = date('Y', strtotime($eventDate));
        $periodLabel = $periodKey;
    }
    
    // initialize bucket if new
    if (!isset($timelineData[$periodKey])) {
        $timelineData[$periodKey] = array(
            'period' => $periodLabel, 
            'count' => 0, 
            'events' => array()
        );
    }
    
    // increment
    $timelineData[$periodKey]['count']++;
    $timelineData[$periodKey]['events'][] = $event;
}

// ensure the timeline is in chronological order
ksort($timelineData);

// --- 4. SUMMARY STATS ---
$totalEvents = count($allEvents);
$totalPeriods = count($timelineData);
$avgPerPeriod = $totalPeriods > 0 ? round($totalEvents / $totalPeriods, 1) : 0;

// find the worst period
$peakPeriod = 'N/A'; 
$peakCount = 0;
foreach ($timelineData as $key => $data) {
    if ($data['count'] > $peakCount) { 
        $peakCount = $data['count']; 
        $peakPeriod = $data['period']; 
    }
}

// --- 5. CATEGORY DISTRIBUTION (SECOND CHART) ---
// need a separate query to count categories for the pie chart
$categorySql = "SELECT dc.CategoryName, COUNT(DISTINCT de.EventID) as eventCount 
                FROM DisruptionEvent de
                JOIN DisruptionCategory dc ON de.CategoryID = dc.CategoryID 
                WHERE de.EventDate BETWEEN :start AND :end";

$catParams = array(':start' => $startDate, ':end' => $endDate);

// re-apply region filter logic
if (!empty($region)) {
    $categorySql .= " AND EXISTS (
                        SELECT 1 FROM ImpactsCompany ic 
                        JOIN Company c ON ic.AffectedCompanyID = c.CompanyID
                        JOIN Location l ON c.LocationID = l.LocationID 
                        WHERE ic.EventID = de.EventID AND l.ContinentName = :region
                      )";
    $catParams[':region'] = $region;
}

$categorySql .= " GROUP BY dc.CategoryName ORDER BY eventCount DESC LIMIT 5";

$stmt2 = $pdo->prepare($categorySql);
$stmt2->execute($catParams);
$topCategories = $stmt2->fetchAll();

// --- 6. AJAX RESPONSE ---
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    echo json_encode(array(
        'success' => true,
        'data' => array(
            'timeline' => array_values($timelineData), // indexed array for chartjs
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

// dropdowns for filters
$allCategories = $pdo->query("SELECT CategoryID, CategoryName FROM DisruptionCategory ORDER BY CategoryName")->fetchAll();
$allRegions = $pdo->query("SELECT DISTINCT ContinentName FROM Location ORDER BY ContinentName")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Disruption Timeline - ERP</title>
    <script src="../assets/forward_protection.js"></script>
    <link rel="stylesheet" href="../assets/styles.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        /* making sure the grid looks nice */
        .filter-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 15px;
            align-items: end;
        }
        @media (max-width: 1200px) {
            .filter-grid { grid-template-columns: repeat(2, 1fr); }
        }
        /* dynamic sizing for the big numbers */
        .stat-card h3 {
            font-size: clamp(1.5rem, 4vw, 3rem);
            word-break: break-word;
            line-height: 1.1;
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
        <a href="regional_disruptions.php">Regional Disruptions</a>
        <a href="timeline.php" class = "active">Disruption Timeline</a>
        <a href="disruptions.php">Disruption Analysis</a>
        <a href="distributors.php">Distributors</a>
        <a href="add_company.php">Add Company</a>
    </nav>

    <div class="container">
        <h2>Disruption Timeline Analysis</h2>

        <div class="filter-section">
            <h3>Filter Timeline</h3>
            <form id="filterForm" onsubmit="return false;">
                <div class="filter-grid">
                    <div class="filter-group">
                        <label>Group By:</label>
                        <select id="group_by">
                            <option value="month" <?= $groupBy == 'month' ? 'selected' : '' ?>>Monthly</option>
                            <option value="quarter" <?= $groupBy == 'quarter' ? 'selected' : '' ?>>Quarterly</option>
                            <option value="year" <?= $groupBy == 'year' ? 'selected' : '' ?>>Yearly</option>
                        </select>
                    </div>
                    <div class="filter-group">
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
                        </div>

                    <div class="filter-group">
                        <label>Start Date:</label>
                        <input type="date" id="start_date" value="<?= htmlspecialchars($startDate) ?>">
                    </div>
                    <div class="filter-group">
                        <label>End Date:</label>
                        <input type="date" id="end_date" value="<?= htmlspecialchars($endDate) ?>">
                    </div>
                </div>
                <div class="flex gap-sm mt-sm" style="justify-content: flex-end;">
                    <button type="button" id="clearBtn" class="btn-reset">Reset Filters</button>
                </div>
            </form>
        </div>

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

        <div class="chart-container">
            <h3>Disruption Frequency Over Time</h3>
            <div class="chart-wrapper">
                <canvas id="timelineChart"></canvas>
            </div>
        </div>

        <div class="grid grid-2">
            <div class="category-list">
                <h3 class="text-gold mb-sm">Top 5 Disruption Categories</h3>
                <div id="categoryList">
                    <?php foreach ($topCategories as $idx => $cat): ?>
                    <div class="category-item">
                        <span><?= $idx + 1 ?>. <?= htmlspecialchars($cat['CategoryName']) ?></span>
                        <span><?= $cat['eventCount'] ?> events</span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="chart-container m-0">
                <h3 class="text-gold mb-sm">Category Distribution</h3>
                <div style="position: relative; height: 300px;">
                    <canvas id="categoryChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        var timelineChart = null; 
        var categoryChart = null;
        var timeout = null;
        
        // --- RESTORE SESSION FILTERS ---
        if(sessionStorage.getItem('time_start')) document.getElementById('start_date').value = sessionStorage.getItem('time_start');
        if(sessionStorage.getItem('time_end')) document.getElementById('end_date').value = sessionStorage.getItem('time_end');
        if(sessionStorage.getItem('time_cat')) document.getElementById('category').value = sessionStorage.getItem('time_cat');
        if(sessionStorage.getItem('time_region')) document.getElementById('region').value = sessionStorage.getItem('time_region');
        if(sessionStorage.getItem('time_group')) document.getElementById('group_by').value = sessionStorage.getItem('time_group');

        // Initial paint
        initCharts();

        // --- LISTENERS ---
        var inputs = document.querySelectorAll('#filterForm input, #filterForm select');
        for(var i=0; i<inputs.length; i++) {
            inputs[i].addEventListener('change', load);
        }
        
        document.getElementById('clearBtn').addEventListener('click', function() {
            // wipe clean
            sessionStorage.removeItem('time_start');
            sessionStorage.removeItem('time_end');
            sessionStorage.removeItem('time_cat');
            sessionStorage.removeItem('time_region');
            sessionStorage.removeItem('time_group');

            document.getElementById('start_date').value = '<?= date('Y-m-d', strtotime('-2 years')) ?>';
            document.getElementById('end_date').value = '<?= date('Y-m-d') ?>';
            document.getElementById('category').value = '';
            document.getElementById('region').value = '';
            document.getElementById('group_by').value = 'month';
            load();
        });

        function initCharts() {
            // initial data from PHP render
            var timelineData = <?= json_encode(array_values($timelineData)) ?>;
            var topCats = <?= json_encode($topCategories) ?>;
            renderAll(timelineData, topCats);
        }
        
        function load() {
            // grab inputs
            var sDate = document.getElementById('start_date').value;
            var eDate = document.getElementById('end_date').value;
            var cat = document.getElementById('category').value;
            var reg = document.getElementById('region').value;
            var grp = document.getElementById('group_by').value;

            // persist
            sessionStorage.setItem('time_start', sDate);
            sessionStorage.setItem('time_end', eDate);
            sessionStorage.setItem('time_cat', cat);
            sessionStorage.setItem('time_region', reg);
            sessionStorage.setItem('time_group', grp);

            var params = 'ajax=1&start_date=' + encodeURIComponent(sDate) +
                        '&end_date=' + encodeURIComponent(eDate) +
                        '&category=' + encodeURIComponent(cat) +
                        '&region=' + encodeURIComponent(reg) +
                        '&group_by=' + encodeURIComponent(grp);
            
            var xhr = new XMLHttpRequest();
            xhr.open('GET', 'timeline.php?' + params, true);
            xhr.onload = function() {
                if (xhr.status === 200) {
                    try {
                        // CRITICAL: Check if response is actually JSON or if we got redirected to HTML
                        if (xhr.responseText.trim().indexOf('{') !== 0) {
                            console.error("Received HTML instead of JSON. Session likely expired.");
                            alert("Session timed out. Please reload the page to log in.");
                            return;
                        }

                        var r = JSON.parse(xhr.responseText);
                        if (r.success) {
                            var d = r.data; 
                            var s = d.summary;
                            
                            // update text stats
                            document.getElementById('stat-total').textContent = s.totalEvents;
                            document.getElementById('stat-periods').textContent = s.totalPeriods;
                            document.getElementById('stat-avg').textContent = s.avgPerPeriod;
                            document.getElementById('stat-peak-period').textContent = s.peakPeriod;
                            document.getElementById('stat-peak-count').textContent = s.peakCount + ' disruptions';
                            document.getElementById('period-label').textContent = document.getElementById('group_by').value + 's analyzed';
                            
                            // redraw charts
                            renderAll(d.timeline, d.topCategories);
                        } else {
                            console.error('Server error:', r.message);
                        }
                    } catch(e) {
                        console.error('JSON Error:', e);
                    }
                }
            };
            xhr.send();
        }

        function renderAll(timelineData, topCats) {
            // --- 1. Line Chart ---
            var labels = []; 
            var counts = [];
            for (var i = 0; i < timelineData.length; i++) {
                labels.push(timelineData[i].period);
                counts.push(parseInt(timelineData[i].count));
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
                        y: { beginAtZero: true, ticks: { color: 'white', stepSize: 1 }, grid: { color: 'rgba(207,185,145,0.1)' } }, 
                        x: { ticks: { color: 'white' }, grid: { color: 'rgba(207,185,145,0.1)' } } 
                    }, 
                    plugins: { legend: { labels: { color: 'white' } } } 
                }
            });
            
            // --- 2. Category List & Donut ---
            var catHtml = '';
            var catLabels = []; 
            var catCounts = [];
            
            for (var i = 0; i < topCats.length; i++) {
                var cat = topCats[i];
                catLabels.push(cat.CategoryName);
                catCounts.push(parseInt(cat.eventCount));
                catHtml += '<div class="category-item"><span>' + (i + 1) + '. ' + esc(cat.CategoryName) + '</span><span>' + cat.eventCount + ' events</span></div>';
            }
            document.getElementById('categoryList').innerHTML = catHtml;
            
            if (categoryChart) categoryChart.destroy();
            var ctx2 = document.getElementById('categoryChart').getContext('2d');
            categoryChart = new Chart(ctx2, {
                type: 'doughnut',
                data: { 
                    labels: catLabels, 
                    datasets: [{ 
                        data: catCounts, 
                        backgroundColor: ['#CFB991', '#f44336', '#ff9800', '#4caf50', '#2196f3'],
                        borderWidth: 0
                    }] 
                },
                options: { 
                    responsive: true, 
                    maintainAspectRatio: false, 
                    plugins: { legend: { labels: { color: 'white' }, position: 'bottom' } } 
                }
            });
        }
        
        function esc(t) { if (!t) return ''; var d = document.createElement('div'); d.textContent = t; return d.innerHTML; }
    });
    </script>
</body>
</html>