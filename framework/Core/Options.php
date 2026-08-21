<?php
/**
 * Typed, scope-aware access to a plugin's settings.
 *
 * Extracted from wpheka-seo-os src/Core/Options.php and extended with the
 * multisite scope that version lacks: it reads and writes with get_option
 * only, which is wrong for any setting that belongs to a network (ADR-012).
 *
 * @package WPHEKA\Framework
 */

declare( strict_types=1 );

namespace WPHEKA\Framework\V1\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Typed access to all of a plugin's settings, stored in a single option row.
 *
 * One row rather than one row per setting: a settings screen reads everything
 * at once, so N options means N queries where one will do.
 *
 * **Scope is explicit and mandatory.** On multisite, `get_option` is per-site
 * and `get_site_option` is network-wide. Choosing wrongly either scatters
 * network settings across sites or leaks one site's settings into another, so
 * the caller declares the scope rather than inheriting a default that happens
 * to work on single-site installs (ADR-012).
 */
final class Options {

	/** Per-site settings. `get_option` / `update_option`. */
	public const SCOPE_SITE = 'site';

	/** Network-wide settings. `get_site_option` / `update_site_option`. */
	public const SCOPE_NETWORK = 'network';

	/**
	 * Option row name.
	 *
	 * @var string
	 */
	private string $key;

	/**
	 * Default value for every setting this plugin understands.
	 *
	 * @var array<string, mixed>
	 */
	private array $defaults;

	/**
	 * Either self::SCOPE_SITE or self::SCOPE_NETWORK.
	 *
	 * @var string
	 */
	private string $scope;

	/**
	 * Cached merged settings, or null when not yet read.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $cache = null;

	/**
	 * Blog id the cache was populated for; null for network scope.
	 *
	 * @var int|null
	 */
	private ?int $cached_for_blog = null;

	/**
	 * Construct a scoped settings accessor.
	 *
	 * @param string               $key      Option row name, prefixed by the plugin.
	 * @param array<string, mixed> $defaults Default for every known setting.
	 * @param string               $scope    self::SCOPE_SITE or self::SCOPE_NETWORK.
	 */
	public function __construct( string $key, array $defaults = array(), string $scope = self::SCOPE_SITE ) {
		$this->key      = $key;
		$this->defaults = $defaults;
		$this->scope    = self::SCOPE_NETWORK === $scope ? self::SCOPE_NETWORK : self::SCOPE_SITE;
	}

	/**
	 * Build with the scope implied by how the plugin was activated.
	 *
	 * Network activation is a super admin declaring "this network is one
	 * deployment"; per-site activation declares "this site uses this product".
	 * Settings follow that declaration, which is the same rule licence seats
	 * follow (ADR-013).
	 *
	 * **Pass $network_activated explicitly during activation.** WordPress writes
	 * `active_sitewide_plugins` AFTER firing `activate_{$plugin}`
	 * (wp-admin/includes/plugin.php: the hook fires at line ~63, the option is
	 * written at ~69). So during activation the inference below returns false
	 * even for a network activation, and provisioning would write per-site
	 * options that the plugin then never reads at runtime, when the inference
	 * correctly returns true. The activation hook receives $network_wide from
	 * WordPress; thread it through rather than re-deriving it.
	 *
	 * @param string               $key              Option row name.
	 * @param array<string, mixed> $defaults         Defaults.
	 * @param string               $plugin_basename  Result of plugin_basename( __FILE__ ).
	 * @param bool|null            $network_activated Explicit override; null infers.
	 * @return self
	 */
	public static function for_plugin( string $key, array $defaults, string $plugin_basename, ?bool $network_activated = null ): self {
		$is_network = null === $network_activated
			? self::is_network_activated( $plugin_basename )
			: $network_activated;

		return new self( $key, $defaults, $is_network ? self::SCOPE_NETWORK : self::SCOPE_SITE );
	}

