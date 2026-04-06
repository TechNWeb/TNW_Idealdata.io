<?php

declare(strict_types=1);

namespace TNW\Idealdata\Api;

/**
 * @api
 */
interface AbandonedCartRepositoryInterface
{
    /**
     * Get list of abandoned carts (active carts past inactivity threshold).
     *
     * @param int $inactivityThresholdMinutes Carts not updated for this many minutes are considered abandoned
     * @param string|null $updatedAtFrom Only carts whose updated_at >= this value (ISO 8601)
     * @param int $pageSize Page size (default 100, max 500)
     * @param int $currentPage Current page (default 1)
     * @return \TNW\Idealdata\Api\Data\AbandonedCartSearchResultsInterface
     * @throws \Magento\Framework\Exception\InputException
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function getList(
        int $inactivityThresholdMinutes,
        ?string $updatedAtFrom = null,
        int $pageSize = 100,
        int $currentPage = 1
    ): Data\AbandonedCartSearchResultsInterface;
}
