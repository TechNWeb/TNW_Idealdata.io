<?php

declare(strict_types=1);

namespace TNW\Idealdata\Model\Data;

use Magento\Framework\DataObject;
use TNW\Idealdata\Api\Data\CartItemSnapshotInterface;
use TNW\Idealdata\Api\Data\CartSnapshotInterface;
use TNW\Idealdata\Api\Data\CustomerSnapshotInterface;
use TNW\Idealdata\Api\Data\FailedTransactionResultInterface;
use TNW\Idealdata\Api\Data\TransactionDataInterface;

class FailedTransactionResult extends DataObject implements FailedTransactionResultInterface
{
    public function getTransaction(): TransactionDataInterface
    {
        return $this->getData('transaction');
    }

    public function getCart(): ?CartSnapshotInterface
    {
        return $this->getData('cart');
    }

    public function getCustomer(): ?CustomerSnapshotInterface
    {
        return $this->getData('customer');
    }

    /**
     * @return CartItemSnapshotInterface[]
     */
    public function getItems(): array
    {
        return $this->getData('items') ?? [];
    }
}
