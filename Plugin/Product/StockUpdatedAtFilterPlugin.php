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
 * matches the same condition.
 *
 * Pagination logic:
 * 1. total_count = changed products + stock-only-changed products (deduplicated)
 * 2. First fill the page with Magento's native product results
 * 3. If page has room (items < pageSize), fill remaining slots with stock-changed products
 * 4. Deduplication: a product that changed both itself and stock appears only once
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

            // IDs of products Magento already returned (changed at product level)
            $existingIds = [];
            foreach ($result->getItems() as $product) {
                $existingIds[(int) $product->getId()] = true;
            }

            $nativeTotal = $result->getTotalCount();
            $nativeItemCount = count($result->getItems());

            // Get all stock-only-changed product IDs (NOT already changed at product level)
            $stockOnlyIds = $this->getStockOnlyChangedProductIds($updatedAtValue, $condition);

            // Deduplicate: remove IDs that Magento already knows about
            // (products changed at BOTH product and stock level)
            // We need to check against ALL native IDs, not just this page
            $overlapCount = $this->countOverlap($updatedAtValue, $condition);
            $stockOnlyTotal = count($stockOnlyIds) - $overlapCount;
            if ($stockOnlyTotal < 0) {
                $stockOnlyTotal = 0;
            }

            // Combined total_count (deduplicated)
            $combinedTotal = $nativeTotal + $stockOnlyTotal;
            $result->setTotalCount($combinedTotal);

            // If this page is already full, no need to add stock items
            if ($nativeItemCount >= $pageSize) {
                return $result;
            }

            // Remove IDs already on this page
            $stockOnlyIds = array_diff($stockOnlyIds, array_keys($existingIds));

            if (empty($stockOnlyIds)) {
                return $result;
            }

            // Calculate how many stock-changed products to add to this page
            $slotsAvailable = $pageSize - $nativeItemCount;

            // Calculate offset into stock-only list based on pagination
            // Stock items start appearing after all native product pages are exhausted
            $nativePages = $nativeTotal > 0 ? (int) ceil($nativeTotal / $pageSize) : 0;

            if ($currentPage <= $nativePages && $nativeItemCount >= $pageSize) {
                // Still in native pages and page is full — nothing to add
                return $result;
            }

            // How many stock-only items to skip (for pages beyond the last native page)
            $stockOffset = 0;
            if ($currentPage > $nativePages) {
                // We're on a page that's entirely stock-only items
                $slotsAvailable = $pageSize;
                $stockOffset = ($currentPage - $nativePages - 1) * $pageSize;

                // Clear native items since we're past native pages
                $result->setItems([]);
                $nativeItemCount = 0;
            }
            // else: currentPage == nativePages (last native page, partially full)
            // stockOffset = 0, fill remaining slots

            // Slice the stock-only IDs for this page
            $stockOnlyIds = array_values($stockOnlyIds);
            $idsForPage = array_slice($stockOnlyIds, $stockOffset, $slotsAvailable);

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
     * Get product IDs whose MSI stock changed but are NOT in the native
     * catalog_product_entity.updated_at result set.
     *
     * @return int[]
     */
    private function getStockOnlyChangedProductIds(string $value, string $condition): array
    {
        $connection = $this->resourceConnection->getConnection();
        $sqlOp = $this->toSqlOp($condition);

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
                ->where("isi.tnw_updated_at {$sqlOp} ?", $value);

            return array_map('intval', $connection->fetchCol($select));
        } catch (\Throwable $e) {
            $this->logger->debug(
                'TNW_Idealdata: Could not query inventory_source_item.tnw_updated_at',
                ['exception' => $e->getMessage()]
            );
            return [];
        }
    }

    /**
     * Count products that changed at BOTH product level AND stock level
     * (to avoid double-counting in total_count).
     */
    private function countOverlap(string $value, string $condition): int
    {
        $connection = $this->resourceConnection->getConnection();
        $sqlOp = $this->toSqlOp($condition);

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
                ->where("cpe.updated_at {$sqlOp} ?", $value);

            return (int) $connection->fetchOne($select);
        } catch (\Throwable $e) {
            return 0;
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
}
