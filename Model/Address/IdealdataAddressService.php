<?php

namespace TNW\Idealdata\Model\Address;

use Magento\Customer\Api\AddressRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\FilterBuilder;
use Magento\Framework\Api\Search\FilterGroupBuilder;
use TNW\Idealdata\Api\IdealdataAddressServiceInterface;

class IdealdataAddressService implements IdealdataAddressServiceInterface
{
    /**
     * @var AddressRepositoryInterface
     */
    private AddressRepositoryInterface $addressRepository;

    /**
     * @var SearchCriteriaBuilder
     */
    private SearchCriteriaBuilder $searchCriteriaBuilder;

    /**
     * @var FilterBuilder
     */
    private FilterBuilder $filterBuilder;

    /**
     * @var FilterGroupBuilder
     */
    private FilterGroupBuilder $filterGroupBuilder;

    /**
     * @param AddressRepositoryInterface $addressRepository
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param FilterBuilder $filterBuilder
     * @param FilterGroupBuilder $filterGroupBuilder
     */
    public function __construct(
        AddressRepositoryInterface $addressRepository,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        FilterBuilder $filterBuilder,
        FilterGroupBuilder $filterGroupBuilder
    ) {
        $this->addressRepository = $addressRepository;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->filterBuilder = $filterBuilder;
        $this->filterGroupBuilder = $filterGroupBuilder;
    }

    /**
     * @param int $id
     * @return \Magento\Customer\Api\Data\AddressInterface|array
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function getAddress(int $id): \Magento\Customer\Api\Data\AddressInterface|array
    {
        try {
            return $this->addressRepository->getById($id);
        } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
            return [];
        }
    }

    /**
     * @param int $minutes
     * @return array|\Magento\Customer\Api\Data\AddressInterface[]
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function getUpdatedAddresses(int $minutes): array
    {
        $date = (new \DateTime())->modify("-{$minutes} minutes")->format('Y-m-d H:i:s');

        $filter = $this->filterBuilder
            ->setField('updated_at')
            ->setValue($date)
            ->setConditionType('gteq')
            ->create();

        $filterGroup = $this->filterGroupBuilder
            ->addFilter($filter)
            ->create();

        $searchCriteria = $this->searchCriteriaBuilder
            ->setFilterGroups([$filterGroup])
            ->create();

        $result = $this->addressRepository->getList($searchCriteria);

        return $result->getItems();
    }

    /**
     * @param int $customerId
     * @return array|\Magento\Customer\Api\Data\AddressInterface[]
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function getAddressesByCustomerId(int $customerId): array
    {
        $filter = $this->filterBuilder
            ->setField('parent_id')
            ->setValue($customerId)
            ->setConditionType('eq')
            ->create();

        $filterGroup = $this->filterGroupBuilder
            ->addFilter($filter)
            ->create();

        $searchCriteria = $this->searchCriteriaBuilder
            ->setFilterGroups([$filterGroup])
            ->create();

        $result = $this->addressRepository->getList($searchCriteria);

        return $result->getItems();
    }
}
