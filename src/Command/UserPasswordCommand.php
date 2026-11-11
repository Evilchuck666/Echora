<?php

namespace App\Command;

use App\Repository\UserRepository;
use App\Security\UserAccounts;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:user:password', description: 'Changes the password of an account and signs it out of every device')]
final readonly class UserPasswordCommand
{
    public function __construct(
        private UserRepository $users,
        private UserAccounts $accounts,
        private PasswordPrompt $passwordPrompt,
    ) {
    }

    public function __invoke(InputInterface $input, SymfonyStyle $io, #[Argument('Username or email')] string $account): int
    {
        $user = $this->users->loadUserByIdentifier($account);
        if (null === $user) {
            $io->error(\sprintf('There is no account "%s".', $account));

            return Command::FAILURE;
        }

        $password = $this->passwordPrompt->ask($input, $io);
        if (null === $password) {
            return Command::FAILURE;
        }

        $signedOut = $this->accounts->changePassword($user, $password);

        $io->success(\sprintf('Password of "%s" changed; %d session(s) signed out.', $user->getUsername(), $signedOut));

        return Command::SUCCESS;
    }
}
