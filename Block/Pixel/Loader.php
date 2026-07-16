<?php

namespace TNW\Idealdata\Block\Pixel;

use Magento\Framework\View\Element\Template;
use Magento\Store\Model\ScopeInterface;

/**
 * Storefront block that supplies the IdealData pixel boot-config to
 * `pixel/loader.phtml`. Rendered into `before.body.end` on every storefront
 * page via view/frontend/layout/default.xml.
 *
 * Injection is fully gated by admin config: the template renders nothing unless
 * the pixel is enabled AND a token + loader URL are configured. The emitted
 * snippet is self-contained and can only set a global + append an async
 * <script> tag, so it never blocks or breaks storefront JavaScript.
 */
class Loader extends Template
{
    const XML_PATH_ENABLED = 'tnw_idealdata_pixel/general/enabled';
    const XML_PATH_INGEST_BASE_URL = 'tnw_idealdata_pixel/general/ingest_base_url';
    const XML_PATH_LOADER_URL = 'tnw_idealdata_pixel/general/loader_url';
    const XML_PATH_TOKEN = 'tnw_idealdata_pixel/general/token';

    /** Canonical platform code (systems.code) the pixel loader boots with. */
    const PLATFORM = 'adobecommerce';

    /**
     * Whether the pixel snippet should be injected on the storefront. True only
     * when explicitly enabled AND the minimum boot-config (token + loader URL)
     * is present — a half-configured pixel injects nothing rather than erroring.
     *
     * @return bool
     */
    public function isPixelEnabled()
    {
        return $this->_scopeConfig->isSetFlag(self::XML_PATH_ENABLED, ScopeInterface::SCOPE_STORE)
            && $this->getToken() !== ''
            && $this->getLoaderUrl() !== '';
    }

    /**
     * The opaque pixel token (exposed to storefront JS by design).
     *
     * @return string
     */
    public function getToken()
    {
        return trim((string) $this->_scopeConfig->getValue(self::XML_PATH_TOKEN, ScopeInterface::SCOPE_STORE));
    }

    /**
     * Full ingest base URL including the /pixel-ingest prefix; the loader
     * appends /config and /collect.
     *
     * @return string
     */
    public function getIngestBaseUrl()
    {
        $value = trim((string) $this->_scopeConfig->getValue(self::XML_PATH_INGEST_BASE_URL, ScopeInterface::SCOPE_STORE));

        return rtrim($value, '/');
    }

    /**
     * Full URL to the async pixel loader script.
     *
     * @return string
     */
    public function getLoaderUrl()
    {
        return trim((string) $this->_scopeConfig->getValue(self::XML_PATH_LOADER_URL, ScopeInterface::SCOPE_STORE));
    }

    /**
     * The canonical platform code passed to window.idealdataSettings.platform.
     *
     * @return string
     */
    public function getPlatform()
    {
        return self::PLATFORM;
    }
}
