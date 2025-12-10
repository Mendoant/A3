-- financial.php: Lines 64-77
SELECT 
            c.CompanyID, 
            c.CompanyName, 
            c.Type, 
            c.TierLevel, 
            l.ContinentName, 
            AVG(fr.HealthScore) as AvgHealthScore,
            COUNT(fr.CompanyID) as ReportCount
        FROM Company c
        JOIN Location l ON c.LocationID = l.LocationID
        JOIN FinancialReport fr ON c.CompanyID = fr.CompanyID
        WHERE 1=1
        [dynamic_conditions]
        GROUP BY c.CompanyID, c.CompanyName, c.Type, c.TierLevel, l.ContinentName
        ORDER BY AvgHealthScore DESC

-- financial.php: Line 38 (Dynamic Append, decided based on previous inputs)
c.CompanyID = :companyId

-- financial.php: Line 43 (Dynamic Append, decided based on previous inputs)
l.ContinentName = :region

-- financial.php: Line 48 (Dynamic Append, decided based on previous inputs)
c.TierLevel = :tier

-- financial.php: Line 53 (Dynamic Append, decided based on previous inputs)
c.Type = :type

-- financial.php: Line 58 (Dynamic Append, decided based on previous inputs)
fr.RepYear >= :startYear AND fr.RepYear <= :endYear

-- financial.php: Line 169
SELECT DISTINCT ContinentName FROM Location ORDER BY ContinentName

-- financial.php: Line 170
SELECT CompanyID, CompanyName FROM Company ORDER BY CompanyName
