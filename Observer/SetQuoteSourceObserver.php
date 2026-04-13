<?php

declare(strict_types=1);

namespace TNW\Idealdata\Observer;

use Magento\Framework\App\State;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;

/**
 * Sets tnw_quote_source on any quote that reaches submission (cart → order
 * conversion) without a source label. Acts as a fallback when the admin plugin
 * hasn't already stamped the value:
 *   - quote.orig_order_id  → 'reorder'
 *   - area webapi_rest/soap → 'api'
 *   - area adminhtml        → 'admin_manual' (shouldn't normally reach here
 *                             because RecordSourceCartPlugin runs first)
 *   - frontend              → 'customer_frontend'
 *
 * IdealData reads tnw_quote_source to distinguish between customer-initiated
 * and admin-initiated carts/orders.
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

            if ($quote->getOrigOrderId()) {
                $quote->setData('tnw_quote_source', 'reorder');
                return;
            }

            $areaCode = null;
            try {
                $areaCode = $this->appState->getAreaCode();
            } catch (\Throwable $e) {
                // area not initialized
            }

            if (in_array($areaCode, ['webapi_rest', 'webapi_soap'], true)) {
                $quote->setData('tnw_quote_source', 'api');
            } elseif ($areaCode === 'adminhtml') {
                $quote->setData('tnw_quote_source', 'admin_manual');
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
