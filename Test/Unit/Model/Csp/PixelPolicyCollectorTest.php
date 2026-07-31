<?php

declare(strict_types=1);

namespace TNW\Idealdata\Test\Unit\Model\Csp;

use Magento\Csp\Api\Data\PolicyInterface;
use Magento\Csp\Model\Policy\FetchPolicy;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TNW\Idealdata\Model\Csp\PixelPolicyCollector;
use TNW\Idealdata\Model\Pixel\Config;

class PixelPolicyCollectorTest extends TestCase
{
    /**
     * @var Config|MockObject
     */
    private $pixelConfig;

    /**
     * @var State|MockObject
     */
    private $appState;

    /**
     * @var PixelPolicyCollector
     */
    private $collector;

    protected function setUp(): void
    {
        $this->pixelConfig = $this->getMockBuilder(Config::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->appState = $this->getMockBuilder(State::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->appState->method('getAreaCode')->willReturn(Area::AREA_FRONTEND);

        $this->collector = new PixelPolicyCollector($this->pixelConfig, $this->appState);
    }

    public function testAddsLoaderAndIngestOriginsWhenEnabled(): void
    {
        $this->configure(true, 'https://pixel.idealdata.io/loader.js', 'https://my.idealdata.io/pixel-ingest');

        $policies = $this->collector->collect();

        $this->assertSame(
            ['https://pixel.idealdata.io'],
            $this->hostSourcesFor($policies, 'script-src')
        );
        // connect-src carries BOTH origins: the ingest calls, and the loader origin
        // itself — a `//# sourceMappingURL` fetch (loader.js.map) is checked against
        // connect-src rather than script-src.
        $this->assertSame(
            ['https://pixel.idealdata.io', 'https://my.idealdata.io'],
            $this->hostSourcesFor($policies, 'connect-src')
        );
        // img-src is defensive: only used if the SDK falls back to an image beacon.
        $this->assertSame(
            ['https://my.idealdata.io'],
            $this->hostSourcesFor($policies, 'img-src')
        );
    }

    /**
     * A re-provisioned (non-canonical) loader host must reach connect-src too, or the
     * loader's source-map fetch is blocked on every storefront page view with DevTools
     * open — the static etc/csp_whitelist.xml only covers the canonical origin.
     */
    public function testWhitelistsANonCanonicalLoaderOriginForSourceMapFetches(): void
    {
        $this->configure(true, 'https://cdn.example-staging.net/idealdata/loader.js', '');

        $this->assertSame(
            ['https://cdn.example-staging.net'],
            $this->hostSourcesFor($this->collector->collect(), 'connect-src')
        );
    }

    public function testAddsNothingWhenPixelDisabled(): void
    {
        $this->pixelConfig->method('isPixelEnabled')->willReturn(false);
        $this->pixelConfig->expects($this->never())->method('getLoaderUrl');
        $this->pixelConfig->expects($this->never())->method('getIngestBaseUrl');

        $defaults = [$this->policy('script-src')];

        $this->assertSame($defaults, $this->collector->collect($defaults));
    }

    /**
     * The collector is registered in the GLOBAL di.xml (registering it per-area
     * replaces core's whole collectors array), so every area instantiates it. Only
     * the storefront policy may be widened.
     *
     * @dataProvider nonFrontendAreaProvider
     */
    public function testAddsNothingOutsideTheFrontendArea(string $areaCode): void
    {
        $appState = $this->getMockBuilder(State::class)->disableOriginalConstructor()->getMock();
        $appState->method('getAreaCode')->willReturn($areaCode);
        $this->pixelConfig->expects($this->never())->method('isPixelEnabled');

        $collector = new PixelPolicyCollector($this->pixelConfig, $appState);
        $defaults = [$this->policy('script-src')];

        $this->assertSame($defaults, $collector->collect($defaults));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public function nonFrontendAreaProvider(): array
    {
        return [
            'admin' => [Area::AREA_ADMINHTML],
            'webapi rest' => [Area::AREA_WEBAPI_REST],
            'crontab' => [Area::AREA_CRONTAB],
        ];
    }

    /**
     * An unset area must not escape as an exception — a throwing policy collector
     * would take down the response, not just the pixel.
     */
    public function testAddsNothingWhenTheAreaIsNotSet(): void
    {
        $appState = $this->getMockBuilder(State::class)->disableOriginalConstructor()->getMock();
        $appState->method('getAreaCode')->willThrowException(new \LogicException('Area code is not set'));

        $collector = new PixelPolicyCollector($this->pixelConfig, $appState);
        $defaults = [$this->policy('img-src')];

        $this->assertSame($defaults, $collector->collect($defaults));
    }

    public function testPreservesDefaultPolicies(): void
    {
        $this->configure(true, 'https://pixel.idealdata.io/loader.js', 'https://my.idealdata.io/pixel-ingest');

        $default = $this->policy('font-src');
        $policies = $this->collector->collect([$default]);

        $this->assertSame($default, $policies[0]);
        // default + loader script-src/connect-src + ingest connect-src/img-src
        $this->assertCount(5, $policies);
    }

    /**
     * A malformed config value must never widen the policy — and must never
     * throw, or a bad value would take down every storefront page.
     *
     * @dataProvider unusableUrlProvider
     */
    public function testSkipsUnusableLoaderUrl(string $loaderUrl): void
    {
        $this->configure(true, $loaderUrl, 'https://my.idealdata.io/pixel-ingest');

        $policies = $this->collector->collect();

        $this->assertSame([], $this->hostSourcesFor($policies, 'script-src'));
        // The ingest origin is still whitelisted — one bad value does not
        // invalidate the other.
        $this->assertSame(['https://my.idealdata.io'], $this->hostSourcesFor($policies, 'connect-src'));
    }

    /**
     * @dataProvider unusableUrlProvider
     */
    public function testSkipsUnusableIngestUrl(string $ingestBase): void
    {
        $this->configure(true, 'https://pixel.idealdata.io/loader.js', $ingestBase);

        $policies = $this->collector->collect();

        // Only the loader origin survives on connect-src: the source-map fetch stays
        // covered even when the ingest base URL is unusable.
        $this->assertSame(['https://pixel.idealdata.io'], $this->hostSourcesFor($policies, 'connect-src'));
        $this->assertSame([], $this->hostSourcesFor($policies, 'img-src'));
        $this->assertSame(['https://pixel.idealdata.io'], $this->hostSourcesFor($policies, 'script-src'));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public function unusableUrlProvider(): array
    {
        return [
            'empty' => [''],
            'whitespace only' => ['   '],
            'no scheme' => ['pixel.idealdata.io/loader.js'],
            'scheme relative' => ['//pixel.idealdata.io/loader.js'],
            'not a url' => ['not-a-url'],
            'non http scheme' => ['javascript:alert(1)'],
            'data uri' => ['data:text/javascript,alert(1)'],
        ];
    }

    public function testKeepsNonDefaultPortAndDropsPathAndQuery(): void
    {
        $this->configure(
            true,
            'https://pixel.idealdata.io:8443/static/loader.js?v=3#frag',
            'http://ingest.local:8080/pixel-ingest'
        );

        $policies = $this->collector->collect();

        $this->assertSame(['https://pixel.idealdata.io:8443'], $this->hostSourcesFor($policies, 'script-src'));
        $this->assertSame(
            ['https://pixel.idealdata.io:8443', 'http://ingest.local:8080'],
            $this->hostSourcesFor($policies, 'connect-src')
        );
    }

    public function testNormalisesSchemeCase(): void
    {
        $this->configure(true, 'HTTPS://pixel.idealdata.io/loader.js', '');

        $this->assertSame(['https://pixel.idealdata.io'], $this->hostSourcesFor($this->collector->collect(), 'script-src'));
    }

    private function configure(bool $enabled, string $loaderUrl, string $ingestBase): void
    {
        $this->pixelConfig->method('isPixelEnabled')->willReturn($enabled);
        $this->pixelConfig->method('getLoaderUrl')->willReturn($loaderUrl);
        $this->pixelConfig->method('getIngestBaseUrl')->willReturn($ingestBase);
    }

    private function policy(string $id): FetchPolicy
    {
        return new FetchPolicy($id, false, ['https://example.com']);
    }

    /**
     * Flatten every host source the collector produced for one directive.
     *
     * @param PolicyInterface[] $policies
     * @return string[]
     */
    private function hostSourcesFor(array $policies, string $id): array
    {
        $hosts = [];
        foreach ($policies as $policy) {
            if ($policy instanceof FetchPolicy && $policy->getId() === $id) {
                $hosts = array_merge($hosts, $policy->getHostSources());
            }
        }

        return $hosts;
    }
}
