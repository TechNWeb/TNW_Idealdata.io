<?php

declare(strict_types=1);

namespace TNW\Idealdata\Test\Unit\CustomerData;

use PHPUnit\Framework\TestCase;
use TNW\Idealdata\CustomerData\Identity;

/**
 * Guards the identity section NAME across every place that has to agree on it.
 *
 * The name is a cross-repo contract: it is the object-manager key the section
 * source is registered under, the key Magento invalidates, the
 * localStorage['mage-cache-storage'] key the pixel SDK reads, and the fallback
 * baked into the cart-tracking bridge for the (unexpected) case where the script
 * tag carries no data-identity-section attribute. A rename that misses one of
 * them fails silently on the storefront — no error, just no identity — so it
 * fails here instead.
 */
class IdentitySectionNameTest extends TestCase
{
    public function testTheConstantIsTheDocumentedName(): void
    {
        $this->assertSame('tnw-idealdata-identity', Identity::SECTION_NAME);
    }

    public function testFrontendDiRegistersTheSourceUnderThatName(): void
    {
        $xpath = $this->xpathFor($this->moduleRoot() . '/etc/frontend/di.xml');
        $nodes = $xpath->query(
            '/config/type[@name="Magento\Customer\CustomerData\SectionPool"]'
            . '/arguments/argument[@name="sectionSourceMap"]/item'
        );

        $this->assertNotFalse($nodes);
        $this->assertSame(1, $nodes->length, 'expected exactly one registered section source');
        $this->assertSame(Identity::SECTION_NAME, $nodes->item(0)->getAttribute('name'));
        $this->assertSame(Identity::class, trim($nodes->item(0)->textContent));
    }

    public function testSectionsXmlInvalidatesThatName(): void
    {
        $xpath = $this->xpathFor($this->moduleRoot() . '/etc/frontend/sections.xml');
        $nodes = $xpath->query('/config/action/section');

        $this->assertNotFalse($nodes);
        $this->assertGreaterThan(0, $nodes->length, 'expected invalidation actions');

        foreach ($nodes as $node) {
            $this->assertSame(Identity::SECTION_NAME, $node->getAttribute('name'));
        }
    }

    public function testTheCartTrackingBridgeFallsBackToThatName(): void
    {
        $bridge = $this->moduleRoot() . '/view/frontend/web/js/cart-tracking.js';
        $this->assertFileExists($bridge);

        $this->assertStringContainsString(
            "|| '" . Identity::SECTION_NAME . "'",
            (string) file_get_contents($bridge),
            'cart-tracking.js must default data-identity-section to ' . Identity::SECTION_NAME
        );
    }

    private function xpathFor(string $xmlFile): \DOMXPath
    {
        $this->assertFileExists($xmlFile);

        $dom = new \DOMDocument();
        $this->assertTrue($dom->load($xmlFile), $xmlFile . ' is not well-formed XML');

        return new \DOMXPath($dom);
    }

    private function moduleRoot(): string
    {
        return dirname(__DIR__, 3);
    }
}
