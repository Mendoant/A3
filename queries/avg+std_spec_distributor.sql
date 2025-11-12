-- Set user-defined variables before running
SET @company_id = 45;           -- ID of the company (Distributor)
-- Reminder: Dist are: CompanyID 45-60
SET @start_date = '2020-01-01';
SET @end_date   = '2025-12-31';

-- Query: average and standard deviation of delay
SELECT 
    d.CompanyID AS DistributorID,
    c.CompanyName AS DistributorName,
    AVG(DATEDIFF(s.ActualDate, s.PromisedDate)) AS AvgDelayDays,
    STDDEV(DATEDIFF(s.ActualDate, s.PromisedDate)) AS StdDelayDays
FROM Distributor d
JOIN Company c 
    ON d.CompanyID = c.CompanyID
JOIN Shipping s 
    ON s.DistributorID = d.CompanyID
WHERE s.ActualDate IS NOT NULL
  AND s.PromisedDate BETWEEN @start_date AND @end_date
  AND d.CompanyID = @company_id
GROUP BY d.CompanyID, c.CompanyName;
