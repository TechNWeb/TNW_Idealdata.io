<?php

declare(strict_types=1);

namespace TNW\Idealdata\Api\Data;

use Magento\Framework\Api\SearchResultsInterface;

/**
 * @api
 */
interface AbandonedCartSearchResultsInterface extends SearchResultsInterface
{
    /**
     * @return \TNW\Idealdata\Api\Data\AbandonedCartInterface[]
     */
    public function getItems();

    /**
     * @param \TNW\Idealdata\Api\Data\AbandonedCartInterface[] $items
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
