SELECT 
    de.EventID,
    dc.CategoryName AS DisruptionCategory,
    de.EventDate,
    de.EventRecoveryDate,
    AVG(DATEDIFF(r.ReceivedDate, s.PromisedDate)) AS AvgDelayDays,
    SUM(DATEDIFF(r.ReceivedDate, s.PromisedDate)) AS TotDelayDays
FROM DisruptionEvent de
JOIN DisruptionCategory dc 
    ON de.CategoryID = dc.CategoryID
JOIN ImpactsCompany ic 
    ON de.EventID = ic.EventID
JOIN Shipping s
    ON s.DestinationCompanyID = ic.AffectedCompanyID
JOIN Receiving r
    ON r.ShipmentID = s.ShipmentID
GROUP BY de.EventID, dc.CategoryName, de.EventDate, de.EventRecoveryDate
ORDER BY AvgDelayDays DESC;
