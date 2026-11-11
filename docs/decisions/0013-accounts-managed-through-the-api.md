# 0013. Accounts managed through the API, the first one becoming the administrator

- **Date:** 2026-11-11
- **Status:** Accepted

## Context

Accounts could only be created, changed and deleted with the `app:user:*` console commands. There was no sign-up
endpoint, and an account was nothing but a username and a password. The owner wanted Echora to behave like Immich:
- the first person to open a new server creates its administrator;
- administrators create, manage and delete the other accounts from the clients;
- an account has a username, an email and a profile picture.

## Decision

- **Setting up a server:**
  - `GET /api/setup` says whether the server still has no account.
  - `POST /api/setup` (public) creates the first account as administrator and signs it in, answering like
    `POST /api/auth`. Once an account exists, it answers 409.
  - One lock (`symfony/lock`, `user-accounts`) covers every creation, so two people setting up at once can't both
    become administrators.
  - The owner accepted the risk, as in Immich, that someone could take a server that is published before it is set
    up. Creating the administrator from the console beforehand stays possible.
- **Managing accounts:**
  - Administrators use `/api/users`: list, create, read, change (`name`, `username`, `email`, `admin`, `password`)
    and delete.
  - There is still no sign-up.
  - The server always keeps an administrator, and an administrator can't delete their own account (409). The
    administrators are counted in SQL under the same lock, so two administrators demoting each other at once can't
    leave none.
- **Identity of an account:**
  - A new account gives an email and a name, the name shown to others (the owner chose to have one, besides the
    username).
  - The username is made from the email (`ana`, `ana-2` when taken, `al-1` when too short) and can be changed
    afterwards.
  - The owner chose sign-in with the email or the username.
  - Accounts created before keep their username as name, and have no email until one is set.
- **Self-service:** everyone can change their own name, username and email (`PATCH /api/me`), their password
  (`POST /api/me/password`, which needs the current one) and their picture (`/api/me/avatar`), as the owner chose.
- **Sessions follow the account, not its name.** Renaming an account must not sign it out, so:
  - JWTs (the `sub` claim) and refresh tokens now hold the account's id;
  - a migration rewrote the refresh tokens already issued, so nobody had to sign in again;
  - the user loader accepts an id, an email or a username. A username can't contain `@`, nor be as long as a UUID.
- **Profile pictures:**
  - Uploaded as `multipart/form-data` with `POST` (PHP only parses multipart bodies for POST).
  - The type is checked from the content (`getimagesize()`), never from what the client says, and the file can be
    up to 5 MB.
  - The picture is re-encoded by `Thumbnails` as a square JPEG of up to 300×300, so no metadata (location) and
    nothing but an image is ever served.
  - It is stored in the media folder and served by Caddy, behind a signed `avatarUrl` like covers. The file name is
    in the URL, so a new picture gets a new URL.

## Consequences

- This supersedes the rule that accounts are created only with the console commands. The commands remain, to get
  back in (like `immich-admin`): `app:user:create` now takes an email.
- JWTs issued before this named accounts by username in a `username` claim, and stop working. Clients refresh
  them, and the refresh tokens kept working through the migration.
- Clients need a setup screen, a profile page and an account administration page; signing in is unchanged.
- The production smoke test checks that setup is closed, and that a profile picture is written to the media volume
  and served by Caddy. It leaves an account's existing picture alone.

## Origin

Asked from the Echora repository by the owner (2026-11-11), modelled on Immich. The plan was validated with the owner,
who decided:
- sign-up with an email and a name;
- sign-in with the email or the username;
- a display name besides the username;
- self-service changes of name, username and email;
- the Immich behaviour for setup, risk included.

## Commits

- `323fa2c` feat(accounts): manage accounts through the API, the first one becoming the administrator
