# 0014. Sign-in throttling, in a firewall of its own

- **Date:** 2026-11-13
- **Status:** Accepted

## Context

Since accounts are managed through the API ([0013](0013-accounts-managed-through-the-api.md)), servers are meant to
be reached from the internet by people's devices. Nothing limited how many passwords could be tried:
`POST /api/auth` answered every attempt, and so did `POST /api/me/password` for wrong current passwords. The owner
chose this as the next step for the API.

## Decision

- `POST /api/auth` uses Symfony's `login_throttling`:
  - at most 5 failed attempts per account and address, and 25 per address whatever the accounts, in 15 minutes;
  - a successful sign-in clears the account's count, but not the address's.
- Throttled attempts answer **429** with a `Retry-After` header (seconds), in the same JSON shape as the other
  failures (`code`, `message`). They are refused even with the right password. Without `LoginFailureHandler`, the JWT
  bundle's failure handler would answer them with a 401, like a wrong password.
- **A firewall of its own** (`login`, `^/api/auth$`) holds `json_login` and the throttling.
  - The throttling counts every failed authentication of its firewall. In the `api` firewall, that includes every
    expired JWT and every spent refresh token.
  - A client waking up with an expired JWT fires several requests at once. That would have used up its address's
    attempts and locked it out of signing in for 15 minutes. A test reproduces it with the login in the `api`
    firewall.
- `POST /api/me/password` counts wrong current passwords per account, in the `password_change` rate limiter: 5 in 15
  minutes, then 429 with `Retry-After`, even with the right one. A stolen JWT must not allow guessing the password.
- `TRUSTED_PROXIES` (empty by default) tells Symfony which reverse proxies' `X-Forwarded-For` to believe.
  - Behind another proxy (nginx, Traefik, Cloudflare...), every request would otherwise come from the proxy's address,
    and the per-address limit would lock everyone out at once.
  - Empty, the header is ignored, so clients can't fake their address.
- `POST /api/setup` is not throttled: it holds no secret and works only once.

## Consequences

- Attempts are counted in `cache.rate_limiter`: `cache.app` on the filesystem, so a deploy (a new container) clears
  them. In tests they are in PostgreSQL, so they last across requests and DAMA rolls them back.
- An account's username and email are counted separately, as Symfony counts by the identifier typed. The per-address
  limit (25) still bounds both.
- The production smoke test makes 6 failed sign-ins with a made-up account and expects the last one to be throttled.
  It never locks the real account, but uses 6 of the 25 attempts of its address for 15 minutes.

## Origin

Proposed from the Echora repository among the next steps for the API, and chosen by the owner (2026-11-13).

## Commits

- Not committed yet.
