--This file documents the SQL queries found in the critical_companies.php file. The lines where a query 
--is found will be commented prior to the exact code pulled from the file.

--Lines 18-19
SELECT c.CompanyID, c.CompanyName, c.Type, c.TierLevel, l.ContinentName, l.CountryName
        FROM Company c LEFT JOIN Location l ON c.LocationID = l.LocationID WHERE 1=1

--Line 32
SELECT COUNT(DISTINCT DownstreamCompanyID) as cnt FROM DependsOn WHERE UpstreamCompanyID = ?

--Lines 36-38
SELECT COUNT(DISTINCT de.EventID) as cnt FROM DisruptionEvent de 
                            JOIN ImpactsCompany ic ON de.EventID = ic.EventID 
                            WHERE ic.AffectedCompanyID = ? AND ic.ImpactLevel = 'High'

--Line 70
SELECT DISTINCT ContinentName FROM Location ORDER BY ContinentName
