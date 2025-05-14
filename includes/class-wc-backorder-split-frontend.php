<?php
/**
 * WC Backorder Split Front-End
 *
 * @version 1.4
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
     */
    public static function init()
    {
        add_action('woocommerce_add_to_cart', array(__CLASS__, 'store_stock_quantity_in_cart_item'), 10, 6);
        add_action('woocommerce_checkout_create_order_line_item', array(__CLASS__, 'save_stock_quantity_to_order_item'), 10, 4);
        add_action('woocommerce_thankyou', array(__CLASS__, 'split_backorder_products'), 10, 1);
    }

    /**
     * Store stock quantity in cart item data if backorder is allowed.
     */
    public static function store_stock_quantity_in_cart_item($cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data)
    {
        $product = wc_get_product($product_id);
        $stock_quantity = $product->get_stock_quantity();

        if ($product->is_on_backorder() || !$product->backorders_allowed()) {
            return;
        }

        if ($quantity > $stock_quantity) {
            WC()->cart->cart_contents[$cart_item_key]['_stock_quantity_at_add'] = $stock_quantity;
        }
    }

    /**
     * Save stock quantity to order item meta data.
     */
    public static function save_stock_quantity_to_order_item($item, $cart_item_key, $values, $order)
    {
        if (isset($values['_stock_quantity_at_add'])) {
            $item->add_meta_data('_stock_quantity_at_add', $values['_stock_quantity_at_add'], true);
        }
    }

    /**
     * Check if all products in an order are on backorder.
     */
    public static function are_all_products_in_backorder($order_id)
    {
        $order = wc_get_order($order_id);

        if (!$order) {
            return false;
        }

        foreach ($order->get_items() as $item) {
            if (!$item->is_type('line_item')) {
                continue;
            }

            $product = $item->get_product();
            $stock_quantity_at_add = $item->get_meta('_stock_quantity_at_add');

            if ($stock_quantity_at_add !== '' || !$product->is_on_backorder()) {
                return false;
            }
        }

        return true;
    }

    /**
     * Split backorder products into a separate order.
     */
    public static function split_backorder_products($order_id)
    {
        $order = wc_get_order($order_id);

        if (!$order) {
            return;
        }

        if (self::are_all_products_in_backorder($order_id)) {
            $order->set_status('backordered');
            $order->save();
            return;
        }

        $backorder_items = [];
        $original_order_items = [];
        foreach ($order->get_items() as $item) {
            if (!$item->is_type('line_item')) {
                continue;
            }

            $product = $item->get_product();
            $quantity = $item->get_quantity();
            $stock_quantity_at_add = $item->get_meta('_stock_quantity_at_add');

            if ($product && $stock_quantity_at_add !== '') {
                $stock_quantity_at_add = (int)$stock_quantity_at_add;
                if ($quantity > $stock_quantity_at_add && $product->backorders_allowed()) {
                    $backorder_quantity = $quantity - $stock_quantity_at_add;

                    if ($stock_quantity_at_add > 0) {
                        $original_order_items[] = [
                            'product' => $product,
                            'quantity' => $stock_quantity_at_add,
                        ];

                        $order->remove_item($item->get_id());
                    }

                    $backorder_items[] = [
                        'product' => $product,
                        'quantity' => $backorder_quantity,
                    ];
                }
            } elseif ($product->is_on_backorder($quantity)) {
                $backorder_items[] = [
                    'product' => $product,
                    'quantity' => $quantity,
                ];
            }
        }

        if (!empty($original_order_items)) {
            foreach ($original_order_items as $original_order_item) {
                $order->add_product($original_order_item['product'], $original_order_item['quantity']);
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
            foreach ($backorder_items as $backorder_item) {
                $backorder_order->add_product($backorder_item['product'], $backorder_item['quantity']);
            }

            $backorder_order->set_address($order->get_address('billing'), 'billing');
            $backorder_order->set_address($order->get_address('shipping'), 'shipping');
            $backorder_order->set_customer_id($order->get_customer_id());
            $backorder_order->set_status('backordered');
            $backorder_order->calculate_totals();
            $backorder_order->save();
        }
    }
}
