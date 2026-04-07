<?php

declare(strict_types=1);

namespace TNW\Idealdata\Plugin\Product;

use Magento\Catalog\Api\Data\ProductExtensionFactory;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\Data\ProductSearchResultsInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Psr\Log\LoggerInterface;

class StockItemPlugin
{
    public function __construct(
        private readonly StockRegistryInterface $stockRegistry,
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
            foreach ($result->getItems() as $product) {
                $this->attachStockItemToProduct($product);
            }
        } catch (\Throwable $e) {
            $this->logger->error(
                'TNW_Idealdata: Failed to attach stock items to product list',
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
}
