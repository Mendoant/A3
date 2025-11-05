<?php
// dashboard_erp.php - Senior Manager module extended functionality (adjusted SQL to match schema in create_db.sql)
// NOTE: This file assumes existence of helper functions in config.php:
// - getPDO() that returns a PDO connected to the database
// - session_start() is called inside config.php or nav.php as appropriate
require_once 'config.php';

if (!isset($_SESSION['Username'])) {
    header('Location: index.php');
    exit;
}

// Input sanitization / defaults
$start_date = $_GET['start_date'] ?? date('Y-m-d', strtotime('-365 days'));
$end_date   = $_GET['end_date']   ?? date('Y-m-d');
$company_id = isset($_GET['company_id']) ? (int)$_GET['company_id'] : null;
$distributor_id = isset($_GET['distributor_id']) ? (int)$_GET['distributor_id'] : null;
$disruption_id = isset($_GET['disruption_id']) ? (int)$_GET['disruption_id'] : null;
$filter_region = isset($_GET['region']) ? $_GET['region'] : null;

$pdo = getPDO();

// Helper to check if a table exists in the current DB/schema
function tableExists(PDO $pdo, string $tableName): bool {
    try {
        // Using information_schema is portable for MySQL. If not available, fallback to a simple query.
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :t");
        $stmt->execute([':t' => $tableName]);
        return (bool)$stmt->fetchColumn();
    } catch (Exception $e) {
        // As a safe fallback, attempt a simple query and catch any exception
        try {
            $pdo->query("SELECT 1 FROM `$tableName` LIMIT 1");
            return true;
        } catch (Exception $e2) {
            return false;
        }
    }
}

$hasRegion = tableExists($pdo, 'Region');

// Fetch regions list only if the Region table exists; otherwise provide an empty list
$regions = [];
if ($hasRegion) {
    try {
        $regions = $pdo->query("SELECT RegionID, RegionName FROM Region ORDER BY RegionName")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        // If query fails for some reason, fall back to empty
        $regions = [];
    }
}

// Important note: the schema defined in create_db.sql uses these tables/names:
// - FinancialReport (CompanyID, Quarter, RepYear, HealthScore) -- no Date column
// - DisruptionEvent (EventID, EventDate, EventRecoveryDate, CategoryID)
// - ImpactsCompany (EventID, AffectedCompanyID, ImpactLevel)
// - DependsOn (UpstreamCompanyID, DownstreamCompanyID)
// - Shipping (ShipmentID, TransactionID, DistributorID, ProductID, SourceCompanyID, DestinationCompanyID, PromisedDate, ActualDate, Quantity)
// - Company (CompanyID, CompanyName, LocationID, TierLevel, Type)
// - Location (LocationID, CountryName, ContinentName)
// The queries below have been adjusted to use these tables/columns. For time-range filtering of FinancialReport we map by year (RepYear) using the YEAR() of the provided dates.

// Convert start/end to years for FinancialReport filtering
$start_year = (int)date('Y', strtotime($start_date));
$end_year = (int)date('Y', strtotime($end_date));

// 1) Average financial health by company over user-defined time period, also by company type, sorted desc
// FinancialReport doesn't have a Date column, so we filter by RepYear between the years of the provided start/end.
$sql_avg_health = "
SELECT c.CompanyID, c.CompanyName, c.Type,
       AVG(fr.HealthScore) AS AvgHealth
