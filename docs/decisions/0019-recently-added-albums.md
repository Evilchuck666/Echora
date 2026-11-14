# 0019. Recently added albums, dated by when a scan first found them

- **Date:** 2026-11-14
- **Status:** Accepted

## Context

New music shows up on its own with the nightly scan ([0015](0015-library-scans-through-the-api.md)), but nothing
told which: the albums were only listed in the order of the library. The web client wanted a "Recently added" list.
Albums didn't record when they entered the library.

## Decision

- **Albums record `addedAt`:** the moment a scan first found the album, set when it is created. It is exposed on
  albums (`album:read`).
- **`GET /api/albums/recently-added`** lists the albums the latest first (`addedAt`, then the id, which is a UUIDv7
  and so follows the order the scan made them in), 30 per page, like any album list.
  - A path of its own, as `/api/favourites/albums`, declared before `GET /api/albums/{id}` so it isn't taken for an
    album's id. It is an operation of the album resource with its own `order`, so nothing else changes in the other
    lists.
- **The date of the albums already there comes from their id.** Album ids are UUIDv7, whose first 48 bits are the
  Unix time in milliseconds at which they were made: the migration reads it, so existing albums keep the day they
  were really scanned in. An id of any other version (none is expected) gets the time of the migration.
- **Not the files' modification time.** It changes when files are copied or retagged, and a library copied to the
  server in one go would show its whole content as added that day, or in the order its files were last edited. The
  scan's own record of when it first saw the album is what "added to Echora" means.

## Consequences

- An album whose tracks all go away is deleted with them; found again later, it is a new album, added again.
- Tracks and artists have no such date. "Recently added" is per album, as music is added album by album.
- `addedAt` has a precision of a second; albums of one scan share it, and their ids keep them in scan order.

## Origin

Asked from the echora-web repository (2026-11-14), for a "Recently added" section of the web client, chosen by the
owner among the next steps.

## Commits

- To be recorded.
