# 0009. Favourites and playlists, per user

- **Date:** 2026-11-10
- **Status:** Accepted

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

**Playlists: decided while building them**

These were left to the implementation, or set by the owner when playlists were asked for (2026-11-10).

- Positions:
  - Each item stores a sort key that is never exposed: a BIGINT, unique and positive in its playlist, with gaps
    between keys.
  - Appending takes the last key plus a step. Moving takes the middle of the new neighbours' keys, and renumbers the
    playlist above its highest key when they touch. Removing changes no other key.
  - The exposed `position` is the index in that order, from 0, computed when items are read. It stays consecutive
    whatever is removed, including by a scan deleting tracks, with nothing to renumber.
  - Two items never share a key (a unique index), and the order never depends on chance.
- Changes to the items of a playlist run one at a time: each locks the playlist row first, in a transaction
  (`SELECT ... FOR UPDATE`). Two tabs or devices changing the same playlist wait for each other instead of clashing.
- ~~Lists of playlists are ordered by last change, newest first, then by id.~~ Superseded by a manual order of their
  own, below. `updatedAt` still changes with any change to a playlist or its items (renaming, adding, moving or
  removing an item, a scan removing one of its tracks: `LibraryScanner` updates those playlists before deleting the
  tracks), but no longer orders the list.
- `PATCH /api/playlist_items/{id}` with a position beyond the last item moves the item to the end (handy to say
  "to the end" without knowing the count). A negative position is a 422.
- `POST /api/playlists/{id}/items`:
  - Adds all the tracks or none: an unknown track, or more than 10 000 items afterwards, is a 422 that names the
    problem.
  - Answers 200 with the playlist (`trackCount`, `durationMs`, `covers`, `updatedAt`).
  - Tracks are given as ids or IRIs.
- `trackCount` counts items (a track added twice counts twice). `durationMs` sums the known lengths. `covers` holds the
  signed cover URLs of the first four different albums that have a cover, in the order of the playlist.
- These three are computed for a whole page of playlists in two queries, never one per playlist, and are not stored.
- The name has 1 to 100 characters, with no space (or line break) at the start or the end, and need not be unique.
- Playlists and items are API Platform resources, so both PATCH requests take `application/merge-patch+json`.
- There is no `GET /api/playlist_items/{id}`: items are read through their playlist.

**Playlists: a manual order of their own**

Each user orders their playlists by hand, and the order is the same on every device. The web client drags them in its
sidebar.

- The order reuses the approach of the items of a playlist. `App\Ordering\SortKeys`, now shared by both orders,
  handles it:
  - a hidden sort key per playlist, unique and positive among its owner's playlists (`owner_id`), with gaps;
  - a `position` computed when read, an index from 0, consecutive also after a playlist is deleted;
  - renumbering when two keys touch.
- `GET /api/playlists` lists them in that order.
- A new playlist comes first (position 0), and the others move down by one.
- `PATCH /api/playlists/{id}` with `{"position": n}`, alone or with `name`, moves a playlist.
  - The index is in the current order, and the ones in between shift by one.
  - Beyond the last playlist means the end, and a negative position is a 422, as with items.
- Moving a playlist doesn't change its `updatedAt`. A new name does; the same name again doesn't.
- Creating and moving lock the owner's row first, in a transaction, so two devices reordering at once wait for each
  other.
  - The lock is `SELECT ... FOR NO KEY UPDATE`, not `FOR UPDATE`, so that inserting rows that point to the user
    (favourites, refresh tokens) isn't blocked meanwhile.
- The migration kept the order playlists had until then: last changed first, then by id, for each user.
- As with items, positions are indexes in the order the API has when a request arrives, so clients send moves one at
  a time.

## Consequences

- Favourites and playlists point to tracks by id, which survives files being moved or renamed
  ([0010](0010-tracks-keep-their-identity-when-files-move.md)). A file whose audio changed is a new track, and drops
  out of them.
- Every track, album and artist response depends on who asks (`favourite`): responses must not be shared between
  users by a cache.
- Positions are indexes in the order the API has when a request arrives. A client that sends several changes to a
  playlist without waiting for each answer can have them applied in another order (a removal overtaking a move makes
  the move land one place off), so clients send them one at a time. Found by the web client against the real API
  (2026-11-10); it queues them per playlist (`src/library/playlists.ts`).

## Origin

Proposed from the Echora Web repository as the next step after the player, and decided with the owner (2026-11-10).
Playlists were asked for from the Echora Web repository, on the design above (2026-11-10). Their manual order was
asked from the Echora Web repository too (2026-11-10), replacing the order by last change.

## Commits

- `2af7766` feat: implement favourites functionality for tracks, albums, and artists
- `379a827` feat(playlists): add per-user playlists with ordered items
- Manual order of playlists: not committed yet.