FROM Company c
LEFT JOIN FinancialReport fr ON fr.CompanyID = c.CompanyID AND fr.RepYear BETWEEN :start_year AND :end_year
GROUP BY c.CompanyID, c.CompanyName, c.Type
ORDER BY AvgHealth DESC, c.CompanyName
LIMIT 200
";
$stmt = $pdo->prepare($sql_avg_health);
$stmt->execute([':start_year' => $start_year, ':end_year' => $end_year]);
$avg_health_by_company = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Also grouped by Type (for a grouped bar)
$sql_by_type = "
SELECT c.Type, AVG(fr.HealthScore) AS AvgHealth
FROM Company c
LEFT JOIN FinancialReport fr ON fr.CompanyID = c.CompanyID AND fr.RepYear BETWEEN :start_year AND :end_year
GROUP BY c.Type
ORDER BY AvgHealth DESC
";
$stmt = $pdo->prepare($sql_by_type);
$stmt->execute([':start_year' => $start_year, ':end_year' => $end_year]);
$avg_health_by_type = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 2) Regional disruption overview: total disruptions and total high impact disruptions by region
// There is no Region table in the provided schema, but Company references Location. We will aggregate by Location.CountryName.
// If Location table does not exist, fall back to a 'Global' aggregation.
if (tableExists($pdo, 'Location')) {
    $sql_region_disruptions = "
    SELECT COALESCE(l.CountryName, 'Unknown') AS RegionName,
           COUNT(ic.EventID) AS TotalDisruptions,
           SUM(CASE WHEN ic.ImpactLevel = 'High' THEN 1 ELSE 0 END) AS HighImpactCount
    FROM Location l
    LEFT JOIN Company c ON c.LocationID = l.LocationID
    LEFT JOIN ImpactsCompany ic ON ic.AffectedCompanyID = c.CompanyID
    LEFT JOIN DisruptionEvent de ON de.EventID = ic.EventID AND de.EventDate BETWEEN :start AND :end
    WHERE (de.EventID IS NOT NULL OR ic.EventID IS NULL) -- ensure left join semantics keep countries even if no disruptions
    GROUP BY COALESCE(l.CountryName, 'Unknown')
    ORDER BY HighImpactCount DESC, TotalDisruptions DESC
    ";
    $stmt = $pdo->prepare($sql_region_disruptions);
    $stmt->execute([':start' => $start_date, ':end' => $end_date]);
    $region_disruptions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    // Minimal fallback: global totals
    $stmt = $pdo->prepare("
        SELECT COUNT(ic.EventID) AS TotalDisruptions,
               SUM(CASE WHEN ic.ImpactLevel = 'High' THEN 1 ELSE 0 END) AS HighImpactCount
        FROM ImpactsCompany ic
        JOIN DisruptionEvent de ON de.EventID = ic.EventID
        WHERE de.EventDate BETWEEN :start AND :end
    ");
    $stmt->execute([':start' => $start_date, ':end' => $end_date]);
    $tot = $stmt->fetch(PDO::FETCH_ASSOC);
    $region_disruptions = [
        ['RegionName' => 'Global', 'TotalDisruptions' => $tot['TotalDisruptions'] ?: 0, 'HighImpactCount' => $tot['HighImpactCount'] ?: 0]
    ];
}

// 3) Most critical companies sorted by Criticality = # downstream affected * HighImpactCount
// Use DependsOn (UpstreamCompanyID, DownstreamCompanyID) to count downstream companies, and ImpactsCompany/DisruptionEvent for high impact counts.
$sql_critical_companies = "
SELECT
  c.CompanyID,
  c.CompanyName,
  COALESCE(down.DownstreamCount, 0) AS DownstreamAffected,
  COALESCE(high.HighImpactCount, 0) AS HighImpactCount,
  (COALESCE(down.DownstreamCount,0) * COALESCE(high.HighImpactCount,0)) AS Criticality
FROM Company c
LEFT JOIN (
    SELECT dp.UpstreamCompanyID, COUNT(DISTINCT dp.DownstreamCompanyID) AS DownstreamCount
    FROM DependsOn dp
    GROUP BY dp.UpstreamCompanyID
) down ON down.UpstreamCompanyID = c.CompanyID
LEFT JOIN (
    SELECT ic.AffectedCompanyID AS CompanyID, SUM(CASE WHEN ic.ImpactLevel = 'High' THEN 1 ELSE 0 END) AS HighImpactCount
    FROM ImpactsCompany ic
    JOIN DisruptionEvent de ON de.EventID = ic.EventID
    WHERE de.EventDate BETWEEN :start AND :end
    GROUP BY ic.AffectedCompanyID
) high ON high.CompanyID = c.CompanyID
ORDER BY Criticality DESC
LIMIT 50
";
$stmt = $pdo->prepare($sql_critical_companies);
$stmt->execute([':start' => $start_date, ':end' => $end_date]);
$critical_companies = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 4) Disruption frequency over time (by day) within period
$sql_disruption_time = "
SELECT DATE(de.EventDate) AS Day, COUNT(*) AS Disruptions
FROM DisruptionEvent de
WHERE de.EventDate BETWEEN :start AND :end
GROUP BY DATE(de.EventDate)
ORDER BY DATE(de.EventDate)
";
$stmt = $pdo->prepare($sql_disruption_time);
$stmt->execute([':start' => $start_date, ':end' => $end_date]);
$disruptions_over_time = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 5) Company financials by region, ability to bring up any company's data
// We'll join Company -> Location for region-like grouping, and use RepYear range for FinancialReport.
if (tableExists($pdo, 'Location')) {
    $sql_company_fin_by_region = "
    SELECT l.LocationID, COALESCE(l.CountryName, 'Unknown') AS RegionName, c.CompanyID, c.CompanyName,
           AVG(fr.HealthScore) AS AvgHealth
    FROM Location l
    LEFT JOIN Company c ON c.LocationID = l.LocationID
    LEFT JOIN FinancialReport fr ON fr.CompanyID = c.CompanyID AND fr.RepYear BETWEEN :start_year AND :end_year
    GROUP BY l.LocationID, COALESCE(l.CountryName, 'Unknown'), c.CompanyID, c.CompanyName
    ORDER BY RegionName, AvgHealth DESC
    ";
} else {
    $sql_company_fin_by_region = "
    SELECT NULL AS LocationID, 'Unknown' AS RegionName, c.CompanyID, c.CompanyName,
           AVG(fr.HealthScore) AS AvgHealth
    FROM Company c
    LEFT JOIN FinancialReport fr ON fr.CompanyID = c.CompanyID AND fr.RepYear BETWEEN :start_year AND :end_year
    GROUP BY c.CompanyID, c.CompanyName
    ORDER BY RegionName, AvgHealth DESC
    ";
}
$stmt = $pdo->prepare($sql_company_fin_by_region);
$stmt->execute([':start_year' => $start_year, ':end_year' => $end_year]);
$company_fin_by_region = $stmt->fetchAll(PDO::FETCH_ASSOC);

