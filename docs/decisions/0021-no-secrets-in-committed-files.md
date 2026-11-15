# 0021. No secrets in committed files; production refuses sample secrets

- **Date:** 2026-11-15
- **Status:** Accepted

## Context

The owner noticed that git tracks `.env`, `.env.dev` and `.env.test`, and saw it as a serious security problem, even
in development. He proposed renaming them to `.env.*.local` or ignoring them.

Tracking them is Symfony's convention, which AGENTS.md also asks for: they hold the defaults that a fresh clone and the
CI need (`DATABASE_URL`, `KERNEL_CLASS`, `MUSIC_LIBRARY_PATH`...), and real secrets belong in `.env.local` or the
environment. Two of their values were real secrets, though, put there by Symfony recipes:
- `JWT_PASSPHRASE` in `.env`, protecting the development private key;
- `APP_SECRET` in `.env.dev`, signing media URLs and encrypting the refresh-token grace mappings in development.

`prod.env` was never committed, and production got its secrets from it, so no production secret leaked. Checking
production showed a worse gap: `APP_SECRET` wasn't required, and `prod.env.example` says `ChangeMe`. A server set up
without changing it ran with a known or empty secret, which lets anyone sign URLs to its music.

## Decision

- **The files stay tracked, without secrets.**
  - `APP_SECRET` and `JWT_PASSPHRASE` are empty in `.env`, and `.env.dev` holds none.
  - `.env.test` keeps fixed, public values, as tests protect nothing.
  - Renaming or ignoring the files was turned down: fresh clones and the CI would stop starting, and the history
    would still hold the old values.
- **Development generates its own secrets.**
  - On its first start, the container writes a random `APP_SECRET` and `JWT_PASSPHRASE` into `.env.local` (ignored by
    git and by Docker), before anything boots the kernel.
  - It deletes JWT keys made with the former passphrase, which can't be read anymore, and makes new ones.
  - Tests get their own key pair (`config/jwt/test`, with a public passphrase in `.env.test`), as Symfony doesn't load
    `.env.local` in the test environment.
- **Production refuses empty or sample secrets.**
  - `compose.prod.yaml` requires `APP_SECRET`, `CADDY_MERCURE_JWT_SECRET` and `POSTGRES_PASSWORD`, as it already
    required `JWT_PASSPHRASE`.
  - The entrypoint refuses to start, with a message saying how to generate one, when `APP_SECRET`, `JWT_PASSPHRASE`
    or the Mercure key is `ChangeMe`, empty or shorter than 32 characters, or when the database password is still
    `ChangeMe`.
- **The history isn't rewritten.** The old values were for development only, and new ones replace them, so they
  protect nothing. Rewriting would break the commits already published.

## Consequences

- After this change, a development machine signs everyone out once (new keys), and old signed URLs stop working.
- `.env.local` and `config/jwt/` hold each machine's secrets: deleting them makes new ones.
- A production server set up with the sample secrets won't start after updating until `prod.env` gets real ones. That
  is the point.

## Origin

Raised by the owner in the Echora repository (2026-11-15). He proposed renaming or ignoring the files; the plan to keep
them tracked without secrets, and to harden production, was approved by him.

## Commits

- Not committed yet.
