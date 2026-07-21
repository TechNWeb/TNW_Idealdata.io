<?php

declare(strict_types=1);

namespace TNW\Idealdata\Model\Data;

use Magento\Framework\DataObject;
use TNW\Idealdata\Api\Data\PixelConfigResultInterface;

class PixelConfigResult extends DataObject implements PixelConfigResultInterface
{
    public function getSuccess(): bool
    {
        return (bool) $this->getData('success');
    }

    public function setSuccess(bool $success): PixelConfigResultInterface
    {
        return $this->setData('success', $success);
    }

    public function getMessage(): string
    {
        return (string) $this->getData('message');
    }

    public function setMessage(string $message): PixelConfigResultInterface
    {
        return $this->setData('message', $message);
    }
}
