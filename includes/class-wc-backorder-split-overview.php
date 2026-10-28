<?php
/**
 * WPHEKA > Backorder Split: a read-only page showing what the plugin has done.
 *
 * The plugin has no settings, so the page has an Overview tab (real numbers, recent
 * splits, the current behaviour) and a Help tab. There is no form and no save bar.
 *
 * @package WC_Backorder_Split
 * @since   2.4.0
 */

defined( 'ABSPATH' ) || exit;

use WC_Backorder_Split\AdminUI\Fields;
use WC_Backorder_Split\AdminUI\Icons;
use WC_Backorder_Split\AdminUI\Menu;
use WC_Backorder_Split\AdminUI\Page;

/**
 * WC_Backorder_Split_Overview Class.
 */
class WC_Backorder_Split_Overview {

	/**
	 * Page slug.
	 */
	const PAGE = 'wc-backorder-split';

	/**
	 * The wordpress.org slug.
	 */
	const SLUG = 'wc-backorder-split';

	/**
	 * Capability for the page: the one for the order screens it links to.
	 */
	const CAPABILITY = 'edit_shop_orders';

	/**
	 * Transient caching the split counts for an hour. Deleted when a split happens.
	 */
	const STATS_TRANSIENT = 'wcbs_overview_stats';

	/**
	 * The page hook WordPress returned, for loading assets on this page only.
	 *
	 * @var string
	 */
	private static $hook = '';

