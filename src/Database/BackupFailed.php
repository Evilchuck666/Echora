<?php

namespace App\Database;

/**
 * pg_dump or pg_restore failed: nothing was written, or nothing was changed.
 */
final class BackupFailed extends \RuntimeException
{
}
