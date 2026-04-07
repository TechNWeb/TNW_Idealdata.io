<?php

declare(strict_types=1);

namespace TNW\Idealdata\Plugin\Product;

use Magento\Catalog\Api\Data\ProductSearchResultsInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\App\ResourceConnection;
use Psr\Log\LoggerInterface;

/**
 * Intercepts the standard `updated_at` filter on GET /V1/products and augments the
 * result set with products whose cataloginventory_stock_item.updated_at also matches
 * the same condition. This way a single native API call returns both product-level
 * and stock-level changes — no custom filter field needed.
 */
class StockUpdatedAtFilterPlugin
{
    private ?string $updatedAtValue = null;
    private ?string $updatedAtCondition = null;

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Detect the standard `updated_at` filter and store its value.
     * Do NOT remove it — let Magento apply it normally.
     */
    public function beforeGetList(
        ProductRepositoryInterface $subject,
        SearchCriteriaInterface $searchCriteria
    ): array {
        try {
            $this->updatedAtValue = null;
            $this->updatedAtCondition = null;

            foreach ($searchCriteria->getFilterGroups() as $filterGroup) {
                foreach ($filterGroup->getFilters() as $filter) {
                    if ($filter->getField() === 'updated_at') {
                        $this->updatedAtValue = $filter->getValue();
                        $this->updatedAtCondition = $filter->getConditionType() ?: 'gteq';
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
     * After Magento returns products matching updated_at, find additional products
     * whose stock changed in the same period but whose catalog_product_entity.updated_at
     * did NOT change. Load them individually and merge into the result.
     *
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
            $this->updatedAtValue = null;
            $this->updatedAtCondition = null;

            // Collect IDs already in result
            $existingIds = [];
            foreach ($result->getItems() as $product) {
                $existingIds[(int) $product->getId()] = true;
            }

            // Find product IDs whose stock changed in the same period
            $stockChangedIds = $this->getStockChangedProductIds($updatedAtValue, $condition);

            // Filter to only IDs NOT already in the result
            $missingIds = array_diff($stockChangedIds, array_keys($existingIds));

            if (empty($missingIds)) {
                return $result;
            }

            // Load the missing products and merge
            $items = $result->getItems();
            foreach ($missingIds as $productId) {
                try {
                    $product = $subject->getById($productId);
                    $items[] = $product;
                } catch (\Throwable $e) {
                    // Product may have been deleted or is not visible — skip
                    continue;
                }
            }

            $result->setItems($items);
            $result->setTotalCount(count($items));
        } catch (\Throwable $e) {
            $this->logger->error(
                'TNW_Idealdata: Failed to merge stock-changed products',
                ['exception' => $e->getMessage()]
            );
        }

        return $result;
    }

    /**
     * @return int[]
     */
    private function getStockChangedProductIds(string $value, string $condition): array
    {
        $connection = $this->resourceConnection->getConnection();
        $tableName = $this->resourceConnection->getTableName('cataloginventory_stock_item');

        $conditionMap = [
            'gteq' => '>=',
            'lteq' => '<=',
            'gt'   => '>',
            'lt'   => '<',
            'eq'   => '=',
            'neq'  => '!=',
        ];
        $sqlOp = $conditionMap[$condition] ?? '>=';

        $select = $connection->select()
            ->from($tableName, ['product_id'])
            ->where("updated_at {$sqlOp} ?", $value);

        return array_map('intval', $connection->fetchCol($select));
    }
}
