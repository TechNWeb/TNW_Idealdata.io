<?php

declare(strict_types=1);

namespace TNW\Idealdata\Model;

use Magento\Framework\Model\AbstractModel;

class PaymentTransaction extends AbstractModel
{
    protected function _construct(): void
    {
        $this->_init(ResourceModel\PaymentTransaction::class);
    }
}
