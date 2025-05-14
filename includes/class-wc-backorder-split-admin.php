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
     * Hook actions and filters
     */
    public static function init()
    {
        add_action('admin_enqueue_scripts', array( __CLASS__, 'wcbs_admin_styles' ));
    }

    /**
     * Enqueue styles.
     */
    public static function wcbs_admin_styles()
    {
        wp_enqueue_style('wcbs-admin-css', wcbs()->plugin_url() . 'assets/admin/css/admin.css', array(), wcbs()->version);
    }
}
