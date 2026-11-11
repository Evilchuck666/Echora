<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Response;
use App\Dto\PasswordChange;
use App\Dto\UserInput;
use App\Repository\UserRepository;
use App\State\CurrentUserProvider;
use App\State\PasswordChangeProcessor;
use App\State\UserProcessor;
use App\State\UserRemoveProcessor;
use App\Validator\PersonName;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * An account that can sign in, with its email or its username. The first one is created by POST /api/setup and is an
 * administrator; administrators manage the others (/api/users). Everyone manages their own account (/api/me). The
 * app:user:* console commands remain, to get back in.
 *
 * Sessions (JWTs, refresh tokens) name the account by its id, which never changes: renaming it keeps them.
 */
#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'app_user')] // "user" is a reserved word in PostgreSQL
#[UniqueEntity('username', message: 'This username is already taken.')]
#[UniqueEntity('email', message: 'Another account already uses this email.')]
#[ApiResource(
    operations: [
        new Get(
            uriTemplate: '/me',
            uriVariables: [],
            openapi: new Operation(summary: 'The account the request is authenticated as'),
            provider: CurrentUserProvider::class,
        ),
        new Patch(
            uriTemplate: '/me',
            uriVariables: [],
            openapi: new Operation(
                summary: 'Changes the name, username or email of the account the request is authenticated as',
                description: 'Send any of `name`, `username` and `email` as application/merge-patch+json. Sessions stay open: they name the account by its id. The password has its own endpoint (POST /api/me/password).',
            ),
            denormalizationContext: ['groups' => ['user:self']],
            read: true,
            provider: CurrentUserProvider::class,
            processor: UserProcessor::class,
        ),
        new Post(
            uriTemplate: '/me/password',
            uriVariables: [],
            status: 200,
            openapi: new Operation(
                responses: [
                    '200' => new Response('Changed: every session of the account is signed out, and a new token pair is returned, as by POST /api/auth'),
                    '422' => new Response('The current password is wrong, or the new one is too short'),
                ],
                summary: 'Changes the password of the account the request is authenticated as',
            ),
            input: PasswordChange::class,
            read: false,
            processor: PasswordChangeProcessor::class,
        ),
        new GetCollection(
            uriTemplate: '/users',
            openapi: new Operation(summary: 'Every account, by username (administrators only)'),
            security: "is_granted('ROLE_ADMIN')",
        ),
        new Post(
            uriTemplate: '/users',
            openapi: new Operation(
                summary: 'Creates an account (administrators only)',
                description: 'The username is made from the email (the part before the @, with a number added if it is taken); it can be changed afterwards.',
            ),
            security: "is_granted('ROLE_ADMIN')",
            input: UserInput::class,
            processor: UserProcessor::class,
        ),
        new Get(
            uriTemplate: '/users/{id}',
            openapi: new Operation(summary: 'An account (administrators only)'),
            security: "is_granted('ROLE_ADMIN')",
        ),
        new Patch(
            uriTemplate: '/users/{id}',
            openapi: new Operation(
                responses: ['409' => new Response('It would leave the server without an administrator')],
                summary: 'Changes an account (administrators only)',
                description: 'Any of `name`, `username`, `email`, `admin` and `password`, as application/merge-patch+json. A new password signs the account out of every device. The last administrator cannot stop being one.',
            ),
            security: "is_granted('ROLE_ADMIN')",
            denormalizationContext: ['groups' => ['user:self', 'user:admin']],
            processor: UserProcessor::class,
        ),
        new Delete(
            uriTemplate: '/users/{id}',
            openapi: new Operation(
                responses: ['409' => new Response('An administrator cannot delete their own account')],
                summary: 'Deletes an account, with its playlists, favourites and picture (administrators only)',
                description: 'Its sessions end at once.',
            ),
            security: "is_granted('ROLE_ADMIN')",
            processor: UserRemoveProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => ['user:read']],
    order: ['username' => 'ASC'],
)]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    public const string ROLE_ADMIN = 'ROLE_ADMIN';

    /** Letters, digits, dots, dashes and underscores; 3 to 32 characters (so never an email, nor a UUID). */
    public const string USERNAME_PATTERN = '/^[A-Za-z0-9._-]{3,32}$/';

    public const int EMAIL_MAX_LENGTH = 180;

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

    /** A new password sent by an administrator (PATCH /api/users/{id}), until App\State\UserProcessor hashes it. */
    #[Assert\Length(min: 8, max: 4096, minMessage: 'The password has at least {{ limit }} characters.')]
    #[Groups(['user:admin'])]
    #[SerializedName('password')]
    #[ApiProperty(description: 'A new password: signs the account out of every device', readable: false)]
    private ?string $plainPassword = null;

    /** Unique, in lower case. Null for accounts created before emails were asked for, until one is set. */
    #[ORM\Column(length: self::EMAIL_MAX_LENGTH, unique: true, nullable: true)]
    #[Assert\Email(message: 'This is not a valid email.')]
    #[Assert\Length(max: self::EMAIL_MAX_LENGTH)]
    #[ApiProperty(description: 'Unique; signs in like the username. Null for older accounts until one is set')]
    #[Groups(['user:read', 'user:self'])]
    private ?string $email = null;

    #[ORM\Column(length: PersonName::MAX_LENGTH)]
    #[PersonName]
    #[ApiProperty(description: 'The name shown to others: 1 to 100 characters')]
    #[Groups(['user:read', 'user:self'])]
    private string $name;

    /** File name of the profile picture in the media folder (App\Library\Thumbnails). Never exposed. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $avatar = null;

    #[ApiProperty(description: 'Signed URL of the profile picture (a JPEG of up to 300×300), usable as the src of an <img>; null without one. Changes when the picture does')]
    #[Groups(['user:read'])]
    private ?string $avatarUrl = null;

    public function __construct(
        #[ORM\Column(length: 32, unique: true)]
        #[Assert\Regex(self::USERNAME_PATTERN, message: 'Use 3 to 32 letters, digits, dots, dashes or underscores.')]
        #[ApiProperty(description: 'Unique: 3 to 32 letters, digits, dots, dashes or underscores. Signs in like the email')]
        #[Groups(['user:read', 'user:self'])]
        private string $username,
        ?string $email = null,
        ?string $name = null,
    ) {
        self::assertValidUsername($username);
        $this->id = Uuid::v7();
        $this->email = null === $email ? null : self::normalizeEmail($email);
        $this->name = $name ?? $username;
    }

    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function setUsername(string $username): void
    {
        $this->username = $username;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): void
    {
        $this->email = self::normalizeEmail($email);
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    /**
     * The id, not the username: JWTs and refresh tokens hold it, and it never changes.
     */
    public function getUserIdentifier(): string
    {
        return $this->id->toRfc4122();
    }

    /**
     * @return list<string>
     */
    #[Groups(['user:read'])]
    public function getRoles(): array
    {
        return array_values(array_unique([...$this->roles, 'ROLE_USER']));
    }

    #[ApiProperty(description: 'Whether the account manages the server (the accounts, through /api/users)')]
    #[Groups(['user:read'])]
    public function isAdmin(): bool
    {
        return \in_array(self::ROLE_ADMIN, $this->roles, true);
    }

    #[Groups(['user:admin'])]
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

    public function setPlainPassword(string $plainPassword): void
    {
        $this->plainPassword = $plainPassword;
    }

    /** The new password sent by an administrator, if any, forgotten once read. */
    public function takePlainPassword(): ?string
    {
        [$password, $this->plainPassword] = [$this->plainPassword, null];

        return $password;
    }

    public function getAvatar(): ?string
    {
        return $this->avatar;
    }

    public function setAvatar(?string $avatar): void
    {
        $this->avatar = $avatar;
    }

    public function getAvatarUrl(): ?string
    {
        return $this->avatarUrl;
    }

    /** Not stored: filled when the account is serialized (App\Serializer\SignedUrlNormalizer). */
    public function setAvatarUrl(?string $avatarUrl): void
    {
        $this->avatarUrl = $avatarUrl;
    }

    private static function assertValidUsername(string $username): void
    {
        if (1 !== preg_match(self::USERNAME_PATTERN, $username)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a valid username: use 3 to 32 letters, digits, dots, dashes or underscores.', $username));
        }
    }
}
