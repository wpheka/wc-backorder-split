<?php
/**
 * Logging that works with or without WooCommerce.
 *
 * @package WPHEKA\Framework
 */

declare( strict_types=1 );

namespace WPHEKA\Framework\V1\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Writes to WooCommerce's logger when it is present, and to a file when it is not.
 *
 * Our existing loggers in wc-moneris-payment-gateway and its Pro counterpart
 * both begin `if ( ! class_exists( 'WC_Logger' ) ) { return; }` — so on any
 * request where WooCommerce is absent or not yet loaded, every log call is
 * silently discarded. That is the worst possible behaviour for a diagnostic
 * tool: it disappears exactly when something is wrong enough that WooCommerce
 * failed to load.
 *
 * Deliberately not PSR-3. PSR-3 would pull an interface dependency into a
 * runtime that ships no Composer autoloader (ADR-005), and WordPress plugins
 * have no ecosystem expectation of it.
 */
final class Logger {

	public const DEBUG   = 'debug';
	public const INFO    = 'info';
	public const WARNING = 'warning';
	public const ERROR   = 'error';

	/**
	 * Log source; becomes the WooCommerce log handle and the file name.
	 *
	 * @var string
	 */
	private string $source;

	/**
	 * Whether logging is switched on.
	 *
	 * @var bool
	 */
	private bool $enabled;

	/**
	 * Cached WooCommerce logger, if any.
	 *
	 * @var object|null
	 */
	private ?object $wc_logger = null;

	/**
	 * Construct a logger.
	 *
	 * @param string $source  Log handle, e.g. 'wpheka-gateway-moneris'.
	 * @param bool   $enabled Whether to write at all.
	 */
	public function __construct( string $source, bool $enabled = true ) {
		$this->source  = sanitize_key( $source );
		$this->enabled = $enabled;
	}

	/**
	 * Log a debug message.
	 *
	 * @param string              $message Message.
	 * @param array<string,mixed> $context Structured context; redacted before writing.
	 * @return void
	 */
	public function debug( string $message, array $context = array() ): void {
		$this->log( self::DEBUG, $message, $context );
	}

	/**
	 * Log an informational message.
	 *
	 * @param string              $message Message.
	 * @param array<string,mixed> $context Structured context.
	 * @return void
	 */
	public function info( string $message, array $context = array() ): void {
		$this->log( self::INFO, $message, $context );
	}

	/**
	 * Log a warning.
	 *
	 * @param string              $message Message.
	 * @param array<string,mixed> $context Structured context.
	 * @return void
	 */
	public function warning( string $message, array $context = array() ): void {
		$this->log( self::WARNING, $message, $context );
	}

	/**
	 * Log an error.
	 *
	 * @param string              $message Message.
	 * @param array<string,mixed> $context Structured context.
	 * @return void
	 */
	public function error( string $message, array $context = array() ): void {
		$this->log( self::ERROR, $message, $context );
	}

	/**
	 * Write one entry.
	 *
	 * @param string              $level   One of the level constants.
	 * @param string              $message Message.
	 * @param array<string,mixed> $context Structured context.
	 * @return void
	 */
	public function log( string $level, string $message, array $context = array() ): void {
		/**
		 * Filters whether a log entry is written.
		 *
		 * @param bool   $enabled Whether to write.
		 * @param string $message The message.
		 * @param string $source  The log source.
		 */
		if ( ! apply_filters( 'wpheka_framework_logging', $this->enabled, $message, $this->source ) ) {
			return;
		}

		if ( $context ) {
			$message .= ' ' . (string) wp_json_encode( self::redact( $context ) );
		}

		if ( $this->write_to_woocommerce( $level, $message ) ) {
			return;
		}

		$this->write_to_file( $level, $message );
	}

	/**
	 * Write through WooCommerce's logger when available.
	 *
	 * @param string $level   Level.
	 * @param string $message Message.
	 * @return bool Whether the entry was written.
	 */
	private function write_to_woocommerce( string $level, string $message ): bool {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return false;
		}

		if ( null === $this->wc_logger ) {
			// wc_get_logger() is typed to return WC_Logger_Interface, so a
			// defensive is_object() check here was dead code -- found once the
			// WooCommerce stubs let PHPStan see the real return type.
			$this->wc_logger = wc_get_logger();
		}

		$this->wc_logger->log( $level, $message, array( 'source' => $this->source ) );

