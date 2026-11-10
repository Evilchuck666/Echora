<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use ApiPlatform\Validator\Exception\ValidationException;
use App\Dto\PlaylistTracksInput;
use App\Entity\Playlist;
use App\Entity\User;
use App\Playlists\PlaylistEditor;
use App\Playlists\PlaylistFull;
use App\Playlists\PlaylistNotFound;
use App\Playlists\PlaylistOrder;
use App\Playlists\PlaylistSummaries;
use App\Playlists\UnknownTracks;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

/**
 * POST /api/playlists/{id}/items: adds the tracks at the end (App\Playlists\PlaylistEditor) and answers with the
 * playlist, its new number of items included.
 *
 * @implements ProcessorInterface<PlaylistTracksInput, Playlist>
 */
final readonly class AddPlaylistTracksProcessor implements ProcessorInterface
{
    public function __construct(
        private PlaylistEditor $editor,
        private PlaylistSummaries $summaries,
        private Security $security,
        private PlaylistOrder $order,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Playlist
    {
        $user = $this->security->getUser();
        \assert($user instanceof User);

        try {
            $playlist = $this->editor->add($user, UriVariable::string($uriVariables, 'id'), $data->trackIds());
        } catch (PlaylistNotFound $e) {
            throw new NotFoundHttpException('No playlist with this id.', $e);
        } catch (UnknownTracks|PlaylistFull $e) {
            throw new ValidationException(new ConstraintViolationList([new ConstraintViolation($e->getMessage(), null, [], $data, 'tracks', $data->tracks)]), previous: $e);
        }

        $this->summaries->fill([$playlist]);
        $playlist->placeAt($this->order->positionOf($playlist));

        return $playlist;
    }
}
