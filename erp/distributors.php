<?php
// distributors.php - Distributor Performance
// Compatibility: PHP 5.4 Safe (Unsortable Rank, Sticky Header Fix)

require_once '../config.php';
requireLogin();

// security check
if (!hasRole('SeniorManager')) {
    header('Location: ../scm/dashboard.php');
    exit;
}

$pdo = getPDO();

// =================================================================
// 1. HANDLE AJAX REQUESTS
// =================================================================
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');

    try {
        // --- Capture Inputs ---
        $distributorId = isset($_GET['dist_id']) ? $_GET['dist_id'] : '';
        $region = isset($_GET['region']) ? $_GET['region'] : '';
        $tier = isset($_GET['tier']) ? $_GET['tier'] : '';
        $sortBy = isset($_GET['sort_col']) ? $_GET['sort_col'] : 'delay';
        $sortDir = isset($_GET['sort_dir']) ? $_GET['sort_dir'] : 'asc';

        // --- SQL PARAMETER CONSTRUCTION ---
        // We use an array for params to be safe against injection
        $finalParams = array();
        $whereClause = "WHERE 1=1";

        // Filter by Distributor ID
        if ($distributorId !== '') {
            $whereClause .= " AND d.CompanyID = ?";
            $finalParams[] = $distributorId;
        }

        // Filter by Region (Continent)
        if ($region !== '') {
            $whereClause .= " AND l.ContinentName = ?";
            $finalParams[] = $region;
        }

        // Filter by Tier Level
        if ($tier !== '') {
            $whereClause .= " AND c.TierLevel = ?";
            $finalParams[] = $tier;
        }

        // --- MAIN QUERY ---
        // Calculating aggregated stats in SQL to keep PHP light
        $sql = "SELECT d.CompanyID, c.CompanyName, c.TierLevel, l.ContinentName,
                    COUNT(s.ShipmentID) as TotalShipments,
                    SUM(CASE WHEN s.Quantity IS NOT NULL THEN s.Quantity ELSE 0 END) as TotalQuantity,
                    SUM(CASE WHEN s.ActualDate IS NOT NULL AND s.ActualDate <= s.PromisedDate THEN 1 ELSE 0 END) as OnTimeCount,
                    AVG(CASE WHEN s.ActualDate > s.PromisedDate THEN DATEDIFF(s.ActualDate, s.PromisedDate) ELSE NULL END) as AvgDelay
                FROM Distributor d
                JOIN Company c ON d.CompanyID = c.CompanyID
                JOIN Location l ON c.LocationID = l.LocationID
                LEFT JOIN Shipping s ON d.CompanyID = s.DistributorID
                $whereClause
                GROUP BY d.CompanyID, c.CompanyName, c.TierLevel, l.ContinentName";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($finalParams);
        $rows = $stmt->fetchAll();

        // Process Data for JSON
        $distributors = array();
        foreach ($rows as $r) {
            $total = intval($r['TotalShipments']);
            $onTime = intval($r['OnTimeCount']);

            // Calculate percentage safely
            if ($total > 0) {
                $pct = ($onTime / $total) * 100;
            } else {
                $pct = 0;
            }

            $delay = ($r['AvgDelay'] !== null) ? floatval($r['AvgDelay']) : 0.0;

            $distributors[] = array(
                'id' => $r['CompanyID'],
                'name' => $r['CompanyName'],
                'tier' => $r['TierLevel'],
                'region' => $r['ContinentName'],
                'volume' => $total,
                'quantity' => intval($r['TotalQuantity']),
                'pct' => $pct,
                'delay' => $delay,
                'rank' => 0 // Placeholder, calculated below
            );
        }

        // --- SORTING (PHP Layer) ---
        // Sorting in PHP allows us to easily sort by calculated fields like 'pct'
        usort($distributors, function($a, $b) use ($sortBy, $sortDir) {
            $valA = $a[$sortBy];
            $valB = $b[$sortBy];

            if ($valA == $valB) return 0;

            if ($sortBy === 'name' || $sortBy === 'region') {
                return ($sortDir === 'asc') ? strcmp($valA, $valB) : strcmp($valB, $valA);
            }

            // Numeric comparison
            $dir = ($sortDir === 'asc') ? 1 : -1;
            return ($valA > $valB) ? (1 * $dir) : (-1 * $dir);
        });

        // --- RANK ASSIGNMENT ---
        foreach ($distributors as $key => $d) {
            $distributors[$key]['rank'] = $key + 1;
        }

        // --- PREPARE CHART DATA ---
        // We sort specifically for charts to show "Top 10" or "Worst 10" visually
        
        // Delay Chart (Worst first)
        $delayData = $distributors;
        usort($delayData, function($a, $b) {
            if ($a['delay'] == $b['delay']) return 0;
            return ($a['delay'] > $b['delay']) ? -1 : 1;
        });

        $chartDelay = array('labels' => array(), 'values' => array());
        foreach($delayData as $d) {
            $chartDelay['labels'][] = $d['name'];
            $chartDelay['values'][] = $d['delay'];
        }

        // Volume Chart (Highest first)
        $volData = $distributors;
        usort($volData, function($a, $b) {
            if ($a['volume'] == $b['volume']) return 0;
            return ($a['volume'] > $b['volume']) ? -1 : 1;
        });

        $chartVolume = array('labels' => array(), 'values' => array());
        foreach($volData as $d) {
            $chartVolume['labels'][] = $d['name'];
            $chartVolume['values'][] = $d['volume'];
        }

        // Metrics Summation
        $totalVol = 0; $totalQty = 0;
        foreach ($distributors as $d) {
            $totalVol += $d['volume'];
            $totalQty += $d['quantity'];
        }

        echo json_encode(array(
            'success' => true,
            'distributors' => $distributors,
            'chartDelay' => $chartDelay,
            'chartVolume' => $chartVolume,
            'metrics' => array(
                'count' => count($distributors),
                'volume' => number_format($totalVol),
                'quantity' => number_format($totalQty)
            )
        ));
    } catch (Exception $e) {
        echo json_encode(array('success' => false, 'message' => $e->getMessage()));
    }
    exit;
}

