<?php

namespace App\Command;

use App\Security\UserAccounts;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Validator\Exception\ValidationFailedException;

#[AsCommand(name: 'app:user:create', description: 'Creates an account; asks for its password')]
final readonly class UserCreateCommand
{
    public function __construct(
        private UserAccounts $accounts,
        private PasswordPrompt $passwordPrompt,
    ) {
    }

    public function __invoke(
        InputInterface $input,
        SymfonyStyle $io,
        #[Argument('Email: the account signs in with it, or with the username made from it')]
        string $email,
        #[Option('Name shown to others (the username by default)')]
        ?string $name = null,
        #[Option('Let the account manage the server')]
        bool $admin = false,
    ): int {
        $password = $this->passwordPrompt->ask($input, $io);
        if (null === $password) {
            return Command::FAILURE;
        }

        try {
            $user = $this->accounts->create($email, $name, $password, $admin);
        } catch (ValidationFailedException $e) {
            foreach ($e->getViolations() as $violation) {
                $io->error(\sprintf('%s: %s', $violation->getPropertyPath(), $violation->getMessage()));
            }

            return Command::INVALID;
        }

        $io->success(\sprintf('%s "%s" created: signs in with "%s" or "%s".', $admin ? 'Administrator' : 'User', $user->getName(), $user->getEmail(), $user->getUsername()));

        return Command::SUCCESS;
    }
}
