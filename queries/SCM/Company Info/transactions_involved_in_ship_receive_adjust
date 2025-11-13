-- Set variables first
SET @company_id = 7;
SET @start_date = '2020-01-01';
SET @end_date   = '2025-12-31';

-- Master query
SELECT 
    @company_id AS TargetCompanyID,
    'Shipping' AS TransactionType,
    it.TransactionID,
    s.ShipmentID,
    p.ProductName,
    src.CompanyName AS SourceCompany,
    dest.CompanyName AS DestinationCompany,
    CASE 
      WHEN s.SourceCompanyID = @company_id THEN 'Source'
      WHEN s.DestinationCompanyID = @company_id THEN 'Destination'
      ELSE NULL
    END AS CompanyRole,
    COALESCE(s.ActualDate, s.PromisedDate) AS TransactionDate,
    s.Quantity AS QuantityShipped,
    NULL AS QuantityReceived,
    NULL AS QuantityChange,
    NULL AS Reason
FROM InventoryTransaction it
JOIN Shipping s ON s.TransactionID = it.TransactionID
JOIN Product p ON p.ProductID = s.ProductID
JOIN Company src ON src.CompanyID = s.SourceCompanyID
JOIN Company dest ON dest.CompanyID = s.DestinationCompanyID
WHERE it.Type = 'Shipping'
  AND (s.SourceCompanyID = @company_id OR s.DestinationCompanyID = @company_id)
  AND (
        (s.PromisedDate BETWEEN @start_date AND @end_date)
     OR (s.ActualDate   BETWEEN @start_date AND @end_date)
      )

UNION ALL

SELECT
    @company_id AS TargetCompanyID,
    'Receiving' AS TransactionType,
    it.TransactionID,
    r.ShipmentID,
    p.ProductName,
    src.CompanyName AS SourceCompany,
    dest.CompanyName AS DestinationCompany,
    'Receiver' AS CompanyRole,
    r.ReceivedDate AS TransactionDate,
    NULL AS QuantityShipped,
    r.QuantityReceived,
    NULL AS QuantityChange,
    NULL AS Reason
FROM InventoryTransaction it
JOIN Receiving r ON r.TransactionID = it.TransactionID
JOIN Shipping s ON s.ShipmentID = r.ShipmentID
JOIN Product p ON p.ProductID = s.ProductID
JOIN Company src ON src.CompanyID = s.SourceCompanyID
JOIN Company dest ON dest.CompanyID = s.DestinationCompanyID
WHERE it.Type = 'Receiving'
  AND r.ReceiverCompanyID = @company_id
  AND r.ReceivedDate BETWEEN @start_date AND @end_date

UNION ALL

SELECT
    @company_id AS TargetCompanyID,
    'Adjustment' AS TransactionType,
    it.TransactionID,
    NULL AS ShipmentID,
    p.ProductName,
    NULL AS SourceCompany,
    NULL AS DestinationCompany,
    'Adjustment' AS CompanyRole,
    ia.AdjustmentDate AS TransactionDate,
    NULL AS QuantityShipped,
    NULL AS QuantityReceived,
    ia.QuantityChange,
    ia.Reason
FROM InventoryTransaction it
JOIN InventoryAdjustment ia ON ia.TransactionID = it.TransactionID
JOIN Product p ON p.ProductID = ia.ProductID
WHERE it.Type = 'Adjustment'
  AND ia.CompanyID = @company_id
  AND ia.AdjustmentDate BETWEEN @start_date AND @end_date

ORDER BY TransactionType, TransactionDate;
