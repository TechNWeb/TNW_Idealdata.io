<?php

namespace TNW\Idealdata\Test\Unit\Block\Adminhtml\System\Config;

use Magento\Backend\Block\Template\Context;
use Magento\Backend\Model\UrlInterface as BackendUrlInterface;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Module\ModuleListInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TNW\Idealdata\Block\Adminhtml\System\Config\Intro;

class IntroTest extends TestCase
{
    /**
     * @var Context|MockObject
     */
    private $context;

    /**
     * @var ProductMetadataInterface|MockObject
     */
    private $productMetadata;

    /**
     * @var ModuleListInterface|MockObject
     */
    private $moduleList;

    /**
     * @var BackendUrlInterface|MockObject
     */
    private $backendUrl;

    protected function setUp(): void
    {
        // The block Context has a very heavy constructor; a fully stubbed mock
        // is enough because AbstractBlock only stores what it hands back.
        $this->context = $this->getMockBuilder(Context::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->productMetadata = $this->getMockBuilder(ProductMetadataInterface::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->moduleList = $this->getMockBuilder(ModuleListInterface::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->backendUrl = $this->getMockBuilder(BackendUrlInterface::class)
            ->disableOriginalConstructor()
            ->getMock();
    }

    private function createBlock(): Intro
    {
        return new Intro(
            $this->context,
            $this->productMetadata,
            $this->moduleList,
            $this->backendUrl
        );
    }

    /**
     * Pull the query string off a built CTA URL as an assoc array.
     */
    private function queryOf(string $url): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return $query;
    }

    public function testTrialUrlCarriesFixedAttributionAndStoreContext(): void
    {
        $this->backendUrl->method('getBaseUrl')->willReturn('https://admin.metalmafia.com/admin/');
        $this->productMetadata->method('getVersion')->willReturn('2.4.7-p3');
        $this->productMetadata->method('getEdition')->willReturn('Community');
        $this->moduleList->method('getOne')->with('TNW_Idealdata')->willReturn(['setup_version' => '1.12.0']);

        $url = $this->createBlock()->getTrialUrl();

        $this->assertStringStartsWith('https://my.idealdata.io?', $url);
        $this->assertSame(
            [
                'utm_source' => 'magento_admin',
                'utm_medium' => 'extension',
                'utm_campaign' => 'ac_module_intro',
                'utm_content' => 'trial',
                'referrer' => 'admin.metalmafia.com',
                'pv' => '2.4.7-p3',
                'pe' => 'community',
                'mv' => '1.12.0',
            ],
            $this->queryOf($url)
        );
    }

    public function testWalkthroughUrlDiffersOnlyByUtmContent(): void
    {
        $this->backendUrl->method('getBaseUrl')->willReturn('https://clevelandequipment.com/');
        $this->productMetadata->method('getVersion')->willReturn('2.4.6');
        $this->productMetadata->method('getEdition')->willReturn('Enterprise');
        $this->moduleList->method('getOne')->willReturn(['setup_version' => '1.12.0']);

        $block = $this->createBlock();
        $trial = $this->queryOf($block->getTrialUrl());
        $walkthrough = $this->queryOf($block->getWalkthroughUrl());

        $this->assertSame('trial', $trial['utm_content']);
        $this->assertSame('walkthrough', $walkthrough['utm_content']);

        unset($trial['utm_content'], $walkthrough['utm_content']);
        $this->assertSame($trial, $walkthrough);

        $this->assertSame('clevelandequipment.com', $walkthrough['referrer']);
        $this->assertSame('enterprise', $walkthrough['pe']);
    }

    public function testAdminDomainFallsBackToRequestHostWithoutPort(): void
    {
        $request = $this->getMockBuilder(RequestInterface::class)
            ->disableOriginalConstructor()
            ->addMethods(['getHttpHost'])
            ->getMockForAbstractClass();
        $request->method('getHttpHost')->willReturn('Staging.Example.com:8443');

        $this->context->method('getRequest')->willReturn($request);
        $this->backendUrl->method('getBaseUrl')->willReturn('');
        $this->productMetadata->method('getVersion')->willReturn('2.4.7');
        $this->productMetadata->method('getEdition')->willReturn('Community');
        $this->moduleList->method('getOne')->willReturn(['setup_version' => '1.12.0']);

        $query = $this->queryOf($this->createBlock()->getTrialUrl());

        $this->assertSame('staging.example.com', $query['referrer']);
    }

    public function testUnresolvableContextValuesAreOmittedRatherThanSentBlank(): void
    {
        $request = $this->getMockBuilder(RequestInterface::class)
            ->disableOriginalConstructor()
            ->addMethods(['getHttpHost'])
            ->getMockForAbstractClass();
        $request->method('getHttpHost')->willReturn('');

        $this->context->method('getRequest')->willReturn($request);
        $this->backendUrl->method('getBaseUrl')->willReturn('');
        // Composer-based installs can report an unknown version.
        $this->productMetadata->method('getVersion')->willReturn('UNKNOWN');
        $this->productMetadata->method('getEdition')->willReturn('');
        $this->moduleList->method('getOne')->willReturn(null);

        $query = $this->queryOf($this->createBlock()->getTrialUrl());

        $this->assertSame(
            [
                'utm_source' => 'magento_admin',
                'utm_medium' => 'extension',
                'utm_campaign' => 'ac_module_intro',
                'utm_content' => 'trial',
            ],
            $query
        );
    }
}
