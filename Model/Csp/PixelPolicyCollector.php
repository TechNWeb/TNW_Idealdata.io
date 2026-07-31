<?php

declare(strict_types=1);

namespace TNW\Idealdata\Model\Csp;

use Magento\Csp\Api\PolicyCollectorInterface;
use Magento\Csp\Model\Policy\FetchPolicy;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use TNW\Idealdata\Model\Pixel\Config;

/**
 * Adds the CONFIGURED pixel origins to the storefront Content-Security-Policy.
 *
 * Stores running Magento_Csp in restrict mode block the loader `<script src>`
 * (script-src) and the SDK's ingest calls (connect-src) unless those origins are
 * whitelisted. `etc/csp_whitelist.xml` ships the canonical IdealData origins, but
 * it is static XML with no access to `core_config_data` — it cannot cover a store
 * the app provisioned with a non-canonical loader/ingest URL (staging, a legacy
 * CDN hostname, a per-tenant distribution). This collector closes that gap by
 * deriving the origins from whatever is actually configured, so a re-provisioned
 * URL is whitelisted without a module release.
 *
 * Gated exactly like the snippet injection (Config::isPixelEnabled): a disabled
 * or half-configured pixel widens the policy by nothing at all.
 *
 * Registered into Magento\Csp\Model\CompositePolicyCollector in the module's GLOBAL
 * etc/di.xml — registering it from an area scope replaces core's whole `collectors`
 * array and breaks the storefront policy (see the comment there). Because the
 * registration is global, this collector is instantiated in every area, so the
 * storefront-only scope is enforced here in PHP: the admin policy is untouched.
 *
 * Runs when the header is built by Magento\Csp\Observer\Render, i.e. on every
 * response, rather than depending on the loader block having rendered.
 */
class PixelPolicyCollector implements PolicyCollectorInterface
{
    public function __construct(
        private readonly Config $pixelConfig,
        private readonly State $appState
    ) {
    }

    /**
     * @param \Magento\Csp\Api\Data\PolicyInterface[] $defaultPolicies
     * @return \Magento\Csp\Api\Data\PolicyInterface[]
     */
    public function collect(array $defaultPolicies = []): array
    {
        if (!$this->isFrontend() || !$this->pixelConfig->isPixelEnabled()) {
            return $defaultPolicies;
        }

        $policies = $defaultPolicies;

        $loaderOrigin = $this->extractOrigin($this->pixelConfig->getLoaderUrl());
        if ($loaderOrigin !== null) {
            $policies[] = new FetchPolicy('script-src', false, [$loaderOrigin]);
        }

        $ingestOrigin = $this->extractOrigin($this->pixelConfig->getIngestBaseUrl());
        if ($ingestOrigin !== null) {
            // connect-src: the SDK's /config + /collect XHR/beacon calls.
            $policies[] = new FetchPolicy('connect-src', false, [$ingestOrigin]);
            // img-src: only used if the SDK ever falls back to an image beacon.
            // Costs nothing when unused and cannot affect any other origin.
            $policies[] = new FetchPolicy('img-src', false, [$ingestOrigin]);
        }

        return $policies;
    }

    /**
     * The pixel is a storefront feature, so only the storefront policy is widened.
     *
     * State::getAreaCode() throws when no area has been set yet (early bootstrap,
     * some CLI paths); treat that as "not the storefront" rather than letting it
     * escape — a policy collector must never break the response.
     */
    private function isFrontend(): bool
    {
        try {
            return $this->appState->getAreaCode() === Area::AREA_FRONTEND;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Reduce a configured URL to a CSP host source (scheme://host[:port]).
     *
     * Returns null for anything that is not an absolute http(s) URL — a malformed
     * value must never widen the policy, and must never throw: a bad config value
     * would otherwise take down every storefront page, not just the pixel.
     */
    private function extractOrigin(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }

        $parts = parse_url($url);
        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);
        if ($scheme !== 'https' && $scheme !== 'http') {
            return null;
        }

        $origin = $scheme . '://' . $parts['host'];
        if (!empty($parts['port'])) {
            $origin .= ':' . $parts['port'];
        }

        return $origin;
    }
}
