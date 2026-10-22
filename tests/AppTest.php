<?php
/**
 * A GitHub App's own token and its installations'.
 *
 * @package   SugarCommerce\Github
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 */

declare( strict_types=1 );

namespace SugarCommerce\Github\Tests;

use PHPUnit\Framework\TestCase;
use SugarCommerce\Github\App;
use SugarCommerce\HttpClient\{Fake, Response};

/**
 * Class AppTest
 */
final class AppTest extends TestCase {

	private static string $pem = '';

	private static string $public = '';

	public static function setUpBeforeClass(): void {
		$key = openssl_pkey_new( array( 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA ) );
		openssl_pkey_export( $key, $pem );

		self::$pem    = (string) $pem;
		self::$public = (string) openssl_pkey_get_details( $key )['key'];
	}

	/* ─── The JWT ───────────────────────────────────────────────────── */

	/**
	 * Signed by the App's own key, verifiable with the public half — the
	 * check GitHub makes.
	 */
	public function test_the_jwt_is_rs256_and_backdated_a_minute(): void {
		$jwt = ( new App( '12345', 'my-app', self::$pem ) )->jwt( 1_000_000 );

		[ $header, $payload, $signature ] = explode( '.', $jwt );

		$claims = json_decode( (string) base64_decode( strtr( $payload, '-_', '+/' ) ), true );

		$this->assertSame( array( 'alg' => 'RS256', 'typ' => 'JWT' ), json_decode( (string) base64_decode( strtr( $header, '-_', '+/' ) ), true ) );
		$this->assertSame( array( 'iat' => 999_940, 'exp' => 1_000_540, 'iss' => '12345' ), $claims );
		$this->assertSame( 1, openssl_verify( $header . '.' . $payload, (string) base64_decode( strtr( $signature, '-_', '+/' ) ), self::$public, OPENSSL_ALGO_SHA256 ) );
	}

	public function test_a_key_that_cannot_sign_makes_no_jwt(): void {
		$this->assertSame( '', ( new App( '1', 'x', 'not a key' ) )->jwt() );
		$this->assertSame( '', ( new App() )->jwt() );
	}

	/* ─── Installations ─────────────────────────────────────────────── */

	public function test_an_installation_token_is_asked_for_with_the_jwt(): void {
		$http = ( new Fake() )->on( 'POST', 'https://api.github.com/app/installations/42/access_tokens', new Response( true, 201, '{"token":"ghs_abc"}' ) );
		$app  = new App( '12345', 'my-app', self::$pem, $http );

		$this->assertTrue( $app->configured() );
		$this->assertSame( 'ghs_abc', $app->installation_token( 42 ) );
		$this->assertStringStartsWith( 'Bearer ey', $http->sent( 'POST' )[0]['headers']['Authorization'] );
	}

	public function test_an_installation_is_checked_against_the_app(): void {
		$http = ( new Fake() )
			->on( 'GET', '*/app/installations/42', new Response( true, 200, '{"id":42,"account":{"login":"midnight"}}' ) )
			->on( 'GET', '*/app/installations/43', new Response( false, 404 ) );
		$app  = new App( '12345', 'my-app', self::$pem, $http );

		$this->assertSame( 'midnight', $app->installation( 42 ) );
		$this->assertNull( $app->installation( 43 ) );
		$this->assertNull( $app->installation( 0 ) );
	}

	public function test_without_an_app_nothing_is_asked(): void {
		$app = new App( http: Fake::strict() );

		$this->assertFalse( $app->configured() );
		$this->assertSame( '', $app->installation_token( 1 ) );
		$this->assertNull( $app->installation( 1 ) );
	}

	public function test_a_dump_does_not_show_the_key(): void {
		$dump = print_r( new App( '12345', 'my-app', self::$pem ), true );

		$this->assertStringNotContainsString( 'PRIVATE KEY', $dump );
		$this->assertStringContainsString( '12345', $dump );
	}

	public function test_a_token_that_is_not_text_is_none(): void {
		$http = ( new Fake() )->queue( new Response( true, 201, '{"token":["x"]}' ), new Response( true, 200, '{"account":"x"}' ) );
		$app  = new App( '12345', 'my-app', self::$pem, $http );

		$this->assertSame( '', $app->installation_token( 9 ) );
		$this->assertSame( '', $app->installation( 9 ) );
	}

	public function test_the_install_link_carries_the_state(): void {
		$url = ( new App( '1', 'my app', self::$pem ) )->install_url( 'st&1' );

		$this->assertSame( 'https://github.com/apps/my%20app/installations/new?state=st%261', $url );
	}
}
