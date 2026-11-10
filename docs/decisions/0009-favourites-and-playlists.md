# 0009. Favourites and playlists, per user

- **Date:** 2026-11-10
- **Status:** Accepted (being built: favourites first, then playlists)

## Context

The library is shared by every account; what each listener keeps for themselves was left for later when multi-user
was decided. The web client reserved the left side of the screen for this navigation.

## Decision

The owner's choices: favourites of tracks, albums and artists; duplicates allowed in playlists; in the web client,
"Add to playlist" in a dialog and, on phones, the navigation in a drawer opened from the header.

**Favourites**

- `Favourite`: user, date, and exactly one of track, album or artist; one per user and item. Deleted with its user or
  its item (`ON DELETE CASCADE`), e.g. when a scan removes a track, or an album left without tracks.
- `PUT` and `DELETE /api/tracks/{id}/favourite`, `/api/albums/{id}/favourite` and `/api/artists/{id}/favourite`:
  idempotent, 204 either way.
- `GET /api/favourites/tracks`, `/api/favourites/albums` and `/api/favourites/artists`: the user's favourites of
  each kind, newest first, paginated, as the usual tracks, albums and artists (signed URLs included).
- A `favourite` boolean on every track, album and artist the API returns (lists, single items, search results), for
  the current user. The user's favourite ids of a kind are read once per request, not once per item.

**Playlists**

- `Playlist`: owner, name (1 to 100 characters), created and updated dates. `PlaylistItem`: playlist, track,
  position, date added; deleted with its track. The same track may appear several times.
- `GET`/`POST /api/playlists`, `GET`/`PATCH`/`DELETE /api/playlists/{id}`, with the number of tracks, the total
  length and up to four album covers (for a mosaic).
- `GET /api/playlists/{id}/items` (in order, paginated, whole tracks); `POST /api/playlists/{id}/items` with
  `{tracks: [IRIs]}` (several at once, at the end: a whole album in one request); `PATCH /api/playlist_items/{id}`
  with `{position}` (the others shift); `DELETE /api/playlist_items/{id}`.
- Only the owner sees or changes a playlist; anyone else gets a 404, not a 403, so its existence doesn't leak. At
  most 10 000 tracks per playlist.

Everything else that is per user follows the same rule: one account never sees another's favourites or playlists.

## Consequences

- Favourites and playlists point to tracks by id, which survives files being moved or renamed
  ([0010](0010-tracks-keep-their-identity-when-files-move.md)). A file whose audio changed is a new track, and drops
  out of them.
- Every track, album and artist response depends on who asks (`favourite`): responses must not be shared between
  users by a cache.

## Origin

Proposed from the Echora Web repository as the next step after the player, and decided with the owner (2026-11-10).
