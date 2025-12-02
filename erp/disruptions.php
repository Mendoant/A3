<?php
// disruptions.php - Company-specific disruption analysis with AJAX
require_once '../config.php';
requireLogin();

// kick out supply chain managers
if (!hasRole('SeniorManager')) {
    header('Location: ../scm/dashboard.php');
    exit;
}

$pdo = getPDO();

// grab filter values
$viewMode = isset($_GET['view_mode']) ? $_GET['view_mode'] : 'by_company';
$selectedCompany = isset($_GET['company_id']) ? $_GET['company_id'] : '';
$selectedEvent = isset($_GET['event_id']) ? $_GET['event_id'] : '';
$impactLevel = isset($_GET['impact_level']) ? $_GET['impact_level'] : '';
$startDate = isset($_GET['start_date']) ? $_GET['start_date'] : '';
$endDate = isset($_GET['end_date']) ? $_GET['end_date'] : '';

// initialize data arrays
$disruptions = array();
$summaryStats = array(
    'totalEvents' => 0,
    'highImpactCount' => 0,
    'avgRecoveryDays' => 0
);

if ($viewMode === 'by_company' && $selectedCompany !== '') {
    // view 1: get all disruptions affecting a specific company with summary stats in SQL
    $sql = "SELECT 
                de.EventID,
                de.EventDate,
                de.EventRecoveryDate,
                dc.CategoryName,
                ic.ImpactLevel,
                CASE 
                    WHEN de.EventRecoveryDate IS NOT NULL 
                    THEN DATEDIFF(de.EventRecoveryDate, de.EventDate)
                    ELSE NULL
                END as recoveryDays
            FROM DisruptionEvent de
            JOIN ImpactsCompany ic ON de.EventID = ic.EventID
            JOIN DisruptionCategory dc ON de.CategoryID = dc.CategoryID
            WHERE ic.AffectedCompanyID = ?";
    
    $params = array($selectedCompany);
    
    // add impact level filter
    if ($impactLevel !== '') {
        $sql .= " AND ic.ImpactLevel = ?";
        $params[] = $impactLevel;
    }
    
    // add date range filters
    if ($startDate !== '') {
        $sql .= " AND de.EventDate >= ?";
        $params[] = $startDate;
    }
    if ($endDate !== '') {
        $sql .= " AND de.EventDate <= ?";
        $params[] = $endDate;
    }
    
    $sql .= " ORDER BY de.EventDate DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $disruptions = $stmt->fetchAll();
    
    // calculate summary stats using SQL aggregation
    $summarySQL = "SELECT 
                    COUNT(*) as totalEvents,
                    SUM(CASE WHEN ic.ImpactLevel = 'High' THEN 1 ELSE 0 END) as highImpactCount,
                    AVG(CASE 
                        WHEN de.EventRecoveryDate IS NOT NULL 
                        THEN DATEDIFF(de.EventRecoveryDate, de.EventDate)
                        ELSE NULL
                    END) as avgRecoveryDays
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
    $summaryStats['avgRecoveryDays'] = $summaryRow['avgRecoveryDays'] !== null 
        ? floatval($summaryRow['avgRecoveryDays']) 
        : 0;
    
} elseif ($viewMode === 'by_event' && $selectedEvent !== '') {
    // view 2: get all companies affected by a specific disruption event
    $sql = "SELECT 
                c.CompanyID,
                c.CompanyName,
                c.Type,
                c.TierLevel,
                l.City,
                l.CountryName,
                l.ContinentName,
                ic.ImpactLevel
            FROM ImpactsCompany ic
            JOIN Company c ON ic.AffectedCompanyID = c.CompanyID
            LEFT JOIN Location l ON c.LocationID = l.LocationID
            WHERE ic.EventID = ?";
    
    $params = array($selectedEvent);
    
    // add impact level filter
    if ($impactLevel !== '') {
        $sql .= " AND ic.ImpactLevel = ?";
        $params[] = $impactLevel;
    }
    
    $sql .= " ORDER BY ic.ImpactLevel DESC, c.CompanyName";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $disruptions = $stmt->fetchAll();
    
    // calculate summary stats using SQL aggregation
    $summarySQL = "SELECT 
                    COUNT(*) as totalCompanies,
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
    
    // get event recovery time using SQL
    $eventStmt = $pdo->prepare("
        SELECT DATEDIFF(EventRecoveryDate, EventDate) as recoveryDays
        FROM DisruptionEvent 
        WHERE EventID = ? AND EventRecoveryDate IS NOT NULL
    ");
    $eventStmt->execute(array($selectedEvent));
    $eventData = $eventStmt->fetch();
    
    $summaryStats['avgRecoveryDays'] = $eventData && $eventData['recoveryDays'] !== null
        ? floatval($eventData['recoveryDays'])
        : 0;
}

// get all companies for dropdown
$companiesStmt = $pdo->query("SELECT CompanyID, CompanyName FROM Company ORDER BY CompanyName");
$companies = $companiesStmt->fetchAll();

// get all disruption events for dropdown
$eventsStmt = $pdo->query("
    SELECT de.EventID, de.EventDate, dc.CategoryName 
    FROM DisruptionEvent de
    JOIN DisruptionCategory dc ON de.CategoryID = dc.CategoryID
    ORDER BY de.EventDate DESC
");
$events = $eventsStmt->fetchAll();

// AJAX response
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Disruption Analysis - ERP System</title>
    <link rel="stylesheet" href="../assets/styles.css">
    <style>
        .view-toggle {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }
        .toggle-btn {
            padding: 10px 20px;
            background: rgba(207,185,145,0.2);
            border: 2px solid #CFB991;
            color: #CFB991;
            border-radius: 4px;
            cursor: pointer;
            font-weight: bold;
        }
        .toggle-btn.active {
            background: #CFB991;
            color: #000;
        }
        .toggle-btn:hover {
            background: rgba(207,185,145,0.3);
        }
        .toggle-btn.active:hover {
            background: #b89968;
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
        .disruption-table {
            width: 100%;
            border-collapse: collapse;
            background: rgba(0,0,0,0.6);
        }
        .disruption-table th {
            background: #CFB991;
            color: #000;
            padding: 12px;
            text-align: left;
            font-weight: bold;
        }
        .disruption-table td {
            padding: 10px 12px;
            border-bottom: 1px solid rgba(207,185,145,0.2);
        }
        .disruption-table tr:hover {
            background: rgba(207,185,145,0.1);
        }
        .impact-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 4px;
            font-size: 0.9em;
            font-weight: bold;
        }
        .impact-high { background: #f44336; color: white; }
        .impact-medium { background: #ff9800; color: white; }
        .impact-low { background: #4caf50; color: white; }
        .tier-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 3px;
            background: rgba(207,185,145,0.3);
            font-size: 0.9em;
        }
        .no-data {
            text-align: center;
            padding: 50px;
            color: #999;
            background: rgba(0,0,0,0.6);
            border-radius: 8px;
        }
        .hidden {
            display: none;
        }
    </style>
</head>
<body>
    <div class="container">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <h1>Disruption Analysis</h1>
            <a href="../logout.php" style="background: #f44336; color: white; padding: 10px 20px; text-decoration: none; border-radius: 4px;">Logout</a>
        </div>

        <nav class="container" style="background: rgba(0,0,0,0.8); padding: 15px 30px; margin-bottom: 30px; border-radius: 8px; display: flex; gap: 20px; flex-wrap: wrap;">
            <a href="dashboard.php">Dashboard</a>
            <a href="financial.php">Financial Health</a>
            <a href="regional_disruptions.php">Regional Disruptions</a>
            <a href="critical_companies.php">Critical Companies</a>
            <a href="timeline.php">Disruption Timeline</a>
            <a href="companies.php">Company List</a>
            <a href="distributors.php">Distributors</a>
            <a href="disruptions.php" class="active">Disruptions</a>
        </nav>

        <div class="view-toggle">
            <button class="toggle-btn <?= $viewMode === 'by_company' ? 'active' : '' ?>" onclick="switchView('by_company')">
                View Disruptions by Company
            </button>
            <button class="toggle-btn <?= $viewMode === 'by_event' ? 'active' : '' ?>" onclick="switchView('by_event')">
                View Companies by Event
            </button>
        </div>

        <div class="filter-section">
            <form id="filterForm">
                <input type="hidden" id="view_mode" name="view_mode" value="<?= htmlspecialchars($viewMode) ?>">
                <div class="filter-row">
                    <div class="filter-group" id="companyFilterGroup" <?= $viewMode === 'by_event' ? 'style="display:none;"' : '' ?>>
                        <label for="company_id">Select Company</label>
                        <select id="company_id" name="company_id">
                            <option value="">-- Select Company --</option>
                            <?php foreach ($companies as $comp): ?>
                                <option value="<?= $comp['CompanyID'] ?>" <?= $selectedCompany == $comp['CompanyID'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($comp['CompanyName']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="filter-group" id="eventFilterGroup" <?= $viewMode === 'by_company' ? 'style="display:none;"' : '' ?>>
                        <label for="event_id">Select Disruption Event</label>
                        <select id="event_id" name="event_id">
                            <option value="">-- Select Event --</option>
                            <?php foreach ($events as $evt): ?>
                                <option value="<?= $evt['EventID'] ?>" <?= $selectedEvent == $evt['EventID'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($evt['CategoryName']) ?> - <?= date('M d, Y', strtotime($evt['EventDate'])) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label for="impact_level">Impact Level</label>
                        <select id="impact_level" name="impact_level">
                            <option value="">All Levels</option>
                            <option value="High" <?= $impactLevel === 'High' ? 'selected' : '' ?>>High</option>
                            <option value="Medium" <?= $impactLevel === 'Medium' ? 'selected' : '' ?>>Medium</option>
                            <option value="Low" <?= $impactLevel === 'Low' ? 'selected' : '' ?>>Low</option>
                        </select>
                    </div>
                    
                    <div class="filter-group" id="startDateGroup" <?= $viewMode === 'by_event' ? 'style="display:none;"' : '' ?>>
                        <label for="start_date">Start Date</label>
                        <input type="date" id="start_date" name="start_date" value="<?= htmlspecialchars($startDate) ?>">
                    </div>
                    
                    <div class="filter-group" id="endDateGroup" <?= $viewMode === 'by_event' ? 'style="display:none;"' : '' ?>>
                        <label for="end_date">End Date</label>
                        <input type="date" id="end_date" name="end_date" value="<?= htmlspecialchars($endDate) ?>">
                    </div>
                    
                    <button type="submit" class="btn-filter">Apply Filters</button>
                </div>
            </form>
        </div>

        <div class="metrics-grid">
            <div class="metric-card">
                <div class="metric-value" id="totalEventsMetric"><?= $summaryStats['totalEvents'] ?></div>
                <div class="metric-label" id="totalEventsLabel">
                    <?= $viewMode === 'by_company' ? 'Total Events' : 'Companies Affected' ?>
                </div>
            </div>
            <div class="metric-card">
                <div class="metric-value" id="highImpactMetric"><?= $summaryStats['highImpactCount'] ?></div>
                <div class="metric-label">High Impact Count</div>
            </div>
            <div class="metric-card">
                <div class="metric-value" id="avgRecoveryMetric"><?= number_format($summaryStats['avgRecoveryDays'], 1) ?></div>
                <div class="metric-label">Avg Recovery Days</div>
            </div>
        </div>

        <div id="resultsContainer">
            <?php if (($viewMode === 'by_company' && $selectedCompany === '') || ($viewMode === 'by_event' && $selectedEvent === '')): ?>
                <div class="no-data">
                    <?php if ($viewMode === 'by_company'): ?>
                        Please select a company to view disruption events.
                    <?php else: ?>
                        Please select a disruption event to view affected companies.
                    <?php endif; ?>
                </div>
            <?php elseif (empty($disruptions)): ?>
                <div class="no-data">
                    No data found for the selected filters.
                </div>
            <?php else: ?>
                <div style="background: rgba(0,0,0,0.6); padding: 20px; border-radius: 8px;">
                    <table class="disruption-table">
                        <thead>
                            <tr id="tableHeaders">
                                <?php if ($viewMode === 'by_company'): ?>
                                    <th>Event ID</th>
                                    <th>Event Date</th>
                                    <th>Category</th>
                                    <th>Impact Level</th>
                                    <th>Recovery Date</th>
                                    <th>Days to Recover</th>
                                <?php else: ?>
                                    <th>Company ID</th>
                                    <th>Company Name</th>
                                    <th>Type</th>
                                    <th>Tier</th>
                                    <th>Location</th>
                                    <th>Region</th>
                                    <th>Impact Level</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody id="disruptionTableBody">
                            <?php foreach ($disruptions as $row): ?>
                                <tr>
                                    <?php if ($viewMode === 'by_company'): ?>
                                        <td><?= htmlspecialchars($row['EventID']) ?></td>
                                        <td><?= date('M d, Y', strtotime($row['EventDate'])) ?></td>
                                        <td><?= htmlspecialchars($row['CategoryName']) ?></td>
                                        <td>
                                            <?php
                                            $impactClass = 'impact-low';
                                            if ($row['ImpactLevel'] === 'High') $impactClass = 'impact-high';
                                            elseif ($row['ImpactLevel'] === 'Medium') $impactClass = 'impact-medium';
                                            ?>
                                            <span class="impact-badge <?= $impactClass ?>"><?= htmlspecialchars($row['ImpactLevel']) ?></span>
                                        </td>
                                        <td><?= $row['EventRecoveryDate'] ? date('M d, Y', strtotime($row['EventRecoveryDate'])) : 'Ongoing' ?></td>
                                        <td><?= $row['recoveryDays'] !== null ? htmlspecialchars($row['recoveryDays']) : 'N/A' ?></td>
                                    <?php else: ?>
                                        <td><?= htmlspecialchars($row['CompanyID']) ?></td>
                                        <td><strong><?= htmlspecialchars($row['CompanyName']) ?></strong></td>
                                        <td><?= htmlspecialchars($row['Type']) ?></td>
                                        <td><span class="tier-badge">Tier <?= htmlspecialchars($row['TierLevel']) ?></span></td>
                                        <td><?= htmlspecialchars($row['City'] ?: 'N/A') ?>, <?= htmlspecialchars($row['CountryName'] ?: 'N/A') ?></td>
                                        <td><?= htmlspecialchars($row['ContinentName'] ?: 'N/A') ?></td>
                                        <td>
                                            <?php
                                            $impactClass = 'impact-low';
                                            if ($row['ImpactLevel'] === 'High') $impactClass = 'impact-high';
                                            elseif ($row['ImpactLevel'] === 'Medium') $impactClass = 'impact-medium';
                                            ?>
                                            <span class="impact-badge <?= $impactClass ?>"><?= htmlspecialchars($row['ImpactLevel']) ?></span>
                                        </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
    (function() {
        var form = document.getElementById('filterForm');
        
        // handle form submission with AJAX
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            loadDisruptions();
            return false;
        });
        
        function loadDisruptions() {
            var viewMode = document.getElementById('view_mode').value;
            var companyId = document.getElementById('company_id').value;
            var eventId = document.getElementById('event_id').value;
            var impactLevel = document.getElementById('impact_level').value;
            var startDate = document.getElementById('start_date').value;
            var endDate = document.getElementById('end_date').value;
            
            var params = 'ajax=1' +
                '&view_mode=' + encodeURIComponent(viewMode) +
                '&company_id=' + encodeURIComponent(companyId) +
                '&event_id=' + encodeURIComponent(eventId) +
                '&impact_level=' + encodeURIComponent(impactLevel) +
                '&start_date=' + encodeURIComponent(startDate) +
                '&end_date=' + encodeURIComponent(endDate);
            
            var xhr = new XMLHttpRequest();
            xhr.open('GET', 'disruptions.php?' + params, true);
            xhr.onload = function() {
                if (xhr.status === 200) {
                    var response = JSON.parse(xhr.responseText);
                    if (response.success) {
                        updateMetrics(response.summaryStats, response.viewMode);
                        updateTable(response.disruptions, response.viewMode);
                    }
                }
            };
            xhr.send();
        }
        
        function updateMetrics(stats, viewMode) {
            document.getElementById('totalEventsMetric').textContent = stats.totalEvents;
            document.getElementById('highImpactMetric').textContent = stats.highImpactCount;
            document.getElementById('avgRecoveryMetric').textContent = parseFloat(stats.avgRecoveryDays).toFixed(1);
            
            // update label based on view mode
            document.getElementById('totalEventsLabel').textContent = 
                viewMode === 'by_company' ? 'Total Events' : 'Companies Affected';
        }
        
        function updateTable(disruptions, viewMode) {
            var container = document.getElementById('resultsContainer');
            
            // check if we need to show "no selection" message
            var companyId = document.getElementById('company_id').value;
            var eventId = document.getElementById('event_id').value;
            
            if ((viewMode === 'by_company' && !companyId) || (viewMode === 'by_event' && !eventId)) {
                container.innerHTML = '<div class="no-data">' +
                    (viewMode === 'by_company' ? 
                        'Please select a company to view disruption events.' : 
                        'Please select a disruption event to view affected companies.') +
                    '</div>';
                return;
            }
            
            if (disruptions.length === 0) {
                container.innerHTML = '<div class="no-data">No data found for the selected filters.</div>';
                return;
            }
            
            // build table
            var tableHTML = '<div style="background: rgba(0,0,0,0.6); padding: 20px; border-radius: 8px;">' +
                '<table class="disruption-table"><thead><tr>';
            
            if (viewMode === 'by_company') {
                tableHTML += '<th>Event ID</th><th>Event Date</th><th>Category</th><th>Impact Level</th><th>Recovery Date</th><th>Days to Recover</th>';
            } else {
                tableHTML += '<th>Company ID</th><th>Company Name</th><th>Type</th><th>Tier</th><th>Location</th><th>Region</th><th>Impact Level</th>';
            }
            
            tableHTML += '</tr></thead><tbody>';
            
            disruptions.forEach(function(row) {
                tableHTML += '<tr>';
                
                if (viewMode === 'by_company') {
                    var impactClass = 'impact-low';
                    if (row.ImpactLevel === 'High') impactClass = 'impact-high';
                    else if (row.ImpactLevel === 'Medium') impactClass = 'impact-medium';
                    
                    var eventDate = new Date(row.EventDate);
                    var recoveryDate = row.EventRecoveryDate ? new Date(row.EventRecoveryDate) : null;
                    
                    tableHTML += '<td>' + escapeHtml(row.EventID) + '</td>';
                    tableHTML += '<td>' + formatDate(eventDate) + '</td>';
                    tableHTML += '<td>' + escapeHtml(row.CategoryName) + '</td>';
                    tableHTML += '<td><span class="impact-badge ' + impactClass + '">' + escapeHtml(row.ImpactLevel) + '</span></td>';
                    tableHTML += '<td>' + (recoveryDate ? formatDate(recoveryDate) : 'Ongoing') + '</td>';
                    tableHTML += '<td>' + (row.recoveryDays !== null ? row.recoveryDays : 'N/A') + '</td>';
                } else {
                    var impactClass = 'impact-low';
                    if (row.ImpactLevel === 'High') impactClass = 'impact-high';
                    else if (row.ImpactLevel === 'Medium') impactClass = 'impact-medium';
                    
                    tableHTML += '<td>' + escapeHtml(row.CompanyID) + '</td>';
                    tableHTML += '<td><strong>' + escapeHtml(row.CompanyName) + '</strong></td>';
                    tableHTML += '<td>' + escapeHtml(row.Type) + '</td>';
                    tableHTML += '<td><span class="tier-badge">Tier ' + escapeHtml(row.TierLevel) + '</span></td>';
                    tableHTML += '<td>' + escapeHtml(row.City || 'N/A') + ', ' + escapeHtml(row.CountryName || 'N/A') + '</td>';
                    tableHTML += '<td>' + escapeHtml(row.ContinentName || 'N/A') + '</td>';
                    tableHTML += '<td><span class="impact-badge ' + impactClass + '">' + escapeHtml(row.ImpactLevel) + '</span></td>';
                }
                
                tableHTML += '</tr>';
            });
            
            tableHTML += '</tbody></table></div>';
            container.innerHTML = tableHTML;
        }
        
        function formatDate(date) {
            var months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
            return months[date.getMonth()] + ' ' + date.getDate() + ', ' + date.getFullYear();
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
        
        // switch between view modes
        window.switchView = function(mode) {
            document.getElementById('view_mode').value = mode;
            
            // toggle filter visibility
            var companyGroup = document.getElementById('companyFilterGroup');
            var eventGroup = document.getElementById('eventFilterGroup');
            var startDateGroup = document.getElementById('startDateGroup');
            var endDateGroup = document.getElementById('endDateGroup');
            
            if (mode === 'by_company') {
                companyGroup.style.display = 'block';
                eventGroup.style.display = 'none';
                startDateGroup.style.display = 'block';
                endDateGroup.style.display = 'block';
            } else {
                companyGroup.style.display = 'none';
                eventGroup.style.display = 'block';
                startDateGroup.style.display = 'none';
                endDateGroup.style.display = 'none';
            }
            
            // update toggle buttons
            var buttons = document.querySelectorAll('.toggle-btn');
            buttons.forEach(function(btn) {
                btn.classList.remove('active');
            });
            event.target.classList.add('active');
            
            // reload data
            loadDisruptions();
        };
    })();
    </script>
</body>
</html>