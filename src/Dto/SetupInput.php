<?php

namespace App\Dto;

use App\Entity\User;
use App\Validator\PersonName;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Body of POST /api/setup: the first account, which becomes the administrator. Its username is made from the email.
 */
final readonly class SetupInput
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
    ) {
    }
}
