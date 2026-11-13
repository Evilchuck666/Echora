#syntax=docker/dockerfile:1

# Versions
FROM dunglas/frankenphp:1-php8.5 AS frankenphp_upstream

# The different stages of this Dockerfile are meant to be built into separate images
# https://docs.docker.com/build/building/multi-stage/#stop-at-a-specific-build-stage
# https://docs.docker.com/reference/compose-file/build/#target


# Base FrankenPHP image
FROM frankenphp_upstream AS frankenphp_base

SHELL ["/bin/bash", "-euxo", "pipefail", "-c"]

WORKDIR /app

# persistent deps
# hadolint ignore=DL3008
RUN <<-EOF
	apt-get update
	apt-get install -y --no-install-recommends \
		file \
		git \
		opus-tools
	install-php-extensions \
		@composer \
		apcu \
		gd \
		intl \
		opcache \
		zip
	rm -rf /var/lib/apt/lists/*
EOF

# https://getcomposer.org/doc/03-cli.md#composer-allow-superuser
ENV COMPOSER_ALLOW_SUPERUSER=1

ENV PHP_INI_SCAN_DIR=":$PHP_INI_DIR/app.conf.d"

# Supercronic, a cron for containers: runs as any user, passes the environment on to the jobs and logs to stdout.
# Started by docker-entrypoint next to FrankenPHP, it runs the jobs of frankenphp/crontab.
# https://github.com/aptible/supercronic/releases (checksums: sha256 of the release binaries)
ARG SUPERCRONIC_VERSION=v0.2.51
ARG TARGETARCH
RUN <<-EOF
	case "$TARGETARCH" in
		amd64) sha256=cc232108374e8e2b537045f2d5eaa1229f619db314c3ba6a14d38b35dc0aa123 ;;
		arm64) sha256=4ae22576a78223d8ccdf5f3b3660a5cbb5288416bf0ca96afc4a36d195932b94 ;;
		*) echo "unsupported architecture: $TARGETARCH"; exit 1 ;;
	esac
	curl -fsSL -o /usr/local/bin/supercronic "https://github.com/aptible/supercronic/releases/download/$SUPERCRONIC_VERSION/supercronic-linux-$TARGETARCH"
	echo "$sha256  /usr/local/bin/supercronic" | sha256sum -c -
	chmod 755 /usr/local/bin/supercronic
EOF

# pg_dump and pg_restore for the database backups (App\Database\DatabaseBackups), of the server's major version: a
# pg_restore newer than the server fails (PostgreSQL 17's sets transaction_timeout, unknown to 16), and an older pg_dump
# refuses a newer server. From PostgreSQL's own repository, as Debian only has its default version. POSTGRES_VERSION
# comes from compose, like the database image's tag. The key is checked against its sha256 (fingerprint
# B97B0AFCAA1A47F044F244A07FCC7D46ACCC4CF8).
ARG POSTGRES_VERSION=16
RUN <<-EOF
	install -d /usr/share/postgresql-common/pgdg
	curl -fsSL -o /usr/share/postgresql-common/pgdg/apt.postgresql.org.asc https://www.postgresql.org/media/keys/ACCC4CF8.asc
	echo "0144068502a1eddd2a0280ede10ef607d1ec592ce819940991203941564e8e76  /usr/share/postgresql-common/pgdg/apt.postgresql.org.asc" | sha256sum -c -
	. /etc/os-release
	echo "deb [signed-by=/usr/share/postgresql-common/pgdg/apt.postgresql.org.asc] https://apt.postgresql.org/pub/repos/apt ${VERSION_CODENAME}-pgdg main" > /etc/apt/sources.list.d/pgdg.list
	apt-get update
	apt-get install -y --no-install-recommends "postgresql-client-${POSTGRES_VERSION%%.*}"
	rm -rf /var/lib/apt/lists/*
EOF

###> recipes ###
###> doctrine/doctrine-bundle ###
RUN install-php-extensions pdo_pgsql
###< doctrine/doctrine-bundle ###
###< recipes ###

COPY --link frankenphp/conf.d/10-app.ini $PHP_INI_DIR/app.conf.d/
COPY --link --chmod=755 frankenphp/docker-entrypoint.sh /usr/local/bin/docker-entrypoint
COPY --link frankenphp/Caddyfile /etc/frankenphp/Caddyfile
COPY --link frankenphp/crontab /etc/supercronic/crontab
RUN supercronic -test /etc/supercronic/crontab

ENTRYPOINT ["docker-entrypoint"]

HEALTHCHECK --start-period=60s CMD ["php", "-r", "exit(false === @file_get_contents('http://localhost:2019/metrics', context: stream_context_create(['http' => ['timeout' => 5]])) ? 1 : 0);"]
CMD [ "frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile" ]

# Dev FrankenPHP image
FROM frankenphp_base AS frankenphp_dev

# Repeated until hadolint inherits the parent stage SHELL: https://github.com/hadolint/hadolint/pull/1258
SHELL ["/bin/bash", "-euxo", "pipefail", "-c"]

ENV APP_ENV=dev
ENV XDEBUG_MODE=off
ENV FRANKENPHP_WORKER_CONFIG=watch

# dev dependencies
RUN <<-EOF
	mv "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"
	install-php-extensions xdebug
	useradd -m -s /bin/bash nonroot
	git config --system --add safe.directory /app
EOF

# Symfony Language Server, for editors and AI agents that don't bundle it themselves
# https://github.com/symfony/language-tools
ARG TARGETARCH
RUN <<-EOF
	case "$TARGETARCH" in
		amd64) lsp_arch=x64 ;;
		arm64) lsp_arch=arm64 ;;
		*) echo "unsupported architecture: $TARGETARCH"; exit 1 ;;
	esac
	# The release URL redirects to the latest tag, no API token nor rate limit involved
	lsp_version=$(basename "$(curl -fsSLI -o /dev/null -w '%{url_effective}' https://github.com/symfony/language-tools/releases/latest)")
	curl -fsSL "https://github.com/symfony/language-tools/releases/download/$lsp_version/symfony-lsp-$lsp_version-linux-$lsp_arch.tar.gz" \
		| tar -xz -C /usr/local/bin --strip-components=1 --wildcards '*/symfony-lsp'
