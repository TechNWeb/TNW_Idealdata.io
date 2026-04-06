<?php

declare(strict_types=1);

namespace TNW\Idealdata\Observer;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\HTTP\Header as HttpHeader;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\Sales\Api\Data\OrderPaymentInterface;
use Magento\Sales\Api\Data\TransactionInterface as Transaction;
use Magento\Sales\Api\TransactionRepositoryInterface;
use Psr\Log\LoggerInterface;

class CaptureFailedPaymentObserver implements ObserverInterface
{
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly TransactionRepositoryInterface $transactionRepository,
        private readonly RemoteAddress $remoteAddress,
        private readonly HttpHeader $httpHeader,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        try {
            /** @var OrderPaymentInterface $payment */
            $payment = $observer->getEvent()->getData('payment');
            if ($payment === null) {
                return;
            }

            if (!$this->isFailedTransaction($payment)) {
                return;
            }

            $order = $payment->getOrder();
            if ($order === null) {
                return;
            }

            $quoteId = (int) $order->getQuoteId();
            if ($quoteId <= 0) {
                return;
            }

            $connection = $this->resourceConnection->getConnection();
            $table = $this->resourceConnection->getTableName('tnw_quote_payment_transaction');

            $declineCode = $this->extractDeclineCode($payment);
            $declineReason = $this->extractDeclineReason($payment);

            $data = [
                'quote_id'         => $quoteId,
                'store_id'         => (int) $order->getStoreId(),
                'transaction_id'   => $payment->getLastTransId(),
                'status'           => $this->determineStatus($payment),
                'decline_code'     => $declineCode,
                'decline_reason'   => $declineReason,
                'decline_category' => $declineCode ? $this->categorizeDeclineCode($declineCode) : null,
                'gateway_message'  => $this->sanitizeGatewayMessage($payment),
                'payment_method'   => $payment->getMethod(),
                'card_type'        => $payment->getCcType(),
                'card_last_four'   => $payment->getCcLast4(),
                'card_expiry_month' => $payment->getCcExpMonth() ? (int) $payment->getCcExpMonth() : null,
                'card_expiry_year' => $payment->getCcExpYear() ? (int) $payment->getCcExpYear() : null,
                'amount'           => $payment->getAmountOrdered() ?? $payment->getAmountAuthorized(),
                'currency'         => $order->getBaseCurrencyCode(),
                'customer_id'      => $order->getCustomerId() ? (int) $order->getCustomerId() : null,
                'customer_email'   => $order->getCustomerEmail(),
                'is_guest'         => $order->getCustomerIsGuest() ? 1 : 0,
                'attempt_number'   => $this->getNextAttemptNumber($quoteId),
                'ip_address'       => $this->remoteAddress->getRemoteAddress(),
                'user_agent'       => $this->httpHeader->getHttpUserAgent(),
            ];

            $connection->insert($table, $data);
        } catch (\Throwable $e) {
            $this->logger->error(
                'TNW_Idealdata: Failed to capture failed payment transaction',
                ['exception' => $e->getMessage()]
            );
        }
    }

    private function isFailedTransaction(OrderPaymentInterface $payment): bool
    {
        $lastTransId = $payment->getLastTransId();
        if (empty($lastTransId)) {
            return false;
        }

        try {
            $order = $payment->getOrder();
            if ($order === null) {
                return false;
            }

            $transaction = $this->transactionRepository->getByTransactionId(
                $lastTransId,
                (int) $payment->getEntityId(),
                (int) $order->getEntityId()
            );

            if ($transaction && $transaction->getTxnType() === Transaction::TYPE_VOID) {
                return true;
            }

            if ($payment->getAdditionalInformation('is_transaction_declined') === true) {
                return true;
            }

            if ($payment->getAdditionalInformation('is_transaction_denied') === true) {
                return true;
            }

            // Check if transaction is closed with a failure indicator
            if ($transaction && $transaction->getIsClosed()
                && $payment->getAdditionalInformation('transaction_status') === 'declined') {
                return true;
            }
        } catch (\Throwable $e) {
            // If we can't find the transaction, it's not a failure we can track
            return false;
        }

        return false;
    }

    private function determineStatus(OrderPaymentInterface $payment): string
    {
        if ($payment->getAdditionalInformation('is_transaction_declined') === true) {
            return 'declined';
        }
        if ($payment->getAdditionalInformation('is_transaction_denied') === true) {
            return 'declined';
        }

        $gatewayStatus = $payment->getAdditionalInformation('transaction_status');
        if ($gatewayStatus !== null) {
            $normalized = strtolower((string) $gatewayStatus);
            if (str_contains($normalized, 'decline')) {
                return 'declined';
            }
            if (str_contains($normalized, 'error')) {
                return 'error';
            }
            if (str_contains($normalized, 'fail')) {
                return 'failed';
            }
        }

        return 'failed';
    }

    private function extractDeclineCode(OrderPaymentInterface $payment): ?string
    {
        $code = $payment->getAdditionalInformation('decline_code')
            ?? $payment->getAdditionalInformation('response_code')
            ?? $payment->getAdditionalInformation('result_code');

        return $code !== null ? (string) $code : null;
    }

    private function extractDeclineReason(OrderPaymentInterface $payment): ?string
    {
        $reason = $payment->getAdditionalInformation('decline_reason')
            ?? $payment->getAdditionalInformation('response_reason_text')
            ?? $payment->getAdditionalInformation('result_message');

        return $reason !== null ? (string) $reason : null;
    }

    private function categorizeDeclineCode(string $code): string
    {
        $hard = [
            'do_not_honor', 'pickup_card', 'lost_card', 'stolen_card',
            'restricted_card', 'security_violation', 'transaction_not_allowed',
            'blocked', 'revocation_of_authorization',
        ];

        $technical = [
            'gateway_timeout', 'network_error', 'processor_unavailable',
            'system_error', 'service_unavailable', 'timeout', 'unknown_error',
        ];

        $code = strtolower(str_replace([' ', '-'], '_', $code));

        if (in_array($code, $hard, true)) {
            return 'hard';
        }
        if (in_array($code, $technical, true)) {
            return 'technical';
        }

        return 'soft';
    }

    private function sanitizeGatewayMessage(OrderPaymentInterface $payment): ?string
    {
        $additionalInfo = $payment->getAdditionalInformation();
        if (empty($additionalInfo) || !is_array($additionalInfo)) {
            return null;
        }

        // Remove any potentially sensitive data
        $sensitiveKeys = [
            'cc_number', 'cc_cid', 'cc_ss_issue', 'cc_ss_start_month',
            'cc_ss_start_year', 'card_number', 'cvv', 'cvc', 'pan',
        ];

        $sanitized = array_diff_key($additionalInfo, array_flip($sensitiveKeys));

        $json = json_encode($sanitized, JSON_UNESCAPED_UNICODE);
        return $json !== false ? $json : null;
    }

    private function getNextAttemptNumber(int $quoteId): int
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('tnw_quote_payment_transaction');

        $count = (int) $connection->fetchOne(
            "SELECT COUNT(*) FROM {$table} WHERE quote_id = ?",
            [$quoteId]
        );

        return $count + 1;
    }
}
