<?php

namespace App\Library;

use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\String\UnicodeString;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Finds a photo of an artist on TheAudioDB (https://www.theaudiodb.com), for artists whose folder has none.
 *
 * The free API key ("123") allows 30 requests a minute, so searches are spaced out. An empty
 * THEAUDIODB_API_KEY turns the lookups off. Only an artist whose name is the same, ignoring
 * case and accents, is taken: a search returns the closest name, which may be someone else.
 */
final class TheAudioDb
{
    private const string SEARCH_URL = 'https://www.theaudiodb.com/api/v1/json/%s/search.php';

    /** 30 requests a minute on the free key: a little slower, to stay clear of the limit. */
    private const float SECONDS_BETWEEN_SEARCHES = 2.1;

    private const int MAX_IMAGE_BYTES = 10 * 1024 * 1024;

    private const array EXTENSIONS = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    private ?float $lastSearchAt = null;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly ClockInterface $clock,
        #[Autowire('%env(THEAUDIODB_API_KEY)%')]
        private readonly string $apiKey,
    ) {
    }

    public function isEnabled(): bool
    {
        return '' !== trim($this->apiKey);
    }

    /**
     * @return DownloadedImage|null The artist's thumbnail (strArtistThumb), or null when TheAudioDB has none
     *
     * @throws TheAudioDbUnavailable
     */
    public function findArtistImage(string $artistName): ?DownloadedImage
    {
        $this->waitForTheRateLimit();

        try {
            $response = $this->httpClient->request('GET', \sprintf(self::SEARCH_URL, rawurlencode(trim($this->apiKey))), [
                'query' => ['s' => $artistName],
                'timeout' => 10,
            ]);
            $this->failUnlessAvailable($response);
            $data = $response->toArray(false);
        } catch (ExceptionInterface $e) {
            throw new TheAudioDbUnavailable(\sprintf('TheAudioDB could not be reached: %s', $e->getMessage()), previous: $e);
        }

        // {"artists": [...]} or {"artists": null} when nothing matches.
        $artists = $data['artists'] ?? null;
        if (!\is_array($artists)) {
            return null;
        }

        foreach ($artists as $artist) {
            if (!\is_array($artist) || !\is_string($artist['strArtist'] ?? null) || !self::isSameName($artist['strArtist'], $artistName)) {
                continue;
            }
            $thumb = $artist['strArtistThumb'] ?? null;

            return \is_string($thumb) && '' !== $thumb ? $this->download($thumb) : null;
        }

        return null;
    }

    /**
     * @throws TheAudioDbUnavailable
     */
    private function download(string $url): ?DownloadedImage
    {
        // Only follow image links to TheAudioDB itself, whatever its answer contains.
        $host = parse_url($url, \PHP_URL_HOST);
        if ('https' !== parse_url($url, \PHP_URL_SCHEME) || !\is_string($host) || !preg_match('/(^|\.)theaudiodb\.com$/', $host)) {
            return null;
        }

        try {
            $response = $this->httpClient->request('GET', $url, ['timeout' => 30]);
            $this->failUnlessAvailable($response);
            if (200 !== $response->getStatusCode()) {
                return null;
            }

            $contentType = strtolower(trim(explode(';', $response->getHeaders(false)['content-type'][0] ?? '')[0]));
            $extension = self::EXTENSIONS[$contentType] ?? null;
            $length = (int) ($response->getHeaders(false)['content-length'][0] ?? 0);
            if (null === $extension || $length > self::MAX_IMAGE_BYTES) {
                return null;
            }

            $content = $response->getContent(false);
        } catch (ExceptionInterface $e) {
            throw new TheAudioDbUnavailable(\sprintf('TheAudioDB could not be reached: %s', $e->getMessage()), previous: $e);
        }

        return '' === $content || \strlen($content) > self::MAX_IMAGE_BYTES ? null : new DownloadedImage($content, $extension);
    }

    /**
     * @throws TheAudioDbUnavailable
     * @throws ExceptionInterface
     */
    private function failUnlessAvailable(ResponseInterface $response): void
    {
        $status = $response->getStatusCode();
        if (429 === $status) {
            throw new TheAudioDbUnavailable('TheAudioDB refused more requests for now (HTTP 429, rate limit).');
        }
        if ($status >= 500) {
            throw new TheAudioDbUnavailable(\sprintf('TheAudioDB answered HTTP %d.', $status));
        }
    }

    private function waitForTheRateLimit(): void
    {
        $now = (float) $this->clock->now()->format('U.u');
        if (null !== $this->lastSearchAt && $now - $this->lastSearchAt < self::SECONDS_BETWEEN_SEARCHES) {
            $this->clock->sleep(self::SECONDS_BETWEEN_SEARCHES - ($now - $this->lastSearchAt));
        }
        $this->lastSearchAt = (float) $this->clock->now()->format('U.u');
    }

    private static function isSameName(string $a, string $b): bool
    {
        $normalize = static fn (string $name): string => (new UnicodeString($name))->ascii()->lower()->collapseWhitespace()->toString();

        return $normalize($a) === $normalize($b);
    }
}
