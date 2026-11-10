# 0006. A 5-minute grace period for refresh tokens whose new pair was lost

- **Date:** 2026-11-10
- **Status:** Accepted

## Context

Refresh tokens are single use with reuse detection ([0001](0001-logout-and-serialized-refreshes.md)). A flaky
end-to-end test of the web client showed the weak point: a refresh can succeed on the server while its response
never reaches the client (the page is reloaded or closed meanwhile, the network drops). The client still holds the
spent token, presents it again, and reuse detection revokes the whole session: the user is signed out for nothing.
In real use this happens on mobile networks and on reloads at the wrong moment.

## Decision

For `REFRESH_TOKEN_GRACE_SECONDS` (300 by default) after a refresh, a spent token is taken as the token that replaced
it, as long as that successor has never been used (`RefreshTokenGraceListener`). The request goes on as if the
client had presented the successor (`RefreshTokenSuccessorExtractor`, first in the bundle's extractor chain) and gets
a new pair.

- Theft is still detected: whoever holds the successor and uses it later presents a spent token, which revokes the
  session. A spent token whose successor was already used, or presented after the grace period, is reuse as before.
- The presented token always maps to the latest pair issued for it. An earlier version followed chains of
  successors, which revived tokens whose successor had been used; it was dropped.
- The successor is a live credential, so it is stored encrypted (sodium) with a key derived from the token it
  replaced and the app secret (`RefreshTokenSuccessors`): reading it needs the old token, which only its holder has.
- It is kept in a persistent cache pool, `cache.refresh_tokens`, in PostgreSQL (the `cache_items` table), not in
  `var/cache`: a deploy clears the application cache, and reuse must still be recognised after one.

This is what Auth0 (reuse interval) and Okta (grace period) do. Five minutes, configurable, was the owner's choice
among the windows compared: long enough for a reload or a network hiccup; a much longer one (say 30 days) would
remove the protection against old leaked tokens.

## Consequences

- A stolen spent token works for up to five minutes, but only until the legitimate client refreshes, which then
  revokes the session.
- The web client's mock API implements the same rule; its tests cover a lost refresh response.

## Origin

Asked from the Echora Web repository, after investigating a flaky CI test, and designed there with the owner
(2026-11-10).

## Commits

- `bec2c8f` feat: Implement refresh token grace period to handle lost responses and enhance security