// If user requested a specific company, fetch its time series financials (by RepYear) and disruptions (via ImpactsCompany + DisruptionEvent)
$company_time_series = [];
$company_disruptions = [];
$company_details = null;
if ($company_id) {
    $stmt = $pdo->prepare("SELECT CompanyID, CompanyName, Type, LocationID FROM Company WHERE CompanyID = :id");
    $stmt->execute([':id' => $company_id]);
    $company_details = $stmt->fetch(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("SELECT RepYear AS Year, Quarter, HealthScore FROM FinancialReport WHERE CompanyID = :id AND RepYear BETWEEN :start_year AND :end_year ORDER BY RepYear, Quarter");
    $stmt->execute([':id' => $company_id, ':start_year' => $start_year, ':end_year' => $end_year]);
    $company_time_series = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("
        SELECT de.EventID, de.EventDate AS Date, ic.ImpactLevel AS Impact, dc.CategoryName AS Category
        FROM DisruptionEvent de
        JOIN ImpactsCompany ic ON ic.EventID = de.EventID
        LEFT JOIN DisruptionCategory dc ON dc.CategoryID = de.CategoryID
        WHERE ic.AffectedCompanyID = :id AND de.EventDate BETWEEN :start AND :end
        ORDER BY de.EventDate DESC
    ");
    $stmt->execute([':id' => $company_id, ':start' => $start_date, ':end' => $end_date]);
    $company_disruptions = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// 6) Top distributors by shipment volume, ability to bring up any distributor
// Use Shipping table's Quantity and PromisedDate/ActualDate
$sql_top_distributors = "
SELECT c.CompanyID AS DistributorID, c.CompanyName, COALESCE(SUM(s.Quantity),0) AS TotalVolume
FROM Company c
JOIN Distributor d ON d.CompanyID = c.CompanyID
LEFT JOIN Shipping s ON s.DistributorID = d.CompanyID AND s.PromisedDate BETWEEN :start AND :end
GROUP BY c.CompanyID, c.CompanyName
ORDER BY TotalVolume DESC
LIMIT 20
";
$stmt = $pdo->prepare($sql_top_distributors);
$stmt->execute([':start' => $start_date, ':end' => $end_date]);
$top_distributors = $stmt->fetchAll(PDO::FETCH_ASSOC);

$distributor_details = [];
$distributor_shipments = [];
if ($distributor_id) {
    $stmt = $pdo->prepare("SELECT CompanyID, CompanyName FROM Company WHERE CompanyID = :id");
    $stmt->execute([':id' => $distributor_id]);
    $distributor_details = $stmt->fetch(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("SELECT ShipmentID, PromisedDate AS Date, Quantity AS Volume, DATEDIFF(IFNULL(ActualDate, PromisedDate), PromisedDate) AS Delay FROM Shipping WHERE DistributorID = :id AND PromisedDate BETWEEN :start AND :end ORDER BY PromisedDate DESC");
    $stmt->execute([':id' => $distributor_id, ':start' => $start_date, ':end' => $end_date]);
    $distributor_shipments = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// 7) Companies affected by specific disruption event
$companies_for_disruption = [];
if ($disruption_id) {
    $stmt = $pdo->prepare("
        SELECT de.EventID, de.EventDate AS Date, de.CategoryID
        FROM DisruptionEvent de
        WHERE de.EventID = :id
    ");
    $stmt->execute([':id' => $disruption_id]);
    $disruption_info = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($disruption_info) {
        // direct affected companies (from ImpactsCompany)
        $stmt = $pdo->prepare("
            SELECT c.CompanyID, c.CompanyName
            FROM ImpactsCompany ic
            JOIN Company c ON c.CompanyID = ic.AffectedCompanyID
            WHERE ic.EventID = :id
            LIMIT 500
        ");
        $stmt->execute([':id' => $disruption_id]);
        $companies_for_disruption = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // downstream via DependsOn: find downstream companies of any directly affected company
        if (!empty($companies_for_disruption)) {
            $affectedIds = array_map(function($r){ return (int)$r['CompanyID']; }, $companies_for_disruption);
            // prepare IN clause safely
            $placeholders = implode(',', array_fill(0, count($affectedIds), '?'));
            $params = $affectedIds;
            $sql_down = "
                SELECT DISTINCT c.CompanyID, c.CompanyName
                FROM DependsOn dp
                JOIN Company c ON c.CompanyID = dp.DownstreamCompanyID
                WHERE dp.UpstreamCompanyID IN ($placeholders)
                LIMIT 500
            ";
            $stmt = $pdo->prepare($sql_down);
            $stmt->execute($params);
            $downstream = $stmt->fetchAll(PDO::FETCH_ASSOC);
            // merge
            foreach ($downstream as $d) $companies_for_disruption[] = $d;
        }
    }
}

// 8) All disruptions for a specific company handled above in $company_disruptions

// 9) Distributors sorted by average delay (using Shipping ActualDate vs PromisedDate)
$sql_distributor_avg_delay = "
SELECT c.CompanyID, c.CompanyName, AVG(DATEDIFF(s.ActualDate, s.PromisedDate)) AS AvgDelay, COUNT(s.ShipmentID) AS Shipments
FROM Company c
JOIN Distributor d ON d.CompanyID = c.CompanyID
LEFT JOIN Shipping s ON s.DistributorID = d.CompanyID AND s.PromisedDate BETWEEN :start AND :end AND s.ActualDate IS NOT NULL
GROUP BY c.CompanyID, c.CompanyName
ORDER BY AvgDelay DESC
LIMIT 50
";
$stmt = $pdo->prepare($sql_distributor_avg_delay);
$stmt->execute([':start' => $start_date, ':end' => $end_date]);
$distributors_by_delay = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 10) Ability to add a new company
$add_company_msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_company') {
    $name = trim($_POST['company_name'] ?? '');
    $type = trim($_POST['company_type'] ?? '');
    $locationId = isset($_POST['location_id']) && $_POST['location_id'] !== '' ? (int)$_POST['location_id'] : null;

    if ($name === '') {
        $add_company_msg = 'Company name is required.';
    } else {
        // Determine columns in Company to insert appropriately
        $cols = $pdo->query("SHOW COLUMNS FROM Company")->fetchAll(PDO::FETCH_COLUMN);
        if (in_array('LocationID', $cols, true) && $locationId !== null) {
            $ins = $pdo->prepare("INSERT INTO Company (CompanyName, Type, LocationID) VALUES (:name, :type, :location)");
            $ok = $ins->execute([':name' => $name, ':type' => $type ?: null, ':location' => $locationId]);
        } elseif (in_array('LocationID', $cols, true)) {
            $ins = $pdo->prepare("INSERT INTO Company (CompanyName, Type, LocationID) VALUES (:name, :type, NULL)");
            $ok = $ins->execute([':name' => $name, ':type' => $type ?: null]);
        } else {
            $ins = $pdo->prepare("INSERT INTO Company (CompanyName, Type) VALUES (:name, :type)");
            $ok = $ins->execute([':name' => $name, ':type' => $type ?: null]);
        }

        if (!empty($ok)) {
            $add_company_msg = 'Company added successfully.';
        } else {
            $add_company_msg = 'Failed to add company. Check server logs for details.';
        }
    }
}

// Note: presentation layer below expects some variables to exist. The queries have been adjusted to match the schema.
?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>ERP / Senior Manager Dashboard</title>
    <link rel="stylesheet" href="assets/styles.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        main.container { max-width:1200px; margin:20px auto; }
        section { margin-bottom:30px; padding:12px; border:1px solid #eee; border-radius:6px; background:#fafafa; }
        .flex-row { display:flex; gap:18px; align-items:flex-start; flex-wrap:wrap; }
        .half { flex:1 1 48%; min-width:300px; }
        table { width:100%; border-collapse:collapse; }
        table th, table td { padding:8px; border:1px solid #ddd; text-align:left; }
    </style>
</head>
<body>
<?php include 'nav.php'; ?>
<main class="container">
    <h2>Senior Manager Module</h2>

    <section class="filters">
        <form method="get" class="flex-row">
            <label>Start <input type="date" name="start_date" value="<?= htmlspecialchars($start_date) ?>"></label>
            <label>End <input type="date" name="end_date" value="<?= htmlspecialchars($end_date) ?>"></label>
            <label>Region
                <select name="region">
                    <option value="">All</option>
                    <?php foreach ($regions as $r): ?>
                        <option value="<?= htmlspecialchars($r['RegionID']) ?>" <?= $filter_region == $r['RegionID'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($r['RegionName']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button type="submit">Apply</button>
            <div style="margin-left:auto;">
                <label>View company ID <input type="number" name="company_id" value="<?= htmlspecialchars($company_id ?? '') ?>" style="width:120px"></label>
                <label>View distributor ID <input type="number" name="distributor_id" value="<?= htmlspecialchars($distributor_id ?? '') ?>" style="width:120px"></label>
                <label>Event ID <input type="number" name="disruption_id" value="<?= htmlspecialchars($disruption_id ?? '') ?>" style="width:120px"></label>
            </div>
        </form>
    </section>

    <section id="seniorKpis" class="flex-row">
        <div class="half">
            <h3>Average Financial Health by Company (<?= htmlspecialchars($start_date) ?> — <?= htmlspecialchars($end_date) ?>)</h3>
            <canvas id="financialHealthChart" width="600" height="300"></canvas>
            <p>Sorted from highest to lowest. Below is the raw table (top 50).</p>
            <table>
                <thead><tr><th>Company</th><th>Type</th><th>Avg Health</th></tr></thead>
                <tbody>
                <?php foreach ($avg_health_by_company as $row): ?>
                    <tr>
                        <td><a href="?company_id=<?= (int)$row['CompanyID'] ?>&start_date=<?= htmlspecialchars($start_date) ?>&end_date=<?= htmlspecialchars($end_date) ?>"><?= htmlspecialchars($row['CompanyName']) ?></a></td>
                        <td><?= htmlspecialchars($row['Type']) ?></td>
                        <td><?= $row['AvgHealth'] !== null ? number_format($row['AvgHealth'],2) : 'N/A' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="half">
            <h3>Average Financial Health by Company Type</h3>
            <canvas id="healthByTypeChart" width="600" height="300"></canvas>

            <h3 style="margin-top:18px">Regional Disruption Overview</h3>
            <canvas id="regionalDisruptionChart" width="600" height="300"></canvas>
            <p>Shows total disruptions and high impact disruptions by region (or global fallback).</p>
            <table>
                <thead><tr><th>Region</th><th>Total Disruptions</th><th>High Impact</th></tr></thead>
                <tbody>
                <?php foreach ($region_disruptions as $r): ?>
                    <tr>
                        <td><?= htmlspecialchars($r['RegionName']) ?></td>
                        <td><?= (int)$r['TotalDisruptions'] ?></td>
                        <td><?= (int)$r['HighImpactCount'] ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section>
        <h3>Most Critical Companies (Criticality = #DownstreamAffected × HighImpactCount)</h3>
        <table>
            <thead><tr><th>Company</th><th>Downstream Affected</th><th>High Impact Count</th><th>Criticality</th></tr></thead>
            <tbody>
            <?php foreach ($critical_companies as $c): ?>
                <tr>
                    <td><a href="?company_id=<?= (int)$c['CompanyID'] ?>&start_date=<?= htmlspecialchars($start_date) ?>&end_date=<?= htmlspecialchars($end_date) ?>"><?= htmlspecialchars($c['CompanyName']) ?></a></td>
                    <td><?= (int)$c['DownstreamAffected'] ?></td>
                    <td><?= (int)$c['HighImpactCount'] ?></td>
                    <td><?= (int)$c['Criticality'] ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>

    <section class="flex-row">
        <div class="half">
            <h3>Disruption Frequency Over Time</h3>
            <canvas id="disruptionTimeChart" width="600" height="250"></canvas>
        </div>

        <div class="half">
            <h3>Company Financials by Region</h3>
            <p>Select a company to view detailed time series and disruptions (link from tables).</p>
            <table>
                <thead><tr><th>Region</th><th>Company</th><th>Avg Health</th></tr></thead>
                <tbody>
                <?php foreach ($company_fin_by_region as $cr): ?>
                    <tr>
                        <td><?= htmlspecialchars($cr['RegionName']) ?></td>
                        <td><a href="?company_id=<?= (int)$cr['CompanyID'] ?>&start_date=<?= htmlspecialchars($start_date) ?>&end_date=<?= htmlspecialchars($end_date) ?>"><?= htmlspecialchars($cr['CompanyName']) ?></a></td>
                        <td><?= $cr['AvgHealth'] !== null ? number_format($cr['AvgHealth'],2) : 'N/A' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section>
        <h3>Top Distributors by Shipment Volume</h3>
        <table>
            <thead><tr><th>Distributor</th><th>Total Volume</th></tr></thead>
            <tbody>
            <?php foreach ($top_distributors as $d): ?>
                <tr>
                    <td><a href="?distributor_id=<?= (int)$d['DistributorID'] ?>&start_date=<?= htmlspecialchars($start_date) ?>&end_date=<?= htmlspecialchars($end_date) ?>"><?= htmlspecialchars($d['CompanyName']) ?></a></td>
                    <td><?= (int)$d['TotalVolume'] ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <?php if ($distributor_details): ?>
            <h4>Distributor: <?= htmlspecialchars($distributor_details['CompanyName']) ?></h4>
            <p>Recent shipments:</p>
            <table>
                <thead><tr><th>Date</th><th>Volume</th><th>Delay (days)</th></tr></thead>
                <tbody>
                <?php foreach ($distributor_shipments as $s): ?>
                    <tr>
                        <td><?= htmlspecialchars($s['Date']) ?></td>
                        <td><?= (int)$s['Volume'] ?></td>
                        <td><?= $s['Delay'] !== null ? htmlspecialchars($s['Delay']) : 'N/A' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>

    <section>
        <h3>Companies Affected by Disruption Event</h3>
        <?php if (!empty($companies_for_disruption)): ?>
            <table>
                <thead><tr><th>Company</th></tr></thead>
                <tbody>
                    <?php foreach ($companies_for_disruption as $cd): ?>
                        <tr><td><a href="?company_id=<?= (int)$cd['CompanyID'] ?>&start_date=<?= htmlspecialchars($start_date) ?>&end_date=<?= htmlspecialchars($end_date) ?>"><?= htmlspecialchars($cd['CompanyName']) ?></a></td></tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p>No disruption selected or no companies found for that event (enter an Event ID above).</p>
        <?php endif; ?>
    </section>

    <section>
        <h3>All Disruptions for Selected Company</h3>
        <?php if ($company_details): ?>
            <h4><?= htmlspecialchars($company_details['CompanyName']) ?></h4>
            <p>Financial series and disruptions for the selected period:</p>
            <canvas id="companyHealthSeries" width="800" height="200"></canvas>
            <table>
                <thead><tr><th>Date</th><th>Impact</th><th>Category</th></tr></thead>
                <tbody>
                <?php foreach ($company_disruptions as $cd): ?>
                    <tr><td><?= htmlspecialchars($cd['Date']) ?></td><td><?= htmlspecialchars($cd['Impact']) ?></td><td><?= htmlspecialchars($cd['Category']) ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p>Select a company via the filters above to view details.</p>
        <?php endif; ?>
    </section>

    <section>
        <h3>Distributors by Average Delay</h3>
        <table>
            <thead><tr><th>Distributor</th><th>Avg Delay (days)</th><th>Shipments</th></tr></thead>
            <tbody>
            <?php foreach ($distributors_by_delay as $dd): ?>
                <tr>
                    <td><a href="?distributor_id=<?= (int)$dd['CompanyID'] ?>&start_date=<?= htmlspecialchars($start_date) ?>&end_date=<?= htmlspecialchars($end_date) ?>"><?= htmlspecialchars($dd['CompanyName']) ?></a></td>
                    <td><?= $dd['AvgDelay'] !== null ? number_format($dd['AvgDelay'],2) : 'N/A' ?></td>
                    <td><?= (int)$dd['Shipments'] ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>

    <section>
        <h3>Add a New Company</h3>
        <?php if ($add_company_msg): ?><p><strong><?= htmlspecialchars($add_company_msg) ?></strong></p><?php endif; ?>
        <form method="post" style="display:flex;gap:12px;align-items:center;">
            <input type="hidden" name="action" value="add_company">
            <label>Name <input name="company_name" required></label>
            <label>Type <input name="company_type"></label>
            <label>Location
                <select name="location_id">
                    <option value="">--</option>
                    <?php
                    // If Location table exists, offer options
                    if (tableExists($pdo, 'Location')) {
                        $locs = $pdo->query("SELECT LocationID, CountryName FROM Location ORDER BY CountryName")->fetchAll(PDO::FETCH_ASSOC);
                        foreach ($locs as $l) {
                            echo '<option value="'.(int)$l['LocationID'].'">'.htmlspecialchars($l['CountryName']).'</option>';
                        }
                    }
                    ?>
                </select>
            </label>
            <button type="submit">Add Company</button>
        </form>
    </section>

</main>

<script src="assets/app.js"></script>
<script>
    // Chart: financial health by company
    const labels_company = <?= json_encode(array_map(function($r){ return $r['CompanyName']; }, $avg_health_by_company)); ?>;
    const data_company = <?= json_encode(array_map(function($r){ return $r['AvgHealth'] !== null ? round((float)$r['AvgHealth'],2) : null; }, $avg_health_by_company)); ?>;
    const ctxF = document.getElementById('financialHealthChart').getContext('2d');
    new Chart(ctxF, {
        type: 'bar',
        data: {
            labels: labels_company,
            datasets: [{
                label: 'Avg Financial Health',
                data: data_company,
                backgroundColor: 'rgba(99,179,237,0.9)'
            }]
        },
        options: { responsive:true, maintainAspectRatio:false, scales: { x:{ ticks:{autoSkip:true, maxRotation:45, minRotation:0} } } }
    });

    // Chart: health by type
    const labels_type = <?= json_encode(array_map(function($r){ return $r['Type']; }, $avg_health_by_type)); ?>;
    const data_type = <?= json_encode(array_map(function($r){ return $r['AvgHealth'] !== null ? round((float)$r['AvgHealth'],2) : null; }, $avg_health_by_type)); ?>;
    const ctxT = document.getElementById('healthByTypeChart').getContext('2d');
    new Chart(ctxT, {
        type: 'bar',
        data: {
            labels: labels_type,
            datasets: [{ label: 'Avg Health by Type', data: data_type, backgroundColor: 'rgba(99,179,237,0.7)' }]
        },
        options: { responsive:true, maintainAspectRatio:false }
    });

    // Regional disruptions stacked bar
    const labels_region = <?= json_encode(array_map(function($r){ return $r['RegionName']; }, $region_disruptions)); ?>;
    const total_dis = <?= json_encode(array_map(function($r){ return (int)$r['TotalDisruptions']; }, $region_disruptions)); ?>;
    const high_dis = <?= json_encode(array_map(function($r){ return (int)$r['HighImpactCount']; }, $region_disruptions)); ?>;
    const ctxR = document.getElementById('regionalDisruptionChart').getContext('2d');
    new Chart(ctxR, {
        type: 'bar',
        data: {
            labels: labels_region,
            datasets: [
                { label: 'High Impact', data: high_dis, backgroundColor: 'rgba(246,173,85,0.95)' },
                { label: 'Other', data: total_dis.map((t,i) => Math.max(0, t - high_dis[i])), backgroundColor: 'rgba(160,160,160,0.5)' }
            ]
        },
        options: { responsive:true, maintainAspectRatio:false, scales:{ y:{ beginAtZero:true } }, plugins:{ tooltip:{ mode:'index', intersect:false } }, interaction:{ mode:'nearest' } }
    });

    // Disruption frequency line
    const disTimeLabels = <?= json_encode(array_map(function($r){ return $r['Day']; }, $disruptions_over_time)); ?>;
    const disTimeData = <?= json_encode(array_map(function($r){ return (int)$r['Disruptions']; }, $disruptions_over_time)); ?>;
    const ctxD = document.getElementById('disruptionTimeChart').getContext('2d');
    new Chart(ctxD, {
        type: 'line',
        data: { labels: disTimeLabels, datasets:[{ label:'Disruptions per day', data: disTimeData, borderColor:'#E53E3E', backgroundColor:'rgba(229,62,62,0.15)', fill:true }]},
        options: { responsive:true, maintainAspectRatio:false, scales:{ x:{ ticks:{ maxRotation:45, minRotation:0 } } } }
    });

    // Company health time series for selected company (uses RepYear + Quarter rows)
    <?php if ($company_details): ?>
    const companyHealthLabels = <?= json_encode(array_map(function($r){ return $r['Year'] . ' ' . $r['Quarter']; }, $company_time_series)); ?>;
    const companyHealthData = <?= json_encode(array_map(function($r){ return (float)$r['HealthScore']; }, $company_time_series)); ?>;
    const ctxC = document.getElementById('companyHealthSeries').getContext('2d');
    new Chart(ctxC, {
        type:'line',
        data:{ labels: companyHealthLabels, datasets:[{label: 'HealthScore', data: companyHealthData, borderColor:'#3182CE', fill:false}]},
        options:{ responsive:true, maintainAspectRatio:false }
    });
    <?php endif; ?>
</script>
</body>
</html>
