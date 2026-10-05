<?php
/**
 * What every GitHub request shares.
 *
 * @package   SugarCommerce\Github
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Github;

/**
 * Class Api
 *
 * The addresses, the pinned version and the headers, in one place so the
 * App, the sign-in and the REST calls cannot drift apart — one of them
 * forgetting the version header is a change on GitHub's side that we did
 * not choose.
 *
 * @since 1.0.0
 */
final readonly class Api {

	/**
	 * GitHub's REST API.
	 */
	public const string BASE = 'https://api.github.com';

	/**
	 * Where a user signs in, and where the code is exchanged.
	 */
	public const string LOGIN = 'https://github.com/login/oauth';

	/**
	 * The API version every request asks for, pinned so a change on
	 * GitHub's side is a change the caller chose.
	 */
	public const string VERSION = '2022-11-28';

	/**
	 * Sent when the caller names nobody. GitHub refuses requests with no
	 * User-Agent at all, and asks for one that names the application.
	 */
	public const string USER_AGENT = 'sugarcommerce-github';

	/**
	 * Headers for an authenticated REST request.
	 *
	 * @since 1.0.0
	 *
	 * @param string $credential Bearer token or App JWT.
	 * @param string $user_agent Who is asking.
	 *
	 * @return array<string, string>
	 */
	public static function headers( #[\SensitiveParameter] string $credential, string $user_agent ): array {
		return array(
			'Accept'               => 'application/vnd.github+json',
			'Authorization'        => 'Bearer ' . $credential,
			'X-GitHub-Api-Version' => self::VERSION,
			'User-Agent'           => $user_agent,
		);
	}

	/**
	 * A repository's path segment, each half encoded.
	 *
	 * @since 1.0.0
	 *
	 * @param string $repo owner/name.
	 *
	 * @return string
	 */
	public static function repo_path( string $repo ): string {
		return implode( '/', array_map( 'rawurlencode', explode( '/', $repo, 2 ) ) );
	}
}
