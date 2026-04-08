<?php

declare(strict_types=1);

namespace TNW\Idealdata\Plugin\Product;

use Magento\Catalog\Api\Data\ProductSearchResultsInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\App\ResourceConnection;
use Psr\Log\LoggerInterface;

/**
 * Intercepts the standard `updated_at` filter on GET /V1/products and augments
 * the result with products whose inventory_source_item.tnw_updated_at also
 * matches the same condition but catalog_product_entity.updated_at does NOT.
 *
 * All pagination is done in SQL — no full ID arrays loaded into PHP memory.
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

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly LoggerInterface $logger
    ) {
    }

    public function beforeGetList(
        ProductRepositoryInterface $subject,
        SearchCriteriaInterface $searchCriteria
    ): array {
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
        if ($this->updatedAtValue === null) {
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

            // Single COUNT query: stock-only changed products (not changed at product level)
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

            // How many slots to fill with stock-only products
            $slotsAvailable = $pageSize - $nativeItemCount;

            // Calculate offset into stock-only results
            $nativePages = $nativeTotal > 0 ? (int) ceil($nativeTotal / $pageSize) : 0;
            $stockOffset = 0;

            if ($currentPage > $nativePages && $nativePages > 0) {
                // Entirely beyond native pages
                $slotsAvailable = $pageSize;
                $stockOffset = ($currentPage - $nativePages - 1) * $pageSize;
                $result->setItems([]);
            } elseif ($currentPage > 1 && $nativePages === 0) {
                // No native results at all, page > 1
                $stockOffset = ($currentPage - 1) * $pageSize;
                $slotsAvailable = $pageSize;
                $result->setItems([]);
            }
            // else: last native page with room — stockOffset = 0

            // Exclude IDs already on this page
            $existingIds = [];
            foreach ($result->getItems() as $product) {
                $existingIds[] = (int) $product->getId();
            }

            // Single SQL with LIMIT/OFFSET — never loads full array
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

            // Load products and merge
            $items = $result->getItems();
            foreach ($idsForPage as $productId) {
                try {
                    $product = $subject->getById($productId);
                    $items[] = $product;
                } catch (\Throwable $e) {
                    continue;
                }
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
     * COUNT of products whose stock changed but product itself did NOT change.
     * Single indexed query, no data loaded into memory.
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
                ->join(
                    ['cpe' => $productTable],
                    'cpe.sku = isi.sku',
                    []
                )
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
     * Paginated IDs of products whose stock changed but product itself did NOT change.
     * Uses SQL LIMIT/OFFSET — never loads more than pageSize IDs.
     *
     * @param int[] $excludeIds IDs already on the current page
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
                ->join(
                    ['cpe' => $productTable],
                    'cpe.sku = isi.sku',
                    ['entity_id']
                )
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

    /**
     * Inverse condition: products that did NOT match the original filter.
     * gt (>) → inverse is <= ; gteq (>=) → inverse is <
     */
    private function toInverseSqlOp(string $condition): string
    {
        $map = [
            'gteq' => '<', 'lteq' => '>', 'gt' => '<=',
            'lt'   => '>=', 'eq'  => '!=', 'neq' => '=',
        ];
        return $map[$condition] ?? '<';
    }
}
