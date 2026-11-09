<?php

namespace App\Tests\Library;

use App\Library\TheAudioDb;
use App\Library\TheAudioDbUnavailable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class TheAudioDbTest extends TestCase
{
    private const string THUMB = 'https://r2.theaudiodb.com/images/media/artist/thumb/motorhead.jpg';

    /** @var list<array{string, string}> Method and URL of every request */
    private array $requests = [];

    public function testItDownloadsTheThumbnailOfTheArtistWithTheSameName(): void
    {
        $theAudioDb = $this->theAudioDb([
            $this->search([['strArtist' => 'Motörhead', 'strArtistThumb' => self::THUMB]]),
            new MockResponse('jpeg bytes', ['response_headers' => ['content-type' => 'image/jpeg']]),
        ]);

        $image = $theAudioDb->findArtistImage('motorhead');

        self::assertNotNull($image);
        self::assertSame(['jpeg bytes', 'jpg'], [$image->content, $image->extension]);
        self::assertSame('https://www.theaudiodb.com/api/v1/json/123/search.php?s=motorhead', $this->requests[0][1]);
        self::assertSame(self::THUMB, $this->requests[1][1]);
    }

    public function testAnArtistWithAnotherNameIsNotTaken(): void
    {
        $theAudioDb = $this->theAudioDb([$this->search([['strArtist' => 'Riot V', 'strArtistThumb' => self::THUMB]])]);

        self::assertNull($theAudioDb->findArtistImage('Riot'));
        self::assertCount(1, $this->requests, 'Nothing was downloaded.');
    }

    public function testNoMatchGivesNothing(): void
    {
        self::assertNull($this->theAudioDb([new MockResponse('{"artists":null}')])->findArtistImage('Nobody'));
    }

    public function testAnArtistWithoutThumbnailGivesNothing(): void
    {
        self::assertNull($this->theAudioDb([$this->search([['strArtist' => 'Accept', 'strArtistThumb' => null]])])->findArtistImage('Accept'));
    }

    public function testImagesAreOnlyDownloadedFromTheAudioDb(): void
    {
        $theAudioDb = $this->theAudioDb([$this->search([['strArtist' => 'Accept', 'strArtistThumb' => 'https://example.com/accept.jpg']])]);

        self::assertNull($theAudioDb->findArtistImage('Accept'));
        self::assertCount(1, $this->requests);
    }

    public function testAResponseThatIsNotAnImageIsIgnored(): void
    {
        $theAudioDb = $this->theAudioDb([
            $this->search([['strArtist' => 'Accept', 'strArtistThumb' => self::THUMB]]),
            new MockResponse('<html>', ['response_headers' => ['content-type' => 'text/html']]),
        ]);

        self::assertNull($theAudioDb->findArtistImage('Accept'));
    }

    public function testTheRateLimitStopsTheLookups(): void
    {
        $this->expectException(TheAudioDbUnavailable::class);
        $this->expectExceptionMessage('HTTP 429');

        $this->theAudioDb([new MockResponse('', ['http_code' => 429])])->findArtistImage('Accept');
    }

    public function testAnUnreachableServerStopsTheLookups(): void
    {
        $this->expectException(TheAudioDbUnavailable::class);

        $this->theAudioDb([new MockResponse('', ['error' => 'Could not resolve host'])])->findArtistImage('Accept');
    }

    public function testSearchesAreSpacedOutForTheRateLimit(): void
    {
        $clock = new MockClock('2026-01-01 12:00:00');
        $theAudioDb = $this->theAudioDb([new MockResponse('{"artists":null}'), new MockResponse('{"artists":null}')], $clock);

        $theAudioDb->findArtistImage('First');
        $theAudioDb->findArtistImage('Second');

        // 30 requests a minute at most.
        self::assertGreaterThanOrEqual(2.0, (float) $clock->now()->format('U.u') - (float) (new \DateTimeImmutable('2026-01-01 12:00:00'))->format('U.u'));
    }

    public function testItDownloadsTheCoverOfTheAlbumWithTheSameArtistAndTitle(): void
    {
        $cover = 'https://r2.theaudiodb.com/images/media/album/thumb/ace.jpg';
        $theAudioDb = $this->theAudioDb([
            new MockResponse(json_encode(['album' => [
                ['strArtist' => 'Motörhead', 'strAlbum' => 'Ace of Spades (Deluxe)', 'strAlbumThumb' => 'https://r2.theaudiodb.com/images/media/album/thumb/deluxe.jpg'],
                ['strArtist' => 'Motörhead', 'strAlbum' => 'Ace of Spades', 'strAlbumThumb' => $cover],
            ]], \JSON_THROW_ON_ERROR)),
            new MockResponse('png bytes', ['response_headers' => ['content-type' => 'image/png']]),
        ]);

        $image = $theAudioDb->findAlbumCover('Motorhead', 'ace of spades');

        self::assertNotNull($image);
        self::assertSame(['png bytes', 'png'], [$image->content, $image->extension]);
        self::assertSame('https://www.theaudiodb.com/api/v1/json/123/searchalbum.php?s=Motorhead&a=ace%20of%20spades', $this->requests[0][1]);
        self::assertSame($cover, $this->requests[1][1]);
    }

    public function testAnAlbumOfAnotherArtistIsNotTaken(): void
    {
        $theAudioDb = $this->theAudioDb([new MockResponse(json_encode(['album' => [
            ['strArtist' => 'Saxon', 'strAlbum' => 'Unleash the Beast', 'strAlbumThumb' => 'https://r2.theaudiodb.com/images/media/album/thumb/saxon.jpg'],
        ]], \JSON_THROW_ON_ERROR))]);

        self::assertNull($theAudioDb->findAlbumCover('Accept', 'Unleash the Beast'));
        self::assertCount(1, $this->requests, 'Nothing was downloaded.');
    }

    public function testNoAlbumMatchGivesNothing(): void
    {
        self::assertNull($this->theAudioDb([new MockResponse('{"album":null}')])->findAlbumCover('Nobody', 'Nothing'));
    }

    public function testAnAlbumWithoutCoverGivesNothing(): void
    {
        $theAudioDb = $this->theAudioDb([new MockResponse(json_encode(['album' => [
            ['strArtist' => 'Accept', 'strAlbum' => 'Restless and Wild', 'strAlbumThumb' => ''],
        ]], \JSON_THROW_ON_ERROR))]);

        self::assertNull($theAudioDb->findAlbumCover('Accept', 'Restless and Wild'));
    }

    public function testAnEmptyKeyTurnsItOff(): void
    {
        self::assertFalse((new TheAudioDb(new MockHttpClient(), new MockClock(), ''))->isEnabled());
        self::assertTrue((new TheAudioDb(new MockHttpClient(), new MockClock(), '123'))->isEnabled());
    }

    /**
     * @param list<array<string, mixed>> $artists
     */
    private function search(array $artists): MockResponse
    {
        return new MockResponse(json_encode(['artists' => $artists], \JSON_THROW_ON_ERROR));
    }

    /**
     * @param list<MockResponse> $responses
     */
    private function theAudioDb(array $responses, MockClock $clock = new MockClock()): TheAudioDb
    {
        $client = new MockHttpClient(function (string $method, string $url) use (&$responses): MockResponse {
            $this->requests[] = [$method, $url];

            return array_shift($responses) ?? throw new \LogicException('Unexpected request to '.$url);
        });

        return new TheAudioDb($client, $clock, '123');
    }
}
