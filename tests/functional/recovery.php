<?php
/**
 * recovery.php hpos|legacy
 *
 * A split that is interrupted must be finishable. Each case injects a failure
 * at one step of the split, checks what the interrupted order looks like, then
 * runs the split again and requires that every line ends on exactly one order
 * with the right totals.
 *
 * The failure is thrown from a WooCommerce or plugin hook that fires during the
 * step, so the plugin stops exactly where a fatal, a timeout or a DB error
 * would. Note that an exception thrown from `woocommerce_update_order` does not
 * escape the order save, so the injection points here are the item save and the
 * plugin's own filters, which do escape.
 *
 * The stored state is read straight from the database, because the order object
 * cached in this request can be older than what the split has already written.
 *
 * Orders made here are deleted at the end, even after a failure.
 */
require __DIR__ . '/lib.php';

$mode  = $args[0] ?? 'hpos';
$label = 'legacy' === $mode ? 'recovery (legacy storage)' : 'recovery (HPOS)';
$ids   = wcbs_t_ids();
$made  = array();

$ctrl    = wc_get_container()->get( Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class );
$on_hpos = $ctrl->custom_orders_table_usage_is_enabled();
if ( ( 'legacy' === $mode ) === $on_hpos ) {
	wcbs_t_result( "$label: running on the expected order storage", false, 'HPOS is ' . ( $on_hpos ? 'on' : 'off' ) );
	return;
}

/**
 * The split state as stored, past every cache.
 *
 * @param int $order_id Order id.
 * @return string
 */
