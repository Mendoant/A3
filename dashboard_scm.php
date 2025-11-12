<?php
// dashboard_erp.php - Supply Chain Manager / ERP Dashboard module
require_once 'config.php';

// 1. --- Authentication & Initialization ---

// Check if the user is logged in
if (!isset($_SESSION['Username'])) {
    header('Location: index.php');
    exit;
}

// Optionally, restrict access to Supply Chain Managers only if logic dictates
// if ($_SESSION['Role'] !== 'SupplyChainManager') {
//     header('Location: dashboard_scm.php'); // Or some other authorized page
//     exit;
// }

$pdo = getPDO();

// 2. --- User Input Handling (Filter Criteria) ---

// Set defaults: last 30 days for transactions, last 365 days for year-long KPIs
$default_start_date_kpi = date('Y-m-d', strtotime('-365 days'));
$default_start_date_tx = date('Y-m-d', strtotime('-30 days'));
$default_end_date       = date('Y-m-d');

// Retrieve and sanitize user inputs
$company_id     = trim($_GET['company_id'] ?? '');
$location_id    = trim($_GET['location_id'] ?? '');
$order_id       = trim($_GET['order_id'] ?? '');
$distributor_id = trim($_GET['distributor_id'] ?? '');

$kpi_start_date = $_GET['kpi_start_date'] ?? $default_start_date_kpi;
$kpi_end_date   = $_GET['kpi_end_date']   ?? $default_end_date;
$tx_start_date  = $_GET['tx_start_date']  ?? $default_start_date_tx;
$tx_end_date    = $_GET['tx_end_date']    ?? $default_end_date;

// 3. --- Data Fetching Functions & Logic (Simulated for Blueprint) ---

// Helper function to simulate data retrieval with PDO (Replace with real queries)
function getMockData($type, $conn, $filters = []) {
    $data = [];

    // Helper for safe query execution
    $runQuery = function($sql, $params = []) use ($conn) {
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    };

    switch ($type) {
        // 1️⃣ Company info
        case 'company_info':
            $sql = "
                SELECT c.CompanyID, c.CompanyName, c.Type, c.TierLevel,
                       l.CountryName, l.ContinentName
                FROM Company c
                JOIN Location l ON c.LocationID = l.LocationID
                WHERE (:company_id IS NULL OR c.CompanyID = :company_id)
                ORDER BY c.CompanyName;
            ";
            $params = [':company_id' => $filters['company_id'] ?? null];
            $data = $runQuery($sql, $params);
            break;

        // 2️⃣ Transactions (shipments)
        case 'transactions':
            $sql = "
                SELECT s.ShipmentID, s.PromisedDate, s.ActualDate, s.Quantity,
                       src.CompanyName AS SourceCompany,
                       dest.CompanyName AS DestinationCompany,
                       p.ProductName
                FROM Shipping s
                JOIN Company src ON s.SourceCompanyID = src.CompanyID
                JOIN Company dest ON s.DestinationCompanyID = dest.CompanyID
                JOIN Product p ON s.ProductID = p.ProductID
                WHERE (:company_id IS NULL OR s.SourceCompanyID = :company_id OR s.DestinationCompanyID = :company_id)
                  AND (:start IS NULL OR s.PromisedDate >= :start)
                  AND (:end IS NULL OR s.PromisedDate <= :end)
                ORDER BY s.PromisedDate DESC;
            ";
            $params = [
                ':company_id' => $filters['company_id'] ?? null,
                ':start' => $filters['start'] ?? null,
                ':end' => $filters['end'] ?? null
            ];
            $data = $runQuery($sql, $params);
            break;

        // 3️⃣ KPI / Financial health data
        case 'kpis':
            $sql = "
                SELECT c.CompanyName, fr.Quarter, fr.RepYear, fr.HealthScore
                FROM FinancialReport fr
                JOIN Company c ON fr.CompanyID = c.CompanyID
                WHERE (:company_id IS NULL OR fr.CompanyID = :company_id)
                  AND (
                    (:start IS NULL OR CONCAT(fr.RepYear, '-', fr.Quarter) >= :start)
                    AND (:end IS NULL OR CONCAT(fr.RepYear, '-', fr.Quarter) <= :end)
                  )
                ORDER BY fr.RepYear DESC, fr.Quarter;
            ";
            $params = [
                ':company_id' => $filters['company_id'] ?? null,
                ':start' => $filters['start'] ?? null,
                ':end' => $filters['end'] ?? null
            ];
            $data = $runQuery($sql, $params);
            break;

        // 4️⃣ Disruption metrics
        case 'disruption_metrics':
            $sql = "
                SELECT de.EventID, de.EventDate, de.EventRecoveryDate,
                       dc.CategoryName, dc.Description
                FROM DisruptionEvent de
                JOIN DisruptionCategory dc ON de.CategoryID = dc.CategoryID
                WHERE (:start IS NULL OR de.EventDate >= :start)
                  AND (:end IS NULL OR de.EventDate <= :end)
                ORDER BY de.EventDate DESC;
            ";
            $params = [
                ':start' => $filters['start'] ?? null,
                ':end' => $filters['end'] ?? null
            ];
            $data = $runQuery($sql, $params);
            break;

        default:
            $data = [];
            break;
    }

    return $data;
}



