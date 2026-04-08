<?php

declare(strict_types=1);

namespace TNW\Idealdata\Plugin\Product;

use Magento\Catalog\Api\Data\ProductExtensionFactory;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\Data\ProductSearchResultsInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\ObjectManagerInterface;
use Psr\Log\LoggerInterface;

class StockItemPlugin
{
    public function __construct(
        private readonly ProductExtensionFactory $extensionFactory,
        private readonly ResourceConnection $resourceConnection,
        private readonly ObjectManagerInterface $objectManager,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterGet(
        ProductRepositoryInterface $subject,
        ProductInterface $result
    ): ProductInterface {
        $products = [$result];
        $this->attachAllStockData($products);
        return $result;
    }

    /**
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterGetList(
        ProductRepositoryInterface $subject,
        ProductSearchResultsInterface $result
    ): ProductSearchResultsInterface {
        $products = $result->getItems();
        if (!empty($products)) {
            $this->attachAllStockData($products);
        }
        return $result;
    }

    /**
     * Single method that batch-loads stock_item, source_items, and manage_stock
     * for all products in one pass. Exactly 2 SQL queries total regardless of
     * product count: 1 for cataloginventory_stock_item, 1 for inventory_source_item.
     *
     * @param ProductInterface[] $products
     */
    private function attachAllStockData(array $products): void
    {
        $productIds = [];
        $skus = [];
        foreach ($products as $product) {
            $id = (int) $product->getId();
            $sku = $product->getSku();
            if ($id > 0) {
                $productIds[$id] = $product;
            }
            if ($sku) {
                $skus[] = $sku;
            }
        }

        // Batch 1: stock items (1 SQL query)
        $stockItemsMap = $this->batchLoadStockItems(array_keys($productIds));

        // Batch 2: source items (1 SQL query)
        $sourceItemsMap = !empty($skus) ? $this->batchLoadSourceItems($skus) : [];

        // Attach to each product
        foreach ($products as $product) {
            try {
                $extensionAttributes = $product->getExtensionAttributes();
                if ($extensionAttributes === null) {
                    $extensionAttributes = $this->extensionFactory->create();
                }

                // stock_item
                $productId = (int) $product->getId();
                if ($extensionAttributes->getStockItem() === null && isset($stockItemsMap[$productId])) {
                    $extensionAttributes->setStockItem($stockItemsMap[$productId]);
                }

                // manage_stock — derived from stock_item, no extra query
                if ($extensionAttributes->getManageStock() === null) {
                    $stockItem = $extensionAttributes->getStockItem();
                    $extensionAttributes->setManageStock(
                        $stockItem ? (int) $stockItem->getManageStock() : 0
                    );
                }

                // source_items
                if (method_exists($extensionAttributes, 'setSourceItems')
                    && $extensionAttributes->getSourceItems() === null
                ) {
                    $sku = $product->getSku();
                    $extensionAttributes->setSourceItems($sourceItemsMap[$sku] ?? []);
                }

                $product->setExtensionAttributes($extensionAttributes);
            } catch (\Throwable $e) {
                $this->logger->debug(
                    'TNW_Idealdata: Could not attach stock data to product ' . ($product->getId() ?? '?'),
                    ['exception' => $e->getMessage()]
                );
            }
        }
    }

    /**
     * 1 SQL query for all product IDs.
     *
     * @param int[] $productIds
     * @return array<int, \Magento\CatalogInventory\Api\Data\StockItemInterface>
     */
    private function batchLoadStockItems(array $productIds): array
    {
        if (empty($productIds)) {
            return [];
        }

        try {
            $connection = $this->resourceConnection->getConnection();
            $table = $this->resourceConnection->getTableName('cataloginventory_stock_item');

            $select = $connection->select()
                ->from($table)
                ->where('product_id IN (?)', $productIds);

            $rows = $connection->fetchAll($select);

            $factory = $this->objectManager->get(
                \Magento\CatalogInventory\Api\Data\StockItemInterfaceFactory::class
            );

            $map = [];
            foreach ($rows as $row) {
                $stockItem = $factory->create(['data' => $row]);
                $map[(int) $row['product_id']] = $stockItem;
            }

            return $map;
        } catch (\Throwable $e) {
            $this->logger->debug(
                'TNW_Idealdata: Could not batch-load stock items',
                ['exception' => $e->getMessage()]
            );
            return [];
        }
    }

    /**
     * 1 SQL query for all SKUs.
     *
     * @param string[] $skus
     * @return array<string, \Magento\InventoryApi\Api\Data\SourceItemInterface[]>
     */
    private function batchLoadSourceItems(array $skus): array
    {
        if (empty($skus)) {
            return [];
        }

        try {
            $connection = $this->resourceConnection->getConnection();
            $table = $this->resourceConnection->getTableName('inventory_source_item');

            $select = $connection->select()
                ->from($table, ['sku', 'source_code', 'quantity', 'status'])
                ->where('sku IN (?)', $skus);

            $rows = $connection->fetchAll($select);

            if (empty($rows)) {
                return [];
            }

            $factory = $this->objectManager->get(
                \Magento\InventoryApi\Api\Data\SourceItemInterfaceFactory::class
            );

            $map = [];
            foreach ($rows as $row) {
                $sku = $row['sku'];
                $sourceItem = $factory->create();
                $sourceItem->setSku($sku);
                $sourceItem->setSourceCode($row['source_code']);
                $sourceItem->setQuantity((float) $row['quantity']);
                $sourceItem->setStatus((int) $row['status']);
                $map[$sku][] = $sourceItem;
            }

            return $map;
        } catch (\Throwable $e) {
            $this->logger->debug(
                'TNW_Idealdata: Could not batch-load source items',
                ['exception' => $e->getMessage()]
            );
            return [];
        }
    }
}
