SELECT
    s.ShipmentID,
    s.ProductID,
    p.ProductName,
    s.SourceCompanyID,
    src.CompanyName AS SourceCompany,
    s.DestinationCompanyID,
    dest.CompanyName AS DestinationCompany,
    s.DistributorID,
    dist.CompanyName AS DistributorName,
    s.PromisedDate,
    s.ActualDate,

    CASE
        WHEN r.ReceivingID IS NOT NULL THEN 'Already Delivered'
        WHEN s.ActualDate IS NULL AND r.ReceivingID IS NULL THEN 'Out for Delivery'
        WHEN s.DistributorID = s.SourceCompanyID THEN 'With Distributor'
        ELSE 'With Supplier'
    END AS ShipmentStatus

FROM Shipping s
LEFT JOIN Receiving r 
    ON r.ShipmentID = s.ShipmentID
LEFT JOIN Product p
    ON p.ProductID = s.ProductID
LEFT JOIN Company src
    ON src.CompanyID = s.SourceCompanyID
LEFT JOIN Company dest
    ON dest.CompanyID = s.DestinationCompanyID
LEFT JOIN Company dist
    ON dist.CompanyID = s.DistributorID

ORDER BY ShipmentStatus, s.ShipmentID;
