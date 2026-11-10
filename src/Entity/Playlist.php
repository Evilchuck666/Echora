<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Response;
use App\Dto\PlaylistInput;
use App\Dto\PlaylistTracksInput;
use App\Repository\PlaylistRepository;
use App\State\AddPlaylistTracksProcessor;
use App\State\PlaylistProcessor;
use App\State\PlaylistProvider;
use App\Validator\PlaylistName;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A playlist of a user: its tracks are PlaylistItems, in their order. Only its owner sees or changes it; for anyone
 * else it doesn't exist (404, see App\Doctrine\OwnedByCurrentUserExtension). Deleted with its owner.
 */
#[ORM\Entity(repositoryClass: PlaylistRepository::class)]
#[ORM\UniqueConstraint(name: 'playlist_owner_sort_key', columns: ['owner_id', 'sort_key'])]
#[ApiResource(
    operations: [
        new GetCollection(
            openapi: new Operation(
                summary: 'The playlists of the user making the request',
                description: 'Only the user\'s own playlists, in the order the user gave them (the same on every device): each has its `position`, an index from 0. A new playlist comes first; PATCH /api/playlists/{id} with `{"position": n}` moves one. Paginated, 30 per page.',
            ),
            provider: PlaylistProvider::class,
        ),
        new Post(
            openapi: new Operation(summary: 'Creates an empty playlist', description: 'The name has 1 to 100 characters, without spaces at the start or the end; it need not be unique. The new playlist comes first among the user\'s playlists (position 0); the others move down by one.'),
            input: PlaylistInput::class,
            processor: PlaylistProcessor::class,
        ),
        new Get(
            openapi: new Operation(summary: 'A playlist of the user making the request', description: 'Someone else\'s playlist is a 404, as if it didn\'t exist.'),
            provider: PlaylistProvider::class,
        ),
        new Patch(
            openapi: new Operation(
                summary: 'Renames or moves a playlist',
                description: 'Send `{"name": "..."}`, `{"position": n}` or both, as application/merge-patch+json. '
                    .'`position` is the index from 0, in the user\'s order as it is when the request arrives, where the playlist goes; the playlists in between shift by one. '
                    .'Beyond the last playlist means the end; a negative position is a 422. Send moves one at a time: two at once may be applied in either order, the second landing one place off. '
                    .'A new name changes updatedAt; moving does not.',
            ),
            denormalizationContext: ['groups' => ['playlist:write']],
            processor: PlaylistProcessor::class,
        ),
        new Delete(openapi: new Operation(summary: 'Deletes a playlist and its items', description: 'The tracks themselves stay in the library.')),
        new Post(
            uriTemplate: '/playlists/{id}/items',
            uriVariables: ['id' => new Link(fromClass: Playlist::class)],
            status: 200,
            openapi: new Operation(
                responses: [
                    '200' => new Response('The tracks were added: the playlist, with its new trackCount, durationMs, covers and updatedAt'),
                    '404' => new Response('The playlist does not exist, or is someone else\'s'),
                    '422' => new Response('A track that does not exist, something that is not a track id or IRI, no track, or more than '.Playlist::MAX_ITEMS.' items in the playlist afterwards: nothing was added'),
                ],
                summary: 'Adds tracks at the end of a playlist',
                description: 'Adds every track given, in this order, at the end of the playlist, all or none: a whole album in one request. '
                    .'The same track may be added several times, also in one request: each appearance is an item of its own.',
            ),
            name: 'playlist_add_tracks',
            input: PlaylistTracksInput::class,
            read: false,
            processor: AddPlaylistTracksProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => ['playlist:read']],
    order: ['sortKey' => 'ASC'],
)]
class Playlist
{
    public const int MAX_ITEMS = 10000;

