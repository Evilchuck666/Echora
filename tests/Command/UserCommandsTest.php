<?php

namespace App\Tests\Command;

use App\Entity\RefreshToken;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class UserCommandsTest extends KernelTestCase
{
    public function testCreatingAnAdministrator(): void
    {
        $tester = $this->command('app:user:create');
        $tester->setInputs(['secret-pass', 'secret-pass']);

        $tester->execute(['email' => 'Ana.Admin@Example.com', '--name' => 'Ana', '--admin' => true]);

        $tester->assertCommandIsSuccessful();
        $user = $this->users()->findOneBy(['email' => 'ana.admin@example.com']);
        self::assertNotNull($user);
        self::assertSame('ana.admin', $user->getUsername(), 'The username is made from the email.');
        self::assertSame('Ana', $user->getName());
        self::assertTrue($user->isAdmin());
        self::assertNotSame('secret-pass', $user->getPassword(), 'Only the hash is stored.');
        self::assertTrue(self::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($user, 'secret-pass'));
        self::assertStringContainsString('ana.admin', $tester->getDisplay());
    }

    public function testAccountsAreNotAdministratorsByDefaultAndAreNamedLikeTheirUsername(): void
    {
        $tester = $this->command('app:user:create');
        $tester->setInputs(['secret-pass', 'secret-pass']);

        $tester->execute(['email' => 'listener@example.com']);

        $user = $this->users()->findOneBy(['username' => 'listener']);
        self::assertNotNull($user);
        self::assertFalse($user->isAdmin());
        self::assertSame('listener', $user->getName());
    }

    public function testThePasswordMustBeTypedTwiceTheSame(): void
    {
        $tester = $this->command('app:user:create');
        $tester->setInputs(['secret-pass', 'another-pass']);

        self::assertSame(Command::FAILURE, $tester->execute(['email' => 'listener@example.com']));
        self::assertNull($this->users()->findOneBy(['username' => 'listener']));
    }

    public function testShortPasswordsAreRejected(): void
    {
        $tester = $this->command('app:user:create');
        $tester->setInputs(['short', 'short', 'short']);

        self::assertSame(Command::FAILURE, $tester->execute(['email' => 'listener@example.com']));
        self::assertNull($this->users()->findOneBy(['username' => 'listener']));
    }

    public function testInvalidAndTakenEmailsAreRejected(): void
    {
        $invalid = $this->command('app:user:create');
        $invalid->setInputs(['secret-pass', 'secret-pass']);
        self::assertSame(Command::INVALID, $invalid->execute(['email' => 'not an email']));

        $this->createUser('listener', 'listener@example.com');
        $taken = $this->command('app:user:create');
        $taken->setInputs(['secret-pass', 'secret-pass']);
        self::assertSame(Command::INVALID, $taken->execute(['email' => 'LISTENER@example.com']));
        self::assertStringContainsString('already uses this email', $taken->getDisplay());
    }

    public function testATakenUsernameGetsANumber(): void
    {
        $this->createUser('listener');

        $tester = $this->command('app:user:create');
        $tester->setInputs(['secret-pass', 'secret-pass']);
        $tester->execute(['email' => 'listener@elsewhere.example']);

        self::assertSame('listener-2', $this->users()->findOneBy(['email' => 'listener@elsewhere.example'])?->getUsername());
    }

    public function testWithoutATerminalThePasswordIsNotAskedFor(): void
    {
        $tester = $this->command('app:user:create');

        self::assertSame(Command::FAILURE, $tester->execute(['email' => 'listener@example.com'], ['interactive' => false]));
        self::assertStringContainsString('interactive terminal', $tester->getDisplay());
    }

    public function testChangingThePasswordSignsTheAccountOut(): void
    {
        $listener = $this->createUser('listener', 'listener@example.com');
        $someoneElse = $this->createUser('someone-else');
        $this->createRefreshToken($listener);
        $this->createRefreshToken($someoneElse);

        $tester = $this->command('app:user:password');
        $tester->setInputs(['new-secret-pass', 'new-secret-pass']);
        // By email, or by username.
        $tester->execute(['account' => 'listener@example.com']);

        $tester->assertCommandIsSuccessful();
        $user = $this->users()->findOneBy(['username' => 'listener']);
        self::assertNotNull($user);
        self::assertTrue(self::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($user, 'new-secret-pass'));
        self::assertSame(0, $this->refreshTokens($listener));
        self::assertSame(1, $this->refreshTokens($someoneElse));
    }

    public function testDeletingAnAccountAsksForConfirmation(): void
    {
        $listener = $this->createUser('listener');
        $this->createRefreshToken($listener);

        $declined = $this->command('app:user:delete');
        $declined->setInputs(['no']);
        $declined->execute(['account' => 'listener']);
        self::assertNotNull($this->users()->findOneBy(['username' => 'listener']));

        $confirmed = $this->command('app:user:delete');
        $confirmed->setInputs(['yes']);
        $confirmed->execute(['account' => 'listener']);
        self::assertNull($this->users()->findOneBy(['username' => 'listener']));
        self::assertSame(0, $this->refreshTokens($listener));
    }

    public function testListingAccounts(): void
    {
        $this->createUser('listener', 'listener@example.com');
        $this->createUser('admin', admin: true);

        $tester = $this->command('app:user:list');
        $tester->execute([]);

        self::assertMatchesRegularExpression('/admin\s+-\s+admin\s+administrator/', $tester->getDisplay());
        self::assertMatchesRegularExpression('/listener\s+listener@example\.com\s+listener\s+user/', $tester->getDisplay());
    }

    private function command(string $name): CommandTester
    {
        return new CommandTester((new Application(self::bootKernel()))->find($name));
    }

    private function createUser(string $username, ?string $email = null, bool $admin = false): User
    {
        self::bootKernel();
        $user = new User($username, $email);
        $user->setAdmin($admin);
        $user->setPassword('irrelevant-hash');
        $this->entityManager()->persist($user);
        $this->entityManager()->flush();

        return $user;
    }

    private function createRefreshToken(User $user): void
    {
        $token = RefreshToken::createForUserWithTtl(bin2hex(random_bytes(16)), $user, 3600);
        $this->entityManager()->persist($token);
        $this->entityManager()->flush();
    }

    private function refreshTokens(User $user): int
    {
        return $this->entityManager()->getRepository(RefreshToken::class)->count(['username' => $user->getUserIdentifier()]);
    }

    private function users(): UserRepository
    {
        return self::getContainer()->get(UserRepository::class);
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
