<?php
/**
 * WC Backorder Split Front-End
 *
 * @version 2.2.0
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
			$product = wc_get_product( $product_id );

			if ( ! $product ) {
				error_log( 'WC Backorder Split: Invalid product ID ' . $product_id );
				return;
			}

			$stock_quantity = $product->get_stock_quantity();

			if ( ! $product->backorders_allowed() ) {
				return;
			}

			if ( is_numeric( $stock_quantity ) && $quantity > $stock_quantity ) {
				if ( isset( WC()->cart->cart_contents[ $cart_item_key ] ) ) {
					WC()->cart->cart_contents[ $cart_item_key ]['_stock_quantity_at_add'] = $stock_quantity;
				}
			}
		} catch ( Exception $e ) {
			error_log( 'WC Backorder Split - Error in store_stock_quantity_in_cart_item: ' . $e->getMessage() );
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

			if ( $stock_quantity_at_add !== '' || ! $product->is_on_backorder() ) {
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
		try {
			$order = wc_get_order( $order_id );

			if ( ! $order ) {
				error_log( 'WC Backorder Split: Invalid order ID ' . $order_id );
				return;
			}

			// Prevent duplicate splits
			if ( $order->get_meta( '_wcbs_backorder_id' ) || $order->get_meta( '_wcbs_processed' ) ) {
				return;
			}

			if ( ! apply_filters( 'wcbs_should_split_order', true, $order_id, $order ) ) {
				return;
			}

			// All items already on backorder — just flip the status, no split needed
			if ( self::are_all_products_in_backorder( $order_id ) ) {
				$order->set_status( 'backordered' );
				$order->add_meta_data( '_wcbs_processed', 'yes', true );
				$order->save();
				return;
			}

			do_action( 'wcbs_before_split_order', $order_id, $order );

			// Classify every line item before touching anything in the DB.
			// Per-unit prices are captured now so they remain correct after
			// the source items are updated.
			$backorder_items        = array();
			$source_items_to_update = array(); // partial backorder: reduce qty on source
			$source_items_to_remove = array(); // full backorder: remove from source

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

				if ( $stock_quantity_at_add !== '' ) {
					$stock_at_add = (int) $stock_quantity_at_add;

					if ( $quantity > $stock_at_add && $product->backorders_allowed() ) {
						$backorder_qty = $quantity - $stock_at_add;

						// Split _reduced_stock proportionally; source keeps its share first
						$kept_reduced      = $original_reduced ? min( (int) $original_reduced, $stock_at_add ) : null;
						$backorder_reduced = $original_reduced ? ( (int) $original_reduced - (int) $kept_reduced ) : null;

						if ( $stock_at_add > 0 ) {
							$source_items_to_update[ $item_id ] = array(
								'item'         => $item,
								'new_qty'      => $stock_at_add,
								'new_subtotal' => $subtotal_per_unit * $stock_at_add,
								'new_total'    => $total_per_unit * $stock_at_add,
								'new_reduced'  => $kept_reduced,
							);
						} else {
							// Stock was 0 — entire quantity goes to backorder
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
					// No stock-at-add meta but product is on backorder (e.g. stock was null)
					$source_items_to_remove[] = $item_id;
					$backorder_items[]        = array(
						'item'     => $item,
						'quantity' => $quantity,
						'subtotal' => $subtotal_per_unit * $quantity,
						'total'    => $total_per_unit * $quantity,
						'reduced'  => $original_reduced ?: null,
					);
				}
			}

			$backorder_items = apply_filters( 'wcbs_backorder_items', $backorder_items, $order_id, $order );

			if ( empty( $backorder_items ) ) {
				return;
			}

			// ---------------------------------------------------------------
			// 1. Update source order items in-place (preserve all item meta)
			// ---------------------------------------------------------------
			foreach ( $source_items_to_update as $data ) {
				/** @var WC_Order_Item_Product $src */
				$src = $data['item'];
				$src->set_quantity( $data['new_qty'] );
				$src->set_subtotal( $data['new_subtotal'] );
				$src->set_total( $data['new_total'] );
				if ( $data['new_reduced'] ) {
					$src->update_meta_data( '_reduced_stock', $data['new_reduced'] );
				}
				$src->save();
				// Note: no add_item() call here — $src IS the cached object (same PHP reference
				// from get_items()), so the cache already reflects the updated qty/totals.
				// Calling add_item() would add a second 'new:ID' key and double-count this item
				// in the subsequent calculate_totals() call.
			}

			foreach ( $source_items_to_remove as $item_id ) {
				$order->remove_item( $item_id );
			}

			$order->calculate_totals();
			$order->save();

			// ---------------------------------------------------------------
			// 2. Create the backorder order with emails suppressed to avoid
			//    sending duplicate processing/new-order notifications
			// ---------------------------------------------------------------
			self::maybe_disable_emails( $order );

			$backorder_order = wc_create_order( array(
				'customer_id' => $order->get_customer_id(),
				'created_via' => __( 'Backorder split', 'wc-backorder-split' ),
			) );

			self::maybe_restore_emails( $order );

			if ( ! $backorder_order ) {
				error_log( 'WC Backorder Split: Failed to create backorder order for order ID ' . $order_id );
				return;
			}

			// ---------------------------------------------------------------
			// 3. Copy addresses + HPOS-aware address indexes (for order search)
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
			// 4. Copy all standard order fields
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
			// 5. Copy attribution and VAT exemption meta fields
			// ---------------------------------------------------------------
			foreach ( self::meta_fields_to_copy() as $meta_field ) {
				if ( $order->meta_exists( $meta_field ) ) {
					foreach ( $order->get_meta( $meta_field, false, 'edit' ) as $meta_value ) {
						$backorder_order->update_meta_data( $meta_field, $meta_value->value );
					}
				}
			}

			// ---------------------------------------------------------------
			// 6. Clone line items — preserves product, variation, and all
			//    item-level meta (e.g. custom fields, addons, subscriptions)
			// ---------------------------------------------------------------
			foreach ( $backorder_items as $data ) {
				$source_item = $data['item'];

				$target_item = clone $source_item;
				$target_item->set_id( 0 );
				$target_item->set_order_id( 0 );
				$target_item->save(); // creates the DB record with a new ID

				$target_item->set_quantity( max( 0.0, (float) $data['quantity'] ) );
				$target_item->set_subtotal( max( 0.0, (float) $data['subtotal'] ) );
				$target_item->set_total( max( 0.0, (float) $data['total'] ) );

				if ( $data['reduced'] ) {
					$target_item->update_meta_data( '_reduced_stock', $data['reduced'] );
				} else {
					$target_item->delete_meta_data( '_reduced_stock' );
				}

				$target_item->save();
				$backorder_order->add_item( $target_item );
			}

			// ---------------------------------------------------------------
			// 7. Clone shipping, fees, and coupons (preserves all item meta)
			// ---------------------------------------------------------------
			self::copy_shipping_to_backorder( $order, $backorder_order );
			self::copy_fees_to_backorder( $order, $backorder_order );
			self::copy_coupons_to_backorder( $order, $backorder_order );

			$backorder_status = apply_filters( 'wcbs_backorder_status', 'backordered', $order_id, $order );
			$backorder_order->set_status( $backorder_status );

			$backorder_order->calculate_totals();
			$backorder_order->save();

			// Link orders together
			$order->add_meta_data( '_wcbs_backorder_id', $backorder_order->get_id(), true );

			/*
			 * Count completed splits. Used only to decide whether the plugin has
			 * demonstrably done its job before it asks for a review -- a count, not
			 * a timer, so the ask follows value delivered rather than time elapsed.
			 */
			update_option( 'wcbs_split_count', 1 + (int) get_option( 'wcbs_split_count', 0 ), false );
			$backorder_order->add_meta_data( '_wcbs_parent_order_id', $order->get_id(), true );
			$order->save();
			$backorder_order->save();

			$order->add_order_note(
				sprintf(
					__( 'Backorder items split into order #%s', 'wc-backorder-split' ),
					$backorder_order->get_id()
				)
			);
			$backorder_order->add_order_note(
				sprintf(
					__( 'This order contains backordered items from order #%s', 'wc-backorder-split' ),
					$order->get_id()
				)
			);

			// ---------------------------------------------------------------
			// 8. Schedule WooCommerce Admin analytics re-import for both orders
			// ---------------------------------------------------------------
			self::possibly_schedule_analytics_import( $order, $backorder_order );

			do_action( 'wcbs_backorder_created', $backorder_order->get_id(), $order_id, $backorder_order, $order );
			do_action( 'wcbs_after_split_order', $order_id, $order );

		} catch ( Exception $e ) {
			error_log( 'WC Backorder Split - Error in split_backorder_products: ' . $e->getMessage() );
			do_action( 'wcbs_split_order_error', $order_id, $e->getMessage() );
		}
	}

	// =========================================================================
	// Private helpers
	// =========================================================================

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
	 * Clone shipping line items to the backorder order, preserving all meta.
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
