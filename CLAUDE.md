# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

@AGENTS.md

## Project

Echora is the backend API for a self-hosted music streaming service: it stores tracks and serves them over HTTP for clients to download and play.

- Stack: PHP 8.5, Symfony 8.1, PostgreSQL 16 via Doctrine ORM, served by FrankenPHP. Docker setup based on the `dunglas/symfony-docker` template.
- This repo is the API only. The web frontend (React + TypeScript) lives in a separate repo, and native apps for other platforms are planned.
- Persistence is Doctrine ORM and the interface is a JSON API (no Twig), so the "ask before generating" questions in AGENTS.md are already answered for those two. Authentication is still undecided: ask.

## Architecture rules

- The API is the only contract between server and clients. Return JSON, not server-rendered pages, and don't add behavior that only works for the web frontend: future native apps must be able to do everything through the same endpoints.
- Audio endpoints must support HTTP Range requests (`206 Partial Content`, `Accept-Ranges: bytes`). Without them, seeking breaks and some players won't play the file. `BinaryFileResponse` handles this.

## Gotchas

- A PostToolUse hook (`.claude/hooks/fix-permissions.sh`) runs `scripts/fix-permissions.sh` automatically after every Bash command containing `docker compose exec` or `docker compose run`, so don't run it by hand after those. Run it manually only if root-owned files appeared some other way.

- `composer.json` declares `GPL-3.0-only`. Don't let a skeleton or recipe overwrite `LICENSE` with an MIT text: check `git diff LICENSE` after any scaffolding.
- The database service publishes port 5432 on a random host port (`docker compose port database 5432`), because the host already runs its own PostgreSQL on 5432.
