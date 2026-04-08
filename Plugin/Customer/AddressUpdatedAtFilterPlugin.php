<?php

declare(strict_types=1);

namespace TNW\Idealdata\Plugin\Customer;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerSearchResultsInterface;
use Magento\Framework\Api\FilterBuilder;
use Magento\Framework\Api\Search\FilterGroupBuilder;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\App\ResourceConnection;
use Psr\Log\LoggerInterface;

/**
 * Intercepts the standard `updated_at` filter on GET /V1/customers/search and augments
 * the result with customers whose customer_address_entity.tnw_updated_at also matches
 * the same condition but customer_entity.updated_at does NOT.
 *
 * All pagination is done in SQL — no full ID arrays loaded into PHP memory.
 * Address-only customers are loaded via a single getList call (batch), not getById loop.
 *
 * total_count = native changed customers + address-only changed customers (deduplicated)
 * Pages: native customers first, then address-only customers fill remaining slots.
 */
class AddressUpdatedAtFilterPlugin
{
    private ?string $updatedAtValue = null;
    private ?string $updatedAtCondition = null;
    private int $pageSize = 0;
    private int $currentPage = 0;
    private bool $isInternalCall = false;

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly FilterBuilder $filterBuilder,
        private readonly FilterGroupBuilder $filterGroupBuilder,
        private readonly LoggerInterface $logger
    ) {
    }

    public function beforeGetList(
        CustomerRepositoryInterface $subject,
        SearchCriteriaInterface $searchCriteria
    ): array {
        if ($this->isInternalCall) {
            return [$searchCriteria];
        }

        try {
            $this->updatedAtValue = null;
            $this->updatedAtCondition = null;
            $this->pageSize = 0;
            $this->currentPage = 0;

            foreach ($searchCriteria->getFilterGroups() as $filterGroup) {
                foreach ($filterGroup->getFilters() as $filter) {
                    if ($filter->getField() === 'updated_at') {
                        $this->updatedAtValue = $filter->getValue();
                        $this->updatedAtCondition = $filter->getConditionType() ?: 'gteq';
                        $this->pageSize = (int) $searchCriteria->getPageSize() ?: 10;
                        $this->currentPage = (int) $searchCriteria->getCurrentPage() ?: 1;
                        break 2;
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->logger->error(
                'TNW_Idealdata: Failed to detect updated_at filter for address merge',
                ['exception' => $e->getMessage()]
            );
        }

        return [$searchCriteria];
    }

    /**
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterGetList(
        CustomerRepositoryInterface $subject,
        CustomerSearchResultsInterface $result
    ): CustomerSearchResultsInterface {
        if ($this->isInternalCall || $this->updatedAtValue === null) {
            return $result;
        }

        try {
            $updatedAtValue = $this->updatedAtValue;
            $condition = $this->updatedAtCondition ?? 'gteq';
            $pageSize = $this->pageSize;
            $currentPage = $this->currentPage;
            $this->updatedAtValue = null;
            $this->updatedAtCondition = null;

            $nativeTotal = $result->getTotalCount();
            $nativeItemCount = count($result->getItems());

            // 1 COUNT query: address-only changed customers
            $addressOnlyTotal = $this->getAddressOnlyChangedCount($updatedAtValue, $condition);

            if ($addressOnlyTotal === 0) {
                return $result;
            }

            // Update total_count (deduplicated)
            $result->setTotalCount($nativeTotal + $addressOnlyTotal);

            // If this page is full with native customers — done
            if ($nativeItemCount >= $pageSize) {
                return $result;
            }

            // Calculate slots and offset
            $slotsAvailable = $pageSize - $nativeItemCount;
            $nativePages = $nativeTotal > 0 ? (int) ceil($nativeTotal / $pageSize) : 0;
            $addressOffset = 0;

            if ($currentPage > $nativePages && $nativePages > 0) {
                $slotsAvailable = $pageSize;
                $addressOffset = ($currentPage - $nativePages - 1) * $pageSize;
                $result->setItems([]);
            } elseif ($currentPage > 1 && $nativePages === 0) {
                $addressOffset = ($currentPage - 1) * $pageSize;
                $slotsAvailable = $pageSize;
                $result->setItems([]);
            }

            // Exclude IDs already on this page
            $existingIds = [];
            foreach ($result->getItems() as $customer) {
                $existingIds[] = (int) $customer->getId();
            }

            // 1 SQL with LIMIT/OFFSET — get IDs for this page
            $idsForPage = $this->getAddressOnlyChangedIds(
                $updatedAtValue,
                $condition,
                $slotsAvailable,
                $addressOffset,
                $existingIds
            );

            if (empty($idsForPage)) {
                return $result;
            }

            // Load all customers in 1 batch getList call
            $addressCustomers = $this->loadCustomersByIds($subject, $idsForPage);

            $items = $result->getItems();
            foreach ($addressCustomers as $customer) {
                $items[] = $customer;
            }
            $result->setItems($items);
        } catch (\Throwable $e) {
            $this->logger->error(
                'TNW_Idealdata: Failed to merge address-changed customers',
                ['exception' => $e->getMessage()]
            );
        }

        return $result;
    }

    /**
     * Load customers by IDs using a single getList call.
     * Recursion guard prevents this plugin from firing on the internal call.
     *
     * @param int[] $ids
     * @return \Magento\Customer\Api\Data\CustomerInterface[]
     */
    private function loadCustomersByIds(CustomerRepositoryInterface $repository, array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        try {
            $filter = $this->filterBuilder
                ->setField('entity_id')
                ->setConditionType('in')
                ->setValue(implode(',', $ids))
                ->create();

            $filterGroup = $this->filterGroupBuilder
                ->addFilter($filter)
                ->create();

            $searchCriteria = $this->searchCriteriaBuilder
                ->setFilterGroups([$filterGroup])
                ->setPageSize(count($ids))
                ->setCurrentPage(1)
                ->create();

            $this->isInternalCall = true;
            try {
                $result = $repository->getList($searchCriteria);
                return $result->getItems();
            } finally {
                $this->isInternalCall = false;
            }
        } catch (\Throwable $e) {
            $this->isInternalCall = false;
            $this->logger->debug(
                'TNW_Idealdata: Could not batch-load address-changed customers',
                ['exception' => $e->getMessage()]
            );
            return [];
        }
    }

    /**
     * COUNT of customers whose address changed (tnw_updated_at) but
     * customer_entity.updated_at did NOT change. Deduplicated by design.
     */
    private function getAddressOnlyChangedCount(string $value, string $condition): int
    {
        $connection = $this->resourceConnection->getConnection();
        $sqlOp = $this->toSqlOp($condition);
        $inverseSqlOp = $this->toInverseSqlOp($condition);

        try {
            $addressTable = $this->resourceConnection->getTableName('customer_address_entity');
            $customerTable = $this->resourceConnection->getTableName('customer_entity');

            $select = $connection->select()
                ->from(
                    ['cae' => $addressTable],
                    [new \Zend_Db_Expr('COUNT(DISTINCT cae.parent_id)')]
                )
                ->join(
                    ['ce' => $customerTable],
                    'ce.entity_id = cae.parent_id',
                    []
                )
                ->where("cae.tnw_updated_at {$sqlOp} ?", $value)
                ->where("ce.updated_at {$inverseSqlOp} ?", $value);

            return (int) $connection->fetchOne($select);
        } catch (\Throwable $e) {
            $this->logger->debug(
                'TNW_Idealdata: Could not count address-only changed customers',
                ['exception' => $e->getMessage()]
            );
            return 0;
        }
    }

    /**
     * Paginated customer IDs via SQL LIMIT/OFFSET.
     * Only customers whose address changed but customer itself did NOT.
     *
     * @param int[] $excludeIds
     * @return int[]
     */
    private function getAddressOnlyChangedIds(
        string $value,
        string $condition,
        int $limit,
        int $offset,
        array $excludeIds
    ): array {
        $connection = $this->resourceConnection->getConnection();
        $sqlOp = $this->toSqlOp($condition);
        $inverseSqlOp = $this->toInverseSqlOp($condition);

        try {
            $addressTable = $this->resourceConnection->getTableName('customer_address_entity');
            $customerTable = $this->resourceConnection->getTableName('customer_entity');

            $select = $connection->select()
                ->distinct(true)
                ->from(['cae' => $addressTable], ['parent_id'])
                ->join(
                    ['ce' => $customerTable],
                    'ce.entity_id = cae.parent_id',
                    []
                )
                ->where("cae.tnw_updated_at {$sqlOp} ?", $value)
                ->where("ce.updated_at {$inverseSqlOp} ?", $value)
                ->order('cae.parent_id ASC')
                ->limit($limit, $offset);

            if (!empty($excludeIds)) {
                $select->where('cae.parent_id NOT IN (?)', $excludeIds);
            }

            return array_map('intval', $connection->fetchCol($select));
        } catch (\Throwable $e) {
            $this->logger->debug(
                'TNW_Idealdata: Could not query address-only changed customer IDs',
                ['exception' => $e->getMessage()]
            );
            return [];
        }
    }

    private function toSqlOp(string $condition): string
    {
        $map = [
            'gteq' => '>=', 'lteq' => '<=', 'gt' => '>',
            'lt'   => '<',  'eq'   => '=',  'neq' => '!=',
        ];
        return $map[$condition] ?? '>=';
    }

    private function toInverseSqlOp(string $condition): string
    {
        $map = [
            'gteq' => '<', 'lteq' => '>', 'gt' => '<=',
            'lt'   => '>=', 'eq'  => '!=', 'neq' => '=',
        ];
        return $map[$condition] ?? '<';
    }
}
