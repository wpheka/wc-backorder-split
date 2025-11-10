<?php
/**
 * WC Backorder Split Admin
 *
 * @version 1.4
 * @package WCBS
 */

if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * WC_Backorder_Split_Admin Class
 */
class WC_Backorder_Split_Admin
{

    /**
     * Hook actions and filters for admin functionality
     */
    public static function init()
    {
        add_action('admin_enqueue_scripts', array( __CLASS__, 'wcbs_admin_styles' ));
    }

    /**
     * Enqueue admin styles only on relevant WooCommerce pages.
     */
    public static function wcbs_admin_styles()
    {
        // Only load admin styles on relevant admin pages
        $screen = get_current_screen();
        if (!$screen || !in_array($screen->id, ['shop_order', 'edit-shop_order', 'woocommerce_page_wc-orders'], true)) {
            return;
        }
        
        wp_enqueue_style('wcbs-admin-css', wcbs()->plugin_url() . 'assets/admin/css/admin.css', array(), wcbs()->version);
    }
}
