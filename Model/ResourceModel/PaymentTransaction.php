<?php

declare(strict_types=1);

namespace TNW\Idealdata\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class PaymentTransaction extends AbstractDb
{
    protected function _construct(): void
    {
        $this->_init('tnw_quote_payment_transaction', 'entity_id');
    }
}
