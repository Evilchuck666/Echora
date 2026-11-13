# 0016. Listening history: plays, recently played and most played tracks

- **Date:** 2026-11-13
- **Status:** Accepted

## Context

Echora knew what each user marked as a favourite and put in playlists, but not what they listened to. Clients
couldn't offer "Recently played" or "Most played", the first things a music app shows, and there was nothing to build
"resume on another device" on later. The owner chose this as the next step for the API.

## Decision

- **Recording plays:**
  - A `Play` is a user, a track and when it was played. It is deleted with the user and with the track, and is not
    exposed as a resource.
  - `POST /api/tracks/{id}/plays` records one (204).
  - The client decides when a track counts as listened to. The API recommends Last.fm's rule: when it ends, or once
    half of it (or 4 minutes) has played, whichever comes first. The server can't tell, as it doesn't see playback
    (files are sent by Caddy, with ranges).
- **Retries and offline plays:**
  - A play of the same track within 30 seconds of another counts once, so a client retrying after a lost response
    doesn't count twice. The play is written with `INSERT ... WHERE NOT EXISTS`.
  - An optional `playedAt` lets future native apps send plays made offline when they reconnect. It is accepted within
    the last year and up to 5 minutes ahead (clock drift).
- **Reading:** `GET /api/me/recent-tracks` (each track once, the most recently played first) and
  `GET /api/me/top-tracks` (the most played first).
  - `top-tracks` takes `?period=week|month|year|all`, defaulting to `all`.
  - Each item is the whole track (as `GET /api/tracks`), its `playCount` and its `lastPlayedAt`, 30 per page.
  - A page takes a fixed number of queries.
- **`DELETE /api/me/history`** forgets every play of the user, for privacy.
- Resuming playback on another device is left for later. It needs another piece of data (where the user stopped,
  updated while playing), which this doesn't prevent.

## Consequences

- Clients have to send the play themselves, when their rule says so. A track shorter than 30 seconds, played twice in
  a row, counts once.
- Plays are kept forever, one small row each. Moving or renaming files keeps them (tracks keep their id,
  [0010](0010-tracks-keep-their-identity-when-files-move.md)), and deleting a file deletes them.
- History is per account, like favourites and playlists: no one else sees it.

## Origin

Proposed from the Echora repository among the next steps for the API, and chosen by the owner (2026-11-13).

## Commits

- `2f0b4f2` feat(history): record plays and list recently and most played tracks
