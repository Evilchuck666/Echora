<?php

namespace App\Download;

/**
 * What to download a track as (GET /api/{kind}/{id}/download?format=...): the client's choice, e.g. Opus while its
 * "Data saver" is on (docs/decisions/0020).
 */
enum DownloadFormat: string
{
    /** The file of the library, FLAC or MP3, as it is. */
    case Original = 'original';

    /** FLAC tracks converted to Opus (256 kbit/s, about a quarter of the size); MP3 tracks as they are. */
    case Opus = 'opus';
}
