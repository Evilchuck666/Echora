<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Entity\LibraryScan;
use App\Repository\LibraryScanRepository;

/**
 * GET /api/library/scan: the latest scan (404 when the library was never scanned).
 *
 * @implements ProviderInterface<LibraryScan>
 */
final readonly class LatestLibraryScanProvider implements ProviderInterface
{
    public function __construct(private LibraryScanRepository $scans)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ?LibraryScan
    {
        return $this->scans->findLatest();
    }
}
