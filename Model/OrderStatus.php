<?php

namespace TNW\Idealdata\Model;

use TNW\Idealdata\Api\Data\OrderStatusInterface;

class OrderStatus implements OrderStatusInterface
{
    public function __construct(
        private readonly string $statusCode,
        private readonly string $statusLabel,
        private readonly string $stateCode,
        private readonly string $stateLabel
    ) {}

    public function getStatusCode(): string
    {
        return $this->statusCode;
    }

    public function getStatusLabel(): string
    {
        return $this->statusLabel;
    }

    public function getStateCode(): string
    {
        return $this->stateCode;
    }

    public function getStateLabel(): string
    {
        return $this->stateLabel;
    }
}
