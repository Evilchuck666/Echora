<?php

namespace App\Tests\Api;

use ApiPlatform\Test\ApiTestCase;
use ApiPlatform\Test\Client;
use App\Entity\Track;
use App\Library\LibraryFiles;
use App\Library\LibraryScanner;
use App\Library\OpusTranscoder;
use App\Message\TranscodeTracks;
use App\MessageHandler\TranscodeTracksHandler;
use App\Repository\TrackRepository;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * POST /api/transcodes: clients have the tracks they are about to play converted to Opus ahead of time, in the
 * background (the transport is in memory in tests: they call the handler themselves).
 */
final class TranscodeRequestTest extends ApiTestCase
{
    use CreatesUsers;

    protected static ?bool $alwaysBootKernel = true;

    private Client $client;
    private string $directory;
    private string $token;

    /** @var list<TranscodeTracks> Messages sent by the requests, in order, for the workers */
    private array $pending = [];

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->token = self::tokenFor(self::createUser());
        $this->directory = self::getContainer()->getParameter('app.media_dir').'/transcodes';
        (new Filesystem())->remove($this->directory);
        self::getContainer()->get(LibraryScanner::class)->scan(download: false);
    }

    public function testTheRequestIsAnsweredAtOnceAndTheTracksAreConvertedInTheBackgroundInTheirOrder(): void
    {
        $second = $this->id('Second Song');
        $opening = $this->id('Opening');
        $guest = $this->id('Guest Track');

        $response = $this->transcode([$second, '/api/tracks/'.$guest, $opening]);

        self::assertResponseStatusCodeSame(202);
        self::assertSame(['queued' => [$second, $guest, $opening], 'ready' => [], 'converting' => []], $response);
        self::assertSame([], glob($this->directory.'/*.opus') ?: [], 'Nothing was converted before the response.');

        self::assertSame([[$second, $guest, $opening]], $this->handlePendingMessages(), 'Converted one after the other, next one first.');
        foreach ([$second, $guest, $opening] as $id) {
            self::assertCount(1, glob($this->directory."/track-$id-*.opus") ?: []);
        }
    }

    public function testMp3AndUnknownTracksAreLeftOutAndDuplicatesCountOnce(): void
    {
        $opening = $this->id('Opening');

        $response = $this->transcode([$this->id('MP3 Song'), '01a1c051-0000-7000-8000-000000000000', $opening, '/api/tracks/'.strtoupper($opening)]);

        self::assertSame(['queued' => [$opening], 'ready' => [], 'converting' => []], $response);
        self::assertSame([[$opening]], $this->handlePendingMessages());
    }

    public function testTracksAlreadyConvertedAreLeftOutAndKeptByPrune(): void
    {
        $opening = $this->id('Opening');
        $second = $this->id('Second Song');
        $converted = $this->transcoder()->transcode($this->source('Opening'), $opening);
        // Last played 40 days ago: the weekly prune (30 days) would delete it...
        touch($converted, (int) filemtime($converted), time() - 40 * 86400);

        $response = $this->transcode([$opening, $second]);

        self::assertSame(['queued' => [$second], 'ready' => [$opening], 'converting' => []], $response);
        // ...but it is about to be played.
        $this->prune();
        self::assertFileExists($converted);
    }

    public function testTracksBeingConvertedAreLeftOut(): void
    {
        $opening = $this->id('Opening');
        $second = $this->id('Second Song');
        $lock = self::getContainer()->get(LockFactory::class)->createLock('opus-transcode-'.$opening);
        self::assertTrue($lock->acquire());

        $response = $this->transcode([$opening, $second]);

        self::assertSame(['queued' => [$second], 'ready' => [], 'converting' => [$opening]], $response);
        $lock->release();
    }

    public function testTheWorkerSkipsTracksThatAPlayerStartedConvertingInTheMeantime(): void
    {
        $opening = $this->id('Opening');
        $second = $this->id('Second Song');
        $this->transcode([$opening, $second]);
        $lock = self::getContainer()->get(LockFactory::class)->createLock('opus-transcode-'.$opening);
        self::assertTrue($lock->acquire());

        self::assertSame([[$second]], $this->handlePendingMessages(), 'Never waits for another conversion.');
        $lock->release();
    }

    public function testANewListReplacesTheTracksOfThePreviousOneThatAreNotConvertedYet(): void
    {
        $opening = $this->id('Opening');
        $second = $this->id('Second Song');

        $this->transcode([$opening]);
        $this->transcode([$second]);

        self::assertSame([[], [$second]], $this->handlePendingMessages(), 'The older list is dropped.');
        self::assertSame([], glob($this->directory."/track-$opening-*.opus") ?: []);
    }

    public function testAnEmptyListCancelsThePreviousOne(): void
    {
        $this->transcode([$this->id('Opening')]);

        $response = $this->transcode([]);

        self::assertResponseStatusCodeSame(202);
        self::assertSame(['queued' => [], 'ready' => [], 'converting' => []], $response);
        self::assertSame([[]], $this->handlePendingMessages(), 'Nothing new to do, and the older list is dropped.');
    }

    public function testTracksConvertedAheadOfTimeAreKeptByPruneUntilTheyArePlayed(): void
    {
        $opening = $this->id('Opening');
        $this->transcode([$opening]);
        $this->handlePendingMessages();

        $this->prune();

        self::assertCount(1, glob($this->directory."/track-$opening-*.opus") ?: []);
    }

    public function testAtMostTwentyTracksPerRequest(): void
    {
        $ids = array_map(static fn (int $i): string => \sprintf('01a1c051-0000-7000-8000-%012d', $i), range(1, 21));

        $this->transcode($ids);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('At most 20 tracks', (string) $this->client->getResponse()?->getContent(false));

        $this->transcode(\array_slice($ids, 0, 20));
        self::assertResponseStatusCodeSame(202);
        self::assertSame([], $this->pending, 'Unknown tracks: nothing to convert.');
    }

    public function testSomethingThatIsNeitherATrackIdNorItsIriIsRefused(): void
    {
        $this->transcode(['/api/albums/01a1c051-0000-7000-8000-000000000000']);
        self::assertResponseStatusCodeSame(422);

        $this->client->request('POST', '/api/transcodes', ['auth_bearer' => $this->token, 'json' => ['songs' => []]]);
        self::assertResponseStatusCodeSame(422);

        $this->client->request('POST', '/api/transcodes', ['auth_bearer' => $this->token, 'headers' => ['Content-Type' => 'application/json'], 'body' => '{"tracks":']);
        self::assertResponseStatusCodeSame(400);
    }

    public function testOnlySignedInUsersCanAsk(): void
    {
        $this->client->request('POST', '/api/transcodes', ['json' => ['tracks' => [$this->id('Opening')]]]);
        $this->collectSentMessages();

        self::assertResponseStatusCodeSame(401);
        self::assertSame([], $this->pending);
    }

    /**
     * @param list<string> $tracks
     *
     * @return array<mixed>
     */
    private function transcode(array $tracks): array
    {
        $response = $this->client->request('POST', '/api/transcodes', ['auth_bearer' => $this->token, 'json' => ['tracks' => $tracks]]);
        $this->collectSentMessages();

        return 202 === $response->getStatusCode() ? $response->toArray() : [];
    }

    /**
     * The in-memory transport is emptied before each request: keep what the previous one sent.
     */
    private function collectSentMessages(): void
    {
        $transport = self::getContainer()->get('messenger.transport.transcodes');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        foreach ($transport->getSent() as $envelope) {
            $message = $envelope->getMessage();
            self::assertInstanceOf(TranscodeTracks::class, $message);
            $this->pending[] = $message;
        }
        $transport->reset();
    }

    /**
     * Does what the background workers do (messenger:consume transcodes), in the order the messages were sent.
     *
     * @return list<list<string>> The tracks converted for each message
     */
    private function handlePendingMessages(): array
    {
        $handler = self::getContainer()->get(TranscodeTracksHandler::class);
        $converted = array_map(static fn (TranscodeTracks $message): array => $handler($message), $this->pending);
        $this->pending = [];

        return $converted;
    }

    private function prune(): void
    {
        $tester = new CommandTester((new Application(self::$kernel ?? self::bootKernel()))->find('app:transcodes:prune'));
        $tester->execute([]);
        $tester->assertCommandIsSuccessful();
    }

    private function transcoder(): OpusTranscoder
    {
        return self::getContainer()->get(OpusTranscoder::class);
    }

    private function track(string $title): Track
    {
        $track = self::getContainer()->get(TrackRepository::class)->findOneBy(['title' => $title]);
        self::assertInstanceOf(Track::class, $track);

        return $track;
    }

    private function id(string $title): string
    {
        return $this->track($title)->getId()->toRfc4122();
    }

    private function source(string $title): string
    {
        $path = self::getContainer()->get(LibraryFiles::class)->path($this->track($title)->getPath());
        self::assertNotNull($path);

        return $path;
    }
}