    /** How many album covers a playlist shows at most (a 2×2 mosaic). */
    public const int MAX_COVERS = 4;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['playlist:read'])]
    private Uuid $id;

    #[ORM\Column]
    #[Groups(['playlist:read'])]
    private \DateTimeImmutable $createdAt;

    /** Changed by a new name or any change to its items, including a scan removing one of its tracks; not by moving it. */
    #[ORM\Column]
    #[Groups(['playlist:read'])]
    private \DateTimeImmutable $updatedAt;

    /**
     * Order among the owner's playlists (App\Ordering\SortKeys, scoped by owner): unique and positive, with gaps, never
     * exposed. Set by App\Playlists\PlaylistOrder.
     */
    #[ORM\Column(type: 'bigint')]
    private int $sortKey;

    /** Index among the owner's playlists, from 0: computed when read; when written (PATCH), where to move it. */
    #[ApiProperty(description: 'Index of the playlist among the user\'s playlists, from 0. Send it in a PATCH to move the playlist there')]
    #[Assert\PositiveOrZero(message: 'The position is an index from 0.')]
    #[Groups(['playlist:read', 'playlist:write'])]
    private int $position = 0;

    /** The position sent in a PATCH, until App\State\PlaylistProcessor moves the playlist there. */
    private ?int $requestedPosition = null;

    #[ApiProperty(description: 'Number of items (a track added twice counts twice)')]
    #[Groups(['playlist:read'])]
    private int $trackCount = 0;

    #[ApiProperty(description: 'Total length in milliseconds of the items whose length is known')]
    #[Groups(['playlist:read'])]
    private int $durationMs = 0;

    /** @var list<string> */
    #[ApiProperty(
        description: 'Signed URLs of the covers of up to 4 different albums, in the order of the playlist (albums without a cover are skipped), for a mosaic. Valid like the coverUrl of an album',
        schema: ['type' => 'array', 'items' => ['type' => 'string', 'format' => 'uri'], 'maxItems' => self::MAX_COVERS],
    )]
    #[Groups(['playlist:read'])]
    private array $covers = [];

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $owner,
        #[ORM\Column(length: PlaylistName::MAX_LENGTH)]
        #[PlaylistName]
        #[Groups(['playlist:read', 'playlist:write'])]
        private string $name,
        int $sortKey,
    ) {
        $this->sortKey = $sortKey;
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getOwner(): User
    {
        return $this->owner;
    }

    public function isOwnedBy(User $user): bool
    {
        return $this->owner->getId()->equals($user->getId());
    }

    public function getName(): string
    {
        return $this->name;
    }

    /** A new name is a change (updatedAt); the same name again is not. */
    public function setName(string $name): void
    {
        if ($name !== $this->name) {
            $this->name = $name;
            $this->touch();
        }
    }

    public function getSortKey(): int
    {
        return $this->sortKey;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    /** From a PATCH: where to move the playlist (App\State\PlaylistProcessor). */
    public function setPosition(int $position): void
    {
        $this->position = $position;
        $this->requestedPosition = $position;
    }

    /** The position sent in a PATCH, if any, forgotten once read. */
    public function takeRequestedPosition(): ?int
    {
        [$position, $this->requestedPosition] = [$this->requestedPosition, null];

        return $position;
    }

    /** Not stored: the index of the playlist among its owner's, set by whoever read it. */
    public function placeAt(int $position): void
    {
        $this->position = $position;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /** After any change to the playlist or its items. */
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getTrackCount(): int
    {
        return $this->trackCount;
    }

    public function getDurationMs(): int
    {
        return $this->durationMs;
    }

    /**
     * @return list<string>
     */
    public function getCovers(): array
    {
        return $this->covers;
    }

    /**
     * Not stored: computed for a page of playlists at once (App\Playlists\PlaylistSummaries).
     *
     * @param list<string> $covers
     */
    public function setSummary(int $trackCount, int $durationMs, array $covers): void
    {
        $this->trackCount = $trackCount;
        $this->durationMs = $durationMs;
        $this->covers = $covers;
    }
}
