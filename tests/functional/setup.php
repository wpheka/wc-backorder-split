<?php
/**
 * Creates the test data: products for every stock situation, a block checkout
 * page, an administrator and a subscriber for the admin screens, and a
 * must-use plugin that captures outgoing mail instead of sending it.
 */
require __DIR__ . '/lib.php';

global $wpdb;
$ids = array(
	'started_at' => time(),
	'max_order'  => (int) $wpdb->get_var( "SELECT MAX(id) FROM {$wpdb->prefix}wc_orders" ),
	'split_count_before' => get_option( 'wcbs_split_count', '__unset__' ),
);

$ids['s1'] = wcbs_t_simple( 'ZZ WCBS Stock1', 1 );
$ids['s5'] = wcbs_t_simple( 'ZZ WCBS Stock5', 5 );

$attr = new WC_Product_Attribute();
$attr->set_name( 'Size' );
$attr->set_options( array( 'S', 'M' ) );
$attr->set_visible( true );
$attr->set_variation( true );

// Stock managed on the parent and shared by both variations.
$shared = new WC_Product_Variable();
$shared->set_name( 'ZZ WCBS Shared' );
$shared->set_attributes( array( $attr ) );
$shared->set_status( 'publish' );
$shared->set_manage_stock( true );
$shared->set_stock_quantity( 2 );
$shared->set_backorders( 'notify' );
$ids['parent'] = $shared->save();
foreach ( array( 'S' => 'vS', 'M' => 'vM' ) as $size => $key ) {
	$v = new WC_Product_Variation();
	$v->set_parent_id( $ids['parent'] );
	$v->set_attributes( array( 'size' => $size ) );
	$v->set_regular_price( '10' );
	$v->set_manage_stock( false );
	$v->set_status( 'publish' );
	$ids[ $key ] = $v->save();
}
WC_Product_Variable::sync( $ids['parent'] );

// A variation managing its own stock.
$own = new WC_Product_Variable();
$own->set_name( 'ZZ WCBS Own' );
$own->set_attributes( array( $attr ) );
$own->set_status( 'publish' );
$ids['parent2'] = $own->save();
$v = new WC_Product_Variation();
$v->set_parent_id( $ids['parent2'] );
$v->set_attributes( array( 'size' => 'S' ) );
$v->set_regular_price( '10' );
$v->set_manage_stock( true );
$v->set_stock_quantity( 1 );
$v->set_backorders( 'notify' );
$v->set_status( 'publish' );
$ids['own'] = $v->save();
WC_Product_Variable::sync( $ids['parent2'] );

$ids['blockpage'] = wp_insert_post( array(
	'post_title'   => 'ZZ WCBS Block Checkout',
	'post_name'    => 'zz-wcbs-block-checkout',
	'post_status'  => 'publish',
	'post_type'    => 'page',
	'post_content' => '<!-- wp:woocommerce/checkout --><div class="wp-block-woocommerce-checkout wc-block-checkout is-loading"></div><!-- /wp:woocommerce/checkout -->',
) );
$ids['cart_page']     = wc_get_page_id( 'cart' );
$ids['checkout_page'] = wc_get_page_id( 'checkout' );

foreach ( array( 'admin' => 'administrator', 'sub' => 'subscriber' ) as $key => $role ) {
	$uid          = wp_insert_user( array(
		'user_login' => 'zz_wcbs_' . $key,
		'user_pass'  => wp_generate_password( 24 ),
		'user_email' => 'zz-wcbs-' . $key . '@example.invalid',
		'role'       => $role,
	) );
	$exp          = time() + 2 * HOUR_IN_SECONDS;
	$ids[ $key ]  = $uid;
	$ids[ $key . '_cookies' ] = array(
		array( 'name' => AUTH_COOKIE, 'value' => wp_generate_auth_cookie( $uid, $exp, 'auth' ), 'domain' => 'localhost', 'path' => wp_parse_url( admin_url(), PHP_URL_PATH ) ),
		array( 'name' => LOGGED_IN_COOKIE, 'value' => wp_generate_auth_cookie( $uid, $exp, 'logged_in' ), 'domain' => 'localhost', 'path' => wp_parse_url( home_url( '/' ), PHP_URL_PATH ) ),
	);
}
// A $7 flat rate for the test postcode only, so the real checkouts carry shipping
// without changing what any other order on this shared site is offered.
$zone = new WC_Shipping_Zone();
$zone->set_zone_name( 'ZZ WCBS test postcode' );
$zone->set_zone_order( -100 );
$zone->add_location( 'R3C 0A1', 'postcode' );
$zone->save();
$instance = $zone->add_shipping_method( 'flat_rate' );
update_option( 'woocommerce_flat_rate_' . $instance . '_settings', array( 'title' => 'ZZ Flat rate', 'tax_status' => 'none', 'cost' => '7' ) );
$ids['zone'] = $zone->get_id();
WC_Cache_Helper::get_transient_version( 'shipping', true );

$ids['home'] = home_url( '/' );
$ids['admin_url'] = admin_url();

// Capture mail instead of sending it, for the duration of the suite only.
$mu = WP_CONTENT_DIR . '/mu-plugins/zz-wcbs-functional-mail.php';
file_put_contents( $mu, '<?php
// Written by wc-backorder-split/tests/functional; deleted when the suite ends.
add_filter( "pre_wp_mail", function ( $null, $atts ) {
	$line = wp_json_encode( array( "to" => $atts["to"], "subject" => $atts["subject"] ) );
	file_put_contents( ' . var_export( wcbs_t_state( 'mail.log' ), true ) . ', $line . "\n", FILE_APPEND );
	return true;
}, 10, 2 );
' );
$ids['mu'] = $mu;

// "Another plugin" with notices hooked three ways, and one printed outside the notice hooks.
$notices_mu = WP_CONTENT_DIR . '/mu-plugins/zz-wcbs-functional-notices.php';
file_put_contents( $notices_mu, '<?php
// Written by wc-backorder-split/tests/functional; deleted when the suite ends.
function zz_wcbs_foreign_notice() { echo \'<div class="notice notice-warning zz-wcbs-foreign"><p>ZZ WCBS function notice</p></div>\'; }
class ZZ_Wcbs_Foreign { public static function notice() { echo \'<div class="notice notice-info zz-wcbs-foreign"><p>ZZ WCBS method notice</p></div>\'; } }
add_action( \'admin_notices\', \'zz_wcbs_foreign_notice\' );
add_action( \'admin_notices\', array( \'ZZ_Wcbs_Foreign\', \'notice\' ) );
add_action( \'all_admin_notices\', function () { echo \'<div class="notice notice-error zz-wcbs-foreign"><p>ZZ WCBS closure notice</p></div>\'; } );
add_action( \'in_admin_header\', function () { echo \'<div class="notice notice-warning zz-wcbs-stray"><p>ZZ WCBS stray notice</p></div>\'; }, 1 );
' );
$ids['notices_mu'] = $notices_mu;

wcbs_t_save_ids( $ids );
echo 'created products, pages, users and the mail capture', "\n";
