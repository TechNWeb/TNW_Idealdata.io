<?php

declare(strict_types=1);

namespace TNW\Idealdata\Model\Data;

use Magento\Framework\DataObject;
use TNW\Idealdata\Api\Data\TransactionDataInterface;

class TransactionData extends DataObject implements TransactionDataInterface
{
    public function getEntityId(): int
    {
        return (int) $this->getData('entity_id');
    }

    public function getQuoteId(): string
    {
        return (string) $this->getData('quote_id');
    }

    public function getTransactionId(): ?string
    {
        return $this->getData('transaction_id');
    }

    public function getStatus(): string
    {
        return (string) $this->getData('status');
    }

    public function getDeclineCode(): ?string
    {
        return $this->getData('decline_code');
    }

    public function getDeclineReason(): ?string
    {
        return $this->getData('decline_reason');
    }

    public function getDeclineCategory(): ?string
    {
        return $this->getData('decline_category');
    }

    public function getPaymentMethod(): ?string
    {
        return $this->getData('payment_method');
    }

    public function getCardType(): ?string
    {
        return $this->getData('card_type');
    }

    public function getCardLastFour(): ?string
    {
        return $this->getData('card_last_four');
    }

    public function getAmount(): ?float
    {
        $amount = $this->getData('amount');
        return $amount !== null ? (float) $amount : null;
    }

    public function getCurrency(): ?string
    {
        return $this->getData('currency');
    }

    public function getAttemptNumber(): int
    {
        return (int) $this->getData('attempt_number');
    }

    public function getIpAddress(): ?string
    {
        return $this->getData('ip_address');
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
