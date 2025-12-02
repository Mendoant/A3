<?php
// erp/financial.php - Financial Health Overview with AJAX
require_once '../config.php';
requireLogin();

// only senior managers can access erp module
if (!hasRole('SeniorManager')) {
    header('Location: ../scm/dashboard.php');
    exit;
}

$pdo = getPDO();

// Get filters
$region = isset($_GET['region']) ? $_GET['region'] : '';
$tierLevel = isset($_GET['tier']) ? $_GET['tier'] : '';
$minHealth = isset($_GET['min_health']) ? $_GET['min_health'] : '';

// Build where clause for filtering
$where = array('1=1');
$params = array();

if (!empty($region)) {
    $where[] = "l.ContinentName = :region";
    $params[':region'] = $region;
}

if (!empty($tierLevel)) {
    $where[] = "c.TierLevel = :tier";
    $params[':tier'] = $tierLevel;
}

$whereClause = 'WHERE ' . implode(' AND ', $where);

// Query: Average financial health by company (sorted)
// get the most recent health score for each company
$sql = "SELECT 
            c.CompanyID,
            c.CompanyName,
            c.Type,
            c.TierLevel,
            l.ContinentName,
            l.CountryName,
            fr.RepYear,
            fr.Quarter,
            fr.HealthScore
        FROM Company c
        LEFT JOIN Location l ON c.LocationID = l.LocationID
        LEFT JOIN FinancialReport fr ON c.CompanyID = fr.CompanyID
        $whereClause
        ORDER BY c.CompanyID, fr.RepYear DESC, fr.Quarter DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$allReports = $stmt->fetchAll();

// Group by company and get only the latest report for each
$companies = array();
$seenCompanies = array();

foreach ($allReports as $report) {
    $cid = $report['CompanyID'];
    
    // only take the first (most recent) report for each company
    if (!isset($seenCompanies[$cid])) {
        // apply min health filter if set
        if (!empty($minHealth)) {
            if ($report['HealthScore'] !== null && floatval($report['HealthScore']) >= floatval($minHealth)) {
                $companies[] = $report;
                $seenCompanies[$cid] = true;
            }
        } else {
            $companies[] = $report;
            $seenCompanies[$cid] = true;
        }
    }
}

// Sort by health score descending (best companies first)
usort($companies, function($a, $b) {
    $aScore = $a['HealthScore'] !== null ? floatval($a['HealthScore']) : 0;
    $bScore = $b['HealthScore'] !== null ? floatval($b['HealthScore']) : 0;
    return $bScore - $aScore; // sort descending
});

// Calculate summary stats
$totalCompanies = count($companies);
$avgHealth = 0;
$healthyCount = 0;  // >= 75
$warningCount = 0;  // 50-74
$criticalCount = 0; // < 50

foreach ($companies as $c) {
    if ($c['HealthScore'] !== null) {
        $score = floatval($c['HealthScore']);
        $avgHealth += $score;
        
        if ($score >= 75) {
            $healthyCount++;
        } elseif ($score >= 50) {
            $warningCount++;
        } else {
            $criticalCount++;
        }
    }
}

$avgHealth = $totalCompanies > 0 ? round($avgHealth / $totalCompanies, 1) : 0;

// Health distribution by region
$regionSql = "SELECT 
                l.ContinentName,
                AVG(fr.HealthScore) as avgHealthScore,
                COUNT(DISTINCT c.CompanyID) as companyCount
              FROM Company c
              LEFT JOIN Location l ON c.LocationID = l.LocationID
              LEFT JOIN FinancialReport fr ON c.CompanyID = fr.CompanyID
              WHERE fr.HealthScore IS NOT NULL
              GROUP BY l.ContinentName
              ORDER BY avgHealthScore DESC";

$stmt = $pdo->query($regionSql);
$regionHealth = $stmt->fetchAll();

// Health distribution by tier level
$tierSql = "SELECT 
                c.TierLevel,
                AVG(fr.HealthScore) as avgHealthScore,
                COUNT(DISTINCT c.CompanyID) as companyCount
            FROM Company c
            LEFT JOIN FinancialReport fr ON c.CompanyID = fr.CompanyID
            WHERE fr.HealthScore IS NOT NULL
            GROUP BY c.TierLevel
            ORDER BY c.TierLevel";

$stmt = $pdo->query($tierSql);
$tierHealth = $stmt->fetchAll();

// AJAX response
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    echo json_encode(array(
        'success' => true,
        'data' => array(
            'companies' => $companies,
            'summary' => array(
                'totalCompanies' => $totalCompanies,
                'avgHealth' => $avgHealth,
                'healthyCount' => $healthyCount,
                'warningCount' => $warningCount,
                'criticalCount' => $criticalCount
            ),
            'regionHealth' => $regionHealth,
            'tierHealth' => $tierHealth
        )
    ));
    exit;
}