// Fetch all necessary data based on filter parameters
$company_data = getMockData('company_info', $pdo, ['company_id' => $company_id]);
$company_data = $company_data[0] ?? [];
$transactions = getMockData('transactions', $pdo, ['company_id' => $company_id, 'start' => $tx_start_date, 'end' => $tx_end_date]);
$kpi_data     = getMockData('kpis', $pdo, ['company_id' => $company_id, 'start' => $kpi_start_date, 'end' => $kpi_end_date]);
$disruptions  = getMockData('disruption_metrics', $pdo, ['start' => $kpi_start_date, 'end' => $kpi_end_date]);

$T = 0;  // Total number of recorded days (time span)
$Ndisruptions = 0;
$Nhigh_impact = 0;
$recovery_times = [];

if (!empty($disruptions) && is_array($disruptions)) {
    $Ndisruptions = count($disruptions);

    foreach ($disruptions as $event) {
        // Recovery time in days, if recovery date exists
        if (!empty($event['EventRecoveryDate'])) {
            $start = new DateTime($event['EventDate']);
            $end = new DateTime($event['EventRecoveryDate']);
            $recovery_times[] = $start->diff($end)->days;
        }

        // Optional: Count “High” impact events if present
        if (isset($event['ImpactLevel']) && $event['ImpactLevel'] === 'High') {
            $Nhigh_impact++;
        }
    }

    // Compute the time span of all events (T)
    $dates = array_column($disruptions, 'EventDate');
    if (!empty($dates)) {
        $min_date = new DateTime(min($dates));
        $max_date = new DateTime(max($dates));
        $T = max(1, $min_date->diff($max_date)->days); // Avoid divide-by-zero
    }
}

// Derived metrics
$DF = ($T > 0) ? $Ndisruptions / $T : 0;  // disruption frequency
$TD = array_sum($recovery_times);
$ART = (count($recovery_times) > 0) ? $TD / count($recovery_times) : 0;  // average recovery time
$HDR = ($Ndisruptions > 0) ? ($Nhigh_impact / $Ndisruptions) * 100 : 0;

$on_time_count = 0;
$total_shipments = 0;
$delays = [];

if (!empty($transactions) && is_array($transactions)) {
    foreach ($transactions as $ship) {
        if (!empty($ship['PromisedDate']) && !empty($ship['ActualDate'])) {
            $promised = new DateTime($ship['PromisedDate']);
            $actual   = new DateTime($ship['ActualDate']);

            $delay_days = $promised->diff($actual)->days;

            // If actual <= promised → on time
            if ($actual <= $promised) {
                $on_time_count++;
            }

            // Record delay (positive if late, negative if early)
            $delays[] = ($actual > $promised)
                ? $delay_days
                : -$delay_days;
        }
        $total_shipments++;
    }
}

// --- Summary metrics ---
$on_time_rate = ($total_shipments > 0)
    ? ($on_time_count / $total_shipments) * 100
    : 0;

$avg_delay = (!empty($delays))
    ? array_sum($delays) / count($delays)
    : 0;

// Sample std-dev calculation
$std_dev_delay = 0;
if (count($delays) > 1) {
    $mean = $avg_delay;
    $variance = array_sum(array_map(fn($d) => pow($d - $mean, 2), $delays)) / (count($delays) - 1);
    $std_dev_delay = sqrt($variance);
}

