<?php

namespace TNW\Idealdata\Block\Pixel;

use Magento\Framework\View\Element\Template;
use TNW\Idealdata\Model\Pixel\Config;

/**
 * Storefront block that supplies the IdealData pixel boot-config to
 * `pixel/loader.phtml`. Rendered into `before.body.end` on every storefront
 * page via view/frontend/layout/default.xml.
 *
 * Injection is fully gated by admin config: the template renders nothing unless
 * the pixel is enabled AND a token + loader URL are configured. The emitted
 * snippet is self-contained and can only set a global + append an async
 * <script> tag, so it never blocks or breaks storefront JavaScript.
 *
 * The config reads live in Model\Pixel\Config, shared with the CSP policy
 * collector (Model\Csp\PixelPolicyCollector) so the injected snippet and the
 * whitelisted origins can never disagree. The XML_PATH_* constants are kept here
 * as aliases — Model\PixelConfigManagement and existing integrations reference
 * them.
 */
class Loader extends Template
{
    const XML_PATH_ENABLED = Config::XML_PATH_ENABLED;
    const XML_PATH_INGEST_BASE_URL = Config::XML_PATH_INGEST_BASE_URL;
    const XML_PATH_LOADER_URL = Config::XML_PATH_LOADER_URL;
    const XML_PATH_TOKEN = Config::XML_PATH_TOKEN;
    const XML_PATH_DEBUG = Config::XML_PATH_DEBUG;

    /** Canonical platform code (systems.code) the pixel loader boots with. */
    const PLATFORM = Config::PLATFORM;

    public function __construct(
        Template\Context $context,
        private readonly Config $pixelConfig,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Whether the pixel snippet should be injected on the storefront. True only
     * when explicitly enabled AND the minimum boot-config (token + loader URL)
     * is present — a half-configured pixel injects nothing rather than erroring.
     *
     * @return bool
     */
    public function isPixelEnabled()
    {
        return $this->pixelConfig->isPixelEnabled();
    }

    /**
     * The opaque pixel token (exposed to storefront JS by design).
     *
     * @return string
     */
    public function getToken()
    {
        return $this->pixelConfig->getToken();
    }

    /**
     * Full ingest base URL including the /pixel-ingest prefix; the loader
     * appends /config and /collect.
     *
     * @return string
     */
    public function getIngestBaseUrl()
    {
        return $this->pixelConfig->getIngestBaseUrl();
    }

    /**
     * Full URL to the async pixel loader script.
     *
     * @return string
     */
    public function getLoaderUrl()
    {
        return $this->pixelConfig->getLoaderUrl();
    }

    /**
     * The canonical platform code passed to window.idealdataSettings.platform.
     *
     * @return string
     */
    public function getPlatform()
    {
        return $this->pixelConfig->getPlatform();
    }

    /**
     * Whether SDK-wide debug logging is enabled. A LOCAL, operator-editable
     * switch (NOT app-managed/provisioned): when on, the loader emits
     * window.idealdataSettings.debug=true and the pixel logs verbosely to the
     * browser console. Off by default. Independent of isPixelEnabled().
     *
     * @return bool
     */
    public function isDebugEnabled()
    {
        return $this->pixelConfig->isDebugEnabled();
    }
}
