-- critical_companies.php: Lines 26-29
SELECT c.CompanyID, c.CompanyName, c.Type, c.TierLevel, l.ContinentName, l.CountryName
        FROM Company c 
        LEFT JOIN Location l ON c.LocationID = l.LocationID 
        WHERE 1=1

-- critical_companies.php: Line 34 (Dynamic Append)
 AND l.ContinentName = :region

-- critical_companies.php: Line 38 (Dynamic Append)
 AND c.TierLevel = :tier

-- critical_companies.php: Line 42 (Dynamic Append)
 ORDER BY c.CompanyName

-- critical_companies.php: Line 59
SELECT COUNT(DISTINCT DownstreamCompanyID) as cnt FROM DependsOn WHERE UpstreamCompanyID = ?

-- critical_companies.php: Lines 64-67
SELECT COUNT(DISTINCT de.EventID) as cnt 
                            FROM DisruptionEvent de 
                            JOIN ImpactsCompany ic ON de.EventID = ic.EventID 
                            WHERE ic.AffectedCompanyID = ? AND ic.ImpactLevel = 'High'

-- critical_companies.php: Line 143
SELECT DISTINCT ContinentName FROM Location ORDER BY ContinentName