// --- Attach metrics for rendering ---
$transactions_kpi = [
    'on_time_rate'  => $on_time_rate,
    'avg_delay'     => $avg_delay,
    'std_dev_delay' => $std_dev_delay
];
?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>ERP / Supply Chain Manager Dashboard</title>
    <link rel="stylesheet" href="assets/styles.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .kpi-box { padding: 10px; border: 1px solid #000000ff; margin: 10px 0; background-color: #b89968; }
        .alert-bar { background-color: #fdd; color: #a00; padding: 10px; margin-bottom: 20px; border: 1px solid #a00; }
        .data-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        .data-table th, .data-table td { border: 1px solid #ddd; padding: 8px; text-align: left; }
    </style>
</head>
<body>
<?php include 'nav.php'; // Navigation bar include ?>
<main class="container">
    <h2>🔗 Supply Chain Manager Module (SCM)</h2>

    <?php if (!empty($disruptions['ongoing_alerts'])): ?>
    <div class="alert-bar">
        **🚨 ACTIVE DISRUPTIONS:**
        <?php foreach ($disruptions['ongoing_alerts'] as $alert): ?>
            <span><?= htmlspecialchars($alert) ?> | </span>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <section class="filters">
        <h3>🔍 Data Filters</h3>
        <form method="get" class="filter-form">
            <label>Company ID (Searchable) <input type="text" name="company_id" value="<?= htmlspecialchars($company_id) ?>"></label>
            <label>Location ID <input type="text" name="location_id" value="<?= htmlspecialchars($location_id) ?>"></label>
            <label>Order ID <input type="text" name="order_id" value="<?= htmlspecialchars($order_id) ?>"></label>
            <hr style="margin: 5px 0;">
            <label>KPI Start Date (Default 1 Year) <input type="date" name="kpi_start_date" value="<?= htmlspecialchars($kpi_start_date) ?>"></label>
            <label>KPI End Date <input type="date" name="kpi_end_date" value="<?= htmlspecialchars($kpi_end_date) ?>"></label>
            <hr style="margin: 5px 0;">
            <label>Transaction Start Date (Default 30 Days) <input type="date" name="tx_start_date" value="<?= htmlspecialchars($tx_start_date) ?>"></label>
            <label>Transaction End Date <input type="date" name="tx_end_date" value="<?= htmlspecialchars($tx_end_date) ?>"></label>
            <button type="submit">Apply Filters & Update Dashboard</button>
        </form>
    </section>

    <hr>

    <section id="companyInfo">
        <h3>🏢 Company Details: <?= htmlspecialchars($company_data['name'] ?? 'N/A') ?></h3>
        <div style="display: flex; gap: 20px;">
            <div style="flex: 1;">
                <h4>Essential Data</h4>
                <ul>
                    <li>**Address:** <?= htmlspecialchars($company_data['address'] ?? '') ?></li>
                    <li>**Type / Tier:** <?= htmlspecialchars(($company_data['type'] ?? '') . ' / ' . ($company_data['tier_level'] ?? '')) ?></li>
                    <li>**Dependencies:** <?= htmlspecialchars($company_data['dependencies'] ?? '') ?> | **Dependents:** <?= htmlspecialchars($company_data['dependents'] ?? '') ?></li>
                    <li>**Financial Status (Recent):** <?= htmlspecialchars($company_data['financial_status'] ?? '') ?></li>
                    <li>**Capacity/Routes:** <?= htmlspecialchars($company_data['capacity'] ?? 'N/A') ?></li>
                    <li>**Products:** <?= htmlspecialchars(implode(', ', $company_data['products'] ?? [])) ?></li>
                </ul>
                <button onclick="alert('TODO: Implement AJAX form for updating company info');">✏️ Update/Save Company Info</button>
            </div>
            <div style="flex: 1;">
                <h4>Key Performance Indicators (KPIs) (<?= htmlspecialchars($kpi_start_date) ?> to <?= htmlspecialchars($kpi_end_date) ?>)</h4>
                <div class="kpi-box">**On-Time Delivery Rate:** **<?= number_format($transactions_kpi['on_time_rate'] * 100, 2) ?>%**</div>
                <div class="kpi-box">**Avg Delay:** **<?= number_format($transactions_kpi['avg_delay'], 2) ?> days**</div>
                <div class="kpi-box">**Std Dev Delay:** **<?= number_format($transactions_kpi['std_dev_delay'], 2) ?> days**</div>

                <canvas id="financialHealthChart" height="150"></canvas>
                <p class="chart-caption">Financial Health Status (Past Year)</p>
            </div>
        </div>
    </section>

    <hr>

    <section id="transactions">
        <h3>📜 Transactions (<?= htmlspecialchars($tx_start_date) ?> to <?= htmlspecialchars($tx_end_date) ?>)</h3>
        <table class="data-table">
            <thead>
                <tr><th>Date</th><th>Type</th><th>Details</th><th>Amount</th><th>Edit</th></tr>
            </thead>
            <tbody>
                <?php foreach ($transactions as $t): ?>
                    <tr>
                        <td><?= htmlspecialchars($t['Date']) ?></td>
                        <td>**<?= htmlspecialchars($t['Type']) ?>**</td>
                        <td><?= htmlspecialchars($t['Details']) ?></td>
                        <td>$<?= number_format($t['Amount'], 2) ?></td>
                        <td><button onclick="alert('TODO: Edit Transaction ID');">Edit</button></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </section>

    <hr>

    <section id="disruptions">
        <h3>💥 Disruption Analysis (<?= htmlspecialchars($kpi_start_date) ?> to <?= htmlspecialchars($kpi_end_date) ?>)</h3>
        <div style="display: flex; flex-wrap: wrap; gap: 20px;">
            <div style="flex: 1; min-width: 45%; background-color: #000000ff">
                <div class="kpi-box">**Disruption Frequency (DF):** **<?= number_format($DF, 2) ?> events/month**</div>
                <div class="kpi-box">**Average Recovery Time (ART):** **<?= number_format($ART, 2) ?> days**</div>
                <div class="kpi-box">**Total Downtime (TD):** **<?= number_format($TD, 2) ?> days**</div>
                <div class="kpi-box">**High-Impact Rate (HDR):** **<?= number_format($HDR, 2) ?>%**</div>

                <canvas id="dsdChart" height="200"></canvas>
                <p class="chart-caption">Disruption Severity Distribution (DSD)</p>
            </div>
            <div style="flex: 1; min-width: 45%;background-color: #000000ff">
                <canvas id="dfCompanyChart" height="200"></canvas>
                <p class="chart-caption">Disruption Frequency by Company (Bar Chart)</p>

                <div style="background-color: #000000ff; height: 200px; padding: 10px; text-align: center;">
                    
                    <p>Regional Risk Concentration (RRC) Heatmap</p>
                </div>
            </div>
        </div>
    </section>

    <?php if (!empty($distributor_id)):
        // Placeholder Distributor Data
        $distributor_metrics = [
            'ShipmentVolume' => 25000, 'OntimeRate' => 0.98,
            'Products' => ['A', 'B', 'C'], 'DisruptionExposure' => 10 + (2 * 3)
        ];
    ?>
    <hr>
    <section id="distributorDetails">
        <h3>🚚 Distributor Details (ID: <?= htmlspecialchars($distributor_id) ?>)</h3>
        <ul>
            <li>**Shipment Volume:** <?= number_format($distributor_metrics['ShipmentVolume']) ?> units</li>
            <li>**On-time Delivery Rate:** <?= number_format($distributor_metrics['OntimeRate'] * 100, 2) ?>%</li>
            <li>**Disruption Exposure:** <?= $distributor_metrics['DisruptionExposure'] ?> (total + 2*high impact)</li>
        </ul>
        <div style="display: flex; flex-wrap: wrap; gap: 10px;">
            <div style="flex: 1; min-width: 45%;"><canvas id="dplot1" height="150"></canvas><p>Volume Trend</p></div>
            <div style="flex: 1; min-width: 45%;"><canvas id="dplot2" height="150"></canvas><p>On-Time by Carrier</p></div>
            <div style="flex: 1; min-width: 45%;"><canvas id="dplot3" height="150"></canvas><p>Product Mix (%)</p></div>
            <div style="flex: 1; min-width: 45%;"><canvas id="dplot4" height="150"></canvas><p>Avg Route Delay</p></div>
        </div>
    </section>
    <?php endif; ?>
</main>

<script src="assets/app.js"></script>
<script>
// --- Chart.js Implementation for Required Plots ---

// Financial Health Status (KPI)
const fctx = document.getElementById('financialHealthChart').getContext('2d');
new Chart(fctx, {
    type:'line',
    data: {
        labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
        datasets:[{label:'Health Score', data:[80,75,77,81,85,88,86,85,90,92,90,91], borderColor:'#3182CE', tension: 0.1, fill: false}]
    },
    options: { responsive:true, maintainAspectRatio:true, aspectRatio: 2, plugins: { legend: { display: false } } }
});

// Disruption Severity Distribution (DSD) - Stacked Bar
const dsdCtx = document.getElementById('dsdChart').getContext('2d');
new Chart(dsdCtx, {
    type:'bar',
    data: {
        labels: ['Severity'],
        datasets:[
            {label:'Low', data:[<?= $disruptions['severity_distribution']['Low'] ?>], backgroundColor:'#48BB78'},
            {label:'Medium', data:[<?= $disruptions['severity_distribution']['Medium'] ?>], backgroundColor:'#ECC94B'},
            {label:'High', data:[<?= $disruptions['severity_distribution']['High'] ?>], backgroundColor:'#E53E3E'}
        ]
    },
    options: {
        responsive:true, maintainAspectRatio:true, aspectRatio: 2, scales: { x: { stacked: true }, y: { stacked: true } }
    }
});

// Disruption Frequency by Company (DF) - Bar Chart
const dfCtx = document.getElementById('dfCompanyChart').getContext('2d');
new Chart(dfCtx, {
    type:'bar',
    data:{
        labels:['Supplier A','Supplier B','Manufacturer C','Distributor D'],
        datasets:[{label:'Disruptions Count', data:[5, 8, 3, 4], backgroundColor:'#68D391'}]
    },
    options:{ responsive:true, maintainAspectRatio:true, aspectRatio: 2}
});

// Note: ART, TD (Histograms) and RRC (Heatmap) require more complex chart types or libraries,
// so they are represented by captions/placeholders here.
</script>
</body>
</html>
