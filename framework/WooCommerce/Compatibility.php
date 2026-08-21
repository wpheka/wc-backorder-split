<?php
/**
 * WooCommerce feature compatibility declarations.
 *
 * @package WPHEKA\Framework
 */

declare( strict_types=1 );

namespace WPHEKA\Framework\V1\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Declares a plugin compatible with WooCommerce features, in one call.
 *
 * This is the single most copy-pasted block in our catalogue. Every WooCommerce
 * plugin we ship carries its own near-identical `declare_compatibility()`
 * function, and the payment gateways carry two — one for HPOS, one for cart and
 * checkout blocks — each wrapped in the same `class_exists` guard.
 *
 * The timing is the part that is easy to get wrong and impossible to notice:
 * `before_woocommerce_init` is the only hook where a declaration is heard.
 * Declaring later is silently ignored, and the plugin is then listed as
 * *incompatible* in WooCommerce's settings while appearing to work in testing.
 */
final class Compatibility {

	/** High Performance Order Storage. */
	public const HPOS = 'custom_order_tables';

	/** Cart and Checkout blocks. */
	public const BLOCKS = 'cart_checkout_blocks';

	/**
	 * Declare compatibility with one or more WooCommerce features.
	 *
	 * **Timing, verified against WooCommerce's source rather than assumed.**
	 * `includes/class-woocommerce.php` hooks `WC::init()` to `init` at priority
	 * 0, and that method fires `before_woocommerce_init`. So the declaration
	 * hook fires on `init`, comfortably after `plugins_loaded` — call this any
	 * time before `init` and it is heard.
	 *
	 * In practice call it from a `plugins_loaded` callback, not at plugin
	 * include time: the framework autoloader only registers on `plugins_loaded`
	 * at -100 (ADR-001), so at include time this class does not yet exist and a
	 * `class_exists()` guard around the call silently skips it. That mistake
	 * declared nothing while appearing to work, and WooCommerce then listed the
	 * plugin as HPOS-incompatible.
	 *
	 * @param string   $plugin_file The plugin's main file, i.e. __FILE__.
	 *                              Must be the main file — WooCommerce resolves
	 *                              it to a plugin basename, so passing a file
	 *                              from a subdirectory silently declares nothing.
	 * @param string[] $features    Feature identifiers; defaults to HPOS and Blocks.
	 * @return void
	 */
	public static function declare_for( string $plugin_file, array $features = array( self::HPOS, self::BLOCKS ) ): void {
		add_action(
			'before_woocommerce_init',
			static function () use ( $plugin_file, $features ): void {
				if ( ! class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
					return;
				}

				foreach ( $features as $feature ) {
					\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( $feature, $plugin_file, true );
				}
			}
		);
	}

	/**
	 * Whether HPOS is the active order storage on this site.
	 *
	 * Needed far less often than it appears. Order CRUD — `wc_get_order()`,
	 * `update_meta_data()`, `save()` — is storage-agnostic and has been since
	 * WooCommerce 3.0; HPOS did not change that API. Code branching on this to
	 * choose between `update_meta_data()` and `update_post_meta()` is carrying a
	 * distinction WooCommerce already handles, as `wc-moneris-payment-gateway-pro`
	 * currently does.
	 *
	 * Reach for this only when reading or writing the order tables directly.
	 *
	 * @return bool
	 */
	public static function hpos_enabled(): bool {
		if ( ! class_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class ) ) {
			return false;
		}

		return \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	}

	/**
	 * Whether WooCommerce is active and loaded.
	 *
	 * @return bool
	 */
	public static function active(): bool {
		return class_exists( 'WooCommerce' );
	}

	/**
	 * WooCommerce's version, or an empty string when it is not active.
	 *
	 * @return string
	 */
	public static function version(): string {
		return defined( 'WC_VERSION' ) ? (string) WC_VERSION : '';
	}
}
