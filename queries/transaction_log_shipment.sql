-- Set these before running the query
SET @company_id = 2;
SET @start_date = '2020-01-01';
SET @end_date   = '2025-12-31';

-- Shipping transactions for a specific company and date range
SELECT 
    it.TransactionID,
    'Shipping' AS TransactionType,
    s.ShipmentID,
    p.ProductName,
    src.CompanyName AS SourceCompany,
    dest.CompanyName AS DestinationCompany,
    CASE 
        WHEN s.SourceCompanyID = @company_id THEN 'Source'
        WHEN s.DestinationCompanyID = @company_id THEN 'Destination'
        ELSE NULL
    END AS CompanyRole,
    s.PromisedDate,
    s.ActualDate,
    s.Quantity AS QuantityShipped
FROM InventoryTransaction it
JOIN Shipping s 
    ON s.TransactionID = it.TransactionID
JOIN Product p 
    ON p.ProductID = s.ProductID
JOIN Company src 
    ON src.CompanyID = s.SourceCompanyID
JOIN Company dest 
    ON dest.CompanyID = s.DestinationCompanyID
WHERE it.Type = 'Shipping'
  AND (
        s.SourceCompanyID = @company_id 
        OR s.DestinationCompanyID = @company_id
      )
  AND (
        (s.PromisedDate BETWEEN @start_date AND @end_date)
        OR (s.ActualDate BETWEEN @start_date AND @end_date)
      )
ORDER BY COALESCE(s.ActualDate, s.PromisedDate);
