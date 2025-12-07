<?php
declare(strict_types=1);

namespace TNW\Idealdata\Api;

use Magento\Customer\Api\Data\AddressInterface;

interface IdealdataAddressServiceInterface
{
    /**
     * @param int $id
     * @return AddressInterface|array
     */
    public function getAddress(int $id): AddressInterface|array;

    /**
     * @param int $minutes
     * @return AddressInterface[]
     */
    public function getUpdatedAddresses(int $minutes): array;

    /**
     * @param int $customerId
     * @return \Magento\Customer\Api\Data\AddressInterface[]
     */
    public function getAddressesByCustomerId(int $customerId): array;

}
