<?php

namespace TNW\Idealdata\Block\Adminhtml\System\Config;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 * Read-only, informational admin block that shows a developer copy-paste
 * reference for binding storefront cart events to the pixel's public
 * `idealdataPixel(...)` API. It renders static guidance + snippets only — it
 * executes nothing and provisions nothing. Mirrors the Intro/Onboarding/Support
 * blocks (a group-level frontend_model rendering a phtml with the shared
 * idealdata-config-* styling).
 */
class DeveloperSnippets extends Field
{
    protected $_template = 'TNW_Idealdata::system/config/developer_snippets.phtml';

    protected function _getElementHtml(AbstractElement $element)
    {
        return $this->_toHtml();
    }
}
