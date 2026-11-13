# 0017. ReplayGain tags read by the scanner and given to players

- **Date:** 2026-11-13
- **Status:** Accepted

## Context

Tracks from different albums and masters play at very different loudness, so the volume jumps from one song to the
next. Well-tagged libraries carry ReplayGain tags that measure it, but the scanner ignored them. The owner chose this
as the next step for the API.

## Decision

- **Reading.** The scanner reads the ReplayGain tags:
  - track and album gain in dB, and the track and album peak (1.0 = full scale), as getID3 gathers them: Vorbis
    comments in FLAC files; `RVA2` frames and the LAME header in MP3 files;
  - also the MP3's `TXXX` frames whatever the case of their description. getID3 only knows `replaygain_track_gain`
    in lower case, while foobar2000 and Mp3tag write it in upper case;
  - values written as text ("-6.48 dB", even with a decimal comma). Absurd ones (beyond ±60 dB, a peak of 0 or above
    10) are dropped as broken tags.
- **Exposing.** Each track exposes them as one `replayGain` object (`trackGain`, `trackPeak`, `albumGain`,
  `albumPeak`).
  - It carries only the values the file has, and is left out when it has none.
  - Album gain stays on each track, as tags are per file and a player applies it to each track of an album. The
    album resource has none.
- **Applying.** The API doesn't apply anything: players do.
  - A player multiplies the volume by 10^(gain/20), and never by more than 1/peak.
  - It uses the track gain for a mix of tracks, the album gain for a whole album.
  - The documentation says so, and that positive gains need the Web Audio API in a browser.
  - The Opus versions keep the loudness of the original, so the same values apply.
- **Not read:** EBU R128 tags (`R128_TRACK_GAIN`) and computing loudness when there are no tags. Echora reads tags,
  it doesn't analyse audio.

## Consequences

- Tracks scanned before get the tags only with a full scan (`app:library:scan --full`, or `POST /api/library/scan`
  with `{"full": true}`), once. On the owner's library, all 236 tracks got a track gain, and none an album gain.
- After this change, the library worker had to be restarted to read the tags (`docker compose restart php`), like any
  change to code the workers run.
- Fixtures with ReplayGain tags live in `tests/Fixtures/replaygain`, outside the test library, so its track counts
  don't change.

## Origin

Proposed from the Echora repository among the next steps for the API, and chosen by the owner (2026-11-13).

## Commits

- Not committed yet.
