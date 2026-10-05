<?php
// order.php <order_id> -- the order and its backorder child as JSON, for checkout.js.
require __DIR__ . '/lib.php';
$o = wc_get_order( (int) $args[0] );
if ( ! $o ) {
	echo wp_json_encode( null );
	return;
}
$child_id = (int) $o->get_meta( '_wcbs_backorder_id' );
$child    = $child_id ? wc_get_order( $child_id ) : null;
echo wp_json_encode( array(
	'status' => $o->get_status(),
	'edit'   => $o->get_edit_order_url(),
	'items'  => (object) wcbs_t_items( $o ),
	'child'  => $child ? array( 'id' => $child_id, 'status' => $child->get_status(), 'items' => (object) wcbs_t_items( $child ), 'parent_meta' => (int) $child->get_meta( '_wcbs_parent_order_id' ), 'edit' => $child->get_edit_order_url() ) : null,
) );
