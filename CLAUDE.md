# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

@AGENTS.md

## Project

Echora is the backend API for a self-hosted music streaming service: it catalogs a folder of FLAC/MP3 files and serves them over HTTP for clients to download and play.

- Stack: PHP 8.5, Symfony 8.1, API Platform 5, PostgreSQL 16 via Doctrine ORM, served by FrankenPHP. Docker setup based on the `dunglas/symfony-docker` template.
- This repo is the API only. The web client (React + TypeScript) lives in a separate repo at `../echora-web` (in production it is built into this image and served by the same Caddy, see below), and native apps for other platforms are planned. When an API change affects clients (fields, endpoints, auth), say so: `../echora-web/CLAUDE.md` documents how the client uses the API.
- The "ask before generating" questions in AGENTS.md are answered: Doctrine ORM, a JSON API built with API Platform (no Twig), and multi-user JWT authentication (LexikJWT + gesdinet refresh tokens).

## Architecture rules

- The API is the only contract between server and clients. Return JSON, not server-rendered pages, and don't add behavior that only works for the web frontend: future native apps must be able to do everything through the same endpoints.
- Audio endpoints must support HTTP Range requests (`206 Partial Content`, `Accept-Ranges: bytes`). Library files (audio, covers) are served by plain Symfony controllers (`TrackStreamController`, `AlbumCoverController`, `ArtistCoverController`) through `LibraryFiles`; they're documented in OpenAPI by `LibraryFilesOpenApiFactory`.
- Production serves the web client too: `compose.prod.yaml` passes the echora-web checkout (`WEB_DIR`, default `../echora-web`) as the `web` build context, the Dockerfile's `web_build` stage runs `bun install --frozen-lockfile` and `bun run build`, and `frankenphp_prod` copies `dist/` to `/srv/web`. The Caddyfile's `@webClient` serves it (SPA fallback to `index.html`, immutable `/assets`) for every path except `/api`, `/bundles`, `/.well-known`, the profiler and `index.php`, only when `/srv/web/index.html` exists: development images don't have it, so dev routing is unchanged. Same origin, so production CORS defaults to no origin (`^$`). Keep new API-side public paths out of `@webClient`.
- Every folder PHP writes to at runtime in production must exist in the image owned by `www-data` (`install -d -o www-data -g 0`): a volume mounted on a path missing from the image is created root-owned (it broke `var/media`).
- Files are sent by Caddy, never streamed by PHP: behind FrankenPHP, `BinaryFileResponse` answers with `X-Accel-Redirect` and the Caddyfile's `intercept` serves the file with `file_server` (ranges included). PHP streaming broke playback: players read a track slowly, `max_execution_time` (30 s) killed the response, and Xdebug wrote its error page into the audio, which Firefox then played as silence. The Caddyfile overwrites the `X-Sendfile-Type` and `X-Accel-Mapping` request headers (a client-sent mapping could expose any file) and only serves paths under `/music/` and `/app/var/media/`: keep both. Without those headers (tests), PHP streams the file. The Caddyfile is bind-mounted in development (`docker compose restart php` after a change) and copied into the production image (rebuild).
- The music folder is mounted read-only at `/music` (`MUSIC_LIBRARY_PATH`; `MUSIC_DIR` on the host). Never write to it. `app:library:scan` (`LibraryScanner`) is the only way tracks get into the database.
- Never expose a track's `path` or `fileModifiedAt`, an album's `coverPath`, `downloadedCover` or `coverLookedUpAt`, or an artist's `imagePath`, `downloadedImage` or `imageLookedUpAt`: they describe the server's disk. Serialization uses explicit `*:read` groups; `track:item` (lyrics) is only for `GET /api/tracks/{id}`, to keep lists light.
- Artist images (`ArtistImages`, the last step of a scan): `artist`/`folder` in the artist folder (the common parent of the artist's album folders, never the library root), or else downloaded from TheAudioDB (`TheAudioDb`: free key `123` in `THEAUDIODB_API_KEY`, empty in `.env.test`; 30 requests a minute, a 429 stops the lookups for that scan; only an exact name match, ignoring case and accents). Downloads and thumbnails live in `%app.media_dir%` (`var/media/<env>`, the `media` volume in prod), never in the music folder. `/api/artists/{id}/cover` always serves a `Thumbnails` JPEG (GD, in the Docker image), not the original. Tests never reach the network: they replace `TheAudioDb` with a `MockHttpClient`.
- Album covers (`AlbumCovers`, a scan step before `ArtistImages`): from the album folder (`CoverFinder`), found again on every scan, or else downloaded from TheAudioDB (`TheAudioDb::findAlbumCover()`, `searchalbum.php`; exact album artist and title match) into `%app.media_dir%/albums`, served as is (no thumbnail). Tracks show their album's `coverUrl`. Both steps share the rate limit and `ScanReport::$theAudioDbFailure`: a 429 on an album stops the artist lookups too.
- When the scanner learns to read a new tag, existing tracks only get it with `app:library:scan --full`: say so in the README and the change summary.
- `GET /api/search` (`App\ApiResource\Search`, a DTO resource with `SearchProvider`) runs `LibrarySearch`: every word must match (`LIKE`, escaped), case- and accent-insensitive through the `UNACCENT()` DQL function (`App\Doctrine\Unaccent`, PostgreSQL's `unaccent` extension, enabled by a migration). It fetches the related artists and albums in the same query; keep it that way (one count and one select per kind).
- API Platform 5 deprecates `#[ApiFilter]`, `SearchFilter` and `OrderFilter`: filter with `#[QueryParameter]` and the per-strategy filters (`PartialSearchFilter`, `ExactFilter`, `IriFilter`, `SortFilter`). API tests extend `ApiPlatform\Test\ApiTestCase`, not the deprecated `ApiPlatform\Symfony\Bundle\Test\ApiTestCase`.

## Security

- Everything under `/api` requires `ROLE_USER` except the docs, `/api/auth`, `/api/token/refresh`, `/api/logout` and the stream and cover routes (tracks, albums, artists) (see `access_control` in `security.yaml`). New public routes must be added there deliberately.
- The stream and cover routes accept a JWT or the `UriSigner` signature of the track's `streamUrl` / album's `coverUrl` (`SignedUrlGenerator`, filled by `SignedUrlNormalizer`; checked by `LibraryFiles::denyUnlessAuthorized()`), because players and `<img>` can't send headers. Never put a JWT in a URL. `UriSigner::sign()` reads an `int` as an absolute timestamp: pass a `DateInterval` for a lifetime. Cover URLs expire at a fixed time (end of tomorrow, UTC) so they stay identical all day and browsers can cache them.
- Accounts are created only with the `app:user:*` commands; there is no sign-up endpoint. Passwords are asked with a hidden prompt (`PasswordPrompt`), never taken as arguments. Symfony 8 doesn't turn interactivity off without a TTY, so `PasswordPrompt` checks `stream_isatty()` itself.
- Refresh tokens are single use, stored hashed, with reuse detection (`gesdinet_jwt_refresh_token.yaml`). Signing an account out means deleting its rows from `refresh_tokens`.
- The bundle looks a refresh token up in its authenticator and deletes it later in the same request, so concurrent refreshes with the same token would both succeed. `RefreshTokenLockListener` holds a `symfony/lock` lock on the token (PostgreSQL advisory locks: `LOCK_DSN` in `compose.yaml`) from before the firewall until the request finishes, so the second request is treated as reuse.
- The refresh firewall's `check_path` is the path `/api/token/refresh`, not the route name: the bundle documents it in OpenAPI as written, and a route name ended up as a bogus path.
- Grace period for lost refresh responses (`RefreshTokenGraceListener`, `RefreshTokenSuccessors`, `RefreshTokenSuccessorExtractor`): after a refresh, the presented token is mapped to the issued one for `REFRESH_TOKEN_GRACE_SECONDS` (300), encrypted with a key derived from the presented token and `kernel.secret`. A spent token presented again while its successor is unused is refreshed as that successor (an extractor at the head of the bundle's chain, so the authenticator, the rotation and the lock all see it); the mapping always starts from the token the client presented, never from the successor, so a used successor or a chain walk never revives a token. Concurrent duplicates are still reuse (the grace listener runs before the lock). Spent tokens and successors are in the persistent `cache.refresh_tokens` pool (PostgreSQL `cache_items`): never move them back to `cache.app`, which is wiped on deploy. Expired rows stay until `bin/console cache:pool:prune`.
- `POST /api/logout` is the firewall's `logout`; the bundle's listener (`invalidate_token_on_logout`) deletes the refresh token in the body. It is documented by `LogoutOpenApiFactory`.
- The JWT key pair is generated by the entrypoint on the first start (`config/jwt`, git- and docker-ignored; the `jwt_keys` volume in prod) with `chmod 600` on the private key.

## Testing

- `bruno/` is a Bruno collection of every endpoint (`.bru` files, credentials in the git-ignored `bruno/.env`). Update it in the same change whenever an endpoint, a parameter or the authentication changes.

- Tests scan `tests/Fixtures/music` (set in `.env.test`), never the real library. The fixtures are one-second silent files generated with ffmpeg (covers: 8×8 images); add new cases the same way and keep them tiny. ffmpeg writes MP3 lyrics as a TXXX frame, not USLT: use mutagen (`python3`) for a real USLT.
- The test client doesn't capture the body of a `BinaryFileResponse`; send it to an output buffer (see `TrackStreamTest::body()`).
- API tests authenticate with the `CreatesUsers` trait (`createUser()`, `tokenFor()`) and `$client->setDefaultOptions(['auth_bearer' => ...])`.
- To try a command that asks for a password from a non-interactive shell, give it a pseudo-terminal: `script -qec "docker compose exec php bin/console app:user:create <name>" /dev/null`.

## Gotchas

- A PostToolUse hook (`.claude/hooks/php-cs-fixer.sh`) formats every PHP file you write or edit with PHP-CS-Fixer, when the stack is running. Expect files to change after an edit (e.g. unused imports removed); re-read before editing the same region again.
- A PostToolUse hook (`.claude/hooks/fix-permissions.sh`) runs `scripts/fix-permissions.sh` automatically after every Bash command containing `docker compose exec` or `docker compose run`, so don't run it by hand after those. Run it manually only if root-owned files appeared some other way.
- `composer.json` declares `GPL-3.0-only`. Don't let a skeleton or recipe overwrite `LICENSE` with an MIT text: check `git diff LICENSE` after any scaffolding.
- Flex skips `contrib` recipes (`allow-contrib: false`): `dama/doctrine-test-bundle`, `gesdinet/jwt-refresh-token-bundle` and PHPStan had to be configured by hand.
- Changes to `frankenphp/docker-entrypoint.sh` need `docker compose up --build --wait`: the script is copied into the image, not bind-mounted.
- Quote regular expressions in YAML (`path: '^/api/tracks/[^/]+/stream$'`): inside `{ ... }` an unquoted `[` starts a list. Symfony's lenient parser accepts it, so `lint:yaml` and the tests pass, but strict parsers (the VS Code YAML extension) report the rest of the file as broken.
- The database service publishes port 5432 on a random host port (`docker compose port database 5432`), because the host already runs its own PostgreSQL on 5432.
