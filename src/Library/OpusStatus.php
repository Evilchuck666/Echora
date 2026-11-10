<?php

namespace App\Library;

/**
 * Where the Opus version of a FLAC track stands (OpusTranscoder::check()).
 */
enum OpusStatus
{
    /** Converted: served at once. */
    case Ready;

    /** Another process is converting it right now. */
    case Converting;

    /** Not converted yet: the first request will wait for the conversion. */
    case Missing;
}
