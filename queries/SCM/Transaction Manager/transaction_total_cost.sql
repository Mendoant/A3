SELECT 
    s.ShipmentID,
    s.TransactionID,
    s.ProductID,
    p.ProductName,
    s.Quantity,
    sp.SupplyPrice,
    (s.Quantity * sp.SupplyPrice) AS TotalCost,
    c1.CompanyName AS SourceCompany,
    c2.CompanyName AS DestinationCompany,
    s.PromisedDate,
    s.ActualDate
FROM 
    Shipping s
    INNER JOIN Product p ON s.ProductID = p.ProductID
    INNER JOIN SuppliesProduct sp ON s.ProductID = sp.ProductID 
        AND s.SourceCompanyID = sp.SupplierID
    INNER JOIN Company c1 ON s.SourceCompanyID = c1.CompanyID
    INNER JOIN Company c2 ON s.DestinationCompanyID = c2.CompanyID
ORDER BY 
    s.ShipmentID;
