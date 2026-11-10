# 0009. Favourites and playlists, per user

- **Date:** 2026-11-10
- **Status:** Proposed (not built yet; four choices pending, below)

## Context

The library is shared by every account; what each listener keeps for themselves was left for later when multi-user
was decided. The web client reserved the left side of the screen for this navigation.

## Proposal

**Favourites**

- `Favourite`: user, track, date; one per user and track. Deleted with its track (`ON DELETE CASCADE`).
- `PUT` and `DELETE /api/tracks/{id}/favourite`, idempotent. `GET /api/favourites`: the user's favourite tracks,
  newest first, paginated, each with the whole track (stream URLs included).
- A `favourite` boolean on every track in lists, search results and playlists, for the current user, computed with
  one query per page, not per track.

**Playlists**

- `Playlist`: owner, name (1 to 100 characters), created and updated dates. `PlaylistItem`: playlist, track,
  position, date added; deleted with its track.
- `GET`/`POST /api/playlists`, `GET`/`PATCH`/`DELETE /api/playlists/{id}`, with the number of tracks, the total
  length and up to four album covers (for a mosaic).
- `GET /api/playlists/{id}/items` (in order, paginated, whole tracks); `POST /api/playlists/{id}/items` with
  `{tracks: [IRIs]}` (several at once, at the end: a whole album in one request); `PATCH /api/playlist_items/{id}`
  with `{position}` (the others shift); `DELETE /api/playlist_items/{id}`.
- Only the owner sees or changes a playlist; anyone else gets a 404, not a 403, so its existence doesn't leak. At
  most 10 000 tracks per playlist.

Tests for isolation between users, cascades on rescans, positions on moves and removals, and limits; README and
Bruno collection.

## Pending choices

1. Favourites of tracks only (proposed), or also of albums and artists.
2. Duplicates in a playlist: allowed (proposed), or refused.
3. In the web client, "Add to playlist" in a dialog (proposed) or a submenu.
4. In the web client on phones, navigation in a drawer from the header (proposed) or tabs at the bottom.

## Consequences

- Tracks are identified by their path: moving or renaming files makes the scan delete them and create new ones, so
  they drop out of favourites and playlists. Accepted for now; re-matching by tags during the scan could fix it later.

## Origin

Proposed from the Echora Web repository, as the next step after the player (2026-11-10).
