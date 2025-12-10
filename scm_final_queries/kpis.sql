-- kpis.php: Lines 54-60
SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN s.ActualDate IS NOT NULL AND s.ActualDate <= s.PromisedDate THEN 1 ELSE 0 END) as onTime,
            SUM(CASE WHEN s.ActualDate IS NOT NULL AND s.ActualDate > s.PromisedDate THEN 1 ELSE 0 END) as `delayed`
        FROM Shipping s
        [joins]
        [whereClause] AND s.ActualDate IS NOT NULL

-- kpis.php: Lines 72-77
SELECT 
            AVG(DATEDIFF(s.ActualDate, s.PromisedDate)) as avgDelay,
            STDDEV(DATEDIFF(s.ActualDate, s.PromisedDate)) as stdDelay
        FROM Shipping s
        [joins]
        [whereClause] AND s.ActualDate IS NOT NULL AND s.ActualDate > s.PromisedDate

-- kpis.php: Lines 88-92
SELECT RepYear, Quarter, HealthScore
            FROM FinancialReport
            WHERE CompanyID = :companyID
            ORDER BY RepYear DESC, Quarter DESC
            LIMIT 4

-- kpis.php: Lines 98
SELECT CompanyName FROM Company WHERE CompanyID = ?

-- kpis.php: Lines 106-112
SELECT 
            dc.CategoryName,
            COUNT(DISTINCT de.EventID) as eventCount,
            SUM(CASE WHEN ic.ImpactLevel = 'High' THEN 1 ELSE 0 END) as highImpact
        FROM DisruptionEvent de
        JOIN DisruptionCategory dc ON de.CategoryID = dc.CategoryID
        LEFT JOIN ImpactsCompany ic ON de.EventID = ic.EventID

-- kpis.php: Line 167
SELECT CompanyID, CompanyName FROM Company ORDER BY CompanyName

-- kpis.php: Line 168
SELECT DISTINCT ContinentName FROM Location ORDER BY ContinentName
