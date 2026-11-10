# 0004. Audio and images sent by Caddy (X-Accel-Redirect), not by PHP

- **Date:** 2026-11-09
- **Status:** Accepted

## Context

Playing in Firefox, tracks went silent near their end, or after a seek, while the position kept moving; seeking
back to an earlier point made them play again. The cause was in the API: PHP streamed each file itself
(`BinaryFileResponse`), and a long response from a slow reader (the browser buffers ahead and then waits) hit PHP's
30-second `max_execution_time`. The response was cut, and in development Xdebug's HTML error page was written into
the middle of the audio, which the decoder then choked on. Firefox reads ahead differently from Chromium, so it
showed first; any long track on a slow network would have hit it.

## Decision

PHP still checks access (JWT or signed URL, `LibraryFiles::denyUnlessAuthorized`) and chooses the file, but answers
with an `X-Accel-Redirect` header instead of the file. Caddy (`frankenphp/Caddyfile`) sets the `X-Sendfile-Type`
and `X-Accel-Mapping` request headers itself, intercepts responses carrying `X-Accel-Redirect`, removes the header
and serves the file, with Range, `Content-Length` and caching headers. Files outside the mapped folders (`/music`,
`/app/var/media`) are still streamed by PHP, as are requests that don't come through Caddy (tests).

Raising `max_execution_time` alone would not do: a PHP worker would stay busy for the whole length of every
download (a player reads at the pace of the music), and FLAC files are about 30 MB.

## Consequences

- PHP workers are freed as soon as access is checked, whatever the file size.
- The mapping in the Caddyfile must cover every folder files are served from; the production smoke test checks
  that `X-Accel-Redirect` never reaches clients ([0007](0007-production-image-checked-in-ci.md)).

## Origin

Found from the Echora Web repository, investigating playback that went silent in Firefox (2026-11-09).

## Commits

- `7350f36` feat: Enhance file serving with Caddy integration and X-Accel-Redirect support