	/**
	 * Hook in.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_page' ), 20 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( WCBS_PLUGIN_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/**
	 * URL of a tab.
	 *
	 * @param string $tab overview or help.
	 * @return string
	 */
	public static function url( $tab = 'overview' ) {
		return add_query_arg(
			array(
				'page' => self::PAGE,
				'tab'  => $tab,
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Register the page under the shared WPHEKA menu. Shop managers cannot open the
	 * WPHEKA parent, but still see the menu: WordPress points it at the first page they may open.
	 */
	public static function register_page() {
		$hook = Menu::add_page( __( 'WC Backorder Split', 'wc-backorder-split' ), __( 'Backorder Split', 'wc-backorder-split' ), self::CAPABILITY, self::PAGE, array( __CLASS__, 'render' ), Icons::menu_icon() );

		self::$hook = is_string( $hook ) ? $hook : '';
	}

	/**
	 * Load the kit on this page only.
	 *
	 * @param string $hook Page hook.
	 */
	public static function enqueue( $hook ) {
		if ( '' === self::$hook || self::$hook !== $hook ) {
			return;
		}
		Page::enqueue( 'wc-backorder-split', plugins_url( 'assets/wpheka-ui/', WCBS_PLUGIN_FILE ) );
		wp_add_inline_style( 'wc-backorder-split-wpheka-ui', '.wcbs-notes{margin:0;padding:var(--w-s5) var(--w-s5) var(--w-s5) calc(var(--w-s5) + 18px);list-style:disc}.wcbs-notes li{margin:0 0 var(--w-s2);color:var(--w-text-2)}.wcbs-notes li:last-child{margin:0}.wcbs-recent-more{margin:0;padding:var(--w-s3) var(--w-s4);border-top:1px solid var(--w-line)}' );
	}

	/**
	 * An "Overview" link first in the plugin's row on the Plugins screen.
	 *
	 * @param array $links Action links.
	 * @return array
	 */
	public static function action_links( $links ) {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return $links;
		}

		return array_merge(
			array( 'overview' => '<a href="' . esc_url( self::url() ) . '" aria-label="' . esc_attr__( 'View WC Backorder Split overview', 'wc-backorder-split' ) . '">' . esc_html__( 'Overview', 'wc-backorder-split' ) . '</a>' ),
			(array) $links
		);
	}

	/**
	 * Links for the header, the rail and the Help tab.
	 *
	 * @return array
	 */
	public static function links() {
		return array(
			'docs'    => 'https://docs.wpheka.com/plugin/' . self::SLUG,
			'hooks'   => 'https://docs.wpheka.com/plugin/' . self::SLUG . '/developers',
			'support' => 'https://wordpress.org/support/plugin/' . self::SLUG . '/',
			'changes' => 'https://wordpress.org/plugins/' . self::SLUG . '/#developers',
			'review'  => 'https://wordpress.org/support/plugin/' . self::SLUG . '/reviews/#new-post',
		);
	}

	/**
	 * The tab being shown.
	 *
	 * @return string
	 */
	private static function current_tab() {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'overview'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- which tab to show.

		return 'help' === $tab ? 'help' : 'overview';
	}

	/**
	 * The page.
	 */
	public static function render() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$tab   = self::current_tab();
		$links = self::links();

		Page::open(
			array(
				'icon'     => plugins_url( 'assets/wpheka-ui/icons/' . self::SLUG . '.png', WCBS_PLUGIN_FILE ),
				'name'     => __( 'WC Backorder Split', 'wc-backorder-split' ),
				'version'  => wcbs()->version,
				'badges'   => array( array( __( 'Free', 'wc-backorder-split' ), 'mint' ) ),
				'subtitle' => __( 'Ship what is in stock now and send backorders in their own order.', 'wc-backorder-split' ),
				'crumb'    => __( 'Backorder Split', 'wc-backorder-split' ),
				'links'    => array(
					array( __( 'Docs', 'wc-backorder-split' ), $links['docs'], 'book' ),
					array( __( 'Support', 'wc-backorder-split' ), $links['support'], 'chat' ),
					array( __( "What's new", 'wc-backorder-split' ), $links['changes'], 'sparkle' ),
				),
				'cta'      => array( __( 'Rate on WordPress.org', 'wc-backorder-split' ), $links['review'], 'star', 'ghost' ),
				'tabs'     => array(
					array( __( 'Overview', 'wc-backorder-split' ), self::url( 'overview' ), 'overview' === $tab ),
					array( __( 'Help', 'wc-backorder-split' ), self::url( 'help' ), 'help' === $tab ),
				),
			)
		);

		if ( 'overview' === $tab ) {
			self::render_stats();
			self::render_overview( $links );
		} else {
			self::render_help( $links );
		}

		Page::close(
			array(
				'slug'       => self::SLUG,
				'icons_url'  => plugins_url( 'assets/wpheka-ui/icons/', WCBS_PLUGIN_FILE ),
				'short_name' => __( 'WC Backorder Split', 'wc-backorder-split' ),
				'review_url' => $links['review'],
				'form'       => '',
			)
		);
	}

	/**
	 * Whether WooCommerce stores orders in its own tables now.
	 *
	 * @return bool
	 */
	private static function hpos() {
		return class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	}

	/**
	 * Admin URL of the orders list, filtered by a status.
	 *
	 * @param string $status Status without the wc- prefix.
	 * @return string
	 */
	public static function orders_url( $status ) {
		return admin_url( self::hpos() ? 'admin.php?page=wc-orders&status=wc-' . $status : 'edit.php?post_type=shop_order&post_status=wc-' . $status );
	}

	/**
	 * Counts of the backorder orders the plugin made (the ones carrying
	 * `_wcbs_parent_order_id`), read from the storage WooCommerce uses now.
	 *
	 * @return array { split, completed, last7, items }
	 */
	public static function stats() {
		$key    = self::hpos() ? 'hpos' : 'posts';
		$cached = get_transient( self::STATS_TRANSIENT );
		if ( is_array( $cached ) && isset( $cached[ $key ] ) ) {
			return $cached[ $key ];
		}

		global $wpdb;
		$since = gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS );

		// Backorder orders are the ones carrying _wcbs_parent_order_id; trash and drafts are left out.
		// The item tables are the same on both storages. Cached in a transient for an hour.
		if ( 'hpos' === $key ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT COUNT(*) AS split, COALESCE( SUM( o.status = 'wc-completed' ), 0 ) AS completed, COALESCE( SUM( o.date_created_gmt >= %s ), 0 ) AS last7
					FROM {$wpdb->prefix}wc_orders o
					WHERE o.type = 'shop_order' AND o.status NOT IN ( 'trash', 'auto-draft', 'wc-checkout-draft' )
					AND EXISTS ( SELECT 1 FROM {$wpdb->prefix}wc_orders_meta m WHERE m.order_id = o.id AND m.meta_key = '_wcbs_parent_order_id' )",
					$since
				),
				ARRAY_A
			);
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$items = $wpdb->get_var(
				"SELECT COALESCE( SUM( im.meta_value ), 0 )
				FROM {$wpdb->prefix}woocommerce_order_items i
				JOIN {$wpdb->prefix}woocommerce_order_itemmeta im ON im.order_item_id = i.order_item_id AND im.meta_key = '_qty'
				WHERE i.order_item_type = 'line_item' AND i.order_id IN (
					SELECT o.id FROM {$wpdb->prefix}wc_orders o
					WHERE o.type = 'shop_order' AND o.status NOT IN ( 'trash', 'auto-draft', 'wc-checkout-draft' )
					AND EXISTS ( SELECT 1 FROM {$wpdb->prefix}wc_orders_meta m WHERE m.order_id = o.id AND m.meta_key = '_wcbs_parent_order_id' )
				)"
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT COUNT(*) AS split, COALESCE( SUM( o.post_status = 'wc-completed' ), 0 ) AS completed, COALESCE( SUM( o.post_date_gmt >= %s ), 0 ) AS last7
					FROM {$wpdb->posts} o
					WHERE o.post_type = 'shop_order' AND o.post_status NOT IN ( 'trash', 'auto-draft', 'wc-checkout-draft' )
					AND EXISTS ( SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id = o.ID AND m.meta_key = '_wcbs_parent_order_id' )",
					$since
				),
				ARRAY_A
			);
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$items = $wpdb->get_var(
				"SELECT COALESCE( SUM( im.meta_value ), 0 )
				FROM {$wpdb->prefix}woocommerce_order_items i
				JOIN {$wpdb->prefix}woocommerce_order_itemmeta im ON im.order_item_id = i.order_item_id AND im.meta_key = '_qty'
				WHERE i.order_item_type = 'line_item' AND i.order_id IN (
					SELECT o.ID FROM {$wpdb->posts} o
					WHERE o.post_type = 'shop_order' AND o.post_status NOT IN ( 'trash', 'auto-draft', 'wc-checkout-draft' )
					AND EXISTS ( SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id = o.ID AND m.meta_key = '_wcbs_parent_order_id' )
				)"
			);
		}

		$stats = array(
			'split'     => (int) ( $row['split'] ?? 0 ),
			'completed' => (int) ( $row['completed'] ?? 0 ),
			'last7'     => (int) ( $row['last7'] ?? 0 ),
			'items'     => (float) $items,
		);

		$cached         = is_array( $cached ) ? $cached : array();
		$cached[ $key ] = $stats;
		set_transient( self::STATS_TRANSIENT, $cached, HOUR_IN_SECONDS );

		return $stats;
	}

	/**
	 * The newest backorder orders, newest first.
	 *
	 * @param int $limit How many.
	 * @return WC_Order[]
	 */
	public static function recent( $limit = 5 ) {
		global $wpdb;

		if ( self::hpos() ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT o.id FROM {$wpdb->prefix}wc_orders o
					WHERE o.type = 'shop_order' AND o.status NOT IN ( 'trash', 'auto-draft', 'wc-checkout-draft' )
					AND EXISTS ( SELECT 1 FROM {$wpdb->prefix}wc_orders_meta m WHERE m.order_id = o.id AND m.meta_key = '_wcbs_parent_order_id' )
					ORDER BY o.date_created_gmt DESC, o.id DESC LIMIT %d",
					$limit
				)
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT o.ID FROM {$wpdb->posts} o
					WHERE o.post_type = 'shop_order' AND o.post_status NOT IN ( 'trash', 'auto-draft', 'wc-checkout-draft' )
					AND EXISTS ( SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id = o.ID AND m.meta_key = '_wcbs_parent_order_id' )
					ORDER BY o.post_date_gmt DESC, o.ID DESC LIMIT %d",
					$limit
				)
			);
		}

		return array_filter( array_map( 'wc_get_order', array_map( 'absint', $ids ) ) );
	}

	/**
	 * The stats strip: real numbers only.
	 */
	private static function render_stats() {
		$stats = self::stats();
		$now   = (int) wc_orders_count( 'backordered' );

		Page::stats(
			array(
				array(
					'label' => __( 'Orders split', 'wc-backorder-split' ),
					'icon'  => 'split',
					'value' => number_format_i18n( $stats['split'] ),
					/* translators: %s: number of splits in the last 7 days. */
					'meta'  => $stats['last7'] ? sprintf( __( '+%s in the last 7 days', 'wc-backorder-split' ), number_format_i18n( $stats['last7'] ) ) : __( 'Since the plugin was installed', 'wc-backorder-split' ),
					'up'    => $stats['last7'] > 0,
				),
				array(
					'label' => __( 'Backordered now', 'wc-backorder-split' ),
					'icon'  => 'box',
					'mint'  => true,
					'value' => number_format_i18n( $now ),
					'meta'  => __( 'Orders with the Backordered status', 'wc-backorder-split' ),
				),
				array(
					'label' => __( 'Backorders completed', 'wc-backorder-split' ),
					'icon'  => 'truck',
					'value' => number_format_i18n( $stats['completed'] ),
					'meta'  => __( 'Shipped later, on their own', 'wc-backorder-split' ),
				),
				array(
					'label' => __( 'Items moved', 'wc-backorder-split' ),
					'icon'  => 'cart',
					'mint'  => true,
					'value' => number_format_i18n( $stats['items'] ),
					/* translators: %s: number of backorder orders. */
					'meta'  => sprintf( _n( 'Across %s backorder order', 'Across %s backorder orders', $stats['split'], 'wc-backorder-split' ), number_format_i18n( $stats['split'] ) ),
				),
			)
		);
	}

	/**
	 * The Overview tab.
	 *
	 * @param array $links Help links.
	 */
	private static function render_overview( array $links ) {
		echo '<div class="w-stack">';
		echo '<div class="w-notice">' . Icons::get( 'info' ) . '<p>' . esc_html__( 'No setup needed. Backorder Split works automatically after checkout. This page shows what it has done.', 'wc-backorder-split' ) . '</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG.
		echo '<div class="w-layout w-layout--rail"><div class="w-stack">';

		self::render_how_it_works();
		self::render_recent();
		self::render_status();

		echo '</div><aside class="w-rail" aria-label="' . esc_attr__( 'Review and help', 'wc-backorder-split' ) . '">';
		Page::review_card( __( 'Saved you some shipping?', 'wc-backorder-split' ), __( 'A quick review helps other store owners find it and keeps it improving.', 'wc-backorder-split' ), $links['review'] );
		Page::help_card(
			array(
				array( __( 'Read the documentation', 'wc-backorder-split' ), $links['docs'], 'book' ),
				array( __( 'Ask in the support forum', 'wc-backorder-split' ), $links['support'], 'chat' ),
			)
		);
		echo '</aside></div></div>';
	}

	/**
	 * Three steps, matching what the code does.
	 */
	private static function render_how_it_works() {
		$steps = array(
			array( __( 'A customer orders', 'wc-backorder-split' ), __( 'Some items are in stock and some are on backorder.', 'wc-backorder-split' ) ),
			array( __( 'The order splits after checkout', 'wc-backorder-split' ), __( 'When the order confirmation page loads, the backordered quantities move to a new linked order with the Backordered status.', 'wc-backorder-split' ) ),
			array( __( 'You ship what you have', 'wc-backorder-split' ), __( 'Both orders link to each other and get a note. The new order keeps the shipping method at no extra cost, so the customer pays shipping once.', 'wc-backorder-split' ) ),
		);

		Fields::section_start( 'wcbs-how', __( 'How it works', 'wc-backorder-split' ) );
		echo '<div class="w-flow">';
		foreach ( $steps as $n => $step ) {
			echo '<div class="w-flow-step"><span class="w-step-n">' . esc_html( number_format_i18n( $n + 1 ) ) . '</span><h3>' . esc_html( $step[0] ) . '</h3><p>' . esc_html( $step[1] ) . '</p></div>';
		}
		echo '</div>';
		Fields::section_end();
	}

	/**
	 * The five newest splits.
	 */
	private static function render_recent() {
		$orders = self::recent();

		Fields::section_start( 'wcbs-recent', __( 'Recent splits', 'wc-backorder-split' ) );

		if ( ! $orders ) {
			echo '<div class="w-empty"><h3>' . esc_html__( 'No orders split yet.', 'wc-backorder-split' ) . '</h3><p>' . esc_html__( 'When a customer buys more than you have in stock, the extra items move to a new Backordered order.', 'wc-backorder-split' ) . '</p></div>';
			Fields::section_end();
			return;
		}

		echo '<div class="w-table-wrap"><table class="w-table wcbs-recent"><thead><tr><th>' . esc_html__( 'Original order', 'wc-backorder-split' ) . '</th><th>' . esc_html__( 'Backorder', 'wc-backorder-split' ) . '</th><th>' . esc_html__( 'Items moved', 'wc-backorder-split' ) . '</th><th>' . esc_html__( 'Status', 'wc-backorder-split' ) . '</th><th>' . esc_html__( 'When', 'wc-backorder-split' ) . '</th></tr></thead><tbody>';

		foreach ( $orders as $order ) {
			$parent = wc_get_order( (int) $order->get_meta( '_wcbs_parent_order_id' ) );
			$items  = array();
			foreach ( $order->get_items() as $item ) {
				$items[] = $item->get_name() . ' × ' . wc_stock_amount( $item->get_quantity() );
			}
			$created = $order->get_date_created();

			echo '<tr data-order="' . esc_attr( $order->get_id() ) . '"><td>';
			echo $parent ? '<a href="' . esc_url( $parent->get_edit_order_url() ) . '">#' . esc_html( $parent->get_order_number() ) . '</a>' : '&ndash;';
			echo '</td><td><a href="' . esc_url( $order->get_edit_order_url() ) . '">#' . esc_html( $order->get_order_number() ) . '</a></td>';
			echo '<td class="w-wrap">' . esc_html( implode( ', ', $items ) ) . '</td>';
			echo '<td><span class="w-pill w-pill--info w-pill--plain">' . esc_html( wc_get_order_status_name( $order->get_status() ) ) . '</span></td>';
			/* translators: %s: time since, for example "3 hours". */
			echo '<td>' . esc_html( $created ? sprintf( __( '%s ago', 'wc-backorder-split' ), human_time_diff( $created->getTimestamp() ) ) : '' ) . '</td></tr>';
		}

		echo '</tbody></table></div>';
		echo '<p class="wcbs-recent-more"><a href="' . esc_url( self::orders_url( 'backordered' ) ) . '">' . esc_html__( 'View all Backordered orders', 'wc-backorder-split' ) . '</a></p>';
		Fields::section_end();
	}

	/**
	 * Read-only status rows, each from real state.
	 */
	private static function render_status() {
		$custom = __( 'Changed by custom code', 'wc-backorder-split' );

		Fields::section_start( 'wcbs-status', __( 'Status', 'wc-backorder-split' ) );
		Fields::kv(
			array(
				array(
					'label' => __( 'Backorder status', 'wc-backorder-split' ),
					'value' => __( 'Backordered', 'wc-backorder-split' ),
					'note'  => has_filter( 'wcbs_backorder_status' ) ? $custom : __( 'New backorder orders get this status.', 'wc-backorder-split' ),
					'pill'  => has_filter( 'wcbs_backorder_status' ) ? array( $custom, 'warn' ) : array( __( 'Default', 'wc-backorder-split' ), 'ok' ),
				),
				array(
					'label' => __( 'Emails during a split', 'wc-backorder-split' ),
					'value' => __( 'Held', 'wc-backorder-split' ),
					'note'  => has_filter( 'wcbs_disable_emails_on_split' ) ? $custom : __( 'WooCommerce emails are held while the new order is created, so the customer does not get a second order email.', 'wc-backorder-split' ),
					'pill'  => has_filter( 'wcbs_disable_emails_on_split' ) ? array( $custom, 'warn' ) : array( __( 'Default', 'wc-backorder-split' ), 'ok' ),
				),
				array(
					'label' => __( 'Shipping on the backorder', 'wc-backorder-split' ),
					'value' => __( 'Same method, no extra cost', 'wc-backorder-split' ),
					'note'  => has_filter( 'wcbs_copy_charges_to_backorder' ) ? $custom : __( 'Fees and coupons stay on the original order.', 'wc-backorder-split' ),
					'pill'  => has_filter( 'wcbs_copy_charges_to_backorder' ) ? array( $custom, 'warn' ) : array( __( 'Default', 'wc-backorder-split' ), 'ok' ),
				),
				array(
					'label' => __( 'Order storage', 'wc-backorder-split' ),
					'value' => self::hpos() ? __( 'High-Performance Order Storage', 'wc-backorder-split' ) : __( 'WordPress posts storage', 'wc-backorder-split' ),
					'pill'  => array( __( 'Supported', 'wc-backorder-split' ), 'ok' ),
				),
			)
		);
		Fields::section_end();
	}

	/**
	 * The Help tab.
	 *
	 * @param array $links Help links.
	 */
	private static function render_help( array $links ) {
		$cards = array(
			array( 'book', __( 'Documentation', 'wc-backorder-split' ), __( 'How a split works, with answers to common questions.', 'wc-backorder-split' ), $links['docs'] ),
			array( 'chat', __( 'Support forum', 'wc-backorder-split' ), __( 'Ask a question. The developers who built the plugin answer.', 'wc-backorder-split' ), $links['support'] ),
			array( 'sparkle', __( "What's new", 'wc-backorder-split' ), __( 'Every change in each version, in plain words.', 'wc-backorder-split' ), $links['changes'] ),
			array( 'code', __( 'Developer hooks', 'wc-backorder-split' ), __( 'Change when orders split, what moves and which status the new order gets.', 'wc-backorder-split' ), $links['hooks'] ),
		);
		echo '<div class="w-stack"><div class="w-flow">';
		foreach ( $cards as $card ) {
			echo '<a class="w-card w-flow-step" href="' . esc_url( $card[3] ) . '" target="_blank" rel="noopener"><span class="w-ico w-ico--lg">' . Icons::get( $card[0] ) . '</span><h3>' . esc_html( $card[1] ) . '</h3><p>' . esc_html( $card[2] ) . '<span class="w-sr"> ' . esc_html__( '(opens in a new tab)', 'wc-backorder-split' ) . '</span></p></a>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG.
		}
		echo '</div>';

		$notes = array(
			__( 'The split runs when the order confirmation page loads, on the classic and the block checkout. Orders added in the admin are not split.', 'wc-backorder-split' ),
			__( 'An order is split only once, even if the confirmation page is reloaded.', 'wc-backorder-split' ),
			__( 'When nothing in the order is in stock, it is not split: the order itself gets the Backordered status.', 'wc-backorder-split' ),
			__( 'Stock is read when the customer adds to cart and at checkout. Variations that share their parent\'s stock share it here too.', 'wc-backorder-split' ),
			__( 'The backorder order keeps the shipping method at no extra cost. Shipping, fees and coupons are paid once, on the original order.', 'wc-backorder-split' ),
			__( 'Both orders get an order note and a "Linked orders" box on the order screen.', 'wc-backorder-split' ),
			__( '"Change status to backordered" is in the orders list bulk actions.', 'wc-backorder-split' ),
			__( 'Errors are logged in WooCommerce > Status > Logs, source wc-backorder-split.', 'wc-backorder-split' ),
		);
		$hooks = array( 'wcbs_should_split_order', 'wcbs_before_split_order', 'wcbs_backorder_items', 'wcbs_backorder_status', 'wcbs_copy_charges_to_backorder', 'wcbs_backorder_created', 'wcbs_after_split_order', 'wcbs_split_order_error', 'wcbs_meta_fields_to_copy', 'wcbs_disable_emails_on_split' );

		Fields::section_start( 'wcbs-good-to-know', __( 'Good to know', 'wc-backorder-split' ) );
		echo '<ul class="wcbs-notes">';
		foreach ( $notes as $note ) {
			echo '<li>' . esc_html( $note ) . '</li>';
		}
		echo '<li>' . esc_html__( 'Developer hooks:', 'wc-backorder-split' ) . ' ';
		$codes = array();
		foreach ( $hooks as $hook ) {
			$codes[] = '<code>' . esc_html( $hook ) . '</code>';
		}
		echo implode( ', ', $codes ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- each escaped above.
		echo '</li></ul>';
		Fields::section_end();
		echo '</div>';
	}
}
