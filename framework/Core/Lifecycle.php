<?php
/**
 * Plugin activation, deactivation and upgrade, correct on multisite.
 *
 * @package WPHEKA\Framework
 */

declare( strict_types=1 );

namespace WPHEKA\Framework\V1\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Runs a plugin's provisioning work once per site, on every site that needs it.
 *
 * `register_activation_hook` fires **once** when a plugin is network-activated,
 * not once per site, and never at all for sites created afterwards. A plugin
 * that provisions only in that hook ships a network where most sites have no
 * tables and no capabilities — and the failure is invisible to a developer
 * working on a single-site install (ADR-012).
 *
 * This class closes both halves of that gap: it fans activation out across the
 * existing network, and it hooks new-site creation so later sites are covered.
 */
final class Lifecycle {

	/**
	 * Sites provisioned per batch when fanning out across a network.
	 *
	 * Activation on a large network is a single request that must not exhaust
	 * memory or time. Batching keeps the working set bounded.
	 */
	private const SITE_BATCH = 100;

	/*
	 * There is deliberately no register() convenience method that a plugin can
	 * call at include time to wire all of this up.
	 *
	 * It looked reasonable and was actively harmful. A plugin main file is
	 * included BEFORE plugins_loaded, but the framework autoloader is only
	 * registered by bootstrap.php on plugins_loaded at -100. So any
	 * `class_exists( Lifecycle::class )` guard at include time is false, the
	 * call is skipped, and wp_initialize_site is never attached.
	 *
	 * Worse, it appeared to work: on an activation request plugins_loaded has
	 * already fired, so the registry boots immediately and the guard passes.
	 * Activation provisioned correctly while new-site provisioning was dead --
	 * silently reintroducing the exact ADR-012 bug this class exists to prevent.
	 *
	 * Plugins therefore wire plain WordPress functions at include time
	 * (register_activation_hook, register_deactivation_hook, add_action) and
	 * call into this class from inside those callbacks, which run after the
	 * framework has booted. See examples/wpheka-example-alpha.
	 */

	/**
	 * Provision on activation.
	 *
	 * $network_wide is passed on to the callback. It must be, rather than being
	 * re-derived: WordPress writes `active_sitewide_plugins` only AFTER firing
	 * the activation hook, so anything asking "am I network-activated?" during
	 * provisioning gets the wrong answer and writes to the wrong option scope.
	 *
	 * @param callable $provision    Per-site provisioning callback, receives bool $network_wide.
	 * @param bool     $network_wide Whether this was a network activation.
	 * @return void
	 */
	public static function activate( callable $provision, bool $network_wide ): void {
		if ( ! $network_wide || ! is_multisite() ) {
			$provision( $network_wide );

			return;
		}

		self::for_each_site( $provision, $network_wide );
	}

	/**
	 * Deprovision on deactivation.
	 *
	 * @param callable $deprovision  Per-site callback, receives bool $network_wide.
	 * @param bool     $network_wide Whether this was a network deactivation.
	 * @return void
	 */
	public static function deactivate( callable $deprovision, bool $network_wide ): void {
		if ( ! $network_wide || ! is_multisite() ) {
			$deprovision( $network_wide );

			return;
		}

		self::for_each_site( $deprovision, $network_wide );
	}

	/**
	 * Run a callback once against every site on the network.
	 *
	 * @param callable $callback     Per-site callback, receives bool $network_wide.
	 * @param bool     $network_wide Passed through to the callback.
	 * @return void
	 */
	public static function for_each_site( callable $callback, bool $network_wide = false ): void {
		$offset = 0;

		do {
			$site_ids = get_sites(
				array(
					'fields'                 => 'ids',
					'number'                 => self::SITE_BATCH,
					'offset'                 => $offset,
					'orderby'                => 'id',
					'update_site_meta_cache' => false,
				)
			);

			foreach ( $site_ids as $site_id ) {
				switch_to_blog( (int) $site_id );

				try {
					$callback( $network_wide );
				} finally {
					// restore_current_blog() in finally: one site throwing must
					// not strand the whole request on the wrong blog.
					restore_current_blog();
				}
			}

			$found   = count( $site_ids );
			$offset += self::SITE_BATCH;
		} while ( self::SITE_BATCH === $found );
	}

	/**
	 * Provision a site created after the plugin was activated.
	 *
	 * Call from a `wp_initialize_site` callback. That hook must be attached with
	 * a plain add_action() at plugin include time — see the note above on why
	 * this class cannot attach it for you.
	 *
	 * @param mixed    $site        WP_Site instance passed by wp_initialize_site.
	 * @param string   $plugin_file The plugin's main file.
	 * @param callable $provision   Per-site provisioning callback. Must be idempotent.
	 * @return void
	 */
	public static function on_new_site( $site, string $plugin_file, callable $provision ): void {
		if ( ! is_multisite() || ! $site instanceof \WP_Site ) {
			return;
		}

		// Only network-activated plugins belong on a brand-new site. A
		// per-site activation is a decision about one specific site.
		if ( ! Options::is_network_activated( plugin_basename( $plugin_file ) ) ) {
			return;
		}

		switch_to_blog( (int) $site->blog_id );

		try {
			// A site created later only exists on a network-activated plugin,
			// which this method has already confirmed above.
			$provision( true );
		} finally {
			restore_current_blog();
		}
	}
}
