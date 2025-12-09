<?php
// disruptions.php - Disruption Analysis
// PHP 5.4 Safe: array(), robust AJAX handling, sticky headers

require_once '../config.php';
requireLogin();

// security check
if (!hasRole('SeniorManager')) {
    header('Location: ../scm/dashboard.php');
    exit;
}

$pdo = getPDO();

// --- 1. CAPTURE INPUTS ---
$viewMode = isset($_GET['view_mode']) ? $_GET['view_mode'] : 'by_company';
$selectedCompany = isset($_GET['company_id']) ? $_GET['company_id'] : '';
$selectedEvent = isset($_GET['event_id']) ? $_GET['event_id'] : '';
$impactLevel = isset($_GET['impact_level']) ? $_GET['impact_level'] : '';
$startDate = isset($_GET['start_date']) ? $_GET['start_date'] : '';
$endDate = isset($_GET['end_date']) ? $_GET['end_date'] : '';

$disruptions = array();
$summaryStats = array('totalEvents' => 0, 'highImpactCount' => 0, 'avgRecoveryDays' => 0);

// --- 2. LOGIC: BY COMPANY VIEW ---
if ($viewMode === 'by_company' && $selectedCompany !== '') {
    // A. Fetch Disruption List
    $sql = "SELECT de.EventID, de.EventDate, de.EventRecoveryDate, dc.CategoryName, ic.ImpactLevel,
                CASE WHEN de.EventRecoveryDate IS NOT NULL THEN DATEDIFF(de.EventRecoveryDate, de.EventDate) ELSE NULL END as recoveryDays
            FROM DisruptionEvent de 
            JOIN ImpactsCompany ic ON de.EventID = ic.EventID
            JOIN DisruptionCategory dc ON de.CategoryID = dc.CategoryID 
            WHERE ic.AffectedCompanyID = ?";
    
    $params = array($selectedCompany);
    
    if ($impactLevel !== '') { 
        $sql .= " AND ic.ImpactLevel = ?"; 
        $params[] = $impactLevel; 
    }
    if ($startDate !== '') { 
        $sql .= " AND de.EventDate >= ?"; 
        $params[] = $startDate; 
    }
    if ($endDate !== '') { 
        $sql .= " AND de.EventDate <= ?"; 
        $params[] = $endDate; 
    }
    
    $sql .= " ORDER BY de.EventDate DESC";
    
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $disruptions = $stmt->fetchAll();
        
        // B. Fetch Summary Stats
        $summarySQL = "SELECT COUNT(*) as totalEvents, 
                              SUM(CASE WHEN ic.ImpactLevel = 'High' THEN 1 ELSE 0 END) as highImpactCount,
                              AVG(CASE WHEN de.EventRecoveryDate IS NOT NULL THEN DATEDIFF(de.EventRecoveryDate, de.EventDate) ELSE NULL END) as avgRecoveryDays
                       FROM DisruptionEvent de 
                       JOIN ImpactsCompany ic ON de.EventID = ic.EventID 
                       WHERE ic.AffectedCompanyID = ?";
        
        $summaryParams = array($selectedCompany);
        
        if ($impactLevel !== '') { 
            $summarySQL .= " AND ic.ImpactLevel = ?"; 
            $summaryParams[] = $impactLevel; 
        }
        if ($startDate !== '') { 
            $summarySQL .= " AND de.EventDate >= ?"; 
            $summaryParams[] = $startDate; 
        }
        if ($endDate !== '') { 
            $summarySQL .= " AND de.EventDate <= ?"; 
            $summaryParams[] = $endDate; 
        }
        
        $summaryStmt = $pdo->prepare($summarySQL);
        $summaryStmt->execute($summaryParams);
        $summaryRow = $summaryStmt->fetch();
        
        $summaryStats['totalEvents'] = intval($summaryRow['totalEvents']);
        $summaryStats['highImpactCount'] = intval($summaryRow['highImpactCount']);
        $summaryStats['avgRecoveryDays'] = $summaryRow['avgRecoveryDays'] !== null ? floatval($summaryRow['avgRecoveryDays']) : 0;
        
    } catch (Exception $e) {
        if (isset($_GET['ajax'])) {
            echo json_encode(array('success' => false, 'message' => $e->getMessage()));
            exit;
        }
    }
} 
// --- 3. LOGIC: BY EVENT VIEW ---
elseif ($viewMode === 'by_event' && $selectedEvent !== '') {
    // A. Fetch Affected Companies
    $sql = "SELECT c.CompanyID, c.CompanyName, c.Type, c.TierLevel, l.City, l.CountryName, l.ContinentName, ic.ImpactLevel
            FROM ImpactsCompany ic 
            JOIN Company c ON ic.AffectedCompanyID = c.CompanyID
            LEFT JOIN Location l ON c.LocationID = l.LocationID 
            WHERE ic.EventID = ?";
    
    $params = array($selectedEvent);
    
    if ($impactLevel !== '') { 
        $sql .= " AND ic.ImpactLevel = ?"; 
        $params[] = $impactLevel; 
    }
    $sql .= " ORDER BY ic.ImpactLevel DESC, c.CompanyName";
    
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $disruptions = $stmt->fetchAll();
        
        // B. Summary Stats
        $summarySQL = "SELECT COUNT(*) as totalCompanies, 
                              SUM(CASE WHEN ic.ImpactLevel = 'High' THEN 1 ELSE 0 END) as highImpactCount
                       FROM ImpactsCompany ic 
                       WHERE ic.EventID = ?";
        
        $summaryParams = array($selectedEvent);
        
        if ($impactLevel !== '') { 
            $summarySQL .= " AND ic.ImpactLevel = ?"; 
            $summaryParams[] = $impactLevel; 
        }
        
        $summaryStmt = $pdo->prepare($summarySQL);
        $summaryStmt->execute($summaryParams);
        $summaryRow = $summaryStmt->fetch();
        
        $summaryStats['totalEvents'] = intval($summaryRow['totalCompanies']);
        $summaryStats['highImpactCount'] = intval($summaryRow['highImpactCount']);
        
        // Event specific recovery
        $eventStmt = $pdo->prepare("SELECT DATEDIFF(EventRecoveryDate, EventDate) as recoveryDays FROM DisruptionEvent WHERE EventID = ? AND EventRecoveryDate IS NOT NULL");
        $eventStmt->execute(array($selectedEvent));
        $eventData = $eventStmt->fetch();
        $summaryStats['avgRecoveryDays'] = ($eventData && $eventData['recoveryDays'] !== null) ? floatval($eventData['recoveryDays']) : 0;
        
    } catch (Exception $e) {
        if (isset($_GET['ajax'])) {
            echo json_encode(array('success' => false, 'message' => $e->getMessage()));
            exit;
        }
    }
}

