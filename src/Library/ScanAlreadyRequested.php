<?php

namespace App\Library;

use App\Entity\LibraryScan;

/**
 * A scan is already queued or running: one at a time.
 */
final class ScanAlreadyRequested extends \DomainException
{
    public function __construct(public readonly LibraryScan $pending)
    {
        parent::__construct(\sprintf('A library scan is already %s (requested at %s UTC): wait for it to finish.', $pending->getStatus()->value, $pending->getRequestedAt()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i')));
    }
}
