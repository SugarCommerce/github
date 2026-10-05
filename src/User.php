<?php
/**
 * A GitHub account.
 *
 * @package   SugarCommerce\Github
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Github;

/**
 * Class User
 *
 * Keep the id, not the login. A login can be renamed, and once renamed the
 * old one can be registered by somebody else — so access stored by login
 * follows the name to a stranger. Store `id`, and look the login up from
 * it ({@see Rest::user_by_id()}) whenever you have to name the account to
 * GitHub.
 *
 * @since 1.0.0
 */
final readonly class User {

	/**
	 * @since 1.0.0
	 *
	 * @param int    $id    GitHub's numeric id. Permanent.
	 * @param string $login The login now. Can change.
	 * @param string $type  User, Organization or Bot.
	 */
	public function __construct(
		public int $id,
		public string $login,
		public string $type = 'User',
	) {}

	/**
	 * From a GitHub user object.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $user As GitHub sent it.
	 *
	 * @return self|null Null when it names nobody: no id, or no login.
	 */
	public static function from_array( array $user ): ?self {
		$id    = is_numeric( $user['id'] ?? null ) ? (int) $user['id'] : 0;
		$login = is_string( $user['login'] ?? null ) ? $user['login'] : '';

		return $id < 1 || '' === $login ? null : new self( $id, $login, is_string( $user['type'] ?? null ) ? $user['type'] : '' );
	}

	/**
	 * As an array.
	 *
	 * @since 1.0.0
	 *
	 * @return array{id: int, login: string, type: string}
	 */
	public function to_array(): array {
		return array( 'id' => $this->id, 'login' => $this->login, 'type' => $this->type );
	}
}
