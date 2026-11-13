# 0018. Nightly database backups, with tools of the server's version

- **Date:** 2026-11-13
- **Status:** Accepted

## Context

The database had come to hold what no scan can bring back: accounts, playlists, favourites and listening history.
Nothing backed it up, so losing the database volume lost all of it. The owner chose this as the next step for the
API.

## Decision

- **Making backups.** `app:database:backup` dumps the database with `pg_dump` in its custom format (compressed,
  restored with `pg_restore`).
  - Each dump goes to a file of its own, `echora-YYYYmmdd-HHMMSS.dump`, written under a temporary name then renamed,
    so a backup is never half a file.
  - It runs every night at 02:30 UTC from `frankenphp/crontab`, before the nightly scan, like the other scheduled
    jobs: no host cron, no extra container.
  - Backups older than `BACKUP_KEEP_DAYS` (14 by default) are deleted, but the latest one is always kept.
- **Where they go.** In production, the `backups` volume.
  - `BACKUP_DIR` can make it a folder of the host instead, ideally on another disk or a NAS, as a backup next to the
    database is lost with the disk.
  - The folder exists in the image, owned by `www-data`, like `var/media`.
- **Restoring.** `app:database:restore` lists the backups, or restores one after a confirmation typed in a terminal.
  - It uses `pg_restore --clean --if-exists --single-transaction`: the whole database is replaced at once, or nothing
    changes if anything fails.
  - It was tried over the running development database, with the web server and workers connected, without waiting
    on locks.
- **Connecting.** The commands get Doctrine's connection through libpq's environment variables (`PGHOST`,
  `PGPASSWORD`...), never on the command line, where the password would show in the process list.
- **Tools of the server's major version.** Debian's client is PostgreSQL 17, the server 16, and
  `pg_restore` 17 fails on a 16 server: it sets `transaction_timeout`, unknown to 16. Trying the first restore showed
  it.
  - The image installs `postgresql-client-${POSTGRES_VERSION}` from PostgreSQL's own repository (its key pinned by
    sha256). `POSTGRES_VERSION` is passed by compose as a build argument, the same variable that picks the database
    image, so both follow it.
  - The production stage copies the real binaries, as Debian's `pg_dump` and `pg_restore` are Perl wrappers.
  - `pg_restore` also needs `--dbname`: it ignores `PGDATABASE`, unlike `pg_dump`.

## Consequences

- The image depends on PostgreSQL's apt repository at build time. A rotated key fails the build, by its checksum.
- Restoring needs the same major version of PostgreSQL. After a restore, the php service is restarted (migrations run
  on start) and the library scanned.
- Backups hold the database only. The music folder is the owner's to back up. The `media` volume is rebuilt on its
  own, except profile pictures, which have to be uploaded again.
- The production CI job makes a backup with the production image, so a missing binary or library, or an unwritable
  folder, fails it.

## Origin

Proposed from the Echora repository among the next steps for the API, and chosen by the owner (2026-11-13).

## Commits

- `87c3c66` feat(backups): back the database up every night and restore it in one transaction
