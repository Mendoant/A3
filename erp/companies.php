<?php
// companies.php - View and search all companies with AJAX filtering
require_once '../config.php';
requireLogin();

// kick out supply chain managers
if (!hasRole('SeniorManager')) {
    header('Location: ../scm/dashboard.php');
    exit;
}

$pdo = getPDO();

// grab filter values
$searchName = isset($_GET['search']) ? trim($_GET['search']) : '';
$filterType = isset($_GET['type']) ? $_GET['type'] : '';
$filterTier = isset($_GET['tier']) ? $_GET['tier'] : '';
$filterRegion = isset($_GET['region']) ? $_GET['region'] : '';

// build the query with filters
$sql = "SELECT c.CompanyID, c.CompanyName, c.Type, c.TierLevel,
               l.City, l.CountryName, l.ContinentName
        FROM Company c
        LEFT JOIN Location l ON c.LocationID = l.LocationID
        WHERE 1=1";

$params = array();

// add search filter if provided
if ($searchName !== '') {
    $sql .= " AND c.CompanyName LIKE ?";
    $params[] = '%' . $searchName . '%';
}

// filter by type
if ($filterType !== '') {
    $sql .= " AND c.Type = ?";
    $params[] = $filterType;
}

// filter by tier
if ($filterTier !== '') {
    $sql .= " AND c.TierLevel = ?";
    $params[] = $filterTier;
}

// filter by region
if ($filterRegion !== '') {
    $sql .= " AND l.ContinentName = ?";
    $params[] = $filterRegion;
}

$sql .= " ORDER BY c.CompanyName";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$companies = $stmt->fetchAll();

// grab financial health scores for each company using a single query with subquery
// get the latest quarter for each company
$healthSQL = "SELECT 
                fr.CompanyID,
                fr.HealthScore
              FROM FinancialReport fr
              INNER JOIN (
                  SELECT CompanyID, 
                         MAX(CONCAT(RepYear, FIELD(Quarter, 'Q1', 'Q2', 'Q3', 'Q4'))) as maxPeriod
                  FROM FinancialReport
                  GROUP BY CompanyID
              ) latest ON fr.CompanyID = latest.CompanyID 
                  AND CONCAT(fr.RepYear, FIELD(fr.Quarter, 'Q1', 'Q2', 'Q3', 'Q4')) = latest.maxPeriod";

$healthStmt = $pdo->query($healthSQL);
$healthScores = array();
while ($row = $healthStmt->fetch()) {
    $healthScores[$row['CompanyID']] = floatval($row['HealthScore']);
}

// add health scores to companies array
foreach ($companies as $key => $comp) {
    $companies[$key]['HealthScore'] = isset($healthScores[$comp['CompanyID']]) 
        ? $healthScores[$comp['CompanyID']] 
        : null;
}

// get unique regions for filter dropdown
$regionStmt = $pdo->query("SELECT DISTINCT ContinentName FROM Location WHERE ContinentName IS NOT NULL ORDER BY ContinentName");
$regions = $regionStmt->fetchAll();

