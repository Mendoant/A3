

-- critical_companies.php: Lines 19-20
SELECT c.CompanyID, c.CompanyName, c.Type, c.TierLevel, l.ContinentName, l.CountryName
        FROM Company c LEFT JOIN Location l ON c.LocationID = l.LocationID WHERE 1=1

-- critical_companies.php: Line 23
 AND l.ContinentName = [region]

-- critical_companies.php: Line 24
 AND c.TierLevel = [tier]

-- critical_companies.php: Line 25
 ORDER BY c.CompanyName

-- critical_companies.php: Line 31
SELECT COUNT(DISTINCT DownstreamCompanyID) as cnt FROM DependsOn WHERE UpstreamCompanyID = ?

-- critical_companies.php: Lines 35-37
SELECT COUNT(DISTINCT de.EventID) as cnt FROM DisruptionEvent de 
                            JOIN ImpactsCompany ic ON de.EventID = ic.EventID 
                            WHERE ic.AffectedCompanyID = ? AND ic.ImpactLevel = 'High'

-- critical_companies.php: Line 69
SELECT DISTINCT ContinentName FROM Location ORDER BY ContinentName
