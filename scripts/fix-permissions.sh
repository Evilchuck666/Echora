#!/usr/bin/env bash
# Give back to the host user the files created as root inside the php container
# (composer require/update/remove, bin/console make:*, Flex recipes...).
set -euo pipefail

cd "$(dirname "$0")/.."

uid="$(id -u)"
gid="$(id -g)"

# var/ is excluded: on the host it is only the mount point of a Docker volume.
root_owned="$(find . -path ./.git -prune -o -path ./var -prune -o ! -user "$uid" -print | wc -l)"

if [ "$root_owned" -eq 0 ]; then
	echo "All project files already belong to $(id -un)."
	exit 0
fi

echo "Changing the owner of $root_owned file(s) to $(id -un)..."

# Reuse the running container when possible; otherwise start a throwaway one.
if [ -n "$(docker compose ps --status running --quiet php 2>/dev/null)" ]; then
	docker compose exec -T php chown -R "$uid:$gid" .
else
	docker compose run --rm -T php chown -R "$uid:$gid" .
fi

echo "Done."
