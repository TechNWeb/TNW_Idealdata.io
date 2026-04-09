<?php

declare(strict_types=1);

namespace TNW\Idealdata\Api;

/**
 * @api
 */
interface FailedTransactionRepositoryInterface
{
    /**
     * Get list of failed payment transactions.
     *
     * @param string $updatedAtFrom Transactions updated after this time (ISO 8601)
     * @param string|null $updatedAtTo Transactions updated before this time (ISO 8601)
     * @param string|null $status Filter: declined, failed, error
     * @param int|null $storeId Filter by store
     * @param bool|null $isGuest Filter guest-only or registered-only
     * @param int $pageSize Default 100, max 500
     * @param int $currentPage Default 1
     * @return \TNW\Idealdata\Api\Data\FailedTransactionSearchResultsInterface
     * @throws \Magento\Framework\Exception\InputException
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function getList(
        string $updatedAtFrom,
        ?string $updatedAtTo = null,
        ?string $status = null,
        ?int $storeId = null,
        ?bool $isGuest = null,
        int $pageSize = 100,
        int $currentPage = 1
    ): Data\FailedTransactionSearchResultsInterface;
}
