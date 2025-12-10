-- disruptions.php: Lines 15-3
SELECT 
    de.EventID,
    de.EventDate,
    de.EventRecoveryDate,
    dc.CategoryName,
    dc.Description as CategoryDescription,
    GROUP_CONCAT(DISTINCT c.CompanyName ORDER BY c.CompanyName SEPARATOR ', ') as AffectedCompanies,
    COUNT(DISTINCT ic.AffectedCompanyID) as CompanyCount,
    DATEDIFF(CURDATE(), de.EventDate) as DaysSinceStart,
    MAX(ic.ImpactLevel) as MaxImpact
FROM DisruptionEvent de
JOIN DisruptionCategory dc ON de.CategoryID = dc.CategoryID
JOIN ImpactsCompany ic ON de.EventID = ic.EventID
JOIN Company c ON ic.AffectedCompanyID = c.CompanyID
WHERE de.EventRecoveryDate IS NULL 
   OR de.EventRecoveryDate >= CURDATE()
GROUP BY de.EventID, de.EventDate, de.EventRecoveryDate, dc.CategoryName, dc.Description
ORDER BY de.EventDate DESC
LIMIT 10

-- disruptions.php: Lines 53-64
SELECT 
            c.CompanyID,
            c.CompanyName,
            c.TierLevel,
            l.ContinentName,
            COUNT(DISTINCT de.EventID) as disruptionCount
        FROM Company c
        LEFT JOIN Location l ON c.LocationID = l.LocationID
        LEFT JOIN ImpactsCompany ic ON c.CompanyID = ic.AffectedCompanyID
        LEFT JOIN DisruptionEvent de ON ic.EventID = de.EventID 
            AND de.EventDate BETWEEN :start AND :end
        WHERE 1=1

-- disruptions.php: Line 68 (Dynamic Append)
 AND c.CompanyID = :companyID

-- disruptions.php: Line 73 (Dynamic Append)
 AND l.ContinentName = :region

-- disruptions.php: Line 78 (Dynamic Append)
 AND c.TierLevel = :tier

-- disruptions.php: Lines 82-83 (Dynamic Append)
GROUP BY c.CompanyID, c.CompanyName, c.TierLevel, l.ContinentName
ORDER BY disruptionCount DESC

-- disruptions.php: Lines 91-98
SELECT 
            AVG(DATEDIFF(de.EventRecoveryDate, de.EventDate)) as avgRecoveryTime
         FROM DisruptionEvent de
         JOIN ImpactsCompany ic ON de.EventID = ic.EventID
         JOIN Company c ON ic.AffectedCompanyID = c.CompanyID
         JOIN Location l ON c.LocationID = l.LocationID
         WHERE de.EventDate BETWEEN :start AND :end
         AND de.EventRecoveryDate IS NOT NULL

-- disruptions.php: Line 103 (Dynamic Append)
 AND c.CompanyID = :companyID

-- disruptions.php: Line 107 (Dynamic Append)
 AND l.ContinentName = :region

-- disruptions.php: Line 111 (Dynamic Append)
 AND c.TierLevel = :tier

-- disruptions.php: Lines 122-129
SELECT 
            COUNT(DISTINCT de.EventID) as totalEvents,
            SUM(CASE WHEN ic.ImpactLevel = 'High' THEN 1 ELSE 0 END) as highImpactEvents
         FROM DisruptionEvent de
         JOIN ImpactsCompany ic ON de.EventID = ic.EventID
         JOIN Company c ON ic.AffectedCompanyID = c.CompanyID
         JOIN Location l ON c.LocationID = l.LocationID
         WHERE de.EventDate BETWEEN :start AND :end

-- disruptions.php: Line 134 (Dynamic Append)
 AND c.CompanyID = :companyID

-- disruptions.php: Line 138 (Dynamic Append)
 AND l.ContinentName = :region

-- disruptions.php: Line 142 (Dynamic Append)
 AND c.TierLevel = :tier

-- disruptions.php: Lines 156-163
SELECT 
            SUM(DATEDIFF(de.EventRecoveryDate, de.EventDate)) as totalDowntime
         FROM DisruptionEvent de
         JOIN ImpactsCompany ic ON de.EventID = ic.EventID
         JOIN Company c ON ic.AffectedCompanyID = c.CompanyID
         JOIN Location l ON c.LocationID = l.LocationID
         WHERE de.EventDate BETWEEN :start AND :end
         AND de.EventRecoveryDate IS NOT NULL

-- disruptions.php: Line 168 (Dynamic Append)
 AND c.CompanyID = :companyID

-- disruptions.php: Line 172 (Dynamic Append)
 AND l.ContinentName = :region

-- disruptions.php: Line 176 (Dynamic Append)
 AND c.TierLevel = :tier

