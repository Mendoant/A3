<?php
// companies.php - Company Financial Health (List View)
// Lists all companies with their health scores, allowing for deep dives

require_once '../config.php';
requireLogin();

// security check
if (!hasRole('SeniorManager')) {
    header('Location: ../scm/dashboard.php');
    exit;
}

$pdo = getPDO();

// =================================================================
// 1. AJAX HANDLER: "VIEW DETAILS" MODAL
// =================================================================
if (isset($_GET['ajax_view_id'])) {
    // turn off errors so json/html fragment doesnt break
    error_reporting(0); 
    header('Content-Type: text/html; charset=utf-8');

    try {
        $id = intval($_GET['ajax_view_id']);
        
        // basic info query
        $stmt = $pdo->prepare("
            SELECT c.*, l.City, l.CountryName, l.ContinentName 
            FROM Company c
            LEFT JOIN Location l ON c.LocationID = l.LocationID
            WHERE c.CompanyID = ?
        ");
        $stmt->execute(array($id));
        $comp = $stmt->fetch();

        // financial info - getting last 4 quarters
        $stmtFin = $pdo->prepare("
            SELECT * FROM FinancialReport 
            WHERE CompanyID = ? 
            ORDER BY RepYear DESC, FIELD(Quarter, 'Q4', 'Q3', 'Q2', 'Q1') 
            LIMIT 4
        ");
        $stmtFin->execute(array($id));
        $reports = $stmtFin->fetchAll();

        // count products
        $stmtProd = $pdo->prepare("SELECT COUNT(*) FROM SuppliesProduct WHERE SupplierID = ?");
        $stmtProd->execute(array($id));
        $prodCount = $stmtProd->fetchColumn();

        if ($comp) {
            echo "<div class='info-box'>";
            
            // Header
            echo "<h3 style='color: var(--purdue-gold); border-bottom: 1px solid var(--purdue-gold); padding-bottom: 10px; margin-bottom: 15px;'>" . htmlspecialchars($comp['CompanyName']) . "</h3>";
            
            // Layout
            echo "<div class='grid-2' style='display: grid; grid-template-columns: 1fr 1fr; gap: 20px;'>";
                
                // Col 1
                echo "<div>";
                echo "<h5 class='text-muted' style='margin-bottom:10px;'>General Information</h5>";
                echo "<p><strong>ID:</strong> " . $comp['CompanyID'] . "</p>";
                echo "<p><strong>Type:</strong> " . htmlspecialchars($comp['Type']) . "</p>";
                echo "<p><strong>Tier:</strong> <span class='tier-badge'>Tier " . htmlspecialchars($comp['TierLevel']) . "</span></p>";
                echo "<p><strong>Products Supplied:</strong> " . intval($prodCount) . "</p>";
                echo "</div>";

                // Col 2
                $city = !empty($comp['City']) ? $comp['City'] : 'N/A';
                $country = !empty($comp['CountryName']) ? $comp['CountryName'] : 'N/A';
                $continent = !empty($comp['ContinentName']) ? $comp['ContinentName'] : 'N/A';

                echo "<div>";
                echo "<h5 class='text-muted' style='margin-bottom:10px;'>Location Details</h5>";
                echo "<p><strong>City:</strong> " . htmlspecialchars($city) . "</p>";
                echo "<p><strong>Country:</strong> " . htmlspecialchars($country) . "</p>";
                echo "<p><strong>Region:</strong> " . htmlspecialchars($continent) . "</p>";
                echo "</div>";

            echo "</div>"; 

            // Financials Section
            echo "<div style='margin-top: 25px; padding-top: 15px; border-top: 1px solid rgba(207, 185, 145, 0.3);'>";
            echo "<h5 class='text-muted' style='margin-bottom:15px;'>Recent Financial Performance (Last 4 Quarters)</h5>";
            
            if (count($reports) > 0) {
                // creating a mini table for the scores
                echo "<table style='width:100%; margin-top:0; background:rgba(0,0,0,0.3);'>";
                echo "<thead><tr>
                        <th style='padding:8px; font-size:0.9em;'>Period</th>
                        <th style='padding:8px; font-size:0.9em;'>Health Score</th>
                        <th style='padding:8px; font-size:0.9em;'>Status</th>
                      </tr></thead>";
                echo "<tbody>";
                
                foreach ($reports as $r) {
                    $year = $r['RepYear'];
                    $q = $r['Quarter'];
                    $score = floatval($r['HealthScore']);
                    
                    // color logic
                    $hClass = 'health-bad';
                    $status = 'Critical';
                    if ($score >= 75) { $hClass = 'health-good'; $status = 'Healthy'; }
                    elseif ($score >= 50) { $hClass = 'health-warning'; $status = 'Warning'; }
                    
                    echo "<tr>";
                    echo "<td style='padding:8px;'>" . htmlspecialchars($year) . " " . htmlspecialchars($q) . "</td>";
                    echo "<td style='padding:8px;'><span class='health-badge $hClass'>" . number_format($score, 1) . "</span></td>";
                    echo "<td style='padding:8px; color: #ccc;'>" . $status . "</td>";
                    echo "</tr>";
                }
                echo "</tbody></table>";
            } else {
                echo "<p class='text-muted'><em>No financial reports filed yet.</em></p>";
            }
            echo "</div>";

            echo "</div>"; 
        } else {
            http_response_code(404);
            echo "<p class='error'>Company not found.</p>";
        }
    } catch (Exception $ex) {
        http_response_code(500);
        echo "<p class='error'>System Error: " . htmlspecialchars($ex->getMessage()) . "</p>";
    }
    exit; 
}

// =================================================================
// 2. MAIN PAGE LOGIC (Search & List)
// =================================================================

// getting filters
$companyId = isset($_GET['company_id']) ? $_GET['company_id'] : ''; 
$filterType = isset($_GET['type']) ? $_GET['type'] : '';
$filterTier = isset($_GET['tier']) ? $_GET['tier'] : '';
$filterRegion = isset($_GET['region']) ? $_GET['region'] : '';

// Base Query
$sql = "SELECT c.CompanyID, c.CompanyName, c.Type, c.TierLevel,
               l.City, l.CountryName, l.ContinentName
        FROM Company c
        LEFT JOIN Location l ON c.LocationID = l.LocationID
        WHERE 1=1";

$params = array();

// building params for safer sql
if ($companyId !== '') { 
    $sql .= " AND c.CompanyID = ?"; 
    $params[] = $companyId; 
}
if ($filterType !== '') { 
    $sql .= " AND c.Type = ?"; 
    $params[] = $filterType; 
}
if ($filterTier !== '') { 
    $sql .= " AND c.TierLevel = ?"; 
    $params[] = $filterTier; 
}
if ($filterRegion !== '') { 
    $sql .= " AND l.ContinentName = ?"; 
    $params[] = $filterRegion; 
}

$sql .= " ORDER BY c.CompanyName";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$companies = $stmt->fetchAll();

// --- Bulk Fetch Latest Health Scores ---
// getting these all at once to avoid n+1 problem in the loop
$healthSQL = "
    SELECT CompanyID, HealthScore 
    FROM FinancialReport 
    ORDER BY RepYear DESC, FIELD(Quarter, 'Q4', 'Q3', 'Q2', 'Q1')
";
$healthStmt = $pdo->query($healthSQL);
$allScores = $healthStmt->fetchAll();

// hashing the scores for easy lookup
$healthMap = array();
foreach ($allScores as $row) {
    if (!isset($healthMap[$row['CompanyID']])) {
        $healthMap[$row['CompanyID']] = floatval($row['HealthScore']);
    }
}

// attach scores to companies array
foreach ($companies as $key => $comp) {
    $companies[$key]['HealthScore'] = isset($healthMap[$comp['CompanyID']]) ? $healthMap[$comp['CompanyID']] : null;
}

// return json if ajax
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    echo json_encode(array('success' => true, 'companies' => $companies, 'count' => count($companies)));
    exit;
}

// Getting Regions for Dropdown
$regionStmt = $pdo->query("SELECT DISTINCT ContinentName FROM Location WHERE ContinentName IS NOT NULL ORDER BY ContinentName");
$regions = $regionStmt->fetchAll();

// Getting All Companies for Search Dropdown
$compStmt = $pdo->query("SELECT CompanyID, CompanyName FROM Company ORDER BY CompanyName");
$allCompanies = $compStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Company Financial Health - ERP System</title>
    <script src="../assets/forward_protection.js"></script>
    <link rel="stylesheet" href="../assets/styles.css">
    
    <style>
        /* Modal Styles */
        .modal {
            display: none; 
            position: fixed; 
            z-index: 2000; 
            left: 0; top: 0;
            width: 100%; height: 100%; 
            overflow: auto; 
            background-color: rgba(0,0,0,0.85); 
            backdrop-filter: blur(5px);
        }
        .modal-content {
            background: linear-gradient(135deg, var(--purdue-gray-dark) 0%, #080808 100%);
            margin: 5% auto;
            padding: 35px;
            border: 2px solid var(--purdue-gold);
            width: 60%; 
            max-width: 800px;
            border-radius: 16px;
            box-shadow: 0 0 60px rgba(207, 185, 145, 0.25);
            color: white;
            position: relative;
        }
        .close {
            position: absolute;
            right: 20px;
            top: 15px;
            color: var(--purdue-gold);
            font-size: 32px;
            font-weight: bold;
            transition: all 0.3s;
            cursor: pointer;
            line-height: 1;
        }
        .close:hover {
            color: white;
            transform: scale(1.1);
            text-shadow: 0 0 15px var(--purdue-gold);
        }
        .loading {
            text-align: center;
            color: var(--purdue-gold);
            font-size: 1.2em;
            padding: 40px;
        }

        /* Table Scroll Window (Standardized) */
        .table-scroll-window {
            height: 600px;
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
        /* Sticky Header */
        .table-scroll-window thead th {
            position: sticky;
            top: 0;
            z-index: 1000;
            background-color: #1a1a1a; 
            border-bottom: 3px solid var(--purdue-gold);
            box-shadow: 0 2px 5px rgba(0,0,0,0.5);
            color: #ffffff;
            cursor: pointer; /* Clickable for sorting */
        }
        /* Visual indicator for sort */
        .sort-indicator {
            margin-left: 5px;
            font-size: 0.8em;
            color: var(--purdue-gold);
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
        <a href="companies.php" class="active">Company Financial Health</a>
        <a href="financial.php">Financial Health</a>
        <a href="critical_companies.php">Critical Companies</a>
        <a href="regional_disruptions.php">Regional Disruptions</a>
        <a href="timeline.php">Disruption Timeline</a>
        <a href="disruptions.php">Disruption Analysis</a>
        <a href="distributors.php">Distributors</a>
        <a href="add_company.php">Add Company</a>
    </nav>

    <div class="container">
        <div class="flex flex-between align-items-center mb-md">
            <h2>Company Financial Health</h2>
            <a href="add_company.php" class="btn">+ Add New Company</a>
        </div>

        <div class="filter-section">
            <form id="filterForm">
                <div class="filter-grid">
                    <div class="filter-group">
                        <label>Company Name</label>
                        <select id="company_id">
                            <option value="">All Companies</option>
                            <?php foreach ($allCompanies as $c): ?>
                                <option value="<?= $c['CompanyID'] ?>">
                                    <?= htmlspecialchars($c['CompanyName']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label>Type</label>
                        <select id="type">
                            <option value="">All Types</option>
                            <option value="Manufacturer">Manufacturer</option>
                            <option value="Distributor">Distributor</option>
                            <option value="Retailer">Retailer</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label>Tier Level</label>
                        <select id="tier">
                            <option value="">All Tiers</option>
                            <option value="1">Tier 1</option>
                            <option value="2">Tier 2</option>
                            <option value="3">Tier 3</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label>Region</label>
                        <select id="region">
                            <option value="">All Regions</option>
                            <?php foreach ($regions as $r): ?>
                                <option value="<?= htmlspecialchars($r['ContinentName']) ?>">
                                    <?= htmlspecialchars($r['ContinentName']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="flex gap-sm mt-sm" style="justify-content: flex-end;">
                    <button type="button" class="btn-reset" onclick="resetFilters()">Clear Filters</button>
                </div>
            </form>
            <div class="results-count" id="resultsCount">Loading...</div>
        </div>

        <div id="tableContainer" class="table-scroll-window">
            <table class="company-table m-0">
                <thead>
                    <tr>
                        <th onclick="sortTable(0)" data-sort="asc">ID <span class="sort-indicator">▲</span></th>
                        <th onclick="sortTable(1)" data-sort="asc">Company Name <span class="sort-indicator"></span></th>
                        <th onclick="sortTable(2)" data-sort="asc">Type <span class="sort-indicator"></span></th>
                        <th onclick="sortTable(3)" data-sort="asc" style="white-space: nowrap; min-width: 80px;">Tier <span class="sort-indicator"></span></th>
                        <th onclick="sortTable(4)" data-sort="asc">Location <span class="sort-indicator"></span></th>
                        <th onclick="sortTable(5)" data-sort="asc">Region <span class="sort-indicator"></span></th>
                        <th onclick="sortTable(6)" data-sort="asc">Health Score <span class="sort-indicator"></span></th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody id="companyTableBody">
                    </tbody>
            </table>
        </div>
    </div>

    <div id="viewModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal()">&times;</span>
            <div id="modalBody">
                </div>
        </div>
    </div>

    <script>
    (function() {
        var currentCompanies = []; 
        var timeout = null;

        // --- RESTORE FILTERS ---
        if(sessionStorage.getItem('comp_id')) document.getElementById('company_id').value = sessionStorage.getItem('comp_id');
        if(sessionStorage.getItem('comp_type'))   document.getElementById('type').value   = sessionStorage.getItem('comp_type');
        if(sessionStorage.getItem('comp_tier'))   document.getElementById('tier').value   = sessionStorage.getItem('comp_tier');
        if(sessionStorage.getItem('comp_region')) document.getElementById('region').value = sessionStorage.getItem('comp_region');

        loadCompanies();

        // --- LISTENERS ---
        var inputs = document.querySelectorAll('#filterForm select');
        inputs.forEach(function(input) {
            input.addEventListener('change', loadCompanies);
        });

        document.getElementById('filterForm').addEventListener('submit', function(e) { e.preventDefault(); loadCompanies(); });

        function loadCompanies() {
            var cId = document.getElementById('company_id').value;
            var tVal = document.getElementById('type').value;
            var trVal = document.getElementById('tier').value;
            var rVal = document.getElementById('region').value;

            // save settings
            sessionStorage.setItem('comp_id', cId);
            sessionStorage.setItem('comp_type', tVal);
            sessionStorage.setItem('comp_tier', trVal);
            sessionStorage.setItem('comp_region', rVal);

            var params = 'ajax=1&company_id=' + encodeURIComponent(cId) +
                '&type=' + encodeURIComponent(tVal) +
                '&tier=' + encodeURIComponent(trVal) +
                '&region=' + encodeURIComponent(rVal);
            
            var xhr = new XMLHttpRequest();
            xhr.open('GET', 'companies.php?' + params, true);
            xhr.onload = function() {
                if (xhr.status === 200) {
                    try {
                        var response = JSON.parse(xhr.responseText);
                        if (response.success) {
                            currentCompanies = response.companies;
                            updateTable(currentCompanies);
                            document.getElementById('resultsCount').textContent = 'Showing ' + response.count + ' companies';
                        }
                    } catch(e) {
                        console.error("JSON Error", e);
                    }
                }
            };
            xhr.send();
        }
        
        function updateTable(companies) {
            var tbody = document.getElementById('companyTableBody');
            tbody.innerHTML = '';
            if (companies.length === 0) {
                tbody.innerHTML = '<tr><td colspan="8" class="no-data">No companies found matching your filters.</td></tr>';
                return;
            }
            companies.forEach(function(comp) {
                var row = document.createElement('tr');
                var healthBadge = '<span class="health-badge health-none">N/A</span>';
                if (comp.HealthScore !== null) {
                    var score = parseFloat(comp.HealthScore);
                    var healthClass = 'health-bad';
                    if (score >= 75) healthClass = 'health-good';
                    else if (score >= 50) healthClass = 'health-warning';
                    healthBadge = '<span class="health-badge ' + healthClass + '">' + score.toFixed(1) + '</span>';
                }
                
                var city = comp.City ? comp.City : 'N/A';
                var country = comp.CountryName ? comp.CountryName : 'N/A';
                var continent = comp.ContinentName ? comp.ContinentName : 'N/A';

                row.innerHTML = '<td>' + esc(comp.CompanyID) + '</td>' +
                    '<td><strong>' + esc(comp.CompanyName) + '</strong></td>' +
                    '<td>' + esc(comp.Type) + '</td>' +
                    '<td style="white-space: nowrap;"><span class="tier-badge">Tier ' + esc(comp.TierLevel) + '</span></td>' +
                    '<td>' + esc(city) + ', ' + esc(country) + '</td>' +
                    '<td>' + esc(continent) + '</td>' +
                    '<td>' + healthBadge + '</td>' + 
                    '<td><button class="btn-secondary" onclick="openModal(' + comp.CompanyID + ')">View Details</button></td>';
                tbody.appendChild(row);
            });
        }
        
        function esc(t) { if (!t) return ''; var d = document.createElement('div'); d.textContent = t; return d.innerHTML; }
        
        // --- SORTING (Javascript Client Side for Speed) ---
        window.sortTable = function(columnIndex) {
            var table = document.querySelector('.company-table');
            var headers = table.querySelectorAll('th');
            if(columnIndex >= 7) return; 

            var currentHeader = headers[columnIndex];
            var currentSort = currentHeader.getAttribute('data-sort');
            var newSort = currentSort === 'asc' ? 'desc' : 'asc';
            
            headers.forEach(function(header) { 
                var ind = header.querySelector('.sort-indicator'); 
                if (ind) ind.textContent = ''; 
            });
            
            var ind = currentHeader.querySelector('.sort-indicator');
            if(ind) ind.textContent = newSort === 'asc' ? '▲' : '▼';
            
            currentHeader.setAttribute('data-sort', newSort);
            
            currentCompanies.sort(function(a, b) {
                var valA, valB;
                switch(columnIndex) {
                    case 0: valA = parseInt(a.CompanyID); valB = parseInt(b.CompanyID); break;
                    case 1: valA = (a.CompanyName || '').toLowerCase(); valB = (b.CompanyName || '').toLowerCase(); break;
                    case 2: valA = (a.Type || '').toLowerCase(); valB = (b.Type || '').toLowerCase(); break;
                    case 3: valA = parseInt(a.TierLevel); valB = parseInt(b.TierLevel); break;
                    case 4: valA = ((a.City || '') + (a.CountryName || '')).toLowerCase(); valB = ((b.City || '') + (b.CountryName || '')).toLowerCase(); break;
                    case 5: valA = (a.ContinentName || '').toLowerCase(); valB = (b.ContinentName || '').toLowerCase(); break;
                    case 6: valA = a.HealthScore !== null ? parseFloat(a.HealthScore) : -999; valB = b.HealthScore !== null ? parseFloat(b.HealthScore) : -999; break;
                }
                if (valA < valB) return newSort === 'asc' ? -1 : 1;
                if (valA > valB) return newSort === 'asc' ? 1 : -1;
                return 0;
            });
            updateTable(currentCompanies);
        };
        
        window.resetFilters = function() {
            document.getElementById('company_id').value = '';
            document.getElementById('type').value = '';
            document.getElementById('tier').value = '';
            document.getElementById('region').value = '';
            sessionStorage.removeItem('comp_id');
            sessionStorage.removeItem('comp_type');
            sessionStorage.removeItem('comp_tier');
            sessionStorage.removeItem('comp_region');
            loadCompanies();
        };

        // --- MODAL STUFF ---
        var modal = document.getElementById("viewModal");
        var modalBody = document.getElementById("modalBody");

        window.openModal = function(id) {
            modal.style.display = "block";
            modalBody.innerHTML = "<div class='loading'>Fetching company data...</div>";

            var xhr = new XMLHttpRequest();
            xhr.open("GET", "companies.php?ajax_view_id=" + id, true);
            
            xhr.onreadystatechange = function () {
                if (xhr.readyState == 4) {
                    if (xhr.status == 200) {
                        modalBody.innerHTML = xhr.responseText;
                    } else {
                        modalBody.innerHTML = "<div class='error text-center' style='padding:20px;'>" +
                            "<h3>Error Loading Data</h3>" +
                            "<p>Server responded with status: " + xhr.status + "</p>" +
                            "</div>";
                    }
                }
            };
            xhr.send();
        };

        window.closeModal = function() {
            modal.style.display = "none";
        };

        window.onclick = function(event) {
            if (event.target == modal) {
                modal.style.display = "none";
            }
        };
    })();
    </script>
</body>
</html>