<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\User;
use App\Security\AccountConflict;
use App\Security\UserAccounts;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * DELETE /api/users/{id}: deletes the account, its playlists, favourites, picture and sessions. An administrator never
 * deletes their own account.
 *
 * @implements ProcessorInterface<User, null>
 */
final readonly class UserRemoveProcessor implements ProcessorInterface
{
    public function __construct(
        private UserAccounts $accounts,
        private Security $security,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $administrator = $this->security->getUser();
        \assert($administrator instanceof User);

        try {
            $this->accounts->delete($data, $administrator);
        } catch (AccountConflict $e) {
            throw new ConflictHttpException($e->getMessage(), $e);
        }

        return null;
    }
}
