<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Response;
use App\Dto\LibraryScanRequest;
use App\Library\ScanPhase;
use App\Library\ScanReport;
use App\Library\ScanStatus;
use App\Library\ScanTrigger;
use App\Repository\LibraryScanRepository;
use App\State\LatestLibraryScanProvider;
use App\State\LibraryScanProcessor;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * One scan of the music library, asked for by an administrator (POST /api/library/scan, run by the library worker),
 * from the console (app:library:scan) or by the nightly job: its status and, once finished, its report. Administrators
 * only (docs/decisions/0015).
 */
#[ORM\Entity(repositoryClass: LibraryScanRepository::class)]
#[ApiResource(
    operations: [
        new Get(
            uriTemplate: '/library/scan',
            uriVariables: [],
            openapi: new Operation(
                responses: ['404' => new Response('The library was never scanned')],
                summary: 'The latest library scan: queued, running, or its report (administrators only)',
                description: 'Ask again while `status` is `queued` or `running`. While running, `phase` (listing, files, covers, artist_images, in this order), `phaseDone` and `phaseTotal` tell how far it got, updated every second at most. '
                    .'Once `finished`, the counts say what changed; `failures` lists the files that could not be read.',
            ),
            security: "is_granted('ROLE_ADMIN')",
            provider: LatestLibraryScanProvider::class,
        ),
        new Post(
            uriTemplate: '/library/scan',
            status: 202,
            openapi: new Operation(
                responses: [
                    '202' => new Response('Queued: the library worker runs it in the background. Follow it with GET /api/library/scan'),
                    '409' => new Response('A scan is already queued or running'),
                ],
                summary: 'Scans the music library in the background (administrators only)',
                description: 'Adds new files, updates changed ones, follows moved ones and removes the tracks of deleted ones, as app:library:scan does. '
                    .'Send `{}`, or `{"full": true}` to read every file again (after an update of Echora that reads more tags). One scan at a time. '
                    .'The library is also scanned every night.',
            ),
            security: "is_granted('ROLE_ADMIN')",
            input: LibraryScanRequest::class,
            processor: LibraryScanProcessor::class,
        ),
        new GetCollection(
            uriTemplate: '/library/scans',
            openapi: new Operation(summary: 'Every library scan, the latest first (administrators only)'),
            security: "is_granted('ROLE_ADMIN')",
        ),
        new Get(
            uriTemplate: '/library/scans/{id}',
            openapi: new Operation(summary: 'A library scan (administrators only)'),
            security: "is_granted('ROLE_ADMIN')",
        ),
    ],
    normalizationContext: ['groups' => ['library_scan:read']],
    order: ['id' => 'DESC'],
)]
class LibraryScan
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['library_scan:read'])]
    private Uuid $id;

    #[ORM\Column(length: 16, enumType: ScanStatus::class)]
    #[ApiProperty(description: 'queued, running, finished or failed')]
    #[Groups(['library_scan:read'])]
    private ScanStatus $status = ScanStatus::Queued;

    #[ORM\Column]
    #[Groups(['library_scan:read'])]
    private \DateTimeImmutable $requestedAt;

    #[ORM\Column(nullable: true)]
    #[Groups(['library_scan:read'])]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['library_scan:read'])]
    private ?\DateTimeImmutable $finishedAt = null;

    #[ORM\Column(length: 16, nullable: true, enumType: ScanPhase::class)]
    #[ApiProperty(description: 'While running: listing (finding the files), files (reading them), covers (albums), then artist_images (artists, 30 TheAudioDB lookups a minute at most). Null otherwise')]
    #[Groups(['library_scan:read'])]
    private ?ScanPhase $phase = null;

    #[ORM\Column(nullable: true)]
    #[ApiProperty(description: 'While running: how many files, albums or artists of the phase are done (updated every second at most)')]
    #[Groups(['library_scan:read'])]
    private ?int $phaseDone = null;

    #[ORM\Column(nullable: true)]
    #[ApiProperty(description: 'While running: how many files, albums or artists the phase has (0 while listing, as it is not known yet)')]
    #[Groups(['library_scan:read'])]
    private ?int $phaseTotal = null;

    #[ORM\Column(nullable: true)]
    #[ApiProperty(description: 'New files, now tracks')]
    #[Groups(['library_scan:read'])]
    private ?int $added = null;

    #[ORM\Column(nullable: true)]
    #[ApiProperty(description: 'Files moved or renamed, found again by their audio: their tracks were kept')]
    #[Groups(['library_scan:read'])]
    private ?int $moved = null;

    #[ORM\Column(nullable: true)]
    #[ApiProperty(description: 'Changed files, read again')]
    #[Groups(['library_scan:read'])]
    private ?int $updated = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['library_scan:read'])]
    private ?int $unchanged = null;

    #[ORM\Column(nullable: true)]
    #[ApiProperty(description: 'Tracks whose file is gone, deleted')]
    #[Groups(['library_scan:read'])]
    private ?int $removed = null;

    #[ORM\Column(nullable: true)]
    #[ApiProperty(description: 'Files that could not be read, and were skipped (see failures)')]
    #[Groups(['library_scan:read'])]
    private ?int $failed = null;

    /** @var array<string, string>|null */
    #[ORM\Column(nullable: true)]
    #[ApiProperty(
        description: 'Why each skipped file could not be read, by its path inside the music library',
        schema: ['type' => 'object', 'additionalProperties' => ['type' => 'string']],
    )]
    #[Groups(['library_scan:read'])]
    private ?array $failures = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['library_scan:read'])]
    private ?int $coversUpdated = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['library_scan:read'])]
    private ?int $coversDownloaded = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['library_scan:read'])]
    private ?int $artistImagesUpdated = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['library_scan:read'])]
    private ?int $artistImagesDownloaded = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[ApiProperty(description: 'Why TheAudioDB lookups stopped during the scan, if they did: the rest waits for the next scan')]
    #[Groups(['library_scan:read'])]
    private ?string $theAudioDbFailure = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[ApiProperty(description: 'Why the scan failed')]
    #[Groups(['library_scan:read'])]
    private ?string $error = null;

    public function __construct(
        // "full" is a reserved word in PostgreSQL (FULL JOIN), which Doctrine doesn't quote.
        #[ORM\Column(name: 'full_scan')]
        #[ApiProperty(description: 'Whether every file was read again, even unchanged ones')]
        #[Groups(['library_scan:read'])]
        private bool $full,
        #[ORM\Column(length: 16, enumType: ScanTrigger::class)]
        #[ApiProperty(description: 'api (an administrator), console (app:library:scan) or schedule (the nightly scan)')]
        #[Groups(['library_scan:read'])]
        private ScanTrigger $trigger,
    ) {
        $this->id = Uuid::v7();
        $this->requestedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getStatus(): ScanStatus
    {
        return $this->status;
    }

    public function isFull(): bool
    {
        return $this->full;
    }

    public function getTrigger(): ScanTrigger
    {
        return $this->trigger;
    }

    public function getRequestedAt(): \DateTimeImmutable
    {
        return $this->requestedAt;
    }

    public function getStartedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function getPhase(): ?ScanPhase
    {
        return $this->phase;
    }

    public function getPhaseDone(): ?int
    {
        return $this->phaseDone;
    }

    public function getPhaseTotal(): ?int
    {
        return $this->phaseTotal;
    }

    public function getAdded(): ?int
    {
        return $this->added;
    }

    public function getMoved(): ?int
    {
        return $this->moved;
    }

    public function getUpdated(): ?int
    {
        return $this->updated;
    }

    public function getUnchanged(): ?int
    {
        return $this->unchanged;
    }

    public function getRemoved(): ?int
    {
        return $this->removed;
    }

    public function getFailed(): ?int
    {
        return $this->failed;
    }

    /**
     * @return array<string, string>|null
     */
    public function getFailures(): ?array
    {
        return $this->failures;
    }

    public function getCoversUpdated(): ?int
    {
        return $this->coversUpdated;
    }

    public function getCoversDownloaded(): ?int
    {
        return $this->coversDownloaded;
    }

    public function getArtistImagesUpdated(): ?int
    {
        return $this->artistImagesUpdated;
    }

    public function getArtistImagesDownloaded(): ?int
    {
        return $this->artistImagesDownloaded;
    }

    public function getTheAudioDbFailure(): ?string
    {
        return $this->theAudioDbFailure;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function start(): void
    {
        $this->status = ScanStatus::Running;
        $this->startedAt = new \DateTimeImmutable();
    }

    public function finish(ScanReport $report): void
    {
        $this->forgetProgress();
        $this->status = ScanStatus::Finished;
        $this->finishedAt = new \DateTimeImmutable();
        $this->added = $report->added;
        $this->moved = $report->moved;
        $this->updated = $report->updated;
        $this->unchanged = $report->unchanged;
        $this->removed = $report->removed;
        $this->failed = \count($report->failed);
        $this->failures = $report->failed;
        $this->coversUpdated = $report->coversUpdated;
        $this->coversDownloaded = $report->coversDownloaded;
        $this->artistImagesUpdated = $report->artistImagesUpdated;
        $this->artistImagesDownloaded = $report->artistImagesDownloaded;
        $this->theAudioDbFailure = $report->theAudioDbFailure;
    }

    public function fail(string $error): void
    {
        $this->forgetProgress();
        $this->status = ScanStatus::Failed;
        $this->finishedAt = new \DateTimeImmutable();
        $this->error = $error;
    }

    /** The progress is written while the scan runs by App\Library\LibraryScanProgress, with SQL. */
    private function forgetProgress(): void
    {
        $this->phase = null;
        $this->phaseDone = null;
        $this->phaseTotal = null;
    }
}
