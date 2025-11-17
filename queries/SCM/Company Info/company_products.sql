SET @companyID = '1';

SELECT
    s.SourceCompanyID              AS CompanyID,
    c.CompanyName,
    p.ProductID,
    p.ProductName,
    SUM(s.Quantity)                AS QuantitySold,
    ROUND(
        100 * SUM(s.Quantity) / t.TotalCompanyQuantity,
        2
    )                              AS PercentOfCompanySales
FROM Shipping s
JOIN Company c 
    ON c.CompanyID = s.SourceCompanyID
JOIN Product p
    ON p.ProductID = s.ProductID
JOIN (
    -- total quantity sold per company (for the selected companies)
    SELECT 
        SourceCompanyID,
        SUM(Quantity) AS TotalCompanyQuantity
    FROM Shipping
    WHERE SourceCompanyID = @companyID   -- <-- user-defined companies here
    GROUP BY SourceCompanyID
) t
    ON t.SourceCompanyID = s.SourceCompanyID
WHERE s.SourceCompanyID = @companyID      -- <-- same list here
GROUP BY
    s.SourceCompanyID,
    c.CompanyName,
    p.ProductID,
    p.ProductName,
    t.TotalCompanyQuantity
ORDER BY
    s.SourceCompanyID,
    PercentOfCompanySales DESC;
