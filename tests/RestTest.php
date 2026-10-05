<?php
/**
 * The REST calls behind repository access.
 *
 * @package   SugarCommerce\Github
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 */

declare( strict_types=1 );

namespace SugarCommerce\Github\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCommerce\Github\{Invitation, Permission, Rest, User};
use SugarCommerce\HttpClient\{Fake, Response};

/**
 * Class RestTest
 *
 * Against a double: nothing here reaches api.github.com.
 */
final class RestTest extends TestCase {

	/* ─── Invitations ───────────────────────────────────────────────── */

	public function test_an_invitation_reports_its_id_or_that_they_were_already_in(): void {
		$http = ( new Fake() )->queue( new Response( true, 201, '{"id":77}' ), new Response( true, 204 ) );
		$rest = new Rest( $http, 'my-shop' );

		$sent = $rest->invite( 'tok', 'midnight/stems', 'octocat', Permission::Triage );
		$in   = $rest->invite( 'tok', 'midnight/stems', 'octocat' );

		$this->assertEquals( new Invitation( true, 77, 201 ), $sent );
		$this->assertFalse( $sent->already_in() );
		$this->assertTrue( $in->already_in() );

		$put = $http->sent( 'PUT' );

		$this->assertSame( 'https://api.github.com/repos/midnight/stems/collaborators/octocat', $put[0]['url'] );
		$this->assertSame( '{"permission":"triage"}', $put[0]['body'] );
		$this->assertSame( '{"permission":"pull"}', $put[1]['body'], 'Pull unless told otherwise.' );
		$this->assertSame( 'Bearer tok', $put[0]['headers']['Authorization'] );
		$this->assertSame( 'my-shop', $put[0]['headers']['User-Agent'] );
		$this->assertSame( '2022-11-28', $put[0]['headers']['X-GitHub-Api-Version'] );
	}

	public function test_a_refused_invitation_carries_githubs_status_and_message(): void {
		$http   = ( new Fake() )->queue( new Response( false, 422, '{"message":"Validation Failed"}' ) );
		$result = ( new Rest( $http ) )->invite( 'tok', 'midnight/stems', 'octocat' );

		$this->assertFalse( $result->ok );
		$this->assertFalse( $result->already_in() );
		$this->assertSame( 422, $result->status );
		$this->assertSame( 'Validation Failed', $result->message );
	}

	/* ─── Taking access away ────────────────────────────────────────── */

	/**
	 * An invitation never accepted is cancelled; a collaborator who left is
	 * already gone. Both are "removed".
	 */
	public function test_revoking_cancels_the_invitation_and_the_collaborator(): void {
		$http = ( new Fake() )->queue( new Response( true, 204 ), new Response( false, 404 ) );

		$this->assertTrue( ( new Rest( $http ) )->revoke_access( 'tok', 'midnight/stems', 'octocat', 77 ) );
		$this->assertSame( 'https://api.github.com/repos/midnight/stems/invitations/77', $http->sent( 'DELETE' )[0]['url'] );
		$this->assertSame( 'https://api.github.com/repos/midnight/stems/collaborators/octocat', $http->sent( 'DELETE' )[1]['url'] );
	}

	public function test_a_failed_removal_says_so(): void {
		$this->assertFalse( ( new Rest( ( new Fake() )->queue( new Response( false, 500 ) ) ) )->revoke_access( 'tok', 'midnight/stems', 'octocat', null ) );
		$this->assertFalse( ( new Rest( ( new Fake() )->queue( new Response( false, 500 ) ) ) )->revoke_access( 'tok', 'midnight/stems', 'octocat', 77 ), 'An invitation that may still be open stops it.' );
	}

	/**
	 * Somebody whose account is gone: only the invitation is cancelled, so
	 * whoever has taken the old name since is left alone.
	 */
	public function test_revoking_a_deleted_account_only_cancels_the_invitation(): void {
		$http = ( new Fake() )->queue( new Response( true, 204 ) );

		$this->assertTrue( ( new Rest( $http ) )->revoke_access( 'tok', 'midnight/stems', '', 77 ) );
		$this->assertCount( 1, $http->sent( 'DELETE' ) );
	}

	public function test_a_collaborator_is_one_github_answers_204_for(): void {
		$http = ( new Fake() )->queue( new Response( true, 204 ), new Response( false, 404 ) );
		$rest = new Rest( $http );

		$this->assertTrue( $rest->is_collaborator( 'tok', 'midnight/stems', 'octocat' ) );
		$this->assertFalse( $rest->is_collaborator( 'tok', 'midnight/stems', 'octocat' ) );
		$this->assertSame( 'https://api.github.com/repos/midnight/stems/collaborators/octocat', $http->sent( 'GET' )[0]['url'] );
	}

	/* ─── People ────────────────────────────────────────────────────── */