-- disruptions.php: Lines 187-194
SELECT 
            l.ContinentName,
            COUNT(DISTINCT de.EventID) as regionDisruptions
         FROM DisruptionEvent de
         JOIN ImpactsCompany ic ON de.EventID = ic.EventID
         JOIN Company c ON ic.AffectedCompanyID = c.CompanyID
         JOIN Location l ON c.LocationID = l.LocationID
         WHERE de.EventDate BETWEEN :start AND :end

-- disruptions.php: Line 199 (Dynamic Append)
 AND c.CompanyID = :companyID

-- disruptions.php: Line 203 (Dynamic Append)
 AND l.ContinentName = :region

-- disruptions.php: Line 207 (Dynamic Append)
 AND c.TierLevel = :tier

-- disruptions.php: Line 211 (Dynamic Append)
 GROUP BY l.ContinentName ORDER BY regionDisruptions DESC

-- disruptions.php: Lines 218-226
SELECT 
            DATE_FORMAT(de.EventDate, '%Y-%m') as period,
            ic.ImpactLevel,
            COUNT(DISTINCT de.EventID) as eventCount
         FROM DisruptionEvent de
         JOIN ImpactsCompany ic ON de.EventID = ic.EventID
         JOIN Company c ON ic.AffectedCompanyID = c.CompanyID
         JOIN Location l ON c.LocationID = l.LocationID
         WHERE de.EventDate BETWEEN :start AND :end

-- disruptions.php: Line 231 (Dynamic Append)
 AND c.CompanyID = :companyID

-- disruptions.php: Line 235 (Dynamic Append)
 AND l.ContinentName = :region

-- disruptions.php: Line 239 (Dynamic Append)
 AND c.TierLevel = :tier

-- disruptions.php: Lines 243-244 (Dynamic Append)
GROUP BY DATE_FORMAT(de.EventDate, '%Y-%m'), ic.ImpactLevel
ORDER BY period, ic.ImpactLevel

-- disruptions.php: Lines 251-258
SELECT DATEDIFF(de.EventRecoveryDate, de.EventDate) as recoveryDays
         FROM DisruptionEvent de
         JOIN ImpactsCompany ic ON de.EventID = ic.EventID
         JOIN Company c ON ic.AffectedCompanyID = c.CompanyID
         JOIN Location l ON c.LocationID = l.LocationID
         WHERE de.EventDate BETWEEN :start AND :end
         AND de.EventRecoveryDate IS NOT NULL
         AND DATEDIFF(de.EventRecoveryDate, de.EventDate) >= 0

-- disruptions.php: Line 263 (Dynamic Append)
 AND c.CompanyID = :companyID

-- disruptions.php: Line 267 (Dynamic Append)
 AND l.ContinentName = :region

-- disruptions.php: Line 271 (Dynamic Append)
 AND c.TierLevel = :tier

-- disruptions.php: Lines 280-288
SELECT dc.CategoryName,
                SUM(DATEDIFF(de.EventRecoveryDate, de.EventDate)) as totalDowntime
         FROM DisruptionEvent de
         JOIN DisruptionCategory dc ON de.CategoryID = dc.CategoryID
         JOIN ImpactsCompany ic ON de.EventID = ic.EventID
         JOIN Company c ON ic.AffectedCompanyID = c.CompanyID
         JOIN Location l ON c.LocationID = l.LocationID
         WHERE de.EventDate BETWEEN :start AND :end
         AND de.EventRecoveryDate IS NOT NULL

-- disruptions.php: Line 293 (Dynamic Append)
 AND c.CompanyID = :companyID

-- disruptions.php: Line 297 (Dynamic Append)
 AND l.ContinentName = :region

-- disruptions.php: Line 301 (Dynamic Append)
 AND c.TierLevel = :tier

-- disruptions.php: Line 305 (Dynamic Append)
 GROUP BY dc.CategoryName ORDER BY totalDowntime DESC

-- disruptions.php: Lines 311-319
SELECT l.ContinentName as RegionName,
                SUM(DATEDIFF(de.EventRecoveryDate, de.EventDate)) as totalDowntime
         FROM DisruptionEvent de
         JOIN DisruptionCategory dc ON de.CategoryID = dc.CategoryID
         JOIN ImpactsCompany ic ON de.EventID = ic.EventID
         JOIN Company c ON ic.AffectedCompanyID = c.CompanyID
         JOIN Location l ON c.LocationID = l.LocationID
         WHERE de.EventDate BETWEEN :start AND :end
         AND de.EventRecoveryDate IS NOT NULL

-- disruptions.php: Line 324 (Dynamic Append)
 AND c.CompanyID = :companyID

-- disruptions.php: Line 328 (Dynamic Append)
 AND l.ContinentName = :region

-- disruptions.php: Line 332 (Dynamic Append)
 AND c.TierLevel = :tier

-- disruptions.php: Line 336 (Dynamic Append)
 GROUP BY l.ContinentName ORDER BY totalDowntime DESC

