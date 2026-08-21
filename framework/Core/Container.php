<?php
/**
 * Minimal service container with lazy singletons.
 *
 * Extracted from wpheka-seo-os src/Core/Container.php, which has run in
 * production since 2026. Kept deliberately small: WordPress plugins need lazy
 * construction and a single place to wire dependencies, not autowiring.
 *
 * @package WPHEKA\Framework
 */

declare( strict_types=1 );

namespace WPHEKA\Framework\V1\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Minimal service container with lazy singletons.
 *
 * Nothing is constructed until it is resolved. Shared hosting is a target
 * environment (PRODUCT_REQUIREMENTS.md), so eager construction is a real cost
 * paid on every request, including requests that never touch the service.
 */
final class Container {

	/**
	 * Registered factories, keyed by service id.
	 *
	 * @var array<string, callable>
	 */
	private array $factories = array();

	/**
	 * Resolved instances, keyed by service id.
	 *
	 * @var array<string, object>
	 */
	private array $instances = array();

	/**
	 * Register a lazy singleton factory.
	 *
	 * @param string   $id      Service id, conventionally the class name.
	 * @param callable $factory Receives this container, returns the service.
	 * @return void
	 */
	public function singleton( string $id, callable $factory ): void {
		$this->factories[ $id ] = $factory;
	}

	/**
	 * Register an already-constructed instance.
	 *
	 * @param string $id     Service id.
	 * @param object $service The instance.
	 * @return void
	 */
	public function instance( string $id, object $service ): void {
		$this->instances[ $id ] = $service;
	}

	/**
	 * Resolve a service.
	 *
	 * Falls back to `new $id( $this )` when no factory is registered but the class
	 * can actually be built that way. Services needing real dependencies register
	 * a factory.
	 *
	 * There is still **no autowiring**: nothing here resolves a constructor's
	 * dependencies. The one reflection call asks a narrower question — "would
	 * `new $id( $this )` work?" — and it is answered once per class and cached,
	 * rather than per resolution. `class_exists()` alone answered a different
	 * question than the one being asked, and got it wrong in both directions: an
	 * abstract class or a constructor expecting something else raised a
	 * `TypeError` or `ArgumentCountError` from inside the container instead of the
	 * documented `RuntimeException`, and `has()` reported every class on the site
	 * as resolvable — so a caller's `has() ? get() : fallback` took the wrong
	 * branch and then fataled.
	 *
	 * @param string $id Service id.
	 * @return object
	 * @throws \RuntimeException When the id is not registered and cannot be constructed.
	 */
	public function get( string $id ): object {
		if ( isset( $this->instances[ $id ] ) ) {
			return $this->instances[ $id ];
		}

		if ( isset( $this->factories[ $id ] ) ) {
			$this->instances[ $id ] = ( $this->factories[ $id ] )( $this );

			return $this->instances[ $id ];
		}

		if ( self::is_constructible( $id ) ) {
			$this->instances[ $id ] = new $id( $this );

			return $this->instances[ $id ];
		}

		throw new \RuntimeException(
			esc_html( sprintf( 'WPHEKA Framework container: unknown service "%s".', $id ) )
		);
	}

	/**
	 * Whether a service id can be resolved.
	 *
	 * @param string $id Service id.
	 * @return bool
	 */
	public function has( string $id ): bool {
		return isset( $this->instances[ $id ] )
			|| isset( $this->factories[ $id ] )
			|| self::is_constructible( $id );
	}

	/**
	 * Whether `new $id( $this )` would actually produce an object.
	 *
	 * Answered once per class name for the life of the request, because the
	 * answer cannot change within one and `has()` is cheap to call in a loop.
	 *
	 * @param string $id Candidate class name.
	 * @return bool
	 */
	private static function is_constructible( string $id ): bool {
		static $cache = array();

		if ( isset( $cache[ $id ] ) ) {
			return $cache[ $id ];
		}

		$cache[ $id ] = self::inspect( $id );

		return $cache[ $id ];
	}

	/**
	 * The uncached half of `is_constructible()`.
	 *
	 * @param string $id Candidate class name.
	 * @return bool
	 */
	private static function inspect( string $id ): bool {
		// Also the autoload trigger: once this is true, ReflectionClass cannot
		// fail to find the class, so there is nothing here to catch.
		if ( ! class_exists( $id ) ) {
			return false;
		}

		$class = new \ReflectionClass( $id );

		// Abstract classes, and classes with a non-public constructor.
		if ( ! $class->isInstantiable() ) {
			return false;
		}

		$constructor = $class->getConstructor();

		if ( null === $constructor ) {
			return true;
		}

		$parameters = $constructor->getParameters();

		// `__construct()` taking nothing is fine: a userland function ignores
		// surplus arguments rather than erroring on them.
		if ( array() === $parameters ) {
			return true;
		}

		// More than one required parameter can never be met by a single argument,
		// whatever its type.
		if ( $constructor->getNumberOfRequiredParameters() > 1 ) {
			return false;
		}

		return self::accepts_container( $parameters[0] );
	}

	/**
	 * Whether a constructor's first parameter would accept this container.
	 *
	 * Note that "has a default" is *not* sufficient — `__construct( ?Logger $l =
	 * null )` requires nothing and still rejects a Container.
	 *
	 * @param \ReflectionParameter $parameter First constructor parameter.
	 * @return bool
	 */
	private static function accepts_container( \ReflectionParameter $parameter ): bool {
		$type = $parameter->getType();

		// Untyped accepts anything, which includes this.
		if ( null === $type ) {
			return true;
		}

		$candidates = $type instanceof \ReflectionUnionType ? $type->getTypes() : array( $type );

		foreach ( $candidates as $candidate ) {
			if ( $candidate instanceof \ReflectionNamedType && self::class === ltrim( $candidate->getName(), '\\' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Forget a resolved instance, keeping its factory.
	 *
	 * Needed on multisite: a service caching site-specific state must be
	 * discarded across `switch_to_blog()`, since the container itself is
	 * per-request and does not re-resolve on a blog switch (ADR-012).
	 *
	 * @param string $id Service id.
	 * @return void
	 */
	public function forget( string $id ): void {
		unset( $this->instances[ $id ] );
	}
}
