<?php

declare(strict_types=1);

namespace TNW\Idealdata\Test\Unit\Model;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Exception\InputException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use TNW\Idealdata\Api\Data\PixelConfigResultInterface;
use TNW\Idealdata\Api\Data\PixelConfigResultInterfaceFactory;
use TNW\Idealdata\Block\Pixel\Loader;
use TNW\Idealdata\Model\Data\PixelConfigResult;
use TNW\Idealdata\Model\PixelConfigManagement;

class PixelConfigManagementTest extends TestCase
{
    /** @var WriterInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $configWriter;

    /** @var TypeListInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $cacheTypeList;

    /** @var LoggerInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $logger;

    private PixelConfigManagement $management;

    protected function setUp(): void
    {
        $this->configWriter = $this->getMockBuilder(WriterInterface::class)->getMock();
        $this->cacheTypeList = $this->getMockBuilder(TypeListInterface::class)->getMock();
        $this->logger = $this->getMockBuilder(LoggerInterface::class)->getMock();

        // The generated factory returns a fresh DataObject-backed result each call.
        $resultFactory = $this->getMockBuilder(PixelConfigResultInterfaceFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();
        $resultFactory->method('create')->willReturnCallback(static fn () => new PixelConfigResult());

        $this->management = new PixelConfigManagement(
            $this->configWriter,
            $this->cacheTypeList,
            $resultFactory,
            $this->logger
        );
    }

    public function testSaveWritesAllFourPathsAndFlushesCache(): void
    {
        $saved = [];
        $this->configWriter->method('save')->willReturnCallback(
            function ($path, $value) use (&$saved) {
                $saved[$path] = $value;
            }
        );

        $cleaned = [];
        $this->cacheTypeList->method('cleanType')->willReturnCallback(
            function ($type) use (&$cleaned) {
                $cleaned[] = $type;
            }
        );

        $result = $this->management->save(
            true,
            'https://app.example.com/pixel-ingest',
            'https://app.example.com/pixel/loader.js',
            'idpx_rawtoken'
        );

        $this->assertSame('1', $saved[Loader::XML_PATH_ENABLED]);
        $this->assertSame('https://app.example.com/pixel-ingest', $saved[Loader::XML_PATH_INGEST_BASE_URL]);
        $this->assertSame('https://app.example.com/pixel/loader.js', $saved[Loader::XML_PATH_LOADER_URL]);
        $this->assertSame('idpx_rawtoken', $saved[Loader::XML_PATH_TOKEN]);

        $this->assertContains('config', $cleaned);
        $this->assertContains('full_page', $cleaned);

        $this->assertInstanceOf(PixelConfigResultInterface::class, $result);
        $this->assertTrue($result->getSuccess());
    }

    public function testSaveIsIdempotentForTheSamePayload(): void
    {
        $calls = 0;
        $this->configWriter->method('save')->willReturnCallback(function () use (&$calls) {
            $calls++;
        });
        $this->cacheTypeList->method('cleanType');

        $args = ['https://app.example.com/pixel-ingest', 'https://app.example.com/pixel/loader.js', 'idpx_rawtoken'];
        $this->management->save(true, ...$args);
        $first = $calls;
        $this->management->save(true, ...$args);

        // Same paths written each time → same resulting state (4 writes per call).
        $this->assertSame(4, $first);
        $this->assertSame(8, $calls);
    }

    public function testSaveDisabledClearsWithoutRequiringToken(): void
    {
        $saved = [];
        $this->configWriter->method('save')->willReturnCallback(function ($path, $value) use (&$saved) {
            $saved[$path] = $value;
        });
        $this->cacheTypeList->method('cleanType');

        $result = $this->management->save(false, '', '', '');

        $this->assertSame('0', $saved[Loader::XML_PATH_ENABLED]);
        $this->assertSame('', $saved[Loader::XML_PATH_TOKEN]);
        $this->assertTrue($result->getSuccess());
    }

    public function testSaveThrowsWhenEnabledWithoutToken(): void
    {
        $this->configWriter->expects($this->never())->method('save');
        $this->expectException(InputException::class);

        $this->management->save(true, 'https://app.example.com/pixel-ingest', 'https://app.example.com/pixel/loader.js', '');
    }

    public function testSaveThrowsWhenEnabledWithInvalidIngestUrl(): void
    {
        $this->configWriter->expects($this->never())->method('save');
        $this->expectException(InputException::class);

        $this->management->save(true, 'not-a-url', 'https://app.example.com/pixel/loader.js', 'idpx_rawtoken');
    }

    public function testSaveThrowsWhenEnabledWithInvalidLoaderUrl(): void
    {
        $this->configWriter->expects($this->never())->method('save');
        $this->expectException(InputException::class);

        $this->management->save(true, 'https://app.example.com/pixel-ingest', 'not-a-url', 'idpx_rawtoken');
    }
}