function wcbs_t_stored_state( $order_id ) {
	global $wpdb;
	$ctrl = wc_get_container()->get( Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class );

	if ( $ctrl->custom_orders_table_usage_is_enabled() ) {
		$sql = "SELECT meta_value FROM {$wpdb->prefix}wc_orders_meta WHERE order_id = %d AND meta_key = '_wcbs_split_state'";
	} else {
		$sql = "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_wcbs_split_state'";
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
	return (string) $wpdb->get_var( $wpdb->prepare( $sql, $order_id ) );
}

/**
 * How many orders name this one as their split parent.
 *
 * @param int $order_id Source order id.
 * @return int
 */
function wcbs_t_child_count( $order_id ) {
	global $wpdb;
	$ctrl = wc_get_container()->get( Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class );

	if ( $ctrl->custom_orders_table_usage_is_enabled() ) {
		$sql = "SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders_meta WHERE meta_key = '_wcbs_parent_order_id' AND meta_value = %s";
	} else {
		$sql = "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_wcbs_parent_order_id' AND meta_value = %s";
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
	return (int) $wpdb->get_var( $wpdb->prepare( $sql, (string) $order_id ) );
}

/**
 * A paid order whose lines are backordered.
 *
 * @param array $lines array( array( product id, stock at add, quantity ), ... ).
 * @return int Order id.
 */
function wcbs_t_backorder_order( $lines, $shipping = false ) {
	$order = wc_create_order();
	$order->set_billing_email( 'zz-wcbs-recovery@example.invalid' );
	$order->set_billing_first_name( 'Zz' );
	$order->set_shipping_first_name( 'Zz' );
	$order->set_payment_method( 'cod' );

	foreach ( $lines as $line ) {
		list( $product_id, $stock, $qty ) = $line;
		wc_update_product_stock( wc_get_product( $product_id ), $stock, 'set' );
		$item_id = $order->add_product( wc_get_product( $product_id ), $qty );
		$item    = $order->get_item( $item_id );
		if ( $item ) {
			$item->add_meta_data( '_stock_quantity_at_add', $stock, true );
			$item->save();
		}
	}

	if ( $shipping ) {
		$ship = new WC_Order_Item_Shipping();
		$ship->set_method_title( 'ZZ Flat rate' );
		$ship->set_method_id( 'flat_rate' );
		$ship->set_total( 7 );
		$order->add_item( $ship );
	}

	$order->calculate_totals();
	$order->set_status( 'processing' );
	$order->save();

	return $order->get_id();
}

/**
 * Run a split that dies at one step.
 *
 * "cloning"   stops on the first line written to the backorder order. Every
 *             line is written in one piece, so a two line order stops with
 *             exactly one line moved.
 * "reducing"  stops on the first source line the split changes, which leaves
 *             any later line on both orders.
 * "finishing" stops after the source is reduced, before the backorder order is
 *             given its status and totals.
 *
 * @param int    $order_id Source order.
 * @param string $step     Step to die at.
 */
function wcbs_t_split_dying_at( $order_id, $step ) {
	if ( 'cloning' === $step ) {
		$boom = function ( $item_id, $item, $item_order_id ) use ( $order_id ) {
			if ( (int) $item_order_id !== (int) $order_id ) {
				throw new Exception( 'injected failure while cloning' );
			}
		};
		add_action( 'woocommerce_new_order_item', $boom, 10, 3 );
		WC_Backorder_Split_Frontend::split_backorder_products( $order_id );
		remove_action( 'woocommerce_new_order_item', $boom, 10 );
		return;
	}

	if ( 'reducing' === $step ) {
		$boom = function ( $item_id, $item, $item_order_id ) use ( $order_id ) {
			if ( (int) $item_order_id === (int) $order_id ) {
				throw new Exception( 'injected failure while reducing the source' );
			}
		};
		add_action( 'woocommerce_update_order_item', $boom, 10, 3 );
		WC_Backorder_Split_Frontend::split_backorder_products( $order_id );
		remove_action( 'woocommerce_update_order_item', $boom, 10 );
		return;
	}

	$boom = function () {
		throw new Exception( 'injected failure before the backorder order was finished' );
	};
	add_filter( 'wcbs_copy_charges_to_backorder', $boom, 10, 1 );
	WC_Backorder_Split_Frontend::split_backorder_products( $order_id );
	remove_filter( 'wcbs_copy_charges_to_backorder', $boom, 10 );
}

try {
	// ------------------------------------------------------------------ 1
	// Interrupted while the backorder order's lines are written. The source
	// must still be whole: nothing may leave it before the child holds it.
	$oid    = wcbs_t_backorder_order( array( array( $ids['s1'], 1, 3 ) ) );
	$made[] = $oid;

	wcbs_t_split_dying_at( $oid, 'cloning' );

	$state  = wcbs_t_stored_state( $oid );
	$src    = wc_get_order( $oid );
	$made[] = (int) $src->get_meta( '_wcbs_backorder_id' );

	wcbs_t_result( "$label: a split interrupted while cloning records its state", 'child_created' === $state, "state is '$state'" );
	wcbs_t_result( "$label: the source keeps every line until the backorder order holds them", array( 'ZZ WCBS Stock1' => 3 ) === wcbs_t_items( $src ), wp_json_encode( wcbs_t_items( $src ) ) );
	wcbs_t_result( "$label: an interrupted split schedules a retry", ! function_exists( 'as_has_scheduled_action' ) || as_has_scheduled_action( 'wcbs_resume_split', array( (int) $oid ), 'wc-backorder-split' ), 'no wcbs_resume_split action is queued' );

	WC_Backorder_Split_Frontend::split_backorder_products( $oid );

	$src    = wc_get_order( $oid );
	$cid    = (int) $src->get_meta( '_wcbs_backorder_id' );
	$child  = $cid ? wc_get_order( $cid ) : null;
	$made[] = $cid;

	wcbs_t_result( "$label: the retry finishes an interrupted clone", array( 'ZZ WCBS Stock1' => 1 ) === wcbs_t_items( $src ) && $child && array( 'ZZ WCBS Stock1' => 2 ) === wcbs_t_items( $child ), wp_json_encode( array( 'source' => wcbs_t_items( $src ), 'child' => $child ? wcbs_t_items( $child ) : null ) ) );
	wcbs_t_result( "$label: the retry leaves exactly one backorder order", 1 === wcbs_t_child_count( $oid ), wcbs_t_child_count( $oid ) . ' orders name it as their parent' );
	wcbs_t_result( "$label: totals are right after an interrupted clone", '10.00' === $src->get_total() && $child && '20.00' === $child->get_total(), 'source ' . $src->get_total() . ', child ' . ( $child ? $child->get_total() : '-' ) );
	wcbs_t_result( "$label: the backorder order ends in the Backordered status", $child && 'backordered' === $child->get_status(), $child ? $child->get_status() : 'no child' );
	wcbs_t_result( "$label: a finished split clears its plan and state", '' === wcbs_t_stored_state( $oid ) && ! $src->get_meta( '_wcbs_split_plan' ), 'state ' . wcbs_t_stored_state( $oid ) );
	wcbs_t_result( "$label: a finished split cancels its retry", ! function_exists( 'as_has_scheduled_action' ) || ! as_has_scheduled_action( 'wcbs_resume_split', array( (int) $oid ), 'wc-backorder-split' ), 'a wcbs_resume_split action is still queued' );
	wcbs_t_result( "$label: the merchant is told the split was interrupted", wcbs_t_has_note( $oid, 'was interrupted and has been completed' ), 'no recovery note on the source order' );

	// ------------------------------------------------------------------ 2
	// Two lines: one reduced, one moved whole. The failure lands on the first
	// line the source changes, so the second is left on the source while the
	// backorder order already holds it. A duplicate, which can be found and
	// tidied, is what this design trades the old silent loss for.
	$oid2   = wcbs_t_backorder_order(
		array(
			array( $ids['s1'], 1, 3 ),
			array( $ids['s5'], 0, 2 ),
		)
	);
	$made[] = $oid2;

	wcbs_t_split_dying_at( $oid2, 'reducing' );

	$src2   = wc_get_order( $oid2 );
	$cid2   = (int) $src2->get_meta( '_wcbs_backorder_id' );
	$child2 = $cid2 ? wc_get_order( $cid2 ) : null;
	$made[] = $cid2;

	$src_items   = wcbs_t_items( $src2 );
	$child_items = $child2 ? wcbs_t_items( $child2 ) : array();
	$duplicated  = isset( $src_items['ZZ WCBS Stock5'], $child_items['ZZ WCBS Stock5'] );

	wcbs_t_result( "$label: an interrupted split leaves a line on both orders, never on none", $duplicated, wp_json_encode( array( 'source' => $src_items, 'child' => $child_items ) ) );

	WC_Backorder_Split_Frontend::split_backorder_products( $oid2 );

	$src2   = wc_get_order( $oid2 );
	$child2 = $cid2 ? wc_get_order( $cid2 ) : null;

	wcbs_t_result( "$label: the retry takes the duplicated line off the source", array( 'ZZ WCBS Stock1' => 1 ) === wcbs_t_items( $src2 ), wp_json_encode( wcbs_t_items( $src2 ) ) );
	wcbs_t_result( "$label: the retry leaves both backordered lines on the backorder order", $child2 && array( 'ZZ WCBS Stock1' => 2, 'ZZ WCBS Stock5' => 2 ) === wcbs_t_items( $child2 ), $child2 ? wp_json_encode( wcbs_t_items( $child2 ) ) : 'no child' );
	wcbs_t_result( "$label: totals are right after a duplicated line is tidied", '10.00' === $src2->get_total() && $child2 && '40.00' === $child2->get_total(), 'source ' . $src2->get_total() . ', child ' . ( $child2 ? $child2->get_total() : '-' ) );
	wcbs_t_result( "$label: no second backorder order is made for a duplicate", 1 === wcbs_t_child_count( $oid2 ), wcbs_t_child_count( $oid2 ) . ' orders name it as their parent' );

	// ------------------------------------------------------------------ 2b
	// Interrupted part way through cloning, so the backorder order already
	// holds one line when the retry starts. The retry must move only what is
	// still missing: a line moved twice would bill the customer twice.
	$oid2b   = wcbs_t_backorder_order(
		array(
			array( $ids['s1'], 1, 3 ),
			array( $ids['s5'], 0, 2 ),
		)
	);
	$made[] = $oid2b;

	wcbs_t_split_dying_at( $oid2b, 'cloning' );

	$src2b   = wc_get_order( $oid2b );
	$cid2b   = (int) $src2b->get_meta( '_wcbs_backorder_id' );
	$child2b = $cid2b ? wc_get_order( $cid2b ) : null;
	$made[]  = $cid2b;

	wcbs_t_result( "$label: a clone interrupted part way leaves the moved line on the backorder order", $child2b && 1 === count( $child2b->get_items( 'line_item' ) ), $child2b ? count( $child2b->get_items( 'line_item' ) ) . ' lines on the backorder order' : 'no child' );

	WC_Backorder_Split_Frontend::split_backorder_products( $oid2b );

	$src2b   = wc_get_order( $oid2b );
	$child2b = $cid2b ? wc_get_order( $cid2b ) : null;

	wcbs_t_result( "$label: the retry moves only the lines still missing", $child2b && array( 'ZZ WCBS Stock1' => 2, 'ZZ WCBS Stock5' => 2 ) === wcbs_t_items( $child2b ) && 2 === count( $child2b->get_items( 'line_item' ) ), $child2b ? count( $child2b->get_items( 'line_item' ) ) . ' lines ' . wp_json_encode( wcbs_t_items( $child2b ) ) : 'no child' );
	wcbs_t_result( "$label: totals are right after a clone is resumed part way", '10.00' === $src2b->get_total() && $child2b && '40.00' === $child2b->get_total(), 'source ' . $src2b->get_total() . ', child ' . ( $child2b ? $child2b->get_total() : '-' ) );

	// ------------------------------------------------------------------ 3
	// Interrupted after the source is reduced, before the backorder order is
	// finished. The lines are all in the right place; the child is not yet
	// presentable.
	$oid3   = wcbs_t_backorder_order( array( array( $ids['s1'], 1, 3 ) ), true );
	$made[] = $oid3;

	wcbs_t_split_dying_at( $oid3, 'finishing' );

	$src3   = wc_get_order( $oid3 );
	$cid3   = (int) $src3->get_meta( '_wcbs_backorder_id' );
	$child3 = $cid3 ? wc_get_order( $cid3 ) : null;
	$made[] = $cid3;

	wcbs_t_result( "$label: a split interrupted before the backorder order is finished records its state", 'source_updated' === wcbs_t_stored_state( $oid3 ), "state is '" . wcbs_t_stored_state( $oid3 ) . "'" );
	wcbs_t_result( "$label: an unfinished backorder order is not left in the Backordered status", $child3 && 'backordered' !== $child3->get_status(), $child3 ? $child3->get_status() : 'no child' );

	WC_Backorder_Split_Frontend::split_backorder_products( $oid3 );

	$src3   = wc_get_order( $oid3 );
	$child3 = $cid3 ? wc_get_order( $cid3 ) : null;

	wcbs_t_result( "$label: the retry finishes a backorder order left unfinished", $child3 && 'backordered' === $child3->get_status() && array( 'ZZ WCBS Stock1' => 2 ) === wcbs_t_items( $child3 ), $child3 ? $child3->get_status() . ' ' . wp_json_encode( wcbs_t_items( $child3 ) ) : 'no child' );
	$ship3 = $child3 ? array_values( $child3->get_items( 'shipping' ) ) : array();
	wcbs_t_result( "$label: the retry gives the backorder order the shipping method once, at no cost", 1 === count( $ship3 ) && 'ZZ Flat rate' === $ship3[0]->get_method_title() && 0.0 === (float) $ship3[0]->get_total(), $child3 ? count( $ship3 ) . ' shipping lines' . ( $ship3 ? ', ' . $ship3[0]->get_method_title() . ' at ' . $ship3[0]->get_total() : '' ) : 'no child' );
	wcbs_t_result( "$label: totals are right after an unfinished backorder order is completed", '17.00' === $src3->get_total() && $child3 && '20.00' === $child3->get_total(), 'source ' . $src3->get_total() . ', child ' . ( $child3 ? $child3->get_total() : '-' ) );

	// ------------------------------------------------------------------ 4
	// The lines moved and the order holding them is gone. This cannot be
	// repaired, so it must be said plainly rather than passed over.
	$oid4   = wcbs_t_backorder_order( array( array( $ids['s1'], 1, 3 ) ) );
	$made[] = $oid4;

	wcbs_t_split_dying_at( $oid4, 'finishing' );

	$src4 = wc_get_order( $oid4 );
	$cid4 = (int) $src4->get_meta( '_wcbs_backorder_id' );
	if ( $cid4 && wc_get_order( $cid4 ) ) {
		wc_get_order( $cid4 )->delete( true );
	}

	WC_Backorder_Split_Frontend::split_backorder_products( $oid4 );

	wcbs_t_result( "$label: a missing backorder order is reported on the source order", wcbs_t_has_note( $oid4, 'is missing' ), 'no note naming the missing order' );
	wcbs_t_result( "$label: an unrepairable split stops retrying", '' === wcbs_t_stored_state( $oid4 ), 'state is still ' . wcbs_t_stored_state( $oid4 ) );

	// ------------------------------------------------------------------ 4b
	// The order was edited between the interrupted attempt and the retry: a
	// line the plan still has to move is gone from the source. Nothing is
	// lost, so the note must say the order changed, not that the backorder
	// order is missing and items are on no order, which would send a merchant
	// looking for a loss that did not happen.
	$oid4b  = wcbs_t_backorder_order(
		array(
			array( $ids['s1'], 1, 3 ),
			array( $ids['s5'], 0, 2 ),
		)
	);
	$made[] = $oid4b;

	wcbs_t_split_dying_at( $oid4b, 'cloning' );

	$src4b  = wc_get_order( $oid4b );
	$cid4b  = (int) $src4b->get_meta( '_wcbs_backorder_id' );
	$made[] = $cid4b;
	foreach ( $src4b->get_items( 'line_item' ) as $item_id => $item ) {
		if ( 'ZZ WCBS Stock5' === $item->get_name() ) {
			$src4b->remove_item( $item_id );
		}
	}
	$src4b->save();

	WC_Backorder_Split_Frontend::split_backorder_products( $oid4b );

	wcbs_t_result( "$label: an order edited before the retry is reported as changed, not as a loss", wcbs_t_has_note( $oid4b, 'was changed before it finished' ) && ! wcbs_t_has_note( $oid4b, 'is missing' ), 'the note does not say the order changed' );
	wcbs_t_result( "$label: the changed-order note names the backorder order to compare", $cid4b && wcbs_t_has_note( $oid4b, '#' . $cid4b ), 'no order number in the note' );
	wcbs_t_result( "$label: a split that no longer matches its order stops retrying", '' === wcbs_t_stored_state( $oid4b ), 'state is still ' . wcbs_t_stored_state( $oid4b ) );

	// ------------------------------------------------------------------ 4c
	// A line the retry would reduce was edited in between (3 to 4 units). The
	// retry must not write its planned quantity over the merchant's edit.
	$oid4c  = wcbs_t_backorder_order(
		array(
			array( $ids['s1'], 1, 3 ),
			array( $ids['s5'], 0, 2 ),
		)
	);
	$made[] = $oid4c;

	wcbs_t_split_dying_at( $oid4c, 'cloning' );

	$src4c  = wc_get_order( $oid4c );
	$made[] = (int) $src4c->get_meta( '_wcbs_backorder_id' );
	foreach ( $src4c->get_items( 'line_item' ) as $item ) {
		if ( 'ZZ WCBS Stock1' === $item->get_name() ) {
			$item->set_quantity( 4 );
			$item->save();
		}
	}

	WC_Backorder_Split_Frontend::split_backorder_products( $oid4c );

	$src4c = wc_get_order( $oid4c );
	wcbs_t_result( "$label: a quantity edited before the retry is kept, not overwritten", 4 === (int) ( wcbs_t_items( $src4c )['ZZ WCBS Stock1'] ?? 0 ), wp_json_encode( wcbs_t_items( $src4c ) ) );
	wcbs_t_result( "$label: a quantity edited before the retry is reported as a changed order", wcbs_t_has_note( $oid4c, 'was changed before it finished' ), 'no changed-order note' );

	// ------------------------------------------------------------------ 4d
	// WooCommerce swallows an error raised while the backorder order is saved
	// with its status. The split must not count that as done.
	$oid4d  = wcbs_t_backorder_order( array( array( $ids['s1'], 1, 3 ) ) );
	$made[] = $oid4d;

	$swallowed = function ( $o ) use ( $oid4d ) {
		if ( (int) $o->get_meta( '_wcbs_parent_order_id' ) === (int) $oid4d && 'backordered' === $o->get_status() ) {
			throw new Exception( 'injected failure saving the backorder order' );
		}
	};
	add_action( 'woocommerce_before_order_object_save', $swallowed, 10, 1 );
	WC_Backorder_Split_Frontend::split_backorder_products( $oid4d );
	remove_action( 'woocommerce_before_order_object_save', $swallowed, 10 );

	$made[] = (int) wc_get_order( $oid4d )->get_meta( '_wcbs_backorder_id' );
	wcbs_t_result( "$label: a backorder order that was not saved keeps the split unfinished", '' !== wcbs_t_stored_state( $oid4d ), 'state cleared although the backorder order was never saved with its status' );

	WC_Backorder_Split_Frontend::split_backorder_products( $oid4d );
	$cid4d = (int) wc_get_order( $oid4d )->get_meta( '_wcbs_backorder_id' );
	wcbs_t_result( "$label: the retry then finishes it", '' === wcbs_t_stored_state( $oid4d ) && $cid4d && 'backordered' === wc_get_order( $cid4d )->get_status(), 'state ' . wcbs_t_stored_state( $oid4d ) );

	// ------------------------------------------------------------------ 4e
	// The scheduled retry fails again (a passing error). It must queue the
	// next attempt itself, count it, and stop after the limit with a note.
	if ( function_exists( 'as_get_scheduled_actions' ) ) {
		$pending = function ( $oid ) {
			return count( as_get_scheduled_actions( array( 'hook' => 'wcbs_resume_split', 'args' => array( (int) $oid ), 'group' => 'wc-backorder-split', 'status' => ActionScheduler_Store::STATUS_PENDING, 'per_page' => 20 ), 'ids' ) );
		};
		$oid4e  = wcbs_t_backorder_order( array( array( $ids['s1'], 1, 3 ) ) );
		$made[] = $oid4e;

		wcbs_t_split_dying_at( $oid4e, 'finishing' );
		$made[] = (int) wc_get_order( $oid4e )->get_meta( '_wcbs_backorder_id' );
		$before = $pending( $oid4e );

		$again = function () {
			throw new Exception( 'injected passing failure in the scheduled retry' );
		};
		add_filter( 'wcbs_copy_charges_to_backorder', $again, 10, 1 );
		WC_Backorder_Split_Frontend::resume_split( $oid4e );
		remove_filter( 'wcbs_copy_charges_to_backorder', $again, 10 );

		wcbs_t_result( "$label: a scheduled retry that fails queues the next attempt", $pending( $oid4e ) === $before + 1 && 1 === (int) wc_get_order( $oid4e )->get_meta( '_wcbs_split_attempts' ), 'pending ' . $before . ' then ' . $pending( $oid4e ) . ', attempts ' . wc_get_order( $oid4e )->get_meta( '_wcbs_split_attempts' ) );

		$o4e = wc_get_order( $oid4e );
		$o4e->update_meta_data( '_wcbs_split_attempts', 4 );
		$o4e->save();
		$before = $pending( $oid4e );
		add_filter( 'wcbs_copy_charges_to_backorder', $again, 10, 1 );
		WC_Backorder_Split_Frontend::resume_split( $oid4e );
		remove_filter( 'wcbs_copy_charges_to_backorder', $again, 10 );

		wcbs_t_result( "$label: after the last attempt it stops retrying and says so", $pending( $oid4e ) === $before && wcbs_t_has_note( $oid4e, 'could not be finished after 5 attempts' ), 'pending ' . $before . ' then ' . $pending( $oid4e ) );

		WC_Backorder_Split_Frontend::resume_split( $oid4e );
		wcbs_t_result( "$label: a retry that succeeds clears the attempt count and the queue", '' === wcbs_t_stored_state( $oid4e ) && '' === (string) wc_get_order( $oid4e )->get_meta( '_wcbs_split_attempts' ) && 0 === $pending( $oid4e ), 'state ' . wcbs_t_stored_state( $oid4e ) . ', pending ' . $pending( $oid4e ) );
	} else {
		wcbs_t_result( "$label: Action Scheduler is available for the retry cases", false, 'as_get_scheduled_actions missing' );
	}

	// ------------------------------------------------------------------ 5
	// An ordinary split is unchanged: one pass, the original note, no leftovers.
	$oid5   = wcbs_t_backorder_order( array( array( $ids['s1'], 1, 3 ) ) );
	$made[] = $oid5;

	WC_Backorder_Split_Frontend::split_backorder_products( $oid5 );

	$src5   = wc_get_order( $oid5 );
	$cid5   = (int) $src5->get_meta( '_wcbs_backorder_id' );
	$child5 = $cid5 ? wc_get_order( $cid5 ) : null;
	$made[] = $cid5;

	wcbs_t_result( "$label: an uninterrupted split still splits", array( 'ZZ WCBS Stock1' => 1 ) === wcbs_t_items( $src5 ) && $child5 && array( 'ZZ WCBS Stock1' => 2 ) === wcbs_t_items( $child5 ), wp_json_encode( array( 'source' => wcbs_t_items( $src5 ), 'child' => $child5 ? wcbs_t_items( $child5 ) : null ) ) );
	wcbs_t_result( "$label: an uninterrupted split keeps its original note", wcbs_t_has_note( $oid5, 'Backorder items split into order' ) && ! wcbs_t_has_note( $oid5, 'was interrupted' ), 'the note does not read as an ordinary split' );
	wcbs_t_result( "$label: an uninterrupted split leaves no plan or state", '' === wcbs_t_stored_state( $oid5 ) && ! $src5->get_meta( '_wcbs_split_plan' ), 'state ' . wcbs_t_stored_state( $oid5 ) );
} finally {
	foreach ( array_unique( array_filter( $made ) ) as $id ) {
		$o = wc_get_order( $id );
		if ( $o ) {
			$o->delete( true );
		}
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( 'wcbs_resume_split', array( (int) $id ), 'wc-backorder-split' );
		}
	}
}

// Last line: run.sh fails the run when this is missing, so a PHP fatal error or an early exit
// above can never drop the remaining cases without a FAIL.
wcbs_t_result( "recovery.php ($mode): ran to the end", true );