	public function test_a_user_by_id_says_whether_the_account_is_gone_or_github_was_unreachable(): void {
		$http = ( new Fake() )->queue(
			new Response( true, 200, '{"id":583231,"login":"octocat","type":"User"}' ),
			new Response( false, 404 ),
			new Response( false, 502 )
		);
		$rest = new Rest( $http );

		$this->assertEquals( new User( 583231, 'octocat', 'User' ), $rest->user_by_id( 'tok', 583231 ) );
		$this->assertSame( 'https://api.github.com/user/583231', $http->sent( 'GET' )[0]['url'] );
		$this->assertFalse( $rest->user_by_id( 'tok', 583231 ) );
		$this->assertNull( $rest->user_by_id( 'tok', 583231 ) );
	}

	public function test_me_and_a_login_read_the_same_shape(): void {
		$http = ( new Fake( new Response( false, 401 ) ) )
			->on( 'GET', 'https://api.github.com/user', new Response( true, 200, '{"id":1,"login":"octocat","type":"User"}' ) )
			->on( 'GET', 'https://api.github.com/users/hubot', new Response( true, 200, '{"id":2,"login":"hubot","type":"Bot"}' ) );
		$rest = new Rest( $http );

		$this->assertSame( array( 'id' => 1, 'login' => 'octocat', 'type' => 'User' ), $rest->me( 'tok' )?->to_array() );
		$this->assertSame( 'Bot', $rest->user( 'tok', 'hubot' )?->type );
		$this->assertNull( $rest->user( 'tok', '' ) );
		$this->assertNull( ( new Rest( ( new Fake() )->queue( new Response( false, 401 ) ) ) )->me( 'bad' ) );
	}

	public function test_a_user_with_no_id_or_login_is_nobody(): void {
		$this->assertNull( User::from_array( array( 'login' => 'x' ) ) );
		$this->assertNull( User::from_array( array( 'id' => 5 ) ) );
		$this->assertNull( User::from_array( array( 'id' => 'abc', 'login' => 'x' ) ) );
	}

	public function test_installations_a_user_can_reach_are_read_by_id(): void {
		$http = ( new Fake() )->on( 'GET', '*/user/installations*', new Response( true, 200, '{"installations":[{"id":42},{"id":"7"},{"nope":1},"junk"]}' ) );

		$this->assertSame( array( 42, 7 ), ( new Rest( $http ) )->user_installations( 'ghu_x' ) );
		$this->assertSame( array(), ( new Rest( ( new Fake() )->queue( new Response( false, 401 ) ) ) )->user_installations( 'ghu_x' ) );
	}

	/* ─── Shapes ────────────────────────────────────────────────────── */

	/**
	 * @return array<string, array{string, bool}>
	 */
	public static function repos(): array {
		return array(
			'owner and name'    => array( 'midnight-records/stems.v2', true ),
			'no owner'          => array( 'stems', false ),
			'a traversal'       => array( '../etc/passwd', false ),
			'a dot name'        => array( 'midnight/..', false ),
			'a leading hyphen'  => array( '-midnight/stems', false ),
			'a third segment'   => array( 'a/b/c', false ),
			'an owner too long' => array( str_repeat( 'a', 40 ) . '/x', false ),
		);
	}

	#[DataProvider( 'repos' )]
	public function test_repositories_have_the_shape_github_allows( string $repo, bool $valid ): void {
		$this->assertSame( $valid, Rest::valid_repo( $repo ) );
	}

	public function test_a_token_is_recognised_by_its_prefix(): void {
		$this->assertTrue( Rest::looks_like_token( 'github_pat_' . str_repeat( 'A', 40 ) ) );
		$this->assertTrue( Rest::looks_like_token( 'ghp_' . str_repeat( 'a', 36 ) ) );
		$this->assertFalse( Rest::looks_like_token( 'ghs_' . str_repeat( 'a', 36 ) ), 'An installation token is not one to paste.' );
		$this->assertFalse( Rest::looks_like_token( 'github_pat_short' ) );
	}

	public function test_repository_segments_are_encoded(): void {
		$http = ( new Fake() )->queue( new Response( true, 204 ) );

		( new Rest( $http ) )->is_collaborator( 'tok', 'midnight/stems', 'a b' );

		$this->assertSame( 'https://api.github.com/repos/midnight/stems/collaborators/a%20b', $http->sent()[0]['url'] );
	}

	/* ─── Permissions ───────────────────────────────────────────────── */

	public function test_only_pull_and_triage_leave_the_code_alone(): void {
		$this->assertFalse( Permission::Pull->writes_code() );
		$this->assertFalse( Permission::Triage->writes_code() );
		$this->assertTrue( Permission::Push->writes_code() );
		$this->assertTrue( Permission::Admin->writes_code() );
	}

	public function test_a_name_from_metadata_is_read_only_unless_writing_is_allowed(): void {
		$this->assertSame( Permission::Triage, Permission::read_only( ' Triage ' ) );
		$this->assertSame( Permission::Pull, Permission::read_only( 'push' ), 'Nothing that writes code is given by accident.' );
		$this->assertSame( Permission::Pull, Permission::read_only( 'owner' ) );
		$this->assertSame( Permission::Push, Permission::read_only( 'push', allow_write: true ) );
	}
}
