<?php
/**
 * Boots major version 1 of the WPHEKA Framework.
 *
 * NOT frozen. This file may change between releases, and it is where any
 * workaround for a defect in the frozen loader belongs (ADR-001).
 *
 * The registry requires exactly one copy of this file per major version, on
 * plugins_loaded at priority -100 — the highest-versioned build registered by
 * any active plugin.
 *
 * @package WPHEKA\Framework
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

// The registry guarantees one boot per major, but a direct require should be
// idempotent too: this file is reachable by paths the registry does not own.
if ( defined( 'WPHEKA_FRAMEWORK_V1_DIR' ) ) {
	return;
}

define( 'WPHEKA_FRAMEWORK_V1_DIR', __DIR__ );
define( 'WPHEKA_FRAMEWORK_V1_VERSION', (string) require __DIR__ . '/version.php' );

/**
 * PSR-4 autoloader for this major version, with a union fallback.
 *
 * Deliberately not Composer's autoloader: no vendor directory ships at
 * runtime (ADR-005), and the framework must load on hosts where Composer's
 * autoloader is absent or belongs to another plugin.
 *
 * Only WPHEKA\Framework\V1\ is claimed, so V2 can register its own loader
 * against its own directory without either interfering with the other.
 *
 * **The winning bundle is not necessarily a complete one** (ADR-022). Bundling
 * is modular (ADR-016) but the frozen registry resolves purely on version
 * string, and its comment that "identical versions are interchangeable" stopped
 * being true the moment two plugins could ship the same version with different
 * module sets. Whichever bundle wins, every module any *other* active plugin
 * bundled simply vanishes. Verified on a real install: a plugin bundling
 * Licensing at 1.9.1 starved a plugin that needed Database, which then fatalled
 * during activation rather than degrading.
 *
 * So a class missing from the winning bundle is looked up in the bundles that
 * lost. The effective module set becomes the union across active plugins, which
 * is what each plugin already expected to have.
 *
 * The fallback is lazy on purpose. Discovery costs filesystem checks, and the
 * common case — one bundle carrying everything asked of it — never reaches it.
 *
 * @param string $class_name Fully-qualified class name.
 * @return void
 */
function wpheka_framework_v1_autoload( $class_name ) {
	$prefix = 'WPHEKA\\Framework\\V1\\';
	$length = strlen( $prefix );

	if ( 0 !== strncmp( $class_name, $prefix, $length ) ) {
		return;
	}

	$relative = substr( $class_name, $length );

	// Keep the resolved path inside a framework directory: a class name
	// carrying traversal segments must not reach outside one.
	if ( false !== strpos( $relative, '.' ) ) {
		return;
	}

	$path = str_replace( '\\', '/', $relative ) . '.php';
	$file = WPHEKA_FRAMEWORK_V1_DIR . '/' . $path;

	if ( is_readable( $file ) ) {
		require_once $file;

		return;
	}

	foreach ( wpheka_framework_v1_roots() as $root ) {
		$candidate = $root . '/' . $path;

		if ( is_readable( $candidate ) ) {
			require_once $candidate;

			return;
		}
	}
}

/**
 * Framework directories other than the one that booted, highest version first.
 *
 * Two sources, because neither alone is sufficient:
 *
 * - The registry knows one path per *distinct version*, which covers a bundle
 *   that lost on version.
 * - It keeps only the first registration of any given version, discarding the
 *   rest outright, so a same-version bundle with different modules is invisible
 *   there and has to be found from the active-plugin list instead.
 *
 * Roots are filtered to major 1. A V2 bundle also lives in a directory called
 * `framework/`, and loading a V2 class file into the V1 namespace would be a
 * far worse failure than the missing class it was trying to fix.
 *
 * Sorted highest version first so that when several bundles carry the same
 * module, the newest wins — matching the resolution rule for the primary
 * bundle. Cross-bundle loading within a major is safe under the backward
 * compatibility guarantee we already make for a major version.
 *
 * @return string[] Absolute directory paths, without trailing slashes.
 */