// Get filter options (only on initial load)
$allRegions = $pdo->query("SELECT DISTINCT ContinentName FROM Location ORDER BY ContinentName")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Financial Health - ERP</title>
    <link rel="stylesheet" href="../assets/styles.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .filter-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-top: 20px; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin: 30px 0; }
        .stat-card { background: rgba(0,0,0,0.6); padding: 24px; border-radius: 8px; border: 2px solid rgba(207,185,145,0.3); text-align: center; }
        .stat-card h3 { margin: 0; font-size: 2rem; color: var(--purdue-gold); }
        .stat-card p { margin: 8px 0 0 0; color: var(--text-light); }
        .stat-card.healthy h3 { color: #4caf50; }
        .stat-card.warning h3 { color: #ff9800; }
        .stat-card.critical h3 { color: #f44336; }
        .chart-container { background: rgba(0,0,0,0.6); padding: 24px; border-radius: 12px; border: 2px solid rgba(207,185,145,0.3); margin: 20px 0; }
        .chart-wrapper { position: relative; height: 350px; }
        .health-badge { display: inline-block; padding: 4px 12px; border-radius: 12px; font-weight: bold; font-size: 0.9rem; }
        .health-good { background: #4caf50; color: white; }
        .health-warning { background: #ff9800; color: white; }
        .health-bad { background: #f44336; color: white; }
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
        <a href="financial.php" class="active">Financial Health</a>
        <a href="regional_disruptions.php">Regional Disruptions</a>
        <a href="critical_companies.php">Critical Companies</a>
        <a href="timeline.php">Disruption Timeline</a>
        <a href="companies.php">Company List</a>
        <a href="distributors.php">Distributors</a>
    </nav>

    <div class="container">
        <h2>Financial Health Overview</h2>

        <div class="content-section">
            <h3>Filter Companies</h3>
            <form id="filterForm">
                <div class="filter-grid">
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
                        <label>Tier Level:</label>
                        <select id="tier">
                            <option value="">All Tiers</option>
                            <option value="1" <?= $tierLevel == '1' ? 'selected' : '' ?>>Tier 1</option>
                            <option value="2" <?= $tierLevel == '2' ? 'selected' : '' ?>>Tier 2</option>
                            <option value="3" <?= $tierLevel == '3' ? 'selected' : '' ?>>Tier 3</option>
                        </select>
                    </div>
                    <div>
                        <label>Min Health Score:</label>
                        <input type="number" id="min_health" min="0" max="100" step="1" placeholder="0-100" value="<?= htmlspecialchars($minHealth) ?>">
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
                <h3 id="stat-total"><?= $totalCompanies ?></h3>
                <p>Total Companies</p>
            </div>
            <div class="stat-card">
                <h3 id="stat-avg"><?= $avgHealth ?></h3>
                <p>Avg Health Score</p>
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

        <!-- Charts Row -->
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(500px, 1fr)); gap: 20px;">
            <!-- Regional Health Chart -->
            <div class="chart-container">
                <h3>Avg Health Score by Region</h3>
                <div class="chart-wrapper">
                    <canvas id="regionChart"></canvas>
                </div>
            </div>

            <!-- Tier Health Chart -->
            <div class="chart-container">
                <h3>Avg Health Score by Tier Level</h3>
                <div class="chart-wrapper">
                    <canvas id="tierChart"></canvas>
                </div>
            </div>
        </div>

        <!-- Company Table (sorted by health score) -->
        <div class="content-section">
            <h3>Companies Ranked by Financial Health (<span id="recordCount"><?= count($companies) ?></span> companies)</h3>
            <div id="tableWrapper" style="overflow-x: auto;">
                <?php if (count($companies) > 0): ?>
                <table>
                    <thead>
                        <tr>
                            <th>Rank</th>
                            <th>Company</th>
                            <th>Type</th>
                            <th>Tier</th>
                            <th>Region</th>
                            <th>Health Score</th>
                            <th>Latest Report</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $rank = 1;
                        foreach ($companies as $c): 
                            $score = $c['HealthScore'] !== null ? floatval($c['HealthScore']) : 0;
                            $badgeClass = 'health-bad';
                            if ($score >= 75) {
                                $badgeClass = 'health-good';
                            } elseif ($score >= 50) {
                                $badgeClass = 'health-warning';
                            }
                        ?>
                        <tr>
                            <td><strong><?= $rank++ ?></strong></td>
                            <td><?= htmlspecialchars($c['CompanyName']) ?></td>
                            <td><?= htmlspecialchars($c['Type']) ?></td>
                            <td>Tier <?= $c['TierLevel'] ?></td>
                            <td><?= htmlspecialchars($c['ContinentName']) ?></td>
                            <td>
                                <span class="health-badge <?= $badgeClass ?>">
                                    <?= round($score, 1) ?>/100
                                </span>
                            </td>
                            <td><?= $c['Quarter'] ?> <?= $c['RepYear'] ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                <p style="text-align: center; padding: 40px; color: var(--text-light);">No financial data found for the selected filters.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
    (function() {
        var form = document.getElementById('filterForm');
        var regionChart = null;
        var tierChart = null;
        
        // load financial data via ajax
        function load() {
            document.getElementById('tableWrapper').innerHTML = '<div class="loading">Loading...</div>';
            
            var params = 'ajax=1&region=' + encodeURIComponent(document.getElementById('region').value) +
                        '&tier=' + encodeURIComponent(document.getElementById('tier').value) +
                        '&min_health=' + encodeURIComponent(document.getElementById('min_health').value);
            
            var xhr = new XMLHttpRequest();
            xhr.open('GET', 'financial.php?' + params, true);
            xhr.onload = function() {
                if (xhr.status === 200) {
                    var r = JSON.parse(xhr.responseText);
                    if (r.success) {
                        var d = r.data;
                        var s = d.summary;
                        
                        // update summary stats
                        document.getElementById('stat-total').textContent = s.totalCompanies;
                        document.getElementById('stat-avg').textContent = s.avgHealth;
                        document.getElementById('stat-healthy').textContent = s.healthyCount;
                        document.getElementById('stat-warning').textContent = s.warningCount;
                        document.getElementById('stat-critical').textContent = s.criticalCount;
                        
                        // regional health chart
                        var regionLabels = [];
                        var regionScores = [];
                        
                        for (var i = 0; i < d.regionHealth.length; i++) {
                            regionLabels.push(d.regionHealth[i].ContinentName);
                            regionScores.push(parseFloat(d.regionHealth[i].avgHealthScore));
                        }
                        
                        if (regionChart) regionChart.destroy();
                        
                        var ctx1 = document.getElementById('regionChart').getContext('2d');
                        regionChart = new Chart(ctx1, {
                            type: 'bar',
                            data: {
                                labels: regionLabels,
                                datasets: [{
                                    label: 'Avg Health Score',
                                    data: regionScores,
                                    backgroundColor: '#CFB991'
                                }]
                            },
                            options: {
                                responsive: true,
                                maintainAspectRatio: false,
                                scales: {
                                    y: { 
                                        beginAtZero: true, 
                                        max: 100,
                                        ticks: { color: 'white' }, 
                                        grid: { color: 'rgba(207,185,145,0.1)' } 
                                    },
                                    x: { ticks: { color: 'white' }, grid: { color: 'rgba(207,185,145,0.1)' } }
                                },
                                plugins: { legend: { labels: { color: 'white' } } }
                            }
                        });
                        
                        // tier health chart
                        var tierLabels = [];
                        var tierScores = [];
                        
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
                                responsive: true,
                                maintainAspectRatio: false,
                                scales: {
                                    y: { 
                                        beginAtZero: true, 
                                        max: 100,
                                        ticks: { color: 'white' }, 
                                        grid: { color: 'rgba(207,185,145,0.1)' } 
                                    },
                                    x: { ticks: { color: 'white' }, grid: { color: 'rgba(207,185,145,0.1)' } }
                                },
                                plugins: { legend: { labels: { color: 'white' } } }
                            }
                        });
                        
                        // build table
                        buildTable(d.companies);
                    }
                }
            };
            xhr.send();
        }
        
        // build company table sorted by health score
        function buildTable(companies) {
            document.getElementById('recordCount').textContent = companies.length;
            
            if (companies.length === 0) {
                document.getElementById('tableWrapper').innerHTML = 
                    '<p style="text-align:center;padding:40px;color:var(--text-light)">No financial data found.</p>';
                return;
            }
            
            var html = '<table><thead><tr><th>Rank</th><th>Company</th><th>Type</th><th>Tier</th><th>Region</th><th>Health Score</th><th>Latest Report</th></tr></thead><tbody>';
            
            for (var i = 0; i < companies.length; i++) {
                var c = companies[i];
                var score = c.HealthScore ? parseFloat(c.HealthScore) : 0;
                var badgeClass = 'health-bad';
                if (score >= 75) badgeClass = 'health-good';
                else if (score >= 50) badgeClass = 'health-warning';
                
                html += '<tr>' +
                    '<td><strong>' + (i + 1) + '</strong></td>' +
                    '<td>' + esc(c.CompanyName) + '</td>' +
                    '<td>' + esc(c.Type) + '</td>' +
                    '<td>Tier ' + c.TierLevel + '</td>' +
                    '<td>' + esc(c.ContinentName) + '</td>' +
                    '<td><span class="health-badge ' + badgeClass + '">' + score.toFixed(1) + '/100</span></td>' +
                    '<td>' + c.Quarter + ' ' + c.RepYear + '</td>' +
                    '</tr>';
            }
            
            document.getElementById('tableWrapper').innerHTML = html + '</tbody></table>';
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
            document.getElementById('region').value = '';
            document.getElementById('tier').value = '';
            document.getElementById('min_health').value = '';
            load();
        });
        
    })();
    </script>
</body>
</html>