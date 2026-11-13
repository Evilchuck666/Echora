<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\LibraryScanRequest;
use App\Entity\LibraryScan;
use App\Library\LibraryScans;
use App\Library\ScanAlreadyRequested;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * POST /api/library/scan: queues a scan for the library worker, unless one is queued or running already (409).
 *
 * @implements ProcessorInterface<LibraryScanRequest, LibraryScan>
 */
final readonly class LibraryScanProcessor implements ProcessorInterface
{
    public function __construct(private LibraryScans $scans)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): LibraryScan
    {
        try {
            return $this->scans->request($data->full);
        } catch (ScanAlreadyRequested $e) {
            throw new ConflictHttpException($e->getMessage(), $e);
        }
    }
}
