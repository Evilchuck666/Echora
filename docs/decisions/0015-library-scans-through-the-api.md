# 0015. Library scans through the API, recorded, and every night

- **Date:** 2026-11-13
- **Status:** Accepted

## Context

Adding music meant opening a shell on the server and running `app:library:scan`, and nothing kept the report. With
accounts administered through the API ([0013](0013-accounts-managed-through-the-api.md)), administrators manage the
server from the clients, except for the library. The owner chose this as the next step for the API.

## Decision

- **Every scan is recorded as a `LibraryScan`**, whoever started it (`trigger`: `api`, `console` or `schedule`):
  - its status (`queued`, `running`, `finished`, `failed`) and dates;
  - once finished, its report: added, moved, updated, unchanged, removed and unreadable files, covers and artist
    images, why TheAudioDB lookups stopped;
  - once failed, why.
- **`POST /api/library/scan`** (administrators) queues a scan; `{"full": true}` reads every file again.
  - It answers 202 at once. `GET /api/library/scan` shows the latest scan, and `GET /api/library/scans` all of them,
    the latest first.
  - The scan runs in a worker of its own (`messenger:consume library`, started by `docker-entrypoint` like the Opus
    workers), on the `library` transport of Messenger. A long scan therefore never holds up the Opus conversions,
    and it never runs in a worker that serves requests.
  - The transport's `redeliver_timeout` is 24 hours, as a big library can take more than the default hour.
  - Failures are recorded by the scan itself, never retried.
- **`app:library:scan`** runs at once, as before, and records its scan too. `frankenphp/crontab` runs it every night
  at 03:00 UTC with `--scheduled`, so new music shows up on its own.
- **One scan at a time.** A new one is refused while another is queued or running: 409 for the API, and an error at
  the console, except with `--scheduled`, where it is only a warning.
  - A scan runs under a lock. One still `running` while the lock is free was interrupted (the container stopped),
    and is failed by the next request, so it never blocks the others.
  - A scan queued for more than a day was lost, and is failed the same way.
- **Progress while it runs**, asked for by the owner once the scans were in place:
  - its `phase` (`listing` the files, reading the `files`, the album `covers`, then the `artist_images`), and how many
    of the phase's items are done out of how many (`phaseDone`, `phaseTotal`);
  - the scanner reports it through a callback, and `LibraryScanProgress` writes it with SQL at most once a second
    (plus at every new phase and its end), as the scanner clears the entity manager as it goes;
  - per phase, not as one percentage, as the phases differ too much: the artist images can take minutes, with
    TheAudioDB's limit of 30 lookups a minute.
- The report lists unreadable files by their path inside the music library. This is a deliberate exception to never
  exposing paths: these reports are shown to administrators only, and fixing those files needs their names. Tracks
  still never expose their paths.

## Consequences

- The production image runs one more background process, the library worker.
- The CI's smoke-test account is an administrator. When the account is one, the smoke test runs a quick scan and
  waits for the worker to finish it.
- Scans keep accumulating, one row each (at least one a day). They are small; prune them if it ever matters.

## Origin

Proposed from the Echora repository among the next steps for the API, and chosen by the owner (2026-11-13), who then
asked for the progress while a scan runs.

## Commits

- Not committed yet.