		return true;
	}

	/**
	 * Fallback file writer, used when WooCommerce is absent.
	 *
	 * Writes into wp-content/uploads with an index.php and .htaccess guard, so
	 * logs are not served over HTTP on hosts that do not deny access by default.
	 *
	 * @param string $level   Level.
	 * @param string $message Message.
	 * @return void
	 */
	private function write_to_file( string $level, string $message ): void {
		$uploads = wp_upload_dir();

		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
			return;
		}

		$dir = trailingslashit( $uploads['basedir'] ) . 'wpheka-logs';

		if ( ! wp_mkdir_p( $dir ) ) {
			return;
		}

		// Deny direct access. Cheap to re-assert and harmless if already present.
		if ( ! file_exists( $dir . '/index.php' ) ) {
			file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}

		self::write_htaccess( $dir );

		$line = sprintf(
			'[%s] %s: %s%s',
			gmdate( 'd-M-Y H:i:s' ) . ' UTC',
			strtoupper( $level ),
			$message,
			PHP_EOL
		);

		/*
		 * The name carries a per-install secret, as WooCommerce's own logger
		 * does. index.php and .htaccess only stop directory listing and only on
		 * Apache -- nginx ignores .htaccess entirely -- so on a misconfigured
		 * host a fully predictable path means anyone can fetch the log by
		 * guessing plugin slug and date. Logs carry redacted secrets but plenty
		 * of operational detail.
		 */
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents(
			sprintf( '%s/%s-%s-%s.log', $dir, $this->source, gmdate( 'Y-m-d' ), self::file_secret() ),
			$line,
			FILE_APPEND | LOCK_EX
		);
	}

	/**
	 * Write the access guard into the log directory.
	 *
	 * `Deny from all` is Apache 2.2 syntax. Apache 2.4 only understands it with
	 * `mod_access_compat` loaded, and where it is not, the directive is not
	 * ignored — it is an unknown command, which makes the whole directory a 500.
	 * So the file has to carry both forms, each inside a guard for the module that
	 * understands it, and they have to be mutually exclusive or 2.4 with the
	 * compatibility module applies both.
	 *
	 * A directory left with only the legacy one-liner is rewritten. Skipping that
	 * would leave every install created before this fix exactly as exposed as it
	 * was, which is the half of the problem that actually exists in the field.
	 *
	 * @param string $dir Log directory.
	 * @return void
	 */
	private static function write_htaccess( string $dir ): void {
		$file = $dir . '/.htaccess';

		$rules = "<IfModule mod_authz_core.c>\n"
			. "\tRequire all denied\n"
			. "</IfModule>\n"
			. "<IfModule !mod_authz_core.c>\n"
			. "\tOrder allow,deny\n"
			. "\tDeny from all\n"
			. "</IfModule>\n";

		if ( file_exists( $file ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local file, never a URL.
			$existing = file_get_contents( $file );

			// Only the exact file this class used to write is replaced. Anything
			// else in there was put there by someone, and is not ours to discard.
			if ( "Deny from all\n" !== $existing ) {
				return;
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $file, $rules );
	}

	/**
	 * A stable per-install suffix for log filenames.
	 *
	 * Generated once and reused, so a day's entries land in one file rather than
	 * scattering across a new name per request.
	 *
	 * @return string
	 */
	private static function file_secret(): string {
		$stored = get_site_option( 'wpheka_log_file_secret', '' );

		if ( is_string( $stored ) && '' !== $stored ) {
			return $stored;
		}

		$secret = wp_generate_password( 20, false );

		// Insert-if-absent, so two concurrent first writes cannot end up naming
		// two different files and splitting the same day's log.
		if ( ! add_site_option( 'wpheka_log_file_secret', $secret ) ) {
			$winner = get_site_option( 'wpheka_log_file_secret', '' );

			if ( is_string( $winner ) && '' !== $winner ) {
				return $winner;
			}

			update_site_option( 'wpheka_log_file_secret', $secret );
		}

		return $secret;
	}

	/**
	 * Redact sensitive values from structured context.
	 *
	 * The matching rules matter, and are the result of getting this wrong once
	 * in wpheka-seo-os. `secret`, `password` and `credential` match anywhere in
	 * a key. `key` and `token` match only as a trailing word — a plain substring
	 * match also caught `target_keywords` and `max_tokens_per_day`, hiding the
	 * very data being diagnosed. Over-redaction is not automatically safer; it
	 * makes a diagnostic tool useless while looking responsible.
	 *
	 * @param array<string,mixed> $context Context to redact.
	 * @return array<string,mixed>
	 */
	public static function redact( array $context ): array {
		foreach ( $context as $key => $value ) {
			if ( is_array( $value ) ) {
				$context[ $key ] = self::redact( $value );
				continue;
			}

			$name = strtolower( (string) $key );

			if ( preg_match( '/(secret|password|credential)/', $name ) || preg_match( '/(^|_)(key|token)$/', $name ) ) {
				$context[ $key ] = '[redacted]';
			}
		}

		return $context;
	}
}
