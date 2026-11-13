# 🎧 Echora

![Platform](https://img.shields.io/badge/platform-Web%20API-blue)
![PHP](https://img.shields.io/badge/PHP-8.5-blue)
![Symfony](https://img.shields.io/badge/Symfony-8.1-blue)
![PostgreSQL](https://img.shields.io/badge/PostgreSQL-16-blue)
![Requires](https://img.shields.io/badge/requires-Docker%20Compose-blue)
![Status](https://img.shields.io/badge/status-early%20development-orange)
[![License](https://img.shields.io/badge/license-GPLv3-green)](LICENSE)
[![Build and tests](https://github.com/Evilchuck666/Echora/actions/workflows/ci.yaml/badge.svg?branch=main)](https://github.com/Evilchuck666/Echora/actions/workflows/ci.yaml)

**Your own music streaming service, on your own server.**

Echora is a self-hosted music streaming service. This repository is its backend: an HTTP API that stores your music library and serves the tracks for any client to download and play.

---

## 🧩 Why Echora?

Streaming platforms decide which music is available, how it sounds and where you can listen to it. Echora serves your own collection instead, from a server you control.

The backend and the clients are separate projects that talk only through the API. A web client comes first, and native apps for different operating systems can be built later on the same endpoints, without changing the server.

---

## ✨ Features

- **Your music folder is the library**: Echora reads the tags of your FLAC and MP3 files and builds a catalog of artists, albums and tracks. The folder is mounted read-only, so your files are never modified.
- **Incremental scans**: rescanning only reads new or changed files, removes the ones you deleted, and refuses to empty the library if the music folder isn't mounted. Files you move or rename are recognised by their audio and keep their track.
- **Covers, artist images and lyrics**: album covers come from the album folder and artist images from the artist folder, or else from [TheAudioDB](https://www.theaudiodb.com); lyrics come from the tags.
- **Streaming with Range support**: tracks are served as they are, and clients can seek and start playing before the whole file has downloaded.
- **Opus for slow connections**: FLAC tracks can also be streamed converted to Opus (256 kbit/s, about a quarter of the size), converted on the first request and kept on the server. Clients can have the next tracks of their queue converted ahead of time, in the background, so they start at once.
- **Browsable API**: artists, albums and tracks with pagination, filters and a search across the whole library that ignores accents, in JSON-LD or plain JSON, documented with OpenAPI so clients can be generated from it.
- **User accounts**: sign in with a username and password, get a JWT and a refresh token that keeps apps signed in. Audio players get signed, expiring URLs, so an `<audio>` element can play tracks without sending any header.

---

## 🧱 Stack

| Part | Technologies |
|---|---|
| Backend (this repository) | PHP 8.5, Symfony 8.1, API Platform 5, Doctrine ORM, PostgreSQL 16, getID3, LexikJWT + refresh tokens |
| Web server | FrankenPHP (Caddy) with automatic HTTPS and HTTP/3 |
| Web client | [Echora Web](https://github.com/Evilchuck666/echora-web) (React + TypeScript), in a separate repository; in production, built into this server's image and served at the root of the site |
| Native apps | Planned, built on the same API |
| Deployment | Docker Compose, based on [Symfony Docker](https://github.com/dunglas/symfony-docker) |

---

## 🚀 Running with Docker Compose

You only need [Docker Compose](https://docs.docker.com/compose/install/) (v2.10+). PHP, Composer and PostgreSQL run inside the containers.

```sh
git clone https://github.com/Evilchuck666/Echora.git
cd Echora
docker compose build --pull
docker compose up --wait
```

The API is served at **https://localhost/api**. Accept the auto-generated TLS certificate the first time. Stop it with:

```sh
docker compose down --remove-orphans
```

Your music folder is mounted read-only from `~/Music`. If it lives somewhere else, start the stack with `MUSIC_DIR=/path/to/music docker compose up --wait`.

Import your music, then open the web client and create your account: the first one becomes the administrator (see [Accounts](#-accounts)).

```sh
docker compose exec php bin/console app:library:scan
```

---

## 🎵 Your music library

The library is scanned every night at 03:00 (UTC), so files you add, change or delete show up on their own. To scan at once, an administrator asks for it from a client (`POST /api/library/scan`, run in the background, one scan at a time), or from the console:

```sh
docker compose exec php bin/console app:library:scan
```

Every scan, whoever started it, is recorded with its report (added, moved, updated, unchanged, removed and unreadable files): `GET /api/library/scan` shows the latest one, `GET /api/library/scans` all of them, to administrators. While a scan runs, it shows how far it got: its `phase` (`listing` the files, reading the `files`, the album `covers`, then the `artist_images`, which can be slow as TheAudioDB allows 30 lookups a minute) and how many of the phase's files, albums or artists are done (`phaseDone` out of `phaseTotal`).

Echora reads FLAC and MP3 files in any folder layout, and uses these tags:

| Field | FLAC (Vorbis comment) | MP3 (ID3v2) | When it is missing |
|---|---|---|---|
| Title | `TITLE` | `TIT2` | The file name |
| Artist | `ARTIST` | `TPE1` | The album artist, or "Unknown Artist" |
| Album artist | `ALBUMARTIST` | `TPE2` | The artist |
| Album | `ALBUM` | `TALB` | "Unknown Album" |
| Year | `DATE` | `TYER` / `TDRC` | Empty |
| Track and disc number | `TRACKNUMBER`, `DISCNUMBER` | `TRCK`, `TPOS` | Empty |
| Genre | `GENRE` | `TCON` | Empty |
| Lyrics | `LYRICS` / `UNSYNCEDLYRICS` | `USLT` | Empty |

Albums are grouped by album artist and title, so a compilation tagged with `ALBUMARTIST=Various Artists` stays one album while each track keeps its own artist.

The cover of an album is the first image of its folder named `folder`, `cover`, `front` or `album` (in that order, any case), in JPEG, PNG or WebP. Covers are looked up again on every scan, so adding or replacing one only takes a scan.

The image of an artist is the first one named `artist` or `folder` (in that order, any case; JPEG, PNG or WebP) in the artist folder: the folder that holds all of the artist's album folders, like `Accept/` for `Accept/1983 - Balls to the Wall/`. Artists whose albums are spread around or sit at the root of the library have no artist folder, and neither do artists who only appear on tracks of other artists' albums.

Albums without a cover in their folder and artists without an image of their own are looked up on [TheAudioDB](https://www.theaudiodb.com), and the cover or photo is downloaded (images by TheAudioDB's community; see its [terms](https://www.theaudiodb.com/docs_terms_of_use.php)). Only an exact match is taken: same album artist and album title (or artist name), ignoring case and accents. A scan sends at most 30 lookups a minute, as TheAudioDB's free key allows, so the first scan of a large library without covers can take a while; an album or artist that wasn't found is asked again a month later, and a cover added to the album folder later replaces the downloaded one. Scan with `--offline` to skip the lookups, or set `THEAUDIODB_API_KEY` empty (in `.env.local`, or in `prod.env` in production) to never contact TheAudioDB.

The API serves artist images as thumbnails: square JPEGs of at most 300 pixels a side, made on the first request and kept in `var/media`.

A scan skips the files that didn't change. After updating Echora to a version that reads more tags, read every file again with `app:library:scan --full`.

Moving or renaming files (or whole folders) doesn't lose anything: a scan recognises a file by its audio, whatever its path, name or tags, and keeps its track with the new path and tags, so whatever points to the track stays valid. FLAC files carry a checksum of their audio, written by the encoder; for MP3 files, the scan reads the audio frames, without the tags. A copy is a new track, and so is a file whose audio changed (converted to another format, or replaced by another master). When several missing tracks have exactly the same audio (e.g. silent tracks), the one with the same title is chosen; if that doesn't tell them apart, the file is added as a new track.

Tracks scanned by Echora versions older than this feature aren't recognised yet: run `app:library:scan --full` once, before moving files.

---

## 👤 Accounts

Like Immich, a new server has no account: whoever opens it first creates the **administrator** (`GET /api/setup` says whether that is still to do, `POST /api/setup` does it, and signs them in). There is no sign-up afterwards: administrators create the other accounts (`/api/users`), and can change, demote or delete them. The server always keeps at least one administrator, and an administrator can't delete their own account.

An account has an **email** and a **name** (shown to others), given when it is created, and a **username** made from the email (`ana` for `ana@example.com`, `ana-2` if it is taken). It signs in with its email or its username. Everyone can change their own name, username, email, password and profile picture (`/api/me`). Sessions name accounts by their id, so renaming one keeps it signed in; a new password signs it out of every other device.

Profile pictures are JPEG, PNG or WebP files of up to 5 MB, cropped to a square of up to 300×300 and stored as JPEG (without their metadata) in the media folder.

The console commands remain, to get back in when no administrator can (like `immich-admin`). Passwords are always typed in a hidden prompt, never passed as arguments, so they don't end up in your shell history.

| Command | What it does |
|---|---|
| `app:user:create <email> [--name=...] [--admin]` | Creates an account (its username comes from the email) |
| `app:user:list` | Lists the accounts |
| `app:user:password <username or email>` | Changes a password and signs the account out of every device |
| `app:user:delete <username or email>` | Deletes an account, its playlists, favourites and picture, and signs it out |

Run them with `docker compose exec php bin/console ...`.

---

## 🔌 API

Open **https://localhost/api** in a browser to explore every endpoint. The OpenAPI specification is at `/api/docs.jsonopenapi`. Both are public; everything else needs authentication.

### Authentication

1. `POST /api/auth` with `{"username": "...", "password": "..."}` (the username or the email) returns a `token` (a JWT, valid for one hour) and a `refresh_token` (valid for 30 days). After 5 failed attempts for an account from one address, or 25 from one address whatever the accounts, in 15 minutes, sign-ins are refused for a while (`429`, with a `Retry-After` header in seconds), even with the right password; a successful sign-in clears the account's count. Wrong current passwords given to `POST /api/me/password` are limited the same way, 5 per account. Behind another reverse proxy, set `TRUSTED_PROXIES` in `prod.env` (see below).
2. Send the JWT on every request: `Authorization: Bearer <token>`. In the documentation page, use the **Authorize** button.
3. Before it expires, `POST /api/token/refresh` with `{"refresh_token": "..."}` returns a new pair. Each refresh token works once; presenting one that was already used signs that session out, as it may have been stolen. That includes two requests sending the same token at once: only one gets a new pair, so a client running in several places at the same time (e.g. browser tabs) has to let only one of them refresh.
   One exception covers a refresh whose response never arrived (the page was reloaded or closed, the network dropped): for 5 minutes (`REFRESH_TOKEN_GRACE_SECONDS`), the spent token still works as long as the one that replaced it hasn't been used, and returns a new pair. Theft is still detected: whoever holds the replaced pair is signed out as soon as they use it.
4. To sign out, `POST /api/logout` with `{"refresh_token": "..."}`: the session can't be renewed any more. It needs no JWT, so it also works once the JWT has expired. A JWT already issued keeps working until it expires (one hour at most).

Every track includes a `streamUrl`: a signed link to its audio, valid for six hours, that works without the `Authorization` header. Use it as the `src` of an `<audio>` element or hand it to a native media player. When it expires, fetch the track again for a new one.

FLAC tracks also include an `opusStreamUrl`, signed and valid like `streamUrl`: the same audio converted to Opus (Ogg, 256 kbit/s), about a quarter of the size of the FLAC file, for mobile data or slow connections. The first request for a track waits for the conversion (a few seconds for a song); the result is kept in `var/media`, so later requests start at once. MP3 tracks aren't converted (an MP3 would lose quality twice) and have no `opusStreamUrl`: play their `streamUrl`.

To avoid that wait, a client that plays the Opus versions sends the next tracks of its queue to `POST /api/transcodes` (see below): the server converts them in the background, so they are ready when the player asks for them.

Likewise, every album (also when embedded in a track) includes a `coverUrl`, and so does every artist (a thumbnail of its image); it is left out when there is no image. Use it as the `src` of an `<img>`: it stays the same for a day, so browsers cache the image.

### Endpoints

| Endpoint | What it returns |
|---|---|
| `POST /api/auth` | A JWT and a refresh token |
| `POST /api/token/refresh` | A new JWT and refresh token |
| `POST /api/logout` | Invalidates a refresh token (signs that session out) |
| `GET /api/setup` | Whether the server still needs its first account (public) |
| `POST /api/setup` | Creates the first account, the administrator, and signs it in (public; 409 once there is an account) |
| `GET /api/me` | The authenticated account: `id`, `username`, `email`, `name`, `admin`, `avatarUrl` |
| `POST /api/library/scan` | Scans the music library in the background: `{}`, or `{"full": true}` (202; 409 while a scan is queued or running; administrators) |
| `GET /api/library/scan` | The latest scan: `status` (`queued`, `running`, `finished`, `failed`), its progress while running (`phase`, `phaseDone`, `phaseTotal`) and its report (administrators) |
| `GET /api/library/scans` | Every scan, the latest first (administrators) |
| `PATCH /api/me` | Changes your `name`, `username` or `email` |
| `POST /api/me/password` | Changes your password (`currentPassword`, `newPassword`): signs out your other devices, returns a new token pair |
| `POST`, `DELETE /api/me/avatar` | Sets (`multipart/form-data`, field `file`) or removes your profile picture |
| `GET /api/users/{id}/avatar` | A profile picture. Use the account's `avatarUrl` |
| `GET`, `POST /api/users` | Every account; creates one: `{"email", "name", "password", "admin"?}` (administrators) |
| `GET`, `PATCH`, `DELETE /api/users/{id}` | One account; `PATCH` changes `name`, `username`, `email`, `admin` or `password` (administrators) |
| `POST`, `DELETE /api/users/{id}/avatar` | Sets or removes the picture of any account (administrators) |
| `GET /api/artists` | Artists, by name. Filter: `name` |
| `GET /api/albums` | Albums in the order of the library: album artists by name, then each one's albums by year (undated ones last) and title. Filters: `artist` (IRI: one artist's albums, by year and title), `title`, `year` |
| `GET /api/tracks` | Tracks in the order of the library: album artists by name, their albums by year and title, then disc and track number. Filters: `album` (IRI), `artist` (IRI of the track artist), `albumArtist` (IRI: every track of an artist's own albums), `title`, `genre` |
| `GET /api/search?q=...` | Artists, albums and tracks matching a search, at once (see below) |
| `GET /api/{artists,albums,tracks}/{id}` | One item. A single track also includes its `lyrics`; lists only say `hasLyrics` |
| `GET /api/tracks/{id}/stream` | The audio file, with HTTP Range support. Use the track's `streamUrl` |
| `GET /api/tracks/{id}/stream/opus` | The audio of a FLAC track converted to Opus, with HTTP Range support. Use the track's `opusStreamUrl` |
| `POST /api/transcodes` | Converts the next tracks of a queue to Opus in the background (202 at once; see below) |
| `GET /api/albums/{id}/cover` | The cover image. Use the album's `coverUrl` |
| `GET /api/artists/{id}/cover` | A thumbnail of the artist's image (JPEG, up to 300×300). Use the artist's `coverUrl` |
| `PUT /api/{tracks,albums,artists}/{id}/favourite` | Adds the item to your favourites (204; adding it twice is fine) |
| `DELETE /api/{tracks,albums,artists}/{id}/favourite` | Removes it from your favourites (204; also when it wasn't one) |
| `GET /api/favourites/{tracks,albums,artists}` | Your favourites of that kind, newest first |
| `POST /api/tracks/{id}/plays` | Records that you listened to a track (204; optional `{"playedAt": "..."}`) |
| `GET /api/me/recent-tracks` | The tracks you played, the most recently played first, with `playCount` and `lastPlayedAt` |
| `GET /api/me/top-tracks?period=...` | The tracks you played most (`week`, `month`, `year` or `all`, the default) |
| `DELETE /api/me/history` | Forgets your listening history |
| `GET /api/playlists` | Your playlists, in the order you gave them (a new one comes first) |
| `POST /api/playlists` | Creates an empty playlist: `{"name": "..."}` (201) |
| `GET`, `PATCH`, `DELETE /api/playlists/{id}` | One of your playlists; `PATCH` renames it (`{"name": "..."}`), moves it (`{"position": n}`) or both |
| `GET /api/playlists/{id}/items` | Its items in order, each with its position and whole track |
| `POST /api/playlists/{id}/items` | Adds tracks at the end: `{"tracks": [...]}` (all or none) |
| `PATCH /api/playlist_items/{id}` | Moves an item: `{"position": n}` |
| `DELETE /api/playlist_items/{id}` | Removes an item (that appearance of the track only) |

Responses are JSON-LD by default; send `Accept: application/json` for plain JSON. `PATCH` requests send their body as `application/merge-patch+json`.

### Favourites

Each account keeps its own favourite tracks, albums and artists; no one else sees them. Every track, album and artist the API returns, in lists, single items and search results alike, says whether it is one of yours (`"favourite": true`), so a client can show it without asking again. A favourite goes away with its item (a track whose file was deleted, an album left without tracks); moving or renaming files keeps them, as tracks are recognised by their audio.

### Converting ahead of time

`POST /api/transcodes` with `{"device": "<uuid>", "tracks": ["<id or /api/tracks/id>", ...]}`, the next tracks of the device's queue in the order they will play (at most 20), answers `202 Accepted` at once and converts them to Opus in the background, one after the other, next one first. `device` is a UUID that the client generates once (e.g. `crypto.randomUUID()`) and keeps. The answer says where each FLAC track stands:

```json
{"queued": ["<id>", "<id>"], "ready": ["<id>"], "converting": []}
```

`queued` will be converted in that order, `ready` already were (their `opusStreamUrl` starts at once), `converting` are being converted right now. MP3 tracks and unknown ids are in none of them. A missing `device` or one that isn't a UUID, more than 20 tracks, or something that is neither a track id nor its IRI, is a `422`.

Each device of an account has its own list, so a phone and a tablet with the same account don't replace each other's. A request replaces the previous one of the same device: tracks of the older list that aren't converted yet are dropped, so send the whole upcoming list whenever the queue changes, and an empty list to cancel. An account keeps the lists of its 5 most recent devices; those of older ones are dropped. The lists of every device and account take turns, one track each, so no one waits for someone else's whole list. Tracks asked for this way that are already converted count as played, so `app:transcodes:prune` keeps them.

The conversions run in background workers (Symfony Messenger, with its queue in PostgreSQL) that the `php` container starts next to FrankenPHP, never in the PHP workers that answer requests. `TRANSCODE_WORKERS` (1 by default) sets how many tracks are converted at once: each conversion takes one CPU core for a few seconds; `0` turns converting ahead of time off.

### Listening history

Each account keeps its own listening history; no one else sees it. A client records a play with `POST /api/tracks/{id}/plays` once a track counts as listened to: when it ends, or once half of it (or 4 minutes) has played, whichever comes first. A play of the same track within 30 seconds of another counts once, so a client can retry safely (a track shorter than that, played twice in a row, counts once too). A client that played offline sends `{"playedAt": "<ISO 8601>"}` when it reconnects (within the last year, not in the future).

`GET /api/me/recent-tracks` lists the tracks played, each once, the most recently played first; `GET /api/me/top-tracks` the most played first, over the last `week`, `month` (30 days), `year` or `all` of the history (`?period=`, `all` by default). Each item is `{"track": {...}, "playCount": n, "lastPlayedAt": "..."}`, the track as `GET /api/tracks` returns it, 30 per page. `DELETE /api/me/history` forgets every play. Plays go with their track (a file deleted from the library) and with the account; moving or renaming files keeps them.

### Playlists

Each account has its own playlists; no one else sees them (someone else's playlist, or one of its items, is a 404, as if it didn't exist). A playlist has a name (1 to 100 characters, without spaces at the start or the end, not necessarily unique), its creation and last change dates, and, in every response, its number of items (`trackCount`), their total length (`durationMs`, the tracks whose length is known) and up to four `covers`: the signed cover URLs of the first four different albums with a cover, in the order of the playlist, for a mosaic. `updatedAt` changes with a new name or any change to the items, including a scan removing one of its tracks.

Each user orders their playlists by hand, and the order is the same on every device: `GET /api/playlists` lists them in that order, each with its `position` (index from 0, always consecutive, also after one is deleted). A new playlist comes first (position 0) and the others move down by one. `PATCH /api/playlists/{id}` with `{"position": n}` (alone or with `name`) moves it there and shifts the ones in between; beyond the end means the end, a negative position is a `422`. Moving a playlist doesn't change its `updatedAt`. Existing playlists kept the order they had before (last changed first).

The items of a playlist are tracks in an order, at most 10 000:

- the same track may appear several times: each appearance is an item with its own `id`, which is what `PATCH` and `DELETE /api/playlist_items/{id}` act on;
- `position` is the index of an item in the playlist, from 0, always consecutive: it changes when items before it are added, moved or removed;
- `POST /api/playlists/{id}/items` with `{"tracks": [ids or IRIs]}` adds them all at the end, in that order (a whole album in one request), or none if one doesn't exist or the playlist would exceed 10 000 items (`422`); it answers with the playlist;
- `PATCH /api/playlist_items/{id}` with `{"position": n}` moves the item there and shifts the ones in between; beyond the end means the end, a negative position is a `422`;
- `DELETE /api/playlist_items/{id}` removes that appearance only.

A track that a scan removes disappears from every playlist, and the others keep their order. Moving or renaming files keeps them, as tracks are recognised by their audio. Changes to the same playlist, and to the order of a user's playlists, are made one at a time, so two tabs or devices changing them at once never mix up the order. Positions are indexes in the order the API has when each request arrives: a client sends its moves one at a time, waiting for each answer, or a later move may be applied first and land one place off.

### Search

`GET /api/search?q=motorhead ace` searches the whole library in one request. Every word has to appear, in any order, ignoring case and accents (`motorhead` finds Motörhead):

- artists match by name, albums by title or artist, tracks by title, artist or album;
- each list is ranked by how well the item's own name matches: the same as the query, starting with it, containing every word, then the items only found through their artist or album;
- `artists`, `albums` and `tracks` hold at most `limit` items each (10 by default, up to 50), and `totalArtists`, `totalAlbums` and `totalTracks` count every match.

The filters of the lists (`name`, `title`) still match one field, and don't ignore accents.

Browsers only let web clients on other origins call the API when `CORS_ALLOW_ORIGIN` allows them. In development it allows `localhost` on any port, for the web client's dev server. In production the web client is served from the same origin as the API and needs no CORS, so no other site is allowed unless you set `CORS_ALLOW_ORIGIN` in `prod.env`.

---

## 🧰 Trying the API with Bruno

The `bruno/` folder is a [Bruno](https://www.usebruno.com/) collection with every endpoint, ready to run:

1. Copy `bruno/.env.example` to `bruno/.env` (git-ignored) and set the username and password of your account.
2. Open the collection: the Bruno extension for VS Code finds it on its own; in the Bruno app, use **Open Collection** and pick the `bruno/` folder.
3. Select the **Local** environment and send any request: the collection signs in on its own when it has no valid JWT. **3 Library / List tracks** remembers a track for the streaming requests; in the VS Code extension, variables don't travel between request tabs, so use the **Runner** to chain them.

`https://localhost` uses a certificate signed by Caddy's local authority. Export it and set it in Bruno under **Preferences → General → Use custom CA certificate**:

```sh
docker compose cp php:/data/caddy/pki/authorities/local/root.crt ~/echora-local-ca.crt
```

To import the API into another tool instead, use `https://localhost/api/docs.jsonopenapi?spec_version=3.0.0`: by default the specification is OpenAPI 3.2, which many importers don't support yet.

---

## 🌍 Production

The production image serves the whole of Echora on your domain: the [web client](https://github.com/Evilchuck666/echora-web) at `https://your-domain/` and the API at `https://your-domain/api`. The web client is built into the image from a checkout of its repository next to this one (`../echora-web`; set `WEB_DIR` in `prod.env` if it lives elsewhere):

```sh
git clone https://github.com/Evilchuck666/Echora.git
git clone https://github.com/Evilchuck666/echora-web.git
cd Echora
```

Copy `prod.env.example` to `prod.env`, set your music folder, domain and secrets, then build and start the production images and scan your library:

```sh
cp prod.env.example prod.env
docker compose --env-file prod.env -f compose.yaml -f compose.prod.yaml build --pull
docker compose --env-file prod.env -f compose.yaml -f compose.prod.yaml up --wait
docker compose --env-file prod.env -f compose.yaml -f compose.prod.yaml exec php bin/console app:library:scan
```

`prod.env` is ignored by git. Caddy gets a TLS certificate for your domain automatically. If another reverse proxy (nginx, Traefik, Cloudflare...) sits in front of Echora, set `TRUSTED_PROXIES` to its address: sign-in attempts are limited per address, and without it every client would share the proxy's. Open `https://your-domain/` and create your account: the first one becomes the administrator. Do it right away, as until then whoever reaches the server first could; or create it from the console before opening the site, with `... exec php bin/console app:user:create <email> --admin`.

To update, pull both repositories and run the `build` and `up` commands again: the image is rebuilt with the new API and web client, and migrations run on start.

After deploying, check the site from any machine with `scripts/smoke-test-prod.sh https://your-domain <username> <password>`. It needs an account and a scanned library, and leaves the library untouched (it signs in and out once, runs a quick scan when the account is an administrator, and makes 6 failed sign-ins with a made-up account to check the throttling, which leaves the real one alone). If the account has no profile picture, it gives it one for a moment and removes it.

Spent refresh tokens are remembered in the database (the `cache_items` table) for 30 days, to recognise a stolen one.

The `php` container runs its own scheduled jobs (`frankenphp/crontab`, with [Supercronic](https://github.com/aptible/supercronic)), so the host needs no cron. Times are in UTC:

- every night at 03:00, `app:library:scan --scheduled` scans the music library (skipped when a scan is already queued or running);
- every Sunday at 04:00, `app:transcodes:prune` deletes the Opus versions that nobody played in the last 30 days, and those of tracks no longer in the library;
- every Sunday at 04:30, `cache:pool:prune` deletes expired cache entries, such as spent refresh tokens older than 30 days.

Their output is in the container's logs (`docker compose ... logs php`), and so is that of the background workers: the one that runs the scans asked for through the API, and those that convert tracks to Opus ahead of time (`TRANSCODE_WORKERS` in `prod.env`, 1 by default). Run the jobs by hand at any time with the `docker compose ... exec php bin/console` prefix; `app:transcodes:prune --days=7` keeps only the last week.

The key pair that signs the JWTs is generated on the first start and kept in the `jwt_keys` volume. Keep that volume: if it is lost, new keys are generated and every account has to sign in again.

Album covers and artist images downloaded from TheAudioDB, the thumbnails and the Opus versions of tracks are kept in the `media` volume. Losing it costs nothing but time: thumbnails and Opus files are made again on request, and the next scan downloads the images again. Opus files take about 1.9 MB per minute of music, but only for the tracks played in the last 30 days.

A `media` volume created by Echora versions older than this guide belongs to root, and Echora can't write to it (artist thumbnails fail with an error 500). Give it to Echora's user once:

```sh
docker compose --env-file prod.env -f compose.yaml -f compose.prod.yaml run --rm --user 0 --entrypoint chown php -R 33:0 /app/var/media
```

---

## 🛠️ Development

Run PHP, Composer and console commands inside the `php` container:

```sh
docker compose exec php bin/console about
docker compose exec php composer require <package>
```

On Linux, files created from inside the container belong to root. Give them back to your user with:

```sh
./scripts/fix-permissions.sh
```

The background workers (`messenger:consume transcodes` and `messenger:consume library`) are long-running processes that keep the classes they loaded: after changing code they run (the Opus conversions: `TranscodeTracksHandler`, `TranscodeQueue`, `OpusTranscoder`, the `TranscodeTracks` message; the scans: `ScanLibraryHandler`, `LibraryScans`, `LibraryScanner` and what it uses), run `docker compose restart php` so they start again with it. `docker compose up --build` doesn't recreate the container when the image didn't change. `messenger:stop-workers` isn't enough: an idle worker only sees it when the next message wakes it up, and handles that message with the old code first. A change to `frankenphp/docker-entrypoint.sh` or `frankenphp/crontab` needs `docker compose up --build --wait`.

To debug with Xdebug, start the stack with `XDEBUG_MODE=develop,debug docker compose up --wait` and run the **Debug PHP (Docker)** configuration in VS Code.

Why the API is built the way it is (and where each change came from) is recorded in [docs/decisions](docs/decisions/README.md): one short record per decision, with its commits.

---

## 🧪 Tests and code quality

The tests run against a separate `app_test` database. Create it once, and migrate it after pulling new migrations:

```sh
docker compose exec php bin/console -e test doctrine:database:create
docker compose exec php bin/console -e test doctrine:migrations:migrate
```

They scan the small library in `tests/Fixtures/music` (one second of silence per file), never your own music.

Then run every check the CI runs, or each one on its own:

| Command | What it does |
|---|---|
| `docker compose exec php composer check` | Runs `cs`, `phpstan` and `test`, in that order |
| `docker compose exec php composer cs` | Checks the coding style ([`@Symfony`](https://cs.symfony.com/doc/ruleSets/Symfony.html)) without changing files |
| `docker compose exec php composer cs:fix` | Fixes the coding style |
| `docker compose exec php composer phpstan` | Static analysis with [PHPStan](https://phpstan.org/) at level `max` |
| `docker compose exec php composer test` | Runs the [PHPUnit](https://phpunit.de/) test suite |

GitHub Actions (`.github/workflows/ci.yaml`) builds the Docker images and runs the same checks, plus the Doctrine migrations and schema validation, on every push to `main` and on pull requests.

A second job builds the **production image**, with the web client from [echora-web](https://github.com/Evilchuck666/echora-web)'s `main` branch (another branch, tag or commit when started by hand), starts it with the test library, and runs `scripts/smoke-test-prod.sh` against it: the web client at the root of the site, the API, signing in, audio and images sent by Caddy, a track converted to Opus by the background workers, CORS closed.

---

## 🚧 Status

Echora is in early development. The library, the browsing API, streaming, search and user accounts work, and the [web client](https://github.com/Evilchuck666/echora-web) browses, searches and plays the library.

---

## ⚖️ License

This project is licensed under the GPLv3 License (GPL-3.0-only) — see the [LICENSE](LICENSE) file for details.
