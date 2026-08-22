<?php
/**
 * WC Backorder Split main class
 *
 * @package WCBS
 * @version 2.2.0
 */

defined('ABSPATH') || exit;

/**
 * WC_Backorder_Split Class
 */
class WC_Backorder_Split
{

    /**
     * WC Backorder Split version
     *
     * @var string
     */
    public $version = '2.3.0';

    /**
     * Min WC required version.
     *
     * @var string
     */
    protected $min_wc_version = '3.4';

    /**
     * Enviroment alert
     *
     * @var string
     */
    protected $environment_alert = '';

    /**
     * The single instance of the class.
     *
     * @var WC_Backorder_Split
     */
    protected static $_instance = null;

    /**
     * Main WC_Backorder_Split Instance
     *
     * Ensures only one instance of WC_Backorder_Split is loaded or can be loaded.
     *
     * @since 1.0.0
     * @static
     * @see WCBS()
     * @return WC_Backorder_Split Main instance
     */
    public static function instance()
    {
        if (is_null(self::$_instance)) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    /**
     * Get the plugin url.
     *
     * @since 1.0.0
     * @return string Plugin URL
     */
    public function plugin_url()
    {
        return plugin_dir_url(WCBS_PLUGIN_FILE);
    }

    /**
     * Get the plugin path.
     *
     * @since 1.0.0
     * @return string Plugin path
     */
    public function plugin_path()
    {
        return plugin_dir_path(WCBS_PLUGIN_FILE);
    }

    /**
     * Return the plugin base name
     *
     * @since 1.4.0
     * @return string Plugin basename
     */
    public function plugin_basename()
    {
        return plugin_basename(WCBS_PLUGIN_FILE);
    }

    /**
     * WC_Backorder_Split Constructor.
     *
     * @since 1.0.0
     */
    public function __construct()
    {
        $this->includes();

        add_action('init', array( $this, 'load_textdomain' ));
        add_filter('plugin_row_meta', array( $this, 'plugin_row_meta' ), 10, 2);
        add_action('plugins_loaded', array( $this, 'init_plugin' ), 5);
    }

    /**
     * Include required files used in admin and on the frontend.
     *
     * @since 1.0.0
     */
    private function includes()
    {

        include_once $this->plugin_path() . 'includes/class-wc-backorder-split-register.php';
        include_once $this->plugin_path() . 'includes/class-wc-backorder-split-frontend.php';

        if ($this->is_request('admin')) {
            include_once $this->plugin_path() . 'includes/class-wc-backorder-split-admin.php';
            include_once $this->plugin_path() . 'includes/class-wc-backorder-split-tracker.php';
        }
    }

    /**
     * Load plugin text domain for translations.
     *
     * @since 1.4.0
     */
    public function load_textdomain()
    {
        load_plugin_textdomain('wc-backorder-split', false, dirname(plugin_basename(WCBS_PLUGIN_FILE)) . '/languages/');
    }

    /**
     * Show row meta on the plugin screen.
     *
     * @since 1.4.0
     * @param array $links Plugin Row Meta
     * @param string $file Plugin Base file
     * @return array Modified links
     */
    public function plugin_row_meta($links, $file)
    {
        if (plugin_basename(WCBS_PLUGIN_FILE) === $file) {
            $row_meta = array(
                'donate'    => '<a href="' . esc_url(apply_filters('wcbs_donate_url', 'https://www.paypal.me/AKSHAYASWAROOP')) . '" title="' . esc_attr(__('Donate', 'wc-backorder-split')) . '">' . __('Donate', 'wc-backorder-split') . '</a>',
                'support' => '<a href="' . esc_url(apply_filters('wcbs_support_url', 'https://www.wpheka.com/submit-ticket/')) . '" title="' . esc_attr(__('Open a support request at wpheka.com', 'wc-backorder-split')) . '">' . __('Support', 'wc-backorder-split') . '</a>',
            );

            return array_merge($links, $row_meta);
        }

        return (array) $links;
    }

    /**
     * Checks the environment for compatibility problems.
     *
     * @since 1.0.0
     * @return bool True if environment is compatible, false otherwise
     */
    private function check_environment()
    {
        if (!defined('WC_VERSION')) {
            // translators: HTML Tags.
            $this->environment_alert = sprintf(__('%1$sWC Backorder Split%2$s requires WooCommerce to be activated to work.', 'wc-backorder-split'), '<strong>', '</strong>');
            return false;
        }

        if (version_compare(WC_VERSION, $this->min_wc_version, '<')) {
            // translators: %1$s: minimum WooCommerce version, %2$s: current WooCommerce version
            $this->environment_alert = sprintf(__('The minimum WooCommerce version required for this plugin is %1$s. You are running %2$s.', 'wc-backorder-split'), $this->min_wc_version, WC_VERSION);
            return false;
        }

        return true;
    }

    /**
     * Returns true if the request is a non-legacy REST API request.
     *
     * @since 1.0.0
     * @return bool True if REST API request, false otherwise
     */
    public function is_rest_api_request()
    {
        if (empty($_SERVER['REQUEST_URI'])) {
            return false;
        }

        $rest_prefix         = trailingslashit(rest_get_url_prefix());
        $request_uri         = sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI']));
        $is_rest_api_request = ( false !== strpos($request_uri, $rest_prefix) );

        return apply_filters('wcbs_is_rest_api_request', $is_rest_api_request);
    }

    /**
     * What type of request is this?
     *
     * @since 1.0.0
     * @param string $type Request type: admin, ajax, cron or frontend
     * @return bool True if request matches type, false otherwise
     */
    private function is_request($type)
    {
        switch ($type) {
            case 'admin':
                return is_admin();
            case 'ajax':
                return defined('DOING_AJAX');
            case 'cron':
                return defined('DOING_CRON');
            case 'frontend':
                return ( ! is_admin() || defined('DOING_AJAX') ) && ! defined('DOING_CRON') && ! $this->is_rest_api_request();
        }
        return false;
    }

    /**
     * Initialize plugin functionality after environment check.
     *
     * @since 1.4.0
     */
    public function init_plugin()
    {

        if (! $this->check_environment()) {
            add_action('admin_notices', array( $this, 'environment_notice' ));
            return;
        }

        WC_Backorder_Split_Register::init();

        if ($this->is_request('admin')) {
            // Admin request.
            WC_Backorder_Split_Admin::init();
            WC_Backorder_Split_Tracker::init();
        }

        if ($this->is_request('frontend')) {
            WC_Backorder_Split_Frontend::init();
        }
    }

    /**
     * Display environment compatibility alert in admin.
     *
     * @since 1.0.0
     */
    public function environment_notice()
    {
        echo '<div id="message" class="error">' . sprintf('<p><strong>%1$s</strong></p>%2$s', 'WC Backorder Split - ' . esc_html__('Heads up!', 'wc-backorder-split'), wp_kses_post(wpautop($this->environment_alert))) . '</div>';
    }
}
