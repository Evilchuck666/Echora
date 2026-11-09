<?php

namespace App\Command;

use App\Entity\RefreshToken;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(name: 'app:user:password', description: 'Changes the password of an account and signs it out of every device')]
final readonly class UserPasswordCommand
{
    public function __construct(
        private UserRepository $users,
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $passwordHasher,
        private PasswordPrompt $passwordPrompt,
    ) {
    }

    public function __invoke(InputInterface $input, SymfonyStyle $io, #[Argument('Username')] string $username): int
    {
        $user = $this->users->findOneBy(['username' => $username]);
        if (null === $user) {
            $io->error(\sprintf('There is no account named "%s".', $username));

            return Command::FAILURE;
        }

        $password = $this->passwordPrompt->ask($input, $io);
        if (null === $password) {
            return Command::FAILURE;
        }

        $user->setPassword($this->passwordHasher->hashPassword($user, $password));
        $this->entityManager->flush();

        // Devices keep their current JWT until it expires (at most an hour), but can't renew it.
        $signedOut = $this->entityManager
            ->createQuery(\sprintf('DELETE FROM %s r WHERE r.username = :username', RefreshToken::class))
            ->setParameter('username', $username)
            ->execute();

        $io->success(\sprintf('Password of "%s" changed; %d session(s) signed out.', $username, $signedOut));

        return Command::SUCCESS;
    }
}
