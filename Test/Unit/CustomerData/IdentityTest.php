<?php

namespace TNW\Idealdata\Test\Unit\CustomerData;

use Magento\Customer\Model\Session as CustomerSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TNW\Idealdata\CustomerData\Identity;

class IdentityTest extends TestCase
{
    /**
     * @var CustomerSession|MockObject
     */
    private $customerSession;

    /**
     * @var Identity
     */
    private $identity;

    protected function setUp(): void
    {
        // Full mock (all public methods stubbable) — Session has a heavy
        // constructor, so disable it. isLoggedIn() and getCustomerId() are both
        // real public methods on the Session class.
        $this->customerSession = $this->getMockBuilder(CustomerSession::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->identity = new Identity($this->customerSession);
    }

    public function testReturnsCustomerIdWhenLoggedIn(): void
    {
        $this->customerSession->method('isLoggedIn')->willReturn(true);
        $this->customerSession->method('getCustomerId')->willReturn(42);

        $this->assertSame(['customer_id' => 42], $this->identity->getSectionData());
    }

    public function testCastsStringCustomerIdToInt(): void
    {
        $this->customerSession->method('isLoggedIn')->willReturn(true);
        $this->customerSession->method('getCustomerId')->willReturn('42');

        $this->assertSame(['customer_id' => 42], $this->identity->getSectionData());
    }

    public function testReturnsEmptyForGuest(): void
    {
        $this->customerSession->method('isLoggedIn')->willReturn(false);
        $this->customerSession->expects($this->never())->method('getCustomerId');

        $this->assertSame([], $this->identity->getSectionData());
    }

    public function testReturnsEmptyWhenLoggedInButNoUsableId(): void
    {
        $this->customerSession->method('isLoggedIn')->willReturn(true);
        $this->customerSession->method('getCustomerId')->willReturn(0);

        $this->assertSame([], $this->identity->getSectionData());
    }
}
