SELECT
    d.CompanyID AS DistributorID,
    c.CompanyName AS DistributorName,
    SUM(s.Quantity) AS TotalShipped,
    RANK() OVER (ORDER BY SUM(s.Quantity) DESC) AS RankByVolume
FROM Distributor d
JOIN Company c 
    ON d.CompanyID = c.CompanyID
JOIN Shipping s 
    ON s.DistributorID = d.CompanyID
GROUP BY d.CompanyID, c.CompanyName
ORDER BY TotalShipped DESC;
