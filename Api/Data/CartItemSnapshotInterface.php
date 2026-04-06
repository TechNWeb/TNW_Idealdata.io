<?php

declare(strict_types=1);

namespace TNW\Idealdata\Api\Data;

/**
 * @api
 */
interface CartItemSnapshotInterface
{
    /**
     * @return string
     */
    public function getItemId(): string;

    /**
     * @return string
     */
    public function getProductId(): string;

    /**
     * @return string
     */
    public function getSku(): string;

    /**
     * @return string
     */
    public function getName(): string;

    /**
     * @return float
     */
    public function getQty(): float;

    /**
     * @return float
     */
    public function getPrice(): float;

    /**
     * @return float
     */
    public function getRowTotal(): float;

    /**
     * @return string
     */
    public function getProductType(): string;
}
