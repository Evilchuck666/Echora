<?php

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:user:list', description: 'Lists the accounts')]
final readonly class UserListCommand
{
    public function __construct(private UserRepository $users)
    {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $users = $this->users->findBy([], ['username' => 'ASC']);

        if ([] === $users) {
            $io->note('There are no accounts yet. Open the web client to create the administrator, or run app:user:create <email> --admin');

            return Command::SUCCESS;
        }

        $io->table(['Username', 'Email', 'Name', 'Role'], array_map(
            static fn (User $user): array => [$user->getUsername(), $user->getEmail() ?? '-', $user->getName(), $user->isAdmin() ? 'administrator' : 'user'],
            $users,
        ));

        return Command::SUCCESS;
    }
}
