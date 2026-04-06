<?php

declare(strict_types=1);

namespace TNW\Idealdata\Model\Data;

use Magento\Framework\Api\SearchResults;
use TNW\Idealdata\Api\Data\FailedTransactionSearchResultsInterface;

class FailedTransactionSearchResults extends SearchResults implements FailedTransactionSearchResultsInterface
{
    /**
     * @var array
     */
    private array $pageInfo = [];

    /**
     * @return array
     */
    public function getPageInfo(): array
    {
        return $this->pageInfo;
    }

    /**
     * @param array $pageInfo
     * @return $this
     */
    public function setPageInfo(array $pageInfo): self
    {
        $this->pageInfo = $pageInfo;
        return $this;
    }
}
