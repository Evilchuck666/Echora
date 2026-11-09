# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

Echora is the backend API for a self-hosted music streaming service: it stores tracks and serves them over HTTP for clients to download and play.

- Stack: Symfony (PHP), PostgreSQL via Doctrine ORM, run locally with Docker Compose.
- This repo is the API only. The web frontend (React + TypeScript) lives in a separate repo, and native apps for other platforms are planned.

## Architecture rules

- The API is the only contract between server and clients. Return JSON, not server-rendered pages, and don't add behavior that only works for the web frontend: future native apps must be able to do everything through the same endpoints.
- Audio endpoints must support HTTP Range requests (`206 Partial Content`, `Accept-Ranges: bytes`). Without them, seeking breaks and some players won't play the file.

## Environment

- Run PHP, Composer and `bin/console` commands inside the Docker container, not on the host.

## Status

The project hasn't been scaffolded yet. Once it is, add the real commands here (Compose service names, how to run tests, lint/format tools) and remove this section.
