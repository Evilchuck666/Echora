# 0008. Tracks in the order of the library, and the `albumArtist` filter

- **Date:** 2026-11-10
- **Status:** Accepted

## Context

The web client added "Play all" on an artist's page and "Play" for the whole library. `GET /api/tracks` couldn't
serve either: its `artist` filter is the track artist (so it also finds guest appearances on other artists'
albums), and its order (disc and track number, then album year across the library) mixed artists and albums.
Fetching every album and then each album's tracks would have meant many requests and a business rule (the order of
the library) living in the client.

## Decision

- A filter `albumArtist` (IRI): every track of an artist's own albums, the same albums their page lists. `artist`
  stays as it was.
- One order for every track list: album artists by name, their albums by year and title (as `GET /api/albums`),
  then disc number, track number and title, and the track id last so that ties never move between pages. It is
  applied by a Doctrine extension, `TrackOrderExtension`: API Platform's `order` attribute follows one relation
  (`album.year`), not two (`album.artist.name`).
- For a single album the result is the same as before.

## Consequences

- Clients get the whole library in order by paging `GET /api/tracks`, 30 tracks a page; the web client starts
  playing with the first page and adds the others meanwhile.
- Ordering joins `album` and its artist on every track list; both are many-to-one, so it stays cheap.

## Origin

Asked from the Echora Web repository, for "Play all" on artist pages and Play for the whole library (2026-11-10).

## Commits

- `aa7e435` feat: enhance track listing with album artist filter and improved sorting
- `48c5126` feat: implement track sorting by album artist and enhance API documentation
