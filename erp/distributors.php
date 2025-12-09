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

// --- 1. HANDLE AJAX REQUESTS ---
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');

    try {
        $distributorId = isset($_GET['dist_id']) ? $_GET['dist_id'] : ''; 
        $sortBy = isset($_GET['sort_col']) ? $_GET['sort_col'] : 'delay'; 
        $sortDir = isset($_GET['sort_dir']) ? $_GET['sort_dir'] : 'asc'; 

        // --- SQL PARAMETER CONSTRUCTION ---
        $finalParams = array();
        $whereClause = "WHERE 1=1";
        
        // Exact match for dropdown
        if ($distributorId !== '') {
            $whereClause .= " AND d.CompanyID = ?";
            $finalParams[] = $distributorId; 
        }

        // --- MAIN QUERY ---
        $sql = "SELECT d.CompanyID, c.CompanyName,
                    COUNT(s.ShipmentID) as TotalShipments,
                    SUM(CASE WHEN s.Quantity IS NOT NULL THEN s.Quantity ELSE 0 END) as TotalQuantity,
                    SUM(CASE WHEN s.ActualDate IS NOT NULL AND s.ActualDate <= s.PromisedDate THEN 1 ELSE 0 END) as OnTimeCount,
                    AVG(CASE WHEN s.ActualDate > s.PromisedDate THEN DATEDIFF(s.ActualDate, s.PromisedDate) ELSE NULL END) as AvgDelay
                FROM Distributor d
                JOIN Company c ON d.CompanyID = c.CompanyID
                LEFT JOIN Shipping s ON d.CompanyID = s.DistributorID
                $whereClause
                GROUP BY d.CompanyID, c.CompanyName";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($finalParams);
        $rows = $stmt->fetchAll();

        // Process Data
        $distributors = array();
        foreach ($rows as $r) {
            $total = intval($r['TotalShipments']);
            $onTime = intval($r['OnTimeCount']);
            
            // Avoid divide by zero
            if ($total > 0) {
                $pct = ($onTime / $total) * 100;
            } else {
                $pct = 0;
            }
            
            $delay = ($r['AvgDelay'] !== null) ? floatval($r['AvgDelay']) : 0.0;
            
            $distributors[] = array(
                'id' => $r['CompanyID'],
                'name' => $r['CompanyName'],
                'volume' => $total,
                'quantity' => intval($r['TotalQuantity']),
                'pct' => $pct,
                'delay' => $delay,
                'rank' => 0 
            );
        }

        // --- SORTING ---
        // Sort based on user request (or default delay)
        usort($distributors, function($a, $b) use ($sortBy, $sortDir) {
            $valA = $a[$sortBy];
            $valB = $b[$sortBy];
            
            if ($valA == $valB) return 0;
            
            if ($sortBy === 'name') {
                return ($sortDir === 'asc') ? strcmp($valA, $valB) : strcmp($valB, $valA);
            }
            
            // Numeric comparison
            $dir = ($sortDir === 'asc') ? 1 : -1;
            return ($valA > $valB) ? (1 * $dir) : (-1 * $dir);
        });

        // --- RANK ASSIGNMENT ---
        // Rank is simply the row number after sorting
        foreach ($distributors as $key => $d) {
            $distributors[$key]['rank'] = $key + 1;
        }

        // --- PREPARE CHART DATA (ALL COMPANIES) ---
        // Delay Chart (Sorted by Delay Descending)
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

        // Volume Chart (Sorted by Volume Descending)
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

        // Metrics
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
$distList = array();
$stmtDist = $pdo->query("SELECT d.CompanyID, c.CompanyName FROM Distributor d JOIN Company c ON d.CompanyID = c.CompanyID ORDER BY c.CompanyName");
if ($stmtDist) {
    $distList = $stmtDist->fetchAll();
}
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
            z-index: 1000; /* High z-index to stay on top */
            background-color: #2a2a2a; /* Solid background so content doesn't show through */
            border-bottom: 3px solid var(--purdue-gold);
            box-shadow: 0 2px 5px rgba(0,0,0,0.5);
            color: #ffffff !important;
            transition: background 0.3s;
        }
        /* Sortable column pointer */
        th.sortable {
            cursor: pointer;
        }
        th.sortable:hover {
            background: #333;
        }
        /* Highlight active column */
        .table-scroll-window thead th.active-header {
            background: rgba(207, 185, 145, 0.2);
            color: var(--purdue-gold) !important;
            border-bottom: 3px solid #fff;
        }
        .sort-icon {
            margin-left: 5px;
            font-size: 0.8em;
            color: var(--purdue-gold);
        }
        /* Dynamic sizing for huge numbers in cards */
        .stat-card h3 {
            font-size: clamp(1.5rem, 4vw, 3rem);
            word-break: break-word;
            line-height: 1.1;
        }
        .loading-overlay {
            display: none;
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0,0,0,0.5);
            z-index: 5;
            justify-content: center;
            align-items: center;
            color: var(--purdue-gold);
            font-weight: bold;
        }
        .charts-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin: 20px 0;
        }
        @media (max-width: 900px) {
            .charts-row { grid-template-columns: 1fr; }
        }
        .chart-container {
            background: rgba(0, 0, 0, 0.6);
            padding: 24px;
            border-radius: 12px;
            border: 2px solid rgba(207, 185, 145, 0.3);
        }
        .chart-wrapper {
            position: relative;
            min-height: 400px; 
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
        <a href="financial.php">Financial Health</a>
        <a href="regional_disruptions.php">Regional Disruptions</a>
        <a href="critical_companies.php">Critical Companies</a>
        <a href="timeline.php">Disruption Timeline</a>
        <a href="companies.php">Company List</a>
        <a href="distributors.php" class="active">Distributors</a>
        <a href="disruptions.php">Disruption Analysis</a>
    </nav>

    <div class="container">
        <h2>Distributor Performance</h2>

        <div class="filter-section">
            <h3>Filter Distributors</h3>
            <form id="filterForm" onsubmit="return false;">
                <div class="filter-grid">
                    <div class="filter-group">
                        <label>Select Distributor:</label>
                        <select id="distributor_id">
                            <option value="">All Distributors</option>
                            <?php foreach ($distList as $dist): ?>
                                <option value="<?= $dist['CompanyID'] ?>">
                                    <?= htmlspecialchars($dist['CompanyName']) ?>
                                </option>
                            <?php endforeach; ?>
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

        <div class="content-section" style="position: relative;">
            <div id="loadingIndicator" class="loading-overlay">Updating...</div>
            <h3>Detailed Distributor List</h3>
            <div id="tableWrapper" class="table-scroll-window">
                <table class="distributor-table m-0" style="border:none;">
                    <thead>
                        <tr>
                            <th id="th-rank" style="cursor: default;">Rank</th>
                            
                            <th id="th-name" onclick="changeSort('name')" class="sortable">Distributor Name <span class="sort-icon" id="sort-name"></span></th>
                            <th id="th-volume" onclick="changeSort('volume')" class="sortable">Total Shipments <span class="sort-icon" id="sort-volume"></span></th>
                            <th id="th-quantity" onclick="changeSort('quantity')" class="sortable">Total Quantity <span class="sort-icon" id="sort-quantity"></span></th>
                            <th id="th-pct" onclick="changeSort('pct')" class="sortable">On-Time % <span class="sort-icon" id="sort-pct"></span></th>
                            <th id="th-delay" onclick="changeSort('delay')" class="sortable">Avg Delay (Days) <span class="sort-icon" id="sort-delay">▲</span></th>
                        </tr>
                    </thead>
                    <tbody id="tableBody">
                        </tbody>
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
        
        var timeout = null;

        if(sessionStorage.getItem('dist_id')) document.getElementById('distributor_id').value = sessionStorage.getItem('dist_id');

        loadData();
        updateHeaderHighlight();

        document.getElementById('distributor_id').addEventListener('change', loadData);
        
        document.getElementById('resetBtn').addEventListener('click', function() {
            sessionStorage.removeItem('dist_id');
            document.getElementById('distributor_id').value = '';
            currentSortCol = 'delay';
            currentSortDir = 'asc';
            updateSortIcons();
            updateHeaderHighlight();
            loadData();
        });

        window.changeSort = function(col) {
            if (currentSortCol === col) {
                // Toggle direction
                currentSortDir = (currentSortDir === 'desc') ? 'asc' : 'desc';
            } else {
                // New column
                currentSortCol = col;
                // Sensible defaults
                if(col === 'volume' || col === 'quantity' || col === 'pct') currentSortDir = 'desc';
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
            for(var i=0; i<icons.length; i++) {
                icons[i].textContent = '';
            }
            var symbol = (currentSortDir === 'asc') ? '▲' : '▼';
            var activeIcon = document.getElementById('sort-' + currentSortCol);
            if(activeIcon) activeIcon.textContent = symbol;
        }

        function loadData() {
            var loader = document.getElementById('loadingIndicator');
            var wrapper = document.getElementById('tableWrapper');
            if(loader) loader.style.display = 'flex';
            if(wrapper) wrapper.style.opacity = '0.3';

            var dId = document.getElementById('distributor_id').value;
            sessionStorage.setItem('dist_id', dId);

            var params = 'ajax=1' + 
                '&dist_id=' + encodeURIComponent(dId) +
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
                            document.getElementById('tableBody').innerHTML = '<tr><td colspan="6" class="no-data">Error: ' + esc(r.message) + '</td></tr>';
                        }
                    } catch(e) {
                        console.error("JSON Error", e);
                        document.getElementById('tableBody').innerHTML = '<tr><td colspan="6" class="no-data">Data Error</td></tr>';
                    }
                }
                if(loader) loader.style.display = 'none';
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
                tbody.innerHTML = '<tr><td colspan="6" class="no-data" style="text-align:center; padding:30px;">No distributors found.</td></tr>';
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
                    '<td>' + parseInt(d.volume).toLocaleString() + '</td>' +
                    '<td>' + parseInt(d.quantity).toLocaleString() + '</td>' +
                    '<td><span class="performance-badge ' + badgeClass + '">' + displayPct + '</span></td>' +
                    '<td>' + parseFloat(d.delay).toFixed(1) + '</td>';
                
                tbody.appendChild(tr);
            }
        }

        function renderCharts(dData, vData) {
            // Adjust height if there are many items
            var itemCount = dData.labels.length;
            var minHeight = 400;
            
            if (itemCount > 20) {
                var newHeight = itemCount * 25; 
                document.querySelectorAll('.chart-wrapper').forEach(function(el) {
                    el.style.height = newHeight + 'px';
                });
            } else {
                document.querySelectorAll('.chart-wrapper').forEach(function(el) {
                    el.style.height = minHeight + 'px';
                });
            }

            // 1. DELAY CHART
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

            // 2. VOLUME CHART
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