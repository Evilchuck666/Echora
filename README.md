# Echora

Echora is a self-hosted music streaming service. This repository contains its backend: an HTTP API that stores your music library and serves tracks for clients to download and play.

> **Status:** early development. The project hasn't been scaffolded yet, so there's nothing to run.

## Architecture

The backend and the clients are separate projects. The API is the only contract between them, so any client can implement the full feature set:

- **API (this repository):** Symfony (PHP) with PostgreSQL via Doctrine ORM. Audio is served with HTTP Range support, so clients can seek and stream instead of downloading the whole file first.
- **Web client:** React + TypeScript, in a separate repository.
- **Native apps:** planned for different operating systems and built on the same API.

## Development

The local environment will run on Docker Compose (PHP and PostgreSQL in containers), so you won't need PHP installed on your machine. Setup instructions will be added once the project is scaffolded.

## License

Copyright (C) 2026 EvilChuck

This program is free software: you can redistribute it and/or modify it under the terms of the GNU General Public License as published by the Free Software Foundation, version 3.

This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the [LICENSE](LICENSE) file for details.

SPDX-License-Identifier: `GPL-3.0-only`
