<?php
// distributors.php - Distributor performance rankings with AJAX
require_once '../config.php';
requireLogin();

// kick out supply chain managers
if (!hasRole('SeniorManager')) {
    header('Location: ../scm/dashboard.php');
    exit;
}

$pdo = getPDO();

// grab filter values
$sortBy = isset($_GET['sort_by']) ? $_GET['sort_by'] : 'volume';
$startDate = isset($_GET['start_date']) ? $_GET['start_date'] : '';
$endDate = isset($_GET['end_date']) ? $_GET['end_date'] : '';

// build date filter for query
$dateFilter = '';
$dateParams = array();
if ($startDate !== '') {
    $dateFilter .= " AND s.ActualDate >= ?";
    $dateParams[] = $startDate;
}
if ($endDate !== '') {
    $dateFilter .= " AND s.ActualDate <= ?";
    $dateParams[] = $endDate;
}

// grab distributor performance data with all calculations in SQL
$sql = "SELECT 
            d.CompanyID,
            c.CompanyName,
            COUNT(CASE WHEN s.ActualDate IS NOT NULL THEN s.ShipmentID END) as totalShipments,
            SUM(CASE WHEN s.ActualDate IS NOT NULL THEN s.Quantity ELSE 0 END) as totalQuantity,
            SUM(CASE WHEN s.ActualDate IS NOT NULL AND s.ActualDate <= s.PromisedDate THEN 1 ELSE 0 END) as onTimeCount,
            CASE 
                WHEN COUNT(CASE WHEN s.ActualDate IS NOT NULL THEN s.ShipmentID END) > 0
                THEN (SUM(CASE WHEN s.ActualDate IS NOT NULL AND s.ActualDate <= s.PromisedDate THEN 1 ELSE 0 END) * 100.0 / 
                      COUNT(CASE WHEN s.ActualDate IS NOT NULL THEN s.ShipmentID END))
                ELSE 0
            END as onTimePercent,
            AVG(CASE 
                WHEN s.ActualDate IS NOT NULL AND s.PromisedDate IS NOT NULL AND s.ActualDate > s.PromisedDate
                THEN DATEDIFF(s.ActualDate, s.PromisedDate)
                ELSE NULL
            END) as avgDelay
        FROM Distributor d
        JOIN Company c ON d.CompanyID = c.CompanyID
        LEFT JOIN Shipping s ON d.CompanyID = s.DistributorID" . 
        ($dateFilter ? " AND s.ActualDate IS NOT NULL" . $dateFilter : "") . "
        GROUP BY d.CompanyID, c.CompanyName
        HAVING totalShipments > 0";

$stmt = $pdo->prepare($sql);
$stmt->execute($dateParams);
$distributors = $stmt->fetchAll();

// convert null avgDelay to 0 for display purposes
foreach ($distributors as $key => $dist) {
    $distributors[$key]['onTimePercent'] = floatval($dist['onTimePercent']);
    $distributors[$key]['avgDelay'] = $dist['avgDelay'] !== null ? floatval($dist['avgDelay']) : 0;
}

// sort based on selected criteria
if ($sortBy === 'delay') {
    // sort by average delay descending (worst first)
    usort($distributors, function($a, $b) {
        if ($b['avgDelay'] == $a['avgDelay']) return 0;
        return ($b['avgDelay'] > $a['avgDelay']) ? 1 : -1;
    });
} else {
    // default sort by volume descending (best first)
    usort($distributors, function($a, $b) {
        return intval($b['totalShipments']) - intval($a['totalShipments']);
    });
}

// prepare chart data - top 10 by volume
$chartDistributors = array_slice($distributors, 0, 10);
$chartLabels = array();
$chartValues = array();
$chartColors = array();

foreach ($chartDistributors as $dist) {
    $chartLabels[] = $dist['CompanyName'];
    $chartValues[] = intval($dist['totalShipments']);
    
    // color based on on-time percentage
    $onTimePercent = $dist['onTimePercent'];
    if ($onTimePercent >= 90) {
        $chartColors[] = '#4caf50'; // green
    } elseif ($onTimePercent >= 75) {
        $chartColors[] = '#ffc107'; // yellow
    } elseif ($onTimePercent >= 50) {
        $chartColors[] = '#ff9800'; // orange
    } else {
        $chartColors[] = '#f44336'; // red
    }
}

