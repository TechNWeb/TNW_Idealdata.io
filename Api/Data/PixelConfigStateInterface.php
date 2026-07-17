<?php

declare(strict_types=1);

namespace TNW\Idealdata\Api\Data;

/**
 * The LIVE storefront-pixel config read back from this store's module config
 * (`tnw_idealdata_pixel/general/*`). The read side of the provisioning contract:
 * Adobe Commerce — not IdealData — is the source of truth for what is actually
 * configured on the storefront, so the IdealData app reads this to reconcile its
 * stored mirror and heal drift.
 *
 * SECURITY: the raw token is NEVER returned. Only whether one is stored
 * ({@see getTokenPresent()}) and a SHA-256 fingerprint of it
 * ({@see getTokenSha256()}), so the app can hash-compare against the SHA-256 it
 * stores for the connection's active token — the only way to detect token drift
 * without exposing the raw value.
 *
 * @api
 */
interface PixelConfigStateInterface
{
    /**
     * @return bool Whether the module is configured to inject the pixel snippet.
     */
    public function getEnabled(): bool;

    /**
     * @param bool $enabled
     * @return $this
     */
    public function setEnabled(bool $enabled): self;

    /**
     * @return string Public ingest base URL (incl. the /pixel-ingest prefix), or empty.
     */
    public function getIngestBase(): string;

    /**
     * @param string $ingestBase
     * @return $this
     */
    public function setIngestBase(string $ingestBase): self;

    /**
     * @return string Fully-qualified URL of the static loader bundle, or empty.
     */
    public function getLoaderUrl(): string;

    /**
     * @param string $loaderUrl
     * @return $this
     */
    public function setLoaderUrl(string $loaderUrl): self;

    /**
     * @return bool Whether a non-empty token is stored.
     */
    public function getTokenPresent(): bool;

    /**
     * @param bool $tokenPresent
     * @return $this
     */
    public function setTokenPresent(bool $tokenPresent): self;

    /**
     * @return string|null SHA-256 hex of the stored token; null when none is stored.
     */
    public function getTokenSha256(): ?string;

    /**
     * @param string|null $tokenSha256
     * @return $this
     */
    public function setTokenSha256(?string $tokenSha256): self;
}
