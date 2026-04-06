<?php

declare(strict_types=1);

namespace TNW\Idealdata\Model;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SearchResultsFactory;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\LocalizedException;
use Psr\Log\LoggerInterface;
use TNW\Idealdata\Api\AbandonedCartRepositoryInterface;
use TNW\Idealdata\Api\Data\AbandonedCartSearchResultsInterface;
use TNW\Idealdata\Api\Data\AbandonedCartSearchResultsInterfaceFactory;
use TNW\Idealdata\Model\Data\AbandonedCart;

class AbandonedCartRepository implements AbandonedCartRepositoryInterface
{
    private const MAX_PAGE_SIZE = 500;

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly AbandonedCartSearchResultsInterfaceFactory $searchResultsFactory,
        private readonly LoggerInterface $logger
    ) {
    }

    public function getList(
        int $inactivityThresholdMinutes,
        ?string $updatedAtFrom = null,
        int $pageSize = 100,
        int $currentPage = 1
    ): AbandonedCartSearchResultsInterface {
        if ($inactivityThresholdMinutes < 1) {
            throw new InputException(
                __('inactivity_threshold_minutes must be a positive integer.')
            );
        }

        if ($updatedAtFrom !== null && !$this->isValidDateTime($updatedAtFrom)) {
            throw new InputException(
                __('updated_at_from must be a valid date-time string (e.g. 2026-04-06 10:00:00).')
            );
        }

        $pageSize = min(max($pageSize, 1), self::MAX_PAGE_SIZE);
        $currentPage = max($currentPage, 1);

        try {
            $connection = $this->resourceConnection->getConnection();
            $quoteTable = $this->resourceConnection->getTableName('quote');
            $quoteItemTable = $this->resourceConnection->getTableName('quote_item');
            $customerTable = $this->resourceConnection->getTableName('customer_entity');

            // Build count query
            $countSelect = $connection->select()
                ->from(['q' => $quoteTable], [new \Zend_Db_Expr('COUNT(DISTINCT q.entity_id)')])
                ->where('q.is_active = 1')
                ->where('q.items_count > 0')
                ->where(
                    'q.updated_at <= DATE_SUB(NOW(), INTERVAL ? MINUTE)',
                    $inactivityThresholdMinutes
                );

            if ($updatedAtFrom !== null) {
                $countSelect->where('q.updated_at >= ?', $updatedAtFrom);
            }

            $totalCount = (int) $connection->fetchOne($countSelect);
            $totalPages = $totalCount > 0 ? (int) ceil($totalCount / $pageSize) : 0;

            // Build main query for quote IDs with pagination
            $quoteSelect = $connection->select()
                ->from(['q' => $quoteTable], ['entity_id'])
                ->where('q.is_active = 1')
                ->where('q.items_count > 0')
                ->where(
                    'q.updated_at <= DATE_SUB(NOW(), INTERVAL ? MINUTE)',
                    $inactivityThresholdMinutes
                )
                ->order('q.updated_at DESC')
                ->limitPage($currentPage, $pageSize);

            if ($updatedAtFrom !== null) {
                $quoteSelect->where('q.updated_at >= ?', $updatedAtFrom);
            }

            $quoteIds = $connection->fetchCol($quoteSelect);

            $items = [];

            if (!empty($quoteIds)) {
                // Load quote data
                $quoteDataSelect = $connection->select()
                    ->from(['q' => $quoteTable])
                    ->where('q.entity_id IN (?)', $quoteIds)
                    ->order('q.updated_at DESC');
                $quotesData = $connection->fetchAll($quoteDataSelect);

                // Load quote items (only parent items)
                $quoteItemSelect = $connection->select()
                    ->from(['qi' => $quoteItemTable])
                    ->where('qi.quote_id IN (?)', $quoteIds)
                    ->where('qi.parent_item_id IS NULL');
                $quoteItemsData = $connection->fetchAll($quoteItemSelect);

                // Group items by quote_id
                $itemsByQuoteId = [];
                foreach ($quoteItemsData as $itemRow) {
                    $itemsByQuoteId[(int) $itemRow['quote_id']][] = $itemRow;
                }

                // Load customer data
                $customerIds = array_filter(
                    array_unique(array_column($quotesData, 'customer_id')),
                    fn($id) => !empty($id)
                );

                $customersMap = [];
                if (!empty($customerIds)) {
                    $customerSelect = $connection->select()
                        ->from(['ce' => $customerTable])
                        ->where('ce.entity_id IN (?)', $customerIds);
                    $customersData = $connection->fetchAll($customerSelect);
                    foreach ($customersData as $customerRow) {
                        $customersMap[(int) $customerRow['entity_id']] = $customerRow;
                    }
                }

                // Assemble results
                foreach ($quotesData as $quoteRow) {
                    $quoteId = (int) $quoteRow['entity_id'];
                    $customerId = !empty($quoteRow['customer_id']) ? (int) $quoteRow['customer_id'] : null;

                    $cart = [
                        'id'                 => (string) $quoteId,
                        'is_active'          => (bool) $quoteRow['is_active'],
                        'is_guest'           => !$customerId,
                        'customer_id'        => $customerId ? (string) $customerId : null,
                        'customer_email'     => $quoteRow['customer_email'] ?? null,
                        'base_subtotal'      => (float) ($quoteRow['base_subtotal'] ?? 0),
                        'base_currency_code' => $quoteRow['base_currency_code'] ?? null,
                        'items_count'        => (int) ($quoteRow['items_count'] ?? 0),
                        'created_at'         => $quoteRow['created_at'] ?? null,
                        'updated_at'         => $quoteRow['updated_at'] ?? null,
                    ];

                    $customer = null;
                    if ($customerId && isset($customersMap[$customerId])) {
                        $c = $customersMap[$customerId];
                        $customer = [
                            'id'        => (string) $customerId,
                            'firstname' => $c['firstname'] ?? null,
                            'lastname'  => $c['lastname'] ?? null,
                            'email'     => $c['email'] ?? null,
                        ];
                    }

                    $cartItems = [];
                    foreach ($itemsByQuoteId[$quoteId] ?? [] as $itemRow) {
                        $cartItems[] = [
                            'item_id'      => (string) ($itemRow['item_id'] ?? ''),
                            'product_id'   => (string) ($itemRow['product_id'] ?? ''),
                            'sku'          => $itemRow['sku'] ?? '',
                            'name'         => $itemRow['name'] ?? '',
                            'qty'          => (float) ($itemRow['qty'] ?? 0),
                            'price'        => (float) ($itemRow['price'] ?? 0),
                            'row_total'    => (float) ($itemRow['row_total'] ?? 0),
                            'product_type' => $itemRow['product_type'] ?? '',
                        ];
                    }

                    $items[] = new AbandonedCart([
                        'cart'     => $cart,
                        'customer' => $customer,
                        'items'    => $cartItems,
                    ]);
                }
            }

            /** @var AbandonedCartSearchResultsInterface $searchResults */
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
                'TNW_Idealdata: Failed to load abandoned carts',
                ['exception' => $e->getMessage()]
            );
            throw new LocalizedException(
                __('An error occurred while loading abandoned carts.'),
                $e
            );
        }
    }

    private function isValidDateTime(string $value): bool
    {
        $parsed = date_create($value);
        return $parsed !== false;
    }
}
