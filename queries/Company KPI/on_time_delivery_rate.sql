-- Set your date range first
SET @company_id = 45;           -- ID of the company (Distributor)
-- Reminder: Dist are: CompanyID 45-60
SET @start_date = '2025-01-01';
SET @end_date   = '2025-12-31';

-- Query: on-time delivery rate
SELECT
    d.CompanyID AS DistributorID,
    c.CompanyName AS DistributorName,
    COUNT(s.ShipmentID) AS TotalShipments,
    SUM(CASE 
          WHEN s.ActualDate <= s.PromisedDate THEN 1 
          ELSE 0 
        END) AS OnTimeShipments,
    ROUND(
        (SUM(CASE WHEN s.ActualDate <= s.PromisedDate THEN 1 ELSE 0 END) 
         / COUNT(s.ShipmentID)) * 100, 
        2
    ) AS OnTimeDeliveryRatePercent
FROM Distributor d
JOIN Company c 
    ON d.CompanyID = c.CompanyID
JOIN Shipping s 
    ON s.DistributorID = d.CompanyID
WHERE s.ActualDate IS NOT NULL
  AND s.PromisedDate BETWEEN @start_date AND @end_date
  AND d.CompanyID = @company_id
GROUP BY d.CompanyID, c.CompanyName
ORDER BY OnTimeDeliveryRatePercent DESC;

