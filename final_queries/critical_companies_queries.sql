
--  companies.php: lines 18-19
SELECT c.CompanyID, c.CompanyName, c.Type, c.TierLevel, l.ContinentName, l.CountryName
        FROM Company c LEFT JOIN Location l ON c.LocationID = l.LocationID WHERE 1=1

--  companies.php: lines 32
SELECT COUNT(DISTINCT DownstreamCompanyID) as cnt FROM DependsOn WHERE UpstreamCompanyID = ?

--  companies.php: lines 36-38
SELECT COUNT(DISTINCT de.EventID) as cnt FROM DisruptionEvent de 
                            JOIN ImpactsCompany ic ON de.EventID = ic.EventID 
                            WHERE ic.AffectedCompanyID = ? AND ic.ImpactLevel = 'High'

--  companies.php: lines 70
SELECT DISTINCT ContinentName FROM Location ORDER BY ContinentName
