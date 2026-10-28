<?php
/**
 * overview.php hpos|legacy
 *
 * The WPHEKA > Backorder Split page, rendered to a string: who may open it, the
 * four counts against splits made here, the Recent splits table, the cache reset
 * on a new split, the Help tab and the shared menu. Orders made here are deleted
 * at the end on the storage they were made on.
 */
require __DIR__ . '/lib.php';

$mode  = $args[0] ?? 'hpos';
$label = 'legacy' === $mode ? 'overview (legacy storage)' : 'overview (HPOS)';
$ids   = wcbs_t_ids();
$made  = array();

if ( ! class_exists( 'WC_Backorder_Split_Overview' ) ) {
	include_once wcbs()->plugin_path() . 'includes/wpheka-ui/load.php';
	include_once wcbs()->plugin_path() . 'includes/class-wc-backorder-split-overview.php';
}
require_once ABSPATH . 'wp-admin/includes/plugin.php';

$render = function ( $tab ) {
	$_GET['tab'] = $tab;
	ob_start();
	WC_Backorder_Split_Overview::render();
	return ob_get_clean();
};
$stat = function ( $html, $name ) {
	// The value of the stat card with this label.
	if ( preg_match( '#<p class="w-stat-label">.*?</span>' . preg_quote( $name, '#' ) . '</p><p class="w-stat-value">(.*?)</p>#s', $html, $m ) ) {
		return trim( wp_strip_all_tags( $m[1] ) );
	}
	return null;
};
// A split order: stock 1, buy 3, so 2 move. $days_ago backdates the backorder.
$split = function ( $days_ago = 0, $complete = false ) use ( $ids, &$made ) {
	wc_update_product_stock( wc_get_product( $ids['s1'] ), 1, 'set' );
	$o = wc_create_order();
	$o->set_billing_email( 'zz-wcbs-guest@example.invalid' );
	$o->add_product( wc_get_product( $ids['s1'] ), 3 );
	foreach ( $o->get_items() as $line ) {
		$line->add_meta_data( '_stock_quantity_at_add', 1, true );
	}
	$o->calculate_totals();
	$o->set_status( 'processing' );
	$o->save();
	$made[] = $o->get_id();
	WC_Backorder_Split_Frontend::split_backorder_products( $o->get_id() );
	$c = wc_get_order( (int) wc_get_order( $o->get_id() )->get_meta( '_wcbs_backorder_id' ) );
	if ( $c ) {
		$made[] = $c->get_id();
		if ( $days_ago ) {
			$c->set_date_created( time() - $days_ago * DAY_IN_SECONDS );
		}
		if ( $complete ) {
			$c->set_status( 'completed' );
		}
		$c->save();
	}
	return $c;
};