	/**
	 * Whether a plugin is network-activated.
	 *
	 * `is_plugin_active_for_network()` lives in an admin-only file, so this
	 * reads the network option directly and works on front-end requests too.
	 *
	 * UNRELIABLE DURING ACTIVATION: WordPress writes `active_sitewide_plugins`
	 * after firing the activation hook, so this returns false mid-activation
	 * even for a network activation. Use the $network_wide argument WordPress
	 * hands the activation hook instead.
	 *
	 * @param string $plugin_basename Result of plugin_basename( __FILE__ ).
	 * @return bool
	 */
	public static function is_network_activated( string $plugin_basename ): bool {
		if ( ! is_multisite() ) {
			return false;
		}

		$active = get_site_option( 'active_sitewide_plugins', array() );

		return is_array( $active ) && isset( $active[ $plugin_basename ] );
	}

	/**
	 * The scope in use.
	 *
	 * @return string
	 */
	public function scope(): string {
		return $this->scope;
	}

	/**
	 * Every setting, with defaults merged in.
	 *
	 * @return array<string, mixed>
	 */
	public function all(): array {
		if ( null !== $this->cache && ! $this->cache_is_stale() ) {
			return $this->cache;
		}

		$saved = self::SCOPE_NETWORK === $this->scope
			? get_site_option( $this->key, array() )
			: get_option( $this->key, array() );

		$this->cache           = array_merge( $this->defaults, is_array( $saved ) ? $saved : array() );
		$this->cached_for_blog = self::SCOPE_NETWORK === $this->scope ? null : get_current_blog_id();

		return $this->cache;
	}

	/**
	 * One setting.
	 *
	 * @param string $key           Setting name.
	 * @param mixed  $default_value Returned when the setting is unknown.
	 * @return mixed
	 */
	public function get( string $key, $default_value = null ) {
		$all = $this->all();

		return array_key_exists( $key, $all ) ? $all[ $key ] : $default_value;
	}

	/**
	 * Merge a partial update and persist it.
	 *
	 * @param array<string, mixed> $partial Settings to change.
	 * @return array<string, mixed> The full settings after the update.
	 */
	public function update( array $partial ): array {
		/*
		 * Re-read before merging. The cache is request-lifetime, so merging onto
		 * it writes back whatever the row held when this request first read it —
		 * and a concurrent writer's change, made in between, is silently undone.
		 * Two admin requests saving different sections of the same screen was the
		 * shape that made this reachable in practice.
		 *
		 * This narrows the window rather than closing it: WordPress has no
		 * compare-and-set for an option row, so a writer landing between this read
		 * and the write below is still lost. Anything that must not be lost gets a
		 * row of its own, as licence seats now do.
		 */
		$this->flush();

		$merged = array_merge( $this->all(), $partial );

		if ( self::SCOPE_NETWORK === $this->scope ) {
			update_site_option( $this->key, $merged );
		} else {
			// Not autoloaded: settings are read on demand, not on every request.
			update_option( $this->key, $merged, false );
		}

		$this->cache           = $merged;
		$this->cached_for_blog = self::SCOPE_NETWORK === $this->scope ? null : get_current_blog_id();

		return $merged;
	}

	/**
	 * Delete the option row entirely.
	 *
	 * @return void
	 */
	public function delete(): void {
		if ( self::SCOPE_NETWORK === $this->scope ) {
			delete_site_option( $this->key );
		} else {
			delete_option( $this->key );
		}

		$this->flush();
	}

	/**
	 * Drop the in-memory cache.
	 *
	 * @return void
	 */
	public function flush(): void {
		$this->cache           = null;
		$this->cached_for_blog = null;
	}

	/**
	 * Whether the cache belongs to a different site than the current one.
	 *
	 * The container is per-request and does not re-resolve services across
	 * `switch_to_blog()` (ADR-012), so a site-scoped cache populated before a
	 * switch would otherwise serve one site's settings to another. Network
	 * scope is unaffected, being the same row for every site.
	 */
	private function cache_is_stale(): bool {
		if ( self::SCOPE_NETWORK === $this->scope ) {
			return false;
		}

		return get_current_blog_id() !== $this->cached_for_blog;
	}
}
