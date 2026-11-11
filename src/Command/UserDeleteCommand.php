<?php

namespace App\Command;

use App\Repository\UserRepository;
use App\Security\UserAccounts;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:user:delete', description: 'Deletes an account and signs it out of every device')]
final readonly class UserDeleteCommand
{
    public function __construct(
        private UserRepository $users,
        private UserAccounts $accounts,
    ) {
    }

    public function __invoke(SymfonyStyle $io, #[Argument('Username or email')] string $account): int
    {
        $user = $this->users->loadUserByIdentifier($account);
        if (null === $user) {
            $io->error(\sprintf('There is no account "%s".', $account));

            return Command::FAILURE;
        }

        $username = $user->getUsername();
        if (!$io->confirm(\sprintf('Delete the account "%s"?', $username), false)) {
            $io->note('Nothing was deleted.');

            return Command::SUCCESS;
        }

        // With its playlists, favourites, picture and sessions.
        $this->accounts->delete($user);

        $io->success(\sprintf('Account "%s" deleted.', $username));

        return Command::SUCCESS;
    }
}
