<?php
namespace TNW\Idealdata\Api\Data;

/**
 * Interface OrderStatusInterface
 * @api
 */
interface OrderStatusInterface
{
    /**
     * Get order status code
     *
     * @return string
     */
    public function getStatus(): string;

    /**
     * Get order status label
     *
     * @return string
     */
    public function getLabel(): string;

    /**
     * Get order state code
     *
     * @return string
     */
    public function getState(): string;
}
