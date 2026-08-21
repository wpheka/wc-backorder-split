<?php
/**
 * Base class for WooCommerce payment gateways.
 *
 * @package WPHEKA\Framework
 */

declare( strict_types=1 );

namespace WPHEKA\Framework\V1\WooCommerce;

use WPHEKA\Framework\V1\Admin\Settings;
use WPHEKA\Framework\V1\Admin\WooCommerceFields;
use WPHEKA\Framework\V1\Core\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * A payment gateway whose settings come from a declared schema.
 *
 * **This file must not be autoloaded when WooCommerce is absent.** It extends
 * `WC_Payment_Gateway`, and extending a class that does not exist is a fatal at
 * parse time, not a graceful failure. Guard with `Compatibility::active()`
 * before referencing this class — including in `class_exists()` checks, which
 * trigger autoloading.
 *
 * What it removes from each gateway: hand-maintained `form_fields` arrays that
 * drift from the documentation, a logger that silently discards everything when
 * WooCommerce has not loaded, and per-gateway HPOS branching that WooCommerce
 * already handles.
 *
 * What it deliberately does not do: `process_payment()` stays abstract. Payment
 * capture is the part that differs per processor and the part where a helpful
 * default would be actively dangerous.
 */
abstract class Gateway extends \WC_Payment_Gateway {

	/**
	 * Declared settings schema.
	 *
	 * @var Settings|null
	 */
	protected ?Settings $schema = null;

	/**
	 * Framework logger, created on first use.
	 *
	 * @var Logger|null
	 */
	private ?Logger $logger = null;

	/**
	 * Build the settings schema for this gateway.
	 *
	 * Called lazily, never at construction: building a schema translates its
	 * labels, and a gateway is constructed early enough that translating there
	 * can precede `init` (ADR-007).
	 *
	 * @return Settings
	 */
	abstract protected function define_settings(): Settings;

	/**
	 * The declared schema, built once.
	 *
	 * @return Settings
	 */
	public function schema(): Settings {
		if ( null === $this->schema ) {
			$this->schema = $this->define_settings();
		}

		return $this->schema;
	}

	/**
	 * Populate WooCommerce's form_fields from the schema.
	 *
	 * Call from the gateway's constructor, after `$this->id` is set. Kept as an
	 * explicit call rather than done automatically, because WooCommerce reads
	 * `form_fields` at a point that varies with how a gateway is registered.
	 *
	 * @return void
	 */
	public function init_form_fields(): void {
		$this->form_fields = WooCommerceFields::from( $this->schema() );
	}

	/**
	 * Read a setting, normalised to its declared type.
	 *
	 * WooCommerce stores checkboxes as 'yes' and 'no'. `get_option()` on a
	 * gateway therefore returns a string where the schema declares a boolean,
	 * and every caller ends up writing its own comparison. This returns what the
	 * schema says it is.
	 *
	 * @param string $key           Setting key.
	 * @param mixed  $default_value Returned when unset.
	 * @return mixed
	 */
	public function setting( string $key, $default_value = null ) {
		/*
		 * Asked of the settings array rather than get_option(), because
		 * get_option() cannot answer the question. It collapses "never saved"
		 * and "saved as an empty string" to the same empty string, so a merchant
		 * who deliberately clears an optional field got the default back and the
		 * field appeared to refuse to stay cleared.
		 *
		 * The previous version tested `'' === $raw` and returned the default. A
		 * comment recorded that PHPStan had found the preceding null check dead,
		 * which was true — get_option() does return a string — but the
		 * conclusion drawn from it was not: an empty string is a value here, not
		 * the absence of one.
		 */
		if ( empty( $this->settings ) ) {
			$this->init_settings();
		}

		if ( ! array_key_exists( $key, $this->settings ) ) {
			return $default_value;
		}

		$normalised = WooCommerceFields::normalise( $this->schema(), array( $key => $this->settings[ $key ] ) );

		return $normalised[ $key ] ?? $default_value;
	}

	/**
	 * Whether a toggle setting is on.
	 *
	 * @param string $key Setting key.
	 * @return bool
	 */
	public function enabled_setting( string $key ): bool {
		return true === $this->setting( $key, false );
	}

