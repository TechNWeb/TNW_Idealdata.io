<?php

declare(strict_types=1);

namespace TNW\Idealdata\Model\Data;

use Magento\Framework\DataObject;
use TNW\Idealdata\Api\Data\CustomerSnapshotInterface;

class CustomerSnapshot extends DataObject implements CustomerSnapshotInterface
{
    public function getId(): string
    {
        return (string) $this->getData('id');
    }

    public function getFirstname(): ?string
    {
        return $this->getData('firstname');
    }

    public function getLastname(): ?string
    {
        return $this->getData('lastname');
    }

    public function getEmail(): ?string
    {
        return $this->getData('email');
    }
}
