# 🎧 Echora

![Platform](https://img.shields.io/badge/platform-Web%20API-blue)
![PHP](https://img.shields.io/badge/PHP-8.5-blue)
![Symfony](https://img.shields.io/badge/Symfony-8.1-blue)
![PostgreSQL](https://img.shields.io/badge/PostgreSQL-16-blue)
![Requires](https://img.shields.io/badge/requires-Docker%20Compose-blue)
![Status](https://img.shields.io/badge/status-early%20development-orange)
[![License](https://img.shields.io/badge/license-GPLv3-green)](LICENSE)

**Your own music streaming service, on your own server.**

Echora is a self-hosted music streaming service. This repository is its backend: an HTTP API that stores your music library and serves the tracks for any client to download and play.

---

## 🧩 Why Echora?

Streaming platforms decide which music is available, how it sounds and where you can listen to it. Echora serves your own collection instead, from a server you control.

The backend and the clients are separate projects that talk only through the API. A web client comes first, and native apps for different operating systems can be built later on the same endpoints, without changing the server.

---

## ✨ Planned features

- **Music library**: your tracks stored on the server and described in a PostgreSQL database.
- **Streaming API**: tracks served over HTTP with Range support, so clients can seek and start playing before the whole file has downloaded.
- **Client-agnostic**: JSON responses only, with no behavior tied to a specific client.

---

## 🧱 Stack

| Part | Technologies |
|---|---|
| Backend (this repository) | PHP 8.5, Symfony 8.1, Doctrine ORM, PostgreSQL 16 |
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

The API is served at **https://localhost**. Accept the auto-generated TLS certificate the first time. Stop it with:

```sh
docker compose down --remove-orphans
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
docker compose run --rm php chown -R $(id -u):$(id -g) .
```

To debug with Xdebug, start the stack with `XDEBUG_MODE=develop,debug docker compose up --wait` and run the **Debug PHP (Docker)** configuration in VS Code.

---

## 🚧 Status

Echora is in early development: the Symfony project and its Docker environment are in place, but there are no API endpoints yet.

---

## ⚖️ License

This project is licensed under the GPLv3 License (GPL-3.0-only) — see the [LICENSE](LICENSE) file for details.
