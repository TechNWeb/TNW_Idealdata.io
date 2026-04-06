<?php

declare(strict_types=1);

namespace TNW\Idealdata\Plugin\Cart;

use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Api\Data\CartItemExtensionFactory;
use Magento\Quote\Api\Data\CartSearchResultsInterface;
use Psr\Log\LoggerInterface;

class AddCartItemAttributesPlugin
{
    public function __construct(
        private readonly CartItemExtensionFactory $itemExtensionFactory,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterGet(
        CartRepositoryInterface $subject,
        CartInterface $result
    ): CartInterface {
        try {
            $this->attachItemAttributes($result);
        } catch (\Throwable $e) {
            $this->logger->error(
                'TNW_Idealdata: Failed to attach cart item attributes',
                ['exception' => $e->getMessage()]
            );
        }

        return $result;
    }

    /**
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterGetList(
        CartRepositoryInterface $subject,
        CartSearchResultsInterface $result
    ): CartSearchResultsInterface {
        try {
            foreach ($result->getItems() as $cart) {
                $this->attachItemAttributes($cart);
            }
        } catch (\Throwable $e) {
            $this->logger->error(
                'TNW_Idealdata: Failed to attach cart item attributes to list',
                ['exception' => $e->getMessage()]
            );
        }

        return $result;
    }

    private function attachItemAttributes(CartInterface $cart): void
    {
        $items = $cart->getItems();
        if (empty($items)) {
            return;
        }

        foreach ($items as $item) {
            $extensionAttributes = $item->getExtensionAttributes();
            if ($extensionAttributes === null) {
                $extensionAttributes = $this->itemExtensionFactory->create();
            }

            $extensionAttributes->setParentItemId(
                $item->getParentItemId() ? (int) $item->getParentItemId() : null
            );
            $extensionAttributes->setProductType($item->getProductType() ?? '');

            if (method_exists($item, 'getRowTotal')) {
                $extensionAttributes->setRowTotal((float) $item->getRowTotal());
            }

            if (method_exists($item, 'getRowTotalWithDiscount')) {
                $extensionAttributes->setRowTotalWithDiscount(
                    (float) $item->getRowTotalWithDiscount()
                );
            } elseif (method_exists($item, 'getData')) {
                $extensionAttributes->setRowTotalWithDiscount(
                    (float) $item->getData('row_total_with_discount')
                );
            }

            $item->setExtensionAttributes($extensionAttributes);
        }
    }
}
