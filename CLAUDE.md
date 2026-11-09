# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

@AGENTS.md

## Project

Echora is the backend API for a self-hosted music streaming service: it catalogs a folder of FLAC/MP3 files and serves them over HTTP for clients to download and play.

- Stack: PHP 8.5, Symfony 8.1, API Platform 5, PostgreSQL 16 via Doctrine ORM, served by FrankenPHP. Docker setup based on the `dunglas/symfony-docker` template.
- This repo is the API only. The web frontend (React + TypeScript) lives in a separate repo, and native apps for other platforms are planned.
- The "ask before generating" questions in AGENTS.md are answered: Doctrine ORM, a JSON API built with API Platform (no Twig), and multi-user token authentication (planned next, not built yet).

## Architecture rules

- The API is the only contract between server and clients. Return JSON, not server-rendered pages, and don't add behavior that only works for the web frontend: future native apps must be able to do everything through the same endpoints.
- Audio endpoints must support HTTP Range requests (`206 Partial Content`, `Accept-Ranges: bytes`). `TrackStreamController` uses `BinaryFileResponse`, which handles this; it's a plain Symfony controller, documented in OpenAPI by `TrackStreamOpenApiFactory`.
- The music folder is mounted read-only at `/music` (`MUSIC_LIBRARY_PATH`; `MUSIC_DIR` on the host). Never write to it. `app:library:scan` (`LibraryScanner`) is the only way tracks get into the database.
- Never expose a track's `path` or `fileModifiedAt`: they describe the server's disk. Serialization uses explicit `*:read` groups.
- API Platform 5 deprecates `#[ApiFilter]`, `SearchFilter` and `OrderFilter`: filter with `#[QueryParameter]` and the per-strategy filters (`PartialSearchFilter`, `ExactFilter`, `IriFilter`, `SortFilter`). API tests extend `ApiPlatform\Test\ApiTestCase`, not the deprecated `ApiPlatform\Symfony\Bundle\Test\ApiTestCase`.

## Testing

- Tests scan `tests/Fixtures/music` (set in `.env.test`), never the real library. The fixtures are one-second silent files generated with ffmpeg; add new cases the same way and keep them tiny.
- The test client doesn't capture the body of a `BinaryFileResponse`; send it to an output buffer (see `TrackStreamTest::body()`).

## Gotchas

- A PostToolUse hook (`.claude/hooks/php-cs-fixer.sh`) formats every PHP file you write or edit with PHP-CS-Fixer, when the stack is running. Expect files to change after an edit (e.g. unused imports removed); re-read before editing the same region again.
- A PostToolUse hook (`.claude/hooks/fix-permissions.sh`) runs `scripts/fix-permissions.sh` automatically after every Bash command containing `docker compose exec` or `docker compose run`, so don't run it by hand after those. Run it manually only if root-owned files appeared some other way.
- `composer.json` declares `GPL-3.0-only`. Don't let a skeleton or recipe overwrite `LICENSE` with an MIT text: check `git diff LICENSE` after any scaffolding.
- Flex skips `contrib` recipes (`allow-contrib: false`): bundles like `dama/doctrine-test-bundle` and tools like PHPStan had to be configured by hand.
- The database service publishes port 5432 on a random host port (`docker compose port database 5432`), because the host already runs its own PostgreSQL on 5432.
