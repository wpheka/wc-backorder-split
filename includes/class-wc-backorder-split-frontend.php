<?php
/**
 * WC Backorder Split Front-End
 *
 * @version 2.1.0
 * @package WCBS
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * WC_Backorder_Split_Frontend Class
 */
class WC_Backorder_Split_Frontend
{

    /**
     * Hook actions and filters
     *
     * @since 1.0.0
     */
    public static function init()
    {
        add_action('woocommerce_add_to_cart', array(__CLASS__, 'store_stock_quantity_in_cart_item'), 10, 6);
        add_action('woocommerce_checkout_create_order_line_item', array(__CLASS__, 'save_stock_quantity_to_order_item'), 10, 4);
        add_action('woocommerce_thankyou', array(__CLASS__, 'split_backorder_products'), 10, 1);
    }

    /**
     * Store stock quantity in cart item data if backorder is allowed.
     *
     * @since 1.0.0
     * @param string $cart_item_key Cart item key
     * @param int $product_id Product ID
     * @param int $quantity Quantity being added to cart
     * @param int $variation_id Variation ID (unused)
     * @param array $variation Variation data (unused)
     * @param array $cart_item_data Cart item data (unused)
     */
    public static function store_stock_quantity_in_cart_item($cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data)
    {
        try {
            $product = wc_get_product($product_id);
            
            // Validate product exists
            if (!$product) {
                error_log('WC Backorder Split: Invalid product ID ' . $product_id);
                return;
            }

            $stock_quantity = $product->get_stock_quantity();

            // Skip if backorders not allowed
            if (!$product->backorders_allowed()) {
                return;
            }

            // Store original stock quantity if customer wants more than available
            // This includes cases where stock is 0 or negative (already on backorder)
            if (is_numeric($stock_quantity) && $quantity > $stock_quantity) {
                if (isset(WC()->cart->cart_contents[$cart_item_key])) {
                    WC()->cart->cart_contents[$cart_item_key]['_stock_quantity_at_add'] = $stock_quantity;
                }
            }
        } catch (Exception $e) {
            error_log('WC Backorder Split - Error in store_stock_quantity_in_cart_item: ' . $e->getMessage());
        }
    }

    /**
     * Save stock quantity to order item meta data.
     *
     * @since 1.0.0
     * @param WC_Order_Item_Product $item Order item
     * @param string $cart_item_key Cart item key (unused)
     * @param array $values Cart item values
     * @param WC_Order $order Order object (unused)
     */
    public static function save_stock_quantity_to_order_item($item, $cart_item_key, $values, $order)
    {
        if (isset($values['_stock_quantity_at_add'])) {
            $item->add_meta_data('_stock_quantity_at_add', $values['_stock_quantity_at_add'], true);
        }
    }

    /**
     * Check if all products in an order are on backorder.
     *
     * @since 1.0.0
     * @param int $order_id Order ID to check
     * @return bool True if all products are on backorder, false otherwise
     */
    public static function are_all_products_in_backorder($order_id)
    {
        $order = wc_get_order($order_id);

        if (!$order) {
            return false;
        }

        $items = $order->get_items('line_item');
        if (empty($items)) {
            return false;
        }

        foreach ($items as $item) {
            $product = $item->get_product();
            if (!$product) {
                continue;
            }
            
            $stock_quantity_at_add = $item->get_meta('_stock_quantity_at_add');

            if ($stock_quantity_at_add !== '' || !$product->is_on_backorder()) {
                return false;
            }
        }

        return true;
    }

