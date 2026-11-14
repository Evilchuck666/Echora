# Decision records

Why the API is the way it is: one short record per decision that shaped it, with the problem it answered, what was
chosen (and what wasn't), what it costs, and the commit that made it. How things work today is in the
[README](../../README.md) and [CLAUDE.md](../../CLAUDE.md); these records keep the reasons, which the code doesn't.

Many of these changes were asked for while building the web client, [Echora Web](https://github.com/Evilchuck666/echora-web),
from its own repository and sessions: each record says where it came from. A new decision about the API gets a new
record here, whichever repository it was taken from; a record that no longer holds is marked superseded, not
deleted.

| # | Decision | Date | Status |
|---|---|---|---|
| [0001](0001-logout-and-serialized-refreshes.md) | Logout endpoint, refresh endpoint documented by path, one refresh per token at a time | 2026-11-09 | Accepted |
| [0002](0002-global-search.md) | One search endpoint for artists, albums and tracks, ignoring accents | 2026-11-09 | Accepted |
| [0003](0003-artist-images-and-covers-from-theaudiodb.md) | Artist images from the artist folder or TheAudioDB, served as thumbnails; missing album covers from TheAudioDB | 2026-11-09 | Accepted |
| [0004](0004-files-sent-by-caddy.md) | Audio and images sent by Caddy (X-Accel-Redirect), not by PHP | 2026-11-09 | Accepted |
| [0005](0005-web-client-in-the-production-image.md) | The production image builds and serves the web client, on the API's origin | 2026-11-10 | Accepted |
| [0006](0006-refresh-token-grace-period.md) | A 5-minute grace period for refresh tokens whose new pair was lost | 2026-11-10 | Accepted |
| [0007](0007-production-image-checked-in-ci.md) | CI builds the production image and smoke-tests it | 2026-11-10 | Accepted |
| [0008](0008-tracks-in-library-order.md) | Tracks and albums in the order of the library, and the `albumArtist` filter | 2026-11-10 | Accepted |
| [0009](0009-favourites-and-playlists.md) | Favourites of tracks, albums and artists, and playlists (ordered by sort keys, positions computed; each user orders their playlists), per user | 2026-11-10 | Accepted |
| [0010](0010-tracks-keep-their-identity-when-files-move.md) | Tracks keep their identity when files are moved or renamed (audio hash) | 2026-11-10 | Accepted |
| [0011](0011-opus-conversion-ahead-of-time.md) | Converting to Opus ahead of time, for the tracks a client is about to play, in background workers | 2026-11-10 | Partly superseded by 0012 |
| [0012](0012-opus-conversion-lists-per-device.md) | One list per device for converting ahead of time, and lists that take turns | 2026-11-10 | Accepted |
| [0013](0013-accounts-managed-through-the-api.md) | Accounts managed through the API (like Immich): the first one becomes the administrator; emails, names and pictures | 2026-11-11 | Accepted |
| [0014](0014-sign-in-throttling.md) | Sign-in throttling (429 with Retry-After), in a firewall of its own; wrong current passwords limited too | 2026-11-13 | Accepted |
| [0015](0015-library-scans-through-the-api.md) | Library scans through the API (a worker of their own), recorded with their reports, and every night | 2026-11-13 | Accepted |
| [0016](0016-listening-history.md) | Listening history: plays (deduplicated, offline-friendly), recently played and most played tracks | 2026-11-13 | Accepted |
| [0017](0017-replaygain.md) | ReplayGain tags read by the scanner (any-case TXXX too) and given to players per track | 2026-11-13 | Accepted |
| [0018](0018-database-backups.md) | Nightly database backups (pg_dump of the server's version, kept 14 days), restored in one transaction | 2026-11-13 | Accepted |
| [0019](0019-recently-added-albums.md) | Recently added albums, dated by when a scan first found them (`addedAt`, read from the UUIDv7 ids for existing ones) | 2026-11-14 | Accepted |

Not recorded here: Opus streaming for FLAC tracks (`e5a61c1`, 2026-11-10) was designed in this repository; its
README section and CLAUDE.md describe it.

## Format

Each record has a title, a date, a status (Proposed, Accepted, Superseded by NNNN, Partly superseded by NNNN), and these sections: **Context**
(the problem), **Decision** (what was chosen, and the alternatives weighed, when there were any), **Consequences**
(what it costs or constrains), **Origin** (who asked, from where) and **Commits**. Only reasons that were actually
given go in: a record doesn't make up alternatives after the fact.
