<?php
/**
 * A collaborator's permission on a repository.
 *
 * @package   SugarCommerce\Github
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Github;

/**
 * Enum Permission
 *
 * GitHub's five repository roles, by the names its collaborator endpoint
 * takes. The two that matter for selling access are the read-only ones:
 * somebody who bought a product and was given push could change it for
 * every other buyer. {@see self::writes_code()} says which is which, so a
 * caller can refuse the rest rather than pass on whatever a form sent.
 *
 * @since 1.0.0
 */
enum Permission: string {

	case Pull     = 'pull';
	case Triage   = 'triage';
	case Push     = 'push';
	case Maintain = 'maintain';
	case Admin    = 'admin';

	/**
	 * Whether this role can change the repository's code.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function writes_code(): bool {
		return match ( $this ) {
			self::Pull, self::Triage => false,
			default                  => true,
		};
	}

	/**
	 * A role from a name, falling back to read-only.
	 *
	 * For a name from configuration or metadata: anything unknown, or any
	 * role that writes code when $allow_write is false, becomes Pull.
	 *
	 * @since 1.0.0
	 *
	 * @param string $name        e.g. `triage`. Any case, any spaces.
	 * @param bool   $allow_write Whether push, maintain and admin may come back.
	 *
	 * @return self
	 */
	public static function read_only( string $name, bool $allow_write = false ): self {
		$role = self::tryFrom( strtolower( trim( $name ) ) ) ?? self::Pull;

		return $role->writes_code() && ! $allow_write ? self::Pull : $role;
	}
}
