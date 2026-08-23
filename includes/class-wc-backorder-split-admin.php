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
        add_action('admin_notices', array( __CLASS__, 'review_notice' ));
        add_action('admin_init', array( __CLASS__, 'maybe_dismiss_review_notice' ));
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

        /*
         * admin_notices fires for anyone who can reach wp-admin at all -- a
         * subscriber can open profile.php -- and backorder_id arrives from the
         * URL. Without this check the notice answers "is there an order with
         * this id?" for any logged-in visitor willing to try ids, and shows the
         * order number and an edit link when there is.
         *
         * edit_shop_orders is the capability the linked screen already requires,
         * so gating on it adds no new restriction for anyone meant to see this.
         */
        if (!current_user_can('edit_shop_orders')) {
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

    /**
     * Ask for a review once the plugin has demonstrably done its job.
     *
     * Follows the shape Elementor uses for its rate-us notice -- dashboard only,
     * dismissed per user rather than per site, and gated on a usage count rather
     * than on elapsed time. Written here rather than taken from Elementor, whose
     * licence differs from this project's (ADR-008).
     *
     * Three splits, not a timer. A week having passed says nothing about whether
     * the plugin was useful; three orders actually split says it was.
     *
     * @since 2.3.0
     * @return void
     */
    public static function review_notice()
    {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;

        if (!$screen || 'dashboard' !== $screen->id) {
            return;
        }

        if (get_user_meta(get_current_user_id(), 'wcbs_review_dismissed', true)) {
            return;
        }

        if (3 > (int) get_option('wcbs_split_count', 0)) {
            return;
        }

        $reviews = 'https://wordpress.org/support/plugin/wc-backorder-split/reviews/';
        $dismiss = wp_nonce_url(
            add_query_arg('wcbs_dismiss_review', '1', admin_url('index.php')),
            'wcbs_dismiss_review'
        );
        ?>
        <div class="notice notice-info is-dismissible">
            <p>
                <?php
                printf(
                    /* translators: 1: plugin name, 2: opening link tag to the reviews page, 3: closing link tag, 4: opening link tag to dismiss, 5: closing link tag */
                    esc_html__('%1$s has split orders on this store. If it has been useful, %2$sleaving a review%3$s helps other shop owners find it. %4$sDon\'t ask again%5$s.', 'wc-backorder-split'),
                    '<strong>' . esc_html__('WC Backorder Split', 'wc-backorder-split') . '</strong>',
                    '<a href="' . esc_url($reviews) . '" target="_blank" rel="noopener noreferrer">',
                    '</a>',
                    '<a href="' . esc_url($dismiss) . '">',
                    '</a>'
                );
                ?>
            </p>
        </div>
        <?php
    }

    /**
     * Stop asking this user, when they ask us to.
     *
     * Per user, not per site: dismissing an ask is one person's decision, and a
     * site-wide flag lets whichever administrator clicks first answer on behalf
     * of everyone else.
     *
     * @since 2.3.0
     * @return void
     */
    public static function maybe_dismiss_review_notice()
    {
        if (!isset($_GET['wcbs_dismiss_review'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce checked immediately below.
            return;
        }

        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        check_admin_referer('wcbs_dismiss_review');

        update_user_meta(get_current_user_id(), 'wcbs_review_dismissed', 1);

        wp_safe_redirect(admin_url('index.php'));
        exit;
    }
}
