<?php

namespace TNW\Idealdata\Model;

use TNW\Idealdata\Api\Data\OrderStatusInterface;

class OrderStatus implements OrderStatusInterface
{
    public function __construct(
        private readonly string $status,
        private readonly string $label,
        private readonly string $state
    ) {}

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getState(): string
    {
        return $this->state;
    }
}
