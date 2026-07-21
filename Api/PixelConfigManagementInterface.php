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
     * TOKEN PRESERVE (drift-heal path): when `$enabled` is true and `$token` is an
     * EMPTY string, the currently-stored token is PRESERVED (not overwritten) —
     * this lets the IdealData app re-push corrected `enabled`/URLs to heal config
     * drift WITHOUT minting/handling a raw token (which it never stores). If no
     * token is stored yet, an empty token while enabling is still rejected.
     *
     * @param bool $enabled Whether the module should inject the pixel snippet.
     * @param string $ingestBase Public ingest base URL (incl. the /pixel-ingest prefix).
     * @param string $loaderUrl Fully-qualified URL of the static loader bundle.
     * @param string $token Raw opaque pixel token (`idpx_…`), stored as-is. Empty +
     *                      enabled = preserve the stored token (see above).
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

    /**
     * Read the LIVE storefront-pixel config back from this store's module config
     * (`tnw_idealdata_pixel/general/*`). The read side of {@see save()} — the
     * IdealData app reconciles its stored mirror against reality and heals drift.
     *
     * The raw token is NEVER returned: only whether one is stored + a SHA-256
     * fingerprint (see {@see \TNW\Idealdata\Api\Data\PixelConfigStateInterface}).
     *
     * @return \TNW\Idealdata\Api\Data\PixelConfigStateInterface
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function get(): Data\PixelConfigStateInterface;
}
