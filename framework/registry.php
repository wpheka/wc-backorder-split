<?php
/**
 * WPHEKA Framework version registry.
 *
 * ============================ FROZEN FILE ============================
 * Do not change this file. Ever. See ADR-001.
 *
 * Every plugin bundling the framework ships a copy of this file. The first
 * copy loaded on a request defines the class; every later copy is skipped by
 * the class_exists() guard below. That means an OLD plugin's copy may be the
 * one that runs, so the behaviour of this file must be identical in every
 * version of the framework we ever release.
 *
 * A defect here cannot be fixed in the field. Work around it in bootstrap.php,
 * which is not frozen.
 *
 * Constraints that follow, and must be preserved:
 *
 *  - Parseable on ancient PHP. This runs before any version guard could, so a
 *    parse error is a fatal white screen rather than a graceful notice. No
 *    closures, no type declarations, no short array syntax, no ::class.
 *  - No dependency on Composer, on the framework autoloader, or on any
 *    framework class. Only WordPress' add_action() and did_action().
 *  - No use of anything from the WPHEKA\Framework namespace.
 *
 * @package WPHEKA\Framework
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WPHEKA_Framework_Versions', false ) ) {

	/**
	 * Resolves which bundled build of the framework boots, per major version.
	 *
	 * Majors are isolated by namespace (WPHEKA\Framework\V1\, V2\, ...) and
	 * each boots independently, so a plugin pinned to V1 is unaffected by a
	 * plugin bundling V2. Within a major, the highest registered build wins,
	 * which is safe because we guarantee backward compatibility inside a
	 * major version.
	 */
	class WPHEKA_Framework_Versions {

		/**
		 * Registered builds, keyed by major then by version string.
		 *
		 * @var array
		 */
		private static $builds = array();

		/**
		 * Majors already booted, keyed by major.
		 *
		 * @var array
		 */
		private static $booted = array();

		/**
		 * Whether the plugins_loaded callback has been attached.
		 *
		 * @var bool
		 */
		private static $hooked = false;

		/**
		 * Register a bundled build of the framework.
		 *
		 * Called at main-plugin-file include time, not on a hook: plugins_loaded
		 * is already too late for payment gateways and block registration.
		 *
		 * @param string $version   Full semantic version of this build, e.g. '1.9.0'.
		 * @param string $bootstrap Absolute path to this build's bootstrap.php.
		 * @return void
		 */
		public static function register( $version, $bootstrap ) {
			$version = (string) $version;
			$major   = self::major( $version );

			if ( '' === $major || ! is_string( $bootstrap ) || '' === $bootstrap ) {
				return;
			}

			if ( ! isset( self::$builds[ $major ] ) ) {
				self::$builds[ $major ] = array();
			}

			// First registration of a given version wins; identical versions are
			// interchangeable by definition, so re-registering is a no-op.
			if ( ! isset( self::$builds[ $major ][ $version ] ) ) {
				self::$builds[ $major ][ $version ] = $bootstrap;
			}

			/*
			 * A plugin activated during this request, or loaded from an mu-plugin
			 * after plugins_loaded has already fired, would otherwise never boot.
			 * Boot its major immediately in that case.
			 */
			if ( function_exists( 'did_action' ) && did_action( 'plugins_loaded' ) ) {
				self::boot_major( $major );
				return;
			}

			if ( ! self::$hooked && function_exists( 'add_action' ) ) {
				self::$hooked = true;
				add_action( 'plugins_loaded', array( 'WPHEKA_Framework_Versions', 'boot' ), -100 );
			}
		}

		/**
		 * Boot the winning build of every registered major.
		 *
		 * Hooked to plugins_loaded at priority -100 so the framework is available
		 * before any consumer plugin initialises.
		 *
		 * @return void
		 */
		public static function boot() {
			foreach ( array_keys( self::$builds ) as $major ) {
				self::boot_major( $major );
			}
		}

		/**
		 * Boot the highest registered build of one major version.
		 *
		 * @param string $major Major version, e.g. '1'.
		 * @return void
		 */
		private static function boot_major( $major ) {
			if ( isset( self::$booted[ $major ] ) ) {
				return;
			}

			$winner = self::resolve( $major );

			if ( null === $winner ) {
				return;
			}

			// Marked before the require so a fatal in bootstrap cannot loop.
			self::$booted[ $major ] = $winner;

			if ( is_readable( self::$builds[ $major ][ $winner ] ) ) {
				require_once self::$builds[ $major ][ $winner ];
			}
		}

		/**
		 * Highest registered version string for a major, or null if none.
		 *
		 * @param string $major Major version, e.g. '1'.
		 * @return string|null
		 */
		private static function resolve( $major ) {
			if ( empty( self::$builds[ $major ] ) ) {
				return null;
			}

			$winner = null;

			foreach ( array_keys( self::$builds[ $major ] ) as $version ) {
				if ( null === $winner || version_compare( $version, $winner, '>' ) ) {
					$winner = $version;
				}
			}

			return $winner;
		}

		/**
		 * Version of the build that booted for a major.
		 *
		 * Consumer plugins compare this against their declared minimum and
		 * degrade with an admin notice if it is too old, rather than fatalling.
		 *
		 * @param string $major Major version, e.g. '1'.
		 * @return string|null Booted version, or null if that major has not booted.
		 */
		public static function active_version( $major ) {
			$major = (string) $major;

			return isset( self::$booted[ $major ] ) ? self::$booted[ $major ] : null;
		}

		/**
		 * Whether a major version has booted.
		 *
		 * @param string $major Major version, e.g. '1'.
		 * @return bool
		 */
		public static function is_booted( $major ) {
			return isset( self::$booted[ (string) $major ] );
		}

		/**
		 * All versions registered for a major, in registration order.
		 *
		 * Diagnostic use: shows which plugins brought which builds to the party.
		 *
		 * @param string $major Major version, e.g. '1'.
		 * @return array
		 */
		public static function registered( $major ) {
			$major = (string) $major;

			return isset( self::$builds[ $major ] ) ? self::$builds[ $major ] : array();
		}

		/**
		 * Extract the major component of a version string.
		 *
		 * @param string $version Version string, e.g. '1.9.0'.
		 * @return string Major version, or '' when unparseable.
		 */
		private static function major( $version ) {
			if ( ! preg_match( '/^(\d+)/', (string) $version, $matches ) ) {
				return '';
			}

			return $matches[1];
		}
	}
}
