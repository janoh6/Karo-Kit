<?php
/**
 * A flat collection of every Option the kit declares, and every
 * cross-cutting view derived from it -- what used to be four separate,
 * hand-maintained lists (register_setting() calls, export_map(),
 * export_labels(), uninstall.php's array).
 *
 * Deliberately flat, not partitioned by module: uninstall, export and the
 * AJAX allowlist all need the merged set across every module, so nothing is
 * gained by Registry knowing which module an option came from. Where
 * register_setting() needs a per-module settings group, that scoping is a
 * caller-side loop (see Karo_Kit::boot()), not a Registry responsibility --
 * this is what wpType()/sanitizerFor() being public static is for.
 *
 * @package Karo_Kit
 */

namespace KaroKit\Core\Options;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Registry {

	/** @var array<string,Option> */
	private array $options = array();

	public function add( Option $option ): void {
		$this->options[ $option->name ] = $option;
	}

	public function get( string $name ): ?Option {
		return $this->options[ $name ] ?? null;
	}

	public function has( string $name ): bool {
		return isset( $this->options[ $name ] );
	}

	/** @return Option[] */
	public function all(): array {
		return array_values( $this->options );
	}

	/** @return string[] Names of options registered with WordPress and AJAX-writable. */
	public function settingNames(): array {
		return $this->namesWhere( static fn( Option $o ) => $o->setting );
	}

	/** @return string[] Names deleted on uninstall. */
	public function uninstallNames(): array {
		return $this->namesWhere( static fn( Option $o ) => $o->uninstall );
	}

	/** @return array<string,string> option name => 'value'|'page', for exported options. */
	public function exportMap(): array {
		$map = array();
		foreach ( $this->options as $option ) {
			if ( $option->export ) {
				$map[ $option->name ] = 'page' === $option->type ? 'page' : 'value';
			}
		}
		return $map;
	}

	/** @return array<string,string> option name => label, for exported options that have one. */
	public function exportLabels(): array {
		$labels = array();
		foreach ( $this->options as $option ) {
			if ( $option->export && null !== $option->label ) {
				$labels[ $option->name ] = $option->label;
			}
		}
		return $labels;
	}

	/**
	 * Seed every declared option with its default, once. add_option() is a
	 * genuine no-op when the row already exists, so a site's own deliberate
	 * choice is never overwritten -- this is safe to call on every
	 * admin_init as well as on activation.
	 */
	public function seedDefaults(): void {
		foreach ( $this->options as $option ) {
			$value = $option->defaultCallback ? ( $option->defaultCallback )() : $option->default;
			add_option( $option->name, $value, '', $option->autoload ? 'yes' : 'no' );
		}
	}

	/** @return string[] */
	private function namesWhere( callable $predicate ): array {
		$names = array();
		foreach ( $this->options as $option ) {
			if ( $predicate( $option ) ) {
				$names[] = $option->name;
			}
		}
		return $names;
	}

	/** WordPress's register_setting() 'type' string for one of our declared types. */
	public static function wpType( string $type ): string {
		return match ( $type ) {
			'bool'  => 'boolean',
			'int', 'page' => 'integer',
			'array' => 'array',
			default => 'string', // string, key, enum, hex
		};
	}

	/** The sanitize_callback for one Option, matching its declared $type exactly. */
	public static function sanitizerFor( Option $option ): callable {
		return match ( $option->type ) {
			'bool'  => static fn( $v ) => $v ? 1 : 0,
			'int'   => static function ( $v ) use ( $option ) {
				$n = absint( $v );
				if ( null !== $option->min ) {
					$n = max( $option->min, $n );
				}
				if ( null !== $option->max ) {
					$n = min( $option->max, $n );
				}
				return $n;
			},
			'page'  => 'absint',
			'key'   => 'sanitize_key',
			'slug'  => array( \Karo_Kit_Gate_Settings::class, 'sanitize_slug' ),
			'enum'  => static function ( $v ) use ( $option ) {
				$v = (string) $v;
				return in_array( $v, $option->enum ?? array(), true ) ? $v : $option->default;
			},
			'hex'   => array( \Karo_Kit_Accent::class, 'normalise_hex' ),
			'array' => static fn( $v ) => $v,
			default => 'sanitize_text_field', // string
		};
	}
}
