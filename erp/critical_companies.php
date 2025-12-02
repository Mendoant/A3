<?php
// erp/critical_companies.php - Critical Companies Analysis with AJAX
require_once '../config.php';
requireLogin();

// only senior managers can access erp module
if (!hasRole('SeniorManager')) {
    header('Location: ../scm/dashboard.php');
    exit;
}

$pdo = getPDO();

// Get filters
$minCriticality = isset($_GET['min_criticality']) ? $_GET['min_criticality'] : '';
$region = isset($_GET['region']) ? $_GET['region'] : '';
$tierLevel = isset($_GET['tier']) ? $_GET['tier'] : '';

// Criticality Score Formula: 
// Criticality = (Number of Downstream Companies) * (High Impact Disruption Count)
// this identifies companies that are both central to the supply chain AND frequently disrupted

// Build the company list manually to avoid complex queries
$companies = array();

// Get all companies first
$sql = "SELECT 
            c.CompanyID,
            c.CompanyName,
            c.Type,
            c.TierLevel,
            l.ContinentName,
            l.CountryName
        FROM Company c
        LEFT JOIN Location l ON c.LocationID = l.LocationID
        WHERE 1=1";

$params = array();

if (!empty($region)) {
    $sql .= " AND l.ContinentName = ?";
    $params[] = $region;
}

if (!empty($tierLevel)) {
    $sql .= " AND c.TierLevel = ?";
    $params[] = $tierLevel;
}

$sql .= " ORDER BY c.CompanyName";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$allCompanies = $stmt->fetchAll();

