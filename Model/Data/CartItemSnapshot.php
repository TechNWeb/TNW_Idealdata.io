<?php

declare(strict_types=1);

namespace TNW\Idealdata\Model\Data;

use Magento\Framework\DataObject;
use TNW\Idealdata\Api\Data\CartItemSnapshotInterface;

class CartItemSnapshot extends DataObject implements CartItemSnapshotInterface
{
    public function getItemId(): string
    {
        return (string) $this->getData('item_id');
    }

    public function getProductId(): string
    {
        return (string) $this->getData('product_id');
    }

    public function getSku(): string
    {
        return (string) $this->getData('sku');
    }

    public function getName(): string
    {
        return (string) $this->getData('name');
    }

    public function getQty(): float
    {
        return (float) $this->getData('qty');
    }

    public function getPrice(): float
    {
        return (float) $this->getData('price');
    }

    public function getRowTotal(): float
    {
        return (float) $this->getData('row_total');
    }

    public function getProductType(): string
    {
        return (string) $this->getData('product_type');
    }
}