// --- DROPDOWN DATA ---
// Fetching distinct values for filters
$distList = array();
$stmtDist = $pdo->query("SELECT d.CompanyID, c.CompanyName FROM Distributor d JOIN Company c ON d.CompanyID = c.CompanyID ORDER BY c.CompanyName");
if ($stmtDist) $distList = $stmtDist->fetchAll();

$regionList = array();
$stmtReg = $pdo->query("SELECT DISTINCT ContinentName FROM Location ORDER BY ContinentName");
if ($stmtReg) $regionList = $stmtReg->fetchAll();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Distributors - ERP</title>
    <script src="../assets/forward_protection.js"></script>
    <link rel="stylesheet" href="../assets/styles.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        /* Ensures the table has its own scrollable area */
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
        /* Sticky Header Fix */
        .table-scroll-window thead th {
            position: sticky;
            top: 0;
            z-index: 1000;
            background-color: #1a1a1a;
            border-bottom: 3px solid var(--purdue-gold);
            color: #ffffff !important;
        }
        th.sortable { cursor: pointer; }
        th.sortable:hover { background: #333; }
        
        .active-header {
            background: rgba(207, 185, 145, 0.2);
            color: var(--purdue-gold) !important;
        }
        .chart-container {
            background: rgba(0, 0, 0, 0.6);
            padding: 24px;
            border-radius: 12px;
            border: 2px solid rgba(207, 185, 145, 0.3);
        }
        .filter-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
            align-items: end;
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
        <a href="timeline.php">Disruption Timeline</a>
        <a href="disruptions.php">Disruption Analysis</a>
        <a href="distributors.php" class="active">Distributors</a>
        <a href="add_company.php">Add Company</a>
    </nav>

    <div class="container">
        <h2>Distributor Performance</h2>

        <div class="filter-section">
            <h3>Filter Distributors</h3>
            <form id="filterForm" onsubmit="return false;">
                <div class="filter-grid">
                    <div class="filter-group">
                        <label>Distributor Name:</label>
                        <select id="distributor_id">
                            <option value="">All Distributors</option>
                            <?php foreach ($distList as $dist): ?>
                                <option value="<?= $dist['CompanyID'] ?>">
                                    <?= htmlspecialchars($dist['CompanyName']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label>Region:</label>
                        <select id="region">
                            <option value="">All Regions</option>
                            <?php foreach ($regionList as $r): ?>
                                <option value="<?= $r['ContinentName'] ?>">
                                    <?= htmlspecialchars($r['ContinentName']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label>Tier:</label>
                        <select id="tier">
                            <option value="">All Tiers</option>
                            <option value="1">Tier 1</option>
                            <option value="2">Tier 2</option>
                            <option value="3">Tier 3</option>
                        </select>
                    </div>
                </div>
                <div class="flex gap-sm mt-sm" style="justify-content: flex-end;">
                    <button type="button" id="resetBtn" class="btn-reset">Reset Search</button>
                </div>
            </form>
        </div>

        <div class="stats-grid">
            <div class="stat-card">
                <h3 id="metric-count">0</h3>
                <p>Distributors Found</p>
            </div>
            <div class="stat-card">
                <h3 id="metric-volume">0</h3>
                <p>Total Shipment Volume</p>
            </div>
            <div class="stat-card">
                <h3 id="metric-quantity">0</h3>
                <p>Total Quantity Shipped</p>
            </div>
        </div>

        <div class="charts-row">
            <div class="chart-container">
                <h3>Highest Avg Delay (Days)</h3>
                <div class="chart-wrapper">
                    <canvas id="delayChart"></canvas>
                </div>
            </div>
            <div class="chart-container">
                <h3>Highest Shipment Volume</h3>
                <div class="chart-wrapper">
                    <canvas id="volumeChart"></canvas>
                </div>
            </div>
        </div>

        <div class="content-section">
            <h3>Detailed Distributor List</h3>
            <div id="tableWrapper" class="table-scroll-window">
                <table class="distributor-table m-0" style="border:none;">
                    <thead>
                        <tr>
                            <th id="th-rank" style="cursor: default;">Rank</th>
                            <th id="th-name" onclick="changeSort('name')" class="sortable">Distributor Name <span class="sort-icon" id="sort-name"></span></th>
                            <th id="th-region" onclick="changeSort('region')" class="sortable">Region <span class="sort-icon" id="sort-region"></span></th>
                            <th id="th-tier" onclick="changeSort('tier')" class="sortable">Tier <span class="sort-icon" id="sort-tier"></span></th>
                            <th id="th-volume" onclick="changeSort('volume')" class="sortable">Total Shipments <span class="sort-icon" id="sort-volume"></span></th>
                            <th id="th-quantity" onclick="changeSort('quantity')" class="sortable">Total Quantity <span class="sort-icon" id="sort-quantity"></span></th>
                            <th id="th-pct" onclick="changeSort('pct')" class="sortable">On-Time % <span class="sort-icon" id="sort-pct"></span></th>
                            <th id="th-delay" onclick="changeSort('delay')" class="sortable">Avg Delay (Days) <span class="sort-icon" id="sort-delay">▲</span></th>
                        </tr>
                    </thead>
                    <tbody id="tableBody"></tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        var currentSortCol = 'delay';
        var currentSortDir = 'asc';
        var delayChartInstance = null;
        var volumeChartInstance = null;

        // Restore State
        if(sessionStorage.getItem('dist_id')) document.getElementById('distributor_id').value = sessionStorage.getItem('dist_id');
        if(sessionStorage.getItem('dist_region')) document.getElementById('region').value = sessionStorage.getItem('dist_region');
        if(sessionStorage.getItem('dist_tier')) document.getElementById('tier').value = sessionStorage.getItem('dist_tier');

        loadData();
        updateHeaderHighlight();

        // Listeners
        document.getElementById('distributor_id').addEventListener('change', loadData);
        document.getElementById('region').addEventListener('change', loadData);
        document.getElementById('tier').addEventListener('change', loadData);

        document.getElementById('resetBtn').addEventListener('click', function() {
            sessionStorage.removeItem('dist_id');
            sessionStorage.removeItem('dist_region');
            sessionStorage.removeItem('dist_tier');
            document.getElementById('distributor_id').value = '';
            document.getElementById('region').value = '';
            document.getElementById('tier').value = '';
            currentSortCol = 'delay';
            currentSortDir = 'asc';
            updateSortIcons();
            updateHeaderHighlight();
            loadData();
        });

        window.changeSort = function(col) {
            if (currentSortCol === col) {
                currentSortDir = (currentSortDir === 'desc') ? 'asc' : 'desc';
            } else {
                currentSortCol = col;
                // Default direction based on column type
                if(['volume','quantity','pct'].indexOf(col) !== -1) currentSortDir = 'desc';
                else currentSortDir = 'asc';
            }
            updateSortIcons();
            updateHeaderHighlight();
            loadData();
        };

        function updateHeaderHighlight() {
            var headers = document.querySelectorAll('thead th.sortable');
            for(var i=0; i<headers.length; i++) {
                headers[i].classList.remove('active-header');
            }
            var activeTh = document.getElementById('th-' + currentSortCol);
            if(activeTh) activeTh.classList.add('active-header');
        }

        function updateSortIcons() {
            var icons = document.querySelectorAll('.sort-icon');
            for(var i=0; i<icons.length; i++) icons[i].textContent = '';
            var symbol = (currentSortDir === 'asc') ? '▲' : '▼';
            var activeIcon = document.getElementById('sort-' + currentSortCol);
            if(activeIcon) activeIcon.textContent = symbol;
        }

        function loadData() {
            var wrapper = document.getElementById('tableWrapper');
            if(wrapper) wrapper.style.opacity = '0.5';

            var dId = document.getElementById('distributor_id').value;
            var reg = document.getElementById('region').value;
            var tier = document.getElementById('tier').value;

            sessionStorage.setItem('dist_id', dId);
            sessionStorage.setItem('dist_region', reg);
            sessionStorage.setItem('dist_tier', tier);

            var params = 'ajax=1' + 
                '&dist_id=' + encodeURIComponent(dId) +
                '&region=' + encodeURIComponent(reg) +
                '&tier=' + encodeURIComponent(tier) +
                '&sort_col=' + encodeURIComponent(currentSortCol) +
                '&sort_dir=' + encodeURIComponent(currentSortDir);

            var xhr = new XMLHttpRequest();
            xhr.open('GET', 'distributors.php?' + params, true);
            xhr.onload = function() {
                if (xhr.status === 200) {
                    try {
                        var r = JSON.parse(xhr.responseText);
                        if (r.success) {
                            renderMetrics(r.metrics);
                            renderTable(r.distributors);
                            renderCharts(r.chartDelay, r.chartVolume);
                        } else {
                            console.error(r.message);
                        }
                    } catch(e) { console.error("JSON Error", e); }
                }
                if(wrapper) wrapper.style.opacity = '1';
            };
            xhr.send();
        }

        function renderMetrics(m) {
            document.getElementById('metric-count').textContent = m.count;
            document.getElementById('metric-volume').textContent = m.volume;
            document.getElementById('metric-quantity').textContent = m.quantity;
        }

        function renderTable(data) {
            var tbody = document.getElementById('tableBody');
            tbody.innerHTML = '';

            if (data.length === 0) {
                tbody.innerHTML = '<tr><td colspan="8" class="no-data">No distributors found.</td></tr>';
                return;
            }

            for (var i = 0; i < data.length; i++) {
                var d = data[i];
                var tr = document.createElement('tr');
                
                var pctVal = parseFloat(d.pct);
                var displayPct = 'N/A';
                var badgeClass = 'perf-fair';

                if (parseInt(d.volume) > 0) {
                    displayPct = pctVal.toFixed(1) + '%';
                    if (pctVal >= 90) badgeClass = 'perf-excellent';
                    else if (pctVal >= 75) badgeClass = 'perf-good';
                    else if (pctVal >= 50) badgeClass = 'perf-fair';
                    else badgeClass = 'perf-poor';
                }

                tr.innerHTML = 
                    '<td><strong>#' + d.rank + '</strong></td>' +
                    '<td><strong>' + esc(d.name) + '</strong></td>' +
                    '<td>' + esc(d.region) + '</td>' +
                    '<td><span class="tier-badge">Tier ' + esc(d.tier) + '</span></td>' +
                    '<td>' + parseInt(d.volume).toLocaleString() + '</td>' +
                    '<td>' + parseInt(d.quantity).toLocaleString() + '</td>' +
                    '<td><span class="performance-badge ' + badgeClass + '">' + displayPct + '</span></td>' +
                    '<td>' + parseFloat(d.delay).toFixed(1) + '</td>';
                
                tbody.appendChild(tr);
            }
        }

        function renderCharts(dData, vData) {
            // Delay Chart
            var ctxD = document.getElementById('delayChart').getContext('2d');
            if (delayChartInstance) delayChartInstance.destroy();
            
            delayChartInstance = new Chart(ctxD, {
                type: 'bar',
                data: {
                    labels: dData.labels,
                    datasets: [{
                        label: 'Avg Delay (Days)',
                        data: dData.values,
                        backgroundColor: '#f44336', 
                        borderColor: 'transparent'
                    }]
                },
                options: {
                    indexAxis: 'y', 
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        x: { beginAtZero: true, ticks: { color: 'white' }, grid: { color: 'rgba(207,185,145,0.1)' } },
                        y: { ticks: { color: 'white' }, grid: { display: false } }
                    },
                    plugins: { legend: { display: false } }
                }
            });

            // Volume Chart
            var ctxV = document.getElementById('volumeChart').getContext('2d');
            if (volumeChartInstance) volumeChartInstance.destroy();
            
            volumeChartInstance = new Chart(ctxV, {
                type: 'bar',
                data: {
                    labels: vData.labels,
                    datasets: [{
                        label: 'Total Shipments',
                        data: vData.values,
                        backgroundColor: '#4caf50', 
                        borderColor: 'transparent'
                    }]
                },
                options: {
                    indexAxis: 'y', 
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        x: { beginAtZero: true, ticks: { color: 'white' }, grid: { color: 'rgba(207,185,145,0.1)' } },
                        y: { ticks: { color: 'white' }, grid: { display: false } }
                    },
                    plugins: { legend: { display: false } }
                }
            });
        }

        function esc(str) {
            if(!str) return '';
            var div = document.createElement('div');
            div.textContent = str;
            return div.innerHTML;
        }
    });
    </script>
</body>
</html>