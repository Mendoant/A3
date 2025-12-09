<?php
// erp/add_company.php - Add New Company
// simple form to insert data into the supply chain

require_once '../config.php';
requireLogin();

// security check
if (!hasRole('SeniorManager')) {
    header('Location: ../scm/dashboard.php');
    exit;
}

$pdo = getPDO();

// initialize vars to avoid notices
$error = ''; 
$success = '';
$companyName = ''; 
$selectedType = ''; 
$selectedTier = ''; 
$selectedLocation = '';
$newCountry = ''; 
$newCity = ''; 
$newContinent = '';

// --- HANDLE FORM SUBMISSION ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // cleaning inputs
    $companyName = trim($_POST['company_name']);
    $selectedType = isset($_POST['type']) ? $_POST['type'] : '';
    $selectedTier = isset($_POST['tier']) ? $_POST['tier'] : '';
    $locationOption = isset($_POST['location_option']) ? $_POST['location_option'] : 'existing';
    
    // validation
    if (empty($companyName)) { $error = 'Company name is required.'; }
    elseif (empty($selectedType)) { $error = 'Company type is required.'; }
    elseif (empty($selectedTier)) { $error = 'Tier level is required.'; }
    else {
        // check for duplicates
        try {
            $checkStmt = $pdo->prepare("SELECT CompanyID FROM Company WHERE CompanyName = ?");
            $checkStmt->execute(array($companyName));
            
            if ($checkStmt->fetch()) { 
                $error = 'A company with this name already exists.'; 
            } else {
                // handle location logic
                $locationID = null;
                
                if ($locationOption === 'existing') {
                    $selectedLocation = isset($_POST['existing_location']) ? $_POST['existing_location'] : '';
                    if (empty($selectedLocation)) { 
                        $error = 'Please select an existing location.'; 
                    } else { 
                        $locationID = intval($selectedLocation); 
                    }
                } else {
                    // creating a new location
                    $newCity = trim($_POST['new_city']);
                    $newCountry = trim($_POST['new_country']);
                    $newContinent = trim($_POST['new_continent']);
                    
                    if (empty($newCity) || empty($newCountry) || empty($newContinent)) { 
                        $error = 'All location fields are required for new location.'; 
                    } else {
                        // check if location already exists to prevent dupes
                        $locCheck = $pdo->prepare("SELECT LocationID FROM Location WHERE City = ? AND CountryName = ?");
                        $locCheck->execute(array($newCity, $newCountry));
                        $existingLoc = $locCheck->fetch();
                        
                        if ($existingLoc) {
                            $locationID = intval($existingLoc['LocationID']);
                        } else {
                            $locStmt = $pdo->prepare("INSERT INTO Location (City, CountryName, ContinentName) VALUES (?, ?, ?)");
                            $locStmt->execute(array($newCity, $newCountry, $newContinent));
                            $locationID = intval($pdo->lastInsertId());
                        }
                    }
                }
                
                // insert the company if we have a valid location
                if ($locationID && empty($error)) {
                    $pdo->beginTransaction();
                    
                    $compStmt = $pdo->prepare("INSERT INTO Company (CompanyName, LocationID, TierLevel, Type) VALUES (?, ?, ?, ?)");
                    $compStmt->execute(array($companyName, $locationID, $selectedTier, $selectedType));
                    $newCompanyID = intval($pdo->lastInsertId());
                    
                    // add specific table entry based on type
                    if ($selectedType === 'Manufacturer') {
                        $typeStmt = $pdo->prepare("INSERT INTO Manufacturer (CompanyID, FactoryCapacity) VALUES (?, 0)");
                        $typeStmt->execute(array($newCompanyID));
                    } elseif ($selectedType === 'Distributor') {
                        $typeStmt = $pdo->prepare("INSERT INTO Distributor (CompanyID) VALUES (?)");
                        $typeStmt->execute(array($newCompanyID));
                    } elseif ($selectedType === 'Retailer') {
                        $typeStmt = $pdo->prepare("INSERT INTO Retailer (CompanyID) VALUES (?)");
                        $typeStmt->execute(array($newCompanyID));
                    }
                    
                    $pdo->commit();
                    $success = 'Company "' . htmlspecialchars($companyName) . '" added successfully!';
                    
                    // clear form
                    $companyName = ''; $selectedType = ''; $selectedTier = ''; $selectedLocation = '';
                    $newCity = ''; $newCountry = ''; $newContinent = '';
                }
            }
        } catch (Exception $e) {
            // roll back if anything exploded
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = 'Database Error: ' . $e->getMessage();
        }
    }
}

