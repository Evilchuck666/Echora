#!/usr/bin/env bash
# PostToolUse(Write|Edit) hook: format the edited PHP file with PHP-CS-Fixer
# (@Symfony, .php-cs-fixer.dist.php) inside the php container.
set -uo pipefail

file="$(jq -r '.tool_response.filePath // .tool_input.file_path // empty')"

case "$file" in
	"$CLAUDE_PROJECT_DIR"/*.php) ;;
	*) exit 0 ;;
esac

relative="${file#"$CLAUDE_PROJECT_DIR"/}"

case "$relative" in
	vendor/* | var/*) exit 0 ;;
esac

cd "$CLAUDE_PROJECT_DIR" || exit 0

# Nothing to do when the stack is down; the CI still checks the style.
[ -n "$(docker compose ps --status running --quiet php 2>/dev/null)" ] || exit 0

# intersection: respect the Finder exclusions of .php-cs-fixer.dist.php.
if ! out="$(docker compose exec -T php vendor/bin/php-cs-fixer fix --quiet --path-mode=intersection -- "$relative" 2>&1)"; then
	echo "php-cs-fixer failed on $relative: $out" >&2
	exit 1
fi

exit 0
