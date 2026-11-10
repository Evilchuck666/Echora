# 0010. Tracks keep their identity when files are moved or renamed (audio hash)

- **Date:** 2026-11-10
- **Status:** Accepted

## Context

The scan identified a track by its file's path. Moving or renaming a file (or a whole folder) made it delete the
track and create a new one, with a new id: anything pointing to the track by id, as favourites and playlists were
about to ([0009](0009-favourites-and-playlists.md)), would lose it.

## Decision

A track is its file's audio, not its path. `TagReader::audioHash()` identifies the audio regardless of the tags and
the file name, and is stored in `Track::$audioHash` (not exposed):

- FLAC: `flac:` and the MD5 of the decoded audio that the encoder writes in STREAMINFO, read with the header at no
  extra cost.
- MP3: `mp3:` and the XXH128 of the audio frames, without the ID3 and APE tags, which means reading the whole file
  (only new and changed files are read).

`LibraryScanner` lists the folder first; then a new file whose hash matches exactly one missing track (or, when
several do, exactly one with the same title) takes over that track with `Track::moveTo()`: same id, new path and
tags (`ScanReport::$moved`). Otherwise the file is a new track.

## Consequences

- Anything that references tracks must use the track id, never the path.
- A copy is a new track, and so is a file whose audio changed (converted to another format, another master).
- Tracks scanned before this existed have no hash: `app:library:scan --full` once, before moving files.

## Origin

Designed in this repository by the owner, ahead of favourites and playlists. Recorded afterwards from the Echora
Web repository, from the commit and its documentation.

## Commits

- `c78eb9c` feat: implement audio hash for tracks to maintain references after file moves or renames
