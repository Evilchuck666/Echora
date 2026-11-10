<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\PlaylistItem;
use App\Entity\User;
use App\Playlists\PlaylistEditor;
use App\Playlists\PlaylistNotFound;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * DELETE /api/playlist_items/{id}: removes the item (App\Playlists\PlaylistEditor).
 *
 * @implements ProcessorInterface<PlaylistItem, null>
 */
final readonly class RemovePlaylistItemProcessor implements ProcessorInterface
{
    public function __construct(
        private PlaylistEditor $editor,
        private Security $security,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $user = $this->security->getUser();
        \assert($user instanceof User);

        try {
            $this->editor->remove($user, UriVariable::string($uriVariables, 'id'));
        } catch (PlaylistNotFound $e) {
            throw new NotFoundHttpException('No playlist item with this id.', $e);
        }

        return null;
    }
}
