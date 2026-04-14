<?php

declare(strict_types=1);

namespace TNW\Idealdata\Plugin\Cart;

use Magento\Framework\App\State;
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
        private readonly LoggerInterface $logger,
        private readonly State $appState
    ) {
    }

    /**
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterGet(
        CartRepositoryInterface $subject,
        CartInterface $result
    ): CartInterface {
        // Only operate in REST/SOAP API contexts. In admin/frontend flows
        // Magento relies on its native quote-item loading and any interference
        // (batch-loading items, setItems, setData) can break operations like
        // "Create Order → Move from cart" where product_id references are lost.
        if (!$this->isApiContext()) {
            return $result;
        }

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
        if (!$this->isApiContext()) {
            return $result;
        }

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

    private function isApiContext(): bool
    {
        try {
            $areaCode = $this->appState->getAreaCode();
            return in_array($areaCode, ['webapi_rest', 'webapi_soap'], true);
        } catch (\Throwable $e) {
            // Area code not set — treat as non-API context (safer default)
            return false;
        }
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
            if (empty($items) && (int) $cart->getItemsCount() > 0) {
                $quoteIdsToLoad[] = (int) $cart->getId();
            }
        }

        if (empty($quoteIdsToLoad)) {
            return;
        }

        $itemsMap = $this->batchLoadQuoteItems($quoteIdsToLoad);

        foreach ($carts as $cart) {
            $cartId = (int) $cart->getId();
            if (isset($itemsMap[$cartId])) {
                $items = $itemsMap[$cartId];
                $cart->setItems($items);
                // The REST serializer reads items via getData('items'), not the
                // internal $_items property. Set the data key explicitly so
                // inactive carts' items appear in the REST response.
                $cart->setData('items', $items);
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
                    $extensionAttributes->setTnwProductId((int) $item->getProductId());
                }

                $extensionAttributes->setTnwParentItemId(
                    $item->getParentItemId() ? (int) $item->getParentItemId() : null
                );
                $extensionAttributes->setTnwProductType($item->getProductType() ?? '');

                if (method_exists($item, 'getRowTotal')) {
                    $extensionAttributes->setTnwRowTotal((float) $item->getRowTotal());
                }

                if (method_exists($item, 'getRowTotalWithDiscount')) {
                    $extensionAttributes->setTnwRowTotalWithDiscount(
                        (float) $item->getRowTotalWithDiscount()
                    );
                } elseif (method_exists($item, 'getData')) {
                    $extensionAttributes->setTnwRowTotalWithDiscount(
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
