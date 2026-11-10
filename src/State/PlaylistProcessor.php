<?php

namespace App\State;

use ApiPlatform\Doctrine\Common\State\PersistProcessor;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\PlaylistInput;
use App\Entity\Playlist;
use App\Entity\User;
use App\Playlists\PlaylistNotFound;
use App\Playlists\PlaylistOrder;
use App\Playlists\PlaylistSummaries;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * POST /api/playlists (a new, empty playlist of the user making the request, first among their playlists) and PATCH
 * /api/playlists/{id} (renamed, which changes updatedAt, and/or moved, which doesn't).
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
        private PlaylistOrder $order,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Playlist
    {
        $user = $this->security->getUser();
        \assert($user instanceof User);

        if ($data instanceof PlaylistInput) {
            $playlist = $this->order->create($user, $data->name);
        } else {
            // The new name, if any (Playlist::setName() changed updatedAt), then the new position, if any.
            $requested = $data->takeRequestedPosition();
            $playlist = $this->persistProcessor->process($data, $operation, $uriVariables, $context);
            try {
                if (null !== $requested) {
                    $this->order->move($user, $playlist, $requested);
                } else {
                    $playlist->placeAt($this->order->positionOf($playlist));
                }
            } catch (PlaylistNotFound $e) {
                throw new NotFoundHttpException('No playlist with this id.', $e);
            }
        }

        $this->summaries->fill([$playlist]);

        return $playlist;
    }
}
