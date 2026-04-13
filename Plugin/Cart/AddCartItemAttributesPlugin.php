<?php

declare(strict_types=1);

namespace TNW\Idealdata\Plugin\Cart;

use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Api\Data\CartItemExtensionFactory;
use Magento\Quote\Api\Data\CartSearchResultsInterface;
use Magento\Quote\Model\ResourceModel\Quote\Item\CollectionFactory as QuoteItemCollectionFactory;
use Psr\Log\LoggerInterface;

class AddCartItemAttributesPlugin
{
    public function __construct(
        private readonly CartItemExtensionFactory $itemExtensionFactory,
        private readonly QuoteItemCollectionFactory $itemCollectionFactory,
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
            $this->ensureItemsLoaded([$result]);
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
            $carts = $result->getItems();
            if (!empty($carts)) {
                $this->ensureItemsLoaded($carts);
                foreach ($carts as $cart) {
                    $this->attachItemAttributes($cart);
                }
            }
        } catch (\Throwable $e) {
            $this->logger->error(
                'TNW_Idealdata: Failed to attach cart item attributes to list',
                ['exception' => $e->getMessage()]
            );
        }

        return $result;
    }

    /**
     * Magento's getList() does not load quote items. Batch-load them
     * in 1 SQL query for all carts that have empty items.
     *
     * @param CartInterface[] $carts
     */
    private function ensureItemsLoaded(array $carts): void
    {
        $quoteIdsToLoad = [];
        foreach ($carts as $cart) {
            $items = $cart->getItems();
            $this->logger->info(sprintf(
                'TNW_Idealdata DEBUG ensureItemsLoaded: cart_id=%d items_count=%d getItems_empty=%s getItems_type=%s',
                (int) $cart->getId(),
                (int) $cart->getItemsCount(),
                empty($items) ? 'true' : 'false',
                is_array($items) ? 'array(' . count($items) . ')' : gettype($items)
            ));
            if (empty($items) && (int) $cart->getItemsCount() > 0) {
                $quoteIdsToLoad[] = (int) $cart->getId();
            }
        }

        $this->logger->info(sprintf(
            'TNW_Idealdata DEBUG ensureItemsLoaded: quoteIdsToLoad=[%s]',
            implode(',', $quoteIdsToLoad)
        ));

        if (empty($quoteIdsToLoad)) {
            return;
        }

        $itemsMap = $this->batchLoadQuoteItems($quoteIdsToLoad);

        foreach ($carts as $cart) {
            $cartId = (int) $cart->getId();
            if (isset($itemsMap[$cartId])) {
                $items = $itemsMap[$cartId];
                $cart->setItems($items);

                // Also set via setData to ensure it persists for REST serialization
                $cart->setData('items', $items);

                // Verify it persisted by re-reading getItems()
                $verifyItems = $cart->getItems();
                $this->logger->info(sprintf(
                    'TNW_Idealdata DEBUG setItems: cart_id=%d set_count=%d after_getItems_count=%s',
                    $cartId,
                    count($items),
                    is_array($verifyItems) ? count($verifyItems) : 'not-array:' . gettype($verifyItems)
                ));
            }
        }
    }

    /**
     * 1 SQL query to load all quote items for multiple carts.
     *
     * @param int[] $quoteIds
     * @return array<int, \Magento\Quote\Api\Data\CartItemInterface[]>
     */
    private function batchLoadQuoteItems(array $quoteIds): array
    {
        try {
            $collection = $this->itemCollectionFactory->create();
            $collection->addFieldToFilter('quote_id', ['in' => $quoteIds]);

            $map = [];
            foreach ($collection as $item) {
                $map[(int) $item->getQuoteId()][] = $item;
            }

            return $map;
        } catch (\Throwable $e) {
            $this->logger->debug(
                'TNW_Idealdata: Could not batch-load quote items',
                ['exception' => $e->getMessage()]
            );
            return [];
        }
    }

    private function attachItemAttributes(CartInterface $cart): void
    {
        $items = $cart->getItems();
        if (empty($items)) {
            return;
        }

        foreach ($items as $item) {
            try {
                $extensionAttributes = $item->getExtensionAttributes();
                if ($extensionAttributes === null) {
                    $extensionAttributes = $this->itemExtensionFactory->create();
                }

                if (method_exists($item, 'getProductId')) {
                    $extensionAttributes->setProductId((int) $item->getProductId());
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
            } catch (\Throwable $e) {
                $this->logger->debug(
                    'TNW_Idealdata: Could not attach attributes to cart item',
                    ['exception' => $e->getMessage()]
                );
            }
        }
    }
}
