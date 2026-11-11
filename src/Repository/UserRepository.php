<?php

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Security\User\UserLoaderInterface;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository implements PasswordUpgraderInterface, UserLoaderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    /**
     * The account an identifier names: its id (what JWTs and refresh tokens hold, as it never changes), its email or
     * its username (what people sign in with). A username can't contain "@", and is too short to be a UUID.
     */
    public function loadUserByIdentifier(string $identifier): ?User
    {
        if (Uuid::isValid($identifier)) {
            return $this->find(Uuid::fromString($identifier));
        }
        if (str_contains($identifier, '@')) {
            return $this->findOneBy(['email' => User::normalizeEmail($identifier)]);
        }

        return $this->findOneBy(['username' => $identifier]);
    }

    /**
     * Rehashes passwords when the hashing algorithm or its cost changes.
     */
    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(\sprintf('Instances of "%s" are not supported.', $user::class));
        }

        $user->setPassword($newHashedPassword);
        $this->getEntityManager()->flush();
    }
}
