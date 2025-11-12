SELECT 
    d.CompanyID AS DistributorID,
    c.CompanyName AS DistributorName,
    AVG(DATEDIFF(s.ActualDate, s.PromisedDate)) AS AvgDelayDays
FROM Distributor d
JOIN Company c 
    ON d.CompanyID = c.CompanyID
JOIN Shipping s 
    ON s.DistributorID = d.CompanyID
WHERE s.ActualDate IS NOT NULL  -- only consider shipments that have been delivered
GROUP BY d.CompanyID, c.CompanyName
ORDER BY AvgDelayDays DESC;
