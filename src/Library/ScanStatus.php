<?php

namespace App\Library;

/**
 * Where a library scan stands (App\Entity\LibraryScan).
 */
enum ScanStatus: string
{
    /** Asked for, waiting for the library worker. */
    case Queued = 'queued';

    case Running = 'running';

    /** Done: the report is filled. */
    case Finished = 'finished';

    /** Stopped: see the error (the music folder missing, the worker interrupted...). */
    case Failed = 'failed';
}
