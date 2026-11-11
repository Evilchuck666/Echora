<?php

namespace App\Security;

use App\Doctrine\SqlValue;
use App\Entity\RefreshToken;
use App\Entity\User;
use App\Library\Thumbnails;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Creates, changes and deletes accounts, for the API and the app:user:* commands alike.
 *
 * Whatever counts or creates accounts runs under one lock (symfony/lock, PostgreSQL advisory locks): two requests can't
 * both set up the server and become administrators, both take the same username, or leave it without an administrator
 * by removing each other's role.
 */
final readonly class UserAccounts
{
    private const string LOCK = 'user-accounts';

    public function __construct(
        private UserRepository $users,
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $passwordHasher,
        private ValidatorInterface $validator,
        private LockFactory $locks,
        private Thumbnails $thumbnails,
    ) {
    }

    public function isSetUp(): bool
    {
        return $this->users->count() > 0;
    }

    /**
     * The first account of the server, an administrator.
     *
     * @throws AccountConflict           when the server already has an account
     * @throws ValidationFailedException
     */
    public function setUp(string $email, string $name, string $password): User
    {
        return $this->locked(function () use ($email, $name, $password): User {
            if ($this->isSetUp()) {
                throw new AccountConflict('This server is already set up: sign in instead.');
            }

            return $this->insert($email, $name, $password, true);
        });
    }

    /**
     * A new account. Its username is made from the email: the part before the @, with a number added when it is taken
     * or too short.
     *
     * @param string|null $name Shown to others; the username when null (console only)
     *
     * @throws ValidationFailedException when the email is already used, or something else is invalid
     */
    public function create(string $email, ?string $name, string $password, bool $admin = false): User
    {
        return $this->locked(fn (): User => $this->insert($email, $name, $password, $admin));
    }

    /**
     * Saves changes made to an account, refusing those that would leave the server without an administrator.
     *
     * @param bool $wasAdmin Whether the account was an administrator before the changes
     *
     * @throws AccountConflict
     */
    public function save(User $user, bool $wasAdmin): void
    {
        $this->locked(function () use ($user, $wasAdmin): void {
            if ($wasAdmin && !$user->isAdmin() && $this->countOtherAdmins($user) < 1) {
                throw new AccountConflict('This is the last administrator: make another account an administrator first.');
            }
            $this->entityManager->flush();
        });
    }

    /**
     * Sets a new password and signs the account out of every device.
     *
     * @return int Number of sessions signed out
     */
    public function changePassword(User $user, string $password): int
    {
        $user->setPassword($this->passwordHasher->hashPassword($user, $password));
        $this->entityManager->flush();

        return $this->signOut($user);
    }

    public function isPasswordValid(User $user, string $password): bool
    {
        return $this->passwordHasher->isPasswordValid($user, $password);
    }

    /**
     * Deletes the account with its playlists and favourites (ON DELETE CASCADE), its picture and its sessions.
     *
     * @param User|null $deletedBy The administrator deleting it (null from the console): never themselves
     *
     * @throws AccountConflict
     */
    public function delete(User $user, ?User $deletedBy = null): void
    {
        if (null !== $deletedBy && $deletedBy->getId()->equals($user->getId())) {
            throw new AccountConflict('An administrator cannot delete their own account: ask another administrator.');
        }

        $this->locked(function () use ($user): void {
            $this->signOut($user);
            $this->thumbnails->remove($user->getAvatar());
            $this->entityManager->remove($user);
            $this->entityManager->flush();
        });
    }

    /**
     * Deletes the account's refresh tokens: its sessions can't be renewed, and its JWTs stop working with the account.
     *
     * @return int Number of sessions signed out
     */
    public function signOut(User $user): int
    {
        return $this->entityManager
            ->createQuery(\sprintf('DELETE FROM %s r WHERE r.username IN (:identifiers)', RefreshToken::class))
            // Refresh tokens hold the id; those issued before it replaced the username in sessions hold the username.
            ->setParameter('identifiers', [$user->getUserIdentifier(), $user->getUsername()])
            ->execute();
    }

    /**
     * @throws ValidationFailedException
     */
    private function insert(string $email, ?string $name, string $password, bool $admin): User
    {
        $user = new User($this->availableUsername($email), $email, $name);
        $user->setAdmin($admin);
        $violations = $this->validator->validate($user);
        if (\count($violations) > 0) {
            throw new ValidationFailedException($user, $violations);
        }

        $user->setPassword($this->passwordHasher->hashPassword($user, $password));
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    private function availableUsername(string $email): string
    {
        $base = preg_replace('/[^a-z0-9._-]+/', '', mb_strtolower(explode('@', $email)[0])) ?? '';
        $base = substr('' === $base ? 'user' : $base, 0, 32);

        for ($number = 1;; ++$number) {
            $suffix = 1 === $number && \strlen($base) >= 3 ? '' : '-'.max($number, 1);
            $username = substr($base, 0, 32 - \strlen($suffix)).$suffix;
            if (null === $this->users->findOneBy(['username' => $username])) {
                return $username;
            }
        }
    }

    /**
     * Read from the database, not from the accounts already loaded: another request may have just changed them.
     */
    private function countOtherAdmins(User $user): int
    {
        return SqlValue::int($this->entityManager->getConnection()->fetchOne(
            "SELECT COUNT(*) FROM app_user WHERE jsonb_exists(roles::jsonb, 'ROLE_ADMIN') AND id <> ?",
            [$user->getId()->toRfc4122()],
        ));
    }

    /**
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     */
    private function locked(callable $operation): mixed
    {
        $lock = $this->locks->createLock(self::LOCK, 30);
        $lock->acquire(true);
        try {
            return $operation();
        } finally {
            $lock->release();
        }
    }
}
