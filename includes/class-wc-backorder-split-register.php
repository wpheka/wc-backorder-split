<?php
/**
 * WC Backorder Split Register
 *
 * @version 1.4
 * @package WCBS
 */

if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * WC_Backorder_Split_Register Class
 */
class WC_Backorder_Split_Register
{

    /**
     * Hook actions and filters
     */
    public static function init()
    {

        // Add new order status in bulk actions (Order's Listing).
        add_filter('bulk_actions-edit-shop_order', array( __CLASS__, 'wcbs_get_custom_order_status_bulk' ), 10, 2);

        // Register new order status.
        add_filter('wc_order_statuses', array( __CLASS__, 'wcbs_add_backorder_order_status' ));
        add_filter('woocommerce_register_shop_order_post_statuses', array( __CLASS__, 'wcbs_register_new_order_status' ));
    }

    /**
     * Add new order status in WC
     *
     * @param array $order_statuses Order Stauses.
     * @return array
     */
    public static function wcbs_add_backorder_order_status($order_statuses)
    {
        $order_statuses['wc-backordered'] = _x('Backordered', 'Order status', 'wc-backorder-split');
        return $order_statuses;
    }

    /**
     * Register new order status in WC
     *
     * @param array $order_statuses Order Stauses.
     * @return array
     */
    public static function wcbs_register_new_order_status($order_statuses)
    {
        // Status must start with "wc-".
        $order_statuses['wc-backordered'] = array(
            'label'                     => _x('Backordered', 'Order status', 'wc-backorder-split'),
            'public'                    => false,
            'exclude_from_search'       => false,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            /* translators: %s: number of orders */
            'label_count'               => _n_noop('Backordered <span class="count">(%s)</span>', 'Backordered <span class="count">(%s)</span>', 'wc-backorder-split'),
        );
        return $order_statuses;
    }

    /**
     * Add new order status in bulk actions (Order's Listing)
     *
     * @param array $bulk_actions Bulk Actions.
     * @return array
     */
    public static function wcbs_get_custom_order_status_bulk($bulk_actions)
    {
        // Note: "mark_" must be there instead of "wc".
        $bulk_actions['mark_backordered'] = 'Change status to backordered';
        return $bulk_actions;
    }
}
