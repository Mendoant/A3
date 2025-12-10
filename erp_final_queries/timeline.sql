-- timeline.php: Lines 27-30
SELECT de.EventID, de.EventDate, dc.CategoryName, dc.CategoryID 
        FROM DisruptionEvent de
        JOIN DisruptionCategory dc ON de.CategoryID = dc.CategoryID 
        WHERE de.EventDate BETWEEN :start AND :end

-- timeline.php: Line 36 (Dynamic Append)
 AND dc.CategoryID = :category

-- timeline.php: Lines 42-46 (Dynamic Append)
 AND EXISTS (
                SELECT 1 FROM ImpactsCompany ic 
                JOIN Company c ON ic.AffectedCompanyID = c.CompanyID
                JOIN Location l ON c.LocationID = l.LocationID 
                WHERE ic.EventID = de.EventID AND l.ContinentName = :region
              )

-- timeline.php: Line 51 (Dynamic Append)
 ORDER BY de.EventDate ASC

-- timeline.php: Lines 120-123
SELECT dc.CategoryName, COUNT(DISTINCT de.EventID) as eventCount 
                FROM DisruptionEvent de
                JOIN DisruptionCategory dc ON de.CategoryID = dc.CategoryID 
                WHERE de.EventDate BETWEEN :start AND :end

-- timeline.php: Lines 129-133 (Dynamic Append, added according to if statement)
 AND EXISTS (
                        SELECT 1 FROM ImpactsCompany ic 
                        JOIN Company c ON ic.AffectedCompanyID = c.CompanyID
                        JOIN Location l ON c.LocationID = l.LocationID 
                        WHERE ic.EventID = de.EventID AND l.ContinentName = :region
                      )

-- timeline.php: Line 138 (Dynamic Append)
 GROUP BY dc.CategoryName ORDER BY eventCount DESC

-- timeline.php: Line 169
SELECT CategoryID, CategoryName FROM DisruptionCategory ORDER BY CategoryName

-- timeline.php: Line 170
SELECT DISTINCT ContinentName FROM Location ORDER BY ContinentName
