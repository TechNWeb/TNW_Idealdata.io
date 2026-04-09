<?php

declare(strict_types=1);

namespace TNW\Idealdata\Api\Data;

/**
 * @api
 */
interface FailedTransactionResultInterface
{
    /**
     * @return \TNW\Idealdata\Api\Data\TransactionDataInterface
     */
    public function getTransaction(): TransactionDataInterface;

    /**
     * @return \TNW\Idealdata\Api\Data\CartSnapshotInterface|null
     */
    public function getCart(): ?CartSnapshotInterface;

    /**
     * @return \TNW\Idealdata\Api\Data\CustomerSnapshotInterface|null
     */
    public function getCustomer(): ?CustomerSnapshotInterface;

    /**
     * @return \TNW\Idealdata\Api\Data\CartItemSnapshotInterface[]
     */
    public function getItems(): array;
}