// For each company, calculate downstream count and high impact disruptions
foreach ($allCompanies as $comp) {
    $cid = $comp['CompanyID'];
    
    // Count downstream companies (companies that depend on this one)
    // if Company A is upstream, then downstream companies depend on A
    $stmt1 = $pdo->prepare("SELECT COUNT(DISTINCT DownstreamCompanyID) as cnt FROM DependsOn WHERE UpstreamCompanyID = ?");
    $stmt1->execute(array($cid));
    $result1 = $stmt1->fetch();
    $downstreamCount = intval($result1['cnt']);
    
    // Count high impact disruptions for this company
    $stmt2 = $pdo->prepare("SELECT COUNT(DISTINCT de.EventID) as cnt 
                            FROM DisruptionEvent de 
                            JOIN ImpactsCompany ic ON de.EventID = ic.EventID 
                            WHERE ic.AffectedCompanyID = ? AND ic.ImpactLevel = 'High'");
    $stmt2->execute(array($cid));
    $result2 = $stmt2->fetch();
    $highImpactCount = intval($result2['cnt']);
    
    // Calculate criticality score
    $criticalityScore = $downstreamCount * $highImpactCount;
    
    // Apply min criticality filter
    if (!empty($minCriticality) && $criticalityScore < intval($minCriticality)) {
        continue;  // skip this company
    }
    
    $companies[] = array(
        'CompanyID' => $comp['CompanyID'],
        'CompanyName' => $comp['CompanyName'],
        'Type' => $comp['Type'],
        'TierLevel' => $comp['TierLevel'],
        'ContinentName' => $comp['ContinentName'],
        'CountryName' => $comp['CountryName'],
        'downstreamCount' => $downstreamCount,
        'highImpactCount' => $highImpactCount,
        'criticalityScore' => $criticalityScore
    );
}

// Sort by criticality score descending (most critical first)
usort($companies, function($a, $b) {
    return $b['criticalityScore'] - $a['criticalityScore'];
});

// Calculate summary stats
// thresholds: critical >= 10, high risk >= 5
$totalCompanies = count($companies);
$criticalCount = 0;  // score >= 10
$highRiskCount = 0;  // score >= 5
$avgCriticality = 0;

foreach ($companies as $c) {
    $score = intval($c['criticalityScore']);
    $avgCriticality += $score;
    
    if ($score >= 10) {
        $criticalCount++;
    } elseif ($score >= 5) {
        $highRiskCount++;
    }
}

$avgCriticality = $totalCompanies > 0 ? round($avgCriticality / $totalCompanies, 1) : 0;

// Get top 10 for chart
$topCompanies = array_slice($companies, 0, 10);

// AJAX response
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    echo json_encode(array(
        'success' => true,
        'data' => array(
            'companies' => $companies,
            'topCompanies' => $topCompanies,
            'summary' => array(
                'totalCompanies' => $totalCompanies,
                'criticalCount' => $criticalCount,
                'highRiskCount' => $highRiskCount,
                'avgCriticality' => $avgCriticality
            )
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
    <title>Critical Companies - ERP</title>
    <link rel="stylesheet" href="../assets/styles.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .filter-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-top: 20px; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin: 30px 0; }
        .stat-card { background: rgba(0,0,0,0.6); padding: 24px; border-radius: 8px; border: 2px solid rgba(207,185,145,0.3); text-align: center; }
        .stat-card h3 { margin: 0; font-size: 2rem; color: var(--purdue-gold); }
        .stat-card p { margin: 8px 0 0 0; color: var(--text-light); }
        .stat-card.critical h3 { color: #f44336; }
        .stat-card.high-risk h3 { color: #ff9800; }
        .chart-container { background: rgba(0,0,0,0.6); padding: 24px; border-radius: 12px; border: 2px solid rgba(207,185,145,0.3); margin: 20px 0; }
        .chart-wrapper { position: relative; height: 400px; }
        .criticality-badge { display: inline-block; padding: 4px 12px; border-radius: 12px; font-weight: bold; font-size: 0.9rem; }
        .criticality-critical { background: #f44336; color: white; }
        .criticality-high { background: #ff9800; color: white; }
        .criticality-moderate { background: #ffc107; color: black; }
        .criticality-low { background: #4caf50; color: white; }
        .info-box { background: rgba(207,185,145,0.1); padding: 15px; border-radius: 8px; margin: 20px 0; border-left: 4px solid var(--purdue-gold); }
        .info-box h4 { color: var(--purdue-gold); margin: 0 0 10px 0; }
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
        <a href="critical_companies.php" class="active">Critical Companies</a>
        <a href="timeline.php">Disruption Timeline</a>
        <a href="companies.php">Company List</a>
        <a href="distributors.php">Distributors</a>
    </nav>

    <div class="container">
        <h2>Critical Companies Analysis</h2>

        <div class="info-box">
            <h4>📊 Criticality Score Formula</h4>
            <p style="color: var(--text-light); margin: 0;">
                <strong>Criticality = (Downstream Companies) × (High Impact Disruptions)</strong><br>
                This metric identifies companies that are both central to the supply chain AND frequently experience high-impact disruptions.
            </p>
        </div>

        <div class="content-section">
            <h3>Filter Companies</h3>
            <form id="filterForm">
                <div class="filter-grid">
                    <div>
                        <label>Min Criticality Score:</label>
                        <input type="number" id="min_criticality" min="0" step="1" placeholder="e.g. 5" value="<?= htmlspecialchars($minCriticality) ?>">
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
                        <label>Tier Level:</label>
                        <select id="tier">
                            <option value="">All Tiers</option>
                            <option value="1" <?= $tierLevel == '1' ? 'selected' : '' ?>>Tier 1</option>
                            <option value="2" <?= $tierLevel == '2' ? 'selected' : '' ?>>Tier 2</option>
                            <option value="3" <?= $tierLevel == '3' ? 'selected' : '' ?>>Tier 3</option>
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
                <h3 id="stat-total"><?= $totalCompanies ?></h3>
                <p>Total Companies</p>
            </div>
            <div class="stat-card critical">
                <h3 id="stat-critical"><?= $criticalCount ?></h3>
                <p>Critical (≥10)</p>
            </div>
            <div class="stat-card high-risk">
                <h3 id="stat-highrisk"><?= $highRiskCount ?></h3>
                <p>High Risk (≥5)</p>
            </div>
            <div class="stat-card">
                <h3 id="stat-avg"><?= $avgCriticality ?></h3>
                <p>Avg Criticality</p>
            </div>
        </div>

        <!-- Top 10 Critical Companies Chart -->
        <div class="chart-container">
            <h3>Top 10 Most Critical Companies</h3>
            <div class="chart-wrapper">
                <canvas id="criticalityChart"></canvas>
            </div>
        </div>

        <!-- Company Table (sorted by criticality) -->
        <div class="content-section">
            <h3>Companies Ranked by Criticality (<span id="recordCount"><?= count($companies) ?></span> companies)</h3>
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
                            <th>Downstream Count</th>
                            <th>High Impact Events</th>
                            <th>Criticality Score</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $rank = 1;
                        foreach ($companies as $c): 
                            $score = intval($c['criticalityScore']);
                            $badgeClass = 'criticality-low';
                            if ($score >= 10) {
                                $badgeClass = 'criticality-critical';
                            } elseif ($score >= 5) {
                                $badgeClass = 'criticality-high';
                            } elseif ($score >= 2) {
                                $badgeClass = 'criticality-moderate';
                            }
                        ?>
                        <tr>
                            <td><strong><?= $rank++ ?></strong></td>
                            <td><?= htmlspecialchars($c['CompanyName']) ?></td>
                            <td><?= htmlspecialchars($c['Type']) ?></td>
                            <td>Tier <?= $c['TierLevel'] ?></td>
                            <td><?= htmlspecialchars($c['ContinentName']) ?></td>
                            <td><?= $c['downstreamCount'] ?></td>
                            <td><?= $c['highImpactCount'] ?></td>
                            <td>
                                <span class="criticality-badge <?= $badgeClass ?>">
                                    <?= $score ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                <p style="text-align: center; padding: 40px; color: var(--text-light);">No companies found matching your criteria.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
    (function() {
        var form = document.getElementById('filterForm');
        var criticalityChart = null;
        
        // initialize chart on page load
        initChart();
        
        function initChart() {
            var topData = <?= json_encode($topCompanies) ?>;
            
            var labels = [];
            var scores = [];
            var colors = [];  // color array for bars
            
            for (var i = 0; i < topData.length; i++) {
                labels.push(topData[i].CompanyName);
                var score = parseInt(topData[i].criticalityScore);
                scores.push(score);
                
                // color code based on criticality level
                if (score >= 10) {
                    colors.push('#f44336');  // red for critical
                } else if (score >= 5) {
                    colors.push('#ff9800');  // orange for high risk
                } else if (score >= 2) {
                    colors.push('#ffc107');  // yellow for moderate
                } else {
                    colors.push('#4caf50');  // green for low
                }
            }
            
            var ctx = document.getElementById('criticalityChart').getContext('2d');
            criticalityChart = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'Criticality Score',
                        data: scores,
                        backgroundColor: colors  // use color array instead of single color
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    indexAxis: 'y',  // horizontal bar chart
                    scales: {
                        x: { 
                            beginAtZero: true,
                            ticks: { color: 'white' }, 
                            grid: { color: 'rgba(207,185,145,0.1)' } 
                        },
                        y: { 
                            ticks: { color: 'white' }, 
                            grid: { color: 'rgba(207,185,145,0.1)' } 
                        }
                    },
                    plugins: { legend: { labels: { color: 'white' } } }
                }
            });
        }
        
        // load critical companies data via ajax
        function load() {
            document.getElementById('tableWrapper').innerHTML = '<div class="loading">Loading...</div>';
            
            var params = 'ajax=1&min_criticality=' + encodeURIComponent(document.getElementById('min_criticality').value) +
                        '&region=' + encodeURIComponent(document.getElementById('region').value) +
                        '&tier=' + encodeURIComponent(document.getElementById('tier').value);
            
            var xhr = new XMLHttpRequest();
            xhr.open('GET', 'critical_companies.php?' + params, true);
            xhr.onload = function() {
                if (xhr.status === 200) {
                    var r = JSON.parse(xhr.responseText);
                    if (r.success) {
                        var d = r.data;
                        var s = d.summary;
                        
                        // update summary stats
                        document.getElementById('stat-total').textContent = s.totalCompanies;
                        document.getElementById('stat-critical').textContent = s.criticalCount;
                        document.getElementById('stat-highrisk').textContent = s.highRiskCount;
                        document.getElementById('stat-avg').textContent = s.avgCriticality;
                        
                        // update chart with top 10
                        var labels = [];
                        var scores = [];
                        var colors = [];  // color array for bars
                        
                        for (var i = 0; i < d.topCompanies.length; i++) {
                            labels.push(d.topCompanies[i].CompanyName);
                            var score = parseInt(d.topCompanies[i].criticalityScore);
                            scores.push(score);
                            
                            // color code based on criticality level
                            if (score >= 10) {
                                colors.push('#f44336');  // red for critical
                            } else if (score >= 5) {
                                colors.push('#ff9800');  // orange for high risk
                            } else if (score >= 2) {
                                colors.push('#ffc107');  // yellow for moderate
                            } else {
                                colors.push('#4caf50');  // green for low
                            }
                        }
                        
                        if (criticalityChart) criticalityChart.destroy();
                        
                        var ctx = document.getElementById('criticalityChart').getContext('2d');
                        criticalityChart = new Chart(ctx, {
                            type: 'bar',
                            data: {
                                labels: labels,
                                datasets: [{
                                    label: 'Criticality Score',
                                    data: scores,
                                    backgroundColor: colors  // use color array instead of single color
                                }]
                            },
                            options: {
                                responsive: true,
                                maintainAspectRatio: false,
                                indexAxis: 'y',
                                scales: {
                                    x: { 
                                        beginAtZero: true,
                                        ticks: { color: 'white' }, 
                                        grid: { color: 'rgba(207,185,145,0.1)' } 
                                    },
                                    y: { 
                                        ticks: { color: 'white' }, 
                                        grid: { color: 'rgba(207,185,145,0.1)' } 
                                    }
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
        
        // build company table sorted by criticality
        function buildTable(companies) {
            document.getElementById('recordCount').textContent = companies.length;
            
            if (companies.length === 0) {
                document.getElementById('tableWrapper').innerHTML = 
                    '<p style="text-align:center;padding:40px;color:var(--text-light)">No companies found matching your criteria.</p>';
                return;
            }
            
            var html = '<table><thead><tr><th>Rank</th><th>Company</th><th>Type</th><th>Tier</th><th>Region</th><th>Downstream Count</th><th>High Impact Events</th><th>Criticality Score</th></tr></thead><tbody>';
            
            for (var i = 0; i < companies.length; i++) {
                var c = companies[i];
                var score = parseInt(c.criticalityScore);
                var badgeClass = 'criticality-low';
                if (score >= 10) badgeClass = 'criticality-critical';
                else if (score >= 5) badgeClass = 'criticality-high';
                else if (score >= 2) badgeClass = 'criticality-moderate';
                
                html += '<tr>' +
                    '<td><strong>' + (i + 1) + '</strong></td>' +
                    '<td>' + esc(c.CompanyName) + '</td>' +
                    '<td>' + esc(c.Type) + '</td>' +
                    '<td>Tier ' + c.TierLevel + '</td>' +
                    '<td>' + esc(c.ContinentName) + '</td>' +
                    '<td>' + c.downstreamCount + '</td>' +
                    '<td>' + c.highImpactCount + '</td>' +
                    '<td><span class="criticality-badge ' + badgeClass + '">' + score + '</span></td>' +
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
            document.getElementById('min_criticality').value = '';
            document.getElementById('region').value = '';
            document.getElementById('tier').value = '';
            load();
        });
        
    })();
    </script>
</body>
</html>