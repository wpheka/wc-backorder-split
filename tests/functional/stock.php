<?php
// stock.php <key> <qty> -- set a test product's stock (stock.php in run order).
require __DIR__ . '/lib.php';
$ids = wcbs_t_ids();
wc_update_product_stock( wc_get_product( $ids[ $args[0] ] ), (int) $args[1], 'set' );
echo wc_get_product( $ids[ $args[0] ] )->get_stock_quantity();
