<?php

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\QueryParameter;
use ApiPlatform\OpenApi\Model\Operation;
use App\Entity\Track;
use App\State\PlayedTracksProvider;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A track in the listening history of the user making the request (docs/decisions/0016), with how many times they
 * played it and when last.
 */
#[ApiResource(
    operations: [
        new GetCollection(
            uriTemplate: '/me/recent-tracks',
            openapi: new Operation(
                summary: 'The tracks the user making the request played, the most recently played first',
                description: 'Each track once, with how many times and when it was last played. Paginated, 30 per page.',
            ),
            name: PlayedTracksProvider::RECENT,
            provider: PlayedTracksProvider::class,
        ),
        new GetCollection(
            uriTemplate: '/me/top-tracks',
            openapi: new Operation(
                summary: 'The tracks the user making the request played most',
                description: 'Each track once, the most played first (then the most recently played), counting the plays of the period only. Paginated, 30 per page.',
            ),
            name: PlayedTracksProvider::TOP,
            provider: PlayedTracksProvider::class,
            parameters: [
                'period' => new QueryParameter(
                    schema: ['type' => 'string', 'enum' => PlayedTracksProvider::PERIODS, 'default' => 'all'],
                    description: 'Count the plays of the last week, month (30 days), year, or all of them',
                    constraints: [new Assert\Choice(choices: PlayedTracksProvider::PERIODS, message: 'The period is week, month, year or all.')],
                ),
            ],
        ),
    ],
    normalizationContext: ['groups' => ['played_track:read', 'track:read']],
)]
final readonly class PlayedTrack
{
    public function __construct(
        #[ApiProperty(description: 'The track, as GET /api/tracks returns it')]
        #[Groups(['played_track:read'])]
        public Track $track,
        #[ApiProperty(description: 'How many times the user played it (in the period, for top tracks)')]
        #[Groups(['played_track:read'])]
        public int $playCount,
        #[ApiProperty(description: 'When the user last played it')]
        #[Groups(['played_track:read'])]
        public \DateTimeImmutable $lastPlayedAt,
    ) {
    }
}
