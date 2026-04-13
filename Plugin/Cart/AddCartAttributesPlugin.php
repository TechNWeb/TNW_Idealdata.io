<?php

declare(strict_types=1);

namespace TNW\Idealdata\Plugin\Cart;

use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\Data\CartExtensionFactory;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Api\Data\CartSearchResultsInterface;
use Psr\Log\LoggerInterface;

class AddCartAttributesPlugin
{
    public function __construct(
        private readonly CartExtensionFactory $extensionFactory,
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
            $this->attachAttributes($result);
        } catch (\Throwable $e) {
            $this->logger->error(
                'TNW_Idealdata: Failed to attach cart attributes',
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
                $this->attachAttributes($cart);
            }
        } catch (\Throwable $e) {
            $this->logger->error(
                'TNW_Idealdata: Failed to attach cart attributes to list',
                ['exception' => $e->getMessage()]
            );
        }

        return $result;
    }

    private function attachAttributes(CartInterface $cart): void
    {
        $extensionAttributes = $cart->getExtensionAttributes();
        if ($extensionAttributes === null) {
            $extensionAttributes = $this->extensionFactory->create();
        }

        // coupon_code comes from the quote object itself
        if (method_exists($cart, 'getCouponCode')) {
            $extensionAttributes->setCouponCode($cart->getCouponCode());
        }

        // applied_rule_ids from quote data
        if (method_exists($cart, 'getData')) {
            $appliedRuleIds = $cart->getData('applied_rule_ids');
            if ($appliedRuleIds !== null) {
                $extensionAttributes->setAppliedRuleIds((string) $appliedRuleIds);
            }
        }

        // customer_is_guest
        if (method_exists($cart, 'getCustomerIsGuest')) {
            $extensionAttributes->setCustomerIsGuest((int) $cart->getCustomerIsGuest());
        } elseif (method_exists($cart, 'getData')) {
            $extensionAttributes->setCustomerIsGuest((int) $cart->getData('customer_is_guest'));
        }

        // Cart parent tracking (populated for admin "move items from cart" flow)
        if (method_exists($cart, 'getData')) {
            $parentQuoteId = $cart->getData('tnw_parent_quote_id');
            if ($parentQuoteId !== null && $parentQuoteId !== '') {
                $extensionAttributes->setTnwParentQuoteId((int) $parentQuoteId);
            }

            $parentQuoteCreatedAt = $cart->getData('tnw_parent_quote_created_at');
            if ($parentQuoteCreatedAt !== null && $parentQuoteCreatedAt !== '') {
                $extensionAttributes->setTnwParentQuoteCreatedAt((string) $parentQuoteCreatedAt);
            }

            $quoteSource = $cart->getData('tnw_quote_source');
            if ($quoteSource !== null && $quoteSource !== '') {
                $extensionAttributes->setTnwQuoteSource((string) $quoteSource);
            }
        }

        $cart->setExtensionAttributes($extensionAttributes);
    }
}
