<?php
// usermeta.php <user key> get|clear <meta key> -- read or remove one test user's meta.
require __DIR__ . '/lib.php';
$ids = wcbs_t_ids();
$uid = (int) $ids[ $args[0] ];
if ( 'clear' === $args[1] ) {
	delete_user_meta( $uid, $args[2] );
	echo 'cleared';
} else {
	echo wp_json_encode( get_user_meta( $uid, $args[2], true ) );
}
