<?php
/**
 * Facade over the resolved framework build.
 *
 * @package WPHEKA\Framework
 */

declare( strict_types=1 );

namespace WPHEKA\Framework\V1\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Reports which build of major version 1 actually booted.
 *
 * Consumer plugins use this to confirm the framework they got is new enough
 * before they initialise. Deliberately dependency-free and stateless: it is
 * called during a plugin's own bootstrap, before any container exists.
 */
final class Framework {

	/**
	 * Major version this class belongs to.
	 */
	public const MAJOR = '1';

	/**
	 * Version of the build that booted for this major.
	 *
	 * @return string Version string, or '' when the registry is absent.
	 */
	public static function version(): string {
		if ( ! class_exists( 'WPHEKA_Framework_Versions', false ) ) {
			return '';
		}

		$version = \WPHEKA_Framework_Versions::active_version( self::MAJOR );

		return is_string( $version ) ? $version : '';
	}

	/**
	 * Whether the booted build is at least the given version.
	 *
	 * @param string $minimum Minimum acceptable version, e.g. '1.5.0'.
	 * @return bool
	 */
	public static function at_least( string $minimum ): bool {
		$version = self::version();

		if ( '' === $version ) {
			return false;
		}

		return version_compare( $version, $minimum, '>=' );
	}

	/**
	 * Directory of the build that booted.
	 *
	 * Not necessarily the calling plugin's own copy — the registry may have
	 * chosen a newer build bundled by a different plugin.
	 *
	 * @return string Absolute path, or '' when not booted.
	 */
	public static function dir(): string {
		return defined( 'WPHEKA_FRAMEWORK_V1_DIR' ) ? (string) WPHEKA_FRAMEWORK_V1_DIR : '';
	}
}
