<?php

declare(strict_types=1);

namespace TNW\Idealdata\Api;

/**
 * Receives the storefront-pixel config from the IdealData app (via the Adobe
 * Commerce connector) and writes it into this store's module config
 * (`tnw_idealdata_pixel/general/*`), so the merchant never enters the token or
 * ingest/loader URLs in Magento by hand.
 *
 * ACL-protected (`TNW_Idealdata::pixel_config_write`) — reachable only with an
 * integration/admin token that has been granted that resource (the same
 * credential class the IdealData connector already uses for product writes).
 * There is NO public/self-service provisioning path.
 *
 * @api
 */
interface PixelConfigManagementInterface
{
    /**
     * Provision the storefront-pixel config. Idempotent: the same payload twice
     * yields the same stored state. After writing, the config cache (and full-page
     * cache) is flushed so the new values are read on the next storefront request.
     *
     * @param bool $enabled Whether the module should inject the pixel snippet.
     * @param string $ingestBase Public ingest base URL (incl. the /pixel-ingest prefix).
     * @param string $loaderUrl Fully-qualified URL of the static loader bundle.
     * @param string $token Raw opaque pixel token (`idpx_…`), stored as-is.
     * @return \TNW\Idealdata\Api\Data\PixelConfigResultInterface
     * @throws \Magento\Framework\Exception\InputException
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function save(
        bool $enabled,
        string $ingestBase,
        string $loaderUrl,
        string $token
    ): Data\PixelConfigResultInterface;
}
