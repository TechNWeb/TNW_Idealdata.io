<?php

declare(strict_types=1);

namespace TNW\Idealdata\Api\Data;

/**
 * @api
 */
interface TransactionDataInterface
{
    /**
     * @return int
     */
    public function getEntityId(): int;

    /**
     * @return string
     */
    public function getQuoteId(): string;

    /**
     * @return string|null
     */
    public function getTransactionId(): ?string;

    /**
     * @return string
     */
    public function getStatus(): string;

    /**
     * @return string|null
     */
    public function getDeclineCode(): ?string;

    /**
     * @return string|null
     */
    public function getDeclineReason(): ?string;

    /**
     * @return string|null
     */
    public function getDeclineCategory(): ?string;

    /**
     * @return string|null
     */
    public function getPaymentMethod(): ?string;

    /**
     * @return string|null
     */
    public function getCardType(): ?string;

    /**
     * @return string|null
     */
    public function getCardLastFour(): ?string;

    /**
     * @return float|null
     */
    public function getAmount(): ?float;

    /**
     * @return string|null
     */
    public function getCurrency(): ?string;

    /**
     * @return int
     */
    public function getAttemptNumber(): int;

    /**
     * @return string|null
     */
    public function getIpAddress(): ?string;

    /**
     * @return string|null
     */
    public function getCreatedAt(): ?string;

    /**
     * @return string|null
     */
    public function getUpdatedAt(): ?string;
}
