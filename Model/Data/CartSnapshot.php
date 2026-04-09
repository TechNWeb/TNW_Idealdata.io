<?php

declare(strict_types=1);

namespace TNW\Idealdata\Model\Data;

use Magento\Framework\DataObject;
use TNW\Idealdata\Api\Data\CartSnapshotInterface;

class CartSnapshot extends DataObject implements CartSnapshotInterface
{
    public function getId(): string
    {
        return (string) $this->getData('id');
    }

    public function getIsActive(): bool
    {
        return (bool) $this->getData('is_active');
    }

    public function getIsGuest(): bool
    {
        return (bool) $this->getData('is_guest');
    }

    public function getCustomerId(): ?string
    {
        $id = $this->getData('customer_id');
        return $id !== null ? (string) $id : null;
    }

    public function getCustomerEmail(): ?string
    {
        return $this->getData('customer_email');
    }

    public function getBaseSubtotal(): float
    {
        return (float) $this->getData('base_subtotal');
    }

    public function getBaseCurrencyCode(): ?string
    {
        return $this->getData('base_currency_code');
    }

    public function getItemsCount(): int
    {
        return (int) $this->getData('items_count');
    }

    public function getCreatedAt(): ?string
    {
        return $this->getData('created_at');
    }

    public function getUpdatedAt(): ?string
    {
        return $this->getData('updated_at');
    }
}
