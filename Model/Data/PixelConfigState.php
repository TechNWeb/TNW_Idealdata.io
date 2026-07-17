<?php

declare(strict_types=1);

namespace TNW\Idealdata\Model\Data;

use Magento\Framework\DataObject;
use TNW\Idealdata\Api\Data\PixelConfigStateInterface;

class PixelConfigState extends DataObject implements PixelConfigStateInterface
{
    public function getEnabled(): bool
    {
        return (bool) $this->getData('enabled');
    }

    public function setEnabled(bool $enabled): PixelConfigStateInterface
    {
        return $this->setData('enabled', $enabled);
    }

    public function getIngestBase(): string
    {
        return (string) $this->getData('ingest_base');
    }

    public function setIngestBase(string $ingestBase): PixelConfigStateInterface
    {
        return $this->setData('ingest_base', $ingestBase);
    }

    public function getLoaderUrl(): string
    {
        return (string) $this->getData('loader_url');
    }

    public function setLoaderUrl(string $loaderUrl): PixelConfigStateInterface
    {
        return $this->setData('loader_url', $loaderUrl);
    }

    public function getTokenPresent(): bool
    {
        return (bool) $this->getData('token_present');
    }

    public function setTokenPresent(bool $tokenPresent): PixelConfigStateInterface
    {
        return $this->setData('token_present', $tokenPresent);
    }

    public function getTokenSha256(): ?string
    {
        $value = $this->getData('token_sha256');

        return $value === null ? null : (string) $value;
    }

    public function setTokenSha256(?string $tokenSha256): PixelConfigStateInterface
    {
        return $this->setData('token_sha256', $tokenSha256);
    }
}
