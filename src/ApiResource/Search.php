<?php

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\QueryParameter;
use App\Entity\Album;
use App\Entity\Artist;
use App\Entity\Track;
use App\State\SearchProvider;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The artists, albums and tracks that match a search (see App\Search\LibrarySearch).
 */
#[ApiResource(
    operations: [
        new Get(
            uriTemplate: '/search',
            uriVariables: [],
            description: 'Searches the whole library at once. Every word of the query has to appear, in any order, ignoring case and accents. '
                .'Artists match by name, albums by title or artist, tracks by title, artist or album. '
                .'Each list is ranked by how well the item\'s own name matches, and holds at most `limit` items; the totals count every match.',
            provider: SearchProvider::class,
            parameters: [
                'q' => new QueryParameter(
                    schema: ['type' => 'string', 'minLength' => 1, 'maxLength' => self::MAX_QUERY_LENGTH],
                    description: 'The text to search for, e.g. "motorhead ace"',
                    required: true,
                    constraints: [new Assert\NotBlank(normalizer: 'trim'), new Assert\Length(max: self::MAX_QUERY_LENGTH)],
                ),
                'limit' => new QueryParameter(
                    schema: ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_LIMIT, 'default' => self::DEFAULT_LIMIT],
                    description: 'How many artists, albums and tracks to return, at most, of each',
                    constraints: [new Assert\Regex('/^\d+$/', message: 'This value should be a whole number.'), new Assert\Range(min: 1, max: self::MAX_LIMIT)],
                ),
            ],
        ),
    ],
    normalizationContext: ['groups' => ['search:read', 'artist:read', 'album:read', 'track:read']],
)]
final readonly class Search
{
    public const int MAX_QUERY_LENGTH = 100;
    public const int DEFAULT_LIMIT = 10;
    public const int MAX_LIMIT = 50;

    /**
     * @param list<Artist> $artists
     * @param list<Album>  $albums
     * @param list<Track>  $tracks
     */
    public function __construct(
        #[ApiProperty(description: 'The query, with its words separated by single spaces')]
        #[Groups(['search:read'])]
        public string $query,
        #[Groups(['search:read'])]
        public array $artists,
        #[ApiProperty(description: 'How many artists match, including the ones beyond the limit')]
        #[Groups(['search:read'])]
        public int $totalArtists,
        #[Groups(['search:read'])]
        public array $albums,
        #[ApiProperty(description: 'How many albums match, including the ones beyond the limit')]
        #[Groups(['search:read'])]
        public int $totalAlbums,
        #[Groups(['search:read'])]
        public array $tracks,
        #[ApiProperty(description: 'How many tracks match, including the ones beyond the limit')]
        #[Groups(['search:read'])]
        public int $totalTracks,
    ) {
    }
}