EOF

COPY --link frankenphp/conf.d/20-app.dev.ini $PHP_INI_DIR/app.conf.d/

CMD [ "frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile", "--watch" ]

# Builder for the prod FrankenPHP image
FROM frankenphp_base AS frankenphp_prod_builder

# Repeated until hadolint inherits the parent stage SHELL: https://github.com/hadolint/hadolint/pull/1258
SHELL ["/bin/bash", "-euxo", "pipefail", "-c"]

ENV APP_ENV=prod

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY --link frankenphp/conf.d/20-app.prod.ini $PHP_INI_DIR/app.conf.d/

# prevent the reinstallation of vendors at every changes in the source code
COPY --link composer.* symfony.* ./
RUN composer install --no-cache --prefer-dist --no-dev --no-autoloader --no-scripts --no-progress

# copy sources
COPY --link --exclude=frankenphp/ . ./

RUN <<-EOF
	mkdir -p var/cache var/log var/share
	composer dump-autoload --classmap-authoritative --no-dev
	composer dump-env prod
	composer run-script --no-dev post-install-cmd
	if [ -f importmap.php ]; then
		php bin/console asset-map:compile
	fi
	chmod +x bin/console
	chmod -R g=u var
	sync
EOF

# Collect shared libraries needed by FrankenPHP and PHP extensions
# hadolint ignore=DL3008,SC3054
RUN <<-'EOF'
	apt-get update
	apt-get install -y --no-install-recommends libtree
	mkdir -p /tmp/libs
	BINARIES=(frankenphp php file opusenc)
	# pg_dump and pg_restore (database backups): the real binaries, not Debian's Perl wrappers in /usr/bin.
	mkdir -p /tmp/pgbin
	cp /usr/lib/postgresql/*/bin/pg_dump /usr/lib/postgresql/*/bin/pg_restore /tmp/pgbin/
	for target in $(printf '%s\n' "${BINARIES[@]}" | xargs -I{} which {}) /tmp/pgbin/* \
		$(find "$(php -r 'echo ini_get("extension_dir");')" -maxdepth 2 -name "*.so"); do
		libtree -pv "$target" 2>/dev/null | grep -oP '(?:── )\K/\S+(?= \[)' | while IFS= read -r lib; do
			[ -f "$lib" ] && cp -n "$lib" /tmp/libs/
		done
	done
	rm -rf /var/lib/apt/lists/*
EOF

# Web client (Echora Web), built from the "web" context: the echora-web repository, WEB_DIR in compose.prod.yaml.
# Its production build calls the API by path (/api/...): the prod image serves it on the same origin, from /srv/web.
FROM oven/bun:1.4-slim AS web_build

WORKDIR /web

COPY --from=web package.json bun.lock ./
RUN bun install --frozen-lockfile

COPY --from=web . ./
RUN bun run build

# Prod FrankenPHP image
FROM debian:13-slim AS frankenphp_prod

SHELL ["/bin/bash", "-euxo", "pipefail", "-c"]

ENV APP_ENV=prod
ENV PHP_INI_SCAN_DIR=":/usr/local/etc/php/app.conf.d"

COPY --from=frankenphp_prod_builder /usr/local/bin/frankenphp /usr/local/bin/frankenphp
COPY --from=frankenphp_prod_builder /usr/local/bin/php /usr/local/bin/php
COPY --from=frankenphp_prod_builder /usr/local/bin/docker-php-entrypoint /usr/local/bin/docker-php-entrypoint
COPY --from=frankenphp_prod_builder /usr/local/lib/php/extensions /usr/local/lib/php/extensions
COPY --from=frankenphp_prod_builder /tmp/libs /usr/lib

COPY --from=frankenphp_prod_builder /usr/local/etc/php/conf.d /usr/local/etc/php/conf.d
COPY --from=frankenphp_prod_builder /usr/local/etc/php/php.ini /usr/local/etc/php/php.ini
COPY --from=frankenphp_prod_builder /usr/local/etc/php/app.conf.d /usr/local/etc/php/app.conf.d

COPY --from=frankenphp_prod_builder /etc/frankenphp/Caddyfile /etc/frankenphp/Caddyfile

# CA certificates for TLS, file/libmagic for Symfony MIME type detection
COPY --from=frankenphp_prod_builder /etc/ssl/certs/ca-certificates.crt /etc/ssl/certs/ca-certificates.crt
COPY --from=frankenphp_prod_builder /etc/ssl/openssl.cnf /etc/ssl/openssl.cnf
COPY --from=frankenphp_prod_builder /usr/bin/file /usr/bin/file
COPY --from=frankenphp_prod_builder /usr/lib/file/magic.mgc /usr/lib/file/magic.mgc

# opusenc converts FLAC tracks to Opus (App\Library\OpusTranscoder); its libraries are in /tmp/libs.
COPY --from=frankenphp_prod_builder /usr/bin/opusenc /usr/bin/opusenc
# pg_dump and pg_restore for the database backups (app:database:backup, app:database:restore); libraries in /tmp/libs.
COPY --from=frankenphp_prod_builder /tmp/pgbin/ /usr/local/bin/
# Supercronic and the scheduled jobs (frankenphp/crontab), started by docker-entrypoint.
COPY --from=frankenphp_prod_builder /usr/local/bin/supercronic /usr/local/bin/supercronic
COPY --from=frankenphp_prod_builder /etc/supercronic/crontab /etc/supercronic/crontab

ENV  OPENSSL_CONF=/etc/ssl/openssl.cnf XDG_CONFIG_HOME=/config XDG_DATA_HOME=/data SSL_CERT_FILE=/etc/ssl/certs/ca-certificates.crt

RUN <<-EOF
	mkdir -p /data/caddy /config/caddy
	chown -R www-data:www-data /data /config
	# Remove setuid/setgid bits
	find / -perm /6000 -type f -exec chmod a-s {} + 2>/dev/null || true
EOF

COPY --link --exclude=var --from=frankenphp_prod_builder /app /app
# Group 0 + g=u for arbitrary-UID runtimes (e.g. OpenShift).
COPY --chown=www-data:0 --from=frankenphp_prod_builder /app/var /app/var
RUN chmod g=u /app/var
# Holds the JWT key pair, generated on the first start (jwt_keys volume in compose.prod.yaml).
RUN install -d -o www-data -g 0 -m 0770 /app/config/jwt
# Downloaded images and thumbnails (media volume in compose.prod.yaml). Created here so that Docker gives the new
# volume this owner: a mount point missing from the image belongs to root, and PHP (www-data) couldn't write to it.
RUN install -d -o www-data -g 0 -m 0770 /app/var/media
# Database backups (backups volume, or BACKUP_DIR, in compose.prod.yaml), for the same reason.
RUN install -d -o www-data -g 0 -m 0770 /app/var/backups

COPY --link --chmod=755 frankenphp/docker-entrypoint.sh /usr/local/bin/docker-entrypoint

# Served by Caddy at the root of the site (frankenphp/Caddyfile), next to /api.
COPY --link --from=web_build /web/dist /srv/web

# www-data, numeric so runtimes can verify runAsNonRoot
USER 33

WORKDIR /app

ENTRYPOINT ["docker-entrypoint"]

HEALTHCHECK --start-period=60s CMD ["php", "-r", "exit(false === @file_get_contents('http://localhost:2019/metrics', context: stream_context_create(['http' => ['timeout' => 5]])) ? 1 : 0);"]
CMD [ "frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile" ]
