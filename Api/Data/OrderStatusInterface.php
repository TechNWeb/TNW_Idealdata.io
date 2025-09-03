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
    public function getStatusCode(): string;

    /**
     * Get order status label
     *
     * @return string
     */
    public function getStatusLabel(): string;

    /**
     * Get order state code
     *
     * @return string
     */
    public function getStateCode(): string;

    /**
     * Get order state label
     *
     * @return string
     */
    public function getStateLabel(): string;
}
