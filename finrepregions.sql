SET @region := 'Europe';

SELECT
    c.CompanyID,
    c.CompanyName,
    c.Type AS CompanyType,
    l.ContinentName AS Region,
    AVG(fr.HealthScore) AS AvgHealthScore,
    (
        SELECT fr2.HealthScore
        FROM FinancialReport fr2
        WHERE fr2.CompanyID = c.CompanyID
        ORDER BY fr2.RepYear DESC, 
                 FIELD(fr2.Quarter, 'Q4','Q3','Q2','Q1')
        LIMIT 1
    ) AS MostRecentHealthScore,
    COUNT(*) AS NumQuarters
FROM Company c
JOIN Location l 
  ON l.LocationID = c.LocationID
JOIN FinancialReport fr 
  ON fr.CompanyID = c.CompanyID
WHERE l.ContinentName = @region
GROUP BY c.CompanyID, c.CompanyName, c.Type, l.ContinentName
ORDER BY AvgHealthScore DESC, c.CompanyName;
