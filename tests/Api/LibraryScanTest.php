<?php

namespace App\Tests\Api;

use ApiPlatform\Test\ApiTestCase;
use ApiPlatform\Test\Client;
use App\Entity\LibraryScan;
use App\Library\ScanTrigger;
use App\Message\ScanLibrary;
use App\MessageHandler\ScanLibraryHandler;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Administrators scan the library through the API: POST /api/library/scan queues it for the library worker, GET
 * /api/library/scan follows it (the transport is in memory in tests: they call the handler themselves).
 */
final class LibraryScanTest extends ApiTestCase
{
    use CreatesUsers;

    protected static ?bool $alwaysBootKernel = true;

    private Client $client;
    private string $token;

    /** @var list<ScanLibrary> */
    private array $pending = [];

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->token = self::tokenFor(self::createUser('admin', admin: true));
    }

    public function testAScanIsQueuedThenRunInTheBackgroundAndItsReportIsShown(): void
    {
        $this->request('GET', '/api/library/scan');
        self::assertResponseStatusCodeSame(404, 'Never scanned.');

        $queued = $this->request('POST', '/api/library/scan', [])->toArray();

        self::assertResponseStatusCodeSame(202);
        self::assertSame(['queued', false, 'api'], [$queued['status'], $queued['full'], $queued['trigger']]);
        self::assertArrayNotHasKey('added', $queued, 'No report yet.');
        self::assertSame('queued', $this->request('GET', '/api/library/scan')->toArray()['status']);
        self::assertCount(1, $this->pending);

        $this->runPendingScans();

        $scan = $this->request('GET', '/api/library/scan')->toArray();
        self::assertSame('finished', $scan['status']);
        self::assertSame($queued['id'], $scan['id']);
        self::assertSame([5, 0, 0, 0, 0, 0], [$scan['added'], $scan['moved'], $scan['updated'], $scan['unchanged'], $scan['removed'], $scan['failed']]);
        self::assertSame([], $scan['failures']);
        self::assertArrayNotHasKey('phase', $scan, 'The progress is only shown while running.');
        self::assertIsString($scan['startedAt']);
        self::assertIsString($scan['finishedAt']);
    }

    public function testOneScanAtATime(): void
    {
        $this->request('POST', '/api/library/scan', []);
        $this->request('POST', '/api/library/scan', ['full' => true]);

        self::assertResponseStatusCodeSame(409);
        self::assertStringContainsString('already queued', (string) $this->client->getResponse()?->getContent(false));
        self::assertCount(1, $this->pending);

        $this->runPendingScans();
        $full = $this->request('POST', '/api/library/scan', ['full' => true])->toArray();
        self::assertResponseStatusCodeSame(202, 'Once the first one is done.');
        self::assertTrue($full['full']);
    }

    public function testAScanHandledTwiceRunsOnce(): void
    {
        $this->request('POST', '/api/library/scan', []);
        $message = $this->pending[0];
        $this->runPendingScans();
        $finishedAt = $this->request('GET', '/api/library/scan')->toArray()['finishedAt'];

        self::getContainer()->get(ScanLibraryHandler::class)($message);

        self::assertSame($finishedAt, $this->request('GET', '/api/library/scan')->toArray()['finishedAt']);
    }

    public function testAScanInterruptedMidwayNoLongerBlocksTheNextOne(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $interrupted = new LibraryScan(false, ScanTrigger::Api);
        $interrupted->start();
        $entityManager->persist($interrupted);
        $entityManager->flush();

        $this->request('POST', '/api/library/scan', []);

        self::assertResponseStatusCodeSame(202);
        $history = $this->request('GET', '/api/library/scans')->toArray();
        self::assertSame(['queued', 'failed'], array_column($history, 'status'), 'The latest first.');
        self::assertIsArray($history[1]);
        self::assertIsString($history[1]['error']);
        self::assertStringContainsString('interrupted', $history[1]['error']);
    }

    public function testOnlyAdministratorsScan(): void
    {
        $this->token = self::tokenFor(self::createUser('listener'));

        foreach ([['GET', '/api/library/scan'], ['POST', '/api/library/scan'], ['GET', '/api/library/scans']] as [$method, $url]) {
            $this->request($method, $url, 'POST' === $method ? [] : null);
            self::assertResponseStatusCodeSame(403, "$method $url");
        }
        $this->client->request('POST', '/api/library/scan', ['json' => []]);
        self::assertResponseStatusCodeSame(401);
        self::assertSame([], $this->pending);
    }

    /**
     * @param array<string, mixed>|null $json
     */
    private function request(string $method, string $url, ?array $json = null): ResponseInterface
    {
        $options = ['auth_bearer' => $this->token, 'headers' => ['Accept' => 'application/json']];
        if (null !== $json) {
            $options['json'] = (object) $json;
        }
        $response = $this->client->request($method, $url, $options);
        $this->collectSentMessages();

        return $response;
    }

    /**
     * The in-memory transport is emptied before each request: keep what the previous one sent.
     */
    private function collectSentMessages(): void
    {
        $transport = self::getContainer()->get('messenger.transport.library');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        foreach ($transport->getSent() as $envelope) {
            $message = $envelope->getMessage();
            self::assertInstanceOf(ScanLibrary::class, $message);
            $this->pending[] = $message;
        }
        $transport->reset();
    }

    /**
     * Does what the library worker does (messenger:consume library).
     */
    private function runPendingScans(): void
    {
        $handler = self::getContainer()->get(ScanLibraryHandler::class);
        while (null !== $message = array_shift($this->pending)) {
            $handler($message);
        }
    }
}
