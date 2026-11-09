<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Provides the authenticated account for GET /api/me.
 *
 * @implements ProviderInterface<User>
 */
final readonly class CurrentUserProvider implements ProviderInterface
{
    public function __construct(private Security $security)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ?User
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $user : null;
    }
}
