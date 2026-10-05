<?php
/**
 * Removes everything setup.php and the cases created: test orders (found by
 * product or by the test email), products, pages, users, the mail capture.
 * Restores the split counter, which the checkouts advance.
 */
require __DIR__ . '/lib.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

global $wpdb;
$ids = wcbs_t_ids();
if ( ! $ids ) {
	echo "nothing to clean up\n";
	return;
}

$products = array_filter( array( $ids['s1'] ?? 0, $ids['s5'] ?? 0, $ids['vS'] ?? 0, $ids['vM'] ?? 0, $ids['parent'] ?? 0, $ids['own'] ?? 0, $ids['parent2'] ?? 0 ) );
$orders   = $wpdb->get_col( $wpdb->prepare(
	"SELECT id FROM {$wpdb->prefix}wc_orders WHERE type = 'shop_order' AND ( billing_email LIKE %s OR id > %d )",
	'zz-wcbs%',
	(int) $ids['max_order']
) );
$deleted = 0;
foreach ( $orders as $oid ) {
	$o = wc_get_order( $oid );
	if ( ! $o ) {
		continue;
	}
	$ours = 0 === strpos( (string) $o->get_billing_email(), 'zz-wcbs' );
	foreach ( $o->get_items() as $it ) {
		if ( in_array( $it->get_product_id(), $products, true ) || in_array( $it->get_variation_id(), $products, true ) ) {
			$ours = true;
		}
	}
	if ( $ours ) {
		$o->delete( true );
		$deleted++;
	}
}
foreach ( array_reverse( $products ) as $pid ) {
	$p = wc_get_product( $pid );
	if ( $p ) {
		$p->delete( true );
	}
}
if ( ! empty( $ids['coupon'] ) ) {
	wp_delete_post( $ids['coupon'], true );
}
if ( ! empty( $ids['blockpage'] ) ) {
	wp_delete_post( $ids['blockpage'], true );
}
foreach ( array( 'admin', 'sub' ) as $key ) {
	if ( ! empty( $ids[ $key ] ) ) {
		wp_delete_user( $ids[ $key ] );
	}
}
if ( ! empty( $ids['mu'] ) && file_exists( $ids['mu'] ) ) {
	unlink( $ids['mu'] );
}
if ( '__unset__' === ( $ids['split_count_before'] ?? '__unset__' ) ) {
	delete_option( 'wcbs_split_count' );
} else {
	update_option( 'wcbs_split_count', $ids['split_count_before'] );
}
$left = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_title LIKE 'ZZ WCBS%'" );
echo "cleanup: deleted $deleted orders; leftover test posts: $left\n";