    /**
     * Split backorder products into a separate order.
     *
     * Creates a new order with 'backordered' status for products that are backordered,
     * while keeping in-stock items in the original order. Also copies shipping, taxes,
     * payment method, fees, and coupons to the backorder.
     *
     * @since 1.0.0
     * @param int $order_id Original order ID
     */
    public static function split_backorder_products($order_id)
    {
        try {
            $order = wc_get_order($order_id);

            if (!$order) {
                error_log('WC Backorder Split: Invalid order ID ' . $order_id);
                return;
            }

            // Prevent duplicate splits - check if order has already been processed
            if ($order->get_meta('_wcbs_backorder_id') || $order->get_meta('_wcbs_processed')) {
                return; // Order already processed, do nothing
            }

            // Allow developers to prevent splitting
            if (!apply_filters('wcbs_should_split_order', true, $order_id, $order)) {
                return;
            }

            // Check if all products are already on backorder
            if (self::are_all_products_in_backorder($order_id)) {
                $order->set_status('backordered');
                $order->add_meta_data('_wcbs_processed', 'yes', true); // Mark as processed
                $order->save();
                return;
            }

            $backorder_items = [];
            $original_order_items = [];

            // Fire action before splitting
            do_action('wcbs_before_split_order', $order_id, $order);

            // Process only line items for better performance
            $line_items = $order->get_items('line_item');
            foreach ($line_items as $item) {
                $product = $item->get_product();
                if (!$product) {
                    continue;
                }

                $quantity = $item->get_quantity();
                $stock_quantity_at_add = $item->get_meta('_stock_quantity_at_add');

                if ($stock_quantity_at_add !== '') {
                    $stock_quantity_at_add = (int)$stock_quantity_at_add;
                    if ($quantity > $stock_quantity_at_add && $product->backorders_allowed()) {
                        $backorder_quantity = $quantity - $stock_quantity_at_add;

                        if ($stock_quantity_at_add > 0) {
                            $original_order_items[] = [
                                'product' => $product,
                                'quantity' => $stock_quantity_at_add,
                                'item' => $item,
                            ];

                            $order->remove_item($item->get_id());
                        }

                        $backorder_items[] = [
                            'product' => $product,
                            'quantity' => $backorder_quantity,
                            'item' => $item,
                        ];
                    }
                } elseif ($product->is_on_backorder($quantity)) {
                    $backorder_items[] = [
                        'product' => $product,
                        'quantity' => $quantity,
                        'item' => $item,
                    ];
                }
            }

            // Allow developers to modify backorder items
            $backorder_items = apply_filters('wcbs_backorder_items', $backorder_items, $order_id, $order);

            // Re-add items with correct quantities to original order
            if (!empty($original_order_items)) {
                foreach ($original_order_items as $original_order_item) {
                    $item_id = $order->add_product($original_order_item['product'], $original_order_item['quantity']);

                    // Copy item meta data
                    if ($item_id && isset($original_order_item['item'])) {
                        $new_item = $order->get_item($item_id);
                        if ($new_item) {
                            self::copy_item_meta($original_order_item['item'], $new_item);
                        }
                    }
                }
            }

            // Recalculate and save the original order
            $order->calculate_totals();
            $order->save();

            // Create backorder order if there are backorder items
            if (!empty($backorder_items)) {
                $backorder_order = wc_create_order([
                    'customer_id' => $order->get_customer_id()
                ]);

                if (!$backorder_order) {
                    error_log('WC Backorder Split: Failed to create backorder order for order ID ' . $order_id);
                    return;
                }

                // Add products to backorder
                foreach ($backorder_items as $backorder_item) {
                    $item_id = $backorder_order->add_product($backorder_item['product'], $backorder_item['quantity']);

                    // Copy item meta data
                    if ($item_id && isset($backorder_item['item'])) {
                        $new_item = $backorder_order->get_item($item_id);
                        if ($new_item) {
                            self::copy_item_meta($backorder_item['item'], $new_item);
                        }
                    }
                }

                // Copy addresses
                $backorder_order->set_address($order->get_address('billing'), 'billing');
                $backorder_order->set_address($order->get_address('shipping'), 'shipping');
                $backorder_order->set_customer_id($order->get_customer_id());

                // Copy payment method
                $backorder_order->set_payment_method($order->get_payment_method());
                $backorder_order->set_payment_method_title($order->get_payment_method_title());

                // Copy shipping method
                self::copy_shipping_to_backorder($order, $backorder_order);

                // Copy fees
                self::copy_fees_to_backorder($order, $backorder_order);

                // Copy coupons
                self::copy_coupons_to_backorder($order, $backorder_order);

                // Set status
                $backorder_status = apply_filters('wcbs_backorder_status', 'backordered', $order_id, $order);
                $backorder_order->set_status($backorder_status);

                // Calculate totals
                $backorder_order->calculate_totals();
                $backorder_order->save();

                // Link orders together
                $order->add_meta_data('_wcbs_backorder_id', $backorder_order->get_id(), true);
                $backorder_order->add_meta_data('_wcbs_parent_order_id', $order->get_id(), true);
                $order->save();
                $backorder_order->save();

                // Add order notes
                $order->add_order_note(
                    sprintf(
                        __('Backorder items split into order #%s', 'wc-backorder-split'),
                        $backorder_order->get_id()
                    )
                );

                $backorder_order->add_order_note(
                    sprintf(
                        __('This order contains backordered items from order #%s', 'wc-backorder-split'),
                        $order->get_id()
                    )
                );

                // Fire action after backorder created
                do_action('wcbs_backorder_created', $backorder_order->get_id(), $order_id, $backorder_order, $order);

                // Log successful creation
                error_log('WC Backorder Split: Created backorder order #' . $backorder_order->get_id() . ' from original order #' . $order_id);
            }

            // Fire action after splitting complete
            do_action('wcbs_after_split_order', $order_id, $order);

        } catch (Exception $e) {
            error_log('WC Backorder Split - Error in split_backorder_products: ' . $e->getMessage());
            do_action('wcbs_split_order_error', $order_id, $e->getMessage());
        }
    }

