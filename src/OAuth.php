<?php
/**
 * Signing a user in with a GitHub App.
 *
 * @package   SugarCommerce\Github
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Github;

use SugarCommerce\HttpClient\Client as Http;

/**
 * Class OAuth
 *
 * GitHub's web flow for a GitHub App, used for one thing: proving who a
 * person is on GitHub. The token it yields is used for that question and
 * revoked straight away, never stored — a token kept, even for its eight
 * hours, is one more thing that could act as them.
 *
 * Two GitHub habits this handles:
 *
 * - **A refused code is a 200.** The token endpoint answers a bad, used or
 *   expired code with HTTP 200 and an `error` field, so a status check
 *   says "fine". The token's absence is the test here.
 * - **The account picker.** Without `prompt=select_account`, somebody
 *   signed in to GitHub as somebody else — a work account, a shared
 *   computer — is signed in silently as that account. With it they see
 *   which account they are about to give.
 *
 * PKCE is always sent. Make the pair and bind the state to the browser
 * with `sugarcommerce/oauth`.
 *
 * @since 1.0.0
 */
final readonly class OAuth {

	/**
	 * Asks GitHub's REST API who a token is.
	 */
	private Rest $rest;

	/**
	 * @since 1.0.0
	 *
	 * @param string $client_id     The App's client id.
	 * @param string $client_secret The App's client secret.
	 * @param Http   $http          HTTP client. Substitute `HttpClient\Fake` in tests.
	 * @param string $user_agent    Names your application to GitHub.
	 */
	public function __construct(
		private string $client_id = '',
		#[\SensitiveParameter] private string $client_secret = '',
		private Http $http = new Http(),
		private string $user_agent = Api::USER_AGENT,
	) {
		$this->rest = new Rest( $http, $user_agent );
	}

	/**
	 * Whether there are credentials to sign people in with.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function configured(): bool {
		return '' !== $this->client_id && '' !== $this->client_secret;
	}

	/**
	 * Where somebody goes to sign in to GitHub.
	 *
	 * No scope: a GitHub App's user token carries the App's own
	 * permissions, and reading who signed in needs none.
	 *
	 * @since 1.0.0
	 *
	 * @param string $redirect  Your callback URL, as registered on the App.
	 * @param string $state     State, bound to this browser.
	 * @param string $challenge PKCE code challenge (S256).
	 * @param string $prompt    `select_account` (default) or '' for none.
	 *
	 * @return string
	 */
	public function authorize_url( string $redirect, string $state, string $challenge, string $prompt = 'select_account' ): string {
		return Api::LOGIN . '/authorize?' . http_build_query( array_filter( array(
			'client_id'             => $this->client_id,
			'redirect_uri'          => $redirect,
			'state'                 => $state,
			'code_challenge'        => $challenge,
			'code_challenge_method' => 'S256',
			'prompt'                => $prompt,
		), static fn ( string $value ): bool => '' !== $value ) );
	}

	/**
	 * Who signed in: swap the code for a token, ask GitHub, let it go.
	 *
	 * @since 1.0.0
	 *
	 * @param string $code     Code from the callback.
	 * @param string $redirect The same callback URL as the authorisation.
	 * @param string $verifier PKCE verifier the challenge was made from.
	 *
	 * @return User|null Null when GitHub refused the code or would not say
	 *                   who it was for.
	 */
	#[\NoDiscard( 'Null means nobody was proved.' )]
	public function identify( string $code, string $redirect, #[\SensitiveParameter] string $verifier ): ?User {
		$token = $this->exchange( $code, $redirect, $verifier );

		if ( '' === $token ) {
			return null;
		}

		$me = $this->rest->me( $token );

		$this->revoke( $token );

		return $me;
	}

	/**
	 * The installations of this App that whoever installed it can reach.
	 *
	 * A setup redirect's `installation_id` is only a number, and ids count
	 * up. The `code` beside it — sent when the App asks for user
	 * authorisation during installation — is GitHub's word on who was at
	 * the keyboard. An installation that person cannot reach is not theirs
	 * to connect.
	 *
	 * @since 1.0.0
	 *
	 * @param string $code Code from the setup redirect.
	 *
	 * @return int[] Installation ids; empty when GitHub refused the code or
	 *               could not be asked.
	 */
	public function installations( string $code ): array {
		$token = '' === $code || ! $this->configured() ? '' : $this->exchange( $code );

		if ( '' === $token ) {
			return array();
		}

		$installations = $this->rest->user_installations( $token );

		$this->revoke( $token );

		return $installations;
	}

	/**
	 * Swap a callback's code for a user token.
	 *
	 * The redirect and verifier belong to a sign-in you started; a code
	 * from the install screen comes from a flow GitHub started, with neither.
	 *
	 * @since 1.0.0
	 *
	 * @param string $code     Code from the callback.
	 * @param string $redirect Your callback URL, or '' for an install's code.
	 * @param string $verifier PKCE verifier, or '' for an install's code.
	 *
	 * @return string The token, or '' when GitHub refused.
	 */
	public function exchange( string $code, string $redirect = '', #[\SensitiveParameter] string $verifier = '' ): string {
		if ( '' === $code ) {
			return '';
		}

		$response = $this->http->post(
			Api::LOGIN . '/access_token',
			http_build_query( array_filter( array(
				'client_id'     => $this->client_id,
				'client_secret' => $this->client_secret,
				'code'          => $code,
				'redirect_uri'  => $redirect,
				'code_verifier' => $verifier,
			), static fn ( string $value ): bool => '' !== $value ) ),
			array( 'Accept' => 'application/json', 'Content-Type' => 'application/x-www-form-urlencoded', 'User-Agent' => $this->user_agent )
		);

		// 200 with an `error` for a bad code: the token's absence is the test.
		return $response->successful() ? (string) ( $response->json()['access_token'] ?? '' ) : '';
	}

	/**
	 * Revoke a user token. Best effort: it expires on its own.
	 *
	 * @since 1.0.0
	 *
	 * @param string $token The token.
	 *
	 * @return bool Whether GitHub confirmed it.
	 */
	public function revoke( #[\SensitiveParameter] string $token ): bool {
		return $this->http->request(
			'DELETE',
			Api::BASE . '/applications/' . rawurlencode( $this->client_id ) . '/token',
			(string) json_encode( array( 'access_token' => $token ) ),
			array(
				'Accept'               => 'application/vnd.github+json',
				'Authorization'        => 'Basic ' . base64_encode( $this->client_id . ':' . $this->client_secret ),
				'Content-Type'         => 'application/json',
				'X-GitHub-Api-Version' => Api::VERSION,
				'User-Agent'           => $this->user_agent,
			)
		)->successful();
	}
}
