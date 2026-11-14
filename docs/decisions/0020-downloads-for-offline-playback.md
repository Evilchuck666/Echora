# 0020. Downloads for offline playback: manifests, in the client's format

- **Date:** 2026-11-14
- **Status:** Accepted

## Context

The owner wanted tracks, albums, artists and playlists to be downloadable. The first plan had two parts:
- manifests of the files, for the native apps;
- ZIP archives built in the background, for the web client.

The owner didn't see the point of downloads in a browser, and asked what other services do. The answer, from
memory:
- Spotify only downloads inside its apps, to play offline, never as files and never on the web;
- Plex and Jellyfin let their web clients download a single original file, while their apps download to play offline;
- Navidrome's web client downloads ZIPs.

The owner also wanted downloads to follow the client's "Data saver": Opus when it is on, the original files
otherwise.

## Decision

- **Downloading is for apps playing offline, as in Spotify.** There are no archives, no background jobs and no extra
  disk; the web client doesn't download.
- **Manifests:** `GET /api/{tracks,albums,artists,playlists}/{id}/download` returns what to download.
  - Each track comes once, in order, with a signed URL to its file, its format, its size, and where to save it
    (`savePath`, "Album Artist/Year - Album/NN - Title.ext", safe on every file system), plus the covers of the
    albums.
  - An artist's manifest has their own albums and their tracks on other artists' albums.
  - A playlist's adds `items`, the track of each item in order, to rebuild it. Only its owner gets it (404 for
    anyone else).
  - The manifests are built in a fixed number of queries.
- **Format:** the client says which it wants (`?format=original|opus`), as the "Data saver" is a setting of each
  device, which the API doesn't know.
  - With `opus`, FLAC tracks point to their Opus version and MP3 tracks stay as they are (they aren't converted).
  - `format` tells what each file is.
  - An Opus file not converted yet has no `size`, and its first download converts it, as playing it does.
- **Files:** `GET /api/tracks/{id}/file` and `/file/opus` serve the same files as streaming.
  - They come as attachments with a readable name (`Content-Disposition`, with an ASCII fallback), served by Caddy
    with byte ranges, so the app downloads them one by one and resumes a download cut short.
  - Their URLs are signed for a day, not six hours, as downloading a whole artist can take long.
  - Manifests live at `/download` and files at `/file`, because one path couldn't be both for a single track.

## Consequences

- Apps write their own download queue (parallel downloads, resuming, storage, the "Data saver" choice). The API only
  says what to fetch and where to put it.
- Downloading a large artist in Opus converts every FLAC track on first download, one request at a time. Apps can
  have the next ones converted ahead with `POST /api/transcodes`.
- The production smoke test checks that a downloaded file keeps its `attachment` header through Caddy.

## Origin

Asked from the Echora repository by the owner (2026-11-14), who dropped the archives for the web and chose the format
by the client's "Data saver".

## Commits

- Not committed yet.
