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
- **Incremental scans**: rescanning only reads new or changed files, removes the ones you deleted, and refuses to empty the library if the music folder isn't mounted.
- **Covers and lyrics**: album covers come from the album folder, lyrics from the tags.
- **Streaming with Range support**: tracks are served as they are, and clients can seek and start playing before the whole file has downloaded.
- **Browsable API**: artists, albums and tracks with pagination and filters, in JSON-LD or plain JSON, documented with OpenAPI so clients can be generated from it.
- **User accounts**: sign in with a username and password, get a JWT and a refresh token that keeps apps signed in. Audio players get signed, expiring URLs, so an `<audio>` element can play tracks without sending any header.

---

## 🧱 Stack

| Part | Technologies |
|---|---|
| Backend (this repository) | PHP 8.5, Symfony 8.1, API Platform 5, Doctrine ORM, PostgreSQL 16, getID3, LexikJWT + refresh tokens |
| Web server | FrankenPHP (Caddy) with automatic HTTPS and HTTP/3 |
| Web client | React + TypeScript, in a separate repository |
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

Create your account, then import your music:

```sh
docker compose exec php bin/console app:user:create <username> --admin
docker compose exec php bin/console app:library:scan
```

---

## 🎵 Your music library

Import or update the library after adding, changing or deleting files:

```sh
docker compose exec php bin/console app:library:scan
```

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

A scan skips the files that didn't change. After updating Echora to a version that reads more tags, read every file again with `app:library:scan --full`.

---

## 👤 Accounts

Accounts are managed from the console. Passwords are always typed in a hidden prompt, never passed as arguments, so they don't end up in your shell history.

| Command | What it does |
|---|---|
| `app:user:create <username> [--admin]` | Creates an account |
| `app:user:list` | Lists the accounts |
| `app:user:password <username>` | Changes a password and signs the account out of every device |
| `app:user:delete <username>` | Deletes an account and signs it out of every device |

Run them with `docker compose exec php bin/console ...`.

---

## 🔌 API

Open **https://localhost/api** in a browser to explore every endpoint. The OpenAPI specification is at `/api/docs.jsonopenapi`. Both are public; everything else needs authentication.

### Authentication

1. `POST /api/auth` with `{"username": "...", "password": "..."}` returns a `token` (a JWT, valid for one hour) and a `refresh_token` (valid for 30 days).
2. Send the JWT on every request: `Authorization: Bearer <token>`. In the documentation page, use the **Authorize** button.
3. Before it expires, `POST /api/token/refresh` with `{"refresh_token": "..."}` returns a new pair. Each refresh token works once; presenting one that was already used signs that session out, as it may have been stolen.

Every track includes a `streamUrl`: a signed link to its audio, valid for six hours, that works without the `Authorization` header. Use it as the `src` of an `<audio>` element or hand it to a native media player. When it expires, fetch the track again for a new one.

Likewise, every album (also when embedded in a track) includes a `coverUrl`, or `null` when its folder has no cover. Use it as the `src` of an `<img>`: it stays the same for a day, so browsers cache the image.

### Endpoints

| Endpoint | What it returns |
|---|---|
| `POST /api/auth` | A JWT and a refresh token |
| `POST /api/token/refresh` | A new JWT and refresh token |
| `GET /api/me` | The authenticated account |
| `GET /api/artists` | Artists, by name. Filter: `name` |
| `GET /api/albums` | Albums, by year. Filters: `artist` (IRI), `title`, `year` |
| `GET /api/tracks` | Tracks, by disc and track number. Filters: `album` (IRI), `artist` (IRI), `title`, `genre` |
| `GET /api/{artists,albums,tracks}/{id}` | One item. A single track also includes its `lyrics`; lists only say `hasLyrics` |
| `GET /api/tracks/{id}/stream` | The audio file, with HTTP Range support. Use the track's `streamUrl` |
| `GET /api/albums/{id}/cover` | The cover image. Use the album's `coverUrl` |

Responses are JSON-LD by default; send `Accept: application/json` for plain JSON.

Browsers only let web clients on other origins call the API when `CORS_ALLOW_ORIGIN` allows them. In development it allows `localhost` on any port; in production, set it in `prod.env` to the address of your web client.

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

Copy `prod.env.example` to `prod.env`, set your music folder, domain and secrets, then build and start the production images and create your account:

```sh
cp prod.env.example prod.env
docker compose --env-file prod.env -f compose.yaml -f compose.prod.yaml build --pull
docker compose --env-file prod.env -f compose.yaml -f compose.prod.yaml up --wait
docker compose --env-file prod.env -f compose.yaml -f compose.prod.yaml exec php bin/console app:user:create <username> --admin
docker compose --env-file prod.env -f compose.yaml -f compose.prod.yaml exec php bin/console app:library:scan
```

`prod.env` is ignored by git. Caddy gets a TLS certificate for your domain automatically.

The key pair that signs the JWTs is generated on the first start and kept in the `jwt_keys` volume. Keep that volume: if it is lost, new keys are generated and every account has to sign in again.

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

To debug with Xdebug, start the stack with `XDEBUG_MODE=develop,debug docker compose up --wait` and run the **Debug PHP (Docker)** configuration in VS Code.

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

---

## 🚧 Status

Echora is in early development. The library, the browsing API, streaming and user accounts work; the web client is next.

---

## ⚖️ License

This project is licensed under the GPLv3 License (GPL-3.0-only) — see the [LICENSE](LICENSE) file for details.
