<?php

declare(strict_types=1);

namespace TNW\Idealdata\Plugin\AdminOrder;

use Magento\Framework\App\ResourceConnection;
use Magento\Sales\Model\AdminOrder\Create;
use Psr\Log\LoggerInterface;

/**
 * Detects the origin of an admin-created order by comparing the customer's
 * active cart (Cart A) items_count before and after createOrder().
 *
 * Magento's admin order creation is multi-step: items are moved during
 * _processActionData() in an earlier request, and by the time createOrder()
 * runs, the _moveQuoteItems flag is lost. So we use a before/after snapshot
 * of Cart A's items_count to detect whether items were transferred.
 *
 * Uses aroundCreateOrder to:
 * 1. Snapshot Cart A items_count BEFORE
 * 2. Run original createOrder()
 * 3. Re-read Cart A items_count AFTER
 * 4. If decreased → admin_split_from_cart + parent/child links
 *    If origOrderId → reorder
 *    Otherwise → admin_manual
 *
 * All quote updates are via raw SQL to avoid side effects (collectTotals etc).
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
    public function aroundCreateOrder(Create $subject, callable $proceed)
    {
        // ── Snapshot Cart A state BEFORE createOrder ─────────────────────
        $orderQuote = $subject->getQuote();
        $customerCart = null;
        $cartAId = null;
        $cartAItemsBefore = 0;
        $cartACreatedAt = null;

        try {
            $customerCart = $subject->getCustomerCart();
            if ($customerCart
                && $customerCart->getId()
                && $orderQuote
                && (int) $customerCart->getId() !== (int) $orderQuote->getId()
            ) {
                $cartAId = (int) $customerCart->getId();
                $cartAItemsBefore = (int) $customerCart->getItemsCount();
                $cartACreatedAt = $customerCart->getCreatedAt();
            }
        } catch (\Throwable $e) {
            // Can't read customer cart — proceed without tracking
        }

        // ── Run original createOrder ─────────────────────────────────────
        $result = $proceed();

        // ── Determine source and stamp via raw SQL ───────────────────────
        try {
            if (!$orderQuote || !$orderQuote->getId()) {
                return $result;
            }

            $connection = $this->resourceConnection->getConnection();
            $tableName = $this->resourceConnection->getTableName('quote');
            $quoteId = (int) $orderQuote->getId();

            // Check if already set (e.g. by SetQuoteSourceObserver — shouldn't
            // happen since observer skips adminhtml, but be safe)
            $existingSource = $connection->fetchOne(
                "SELECT tnw_quote_source FROM {$tableName} WHERE entity_id = ?",
                [$quoteId]
            );
            if ($existingSource) {
                return $result;
            }

            // All raw SQL updates include updated_at bump to force a re-sync.
            // Use current time +1s to guarantee the value is AFTER whatever
            // createOrder() wrote (which just completed inside $proceed).
            $now = date('Y-m-d H:i:s', time() + 1);

            // Scenario 1: reorder
            if ($orderQuote->getOrigOrderId()) {
                $connection->update($tableName, [
                    'tnw_quote_source' => 'reorder',
                    'updated_at' => $now
                ], ['entity_id = ?' => $quoteId]);
                return $result;
            }

            // Scenario 2: items moved from customer cart
            if ($cartAId && $cartAItemsBefore > 0) {
                $cartAItemsAfter = (int) $connection->fetchOne(
                    "SELECT items_count FROM {$tableName} WHERE entity_id = ?",
                    [$cartAId]
                );

                if ($cartAItemsAfter < $cartAItemsBefore) {
                    // Cart A lost items → they were moved to Cart B
                    $connection->update($tableName, [
                        'tnw_quote_source' => 'admin_split_from_cart',
                        'tnw_parent_quote_id' => $cartAId,
                        'tnw_parent_quote_created_at' => $cartACreatedAt,
                        'updated_at' => $now
                    ], ['entity_id = ?' => $quoteId]);

                    $connection->update($tableName, [
                        'tnw_child_quote_id' => $quoteId,
                        'updated_at' => $now
                    ], ['entity_id = ?' => $cartAId]);

                    return $result;
                }
            }

            // Scenario 3: manual admin order (no items moved, no reorder)
            $connection->update($tableName, [
                'tnw_quote_source' => 'admin_manual',
                'updated_at' => $now
            ], ['entity_id = ?' => $quoteId]);
        } catch (\Throwable $e) {
            $this->logger->warning(
                'TNW_Idealdata: Failed to record admin cart source',
                ['exception' => $e->getMessage()]
            );
        }

        return $result;
    }
}
