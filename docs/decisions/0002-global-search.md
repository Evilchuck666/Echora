# 0002. One search endpoint for artists, albums and tracks, ignoring accents

- **Date:** 2026-11-09
- **Status:** Accepted

## Context

The web client wanted one search field for the whole library. The collections only had partial `name`/`title`
filters: one request per kind, a single substring (so "balls wall" found nothing), case-insensitive but not
accent-insensitive ("motorhead" missed "Motörhead"), and no ranking.

## Decision

`GET /api/search?q=…&limit=…` returns artists, albums and tracks at once, each list with its total
(`LibrarySearch`, `SearchProvider`, the `Search` API resource):

- Every word of the query must appear, in any order, ignoring case and accents (PostgreSQL's `unaccent`, enabled by
  a migration and exposed to DQL as `UNACCENT`).
- Items are also found through the names around them: an album by its artist, a track by its artist or album.
- Ranking: equal to the query, then starting with it, then containing every word in its own name, then found only
  through the names around it; within a rank, more matching words first.
- `limit` defaults to 10 per kind, at most 50 (`Search::MAX_LIMIT`); queries up to 100 characters.

## Consequences

- One request per keystroke pause: the client debounces it.
- Search results are serialized with the same groups as the lists (`search:read` plus `artist:read`, `album:read`
  and `track:read`): a field added to tracks shows in search results too.

## Origin

Asked from the Echora Web repository, for its header search (2026-11-09).

## Commits

- `b0a0153` feat: Implement search functionality to query artists, albums, and tracks with accent-insensitive matching
