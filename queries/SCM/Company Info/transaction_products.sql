-- Query 2: Products that are part of a Transaction (Shipping or Inventory Adjustment)
-- This query returns a distinct list of products and their categories that have been involved
-- in any recorded inventory transaction, and the number of times each product was involved.

SELECT
    p.ProductID,
    p.ProductName,
    p.Category,
    COUNT(t.TransactionID) AS TotalTransactionCount
FROM
    Product p
JOIN
    (
        -- Get products involved in Shipping transactions
        SELECT ProductID FROM Shipping
        UNION ALL
        -- Get products involved in Inventory Adjustment transactions
        SELECT ProductID FROM InventoryAdjustment
    ) AS transactions_union(ProductID)
ON
    p.ProductID = transactions_union.ProductID
GROUP BY
    p.ProductID, p.ProductName, p.Category
ORDER BY
    TotalTransactionCount DESC;