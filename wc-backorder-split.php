<?php
/**
 * Plugin Name: WC Backorder Split
 * Plugin URI: https://www.wpheka.com/product/wc-backorder-split
 * Description: The <code><strong>WC Backorder Split</strong></code> plugin helps you split the WooCommerce order for the products that you do not have in stock.
 * Author: WPHEKA
 * Version: 2.0
 * Requires at least: 4.9
 * Tested up to: 6.8.3
 * Author URI: https://www.wpheka.com
 * Text Domain: wc-backorder-split
 * Domain Path: /languages
 * Requires Plugins: woocommerce
 * WC requires at least: 4.2
 * WC tested up to: 10.3.4
 * License: GPLv3 or later
 *
 * @package WCBS
 */

if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

// Define WCBS_PLUGIN_FILE.
if (! defined('WCBS_PLUGIN_FILE')) {
    define('WCBS_PLUGIN_FILE', __FILE__);
}

// Declare High Performance Order Storage compatibility
add_action('before_woocommerce_init', function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

// Include the main WC Backorder Split class.
if (! class_exists('WC_Backorder_Split')) {
    include_once dirname(__FILE__) . '/includes/class-wc-backorder-split.php';
}

/**
 * Returns the main instance of WC_Backorder_Split to prevent the need to use globals.
 *
 * @since  1.4
 * @return WC_Backorder_Split
 */
function wcbs()
{
    return WC_Backorder_Split::instance();
}

return wcbs();
