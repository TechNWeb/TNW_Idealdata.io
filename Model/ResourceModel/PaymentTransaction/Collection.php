<?php

declare(strict_types=1);

namespace TNW\Idealdata\Model\ResourceModel\PaymentTransaction;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use TNW\Idealdata\Model\PaymentTransaction as PaymentTransactionModel;
use TNW\Idealdata\Model\ResourceModel\PaymentTransaction as PaymentTransactionResource;

class Collection extends AbstractCollection
{
    protected function _construct(): void
    {
        $this->_init(PaymentTransactionModel::class, PaymentTransactionResource::class);
    }
}
