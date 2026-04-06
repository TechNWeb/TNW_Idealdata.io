<?php

declare(strict_types=1);

namespace TNW\Idealdata\Api\Data;

/**
 * @api
 */
interface AbandonedCartInterface
{
    /**
     * @return array
     */
    public function getCart(): array;

    /**
     * @return array|null
     */
    public function getCustomer(): ?array;

    /**
     * @return array[]
     */
    public function getItems(): array;
}
