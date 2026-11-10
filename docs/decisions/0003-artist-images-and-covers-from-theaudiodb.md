# 0003. Artist images from the artist folder or TheAudioDB, served as thumbnails; missing album covers from TheAudioDB

- **Date:** 2026-11-09
- **Status:** Accepted

## Context

Albums had covers (`GET /api/albums/{id}/cover`), artists had nothing, and the web client's artist list and search
results wanted a round thumbnail per artist. Many libraries have no artist images on disk, and some albums have no
cover file either.

## Decision

- `GET /api/artists/{id}/cover`, mirroring the album endpoint, with a signed `coverUrl` on each artist.
- The image comes from the artist folder (the folder holding all of the artist's album folders), named `artist` or
  `folder` (`CoverFinder::ARTIST_NAMES`). The owner chose these names.
- Artists without a local image are looked up on TheAudioDB during the scan (`ArtistImages`, `TheAudioDb`); the
  image is downloaded into the media folder. One not found there is asked again a month later, not on every scan.
  The same applies to albums without a local cover (`AlbumCovers`).
- What is served is a small square JPEG thumbnail (`Thumbnails`), made on the first request and kept on disk, named
  after the source's path, size and modification time, so a replaced image gets a new one. The owner chose resized
  thumbnails over the original files.

## Consequences

- The media folder (`/app/var/media`, a volume in production) must be writable by PHP; see
  [0005](0005-web-client-in-the-production-image.md) for the ownership bug this caused.
- TheAudioDB needs an API key (`THEAUDIODB_API_KEY`); `app:library:scan --offline` skips it.
- An artist with no folder of their own (albums spread around, or a compilation) only gets an image from TheAudioDB.

## Origin

Asked from the Echora Web repository, before building its search, so results could show artists with an image
(2026-11-09). The owner chose the file names, the thumbnails and the TheAudioDB fallback when asked.

## Commits

- `71af83c` feat: Add artist image handling from TheAudioDB
- `cdd5fd2` feat: Enhance album cover management with TheAudioDB integration
