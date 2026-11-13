<?php

namespace App\Tests\Api;

use ApiPlatform\Test\ApiTestCase;
use ApiPlatform\Test\Client;
use App\Library\LibraryScanner;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Tracks expose their ReplayGain tags, so that players even out the loudness (docs/decisions/0017).
 */
final class ReplayGainTest extends ApiTestCase
{
    use CreatesUsers;

    protected static ?bool $alwaysBootKernel = true;

    private Client $client;
    private string $library;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->client->setDefaultOptions(['auth_bearer' => self::tokenFor(self::createUser()), 'headers' => ['Accept' => 'application/json']]);
        $this->library = sys_get_temp_dir().'/echora-library-'.bin2hex(random_bytes(4));
        (new Filesystem())->mirror(__DIR__.'/../Fixtures/music', $this->library);
        (new Filesystem())->mirror(__DIR__.'/../Fixtures/replaygain', $this->library.'/Gain Artist/Gain Album');
        self::getContainer()->get(LibraryScanner::class)->scan($this->library, download: false);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->library);
        parent::tearDown();
    }

    public function testTracksComeWithTheReplayGainValuesTheirFileHas(): void
    {
        self::assertSame(['trackGain' => -6.48, 'trackPeak' => 0.988159, 'albumGain' => -5.92, 'albumPeak' => 0.999969], $this->track('Loud Song')['replayGain']);
        self::assertSame(['trackGain' => 3.21, 'trackPeak' => 0.501187], $this->track('Quiet Song')['replayGain'], 'Only the values the file has.');
        self::assertArrayNotHasKey('replayGain', $this->track('Opening'), 'Left out without any.');
    }

    public function testTracksScannedBeforeOnlyGetThemWithAFullScan(): void
    {
        // As if scanned by an older Echora, which didn't read the tags.
        self::getContainer()->get('doctrine.dbal.default_connection')->executeStatement('UPDATE track SET replay_gain_track_gain = NULL, replay_gain_track_peak = NULL, replay_gain_album_gain = NULL, replay_gain_album_peak = NULL');
        $scanner = self::getContainer()->get(LibraryScanner::class);

        $scanner->scan($this->library, download: false);
        self::assertArrayNotHasKey('replayGain', $this->track('Loud Song'), 'An unchanged file is not read again.');

        $scanner->scan($this->library, full: true, download: false);
        $replayGain = $this->track('Loud Song')['replayGain'] ?? null;
        self::assertIsArray($replayGain);
        self::assertSame(-6.48, $replayGain['trackGain']);
    }

    /**
     * @return array<mixed>
     */
    private function track(string $title): array
    {
        $tracks = $this->client->request('GET', '/api/tracks?title='.rawurlencode($title))->toArray();
        self::assertCount(1, $tracks);
        self::assertIsArray($tracks[0]);

        return $tracks[0];
    }
}
