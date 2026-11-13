<?php

namespace App\Library;

/**
 * What started a library scan.
 */
enum ScanTrigger: string
{
    /** An administrator, through POST /api/library/scan. */
    case Api = 'api';

    /** Someone running app:library:scan. */
    case Console = 'console';

    /** The nightly scan (frankenphp/crontab). */
    case Schedule = 'schedule';
}
