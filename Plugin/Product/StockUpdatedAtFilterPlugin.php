<?php

declare(strict_types=1);

namespace TNW\Idealdata\Plugin\Product;

use Magento\Catalog\Api\Data\ProductSearchResultsInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\App\ResourceConnection;
use Psr\Log\LoggerInterface;

class StockUpdatedAtFilterPlugin
{
    private ?string $stockUpdatedAtValue = null;
    private ?string $stockUpdatedAtCondition = null;

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
            $this->stockUpdatedAtValue = null;
            $this->stockUpdatedAtCondition = null;

            $filterGroups = $searchCriteria->getFilterGroups();
            $modified = false;

            foreach ($filterGroups as $groupIndex => $filterGroup) {
                $filters = $filterGroup->getFilters();
                $remainingFilters = [];

                foreach ($filters as $filter) {
                    if ($filter->getField() === 'tnw_stock_updated_at') {
                        $this->stockUpdatedAtValue = $filter->getValue();
                        $this->stockUpdatedAtCondition = $filter->getConditionType() ?: 'gteq';
                        $modified = true;
                    } else {
                        $remainingFilters[] = $filter;
                    }
                }

                if (count($remainingFilters) !== count($filters)) {
                    if (empty($remainingFilters)) {
                        unset($filterGroups[$groupIndex]);
                    } else {
                        $filterGroup->setFilters($remainingFilters);
                    }
                }
            }

            if ($modified) {
                $searchCriteria->setFilterGroups(array_values($filterGroups));
            }
        } catch (\Throwable $e) {
            $this->logger->error(
                'TNW_Idealdata: Failed to process tnw_stock_updated_at filter',
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
        if ($this->stockUpdatedAtValue === null) {
            return $result;
        }

        try {
            $stockUpdatedValue = $this->stockUpdatedAtValue;
            $condition = $this->stockUpdatedAtCondition ?? 'gteq';
            $this->stockUpdatedAtValue = null;
            $this->stockUpdatedAtCondition = null;

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
                ->where("updated_at {$sqlOp} ?", $stockUpdatedValue);

            $validProductIds = array_map('intval', $connection->fetchCol($select));

            if (empty($validProductIds)) {
                $result->setItems([]);
                $result->setTotalCount(0);
                return $result;
            }

            $filteredItems = [];
            foreach ($result->getItems() as $product) {
                if (in_array((int) $product->getId(), $validProductIds, true)) {
                    $filteredItems[] = $product;
                }
            }

            $result->setItems($filteredItems);
            $result->setTotalCount(count($filteredItems));
        } catch (\Throwable $e) {
            $this->logger->error(
                'TNW_Idealdata: Failed to apply tnw_stock_updated_at post-filter',
                ['exception' => $e->getMessage()]
            );
        }

        return $result;
    }
}
