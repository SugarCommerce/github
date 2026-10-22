<?php
/**
 * A GitHub App: its own token, and its installations'.
 *
 * @package   SugarCommerce\Github
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Github;

use SugarCommerce\HttpClient\Client as Http;
use SugarCommerce\SignedTokens\Base64Url;

/**
 * Class App
 *
 * An App is installed by the owner of some repositories, on GitHub's own
 * screen, and uninstalling is them taking it back — which is why it beats
 * asking for a token pasted into a form. The App proves itself with a JWT
 * signed by its private key, and trades that for an installation token
 * that acts on the repositories it was given, for an hour.
 *
 * Mistakes this keeps out:
 *
 * - **A clock that runs fast.** The JWT is backdated a minute and lives
 *   nine more, inside GitHub's ten-minute ceiling, as GitHub advises.
 * - **Storing installation tokens.** They are cheap to mint, so mint one
 *   per run; one that is never written down cannot leak from a backup.
 * - **Trusting a setup redirect's `installation_id`.** It is a number in a
 *   query string, and ids count up, so anybody can name somebody else's.
 *   Check it against the installations the installing user can reach
 *   ({@see OAuth::installations()}) before connecting it to anything.
 *
 * @since 1.0.0
 */
final readonly class App {

	/**
	 * Hold the App's identity and key.
	 *
	 * @since 1.0.0
	 *
	 * @param string $app_id      The App's id.
	 * @param string $slug        The App's URL name, for the install link.
	 * @param string $private_key The App's private key, PEM.
	 * @param Http   $http        HTTP client. Substitute `HttpClient\Fake` in tests.
	 * @param string $user_agent  Names your application to GitHub.
	 */
	public function __construct(
		private string $app_id = '',
		private string $slug = '',
		#[\SensitiveParameter] private string $private_key = '',
		private Http $http = new Http(),
		private string $user_agent = Api::USER_AGENT,
	) {}

	/**
	 * Whether the App is set up: an id, a name and a key.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function configured(): bool {
		return '' !== $this->app_id && '' !== $this->slug && '' !== $this->private_key;
	}

	/**
	 * Where somebody goes to install the App.
	 *
	 * GitHub sends them back to the App's setup (or callback) URL with the
	 * installation id, this state, and — when the App asks for user
	 * authorisation during installation — a code that says who installed it.
	 *
	 * @since 1.0.0
	 *
	 * @param string $state Signed state naming whose install this is.
	 *
	 * @return string
	 */
	public function install_url( string $state ): string {
		return 'https://github.com/apps/' . rawurlencode( $this->slug ) . '/installations/new?' . http_build_query( array( 'state' => $state ) );
	}

	/**
	 * The account an installation belongs to, checking it is one of this App's.
	 *
	 * @since 1.0.0
	 *
	 * @param int $installation Installation id.
	 *
	 * @return string|null The account's login, or null when GitHub does not
	 *                     know that installation for this App.
	 */
	public function installation( int $installation ): ?string {
		$jwt = $this->jwt();

		if ( '' === $jwt || $installation < 1 ) {
			return null;
		}

		$response = $this->http->get( Api::BASE . '/app/installations/' . $installation, Api::headers( $jwt, $this->user_agent ) );

		if ( ! $response->successful() ) {
			return null;
		}

		$json    = $response->json();
		$account = is_array( $json ) ? ( $json['account'] ?? null ) : null;
		$login   = is_array( $account ) ? ( $account['login'] ?? '' ) : '';

		return is_string( $login ) ? $login : '';
	}

	/**
	 * An installation token, good for an hour.
	 *
	 * @since 1.0.0
	 *
	 * @param int $installation Installation id.
	 *
	 * @return string The token, or '' when GitHub refused or there is no App.
	 */
	public function installation_token( int $installation ): string {
		$jwt = $this->jwt();

		if ( '' === $jwt || $installation < 1 ) {
			return '';
		}

		$response = $this->http->post( Api::BASE . '/app/installations/' . $installation . '/access_tokens', '', Api::headers( $jwt, $this->user_agent ) );

		$json  = $response->successful() ? $response->json() : null;
		$token = is_array( $json ) ? ( $json['token'] ?? '' ) : '';

		return is_string( $token ) ? $token : '';
	}

	/**
	 * The App's own token: an RS256 JWT signed with its key.
	 *
	 * Issued a minute in the past and expiring nine minutes from now, as
	 * GitHub advises for a clock that runs fast.
	 *
	 * @since 1.0.0
	 *
	 * @param int|null $now Current time. For tests.
	 *
	 * @return string The JWT, or '' when there is no App or the key will not sign.
	 */
	public function jwt( ?int $now = null ): string {
		if ( ! $this->configured() ) {
			return '';
		}

		$now     = $now ?? time();
		$header  = Base64Url::encode( (string) json_encode( array( 'alg' => 'RS256', 'typ' => 'JWT' ) ) );
		$payload = Base64Url::encode( (string) json_encode( array( 'iat' => $now - 60, 'exp' => $now + 540, 'iss' => $this->app_id ) ) );

		$signature = '';

		// openssl_sign warns on a key it cannot read; the empty answer says so.
		if ( ! @openssl_sign( $header . '.' . $payload, $signature, $this->private_key, OPENSSL_ALGO_SHA256 ) || ! is_string( $signature ) ) {
			return '';
		}

		return $header . '.' . $payload . '.' . Base64Url::encode( $signature );
	}

	/**
	 * What var_dump() and print_r() show: the App, with its key redacted.
	 *
	 * @since 1.1.0
	 *
	 * @return array<string, mixed>
	 */
	public function __debugInfo(): array {
		return array(
			'app_id'      => $this->app_id,
			'slug'        => $this->slug,
			'private_key' => '' === $this->private_key ? '' : '[redacted]',
			'user_agent'  => $this->user_agent,
		);
	}
}
