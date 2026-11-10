<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\PlaylistItemMove;
use App\Entity\PlaylistItem;
use App\Entity\User;
use App\Playlists\PlaylistEditor;
use App\Playlists\PlaylistNotFound;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * PATCH /api/playlist_items/{id}: moves the item (App\Playlists\PlaylistEditor) and answers with it at its new position.
 *
 * @implements ProcessorInterface<PlaylistItemMove, PlaylistItem>
 */
final readonly class MovePlaylistItemProcessor implements ProcessorInterface
{
    public function __construct(
        private PlaylistEditor $editor,
        private Security $security,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): PlaylistItem
    {
        $user = $this->security->getUser();
        \assert($user instanceof User);

        try {
            return $this->editor->move($user, UriVariable::string($uriVariables, 'id'), $data->position);
        } catch (PlaylistNotFound $e) {
            throw new NotFoundHttpException('No playlist item with this id.', $e);
        }
    }
}
