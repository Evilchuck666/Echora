<?php

namespace App\Library;

/**
 * The answer to POST /api/transcodes: where each track that has an Opus version stands. Tracks without one (MP3) and
 * unknown tracks are in none of the lists.
 */
final class TranscodeRequestOutcome
{
    /** @var list<string> Identifiers of the tracks that will be converted in the background, in this order */
    public array $queued = [];

    /** @var list<string> Identifiers of the tracks already converted */
    public array $ready = [];

    /** @var list<string> Identifiers of the tracks being converted right now (by a player's request or a worker) */
    public array $converting = [];
}
