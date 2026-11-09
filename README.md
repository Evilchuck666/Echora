# 🎧 Echora

![Platform](https://img.shields.io/badge/platform-Web%20API-blue)
![Backend](https://img.shields.io/badge/backend-Symfony-blue)
![Database](https://img.shields.io/badge/database-PostgreSQL-blue)
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
| Backend (this repository) | Symfony (PHP), Doctrine ORM, PostgreSQL |
| Web client | React + TypeScript, in a separate repository |
| Native apps | Planned, built on the same API |
| Local environment | Docker Compose: PHP and PostgreSQL in containers |

---

## 🚧 Status

Echora is in early development and hasn't been scaffolded yet, so there's nothing to run. Setup and development instructions will be added here once the Symfony project and its Docker Compose environment are in place. You won't need PHP installed on your machine: everything will run inside the containers.

---

## ⚖️ License

This project is licensed under the GPLv3 License (GPL-3.0-only) — see the [LICENSE](LICENSE) file for details.
