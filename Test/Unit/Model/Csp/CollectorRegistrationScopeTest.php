<?php

declare(strict_types=1);

namespace TNW\Idealdata\Test\Unit\Model\Csp;

use PHPUnit\Framework\TestCase;

/**
 * Guards the SCOPE the pixel CSP collector is registered in.
 *
 * Magento merges object-manager arguments across scopes with array_replace() keyed
 * by argument NAME, not by array item (Magento\Framework\ObjectManager\Config\
 * Config::_mergeConfiguration). Declaring the `collectors` argument of
 * Magento\Csp\Model\CompositePolicyCollector from an AREA di.xml therefore REPLACES
 * the entire array core declares in the global scope, leaving PixelPolicyCollector
 * as the storefront's only collector — no 'self'/'unsafe-inline'/data: config
 * defaults, no csp_whitelist.xml hosts, no per-request nonce. The storefront policy
 * collapses to the three IdealData origins and the browser blocks every store
 * script, image and XHR (shipped in 1.12, fixed in 1.13).
 *
 * Item names merge only within a single scope, so the registration must live in the
 * module's global etc/di.xml, alongside core's own declaration.
 */
class CollectorRegistrationScopeTest extends TestCase
{
    private const COLLECTOR_TYPE = 'Magento\Csp\Model\CompositePolicyCollector';

    public function testGlobalDiRegistersThePixelCollector(): void
    {
        $arguments = $this->collectorArgumentsIn($this->moduleRoot() . '/etc/di.xml');

        $this->assertNotNull(
            $arguments,
            'etc/di.xml must register PixelPolicyCollector into ' . self::COLLECTOR_TYPE
        );
        $this->assertStringContainsString('TNW\Idealdata\Model\Csp\PixelPolicyCollector', $arguments);
    }

    /**
     * Covers every area-scoped di.xml the module ships, so a future etc/<area>/di.xml
     * is guarded without touching this test.
     */
    public function testNoAreaDiDeclaresCollectorArguments(): void
    {
        $areaDiFiles = glob($this->moduleRoot() . '/etc/*/di.xml') ?: [];
        $this->assertNotEmpty($areaDiFiles, 'expected at least etc/frontend/di.xml');

        foreach ($areaDiFiles as $diFile) {
            $this->assertNull(
                $this->collectorArgumentsIn($diFile),
                sprintf(
                    '%s declares arguments for %s. An area scope REPLACES the global'
                    . ' `collectors` array instead of merging into it, which disables every'
                    . ' core CSP collector in that area. Register it in etc/di.xml instead.',
                    substr($diFile, strlen($this->moduleRoot()) + 1),
                    self::COLLECTOR_TYPE
                )
            );
        }
    }

    /**
     * The serialised <arguments> node the given di.xml declares for the composite
     * collector, or null when it declares none.
     */
    private function collectorArgumentsIn(string $diFile): ?string
    {
        $this->assertFileExists($diFile);

        $dom = new \DOMDocument();
        $this->assertTrue($dom->load($diFile), $diFile . ' is not well-formed XML');

        $xpath = new \DOMXPath($dom);
        $nodes = $xpath->query(
            sprintf('/config/type[@name="%s"]/arguments', self::COLLECTOR_TYPE)
        );

        if ($nodes === false || $nodes->length === 0) {
            return null;
        }

        return $dom->saveXML($nodes->item(0));
    }

    private function moduleRoot(): string
    {
        return dirname(__DIR__, 4);
    }
}
