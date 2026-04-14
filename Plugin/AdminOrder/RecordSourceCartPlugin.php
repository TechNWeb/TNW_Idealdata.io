<?php

declare(strict_types=1);

namespace TNW\Idealdata\Plugin\AdminOrder;

use Magento\Sales\Model\AdminOrder\Create;
use Psr\Log\LoggerInterface;

/**
 * When admin creates an order with "Move items from customer's cart", Magento
 * creates a NEW quote (Cart B), moves items from the customer's persistent cart
 * (Cart A), and converts Cart B to the order. This plugin stamps Cart B with:
 *
 *  - tnw_quote_source = 'admin_split_from_cart' | 'admin_manual'
 *  - tnw_parent_quote_id = Cart A id (when applicable)
 *  - tnw_parent_quote_created_at = Cart A created_at (when applicable)
 *
 * It also stamps Cart A with tnw_child_quote_id pointing at Cart B, so
 * IdealData can efficiently detect "drained" Cart A's in the lifecycle
 * processor and remove them.
 *
 * IdealData uses these fields to restore the Cart A → Cart B → Order lineage
 * and to compute the correct cart status / time-to-convert.
 */
class RecordSourceCartPlugin
{
    public function __construct(
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Runs right before Magento converts the admin-built quote into an order.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function beforeCreateOrder(Create $subject): void
    {
        try {
            $orderQuote = $subject->getQuote();
            if (!$orderQuote || $orderQuote->getData('tnw_quote_source')) {
                return;
            }

            $customerCart = $subject->getCustomerCart();
            if ($customerCart
                && $customerCart->getId()
                && (int) $customerCart->getId() !== (int) $orderQuote->getId()
            ) {
                // Stamp Cart B with parent info
                $orderQuote->setData('tnw_quote_source', 'admin_split_from_cart');
                $orderQuote->setData('tnw_parent_quote_id', (int) $customerCart->getId());
                if ($customerCart->getCreatedAt()) {
                    $orderQuote->setData('tnw_parent_quote_created_at', $customerCart->getCreatedAt());
                }

                // Stamp Cart A with child info (for lifecycle cleanup detection)
                $customerCart->setData('tnw_child_quote_id', (int) $orderQuote->getId());
                $customerCart->save();
            } else {
                $orderQuote->setData('tnw_quote_source', 'admin_manual');
            }
        } catch (\Throwable $e) {
            $this->logger->warning(
                'TNW_Idealdata: Failed to record admin cart source',
                ['exception' => $e->getMessage()]
            );
        }
    }
}
