#!/bin/sh
set -e

if [ "$1" = 'frankenphp' ] || [ "$1" = 'php' ] || [ "$1" = 'bin/console' ]; then

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

exec docker-php-entrypoint "$@"
