SELECT 
    c.CompanyName,
    m.FactoryCapacity
FROM Company c
JOIN Manufacturer m 
    ON m.CompanyID = c.CompanyID
WHERE c.CompanyName = 'Boyd PLC'
  AND c.Type = 'Manufacturer';
