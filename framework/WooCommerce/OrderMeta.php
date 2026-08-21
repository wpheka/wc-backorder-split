<?php
/**
 * Storage-agnostic order meta access.
 *
 * @package WPHEKA\Framework
 */

declare( strict_types=1 );

namespace WPHEKA\Framework\V1\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes order meta without caring whether HPOS is enabled.
 *
 * `wc-moneris-payment-gateway-pro` currently branches on HPOS for every order
 * meta read and write, calling `update_meta_data()` on the order when HPOS is
 * on and `update_post_meta()` when it is off. **That branch is unnecessary.**
 * WooCommerce's order CRUD has been storage-agnostic since 3.0 and HPOS did not
 * change it — `update_meta_data()` followed by `save()` writes to whichever
 * store is active. Verified against a live HPOS-enabled install.
 *
 * Carrying the branch is not merely redundant, it is a bug surface: the legacy
 * arm writes to postmeta directly, so on an HPOS site with post syncing off,
 * anything reaching that arm writes where nothing will ever read.
 *
 * This class exists to make the correct call convenient, not to abstract a
 * difference WooCommerce already hides.
 */
final class OrderMeta {

	/**
	 * Read one meta value from an order.
	 *
	 * @param int|\WC_Order $order Order or order id.
	 * @param string        $key   Meta key.
	 * @param mixed         $default_value Returned when the order or key is absent.
	 * @return mixed
	 */
	public static function get( $order, string $key, $default_value = '' ) {
		$order = self::resolve( $order );

		if ( null === $order ) {
			return $default_value;
		}

		$value = $order->get_meta( $key );

		return '' === $value || null === $value ? $default_value : $value;
	}

	/**
	 * Write one meta value to an order and persist it.
	 *
	 * Saves immediately. A caller writing several keys should use `set_many()`
	 * instead: each `save()` is a database write, and saving once per key turns
	 * a checkout into several redundant round-trips.
	 *
	 * @param int|\WC_Order $order Order or order id.
	 * @param string        $key   Meta key.
	 * @param mixed         $value Value to store.
	 * @return bool Whether the order was found and saved.
	 */
	public static function set( $order, string $key, $value ): bool {
		return self::set_many( $order, array( $key => $value ) );
	}

	/**
	 * Write several meta values and persist them in one save.
	 *
	 * @param int|\WC_Order        $order Order or order id.
	 * @param array<string, mixed> $data  Key => value.
	 * @return bool Whether the order was found and saved.
	 */
	public static function set_many( $order, array $data ): bool {
		$order = self::resolve( $order );

		if ( null === $order ) {
			return false;
		}

		foreach ( $data as $key => $value ) {
			$order->update_meta_data( $key, $value );
		}

		return self::persist( $order );
	}

	/**
	 * Remove one meta key from an order.
	 *
	 * @param int|\WC_Order $order Order or order id.
	 * @param string        $key   Meta key.
	 * @return bool Whether the order was found and saved.
	 */
	public static function delete( $order, string $key ): bool {
		$order = self::resolve( $order );

		if ( null === $order ) {
			return false;
		}

		$order->delete_meta_data( $key );

		return self::persist( $order );
	}

	/**
	 * Save an order and report whether the write actually happened.
	 *
	 * `WC_Data::save()` returns the order id, which is truthy for an order that
	 * was loaded successfully whether or not the datastore accepted the write —
	 * so "it returned a positive number" is not evidence of persistence. A
	 * datastore that cannot write throws instead, and an uncaught exception here
	 * surfaces mid-checkout as a fatal rather than as a gateway declining.
	 *
	 * Callers act on this: a gateway that stores a transaction id and carries on
	 * regardless has an order that was charged and cannot be reconciled.
	 *
	 * @param \WC_Order $order Order to save.
	 * @return bool Whether it was persisted.
	 */
	private static function persist( \WC_Order $order ): bool {
		try {
			// Typed int by WC_Data, so the id alone settles it. A belt-and-braces
			// is_numeric() here was flagged as unreachable, as it was in Logger.
			return $order->save() > 0;
		} catch ( \Exception $e ) {
			return false;
		}
	}

	/**
	 * Accept an id or an order object and return an order, or null.
	 *
	 * `wc_get_order()` returns false for a missing or non-order id, and callers
	 * routinely forget that. Returning null and checking once here means a
	 * deleted order produces a no-op rather than a fatal on a checkout page.
	 *
	 * @param int|\WC_Order $order Order or order id.
	 * @return \WC_Order|null
	 */
	private static function resolve( $order ): ?\WC_Order {
		if ( $order instanceof \WC_Order ) {
			return $order;
		}

		if ( ! function_exists( 'wc_get_order' ) ) {
			return null;
		}

		$resolved = wc_get_order( $order );

		return $resolved instanceof \WC_Order ? $resolved : null;
	}
}
