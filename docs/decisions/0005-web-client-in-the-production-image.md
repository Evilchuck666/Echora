# 0005. The production image builds and serves the web client, on the API's origin

- **Date:** 2026-11-10
- **Status:** Accepted

## Context

Echora had to be usable outside `localhost`. The web client is a static single-page app; serving it from another
origin would need CORS in production, a second deployment, and a client that knows where the API lives.

## Decision

- The Dockerfile has a `web_build` stage (`oven/bun`) fed by an extra build context, `web`, the echora-web
  repository (`WEB_DIR` in `compose.prod.yaml`, `../echora-web` by default). It runs `bun install --frozen-lockfile`
  and `bun run build`, and the `dist/` output goes to `/srv/web` in the image.
- Caddy serves it at the root of the site (`@webClient` in the Caddyfile): the app's routes fall back to
  `index.html`, which is never cached (`no-cache`); built assets under `/assets` are cached for good (`immutable`);
  a missing asset is a 404, not the app. `/api`, `/bundles` and `/.well-known` go to PHP.
- The production build of the client calls the API by path (`/api/...`): same origin, so production CORS defaults
  to no origin at all (`CORS_ALLOW_ORIGIN: ^$$`).
- The image creates `/app/var/media` owned by `www-data`. Without it, Docker created the new media volume owned by
  root, PHP couldn't write thumbnails, and artist images answered 500.

## Consequences

- Building the production image needs the echora-web repository next to this one, and a client change that breaks
  `bun install --frozen-lockfile` or `bun run build` breaks this image (checked in CI,
  [0007](0007-production-image-checked-in-ci.md)).
- The client may not add top-level routes starting with `/api`, `/bundles` or `/.well-known`.

## Origin

Asked from the Echora Web repository: "a production build served by the API's own Caddy, same domain, no CORS"
(2026-11-10).

## Commits

- `bec2c8f` feat: Implement refresh token grace period to handle lost responses and enhance security (committed
  together with [0006](0006-refresh-token-grace-period.md): Dockerfile, Caddyfile, `compose.prod.yaml`,
  `prod.env.example`)