// AJAX response - return json
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    echo json_encode(array(
        'success' => true,
        'distributors' => $distributors,
        'chartLabels' => $chartLabels,
        'chartValues' => $chartValues,
        'chartColors' => $chartColors
    ));
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Distributor Performance - ERP System</title>
    <link rel="stylesheet" href="../assets/styles.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
    <style>
        .metrics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .metric-card {
            background: rgba(207,185,145,0.1);
            padding: 20px;
            border-radius: 8px;
            border: 1px solid rgba(207,185,145,0.3);
        }
        .metric-value {
            font-size: 2em;
            font-weight: bold;
            color: #CFB991;
        }
        .metric-label {
            color: rgba(255,255,255,0.7);
            margin-top: 5px;
        }
        .filter-section {
            background: rgba(0,0,0,0.7);
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 30px;
        }
        .filter-row {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            align-items: flex-end;
        }
        .filter-group {
            flex: 1;
            min-width: 180px;
        }
        .filter-group label {
            display: block;
            margin-bottom: 5px;
            color: #CFB991;
            font-weight: bold;
        }
        .filter-group input,
        .filter-group select {
            width: 100%;
            padding: 8px;
            border: 1px solid #CFB991;
            background: rgba(0,0,0,0.5);
            color: white;
            border-radius: 4px;
        }
        .btn-filter {
            padding: 8px 20px;
            background: #CFB991;
            color: #000;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-weight: bold;
            align-self: flex-end;
        }
        .btn-filter:hover {
            background: #b89968;
        }
        .chart-container {
            background: rgba(0,0,0,0.6);
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 30px;
        }
        .chart-wrapper {
            position: relative;
            height: 400px;
        }
        .distributor-table {
            width: 100%;
            border-collapse: collapse;
            background: rgba(0,0,0,0.6);
        }
        .distributor-table th {
            background: #CFB991;
            color: #000;
            padding: 12px;
            text-align: left;
            font-weight: bold;
            cursor: pointer;
            user-select: none;
        }
        .distributor-table th:hover {
            background: #b89968;
        }
        .distributor-table td {
            padding: 10px 12px;
            border-bottom: 1px solid rgba(207,185,145,0.2);
        }
        .distributor-table tr:hover {
            background: rgba(207,185,145,0.1);
        }
        .performance-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 4px;
            font-size: 0.9em;
            font-weight: bold;
        }
        .perf-excellent { background: #4caf50; color: white; }
        .perf-good { background: #ffc107; color: black; }
        .perf-fair { background: #ff9800; color: white; }
        .perf-poor { background: #f44336; color: white; }
        .sort-indicator {
            margin-left: 5px;
            font-size: 0.8em;
            opacity: 0.6;
        }
        .section-title {
            color: #CFB991;
            margin-bottom: 15px;
            font-size: 1.3em;
        }
    </style>
</head>
<body>
    <div class="container">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <h1>Distributor Performance</h1>
            <a href="../logout.php" style="background: #f44336; color: white; padding: 10px 20px; text-decoration: none; border-radius: 4px;">Logout</a>
        </div>

        <nav class="container" style="background: rgba(0,0,0,0.8); padding: 15px 30px; margin-bottom: 30px; border-radius: 8px; display: flex; gap: 20px; flex-wrap: wrap;">
            <a href="dashboard.php">Dashboard</a>
            <a href="financial.php">Financial Health</a>
            <a href="regional_disruptions.php">Regional Disruptions</a>
            <a href="critical_companies.php">Critical Companies</a>
            <a href="timeline.php">Disruption Timeline</a>
            <a href="companies.php">Company List</a>
            <a href="distributors.php" class="active">Distributors</a>
            <a href="disruptions.php">Disruptions</a>
        </nav>

        <div class="filter-section">
            <form id="filterForm">
                <div class="filter-row">
                    <div class="filter-group">
                        <label for="sort_by">Sort By</label>
                        <select id="sort_by" name="sort_by">
                            <option value="volume" <?= $sortBy === 'volume' ? 'selected' : '' ?>>Total Shipments (High to Low)</option>
                            <option value="delay" <?= $sortBy === 'delay' ? 'selected' : '' ?>>Average Delay (Worst to Best)</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label for="start_date">Start Date</label>
                        <input type="date" id="start_date" name="start_date" value="<?= htmlspecialchars($startDate) ?>">
                    </div>
                    <div class="filter-group">
                        <label for="end_date">End Date</label>
                        <input type="date" id="end_date" name="end_date" value="<?= htmlspecialchars($endDate) ?>">
                    </div>
                    <button type="submit" class="btn-filter">Apply Filters</button>
                </div>
            </form>
        </div>

        <div class="metrics-grid">
            <div class="metric-card">
                <div class="metric-value"><?= count($distributors) ?></div>
                <div class="metric-label">Active Distributors</div>
            </div>
            <div class="metric-card">
                <div class="metric-value"><?= number_format(array_sum(array_column($distributors, 'totalShipments'))) ?></div>
                <div class="metric-label">Total Shipments</div>
            </div>
            <div class="metric-card">
                <div class="metric-value"><?= number_format(array_sum(array_column($distributors, 'totalQuantity'))) ?></div>
                <div class="metric-label">Total Quantity</div>
            </div>
            <div class="metric-card">
                <?php
                $totalShip = array_sum(array_column($distributors, 'totalShipments'));
                $totalOnTime = array_sum(array_column($distributors, 'onTimeCount'));
                $overallPercent = $totalShip > 0 ? ($totalOnTime / $totalShip) * 100 : 0;
                ?>
                <div class="metric-value"><?= number_format($overallPercent, 1) ?>%</div>
                <div class="metric-label">Overall On-Time Rate</div>
            </div>
        </div>

        <div class="chart-container">
            <h3 class="section-title">Top 10 Distributors by Volume</h3>
            <div class="chart-wrapper">
                <canvas id="volumeChart"></canvas>
            </div>
        </div>

        <div style="background: rgba(0,0,0,0.6); padding: 20px; border-radius: 8px;">
            <h3 class="section-title">All Distributors</h3>
            <table class="distributor-table">
                <thead>
                    <tr>
                        <th onclick="sortTable(0)" data-sort="asc">Rank <span class="sort-indicator"></span></th>
                        <th onclick="sortTable(1)" data-sort="asc">Distributor Name <span class="sort-indicator"></span></th>
                        <th onclick="sortTable(2)" data-sort="asc">Total Shipments <span class="sort-indicator"></span></th>
                        <th onclick="sortTable(3)" data-sort="asc">Total Quantity <span class="sort-indicator"></span></th>
                        <th onclick="sortTable(4)" data-sort="asc">On-Time % <span class="sort-indicator"></span></th>
                        <th onclick="sortTable(5)" data-sort="asc">Avg Delay (days) <span class="sort-indicator"></span></th>
                    </tr>
                </thead>
                <tbody id="distributorTableBody">
                    <?php foreach ($distributors as $idx => $dist): ?>
                        <tr>
                            <td><?= $idx + 1 ?></td>
                            <td><strong><?= htmlspecialchars($dist['CompanyName']) ?></strong></td>
                            <td><?= number_format($dist['totalShipments']) ?></td>
                            <td><?= number_format($dist['totalQuantity']) ?></td>
                            <td>
                                <?php
                                $onTimePercent = $dist['onTimePercent'];
                                $perfClass = 'perf-poor';
                                if ($onTimePercent >= 90) $perfClass = 'perf-excellent';
                                elseif ($onTimePercent >= 75) $perfClass = 'perf-good';
                                elseif ($onTimePercent >= 50) $perfClass = 'perf-fair';
                                ?>
                                <span class="performance-badge <?= $perfClass ?>"><?= number_format($onTimePercent, 1) ?>%</span>
                            </td>
                            <td><?= number_format($dist['avgDelay'], 1) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($distributors)): ?>
                        <tr>
                            <td colspan="6" style="text-align: center; padding: 30px; color: #999;">
                                No distributor data available for the selected date range.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <script>
    (function() {
        var form = document.getElementById('filterForm');
        var chartInstance = null;
        var currentDistributors = <?= json_encode($distributors) ?>;
        
        // initialize chart on page load
        initChart(<?= json_encode($chartLabels) ?>, <?= json_encode($chartValues) ?>, <?= json_encode($chartColors) ?>);
        
        function initChart(labels, values, colors) {
            var ctx = document.getElementById('volumeChart').getContext('2d');
            
            // destroy existing chart if it exists
            if (chartInstance) {
                chartInstance.destroy();
            }
            
            chartInstance = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'Total Shipments',
                        data: values,
                        backgroundColor: colors,
                        borderColor: 'rgba(207,185,145,0.5)',
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: { color: 'white' },
                            grid: { color: 'rgba(207,185,145,0.1)' }
                        },
                        x: {
                            ticks: { 
                                color: 'white',
                                maxRotation: 45,
                                minRotation: 45
                            },
                            grid: { color: 'rgba(207,185,145,0.1)' }
                        }
                    },
                    plugins: {
                        legend: {
                            display: true,
                            labels: { color: 'white' }
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return 'Shipments: ' + context.parsed.y.toLocaleString();
                                }
                            }
                        }
                    }
                }
            });
        }
        
        // handle form submission with AJAX
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            loadDistributors();
            return false;
        });
        
        function loadDistributors() {
            var sortBy = document.getElementById('sort_by').value;
            var startDate = document.getElementById('start_date').value;
            var endDate = document.getElementById('end_date').value;
            
            var params = 'ajax=1' +
                '&sort_by=' + encodeURIComponent(sortBy) +
                '&start_date=' + encodeURIComponent(startDate) +
                '&end_date=' + encodeURIComponent(endDate);
            
            var xhr = new XMLHttpRequest();
            xhr.open('GET', 'distributors.php?' + params, true);
            xhr.onload = function() {
                if (xhr.status === 200) {
                    var response = JSON.parse(xhr.responseText);
                    if (response.success) {
                        currentDistributors = response.distributors;
                        updateTable(currentDistributors);
                        updateMetrics(currentDistributors);
                        initChart(response.chartLabels, response.chartValues, response.chartColors);
                    }
                }
            };
            xhr.send();
        }
        
        function updateTable(distributors) {
            var tbody = document.getElementById('distributorTableBody');
            tbody.innerHTML = '';
            
            if (distributors.length === 0) {
                tbody.innerHTML = '<tr><td colspan="6" style="text-align: center; padding: 30px; color: #999;">No distributor data available for the selected date range.</td></tr>';
                return;
            }
            
            distributors.forEach(function(dist, idx) {
                var row = document.createElement('tr');
                
                // determine performance badge class
                var onTimePercent = parseFloat(dist.onTimePercent);
                var perfClass = 'perf-poor';
                if (onTimePercent >= 90) perfClass = 'perf-excellent';
                else if (onTimePercent >= 75) perfClass = 'perf-good';
                else if (onTimePercent >= 50) perfClass = 'perf-fair';
                
                row.innerHTML = 
                    '<td>' + (idx + 1) + '</td>' +
                    '<td><strong>' + escapeHtml(dist.CompanyName) + '</strong></td>' +
                    '<td>' + parseInt(dist.totalShipments).toLocaleString() + '</td>' +
                    '<td>' + parseInt(dist.totalQuantity).toLocaleString() + '</td>' +
                    '<td><span class="performance-badge ' + perfClass + '">' + onTimePercent.toFixed(1) + '%</span></td>' +
                    '<td>' + parseFloat(dist.avgDelay).toFixed(1) + '</td>';
                
                tbody.appendChild(row);
            });
        }
        
        function updateMetrics(distributors) {
            var totalShip = 0;
            var totalQty = 0;
            var totalOnTime = 0;
            
            distributors.forEach(function(dist) {
                totalShip += parseInt(dist.totalShipments);
                totalQty += parseInt(dist.totalQuantity);
                totalOnTime += parseInt(dist.onTimeCount);
            });
            
            var overallPercent = totalShip > 0 ? (totalOnTime / totalShip) * 100 : 0;
            
            var metricCards = document.querySelectorAll('.metric-card .metric-value');
            metricCards[0].textContent = distributors.length;
            metricCards[1].textContent = totalShip.toLocaleString();
            metricCards[2].textContent = totalQty.toLocaleString();
            metricCards[3].textContent = overallPercent.toFixed(1) + '%';
        }
        
        function escapeHtml(text) {
            if (text === null || text === undefined) return '';
            var map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return String(text).replace(/[&<>"']/g, function(m) { return map[m]; });
        }
        
        // sort table by column
        window.sortTable = function(columnIndex) {
            var table = document.querySelector('.distributor-table');
            var headers = table.querySelectorAll('th');
            var currentHeader = headers[columnIndex];
            var currentSort = currentHeader.getAttribute('data-sort');
            var newSort = currentSort === 'asc' ? 'desc' : 'asc';
            
            // clear all sort indicators
            headers.forEach(function(header) {
                var indicator = header.querySelector('.sort-indicator');
                if (indicator) indicator.textContent = '';
            });
            
            // set new sort indicator
            var indicator = currentHeader.querySelector('.sort-indicator');
            indicator.textContent = newSort === 'asc' ? '▲' : '▼';
            currentHeader.setAttribute('data-sort', newSort);
            
            // sort the distributors array
            currentDistributors.sort(function(a, b) {
                var valA, valB;
                
                switch(columnIndex) {
                    case 0: // Rank (use original array index, dont sort)
                        return 0;
                    case 1: // Name
                        valA = (a.CompanyName || '').toLowerCase();
                        valB = (b.CompanyName || '').toLowerCase();
                        break;
                    case 2: // Total Shipments
                        valA = parseInt(a.totalShipments);
                        valB = parseInt(b.totalShipments);
                        break;
                    case 3: // Total Quantity
                        valA = parseInt(a.totalQuantity);
                        valB = parseInt(b.totalQuantity);
                        break;
                    case 4: // On-Time %
                        valA = parseFloat(a.onTimePercent);
                        valB = parseFloat(b.onTimePercent);
                        break;
                    case 5: // Avg Delay
                        valA = parseFloat(a.avgDelay);
                        valB = parseFloat(b.avgDelay);
                        break;
                    default:
                        return 0;
                }
                
                if (valA < valB) return newSort === 'asc' ? -1 : 1;
                if (valA > valB) return newSort === 'asc' ? 1 : -1;
                return 0;
            });
            
            updateTable(currentDistributors);
        };
    })();
    </script>
</body>
</html>