<?php
/**
 * WC Backorder Split Admin
 *
 * @version 2.1.0
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
     *
     * @since 1.0.0
     */
    public static function init()
    {
        add_action('admin_enqueue_scripts', array( __CLASS__, 'wcbs_admin_styles' ));
        add_action('woocommerce_admin_order_data_after_order_details', array( __CLASS__, 'display_linked_orders' ), 10, 1);
        add_action('admin_notices', array( __CLASS__, 'display_admin_notices' ));
    }

    /**
     * Enqueue admin styles only on relevant WooCommerce pages.
     *
     * @since 1.0.0
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

    /**
     * Display linked orders (parent/backorder relationship) in order details.
     *
     * @since 2.1.0
     * @param WC_Order $order Order object
     */
    public static function display_linked_orders($order)
    {
        $backorder_id = $order->get_meta('_wcbs_backorder_id');
        $parent_order_id = $order->get_meta('_wcbs_parent_order_id');

        if ($backorder_id || $parent_order_id) {
            echo '<div class="wcbs-linked-orders" style="margin-top: 12px; padding: 12px; background: #f8f9fa; border-left: 3px solid #2271b1;">';

            if ($backorder_id) {
                $backorder = wc_get_order($backorder_id);
                if ($backorder instanceof WC_Order) {
                    echo '<p><strong>' . esc_html__('Backorder Created:', 'wc-backorder-split') . '</strong> ';
                    echo '<a href="' . esc_url($backorder->get_edit_order_url()) . '">';
                    echo esc_html__('Order #', 'wc-backorder-split') . esc_html($backorder_id);
                    echo '</a> (' . esc_html(wc_get_order_status_name($backorder->get_status())) . ')</p>';
                }
            }

            if ($parent_order_id) {
                $parent_order = wc_get_order($parent_order_id);
                if ($parent_order instanceof WC_Order) {
                    echo '<p><strong>' . esc_html__('Split From Order:', 'wc-backorder-split') . '</strong> ';
                    echo '<a href="' . esc_url($parent_order->get_edit_order_url()) . '">';
                    echo esc_html__('Order #', 'wc-backorder-split') . esc_html($parent_order_id);
                    echo '</a> (' . esc_html(wc_get_order_status_name($parent_order->get_status())) . ')</p>';
                }
            }

            echo '</div>';
        }
    }

    /**
     * Display admin notices for backorder operations.
     *
     * @since 2.1.0
     */
    public static function display_admin_notices()
    {
        // Read-only notice driven by a redirect, so there is no nonce to verify
        // and nothing here changes state.
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $notice = isset($_GET['wcbs_notice']) ? sanitize_key(wp_unslash($_GET['wcbs_notice'])) : '';
        $backorder_id = isset($_GET['backorder_id']) ? absint($_GET['backorder_id']) : 0;
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        if ('backorder_created' !== $notice || !$backorder_id) {
            return;
        }

        $backorder = wc_get_order($backorder_id);

        if (!$backorder instanceof WC_Order) {
            return;
        }
        ?>
        <div class="notice notice-success is-dismissible">
            <p>
                <?php
                printf(
                    /* translators: %s: backorder ID with link */
                    esc_html__('Backorder successfully created: %s', 'wc-backorder-split'),
                    '<a href="' . esc_url($backorder->get_edit_order_url()) . '">' .
                    esc_html__('Order #', 'wc-backorder-split') . esc_html($backorder_id) . '</a>'
                );
                ?>
            </p>
        </div>
        <?php
    }
}
