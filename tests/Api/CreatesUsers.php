<?php

namespace App\Tests\Api;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Creates accounts and JWTs in tests. Needs a booted kernel.
 */
trait CreatesUsers
{
    private static function createUser(string $username = 'listener', string $password = 'listener-pass', bool $admin = false): User
    {
        $container = self::getContainer();
        $user = new User($username);
        $user->setAdmin($admin);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, $password));

        $entityManager = $container->get(EntityManagerInterface::class);
        $entityManager->persist($user);
        $entityManager->flush();

        return $user;
    }

    private static function tokenFor(User $user): string
    {
        return self::getContainer()->get(JWTTokenManagerInterface::class)->create($user);
    }
}
