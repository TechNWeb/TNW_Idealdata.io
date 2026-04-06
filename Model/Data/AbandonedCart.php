<?php

declare(strict_types=1);

namespace TNW\Idealdata\Model\Data;

use Magento\Framework\DataObject;
use TNW\Idealdata\Api\Data\AbandonedCartInterface;

class AbandonedCart extends DataObject implements AbandonedCartInterface
{
    public function getCart(): array
    {
        return $this->getData('cart') ?? [];
    }

    public function getCustomer(): ?array
    {
        return $this->getData('customer');
    }

    public function getItems(): array
    {
        return $this->getData('items') ?? [];
    }
}
