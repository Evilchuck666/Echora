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

> 🚧 **Status:** Echora is in early development. The library, the browsing API, streaming, search and user accounts work, and the [web client](https://github.com/Evilchuck666/echora-web) browses, searches and plays the library.

🌐 **Website:** https://evilchuck666.github.io/Echora/

## 🧩 Why Echora?

Streaming platforms decide which music is available, how it sounds and where you can listen to it. Echora serves your own collection instead, from a server you control.

The backend and the clients are separate projects that talk only through the API. A web client comes first, and native apps for different operating systems can be built later on the same endpoints, without changing the server.

## ✨ Features

- **Your music folder is the library**: Echora reads the tags of your FLAC and MP3 files and builds a catalog of artists, albums and tracks. The folder is mounted read-only, so your files are never modified.
- **Incremental scans**: rescanning only reads new or changed files, removes the ones you deleted, and refuses to empty the library if the music folder isn't mounted. Files you move or rename are recognised by their audio and keep their track.
- **Covers, artist images and lyrics**: album covers come from the album folder and artist images from the artist folder, or else from [TheAudioDB](https://www.theaudiodb.com); lyrics come from the tags.
- **Streaming with Range support**: tracks are served as they are, and clients can seek and start playing before the whole file has downloaded.
- **Opus for slow connections**: FLAC tracks can also be streamed converted to Opus (256 kbit/s, about a quarter of the size), converted on the first request and kept on the server. Clients can have the next tracks of their queue converted ahead of time, in the background, so they start at once.
- **Browsable API**: artists, albums and tracks with pagination, filters and a search across the whole library that ignores accents, in JSON-LD or plain JSON, documented with OpenAPI so clients can be generated from it.
- **User accounts**: sign in with a username and password, get a JWT and a refresh token that keeps apps signed in. Audio players get signed, expiring URLs, so an `<audio>` element can play tracks without sending any header.

## 🚀 Getting started

You only need [Docker Compose](https://docs.docker.com/compose/install/) (v2.10+). PHP, Composer and PostgreSQL run inside the containers.

```sh
git clone https://github.com/Evilchuck666/Echora.git
cd Echora
docker compose build --pull
docker compose up --wait
```

The API is served at **https://localhost/api**. Accept the auto-generated TLS certificate the first time. The first start also generates the secrets of your machine in `.env.local`, which git ignores. Stop it with:

```sh
docker compose down --remove-orphans
```

Your music folder is mounted read-only from `~/Music`. If it lives somewhere else, start the stack with `MUSIC_DIR=/path/to/music docker compose up --wait`.

Import your music, then open the web client and create your account: the first one becomes the administrator (see [Accounts](#-accounts)).

```sh
docker compose exec php bin/console app:library:scan
```

## 🎵 Your music library

The library is scanned every night at 03:00 (UTC), so files you add, change or delete show up on their own. To scan at once, an administrator asks for it from a client (`POST /api/library/scan`, run in the background, one scan at a time), or from the console:

```sh
docker compose exec php bin/console app:library:scan
```

Albums are grouped by album artist and title, so a compilation tagged with `ALBUMARTIST=Various Artists` stays one album while each track keeps its own artist.

The cover of an album is the first image of its folder named `folder`, `cover`, `front` or `album` (in that order, any case), in JPEG, PNG or WebP. Covers are looked up again on every scan, so adding or replacing one only takes a scan.

The image of an artist is the first one named `artist` or `folder` (in that order, any case; JPEG, PNG or WebP) in the artist folder: the folder that holds all of the artist's album folders, like `Accept/` for `Accept/1983 - Balls to the Wall/`. Artists whose albums are spread around or sit at the root of the library have no artist folder, and neither do artists who only appear on tracks of other artists' albums.

The tags Echora reads, ReplayGain, covers and photos from TheAudioDB, thumbnails and how moved files keep their track are explained in the [technical guide](TECHNICAL_GUIDE.md#your-music-library).

## 👤 Accounts

A new server has no account: whoever opens it first creates the **administrator** (`GET /api/setup` says whether that is still to do, `POST /api/setup` does it, and signs them in). There is no sign-up afterwards: administrators create the other accounts (`/api/users`), and can change, demote or delete them. The server always keeps at least one administrator, and an administrator can't delete their own account.

An account has an **email** and a **name** (shown to others), given when it is created, and a **username** made from the email (`ana` for `ana@example.com`, `ana-2` if it is taken). It signs in with its email or its username. Everyone can change their own name, username, email, password and profile picture (`/api/me`). Sessions name accounts by their id, so renaming one keeps it signed in; a new password signs it out of every other device.

Profile pictures are JPEG, PNG or WebP files of up to 5 MB, cropped to a square of up to 300×300 and stored as JPEG (without their metadata) in the media folder.

If no administrator can sign in, the [console commands](TECHNICAL_GUIDE.md#accounts-from-the-console) get you back in.

## 📚 Learn more

### 🧱 Stack

| Part | Technologies |
|---|---|
| Backend (this repository) | PHP 8.5, Symfony 8.1, API Platform 5, Doctrine ORM, PostgreSQL 16, getID3, LexikJWT + refresh tokens |
| Web server | FrankenPHP (Caddy) with automatic HTTPS and HTTP/3 |
| Web client | [Echora Web](https://github.com/Evilchuck666/echora-web) (React + TypeScript), in a separate repository; in production, built into this server's image and served at the root of the site |
| Native apps | Planned, built on the same API |
| Deployment | Docker Compose, based on [Symfony Docker](https://github.com/dunglas/symfony-docker) |

- [Technical guide](TECHNICAL_GUIDE.md): the full API reference, trying it with
  Bruno, production and backups, development, tests and code quality.
- [Decision records](docs/decisions/README.md): why the API is built the way it
  is, one short record per decision.
- [Echora Web](https://github.com/Evilchuck666/echora-web): the web client.

## ⚖️ License

Echora is free software released under the **GNU General Public License v3.0** (GPL-3.0). See [LICENSE](LICENSE).
