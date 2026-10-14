<?php
/**
 * Test orders for bulk.js: bulk-orders.php create|status|delete [ids...]
 *
 * Orders for bulk.js on the order storage that is active now:
 * - create: two Processing orders for "ZZ WCBS Stock5"; prints their ids, the storage and
 *   the orders screen URL as JSON;
 * - status <id> <id>: each order's status as JSON;
 * - delete <id> <id>: deletes them. bulk.js always calls this, because orders created on
 *   legacy storage are not in the HPOS table cleanup.php searches.
 *
 * @package WC_Backorder_Split
 */

// The suite's shared helpers.
require __DIR__ . '/lib.php';

$wcbs_mode = $args[0] ?? '';
$wcbs_ids  = array_map( 'absint', array_slice( $args, 1 ) );
$wcbs_ctrl = wc_get_container()->get( Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class );
$wcbs_hpos = $wcbs_ctrl->custom_orders_table_usage_is_enabled();

if ( 'create' === $wcbs_mode ) {
	$wcbs_t       = wcbs_t_ids();
	$wcbs_created = array();
	for ( $wcbs_i = 1; $wcbs_i <= 2; $wcbs_i++ ) {
		$wcbs_order = wc_create_order();
		$wcbs_order->add_product( wc_get_product( $wcbs_t['s5'] ), 1 );
		$wcbs_order->set_billing_email( 'zz-wcbs-bulk@example.invalid' );
		$wcbs_order->set_billing_first_name( 'Zz Bulk ' . $wcbs_i );
		$wcbs_order->calculate_totals();
		$wcbs_order->set_status( 'processing' );
		$wcbs_order->save();
		$wcbs_created[] = $wcbs_order->get_id();
	}
	echo wp_json_encode(
		array(
			'ids'     => $wcbs_created,
			'storage' => $wcbs_hpos ? 'hpos' : 'legacy',
			'list'    => $wcbs_hpos ? admin_url( 'admin.php?page=wc-orders' ) : admin_url( 'edit.php?post_type=shop_order' ),
		)
	);
} elseif ( 'status' === $wcbs_mode ) {
	$wcbs_status = array();
	foreach ( $wcbs_ids as $wcbs_id ) {
		$wcbs_order              = wc_get_order( $wcbs_id );
		$wcbs_status[ $wcbs_id ] = $wcbs_order ? $wcbs_order->get_status() : null;
	}
	echo wp_json_encode( $wcbs_status );
} elseif ( 'delete' === $wcbs_mode ) {
	foreach ( $wcbs_ids as $wcbs_id ) {
		$wcbs_order = wc_get_order( $wcbs_id );
		if ( $wcbs_order ) {
			$wcbs_order->delete( true );
		}
	}
	echo wp_json_encode( array( 'deleted' => $wcbs_ids ) );
}
