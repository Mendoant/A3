-- add_company.php: Line 42
SELECT CompanyID FROM Company WHERE CompanyName = [company_name]

-- add_company.php: Line 68
SELECT LocationID FROM Location WHERE City = [new_city] AND CountryName = [new_country]

-- add_company.php: Line 75
INSERT INTO Location (City, CountryName, ContinentName) VALUES ([new_city], [new_country], [new_continent])

-- add_company.php: Line 86
INSERT INTO Company (CompanyName, LocationID, TierLevel, Type) VALUES ([company_name], ?, [tier], [type])

-- add_company.php: Line 92
INSERT INTO Manufacturer (CompanyID, FactoryCapacity) VALUES (?, 0)

-- add_company.php: Line 95
INSERT INTO Distributor (CompanyID) VALUES (?)

-- add_company.php: Line 98
INSERT INTO Retailer (CompanyID) VALUES (?)

-- add_company.php: Line 121
SELECT LocationID, City, CountryName, ContinentName FROM Location ORDER BY CountryName, City
