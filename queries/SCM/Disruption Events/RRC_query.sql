--RRC Query

SELECT
    l.ContinentName AS Region,
    COUNT(DISTINCT ic.EventID) AS NumDisruptionsInRegion,
    (COUNT(DISTINCT ic.EventID) / t.TotalDisruptions) AS RRC
FROM ImpactsCompany ic
JOIN Company c 
    ON ic.AffectedCompanyID = c.CompanyID
JOIN Location l
    ON c.LocationID = l.LocationID
CROSS JOIN (
    SELECT COUNT(DISTINCT EventID) AS TotalDisruptions
    FROM DisruptionEvent
) t
GROUP BY l.ContinentName, t.TotalDisruptions
ORDER BY RRC DESC;
