<?php

declare(strict_types=1);

namespace TNW\Idealdata\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\LocalizedException;
use Psr\Log\LoggerInterface;
use TNW\Idealdata\Api\Data\FailedTransactionSearchResultsInterface;
use TNW\Idealdata\Api\Data\FailedTransactionSearchResultsInterfaceFactory;
use TNW\Idealdata\Api\FailedTransactionRepositoryInterface;
use TNW\Idealdata\Model\Data\CartItemSnapshot;
use TNW\Idealdata\Model\Data\CartSnapshot;
use TNW\Idealdata\Model\Data\CustomerSnapshot;
use TNW\Idealdata\Model\Data\FailedTransactionResult;
use TNW\Idealdata\Model\Data\TransactionData;

class FailedTransactionRepository implements FailedTransactionRepositoryInterface
{
    private const MAX_PAGE_SIZE = 500;

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly FailedTransactionSearchResultsInterfaceFactory $searchResultsFactory,
        private readonly LoggerInterface $logger
    ) {
    }

    public function getList(
        string $updatedAtFrom,
        ?string $updatedAtTo = null,
        ?string $status = null,
        ?int $storeId = null,
        ?bool $isGuest = null,
        int $pageSize = 100,
        int $currentPage = 1
    ): FailedTransactionSearchResultsInterface {
        if (!$this->isValidDateTime($updatedAtFrom)) {
            throw new InputException(
                __('updated_at_from must be a valid date-time string (e.g. 2026-04-06 10:00:00).')
            );
        }

        if ($updatedAtTo !== null && !$this->isValidDateTime($updatedAtTo)) {
            throw new InputException(
                __('updated_at_to must be a valid date-time string (e.g. 2026-04-06 12:00:00).')
            );
        }

        $validStatuses = ['declined', 'failed', 'error'];
        if ($status !== null && !in_array($status, $validStatuses, true)) {
            throw new InputException(
                __('status must be one of: declined, failed, error.')
            );
        }

        $pageSize = min(max($pageSize, 1), self::MAX_PAGE_SIZE);
        $currentPage = max($currentPage, 1);

        try {
            $connection = $this->resourceConnection->getConnection();
            $txnTable = $this->resourceConnection->getTableName('tnw_quote_payment_transaction');
            $quoteTable = $this->resourceConnection->getTableName('quote');
            $quoteItemTable = $this->resourceConnection->getTableName('quote_item');
            $customerTable = $this->resourceConnection->getTableName('customer_entity');

            // Count query
            $countSelect = $connection->select()
                ->from(['t' => $txnTable], [new \Zend_Db_Expr('COUNT(*)')])
                ->where('t.updated_at >= ?', $updatedAtFrom);

            if ($updatedAtTo !== null) {
                $countSelect->where('t.updated_at <= ?', $updatedAtTo);
            }
            if ($status !== null) {
                $countSelect->where('t.status = ?', $status);
            }
            if ($storeId !== null) {
                $countSelect->where('t.store_id = ?', $storeId);
            }
            if ($isGuest !== null) {
                $countSelect->where('t.is_guest = ?', $isGuest ? 1 : 0);
            }

            $totalCount = (int) $connection->fetchOne($countSelect);
            $totalPages = $totalCount > 0 ? (int) ceil($totalCount / $pageSize) : 0;

            // Main transaction query
            $txnSelect = $connection->select()
                ->from(['t' => $txnTable])
                ->where('t.updated_at >= ?', $updatedAtFrom)
                ->order('t.updated_at DESC')
                ->limitPage($currentPage, $pageSize);

            if ($updatedAtTo !== null) {
                $txnSelect->where('t.updated_at <= ?', $updatedAtTo);
            }
            if ($status !== null) {
                $txnSelect->where('t.status = ?', $status);
            }
            if ($storeId !== null) {
                $txnSelect->where('t.store_id = ?', $storeId);
            }
            if ($isGuest !== null) {
                $txnSelect->where('t.is_guest = ?', $isGuest ? 1 : 0);
            }

            $txnRows = $connection->fetchAll($txnSelect);

            $items = [];

            if (!empty($txnRows)) {
                // Collect quote IDs and customer IDs
                $quoteIds = array_unique(array_column($txnRows, 'quote_id'));
                $customerIds = array_filter(
                    array_unique(array_column($txnRows, 'customer_id')),
                    fn($id) => !empty($id)
                );

                // Batch-load quotes
                $quotesMap = [];
                if (!empty($quoteIds)) {
                    $quoteSelect = $connection->select()
                        ->from(['q' => $quoteTable])
                        ->where('q.entity_id IN (?)', $quoteIds);
                    foreach ($connection->fetchAll($quoteSelect) as $row) {
                        $quotesMap[(int) $row['entity_id']] = $row;
                    }
                }

                // Batch-load quote items (parent items only)
                $quoteItemsMap = [];
                if (!empty($quoteIds)) {
                    $itemSelect = $connection->select()
                        ->from(['qi' => $quoteItemTable])
                        ->where('qi.quote_id IN (?)', $quoteIds)
                        ->where('qi.parent_item_id IS NULL');
                    foreach ($connection->fetchAll($itemSelect) as $row) {
                        $quoteItemsMap[(int) $row['quote_id']][] = $row;
                    }
                }

                // Batch-load customers
                $customersMap = [];
                if (!empty($customerIds)) {
                    $customerSelect = $connection->select()
                        ->from(['ce' => $customerTable])
                        ->where('ce.entity_id IN (?)', $customerIds);
                    foreach ($connection->fetchAll($customerSelect) as $row) {
                        $customersMap[(int) $row['entity_id']] = $row;
                    }
                }

                // Assemble results
                foreach ($txnRows as $txnRow) {
                    $quoteId = (int) $txnRow['quote_id'];
                    $customerId = !empty($txnRow['customer_id']) ? (int) $txnRow['customer_id'] : null;

                    $transaction = new TransactionData([
                        'entity_id'        => (int) $txnRow['entity_id'],
                        'quote_id'         => (string) $txnRow['quote_id'],
                        'transaction_id'   => $txnRow['transaction_id'],
                        'status'           => $txnRow['status'],
                        'decline_code'     => $txnRow['decline_code'],
                        'decline_reason'   => $txnRow['decline_reason'],
                        'decline_category' => $txnRow['decline_category'],
                        'payment_method'   => $txnRow['payment_method'],
                        'card_type'        => $txnRow['card_type'],
                        'card_last_four'   => $txnRow['card_last_four'],
                        'amount'           => $txnRow['amount'] !== null ? (float) $txnRow['amount'] : null,
                        'currency'         => $txnRow['currency'],
                        'attempt_number'   => (int) $txnRow['attempt_number'],
                        'ip_address'       => $txnRow['ip_address'],
                        'created_at'       => $txnRow['created_at'],
                        'updated_at'       => $txnRow['updated_at'],
                    ]);

                    $cart = null;
                    if (isset($quotesMap[$quoteId])) {
                        $q = $quotesMap[$quoteId];
                        $cart = new CartSnapshot([
                            'id'                 => (string) $quoteId,
                            'is_active'          => (bool) ($q['is_active'] ?? false),
                            'is_guest'           => !$customerId,
                            'customer_id'        => $customerId ? (string) $customerId : null,
                            'customer_email'     => $q['customer_email'] ?? null,
                            'base_subtotal'      => (float) ($q['base_subtotal'] ?? 0),
                            'base_currency_code' => $q['base_currency_code'] ?? null,
                            'items_count'        => (int) ($q['items_count'] ?? 0),
                            'created_at'         => $q['created_at'] ?? null,
                            'updated_at'         => $q['updated_at'] ?? null,
                        ]);
                    }

                    $customer = null;
                    if ($customerId && isset($customersMap[$customerId])) {
                        $c = $customersMap[$customerId];
                        $customer = new CustomerSnapshot([
                            'id'        => (string) $customerId,
                            'firstname' => $c['firstname'] ?? null,
                            'lastname'  => $c['lastname'] ?? null,
                            'email'     => $c['email'] ?? null,
                        ]);
                    }

                    $cartItems = [];
                    foreach ($quoteItemsMap[$quoteId] ?? [] as $itemRow) {
                        $cartItems[] = new CartItemSnapshot([
                            'item_id'      => (string) ($itemRow['item_id'] ?? ''),
                            'product_id'   => (string) ($itemRow['product_id'] ?? ''),
                            'sku'          => $itemRow['sku'] ?? '',
                            'name'         => $itemRow['name'] ?? '',
                            'qty'          => (float) ($itemRow['qty'] ?? 0),
                            'price'        => (float) ($itemRow['price'] ?? 0),
                            'row_total'    => (float) ($itemRow['row_total'] ?? 0),
                            'product_type' => $itemRow['product_type'] ?? '',
                        ]);
                    }

                    $items[] = new FailedTransactionResult([
                        'transaction' => $transaction,
                        'cart'        => $cart,
                        'customer'    => $customer,
                        'items'       => $cartItems,
                    ]);
                }
            }

            /** @var FailedTransactionSearchResultsInterface $searchResults */
            $searchResults = $this->searchResultsFactory->create();
            $searchResults->setItems($items);
            $searchResults->setTotalCount($totalCount);
            $searchResults->setPageInfo([
                'page_size'    => $pageSize,
                'current_page' => $currentPage,
                'total_pages'  => $totalPages,
            ]);

            return $searchResults;
        } catch (InputException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->logger->error(
                'TNW_Idealdata: Failed to load failed transactions',
                ['exception' => $e->getMessage()]
            );
            throw new LocalizedException(
                __('An error occurred while loading failed transactions.'),
                $e
            );
        }
    }

    private function isValidDateTime(string $value): bool
    {
        return date_create($value) !== false;
    }
}
