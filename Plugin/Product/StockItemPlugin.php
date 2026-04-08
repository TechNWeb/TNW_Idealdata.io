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
        $sourceItemsMap = $this->loadSourceItemsBySkus([$result->getSku()]);
        $this->safeAttachSourceItems($result, $sourceItemsMap);
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

        $skus = [];
        foreach ($products as $product) {
            $sku = $product->getSku();
            if ($sku) {
                $skus[] = $sku;
            }
        }

        $sourceItemsMap = !empty($skus) ? $this->loadSourceItemsBySkus($skus) : [];

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
     * @param array<string, \Magento\InventoryApi\Api\Data\SourceItemInterface[]> $sourceItemsMap
     */
    private function safeAttachSourceItems(ProductInterface $product, array $sourceItemsMap): void
    {
        try {
            $extensionAttributes = $product->getExtensionAttributes();
            if ($extensionAttributes === null) {
                $extensionAttributes = $this->extensionFactory->create();
            }

            if (!method_exists($extensionAttributes, 'setSourceItems')) {
                return;
            }

            if ($extensionAttributes->getSourceItems() !== null) {
                return;
            }

            $sku = $product->getSku();
            $sourceItems = $sourceItemsMap[$sku] ?? [];

            $extensionAttributes->setSourceItems($sourceItems);
            $product->setExtensionAttributes($extensionAttributes);
        } catch (\Throwable $e) {
            $this->logger->debug(
                'TNW_Idealdata: Could not set source items for SKU ' . ($product->getSku() ?? '?'),
                ['exception' => $e->getMessage()]
            );
        }
    }

    /**
     * Single SQL query to load source items for one or many SKUs.
     * Used by both afterGet and afterGetList — consistent behavior.
     *
     * @param string[] $skus
     * @return array<string, \Magento\InventoryApi\Api\Data\SourceItemInterface[]>
     */
    private function loadSourceItemsBySkus(array $skus): array
    {
        $skus = array_filter($skus);
        if (empty($skus)) {
            return [];
        }

        try {
            $connection = $this->resourceConnection->getConnection();
            $tableName = $this->resourceConnection->getTableName('inventory_source_item');

            if (!$connection->isTableExists($tableName)) {
                return [];
            }

            $select = $connection->select()
                ->from($tableName, ['sku', 'source_code', 'quantity', 'status'])
                ->where('sku IN (?)', $skus);

            $rows = $connection->fetchAll($select);

            if (empty($rows)) {
                return [];
            }

            if (!class_exists(\Magento\InventoryApi\Api\Data\SourceItemInterfaceFactory::class)) {
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
                'TNW_Idealdata: Could not load MSI source items',
                ['exception' => $e->getMessage()]
            );
            return [];
        }
    }
}