function wpheka_framework_v1_roots() {
	static $roots = null;

	if ( null !== $roots ) {
		return $roots;
	}

	$found = array();

	// Source 1: bundles the registry kept, which lost on version.
	if ( class_exists( 'WPHEKA_Framework_Versions', false ) ) {
		foreach ( WPHEKA_Framework_Versions::registered( '1' ) as $bootstrap ) {
			if ( is_string( $bootstrap ) && '' !== $bootstrap ) {
				$found[] = dirname( $bootstrap );
			}
		}
	}

	/*
	 * Source 2: bundles discarded as duplicate versions, recoverable only from
	 * the plugins themselves.
	 *
	 * Each value is checked for traversal before it becomes a path. These come
	 * from the active_plugins option, and the roots built here are require()d
	 * from — so a value containing "../" would load PHP from anywhere on disk.
	 * Writing that option already implies database access, but the loader is the
	 * one component ADR-001 says cannot be fixed in the field, so it does not
	 * get to assume its inputs are well-formed.
	 *
	 * Deliberately checked *before* realpath, not after. A plugin directory that
	 * is a symlink resolves outside WP_PLUGIN_DIR legitimately — routine in
	 * development and on some managed hosts, and supported on purpose — so
	 * containment is asserted on the path as given, and the symlink is allowed
	 * to point where it likes.
	 */
	foreach ( wpheka_framework_v1_active_plugin_files() as $plugin_file ) {
		if ( false !== strpos( $plugin_file, '..' ) || '/' === substr( $plugin_file, 0, 1 ) ) {
			continue;
		}

		$found[] = dirname( WP_PLUGIN_DIR . '/' . $plugin_file ) . '/framework';
	}

	$roots   = array();
	$primary = realpath( WPHEKA_FRAMEWORK_V1_DIR );
	$primary = false === $primary ? WPHEKA_FRAMEWORK_V1_DIR : $primary;

	foreach ( $found as $root ) {
		/*
		 * Resolved before comparing. One bundle is reachable by more than one
		 * path whenever plugins are symlinked — routine in development and on
		 * some managed hosts — and the registry records the resolved path while
		 * the active-plugin list yields the symlinked one. Comparing the raw
		 * strings let the booted bundle reappear in its own fallback list and
		 * every bundle be scanned twice.
		 */
		$root = realpath( rtrim( (string) $root, '/' ) );

		if ( false === $root || $primary === $root || isset( $roots[ $root ] ) ) {
			continue;
		}

		$version = wpheka_framework_v1_root_version( $root );

		if ( null === $version ) {
			continue;
		}

		$roots[ $root ] = $version;
	}

	// Highest version first; ties keep discovery order, which is stable.
	uasort(
		$roots,
		function ( $left, $right ) {
			return version_compare( $right, $left );
		}
	);

	$roots = array_keys( $roots );

	return $roots;
}

/**
 * Plugin files active on this site, including network-activated ones.
 *
 * @return string[] Plugin basenames, e.g. "my-plugin/my-plugin.php".
 */
function wpheka_framework_v1_active_plugin_files() {
	$files = get_option( 'active_plugins', array() );
	$files = is_array( $files ) ? $files : array();

	if ( is_multisite() ) {
		// Network activations are keyed by plugin file, not listed as values.
		$sitewide = get_site_option( 'active_sitewide_plugins', array() );

		if ( is_array( $sitewide ) ) {
			$files = array_merge( $files, array_keys( $sitewide ) );
		}
	}

	return array_unique( array_filter( $files, 'is_string' ) );
}

/**
 * Version of a candidate framework directory, if it is a usable major 1 bundle.
 *
 * @param string $root Absolute path to a framework directory.
 * @return string|null Version string, or null when unusable or not major 1.
 */
function wpheka_framework_v1_root_version( $root ) {
	$version_file = $root . '/version.php';

	if ( ! is_readable( $version_file ) ) {
		return null;
	}

	$version = require $version_file;

	if ( ! is_string( $version ) || ! preg_match( '/^1\./', $version ) ) {
		return null;
	}

	return $version;
}

spl_autoload_register( 'wpheka_framework_v1_autoload' );
