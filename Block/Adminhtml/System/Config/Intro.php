<?php

namespace TNW\Idealdata\Block\Adminhtml\System\Config;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

class Intro extends Field
{
    protected $_template = 'TNW_Idealdata::system/config/intro.phtml';

    protected function _getElementHtml(AbstractElement $element)
    {
        return $this->_toHtml();
    }
}