// Dropdowns
$companies = $pdo->query("SELECT CompanyID, CompanyName FROM Company ORDER BY CompanyName")->fetchAll();
$events = $pdo->query("SELECT de.EventID, de.EventDate, dc.CategoryName FROM DisruptionEvent de JOIN DisruptionCategory dc ON de.CategoryID = dc.CategoryID ORDER BY de.EventDate DESC")->fetchAll();

// AJAX Return
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    echo json_encode(array(
        'success' => true, 
        'disruptions' => $disruptions, 
        'summaryStats' => $summaryStats, 
        'viewMode' => $viewMode
    ));
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Disruption Analysis - ERP System</title>
    <script src="../assets/forward_protection.js"></script>
    <link rel="stylesheet" href="../assets/styles.css">
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
        /* Sticky Header Fix: Solid background + High Z-index */
        .table-scroll-window thead th {
            position: sticky;
            top: 0;
            z-index: 1000;
            background-color: #1a1a1a; 
            border-bottom: 3px solid var(--purdue-gold);
            box-shadow: 0 2px 5px rgba(0,0,0,0.5);
            color: #ffffff;
        }
        /* 4-column filter grid */
        .filter-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 15px;
            align-items: end;
        }
        @media (max-width: 1200px) {
            .filter-grid { grid-template-columns: repeat(2, 1fr); }
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
        <a href="distributors.php">Distributors</a>
        <a href="disruptions.php" class="active">Disruption Analysis</a>
    </nav>

    <div class="container">
        <div class="flex flex-between align-items-center mb-md">
            <h2>Disruption Analysis</h2>
            
            <div class="view-toggle m-0">
                <button id="btn-by-company" class="toggle-btn active">By Company</button>
                <button id="btn-by-event" class="toggle-btn">By Event</button>
            </div>
        </div>

        <div class="filter-section">
            <form id="filterForm" onsubmit="return false;">
                <input type="hidden" id="view_mode" name="view_mode" value="by_company">
                <div class="filter-grid">
                    <div class="filter-group" id="companyFilterGroup">
                        <label>Select Company</label>
                        <select id="company_id" name="company_id">
                            <option value="">-- Select Company --</option>
                            <?php foreach ($companies as $comp): ?>
                                <option value="<?= $comp['CompanyID'] ?>">
                                    <?= htmlspecialchars($comp['CompanyName']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="filter-group" id="eventFilterGroup" style="display:none;">
                        <label>Select Disruption Event</label>
                        <select id="event_id" name="event_id">
                            <option value="">-- Select Event --</option>
                            <?php foreach ($events as $evt): ?>
                                <option value="<?= $evt['EventID'] ?>">
                                    <?= htmlspecialchars($evt['CategoryName']) ?> - <?= date('M d, Y', strtotime($evt['EventDate'])) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label>Impact Level</label>
                        <select id="impact_level" name="impact_level">
                            <option value="">All Levels</option>
                            <option value="High">High</option>
                            <option value="Medium">Medium</option>
                            <option value="Low">Low</option>
                        </select>
                    </div>
                    
                    <div class="filter-group" id="startDateGroup">
                        <label>Start Date</label>
                        <input type="date" id="start_date" name="start_date">
                    </div>
                    
                    <div class="filter-group" id="endDateGroup">
                        <label>End Date</label>
                        <input type="date" id="end_date" name="end_date">
                    </div>
                </div>
                
                <div class="flex gap-sm mt-sm" style="justify-content: flex-end;">
                    <button type="button" id="resetBtn" class="btn-reset">Reset Filters</button>
                </div>
            </form>
        </div>

        <div class="stats-grid">
            <div class="stat-card">
                <h3 id="totalEventsMetric"><?= $summaryStats['totalEvents'] ?></h3>
                <p id="totalEventsLabel">Total Events</p>
            </div>
            <div class="stat-card">
                <h3 id="highImpactMetric"><?= $summaryStats['highImpactCount'] ?></h3>
                <p>High Impact Count</p>
            </div>
            <div class="stat-card">
                <h3 id="avgRecoveryMetric"><?= number_format($summaryStats['avgRecoveryDays'], 1) ?></h3>
                <p>Avg Recovery Days</p>
            </div>
        </div>

        <div class="content-section">
            <h3 class="mb-sm">Analysis Results</h3>
            <div id="resultsContainer" class="table-scroll-window">
                <p class="no-data" style="text-align:center; padding:50px;">Please select a company above to view disruption history.</p>
            </div>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // --- RESTORE ---
        if(sessionStorage.getItem('dis_mode')) switchView(sessionStorage.getItem('dis_mode'));
        if(sessionStorage.getItem('dis_comp')) document.getElementById('company_id').value = sessionStorage.getItem('dis_comp');
        if(sessionStorage.getItem('dis_event')) document.getElementById('event_id').value = sessionStorage.getItem('dis_event');
        if(sessionStorage.getItem('dis_impact')) document.getElementById('impact_level').value = sessionStorage.getItem('dis_impact');
        if(sessionStorage.getItem('dis_start')) document.getElementById('start_date').value = sessionStorage.getItem('dis_start');
        if(sessionStorage.getItem('dis_end')) document.getElementById('end_date').value = sessionStorage.getItem('dis_end');

        // Trigger load if data is selected
        if(document.getElementById('company_id').value || document.getElementById('event_id').value) {
            loadDisruptions();
        }

        // --- LISTENERS ---
        var inputs = document.querySelectorAll('#filterForm input, #filterForm select');
        for(var i=0; i<inputs.length; i++) {
            inputs[i].addEventListener('change', loadDisruptions);
        }

        // Button Listeners
        document.getElementById('btn-by-company').addEventListener('click', function() {
            switchView('by_company');
            loadDisruptions();
        });
        
        document.getElementById('btn-by-event').addEventListener('click', function() {
            switchView('by_event');
            loadDisruptions();
        });

        document.getElementById('resetBtn').addEventListener('click', function() {
            sessionStorage.removeItem('dis_mode');
            sessionStorage.removeItem('dis_comp');
            sessionStorage.removeItem('dis_event');
            sessionStorage.removeItem('dis_impact');
            sessionStorage.removeItem('dis_start');
            sessionStorage.removeItem('dis_end');
            
            document.getElementById('company_id').value = '';
            document.getElementById('event_id').value = '';
            document.getElementById('impact_level').value = '';
            document.getElementById('start_date').value = '';
            document.getElementById('end_date').value = '';
            
            switchView('by_company');
            loadDisruptions();
        });

        function switchView(mode) {
            document.getElementById('view_mode').value = mode;
            var companyGroup = document.getElementById('companyFilterGroup');
            var eventGroup = document.getElementById('eventFilterGroup');
            var startGroup = document.getElementById('startDateGroup');
            var endGroup = document.getElementById('endDateGroup');
            
            if (mode === 'by_company') {
                companyGroup.style.display = 'block'; eventGroup.style.display = 'none';
                startGroup.style.display = 'block'; endGroup.style.display = 'block';
                document.getElementById('btn-by-company').classList.add('active');
                document.getElementById('btn-by-event').classList.remove('active');
            } else {
                companyGroup.style.display = 'none'; eventGroup.style.display = 'block';
                startGroup.style.display = 'none'; endGroup.style.display = 'none';
                document.getElementById('btn-by-company').classList.remove('active');
                document.getElementById('btn-by-event').classList.add('active');
            }
            sessionStorage.setItem('dis_mode', mode);
        }

        function loadDisruptions() {
            var viewMode = document.getElementById('view_mode').value;
            document.getElementById('resultsContainer').style.opacity = '0.5';

            var compId = document.getElementById('company_id').value;
            var evtId = document.getElementById('event_id').value;
            var imp = document.getElementById('impact_level').value;
            var sDate = document.getElementById('start_date').value;
            var eDate = document.getElementById('end_date').value;

            // save
            sessionStorage.setItem('dis_comp', compId);
            sessionStorage.setItem('dis_event', evtId);
            sessionStorage.setItem('dis_impact', imp);
            sessionStorage.setItem('dis_start', sDate);
            sessionStorage.setItem('dis_end', eDate);

            var params = 'ajax=1' +
                '&view_mode=' + encodeURIComponent(viewMode) +
                '&company_id=' + encodeURIComponent(compId) +
                '&event_id=' + encodeURIComponent(evtId) +
                '&impact_level=' + encodeURIComponent(imp) +
                '&start_date=' + encodeURIComponent(sDate) +
                '&end_date=' + encodeURIComponent(eDate);
            
            var xhr = new XMLHttpRequest();
            xhr.open('GET', 'disruptions.php?' + params, true);
            xhr.onload = function() {
                if (xhr.status === 200) {
                    try {
                        // DEBUG: Log response if it's not JSON
                        if (xhr.responseText.trim().indexOf('{') !== 0) {
                            console.error("Received Invalid JSON:", xhr.responseText);
                            document.getElementById('resultsContainer').innerHTML = '<p class="error" style="padding:50px;">Error: Server returned unexpected data. You may need to log in again.</p>';
                            return;
                        }

                        var response = JSON.parse(xhr.responseText);
                        if (response.success) {
                            updateMetrics(response.summaryStats, response.viewMode);
                            updateTable(response.disruptions, response.viewMode);
                            document.getElementById('resultsContainer').style.opacity = '1';
                        } else {
                            document.getElementById('resultsContainer').innerHTML = '<p class="error">Error: ' + (response.message || 'Unknown error') + '</p>';
                        }
                    } catch(e) {
                        console.error('JSON Parsing Error:', e);
                    }
                } else {
                    document.getElementById('resultsContainer').innerHTML = '<p class="error">Server Error: ' + xhr.status + '</p>';
                }
            };
            xhr.send();
        }
        
        function updateMetrics(stats, viewMode) {
            document.getElementById('totalEventsMetric').textContent = stats.totalEvents;
            document.getElementById('highImpactMetric').textContent = stats.highImpactCount;
            document.getElementById('avgRecoveryMetric').textContent = parseFloat(stats.avgRecoveryDays).toFixed(1);
            document.getElementById('totalEventsLabel').textContent = viewMode === 'by_company' ? 'Total Events' : 'Companies Affected';
        }
        
        function updateTable(disruptions, viewMode) {
            var container = document.getElementById('resultsContainer');
            container.innerHTML = ''; 
            
            if (disruptions.length === 0) {
                var companyId = document.getElementById('company_id').value;
                var eventId = document.getElementById('event_id').value;
                var msg = '';
                if ((viewMode === 'by_company' && !companyId) || (viewMode === 'by_event' && !eventId)) {
                    msg = (viewMode === 'by_company' ? 'Please select a company above to view disruption history.' : 'Please select a disruption event above to view affected companies.');
                } else {
                    msg = 'No data found for the selected filters.';
                }
                container.innerHTML = '<p class="no-data" style="text-align:center; padding:50px;">' + msg + '</p>';
                return;
            }
            
            var tableHTML = '<table class="disruption-table m-0"><thead><tr>';
            if (viewMode === 'by_company') tableHTML += '<th>Event ID</th><th>Event Date</th><th>Category</th><th>Impact Level</th><th>Recovery Date</th><th>Days to Recover</th>';
            else tableHTML += '<th>Company ID</th><th>Company Name</th><th>Type</th><th>Tier</th><th>Location</th><th>Region</th><th>Impact Level</th>';
            tableHTML += '</tr></thead><tbody>';
            
            for(var i=0; i<disruptions.length; i++) {
                var row = disruptions[i];
                var impactClass = 'impact-low';
                if (row.ImpactLevel === 'High') impactClass = 'impact-high';
                else if (row.ImpactLevel === 'Medium') impactClass = 'impact-medium';
                
                tableHTML += '<tr>';
                if (viewMode === 'by_company') {
                    tableHTML += '<td>' + esc(row.EventID) + '</td><td>' + formatDate(row.EventDate) + '</td><td>' + esc(row.CategoryName) + '</td>' +
                        '<td><span class="impact-badge ' + impactClass + '">' + esc(row.ImpactLevel) + '</span></td>' +
                        '<td>' + (row.EventRecoveryDate ? formatDate(row.EventRecoveryDate) : 'Ongoing') + '</td>' +
                        '<td>' + (row.recoveryDays !== null ? row.recoveryDays : 'N/A') + '</td>';
                } else {
                    tableHTML += '<td>' + esc(row.CompanyID) + '</td><td><strong>' + esc(row.CompanyName) + '</strong></td>' +
                        '<td>' + esc(row.Type) + '</td><td><span class="tier-badge">Tier ' + esc(row.TierLevel) + '</span></td>' +
                        '<td>' + esc(row.City || 'N/A') + ', ' + esc(row.CountryName || 'N/A') + '</td><td>' + esc(row.ContinentName || 'N/A') + '</td>' +
                        '<td><span class="impact-badge ' + impactClass + '">' + esc(row.ImpactLevel) + '</span></td>';
                }
                tableHTML += '</tr>';
            }
            container.innerHTML = tableHTML + '</tbody></table>';
        }
        
        function formatDate(dateString) {
            if (!dateString) return '';
            // simple parsing for yyyy-mm-dd
            var parts = dateString.split('-');
            if(parts.length === 3) {
                var d = new Date(parts[0], parts[1]-1, parts[2]);
                return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
            }
            return dateString;
        }
        
        function esc(t) { if (!t) return ''; var d = document.createElement('div'); d.textContent = t; return d.innerHTML; }
    });
    </script>
</body>
</html>