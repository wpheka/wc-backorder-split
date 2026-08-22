<?php
/**
 * Plugin Name: WC Backorder Split
 * Plugin URI: https://www.wpheka.com/product/wc-backorder-split
 * Description: The <code><strong>WC Backorder Split</strong></code> plugin helps you split the WooCommerce order for the products that you do not have in stock.
 * Author: WPHEKA
 * Version: 2.2.0
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Tested up to: 7.1
 * Author URI: https://www.wpheka.com
 * Text Domain: wc-backorder-split
 * Domain Path: /languages
 * Requires Plugins: woocommerce
 * WC requires at least: 4.2
 * WC tested up to: 11.0.1
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

define('WCBS_MIN_FRAMEWORK', '1.0.0');

/*
 * Loaded at include time so the registry resolves before plugins_loaded. The
 * classes themselves are not available yet -- the autoloader registers on
 * plugins_loaded at -100 -- so nothing may touch them until then.
 */
if (is_readable(__DIR__ . '/framework/register.php')) {
    require_once __DIR__ . '/framework/register.php';
}

/**
 * Whether a usable framework booted.
 *
 * @since 2.3.0
 * @return bool
 */
function wcbs_framework_ready()
{
    if (! class_exists('WPHEKA_Framework_Versions', false)) {
        return false;
    }

    $active = WPHEKA_Framework_Versions::active_version('1');

    if (! is_string($active) || ! version_compare($active, WCBS_MIN_FRAMEWORK, '>=')) {
        return false;
    }

    // The only framework class this plugin touches. Checked by name and not
    // just by version, because bundling is modular and another plugin's bundle
    // can win -- a version check passes while the module set is incomplete.
    return class_exists('\\WPHEKA\\Framework\\V1\\WooCommerce\\Compatibility');
}

/**
 * Declares High Performance Order Storage compatibility.
 *
 * Hooked to plugins_loaded rather than called at include time. The framework
 * autoloader only registers on plugins_loaded at -100, so at include time
 * Compatibility does not exist and a class_exists() guard would skip the
 * declaration silently -- WooCommerce would then list this plugin as
 * HPOS-incompatible while the code looked correct.
 *
 * @since 2.3.0
 * @return void
 */
function wcbs_declare_hpos_compatibility()
{
    if (! wcbs_framework_ready()) {
        // Fall back to declaring it by hand, so a bundle that lost the
        // WooCommerce module does not silently drop the declaration.
        add_action(
            'before_woocommerce_init',
            static function () {
                if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
                    \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', WCBS_PLUGIN_FILE, true);
                    \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', WCBS_PLUGIN_FILE, true);
                }
            }
        );

        return;
    }

    /*
     * Both features, and the blocks one is now verified rather than assumed.
     * This plugin's three frontend hooks all fire on the path the Cart and
     * Checkout blocks use:
     *
     *   woocommerce_add_to_cart                      WC_Cart::add_to_cart, which
     *                                                the Store API calls
     *   woocommerce_checkout_create_order_line_item   fired by WC_Checkout, which
     *                                                StoreApi OrderController
     *                                                delegates line items to
     *   woocommerce_thankyou                          fired by the Order
     *                                                Confirmation block
     *
     * Checked against WooCommerce 11.0.1 source and then exercised: an order
     * built through wc()->checkout->create_order_line_items() -- the Store API's
     * own path -- fired both cart and line-item hooks and this plugin's callback
     * wrote its item meta.
     */
    \WPHEKA\Framework\V1\WooCommerce\Compatibility::declare_for(
        WCBS_PLUGIN_FILE,
        array(
            \WPHEKA\Framework\V1\WooCommerce\Compatibility::HPOS   => true,
            \WPHEKA\Framework\V1\WooCommerce\Compatibility::BLOCKS => true,
        )
    );
}
add_action('plugins_loaded', 'wcbs_declare_hpos_compatibility');

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
