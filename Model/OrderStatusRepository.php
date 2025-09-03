<?php

namespace TNW\Idealdata\Model;

use Magento\Sales\Model\ResourceModel\Order\Status\CollectionFactory as StatusCollectionFactory;
use TNW\Idealdata\Api\OrderStatusRepositoryInterface;
use TNW\Idealdata\Model\OrderStatus;

class OrderStatusRepository implements OrderStatusRepositoryInterface
{
    private StatusCollectionFactory $statusCollectionFactory;
    private \Magento\Sales\Model\Order\Config $orderConfig;

    public function __construct(
        StatusCollectionFactory $statusCollectionFactory,
        \Magento\Sales\Model\Order\Config $orderConfig
    ) {
        $this->statusCollectionFactory = $statusCollectionFactory;
        $this->orderConfig = $orderConfig;
    }

    public function getList(): array
    {
        $result = [];
        $states = $this->orderConfig->getStates();

        $collection = $this->statusCollectionFactory->create()->joinStates();

        foreach ($collection as $item) {
            $statusCode = $item->getStatus();
            $statusLabel = $item->getLabel();
            $stateCode = (string) $item->getState();
            $stateLabel = $states[$stateCode] ?? $stateCode;

            $result[] = new OrderStatus(
                $statusCode,
                $statusLabel,
                $stateCode,
                (string) $stateLabel
            );
        }

        return $result;
    }
}
