<?php
// dashboard_erp.php - Senior Manager Enterprise Resource Planning (ERP) Dashboard
require_once 'config.php';

// 1. --- Authentication & Session Check ---

// Start session if not started (handled by config.php)
// Check if the user is logged in
if (!isset($_SESSION['Username'])) {
    header('Location: index.php');
    exit;
}

// Restrict access if necessary (assuming Senior Managers use this specific dashboard)
if ($_SESSION['Role'] !== 'SeniorManager') {
    // If a SCM accidentally lands here, redirect them
    header('Location: dashboard_scm.php');
    exit;
}

$pdo = getPDO();

// 2. --- User Input Handling (Filter Criteria) ---

// Set defaults: last 365 days for most metrics
$default_start_date = date('Y-m-d', strtotime('-365 days'));
$default_end_date   = date('Y-m-d');

// Retrieve and sanitize user inputs
$start_date     = $_GET['start_date']     ?? $default_start_date;
$end_date       = $_GET['end_date']       ?? $default_end_date;
$company_id     = trim($_GET['company_id'] ?? '');
$distributor_id = trim($_GET['distributor_id'] ?? '');
$disruption_id  = trim($_GET['disruption_id'] ?? '');

// 3. --- Data Fetching and Calculation (Simulated for Blueprint) ---

// This function simulates all necessary data aggregation for the Senior Manager view.
function getSeniorManagerData($pdo, $params) {
    // In a real application, replace these mock returns with complex SQL queries
    // using the $pdo object and the $params (start_date, end_date, etc.).

    // Avg Financial Health by Company & Type (Bar Chart)
    $financial_health = [
        ['Company' => 'Alpha Logistics', 'Type' => 'Distributor', 'Health' => 88],
        ['Company' => 'Beta Manufacturing', 'Type' => 'Manufacturer', 'Health' => 75],
        ['Company' => 'Gamma Raw Materials', 'Type' => 'Supplier', 'Health' => 92],
    ];
    // Sort high to low
    usort($financial_health, fn($a, $b) => $b['Health'] <=> $a['Health']);

    // Regional Disruption Overview (Stacked Bar/Heatmap data)
    $regional_disruptions = [
        ['Region' => 'North America', 'Total' => 25, 'HighImpact' => 5],
        ['Region' => 'Europe', 'Total' => 18, 'HighImpact' => 3],
        ['Region' => 'Asia Pacific', 'Total' => 40, 'HighImpact' => 12],
    ];

    // Criticality calculation: Criticality = # Downstream affected * HighImpactCount
    $critical_companies = [
        ['Company' => 'Beta Manufacturing', 'DownstreamAffected' => 15, 'HighImpactCount' => 3, 'Criticality' => 45],
        ['Company' => 'Alpha Logistics', 'DownstreamAffected' => 8, 'HighImpactCount' => 2, 'Criticality' => 16],
        ['Company' => 'Delta Assembly', 'DownstreamAffected' => 20, 'HighImpactCount' => 1, 'Criticality' => 20],
    ];
    usort($critical_companies, fn($a, $b) => $b['Criticality'] <=> $a['Criticality']);

    // Disruption Frequency Over Time (Line Plot)
    $df_over_time = [
        'labels' => ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun'],
        'data' => [3, 5, 4, 7, 6, 8] // Disruptions per month/quarter
    ];

    // Top Distributors by Shipment Volume (Table/Link List)
    $top_distributors = [
        ['ID' => 'D001', 'Name' => 'WorldShip', 'Volume' => 1500000],
        ['ID' => 'D002', 'Name' => 'SpeedyDeliver', 'Volume' => 1200000],
    ];

    // Distributors sorted by Average Delay
    $distributors_by_delay = [
        ['Name' => 'QuickRunner', 'AvgDelay' => 0.1],
        ['Name' => 'ReliableTrans', 'AvgDelay' => 0.5],
        ['Name' => 'SlowMovers', 'AvgDelay' => 1.2],
    ];
    usort($distributors_by_delay, fn($a, $b) => $a['AvgDelay'] <=> $b['AvgDelay']);


    // Companies Affected by a Specific Event (Nontrivial Table 1)
    $affected_companies = [
        ['Company' => 'Beta Manufacturing', 'DamageEstimate' => '$500k'],
        ['Company' => 'Gamma Raw Materials', 'DamageEstimate' => '$100k'],
    ];

    // Total Inventory Value by Warehouse Region (Nontrivial Plot 2)
    $inventory_by_region = [
        'labels' => ['Midwest', 'Northeast', 'West Coast'],
        'data' => [12.5, 8.9, 15.1] // Value in millions
    ];

    return [
        'financial_health' => $financial_health,
        'regional_disruptions' => $regional_disruptions,
        'critical_companies' => $critical_companies,
        'df_over_time' => $df_over_time,
        'top_distributors' => $top_distributors,
        'distributors_by_delay' => $distributors_by_delay,
        'affected_companies' => $affected_companies,
        'inventory_by_region' => $inventory_by_region,
    ];
}

