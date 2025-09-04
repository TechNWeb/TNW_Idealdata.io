<?php

namespace TNW\Idealdata\Api;


interface OrderStatusRepositoryInterface
{
    /**
     * Get all order statuses with labels and states
     *
     * @return \TNW\Idealdata\Api\Data\OrderStatusInterface[]
     */
    public function getList(): array;
}
