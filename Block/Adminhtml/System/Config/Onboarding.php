<?php

namespace TNW\Idealdata\Block\Adminhtml\System\Config;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

class Onboarding extends Field
{
    protected $_template = 'TNW_Idealdata::system/config/onboarding.phtml';

    protected function _getElementHtml(AbstractElement $element)
    {
        return $this->_toHtml();
    }
}
