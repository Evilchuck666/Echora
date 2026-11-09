<?php

namespace App\Command;

use App\Entity\RefreshToken;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:user:delete', description: 'Deletes an account and signs it out of every device')]
final readonly class UserDeleteCommand
{
    public function __construct(
        private UserRepository $users,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(SymfonyStyle $io, #[Argument('Username')] string $username): int
    {
        $user = $this->users->findOneBy(['username' => $username]);
        if (null === $user) {
            $io->error(\sprintf('There is no account named "%s".', $username));

            return Command::FAILURE;
        }

        if (!$io->confirm(\sprintf('Delete the account "%s"?', $username), false)) {
            $io->note('Nothing was deleted.');

            return Command::SUCCESS;
        }

        $this->entityManager
            ->createQuery(\sprintf('DELETE FROM %s r WHERE r.username = :username', RefreshToken::class))
            ->setParameter('username', $username)
            ->execute();
        $this->entityManager->remove($user);
        $this->entityManager->flush();

        $io->success(\sprintf('Account "%s" deleted.', $username));

        return Command::SUCCESS;
    }
}
