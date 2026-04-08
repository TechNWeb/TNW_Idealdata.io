<?php

declare(strict_types=1);

namespace TNW\Idealdata\Plugin\Product;

use Magento\Catalog\Api\Data\ProductExtensionFactory;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\Data\ProductSearchResultsInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\ObjectManagerInterface;
use Psr\Log\LoggerInterface;

class StockItemPlugin
{
    public function __construct(
        private readonly StockRegistryInterface $stockRegistry,
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
        try {
            $this->attachStockData($result);
        } catch (\Throwable $e) {
            $this->logger->error(
                'TNW_Idealdata: Failed to attach stock data to product',
                ['exception' => $e->getMessage()]
            );
        }

        return $result;
    }

    /**
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterGetList(
        ProductRepositoryInterface $subject,
        ProductSearchResultsInterface $result
    ): ProductSearchResultsInterface {
        try {
            $products = $result->getItems();
            if (empty($products)) {
                return $result;
            }

            // Batch-load source items for all SKUs at once
            $skus = [];
            foreach ($products as $product) {
                $sku = $product->getSku();
                if ($sku) {
                    $skus[] = $sku;
                }
            }

            $sourceItemsMap = !empty($skus) ? $this->batchLoadSourceItems($skus) : [];

            foreach ($products as $product) {
                $this->attachStockItem($product);
                $this->attachSourceItems($product, $sourceItemsMap);
            }
        } catch (\Throwable $e) {
            $this->logger->error(
                'TNW_Idealdata: Failed to attach stock data to product list',
                ['exception' => $e->getMessage()]
            );
        }

        return $result;
    }

    private function attachStockData(ProductInterface $product): void
    {
        $this->attachStockItem($product);
        $this->attachSourceItems($product);
    }

    private function attachStockItem(ProductInterface $product): void
    {
        $extensionAttributes = $product->getExtensionAttributes();
        if ($extensionAttributes === null) {
            $extensionAttributes = $this->extensionFactory->create();
        }

        if ($extensionAttributes->getStockItem() !== null) {
            return;
        }

        $productId = (int) $product->getId();
        if ($productId <= 0) {
            return;
        }

        try {
            $stockItem = $this->stockRegistry->getStockItem($productId);
            $extensionAttributes->setStockItem($stockItem);
            $product->setExtensionAttributes($extensionAttributes);
        } catch (\Throwable $e) {
            $this->logger->debug(
                'TNW_Idealdata: Could not load stock item for product ' . $productId,
                ['exception' => $e->getMessage()]
            );
        }
    }

    /**
     * Attach MSI source items to product extension attributes.
     *
     * @param array<string, array> $preloadedMap Pre-loaded source items keyed by SKU (optional, for batch mode)
     */
    private function attachSourceItems(ProductInterface $product, array $preloadedMap = []): void
    {
        $extensionAttributes = $product->getExtensionAttributes();
        if ($extensionAttributes === null) {
            $extensionAttributes = $this->extensionFactory->create();
        }

        if ($extensionAttributes->getSourceItems() !== null) {
            return;
        }

        $sku = $product->getSku();
        if (empty($sku)) {
            return;
        }

        try {
            if (!empty($preloadedMap)) {
                $sourceItems = $preloadedMap[$sku] ?? [];
            } else {
                $sourceItems = $this->loadSourceItemsBySku($sku);
            }

            $extensionAttributes->setSourceItems($sourceItems);
            $product->setExtensionAttributes($extensionAttributes);
        } catch (\Throwable $e) {
            $this->logger->debug(
                'TNW_Idealdata: Could not load source items for SKU ' . $sku,
                ['exception' => $e->getMessage()]
            );
        }
    }

    /**
     * Load source items for a single SKU via MSI API.
     */
    private function loadSourceItemsBySku(string $sku): array
    {
        if (!interface_exists(\Magento\InventoryApi\Api\GetSourceItemsBySkuInterface::class)) {
            return [];
        }

        $service = $this->objectManager->get(\Magento\InventoryApi\Api\GetSourceItemsBySkuInterface::class);
        return $service->execute($sku);
    }

    /**
     * Batch-load source items for multiple SKUs using a single DB query.
     *
     * @param string[] $skus
     * @return array<string, \Magento\InventoryApi\Api\Data\SourceItemInterface[]>
     */
    private function batchLoadSourceItems(array $skus): array
    {
        try {
            if (!interface_exists(\Magento\InventoryApi\Api\Data\SourceItemInterfaceFactory::class)
                && !interface_exists(\Magento\InventoryApi\Api\Data\SourceItemInterface::class)) {
                return [];
            }

            $connection = $this->resourceConnection->getConnection();
            $tableName = $this->resourceConnection->getTableName('inventory_source_item');

            // Check if MSI table exists
            if (!$connection->isTableExists($tableName)) {
                return [];
            }

            $select = $connection->select()
                ->from($tableName)
                ->where('sku IN (?)', $skus);

            $rows = $connection->fetchAll($select);

            // Build SourceItem objects grouped by SKU
            $factory = $this->objectManager->get(\Magento\InventoryApi\Api\Data\SourceItemInterfaceFactory::class);

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
                'TNW_Idealdata: Could not batch-load MSI source items',
                ['exception' => $e->getMessage()]
            );
            return [];
        }
    }
}
