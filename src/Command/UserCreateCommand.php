<?php

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(name: 'app:user:create', description: 'Creates an account; asks for its password')]
final readonly class UserCreateCommand
{
    public function __construct(
        private UserRepository $users,
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $passwordHasher,
        private PasswordPrompt $passwordPrompt,
    ) {
    }

    public function __invoke(
        InputInterface $input,
        SymfonyStyle $io,
        #[Argument('Username: 3 to 32 letters, digits, dots, dashes or underscores')]
        string $username,
        #[Option('Let the account manage the server')]
        bool $admin = false,
    ): int {
        if (null !== $this->users->findOneBy(['username' => $username])) {
            $io->error(\sprintf('The username "%s" is already taken.', $username));

            return Command::FAILURE;
        }

        try {
            $user = new User($username);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::INVALID;
        }

        $password = $this->passwordPrompt->ask($input, $io);
        if (null === $password) {
            return Command::FAILURE;
        }

        $user->setAdmin($admin);
        $user->setPassword($this->passwordHasher->hashPassword($user, $password));
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $io->success(\sprintf('%s "%s" created.', $admin ? 'Administrator' : 'User', $username));

        return Command::SUCCESS;
    }
}