try {
	$ctrl    = wc_get_container()->get( Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class );
	$on_hpos = $ctrl->custom_orders_table_usage_is_enabled();
	if ( ( 'legacy' === $mode ) === $on_hpos ) {
		wcbs_t_result( "$label: running on the expected order storage", false, 'HPOS is ' . ( $on_hpos ? 'on' : 'off' ) );
		return;
	}

	// The plugin's own cache-reset hook, as on a real request.
	add_action( 'wcbs_backorder_created', array( wcbs(), 'flush_overview_stats' ) );

	wp_set_current_user( $ids['admin'] );
	delete_transient( WC_Backorder_Split_Overview::STATS_TRANSIENT );
	$before = WC_Backorder_Split_Overview::stats();

	$split( 0 );
	$split( 10, true );
	$split( 0 );
	$after = WC_Backorder_Split_Overview::stats();
	wcbs_t_result( "$label: Orders split counts each new backorder", 3 === $after['split'] - $before['split'], wp_json_encode( array( $before, $after ) ) );
	wcbs_t_result( "$label: last 7 days leaves out an older split", 2 === $after['last7'] - $before['last7'], wp_json_encode( array( $before, $after ) ) );
	wcbs_t_result( "$label: Backorders completed counts the completed one", 1 === $after['completed'] - $before['completed'], wp_json_encode( array( $before, $after ) ) );
	wcbs_t_result( "$label: Items moved adds 2 per split", 6.0 === $after['items'] - $before['items'], wp_json_encode( array( $before, $after ) ) );

	// Cached until the next split, which resets it.
	$cached = get_transient( WC_Backorder_Split_Overview::STATS_TRANSIENT );
	wcbs_t_result( "$label: counts are cached", is_array( $cached ) && ! empty( $cached[ 'legacy' === $mode ? 'posts' : 'hpos' ] ), wp_json_encode( $cached ) );
	$split( 0 );
	wcbs_t_result( "$label: a new split clears the cached counts", false === get_transient( WC_Backorder_Split_Overview::STATS_TRANSIENT ) && 4 === WC_Backorder_Split_Overview::stats()['split'] - $before['split'], wp_json_encode( get_transient( WC_Backorder_Split_Overview::STATS_TRANSIENT ) ) );

	$html = $render( 'overview' );
	wcbs_t_result( "$label: page renders for an administrator", false !== strpos( $html, 'class="wpheka-ui' ) && false !== strpos( $html, 'aria-current="page">Overview' ), substr( wp_strip_all_tags( $html ), 0, 200 ) );
	wcbs_t_result( "$label: no form and no save bar", false === strpos( $html, 'data-w-form' ) && false === strpos( $html, 'w-savebar' ), 'form markup found' );
	$stats = WC_Backorder_Split_Overview::stats();
	wcbs_t_result( "$label: Orders split card shows the count", number_format_i18n( $stats['split'] ) === $stat( $html, 'Orders split' ), var_export( $stat( $html, 'Orders split' ), true ) );
	wcbs_t_result( "$label: Backordered now card matches WooCommerce's count", number_format_i18n( (int) wc_orders_count( 'backordered' ) ) === $stat( $html, 'Backordered now' ), var_export( $stat( $html, 'Backordered now' ), true ) . ' vs ' . wc_orders_count( 'backordered' ) );

	$newest = (int) end( $made );
	preg_match_all( '#<tr data-order="(\d+)"#', $html, $rows );
	wcbs_t_result( "$label: Recent splits lists five, newest first", 5 >= count( $rows[1] ) && $newest === (int) ( $rows[1][0] ?? 0 ), wp_json_encode( $rows[1] ) . " newest $newest" );
	wcbs_t_result( "$label: Recent splits links the original order", false !== strpos( $html, 'ZZ WCBS Stock1 × 2' ) && false !== strpos( $html, 'View all Backordered orders' ), 'row content missing' );

	$help = $render( 'help' );
	wcbs_t_result( "$label: Help tab has four link cards", 4 === substr_count( $help, 'w-card w-flow-step' ) && false !== strpos( $help, 'https://docs.wpheka.com/plugin/wc-backorder-split' ), substr_count( $help, 'w-card w-flow-step' ) . ' cards' );
	wcbs_t_result( "$label: Help lists the developer hooks", false !== strpos( $help, '<code>wcbs_copy_charges_to_backorder</code>' ) && false !== strpos( $help, '<code>wcbs_should_split_order</code>' ), 'hooks missing' );
	wcbs_t_result( "$label: an unknown tab shows Overview", false !== strpos( $render( 'nope' ), 'aria-current="page">Overview' ), 'other tab shown' );

	if ( 'hpos' === $mode ) {
		// Who may open it: shop managers yes, subscribers and customers no.
		$sm = wp_insert_user( array( 'user_login' => 'zz-wcbs-sm-' . wp_rand(), 'user_pass' => wp_generate_password(), 'user_email' => 'zz-wcbs-sm-' . wp_rand() . '@example.invalid', 'role' => 'shop_manager' ) );
		wp_set_current_user( $sm );
		wcbs_t_result( "$label: a shop manager can open the page", false !== strpos( $render( 'overview' ), 'class="wpheka-ui' ), 'empty for shop manager' );
		wp_set_current_user( $ids['sub'] );
		wcbs_t_result( "$label: a subscriber gets nothing", '' === trim( $render( 'overview' ) ), 'page shown to a subscriber' );
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $sm );
		wp_set_current_user( $ids['admin'] );

		// The Plugins screen row and the shared WPHEKA menu.
		$row = WC_Backorder_Split_Overview::action_links( array( 'deactivate' => 'x' ) );
		wcbs_t_result( "$label: Overview link comes first in the plugin row", 'overview' === array_key_first( $row ) && false !== strpos( $row['overview'], 'page=wc-backorder-split' ), wp_json_encode( $row ) );

		global $menu, $submenu;
		$menu    = array();
		$submenu = array();
		WC_Backorder_Split_Overview::register_page();
		WC_Backorder_Split_Overview::register_page();
		$parents = array_filter( (array) $menu, function ( $m ) { return 'wpheka_plugin_panel' === ( $m[2] ?? '' ); } );
		$pages   = array_filter( (array) ( $submenu['wpheka_plugin_panel'] ?? array() ), function ( $m ) { return 'wc-backorder-split' === ( $m[2] ?? '' ); } );
		wcbs_t_result( "$label: one WPHEKA parent menu with the page under it", 1 === count( $parents ) && count( $pages ) >= 1, count( $parents ) . ' parents, ' . count( $pages ) . ' pages' );
	}
} catch ( Throwable $e ) {
	wcbs_t_result( "$label: ran without an exception", false, get_class( $e ) . ': ' . $e->getMessage() . ' @' . $e->getLine() );
} finally {
	foreach ( array_reverse( $made ) as $made_id ) {
		$made_order = wc_get_order( $made_id );
		if ( $made_order ) {
			$made_order->delete( true );
		}
	}
	delete_transient( WC_Backorder_Split_Overview::STATS_TRANSIENT );
}

wcbs_t_result( "overview.php ($mode): ran to the end", true );
