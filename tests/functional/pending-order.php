<?php
// pending-order.php -- a pending order (1 of 3 in stock) that reached the
// confirmation page unpaid, as JSON {id, edit}, for admin.js.
require __DIR__ . '/lib.php';
$ids = wcbs_t_ids();
wc_update_product_stock( wc_get_product( $ids['s1'] ), 1, 'set' );
$o = wc_create_order();
$o->set_billing_email( 'zz-wcbs-guest@example.invalid' );
$o->set_payment_method( 'bacs' );
$o->add_product( wc_get_product( $ids['s1'] ), 3 );
foreach ( $o->get_items() as $line ) {
	$line->add_meta_data( '_stock_quantity_at_add', 1, true );
}
$o->calculate_totals();
$o->set_status( 'pending' );
$o->save();
WC_Backorder_Split_Frontend::split_backorder_products( $o->get_id() ); // The confirmation page.
$o = wc_get_order( $o->get_id() );
echo wp_json_encode( array( 'id' => $o->get_id(), 'edit' => $o->get_edit_order_url(), 'marked' => $o->get_meta( '_wcbs_awaiting_payment' ) ) );
