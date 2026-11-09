<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\Repository\UserRepository;
use App\State\CurrentUserProvider;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * An account that can sign in. Accounts are managed with the app:user:* console commands.
 */
#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'app_user')] // "user" is a reserved word in PostgreSQL
#[ApiResource(
    operations: [
        new Get(
            uriTemplate: '/me',
            uriVariables: [],
            description: 'The account the request is authenticated as.',
            provider: CurrentUserProvider::class,
        ),
    ],
    normalizationContext: ['groups' => ['user:read']],
)]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    public const string ROLE_ADMIN = 'ROLE_ADMIN';

    /** Letters, digits, dots, dashes and underscores; 3 to 32 characters. */
    public const string USERNAME_PATTERN = '/^[A-Za-z0-9._-]{3,32}$/';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ApiProperty(identifier: true)]
    #[Groups(['user:read'])]
    private Uuid $id;

    /** @var list<string> Roles besides ROLE_USER, which every account has */
    #[ORM\Column]
    private array $roles = [];

    #[ORM\Column]
    private string $password = '';

    public function __construct(
        #[ORM\Column(length: 32, unique: true)]
        #[Groups(['user:read'])]
        private string $username,
    ) {
        if (1 !== preg_match(self::USERNAME_PATTERN, $username)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a valid username: use 3 to 32 letters, digits, dots, dashes or underscores.', $username));
        }

        $this->id = Uuid::v7();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function getUserIdentifier(): string
    {
        \assert('' !== $this->username); // guaranteed by USERNAME_PATTERN

        return $this->username;
    }

    /**
     * @return list<string>
     */
    #[Groups(['user:read'])]
    public function getRoles(): array
    {
        return array_values(array_unique([...$this->roles, 'ROLE_USER']));
    }

    public function isAdmin(): bool
    {
        return \in_array(self::ROLE_ADMIN, $this->roles, true);
    }

    public function setAdmin(bool $admin): void
    {
        $this->roles = $admin ? [self::ROLE_ADMIN] : [];
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    /**
     * @param string $hashedPassword Hashed with UserPasswordHasherInterface, never the plain password
     */
    public function setPassword(string $hashedPassword): void
    {
        $this->password = $hashedPassword;
    }
}
