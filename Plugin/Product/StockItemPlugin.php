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
        $this->safeAttachStockItem($result);
        $this->safeAttachSourceItems($result);
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
        if (empty($products)) {
            return $result;
        }

        // Batch-load source items for all SKUs at once
        $sourceItemsMap = $this->safeBatchLoadSourceItems($products);

        foreach ($products as $product) {
            $this->safeAttachStockItem($product);
            $this->safeAttachSourceItems($product, $sourceItemsMap);
        }

        return $result;
    }

    private function safeAttachStockItem(ProductInterface $product): void
    {
        try {
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

            $stockItem = $this->stockRegistry->getStockItem($productId);
            $extensionAttributes->setStockItem($stockItem);
            $product->setExtensionAttributes($extensionAttributes);
        } catch (\Throwable $e) {
            $this->logger->debug(
                'TNW_Idealdata: Could not load stock item for product ' . ($product->getId() ?? '?'),
                ['exception' => $e->getMessage()]
            );
        }
    }

    /**
     * @param array<string, array> $preloadedMap Pre-loaded source items keyed by SKU
     */
    private function safeAttachSourceItems(ProductInterface $product, array $preloadedMap = []): void
    {
        try {
            $extensionAttributes = $product->getExtensionAttributes();
            if ($extensionAttributes === null) {
                $extensionAttributes = $this->extensionFactory->create();
            }

            // Check if the generated method exists (may not after adding the attribute without recompile)
            if (!method_exists($extensionAttributes, 'getSourceItems')) {
                return;
            }

            if ($extensionAttributes->getSourceItems() !== null) {
                return;
            }

            $sku = $product->getSku();
            if (empty($sku)) {
                return;
            }

            if (!empty($preloadedMap)) {
                $sourceItems = $preloadedMap[$sku] ?? [];
            } else {
                $sourceItems = $this->loadSourceItemsBySku($sku);
            }

            if (method_exists($extensionAttributes, 'setSourceItems')) {
                $extensionAttributes->setSourceItems($sourceItems);
                $product->setExtensionAttributes($extensionAttributes);
            }
        } catch (\Throwable $e) {
            $this->logger->debug(
                'TNW_Idealdata: Could not load source items for SKU ' . ($product->getSku() ?? '?'),
                ['exception' => $e->getMessage()]
            );
        }
    }

    /**
     * @param ProductInterface[] $products
     * @return array<string, array>
     */
    private function safeBatchLoadSourceItems(array $products): array
    {
        try {
            $connection = $this->resourceConnection->getConnection();
            $tableName = $this->resourceConnection->getTableName('inventory_source_item');

            if (!$connection->isTableExists($tableName)) {
                return [];
            }

            $skus = [];
            foreach ($products as $product) {
                $sku = $product->getSku();
                if ($sku) {
                    $skus[] = $sku;
                }
            }

            if (empty($skus)) {
                return [];
            }

            $select = $connection->select()
                ->from($tableName)
                ->where('sku IN (?)', $skus);

            $rows = $connection->fetchAll($select);

            if (!class_exists(\Magento\InventoryApi\Api\Data\SourceItemInterfaceFactory::class)) {
                return [];
            }

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

    private function loadSourceItemsBySku(string $sku): array
    {
        if (!interface_exists(\Magento\InventoryApi\Api\GetSourceItemsBySkuInterface::class)) {
            return [];
        }

        $service = $this->objectManager->get(\Magento\InventoryApi\Api\GetSourceItemsBySkuInterface::class);
        return $service->execute($sku);
    }
}
