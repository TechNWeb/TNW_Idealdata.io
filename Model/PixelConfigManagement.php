<?php

declare(strict_types=1);

namespace TNW\Idealdata\Model;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Exception\InputException;
use Psr\Log\LoggerInterface;
use TNW\Idealdata\Api\Data\PixelConfigResultInterface;
use TNW\Idealdata\Api\Data\PixelConfigResultInterfaceFactory;
use TNW\Idealdata\Api\Data\PixelConfigStateInterface;
use TNW\Idealdata\Api\Data\PixelConfigStateInterfaceFactory;
use TNW\Idealdata\Api\PixelConfigManagementInterface;
use TNW\Idealdata\Block\Pixel\Loader;

/**
 * Writes the storefront-pixel config (`tnw_idealdata_pixel/general/*`) at the
 * default scope from a provisioning call, then flushes the config + full-page
 * caches so the loader block reads the new values on the next storefront request.
 *
 * Programmatic `WriterInterface::save` does NOT auto-flush — without the cache
 * clean, `ScopeConfigInterface` keeps returning the stale value, and (with FPC
 * on) already-rendered pages keep serving the previous snippet. Both are cleaned
 * here. (The rotated-token grace window in IdealData covers the brief lag before
 * FPC rebuilds, so presence is never interrupted by a re-push.)
 */
class PixelConfigManagement implements PixelConfigManagementInterface
{
    public function __construct(
        private readonly WriterInterface $configWriter,
        private readonly TypeListInterface $cacheTypeList,
        private readonly PixelConfigResultInterfaceFactory $resultFactory,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly PixelConfigStateInterfaceFactory $stateFactory,
        private readonly LoggerInterface $logger
    ) {
    }

    public function save(
        bool $enabled,
        string $ingestBase,
        string $loaderUrl,
        string $token
    ): PixelConfigResultInterface {
        $ingestBase = trim($ingestBase);
        $loaderUrl = trim($loaderUrl);
        $token = trim($token);

        // Token-preserve (drift-heal path): enabling with an EMPTY token means
        // "keep the stored token" — so the IdealData app can re-push corrected
        // enabled/URLs without minting/handling a raw token (it never stores one).
        $preserveToken = false;
        if ($enabled && $token === '') {
            $storedToken = trim((string) $this->scopeConfig->getValue(Loader::XML_PATH_TOKEN));
            if ($storedToken === '') {
                throw new InputException(__('token is required when the pixel is enabled.'));
            }
            $preserveToken = true;
        }

        // When enabling, the boot-config the loader needs must be present + valid.
        // When disabling, empty values are allowed (they clear the snippet).
        if ($enabled) {
            if (!$this->isValidUrl($ingestBase)) {
                throw new InputException(__('ingestBase must be a valid absolute URL.'));
            }
            if (!$this->isValidUrl($loaderUrl)) {
                throw new InputException(__('loaderUrl must be a valid absolute URL.'));
            }
        } else {
            // Reject malformed non-empty URLs even while disabling — never persist junk.
            if ($ingestBase !== '' && !$this->isValidUrl($ingestBase)) {
                throw new InputException(__('ingestBase must be a valid absolute URL.'));
            }
            if ($loaderUrl !== '' && !$this->isValidUrl($loaderUrl)) {
                throw new InputException(__('loaderUrl must be a valid absolute URL.'));
            }
        }

        // Write at the default scope (scopeId 0) — store-wide unless overridden.
        $this->configWriter->save(Loader::XML_PATH_ENABLED, $enabled ? '1' : '0');
        $this->configWriter->save(Loader::XML_PATH_INGEST_BASE_URL, $ingestBase);
        $this->configWriter->save(Loader::XML_PATH_LOADER_URL, $loaderUrl);
        if (!$preserveToken) {
            $this->configWriter->save(Loader::XML_PATH_TOKEN, $token);
        }

        // Required: WriterInterface::save does NOT auto-flush; also bust FPC so the
        // change reaches already-cached pages.
        $this->cacheTypeList->cleanType('config');
        $this->cacheTypeList->cleanType('full_page');

        $this->logger->info(
            sprintf(
                '[TNW_Idealdata] pixel config provisioned (enabled=%s, tokenPreserved=%s)',
                $enabled ? '1' : '0',
                $preserveToken ? '1' : '0'
            )
        );

        /** @var PixelConfigResultInterface $result */
        $result = $this->resultFactory->create();
        $result->setSuccess(true);
        $result->setMessage($enabled ? 'Pixel config saved and enabled.' : 'Pixel config saved and disabled.');

        return $result;
    }

    public function get(): PixelConfigStateInterface
    {
        // Read at the default scope — mirrors where save() writes (scopeId 0), so
        // the read-back reflects exactly what the app provisioned.
        $enabled = $this->scopeConfig->isSetFlag(Loader::XML_PATH_ENABLED);
        $ingestBase = trim((string) $this->scopeConfig->getValue(Loader::XML_PATH_INGEST_BASE_URL));
        $loaderUrl = trim((string) $this->scopeConfig->getValue(Loader::XML_PATH_LOADER_URL));
        $token = trim((string) $this->scopeConfig->getValue(Loader::XML_PATH_TOKEN));

        $tokenPresent = $token !== '';

        /** @var PixelConfigStateInterface $state */
        $state = $this->stateFactory->create();
        $state->setEnabled($enabled);
        $state->setIngestBase($ingestBase);
        $state->setLoaderUrl($loaderUrl);
        $state->setTokenPresent($tokenPresent);
        // Never return the raw token — only a SHA-256 fingerprint for drift compare.
        $state->setTokenSha256($tokenPresent ? hash('sha256', $token) : null);

        return $state;
    }

    private function isValidUrl(string $url): bool
    {
        return $url !== '' && filter_var($url, FILTER_VALIDATE_URL) !== false;
    }
}
