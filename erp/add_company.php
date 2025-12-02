<?php
// add_company.php - Add new company to database
require_once '../config.php';
requireLogin();

// kick out supply chain managers
if (!hasRole('SeniorManager')) {
    header('Location: ../scm/dashboard.php');
    exit;
}

$pdo = getPDO();

// initialize variables for form
$error = '';
$success = '';
$companyName = '';
$selectedType = '';
$selectedTier = '';
$selectedLocation = '';
$newCountry = '';
$newCity = '';
$newContinent = '';

// handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $companyName = trim($_POST['company_name']);
    $selectedType = $_POST['type'];
    $selectedTier = $_POST['tier'];
    $locationOption = $_POST['location_option'];
    
    // validate inputs
    if (empty($companyName)) {
        $error = 'Company name is required.';
    } elseif (empty($selectedType)) {
        $error = 'Company type is required.';
    } elseif (empty($selectedTier)) {
        $error = 'Tier level is required.';
    } else {
        // check if company name already exists
        $checkStmt = $pdo->prepare("SELECT CompanyID FROM Company WHERE CompanyName = ?");
        $checkStmt->execute(array($companyName));
        if ($checkStmt->fetch()) {
            $error = 'A company with this name already exists.';
        } else {
            // determine locationID
            $locationID = null;
            
            if ($locationOption === 'existing') {
                $selectedLocation = $_POST['existing_location'];
                if (empty($selectedLocation)) {
                    $error = 'Please select an existing location.';
                } else {
                    $locationID = intval($selectedLocation);
                }
            } else {
                // add new location
                $newCity = trim($_POST['new_city']);
                $newCountry = trim($_POST['new_country']);
                $newContinent = trim($_POST['new_continent']);
                
                if (empty($newCity) || empty($newCountry) || empty($newContinent)) {
                    $error = 'All location fields are required for new location.';
                } else {
                    // insert new location
                    $locStmt = $pdo->prepare("INSERT INTO Location (City, CountryName, ContinentName) VALUES (?, ?, ?)");
                    $locStmt->execute(array($newCity, $newCountry, $newContinent));
                    $locationID = intval($pdo->lastInsertId());
                }
            }
            
            // if we have a location, proceed with company insert
            if ($locationID && empty($error)) {
                try {
                    $pdo->beginTransaction();
                    
                    // insert into Company table
                    $compStmt = $pdo->prepare("INSERT INTO Company (CompanyName, LocationID, TierLevel, Type) VALUES (?, ?, ?, ?)");
                    $compStmt->execute(array($companyName, $locationID, $selectedTier, $selectedType));
                    $newCompanyID = intval($pdo->lastInsertId());
                    
                    // insert into type-specific table
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
                    
                    // clear form after success
                    $companyName = '';
                    $selectedType = '';
                    $selectedTier = '';
                    $selectedLocation = '';
                    
                } catch (Exception $e) {
                    $pdo->rollBack();
                    $error = 'Failed to add company: ' . $e->getMessage();
                }
            }
        }
    }
}

