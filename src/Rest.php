<?php
/**
 * The REST calls behind repository access.
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
 * Class Rest
 *
 * Who a token is, who an id is, and collaborators: invite, check, remove.
 * Every call takes the credential to act with — an installation token from
 * {@see App}, or a user's own fine-grained token — because which one to
 * use is the caller's decision, not this class's.
 *
 * Three answers are kept apart that are easy to fold together:
 *
 * - **A deleted account is not an outage.** {@see self::user_by_id()}
 *   answers false for "GitHub has no such account" and null for "GitHub
 *   could not be asked", so a caller removing access does not guess.
 * - **"Not a collaborator" is success when removing.** A 404 on removal
 *   means they never accepted or already left; either way they do not
 *   have access, which is what was asked.
 * - **An open invitation is not a collaborator.** Removing a collaborator
 *   leaves their pending invitation live; {@see self::revoke_access()}
 *   cancels the invitation too.
 *
 * @since 1.0.0
 */
final readonly class Rest {

	/**
	 * Make the REST calls through one HTTP client, under one name.
	 *
	 * @since 1.0.0
	 *
	 * @param Http   $http       HTTP client. Substitute `HttpClient\Fake` in tests.
	 * @param string $user_agent Names your application to GitHub.
	 */
	public function __construct(
		private Http $http = new Http(),
		private string $user_agent = Api::USER_AGENT,
	) {}

	/* ─── People ────────────────────────────────────────────────────── */

	/**
	 * Who a token acts as.
	 *
	 * @since 1.0.0
	 *
	 * @param string $credential A token.
	 *
	 * @return User|null Null when GitHub does not accept it.
	 */
	public function me( #[\SensitiveParameter] string $credential ): ?User {
		return $this->person( Api::BASE . '/user', $credential );
	}

	/**
	 * A user by their current login.
	 *
	 * @since 1.0.0
	 *
	 * @param string $credential Token to ask with.
	 * @param string $login      Login.
	 *
	 * @return User|null Null when there is no such login, or GitHub could not be asked.
	 */
	public function user( #[\SensitiveParameter] string $credential, string $login ): ?User {
		$segment = self::segment( $login );

		return '' === $segment ? null : $this->person( Api::BASE . '/users/' . $segment, $credential );
	}

	/**
	 * A user by their numeric id — the one to keep, since a login can change.
	 *
	 * @since 1.0.0
	 *
	 * @param string $credential Token to ask with.
	 * @param int    $id         GitHub user id.
	 *
	 * @return User|false|null False when GitHub has no such account (deleted,
	 *                         so there is nobody to name) — a 404, or an
	 *                         answer that names nobody; null when it could
	 *                         not be asked, and the caller should not guess.
	 */
	public function user_by_id( #[\SensitiveParameter] string $credential, int $id ): User|false|null {
		$response = $this->http->get( Api::BASE . '/user/' . $id, Api::headers( $credential, $this->user_agent ) );

		if ( 404 === $response->status ) {
			return false;
		}

		return $response->successful() ? ( User::from_array( self::object( $response->json() ) ) ?? false ) : null;
	}

	/**
	 * The installations of an App that a user's token can reach.
	 *
	 * @since 1.0.0
	 *
	 * @param string $token A user-to-server token for the App.
	 *
	 * @return int[] Installation ids; empty when GitHub refused or could not
	 *               be asked.
	 */
	public function user_installations( #[\SensitiveParameter] string $token ): array {
		$response = $this->http->get( Api::BASE . '/user/installations?per_page=100', Api::headers( $token, $this->user_agent ) );

		if ( ! $response->successful() ) {
			return array();
		}

		$rows = self::object( $response->json() )['installations'] ?? array();

		return array_values( array_filter( array_map(
			static fn ( mixed $row ): int => is_array( $row ) && is_numeric( $row['id'] ?? null ) ? (int) $row['id'] : 0,
			is_array( $rows ) ? $rows : array()
		) ) );
	}

	/* ─── Collaborators ─────────────────────────────────────────────── */

	/**
	 * Whether somebody is a collaborator: has accepted their invitation.
	 *
	 * @since 1.0.0
	 *
	 * @param string $credential Token with access to the repository.
	 * @param string $repo       owner/name.
	 * @param string $login      Their current login.
	 *
	 * @return bool False for "not yet" (404) and "could not ask" alike; ask
	 *              again later either way. False, without asking, for a
	 *              repository or login that is not one.
	 */
	public function is_collaborator( #[\SensitiveParameter] string $credential, string $repo, string $login ): bool {
		$url = $this->collaborator_url( $repo, $login );

		return '' !== $url && 204 === $this->http->get( $url, Api::headers( $credential, $this->user_agent ) )->status;
	}

	/**
	 * Invite somebody to a repository.
	 *
	 * @since 1.0.0
	 *
	 * @param string     $credential Token with Administration write on the repo.
	 * @param string     $repo       owner/name.
	 * @param string     $login      Their login.
	 * @param Permission $permission The role to give.
	 *
	 * @return Invitation Refused with status 0, without asking, for a
	 *                    repository or login that is not one.
	 */
	#[\NoDiscard( 'A refused invitation means they have no access.' )]
	public function invite( #[\SensitiveParameter] string $credential, string $repo, string $login, Permission $permission = Permission::Pull ): Invitation {
		$url = $this->collaborator_url( $repo, $login );

		if ( '' === $url ) {
			return new Invitation( false, null, 0, 'Not a repository and login GitHub would accept.' );
		}

		$response = $this->http->put(
			$url,
			(string) json_encode( array( 'permission' => $permission->value ) ),
			Api::headers( $credential, $this->user_agent ) + array( 'Content-Type' => 'application/json' )
		);

		$json    = self::object( $response->json() );
		$id      = is_numeric( $json['id'] ?? null ) ? (int) $json['id'] : 0;
		$message = $json['message'] ?? '';

		return match ( $response->status ) {
			201     => new Invitation( true, $id > 0 ? $id : null, 201 ),
			204     => new Invitation( true, null, 204 ),
			default => new Invitation( false, null, $response->status, is_string( $message ) ? $message : '' ),
		};
	}

	/**
	 * Cancel an invitation not yet accepted.
	 *
	 * @since 1.0.0
	 *
	 * @param string $credential Token with Administration write.
	 * @param string $repo       owner/name.
	 * @param int    $invitation GitHub's invitation id.
	 *
	 * @return bool True when it is gone, including when it already was.
	 *              False, without asking, for a repository that is not one.
	 */
	#[\NoDiscard( 'False means the invitation may still be open.' )]
	public function cancel_invitation( #[\SensitiveParameter] string $credential, string $repo, int $invitation ): bool {
		if ( ! self::valid_repo( $repo ) ) {
			return false;
		}

		$response = $this->http->delete( Api::BASE . '/repos/' . Api::repo_path( $repo ) . '/invitations/' . $invitation, Api::headers( $credential, $this->user_agent ) );

		return $response->successful() || 404 === $response->status;
	}

	/**
	 * Remove a collaborator.
	 *
	 * @since 1.0.0
	 *
	 * @param string $credential Token with Administration write.
	 * @param string $repo       owner/name.
	 * @param string $login      Their current login.
	 *
	 * @return bool True when they are not a collaborator now, including when
	 *              they never were. False, without asking, for a repository
	 *              or login that is not one.
	 */
	#[\NoDiscard( 'False means they may still have access.' )]
	public function remove_collaborator( #[\SensitiveParameter] string $credential, string $repo, string $login ): bool {
		$url = $this->collaborator_url( $repo, $login );

		if ( '' === $url ) {
			return false;
		}

		$response = $this->http->delete( $url, Api::headers( $credential, $this->user_agent ) );

		return $response->successful() || 404 === $response->status;
	}

	/**
	 * Take somebody off a repository: the invitation if it is still open,
	 * and the collaborator if they accepted it.
	 *
	 * @since 1.0.0
	 *
	 * @param string   $credential Token with Administration write.
	 * @param string   $repo       owner/name.
	 * @param string   $login      Their current login, or '' when their
	 *                             account no longer exists: then only the
	 *                             invitation is cancelled, and nobody who has
	 *                             since taken the old name is touched.
	 * @param int|null $invitation The invitation id, if one was sent.
	 *
	 * @return bool True when they no longer have access.
	 */
	#[\NoDiscard( 'False means they may still have access.' )]
	public function revoke_access( #[\SensitiveParameter] string $credential, string $repo, string $login, ?int $invitation ): bool {
		if ( null !== $invitation && ! $this->cancel_invitation( $credential, $repo, $invitation ) ) {
			return false;
		}

		return '' === $login || $this->remove_collaborator( $credential, $repo, $login );
	}

	/* ─── Shapes ────────────────────────────────────────────────────── */

	/**
	 * Whether a repository name has the owner/name shape GitHub allows.
	 *
	 * Checked before it goes into a URL path, where `..` would be a
	 * different endpoint.
	 *
	 * @since 1.0.0
	 *
	 * @param string $repo Candidate.
	 *
	 * @return bool
	 */
	public static function valid_repo( string $repo ): bool {
		return 1 === preg_match( '#^[A-Za-z0-9](?:[A-Za-z0-9-]{0,38})/[A-Za-z0-9._-]{1,100}$#D', $repo ) && ! str_ends_with( $repo, '/.' ) && ! str_ends_with( $repo, '/..' );
	}

	/**
	 * Whether a pasted string looks like a personal access token.
	 *
	 * Fine-grained (`github_pat_`) or classic (`ghp_`). A shape check only,
	 * to refuse a typo before asking GitHub; {@see self::me()} is the real test.
	 *
	 * @since 1.0.0
	 *
	 * @param string $token Candidate, trimmed.
	 *
	 * @return bool
	 */
	public static function looks_like_token( #[\SensitiveParameter] string $token ): bool {
		return 1 === preg_match( '/^(github_pat_|ghp_)[A-Za-z0-9_]{20,255}$/D', $token );
	}

	/**
	 * Read a user from one of GitHub's user endpoints.
	 *
	 * @param string $url        Endpoint.
	 * @param string $credential Token to ask with.
	 *
	 * @return User|null
	 */
	private function person( string $url, #[\SensitiveParameter] string $credential ): ?User {
		$response = $this->http->get( $url, Api::headers( $credential, $this->user_agent ) );

		return $response->successful() ? User::from_array( self::object( $response->json() ) ) : null;
	}

	/**
	 * A collaborator's URL, or '' when the repository or login is not one.
	 *
	 * @param string $repo  owner/name.
	 * @param string $login Their login.
	 *
	 * @return string
	 */
	private function collaborator_url( string $repo, string $login ): string {
		$segment = self::segment( $login );

		return self::valid_repo( $repo ) && '' !== $segment ? Api::BASE . '/repos/' . Api::repo_path( $repo ) . '/collaborators/' . $segment : '';
	}

	/**
	 * A login as one URL path segment, or '' when it cannot be one.
	 *
	 * rawurlencode() leaves `.` alone, so a login of `..` would climb out
	 * of `/collaborators/` once the path is resolved — and DELETE
	 * /repos/{owner}/{name}/collaborators/.. is DELETE /repos/{owner}/{name},
	 * which deletes the repository.
	 *
	 * @param string $login Login.
	 *
	 * @return string
	 */
	private static function segment( string $login ): string {
		return '' === trim( $login, '.' ) ? '' : rawurlencode( $login );
	}

	/**
	 * A decoded JSON object, or an empty one when it is anything else.
	 *
	 * @param mixed $json Decoded response body.
	 *
	 * @return array<string, mixed>
	 */
	private static function object( mixed $json ): array {
		$object = array();

		foreach ( is_array( $json ) ? $json : array() as $key => $value ) {
			if ( is_string( $key ) ) {
				$object[ $key ] = $value;
			}
		}

		return $object;
	}
}
