<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Response;
use App\Dto\PlaylistItemMove;
use App\Repository\PlaylistItemRepository;
use App\State\MovePlaylistItemProcessor;
use App\State\PlaylistItemsProvider;
use App\State\RemovePlaylistItemProcessor;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * One appearance of a track in a playlist: a track added twice is two items, each with its own id. Deleted with its
 * playlist and with its track (e.g. when a scan removes the track).
 *
 * The order is that of $sortKey, unique in the playlist and not exposed: keys leave gaps, so moving an item changes
 * its key alone (App\Playlists\PlaylistEditor), and removing one (by the API or a scan) changes no other. The exposed
 * position is the index in that order, from 0, computed when the item is read.
 */
#[ORM\Entity(repositoryClass: PlaylistItemRepository::class)]
#[ORM\UniqueConstraint(name: 'playlist_item_sort_key', columns: ['playlist_id', 'sort_key'])]
#[ApiResource(
    operations: [
        new GetCollection(
            uriTemplate: '/playlists/{id}/items',
            uriVariables: ['id' => new Link(toProperty: 'playlist', fromClass: Playlist::class)],
            openapi: new Operation(
                responses: [
                    '200' => new Response('A page of the items, in the order of the playlist'),
                    '404' => new Response('The playlist does not exist, or is someone else\'s'),
                ],
                summary: 'The items of a playlist, in its order',
                description: 'Each item has its own id (a track added twice is two items), its position (index from 0 in the playlist), the date it was added, and its whole track as GET /api/tracks returns it (signed streamUrl and opusStreamUrl, favourite, album with its coverUrl). Paginated, 30 per page.',
            ),
            provider: PlaylistItemsProvider::class,
        ),
        new Patch(
            openapi: new Operation(
                summary: 'Moves an item of a playlist',
                description: 'Send `{"position": n}` as application/merge-patch+json: the index from 0, in the current order, where the item goes; the items in between shift by one. '
                    .'A position beyond the last item moves it to the end; a negative one is a 422. Answers with the item at its new position.',
            ),
            input: PlaylistItemMove::class,
            processor: MovePlaylistItemProcessor::class,
        ),
        new Delete(
            openapi: new Operation(summary: 'Removes an item from a playlist', description: 'That appearance of the track only; the items after it move up by one.'),
            processor: RemovePlaylistItemProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => ['playlist_item:read', 'track:read']],
)]
class PlaylistItem
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['playlist_item:read'])]
    private Uuid $id;

    #[ORM\Column]
    #[Groups(['playlist_item:read'])]
    private \DateTimeImmutable $addedAt;

    #[ApiProperty(description: 'Index of the item in the playlist, from 0')]
    #[Groups(['playlist_item:read'])]
    private int $position = 0;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Playlist $playlist,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        #[Groups(['playlist_item:read'])]
        private Track $track,
        #[ORM\Column(type: 'bigint')]
        private int $sortKey,
    ) {
        $this->id = Uuid::v7();
        $this->addedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getPlaylist(): Playlist
    {
        return $this->playlist;
    }

    public function getTrack(): Track
    {
        return $this->track;
    }

    public function getSortKey(): int
    {
        return $this->sortKey;
    }

    public function getAddedAt(): \DateTimeImmutable
    {
        return $this->addedAt;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    /** Not stored: the index of the item in the playlist, set by whoever read it. */
    public function setPosition(int $position): void
    {
        $this->position = $position;
    }
}
