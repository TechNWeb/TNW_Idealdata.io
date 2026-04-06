<?php

declare(strict_types=1);

namespace TNW\Idealdata\Plugin\Order;

use Magento\Framework\App\ResourceConnection;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderItemExtensionFactory;
use Magento\Sales\Api\Data\OrderItemInterface;
use Magento\Sales\Api\Data\OrderSearchResultInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Psr\Log\LoggerInterface;

class AddOrderItemAttributesPlugin
{
    public function __construct(
        private readonly OrderItemExtensionFactory $itemExtensionFactory,
        private readonly ResourceConnection $resourceConnection,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterGet(
        OrderRepositoryInterface $subject,
        OrderInterface $result
    ): OrderInterface {
        try {
            $items = $result->getItems();
            if (!empty($items)) {
                $this->attachAttributesToItems($items);
            }
        } catch (\Throwable $e) {
            $this->logger->error(
                'TNW_Idealdata: Failed to attach attributes to order items',
                ['exception' => $e->getMessage()]
            );
        }

        return $result;
    }

    /**
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterGetList(
        OrderRepositoryInterface $subject,
        OrderSearchResultInterface $result
    ): OrderSearchResultInterface {
        try {
            $allItems = [];
            foreach ($result->getItems() as $order) {
                $orderItems = $order->getItems();
                if (!empty($orderItems)) {
                    foreach ($orderItems as $item) {
                        $allItems[] = $item;
                    }
                }
            }

            if (!empty($allItems)) {
                $this->attachAttributesToItems($allItems);
            }
        } catch (\Throwable $e) {
            $this->logger->error(
                'TNW_Idealdata: Failed to attach attributes to order items in list',
                ['exception' => $e->getMessage()]
            );
        }

        return $result;
    }

    /**
     * @param OrderItemInterface[] $items
     */
    private function attachAttributesToItems(array $items): void
    {
        $itemIds = [];
        foreach ($items as $item) {
            $itemId = (int) $item->getItemId();
            if ($itemId > 0) {
                $itemIds[] = $itemId;
            }
        }

        // Batch-load buy_request options
        $buyRequestMap = [];
        if (!empty($itemIds)) {
            $buyRequestMap = $this->loadBuyRequests($itemIds);
        }

        foreach ($items as $item) {
            $extensionAttributes = $item->getExtensionAttributes();
            if ($extensionAttributes === null) {
                $extensionAttributes = $this->itemExtensionFactory->create();
            }

            $extensionAttributes->setProductType($item->getProductType() ?? '');
            $extensionAttributes->setParentItemId(
                $item->getParentItemId() ? (int) $item->getParentItemId() : null
            );

            $itemId = (int) $item->getItemId();
            $buyRequest = $buyRequestMap[$itemId] ?? null;
            $extensionAttributes->setBuyRequest($buyRequest);

            $item->setExtensionAttributes($extensionAttributes);
        }
    }

    /**
     * @param int[] $itemIds
     * @return array<int, string|null>
     */
    private function loadBuyRequests(array $itemIds): array
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('sales_order_item_option');

        $select = $connection->select()
            ->from($table, ['order_item_id', 'value'])
            ->where('order_item_id IN (?)', $itemIds)
            ->where('code = ?', 'info_buyRequest');

        $rows = $connection->fetchAll($select);
        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['order_item_id']] = $row['value'] ?? null;
        }

        return $map;
    }
}
