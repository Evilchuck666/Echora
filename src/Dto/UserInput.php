<?php

namespace App\Dto;

use App\Entity\User;
use App\Validator\PersonName;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Body of POST /api/users: a new account. Its username is made from the email.
 */
final readonly class UserInput
{
    public function __construct(
        #[Assert\NotBlank(message: 'The email cannot be empty.')]
        #[Assert\Email(message: 'This is not a valid email.')]
        #[Assert\Length(max: User::EMAIL_MAX_LENGTH)]
        public string $email,
        #[PersonName]
        public string $name,
        #[Assert\Length(min: 8, max: 4096, minMessage: 'The password has at least {{ limit }} characters.')]
        public string $password,
        /** Whether the account manages the server too. */
        public bool $admin = false,
    ) {
    }
}