    /**
     * Copy item meta data from one order item to another.
     *
     * @since 2.1.0
     * @param WC_Order_Item_Product $source_item Source order item
     * @param WC_Order_Item_Product $target_item Target order item
     */
    private static function copy_item_meta($source_item, $target_item)
    {
        $meta_data = $source_item->get_meta_data();
        foreach ($meta_data as $meta) {
            // Skip internal meta
            if (strpos($meta->key, '_') === 0) {
                continue;
            }
            $target_item->add_meta_data($meta->key, $meta->value, true);
        }
        $target_item->save();
    }

    /**
     * Copy shipping method and costs to backorder.
     *
     * @since 2.1.0
     * @param WC_Order $source_order Source order
     * @param WC_Order $target_order Target backorder order
     */
    private static function copy_shipping_to_backorder($source_order, $target_order)
    {
        $shipping_items = $source_order->get_items('shipping');
        foreach ($shipping_items as $shipping_item) {
            $item = new WC_Order_Item_Shipping();
            $item->set_method_title($shipping_item->get_method_title());
            $item->set_method_id($shipping_item->get_method_id());
            $item->set_total($shipping_item->get_total());
            $item->set_taxes($shipping_item->get_taxes());

            // Copy shipping meta
            $meta_data = $shipping_item->get_meta_data();
            foreach ($meta_data as $meta) {
                $item->add_meta_data($meta->key, $meta->value, true);
            }

            $target_order->add_item($item);
        }
    }

    /**
     * Copy fees to backorder.
     *
     * @since 2.1.0
     * @param WC_Order $source_order Source order
     * @param WC_Order $target_order Target backorder order
     */
    private static function copy_fees_to_backorder($source_order, $target_order)
    {
        $fee_items = $source_order->get_items('fee');
        foreach ($fee_items as $fee_item) {
            $item = new WC_Order_Item_Fee();
            $item->set_name($fee_item->get_name());
            $item->set_tax_class($fee_item->get_tax_class());
            $item->set_tax_status($fee_item->get_tax_status());
            $item->set_amount($fee_item->get_amount());
            $item->set_total($fee_item->get_total());
            $item->set_taxes($fee_item->get_taxes());

            $target_order->add_item($item);
        }
    }

    /**
     * Copy coupons to backorder.
     *
     * @since 2.1.0
     * @param WC_Order $source_order Source order
     * @param WC_Order $target_order Target backorder order
     */
    private static function copy_coupons_to_backorder($source_order, $target_order)
    {
        $coupon_items = $source_order->get_items('coupon');
        foreach ($coupon_items as $coupon_item) {
            $item = new WC_Order_Item_Coupon();
            $item->set_code($coupon_item->get_code());
            $item->set_discount($coupon_item->get_discount());
            $item->set_discount_tax($coupon_item->get_discount_tax());

            $target_order->add_item($item);
        }
    }
}
