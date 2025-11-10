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
     *
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

            // Skip if product is already on backorder or backorders not allowed
            if ($product->is_on_backorder() || !$product->backorders_allowed()) {
                return;
            }

            // Store original stock quantity if customer wants more than available
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
     * while keeping in-stock items in the original order.
     *
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

            // Check if all products are already on backorder
            if (self::are_all_products_in_backorder($order_id)) {
                $order->set_status('backordered');
                $order->save();
                return;
            }

            $backorder_items = [];
            $original_order_items = [];
            
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

            // Re-add items with correct quantities to original order
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
                
                if (!$backorder_order) {
                    error_log('WC Backorder Split: Failed to create backorder order for order ID ' . $order_id);
                    return;
                }
                
                foreach ($backorder_items as $backorder_item) {
                    $backorder_order->add_product($backorder_item['product'], $backorder_item['quantity']);
                }

                $backorder_order->set_address($order->get_address('billing'), 'billing');
                $backorder_order->set_address($order->get_address('shipping'), 'shipping');
                $backorder_order->set_customer_id($order->get_customer_id());
                $backorder_order->set_status('backordered');
                $backorder_order->calculate_totals();
                $backorder_order->save();
                
                // Log successful creation
                error_log('WC Backorder Split: Created backorder order #' . $backorder_order->get_id() . ' from original order #' . $order_id);
            }
        } catch (Exception $e) {
            error_log('WC Backorder Split - Error in split_backorder_products: ' . $e->getMessage());
        }
    }
}
