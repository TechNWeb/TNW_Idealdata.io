<?php

declare(strict_types=1);

namespace TNW\Idealdata\Api\Data;

/**
 * @api
 */
interface CustomerSnapshotInterface
{
    /**
     * @return string
     */
    public function getId(): string;

    /**
     * @return string|null
     */
    public function getFirstname(): ?string;

    /**
     * @return string|null
     */
    public function getLastname(): ?string;

    /**
     * @return string|null
     */
    public function getEmail(): ?string;
}