// AJAX response - return json
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    echo json_encode(array(
        'success' => true,
        'companies' => $companies,
        'count' => count($companies)
    ));
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Company List - ERP System</title>
    <link rel="stylesheet" href="../assets/styles.css">
    <style>
        .company-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            background: rgba(0,0,0,0.6);
        }
        .company-table th {
            background: #CFB991;
            color: #000;
            padding: 12px;
            text-align: left;
            font-weight: bold;
        }
        .company-table td {
            padding: 10px 12px;
            border-bottom: 1px solid rgba(207,185,145,0.2);
        }
        .company-table tr:hover {
            background: rgba(207,185,145,0.1);
        }
        .health-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 4px;
            font-size: 0.9em;
            font-weight: bold;
        }
        .health-good { background: #4caf50; color: white; }
        .health-warning { background: #ffc107; color: black; }
        .health-bad { background: #f44336; color: white; }
        .health-none { background: #666; color: white; }
        .filter-section {
            background: rgba(0,0,0,0.7);
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .filter-row {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            align-items: flex-end;
        }
        .filter-group {
            flex: 1;
            min-width: 200px;
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
        .btn-reset {
            padding: 8px 20px;
            background: #666;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            align-self: flex-end;
        }
        .btn-reset:hover {
            background: #555;
        }
        .results-count {
            margin-top: 10px;
            color: #CFB991;
            font-size: 0.95em;
        }
        .tier-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 3px;
            background: rgba(207,185,145,0.3);
            font-size: 0.9em;
        }
        .company-table th {
            cursor: pointer;
            user-select: none;
            position: relative;
        }
        .company-table th:hover {
            background: #b89968;
        }
        .sort-indicator {
            margin-left: 5px;
            font-size: 0.8em;
            opacity: 0.6;
        }
    </style>
</head>
<body>
    <div class="container">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <h1>Company List</h1>
            <div>
                <a href="add_company.php" class="btn" style="background: #4caf50; color: white; padding: 10px 20px; text-decoration: none; border-radius: 4px; margin-right: 10px;">+ Add New Company</a>
                <a href="../logout.php" style="background: #f44336; color: white; padding: 10px 20px; text-decoration: none; border-radius: 4px;">Logout</a>
            </div>
        </div>

        <nav class="container" style="background: rgba(0,0,0,0.8); padding: 15px 30px; margin-bottom: 30px; border-radius: 8px; display: flex; gap: 20px; flex-wrap: wrap;">
            <a href="dashboard.php">Dashboard</a>
            <a href="financial.php">Financial Health</a>
            <a href="regional_disruptions.php">Regional Disruptions</a>
            <a href="critical_companies.php">Critical Companies</a>
            <a href="timeline.php">Disruption Timeline</a>
            <a href="companies.php" class="active">Company List</a>
            <a href="distributors.php">Distributors</a>
            <a href="disruptions.php">Disruptions</a>
        </nav>

        <div class="filter-section">
            <form id="filterForm">
                <div class="filter-row">
                    <div class="filter-group">
                        <label for="search">Company Name</label>
                        <input type="text" id="search" name="search" value="<?= htmlspecialchars($searchName) ?>" placeholder="Search by name...">
                    </div>
                    <div class="filter-group">
                        <label for="type">Type</label>
                        <select id="type" name="type">
                            <option value="">All Types</option>
                            <option value="Manufacturer" <?= $filterType === 'Manufacturer' ? 'selected' : '' ?>>Manufacturer</option>
                            <option value="Distributor" <?= $filterType === 'Distributor' ? 'selected' : '' ?>>Distributor</option>
                            <option value="Retailer" <?= $filterType === 'Retailer' ? 'selected' : '' ?>>Retailer</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label for="tier">Tier Level</label>
                        <select id="tier" name="tier">
                            <option value="">All Tiers</option>
                            <option value="1" <?= $filterTier === '1' ? 'selected' : '' ?>>Tier 1</option>
                            <option value="2" <?= $filterTier === '2' ? 'selected' : '' ?>>Tier 2</option>
                            <option value="3" <?= $filterTier === '3' ? 'selected' : '' ?>>Tier 3</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label for="region">Region</label>
                        <select id="region" name="region">
                            <option value="">All Regions</option>
                            <?php foreach ($regions as $r): ?>
                                <option value="<?= htmlspecialchars($r['ContinentName']) ?>" <?= $filterRegion === $r['ContinentName'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($r['ContinentName']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn-filter">Apply Filters</button>
                    <button type="button" class="btn-reset" onclick="resetFilters()">Reset</button>
                </div>
            </form>
            <div class="results-count" id="resultsCount">
                Showing <?= count($companies) ?> companies
            </div>
        </div>

        <div id="tableContainer">
            <table class="company-table">
                <thead>
                    <tr>
                        <th onclick="sortTable(0)" data-sort="asc">ID <span class="sort-indicator">▲</span></th>
                        <th onclick="sortTable(1)" data-sort="asc">Company Name <span class="sort-indicator"></span></th>
                        <th onclick="sortTable(2)" data-sort="asc">Type <span class="sort-indicator"></span></th>
                        <th onclick="sortTable(3)" data-sort="asc">Tier <span class="sort-indicator"></span></th>
                        <th onclick="sortTable(4)" data-sort="asc">Location <span class="sort-indicator"></span></th>
                        <th onclick="sortTable(5)" data-sort="asc">Region <span class="sort-indicator"></span></th>
                        <th onclick="sortTable(6)" data-sort="asc">Health Score <span class="sort-indicator"></span></th>
                    </tr>
                </thead>
                <tbody id="companyTableBody">
                    <?php foreach ($companies as $comp): ?>
                        <tr>
                            <td><?= htmlspecialchars($comp['CompanyID']) ?></td>
                            <td><strong><?= htmlspecialchars($comp['CompanyName']) ?></strong></td>
                            <td><?= htmlspecialchars($comp['Type']) ?></td>
                            <td><span class="tier-badge">Tier <?= htmlspecialchars($comp['TierLevel']) ?></span></td>
                            <td><?= htmlspecialchars($comp['City'] ?: 'N/A') ?>, <?= htmlspecialchars($comp['CountryName'] ?: 'N/A') ?></td>
                            <td><?= htmlspecialchars($comp['ContinentName'] ?: 'N/A') ?></td>
                            <td>
                                <?php if ($comp['HealthScore'] !== null): 
                                    $score = $comp['HealthScore'];
                                    $class = 'health-none';
                                    if ($score >= 75) $class = 'health-good';
                                    elseif ($score >= 50) $class = 'health-warning';
                                    elseif ($score >= 0) $class = 'health-bad';
                                ?>
                                    <span class="health-badge <?= $class ?>"><?= number_format($score, 1) ?></span>
                                <?php else: ?>
                                    <span class="health-badge health-none">N/A</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($companies)): ?>
                        <tr>
                            <td colspan="7" style="text-align: center; padding: 30px; color: #999;">
                                No companies found matching your filters.
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
        var currentCompanies = <?= json_encode($companies) ?>;
        
        // handle form submission with AJAX
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            loadCompanies();
            return false;
        });
        
        function loadCompanies() {
            // build query params
            var search = document.getElementById('search').value;
            var type = document.getElementById('type').value;
            var tier = document.getElementById('tier').value;
            var region = document.getElementById('region').value;
            
            var params = 'ajax=1' +
                '&search=' + encodeURIComponent(search) +
                '&type=' + encodeURIComponent(type) +
                '&tier=' + encodeURIComponent(tier) +
                '&region=' + encodeURIComponent(region);
            
            var xhr = new XMLHttpRequest();
            xhr.open('GET', 'companies.php?' + params, true);
            xhr.onload = function() {
                if (xhr.status === 200) {
                    var response = JSON.parse(xhr.responseText);
                    if (response.success) {
                        currentCompanies = response.companies;
                        updateTable(currentCompanies);
                        document.getElementById('resultsCount').textContent = 
                            'Showing ' + response.count + ' companies';
                    }
                }
            };
            xhr.send();
        }
        
        function updateTable(companies) {
            var tbody = document.getElementById('companyTableBody');
            tbody.innerHTML = '';
            
            if (companies.length === 0) {
                tbody.innerHTML = '<tr><td colspan="7" style="text-align: center; padding: 30px; color: #999;">No companies found matching your filters.</td></tr>';
                return;
            }
            
            companies.forEach(function(comp) {
                var row = document.createElement('tr');
                
                // health score badge
                var healthBadge = '';
                if (comp.HealthScore !== null) {
                    var score = parseFloat(comp.HealthScore);
                    var healthClass = 'health-none';
                    if (score >= 75) healthClass = 'health-good';
                    else if (score >= 50) healthClass = 'health-warning';
                    else if (score >= 0) healthClass = 'health-bad';
                    healthBadge = '<span class="health-badge ' + healthClass + '">' + score.toFixed(1) + '</span>';
                } else {
                    healthBadge = '<span class="health-badge health-none">N/A</span>';
                }
                
                row.innerHTML = 
                    '<td>' + escapeHtml(comp.CompanyID) + '</td>' +
                    '<td><strong>' + escapeHtml(comp.CompanyName) + '</strong></td>' +
                    '<td>' + escapeHtml(comp.Type) + '</td>' +
                    '<td><span class="tier-badge">Tier ' + escapeHtml(comp.TierLevel) + '</span></td>' +
                    '<td>' + escapeHtml(comp.City || 'N/A') + ', ' + escapeHtml(comp.CountryName || 'N/A') + '</td>' +
                    '<td>' + escapeHtml(comp.ContinentName || 'N/A') + '</td>' +
                    '<td>' + healthBadge + '</td>';
                
                tbody.appendChild(row);
            });
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
        
        // sort table by column index
        window.sortTable = function(columnIndex) {
            var table = document.querySelector('.company-table');
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
            
            // sort the companies array
            currentCompanies.sort(function(a, b) {
                var valA, valB;
                
                switch(columnIndex) {
                    case 0: // ID
                        valA = parseInt(a.CompanyID);
                        valB = parseInt(b.CompanyID);
                        break;
                    case 1: // Company Name
                        valA = (a.CompanyName || '').toLowerCase();
                        valB = (b.CompanyName || '').toLowerCase();
                        break;
                    case 2: // Type
                        valA = (a.Type || '').toLowerCase();
                        valB = (b.Type || '').toLowerCase();
                        break;
                    case 3: // Tier
                        valA = parseInt(a.TierLevel);
                        valB = parseInt(b.TierLevel);
                        break;
                    case 4: // Location
                        valA = ((a.City || '') + (a.CountryName || '')).toLowerCase();
                        valB = ((b.City || '') + (b.CountryName || '')).toLowerCase();
                        break;
                    case 5: // Region
                        valA = (a.ContinentName || '').toLowerCase();
                        valB = (b.ContinentName || '').toLowerCase();
                        break;
                    case 6: // Health Score
                        valA = a.HealthScore !== null ? parseFloat(a.HealthScore) : -999;
                        valB = b.HealthScore !== null ? parseFloat(b.HealthScore) : -999;
                        break;
                    default:
                        return 0;
                }
                
                if (valA < valB) return newSort === 'asc' ? -1 : 1;
                if (valA > valB) return newSort === 'asc' ? 1 : -1;
                return 0;
            });
            
            // update the table with sorted data
            updateTable(currentCompanies);
        };
        
        // reset filters function
        window.resetFilters = function() {
            document.getElementById('search').value = '';
            document.getElementById('type').value = '';
            document.getElementById('tier').value = '';
            document.getElementById('region').value = '';
            loadCompanies();
        };
    })();
    </script>
</body>
</html>