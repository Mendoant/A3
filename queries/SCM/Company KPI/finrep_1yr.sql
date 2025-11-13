SET @CompanyID = 56;  

SELECT 
    t.CompanyID,
    c.CompanyName,
    t.RepYear,
    t.Quarter,
    t.HealthScore
FROM (
    SELECT 
        fr.CompanyID,
        fr.RepYear,
        fr.Quarter,
        fr.HealthScore,
        STR_TO_DATE(
            CONCAT(CAST(fr.RepYear AS CHAR), '-',
                   CASE fr.Quarter
                       WHEN 'Q1' THEN '03-31'
                       WHEN 'Q2' THEN '06-30'
                       WHEN 'Q3' THEN '09-30'
                       WHEN 'Q4' THEN '12-31'
                   END),
            '%Y-%m-%d'
        ) AS quarter_end
    FROM FinancialReport fr
) AS t
JOIN Company c 
  ON c.CompanyID = t.CompanyID
WHERE t.CompanyID = @CompanyID
  AND t.quarter_end > DATE_SUB(CURDATE(), INTERVAL 1 YEAR)
ORDER BY t.quarter_end;
