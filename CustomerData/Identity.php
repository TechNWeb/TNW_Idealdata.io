<?php

namespace TNW\Idealdata\CustomerData;

use Magento\Customer\CustomerData\SectionSourceInterface;
use Magento\Customer\Model\Session as CustomerSession;

/**
 * Customer-data section source exposing the logged-in customer's numeric id to
 * storefront JavaScript for the IdealData storefront pixel.
 *
 * The default Magento `customer` section does NOT carry the numeric entity id
 * (its `data_id` is a cache-version marker, not the customer id), so the pixel
 * SDK cannot read identity from it. This dedicated section fills that gap.
 *
 * Contract:
 *  - Logged-in customer  → ['customer_id' => (int) $id]
 *  - Guest / not logged in → [] (the key is ABSENT, never a 0 sentinel) so the
 *    SDK's extractCustomerId correctly reads "no identity" and sends nothing.
 *
 * Registered against the section name `tnw-idealdata-identity` in
 * etc/frontend/di.xml. The data is delivered to the browser via the standard
 * `/customer/section/load` AJAX endpoint (after Full Page Cache), which persists
 * it to localStorage['mage-cache-storage'] under the section name.
 */
class Identity implements SectionSourceInterface
{
    /**
     * The customer-data section name this source is registered against. Also the
     * localStorage['mage-cache-storage'] key the pixel SDK and the cart-tracking
     * bridge read, and the name etc/frontend/di.xml + etc/frontend/sections.xml
     * declare — a cross-repo contract, so the three must stay in sync (guarded by
     * Test\Unit\CustomerData\IdentitySectionNameTest).
     */
    const SECTION_NAME = 'tnw-idealdata-identity';

    /**
     * @var CustomerSession
     */
    private $customerSession;

    /**
     * @param CustomerSession $customerSession
     */
    public function __construct(CustomerSession $customerSession)
    {
        $this->customerSession = $customerSession;
    }

    /**
     * @inheritDoc
     * @return array
     */
    public function getSectionData()
    {
        if (!$this->customerSession->isLoggedIn()) {
            return [];
        }

        $customerId = (int) $this->customerSession->getCustomerId();
        if ($customerId <= 0) {
            // Defensive: an authenticated session with no usable id is treated
            // as "no identity" rather than emitting a 0 sentinel.
            return [];
        }

        return ['customer_id' => $customerId];
    }
}
