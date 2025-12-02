<?php
// test_connection.php - Database Connection Test
// DELETE THIS FILE AFTER TESTING!

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config.php';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Connection Test</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 1200px;
            margin: 40px auto;
            padding: 20px;
            background: #1a1a1a;
            color: white;
        }
        .success {
            background: rgba(40, 167, 69, 0.2);
            border: 2px solid #28a745;
            padding: 20px;
            border-radius: 8px;
            margin: 20px 0;
        }
        .error {
            background: rgba(220, 53, 69, 0.2);
            border: 2px solid #dc3545;
            padding: 20px;
            border-radius: 8px;
            margin: 20px 0;
        }
        .info {
            background: rgba(207, 185, 145, 0.2);
            border: 2px solid #CFB991;
            padding: 20px;
            border-radius: 8px;
            margin: 20px 0;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
            background: rgba(0,0,0,0.4);
        }
        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid rgba(207, 185, 145, 0.3);
        }
        th {
            background: rgba(207, 185, 145, 0.3);
            color: #CFB991;
        }
        h1 {
            color: #CFB991;
        }
        h2 {
            color: #CFB991;
            margin-top: 40px;
        }
        .checkmark {
            color: #28a745;
            font-size: 20px;
        }
        .warning {
            color: #ffc107;
            font-size: 20px;
        }
        .delete-warning {
            background: rgba(220, 53, 69, 0.3);
            border: 2px solid #dc3545;
            padding: 30px;
            border-radius: 8px;
            margin: 40px 0;
            text-align: center;
            font-size: 20px;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <h1>🔍 Database Connection Test</h1>
    
    <?php
    try {
        // Test 1: Can we connect?
        $pdo = getPDO();
        echo '<div class="success">';
        echo '<span class="checkmark">✓</span> <strong>SUCCESS:</strong> Connected to database successfully!<br>';
        echo '<strong>Host:</strong> ' . DB_HOST . '<br>';
        echo '<strong>Database:</strong> ' . DB_NAME . '<br>';
        echo '<strong>User:</strong> ' . DB_USER;
        echo '</div>';
        
        // Test 2: Check if tables exist
        echo '<h2>📊 Database Tables Check</h2>';
        
        $required_tables = array(
            'User', 'Location', 'Company', 'Manufacturer', 'Distributor', 'Retailer',
            'Product', 'InventoryTransaction', 'Shipping', 'Receiving', 
            'FinancialReport', 'DisruptionCategory', 'DisruptionEvent', 
            'DependsOn', 'SuppliesProduct', 'ImpactsCompany', 'OperatesLogistics'
        );
        
        echo '<table>';
        echo '<tr><th>Table Name</th><th>Status</th><th>Row Count</th></tr>';
        
        $all_tables_exist = true;
        foreach ($required_tables as $table) {
            try {
                $stmt = $pdo->query("SELECT COUNT(*) as count FROM `$table`");
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                $count = $result['count'];
                echo "<tr>";
                echo "<td><strong>$table</strong></td>";
                echo "<td><span class='checkmark'>✓</span> Exists</td>";
                echo "<td>$count rows</td>";
                echo "</tr>";
            } catch (PDOException $e) {
                echo "<tr>";
                echo "<td><strong>$table</strong></td>";
                echo "<td style='color:#dc3545;'>✗ Missing or Error</td>";
                echo "<td>-</td>";
                echo "</tr>";
                $all_tables_exist = false;
            }
        }
        echo '</table>';
        
        if (!$all_tables_exist) {
            echo '<div class="error">';
            echo '<strong>⚠️ WARNING:</strong> Some tables are missing. Make sure you\'ve imported create_db.sql';
            echo '</div>';
        }
        
        // Test 3: Check for sample data
        echo '<h2>📈 Sample Data Check</h2>';
        
        $data_checks = array(
            array('table' => 'Company', 'min' => 1, 'description' => 'Companies'),
            array('table' => 'Location', 'min' => 1, 'description' => 'Locations'),
            array('table' => 'DisruptionEvent', 'min' => 1, 'description' => 'Disruption Events'),
            array('table' => 'Product', 'min' => 1, 'description' => 'Products'),
            array('table' => 'Shipping', 'min' => 1, 'description' => 'Shipments')
        );
        
        echo '<div class="info">';
        $has_data = true;
        foreach ($data_checks as $check) {
            try {
                $stmt = $pdo->query("SELECT COUNT(*) as count FROM `{$check['table']}`");
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                $count = $result['count'];
                
                if ($count >= $check['min']) {
                    echo "<span class='checkmark'>✓</span> {$check['description']}: <strong>$count</strong><br>";
                } else {
                    echo "<span class='warning'>⚠</span> {$check['description']}: <strong>$count</strong> (Expected at least {$check['min']})<br>";
                    $has_data = false;
                }
            } catch (PDOException $e) {
                echo "<span style='color:#dc3545;'>✗</span> {$check['description']}: Error checking<br>";
                $has_data = false;
            }
        }
        echo '</div>';
        
        if (!$has_data) {
            echo '<div class="error">';
            echo '<strong>⚠️ WARNING:</strong> Your database appears to be empty or missing data. ';
            echo 'Make sure you\'ve populated it with sample data for testing.';
            echo '</div>';
        }
        
        // Test 4: Check User authentication
        echo '<h2>👤 User Authentication Check</h2>';
        
        try {
            $stmt = $pdo->query("SELECT Username, Role FROM `User`");
            $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (count($users) > 0) {
                echo '<div class="success">';
                echo '<span class="checkmark">✓</span> Found ' . count($users) . ' user(s) in database:<br><br>';
                echo '<table>';
                echo '<tr><th>Username</th><th>Role</th></tr>';
                foreach ($users as $user) {
                    echo '<tr>';
                    echo '<td><strong>' . htmlspecialchars($user['Username']) . '</strong></td>';
                    echo '<td>' . htmlspecialchars($user['Role']) . '</td>';
                    echo '</tr>';
                }
                echo '</table>';
                echo '</div>';
            } else {
                echo '<div class="error">';
                echo '<strong>⚠️ WARNING:</strong> No users found in database. ';
                echo 'Run create_test_users.php to create test accounts.';
                echo '</div>';
            }
        } catch (PDOException $e) {
            echo '<div class="error">';
            echo '<strong>Error:</strong> Cannot check users - ' . $e->getMessage();
            echo '</div>';
        }
        
        // Test 5: Check Date Ranges
        echo '<h2>📅 Data Date Ranges</h2>';
        
        echo '<div class="info">';
        try {
            $stmt = $pdo->query("SELECT MIN(EventDate) as min_date, MAX(EventDate) as max_date FROM DisruptionEvent");
            $dates = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($dates['min_date']) {
                echo "<strong>Disruption Events:</strong> {$dates['min_date']} to {$dates['max_date']}<br>";
            } else {
                echo "<span class='warning'>⚠</span> No disruption events found<br>";
            }
        } catch (PDOException $e) {
            echo "Cannot check disruption dates<br>";
        }
        
        try {
            $stmt = $pdo->query("SELECT MIN(RepYear) as min_year, MAX(RepYear) as max_year FROM FinancialReport");
            $years = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($years['min_year']) {
                echo "<strong>Financial Reports:</strong> {$years['min_year']} to {$years['max_year']}<br>";
            } else {
                echo "<span class='warning'>⚠</span> No financial reports found<br>";
            }
        } catch (PDOException $e) {
            echo "Cannot check financial report years<br>";
        }
        
        try {
            $stmt = $pdo->query("SELECT MIN(PromisedDate) as min_date, MAX(PromisedDate) as max_date FROM Shipping");
            $ship_dates = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($ship_dates['min_date']) {
                echo "<strong>Shipping Records:</strong> {$ship_dates['min_date']} to {$ship_dates['max_date']}<br>";
            } else {
                echo "<span class='warning'>⚠</span> No shipping records found<br>";
            }
        } catch (PDOException $e) {
            echo "Cannot check shipping dates<br>";
        }
        echo '</div>';
        
        // Test 6: Sample query test
        echo '<h2>🔍 Sample Query Test</h2>';
        
        try {
            $sql = "
                SELECT c.CompanyName, c.Type, l.ContinentName
                FROM Company c
                LEFT JOIN Location l ON c.LocationID = l.LocationID
                LIMIT 5
            ";
            $stmt = $pdo->query($sql);
            $companies = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (count($companies) > 0) {
                echo '<div class="success">';
                echo '<span class="checkmark">✓</span> Sample JOIN query successful:<br><br>';
                echo '<table>';
                echo '<tr><th>Company Name</th><th>Type</th><th>Region</th></tr>';
                foreach ($companies as $company) {
                    echo '<tr>';
                    echo '<td>' . htmlspecialchars($company['CompanyName']) . '</td>';
                    echo '<td>' . htmlspecialchars($company['Type']) . '</td>';
                    echo '<td>' . htmlspecialchars($company['ContinentName']) . '</td>';
                    echo '</tr>';
                }
                echo '</table>';
                echo '</div>';
            }
        } catch (PDOException $e) {
            echo '<div class="error">';
            echo '<strong>Error:</strong> Sample query failed - ' . $e->getMessage();
            echo '</div>';
        }
        
        // Final summary
        echo '<h2>✅ Connection Summary</h2>';
        echo '<div class="success">';
        echo '<h3>Your database connection is working!</h3>';
        echo '<p>Based on the tests above, here\'s what you should do:</p>';
        echo '<ol>';
        echo '<li>✓ Database connection is established</li>';
        
        if ($all_tables_exist) {
            echo '<li>✓ All required tables exist</li>';
        } else {
            echo '<li><strong style="color:#ffc107;">⚠</strong> Import create_db.sql to create missing tables</li>';
        }
        
        if ($has_data) {
            echo '<li>✓ Database has sample data</li>';
        } else {
            echo '<li><strong style="color:#ffc107;">⚠</strong> Populate your database with sample data</li>';
        }
        
        if (count($users) > 0) {
            echo '<li>✓ User accounts exist for login</li>';
        } else {
            echo '<li><strong style="color:#ffc107;">⚠</strong> Run create_test_users.php to create login accounts</li>';
        }
        
        echo '</ol>';
        echo '<p><strong>Next Step:</strong> Try logging in at <a href="index.php" style="color:#CFB991;">index.php</a></p>';
        echo '</div>';
        
    } catch (PDOException $e) {
        echo '<div class="error">';
        echo '<h2>❌ Connection Failed</h2>';
        echo '<strong>Error:</strong> ' . $e->getMessage() . '<br><br>';
        echo '<strong>Possible causes:</strong><br>';
        echo '<ul>';
        echo '<li>Database credentials in config.php are incorrect</li>';
        echo '<li>Database server is not running</li>';
        echo '<li>Database does not exist</li>';
        echo '<li>User does not have permission to access the database</li>';
        echo '</ul>';
        echo '<br><strong>Check your config.php file:</strong><br>';
        echo '<code>DB_HOST: ' . DB_HOST . '</code><br>';
        echo '<code>DB_NAME: ' . DB_NAME . '</code><br>';
        echo '<code>DB_USER: ' . DB_USER . '</code><br>';
        echo '</div>';
    }
    ?>
    
    <div class="delete-warning">
        ⚠️ IMPORTANT: DELETE THIS FILE (test_connection.php) AFTER TESTING! ⚠️
        <br><br>
        This file exposes database information and should not be left on a production server.
    </div>
    
</body>
</html>