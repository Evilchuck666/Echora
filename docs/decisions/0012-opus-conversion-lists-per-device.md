# 0012. One list per device for converting ahead of time, and lists that take turns

- **Date:** 2026-11-10
- **Status:** Accepted

## Context

With [0011](0011-opus-conversion-ahead-of-time.md), each request to `POST /api/transcodes` replaced the previous one
of the same account. Picture an account with "Data saver" on both a phone and a tablet: when the user switches from
one to the other, each device's list replaces the other's. Also, a worker converted the whole list of a request
before moving on to the next one. With one worker, a second list waited behind up to 20 conversions of the first,
about a minute and a half.

## Decision

- Each device has its own list.
  - The body takes a `device`: a UUID that the client generates once and keeps.
  - The field is required: the owner preferred that to an optional field meaning "one list for the account".
  - A request replaces only the previous one of the same device.
  - Tabs of one browser share their storage, so they are one device.
- An account keeps the lists of its 5 most recent devices (`TranscodeQueue::MAX_DEVICES`) and drops the others.
  - The server can't tell a real device from a made-up id.
  - So a client that sends a new id with every request (a bug) can't make lists pile up.
  - The latest request of each device is kept in one `cache.app` item per account. It is updated under a lock, so
    two devices sending their lists at once don't lose one.
- Lists take turns: a message converts one track and sends the rest of its list back to the end of the queue.
  - The lists of every device and account then alternate, each keeping its own order.
  - Tracks that need nothing (ready, being converted, gone) don't use the turn.
- Deriving the device from the session (the refresh token) was weighed and left out. It would need a session id that
  survives every rotation of the refresh token, which means changing the authentication for a personal service.

## Consequences

- Clients have to generate and keep a device id. A request without one is refused (`422`).
- At most one message per list is in the queue at a time. A list of 20 tracks goes through the queue 20 times, which
  costs a few queries each.
- A list from a sixth device drops the list of the least recent device of the account.
- 0011 said that changed worker code only needs `messenger:stop-workers`. Trying this change showed that an idle worker
  only sees that signal when the next message wakes it up, and handles that message with the old code first. Here the
  message class had changed, so that worker crashed. Restarting the container (`docker compose restart php`) is what
  loads new code. A deploy replaces the container anyway.

## Origin

Asked from the Echora Web repository (2026-11-10). The owner wanted the phone and tablet of one account to keep their
own lists. The turns and the five-device limit were proposed with it and accepted, and the owner made `device`
required.

## Commits

- `bf2c998` feat(transcoding): implement device-specific transcoding lists and improve request handling
