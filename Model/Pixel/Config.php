<?php

declare(strict_types=1);

namespace TNW\Idealdata\Model\Pixel;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Single source of truth for the storefront-pixel configuration
 * (`tnw_idealdata_pixel/general/*`).
 *
 * Both the loader block (which decides whether to inject the snippet) and the
 * CSP policy collector (which decides which origins to whitelist) read through
 * this class. They MUST agree: a store whose CSP header lacks the loader origin
 * while the snippet is injected fails silently in the browser, and a store that
 * whitelists origins it never calls widens its policy for nothing.
 */
class Config
{
    public const XML_PATH_ENABLED = 'tnw_idealdata_pixel/general/enabled';
    public const XML_PATH_INGEST_BASE_URL = 'tnw_idealdata_pixel/general/ingest_base_url';
    public const XML_PATH_LOADER_URL = 'tnw_idealdata_pixel/general/loader_url';
    public const XML_PATH_TOKEN = 'tnw_idealdata_pixel/general/token';
    public const XML_PATH_DEBUG = 'tnw_idealdata_pixel/general/debug';

    /** Canonical platform code (systems.code) the pixel loader boots with. */
    public const PLATFORM = 'adobecommerce';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * Whether the pixel snippet should be injected on the storefront. True only
     * when explicitly enabled AND the minimum boot-config (token + loader URL)
     * is present — a half-configured pixel injects nothing rather than erroring.
     */
    public function isPixelEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED, ScopeInterface::SCOPE_STORE)
            && $this->getToken() !== ''
            && $this->getLoaderUrl() !== '';
    }

    /**
     * The opaque pixel token (exposed to storefront JS by design).
     */
    public function getToken(): string
    {
        return trim((string) $this->scopeConfig->getValue(self::XML_PATH_TOKEN, ScopeInterface::SCOPE_STORE));
    }

    /**
     * Full ingest base URL including the /pixel-ingest prefix; the loader
     * appends /config and /collect.
     */
    public function getIngestBaseUrl(): string
    {
        $value = trim(
            (string) $this->scopeConfig->getValue(self::XML_PATH_INGEST_BASE_URL, ScopeInterface::SCOPE_STORE)
        );

        return rtrim($value, '/');
    }

    /**
     * Full URL to the async pixel loader script.
     */
    public function getLoaderUrl(): string
    {
        return trim((string) $this->scopeConfig->getValue(self::XML_PATH_LOADER_URL, ScopeInterface::SCOPE_STORE));
    }

    /**
     * The canonical platform code passed to window.idealdataSettings.platform.
     */
    public function getPlatform(): string
    {
        return self::PLATFORM;
    }

    /**
     * Whether SDK-wide debug logging is enabled. A LOCAL, operator-editable
     * switch (NOT app-managed/provisioned): when on, the loader emits
     * window.idealdataSettings.debug=true and the pixel logs verbosely to the
     * browser console. Off by default. Independent of isPixelEnabled().
     */
    public function isDebugEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_DEBUG, ScopeInterface::SCOPE_STORE);
    }
}
