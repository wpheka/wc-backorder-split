<?php
/**
 * Detects translation calls made before `init`.
 *
 * @package WPHEKA\Framework
 */

declare( strict_types=1 );

namespace WPHEKA\Framework\V1\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Records any translation of a plugin's text domain that happens before `init`.
 *
 * WordPress 6.7+ refuses to translate a string requested before `init` and
 * raises `_doing_it_wrong` with `_load_textdomain_just_in_time`. In production
 * `WP_DEBUG` is off, so that notice goes nowhere: the string is silently left
 * in English, in every language, forever.
 *
 * `wpheka-seo-os` shipped exactly this defect. Six WooCommerce identifier
 * labels called `__()` at service-registration time, reached from `Plugin::boot()`
 * on `plugins_loaded`, and were never translatable. It took a deliberate
 * mu-plugin backtrace to find. ADR-007 made "no translation before `init`" a
 * rule; this class makes it observable.
 *
 * Detection is by filtering `gettext`, which fires for every translated string.
 * That is broader than waiting for WordPress' own notice: it catches an early
 * call even when the text domain happens to be loaded already, which is the
 * case that produces no warning at all and still breaks on a site where load
 * order differs.
 *
 * Development aid, not a production feature. Register it only when `WP_DEBUG`
 * is on, or from a test.
 */
final class I18nGuard {

	/**
	 * Most violations recorded; a hot path must not exhaust memory.
	 */
	private const LIMIT = 25;

	/**
	 * Text domain to watch.
	 *
	 * @var string
	 */
	private string $domain;

	/**
	 * Violations recorded this request.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $violations = array();

	/**
	 * Construct a guard for one text domain.
	 *
	 * @param string $domain Text domain to watch.
	 */
	public function __construct( string $domain ) {
		$this->domain = $domain;
	}

	/**
	 * Start watching.
	 *
	 * Must be registered before the plugin's other subsystems, since the whole
	 * point is to catch what they do while registering.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'gettext', array( $this, 'inspect' ), 10, 3 );
	}

	/**
	 * Record a translation requested before `init`.
	 *
	 * Returns the translation untouched. A guard that changed behaviour would
	 * be a bug of its own.
	 *
	 * @param string $translation Translated text.
	 * @param string $text        Original text.
	 * @param string $domain      Text domain.
	 * @return string
	 */
	public function inspect( $translation, $text = '', $domain = '' ): string {
		if ( $domain !== $this->domain || did_action( 'init' ) ) {
			return (string) $translation;
		}

		if ( count( $this->violations ) < self::LIMIT ) {
			$source = $this->caller();
			$key    = $text . '|' . $source;

			foreach ( $this->violations as $existing ) {
				if ( $existing['key'] === $key ) {
					return (string) $translation;
				}
			}

			$this->violations[] = array(
				'key'    => $key,
				'string' => $text,
				'source' => $source,
				'hook'   => '' === (string) current_filter() ? 'none' : (string) current_filter(),
			);
		}

		return (string) $translation;
	}

	/**
	 * Everything recorded this request.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function violations(): array {
		return $this->violations;
	}

	/**
	 * First call-stack frame outside WordPress core and this class.
	 *
	 * Naming the translation function itself would be useless — every violation
	 * would report `__()`. What matters is the code that called it.
	 *
	 * @return string
	 */
	private function caller(): string {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_debug_backtrace, WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace
		$stack = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 20 );
		// Literal rather than WPINC: this is a development aid, and on a site that
		// relocated wp-includes the guard degrades to naming a core frame, which is
		// harmless. Depending on the constant buys nothing and costs analysability.
		$core = wp_normalize_path( ABSPATH . 'wp-includes' );
		$self = wp_normalize_path( __FILE__ );

		foreach ( $stack as $step ) {
			if ( empty( $step['file'] ) ) {
				continue;
			}

			$file = wp_normalize_path( (string) $step['file'] );

			if ( $file === $self || 0 === strpos( $file, $core ) ) {
				continue;
			}

			return basename( dirname( $file ) ) . '/' . basename( $file ) . ':' . ( $step['line'] ?? 0 );
		}

		return '(unknown)';
	}
}