// grab all locations for dropdown
$locStmt = $pdo->query("SELECT LocationID, City, CountryName, ContinentName FROM Location ORDER BY CountryName, City");
$locations = $locStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Company - ERP System</title>
    <link rel="stylesheet" href="../assets/styles.css">
    <style>
        .form-container {
            max-width: 800px;
            margin: 0 auto;
            background: rgba(0,0,0,0.7);
            padding: 30px;
            border-radius: 8px;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #CFB991;
            font-weight: bold;
        }
        .form-group input,
        .form-group select {
            width: 100%;
            padding: 10px;
            border: 1px solid #CFB991;
            background: rgba(0,0,0,0.5);
            color: white;
            border-radius: 4px;
            font-size: 1em;
        }
        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: #b89968;
            background: rgba(0,0,0,0.7);
        }
        .radio-group {
            display: flex;
            gap: 20px;
            margin-top: 8px;
        }
        .radio-option {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .radio-option input[type="radio"] {
            width: auto;
        }
        .radio-option label {
            margin: 0;
            font-weight: normal;
            color: white;
        }
        .location-section {
            background: rgba(207,185,145,0.1);
            padding: 15px;
            border-radius: 4px;
            margin-top: 10px;
        }
        .hidden {
            display: none;
        }
        .btn-submit {
            background: #CFB991;
            color: #000;
            padding: 12px 30px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 1.1em;
            font-weight: bold;
        }
        .btn-submit:hover {
            background: #b89968;
        }
        .btn-cancel {
            background: #666;
            color: white;
            padding: 12px 30px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 1.1em;
            margin-left: 10px;
            text-decoration: none;
            display: inline-block;
        }
        .btn-cancel:hover {
            background: #555;
        }
        .alert {
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        .alert-error {
            background: rgba(244,67,54,0.2);
            border: 1px solid #f44336;
            color: #ff9999;
        }
        .alert-success {
            background: rgba(76,175,80,0.2);
            border: 1px solid #4caf50;
            color: #90ee90;
        }
        .form-hint {
            font-size: 0.9em;
            color: rgba(255,255,255,0.6);
            margin-top: 5px;
        }
        .required {
            color: #f44336;
        }
    </style>
</head>
<body>
    <div class="container">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <h1>Add New Company</h1>
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
            <a href="disruptions.php">Disruptions</a>
        </nav>

        <div class="form-container">
            <?php if ($error): ?>
                <div class="alert alert-error">
                    <strong>Error:</strong> <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success">
                    <strong>Success!</strong> <?= $success ?>
                    <div style="margin-top: 10px;">
                        <a href="companies.php" style="color: #CFB991; text-decoration: underline;">View all companies</a> or add another company below.
                    </div>
                </div>
            <?php endif; ?>

            <form method="POST" action="add_company.php">
                <div class="form-group">
                    <label for="company_name">Company Name <span class="required">*</span></label>
                    <input type="text" id="company_name" name="company_name" value="<?= htmlspecialchars($companyName) ?>" required>
                    <div class="form-hint">Must be unique across all companies</div>
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
                    <div class="form-hint">Tier 1 = Top level, Tier 3 = Bottom level</div>
                </div>

                <div class="form-group">
                    <label>Location <span class="required">*</span></label>
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

                <div style="margin-top: 30px;">
                    <button type="submit" class="btn-submit">Add Company</button>
                    <a href="companies.php" class="btn-cancel">Cancel</a>
                </div>
            </form>
        </div>
    </div>

    <script>
    (function() {
        // toggle between existing and new location sections
        var existingRadio = document.getElementById('location_existing');
        var newRadio = document.getElementById('location_new');
        var existingSection = document.getElementById('existing_location_section');
        var newSection = document.getElementById('new_location_section');

        function toggleLocationSections() {
            if (existingRadio.checked) {
                existingSection.classList.remove('hidden');
                newSection.classList.add('hidden');
                // clear new location fields when switching to existing
                document.getElementById('new_city').removeAttribute('required');
                document.getElementById('new_country').removeAttribute('required');
                document.getElementById('new_continent').removeAttribute('required');
                document.getElementById('existing_location').setAttribute('required', 'required');
            } else {
                existingSection.classList.add('hidden');
                newSection.classList.remove('hidden');
                // make new location fields required
                document.getElementById('new_city').setAttribute('required', 'required');
                document.getElementById('new_country').setAttribute('required', 'required');
                document.getElementById('new_continent').setAttribute('required', 'required');
                document.getElementById('existing_location').removeAttribute('required');
            }
        }

        existingRadio.addEventListener('change', toggleLocationSections);
        newRadio.addEventListener('change', toggleLocationSections);

        // initialize on page load
        toggleLocationSections();
    })();
    </script>
</body>
</html>