-- disruptions.php: Lines 342-350
SELECT c.TierLevel,
                SUM(DATEDIFF(de.EventRecoveryDate, de.EventDate)) as totalDowntime
         FROM DisruptionEvent de
         JOIN DisruptionCategory dc ON de.CategoryID = dc.CategoryID
         JOIN ImpactsCompany ic ON de.EventID = ic.EventID
         JOIN Company c ON ic.AffectedCompanyID = c.CompanyID
         JOIN Location l ON c.LocationID = l.LocationID
         WHERE de.EventDate BETWEEN :start AND :end
         AND de.EventRecoveryDate IS NOT NULL

-- disruptions.php: Line 355 (Dynamic Append)
 AND c.CompanyID = :companyID

-- disruptions.php: Line 359 (Dynamic Append)
 AND l.ContinentName = :region

-- disruptions.php: Line 363 (Dynamic Append)
 AND c.TierLevel = :tier

-- disruptions.php: Line 367 (Dynamic Append)
 GROUP BY c.TierLevel ORDER BY totalDowntime DESC

-- disruptions.php: Lines 375-381
SELECT l.CountryName,
                COUNT(DISTINCT de.EventID) as eventCount
         FROM DisruptionEvent de
         JOIN ImpactsCompany ic ON de.EventID = ic.EventID
         JOIN Company c ON ic.AffectedCompanyID = c.CompanyID
         JOIN Location l ON c.LocationID = l.LocationID
         WHERE de.EventDate BETWEEN :start AND :end

-- disruptions.php: Line 386 (Dynamic Append)
 AND c.CompanyID = :companyID

-- disruptions.php: Line 390 (Dynamic Append)
 AND l.ContinentName = :region

-- disruptions.php: Line 394 (Dynamic Append)
 AND c.TierLevel = :tier

-- disruptions.php: Line 398 (Dynamic Append)
 GROUP BY l.CountryName ORDER BY eventCount DESC

-- disruptions.php: Lines 405-413
SELECT dc.CategoryName,
                 COUNT(DISTINCT de.EventID) as totalEvents,
                 SUM(CASE WHEN ic.ImpactLevel = 'High' THEN 1 ELSE 0 END) as highImpactEvents
          FROM DisruptionEvent de
          JOIN ImpactsCompany ic ON de.EventID = ic.EventID
          JOIN DisruptionCategory dc ON de.CategoryID = dc.CategoryID
          JOIN Company c ON ic.AffectedCompanyID = c.CompanyID
          JOIN Location l ON c.LocationID = l.LocationID
          WHERE de.EventDate BETWEEN :start AND :end

-- disruptions.php: Line 418 (Dynamic Append)
 AND c.CompanyID = :companyID

-- disruptions.php: Line 422 (Dynamic Append)
 AND l.ContinentName = :region

-- disruptions.php: Line 426 (Dynamic Append)
 AND c.TierLevel = :tier

-- disruptions.php: Line 430 (Dynamic Append)
 GROUP BY dc.CategoryName ORDER BY dc.CategoryName

-- disruptions.php: Lines 437-460
SELECT 
                de.EventID,
                de.EventDate,
                de.EventRecoveryDate,
                dc.CategoryName,
                dc.Description as CategoryDescription,
                GROUP_CONCAT(DISTINCT c.CompanyName ORDER BY c.CompanyName SEPARATOR ', ') as AffectedCompanies,
                COUNT(DISTINCT ic.AffectedCompanyID) as CompanyCount,
                MAX(ic.ImpactLevel) as MaxImpact,
                CASE 
                    WHEN de.EventRecoveryDate IS NULL THEN DATEDIFF(CURDATE(), de.EventDate)
                    ELSE DATEDIFF(de.EventRecoveryDate, de.EventDate)
                END as Duration,
                CASE 
                    WHEN de.EventRecoveryDate IS NULL THEN 'Ongoing'
                    WHEN de.EventRecoveryDate >= CURDATE() THEN 'Ongoing'
                    ELSE 'Recovered'
                END as Status
            FROM DisruptionEvent de
            JOIN DisruptionCategory dc ON de.CategoryID = dc.CategoryID
            JOIN ImpactsCompany ic ON de.EventID = ic.EventID
            JOIN Company c ON ic.AffectedCompanyID = c.CompanyID
            JOIN Location l ON c.LocationID = l.LocationID
            WHERE de.EventDate BETWEEN :start AND :end

-- disruptions.php: Line 465 (Dynamic Append)
 AND c.CompanyID = :companyID

-- disruptions.php: Line 469 (Dynamic Append)
 AND l.ContinentName = :region

-- disruptions.php: Line 473 (Dynamic Append)
 AND c.TierLevel = :tier

-- disruptions.php: Line 477-478 (Dynamic Append)
GROUP BY de.EventID, de.EventDate, de.EventRecoveryDate, dc.CategoryName, dc.Description
ORDER BY de.EventDate DESC

-- disruptions.php: Line 461
SELECT CompanyID, CompanyName FROM Company ORDER BY CompanyName

-- disruptions.php: Line 462
SELECT DISTINCT ContinentName FROM Location ORDER BY ContinentName
