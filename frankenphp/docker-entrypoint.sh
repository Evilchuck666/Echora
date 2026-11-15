#!/bin/sh
set -e

if [ "$1" = 'frankenphp' ] || [ "$1" = 'php' ] || [ "$1" = 'bin/console' ]; then

	# Secrets (docs/decisions/0021). Production gets them from prod.env, and refuses to start with empty, sample or short
	# ones: anyone could sign media URLs with a known APP_SECRET. Development generates its own, once per machine, in
	# .env.local (ignored by git): none is committed. Both before anything boots the kernel (composer, bin/console).
	if [ "$APP_ENV" = 'prod' ]; then
		for name in APP_SECRET JWT_PASSPHRASE MERCURE_PUBLISHER_JWT_KEY; do
			eval "value=\${$name:-}"
			case "$value" in
				*[Cc]hange[Mm]e* | *[Cc]hange[Tt]his*) problem='is still the sample value of prod.env.example' ;;
				*) [ ${#value} -lt 32 ] && problem='is empty or shorter than 32 characters' || problem='' ;;
			esac
			if [ -n "$problem" ]; then
				echo "$name $problem. Set a long random value in prod.env (e.g. the output of: openssl rand -hex 32)." >&2
				exit 1
			fi
		done
		case "${DATABASE_URL:-}" in
			*[Cc]hange[Mm]e*)
				echo 'POSTGRES_PASSWORD is still the sample value of prod.env.example. Set a random one in prod.env.' >&2
				exit 1
				;;
		esac
	else
		touch .env.local
		if ! grep -q '^APP_SECRET=.' .env.local; then
			echo "APP_SECRET=$(php -r 'echo bin2hex(random_bytes(32));')" >> .env.local
		fi
		if ! grep -q '^JWT_PASSPHRASE=.' .env.local; then
			echo "JWT_PASSPHRASE=$(php -r 'echo bin2hex(random_bytes(32));')" >> .env.local
			# Keys made with a former passphrase (the one .env used to hold) can't be read with this one: new keys below.
			rm -f config/jwt/private.pem config/jwt/public.pem
		fi
		# Bind-mounted from the host: give the file to the owner of the project, not to root.
		chown "$(stat -c '%u:%g' .env)" .env.local 2>/dev/null || true
	fi

	if [ -z "$(ls -A 'vendor/' 2>/dev/null)" ]; then
		composer install --prefer-dist --no-progress --no-interaction
	fi

	# Display information about the current project
	# Or about an error in project initialization
	php bin/console -V

	if grep -q ^DATABASE_URL= .env; then
		echo 'Waiting for database to be ready...'
		ATTEMPTS_LEFT_TO_REACH_DATABASE=60
		until [ $ATTEMPTS_LEFT_TO_REACH_DATABASE -eq 0 ] || DATABASE_ERROR=$(php bin/console dbal:run-sql -q "SELECT 1" 2>&1); do
			if [ $? -eq 255 ]; then
				# If the Doctrine command exits with 255, an unrecoverable error occurred
				ATTEMPTS_LEFT_TO_REACH_DATABASE=0
				break
			fi
			sleep 1
			ATTEMPTS_LEFT_TO_REACH_DATABASE=$((ATTEMPTS_LEFT_TO_REACH_DATABASE - 1))
			echo "Still waiting for database to be ready... Or maybe the database is not reachable. $ATTEMPTS_LEFT_TO_REACH_DATABASE attempts left."
		done

		if [ $ATTEMPTS_LEFT_TO_REACH_DATABASE -eq 0 ]; then
			echo 'The database is not up or not reachable:'
			echo "$DATABASE_ERROR"
			exit 1
		else
			echo 'The database is now ready and reachable'
		fi

		if find ./migrations -iname '*.php' -print -quit | grep --quiet .; then
			php bin/console doctrine:migrations:migrate --no-interaction --all-or-nothing
		fi
	fi

	# Key pair that signs the JWTs: created on the first start, kept afterwards
	# (bind-mounted config/jwt in dev, the jwt_keys volume in prod).
	if grep -q ^JWT_PASSPHRASE= .env; then
		php bin/console lexik:jwt:generate-keypair --skip-if-exists
		chmod 600 config/jwt/private.pem
		chmod 644 config/jwt/public.pem
		# The tests' own pair (config/jwt/test, with the public passphrase of .env.test).
		if [ "$APP_ENV" != 'prod' ]; then
			mkdir -p config/jwt/test
			php bin/console lexik:jwt:generate-keypair --skip-if-exists --env=test
			chmod 600 config/jwt/test/private.pem
			chmod 644 config/jwt/test/public.pem
			chown -R "$(stat -c '%u:%g' .env)" config/jwt 2>/dev/null || true
		fi
	fi

	echo 'PHP app ready!'
fi

# Scheduled jobs (frankenphp/crontab), next to the web server only: not for one-off commands
# (docker compose run php bin/console ...). Its logs go to the container's output.
if [ "$1" = 'frankenphp' ] && [ -f /etc/supercronic/crontab ]; then
	supercronic /etc/supercronic/crontab &
fi

# Background workers (Symfony Messenger, transport "transcodes"): convert to Opus the tracks that clients are about to
# play (POST /api/transcodes), outside the PHP workers that serve requests. One opusenc per worker at most:
# TRANSCODE_WORKERS sets how many run at once (0: none). Each one restarts every hour. To load changed code in
# development, restart the container: messenger:stop-workers only reaches a worker when it wakes up, and it handles
# that message with the old code first.
if [ "$1" = 'frankenphp' ]; then
	case "${TRANSCODE_WORKERS:-1}" in
		'' | *[!0-9]*)
			echo "TRANSCODE_WORKERS must be a whole number, not \"$TRANSCODE_WORKERS\": no transcode workers started." >&2
			;;
		*)
			worker=0
			while [ "$worker" -lt "${TRANSCODE_WORKERS:-1}" ]; do
				(
					while true; do
						php bin/console messenger:consume transcodes --time-limit=3600 --memory-limit=256M --no-interaction || sleep 5
					done
				) &
				worker=$((worker + 1))
			done
			;;
	esac
fi

# The library worker (Symfony Messenger, transport "library"): runs the scans that administrators ask for
# (POST /api/library/scan), one at a time, outside the PHP workers that serve requests and apart from the Opus workers,
# so that a long scan holds up neither. Restarts every hour, between two scans.
if [ "$1" = 'frankenphp' ]; then
	(
		while true; do
			php bin/console messenger:consume library --time-limit=3600 --no-interaction || sleep 5
		done
	) &
fi

exec docker-php-entrypoint "$@"
