<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Body of POST /api/me/password.
 */
final readonly class PasswordChange
{
    public function __construct(
        #[Assert\NotBlank(message: 'Send the current password.')]
        public string $currentPassword,
        #[Assert\Length(min: 8, max: 4096, minMessage: 'The password has at least {{ limit }} characters.')]
        public string $newPassword,
    ) {
    }
}
