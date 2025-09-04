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

        $collection = $this->statusCollectionFactory->create()->joinStates();

        foreach ($collection as $item) {
            $status = $item->getStatus();
            $label = $item->getLabel();
            $state = (string) $item->getState();

            $result[] = new OrderStatus(
                $status,
                $label,
                $state,
            );
        }

        return $result;
    }
}
