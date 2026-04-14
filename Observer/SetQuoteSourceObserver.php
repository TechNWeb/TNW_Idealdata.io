<?php

declare(strict_types=1);

namespace TNW\Idealdata\Observer;

use Magento\Framework\App\State;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;

/**
 * Sets tnw_quote_source on quotes at submission time for non-admin contexts.
 * Admin-created orders are handled by RecordSourceCartPlugin (aroundCreateOrder).
 */
class SetQuoteSourceObserver implements ObserverInterface
{
    public function __construct(
        private readonly State $appState,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        try {
            $quote = $observer->getEvent()->getQuote();
            if (!$quote || $quote->getData('tnw_quote_source')) {
                return;
            }

            $areaCode = null;
            try {
                $areaCode = $this->appState->getAreaCode();
            } catch (\Throwable $e) {
                // area not initialized
            }

            // Admin orders are handled by RecordSourceCartPlugin — skip here
            if ($areaCode === 'adminhtml') {
                return;
            }

            if ($quote->getOrigOrderId()) {
                $quote->setData('tnw_quote_source', 'reorder');
            } elseif (in_array($areaCode, ['webapi_rest', 'webapi_soap'], true)) {
                $quote->setData('tnw_quote_source', 'api');
            } else {
                $quote->setData('tnw_quote_source', 'customer_frontend');
            }
        } catch (\Throwable $e) {
            $this->logger->warning(
                'TNW_Idealdata: Failed to set tnw_quote_source',
                ['exception' => $e->getMessage()]
            );
        }
    }
}
