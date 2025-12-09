<?php
// erp/critical_companies.php - Critical Companies Analysis
// figuring out which companies are the "bottlenecks" or highest risk

require_once '../config.php';
requireLogin();

// security check
if (!hasRole('SeniorManager')) {
    header('Location: ../scm/dashboard.php');
    exit;
}

$pdo = getPDO();

// --- 1. GET FILTERS ---
$minCriticality = isset($_GET['min_criticality']) ? $_GET['min_criticality'] : '';
$maxCriticality = isset($_GET['max_criticality']) ? $_GET['max_criticality'] : ''; // New max filter
$region = isset($_GET['region']) ? $_GET['region'] : '';
$tierLevel = isset($_GET['tier']) ? $_GET['tier'] : '';

// --- 2. FETCH DATA ---
// we need to grab the companies first, then calculate their scores in a loop
// because the score depends on multiple different tables (dependencies + disruption history)
$companies = array();
$sql = "SELECT c.CompanyID, c.CompanyName, c.Type, c.TierLevel, l.ContinentName, l.CountryName
        FROM Company c 
        LEFT JOIN Location l ON c.LocationID = l.LocationID 
        WHERE 1=1";

$params = array();

if (!empty($region)) { 
    $sql .= " AND l.ContinentName = :region"; 
    $params[':region'] = $region; 
}
if (!empty($tierLevel)) { 
    $sql .= " AND c.TierLevel = :tier"; 
    $params[':tier'] = $tierLevel; 
}

$sql .= " ORDER BY c.CompanyName";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $allCompanies = $stmt->fetchAll();
} catch (PDOException $e) {
    if (isset($_GET['ajax'])) {
        die(json_encode(array('success' => false, 'message' => $e->getMessage())));
    }
}

