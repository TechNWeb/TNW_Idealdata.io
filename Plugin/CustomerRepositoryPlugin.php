<?php

namespace TNW\Idealdata\Plugin;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Api\Data\CustomerSearchResultsInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\Module\Manager as ModuleManager;
use Psr\Log\LoggerInterface;

class CustomerRepositoryPlugin
{

    /**
     * @param ModuleManager $moduleManager
     * @param ObjectManagerInterface $objectManager
     * @param LoggerInterface $logger
     */
    public function __construct(
        protected ModuleManager $moduleManager,
        protected ObjectManagerInterface $objectManager,
        protected LoggerInterface $logger
    ) {
    }

    /**
     * @param CustomerRepositoryInterface $subject
     * @param CustomerSearchResultsInterface $searchResults
     * @return CustomerSearchResultsInterface
     */
    public function afterGetList(
        CustomerRepositoryInterface $subject,
        CustomerSearchResultsInterface $searchResults
    ): CustomerSearchResultsInterface {
        $items = $searchResults->getItems();

        $b2bEnabled = $this->moduleManager->isEnabled('Magento_Company');
        $companyManagement = null;

        if ($b2bEnabled) {
            $companyManagement = $this->objectManager->get(\Magento\Company\Api\CompanyManagementInterface::class);
        }

        foreach ($items as $customer) {
            $type = 'individual_user';

            if ($b2bEnabled && $companyManagement->getByCustomerId($customer->getId())) {
                $type = 'company_user';
            }
            elseif ($this->hasCompanyAttribute($customer)) {
                $type = 'company_user';
            }
            elseif ($customer->getDefaultBilling()) {
                try {
                    $address = $this->objectManager
                        ->get(\Magento\Customer\Api\AddressRepositoryInterface::class)
                        ->getById($customer->getDefaultBilling());

                    if ($address && $address->getCompany()) {
                        $type = 'company_user';
                    }
                } catch (\Exception $e) {
                    $this->logger->info($e->getMessage());
                }
            }

            $extensionAttributes = $customer->getExtensionAttributes();
            if ($extensionAttributes) {
                $extensionAttributes->setCustomerType($type);
            }
        }

        return $searchResults;
    }

    /**
     * @param CustomerInterface $customer
     * @return bool
     */
    private function hasCompanyAttribute(CustomerInterface $customer): bool
    {
        foreach ($customer->getCustomAttributes() as $attribute) {
            if (strtolower($attribute->getAttributeCode()) === 'company') {
                $value = trim($attribute->getValue());
                return !empty($value);
            }
        }

        return false;
    }
}
