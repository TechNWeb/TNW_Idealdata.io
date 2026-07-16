<?php

declare(strict_types=1);

namespace TNW\Idealdata\Api;

/**
 * Receives the storefront-pixel config from the IdealData app (via the Adobe
 * Commerce connector) and writes it into this store's module config
 * (`tnw_idealdata_pixel/general/*`), so the merchant never enters the token or
 * ingest/loader URLs in Magento by hand.
 *
 * ACL-protected (`Magento_Catalog::products`) — reachable only with an
 * integration/admin token that holds that resource. The IdealData integration
 * ALREADY holds it (product sync + Signal 30 product writes both require it), so
 * provisioning needs no new grant and no integration reauthorization. There is
 * NO public/self-service provisioning path.
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
