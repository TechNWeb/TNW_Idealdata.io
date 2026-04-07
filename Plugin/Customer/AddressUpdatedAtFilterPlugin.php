<?php

declare(strict_types=1);

namespace TNW\Idealdata\Plugin\Customer;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerSearchResultsInterface;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\App\ResourceConnection;
use Psr\Log\LoggerInterface;

/**
 * Intercepts the standard `updated_at` filter on GET /V1/customers/search and augments
 * the result set with customers whose customer_address_entity.updated_at also matches
 * the same condition. This way a single native API call returns both customer-level
 * and address-level changes.
 */
class AddressUpdatedAtFilterPlugin
{
    private ?string $updatedAtValue = null;
    private ?string $updatedAtCondition = null;

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Detect the standard `updated_at` filter and store its value.
     * Do NOT remove it — let Magento apply it normally.
     */
    public function beforeGetList(
        CustomerRepositoryInterface $subject,
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
                'TNW_Idealdata: Failed to detect updated_at filter for address merge',
                ['exception' => $e->getMessage()]
            );
        }

        return [$searchCriteria];
    }

    /**
     * After Magento returns customers matching updated_at, find additional customers
     * whose address changed in the same period but whose customer_entity.updated_at
     * did NOT change. Load them individually and merge into the result.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterGetList(
        CustomerRepositoryInterface $subject,
        CustomerSearchResultsInterface $result
    ): CustomerSearchResultsInterface {
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
            foreach ($result->getItems() as $customer) {
                $existingIds[(int) $customer->getId()] = true;
            }

            // Find customer IDs whose address changed in the same period
            $addressChangedIds = $this->getAddressChangedCustomerIds($updatedAtValue, $condition);

            // Filter to only IDs NOT already in the result
            $missingIds = array_diff($addressChangedIds, array_keys($existingIds));

            if (empty($missingIds)) {
                return $result;
            }

            // Load the missing customers and merge
            $items = $result->getItems();
            foreach ($missingIds as $customerId) {
                try {
                    $customer = $subject->getById($customerId);
                    $items[] = $customer;
                } catch (\Throwable $e) {
                    // Customer may have been deleted — skip
                    continue;
                }
            }

            $result->setItems($items);
            $result->setTotalCount(count($items));
        } catch (\Throwable $e) {
            $this->logger->error(
                'TNW_Idealdata: Failed to merge address-changed customers',
                ['exception' => $e->getMessage()]
            );
        }

        return $result;
    }

    /**
     * @return int[]
     */
    private function getAddressChangedCustomerIds(string $value, string $condition): array
    {
        $connection = $this->resourceConnection->getConnection();
        $tableName = $this->resourceConnection->getTableName('customer_address_entity');

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
            ->from($tableName, ['parent_id'])
            ->distinct(true)
            ->where("updated_at {$sqlOp} ?", $value);

        return array_map('intval', $connection->fetchCol($select));
    }
}
