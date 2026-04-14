<?php

declare(strict_types=1);

namespace TNW\Idealdata\Plugin\AdminOrder;

use Magento\Framework\App\ResourceConnection;
use Magento\Sales\Model\AdminOrder\Create;
use Psr\Log\LoggerInterface;

/**
 * Records the origin of a quote created during admin order creation.
 *
 * Three scenarios:
 *
 * 1. "Move items from customer's cart" — admin explicitly transfers items
 *    from the customer's persistent cart (Cart A) into a new quote (Cart B).
 *    Detected via $subject->getMoveQuoteItems(). Cart B gets:
 *      tnw_quote_source       = 'admin_split_from_cart'
 *      tnw_parent_quote_id    = Cart A entity_id
 *      tnw_parent_quote_created_at = Cart A created_at
 *    Cart A gets tnw_child_quote_id = Cart B entity_id (via raw SQL).
 *
 * 2. Reorder — admin creates order from an existing order's data.
 *    Detected via $orderQuote->getOrigOrderId(). Cart B gets:
 *      tnw_quote_source = 'reorder'
 *    Cart A is NOT touched.
 *
 * 3. Manual order — admin creates order from scratch for a customer.
 *    Cart B gets:
 *      tnw_quote_source = 'admin_manual'
 *    Cart A is NOT touched.
 *
 * Cart A is updated via raw SQL (not $quote->save()) to avoid triggering
 * collectTotals / observers that interfere with the Cart B → Order conversion.
 */
class RecordSourceCartPlugin
{
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function beforeCreateOrder(Create $subject): void
    {
        try {
            $orderQuote = $subject->getQuote();
            if (!$orderQuote || $orderQuote->getData('tnw_quote_source')) {
                return;
            }

            // Scenario 1: items explicitly moved from customer's cart
            if ($subject->getMoveQuoteItems()) {
                $customerCart = $subject->getCustomerCart();
                if ($customerCart
                    && $customerCart->getId()
                    && (int) $customerCart->getId() !== (int) $orderQuote->getId()
                ) {
                    $orderQuote->setData('tnw_quote_source', 'admin_split_from_cart');
                    $orderQuote->setData('tnw_parent_quote_id', (int) $customerCart->getId());
                    if ($customerCart->getCreatedAt()) {
                        $orderQuote->setData('tnw_parent_quote_created_at', $customerCart->getCreatedAt());
                    }

                    // Stamp Cart A with child reference (raw SQL to avoid side effects)
                    $connection = $this->resourceConnection->getConnection();
                    $connection->update(
                        $this->resourceConnection->getTableName('quote'),
                        ['tnw_child_quote_id' => (int) $orderQuote->getId()],
                        ['entity_id = ?' => (int) $customerCart->getId()]
                    );
                    return;
                }
            }

            // Scenario 2: reorder
            if ($orderQuote->getOrigOrderId()) {
                $orderQuote->setData('tnw_quote_source', 'reorder');
                return;
            }

            // Scenario 3: manual admin order
            $orderQuote->setData('tnw_quote_source', 'admin_manual');
        } catch (\Throwable $e) {
            $this->logger->warning(
                'TNW_Idealdata: Failed to record admin cart source',
                ['exception' => $e->getMessage()]
            );
        }
    }
}