// --- 3. CALCULATE CRITICALITY SCORES ---
foreach ($allCompanies as $comp) {
    $cid = $comp['CompanyID'];
    
    // factor 1: how many companies depend on this one? (Downstream dependencies)
    $stmt1 = $pdo->prepare("SELECT COUNT(DISTINCT DownstreamCompanyID) as cnt FROM DependsOn WHERE UpstreamCompanyID = ?");
    $stmt1->execute(array($cid));
    $downstreamCount = intval($stmt1->fetch()['cnt']);
    
    // factor 2: how prone is it to disasters? (High impact events only)
    $stmt2 = $pdo->prepare("SELECT COUNT(DISTINCT de.EventID) as cnt 
                            FROM DisruptionEvent de 
                            JOIN ImpactsCompany ic ON de.EventID = ic.EventID 
                            WHERE ic.AffectedCompanyID = ? AND ic.ImpactLevel = 'High'");
    $stmt2->execute(array($cid));
    $highImpactCount = intval($stmt2->fetch()['cnt']);
    
    // the formula: weighted score. 
    // adding 1 to avoid multiplying by zero.
    $criticalityScore = ($downstreamCount + 1) * ($highImpactCount + 1);
    
    // --- APPLY SCORE FILTERS ---
    
    // Min Score Check
    if (!empty($minCriticality) && $criticalityScore < intval($minCriticality)) {
        continue; 
    }
    
    // Max Score Check
    if (!empty($maxCriticality) && $criticalityScore > intval($maxCriticality)) {
        continue;
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

// sort by score descending (worst first)
usort($companies, function($a, $b) { 
    return $b['criticalityScore'] - $a['criticalityScore']; 
});

// --- 4. SUMMARY STATS ---
$totalCompanies = count($companies);
$criticalCount = 0; 
$highRiskCount = 0; 
$avgCriticality = 0;
$totalScore = 0;

foreach ($companies as $c) {
    $score = intval($c['criticalityScore']);
    $totalScore += $score;
    
    if ($score >= 10) $criticalCount++;
    elseif ($score >= 5) $highRiskCount++;
}

$avgCriticality = $totalCompanies > 0 ? round($totalScore / $totalCompanies, 1) : 0;
$topCompanies = array_slice($companies, 0, 10);

// --- 5. AJAX RESPONSE ---
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

// dropdown for regions
$allRegions = $pdo->query("SELECT DISTINCT ContinentName FROM Location ORDER BY ContinentName")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Critical Companies - ERP</title>
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
        /* Sticky header fix: solid bg + high z-index */
        .table-scroll-window thead th {
            position: sticky;
            top: 0;
            z-index: 1000;
            background-color: #1a1a1a; 
            border-bottom: 3px solid var(--purdue-gold);
            box-shadow: 0 2px 5px rgba(0,0,0,0.5);
            color: #ffffff;
        }
        
        /* 2x2 Grid Layout for this specific page */
        .filter-grid-2x2 {
            display: grid;
            grid-template-columns: repeat(2, 1fr); /* 2 columns */
            gap: 15px;
            align-items: end;
        }
        
        @media (max-width: 800px) {
            .filter-grid-2x2 { grid-template-columns: 1fr; }
        }
        
        /* Dynamic font size for stats */
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
        <a href="critical_companies.php" class="active">Critical Companies</a>
        <a href="regional_disruptions.php">Regional Disruptions</a>
        <a href="timeline.php">Disruption Timeline</a>
        <a href="disruptions.php">Disruption Analysis</a>
        <a href="distributors.php">Distributors</a>
        <a href="add_company.php">Add Company</a>
    </nav>

    <div class="container">
        <h2>Critical Companies Analysis</h2>
        <div class="filter-section">
            <h3>Filter Companies</h3>
            <form id="filterForm" onsubmit="return false;">
                <div class="filter-grid-2x2">
                    <div class="filter-group">
                        <label>Min Criticality Score:</label>
                        <input type="number" id="min_criticality" min="0" step="1" placeholder="e.g. 5" value="<?= htmlspecialchars($minCriticality) ?>">
                    </div>
                    
                    <div class="filter-group">
                        <label>Max Criticality Score:</label>
                        <input type="number" id="max_criticality" min="0" step="1" placeholder="e.g. 100" value="<?= htmlspecialchars($maxCriticality) ?>">
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
                        <label>Tier Level:</label>
                        <select id="tier">
                            <option value="">All Tiers</option>
                            <option value="1" <?= $tierLevel == '1' ? 'selected' : '' ?>>Tier 1</option>
                            <option value="2" <?= $tierLevel == '2' ? 'selected' : '' ?>>Tier 2</option>
                            <option value="3" <?= $tierLevel == '3' ? 'selected' : '' ?>>Tier 3</option>
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
                <h3 id="stat-total"><?= $totalCompanies ?></h3>
                <p>Total Companies</p>
            </div>
            <div class="stat-card critical">
                <h3 id="stat-critical"><?= $criticalCount ?></h3>
                <p>Critical (≥10)</p>
            </div>
            <div class="stat-card warning">
                <h3 id="stat-highrisk"><?= $highRiskCount ?></h3>
                <p>High Risk (≥5)</p>
            </div>
            <div class="stat-card">
                <h3 id="stat-avg"><?= $avgCriticality ?></h3>
                <p>Avg Criticality</p>
            </div>
        </div>

        <div class="chart-container">
            <h3>Top 10 Most Critical Companies</h3>
            <div class="chart-wrapper">
                <canvas id="criticalityChart"></canvas>
            </div>
        </div>

        <div class="content-section">
            <h3>Companies Ranked by Criticality (<span id="recordCount"><?= count($companies) ?></span> companies)</h3>
            <div id="tableWrapper" class="table-scroll-window">
                <?php if (count($companies) > 0): ?>
                <table style="margin-top:0;">
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
                    <tbody id="tableBody">
                        <?php $rank = 1; foreach ($companies as $c): 
                            $score = intval($c['criticalityScore']);
                            $badgeClass = 'criticality-low';
                            if ($score >= 10) $badgeClass = 'criticality-critical';
                            elseif ($score >= 5) $badgeClass = 'criticality-high';
                            elseif ($score >= 2) $badgeClass = 'criticality-moderate';
                        ?>
                        <tr>
                            <td><strong><?= $rank++ ?></strong></td>
                            <td><?= htmlspecialchars($c['CompanyName']) ?></td>
                            <td><?= htmlspecialchars($c['Type']) ?></td>
                            <td>Tier <?= $c['TierLevel'] ?></td>
                            <td><?= htmlspecialchars($c['ContinentName']) ?></td>
                            <td><?= $c['downstreamCount'] ?></td>
                            <td><?= $c['highImpactCount'] ?></td>
                            <td><span class="criticality-badge <?= $badgeClass ?>"><?= $score ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                <p class="no-data">No companies found matching your criteria.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        var criticalityChart = null;
        var timeout = null;
        
        // checking storage to see if we were here before
        if(sessionStorage.getItem('crit_min')) document.getElementById('min_criticality').value = sessionStorage.getItem('crit_min');
        if(sessionStorage.getItem('crit_max')) document.getElementById('max_criticality').value = sessionStorage.getItem('crit_max');
        if(sessionStorage.getItem('crit_region')) document.getElementById('region').value = sessionStorage.getItem('crit_region');
        if(sessionStorage.getItem('crit_tier')) document.getElementById('tier').value = sessionStorage.getItem('crit_tier');

        // Initial paint using the data PHP already calculated
        initChart(<?= json_encode($topCompanies) ?>);

        // --- DYNAMIC FILTER LOGIC ---
        var inputs = document.querySelectorAll('#filterForm input, #filterForm select');
        for(var i=0; i<inputs.length; i++) {
            if(inputs[i].type === 'number') {
                inputs[i].addEventListener('input', function() {
                    clearTimeout(timeout);
                    timeout = setTimeout(load, 300); // debounce text input
                });
            } else {
                inputs[i].addEventListener('change', load);
            }
        }
        
        document.getElementById('clearBtn').addEventListener('click', function() {
            // wipe the storage clean
            sessionStorage.removeItem('crit_min');
            sessionStorage.removeItem('crit_max');
            sessionStorage.removeItem('crit_region');
            sessionStorage.removeItem('crit_tier');

            document.getElementById('min_criticality').value = '';
            document.getElementById('max_criticality').value = '';
            document.getElementById('region').value = '';
            document.getElementById('tier').value = '';
            load();
        });

        function initChart(topData) {
            var labels = []; var scores = []; var colors = [];
            for (var i = 0; i < topData.length; i++) {
                labels.push(topData[i].CompanyName);
                var score = parseInt(topData[i].criticalityScore);
                scores.push(score);
                // coloring based on severity
                if (score >= 10) colors.push('#f44336');
                else if (score >= 5) colors.push('#ff9800');
                else if (score >= 2) colors.push('#ffc107');
                else colors.push('#4caf50');
            }
            
            if (criticalityChart) criticalityChart.destroy();
            var ctx = document.getElementById('criticalityChart').getContext('2d');
            criticalityChart = new Chart(ctx, {
                type: 'bar',
                data: { labels: labels, datasets: [{ label: 'Criticality Score', data: scores, backgroundColor: colors }] },
                options: {
                    responsive: true, maintainAspectRatio: false, indexAxis: 'y',
                    scales: {
                        x: { beginAtZero: true, ticks: { color: 'white' }, grid: { color: 'rgba(207,185,145,0.1)' } },
                        y: { ticks: { color: 'white' }, grid: { color: 'rgba(207,185,145,0.1)' } }
                    },
                    plugins: { legend: { display: false } }
                }
            });
        }
        
        function load() {
            var tableWrapper = document.getElementById('tableWrapper');
            if(tableWrapper) tableWrapper.style.opacity = '0.5';
            
            // grab the values
            var minC = document.getElementById('min_criticality').value;
            var maxC = document.getElementById('max_criticality').value;
            var reg = document.getElementById('region').value;
            var tVal = document.getElementById('tier').value;

            // save to session storage
            sessionStorage.setItem('crit_min', minC);
            sessionStorage.setItem('crit_max', maxC);
            sessionStorage.setItem('crit_region', reg);
            sessionStorage.setItem('crit_tier', tVal);

            var params = 'ajax=1&min_criticality=' + encodeURIComponent(minC) +
                        '&max_criticality=' + encodeURIComponent(maxC) +
                        '&region=' + encodeURIComponent(reg) +
                        '&tier=' + encodeURIComponent(tVal);
            
            var xhr = new XMLHttpRequest();
            xhr.open('GET', 'critical_companies.php?' + params, true);
            xhr.onload = function() {
                if (xhr.status === 200) {
                    try {
                        // THE SAFETY CHECK: did we get JSON or the Login Page?
                        if (xhr.responseText.trim().indexOf('{') !== 0) {
                            console.error("Received HTML instead of JSON. Session likely expired.");
                            // Optional: window.location.href = '../index.php';
                            return;
                        }

                        var r = JSON.parse(xhr.responseText);
                        if (r.success) {
                            var d = r.data; var s = d.summary;
                            document.getElementById('stat-total').textContent = s.totalCompanies;
                            document.getElementById('stat-critical').textContent = s.criticalCount;
                            document.getElementById('stat-highrisk').textContent = s.highRiskCount;
                            document.getElementById('stat-avg').textContent = s.avgCriticality;
                            
                            initChart(d.topCompanies);
                            buildTable(d.companies);
                            if(tableWrapper) tableWrapper.style.opacity = '1';
                        }
                    } catch(e) {
                        console.error("JSON Error", e);
                    }
                }
            };
            xhr.send();
        }
        
        function buildTable(companies) {
            document.getElementById('recordCount').textContent = companies.length;
            if (companies.length === 0) { 
                document.getElementById('tableWrapper').innerHTML = '<p class="no-data">No companies found matching your criteria.</p>'; 
                return; 
            }
            
            var html = '<table style="margin-top:0;"><thead><tr><th>Rank</th><th>Company</th><th>Type</th><th>Tier</th><th>Region</th><th>Downstream Count</th><th>High Impact Events</th><th>Criticality Score</th></tr></thead><tbody>';
            
            for (var i = 0; i < companies.length; i++) {
                var c = companies[i];
                var score = parseInt(c.criticalityScore);
                var badgeClass = 'criticality-low';
                if (score >= 10) badgeClass = 'criticality-critical';
                else if (score >= 5) badgeClass = 'criticality-high';
                else if (score >= 2) badgeClass = 'criticality-moderate';
                
                html += '<tr><td><strong>' + (i + 1) + '</strong></td>' +
                    '<td>' + esc(c.CompanyName) + '</td>' +
                    '<td>' + esc(c.Type) + '</td>' +
                    '<td>Tier ' + c.TierLevel + '</td>' +
                    '<td>' + esc(c.ContinentName) + '</td>' +
                    '<td>' + c.downstreamCount + '</td>' +
                    '<td>' + c.highImpactCount + '</td>' +
                    '<td><span class="criticality-badge ' + badgeClass + '">' + score + '</span></td></tr>';
            }
            document.getElementById('tableWrapper').innerHTML = html + '</tbody></table>';
        }
        
        function esc(t) { if (!t) return ''; var d = document.createElement('div'); d.textContent = t; return d.innerHTML; }
    });
    </script>
</body>
</html>