#!/usr/bin/env bash
# Each check's condition is written in single quotes on purpose: check() evaluates it later.
# shellcheck disable=SC2016
# Checks a running production Echora from the outside: the web client at the root of the site, the API next to it,
# signing in, media served by Caddy, and what must stay closed. Run by CI after starting the production image, and
# useful after a deploy:
#
#   scripts/smoke-test-prod.sh https://music.example.com <username> <password>
#
# The account needs a scanned library with at least one track, one album cover and one artist image. The username may
# be the email. If the account is an administrator, a (quick) scan of the library is run and followed. Its profile picture is left alone; one is set for a moment only if it has none.
# SMOKE_INSECURE=1 accepts a certificate the system doesn't trust (Caddy's local authority, e.g. in CI).
set -euo pipefail

if [ $# -ne 3 ]; then
    echo "Usage: $0 <base URL> <username> <password>" >&2
    exit 2
fi
BASE=${1%/}
USERNAME=$2
PASSWORD=$3

CURL=(curl --silent --show-error --max-time 30)
if [ "${SMOKE_INSECURE:-0}" = 1 ]; then
    CURL+=(--insecure)
fi

WORK=$(mktemp -d)
trap 'rm -rf "$WORK"' EXIT
FAILURES=0

pass() { printf '  \033[32mok\033[0m    %s\n' "$1"; }
fail() { printf '  \033[31mFAIL\033[0m  %s\n' "$1"; FAILURES=$((FAILURES + 1)); }
check() { if eval "$2"; then pass "$1"; else fail "$1"; fi; }

# GET (or other) request: status, headers and body go to $WORK/status, $WORK/headers, $WORK/body.
request() {
    "${CURL[@]}" --output "$WORK/body" --dump-header "$WORK/headers" --write-out '%{http_code}' "$@" > "$WORK/status" || echo 000 > "$WORK/status"
}
status() { cat "$WORK/status"; }
header() { grep -i "^$1:" "$WORK/headers" | tail -1 | cut -d: -f2- | tr -d '\r' | sed 's/^ *//'; }
json() { python3 -c 'import json, sys; data = json.load(open(sys.argv[1])); print(eval(sys.argv[2], {"data": data}) or "")' "$WORK/body" "$1" 2>/dev/null || true; }

echo "Web client"
request "$BASE/"
check "/ serves the app" '[ "$(status)" = 200 ] && grep -q "<title>Echora</title>" "$WORK/body"'
check "/ is checked on every visit (no-cache)" '[ "$(header Cache-Control)" = no-cache ]'
ASSET=$(grep -o '/assets/[^"]*\.js' "$WORK/body" | head -1 || true)
request "$BASE/artists/any-route-of-the-app"
check "routes of the app fall back to the app" '[ "$(status)" = 200 ] && grep -q "<title>Echora</title>" "$WORK/body"'
request "$BASE$ASSET"
check "built assets are cached for good" '[ "$(status)" = 200 ] && [[ "$(header Cache-Control)" == *immutable* ]]'
request "$BASE/assets/missing-$RANDOM.js"
check "a missing asset is a 404, not the app" '[ "$(status)" = 404 ]'

echo "API"
request "$BASE/api/docs.jsonopenapi"
check "the OpenAPI document is public" '[ "$(status)" = 200 ] && [ -n "$(json "data[\"openapi\"]")" ]'
request "$BASE/bundles/apiplatform/swagger-ui/swagger-ui.css"
check "the documentation assets are served" '[ "$(status)" = 200 ]'
request "$BASE/api/artists"
check "the library needs a JWT" '[ "$(status)" = 401 ]'
request -X OPTIONS -H "Origin: https://elsewhere.example" -H "Access-Control-Request-Method: POST" "$BASE/api/auth"
check "other sites may not call the API from a browser (CORS)" '[ -z "$(header Access-Control-Allow-Origin)" ]'

echo "Account"
request -X POST -H "Content-Type: application/json" -d "{\"username\": \"$USERNAME\", \"password\": \"$PASSWORD\"}" "$BASE/api/auth"
check "signing in returns a JWT and a refresh token" '[ "$(status)" = 200 ] && [ -n "$(json "data[\"refresh_token\"]")" ]'
TOKEN=$(json 'data["token"]')
REFRESH=$(json 'data["refresh_token"]')
AUTH=(-H "Authorization: Bearer $TOKEN" -H "Accept: application/json")
request "${AUTH[@]}" "$BASE/api/me"
# The account signs in with its username or its email.
check "the JWT works" '[ "$(status)" = 200 ] && { [ "$(json "data[\"username\"]")" = "$USERNAME" ] || [ "$(json "data.get(\"email\")")" = "$USERNAME" ]; }'

echo "Accounts"
request -H "Accept: application/json" "$BASE/api/setup"
check "setup is closed once the server has an account" '[ "$(status)" = 200 ] && [ "$(json "str(data[\"required\"])")" = False ]'
request -X POST -H "Content-Type: application/json" -d '{"email": "intruder@example.com", "name": "Intruder", "password": "intruder-password"}' "$BASE/api/setup"
check "nobody else can set it up" '[ "$(status)" = 409 ]'
request "${AUTH[@]}" "$BASE/api/me"
AVATAR=$(json 'data.get("avatarUrl")')
if [ -z "$AVATAR" ]; then
    # The account has no picture: give it one for a moment (a 1×1 PNG), then remove it.
    python3 -c 'import base64, sys; open(sys.argv[1], "wb").write(base64.b64decode("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=="))' "$WORK/avatar.png"
    request "${AUTH[@]}" -F "file=@$WORK/avatar.png;type=image/png" "$BASE/api/me/avatar"
    check "a profile picture is uploaded (written to the media volume)" '[ "$(status)" = 200 ] && [ -n "$(json "data[\"avatarUrl\"]")" ]'
    UPLOADED=$(json 'data["avatarUrl"]')
    request "$UPLOADED"
    check "the profile picture, without the JWT" '[ "$(status)" = 200 ] && [ "$(header Content-Type)" = image/jpeg ]'
    request "${AUTH[@]}" -X DELETE "$BASE/api/me/avatar"
    check "the profile picture is removed again" '[ "$(status)" = 204 ]'
else
    # The account's own picture stays as it is.
    request "$AVATAR"
    check "the profile picture, without the JWT" '[ "$(status)" = 200 ] && [ "$(header Content-Type)" = image/jpeg ]'
fi

echo "Media (sent by Caddy)"
request "${AUTH[@]}" "$BASE/api/tracks"
STREAM=$(json 'data[0]["streamUrl"]')
check "tracks come with a signed streamUrl" '[ -n "$STREAM" ]'
request -H "Range: bytes=0-99" "$STREAM"
check "a range of the audio, without the JWT" '[ "$(status)" = 206 ] && [ "$(wc -c < "$WORK/body")" -eq 100 ]'
check "the internal X-Accel-Redirect header never reaches clients" '[ -z "$(header X-Accel-Redirect)" ] && [ -n "$(header Content-Range)" ]'
request "$STREAM"
check "the whole audio file" '[ "$(status)" = 200 ] && [ "$(wc -c < "$WORK/body")" -eq "$(header Content-Length)" ] && [ "$(header Content-Length)" -gt 0 ]'
request "${STREAM%%\?*}"
check "the audio needs its signature" '[ "$(status)" = 401 ]'
request "${AUTH[@]}" "$BASE/api/albums"
COVER=$(json 'next((a["coverUrl"] for a in data if a.get("coverUrl")), "")')
request "$COVER"
check "an album cover" '[ -n "$COVER" ] && [ "$(status)" = 200 ] && [[ "$(header Content-Type)" == image/* ]]'
request "${AUTH[@]}" "$BASE/api/artists"
PHOTO=$(json 'next((a["coverUrl"] for a in data if a.get("coverUrl")), "")')
request "$PHOTO"
# Made on the first request and written to the media volume: fails if PHP can't write there.
check "an artist thumbnail (written to the media volume)" '[ -n "$PHOTO" ] && [ "$(status)" = 200 ] && [ "$(header Content-Type)" = image/jpeg ]'

echo "Opus (converted by the background workers)"
request "${AUTH[@]}" "$BASE/api/tracks"
FLAC=$(json 'next((t["id"] for t in data if t.get("opusStreamUrl")), "")')
OPUS=$(json 'next((t["opusStreamUrl"] for t in data if t.get("opusStreamUrl")), "")')
if [ -z "$FLAC" ]; then
    printf '  skip  %s\n' "no FLAC track on the first page of tracks"
else
    DEVICE=$(python3 -c 'import uuid; print(uuid.uuid4())')
    transcode() { request "${AUTH[@]}" -X POST -H "Content-Type: application/json" -d "{\"device\": \"$DEVICE\", \"tracks\": [\"$FLAC\"]}" "$BASE/api/transcodes"; }
    transcode
    check "converting ahead of time is accepted at once" '[ "$(status)" = 202 ]'
    # Asking again tells where the track stands; ready at once if an earlier run (or a player) converted it.
    for _ in $(seq 1 30); do
        [ "$(json "\"$FLAC\" in data[\"ready\"]")" = True ] && break
        sleep 2
        transcode
    done
    check "a background worker converted it (written to the media volume)" '[ "$(json "\"$FLAC\" in data[\"ready\"]")" = True ]'
    request -H "Range: bytes=0-99" "$OPUS"
    check "a range of the Opus version, without the JWT" '[ "$(status)" = 206 ] && [ "$(wc -c < "$WORK/body")" -eq 100 ] && [[ "$(header Content-Type)" == audio/ogg* ]]'
fi

echo "Library scan (run by the library worker)"
request "${AUTH[@]}" "$BASE/api/me"
if [ "$(json 'data["admin"]')" = True ]; then
    # A quick scan, like the nightly one: only what changed is read. A scan already running (409) is followed instead.
    request "${AUTH[@]}" -X POST -H "Content-Type: application/json" -d '{}' "$BASE/api/library/scan"
    check "a scan is queued at once (or one is already running)" '[ "$(status)" = 202 ] || [ "$(status)" = 409 ]'
    for _ in $(seq 1 150); do
        request "${AUTH[@]}" "$BASE/api/library/scan"
        SCAN=$(json 'data["status"]')
        { [ "$SCAN" = finished ] || [ "$SCAN" = failed ]; } && break
        sleep 2
    done
    check "the library worker finished it" '[ "$SCAN" = finished ]'
else
    request "${AUTH[@]}" "$BASE/api/library/scan"
    check "only administrators scan the library" '[ "$(status)" = 403 ]'
fi

echo "Downloads (sent by Caddy)"
request "${AUTH[@]}" "$BASE/api/tracks"
TRACK_ID=$(json 'data[0]["id"]')
request "${AUTH[@]}" "$BASE/api/tracks/$TRACK_ID/download"
FILE=$(json 'data["tracks"][0]["url"]')
check "a track has a download manifest" '[ "$(status)" = 200 ] && [ -n "$FILE" ] && [ -n "$(json "data[\"tracks\"][0][\"savePath\"]")" ]'
request -H "Range: bytes=0-99" "$FILE"
check "its file is an attachment with a name, without the JWT, by ranges" '[ "$(status)" = 206 ] && [[ "$(header Content-Disposition)" == attachment* ]] && [[ "$(header Content-Disposition)" == *filename=* ]]'

echo "Session"
request -X POST -H "Content-Type: application/json" -d "{\"refresh_token\": \"$REFRESH\"}" "$BASE/api/token/refresh"
check "the refresh token gives a new pair" '[ "$(status)" = 200 ] && [ -n "$(json "data[\"refresh_token\"]")" ]'
REFRESH=$(json 'data["refresh_token"]')
request -X POST -H "Content-Type: application/json" -d "{\"refresh_token\": \"$REFRESH\"}" "$BASE/api/logout"
check "signing out" '[ "$(status)" = 200 ]'
request -X POST -H "Content-Type: application/json" -d "{\"refresh_token\": \"$REFRESH\"}" "$BASE/api/token/refresh"
check "a signed-out refresh token is refused" '[ "$(status)" = 401 ]'

echo "Sign-in throttling"
# A made-up account, never the one given: 5 failed attempts lock it for this address. They also take 6 of the 25
# failed attempts this address may make in 15 minutes.
NOBODY="smoke-throttle-$RANDOM$RANDOM"
for _ in 1 2 3 4 5; do
    request -X POST -H "Content-Type: application/json" -d "{\"username\": \"$NOBODY\", \"password\": \"wrong-password\"}" "$BASE/api/auth"
done
check "5 failed sign-ins are refused one by one" '[ "$(status)" = 401 ]'
request -X POST -H "Content-Type: application/json" -d "{\"username\": \"$NOBODY\", \"password\": \"wrong-password\"}" "$BASE/api/auth"
check "the next one is throttled, with Retry-After" '[ "$(status)" = 429 ] && [ "$(header Retry-After)" -gt 0 ]'

if [ "$FAILURES" -gt 0 ]; then
    echo "$FAILURES check(s) failed."
    exit 1
fi
echo "All checks passed."
