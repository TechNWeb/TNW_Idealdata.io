<?php

declare(strict_types=1);

namespace TNW\Idealdata\Api\Data;

/**
 * Result of a pixel-config provisioning call.
 *
 * @api
 */
interface PixelConfigResultInterface
{
    /**
     * @return bool Whether the config was written + caches flushed successfully.
     */
    public function getSuccess(): bool;

    /**
     * @param bool $success
     * @return $this
     */
    public function setSuccess(bool $success): self;

    /**
     * @return string Human-readable status message.
     */
    public function getMessage(): string;

    /**
     * @param string $message
     * @return $this
     */
    public function setMessage(string $message): self;
}
