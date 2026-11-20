<?php
/**
 * WC Backorder Split Front-End
 *
 * @version 2.4.0
 * @package WCBS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WC_Backorder_Split_Frontend Class
 */
class WC_Backorder_Split_Frontend {

	/**
	 * Stock still unallocated while one order's lines are recorded, keyed by
	 * the id of the product that owns the stock (the parent, for variations
	 * whose stock it manages). Reset for each new order and each new pass over
	 * the same order.
	 *
	 * @var array{order: WC_Order|null, keys: string[], remaining: int[]}
	 */
	private static $allocation = array(
		'order'     => null,
		'keys'      => array(),
		'remaining' => array(),
	);

	/**
	 * Hook actions and filters
	 *
	 * @since 1.0.0
	 */
	public static function init() {
		add_action( 'woocommerce_add_to_cart', array( __CLASS__, 'store_stock_quantity_in_cart_item' ), 10, 6 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'save_stock_quantity_to_order_item' ), 10, 4 );
		add_action( 'woocommerce_thankyou', array( __CLASS__, 'split_backorder_products' ), 10, 1 );
	}

	/**
	 * Hook the payment confirmation that splits an order marked at the
	 * confirmation page. Registered on every request, not only the front end:
	 * a payment can be confirmed from wp-admin, admin-ajax, a cron job or a
	 * REST webhook.
	 *
	 * @since 2.4.3
	 */
	public static function init_payment_hooks() {
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'maybe_defer_split_on_payment' ), 10, 3 );

		// Finishes a split whose request died part way. Registered here, with
		// the payment hooks, because the scheduled action runs in a cron
		// request rather than on the front end.
		add_action( 'wcbs_resume_split', array( __CLASS__, 'resume_split' ), 10, 1 );
	}

	/**
	 * Order ids waiting to be split at the end of this request.
	 *
	 * @var int[]
	 */
	private static $deferred = array();

	/**
	 * Split an order that reached the confirmation page unpaid, once its
	 * payment is confirmed.
	 *
	 * Redirect and asynchronous gateways can show the confirmation page while
	 * the order is still pending; the payment is confirmed later, often by a
	 * server-to-server notification. Only orders marked at the confirmation
	 * page are handled here, so every other order splits exactly as before.
	 *
	 * The split itself waits for the end of the request: the gateway may still
	 * hold and save its own copy of the order after confirming the payment,
	 * which would write the original quantities back over the split.
	 *
	 * @since 2.4.3
	 * @param int    $order_id Order id.
	 * @param string $from     Previous status.
	 * @param string $to       New status.
	 */
	public static function maybe_defer_split_on_payment( $order_id, $from, $to ) {
		// Paid statuses only (processing and completed, plus any a store adds).
		// On hold can still fail, and splitting before that would leave only
		// the in-stock part to pay on a retry.
		if ( ! in_array( $to, wc_get_is_paid_statuses(), true ) ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order || 'yes' !== $order->get_meta( '_wcbs_awaiting_payment' ) ) {
			return;
		}

		if ( empty( self::$deferred ) ) {
			add_action( 'shutdown', array( __CLASS__, 'run_deferred_splits' ) );
		}
		self::$deferred[ (int) $order_id ] = (int) $order_id;
	}

	/**
	 * Run the splits deferred by maybe_defer_split_on_payment().
	 *
	 * @since 2.4.3
	 */
	public static function run_deferred_splits() {
		$ids            = self::$deferred;
		self::$deferred = array();

		foreach ( $ids as $order_id ) {
			self::flush_order_cache( $order_id );
			self::split_backorder_products( $order_id );
		}
	}

	/**
	 * Store stock quantity in cart item data if backorder is allowed.
	 *
	 * @since 1.0.0
	 * @param string $cart_item_key
	 * @param int    $product_id
	 * @param int    $quantity
	 * @param int    $_variation_id
	 * @param array  $_variation
	 * @param array  $_cart_item_data
	 */
	public static function store_stock_quantity_in_cart_item( $cart_item_key, $product_id, $quantity, $_variation_id, $_variation, $_cart_item_data ) {
		try {
			// The variation, when there is one. $product_id is the parent, which
			// carries no stock of its own when stock is managed per variation.
			$product = wc_get_product( $_variation_id ? $_variation_id : $product_id );

			if ( ! $product ) {
				self::log_error( 'Invalid product ID ' . ( $_variation_id ? $_variation_id : $product_id ) );
				return;
			}

			$stock_quantity = $product->get_stock_quantity();

			if ( ! $product->backorders_allowed() ) {
				return;
			}

			if ( is_numeric( $stock_quantity ) && $quantity > $stock_quantity ) {
				if ( isset( WC()->cart->cart_contents[ $cart_item_key ] ) ) {
					// Never below zero: stock already oversold by earlier
					// backorders would otherwise backorder more than was bought.
					WC()->cart->cart_contents[ $cart_item_key ]['_stock_quantity_at_add'] = max( 0, (int) $stock_quantity );
				}
			}
		} catch ( Exception $e ) {
			self::log_error( 'Error in store_stock_quantity_in_cart_item: ' . $e->getMessage() );
		}
	}

	/**
	 * Save stock quantity to order item meta data.
	 *
	 * @since 1.0.0
	 * @param WC_Order_Item_Product $item
	 * @param string                $_cart_item_key
	 * @param array                 $values
	 * @param WC_Order              $_order
	 */
	public static function save_stock_quantity_to_order_item( $item, $_cart_item_key, $values, $_order ) {
		/*
		 * Read the stock again here, at checkout, for every stock-managed line
		 * that allows backorders. The add-to-cart value alone missed quantity
		 * changes made in the cart, and compared only the quantity added, not
		 * the line total. Stock is not reduced yet at this point (WooCommerce
		 * writes its own "Backordered" item meta from the same reading just
		 * before this hook), and the item's product is the variation when
		 * there is one.
		 *
		 * Recording it for in-stock lines too matters: without the meta,
		 * split_backorder_products() falls back to is_on_backorder(), which
		 * runs after stock has been reduced and so can mistake an in-stock
		 * line for a backordered one.
		 */
		$product = $item->get_product();

		if ( $product && $product->managing_stock() && $product->backorders_allowed() && is_numeric( $product->get_stock_quantity() ) ) {
			$item->add_meta_data( '_stock_quantity_at_add', self::allocate_stock( $product, $_order, $_cart_item_key, $item->get_quantity() ), true );
			return;
		}

		if ( isset( $values['_stock_quantity_at_add'] ) ) {
			$item->add_meta_data( '_stock_quantity_at_add', $values['_stock_quantity_at_add'], true );
		}
	}

	/**
	 * Check if all products in an order are on backorder.
	 *
	 * @since 1.0.0
	 * @param  int $order_id
	 * @return bool
	 */
	public static function are_all_products_in_backorder( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return false;
		}

		$items = $order->get_items( 'line_item' );
		if ( empty( $items ) ) {
			return false;
		}

		foreach ( $items as $item ) {
			/** @var WC_Order_Item_Product $item */
			$product = $item->get_product();
			if ( ! $product ) {
				continue;
			}

			$stock_quantity_at_add = $item->get_meta( '_stock_quantity_at_add' );

			if ( '' !== $stock_quantity_at_add ) {
				// Recorded at checkout: the line is wholly backordered only when
				// nothing was in stock. This used to return false for any
				// recorded value, so an order with every line at zero stock
				// went down the split path and left the original order empty.
				if ( (int) $stock_quantity_at_add > 0 ) {
					return false;
				}
			} elseif ( ! $product->is_on_backorder() ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Split backorder products into a separate order.
	 *
	 * On the thank-you page, inspects every line item to determine which
	 * quantities are backordered. In-stock quantities stay on the original
	 * order (updated in-place); backordered quantities are cloned into a new
	 * order with status "backordered".
	 *
	 * @since 1.0.0
	 * @param int $order_id
	 */
	public static function split_backorder_products( $order_id ) {
		$locked = false;
		try {
			$order = wc_get_order( $order_id );

			if ( ! $order ) {
				self::log_error( 'Invalid order ID ' . $order_id );
				return;
			}

			// A split that did not finish leaves a state behind. Such an order is
			// resumed rather than skipped, even though _wcbs_backorder_id is set.
			$resuming = '' !== self::split_state( $order );

			// Prevent duplicate splits
			if ( ! $resuming && ( $order->get_meta( '_wcbs_backorder_id' ) || $order->get_meta( '_wcbs_processed' ) ) ) {
				return;
			}

			// WooCommerce shows this page for a failed payment too. Leave the
			// order whole, so a retry pays the full amount and the split runs
			// when the paid order's confirmation page loads.
			if ( $order->has_status( array( 'failed', 'cancelled' ) ) ) {
				return;
			}

			// Not paid yet (a redirect or asynchronous gateway). Splitting now
			// would leave only the in-stock part to pay if this payment fails
			// and is retried. Mark it, and split when the payment is confirmed.
			if ( ! $resuming && $order->has_status( 'pending' ) ) {
				$order->update_meta_data( '_wcbs_awaiting_payment', 'yes' );
				$order->save();

				// The confirmation may have landed while the mark was saved, and
				// then nothing would split the order. Read the stored status,
				// not this request's cached copy of the order.
				if ( in_array( self::stored_status( $order_id ), array( 'pending', 'failed', 'cancelled' ), true ) ) {
					return;
				}
			}

			// The confirmation page and a payment notification can reach this
			// point at the same moment. Only one of them may split, and the
			// other may already have: check the database, then reload the order
			// past this request's caches.
			$locked = self::acquire_lock( $order_id );
			if ( ! $locked ) {
				return;
			}
			self::flush_order_cache( $order_id );
			$order = wc_get_order( $order_id );
			if ( ! $order || $order->has_status( array( 'pending', 'failed', 'cancelled' ) ) ) {
				return;
			}

			// Read the state again now the caches are gone: another request may
			// have started, or finished, a split since the first check.
			$resuming = '' !== self::split_state( $order );
			if ( ! $resuming && self::stored_meta_exists( $order_id, array( '_wcbs_backorder_id', '_wcbs_processed' ) ) ) {
				return;
			}

			// An order that reached the confirmation page unpaid splits only
			// while it is paid: it may have gone back on hold since.
			if ( 'yes' === $order->get_meta( '_wcbs_awaiting_payment' ) && ! $order->has_status( wc_get_is_paid_statuses() ) ) {
				return;
			}

			$plan = array();

			if ( $resuming ) {
				$plan = self::split_plan( $order );

				if ( empty( $plan['items'] ) ) {
					// A state with no usable plan. There is nothing to resume
					// from, so clear it and let the ordinary path decide again.
					self::clear_split( $order );
					$resuming = false;
				}
			}

			if ( ! $resuming ) {
				if ( ! apply_filters( 'wcbs_should_split_order', true, $order_id, $order ) ) {
					return;
				}

				// All items already on backorder — just flip the status, no split needed
				if ( self::are_all_products_in_backorder( $order_id ) ) {
					$order->set_status( 'backordered' );
					$order->add_meta_data( '_wcbs_processed', 'yes', true );
					$order->delete_meta_data( '_wcbs_awaiting_payment' );
					$order->save();
					return;
				}

				do_action( 'wcbs_before_split_order', $order_id, $order );

				$plan = self::build_split_plan( $order );

				if ( empty( $plan['items'] ) ) {
					if ( $order->get_meta( '_wcbs_awaiting_payment' ) ) {
						$order->delete_meta_data( '_wcbs_awaiting_payment' );
						$order->save();
					}
					return;
				}

				/*
				 * The plan is written before a single line moves. Everything
				 * after this point can be finished, or reported, from it: the
				 * quantities and totals it holds are the ones the order had
				 * before the split started.
				 */
				$order->update_meta_data( self::PLAN_META, $plan );
				$order->update_meta_data( self::STATE_META, self::STATE_PLANNED );
				$order->save();

				// A crash from here on cannot rely on another page load for this
				// order, so a scheduled action picks it up instead.
				self::schedule_resume( $order_id );
			}

			self::run_split( $order, $plan, $resuming );

		} catch ( Exception $e ) {
			self::log_error( 'Error in split_backorder_products for order ID ' . $order_id . ': ' . $e->getMessage() );
			do_action( 'wcbs_split_order_error', $order_id, $e->getMessage() );
		} finally {
			if ( $locked ) {
				self::release_lock( $order_id );
			}
		}
	}

	/**
	 * Carry out a planned split, from wherever the last attempt stopped.
	 *
	 * Every step is written so that running it twice gives the same result as
	 * running it once: each one sets a value taken from the plan rather than
	 * changing the current value, and each one is skipped when the recorded
	 * state shows it is already done.
	 *
	 * @since 2.4.4
	 * @param WC_Order $order    Source order.
	 * @param array    $plan     Split plan.
	 * @param bool     $resuming Whether this is picking up an interrupted split.
	 * @return void
	 */
	private static function run_split( $order, $plan, $resuming ) {
		$order_id = $order->get_id();
		$state    = self::split_state( $order );

		/*
		 * 1. The child order, created before any line moves, and linked in both
		 * directions straight away. A crash from here leaves the same line on
		 * two orders, which the next attempt finds and tidies, rather than on
		 * none, which nothing could find.
		 */
		$backorder_order = self::find_child_order( $order );

		if ( ! $backorder_order ) {
			if ( self::state_at_least( $state, self::STATE_CHILD_FILLED ) ) {
				// Lines have already moved and the order holding them is gone.
				self::report_unrecoverable( $order, $plan );
				return;
			}

			self::maybe_disable_emails( $order );

			$backorder_order = wc_create_order( array(
				'customer_id' => $order->get_customer_id(),
				'created_via' => __( 'Backorder split', 'wc-backorder-split' ),
			) );

			self::maybe_restore_emails( $order );

			if ( ! $backorder_order ) {
				self::log_error( 'Failed to create backorder order for order ID ' . $order_id );
				return;
			}

			$backorder_order->add_meta_data( '_wcbs_parent_order_id', $order_id, true );
			$backorder_order->save();

			$order->update_meta_data( '_wcbs_backorder_id', $backorder_order->get_id() );
			$order->update_meta_data( self::STATE_META, self::STATE_CHILD_CREATED );
			$order->save();
			$state = self::STATE_CHILD_CREATED;
		}

		// ---------------------------------------------------------------
		// 2. Addresses + HPOS-aware address indexes (for order search)
		// ---------------------------------------------------------------
		$backorder_order->set_address( $order->get_address( 'billing' ), 'billing' );
		$backorder_order->set_address( $order->get_address( 'shipping' ), 'shipping' );

		if ( self::is_hpos_enabled() ) {
			$backorder_order->update_meta_data( '_billing_address_index', $order->get_meta( '_billing_address_index' ) );
			$backorder_order->update_meta_data( '_shipping_address_index', $order->get_meta( '_shipping_address_index' ) );
		} else {
			update_post_meta( $backorder_order->get_id(), '_billing_address_index', get_post_meta( $order->get_id(), '_billing_address_index', true ) );
			update_post_meta( $backorder_order->get_id(), '_shipping_address_index', get_post_meta( $order->get_id(), '_shipping_address_index', true ) );
		}

		// ---------------------------------------------------------------
		// 3. Copy all standard order fields
		// ---------------------------------------------------------------
		$backorder_order->set_currency( $order->get_currency() );
		$backorder_order->set_prices_include_tax( $order->get_prices_include_tax() );
		$backorder_order->set_customer_ip_address( $order->get_customer_ip_address() );
		$backorder_order->set_customer_user_agent( $order->get_customer_user_agent() );
		$backorder_order->set_customer_note( $order->get_customer_note() );
		$backorder_order->set_payment_method( $order->get_payment_method() );
		$backorder_order->set_payment_method_title( $order->get_payment_method_title() );
		$backorder_order->set_transaction_id( $order->get_transaction_id() );
		$backorder_order->set_date_paid( $order->get_date_paid() );
		$backorder_order->set_date_completed( $order->get_date_completed() );
		$backorder_order->set_order_stock_reduced( $order->get_order_stock_reduced() );

		// ---------------------------------------------------------------
		// 4. Copy attribution and VAT exemption meta fields
		// ---------------------------------------------------------------
		foreach ( self::meta_fields_to_copy() as $meta_field ) {
			if ( $order->meta_exists( $meta_field ) ) {
				foreach ( $order->get_meta( $meta_field, false, 'edit' ) as $meta_value ) {
					$backorder_order->update_meta_data( $meta_field, $meta_value->value );
				}
			}
		}

		/*
		 * 5. Clone the planned lines onto the child. Each one records the source
		 * item it came from, so an attempt that stopped halfway moves only what
		 * is still missing. The source is untouched until this step is done,
		 * which is why a resume can still read the lines it needs.
		 */
		$source_items = $order->get_items( 'line_item' );

		if ( ! self::state_at_least( $state, self::STATE_CHILD_FILLED ) ) {
			$already = self::moved_source_item_ids( $backorder_order );

			foreach ( $plan['items'] as $entry ) {
				$item_id = (int) $entry['item_id'];

				if ( in_array( $item_id, $already, true ) ) {
					continue;
				}

				if ( empty( $source_items[ $item_id ] ) ) {
					// The plan no longer matches the order: someone changed it
					// between the interrupted attempt and this one.
					self::report_unrecoverable( $order, $plan, 'changed' );
					return;
				}

				/*
				 * Built completely, then written once. WooCommerce writes the
				 * row, its data and its meta inside a single create, so a line
				 * is either absent or present, attached to the backorder order
				 * and carrying the marker that says which source line it came
				 * from. That is what lets a retry move only what is missing,
				 * and it is why the order id is set before the save rather
				 * than left at zero until the order itself is saved.
				 */
				$target_item = clone $source_items[ $item_id ];
				$target_item->set_id( 0 );
				$target_item->set_order_id( $backorder_order->get_id() );
				$target_item->set_quantity( max( 0.0, (float) $entry['quantity'] ) );
				$target_item->set_subtotal( max( 0.0, (float) $entry['subtotal'] ) );
				$target_item->set_total( max( 0.0, (float) $entry['total'] ) );

				if ( $entry['reduced'] ) {
					$target_item->update_meta_data( '_reduced_stock', $entry['reduced'] );
				} else {
					$target_item->delete_meta_data( '_reduced_stock' );
				}

				$target_item->update_meta_data( '_wcbs_from_item_id', $item_id );
				$target_item->save();
				$backorder_order->add_item( $target_item );
			}

			$backorder_order->save();

			$order->update_meta_data( self::STATE_META, self::STATE_CHILD_FILLED );
			$order->save();
			$state = self::STATE_CHILD_FILLED;
		}

		/*
		 * 6. Only now does the source lose anything, and only to lines the child
		 * already holds. Setting each line to the quantity the plan recorded
		 * gives the same answer however many times it runs.
		 */
		if ( ! self::state_at_least( $state, self::STATE_SOURCE_UPDATED ) ) {
			foreach ( $plan['items'] as $entry ) {
				$item_id = (int) $entry['item_id'];

				if ( 'remove' === $entry['source'] ) {
					if ( isset( $source_items[ $item_id ] ) ) {
						$order->remove_item( $item_id );
					}
					continue;
				}

				if ( 'reduce' !== $entry['source'] || empty( $source_items[ $item_id ] ) ) {
					continue;
				}

				/** @var WC_Order_Item_Product $src */
				$src = $source_items[ $item_id ];
				$src->set_quantity( $entry['new_qty'] );
				$src->set_subtotal( $entry['new_subtotal'] );
				$src->set_total( $entry['new_total'] );
				if ( $entry['new_reduced'] ) {
					$src->update_meta_data( '_reduced_stock', $entry['new_reduced'] );
				}
				$src->save();
				// Note: no add_item() call here — $src IS the cached object (same PHP reference
				// from get_items()), so the cache already reflects the updated qty/totals.
				// Calling add_item() would add a second 'new:ID' key and double-count this item
				// in the subsequent calculate_totals() call.
			}

			$order->calculate_totals();
			$order->update_meta_data( self::STATE_META, self::STATE_SOURCE_UPDATED );
			$order->save();
			$state = self::STATE_SOURCE_UPDATED;
		}

		/*
		 * 7. Shipping, fees and coupons. The customer paid them once, on the
		 * original order, so by default the backorder only gets the shipping
		 * method at zero cost (the warehouse still sees how to ship it). Fees
		 * and coupon lines stay on the original; the moved lines already carry
		 * their share of any discount. Each is copied only when the child does
		 * not already have it, so a resume cannot double them.
		 */
		if ( apply_filters( 'wcbs_copy_charges_to_backorder', false, $order, $backorder_order ) ) {
			if ( ! $backorder_order->get_items( 'shipping' ) ) {
				self::copy_shipping_to_backorder( $order, $backorder_order );
			}
			if ( ! $backorder_order->get_items( 'fee' ) ) {
				self::copy_fees_to_backorder( $order, $backorder_order );
			}
			if ( ! $backorder_order->get_items( 'coupon' ) ) {
				self::copy_coupons_to_backorder( $order, $backorder_order );
			}
		} elseif ( ! $backorder_order->get_items( 'shipping' ) ) {
			self::copy_shipping_method_to_backorder( $order, $backorder_order, $plan['items'] );
		}

		$backorder_status = apply_filters( 'wcbs_backorder_status', 'backordered', $order_id, $order );
		$backorder_order->set_status( $backorder_status );

		$backorder_order->calculate_totals();
		$backorder_order->save();

		/*
		 * 8. Finished. The plan and the state go, which is what tells every
		 * later request that this order is done, and the scheduled fallback is
		 * cancelled.
		 */
		$order->delete_meta_data( self::PLAN_META );
		$order->delete_meta_data( self::STATE_META );
		$order->delete_meta_data( '_wcbs_awaiting_payment' );

		/*
		 * Count completed splits. Used only to decide whether the plugin has
		 * demonstrably done its job before it asks for a review -- a count, not
		 * a timer, so the ask follows value delivered rather than time elapsed.
		 */
		update_option( 'wcbs_split_count', 1 + (int) get_option( 'wcbs_split_count', 0 ), false );
		$order->save();

		self::unschedule_resume( $order_id );

		if ( $resuming ) {
			$order->add_order_note(
				sprintf(
					/* translators: %s: order number */
					__( 'A backorder split was interrupted and has been completed. Backorder items are on order #%s.', 'wc-backorder-split' ),
					$backorder_order->get_id()
				)
			);
			$backorder_order->add_order_note(
				sprintf(
					/* translators: %s: order number */
					__( 'This order was completed after an interrupted split of order #%s.', 'wc-backorder-split' ),
					$order->get_id()
				)
			);
		} else {
			$order->add_order_note(
				sprintf(
					/* translators: %s: order number */
					__( 'Backorder items split into order #%s', 'wc-backorder-split' ),
					$backorder_order->get_id()
				)
			);
			$backorder_order->add_order_note(
				sprintf(
					/* translators: %s: order number */
					__( 'This order contains backordered items from order #%s', 'wc-backorder-split' ),
					$order->get_id()
				)
			);
		}

		if ( (float) $order->get_shipping_total() > 0 && ! (float) $backorder_order->get_shipping_total() ) {
			$backorder_order->add_order_note(
				sprintf(
					/* translators: %s: original order number */
					__( 'Shipping was charged on order #%s.', 'wc-backorder-split' ),
					$order->get_id()
				)
			);
		}

		// ---------------------------------------------------------------
		// 9. Schedule WooCommerce Admin analytics re-import for both orders
		// ---------------------------------------------------------------
		self::possibly_schedule_analytics_import( $order, $backorder_order );

		do_action( 'wcbs_backorder_created', $backorder_order->get_id(), $order_id, $backorder_order, $order );
		do_action( 'wcbs_after_split_order', $order_id, $order );
	}

	// =========================================================================
	// Private helpers
	// =========================================================================

	/**
	 * Order meta holding the plan of an unfinished split.
	 */
	const PLAN_META = '_wcbs_split_plan';

	/**
	 * Order meta holding how far an unfinished split got. Absent means finished.
	 */
	const STATE_META = '_wcbs_split_state';

	const STATE_PLANNED        = 'planned';
	const STATE_CHILD_CREATED  = 'child_created';
	const STATE_CHILD_FILLED   = 'child_filled';
	const STATE_SOURCE_UPDATED = 'source_updated';

	/**
	 * How far an unfinished split got, or '' when there is none.
	 *
	 * @since 2.4.4
	 * @param WC_Order $order Source order.
	 * @return string
	 */
	private static function split_state( $order ) {
		return (string) $order->get_meta( self::STATE_META );
	}

	/**
	 * The stored plan of an unfinished split.
	 *
	 * @since 2.4.4
	 * @param WC_Order $order Source order.
	 * @return array
	 */
	private static function split_plan( $order ) {
		$plan = $order->get_meta( self::PLAN_META );

		return is_array( $plan ) ? $plan : array();
	}

	/**
	 * Forget an unfinished split, so the ordinary path decides again.
	 *
	 * @since 2.4.4
	 * @param WC_Order $order Source order.
	 * @return void
	 */
	private static function clear_split( $order ) {
		$order->delete_meta_data( self::PLAN_META );
		$order->delete_meta_data( self::STATE_META );
		$order->save();
		self::unschedule_resume( $order->get_id() );
	}

	/**
	 * Order of the split states, so a resume can tell what is already done.
	 *
	 * @since 2.4.4
	 * @param string $state State name.
	 * @return int
	 */
	private static function state_rank( $state ) {
		$ranks = array(
			''                         => 0,
			self::STATE_PLANNED        => 1,
			self::STATE_CHILD_CREATED  => 2,
			self::STATE_CHILD_FILLED   => 3,
			self::STATE_SOURCE_UPDATED => 4,
		);

		return isset( $ranks[ $state ] ) ? $ranks[ $state ] : 0;
	}

	/**
	 * Whether a split reached a given step.
	 *
	 * @since 2.4.4
	 * @param string $state  Recorded state.
	 * @param string $target State to compare against.
	 * @return bool
	 */
	private static function state_at_least( $state, $target ) {
		return self::state_rank( $state ) >= self::state_rank( $target );
	}

	/**
	 * Work out what the split will do, without changing anything.
	 *
	 * One record per source line: what moves to the child, and what happens to
	 * the line it came from. The quantities and totals are the ones the order
	 * has now, which is why the plan stays usable after the order changes.
	 *
	 * @since 2.4.4
	 * @param WC_Order $order Source order.
	 * @return array Plan, with an empty 'items' when there is nothing to split.
	 */
	private static function build_split_plan( $order ) {
		$order_id               = $order->get_id();
		$backorder_items        = array();
		$source_items_to_update = array(); // partial backorder: reduce qty on source.
		$source_items_to_remove = array(); // full backorder: remove from source.

		foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
			/** @var WC_Order_Item_Product $item */
			$product = $item->get_product();
			if ( ! $product ) {
				continue;
			}

			$quantity              = $item->get_quantity( 'edit' );
			$stock_quantity_at_add = $item->get_meta( '_stock_quantity_at_add' );
			$subtotal_per_unit     = (float) $order->get_item_subtotal( $item, false, false );
			$total_per_unit        = (float) $order->get_item_total( $item, false, false );
			$original_reduced      = $item->get_meta( '_reduced_stock' );

			if ( '' !== $stock_quantity_at_add ) {
				$stock_at_add = (int) $stock_quantity_at_add;

				if ( $quantity > $stock_at_add && $product->backorders_allowed() ) {
					$backorder_qty = $quantity - $stock_at_add;

					// Split _reduced_stock proportionally; source keeps its share first.
					$kept_reduced      = $original_reduced ? min( (int) $original_reduced, $stock_at_add ) : null;
					$backorder_reduced = $original_reduced ? ( (int) $original_reduced - (int) $kept_reduced ) : null;

					if ( $stock_at_add > 0 ) {
						$source_items_to_update[ $item_id ] = array(
							'new_qty'      => $stock_at_add,
							'new_subtotal' => $subtotal_per_unit * $stock_at_add,
							'new_total'    => $total_per_unit * $stock_at_add,
							'new_reduced'  => $kept_reduced,
						);
					} else {
						// Stock was 0 — entire quantity goes to backorder.
						$source_items_to_remove[] = $item_id;
					}

					$backorder_items[] = array(
						'item'     => $item,
						'quantity' => $backorder_qty,
						'subtotal' => $subtotal_per_unit * $backorder_qty,
						'total'    => $total_per_unit * $backorder_qty,
						'reduced'  => $backorder_reduced,
					);
				}
			} elseif ( $product->is_on_backorder( $quantity ) ) {
				// No stock-at-add meta but product is on backorder (e.g. stock was null).
				$source_items_to_remove[] = $item_id;
				$backorder_items[]        = array(
					'item'     => $item,
					'quantity' => $quantity,
					'subtotal' => $subtotal_per_unit * $quantity,
					'total'    => $total_per_unit * $quantity,
					'reduced'  => $original_reduced ? $original_reduced : null,
				);
			}
		}

		$backorder_items = apply_filters( 'wcbs_backorder_items', $backorder_items, $order_id, $order );

		$plan = array(
			'version' => 1,
			'items'   => array(),
		);

		foreach ( (array) $backorder_items as $data ) {
			if ( empty( $data['item'] ) || ! is_a( $data['item'], 'WC_Order_Item' ) ) {
				continue;
			}

			$item_id = (int) $data['item']->get_id();
			$entry   = array(
				'item_id'      => $item_id,
				'name'         => $data['item']->get_name(),
				'quantity'     => $data['quantity'],
				'subtotal'     => $data['subtotal'],
				'total'        => $data['total'],
				'reduced'      => isset( $data['reduced'] ) ? $data['reduced'] : null,
				'source'       => 'none',
				'new_qty'      => 0,
				'new_subtotal' => 0,
				'new_total'    => 0,
				'new_reduced'  => null,
			);

			if ( isset( $source_items_to_update[ $item_id ] ) ) {
				$entry['source']       = 'reduce';
				$entry['new_qty']      = $source_items_to_update[ $item_id ]['new_qty'];
				$entry['new_subtotal'] = $source_items_to_update[ $item_id ]['new_subtotal'];
				$entry['new_total']    = $source_items_to_update[ $item_id ]['new_total'];
				$entry['new_reduced']  = $source_items_to_update[ $item_id ]['new_reduced'];
			} elseif ( in_array( $item_id, $source_items_to_remove, true ) ) {
				$entry['source'] = 'remove';
			}

			$plan['items'][] = $entry;
		}

		return $plan;
	}

	/**
	 * The child order of a split, however far the last attempt got.
	 *
	 * Usually the id recorded on the source. When a split was interrupted
	 * between creating the child and recording its id, the child is found by
	 * the link it carries in the other direction, and the id is written back.
	 *
	 * @since 2.4.4
	 * @param WC_Order $order Source order.
	 * @return WC_Order|null
	 */
	private static function find_child_order( $order ) {
		$child_id = (int) $order->get_meta( '_wcbs_backorder_id' );

		if ( $child_id ) {
			$child = wc_get_order( $child_id );
			if ( $child ) {
				return $child;
			}
		}

		$found = self::stored_child_id( $order->get_id() );

		if ( $found ) {
			$child = wc_get_order( $found );
			if ( $child ) {
				$order->update_meta_data( '_wcbs_backorder_id', $found );
				$order->save();
				return $child;
			}
		}

		return null;
	}

	/**
	 * Id of the order that names this one as its split parent, bypassing caches.
	 *
	 * @since 2.4.4
	 * @param int $order_id Source order id.
	 * @return int Child order id, or 0.
	 */
	private static function stored_child_id( $order_id ) {
		global $wpdb;

		if ( self::is_hpos_enabled() ) {
			$sql = "SELECT order_id FROM {$wpdb->prefix}wc_orders_meta WHERE meta_key = '_wcbs_parent_order_id' AND meta_value = %s ORDER BY order_id DESC LIMIT 1";
		} else {
			$sql = "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wcbs_parent_order_id' AND meta_value = %s ORDER BY post_id DESC LIMIT 1";
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- must skip the caches; $sql holds only a placeholder.
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, (string) $order_id ) );
	}

	/**
	 * Source line ids a previous attempt already moved to the child.
	 *
	 * @since 2.4.4
	 * @param WC_Order $child Backorder order.
	 * @return int[]
	 */
	private static function moved_source_item_ids( $child ) {
		$ids = array();

		foreach ( $child->get_items( 'line_item' ) as $item ) {
			$from = (int) $item->get_meta( '_wcbs_from_item_id' );
			if ( $from ) {
				$ids[] = $from;
			}
		}

		return $ids;
	}

	/**
	 * Tell the merchant a split cannot be finished, and stop trying.
	 *
	 * Reached only when lines have already moved and the order holding them is
	 * gone, or when the order no longer matches the plan. Silence here would
	 * leave items the customer paid for on no order at all, which is the whole
	 * failure this recovery exists to prevent.
	 *
	 * @since 2.4.4
	 * @param WC_Order $order  Source order.
	 * @param array    $plan   Split plan.
	 * @param string   $reason 'missing' when the backorder order is gone after lines
	 *                         moved, 'changed' when the order no longer matches the plan.
	 * @return void
	 */
	private static function report_unrecoverable( $order, $plan, $reason = 'missing' ) {
		$names = array();

		foreach ( $plan['items'] as $entry ) {
			$names[] = $entry['name'] . ' &times; ' . wc_stock_amount( $entry['quantity'] );
		}

		$child_id = (int) $order->get_meta( '_wcbs_backorder_id' );
		$listed   = implode( ', ', $names );

		if ( 'changed' === $reason ) {
			// The backorder order exists and the original was edited before the
			// split could finish, so nothing is lost: the two orders need a look.
			$note = sprintf(
				/* translators: 1: backorder order number, 2: list of items */
				__( 'A backorder split could not be completed because this order was changed before it finished. Please compare this order with backorder order #%1$s. Items the split was moving: %2$s.', 'wc-backorder-split' ),
				$child_id ? $child_id : '-',
				$listed
			);
		} else {
			$note = sprintf(
				/* translators: 1: backorder order number, 2: list of items */
				__( 'A backorder split could not be completed: order #%1$s is missing. These items are on no order: %2$s. Please check this order.', 'wc-backorder-split' ),
				$child_id ? $child_id : '-',
				$listed
			);
		}
		$order->add_order_note( $note );

		self::log_error(
			sprintf(
				'Backorder split for order %d cannot be completed (%s): backorder order %d. Items: %s',
				$order->get_id(),
				'changed' === $reason ? 'the order no longer matches the plan' : 'the backorder order is missing',
				$child_id,
				$listed
			)
		);

		do_action( 'wcbs_split_order_error', $order->get_id(), 'unrecoverable' );

		self::clear_split( $order );
	}

	/**
	 * Ask Action Scheduler to finish a split if this request does not.
	 *
	 * The ordinary triggers are the confirmation page and a payment status
	 * change. Neither is guaranteed to happen again on an order whose request
	 * died, so recovery cannot depend on them. Action Scheduler ships with
	 * WooCommerce and survives the fatal that killed the request.
	 *
	 * Six minutes, which is past the five minute split lock, so the retry can
	 * take a lock the dead request left behind.
	 *
	 * @since 2.4.4
	 * @param int $order_id Source order id.
	 * @return void
	 */
	private static function schedule_resume( $order_id ) {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}

		$args = array( (int) $order_id );

		if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( 'wcbs_resume_split', $args, 'wc-backorder-split' ) ) {
			return;
		}

		as_schedule_single_action( time() + 6 * MINUTE_IN_SECONDS, 'wcbs_resume_split', $args, 'wc-backorder-split' );
	}

	/**
	 * Cancel the scheduled fallback for an order.
	 *
	 * @since 2.4.4
	 * @param int $order_id Source order id.
	 * @return void
	 */
	private static function unschedule_resume( $order_id ) {
		if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
			return;
		}

		as_unschedule_all_actions( 'wcbs_resume_split', array( (int) $order_id ), 'wc-backorder-split' );
	}

	/**
	 * Finish a split that an earlier request did not.
	 *
	 * @since 2.4.4
	 * @param int $order_id Source order id.
	 * @return void
	 */
	public static function resume_split( $order_id ) {
		self::flush_order_cache( (int) $order_id );
		self::split_backorder_products( (int) $order_id );
	}
	/**
	 * Order status as stored in the database, bypassing every object cache.
	 *
	 * @param int $order_id Order id.
	 * @return string Status without the "wc-" prefix, or '' when not found.
	 */
	private static function stored_status( $order_id ) {
		global $wpdb;

		if ( self::is_hpos_enabled() ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- must skip the caches.
			$status = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$wpdb->prefix}wc_orders WHERE id = %d", $order_id ) );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- must skip the caches.
			$status = $wpdb->get_var( $wpdb->prepare( "SELECT post_status FROM {$wpdb->posts} WHERE ID = %d", $order_id ) );
		}

		return 0 === strpos( (string) $status, 'wc-' ) ? substr( $status, 3 ) : (string) $status;
	}

	/**
	 * Whether any of the given meta keys is stored for an order, bypassing
	 * every object cache.
	 *
	 * @param int      $order_id Order id.
	 * @param string[] $keys     Meta keys.
	 * @return bool
	 */
	private static function stored_meta_exists( $order_id, $keys ) {
		global $wpdb;

		$in = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
		if ( self::is_hpos_enabled() ) {
			$sql = "SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders_meta WHERE order_id = %d AND meta_key IN ($in) AND meta_value <> ''";
		} else {
			$sql = "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key IN ($in) AND meta_value <> ''";
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- must skip the caches; $in holds only placeholders.
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, array_merge( array( (int) $order_id ), $keys ) ) ) > 0;
	}

	/**
	 * Drop this request's cached copies of an order, so the next
	 * wc_get_order() reads what another request may have saved since.
	 *
	 * @param int $order_id Order id.
	 */
	private static function flush_order_cache( $order_id ) {
		clean_post_cache( $order_id );

		if ( class_exists( 'Automattic\WooCommerce\Caches\OrderCache' ) && function_exists( 'wc_get_container' ) ) {
			wc_get_container()->get( 'Automattic\WooCommerce\Caches\OrderCache' )->remove( $order_id );
		}

		if ( self::is_hpos_enabled() ) {
			$store = WC_Data_Store::load( 'order' );
			if ( is_callable( array( $store, 'clear_cached_data' ) ) ) {
				$store->clear_cached_data( array( $order_id ) );
			}
		}
	}

	/**
	 * Lock values this request holds, keyed by order id.
	 *
	 * @var string[]
	 */
	private static $locks = array();

	/**
	 * Take the split lock for one order.
	 *
	 * An INSERT IGNORE on the options table's unique key, so two requests can
	 * never both get it (add_option() upserts and would let both through). The
	 * value is the time it was taken plus a token unique to this request, so a
	 * request only ever releases its own lock. A lock left behind by a request
	 * that died mid-split expires after five minutes; a split takes well under
	 * a second.
	 *
	 * @param int $order_id Order id.
	 * @return bool True when this request holds the lock.
	 */
	private static function acquire_lock( $order_id ) {
		global $wpdb;

		$name  = 'wcbs_split_lock_' . (int) $order_id;
		$value = time() . ':' . wp_generate_password( 12, false );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- an atomic lock.
		$taken = (bool) $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $name, $value ) );

		if ( ! $taken ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- an atomic lock.
			$held = (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
			if ( '' === $held || (int) $held >= time() - 5 * MINUTE_IN_SECONDS ) {
				return false;
			}
			// Expired. Delete only the row that was read, so two requests that
			// both see it expired cannot both take the lock.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- an atomic lock.
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $name, $held ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- an atomic lock.
			$taken = (bool) $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $name, $value ) );
		}

		if ( $taken ) {
			self::$locks[ (int) $order_id ] = $value;
		}
		return $taken;
	}

	/**
	 * Release the split lock for one order, only if this request holds it.
	 *
	 * @param int $order_id Order id.
	 */
	private static function release_lock( $order_id ) {
		global $wpdb;

		if ( ! isset( self::$locks[ (int) $order_id ] ) ) {
			return;
		}
		$value = self::$locks[ (int) $order_id ];
		unset( self::$locks[ (int) $order_id ] );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- an atomic lock.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", 'wcbs_split_lock_' . (int) $order_id, $value ) );
	}

	/**
	 * How much of a line's quantity is covered by stock, given the lines of the
	 * same order recorded before it.
	 *
	 * Lines that draw on one stock pool share it: two variations whose stock the
	 * parent manages, or the same product on two lines. Reading the stock level
	 * for each line on its own gave every line the full pool, so an order for
	 * 2 + 2 against a pool of 2 recorded both lines as in stock and nothing was
	 * split. Grouped by get_stock_managed_by_id(), as WooCommerce groups them
	 * when it reduces stock.
	 *
	 * @param WC_Product $product       Line product.
	 * @param WC_Order   $order         Order being built.
	 * @param string     $cart_item_key Cart item key of the line.
	 * @param int        $quantity      Line quantity.
	 * @return int Stock available to this line, never below zero.
	 */
	private static function allocate_stock( $product, $order, $cart_item_key, $quantity ) {
		// A new order, or a second pass over the same one (the Store API can
		// rebuild a draft order's lines in one request), starts from the real
		// stock levels again.
		if ( self::$allocation['order'] !== $order || in_array( (string) $cart_item_key, self::$allocation['keys'], true ) ) {
			self::$allocation = array(
				'order'     => $order,
				'keys'      => array(),
				'remaining' => array(),
			);
		}
		self::$allocation['keys'][] = (string) $cart_item_key;

		$owner = (int) $product->get_stock_managed_by_id();
		if ( ! isset( self::$allocation['remaining'][ $owner ] ) ) {
			self::$allocation['remaining'][ $owner ] = max( 0, (int) $product->get_stock_quantity() );
		}

		$available                               = self::$allocation['remaining'][ $owner ];
		self::$allocation['remaining'][ $owner ] = max( 0, $available - max( 0, (int) $quantity ) );

		return $available;
	}

	/**
	 * Log an error to WooCommerce > Status > Logs, source wc-backorder-split.
	 *
	 * Replaces bare error_log() calls, which wrote to the server's PHP log on
	 * every store. Pass ids and exception text only, never customer details.
	 *
	 * @param string $message Message.
	 */
	private static function log_error( $message ) {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->error( $message, array( 'source' => 'wc-backorder-split' ) );
		}
	}

	/**
	 * Returns the order meta fields to copy to the backorder order.
	 * Covers WooCommerce order attribution and VAT exemption.
	 *
	 * @return array
	 */
	private static function meta_fields_to_copy() {
		return apply_filters( 'wcbs_meta_fields_to_copy', array(
			'is_vat_exempt',
			'_wc_order_attribution_source_type',
			'_wc_order_attribution_device_type',
			'_wc_order_attribution_referrer',
			'_wc_order_attribution_session_count',
			'_wc_order_attribution_session_entry',
			'_wc_order_attribution_session_pages',
			'_wc_order_attribution_session_start_time',
			'_wc_order_attribution_user_agent',
			'_wc_order_attribution_utm_creative_format',
			'_wc_order_attribution_utm_marketing_tactic',
			'_wc_order_attribution_utm_source',
			'_wc_order_attribution_utm_source_platform',
		) );
	}

	/**
	 * Suppress WooCommerce emails while the backorder order is being created
	 * to avoid sending duplicate processing / new-order notifications.
	 *
	 * @param WC_Order $order
	 */
	private static function maybe_disable_emails( $order ) {
		if ( ! apply_filters( 'wcbs_disable_emails_on_split', true, $order ) ) {
			return;
		}
		add_filter( 'woocommerce_email_enabled_customer_processing_order', '__return_false' );
		add_filter( 'woocommerce_email_enabled_customer_completed_order', '__return_false' );
		add_filter( 'woocommerce_email_enabled_customer_refunded_order', '__return_false' );
		add_filter( 'woocommerce_email_enabled_customer_on_hold_order', '__return_false' );
		add_filter( 'woocommerce_email_enabled_cancelled_order', '__return_false' );
		add_filter( 'woocommerce_email_enabled_failed_order', '__return_false' );
		add_filter( 'woocommerce_email_enabled_new_order', '__return_false' );
	}

	/**
	 * Restore WooCommerce emails after backorder order creation.
	 *
	 * @param WC_Order $order
	 */
	private static function maybe_restore_emails( $order ) {
		if ( ! apply_filters( 'wcbs_disable_emails_on_split', true, $order ) ) {
			return;
		}
		remove_filter( 'woocommerce_email_enabled_customer_processing_order', '__return_false' );
		remove_filter( 'woocommerce_email_enabled_customer_completed_order', '__return_false' );
		remove_filter( 'woocommerce_email_enabled_customer_refunded_order', '__return_false' );
		remove_filter( 'woocommerce_email_enabled_customer_on_hold_order', '__return_false' );
		remove_filter( 'woocommerce_email_enabled_cancelled_order', '__return_false' );
		remove_filter( 'woocommerce_email_enabled_failed_order', '__return_false' );
		remove_filter( 'woocommerce_email_enabled_new_order', '__return_false' );
	}

	/**
	 * Returns true when WooCommerce High-Performance Order Storage is active.
	 *
	 * @return bool
	 */
	private static function is_hpos_enabled() {
		return class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	}

	/**
	 * Schedules both orders for re-import into WooCommerce Admin analytics
	 * so revenue and order-count reports stay accurate after a split.
	 *
	 * @param WC_Order $source_order
	 * @param WC_Order $target_order
	 */
	private static function possibly_schedule_analytics_import( $source_order, $target_order ) {
		$scheduler = array( 'Automattic\WooCommerce\Internal\Admin\Schedulers\OrdersScheduler', 'possibly_schedule_import' );

		// WooCommerce < 6.4 used a different namespace
		if ( ! method_exists( $scheduler[0], $scheduler[1] ) ) {
			$scheduler = array( 'Automattic\WooCommerce\Admin\Schedulers\OrdersScheduler', 'possibly_schedule_import' );
		}

		if ( method_exists( $scheduler[0], $scheduler[1] ) ) {
			call_user_func( $scheduler, $source_order->get_id() );
			call_user_func( $scheduler, $target_order->get_id() );
		}
	}

	/**
	 * Copy each shipping method to the backorder order at zero cost.
	 *
	 * The method, its IDs and meta are kept so the order shows how it ships;
	 * the cost and its taxes stay on the original order, which the customer
	 * paid. The "Items" meta is rewritten to list the backorder's own items.
	 *
	 * @since 2.4.0
	 * @param WC_Order $source_order The original order.
	 * @param WC_Order $target_order The backorder order.
	 * @param array    $plan_items   The planned lines (name and quantity). Taken
	 *                               from the plan rather than from live items, so
	 *                               it still works when a resume runs after the
	 *                               source lines have gone.
	 */
	private static function copy_shipping_method_to_backorder( $source_order, $target_order, $plan_items ) {
		$names = array();
		foreach ( $plan_items as $entry ) {
			$names[] = $entry['name'] . ' &times; ' . wc_stock_amount( $entry['quantity'] );
		}

		foreach ( $source_order->get_items( 'shipping' ) as $shipping_item ) {
			/** @var WC_Order_Item_Shipping $target_item */
			$target_item = clone $shipping_item;
			$target_item->set_id( 0 );
			$target_item->set_order_id( 0 );
			$target_item->set_total( 0 );
			$target_item->set_taxes( array( 'total' => array() ) );
			if ( '' !== $target_item->get_meta( 'Items' ) ) {
				$target_item->update_meta_data( 'Items', implode( ', ', $names ) );
			}
			$target_item->save();
			$target_order->add_item( $target_item );
		}
	}

	/**
	 * Clone shipping line items to the backorder order, preserving all meta.
	 *
	 * Only used when `wcbs_copy_charges_to_backorder` returns true.
	 *
	 * @param WC_Order $source_order
	 * @param WC_Order $target_order
	 */
	private static function copy_shipping_to_backorder( $source_order, $target_order ) {
		foreach ( $source_order->get_items( 'shipping' ) as $shipping_item ) {
			$target_item = clone $shipping_item;
			$target_item->set_id( 0 );
			$target_item->set_order_id( 0 );
			$target_item->save();
			$target_order->add_item( $target_item );
		}
	}

	/**
	 * Clone fee line items to the backorder order, preserving all meta.
	 *
	 * @param WC_Order $source_order
	 * @param WC_Order $target_order
	 */
	private static function copy_fees_to_backorder( $source_order, $target_order ) {
		foreach ( $source_order->get_items( 'fee' ) as $fee_item ) {
			$target_item = clone $fee_item;
			$target_item->set_id( 0 );
			$target_item->set_order_id( 0 );
			$target_item->save();
			$target_order->add_item( $target_item );
		}
	}

	/**
	 * Clone coupon line items to the backorder order, preserving all meta.
	 *
	 * @param WC_Order $source_order
	 * @param WC_Order $target_order
	 */
	private static function copy_coupons_to_backorder( $source_order, $target_order ) {
		foreach ( $source_order->get_items( 'coupon' ) as $coupon_item ) {
			$target_item = clone $coupon_item;
			$target_item->set_id( 0 );
			$target_item->set_order_id( 0 );
			$target_item->save();
			$target_order->add_item( $target_item );
		}
	}
}
