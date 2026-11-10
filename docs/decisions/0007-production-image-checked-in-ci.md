# 0007. CI builds the production image and smoke-tests it

- **Date:** 2026-11-10
- **Status:** Accepted

## Context

The production image is built from two repositories ([0005](0005-web-client-in-the-production-image.md)), and its
first real run had a bug no test saw: the media volume belonged to root, so artist thumbnails answered 500. The unit
and API tests run against the development setup, which differs (no Caddy file serving with the production
Caddyfile, no built client, other CORS).

## Decision

- A second CI job, "Production image" (`.github/workflows/ci.yaml`), checks out this repository and echora-web side
  by side, builds the production image with a throwaway `prod.env` (random secrets, the test music fixtures,
  TheAudioDB off), starts it, creates an account, scans the library, and runs `scripts/smoke-test-prod.sh`.
- The smoke test checks a running production Echora from the outside (21 checks): the client at `/` with its cache
  headers and route fallback; public docs; the library behind a JWT; CORS closed to other origins; sign-in, refresh,
  sign-out; audio with Range and without the JWT, refused without its signature, `X-Accel-Redirect` never leaking;
  an album cover; an artist thumbnail (which proves the media volume is writable). It doesn't depend on particular
  fixtures, so it also checks a real deployment after it is updated.
- The job builds echora-web's `main`, or another ref given to "Run workflow" (`web_ref`).

## Consequences

- A change to how production serves something should come with a smoke check.
- The job runs on changes to this repository, not to echora-web: a client change that breaks the image shows up at
  the next run of this CI, or when it is run by hand.

## Origin

Asked from the Echora Web repository: "check the production image in the API's CI" (2026-11-10).

## Commits

- `3d6116e` feat: Add production CI job and smoke test script for deployment validation
