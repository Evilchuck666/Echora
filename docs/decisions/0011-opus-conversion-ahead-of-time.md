# 0011. Converting to Opus ahead of time, in background workers

- **Date:** 2026-11-10
- **Status:** Accepted

## Context

With "Data saver" on, the web client plays the `opusStreamUrl` of FLAC tracks. The first request for a track waits
for `OpusTranscoder` to convert it (about 5 seconds on the owner's library), so skipping to a track that wasn't
preloaded shows a spinner. The client only preloads the next track during the last 30 seconds of the current one.
The queue lives in the client: the API doesn't know what comes next.

## Decision

Clients tell the API which tracks are about to play, and the API converts them in the background.

- `POST /api/transcodes` with `{"tracks": [...]}` takes track ids or IRIs, next one first.
  - It needs a JWT (`ROLE_USER`) and accepts at most 20 tracks, so a request stays small.
  - It answers `202` at once, without waiting for any conversion. The body says which tracks are `queued`, `ready` or
    `converting`.
  - MP3 tracks, tracks already converted and tracks being converted are left out. The per-track lock of
    `OpusTranscoder` tells which ones are being converted, and the background conversion never waits for it.
- Each request replaces the user's previous one, so an old list doesn't pile up:
  - The request's id is kept in `cache.app` for the user.
  - The worker checks it before each track and drops a list that is no longer the latest, finishing only the
    current conversion.
  - An empty list just cancels the previous one.
- The conversions run outside the PHP workers that serve requests, in `messenger:consume transcodes` workers.
  - Symfony Messenger, with the Doctrine transport: its queue is a PostgreSQL table, and a NOTIFY wakes the workers, so
    no other service or container is needed.
  - `docker-entrypoint` starts the workers next to FrankenPHP, as it does Supercronic, the same way in development and
    production.
  - `TRANSCODE_WORKERS` (default 1) sets how many conversions run at once: each `opusenc` takes one CPU core, and the
    server may be small.
- A request becomes one message holding its ordered list, not one message per track.
  - The worker converts the tracks one after the other, next one first. The transport only orders messages to the
    second, so separate messages could come out in any order.
  - A dropped list costs a single message.
- A track asked for that is already converted counts as played (its access time is updated), so
  `app:transcodes:prune` keeps whatever is about to be played. A track converted ahead of time is as fresh as one
  just played.
- Failures are logged and never retried: converting ahead of time only saves a wait. The track is still converted
  when it is first played.

## Consequences

- One list per account: two devices of the same account playing at the same time replace each other's list.
- With one worker, a new list starts once the current conversion of the old one ends (a few seconds at most).
- A deploy empties `cache.app`, so the lists still waiting then are dropped. The client sends a new one when its
  queue changes.
- The workers are long-running processes: changed code needs `bin/console messenger:stop-workers` (they restart on
  their own) or a restart. Each one also restarts every hour.
- The production smoke test asks for the Opus version of a FLAC track and waits for a worker to convert it.

## Origin

Asked from the Echora Web repository (2026-11-10). The owner described the problem and the requirements:

- an authenticated endpoint that answers 202 and takes at most 20 tracks;
- background conversion outside the HTTP workers, with a limited and configurable number of conversions at once;
- the order of the list kept, and old lists not piling up;
- the same setup in development and production, with no new service;
- prune never deleting a track converted ahead of time before it is played.

The owner left the mechanism to the implementation, which chose Messenger with the Doctrine transport, one message
per request, and per-user replacement, for the reasons above.

## Commits

- Not committed yet.
