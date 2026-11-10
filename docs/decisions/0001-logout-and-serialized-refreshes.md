# 0001. Logout endpoint, refresh endpoint documented by path, one refresh per token at a time

- **Date:** 2026-11-09
- **Status:** Accepted

## Context

The web client was being built on the API's authentication (JWT for one hour, single-use refresh tokens with reuse
detection) and found three gaps:

- There was no way to sign out: the refresh token stayed valid after the user left, so dropping it in the browser
  was the only "sign-out", and a copy of it kept working.
- The refresh endpoint appeared in the OpenAPI specification under its route name (`api_refresh_token`), not its
  path, so the generated client types had no `/api/token/refresh`.
- Two requests presenting the same refresh token at the same moment (two tabs, a retry) could both find it before
  either deleted it, and both get a new pair: two live chains that reuse detection never noticed.

## Decision

- `POST /api/logout` with `{"refresh_token"}` invalidates that refresh token (the firewall's logout listener, with
  gesdinet's `invalidate_token_on_logout`). It needs no JWT, since the JWT may already have expired; the JWT itself
  stays valid until it expires (one hour), and the client drops it. Signing out twice is not an error. It is
  documented by `LogoutOpenApiFactory`, as there is no controller for API Platform to find.
- The refresh firewall's `check_path` is the path, `/api/token/refresh`, so the bundle documents it as written.
- `RefreshTokenLockListener` holds a lock per refresh token (symfony/lock on PostgreSQL advisory locks, shared by
  every PHP worker) around the firewall: a second request with the same token waits until the first has spent it,
  then is refused and its chain revoked, exactly as if the two had arrived one after the other.

## Consequences

- Clients must still refresh single-flight (concurrent 401s wait for one refresh) and coordinate between tabs: the
  lock makes the API correct, not forgiving. The web client does it with a Web Lock.
- A refresh whose response is lost still signs the user out; see [0006](0006-refresh-token-grace-period.md).

## Origin

Asked from the Echora Web repository while building its sign-in and session refresh (2026-11-09).

## Commits

- `59e403b` feat: Implement logout functionality to invalidate refresh tokens and enhance session management
