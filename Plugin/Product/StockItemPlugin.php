<?php

declare(strict_types=1);

namespace TNW\Idealdata\Plugin\Product;

use Magento\Catalog\Api\Data\ProductExtensionFactory;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\Data\ProductSearchResultsInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\CatalogInventory\Api\Data\StockItemInterface;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\CatalogInventory\Model\ResourceModel\Stock\Item\CollectionFactory as StockItemCollectionFactory;
use Psr\Log\LoggerInterface;

class StockItemPlugin
{
    public function __construct(
        private readonly StockRegistryInterface $stockRegistry,
        private readonly StockItemCollectionFactory $stockItemCollectionFactory,
        private readonly ProductExtensionFactory $extensionFactory,
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
            $this->attachStockItemToProduct($result);
        } catch (\Throwable $e) {
            $this->logger->error(
                'TNW_Idealdata: Failed to attach stock_item to product',
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

            $productIds = [];
            foreach ($products as $product) {
                $productId = (int) $product->getId();
                if ($productId > 0) {
                    $productIds[] = $productId;
                }
            }

            if (empty($productIds)) {
                return $result;
            }

            $stockItemsMap = $this->batchLoadStockItems($productIds);

            foreach ($products as $product) {
                $productId = (int) $product->getId();
                $extensionAttributes = $product->getExtensionAttributes();
                if ($extensionAttributes === null) {
                    $extensionAttributes = $this->extensionFactory->create();
                }

                if ($extensionAttributes->getStockItem() !== null) {
                    continue;
                }

                if (isset($stockItemsMap[$productId])) {
                    $extensionAttributes->setStockItem($stockItemsMap[$productId]);
                    $product->setExtensionAttributes($extensionAttributes);
                }
            }
        } catch (\Throwable $e) {
            $this->logger->error(
                'TNW_Idealdata: Failed to batch-load stock items for product list',
                ['exception' => $e->getMessage()]
            );
        }

        return $result;
    }

    private function attachStockItemToProduct(ProductInterface $product): void
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

        $stockItem = $this->stockRegistry->getStockItem($productId);
        $extensionAttributes->setStockItem($stockItem);
        $product->setExtensionAttributes($extensionAttributes);
    }

    /**
     * @param int[] $productIds
     * @return StockItemInterface[]
     */
    private function batchLoadStockItems(array $productIds): array
    {
        $collection = $this->stockItemCollectionFactory->create();
        $collection->addFieldToFilter('product_id', ['in' => $productIds]);

        $map = [];
        foreach ($collection as $stockItem) {
            $map[(int) $stockItem->getProductId()] = $stockItem;
        }

        return $map;
    }
}
