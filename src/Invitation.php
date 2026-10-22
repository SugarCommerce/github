<?php
/**
 * What inviting a collaborator came to.
 *
 * @package   SugarCommerce\Github
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Github;

/**
 * Class Invitation
 *
 * GitHub answers an invitation two successful ways, and they mean
 * different things: 201 sends an invitation the person still has to
 * accept, with an id you need later to cancel it; 204 means they were a
 * collaborator already and nothing was sent. Folding both into "true"
 * loses the id, and then an invitation that is never accepted can never be
 * withdrawn.
 *
 * @since 1.0.0
 */
final readonly class Invitation {

	/**
	 * What GitHub answered an invitation with.
	 *
	 * @since 1.0.0
	 *
	 * @param bool     $ok      Whether they are invited or already in.
	 * @param int|null $id      GitHub's invitation id when one was sent (201).
	 * @param int      $status  HTTP status, 0 when GitHub was not reached.
	 * @param string   $message GitHub's own message on a refusal.
	 */
	public function __construct(
		public bool $ok,
		public ?int $id = null,
		public int $status = 0,
		public string $message = '',
	) {}

	/**
	 * Whether they were a collaborator already, so there is nothing to accept.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function already_in(): bool {
		return $this->ok && null === $this->id;
	}
}
