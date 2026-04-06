<?php

declare(strict_types=1);

namespace TNW\Idealdata\Plugin\Order;

use Magento\Framework\Api\AttributeValueFactory;
use Magento\Sales\Api\Data\OrderExtensionFactory;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderSearchResultInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Psr\Log\LoggerInterface;

class AddCustomAttributesPlugin
{
    public function __construct(
        private readonly OrderExtensionFactory $extensionFactory,
        private readonly AttributeValueFactory $attributeValueFactory,
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
            $this->attachCustomAttributes($result);
        } catch (\Throwable $e) {
            $this->logger->error(
                'TNW_Idealdata: Failed to attach custom attributes to order',
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
            foreach ($result->getItems() as $order) {
                $this->attachCustomAttributes($order);
            }
        } catch (\Throwable $e) {
            $this->logger->error(
                'TNW_Idealdata: Failed to attach custom attributes to order list',
                ['exception' => $e->getMessage()]
            );
        }

        return $result;
    }

    private function attachCustomAttributes(OrderInterface $order): void
    {
        $extensionAttributes = $order->getExtensionAttributes();
        if ($extensionAttributes === null) {
            $extensionAttributes = $this->extensionFactory->create();
        }

        // Only attach if not already set
        if (!empty($extensionAttributes->getCustomAttributes())) {
            return;
        }

        $customAttributes = [];
        $orderCustomAttributes = $order->getCustomAttributes();
        if (is_array($orderCustomAttributes)) {
            foreach ($orderCustomAttributes as $attribute) {
                $customAttributes[] = $attribute;
            }
        }

        // Also check getData for any additional custom attributes
        $data = $order->getData();
        $standardFields = $this->getStandardOrderFields();
        foreach ($data as $key => $value) {
            if (!in_array($key, $standardFields, true) && $value !== null && !is_array($value) && !is_object($value)) {
                // Check if this attribute is already in the list
                $alreadyIncluded = false;
                foreach ($customAttributes as $attr) {
                    if ($attr->getAttributeCode() === $key) {
                        $alreadyIncluded = true;
                        break;
                    }
                }

                if (!$alreadyIncluded && str_starts_with($key, 'custom_') || str_starts_with($key, 'tnw_')) {
                    $attributeValue = $this->attributeValueFactory->create();
                    $attributeValue->setAttributeCode($key);
                    $attributeValue->setValue((string) $value);
                    $customAttributes[] = $attributeValue;
                }
            }
        }

        $extensionAttributes->setCustomAttributes($customAttributes);
        $order->setExtensionAttributes($extensionAttributes);
    }

    /**
     * @return string[]
     */
    private function getStandardOrderFields(): array
    {
        return [
            'entity_id', 'state', 'status', 'coupon_code', 'protect_code',
            'shipping_description', 'is_virtual', 'store_id', 'customer_id',
            'base_discount_amount', 'base_discount_canceled', 'base_discount_invoiced',
            'base_discount_refunded', 'base_grand_total', 'base_shipping_amount',
            'base_shipping_canceled', 'base_shipping_invoiced', 'base_shipping_refunded',
            'base_shipping_tax_amount', 'base_subtotal', 'base_subtotal_canceled',
            'base_subtotal_invoiced', 'base_subtotal_refunded', 'base_tax_amount',
            'base_tax_canceled', 'base_tax_invoiced', 'base_tax_refunded',
            'base_to_global_rate', 'base_to_order_rate', 'base_total_canceled',
            'base_total_invoiced', 'base_total_invoiced_cost', 'base_total_offline_refunded',
            'base_total_online_refunded', 'base_total_paid', 'base_total_qty_ordered',
            'base_total_refunded', 'base_adjustment_negative', 'base_adjustment_positive',
            'base_currency_code', 'customer_email', 'customer_firstname', 'customer_lastname',
            'customer_middlename', 'customer_prefix', 'customer_suffix', 'customer_taxvat',
            'discount_amount', 'discount_canceled', 'discount_invoiced', 'discount_refunded',
            'grand_total', 'shipping_amount', 'shipping_canceled', 'shipping_invoiced',
            'shipping_refunded', 'shipping_tax_amount', 'store_to_base_rate',
            'store_to_order_rate', 'subtotal', 'subtotal_canceled', 'subtotal_invoiced',
            'subtotal_refunded', 'tax_amount', 'tax_canceled', 'tax_invoiced', 'tax_refunded',
            'total_canceled', 'total_invoiced', 'total_offline_refunded',
            'total_online_refunded', 'total_paid', 'total_qty_ordered', 'total_refunded',
            'adjustment_negative', 'adjustment_positive', 'applied_rule_ids',
            'base_discount_tax_compensation_amount', 'base_discount_tax_compensation_invoiced',
            'base_discount_tax_compensation_refunded', 'base_shipping_discount_amount',
            'base_shipping_discount_tax_compensation_amnt', 'base_shipping_incl_tax',
            'base_subtotal_incl_tax', 'base_total_due', 'billing_address_id',
            'can_ship_partially', 'can_ship_partially_item', 'coupon_rule_name',
            'created_at', 'customer_dob', 'customer_gender', 'customer_group_id',
            'customer_is_guest', 'customer_note', 'customer_note_notify',
            'discount_description', 'discount_tax_compensation_amount',
            'discount_tax_compensation_invoiced', 'discount_tax_compensation_refunded',
            'edit_increment', 'email_sent', 'ext_customer_id', 'ext_order_id',
            'forced_shipment_with_invoice', 'global_currency_code', 'hold_before_state',
            'hold_before_status', 'increment_id', 'is_virtual', 'order_currency_code',
            'original_increment_id', 'payment_auth_expiration', 'payment_authorization_amount',
            'quote_address_id', 'quote_id', 'relation_child_id', 'relation_child_real_id',
            'relation_parent_id', 'relation_parent_real_id', 'remote_ip',
            'shipping_discount_amount', 'shipping_discount_tax_compensation_amount',
            'shipping_incl_tax', 'shipping_method', 'store_currency_code', 'store_name',
            'subtotal_incl_tax', 'tax_amount', 'total_due', 'total_item_count',
            'total_qty_ordered', 'updated_at', 'weight', 'x_forwarded_for',
            'items', 'billing_address', 'payment', 'status_histories', 'extension_attributes',
        ];
    }
}
