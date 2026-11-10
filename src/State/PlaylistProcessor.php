<?php

namespace App\State;

use ApiPlatform\Doctrine\Common\State\PersistProcessor;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\PlaylistInput;
use App\Entity\Playlist;
use App\Entity\User;
use App\Playlists\PlaylistSummaries;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * POST /api/playlists (a new, empty playlist of the user making the request) and PATCH /api/playlists/{id} (renamed:
 * its updatedAt changes).
 *
 * @implements ProcessorInterface<PlaylistInput|Playlist, Playlist>
 */
final readonly class PlaylistProcessor implements ProcessorInterface
{
    /**
     * @param ProcessorInterface<Playlist, Playlist> $persistProcessor
     */
    public function __construct(
        #[Autowire(service: PersistProcessor::class)]
        private ProcessorInterface $persistProcessor,
        private Security $security,
        private PlaylistSummaries $summaries,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Playlist
    {
        if ($data instanceof PlaylistInput) {
            $user = $this->security->getUser();
            \assert($user instanceof User);
            $playlist = new Playlist($user, $data->name);
        } else {
            $playlist = $data;
            $playlist->touch();
        }

        $playlist = $this->persistProcessor->process($playlist, $operation, $uriVariables, $context);
        $this->summaries->fill([$playlist]);

        return $playlist;
    }
}
