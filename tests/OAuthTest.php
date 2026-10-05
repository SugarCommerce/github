<?php
/**
 * Signing a user in with a GitHub App.
 *
 * @package   SugarCommerce\Github
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 */

declare( strict_types=1 );

namespace SugarCommerce\Github\Tests;

use PHPUnit\Framework\TestCase;
use SugarCommerce\Github\{OAuth, Rest, User};
use SugarCommerce\HttpClient\{Fake, Response};

/**
 * Class OAuthTest
 */
final class OAuthTest extends TestCase {

	/* ─── The authorize URL ─────────────────────────────────────────── */

	public function test_the_authorize_url_asks_for_no_scope_and_uses_pkce(): void {
		parse_str( (string) parse_url( ( new OAuth( 'Iv1.abc', 'shh' ) )->authorize_url( 'https://x/github/callback', 'st', 'ch' ), PHP_URL_QUERY ), $query );

		$this->assertSame( array( 'client_id' => 'Iv1.abc', 'redirect_uri' => 'https://x/github/callback', 'state' => 'st', 'code_challenge' => 'ch', 'code_challenge_method' => 'S256', 'prompt' => 'select_account' ), $query );
	}

	public function test_the_account_picker_can_be_left_out(): void {
		$this->assertStringNotContainsString( 'prompt', ( new OAuth( 'a', 'b' ) )->authorize_url( 'https://x', 'st', 'ch', '' ) );
	}

	/* ─── Identifying ───────────────────────────────────────────────── */

	/**
	 * The code and verifier for a token, the token for an identity, then
	 * the token revoked with the App's own credentials.
	 */
	public function test_identifying_uses_the_token_once_and_revokes_it(): void {
		$http = ( new Fake( new Response( false, 404 ) ) )
			->on( 'POST', 'https://github.com/login/oauth/access_token', new Response( true, 200, '{"access_token":"ghu_abc","token_type":"bearer"}' ) )
			->on( 'GET', 'https://api.github.com/user', new Response( true, 200, '{"id":583231,"login":"octocat","type":"User"}' ) )
			->on( 'DELETE', 'https://api.github.com/applications/Iv1.abc/token', new Response( true, 204 ) );

		$oauth = new OAuth( 'Iv1.abc', 'shh', $http, 'my-shop' );

		$this->assertTrue( $oauth->configured() );
		$this->assertEquals( new User( 583231, 'octocat', 'User' ), $oauth->identify( 'code1', 'https://get.example/github/callback', 'verifier' ) );

		parse_str( (string) $http->sent( 'POST' )[0]['body'], $exchanged );

		$this->assertSame( array( 'client_id' => 'Iv1.abc', 'client_secret' => 'shh', 'code' => 'code1', 'redirect_uri' => 'https://get.example/github/callback', 'code_verifier' => 'verifier' ), $exchanged );
		$this->assertSame( 'application/json', $http->sent( 'POST' )[0]['headers']['Accept'] );
		$this->assertSame( 'my-shop', $http->sent( 'POST' )[0]['headers']['User-Agent'] );
		$this->assertSame( 'Bearer ghu_abc', $http->sent( 'GET' )[0]['headers']['Authorization'] );
		$this->assertSame( '{"access_token":"ghu_abc"}', $http->sent( 'DELETE' )[0]['body'] );
		$this->assertSame( 'Basic ' . base64_encode( 'Iv1.abc:shh' ), $http->sent( 'DELETE' )[0]['headers']['Authorization'] );
	}

	/**
	 * GitHub answers a bad code with 200 and an `error`.
	 */
	public function test_a_code_github_refuses_identifies_nobody(): void {
		$http = ( new Fake() )->queue( new Response( true, 200, '{"error":"bad_verification_code"}' ) );

		$this->assertNull( ( new OAuth( 'a', 'b', $http ) )->identify( 'stale', 'https://x/github/callback', 'v' ) );
		$this->assertCount( 1, $http->sent(), 'Nothing more is asked.' );
		$this->assertFalse( ( new OAuth() )->configured() );
		$this->assertSame( '', ( new OAuth( 'a', 'b', Fake::strict() ) )->exchange( '' ) );
	}

	/* ─── Installs ──────────────────────────────────────────────────── */

	/**
	 * An install's code has no redirect or verifier: GitHub started it.
	 */
	public function test_the_installations_behind_an_install_code(): void {
		$http = ( new Fake( new Response( false, 404 ) ) )
			->on( 'POST', '*/access_token', new Response( true, 200, '{"access_token":"ghu_install"}' ) )
			->on( 'GET', '*/user/installations*', new Response( true, 200, '{"installations":[{"id":42}]}' ) )
			->on( 'DELETE', '*/token', new Response( true, 204 ) );

		$this->assertSame( array( 42 ), ( new OAuth( 'Iv1.abc', 'shh', $http ) )->installations( 'c0de' ) );

		parse_str( (string) $http->sent( 'POST' )[0]['body'], $exchanged );

		$this->assertSame( array( 'client_id' => 'Iv1.abc', 'client_secret' => 'shh', 'code' => 'c0de' ), $exchanged );
		$this->assertCount( 1, $http->sent( 'DELETE' ), 'The token is let go.' );
	}

	public function test_no_code_or_no_credentials_reaches_nothing(): void {
		$this->assertSame( array(), ( new OAuth( 'a', 'b', Fake::strict() ) )->installations( '' ) );
		$this->assertSame( array(), ( new OAuth( http: Fake::strict() ) )->installations( 'c0de' ) );
	}

	/* ─── Results that must be checked ──────────────────────────────── */

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function must_check(): array {
		return array(
			'OAuth::identify'         => array( OAuth::class, 'identify' ),
			'Rest::invite'            => array( Rest::class, 'invite' ),
			'Rest::cancel_invitation' => array( Rest::class, 'cancel_invitation' ),
			'Rest::remove'            => array( Rest::class, 'remove_collaborator' ),
			'Rest::revoke_access'     => array( Rest::class, 'revoke_access' ),
		);
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'must_check' )]
	public function test_the_result_must_be_used( string $class, string $method ): void {
		$attributes = ( new \ReflectionMethod( $class, $method ) )->getAttributes( \NoDiscard::class );

		$this->assertCount( 1, $attributes, $method . ' lost #[\NoDiscard].' );
		$this->assertNotSame( '', $attributes[0]->newInstance()->message );
	}
}
