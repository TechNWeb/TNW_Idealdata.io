<?php

declare(strict_types=1);

namespace TNW\Idealdata\Plugin\Customer;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerSearchResultsInterface;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Customer\Api\Data\AddressInterfaceFactory;
use Psr\Log\LoggerInterface;

class AddressUpdatedAtFilterPlugin
{
    private ?string $addressUpdatedAtValue = null;
    private ?string $addressUpdatedAtCondition = null;

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly LoggerInterface $logger
    ) {
    }

    public function beforeGetList(
        CustomerRepositoryInterface $subject,
        SearchCriteriaInterface $searchCriteria
    ): array {
        try {
            $this->addressUpdatedAtValue = null;
            $this->addressUpdatedAtCondition = null;

            $filterGroups = $searchCriteria->getFilterGroups();
            $modified = false;

            foreach ($filterGroups as $groupIndex => $filterGroup) {
                $filters = $filterGroup->getFilters();
                $remainingFilters = [];

                foreach ($filters as $filter) {
                    if ($filter->getField() === 'tnw_address_updated_at') {
                        $this->addressUpdatedAtValue = $filter->getValue();
                        $this->addressUpdatedAtCondition = $filter->getConditionType() ?: 'gteq';
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
                'TNW_Idealdata: Failed to process tnw_address_updated_at filter',
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
        if ($this->addressUpdatedAtValue === null) {
            return $result;
        }

        try {
            $addressUpdatedValue = $this->addressUpdatedAtValue;
            $condition = $this->addressUpdatedAtCondition ?? 'gteq';
            $this->addressUpdatedAtValue = null;
            $this->addressUpdatedAtCondition = null;

            $connection = $this->resourceConnection->getConnection();
            $addressTable = $this->resourceConnection->getTableName('customer_address_entity');

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
                ->from($addressTable, ['parent_id'])
                ->distinct(true)
                ->where("updated_at {$sqlOp} ?", $addressUpdatedValue);

            $validCustomerIds = array_map('intval', $connection->fetchCol($select));

            if (empty($validCustomerIds)) {
                $result->setItems([]);
                $result->setTotalCount(0);
                return $result;
            }

            $filteredItems = [];
            foreach ($result->getItems() as $customer) {
                if (in_array((int) $customer->getId(), $validCustomerIds, true)) {
                    $filteredItems[] = $customer;
                }
            }

            $result->setItems($filteredItems);
            $result->setTotalCount(count($filteredItems));
        } catch (\Throwable $e) {
            $this->logger->error(
                'TNW_Idealdata: Failed to apply tnw_address_updated_at post-filter',
                ['exception' => $e->getMessage()]
            );
        }

        return $result;
    }
}