	/**
	 * This gateway's logger.
	 *
	 * Logging is gated on a `logging` toggle when the schema declares one, so a
	 * gateway does not write card-flow diagnostics on every checkout by default.
	 *
	 * @return Logger
	 */
	public function log(): Logger {
		if ( null === $this->logger ) {
			$enabled = true;

			foreach ( $this->schema()->fields() as $field ) {
				if ( 'logging' === $field->id() ) {
					$enabled = $this->enabled_setting( 'logging' );
					break;
				}
			}

			$this->logger = new Logger( $this->id, $enabled );
		}

		return $this->logger;
	}

	/**
	 * Store meta on an order, without caring which storage is active.
	 *
	 * @param int|\WC_Order        $order Order or order id.
	 * @param array<string, mixed> $data  Key => value.
	 * @return bool
	 */
	public function remember( $order, array $data ): bool {
		return OrderMeta::set_many( $order, $data );
	}

	/**
	 * Read meta from an order.
	 *
	 * @param int|\WC_Order $order Order or order id.
	 * @param string        $key   Meta key.
	 * @param mixed         $default_value Returned when absent.
	 * @return mixed
	 */
	public function recall( $order, string $key, $default_value = '' ) {
		return OrderMeta::get( $order, $key, $default_value );
	}

	/**
	 * Record a failure and return the result array WooCommerce expects.
	 *
	 * Gateways repeat this shape at every failure point, and the repetition is
	 * where the mistakes live: a notice added but the order left processing, or
	 * a raw processor message shown to the customer. The message given to the
	 * customer and the detail written to the log are separate arguments so a
	 * processor's diagnostics are never rendered at checkout.
	 *
	 * @param string              $customer_message Already-translated, safe to display.
	 * @param string              $log_detail       Diagnostic detail for the log only.
	 * @param array<string,mixed> $context          Structured context; redacted before writing.
	 * @return array<string, string>
	 */
	public function fail( string $customer_message, string $log_detail = '', array $context = array() ): array {
		// Unconditional: the log entry is the record of the failure, and it must
		// survive contexts where there is no customer to show a notice to.
		$this->log()->error( '' === $log_detail ? $customer_message : $log_detail, $context );

		/*
		 * A notice needs somewhere to live, and a session only exists on a
		 * front-end request. A gateway calls its failure path from webhooks, IPN
		 * handlers, REST routes and admin screens too, none of which have one.
		 *
		 * WooCommerce 10.5 added its own guard for this, returning early with a
		 * `_doing_it_wrong` — but this framework supports WooCommerce 3.0+, and
		 * on anything older `wc_add_notice()` calls `WC()->session->get()`
		 * straight out. A fatal, thrown inside a payment failure handler, on the
		 * versions least likely to be watched. Guarding here rather than relying
		 * on the host's version also keeps the newer versions quiet, since their
		 * guard still emits a notice into the log this method just wrote to.
		 *
		 * No function_exists( 'WC' ) alongside it: this class extends
		 * \WC_Payment_Gateway, so it cannot be declared at all without
		 * WooCommerce loaded — by the time any method of it runs, WC() exists.
		 * That check was written in reflexively and removed as dead code.
		 */
		if ( did_action( 'woocommerce_init' ) && $this->session() ) {
			wc_add_notice( $customer_message, 'error' );
		}

		return array(
			'result'   => 'failure',
			'redirect' => '',
		);
	}

	/**
	 * The success result array, after the order has been completed.
	 *
	 * @param \WC_Order $order Paid order.
	 * @return array<string, string>
	 */
	public function succeed( \WC_Order $order ): array {
		return array(
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order ),
		);
	}

	/**
	 * The WooCommerce session, when there is one.
	 *
	 * **Returns `mixed` on purpose.** The WooCommerce stubs declare this property
	 * non-nullable, so a static analyser reports any check of it as always true.
	 * It is not: WooCommerce initialises the session only on requests that need
	 * one, and `wc_add_notice()` in core guards it with `if ( ! WC()->session )`
	 * — core does not defend against a state that cannot occur.
	 *
	 * Reading it through a `mixed` return states that the stub's claim does not
	 * hold here, which is true, rather than suppressing the question with an
	 * ignore comment or a cast. Compare `Scheduler::intervals_beneath()`, which
	 * exists for the same reason against a different stub.
	 *
	 * @return mixed The session object, or null on a request without one.
	 */
	private function session() {
		return WC()->session;
	}
}
