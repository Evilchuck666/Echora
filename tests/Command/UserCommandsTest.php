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

        $tester->execute(['username' => 'admin', '--admin' => true]);

        $tester->assertCommandIsSuccessful();
        $user = $this->users()->findOneBy(['username' => 'admin']);
        self::assertNotNull($user);
        self::assertTrue($user->isAdmin());
        self::assertNotSame('secret-pass', $user->getPassword(), 'Only the hash is stored.');
        self::assertTrue(self::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($user, 'secret-pass'));
    }

    public function testAccountsAreNotAdministratorsByDefault(): void
    {
        $tester = $this->command('app:user:create');
        $tester->setInputs(['secret-pass', 'secret-pass']);

        $tester->execute(['username' => 'listener']);

        self::assertFalse($this->users()->findOneBy(['username' => 'listener'])?->isAdmin());
    }

    public function testThePasswordMustBeTypedTwiceTheSame(): void
    {
        $tester = $this->command('app:user:create');
        $tester->setInputs(['secret-pass', 'another-pass']);

        self::assertSame(Command::FAILURE, $tester->execute(['username' => 'listener']));
        self::assertNull($this->users()->findOneBy(['username' => 'listener']));
    }

    public function testShortPasswordsAreRejected(): void
    {
        $tester = $this->command('app:user:create');
        $tester->setInputs(['short', 'short', 'short']);

        self::assertSame(Command::FAILURE, $tester->execute(['username' => 'listener']));
        self::assertNull($this->users()->findOneBy(['username' => 'listener']));
    }

    public function testInvalidAndTakenUsernamesAreRejected(): void
    {
        self::assertSame(Command::INVALID, $this->command('app:user:create')->execute(['username' => 'no spaces']));

        $this->createUser('listener');
        self::assertSame(Command::FAILURE, $this->command('app:user:create')->execute(['username' => 'listener']));
    }

    public function testWithoutATerminalThePasswordIsNotAskedFor(): void
    {
        $tester = $this->command('app:user:create');

        self::assertSame(Command::FAILURE, $tester->execute(['username' => 'listener'], ['interactive' => false]));
        self::assertStringContainsString('interactive terminal', $tester->getDisplay());
    }

    public function testChangingThePasswordSignsTheAccountOut(): void
    {
        $this->createUser('listener');
        $this->createRefreshToken('listener');
        $this->createRefreshToken('someone-else');

        $tester = $this->command('app:user:password');
        $tester->setInputs(['new-secret-pass', 'new-secret-pass']);
        $tester->execute(['username' => 'listener']);

        $tester->assertCommandIsSuccessful();
        $user = $this->users()->findOneBy(['username' => 'listener']);
        self::assertNotNull($user);
        self::assertTrue(self::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($user, 'new-secret-pass'));
        self::assertSame(0, $this->entityManager()->getRepository(RefreshToken::class)->count(['username' => 'listener']));
        self::assertSame(1, $this->entityManager()->getRepository(RefreshToken::class)->count(['username' => 'someone-else']));
    }

    public function testDeletingAnAccountAsksForConfirmation(): void
    {
        $this->createUser('listener');
        $this->createRefreshToken('listener');

        $declined = $this->command('app:user:delete');
        $declined->setInputs(['no']);
        $declined->execute(['username' => 'listener']);
        self::assertNotNull($this->users()->findOneBy(['username' => 'listener']));

        $confirmed = $this->command('app:user:delete');
        $confirmed->setInputs(['yes']);
        $confirmed->execute(['username' => 'listener']);
        self::assertNull($this->users()->findOneBy(['username' => 'listener']));
        self::assertSame(0, $this->entityManager()->getRepository(RefreshToken::class)->count(['username' => 'listener']));
    }

    public function testListingAccounts(): void
    {
        $this->createUser('listener');
        $this->createUser('admin', admin: true);

        $tester = $this->command('app:user:list');
        $tester->execute([]);

        self::assertMatchesRegularExpression('/admin\s+administrator/', $tester->getDisplay());
        self::assertMatchesRegularExpression('/listener\s+user/', $tester->getDisplay());
    }

    private function command(string $name): CommandTester
    {
        return new CommandTester((new Application(self::bootKernel()))->find($name));
    }

    private function createUser(string $username, bool $admin = false): User
    {
        self::bootKernel();
        $user = new User($username);
        $user->setAdmin($admin);
        $user->setPassword('irrelevant-hash');
        $this->entityManager()->persist($user);
        $this->entityManager()->flush();

        return $user;
    }

    private function createRefreshToken(string $username): void
    {
        $token = RefreshToken::createForUserWithTtl(bin2hex(random_bytes(16)), new User($username), 3600);
        $this->entityManager()->persist($token);
        $this->entityManager()->flush();
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
