<?php
/**
 * Shared helpers for the functional suite. Loaded by setup, checks and cleanup
 * through WP-CLI's eval-file, so WordPress and WooCommerce are already running.
 */

function wcbs_t_state( $file ) {
	return rtrim( getenv( 'WCBS_STATE' ), '/' ) . '/' . $file;
}

function wcbs_t_ids() {
	$path = wcbs_t_state( 'ids.json' );
	return file_exists( $path ) ? json_decode( file_get_contents( $path ), true ) : array();
}

function wcbs_t_save_ids( $ids ) {
	file_put_contents( wcbs_t_state( 'ids.json' ), wp_json_encode( $ids ) );
}

/** Record one case. $ok decides PASS or FAIL; $detail explains a failure. */
function wcbs_t_result( $name, $ok, $detail = '' ) {
	$line = ( $ok ? 'PASS' : 'FAIL' ) . '|' . $name . ( $ok || '' === $detail ? '' : ' -- ' . $detail );
	file_put_contents( wcbs_t_state( 'results' ), $line . "\n", FILE_APPEND );
	echo $line, "\n";
}

/** "Name x2" map of an order's items, for comparing with an expectation. */
function wcbs_t_items( $order ) {
	$out = array();
	foreach ( $order->get_items() as $item ) {
		$out[ $item->get_name() ] = (int) $item->get_quantity();
	}
	ksort( $out );
	return $out;
}

function wcbs_t_simple( $name, $stock ) {
	$p = new WC_Product_Simple();
	$p->set_name( $name );
	$p->set_regular_price( '10' );
	$p->set_manage_stock( true );
	$p->set_stock_quantity( $stock );
	$p->set_backorders( 'notify' );
	$p->set_status( 'publish' );
	return $p->save();
}

/**
 * Whether an order has a note containing a given string.
 *
 * @param int    $order_id Order id.
 * @param string $needle   Text to look for.
 * @return bool
 */
function wcbs_t_has_note( $order_id, $needle ) {
	$args = array(
		'order_id' => $order_id,
		'limit'    => 50,
	);

	foreach ( wc_get_order_notes( $args ) as $note ) {
		if ( false !== strpos( wp_strip_all_tags( $note->content ), $needle ) ) {
			return true;
		}
	}

	return false;
}
