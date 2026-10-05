# GitHub

The GitHub calls for selling access to private repositories: a GitHub App's RS256 JWT and installation tokens, user sign-in with PKCE that handles GitHub's 200-with-an-error answer, and collaborator invitations kept by numeric id rather than by a login that can be renamed.

## Why

"Buy this, get access to the repo" sounds like one API call. It is about ten, and each has a way to go wrong that only shows up later:

- **Logins are not identities.** A login can be renamed, and the old one registered by a stranger. Access stored by login follows the name. Store the numeric id and look the login up when you need it.
- **A refused code is HTTP 200.** GitHub's token endpoint answers a bad or reused code with 200 and an `error` field.
- **Invitations have two kinds of success.** 201 sends an invitation with an id you need later to cancel it; 204 means they were already in. Fold them together and an unaccepted invitation can never be withdrawn.
- **Removing a collaborator leaves their invitation open.** Both have to go.
- **A deleted account is not an outage.** "GitHub has no such user" and "GitHub could not be asked" must be different answers, or a sweep guesses.
- **A setup redirect's `installation_id` is just a number.** Ids count up; check it against the installations the installing user can reach.

## Features

- 🤖 **GitHub App** — RS256 JWT via OpenSSL, backdated a minute; installation tokens minted per run, never stored.
- 🔑 **User sign-in** — PKCE always, the account picker by default, the token used once and revoked.
- 🧭 **Install checks** — the installations a user's code can reach, to verify a setup redirect.
- 👥 **Collaborators** — invite, check, cancel, remove, and `revoke_access()` that does both halves.
- 🪪 **`User` by id** — `user_by_id()` returns a user, `false` for a deleted account, or `null` when GitHub could not be asked.
- 🛡️ **`Permission`** — the five roles, `writes_code()`, and `read_only()` for a name from metadata.
- 📌 **Pinned API version** and a User-Agent you choose.

## Requirements

PHP 8.5+ with OpenSSL, [`sugarcommerce/http-client`](https://github.com/sugarcommerce/http-client), [`sugarcommerce/signed-tokens`](https://github.com/sugarcommerce/signed-tokens) (for base64url).

## Installation

```bash
composer require sugarcommerce/github
```

## Usage

### The App

```php
use SugarCommerce\Github\App;

$app = new App( $app_id, 'my-app', $private_key_pem, user_agent: 'MyShop' );

$app->install_url( $signed_state );          // send the seller here
$app->installation( 42 );                    // 'midnight-records', or null
$token = $app->installation_token( 42 );     // an hour; mint per run
```

### Signing a buyer in

Pair it with [`sugarcommerce/oauth`](https://github.com/sugarcommerce/oauth) for the PKCE pair and a state bound to the browser:

```php
use SugarCommerce\Github\OAuth;

$oauth = new OAuth( $client_id, $client_secret, user_agent: 'MyShop' );

$url = $oauth->authorize_url( $callback, $out->state, $out->challenge );

// In the callback:
$user = $oauth->identify( $_GET['code'], $callback, $trip->verifier );   // ?User

$user->id;      // keep this
$user->login;   // and look this up again when you need it
```

`identify()` exchanges the code, asks who it is, and revokes the token before returning.

For a setup redirect, check the installation belongs to whoever came back:

```php
in_array( $installation_id, $oauth->installations( $_GET['code'] ), true );
```

### Collaborators

```php
use SugarCommerce\Github\{Permission, Rest};

$rest = new Rest( user_agent: 'MyShop' );

$sent = $rest->invite( $token, 'midnight/stems', $login, Permission::read_only( $metadata ) );

$sent->ok;            // invited, or already in
$sent->id;            // keep it: needed to cancel an unaccepted invitation
$sent->already_in();

$rest->is_collaborator( $token, 'midnight/stems', $login );   // accepted yet?

// Their login today, from the id you kept:
$user = $rest->user_by_id( $token, $id );   // User, false (deleted) or null (ask later)

$rest->revoke_access( $token, 'midnight/stems', false === $user ? '' : $user->login, $sent->id );
```

`Rest::valid_repo()` and `Rest::looks_like_token()` check shapes before anything goes into a URL or a request.

## Testing

```bash
composer install
composer test
```

38 tests, all against `HttpClient\Fake` — nothing reaches GitHub. The JWT is verified with the key's public half; the token exchange's 200-with-an-error; token revocation after use; install codes without a redirect or verifier; both kinds of successful invitation; cancelling and removing, with 404 as success; a deleted account versus an unreachable API; repository and token shapes; permissions; and the `#[\NoDiscard]` results.

## License

GPL-2.0-or-later