$dashboard_data = getSeniorManagerData($pdo, [
    'start_date' => $start_date,
    'end_date' => $end_date,
    'company_id' => $company_id,
    'distributor_id' => $distributor_id,
]);

// Helper to render table rows
function renderTableRows($data) {
    $html = '';
    foreach ($data as $row) {
        $html .= '<tr>';
        foreach ($row as $value) {
            $html .= '<td>' . htmlspecialchars($value) . '</td>';
        }
        $html .= '</tr>';
    }
    return $html;
}

// Function to handle adding a new company (requires POST handling, simulation here)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_company') {
    // 1. Sanitize and validate input
    $new_name = trim($_POST['new_company_name'] ?? '');
    // 2. Prepare and execute INSERT query
    // $sql = "INSERT INTO Company (CompanyName, ...) VALUES (:name, ...)";
    // $stmt = $pdo->prepare($sql);
    // $stmt->execute([':name' => $new_name]);
    // 3. Provide feedback (redirect)
    $_SESSION['message'] = "Company '{$new_name}' added successfully (simulated).";
    header('Location: dashboard_erp.php');
    exit;
}

// Display messages after redirects
$message = $_SESSION['message'] ?? '';
unset($_SESSION['message']);

?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>ERP / Senior Manager Dashboard</title>
    <link rel="stylesheet" href="assets/styles.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        /* Styles adapted for clean layout */
        .grid-3 { display: flex; flex-wrap: wrap; gap: 20px; }
        .grid-3 > div { flex: 1 1 calc(33.333% - 20px); min-width: 300px; padding: 15px; border: 1px solid #eee; }
        .grid-2 { display: flex; flex-wrap: wrap; gap: 20px; }
        .grid-2 > div { flex: 1 1 calc(50% - 10px); min-width: 40%; padding: 15px; border: 1px solid #eee; }
        .filter-form label { display: block; margin-bottom: 5px; }
        .data-table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        .data-table th, .data-table td { border: 1px solid #ddd; padding: 8px; text-align: left; }
    </style>
</head>
<body>
<?php include 'nav.php'; // Navigation bar include ?>
<main class="container">
    <h2>👑 Senior Manager Module</h2>

    <?php if ($message): ?>
        <div style="background-color: #d4edda; color: #155724; padding: 10px; margin-bottom: 20px;">
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <!-- 4. --- Global Filter Controls --- -->
    <section class="filters">
        <h3>🔍 Time Period & High-Level Filters</h3>
        <form method="get" class="filter-form">
            <label>Start Date <input type="date" name="start_date" value="<?= htmlspecialchars($start_date) ?>"></label>
            <label>End Date <input type="date" name="end_date" value="<?= htmlspecialchars($end_date) ?>"></label>
            <button type="submit">Apply Date Range</button>
        </form>
    </section>

    <hr>

    <!-- 5. --- Core Strategic Metrics (Plots) --- -->
    <section id="strategicMetrics">
        <h3>📊 Strategic Performance Overview (<?= htmlspecialchars($start_date) ?> to <?= htmlspecialchars($end_date) ?>)</h3>
        <div class="grid-2">
            <div>
                <h4>Average Financial Health by Company & Type (Sorted)</h4>
                <canvas id="financialHealthChart" height="250"></canvas>
            </div>
            <div>
                <h4>Regional Disruption Overview: Total vs. High Impact</h4>
                <canvas id="regionalDisruptionChart" height="250"></canvas>
            </div>
        </div>
        <div class="grid-2">
            <div>
                <h4>Disruption Frequency Over Time (DF)</h4>
                <canvas id="dfOverTimeChart" height="250"></canvas>
            </div>
            <div>
                <h4>Non-Trivial Plot: Inventory Value by Region (in Millions)</h4>
                <canvas id="inventoryRegionChart" height="250"></canvas>
            </div>
        </div>
    </section>

    <hr>

    <!-- 6. --- Criticality and Drill-Down Tables --- -->
    <section id="criticality">
        <h3>⚠️ Critical Risk and Management Drill-Down</h3>
        <div class="grid-3">
            <div>
                <h4>Most Critical Companies (Criticality = Affected * HighImpact)</h4>
                <table class="data-table">
                    <thead><tr><th>Company</th><th>Criticality Score</th></tr></thead>
                    <tbody>
                        <?php foreach ($dashboard_data['critical_companies'] as $c): ?>
                            <tr>
                                <td><?= htmlspecialchars($c['Company']) ?></td>
                                <td>**<?= htmlspecialchars($c['Criticality']) ?>**</td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div>
                <h4>Distributors Sorted by Average Delay (Lowest Delay First)</h4>
                <table class="data-table">
                    <thead><tr><th>Distributor</th><th>Avg Delay (Days)</th></tr></thead>
                    <tbody>
                        <?= renderTableRows($dashboard_data['distributors_by_delay']) ?>
                    </tbody>
                </table>
            </div>

            <div>
                <h4>Top Distributors by Shipment Volume</h4>
                <table class="data-table">
                    <thead><tr><th>Distributor</th><th>Volume (Units)</th></tr></thead>
                    <tbody>
                        <?php foreach ($dashboard_data['top_distributors'] as $d): ?>
                            <tr>
                                <td>
                                    <!-- Ability to bring up any distributor's data -->
                                    <a href="dashboard_scm.php?distributor_id=<?= htmlspecialchars($d['ID']) ?>">
                                        <?= htmlspecialchars($d['Name']) ?>
                                    </a>
                                </td>
                                <td><?= number_format($d['Volume']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <hr>

    <!-- 7. --- Company & Disruption Specific Search / Data Entry --- -->
    <section id="detailSearch">
        <h3>🔎 Detailed Lookups & Entry</h3>
        <div class="grid-2">
            <div>
                <h4>Company Financials by Region / Company Data Lookup</h4>
                <form method="get" class="filter-form" action="dashboard_scm.php">
                    <label>Company ID to View: <input type="text" name="company_id" value="<?= htmlspecialchars($company_id) ?>"></label>
                    <button type="submit">View Company Details</button>
                    <p><small>(Links to Supply Chain Manager dashboard for drill-down)</small></p>
                </form>

                <?php if (!empty($company_id)): ?>
                <!-- All disruptions for a specific company (Non-trivial table 2) -->
                <h5>All Disruptions for Company ID: <?= htmlspecialchars($company_id) ?></h5>
                <table class="data-table">
                    <thead><tr><th>Event Date</th><th>Type</th><th>Severity</th></tr></thead>
                    <tbody>
                        <tr><td>2025-05-10</td><td>Port Strike</td><td>High</td></tr>
                        <tr><td>2025-08-01</td><td>Weather Delay</td><td>Medium</td></tr>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>

            <div>
                <h4>Companies Affected by Specific Disruption Event</h4>
                <form method="get" class="filter-form" action="dashboard_erp.php">
                    <label>Disruption Event ID: <input type="text" name="disruption_id" value="<?= htmlspecialchars($disruption_id) ?>"></label>
                    <button type="submit">Check Affected Companies</button>
                </form>

                <?php if (!empty($disruption_id)): ?>
                <h5>Affected by Event ID: <?= htmlspecialchars($disruption_id) ?></h5>
                <table class="data-table">
                    <thead><tr><th>Company</th><th>Damage Estimate</th></tr></thead>
                    <tbody>
                        <?= renderTableRows($dashboard_data['affected_companies']) ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>

        <hr>

        <h4>➕ Add New Company</h4>
        <form method="post" action="dashboard_erp.php" class="filter-form">
            <input type="hidden" name="action" value="add_company">
            <label>Company Name: <input type="text" name="new_company_name" required></label>
            <button type="submit">Add New Company</button>
            <p><small>(This action is simulated for the blueprint)</small></p>
        </form>
    </section>

</main>

<script src="assets/app.js"></script>
<script>
// --- Chart.js Implementations ---
const data = <?= json_encode($dashboard_data) ?>;

// 1. Average Financial Health by Company & Type (Bar Chart)
const fhCtx = document.getElementById('financialHealthChart').getContext('2d');
new Chart(fhCtx, {
    type:'bar',
    data: {
        labels: data.financial_health.map(c => `${c.Company} (${c.Type})`),
        datasets:[{
            label:'Avg Health Score (0-100)',
            data: data.financial_health.map(c => c.Health),
            backgroundColor: data.financial_health.map(c => c.Type === 'Distributor' ? '#4C51BF' : (c.Type === 'Manufacturer' ? '#D53F8C' : '#38A169'))
        }]
    },
    options: { responsive:true, maintainAspectRatio:true, aspectRatio: 2, indexAxis: 'y' } // Horizontal bars
});

// 2. Regional Disruption Overview (Stacked Bar Chart)
const rdCtx = document.getElementById('regionalDisruptionChart').getContext('2d');
new Chart(rdCtx, {
    type:'bar',
    data: {
        labels: data.regional_disruptions.map(r => r.Region),
        datasets:[
            {
                label:'Total Disruptions',
                data: data.regional_disruptions.map(r => r.Total - r.HighImpact), // Non-High Impact
                backgroundColor:'#F6AD55'
            },
            {
                label:'High Impact Disruptions',
                data: data.regional_disruptions.map(r => r.HighImpact),
                backgroundColor:'#E53E3E'
            }
        ]
    },
    options: { responsive:true, maintainAspectRatio:true, aspectRatio: 2, scales: { x: { stacked: true }, y: { stacked: true } } }
});

// 3. Disruption Frequency Over Time (Line Plot)
const dfOverTimeCtx = document.getElementById('dfOverTimeChart').getContext('2d');
new Chart(dfOverTimeCtx, {
    type:'line',
    data: {
        labels: data.df_over_time.labels,
        datasets:[{
            label:'Disruption Count',
            data: data.df_over_time.data,
            borderColor:'#4299E1',
            tension: 0.1,
            fill: false
        }]
    },
    options: { responsive:true, maintainAspectRatio:true, aspectRatio: 2}
});

// 4. Non-Trivial Plot 2: Inventory Value by Region (Bar Chart)
const invCtx = document.getElementById('inventoryRegionChart').getContext('2d');
new Chart(invCtx, {
    type:'bar',
    data: {
        labels: data.inventory_by_region.labels,
        datasets:[{
            label:'Inventory Value (M USD)',
            data: data.inventory_by_region.data,
            backgroundColor:'#9F7AEA'
        }]
    },
    options: { responsive:true, maintainAspectRatio:true, aspectRatio: 2}
});
</script>
</body>
</html>
