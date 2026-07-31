<?php

declare(strict_types=1);

namespace TNW\Idealdata\Test\Unit\Block\Pixel;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use TNW\Idealdata\Block\Pixel\Loader;
use TNW\Idealdata\CustomerData\Identity;

/**
 * Guards how the cart-tracking bridge reaches the storefront.
 *
 * The bridge is what makes cart capture work on themes that dispatch no
 * add-to-cart JavaScript event (Hyvä), so three properties matter and are all
 * invisible at runtime if broken:
 *
 *  - it is injected by pixel/loader.phtml, BEHIND the isPixelEnabled() guard, so
 *    a disabled or half-configured pixel still puts nothing on the storefront;
 *  - it goes through $secureRenderer, like the other two tags, so it carries the
 *    request nonce on stores running nonce-based CSP (without it the browser
 *    silently drops the tag);
 *  - it is configured through data-attributes, which keeps the asset static and
 *    cacheable and keeps the template free of a third inline script.
 */
class CartTrackingInjectionTest extends TestCase
{
    private string $template;

    /**
     * The same template with comments removed. The file's own documentation
     * discusses hand-written `<script>` tags and the renderer, so structural
     * assertions have to look at code only.
     */
    private string $code;

    protected function setUp(): void
    {
        $templateFile = $this->moduleRoot() . '/view/frontend/templates/pixel/loader.phtml';
        $this->assertFileExists($templateFile);
        $this->template = (string) file_get_contents($templateFile);
        $this->code = php_strip_whitespace($templateFile);
    }

    public function testTheBridgeAssetExists(): void
    {
        $this->assertFileExists($this->moduleRoot() . '/view/frontend/web/js/cart-tracking.js');
    }

    public function testTheBlockExposesTheBridgeConfiguration(): void
    {
        $block = (new ReflectionClass(Loader::class))->newInstanceWithoutConstructor();

        $this->assertSame(Identity::SECTION_NAME, $block->getIdentitySectionName());
        $this->assertTrue(method_exists($block, 'getCartTrackingUrl'));
        $this->assertTrue(method_exists($block, 'getSectionLoadUrl'));
    }

    public function testTheTemplateInjectsTheBridgeWithItsConfiguration(): void
    {
        foreach (
            [
                '$block->getCartTrackingUrl()',
                '$block->getSectionLoadUrl()',
                '$block->getIdentitySectionName()',
                'data-idealdata-cart-tracking',
                'data-section-load-url',
                'data-identity-section',
            ] as $needle
        ) {
            $this->assertStringContainsString($needle, $this->template);
        }
    }

    public function testTheBridgeIsRenderedThroughTheSecureRenderer(): void
    {
        $this->assertSame(
            3,
            substr_count($this->code, '$secureRenderer->renderTag('),
            'expected exactly three nonce-stamped tags: settings, loader, cart bridge'
        );
        $this->assertStringNotContainsString(
            '<script',
            $this->code,
            'no hand-written <script> tag may bypass the CSP nonce'
        );
    }

    public function testTheBridgeIsInjectedBehindThePixelEnabledGuard(): void
    {
        $guardPosition = strpos($this->template, 'if (!$block->isPixelEnabled()) {');
        $bridgePosition = strpos($this->template, '$block->getCartTrackingUrl()');

        $this->assertNotFalse($guardPosition, 'the isPixelEnabled() early return must be present');
        $this->assertNotFalse($bridgePosition);
        $this->assertLessThan(
            $bridgePosition,
            $guardPosition,
            'the bridge must be injected after the isPixelEnabled() early return, not before it'
        );
    }

    private function moduleRoot(): string
    {
        return dirname(__DIR__, 4);
    }
}
