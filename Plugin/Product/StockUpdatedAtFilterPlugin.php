<?php

declare(strict_types=1);

namespace TNW\Idealdata\Plugin\Product;

use Magento\Catalog\Api\Data\ProductSearchResultsInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Api\FilterBuilder;
use Magento\Framework\Api\Search\FilterGroupBuilder;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\App\ResourceConnection;
use Psr\Log\LoggerInterface;

/**
 * Intercepts the standard `updated_at` filter on GET /V1/products and augments
 * the result with products whose inventory_source_item.tnw_updated_at also
 * matches the same condition but catalog_product_entity.updated_at does NOT.
 *
 * All pagination is done in SQL — no full ID arrays loaded into PHP memory.
 * Stock-only products are loaded via a single getList call (batch), not getById loop.
 *
 * total_count = native changed products + stock-only changed products
 * Pages: native products first, then stock-only products fill remaining slots.
 */
class StockUpdatedAtFilterPlugin
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
        ProductRepositoryInterface $subject,
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
                        $this->pageSize = (int) $searchCriteria->getPageSize() ?: 20;
                        $this->currentPage = (int) $searchCriteria->getCurrentPage() ?: 1;
                        break 2;
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->logger->error(
                'TNW_Idealdata: Failed to detect updated_at filter for stock merge',
                ['exception' => $e->getMessage()]
            );
        }

        return [$searchCriteria];
    }

    /**
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterGetList(
        ProductRepositoryInterface $subject,
        ProductSearchResultsInterface $result
    ): ProductSearchResultsInterface {
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

            // 1 COUNT query: stock-only changed products
            $stockOnlyTotal = $this->getStockOnlyChangedCount($updatedAtValue, $condition);

            if ($stockOnlyTotal === 0) {
                return $result;
            }

            // Update total_count
            $result->setTotalCount($nativeTotal + $stockOnlyTotal);

            // If this page is full with native products — done
            if ($nativeItemCount >= $pageSize) {
                return $result;
            }

            // Calculate slots and offset
            $slotsAvailable = $pageSize - $nativeItemCount;
            $nativePages = $nativeTotal > 0 ? (int) ceil($nativeTotal / $pageSize) : 0;
            $stockOffset = 0;

            if ($currentPage > $nativePages && $nativePages > 0) {
                $slotsAvailable = $pageSize;
                $stockOffset = ($currentPage - $nativePages - 1) * $pageSize;
                $result->setItems([]);
            } elseif ($currentPage > 1 && $nativePages === 0) {
                $stockOffset = ($currentPage - 1) * $pageSize;
                $slotsAvailable = $pageSize;
                $result->setItems([]);
            }

            // Exclude IDs already on this page
            $existingIds = [];
            foreach ($result->getItems() as $product) {
                $existingIds[] = (int) $product->getId();
            }

            // 1 SQL with LIMIT/OFFSET — get IDs for this page
            $idsForPage = $this->getStockOnlyChangedIds(
                $updatedAtValue,
                $condition,
                $slotsAvailable,
                $stockOffset,
                $existingIds
            );

            if (empty($idsForPage)) {
                return $result;
            }

            // Load all products in 1 batch getList call (triggers StockItemPlugin::afterGetList)
            $stockProducts = $this->loadProductsByIds($subject, $idsForPage);

            $items = $result->getItems();
            foreach ($stockProducts as $product) {
                $items[] = $product;
            }
            $result->setItems($items);
        } catch (\Throwable $e) {
            $this->logger->error(
                'TNW_Idealdata: Failed to merge stock-changed products',
                ['exception' => $e->getMessage()]
            );
        }

        return $result;
    }

    /**
     * Load products by IDs using a single getList call.
     * Recursion guard prevents this plugin from firing on the internal call.
     * StockItemPlugin::afterGetList WILL fire — batch-loading stock data.
     *
     * @param int[] $ids
     * @return ProductInterface[]
     */
    private function loadProductsByIds(ProductRepositoryInterface $repository, array $ids): array
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
                'TNW_Idealdata: Could not batch-load stock-changed products',
                ['exception' => $e->getMessage()]
            );
            return [];
        }
    }

    /**
     * COUNT of products whose stock changed but product itself did NOT change.
     */
    private function getStockOnlyChangedCount(string $value, string $condition): int
    {
        $connection = $this->resourceConnection->getConnection();
        $sqlOp = $this->toSqlOp($condition);
        $inverseSqlOp = $this->toInverseSqlOp($condition);

        try {
            $msiTable = $this->resourceConnection->getTableName('inventory_source_item');
            $productTable = $this->resourceConnection->getTableName('catalog_product_entity');

            $select = $connection->select()
                ->from(['isi' => $msiTable], [new \Zend_Db_Expr('COUNT(DISTINCT cpe.entity_id)')])
                ->join(['cpe' => $productTable], 'cpe.sku = isi.sku', [])
                ->where("isi.tnw_updated_at {$sqlOp} ?", $value)
                ->where("cpe.updated_at {$inverseSqlOp} ?", $value);

            return (int) $connection->fetchOne($select);
        } catch (\Throwable $e) {
            $this->logger->debug(
                'TNW_Idealdata: Could not count stock-only changed products',
                ['exception' => $e->getMessage()]
            );
            return 0;
        }
    }

    /**
     * Paginated IDs via SQL LIMIT/OFFSET.
     *
     * @param int[] $excludeIds
     * @return int[]
     */
    private function getStockOnlyChangedIds(
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
            $msiTable = $this->resourceConnection->getTableName('inventory_source_item');
            $productTable = $this->resourceConnection->getTableName('catalog_product_entity');

            $select = $connection->select()
                ->distinct(true)
                ->from(['isi' => $msiTable], [])
                ->join(['cpe' => $productTable], 'cpe.sku = isi.sku', ['entity_id'])
                ->where("isi.tnw_updated_at {$sqlOp} ?", $value)
                ->where("cpe.updated_at {$inverseSqlOp} ?", $value)
                ->order('cpe.entity_id ASC')
                ->limit($limit, $offset);

            if (!empty($excludeIds)) {
                $select->where('cpe.entity_id NOT IN (?)', $excludeIds);
            }

            return array_map('intval', $connection->fetchCol($select));
        } catch (\Throwable $e) {
            $this->logger->debug(
                'TNW_Idealdata: Could not query stock-only changed product IDs',
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
