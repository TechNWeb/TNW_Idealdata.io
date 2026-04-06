<?php

declare(strict_types=1);

namespace TNW\Idealdata\Plugin\Product;

use Magento\Catalog\Api\Data\ProductExtensionFactory;
use Magento\Catalog\Api\Data\ProductExtensionInterface;
use Magento\Catalog\Model\Product;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Psr\Log\LoggerInterface;

class AddManageStockPlugin
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
    public function afterGetExtensionAttributes(
        Product $subject,
        ?ProductExtensionInterface $result
    ): ProductExtensionInterface {
        try {
            if ($result === null) {
                $result = $this->extensionFactory->create();
            }

            $productId = (int) $subject->getId();
            if ($productId <= 0) {
                return $result;
            }

            if ($result->getManageStock() !== null) {
                return $result;
            }

            $stockItem = $this->stockRegistry->getStockItem($productId);
            $result->setManageStock((int) $stockItem->getManageStock());
        } catch (\Throwable $e) {
            $this->logger->error(
                'TNW_Idealdata: Failed to load manage_stock for product',
                ['exception' => $e->getMessage()]
            );
        }

        return $result;
    }
}
