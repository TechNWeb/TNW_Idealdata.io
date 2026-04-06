<?php

declare(strict_types=1);

namespace TNW\Idealdata\Api\Data;

use Magento\Framework\Api\SearchResultsInterface;

/**
 * @api
 */
interface FailedTransactionSearchResultsInterface extends SearchResultsInterface
{
    /**
     * @return \TNW\Idealdata\Api\Data\FailedTransactionResultInterface[]
     */
    public function getItems();

    /**
     * @param \TNW\Idealdata\Api\Data\FailedTransactionResultInterface[] $items
     * @return $this
     */
    public function setItems(array $items);

    /**
     * @return array
     */
    public function getPageInfo(): array;

    /**
     * @param array $pageInfo
     * @return $this
     */
    public function setPageInfo(array $pageInfo): self;
}