// fetch locations for the dropdown
$locStmt = $pdo->query("SELECT LocationID, City, CountryName, ContinentName FROM Location ORDER BY CountryName, City");
$locations = $locStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Add Company - ERP System</title>
    <script src="../assets/forward_protection.js"></script>
    <link rel="stylesheet" href="../assets/styles.css">
    <style>
        /* 2-column grid for the form inputs */
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        
        /* on small screens, stack them */
        @media (max-width: 768px) {
            .form-grid { grid-template-columns: 1fr; }
        }
        
        /* make the radio buttons look a bit better */
        .location-toggle {
            background: rgba(0, 0, 0, 0.4);
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 15px;
            border: 1px solid rgba(207, 185, 145, 0.2);
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
        <a href="critical_companies.php">Critical Companies</a>
        <a href="regional_disruptions.php">Regional Disruptions</a>
        <a href="timeline.php">Disruption Timeline</a>
        <a href="disruptions.php">Disruption Analysis</a>
        <a href="distributors.php">Distributors</a>
        <a href="add_company.php" class="active">Add Company</a>
    </nav>

    <div class="container">
        <h2>Add New Company</h2>

        <div class="form-container">
            <?php if ($error): ?>
                <div class="alert alert-error">
                    <strong>Error:</strong> <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success">
                    <strong>Success!</strong> <?= $success ?>
                    <div class="mt-sm">
                        <a href="companies.php" class="text-gold" style="text-decoration: underline;">View all companies</a> or add another below.
                    </div>
                </div>
            <?php endif; ?>

            <form method="POST" action="add_company.php">
                <div class="form-grid">
                    <div class="form-group">
                        <label for="company_name">Company Name <span class="required">*</span></label>
                        <input type="text" id="company_name" name="company_name" value="<?= htmlspecialchars($companyName) ?>" required>
                        <div class="form-hint">Must be unique</div>
                    </div>

                    <div class="form-group">
                        <label for="type">Company Type <span class="required">*</span></label>
                        <select id="type" name="type" required>
                            <option value="">-- Select Type --</option>
                            <option value="Manufacturer" <?= $selectedType === 'Manufacturer' ? 'selected' : '' ?>>Manufacturer</option>
                            <option value="Distributor" <?= $selectedType === 'Distributor' ? 'selected' : '' ?>>Distributor</option>
                            <option value="Retailer" <?= $selectedType === 'Retailer' ? 'selected' : '' ?>>Retailer</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="tier">Tier Level <span class="required">*</span></label>
                        <select id="tier" name="tier" required>
                            <option value="">-- Select Tier --</option>
                            <option value="1" <?= $selectedTier === '1' ? 'selected' : '' ?>>Tier 1</option>
                            <option value="2" <?= $selectedTier === '2' ? 'selected' : '' ?>>Tier 2</option>
                            <option value="3" <?= $selectedTier === '3' ? 'selected' : '' ?>>Tier 3</option>
                        </select>
                        <div class="form-hint">Tier 1 = Top level</div>
                    </div>
                    
                    <div></div>
                </div>

                <div class="accent-bar"></div>

                <div class="form-group">
                    <label>Location Settings <span class="required">*</span></label>
                    
                    <div class="location-toggle">
                        <div class="radio-group">
                            <div class="radio-option">
                                <input type="radio" id="location_existing" name="location_option" value="existing" checked>
                                <label for="location_existing">Use Existing Location</label>
                            </div>
                            <div class="radio-option">
                                <input type="radio" id="location_new" name="location_option" value="new">
                                <label for="location_new">Add New Location</label>
                            </div>
                        </div>
                    </div>

                    <div id="existing_location_section" class="location-section">
                        <label for="existing_location">Select Location</label>
                        <select id="existing_location" name="existing_location">
                            <option value="">-- Select Location --</option>
                            <?php foreach ($locations as $loc): ?>
                                <option value="<?= $loc['LocationID'] ?>" <?= $selectedLocation == $loc['LocationID'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($loc['City']) ?>, <?= htmlspecialchars($loc['CountryName']) ?> (<?= htmlspecialchars($loc['ContinentName']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div id="new_location_section" class="location-section hidden">
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="new_city">City</label>
                                <input type="text" id="new_city" name="new_city" value="<?= htmlspecialchars($newCity) ?>">
                            </div>
                            <div class="form-group">
                                <label for="new_country">Country</label>
                                <input type="text" id="new_country" name="new_country" value="<?= htmlspecialchars($newCountry) ?>">
                            </div>
                            <div class="form-group">
                                <label for="new_continent">Continent</label>
                                <select id="new_continent" name="new_continent">
                                    <option value="">-- Select Continent --</option>
                                    <option value="Africa" <?= $newContinent === 'Africa' ? 'selected' : '' ?>>Africa</option>
                                    <option value="Asia" <?= $newContinent === 'Asia' ? 'selected' : '' ?>>Asia</option>
                                    <option value="Europe" <?= $newContinent === 'Europe' ? 'selected' : '' ?>>Europe</option>
                                    <option value="North America" <?= $newContinent === 'North America' ? 'selected' : '' ?>>North America</option>
                                    <option value="South America" <?= $newContinent === 'South America' ? 'selected' : '' ?>>South America</option>
                                    <option value="Oceania" <?= $newContinent === 'Oceania' ? 'selected' : '' ?>>Oceania</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mt-md flex gap-md">
                    <button type="submit" class="btn-submit">Add Company</button>
                    <a href="companies.php" class="btn-cancel">Cancel</a>
                </div>
            </form>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        var existingRadio = document.getElementById('location_existing');
        var newRadio = document.getElementById('location_new');
        var existingSection = document.getElementById('existing_location_section');
        var newSection = document.getElementById('new_location_section');

        function toggleLocationSections() {
            if (existingRadio.checked) {
                // Show existing, hide new
                existingSection.classList.remove('hidden');
                newSection.classList.add('hidden');
                
                // Toggle requirements
                document.getElementById('new_city').removeAttribute('required');
                document.getElementById('new_country').removeAttribute('required');
                document.getElementById('new_continent').removeAttribute('required');
                document.getElementById('existing_location').setAttribute('required', 'required');
            } else {
                // Show new, hide existing
                existingSection.classList.add('hidden');
                newSection.classList.remove('hidden');
                
                // Toggle requirements
                document.getElementById('new_city').setAttribute('required', 'required');
                document.getElementById('new_country').setAttribute('required', 'required');
                document.getElementById('new_continent').setAttribute('required', 'required');
                document.getElementById('existing_location').removeAttribute('required');
            }
        }

        if(existingRadio && newRadio) {
            existingRadio.addEventListener('change', toggleLocationSections);
            newRadio.addEventListener('change', toggleLocationSections);
            // Run once on load
            toggleLocationSections();
        }
    });
    </script>
</body>
</html>