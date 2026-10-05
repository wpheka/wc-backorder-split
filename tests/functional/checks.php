<?php
/**
 * checks.php internals|legacy
 *
 * Exercises the split directly on orders built in code, so every part of what
 * is copied can be asserted: shipping, fees, coupons, notes, the two-way links,
 * the custom status and that a second run does nothing. "legacy" runs the same
 * on WooCommerce's posts storage and switches back to HPOS afterwards.
 */
require __DIR__ . '/lib.php';

$mode = $args[0] ?? 'internals';
$ids  = wcbs_t_ids();
$ctrl = wc_get_container()->get( Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class );

// run.sh switches the storage between requests; confirm this one really is on it.
$on_hpos = $ctrl->custom_orders_table_usage_is_enabled();
$label   = 'legacy' === $mode ? 'legacy storage' : 'HPOS';
if ( ( 'legacy' === $mode ) === $on_hpos ) {
	wcbs_t_result( "$label: running on the expected order storage", false, 'HPOS is ' . ( $on_hpos ? 'on' : 'off' ) );
	return;
}

try {
	if ( 'internals' === $mode ) {
		$statuses = wc_get_order_statuses();
		wcbs_t_result( 'custom "Backordered" order status is registered', isset( $statuses['wc-backordered'] ), wp_json_encode( array_keys( $statuses ) ) );
	}

	// Coupons may be switched off on the site; enable them for this request only.
	add_filter( 'pre_option_woocommerce_coupons_enabled', function () { return 'yes'; } );
	if ( empty( $ids['coupon'] ) ) {
		$c = new WC_Coupon();
		$c->set_code( 'zzwcbs5' );
		$c->set_discount_type( 'fixed_cart' );
		$c->set_amount( 5 );
		$ids['coupon'] = $c->save();
		wcbs_t_save_ids( $ids );
	}

	wc_update_product_stock( wc_get_product( $ids['s1'] ), 1, 'set' );
	$order = wc_create_order();
	$order->set_billing_email( 'zz-wcbs-guest@example.invalid' );
	$order->set_billing_first_name( 'Zz' );
	$order->set_shipping_first_name( 'Zz' );
	$order->set_shipping_address_1( '1 Test St' );
	$order->set_payment_method( 'cod' );
	$order->add_product( wc_get_product( $ids['s1'] ), 3 );
	// On the order's own item object: get_item() returns a copy, and the
	// order's save would write its cached item back without the meta.
	foreach ( $order->get_items() as $line ) {
		$line->add_meta_data( '_stock_quantity_at_add', 1, true );
	}
	$ship = new WC_Order_Item_Shipping();
	$ship->set_method_title( 'ZZ Flat rate' );
	$ship->set_method_id( 'flat_rate' );
	$ship->set_total( 7 );
	$order->add_item( $ship );
	$fee = new WC_Order_Item_Fee();
	$fee->set_name( 'ZZ Handling' );
	$fee->set_total( 2 );
	$order->add_item( $fee );
	$order->apply_coupon( 'zzwcbs5' );
	$order->calculate_totals();
	$order->set_status( 'processing' );
	$order->save();
	$oid = $order->get_id();
	$recorded = array_map( function ( $i ) { return $i->get_meta( '_stock_quantity_at_add' ); }, array_values( wc_get_order( $oid )->get_items() ) );
	if ( array( '1' ) !== array_map( 'strval', $recorded ) ) {
		wcbs_t_result( "$label: test order built with the recorded stock level", false, wp_json_encode( $recorded ) );
	}

	WC_Backorder_Split_Frontend::split_backorder_products( $oid );

	$order    = wc_get_order( $oid );
	$child_id = (int) $order->get_meta( '_wcbs_backorder_id' );
	$child    = $child_id ? wc_get_order( $child_id ) : null;
	wcbs_t_result( "$label: split creates a backorder order linked both ways", $child && (int) $child->get_meta( '_wcbs_parent_order_id' ) === $oid, "order #$oid child " . var_export( $child_id, true ) );
	if ( $child ) {
		wcbs_t_result( "$label: quantities move (1 kept, 2 backordered)", array( 'ZZ WCBS Stock1' => 1 ) === wcbs_t_items( $order ) && array( 'ZZ WCBS Stock1' => 2 ) === wcbs_t_items( $child ), wp_json_encode( array( wcbs_t_items( $order ), wcbs_t_items( $child ) ) ) );
		wcbs_t_result( "$label: backorder order has the Backordered status", 'backordered' === $child->get_status(), $child->get_status() );
		$titles = array_map( function ( $i ) { return $i->get_method_title(); }, array_values( $child->get_items( 'shipping' ) ) );
		wcbs_t_result( "$label: shipping copied to the backorder order", in_array( 'ZZ Flat rate', $titles, true ), wp_json_encode( $titles ) );
		$fees = array_map( function ( $i ) { return $i->get_name(); }, array_values( $child->get_items( 'fee' ) ) );
		wcbs_t_result( "$label: fees copied to the backorder order", in_array( 'ZZ Handling', $fees, true ), wp_json_encode( $fees ) );
		wcbs_t_result( "$label: coupon copied to the backorder order", in_array( 'zzwcbs5', $child->get_coupon_codes(), true ), wp_json_encode( $child->get_coupon_codes() ) );
		wcbs_t_result( "$label: customer details copied", 'zz-wcbs-guest@example.invalid' === $child->get_billing_email() && '1 Test St' === $child->get_shipping_address_1(), $child->get_billing_email() . ' / ' . $child->get_shipping_address_1() );
		$notes = function ( $id ) { return implode( ' | ', array_map( function ( $n ) { return wp_strip_all_tags( $n->content ); }, wc_get_order_notes( array( 'order_id' => $id ) ) ) ); };
		wcbs_t_result( "$label: order notes link the two orders", false !== strpos( $notes( $oid ), (string) $child_id ) && false !== strpos( $notes( $child_id ), (string) $oid ), $notes( $oid ) . ' // ' . $notes( $child_id ) );
	}

	WC_Backorder_Split_Frontend::split_backorder_products( $oid );
	$again = (int) wc_get_order( $oid )->get_meta( '_wcbs_backorder_id' );
	global $wpdb;
	$children = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders_meta WHERE meta_key = '_wcbs_parent_order_id' AND meta_value = %s", (string) $oid ) );
	if ( 'legacy' === $mode ) {
		$children = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_wcbs_parent_order_id' AND meta_value = %s", (string) $oid ) );
	}
	wcbs_t_result( "$label: running the split twice creates no second backorder", $again === $child_id && 1 === $children, "children: $children" );
} catch ( Throwable $e ) {
	wcbs_t_result( "$label: split ran without an exception", false, get_class( $e ) . ': ' . $e->getMessage() );
}
