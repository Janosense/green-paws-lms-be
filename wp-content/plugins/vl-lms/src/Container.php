<?php

declare(strict_types=1);

namespace VL\LMS;

use InvalidArgumentException;
use RuntimeException;

/**
 * Minimal lazy service locator.
 *
 * Factories receive the container itself and are invoked at most once per
 * id — the resolved instance is cached for subsequent calls.
 */
final class Container {

	/** @var array<string, callable|object> */
	private array $factories = [];

	/** @var array<string, object> */
	private array $resolved = [];

	/**
	 * Register a service.
	 *
	 * @param string          $id      Identifier — typically a fully-qualified class name.
	 * @param callable|object $service A factory `fn(Container $c): object`, or a pre-built instance.
	 */
	public function set( string $id, callable|object $service ): void {
		if ( is_callable( $service ) ) {
			$this->factories[ $id ] = $service;
			unset( $this->resolved[ $id ] );
			return;
		}

		$this->resolved[ $id ] = $service;
		unset( $this->factories[ $id ] );
	}

	public function has( string $id ): bool {
		return isset( $this->resolved[ $id ] ) || isset( $this->factories[ $id ] );
	}

	/**
	 * Resolve a service. Factories run the first time and the result is memoized.
	 */
	public function get( string $id ): object {
		if ( isset( $this->resolved[ $id ] ) ) {
			return $this->resolved[ $id ];
		}

		if ( ! isset( $this->factories[ $id ] ) ) {
			throw new InvalidArgumentException( esc_html( sprintf( 'Service "%s" is not registered.', $id ) ) );
		}

		$instance = ( $this->factories[ $id ] )( $this );

		if ( ! is_object( $instance ) ) {
			throw new RuntimeException( esc_html( sprintf( 'Factory for "%s" did not return an object.', $id ) ) );
		}

		$this->resolved[ $id ] = $instance;
		return $instance;
	}
}
