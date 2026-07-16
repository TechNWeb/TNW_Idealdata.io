<?php

declare(strict_types=1);

namespace TNW\Idealdata\Block\Adminhtml\System\Config;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 * Renders a Storefront Pixel config field as read-only (disabled). These values
 * are managed automatically by the IdealData app (pushed in via the pixel
 * provisioning REST endpoint), so the merchant sees the delivered values but is
 * never prompted to enter them by hand.
 *
 * Stores not using app provisioning can still set the values out-of-band with
 * `bin/magento config:set tnw_idealdata_pixel/general/<field> <value>` (see README).
 */
class ReadOnlyField extends Field
{
    protected function _getElementHtml(AbstractElement $element)
    {
        // `disabled` greys the field and prevents submit; `readonly` is belt-and-
        // suspenders. Magento's dependence controller (for fields with <depends>)
        // strips `disabled` client-side when the master condition is met — these
        // fields therefore carry NO <depends> (see system.xml) so `disabled` sticks;
        // `readonly` additionally survives any such toggling on text inputs.
        $element->setDisabled('disabled');
        $element->setReadonly('readonly');

        return parent::_getElementHtml($element);
    }
}
