<?php
/**
 * Registers payment methods with the WooCommerce Blocks checkout.
 *
 * @package WPHEKA\Framework
 */

declare( strict_types=1 );

namespace WPHEKA\Framework\V1\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Registers block payment method types.
 *
 * The Blocks checkout does not render classic gateways. A gateway that works
 * perfectly on the shortcode checkout simply does not appear on a Blocks one,
 * with no error anywhere — the customer just cannot pay with it. That failure
 * mode is why this is worth a framework helper rather than being left to each
 * plugin: it is invisible unless someone tests the Blocks checkout specifically.
 *
 * `wc-moneris-payment-gateway-pro` registers two of these (Direct and MCO) with
 * a repeated block of `class_exists` guard, `require_once`, and closure, which
 * is what this replaces.
 */
final class Blocks {

	/**
	 * Register one or more block payment method types.
	 *
	 * Factories are used rather than instances so nothing is constructed on
	 * requests that never reach the Blocks checkout, which is most of them.
	 *
	 * @param callable[] $factories Each returns an AbstractPaymentMethodType instance.
	 * @return void
	 */
	public static function register_payment_methods( array $factories ): void {
		add_action(
			'woocommerce_blocks_payment_method_type_registration',
			static function ( $registry ) use ( $factories ): void {
				foreach ( $factories as $factory ) {
					$method = $factory();

					/*
					 * The interface, not is_object(). PaymentMethodRegistry::register()
					 * type-hints IntegrationInterface, so handing it any other object
					 * is a TypeError thrown inside WooCommerce's own registration
					 * pass — which takes down the Blocks checkout for every gateway
					 * on the site, not just the one that was mis-declared.
					 */
					if ( $method instanceof \Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface ) {
						$registry->register( $method );
					}
				}
			}
		);
	}

	/**
	 * Whether the Blocks payment API is present.
	 *
	 * Guard before referencing `AbstractPaymentMethodType`: extending a class
	 * that does not exist is a fatal at file-parse time, not a graceful
	 * degradation, so the subclass file must not even be loaded when Blocks is
	 * absent.
	 *
	 * @return bool
	 */
	public static function available(): bool {
		return class_exists( \Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType::class );
	}

	/**
	 * Whether the current page is being rendered by the Blocks checkout.
	 *
	 * Useful for gateways that must enqueue different assets for each checkout,
	 * and for diagnostics reporting which checkout a site actually uses.
	 *
	 * @return bool
	 */
	public static function checkout_is_block_based(): bool {
		if ( ! function_exists( 'wc_get_page_id' ) || ! function_exists( 'has_block' ) ) {
			return false;
		}

		$checkout_id = wc_get_page_id( 'checkout' );

		if ( $checkout_id <= 0 ) {
			return false;
		}

		return has_block( 'woocommerce/checkout', $checkout_id );
	}
}
