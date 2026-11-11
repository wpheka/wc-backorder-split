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

$oid      = 0;
$child_id = 0;
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
	$ship->add_meta_data( 'Items', 'ZZ WCBS Stock1 &times; 3', true );
	$order->add_item( $ship );
	$fee = new WC_Order_Item_Fee();
	$fee->set_name( 'ZZ Handling' );
	$fee->set_total( 2 );
	$order->add_item( $fee );
	$order->apply_coupon( 'zzwcbs5' );
	$order->calculate_totals();
	$order->set_status( 'processing' );
	$order->save();
	$oid  = $order->get_id();
	$paid = (float) $order->get_total();
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
		wcbs_t_result( "$label: shipping method copied to the backorder order", array( 'ZZ Flat rate' ) === $titles, wp_json_encode( $titles ) );
		wcbs_t_result( "$label: backorder shipping costs nothing", 0.0 === (float) $child->get_shipping_total() && 0.0 === (float) $child->get_shipping_tax(), $child->get_shipping_total() . ' tax ' . $child->get_shipping_tax() );
		wcbs_t_result( "$label: fees and coupon stay on the original only", ! $child->get_items( 'fee' ) && ! $child->get_coupon_codes() && $order->get_items( 'fee' ) && array( 'zzwcbs5' ) === $order->get_coupon_codes(), wp_json_encode( array( count( $child->get_items( 'fee' ) ), $child->get_coupon_codes(), $order->get_coupon_codes() ) ) );
		wcbs_t_result( "$label: original keeps its shipping cost", 7.0 === (float) $order->get_shipping_total(), $order->get_shipping_total() );
		wcbs_t_result( "$label: the two orders add up to what the customer paid", abs( (float) $order->get_total() + (float) $child->get_total() - $paid ) < 0.001, $order->get_total() . ' + ' . $child->get_total() . " vs $paid" );
		wcbs_t_result( "$label: customer details copied", 'zz-wcbs-guest@example.invalid' === $child->get_billing_email() && '1 Test St' === $child->get_shipping_address_1(), $child->get_billing_email() . ' / ' . $child->get_shipping_address_1() );
		$notes = function ( $id ) { return implode( ' | ', array_map( function ( $n ) { return wp_strip_all_tags( $n->content ); }, wc_get_order_notes( array( 'order_id' => $id ) ) ) ); };
		wcbs_t_result( "$label: order notes link the two orders", false !== strpos( $notes( $oid ), (string) $child_id ) && false !== strpos( $notes( $child_id ), (string) $oid ), $notes( $oid ) . ' // ' . $notes( $child_id ) );
		wcbs_t_result( "$label: backorder note says where shipping was charged", false !== strpos( $notes( $child_id ), "Shipping was charged on order #$oid." ), $notes( $child_id ) );
		$items_meta = array_map( function ( $i ) { return $i->get_meta( 'Items' ); }, array_values( $child->get_items( 'shipping' ) ) );
		wcbs_t_result( "$label: backorder shipping lists only its own items", array( 'ZZ WCBS Stock1 &times; 2' ) === $items_meta, wp_json_encode( $items_meta ) );

		// The order screen must not list the internal stock reading under the
		// product. WP-CLI is not an admin request, so load the admin hooks as
		// one would, then render WooCommerce's own item meta view.
		if ( ! class_exists( 'WC_Backorder_Split_Admin' ) ) {
			include_once wcbs()->plugin_path() . 'includes/class-wc-backorder-split-admin.php';
		}
		WC_Backorder_Split_Admin::init();
		$meta_view = function ( $o ) {
			$html = '';
			foreach ( $o->get_items() as $item_id => $item ) {
				ob_start();
				include WC_ABSPATH . 'includes/admin/meta-boxes/views/html-order-item-meta.php';
				$html .= ob_get_clean();
			}
			return $html;
		};

		$kept = array_map(
			function ( $i ) {
				return $i->get_meta( '_stock_quantity_at_add' );
			},
			array_values( $order->get_items() )
		);
		// A view that fails to render would hide the key too, so require real item meta output.
		$views = $meta_view( $order ) . $meta_view( $child );
		wcbs_t_result( "$label: order screen hides _stock_quantity_at_add on both orders", false !== strpos( $views, 'class="view"' ) && false === strpos( $views, '_stock_quantity_at_add' ), '' === $views ? 'the item meta view rendered nothing' : 'shown in the item meta' );
		wcbs_t_result( "$label: hidden _stock_quantity_at_add is still stored on the item", array( '1' ) === array_map( 'strval', $kept ), wp_json_encode( $kept ) );
	}

	WC_Backorder_Split_Frontend::split_backorder_products( $oid );
	$again = (int) wc_get_order( $oid )->get_meta( '_wcbs_backorder_id' );
	global $wpdb;
	$children = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders_meta WHERE meta_key = '_wcbs_parent_order_id' AND meta_value = %s", (string) $oid ) );
	if ( 'legacy' === $mode ) {
		$children = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_wcbs_parent_order_id' AND meta_value = %s", (string) $oid ) );
	}
	wcbs_t_result( "$label: running the split twice creates no second backorder", $again === $child_id && 1 === $children, "children: $children" );

	// More money cases on their own orders, deleted at once on either storage.
	$money_case = function ( $name, $setup ) use ( $ids, $label ) {
		$made = array();
		try {
			wc_update_product_stock( wc_get_product( $ids['s1'] ), 1, 'set' );
			$o = wc_create_order();
			$o->set_billing_email( 'zz-wcbs-guest@example.invalid' );
			$o->set_payment_method( 'cod' );
			$o->add_product( wc_get_product( $ids['s1'] ), 3 );
			foreach ( $o->get_items() as $line ) {
				$line->add_meta_data( '_stock_quantity_at_add', 1, true );
			}
			$s = new WC_Order_Item_Shipping();
			$s->set_method_title( 'ZZ Flat rate' );
			$s->set_method_id( 'flat_rate' );
			$s->set_total( 7 );
			$o->add_item( $s );
			$setup( $o );
			$o->calculate_totals();
			$o->set_status( 'processing' );
			$o->save();
			$made[] = $o->get_id();
			$paid   = (float) $o->get_total();

			WC_Backorder_Split_Frontend::split_backorder_products( $o->get_id() );
			$o      = wc_get_order( $o->get_id() );
			$c      = wc_get_order( (int) $o->get_meta( '_wcbs_backorder_id' ) );
			$made[] = $c ? $c->get_id() : 0;
			return array( $o, $c, $paid );
		} finally {
			// Deleted in the outer finally, also when a case throws.
			$GLOBALS['wcbs_t_made'] = array_merge( $GLOBALS['wcbs_t_made'] ?? array(), $made );
		}
	};

	// A failed payment also reaches the confirmation page. The order stays whole and
	// failed, then splits once a retried payment succeeds.
	foreach ( array( 'mixed' => 1, 'all backordered' => 0 ) as $case => $stock_at_add ) {
		wc_update_product_stock( wc_get_product( $ids['s1'] ), $stock_at_add, 'set' );
		$f = wc_create_order();
		$f->set_billing_email( 'zz-wcbs-guest@example.invalid' );
		$f->set_payment_method( 'cod' );
		$f->add_product( wc_get_product( $ids['s1'] ), 3 );
		foreach ( $f->get_items() as $line ) {
			$line->add_meta_data( '_stock_quantity_at_add', $stock_at_add, true );
		}
		$f->calculate_totals();
		$f->set_status( 'failed' );
		$f->save();
		$fid                    = $f->get_id();
		$GLOBALS['wcbs_t_made'][] = $fid;
		WC_Backorder_Split_Frontend::split_backorder_products( $fid );
		$f = wc_get_order( $fid );
		wcbs_t_result( "$label: failed order ($case) is left whole and failed", 'failed' === $f->get_status() && ! $f->get_meta( '_wcbs_backorder_id' ) && ! $f->get_meta( '_wcbs_processed' ) && array( 'ZZ WCBS Stock1' => 3 ) === wcbs_t_items( $f ), $f->get_status() . ' ' . wp_json_encode( wcbs_t_items( $f ) ) );

		$f->set_status( 'processing' );
		$f->save();
		WC_Backorder_Split_Frontend::split_backorder_products( $fid );
		$f    = wc_get_order( $fid );
		$fcid = (int) $f->get_meta( '_wcbs_backorder_id' );
		if ( $fcid ) {
			$GLOBALS['wcbs_t_made'][] = $fcid;
		}
		$ok = $stock_at_add ? ( $fcid && array( 'ZZ WCBS Stock1' => 1 ) === wcbs_t_items( $f ) ) : 'backordered' === $f->get_status();
		wcbs_t_result( "$label: failed order ($case) splits after a successful retry", $ok, $f->get_status() . ' child ' . $fcid . ' ' . wp_json_encode( wcbs_t_items( $f ) ) );
	}

	// Orders still pending at the confirmation page (redirect and asynchronous
	// gateways) wait for the payment, then split at the end of the request that
	// confirms it, with no emails from the split itself.
	$new_order = function ( $stock_at_add, $status ) use ( $ids ) {
		wc_update_product_stock( wc_get_product( $ids['s1'] ), $stock_at_add, 'set' );
		$o = wc_create_order();
		$o->set_billing_email( 'zz-wcbs-guest@example.invalid' );
		$o->set_payment_method( 'cod' );
		$o->add_product( wc_get_product( $ids['s1'] ), 3 );
		foreach ( $o->get_items() as $line ) {
			$line->add_meta_data( '_stock_quantity_at_add', $stock_at_add, true );
		}
		$o->calculate_totals();
		$o->set_status( $status );
		$o->save();
		$GLOBALS['wcbs_t_made'][] = $o->get_id();
		return $o->get_id();
	};
	$child_of = function ( $id ) {
		$cid = (int) wc_get_order( $id )->get_meta( '_wcbs_backorder_id' );
		if ( $cid ) {
			$GLOBALS['wcbs_t_made'][] = $cid;
		}
		return $cid;
	};
	$mails = 0;
	$count_mail = function ( $pre ) use ( &$mails ) {
		++$mails;
		return true; // Short-circuits wp_mail: nothing is sent.
	};
	foreach ( array( 'mixed' => 1, 'all backordered' => 0 ) as $case => $stock_at_add ) {
		$pid = $new_order( $stock_at_add, 'pending' );
		WC_Backorder_Split_Frontend::split_backorder_products( $pid );
		$p = wc_get_order( $pid );
		wcbs_t_result( "$label: pending order ($case) is left whole at the confirmation page", 'pending' === $p->get_status() && ! $p->get_meta( '_wcbs_backorder_id' ) && ! $p->get_meta( '_wcbs_processed' ) && 'yes' === $p->get_meta( '_wcbs_awaiting_payment' ) && array( 'ZZ WCBS Stock1' => 3 ) === wcbs_t_items( $p ), $p->get_status() . ' ' . wp_json_encode( wcbs_t_items( $p ) ) );

		$p->update_status( 'processing' );
		$p = wc_get_order( $pid );
		wcbs_t_result( "$label: pending order ($case) is not split inside the request that confirms payment", array( 'ZZ WCBS Stock1' => 3 ) === wcbs_t_items( $p ) && ! $p->get_meta( '_wcbs_backorder_id' ), wp_json_encode( wcbs_t_items( $p ) ) );

		$mails = 0;
		add_filter( 'pre_wp_mail', $count_mail );
		WC_Backorder_Split_Frontend::run_deferred_splits(); // What the shutdown hook runs.
		remove_filter( 'pre_wp_mail', $count_mail );
		$p   = wc_get_order( $pid );
		$pcid = $child_of( $pid );
		$ok  = $stock_at_add ? ( $pcid && array( 'ZZ WCBS Stock1' => 1 ) === wcbs_t_items( $p ) && array( 'ZZ WCBS Stock1' => 2 ) === wcbs_t_items( wc_get_order( $pcid ) ) ) : 'backordered' === $p->get_status();
		wcbs_t_result( "$label: pending order ($case) splits once payment is confirmed", $ok && '' === $p->get_meta( '_wcbs_awaiting_payment' ), $p->get_status() . ' child ' . $pcid . ' ' . wp_json_encode( wcbs_t_items( $p ) ) );
		wcbs_t_result( "$label: the deferred split ($case) sends no email", 0 === $mails, "$mails sent" );
	}

	// A pending order that never reached the confirmation page (an admin order
	// marked paid, say) is not split by a status change: unchanged behaviour.
	$uid = $new_order( 1, 'pending' );
	wc_get_order( $uid )->update_status( 'processing' );
	WC_Backorder_Split_Frontend::run_deferred_splits();
	wcbs_t_result( "$label: an unmarked pending order marked paid is not split", ! $child_of( $uid ) && array( 'ZZ WCBS Stock1' => 3 ) === wcbs_t_items( wc_get_order( $uid ) ) );

	// On hold is not paid: a marked order waits for processing or completed.
	$hid = $new_order( 1, 'pending' );
	WC_Backorder_Split_Frontend::split_backorder_products( $hid );
	wc_get_order( $hid )->update_status( 'on-hold' );
	WC_Backorder_Split_Frontend::run_deferred_splits();
	$h = wc_get_order( $hid );
	wcbs_t_result( "$label: a marked order put on hold is not split yet", ! $child_of( $hid ) && 'yes' === $h->get_meta( '_wcbs_awaiting_payment' ) && array( 'ZZ WCBS Stock1' => 3 ) === wcbs_t_items( $h ), wp_json_encode( wcbs_t_items( $h ) ) );
	$h->update_status( 'processing' );
	WC_Backorder_Split_Frontend::run_deferred_splits();
	wcbs_t_result( "$label: the same order splits once it is processing", (bool) $child_of( $hid ) && array( 'ZZ WCBS Stock1' => 1 ) === wcbs_t_items( wc_get_order( $hid ) ) );

	// Queued when it was paid, back on hold before the end of the request: still whole.
	$bid = $new_order( 1, 'pending' );
	WC_Backorder_Split_Frontend::split_backorder_products( $bid );
	wc_get_order( $bid )->update_status( 'processing' );
	wc_get_order( $bid )->update_status( 'on-hold' );
	WC_Backorder_Split_Frontend::run_deferred_splits();
	$b = wc_get_order( $bid );
	wcbs_t_result( "$label: a marked order back on hold before the deferred split stays whole", ! $child_of( $bid ) && 'yes' === $b->get_meta( '_wcbs_awaiting_payment' ) && array( 'ZZ WCBS Stock1' => 3 ) === wcbs_t_items( $b ), $b->get_status() . ' ' . wp_json_encode( wcbs_t_items( $b ) ) );

	// A paid status a store adds through WooCommerce's filter counts as paid.
	$paid_filter = function ( $statuses ) {
		return array_merge( $statuses, array( 'on-hold' ) );
	};
	add_filter( 'woocommerce_order_is_paid_statuses', $paid_filter );
	$cid = $new_order( 1, 'pending' );
	WC_Backorder_Split_Frontend::split_backorder_products( $cid );
	wc_get_order( $cid )->update_status( 'on-hold' );
	WC_Backorder_Split_Frontend::run_deferred_splits();
	remove_filter( 'woocommerce_order_is_paid_statuses', $paid_filter );
	wcbs_t_result( "$label: a store's own paid status splits a marked order", (bool) $child_of( $cid ) && array( 'ZZ WCBS Stock1' => 1 ) === wcbs_t_items( wc_get_order( $cid ) ) );

	// A deferred split that fails keeps the mark, so the next paid status change retries it.
	$eid  = $new_order( 1, 'pending' );
	WC_Backorder_Split_Frontend::split_backorder_products( $eid );
	$boom = function () {
		throw new Exception( 'ZZ test failure' );
	};
	add_action( 'wcbs_before_split_order', $boom );
	wc_get_order( $eid )->update_status( 'processing' );
	WC_Backorder_Split_Frontend::run_deferred_splits();
	remove_action( 'wcbs_before_split_order', $boom );
	$e = wc_get_order( $eid );
	wcbs_t_result( "$label: a failed deferred split keeps the payment mark", ! $child_of( $eid ) && 'yes' === $e->get_meta( '_wcbs_awaiting_payment' ), 'mark: ' . $e->get_meta( '_wcbs_awaiting_payment' ) );
	$e->update_status( 'completed' );
	WC_Backorder_Split_Frontend::run_deferred_splits();
	$e = wc_get_order( $eid );
	wcbs_t_result( "$label: it splits on the next paid status change", (bool) $child_of( $eid ) && '' === $e->get_meta( '_wcbs_awaiting_payment' ) && array( 'ZZ WCBS Stock1' => 1 ) === wcbs_t_items( $e ), wp_json_encode( wcbs_t_items( $e ) ) );

	// The payment failed in another request after this one loaded the order:
	// the status is checked again once the lock is held.
	$xid = $new_order( 1, 'processing' );
	wc_get_order( $xid ); // Loaded, and cached, while still processing.
	if ( Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
		$GLOBALS['wpdb']->update( "{$GLOBALS['wpdb']->prefix}wc_orders", array( 'status' => 'wc-failed' ), array( 'id' => $xid ) );
	} else {
		$GLOBALS['wpdb']->update( $GLOBALS['wpdb']->posts, array( 'post_status' => 'wc-failed' ), array( 'ID' => $xid ) );
	}
	WC_Backorder_Split_Frontend::split_backorder_products( $xid );
	wcbs_t_result( "$label: an order that failed in another request is not split", ! $child_of( $xid ) && array( 'ZZ WCBS Stock1' => 3 ) === wcbs_t_items( wc_get_order( $xid ) ) );

	// The payment is confirmed in another request while the confirmation page
	// saves its mark: the stored status is paid, so the page splits at once.
	$rid  = $new_order( 1, 'pending' );
	$race = function ( $order ) use ( $rid, &$race ) {
		global $wpdb;
		if ( $order->get_id() !== $rid ) {
			return;
		}
		remove_action( 'woocommerce_after_order_object_save', $race );
		if ( Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
			$wpdb->update( "{$wpdb->prefix}wc_orders", array( 'status' => 'wc-processing' ), array( 'id' => $rid ) );
		} else {
			$wpdb->update( $wpdb->posts, array( 'post_status' => 'wc-processing' ), array( 'ID' => $rid ) );
		}
	};
	add_action( 'woocommerce_after_order_object_save', $race );
	WC_Backorder_Split_Frontend::split_backorder_products( $rid );
	remove_action( 'woocommerce_after_order_object_save', $race );
	$rcid = $child_of( $rid );
	wcbs_t_result( "$label: payment confirmed while the page saved its mark still splits", $rcid && array( 'ZZ WCBS Stock1' => 1 ) === wcbs_t_items( wc_get_order( $rid ) ), 'child ' . $rcid );

	// Lock: a split already running elsewhere stops this one; an expired lock does not.
	global $wpdb;
	$lid = $new_order( 1, 'processing' );
	$wpdb->insert( $wpdb->options, array( 'option_name' => 'wcbs_split_lock_' . $lid, 'option_value' => (string) time(), 'autoload' => 'no' ) );
	WC_Backorder_Split_Frontend::split_backorder_products( $lid );
	wcbs_t_result( "$label: an order locked by another split is not split again", ! $child_of( $lid ) && array( 'ZZ WCBS Stock1' => 3 ) === wcbs_t_items( wc_get_order( $lid ) ) );
	$wpdb->update( $wpdb->options, array( 'option_value' => (string) ( time() - 600 ) ), array( 'option_name' => 'wcbs_split_lock_' . $lid ) );
	WC_Backorder_Split_Frontend::split_backorder_products( $lid );
	$lock_left = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name = %s", 'wcbs_split_lock_' . $lid ) );
	wcbs_t_result( "$label: an expired lock is taken over and released after the split", $child_of( $lid ) && 0 === $lock_left, "locks left: $lock_left" );

	// Another request split the order after this one loaded it: the database
	// check under the lock stops a second backorder.
	$sid = $new_order( 1, 'processing' );
	wc_get_order( $sid ); // Loaded, and cached, before the other request's split.
	if ( Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
		$wpdb->insert( "{$wpdb->prefix}wc_orders_meta", array( 'order_id' => $sid, 'meta_key' => '_wcbs_backorder_id', 'meta_value' => '999999' ) );
	} else {
		$wpdb->insert( $wpdb->postmeta, array( 'post_id' => $sid, 'meta_key' => '_wcbs_backorder_id', 'meta_value' => '999999' ) );
	}
	WC_Backorder_Split_Frontend::split_backorder_products( $sid );
	$parents = Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()
		? (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders_meta WHERE meta_key = '_wcbs_parent_order_id' AND meta_value = %s", (string) $sid ) )
		: (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_wcbs_parent_order_id' AND meta_value = %s", (string) $sid ) );
	wcbs_t_result( "$label: an order split by another request is not split again", 0 === $parents, "backorders: $parents" );

	// The payment details another request saved after this one loaded the order
	// (a notification recording the transaction) reach the backorder.
	$tid = $new_order( 1, 'processing' );
	wc_get_order( $tid ); // Loaded, and cached, before the notification.
	if ( Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
		$wpdb->update( "{$wpdb->prefix}wc_orders", array( 'transaction_id' => 'ZZTXN1' ), array( 'id' => $tid ) );
	} else {
		// Straight to the table, as another request would: this request's cache keeps the old value.
		$wpdb->delete( $wpdb->postmeta, array( 'post_id' => $tid, 'meta_key' => '_transaction_id' ) );
		$wpdb->insert( $wpdb->postmeta, array( 'post_id' => $tid, 'meta_key' => '_transaction_id', 'meta_value' => 'ZZTXN1' ) );
	}
	WC_Backorder_Split_Frontend::split_backorder_products( $tid );
	$tcid = $child_of( $tid );
	$txn  = $tcid ? wc_get_order( $tcid )->get_transaction_id() : '';
	wcbs_t_result( "$label: the backorder gets payment details saved by another request", 'ZZTXN1' === $txn, "child $tcid transaction '$txn'" );

	// A percent coupon: each order keeps its own share of the discount, counted once.
	if ( empty( $ids['coupon_pct'] ) ) {
		$c = new WC_Coupon();
		$c->set_code( 'zzwcbs10pct' );
		$c->set_discount_type( 'percent' );
		$c->set_amount( 10 );
		$ids['coupon_pct'] = $c->save();
		wcbs_t_save_ids( $ids );
	}
	list( $o, $c, $paid ) = $money_case( 'percent', function ( $o ) { $o->apply_coupon( 'zzwcbs10pct' ); } );
	wcbs_t_result( "$label: percent coupon: discount split, counted once", $c && abs( (float) $o->get_total() + (float) $c->get_total() - $paid ) < 0.001 && ! $c->get_coupon_codes() && abs( (float) $o->get_discount_total() + (float) $c->get_discount_total() - 3.0 ) < 0.001, $c ? $o->get_total() . ' + ' . $c->get_total() . " vs $paid; discounts " . $o->get_discount_total() . ' + ' . $c->get_discount_total() : 'no backorder' );

	// Free shipping on the original: no "Shipping was charged" note on the backorder.
	list( $o, $c, $paid ) = $money_case(
		'free shipping',
		function ( $o ) {
			foreach ( $o->get_items( 'shipping' ) as $s ) {
				$s->set_total( 0 );
			}
		}
	);
	$free_notes = $c ? implode( ' | ', wp_list_pluck( wc_get_order_notes( array( 'order_id' => $c->get_id() ) ), 'content' ) ) : '';
	wcbs_t_result( "$label: free shipping: no note about where shipping was charged", $c && false === strpos( $free_notes, 'Shipping was charged' ) && 1 === count( $c->get_items( 'shipping' ) ), $c ? $free_notes : 'no backorder' );

	// Taxed shipping: the backorder's shipping tax is zero too.
	add_filter( 'pre_option_woocommerce_calc_taxes', function () { return 'yes'; } );
	$rate_id = WC_Tax::_insert_tax_rate( array( 'tax_rate_country' => '', 'tax_rate' => '10.0000', 'tax_rate_name' => 'ZZ WCBS tax', 'tax_rate_priority' => 1, 'tax_rate_shipping' => 1, 'tax_rate_class' => '' ) );
	try {
		list( $o, $c, $paid ) = $money_case( 'taxed', function ( $o ) {} );
		wcbs_t_result( "$label: taxed shipping: backorder shipping and its tax are zero", $c && 0.0 === (float) $c->get_shipping_total() && 0.0 === (float) $c->get_shipping_tax() && 0.7 === round( (float) $o->get_shipping_tax(), 2 ), $c ? $c->get_shipping_total() . ' tax ' . $c->get_shipping_tax() . ', original tax ' . $o->get_shipping_tax() : 'no backorder' );
		wcbs_t_result( "$label: taxed: the two orders add up to what the customer paid", $c && abs( (float) $o->get_total() + (float) $c->get_total() - $paid ) < 0.001, $c ? $o->get_total() . ' + ' . $c->get_total() . " vs $paid" : 'no backorder' );
	} finally {
		WC_Tax::_delete_tax_rate( $rate_id );
	}

	// wcbs_copy_charges_to_backorder restores the old full copy.
	$full = function () { return true; };
	add_filter( 'wcbs_copy_charges_to_backorder', $full );
	list( $o, $c, $paid ) = $money_case( 'full copy', function ( $o ) { $o->apply_coupon( 'zzwcbs5' ); } );
	remove_filter( 'wcbs_copy_charges_to_backorder', $full );
	wcbs_t_result( "$label: filter restores the full copy of shipping and coupon", $c && 7.0 === (float) $c->get_shipping_total() && array( 'zzwcbs5' ) === $c->get_coupon_codes(), $c ? $c->get_shipping_total() . ' ' . wp_json_encode( $c->get_coupon_codes() ) : 'no backorder' );
} catch ( Throwable $e ) {
	wcbs_t_result( "$label: split ran without an exception", false, get_class( $e ) . ': ' . $e->getMessage() );
} finally {
	foreach ( $GLOBALS['wcbs_t_made'] ?? array() as $made_id ) {
		$made_order = $made_id ? wc_get_order( $made_id ) : null;
		if ( $made_order ) {
			$made_order->delete( true );
		}
	}
	// cleanup.php runs on whatever storage the site is left on and finds orders through the
	// HPOS table. Orders made here exist only in legacy storage when sync is off, so delete
	// them now, while legacy storage is still active, whether the split passed or threw.
	if ( 'legacy' === $mode && $oid ) {
		$parent = wc_get_order( $oid );
		if ( ! $child_id && $parent ) {
			$child_id = (int) $parent->get_meta( '_wcbs_backorder_id' );
		}
		$child = $child_id ? wc_get_order( $child_id ) : null;
		foreach ( array( $child, $parent ) as $test_order ) {
			if ( $test_order ) {
				$test_order->delete( true );
			}
		}
	}
}

// Last line: run.sh fails the run when this is missing, so a PHP fatal error or an early exit
// above can never drop the remaining cases without a FAIL.
wcbs_t_result( "checks.php ($mode): ran to the end", true );
