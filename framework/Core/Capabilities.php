<?php
/**
 * Custom capability registration.
 *
 * @package WPHEKA\Framework
 */

declare( strict_types=1 );

namespace WPHEKA\Framework\V1\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Grants and revokes a plugin's custom capabilities against WordPress roles.
 *
 * Extracted from wpheka-seo-os src/Core/Capabilities.php and generalised: the
 * capability-to-roles map is supplied by the plugin rather than hardcoded.
 *
 * Capabilities are persisted into the roles table, so granting is a write and
 * belongs in provisioning (`Core\Lifecycle`), not in a boot path. On multisite
 * roles are per-site, which is why this runs once per site rather than once per
 * network (ADR-012).
 */
final class Capabilities {

	/**
	 * Capability name to the roles that should hold it.
	 *
	 * @var array<string, string[]>
	 */
	private array $map;

	/**
	 * Construct with a capability map.
	 *
	 * @param array<string, string[]> $map e.g. array( 'manage_thing' => array( 'administrator' ) ).
	 */
	public function __construct( array $map ) {
		$this->map = $map;
	}

	/**
	 * Grant every mapped capability.
	 *
	 * Idempotent: `add_cap` on a role that already has the capability is a
	 * no-op, which matters because provisioning runs on activation, on every
	 * site of a network, and on sites created later.
	 *
	 * @return void
	 */
	public function add(): void {
		foreach ( $this->map as $capability => $roles ) {
			foreach ( $roles as $role_name ) {
				$role = get_role( $role_name );

				if ( $role instanceof \WP_Role ) {
					$role->add_cap( $capability );
				}
			}
		}
	}

	/**
	 * Revoke every mapped capability.
	 *
	 * For uninstall, not deactivation. A deactivated plugin that stripped its
	 * capabilities would silently change what site administrators can do, and
	 * reactivating would not obviously restore it.
	 *
	 * @return void
	 */
	public function remove(): void {
		foreach ( $this->map as $capability => $roles ) {
			foreach ( $roles as $role_name ) {
				$role = get_role( $role_name );

				if ( $role instanceof \WP_Role ) {
					$role->remove_cap( $capability );
				}
			}
		}
	}

	/**
	 * Every capability this map declares.
	 *
	 * @return string[]
	 */
	public function names(): array {
		return array_keys( $this->map );
	}
}
