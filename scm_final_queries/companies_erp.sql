-- companies.php: Lines 29-32
SELECT c.*, l.City, l.CountryName, l.ContinentName 
            FROM Company c
            LEFT JOIN Location l ON c.LocationID = l.LocationID
            WHERE c.CompanyID = ?

-- companies.php: Lines 39-42
SELECT * FROM FinancialReport 
            WHERE CompanyID = ? 
            ORDER BY RepYear DESC, FIELD(Quarter, 'Q4', 'Q3', 'Q2', 'Q1') 
            LIMIT 4

-- companies.php: Line 48
SELECT COUNT(*) FROM SuppliesProduct WHERE SupplierID = ?

-- companies.php: Lines 144-148
SELECT c.CompanyID, c.CompanyName, c.Type, c.TierLevel,
               l.City, l.CountryName, l.ContinentName
        FROM Company c
        LEFT JOIN Location l ON c.LocationID = l.LocationID
        WHERE 1=1

-- companies.php: Line 154 (Dynamic Append)
 AND c.CompanyID = ?

-- companies.php: Line 158 (Dynamic Append)
 AND c.Type = ?

-- companies.php: Line 162 (Dynamic Append)
 AND c.TierLevel = ?

-- companies.php: Line 166 (Dynamic Append)
 AND l.ContinentName = ?

-- companies.php: Line 170 (Dynamic Append)
 ORDER BY c.CompanyName

-- companies.php: Lines 179-181
SELECT CompanyID, HealthScore 
    FROM FinancialReport 
    ORDER BY RepYear DESC, FIELD(Quarter, 'Q4', 'Q3', 'Q2', 'Q1')

-- companies.php: Line 207
SELECT DISTINCT ContinentName FROM Location WHERE ContinentName IS NOT NULL ORDER BY ContinentName

-- companies.php: Line 211
SELECT CompanyID, CompanyName FROM Company ORDER BY CompanyName
