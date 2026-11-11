<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use ApiPlatform\Validator\Exception\ValidationException;
use App\Dto\PasswordChange;
use App\Entity\User;
use App\Security\SessionTokens;
use App\Security\UserAccounts;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

/**
 * POST /api/me/password: checks the current password, sets the new one, signs the account out of every device and
 * answers with a new session for the one that asked.
 *
 * @implements ProcessorInterface<PasswordChange, Response>
 */
final readonly class PasswordChangeProcessor implements ProcessorInterface
{
    public function __construct(
        private UserAccounts $accounts,
        private SessionTokens $tokens,
        private Security $security,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Response
    {
        $user = $this->security->getUser();
        \assert($user instanceof User);

        if (!$this->accounts->isPasswordValid($user, $data->currentPassword)) {
            throw new ValidationException(new ConstraintViolationList([new ConstraintViolation('The current password is wrong.', null, [], $data, 'currentPassword', null)]));
        }
        $this->accounts->changePassword($user, $data->newPassword);

        return $this->tokens->respond($user);
    }
}
