#!/usr/bin/env bash
# PostToolUse(Bash) hook: after a `docker compose exec` or `docker compose run`,
# give the files created as root inside the container back to the host user.
set -uo pipefail

cmd="$(jq -r '.tool_input.command // empty')"

grep -qE 'docker[ -]compose\b.*\b(exec|run)\b' <<<"$cmd" || exit 0

if ! out="$("$CLAUDE_PROJECT_DIR/scripts/fix-permissions.sh" 2>&1)"; then
	echo "scripts/fix-permissions.sh failed: $out" >&2
	exit 1
fi

# Stay silent when there was nothing to fix.
case "$out" in
	*Changing*)
		jq -n --arg m "$out" '{
			systemMessage: $m,
			hookSpecificOutput: {
				hookEventName: "PostToolUse",
				additionalContext: ("scripts/fix-permissions.sh ran automatically: " + $m)
			}
		}'
		;;
esac

exit 0
