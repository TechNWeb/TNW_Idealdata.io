<?php

declare(strict_types=1);

namespace TNW\Idealdata\Api\Data;

/**
 * @api
 */
interface CartSnapshotInterface
{
    /**
     * @return string
     */
    public function getId(): string;

    /**
     * @return bool
     */
    public function getIsActive(): bool;

    /**
     * @return bool
     */
    public function getIsGuest(): bool;

    /**
     * @return string|null
     */
    public function getCustomerId(): ?string;

    /**
     * @return string|null
     */
    public function getCustomerEmail(): ?string;

    /**
     * @return float
     */
    public function getBaseSubtotal(): float;

    /**
     * @return string|null
     */
    public function getBaseCurrencyCode(): ?string;

    /**
     * @return int
     */
    public function getItemsCount(): int;

    /**
     * @return string|null
     */
    public function getCreatedAt(): ?string;

    /**
     * @return string|null
     */
    public function getUpdatedAt(): ?string;
}
